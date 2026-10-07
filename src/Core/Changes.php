<?php
/**
 * Reversible website changes.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The only way modules should change the website. Each method applies the
 * change, records it in the activity log with before/after data and
 * returns the activity id, so every plugin-made change can be undone.
 *
 * Undo refuses to clobber later edits: if the value changed again after
 * SearchPilot's change, the revert fails with `searchpilot_changed_since`
 * unless forced.
 */
class Changes {

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param ActivityLog $log Activity log.
	 */
	public function __construct( ActivityLog $log ) {
		$this->log = $log;
	}

	/**
	 * Registers built-in revert handlers.
	 *
	 * @param ActivityLog $log Log.
	 */
	public static function register_handlers( ActivityLog $log ) {
		$log->register_handler( 'post_meta', array( __CLASS__, 'revert_post_meta' ) );
		$log->register_handler( 'term_meta', array( __CLASS__, 'revert_term_meta' ) );
		$log->register_handler( 'post_content', array( __CLASS__, 'revert_post_content' ) );
		$log->register_handler( 'option', array( __CLASS__, 'revert_option' ) );
		$log->register_handler( 'settings', array( __CLASS__, 'revert_settings' ) );
		$log->register_handler( 'callback', array( __CLASS__, 'revert_callback' ) );
	}

