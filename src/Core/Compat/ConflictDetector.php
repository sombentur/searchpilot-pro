<?php
/**
 * Detects other SEO plugins and decides who owns each output feature.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core\Compat;

use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * SearchPilot never silently duplicates front-end output. For every feature
 * (canonical, meta description, sitemap, schema…) exactly one owner is
 * chosen. With the default "auto" setting another active SEO plugin keeps
 * the feature and SearchPilot works alongside it in companion mode
 * (indexing, monitoring, analytics, internal links). An administrator can
 * hand a feature to SearchPilot only when the other plugin's output for
 * it can be switched off (see suppressible()).
 *
 * Call owner()/owns() lazily (on `wp`, `template_redirect`, `wp_head`, or
 * in admin screens) — other plugins' settings are not final at plugins_loaded.
 */
class ConflictDetector {

	const FEATURES = array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'sitemap', 'news_sitemap', 'breadcrumbs', 'redirects', 'not_found', 'indexnow', 'product_schema' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Memoized detection.
	 *
	 * @var array|null
	 */
	private $detected = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Forgets memoized results (after plugin (de)activation or setting changes).
	 */
	public function reset() {
		$this->detected = null;
	}

	/**
	 * Active SEO-relevant plugins and the features they output.
	 *
	 * @return array<string,array{name:string,version:string,features:string[],kind:string}>
	 */
	public function detect() {
		if ( null !== $this->detected ) {
			return $this->detected;
		}
		$found = array();

		if ( defined( 'WPSEO_VERSION' ) ) {
			$wpseo    = (array) get_option( 'wpseo', array() );
			$titles   = (array) get_option( 'wpseo_titles', array() );
			$features = array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' );
			if ( ! isset( $wpseo['enable_xml_sitemap'] ) || ! empty( $wpseo['enable_xml_sitemap'] ) ) {
				$features[] = 'sitemap';
			}
			if ( ! empty( $titles['breadcrumbs-enable'] ) ) {
				$features[] = 'breadcrumbs';
			}
			if ( defined( 'WPSEO_PREMIUM_VERSION' ) || defined( 'WPSEO_PREMIUM_FILE' ) ) {
				$features[] = 'redirects';
			}
			if ( defined( 'WPSEO_NEWS_VERSION' ) ) {
				$features[] = 'news_sitemap';
			}
			$found['yoast'] = array(
				'name'     => 'Yoast SEO',
				'version'  => (string) WPSEO_VERSION,
				'features' => $features,
				'kind'     => 'seo',
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ) {
			$modules  = (array) get_option( 'rank_math_modules', array() );
			$general  = (array) get_option( 'rank-math-options-general', array() );
			$features = array( 'title', 'description', 'canonical', 'robots', 'social' );
			if ( in_array( 'rich-snippet', $modules, true ) || empty( $modules ) ) {
				$features[] = 'schema';
			}
			if ( in_array( 'sitemap', $modules, true ) || empty( $modules ) ) {
				$features[] = 'sitemap';
			}
			if ( in_array( 'news-sitemap', $modules, true ) ) {
				$features[] = 'news_sitemap';
			}
			if ( in_array( 'redirections', $modules, true ) ) {
				$features[] = 'redirects';
			}
			if ( in_array( '404-monitor', $modules, true ) ) {
				$features[] = 'not_found';
			}
			if ( in_array( 'instant-indexing', $modules, true ) ) {
				$features[] = 'indexnow';
			}
			if ( isset( $general['breadcrumbs'] ) && 'on' === $general['breadcrumbs'] ) {
				$features[] = 'breadcrumbs';
			}
			$found['rank_math'] = array(
				'name'     => 'Rank Math SEO',
				'version'  => defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '',
				'features' => $features,
				'kind'     => 'seo',
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$opts     = json_decode( (string) get_option( 'aioseo_options', '' ), true );
			$features = array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' );
			if ( ! is_array( $opts ) || ! isset( $opts['sitemap']['general']['enable'] ) || ! empty( $opts['sitemap']['general']['enable'] ) ) {
				$features[] = 'sitemap';
			}
			if ( defined( 'AIOSEO_REDIRECTS_VERSION' ) || function_exists( 'aioseoRedirects' ) ) {
				$features[] = 'redirects';
				$features[] = 'not_found';
			}
			if ( defined( 'AIOSEO_INDEX_NOW_VERSION' ) || function_exists( 'aioseoIndexNow' ) ) {
				$features[] = 'indexnow';
			}
			$found['aioseo'] = array(
				'name'     => 'All in One SEO',
				'version'  => defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '',
				'features' => $features,
				'kind'     => 'seo',
			);
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$sitemap  = (array) get_option( 'seopress_xml_sitemap_option_name', array() );
			$features = array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' );
			if ( ! empty( $sitemap['seopress_xml_sitemap_general_enable'] ) ) {
				$features[] = 'sitemap';
			}
			if ( defined( 'SEOPRESS_PRO_VERSION' ) ) {
				$features[] = 'redirects';
				$features[] = 'not_found';
				$features[] = 'breadcrumbs';
			}
			$found['seopress'] = array(
				'name'     => 'SEOPress',
				'version'  => (string) SEOPRESS_VERSION,
				'features' => $features,
				'kind'     => 'seo',
			);
		}

		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$opts     = (array) get_option( 'autodescription-site-settings', array() );
			$features = array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' );
			if ( ! isset( $opts['sitemaps_output'] ) || ! empty( $opts['sitemaps_output'] ) ) {
				$features[] = 'sitemap';
			}
			$found['tsf'] = array(
				'name'     => 'The SEO Framework',
				'version'  => (string) THE_SEO_FRAMEWORK_VERSION,
				'features' => $features,
				'kind'     => 'seo',
			);
		}

		$simple = array(
			'slim_seo'   => array( 'SLIM_SEO_VER', 'Slim SEO', array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'sitemap', 'breadcrumbs', 'redirects' ) ),
			'squirrly'   => array( 'SQ_VERSION', 'Squirrly SEO', array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'sitemap' ) ),
			'smartcrawl' => array( 'SMARTCRAWL_VERSION', 'SmartCrawl', array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'sitemap' ) ),
			'schema_pro' => array( 'BSF_AIOSRS_PRO_VER', 'Schema Pro', array( 'schema' ) ),
			'saswp'      => array( 'SASWP_VERSION', 'Schema & Structured Data for WP', array( 'schema' ) ),
			'redirection' => array( 'REDIRECTION_VERSION', 'Redirection', array( 'redirects', 'not_found' ) ),
			'xmlsf'      => array( 'XMLSF_VERSION', 'XML Sitemap & Google News', array( 'sitemap', 'news_sitemap' ) ),
		);
		foreach ( $simple as $id => $def ) {
			if ( defined( $def[0] ) ) {
				$found[ $id ] = array(
					'name'     => $def[1],
					'version'  => (string) constant( $def[0] ),
					'features' => $def[2],
					'kind'     => 'seo',
				);
			}
		}

		// WP Meta SEO (JoomUnited) prints its own title, description, canonical and social tags.
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		if ( defined( 'WPMSEO_VERSION' ) || in_array( 'wp-meta-seo/wp-meta-seo.php', $active, true ) ) {
			$found['wp_meta_seo'] = array(
				'name'     => 'WP Meta SEO',
				'version'  => defined( 'WPMSEO_VERSION' ) ? (string) WPMSEO_VERSION : '',
				'features' => array( 'title', 'description', 'canonical', 'social' ),
				'kind'     => 'seo',
			);
		}

		if ( class_exists( 'GoogleSitemapGeneratorLoader' ) ) {
			$found['google_xml_sitemaps'] = array(
				'name'     => 'XML Sitemaps (Google XML Sitemaps)',
				'version'  => '',
				'features' => array( 'sitemap' ),
				'kind'     => 'seo',
			);
		}

		if ( class_exists( 'breadcrumb_navxt' ) ) {
			$found['navxt'] = array(
				'name'     => 'Breadcrumb NavXT',
				'version'  => '',
				'features' => array( 'breadcrumbs' ),
				'kind'     => 'seo',
			);
		}

		if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) ) {
			$features = array();
			if ( \Jetpack::is_module_active( 'sitemaps' ) ) {
				$features[] = 'sitemap';
			}
			if ( \Jetpack::is_module_active( 'publicize' ) || \Jetpack::is_module_active( 'sharedaddy' ) ) {
				$features[] = 'social';
			}
			if ( $features ) {
				$found['jetpack'] = array(
					'name'     => 'Jetpack',
					'version'  => defined( 'JETPACK__VERSION' ) ? (string) JETPACK__VERSION : '',
					'features' => $features,
					'kind'     => 'suite',
				);
			}
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$found['woocommerce'] = array(
				'name'     => 'WooCommerce',
				'version'  => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				'features' => array( 'product_schema' ),
				'kind'     => 'commerce',
			);
		}

		/**
		 * Filters detected plugins (add custom integrations here).
		 *
		 * @param array $found Detected plugins.
		 */
		$this->detected = (array) apply_filters( 'searchpilot_detected_plugins', $found );
		return $this->detected;
	}

	/**
	 * Plugins (other than SearchPilot) outputting a feature.
	 *
	 * @param string $feature Feature.
	 * @return string[] Plugin ids.
	 */
	public function providers( $feature ) {
		$ids = array();
		foreach ( $this->detect() as $id => $plugin ) {
			if ( in_array( $feature, $plugin['features'], true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Owner of a feature.
	 *
	 * @param string $feature Feature.
	 * @return string searchpilot | off | plugin id
	 */
	public function owner( $feature ) {
		$setting_key = 'news_sitemap' === $feature ? 'sitemap' : ( 'not_found' === $feature ? 'redirects' : $feature );
		$setting     = in_array( $setting_key, array( 'indexnow', 'product_schema' ), true ) ? 'auto' : (string) $this->settings->get( 'ownership.' . $setting_key, 'auto' );
		$providers   = $this->providers( $feature );

		if ( 'off' === $setting ) {
			$owner = 'off';
		} elseif ( empty( $providers ) ) {
			$owner = 'searchpilot';
		} elseif ( 'auto' === $setting && $this->only_suites( $providers ) && $this->suppressible_all( $providers, $feature ) ) {
			// Side features of suites (e.g. Jetpack's Open Graph tags) yield to SearchPilot.
			$owner = 'searchpilot';
		} elseif ( 'searchpilot' === $setting && $this->suppressible_all( $providers, $feature ) ) {
			$owner = 'searchpilot';
		} else {
			$owner = $providers[0];
		}
		/**
		 * Filters the owner of an output feature.
		 *
		 * @param string   $owner     Owner id.
		 * @param string   $feature   Feature.
		 * @param string[] $providers Other plugins providing it.
		 */
		return (string) apply_filters( 'searchpilot_feature_owner', $owner, $feature, $providers );
	}

	/**
	 * Whether SearchPilot outputs a feature.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function owns( $feature ) {
		return 'searchpilot' === $this->owner( $feature );
	}

	/**
	 * Whether SearchPilot must switch off another plugin's output (forced takeover).
	 *
	 * @param string $plugin  Plugin id.
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function must_suppress( $plugin, $feature ) {
		return $this->owns( $feature ) && in_array( $plugin, $this->providers( $feature ), true );
	}

	/**
	 * Features SearchPilot can switch off in another plugin through its
	 * public filters. Anything not listed must be disabled in that plugin.
	 *
	 * @return array<string,string[]>
	 */
	public static function suppressible_map() {
		return array(
			'yoast'     => array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' ),
			'rank_math' => array( 'title', 'description', 'canonical', 'robots', 'social', 'schema' ),
			'jetpack'   => array( 'social' ),
		);
	}

	/**
	 * Whether every provider's output can be suppressed.
	 *
	 * @param string[] $providers Plugin ids.
	 * @param string   $feature   Feature.
	 * @return bool
	 */
	private function suppressible_all( array $providers, $feature ) {
		$map = self::suppressible_map();
		foreach ( $providers as $id ) {
			if ( empty( $map[ $id ] ) || ! in_array( $feature, $map[ $id ], true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether all providers are multi-purpose suites rather than SEO plugins.
	 *
	 * @param string[] $providers Plugin ids.
	 * @return bool
	 */
	private function only_suites( array $providers ) {
		$detected = $this->detect();
		foreach ( $providers as $id ) {
			if ( 'suite' !== ( $detected[ $id ]['kind'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Primary companion SEO plugin (for reading its meta), or ''.
	 *
	 * @return string yoast|rank_math|aioseo|seopress|tsf|''
	 */
	public function primary_seo_plugin() {
		foreach ( array( 'rank_math', 'yoast', 'aioseo', 'seopress', 'tsf', 'slim_seo', 'squirrly', 'smartcrawl' ) as $id ) {
			if ( isset( $this->detect()[ $id ] ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Report for the admin UI.
	 *
	 * @return array
	 */
	public function report() {
		$features = array();
		$labels   = self::feature_labels();
		foreach ( self::FEATURES as $feature ) {
			$providers            = $this->providers( $feature );
			$owner                = $this->owner( $feature );
			$features[ $feature ] = array(
				'label'        => $labels[ $feature ] ?? $feature,
				'owner'        => $owner,
				'providers'    => $providers,
				'conflict'     => count( $providers ) > 1,
				'suppressible' => $this->suppressible_all( $providers, $feature ),
			);
		}
		return array(
			'plugins'   => $this->detect(),
			'features'  => $features,
			'companion' => '' !== $this->primary_seo_plugin(),
			'primary'   => $this->primary_seo_plugin(),
		);
	}

	/**
	 * Feature labels.
	 *
	 * @return array<string,string>
	 */
	public static function feature_labels() {
		return array(
			'title'          => __( 'SEO titles', 'searchpilot-pro' ),
			'description'    => __( 'Meta descriptions', 'searchpilot-pro' ),
			'canonical'      => __( 'Canonical URLs', 'searchpilot-pro' ),
			'robots'         => __( 'Robots meta', 'searchpilot-pro' ),
			'social'         => __( 'Open Graph & X/Twitter cards', 'searchpilot-pro' ),
			'schema'         => __( 'Structured data (JSON-LD)', 'searchpilot-pro' ),
			'sitemap'        => __( 'XML sitemaps', 'searchpilot-pro' ),
			'news_sitemap'   => __( 'Google News sitemap', 'searchpilot-pro' ),
			'breadcrumbs'    => __( 'Breadcrumbs', 'searchpilot-pro' ),
			'redirects'      => __( 'Redirects', 'searchpilot-pro' ),
			'not_found'      => __( '404 monitor', 'searchpilot-pro' ),
			'indexnow'       => __( 'IndexNow submissions', 'searchpilot-pro' ),
			'product_schema' => __( 'Product structured data', 'searchpilot-pro' ),
		);
	}
}
