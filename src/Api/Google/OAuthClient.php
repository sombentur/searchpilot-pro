<?php
/**
 * Google OAuth 2.0 web-server flow with the administrator's own client.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

use SearchPilot\Core\Credentials;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization-code flow (RFC 6749 §4.1) with PKCE S256 (RFC 7636):
 *
 *   1. create_authorization() stores a random state bound to the user in a
 *      10-minute transient (with the PKCE verifier and the return screen)
 *      and returns Google's consent URL.
 *   2. Google redirects to admin-post.php?action=searchpilot_google_oauth;
 *      ApiModule::oauth_callback() calls consume_state() and exchange_code().
 *   3. Tokens are stored encrypted in Credentials service "google".
 *
 * The client ID/secret belong to the site owner's Google Cloud project, so
 * no third party ever sees the tokens.
 */
class OAuthClient {

	const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	const REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

	const SCOPE_WEBMASTERS = 'https://www.googleapis.com/auth/webmasters';

	const SCOPE_INDEXING = 'https://www.googleapis.com/auth/indexing';

	const ACTION = 'searchpilot_google_oauth';

	const STATE_PREFIX = 'searchpilot_goauth_';

	const STATE_TTL = 600;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param Settings    $settings    Settings.
	 * @param HttpClient  $http        HTTP client.
	 * @param RateLimiter $limiter     Rate limiter.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Credentials $credentials, Settings $settings, HttpClient $http, RateLimiter $limiter, Logger $logger ) {
		$this->credentials = $credentials;
		$this->settings    = $settings;
		$this->http        = $http;
		$this->limiter     = $limiter;
		$this->logger      = $logger;
	}

	/**
	 * Redirect URI to register in the Google Cloud OAuth client (exact match).
	 *
	 * @return string
	 */
	public function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . self::ACTION );
	}

	/**
	 * Whether a client ID and secret are available.
	 *
	 * @return bool
	 */
	public function has_client() {
		return $this->credentials->has( 'google', 'client_id' ) && $this->credentials->has( 'google', 'client_secret' );
	}

	/**
	 * Validates and stores the OAuth client credentials.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret ('' keeps the saved one).
	 * @return true|\WP_Error
	 */
	public function save_client( $client_id, $client_secret ) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		$fields        = array();

		if ( ! $this->credentials->is_constant( 'google', 'client_id' ) && ( '' !== $client_id || ! $this->credentials->has( 'google', 'client_id' ) ) ) {
			if ( ! self::valid_client_id( $client_id ) ) {
				return new \WP_Error( 'searchpilot_invalid_client_id', __( 'That does not look like a Google OAuth client ID. It ends with “.apps.googleusercontent.com”.', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
			$fields['client_id'] = $client_id;
		}
		if ( ! $this->credentials->is_constant( 'google', 'client_secret' ) ) {
			if ( '' === $client_secret && ! $this->credentials->has( 'google', 'client_secret' ) ) {
				return new \WP_Error( 'searchpilot_invalid_client_secret', __( 'Enter the client secret shown next to the client ID in Google Cloud.', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
			if ( '' !== $client_secret ) {
				if ( ! preg_match( '/^[A-Za-z0-9_\-]{10,128}$/', $client_secret ) ) {
					return new \WP_Error( 'searchpilot_invalid_client_secret', __( 'The client secret contains unexpected characters. Copy it again from Google Cloud → Credentials.', 'searchpilot-pro' ), array( 'status' => 400 ) );
				}
				$fields['client_secret'] = $client_secret;
			}
		}
		$current = (string) $this->credentials->get( 'google', 'client_id', '' );
		if ( isset( $fields['client_id'] ) && '' !== $current && $current !== $fields['client_id'] ) {
			// A different client invalidates tokens issued to the previous one.
			$fields['access_token']  = null;
			$fields['refresh_token'] = null;
			$fields['expires_at']    = null;
			$fields['scope']         = null;
		}
		if ( empty( $fields ) ) {
			return true;
		}
		return $this->credentials->set( 'google', $fields );
	}

	/**
	 * Format check for client IDs.
	 *
	 * @param string $client_id Client ID.
	 * @return bool
	 */
	public static function valid_client_id( $client_id ) {
		return (bool) preg_match( '/^[0-9]{6,}-[a-z0-9]+\.apps\.googleusercontent\.com$/i', (string) $client_id );
	}

	/**
	 * Scopes to request: Search Console always (sitemap submission needs
	 * write access), the Indexing API only when the administrator enabled it.
	 *
	 * @param bool|null $indexing Include the Indexing API scope (null = setting).
	 * @return string[]
	 */
	public function scopes( $indexing = null ) {
		$indexing = null === $indexing ? (bool) $this->settings->get( 'indexing.google_indexing_api', false ) : (bool) $indexing;
		$scopes   = array( self::SCOPE_WEBMASTERS );
		if ( $indexing ) {
			$scopes[] = self::SCOPE_INDEXING;
		}
		return $scopes;
	}

	/**
	 * Creates the authorization request.
	 *
	 * @param string $return  engines|setup — where to send the user back.
	 * @param int    $user_id User starting the flow.
	 * @return string|\WP_Error Consent URL.
	 */
	public function create_authorization( $return, $user_id ) {
		if ( ! $this->has_client() ) {
			return new \WP_Error( 'searchpilot_no_client', __( 'Save your Google OAuth client ID and secret first.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$state    = wp_generate_password( 40, false, false );
		$verifier = self::pkce_verifier();
		$scopes   = $this->scopes();
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'user'     => (int) $user_id,
				'verifier' => $verifier,
				'return'   => 'setup' === $return ? 'setup' : 'engines',
				'scopes'   => $scopes,
				'created'  => time(),
			),
			self::STATE_TTL
		);
		return self::build_auth_url(
			array(
				'client_id'              => (string) $this->credentials->get( 'google', 'client_id' ),
				'redirect_uri'           => $this->redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => implode( ' ', $scopes ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
				'code_challenge'         => self::pkce_challenge( $verifier ),
				'code_challenge_method'  => 'S256',
			)
		);
	}

	/**
	 * Consent URL from parameters.
	 *
	 * @param array $params Query parameters.
	 * @return string
	 */
	public static function build_auth_url( array $params ) {
		return self::AUTH_ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Random PKCE code verifier (43–128 unreserved characters).
	 *
	 * @return string
	 */
	public static function pkce_verifier() {
		try {
			$bytes = random_bytes( 48 );
		} catch ( \Exception $e ) {
			$bytes = wp_generate_password( 48, true, true );
		}
		return Jwt::base64url_encode( $bytes );
	}

	/**
	 * S256 code challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	public static function pkce_challenge( $verifier ) {
		return Jwt::base64url_encode( hash( 'sha256', (string) $verifier, true ) );
	}

	/**
	 * Reads and deletes the stored state (single use, bound to the user).
	 *
	 * @param string $state   State from the callback.
	 * @param int    $user_id Current user.
	 * @return array|\WP_Error
	 */
	public function consume_state( $state, $user_id ) {
		$state = (string) $state;
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/', $state ) ) {
			return new \WP_Error( 'invalid_state', __( 'The Google sign-in response could not be verified. Start the connection again.', 'searchpilot-pro' ) );
		}
		$data = get_transient( self::STATE_PREFIX . $state );
		delete_transient( self::STATE_PREFIX . $state );
		if ( ! is_array( $data ) || empty( $data['verifier'] ) ) {
			return new \WP_Error( 'expired_state', __( 'The Google sign-in took too long or was already used. Start the connection again.', 'searchpilot-pro' ) );
		}
		if ( (int) $data['user'] !== (int) $user_id ) {
			return new \WP_Error( 'invalid_state', __( 'The Google sign-in was started by a different user.', 'searchpilot-pro' ) );
		}
		return $data;
	}

	/**
	 * Return target stored with a state, without consuming it (for error redirects).
	 *
	 * @param string $state State.
	 * @return string engines|setup
	 */
	public function peek_return( $state ) {
		$state = (string) $state;
		if ( ! preg_match( '/^[A-Za-z0-9]{40}$/', $state ) ) {
			return 'engines';
		}
		$data = get_transient( self::STATE_PREFIX . $state );
		return is_array( $data ) && 'setup' === ( $data['return'] ?? '' ) ? 'setup' : 'engines';
	}

	/**
	 * Exchanges an authorization code for tokens.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE verifier.
	 * @return array|\WP_Error Normalized token set.
	 */
	public function exchange_code( $code, $verifier ) {
		return $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $code,
				'redirect_uri'  => $this->redirect_uri(),
				'client_id'     => (string) $this->credentials->get( 'google', 'client_id' ),
				'client_secret' => (string) $this->credentials->get( 'google', 'client_secret' ),
				'code_verifier' => (string) $verifier,
			)
		);
	}

	/**
	 * Refreshes an access token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @return array|\WP_Error
	 */
	public function refresh( $refresh_token ) {
		return $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $refresh_token,
				'client_id'     => (string) $this->credentials->get( 'google', 'client_id' ),
				'client_secret' => (string) $this->credentials->get( 'google', 'client_secret' ),
			)
		);
	}

	/**
	 * Revokes a token at Google (best effort).
	 *
	 * @param string $token Refresh or access token.
	 * @return true|\WP_Error
	 */
	public function revoke( $token ) {
		if ( '' === (string) $token ) {
			return true;
		}
		$response = $this->http->post(
			self::REVOKE_ENDPOINT,
			array(
				'body'    => array( 'token' => (string) $token ),
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'channel' => 'google',
				'timeout' => 15,
			)
		);
		// 400 "invalid_token" means it was already revoked or expired: nothing left to do.
		if ( $response->ok() || 400 === $response->code ) {
			return true;
		}
		return $response->to_wp_error( 'searchpilot_google_revoke' );
	}

	/**
	 * Stores a token set from exchange_code()/refresh().
	 *
	 * @param array $tokens Normalized token set.
	 * @param bool  $initial First connection (records connected_at).
	 * @return true|\WP_Error
	 */
	public function store_tokens( array $tokens, $initial = false ) {
		$fields = array(
			'access_token' => (string) $tokens['access_token'],
			'expires_at'   => (int) $tokens['expires_at'],
		);
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$fields['refresh_token'] = (string) $tokens['refresh_token'];
		}
		if ( ! empty( $tokens['scope'] ) ) {
			$fields['scope'] = (string) $tokens['scope'];
		}
		if ( $initial ) {
			$fields['connected_at'] = gmdate( 'c' );
		}
		return $this->credentials->set( 'google', $fields );
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array $body Form body.
	 * @return array|\WP_Error
	 */
	private function token_request( array $body ) {
		if ( ! $this->limiter->attempt( 'google_oauth' ) ) {
			return new \WP_Error( 'searchpilot_quota', __( 'Too many Google sign-in requests in a short time. Try again in a minute.', 'searchpilot-pro' ), array( 'status' => 429 ) );
		}
		$response = $this->http->post(
			self::TOKEN_ENDPOINT,
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'channel' => 'google',
				'timeout' => 20,
			)
		);
		return self::parse_token_response( $response->code, $response->json(), $response->error_message() );
	}

	/**
	 * Normalizes a token endpoint response.
	 *
	 * @param int        $status  HTTP status (0 = transport failure).
	 * @param array|null $json    Decoded body.
	 * @param string     $message Redacted error message.
	 * @param int|null   $now     Current time (tests).
	 * @return array|\WP_Error {access_token, refresh_token, expires_at, scope}
	 */
	public static function parse_token_response( $status, $json, $message = '', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( $status >= 200 && $status < 300 && is_array( $json ) && ! empty( $json['access_token'] ) ) {
			$expires_in = isset( $json['expires_in'] ) ? (int) $json['expires_in'] : 3600;
			return array(
				'access_token'  => (string) $json['access_token'],
				'refresh_token' => isset( $json['refresh_token'] ) ? (string) $json['refresh_token'] : '',
				// Renew a minute early so a token never expires mid-request.
				'expires_at'    => $now + max( 60, $expires_in ) - 60,
				'scope'         => isset( $json['scope'] ) ? (string) $json['scope'] : '',
			);
		}
		$code = is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ? sanitize_key( $json['error'] ) : '';
		if ( 'invalid_grant' === $code ) {
			return new \WP_Error( 'invalid_grant', __( 'Google no longer accepts the saved authorization (it was revoked, expired, or the password changed). Reconnect Google.', 'searchpilot-pro' ), array( 'status' => 401 ) );
		}
		if ( in_array( $code, array( 'invalid_client', 'unauthorized_client' ), true ) ) {
			return new \WP_Error( 'invalid_client', __( 'Google rejected the OAuth client ID or secret. Check them in Google Cloud → Credentials.', 'searchpilot-pro' ), array( 'status' => 401 ) );
		}
		if ( 'redirect_uri_mismatch' === $code ) {
			return new \WP_Error( 'redirect_uri_mismatch', __( 'The redirect URI registered in Google Cloud does not match the one SearchPilot shows. Copy it exactly into the OAuth client.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( 0 === (int) $status ) {
			return new \WP_Error(
				'searchpilot_google_unreachable',
				/* translators: %s: error message. */
				sprintf( __( 'Google could not be reached: %s', 'searchpilot-pro' ), $message ),
				array(
					'status'    => 502,
					'retryable' => true,
				)
			);
		}
		return new \WP_Error(
			'searchpilot_google_token',
			/* translators: %s: error message. */
			sprintf( __( 'Google did not issue a token: %s', 'searchpilot-pro' ), '' !== $message ? $message : 'HTTP ' . (int) $status ),
			array(
				'status'    => (int) $status >= 500 ? 502 : 400,
				'retryable' => (int) $status >= 500,
			)
		);
	}
}
