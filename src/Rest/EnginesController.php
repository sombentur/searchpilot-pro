<?php
/**
 * Search engine connection endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Api\Bing\BingWebmaster;
use SearchPilot\Api\Connections;
use SearchPilot\Api\Google\GoogleAuth;
use SearchPilot\Api\Google\IndexingApi;
use SearchPilot\Api\Google\OAuthClient;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\Google\ServiceAccount;
use SearchPilot\Api\IndexNow\IndexNow;
use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Credentials;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Queue\SubmissionQueue;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * /engines… — Google (OAuth / service account / property / Indexing API),
 * Bing Webmaster, IndexNow and manual URL submission. Credentials are
 * write-only: responses carry set/masked/constant flags, never secrets.
 */
class EnginesController extends Controller {

	const MAX_MANUAL_URLS = 100;

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/engines', 'GET', array( $this, 'index' ), 'manage' );

		$this->route( '/engines/google/credentials', 'POST', array( $this, 'google_credentials' ), 'manage' );
		$this->route( '/engines/google/connect', 'POST', array( $this, 'google_connect' ), 'manage' );
		$this->route( '/engines/google/service-account', 'POST', array( $this, 'google_service_account' ), 'manage' );
		$this->route( '/engines/google/properties', 'GET', array( $this, 'google_properties' ), 'manage' );
		$this->route( '/engines/google/property', 'POST', array( $this, 'google_property' ), 'manage' );
		$this->route( '/engines/google/test', 'POST', array( $this, 'google_test' ), 'manage' );
		$this->route( '/engines/google/disconnect', 'POST', array( $this, 'google_disconnect' ), 'manage' );
		$this->route( '/engines/google/indexing-api', 'POST', array( $this, 'google_indexing_api' ), 'manage' );

		$this->route( '/engines/bing', 'POST', array( $this, 'bing_connect' ), 'manage' );
		$this->route( '/engines/bing/test', 'POST', array( $this, 'bing_test' ), 'manage' );
		$this->route( '/engines/bing/disconnect', 'POST', array( $this, 'bing_disconnect' ), 'manage' );

		$this->route( '/engines/indexnow', 'GET', array( $this, 'indexnow' ), 'manage' );
		$this->route( '/engines/indexnow', 'POST', array( $this, 'indexnow_update' ), 'manage' );
		$this->route( '/engines/indexnow/regenerate', 'POST', array( $this, 'indexnow_regenerate' ), 'manage' );
		$this->route( '/engines/indexnow/verify', 'POST', array( $this, 'indexnow_verify' ), 'manage' );

