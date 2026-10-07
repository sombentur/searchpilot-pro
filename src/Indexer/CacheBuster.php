<?php
/**
 * Cache-busting for re-scans.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a self-check bypass page caches and removes every trace of the
 * bypass from what was fetched.
 *
 * A re-scan must see the page as WordPress renders it now, not a copy a
 * page cache stored before a fix. The request gets a one-off query
 * argument `nocache=spp<random>`:
 *
 * - Vayu Speed treats a `nocache` parameter as "never cache this request"
 *   (it bypasses its disk cache and sends `X-LiteSpeed-Cache-Control: no-cache`,
 *   so the LiteSpeed server does not store the response either);
 * - the LiteSpeed server cache (and LiteSpeed Cache, WP Rocket, W3TC, WP Super
 *   Cache, Cloudflare) keys pages by their full URL: a query string that was
 *   never requested before cannot be served from cache;
 * - `Cache-Control: no-cache` and `Pragma: no-cache` request headers are sent
 *   too, for caches configured to honour them.
 *
 * WordPress ignores the unknown argument. Redirect targets (`Location`) and the
 * returned HTML may echo the requested URL (canonical redirects, share buttons,
 * JSON-LD), so the argument is stripped from every URL, header and the body
 * before the page is analysed — nothing SearchPilot stores contains it.
 * Pure: no WordPress calls, unit-tested.
 */
final class CacheBuster {

	/**
	 * Query argument name (Vayu Speed bypasses its cache for it).
	 */
	const PARAM = 'nocache';

	/**
	 * Token prefix (makes SearchPilot's requests recognisable in access logs).
	 */
	const PREFIX = 'spp';

	/**
	 * A new one-off token.
	 *
	 * @return string Lower-case letters and digits.
	 */
	public static function token() {
		try {
			$random = bin2hex( random_bytes( 6 ) );
		} catch ( \Exception $e ) {
			$random = substr( md5( uniqid( '', true ) ), 0, 12 );
		}
		return self::PREFIX . $random;
	}

	/**
	 * Whether a token is well-formed (only such tokens are ever added or stripped).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function valid( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-z0-9]{6,40}$/', $token );
	}

	/**
	 * URL with the cache-busting argument (before any #fragment).
	 *
	 * @param string $url   URL.
	 * @param string $token Token.
	 * @return string
	 */
	public static function add( $url, $token ) {
		$url = (string) $url;
		if ( ! self::valid( $token ) || '' === $url ) {
			return $url;
		}
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}
		if ( false === strpos( $url, '?' ) ) {
			$separator = '?';
		} else {
			$last      = substr( $url, -1 );
			$separator = ( '?' === $last || '&' === $last ) ? '' : '&';
		}
		return $url . $separator . self::PARAM . '=' . $token . $fragment;
	}

	/**
	 * Removes the cache-busting argument from a URL, a header value or a whole
	 * HTML document (plain, HTML-escaped `&amp;`/`&#038;` and percent-encoded forms).
	 *
	 * @param string $text  Text.
	 * @param string $token Token used for the request.
	 * @return string
	 */
	public static function strip( $text, $token ) {
		$text = (string) $text;
		if ( ! self::valid( $token ) || '' === $text || false === stripos( $text, $token ) ) {
			return $text;
		}
		$param = preg_quote( self::PARAM, '/' );
		$value = preg_quote( $token, '/' );
		$eq    = '(?:=|%3D|\\\\u003d)';
		// First argument followed by others: "?nocache=T&a=1" → "?a=1".
		$text = (string) preg_replace( '/\?' . $param . $eq . $value . '(?:&amp;|&#0?38;|\\\\u0026|&)/i', '?', $text );
		$text = (string) preg_replace( '/%3F' . $param . '%3D' . $value . '%26/i', '%3F', $text );
		// Any other position, together with its separator: "?a=1&nocache=T" → "?a=1", "/x/?nocache=T" → "/x/".
		$text = (string) preg_replace( '/(?:\?|&amp;|&#0?38;|\\\\u0026|&|%3F|%26)' . $param . $eq . $value . '/i', '', $text );
		// Leftovers without a recognisable separator.
		return (string) preg_replace( '/' . $param . $eq . $value . '/i', '', $text );
	}

	/**
	 * strip() applied to every string inside an array (headers, redirect hops).
	 *
	 * @param mixed  $value Value.
	 * @param string $token Token.
	 * @return mixed
	 */
	public static function strip_deep( $value, $token ) {
		if ( is_string( $value ) ) {
			return self::strip( $value, $token );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::strip_deep( $item, $token );
			}
		}
		return $value;
	}

	/**
	 * Request headers that ask caches for a fresh copy.
	 *
	 * @return array<string,string>
	 */
	public static function headers() {
		return array(
			'Cache-Control' => 'no-cache',
			'Pragma'        => 'no-cache',
		);
	}
}
