<?php
/**
 * Import & migration module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Background import batches, background undo batches and the revert
 * handlers that make every import undoable.
 */
class ImportModule implements Module {

	/**
	 * Importer.
	 *
	 * @var Importer
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param Importer $importer Importer.
	 */
	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( Importer::HOOK, array( $this, 'run_batch' ) );
		add_action( Importer::UNDO_HOOK, array( $this, 'run_undo_batch' ) );
		add_action( 'searchpilot_register_revert_handlers', array( $this, 'register_handlers' ) );
	}

	/**
	 * Revert handlers.
	 *
	 * @param ActivityLog $log Log.
	 */
	public function register_handlers( ActivityLog $log ) {
		$log->register_handler( 'import_post', array( Importer::class, 'revert_post' ) );
		$log->register_handler( 'import_term', array( Importer::class, 'revert_term' ) );
	}

	/**
	 * Scheduler callback: one import batch.
	 */
	public function run_batch() {
		$this->importer->run_batch();
	}

	/**
	 * Scheduler callback: one undo batch.
	 */
	public function run_undo_batch() {
		$this->importer->run_undo_batch();
	}
}
