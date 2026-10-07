<?php
/**
 * Plugin Name: SearchPilot mock search-engine APIs (development only)
 * Description: Answers Google OAuth/Search Console/URL Inspection/Indexing API, Bing Webmaster API and IndexNow requests locally with realistic synthetic data. Copy to wp-content/mu-plugins/ on a LOCAL development site and define SEARCHPILOT_MOCK_APIS as true. Never use on a live site.
 *
 * @package SearchPilot
 */

// phpcs:disable

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEARCHPILOT_MOCK_APIS' ) || ! SEARCHPILOT_MOCK_APIS ) {
	return;
}

/**
 * Deterministic pseudo-random number in [0, 1) for a seed string.
 *
 * @param string $seed Seed.
 * @return float
 */
function searchpilot_mock_rand( $seed ) {
	return hexdec( substr( md5( (string) $seed ), 0, 8 ) ) / 4294967296;
}

/**
 * JSON response in wp_remote_request() format.
 *
 * @param mixed $data   Data.
 * @param int   $status Status.
 * @return array
 */
function searchpilot_mock_json( $data, $status = 200 ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
		'body'     => null === $data ? '' : wp_json_encode( $data ),
		'response' => array(
			'code'    => $status,
			'message' => get_status_header_desc( $status ),
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Published content used to build synthetic performance rows.
 *
 * @return array[] [ [url, title], … ]
 */
function searchpilot_mock_pages() {
	static $pages = null;
	if ( null !== $pages ) {
		return $pages;
	}
	$pages = array( array( home_url( '/' ), get_bloginfo( 'name' ) ) );
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'numberposts' => 60, 'post_status' => 'publish' ) ) as $post ) {
		$pages[] = array( get_permalink( $post ), get_the_title( $post ) );
	}
	return $pages;
}

/**
 * Synthetic Search Analytics rows.
 *
 * @param array $body Request body.
 * @return array
 */
