<?php
/**
 * Switches off another SEO plugin's output for features handed to SearchPilot.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Core\Compat\ConflictDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Only acts when an administrator forced a feature to SearchPilot while Yoast
 * SEO or Rank Math is active (ConflictDetector::must_suppress()). Uses the
 * plugins' public filters only and touches nothing else, so companion mode
 * stays untouched. Every callback is defensive: the other plugin's API may
 * change between versions.
 */
class Suppressor {

	/**
	 * Yoast presenter class fragments per feature.
	 */
	const YOAST_PRESENTERS = array(
		'title'       => array( '\\Title_Presenter' ),
		'description' => array( '\\Meta_Description_Presenter' ),
		'canonical'   => array( '\\Canonical_Presenter', '\\Rel_Prev_Presenter', '\\Rel_Next_Presenter' ),
		'robots'      => array( '\\Robots_Presenter', '\\Googlebot_Presenter', '\\Bingbot_Presenter' ),
		'social'      => array( '\\Open_Graph\\', '\\Twitter\\', '\\Slack\\' ),
		'schema'      => array( '\\Schema_Presenter' ),
	);

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Titles.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Suppressed features per plugin, decided on `wp`.
	 *
	 * @var array<string,string[]>
	 */
	private $active = array();

	/**
	 * Constructor.
	 *
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param TitleResolver    $titles    Titles.
	 */
	public function __construct( ConflictDetector $conflicts, TitleResolver $titles ) {
		$this->conflicts = $conflicts;
		$this->titles    = $titles;
	}

	/**
	 * Features SearchPilot suppresses for a plugin.
	 *
	 * @param string $plugin yoast|rank_math.
	 * @return string[]
	 */
	public function features_for( $plugin ) {
		$out = array();
		foreach ( ConflictDetector::suppressible_map()[ $plugin ] ?? array() as $feature ) {
			if ( $this->conflicts->must_suppress( $plugin, $feature ) ) {
				$out[] = $feature;
			}
		}
		return $out;
	}

	/**
	 * Whether any robots output of another plugin is being replaced.
	 *
	 * @return bool
	 */
	public function replaces_robots() {
		return in_array( 'robots', $this->active['yoast'] ?? array(), true ) || in_array( 'robots', $this->active['rank_math'] ?? array(), true );
	}

	/**
	 * Adds the suppression filters (front end, on `wp`).
	 */
	public function apply() {
		$this->active = array(
			'yoast'     => $this->features_for( 'yoast' ),
			'rank_math' => $this->features_for( 'rank_math' ),
		);
		if ( $this->active['yoast'] ) {
			$this->suppress_yoast( $this->active['yoast'] );
		}
		if ( $this->active['rank_math'] ) {
			$this->suppress_rank_math( $this->active['rank_math'] );
		}
		if ( $this->replaces_robots() && ! has_action( 'wp_head', 'wp_robots' ) && function_exists( 'wp_robots' ) ) {
			// Both plugins remove core's robots tag in favour of their own.
			add_action( 'wp_head', 'wp_robots', 1 );
		}
	}

	/**
	 * Yoast SEO.
	 *
	 * @param string[] $features Features.
	 */
	private function suppress_yoast( array $features ) {
		add_filter(
			'wpseo_frontend_presenters',
			function ( $presenters ) use ( $features ) {
				return is_array( $presenters ) ? self::filter_yoast_presenters( $presenters, $features ) : $presenters;
			},
			PHP_INT_MAX
		);
		if ( in_array( 'title', $features, true ) ) {
			add_filter( 'wpseo_title', array( $this, 'our_title' ), PHP_INT_MAX );
		}
		if ( in_array( 'description', $features, true ) ) {
			add_filter( 'wpseo_metadesc', '__return_empty_string', PHP_INT_MAX );
		}
		if ( in_array( 'canonical', $features, true ) ) {
			add_filter( 'wpseo_canonical', '__return_false', PHP_INT_MAX );
			add_filter( 'wpseo_prev_rel_link', '__return_false', PHP_INT_MAX );
			add_filter( 'wpseo_next_rel_link', '__return_false', PHP_INT_MAX );
		}
		if ( in_array( 'robots', $features, true ) ) {
			add_filter( 'wpseo_robots', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'wpseo_robots_array', '__return_empty_array', PHP_INT_MAX );
		}
		if ( in_array( 'social', $features, true ) ) {
			add_filter( 'wpseo_output_twitter_card', '__return_false', PHP_INT_MAX );
			add_filter( 'wpseo_enable_structured_data_opengraph', '__return_false', PHP_INT_MAX );
		}
		if ( in_array( 'schema', $features, true ) ) {
			add_filter( 'wpseo_json_ld_output', '__return_false', PHP_INT_MAX );
			add_filter( 'wpseo_schema_graph', '__return_empty_array', PHP_INT_MAX );
		}
	}

