<?php
/**
 * robots.txt parser (RFC 9309 semantics, as used by Google and Bing).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * - Groups start with one or more user-agent lines; a crawler uses the group
 *   with the most specific matching user-agent token, else the `*` group.
 * - Within a group the longest matching Allow/Disallow path wins; on a tie
 *   Allow wins. `*` matches any sequence and a trailing `$` anchors the end.
 * - An empty Disallow allows everything. /robots.txt is always allowed.
 */
final class RobotsParser {

	/**
	 * Groups: array of [ 'agents' => string[], 'rules' => [ [type, path] ] ].
	 *
	 * @var array
	 */
	private $groups = array();

	/**
	 * Sitemap URLs.
	 *
	 * @var string[]
	 */
	private $sitemaps = array();

	/**
	 * Parses robots.txt content.
	 *
	 * @param string $content Content.
	 * @return RobotsParser
	 */
	public static function parse( $content ) {
		$parser = new self();
		$parser->load( (string) $content );
		return $parser;
	}

	/**
	 * Loads content.
	 *
	 * @param string $content Content.
	 */
	private function load( $content ) {
		// Strip a UTF-8 BOM.
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		}
		$current      = null;
		$last_was_ua  = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = explode( ':', $line, 2 );
			$field                 = strtolower( trim( $field ) );
			$value                 = trim( $value );

			if ( 'user-agent' === $field ) {
				if ( ! $last_was_ua || null === $current ) {
					$this->groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current        = count( $this->groups ) - 1;
				}
				$this->groups[ $current ]['agents'][] = strtolower( $value );
				$last_was_ua                          = true;
				continue;
			}
			$last_was_ua = false;
			if ( 'sitemap' === $field ) {
				$this->sitemaps[] = $value;
				continue;
			}
			if ( null === $current ) {
				continue; // Rules before any user-agent are ignored.
			}
			if ( 'allow' === $field || 'disallow' === $field ) {
				$this->groups[ $current ]['rules'][] = array( $field, $value );
			}
		}
	}

	/**
	 * Sitemap URLs declared in the file.
	 *
	 * @return string[]
	 */
	public function sitemaps() {
		return $this->sitemaps;
	}

	/**
	 * Rules applying to a crawler (merged groups of its most specific agent token).
	 *
	 * @param string $user_agent Crawler token, e.g. Googlebot, bingbot.
	 * @return array<int,array{0:string,1:string}>
	 */
	public function rules_for( $user_agent ) {
		$agent    = strtolower( $user_agent );
		$best_len = -1;
		$rules    = array();
		$star     = array();
		foreach ( $this->groups as $group ) {
			foreach ( $group['agents'] as $token ) {
				if ( '*' === $token ) {
					$star = array_merge( $star, $group['rules'] );
					continue;
				}
				if ( '' !== $token && 0 === strpos( $agent, $token ) ) {
					$len = strlen( $token );
					if ( $len > $best_len ) {
						$best_len = $len;
						$rules    = $group['rules'];
					} elseif ( $len === $best_len ) {
						$rules = array_merge( $rules, $group['rules'] );
					}
				}
			}
		}
		return $best_len >= 0 ? $rules : $star;
	}

	/**
	 * Whether a URL (or path) may be crawled.
	 *
	 * @param string $url        Absolute URL or path (with query).
	 * @param string $user_agent Crawler token.
	 * @return bool
	 */
	public function is_allowed( $url, $user_agent = 'Googlebot' ) {
		$path = self::path_of( $url );
		if ( '/robots.txt' === strtolower( $path ) ) {
			return true;
		}
		$best_len = -1;
		$allowed  = true;
		foreach ( $this->rules_for( $user_agent ) as $rule ) {
			list( $type, $pattern ) = $rule;
			if ( '' === $pattern ) {
				continue; // "Disallow:" with no value allows everything.
			}
			if ( ! self::matches( $pattern, $path ) ) {
				continue;
			}
			$len = strlen( $pattern );
			if ( $len > $best_len || ( $len === $best_len && 'allow' === $type ) ) {
				$best_len = $len;
				$allowed  = 'allow' === $type;
			}
		}
		return $allowed;
	}

	/**
	 * The rule deciding a URL, for explanations: [type, pattern] or null.
	 *
	 * @param string $url        URL or path.
	 * @param string $user_agent Crawler token.
	 * @return array|null
	 */
	public function matching_rule( $url, $user_agent = 'Googlebot' ) {
		$path     = self::path_of( $url );
		$best     = null;
		$best_len = -1;
		foreach ( $this->rules_for( $user_agent ) as $rule ) {
			if ( '' === $rule[1] || ! self::matches( $rule[1], $path ) ) {
				continue;
			}
			$len = strlen( $rule[1] );
			if ( $len > $best_len || ( $len === $best_len && 'allow' === $rule[0] ) ) {
				$best_len = $len;
				$best     = $rule;
			}
		}
		return $best;
	}

	/**
	 * Whether a rule pattern matches a path.
	 *
	 * @param string $pattern Pattern.
	 * @param string $path    Path.
	 * @return bool
	 */
	public static function matches( $pattern, $path ) {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '';
		foreach ( explode( '*', $pattern ) as $i => $part ) {
			$regex .= ( $i ? '.*' : '' ) . preg_quote( self::normalize_encoding( $part ), '#' );
		}
		return 1 === preg_match( '#^' . $regex . ( $anchored ? '$' : '' ) . '#', self::normalize_encoding( $path ) );
	}

	/**
	 * Path + query of a URL.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private static function path_of( $url ) {
		$url = (string) $url;
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper usable without WordPress.
			$url   = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}
		return '' === $url ? '/' : $url;
	}

	/**
	 * Upper-cases percent-encoding and encodes raw UTF-8 so both sides compare equally.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize_encoding( $value ) {
		$value = (string) preg_replace_callback(
			'/[^\x21-\x7E]/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			$value
		);
		return (string) preg_replace_callback(
			'/%[0-9a-fA-F]{2}/',
			static function ( $m ) {
				return strtoupper( $m[0] );
			},
			$value
		);
	}
}
