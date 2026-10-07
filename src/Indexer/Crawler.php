<?php
/**
 * Incremental self-audit crawler.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Core\Http\HttpClient;
use SearchPilot\Core\Http\HttpResponse;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\RobotsParser;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches registry URLs from the server itself (optionally straight to the
 * origin IP, past the CDN), follows redirects manually to record the
 * chain, analyses the rendered page and stores the result on the row.
 * Runs in small batches under a lock and a time budget; a circuit breaker
 * pauses crawling when the site cannot reach itself.
 */
class Crawler {

	const MAX_HOPS        = 5;
	const MAX_BYTES       = 2097152;
	const FAILURE_LIMIT   = 5;
	const PAUSE_SECONDS   = HOUR_IN_SECONDS;
	const HEALTH          = 'crawl_health';
	const ROBOTS          = 'robots_txt';
	const ROBOTS_PASS     = 'robots_pass';
	const LAST_RUN        = 'crawl_last_run';
	const RECHECK_MARKER  = '2000-01-01 00:00:00';
	const ROBOTS_MAX_SIZE = 65536;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

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
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Page inspector.
	 *
	 * @var PageInspector
	 */
	private $inspector;

	/**
	 * Issue detector.
	 *
	 * @var IssueDetector
	 */
	private $detector;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Registry sync.
	 *
	 * @var RegistrySync
	 */
	private $registry;

	/**
	 * Parsed robots.txt (memo).
	 *
	 * @var RobotsParser|null
	 */
	private $robots = null;

	/**
	 * Canonical target results within one run.
	 *
	 * @var array<string,array>
	 */
	private $targets = array();

	/**
	 * Constructor.
	 *
	 * @param UrlRepository   $urls      URLs.
	 * @param HttpClient      $http      HTTP.
	 * @param RateLimiter     $limiter   Rate limiter.
	 * @param Settings        $settings  Settings.
	 * @param State           $state     State.
	 * @param Logger          $logger    Logger.
	 * @param PageInspector   $inspector Inspector.
	 * @param IssueDetector   $detector  Detector.
	 * @param IssueRepository $issues    Issues.
	 * @param RegistrySync    $registry  Registry sync.
	 */
	public function __construct( UrlRepository $urls, HttpClient $http, RateLimiter $limiter, Settings $settings, State $state, Logger $logger, PageInspector $inspector, IssueDetector $detector, IssueRepository $issues, RegistrySync $registry ) {
		$this->urls      = $urls;
		$this->http      = $http;
		$this->limiter   = $limiter;
		$this->settings  = $settings;
		$this->state     = $state;
		$this->logger    = $logger;
		$this->inspector = $inspector;
		$this->detector  = $detector;
		$this->issues    = $issues;
		$this->registry  = $registry;
	}

	/* --------------------------------------------------------------------- *
	 * Batches
	 * --------------------------------------------------------------------- */

	/**
	 * Crawls due URLs.
	 *
	 * @param int    $seconds Time budget.
	 * @param int    $limit   Max URLs (0 = indexing.crawl_batch).
	 * @param string $context hourly|micro|manual.
	 * @return array{status:string,checked:int,failed:int,issues:int,remaining:int}
	 */
	public function run_batch( $seconds = 25, $limit = 0, $context = 'hourly' ) {
		$paused = $this->paused_until();
		if ( $paused ) {
			return array(
				'status'       => 'paused',
				'paused_until' => gmdate( 'c', $paused ),
				'checked'      => 0,
				'failed'       => 0,
				'issues'       => 0,
				'remaining'    => $this->backlog(),
			);
		}
		$result = Lock::run(
			'crawl',
			max( 120, (int) $seconds * 3 ),
			function () use ( $seconds, $limit, $context ) {
				return $this->crawl( new TimeBudget( (int) $seconds ), (int) $limit, $context );
			}
		);
		if ( null === $result ) {
			return array(
				'status'    => 'busy',
				'checked'   => 0,
				'failed'    => 0,
				'issues'    => 0,
				'remaining' => $this->backlog(),
			);
		}
		return $result;
	}

