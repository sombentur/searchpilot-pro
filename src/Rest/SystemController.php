<?php
/**
 * System status endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Credentials;
use SearchPilot\Core\CoreModule;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\SetupAudit;
use SearchPilot\Database\Migrator;
use SearchPilot\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * GET /system, POST /system/audit, POST /system/repair, POST /system/run.
 */
class SystemController extends Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/system', 'GET', array( $this, 'status' ), 'manage' );
		$this->route( '/system/audit', 'POST', array( $this, 'audit' ), 'manage' );
		$this->route( '/system/repair', 'POST', array( $this, 'repair' ), 'manage' );
		$this->route(
			'/system/run',
			'POST',
			array( $this, 'run_job' ),
			'manage',
			array(
				// Validated in run_job() so both "daily" and "searchpilot_daily" are accepted
				// and a wrong or missing value gets a 400 that lists the accepted ones.
				'job' => array(
					'type'        => 'string',
					'description' => __( 'Job to run: every_5min, hourly, daily or weekly (the searchpilot_ prefix is optional).', 'searchpilot-pro' ),
				),
			)
		);
	}

	/**
	 * Recurring job hook for a job name: "daily", "searchpilot_daily" (any
	 * case, surrounding spaces ignored); '' when the name is unknown.
	 *
	 * @param mixed $name Job name.
	 * @return string Hook name or ''.
	 */
	public static function job_hook( $name ) {
		if ( ! is_scalar( $name ) ) {
			return '';
		}
		$name = strtolower( trim( (string) $name ) );
		if ( '' === $name || ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return '';
		}
		$hook = 0 === strpos( $name, 'searchpilot_' ) ? $name : 'searchpilot_' . $name;
		return array_key_exists( $hook, Scheduler::RECURRING ) ? $hook : '';
	}

	/**
	 * Accepted job names (short and full forms).
	 *
	 * @return string[]
	 */
	public static function accepted_jobs() {
		$out = array();
		foreach ( array_keys( Scheduler::RECURRING ) as $hook ) {
			$out[] = substr( $hook, strlen( 'searchpilot_' ) );
		}
		return array_merge( $out, array_keys( Scheduler::RECURRING ) );
	}

	/**
	 * GET /system.
	 *
	 * @return array
	 */
	public function status() {
		$scheduler = $this->service( Scheduler::class );
		$runs      = array();
		foreach ( array_keys( Scheduler::RECURRING ) as $hook ) {
			$last          = $scheduler->last_runs()[ $hook ] ?? 0;
			$next          = Scheduler::next_run( $hook );
			$runs[ $hook ] = array(
				'last_run' => $last ? gmdate( 'c', (int) $last ) : null,
				'next_run' => $next ? gmdate( 'c', (int) $next ) : null,
			);
		}
		$cred_error = $this->service( Credentials::class )->error();
		return array(
			'version'     => SEARCHPILOT_VERSION,
			'db_version'  => get_option( Migrator::OPTION, '' ),
			'environment' => $this->service( Environment::class )->summary(),
			'scheduler'   => array(
				'backend' => Scheduler::backend(),
				'healthy' => $scheduler->healthy(),
				'jobs'    => $runs,
			),
			'quotas'      => $this->service( RateLimiter::class )->snapshot(),
			'tables'      => array( 'missing' => Schema::missing_tables() ),
			'compat'      => $this->service( ConflictDetector::class )->report(),
			'credentials' => array( 'error' => $cred_error ? $cred_error->get_error_message() : '' ),
			'audit'       => $this->service( SetupAudit::class )->last(),
		);
	}

	/**
	 * POST /system/audit.
	 *
	 * @return array
	 */
	public function audit() {
		return $this->service( SetupAudit::class )->run();
	}

	/**
	 * POST /system/repair: reinstall tables, reschedule jobs, flush rewrites.
	 *
	 * @return array
	 */
	public function repair() {
		Migrator::upgrade( (string) get_option( Migrator::OPTION, '' ) );
		Schema::install();
		delete_transient( 'searchpilot_schedule_checked' );
		$this->service( Scheduler::class )->ensure_scheduled();
		CoreModule::request_rewrite_flush();
		return array(
			'repaired' => true,
			'missing'  => Schema::missing_tables(),
		);
	}

	/**
	 * POST /system/run: runs a recurring job now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function run_job( \WP_REST_Request $request ) {
		$job = self::job_hook( $request->get_param( 'job' ) );
		if ( '' === $job ) {
			$accepted = self::accepted_jobs();
			return new \WP_Error(
				'searchpilot_invalid_job',
				/* translators: %s: accepted job names. */
				sprintf( __( 'Unknown job. Accepted values: %s.', 'searchpilot-pro' ), implode( ', ', $accepted ) ),
				array(
					'status'   => 400,
					'accepted' => $accepted,
				)
			);
		}
		$start = microtime( true );
		do_action( $job ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		return array(
			'ran'         => true,
			'job'         => $job,
			'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}
}
