<?php
/**
 * Planner for title_length.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes\Fixers;

use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\Analyzer;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Titles that do not fit search results.
 *
 * Too long: writes a shorter SEO title — never the post title — trying,
 * in order: the title without the site-name suffix ("site"), the leading
 * clause before the first ? ! : or dash ("clause"), and a word-boundary
 * trim ("trim"). A candidate is used only when the shared length rule
 * accepts it and no other published page already uses it; a leading clause
 * shared by other posts (a series prefix such as "Dasara 2026: Day 1 …") is
 * never used, because the series would end up with identical titles.
 *
 * Kinds and modes:
 *   site         the served title is the headline plus the site name: the
 *                SEO title is set to the owner's headline VARIABLE (Rank Math
 *                and SearchPilot %title%, Yoast %%title%%, AIOSEO
 *                #post_title), so the suffix goes but later headline
 *                corrections still flow through — DETERMINISTIC (unattended
 *                in Autopilot);
 *   site_literal the suffix removed, but as fixed text (custom SEO title, or
 *                an owner without a headline variable) — ASSISTED;
 *   clause       the leading clause, as fixed text — ASSISTED;
 *   trim         a word-boundary cut, as fixed text — ASSISTED;
 *   ''           nothing safe fits — advisory.
 * Fixed text no longer follows the headline, so it always needs a person.
 * The kind is chosen with the same checks at analysis time and cached in
 * the issue data (fix_kind, fix_title), so the mode shown, the mode
 * Autopilot uses and the planner always agree; the planner refreshes the
 * cache when the site changed and refuses to hand anything but the
 * headline variable to a run that was started as deterministic.
 *
 * Too short on a utility page (assisted): "{title} {separator} {site}"
 * with the separator of the plugin that owns titles. Too short on an
 * article: advisory — headlines are editorial.
 */
class TitleFixer extends AbstractFixer {

	/**
	 * Fix mode for an issue.
	 *
	 * @param array $issue Issue.
	 * @return string deterministic|assisted|advisory
	 */
	public function mode( array $issue ) {
		$post = get_post( (int) ( $issue['object_id'] ?? 0 ) );
		if ( ! $post instanceof \WP_Post || ! $this->owner_of( 'title' )['writable'] ) {
			return 'advisory';
		}
		if ( 'long' === self::direction_of( $issue ) ) {
			return self::long_mode( (array) ( $issue['data'] ?? array() ) );
		}
		return $this->is_utility( $post ) ? 'assisted' : 'advisory';
	}