	/**
	 * Crawl loop (lock held).
	 *
	 * @param TimeBudget $budget  Budget.
	 * @param int        $limit   Limit.
	 * @param string     $context Context.
	 * @return array
	 */
	private function crawl( TimeBudget $budget, $limit, $context ) {
		$start = microtime( true );
		$this->refresh_robots();
		$this->apply_robots_pass( $budget );

		$limit = $limit > 0 ? $limit : (int) $this->settings->get( 'indexing.crawl_batch', 20 );
		$rows  = $this->urls->due_for_check( $limit, (int) $this->settings->get( 'indexing.recheck_days', 14 ) );
		$stats = array(
			'status'  => 'ok',
			'checked' => 0,
			'failed'  => 0,
			'issues'  => 0,
		);
		foreach ( $rows as $row ) {
			if ( $budget->exhausted( 4 ) ) {
				$stats['status'] = 'budget';
				break;
			}
			if ( $this->paused_until() ) {
				$stats['status'] = 'paused';
				break;
			}
			if ( ! $this->limiter->attempt( 'self_audit' ) ) {
				$stats['status'] = 'rate_limited';
				break;
			}
			$result = $this->check_row( $row, $budget, array( 'bust_cache' => self::needs_fresh_copy( $row ) ) );
			if ( is_wp_error( $result ) ) {
				if ( 'searchpilot_url_removed' === $result->get_error_code() ) {
					$stats['removed'] = ( $stats['removed'] ?? 0 ) + 1;
				} else {
					++$stats['failed'];
				}
				continue;
			}
			++$stats['checked'];
			$stats['issues'] += count( $result['issues'] );
		}
		$stats['remaining']   = $this->backlog();
		$stats['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		$stats['context']     = $context;
		$stats['at']          = gmdate( 'c' );
		$this->state->set( self::LAST_RUN, $stats );
		if ( $stats['checked'] || $stats['failed'] ) {
			$this->logger->info( 'crawler', sprintf( 'Checked %d URLs (%d failed).', $stats['checked'], $stats['failed'] ), $stats );
		}
		$this->targets = array();
		return $stats;
	}

	/**
	 * Whether a scheduled check should look past page caches: the row was never
	 * checked, a re-check was requested, or the page changed after its last
	 * check — exactly when a cached copy may be stale. Rows due only because of
	 * their age are fetched normally (the cached copy is what visitors get).
	 *
	 * @param array $row Hydrated row.
	 * @return bool
	 */
	public static function needs_fresh_copy( array $row ) {
		$checked = (string) ( $row['last_checked'] ?? '' );
		if ( '' === $checked || 0 === strpos( $checked, substr( self::RECHECK_MARKER, 0, 10 ) ) ) {
			return true;
		}
		$modified = (string) ( $row['last_modified'] ?? '' );
		return '' !== $modified && strtotime( $modified ) > strtotime( $checked );
	}

	/**
	 * Number of URLs waiting for a check (capped at 1000 to keep the query cheap).
	 *
	 * @return int
	 */
	public function backlog() {
		global $wpdb;
		$table  = Tables::urls();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $this->settings->get( 'indexing.recheck_days', 14 ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT id FROM {$table} WHERE status = 'active' AND ( last_checked IS NULL OR last_checked < %s OR ( last_modified IS NOT NULL AND last_modified > last_checked ) ) LIMIT 1000 ) due", $cutoff ) );
	}

	/**
	 * Asks for an early re-check of a row. Never-checked rows keep NULL; checked
	 * rows get a marker date so their previous results stay visible meanwhile.
	 *
	 * @param int $id Row id.
	 */
	public static function request_recheck( $id ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_checked = %s WHERE id = %d AND last_checked IS NOT NULL", self::RECHECK_MARKER, (int) $id ) );
	}

	/**
	 * Schedules a background check of one row, at most 20 per 10 minutes
	 * (bulk edits fall back to the normal crawl queue).
	 *
	 * @param \SearchPilot\Core\Scheduler $scheduler Scheduler.
	 * @param int                         $id        Row id.
	 * @param int                         $delay     Seconds.
	 * @return bool Whether an event was scheduled.
	 */
	public static function schedule_recheck( $scheduler, $id, $delay = 120 ) {
		$window = get_transient( 'searchpilot_recheck_events' );
		if ( ! is_array( $window ) || (int) ( $window['start'] ?? 0 ) < time() - 10 * MINUTE_IN_SECONDS ) {
			$window = array(
				'start' => time(),
				'count' => 0,
			);
		}
		if ( (int) $window['count'] >= 20 ) {
			return false;
		}
		++$window['count'];
		// Fixed window: the expiry is not extended by later events.
		set_transient( 'searchpilot_recheck_events', $window, max( 60, (int) $window['start'] + 10 * MINUTE_IN_SECONDS - time() ) );
		$scheduler->enqueue_async( 'searchpilot_indexer_recheck', array( (int) $id ), (int) $delay );
		return true;
	}

	/* --------------------------------------------------------------------- *
	 * One URL
	 * --------------------------------------------------------------------- */

	/**
	 * Checks one registry row now and runs issue detection.
	 *
	 * @param array           $row    Hydrated row.
	 * @param TimeBudget|null $budget Budget (bounds request timeouts).
	 * @param array           $opts   {
	 *     @type bool $bust_cache Fetch past page caches (re-scans after a change; see CacheBuster).
	 * }
	 * @return array{row:array,details:array,issues:string[]}|\WP_Error
	 */
	public function check_row( array $row, $budget = null, array $opts = array() ) {
		$budget = $budget instanceof TimeBudget ? $budget : new TimeBudget( 40 );
		$row    = $this->refresh_row( $row );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$url    = (string) $row['url'];
		$chain  = $this->fetch( $url, $budget, $opts );
		$status = (int) $chain['status'];

		if ( ! empty( $chain['refused'] ) ) {
			// Not this site's origin (other host or port): never fetched, not a loopback failure.
			return new \WP_Error( 'searchpilot_not_fetchable', __( 'This URL is not on the site’s own host and port, so SearchPilot does not fetch it.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		if ( 0 === $status ) {
			$this->record_failure( $chain['error'] ? $chain['error'] : __( 'no response', 'searchpilot-pro' ), $url );
			// Retry in about six hours instead of waiting for the full re-check interval. Earlier
			// findings (and their issues) are kept: a failed fetch proves nothing about the page.
			$retry = gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) $this->settings->get( 'indexing.recheck_days', 14 ) * DAY_IN_SECONDS - 6 * HOUR_IN_SECONDS ) );
			$this->urls->update(
				(int) $row['id'],
				array(
					'errors'       => self::with_fetch_code( (array) $row['errors'], 'fetch_failed' ),
					'last_checked' => $retry,
				)
			);
			return new \WP_Error( 'searchpilot_fetch_failed', sprintf( /* translators: %s: error. */ __( 'The page could not be fetched: %s', 'searchpilot-pro' ), $chain['error'] ) );
		}
		if ( in_array( (int) $chain['first_status'], PageInspector::BLOCKED_STATUSES, true ) ) {
			/* translators: %d: HTTP status. */
			$this->record_failure( sprintf( __( 'HTTP %d — the request was refused', 'searchpilot-pro' ), (int) $chain['first_status'] ), $url );
			// The refusal says nothing about the page: keep its last known status and findings.
			$this->urls->update(
				(int) $row['id'],
				array(
					'errors'       => self::with_fetch_code( (array) $row['errors'], 'fetch_blocked' ),
					'last_checked' => current_time( 'mysql', true ),
				)
			);
			return new \WP_Error( 'searchpilot_fetch_blocked', __( 'The server refused SearchPilot’s request (firewall, basic authentication or bot protection).', 'searchpilot-pro' ) );
		}
		$this->record_success();

		$root    = 'pagination' === ( $row['object_type'] ?? '' ) ? RegistrySync::pagination_root( $url ) : '';
		$context = array(
			'indexable'          => ! empty( $row['indexable'] ),
			'expected_canonical' => $this->detector->expected_canonical( $row ),
			'pagination_root'    => $root,
			'expect_noindex'     => in_array( (string) ( $row['indexability_reason'] ?? '' ), array( 'noindex', 'noindex_default', 'paginated_noindex', 'site_noindex' ), true ),
		);
		$facts   = $this->inspector->analyze( $chain, $context );
		$codes   = $facts['codes'];

		$robots = $this->robots_status( $url );
		if ( $robots['blocked'] ) {
			$codes[] = 'robots_blocked';
		}

		$target = null;
		if ( 200 === $facts['status'] && '' !== $facts['canonical'] && 1 === $facts['canonical_distinct'] && Url::normalize( $facts['canonical'] ) !== Url::normalize( $facts['final_url'] ) && ! in_array( 'canonical_cross_domain', $codes, true ) && ! in_array( 'pagination_canonical', $codes, true ) ) {
			$target = $this->canonical_target( $facts['canonical'], $budget );
			if ( ! empty( $target['checked'] ) ) {
				if ( 200 !== (int) $target['status'] || ! empty( $target['noindex'] ) || ! empty( $target['redirected'] ) ) {
					$codes[] = 'canonical_to_non_200';
				}
			} elseif ( in_array( 'canonical_to_non_200', (array) $row['errors'], true ) && Url::normalize( (string) $row['canonical_url'] ) === Url::normalize( $facts['canonical'] ) ) {
				// Target not re-checked this time (budget): keep the previous finding.
				$codes[] = 'canonical_to_non_200';
			}
		}
		$codes = array_values( array_unique( $codes ) );

		$details = PageInspector::storable( $facts );
		unset( $details['codes'] );
		$details['codes']            = $codes;
		$details['robots']           = $robots;
		$details['canonical_target'] = $target;
		$details['checked_at']       = gmdate( 'c' );
		$details['duration_ms']      = (int) $chain['duration_ms'];
		$details['cache_bypassed']   = ! empty( $chain['cache_bypassed'] );

		$this->urls->update(
			(int) $row['id'],
			array(
				'http_status'        => (int) $chain['first_status'],
				'redirect_to'        => $facts['redirected'] ? mb_substr( (string) $facts['final_url'], 0, 2000 ) : null,
				'canonical_url'      => $facts['is_html'] ? ( '' !== $facts['canonical'] ? Url::normalize( $facts['canonical'] ) : '' ) : null,
				'robots_meta'        => mb_substr( implode( ', ', $facts['directives'] ), 0, 191 ),
				'robots_txt_blocked' => $robots['blocked'],
				'errors'             => $codes,
				'last_checked'       => current_time( 'mysql', true ),
			)
		);

		$key = 'searchpilot_crawl_' . (int) $row['id'];
		if ( $codes || $facts['redirected'] ) {
			set_transient( $key, $details, ( (int) $this->settings->get( 'indexing.recheck_days', 14 ) + 2 ) * DAY_IN_SECONDS );
		} else {
			delete_transient( $key );
		}

		if ( $facts['is_html'] && 200 === $facts['status'] ) {
			$this->store_rendered_head( $row, $facts, ! empty( $chain['cache_bypassed'] ) );
			$this->fire_crawled( $row, $chain, $facts );
		}
		unset( $chain, $facts );

		$fresh    = $this->urls->get( (int) $row['id'] );
		$detected = $fresh ? $this->detector->detect( $fresh, $details ) : array( 'types' => array() );
		return array(
			'row'     => $fresh ? $this->urls->get( (int) $row['id'] ) : $row,
			'details' => $details,
			'issues'  => $detected['types'],
		);
	}

