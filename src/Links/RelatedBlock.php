<?php
/**
 * "Related articles" block appended to a post (pure).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Fixes\ContentEditor;

defined( 'ABSPATH' ) || exit;

/**
 * When a source post has no phrase that could carry a link to a related
 * article, SearchPilot links it from a short list at the end of the post,
 * with the target's title as the link text:
 *
 *   block content   core/paragraph.searchpilot-related-links__title (title in
 *                   <strong> — no heading, so the outline and the heading checks
 *                   are unaffected) + core/list.searchpilot-related-links__list
 *                   → core/list-item, exactly as the block editor serializes
 *                   them, so the blocks stay valid;
 *   classic content the same structure as plain HTML (p / ul / li).
 *
 * ContentEditor::replace_marked_block() owns the region around it (marker
 * "related-links": a core/group, or in classic content a div, with class
 * searchpilot-related-links): adding a target later rewrites the list
 * instead of appending a second one, and removing the last item removes the
 * region. Every link carries data-spp-link (plugin-inserted, for the link
 * graph and surgical undo).
 */
final class RelatedBlock {

	const MARKER = 'related-links';

	const CSS_CLASS = 'searchpilot-related-links';

	/**
	 * Default maximum items in the list.
	 */
	const MAX_ITEMS = 3;

	/**
	 * Markup inside the marked region, or null.
	 *
	 * @param string $content Post content.
	 * @return string|null
	 */
	public static function region( $content ) {
		return ContentEditor::get_marked_block( (string) $content, self::MARKER );
	}

	/**
	 * Items currently in the list (in order).
	 *
	 * @param string $content Post content.
	 * @return array<int,array{url:string,title:string,link:string,linked:bool}>
	 */
	public static function items( $content ) {
		$region = self::region( $content );
		if ( null === $region ) {
			return array();
		}
		$out = array();
		if ( ! preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $region, $lis ) ) {
			return $out;
		}
		foreach ( $lis[1] as $li ) {
			if ( preg_match( '#<a\b([^>]*)>(.*?)</a>#is', $li, $a ) ) {
				$url  = preg_match( '#\bhref\s*=\s*"([^"]*)"#i', $a[1], $h ) ? html_entity_decode( $h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				$link = preg_match( '#\bdata-spp-link\s*=\s*"([^"]*)"#i', $a[1], $d ) ? $d[1] : '';
				$out[] = array(
					'url'    => $url,
					'title'  => self::text( $a[2] ),
					'link'   => $link,
					'linked' => '' !== $url,
				);
			} else {
				// A list entry whose link was removed (e.g. surgical undo after later edits).
				$out[] = array(
					'url'    => '',
					'title'  => self::text( $li ),
					'link'   => '',
					'linked' => false,
				);
			}
		}
		return $out;
	}

	/**
	 * Adds targets to the list (existing items first, duplicates by URL skipped,
	 * at most $max items) and returns the new content.
	 *
	 * @param string $content Post content.
	 * @param array  $add     Items: url, title, link (data-spp-link value).
	 * @param string $heading List title.
	 * @param array  $args    max (int), list_class (bool: core/list carries wp-block-list, WordPress 6.6+).
	 * @return array{content:string,added:string[]} added = URLs that were added.
	 */
	public static function add( $content, array $add, $heading, array $args = array() ) {
		$content = (string) $content;
		$max     = max( 1, (int) ( $args['max'] ?? self::MAX_ITEMS ) );
		$items   = array_values(
			array_filter(
				self::items( $content ),
				static function ( $item ) {
					return $item['linked'];
				}
			)
		);
		$seen  = array();
		foreach ( $items as $item ) {
			$seen[ self::url_key( $item['url'] ) ] = true;
		}
		$added = array();
		foreach ( $add as $item ) {
			$url = trim( (string) ( $item['url'] ?? '' ) );
			if ( '' === $url || isset( $seen[ self::url_key( $url ) ] ) || count( $items ) >= $max ) {
				continue;
			}
			$seen[ self::url_key( $url ) ] = true;
			$items[]                       = array(
				'url'    => $url,
				'title'  => trim( (string) ( $item['title'] ?? '' ) ),
				'link'   => (string) ( $item['link'] ?? '' ),
				'linked' => true,
			);
			$added[] = $url;
		}
		if ( ! $added ) {
			return array(
				'content' => $content,
				'added'   => array(),
			);
		}
		return array(
			'content' => self::replace( $content, $items, $heading, $args ),
			'added'   => $added,
		);
	}

