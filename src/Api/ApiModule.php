<?php
/**
 * Search engine API module: OAuth callback, IndexNow key file, health checks.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api;

use SearchPilot\Api\Bing\BingWebmaster;
use SearchPilot\Api\Google\GoogleAuth;
use SearchPilot\Api\Google\OAuthClient;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\IndexNow\IndexNow;
use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks only; services are resolved lazily so front-end requests pay
 * nothing except one string comparison for "*.txt" paths.
 */
class ApiModule implements Module {

	/**
	 * Error codes that may appear in the google_error query argument.
	 */
	const OAUTH_ERRORS = array( 'access_denied', 'invalid_state', 'expired_state', 'forbidden', 'missing_code', 'token_exchange_failed', 'invalid_client', 'redirect_uri_mismatch', 'missing_refresh_token', 'storage_failed', 'no_client' );

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_serve_indexnow_key' ), 0 );
		add_action( 'admin_post_' . OAuthClient::ACTION, array( $this, 'oauth_callback' ) );
		add_action( 'admin_post_nopriv_' . OAuthClient::ACTION, array( $this, 'oauth_callback_logged_out' ) );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_daily', array( $this, 'health_check' ), 20 );
		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 10, 2 );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard_summary' ) );
	}

	/**
	 * Resolves a service.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	private function service( $class ) {
		return $this->container->get( $class );
	}

	/**
	 * Serves /{key}.txt before WordPress queries anything.
	 */
	public function maybe_serve_indexnow_key() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '.txt' !== substr( $path, -4 ) ) {
			return;
		}
		$this->service( IndexNow::class )->serve_key_file();
	}

	/**
	 * OAuth callback for visitors who are not logged in: log in first, then come back.
	 */
	public function oauth_callback_logged_out() {
		auth_redirect();
		exit;
	}

	/**
	 * OAuth redirect target (admin-post.php?action=searchpilot_google_oauth).
	 *
	 * The random single-use `state` bound to the user (10-minute transient)
	 * is the CSRF protection for this endpoint.
	 */
	public function oauth_callback() {
		/** OAuth client. @var OAuthClient $oauth */
		$oauth = $this->service( OAuthClient::class );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state parameter is verified instead.
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$return = $oauth->peek_return( $state );

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! Capabilities::can_manage() ) {
			$this->oauth_redirect( $return, array( 'google_error' => 'forbidden' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['error'] ) );
			$oauth->consume_state( $state, get_current_user_id() );
			$this->logger()->warning( 'google', 'Google sign-in was not completed: ' . $error );
			$this->oauth_redirect( $return, array( 'google_error' => 'access_denied' === $error ? 'access_denied' : 'token_exchange_failed' ) );
		}
		$data = $oauth->consume_state( $state, get_current_user_id() );
		if ( is_wp_error( $data ) ) {
			$this->logger()->warning( 'google', 'Google sign-in state check failed: ' . $data->get_error_message() );
			$this->oauth_redirect( $return, array( 'google_error' => $data->get_error_code() ) );
		}
		$code = isset( $_GET['code'] ) ? (string) wp_unslash( $_GET['code'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the pattern below.
		// phpcs:enable
		if ( '' === $code || ! preg_match( '#^[A-Za-z0-9/_.\-]{10,512}$#', $code ) ) {
			$this->oauth_redirect( $data['return'], array( 'google_error' => 'missing_code' ) );
		}

		$tokens = $oauth->exchange_code( $code, $data['verifier'] );
		if ( is_wp_error( $tokens ) ) {
			$this->logger()->error( 'google', 'Exchanging the Google authorization code failed: ' . $tokens->get_error_message() );
			$code_out = in_array( $tokens->get_error_code(), array( 'invalid_client', 'redirect_uri_mismatch' ), true ) ? $tokens->get_error_code() : 'token_exchange_failed';
			$this->oauth_redirect( $data['return'], array( 'google_error' => $code_out ) );
		}
		/** Credentials. @var \SearchPilot\Core\Credentials $credentials */
		$credentials = $this->service( \SearchPilot\Core\Credentials::class );
		if ( '' === $tokens['refresh_token'] && ! $credentials->has( 'google', 'refresh_token' ) ) {
			$this->oauth_redirect( $data['return'], array( 'google_error' => 'missing_refresh_token' ) );
		}
		$stored = $oauth->store_tokens( $tokens, true );
		if ( is_wp_error( $stored ) ) {
			$this->logger()->error( 'google', 'Storing Google tokens failed: ' . $stored->get_error_message() );
			$this->oauth_redirect( $data['return'], array( 'google_error' => 'storage_failed' ) );
		}
		$this->service( Settings::class )->update( array( 'engines.google_auth' => 'oauth' ) );

		/** Search Console. @var SearchConsole $gsc */
		$gsc      = $this->service( SearchConsole::class );
		$property = $gsc->property();
		if ( '' === $property ) {
			$property = $gsc->auto_select_property();
		}
		$this->service( GoogleAuth::class )->record_success( true );
		$this->logger()->info( 'google', 'Google Search Console connected with OAuth.', array( 'property' => $property ) );

		$args = array( 'google' => 'connected' );
		if ( '' === $property ) {
			$args['google_property'] = 'missing';
		}
		$this->oauth_redirect( $data['return'], $args );
	}

	/**
	 * Redirects back to the engines page or the setup wizard and exits.
	 *
	 * @param string $return engines|setup.
	 * @param array  $args   Query args (status codes only — never secrets).
	 */
	private function oauth_redirect( $return, array $args ) {
		if ( isset( $args['google_error'] ) && ! in_array( $args['google_error'], self::OAUTH_ERRORS, true ) ) {
			$args['google_error'] = 'token_exchange_failed';
		}
		$base = 'setup' === $return ? admin_url( 'admin.php?page=searchpilot-setup&step=google' ) : admin_url( 'admin.php?page=searchpilot-engines' );
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $base ) );
		exit;
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	private function logger() {
		return $this->service( Logger::class );
	}

	/**
	 * Registers this module's issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$engines_link = admin_url( 'admin.php?page=searchpilot-engines' );

		$registry->register(
			GoogleAuth::ISSUE,
			array(
				'label'    => __( 'Google connection failed', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$data = (array) ( $issue['data'] ?? array() );
					$lead = ! empty( $data['critical'] ) ? __( 'Search Console connectivity failure: SearchPilot can no longer reach your Search Console property.', 'searchpilot-pro' ) : __( 'SearchPilot could not authenticate with Google.', 'searchpilot-pro' );
					return trim( $lead . ' ' . (string) ( $data['message'] ?? '' ) );
				},
				'why'      => __( 'Without a working Google connection SearchPilot cannot import Search Console performance data, inspect URLs, check indexing or submit sitemaps. Existing data stops updating.', 'searchpilot-pro' ),
				/* translators: %s: admin URL. */
				'fix'      => sprintf( __( 'Open SearchPilot → Search Engines (%s) and reconnect Google. If you use a service account, make sure the key still exists in Google Cloud and the account is still an Owner or Full user of the property.', 'searchpilot-pro' ), $engines_link ),
				'action'   => 'reconnect_google',
			)
		);
		$registry->register(
			BingWebmaster::ISSUE,
			array(
				'label'    => __( 'Bing Webmaster Tools connection failed', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					return trim( __( 'Bing rejected the saved API key.', 'searchpilot-pro' ) . ' ' . (string) ( $issue['data']['message'] ?? '' ) );
				},
				'why'      => __( 'Bing performance data, crawl statistics and sitemap submissions stop until the key works again. IndexNow submissions are not affected.', 'searchpilot-pro' ),
				'fix'      => __( 'Generate a new API key in Bing Webmaster Tools → Settings → API access and save it in SearchPilot → Search Engines → Bing.', 'searchpilot-pro' ),
				'action'   => 'reconnect_bing',
			)
		);
		$registry->register(
			'indexnow_key_unreachable',
			array(
				'label'    => __( 'IndexNow key file is not reachable', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					return trim( __( 'The IndexNow key file could not be verified.', 'searchpilot-pro' ) . ' ' . (string) ( $issue['data']['message'] ?? '' ) );
				},
				'why'      => __( 'Search engines check the key file before they trust IndexNow notifications. If it is unreachable, every submission is rejected (HTTP 403) and new or updated pages are discovered more slowly.', 'searchpilot-pro' ),
				'fix'      => __( 'Open the key file URL in a browser. It must show only the key. Purge the page cache/CDN for that URL, make sure the web server passes missing .txt files to WordPress, then press “Verify” in Search Engines → IndexNow.', 'searchpilot-pro' ),
				'action'   => 'verify_indexnow',
			)
		);
		$registry->register(
			'indexnow_duplicate_submitter',
			array(
				'label'    => __( 'Another plugin already sends IndexNow notifications', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					/* translators: %s: plugin name. */
					return sprintf( __( '%s is already submitting URLs to IndexNow, so SearchPilot does not send its own IndexNow notifications.', 'searchpilot-pro' ), (string) ( $issue['data']['owner_name'] ?? __( 'Another plugin', 'searchpilot-pro' ) ) );
				},
				'why'      => __( 'One IndexNow submitter is enough: engines share submissions, and duplicate pings only waste quota. Nothing is broken.', 'searchpilot-pro' ),
				'fix'      => __( 'No action is required. If you prefer SearchPilot’s queue, retries and history, switch IndexNow off in the other plugin; SearchPilot takes over automatically.', 'searchpilot-pro' ),
				'action'   => 'review_indexnow',
			)
		);
	}

	/**
	 * Daily health checks (key file, duplicate submitters, Bing quota, Google token).
	 */
	public function health_check() {
		Lock::run(
			'engines_health',
			300,
			function () {
				/** Issues. @var IssueRepository $issues */
				$issues = $this->service( IssueRepository::class );
				/** IndexNow. @var IndexNow $indexnow */
				$indexnow = $this->service( IndexNow::class );

				if ( $indexnow->is_enabled() && 'searchpilot' !== $indexnow->owner() && 'off' !== $indexnow->owner() ) {
					$issues->upsert(
						'indexnow_duplicate_submitter',
						array(
							'object_type' => 'site',
							'object_id'   => 0,
							'title'       => $indexnow->owner_name(),
							'data'        => array(
								'owner'      => $indexnow->owner(),
								'owner_name' => $indexnow->owner_name(),
							),
						)
					);
				} else {
					$issues->resolve( 'indexnow_duplicate_submitter', 'site', 0 );
				}

				if ( $indexnow->is_active() ) {
					$check = $indexnow->verify_key_details();
					if ( $check['verified'] ) {
						$issues->resolve( 'indexnow_key_unreachable', 'site', 0 );
					} else {
						$issues->upsert(
							'indexnow_key_unreachable',
							array(
								'object_type' => 'site',
								'object_id'   => 0,
								'title'       => $check['key_url'],
								'data'        => array(
									'message' => $check['message'],
									'status'  => $check['status'],
									'key_url' => $check['key_url'],
								),
							)
						);
					}
				} else {
					$issues->resolve( 'indexnow_key_unreachable', 'site', 0 );
				}

				/** Bing. @var BingWebmaster $bing */
				$bing = $this->service( BingWebmaster::class );
				if ( $bing->is_connected() ) {
					$bing->get_url_submission_quota();
					$bing->refresh_reports();
				}

				/** Google. @var GoogleAuth $google */
				$google = $this->service( GoogleAuth::class );
				if ( $google->is_configured() ) {
					$google->access_token( array( OAuthClient::SCOPE_WEBMASTERS ) );
				}
			}
		);
	}

	/**
	 * Settings side effects: a new IndexNow key needs a new verification.
	 *
	 * @param array $new    New settings.
	 * @param array $before Previous settings.
	 */
	public function settings_updated( $new, $before ) {
		$new_key = (string) ( $new['indexing']['indexnow_key'] ?? '' );
		$old_key = (string) ( $before['indexing']['indexnow_key'] ?? '' );
		if ( $new_key !== $old_key ) {
			$this->service( \SearchPilot\Core\State::class )->set( IndexNow::STATE_KEY, array() );
		}
	}

	/**
	 * Adds the `engines` dashboard section.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard_summary( $summary ) {
		$summary            = is_array( $summary ) ? $summary : array();
		$summary['engines'] = $this->service( Connections::class )->status();
		/** Bing. @var BingWebmaster $bing */
		$bing = $this->service( BingWebmaster::class );
		// Cached only: building the dashboard never calls Bing.
		$summary['bing_crawl'] = $bing->has_key() ? $bing->crawl_summary() : null;
		$summary['bing_feeds'] = $bing->has_key() ? $bing->feeds() : null;
		return $summary;
	}
}
