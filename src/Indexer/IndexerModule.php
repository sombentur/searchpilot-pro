<?php
/**
 * Indexer module: registry, crawler, URL Inspection.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Audit\Rescanner;
use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Issues\IssueRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the smart indexing engine to the scheduler. Nothing here runs on
 * front-end page views: work happens on cron hooks, in async events and in
 * REST requests. Services are resolved lazily inside callbacks.
 */
class IndexerModule implements Module {

	/**
	 * Container.
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
		add_action( 'searchpilot_initial_sync', array( $this, 'initial_sync' ) );
		add_action( 'searchpilot_hourly', array( $this, 'hourly' ), 20 );
		add_action( 'searchpilot_every_5min', array( $this, 'every_5min' ), 20 );
		add_action( 'searchpilot_weekly', array( $this, 'weekly' ), 20 );
		add_action( 'searchpilot_indexer_crawl', array( $this, 'crawl' ) );
		add_action( 'searchpilot_indexer_inspect', array( $this, 'inspect' ) );
		add_action( 'searchpilot_indexer_recheck', array( $this, 'recheck' ), 10, 1 );
		add_action( 'searchpilot_indexer_refresh', array( $this, 'refresh' ) );
		add_action( 'searchpilot_url_changed', array( $this, 'url_changed' ), 20, 1 );
		add_action( 'searchpilot_issue_fixed', array( $this, 'issue_fixed' ), 10, 2 );
		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 20, 2 );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard_summary' ) );
		// Registry hygiene: deleted, trashed and unpublished content leaves the registry with its issues
		// (independent of the change detector, which tracks terms only when term notifications are on).
		add_action( 'delete_term', array( $this, 'term_deleted' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'post_deleted' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'post_status_changed' ), 30, 3 );
		add_action( 'deleted_user', array( $this, 'user_deleted' ), 20, 1 );
		// Re-scans (contract C3) and the audit freshness pass after the daily full audit.
		add_action( Rescanner::HOOK, array( $this, 'drain_rescans' ) );
		add_action( Rescanner::AUDIT_HOOK, array( $this, 'audit_refresh' ) );
		add_action( 'searchpilot_monitor_full', array( $this, 'schedule_audit_refresh' ), 20 );
		// Robots defaults of the SEO plugin in companion mode decide what is noindex by design.
		foreach ( array( 'rank-math-options-titles', 'wpseo_titles', 'seopress_titles_option_name' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'seo_plugin_settings_updated' ) );
		}
	}

	/**
	 * Re-checks indexability after the companion SEO plugin's title/robots settings changed.
	 */
	public function seo_plugin_settings_updated() {
		$this->queue_reconcile( 'seo_plugin' );
	}

	/**
	 * Starts a registry reconciliation (unless a build runs) and processes it in the background.
	 *
	 * @param string $reason Reason.
	 */
	public function queue_reconcile( $reason ) {
		$registry = $this->get( RegistrySync::class );
		if ( ! $registry->is_building() && ! $registry->is_reconciling() ) {
			$registry->start_reconcile( $reason );
		}
		$this->scheduler()->enqueue_async( 'searchpilot_indexer_refresh', array(), 30 );
	}

	/**
	 * Service shortcut.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	private function get( $class ) {
		return $this->container->get( $class );
	}

	/**
	 * Registers issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$this->get( IssueDetector::class )->register_types( $registry );
	}

	/**
	 * After the setup wizard: build the registry and start checking.
	 */
	public function initial_sync() {
		$registry = $this->get( RegistrySync::class );
		if ( ! $registry->is_building() ) {
			$registry->start_build( 'initial' );
		}
		$this->run_registry( 20 );
		$this->get( Crawler::class )->refresh_robots( true );
		$this->scheduler()->enqueue_async( 'searchpilot_indexer_crawl', array(), 30 );
	}

	/**
	 * Hourly: registry build/reconcile slice inline; crawl and inspections
	 * as separate background events so no single request runs too long.
	 */
	public function hourly() {
		$this->run_registry( 15 );
		$this->scheduler()->enqueue_async( 'searchpilot_indexer_crawl', array(), 20 );
		$this->scheduler()->enqueue_async( 'searchpilot_indexer_inspect', array(), 90 );
	}

