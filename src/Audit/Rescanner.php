<?php
/**
 * Re-scans posts after they change (contract C3).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Audit;

use SearchPilot\Container;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\Crawler;
use SearchPilot\Indexer\RegistrySync;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Links\LinksModule;
use SearchPilot\Seo\Analysis\AnalysisModule;

defined( 'ABSPATH' ) || exit;

/**
 * Re-checks one post the way search engines see it now and rewrites its
 * issues from that fresh evidence:
 *
 * 1. the live page is fetched again past page caches (Crawler::check_row with
 *    CacheBuster), which re-runs every URL check and lets the crawl listeners
 *    (H1 count, schema, rendered head) record the fresh page;
 * 2. the content analysis runs again (AnalysisModule::analyze_post());
 * 3. the link checks run again from the live link graph (LinksModule::refresh_link_issues()).
 *
 * Each step upserts what it still finds — with data from this scan — and
 * resolves what it no longer finds, so issue data and "Seen" times never stay
 * frozen at an old snapshot. The result lists the issue types open on the post
 * afterwards; the fix engine compares them with the types open before a fix.
 *
 * Large sets go to a background queue (one deduplicated Action Scheduler
 * event that re-queues itself until the queue is empty and resumes after
 * interruptions, rate limits or crawl pauses).
 */
class Rescanner {

	/**
	 * Background hook that drains the queue.
	 */
	const HOOK = 'searchpilot_rescan_drain';

	/**
	 * Background hook of the audit freshness pass (after the daily full audit).
	 */
	const AUDIT_HOOK = 'searchpilot_audit_refresh';

	/**
	 * Option holding the queue (not autoloaded).
	 */
	const OPTION = 'searchpilot_rescan_queue';

	/**
	 * Lock held while the queue is drained.
	 */
	const LOCK = 'rescan';

	/**
	 * Largest set re-scanned within a REST request; larger sets are queued.
	 */
	const SYNC_MAX = 10;

	/**
	 * Most explicit ids the queue holds (the whole-site mode walks post ids instead).
	 */
	const QUEUE_MAX = 5000;

	/**
	 * Most posts a user without the manage capability may re-scan per request
	 * (every re-scan is an uncached full page render; larger sets are an
	 * administrator decision, like re-scanning the whole site).
	 */
	const EDITOR_MAX = 25;

	/**
	 * Seconds a crawl step needs at least (fetch timeout plus analysis).
	 */
	const CRAWL_SECONDS = 8;

	/**
	 * Tries per queued post before it is dropped (also counts runs that died
	 * mid-scan, e.g. on a fatal error, so one bad post never blocks the queue).
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Crawl outcomes that say nothing about the post: it stays queued, nothing else runs.
	 */
	const BLOCKED = array( 'rate_limited', 'paused', 'budget' );

	/**
	 * Container (modules are resolved lazily).
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Registry sync.
	 *
	 * @var RegistrySync
	 */
	private $registry;

	/**
	 * Crawler.
	 *
	 * @var Crawler
	 */
	private $crawler;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Posts whose live page was re-checked in this request: id => Unix time.
	 *
	 * @var array<int,int>
	 */
	private static $scanned = array();

	/**
	 * Constructor.
	 *
	 * @param Container     $container Container.
	 * @param UrlRepository $urls      URL registry.
	 * @param RegistrySync  $registry  Registry sync.
	 * @param Crawler       $crawler   Crawler.
	 * @param RateLimiter   $limiter   Rate limiter.
	 * @param Scheduler     $scheduler Scheduler.
	 * @param ContentTypes  $types     Content types.
	 * @param Logger        $logger    Logger.
	 */
	public function __construct( Container $container, UrlRepository $urls, RegistrySync $registry, Crawler $crawler, RateLimiter $limiter, Scheduler $scheduler, ContentTypes $types, Logger $logger ) {
		$this->container = $container;
		$this->urls      = $urls;
		$this->registry  = $registry;
		$this->crawler   = $crawler;
		$this->limiter   = $limiter;
		$this->scheduler = $scheduler;
		$this->types     = $types;
		$this->logger    = $logger;
	}

	/* --------------------------------------------------------------------- *
	 * One post
	 * --------------------------------------------------------------------- */