	/**
	 * Updates (or deletes, when $value is null) post meta.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   New value (null deletes).
	 * @param array  $opts    description, source, action, group_id, object_type.
	 * @return int|\WP_Error Activity id.
	 */
	public function update_post_meta( $post_id, $key, $value, array $opts = array() ) {
		$post_id = (int) $post_id;
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		$exists = metadata_exists( 'post', $post_id, $key );
		$before = array(
			'post_id' => $post_id,
			'key'     => $key,
			'exists'  => $exists,
			'value'   => $exists ? get_post_meta( $post_id, $key, true ) : null,
		);
		if ( $exists && null !== $value && $before['value'] === $value ) {
			return 0; // Nothing to change.
		}
		if ( null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
		}
		return $this->log->record(
			array(
				'action'      => $opts['action'] ?? 'meta_updated',
				/* translators: 1: meta field, 2: post title. */
				'description' => $opts['description'] ?? sprintf( __( 'Updated %1$s on “%2$s”', 'searchpilot-pro' ), $key, get_the_title( $post_id ) ),
				'object_type' => $opts['object_type'] ?? ( 'attachment' === get_post_type( $post_id ) ? 'attachment' : 'post' ),
				'object_id'   => $post_id,
				'handler'     => 'post_meta',
				'before'      => $before,
				'after'       => array(
					'post_id' => $post_id,
					'key'     => $key,
					'value'   => $value,
				),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
	}

	/**
	 * Updates (or deletes) term meta.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   New value (null deletes).
	 * @param array  $opts    description, source, action, group_id.
	 * @return int|\WP_Error Activity id.
	 */
	public function update_term_meta( $term_id, $key, $value, array $opts = array() ) {
		$term_id = (int) $term_id;
		$exists  = metadata_exists( 'term', $term_id, $key );
		$before  = array(
			'term_id' => $term_id,
			'key'     => $key,
			'exists'  => $exists,
			'value'   => $exists ? get_term_meta( $term_id, $key, true ) : null,
		);
		if ( $exists && null !== $value && $before['value'] === $value ) {
			return 0;
		}
		if ( null === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
		}
		return $this->log->record(
			array(
				'action'      => $opts['action'] ?? 'term_meta_updated',
				/* translators: %s: meta field. */
				'description' => $opts['description'] ?? sprintf( __( 'Updated term field %s', 'searchpilot-pro' ), $key ),
				'object_type' => 'term',
				'object_id'   => $term_id,
				'handler'     => 'term_meta',
				'before'      => $before,
				'after'       => array(
					'term_id' => $term_id,
					'key'     => $key,
					'value'   => $value,
				),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
	}

	/**
	 * Replaces post content (e.g. inserting internal links).
	 *
	 * @param int    $post_id     Post id.
	 * @param string $new_content New raw content (unslashed).
	 * @param array  $opts {
	 *     @type string $description       Description.
	 *     @type string $source            Source.
	 *     @type string $action            Action.
	 *     @type string $group_id          Group.
	 *     @type string $marker            Attribute marking inserted markup (e.g. data-spp-link="12"),
	 *                                     used to undo surgically when the post was edited since.
	 *     @type bool   $preserve_modified Keep post_modified (default true: link edits are not "fresh content").
	 * }
	 * @return int|\WP_Error Activity id.
	 */
	public function update_post_content( $post_id, $new_content, array $opts = array() ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		$old = (string) $post->post_content;
		if ( $old === $new_content ) {
			return 0;
		}
		$result = self::write_content( $post, (string) $new_content, $opts['preserve_modified'] ?? true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->log->record(
			array(
				'action'      => $opts['action'] ?? 'content_updated',
				/* translators: %s: post title. */
				'description' => $opts['description'] ?? sprintf( __( 'Updated the content of “%s”', 'searchpilot-pro' ), get_the_title( $post ) ),
				'object_type' => 'post',
				'object_id'   => (int) $post->ID,
				'handler'     => 'post_content',
				'before'      => array(
					'post_id' => (int) $post->ID,
					'content' => $old,
					'hash'    => md5( $old ),
				),
				'after'       => array(
					'post_id' => (int) $post->ID,
					'hash'    => md5( (string) $new_content ),
					'marker'  => (string) ( $opts['marker'] ?? '' ),
				),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
	}

	/**
	 * Updates an option.
	 *
	 * @param string $name  Option.
	 * @param mixed  $value Value (null deletes).
	 * @param array  $opts  description, source, action, group_id, autoload.
	 * @return int|\WP_Error
	 */
	public function update_option( $name, $value, array $opts = array() ) {
		$missing = new \stdClass();
		$current = get_option( $name, $missing );
		$exists  = $current !== $missing;
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, $opts['autoload'] ?? null );
		}
		return $this->log->record(
			array(
				'action'      => $opts['action'] ?? 'option_updated',
				/* translators: %s: option name. */
				'description' => $opts['description'] ?? sprintf( __( 'Changed the %s setting', 'searchpilot-pro' ), $name ),
				'object_type' => 'option',
				'handler'     => 'option',
				'before'      => array(
					'name'   => $name,
					'exists' => $exists,
					'value'  => $exists ? $current : null,
				),
				'after'       => array(
					'name'  => $name,
					'value' => $value,
				),
				'source'      => $opts['source'] ?? 'user',
				'group_id'    => $opts['group_id'] ?? '',
			)
		);
	}

	/**
	 * Records a change whose revert is handled by a named handler
	 * registered through `searchpilot_register_revert_handlers`.
	 *
	 * @param string $handler Handler name.
	 * @param array  $args    ActivityLog::record() args (without handler).
	 * @return int
	 */
	public function record( $handler, array $args ) {
		$args['handler'] = $handler;
		return $this->log->record( $args );
	}

	/**
	 * Writes post content, optionally keeping the modified date.
	 *
	 * @param \WP_Post $post              Post.
	 * @param string   $content           Content.
	 * @param bool     $preserve_modified Keep post_modified.
	 * @return true|\WP_Error
	 */
	private static function write_content( \WP_Post $post, $content, $preserve_modified ) {
		$keep = null;
		if ( $preserve_modified ) {
			$keep = static function ( $data, $postarr ) use ( $post ) {
				if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post->ID ) {
					$data['post_modified']     = $post->post_modified;
					$data['post_modified_gmt'] = $post->post_modified_gmt;
				}
				return $data;
			};
			add_filter( 'wp_insert_post_data', $keep, 99, 2 );
		}
		// The content was already filtered when its author saved it, and SearchPilot only
		// adds or removes its own escaped markup. Without this, background runs (no user,
		// so no unfiltered_html) would let kses strip embeds and iframes the author added.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		// SearchPilot only changes markup (links, heading levels, ALT, its own blocks) of a post that
		// is already published. Re-running the publish chain would schedule pingbacks and enclosure
		// checks for every link (on sites with XML-RPC on, self-pingbacks become comments awaiting
		// moderation) and store a revision per write; the activity log already holds the undo.
		$quiet = array(
			array( 'publish_post', '_publish_post_hook' ),
			array( 'post_updated', 'wp_save_post_revision' ),
			array( 'wp_after_insert_post', 'wp_save_post_revision_on_insert' ),
		);
		$removed = array();
		foreach ( $quiet as $hook ) {
			$priority = has_action( $hook[0], $hook[1] );
			if ( false !== $priority ) {
				remove_action( $hook[0], $hook[1], $priority );
				$removed[] = array( $hook[0], $hook[1], $priority );
			}
		}
		$GLOBALS['searchpilot_internal_write'] = true;
		try {
			$result = wp_update_post(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => wp_slash( $content ),
				),
				true
			);
		} finally {
			unset( $GLOBALS['searchpilot_internal_write'] );
			if ( $kses ) {
				kses_init_filters();
			}
			foreach ( $removed as $hook ) {
				// Core registers these with the number of arguments they use.
				add_action( $hook[0], $hook[1], $hook[2], 'wp_save_post_revision_on_insert' === $hook[1] ? 3 : 1 );
			}
		}
		if ( $keep ) {
			remove_filter( 'wp_insert_post_data', $keep, 99 );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether the current save was made by SearchPilot itself (modules can
	 * skip reprocessing their own writes).
	 *
	 * @return bool
	 */
	public static function is_internal_write() {
		return ! empty( $GLOBALS['searchpilot_internal_write'] );
	}

	/**
	 * Revert: post meta.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_post_meta( array $before, array $after, $row, $force = false ) {
		$post_id = (int) ( $before['post_id'] ?? 0 );
		$key     = (string) ( $before['key'] ?? '' );
		if ( ! $post_id || '' === $key || ! get_post( $post_id ) ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		$current = metadata_exists( 'post', $post_id, $key ) ? get_post_meta( $post_id, $key, true ) : null;
		if ( ! $force && ! self::same( $current, $after['value'] ?? null ) ) {
			return self::changed_since();
		}
		if ( empty( $before['exists'] ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, is_string( $before['value'] ) ? wp_slash( $before['value'] ) : $before['value'] );
		}
		return true;
	}

	/**
	 * Revert: term meta.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_term_meta( array $before, array $after, $row, $force = false ) {
		$term_id = (int) ( $before['term_id'] ?? 0 );
		$key     = (string) ( $before['key'] ?? '' );
		if ( ! $term_id || '' === $key ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Nothing to undo.', 'searchpilot-pro' ) );
		}
		$current = metadata_exists( 'term', $term_id, $key ) ? get_term_meta( $term_id, $key, true ) : null;
		if ( ! $force && ! self::same( $current, $after['value'] ?? null ) ) {
			return self::changed_since();
		}
		if ( empty( $before['exists'] ) ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, is_string( $before['value'] ) ? wp_slash( $before['value'] ) : $before['value'] );
		}
		return true;
	}

	/**
	 * Revert: post content. Restores the old content when untouched since;
	 * otherwise removes only the markup carrying the marker attribute.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_post_content( array $before, array $after, $row, $force = false ) {
		$post = get_post( (int) ( $before['post_id'] ?? 0 ) );
		if ( ! $post ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		$current = (string) $post->post_content;
		if ( md5( $current ) === ( $after['hash'] ?? '' ) || $force ) {
			return self::write_content( $post, (string) ( $before['content'] ?? '' ), true );
		}
		$marker = (string) ( $after['marker'] ?? '' );
		if ( '' !== $marker && false !== strpos( $current, $marker ) ) {
			$cleaned = self::remove_marked_links( $current, $marker );
			if ( $cleaned !== $current ) {
				return self::write_content( $post, $cleaned, true );
			}
		}
		return self::changed_since();
	}

	/**
	 * Unwraps <a …marker…>text</a> back to text.
	 *
	 * @param string $content Content.
	 * @param string $marker  Marker attribute, e.g. data-spp-link="12".
	 * @return string
	 */
	public static function remove_marked_links( $content, $marker ) {
		$pattern = '#<a\b[^>]*' . preg_quote( $marker, '#' ) . '[^>]*>(.*?)</a>#is';
		return (string) preg_replace( $pattern, '$1', $content );
	}

	/**
	 * Revert: option.
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_option( array $before, array $after, $row, $force = false ) {
		$name = (string) ( $before['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Nothing to undo.', 'searchpilot-pro' ) );
		}
		if ( ! $force && ! self::same( get_option( $name, null ), $after['value'] ?? null ) ) {
			return self::changed_since();
		}
		if ( empty( $before['exists'] ) ) {
			delete_option( $name );
		} else {
			update_option( $name, $before['value'] );
		}
		return true;
	}

	/**
	 * Revert: SearchPilot settings (restores the previous values of changed keys).
	 *
	 * @param array  $before Before (dot key => value).
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_settings( array $before, array $after, $row, $force = false ) {
		$settings = \SearchPilot\Plugin::instance()->get( Settings::class );
		if ( ! $force ) {
			foreach ( $after as $key => $value ) {
				if ( ! self::same( $settings->get( (string) $key ), $value ) ) {
					return self::changed_since();
				}
			}
		}
		$result = $settings->update( $before );
		return empty( $result['errors'] ) ? true : new \WP_Error( 'searchpilot_invalid', implode( ' ', $result['errors'] ) );
	}

	/**
	 * Revert through a static callback named in before['callback'] (class::method).
	 *
	 * @param array  $before Before.
	 * @param array  $after  After.
	 * @param object $row    Row.
	 * @param bool   $force  Force.
	 * @return true|\WP_Error
	 */
	public static function revert_callback( array $before, array $after, $row, $force = false ) {
		$callback = (string) ( $before['callback'] ?? '' );
		if ( 0 !== strpos( $callback, 'SearchPilot\\' ) || ! is_callable( $callback ) ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Nothing to undo.', 'searchpilot-pro' ) );
		}
		return call_user_func( $callback, $before, $after, $row, $force );
	}

	/**
	 * Loose equality for stored values (meta round-trips ints as strings).
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return (string) $a === (string) $b;
		}
		if ( null === $a || null === $b ) {
			return ( null === $a || '' === $a ) && ( null === $b || '' === $b );
		}
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Error for a value edited after SearchPilot's change.
	 *
	 * @return \WP_Error
	 */
	private static function changed_since() {
		return new \WP_Error( 'searchpilot_changed_since', __( 'This was edited again after SearchPilot’s change, so it was not undone automatically. Review it manually (post revisions keep earlier versions).', 'searchpilot-pro' ), array( 'status' => 409 ) );
	}
}
