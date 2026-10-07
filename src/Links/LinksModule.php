<?php
/**
 * Internal links module: content graph, suggestions, orphans.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\ContentRenderer;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the content graph into WordPress:
 *
 *   - builds the term index and link graph over existing posts in
 *     background batches (its own async event, re-queued while work
 *     remains), with progress in State;
 *   - saved, published and unpublished posts go into ONE small queue (a
 *     non-autoloaded option) drained by a single deduplicated event — never
 *     one cron event per post;
 *   - for newly published posts, generates New → Existing and Existing → New
 *     suggestions and inserts links automatically only when the automation
 *     policy allows the internal_links task;
 *   - reports orphan pages and broken internal links as issues, and keeps
 *     them in step with the live link graph: every change of a post's links
 *     (save, inserted link, undo, index build) re-evaluates the posts it
 *     affects (refresh_link_issues()), so the SEO Audit and the Internal
 *     Links screen never disagree;
 *   - offers assisted fix plans for no_internal_links and orphan_page
 *     (LinkPlanner).
 */
class LinksModule implements Module {

	const LOCK = 'links_index';

	const QUEUE_OPTION = 'searchpilot_links_queue';

	/**
	 * Max queued ids per kind (the hourly catch-up covers anything beyond).
	 */
	const QUEUE_CAP = 5000;

	/**
	 * Option set once the post-upgrade review of automatic links has run.
	 */
	const WEAK_REVIEW_OPTION = 'searchpilot_links_weak_reviewed';

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Posts whose evaluation is deferred while refresh_link_issues() prepares
	 * posts (null when not preparing).
	 *
	 * @var array<int,bool>|null
	 */
	private $deferred = null;

