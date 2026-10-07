<?php
/**
 * Resumable Search Console / Bing performance sync.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * A sync spans many API calls (site totals, then query/page snapshots for
 * five ranges, each for the current and the previous period), so it runs
 * as a small state machine stored in State `analytics_sync`:
 *
 *   start() builds the step list → run() executes steps until the time
 *   budget is used → the next hourly / 5-minute tick (or an async event)
 *   continues from the cursor → the last steps rebuild opportunities and
 *   record the sync time.
 *
 * Every step holds the `analytics` lock and keeps the old data when an API
 * call fails (errors are logged; quota errors pause the sync until later).
 */
class PerformanceSync {

	const STATE_KEY        = 'analytics_sync';
	const LOCK             = 'analytics';
	const LOCK_TTL         = 300;
	const CONTINUE_HOOK    = 'searchpilot_analytics_continue';
	const SNAPSHOT_RANGES  = array( '7d', '28d', '3m', '6m', '12m' );
	const QUERY_PAGE       = array( '28d', '3m' );
	const BING_RANGES      = array( '28d', '3m', '6m' );
	const MAX_ROWS         = 5000;
	const MAX_QUERY_PAGE   = 10000;
	const RECENT_DAYS      = 10;
	const STALE_AFTER      = 21600;
	const MIN_STEP_SECONDS = 8;
	const MAX_ATTEMPTS     = 3;

	/**
	 * Repository.
	 *
	 * @var PerformanceRepository
	 */
	private $repo;

	/**
	 * URL resolver.
	 *
	 * @var UrlResolver
	 */
	private $resolver;

	/**
	 * Opportunity engine.
	 *
	 * @var Opportunities
	 */
	private $opportunities;

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
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param PerformanceRepository $repo          Repository.
	 * @param UrlResolver           $resolver      Resolver.
	 * @param Opportunities         $opportunities Opportunities.
	 * @param Settings              $settings      Settings.
	 * @param State                 $state         State.
	 * @param Logger                $logger        Logger.
	 * @param Scheduler             $scheduler     Scheduler.
	 */
	public function __construct( PerformanceRepository $repo, UrlResolver $resolver, Opportunities $opportunities, Settings $settings, State $state, Logger $logger, Scheduler $scheduler ) {
		$this->repo          = $repo;
		$this->resolver      = $resolver;
		$this->opportunities = $opportunities;
		$this->settings      = $settings;
		$this->state         = $state;
		$this->logger        = $logger;
		$this->scheduler     = $scheduler;
	}

	/* --------------------------------------------------------------------
	 * Entry points
	 * ------------------------------------------------------------------ */

	/**
	 * Daily cron: start a new sync and work on it.
	 */
	public function daily() {
		$this->start( 'daily' );
		$this->work( 20 );
	}

	/**
	 * First sync after the setup wizard (16 months of history).
	 */
	public function initial() {
		$this->start( 'initial', true );
		$this->work( 20 );
	}

	/**
	 * Hourly / 5-minute / async tick: continue a running sync.
	 */
	public function tick() {
		$sync = $this->current();
		if ( 'running' !== $sync['status'] ) {
			return;
		}
		$this->work( 'searchpilot_every_5min' === current_action() ? 15 : 20 );
	}

	/**
	 * Starts a sync unless one is already running (and not stale).
	 *
	 * @param string $trigger daily|initial|manual.
	 * @param bool   $force   Restart even when running.
	 * @return array Sync state.
	 */
	public function start( $trigger = 'manual', $force = false ) {
		$sync = $this->current();
		$now  = time();
		if ( ! $force && 'running' === $sync['status'] && ( $now - (int) $sync['updated_ts'] ) < self::STALE_AFTER ) {
			return $sync;
		}
		$connections = $this->repo->connections();
		$use_bing    = $connections['bing'] && $this->settings->get( 'analytics.bing', true );
		$steps       = self::plan( $connections['google'], $use_bing );
		if ( ! $connections['google'] && ! $use_bing ) {
			$sync = array_merge(
				self::blank(),
				array(
					'status'     => 'idle',
					'trigger'    => $trigger,
					'message'    => 'not_connected',
					'updated_ts' => $now,
				)
			);
			$this->save( $sync );
			return $sync;
		}
		$sync = array_merge(
			self::blank(),
			array(
				'status'     => 'running',
				'trigger'    => $trigger,
				'started_at' => current_time( 'mysql', true ),
				'steps'      => $steps,
				'cursor'     => 0,
				'updated_ts' => $now,
				'full'       => 'initial' === $trigger,
			)
		);
		$this->save( $sync );
		$this->logger->info(
			'analytics',
			sprintf( 'Performance sync started (%s): %d steps.', $trigger, count( $steps ) ),
			array(
				'google' => $connections['google'],
				'bing'   => $use_bing,
			)
		);
		return $sync;
	}

