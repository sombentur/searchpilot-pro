<?php
/**
 * Issue storage.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Issues;

use SearchPilot\Database\Tables;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Stores detected issues and opportunities. One row per (type, object, url)
 * — re-detection updates the row instead of duplicating it. Statuses:
 * open, pending_approval, fixed, resolved (condition cleared), ignored.
 */
class IssueRepository {

	const OPEN_STATUSES = array( 'open', 'pending_approval' );

	const FIX_MEMORY = 'searchpilot_fix_memory';

	/**
	 * Fix memory loaded in this request.
	 *
	 * @var array|null
	 */
	private $memory = null;

	/**
	 * Registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function __construct( IssueRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Deterministic key.
	 *
	 * @param string $type        Type.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $url         URL.
	 * @return string
	 */
	public static function key( $type, $object_type = '', $object_id = 0, $url = '' ) {
		return sha1( $type . '|' . $object_type . '|' . (int) $object_id . '|' . ( '' !== $url ? Url::normalize( $url ) : '' ) );
	}

	/**
	 * Creates or refreshes an issue.
	 *
	 * @param string $type Type id (must be registered).
	 * @param array  $args {
	 *     @type string $object_type post|term|author|url|site|redirect|query.
	 *     @type int    $object_id   Object id.
	 *     @type string $url         URL.
	 *     @type string $severity    Override severity.
	 *     @type string $title       Row title (e.g. post title or URL).
	 *     @type array  $data        Details used by what/why/fix callables and fixers.
	 *     @type string $key_suffix  Extra discriminator when one object can have several issues of a type.
	 * }
	 * @return int Issue id.
	 */
	public function upsert( $type, array $args = array() ) {
		global $wpdb;
		$def         = $this->registry->get( $type );
		$object_type = (string) ( $args['object_type'] ?? '' );
		$object_id   = (int) ( $args['object_id'] ?? 0 );
		$url         = (string) ( $args['url'] ?? '' );
		$severity    = (string) ( $args['severity'] ?? ( $def['severity'] ?? 'medium' ) );
		$key         = self::key( $type . ( isset( $args['key_suffix'] ) ? '#' . $args['key_suffix'] : '' ), $object_type, $object_id, $url );
		$now         = current_time( 'mysql', true );
		$table       = Tables::issues();
		$data        = isset( $args['data'] ) ? wp_json_encode( $args['data'] ) : null;
		$title       = mb_substr( (string) ( $args['title'] ?? '' ), 0, 250 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE issue_key = %s", $key ) );
		if ( $existing ) {
			$update = array(
				'severity'      => $severity,
				'severity_rank' => IssueRegistry::rank( $severity ),
				'last_seen_at'  => $now,
				'updated_at'    => $now,
				'data'          => $data,
			);
			if ( '' !== $title ) {
				$update['title'] = $title;
			}
			$reopened = in_array( $existing->status, array( 'fixed', 'resolved' ), true );
			if ( $reopened ) {
				// Came back: reopen.
				$update['status']      = 'open';
				$update['resolved_at'] = null;
				$update['detected_at'] = $now;
			}
			$wpdb->update( $table, $update, array( 'id' => (int) $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET occurrences = occurrences + 1 WHERE id = %d", (int) $existing->id ) );
			if ( $reopened ) {
				/** This action is documented below (a problem that returned counts as detected again). */
				do_action( 'searchpilot_issue_detected', (int) $existing->id, $type, $severity, array_merge( $args, array( 'reopened' => true ) ) );
			}
			return (int) $existing->id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'issue_key'     => $key,
				'type'          => substr( $type, 0, 64 ),
				'category'      => (string) ( $def['category'] ?? 'technical' ),
				'severity'      => $severity,
				'severity_rank' => IssueRegistry::rank( $severity ),
				'status'        => 'open',
				'object_type'   => substr( $object_type, 0, 20 ),
				'object_id'     => $object_id,
				'url'           => '' !== $url ? $url : null,
				'title'         => $title,
				'data'          => $data,
				'occurrences'   => 1,
				'detected_at'   => $now,
				'last_seen_at'  => $now,
				'updated_at'    => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires when a new issue is detected.
		 *
		 * @param int    $id       Issue id.
		 * @param string $type     Type.
		 * @param string $severity Severity.
		 * @param array  $args     Args.
		 */
		do_action( 'searchpilot_issue_detected', $id, $type, $severity, $args );
		return $id;
	}

	/**
	 * Marks a specific issue as resolved (condition no longer present).
	 *
	 * @param string $type        Type.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $url         URL.
	 * @param string $key_suffix  Discriminator used at upsert.
	 * @return bool Whether an open issue was resolved.
	 */
	public function resolve( $type, $object_type = '', $object_id = 0, $url = '', $key_suffix = null ) {
		global $wpdb;
		$key   = self::key( $type . ( null !== $key_suffix ? '#' . $key_suffix : '' ), $object_type, $object_id, $url );
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s, updated_at = %s WHERE issue_key = %s AND status IN ('open','pending_approval')", current_time( 'mysql', true ), current_time( 'mysql', true ), $key ) );
		return $rows > 0;
	}

	/**
	 * Resolves open issues of the given types on one object that were not
	 * re-detected in the latest check of that object.
	 *
	 * @param string   $object_type Object type.
	 * @param int      $object_id   Object id.
	 * @param string[] $checked     Types that were checked.
	 * @param int[]    $keep_ids    Issue ids detected in this run.
	 * @return int Rows resolved.
	 */
	public function resolve_stale_for_object( $object_type, $object_id, array $checked, array $keep_ids ) {
		global $wpdb;
		if ( empty( $checked ) ) {
			return 0;
		}
		$table        = Tables::issues();
		$type_holders = implode( ',', array_fill( 0, count( $checked ), '%s' ) );
		$params       = array_merge( array( current_time( 'mysql', true ), current_time( 'mysql', true ), $object_type, (int) $object_id ), $checked );
		$keep_sql     = '';
		$keep_ids     = array_filter( array_map( 'intval', $keep_ids ) );
		if ( $keep_ids ) {
			$keep_sql = ' AND id NOT IN (' . implode( ',', $keep_ids ) . ')';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s, updated_at = %s WHERE object_type = %s AND object_id = %d AND type IN ({$type_holders}) AND status IN ('open','pending_approval'){$keep_sql}", $params ) );
	}

	/**
	 * Resolves every open issue of a type not seen since a timestamp
	 * (site-wide audits that re-check everything of that type).
	 *
	 * @param string $type  Type.
	 * @param string $since MySQL UTC datetime of the audit start.
	 * @return int
	 */
	public function resolve_unseen( $type, $since ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s, updated_at = %s WHERE type = %s AND last_seen_at < %s AND status IN ('open','pending_approval')", current_time( 'mysql', true ), current_time( 'mysql', true ), $type, $since ) );
	}

	/**
	 * Changes status.
	 *
	 * @param int    $id     Issue id.
	 * @param string $status Status.
	 * @return bool
	 */
	public function set_status( $id, $status ) {
		global $wpdb;
		$fields = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( in_array( $status, array( 'fixed', 'resolved' ), true ) ) {
			$fields['resolved_at'] = current_time( 'mysql', true );
		}
		return false !== $wpdb->update( Tables::issues(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges keys into an issue's data without touching occurrences or status.
	 *
	 * @param int   $id    Issue id.
	 * @param array $patch Keys to set (null values remove the key).
	 * @return bool
	 */
	public function update_data( $id, array $patch ) {
		global $wpdb;
		$issue = $this->get( $id );
		if ( ! $issue ) {
			return false;
		}
		$data = $issue['data'];
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $data[ $key ] );
			} else {
				$data[ $key ] = $value;
			}
		}
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::issues(),
			array(
				'data'       => wp_json_encode( $data ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Fetches an issue row (data decoded).
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Queries issues.
	 *
	 * @param array $filters status (string|array|'open'), severity, category, type, object_type, object_id, search, page, per_page, orderby (severity|detected|updated).
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::issues();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 500, max( 1, (int) ( $filters['per_page'] ?? 50 ) ) );

		$status = $filters['status'] ?? 'open';
		if ( 'open' === $status ) {
			$status = self::OPEN_STATUSES;
		}
		if ( 'any' !== $status && ! empty( $status ) ) {
			$status   = (array) $status;
			$where[]  = 'status IN (' . implode( ',', array_fill( 0, count( $status ), '%s' ) ) . ')';
			$params   = array_merge( $params, array_map( 'strval', $status ) );
		}
		foreach ( array( 'severity', 'category', 'type', 'object_type' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$values  = (array) $filters[ $field ];
				$where[] = "{$field} IN (" . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$params  = array_merge( $params, array_map( 'strval', $values ) );
			}
		}
		if ( ! empty( $filters['exclude_category'] ) ) {
			$values  = (array) $filters['exclude_category'];
			$where[] = 'category NOT IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $values ) );
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $filters['object_id'];
		}
		if ( ! empty( $filters['min_id'] ) ) {
			$where[]  = 'id > %d';
			$params[] = (int) $filters['min_id'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(title LIKE %s OR url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$order = array(
			'severity' => 'severity_rank ASC, occurrences DESC, id DESC',
			'detected' => 'detected_at DESC, id DESC',
			'updated'  => 'updated_at DESC, id DESC',
			'id'       => 'id ASC',
		)[ $filters['orderby'] ?? 'severity' ] ?? 'severity_rank ASC, id DESC';

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Open issue counts per severity (optionally per category).
	 *
	 * @param string|null $category Category filter.
	 * @param string[]    $exclude  Categories to exclude.
	 * @return array{critical:int,high:int,medium:int,opportunity:int,total:int,pending_approval:int}
	 */
	public function counts( $category = null, array $exclude = array() ) {
		global $wpdb;
		$table  = Tables::issues();
		$where  = "status IN ('open','pending_approval')";
		$params = array();
		if ( $category ) {
			$where   .= ' AND category = %s';
			$params[] = $category;
		}
		if ( $exclude ) {
			$where .= ' AND category NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')';
			$params = array_merge( $params, $exclude );
		}
		$sql = "SELECT severity, status, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY severity, status";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$out  = array(
			'critical'         => 0,
			'high'             => 0,
			'medium'           => 0,
			'opportunity'      => 0,
			'total'            => 0,
			'pending_approval' => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row->severity ] ) ) {
				$out[ $row->severity ] += (int) $row->n;
			}
			if ( 'pending_approval' === $row->status ) {
				$out['pending_approval'] += (int) $row->n;
			}
			$out['total'] += (int) $row->n;
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Fix memory: which fix was applied to an issue, and which the person
	 * declined (undid, or reverted by hand). Kept in a non-autoloaded option
	 * because issue `data` is rewritten by every scan.
	 * ------------------------------------------------------------------ */

	/**
	 * The fix memory.
	 *
	 * @return array{applied:array,activity:array,declined:array}
	 */
	public function fix_memory() {
		if ( null === $this->memory ) {
			$stored       = get_option( self::FIX_MEMORY, array() );
			$stored       = is_array( $stored ) ? $stored : array();
			$this->memory = array(
				'applied'  => (array) ( $stored['applied'] ?? array() ),
				'activity' => (array) ( $stored['activity'] ?? array() ),
				'declined' => (array) ( $stored['declined'] ?? array() ),
			);
		}
		return $this->memory;
	}

	/**
	 * Remembers a fix that was applied and kept.
	 *
	 * @param int    $issue_id     Issue id.
	 * @param string $fingerprint  Plan fingerprint ('' for legacy fixers).
	 * @param int[]  $activity_ids Activity entries written by the fix.
	 */
	public function remember_fix( $issue_id, $fingerprint, array $activity_ids ) {
		$this->reload_memory();
		$memory = $this->fix_memory();

		$memory['applied'][ (int) $issue_id ] = array(
			'fp' => (string) $fingerprint,
			'at' => time(),
		);
		foreach ( $activity_ids as $id ) {
			$memory['activity'][ (int) $id ] = (int) $issue_id;
		}
		unset( $memory['declined'][ (int) $issue_id ] );
		$this->save_memory( $memory );
	}

	/**
	 * Marks the fix of an issue as declined by a person: automatic fixing
	 * skips it until the fix the planner proposes changes (or, for legacy
	 * fixers, until the post is edited).
	 *
	 * @param int $issue_id Issue id.
	 */
	public function decline_fix( $issue_id ) {
		$this->reload_memory();
		$issue_id = (int) $issue_id;
		$memory   = $this->fix_memory();
		$issue    = $this->get( $issue_id );
		$modified = '';
		if ( $issue && in_array( $issue['object_type'], array( 'post', 'attachment' ), true ) && $issue['object_id'] ) {
			$post     = get_post( (int) $issue['object_id'] );
			$modified = $post ? (string) $post->post_modified_gmt : '';
		}
		$memory['declined'][ $issue_id ] = array(
			'fp'       => (string) ( $memory['applied'][ $issue_id ]['fp'] ?? '' ),
			'modified' => $modified,
			'at'       => time(),
		);
		unset( $memory['applied'][ $issue_id ] );
		$this->save_memory( $memory );
	}

	/**
	 * Forgets a declined fix (the person applied it again themselves).
	 *
	 * @param int $issue_id Issue id.
	 */
	public function clear_declined( $issue_id ) {
		$this->reload_memory();
		$memory = $this->fix_memory();
		if ( isset( $memory['declined'][ (int) $issue_id ] ) ) {
			unset( $memory['declined'][ (int) $issue_id ] );
			$this->save_memory( $memory );
		}
	}

	/**
	 * Declined-fix record of an issue, or null.
	 *
	 * @param int $issue_id Issue id.
	 * @return array{fp:string,modified:string,at:int}|null
	 */
	public function declined_fix( $issue_id ) {
		$memory = $this->fix_memory();
		return isset( $memory['declined'][ (int) $issue_id ] ) ? (array) $memory['declined'][ (int) $issue_id ] : null;
	}

	/**
	 * Fingerprint of the fix last applied to an issue ('' when none).
	 *
	 * @param int $issue_id Issue id.
	 * @return string|null Null when no fix was remembered.
	 */
	public function applied_fix( $issue_id ) {
		$memory = $this->fix_memory();
		return isset( $memory['applied'][ (int) $issue_id ] ) ? (string) $memory['applied'][ (int) $issue_id ]['fp'] : null;
	}

	/**
	 * Issue fixed by an activity entry (0 when unknown).
	 *
	 * @param int $activity_id Activity id.
	 * @return int
	 */
	public function issue_for_activity( $activity_id ) {
		$memory = $this->fix_memory();
		return (int) ( $memory['activity'][ (int) $activity_id ] ?? 0 );
	}

	/**
	 * Whether automatic fixing must skip an issue the person declined.
	 *
	 * @param array      $issue       Issue.
	 * @param string|null $fingerprint Fingerprint of the fix proposed now (null for legacy fixers).
	 * @return bool
	 */
	public function fix_declined( array $issue, $fingerprint ) {
		$declined = $this->declined_fix( (int) $issue['id'] );
		if ( null === $declined ) {
			return false;
		}
		if ( null !== $fingerprint && '' !== $declined['fp'] ) {
			return hash_equals( $declined['fp'], (string) $fingerprint );
		}
		// Legacy fixer (or no fingerprint known): declined until the post is edited.
		if ( in_array( $issue['object_type'], array( 'post', 'attachment' ), true ) && $issue['object_id'] ) {
			$post = get_post( (int) $issue['object_id'] );
			return $post && (string) $post->post_modified_gmt === (string) $declined['modified'];
		}
		return true;
	}

	/**
	 * Drops the cached memory so a write starts from the stored state
	 * (another request may have changed it).
	 */
	private function reload_memory() {
		$this->memory = null;
		wp_cache_delete( self::FIX_MEMORY, 'options' );
	}

	/**
	 * Saves the fix memory (bounded).
	 *
	 * @param array $memory Memory.
	 */
	private function save_memory( array $memory ) {
		foreach ( array( 'applied' => 2000, 'activity' => 5000, 'declined' => 2000 ) as $key => $cap ) {
			if ( count( $memory[ $key ] ) > $cap ) {
				$memory[ $key ] = array_slice( $memory[ $key ], -$cap, null, true );
			}
		}
		$this->memory = $memory;
		update_option( self::FIX_MEMORY, $memory, false );
	}

	/**
	 * Open issue counts per type.
	 *
	 * @param string[] $types   Types to count (empty = all).
	 * @param array    $filters category (string|string[]), exclude_category (string[]).
	 * @return array<string,int>
	 */
	public function type_counts( array $types = array(), array $filters = array() ) {
		global $wpdb;
		$table  = Tables::issues();
		$where  = array( "status IN ('open','pending_approval')" );
		$params = array();
		if ( $types ) {
			$where[] = 'type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $types ) );
		}
		if ( ! empty( $filters['category'] ) ) {
			$values  = (array) $filters['category'];
			$where[] = 'category IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $values ) );
		}
		if ( ! empty( $filters['exclude_category'] ) ) {
			$values  = (array) $filters['exclude_category'];
			$where[] = 'category NOT IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $values ) );
		}
		$sql = "SELECT type, COUNT(*) AS n FROM {$table} WHERE " . implode( ' AND ', $where ) . ' GROUP BY type';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->type ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Open issues for one object (e.g. a post in the editor sidebar).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return array
	 */
	public function for_object( $object_type, $object_id ) {
		return $this->query(
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'per_page'    => 100,
			)
		)['items'];
	}

	/**
	 * Deletes resolved/fixed issues older than a number of days.
	 *
	 * @param int $days Days.
	 */
	public function prune( $days = 60 ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('fixed','resolved') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Deletes every issue of an object (object deleted).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 */
	public function delete_for_object( $object_type, $object_id ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::issues(),
			array(
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
			)
		);
	}

	/**
	 * Decodes data and adds definition texts.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['object_id']   = (int) $row['object_id'];
		$row['occurrences'] = (int) $row['occurrences'];
		$row['data']        = $row['data'] ? json_decode( $row['data'], true ) : array();
		if ( ! is_array( $row['data'] ) ) {
			$row['data'] = array();
		}
		$def = $this->registry->get( $row['type'] );

		// Texts are plain text (never HTML): titles come from get_the_title(), which
		// texturizes them into entities (&#8217; &#8220; &#8211;). Callables see the
		// stored row; every output is decoded exactly once.
		$row['label']       = self::plain( $def['label'] ?? $row['type'] );
		$row['what']        = self::plain( self::text( $def['what'] ?? '', $row ) );
		$row['why']         = self::plain( self::text( $def['why'] ?? '', $row ) );
		$row['fix']         = self::plain( self::text( $def['fix'] ?? '', $row ) );
		$row['manual_step'] = self::plain( self::text( $def['manual_step'] ?? '', $row ) );
		$row['title']       = self::plain( (string) $row['title'] );

		$has_planner        = $def && IssueRegistry::has_planner( $def );
		$has_fixer          = $def && ! empty( $def['fixer'] ) && is_callable( $def['fixer'] );
		$row['mode']        = $def ? $this->registry->mode_for( $def, $row ) : 'advisory';
		$row['fixable']     = 'advisory' !== $row['mode'] && ( $has_planner || $has_fixer );
		$row['previewable'] = $row['fixable'] && $has_planner;
		$row['fix_label']   = '';
		if ( $row['fixable'] ) {
			$label            = (string) ( $def['fix_label'] ?? '' );
			$row['fix_label'] = '' !== $label ? self::plain( $label ) : ( $row['previewable'] ? __( 'Preview fix', 'searchpilot-pro' ) : __( 'Fix automatically', 'searchpilot-pro' ) );
		}
		$declined            = $this->declined_fix( $row['id'] );
		$row['declined']     = null !== $declined && in_array( $row['status'], self::OPEN_STATUSES, true );
		$row['destructive']  = ! empty( $def['destructive'] );
		$row['action']       = $def['action'] ?? '';
		$row['task']         = $def['task'] ?? '';
		$row['edit_link']    = self::edit_link( $row['object_type'], $row['object_id'] );
		$row['detected_at']  = $row['detected_at'] ? mysql_to_rfc3339( $row['detected_at'] ) : null;
		$row['last_seen_at'] = $row['last_seen_at'] ? mysql_to_rfc3339( $row['last_seen_at'] ) : null;
		$row['resolved_at']  = $row['resolved_at'] ? mysql_to_rfc3339( $row['resolved_at'] ) : null;
		unset( $row['issue_key'], $row['severity_rank'] );
		return $row;
	}

	/**
	 * Resolves a text definition.
	 *
	 * @param string|callable $text Text or callable.
	 * @param array           $row  Issue.
	 * @return string
	 */
	private static function text( $text, array $row ) {
		if ( is_callable( $text ) && ! is_string( $text ) ) {
			return (string) call_user_func( $text, $row );
		}
		return (string) $text;
	}

	/**
	 * Plain text: HTML entities decoded (&#8217; → ’), for the UI, CSV
	 * exports and e-mails, which all escape on output.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function plain( $text ) {
		$text = (string) $text;
		if ( false === strpos( $text, '&' ) ) {
			return $text;
		}
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Admin edit link for the object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Id.
	 * @return string
	 */
	private static function edit_link( $type, $id ) {
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
		if ( 'author' === $type ) {
			return (string) get_edit_user_link( $id );
		}
		return '';
	}
}
