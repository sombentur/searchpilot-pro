<?php
/**
 * Access tokens for Google APIs (OAuth or service account).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

use SearchPilot\Core\Credentials;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\HttpResponse;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One entry point for "give me a valid token for these scopes". Refreshes
 * automatically, retries an API call once after a 401, and when Google
 * permanently rejects the authorization (invalid_grant) marks the
 * connection as failed, records the `google_connection_failed` issue and
 * logs it. Connection health (not secrets) lives in State
 * `google_connection`.
 */
class GoogleAuth {

	const STATE_KEY = 'google_connection';

	const ISSUE = 'google_connection_failed';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * OAuth client.
	 *
	 * @var OAuthClient
	 */
	private $oauth;

	/**
	 * Service account.
	 *
	 * @var ServiceAccount
	 */
	private $service_account;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings        Settings.
	 * @param Credentials     $credentials     Credentials.
	 * @param State           $state           State.
	 * @param OAuthClient     $oauth           OAuth client.
	 * @param ServiceAccount  $service_account Service account.
	 * @param HttpClient      $http            HTTP client.
	 * @param Logger          $logger          Logger.
	 * @param IssueRepository $issues          Issues.
	 */
	public function __construct( Settings $settings, Credentials $credentials, State $state, OAuthClient $oauth, ServiceAccount $service_account, HttpClient $http, Logger $logger, IssueRepository $issues ) {
		$this->settings        = $settings;
		$this->credentials     = $credentials;
		$this->state           = $state;
		$this->oauth           = $oauth;
		$this->service_account = $service_account;
		$this->http            = $http;
		$this->logger          = $logger;
		$this->issues          = $issues;
	}

	/**
	 * OAuth client service.
	 *
	 * @return OAuthClient
	 */
	public function oauth() {
		return $this->oauth;
	}

	/**
	 * Service-account service.
	 *
	 * @return ServiceAccount
	 */
	public function service_account() {
		return $this->service_account;
	}

	/**
	 * Configured method.
	 *
	 * @return string oauth|service_account
	 */
	public function method() {
		return 'service_account' === $this->settings->get( 'engines.google_auth', 'oauth' ) ? 'service_account' : 'oauth';
	}

	/**
	 * Whether usable credentials exist for the configured method.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( 'service_account' === $this->method() ) {
			return $this->service_account->is_configured();
		}
		return $this->oauth->has_client() && $this->credentials->has( 'google', 'refresh_token' );
	}

	/**
	 * Scopes granted to the OAuth connection.
	 *
	 * @return string[]
	 */
	public function granted_scopes() {
		$scope = trim( (string) $this->credentials->get( 'google', 'scope', '' ) );
		if ( '' === $scope ) {
			return $this->credentials->has( 'google', 'refresh_token' ) ? array( OAuthClient::SCOPE_WEBMASTERS ) : array();
		}
		return array_values( array_filter( preg_split( '/\s+/', $scope ) ) );
	}

	/**
	 * Whether a scope can be used (service accounts may request any scope).
	 *
	 * @param string $scope Scope URL.
	 * @return bool
	 */
	public function has_scope( $scope ) {
		if ( 'service_account' === $this->method() ) {
			return $this->service_account->is_configured();
		}
		return in_array( $scope, $this->granted_scopes(), true );
	}

	/**
	 * Valid access token for the scopes.
	 *
	 * @param string[] $scopes        Scopes.
	 * @param bool     $force_refresh Ignore the cached token.
	 * @return string|\WP_Error
	 */
	public function access_token( array $scopes, $force_refresh = false ) {
		$scopes = array_values( array_unique( array_filter( array_map( 'strval', $scopes ) ) ) );
		if ( empty( $scopes ) ) {
			$scopes = array( OAuthClient::SCOPE_WEBMASTERS );
		}
		if ( 'service_account' === $this->method() ) {
			return $this->service_account_token( $scopes, $force_refresh );
		}
		return $this->oauth_token( $scopes, $force_refresh );
	}

