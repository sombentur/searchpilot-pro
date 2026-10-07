<?php
/**
 * Setup wizard state endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Scheduler;
use SearchPilot\Core\SetupAudit;
use SearchPilot\Core\State;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wizard, POST /wizard (save step), POST /wizard/complete.
 */
class WizardController extends Controller {

	const STEPS = array( 'audit', 'google', 'bing', 'indexnow', 'sitemap', 'content', 'automation', 'notifications', 'finish' );

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
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/wizard', 'GET', array( $this, 'get_state' ), 'manage' );
		$this->route( '/wizard', 'POST', array( $this, 'save_step' ), 'manage' );
		$this->route( '/wizard/complete', 'POST', array( $this, 'complete' ), 'manage' );
	}

	/**
	 * GET /wizard.
	 *
	 * @return array
	 */
	public function get_state() {
		$audit = $this->service( SetupAudit::class )->last();
		return array(
			'completed' => (bool) $this->state->get( 'wizard_completed', false ),
			'step'      => (string) $this->state->get( 'wizard_step', 'audit' ),
			'steps'     => self::STEPS,
			'audit'     => $audit,
		);
	}

	/**
	 * POST /wizard {step}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function save_step( \WP_REST_Request $request ) {
		$step = sanitize_key( (string) $request->get_param( 'step' ) );
		if ( in_array( $step, self::STEPS, true ) ) {
			$this->state->set( 'wizard_step', $step );
		}
		return $this->get_state();
	}

	/**
	 * POST /wizard/complete.
	 *
	 * @return array
	 */
	public function complete() {
		$first = ! $this->state->get( 'wizard_completed', false );
		$this->state->set_many(
			array(
				'wizard_completed'    => true,
				'wizard_step'         => 'finish',
				'wizard_completed_at' => gmdate( 'c' ),
			)
		);
		// Submissions are only sent from the address that finished setup (cloned/moved sites stay silent).
		$this->service( \SearchPilot\Core\Environment::class )->record_site_address();
		$this->service( Scheduler::class )->ensure_scheduled();
		// Kick off the first sync in the background (registry build, sitemap, analytics, audit).
		$this->service( Scheduler::class )->enqueue_async( 'searchpilot_initial_sync', array(), 10 );
		/**
		 * Fires when the administrator finishes the setup wizard.
		 *
		 * @param bool $first Whether it is the first completion.
		 */
		do_action( 'searchpilot_setup_completed', $first );
		return $this->get_state();
	}
}
