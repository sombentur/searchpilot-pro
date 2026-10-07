<?php
/**
 * Sitemap and robots.txt endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Sitemap\RobotsTxt;
use SearchPilot\Sitemap\SitemapHealth;
use SearchPilot\Sitemap\SitemapModule;
use SearchPilot\Sitemap\SitemapRouter;
use SearchPilot\Sitemap\SitemapXml;
use SearchPilot\Sitemap\VideoDetector;

defined( 'ABSPATH' ) || exit;

/**
 * GET /sitemaps, POST /sitemaps/regenerate|submit|health, GET /sitemaps/preview,
 * GET|POST /robots, POST /robots/preview, GET /robots/test.
 */
class SitemapsController extends Controller {

	/**
	 * Sitemap module.
	 *
	 * @var SitemapModule
	 */
	private $module;

	/**
	 * robots.txt module.
	 *
	 * @var RobotsTxt
	 */
	private $robots;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param SitemapModule $module   Sitemap module.
	 * @param RobotsTxt     $robots   robots.txt module.
	 * @param Settings      $settings Settings.
	 */
	public function __construct( SitemapModule $module, RobotsTxt $robots, Settings $settings ) {
		$this->module   = $module;
		$this->robots   = $robots;
		$this->settings = $settings;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/sitemaps', 'GET', array( $this, 'overview' ), 'view' );
		$this->route( '/sitemaps/regenerate', 'POST', array( $this, 'regenerate' ), 'manage' );
		$this->route( '/sitemaps/submit', 'POST', array( $this, 'submit' ), 'manage' );
		$this->route( '/sitemaps/health', 'POST', array( $this, 'health' ), 'manage' );
		$this->route(
			'/sitemaps/preview',
			'GET',
			array( $this, 'preview' ),
			'view',
			array(
				'type' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'page' => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route( '/robots', 'GET', array( $this, 'robots' ), 'view' );
		$this->route(
			'/robots',
			'POST',
			array( $this, 'save_robots' ),
			'manage',
			array(
				'custom_rules' => array( 'type' => 'string' ),
				'sitemap_line' => array( 'type' => 'boolean' ),
				'manage'       => array( 'type' => 'boolean' ),
			)
		);
		$this->route(
			'/robots/preview',
			'POST',
			array( $this, 'preview_robots' ),
			'manage',
			array(
				'custom_rules' => array( 'type' => 'string' ),
				'sitemap_line' => array( 'type' => 'boolean' ),
			)
		);
		$this->route(
			'/robots/test',
			'GET',
			array( $this, 'test_robots' ),
			'view',
			array(
				'url'   => array(
					'type'     => 'string',
					'required' => true,
				),
				'agent' => array(
					'type'    => 'string',
					'default' => 'Googlebot',
				),
			)
		);
	}

	/**
	 * GET /sitemaps.
	 *
	 * @return array
	 */
	public function overview() {
		global $wpdb;
		$builder   = $this->module->builder();
		$conflicts = $this->service( ConflictDetector::class );
		$health    = SitemapHealth::status();
		$owned     = 'searchpilot' === $health['owner'];
		$children  = array();
		if ( $owned ) {
			foreach ( $builder->index_entries() as $child ) {
				$children[] = array(
					'type'    => $child['type'],
					'kind'    => $child['kind'],
					'label'   => $child['label'],
					'page'    => (int) $child['page'],
					'url'     => $child['loc'],
					'urls'    => (int) $child['urls'],
					'lastmod' => '' !== $child['lastmod'] ? SitemapXml::w3c_date( $child['lastmod'] ) : null,
				);
			}
		}
		$types = array();
		foreach ( $builder->types() as $id => $def ) {
			$types[] = array(
				'id'    => $id,
				'kind'  => $def['kind'],
				'label' => $def['label'],
			);
		}
		$post_types = array();
		foreach ( $this->service( ContentTypes::class )->post_types() as $name => $conf ) {
			$post_types[] = array(
				'name'    => $name,
				'label'   => html_entity_decode( (string) $conf['label'], ENT_QUOTES, 'UTF-8' ),
				'sitemap' => (bool) $conf['sitemap'],
			);
		}
		$categories = array();
		foreach ( (array) get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 300,
				'orderby'    => 'name',
			)
		) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$categories[] = array(
					'id'   => (int) $term->term_id,
					'name' => trim( wp_strip_all_tags( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ) ),
				);
			}
		}
		$env        = $this->service( Environment::class );
		$state      = $this->service( State::class );
		$backlog    = (array) $state->get( 'video_backlog', array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$with_video = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", VideoDetector::META ) );
		$urls_total = 0;
		foreach ( $children as $child ) {
			$urls_total += $child['urls'];
		}
		return array(
			'owner'      => $health['owner'],
			'owner_name' => $health['owner_name'],
			'owned'      => $owned,
			'companion'  => ! in_array( $health['owner'], array( 'searchpilot', 'core', 'off' ), true ),
			'index_url'  => SitemapModule::index_url(),
			'active_url' => SitemapHealth::active_sitemap_url(),
			'stylesheet' => SitemapRouter::url( 'xsl' ),
			'sitemaps'   => $children,
			'types'      => $types,
			'totals'     => array(
				'sitemaps' => count( $children ),
				'urls'     => $urls_total,
			),
			'settings'   => array(
				'images'                => (bool) $this->settings->get( 'sitemap.images' ),
				'video'                 => (bool) $this->settings->get( 'sitemap.video' ),
				'news'                  => (bool) $this->settings->get( 'sitemap.news' ),
				'max_urls'              => (int) $this->settings->get( 'sitemap.max_urls' ),
				'news_name'             => (string) $this->settings->get( 'sitemap.news_name' ),
				'news_language'         => (string) $this->settings->get( 'sitemap.news_language' ),
				'news_post_types'       => array_values( (array) $this->settings->get( 'sitemap.news_post_types' ) ),
				'news_categories'       => array_values( array_map( 'intval', (array) $this->settings->get( 'sitemap.news_categories' ) ) ),
				'stylesheet'            => (bool) $this->settings->get( 'sitemap.stylesheet' ),
				'submit_on_change'      => (bool) $this->settings->get( 'sitemap.submit_on_change' ),
				'submit_throttle_hours' => (int) $this->settings->get( 'sitemap.submit_throttle_hours' ),
			),
			'news'       => array(
				'enabled'  => (bool) $this->settings->get( 'sitemap.news' ),
				'owned'    => $conflicts->owns( 'news_sitemap' ),
				'owner'    => $conflicts->owner( 'news_sitemap' ),
				'active'   => $owned && $builder->news_enabled(),
				'url'      => SitemapRouter::url( 'news' ),
				'language' => $builder->news_language(),
				'name'     => $builder->news_name(),
			),
			'video'      => array(
				'posts'        => $with_video,
				'backlog_done' => ! empty( $backlog['done'] ),
			),
			'health'     => $health,
			'post_types' => $post_types,
			'categories' => $categories,
			'submission' => array(
				'available' => class_exists( '\SearchPilot\Queue\SubmissionQueue' ),
				'engines'   => SitemapModule::engines(),
				'allowed'   => $env->submissions_allowed(),
				'message'   => method_exists( $env, 'submission_block_message' ) && ! $env->submissions_allowed() ? (string) $env->submission_block_message() : '',
			),
			'permalinks' => '' !== (string) get_option( 'permalink_structure', '' ),
		);
	}

