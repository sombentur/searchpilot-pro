<?php
/**
 * Plugin settings with a declarative, sanitized schema.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Settings live in one autoloaded option (`searchpilot_settings`) as a
 * nested array. Keys are addressed with dot notation: `sitemap.enabled`.
 * API credentials are never stored here — see Credentials.
 */
class Settings {

	const OPTION = 'searchpilot_settings';

	/**
	 * Cached merged settings.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Settings schema: flat dot key => definition.
	 *
	 * Types: bool, int, float, string, text (multi-line), url, email_list,
	 * enum (values), int_list, string_list, map (item => sub-schema).
	 *
	 * @return array<string,array>
	 */
	public static function schema() {
		$schema_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage', 'none' );
		return array(
			// General.
			'general.mode'                        => array( 'type' => 'enum', 'values' => array( 'manual', 'assisted', 'autopilot' ), 'default' => 'assisted' ),
			'general.environment_guard'           => array( 'type' => 'bool', 'default' => true ),
			'general.delete_data_on_uninstall'    => array( 'type' => 'bool', 'default' => false ),
			'general.log_level'                   => array( 'type' => 'enum', 'values' => array( 'debug', 'info', 'warning', 'error' ), 'default' => 'info' ),
			'general.log_retention_days'          => array( 'type' => 'int', 'min' => 1, 'max' => 365, 'default' => 30 ),
			'general.history_retention_days'      => array( 'type' => 'int', 'min' => 7, 'max' => 730, 'default' => 90 ),
			'general.admin_bar'                   => array( 'type' => 'bool', 'default' => true ),

			// Content types. Keys of the maps are post type / taxonomy names.
			'content.post_types'                  => array(
				'type'    => 'map',
				'default' => array(),
				'item'    => array(
					'index'   => array( 'type' => 'bool', 'default' => true ),
					'sitemap' => array( 'type' => 'bool', 'default' => true ),
					'schema'  => array( 'type' => 'enum', 'values' => $schema_types, 'default' => 'Article' ),
				),
			),
			'content.taxonomies'                  => array(
				'type'    => 'map',
				'default' => array(),
				'item'    => array(
					'index'   => array( 'type' => 'bool', 'default' => true ),
					'sitemap' => array( 'type' => 'bool', 'default' => true ),
				),
			),
			'content.authors_index'               => array( 'type' => 'bool', 'default' => true ),
			'content.authors_sitemap'             => array( 'type' => 'bool', 'default' => true ),
			'content.date_archives_noindex'       => array( 'type' => 'bool', 'default' => true ),
			'content.search_noindex'              => array( 'type' => 'bool', 'default' => true ),
			'content.paginated_noindex'           => array( 'type' => 'bool', 'default' => false ),
			'content.attachment_redirect'         => array( 'type' => 'bool', 'default' => true ),
			'content.thin_words'                  => array( 'type' => 'int', 'min' => 50, 'max' => 2000, 'default' => 300 ),
			// Per post type detection rules (Analyzer::post_type_rule() merges built-in defaults under these).
			'analysis.post_type_rules'            => array(
				'type'    => 'map',
				'default' => array(
					'page' => array(
						'thin_words'     => 0,
						'featured_image' => false,
						'utility'        => true,
					),
				),
				'item'    => array(
					// -1 = inherit content.thin_words, 0 = never report thin content.
					'thin_words'     => array( 'type' => 'int', 'min' => -1, 'max' => 5000, 'default' => -1 ),
					'featured_image' => array( 'type' => 'bool', 'default' => true ),
					'utility'        => array( 'type' => 'bool', 'default' => false ),
				),
			),
			'content.excluded_ids'                => array( 'type' => 'int_list', 'default' => array() ),

			// Meta output (only when SearchPilot owns the feature).
			'meta.separator'                      => array( 'type' => 'string', 'default' => '–', 'max' => 5 ),
			'meta.title_post'                     => array( 'type' => 'template', 'default' => '%title% %sep% %sitename%' ),
			'meta.title_page'                     => array( 'type' => 'template', 'default' => '%title% %sep% %sitename%' ),
			'meta.title_term'                     => array( 'type' => 'template', 'default' => '%term% %sep% %sitename%' ),
			'meta.title_author'                   => array( 'type' => 'template', 'default' => '%author% %sep% %sitename%' ),
			'meta.title_archive'                  => array( 'type' => 'template', 'default' => '%archive% %sep% %sitename%' ),
			'meta.title_search'                   => array( 'type' => 'template', 'default' => '%search% %sep% %sitename%' ),
			'meta.title_404'                      => array( 'type' => 'template', 'default' => '%notfound% %sep% %sitename%' ),
			'meta.home_title'                     => array( 'type' => 'template', 'default' => '' ),
			'meta.home_description'               => array( 'type' => 'template_text', 'default' => '' ),
			'meta.auto_description'               => array( 'type' => 'bool', 'default' => true ),
			'meta.og_enabled'                     => array( 'type' => 'bool', 'default' => true ),
			'meta.twitter_enabled'                => array( 'type' => 'bool', 'default' => true ),
			'meta.twitter_site'                   => array( 'type' => 'string', 'default' => '', 'max' => 32 ),
			'meta.default_image'                  => array( 'type' => 'int', 'min' => 0, 'default' => 0 ),
			'meta.facebook_app_id'                => array( 'type' => 'string', 'default' => '', 'max' => 32 ),
			'meta.max_image_preview_large'        => array( 'type' => 'bool', 'default' => true ),

			// Feature ownership: auto = SearchPilot unless another SEO plugin already outputs it.
			'ownership.title'                     => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.description'               => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.canonical'                 => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.robots'                    => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.social'                    => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.schema'                    => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.sitemap'                   => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.breadcrumbs'               => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),
			'ownership.redirects'                 => array( 'type' => 'enum', 'values' => array( 'auto', 'searchpilot', 'off' ), 'default' => 'auto' ),

			// Sitemaps.
			'sitemap.max_urls'                    => array( 'type' => 'int', 'min' => 100, 'max' => 50000, 'default' => 1000 ),
			'sitemap.images'                      => array( 'type' => 'bool', 'default' => true ),
			'sitemap.video'                       => array( 'type' => 'bool', 'default' => true ),
			'sitemap.news'                        => array( 'type' => 'bool', 'default' => false ),
			'sitemap.news_name'                   => array( 'type' => 'string', 'default' => '' ),
			'sitemap.news_language'               => array( 'type' => 'string', 'default' => '', 'max' => 12 ),
			'sitemap.news_post_types'             => array( 'type' => 'string_list', 'default' => array( 'post' ) ),
			'sitemap.news_categories'             => array( 'type' => 'int_list', 'default' => array() ),
			'sitemap.stylesheet'                  => array( 'type' => 'bool', 'default' => true ),
			'sitemap.submit_on_change'            => array( 'type' => 'bool', 'default' => true ),
			'sitemap.submit_throttle_hours'       => array( 'type' => 'int', 'min' => 1, 'max' => 168, 'default' => 6 ),

			// Indexing & submission.
			'indexing.indexnow'                   => array( 'type' => 'bool', 'default' => true ),
			'indexing.indexnow_key'               => array( 'type' => 'string', 'default' => '', 'max' => 128 ),
			'indexing.indexnow_endpoint'          => array( 'type' => 'enum', 'values' => array( 'api.indexnow.org', 'www.bing.com', 'yandex.com', 'search.seznam.cz', 'searchadvisor.naver.com', 'indexnow.yep.com' ), 'default' => 'api.indexnow.org' ),
			'indexing.bing_url_submission'        => array( 'type' => 'bool', 'default' => false ),
			'indexing.google_indexing_api'        => array( 'type' => 'bool', 'default' => false ),
			'indexing.indexing_api_post_types'    => array( 'type' => 'string_list', 'default' => array() ),
			'indexing.notify_publish'             => array( 'type' => 'bool', 'default' => true ),
			'indexing.notify_update'              => array( 'type' => 'bool', 'default' => true ),
			'indexing.notify_delete'              => array( 'type' => 'bool', 'default' => true ),
			'indexing.notify_terms'               => array( 'type' => 'bool', 'default' => false ),
			'indexing.change_threshold'           => array( 'type' => 'int', 'min' => 0, 'max' => 64, 'default' => 3 ),
			'indexing.resubmit_cooldown_minutes'  => array( 'type' => 'int', 'min' => 1, 'max' => 10080, 'default' => 60 ),
			'indexing.inspection_daily_budget'    => array( 'type' => 'int', 'min' => 0, 'max' => 2000, 'default' => 400 ),
			'indexing.crawl_batch'                => array( 'type' => 'int', 'min' => 1, 'max' => 200, 'default' => 20 ),
			'indexing.recheck_days'               => array( 'type' => 'int', 'min' => 1, 'max' => 90, 'default' => 14 ),
			'indexing.origin_ip'                  => array( 'type' => 'string', 'default' => '', 'max' => 45 ),
			'indexing.monitor_pagination'         => array( 'type' => 'bool', 'default' => true ),

			// Search engine connection choices (not secrets).
			'engines.google_property'             => array( 'type' => 'string', 'default' => '' ),
			'engines.google_auth'                 => array( 'type' => 'enum', 'values' => array( 'oauth', 'service_account' ), 'default' => 'oauth' ),
			'engines.bing_site'                   => array( 'type' => 'string', 'default' => '' ),

			// Schema.
			'schema.entity'                       => array( 'type' => 'enum', 'values' => array( 'organization', 'person' ), 'default' => 'organization' ),
			'schema.org_name'                     => array( 'type' => 'string', 'default' => '' ),
			'schema.org_legal_name'               => array( 'type' => 'string', 'default' => '' ),
			'schema.org_logo'                     => array( 'type' => 'int', 'min' => 0, 'default' => 0 ),
			'schema.person_user'                  => array( 'type' => 'int', 'min' => 0, 'default' => 0 ),
			'schema.same_as'                      => array( 'type' => 'url_list', 'default' => array() ),
			'schema.contact_email'                => array( 'type' => 'string', 'default' => '' ),
			'schema.contact_phone'                => array( 'type' => 'string', 'default' => '', 'max' => 40 ),
			'schema.breadcrumbs'                  => array( 'type' => 'enum', 'values' => array( 'displayed', 'always', 'off' ), 'default' => 'displayed' ),
			'schema.faq'                          => array( 'type' => 'bool', 'default' => true ),
			'schema.video'                        => array( 'type' => 'bool', 'default' => true ),
			'schema.news_categories'              => array( 'type' => 'int_list', 'default' => array() ),

			// Breadcrumbs.
			'breadcrumbs.separator'               => array( 'type' => 'string', 'default' => '›', 'max' => 5 ),
			'breadcrumbs.home_label'              => array( 'type' => 'string', 'default' => '' ),
			'breadcrumbs.show_current'            => array( 'type' => 'bool', 'default' => true ),
			'breadcrumbs.show_blog'               => array( 'type' => 'bool', 'default' => true ),
			'breadcrumbs.prefix'                  => array( 'type' => 'string', 'default' => '' ),

			// Internal links.
			'links.suggestions'                   => array( 'type' => 'bool', 'default' => true ),
			'links.auto_insert'                   => array( 'type' => 'bool', 'default' => false ),
			'links.max_auto_per_post'             => array( 'type' => 'int', 'min' => 0, 'max' => 20, 'default' => 3 ),
			'links.auto_related_block'            => array( 'type' => 'bool', 'default' => false ),
			'links.max_links_per_post'            => array( 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 12 ),
			'links.words_per_link'                => array( 'type' => 'int', 'min' => 30, 'max' => 1000, 'default' => 150 ),
			'links.max_inbound_per_new_post'      => array( 'type' => 'int', 'min' => 0, 'max' => 50, 'default' => 5 ),
			'links.min_relevance'                 => array( 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 25 ),
			'links.post_types'                    => array( 'type' => 'string_list', 'default' => array( 'post', 'page' ) ),
			'links.excluded_ids'                  => array( 'type' => 'int_list', 'default' => array() ),

			// Redirects & 404.
			'redirects.auto_slug'                 => array( 'type' => 'bool', 'default' => true ),
			'redirects.monitor_404'               => array( 'type' => 'bool', 'default' => true ),
			'redirects.log_bots'                  => array( 'type' => 'bool', 'default' => true ),
			'redirects.retention_404_days'        => array( 'type' => 'int', 'min' => 1, 'max' => 365, 'default' => 60 ),
			'redirects.ignore_patterns'           => array( 'type' => 'raw_text', 'default' => "/wp-content/*.map\n/favicon.ico\n/apple-touch-icon*" ),

			// robots.txt (virtual file only).
			'robots.manage'                       => array( 'type' => 'bool', 'default' => true ),
			'robots.custom_rules'                 => array( 'type' => 'raw_text', 'default' => '' ),
			'robots.sitemap_line'                 => array( 'type' => 'bool', 'default' => true ),

			// Analytics.
			'analytics.fresh_data'                => array( 'type' => 'bool', 'default' => false ),
			'analytics.bing'                      => array( 'type' => 'bool', 'default' => true ),
			'analytics.min_impressions'           => array( 'type' => 'int', 'min' => 1, 'max' => 100000, 'default' => 100 ),

			// Alerts & reports.
			'alerts.email'                        => array( 'type' => 'bool', 'default' => false ),
			'alerts.recipients'                   => array( 'type' => 'email_list', 'default' => array() ),
			'alerts.min_severity'                 => array( 'type' => 'enum', 'values' => array( 'critical', 'high', 'medium' ), 'default' => 'critical' ),
			'alerts.admin_notices'                => array( 'type' => 'bool', 'default' => true ),
			'alerts.report'                       => array( 'type' => 'enum', 'values' => array( 'off', 'weekly', 'monthly' ), 'default' => 'off' ),
			'alerts.report_recipients'            => array( 'type' => 'email_list', 'default' => array() ),
			'alerts.traffic_drop_percent'         => array( 'type' => 'int', 'min' => 5, 'max' => 95, 'default' => 40 ),
			'alerts.index_drop_percent'           => array( 'type' => 'int', 'min' => 5, 'max' => 95, 'default' => 20 ),
			'alerts.error_spike'                  => array( 'type' => 'int', 'min' => 5, 'max' => 100000, 'default' => 50 ),

			// Automation task overrides: default = follow the mode matrix.
			'automation.tasks'                    => array(
				'type'    => 'map',
				'default' => array(),
				'item'    => array(
					'policy' => array( 'type' => 'enum', 'values' => array( 'default', 'auto', 'approval', 'off' ), 'default' => 'default' ),
				),
			),
		);
	}

