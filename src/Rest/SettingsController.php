<?php
/**
 * Settings endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /settings. Changes are recorded in the activity log and can be undone.
 */
class SettingsController extends Controller {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $activity;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param ActivityLog $activity Activity log.
	 */
	public function __construct( Settings $settings, ActivityLog $activity ) {
		$this->settings = $settings;
		$this->activity = $activity;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/settings', 'GET', array( $this, 'get_settings' ), 'manage' );
		$this->route(
			'/settings',
			'POST',
			array( $this, 'update_settings' ),
			'manage',
			array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->payload();
	}

	/**
	 * POST /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$changes = $request->get_param( 'settings' );
		if ( ! is_array( $changes ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Invalid settings payload.', 'searchpilot-pro' ) );
		}
		// Never accept settings that would carry secrets.
		unset( $changes['credentials'] );

		$before_all = $this->flatten( $this->settings->all() );
		$result     = $this->settings->update( $changes );
		$after_all  = $this->flatten( $result['settings'] );

		$before = array();
		$after  = array();
		foreach ( $after_all as $key => $value ) {
			$old = $before_all[ $key ] ?? null;
			if ( wp_json_encode( $old ) !== wp_json_encode( $value ) ) {
				$before[ $key ] = $old;
				$after[ $key ]  = $value;
			}
		}
		if ( $after ) {
			$this->activity->record(
				array(
					'action'      => 'settings_updated',
					/* translators: %s: comma separated setting names. */
					'description' => sprintf( __( 'Changed settings: %s', 'searchpilot-pro' ), implode( ', ', array_map( array( __CLASS__, 'setting_label' ), array_slice( array_keys( $after ), 0, 8 ) ) ) . ( count( $after ) > 8 ? '…' : '' ) ),
					'object_type' => 'settings',
					'handler'     => 'settings',
					'before'      => $before,
					'after'       => $after,
					'source'      => 'user',
				)
			);
		}
		$payload           = $this->payload();
		$payload['errors'] = $result['errors'];
		$payload['saved']  = count( $after );
		return $payload;
	}

	/**
	 * Human label for a setting key ("meta.title_post" → "Titles & meta: title post").
	 *
	 * @param string $key Dot key.
	 * @return string
	 */
	public static function setting_label( $key ) {
		$sections = array(
			'general'     => __( 'General', 'searchpilot-pro' ),
			'content'     => __( 'Content types', 'searchpilot-pro' ),
			'meta'        => __( 'Titles & meta', 'searchpilot-pro' ),
			'ownership'   => __( 'SEO plugin compatibility', 'searchpilot-pro' ),
			'sitemap'     => __( 'Sitemaps', 'searchpilot-pro' ),
			'indexing'    => __( 'Indexing', 'searchpilot-pro' ),
			'engines'     => __( 'Search engines', 'searchpilot-pro' ),
			'schema'      => __( 'Schema', 'searchpilot-pro' ),
			'breadcrumbs' => __( 'Breadcrumbs', 'searchpilot-pro' ),
			'links'       => __( 'Internal links', 'searchpilot-pro' ),
			'redirects'   => __( 'Redirects', 'searchpilot-pro' ),
			'robots'      => 'robots.txt',
			'analytics'   => __( 'Analytics', 'searchpilot-pro' ),
			'alerts'      => __( 'Alerts & reports', 'searchpilot-pro' ),
			'automation'  => __( 'Automation', 'searchpilot-pro' ),
		);
		$parts   = explode( '.', (string) $key, 2 );
		$section = $sections[ $parts[0] ] ?? $parts[0];
		if ( 'general.mode' === $key ) {
			return __( 'Automation mode', 'searchpilot-pro' );
		}
		return isset( $parts[1] ) ? $section . ': ' . str_replace( '_', ' ', $parts[1] ) : $section;
	}

	/**
	 * Full settings payload for the UI.
	 *
	 * @return array
	 */
	private function payload() {
		$plugin = \SearchPilot\Plugin::instance();
		$types  = $plugin->get( ContentTypes::class );
		$schema = array();
		foreach ( Settings::schema() as $key => $def ) {
			$schema[ $key ] = array_intersect_key( $def, array_flip( array( 'type', 'values', 'min', 'max', 'default' ) ) );
		}
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'fields'     => array( 'ID', 'display_name' ),
				'number'     => 200,
			)
		);
		return array(
			'settings'   => $this->settings->all(),
			'schema'     => $schema,
			'post_types' => $types->post_types(),
			'taxonomies' => $types->taxonomies(),
			'compat'     => $plugin->get( ConflictDetector::class )->report(),
			'tasks'      => AutomationPolicy::tasks(),
			'matrix'     => AutomationPolicy::matrix(),
			'policy'     => $this->policies(),
			'users'      => array_map(
				static function ( $u ) {
					return array(
						'id'   => (int) $u->ID,
						'name' => $u->display_name,
					);
				},
				$users
			),
			'categories' => array_map(
				static function ( $t ) {
					return array(
						'id'   => (int) $t->term_id,
						'name' => $t->name,
					);
				},
				(array) get_terms(
					array(
						'taxonomy'   => 'category',
						'hide_empty' => false,
						'number'     => 500,
					)
				)
			),
			'logo_url'   => $this->attachment_url( (int) $this->settings->get( 'schema.org_logo' ) ),
			'image_url'  => $this->attachment_url( (int) $this->settings->get( 'meta.default_image' ) ),
		);
	}

	/**
	 * Effective policy per task.
	 *
	 * @return array<string,string>
	 */
	private function policies() {
		$policy = \SearchPilot\Plugin::instance()->get( AutomationPolicy::class );
		$out    = array();
		foreach ( array_keys( AutomationPolicy::tasks() ) as $task ) {
			$out[ $task ] = $policy->policy( $task );
		}
		return $out;
	}

	/**
	 * Attachment URL or ''.
	 *
	 * @param int $id Attachment id.
	 * @return string
	 */
	private function attachment_url( $id ) {
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'medium' );
		return $url ? $url : '';
	}

	/**
	 * Flattens settings to schema dot keys.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private function flatten( array $settings ) {
		$out = array();
		foreach ( array_keys( Settings::schema() ) as $key ) {
			$value = $settings;
			foreach ( explode( '.', $key ) as $part ) {
				$value = is_array( $value ) && array_key_exists( $part, $value ) ? $value[ $part ] : null;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
