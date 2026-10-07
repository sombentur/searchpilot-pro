<?php
/**
 * IndexNow protocol client and key file.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\IndexNow;

use SearchPilot\Core\Activator;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow (https://www.indexnow.org): one POST notifies Bing, Yandex,
 * Seznam, Naver and Yep — participating engines share submissions, so one
 * endpoint is enough. Google does not use IndexNow.
 *
 * The key file /{key}.txt is answered by serve_key_file() before WordPress
 * parses the request (no rewrite rules, works in subdirectory installs).
 */
class IndexNow {

	const MAX_URLS = 10000;

	const STATE_KEY = 'indexnow_status';

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
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * HTTP status of the last submission (0 = none yet).
	 *
	 * @var int
	 */
	private $last_status = 0;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param HttpClient       $http      HTTP client.
	 * @param RateLimiter      $limiter   Rate limiter.
	 * @param ConflictDetector $conflicts Conflict detector.
	 * @param State            $state     State.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( Settings $settings, HttpClient $http, RateLimiter $limiter, ConflictDetector $conflicts, State $state, Logger $logger ) {
		$this->settings  = $settings;
		$this->http      = $http;
		$this->limiter   = $limiter;
		$this->conflicts = $conflicts;
		$this->state     = $state;
		$this->logger    = $logger;
	}

	/**
	 * Switched on in settings with a valid key.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->settings->get( 'indexing.indexnow', true ) && self::valid_key( $this->key() );
	}

	/**
	 * Owner of IndexNow submissions (searchpilot or another plugin id).
	 *
	 * @return string
	 */
	public function owner() {
		return $this->conflicts->owner( 'indexnow' );
	}

	/**
	 * Whether SearchPilot sends IndexNow pings (enabled and not handled by another plugin).
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->is_enabled() && 'searchpilot' === $this->owner();
	}

	/**
	 * Name of the plugin that already submits IndexNow ('' when SearchPilot owns it).
	 *
	 * @return string
	 */
	public function owner_name() {
		$owner = $this->owner();
		if ( 'searchpilot' === $owner || 'off' === $owner ) {
			return '';
		}
		$detected = $this->conflicts->detect();
		$names    = array(
			'rank_math' => __( 'Rank Math (Instant Indexing)', 'searchpilot-pro' ),
			'aioseo'    => __( 'All in One SEO (IndexNow)', 'searchpilot-pro' ),
		);
		return $names[ $owner ] ?? ( $detected[ $owner ]['name'] ?? $owner );
	}

	/**
	 * The key.
	 *
	 * @return string
	 */
	public function key() {
		return trim( (string) $this->settings->get( 'indexing.indexnow_key', '' ) );
	}

	/**
	 * Public URL of the key file.
	 *
	 * @return string
	 */
	public function key_url() {
		$key = $this->key();
		return '' === $key ? '' : home_url( '/' . $key . '.txt' );
	}

	/**
	 * Endpoint host.
	 *
	 * @return string
	 */
	public function endpoint() {
		return (string) $this->settings->get( 'indexing.indexnow_endpoint', 'api.indexnow.org' );
	}

	/**
	 * IndexNow key format: 8–128 characters a-z, A-Z, 0-9 and dashes.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function valid_key( $key ) {
		return (bool) preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', (string) $key );
	}

	/**
	 * Replaces the key with a new random 32-hex key.
	 *
	 * @return string New key.
	 */
	public function regenerate() {
		$key = Activator::generate_indexnow_key();
		$this->settings->update( array( 'indexing.indexnow_key' => $key ) );
		$this->state->set( self::STATE_KEY, array() );
		$this->logger->info( 'indexnow', 'IndexNow key regenerated.' );
		return $key;
	}

	/**
	 * Answers /{key}.txt (hooked on parse_request, priority 0). Cheap: only
	 * requests whose path ends in ".txt" are compared.
	 *
	 * @return void
	 */
	public function serve_key_file() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared, never output.
		$key = $this->key();
		if ( '' === $uri || '' === $key || ! self::valid_key( $key ) ) {
			return;
		}
		if ( ! self::matches_key_path( $uri, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $key ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			header( 'Cache-Control: public, max-age=3600' );
		}
		echo esc_html( $key );
		exit;
	}

	/**
	 * Whether a request URI is the key file of a (possibly subdirectory) install.
	 *
	 * @param string $request_uri Request URI (path and query).
	 * @param string $home_path   Path of home_url('/') (e.g. "/" or "/blog/").
	 * @param string $key         Key.
	 * @return bool
	 */
	public static function matches_key_path( $request_uri, $home_path, $key ) {
		$path = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
		if ( '.txt' !== substr( $path, -4 ) ) {
			return false;
		}
		$home_path = '/' . trim( (string) $home_path, '/' );
		$home_path = '/' === $home_path ? '' : $home_path;
		return rawurldecode( $path ) === $home_path . '/' . $key . '.txt';
	}