	/**
	 * Default nested settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::schema() as $key => $def ) {
			self::set_path( $out, $key, $def['default'] );
		}
		return $out;
	}

	/**
	 * Returns all settings merged over defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$this->cache = self::merge( self::defaults(), $stored );
		}
		return $this->cache;
	}

	/**
	 * Reads a value by dot key.
	 *
	 * @param string $key     Dot key, e.g. sitemap.enabled.
	 * @param mixed  $default Fallback when missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$value = self::get_path( $this->all(), $key );
		if ( null === $value ) {
			$schema = self::schema();
			return isset( $schema[ $key ] ) ? $schema[ $key ]['default'] : $default;
		}
		return $value;
	}

	/**
	 * Updates a partial nested or dot-keyed array of settings.
	 *
	 * Unknown keys are dropped; every value is sanitized by its schema type.
	 *
	 * @param array $changes Nested (['sitemap' => ['news' => true]]) or dot-keyed (['sitemap.news' => true]) values.
	 * @return array{settings:array,errors:array<string,string>}
	 */
	public function update( array $changes ) {
		$schema  = self::schema();
		$current = $this->all();
		$flat    = self::flatten_input( $changes, $schema );
		$errors  = array();
		$before  = $current;

		foreach ( $flat as $key => $value ) {
			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}
			$clean = self::sanitize_value( $value, $schema[ $key ] );
			if ( $clean instanceof \WP_Error ) {
				$errors[ $key ] = $clean->get_error_message();
				continue;
			}
			self::set_path( $current, $key, $clean );
		}

