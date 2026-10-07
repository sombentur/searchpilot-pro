<?php
/**
 * Activity log endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * GET /activity, POST /activity/{id}/undo, POST /activity/group/{group}/undo.
 */
class ActivityController extends Controller {

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param ActivityLog $log Log.
	 */
	public function __construct( ActivityLog $log ) {
		$this->log = $log;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/activity', 'GET', array( $this, 'list_activity' ), 'view' );
		$this->route( '/activity/(?P<id>\d+)/undo', 'POST', array( $this, 'undo' ), 'edit', $this->id_arg() );
		$this->route( '/activity/group/(?P<group>[a-z0-9]+)/undo', 'POST', array( $this, 'undo_group' ), 'manage' );
	}

	/**
	 * GET /activity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_activity( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$result                  = $this->log->query(
			array(
				'page'        => $page,
				'per_page'    => $per_page,
				'source'      => sanitize_key( (string) $request->get_param( 'source' ) ),
				'action'      => sanitize_key( (string) $request->get_param( 'action' ) ),
				'object_type' => sanitize_key( (string) $request->get_param( 'object_type' ) ),
				'object_id'   => absint( $request->get_param( 'object_id' ) ),
				'group_id'    => sanitize_key( (string) $request->get_param( 'group_id' ) ),
				'status'      => sanitize_key( (string) $request->get_param( 'status' ) ),
				'reversible'  => $request->get_param( 'reversible' ),
				'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			)
		);
		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * POST /activity/{id}/undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function undo( \WP_REST_Request $request ) {
		$row = $this->log->get( (int) $request['id'] );
		if ( ! $row ) {
			return $this->error( 'searchpilot_not_found', __( 'Activity entry not found.', 'searchpilot-pro' ), 404 );
		}
		// Object-level check: undoing a post change requires permission to edit that post.
		if ( in_array( $row->object_type, array( 'post', 'attachment' ), true ) && ! Capabilities::can_edit( (int) $row->object_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		if ( ! in_array( $row->object_type, array( 'post', 'attachment' ), true ) && ! Capabilities::can_manage() ) {
			return $this->error( 'rest_forbidden', __( 'Only administrators can undo this change.', 'searchpilot-pro' ), 403 );
		}
		$result = $this->log->undo( (int) $row->id, (bool) $request->get_param( 'force' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'undone' => true,
			'entry'  => $this->log->present( $this->log->get( (int) $row->id ) ),
		);
	}

	/**
	 * POST /activity/group/{group}/undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function undo_group( \WP_REST_Request $request ) {
		return $this->log->undo_group( sanitize_key( (string) $request['group'] ) );
	}
}