	/**
	 * Rewrites the list with the given items ('' items removes the region).
	 *
	 * @param string $content Post content.
	 * @param array  $items   Items (url, title, link); entries without URL are dropped.
	 * @param string $heading List title.
	 * @param array  $args    list_class (bool).
	 * @return string
	 */
	public static function replace( $content, array $items, $heading, array $args = array() ) {
		$items = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return '' !== trim( (string) ( $item['url'] ?? '' ) );
				}
			)
		);
		$content = (string) $content;
		$html    =$items ? self::build( $items, $heading, ContentEditor::is_block_content( $content ), ! isset( $args['list_class'] ) || $args['list_class'] ) : '';
		return ContentEditor::replace_marked_block( $content, self::MARKER, $html );
	}

	/**
	 * List markup (without the markers).
	 *
	 * @param array  $items      Items: url, title, link.
	 * @param string $heading    List title (plain text).
	 * @param bool   $blocks     Block markup (else classic HTML).
	 * @param bool   $list_class core/list saves class wp-block-list (WordPress 6.6+).
	 * @return string
	 */
	public static function build( array $items, $heading, $blocks, $list_class = true ) {
		$links = array();
		foreach ( $items as $item ) {
			$attrs = ' href="' . self::esc_url( (string) $item['url'] ) . '"';
			if ( '' !== (string) ( $item['link'] ?? '' ) ) {
				$attrs .= ' data-spp-link="' . self::esc_attr( (string) $item['link'] ) . '"';
			}
			$title   = '' !== trim( (string) ( $item['title'] ?? '' ) ) ? (string) $item['title'] : (string) $item['url'];
			$links[] = '<a' . $attrs . '>' . self::esc_html( $title ) . '</a>';
		}
		$heading = self::esc_html( trim( (string) $heading ) );
		$class   = self::CSS_CLASS;
		if ( ! $blocks ) {
			$lis = array();
			foreach ( $links as $link ) {
				$lis[] = '<li>' . $link . '</li>';
			}
			return '<p class="' . $class . '__title"><strong>' . $heading . "</strong></p>\n"
				. '<ul class="' . $class . "__list\">\n" . implode( "\n", $lis ) . "\n</ul>";
		}
		$lis = array();
		foreach ( $links as $link ) {
			$lis[] = "<!-- wp:list-item -->\n<li>" . $link . "</li>\n<!-- /wp:list-item -->";
		}
		$ul_class = ( $list_class ? 'wp-block-list ' : '' ) . $class . '__list';
		return '<!-- wp:paragraph {"className":"' . $class . "__title\"} -->\n"
			. '<p class="' . $class . '__title"><strong>' . $heading . "</strong></p>\n"
			. "<!-- /wp:paragraph -->\n\n"
			. '<!-- wp:list {"className":"' . $class . "__list\"} -->\n"
			. '<ul class="' . $ul_class . '">' . implode( "\n\n", $lis ) . "</ul>\n"
			. '<!-- /wp:list -->';
	}

	/**
	 * Default list title for a post written in the given script. Sites whose
	 * language has no translation of "Related articles" still get a title in
	 * their own language for Kannada and Hindi.
	 *
	 * @param string $script     Script (Ranker::script()).
	 * @param string $translated The translated English title.
	 * @return string
	 */
	public static function default_heading( $script, $translated ) {
		if ( 'Related articles' !== $translated ) {
			return $translated; // A translation exists for the site language.
		}
		if ( 'kannada' === $script ) {
			return 'ಸಂಬಂಧಿತ ಸುದ್ದಿಗಳು';
		}
		if ( 'devanagari' === $script ) {
			return 'संबंधित लेख';
		}
		return $translated;
	}

	/**
	 * Comparable form of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_key( $url ) {
		$url = strtolower( rawurldecode( trim( (string) $url ) ) );
		$url = (string) preg_replace( '#^https?://(www\.)?#', '', $url );
		return rtrim( (string) preg_replace( '/[?#].*$/', '', $url ), '/' );
	}

	/**
	 * Plain text of a fragment.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text( $html ) {
		$text = html_entity_decode( strip_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Escapes text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}

	/**
	 * Escapes an attribute.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Escapes a URL for an href.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function esc_url( $url ) {
		return function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