	/**
	 * Removes Yoast presenters of suppressed features (pure).
	 *
	 * @param array    $presenters Presenter objects (or class names).
	 * @param string[] $features   Features.
	 * @return array
	 */
	public static function filter_yoast_presenters( array $presenters, array $features ) {
		$fragments = array();
		foreach ( $features as $feature ) {
			$fragments = array_merge( $fragments, self::YOAST_PRESENTERS[ $feature ] ?? array() );
		}
		if ( ! $fragments ) {
			return $presenters;
		}
		$out = array();
		foreach ( $presenters as $key => $presenter ) {
			$class = '\\' . ltrim( is_object( $presenter ) ? get_class( $presenter ) : (string) $presenter, '\\' );
			$drop  = false;
			foreach ( $fragments as $fragment ) {
				// "\Open_Graph\" matches a namespace; "\Title_Presenter" only the top-level presenter, not Open_Graph\Title_Presenter.
				$match = '\\' === substr( $fragment, -1 )
					? false !== strpos( $class, $fragment )
					: substr( $class, -strlen( '\\Presenters' . $fragment ) ) === '\\Presenters' . $fragment;
				if ( $match ) {
					$drop = true;
					break;
				}
			}
			if ( ! $drop ) {
				$out[ $key ] = $presenter;
			}
		}
		return is_int( key( $presenters ) ) ? array_values( $out ) : $out;
	}

	/**
	 * Rank Math.
	 *
	 * @param string[] $features Features.
	 */
	private function suppress_rank_math( array $features ) {
		if ( in_array( 'title', $features, true ) ) {
			add_filter( 'rank_math/frontend/title', array( $this, 'our_title' ), PHP_INT_MAX );
		}
		if ( in_array( 'description', $features, true ) ) {
			add_filter( 'rank_math/frontend/description', '__return_empty_string', PHP_INT_MAX );
		}
		if ( in_array( 'canonical', $features, true ) ) {
			add_filter( 'rank_math/frontend/canonical', '__return_empty_string', PHP_INT_MAX );
			add_filter( 'rank_math/frontend/disable_adjacent_rel_links', '__return_true', PHP_INT_MAX );
		}
		if ( in_array( 'robots', $features, true ) ) {
			add_filter( 'rank_math/frontend/robots', '__return_empty_array', PHP_INT_MAX );
		}
		if ( in_array( 'schema', $features, true ) ) {
			add_filter( 'rank_math/json_ld', '__return_empty_array', PHP_INT_MAX );
		}
		if ( in_array( 'social', $features, true ) ) {
			$remove = static function () {
				remove_all_actions( 'rank_math/opengraph/facebook' );
				remove_all_actions( 'rank_math/opengraph/twitter' );
				remove_all_actions( 'rank_math/opengraph/slack' );
			};
			// Rank Math adds its Open Graph hooks late; clear them right before its head output.
			add_action( 'rank_math/head', $remove, 0 );
			add_action( 'wp_head', $remove, 0 );
		}
	}

	/**
	 * Title filter callback: SearchPilot's title (escaped for attribute/HTML use).
	 *
	 * @param string $title Other plugin's title.
	 * @return string
	 */
	public function our_title( $title = '' ) {
		try {
			$ours = $this->titles->title();
		} catch ( \Throwable $e ) {
			return $title;
		}
		return '' !== $ours ? $ours : $title;
	}
}