	/**
	 * Previous error codes plus one fetch-failure code (older fetch codes dropped).
	 *
	 * @param string[] $codes Previous codes.
	 * @param string   $code  fetch_failed|fetch_blocked.
	 * @return string[]
	 */
	public static function with_fetch_code( array $codes, $code ) {
		$codes = array_values( array_diff( array_map( 'strval', $codes ), array( 'fetch_failed', 'fetch_blocked' ) ) );
		array_unshift( $codes, $code );
		return $codes;
	}

	/**
	 * Whether SearchPilot may fetch a URL in a self-audit: http(s), no user
	 * info, the site's own host (www or not) and exactly the site's port.
	 * When the home URL has no explicit port only the default ports (80/443)
	 * qualify; other ports are never fetched (no SSRF through crafted
	 * canonicals or redirects).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_fetchable( $url ) {
		$parts = wp_parse_url( Url::absolute( (string) $url ) );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( empty( $parts['host'] ) || empty( $home['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( Url::bare_host( $parts['host'] ) !== Url::bare_host( $home['host'] ) ) {
			return false;
		}
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : 0;
		if ( $home_port && ! in_array( $home_port, array( 80, 443 ), true ) ) {
			return $port === $home_port;
		}
		return 0 === $port || in_array( $port, array( 80, 443 ), true );
	}

	/**
	 * Re-reads the WordPress side of a row (indexability, sitemap membership,
	 * URL) so a check never judges the page against stale intentions — e.g.
	 * right after a fix removed a noindex.
	 *
	 * @param array $row Row.
	 * @return array|\WP_Error Current row, or an error when the object is gone.
	 */
	private function refresh_row( array $row ) {
		$type = (string) ( $row['object_type'] ?? '' );
		$id   = (int) ( $row['object_id'] ?? 0 );
		if ( ! $id || ! in_array( $type, array( 'post', 'attachment', 'term', 'author' ), true ) ) {
			return $row;
		}
		$current = $this->registry->sync_object( $type, $id );
		if ( $current === (int) $row['id'] ) {
			$fresh = $this->urls->get( (int) $row['id'] );
			return $fresh ? $fresh : $row;
		}
		// The object is gone/unpublished, or its URL changed: this row is outdated.
		$this->registry->remove_row( $row );
		if ( $current ) {
			$fresh = $this->urls->get( $current );
			if ( $fresh ) {
				return $fresh;
			}
		}
		return new \WP_Error( 'searchpilot_url_removed', __( 'This URL is no longer published, so it was removed from the registry.', 'searchpilot-pro' ), array( 'status' => 410 ) );
	}

