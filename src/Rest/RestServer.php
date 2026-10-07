<?php
/**
 * REST route registration.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every controller under /wp-json/searchpilot/v1.
 */
class RestServer implements Module {

	const REST_NAMESPACE = 'searchpilot/v1';

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether routes were registered in this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Controller classes. Missing classes are skipped.
	 *
	 * @return string[]
	 */
	public static function controllers() {
		$controllers = array(
			SettingsController::class,
			LogsController::class,
			ActivityController::class,
			IssuesController::class,
			SystemController::class,
			WizardController::class,
			EnginesController::class,
			QueueController::class,
			SitemapsController::class,
			RedirectsController::class,
			NotFoundController::class,
			SchemaController::class,
			SeoController::class,
			ImportController::class,
			AnalysisController::class,
			LinksController::class,
			IndexingController::class,
			AuditController::class,
			DashboardController::class,
			PerformanceController::class,
			OpportunitiesController::class,
			ReportsController::class,
		);
		/**
		 * Filters REST controller classes.
		 *
		 * @param string[] $controllers Classes.
		 */
		return (array) apply_filters( 'searchpilot_rest_controllers', $controllers );
	}

	/**
	 * Whether this request can reach SearchPilot routes. Public /wp/v2 calls
	 * (and the block editor's many core requests) skip building our 22
	 * controllers. Internal rest_do_request() calls (WP-CLI, preloading) and
	 * the route index still get them.
	 *
	 * @return bool
	 */
	private static function is_relevant_request() {
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		if ( '' === $route || '/' === $route ) {
			return true;
		}
		$route = '/' . ltrim( $route, '/' );
		$ours  = 0 === strpos( $route, '/' . self::REST_NAMESPACE ) || 0 === strpos( $route, '/searchpilot' ) || 0 === strpos( $route, '/batch/' );
		/**
		 * Filters whether SearchPilot registers its REST routes for this request.
		 *
		 * @param bool   $ours  Whether the route is SearchPilot's.
		 * @param string $route Requested route.
		 */
		return (bool) apply_filters( 'searchpilot_register_rest_routes', $ours, $route );
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Internal rest_do_request() calls to our routes made while serving another
		// namespace's REST request: register on demand, just before dispatch.
		add_filter( 'rest_pre_dispatch', array( $this, 'register_on_demand' ), 0, 3 );
	}

	/**
	 * Registers routes late when a request for them reaches the dispatcher.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function register_on_demand( $result, $server, $request ) {
		if ( ! $this->registered && $request instanceof \WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . self::REST_NAMESPACE ) ) {
			$this->register_routes( true );
		}
		return $result;
	}

	/**
	 * Registers routes of every controller.
	 *
	 * @param bool $force Register even when the request targets another namespace.
	 */
	public function register_routes( $force = false ) {
		if ( $this->registered || ( true !== $force && ! self::is_relevant_request() ) ) {
			return;
		}
		$this->registered = true;
		foreach ( self::controllers() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			try {
				$controller = $this->container->get( $class );
				if ( $controller instanceof Controller ) {
					$controller->register_routes();
				}
			} catch ( \Throwable $e ) {
				Logger::emergency_log( 'REST controller ' . $class . ' failed: ' . $e->getMessage() );
			}
		}
	}
}
