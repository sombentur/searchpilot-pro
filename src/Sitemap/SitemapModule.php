<?php
/**
 * XML sitemaps module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires XML sitemaps: routing (only while SearchPilot owns the `sitemap`
 * feature — checked lazily), fine-grained cache invalidation, cache purges,
 * debounced submission of the active sitemap (ours, or the owning plugin's
 * in companion mode), health checks and the video metadata cache.
 *
 * Invalidation is as narrow as possible:
 *   - editing a published post re-renders only the page of its type's
 *     sitemap that holds it (the page summary's lastmod is patched in place);
 *   - taxonomy and author sitemaps change only on publish/unpublish/delete,
 *     term assignment or author changes;
 *   - video only for posts with videos, news only for recent news posts;
 *   - structural changes recompute the page boundaries once per request and
 *     re-render only the pages whose boundaries or members changed.
 *
 * <lastmod> follows the last SIGNIFICANT change (post meta
 * `_searchpilot_lastmod`, written from the change detector's
 * searchpilot_url_changed events), so trivial saves do not move it.
 */
class SitemapModule implements Module {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Router.
	 *
	 * @var SitemapRouter
	 */
	private $router;

	/**
	 * Builder.
	 *
	 * @var SitemapBuilder
	 */
	private $builder;

	/**
	 * Cache.
	 *
	 * @var SitemapCache
	 */
	private $cache;

	/**
	 * Health.
	 *
	 * @var SitemapHealth
	 */
	private $health;

	/**
	 * Video detector.
	 *
	 * @var VideoDetector
	 */
	private $video;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * Policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Changes collected in this request: group => [ ids => [ id => lastmod ], structural => bool ].
	 *
	 * @var array<string,array>
	 */
	private $changes = array();

	/**
	 * URLs already purged in this request.
	 *
	 * @var array<string,bool>
	 */
	private $purged = array();

	/**
	 * post_modified_gmt of published posts before this request's save.
	 *
	 * @var array<int,string>
	 */
	private $before_modified = array();

	/**
	 * Whether a submission should be scheduled at shutdown.
	 *
	 * @var bool
	 */
	private $submit = false;

	/**
	 * Whether news content changed.
	 *
	 * @var bool
	 */
	private $news_dirty = false;

	/**
	 * Whether the shutdown handler is hooked.
	 *
	 * @var bool
	 */
	private $hooked = false;

	/**
	 * Meta keys that change sitemap membership or content (lazy).
	 *
	 * @var array<string,bool>|null
	 */
	private $watched_keys = null;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param State            $state     State.
	 * @param ConflictDetector $conflicts Conflict detector.
	 * @param SitemapRouter    $router    Router.
	 * @param SitemapBuilder   $builder   Builder.
	 * @param SitemapCache     $cache     Cache.
	 * @param SitemapHealth    $health    Health.
	 * @param VideoDetector    $video     Video detector.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param CachePurger      $purger    Cache purger.
	 * @param AutomationPolicy $policy    Policy.
	 * @param ContentTypes     $types     Content types.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( Settings $settings, State $state, ConflictDetector $conflicts, SitemapRouter $router, SitemapBuilder $builder, SitemapCache $cache, SitemapHealth $health, VideoDetector $video, Scheduler $scheduler, CachePurger $purger, AutomationPolicy $policy, ContentTypes $types, Logger $logger ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->conflicts = $conflicts;
		$this->router    = $router;
		$this->builder   = $builder;
		$this->cache     = $cache;
		$this->health    = $health;
		$this->video     = $video;
		$this->scheduler = $scheduler;
		$this->purger    = $purger;
		$this->policy    = $policy;
		$this->types     = $types;
		$this->logger    = $logger;
	}

