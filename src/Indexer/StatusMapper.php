<?php
/**
 * Maps Search Console URL Inspection results to registry index statuses.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapper: no WordPress calls, no state. Coverage texts are compared
 * after normalizing case, curly quotes, dashes and whitespace, so
 * "Excluded by ‘noindex’ tag" and "excluded by 'noindex' tag" match.
 */
final class StatusMapper {

	/**
	 * Exact coverage texts (normalized) => index status.
	 */
	const EXACT = array(
		'submitted and indexed'                                   => 'indexed',
		'indexed, not submitted in sitemap'                       => 'indexed',
		'indexed, though blocked by robots.txt'                   => 'indexed',
		'crawled - currently not indexed'                         => 'crawled_not_indexed',
		'discovered - currently not indexed'                      => 'discovered_not_indexed',
		"excluded by 'noindex' tag"                               => 'excluded_noindex',
		"submitted url marked 'noindex'"                          => 'excluded_noindex',
		'blocked by robots.txt'                                   => 'blocked_robots',
		'submitted url blocked by robots.txt'                     => 'blocked_robots',
		'duplicate, google chose different canonical than user'   => 'duplicate_canonical',
		'duplicate without user-selected canonical'               => 'duplicate_canonical',
		'duplicate, submitted url not selected as canonical'      => 'duplicate_canonical',
		'alternate page with proper canonical tag'                => 'alternate_canonical',
		'page with redirect'                                      => 'redirect',
		'redirect error'                                          => 'redirect',
		'not found (404)'                                         => 'not_found',
		'submitted url not found (404)'                           => 'not_found',
		'soft 404'                                                => 'soft_404',
		'submitted url seems to be a soft 404'                    => 'soft_404',
		'server error (5xx)'                                      => 'server_error',
		'submitted url returns server error (5xx)'                => 'server_error',
		'url is unknown to google'                                => 'unknown_to_google',
	);

	/**
	 * Fallback patterns (normalized substring => status), checked in order.
	 * More specific phrases come first ("not indexed" contains "indexed").
	 */
	const PATTERNS = array(
		'though blocked by robots'  => 'indexed',
		'crawled - currently not'   => 'crawled_not_indexed',
		'discovered - currently not' => 'discovered_not_indexed',
		'noindex'                   => 'excluded_noindex',
		'blocked by robots'         => 'blocked_robots',
		'alternate page'            => 'alternate_canonical',
		'duplicate'                 => 'duplicate_canonical',
		'redirect'                  => 'redirect',
		'soft 404'                  => 'soft_404',
		'(404)'                     => 'not_found',
		'not found'                 => 'not_found',
		'(5xx)'                     => 'server_error',
		'server error'              => 'server_error',
		'unknown to google'         => 'unknown_to_google',
	);

	/**
	 * Maps a normalized inspection result (see Api\Google\SearchConsole::inspect_url()).
	 *
	 * @param array $result Normalized result: verdict, coverage_state, indexing_state,
	 *                      robots_txt_state, last_crawl_time, google_canonical, user_canonical.
	 * @return array{index_status:string,coverage_state:string,verdict:string,warning:string,google_canonical:string,user_canonical:string,last_crawled:string|null}
	 */
	public static function map( array $result ) {
		$coverage = trim( (string) ( $result['coverage_state'] ?? '' ) );
		$verdict  = strtoupper( trim( (string) ( $result['verdict'] ?? '' ) ) );
		$state    = strtoupper( trim( (string) ( $result['indexing_state'] ?? '' ) ) );
		$status   = self::status_for_coverage( $coverage );
		$warning  = '';

		if ( 'indexed' === $status && false !== strpos( self::normalize( $coverage ), 'blocked by robots' ) ) {
			$warning = 'indexed_blocked_robots';
		}

		if ( '' === $status ) {
			if ( 'PASS' === $verdict ) {
				$status = 'indexed';
			} elseif ( in_array( $state, array( 'BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER' ), true ) ) {
				$status = 'excluded_noindex';
			} elseif ( 'BLOCKED_BY_ROBOTS_TXT' === $state ) {
				$status = 'blocked_robots';
			} else {
				$status = 'not_indexed';
			}
		}

		return array(
			'index_status'     => $status,
			'coverage_state'   => self::truncate( $coverage, 191 ),
			'verdict'          => self::truncate( $verdict, 20 ),
			'warning'          => $warning,
			'google_canonical' => trim( (string) ( $result['google_canonical'] ?? '' ) ),
			'user_canonical'   => trim( (string) ( $result['user_canonical'] ?? '' ) ),
			'last_crawled'     => self::mysql_time( $result['last_crawl_time'] ?? null ),
		);
	}

	/**
	 * Index status for a coverage text ('' when not recognized).
	 *
	 * @param string $coverage Coverage state text.
	 * @return string
	 */
	public static function status_for_coverage( $coverage ) {
		$text = self::normalize( $coverage );
		if ( '' === $text ) {
			return '';
		}
		if ( isset( self::EXACT[ $text ] ) ) {
			return self::EXACT[ $text ];
		}
		foreach ( self::PATTERNS as $needle => $status ) {
			if ( false !== strpos( $text, $needle ) ) {
				return $status;
			}
		}
		return '';
	}

	/**
	 * Normalizes a coverage text: lower case, straight quotes, ASCII dashes, single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		$text = str_replace(
			array( "\u{2018}", "\u{2019}", "\u{201A}", "\u{2032}", "\u{00B4}", '`', "\u{201C}", "\u{201D}", "\u{201E}", "\u{2033}", '"' ),
			array( "'", "'", "'", "'", "'", "'", "'", "'", "'", "'", "'" ),
			$text
		);
		$text = str_replace( array( "\u{2013}", "\u{2014}", "\u{2012}", "\u{2010}", "\u{2011}", "\u{2212}" ), '-', $text );
		$text = str_replace( "\u{00A0}", ' ', $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		// "Crawled – currently" and "Crawled-currently" both become "crawled - currently".
		$text = (string) preg_replace( '/\s*-\s*(currently)/u', ' - $1', $text );
		return trim( rtrim( trim( $text ), '.' ) );
	}

	/**
	 * Whether an index status counts as "in Google's index".
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_indexed( $status ) {
		return 'indexed' === $status;
	}

	/**
	 * ISO 8601 → MySQL UTC datetime (null when empty/invalid).
	 *
	 * @param mixed $iso ISO time.
	 * @return string|null
	 */
	public static function mysql_time( $iso ) {
		if ( ! is_string( $iso ) || '' === trim( $iso ) ) {
			return null;
		}
		$ts = strtotime( $iso );
		if ( false === $ts || $ts <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function truncate( $text, $length ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
