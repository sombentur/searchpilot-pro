<?php
/**
 * Core hooks: scheduling, maintenance, multisite, translations.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Issues\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Wires shared infrastructure.
 */
class CoreModule implements Module {

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Scheduler $scheduler Scheduler.
	 * @param Logger    $logger    Logger.
	 */
	public function __construct( Scheduler $scheduler, Logger $logger ) {
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		$this->scheduler->register();
		\SearchPilot\Plugin::instance()->get( Compat\CachePurger::class )->register();

		add_filter( 'user_has_cap', array( $this, 'grant_admin_caps' ), 10, 4 );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 999 );

		add_action( 'searchpilot_daily', array( $this, 'maintenance' ) );
		add_action( 'searchpilot_hourly', array( $this, 'schedule_auto_fix' ), 50 );
		add_action( 'searchpilot_auto_fix', array( $this, 'auto_fix' ) );

		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 5, 2 );
		add_action( 'activated_plugin', array( $this, 'plugins_changed' ) );
		add_action( 'deactivated_plugin', array( $this, 'plugins_changed' ) );

		// Multisite: install on new sites, drop tables with deleted sites.
		add_action( 'wp_initialize_site', array( Activator::class, 'on_new_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( $this, 'drop_site_tables' ) );
	}

	/**
	 * Users who can manage_options always hold every SearchPilot capability,
	 * even when roles were reset after activation.
	 *
	 * @param array    $allcaps All caps of the user.
	 * @param string[] $caps    Required primitive caps.
	 * @param array    $args    Requested cap and arguments.
	 * @param \WP_User $user    User.
	 * @return array
	 */
	public function grant_admin_caps( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( array( Capabilities::MANAGE, Capabilities::VIEW, Capabilities::EDIT ) as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'searchpilot-pro', false, dirname( SEARCHPILOT_BASENAME ) . '/languages' );
	}

	/**
	 * Flushes rewrite rules once after activation or routing-related changes.
	 */
	public function maybe_flush_rewrites() {
		// Kept autoloaded as 0/1: a missing option would cost an uncached query on every request.
		if ( get_option( 'searchpilot_flush_rewrite' ) ) {
			update_option( 'searchpilot_flush_rewrite', 0, true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Requests a rewrite flush on the next request.
	 */
	public static function request_rewrite_flush() {
		update_option( 'searchpilot_flush_rewrite', 1, true );
	}

	/**
	 * Settings side effects.
	 *
	 * @param array $new    New settings.
	 * @param array $before Previous settings.
	 */
	public function settings_updated( $new, $before ) {
		\SearchPilot\Plugin::instance()->get( ConflictDetector::class )->reset();
		foreach ( array( 'ownership', 'sitemap', 'content' ) as $section ) {
			if ( wp_json_encode( $new[ $section ] ?? array() ) !== wp_json_encode( $before[ $section ] ?? array() ) ) {
				self::request_rewrite_flush();
				break;
			}
		}
	}

	/**
	 * Another plugin was (de)activated: ownership may have changed.
	 */
	public function plugins_changed() {
		\SearchPilot\Plugin::instance()->get( ConflictDetector::class )->reset();
		self::request_rewrite_flush();
		delete_transient( 'searchpilot_schedule_checked' );
	}

	/**
	 * Daily housekeeping.
	 */
	public function maintenance() {
		Lock::run(
			'maintenance',
			600,
			function () {
				$plugin = \SearchPilot\Plugin::instance();
				$this->logger->prune();
				$plugin->get( ActivityLog::class )->prune();
				$plugin->get( IssueRepository::class )->prune( 60 );
				$plugin->get( \SearchPilot\Indexer\UrlRepository::class )->prune_removed( 180 );
				/**
				 * Fires during daily maintenance so modules can prune their tables.
				 */
				do_action( 'searchpilot_maintenance' );
			}
		);
	}

	/**
	 * Runs automatic fixes in their own background request (the hourly request
	 * is shared by many jobs and its time budget may already be spent).
	 */
	public function schedule_auto_fix() {
		$this->scheduler->enqueue_async( 'searchpilot_auto_fix', array(), 30 );
	}

	/**
	 * Applies automatic fixes allowed by the automation mode.
	 */
	public function auto_fix() {
		Lock::run(
			'autofix',
			900,
			function () {
				$stats = \SearchPilot\Plugin::instance()->get( Issues::class )->auto_fix( new TimeBudget( 30 ), 100 );
				if ( $stats['fixed'] || $stats['pending'] || $stats['failed'] ) {
					$this->logger->info( 'automation', 'Automatic fixes ran.', $stats );
				}
			}
		);
	}

	/**
	 * Adds our tables to the list dropped with a deleted site.
	 *
	 * @param string[] $tables Tables.
	 * @return string[]
	 */
	public function drop_site_tables( $tables ) {
		foreach ( Tables::ALL as $short ) {
			$tables[] = Tables::name( $short );
		}
		return $tables;
	}
}