function searchpilot_mock_search_analytics( array $body ) {
	$dims  = (array) ( $body['dimensions'] ?? array() );
	$start = strtotime( $body['startDate'] ?? 'now -28 days' );
	$end   = strtotime( $body['endDate'] ?? 'now' );
	$rows  = array();
	$pages = searchpilot_mock_pages();

	if ( array( 'date' ) === $dims ) {
		for ( $t = $start; $t <= $end; $t += DAY_IN_SECONDS ) {
			$day         = gmdate( 'Y-m-d', $t );
			$trend       = 1 + ( $t - strtotime( '-16 months' ) ) / ( 480 * DAY_IN_SECONDS );
			$weekday     = in_array( (int) gmdate( 'N', $t ), array( 6, 7 ), true ) ? 0.8 : 1.0;
			$impressions = (int) round( 1800 * $trend * $weekday * ( 0.85 + 0.3 * searchpilot_mock_rand( 'i' . $day ) ) );
			$clicks      = (int) round( $impressions * ( 0.035 + 0.01 * searchpilot_mock_rand( 'c' . $day ) ) );
			$rows[]      = array(
				'keys'        => array( $day ),
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'ctr'         => $impressions ? $clicks / $impressions : 0,
				'position'    => 9 + 4 * searchpilot_mock_rand( 'p' . $day ),
			);
		}
		return $rows;
	}

	$days    = max( 1, (int) round( ( $end - $start ) / DAY_IN_SECONDS ) + 1 );
	$period  = gmdate( 'Ymd', $start );
	$queries = array();
	foreach ( $pages as $i => $page ) {
		$words = array_values( array_filter( preg_split( '/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower( $page[1] ) ) ) );
		if ( count( $words ) >= 2 ) {
			$queries[] = array( implode( ' ', array_slice( $words, 0, 2 ) ), $i );
			$queries[] = array( implode( ' ', array_slice( $words, 0, min( 4, count( $words ) ) ) ) . ' guide', $i );
		}
	}
	// A deliberate cannibalization pair.
	if ( count( $pages ) > 3 && $queries ) {
		$queries[] = array( $queries[0][0], 2 );
	}

	foreach ( $queries as $q ) {
		list( $query, $page_index ) = $q;
		$seed        = $query . '|' . $page_index;
		$position    = 1 + 29 * searchpilot_mock_rand( 'pos' . $seed . $period );
		$impressions = (int) round( $days * ( 5 + 60 * searchpilot_mock_rand( 'imp' . $seed ) ) * ( $position < 11 ? 1.4 : 0.8 ) );
		$expected    = $position < 2 ? 0.28 : ( $position < 4 ? 0.12 : ( $position < 11 ? 0.04 : 0.01 ) );
		$clicks      = (int) round( $impressions * $expected * ( 0.4 + searchpilot_mock_rand( 'ctr' . $seed . $period ) ) );
		$keys        = array();
		foreach ( $dims as $dim ) {
			$keys[] = 'query' === $dim ? $query : ( 'page' === $dim ? $pages[ $page_index ][0] : '' );
		}
		$key = implode( '|', $keys );
		if ( isset( $rows[ $key ] ) ) {
			$rows[ $key ]['clicks']      += $clicks;
			$rows[ $key ]['impressions'] += $impressions;
			continue;
		}
		$rows[ $key ] = array(
			'keys'        => $keys,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => 0,
			'position'    => round( $position, 2 ),
		);
	}
	$rows = array_values( $rows );
	foreach ( $rows as &$row ) {
		$row['ctr'] = $row['impressions'] ? $row['clicks'] / $row['impressions'] : 0;
	}
	unset( $row );
	usort( $rows, static function ( $a, $b ) {
		return $b['clicks'] <=> $a['clicks'];
	} );
	$start_row = (int) ( $body['startRow'] ?? 0 );
	$limit     = (int) ( $body['rowLimit'] ?? 1000 );
	return array_slice( $rows, $start_row, $limit );
}

/**
 * Synthetic URL Inspection result.
 *
 * @param string $url URL.
 * @return array
 */
function searchpilot_mock_inspection( $url ) {
	$r = searchpilot_mock_rand( 'insp' . $url );
	if ( $r < 0.72 ) {
		$coverage = 'Submitted and indexed';
		$verdict  = 'PASS';
	} elseif ( $r < 0.84 ) {
		$coverage = 'Crawled - currently not indexed';
		$verdict  = 'NEUTRAL';
	} elseif ( $r < 0.93 ) {
		$coverage = 'Discovered - currently not indexed';
		$verdict  = 'NEUTRAL';
	} else {
		$coverage = 'URL is unknown to Google';
		$verdict  = 'NEUTRAL';
	}
	return array(
		'inspectionResult' => array(
			'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=mock&id=' . rawurlencode( $url ),
			'indexStatusResult'    => array(
				'verdict'         => $verdict,
				'coverageState'   => $coverage,
				'robotsTxtState'  => 'ALLOWED',
				'indexingState'   => 'INDEXING_ALLOWED',
				'lastCrawlTime'   => 'PASS' === $verdict ? gmdate( 'Y-m-d\TH:i:s\Z', time() - (int) ( 86400 * 10 * $r ) ) : null,
				'pageFetchState'  => 'PASS' === $verdict ? 'SUCCESSFUL' : 'PAGE_FETCH_STATE_UNSPECIFIED',
				'googleCanonical' => 'PASS' === $verdict ? $url : '',
				'userCanonical'   => $url,
				'crawledAs'       => 'MOBILE',
				'sitemap'         => array( home_url( '/sitemap.xml' ) ),
				'referringUrls'   => array(),
			),
			'richResultsResult'    => array(
				'verdict'       => 'PASS',
				'detectedItems' => array(
					array(
						'richResultType' => 'Breadcrumbs',
						'items'          => array( array( 'name' => 'Unnamed item', 'issues' => array() ) ),
					),
				),
			),
		),
	);
}

/**
 * Bing Webmaster API method responses.
 *
 * @param string $method Method.
 * @return array
 */
function searchpilot_mock_bing( $method ) {
	$date = static function ( $ts ) {
		return '/Date(' . ( $ts * 1000 ) . '-0700)/';
	};
	switch ( $method ) {
		case 'GetUserSites':
			return array( array( 'Url' => home_url( '/' ), 'IsVerified' => true ) );
		case 'GetUrlSubmissionQuota':
			return array( 'DailyQuota' => 100, 'MonthlyQuota' => 3000 );
		case 'SubmitUrlBatch':
		case 'SubmitUrl':
		case 'SubmitFeed':
			return null;
		case 'GetFeeds':
			return array( array( 'Url' => home_url( '/sitemap.xml' ), 'Status' => 'Success', 'UrlCount' => 42, 'LastCrawled' => $date( time() - 86400 ), 'Submitted' => $date( time() - 30 * 86400 ) ) );
		case 'GetRankAndTrafficStats':
			$out = array();
			for ( $i = 180; $i >= 1; $i-- ) {
				$ts    = time() - $i * 86400;
				$out[] = array( 'Date' => $date( $ts ), 'Clicks' => (int) ( 30 + 20 * searchpilot_mock_rand( 'bc' . $i ) ), 'Impressions' => (int) ( 900 + 400 * searchpilot_mock_rand( 'bi' . $i ) ) );
			}
			return $out;
		case 'GetQueryStats':
		case 'GetPageStats':
			$out = array();
			foreach ( searchpilot_mock_pages() as $n => $page ) {
				for ( $w = 1; $w <= 12; $w++ ) {
					$out[] = array(
						'Query'                  => 'GetPageStats' === $method ? $page[0] : mb_strtolower( $page[1] ),
						'Date'                   => $date( time() - $w * 7 * 86400 ),
						'Clicks'                 => (int) ( 10 * searchpilot_mock_rand( 'bq' . $n . $w ) ),
						'Impressions'            => (int) ( 200 * searchpilot_mock_rand( 'bqi' . $n . $w ) ),
						'AvgClickPosition'       => round( 3 + 10 * searchpilot_mock_rand( 'bqp' . $n ), 1 ),
						'AvgImpressionPosition'  => round( 4 + 12 * searchpilot_mock_rand( 'bqp' . $n ), 1 ),
					);
				}
			}
			return $out;
		case 'GetCrawlStats':
			$out = array();
			for ( $i = 30; $i >= 1; $i-- ) {
				$out[] = array( 'Date' => $date( time() - $i * 86400 ), 'CrawledPages' => 120, 'CrawlErrors' => 2, 'InIndex' => 95, 'Code4xx' => 1, 'Code5xx' => 0, 'BlockedByRobotsTxt' => 0, 'InLinks' => 300 );
			}
			return $out;
	}
	return array();
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$method = strtoupper( $args['method'] ?? 'GET' );
		$body   = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : ( $args['body'] ?? array() );

		if ( 'oauth2.googleapis.com' === $host && '/token' === $path ) {
			return searchpilot_mock_json(
				array(
					'access_token'  => 'ya29.mock-' . wp_generate_password( 24, false ),
					'expires_in'    => 3599,
					'token_type'    => 'Bearer',
					'scope'         => 'https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/indexing',
					'refresh_token' => '1//mock-refresh-' . wp_generate_password( 20, false ),
				)
			);
		}
		if ( 'oauth2.googleapis.com' === $host && '/revoke' === $path ) {
			return searchpilot_mock_json( array() );
		}
		if ( 'www.googleapis.com' === $host || 'searchconsole.googleapis.com' === $host ) {
			if ( preg_match( '#/urlInspection/index:inspect$#', $path ) ) {
				return searchpilot_mock_json( searchpilot_mock_inspection( (string) ( $body['inspectionUrl'] ?? '' ) ) );
			}
			if ( preg_match( '#/webmasters/v3/sites/?$#', $path ) ) {
				$bare = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
				return searchpilot_mock_json(
					array(
						'siteEntry' => array(
							array( 'siteUrl' => 'sc-domain:' . $bare, 'permissionLevel' => 'siteOwner' ),
							array( 'siteUrl' => home_url( '/' ), 'permissionLevel' => 'siteFullUser' ),
						),
					)
				);
			}
			if ( preg_match( '#/searchAnalytics/query$#', $path ) ) {
				return searchpilot_mock_json( array( 'rows' => searchpilot_mock_search_analytics( (array) $body ), 'responseAggregationType' => 'byPage' ) );
			}
			if ( preg_match( '#/sitemaps/?$#', $path ) ) {
				return searchpilot_mock_json(
					array(
						'sitemap' => array(
							array(
								'path'            => home_url( '/sitemap.xml' ),
								'lastSubmitted'   => gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 ),
								'lastDownloaded'  => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 ),
								'isPending'       => false,
								'isSitemapsIndex' => true,
								'warnings'        => '0',
								'errors'          => '0',
								'contents'        => array( array( 'type' => 'web', 'submitted' => '42', 'indexed' => '0' ) ),
							),
						),
					)
				);
			}
			if ( preg_match( '#/sitemaps/.+#', $path ) ) {
				return searchpilot_mock_json( null, in_array( $method, array( 'PUT', 'DELETE' ), true ) ? 204 : 200 );
			}
		}
		if ( 'indexing.googleapis.com' === $host ) {
			return searchpilot_mock_json( array( 'urlNotificationMetadata' => array( 'url' => $body['url'] ?? '', 'latestUpdate' => array( 'url' => $body['url'] ?? '', 'type' => $body['type'] ?? 'URL_UPDATED', 'notifyTime' => gmdate( 'c' ) ) ) ) );
		}
		if ( 'ssl.bing.com' === $host && preg_match( '#/webmaster/api\.svc/json/(\w+)#', $path, $m ) ) {
			return searchpilot_mock_json( array( 'd' => searchpilot_mock_bing( $m[1] ) ) );
		}
		if ( preg_match( '#(^|\.)(indexnow\.org|bing\.com|yandex\.com|seznam\.cz|naver\.com|yep\.com)$#', $host ) && '/indexnow' === $path ) {
			return searchpilot_mock_json( null, 202 );
		}
		if ( in_array( $host, array( 'www.youtube.com', 'vimeo.com' ), true ) && false !== strpos( $path, 'oembed' ) ) {
			return searchpilot_mock_json( array( 'title' => 'Mock video', 'author_name' => 'Mock channel', 'thumbnail_url' => 'https://i.ytimg.com/vi/mock/hqdefault.jpg', 'duration' => 213 ) );
		}
		return $pre;
	},
	1,
	3
);

// Local testing only: let submissions run against the mocks on a local address.
add_filter(
	'searchpilot_submission_block_reason',
	static function ( $reason ) {
		return 'local' === $reason ? '' : $reason;
	}
);
