<?php
/**
 * Scheduled e-mail reports.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Reports;

use SearchPilot\Analytics\Ranges;
use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the weekly or monthly report (setting `alerts.report`) once the
 * period has ended and search-engine data for it is available — Search
 * Console lags a few days, so a week ending Sunday is usually reported on
 * Wednesday or Thursday. Waits at most six days for data.
 */
class ReportsModule implements Module {

	const MAX_WAIT_DAYS = 6;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'searchpilot_daily', array( $this, 'maybe_send' ), 60 );
		add_action( 'searchpilot_analytics_synced', array( $this, 'maybe_send' ), 20, 0 );
		add_action( 'searchpilot_weekly', array( $this, 'prune' ) );
	}

	/**
	 * Builds and e-mails the scheduled report when it is due.
	 *
	 * @return int|null Report id when one was sent.
	 */
	public function maybe_send() {
		$settings = $this->container->get( \SearchPilot\Core\Settings::class );
		$type     = (string) $settings->get( 'alerts.report', 'off' );
		if ( ! in_array( $type, array( 'weekly', 'monthly' ), true ) ) {
			return null;
		}
		return Lock::run(
			'reports',
			300,
			function () use ( $type ) {
				return $this->send_due( $type );
			}
		);
	}

	/**
	 * Sends the report of the last completed period if not sent yet.
	 *
	 * @param string $type weekly|monthly.
	 * @return int|null
	 */
	private function send_due( $type ) {
		$state   = $this->container->get( \SearchPilot\Core\State::class );
		$builder = $this->container->get( ReportBuilder::class );
		$today   = wp_date( 'Y-m-d' );
		$period  = $builder->period( $type, $today );
		$last    = (array) $state->get( 'reports_last', array() );
		if ( isset( $last[ $type ] ) && (string) $last[ $type ] >= $period['to'] ) {
			return null;
		}
		if ( ! $this->data_ready( $period['to'], $today ) ) {
			return null;
		}
		$logger   = $this->container->get( \SearchPilot\Core\Logger::class );
		$existing = $builder->find( $type, $period['from'], $period['to'] );
		$report   = $existing ? $builder->get( $existing ) : null;
		if ( $report && empty( $report['emailed'] ) && $builder->is_stale( $report ) ) {
			// Generated (e.g. manually) before search data covered the whole period: rebuild it.
			$report = $builder->rebuild( $existing );
			$logger->info( 'reports', sprintf( 'Rebuilt %1$s report #%2$d with data up to the period end.', $type, (int) $existing ) );
		}
		if ( ! $report ) {
			$report = $builder->build( $type, $period['from'], $period['to'] );
		}
		if ( is_wp_error( $report ) || ! $report ) {
			$logger->warning( 'reports', sprintf( 'The %s report could not be built; retrying on the next daily run.', $type ) );
			return null;
		}
		if ( empty( $report['emailed'] ) ) {
			$sent = $this->container->get( ReportMailer::class )->send( $report );
			if ( is_wp_error( $sent ) ) {
				// Not marked as sent: the next daily run tries again.
				$logger->warning( 'reports', sprintf( 'The %1$s report #%2$d was not e-mailed (%3$s); retrying on the next daily run.', $type, (int) $report['id'], $sent->get_error_message() ) );
				return null;
			}
		}
		$last[ $type ] = $period['to'];
		$state->set( 'reports_last', $last );
		return (int) $report['id'];
	}

	/**
	 * Whether Search Console data covers the period end (or waiting longer is pointless).
	 *
	 * @param string $end   Period end.
	 * @param string $today Today.
	 * @return bool
	 */
	private function data_ready( $end, $today ) {
		if ( Ranges::diff_days( $end, $today ) >= self::MAX_WAIT_DAYS ) {
			return true;
		}
		$perf = $this->container->get( \SearchPilot\Analytics\PerformanceRepository::class );
		$conn = $perf->connections();
		if ( ! $conn['google'] && ! $conn['bing'] ) {
			return Ranges::diff_days( $end, $today ) >= 1;
		}
		$latest = $perf->latest_date( $conn['google'] ? 'google' : 'bing' );
		return $latest && $latest >= $end;
	}

	/**
	 * Keeps the report table small.
	 */
	public function prune() {
		$this->container->get( ReportBuilder::class )->prune( 120 );
	}
}