	/**
	 * Runs steps within a time budget and schedules the rest.
	 *
	 * @param int $seconds Budget.
	 * @return array Sync state.
	 */
	public function work( $seconds = 20 ) {
		$result = Lock::run(
			self::LOCK,
			self::LOCK_TTL,
			function () use ( $seconds ) {
				return $this->run( new TimeBudget( $seconds ) );
			}
		);
		$sync = is_array( $result ) ? $result : $this->current();
		if ( 'running' === $sync['status'] ) {
			$delay = ! empty( $sync['paused_until'] ) ? max( 30, (int) $sync['paused_until'] - time() ) : 20;
			$this->scheduler->enqueue_async( self::CONTINUE_HOOK, array(), min( $delay, HOUR_IN_SECONDS ) );
		}
		return $sync;
	}

	/**
	 * Executes steps from the cursor until done or out of time.
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array Sync state.
	 */
	public function run( TimeBudget $budget ) {
		$sync = $this->current();
		if ( 'running' !== $sync['status'] ) {
			return $sync;
		}
		if ( ! empty( $sync['paused_until'] ) && (int) $sync['paused_until'] > time() ) {
			return $sync;
		}
		$sync['paused_until'] = 0;
		$steps                = (array) $sync['steps'];
		$count                = count( $steps );

		$min_step = self::min_step_seconds( (int) ini_get( 'max_execution_time' ) );
		while ( $sync['cursor'] < $count ) {
			// The budget counts from the start of the request, which other jobs may
			// already have used: stop before a step that could overrun and let the
			// next tick resume from the cursor.
			if ( $budget->remaining() < $min_step ) {
				break;
			}
			$step   = $steps[ $sync['cursor'] ];
			$result = $this->execute( $step, $sync );
			++$sync['ran_this_call'];

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				$data = $result->get_error_data();
				$http = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
				$this->logger->warning(
					'analytics',
					sprintf( 'Sync step %s failed: %s', self::step_id( $step ), $result->get_error_message() ),
					array( 'code' => $code )
				);
				if ( 'searchpilot_quota' === $code || 429 === $http ) {
					// Out of API budget: pause and retry this step later (old data stays).
					$sync['paused_until'] = time() + 30 * MINUTE_IN_SECONDS;
					$sync['errors'][]     = self::error_entry( $step, $result );
					break;
				}
				$step_key                      = self::step_id( $step );
				$attempts                      = (int) ( $sync['attempts'][ $step_key ] ?? 0 ) + 1;
				$sync['attempts'][ $step_key ] = $attempts;
				if ( $http >= 500 && $attempts < self::MAX_ATTEMPTS ) {
					$sync['paused_until'] = time() + 5 * MINUTE_IN_SECONDS;
					break;
				}
				$sync['errors'][] = self::error_entry( $step, $result );
				if ( 'searchpilot_not_connected' === $code ) {
					$sync['skip_engine'][ $step['engine'] ] = true;
				}
			} elseif ( 'all' !== $step['engine'] ) {
				$sync['ok_engine'][ $step['engine'] ] = true;
			}
			++$sync['cursor'];
			$sync['updated_ts'] = time();
			$this->save( $sync );
		}