		$this->route( '/engines/submit', 'POST', array( $this, 'submit' ), 'edit' );
	}

	/* ---------------------------------------------------------------- *
	 * Overview
	 * ---------------------------------------------------------------- */

	/**
	 * GET /engines.
	 *
	 * @return array
	 */
	public function index() {
		/** Credentials. @var Credentials $credentials */
		$credentials = $this->service( Credentials::class );
		/** Google auth. @var GoogleAuth $google */
		$google = $this->service( GoogleAuth::class );
		/** OAuth. @var OAuthClient $oauth */
		$oauth = $this->service( OAuthClient::class );
		/** Service account. @var ServiceAccount $sa */
		$sa = $this->service( ServiceAccount::class );
		/** Indexing API. @var IndexingApi $indexing */
		$indexing = $this->service( IndexingApi::class );
		/** Bing. @var BingWebmaster $bing */
		$bing = $this->service( BingWebmaster::class );
		/** IndexNow. @var IndexNow $indexnow */
		$indexnow = $this->service( IndexNow::class );
		/** Settings. @var Settings $settings */
		$settings = $this->service( Settings::class );

		$status  = $this->service( Connections::class )->status();
		$health  = $google->connection();
		$bing_hc = (array) $this->service( \SearchPilot\Core\State::class )->get( BingWebmaster::STATE_KEY, array() );

		$post_types = array();
		foreach ( $this->service( ContentTypes::class )->post_types() as $name => $type ) {
			$post_types[] = array(
				'value' => $name,
				'label' => (string) $type['label'],
			);
		}

		return array_merge(
			$status,
			array(
				'redirect_uri'   => $oauth->redirect_uri(),
				'credentials'    => array(
					'google'    => array_merge(
						$credentials->masked( 'google', array( 'client_id', 'client_secret' ) ),
						array(
							'authorized' => array(
								'set'      => $credentials->has( 'google', 'refresh_token' ),
								'masked'   => '',
								'constant' => false,
							),
						)
					),
					'google_sa' => array(
						'json' => array(
							'set'      => $sa->is_configured(),
							'masked'   => $sa->is_configured() ? $sa->client_email() : '',
							'constant' => $sa->is_constant(),
						),
					),
					'bing'      => $credentials->masked( 'bing', array( 'api_key' ) ),
				),
				'quotas'         => self::quota_rows( $this->service( RateLimiter::class ) ),
				'google_setup'   => array(
					'method'              => $google->method(),
					'has_client'          => $oauth->has_client(),
					'service_account'     => $sa->is_configured() ? $sa->client_email() : '',
					'scopes_granted'      => 'oauth' === $google->method() ? $google->granted_scopes() : array(),
					'indexing_setting'    => $indexing->setting_on(),
					'indexing_scope'      => $indexing->scope_granted(),
					'indexing_post_types' => $indexing->post_types(),
					'indexing_types'      => array_map(
						static function ( $name, $label ) {
							return array(
								'value' => $name,
								'label' => $label,
							);
						},
						array_keys( $indexing->allowed_post_types() ),
						array_values( $indexing->allowed_post_types() )
					),
					'needs_reconnect'     => $indexing->setting_on() && 'oauth' === $google->method() && $google->is_configured() && ! $indexing->scope_granted(),
					'connected_at'        => (string) $credentials->get( 'google', 'connected_at', '' ),
					'error_code'          => $health['error_code'],
					'status'              => $health['status'],
				),
				'bing_setup'     => array(
					'url_submission'   => (bool) $settings->get( 'indexing.bing_url_submission', false ),
					'checked_at'       => $bing->connection()['checked_at'],
					'quota_checked_at' => ! empty( $bing_hc['quota_checked_at'] ) ? (string) $bing_hc['quota_checked_at'] : null,
					'default_site'     => home_url( '/' ),
				),
				'bing_crawl'     => $bing->has_key() ? $bing->crawl_summary() : null,
				'bing_feeds'     => $bing->has_key() ? $bing->feeds() : null,
				'indexnow_setup' => $this->indexnow_details(),
				'post_types'     => $post_types,
				'submissions'    => $this->submissions(),
			)
		);
	}

	/**
	 * Environment / guard summary.
	 *
	 * @return array{allowed:bool,reason:string,message:string}
	 */
	private function submissions() {
		/** Queue. @var SubmissionQueue $queue */
		$queue  = $this->service( SubmissionQueue::class );
		$reason = $queue->block_reason();
		return array(
			'allowed' => '' === $reason,
			'reason'  => $reason,
			'message' => '' !== $reason ? $queue->hold_message( $reason ) : '',
		);
	}

	/**
	 * Rate-limit snapshot with labels.
	 *
	 * @param RateLimiter $limiter Limiter.
	 * @return array[]
	 */
	public static function quota_rows( RateLimiter $limiter ) {
		$labels = array(
			'google_inspection' => __( 'Google URL Inspection', 'searchpilot-pro' ),
			'google_indexing'   => __( 'Google Indexing API', 'searchpilot-pro' ),
			'google_analytics'  => __( 'Search Console Search Analytics', 'searchpilot-pro' ),
			'google_sitemaps'   => __( 'Search Console Sitemaps', 'searchpilot-pro' ),
			'google_oauth'      => __( 'Google sign-in / tokens', 'searchpilot-pro' ),
			'bing_api'          => __( 'Bing Webmaster API', 'searchpilot-pro' ),
			'bing_url'          => __( 'Bing URL Submission', 'searchpilot-pro' ),
			'indexnow'          => __( 'IndexNow requests', 'searchpilot-pro' ),
			'self_audit'        => __( 'Self-audit page fetches', 'searchpilot-pro' ),
		);
		$rows = array();
		foreach ( $limiter->snapshot() as $key => $data ) {
			$limit  = (int) $data['limit_day'];
			$rows[] = array(
				'key'           => (string) $key,
				'label'         => $labels[ $key ] ?? (string) $key,
				'used_today'    => (int) $data['used_today'],
				'limit_day'     => $limit,
				'remaining'     => $limit > 0 ? max( 0, $limit - (int) $data['used_today'] ) : null,
				'blocked_until' => $data['blocked_until'],
			);
		}
		return $rows;
	}

	/**
	 * Updates settings and records the change in the activity log (undoable).
	 *
	 * @param array  $changes     Dot key => value.
	 * @param string $description Activity description.
	 * @return array Settings::update() result.
	 */
	private function audited_update( array $changes, $description ) {
		/** Settings. @var Settings $settings */
		$settings = $this->service( Settings::class );
		$before   = array();
		foreach ( array_keys( $changes ) as $key ) {
			$before[ $key ] = $settings->get( $key );
		}
		$result = $settings->update( $changes );
		$this->record_settings( $before, $description );
		return $result;
	}

	/**
	 * Records changed settings (compared with $before) in the activity log.
	 *
	 * @param array  $before      Dot key => previous value.
	 * @param string $description Description.
	 */
	private function record_settings( array $before, $description ) {
		/** Settings. @var Settings $settings */
		$settings = $this->service( Settings::class );
		$old      = array();
		$new      = array();
		foreach ( $before as $key => $value ) {
			$now = $settings->get( $key );
			if ( wp_json_encode( $now ) !== wp_json_encode( $value ) ) {
				$old[ $key ] = $value;
				$new[ $key ] = $now;
			}
		}
		if ( empty( $new ) ) {
			return;
		}
		$this->service( ActivityLog::class )->record(
			array(
				'action'      => 'settings_updated',
				'description' => $description,
				'object_type' => 'settings',
				'handler'     => 'settings',
				'before'      => $old,
				'after'       => $new,
				'source'      => 'user',
			)
		);
	}

	/* ---------------------------------------------------------------- *
	 * Google
	 * ---------------------------------------------------------------- */

	/**
	 * POST /engines/google/credentials {client_id, client_secret}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function google_credentials( \WP_REST_Request $request ) {
		/** OAuth. @var OAuthClient $oauth */
		$oauth  = $this->service( OAuthClient::class );
		$result = $oauth->save_client( sanitize_text_field( (string) $request->get_param( 'client_id' ) ), sanitize_text_field( (string) $request->get_param( 'client_secret' ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->service( Settings::class )->update( array( 'engines.google_auth' => 'oauth' ) );
		return array(
			'saved'       => true,
			'google'      => $this->service( Connections::class )->google_status(),
			'credentials' => $this->service( Credentials::class )->masked( 'google', array( 'client_id', 'client_secret' ) ),
		);
	}

	/**
	 * POST /engines/google/connect {return: engines|setup} → { auth_url }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function google_connect( \WP_REST_Request $request ) {
		$return = 'setup' === sanitize_key( (string) $request->get_param( 'return' ) ) ? 'setup' : 'engines';
		$url    = $this->service( OAuthClient::class )->create_authorization( $return, get_current_user_id() );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return array( 'auth_url' => $url );
	}

	/**
	 * POST /engines/google/service-account {json}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function google_service_account( \WP_REST_Request $request ) {
		/** Service account. @var ServiceAccount $sa */
		$sa   = $this->service( ServiceAccount::class );
		$json = (string) $request->get_param( 'json' );
		if ( $sa->is_constant() ) {
			return $this->error( 'searchpilot_sa_constant', __( 'The service-account key is defined in wp-config.php (SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT). Change it there.', 'searchpilot-pro' ) );
		}
		if ( strlen( $json ) > 20000 ) {
			return $this->error( 'searchpilot_sa_json', __( 'That file is too large to be a service-account key.', 'searchpilot-pro' ) );
		}
		$key = ServiceAccount::parse( $json );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$warning = '';
		$token   = $sa->fetch_token( array( OAuthClient::SCOPE_WEBMASTERS ), $key, false );
		if ( is_wp_error( $token ) ) {
			$data = (array) $token->get_error_data();
			if ( empty( $data['retryable'] ) ) {
				return $token;
			}
			/* translators: %s: error message. */
			$warning = sprintf( __( 'The key was saved, but Google could not be reached to test it: %s', 'searchpilot-pro' ), $token->get_error_message() );
		}
		$saved = $sa->save( $json );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->service( Settings::class )->update( array( 'engines.google_auth' => 'service_account' ) );

		$properties = array();
		if ( '' === $warning ) {
			/** Search Console. @var SearchConsole $gsc */
			$gsc   = $this->service( SearchConsole::class );
			$sites = $gsc->list_sites();
			if ( is_wp_error( $sites ) ) {
				/* translators: %s: error message. */
				$warning = sprintf( __( 'The key works, but the properties could not be listed: %s', 'searchpilot-pro' ), $sites->get_error_message() );
			} else {
				$properties = $this->property_rows( $sites );
				$current    = $gsc->property();
				if ( '' === $current || ! in_array( $current, wp_list_pluck( $sites, 'site_url' ), true ) ) {
					$best = SearchConsole::best_property( $sites, home_url( '/' ) );
					if ( '' !== $best ) {
						$gsc->set_property( $best );
					} elseif ( empty( $sites ) ) {
						/* translators: %s: service account e-mail. */
						$warning = sprintf( __( 'The key works, but %s has no access to any Search Console property yet. In Search Console → Settings → Users and permissions, add it as an Owner (or Full user) of this site’s property.', 'searchpilot-pro' ), $key['client_email'] );
					}
				}
				$this->service( GoogleAuth::class )->record_success( true );
			}
		}
		return array(
			'saved'      => true,
			'warning'    => $warning,
			'google'     => $this->service( Connections::class )->google_status(),
			'properties' => $properties,
		);
	}

	/**
	 * Property rows for the picker.
	 *
	 * @param array $sites list_sites() rows.
	 * @return array[]
	 */
	private function property_rows( array $sites ) {
		$best = SearchConsole::best_property( $sites, home_url( '/' ) );
		$rows = array();
		foreach ( $sites as $site ) {
			$url    = (string) $site['site_url'];
			$level  = (string) $site['permission_level'];
			$rows[] = array(
				'site_url'         => $url,
				'permission_level' => $level,
				'type'             => 0 === strpos( $url, 'sc-domain:' ) ? 'domain' : 'url_prefix',
				'usable'           => 'siteUnverifiedUser' !== $level,
				'recommended'      => $url === $best,
				'matches_site'     => '' !== SearchConsole::best_property( array( $site ), home_url( '/' ) ),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( (int) $b['recommended'], (int) $b['matches_site'], (int) $b['usable'], $a['site_url'] ) <=> array( (int) $a['recommended'], (int) $a['matches_site'], (int) $a['usable'], $b['site_url'] );
			}
		);
		return $rows;
	}

	/**
	 * GET /engines/google/properties.
	 *
	 * @return array|\WP_Error
	 */
	public function google_properties() {
		/** Search Console. @var SearchConsole $gsc */
		$gsc   = $this->service( SearchConsole::class );
		$sites = $gsc->list_sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		return array(
			'items'       => $this->property_rows( $sites ),
			'selected'    => $gsc->property(),
			'recommended' => SearchConsole::best_property( $sites, home_url( '/' ) ),
		);
	}

	/**
	 * POST /engines/google/property {property}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function google_property( \WP_REST_Request $request ) {
		/** Search Console. @var SearchConsole $gsc */
		$gsc      = $this->service( SearchConsole::class );
		$property = trim( sanitize_text_field( (string) $request->get_param( 'property' ) ) );
		if ( ! SearchConsole::valid_property( $property ) ) {
			return $this->error( 'searchpilot_invalid_property', __( 'Choose a Search Console property from the list.', 'searchpilot-pro' ) );
		}
		$sites = $gsc->list_sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$match = null;
		foreach ( $sites as $site ) {
			if ( $site['site_url'] === $property ) {
				$match = $site;
				break;
			}
		}
		if ( null === $match ) {
			return $this->error( 'searchpilot_invalid_property', __( 'The connected Google account cannot access that property.', 'searchpilot-pro' ) );
		}
		if ( 'siteUnverifiedUser' === $match['permission_level'] ) {
			return $this->error( 'searchpilot_unverified_property', __( 'That property is not verified for the connected account. Verify it in Search Console first.', 'searchpilot-pro' ) );
		}
		$before = array( 'engines.google_property' => $gsc->property() );
		$result = $gsc->set_property( $property );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/* translators: %s: Search Console property. */
		$this->record_settings( $before, sprintf( __( 'Selected the Search Console property %s', 'searchpilot-pro' ), $property ) );
		return array( 'google' => $this->service( Connections::class )->google_status() );
	}

	/**
	 * POST /engines/google/test.
	 *
	 * @return array|\WP_Error
	 */
	public function google_test() {
		/** Google auth. @var GoogleAuth $google */
		$google = $this->service( GoogleAuth::class );
		/** Search Console. @var SearchConsole $gsc */
		$gsc = $this->service( SearchConsole::class );
		if ( ! $google->is_configured() ) {
			return $this->error( 'searchpilot_google_not_connected', __( 'Google is not connected yet.', 'searchpilot-pro' ) );
		}
		$token = $google->access_token( array( OAuthClient::SCOPE_WEBMASTERS ), true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$sites = $gsc->list_sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$property   = $gsc->property();
		$permission = '';
		foreach ( $sites as $site ) {
			if ( $site['site_url'] === $property ) {
				$permission = $site['permission_level'];
			}
		}
		if ( '' === $property ) {
			$message = __( 'Google answered, but no Search Console property is selected yet. Choose one below.', 'searchpilot-pro' );
			$ok      = false;
		} elseif ( '' === $permission || 'siteUnverifiedUser' === $permission ) {
			/* translators: %s: property. */
			$message = sprintf( __( 'Google answered, but the account cannot access %s anymore. Choose another property or restore the account’s permission in Search Console.', 'searchpilot-pro' ), $property );
			$ok      = false;
		} else {
			/* translators: 1: property, 2: permission level. */
			$message = sprintf( __( 'Connection works. Property %1$s (%2$s).', 'searchpilot-pro' ), $property, $permission );
			$ok      = true;
			$google->record_success( true );
		}
		return array(
			'ok'         => $ok,
			'message'    => $message,
			'permission' => $permission,
			'google'     => $this->service( Connections::class )->google_status(),
		);
	}

	/**
	 * POST /engines/google/disconnect {forget_client?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function google_disconnect( \WP_REST_Request $request ) {
		$this->service( GoogleAuth::class )->disconnect( rest_sanitize_boolean( $request->get_param( 'forget_client' ) ) );
		return array( 'google' => $this->service( Connections::class )->google_status() );
	}

	/**
	 * POST /engines/google/indexing-api {enabled, post_types}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function google_indexing_api( \WP_REST_Request $request ) {
		$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		$types   = $request->get_param( 'post_types' );
		$changes = array( 'indexing.google_indexing_api' => $enabled );
		if ( null !== $types ) {
			// Posts, pages and article-type content can never be declared job/livestream types.
			$allowed                                     = array_keys( $this->service( IndexingApi::class )->allowed_post_types() );
			$types                                       = array_values( array_intersect( array_map( 'sanitize_key', (array) $types ), $allowed ) );
			$changes['indexing.indexing_api_post_types'] = $types;
		}
		$result = $this->audited_update( $changes, $enabled ? __( 'Switched the Google Indexing API on (job postings and livestreams only)', 'searchpilot-pro' ) : __( 'Changed the Google Indexing API settings', 'searchpilot-pro' ) );
		if ( ! empty( $result['errors'] ) ) {
			return $this->error( 'searchpilot_invalid', implode( ' ', $result['errors'] ) );
		}
		/** Google auth. @var GoogleAuth $google */
		$google = $this->service( GoogleAuth::class );
		/** Indexing API. @var IndexingApi $indexing */
		$indexing = $this->service( IndexingApi::class );
		return array(
			'enabled'         => $indexing->setting_on(),
			'active'          => $indexing->is_enabled(),
			'post_types'      => $indexing->post_types(),
			'needs_reconnect' => $enabled && 'oauth' === $google->method() && $google->is_configured() && ! $indexing->scope_granted(),
			'google'          => $this->service( Connections::class )->google_status(),
		);
	}

	/* ---------------------------------------------------------------- *
	 * Bing
	 * ---------------------------------------------------------------- */

	/**
	 * POST /engines/bing {api_key, site}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function bing_connect( \WP_REST_Request $request ) {
		$result = $this->service( BingWebmaster::class )->connect(
			sanitize_text_field( (string) $request->get_param( 'api_key' ) ),
			esc_url_raw( (string) $request->get_param( 'site' ) )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'bing'        => $this->service( Connections::class )->bing_status(),
			'credentials' => $this->service( Credentials::class )->masked( 'bing', array( 'api_key' ) ),
		);
	}

	/**
	 * POST /engines/bing/test.
	 *
	 * @return array|\WP_Error
	 */
	public function bing_test() {
		$result = $this->service( BingWebmaster::class )->test();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'      => true,
			'message' => __( 'Connection works.', 'searchpilot-pro' ),
			'bing'    => $this->service( Connections::class )->bing_status(),
		);
	}

	/**
	 * POST /engines/bing/disconnect.
	 *
	 * @return array|\WP_Error
	 */
	public function bing_disconnect() {
		$result = $this->service( BingWebmaster::class )->disconnect();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'bing' => $this->service( Connections::class )->bing_status() );
	}

	/* ---------------------------------------------------------------- *
	 * IndexNow
	 * ---------------------------------------------------------------- */

	/**
	 * IndexNow details for the UI.
	 *
	 * @return array
	 */
	private function indexnow_details() {
		/** IndexNow. @var IndexNow $indexnow */
		$indexnow = $this->service( IndexNow::class );
		$schema   = Settings::schema();
		return array(
			'enabled'    => $indexnow->is_enabled(),
			'active'     => $indexnow->is_active(),
			'key'        => $indexnow->key(),
			'key_url'    => $indexnow->key_url(),
			'endpoint'   => $indexnow->endpoint(),
			'endpoints'  => (array) ( $schema['indexing.indexnow_endpoint']['values'] ?? array( 'api.indexnow.org' ) ),
			'owner'      => $indexnow->owner(),
			'owner_name' => $indexnow->owner_name(),
			'last'       => $indexnow->last_verification(),
		);
	}

	/**
	 * GET /engines/indexnow.
	 *
	 * @return array
	 */
	public function indexnow() {
		return array_merge(
			$this->service( Connections::class )->indexnow_status(),
			array( 'details' => $this->indexnow_details() )
		);
	}

	/**
	 * POST /engines/indexnow {enabled, endpoint}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function indexnow_update( \WP_REST_Request $request ) {
		$changes = array();
		if ( null !== $request->get_param( 'enabled' ) ) {
			$changes['indexing.indexnow'] = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		}
		if ( null !== $request->get_param( 'endpoint' ) ) {
			$changes['indexing.indexnow_endpoint'] = sanitize_text_field( (string) $request->get_param( 'endpoint' ) );
		}
		if ( $changes ) {
			$result = $this->audited_update( $changes, __( 'Changed the IndexNow settings', 'searchpilot-pro' ) );
			if ( ! empty( $result['errors'] ) ) {
				return $this->error( 'searchpilot_invalid', implode( ' ', $result['errors'] ) );
			}
		}
		return $this->indexnow();
	}

	/**
	 * POST /engines/indexnow/regenerate.
	 *
	 * @return array
	 */
	public function indexnow_regenerate() {
		$before = array( 'indexing.indexnow_key' => $this->service( IndexNow::class )->key() );
		$this->service( IndexNow::class )->regenerate();
		$this->record_settings( $before, __( 'Generated a new IndexNow key', 'searchpilot-pro' ) );
		return $this->indexnow();
	}

	/**
	 * POST /engines/indexnow/verify.
	 *
	 * @return array
	 */
	public function indexnow_verify() {
		/** IndexNow. @var IndexNow $indexnow */
		$indexnow = $this->service( IndexNow::class );
		$result   = $indexnow->verify_key_details();
		if ( $result['verified'] ) {
			$this->service( IssueRepository::class )->resolve( 'indexnow_key_unreachable', 'site', 0 );
		}
		return array_merge( $result, array( 'indexnow' => $this->indexnow() ) );
	}

	/* ---------------------------------------------------------------- *
	 * Manual submission
	 * ---------------------------------------------------------------- */

	/**
	 * POST /engines/submit {urls: string[]} → { queued: {service: n}, held, message }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$raw = $request->get_param( 'urls' );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\r\n,]+/', $raw );
		}
		$raw = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $raw ) ) ) );
		if ( empty( $raw ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Enter at least one URL of this site.', 'searchpilot-pro' ) );
		}
		if ( count( $raw ) > self::MAX_MANUAL_URLS ) {
			/* translators: %d: maximum number of URLs. */
			return $this->error( 'searchpilot_too_many', sprintf( __( 'Submit at most %d URLs at a time.', 'searchpilot-pro' ), self::MAX_MANUAL_URLS ) );
		}
		/** Queue. @var SubmissionQueue $queue */
		$queue    = $this->service( SubmissionQueue::class );
		$queued   = array();
		$rejected = array();
		$results  = array();
		foreach ( $raw as $input ) {
			$url = esc_url_raw( Url::absolute( $input ) );
			if ( '' === $url || ! Url::is_internal( $url ) || ! preg_match( '#^https?://#i', $url ) ) {
				$rejected[] = array(
					'url'    => $input,
					'reason' => __( 'Not a URL of this site.', 'searchpilot-pro' ),
				);
				continue;
			}
			$post_id = (int) url_to_postid( $url );
			if ( $post_id && ! Capabilities::can_edit( $post_id ) ) {
				$rejected[] = array(
					'url'    => $url,
					'reason' => __( 'You cannot edit this content.', 'searchpilot-pro' ),
				);
				continue;
			}
			if ( $post_id && 'publish' !== get_post_status( $post_id ) ) {
				$rejected[] = array(
					'url'    => $url,
					'reason' => __( 'This content is not published.', 'searchpilot-pro' ),
				);
				continue;
			}
			$ids = $queue->enqueue_url(
				$url,
				'update',
				array(
					'object_type'    => $post_id ? 'post' : '',
					'object_id'      => $post_id,
					'reason'         => 'manual',
					'user_initiated' => true,
					'force'          => true,
				)
			);
			foreach ( $ids as $service => $id ) {
				if ( $id ) {
					$queued[ $service ] = ( $queued[ $service ] ?? 0 ) + 1;
				}
			}
			$results[] = array(
				'url'      => $url,
				'services' => $ids,
			);
		}

		$block  = $queue->block_reason();
		$held   = '' !== $block && ! empty( $queued );
		$status = empty( $queued ) ? 'none' : ( $held ? 'held' : 'queued' );
		$reason = '';
		if ( empty( $queued ) ) {
			list( $reason, $message ) = $this->nothing_queued_reason( $results, $rejected );
		} elseif ( $held ) {
			$reason = $block;
			/* translators: %s: explanation. */
			$message = sprintf( __( 'Queued, but held: %s', 'searchpilot-pro' ), $queue->hold_message( $block ) );
		} else {
			/* translators: %d: number of URLs. */
			$message = sprintf( _n( '%d URL queued. It is sent within a few minutes.', '%d URLs queued. They are sent within a few minutes.', count( $results ), 'searchpilot-pro' ), count( $results ) );
		}
		return array(
			// {} when nothing was queued; then `status` is "none" and `reason` says why.
			'queued'   => (object) $queued,
			'status'   => $status,
			'held'     => $held,
			'reason'   => $reason,
			'message'  => $message,
			'rejected' => $rejected,
			'results'  => $results,
		);
	}

	/**
	 * Why a manual submission queued nothing.
	 *
	 * @param array $results  Accepted URLs.
	 * @param array $rejected Rejected URLs.
	 * @return array{0:string,1:string} reason code (rejected|handled_elsewhere|no_services|not_queued), message.
	 */
	private function nothing_queued_reason( array $results, array $rejected ) {
		if ( empty( $results ) ) {
			$first = $rejected ? (string) $rejected[0]['reason'] : '';
			return array(
				'rejected',
				/* translators: %s: reason of the first rejected URL. */
				'' !== $first ? sprintf( __( 'Nothing was queued: %s', 'searchpilot-pro' ), $first ) : __( 'Nothing was queued: none of the URLs belongs to this site.', 'searchpilot-pro' ),
			);
		}
		/** IndexNow. @var IndexNow $indexnow */
		$indexnow = $this->service( IndexNow::class );
		$bing_on  = (bool) $this->service( Settings::class )->get( 'indexing.bing_url_submission', false ) && $this->service( BingWebmaster::class )->is_connected();
		if ( $indexnow->is_enabled() && ! $indexnow->is_active() && ! $bing_on ) {
			return array(
				'handled_elsewhere',
				/* translators: %s: plugin name. */
				sprintf( __( 'Nothing was queued: %s already sends IndexNow notifications for this site, so SearchPilot does not send them twice.', 'searchpilot-pro' ), $indexnow->owner_name() ),
			);
		}
		if ( ! $indexnow->is_active() && ! $bing_on ) {
			return array(
				'no_services',
				__( 'Nothing was queued: no search engine is set up to receive URLs. Switch on IndexNow (Search Engines → IndexNow) or Bing URL submission. Google learns about pages from your sitemap.', 'searchpilot-pro' ),
			);
		}
		return array(
			'not_queued',
			__( 'Nothing was queued for these URLs.', 'searchpilot-pro' ),
		);
	}
}