	/**
	 * Post meta holding the head of the last successful (200, HTML) crawl of a post.
	 */
	const RENDERED_HEAD_META = '_searchpilot_rendered_head';

	/**
	 * Remembers what the page actually served in its head (title, meta
	 * description) for posts and attachment pages. Only 200 HTML responses of
	 * the post's own URL (not redirected) are recorded — a failed fetch keeps
	 * the last good snapshot — and the
	 * meta is written only when something besides the time changed, or once
	 * a day to keep the crawl time current.
	 *
	 * @param array $row      Row.
	 * @param array $facts    Facts of a 200 HTML response.
	 * @param bool  $bypassed The page was fetched past page caches.
	 */
	private function store_rendered_head( array $row, array $facts, $bypassed = false ) {
		$post_id = (int) ( $row['object_id'] ?? 0 );
		if ( ! $post_id || ! in_array( (string) ( $row['object_type'] ?? '' ), array( 'post', 'attachment' ), true ) ) {
			return;
		}
		if ( ! empty( $facts['redirected'] ) ) {
			return; // The head belongs to the redirect target, not to this post's URL.
		}
		$head = array(
			'title'             => (string) $facts['title'],
			'description'       => (string) ( $facts['description'] ?? '' ),
			'url'               => (string) $row['url'],
			'final_url'         => Url::normalize( (string) $facts['final_url'] ),
			'status'            => (int) $facts['status'],
			'title_count'       => (int) $facts['title_count'],
			'description_count' => (int) $facts['description_count'],
			'crawled_at'        => current_time( 'mysql', true ),
			'cache_bypassed'    => (bool) $bypassed,
		);
		// Fingerprint of the SEO inputs the head was rendered from: fixers change SEO meta without
		// touching post_modified, so MetaStore compares it to tell a fresh snapshot from a stale one.
		$meta = \SearchPilot\Plugin::instance()->get( \SearchPilot\Seo\MetaStore::class );
		if ( is_object( $meta ) && method_exists( $meta, 'head_inputs_hash' ) ) {
			try {
				$head['inputs'] = (string) $meta->head_inputs_hash( $post_id );
			} catch ( \Throwable $e ) {
				$this->logger->warning( 'crawler', 'Could not fingerprint the SEO inputs of a crawled post: ' . $e->getMessage(), array( 'post_id' => $post_id ) );
			}
		}
		$previous = get_post_meta( $post_id, self::RENDERED_HEAD_META, true );
		if ( is_array( $previous ) && ! $head['cache_bypassed'] && ! empty( $previous['cache_bypassed'] ) && self::snapshot_current( $post_id, $previous, $head ) ) {
			// A copy that may come from a page cache never replaces a cache-bypassed
			// snapshot that still matches the post (same inputs, taken after the last edit).
			return;
		}
		if ( is_array( $previous ) ) {
			$same = true;
			foreach ( $head as $key => $value ) {
				if ( 'crawled_at' !== $key && (string) ( $previous[ $key ] ?? '' ) !== (string) $value ) {
					$same = false;
					break;
				}
			}
			$age = time() - (int) strtotime( (string) ( $previous['crawled_at'] ?? '' ) . ' UTC' );
			// Unchanged: keep the row unless the post was edited after the stored crawl
			// (consumers compare crawled_at with post_modified_gmt to know it is fresh).
			$modified = get_post_field( 'post_modified_gmt', $post_id );
			$stale    = $modified && '0000-00-00 00:00:00' !== $modified && strtotime( $modified . ' UTC' ) >= strtotime( (string) ( $previous['crawled_at'] ?? '' ) . ' UTC' );
			if ( $same && ! $stale && $age < DAY_IN_SECONDS ) {
				return;
			}
		}
		update_post_meta( $post_id, self::RENDERED_HEAD_META, $head );
	}

