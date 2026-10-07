<?php
/**
 * Activity log with undo.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

use SearchPilot\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Every change SearchPilot makes to the website (meta, content, redirects,
 * settings) is recorded with enough data to undo it. Revert handlers are
 * registered by name; the built-in ones live in Changes.
 */
class ActivityLog {

	/**
	 * Revert handlers: name => callable( array $before, array $after, object $row ) : true|\WP_Error.
	 *
	 * @var array<string,callable>
	 */
	private $handlers = array();

	/**
	 * Registers a revert handler.
	 *
	 * @param string   $name    Handler name.
	 * @param callable $handler Callback.
	 */
	public function register_handler( $name, callable $handler ) {
		$this->handlers[ $name ] = $handler;
	}

	/**
	 * Whether a handler exists.
	 *
	 * @param string $name Handler name.
	 * @return bool
	 */
	public function has_handler( $name ) {
		$this->ensure_handlers();
		return isset( $this->handlers[ $name ] );
	}

	/**
	 * Records an entry.
	 *
	 * @param array $args {
	 *     @type string $action      Machine action, e.g. meta_updated, link_inserted, redirect_created.
	 *     @type string $description Human description.
	 *     @type string $object_type post|term|attachment|redirect|option|settings|site.
	 *     @type int    $object_id   Object id.
	 *     @type string $handler     Revert handler name ('' = not reversible).
	 *     @type array  $before      Data needed to revert.
	 *     @type array  $after       Data describing the new state.
	 *     @type string $source      user|manual|assisted|autopilot|system|import.
	 *     @type string $group_id    Groups bulk operations for one-click undo.
	 *     @type int    $user_id     Acting user (default current user).
	 * }
	 * @return int Activity id (0 on failure).
	 */
	public function record( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'action'      => 'change',
				'description' => '',
				'object_type' => '',
				'object_id'   => 0,
				'handler'     => '',
				'before'      => null,
				'after'       => null,
				'source'      => 'user',
				'group_id'    => '',
				'user_id'     => get_current_user_id(),
			)
		);
		$row  = array(
			'user_id'     => (int) $args['user_id'],
			'source'      => substr( sanitize_key( $args['source'] ), 0, 20 ),
			'action'      => substr( sanitize_key( $args['action'] ), 0, 64 ),
			'object_type' => substr( sanitize_key( $args['object_type'] ), 0, 20 ),
			'object_id'   => (int) $args['object_id'],
			'description' => wp_strip_all_tags( (string) $args['description'] ),
			'handler'     => substr( sanitize_key( $args['handler'] ), 0, 40 ),
			'before_data' => null === $args['before'] ? null : wp_json_encode( $args['before'] ),
			'after_data'  => null === $args['after'] ? null : wp_json_encode( $args['after'] ),
			'reversible'  => '' !== $args['handler'] ? 1 : 0,
			'status'      => 'applied',
			'group_id'    => substr( (string) $args['group_id'], 0, 40 ),
			'created_at'  => current_time( 'mysql', true ),
		);
		$ok   = $wpdb->insert( Tables::activity(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$id   = $ok ? (int) $wpdb->insert_id : 0;

		/**
		 * Fires after an activity entry is recorded.
		 *
		 * @param int   $id  Activity id.
		 * @param array $row Row.
		 */
		do_action( 'searchpilot_activity_recorded', $id, $row );
		return $id;
	}

	/**
	 * Fetches an entry.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::activity();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		return $row ?: null;
	}

	/**
	 * Lists entries.
	 *
	 * @param array $filters page, per_page, source, action, object_type, object_id, reversible, search, group_id.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::activity();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );

		foreach ( array( 'source', 'action', 'object_type', 'group_id', 'status' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[]  = "{$field} = %s";
				$params[] = (string) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $filters['object_id'];
		}
		if ( isset( $filters['reversible'] ) && '' !== $filters['reversible'] ) {
			$where[]  = 'reversible = %d';
			$params[] = (int) (bool) $filters['reversible'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'description LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );
		$items     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, source, action, object_type, object_id, description, handler, reversible, status, group_id, reverted_at, reverted_by, created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			)
		);
		// phpcs:enable

		return array(
			'items' => array_map( array( $this, 'present' ), (array) $items ),
			'total' => $total,
		);
	}

	/**
	 * Formats a row for the API.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	public function present( $row ) {
		$user = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
		return array(
			'id'          => (int) $row->id,
			'source'      => $row->source,
			'action'      => $row->action,
			'object_type' => $row->object_type,
			'object_id'   => (int) $row->object_id,
			'object_link' => $this->object_link( $row->object_type, (int) $row->object_id ),
			'description' => $row->description,
			'reversible'  => (bool) $row->reversible && 'applied' === $row->status,
			'status'      => $row->status,
			'group_id'    => $row->group_id,
			'user'        => $user ? $user->display_name : ( 'user' === $row->source ? '' : __( 'SearchPilot', 'searchpilot-pro' ) ),
			'created_at'  => mysql_to_rfc3339( $row->created_at ),
			'reverted_at' => $row->reverted_at ? mysql_to_rfc3339( $row->reverted_at ) : null,
		);
	}

	/**
	 * Edit link for an object.
	 *
	 * @param string $type Type.
	 * @param int    $id   Id.
	 * @return string
	 */
	private function object_link( $type, $id ) {
		if ( ! $id ) {
			return '';
		}
		if ( in_array( $type, array( 'post', 'attachment' ), true ) ) {
			return (string) get_edit_post_link( $id, 'raw' );
		}
		if ( 'term' === $type ) {
			$term = get_term( $id );
			return $term && ! is_wp_error( $term ) ? (string) get_edit_term_link( $term ) : '';
		}
		return '';
	}

	/**
	 * Undoes an entry.
	 *
	 * @param int  $id    Activity id.
	 * @param bool $force Revert even when the object changed since.
	 * @return true|\WP_Error
	 */
	public function undo( $id, $force = false ) {
		global $wpdb;
		$this->ensure_handlers();
		$row = $this->get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'Activity entry not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		if ( ! $row->reversible || 'applied' !== $row->status ) {
			return new \WP_Error( 'searchpilot_not_reversible', __( 'This change cannot be undone (or was already undone).', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( empty( $this->handlers[ $row->handler ] ) ) {
			return new \WP_Error( 'searchpilot_no_handler', __( 'The feature that made this change is not active, so it cannot be undone now.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$before = json_decode( (string) $row->before_data, true );
		$after  = json_decode( (string) $row->after_data, true );
		$result = call_user_func( $this->handlers[ $row->handler ], is_array( $before ) ? $before : array(), is_array( $after ) ? $after : array(), $row, (bool) $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::activity(),
			array(
				'status'      => 'reverted',
				'reverted_at' => current_time( 'mysql', true ),
				'reverted_by' => get_current_user_id(),
			),
			array( 'id' => (int) $row->id )
		);
		$this->record(
			array(
				'action'      => 'undo',
				/* translators: %s: original change description. */
				'description' => sprintf( __( 'Undid: %s', 'searchpilot-pro' ), $row->description ),
				'object_type' => $row->object_type,
				'object_id'   => (int) $row->object_id,
				'source'      => 'user',
			)
		);
		/**
		 * Fires after a change was undone.
		 *
		 * @param object $row Activity row.
		 */
		do_action( 'searchpilot_activity_undone', $row );
		return true;
	}

	/**
	 * Undoes every reversible entry of a group (newest first).
	 *
	 * @param string $group_id Group id.
	 * @return array{undone:int,failed:int,errors:string[]}
	 */
	public function undo_group( $group_id ) {
		global $wpdb;
		// An empty id is not a group: it would match every ungrouped entry of the site.
		if ( '' === trim( (string) $group_id ) ) {
			return array(
				'undone' => 0,
				'failed' => 0,
				'errors' => array(),
			);
		}
		$table  = Tables::activity();
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE group_id = %s AND reversible = 1 AND status = 'applied' ORDER BY id DESC", (string) $group_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$result = array(
			'undone' => 0,
			'failed' => 0,
			'errors' => array(),
		);
		foreach ( (array) $ids as $id ) {
			$r = $this->undo( (int) $id );
			if ( is_wp_error( $r ) ) {
				++$result['failed'];
				$result['errors'][] = $r->get_error_message();
			} else {
				++$result['undone'];
			}
		}
		return $result;
	}

	/**
	 * Deletes entries older than the retention window (keeps reversible recent ones).
	 */
	public function prune() {
		global $wpdb;
		$days  = (int) \SearchPilot\Plugin::instance()->get( Settings::class )->get( 'general.history_retention_days', 90 );
		$table = Tables::activity();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Makes sure built-in and module handlers are registered.
	 */
	private function ensure_handlers() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		Changes::register_handlers( $this );
		/**
		 * Register revert handlers: $log->register_handler( 'name', callable ).
		 *
		 * @param ActivityLog $log Activity log.
		 */
		do_action( 'searchpilot_register_revert_handlers', $this );
	}

	/**
	 * New group id for bulk operations.
	 *
	 * @return string
	 */
	public static function new_group_id() {
		return 'g' . str_replace( '-', '', wp_generate_uuid4() );
	}
}
