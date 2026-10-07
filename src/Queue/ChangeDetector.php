<?php
/**
 * Detects meaningful URL changes and notifies search engines through the queue.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

use SearchPilot\Container;
use SearchPilot\Core\Changes;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\SimHash;
use SearchPilot\Support\Text;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks are cheap: they only record what happened. The decision is made
 * once per post at the end of the request (flush() on `shutdown`), when the
 * editor, meta boxes and SEO plugins have saved everything — so a publish
 * with a simultaneous noindex, or Gutenberg's double save (REST + meta
 * boxes), is evaluated once with the final state.
 *
 * For every event the post's URL-registry row is updated, the action
 * `searchpilot_url_changed` fires, and URLs are queued according to
 * `indexing.notify_publish|notify_update|notify_delete`. Removed and
 * noindexed URLs are sent with action "delete" (Indexing API URL_DELETED;
 * IndexNow/Bing simply recrawl). Redirects notify old and new URL.
 */
class ChangeDetector {

	/**
	 * Meta keys (of every supported SEO plugin) whose change matters.
	 *
	 * @var array<string,string>|null key => field
	 */
	private static $watched = null;

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
	 * Posts touched in this request: id => entry.
	 *
	 * @var array<int,array>
	 */
	private $pending = array();

	/**
	 * Signatures of events already fired in this request.
	 *
	 * @var array<string,bool>
	 */
	private $fired = array();

	/**
	 * Term URLs captured before edits/deletion: term_id => url.
	 *
	 * @var array<int,array>
	 */
	private $term_before = array();

	/**
	 * Cached public post type names.
	 *
	 * @var string[]|null
	 */
	private $post_types = null;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container (heavy services are resolved lazily).
	 * @param Settings  $settings  Settings.
	 */
	public function __construct( Container $container, Settings $settings ) {
		$this->container = $container;
		$this->settings  = $settings;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 99, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_trash' ), 1 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ), 1, 2 );
		add_action( 'add_post_meta', array( $this, 'before_meta_add' ), 1, 2 );
		add_action( 'update_post_meta', array( $this, 'before_meta_change' ), 1, 3 );
		add_action( 'delete_post_meta', array( $this, 'before_meta_change' ), 1, 3 );
		add_action( 'added_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10, 3 );
		add_action( 'created_term', array( $this, 'on_term_created' ), 10, 3 );
		add_action( 'edit_terms', array( $this, 'on_term_before_edit' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'on_term_edited' ), 10, 3 );
		add_action( 'pre_delete_term', array( $this, 'on_term_before_delete' ), 10, 2 );
		add_action( 'delete_term', array( $this, 'on_term_deleted' ), 10, 3 );
		add_action( 'shutdown', array( $this, 'flush' ), 1 );
	}

	/**
	 * Resolves a service.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	private function service( $class ) {
		return $this->container->get( $class );
	}

	/* ------------------------------------------------------------------
	 * Post hooks (record only)
	 * ------------------------------------------------------------------ */

	/**
	 * Status transitions: new publish, unpublish, trash.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function on_transition( $new, $old, $post ) {
		if ( $new === $old || ! $this->tracks( $post ) ) {
			return;
		}
		if ( 'publish' === $new ) {
			$this->touch( $post, array( 'published' => true ) );
		} elseif ( 'publish' === $old ) {
			$this->touch(
				$post,
				array(
					'trash' === $new ? 'deleted' : 'unpublished' => true,
					'published' => false,
				)
			);
		}
	}

	/**
	 * Captures the post before an update (old slug/title/content/permalink).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 */
	public function on_post_updated( $post_id, $after, $before ) {
		if ( ! $this->tracks( $after ) || ! $before instanceof \WP_Post ) {
			return;
		}
		$entry = $this->pending[ (int) $post_id ] ?? array();
		if ( ! isset( $entry['before'] ) ) {
			$entry['before'] = array(
				'status'  => (string) $before->post_status,
				'title'   => (string) $before->post_title,
				'name'    => (string) $before->post_name,
				'excerpt' => (string) $before->post_excerpt,
				'parent'  => (int) $before->post_parent,
				'date'    => (string) $before->post_date,
				'content' => (string) $before->post_content,
				'url'     => 'publish' === $before->post_status ? (string) get_permalink( $before ) : '',
			);
		}
		$this->pending[ (int) $post_id ] = $entry;
		$this->touch( $after, array() );
	}

	/**
	 * Marks the post as saved (and whether SearchPilot itself wrote it).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		if ( ! $this->tracks( $post ) ) {
			return;
		}
		$this->touch( $post, array( 'internal' => Changes::is_internal_write() ) );
	}

	/**
	 * Captures the published URL before WordPress appends "__trashed" to the slug.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trash( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $this->tracks( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$this->touch( $post, array( 'removed_url' => (string) get_permalink( $post ) ) );
	}

	/**
	 * Permanent deletion of a published post (trash skipped).
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post.
	 */
	public function on_before_delete( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $this->tracks( $post ) ) {
			return;
		}
		if ( 'publish' === $post->post_status ) {
			$this->touch(
				$post,
				array(
					'deleted'     => true,
					'removed_url' => (string) get_permalink( $post ),
				)
			);
			return;
		}
		// A trashed post being emptied from the trash: its URL was handled at trash time.
		$this->urls()->mark_removed_for_object( 'post', (int) $post_id );
	}

