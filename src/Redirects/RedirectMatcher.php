<?php
/**
 * Pure redirect matching logic.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Redirects;

use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes sources, matches request paths against rules, resolves
 * targets and analyses rule graphs for chains and loops. No database
 * access: RedirectRepository loads rules, RedirectsModule applies them.
 *
 * Rule shape: [ id, source, match_type (exact|prefix|regex), target, status_code, enabled ].
 *
 * - exact:  path (and query, when the rule has one) compared case-insensitively,
 *           trailing slash ignored. A rule without a query also matches the
 *           path with any query string (the query is passed through).
 * - prefix: request path starts with the source; a `*` in the target is
 *           replaced by the rest of the path (/old/* → /new/*).
 * - regex:  PCRE pattern without delimiters, case-insensitive, matched against
 *           the path (and query when the pattern contains `\?`); `$1`…`$9`
 *           in the target are replaced by captures.
 */
final class RedirectMatcher {

	const CODES = array( 301, 302, 307, 308, 410, 451 );

	const MATCH_TYPES = array( 'exact', 'prefix', 'regex' );

	/**
	 * Most pattern (prefix/regex) rules evaluated for one request.
	 */
	const MAX_PATTERN_RULES = 200;

	/**
	 * Longest accepted regex pattern.
	 */
	const MAX_REGEX_LENGTH = 500;

