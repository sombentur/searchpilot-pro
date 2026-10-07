<?php
/**
 * Background submission worker.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

use SearchPilot\Api\Bing\BingWebmaster;
use SearchPilot\Api\Google\IndexingApi;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\IndexNow\IndexNow;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on `searchpilot_every_5min` and `searchpilot_process_queue` under
 * Lock "queue" with a 25-second TimeBudget:
 *
 *   1. recover rows stuck in `processing` for more than 15 minutes;
 *   2. if the environment guard blocks submissions, hold every pending row;
 *   3. claim due rows per service (atomic claim by batch id) and send them —
 *      IndexNow as one request, Bing in ≤500-URL batches within the daily
 *      quota, the Indexing API one by one within its quota, sitemaps one
 *      each;
 *   4. success → done (+ UrlRepository last_submitted/engines), retryable
 *      failure → exponential back-off (Backoff), permanent failure → failed.
 */
class QueueWorker {

	const LOCK = 'queue';

	const ISSUE = 'submissions_failing';

	const FAILURE_THRESHOLD = 20;

	/**
	 * Service → RateLimiter key.
	 */
	const BUDGETS = array(
		'indexnow'        => 'indexnow',
		'bing_url'        => 'bing_url',
		'bing_sitemap'    => 'bing_api',
		'google_indexing' => 'google_indexing',
		'gsc_sitemap'     => 'google_sitemaps',
	);

	/**
	 * Service → log channel.
	 */
	const CHANNELS = array(
		'indexnow'        => 'indexnow',
		'bing_url'        => 'bing',
		'bing_sitemap'    => 'bing',
		'google_indexing' => 'google',
		'gsc_sitemap'     => 'google',
	);

	/**
	 * Repository.
	 *
	 * @var QueueRepository
	 */
	private $repo;

	/**
	 * Submission queue (decisions, environment).
	 *
	 * @var SubmissionQueue
	 */
	private $queue;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * IndexNow.
	 *
	 * @var IndexNow
	 */
	private $indexnow;

	/**
	 * Bing.
	 *
	 * @var BingWebmaster
	 */
	private $bing;

	/**
	 * Indexing API.
	 *
	 * @var IndexingApi
	 */
	private $indexing;

	/**
	 * Search Console.
	 *
	 * @var SearchConsole
	 */
	private $gsc;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param QueueRepository $repo     Repository.
	 * @param SubmissionQueue $queue    Queue.
	 * @param Settings        $settings Settings.
	 * @param IndexNow        $indexnow IndexNow.
	 * @param BingWebmaster   $bing     Bing.
	 * @param IndexingApi     $indexing Indexing API.
	 * @param SearchConsole   $gsc      Search Console.
	 * @param RateLimiter     $limiter  Rate limiter.
	 * @param UrlRepository   $urls     URL registry.
	 * @param IssueRepository $issues   Issues.
	 * @param Logger          $logger   Logger.
	 */
	public function __construct( QueueRepository $repo, SubmissionQueue $queue, Settings $settings, IndexNow $indexnow, BingWebmaster $bing, IndexingApi $indexing, SearchConsole $gsc, RateLimiter $limiter, UrlRepository $urls, IssueRepository $issues, Logger $logger ) {
		$this->repo     = $repo;
		$this->queue    = $queue;
		$this->settings = $settings;
		$this->indexnow = $indexnow;
		$this->bing     = $bing;
		$this->indexing = $indexing;
		$this->gsc      = $gsc;
		$this->limiter  = $limiter;
		$this->urls     = $urls;
		$this->issues   = $issues;
		$this->logger   = $logger;
	}

	/**
	 * Runs one pass under the queue lock.
	 *
	 * @param int $seconds Time budget.
	 * @return array|null Stats, or null when another worker holds the lock.
	 */
	public function run( $seconds = 25 ) {
		return Lock::run(
			self::LOCK,
			300,
			function () use ( $seconds ) {
				return $this->process( new TimeBudget( $seconds ) );
			}
		);
	}