	/**
	 * Re-scans one post now (synchronously, within the time budget).
	 *
	 * @param int   $post_id Post (or attachment) id.
	 * @param array $opts    {
	 *     @type TimeBudget $budget   Budget shared with the caller (default: a new 40-second budget).
	 *     @type bool       $crawl    Re-fetch the live page (default true).
	 *     @type bool       $analysis Re-run the content analysis (default true).
	 *     @type bool       $links    Re-run the link checks (default true).
	 * }
	 * @return array {
	 *     @type bool     $checked    Every step that applies ran (false: see error; the page was not verified).
	 *     @type string[] $types      Issue types open on the post after the scan (sorted).
	 *     @type int      $resolved   Open issues of the post that were resolved by the scan.
	 *     @type int      $opened     Issues of the post that are open now but were not before.
	 *     @type string   $error      Human message ('' when everything ran).
	 *     @type int      $post_id    Post id.
	 *     @type string   $url        Clean URL that was checked ('' when the post has no public URL).
	 *     @type array    $stages     crawl: checked|not_public|removed|failed|paused|rate_limited|budget|skipped;
	 *                                analysis: analyzed|not_analyzable|failed|unavailable|skipped;
	 *                                links: refreshed|failed|unavailable|skipped.
	 *     @type int|null $score      Internal content score after the scan (null when not analyzed).
	 *     @type string   $grade      Grade of that score ('' when not analyzed).
	 *     @type string   $scanned_at ISO 8601 time of the scan.
	 * }
	 */
	public function rescan_post( $post_id, array $opts = array() ) {
		$post_id = (int) $post_id;
		$opts    = array_merge(
			array(
				'budget'   => null,
				'crawl'    => true,
				'analysis' => true,
				'links'    => true,
			),
			$opts
		);
		$budget  = $opts['budget'] instanceof TimeBudget ? $opts['budget'] : new TimeBudget( 40 );
		$result  = array(
			'checked'    => false,
			'types'      => array(),
			'resolved'   => 0,
			'opened'     => 0,
			'error'      => '',
			'post_id'    => $post_id,
			'url'        => '',
			'stages'     => array(
				'crawl'    => 'skipped',
				'analysis' => 'skipped',
				'links'    => 'skipped',
			),
			'score'      => null,
			'grade'      => '',
			'scanned_at' => gmdate( 'c' ),
		);
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			$result['error'] = __( 'The post no longer exists.', 'searchpilot-pro' );
			return $result;
		}
		$object_type = 'attachment' === $post->post_type ? 'attachment' : 'post';
		$before      = $this->open_issues( $object_type, $post_id );
		$errors      = array();
		$complete    = true;

		// 1. The live page, past page caches.
		if ( $opts['crawl'] ) {
			$crawl                     = $this->crawl_post( $post, $budget );
			$result['stages']['crawl'] = $crawl['status'];
			$result['url']             = $crawl['url'];
			if ( '' !== $crawl['error'] ) {
				$errors[] = $crawl['error'];
			}
			if ( ! in_array( $crawl['status'], array( 'checked', 'not_public', 'removed' ), true ) ) {
				$complete = false;
			}
			if ( in_array( $crawl['status'], self::BLOCKED, true ) ) {
				// Paused, rate-limited or out of time: the page could not be checked at all, so
				// re-analysing stored data would only cost time. The post stays unverified.
				$opts['analysis'] = false;
				$opts['links']    = false;
			}
		}

		// 2. Content analysis (reads the snapshot the crawl just stored).
		if ( $opts['analysis'] && 'post' === $object_type ) {
			$module = $this->module( AnalysisModule::class );
			if ( $module && method_exists( $module, 'analyze_post' ) ) {
				try {
					$analysis                     = $module->analyze_post( $post_id );
					$result['stages']['analysis'] = is_array( $analysis ) ? 'analyzed' : 'not_analyzable';
					if ( is_array( $analysis ) ) {
						$result['score'] = isset( $analysis['score'] ) ? (int) $analysis['score'] : null;
						$result['grade'] = isset( $analysis['grade'] ) ? (string) $analysis['grade'] : '';
					}
				} catch ( \Throwable $e ) {
					$result['stages']['analysis'] = 'failed';
					$complete                     = false;
					$errors[]                     = __( 'The content analysis failed; details are in the SearchPilot log.', 'searchpilot-pro' );
					$this->logger->warning( 'audit', sprintf( 'Re-scan: content analysis of post %d failed: %s', $post_id, $e->getMessage() ) );
				}
			} else {
				$result['stages']['analysis'] = 'unavailable';
			}
		}