	/**
	 * POST /sitemaps/regenerate.
	 *
	 * @return array
	 */
	public function regenerate() {
		$result            = $this->module->regenerate();
		$payload           = $this->overview();
		$payload['result'] = $result;
		return $payload;
	}

	/**
	 * POST /sitemaps/submit (user-initiated).
	 *
	 * @return array|\WP_Error
	 */
	public function submit() {
		return $this->module->submit_now();
	}

	/**
	 * POST /sitemaps/health (run now).
	 *
	 * @return array
	 */
	public function health() {
		return $this->module->health()->run( 20 );
	}

	/**
	 * GET /sitemaps/preview?type=&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$builder = $this->module->builder();
		$type    = (string) $request->get_param( 'type' );
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		if ( 'index' === $type ) {
			$items = array();
			foreach ( $builder->index_entries() as $child ) {
				$items[] = array(
					'loc'     => $child['loc'],
					'lastmod' => '' !== $child['lastmod'] ? SitemapXml::w3c_date( $child['lastmod'] ) : null,
					'urls'    => (int) $child['urls'],
				);
			}
			return array(
				'type'  => 'index',
				'page'  => 1,
				'url'   => SitemapModule::index_url(),
				'total' => count( $items ),
				'items' => $items,
			);
		}
		if ( ! $builder->is_type( $type ) ) {
			return $this->error( 'searchpilot_not_found', __( 'This sitemap does not exist or is switched off.', 'searchpilot-pro' ), 404 );
		}
		$entries = $builder->entries( $type, $page );
		if ( null === $entries ) {
			return $this->error( 'searchpilot_not_found', __( 'This sitemap page does not exist.', 'searchpilot-pro' ), 404 );
		}
		$items = array();
		foreach ( array_slice( $entries, 0, 50 ) as $entry ) {
			$videos  = (array) ( $entry['videos'] ?? array() );
			$items[] = array(
				'loc'         => (string) $entry['loc'],
				'lastmod'     => SitemapXml::w3c_date( $entry['lastmod'] ?? '' ) ? SitemapXml::w3c_date( $entry['lastmod'] ?? '' ) : null,
				'images'      => count( (array) ( $entry['images'] ?? array() ) ),
				'videos'      => count( $videos ),
				'video_title' => $videos ? (string) $videos[0]['title'] : '',
				'news_title'  => (string) ( $entry['news']['title'] ?? '' ),
			);
		}
		return array(
			'type'  => $type,
			'page'  => $page,
			'url'   => SitemapRouter::url( $type, $page ),
			'total' => count( $entries ),
			'items' => $items,
		);
	}

	/**
	 * GET /robots.
	 *
	 * @return array
	 */
	public function robots() {
		return $this->robots->report( true );
	}

