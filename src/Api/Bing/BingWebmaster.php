<?php
/**
 * Bing Webmaster Tools API client (JSON endpoint).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Bing;

use SearchPilot\Core\Credentials;
use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * https://ssl.bing.com/webmaster/api.svc/json/{Method}?apikey=KEY — JSON
 * responses are wrapped in {"d": …} and dates look like
 * "/Date(1316156400000-0700)/". The API key lives in Credentials
 * bing.api_key (the SEARCHPILOT_BING_API_KEY constant overrides it); the
 * site in setting engines.bing_site (default home_url('/')).
 *
 * Budgets: bing_api for reads, bing_url for URL submissions (its daily
 * limit is the account's own quota, stored in option
 * searchpilot_bing_daily_quota by get_url_submission_quota()).
 */
class BingWebmaster {

	const API = 'https://ssl.bing.com/webmaster/api.svc/json/';

	const STATE_KEY = 'bing_connection';

	const ISSUE = 'bing_connection_failed';

	const QUOTA_OPTION = 'searchpilot_bing_daily_quota';

	const CRAWL_STATE = 'bing_crawl';

	const FEEDS_STATE = 'bing_feeds';

	const MAX_BATCH = 500;

	/**
	 * Bing ErrorCode values that mean "the key or account is not usable".
	 */
	const AUTH_ERRORS = array( 3, 6, 10, 14 );

	/**
	 * Bing ErrorCode values that mean "throttled".
	 */
	const THROTTLE_ERRORS = array( 4, 5 );

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $http;

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
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param Credentials     $credentials Credentials.
	 * @param Settings        $settings    Settings.
	 * @param State           $state       State.
	 * @param HttpClient      $http        HTTP client.
	 * @param RateLimiter     $limiter     Rate limiter.
	 * @param Logger          $logger      Logger.
	 * @param IssueRepository $issues      Issues.
	 */
	public function __construct( Credentials $credentials, Settings $settings, State $state, HttpClient $http, RateLimiter $limiter, Logger $logger, IssueRepository $issues ) {
		$this->credentials = $credentials;
		$this->settings    = $settings;
		$this->state       = $state;
		$this->http        = $http;
		$this->limiter     = $limiter;
		$this->logger      = $logger;
		$this->issues      = $issues;
	}

	/**
	 * Saved API key ('' when none).
	 *
	 * @return string
	 */
	private function api_key() {
		return trim( (string) $this->credentials->get( 'bing', 'api_key', '' ) );
	}

	/**
	 * Whether an API key is available.
	 *
	 * @return bool
	 */
	public function has_key() {
		return '' !== $this->api_key();
	}

	/**
	 * Key present and not known to be rejected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->has_key() && 'error' !== $this->connection()['status'];
	}

	/**
	 * Site URL registered in Bing Webmaster Tools.
	 *
	 * @return string
	 */
	public function site() {
		$site = trim( (string) $this->settings->get( 'engines.bing_site', '' ) );
		return '' !== $site ? $site : home_url( '/' );
	}

	/**
	 * Connection health.
	 *
	 * @return array{status:string,error:string,checked_at:string|null,quota:array|null}
	 */
	public function connection() {
		$data = (array) $this->state->get( self::STATE_KEY, array() );
		return array(
			'status'     => (string) ( $data['status'] ?? '' ),
			'error'      => (string) ( $data['error'] ?? '' ),
			'checked_at' => ! empty( $data['checked_at'] ) ? (string) $data['checked_at'] : null,
			'quota'      => isset( $data['quota'] ) && is_array( $data['quota'] ) ? array(
				'daily'   => (int) ( $data['quota']['daily'] ?? 0 ),
				'monthly' => (int) ( $data['quota']['monthly'] ?? 0 ),
			) : null,
		);
	}