	/**
	 * Every 5 minutes: continue a registry build and crawl micro-batches while
	 * the backlog is large.
	 */
	public function every_5min() {
		// Set by a database upgrade that changes how indexability is computed.
		if ( get_option( 'searchpilot_pending_reconcile' ) ) {
			delete_option( 'searchpilot_pending_reconcile' );
			$this->queue_reconcile( 'upgrade' );
		}
		$registry = $this->get( RegistrySync::class );
		if ( $registry->is_building() ) {
			$this->run_registry( 15 );
		}
		$crawler = $this->get( Crawler::class );
		$batch   = (int) $this->get( Settings::class )->get( 'indexing.crawl_batch', 20 );
		if ( ! $crawler->paused_until() && $crawler->backlog() > 2 * $batch ) {
			$crawler->run_batch( 20, min( $batch, 10 ), 'micro' );
		}
		if ( RegistrySync::cleanup_requested() ) {
			$this->run_cleanup( 15 );
		}
		// Safety net: a queued re-scan whose event was lost resumes here.
		$rescanner = $this->get( Rescanner::class );
		if ( $rescanner->has_pending() && ! \SearchPilot\Core\Lock::held( Rescanner::LOCK ) ) {
			$this->scheduler()->enqueue_async( Rescanner::HOOK, array(), 30 );
		}
	}

	/**
	 * Runs a slice of the cleanup of rows/issues whose content is gone.
	 *
	 * @param int $seconds Budget.
	 * @return array|null Result, or null when the registry is busy.
	 */
	public function run_cleanup( $seconds ) {
		$registry = $this->get( RegistrySync::class );
		return \SearchPilot\Core\Lock::run(
			'registry',
			max( 120, 3 * (int) $seconds ),
			static function () use ( $registry, $seconds ) {
				return $registry->cleanup_orphans( new TimeBudget( (int) $seconds ) );
			}
		);
	}

	/* --------------------------------------------------------------------- *
	 * Registry hygiene
	 * --------------------------------------------------------------------- */

	/**
	 * A term was deleted: its registry rows go, its issues are resolved.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_deleted( $term_id, $tt_id = 0, $taxonomy = '' ) {
		$this->forget( 'term', (int) $term_id, true );
	}

	/**
	 * A post or attachment was deleted permanently.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post (WordPress 5.5+).
	 */
	public function post_deleted( $post_id, $post = null ) {
		$type = $post instanceof \WP_Post ? (string) $post->post_type : '';
		if ( '' !== $type && 'attachment' !== $type && ! is_post_type_viewable( $type ) ) {
			return; // Revisions, menu items, templates, orders…: never public URLs.
		}
		$this->forget( 'attachment' === $type ? 'attachment' : 'post', (int) $post_id, true );
	}

	/**
	 * A published post was unpublished, scheduled or trashed.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function post_status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' !== $old_status || 'publish' === $new_status || ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		$this->forget( 'post', (int) $post->ID, false );
	}

	/**
	 * A user was deleted: the author archive goes.
	 *
	 * @param int $user_id User id.
	 */
	public function user_deleted( $user_id ) {
		$this->forget( 'author', (int) $user_id, true );
	}

