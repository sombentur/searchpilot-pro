<?php
/**
 * Search performance module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Issues\IssueRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the performance sync and the opportunity engine to the scheduler.
 * Everything here runs in the background or in the admin; the public site
 * is never touched.
 */
class AnalyticsModule implements Module {

	const RESCAN_HOOK = 'searchpilot_opportunities_rescan';

	/**
	 * Container (services are built lazily inside callbacks).
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_daily', array( $this, 'daily' ), 20 );
		add_action( 'searchpilot_initial_sync', array( $this, 'initial' ), 20 );
		add_action( 'searchpilot_hourly', array( $this, 'tick' ), 30 );
		add_action( 'searchpilot_every_5min', array( $this, 'tick' ), 30 );
		add_action( PerformanceSync::CONTINUE_HOOK, array( $this, 'tick' ) );
		add_action( 'searchpilot_page_crawled', array( $this, 'page_crawled' ), 30 );
		add_action( 'searchpilot_activity_undone', array( $this, 'activity_undone' ) );
		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 20, 2 );
		add_action( self::RESCAN_HOOK, array( $this, 'rescan' ) );
		add_action( 'permalink_structure_changed', array( $this, 'clear_url_cache' ) );
	}

	/**
	 * Registers opportunity issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$this->container->get( Opportunities::class )->register_types( $registry );
	}

	/**
	 * Daily sync.
	 */
	public function daily() {
		$this->container->get( PerformanceSync::class )->daily();
	}

	/**
	 * First sync after setup.
	 */
	public function initial() {
		$this->container->get( PerformanceSync::class )->initial();
	}

	/**
	 * Continues a running sync (cheap no-op when idle).
	 */
	public function tick() {
		$sync = $this->container->get( PerformanceSync::class );
		if ( $sync->is_running() ) {
			$sync->tick();
		}
	}

	/**
	 * Records the structured data found on crawled posts.
	 *
	 * @param array $page Crawled page.
	 */
	public function page_crawled( $page ) {
		$this->container->get( Opportunities::class )->record_crawl( $page );
	}

	/**
	 * Clears "applied" markers of undone title/description changes.
	 *
	 * @param object $row Activity row.
	 */
	public function activity_undone( $row ) {
		$this->container->get( Opportunities::class )->on_activity_undone( $row );
	}

	/**
	 * Re-scans opportunities when their threshold changes.
	 *
	 * @param array $new    New settings.
	 * @param array $before Old settings.
	 */
	public function settings_updated( $new, $before ) {
		$old_min = (int) ( $before['analytics']['min_impressions'] ?? 0 );
		$new_min = (int) ( $new['analytics']['min_impressions'] ?? 0 );
		if ( $old_min !== $new_min ) {
			$this->container->get( \SearchPilot\Core\Scheduler::class )->enqueue_async( self::RESCAN_HOOK, array(), 30 );
		}
	}

	/**
	 * Background opportunity re-scan (stored data only, no API calls).
	 */
	public function rescan() {
		$opportunities = $this->container->get( Opportunities::class );
		\SearchPilot\Core\Lock::run(
			PerformanceSync::LOCK,
			PerformanceSync::LOCK_TTL,
			static function () use ( $opportunities ) {
				return $opportunities->run();
			}
		);
	}

	/**
	 * Forgets cached URL → post lookups.
	 */
	public function clear_url_cache() {
		$this->container->get( UrlResolver::class )->clear();
	}
}
