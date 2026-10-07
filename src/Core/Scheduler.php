<?php
/**
 * Background job scheduling (Action Scheduler or WP-Cron).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the recurring hooks every module attaches its background work to:
 *
 *   searchpilot_every_5min  queue processing and small incremental batches
 *   searchpilot_hourly      crawling, inspections, critical monitoring
 *   searchpilot_daily       analytics sync, full audits, pruning
 *   searchpilot_weekly      reconciliation and reports
 *
 * Action Scheduler is used when another plugin (e.g. WooCommerce) provides
 * it; otherwise WP-Cron. Jobs must be idempotent, take a Lock, and respect
 * a TimeBudget so they never overrun.
 */
class Scheduler {

	const GROUP = 'searchpilot';

	const RECURRING = array(
		'searchpilot_every_5min' => 300,
		'searchpilot_hourly'     => HOUR_IN_SECONDS,
		'searchpilot_daily'      => DAY_IN_SECONDS,
		'searchpilot_weekly'     => WEEK_IN_SECONDS,
	);

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param State $state State.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
	}

	/**
	 * Adds hooks.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 minute queue cadence is intentional.
		add_action( 'init', array( $this, 'maybe_ensure_scheduled' ), 20 );
		foreach ( array_keys( self::RECURRING ) as $hook ) {
			add_action( $hook, array( $this, 'record_run' ), 0 );
		}
	}

	/**
	 * Adds the 5-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		$schedules['searchpilot_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (SearchPilot)', 'searchpilot-pro' ),
		);
		return $schedules;
	}

	/**
	 * Which backend runs jobs.
	 *
	 * @return string action_scheduler|wp_cron
	 */
	public static function backend() {
		$use_as = function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' );
		/**
		 * Filters whether Action Scheduler is used when available.
		 *
		 * @param bool $use_as Use Action Scheduler.
		 */
		return apply_filters( 'searchpilot_use_action_scheduler', $use_as ) ? 'action_scheduler' : 'wp_cron';
	}

	/**
	 * Checks the recurring events at most every 10 minutes (admin/cron only).
	 */
	public function maybe_ensure_scheduled() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( get_transient( 'searchpilot_schedule_checked' ) ) {
			return;
		}
		set_transient( 'searchpilot_schedule_checked', 1, 10 * MINUTE_IN_SECONDS );
		$this->ensure_scheduled();
	}

	/**
	 * Makes sure every recurring hook is scheduled exactly once.
	 */
	public function ensure_scheduled() {
		$backend = self::backend();
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( 'action_scheduler' === $backend ) {
				wp_clear_scheduled_hook( $hook );
				if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
					as_schedule_recurring_action( time() + wp_rand( 30, 300 ), $interval, $hook, array(), self::GROUP );
				}
				continue;
			}
			if ( ! wp_next_scheduled( $hook ) ) {
				$recurrence = array(
					300              => 'searchpilot_5min',
					HOUR_IN_SECONDS  => 'hourly',
					DAY_IN_SECONDS   => 'daily',
					WEEK_IN_SECONDS  => 'weekly',
				)[ $interval ];
				// Spread daily/weekly work away from the top of the hour.
				wp_schedule_event( time() + wp_rand( 60, 600 ), $recurrence, $hook );
			}
		}
		$this->state->set( 'scheduler_backend', $backend );
	}

	/**
	 * Removes every scheduled SearchPilot event (deactivation).
	 */
	public static function unschedule_all() {
		foreach ( array_keys( self::RECURRING ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		// Single events scheduled by modules.
		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $events ) {
				foreach ( array_keys( (array) $events ) as $hook ) {
					if ( 0 === strpos( (string) $hook, 'searchpilot_' ) ) {
						// wp_clear_scheduled_hook() only matches events without args;
						// per-post events (analyze_post[123]…) need wp_unschedule_hook().
						wp_unschedule_hook( $hook );
					}
				}
			}
		}
		delete_transient( 'searchpilot_schedule_checked' );
	}

	/**
	 * Runs a hook in the background soon (deduplicated).
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args (must be scalar/array, JSON-safe).
	 * @param int    $delay Seconds from now.
	 */
	public function enqueue_async( $hook, array $args = array(), $delay = 5 ) {
		if ( 'action_scheduler' === self::backend() ) {
			// as_next_scheduled_action() is also true for the action that is running right now,
			// which would drop every job that re-queues itself; only a pending copy counts.
			if ( ! self::as_pending( $hook, $args ) ) {
				as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), $hook, $args );
		}
	}

	/**
	 * Whether a pending (not running) Action Scheduler action exists.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return bool
	 */
	private static function as_pending( $hook, array $args ) {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
			return ! empty( $ids );
		}
		return false !== as_next_scheduled_action( $hook, $args, self::GROUP ) && ! doing_action( $hook );
	}

	/**
	 * Next run of a hook on the active backend (Unix time or 0).
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	public static function next_run( $hook ) {
		if ( 'action_scheduler' === self::backend() ) {
			$next = as_next_scheduled_action( $hook, array(), self::GROUP );
			return is_int( $next ) ? $next : 0;
		}
		return (int) wp_next_scheduled( $hook );
	}

	/**
	 * Records the last run time of a recurring hook (for health checks).
	 */
	public function record_run() {
		$hook = current_action();
		$runs = (array) $this->state->get( 'cron_runs', array() );

		$runs[ $hook ] = time();
		$this->state->set( 'cron_runs', $runs );
	}

	/**
	 * Last run of each recurring hook.
	 *
	 * @return array<string,int>
	 */
	public function last_runs() {
		return (array) $this->state->get( 'cron_runs', array() );
	}

	/**
	 * Whether background processing looks healthy (hourly hook ran recently).
	 *
	 * @return bool
	 */
	public function healthy() {
		$runs      = $this->last_runs();
		$installed = strtotime( (string) $this->state->get( 'installed_at', '' ) );
		if ( empty( $runs['searchpilot_hourly'] ) ) {
			// Give a fresh install three hours before complaining.
			return ! $installed || ( time() - $installed ) < 3 * HOUR_IN_SECONDS;
		}
		return ( time() - (int) $runs['searchpilot_hourly'] ) < 3 * HOUR_IN_SECONDS;
	}
}
