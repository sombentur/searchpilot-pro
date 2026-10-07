<?php
/**
 * Planners for heading_structure and multiple_h1.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Fixes\ContentEditor;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic heading fixes. Only heading LEVELS change — never the
 * heading text — through ContentEditor, which keeps the Gutenberg block
 * attribute (`{"level":N}`) and the HTML tag in sync and also handles
 * classic content.
 *
 *  - heading_structure: first moves the whole outline up so its
 *    shallowest heading sits directly below the page title (newsrooms that
 *    use H3 for every subhead: H3 H3 H3 → H2 H2 H2, H3 H4 H3 H4 → H2 H3 H2
 *    H3), then clamps only real skips to (previous level + 1) in document
 *    order (H2 H4 H5 → H2 H3 H4). Sibling sections stay siblings.
 *    It runs unattended only when the post content holds exactly the
 *    outline the check saw on the rendered page (no headings added by
 *    synced patterns, shortcodes or other blocks) and that outline really
 *    skips a level; empty headings and missing subheadings need a person.
 *  - multiple_h1: demotes H1 headings inside the content to H2 so the
 *    theme's title stays the only H1. When the content uses several H1s
 *    as its top section level, every heading moves one level down instead
 *    (H1/H2 → H2/H3) so sub-sections stay below their sections.
 */
class HeadingFixer extends AbstractFixer {

