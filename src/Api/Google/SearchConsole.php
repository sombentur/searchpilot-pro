<?php
/**
 * Google Search Console API client.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

use SearchPilot\Core\Http\HttpResponse;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Official Search Console API only: sites, Search Analytics, Sitemaps and
 * URL Inspection. Every call consumes RateLimiter budget
 * (google_analytics / google_inspection / google_sitemaps); 429 and quota
 * errors block the key and return WP_Error `searchpilot_quota`.
 */
class SearchConsole {

	const API = 'https://www.googleapis.com/webmasters/v3';

	const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	const MAX_ROWS_PER_CALL = 25000;

	const PERMISSION_RANK = array(
		'siteOwner'          => 4,
		'siteFullUser'       => 3,
		'siteRestrictedUser' => 2,
		'siteUnverifiedUser' => 0,
	);

	/**
	 * Auth.
	 *
	 * @var GoogleAuth
	 */
	private $auth;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param GoogleAuth  $auth     Auth.
	 * @param Settings    $settings Settings.
	 * @param RateLimiter $limiter  Rate limiter.
	 * @param Logger      $logger   Logger.
	 */
	public function __construct( GoogleAuth $auth, Settings $settings, RateLimiter $limiter, Logger $logger ) {
		$this->auth     = $auth;
		$this->settings = $settings;
		$this->limiter  = $limiter;
		$this->logger   = $logger;
	}

	/**
	 * Credentials present AND a property selected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->auth->is_configured() && '' !== $this->property();
	}

	/**
	 * Selected property (sc-domain:example.com or https://example.com/).
	 *
	 * @return string
	 */
	public function property() {
		return (string) $this->settings->get( 'engines.google_property', '' );
	}