	/**
	 * Whether a weak-link issue refresh is queued for the end of this request.
	 *
	 * @var bool
	 */
	private $weak_refresh_queued = false;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 * @param Settings  $settings  Settings.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Container $container, Settings $settings, Scheduler $scheduler ) {
		$this->container = $container;
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'save_post', array( $this, 'on_save' ), 30, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 1 );
		add_action( 'searchpilot_url_changed', array( $this, 'on_url_changed' ), 20, 1 );

		add_action( 'searchpilot_links_drain', array( $this, 'drain' ) );
		add_action( 'searchpilot_links_build', array( $this, 'run_build_job' ) );
		add_action( 'searchpilot_links_hourly_job', array( $this, 'run_hourly_job' ) );
		// Events scheduled by earlier versions (one per post) are folded into the queue.
		add_action( 'searchpilot_links_new_post', array( $this, 'run_new_post' ), 10, 1 );
		add_action( 'searchpilot_links_reindex_post', array( $this, 'run_reindex_post' ), 10, 1 );

		add_action( 'searchpilot_every_5min', array( $this, 'build_tick' ), 30 );
		add_action( 'searchpilot_every_5min', array( $this, 'weak_review_tick' ), 31 );
		add_action( 'searchpilot_links_weak_review', array( $this, 'run_weak_review' ) );
		add_action( 'searchpilot_fix_applied', array( $this, 'on_fix_applied' ), 10, 3 );
		add_action( 'searchpilot_hourly', array( $this, 'hourly' ), 30 );
		add_action( 'searchpilot_initial_sync', array( $this, 'initial_sync' ), 30 );
		add_action( 'searchpilot_maintenance', array( $this, 'maintenance' ) );

		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_planners' ), 20 );
		add_action( 'searchpilot_links_graph_changed', array( $this, 'on_graph_changed' ), 10, 2 );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard' ) );
		add_action( 'searchpilot_activity_undone', array( $this, 'on_undo' ) );
		add_action( 'searchpilot_settings_updated', array( $this, 'settings_updated' ), 20, 2 );
		add_action( 'wp_update_nav_menu', array( LinkGraph::class, 'flush_menu_cache' ) );
		add_action( 'wp_delete_nav_menu', array( LinkGraph::class, 'flush_menu_cache' ) );
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
	 * Whether saves should be ignored (imports, autosaves): the hourly
	 * catch-up picks those posts up later.
	 *
	 * @return bool
	 */
	private static function skip_request() {
		return ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
	}

	/* ------------------------------------------------------------------
	 * WordPress events
	 * ------------------------------------------------------------------ */

	/**
	 * Queues a re-index of a saved published post.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ) {
		if ( self::skip_request() || ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! isset( $this->get( ContentTypes::class )->post_types()[ $post->post_type ] ) ) {
			return;
		}
		$this->queue_posts( array( (int) $post_id ), 'reindex' );
	}

	/**
	 * Publish → suggestions; unpublish → removal.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || $new_status === $old_status ) {
			return;
		}
		if ( ! isset( $this->get( ContentTypes::class )->post_types()[ $post->post_type ] ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			if ( ! self::skip_request() ) {
				$this->queue_posts( array( (int) $post->ID ), 'new', 60 );
			}
		} elseif ( 'publish' === $old_status ) {
			$this->cleanup( (int) $post->ID, false );
		}
	}

	/**
	 * Post deleted permanently.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'nav_menu_item' === $post->post_type ) {
			LinkGraph::flush_menu_cache();
			return;
		}
		if ( ! $post || ! isset( $this->get( ContentTypes::class )->post_types()[ $post->post_type ] ) ) {
			return;
		}
		$this->cleanup( (int) $post_id, true );
	}

	/**
	 * URL events from the change detector.
	 *
	 * @param array $change Change payload.
	 */
	public function on_url_changed( $change ) {
		if ( self::skip_request() || ! is_array( $change ) || 'post' !== ( $change['object_type'] ?? '' ) || empty( $change['object_id'] ) ) {
			return;
		}
		$id = (int) $change['object_id'];
		switch ( (string) ( $change['event'] ?? '' ) ) {
			case 'publish':
				$this->queue_posts( array( $id ), 'new', 60 );
				break;
			case 'update':
			case 'index':
			case 'redirect':
				$this->queue_posts( array( $id ), 'reindex' );
				break;
			case 'noindex':
				$this->get( ContentIndex::class )->remove_post( $id );
				$this->evaluate_link_issues( array( $id ) );
				break;
		}
	}

	/**
	 * Removes a post from the graph (unpublished or deleted).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $deleted Permanently deleted.
	 */
	public function cleanup( $post_id, $deleted ) {
		$this->get( ContentIndex::class )->remove_post( $post_id );
		$sources = $this->get( LinkGraph::class )->remove_post( $post_id );
		$this->get( SuggestionRepository::class )->delete_for_post( $post_id, ! $deleted );
		$this->dequeue( array( (int) $post_id ), array( (int) $post_id ) );
		// Posts linking to it now point to a missing page: re-check them (one queued batch).
		$sources = array_values(
			array_filter(
				array_slice( array_map( 'intval', $sources ), 0, 50 ),
				static function ( $id ) use ( $post_id ) {
					return $id !== (int) $post_id;
				}
			)
		);
		if ( $sources ) {
			$this->queue_posts( $sources, 'reindex', 120 );
		}
	}

	/**
	 * After a change was undone: inserted links and list entries are handled
	 * by the suggester; any other undone content change of a post (e.g. an
	 * applied fix plan) changes its links too, so its edges are re-read now.
	 *
	 * @param object $row Activity row.
	 */
	public function on_undo( $row ) {
		if ( ! is_object( $row ) ) {
			return;
		}
		$suggester = $this->get( Suggester::class );
		$suggester->on_undo( $row );
		if ( in_array( (string) ( $row->action ?? '' ), array( 'link_inserted', 'related_link_added' ), true ) ) {
			return;
		}
		if ( 'post' !== (string) ( $row->object_type ?? '' ) || 'post_content' !== (string) ( $row->handler ?? '' ) || empty( $row->object_id ) ) {
			return;
		}
		$id = (int) $row->object_id;
		$suggester->tidy_related( $id );
		$this->get( LinkGraph::class )->update_post( $id );
		if ( 'fix_weak_auto_links' === (string) ( $row->action ?? '' ) && ! $this->weak_refresh_queued ) {
			// The removed links are back: re-raise the issue once per request (after the whole group is undone).
			$this->weak_refresh_queued = true;
			add_action(
				'shutdown',
				function () {
					$this->refresh_weak_links_issue( 500 );
				}
			);
		}
	}

	/**
	 * A post's links changed: re-evaluate it and the targets that gained or lost a link.
	 *
	 * @param int   $source_id Source post.
	 * @param int[] $targets   Affected targets.
	 */
	public function on_graph_changed( $source_id, $targets = array() ) {
		if ( null !== $this->deferred ) {
			// refresh_link_issues() evaluates these together with the requested posts.
			foreach ( array_merge( array( (int) $source_id ), array_map( 'intval', (array) $targets ) ) as $id ) {
				$this->deferred[ $id ] = true;
			}
			return;
		}
		try {
			$this->evaluate_link_issues( array_merge( array( (int) $source_id ), array_map( 'intval', (array) $targets ) ) );
		} catch ( \Throwable $e ) {
			$this->get( Logger::class )->warning( 'links', sprintf( 'Link issue refresh after a change of post %d failed: %s', (int) $source_id, $e->getMessage() ) );
		}
	}

	/**
	 * Eligibility settings changed: rebuild the index.
	 *
	 * @param array $new    New settings.
	 * @param array $before Previous settings.
	 */
	public function settings_updated( $new, $before ) {
		foreach ( array( 'post_types', 'excluded_ids' ) as $key ) {
			if ( wp_json_encode( $new['links'][ $key ] ?? null ) !== wp_json_encode( $before['links'][ $key ] ?? null ) ) {
				$this->start_build( false );
				return;
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------ */

	/**
	 * Adds posts to the work queue and schedules the (single) drain event.
	 *
	 * @param int[]  $ids   Post ids.
	 * @param string $kind  new (index + suggestions) | reindex (index only).
	 * @param int    $delay Seconds before the drain runs.
	 */
	public function queue_posts( array $ids, $kind = 'reindex', $delay = 30 ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}
		$queue = $this->read_queue();
		foreach ( $ids as $id ) {
			if ( 'new' === $kind ) {
				$queue['new'][ $id ] = 1;
				unset( $queue['reindex'][ $id ] );
			} elseif ( ! isset( $queue['new'][ $id ] ) ) {
				$queue['reindex'][ $id ] = 1;
			}
		}
		foreach ( array( 'new', 'reindex' ) as $k ) {
			if ( count( $queue[ $k ] ) > self::QUEUE_CAP ) {
				$queue[ $k ] = array_slice( $queue[ $k ], -self::QUEUE_CAP, null, true );
			}
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		$this->scheduler->enqueue_async( 'searchpilot_links_drain', array(), $delay );
	}

	/**
	 * Current queue (read fresh from the database).
	 *
	 * @return array{new:array<int,int>,reindex:array<int,int>}
	 */
	public function read_queue() {
		wp_cache_delete( self::QUEUE_OPTION, 'options' );
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();
		return array(
			'new'     => isset( $queue['new'] ) && is_array( $queue['new'] ) ? $queue['new'] : array(),
			'reindex' => isset( $queue['reindex'] ) && is_array( $queue['reindex'] ) ? $queue['reindex'] : array(),
		);
	}

	/**
	 * Removes ids from the queue.
	 *
	 * @param int[] $new     Ids to remove from "new".
	 * @param int[] $reindex Ids to remove from "reindex".
	 */
	private function dequeue( array $new, array $reindex ) {
		$queue = $this->read_queue();
		$had   = count( $queue['new'] ) + count( $queue['reindex'] );
		foreach ( $new as $id ) {
			unset( $queue['new'][ (int) $id ] );
		}
		foreach ( $reindex as $id ) {
			unset( $queue['reindex'][ (int) $id ] );
		}
		if ( count( $queue['new'] ) + count( $queue['reindex'] ) !== $had ) {
			update_option( self::QUEUE_OPTION, $queue, false );
		}
	}

	/**
	 * Drains the queue within a time budget and re-queues itself while work remains.
	 * On a young site it first finishes the index build, so new posts get
	 * suggestions straight away.
	 */
	public function drain() {
		$more = Lock::run(
			self::LOCK,
			300,
			function () {
				$budget = new TimeBudget( 20 );
				$index  = $this->get( ContentIndex::class );
				if ( ! $index->is_ready() ) {
					$this->build_step( $budget );
				}
				$ready = $index->is_ready();
				$queue = $this->read_queue();
				$new   = $ready ? array_slice( array_keys( $queue['new'] ), 0, 10 ) : array();
				$again = array_slice( array_keys( $queue['reindex'] ), 0, 25 );
				$this->dequeue( $new, $again );
				$left_new   = array();
				$left_again = array();
				foreach ( $new as $id ) {
					if ( $budget->exhausted( 4 ) ) {
						$left_new[] = $id;
						continue;
					}
					try {
						$this->process_post( $id );
						$this->get( Suggester::class )->for_new_post( $id );
					} catch ( \Throwable $e ) {
						$this->get( Logger::class )->warning( 'links', sprintf( 'Link suggestions for post %d failed: %s', $id, $e->getMessage() ) );
					}
				}
				foreach ( $again as $id ) {
					if ( $budget->exhausted() ) {
						$left_again[] = $id;
						continue;
					}
					try {
						$this->process_post( $id );
						$this->get( Suggester::class )->refresh_source( $id );
					} catch ( \Throwable $e ) {
						$this->get( Logger::class )->warning( 'links', sprintf( 'Re-indexing post %d failed: %s', $id, $e->getMessage() ) );
					}
				}
				if ( $left_new || $left_again ) {
					$queue = $this->read_queue();
					foreach ( $left_new as $id ) {
						$queue['new'][ $id ] = 1;
					}
					foreach ( $left_again as $id ) {
						$queue['reindex'][ $id ] = 1;
					}
					update_option( self::QUEUE_OPTION, $queue, false );
				}
				$queue = $this->read_queue();
				return array(
					'more'  => ! empty( $queue['reindex'] ) || ! empty( $queue['new'] ),
					'ready' => $ready,
				);
			}
		);
		if ( null === $more ) {
			// The build (or another drain) holds the lock: try again shortly.
			$this->scheduler->enqueue_async( 'searchpilot_links_drain', array(), 60 );
		} elseif ( $more['more'] ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_drain', array(), $more['ready'] ? 10 : 60 );
		}
	}

	/**
	 * Legacy per-post event: fold into the queue.
	 *
	 * @param int $post_id Post id.
	 */
	public function run_new_post( $post_id ) {
		$this->queue_posts( array( (int) $post_id ), 'new', 5 );
	}

	/**
	 * Legacy per-post event: fold into the queue.
	 *
	 * @param int $post_id Post id.
	 */
	public function run_reindex_post( $post_id ) {
		$this->queue_posts( array( (int) $post_id ), 'reindex', 5 );
	}

	/**
	 * Re-indexes one post now (terms, edges, stale suggestions).
	 *
	 * @param int $post_id Post id.
	 * @return bool|null False/true when done, null when the index is busy.
	 */
	public function reindex_now( $post_id ) {
		$post_id = (int) $post_id;
		return Lock::run(
			self::LOCK,
			120,
			function () use ( $post_id ) {
				$this->process_post( $post_id );
				$this->get( Suggester::class )->refresh_source( $post_id );
				return true;
			}
		);
	}

	/**
	 * Renders a post once and updates its terms and edges.
	 *
	 * @param int $post_id Post id.
	 */
	public function process_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$graph = $this->get( LinkGraph::class );
		$index = $this->get( ContentIndex::class );
		if ( ! $graph->in_graph( $post ) ) {
			$index->remove_post( $post->ID );
			$graph->remove_post( $post->ID );
			return;
		}
		$html = $this->get( ContentRenderer::class )->render( $post->post_content, $post );
		$index->index_post( $post, $html );
		$graph->update_post( $post->ID, $html );
	}

	/* ------------------------------------------------------------------
	 * Build and scheduled jobs (each in its own request)
	 * ------------------------------------------------------------------ */

	/**
	 * Every 5 minutes: make sure the build job is scheduled while building.
	 */
	public function build_tick() {
		if ( ! $this->get( ContentIndex::class )->is_ready() ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_build', array(), 5 );
		}
	}

	/**
	 * Build job: runs a batch and re-queues itself until the build is done.
	 */
	public function run_build_job() {
		$status = $this->run_build_batch( new TimeBudget( 20 ) );
		if ( ! $status['ready'] ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_build', array(), 30 );
		} elseif ( $this->queue_size() ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_drain', array(), 5 );
		}
	}

	/**
	 * Hourly hook: hand the work to its own event (fresh time budget).
	 */
	public function hourly() {
		$this->scheduler->enqueue_async( 'searchpilot_links_hourly_job', array(), 10 );
	}

	/**
	 * Hourly job: continue the build, catch up missed edits, sweep orphans.
	 */
	public function run_hourly_job() {
		if ( ! $this->get( ContentIndex::class )->is_ready() ) {
			$this->run_build_job();
			return;
		}
		$more = Lock::run(
			self::LOCK,
			600,
			function () {
				$budget = new TimeBudget( 20 );
				$more   = $this->catch_up( $budget );
				if ( $budget->exhausted( 6 ) ) {
					return true; // Sweep in the next run.
				}
				$this->orphan_sweep();
				if ( ! $budget->exhausted( 8 ) ) {
					$this->refresh_weak_links_issue( 200 );
				}
				if ( ! $budget->exhausted( 6 ) && $this->backlog_pass( $budget ) ) {
					$more = true;
				}
				return $more;
			}
		);
		if ( null === $more || $more ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_hourly_job', array(), null === $more ? 300 : 60 );
		}
	}

	/**
	 * First sync after the setup wizard.
	 */
	public function initial_sync() {
		$state = $this->get( ContentIndex::class )->build_state();
		if ( '' === $state['started_at'] ) {
			$this->start_build( false );
			return;
		}
		$this->scheduler->enqueue_async( 'searchpilot_links_build', array(), 5 );
	}

	/**
	 * Daily housekeeping.
	 */
	public function maintenance() {
		$this->get( SuggestionRepository::class )->prune( 30 );
		Lock::run(
			self::LOCK,
			300,
			function () {
				$this->get( ContentIndex::class )->heal_raw_vectors( 200 );
			}
		);
	}

	/**
	 * (Re)starts the index build.
	 *
	 * @param bool $truncate Clear the index first (full rebuild).
	 * @return array Build status.
	 */
	public function start_build( $truncate = false ) {
		$index = $this->get( ContentIndex::class );
		if ( $truncate ) {
			Lock::run(
				self::LOCK,
				120,
				static function () use ( $index ) {
					$index->truncate();
				}
			);
		}
		$index->save_build_state(
			array(
				'phase'              => 'build',
				'cursor'             => 0,
				'processed'          => 0,
				'total'              => $this->count_graph_posts(),
				'started_at'         => current_time( 'mysql', true ),
				'completed_at'       => '',
				'weights_started_at' => '',
			)
		);
		$this->scheduler->enqueue_async( 'searchpilot_links_build', array(), 5 );
		return $this->status();
	}

	/**
	 * Runs build batches within a time budget (takes the index lock).
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array Build status.
	 */
	public function run_build_batch( TimeBudget $budget ) {
		Lock::run(
			self::LOCK,
			600,
			function () use ( $budget ) {
				$this->build_step( $budget );
			}
		);
		return $this->status();
	}

	/**
	 * One build step (caller holds the lock): index posts by ID ranges, then
	 * convert raw frequencies to TF-IDF weights.
	 *
	 * @param TimeBudget $budget Budget.
	 */
	private function build_step( TimeBudget $budget ) {
		$index = $this->get( ContentIndex::class );
		$state = $index->build_state();
		if ( 'done' === $state['phase'] ) {
			return;
		}
		if ( '' === $state['started_at'] ) {
			$state['started_at'] = current_time( 'mysql', true );
			$state['total']      = $this->count_graph_posts();
		}
		$saved_at = time();
		while ( 'build' === $state['phase'] && ! $budget->exhausted() ) {
			$ids = $this->graph_post_ids_after( (int) $state['cursor'], 25 );
			if ( ! $ids ) {
				$state['phase']              = 'weights';
				$state['cursor']             = 0;
				$state['weights_started_at'] = current_time( 'mysql', true );
				break;
			}
			foreach ( $ids as $id ) {
				if ( $budget->exhausted() ) {
					break 2;
				}
				try {
					$this->process_post( $id );
				} catch ( \Throwable $e ) {
					$this->get( Logger::class )->warning( 'links', sprintf( 'Indexing post %d failed: %s', $id, $e->getMessage() ) );
				}
				$state['cursor'] = $id;
				++$state['processed'];
			}
			if ( time() - $saved_at > 5 ) {
				$index->save_build_state( $state );
				$saved_at = time();
			}
		}
		while ( 'weights' === $state['phase'] && ! $budget->exhausted() ) {
			$ids = $index->indexed_ids_after( (int) $state['cursor'], 100 );
			if ( ! $ids ) {
				$state['phase']        = 'done';
				$state['completed_at'] = current_time( 'mysql', true );
				break;
			}
			foreach ( $ids as $id ) {
				if ( $budget->exhausted() ) {
					break 2;
				}
				// Skips vectors that already hold final weights (posts re-indexed meanwhile).
				$index->reweight_post( $id );
				$state['cursor'] = $id;
			}
		}
		$index->save_build_state( $state );
		if ( 'done' === $state['phase'] ) {
			$this->get( Logger::class )->info( 'links', sprintf( 'Internal link index built: %d posts.', $index->indexed_count() ) );
			$this->orphan_sweep();
		}
	}

	/**
	 * Re-indexes posts edited since they were indexed and indexes posts the
	 * event hooks missed (cursor scan; recent ones also get suggestions).
	 *
	 * @param TimeBudget $budget Budget.
	 * @return bool Whether edited posts remain.
	 */
	private function catch_up( TimeBudget $budget ) {
		global $wpdb;
		$content = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$stale = array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$content} c ON c.post_id = p.ID WHERE p.post_status = 'publish' AND c.indexed_at IS NOT NULL AND p.post_modified_gmt > c.indexed_at ORDER BY p.post_modified_gmt DESC LIMIT 30" ) );
		foreach ( $stale as $id ) {
			if ( $budget->exhausted( 5 ) ) {
				return true;
			}
			$this->process_post( $id );
		}
		$more   = count( $stale ) >= 30;
		$state  = \SearchPilot\Plugin::instance()->get( \SearchPilot\Core\State::class );
		$cursor = (int) $state->get( 'links_scan_cursor', 0 );
		$ids    = $this->graph_post_ids_after( $cursor, 200 );
		if ( ! $ids ) {
			$state->set( 'links_scan_cursor', 0 );
			return $more;
		}
		$rows   = $this->get( AnalysisRepository::class )->get_many( $ids );
		$index  = $this->get( ContentIndex::class );
		$recent = array();
		foreach ( $ids as $id ) {
			if ( $budget->exhausted( 5 ) ) {
				break;
			}
			$cursor = $id;
			if ( empty( $rows[ $id ]['indexed_at'] ) && $index->is_eligible( $id ) ) {
				$this->process_post( $id );
				$post = get_post( $id );
				if ( $post && strtotime( $post->post_date_gmt . ' UTC' ) > time() - 3 * DAY_IN_SECONDS ) {
					$recent[] = $id; // Published recently but missed (e.g. during an import): suggest links too.
				}
			}
		}
		$state->set( 'links_scan_cursor', $cursor );
		if ( $recent ) {
			$this->queue_posts( $recent, 'new', 60 );
		}
		return $more;
	}

	/**
	 * Number of queued posts.
	 *
	 * @return int
	 */
	public function queue_size() {
		$queue = $this->read_queue();
		return count( $queue['new'] ) + count( $queue['reindex'] );
	}

	/* ------------------------------------------------------------------
	 * Orphans
	 * ------------------------------------------------------------------ */

	/**
	 * Re-evaluates link issues of posts from the live link graph — the same
	 * data the Internal Links screen shows (contract C5; the re-scanner calls
	 * it after fixes, and it runs on every graph change).
	 *
	 *   orphan_page        opened for posts the graph counts as orphans (indexed
	 *                      for linking, published, no link from another post,
	 *                      not in a menu, not the front/blog page), resolved for
	 *                      all others. While an index build runs, issues are
	 *                      only resolved, never opened (the graph is incomplete).
	 *   no_internal_links  resolved once the post links to another page of the
	 *                      site (it is opened only by content analysis).
	 *
	 * Posts whose edits still wait in the background queue have their links
	 * re-read first, so the result is correct right after a write.
	 *
	 * @param int[]           $post_ids Post ids. Empty: the whole site (every open link
	 *                                  issue re-checked, then all orphans reported) — used
	 *                                  by "Run audit now" and the hourly sweep.
	 * @param TimeBudget|null $budget   Caller's budget for the whole-site pass (default 20 s).
	 * @return array{checked:int,opened:int,resolved:int}
	 */
	public function refresh_link_issues( array $post_ids, $budget = null ) {
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $post_ids ) ) ) );
		if ( ! $ids ) {
			return $this->refresh_all_link_issues( $budget instanceof TimeBudget ? $budget : new TimeBudget( 20 ) );
		}
		$this->deferred = array();
		try {
			$this->prepare_posts( array_slice( $ids, 0, 50 ) );
		} finally {
			$affected       = array_keys( $this->deferred );
			$this->deferred = null;
		}
		$result = $this->evaluate_link_issues( $ids, true );
		$others = array_values( array_diff( $affected, $ids ) );
		if ( $others ) {
			$this->evaluate_link_issues( $others ); // Targets whose inbound links changed meanwhile.
		}
		return $result;
	}

	/**
	 * Brings posts into the graph before their link issues are judged, so the
	 * result is the same before and after a save: posts whose edits wait in the
	 * queue, published posts that take part in linking but were never indexed
	 * (e.g. just published), and published posts whose links were never read
	 * are indexed now. When the index job holds the lock, their links are read
	 * without touching the term index (eligibility then stands in for the index
	 * entry, see evaluate_link_issues()).
	 *
	 * @param int[] $ids Post ids.
	 */
	private function prepare_posts( array $ids ) {
		$queue = $this->read_queue();
		$graph = $this->get( LinkGraph::class );
		$index = $this->get( ContentIndex::class );
		$need  = array();
		foreach ( $graph->link_state( $ids ) as $id => $state ) {
			if ( isset( $queue['new'][ $id ] ) || isset( $queue['reindex'][ $id ] ) ) {
				$need[] = $id;
			} elseif ( $state['published'] && ( ! $state['known'] || ( ! $state['indexed'] && $index->is_eligible( $id ) ) ) && $graph->in_graph( $id ) ) {
				$need[] = $id;
			}
		}
		if ( ! $need ) {
			return;
		}
		$done = Lock::run(
			self::LOCK,
			120,
			function () use ( $need ) {
				foreach ( $need as $id ) {
					$this->process_post( $id );
				}
				return true;
			}
		);
		if ( null === $done ) {
			foreach ( $need as $id ) {
				$graph->update_post( $id );
			}
		}
	}

	/**
	 * Whole-site pass: every open orphan_page / no_internal_links issue is
	 * re-checked, then orphans not reported yet are opened (up to 1,000).
	 *
	 * @param TimeBudget $budget Budget.
	 * @return array{checked:int,opened:int,resolved:int}
	 */
	private function refresh_all_link_issues( TimeBudget $budget ) {
		global $wpdb;
		$total = array(
			'checked'  => 0,
			'opened'   => 0,
			'resolved' => 0,
		);
		$add   = static function ( array $part ) use ( &$total ) {
			foreach ( $total as $key => $value ) {
				$total[ $key ] = $value + (int) ( $part[ $key ] ?? 0 );
			}
		};
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$open = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT object_id FROM {$table} WHERE type IN ('orphan_page','no_internal_links') AND object_type = 'post' AND status IN ('open','pending_approval') ORDER BY object_id ASC LIMIT 5000" ) );
		foreach ( array_chunk( $open, 200 ) as $chunk ) {
			if ( $budget->exhausted( 3 ) ) {
				return $total;
			}
			$add( $this->evaluate_link_issues( $chunk, true ) );
		}
		if ( ! $this->get( ContentIndex::class )->is_ready() ) {
			return $total;
		}
		$graph = $this->get( LinkGraph::class );
		for ( $page = 1; $page <= 5; $page++ ) {
			if ( $budget->exhausted( 3 ) ) {
				break;
			}
			$orphans = $graph->orphans( $page, 200 );
			$fresh   = array_values( array_diff( $orphans['ids'], $open ) );
			if ( $fresh ) {
				$add( $this->evaluate_link_issues( $fresh ) );
			}
			if ( count( $orphans['ids'] ) < 200 ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * Opens/resolves link issues of posts from the stored graph (no re-render).
	 *
	 * @param int[] $ids     Post ids.
	 * @param bool  $recount Recount inbound links from the links table first.
	 * @return array{checked:int,opened:int,resolved:int}
	 */
	public function evaluate_link_issues( array $ids, $recount = false ) {
		global $wpdb;
		$out = array(
			'checked'  => 0,
			'opened'   => 0,
			'resolved' => 0,
		);
		$ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return $out;
		}
		$graph = $this->get( LinkGraph::class );
		if ( $recount ) {
			$graph->recount_inbound( $ids );
		}
		$issues = $this->get( IssueRepository::class );
		$index  = $this->get( ContentIndex::class );
		$ready  = $index->is_ready();
		$table  = Tables::issues();
		$open   = array(
			'orphan_page'       => array(),
			'no_internal_links' => array(),
		);
		// One read of the open link issues, so unchanged posts cost no writes.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( "SELECT DISTINCT type, object_id FROM {$table} WHERE type IN ('orphan_page','no_internal_links') AND object_type = 'post' AND status IN ('open','pending_approval') AND object_id IN (" . implode( ',', $ids ) . ')' ) as $row ) {
			$open[ (string) $row->type ][ (int) $row->object_id ] = true;
		}
		$orphan = array();
		foreach ( $graph->link_state( $ids ) as $id => $state ) {
			++$out['checked'];
			if ( ! $state['orphan'] && ! $state['indexed'] && $state['published'] && 0 === $state['inbound'] && ! $state['exempt'] && $index->is_eligible( $id ) ) {
				// Takes part in linking but its index entry is still queued: judged like an indexed post.
				$state['orphan'] = true;
			}
			if ( $state['orphan'] ) {
				if ( $ready ) {
					$orphan[] = $id;
				}
			} elseif ( isset( $open['orphan_page'][ $id ] ) && ( $ready || $state['inbound'] > 0 || $state['exempt'] || ! $state['published'] ) ) {
				// During a build a not-yet-indexed post keeps its issue until the build completes.
				$out['resolved'] += $issues->resolve_stale_for_object( 'post', $id, array( 'orphan_page' ), array() );
			}
			if ( isset( $open['no_internal_links'][ $id ] ) && $state['outbound_internal'] > 0 ) {
				$out['resolved'] += $issues->resolve_stale_for_object( 'post', $id, array( 'no_internal_links' ), array() );
			}
		}
		if ( ! $orphan ) {
			return $out;
		}
		$was_open = $open['orphan_page'];
		$meta     = $this->get( MetaStore::class );
		$site     = (int) $graph->orphans( 1, 1 )['total'];
		$traffic  = $this->ids_with_traffic( $orphan );
		$listed   = $this->listed_on_archives( $orphan );
		foreach ( $orphan as $id ) {
			$post        = get_post( $id );
			$age_days    = $post ? ( time() - (int) strtotime( $post->post_date_gmt . ' UTC' ) ) / DAY_IN_SECONDS : 999;
			$cornerstone = (bool) $meta->get_post_field( $id, 'cornerstone' );
			if ( $cornerstone || isset( $traffic[ $id ] ) ) {
				$severity = 'high';
				$reason   = $cornerstone ? 'cornerstone' : 'traffic';
			} elseif ( $age_days < 30 && isset( $listed[ $id ] ) ) {
				$severity = 'opportunity';
				$reason   = 'recent_listed';
			} else {
				$severity = 'medium';
				$reason   = 'default';
			}
			$issue_id = $issues->upsert(
				'orphan_page',
				array(
					'object_type' => 'post',
					'object_id'   => $id,
					'url'         => (string) get_permalink( $id ),
					'title'       => Suggester::plain_title( $id ),
					'severity'    => $severity,
					'data'        => array(
						'site_orphans' => $site,
						'reason'       => $reason,
						'inbound'      => 0,
						'links_url'    => admin_url( 'admin.php?page=searchpilot-links&tab=orphans' ),
					),
				)
			);
			// An issue opened under an earlier address of the post is the same problem.
			$issues->resolve_stale_for_object( 'post', $id, array( 'orphan_page' ), array( $issue_id ) );
			if ( ! isset( $was_open[ $id ] ) ) {
				++$out['opened'];
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Weak automatic links (inserted by earlier versions)
	 * ------------------------------------------------------------------ */

	/**
	 * In-text links SearchPilot inserted that are still in the content but
	 * fail the anchor rules of this version (Suggester::anchor_fits — e.g.
	 * «across Bengaluru», padded around a city name), or whose target is no
	 * longer published. Sources: activity entries `link_inserted` that were
	 * not undone (any source: Autopilot or approved), plus applied in-text
	 * suggestions whose activity entry was pruned. Each link is found by its
	 * marker attribute, so links a person added are never listed.
	 *
	 * @param int $limit Max links checked (newest first).
	 * @return array<int,array{key:string,post_id:int,post_title:string,post_edit:string,target_id:int,target_title:string,target_url:string,anchor:string,context:string,reason:string,activity_id:int,suggestion_id:int,marker:string,source:string,inserted_at:string|null}>
	 */
	public function weak_auto_links( $limit = 500 ) {
		global $wpdb;
		$limit      = max( 1, min( 2000, (int) $limit ) );
		$budget     = new TimeBudget( 15 );
		$repo       = $this->get( SuggestionRepository::class );
		$activity   = Tables::activity();
		$candidates = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, object_id, after_data, source, created_at FROM {$activity} WHERE action = 'link_inserted' AND object_type = 'post' AND status = 'applied' ORDER BY id DESC LIMIT %d", $limit ) ) as $row ) {
			$after  = json_decode( (string) $row->after_data, true );
			$marker = is_array( $after ) ? (string) ( $after['marker'] ?? '' ) : '';
			if ( AutoLinks::is_marker( $marker ) ) {
				$candidates[] = array( (int) $row->object_id, $marker, (int) $row->id, (string) $row->source, (string) $row->created_at, $repo->by_activity( (int) $row->id ) );
			}
		}
		if ( count( $candidates ) < $limit ) {
			$table = Tables::link_suggestions();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$table} s LEFT JOIN {$activity} a ON a.id = s.activity_id WHERE s.status = 'applied' AND s.kind = 'inline' AND a.id IS NULL ORDER BY s.id DESC LIMIT %d", $limit - count( $candidates ) ), ARRAY_A ) as $row ) {
				$row          = $repo->cast( $row );
				$candidates[] = array( (int) $row['source_id'], 'data-spp-link="' . (int) $row['id'] . '"', 0, '', (string) $row['updated_at'], $row );
			}
		}
		$suggester = $this->get( Suggester::class );
		$graph     = $this->get( LinkGraph::class );
		$seen      = array();
		$out       = array();
		foreach ( $candidates as $candidate ) {
			if ( $budget->exhausted( 2 ) ) {
				break;
			}
			list( $post_id, $marker, $activity_id, $source, $at, $suggestion ) = $candidate;
			$key = $post_id . '|' . $marker;
			$post = get_post( $post_id );
			if ( isset( $seen[ $key ] ) || ! $post || 'trash' === $post->post_status || ! ContentRenderer::is_editable( $post ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$link         = AutoLinks::find( (string) $post->post_content, $marker );
			if ( ! $link ) {
				continue; // Removed or rewritten by an editor since.
			}
			$target_id = $suggestion ? (int) $suggestion['target_id'] : 0;
			if ( ! $target_id && '' !== $link['href'] ) {
				$target_id = (int) $graph->resolve( \SearchPilot\Support\Url::absolute( $link['href'], (string) get_permalink( $post ) ) )['target_id'];
			}
			$target = $target_id ? get_post( $target_id ) : null;
			if ( ! $target || 'publish' !== $target->post_status ) {
				$reason = 'target_gone';
			} elseif ( ! $suggester->anchor_fits( $link['anchor'], $target ) ) {
				$reason = 'weak_anchor';
			} else {
				continue;
			}
			$out[] = array(
				'key'           => $activity_id ? 'a' . $activity_id : 's' . (int) ( $suggestion['id'] ?? 0 ),
				'post_id'       => (int) $post->ID,
				'post_title'    => Suggester::plain_title( $post ),
				'post_edit'     => (string) get_edit_post_link( $post->ID, 'raw' ),
				'target_id'     => $target ? (int) $target->ID : 0,
				'target_title'  => $target ? Suggester::plain_title( $target ) : '',
				'target_url'    => $target ? (string) get_permalink( $target ) : $link['href'],
				'anchor'        => $link['anchor'],
				'context'       => $link['context'],
				'reason'        => $reason,
				'activity_id'   => $activity_id,
				'suggestion_id' => (int) ( $suggestion['id'] ?? 0 ),
				'marker'        => $marker,
				'source'        => $source,
				'inserted_at'   => '' !== $at ? mysql_to_rfc3339( $at ) : null,
			);
		}
		// In-text links written by link fixes ("no internal links", orphan pages).
		$types = $this->get( ContentIndex::class )->post_types();
		if ( $types && count( $out ) < $limit ) {
			$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$holders}) AND post_content LIKE %s ORDER BY ID DESC LIMIT %d", array_merge( $types, array( '%' . $wpdb->esc_like( 'data-spp-link="fix-' ) . '%', $limit ) ) ) );
			foreach ( $ids as $post_id ) {
				if ( $budget->exhausted( 2 ) || count( $out ) >= $limit ) {
					break;
				}
				$post = get_post( (int) $post_id );
				if ( ! $post || ! ContentRenderer::is_editable( $post ) ) {
					continue;
				}
				foreach ( AutoLinks::fix_links( (string) $post->post_content ) as $link ) {
					$target_id = '' !== $link['href'] ? (int) $graph->resolve( \SearchPilot\Support\Url::absolute( $link['href'], (string) get_permalink( $post ) ) )['target_id'] : 0;
					$target    = $target_id ? get_post( $target_id ) : null;
					if ( ! $target || 'publish' !== $target->post_status ) {
						$reason = 'target_gone';
					} elseif ( ! $suggester->anchor_fits( $link['anchor'], $target ) ) {
						$reason = 'weak_anchor';
					} else {
						continue;
					}
					$out[] = array(
						'key'           => 'f' . (int) $post->ID . '-' . substr( md5( $link['html'] ), 0, 10 ),
						'post_id'       => (int) $post->ID,
						'post_title'    => Suggester::plain_title( $post ),
						'post_edit'     => (string) get_edit_post_link( $post->ID, 'raw' ),
						'target_id'     => $target ? (int) $target->ID : 0,
						'target_title'  => $target ? Suggester::plain_title( $target ) : '',
						'target_url'    => $target ? (string) get_permalink( $target ) : $link['href'],
						'anchor'        => $link['anchor'],
						'context'       => $link['context'],
						'reason'        => $reason,
						'activity_id'   => 0,
						'suggestion_id' => 0,
						'marker'        => $link['marker'],
						'html'          => $link['html'],
						'source'        => 'fix',
						'inserted_at'   => null,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Raises, refreshes or resolves the site-level weak_auto_links issue.
	 *
	 * @param int $limit Max links checked.
	 * @return array{count:int,items:array}
	 */
	public function refresh_weak_links_issue( $limit = 200 ) {
		$items  = $this->weak_auto_links( $limit );
		$issues = $this->get( IssueRepository::class );
		if ( ! $items ) {
			$issues->resolve( 'weak_auto_links', 'site', 0 );
			return array(
				'count' => 0,
				'items' => array(),
			);
		}
		$examples = array();
		$posts    = array();
		foreach ( $items as $item ) {
			$posts[ $item['post_id'] ] = true;
			if ( count( $examples ) < 5 && 'weak_anchor' === $item['reason'] && ! in_array( $item['anchor'], $examples, true ) ) {
				$examples[] = $item['anchor'];
			}
		}
		$issues->upsert(
			'weak_auto_links',
			array(
				'object_type' => 'site',
				'object_id'   => 0,
				'title'       => get_bloginfo( 'name' ),
				'data'        => array(
					'count'     => count( $items ),
					'posts'     => count( $posts ),
					'examples'  => $examples,
					'more'      => count( $items ) >= $limit,
					'links_url' => admin_url( 'admin.php?page=searchpilot-links&tab=review' ),
				),
			)
		);
		return array(
			'count' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * One-off review after the upgrade to 1.1 (links earlier versions inserted
	 * automatically): the every-5-minutes tick queues it once; the migrator
	 * may also call it directly.
	 *
	 * @return array{count:int,items:array}
	 */
	public function review_weak_links() {
		update_option( self::WEAK_REVIEW_OPTION, gmdate( 'c' ), false );
		return $this->refresh_weak_links_issue( 500 );
	}

	/**
	 * Every 5 minutes: queue the one-off review when it has not run yet.
	 */
	public function weak_review_tick() {
		if ( false === get_option( self::WEAK_REVIEW_OPTION, false ) ) {
			$this->scheduler->enqueue_async( 'searchpilot_links_weak_review', array(), 30 );
		}
	}

	/**
	 * Background job for the one-off review.
	 */
	public function run_weak_review() {
		Lock::run(
			'links_weak_review',
			300,
			function () {
				$this->review_weak_links();
			}
		);
	}

	/**
	 * After a weak-link removal was written, re-check the site-level issue
	 * (post re-scans do not cover it), so the fix is verified.
	 *
	 * @param array  $plan     Plan.
	 * @param int[]  $ids      Activity ids.
	 * @param string $group_id Group.
	 */
	public function on_fix_applied( $plan, $ids = array(), $group_id = '' ) {
		if ( is_array( $plan ) && 'weak_auto_links' === ( $plan['type'] ?? '' ) ) {
			$this->refresh_weak_links_issue( 500 );
		}
	}

	/**
	 * The open weak_auto_links issue, or null.
	 *
	 * @return array|null
	 */
	public function weak_links_issue() {
		$found = $this->get( IssueRepository::class )->query(
			array(
				'type'     => 'weak_auto_links',
				'status'   => 'open',
				'per_page' => 1,
			)
		);
		return $found['items'] ? $found['items'][0] : null;
	}

	/**
	 * Site-wide orphan sweep (hourly and after the index build).
	 *
	 * Severity: high — cornerstone content, or pages that already get search
	 * clicks; opportunity — posts under 30 days old still listed on the home
	 * page or the first page of one of their category archives; medium —
	 * everything else.
	 *
	 * @return array{checked:int,opened:int,resolved:int}
	 */
	public function orphan_sweep() {
		return $this->refresh_link_issues( array() );
	}

	/**
	 * Posts (of the given ids) with search clicks in the stored 28-day page data.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,bool>
	 */
	private function ids_with_traffic( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table    = Tables::perf_rows();
		$in       = implode( ',', $ids );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_col( "SELECT DISTINCT object_id FROM {$table} WHERE range_key = '28d' AND dimension = 'page' AND clicks > 0 AND object_id IN ({$in})" );
		$wpdb->suppress_errors( $suppress );
		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	/**
	 * Recent posts (of the given ids) still shown on the home page or on the
	 * first page of one of their category archives.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,bool>
	 */
	private function listed_on_archives( array $ids ) {
		$recent = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && 'post' === $post->post_type && strtotime( $post->post_date_gmt . ' UTC' ) > time() - 30 * DAY_IN_SECONDS ) {
				$recent[] = (int) $id;
			}
		}
		if ( ! $recent ) {
			return array();
		}
		$per    = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$query  = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'numberposts'         => $per,
			'fields'              => 'ids',
			'suppress_filters'    => true,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		$listed = array_fill_keys( array_map( 'intval', get_posts( $query ) ), true );
		$terms  = wp_get_object_terms( $recent, 'category', array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array_intersect_key( $listed, array_flip( $recent ) );
		}
		$by_term = array();
		foreach ( $terms as $term ) {
			$by_term[ (int) $term->term_id ][] = (int) $term->object_id;
		}
		foreach ( array_slice( $by_term, 0, 50, true ) as $term_id => $members ) {
			$first = get_posts( array_merge( $query, array( 'cat' => $term_id ) ) );
			foreach ( array_intersect( $members, array_map( 'intval', $first ) ) as $id ) {
				$listed[ $id ] = true;
			}
		}
		return array_intersect_key( $listed, array_flip( $recent ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Autopilot backlog for existing posts: posts without internal links, or with
	 * no links pointing to them, get suggestions and a capped number of automatic
	 * links (at most 10 posts per run, one full pass per week).
	 *
	 * @param TimeBudget $budget Budget.
	 * @return bool Whether more posts remain in this pass.
	 */
	private function backlog_pass( TimeBudget $budget ) {
		if ( ! $this->get( \SearchPilot\Core\AutomationPolicy::class )->allows( \SearchPilot\Core\AutomationPolicy::TASK_INTERNAL_LINKS ) ) {
			return false;
		}
		$state   = $this->get( \SearchPilot\Core\State::class );
		$pass_at = (string) $state->get( 'links_backlog_pass_at', '' );
		if ( '' !== $pass_at && strtotime( $pass_at ) > time() - WEEK_IN_SECONDS ) {
			return false;
		}
		$cursor = (int) $state->get( 'links_backlog_cursor', 0 );
		$ids    = $this->graph_post_ids_after( $cursor, 40 );
		if ( ! $ids ) {
			$state->set( 'links_backlog_cursor', 0 );
			$state->set( 'links_backlog_pass_at', gmdate( 'c' ) );
			return false;
		}
		$graph     = $this->get( LinkGraph::class );
		$index     = $this->get( ContentIndex::class );
		$suggester = $this->get( Suggester::class );
		$repo      = $this->get( SuggestionRepository::class );
		$visited   = 0;
		$applied   = 0;
		foreach ( $ids as $id ) {
			if ( $visited >= 10 || $budget->exhausted( 5 ) ) {
				break;
			}
			$cursor = $id;
			if ( ! $index->is_eligible( $id ) ) {
				continue;
			}
			$no_out = 0 === (int) $graph->internal_count( $id );
			$no_in  = ! $graph->edges_to( $id );
			if ( ! $no_out && ! $no_in ) {
				continue;
			}
			++$visited;
			$result  = $suggester->generate( $id, $no_out && $no_in ? 'both' : ( $no_out ? 'outbound' : 'inbound' ) );
			$sources = $no_out ? array( $id ) : array();
			foreach ( (array) $result['inbound'] as $suggestion_id ) {
				$row = $repo->get( $suggestion_id );
				if ( $row ) {
					$sources[] = (int) $row['source_id'];
				}
			}
			$applied += $suggester->auto_apply_capped( $sources );
		}
		$state->set( 'links_backlog_cursor', $cursor );
		if ( $applied ) {
			$this->get( Logger::class )->info( 'links', sprintf( 'Internal links added to existing posts automatically: %d.', $applied ) );
		}
		return true;
	}

	/**
	 * Published posts of public types after a cursor.
	 *
	 * @param int $after Cursor.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	private function graph_post_ids_after( $after, $limit ) {
		global $wpdb;
		$types = array_keys( $this->get( ContentTypes::class )->post_types() );
		if ( ! $types ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$holders}) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( $types, array( (int) $after, (int) $limit ) ) ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of published posts of public types.
	 *
	 * @return int
	 */
	private function count_graph_posts() {
		global $wpdb;
		$types = array_keys( $this->get( ContentTypes::class )->post_types() );
		if ( ! $types ) {
			return 0;
		}
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$holders})", $types ) );
	}

	/**
	 * Build status for the UI.
	 *
	 * @return array{phase:string,ready:bool,processed:int,total:int,started_at:string|null,completed_at:string|null,queued:int}
	 */
	public function status() {
		$state = $this->get( ContentIndex::class )->build_state();
		return array(
			'phase'        => (string) $state['phase'],
			'ready'        => 'done' === $state['phase'],
			'processed'    => (int) $state['processed'],
			'total'        => max( (int) $state['total'], (int) $state['processed'] ),
			'started_at'   => '' !== (string) $state['started_at'] ? mysql_to_rfc3339( (string) $state['started_at'] ) : null,
			'completed_at' => '' !== (string) $state['completed_at'] ? mysql_to_rfc3339( (string) $state['completed_at'] ) : null,
			'queued'       => $this->queue_size(),
		);
	}

	/* ------------------------------------------------------------------
	 * Issues & dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Registers link issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			'orphan_page',
			array(
				'label'       => __( 'No other article links to this page', 'searchpilot-pro' ),
				'category'    => 'links',
				'severity'    => 'medium',
				'what'        => static function ( $issue ) {
					/* translators: %s: post title. */
					$text   = sprintf( __( '“%s” is not linked from the text of any other post or page, and it is not in a menu.', 'searchpilot-pro' ), $issue['title'] );
					$reason = (string) ( $issue['data']['reason'] ?? '' );
					if ( 'cornerstone' === $reason ) {
						$text .= ' ' . __( 'It is marked as cornerstone content, so it should be one of the best-linked pages on the site.', 'searchpilot-pro' );
					} elseif ( 'traffic' === $reason ) {
						$text .= ' ' . __( 'It already gets clicks from search, so links from related articles can help it further.', 'searchpilot-pro' );
					} elseif ( 'recent_listed' === $reason ) {
						$text .= ' ' . __( 'It is still listed on the home page or its category page, but will drop off as newer posts arrive.', 'searchpilot-pro' );
					}
					return $text;
				},
				'why'         => __( 'Home-page and category listings only show the newest posts. Once an article moves further back, links from related articles are the main way readers and search engines keep finding it — and they tell search engines which topics the page belongs to.', 'searchpilot-pro' ),
				'fix'         => __( 'Link to it from two or three related articles where it helps the reader. “Preview links” proposes them — a sentence that already names the topic, or an entry in a short related-articles list at the end of a related post — and shows every change before anything is saved.', 'searchpilot-pro' ),
				'action'      => 'add_internal_links',
				'task'        => \SearchPilot\Core\AutomationPolicy::TASK_INTERNAL_LINKS,
				'mode'        => 'assisted',
				'planner'     => array( $this->get( LinkPlanner::class ), 'plan_orphan_page' ),
				'auto_ok'     => array( $this, 'auto_link_fixes' ),
				'fix_label'   => __( 'Preview links', 'searchpilot-pro' ),
				'manual_step' => __( 'Open two or three related articles and link a phrase that names this topic to the page (or add it to a “Related articles” list at the end).', 'searchpilot-pro' ),
				// Caused by other posts (nobody links here), so a fix to this post never "introduces" it.
				'scope'       => 'incoming',
				// Removing a weak automatic link can leave its target without inbound links: the
				// clean-up reveals that, it does not cause a problem (FixEngine must not undo it).
				'measurable_after' => array( 'weak_auto_links' ),
			)
		);
		$registry->register(
			'broken_internal_link',
			array(
				'label'    => __( 'Broken internal link', 'searchpilot-pro' ),
				'category' => 'links',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$urls = (array) ( $issue['data']['urls'] ?? array() );
					/* translators: 1: post title, 2: URLs. */
					return sprintf( __( '“%1$s” links to pages that no longer exist: %2$s', 'searchpilot-pro' ), $issue['title'], implode( ', ', array_slice( $urls, 0, 5 ) ) );
				},
				'why'      => __( 'Links to missing pages send visitors to an error page and waste the time search engines spend crawling your site.', 'searchpilot-pro' ),
				'fix'      => __( 'Edit the post and update each link to the current address, or remove it. If the page moved, add a redirect from the old address.', 'searchpilot-pro' ),
				'action'   => 'fix_links',
			)
		);
		$registry->register(
			'weak_auto_links',
			array(
				'label'       => __( 'Automatic links with weak link text', 'searchpilot-pro' ),
				'category'    => 'links',
				'severity'    => 'medium',
				'what'        => static function ( $issue ) {
					$data     = (array) ( $issue['data'] ?? array() );
					$count    = (int) ( $data['count'] ?? 0 );
					$examples = array_map(
						static function ( $anchor ) {
							return '«' . $anchor . '»';
						},
						array_slice( (array) ( $data['examples'] ?? array() ), 0, 3 )
					);
					$text     = $examples
						/* translators: 1: number of links, 2: example link texts. */
						? sprintf( _n( '%1$d link SearchPilot added automatically uses weak link text (e.g. %2$s) — remove it.', '%1$d links SearchPilot added automatically use weak link text (e.g. %2$s) — remove them.', $count, 'searchpilot-pro' ), $count, implode( ', ', $examples ) )
						/* translators: %d: number of links. */
						: sprintf( _n( '%d link SearchPilot added automatically points to a page that is no longer published — remove it.', '%d links SearchPilot added automatically point to pages that are no longer published — remove them.', $count, 'searchpilot-pro' ), $count );
					if ( ! empty( $data['more'] ) ) {
						$text .= ' ' . __( 'Only the newest links were checked; more may follow after these are removed.', 'searchpilot-pro' );
					}
					return $text;
				},
				'why'         => __( 'Link text should name the article it leads to. Earlier versions of SearchPilot sometimes linked a common word (such as the city the site covers) together with a neighbouring word, like «across Bengaluru». Such links tell readers and search engines nothing about the target and can look automated.', 'searchpilot-pro' ),
				'fix'         => __( 'Remove these links. The words stay in the text — only the link is removed — and links people added are never touched. Every removal is logged and can be undone.', 'searchpilot-pro' ),
				'action'      => 'fix_links',
				'task'        => \SearchPilot\Core\AutomationPolicy::TASK_INTERNAL_LINKS,
				'mode'        => 'assisted',
				'planner'     => array( $this->get( LinkPlanner::class ), 'plan_weak_auto_links' ),
				// SearchPilot's own additions: in Autopilot it removes them itself (the words stay).
				'auto_ok'     => true,
				'fix_label'   => __( 'Preview removal', 'searchpilot-pro' ),
				'manual_step' => __( 'Open SearchPilot → Internal Links → Review automatic links and remove the listed links.', 'searchpilot-pro' ),
			)
		);
	}

	/**
	 * Whether link fixes (which may add a related-articles list) may run unattended:
	 * only when the admin allowed automatic related lists; in-text links alone are
	 * inserted by the Autopilot backlog either way.
	 *
	 * @return bool
	 */
	public function auto_link_fixes() {
		return (bool) $this->get( Settings::class )->get( 'links.auto_related_block' );
	}

	/**
	 * Adds fix planners to link issue types owned by other modules
	 * (no_internal_links is detected by content analysis).
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_planners( IssueRegistry $registry ) {
		if ( ! method_exists( $registry, 'extend' ) ) {
			return;
		}
		$registry->extend(
			'no_internal_links',
			array(
				'planner'     => array( $this->get( LinkPlanner::class ), 'plan_no_internal_links' ),
				'auto_ok'     => array( $this, 'auto_link_fixes' ),
				'mode'        => 'assisted',
				'task'        => \SearchPilot\Core\AutomationPolicy::TASK_INTERNAL_LINKS,
				'fix_label'   => __( 'Preview links', 'searchpilot-pro' ),
				'manual_step' => __( 'Edit the post and link two or three phrases to related articles on this site.', 'searchpilot-pro' ),
				// Removing a weak automatic link may leave a post without internal links: that is
				// revealed by the clean-up, not caused by it (FixEngine must not undo the removal).
				'measurable_after' => array( 'weak_auto_links' ),
			)
		);
	}

	/**
	 * Dashboard section.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard( $summary ) {
		$summary = is_array( $summary ) ? $summary : array();
		try {
			$stats            = $this->get( LinkGraph::class )->stats();
			$summary['links'] = array(
				'indexed_posts'       => (int) $stats['indexed_posts'],
				'internal_links'      => (int) $stats['internal_links'],
				'orphans'             => (int) $stats['orphans'],
				'suggestions_pending' => (int) $this->get( SuggestionRepository::class )->counts()['pending'],
			);
		} catch ( \Throwable $e ) {
			$summary['links'] = array(
				'indexed_posts'       => 0,
				'internal_links'      => 0,
				'orphans'             => 0,
				'suggestions_pending' => 0,
			);
		}
		return $summary;
	}
}