		// 3. Link checks from the live link graph (the one the Internal Links screen shows).
		if ( $opts['links'] && 'post' === $object_type ) {
			$links = $this->module( LinksModule::class );
			if ( $links && method_exists( $links, 'refresh_link_issues' ) ) {
				try {
					$links->refresh_link_issues( array( $post_id ) );
					$result['stages']['links'] = 'refreshed';
				} catch ( \Throwable $e ) {
					$result['stages']['links'] = 'failed';
					$complete                  = false;
					$errors[]                  = __( 'The link checks failed; details are in the SearchPilot log.', 'searchpilot-pro' );
					$this->logger->warning( 'audit', sprintf( 'Re-scan: link checks of post %d failed: %s', $post_id, $e->getMessage() ) );
				}
			} else {
				$result['stages']['links'] = 'unavailable';
			}
		}

		$after = $this->open_issues( $object_type, $post_id );
		$types = array_values( array_unique( array_values( $after ) ) );
		sort( $types );
		$result['types']    = $types;
		$result['resolved'] = count( array_diff_key( $before, $after ) );
		$result['opened']   = count( array_diff_key( $after, $before ) );
		$result['checked']  = $complete;
		$result['error']    = implode( ' ', array_unique( $errors ) );
		if ( '' === $result['url'] && 'publish' === $post->post_status ) {
			$result['url'] = (string) get_permalink( $post );
		}
		if ( 'checked' === $result['stages']['crawl'] ) {
			self::$scanned[ $post_id ] = time();
		}
		return $result;
	}

	/**
	 * Whether this request already re-checked the post's live page within the
	 * last few minutes (callers skip their own follow-up checks then).
	 *
	 * @param int $post_id Post id.
	 * @param int $seconds Window.
	 * @return bool
	 */
	public static function scanned_recently( $post_id, $seconds = 300 ) {
		$at = self::$scanned[ (int) $post_id ] ?? 0;
		return $at > 0 && time() - $at <= (int) $seconds;
	}

	/**
	 * Re-fetches the post's public URL past page caches.
	 *
	 * @param \WP_Post   $post   Post.
	 * @param TimeBudget $budget Budget.
	 * @return array{status:string,error:string,url:string}
	 */
	private function crawl_post( \WP_Post $post, TimeBudget $budget ) {
		$object_type = 'attachment' === $post->post_type ? 'attachment' : 'post';
		$out         = array(
			'status' => 'skipped',
			'error'  => '',
			'url'    => '',
		);
		$row_id      = (int) $this->registry->sync_post( $post );
		if ( ! $row_id ) {
			// No public URL (draft, private, trashed, untracked type): nothing to fetch.
			// Rows left active by a missed event are retired with their issues.
			foreach ( $this->urls->all_for_object( $object_type, (int) $post->ID ) as $row ) {
				if ( 'active' === $row['status'] ) {
					$this->registry->forget_object( $object_type, (int) $post->ID, false );
					break;
				}
			}
			$out['status'] = 'not_public';
			return $out;
		}
		$row        = $this->urls->get( $row_id );
		$out['url'] = $row ? (string) $row['url'] : '';
		if ( ! $row ) {
			$out['status'] = 'failed';
			$out['error']  = __( 'The page could not be found in the URL registry.', 'searchpilot-pro' );
			return $out;
		}
		$paused = $this->crawler->paused_until();
		if ( $paused ) {
			$out['status'] = 'paused';
			/* translators: %s: time the pause ends. */
			$out['error'] = sprintf( __( 'Page checks are paused until %s because SearchPilot could not reach the site’s own pages. The page was not re-checked.', 'searchpilot-pro' ), wp_date( get_option( 'time_format', 'H:i' ), $paused ) );
			return $out;
		}
		if ( $budget->remaining() < self::CRAWL_SECONDS ) {
			$out['status'] = 'budget';
			$out['error']  = __( 'There was not enough time left in this request to fetch the page. It will be re-checked again shortly.', 'searchpilot-pro' );
			return $out;
		}
		if ( ! $this->limiter->attempt( 'self_audit' ) ) {
			$out['status'] = 'rate_limited';
			$out['error']  = __( 'SearchPilot already checked many pages in the last minute; the page was not re-fetched. Try again in a minute.', 'searchpilot-pro' );
			return $out;
		}
		$checked = $this->crawler->check_row( $row, $budget, array( 'bust_cache' => true ) );
		if ( is_wp_error( $checked ) ) {
			$out['status'] = 'searchpilot_url_removed' === $checked->get_error_code() ? 'removed' : 'failed';
			$out['error']  = 'removed' === $out['status'] ? '' : $checked->get_error_message();
			return $out;
		}
		$out['status'] = 'checked';
		$out['url']    = isset( $checked['row']['url'] ) ? (string) $checked['row']['url'] : $out['url'];
		return $out;
	}

	/**
	 * Open issues of an object: id => type.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return array<int,string>
	 */
	private function open_issues( $object_type, $object_id ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type FROM {$table} WHERE object_type = %s AND object_id = %d AND status IN ('open','pending_approval')", (string) $object_type, (int) $object_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['type'];
		}
		return $out;
	}

	/**
	 * An active module instance (null when the module is switched off).
	 *
	 * @param string $class Module class.
	 * @return object|null
	 */
	private function module( $class ) {
		if ( ! class_exists( $class ) || ! \SearchPilot\Plugin::instance()->has_module( $class ) ) {
			return null;
		}
		try {
			return $this->container->get( $class );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/* --------------------------------------------------------------------- *
	 * Batches
	 * --------------------------------------------------------------------- */

	/**
	 * Re-scans several posts until the budget is used.
	 *
	 * @param int[]      $post_ids Post ids.
	 * @param TimeBudget $budget   Budget.
	 * @return array{results:array<int,array>,remaining:int[],complete:bool}
	 *         results: post id => rescan_post() result; remaining: ids not reached.
	 */
	public function rescan_objects( array $post_ids, TimeBudget $budget ) {
		$ids     = self::clean_ids( $post_ids );
		$results = array();
		$left    = array();
		foreach ( $ids as $index => $id ) {
			if ( $budget->remaining() < self::CRAWL_SECONDS + 2 ) {
				$left = array_slice( $ids, $index );
				break;
			}
			$results[ $id ] = $this->rescan_post( $id, array( 'budget' => $budget ) );
		}
		return array(
			'results'   => $results,
			'remaining' => array_values( $left ),
			'complete'  => ! $left,
		);
	}

	/**
	 * Refuses re-scan requests larger than EDITOR_MAX posts from users who
	 * cannot manage SearchPilot.
	 *
	 * @param int[] $ids Clean post ids of the request.
	 * @return \WP_Error|null Error (HTTP 403) or null when allowed.
	 */
	public static function request_size_error( array $ids ) {
		if ( count( $ids ) <= self::EDITOR_MAX || \SearchPilot\Core\Capabilities::can_manage() ) {
			return null;
		}
		return new \WP_Error(
			'searchpilot_too_many_posts',
			sprintf(
				/* translators: 1: number of posts requested, 2: maximum. */
				__( 'You asked to re-scan %1$d posts. Editors can re-scan up to %2$d posts at a time; larger sets and the whole site can only be re-scanned by an administrator.', 'searchpilot-pro' ),
				count( $ids ),
				self::EDITOR_MAX
			),
			array(
				'status' => 403,
				'max'    => self::EDITOR_MAX,
			)
		);
	}

	/**
	 * Unique positive ids.
	 *
	 * @param array $ids Ids.
	 * @return int[]
	 */
	public static function clean_ids( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			// Never absint(): "-3" must not turn into post 3.
			if ( is_scalar( $id ) && is_numeric( $id ) && (int) $id > 0 && (string) (int) $id === trim( (string) $id ) ) {
				$out[ (int) $id ] = (int) $id;
			}
		}
		return array_values( $out );
	}

	/* --------------------------------------------------------------------- *
	 * Background queue
	 * --------------------------------------------------------------------- */

	/**
	 * Queues posts for a background re-scan (deduplicated; resumes until done).
	 *
	 * @param int[] $post_ids Post ids.
	 * @return int Posts newly queued (ids already waiting are not counted again).
	 */
	public function queue_rescan( array $post_ids ) {
		$ids   = self::clean_ids( $post_ids );
		$added = 0;
		if ( $ids ) {
			$this->mutate_queue(
				static function ( array $q ) use ( $ids, &$added ) {
					if ( ! $q['ids'] && empty( $q['all'] ) ) {
						$q = self::fresh_queue();
					}
					$waiting = array_flip( $q['ids'] );
					foreach ( $ids as $id ) {
						if ( isset( $waiting[ $id ] ) || count( $q['ids'] ) >= self::QUEUE_MAX ) {
							continue;
						}
						$q['ids'][]     = $id;
						$waiting[ $id ] = true;
						++$added;
					}
					$q['total'] += $added;
					return $q;
				}
			);
		}
		if ( $added ) {
			$this->scheduler->enqueue_async( self::HOOK, array(), 10 );
		}
		return $added;
	}

	/**
	 * Queues a re-scan of every published post of the configured post types
	 * (walks post ids upwards; explicit ids queued meanwhile go first).
	 *
	 * @return int Estimated number of posts.
	 */
	public function queue_all() {
		$total = 0;
		foreach ( array_keys( $this->types->post_types() ) as $type ) {
			$counts = wp_count_posts( $type );
			$total += (int) ( $counts->publish ?? 0 );
		}
		$this->mutate_queue(
			static function ( array $q ) use ( $total ) {
				if ( ! $q['ids'] && empty( $q['all'] ) ) {
					$q = self::fresh_queue();
				}
				if ( empty( $q['all'] ) ) {
					$q['total'] += $total;
				}
				$q['all']    = true;
				$q['cursor'] = 0;
				return $q;
			}
		);
		$this->scheduler->enqueue_async( self::HOOK, array(), 10 );
		return $total;
	}

	/**
	 * Empties the queue (posts already re-scanned keep their results).
	 *
	 * @return int Posts that were still waiting (estimate for the whole-site mode).
	 */
	public function cancel() {
		$waiting = (int) $this->queue_status()['pending'];
		$this->mutate_queue(
			static function ( array $q ) {
				$q['ids']         = array();
				$q['attempts']    = array();
				$q['all']         = false;
				$q['cursor']      = 0;
				$q['finished_at'] = gmdate( 'c' );
				return $q;
			}
		);
		return $waiting;
	}

	/**
	 * Whether queued work is waiting.
	 *
	 * @return bool
	 */
	public function has_pending() {
		$q = $this->read_queue();
		return ! empty( $q['ids'] ) || ! empty( $q['all'] );
	}

	/**
	 * Queue progress for the UI.
	 *
	 * @return array{pending:int,all:bool,total:int,done:int,failed:int,dropped:int,running:bool,started_at:?string,updated_at:?string,finished_at:?string,next_run:?string}
	 *         failed: posts that could not be verified (dropped after MAX_ATTEMPTS tries; included in failed).
	 */
	public function queue_status() {
		$q    = $this->read_queue();
		$next = Scheduler::next_run( self::HOOK );
		return array(
			'pending'     => count( $q['ids'] ) + ( ! empty( $q['all'] ) ? max( 0, (int) $q['total'] - (int) $q['done'] - count( $q['ids'] ) ) : 0 ),
			'all'         => ! empty( $q['all'] ),
			'total'       => (int) $q['total'],
			'done'        => (int) $q['done'],
			'failed'      => (int) $q['failed'],
			'dropped'     => (int) $q['dropped'],
			'running'     => Lock::held( self::LOCK ),
			'started_at'  => $q['started_at'] ? (string) $q['started_at'] : null,
			'updated_at'  => $q['updated_at'] ? (string) $q['updated_at'] : null,
			'finished_at' => $q['finished_at'] ? (string) $q['finished_at'] : null,
			'next_run'    => $next ? gmdate( 'c', (int) $next ) : null,
		);
	}

	/**
	 * Background event: re-scans queued posts one by one within a time budget
	 * and re-queues itself while work remains.
	 *
	 * - While self-checks are paused it does nothing and wakes up when the
	 *   pause ends (no analysis or link work is spent on posts it cannot check).
	 * - Posts blocked by the rate limit or the time budget stay queued.
	 * - Every attempt is counted before the scan starts; a post that could not
	 *   be verified is retried later, and after MAX_ATTEMPTS tries (including
	 *   runs that died mid-scan) it is dropped with a log entry.
	 */
	public function drain() {
		$more = Lock::run(
			self::LOCK,
			300,
			function () {
				$budget = new TimeBudget( 20 );
				while ( $budget->remaining() >= self::CRAWL_SECONDS + 2 ) {
					$paused = $this->crawler->paused_until();
					if ( $paused ) {
						return max( 60, $paused - time() + 30 );
					}
					$next = $this->claim_next();
					if ( null === $next ) {
						$this->finish_queue();
						return 0;
					}
					$id     = $next['id'];
					$result = $this->rescan_post( $id, array( 'budget' => $budget ) );
					$stage  = (string) $result['stages']['crawl'];
					if ( in_array( $stage, self::BLOCKED, true ) ) {
						// Not this post's fault: give the attempt back and try later.
						$this->settle( $id, $next['from_cursor'], 'blocked' );
						return 'budget' === $stage ? 15 : 60;
					}
					if ( ! empty( $result['checked'] ) || ! get_post( $id ) ) {
						$this->settle( $id, $next['from_cursor'], 'done' );
						continue;
					}
					if ( $next['attempt'] >= self::MAX_ATTEMPTS ) {
						$this->logger->warning( 'audit', sprintf( 'Re-scan of post %d was dropped from the queue after %d attempts: %s', $id, $next['attempt'], '' !== $result['error'] ? $result['error'] : 'not verified' ) );
						$this->settle( $id, $next['from_cursor'], 'failed' );
						continue;
					}
					$this->settle( $id, $next['from_cursor'], 'retry' );
				}
				if ( ! $this->has_pending() ) {
					$this->finish_queue();
					return 0;
				}
				return 15;
			}
		);
		if ( null === $more ) {
			$this->scheduler->enqueue_async( self::HOOK, array(), 60 );
		} elseif ( $more > 0 ) {
			$this->scheduler->enqueue_async( self::HOOK, array(), (int) $more );
		}
	}

	/**
	 * Picks the next post (explicit ids first, then the whole-site cursor) and
	 * records the attempt before the scan starts. Posts already tried
	 * MAX_ATTEMPTS times (e.g. the scan died on them) are dropped with a log entry.
	 *
	 * @return array{id:int,from_cursor:bool,attempt:int}|null Null when nothing is queued.
	 */
	private function claim_next() {
		for ( $guard = 0; $guard < 100; $guard++ ) {
			$q = $this->read_queue();
			if ( $q['ids'] ) {
				$id          = (int) $q['ids'][0];
				$from_cursor = false;
			} elseif ( ! empty( $q['all'] ) ) {
				$id          = $this->next_cursor_id( (int) $q['cursor'] );
				$from_cursor = true;
				if ( ! $id ) {
					$this->mutate_queue(
						static function ( array $q ) {
							$q['all']    = false;
							$q['cursor'] = 0;
							return $q;
						}
					);
					return null;
				}
			} else {
				return null;
			}
			$tries = (int) ( $q['attempts'][ $id ] ?? 0 );
			if ( $tries >= self::MAX_ATTEMPTS ) {
				$this->logger->warning( 'audit', sprintf( 'Re-scan of post %d was dropped from the queue: %d earlier attempts did not finish.', $id, $tries ) );
				$this->settle( $id, $from_cursor, 'failed' );
				continue;
			}
			$this->mutate_queue(
				static function ( array $q ) use ( $id, $tries ) {
					$q['attempts'][ $id ] = $tries + 1;
					return $q;
				}
			);
			return array(
				'id'          => $id,
				'from_cursor' => $from_cursor,
				'attempt'     => $tries + 1,
			);
		}
		return null;
	}

	/**
	 * First published post of the configured types after an id.
	 *
	 * @param int $after Cursor.
	 * @return int 0 when the walk is complete.
	 */
	private function next_cursor_id( $after ) {
		$types = array_keys( $this->types->post_types() );
		if ( ! $types ) {
			return 0;
		}
		global $wpdb;
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$holders}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 1", array_merge( $types, array( (int) $after ) ) ) );
	}

	/**
	 * Records the outcome of one attempt.
	 *
	 * @param int    $id          Post id.
	 * @param bool   $from_cursor The id came from the whole-site cursor.
	 * @param string $outcome     done|failed|retry|blocked.
	 */
	private function settle( $id, $from_cursor, $outcome ) {
		$id = (int) $id;
		$this->mutate_queue(
			static function ( array $q ) use ( $id, $from_cursor, $outcome ) {
				if ( 'blocked' === $outcome ) {
					$q['attempts'][ $id ] = max( 0, (int) ( $q['attempts'][ $id ] ?? 0 ) - 1 );
					return $q;
				}
				if ( 'retry' === $outcome ) {
					if ( ! $from_cursor ) {
						// To the back of the queue: the others go first.
						$q['ids']   = array_values( array_diff( $q['ids'], array( $id ) ) );
						$q['ids'][] = $id;
					}
					return $q;
				}
				if ( $from_cursor ) {
					$q['cursor'] = max( (int) $q['cursor'], $id );
				} else {
					$q['ids'] = array_values( array_diff( $q['ids'], array( $id ) ) );
				}
				unset( $q['attempts'][ $id ] );
				++$q['done'];
				if ( 'failed' === $outcome ) {
					++$q['failed'];
					++$q['dropped'];
				}
				return $q;
			}
		);
	}

	/**
	 * Marks the queue finished (counters stay for the progress display).
	 */
	private function finish_queue() {
		$this->mutate_queue(
			static function ( array $q ) {
				if ( ! $q['ids'] && empty( $q['all'] ) && empty( $q['finished_at'] ) && ! empty( $q['started_at'] ) ) {
					$q['finished_at'] = gmdate( 'c' );
				}
				return $q;
			}
		);
	}

	/**
	 * Empty queue.
	 *
	 * @return array
	 */
	private static function fresh_queue() {
		return array(
			'ids'         => array(),
			'all'         => false,
			'cursor'      => 0,
			'total'       => 0,
			'done'        => 0,
			'failed'      => 0,
			'dropped'     => 0,
			'attempts'    => array(),
			'started_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
			'finished_at' => null,
		);
	}

	/**
	 * Current queue (read from the database, never from a stale cache).
	 *
	 * @return array
	 */
	private function read_queue() {
		wp_cache_delete( self::OPTION, 'options' );
		$q = get_option( self::OPTION, array() );
		$q = is_array( $q ) ? array_merge( self::fresh_queue(), array( 'started_at' => null ), $q ) : array_merge( self::fresh_queue(), array( 'started_at' => null ) );
		$q['ids']      = self::clean_ids( (array) $q['ids'] );
		$q['attempts'] = self::clean_attempts( (array) $q['attempts'], $q );
		return $q;
	}

	/**
	 * Attempt counters (post id => tries) of posts that are still queued.
	 *
	 * @param array $attempts Stored counters.
	 * @param array $q        Queue.
	 * @return array<int,int>
	 */
	private static function clean_attempts( array $attempts, array $q ) {
		$out     = array();
		$waiting = array_flip( (array) $q['ids'] );
		foreach ( $attempts as $id => $tries ) {
			$id = (int) $id;
			// Cursor ids are kept too (the whole-site walk has no id list).
			if ( $id > 0 && (int) $tries > 0 && ( isset( $waiting[ $id ] ) || ! empty( $q['all'] ) ) ) {
				$out[ $id ] = min( 99, (int) $tries );
			}
		}
		return $out;
	}

	/**
	 * Read-modify-write of the queue under a short lock (concurrent requests
	 * must not lose ids).
	 *
	 * @param callable $change Receives and returns the queue array.
	 */
	private function mutate_queue( callable $change ) {
		$locked = false;
		for ( $i = 0; $i < 30; $i++ ) {
			if ( Lock::acquire( 'rescan_queue', 15 ) ) {
				$locked = true;
				break;
			}
			usleep( 100000 );
		}
		try {
			$q               = call_user_func( $change, $this->read_queue() );
			$q['ids']        = self::clean_ids( (array) $q['ids'] );
			$q['attempts']   = self::clean_attempts( (array) ( $q['attempts'] ?? array() ), $q );
			$q['updated_at'] = gmdate( 'c' );
			update_option( self::OPTION, $q, false );
		} finally {
			if ( $locked ) {
				Lock::release( 'rescan_queue' );
			}
		}
		if ( ! $locked ) {
			$this->logger->warning( 'audit', 'The re-scan queue was updated without its lock (another request held it for 3 seconds).' );
		}
	}

	/* --------------------------------------------------------------------- *
	 * "Run audit now" freshness
	 * --------------------------------------------------------------------- */

	/**
	 * Brings the audit's issue rows up to date beyond the site-wide monitor
	 * checks: retires rows and issues of deleted/unpublished content, re-runs
	 * the link checks from the live link graph (so the audit and the Internal
	 * Links screen agree), optionally crawls a batch of due pages, and
	 * re-analyzes only posts whose analysis is out of date (edited, re-crawled
	 * or with issue data older than the post since). Unchanged posts are not
	 * re-analyzed. What does not fit in the budget is queued for the background
	 * analysis.
	 *
	 * @param TimeBudget $budget Budget.
	 * @param bool       $crawl  Also crawl one batch of due pages.
	 * @return array{registry:array,links:array|null,crawl:array|null,analysis:array{analyzed:int,queued:int},deferred:bool}
	 *         deferred: the link checks did not fit and run in the background shortly.
	 */
	public function refresh_audit( TimeBudget $budget, $crawl = true ) {
		$out = array(
			'registry' => array(
				'rows'   => 0,
				'issues' => 0,
				'done'   => false,
			),
			'links'    => null,
			'crawl'    => null,
			'analysis' => array(
				'analyzed' => 0,
				'queued'   => 0,
			),
		);

		$deferred = false;

		// 1. Rows and issues of content that no longer exists (a few seconds at most).
		if ( $budget->remaining() > 10 ) {
			$seconds  = (int) min( 5, floor( $budget->remaining() - 5 ) );
			$registry = Lock::run(
				'registry',
				120,
				function () use ( $seconds ) {
					return $this->registry->cleanup_orphans( new TimeBudget( $seconds ) );
				}
			);
			if ( is_array( $registry ) ) {
				$out['registry'] = $registry;
			}
		} else {
			RegistrySync::request_cleanup();
		}

		// 2. Link checks from the live graph (empty list = every open link issue + orphan sweep),
		// so the audit and the Internal Links screen always agree.
		$links = $this->module( LinksModule::class );
		if ( $links && $budget->exhausted( 3 ) ) {
			$deferred = true;
		} elseif ( $links ) {
			try {
				if ( method_exists( $links, 'refresh_link_issues' ) ) {
					// The caller's budget bounds the whole-site pass (an empty id list).
					$refreshed    = $links->refresh_link_issues( array(), $budget );
					$out['links'] = is_array( $refreshed ) ? $refreshed : array();
				} elseif ( method_exists( $links, 'orphan_sweep' ) ) {
					$links->orphan_sweep();
					$out['links'] = array();
				}
			} catch ( \Throwable $e ) {
				$this->logger->warning( 'audit', 'Audit refresh: link checks failed: ' . $e->getMessage() );
			}
		}

		// 3. One batch of due pages (half of what is left, at most 20 seconds).
		if ( $crawl && $budget->remaining() > 15 ) {
			$out['crawl'] = $this->crawler->run_batch( (int) min( 20, floor( ( $budget->remaining() - 5 ) / 2 ) ), 0, 'manual' );
		}

		// 4. Content analysis of posts whose analysis is out of date.
		$module = $this->module( AnalysisModule::class );
		if ( $module && method_exists( $module, 'run_analyze_post' ) ) {
			$ids = $this->analysis_candidates( 1000 );
			foreach ( $ids as $index => $id ) {
				if ( $budget->exhausted( 3 ) ) {
					$rest = array_slice( $ids, $index );
					if ( $rest && method_exists( $module, 'queue' ) ) {
						$module->queue( $rest );
						$out['analysis']['queued'] = count( $rest );
					}
					break;
				}
				$module->run_analyze_post( $id );
				++$out['analysis']['analyzed'];
			}
		}

		// Whatever did not fit runs in a background request of its own.
		if ( $deferred ) {
			$this->scheduler->enqueue_async( self::AUDIT_HOOK, array(), 30 );
		}
		$out['deferred'] = $deferred;
		return $out;
	}

	/**
	 * Posts whose content analysis is out of date, at most $limit:
	 *
	 * 1. published posts edited after their last analysis, or never analyzed;
	 * 2. posts whose page was crawled after their last analysis (the analysis
	 *    has not seen the latest rendered head yet);
	 * 3. posts with open content issues last seen before the post's latest edit
	 *    (the issue data describes an older version of the post).
	 *
	 * Posts that did not change since their analysis are left alone, however
	 * many issues they have.
	 *
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	private function analysis_candidates( $limit ) {
		global $wpdb;
		$limit      = max( 1, (int) $limit );
		$ids        = array();
		$post_types = array_keys( $this->types->post_types() );

		// 1. Edited since the analysis, or never analyzed.
		$repo_class = '\SearchPilot\Seo\Analysis\AnalysisRepository';
		if ( $post_types && class_exists( $repo_class ) ) {
			$repo = $this->container->get( $repo_class );
			if ( is_object( $repo ) && method_exists( $repo, 'backlog_ids' ) ) {
				$ids = array_map( 'intval', (array) $repo->backlog_ids( $post_types, 0, $limit ) );
			}
		}

		// 2. Crawled after the analysis (the recheck marker date is not a crawl).
		if ( count( $ids ) < $limit ) {
			$urls    = Tables::urls();
			$content = Tables::content();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$more = $wpdb->get_col( $wpdb->prepare( "SELECT u.object_id FROM {$urls} u INNER JOIN {$content} c ON c.post_id = u.object_id WHERE u.object_type = 'post' AND u.status = 'active' AND u.last_checked > %s AND c.analyzed_at IS NOT NULL AND u.last_checked > c.analyzed_at ORDER BY u.last_checked DESC LIMIT %d", Crawler::RECHECK_MARKER, $limit - count( $ids ) ) );
			$ids  = array_merge( $ids, array_map( 'intval', (array) $more ) );
		}

		// 3. Open content issues whose data predates the post's latest edit.
		$types = defined( AnalysisModule::class . '::ISSUE_TYPES' ) ? (array) AnalysisModule::ISSUE_TYPES : array();
		if ( $types && count( $ids ) < $limit ) {
			$table   = Tables::issues();
			$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$more = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT i.object_id FROM {$table} i INNER JOIN {$wpdb->posts} p ON p.ID = i.object_id WHERE i.object_type = 'post' AND i.status IN ('open','pending_approval') AND i.type IN ({$holders}) AND p.post_status = 'publish' AND i.last_seen_at < p.post_modified_gmt LIMIT %d", array_merge( $types, array( $limit - count( $ids ) ) ) ) );
			$ids  = array_merge( $ids, array_map( 'intval', (array) $more ) );
		}
		return array_slice( self::clean_ids( $ids ), 0, $limit );
	}
}