	/**
	 * Whether a stored snapshot still describes the post: taken after its last
	 * edit and from the same SEO inputs as the snapshot just fetched.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $previous Stored snapshot.
	 * @param array $head     New snapshot.
	 * @return bool
	 */
	private static function snapshot_current( $post_id, array $previous, array $head ) {
		$crawled  = strtotime( (string) ( $previous['crawled_at'] ?? '' ) . ' UTC' );
		$modified = (string) get_post_field( 'post_modified_gmt', $post_id );
		$modified = '' !== $modified && '0000-00-00 00:00:00' !== $modified ? (int) strtotime( $modified . ' UTC' ) : 0;
		if ( ! $crawled || $crawled <= $modified ) {
			return false;
		}
		return (string) ( $previous['inputs'] ?? '' ) === (string) ( $head['inputs'] ?? '' );
	}

	/**
	 * Head of the last successful crawl of a post (what the page served).
	 *
	 * @param int $post_id Post or attachment id.
	 * @return array{title:string,description:string,url:string,final_url:string,status:int,title_count:int,description_count:int,crawled_at:string,inputs:string,cache_bypassed:bool}|null
	 *         crawled_at is UTC 'Y-m-d H:i:s'; inputs is MetaStore::head_inputs_hash() at crawl
	 *         time ('' when unknown); cache_bypassed is true only when the page was fetched
	 *         past page caches (only such snapshots can be "fresh"); null when the page was
	 *         never crawled successfully.
	 */
	public static function rendered_head( $post_id ) {
		$head = get_post_meta( (int) $post_id, self::RENDERED_HEAD_META, true );
		if ( ! is_array( $head ) || empty( $head['crawled_at'] ) ) {
			return null;
		}
		return array(
			'title'             => (string) ( $head['title'] ?? '' ),
			'description'       => (string) ( $head['description'] ?? '' ),
			'url'               => (string) ( $head['url'] ?? '' ),
			'final_url'         => (string) ( $head['final_url'] ?? '' ),
			'status'            => (int) ( $head['status'] ?? 0 ),
			'title_count'       => (int) ( $head['title_count'] ?? 0 ),
			'description_count' => (int) ( $head['description_count'] ?? 0 ),
			'crawled_at'        => (string) $head['crawled_at'],
			'inputs'            => (string) ( $head['inputs'] ?? '' ),
			'cache_bypassed'    => ! empty( $head['cache_bypassed'] ),
		);
	}

	/**
	 * Lets other modules analyse the real page (schema, headings, meta output).
	 *
	 * @param array $row   Row.
	 * @param array $chain Fetch result.
	 * @param array $facts Facts (with the parsed document).
	 */
	private function fire_crawled( array $row, array $chain, array $facts ) {
		$page = array(
			'url'         => (string) $row['url'],
			'final_url'   => (string) $facts['final_url'],
			'status'      => (int) $facts['status'],
			'headers'     => (array) $chain['headers'],
			'html'        => (string) $chain['body'],
			'doc'         => $facts['doc'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'row_id'      => (int) $row['id'],
			'redirects'   => (array) $facts['hops'],
		);
		try {
			/**
			 * Fires after the crawler fetched a page that answered 200 with HTML.
			 *
			 * @param array $page { url, final_url, status, headers (lower-case), html (≤2 MB),
			 *                     doc (Support\HtmlDocument), object_type, object_id, row_id, redirects }.
			 */
			do_action( 'searchpilot_page_crawled', $page );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'crawler', 'A searchpilot_page_crawled listener failed: ' . $e->getMessage(), array( 'url' => $row['url'] ) );
		}
	}