	/**
	 * Forgets an object, never breaking the request that deleted it.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param bool   $deleted     Permanently deleted.
	 */
	private function forget( $object_type, $object_id, $deleted ) {
		if ( $object_id <= 0 ) {
			return;
		}
		try {
			$this->get( RegistrySync::class )->forget_object( $object_type, $object_id, $deleted );
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->error( 'indexer', sprintf( 'Could not remove %s %d from the URL registry: %s', $object_type, $object_id, $e->getMessage() ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Re-scans and audit freshness
	 * --------------------------------------------------------------------- */

	/**
	 * Background re-scan queue.
	 */
	public function drain_rescans() {
		$this->get( Rescanner::class )->drain();
	}

	/**
	 * After the daily full audit: refresh links and content analysis in a request of their own.
	 */
	public function schedule_audit_refresh() {
		$this->scheduler()->enqueue_async( Rescanner::AUDIT_HOOK, array(), 60 );
	}

	/**
	 * Background audit freshness pass (no crawl: the hourly crawl covers due pages).
	 */
	public function audit_refresh() {
		$this->get( Rescanner::class )->refresh_audit( new TimeBudget( 25 ), false );
	}

	/**
	 * Weekly: reconcile the registry with WordPress.
	 */
	public function weekly() {
		$registry = $this->get( RegistrySync::class );
		if ( ! $registry->is_building() ) {
			$registry->start_reconcile( 'weekly' );
			$this->run_registry( 15 );
		}
		// Issues of content deleted while SearchPilot was inactive (the every-5-minutes job runs it).
		RegistrySync::request_cleanup();
	}

	/**
	 * Background crawl batch.
	 */
	public function crawl() {
		$this->get( Crawler::class )->run_batch( 25, 0, 'hourly' );
	}

	/**
	 * Background URL Inspection run.
	 */
	public function inspect() {
		$this->get( InspectionSync::class )->run( 25 );
	}

	/**
	 * Background re-check of one row.
	 *
	 * @param int $row_id Row id.
	 */
	public function recheck( $row_id ) {
		$row = $this->get( UrlRepository::class )->get( (int) $row_id );
		if ( $row && 'active' === $row['status'] ) {
			$this->get( Crawler::class )->check_row( $row, new TimeBudget( 30 ), array( 'bust_cache' => Crawler::needs_fresh_copy( $row ) ) );
		}
	}

	/**
	 * Background registry refresh (after settings changes).
	 */
	public function refresh() {
		$this->run_registry( 20 );
		$registry = $this->get( RegistrySync::class );
		if ( $registry->is_building() || $registry->is_reconciling() ) {
			$this->scheduler()->enqueue_async( 'searchpilot_indexer_refresh', array(), 120 );
		}
	}

	/**
	 * Runs a slice of the registry build, or of a reconciliation.
	 *
	 * @param int $seconds Budget.
	 * @return array
	 */
	public function run_registry( $seconds ) {
		$registry = $this->get( RegistrySync::class );
		$result   = \SearchPilot\Core\Lock::run(
			'registry',
			max( 120, 3 * (int) $seconds ),
			static function () use ( $registry, $seconds ) {
				if ( $registry->is_building() ) {
					return $registry->run_build( new TimeBudget( (int) $seconds ) );
				}
				if ( $registry->is_reconciling() ) {
					return $registry->run_reconcile( new TimeBudget( (int) $seconds ) );
				}
				return $registry->progress();
			}
		);
		return is_array( $result ) ? $result : $registry->progress();
	}

	/**
	 * URL events from the change detector.
	 *
	 * @param array $change Change.
	 */
	public function url_changed( $change ) {
		if ( ! is_array( $change ) ) {
			return;
		}
		try {
			$id    = $this->get( RegistrySync::class )->handle_change( $change );
			$event = (string) ( $change['event'] ?? '' );
			if ( $id && in_array( $event, array( 'publish', 'noindex', 'index', 'redirect' ), true ) ) {
				// Check the rendered page soon after publishing (catches noindex/canonical accidents early).
				Crawler::schedule_recheck( $this->scheduler(), (int) $id, 120 );
			}
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->error( 'indexer', 'URL change handling failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Safe follow-ups after a fix.
	 *
	 * @param array  $issue  Issue.
	 * @param string $source Source.
	 */
	public function issue_fixed( $issue, $source = 'user' ) {
		if ( is_array( $issue ) ) {
			$this->get( Fixers::class )->follow_up( $issue, (string) $source );
		}
	}

	/**
	 * Refreshes indexability after content/sitemap settings changed.
	 *
	 * @param array $new    New settings.
	 * @param array $before Previous settings.
	 */
	public function settings_updated( $new, $before ) {
		$changed = false;
		foreach ( array( 'content', 'ownership' ) as $section ) {
			if ( wp_json_encode( $new[ $section ] ?? array() ) !== wp_json_encode( $before[ $section ] ?? array() ) ) {
				$changed = true;
				break;
			}
		}
		if ( ! $changed && (bool) ( $new['indexing']['monitor_pagination'] ?? true ) !== (bool) ( $before['indexing']['monitor_pagination'] ?? true ) ) {
			$changed = true;
		}
		if ( ! $changed ) {
			return;
		}
		$this->queue_reconcile( 'settings' );
	}

	/**
	 * Dashboard section `indexing`.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard_summary( $summary ) {
		$summary             = is_array( $summary ) ? $summary : array();
		$urls                = $this->get( UrlRepository::class );
		$crawler             = $this->get( Crawler::class );
		$summary['indexing'] = array_merge(
			$urls->summary(),
			array(
				'by_status'    => $urls->count_by_status(),
				'registry'     => $this->get( RegistrySync::class )->progress(),
				'crawl_paused' => $crawler->paused_until() ? gmdate( 'c', $crawler->paused_until() ) : null,
				'inspections'  => $this->get( InspectionSync::class )->available(),
			)
		);
		return $summary;
	}

	/**
	 * Scheduler.
	 *
	 * @return Scheduler
	 */
	private function scheduler() {
		return $this->get( Scheduler::class );
	}
}
