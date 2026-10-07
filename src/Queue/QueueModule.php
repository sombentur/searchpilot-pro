<?php
/**
 * Submission queue module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Issues\IssueRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the change detector, the background worker, pruning, the
 * post-setup release and the `queue` dashboard section.
 */
class QueueModule implements Module {

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
		$this->container->get( ChangeDetector::class )->register();

		add_action( 'searchpilot_every_5min', array( $this, 'process' ) );
		add_action( SubmissionQueue::PROCESS_HOOK, array( $this, 'process' ) );
		add_action( 'searchpilot_maintenance', array( $this, 'prune' ) );
		add_action( 'searchpilot_setup_completed', array( $this, 'setup_completed' ) );
		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 20, 2 );
		add_action( 'update_option_blog_public', array( $this, 'visibility_changed' ), 20 );
		add_action( 'searchpilot_daily', array( $this, 'daily' ), 30 );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard_summary' ) );
	}

	/**
	 * Runs the worker (cron / async hook).
	 */
	public function process() {
		$this->container->get( QueueWorker::class )->run( 25 );
	}

	/**
	 * Daily pruning of old history rows.
	 */
	public function prune() {
		$this->container->get( QueueWorker::class )->prune();
	}

	/**
	 * Setup wizard finished: rows held only because setup was unfinished
	 * follow the automation policy now; then the worker is started.
	 */
	public function setup_completed() {
		$this->container->get( SubmissionQueue::class )->release_after_setup();
	}

	/**
	 * Mode, task policies or the environment guard changed: held rows follow
	 * the new rules (nothing is released while a guard still applies).
	 *
	 * @param array $new    New settings.
	 * @param array $before Previous settings.
	 */
	public function settings_updated( $new, $before ) {
		$watch = array(
			array( 'general', 'mode' ),
			array( 'general', 'environment_guard' ),
			array( 'automation', 'tasks' ),
		);
		foreach ( $watch as $path ) {
			$a = $new[ $path[0] ][ $path[1] ] ?? null;
			$b = $before[ $path[0] ][ $path[1] ] ?? null;
			if ( wp_json_encode( $a ) !== wp_json_encode( $b ) ) {
				$this->container->get( SubmissionQueue::class )->reevaluate_held( 'settings' );
				return;
			}
		}
	}

	/**
	 * Settings → Reading → "Discourage search engines" changed.
	 */
	public function visibility_changed() {
		$this->container->get( SubmissionQueue::class )->reevaluate_held( 'visibility' );
	}

	/**
	 * Daily: catches environment changes made outside WordPress (wp-config.php).
	 */
	public function daily() {
		$this->container->get( SubmissionQueue::class )->reevaluate_held( 'daily' );
	}

	/**
	 * Issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			QueueWorker::ISSUE,
			array(
				'label'    => __( 'Search engine submissions are failing', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$data  = (array) ( $issue['data'] ?? array() );
					$lines = array();
					foreach ( (array) ( $data['top'] ?? array() ) as $top ) {
						$lines[] = sprintf( '%1$s (%2$d×): %3$s', (string) ( $top['service'] ?? '' ), (int) ( $top['n'] ?? 0 ), (string) ( $top['last_error'] ?? '' ) );
					}
					/* translators: %d: number of failed submissions. */
					$lead = sprintf( __( '%d submissions to search engines failed in the last 24 hours.', 'searchpilot-pro' ), (int) ( $data['count'] ?? 0 ) );
					return $lines ? $lead . ' ' . implode( ' · ', $lines ) : $lead;
				},
				'why'      => __( 'Failed notifications mean search engines learn about new, changed and removed pages later. A rejected IndexNow key or an expired connection usually affects every submission.', 'searchpilot-pro' ),
				'fix'      => __( 'Open Search Engines → Submission queue and read the error of the failed rows. Fix the connection (verify the IndexNow key, reconnect Google or Bing), then press “Retry failed”.', 'searchpilot-pro' ),
				'action'   => 'review_queue',
			)
		);
	}

	/**
	 * Adds the `queue` dashboard section.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard_summary( $summary ) {
		$summary          = is_array( $summary ) ? $summary : array();
		$summary['queue'] = $this->container->get( QueueRepository::class )->stats();
		return $summary;
	}
}