	/**
	 * Processes due rows until the budget is used.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array{recovered:int,held:int,done:int,retry:int,failed:int,skipped:int,requests:int}
	 */
	public function process( TimeBudget $budget ) {
		$stats = array(
			'recovered' => $this->repo->recover_stale( 15 ),
			'held'      => 0,
			'done'      => 0,
			'retry'     => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'requests'  => 0,
		);

		$block = $this->queue->block_reason();
		if ( '' !== $block ) {
			$stats['held'] = $this->repo->hold_pending( $block );
			if ( $stats['held'] ) {
				$this->logger->info( 'queue', sprintf( 'Submissions are held (%s): %d pending row(s) moved to held.', $block, $stats['held'] ) );
			}
			return $stats;
		}

		foreach ( array( 'gsc_sitemap', 'bing_sitemap', 'indexnow', 'bing_url', 'google_indexing' ) as $service ) {
			if ( $budget->exhausted( 3.0 ) ) {
				break;
			}
			switch ( $service ) {
				case 'indexnow':
					$this->process_indexnow( $budget, $stats );
					break;
				case 'bing_url':
					$this->process_bing( $budget, $stats );
					break;
				case 'google_indexing':
					$this->process_google_indexing( $budget, $stats );
					break;
				default:
					$this->process_sitemaps( $service, $budget, $stats );
			}
		}

		if ( $stats['done'] || $stats['failed'] || $stats['retry'] || $stats['skipped'] ) {
			$this->logger->info( 'queue', 'Submission queue processed.', $stats );
		}
		$this->check_failure_rate();
		return $stats;
	}

	/**
	 * IndexNow: all due URLs in one request (≤10,000; claimed in chunks of 2,000).
	 *
	 * @param TimeBudget $budget Budget.
	 * @param array      $stats  Stats (by reference).
	 */
	private function process_indexnow( TimeBudget $budget, array &$stats ) {
		while ( ! $budget->exhausted( 3.0 ) ) {
			if ( ! $this->indexnow->is_active() ) {
				$message = $this->indexnow->is_enabled()
					/* translators: %s: plugin name. */
					? sprintf( __( 'Not sent: %s already submits IndexNow notifications.', 'searchpilot-pro' ), $this->indexnow->owner_name() )
					: __( 'Not sent: IndexNow is switched off.', 'searchpilot-pro' );
				$this->skip_due( 'indexnow', $message, $stats );
				return;
			}
			if ( $this->limiter->blocked_until( 'indexnow' ) ) {
				return;
			}
			$rows = $this->repo->claim( 'indexnow', 2000, self::batch_id() );
			if ( empty( $rows ) ) {
				return;
			}
			$urls   = array_values( array_unique( array_column( $rows, 'url' ) ) );
			$result = $this->indexnow->submit( $urls );
			++$stats['requests'];
			$this->finish( 'indexnow', $rows, $result, $stats );
			if ( is_wp_error( $result ) ) {
				return;
			}
		}
	}

	/**
	 * Bing URL Submission API: batches of ≤500 within the daily quota.
	 *
	 * @param TimeBudget $budget Budget.
	 * @param array      $stats  Stats (by reference).
	 */
	private function process_bing( TimeBudget $budget, array &$stats ) {
		while ( ! $budget->exhausted( 3.0 ) ) {
			if ( ! $this->settings->get( 'indexing.bing_url_submission', false ) || ! $this->bing->is_connected() ) {
				$this->skip_due( 'bing_url', __( 'Not sent: Bing URL submission is switched off or Bing is not connected.', 'searchpilot-pro' ), $stats );
				return;
			}
			if ( $this->limiter->blocked_until( 'bing_url' ) ) {
				return;
			}
			$remaining = min( BingWebmaster::MAX_BATCH, $this->limiter->remaining_today( 'bing_url' ) );
			if ( $remaining < 1 ) {
				return;
			}
			$rows = $this->repo->claim( 'bing_url', $remaining, self::batch_id() );
			if ( empty( $rows ) ) {
				return;
			}
			$urls   = array_values( array_unique( array_column( $rows, 'url' ) ) );
			$result = $this->bing->submit_url_batch( $urls );
			++$stats['requests'];
			$this->finish( 'bing_url', $rows, $result, $stats );
			if ( is_wp_error( $result ) ) {
				return;
			}
		}
	}

