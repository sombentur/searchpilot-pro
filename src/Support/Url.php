<?php
/**
 * URL helpers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * URL normalization used everywhere URLs are compared or hashed, so a URL
 * from WordPress, Search Console, Bing and a crawl all map to one registry
 * row. Percent-encoding is upper-cased (WordPress stores non-Latin slugs
 * lower-case, Google reports them upper-case) and raw UTF-8 is encoded.
 */
final class Url {

	/**
	 * Tracking parameters removed during normalization.
	 */
	const TRACKING = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'fbclid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_ga', 'yclid' );

	/**
	 * Normalizes a URL for comparison and hashing.
	 *
	 * @param string $url URL (absolute or root-relative).
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = self::absolute( $url );
		$url = self::encode_iri( $url );

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path = $parts['path'] ?? '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$path = self::upper_percent( $path );
		// Collapse duplicate slashes in the path.
		$path = (string) preg_replace( '#/{2,}#', '/', $path );

		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$pairs = array();
			foreach ( explode( '&', $parts['query'] ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );
				if ( in_array( $name, self::TRACKING, true ) ) {
					continue;
				}
				$pairs[] = self::upper_percent( $pair );
			}
			$query = implode( '&', $pairs );
		}

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * SHA-1 of the normalized URL (40 hex chars).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		return sha1( self::normalize( $url ) );
	}

	/**
	 * Makes a root-relative or protocol-relative URL absolute (site home).
	 *
	 * @param string $url  URL.
	 * @param string $base Base URL (default home_url()).
	 * @return string
	 */
	public static function absolute( $url, $base = '' ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			return $url;
		}
		$base = '' !== $base ? $base : home_url( '/' );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https' ) . ':' . $url;
		}
		$origin = self::origin( $base );
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		if ( 0 === strpos( $url, '?' ) || 0 === strpos( $url, '#' ) ) {
			$clean = preg_replace( '/[?#].*$/', '', $base );
			return $clean . $url;
		}
		// Relative path: resolve against the base directory.
		$path = (string) wp_parse_url( $base, PHP_URL_PATH );
		$dir  = '' === $path ? '/' : ( '/' === substr( $path, -1 ) ? $path : dirname( $path ) . '/' );
		$dir  = str_replace( '\\', '/', $dir );
		return $origin . self::remove_dot_segments( $dir . $url );
	}

	/**
	 * scheme://host[:port] of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return untrailingslashit( home_url() );
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Whether a URL belongs to this site (www and non-www treated alike).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '#^(mailto|tel|javascript|data|sms|ftp):#i', $url ) ) {
			return false;
		}
		if ( 0 === strpos( $url, '#' ) ) {
			return false;
		}
		$parts = wp_parse_url( self::absolute( $url ) );
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		if ( self::bare_host( $parts['host'] ) !== self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return false;
		}
		// Same host on another port is a different service (never treat it as this site:
		// it would let crafted canonicals make self-audits fetch arbitrary local ports).
		// http:// and https:// links on the default ports are the same site.
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$home_port = (int) wp_parse_url( home_url(), PHP_URL_PORT );
		return 0 === $port || in_array( $port, array( 80, 443 ), true ) && 0 === $home_port || $port === $home_port;
	}

	/**
	 * Host without a leading www.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function bare_host( $host ) {
		$host = strtolower( (string) $host );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Path + query relative to the site root (for redirects), always starting with '/'.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function to_path( $url ) {
		$url   = self::encode_iri( self::absolute( $url ) );
		$parts = wp_parse_url( $url );
		$path  = $parts['path'] ?? '/';
		// The full path is kept, so subdirectory installs (/blog/foo) keep working.
		$path = '/' . ltrim( self::upper_percent( $path ), '/' );
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path .= '?' . self::upper_percent( $parts['query'] );
		}
		return $path;
	}

	/**
	 * Strips the query string and fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function strip_query( $url ) {
		return (string) preg_replace( '/[?#].*$/', '', (string) $url );
	}

	/**
	 * Percent-encodes characters outside printable ASCII (IRI → URI).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function encode_iri( $url ) {
		return (string) preg_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			(string) $url
		);
	}

	/**
	 * Decodes percent-encoding for display (keeps it valid UTF-8).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function display( $url ) {
		$decoded = rawurldecode( (string) $url );
		return mb_check_encoding( $decoded, 'UTF-8' ) ? $decoded : (string) $url;
	}

	/**
	 * Upper-cases percent-encoded octets.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function upper_percent( $value ) {
		return (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}/',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			(string) $value
		);
	}

	/**
	 * RFC 3986 dot-segment removal.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function remove_dot_segments( $path ) {
		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $out );
				continue;
			}
			$out[] = $segment;
		}
		$result = implode( '/', $out );
		return '/' === substr( $result, 0, 1 ) ? $result : '/' . $result;
	}
}