	/**
	 * Fetches a URL following redirects manually.
	 *
	 * With `bust_cache`, every request carries a one-off cache-busting argument
	 * (see CacheBuster) that is removed from the returned URLs, headers and body,
	 * so callers and stored data only ever see the clean URL. A server that
	 * answers the argument with a redirect to the same URL is asked again
	 * without it (`cache_bypassed` is false then: only the request headers asked
	 * for a fresh copy).
	 *
	 * @param string          $url    URL.
	 * @param TimeBudget|null $budget Budget.
	 * @param array           $opts   { @type bool $bust_cache Bypass page caches. }
	 * @return array{url:string,final_url:string,status:int,first_status:int,headers:array,body:string,hops:array,loop:bool,too_many:bool,external:bool,error:string,duration_ms:int,cache_bypassed:bool}
	 */
	public function fetch( $url, $budget = null, array $opts = array() ) {
		$token = ! empty( $opts['bust_cache'] ) ? CacheBuster::token() : '';
		if ( ! self::is_fetchable( $url ) ) {
			return array(
				'url'            => (string) $url,
				'final_url'      => (string) $url,
				'status'         => 0,
				'first_status'   => 0,
				'headers'        => array(),
				'body'           => '',
				'hops'           => array(),
				'loop'           => false,
				'too_many'       => false,
				'external'       => false,
				'refused'        => true,
				'error'          => __( 'Not fetched: the URL is not on the site’s own host and port.', 'searchpilot-pro' ),
				'duration_ms'    => 0,
				'cache_bypassed' => false,
			);
		}
		$start    = microtime( true );
		$hops     = array();
		$seen     = array( Url::normalize( $url ) => true );
		$current  = $url;
		$first    = null;
		$loop     = false;
		$too_many = false;
		$external = false;
		$response = null;
		$strip    = $token;
		$extra    = '' !== $token ? CacheBuster::headers() : array();
		$retried  = false;

		for ( $i = 0; $i <= self::MAX_HOPS; $i++ ) {
			$timeout  = $budget instanceof TimeBudget ? max( 3, min( 10, (int) floor( $budget->remaining() - 1 ) ) ) : 10;
			$response = '' !== $token ? $this->request( CacheBuster::add( $current, $token ), $timeout, $extra ) : $this->request( $current, $timeout, $extra );
			if ( null === $first ) {
				$first = $response->code;
			}
			$location = '' !== $strip ? CacheBuster::strip( $response->header( 'location' ), $strip ) : $response->header( 'location' );
			if ( $response->code < 300 || $response->code >= 400 || '' === $location ) {
				break;
			}
			$next = Url::absolute( $location, $current );
			if ( '' !== $token && ! $retried && Url::normalize( $next ) === Url::normalize( $current ) ) {
				// The server strips unknown query arguments with a redirect to the very URL we asked
				// for: that is not a redirect of the page. Fetch it once more without the argument
				// (the no-cache request headers remain) and report that caches were not bypassed.
				$token   = '';
				$retried = true;
				$first   = null;
				--$i;
				continue;
			}
			$hops[] = array(
				'url'      => $current,
				'status'   => $response->code,
				'location' => $next,
			);
			if ( count( $hops ) > self::MAX_HOPS ) {
				$too_many = true;
				break;
			}
			if ( ! self::is_fetchable( $next ) ) {
				// Off-site, another port or scheme: recorded, never followed.
				$external = true;
				$current  = $next;
				break;
			}
			$key = Url::normalize( $next );
			if ( isset( $seen[ $key ] ) ) {
				$loop    = true;
				$current = $next;
				break;
			}
			$seen[ $key ] = true;
			$current      = $next;
		}

		$headers = $response ? (array) $response->headers : array();
		$body    = $response && ! $external ? (string) $response->body : '';
		$error   = $response ? (string) $response->error : '';
		if ( '' !== $strip ) {
			// The page may echo the requested URL (redirects, share links, JSON-LD): keep only the clean one.
			$headers = CacheBuster::strip_deep( $headers, $strip );
			$body    = CacheBuster::strip( $body, $strip );
			$error   = CacheBuster::strip( $error, $strip );
		}
		return array(
			'url'            => $url,
			'final_url'      => $current,
			'status'         => $response ? (int) $response->code : 0,
			'first_status'   => (int) $first,
			'headers'        => $headers,
			'body'           => $body,
			'hops'           => $hops,
			'loop'           => $loop,
			'too_many'       => $too_many,
			'external'       => $external,
			'refused'        => false,
			'error'          => $error,
			'duration_ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'cache_bypassed' => '' !== $token,
		);
	}

	/**
	 * One self-audit request.
	 *
	 * @param string $url     URL.
	 * @param int    $timeout Timeout.
	 * @param array  $extra   Extra request headers.
	 * @return HttpResponse
	 */
	private function request( $url, $timeout, array $extra = array() ) {
		return $this->http->get(
			$url,
			array(
				'timeout'             => (int) $timeout,
				'redirection'         => 0,
				'channel'             => 'crawler',
				'log_errors'          => false,
				'resolve_to'          => self::is_fetchable( $url ) ? (string) $this->settings->get( 'indexing.origin_ip', '' ) : '',
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array_merge(
					array(
						'Accept'        => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
						'Cache-Control' => 'no-cache',
					),
					$extra
				),
			)
		);
	}

