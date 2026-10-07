<?php
/**
 * Normalized HTTP response.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core\Http;

use SearchPilot\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable response value object.
 */
class HttpResponse {

	/**
	 * Status code (0 on transport failure).
	 *
	 * @var int
	 */
	public $code;

	/**
	 * Raw body.
	 *
	 * @var string
	 */
	public $body;

	/**
	 * Lower-cased headers.
	 *
	 * @var array<string,string>
	 */
	public $headers;

	/**
	 * Transport error message.
	 *
	 * @var string
	 */
	public $error;

	/**
	 * Duration in milliseconds.
	 *
	 * @var int
	 */
	public $duration_ms;

	/**
	 * Final URL requested.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Decoded JSON cache.
	 *
	 * @var mixed
	 */
	private $json = false;

	/**
	 * Constructor.
	 *
	 * @param int    $code        Status.
	 * @param string $body        Body.
	 * @param array  $headers     Headers.
	 * @param string $error       Transport error.
	 * @param int    $duration_ms Duration.
	 * @param string $url         URL.
	 */
	public function __construct( $code, $body = '', array $headers = array(), $error = '', $duration_ms = 0, $url = '' ) {
		$this->code        = (int) $code;
		$this->body        = (string) $body;
		$this->headers     = array_change_key_case( $headers, CASE_LOWER );
		$this->error       = (string) $error;
		$this->duration_ms = (int) $duration_ms;
		$this->url         = (string) $url;
	}

	/**
	 * 2xx status.
	 *
	 * @return bool
	 */
	public function ok() {
		return $this->code >= 200 && $this->code < 300;
	}

	/**
	 * Decoded JSON body (array) or null.
	 *
	 * @return array|null
	 */
	public function json() {
		if ( false === $this->json ) {
			$decoded    = json_decode( $this->body, true );
			$this->json = is_array( $decoded ) ? $decoded : null;
		}
		return $this->json;
	}

	/**
	 * Header value.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public function header( $name ) {
		$value = $this->headers[ strtolower( $name ) ] ?? '';
		return is_array( $value ) ? (string) end( $value ) : (string) $value;
	}

	/**
	 * Whether retrying later may succeed.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return 0 === $this->code || in_array( $this->code, array( 408, 425, 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Whether the provider throttled us.
	 *
	 * @return bool
	 */
	public function is_rate_limited() {
		if ( 429 === $this->code ) {
			return true;
		}
		$json = $this->json();
		if ( 403 === $this->code && is_array( $json ) ) {
			$reason = $json['error']['errors'][0]['reason'] ?? ( $json['error']['status'] ?? '' );
			return in_array( $reason, array( 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'RESOURCE_EXHAUSTED' ), true );
		}
		return false;
	}

	/**
	 * Seconds from a Retry-After header (0 when absent).
	 *
	 * @return int
	 */
	public function retry_after() {
		$value = trim( $this->header( 'retry-after' ) );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$ts = strtotime( $value );
		return $ts ? max( 0, $ts - time() ) : 0;
	}

	/**
	 * Best-effort provider error message (redacted).
	 *
	 * @return string
	 */
	public function error_message() {
		if ( '' !== $this->error ) {
			return Logger::redact_string( $this->error );
		}
		$json    = $this->json();
		$message = '';
		if ( is_array( $json ) ) {
			if ( isset( $json['error']['message'] ) ) {
				$message = (string) $json['error']['message'];
			} elseif ( isset( $json['error_description'] ) ) {
				$message = (string) $json['error_description'];
			} elseif ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
				$message = $json['error'];
			} elseif ( isset( $json['Message'] ) ) {
				$message = (string) $json['Message'];
			} elseif ( isset( $json['message'] ) ) {
				$message = (string) $json['message'];
			}
		}
		if ( '' === $message && ! $this->ok() ) {
			$message = 'HTTP ' . $this->code . ( '' !== $this->body ? ': ' . mb_substr( wp_strip_all_tags( $this->body ), 0, 200 ) : '' );
		}
		return Logger::redact_string( $message );
	}

	/**
	 * Converts a failure into a WP_Error.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	public function to_wp_error( $code = 'searchpilot_http' ) {
		return new \WP_Error(
			$code,
			$this->error_message(),
			array(
				'status'    => $this->code,
				'retryable' => $this->is_retryable(),
			)
		);
	}
}