	/**
	 * SearchPilot's sitemap index URL.
	 *
	 * @return string
	 */
	public static function index_url() {
		return SitemapRouter::url( 'index' );
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( $this->router, 'register_rules' ), 10 );
		add_filter( 'query_vars', array( $this->router, 'query_vars' ) );
		add_action( 'parse_request', array( $this->router, 'maybe_serve' ), 1 );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'core_sitemaps_enabled' ), 99 );

		// Change tracking (core hooks) and significance/submission (A's change detector).
		add_action( 'searchpilot_url_changed', array( $this, 'on_url_changed' ), 10 );
		add_action( 'pre_post_update', array( $this, 'before_update' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 99, 2 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'on_object_terms' ), 20, 6 );
		add_action( 'edited_term', array( $this, 'on_term_edited' ), 20, 3 );
		add_action( 'delete_term', array( $this, 'on_term_deleted' ), 20, 3 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 20 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ), 20 );
		add_action( 'added_post_meta', array( $this, 'on_meta' ), 20, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta' ), 20, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta' ), 20, 3 );
		add_action( 'searchpilot_settings_updated', array( $this, 'on_settings' ), 20, 2 );

		// Background work runs in its own requests (TimeBudget is request-relative).
		add_action( 'searchpilot_sitemap_submit', array( $this, 'submit' ) );
		add_action( 'searchpilot_daily', array( $this, 'schedule_health' ), 20 );
		add_action( 'searchpilot_sitemap_health', array( $this, 'daily' ) );
		add_action( 'searchpilot_hourly', array( $this, 'schedule_continue' ), 45 );
		add_action( 'searchpilot_sitemap_health_continue', array( $this->health, 'continue_cycle' ) );
		add_action( 'searchpilot_initial_sync', array( $this, 'initial_sync' ), 20 );
		add_action( 'searchpilot_register_issue_types', array( $this->health, 'register_issue_types' ) );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard' ) );

		$this->video->register();
	}

	/**
	 * Whether SearchPilot generates the sitemaps.
	 *
	 * @return bool
	 */
	public function owns() {
		return $this->conflicts->owns( 'sitemap' );
	}

	/**
	 * wp_sitemaps_enabled: core sitemaps are replaced by ours while we own sitemaps.
	 *
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public function core_sitemaps_enabled( $enabled ) {
		return $this->owns() ? false : $enabled;
	}

	/* ------------------------------------------------------------------ *
	 * Change tracking
	 * ------------------------------------------------------------------ */

	/**
	 * Records a change of objects in one sitemap group.
	 *
	 * @param string            $group      Group (pt-post, tax-category, authors, video, news).
	 * @param array<int,string> $ids        Object id => new lastmod ('' = unchanged).
	 * @param bool              $structural Objects entered/left the group's candidates.
	 */
	private function note( $group, array $ids, $structural ) {
		if ( ! $this->owns() || ! $this->builder->is_type( $group ) ) {
			return;
		}
		$entry = $this->changes[ $group ] ?? array(
			'ids'        => array(),
			'structural' => false,
		);
		$urls  = array();
		foreach ( $ids as $id => $lastmod ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			$entry['ids'][ $id ] = max( (string) ( $entry['ids'][ $id ] ?? '' ), (string) $lastmod );
			$page                = $this->builder->page_for( $group, $id );
			if ( $page ) {
				$urls[] = SitemapRouter::url( $group, $page );
			}
		}
		$entry['structural']      = $entry['structural'] || $structural;
		$this->changes[ $group ] = $entry;
		if ( 'news' === $group ) {
			$urls[]           = SitemapRouter::url( 'news' );
			$this->news_dirty = true;
		}
		$urls[] = self::index_url();
		$this->purge( $urls );
		$this->hook_shutdown();
	}

	/**
	 * Records a change of a published post.
	 *
	 * @param \WP_Post $post       Post.
	 * @param bool     $structural Published/unpublished/deleted.
	 * @param bool     $related    Also its terms and author (publish/unpublish/delete).
	 */
	private function note_post( \WP_Post $post, $structural, $related ) {
		$group = 'pt-' . $post->post_type;
		if ( ! $this->owns() || ! $this->builder->is_type( $group ) ) {
			return;
		}
		$lastmod = $this->builder->post_lastmod( $post );
		$this->note( $group, array( (int) $post->ID => $lastmod ), $structural );
		if ( $related ) {
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				if ( ! $this->builder->is_type( 'tax-' . $taxonomy ) ) {
					continue;
				}
				$terms = get_the_terms( $post, $taxonomy );
				if ( is_array( $terms ) && $terms ) {
					$this->note( 'tax-' . $taxonomy, array_fill_keys( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ), $lastmod ), true );
				}
			}
			$this->note( 'authors', array( (int) $post->post_author => $lastmod ), true );
		}
		if ( metadata_exists( 'post', $post->ID, VideoDetector::META ) ) {
			$this->note( 'video', array( (int) $post->ID => $lastmod ), $structural );
		}
		if ( $this->builder->is_type( 'news' ) && in_array( $post->post_type, (array) $this->settings->get( 'sitemap.news_post_types', array( 'post' ) ), true ) && strtotime( $post->post_date_gmt . ' UTC' ) >= time() - 2 * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			$this->note( 'news', array(), true );
		}
	}

	/**
	 * Purges URLs from page caches (once per request, while headers can still be sent).
	 *
	 * @param string[] $urls URLs.
	 */
	private function purge( array $urls ) {
		$new = array();
		foreach ( $urls as $url ) {
			if ( '' !== (string) $url && ! isset( $this->purged[ $url ] ) ) {
				$this->purged[ $url ] = true;
				$new[]                = $url;
			}
		}
		if ( $new ) {
			$this->purger->purge_urls( array_slice( $new, 0, 300 ) );
		}
	}

	/**
	 * Hooks the shutdown flush once (after the change detector's own flush at priority 1).
	 */
	private function hook_shutdown() {
		if ( ! $this->hooked ) {
			$this->hooked = true;
			add_action( 'shutdown', array( $this, 'flush' ), 50 );
		}
	}

	/**
	 * shutdown: applies the collected changes (page patches or one boundary
	 * recomputation per group), writes the version bumps once and schedules
	 * the debounced submission.
	 */
	public function flush() {
		$store = array();
		$urls  = array();
		foreach ( $this->changes as $group => $change ) {
			$ids = array_keys( $change['ids'] );
			if ( ! $change['structural'] && null !== $this->builder->cached_summary( $group ) ) {
				$pages   = array();
				$missing = false;
				foreach ( $change['ids'] as $id => $lastmod ) {
					$page = $this->builder->patch_summary( $group, (int) $id, (string) $lastmod );
					if ( $page ) {
						$pages[] = $page;
					} else {
						$missing = true;
					}
				}
				if ( ! $missing ) {
					$this->cache->bump_pages( $group, $pages );
					foreach ( array_unique( $pages ) as $page ) {
						$urls[] = SitemapRouter::url( $group, $page );
					}
					continue;
				}
			}
			// Structural (or not in any cached page): recompute boundaries once.
			$result = $this->builder->recompute( $group, $ids );
			$this->cache->bump_summary( $group );
			$this->cache->bump_pages( $group, $result['pages'] );
			$store[ $group ] = $result['summary'];
			foreach ( $result['pages'] as $page ) {
				$urls[] = SitemapRouter::url( $group, $page );
			}
		}
		$this->changes = array();
		$this->cache->flush();
		foreach ( $store as $group => $summary ) {
			$this->cache->set( 'summary:' . $group, 1, $summary );
		}
		if ( $urls ) {
			$urls[] = self::index_url();
			$this->purge( $urls );
		}
		if ( $this->news_dirty ) {
			$this->state->set( 'sitemap_news_dirty', true );
		}
		if ( $this->submit && $this->settings->get( 'sitemap.submit_on_change', true ) && ! $this->policy->disabled( AutomationPolicy::TASK_SUBMIT_SITEMAPS ) && class_exists( '\SearchPilot\Queue\SubmissionQueue' ) ) {
			$this->scheduler->enqueue_async( 'searchpilot_sitemap_submit', array(), 300 );
		}
		$this->submit     = false;
		$this->news_dirty = false;
		$this->hooked     = false;
	}

	/**
	 * searchpilot_url_changed: records the time of significant changes (sitemap
	 * lastmod) and flags a debounced submission.
	 *
	 * @param array $change Change.
	 */
	public function on_url_changed( $change ) {
		if ( ! is_array( $change ) ) {
			return;
		}
		$event       = (string) ( $change['event'] ?? '' );
		$type        = (string) ( $change['object_type'] ?? '' );
		$id          = (int) ( $change['object_id'] ?? 0 );
		$significant = ! empty( $change['significant'] ) || 'update' !== $event;
		if ( 'post' === $type && $id && in_array( $event, array( 'publish', 'update', 'redirect', 'index' ), true ) ) {
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status ) {
				if ( $significant ) {
					if ( get_post_meta( $id, SitemapBuilder::LASTMOD_META, true ) !== $post->post_modified_gmt ) {
						update_post_meta( $id, SitemapBuilder::LASTMOD_META, $post->post_modified_gmt );
						$this->note_post( $post, false, false );
					}
				} elseif ( ! metadata_exists( 'post', $id, SitemapBuilder::LASTMOD_META ) && ! empty( $this->before_modified[ $id ] ) ) {
					// First trivial edit of an older post: keep its previous date as the significant one.
					update_post_meta( $id, SitemapBuilder::LASTMOD_META, $this->before_modified[ $id ] );
					$this->note_post( $post, false, false );
				}
			}
		}
		if ( $significant ) {
			$this->submit = true;
			$this->hook_shutdown();
		}
	}

	/**
	 * pre_post_update: remembers the modified date of published posts before the save.
	 *
	 * @param int $post_id Post id.
	 */
	public function before_update( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( $post && 'publish' === $post->post_status && count( $this->before_modified ) < 500 ) {
			$this->before_modified[ (int) $post_id ] = (string) $post->post_modified_gmt;
		}
	}

	/**
	 * transition_post_status: publish/unpublish are structural.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( $post instanceof \WP_Post && $new !== $old && ( 'publish' === $new || 'publish' === $old ) ) {
			$this->note_post( $post, true, true );
		}
	}

	/**
	 * save_post of published content: its own page only.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->note_post( $post, false, false );
	}

	/**
	 * post_updated: author changes move the post between author archives.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( $after instanceof \WP_Post && $before instanceof \WP_Post && 'publish' === $after->post_status && (int) $after->post_author !== (int) $before->post_author ) {
			$this->note(
				'authors',
				array(
					(int) $before->post_author => '',
					(int) $after->post_author  => $this->builder->post_lastmod( $after ),
				),
				true
			);
		}
	}

	/**
	 * before_delete_post: permanent deletion of published content (terms are still attached here).
	 *
	 * @param int $post_id Post id.
	 */
	public function on_before_delete( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( $post && 'publish' === $post->post_status ) {
			$this->note_post( $post, true, true );
		}
	}

	/**
	 * set_object_terms: terms added to or removed from a published post.
	 *
	 * @param int    $object_id  Object id.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     New term taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append.
	 * @param array  $old_tt_ids Previous term taxonomy ids.
	 */
	public function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( ! $this->builder->is_type( 'tax-' . $taxonomy ) ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$new     = array_map( 'intval', (array) $tt_ids );
		$old     = array_map( 'intval', (array) $old_tt_ids );
		$changed = array_unique( array_merge( array_diff( $new, $old ), array_diff( $old, $new ) ) );
		if ( empty( $changed ) ) {
			return;
		}
		$lastmod = $this->builder->post_lastmod( $post );
		$ids     = array();
		foreach ( $changed as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[ (int) $term->term_id ] = $lastmod;
			}
		}
		if ( $ids ) {
			$this->note( 'tax-' . $taxonomy, $ids, true );
		}
	}

	/**
	 * edited_term: name/slug changes affect only the term's page.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_edited( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( '' !== (string) $taxonomy ) {
			$this->note( 'tax-' . $taxonomy, array( (int) $term_id => '' ), false );
		}
	}

	/**
	 * delete_term: structural for its taxonomy.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_deleted( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( '' !== (string) $taxonomy ) {
			$this->note( 'tax-' . $taxonomy, array( (int) $term_id => '' ), true );
		}
	}

	/**
	 * profile_update: the author archive URL may change.
	 *
	 * @param int $user_id User id.
	 */
	public function on_profile_update( $user_id ) {
		$this->note( 'authors', array( (int) $user_id => '' ), false );
	}

	/**
	 * deleted_user: structural for authors.
	 *
	 * @param int $user_id User id.
	 */
	public function on_user_deleted( $user_id ) {
		$this->note( 'authors', array( (int) $user_id => '' ), true );
	}

	/**
	 * Post meta that changes sitemap membership or content (noindex, canonical,
	 * exclusion, featured image, videos).
	 *
	 * @param int    $meta_id   Meta id(s).
	 * @param int    $object_id Post id.
	 * @param string $meta_key  Meta key.
	 */
	public function on_meta( $meta_id, $object_id, $meta_key ) {
		if ( VideoDetector::META === $meta_key ) {
			$post = get_post( (int) $object_id );
			if ( $post && 'publish' === $post->post_status ) {
				// A post gaining or losing videos enters/leaves the video sitemap.
				$this->note( 'video', array( (int) $post->ID => $this->builder->post_lastmod( $post ) ), 'updated_post_meta' !== current_action() );
			}
			return;
		}
		if ( null === $this->watched_keys ) {
			$keys = array( '_thumbnail_id' => true );
			$meta = \SearchPilot\Plugin::instance()->get( MetaStore::class );
			foreach ( array( 'noindex', 'canonical', 'sitemap_exclude' ) as $field ) {
				$key = MetaStore::post_key( $meta->store_for( $field ), $field );
				if ( '' !== $key ) {
					$keys[ $key ] = true;
				}
				$keys[ MetaStore::PREFIX . $field ] = true;
			}
			$this->watched_keys = $keys;
		}
		if ( ! isset( $this->watched_keys[ (string) $meta_key ] ) ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( $post && 'publish' === $post->post_status ) {
			// Noindex/exclusion keeps the post inside the same ID window: only its page changes.
			$this->note_post( $post, false, false );
		}
	}

	/**
	 * Settings changed: sitemap, content types or ownership affect every sitemap.
	 *
	 * @param array $new Settings.
	 * @param array $old Previous settings.
	 */
	public function on_settings( $new, $old ) {
		$changed = false;
		foreach ( array( 'sitemap', 'content', 'ownership' ) as $section ) {
			if ( wp_json_encode( $new[ $section ] ?? array() ) !== wp_json_encode( $old[ $section ] ?? array() ) ) {
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return;
		}
		$this->builder->reset();
		$was_video = ! empty( $old['sitemap']['video'] ) || ! empty( $old['schema']['video'] );
		$is_video  = ! empty( $new['sitemap']['video'] ) || ! empty( $new['schema']['video'] );
		if ( $is_video && ! $was_video ) {
			$this->video->reset_backlog();
		}
		if ( $this->owns() ) {
			$this->purge_everything();
			$this->cache->bump_all();
			$this->submit = true;
			$this->hook_shutdown();
		}
	}

	/**
	 * Purges the index and every known child sitemap.
	 */
	private function purge_everything() {
		$urls    = array( self::index_url(), SitemapRouter::url( 'news' ) );
		$entries = $this->cache->get( 'index_data' );
		foreach ( (array) $entries as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['loc'] ) ) {
				$urls[] = $entry['loc'];
			}
		}
		$this->purged = array();
		$this->purge( $urls );
	}

	/* ------------------------------------------------------------------ *
	 * Submission
	 * ------------------------------------------------------------------ */

	/**
	 * Debounced submission (async hook): hands the active sitemap to the submission queue.
	 */
	public function submit() {
		$class = '\SearchPilot\Queue\SubmissionQueue';
		if ( ! class_exists( $class ) || ! $this->settings->get( 'sitemap.submit_on_change', true ) || $this->policy->disabled( AutomationPolicy::TASK_SUBMIT_SITEMAPS ) ) {
			return;
		}
		$url = SitemapHealth::active_sitemap_url();
		if ( '' === $url ) {
			return;
		}
		$queue = \SearchPilot\Plugin::instance()->get( $class );
		$queue->enqueue_sitemap( $url, 'sitemap_change' );
		if ( $this->state->get( 'sitemap_news_dirty', false ) ) {
			$this->state->set( 'sitemap_news_dirty', false );
			if ( $this->owns() && $this->builder->news_enabled() ) {
				$queue->enqueue_sitemap( SitemapRouter::url( 'news' ), 'news_change' );
			}
		}
	}

	/**
	 * Which engines can receive a sitemap.
	 *
	 * @return array{google:bool,bing:bool}
	 */
	public static function engines() {
		$plugin = \SearchPilot\Plugin::instance();
		$out    = array(
			'google' => false,
			'bing'   => false,
		);
		try {
			if ( class_exists( '\SearchPilot\Api\Google\SearchConsole' ) ) {
				$out['google'] = (bool) $plugin->get( '\SearchPilot\Api\Google\SearchConsole' )->is_connected();
			}
			if ( class_exists( '\SearchPilot\Api\Bing\BingWebmaster' ) ) {
				$out['bing'] = (bool) $plugin->get( '\SearchPilot\Api\Bing\BingWebmaster' )->is_connected();
			}
		} catch ( \Throwable $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * User-initiated submission (REST). The message names what was really queued.
	 *
	 * @return array{queued:array,services:string[],held:bool,message:string}|\WP_Error
	 */
	public function submit_now() {
		$class = '\SearchPilot\Queue\SubmissionQueue';
		if ( ! class_exists( $class ) ) {
			return new \WP_Error( 'searchpilot_unavailable', __( 'Search engine submission is not available in this installation.', 'searchpilot-pro' ), array( 'status' => 501 ) );
		}
		$url = SitemapHealth::active_sitemap_url();
		if ( '' === $url ) {
			return new \WP_Error( 'searchpilot_no_sitemap', __( 'No sitemap is active on this site.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$engines = self::engines();
		if ( ! $engines['google'] && ! $engines['bing'] ) {
			return new \WP_Error( 'searchpilot_no_engines', __( 'Nothing was queued: connect Google Search Console or Bing Webmaster Tools first (Search Engines screen).', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$queue  = \SearchPilot\Plugin::instance()->get( $class );
		$urls   = array( $url );
		$queued = array( $url => (array) $queue->enqueue_sitemap( $url, 'manual', true ) );
		if ( $this->owns() && $this->builder->news_enabled() ) {
			$news            = SitemapRouter::url( 'news' );
			$urls[]          = $news;
			$queued[ $news ] = (array) $queue->enqueue_sitemap( $news, 'manual', true );
		}
		$services = array();
		foreach ( $queued as $result ) {
			foreach ( $result as $service => $queue_id ) {
				if ( (int) $queue_id > 0 ) {
					$services[ $service ] = true;
				}
			}
		}
		if ( empty( $services ) ) {
			return new \WP_Error( 'searchpilot_not_queued', __( 'Nothing was queued. The sitemap was probably submitted a few minutes ago — try again later.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$labels = array(
			'gsc_sitemap'  => __( 'Google Search Console', 'searchpilot-pro' ),
			'bing_sitemap' => __( 'Bing Webmaster Tools', 'searchpilot-pro' ),
		);
		$names  = array();
		foreach ( array_keys( $services ) as $service ) {
			$names[] = $labels[ $service ] ?? $service;
		}
		$held     = false;
		$decision = method_exists( $queue, 'decide' ) ? (array) $queue->decide( AutomationPolicy::TASK_SUBMIT_SITEMAPS, true ) : array();
		/* translators: %s: list of services. */
		$message = sprintf( __( 'Queued for %s.', 'searchpilot-pro' ), implode( ' & ', $names ) );
		if ( 'held' === ( $decision['status'] ?? '' ) ) {
			$held    = true;
			$env     = \SearchPilot\Plugin::instance()->get( \SearchPilot\Core\Environment::class );
			$reason  = method_exists( $env, 'submission_block_message' ) ? (string) $env->submission_block_message() : '';
			$message = sprintf(
				/* translators: 1: services, 2: reason. */
				__( 'Queued for %1$s but held: %2$s', 'searchpilot-pro' ),
				implode( ' & ', $names ),
				'' !== $reason ? $reason : __( 'submissions are paused on this site.', 'searchpilot-pro' )
			);
		}
		if ( ! $engines['google'] || ! $engines['bing'] ) {
			$message .= ' ' . ( $engines['google'] ? __( 'Bing Webmaster Tools is not connected.', 'searchpilot-pro' ) : __( 'Google Search Console is not connected.', 'searchpilot-pro' ) );
		}
		return array(
			'queued'   => $queued,
			'urls'     => $urls,
			'services' => array_keys( $services ),
			'held'     => $held,
			'message'  => $message,
		);
	}

	/**
	 * Regenerates our sitemaps now (REST).
	 *
	 * @return array{children:int}
	 */
	public function regenerate() {
		$this->builder->reset();
		$this->purge_everything();
		$this->cache->bump_all();
		$children = 0;
		if ( $this->owns() ) {
			$this->builder->render( 'index' );
			$children = count( $this->builder->index_entries() );
			$this->purge_everything();
		}
		return array( 'children' => $children );
	}

	/* ------------------------------------------------------------------ *
	 * Background
	 * ------------------------------------------------------------------ */

	/**
	 * searchpilot_daily: runs the health check in its own request.
	 */
	public function schedule_health() {
		$this->scheduler->enqueue_async( 'searchpilot_sitemap_health', array(), 30 );
	}

	/**
	 * searchpilot_hourly: continues an unfinished health cycle in its own request.
	 */
	public function schedule_continue() {
		$health = (array) $this->state->get( SitemapHealth::STATE, array() );
		if ( ! empty( $health['cycle']['queue'] ) ) {
			$this->scheduler->enqueue_async( 'searchpilot_sitemap_health_continue', array(), 60 );
		}
	}

	/**
	 * Health check (async hook).
	 */
	public function daily() {
		$this->health->run( 25 );
	}

	/**
	 * First sync after the setup wizard: warm our index and check health once.
	 */
	public function initial_sync() {
		if ( $this->owns() ) {
			$this->builder->render( 'index' );
		}
		$this->scheduler->enqueue_async( 'searchpilot_sitemap_health', array(), 30 );
	}

	/**
	 * searchpilot_dashboard_summary: adds `sitemaps`.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard( $summary ) {
		$summary             = is_array( $summary ) ? $summary : array();
		$status              = SitemapHealth::status();
		$summary['sitemaps'] = array_intersect_key( $status, array_flip( array( 'owner', 'owner_name', 'index_url', 'status', 'checked_at', 'url_count', 'errors' ) ) );
		return $summary;
	}

	/**
	 * Builder (for controllers).
	 *
	 * @return SitemapBuilder
	 */
	public function builder() {
		return $this->builder;
	}

	/**
	 * Health service (for controllers).
	 *
	 * @return SitemapHealth
	 */
	public function health() {
		return $this->health;
	}
}