	/**
	 * POST /robots {custom_rules, sitemap_line, manage}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function save_robots( \WP_REST_Request $request ) {
		$changes = array();
		if ( null !== $request->get_param( 'custom_rules' ) ) {
			$rules = (string) $request->get_param( 'custom_rules' );
			if ( strlen( $rules ) > 20000 ) {
				return $this->error( 'searchpilot_too_long', __( 'Custom rules are limited to 20,000 characters.', 'searchpilot-pro' ) );
			}
			$changes['custom_rules'] = $rules;
		}
		if ( null !== $request->get_param( 'sitemap_line' ) ) {
			$changes['sitemap_line'] = rest_sanitize_boolean( $request->get_param( 'sitemap_line' ) );
		}
		if ( null !== $request->get_param( 'manage' ) ) {
			$changes['manage'] = rest_sanitize_boolean( $request->get_param( 'manage' ) );
		}
		$result           = $this->robots->save( $changes );
		$report           = $this->robots->report( true );
		$report['saved']  = $result['saved'];
		$report['errors'] = $result['errors'];
		return $report;
	}

	/**
	 * POST /robots/preview {custom_rules, sitemap_line}: live validation without saving.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function preview_robots( \WP_REST_Request $request ) {
		$rules = mb_substr( (string) $request->get_param( 'custom_rules' ), 0, 20000 );
		$line  = null === $request->get_param( 'sitemap_line' ) ? (bool) $this->settings->get( 'robots.sitemap_line', true ) : rest_sanitize_boolean( $request->get_param( 'sitemap_line' ) );
		return $this->robots->preview( $rules, $line );
	}

	/**
	 * GET /robots/test?url=&agent=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function test_robots( \WP_REST_Request $request ) {
		$agent = sanitize_text_field( (string) $request->get_param( 'agent' ) );
		$url   = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/', '', wp_strip_all_tags( (string) $request->get_param( 'url' ) ) ) );
		return $this->robots->test( $url, $agent );
	}
}