	/**
	 * What a person does when there is no automatic fix.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public function manual_step( array $issue ) {
		$owner = $this->owner_of( 'title' )['owner_name'];
		$title = self::plain( $issue['title'] ?? '' );
		if ( 'long' === self::direction_of( $issue ) ) {
			/* translators: 1: post title, 2: SEO plugin name. */
			return sprintf( __( 'Shorten the SEO title of “%1$s” in the %2$s panel of the editor: main topic first, short enough for the width search results show (about 55–60 characters in English, fewer in wider scripts such as Kannada).', 'searchpilot-pro' ), $title, $owner );
		}
		$post = get_post( (int) ( $issue['object_id'] ?? 0 ) );
		if ( $post instanceof \WP_Post && $this->is_utility( $post ) ) {
			/* translators: 1: page title, 2: SEO plugin name, 3: example title. */
			return sprintf( __( 'Add the site name to the SEO title of “%1$s” in the %2$s panel so searchers recognise the page, for example “%3$s”.', 'searchpilot-pro' ), $title, $owner, self::with_site_name( '' !== $title ? $title : __( 'Contact', 'searchpilot-pro' ), $this->separator(), self::site_name() ) );
		}
		/* translators: 1: post title, 2: SEO plugin name. */
		return sprintf( __( 'Rewrite the SEO title of “%1$s” in the %2$s panel of the editor so it uses more of the width search results show (roughly 30–60 characters in English, fewer in Kannada): name the topic and what makes the page worth clicking (place, date, angle). Headlines are editorial, so SearchPilot does not write them for you.', 'searchpilot-pro' ), $title, $owner );
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
		$title = $this->served( $post, 'title' );
		if ( '' === $title ) {
			return new \WP_Error( 'searchpilot_nothing_to_fix', __( 'The page has no title at all. Write one in the editor.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$type_rule = self::type_rule( $post );
		$rule      = self::rule( $title, $type_rule );
		if ( 'pass' === $rule['status'] ) {
			return new \WP_Error(
				'searchpilot_nothing_to_fix',
				/* translators: %d: characters. */
				sprintf( __( 'The title now has a good length (%d characters). Re-check the page to close this issue.', 'searchpilot-pro' ), $rule['graphemes'] ),
				array( 'status' => 409 )
			);
		}
		$denied = $this->unwritable( 'title' );
		if ( $denied ) {
			return $denied;
		}
		return 'short' === $rule['direction'] ? $this->plan_short( $issue, $post, $title, $type_rule ) : $this->plan_long( $issue, $post, $title, $type_rule );
	}

	/**
	 * Too long: shorter SEO title.
	 *
	 * @param array    $issue     Issue.
	 * @param \WP_Post $post      Post.
	 * @param string   $title     Served title.
	 * @param array    $type_rule Post-type rule.
	 * @return array|\WP_Error
	 */
	private function plan_long( array $issue, \WP_Post $post, $title, array $type_rule ) {
		$choice = $this->choose_long( $post, $title, $type_rule );
		$cached = isset( $issue['data']['fix_kind'] ) ? (string) $issue['data']['fix_kind'] : null;
		if ( ! empty( $issue['id'] ) && ( $choice['kind'] !== $cached || $choice['text'] !== (string) ( $issue['data']['fix_title'] ?? '' ) ) ) {
			// Keep the cached kind (and so the mode) in step with what the site allows now.
			$this->get( IssueRepository::class )->update_data(
				(int) $issue['id'],
				array(
					'fix_kind'  => $choice['kind'],
					'fix_title' => $choice['text'],
				)
			);
		}
		$reasons = $choice['reasons'];
		if ( '' === $choice['kind'] ) {
			$message = __( 'SearchPilot could not shorten this title safely: no shorter version keeps whole words within the length search results show.', 'searchpilot-pro' );
			if ( $reasons ) {
				/* translators: %s: reasons. */
				$message = sprintf( __( 'SearchPilot could not shorten this title safely: %s.', 'searchpilot-pro' ), implode( '; ', $reasons ) );
			}
			return new \WP_Error( 'searchpilot_no_safe_title', $message . ' ' . $this->manual_step( array_merge( $issue, array( 'title' => self::title_of( $post ) ) ) ), array( 'status' => 409 ) );
		}
		if ( null !== $cached && 'deterministic' === self::mode_for_kind( $cached ) && 'deterministic' !== self::mode_for_kind( $choice['kind'] ) ) {
			// Started as an unattended fix, but only a word cut fits now: never apply that without a person.
			return new \WP_Error(
				'searchpilot_fix_needs_review',
				__( 'The shorter title that keeps following the headline is no longer available, so this fix would write fixed text. That needs your review: preview the fix again.', 'searchpilot-pro' ),
				array( 'status' => 409 )
			);
		}
		$text  = $choice['text'];
		$how   = $choice['kind'];
		$value = $choice['value'];
		$plan = self::new_plan(
			$issue,
			sprintf(
				/* translators: 1: post title, 2: characters. */
				__( 'Shorten the SEO title of “%1$s” to %2$d characters (the post title stays as it is)', 'searchpilot-pro' ),
				self::title_of( $post ),
				Text::length( $text )
			),
			'post',
			$post->ID
		);
		$plan['changes'][] = $this->title_change( $post, $title, $text, $value );
		if ( 'trim' === $how ) {
			$plan['warnings'][] = __( 'The title was cut at a word boundary. Check that it still reads well.', 'searchpilot-pro' );
		}
		if ( $reasons ) {
			/* translators: %s: reasons. */
			$plan['warnings'][] = sprintf( __( 'A shorter version was not used: %s.', 'searchpilot-pro' ), implode( '; ', $reasons ) );
		}
		if ( $value === $text ) {
			$this->stored_warning( $plan );
		}
		return $plan;
	}

	/**
	 * Chooses the shorter title for an over-long one: the first candidate the
	 * length rule accepts that no other published page uses (and, for a
	 * leading clause, that does not start other titles too).
	 *
	 * @param \WP_Post   $post      Post.
	 * @param string     $title     Served title.
	 * @param array|null $type_rule Post-type rule (null: the post's).
	 * @return array{text:string,kind:string,value:string,reasons:string[]} kind site|site_literal|clause|trim, '' when nothing safe fits; value is what is stored.
	 */
	public function choose_long( \WP_Post $post, $title, $type_rule = null ) {
		$type_rule = is_array( $type_rule ) ? $type_rule : self::type_rule( $post );
		$passes    = static function ( $text ) use ( $type_rule ) {
			return 'pass' === self::rule( $text, $type_rule )['status'];
		};
		$reasons   = array();
		foreach ( self::candidates( $title, self::site_name(), $passes ) as $candidate ) {
			$others = $this->duplicates( $candidate['text'], $post->ID );
			if ( $others ) {
				/* translators: 1: candidate title, 2: other post title. */
				$reasons[] = sprintf( __( '“%1$s” is already the title of “%2$s”', 'searchpilot-pro' ), $candidate['text'], self::title_of( $others[0] ) );
				continue;
			}
			if ( 'clause' === $candidate['kind'] ) {
				$siblings = $this->series_siblings( $candidate['text'], $post );
				if ( $siblings ) {
					/* translators: 1: candidate title, 2: other post title. */
					$reasons[] = sprintf( __( '“%1$s” also starts other titles (for example “%2$s”), so the articles would become indistinguishable', 'searchpilot-pro' ), $candidate['text'], self::title_of( $siblings[0] ) );
					continue;
				}
			}
			$kind  = $candidate['kind'];
			$value = $candidate['text'];
			if ( 'site' === $kind ) {
				// The suffix-free title follows the headline only when it IS the
				// headline and the owner has a headline variable that resolves to it.
				$variable = $this->title_variable();
				$resolved = '' !== $variable ? self::plain( $this->get( MetaStore::class )->resolve_vars( $variable, $post ) ) : '';
				if ( '' !== $variable && $candidate['text'] === self::title_of( $post ) && $resolved === $candidate['text'] ) {
					$value = $variable;
				} else {
					$kind = 'site_literal';
				}
			}
			return array(
				'text'    => $candidate['text'],
				'kind'    => $kind,
				'value'   => $value,
				'reasons' => $reasons,
			);
		}
		return array(
			'text'    => '',
			'kind'    => '',
			'value'   => '',
			'reasons' => $reasons,
		);
	}

	/**
	 * Too short: "{title} {sep} {site}" on utility pages.
	 *
	 * @param array    $issue     Issue.
	 * @param \WP_Post $post      Post.
	 * @param string   $title     Served title.
	 * @param array    $type_rule Post-type rule.
	 * @return array|\WP_Error
	 */
	private function plan_short( array $issue, \WP_Post $post, $title, array $type_rule ) {
		if ( ! $this->is_utility( $post ) ) {
			return new \WP_Error( 'searchpilot_editorial', $this->manual_step( array_merge( $issue, array( 'title' => self::title_of( $post ) ) ) ), array( 'status' => 409 ) );
		}
		$site = self::site_name();
		if ( '' === $site ) {
			return new \WP_Error( 'searchpilot_no_site_name', __( 'The site has no name (Settings → General → Site Title), so SearchPilot cannot add it to the title.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( false !== mb_stripos( $title, $site, 0, 'UTF-8' ) ) {
			return new \WP_Error( 'searchpilot_editorial', __( 'The title already names the site and is still short. Write a more descriptive title for this page.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$text = self::with_site_name( $title, $this->separator(), $site );
		$rule = self::rule( $text, $type_rule );
		if ( 'pass' !== $rule['status'] ) {
			return new \WP_Error(
				'searchpilot_no_safe_title',
				/* translators: 1: proposed title, 2: characters. */
				sprintf( __( 'Even with the site name (“%1$s”, %2$d characters) the title would not have a good length. Write a more descriptive title for this page.', 'searchpilot-pro' ), $text, $rule['graphemes'] ),
				array( 'status' => 409 )
			);
		}
		$others = $this->duplicates( $text, $post->ID );
		if ( $others ) {
			return new \WP_Error(
				'searchpilot_no_safe_title',
				/* translators: 1: proposed title, 2: other post title. */
				sprintf( __( '“%1$s” is already the title of “%2$s”. Write a title that says what is different about this page.', 'searchpilot-pro' ), $text, self::title_of( $others[0] ) ),
				array( 'status' => 409 )
			);
		}
		$plan              = self::new_plan(
			$issue,
			/* translators: %s: post title. */
			sprintf( __( 'Add the site name to the SEO title of “%s”', 'searchpilot-pro' ), self::title_of( $post ) ),
			'post',
			$post->ID
		);
		$plan['changes'][] = $this->title_change( $post, $title, $text, $text );
		$this->stored_warning( $plan );
		return $plan;
	}

	/**
	 * The seo_field change for a new title.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $title Served title.
	 * @param string   $text  New title as it will show.
	 * @param string   $value Exact value to store (fixed text, or the owner's headline variable).
	 * @return array
	 */
	private function title_change( \WP_Post $post, $title, $text, $value ) {
		$stored = (string) $this->get( MetaStore::class )->get_post_field( $post->ID, 'title' );
		$owner  = $this->owner_of( 'title' )['owner_name'];
		$label  = $value === $text
			/* translators: %s: SEO plugin name. */
			? sprintf( __( 'SEO title (%s) — fixed text', 'searchpilot-pro' ), $owner )
			/* translators: 1: SEO plugin name, 2: title variable such as %title%. */
			: sprintf( __( 'SEO title (%1$s) — set to %2$s, keeps following the headline', 'searchpilot-pro' ), $owner, $value );
		return self::change( 'seo_field', $post->ID, 'title', $label, $title, $text, $value, $stored );
	}

	/**
	 * Warns that the SEO title becomes fixed text (it no longer follows the headline).
	 *
	 * @param array $plan Plan (by reference).
	 */
	private function stored_warning( array &$plan ) {
		$plan['warnings'][] = sprintf(
			/* translators: %s: SEO plugin name. */
			__( 'The SEO title becomes fixed text and no longer follows the headline: if the headline is corrected later, search results keep this title. Update the SEO title too — or clear it in the %s panel so it follows the headline again.', 'searchpilot-pro' ),
			$this->owner_of( 'title' )['owner_name']
		);
	}

	/**
	 * The owner's headline variable for the SEO-title field ('' when the
	 * owner has none SearchPilot can rely on).
	 *
	 * @return string
	 */
	public function title_variable() {
		return self::variable_for_store( $this->get( MetaStore::class )->store_for( 'title' ) );
	}

	/**
	 * Published posts whose effective title equals a text.
	 *
	 * @param string $text    Title.
	 * @param int    $post_id Post to exclude.
	 * @return int[]
	 */
	private function duplicates( $text, $post_id ) {
		return $this->get( AnalysisRepository::class )->duplicates_of( 'title_hash', AnalysisRepository::text_hash( $text ), (int) $post_id, 3 );
	}

	/**
	 * Other published posts whose title starts with a clause.
	 *
	 * @param string   $clause Clause.
	 * @param \WP_Post $post   Post.
	 * @return int[]
	 */
	private function series_siblings( $clause, \WP_Post $post ) {
		global $wpdb;
		$types = array_keys( $this->get( ContentTypes::class )->post_types() );
		if ( ! $types ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params  = array_merge( array( (int) $post->ID, $wpdb->esc_like( $clause ) . '%' ), $types );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID <> %d AND post_title LIKE %s AND post_type IN ({$holders}) LIMIT 3", $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Title separator of the plugin that owns titles.
	 *
	 * @return string
	 */
	public function separator() {
		$store = $this->get( MetaStore::class )->store_for( 'title' );
		$sep   = '';
		if ( 'rank_math' === $store ) {
			$titles = (array) get_option( 'rank-math-options-titles', array() );
			$sep    = (string) ( $titles['title_separator'] ?? '-' );
		} elseif ( 'yoast' === $store ) {
			$titles = (array) get_option( 'wpseo_titles', array() );
			$sep    = self::yoast_separator( (string) ( $titles['separator'] ?? 'sc-dash' ) );
		} elseif ( 'aioseo' === $store ) {
			$options = json_decode( (string) get_option( 'aioseo_options', '' ), true );
			$sep     = is_array( $options ) ? (string) ( $options['searchAppearance']['global']['separator'] ?? '' ) : '';
		} elseif ( 'seopress' === $store ) {
			$titles = (array) get_option( 'seopress_titles_option_name', array() );
			$sep    = (string) ( $titles['seopress_titles_sep'] ?? '' );
		}
		if ( 'searchpilot' === $store || '' === trim( $sep ) ) {
			$sep = (string) $this->get( Settings::class )->get( 'meta.separator', '–' );
		}
		$sep = trim( html_entity_decode( wp_strip_all_tags( $sep ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' !== $sep ? $sep : '–';
	}

	/* ------------------------------------------------------------------
	 * Pure logic (unit-tested)
	 * ------------------------------------------------------------------ */

	/**
	 * The shared title length rule (Analyzer::title_length_status(), so the
	 * check and the fix always agree).
	 *
	 * @param string $title     Title.
	 * @param array  $type_rule Post-type detection rule (Analyzer::post_type_rule()).
	 * @return array{status:string,graphemes:int,direction:string}
	 */
	public static function rule( $title, array $type_rule = array() ) {
		$title     = trim( (string) $title );
		$graphemes = Text::length( $title );
		$long      = $graphemes > 60;
		if ( method_exists( Analyzer::class, 'title_length_status' ) ) {
			$result    = (array) Analyzer::title_length_status( $title, $type_rule );
			$status    = (string) ( $result['status'] ?? 'pass' );
			$graphemes = isset( $result['graphemes'] ) ? (int) $result['graphemes'] : $graphemes;
			if ( isset( $result['direction'] ) && in_array( $result['direction'], array( 'long', 'short' ), true ) ) {
				$long = 'long' === $result['direction'];
			} elseif ( isset( $result['width'], $result['limit'] ) && (float) $result['limit'] > 0 ) {
				$long = (float) $result['width'] > (float) $result['limit'];
			}
		} elseif ( $graphemes < 15 ) {
			$status = 'fail';
		} elseif ( $graphemes < 30 || $graphemes > 60 ) {
			$status = 'warning';
		} else {
			$status = 'pass';
		}
		$direction = '';
		if ( 'pass' !== $status ) {
			$direction = $long ? 'long' : 'short';
		}
		return array(
			'status'    => $status,
			'graphemes' => $graphemes,
			'direction' => $direction,
		);
	}

	/**
	 * Shorter-title candidates of an over-long title in priority order —
	 * site-name suffix removed, leading clause(s), word-boundary trim —
	 * keeping only those the rule accepts (no database checks).
	 *
	 * @param string   $title  Served title.
	 * @param string   $site   Site name.
	 * @param callable $passes callable( string ): bool — the length rule.
	 * @return array<int,array{text:string,kind:string}>
	 */
	public static function candidates( $title, $site, callable $passes ) {
		$title = trim( (string) $title );
		$core  = self::strip_site_suffix( $title, $site );
		$list  = array();
		if ( $core !== $title ) {
			$list[] = array( $core, 'site' );
		}
		foreach ( array_unique( array( $core, $title ) ) as $base ) {
			$clause = self::leading_clause( $base );
			if ( '' !== $clause && $clause !== $core ) {
				$list[] = array( $clause, 'clause' );
			}
		}
		$trimmed = self::trim_to_fit( $core, $passes );
		if ( '' !== $trimmed ) {
			$list[] = array( $trimmed, 'trim' );
		}
		$out  = array();
		$seen = array();
		foreach ( $list as $item ) {
			list( $text, $kind ) = $item;
			if ( '' === $text || isset( $seen[ $text ] ) || ! call_user_func( $passes, $text ) ) {
				continue;
			}
			$seen[ $text ] = true;
			$out[]         = array(
				'text' => $text,
				'kind' => $kind,
			);
		}
		return $out;
	}

	/**
	 * Fix mode for the kind of shorter title: only the headline variable
	 * (which keeps following the headline) is deterministic; fixed text
	 * (suffix removed as text, leading clause, word cut) needs a person;
	 * nothing safe is advisory.
	 *
	 * @param string $kind site|site_literal|clause|trim|''.
	 * @return string deterministic|assisted|advisory
	 */
	public static function mode_for_kind( $kind ) {
		if ( 'site' === $kind ) {
			return 'deterministic';
		}
		return in_array( (string) $kind, array( 'site_literal', 'clause', 'trim' ), true ) ? 'assisted' : 'advisory';
	}

	/**
	 * Headline variable of an SEO-title store ('' when there is none
	 * SearchPilot can rely on: SEOPress, The SEO Framework).
	 *
	 * @param string $store searchpilot|rank_math|yoast|aioseo|seopress|tsf.
	 * @return string
	 */
	public static function variable_for_store( $store ) {
		$map = array(
			'searchpilot' => '%title%',
			'rank_math'   => '%title%',
			'yoast'       => '%%title%%',
			'aioseo'      => '#post_title',
		);
		return $map[ (string) $store ] ?? '';
	}

	/**
	 * Mode of an over-long title issue from its data. Without the cached kind
	 * (issues stored before 1.1) it is assisted: never unattended.
	 *
	 * @param array $data Issue data.
	 * @return string
	 */
	public static function long_mode( array $data ) {
		return isset( $data['fix_kind'] ) ? self::mode_for_kind( (string) $data['fix_kind'] ) : 'assisted';
	}

	/**
	 * Detection rule of a post's type (Analyzer::post_type_rule()).
	 *
	 * @param \WP_Post|string $post Post or post type.
	 * @return array
	 */
	public static function type_rule( $post ) {
		$type = $post instanceof \WP_Post ? $post->post_type : (string) $post;
		if ( '' === $type || ! method_exists( Analyzer::class, 'post_type_rule' ) ) {
			return array();
		}
		return (array) Analyzer::post_type_rule( $type );
	}

	/**
	 * Direction of a title_length issue (data from 1.1, else from the stored length).
	 *
	 * @param array $issue Issue.
	 * @return string long|short
	 */
	public static function direction_of( array $issue ) {
		$data = (array) ( $issue['data'] ?? array() );
		if ( isset( $data['direction'] ) && in_array( $data['direction'], array( 'long', 'short' ), true ) ) {
			return $data['direction'];
		}
		if ( isset( $data['title'] ) && '' !== (string) $data['title'] ) {
			$direction = self::rule( (string) $data['title'], self::type_rule( (string) ( $data['post_type'] ?? '' ) ) )['direction'];
			if ( '' !== $direction ) {
				return $direction;
			}
		}
		return (int) ( $data['length'] ?? 0 ) > 45 ? 'long' : 'short';
	}

	/**
	 * Leading clause before the first ? ! : or dash ('' when none). ? and !
	 * stay (they belong to the clause); a colon breaks only when a space
	 * follows (not in 10:30), and dashes inside words or number ranges
	 * (2019–2020) are not clause breaks.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function leading_clause( $title ) {
		$title = trim( (string) $title );
		if ( ! preg_match( '/[?!](?=\s|$)|:(?=\s|$)|\s[\x{2013}\x{2014}]|[\x{2013}\x{2014}]\s|\s[|\x{00B7}\x{2022}]\s|\s-\s/u', $title, $m, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}
		$offset = (int) $m[0][1];
		if ( $offset <= 0 ) {
			return '';
		}
		$clause = in_array( $m[0][0], array( '?', '!' ), true ) ? substr( $title, 0, $offset + 1 ) : substr( $title, 0, $offset );
		$clause = trim( (string) $clause );
		if ( '' === $clause || ! preg_match( '/\p{L}/u', $clause ) || $clause === $title ) {
			return '';
		}
		return $clause;
	}

	/**
	 * Removes a trailing (or leading) " {sep} {site name}".
	 *
	 * @param string $title Title.
	 * @param string $site  Site name.
	 * @return string
	 */
	public static function strip_site_suffix( $title, $site ) {
		$title = trim( (string) $title );
		$site  = trim( (string) $site );
		if ( '' === $site || '' === $title ) {
			return $title;
		}
		$sep  = '(?:[\-\x{2013}\x{2014}|:~\x{00B7}\x{2022}\x{00BB}\x{00AB}\/*>]+)';
		$name = preg_quote( $site, '/' );
		if ( preg_match( '/^(.*\S)\s*' . $sep . '\s*' . $name . '$/iu', $title, $m ) && preg_match( '/\p{L}/u', $m[1] ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/^' . $name . '\s*' . $sep . '\s*(\S.*)$/iu', $title, $m ) && preg_match( '/\p{L}/u', $m[1] ) ) {
			return trim( $m[1] );
		}
		return $title;
	}

	/**
	 * Longest word-boundary cut of a text that the rule accepts ('' when none).
	 *
	 * @param string   $text   Text.
	 * @param callable $passes callable( string ): bool.
	 * @param int      $min    Shortest cut to try (graphemes).
	 * @return string
	 */
	public static function trim_to_fit( $text, callable $passes, $min = 20 ) {
		$text     = trim( (string) $text );
		$previous = '';
		// The longest word-boundary cut that fits the result width (C1), when it is accepted.
		if ( method_exists( Text::class, 'serp_truncate' ) ) {
			$candidate = self::clean_tail( Text::serp_truncate( $text, 'title', '' ) );
			if ( '' !== $candidate && $candidate !== $text && call_user_func( $passes, $candidate ) ) {
				return $candidate;
			}
		}
		for ( $n = Text::length( $text ) - 1; $n >= $min; $n-- ) {
			$candidate = self::clean_tail( Text::truncate( $text, $n, '' ) );
			if ( '' === $candidate || $candidate === $previous || $candidate === $text ) {
				continue;
			}
			$previous = $candidate;
			if ( call_user_func( $passes, $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Removes dangling punctuation and trailing function words after a cut.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean_tail( $text ) {
		$chars = " \t\n\r\0\x0B,;:-|/";
		$text  = self::rtrim_unicode( trim( (string) $text ), $chars );
		$stop  = Text::stopwords();
		for ( $i = 0; $i < 3; $i++ ) {
			$words = preg_split( '/\s+/u', $text );
			if ( ! is_array( $words ) || count( $words ) < 3 ) {
				break;
			}
			$last = mb_strtolower( (string) end( $words ), 'UTF-8' );
			if ( is_numeric( $last ) || ! isset( $stop[ $last ] ) ) {
				break;
			}
			array_pop( $words );
			$text = self::rtrim_unicode( implode( ' ', $words ), $chars );
		}
		return $text;
	}

	/**
	 * "{title} {sep} {site}".
	 *
	 * @param string $title Title.
	 * @param string $sep   Separator.
	 * @param string $site  Site name.
	 * @return string
	 */
	public static function with_site_name( $title, $sep, $site ) {
		return trim( (string) $title ) . ' ' . trim( (string) $sep ) . ' ' . trim( (string) $site );
	}

	/**
	 * Yoast separator option → character.
	 *
	 * @param string $key Option value (sc-dash…).
	 * @return string
	 */
	public static function yoast_separator( $key ) {
		$map = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-colon'  => ':',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-star'   => '*',
			'sc-smstar' => '⋆',
			'sc-pipe'   => '|',
			'sc-tilde'  => '~',
			'sc-laquo'  => '«',
			'sc-raquo'  => '»',
			'sc-lt'     => '<',
			'sc-gt'     => '>',
		);
		return $map[ $key ] ?? '-';
	}

	/**
	 * Site name as plain text.
	 *
	 * @return string
	 */
	private static function site_name() {
		return trim( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Right-trims ASCII characters plus en/em dashes and middle dots.
	 *
	 * @param string $text  Text.
	 * @param string $chars ASCII characters.
	 * @return string
	 */
	private static function rtrim_unicode( $text, $chars ) {
		$text = rtrim( (string) $text, $chars );
		return rtrim( (string) preg_replace( '/[\s\x{2013}\x{2014}\x{00B7}\x{2022}]+$/u', '', $text ), $chars );
	}
}