	/**
	 * Status of a canonical target (registry first, a fetch otherwise).
	 *
	 * @param string     $url    Canonical URL.
	 * @param TimeBudget $budget Budget.
	 * @return array{url:string,checked:bool,status:int,first_status:int,final:string,redirected:bool,noindex:bool,source:string}
	 */
	private function canonical_target( $url, TimeBudget $budget ) {
		$key = Url::normalize( $url );
		if ( isset( $this->targets[ $key ] ) ) {
			return $this->targets[ $key ];
		}
		$out = array(
			'url'          => $url,
			'checked'      => false,
			'status'       => 0,
			'first_status' => 0,
			'final'        => '',
			'redirected'   => false,
			'noindex'      => false,
			'source'       => '',
		);
		if ( ! self::is_fetchable( $url ) ) {
			return $out;
		}
		$row    = $this->urls->get_by_url( $url );
		$recent = time() - (int) $this->settings->get( 'indexing.recheck_days', 14 ) * DAY_IN_SECONDS;
		if ( $row && 'active' === $row['status'] && ! empty( $row['last_checked'] ) && strtotime( $row['last_checked'] ) > $recent && (int) $row['http_status'] > 0 ) {
			$codes  = (array) $row['errors'];
			$status = (int) $row['http_status'];
			if ( $status >= 300 && $status < 400 ) {
				$status = in_array( 'http_4xx', $codes, true ) ? 404 : ( in_array( 'http_5xx', $codes, true ) ? 500 : 200 );
			}
			$out = array_merge(
				$out,
				array(
					'checked'      => true,
					'status'       => $status,
					'first_status' => (int) $row['http_status'],
					'final'        => ! empty( $row['redirect_to'] ) ? (string) $row['redirect_to'] : $url,
					'redirected'   => ! empty( $row['redirect_to'] ),
					'noindex'      => false !== strpos( (string) $row['robots_meta'], 'noindex' ) || false !== strpos( (string) $row['robots_meta'], 'none' ),
					'source'       => 'registry',
				)
			);
		} elseif ( $budget->remaining() > 10 ) {
			$chain = $this->fetch( $url, $budget );
			if ( $chain['status'] > 0 ) {
				$facts = $this->inspector->analyze( $chain, array( 'indexable' => true ) );
				$out   = array_merge(
					$out,
					array(
						'checked'      => true,
						'status'       => (int) $chain['status'],
						'first_status' => (int) $chain['first_status'],
						'final'        => (string) $chain['final_url'],
						'redirected'   => ! empty( $chain['hops'] ),
						'noindex'      => ! empty( $facts['noindex'] ),
						'source'       => 'fetch',
					)
				);
			}
		}
		$this->targets[ $key ] = $out;
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Circuit breaker
	 * --------------------------------------------------------------------- */

	/**
	 * Crawl health.
	 *
	 * @return array{consecutive:int,paused_until:int,last_error:string,last_failure_at:int,last_success_at:int}
	 */
	public function health() {
		$h = $this->state->get( self::HEALTH );
		return array_merge(
			array(
				'consecutive'     => 0,
				'paused_until'    => 0,
				'last_error'      => '',
				'last_failure_at' => 0,
				'last_success_at' => 0,
			),
			is_array( $h ) ? $h : array()
		);
	}

	/**
	 * Unix time until which crawling is paused (0 = running).
	 *
	 * @return int
	 */
	public function paused_until() {
		$until = (int) $this->health()['paused_until'];
		return $until > time() ? $until : 0;
	}

	/**
	 * Records a transport failure; pauses after FAILURE_LIMIT in a row.
	 *
	 * @param string $error Error.
	 * @param string $url   URL.
	 */
	private function record_failure( $error, $url ) {
		$h                    = $this->health();
		$h['consecutive']     = (int) $h['consecutive'] + 1;
		$h['last_error']      = mb_substr( (string) $error, 0, 300 );
		$h['last_failure_at'] = time();
		if ( $h['consecutive'] >= self::FAILURE_LIMIT ) {
			$h['paused_until'] = time() + self::PAUSE_SECONDS;
			$this->issues->upsert(
				'loopback_blocked',
				array(
					'object_type' => 'site',
					'object_id'   => 0,
					'url'         => home_url( '/' ),
					'title'       => get_bloginfo( 'name' ),
					'data'        => array(
						'failures'     => $h['consecutive'],
						'error'        => $h['last_error'],
						'url'          => $url,
						'paused_until' => gmdate( 'c', $h['paused_until'] ),
					),
				)
			);
			$this->logger->warning( 'crawler', sprintf( 'Self-checks failed %d times in a row; crawling paused for an hour.', $h['consecutive'] ), array( 'error' => $h['last_error'] ) );
		}
		$this->state->set( self::HEALTH, $h );
	}

	/**
	 * Records a successful fetch (resets the breaker).
	 */
	private function record_success() {
		$h        = $this->health();
		$was_down = $h['consecutive'] > 0 || $h['paused_until'] > 0;
		if ( $was_down || time() - (int) $h['last_success_at'] > 300 ) {
			$h['consecutive']     = 0;
			$h['paused_until']    = 0;
			$h['last_success_at'] = time();
			$this->state->set( self::HEALTH, $h );
		}
		if ( $was_down ) {
			$this->issues->resolve( 'loopback_blocked', 'site', 0, home_url( '/' ) );
		}
	}

	/**
	 * Clears a pause (manual retry from the UI).
	 */
	public function resume() {
		if ( ! $this->paused_until() ) {
			return;
		}
		$h                 = $this->health();
		$h['paused_until'] = 0;
		// One more failure pauses again; one success clears the breaker.
		$h['consecutive'] = self::FAILURE_LIMIT - 1;
		$this->state->set( self::HEALTH, $h );
	}

	/* --------------------------------------------------------------------- *
	 * robots.txt
	 * --------------------------------------------------------------------- */

	/**
	 * Fetches robots.txt when the cached copy is older than an hour.
	 *
	 * @param bool $force Fetch now.
	 * @return array Info: fetched_at, status, hash, size, sitemaps, error, url.
	 */
	public function refresh_robots( $force = false ) {
		$info = $this->state->get( self::ROBOTS );
		$info = is_array( $info ) ? $info : array();
		if ( ! $force && ! empty( $info['fetched_at'] ) && time() - (int) $info['fetched_at'] < HOUR_IN_SECONDS ) {
			return $info;
		}
		$url      = home_url( '/robots.txt' );
		$response = $this->http->get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 5,
				'channel'             => 'crawler',
				'log_errors'          => false,
				'resolve_to'          => (string) $this->settings->get( 'indexing.origin_ip', '' ),
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => 524288,
			)
		);
		$info['url']        = $url;
		$info['fetched_at'] = time();
		$info['status']     = (int) $response->code;
		$info['error']      = '';
		if ( 0 === $response->code ) {
			$info['error'] = $response->error_message();
		} elseif ( $response->ok() ) {
			$body            = (string) $response->body;
			$info['size']    = strlen( $body );
			$info['content'] = substr( $body, 0, self::ROBOTS_MAX_SIZE );
			$info['hash']    = md5( $info['content'] );
			$info['sitemaps'] = array_slice( RobotsParser::parse( $info['content'] )->sitemaps(), 0, 20 );
		} elseif ( $response->code >= 400 && $response->code < 500 ) {
			// Crawlers treat a missing robots.txt as "allow everything".
			$info['size']     = 0;
			$info['content']  = '';
			$info['hash']     = md5( '' );
			$info['sitemaps'] = array();
		} else {
			$info['error'] = $response->error_message();
		}
		$this->state->set( self::ROBOTS, $info );
		$this->robots = null;
		return $info;
	}

	/**
	 * Cached robots.txt info without the content.
	 *
	 * @return array
	 */
	public function robots_info() {
		$info = $this->state->get( self::ROBOTS );
		$info = is_array( $info ) ? $info : array();
		unset( $info['content'] );
		if ( ! empty( $info['fetched_at'] ) ) {
			$info['fetched_at'] = gmdate( 'c', (int) $info['fetched_at'] );
		}
		return $info;
	}

	/**
	 * Parsed robots.txt (null until fetched once).
	 *
	 * @return RobotsParser|null
	 */
	public function robots_parser() {
		if ( null === $this->robots ) {
			$info = $this->state->get( self::ROBOTS );
			if ( ! is_array( $info ) || ! array_key_exists( 'content', $info ) ) {
				return null;
			}
			$this->robots = RobotsParser::parse( (string) $info['content'] );
		}
		return $this->robots;
	}

	/**
	 * Whether Googlebot/Bingbot may crawl a URL, with the deciding rule.
	 *
	 * @param string $url URL.
	 * @return array{blocked:bool,agents:string[],rule:string}
	 */
	public function robots_status( $url ) {
		$parser = $this->robots_parser();
		$out    = array(
			'blocked' => false,
			'agents'  => array(),
			'rule'    => '',
		);
		if ( ! $parser ) {
			return $out;
		}
		foreach ( array( 'Googlebot' => 'googlebot', 'Bingbot' => 'bingbot' ) as $label => $token ) {
			if ( ! $parser->is_allowed( $url, $token ) ) {
				$out['blocked']  = true;
				$out['agents'][] = $label;
				if ( '' === $out['rule'] ) {
					$rule        = $parser->matching_rule( $url, $token );
					$out['rule'] = $rule ? ucfirst( $rule[0] ) . ': ' . $rule[1] : '';
				}
			}
		}
		return $out;
	}

	/**
	 * After robots.txt changed, updates robots_txt_blocked for every active
	 * row (500 per query, a few thousand per run) and re-evaluates changed rows.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return int Rows whose flag changed.
	 */
	public function apply_robots_pass( TimeBudget $budget ) {
		$info = $this->state->get( self::ROBOTS );
		if ( ! is_array( $info ) || ! isset( $info['hash'] ) ) {
			return 0;
		}
		$pass = $this->state->get( self::ROBOTS_PASS );
		$pass = is_array( $pass ) ? $pass : array();
		if ( ( $pass['hash'] ?? '' ) !== $info['hash'] ) {
			$pass = array(
				'hash'   => $info['hash'],
				'cursor' => 0,
				'done'   => false,
			);
		}
		if ( ! empty( $pass['done'] ) ) {
			return 0;
		}
		global $wpdb;
		$table   = Tables::urls();
		$changed = 0;
		for ( $i = 0; $i < 6 && ! $budget->exhausted( 8 ); $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, robots_txt_blocked FROM {$table} WHERE status = 'active' AND id > %d ORDER BY id ASC LIMIT 500", (int) $pass['cursor'] ), ARRAY_A );
			if ( ! $rows ) {
				$pass['done'] = true;
				break;
			}
			foreach ( $rows as $r ) {
				$pass['cursor'] = (int) $r['id'];
				$robots         = $this->robots_status( (string) $r['url'] );
				if ( (int) $robots['blocked'] !== (int) $r['robots_txt_blocked'] ) {
					$this->urls->update( (int) $r['id'], array( 'robots_txt_blocked' => $robots['blocked'] ) );
					$fresh = $this->urls->get( (int) $r['id'] );
					if ( $fresh ) {
						$details           = get_transient( 'searchpilot_crawl_' . (int) $r['id'] );
						$details           = is_array( $details ) ? $details : array();
						$details['robots'] = $robots;
						$this->detector->detect( $fresh, $details );
					}
					++$changed;
				}
			}
		}
		$this->state->set( self::ROBOTS_PASS, $pass );
		return $changed;
	}
}
