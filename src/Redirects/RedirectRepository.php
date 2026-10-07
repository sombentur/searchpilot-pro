<?php
/**
 * Redirect rule storage.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Redirects;

use SearchPilot\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for `searchpilot_redirects`. Changes that should be logged
 * and undoable go through RedirectsModule (create_rule, update_rule, …);
 * this class only reads and writes rows.
 *
 * The front end uses find_exact() (one indexed query on source_hash,
 * cached in the object cache group `searchpilot_redirects` when a
 * persistent cache exists) and pattern_rules() on 404s only.
 */
class RedirectRepository {

	const CACHE_GROUP   = 'searchpilot_redirects';
	const COUNTS_OPTION = 'searchpilot_redirect_counts';
	const PATTERNS_KEY  = 'searchpilot_redirect_patterns';

	const COLUMNS = array( 'source', 'source_hash', 'match_type', 'target', 'status_code', 'enabled', 'origin', 'object_id', 'hits', 'last_hit', 'notes', 'created_by', 'created_at', 'updated_at' );

	const ORIGINS = array( 'manual', 'slug_change', 'import', '404', 'fix', 'deleted' );

	/**
	 * Counts of enabled rules by kind (autoloaded; lets the front end skip lookups entirely).
	 *
	 * @return array{exact:int,patterns:int}
	 */
	public function counts() {
		$counts = get_option( self::COUNTS_OPTION, null );
		if ( ! is_array( $counts ) ) {
			$counts = $this->refresh_counts();
		}
		return array(
			'exact'    => (int) ( $counts['exact'] ?? 0 ),
			'patterns' => (int) ( $counts['patterns'] ?? 0 ),
		);
	}

	/**
	 * Recomputes the counts option and clears caches (after every change).
	 *
	 * @return array
	 */
	public function refresh_counts() {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows   = $wpdb->get_results( "SELECT match_type, COUNT(*) AS n FROM {$table} WHERE enabled = 1 GROUP BY match_type" );
		$counts = array(
			'exact'    => 0,
			'patterns' => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( 'exact' === $row->match_type ) {
				$counts['exact'] += (int) $row->n;
			} else {
				$counts['patterns'] += (int) $row->n;
			}
		}
		update_option( self::COUNTS_OPTION, $counts, true );
		// wp_cache_set_last_changed() needs WordPress 6.3; set the key directly.
		wp_cache_set( 'last_changed', microtime(), self::CACHE_GROUP );
		delete_transient( self::PATTERNS_KEY );
		wp_cache_delete( self::PATTERNS_KEY, self::CACHE_GROUP );
		return $counts;
	}

	/**
	 * Enabled exact rules for any of the hashes (newest first). One indexed query.
	 *
	 * @param string[] $hashes Source hashes.
	 * @return array[]
	 */
	public function find_exact( array $hashes ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}
		$cache     = wp_using_ext_object_cache();
		$cache_key = '';
		if ( $cache ) {
			$cache_key = 'exact:' . md5( implode( ',', $hashes ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
			$hit       = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}
		$table   = Tables::redirects();
		$holders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, source_hash, match_type, target, status_code, enabled, origin, created_by FROM {$table} WHERE source_hash IN ({$holders}) AND enabled = 1 AND match_type = 'exact' ORDER BY id DESC", $hashes ), ARRAY_A );
		$rows = array_map( array( $this, 'hydrate' ), (array) $rows );
		if ( $cache ) {
			// Misses are cached too (empty array).
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		return $rows;
	}

	/**
	 * Which of the hashes are the source of an enabled exact redirect.
	 *
	 * @param string[] $hashes Hashes.
	 * @return array<string,bool>
	 */
	public function existing_sources( array $hashes ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );
		if ( empty( $hashes ) || $this->counts()['exact'] < 1 ) {
			return array();
		}
		$table = Tables::redirects();
		$out   = array();
		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT source_hash FROM {$table} WHERE source_hash IN ({$holders}) AND enabled = 1 AND match_type = 'exact' AND status_code BETWEEN 300 AND 399", $chunk ) );
			foreach ( (array) $found as $hash ) {
				$out[ $hash ] = true;
			}
		}
		return $out;
	}

