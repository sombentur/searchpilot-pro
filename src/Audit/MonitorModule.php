<?php
/**
 * Monitoring module: scheduled site checks and alerts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Audit;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Scheduler;
use SearchPilot\Issues\IssueRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Critical checks hourly, the full set daily (in its own background
 * event), alert digests from `searchpilot_issue_detected`.
 */
class MonitorModule implements Module {

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
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_hourly', array( $this, 'hourly' ), 30 );
		add_action( 'searchpilot_daily', array( $this, 'daily' ), 30 );
		add_action( 'searchpilot_initial_sync', array( $this, 'daily' ), 30 );
		add_action( 'searchpilot_monitor_full', array( $this, 'full' ) );
		add_action( 'searchpilot_issue_detected', array( $this, 'issue_detected' ), 10, 4 );
		add_action( Alerts::HOOK, array( $this, 'send_alerts' ) );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard_summary' ) );
	}

	/**
	 * Registers site-level issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$this->container->get( SiteMonitor::class )->register_types( $registry );
	}

	/**
	 * Hourly critical checks.
	 */
	public function hourly() {
		Lock::run(
			'monitor',
			300,
			function () {
				$this->container->get( SiteMonitor::class )->run( 'critical' );
			}
		);
	}

	/**
	 * Daily: full audit in a separate background request.
	 */
	public function daily() {
		$this->container->get( Scheduler::class )->enqueue_async( 'searchpilot_monitor_full', array(), 120 );
	}

	/**
	 * Full checks (background event).
	 */
	public function full() {
		Lock::run(
			'monitor',
			600,
			function () {
				$this->container->get( SiteMonitor::class )->run( 'full' );
			}
		);
	}

	/**
	 * Queues alerts for new issues.
	 *
	 * @param int    $id       Issue id.
	 * @param string $type     Type.
	 * @param string $severity Severity.
	 * @param array  $args     Args.
	 */
	public function issue_detected( $id, $type, $severity, $args = array() ) {
		try {
			$this->container->get( Alerts::class )->on_issue_detected( (int) $id, (string) $type, (string) $severity, is_array( $args ) ? $args : array() );
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->error( 'audit', 'Alert queueing failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Sends the alert digest (background event).
	 */
	public function send_alerts() {
		$this->container->get( Alerts::class )->send_digest();
	}

	/**
	 * Dashboard section `monitor`.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard_summary( $summary ) {
		$summary            = is_array( $summary ) ? $summary : array();
		$summary['monitor'] = $this->container->get( SiteMonitor::class )->summary();
		return $summary;
	}
}