		if ( $sync['cursor'] >= $count ) {
			$sync['status']      = empty( $sync['ok_engine'] ) && ! empty( $sync['errors'] ) ? 'error' : 'done';
			$sync['finished_at'] = current_time( 'mysql', true );
			$this->logger->info(
				'analytics',
				sprintf( 'Performance sync finished (%s) with %d error(s).', $sync['status'], count( $sync['errors'] ) )
			);
		}
		$sync['ran_this_call'] = 0;
		$sync['updated_ts']    = time();
		$this->save( $sync );
		return $sync;
	}

	/**
	 * Seconds a step needs before it may start. Hosts with a very short
	 * max_execution_time get a lower floor so the sync still progresses.
	 *
	 * @param int $max_execution_time PHP limit (0 = none).
	 * @return float
	 */
	public static function min_step_seconds( $max_execution_time ) {
		$budget = $max_execution_time > 0 ? 0.6 * $max_execution_time : 0.0;
		if ( $budget > 0 && $budget < 2 * self::MIN_STEP_SECONDS ) {
			return max( 3.0, 0.5 * $budget );
		}
		return (float) self::MIN_STEP_SECONDS;
	}

	/**
	 * Current sync state with defaults.
	 *
	 * @return array
	 */
	public function current() {
		$sync = $this->state->get( self::STATE_KEY, array() );
		return array_merge( self::blank(), is_array( $sync ) ? $sync : array() );
	}

	/**
	 * Whether a sync is in progress.
	 *
	 * @return bool
	 */
	public function is_running() {
		return 'running' === $this->current()['status'];
	}

	/**
	 * Status for the admin: progress, last sync per engine, freshness notes.
	 *
	 * @return array
	 */
	public function status() {
		$sync      = $this->current();
		$steps     = (array) $sync['steps'];
		$total     = count( $steps );
		$done      = min( $total, (int) $sync['cursor'] );
		$current   = $done < $total ? self::step_label( $steps[ $done ] ) : '';
		$freshness = (array) $this->state->get( 'analytics_freshness', array() );
		$last      = (array) $this->state->get( 'analytics_last_sync', array() );
		$fresh     = (bool) $this->settings->get( 'analytics.fresh_data', false );
		$notes     = array(
			'google' => $fresh
				? __( 'Search Console data is typically 1–2 days behind (fresh data is enabled; the latest days may still change).', 'searchpilot-pro' )
				: __( 'Search Console data is typically 2–3 days behind.', 'searchpilot-pro' ),
			'bing'   => __( 'Bing reports search performance in weekly buckets that lag by about a week.', 'searchpilot-pro' ),
		);
		$errors = array();
		foreach ( array_slice( (array) $sync['errors'], -5 ) as $error ) {
			$errors[] = array(
				'step'    => (string) ( $error['step'] ?? '' ),
				'label'   => self::step_label( (array) ( $error['def'] ?? array() ) ),
				'message' => (string) ( $error['message'] ?? '' ),
			);
		}
		return array(
			'status'       => (string) $sync['status'],
			'trigger'      => (string) $sync['trigger'],
			'message'      => 'not_connected' === $sync['message'] ? __( 'Connect Google Search Console or Bing Webmaster Tools to import search performance.', 'searchpilot-pro' ) : '',
			'started_at'   => $sync['started_at'] ? mysql_to_rfc3339( $sync['started_at'] ) : null,
			'finished_at'  => $sync['finished_at'] ? mysql_to_rfc3339( $sync['finished_at'] ) : null,
			'paused_until' => ! empty( $sync['paused_until'] ) && (int) $sync['paused_until'] > time() ? gmdate( 'c', (int) $sync['paused_until'] ) : null,
			'progress'     => array(
				'done'    => $done,
				'total'   => $total,
				'percent' => $total ? (int) round( $done / $total * 100 ) : 0,
				'current' => $current,
			),
			'errors'       => $errors,
			'last_sync'    => array(
				'google' => isset( $last['google'] ) ? (string) $last['google'] : null,
				'bing'   => isset( $last['bing'] ) ? (string) $last['bing'] : null,
			),
			'connected'    => $this->repo->connections(),
			'bing_enabled' => (bool) $this->settings->get( 'analytics.bing', true ),
			'freshness'    => array(
				'google' => array_merge(
					array(
						'latest'     => $this->repo->latest_date( 'google' ),
						'earliest'   => $this->repo->earliest_date( 'google' ),
						'data_state' => $fresh ? 'all' : 'final',
						'lag_days'   => Ranges::lag_days( $fresh ),
						'note'       => $notes['google'],
					),
					array_intersect_key( (array) ( $freshness['google'] ?? array() ), array_flip( array( 'synced_at' ) ) )
				),
				'bing'   => array_merge(
					array(
						'latest'   => $this->repo->latest_date( 'bing' ),
						'earliest' => $this->repo->earliest_date( 'bing' ),
						'weekly'   => true,
						'note'     => $notes['bing'],
					),
					array_intersect_key( (array) ( $freshness['bing'] ?? array() ), array_flip( array( 'synced_at', 'rows_latest' ) ) )
				),
			),
			'opportunities' => $this->state->get( 'opportunities_last_run', null ),
		);
	}

	/* --------------------------------------------------------------------
	 * Plan & steps
	 * ------------------------------------------------------------------ */

	/**
	 * Step list for the connected engines.
	 *
	 * @param bool $google Google connected.
	 * @param bool $bing   Bing connected and enabled.
	 * @return array[]
	 */
	public static function plan( $google, $bing ) {
		$steps = array();
		if ( $google ) {
			$steps[] = array(
				'engine' => 'google',
				'task'   => 'daily',
			);
			foreach ( self::SNAPSHOT_RANGES as $range ) {
				foreach ( array( 'query', 'page' ) as $dimension ) {
					$steps[] = array(
						'engine'    => 'google',
						'task'      => 'rows',
						'range'     => $range,
						'dimension' => $dimension,
					);
				}
				if ( in_array( $range, self::QUERY_PAGE, true ) ) {
					$steps[] = array(
						'engine'    => 'google',
						'task'      => 'rows',
						'range'     => $range,
						'dimension' => 'query_page',
					);
				}
			}
		}
		if ( $bing ) {
			$steps[] = array(
				'engine' => 'bing',
				'task'   => 'daily',
			);
			foreach ( array( 'query', 'page' ) as $dimension ) {
				$steps[] = array(
					'engine'    => 'bing',
					'task'      => 'rows',
					'dimension' => $dimension,
				);
			}
		}
		$steps[] = array(
			'engine' => 'all',
			'task'   => 'opportunities',
		);
		$steps[] = array(
			'engine' => 'all',
			'task'   => 'finish',
		);
		return $steps;
	}

	/**
	 * Executes one step.
	 *
	 * @param array $step Step.
	 * @param array $sync Sync state.
	 * @return true|\WP_Error
	 */
	private function execute( array $step, array $sync ) {
		if ( ! empty( $sync['skip_engine'][ $step['engine'] ] ) ) {
			return true;
		}
		try {
			switch ( $step['engine'] . ':' . $step['task'] ) {
				case 'google:daily':
					return $this->google_daily( ! empty( $sync['full'] ) );
				case 'google:rows':
					return $this->google_rows( (string) $step['range'], (string) $step['dimension'] );
				case 'bing:daily':
					return $this->bing_daily();
				case 'bing:rows':
					return $this->bing_rows( (string) $step['dimension'] );
				case 'all:opportunities':
					$this->opportunities->run();
					return true;
				case 'all:finish':
					return $this->finish( $sync );
			}
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'searchpilot_sync_exception', $e->getMessage() );
		}
		return true;
	}

	/**
	 * Google: site totals per day.
	 *
	 * @param bool $full Import the full 16 months.
	 * @return true|\WP_Error
	 */
	private function google_daily( $full ) {
		$client = $this->repo->google();
		if ( ! $client ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Google Search Console is not connected.', 'searchpilot-pro' ) );
		}
		$fresh      = (bool) $this->settings->get( 'analytics.fresh_data', false );
		$end        = Ranges::end_date( wp_date( 'Y-m-d' ), $fresh );
		$backfilled = (array) $this->state->get( 'analytics_backfilled', array() );
		$full       = $full || empty( $backfilled['google'] ) || ! $this->repo->has_data( 'google' );
		$start      = Ranges::shift( $end, -( ( $full ? Ranges::HISTORY_DAYS : self::RECENT_DAYS ) - 1 ) );
		$rows       = $client->search_analytics(
			array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'date' ),
				'type'       => 'web',
				'dataState'  => $fresh ? 'all' : 'final',
			),
			1000
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$daily = array();
		foreach ( (array) $rows as $row ) {
			$daily[] = array(
				'date'        => (string) ( $row['keys'][0] ?? '' ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}
		$written = $this->repo->upsert_daily( 'google', $daily );
		if ( $full ) {
			$backfilled['google'] = true;
			$this->state->set( 'analytics_backfilled', $backfilled );
		}
		$this->freshness(
			'google',
			array(
				'synced_at'  => gmdate( 'c' ),
				'data_state' => $fresh ? 'all' : 'final',
			)
		);
		$this->logger->info( 'analytics', sprintf( 'Imported %1$d days of Google totals (%2$s → %3$s).', $written, $start, $end ) );
		return true;
	}

	/**
	 * Google: one snapshot (range × dimension), current and previous period merged.
	 *
	 * @param string $range     Range key.
	 * @param string $dimension query|page|query_page.
	 * @return true|\WP_Error
	 */
	private function google_rows( $range, $dimension ) {
		$client = $this->repo->google();
		if ( ! $client ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Google Search Console is not connected.', 'searchpilot-pro' ) );
		}
		$fresh      = (bool) $this->settings->get( 'analytics.fresh_data', false );
		$target_end = Ranges::end_date( wp_date( 'Y-m-d' ), $fresh );
		$latest     = $this->repo->latest_date( 'google' );
		// Anchor on the latest day Search Console actually returned, so the current period is complete.
		$end        = $latest && $latest < $target_end ? $latest : $target_end;
		$current    = Ranges::window( $range, $end );
		$previous   = Ranges::previous( $current );
		$dimensions = 'query_page' === $dimension ? array( 'query', 'page' ) : array( $dimension );
		$max        = 'query_page' === $dimension ? self::MAX_QUERY_PAGE : self::MAX_ROWS;
		$body       = static function ( array $window ) use ( $dimensions, $fresh ) {
			return array(
				'startDate'  => $window['from'],
				'endDate'    => $window['to'],
				'dimensions' => $dimensions,
				'type'       => 'web',
				'dataState'  => $fresh ? 'all' : 'final',
			);
		};
		// Search Console keeps ~16 months (a new property less): compare only when
		// the previous period lies completely inside the stored history.
		$earliest = $this->repo->earliest_date( 'google' );
		$history  = $earliest ? $earliest : Ranges::shift( $end, -( Ranges::HISTORY_DAYS - 1 ) );
		$coverage = Ranges::coverage( $previous, $history, $end );
		$now_rows = $client->search_analytics( $body( $current ), $max );
		if ( is_wp_error( $now_rows ) ) {
			return $now_rows;
		}
		$prev_rows = array();
		if ( $coverage['complete'] ) {
			$prev_rows = $client->search_analytics( $body( $previous ), $max );
			if ( is_wp_error( $prev_rows ) ) {
				return $prev_rows;
			}
		}
		$merged = self::merge_periods(
			self::normalize_rows( (array) $now_rows, $dimension ),
			self::normalize_rows( (array) $prev_rows, $dimension )
		);
		$merged = $this->attach_objects( $merged, $dimension );
		$fetch  = current_time( 'mysql', true );
		$count  = $this->repo->replace_rows( 'google', $range, $dimension, $merged, $fetch );
		$this->repo->set_snapshot(
			'google',
			$range,
			array(
				'from'              => $current['from'],
				'to'                => $current['to'],
				'prev_from'         => $previous['from'],
				'prev_to'           => $previous['to'],
				'prev_complete'     => $coverage['complete'],
				'prev_covered_days' => $coverage['covered_days'],
				'fetched_at'        => mysql_to_rfc3339( $fetch ),
				'counts'            => array( $dimension => $count ),
			)
		);
		return true;
	}

	/**
	 * Bing: daily clicks and impressions.
	 *
	 * @return true|\WP_Error
	 */
	private function bing_daily() {
		$client = $this->repo->bing();
		if ( ! $client ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Bing Webmaster Tools is not connected.', 'searchpilot-pro' ) );
		}
		$rows = $client->get_rank_and_traffic_stats();
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$cutoff = Ranges::shift( wp_date( 'Y-m-d' ), -Ranges::HISTORY_DAYS );
		$daily  = array();
		foreach ( (array) $rows as $row ) {
			$date = substr( (string) ( $row['date'] ?? '' ), 0, 10 );
			if ( ! Ranges::is_date( $date ) || $date < $cutoff ) {
				continue;
			}
			$daily[] = array(
				'date'        => $date,
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => 0,
			);
		}
		$written = $this->repo->upsert_daily( 'bing', $daily );
		$this->freshness( 'bing', array( 'synced_at' => gmdate( 'c' ) ) );
		$this->logger->info( 'analytics', sprintf( 'Imported %d days of Bing totals.', $written ) );
		return true;
	}

	/**
	 * Bing: weekly query/page buckets aggregated into 28d, 3m and 6m snapshots.
	 *
	 * @param string $dimension query|page.
	 * @return true|\WP_Error
	 */
	private function bing_rows( $dimension ) {
		$client = $this->repo->bing();
		if ( ! $client ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Bing Webmaster Tools is not connected.', 'searchpilot-pro' ) );
		}
		$rows = 'page' === $dimension ? $client->get_page_stats() : $client->get_query_stats();
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rows     = (array) $rows;
		$latest   = '';
		$earliest = '';
		foreach ( $rows as $row ) {
			$date = substr( (string) ( $row['date'] ?? '' ), 0, 10 );
			if ( ! Ranges::is_date( $date ) ) {
				continue;
			}
			if ( $date > $latest ) {
				$latest = $date;
			}
			if ( '' === $earliest || $date < $earliest ) {
				$earliest = $date;
			}
		}
		if ( '' === $latest ) {
			return true;
		}
		$fetch = current_time( 'mysql', true );
		foreach ( self::BING_RANGES as $range ) {
			$current  = Ranges::window( $range, $latest );
			$previous = Ranges::previous( $current );
			// Each bucket covers the week ending on its date.
			$coverage = Ranges::coverage( $previous, Ranges::shift( $earliest, -6 ), $latest );
			$merged   = self::aggregate_bing( $rows, $dimension, $current, $coverage['complete'] ? $previous : null );
			$merged   = $this->attach_objects( $merged, $dimension );
			$count    = $this->repo->replace_rows( 'bing', $range, $dimension, $merged, $fetch );
			$this->repo->set_snapshot(
				'bing',
				$range,
				array(
					'from'              => $current['from'],
					'to'                => $current['to'],
					'prev_from'         => $previous['from'],
					'prev_to'           => $previous['to'],
					'prev_complete'     => $coverage['complete'],
					'prev_covered_days' => $coverage['covered_days'],
					'fetched_at'        => mysql_to_rfc3339( $fetch ),
					'weekly'            => true,
					'counts'            => array( $dimension => $count ),
				)
			);
		}
		$this->freshness( 'bing', array( 'rows_latest' => $latest ) );
		return true;
	}

	/**
	 * Final step: record sync times and notify listeners.
	 *
	 * @param array $sync Sync state.
	 * @return true
	 */
	private function finish( array $sync ) {
		$last = (array) $this->state->get( 'analytics_last_sync', array() );
		foreach ( array_keys( (array) $sync['ok_engine'] ) as $engine ) {
			if ( in_array( $engine, PerformanceRepository::ENGINES, true ) ) {
				$last[ $engine ] = gmdate( 'c' );
			}
		}
		$this->state->set( 'analytics_last_sync', $last );
		/**
		 * Fires after a performance sync completed.
		 *
		 * @param array $engines Engines that synced successfully.
		 * @param array $errors  Errors of the run.
		 */
		do_action( 'searchpilot_analytics_synced', array_keys( (array) $sync['ok_engine'] ), (array) $sync['errors'] );
		return true;
	}

	/**
	 * Stores freshness info.
	 *
	 * @param string $engine Engine.
	 * @param array  $info   Info.
	 */
	private function freshness( $engine, array $info ) {
		$all            = (array) $this->state->get( 'analytics_freshness', array() );
		$all[ $engine ] = array_merge( (array) ( $all[ $engine ] ?? array() ), $info );
		$this->state->set( 'analytics_freshness', $all );
	}

	/**
	 * Resolves page URLs to post ids.
	 *
	 * @param array  $rows      Rows.
	 * @param string $dimension Dimension.
	 * @return array
	 */
	private function attach_objects( array $rows, $dimension ) {
		if ( 'query' === $dimension || ! $rows ) {
			return $rows;
		}
		$map = $this->resolver->resolve_many( array_unique( array_column( $rows, 'page' ) ), 1500 );
		$this->resolver->flush();
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['object_id'] = (int) ( $map[ Url::normalize( $row['page'] ) ] ?? 0 );
		}
		return $rows;
	}

	/* --------------------------------------------------------------------
	 * Pure helpers (unit tested through the sync test script)
	 * ------------------------------------------------------------------ */

	/**
	 * Converts Search Analytics API rows to keyed rows.
	 *
	 * @param array  $rows      API rows (keys, clicks, impressions, ctr, position).
	 * @param string $dimension query|page|query_page.
	 * @return array<string,array> Row hash => row.
	 */
	public static function normalize_rows( array $rows, $dimension ) {
		$out = array();
		foreach ( $rows as $row ) {
			$keys = array_values( (array) ( $row['keys'] ?? array() ) );
			if ( ! $keys ) {
				continue;
			}
			$query = 'page' === $dimension ? '' : trim( (string) $keys[0] );
			$page  = 'query' === $dimension ? '' : Url::normalize( (string) ( 'page' === $dimension ? $keys[0] : ( $keys[1] ?? '' ) ) );
			if ( ( 'page' !== $dimension && '' === $query ) || ( 'query' !== $dimension && '' === $page ) ) {
				continue;
			}
			$hash = PerformanceRepository::row_hash( $dimension, mb_substr( $query, 0, 255, 'UTF-8' ), $page );
			if ( isset( $out[ $hash ] ) ) {
				continue;
			}
			$impressions  = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$clicks       = max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$out[ $hash ] = array(
				'query'       => $query,
				'page'        => $page,
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'ctr'         => $impressions ? $clicks / $impressions : 0.0,
				'position'    => (float) ( $row['position'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Merges current and previous period rows into one row with prev_* fields.
	 * Rows present only in the previous period (lost queries/pages) are kept
	 * with zero current values.
	 *
	 * @param array $current  Hash => row.
	 * @param array $previous Hash => row.
	 * @return array[]
	 */
	public static function merge_periods( array $current, array $previous ) {
		$out = array();
		foreach ( $current as $hash => $row ) {
			$prev  = $previous[ $hash ] ?? null;
			$out[] = array_merge(
				$row,
				array(
					'prev_clicks'      => $prev ? (int) $prev['clicks'] : 0,
					'prev_impressions' => $prev ? (int) $prev['impressions'] : 0,
					'prev_ctr'         => $prev ? (float) $prev['ctr'] : 0.0,
					'prev_position'    => $prev ? (float) $prev['position'] : 0.0,
				)
			);
		}
		foreach ( $previous as $hash => $prev ) {
			if ( isset( $current[ $hash ] ) ) {
				continue;
			}
			$out[] = array(
				'query'            => $prev['query'],
				'page'             => $prev['page'],
				'clicks'           => 0,
				'impressions'      => 0,
				'ctr'              => 0.0,
				'position'         => 0.0,
				'prev_clicks'      => (int) $prev['clicks'],
				'prev_impressions' => (int) $prev['impressions'],
				'prev_ctr'         => (float) $prev['ctr'],
				'prev_position'    => (float) $prev['position'],
			);
		}
		return $out;
	}

	/**
	 * Aggregates Bing weekly buckets into a current/previous snapshot.
	 * Position is the impression-weighted avg_impression_position.
	 *
	 * @param array      $rows      Bing rows (query|page, date, clicks, impressions, avg_impression_position).
	 * @param string     $dimension query|page.
	 * @param array      $current   Window (from, to).
	 * @param array|null $previous  Window (from, to), or null when it is not fully covered (no comparison).
	 * @return array[]
	 */
	public static function aggregate_bing( array $rows, $dimension, array $current, $previous ) {
		$acc = array();
		foreach ( $rows as $row ) {
			$date = substr( (string) ( $row['date'] ?? '' ), 0, 10 );
			if ( $date >= $current['from'] && $date <= $current['to'] ) {
				$period = 'cur';
			} elseif ( is_array( $previous ) && $date >= $previous['from'] && $date <= $previous['to'] ) {
				$period = 'prev';
			} else {
				continue;
			}
			$query = 'query' === $dimension ? trim( (string) ( $row['query'] ?? '' ) ) : '';
			$page  = 'page' === $dimension ? Url::normalize( (string) ( $row['page'] ?? '' ) ) : '';
			if ( ( 'query' === $dimension && '' === $query ) || ( 'page' === $dimension && '' === $page ) ) {
				continue;
			}
			$key = 'query' === $dimension ? mb_strtolower( $query, 'UTF-8' ) : $page;
			if ( ! isset( $acc[ $key ] ) ) {
				$acc[ $key ] = array(
					'query' => $query,
					'page'  => $page,
					'cur'   => array( 0, 0, 0.0, 0 ),
					'prev'  => array( 0, 0, 0.0, 0 ),
				);
			}
			$impressions = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$position    = (float) ( $row['avg_impression_position'] ?? ( $row['position'] ?? 0 ) );

			$acc[ $key ][ $period ][0] += max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$acc[ $key ][ $period ][1] += $impressions;
			if ( $position > 0 && $impressions > 0 ) {
				$acc[ $key ][ $period ][2] += $position * $impressions;
				$acc[ $key ][ $period ][3] += $impressions;
			}
		}
		$out = array();
		foreach ( $acc as $item ) {
			list( $c, $i, $pw, $pi )      = $item['cur'];
			list( $pc, $p_i, $ppw, $ppi ) = $item['prev'];
			$out[]                        = array(
				'query'            => $item['query'],
				'page'             => $item['page'],
				'clicks'           => $c,
				'impressions'      => $i,
				'ctr'              => $i ? $c / $i : 0.0,
				'position'         => $pi ? $pw / $pi : 0.0,
				'prev_clicks'      => $pc,
				'prev_impressions' => $p_i,
				'prev_ctr'         => $p_i ? $pc / $p_i : 0.0,
				'prev_position'    => $ppi ? $ppw / $ppi : 0.0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['clicks'] <=> $a['clicks'] ?: $b['impressions'] <=> $a['impressions'];
			}
		);
		return array_slice( $out, 0, self::MAX_ROWS );
	}

	/* --------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Default state.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'status'        => 'idle',
			'trigger'       => '',
			'message'       => '',
			'started_at'    => '',
			'finished_at'   => '',
			'steps'         => array(),
			'cursor'        => 0,
			'errors'        => array(),
			'attempts'      => array(),
			'skip_engine'   => array(),
			'ok_engine'     => array(),
			'paused_until'  => 0,
			'updated_ts'    => 0,
			'ran_this_call' => 0,
			'full'          => false,
		);
	}

	/**
	 * Saves the state.
	 *
	 * @param array $sync State.
	 */
	private function save( array $sync ) {
		$sync['errors'] = array_slice( (array) $sync['errors'], -20 );
		$this->state->set( self::STATE_KEY, $sync );
	}

	/**
	 * Machine id of a step.
	 *
	 * @param array $step Step.
	 * @return string
	 */
	private static function step_id( array $step ) {
		return implode( ':', array_filter( array( $step['engine'] ?? '', $step['task'] ?? '', $step['range'] ?? '', $step['dimension'] ?? '' ) ) );
	}

	/**
	 * Error record for the state.
	 *
	 * @param array     $step  Step.
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private static function error_entry( array $step, \WP_Error $error ) {
		return array(
			'step'    => self::step_id( $step ),
			'def'     => $step,
			'code'    => $error->get_error_code(),
			'message' => mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 300 ),
			'at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Human label of a step.
	 *
	 * @param array $step Step.
	 * @return string
	 */
	public static function step_label( array $step ) {
		$engine    = 'bing' === ( $step['engine'] ?? '' ) ? __( 'Bing', 'searchpilot-pro' ) : __( 'Google', 'searchpilot-pro' );
		$dimension = array(
			'query'      => __( 'queries', 'searchpilot-pro' ),
			'page'       => __( 'pages', 'searchpilot-pro' ),
			'query_page' => __( 'queries per page', 'searchpilot-pro' ),
		)[ $step['dimension'] ?? '' ] ?? '';
		switch ( $step['task'] ?? '' ) {
			case 'daily':
				/* translators: %s: search engine. */
				return sprintf( __( '%s daily totals', 'searchpilot-pro' ), $engine );
			case 'rows':
				if ( ! empty( $step['range'] ) ) {
					/* translators: 1: search engine, 2: dimension, 3: range key. */
					return sprintf( __( '%1$s %2$s (%3$s)', 'searchpilot-pro' ), $engine, $dimension, $step['range'] );
				}
				/* translators: 1: search engine, 2: dimension. */
				return sprintf( __( '%1$s %2$s', 'searchpilot-pro' ), $engine, $dimension );
			case 'opportunities':
				return __( 'Finding content opportunities', 'searchpilot-pro' );
			case 'finish':
				return __( 'Finishing', 'searchpilot-pro' );
		}
		return '';
	}
}
