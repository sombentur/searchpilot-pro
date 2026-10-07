<?php
/**
 * Plugin bootstrap and module registry.
 *
 * @package SearchPilot
 */

namespace SearchPilot;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Requirements;
use SearchPilot\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every module. Modules only add hooks in register(); their
 * constructors must stay cheap (no queries, no remote calls) because the
 * front-end loads them on every request.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Registered module instances keyed by class.
	 *
	 * @var array<string,Module>
	 */
	private $modules = array();

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->container->set( self::class, $this );
		$this->container->set( Container::class, $this->container );
	}

	/**
	 * plugins_loaded callback.
	 */
	public static function boot() {
		if ( ! Requirements::met() ) {
			add_action( 'admin_notices', array( Requirements::class, 'render_notice' ) );
			return;
		}
		self::instance()->init();
	}

	/**
	 * Modules loaded in every context. Classes that do not exist are skipped,
	 * so optional modules can be removed without breaking the plugin.
	 *
	 * @return string[]
	 */
	public static function modules() {
		$modules = array(
			\SearchPilot\Core\CoreModule::class,
			\SearchPilot\Rest\RestServer::class,
			\SearchPilot\Api\ApiModule::class,
			\SearchPilot\Queue\QueueModule::class,
			\SearchPilot\Sitemap\SitemapModule::class,
			\SearchPilot\Sitemap\RobotsTxt::class,
			\SearchPilot\Redirects\RedirectsModule::class,
			\SearchPilot\Seo\FrontendModule::class,
			\SearchPilot\Seo\Breadcrumbs::class,
			\SearchPilot\Schema\SchemaModule::class,
			\SearchPilot\Editor\EditorModule::class,
			\SearchPilot\Import\ImportModule::class,
			\SearchPilot\Seo\Analysis\AnalysisModule::class,
			\SearchPilot\Links\LinksModule::class,
			\SearchPilot\Indexer\IndexerModule::class,
			\SearchPilot\Audit\MonitorModule::class,
			\SearchPilot\Fixes\FixersModule::class,
			\SearchPilot\Fixes\BulkRunner::class,
			\SearchPilot\Analytics\AnalyticsModule::class,
			\SearchPilot\Reports\ReportsModule::class,
			\SearchPilot\Cli\CliModule::class,
			\SearchPilot\Admin\AdminBar::class,
		);
		if ( is_admin() ) {
			$modules[] = \SearchPilot\Admin\Admin::class;
			$modules[] = \SearchPilot\Admin\Notices::class;
		}
		/**
		 * Filters the module classes SearchPilot boots.
		 *
		 * @param string[] $modules Module class names.
		 */
		return (array) apply_filters( 'searchpilot_modules', $modules );
	}

	/**
	 * Registers modules.
	 */
	private function init() {
		Migrator::maybe_upgrade();

		foreach ( self::modules() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			try {
				$module = $this->container->get( $class );
				if ( $module instanceof Module ) {
					$module->register();
					$this->modules[ $class ] = $module;
				}
			} catch ( \Throwable $e ) {
				// A broken module must never take the site down.
				Logger::emergency_log( 'Module ' . $class . ' failed to load: ' . $e->getMessage() );
			}
		}

		/**
		 * Fires after SearchPilot registered all modules.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'searchpilot_loaded', $this );
	}

	/**
	 * Resolves a service from the container.
	 *
	 * @param string $id Service id (class name).
	 * @return object
	 */
	public function get( $id ) {
		return $this->container->get( $id );
	}

	/**
	 * Returns the container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Whether a module is active.
	 *
	 * @param string $class Module class.
	 * @return bool
	 */
	public function has_module( $class ) {
		return isset( $this->modules[ ltrim( $class, '\\' ) ] );
	}
}