	/**
	 * Plans the heading_structure fix.
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error Plan.
	 */
	public function plan_structure( array $issue ) {
		$post = $this->editable_post( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$headings = ContentEditor::headings( (string) $post->post_content );
		$levels   = self::levels( $headings );
		$data     = (array) ( $issue['data'] ?? array() );
		$cached   = self::structure_mode_for( $data );
		$raw      = array(
			'raw_outline' => $levels,
			'raw_fixed'   => (bool) self::fixed( $headings ),
		);
		if ( ! empty( $issue['id'] ) && isset( $data['outline'] ) && ( ( $data['raw_outline'] ?? null ) !== $raw['raw_outline'] || (bool) ( $data['raw_fixed'] ?? false ) !== $raw['raw_fixed'] ) ) {
			// The content changed since the analysis: keep the cached outline (and so the mode) honest.
			$this->get( IssueRepository::class )->update_data( (int) $issue['id'], $raw );
			if ( 'deterministic' === $cached && 'deterministic' !== self::structure_mode_for( array_merge( $data, $raw ) ) ) {
				return new \WP_Error(
					'searchpilot_fix_needs_review',
					__( 'The post content no longer matches the headings the check found on the page, so this fix needs your review: preview it again.', 'searchpilot-pro' ),
					array( 'status' => 409 )
				);
			}
		}
		$new = self::normalize_levels( $levels, 1, self::fixed( $headings ) );
		if ( $new === $levels ) {
			return new \WP_Error(
				'searchpilot_nothing_to_fix',
				__( 'The headings stored in this post’s content are already in order. The skipped level comes from a part of the page SearchPilot does not edit — a reusable block or pattern, a Custom HTML block, a shortcode, a widget or the theme. Change the heading level there.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$plan = $this->level_plan(
			$issue,
			$post,
			$headings,
			$new,
			/* translators: %s: post title. */
			sprintf( __( 'Put the headings of “%s” in order (no skipped levels)', 'searchpilot-pro' ), self::title_of( $post ) )
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$empty = 0;
		foreach ( $headings as $heading ) {
			if ( '' === trim( (string) $heading['text'] ) ) {
				++$empty;
			}
		}
		if ( self::has_skip( $new ) ) {
			$plan['warnings'][] = __( 'Some headings sit inside another block’s own markup (for example an “advanced heading” block) and cannot be changed here, so a skipped level remains. Change those in the editor, otherwise this issue stays open.', 'searchpilot-pro' );
		}
		if ( isset( $data['outline'] ) && array_map( 'intval', (array) $data['outline'] ) !== $levels ) {
			$plan['warnings'][] = __( 'The published page shows headings that are not part of this post’s content (a synced pattern, a shortcode or another block adds them). Only the headings in the content change; check the page afterwards.', 'searchpilot-pro' );
		}
		if ( $empty ) {
			$plan['warnings'][] = sprintf(
				/* translators: %d: number of headings. */
				_n( '%d heading is empty. SearchPilot does not delete headings — remove it in the editor, otherwise this issue stays open.', '%d headings are empty. SearchPilot does not delete headings — remove them in the editor, otherwise this issue stays open.', $empty, 'searchpilot-pro' ),
				$empty
			);
		}
		return $plan;
	}

	/**
	 * Plans the multiple_h1 fix.
	 *
	 * @param array $issue Issue.
	 * @return array|\WP_Error Plan.
	 */
	public function plan_multiple_h1( array $issue ) {
		$post = $this->editable_post( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$headings   = ContentEditor::headings( (string) $post->post_content );
		$levels     = self::levels( $headings );
		$fixed      = self::fixed( $headings );
		$keep_first = ! ContentRenderer::theme_prints_title( $post );
		$new        = self::demote_h1_levels( $levels, $keep_first, $fixed );
		$demoted    = 0;
		foreach ( $levels as $i => $level ) {
			if ( 1 === $level && 1 !== $new[ $i ] ) {
				++$demoted;
			}
		}
		if ( ! $demoted ) {
			return new \WP_Error(
				'searchpilot_nothing_to_fix',
				$keep_first
					? __( 'This layout hides the theme title, and the content has only one H1 — the page’s main heading. The other H1 headings come from the theme, a widget area or a page-builder template; change them there.', 'searchpilot-pro' )
					: __( 'The content of this post has no H1 heading, so the extra H1 comes from the theme, a widget area, a reusable block or a page-builder template. Change it there to an H2 (or a styled paragraph).', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$plan = $this->level_plan(
			$issue,
			$post,
			$headings,
			$new,
			/* translators: %s: post title. */
			sprintf( __( 'Keep the page title as the only H1 of “%s” (H1 headings in the content become H2)', 'searchpilot-pro' ), self::title_of( $post ) )
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$rendered = (int) ( $issue['data']['h1'] ?? 0 );
		$left     = $rendered ? $rendered - $demoted : count( array_keys( $new, 1, true ) ) + ( $keep_first ? 0 : 1 );
		if ( $left > 1 ) {
			$plan['warnings'][] = sprintf(
				/* translators: %d: number of H1 headings. */
				__( 'After this change the published page still has %d H1 headings: the others come from the theme or widgets. Change them there, otherwise this issue stays open.', 'searchpilot-pro' ),
				$left
			);
		}
		return $plan;
	}

	/**
	 * The issue's post when its content can be edited.
	 *
	 * @param array $issue Issue.
	 * @return \WP_Post|\WP_Error
	 */
	private function editable_post( array $issue ) {
		$post = self::post_of( $issue );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$builder = self::builder( $post );
		if ( '' !== $builder ) {
			return new \WP_Error(
				'searchpilot_page_builder',
				/* translators: %s: page builder name. */
				sprintf( __( 'This page is laid out with %s, so its headings live in the builder’s widgets, not in the post content. Open it in the builder and change the heading level of each heading widget (H2 for sections, H3 inside them).', 'searchpilot-pro' ), self::builder_label( $builder ) ),
				array( 'status' => 409 )
			);
		}
		if ( ! class_exists( ContentEditor::class ) ) {
			return new \WP_Error( 'searchpilot_fix_unavailable', __( 'The content editor of the fix engine is not available.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		return $post;
	}

	/**
	 * Builds the post_content plan for new heading levels.
	 *
	 * @param array    $issue    Issue.
	 * @param \WP_Post $post     Post.
	 * @param array    $headings Headings (index, level, text).
	 * @param int[]    $new      New level per heading index.
	 * @param string   $summary  Summary.
	 * @return array|\WP_Error
	 */
	private function level_plan( array $issue, \WP_Post $post, array $headings, array $new, $summary ) {
		$content = (string) $post->post_content;
		$updated = ContentEditor::set_heading_levels(
			$content,
			static function ( $level, $index ) use ( $new ) {
				return isset( $new[ $index ] ) ? (int) $new[ $index ] : (int) $level;
			}
		);
		// The edited content must parse back to exactly the planned outline.
		if ( $updated === $content || self::levels( ContentEditor::headings( $updated ) ) !== $new ) {
			return new \WP_Error( 'searchpilot_fix_failed', __( 'SearchPilot could not change the heading levels of this content safely. Change them in the editor.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$diff = self::describe( $headings, $new );
		$plan = self::new_plan( $issue, $summary, 'post', $post->ID );

		$plan['changes'][] = self::change(
			'post_content',
			$post->ID,
			'post_content',
			sprintf(
				/* translators: %d: number of headings. */
				_n( 'Content: %d heading level', 'Content: %d heading levels', count( $diff['before'] ), 'searchpilot-pro' ),
				count( $diff['before'] )
			),
			implode( "\n", $diff['before'] ),
			implode( "\n", $diff['after'] ),
			$updated,
			$content
		);
		return $plan;
	}

	/* ------------------------------------------------------------------
	 * Pure logic (unit-tested)
	 * ------------------------------------------------------------------ */

	/**
	 * Levels of a heading list, in document order.
	 *
	 * @param array $headings Headings with a `level` key.
	 * @return int[]
	 */
	public static function levels( array $headings ) {
		$out = array();
		foreach ( array_values( $headings ) as $heading ) {
			$out[] = max( 1, min( 6, (int) ( is_array( $heading ) ? ( $heading['level'] ?? 2 ) : $heading ) ) );
		}
		return $out;
	}

	/**
	 * Indexes of headings ContentEditor cannot change (inside another
	 * block's own markup): they keep their level.
	 *
	 * @param array $headings Headings with an `editable` flag.
	 * @return int[]
	 */
	public static function fixed( array $headings ) {
		$out = array();
		foreach ( array_values( $headings ) as $i => $heading ) {
			if ( is_array( $heading ) && isset( $heading['editable'] ) && ! $heading['editable'] ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * New outline: the whole outline moves up so its shallowest heading sits
	 * directly below the page title, then real skips are clamped.
	 *
	 * @param int[] $levels Levels in document order.
	 * @param int   $start  Level of the page title (1).
	 * @param int[] $fixed  Indexes that cannot change (they keep their level).
	 * @return int[]
	 */
	public static function normalize_levels( array $levels, $start = 1, array $fixed = array() ) {
		$levels = array_values( array_map( 'intval', $levels ) );
		if ( ! $levels ) {
			return array();
		}
		$locked = array_flip( array_map( 'intval', $fixed ) );
		$shift  = max( 0, min( $levels ) - ( max( 1, (int) $start ) + 1 ) );
		if ( $shift ) {
			foreach ( $levels as $i => $level ) {
				if ( ! isset( $locked[ $i ] ) ) {
					$levels[ $i ] = max( 1, $level - $shift );
				}
			}
		}
		return self::clamp_levels( $levels, $start, $fixed );
	}

	/**
	 * Fix mode of a heading_structure issue from its data (page builders are
	 * handled by the caller):
	 *   deterministic — the rendered outline skips a level and the post
	 *                   content holds exactly that outline, all editable;
	 *   assisted      — it skips a level, but the content differs from the
	 *                   rendered page or has headings that cannot be edited
	 *                   (or the data predates 1.1);
	 *   advisory      — no skipped level (empty headings, no subheadings).
	 *
	 * @param array $data Issue data (outline, raw_outline, raw_fixed).
	 * @return string
	 */
	public static function structure_mode_for( array $data ) {
		if ( ! isset( $data['outline'] ) || ! is_array( $data['outline'] ) ) {
			return 'assisted';
		}
		$outline = array_values( array_map( 'intval', $data['outline'] ) );
		if ( ! self::has_skip( $outline ) ) {
			return 'advisory';
		}
		$raw = isset( $data['raw_outline'] ) && is_array( $data['raw_outline'] ) ? array_values( array_map( 'intval', $data['raw_outline'] ) ) : null;
		if ( $raw !== $outline || ! empty( $data['raw_fixed'] ) ) {
			return 'assisted';
		}
		return 'deterministic';
	}

	/**
	 * Fix mode of a heading issue (page builders: advisory).
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function mode( array $issue ) {
		if ( '' !== self::builder( (int) ( $issue['object_id'] ?? 0 ) ) ) {
			return 'advisory';
		}
		if ( 'multiple_h1' === ( $issue['type'] ?? '' ) ) {
			return 'deterministic';
		}
		return self::structure_mode_for( (array) ( $issue['data'] ?? array() ) );
	}

	/**
	 * Clamps each level to the previous (new) level + 1.
	 *
	 * @param int[] $levels Levels in document order.
	 * @param int   $start  Level of the page title (1).
	 * @param int[] $fixed  Indexes that cannot change (they keep their level).
	 * @return int[]
	 */
	public static function clamp_levels( array $levels, $start = 1, array $fixed = array() ) {
		$out      = array();
		$previous = max( 1, (int) $start );
		$fixed    = array_flip( array_map( 'intval', $fixed ) );
		foreach ( array_values( $levels ) as $i => $level ) {
			$level = max( 1, min( 6, (int) $level ) );
			if ( ! isset( $fixed[ $i ] ) ) {
				$level = min( $level, $previous + 1 );
			}
			$out[]    = $level;
			$previous = $level;
		}
		return $out;
	}

	/**
	 * Whether an outline still skips a level.
	 *
	 * @param int[] $levels Levels.
	 * @param int   $start  Level of the page title.
	 * @return bool
	 */
	public static function has_skip( array $levels, $start = 1 ) {
		$previous = (int) $start;
		foreach ( $levels as $level ) {
			if ( (int) $level > $previous + 1 ) {
				return true;
			}
			$previous = (int) $level;
		}
		return false;
	}

	/**
	 * New levels so that the content has no H1 (or only the first one when
	 * the layout hides the theme title), then clamped.
	 *
	 * @param int[] $levels     Levels in document order.
	 * @param bool  $keep_first Keep the first content H1 (layout hides the title).
	 * @param int[] $fixed      Indexes that cannot change.
	 * @return int[]
	 */
	public static function demote_h1_levels( array $levels, $keep_first = false, array $fixed = array() ) {
		$levels = array_values( array_map( 'intval', $levels ) );
		$h1     = array_keys( $levels, 1, true );
		if ( ! $h1 ) {
			return self::clamp_levels( $levels, 1, $fixed );
		}
		$locked = array_flip( array_map( 'intval', $fixed ) );
		$kept = $keep_first ? $h1[0] : -1;
		$out  = $levels;

		// Several H1s used as the top section level, with sub-headings below them:
		// move the whole outline down one level so sub-sections stay below sections.
		$shift = false;
		if ( ! $keep_first && count( $h1 ) >= 2 ) {
			foreach ( $levels as $i => $level ) {
				if ( $i > $h1[0] && $level >= 2 ) {
					$shift = true;
					break;
				}
			}
		}
		foreach ( $out as $i => $level ) {
			if ( $i === $kept || isset( $locked[ $i ] ) ) {
				continue;
			}
			if ( $shift ) {
				$out[ $i ] = min( 6, $level + 1 );
			} elseif ( 1 === $level ) {
				$out[ $i ] = 2;
			}
		}
		return self::normalize_levels( $out, 1, $fixed );
	}

	/**
	 * Diff lines for the headings whose level changes.
	 *
	 * @param array $headings Headings (level, text).
	 * @param int[] $new      New levels.
	 * @return array{before:string[],after:string[]}
	 */
	public static function describe( array $headings, array $new ) {
		$before = array();
		$after  = array();
		foreach ( array_values( $headings ) as $i => $heading ) {
			$old = (int) ( $heading['level'] ?? 0 );
			if ( ! isset( $new[ $i ] ) || (int) $new[ $i ] === $old ) {
				continue;
			}
			$text     = trim( (string) ( $heading['text'] ?? '' ) );
			$text     = '' === $text ? __( '(empty heading)', 'searchpilot-pro' ) : self::quote( Text::truncate( $text, 80 ) );
			$before[] = 'H' . $old . ' ' . $text;
			$after[]  = 'H' . (int) $new[ $i ] . ' ' . $text;
		}
		return array(
			'before' => $before,
			'after'  => $after,
		);
	}
}