	/**
	 * Google Indexing API: one URL per request, only eligible pages, within quota.
	 *
	 * @param TimeBudget $budget Budget.
	 * @param array      $stats  Stats (by reference).
	 */
	private function process_google_indexing( TimeBudget $budget, array &$stats ) {
		if ( ! $this->indexing->is_enabled() ) {
			$this->skip_due( 'google_indexing', __( 'Not sent: the Google Indexing API is switched off or not authorized.', 'searchpilot-pro' ), $stats );
			return;
		}
		if ( $this->limiter->blocked_until( 'google_indexing' ) ) {
			return;
		}
		$limit = min( 50, $this->limiter->remaining_today( 'google_indexing' ) );
		$rows  = $this->repo->claim( 'google_indexing', $limit, self::batch_id() );
		foreach ( $rows as $index => $row ) {
			if ( $budget->exhausted( 5.0 ) ) {
				$this->repo->unclaim( array_column( array_slice( $rows, $index ), 'id' ) );
				return;
			}
			if ( 'post' !== $row['object_type'] || ! (int) $row['object_id'] ) {
				$this->finish_skipped( array( $row ), IndexingApi::reason_message( 'not_candidate' ), $stats );
				continue;
			}
			if ( 'delete' === $row['action'] ) {
				// Removal notices only for URLs Google received through the Indexing API before.
				if ( ! $this->repo->was_sent( 'google_indexing', $row['url'] ) && ! $this->registry_has_engine( $row['url'], 'google_indexing' ) ) {
					$this->finish_skipped( array( $row ), IndexingApi::reason_message( 'never_sent' ), $stats );
					continue;
				}
			} else {
				// Proof, not a hint: the published page must carry JobPosting / livestream markup.
				$check = $this->indexing->check( (int) $row['object_id'], true );
				if ( 'fetch_failed' === $check['reason'] ) {
					$retry = new \WP_Error(
						'searchpilot_indexing_unverified',
						$check['message'],
						array(
							'status'    => 0,
							'retryable' => true,
						)
					);
					$this->finish( 'google_indexing', array( $row ), $retry, $stats );
					continue;
				}
				if ( ! $check['eligible'] ) {
					$this->finish_skipped( array( $row ), $check['message'], $stats );
					continue;
				}
			}
			$result = $this->indexing->publish( $row['url'], 'delete' === $row['action'] ? 'URL_DELETED' : 'URL_UPDATED' );
			++$stats['requests'];
			if ( is_wp_error( $result ) && 'searchpilot_quota' === $result->get_error_code() ) {
				// Quota is not the row's fault: this and the remaining rows wait for the reset.
				$this->defer( 'google_indexing', array_slice( $rows, $index ), $result );
				return;
			}
			$this->finish( 'google_indexing', array( $row ), $result, $stats );
		}
	}

	/**
	 * Whether the URL registry lists a service among the engines a URL was sent to.
	 *
	 * @param string $url     URL.
	 * @param string $service Service.
	 * @return bool
	 */
	private function registry_has_engine( $url, $service ) {
		$row = $this->urls->get_by_url( $url );
		return $row && in_array( $service, (array) $row['engines'], true );
	}

	/**
	 * Sitemap submissions (one request each).
	 *
	 * @param string     $service gsc_sitemap|bing_sitemap.
	 * @param TimeBudget $budget  Budget.
	 * @param array      $stats   Stats (by reference).
	 */
	private function process_sitemaps( $service, TimeBudget $budget, array &$stats ) {
		$connected = 'gsc_sitemap' === $service ? $this->gsc->is_connected() : $this->bing->is_connected();
		if ( ! $connected ) {
			$this->skip_due( $service, 'gsc_sitemap' === $service ? __( 'Not sent: Google Search Console is not connected.', 'searchpilot-pro' ) : __( 'Not sent: Bing Webmaster Tools is not connected.', 'searchpilot-pro' ), $stats );
			return;
		}
		$rows = $this->repo->claim( $service, 5, self::batch_id() );
		foreach ( $rows as $index => $row ) {
			if ( $budget->exhausted( 3.0 ) ) {
				$this->repo->unclaim( array_column( array_slice( $rows, $index ), 'id' ) );
				return;
			}
			$result = 'gsc_sitemap' === $service ? $this->gsc->submit_sitemap( $row['url'] ) : $this->bing->submit_feed( $row['url'] );
			++$stats['requests'];
			$this->finish( $service, array( $row ), $result, $stats );
		}
	}

	/**
	 * Marks due rows of a service as skipped (service switched off since queued).
	 *
	 * @param string $service Service.
	 * @param string $message Reason.
	 * @param array  $stats   Stats (by reference).
	 */
	private function skip_due( $service, $message, array &$stats ) {
		$rows = $this->repo->claim( $service, 500, self::batch_id() );
		if ( $rows ) {
			$this->finish_skipped( $rows, $message, $stats );
		}
	}