	/**
	 * SEO meta changes (title, description, robots, canonical of any supported plugin).
	 *
	 * @param int    $meta_id  Meta id.
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 */
	public function on_meta( $meta_id, $post_id, $meta_key ) {
		$watched = self::watched_keys();
		if ( ! isset( $watched[ $meta_key ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $this->tracks( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$entry                           = $this->pending[ (int) $post_id ] ?? array();
		$entry['meta']                   = array_merge( (array) ( $entry['meta'] ?? array() ), array( $watched[ $meta_key ] => true ) );
		$this->pending[ (int) $post_id ] = $entry;
		$this->touch( $post, array() );
	}

	/**
	 * Deleted SEO meta.
	 *
	 * @param string[] $meta_ids Meta ids.
	 * @param int      $post_id  Post id.
	 * @param string   $meta_key Meta key.
	 */
	public function on_meta_deleted( $meta_ids, $post_id, $meta_key ) {
		$this->on_meta( 0, $post_id, $meta_key );
	}

	/**
	 * Before an SEO title/description meta is added: remember the current output.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 */
	public function before_meta_add( $post_id, $meta_key ) {
		$this->capture_seo_output( (int) $post_id, (string) $meta_key );
	}

	/**
	 * Before an SEO title/description meta is updated or deleted.
	 *
	 * @param int|int[] $meta_id  Meta id(s).
	 * @param int       $post_id  Post id.
	 * @param string    $meta_key Meta key.
	 */
	public function before_meta_change( $meta_id, $post_id, $meta_key ) {
		$this->capture_seo_output( (int) $post_id, (string) $meta_key );
	}

	/**
	 * Remembers the title/description a published post outputs before its
	 * SEO meta changes, so a write that leaves the output unchanged (e.g. an
	 * automatic fill with the text already used as fallback) is not treated
	 * as a significant change.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 */
	private function capture_seo_output( $post_id, $meta_key ) {
		$watched = self::watched_keys();
		if ( ! isset( $watched[ $meta_key ] ) || ! in_array( $watched[ $meta_key ], array( 'title', 'description' ), true ) ) {
			return;
		}
		if ( isset( $this->pending[ $post_id ]['seo_before'] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $this->tracks( $post ) || 'publish' !== $post->post_status ) {
			return;
		}
		$entry                     = $this->pending[ $post_id ] ?? array();
		$entry['seo_before']       = $this->seo_output( $post );
		$this->pending[ $post_id ] = $entry;
	}

	/**
	 * Title and meta description a post outputs.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{title:string,description:string}
	 */
	private function seo_output( \WP_Post $post ) {
		/** Meta store. @var MetaStore $meta */
		$meta = $this->service( MetaStore::class );
		return array(
			'title'       => (string) $meta->effective_title( $post ),
			'description' => (string) $meta->effective_description( $post ),
		);
	}

	/**
	 * Meta keys that can change a post's search appearance or indexability.
	 *
	 * @return array<string,string> key => field
	 */
	public static function watched_keys() {
		if ( null !== self::$watched ) {
			return self::$watched;
		}
		$fields = array( 'title', 'description', 'noindex', 'canonical', 'robots' );
		$keys   = array();
		foreach ( MetaStore::POST_KEYS as $store_keys ) {
			foreach ( $fields as $field ) {
				if ( ! empty( $store_keys[ $field ] ) ) {
					$keys[ $store_keys[ $field ] ] = 'robots' === $field ? 'noindex' : $field;
				}
			}
		}
		foreach ( array( 'title', 'description', 'noindex', 'canonical', 'indexing_api' ) as $field ) {
			$keys[ MetaStore::PREFIX . $field ] = $field;
		}
		self::$watched = $keys;
		return $keys;
	}

	/**
	 * Whether a post is tracked at all (public type, not a revision/autosave/import).
	 *
	 * @param mixed $post Post.
	 * @return bool
	 */
	private function tracks( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}
		if ( 'revision' === $post->post_type || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return false;
		}
		if ( null === $this->post_types ) {
			$this->post_types = array_keys( $this->content_types()->post_types() );
		}
		return in_array( $post->post_type, $this->post_types, true );
	}

	/**
	 * Records a touched post.
	 *
	 * @param \WP_Post $post  Post.
	 * @param array    $flags Flags to merge.
	 */
	private function touch( \WP_Post $post, array $flags ) {
		$id    = (int) $post->ID;
		$entry = $this->pending[ $id ] ?? array();
		foreach ( $flags as $flag => $value ) {
			if ( 'internal' === $flag ) {
				// Internal only when every save in this request was SearchPilot's own.
				$entry['internal'] = isset( $entry['internal'] ) ? ( $entry['internal'] && $value ) : (bool) $value;
				continue;
			}
			if ( 'removed_url' === $flag && '' === (string) $value ) {
				continue;
			}
			$entry[ $flag ] = $value;
		}
		$entry['post_type']   = (string) $post->post_type;
		$this->pending[ $id ] = $entry;
		// Long CLI/cron loops: evaluate early so memory stays bounded.
		if ( count( $this->pending ) > 100 && ! $this->flushing ) {
			$this->flush();
		}
	}

	/* ------------------------------------------------------------------
	 * Evaluation
	 * ------------------------------------------------------------------ */

	/**
	 * Evaluates every touched post (runs on shutdown; callable directly).
	 *
	 * @return array[] Events fired.
	 */
	public function flush() {
		if ( $this->flushing || empty( $this->pending ) ) {
			return array();
		}
		$this->flushing = true;
		$events         = array();
		try {
			while ( $this->pending ) {
				$id    = (int) array_key_first( $this->pending );
				$entry = $this->pending[ $id ];
				unset( $this->pending[ $id ] );
				try {
					$events = array_merge( $events, $this->evaluate( $id, $entry ) );
				} catch ( \Throwable $e ) {
					$this->logger()->error( 'queue', 'Change detection failed for post ' . $id . ': ' . $e->getMessage() );
				}
			}
		} finally {
			$this->flushing = false;
		}
		return $events;
	}

	/**
	 * Decides what happened to one post.
	 *
	 * @param int   $id    Post id.
	 * @param array $entry Recorded entry.
	 * @return array[] Events.
	 */
	private function evaluate( $id, array $entry ) {
		$post    = get_post( $id );
		$urls    = $this->urls();
		$row     = $urls->get_by_object( 'post', $id );
		$active  = $row && 'active' === $row['status'] ? $row : null;
		$subtype = $post ? (string) $post->post_type : (string) ( $entry['post_type'] ?? '' );

		// Removed: trashed, deleted or unpublished (and not republished in the same request).
		$published_now = $post && 'publish' === $post->post_status;
		if ( ! $published_now && ( ! empty( $entry['deleted'] ) || ! empty( $entry['unpublished'] ) || ! $post ) ) {
			$url = (string) ( $entry['removed_url'] ?? '' );
			if ( '' === $url && $active ) {
				$url = (string) $active['url'];
			}
			if ( '' === $url && ! empty( $entry['before']['url'] ) ) {
				$url = (string) $entry['before']['url'];
			}
			if ( '' === $url && $post ) {
				$url = $this->published_permalink( $post );
			}
			if ( '' === $url ) {
				return array();
			}
			$event = ! empty( $entry['deleted'] ) || ! $post || 'trash' === $post->post_status ? 'delete' : 'unpublish';
			$urls->mark_removed_for_object( 'post', $id );
			$existing = $urls->get_by_url( $url );
			if ( $existing ) {
				$urls->update(
					(int) $existing['id'],
					array(
						'status'              => 'removed',
						'indexable'           => 0,
						'indexability_reason' => $post ? 'not_published' : 'missing',
					)
				);
			}
			$was_indexable = $row ? (bool) $row['indexable'] : true;
			$change        = $this->fire( $event, $url, '', 'post', $id, $subtype, true );
			if ( $change && $was_indexable && $this->settings->get( 'indexing.notify_delete', true ) ) {
				$this->enqueue( $url, 'delete', $id, $event );
			}
			return $change ? array( $change ) : array();
		}

		if ( 'publish' !== $post->post_status ) {
			return array();
		}

		$new_url      = (string) get_permalink( $post );
		$indexability = $this->content_types()->post_indexability( $post );
		$indexable    = (bool) $indexability['indexable'];
		$was_known    = null !== $row;
		$was_index    = $row ? (bool) $row['indexable'] : null;
		$before       = (array) ( $entry['before'] ?? array() );

		// Content fingerprint (only computed when the text may have changed).
		$old_hash        = $row && 16 === strlen( (string) $row['content_hash'] ) ? (string) $row['content_hash'] : '';
		$content_changed = isset( $before['content'] ) && (string) $before['content'] !== (string) $post->post_content;
		$new_hash        = $old_hash;
		if ( '' === $old_hash || $content_changed || ! empty( $entry['published'] ) ) {
			$new_hash = SimHash::of( Text::strip( self::renderable( (string) $post->post_content ) ) );
			if ( '' === $old_hash && $content_changed ) {
				$old_hash = SimHash::of( Text::strip( self::renderable( (string) $before['content'] ) ) );
			}
		}
		$distance = ( '' !== $old_hash && '' !== $new_hash ) ? SimHash::distance( $old_hash, $new_hash ) : ( $content_changed ? 64 : 0 );

		$old_url = '';
		if ( $active && Url::normalize( $active['url'] ) !== Url::normalize( $new_url ) ) {
			$old_url = (string) $active['url'];
		} elseif ( ! empty( $before['url'] ) && Url::normalize( $before['url'] ) !== Url::normalize( $new_url ) ) {
			$old_url = (string) $before['url'];
		}

		// Registry row always reflects the final state.
		$this->save_row( $post, $new_url, $indexability, $new_hash );
		if ( '' !== $old_url ) {
			$urls->mark_removed_for_object( 'post', $id, $new_url );
		}

		$events = array();
		if ( ! empty( $entry['published'] ) && ( 'publish' !== ( $before['status'] ?? '' ) ) ) {
			$change = $this->fire( 'publish', $new_url, $old_url, 'post', $id, $subtype, true );
			if ( $change ) {
				$events[] = $change;
				if ( $indexable && $this->settings->get( 'indexing.notify_publish', true ) ) {
					$this->enqueue( $new_url, 'update', $id, 'publish' );
				}
				if ( '' !== $old_url && ( null === $was_index || $was_index ) && $this->settings->get( 'indexing.notify_update', true ) ) {
					$this->enqueue_old_url( $old_url, $id );
				}
			}
			return $events;
		}

		if ( '' !== $old_url ) {
			$change = $this->fire( 'redirect', $new_url, $old_url, 'post', $id, $subtype, true );
			if ( $change ) {
				$events[] = $change;
				if ( $this->settings->get( 'indexing.notify_update', true ) ) {
					if ( $indexable ) {
						$this->enqueue( $new_url, 'update', $id, 'redirect' );
					}
					if ( null === $was_index || $was_index ) {
						$this->enqueue_old_url( $old_url, $id );
					}
				}
				$events = array_merge( $events, $this->descendants_moved( $post ) );
			}
			return $events;
		}

		if ( $was_known && true === $was_index && ! $indexable ) {
			$change = $this->fire( 'noindex', $new_url, '', 'post', $id, $subtype, true );
			if ( $change ) {
				$events[] = $change;
				if ( $this->settings->get( 'indexing.notify_delete', true ) ) {
					$this->enqueue( $new_url, 'delete', $id, 'noindex' );
				}
			}
			return $events;
		}
		if ( $was_known && false === $was_index && $indexable ) {
			$change = $this->fire( 'index', $new_url, '', 'post', $id, $subtype, true );
			if ( $change ) {
				$events[] = $change;
				if ( $this->settings->get( 'indexing.notify_publish', true ) ) {
					$this->enqueue( $new_url, 'update', $id, 'index' );
				}
			}
			return $events;
		}

		// Plain update: was anything changed at all, and was it significant?
		$fields_changed = array();
		$props          = array(
			'title'   => 'post_title',
			'name'    => 'post_name',
			'excerpt' => 'post_excerpt',
		);
		foreach ( $props as $key => $prop ) {
			if ( isset( $before[ $key ] ) && (string) $before[ $key ] !== (string) $post->$prop ) {
				$fields_changed[] = $key;
			}
		}
		$meta_changed = array_keys( array_intersect_key( (array) ( $entry['meta'] ?? array() ), array_flip( array( 'title', 'description' ) ) ) );
		if ( $meta_changed && isset( $entry['seo_before'] ) ) {
			// Only a change of what the page actually outputs counts (automatic fills
			// often store the very text that was already shown as the fallback).
			$now          = $this->seo_output( $post );
			$meta_changed = array_values(
				array_filter(
					$meta_changed,
					static function ( $field ) use ( $now, $entry ) {
						return (string) $now[ $field ] !== (string) ( $entry['seo_before'][ $field ] ?? '' );
					}
				)
			);
		}
		$threshold    = max( 1, (int) $this->settings->get( 'indexing.change_threshold', 3 ) );
		$text_changed = $content_changed && $distance > 0;
		if ( empty( $fields_changed ) && empty( $entry['meta'] ) && ! $content_changed ) {
			// Nothing changed (e.g. the second save of Gutenberg's meta boxes).
			return array();
		}
		// SearchPilot's own content writes (e.g. inserted internal links) only count
		// when the text itself changed enough; so do editors' small text edits.
		$significant = ! empty( $fields_changed ) || ! empty( $meta_changed ) || ( $text_changed && $distance >= $threshold );
		$state  = $new_hash . '|' . $post->post_title . '|' . $post->post_name . '|' . md5( (string) $post->post_excerpt . wp_json_encode( $entry['meta'] ?? array() ) );
		$change = $this->fire( 'update', $new_url, '', 'post', $id, $subtype, $significant, $state );
		if ( $change ) {
			$events[] = $change;
			if ( $significant && $indexable && $this->settings->get( 'indexing.notify_update', true ) ) {
				$this->enqueue( $new_url, 'update', $id, 'update' );
			}
		}
		return $events;
	}

	/**
	 * Child pages whose URL changed with their parent (hierarchical types, up to 100).
	 *
	 * @param \WP_Post $post Parent.
	 * @return array[] Events.
	 */
	private function descendants_moved( \WP_Post $post ) {
		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return array();
		}
		$children = get_posts(
			array(
				'post_type'        => $post->post_type,
				'post_status'      => 'publish',
				'post_parent'      => (int) $post->ID,
				'numberposts'      => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$events = array();
		foreach ( (array) $children as $child_id ) {
			$child = get_post( (int) $child_id );
			$row   = $child ? $this->urls()->get_by_object( 'post', (int) $child_id ) : null;
			if ( ! $child || ! $row || 'active' !== $row['status'] ) {
				continue;
			}
			$new = (string) get_permalink( $child );
			if ( Url::normalize( $row['url'] ) === Url::normalize( $new ) ) {
				continue;
			}
			$indexability = $this->content_types()->post_indexability( $child );
			$this->save_row( $child, $new, $indexability, (string) $row['content_hash'] );
			$this->urls()->mark_removed_for_object( 'post', (int) $child_id, $new );
			$change = $this->fire( 'redirect', $new, (string) $row['url'], 'post', (int) $child_id, (string) $child->post_type, true );
			if ( ! $change ) {
				continue;
			}
			$events[] = $change;
			if ( $this->settings->get( 'indexing.notify_update', true ) ) {
				if ( $indexability['indexable'] ) {
					$this->enqueue( $new, 'update', (int) $child_id, 'redirect' );
				}
				if ( $row['indexable'] ) {
					$this->enqueue_old_url( (string) $row['url'], (int) $child_id );
				}
			}
			$events = array_merge( $events, $this->descendants_moved( $child ) );
		}
		return $events;
	}

	/**
	 * Upserts the registry row of a published post.
	 *
	 * @param \WP_Post $post         Post.
	 * @param string   $url          Current URL.
	 * @param array    $indexability {indexable, reason}.
	 * @param string   $hash         SimHash (16 hex) or ''.
	 */
	private function save_row( \WP_Post $post, $url, array $indexability, $hash ) {
		$fields = array(
			'object_type'         => 'post',
			'object_subtype'      => (string) $post->post_type,
			'object_id'           => (int) $post->ID,
			'status'              => 'active',
			'indexable'           => (bool) $indexability['indexable'],
			'indexability_reason' => (string) $indexability['reason'],
			'last_modified'       => '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : current_time( 'mysql', true ),
		);
		if ( 16 === strlen( (string) $hash ) ) {
			$fields['content_hash'] = $hash;
		}
		$this->urls()->upsert( $url, $fields );
	}

	/**
	 * URL a post has when published (for posts that were just unpublished).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function published_permalink( \WP_Post $post ) {
		$clone              = clone $post;
		$clone->post_status = 'publish';
		$clone->post_name   = preg_replace( '/__trashed$/', '', (string) $clone->post_name );
		return (string) get_permalink( $clone );
	}

	/**
	 * Post content as the reader sees it (dynamic blocks rendered is too
	 * expensive here; blocks' comment delimiters are simply dropped).
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private static function renderable( $content ) {
		return (string) preg_replace( '/<!--\s*\/?wp:[^>]*-->/', ' ', $content );
	}

	/* ------------------------------------------------------------------
	 * Terms (only when indexing.notify_terms is on)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a taxonomy is tracked.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private function tracks_taxonomy( $taxonomy ) {
		if ( ! $this->settings->get( 'indexing.notify_terms', false ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return false;
		}
		return array_key_exists( (string) $taxonomy, $this->content_types()->taxonomies() );
	}

	/**
	 * Term link or ''.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function term_url( $term_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * New term.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_created( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		$url = self::term_url( $term_id, $taxonomy );
		if ( '' === $url ) {
			return;
		}
		$indexability = $this->content_types()->term_indexability( (int) $term_id );
		$this->save_term_row( (int) $term_id, (string) $taxonomy, $url, $indexability );
		if ( $this->fire( 'term_create', $url, '', 'term', (int) $term_id, (string) $taxonomy, true ) && $indexability['indexable'] && $this->settings->get( 'indexing.notify_publish', true ) ) {
			$this->enqueue( $url, 'update', (int) $term_id, 'publish', 'term' );
		}
	}

	/**
	 * Captures a term before it is edited.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_before_edit( $term_id, $taxonomy ) {
		if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$this->term_before[ (int) $term_id ] = array(
			'url'         => self::term_url( $term_id, $taxonomy ),
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
			'indexable'   => (bool) $this->content_types()->term_indexability( $term )['indexable'],
		);
	}

	/**
	 * Edited term: slug/parent change → redirect, name/description change → update.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_edited( $term_id, $tt_id, $taxonomy ) {
		if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		clean_term_cache( (int) $term_id, (string) $taxonomy );
		$term   = get_term( (int) $term_id, (string) $taxonomy );
		$before = $this->term_before[ (int) $term_id ] ?? array();
		unset( $this->term_before[ (int) $term_id ] );
		$url = self::term_url( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || '' === $url ) {
			return;
		}
		$indexability = $this->content_types()->term_indexability( $term );
		$this->save_term_row( (int) $term_id, (string) $taxonomy, $url, $indexability );
		$old = (string) ( $before['url'] ?? '' );
		if ( '' !== $old && Url::normalize( $old ) !== Url::normalize( $url ) ) {
			$this->urls()->mark_removed_for_object( 'term', (int) $term_id, $url );
			if ( $this->fire( 'redirect', $url, $old, 'term', (int) $term_id, (string) $taxonomy, true ) && $this->settings->get( 'indexing.notify_update', true ) ) {
				if ( $indexability['indexable'] ) {
					$this->enqueue( $url, 'update', (int) $term_id, 'redirect', 'term' );
				}
				if ( ! empty( $before['indexable'] ) ) {
					$this->enqueue( $old, 'update', (int) $term_id, 'redirect', 'term' );
				}
			}
			return;
		}
		$significant = ( $before['name'] ?? $term->name ) !== $term->name || ( $before['description'] ?? $term->description ) !== $term->description;
		if ( $this->fire( 'term_update', $url, '', 'term', (int) $term_id, (string) $taxonomy, $significant ) && $significant && $indexability['indexable'] && $this->settings->get( 'indexing.notify_update', true ) ) {
			$this->enqueue( $url, 'update', (int) $term_id, 'update', 'term' );
		}
	}

	/**
	 * Captures a term URL before deletion.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_before_delete( $term_id, $taxonomy ) {
		if ( ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		$term = get_term( (int) $term_id, (string) $taxonomy );
		$this->term_before[ (int) $term_id ] = array(
			'url'       => self::term_url( $term_id, $taxonomy ),
			'indexable' => $term && ! is_wp_error( $term ) ? (bool) $this->content_types()->term_indexability( $term )['indexable'] : false,
		);
	}

	/**
	 * Deleted term.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_term_deleted( $term_id, $tt_id, $taxonomy ) {
		$before = $this->term_before[ (int) $term_id ] ?? array();
		unset( $this->term_before[ (int) $term_id ] );
		if ( empty( $before['url'] ) || ! $this->tracks_taxonomy( $taxonomy ) ) {
			return;
		}
		$this->urls()->mark_removed_for_object( 'term', (int) $term_id );
		if ( $this->fire( 'term_delete', (string) $before['url'], '', 'term', (int) $term_id, (string) $taxonomy, true ) && ! empty( $before['indexable'] ) && $this->settings->get( 'indexing.notify_delete', true ) ) {
			$this->enqueue( (string) $before['url'], 'delete', (int) $term_id, 'delete', 'term' );
		}
	}

	/**
	 * Registry row of a term.
	 *
	 * @param int    $term_id      Term id.
	 * @param string $taxonomy     Taxonomy.
	 * @param string $url          URL.
	 * @param array  $indexability {indexable, reason}.
	 */
	private function save_term_row( $term_id, $taxonomy, $url, array $indexability ) {
		$this->urls()->upsert(
			$url,
			array(
				'object_type'         => 'term',
				'object_subtype'      => $taxonomy,
				'object_id'           => $term_id,
				'status'              => 'active',
				'indexable'           => (bool) $indexability['indexable'],
				'indexability_reason' => (string) $indexability['reason'],
				'last_modified'       => current_time( 'mysql', true ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	/**
	 * Fires searchpilot_url_changed once per distinct event in this request.
	 *
	 * @param string $event       Event.
	 * @param string $url         URL.
	 * @param string $old_url     Previous URL.
	 * @param string $object_type post|term.
	 * @param int    $object_id   Object id.
	 * @param string $subtype     Post type or taxonomy.
	 * @param bool   $significant Significant.
	 * @param string $state       Fingerprint of the object's state (identical states fire once).
	 * @return array|null The change, or null when already fired.
	 */
	private function fire( $event, $url, $old_url, $object_type, $object_id, $subtype, $significant, $state = '' ) {
		$signature = md5( $event . '|' . Url::normalize( $url ) . '|' . Url::normalize( $old_url ) . '|' . $object_type . '|' . $object_id . '|' . (int) $significant . '|' . $state );
		if ( isset( $this->fired[ $signature ] ) ) {
			return null;
		}
		$this->fired[ $signature ] = true;
		$change                    = array(
			'event'       => $event,
			'url'         => (string) $url,
			'old_url'     => (string) $old_url,
			'object_type' => $object_type,
			'object_id'   => (int) $object_id,
			'subtype'     => (string) $subtype,
			'significant' => (bool) $significant,
		);
		/**
		 * Fires for every meaningful URL event.
		 *
		 * @param array $change { event, url, old_url, object_type, object_id, subtype, significant }.
		 */
		do_action( 'searchpilot_url_changed', $change );
		return $change;
	}

	/**
	 * Queues a URL.
	 *
	 * @param string $url         URL.
	 * @param string $action      update|delete.
	 * @param int    $object_id   Object id.
	 * @param string $reason      Reason.
	 * @param string $object_type Object type.
	 */
	private function enqueue( $url, $action, $object_id, $reason, $object_type = 'post' ) {
		$this->queue()->enqueue_url(
			$url,
			$action,
			array(
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
				'reason'      => $reason,
				// The URL's state changed materially: the resubmit cool-down does not apply.
				'force'       => in_array( $reason, array( 'redirect', 'index', 'noindex', 'delete', 'unpublish' ), true ),
			)
		);
	}

	/**
	 * Old URL of a moved post: IndexNow/Bing recrawl it (and see the redirect),
	 * the Indexing API receives URL_DELETED.
	 *
	 * @param string $old_url   Old URL.
	 * @param int    $object_id Post id.
	 */
	private function enqueue_old_url( $old_url, $object_id ) {
		$queue = $this->queue();
		$queue->enqueue_url(
			$old_url,
			'update',
			array(
				'object_type' => 'post',
				'object_id'   => (int) $object_id,
				'reason'      => 'redirect',
				'services'    => array( 'indexnow', 'bing_url' ),
				'force'       => true,
			)
		);
		$queue->enqueue_url(
			$old_url,
			'delete',
			array(
				'object_type' => 'post',
				'object_id'   => (int) $object_id,
				'reason'      => 'redirect',
				'services'    => array( 'google_indexing' ),
				'force'       => true,
			)
		);
	}

	/**
	 * URL registry.
	 *
	 * @return UrlRepository
	 */
	private function urls() {
		return $this->service( UrlRepository::class );
	}

	/**
	 * Content types.
	 *
	 * @return ContentTypes
	 */
	private function content_types() {
		return $this->service( ContentTypes::class );
	}

	/**
	 * Submission queue.
	 *
	 * @return SubmissionQueue
	 */
	private function queue() {
		return $this->service( SubmissionQueue::class );
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	private function logger() {
		return $this->service( Logger::class );
	}
}
