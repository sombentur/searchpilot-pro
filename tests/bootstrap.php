<?php
/**
 * Unit test bootstrap: loads the autoloader and stubs the handful of
 * WordPress functions the pure services use, so tests need no database.
 *
 * @package SearchPilot
 */

// phpcs:disable

if ( 'cli' !== PHP_SAPI ) {
	exit; // Tests never run over HTTP.
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SEARCHPILOT_VERSION' ) ) {
	define( 'SEARCHPILOT_VERSION', '1.0.0-test' );
	define( 'SEARCHPILOT_DIR', dirname( __DIR__ ) . '/' );
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800 ) as $c => $v ) {
	if ( ! defined( $c ) ) {
		define( $c, $v );
	}
}

$GLOBALS['spp_test_home'] = 'https://example.com/';
$GLOBALS['spp_test_filters'] = array();

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return rtrim( $GLOBALS['spp_test_home'], '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) {
		return rtrim( $s, '/\\' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $s ) {
		return rtrim( $s, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( isset( $GLOBALS['spp_test_filters'][ $hook ] ) ) {
			return call_user_func( $GLOBALS['spp_test_filters'][ $hook ], $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb ) {
		$GLOBALS['spp_test_filters'][ $hook ] = $cb;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return trim( strip_tags( $text ) );
	}
}
if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( $text ) {
		return mb_check_encoding( (string) $text, 'UTF-8' ) ? (string) $text : '';
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return 'en_US';
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( trim( (string) $url ), FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_xml' ) ) {
	function esc_xml( $t ) {
		return htmlspecialchars( (string) $t, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $t ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $t ) {
		return trim( strip_tags( (string) $t ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) {
		return $t;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = null ) {
		return esc_html( $t );
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $s, $p, $n, $d = null ) {
		return 1 === (int) $n ? $s : $p;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
				if ( '' !== $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}
		public function get_error_message( $code = '' ) {
			$code = $code ?: $this->get_error_code();
			return $this->errors[ $code ][0] ?? '';
		}
		public function get_error_data( $code = '' ) {
			$code = $code ?: $this->get_error_code();
			return $this->error_data[ $code ] ?? null;
		}
		public function add_data( $data, $code = '' ) {
			$code = $code ?: $this->get_error_code();
			$this->error_data[ $code ] = $data;
		}
	}
}

require_once dirname( __DIR__ ) . '/src/Autoloader.php';
\SearchPilot\Autoloader::register( 'SearchPilot\\', dirname( __DIR__ ) . '/src/' );