	/**
	 * Enabled prefix/regex rules (cached, capped).
	 *
	 * @param int $limit Max rules.
	 * @return array[]
	 */
	public function pattern_rules( $limit = RedirectMatcher::MAX_PATTERN_RULES ) {
		global $wpdb;
		if ( $this->counts()['patterns'] < 1 ) {
			return array();
		}
		$cache = wp_using_ext_object_cache();
		$rows  = $cache ? wp_cache_get( self::PATTERNS_KEY, self::CACHE_GROUP ) : get_transient( self::PATTERNS_KEY );
		if ( is_array( $rows ) ) {
			return $rows;
		}
		$table = Tables::redirects();
		// Prefix rules first (cheap), then regex in creation order.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, source_hash, match_type, target, status_code, enabled, origin, created_by FROM {$table} WHERE enabled = 1 AND match_type IN ('prefix','regex') ORDER BY (match_type = 'prefix') DESC, id ASC LIMIT %d", (int) $limit ), ARRAY_A );
		$rows = array_map( array( $this, 'hydrate' ), (array) $rows );
		if ( $cache ) {
			wp_cache_set( self::PATTERNS_KEY, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		} else {
			set_transient( self::PATTERNS_KEY, $rows, HOUR_IN_SECONDS );
		}
		return $rows;
	}

	/**
	 * Counts a hit (one UPDATE).
	 *
	 * @param int $id Rule id.
	 */
	public function hit( $id ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), (int) $id ) );
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Raw row (for activity snapshots).
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function raw( $id ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Rules with the same source (duplicate detection).
	 *
	 * @param string $match_type Match type.
	 * @param string $source     Normalized source.
	 * @param string $hash       Source hash ('' for regex).
	 * @return array|null First duplicate.
	 */
	public function find_duplicate( $match_type, $source, $hash ) {
		global $wpdb;
		$table = Tables::redirects();
		if ( '' !== (string) $hash ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s AND match_type = %s ORDER BY enabled DESC, id DESC LIMIT 1", $hash, $match_type ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE match_type = %s AND source = %s ORDER BY enabled DESC, id DESC LIMIT 1", $match_type, $source ), ARRAY_A );
		}
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Enabled exact rules whose source hash matches (any code).
	 *
	 * @param string $hash Hash.
	 * @return array[]
	 */
	public function by_source_hash( $hash ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s AND match_type = 'exact' AND enabled = 1 ORDER BY id DESC", (string) $hash ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Rules created for an object (e.g. slug-change redirects of a post).
	 *
	 * @param int    $object_id Object id.
	 * @param string $origin    Origin filter ('' = any).
	 * @return array[]
	 */
	public function by_object( $object_id, $origin = '' ) {
		global $wpdb;
		$table = Tables::redirects();
		if ( '' !== $origin ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d AND origin = %s ORDER BY id ASC", (int) $object_id, $origin ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d ORDER BY id ASC", (int) $object_id ), ARRAY_A );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $data    Columns.
	 * @param bool  $refresh Refresh counts/caches (false during bulk imports; call refresh_counts() after).
	 * @return int Id (0 on failure).
	 */
	public function insert( array $data, $refresh = true ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'source_hash' => '',
				'match_type'  => 'exact',
				'target'      => '',
				'status_code' => 301,
				'enabled'     => 1,
				'origin'      => 'manual',
				'object_id'   => 0,
				'hits'        => 0,
				'notes'       => '',
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			$this->clean( $data )
		);
		if ( isset( $data['id'] ) ) {
			$data['id'] = (int) $data['id'];
		}
		$ok = $wpdb->insert( Tables::redirects(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $ok ? (int) $wpdb->insert_id : 0;
		if ( $refresh ) {
			$this->refresh_counts();
		}
		return $id;
	}

	/**
	 * Re-inserts a deleted row with its original id when free (undo).
	 *
	 * @param array $row Raw row.
	 * @return int
	 */
	public function restore( array $row ) {
		$data = $this->clean( $row );
		$id   = (int) ( $row['id'] ?? 0 );
		unset( $data['id'] );
		if ( $id && ! $this->raw( $id ) ) {
			$data['id'] = $id;
		}
		return $this->insert( $data );
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id      Id.
	 * @param array $data    Columns.
	 * @param bool  $refresh Refresh counts/caches.
	 * @return bool
	 */
	public function update( $id, array $data, $refresh = true ) {
		global $wpdb;
		$data = $this->clean( $data );
		unset( $data['id'] );
		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}
		$ok = false !== $wpdb->update( Tables::redirects(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $refresh ) {
			$this->refresh_counts();
		}
		return $ok;
	}

	/**
	 * Deletes rows.
	 *
	 * @param int[] $ids     Ids.
	 * @param bool  $refresh Refresh counts/caches.
	 * @return int Rows deleted.
	 */
	public function delete( array $ids, $refresh = true ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table = Tables::redirects();
		$n     = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$n += (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $chunk ) . ')' );
		}
		if ( $refresh ) {
			$this->refresh_counts();
		}
		return $n;
	}

	/**
	 * Lists rules.
	 *
	 * @param array $filters search, match_type, origin, enabled (0|1|''), page, per_page, orderby (hits|updated|created|source), order.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::redirects();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );
		if ( ! empty( $filters['match_type'] ) && in_array( $filters['match_type'], RedirectMatcher::MATCH_TYPES, true ) ) {
			$where[]  = 'match_type = %s';
			$params[] = $filters['match_type'];
		}
		if ( ! empty( $filters['origin'] ) ) {
			$where[]  = 'origin = %s';
			$params[] = (string) $filters['origin'];
		}
		if ( isset( $filters['enabled'] ) && '' !== $filters['enabled'] && null !== $filters['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = (int) (bool) $filters['enabled'];
		}
		if ( ! empty( $filters['ids'] ) ) {
			$where[] = 'id IN (' . implode( ',', array_map( 'absint', (array) $filters['ids'] ) ) . ')';
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$enc      = '%' . $wpdb->esc_like( \SearchPilot\Support\Url::encode_iri( (string) $filters['search'] ) ) . '%';
			$where[]  = '(source LIKE %s OR source LIKE %s OR target LIKE %s OR target LIKE %s OR notes LIKE %s)';
			$params[] = $like;
			$params[] = $enc;
			$params[] = $like;
			$params[] = $enc;
			$params[] = $like;
		}
		$orders  = array(
			'hits'    => 'hits',
			'updated' => 'updated_at',
			'created' => 'created_at',
			'source'  => 'source',
			'last_hit' => 'last_hit',
		);
		$orderby = $orders[ $filters['orderby'] ?? 'updated' ] ?? 'updated_at';
		$order   = 'ASC' === strtoupper( (string) ( $filters['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Enabled exact rules for graph analysis (id, source, target, code).
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public function graph_rules( $limit = 50000 ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, target, match_type, status_code, enabled FROM {$table} WHERE enabled = 1 AND match_type = 'exact' AND status_code BETWEEN 300 AND 399 ORDER BY id ASC LIMIT %d", (int) $limit ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Dashboard numbers.
	 *
	 * hits_30d sums the all-time hits of rules used in the last 30 days (per-day hits are not stored).
	 *
	 * @return array{total:int,active:int,hits_total:int,hits_30d:int,used_30d:int,auto:int,by_type:array}
	 */
	public function stats() {
		global $wpdb;
		$table = Tables::redirects();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM(enabled = 1) AS active, SUM(hits) AS hits_total,
					SUM(CASE WHEN last_hit >= %s THEN hits ELSE 0 END) AS hits_30d,
					SUM(CASE WHEN last_hit >= %s THEN 1 ELSE 0 END) AS used_30d,
					SUM(CASE WHEN origin IN ('slug_change','deleted','404','fix') THEN 1 ELSE 0 END) AS auto,
					SUM(match_type = 'exact') AS exact_n, SUM(match_type = 'prefix') AS prefix_n, SUM(match_type = 'regex') AS regex_n
				FROM {$table}",
				$since,
				$since
			),
			ARRAY_A
		);
		$row = (array) $row;
		return array(
			'total'    => (int) ( $row['total'] ?? 0 ),
			'active'   => (int) ( $row['active'] ?? 0 ),
			'hits_total' => (int) ( $row['hits_total'] ?? 0 ),
			'hits_30d' => (int) ( $row['hits_30d'] ?? 0 ),
			'used_30d' => (int) ( $row['used_30d'] ?? 0 ),
			'auto'     => (int) ( $row['auto'] ?? 0 ),
			'by_type'  => array(
				'exact'  => (int) ( $row['exact_n'] ?? 0 ),
				'prefix' => (int) ( $row['prefix_n'] ?? 0 ),
				'regex'  => (int) ( $row['regex_n'] ?? 0 ),
			),
		);
	}

	/**
	 * Every rule for export (batched by id).
	 *
	 * @param int $after Id cursor.
	 * @param int $limit Batch size.
	 * @return array[]
	 */
	public function batch( $after, $limit = 1000 ) {
		global $wpdb;
		$table = Tables::redirects();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Row formatting.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		foreach ( array( 'id', 'status_code', 'object_id', 'hits', 'created_by' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		if ( isset( $row['enabled'] ) ) {
			$row['enabled'] = (bool) (int) $row['enabled'];
		}
		return $row;
	}

	/**
	 * Keeps known columns.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function clean( array $data ) {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, self::COLUMNS, true ) && 'id' !== $key ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = (int) $value;
			}
			if ( 'notes' === $key ) {
				$value = mb_substr( sanitize_text_field( (string) $value ), 0, 250 );
			}
			if ( 'origin' === $key ) {
				$value = in_array( $value, self::ORIGINS, true ) ? $value : 'manual';
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
