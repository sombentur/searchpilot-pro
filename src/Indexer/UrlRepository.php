<?php
/**
 * URL registry storage.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Database\Tables;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * One row per known URL with its indexing state:
 * Index Status → Last Submitted → Last Crawled → Last Modified → Canonical →
 * Sitemap Status → Indexability → Search Engines → Errors → Recommended Action.
 *
 * index_status values: unknown, indexed, not_indexed, crawled_not_indexed,
 * discovered_not_indexed, excluded_noindex, blocked_robots, duplicate_canonical,
 * alternate_canonical, redirect, not_found, soft_404, server_error,
 * unknown_to_google, removed.
 */
class UrlRepository {

	const INDEX_STATUSES = array( 'unknown', 'indexed', 'not_indexed', 'crawled_not_indexed', 'discovered_not_indexed', 'excluded_noindex', 'blocked_robots', 'duplicate_canonical', 'alternate_canonical', 'redirect', 'not_found', 'soft_404', 'server_error', 'unknown_to_google', 'removed' );

	const COLUMNS = array( 'url', 'object_type', 'object_subtype', 'object_id', 'status', 'index_status', 'coverage_state', 'verdict', 'indexable', 'indexability_reason', 'in_sitemap', 'http_status', 'redirect_to', 'canonical_url', 'google_canonical', 'robots_meta', 'robots_txt_blocked', 'engines', 'errors', 'recommended_action', 'priority', 'content_hash', 'last_modified', 'last_submitted', 'last_crawled', 'last_inspected', 'last_checked' );