		update_option( self::OPTION, $current, true );
		$this->cache = null;

		/**
		 * Fires after settings changed.
		 *
		 * @param array $new    New settings.
		 * @param array $before Previous settings.
		 */
		do_action( 'searchpilot_settings_updated', $this->all(), $before );

		return array(
			'settings' => $this->all(),
			'errors'   => $errors,
		);
	}

	/**
	 * Clears the in-memory cache (after external option writes).
	 */
	public function flush() {
		$this->cache = null;
	}

	/**
	 * Converts nested input to flat schema keys. Map-typed keys stop the descent.
	 *
	 * @param array  $input  Input.
	 * @param array  $schema Schema.
	 * @param string $prefix Current prefix.
	 * @return array
	 */
	private static function flatten_input( array $input, array $schema, $prefix = '' ) {
		$out = array();
		foreach ( $input as $k => $v ) {
			$key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( isset( $schema[ $key ] ) ) {
				$out[ $key ] = $v;
				continue;
			}
			if ( is_array( $v ) ) {
				$out = array_merge( $out, self::flatten_input( $v, $schema, $key ) );
			}
		}
		return $out;
	}

	/**
	 * Sanitizes one value.
	 *
	 * @param mixed $value Raw value.
	 * @param array $def   Schema definition.
	 * @return mixed|\WP_Error
	 */
	public static function sanitize_value( $value, array $def ) {
		switch ( $def['type'] ) {
			case 'bool':
				return (bool) rest_sanitize_boolean( $value );
			case 'int':
				if ( ! is_numeric( $value ) ) {
					return new \WP_Error( 'invalid', __( 'Enter a number.', 'searchpilot-pro' ) );
				}
				$value = (int) $value;
				if ( isset( $def['min'] ) ) {
					$value = max( (int) $def['min'], $value );
				}
				if ( isset( $def['max'] ) ) {
					$value = min( (int) $def['max'], $value );
				}
				return $value;
			case 'float':
				return is_numeric( $value ) ? (float) $value : (float) $def['default'];
			case 'string':
				$value = sanitize_text_field( (string) $value );
				if ( isset( $def['max'] ) ) {
					$value = mb_substr( $value, 0, (int) $def['max'] );
				}
				return $value;
			case 'text':
				return sanitize_textarea_field( (string) $value );
			case 'template':
				// Keeps %category%, %date%… (sanitize_text_field would eat "%ca"/"%da").
				$value = \SearchPilot\Support\Text::clean( $value );
				return isset( $def['max'] ) ? mb_substr( $value, 0, (int) $def['max'] ) : $value;
			case 'raw_text':
				return self::sanitize_raw_text( $value );
			case 'template_text':
				return \SearchPilot\Support\Text::clean( $value, true );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'enum':
				$value = (string) $value;
				if ( ! in_array( $value, $def['values'], true ) ) {
					return new \WP_Error( 'invalid', __( 'Choose one of the listed options.', 'searchpilot-pro' ) );
				}
				return $value;
			case 'int_list':
				$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
				return array_values( array_unique( array_filter( array_map( 'absint', (array) $list ) ) ) );
			case 'string_list':
				$list = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
				return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $list ) ) ) );
			case 'url_list':
				$list = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
				return array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_map( 'trim', (array) $list ) ) ) ) );
			case 'email_list':
				$list    = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
				$valid   = array();
				$invalid = array();
				foreach ( array_filter( array_map( 'trim', array_map( 'strval', (array) $list ) ) ) as $address ) {
					$clean = sanitize_email( $address );
					if ( '' !== $clean && is_email( $clean ) ) {
						$valid[] = $clean;
					} else {
						$invalid[] = sanitize_text_field( $address );
					}
				}
				if ( $invalid ) {
					/* translators: %s: invalid e-mail addresses. */
					return new \WP_Error( 'invalid', sprintf( __( 'Not a valid e-mail address: %s', 'searchpilot-pro' ), implode( ', ', $invalid ) ) );
				}
				return array_values( array_unique( $valid ) );
			case 'map':
				if ( ! is_array( $value ) ) {
					return array();
				}
				$out = array();
				foreach ( $value as $map_key => $item ) {
					$map_key = sanitize_key( $map_key );
					if ( '' === $map_key || ! is_array( $item ) ) {
						continue;
					}
					$clean_item = array();
					foreach ( $def['item'] as $field => $field_def ) {
						$raw                  = array_key_exists( $field, $item ) ? $item[ $field ] : $field_def['default'];
						$clean                = self::sanitize_value( $raw, $field_def );
						$clean_item[ $field ] = $clean instanceof \WP_Error ? $field_def['default'] : $clean;
					}
					$out[ $map_key ] = $clean_item;
				}
				return $out;
		}
		return $def['default'];
	}

	/**
	 * Multi-line text of URL paths/rules (robots.txt, URL patterns). Unlike
	 * sanitize_textarea_field() it keeps percent-encoded octets (%E0%B2%95 —
	 * Kannada and other non-Latin paths), which WordPress would delete.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_raw_text( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_check_invalid_utf8( $value );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$lines = array();
		foreach ( explode( "\n", $value ) as $line ) {
			// Drop control characters and anything tag-like; keep everything else verbatim.
			$line    = (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $line );
			$line    = wp_strip_all_tags( $line );
			$lines[] = rtrim( $line );
		}
		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Recursive merge where stored scalars and lists replace defaults.
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && self::is_assoc( $defaults[ $key ] ) && self::is_assoc( $value ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/**
	 * Whether an array is associative (a non-empty dictionary).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		return ! empty( $arr ) && array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Reads a nested value.
	 *
	 * @param array  $arr Array.
	 * @param string $key Dot key.
	 * @return mixed|null
	 */
	private static function get_path( array $arr, $key ) {
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $arr ) || ! array_key_exists( $part, $arr ) ) {
				return null;
			}
			$arr = $arr[ $part ];
		}
		return $arr;
	}

	/**
	 * Writes a nested value.
	 *
	 * @param array  $arr   Array (by reference).
	 * @param string $key   Dot key.
	 * @param mixed  $value Value.
	 */
	private static function set_path( array &$arr, $key, $value ) {
		$parts = explode( '.', $key );
		$last  = array_pop( $parts );
		$ref   = &$arr;
		foreach ( $parts as $part ) {
			if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
				$ref[ $part ] = array();
			}
			$ref = &$ref[ $part ];
		}
		$ref[ $last ] = $value;
	}
}
