<?php
/**
 * 404 monitor endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Issues\IssueRepository;
use SearchPilot\Redirects\RedirectMatcher;
use SearchPilot\Redirects\RedirectsModule;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * GET /404, GET /404/stats, GET /404/{id}/suggest, POST /404/{id}/redirect,
 * POST /404/{id}/ignore, POST /404/{id}/reopen, DELETE /404/{id}, POST /404/clear.
 */
class NotFoundController extends Controller {

	/**
	 * Redirect module.
	 *
	 * @var RedirectsModule
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param RedirectsModule $module Module.
	 */
	public function __construct( RedirectsModule $module ) {
		$this->module = $module;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/404', 'GET', array( $this, 'list_rows' ), 'view' );
		$this->route( '/404/stats', 'GET', array( $this, 'stats' ), 'view' );
		$this->route( '/404/(?P<id>\d+)/suggest', 'GET', array( $this, 'suggest' ), 'view', $this->id_arg() );
		$this->route(
			'/404/(?P<id>\d+)/redirect',
			'POST',
			array( $this, 'redirect' ),
			'manage',
			array_merge(
				$this->id_arg(),
				array(
					'target' => array( 'type' => 'string' ),
					'code'   => array(
						'type'    => 'integer',
						'default' => 301,
					),
				)
			)
		);
		$this->route( '/404/(?P<id>\d+)/ignore', 'POST', array( $this, 'ignore' ), 'manage', $this->id_arg() );
		$this->route( '/404/(?P<id>\d+)/reopen', 'POST', array( $this, 'reopen' ), 'manage', $this->id_arg() );
		$this->route( '/404/(?P<id>\d+)', 'DELETE', array( $this, 'delete' ), 'manage', $this->id_arg() );
		$this->route(
			'/404/clear',
			'POST',
			array( $this, 'clear' ),
			'manage',
			array(
				'older_than_days' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * GET /404.
	 *
	 * @param \WP_REST_Request $request Request (search, bots include|exclude|only, status open|ignored|redirected|any, orderby hits|last_seen, order, page, per_page).
	 * @return array
	 */
	public function list_rows( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$bots                    = sanitize_key( (string) $request->get_param( 'bots' ) );
		$status                  = sanitize_key( (string) $request->get_param( 'status' ) );
		$result                  = $this->module->monitor()->query(
			array(
				'search'   => RedirectMatcher::clean_input( $request->get_param( 'search' ), 500 ),
				'bots'     => in_array( $bots, array( 'include', 'exclude', 'only' ), true ) ? $bots : 'include',
				'status'   => '' !== $status ? $status : 'open',
				'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		return $this->paged(
			$result['items'],
			$result['total'],
			$page,
			$per_page,
			array( 'active' => $this->module->monitor()->active() )
		);
	}

	/**
	 * GET /404/stats.
	 *
	 * @return array
	 */
	public function stats() {
		return $this->module->monitor()->stats();
	}

	/**
	 * Row or error.
	 *
	 * @param int $id Id.
	 * @return array|\WP_Error
	 */
	private function row( $id ) {
		$row = $this->module->monitor()->get( (int) $id );
		return $row ? $row : $this->error( 'searchpilot_not_found', __( 'This 404 entry no longer exists.', 'searchpilot-pro' ), 404 );
	}

	/**
	 * GET /404/{id}/suggest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function suggest( \WP_REST_Request $request ) {
		$row = $this->row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return array(
			'row'         => $row,
			'suggestions' => $this->module->monitor()->suggest( $row['url'], 5 ),
		);
	}

	/**
	 * POST /404/{id}/redirect {target, code}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function redirect( \WP_REST_Request $request ) {
		$row = $this->row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$code = absint( $request->get_param( 'code' ) );
		// The bare path: `/old-post/?fbclid=abc` must redirect every variant of /old-post/.
		$bare   = RedirectMatcher::split( $row['path'] )[0];
		$result = $this->module->create_rule(
			array(
				'source'      => $bare,
				'target'      => RedirectMatcher::clean_input( $request->get_param( 'target' ) ),
				'status_code' => $code ? $code : 301,
				'match_type'  => 'exact',
			),
			array( 'origin' => '404' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = $this->module->monitor()->mark_redirected( $bare, (int) $result['id'] );
		$this->module->monitor()->set_status( array( (int) $row['id'] ), 'redirected', (int) $result['id'] );
		$this->service( IssueRepository::class )->resolve( 'frequent_404', 'url', (int) $row['id'], $row['url'] );
		return array(
			'rule'    => $result,
			'row'     => $this->module->monitor()->get( (int) $row['id'] ),
			'updated' => count( array_unique( array_merge( $rows, array( (int) $row['id'] ) ) ) ),
		);
	}

	/**
	 * POST /404/{id}/ignore.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function ignore( \WP_REST_Request $request ) {
		$row = $this->row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->module->monitor()->set_status( array( (int) $row['id'] ), 'ignored' );
		$this->service( IssueRepository::class )->resolve( 'frequent_404', 'url', (int) $row['id'], $row['url'] );
		return array( 'row' => $this->module->monitor()->get( (int) $row['id'] ) );
	}

	/**
	 * POST /404/{id}/reopen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function reopen( \WP_REST_Request $request ) {
		$row = $this->row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->module->monitor()->set_status( array( (int) $row['id'] ), 'open' );
		return array( 'row' => $this->module->monitor()->get( (int) $row['id'] ) );
	}

	/**
	 * DELETE /404/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		$row = $this->row( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$this->module->monitor()->delete( array( (int) $row['id'] ) );
		$this->service( IssueRepository::class )->resolve( 'frequent_404', 'url', (int) $row['id'], $row['url'] );
		return array(
			'deleted' => true,
			'id'      => (int) $row['id'],
			'url'     => Url::display( $row['url'] ),
		);
	}

	/**
	 * POST /404/clear {older_than_days?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function clear( \WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'older_than_days' ) );
		return array(
			'deleted'         => $this->module->monitor()->clear( $days ),
			'older_than_days' => $days,
		);
	}
}
