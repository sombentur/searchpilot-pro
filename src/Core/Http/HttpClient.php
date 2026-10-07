<?php
/**
 * Thin wrapper around the WordPress HTTP API.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core\Http;

use SearchPilot\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Adds JSON handling, timing, redacted logging and an injectable transport
 * (for unit tests) on top of wp_remote_request().
 */
class HttpClient {

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Optional transport: callable( string $url, array $args ) returning a
	 * wp_remote_request()-style array or WP_Error.
	 *
	 * @var callable|null
	 */
	private $transport = null;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Replaces the transport (tests).
	 *
	 * @param callable|null $transport Transport.
	 */
	public function set_transport( $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Default User-Agent.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'SearchPilotPro/' . SEARCHPILOT_VERSION . ' (WordPress; +' . home_url( '/' ) . ')';
	}

	/**
	 * GET shortcut.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args (see request()).
	 * @return HttpResponse
	 */
	public function get( $url, array $args = array() ) {
		return $this->request( 'GET', $url, $args );
	}

	/**
	 * POST shortcut.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args (see request()).
	 * @return HttpResponse
	 */
	public function post( $url, array $args = array() ) {
		return $this->request( 'POST', $url, $args );
	}

	/**
	 * Performs a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args {
	 *     @type array  $headers     Headers.
	 *     @type mixed  $json        Body encoded as JSON.
	 *     @type mixed  $body        Raw body or form array.
	 *     @type array  $query       Query args (raw-url-encoded).
	 *     @type int    $timeout     Seconds (default 20).
	 *     @type int    $redirection Redirects to follow (default 3).
	 *     @type string $channel     Log channel (default http).
	 *     @type bool   $log_errors  Log failures (default true).
	 *     @type string $resolve_to  IP to resolve the URL's host to (bypass CDN for self-audits).
	 *     @type bool   $sslverify   Verify TLS (default true).
	 *     @type int    $limit_response_size Max bytes to read.
	 * }
	 * @return HttpResponse
	 */
	public function request( $method, $url, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'headers'             => array(),
				'timeout'             => 20,
				'redirection'         => 3,
				'channel'             => 'http',
				'log_errors'          => true,
				'resolve_to'          => '',
				'sslverify'           => true,
				'limit_response_size' => null,
			)
		);

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}

		$wp_args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => (int) $args['timeout'],
			'redirection' => (int) $args['redirection'],
			'user-agent'  => self::user_agent(),
			'headers'     => $args['headers'],
			'sslverify'   => (bool) apply_filters( 'searchpilot_http_sslverify', (bool) $args['sslverify'], $url ),
		);
		if ( null !== $args['limit_response_size'] ) {
			$wp_args['limit_response_size'] = (int) $args['limit_response_size'];
		}
		if ( array_key_exists( 'json', $args ) ) {
			$wp_args['body']                    = wp_json_encode( $args['json'] );
			$wp_args['headers']['Content-Type'] = 'application/json; charset=utf-8';
		} elseif ( array_key_exists( 'body', $args ) ) {
			$wp_args['body'] = $args['body'];
		}
		// Google answers 411 to a body-less PUT/POST without Content-Length (e.g. sitemaps.submit);
		// the transports omit the header when the body is empty, so send it explicitly.
		if ( in_array( $wp_args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) && ( ! isset( $wp_args['body'] ) || '' === $wp_args['body'] || array() === $wp_args['body'] ) ) {
			$has_length = false;
			foreach ( array_keys( (array) $wp_args['headers'] ) as $name ) {
				if ( 'content-length' === strtolower( (string) $name ) ) {
					$has_length = true;
				}
			}
			if ( ! $has_length ) {
				$wp_args['body']                      = '';
				$wp_args['headers']['Content-Length'] = '0';
			}
		}

		$resolver = null;
		if ( '' !== (string) $args['resolve_to'] && filter_var( $args['resolve_to'], FILTER_VALIDATE_IP ) ) {
			$resolver = $this->add_resolver( $url, (string) $args['resolve_to'] );
		}

		$start = microtime( true );
		if ( null !== $this->transport ) {
			$raw = call_user_func( $this->transport, $url, $wp_args );
		} else {
			$raw = wp_remote_request( $url, $wp_args );
		}
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( $resolver ) {
			remove_action( 'http_api_curl', $resolver, 10 );
		}

		if ( is_wp_error( $raw ) ) {
			$response = new HttpResponse( 0, '', array(), $raw->get_error_message(), $duration, $url );
		} else {
			$headers = wp_remote_retrieve_headers( $raw );
			if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				$headers = $headers->getAll();
			}
			$response = new HttpResponse(
				(int) wp_remote_retrieve_response_code( $raw ),
				(string) wp_remote_retrieve_body( $raw ),
				is_array( $headers ) ? $headers : array(),
				'',
				$duration,
				$url
			);
		}

		if ( $args['log_errors'] && ! $response->ok() && ! ( $response->code >= 300 && $response->code < 400 ) ) {
			$this->logger->warning(
				$args['channel'],
				sprintf( '%s %s failed: %s', $wp_args['method'], self::loggable_url( $url ), $response->error_message() ),
				array(
					'status'      => $response->code,
					'duration_ms' => $duration,
				)
			);
		} else {
			$this->logger->debug(
				$args['channel'],
				sprintf( '%s %s → %d', $wp_args['method'], self::loggable_url( $url ), $response->code ),
				array( 'duration_ms' => $duration )
			);
		}

		return $response;
	}

	/**
	 * URL safe for logs (query secrets redacted).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function loggable_url( $url ) {
		return Logger::redact_string( (string) $url );
	}

	/**
	 * Adds a one-shot curl resolver so the request reaches the origin server
	 * directly (useful behind Cloudflare/CDNs for self-audits).
	 *
	 * @param string $url URL.
	 * @param string $ip  IP.
	 * @return callable
	 */
	private function add_resolver( $url, $ip ) {
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		$resolver = static function ( $handle, $parsed_args, $request_url ) use ( $url, $host, $ip ) {
			if ( $request_url !== $url || '' === $host || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return;
			}
			$addr = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $addr, $host . ':80:' . $addr ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		};
		add_action( 'http_api_curl', $resolver, 10, 3 );
		return $resolver;
	}
}
