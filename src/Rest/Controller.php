<?php
/**
 * Base REST controller.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers. Every route declares a permission level:
 *   manage — settings/connections/automation (searchpilot_manage)
 *   view   — read dashboards and reports (searchpilot_view)
 *   edit   — apply SEO changes (searchpilot_edit + per-object checks in the callback)
 *
 * Callbacks are wrapped so unexpected exceptions become a generic 500
 * (details go to the redacted log, never to the client).
 */
abstract class Controller {

	/**
	 * Registers routes.
	 */
	abstract public function register_routes();

	/**
	 * Registers one route.
	 *
	 * @param string          $path       Path (e.g. '/settings' or '/issues/(?P<id>\d+)').
	 * @param string|string[] $methods    HTTP methods.
	 * @param callable        $callback   Callback( \WP_REST_Request $request ).
	 * @param string          $permission manage|view|edit.
	 * @param array           $args       Argument schema.
	 */
	protected function route( $path, $methods, callable $callback, $permission = 'manage', array $args = array() ) {
		register_rest_route(
			RestServer::REST_NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $this->wrap( $callback ),
				'permission_callback' => $this->permission( $permission ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Permission callback for a level.
	 *
	 * @param string $level manage|view|edit.
	 * @return callable
	 */
	protected function permission( $level ) {
		return static function () use ( $level ) {
			switch ( $level ) {
				case 'view':
					$ok = Capabilities::can_view();
					break;
				case 'edit':
					$ok = Capabilities::can_edit();
					break;
				default:
					$ok = Capabilities::can_manage();
			}
			return $ok ? true : new \WP_Error( 'rest_forbidden', __( 'You are not allowed to do that.', 'searchpilot-pro' ), array( 'status' => rest_authorization_required_code() ) );
		};
	}

	/**
	 * Wraps a callback with error handling.
	 *
	 * @param callable $callback Callback.
	 * @return callable
	 */
	private function wrap( callable $callback ) {
		return static function ( \WP_REST_Request $request ) use ( $callback ) {
			try {
				$result = call_user_func( $callback, $request );
			} catch ( \Throwable $e ) {
				Logger::instance()->error(
					'rest',
					sprintf( '%s %s failed: %s', $request->get_method(), $request->get_route(), $e->getMessage() ),
					array(
						'file' => basename( $e->getFile() ),
						'line' => $e->getLine(),
					)
				);
				return new \WP_Error( 'searchpilot_error', __( 'Something went wrong. Details were written to SearchPilot → System Logs.', 'searchpilot-pro' ), array( 'status' => 500 ) );
			}
			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				if ( ! is_array( $data ) || empty( $data['status'] ) ) {
					$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
				}
				return $result;
			}
			return rest_ensure_response( $result );
		};
	}

	/**
	 * page / per_page from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $default Default per page.
	 * @return array{0:int,1:int}
	 */
	protected function paging( \WP_REST_Request $request, $default = 25 ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 200, $per_page ) : $default;
		return array( $page, $per_page );
	}

	/**
	 * Standard paged payload.
	 *
	 * @param array $items    Items.
	 * @param int   $total    Total rows.
	 * @param int   $page     Page.
	 * @param int   $per_page Per page.
	 * @param array $extra    Extra keys.
	 * @return array
	 */
	protected function paged( array $items, $total, $page, $per_page, array $extra = array() ) {
		return array_merge(
			array(
				'items'    => array_values( $items ),
				'total'    => (int) $total,
				'page'     => (int) $page,
				'per_page' => (int) $per_page,
				'pages'    => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
			),
			$extra
		);
	}

	/**
	 * Error helper.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Integer id route argument schema.
	 *
	 * @return array
	 */
	protected function id_arg() {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Shortcut to a service.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	protected function service( $class ) {
		return \SearchPilot\Plugin::instance()->get( $class );
	}
}
