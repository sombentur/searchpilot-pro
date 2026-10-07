<?php
/**
 * Redirect endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Redirects\RedirectImporter;
use SearchPilot\Redirects\RedirectMatcher;
use SearchPilot\Redirects\RedirectsModule;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /redirects, PUT/DELETE /redirects/{id}, POST /redirects/bulk,
 * POST /redirects/test, GET /redirects/export, POST /redirects/import,
 * GET /redirects/import/sources, POST /redirects/import/{source}.
 * Every change goes through the activity log and can be undone.
 */
class RedirectsController extends Controller {

	/**
	 * Redirect module.
	 *
	 * @var RedirectsModule
	 */
	private $module;

	/**
	 * Importer.
	 *
	 * @var RedirectImporter
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param RedirectsModule  $module   Module.
	 * @param RedirectImporter $importer Importer.
	 */
	public function __construct( RedirectsModule $module, RedirectImporter $importer ) {
		$this->module   = $module;
		$this->importer = $importer;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$rule_args = array(
			'source'      => array( 'type' => 'string' ),
			'target'      => array( 'type' => 'string' ),
			'match_type'  => array(
				'type' => 'string',
				'enum' => array( 'exact', 'prefix', 'regex' ),
			),
			'status_code' => array( 'type' => 'integer' ),
			'code'        => array( 'type' => 'integer' ),
			'enabled'     => array( 'type' => 'boolean' ),
			'notes'       => array( 'type' => 'string' ),
		);
		$this->route( '/redirects', 'GET', array( $this, 'list_rules' ), 'view' );
		$this->route( '/redirects', 'POST', array( $this, 'create' ), 'manage', $rule_args );
		$this->route( '/redirects/(?P<id>\d+)', 'GET', array( $this, 'get_rule' ), 'view', $this->id_arg() );
		$this->route( '/redirects/(?P<id>\d+)', 'PUT', array( $this, 'update' ), 'manage', array_merge( $this->id_arg(), $rule_args ) );
		$this->route( '/redirects/(?P<id>\d+)', 'DELETE', array( $this, 'delete' ), 'manage', $this->id_arg() );
		$this->route( '/redirects/bulk', 'POST', array( $this, 'bulk' ), 'manage' );
		$this->route(
			'/redirects/test',
			'POST',
			array( $this, 'test' ),
			'view',
			array(
				'url' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
		$this->route( '/redirects/export', 'GET', array( $this, 'export' ), 'manage' );
		$this->route(
			'/redirects/import',
			'POST',
			array( $this, 'import_csv' ),
			'manage',
			array(
				'csv' => array(
					'type'     => 'string',
					'required' => true,
				),
			)
		);
		$this->route( '/redirects/import/sources', 'GET', array( $this, 'import_sources' ), 'manage' );
		$this->route( '/redirects/import/(?P<source>[a-z_]+)', 'POST', array( $this, 'import_source' ), 'manage' );
	}

	/**
	 * GET /redirects.
	 *
	 * @param \WP_REST_Request $request Request (search, match_type, origin, enabled, page, per_page, orderby hits|updated|created|source|last_hit, order).
	 * @return array
	 */
	public function list_rules( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$enabled                 = $request->get_param( 'enabled' );
		$result                  = $this->module->repository()->query(
			array(
				'search'     => RedirectMatcher::clean_input( $request->get_param( 'search' ), 500 ),
				'match_type' => sanitize_key( (string) $request->get_param( 'match_type' ) ),
				'origin'     => sanitize_key( (string) $request->get_param( 'origin' ) ),
				'enabled'    => null === $enabled || '' === $enabled ? '' : (int) rest_sanitize_boolean( $enabled ),
				'orderby'    => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'      => sanitize_key( (string) $request->get_param( 'order' ) ),
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);
		$issues                  = $this->service( IssueRepository::class );
		$problems                = array();
		foreach ( array( 'redirect_loop', 'redirect_chain', 'redirect_to_error' ) as $type ) {
			$problems[ $type ] = (int) $issues->query(
				array(
					'type'     => array( $type ),
					'per_page' => 1,
				)
			)['total'];
		}
		$conflicts = $this->service( ConflictDetector::class );
		$owner     = $conflicts->owner( 'redirects' );
		$plugins   = $conflicts->detect();
		return $this->paged(
			array_map( array( $this->module, 'present' ), $result['items'] ),
			$result['total'],
			$page,
			$per_page,
			array(
				'stats'      => $this->module->repository()->stats(),
				'problems'   => $problems,
				'active'     => $this->module->active(),
				'owner'      => $owner,
				'owner_name' => 'searchpilot' === $owner ? __( 'SearchPilot Pro', 'searchpilot-pro' ) : ( 'off' === $owner ? '' : (string) ( $plugins[ $owner ]['name'] ?? $owner ) ),
				'auto_slug'  => (bool) \SearchPilot\Plugin::instance()->get( \SearchPilot\Core\Settings::class )->get( 'redirects.auto_slug', true ),
			)
		);
	}

	/**
	 * Rule input from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function input( \WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'source', 'target' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = RedirectMatcher::clean_input( $request->get_param( $field ) );
			}
		}
		if ( null !== $request->get_param( 'match_type' ) ) {
			$input['match_type'] = sanitize_key( (string) $request->get_param( 'match_type' ) );
		}
		$code = null !== $request->get_param( 'status_code' ) ? $request->get_param( 'status_code' ) : $request->get_param( 'code' );
		if ( null !== $code ) {
			$input['status_code'] = absint( $code );
		}
		if ( null !== $request->get_param( 'enabled' ) ) {
			$input['enabled'] = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		}
		if ( null !== $request->get_param( 'notes' ) ) {
			$input['notes'] = sanitize_text_field( (string) $request->get_param( 'notes' ) );
		}
		return $input;
	}

	/**
	 * POST /redirects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$input = array_merge(
			array(
				'match_type'  => 'exact',
				'status_code' => 301,
			),
			$this->input( $request )
		);
		return $this->module->create_rule( $input, array( 'origin' => 'manual' ) );
	}

	/**
	 * GET /redirects/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_rule( \WP_REST_Request $request ) {
		$rule = $this->module->present( $this->module->repository()->get( (int) $request['id'] ) );
		return $rule ? $rule : $this->error( 'searchpilot_not_found', __( 'Redirect not found.', 'searchpilot-pro' ), 404 );
	}

	/**
	 * PUT /redirects/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		return $this->module->update_rule( (int) $request['id'], $this->input( $request ) );
	}

	/**
	 * DELETE /redirects/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		$n = $this->module->delete_rules( array( (int) $request['id'] ) );
		if ( ! $n ) {
			return $this->error( 'searchpilot_not_found', __( 'Redirect not found.', 'searchpilot-pro' ), 404 );
		}
		return array(
			'deleted' => true,
			'id'      => (int) $request['id'],
		);
	}

	/**
	 * POST /redirects/bulk {ids, action: enable|disable|delete}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 500 );
		if ( empty( $ids ) || ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose redirects and an action.', 'searchpilot-pro' ) );
		}
		if ( 'delete' === $action ) {
			$done = $this->module->delete_rules( $ids );
		} else {
			$done = $this->module->set_enabled( $ids, 'enable' === $action );
		}
		return array(
			'done'   => (int) $done,
			'action' => $action,
		);
	}

	/**
	 * POST /redirects/test {url}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$url = RedirectMatcher::clean_input( $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return $this->error( 'searchpilot_invalid', __( 'Enter an address to test.', 'searchpilot-pro' ) );
		}
		if ( preg_match( '#^https?://#i', $url ) && ! \SearchPilot\Support\Url::is_internal( $url ) ) {
			return $this->error( 'searchpilot_external', __( 'Enter an address on this site.', 'searchpilot-pro' ) );
		}
		return $this->module->test( $url );
	}

	/**
	 * GET /redirects/export.
	 *
	 * @return array
	 */
	public function export() {
		return $this->importer->export_csv();
	}

	/**
	 * POST /redirects/import {csv}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function import_csv( \WP_REST_Request $request ) {
		return $this->importer->import_csv( (string) $request->get_param( 'csv' ) );
	}

	/**
	 * GET /redirects/import/sources.
	 *
	 * @return array
	 */
	public function import_sources() {
		return array( 'sources' => $this->importer->sources() );
	}

	/**
	 * POST /redirects/import/{source}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function import_source( \WP_REST_Request $request ) {
		return $this->importer->import_source( sanitize_key( (string) $request['source'] ) );
	}
}