	/**
	 * Builds a submission payload: only URLs of the home host, deduplicated, ≤10,000.
	 *
	 * @param string[] $urls         URLs.
	 * @param string   $home_url     Home URL.
	 * @param string   $key          Key.
	 * @param string   $key_location Key file URL.
	 * @return array{payload:array,skipped:string[]}
	 */
	public static function build_payload( array $urls, $home_url, $key, $key_location ) {
		$host    = strtolower( (string) wp_parse_url( (string) $home_url, PHP_URL_HOST ) );
		$list    = array();
		$skipped = array();
		foreach ( $urls as $url ) {
			$normalized = Url::normalize( (string) $url );
			$url_host   = strtolower( (string) wp_parse_url( $normalized, PHP_URL_HOST ) );
			$scheme     = strtolower( (string) wp_parse_url( $normalized, PHP_URL_SCHEME ) );
			if ( '' === $normalized || $url_host !== $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$skipped[] = (string) $url;
				continue;
			}
			$list[ $normalized ] = true;
			if ( count( $list ) >= self::MAX_URLS ) {
				break;
			}
		}
		return array(
			'payload' => array(
				'host'        => $host,
				'key'         => (string) $key,
				'keyLocation' => (string) $key_location,
				'urlList'     => array_keys( $list ),
			),
			'skipped' => $skipped,
		);
	}

	/**
	 * Human explanation of an IndexNow HTTP status.
	 *
	 * @param int $status HTTP status.
	 * @return array{ok:bool,retry:bool,message:string}
	 */
	public static function interpret_status( $status ) {
		$status = (int) $status;
		switch ( true ) {
			case 200 === $status:
			case 202 === $status:
				return array(
					'ok'      => true,
					'retry'   => false,
					'message' => 202 === $status ? __( 'Accepted. The key is validated asynchronously by the search engine.', 'searchpilot-pro' ) : __( 'URLs submitted.', 'searchpilot-pro' ),
				);
			case 400 === $status:
				return array(
					'ok'      => false,
					'retry'   => false,
					'message' => __( 'IndexNow rejected the request as invalid (400).', 'searchpilot-pro' ),
				);
			case 403 === $status:
				return array(
					'ok'      => false,
					'retry'   => false,
					'message' => __( 'IndexNow says the key is not valid (403): the key file was not found at the key location or its content does not match. Verify the key file in Search Engines → IndexNow.', 'searchpilot-pro' ),
				);
			case 422 === $status:
				return array(
					'ok'      => false,
					'retry'   => false,
					'message' => __( 'IndexNow refused the URLs (422): they do not belong to the host or do not match the key.', 'searchpilot-pro' ),
				);
			case 429 === $status:
				return array(
					'ok'      => false,
					'retry'   => true,
					'message' => __( 'IndexNow is throttling requests (429). SearchPilot backs off and retries later.', 'searchpilot-pro' ),
				);
			case 0 === $status || $status >= 500:
				return array(
					'ok'      => false,
					'retry'   => true,
					/* translators: %d: HTTP status. */
					'message' => 0 === $status ? __( 'The IndexNow endpoint could not be reached.', 'searchpilot-pro' ) : sprintf( __( 'The IndexNow endpoint had a server error (%d).', 'searchpilot-pro' ), $status ),
				);
		}
		return array(
			'ok'      => false,
			'retry'   => false,
			/* translators: %d: HTTP status. */
			'message' => sprintf( __( 'IndexNow answered with HTTP %d.', 'searchpilot-pro' ), $status ),
		);
	}