	/**
	 * Marks claimed rows skipped.
	 *
	 * @param array[] $rows    Rows.
	 * @param string  $message Reason.
	 * @param array   $stats   Stats (by reference).
	 */
	private function finish_skipped( array $rows, $message, array &$stats ) {
		$stats['skipped'] += $this->repo->update_many(
			array_column( $rows, 'id' ),
			array(
				'status'       => 'skipped',
				'last_error'   => $message,
				'batch_id'     => '',
				'locked_at'    => null,
				'completed_at' => QueueRepository::now(),
			)
		);
	}

	/**
	 * Records the result of a request for its rows.
	 *
	 * @param string          $service Service.
	 * @param array[]         $rows    Claimed rows.
	 * @param true|\WP_Error  $result  Result.
	 * @param array           $stats   Stats (by reference).
	 */
	private function finish( $service, array $rows, $result, array &$stats ) {
		$urls    = array_values( array_unique( array_column( $rows, 'url' ) ) );
		$channel = self::CHANNELS[ $service ] ?? 'queue';
		$now     = QueueRepository::now();

		if ( ! is_wp_error( $result ) ) {
			$code = 'indexnow' === $service ? $this->indexnow->last_status() : 200;
			foreach ( $rows as $row ) {
				$this->repo->update(
					(int) $row['id'],
					array(
						'status'        => 'done',
						'attempts'      => (int) $row['attempts'] + 1,
						'response_code' => $code,
						'last_error'    => null,
						'batch_id'      => '',
						'locked_at'     => null,
						'completed_at'  => $now,
					)
				);
			}
			$stats['done'] += count( $rows );
			if ( in_array( $service, SubmissionQueue::URL_SERVICES, true ) ) {
				$this->record_submitted( $service, $rows, $now );
			}
			$this->logger->info(
				$channel,
				sprintf( '%1$s: %2$d URL(s) submitted.', $service, count( $urls ) ),
				array( 'urls' => array_slice( $urls, 0, 20 ) )
			);
			/**
			 * Fires after a submission to a search engine.
			 *
			 * @param string         $service Service (indexnow|bing_url|google_indexing|gsc_sitemap|bing_sitemap).
			 * @param string[]       $urls    URLs.
			 * @param true|\WP_Error $result  Result.
			 */
			do_action( 'searchpilot_submitted', $service, $urls, true );
			return;
		}

		if ( 'searchpilot_quota' === $result->get_error_code() ) {
			$this->defer( $service, $rows, $result );
			return;
		}

		$data        = (array) $result->get_error_data();
		$status      = (int) ( $data['http_status'] ?? ( $data['status'] ?? 0 ) );
		$retryable   = isset( $data['retryable'] ) ? (bool) $data['retryable'] : 'retry' === Backoff::classify( $status );
		$retry_after = (int) ( $data['retry_after'] ?? 0 );
		$message     = Logger::redact_string( $result->get_error_message() );
		$failed      = 0;
		$retry       = 0;
		foreach ( $rows as $row ) {
			$attempts = (int) $row['attempts'] + 1;
			if ( $retryable && ! Backoff::exhausted( $attempts, (int) $row['max_attempts'] ) ) {
				$this->repo->update(
					(int) $row['id'],
					array(
						'status'        => 'pending',
						'attempts'      => $attempts,
						'response_code' => max( 0, min( 65535, $status ) ),
						'last_error'    => $message,
						'batch_id'      => '',
						'locked_at'     => null,
						'available_at'  => gmdate( 'Y-m-d H:i:s', Backoff::next_attempt_at( $attempts, $retry_after ) ),
					)
				);
				++$retry;
				continue;
			}
			$this->repo->update(
				(int) $row['id'],
				array(
					'status'        => 'failed',
					'attempts'      => $attempts,
					'response_code' => max( 0, min( 65535, $status ) ),
					'last_error'    => $message,
					'batch_id'      => '',
					'locked_at'     => null,
					'completed_at'  => $now,
				)
			);
			++$failed;
		}
		$stats['retry']  += $retry;
		$stats['failed'] += $failed;
		$this->logger->warning(
			$channel,
			sprintf( '%1$s submission failed for %2$d URL(s): %3$s', $service, count( $urls ), $message ),
			array(
				'status'    => $status,
				'retryable' => $retryable,
				'retry'     => $retry,
				'failed'    => $failed,
			)
		);
		/** This action is documented above. */
		do_action( 'searchpilot_submitted', $service, $urls, $result );
	}