	/**
	 * Normalized root-relative path (+ query) for storage: leading slash,
	 * fragment removed, non-ASCII percent-encoded, percent-escapes upper-cased.
	 *
	 * @param string $url URL or path.
	 * @return string '' when empty.
	 */
	public static function normalize_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = (string) preg_replace( '/#.*$/s', '', $url );
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			return Url::to_path( $url );
		}
		// A request URI may start with "//": it is a path, not a protocol-relative URL.
		if ( '/' !== substr( $url, 0, 1 ) ) {
			$url = '/' . $url;
		}
		$url = Url::encode_iri( $url );
		$url = (string) preg_replace( '#^/{2,}#', '/', $url );
		return self::upper_percent( $url );
	}

	/**
	 * Cleans user input that holds a path, URL or pattern (search boxes, sources,
	 * targets) WITHOUT destroying percent-encoded octets (%E0%B2%95…), which
	 * sanitize_text_field() would delete. Removes control characters and tags.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function clean_input( $value, $max = 2000 ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		$value = trim( (string) preg_replace( '#<[^>]*>#', '', $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * Matching key of a normalized path: lower-case (percent-escapes kept
	 * upper-case), duplicate slashes collapsed, trailing slash removed.
	 *
	 * @param string $path Normalized path (+ query).
	 * @return string
	 */
	public static function key( $path ) {
		list( $p, $q ) = self::split( self::normalize_path( $path ) );
		$p             = (string) preg_replace( '#/{2,}#', '/', $p );
		if ( strlen( $p ) > 1 ) {
			$p = rtrim( $p, '/' );
			$p = '' === $p ? '/' : $p;
		}
		$p = self::upper_percent( strtolower( $p ) );
		return $p . ( '' !== $q ? '?' . $q : '' );
	}

	/**
	 * SHA-1 of the matching key (stored in source_hash).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function hash( $path ) {
		return sha1( self::key( $path ) );
	}

	/**
	 * Splits "path?query".
	 *
	 * @param string $path Path.
	 * @return array{0:string,1:string}
	 */
	public static function split( $path ) {
		$parts = explode( '?', (string) $path, 2 );
		return array( '' === $parts[0] ? '/' : $parts[0], isset( $parts[1] ) ? $parts[1] : '' );
	}

	/**
	 * Hashes to look up for a request: path+query first, then the bare path.
	 *
	 * @param string $request_uri Request URI (path + query).
	 * @return array{full:string,path:string,query:string}
	 */
	public static function request_hashes( $request_uri ) {
		$normalized    = self::normalize_path( $request_uri );
		list( $p, $q ) = self::split( $normalized );
		return array(
			'full'  => '' !== $q ? self::hash( $p . '?' . $q ) : self::hash( $p ),
			'path'  => self::hash( $p ),
			'query' => $q,
		);
	}

	/**
	 * Compiles a user regex into a delimited, case-insensitive pattern.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return string '' when invalid.
	 */
	public static function compile( $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern || strlen( $pattern ) > self::MAX_REGEX_LENGTH ) {
			return '';
		}
		// Escape unescaped delimiters.
		$escaped = (string) preg_replace( '/(?<!\\\\)~/', '\\~', $pattern );
		$flags   = 'i';
		if ( preg_match( '/[^\x00-\x7F]/', $pattern ) ) {
			$flags .= 'u';
		}
		$regex = '~' . $escaped . '~' . $flags;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating user input.
		if ( false === @preg_match( $regex, '' ) ) {
			return '';
		}
		return $regex;
	}

	/**
	 * Validates and normalizes a rule for saving.
	 *
	 * @param array $rule {
	 *     @type string $source      Source.
	 *     @type string $match_type  exact|prefix|regex.
	 *     @type string $target      Target URL/path.
	 *     @type int    $status_code HTTP code.
	 * }
	 * @param array $opts {
	 *     @type bool     $allow_external External targets allowed (administrators).
	 *     @type string[] $excluded       Extra reserved paths.
	 * }
	 * @return array|\WP_Error Normalized rule (source, source_hash, match_type, target, status_code).
	 */
	public static function validate( array $rule, array $opts = array() ) {
		$type = (string) ( $rule['match_type'] ?? 'exact' );
		if ( ! in_array( $type, self::MATCH_TYPES, true ) ) {
			return new \WP_Error( 'searchpilot_invalid_match', __( 'Choose exact, prefix or regex matching.', 'searchpilot-pro' ) );
		}
		$code = (int) ( $rule['status_code'] ?? 301 );
		if ( ! in_array( $code, self::CODES, true ) ) {
			return new \WP_Error( 'searchpilot_invalid_code', __( 'Choose a redirect type: 301, 302, 307, 308, 410 or 451.', 'searchpilot-pro' ) );
		}
		$raw_source = trim( (string) ( $rule['source'] ?? '' ) );
		if ( '' === $raw_source ) {
			return new \WP_Error( 'searchpilot_invalid_source', __( 'Enter the old address (source).', 'searchpilot-pro' ) );
		}

		if ( 'regex' === $type ) {
			if ( '' === self::compile( $raw_source ) ) {
				return new \WP_Error( 'searchpilot_invalid_regex', __( 'The regular expression is not valid (or longer than 500 characters).', 'searchpilot-pro' ) );
			}
			$source = $raw_source;
			$hash   = '';
		} else {
			if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $raw_source ) && ! Url::is_internal( $raw_source ) ) {
				return new \WP_Error( 'searchpilot_external_source', __( 'The source must be an address on this site.', 'searchpilot-pro' ) );
			}
			$source = self::normalize_path( $raw_source );
			if ( 'prefix' === $type && '/' === $source ) {
				return new \WP_Error( 'searchpilot_prefix_root', __( 'A prefix rule for “/” would redirect the whole site.', 'searchpilot-pro' ) );
			}
			$hash = self::hash( $source );
		}
		if ( 'regex' !== $type && self::is_excluded( $source, (array) ( $opts['excluded'] ?? array() ), (string) ( $opts['home_path'] ?? '' ) ) ) {
			return new \WP_Error( 'searchpilot_reserved_source', __( 'WordPress admin, login, REST API, cron, sitemap and robots.txt addresses cannot be redirected.', 'searchpilot-pro' ) );
		}

		$target = trim( (string) ( $rule['target'] ?? '' ) );
		if ( in_array( $code, array( 410, 451 ), true ) ) {
			$target = '';
		} else {
			if ( '' === $target ) {
				return new \WP_Error( 'searchpilot_invalid_target', __( 'Enter the new address (target).', 'searchpilot-pro' ) );
			}
			$target = self::normalize_target( $target );
			if ( '' === $target ) {
				return new \WP_Error( 'searchpilot_invalid_target', __( 'The target must be a path on this site (/new-page) or an http(s) address.', 'searchpilot-pro' ) );
			}
			if ( self::is_external( $target ) && empty( $opts['allow_external'] ) ) {
				return new \WP_Error( 'searchpilot_external_target', __( 'Only administrators can create redirects to other websites.', 'searchpilot-pro' ) );
			}
			if ( 'regex' !== $type && ! self::is_external( $target ) && false === strpos( $target, '*' ) && self::key( $target ) === self::key( $source ) ) {
				return new \WP_Error( 'searchpilot_redirect_loop', __( 'The target is the same address as the source, which would create a redirect loop.', 'searchpilot-pro' ) );
			}
		}

		return array(
			'source'      => $source,
			'source_hash' => $hash,
			'match_type'  => $type,
			'target'      => $target,
			'status_code' => $code,
		);
	}

	/**
	 * Normalizes a target: internal URLs become root-relative paths, external
	 * URLs must be http(s).
	 *
	 * @param string $target Target.
	 * @return string '' when invalid.
	 */
	public static function normalize_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target || preg_match( '/[\x00-\x1F\x7F]/', $target ) ) {
			return '';
		}
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $target, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $target ) || 0 === strpos( $target, '//' ) ) {
			if ( 0 === strpos( $target, '//' ) ) {
				$target = 'https:' . $target;
			}
			if ( Url::is_internal( $target ) ) {
				// Keep an explicit fragment on internal targets.
				$fragment = '';
				if ( false !== strpos( $target, '#' ) ) {
					$fragment = '#' . substr( $target, strpos( $target, '#' ) + 1 );
				}
				return Url::to_path( $target ) . $fragment;
			}
			$host = wp_parse_url( $target, PHP_URL_HOST );
			return $host ? Url::encode_iri( $target ) : '';
		}
		$fragment = '';
		if ( false !== strpos( $target, '#' ) ) {
			$fragment = '#' . substr( $target, strpos( $target, '#' ) + 1 );
			$target   = substr( $target, 0, strpos( $target, '#' ) );
		}
		return self::normalize_path( '' === $target ? '/' : $target ) . Url::encode_iri( $fragment );
	}

	/**
	 * Whether a stored target points to another site.
	 *
	 * @param string $target Target.
	 * @return bool
	 */
	public static function is_external( $target ) {
		return (bool) preg_match( '#^https?://#i', (string) $target ) && ! Url::is_internal( $target );
	}

	/**
	 * Finds the rule that applies to a request path.
	 *
	 * Exact rules win over prefix rules, prefix over regex; within a type the
	 * longest prefix wins and regex rules keep their order.
	 *
	 * @param string $request Request path (+ query).
	 * @param array  $rules   Enabled rules.
	 * @return array|null [ rule, target (resolved, '' for 410/451), matched (exact|prefix|regex) ].
	 */
	public static function match( $request, array $rules ) {
		$normalized    = self::normalize_path( $request );
		list( $p, $q ) = self::split( $normalized );
		$full_key      = self::key( $normalized );
		$path_key      = self::key( $p );

		$exact_path = null;
		$prefix     = null;
		$prefix_len = -1;
		$regexes    = array();
		foreach ( $rules as $rule ) {
			if ( isset( $rule['enabled'] ) && ! $rule['enabled'] ) {
				continue;
			}
			$type = (string) ( $rule['match_type'] ?? 'exact' );
			if ( 'exact' === $type ) {
				$rule_key = self::key( (string) $rule['source'] );
				if ( $rule_key === $full_key && '' !== $q ) {
					return self::result( $rule, $normalized, array(), 'exact', '' );
				}
				if ( $rule_key === $path_key && null === $exact_path ) {
					$exact_path = $rule;
				}
			} elseif ( 'prefix' === $type ) {
				$source = strtolower( self::normalize_path( (string) $rule['source'] ) );
				$len    = strlen( $source );
				if ( $len > $prefix_len && 0 === strpos( strtolower( $p ), $source ) ) {
					$prefix     = $rule;
					$prefix_len = $len;
				}
			} elseif ( 'regex' === $type && count( $regexes ) < self::MAX_PATTERN_RULES ) {
				$regexes[] = $rule;
			}
		}
		if ( null !== $exact_path ) {
			return self::result( $exact_path, $normalized, array(), 'exact', $q );
		}
		if ( null !== $prefix ) {
			$source    = self::normalize_path( (string) $prefix['source'] );
			$remainder = (string) substr( $p, strlen( $source ) );
			return self::result( $prefix, $normalized, array( 'remainder' => ltrim( $remainder, '/' ) ), 'prefix', $q );
		}
		foreach ( $regexes as $rule ) {
			$regex = self::compile( (string) $rule['source'] );
			if ( '' === $regex ) {
				continue;
			}
			$unicode = 'u' === substr( $regex, -1 );
			$subject = false !== strpos( (string) $rule['source'], '\?' ) ? $normalized : $p;
			if ( $unicode ) {
				$subject = rawurldecode( $subject );
				if ( ! preg_match( '//u', $subject ) ) {
					continue;
				}
			}
			$m = array();
			if ( 1 === preg_match( $regex, $subject, $m ) ) {
				return self::result( $rule, $normalized, array( 'captures' => $m ), 'regex', '' );
			}
		}
		return null;
	}

	/**
	 * Builds a match result.
	 *
	 * @param array  $rule       Rule.
	 * @param string $request    Normalized request.
	 * @param array  $context    remainder / captures.
	 * @param string $matched    Match kind.
	 * @param string $pass_query Query string to pass through.
	 * @return array
	 */
	private static function result( array $rule, $request, array $context, $matched, $pass_query ) {
		return array(
			'rule'    => $rule,
			'target'  => self::resolve_target( $rule, $context, $pass_query ),
			'matched' => $matched,
			'request' => $request,
		);
	}

	/**
	 * Resolves a rule's target for a request.
	 *
	 * @param array  $rule       Rule.
	 * @param array  $context    remainder (prefix) / captures (regex).
	 * @param string $pass_query Query to append when the target has none.
	 * @return string
	 */
	public static function resolve_target( array $rule, array $context = array(), $pass_query = '' ) {
		if ( in_array( (int) ( $rule['status_code'] ?? 301 ), array( 410, 451 ), true ) ) {
			return '';
		}
		$target = (string) ( $rule['target'] ?? '' );
		if ( isset( $context['remainder'] ) && false !== strpos( $target, '*' ) ) {
			$target = str_replace( '*', (string) $context['remainder'], $target );
		}
		if ( ! empty( $context['captures'] ) ) {
			$captures = $context['captures'];
			$target   = (string) preg_replace_callback(
				'/\$(\d)/',
				static function ( $m ) use ( $captures ) {
					return isset( $captures[ (int) $m[1] ] ) ? (string) $captures[ (int) $m[1] ] : '';
				},
				$target
			);
			$target   = Url::encode_iri( $target );
		}
		if ( '/' === substr( (string) ( $rule['target'] ?? '' ), 0, 1 ) ) {
			// An internal target stays internal whatever the captures contain (no open redirects).
			$target = '/' . ltrim( $target, '/\\' );
		}
		if ( '' !== (string) $pass_query && false === strpos( $target, '?' ) && '' !== $target ) {
			$hash   = '';
			$pos    = strpos( $target, '#' );
			if ( false !== $pos ) {
				$hash   = substr( $target, $pos );
				$target = substr( $target, 0, $pos );
			}
			$target .= '?' . $pass_query . $hash;
		}
		return $target;
	}

	/**
	 * Whether a path must never be redirected (admin, login, REST, cron,
	 * AJAX, sitemaps, robots.txt, IndexNow key files).
	 *
	 * @param string   $path      Path (+ query), root-relative.
	 * @param string[] $extra     Additional reserved paths (exact, lower-case).
	 * @param string   $home_path Path of the home URL for subdirectory installs ('' or '/blog').
	 * @return bool
	 */
	public static function is_excluded( $path, array $extra = array(), $home_path = '' ) {
		list( $p, $q ) = self::split( strtolower( (string) $path ) );
		$home_path     = rtrim( strtolower( (string) $home_path ), '/' );
		if ( '' !== $home_path && 0 === strpos( $p, $home_path . '/' ) ) {
			$p = substr( $p, strlen( $home_path ) );
		}
		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php', '/wp-includes/', '/wp-signup.php', '/wp-activate.php' ) as $reserved ) {
			if ( 0 === strpos( $p, $reserved ) ) {
				return true;
			}
		}
		if ( in_array( $p, array( '/robots.txt', '/sitemap.xsl', '/sitemap_index.xml', '/sitemaps.xml', '/news-sitemap.xml', '/ads.txt', '/app-ads.txt' ), true ) ) {
			return true;
		}
		if ( preg_match( '#^/(wp-)?sitemap[a-z0-9_\-]*\.(xml|xsl)$#', $p ) || preg_match( '#^/[a-z0-9_\-]*sitemap[a-z0-9_\-]*\.xml$#', $p ) ) {
			return true;
		}
		if ( false !== strpos( $q, 'rest_route=' ) || false !== strpos( $q, 'spp_sitemap=' ) ) {
			return true;
		}
		foreach ( $extra as $reserved ) {
			$reserved = strtolower( (string) $reserved );
			if ( '' !== $reserved && $p === $reserved ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path matches one of the wildcard lines (`*` = anything).
	 *
	 * @param string $path     Path (+ query).
	 * @param string $patterns Newline-separated patterns (`#` comments allowed).
	 * @return bool
	 */
	public static function wildcard_match( $path, $patterns ) {
		$path          = (string) $path;
		list( $bare, ) = self::split( $path );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $patterns ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $line, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) || preg_match( $regex, $bare ) || preg_match( $regex, rawurldecode( $bare ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Finds loops and chains among enabled exact internal 3xx rules.
	 *
	 * @param array $rules Rules (id, source, target, match_type, status_code, enabled).
	 * @param int   $max_hops Safety limit.
	 * @return array{loops:array<int,int[]>,chains:array<int,array{ids:int[],final:string}>}
	 */
	public static function analyze( array $rules, $max_hops = 20 ) {
		$by_key = array();
		foreach ( $rules as $rule ) {
			if ( ! self::is_graph_rule( $rule ) ) {
				continue;
			}
			$key = self::key( (string) $rule['source'] );
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = $rule;
			}
		}
		$loops  = array();
		$chains = array();
		$seen   = array();
		foreach ( $by_key as $start_key => $rule ) {
			$visited = array( $start_key => 0 );
			$ids     = array( (int) $rule['id'] );
			$current = $rule;
			$loop    = false;
			for ( $hop = 0; $hop < $max_hops; $hop++ ) {
				$next_key = self::key( self::target_path( (string) $current['target'] ) );
				if ( isset( $visited[ $next_key ] ) ) {
					$loop = true;
					// Only the part of the walk that cycles.
					$ids = array_slice( $ids, $visited[ $next_key ] );
					break;
				}
				if ( ! isset( $by_key[ $next_key ] ) ) {
					break;
				}
				$current              = $by_key[ $next_key ];
				$visited[ $next_key ] = count( $ids );
				$ids[]                = (int) $current['id'];
			}
			if ( $loop ) {
				$sorted = $ids;
				sort( $sorted );
				$sig = implode( ',', $sorted );
				if ( ! isset( $seen[ $sig ] ) ) {
					$seen[ $sig ] = true;
					$loops[]      = $ids;
				}
				continue;
			}
			if ( count( $ids ) >= 2 ) {
				$chains[] = array(
					'ids'   => $ids,
					'final' => (string) $current['target'],
				);
			}
		}
		// Rules that are part of a loop are reported as loops only.
		if ( $loops ) {
			$in_loop = array();
			foreach ( $loops as $loop_ids ) {
				foreach ( $loop_ids as $id ) {
					$in_loop[ $id ] = true;
				}
			}
			$chains = array_values(
				array_filter(
					$chains,
					static function ( $chain ) use ( $in_loop ) {
						return ! isset( $in_loop[ $chain['ids'][0] ] );
					}
				)
			);
		}
		return array(
			'loops'  => $loops,
			'chains' => $chains,
		);
	}

	/**
	 * Whether a rule takes part in chain/loop analysis.
	 *
	 * @param array $rule Rule.
	 * @return bool
	 */
	private static function is_graph_rule( array $rule ) {
		if ( isset( $rule['enabled'] ) && ! $rule['enabled'] ) {
			return false;
		}
		if ( 'exact' !== ( $rule['match_type'] ?? 'exact' ) ) {
			return false;
		}
		$code = (int) ( $rule['status_code'] ?? 301 );
		if ( $code < 300 || $code > 399 ) {
			return false;
		}
		$target = (string) ( $rule['target'] ?? '' );
		return '' !== $target && ! self::is_external( $target ) && false === strpos( $target, '*' );
	}

	/**
	 * Path part of an internal target (absolute internal URLs are reduced).
	 *
	 * @param string $target Target.
	 * @return string
	 */
	public static function target_path( $target ) {
		$target = (string) preg_replace( '/#.*$/s', '', (string) $target );
		if ( preg_match( '#^https?://#i', $target ) ) {
			return Url::to_path( $target );
		}
		return self::normalize_path( $target );
	}

	/**
	 * Upper-cases percent-escapes.
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
}
