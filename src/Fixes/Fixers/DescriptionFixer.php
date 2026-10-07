<?php
/**
 * Planner for description_length (over-long descriptions only).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\Analyzer;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Descriptions are editorial, so only the safe case gets a (previewed,
 * assisted) fix: a description the page serves that is too long for
 * search results is shortened — to its longest run of whole sentences
 * that fits, else at a word boundary with an ellipsis (C1 Text::truncate,
 * never splitting a grapheme cluster) — and written into the description
 * field of the plugin that owns descriptions. Short descriptions need
 * new words, which a person writes (advisory).
 */
class DescriptionFixer extends AbstractFixer {

	/**
	 * Fix mode for an issue.
	 *
	 * @param array $issue Issue.
	 * @return string assisted|advisory
	 */
	public function mode( array $issue ) {
		if ( 'long' !== self::direction_of( $issue ) || ! get_post( (int) ( $issue['object_id'] ?? 0 ) ) ) {
			return 'advisory';
		}
		return $this->owner_of( 'description' )['writable'] ? 'assisted' : 'advisory';
	}

	/**
	 * What a person does when there is no automatic fix.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function manual_step( array $issue ) {
		$owner = $this->owner_of( 'description' )['owner_name'];
		$title = self::plain( $issue['title'] ?? '' );
		if ( 'long' === self::direction_of( $issue ) ) {
			/* translators: 1: post title, 2: SEO plugin name. */
			return sprintf( __( 'Shorten the meta description of “%1$s” in the %2$s panel of the editor so it fits in search results (about 150–160 characters in English, about 100 in Kannada), key point first.', 'searchpilot-pro' ), $title, $owner );
		}
		/* translators: 1: post title, 2: SEO plugin name. */
		return sprintf( __( 'Write a meta description for “%1$s” of about 150–160 characters in English (about 100 in Kannada) in the %2$s panel of the editor: the key fact or answer first, then why the page is worth reading. A longer excerpt also works when your SEO plugin builds the description from it.', 'searchpilot-pro' ), $title, $owner );
	}

	/**
	 * Plans the fix.
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error
	 */
	public function plan( array $issue ) {
		$post = self::post_of( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$description = $this->served( $post, 'description' );
		$rule        = self::rule( $description );
		if ( '' === $description || 'pass' === $rule['status'] ) {
			return new \WP_Error( 'searchpilot_nothing_to_fix', __( 'The description now has a good length. Re-check the page to close this issue.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( 'long' !== $rule['direction'] ) {
			return new \WP_Error( 'searchpilot_editorial', $this->manual_step( array_merge( $issue, array( 'title' => self::title_of( $post ) ) ) ), array( 'status' => 409 ) );
		}
		$denied = $this->unwritable( 'description' );
		if ( $denied ) {
			return $denied;
		}
		$passes = static function ( $text ) {
			return 'pass' === self::rule( $text )['status'];
		};
		$text = self::shorten( $description, $passes );
		if ( '' === $text ) {
			return new \WP_Error( 'searchpilot_no_safe_description', __( 'SearchPilot could not shorten this description to a good length without cutting it badly. Write a shorter one in the editor.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$others = $this->get( AnalysisRepository::class )->duplicates_of( 'description_hash', AnalysisRepository::text_hash( $text ), $post->ID, 3 );
		if ( $others ) {
			return new \WP_Error(
				'searchpilot_no_safe_description',
				/* translators: %s: other post title. */
				sprintf( __( 'The shortened description would be identical to the description of “%s”. Write one that is specific to this page.', 'searchpilot-pro' ), self::title_of( $others[0] ) ),
				array( 'status' => 409 )
			);
		}
		$meta   = $this->get( MetaStore::class );
		$stored = (string) $meta->get_post_field( $post->ID, 'description' );
		$owner  = $this->owner_of( 'description' )['owner_name'];
		$plan   = self::new_plan(
			$issue,
			sprintf(
				/* translators: 1: post title, 2: characters before, 3: characters after. */
				__( 'Shorten the meta description of “%1$s” from %2$d to %3$d characters', 'searchpilot-pro' ),
				self::title_of( $post ),
				$rule['graphemes'],
				Text::length( $text )
			),
			'post',
			$post->ID
		);

		$plan['changes'][]  = self::change(
			'seo_field',
			$post->ID,
			'description',
			/* translators: %s: SEO plugin name. */
			sprintf( __( 'Meta description (%s)', 'searchpilot-pro' ), $owner ),
			$description,
			$text,
			$text,
			$stored
		);
		$plan['warnings'][] = __( 'Descriptions are editorial: read the shortened text before you apply it.', 'searchpilot-pro' );
		if ( '' === trim( $stored ) ) {
			/* translators: %s: SEO plugin name. */
			$plan['warnings'][] = sprintf( __( 'The page currently builds its description automatically (for example from the excerpt). After this fix it is fixed text in the %s panel and no longer follows later edits — update or clear it when the article changes.', 'searchpilot-pro' ), $owner );
		}
		return $plan;
	}

	/* ------------------------------------------------------------------
	 * Pure logic (unit-tested)
	 * ------------------------------------------------------------------ */

	/**
	 * The shared description length rule (Analyzer's, so checks and fixes agree).
	 *
	 * @param string $description Description.
	 * @return array{status:string,graphemes:int,direction:string}
	 */
	public static function rule( $description ) {
		$description = trim( (string) $description );
		$graphemes   = Text::length( $description );
		$long        = $graphemes > 156;
		if ( method_exists( Analyzer::class, 'description_length_status' ) ) {
			$result    = (array) Analyzer::description_length_status( $description );
			$status    = (string) ( $result['status'] ?? 'pass' );
			$graphemes = isset( $result['graphemes'] ) ? (int) $result['graphemes'] : $graphemes;
			if ( isset( $result['direction'] ) && in_array( $result['direction'], array( 'long', 'short' ), true ) ) {
				$long = 'long' === $result['direction'];
			} elseif ( isset( $result['width'], $result['limit'] ) && (float) $result['limit'] > 0 ) {
				$long = (float) $result['width'] > (float) $result['limit'];
			}
		} elseif ( $graphemes < 50 || $graphemes > 200 ) {
			$status = 'fail';
		} elseif ( $graphemes < 120 || $graphemes > 156 ) {
			$status = 'warning';
		} else {
			$status = 'pass';
		}
		return array(
			'status'    => $status,
			'graphemes' => $graphemes,
			'direction' => 'pass' === $status ? '' : ( $long ? 'long' : 'short' ),
		);
	}

	/**
	 * Direction of a description_length issue.
	 *
	 * @param array $issue Issue.
	 * @return string long|short
	 */
	public static function direction_of( array $issue ) {
		$data = (array) ( $issue['data'] ?? array() );
		if ( isset( $data['direction'] ) && in_array( $data['direction'], array( 'long', 'short' ), true ) ) {
			return $data['direction'];
		}
		if ( ! empty( $data['description'] ) ) {
			$direction = self::rule( (string) $data['description'] )['direction'];
			if ( '' !== $direction ) {
				return $direction;
			}
		}
		return (int) ( $data['length'] ?? 0 ) > 156 ? 'long' : 'short';
	}

	/**
	 * Shortest safe form of an over-long description: the longest run of
	 * whole sentences the rule accepts, else the longest word-boundary cut
	 * with an ellipsis ('' when neither fits).
	 *
	 * @param string   $description Description.
	 * @param callable $passes      callable( string ): bool.
	 * @return string
	 */
	public static function shorten( $description, callable $passes ) {
		$description = trim( (string) preg_replace( '/\s+/u', ' ', (string) $description ) );
		$best        = '';
		$run         = '';
		foreach ( Text::sentences( $description ) as $sentence ) {
			$run = '' === $run ? $sentence : $run . ' ' . $sentence;
			if ( $run === $description ) {
				break;
			}
			if ( call_user_func( $passes, $run ) ) {
				$best = $run;
			}
		}
		if ( '' !== $best ) {
			return $best;
		}
		// The longest word-boundary cut that fits the result width (C1), when it is accepted.
		if ( method_exists( Text::class, 'serp_truncate' ) ) {
			$candidate = Text::serp_truncate( $description, 'description', '…' );
			if ( $candidate !== $description && call_user_func( $passes, $candidate ) ) {
				return $candidate;
			}
		}
		for ( $n = Text::length( $description ) - 1; $n >= 40; $n-- ) {
			$candidate = Text::truncate( $description, $n, '…' );
			if ( $candidate !== $description && call_user_func( $passes, $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}
}
