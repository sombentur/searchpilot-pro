<?php
/**
 * Execution time budget for background batches.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lets batch jobs stop cleanly before PHP's max_execution_time.
 */
final class TimeBudget {

	/**
	 * Deadline (microtime).
	 *
	 * @var float
	 */
	private $deadline;

	/**
	 * Constructor.
	 *
	 * Several jobs often share one cron request (e.g. every searchpilot_hourly
	 * listener), so the deadline is also capped relative to the start of the
	 * request: never later than 60% of max_execution_time after it began (or
	 * 50 s when PHP has no limit but a web server/proxy would still time out).
	 *
	 * @param int $seconds Desired budget for this job.
	 */
	public function __construct( $seconds = 20 ) {
		$now      = microtime( true );
		$deadline = $now + max( 1, (int) $seconds );
		$max      = (int) ini_get( 'max_execution_time' );
		$cli      = 'cli' === PHP_SAPI;
		if ( $max <= 0 && ! $cli ) {
			$max = 84; // 60% ≈ 50 s: below typical proxy/FastCGI timeouts.
		}
		if ( $max > 0 ) {
			$start    = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $now; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$start    = $start > $now || $start < $now - 3600 ? $now : $start;
			$deadline = min( $deadline, $start + $max * 0.6 );
		}
		$this->deadline = $deadline;
	}

	/**
	 * Seconds left.
	 *
	 * @return float
	 */
	public function remaining() {
		return max( 0.0, $this->deadline - microtime( true ) );
	}

	/**
	 * Whether the budget is used up (keeps a safety margin).
	 *
	 * @param float $margin Seconds to keep in reserve.
	 * @return bool
	 */
	public function exhausted( $margin = 2.0 ) {
		return $this->remaining() <= $margin;
	}
}
