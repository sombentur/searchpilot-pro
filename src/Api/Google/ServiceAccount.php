<?php
/**
 * Google service-account authentication (JWT bearer grant).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

use SearchPilot\Core\Credentials;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Alternative to OAuth for administrators who prefer a service account:
 * the JSON key is stored encrypted (Credentials "google_sa" → "json", or the
 * SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT constant) and access tokens are
 * obtained with an RS256-signed assertion (RFC 7523). The service-account
 * e-mail must be added as an Owner (or Full user) of the Search Console
 * property.
 */
class ServiceAccount {

	const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

	/**
	 * Hosts a key file's token_uri may point to (the assertion is only ever sent to Google).
	 */
	const TOKEN_HOSTS = array( 'oauth2.googleapis.com', 'accounts.google.com', 'www.googleapis.com' );

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

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
	 * Constructor.
	 *
	 * @param Credentials $credentials Credentials.
	 * @param HttpClient  $http        HTTP client.
	 * @param RateLimiter $limiter     Rate limiter.
	 */
	public function __construct( Credentials $credentials, HttpClient $http, RateLimiter $limiter ) {
		$this->credentials = $credentials;
		$this->http        = $http;
		$this->limiter     = $limiter;
	}

	/**
	 * Validates a JSON key file.
	 *
	 * @param string $json Raw JSON.
	 * @return array|\WP_Error Parsed key (type, project_id, private_key_id, private_key, client_email, token_uri).
	 */
	public static function parse( $json ) {
		$json = trim( (string) $json );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'searchpilot_sa_json', __( 'This is not valid JSON. Paste the entire key file you downloaded from Google Cloud (it starts with “{”).', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( 'service_account' !== ( $data['type'] ?? '' ) ) {
			return new \WP_Error( 'searchpilot_sa_type', __( 'This JSON is not a service-account key (its "type" must be "service_account"). OAuth client files are entered in the OAuth section instead.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$email = (string) ( $data['client_email'] ?? '' );
		if ( ! preg_match( '/^[^@\s]+@[a-z0-9.\-]+\.iam\.gserviceaccount\.com$/i', $email ) && ! preg_match( '/^[^@\s]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $email ) ) {
			return new \WP_Error( 'searchpilot_sa_email', __( 'The key file has no valid "client_email".', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$key = (string) ( $data['private_key'] ?? '' );
		if ( false === strpos( $key, 'PRIVATE KEY-----' ) ) {
			return new \WP_Error( 'searchpilot_sa_key', __( 'The key file has no "private_key". Create a new JSON key for the service account.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$token_uri = (string) ( $data['token_uri'] ?? '' );
		$host      = strtolower( (string) wp_parse_url( $token_uri, PHP_URL_HOST ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $token_uri, PHP_URL_SCHEME ) ) || ! in_array( $host, self::TOKEN_HOSTS, true ) ) {
			return new \WP_Error( 'searchpilot_sa_token_uri', __( 'The key file\'s "token_uri" must be Google\'s token endpoint (https://oauth2.googleapis.com/token).', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		return array(
			'type'           => 'service_account',
			'project_id'     => sanitize_text_field( (string) ( $data['project_id'] ?? '' ) ),
			'private_key_id' => preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $data['private_key_id'] ?? '' ) ),
			'private_key'    => $key,
			'client_email'   => $email,
			'token_uri'      => $token_uri,
		);
	}

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->credentials->has( 'google_sa', 'json' );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public function is_constant() {
		return $this->credentials->is_constant( 'google_sa', 'json' );
	}

	/**
	 * Parsed stored key.
	 *
	 * @return array|\WP_Error
	 */
	public function key() {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'searchpilot_sa_missing', __( 'No service-account key is saved.', 'searchpilot-pro' ) );
		}
		return self::parse( (string) $this->credentials->get( 'google_sa', 'json', '' ) );
	}

	/**
	 * Service-account e-mail ('' when none).
	 *
	 * @return string
	 */
	public function client_email() {
		$key = $this->key();
		return is_wp_error( $key ) ? '' : $key['client_email'];
	}

	/**
	 * Saves a validated key (clears cached tokens).
	 *
	 * @param string $json Raw JSON.
	 * @return true|\WP_Error
	 */
	public function save( $json ) {
		if ( $this->is_constant() ) {
			return new \WP_Error( 'searchpilot_sa_constant', __( 'The service-account key is defined in wp-config.php (SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT). Change it there.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$parsed = self::parse( $json );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return $this->credentials->set(
			'google_sa',
			array(
				'json'   => trim( (string) $json ),
				'tokens' => null,
			)
		);
	}

	/**
	 * Removes the stored key and cached tokens.
	 *
	 * @return true|\WP_Error
	 */
	public function clear() {
		return $this->credentials->clear( 'google_sa' );
	}

	/**
	 * Cached token for a scope set, or null.
	 *
	 * @param string[] $scopes Scopes.
	 * @return string|null
	 */
	public function cached_token( array $scopes ) {
		$tokens = (array) $this->credentials->get( 'google_sa', 'tokens', array() );
		$entry  = $tokens[ self::scope_key( $scopes ) ] ?? null;
		if ( is_array( $entry ) && ! empty( $entry['access_token'] ) && (int) ( $entry['expires_at'] ?? 0 ) > time() ) {
			return (string) $entry['access_token'];
		}
		return null;
	}

	/**
	 * Forgets cached tokens (after a 401).
	 *
	 * @param string[]|null $scopes Scope set (null = all).
	 */
	public function forget_tokens( $scopes = null ) {
		if ( null === $scopes ) {
			$this->credentials->set( 'google_sa', array( 'tokens' => null ) );
			return;
		}
		$tokens = (array) $this->credentials->get( 'google_sa', 'tokens', array() );
		unset( $tokens[ self::scope_key( $scopes ) ] );
		$this->credentials->set( 'google_sa', array( 'tokens' => $tokens ) );
	}

	/**
	 * Obtains (and caches) an access token for a scope set.
	 *
	 * @param string[]   $scopes Scopes.
	 * @param array|null $key    Parsed key to use instead of the stored one (connection test).
	 * @param bool       $cache  Store the token.
	 * @return string|\WP_Error
	 */
	public function fetch_token( array $scopes, $key = null, $cache = true ) {
		$key = null === $key ? $this->key() : $key;
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$assertion = Jwt::service_account_assertion( $key['client_email'], $key['private_key'], $scopes, $key['token_uri'], null, $key['private_key_id'] );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}
		if ( ! $this->limiter->attempt( 'google_oauth' ) ) {
			return new \WP_Error( 'searchpilot_quota', __( 'Too many Google token requests in a short time. Try again in a minute.', 'searchpilot-pro' ), array( 'status' => 429 ) );
		}
		$response = $this->http->post(
			$key['token_uri'],
			array(
				'body'    => array(
					'grant_type' => self::GRANT_TYPE,
					'assertion'  => $assertion,
				),
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'channel' => 'google',
				'timeout' => 20,
			)
		);
		$tokens = OAuthClient::parse_token_response( $response->code, $response->json(), $response->error_message() );
		if ( is_wp_error( $tokens ) ) {
			if ( 'invalid_grant' === $tokens->get_error_code() ) {
				$detail = is_array( $response->json() ) ? (string) ( $response->json()['error_description'] ?? '' ) : '';
				return new \WP_Error(
					'invalid_grant',
					/* translators: %s: Google's explanation. */
					sprintf( __( 'Google rejected the service-account key (%s). The key may have been deleted or the server clock is wrong. Create a new key and paste it again.', 'searchpilot-pro' ), '' !== $detail ? sanitize_text_field( $detail ) : 'invalid_grant' ),
					array( 'status' => 401 )
				);
			}
			return $tokens;
		}
		if ( $cache ) {
			$all                                = (array) $this->credentials->get( 'google_sa', 'tokens', array() );
			$all[ self::scope_key( $scopes ) ] = array(
				'access_token' => $tokens['access_token'],
				'expires_at'   => $tokens['expires_at'],
			);
			// Keep the cache small: at most a few scope sets are ever used.
			$all = array_slice( $all, -4, null, true );
			$this->credentials->set( 'google_sa', array( 'tokens' => $all ) );
		}
		return $tokens['access_token'];
	}

	/**
	 * Cache key of a scope set (order-independent).
	 *
	 * @param string[] $scopes Scopes.
	 * @return string
	 */
	public static function scope_key( array $scopes ) {
		$scopes = array_values( array_unique( array_map( 'strval', $scopes ) ) );
		sort( $scopes );
		return substr( md5( implode( ' ', $scopes ) ), 0, 12 );
	}
}