	/**
	 * Submits URLs.
	 *
	 * @param string[] $urls URLs of this site.
	 * @return true|\WP_Error Error data: status, retryable, retry_after.
	 */
	public function submit( array $urls ) {
		if ( ! $this->is_enabled() ) {
			return new \WP_Error(
				'searchpilot_indexnow_disabled',
				__( 'IndexNow is switched off.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		$built = self::build_payload( $urls, home_url( '/' ), $this->key(), $this->key_url() );
		if ( empty( $built['payload']['urlList'] ) ) {
			return new \WP_Error(
				'searchpilot_indexnow_no_urls',
				__( 'None of the URLs belong to this site’s host, so nothing was sent to IndexNow.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( ! $this->limiter->attempt( 'indexnow' ) ) {
			return new \WP_Error(
				'searchpilot_quota',
				__( 'IndexNow request budget reached for now; the URLs are sent in the next run.', 'searchpilot-pro' ),
				array(
					'status'      => 429,
					'retryable'   => true,
					'retry_after' => $this->limiter->blocked_until( 'indexnow' ) ? $this->limiter->blocked_until( 'indexnow' ) - time() : 60,
				)
			);
		}
		$response = $this->http->post(
			'https://' . $this->endpoint() . '/indexnow',
			array(
				'json'       => $built['payload'],
				'channel'    => 'indexnow',
				'timeout'    => 20,
				'log_errors' => false,
			)
		);
		$this->last_status = (int) $response->code;
		$result            = self::interpret_status( $response->code );
		if ( $result['ok'] ) {
			$this->mark_accepted();
			return true;
		}
		$retry_after = $response->retry_after();
		if ( 429 === $response->code ) {
			$this->limiter->block( 'indexnow', time() + max( 300, $retry_after ) );
		}
		if ( 403 === $response->code ) {
			$this->state->set(
				self::STATE_KEY,
				array_merge(
					(array) $this->state->get( self::STATE_KEY, array() ),
					array(
						'verified'   => false,
						'message'    => $result['message'],
						'checked_at' => gmdate( 'c' ),
					)
				)
			);
		}
		$this->logger->warning(
			'indexnow',
			$result['message'],
			array(
				'status'   => $response->code,
				'endpoint' => $this->endpoint(),
				'urls'     => count( $built['payload']['urlList'] ),
			)
		);
		return new \WP_Error(
			'searchpilot_indexnow_' . ( $result['retry'] ? 'retry' : 'failed' ),
			$result['message'],
			array(
				'status'      => $response->code,
				'retryable'   => $result['retry'],
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * HTTP status of the last submit() request (200 or 202 on success).
	 *
	 * @return int
	 */
	public function last_status() {
		return $this->last_status ? $this->last_status : 200;
	}

	/**
	 * Fetches the key file over loopback and compares its content.
	 *
	 * @return bool
	 */
	public function verify_key() {
		$result = $this->verify_key_details();
		return (bool) $result['verified'];
	}

	/**
	 * Verification with details for the UI.
	 *
	 * @return array{verified:bool,message:string,status:int,checked_at:string,key_url:string}
	 */
	public function verify_key_details() {
		$key_url = $this->key_url();
		if ( ! self::valid_key( $this->key() ) ) {
			$out = array(
				'verified'   => false,
				'message'    => __( 'There is no valid IndexNow key yet. Generate one.', 'searchpilot-pro' ),
				'status'     => 0,
				'checked_at' => gmdate( 'c' ),
				'key_url'    => $key_url,
			);
			$this->state->set( self::STATE_KEY, $out );
			return $out;
		}
		$response = $this->http->get(
			$key_url,
			array(
				'channel'             => 'indexnow',
				'timeout'             => 15,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
				'log_errors'          => false,
				'limit_response_size' => 4096,
			)
		);
		$body     = trim( (string) $response->body );
		$verified = 200 === $response->code && $body === $this->key();
		if ( $verified ) {
			$message = __( 'The key file is reachable and matches the key.', 'searchpilot-pro' );
		} elseif ( 0 === $response->code ) {
			/* translators: %s: error. */
			$message = sprintf( __( 'The site could not fetch its own key file (%s). Loopback requests may be blocked by the host or a firewall; search engines may still reach it. Open the key file URL in a browser to check.', 'searchpilot-pro' ), $response->error_message() );
		} elseif ( 200 === $response->code ) {
			$message = __( 'The key file URL answered, but with different content — a cache, CDN or another plugin is serving something else. Purge the cache for this URL.', 'searchpilot-pro' );
		} else {
			/* translators: %d: HTTP status. */
			$message = sprintf( __( 'The key file URL answered HTTP %d. The web server may be serving .txt files directly; make sure requests for missing files reach WordPress.', 'searchpilot-pro' ), $response->code );
		}
		$out = array(
			'verified'   => $verified,
			'message'    => $message,
			'status'     => (int) $response->code,
			'checked_at' => gmdate( 'c' ),
			'key_url'    => $key_url,
		);
		$this->state->set( self::STATE_KEY, $out );
		if ( ! $verified ) {
			$this->logger->warning( 'indexnow', 'IndexNow key file check failed: ' . $message );
		}
		return $out;
	}

	/**
	 * Last verification result.
	 *
	 * @return array{verified:bool|null,message:string,checked_at:string|null,accepted_at:string|null}
	 */
	public function last_verification() {
		$data = (array) $this->state->get( self::STATE_KEY, array() );
		return array(
			'verified'    => array_key_exists( 'verified', $data ) ? (bool) $data['verified'] : null,
			'message'     => (string) ( $data['message'] ?? '' ),
			'checked_at'  => ! empty( $data['checked_at'] ) ? (string) $data['checked_at'] : null,
			'accepted_at' => ! empty( $data['accepted_at'] ) ? (string) $data['accepted_at'] : null,
		);
	}

	/**
	 * Remembers the last accepted submission.
	 */
	private function mark_accepted() {
		$data                = (array) $this->state->get( self::STATE_KEY, array() );
		$data['accepted_at'] = gmdate( 'c' );
		$this->state->set( self::STATE_KEY, $data );
	}
}