	/**
	 * Selects a property.
	 *
	 * @param string $property Property.
	 * @return true|\WP_Error
	 */
	public function set_property( $property ) {
		$property = trim( (string) $property );
		if ( '' !== $property && ! self::valid_property( $property ) ) {
			return new \WP_Error( 'searchpilot_invalid_property', __( 'Choose a Search Console property from the list.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$result = $this->settings->update( array( 'engines.google_property' => $property ) );
		if ( ! empty( $result['errors'] ) ) {
			return new \WP_Error( 'searchpilot_invalid_property', implode( ' ', $result['errors'] ), array( 'status' => 400 ) );
		}
		if ( '' !== $property && $this->auth->is_configured() && 'error' !== $this->auth->connection()['status'] ) {
			$this->auth->record_success( true );
		}
		return true;
	}

	/**
	 * Whether a string is a Search Console property identifier.
	 *
	 * @param string $property Property.
	 * @return bool
	 */
	public static function valid_property( $property ) {
		$property = (string) $property;
		if ( 0 === strpos( $property, 'sc-domain:' ) ) {
			return (bool) preg_match( '/^sc-domain:[a-z0-9][a-z0-9.\-]*\.[a-z0-9\-]{2,}$/i', $property );
		}
		return (bool) preg_match( '#^https?://[^\s/?\#]+/#i', $property );
	}

	/**
	 * Properties the connected account can see.
	 *
	 * @return array|\WP_Error [ [ site_url, permission_level ] ]
	 */
	public function list_sites() {
		$json = $this->call( 'GET', self::API . '/sites', array(), 'google_analytics' );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return self::parse_sites( $json );
	}

	/**
	 * Parses a sites.list response.
	 *
	 * @param array $json Response.
	 * @return array
	 */
	public static function parse_sites( array $json ) {
		$out = array();
		foreach ( (array) ( $json['siteEntry'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['siteUrl'] ) ) {
				continue;
			}
			$out[] = array(
				'site_url'         => (string) $entry['siteUrl'],
				'permission_level' => (string) ( $entry['permissionLevel'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Picks the property that covers the site: the Domain property of the
	 * host (www-less), else a URL-prefix property with exactly the home URL's
	 * scheme and host whose path is a prefix of the home path (a URL-prefix
	 * property covers nothing else — http/https or www variants do not
	 * match), else a Domain property of a parent domain. Unverified entries
	 * are ignored; higher permission wins ties.
	 *
	 * @param array  $sites    list_sites() rows.
	 * @param string $home_url Site home URL.
	 * @return string '' when nothing matches.
	 */
	public static function best_property( array $sites, $home_url ) {
		$home      = Url::normalize( trailingslashit( (string) $home_url ) );
		$host      = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$bare      = Url::bare_host( $host );
		$origin    = Url::origin( $home );
		$home_path = (string) wp_parse_url( $home, PHP_URL_PATH );
		$best      = '';
		$score     = -1;

		foreach ( $sites as $site ) {
			$url   = (string) ( $site['site_url'] ?? '' );
			$level = (string) ( $site['permission_level'] ?? '' );
			$rank  = self::PERMISSION_RANK[ $level ] ?? 1;
			if ( '' === $url || 'siteUnverifiedUser' === $level ) {
				continue;
			}
			$match = 0;
			if ( 0 === strpos( $url, 'sc-domain:' ) ) {
				$domain = strtolower( substr( $url, 10 ) );
				if ( '' === $domain ) {
					continue;
				}
				if ( $domain === $bare || $domain === $host ) {
					$match = 400;
				} elseif ( substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					$match = 100 + strlen( $domain );
				}
			} else {
				$prefix      = Url::normalize( trailingslashit( $url ) );
				$prefix_path = (string) wp_parse_url( $prefix, PHP_URL_PATH );
				if ( Url::origin( $prefix ) !== $origin || 0 !== strpos( $home_path, $prefix_path ) ) {
					continue;
				}
				// The exact home URL first; otherwise the longest covering path prefix.
				$match = $prefix === $home ? 300 : 200 + min( 60, strlen( $prefix_path ) );
			}
			if ( ! $match ) {
				continue;
			}
			$total = $match * 10 + $rank;
			if ( $total > $score ) {
				$score = $total;
				$best  = $url;
			}
		}
		return $best;
	}

	/**
	 * Selects the best matching property automatically.
	 *
	 * @return string Selected property ('' when none matched).
	 */
	public function auto_select_property() {
		$sites = $this->list_sites();
		if ( is_wp_error( $sites ) ) {
			$this->logger->warning( 'google', 'Could not list Search Console properties: ' . $sites->get_error_message() );
			return '';
		}
		$best = self::best_property( $sites, home_url( '/' ) );
		if ( '' !== $best ) {
			$this->set_property( $best );
			$this->logger->info( 'google', 'Search Console property selected automatically: ' . $best );
		}
		return $best;
	}

	/**
	 * Search Analytics query with paging (startRow) up to $max_rows.
	 *
	 * @param array $body     API body (startDate, endDate, dimensions, type, dataState, dimensionFilterGroups, aggregationType).
	 * @param int   $max_rows Maximum rows to fetch in total.
	 * @return array|\WP_Error Rows [ keys, clicks, impressions, ctr, position ].
	 */
	public function search_analytics( array $body, $max_rows = 25000 ) {
		$property = $this->property();
		if ( '' === $property ) {
			return $this->not_connected();
		}
		foreach ( array( 'startDate', 'endDate' ) as $date ) {
			if ( empty( $body[ $date ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $body[ $date ] ) ) {
				return new \WP_Error( 'searchpilot_invalid_range', __( 'Search Analytics needs a start and end date (YYYY-MM-DD).', 'searchpilot-pro' ) );
			}
		}
		if ( empty( $body['type'] ) && empty( $body['searchType'] ) ) {
			$body['type'] = 'web';
		}
		$max_rows = max( 1, (int) $max_rows );
		$url      = self::API . '/sites/' . self::encode_site( $property ) . '/searchAnalytics/query';
		$rows     = array();
		$start    = 0;
		do {
			$limit            = min( self::MAX_ROWS_PER_CALL, $max_rows - count( $rows ) );
			$body['rowLimit'] = $limit;
			$body['startRow'] = $start;
			$json             = $this->call( 'POST', $url, array( 'json' => $body ), 'google_analytics' );
			if ( is_wp_error( $json ) ) {
				// Keep what was fetched when a later page fails, unless nothing was.
				return $rows ? $rows : $json;
			}
			$page   = self::normalize_rows( (array) ( $json['rows'] ?? array() ) );
			$rows   = array_merge( $rows, $page );
			$start += count( $page );
		} while ( count( $page ) === $limit && count( $rows ) < $max_rows );
		return $rows;
	}

	/**
	 * Normalizes Search Analytics rows.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	public static function normalize_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'keys'        => array_map( 'strval', (array) ( $row['keys'] ?? array() ) ),
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				'ctr'         => max( 0.0, min( 1.0, (float) ( $row['ctr'] ?? 0 ) ) ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * URL Inspection (one URL).
	 *
	 * @param string $url URL of this site.
	 * @return array|\WP_Error Normalized result (see parse_inspection()).
	 */
	public function inspect_url( $url ) {
		$property = $this->property();
		if ( '' === $property ) {
			return $this->not_connected();
		}
		$locale = str_replace( '_', '-', (string) get_locale() );
		$json   = $this->call(
			'POST',
			self::INSPECT,
			array(
				'json' => array(
					'inspectionUrl' => Url::normalize( $url ),
					'siteUrl'       => $property,
					'languageCode'  => '' !== $locale ? $locale : 'en-US',
				),
			),
			'google_inspection'
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return self::parse_inspection( $json );
	}

	/**
	 * Normalizes a urlInspection.index:inspect response.
	 *
	 * @param array $json Response.
	 * @return array
	 */
	public static function parse_inspection( array $json ) {
		$result = (array) ( $json['inspectionResult'] ?? array() );
		$index  = (array) ( $result['indexStatusResult'] ?? array() );
		$rich   = (array) ( $result['richResultsResult'] ?? array() );

		$items = array();
		foreach ( (array) ( $rich['detectedItems'] ?? array() ) as $detected ) {
			if ( ! is_array( $detected ) ) {
				continue;
			}
			$issues = array();
			foreach ( (array) ( $detected['items'] ?? array() ) as $item ) {
				foreach ( (array) ( $item['issues'] ?? array() ) as $issue ) {
					if ( ! is_array( $issue ) ) {
						continue;
					}
					$issues[] = array(
						'message'  => (string) ( $issue['issueMessage'] ?? '' ),
						'severity' => (string) ( $issue['severity'] ?? '' ),
					);
				}
			}
			$items[] = array(
				'type'   => (string) ( $detected['richResultType'] ?? '' ),
				'issues' => $issues,
			);
		}

		$verdict = (string) ( $index['verdict'] ?? 'VERDICT_UNSPECIFIED' );
		if ( ! in_array( $verdict, array( 'PASS', 'PARTIAL', 'FAIL', 'NEUTRAL', 'VERDICT_UNSPECIFIED' ), true ) ) {
			$verdict = 'VERDICT_UNSPECIFIED';
		}
		$crawl = (string) ( $index['lastCrawlTime'] ?? '' );
		if ( '' !== $crawl ) {
			$ts    = strtotime( $crawl );
			$crawl = $ts ? gmdate( 'c', $ts ) : '';
		}

		return array(
			'verdict'          => $verdict,
			'coverage_state'   => (string) ( $index['coverageState'] ?? '' ),
			'indexing_state'   => (string) ( $index['indexingState'] ?? '' ),
			'robots_txt_state' => (string) ( $index['robotsTxtState'] ?? '' ),
			'page_fetch_state' => (string) ( $index['pageFetchState'] ?? '' ),
			'last_crawl_time'  => '' !== $crawl ? $crawl : null,
			'google_canonical' => (string) ( $index['googleCanonical'] ?? '' ),
			'user_canonical'   => (string) ( $index['userCanonical'] ?? '' ),
			'crawled_as'       => (string) ( $index['crawledAs'] ?? '' ),
			'sitemaps'         => array_values( array_map( 'strval', (array) ( $index['sitemap'] ?? array() ) ) ),
			'referring_urls'   => array_values( array_map( 'strval', (array) ( $index['referringUrls'] ?? array() ) ) ),
			'rich_results'     => array(
				'verdict' => (string) ( $rich['verdict'] ?? '' ),
				'items'   => $items,
			),
			'inspection_link'  => (string) ( $result['inspectionResultLink'] ?? '' ),
			'raw'              => $result,
		);
	}

	/**
	 * Sitemaps known to Search Console for the property.
	 *
	 * @return array|\WP_Error
	 */
	public function list_sitemaps() {
		$property = $this->property();
		if ( '' === $property ) {
			return $this->not_connected();
		}
		$json = $this->call( 'GET', self::API . '/sites/' . self::encode_site( $property ) . '/sitemaps', array(), 'google_sitemaps' );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return self::parse_sitemaps( $json );
	}

	/**
	 * Normalizes a sitemaps.list response.
	 *
	 * @param array $json Response.
	 * @return array
	 */
	public static function parse_sitemaps( array $json ) {
		$out = array();
		foreach ( (array) ( $json['sitemap'] ?? array() ) as $sitemap ) {
			if ( ! is_array( $sitemap ) || empty( $sitemap['path'] ) ) {
				continue;
			}
			$contents = array();
			foreach ( (array) ( $sitemap['contents'] ?? array() ) as $content ) {
				$contents[] = array(
					'type'      => (string) ( $content['type'] ?? '' ),
					'submitted' => (int) ( $content['submitted'] ?? 0 ),
					'indexed'   => (int) ( $content['indexed'] ?? 0 ),
				);
			}
			$out[] = array(
				'path'              => (string) $sitemap['path'],
				'last_submitted'    => self::iso( $sitemap['lastSubmitted'] ?? '' ),
				'last_downloaded'   => self::iso( $sitemap['lastDownloaded'] ?? '' ),
				'is_pending'        => ! empty( $sitemap['isPending'] ),
				'is_sitemaps_index' => ! empty( $sitemap['isSitemapsIndex'] ),
				'type'              => (string) ( $sitemap['type'] ?? '' ),
				'warnings'          => (int) ( $sitemap['warnings'] ?? 0 ),
				'errors'            => (int) ( $sitemap['errors'] ?? 0 ),
				'contents'          => $contents,
			);
		}
		return $out;
	}

	/**
	 * Removes an entry from the property's sitemap list (sitemaps.delete). The page or
	 * file itself is not touched; Google only stops treating the address as a sitemap.
	 *
	 * @param string $sitemap_url Address as listed by list_sitemaps() (this site only).
	 * @return true|\WP_Error
	 */
	public function delete_sitemap( $sitemap_url ) {
		$property = $this->property();
		if ( '' === $property ) {
			return $this->not_connected();
		}
		$sitemap_url = esc_url_raw( (string) $sitemap_url );
		if ( '' === $sitemap_url || ! Url::is_internal( $sitemap_url ) ) {
			return new \WP_Error( 'searchpilot_invalid_sitemap', __( 'Only entries of this site can be removed.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$url  = self::API . '/sites/' . self::encode_site( $property ) . '/sitemaps/' . rawurlencode( $sitemap_url );
		$json = $this->call( 'DELETE', $url, array(), 'google_sitemaps', true );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$this->logger->info( 'google', 'Sitemap entry removed from Search Console: ' . $sitemap_url );
		return true;
	}

	/**
	 * Submits (resubmits) a sitemap.
	 *
	 * @param string $sitemap_url Absolute sitemap URL on this site.
	 * @return true|\WP_Error
	 */
	public function submit_sitemap( $sitemap_url ) {
		$property = $this->property();
		if ( '' === $property ) {
			return $this->not_connected();
		}
		$sitemap_url = esc_url_raw( (string) $sitemap_url );
		if ( '' === $sitemap_url || ! Url::is_internal( $sitemap_url ) ) {
			return new \WP_Error( 'searchpilot_invalid_sitemap', __( 'Only sitemaps on this site can be submitted.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$url  = self::API . '/sites/' . self::encode_site( $property ) . '/sitemaps/' . rawurlencode( $sitemap_url );
		$json = $this->call( 'PUT', $url, array( 'body' => '' ), 'google_sitemaps', true );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$this->logger->info( 'google', 'Sitemap submitted to Search Console: ' . $sitemap_url );
		return true;
	}

	/**
	 * Encodes a property for the URL path (sc-domain:… and https://… alike).
	 *
	 * @param string $site Property.
	 * @return string
	 */
	public static function encode_site( $site ) {
		return rawurlencode( (string) $site );
	}

	/**
	 * Performs an API call with budget checks and error mapping.
	 *
	 * @param string $method     Method.
	 * @param string $url        URL.
	 * @param array  $args       HttpClient args.
	 * @param string $budget     RateLimiter key.
	 * @param bool   $allow_empty Empty 2xx bodies count as success.
	 * @return array|\WP_Error Decoded JSON.
	 */
	private function call( $method, $url, array $args, $budget, $allow_empty = false ) {
		if ( ! $this->auth->is_configured() ) {
			return $this->not_connected();
		}
		if ( ! $this->limiter->attempt( $budget ) ) {
			return self::quota_error( $budget, $this->limiter->blocked_until( $budget ) );
		}
		$args['channel'] = 'google';
		$args['timeout'] = $args['timeout'] ?? 30;
		$response        = $this->auth->request( $method, $url, $args, array( OAuthClient::SCOPE_WEBMASTERS ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $this->handle( $response, $budget, $allow_empty );
	}

	/**
	 * Maps a response to data or a clear error.
	 *
	 * @param HttpResponse $response    Response.
	 * @param string       $budget      RateLimiter key.
	 * @param bool         $allow_empty Empty bodies are fine.
	 * @return array|\WP_Error
	 */
	private function handle( HttpResponse $response, $budget, $allow_empty ) {
		if ( $response->ok() ) {
			$json = $response->json();
			if ( null === $json ) {
				return $allow_empty || '' === trim( $response->body ) ? array() : new \WP_Error( 'searchpilot_google_response', __( 'Search Console returned an unreadable response.', 'searchpilot-pro' ), array( 'status' => 502 ) );
			}
			return $json;
		}
		if ( $response->is_rate_limited() ) {
			$retry = $response->retry_after();
			if ( $retry > 0 ) {
				$this->limiter->block( $budget, time() + $retry );
			} elseif ( 429 === $response->code && false === stripos( $response->body, 'quota' ) ) {
				$this->limiter->block( $budget, time() + 5 * MINUTE_IN_SECONDS );
			} else {
				$this->limiter->block_until_reset( $budget );
			}
			$this->logger->warning( 'google', sprintf( 'Search Console quota reached (%s): %s', $budget, $response->error_message() ) );
			return self::quota_error( $budget, $this->limiter->blocked_until( $budget ) );
		}
		$message = $response->error_message();
		if ( 403 === $response->code ) {
			return new \WP_Error(
				'searchpilot_gsc_permission',
				/* translators: 1: property, 2: Google's message. */
				sprintf( __( 'The connected Google account has no access to the Search Console property %1$s (%2$s). Add it as an Owner or Full user in Search Console → Settings → Users and permissions, or choose another property.', 'searchpilot-pro' ), $this->property(), $message ),
				array( 'status' => 403 )
			);
		}
		if ( 401 === $response->code ) {
			return new \WP_Error( 'searchpilot_google_unauthorized', __( 'Google refused the credentials. Reconnect Google.', 'searchpilot-pro' ), array( 'status' => 401 ) );
		}
		if ( 404 === $response->code ) {
			/* translators: %s: Google's message. */
			return new \WP_Error( 'searchpilot_gsc_not_found', sprintf( __( 'Search Console could not find that resource: %s', 'searchpilot-pro' ), $message ), array( 'status' => 404 ) );
		}
		return new \WP_Error(
			'searchpilot_google_api',
			/* translators: %s: error message. */
			sprintf( __( 'Search Console request failed: %s', 'searchpilot-pro' ), $message ),
			array(
				'status'      => $response->code >= 500 || 0 === $response->code ? 502 : 400,
				'retryable'   => $response->is_retryable(),
				'http_status' => $response->code,
			)
		);
	}

	/**
	 * Quota error.
	 *
	 * @param string $budget Budget key.
	 * @param int    $until  Blocked until.
	 * @return \WP_Error
	 */
	public static function quota_error( $budget, $until = 0 ) {
		$labels = array(
			'google_analytics'  => __( 'Search Analytics', 'searchpilot-pro' ),
			'google_inspection' => __( 'URL Inspection', 'searchpilot-pro' ),
			'google_sitemaps'   => __( 'Sitemaps', 'searchpilot-pro' ),
			'google_indexing'   => __( 'Indexing API', 'searchpilot-pro' ),
		);
		return new \WP_Error(
			'searchpilot_quota',
			/* translators: %s: API name. */
			sprintf( __( 'The %s quota is used up for now. SearchPilot pauses and continues automatically when Google allows it again.', 'searchpilot-pro' ), $labels[ $budget ] ?? $budget ),
			array(
				'status'        => 429,
				'retryable'     => true,
				'blocked_until' => $until ? gmdate( 'c', (int) $until ) : null,
				'retry_after'   => $until ? max( 60, (int) $until - time() ) : 0,
			)
		);
	}

	/**
	 * Not-connected error.
	 *
	 * @return \WP_Error
	 */
	private function not_connected() {
		return new \WP_Error( 'searchpilot_gsc_not_connected', __( 'Google Search Console is not connected, or no property is selected.', 'searchpilot-pro' ), array( 'status' => 400 ) );
	}

	/**
	 * ISO 8601 from a Google timestamp.
	 *
	 * @param string $value Timestamp.
	 * @return string|null
	 */
	private static function iso( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'c', $ts ) : null;
	}
}
