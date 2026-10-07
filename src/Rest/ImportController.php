<?php
/**
 * Import & migration endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Lock;
use SearchPilot\Import\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * GET /import, POST /import/start, POST /import/cancel, POST /import/undo.
 */
class ImportController extends Controller {

	/**
	 * Importer.
	 *
	 * @var Importer
	 */
	private $importer;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param Importer         $importer  Importer.
	 * @param ConflictDetector $conflicts Conflicts.
	 */
	public function __construct( Importer $importer, ConflictDetector $conflicts ) {
		$this->importer  = $importer;
		$this->conflicts = $conflicts;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/import', 'GET', array( $this, 'status' ), 'manage' );
		$this->route(
			'/import/start',
			'POST',
			array( $this, 'start' ),
			'manage',
			array(
				'source'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'overwrite' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'include'   => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string' ),
					'default' => array( 'posts', 'terms' ),
				),
			)
		);
		$this->route( '/import/cancel', 'POST', array( $this, 'cancel' ), 'manage' );
		$this->route(
			'/import/undo',
			'POST',
			array( $this, 'undo' ),
			'manage',
			array(
				'group_id' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
	}

	/**
	 * GET /import (keeps a stalled run moving when WP-Cron is slow).
	 *
	 * @param \WP_REST_Request $request Request (fresh=1 recounts).
	 * @return array
	 */
	public function status( \WP_REST_Request $request ) {
		$progress = $this->importer->progress();
		if ( 'running' === ( $progress['status'] ?? '' ) && time() - (int) ( $progress['updated_ts'] ?? 0 ) > 60 && ! Lock::held( 'import' ) ) {
			$this->importer->run_batch( 10 );
		}
		$undo = $this->importer->undo_progress();
		if ( 'running' === ( $undo['status'] ?? '' ) && ! Lock::held( 'import_undo' ) ) {
			$this->importer->run_undo_batch();
		}
		return $this->payload( (bool) rest_sanitize_boolean( $request->get_param( 'fresh' ) ) );
	}

	/**
	 * Full payload.
	 *
	 * @param bool $fresh Recount sources.
	 * @return array
	 */
	private function payload( $fresh = false ) {
		$names = array( 'searchpilot' => __( 'SearchPilot', 'searchpilot-pro' ) );
		foreach ( $this->conflicts->detect() as $id => $plugin ) {
			$names[ $id ] = (string) $plugin['name'];
		}
		$owners = array();
		foreach ( array( 'title', 'description', 'canonical', 'robots', 'social', 'schema', 'breadcrumbs', 'sitemap' ) as $feature ) {
			$owner              = $this->conflicts->owner( $feature );
			$owners[ $feature ] = array(
				'owner' => $owner,
				'name'  => $names[ $owner ] ?? $owner,
			);
		}
		return array(
			'sources'  => $this->importer->availability( $fresh ),
			'progress' => $this->importer->progress(),
			'undo'     => $this->importer->undo_progress(),
			'history'  => $this->importer->history(),
			'owners'   => $owners,
			'labels'   => ConflictDetector::feature_labels(),
		);
	}

	/**
	 * POST /import/start.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function start( \WP_REST_Request $request ) {
		$include = array_map( 'sanitize_key', (array) $request->get_param( 'include' ) );
		$result  = $this->importer->start( (string) $request->get_param( 'source' ), (bool) $request->get_param( 'overwrite' ), $include );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Show progress right away; the rest continues in the background.
		$this->importer->run_batch( 8 );
		return $this->payload();
	}

	/**
	 * POST /import/cancel.
	 *
	 * @return array
	 */
	public function cancel() {
		$this->importer->cancel();
		return $this->payload();
	}

	/**
	 * POST /import/undo {group_id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function undo( \WP_REST_Request $request ) {
		$result = $this->importer->undo( (string) $request->get_param( 'group_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->payload();
	}
}
