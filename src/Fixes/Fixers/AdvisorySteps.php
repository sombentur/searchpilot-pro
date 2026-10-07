<?php
/**
 * Manual steps for issues that have no safe automatic fix.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Core\Settings;
use SearchPilot\Links\LinkGraph;

defined( 'ABSPATH' ) || exit;

/**
 * The concrete thing a person does, shown as the primary action instead of
 * a fix button: thin content and "crawled – not indexed" need editorial
 * judgement (and Search Console), heading problems on page-builder layouts
 * live in the builder's widgets.
 */
class AdvisorySteps extends AbstractFixer {

	/**
	 * thin_content.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function thin_content( array $issue ) {
		$title = self::plain( $issue['title'] ?? '' );
		$post  = get_post( (int) ( $issue['object_id'] ?? 0 ) );
		if ( $post instanceof \WP_Post && $this->is_utility( $post ) ) {
			/* translators: %s: page title. */
			return sprintf( __( '“%s” is a utility page (contact, legal, corrections…). If it already tells visitors everything they need, nothing has to change — ignore this issue. Otherwise add the missing details (who to contact and how, response times, addresses).', 'searchpilot-pro' ), $title );
		}
		return sprintf(
			/* translators: 1: post title, 2: words, 3: site guideline. */
			__( 'Open “%1$s” and read it as a visitor: add the facts, examples, sources or context it is missing (it has %2$d words; the site guideline is %3$d — never pad text to reach it). If the page cannot stand on its own, merge it into a stronger related page and redirect it, or set it to noindex.', 'searchpilot-pro' ),
			$title,
			(int) ( $issue['data']['words'] ?? 0 ),
			(int) ( $issue['data']['threshold'] ?? 300 )
		);
	}

	/**
	 * crawled_not_indexed.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function crawled_not_indexed( array $issue ) {
		$url     = (string) ( $issue['url'] ?? '' );
		$post_id = 'post' === ( $issue['object_type'] ?? '' ) ? (int) ( $issue['object_id'] ?? 0 ) : 0;
		$links   = '';
		if ( $post_id ) {
			try {
				$inbound = count( (array) $this->get( LinkGraph::class )->edges_to( $post_id ) );
				$links   = ' ' . sprintf(
					/* translators: %d: number of internal links. */
					_n( '(%d internal link points to it now.)', '(%d internal links point to it now.)', $inbound, 'searchpilot-pro' ),
					$inbound
				);
			} catch ( \Throwable $e ) {
				$links = '';
			}
		}
		$inspect = self::inspect_url( (string) $this->get( Settings::class )->get( 'engines.google_property', '' ), $url );
		$step3   = '' !== $inspect
			/* translators: %s: Search Console URL. */
			? sprintf( __( '3) In Google Search Console, inspect the address and click “Request indexing” once: %s', 'searchpilot-pro' ), $inspect )
			: __( '3) In Google Search Console, open URL Inspection for this address and click “Request indexing” once.', 'searchpilot-pro' );
		return implode(
			' ',
			array(
				__( '1) Make the page clearly worth indexing: original reporting, facts, quotes or images that other pages do not have, and no near-copy of another article.', 'searchpilot-pro' ),
				__( '2) Link to it from two or three related articles and its category page — SearchPilot → Internal Links suggests sources.', 'searchpilot-pro' ) . $links,
				$step3,
				__( 'Google decides over days or weeks; repeated requests do not speed it up, and indexing is never guaranteed.', 'searchpilot-pro' ),
			)
		);
	}

	/**
	 * Heading problems on page-builder layouts (and the generic heading step).
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function headings( array $issue ) {
		$builder = self::builder( (int) ( $issue['object_id'] ?? 0 ) );
		if ( 'multiple_h1' === ( $issue['type'] ?? '' ) ) {
			return '' !== $builder
				/* translators: %s: page builder name. */
				? sprintf( __( 'Open the page in %s and change every heading widget set to H1 — except the page title — to H2.', 'searchpilot-pro' ), self::builder_label( $builder ) )
				: __( 'Keep the page title as the only H1: change other H1 headings in the content, the theme or widget areas to H2.', 'searchpilot-pro' );
		}
		if ( '' !== $builder ) {
			/* translators: %s: page builder name. */
			return sprintf( __( 'Open the page in %s and set the heading widgets in order: H2 for sections, H3 inside them, without skipping levels; give empty headings a text or remove them.', 'searchpilot-pro' ), self::builder_label( $builder ) );
		}
		$data  = (array) ( $issue['data'] ?? array() );
		$steps = array();
		if ( isset( $data['outline'] ) && is_array( $data['outline'] ) && HeadingFixer::has_skip( array_map( 'intval', $data['outline'] ) ) ) {
			$steps[] = __( 'Put the headings in order: H2 for main sections, H3 inside them, without skipping a level.', 'searchpilot-pro' );
		}
		$empty = (int) ( $data['empty'] ?? 0 );
		if ( $empty ) {
			/* translators: %d: number of headings. */
			$steps[] = sprintf( _n( 'Remove the empty heading or give it a short descriptive text.', 'Remove the %d empty headings or give them a short descriptive text.', $empty, 'searchpilot-pro' ), $empty );
		}
		if ( ! empty( $data['no_subheadings'] ) ) {
			$steps[] = __( 'Break the text into sections with H2 subheadings that say what each part is about, so readers and screen readers can scan it.', 'searchpilot-pro' );
		}
		return $steps ? implode( ' ', $steps ) : __( 'Use H2 for main sections and H3 inside them, in order, each with a short descriptive text; remove empty headings.', 'searchpilot-pro' );
	}

	/**
	 * Search Console URL Inspection deep link ('' without a property).
	 *
	 * @param string $property Property (https://example.com/ or sc-domain:example.com).
	 * @param string $url      Page URL.
	 * @return string
	 */
	public static function inspect_url( $property, $url ) {
		$property = trim( (string) $property );
		$url      = trim( (string) $url );
		if ( '' === $property || '' === $url || ! preg_match( '#^(sc-domain:[a-z0-9.\-]+|https?://\S+)$#i', $property ) ) {
			return '';
		}
		return 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $property ) . '&id=' . rawurlencode( $url );
	}
}