	/**
	 * OAuth token (refreshing when needed).
	 *
	 * @param string[] $scopes        Scopes.
	 * @param bool     $force_refresh Force refresh.
	 * @return string|\WP_Error
	 */
	private function oauth_token( array $scopes, $force_refresh ) {
		$refresh = (string) $this->credentials->get( 'google', 'refresh_token', '' );
		if ( '' === $refresh || ! $this->oauth->has_client() ) {
			return new \WP_Error( 'searchpilot_google_not_connected', __( 'Google Search Console is not connected.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		foreach ( $scopes as $scope ) {
			if ( ! $this->has_scope( $scope ) ) {
				return new \WP_Error(
					'searchpilot_scope_missing',
					OAuthClient::SCOPE_INDEXING === $scope
						? __( 'The Google connection does not include the Indexing API permission yet. Reconnect Google (Search Engines → Google) and allow the Indexing API.', 'searchpilot-pro' )
						: __( 'The Google connection is missing a required permission. Reconnect Google.', 'searchpilot-pro' ),
					array( 'status' => 403 )
				);
			}
		}
		$token   = (string) $this->credentials->get( 'google', 'access_token', '' );
		$expires = (int) $this->credentials->get( 'google', 'expires_at', 0 );
		if ( ! $force_refresh && '' !== $token && $expires > time() ) {
			return $token;
		}
		$tokens = $this->oauth->refresh( $refresh );
		if ( is_wp_error( $tokens ) ) {
			if ( in_array( $tokens->get_error_code(), array( 'invalid_grant', 'invalid_client' ), true ) ) {
				$this->record_failure( $tokens, 'invalid_grant' === $tokens->get_error_code() );
			} else {
				$this->logger->warning( 'google', 'Refreshing the Google access token failed: ' . $tokens->get_error_message() );
			}
			return $tokens;
		}
		$stored = $this->oauth->store_tokens( $tokens );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->record_success();
		return $tokens['access_token'];
	}

	/**
	 * Service-account token (cached per scope set).
	 *
	 * @param string[] $scopes        Scopes.
	 * @param bool     $force_refresh Force a new token.
	 * @return string|\WP_Error
	 */
	private function service_account_token( array $scopes, $force_refresh ) {
		if ( ! $this->service_account->is_configured() ) {
			return new \WP_Error( 'searchpilot_google_not_connected', __( 'No Google service-account key is saved.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( ! $force_refresh ) {
			$cached = $this->service_account->cached_token( $scopes );
			if ( null !== $cached ) {
				return $cached;
			}
		}
		$token = $this->service_account->fetch_token( $scopes );
		if ( is_wp_error( $token ) ) {
			if ( in_array( $token->get_error_code(), array( 'invalid_grant', 'invalid_client', 'searchpilot_sa_key', 'searchpilot_jwt_key', 'searchpilot_sa_json' ), true ) ) {
				$this->record_failure( $token, false );
			} else {
				$this->logger->warning( 'google', 'Service-account token request failed: ' . $token->get_error_message() );
			}
			return $token;
		}
		$this->record_success();
		return $token;
	}

	/**
	 * Forgets cached tokens for a scope set (forces a refresh next time).
	 *
	 * @param string[] $scopes Scopes.
	 */
	public function forget_token( array $scopes ) {
		if ( 'service_account' === $this->method() ) {
			$this->service_account->forget_tokens( $scopes );
			return;
		}
		$this->credentials->set(
			'google',
			array(
				'access_token' => null,
				'expires_at'   => null,
			)
		);
	}

	/**
	 * Authorized API request. A 401 refreshes the token once and retries.
	 *
	 * @param string   $method HTTP method.
	 * @param string   $url    URL.
	 * @param array    $args   HttpClient args.
	 * @param string[] $scopes Scopes.
	 * @return HttpResponse|\WP_Error
	 */
	public function request( $method, $url, array $args, array $scopes ) {
		$token = $this->access_token( $scopes );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args            = wp_parse_args( $args, array( 'channel' => 'google' ) );
		$args['headers'] = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$response                         = $this->http->request( $method, $url, $args );
		if ( 401 !== $response->code ) {
			return $response;
		}
		$this->forget_token( $scopes );
		$token = $this->access_token( $scopes, true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$response                         = $this->http->request( $method, $url, $args );
		if ( 401 === $response->code ) {
			$this->record_failure( new \WP_Error( 'searchpilot_google_unauthorized', __( 'Google refused the credentials even after refreshing the token. Reconnect Google.', 'searchpilot-pro' ) ), false );
		}
		return $response;
	}

	/**
	 * Connection health from State.
	 *
	 * @return array{status:string,error:string,error_code:string,checked_at:string|null,ever_connected:bool}
	 */
	public function connection() {
		$data = (array) $this->state->get( self::STATE_KEY, array() );
		return array(
			'status'         => (string) ( $data['status'] ?? '' ),
			'error'          => (string) ( $data['error'] ?? '' ),
			'error_code'     => (string) ( $data['error_code'] ?? '' ),
			'checked_at'     => ! empty( $data['checked_at'] ) ? (string) $data['checked_at'] : null,
			'ever_connected' => ! empty( $data['ever_connected'] ),
		);
	}

	/**
	 * Records a working connection (and resolves the failure issue).
	 *
	 * @param bool $force Write even if recently recorded.
	 */
	public function record_success( $force = false ) {
		$current = $this->connection();
		$fresh   = $current['checked_at'] && ( time() - (int) strtotime( $current['checked_at'] ) ) < 10 * MINUTE_IN_SECONDS;
		$has_property = '' !== (string) $this->settings->get( 'engines.google_property', '' );
		if ( ! $force && 'connected' === $current['status'] && $fresh && ( $current['ever_connected'] || ! $has_property ) ) {
			return;
		}
		$this->state->set(
			self::STATE_KEY,
			array(
				'status'         => 'connected',
				'error'          => '',
				'error_code'     => '',
				'checked_at'     => gmdate( 'c' ),
				'ever_connected' => $current['ever_connected'] || $has_property,
			)
		);
		if ( 'error' === $current['status'] ) {
			$this->logger->info( 'google', 'Google connection restored.' );
		}
		$this->issues->resolve( self::ISSUE, 'site', 0 );
	}

	/**
	 * Records a permanent authorization failure.
	 *
	 * @param \WP_Error $error         Error.
	 * @param bool      $clear_tokens  Drop the rejected OAuth tokens (invalid_grant).
	 */
	public function record_failure( \WP_Error $error, $clear_tokens = false ) {
		$current = $this->connection();
		if ( $clear_tokens && 'oauth' === $this->method() ) {
			$this->credentials->set(
				'google',
				array(
					'access_token'  => null,
					'refresh_token' => null,
					'expires_at'    => null,
				)
			);
		}
		$this->state->set(
			self::STATE_KEY,
			array(
				'status'         => 'error',
				'error'          => $error->get_error_message(),
				'error_code'     => (string) $error->get_error_code(),
				'checked_at'     => gmdate( 'c' ),
				'ever_connected' => $current['ever_connected'],
			)
		);
		$critical = $current['ever_connected'] && '' !== (string) $this->settings->get( 'engines.google_property', '' );
		$this->issues->upsert(
			self::ISSUE,
			array(
				'object_type' => 'site',
				'object_id'   => 0,
				'severity'    => $critical ? 'critical' : 'high',
				'title'       => $critical ? __( 'Search Console connectivity failure', 'searchpilot-pro' ) : __( 'Google connection failed', 'searchpilot-pro' ),
				'data'        => array(
					'code'     => (string) $error->get_error_code(),
					'message'  => $error->get_error_message(),
					'method'   => $this->method(),
					'critical' => $critical,
				),
			)
		);
		$this->logger->error(
			'google',
			'Google authorization failed: ' . $error->get_error_message(),
			array(
				'code'   => (string) $error->get_error_code(),
				'method' => $this->method(),
			)
		);
	}

	/**
	 * Account shown in the UI ('' for OAuth: the plugin requests no profile scope).
	 *
	 * @return string
	 */
	public function account() {
		return 'service_account' === $this->method() ? $this->service_account->client_email() : '';
	}

	/**
	 * Revokes and removes Google credentials (client ID/secret are kept
	 * unless $forget_client).
	 *
	 * @param bool $forget_client Also remove the OAuth client ID and secret.
	 * @return true
	 */
	public function disconnect( $forget_client = false ) {
		$refresh = (string) $this->credentials->get( 'google', 'refresh_token', '' );
		$access  = (string) $this->credentials->get( 'google', 'access_token', '' );
		$token   = '' !== $refresh ? $refresh : $access;
		if ( '' !== $token ) {
			$revoked = $this->oauth->revoke( $token );
			if ( is_wp_error( $revoked ) ) {
				$this->logger->warning( 'google', 'Revoking the Google token failed (it was removed locally anyway): ' . $revoked->get_error_message() );
			}
		}
		$fields = array(
			'access_token'  => null,
			'refresh_token' => null,
			'expires_at'    => null,
			'scope'         => null,
			'connected_at'  => null,
		);
		if ( $forget_client ) {
			$fields['client_id']     = null;
			$fields['client_secret'] = null;
		}
		$this->credentials->set( 'google', $fields );
		if ( $this->service_account->is_constant() ) {
			$this->service_account->forget_tokens();
		} else {
			$this->service_account->clear();
		}
		$this->settings->update( array( 'engines.google_property' => '' ) );
		$this->state->set( self::STATE_KEY, array() );
		$this->issues->resolve( self::ISSUE, 'site', 0 );
		$this->logger->info( 'google', 'Google was disconnected by an administrator.' );
		return true;
	}
}