	/**
	 * Inserts or updates the row for a URL.
	 *
	 * @param string $url    URL.
	 * @param array  $fields Column values (errors may be an array).
	 * @return int Row id.
	 */
	public function upsert( $url, array $fields = array() ) {
		global $wpdb;
		$hash   = Url::hash( $url );
		$table  = Tables::urls();
		$now    = current_time( 'mysql', true );
		$fields = $this->clean( $fields );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) );
		if ( $id ) {
			$fields['updated_at'] = $now;
			$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$fields = array_merge(
			array(
				'url'        => Url::normalize( $url ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$fields,
			array( 'url_hash' => $hash )
		);
		$wpdb->insert( $table, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a row by id.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 * @return bool
	 */
	public function update( $id, array $fields ) {
		global $wpdb;
		$fields               = $this->clean( $fields );
		$fields['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( Tables::urls(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Row by URL.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public function get_by_url( $url ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url_hash = %s", Url::hash( $url ) ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Active row for a WordPress object (the canonical URL of that object).
	 *
	 * @param string $object_type post|term|author|home|post_type_archive|attachment.
	 * @param int    $object_id   Object id.
	 * @return array|null
	 */
	public function get_by_object( $object_type, $object_id ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY (status = 'active') DESC, id DESC LIMIT 1", $object_type, (int) $object_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * All rows for an object (current and previous URLs).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return array[]
	 */
	public function all_for_object( $object_type, $object_id ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d ORDER BY id DESC", $object_type, (int) $object_id ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Marks every row of an object as removed (object deleted/unpublished).
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param string $except_url  Keep this URL active.
	 */
	public function mark_removed_for_object( $object_type, $object_id, $except_url = '' ) {
		global $wpdb;
		$table  = Tables::urls();
		$except = '' !== $except_url ? Url::hash( $except_url ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'removed', in_sitemap = 0, updated_at = %s WHERE object_type = %s AND object_id = %d AND url_hash <> %s", current_time( 'mysql', true ), $object_type, (int) $object_id, $except ) );
	}

	/**
	 * Deletes rows by id, with their stored crawl and inspection results.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public function delete_rows( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table   = Tables::urls();
		$deleted = 0;
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$holders})", $chunk ) );
			foreach ( $chunk as $id ) {
				delete_transient( 'searchpilot_crawl_' . $id );
				delete_transient( 'searchpilot_inspection_' . $id );
			}
		}
		return $deleted;
	}

	/**
	 * Lists rows.
	 *
	 * @param array $filters status (active|removed|any), index_status (string|array), object_type,
	 *                       object_subtype, indexable (0|1), in_sitemap (0|1), http (2xx|3xx|4xx|5xx),
	 *                       has_errors (bool), search, page, per_page, orderby, order.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::urls();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 500, max( 1, (int) ( $filters['per_page'] ?? 50 ) ) );

		$status = $filters['status'] ?? 'active';
		if ( 'any' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = (string) $status;
		}
		if ( ! empty( $filters['index_status'] ) ) {
			$values  = (array) $filters['index_status'];
			$where[] = 'index_status IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $values ) );
		}
		foreach ( array( 'object_type', 'object_subtype', 'recommended_action' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$where[]  = "{$field} = %s";
				$params[] = (string) $filters[ $field ];
			}
		}
		foreach ( array( 'indexable', 'in_sitemap', 'robots_txt_blocked' ) as $field ) {
			if ( isset( $filters[ $field ] ) && '' !== $filters[ $field ] && null !== $filters[ $field ] ) {
				$where[]  = "{$field} = %d";
				$params[] = (int) (bool) $filters[ $field ];
			}
		}
		if ( ! empty( $filters['http'] ) && preg_match( '/^([2-5])xx$/', (string) $filters['http'], $m ) ) {
			$where[]  = 'http_status BETWEEN %d AND %d';
			$params[] = (int) $m[1] * 100;
			$params[] = (int) $m[1] * 100 + 99;
		}
		if ( ! empty( $filters['has_errors'] ) ) {
			$where[] = "errors IS NOT NULL AND errors NOT IN ('', '[]')";
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( Url::encode_iri( (string) $filters['search'] ) ) . '%';
		}
		$orderable = array( 'id', 'url', 'index_status', 'last_submitted', 'last_crawled', 'last_modified', 'last_checked', 'last_inspected', 'http_status', 'priority', 'updated_at' );
		$orderby   = in_array( $filters['orderby'] ?? '', $orderable, true ) ? $filters['orderby'] : 'last_modified';
		$order     = 'ASC' === strtoupper( (string) ( $filters['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

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
	 * Counts of active URLs by index status.
	 *
	 * @return array<string,int>
	 */
	public function count_by_status() {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT index_status, COUNT(*) AS n FROM {$table} WHERE status = 'active' GROUP BY index_status" );
		$out  = array_fill_keys( self::INDEX_STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row->index_status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Aggregate numbers for dashboards.
	 *
	 * @return array
	 */
	public function summary() {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(indexable = 1) AS indexable,
				SUM(in_sitemap = 1) AS in_sitemap,
				SUM(index_status = 'indexed') AS indexed,
				SUM(index_status NOT IN ('indexed','unknown')) AS not_indexed,
				SUM(index_status = 'unknown') AS unknown,
				SUM(http_status BETWEEN 400 AND 499) AS errors_4xx,
				SUM(http_status >= 500) AS errors_5xx,
				SUM(robots_txt_blocked = 1) AS blocked,
				MAX(last_inspected) AS last_inspected,
				MAX(last_checked) AS last_checked
			FROM {$table} WHERE status = 'active'",
			ARRAY_A
		);
		$row = (array) $row;
		foreach ( array( 'total', 'indexable', 'in_sitemap', 'indexed', 'not_indexed', 'unknown', 'errors_4xx', 'errors_5xx', 'blocked' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		$row['last_inspected'] = ! empty( $row['last_inspected'] ) ? mysql_to_rfc3339( $row['last_inspected'] ) : null;
		$row['last_checked']   = ! empty( $row['last_checked'] ) ? mysql_to_rfc3339( $row['last_checked'] ) : null;
		return $row;
	}

	/**
	 * Active rows whose own HTTP check is due, most urgent first.
	 *
	 * @param int $limit       Max rows.
	 * @param int $recheck_days Re-check interval.
	 * @return array[]
	 */
	public function due_for_check( $limit, $recheck_days ) {
		global $wpdb;
		$table  = Tables::urls();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $recheck_days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND ( last_checked IS NULL OR last_checked < %s OR ( last_modified IS NOT NULL AND last_modified > last_checked ) )
				ORDER BY ( last_checked IS NULL ) DESC, ( last_modified > last_checked ) DESC, priority DESC, last_checked ASC LIMIT %d",
				$cutoff,
				(int) $limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Active indexable rows whose Search Console inspection is due.
	 *
	 * @param int $limit Max rows.
	 * @param int $days  Re-inspect after this many days.
	 * @return array[]
	 */
	public function due_for_inspection( $limit, $days = 30 ) {
		global $wpdb;
		$table  = Tables::urls();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$recent = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		// Priority: never inspected, submitted 2+ days ago but not yet indexed, stale inspections, important pages.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'active' AND indexable = 1 AND (
					last_inspected IS NULL
					OR last_inspected < %s
					OR ( index_status <> 'indexed' AND last_submitted IS NOT NULL AND last_submitted < %s AND last_inspected < last_submitted )
					OR ( last_modified IS NOT NULL AND last_modified > last_inspected AND last_inspected < %s )
				)
				ORDER BY ( last_inspected IS NULL ) DESC, ( index_status <> 'indexed' ) DESC, priority DESC, last_inspected ASC LIMIT %d",
				$cutoff,
				$recent,
				$recent,
				(int) $limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Deletes rows removed long ago.
	 *
	 * @param int $days Days.
	 */
	public function prune_removed( $days = 180 ) {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'removed' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Row formatting for the API.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		foreach ( array( 'id', 'object_id', 'indexable', 'in_sitemap', 'http_status', 'robots_txt_blocked', 'priority' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		$row['indexable']          = ! empty( $row['indexable'] );
		$row['in_sitemap']         = ! empty( $row['in_sitemap'] );
		$row['robots_txt_blocked'] = ! empty( $row['robots_txt_blocked'] );
		$errors                    = isset( $row['errors'] ) ? json_decode( (string) $row['errors'], true ) : array();
		$row['errors']             = is_array( $errors ) ? $errors : array();
		$row['engines']            = '' !== (string) ( $row['engines'] ?? '' ) ? explode( ',', $row['engines'] ) : array();
		$row['display_url']        = Url::display( $row['url'] ?? '' );
		foreach ( array( 'last_modified', 'last_submitted', 'last_crawled', 'last_inspected', 'last_checked', 'created_at', 'updated_at' ) as $date ) {
			$row[ $date ] = ! empty( $row[ $date ] ) ? mysql_to_rfc3339( $row[ $date ] ) : null;
		}
		unset( $row['url_hash'] );
		return $row;
	}

	/**
	 * Keeps known columns and encodes arrays.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private function clean( array $fields ) {
		$out = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, self::COLUMNS, true ) ) {
				continue;
			}
			if ( 'errors' === $key ) {
				$value = is_array( $value ) ? wp_json_encode( array_values( $value ) ) : $value;
			} elseif ( 'engines' === $key && is_array( $value ) ) {
				$value = implode( ',', array_unique( array_filter( $value ) ) );
			} elseif ( 'url' === $key ) {
				$value = Url::normalize( $value );
			} elseif ( is_bool( $value ) ) {
				$value = (int) $value;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