	/**
	 * Validates a key and site, then stores them.
	 *
	 * @param string $api_key API key ('' keeps the saved/constant key).
	 * @param string $site    Site URL ('' = home URL).
	 * @return array|\WP_Error Connection status.
	 */
	public function connect( $api_key, $site ) {
		$api_key = trim( (string) $api_key );
		$site    = trim( (string) $site );
		$site    = '' !== $site ? esc_url_raw( $site ) : home_url( '/' );
		if ( '' === $api_key ) {
			$api_key = $this->api_key();
		}
		if ( '' === $api_key ) {
			return new \WP_Error( 'searchpilot_bing_key_missing', __( 'Enter your Bing Webmaster API key.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $api_key ) ) {
			return new \WP_Error( 'searchpilot_bing_key_format', __( 'That does not look like a Bing Webmaster API key (32 letters and digits). Copy it from Bing Webmaster Tools → Settings → API access.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( ! Url::is_internal( $site ) ) {
			return new \WP_Error( 'searchpilot_bing_site', __( 'The Bing site must be this website’s address.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$sites = $this->get_user_sites( $api_key );
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$match = self::find_site( $sites, $site );
		if ( null === $match ) {
			return new \WP_Error(
				'searchpilot_bing_site_missing',
				/* translators: %s: site URL. */
				sprintf( __( '%s is not added to this Bing Webmaster Tools account. Add and verify the site in Bing Webmaster Tools first (you can import it from Google Search Console).', 'searchpilot-pro' ), $site ),
				array( 'status' => 400 )
			);
		}
		if ( empty( $match['verified'] ) ) {
			return new \WP_Error(
				'searchpilot_bing_site_unverified',
				/* translators: %s: site URL. */
				sprintf( __( '%s is added to Bing Webmaster Tools but not verified yet. Verify ownership there, then try again.', 'searchpilot-pro' ), $match['url'] ),
				array( 'status' => 400 )
			);
		}
		if ( ! $this->credentials->is_constant( 'bing', 'api_key' ) ) {
			$saved = $this->credentials->set( 'bing', array( 'api_key' => $api_key ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		$this->settings->update( array( 'engines.bing_site' => $match['url'] ) );
		$this->record_success();
		$this->get_url_submission_quota();
		$this->refresh_reports( true );
		$this->logger->info( 'bing', 'Bing Webmaster Tools connected for ' . $match['url'] );
		return $this->connection();
	}

	/**
	 * Removes the stored key.
	 *
	 * @return true|\WP_Error
	 */
	public function disconnect() {
		if ( $this->credentials->is_constant( 'bing', 'api_key' ) ) {
			return new \WP_Error( 'searchpilot_bing_constant', __( 'The Bing API key is defined in wp-config.php (SEARCHPILOT_BING_API_KEY). Remove it there to disconnect.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$this->credentials->clear( 'bing' );
		$this->state->set_many(
			array(
				self::STATE_KEY   => array(),
				self::CRAWL_STATE => array(),
				self::FEEDS_STATE => array(),
			)
		);
		delete_option( self::QUOTA_OPTION );
		$this->issues->resolve( self::ISSUE, 'site', 0 );
		$this->logger->info( 'bing', 'Bing Webmaster Tools was disconnected by an administrator.' );
		return true;
	}

	/**
	 * Sites in the Bing account.
	 *
	 * @param string|null $api_key Key to test ('' / null = saved key).
	 * @return array|\WP_Error [ [ url, verified ] ]
	 */
	public function get_user_sites( $api_key = null ) {
		$d = $this->call( 'GetUserSites', 'GET', array(), null, 'bing_api', $api_key );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$out = array();
		foreach ( (array) $d as $site ) {
			if ( is_array( $site ) && ! empty( $site['Url'] ) ) {
				$out[] = array(
					'url'      => (string) $site['Url'],
					'verified' => ! empty( $site['IsVerified'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Finds a site in GetUserSites results (scheme/www/trailing slash tolerant).
	 *
	 * @param array  $sites Sites.
	 * @param string $site  Wanted site.
	 * @return array|null
	 */
	public static function find_site( array $sites, $site ) {
		$want      = Url::normalize( trailingslashit( (string) $site ) );
		$want_bare = preg_replace( '#^https?://(www\.)?#i', '', $want );
		$fallback  = null;
		foreach ( $sites as $entry ) {
			$have = Url::normalize( trailingslashit( (string) ( $entry['url'] ?? '' ) ) );
			if ( $have === $want ) {
				return $entry;
			}
			if ( null === $fallback && preg_replace( '#^https?://(www\.)?#i', '', $have ) === $want_bare ) {
				$fallback = $entry;
			}
		}
		return $fallback;
	}

	/**
	 * Daily/monthly URL submission quota (also stored for the RateLimiter).
	 *
	 * @return array|\WP_Error [ daily, monthly ]
	 */
	public function get_url_submission_quota() {
		$d = $this->call( 'GetUrlSubmissionQuota', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$quota = array(
			'daily'   => max( 0, (int) ( $d['DailyQuota'] ?? 0 ) ),
			'monthly' => max( 0, (int) ( $d['MonthlyQuota'] ?? 0 ) ),
		);
		// Bing reports what is left today; left + already used by us today = today's limit.
		// The RateLimiter treats 0 as "unlimited", so an exhausted quota blocks the key instead.
		update_option( self::QUOTA_OPTION, max( 1, $quota['daily'] + $this->limiter->used_today( 'bing_url' ) ), false );
		if ( 0 === $quota['daily'] ) {
			$this->limiter->block_until_reset( 'bing_url' );
		}
		$data                     = (array) $this->state->get( self::STATE_KEY, array() );
		$data['quota']            = $quota;
		$data['quota_checked_at'] = gmdate( 'c' );
		$this->state->set( self::STATE_KEY, $data );
		return $quota;
	}

	/**
	 * Submits up to 500 URLs (within the daily quota).
	 *
	 * @param string[] $urls URLs of this site.
	 * @return true|\WP_Error
	 */
	public function submit_url_batch( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( array( Url::class, 'normalize' ), $urls ), array( Url::class, 'is_internal' ) ) ) );
		if ( empty( $urls ) ) {
			return new \WP_Error(
				'searchpilot_bing_no_urls',
				__( 'No URLs of this site to submit.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( count( $urls ) > self::MAX_BATCH ) {
			return new \WP_Error(
				'searchpilot_bing_batch',
				__( 'Bing accepts at most 500 URLs per request.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( ! $this->limiter->attempt( 'bing_url', count( $urls ) ) ) {
			return new \WP_Error(
				'searchpilot_quota',
				__( 'The Bing URL submission quota for today is used up. The remaining URLs are sent after the daily reset.', 'searchpilot-pro' ),
				array(
					'status'      => 429,
					'retryable'   => true,
					'retry_after' => $this->seconds_until_reset(),
				)
			);
		}
		$d = $this->call(
			'SubmitUrlBatch',
			'POST',
			array(),
			array(
				'siteUrl' => $this->site(),
				'urlList' => $urls,
			),
			null
		);
		if ( is_wp_error( $d ) ) {
			if ( 'searchpilot_quota' === $d->get_error_code() ) {
				$this->limiter->block_until_reset( 'bing_url' );
			}
			return $d;
		}
		return true;
	}

	/**
	 * Submits a sitemap/feed.
	 *
	 * @param string $url Sitemap URL.
	 * @return true|\WP_Error
	 */
	public function submit_feed( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url || ! Url::is_internal( $url ) ) {
			return new \WP_Error(
				'searchpilot_invalid_sitemap',
				__( 'Only sitemaps on this site can be submitted.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		$d = $this->call(
			'SubmitFeed',
			'POST',
			array(),
			array(
				'siteUrl' => $this->site(),
				'feedUrl' => $url,
			),
			'bing_api'
		);
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$this->logger->info( 'bing', 'Sitemap submitted to Bing: ' . $url );
		return true;
	}

	/**
	 * Sitemaps/feeds known to Bing.
	 *
	 * @return array|\WP_Error [ url, status, type, submitted, last_crawled, url_count, compressed, file_size ]
	 */
	public function get_feeds() {
		$d = $this->call( 'GetFeeds', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$out = array();
		foreach ( (array) $d as $feed ) {
			if ( ! is_array( $feed ) || empty( $feed['Url'] ) ) {
				continue;
			}
			$out[] = array(
				'url'          => (string) $feed['Url'],
				'status'       => (string) ( $feed['Status'] ?? '' ),
				'type'         => (string) ( $feed['Type'] ?? '' ),
				'submitted'    => self::parse_datetime( $feed['Submitted'] ?? '' ),
				'last_crawled' => self::parse_datetime( $feed['LastCrawled'] ?? '' ),
				'url_count'    => (int) ( $feed['UrlCount'] ?? 0 ),
				'compressed'   => ! empty( $feed['Compressed'] ),
				'file_size'    => (int) ( $feed['FileSize'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Daily clicks and impressions.
	 *
	 * @return array|\WP_Error [ [ date, clicks, impressions ] ]
	 */
	public function get_rank_and_traffic_stats() {
		$d = $this->call( 'GetRankAndTrafficStats', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		return is_wp_error( $d ) ? $d : self::map_traffic( (array) $d );
	}

	/**
	 * Query statistics.
	 *
	 * @return array|\WP_Error [ [ query, date, clicks, impressions, avg_click_position, avg_impression_position ] ]
	 */
	public function get_query_stats() {
		$d = $this->call( 'GetQueryStats', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		return is_wp_error( $d ) ? $d : self::map_query_stats( (array) $d, 'query' );
	}

	/**
	 * Page statistics (Bing returns the page URL in the "Query" field).
	 *
	 * @return array|\WP_Error [ [ page, date, clicks, impressions, avg_click_position, avg_impression_position ] ]
	 */
	public function get_page_stats() {
		$d = $this->call( 'GetPageStats', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		return is_wp_error( $d ) ? $d : self::map_query_stats( (array) $d, 'page' );
	}

	/**
	 * Crawl statistics.
	 *
	 * @return array|\WP_Error [ [ date, crawled_pages, crawl_errors, in_index, code_4xx, code_5xx, blocked_by_robots ] ]
	 */
	public function get_crawl_stats() {
		$d = $this->call( 'GetCrawlStats', 'GET', array( 'siteUrl' => $this->site() ), null, 'bing_api' );
		return is_wp_error( $d ) ? $d : self::map_crawl_stats( (array) $d );
	}

	/**
	 * Cached 30-day crawl summary (GetCrawlStats), refreshed at most daily.
	 *
	 * @param bool $refresh Fetch from Bing when the cache is older than a day (background/admin actions only).
	 * @param bool $force   Fetch now regardless of the cache age.
	 * @return array|null { checked_at, from, to, days, totals{crawled_pages,crawl_errors,code_4xx,code_5xx,blocked_by_robots}, in_index, series[] } or null.
	 */
	public function crawl_summary( $refresh = false, $force = false ) {
		$cached = (array) $this->state->get( self::CRAWL_STATE, array() );
		$stale  = empty( $cached['checked_at'] ) || strtotime( (string) $cached['checked_at'] ) < time() - DAY_IN_SECONDS + 600;
		if ( ( $force || ( $refresh && $stale ) ) && $this->is_connected() ) {
			$rows = $this->get_crawl_stats();
			if ( is_wp_error( $rows ) ) {
				$this->logger->warning( 'bing', 'Could not refresh Bing crawl statistics: ' . $rows->get_error_message() );
			} else {
				$cached = array_merge( self::summarize_crawl( $rows ), array( 'checked_at' => gmdate( 'c' ) ) );
				$this->state->set( self::CRAWL_STATE, $cached );
			}
		}
		return empty( $cached['checked_at'] ) ? null : $cached;
	}

	/**
	 * Cached list of sitemaps/feeds Bing knows (GetFeeds), refreshed at most daily.
	 *
	 * @param bool $refresh Fetch when the cache is older than a day.
	 * @param bool $force   Fetch now.
	 * @return array|null { checked_at, items[] } or null.
	 */
	public function feeds( $refresh = false, $force = false ) {
		$cached = (array) $this->state->get( self::FEEDS_STATE, array() );
		$stale  = empty( $cached['checked_at'] ) || strtotime( (string) $cached['checked_at'] ) < time() - DAY_IN_SECONDS + 600;
		if ( ( $force || ( $refresh && $stale ) ) && $this->is_connected() ) {
			$items = $this->get_feeds();
			if ( is_wp_error( $items ) ) {
				$this->logger->warning( 'bing', 'Could not refresh the Bing sitemap list: ' . $items->get_error_message() );
			} else {
				$cached = array(
					'checked_at' => gmdate( 'c' ),
					'items'      => array_slice( $items, 0, 50 ),
				);
				$this->state->set( self::FEEDS_STATE, $cached );
			}
		}
		return empty( $cached['checked_at'] ) ? null : $cached;
	}

	/**
	 * Refreshes the cached crawl statistics and sitemap list.
	 *
	 * @param bool $force Ignore cache age.
	 */
	public function refresh_reports( $force = false ) {
		$this->crawl_summary( true, $force );
		$this->feeds( true, $force );
	}

	/**
	 * Summarizes GetCrawlStats rows: the 30 days up to the latest reported day.
	 *
	 * @param array $rows map_crawl_stats() rows.
	 * @param int   $days Days.
	 * @return array{from:string,to:string,days:int,totals:array,in_index:int,series:array}
	 */
	public static function summarize_crawl( array $rows, $days = 30 ) {
		$totals = array(
			'crawled_pages'     => 0,
			'crawl_errors'      => 0,
			'code_4xx'          => 0,
			'code_5xx'          => 0,
			'blocked_by_robots' => 0,
		);
		$rows   = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return is_array( $row ) && ! empty( $row['date'] );
				}
			)
		);
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		if ( empty( $rows ) ) {
			return array(
				'from'     => '',
				'to'       => '',
				'days'     => (int) $days,
				'totals'   => $totals,
				'in_index' => 0,
				'series'   => array(),
			);
		}
		$to     = (string) end( $rows )['date'];
		$from   = gmdate( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$series = array();
		foreach ( $rows as $row ) {
			if ( $row['date'] < $from ) {
				continue;
			}
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += (int) ( $row[ $key ] ?? 0 );
			}
			$series[] = array(
				'date'              => (string) $row['date'],
				'crawled_pages'     => (int) ( $row['crawled_pages'] ?? 0 ),
				'crawl_errors'      => (int) ( $row['crawl_errors'] ?? 0 ),
				'in_index'          => (int) ( $row['in_index'] ?? 0 ),
				'code_4xx'          => (int) ( $row['code_4xx'] ?? 0 ),
				'code_5xx'          => (int) ( $row['code_5xx'] ?? 0 ),
				'blocked_by_robots' => (int) ( $row['blocked_by_robots'] ?? 0 ),
			);
		}
		$last = end( $series );
		return array(
			'from'     => $from,
			'to'       => $to,
			'days'     => (int) $days,
			'totals'   => $totals,
			'in_index' => $last ? (int) $last['in_index'] : 0,
			'series'   => $series,
		);
	}

	/**
	 * Maps GetRankAndTrafficStats rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function map_traffic( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$date = is_array( $row ) ? self::parse_date( $row['Date'] ?? '' ) : '';
			if ( '' === $date ) {
				continue;
			}
			$out[] = array(
				'date'        => $date,
				'clicks'      => (int) ( $row['Clicks'] ?? 0 ),
				'impressions' => (int) ( $row['Impressions'] ?? 0 ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $out;
	}

	/**
	 * Maps GetQueryStats/GetPageStats rows.
	 *
	 * @param array  $rows Rows.
	 * @param string $key  query|page.
	 * @return array
	 */
	public static function map_query_stats( array $rows, $key = 'query' ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Query'] ) ) {
				continue;
			}
			$out[] = array(
				$key                      => (string) $row['Query'],
				'date'                    => self::parse_date( $row['Date'] ?? '' ),
				'clicks'                  => (int) ( $row['Clicks'] ?? 0 ),
				'impressions'             => (int) ( $row['Impressions'] ?? 0 ),
				'avg_click_position'      => self::position( $row['AvgClickPosition'] ?? 0 ),
				'avg_impression_position' => self::position( $row['AvgImpressionPosition'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Maps GetCrawlStats rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function map_crawl_stats( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$date = is_array( $row ) ? self::parse_date( $row['Date'] ?? '' ) : '';
			if ( '' === $date ) {
				continue;
			}
			$out[] = array(
				'date'              => $date,
				'crawled_pages'     => (int) ( $row['CrawledPages'] ?? 0 ),
				'crawl_errors'      => (int) ( $row['CrawlErrors'] ?? 0 ),
				'in_index'          => (int) ( $row['InIndex'] ?? 0 ),
				'code_4xx'          => (int) ( $row['Code4xx'] ?? 0 ),
				'code_5xx'          => (int) ( $row['Code5xx'] ?? 0 ),
				'blocked_by_robots' => (int) ( $row['BlockedByRobotsTxt'] ?? 0 ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $out;
	}

	/**
	 * Average position (-1 / 0 = no data).
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	private static function position( $value ) {
		$value = (float) $value;
		return $value > 0 ? $value : 0.0;
	}

	/**
	 * Parses "/Date(1316156400000-0700)/" (or ISO) into Y-m-d in the date's own offset.
	 *
	 * @param mixed $value Raw date.
	 * @return string '' when unparseable.
	 */
	public static function parse_date( $value ) {
		$dt = self::to_datetime( $value );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * Parses a Bing date into ISO 8601 (keeping its offset).
	 *
	 * @param mixed $value Raw date.
	 * @return string|null
	 */
	public static function parse_datetime( $value ) {
		$dt = self::to_datetime( $value );
		return $dt ? $dt->format( 'c' ) : null;
	}

	/**
	 * DateTime from a Microsoft JSON date.
	 *
	 * @param mixed $value Raw date.
	 * @return \DateTimeImmutable|null
	 */
	private static function to_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '#^/?Date\((-?\d+)([+-]\d{4})?\)/?$#', $value, $m ) ) {
			$ms     = (float) $m[1];
			$offset = isset( $m[2] ) && '' !== $m[2] ? $m[2] : '+0000';
			// Year 1 / 9999 sentinels mean "no date".
			if ( $ms <= -62135596800000 || $ms >= 253402300799000 ) {
				return null;
			}
			try {
				$seconds = (int) floor( $ms / 1000 );
				$dt      = new \DateTimeImmutable( '@' . $seconds );
				return $dt->setTimezone( new \DateTimeZone( $offset ) );
			} catch ( \Exception $e ) {
				return null;
			}
		}
		try {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
				return null;
			}
			return new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Unwraps {"d": …} or maps a Bing error payload.
	 *
	 * @param int        $status HTTP status.
	 * @param array|null $json   Decoded body.
	 * @param string     $body   Raw body.
	 * @return mixed|\WP_Error Unwrapped data.
	 */
	public static function unwrap( $status, $json, $body = '' ) {
		$status = (int) $status;
		if ( $status >= 200 && $status < 300 ) {
			if ( is_array( $json ) && array_key_exists( 'd', $json ) ) {
				return $json['d'];
			}
			if ( '' === trim( (string) $body ) ) {
				return null;
			}
			return new \WP_Error(
				'searchpilot_bing_response',
				__( 'Bing returned an unreadable response.', 'searchpilot-pro' ),
				array(
					'status'    => 502,
					'retryable' => true,
				)
			);
		}
		$code    = is_array( $json ) ? (int) ( $json['ErrorCode'] ?? 0 ) : 0;
		$message = is_array( $json ) ? sanitize_text_field( (string) ( $json['Message'] ?? '' ) ) : '';
		if ( '' === $message && '' !== trim( (string) $body ) ) {
			$message = mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $body ) ), 0, 200 );
		}
		if ( in_array( $code, self::AUTH_ERRORS, true ) || 401 === $status ) {
			return new \WP_Error(
				'searchpilot_bing_auth',
				/* translators: %s: Bing's message. */
				sprintf( __( 'Bing rejected the API key (%s). Generate a new key in Bing Webmaster Tools → Settings → API access and save it again.', 'searchpilot-pro' ), '' !== $message ? $message : 'InvalidApiKey' ),
				array(
					'status'     => 401,
					'retryable'  => false,
					'bing_error' => $code,
				)
			);
		}
		if ( in_array( $code, self::THROTTLE_ERRORS, true ) || 429 === $status || false !== stripos( $message, 'quota' ) ) {
			return new \WP_Error(
				'searchpilot_quota',
				/* translators: %s: Bing's message. */
				sprintf( __( 'Bing throttled the request or the quota is used up (%s).', 'searchpilot-pro' ), $message ),
				array(
					'status'     => 429,
					'retryable'  => true,
					'bing_error' => $code,
				)
			);
		}
		if ( 8 === $code || 7 === $code || 13 === $code ) {
			return new \WP_Error(
				'searchpilot_bing_invalid',
				/* translators: %s: Bing's message. */
				sprintf( __( 'Bing refused the request: %s', 'searchpilot-pro' ), '' !== $message ? $message : 'InvalidParameter' ),
				array(
					'status'     => 400,
					'retryable'  => false,
					'bing_error' => $code,
				)
			);
		}
		return new \WP_Error(
			'searchpilot_bing_api',
			/* translators: %s: error message. */
			sprintf( __( 'Bing Webmaster API request failed: %s', 'searchpilot-pro' ), '' !== $message ? $message : 'HTTP ' . $status ),
			array(
				'status'     => $status >= 500 || 0 === $status ? 502 : 400,
				'retryable'  => 0 === $status || $status >= 500 || in_array( $code, array( 1, 2 ), true ),
				'bing_error' => $code,
			)
		);
	}

	/**
	 * Calls a Bing method.
	 *
	 * @param string      $method      API method.
	 * @param string      $http_method GET|POST.
	 * @param array       $query       Query args.
	 * @param array|null  $body        JSON body.
	 * @param string|null $budget      RateLimiter key (null = already consumed).
	 * @param string|null $api_key     Key override (validation).
	 * @return mixed|\WP_Error
	 */
	private function call( $method, $http_method, array $query, $body, $budget, $api_key = null ) {
		$key = null === $api_key || '' === $api_key ? $this->api_key() : (string) $api_key;
		if ( '' === $key ) {
			return new \WP_Error(
				'searchpilot_bing_not_connected',
				__( 'Bing Webmaster Tools is not connected.', 'searchpilot-pro' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( null !== $budget && ! $this->limiter->attempt( $budget ) ) {
			return new \WP_Error(
				'searchpilot_quota',
				__( 'Too many Bing requests right now. SearchPilot continues automatically later.', 'searchpilot-pro' ),
				array(
					'status'      => 429,
					'retryable'   => true,
					'retry_after' => 60,
				)
			);
		}
		$url  = self::API . rawurlencode( $method ) . '?apikey=' . rawurlencode( $key );
		$args = array(
			'channel' => 'bing',
			'timeout' => 25,
			'headers' => array( 'Accept' => 'application/json' ),
		);
		if ( $query ) {
			$args['query'] = $query;
		}
		if ( null !== $body ) {
			$args['json'] = $body;
		}
		$response = $this->http->request( $http_method, $url, $args );
		$result   = self::unwrap( $response->code, $response->json(), 0 === $response->code ? $response->error_message() : $response->body );
		if ( is_wp_error( $result ) ) {
			if ( 'searchpilot_bing_auth' === $result->get_error_code() && ( null === $api_key || '' === $api_key ) ) {
				$this->record_failure( $result );
			}
			if ( 'searchpilot_quota' === $result->get_error_code() && null !== $budget ) {
				$retry = $response->retry_after();
				$this->limiter->block( $budget, time() + ( $retry > 0 ? $retry : 15 * MINUTE_IN_SECONDS ) );
			}
			return $result;
		}
		return $result;
	}

	/**
	 * Records a working connection.
	 */
	private function record_success() {
		$data = (array) $this->state->get( self::STATE_KEY, array() );
		$was  = (string) ( $data['status'] ?? '' );

		$data['status']     = 'connected';
		$data['error']      = '';
		$data['checked_at'] = gmdate( 'c' );
		$this->state->set( self::STATE_KEY, $data );
		$this->issues->resolve( self::ISSUE, 'site', 0 );
		if ( 'error' === $was ) {
			$this->logger->info( 'bing', 'Bing connection restored.' );
		}
	}

	/**
	 * Records a rejected key.
	 *
	 * @param \WP_Error $error Error.
	 */
	private function record_failure( \WP_Error $error ) {
		$data               = (array) $this->state->get( self::STATE_KEY, array() );
		$data['status']     = 'error';
		$data['error']      = $error->get_error_message();
		$data['checked_at'] = gmdate( 'c' );
		$this->state->set( self::STATE_KEY, $data );
		$this->issues->upsert(
			self::ISSUE,
			array(
				'object_type' => 'site',
				'object_id'   => 0,
				'title'       => __( 'Bing Webmaster Tools connection failed', 'searchpilot-pro' ),
				'data'        => array( 'message' => $error->get_error_message() ),
			)
		);
		$this->logger->error( 'bing', 'Bing rejected the API key: ' . $error->get_error_message() );
	}

	/**
	 * Tests the saved key and site.
	 *
	 * @return array|\WP_Error Connection status.
	 */
	public function test() {
		$sites = $this->get_user_sites();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}
		$match = self::find_site( $sites, $this->site() );
		if ( null === $match || empty( $match['verified'] ) ) {
			return new \WP_Error(
				'searchpilot_bing_site_missing',
				/* translators: %s: site URL. */
				sprintf( __( '%s is not a verified site in this Bing Webmaster Tools account.', 'searchpilot-pro' ), $this->site() ),
				array( 'status' => 400 )
			);
		}
		$this->record_success();
		$quota = $this->get_url_submission_quota();
		if ( is_wp_error( $quota ) ) {
			$this->logger->warning( 'bing', 'Could not read the Bing URL submission quota: ' . $quota->get_error_message() );
		}
		$this->refresh_reports( true );
		return $this->connection();
	}

	/**
	 * Seconds until the RateLimiter's daily window resets (midnight Pacific).
	 *
	 * @return int
	 */
	private function seconds_until_reset() {
		try {
			$next = new \DateTime( 'tomorrow', new \DateTimeZone( 'America/Los_Angeles' ) );
			return max( 60, $next->getTimestamp() - time() + 60 );
		} catch ( \Exception $e ) {
			return HOUR_IN_SECONDS;
		}
	}
}