	/**
	 * Returns rows to pending without counting an attempt (quota used up).
	 *
	 * @param string    $service Service.
	 * @param array[]   $rows    Claimed rows.
	 * @param \WP_Error $error   Quota error.
	 */
	private function defer( $service, array $rows, \WP_Error $error ) {
		$data  = (array) $error->get_error_data();
		$until = max(
			time() + MINUTE_IN_SECONDS,
			time() + (int) ( $data['retry_after'] ?? 0 ),
			$this->limiter->blocked_until( self::BUDGETS[ $service ] ?? $service )
		);
		$this->repo->unclaim( array_column( $rows, 'id' ), gmdate( 'Y-m-d H:i:s', $until ) );
		$this->logger->info(
			self::CHANNELS[ $service ] ?? 'queue',
			sprintf( '%1$s quota reached; %2$d notification(s) wait until %3$s UTC.', $service, count( $rows ), gmdate( 'Y-m-d H:i', $until ) ),
			array( 'message' => $error->get_error_message() )
		);
	}

	/**
	 * Updates the URL registry after a successful URL submission.
	 *
	 * @param string  $service Service.
	 * @param array[] $rows    Rows.
	 * @param string  $now     MySQL UTC time.
	 */
	private function record_submitted( $service, array $rows, $now ) {
		global $wpdb;
		$by_hash = array();
		foreach ( $rows as $row ) {
			$by_hash[ Url::hash( $row['url'] ) ] = $row;
		}
		$table    = Tables::urls();
		$existing = array();
		foreach ( array_chunk( array_keys( $by_hash ), 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$found = $wpdb->get_results( $wpdb->prepare( "SELECT id, url_hash, engines FROM {$table} WHERE url_hash IN ({$holders})", $chunk ), ARRAY_A );
			foreach ( (array) $found as $item ) {
				$existing[ $item['url_hash'] ] = $item;
			}
		}
		foreach ( $by_hash as $hash => $row ) {
			if ( isset( $existing[ $hash ] ) ) {
				$engines = array_filter( explode( ',', (string) $existing[ $hash ]['engines'] ) );
				$engines = array_values( array_unique( array_merge( $engines, array( $service ) ) ) );
				$this->urls->update(
					(int) $existing[ $hash ]['id'],
					array(
						'last_submitted' => $now,
						'engines'        => $engines,
					)
				);
				continue;
			}
			if ( 'delete' === $row['action'] ) {
				continue;
			}
			$fields = array(
				'last_submitted' => $now,
				'engines'        => array( $service ),
			);
			if ( '' !== (string) $row['object_type'] && (int) $row['object_id'] ) {
				$fields['object_type'] = (string) $row['object_type'];
				$fields['object_id']   = (int) $row['object_id'];
			}
			$this->urls->upsert( $row['url'], $fields );
		}
	}

	/**
	 * Registers or resolves the "submissions failing" issue.
	 */
	public function check_failure_rate() {
		$since = QueueRepository::now( -DAY_IN_SECONDS );
		$count = $this->repo->failures_since( $since );
		if ( $count > self::FAILURE_THRESHOLD ) {
			$top = $this->repo->top_failures( $since, 3 );
			$this->issues->upsert(
				self::ISSUE,
				array(
					'object_type' => 'site',
					'object_id'   => 0,
					'severity'    => $count > 100 ? 'critical' : 'high',
					/* translators: %d: number of failures. */
					'title'       => sprintf( __( '%d failed submissions in 24 hours', 'searchpilot-pro' ), $count ),
					'data'        => array(
						'count' => $count,
						'top'   => $top,
					),
				)
			);
			return;
		}
		$this->issues->resolve( self::ISSUE, 'site', 0 );
	}

	/**
	 * Deletes finished rows past the history retention.
	 *
	 * @return int
	 */
	public function prune() {
		$deleted = $this->repo->prune( (int) $this->settings->get( 'general.history_retention_days', 90 ) );
		if ( $deleted ) {
			$this->logger->debug( 'queue', sprintf( 'Pruned %d old queue row(s).', $deleted ) );
		}
		return $deleted;
	}

	/**
	 * New batch id.
	 *
	 * @return string
	 */
	private static function batch_id() {
		return wp_generate_uuid4();
	}
}
