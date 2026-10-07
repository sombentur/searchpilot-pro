<?php
/**
 * Submission queue storage.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

use SearchPilot\Database\Tables;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of {prefix}searchpilot_queue. One row = one URL (or sitemap) for one
 * service. Statuses: pending, held, processing, done, failed, skipped,
 * cancelled. Higher `priority` values are processed first.
 *
 * While a row is `held`, `last_error` carries the hold code as
 * "hold:<reason>" (local|setup|environment|approval); hydrate() exposes it
 * as `hold_reason`.
 */
class QueueRepository {

	const STATUSES = array( 'pending', 'held', 'processing', 'done', 'failed', 'skipped', 'cancelled' );

	const OPEN = array( 'pending', 'held', 'processing' );

	const HISTORY = array( 'done', 'failed', 'skipped', 'cancelled' );

	const SERVICES = array( 'indexnow', 'bing_url', 'google_indexing', 'gsc_sitemap', 'bing_sitemap' );

	const HOLD_PREFIX = 'hold:';

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row Column values.
	 * @return int Id.
	 */
	public function insert( array $row ) {
		global $wpdb;
		$now = self::now();
		$row = array_merge(
			array(
				'action'       => 'update',
				'status'       => 'pending',
				'priority'     => 10,
				'attempts'     => 0,
				'max_attempts' => 5,
				'object_type'  => '',
				'object_id'    => 0,
				'reason'       => '',
				'available_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			$row
		);
		$row['url']      = Url::normalize( (string) $row['url'] );
		$row['url_hash'] = Url::hash( $row['url'] );
		$row['priority'] = max( 0, min( 255, (int) $row['priority'] ) );
		$row['reason']   = substr( sanitize_key( (string) $row['reason'] ), 0, 64 );
		$wpdb->insert( Tables::queue(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 * @return bool
	 */
	public function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = self::now();
		return false !== $wpdb->update( Tables::queue(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Updates many rows with the same values.
	 *
	 * @param int[]  $ids          Ids.
	 * @param array  $fields       Column => value (null allowed).
	 * @param string $status_guard Only rows in this status ('' = any).
	 * @return int Rows changed.
	 */
	public function update_many( array $ids, array $fields, $status_guard = '' ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) || empty( $fields ) ) {
			return 0;
		}
		$fields['updated_at'] = self::now();
		$sets                 = array();
		$params               = array();
		foreach ( $fields as $column => $value ) {
			$column = preg_replace( '/[^a-z_]/', '', (string) $column );
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[]   = is_int( $value ) ? "{$column} = %d" : "{$column} = %s";
			$params[] = $value;
		}
		$table = Tables::queue();
		$total = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql = "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id IN (' . implode( ',', $chunk ) . ')';
			$p   = $params;
			if ( '' !== $status_guard ) {
				$sql .= ' AND status = %s';
				$p[]  = $status_guard;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$total += (int) ( $p ? $wpdb->query( $wpdb->prepare( $sql, $p ) ) : $wpdb->query( $sql ) );
		}
		return $total;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function raw( $id ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Hydrated row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		$row = $this->raw( $id );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Open (pending/held) row for a dedupe key.
	 *
	 * @param string $dedupe_key Key.
	 * @return array|null Raw row.
	 */
	public function find_open( $dedupe_key ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s AND status IN ('pending','held') ORDER BY id DESC LIMIT 1", $dedupe_key ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Latest row of a status for a dedupe key completed after a time.
	 *
	 * @param string $dedupe_key Key.
	 * @param string $status     done|skipped|…
	 * @param string $since      MySQL UTC datetime.
	 * @return array|null Raw row.
	 */
	public function find_recent( $dedupe_key, $status, $since ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s AND status = %s AND completed_at >= %s ORDER BY id DESC LIMIT 1", $dedupe_key, $status, $since ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Whether a URL was ever sent successfully to a service (with an action).
	 *
	 * @param string $service Service.
	 * @param string $url     URL.
	 * @param string $action  Action.
	 * @return bool
	 */
	public function was_sent( $service, $url, $action = 'update' ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s AND service = %s AND action = %s AND status = 'done' LIMIT 1", Url::hash( $url ), $service, $action ) );
	}

	/**
	 * Held rows with one of the given hold reasons (id, url, service, reason, hold).
	 *
	 * @param string[] $holds Hold reasons.
	 * @param int      $limit Max rows.
	 * @return array[]
	 */
	public function held_rows( array $holds, $limit = 5000 ) {
		global $wpdb;
		$holds = array_values( array_filter( array_map( 'sanitize_key', $holds ) ) );
		if ( empty( $holds ) ) {
			return array();
		}
		$table   = Tables::queue();
		$markers = array_map(
			static function ( $hold ) {
				return self::HOLD_PREFIX . $hold;
			},
			$holds
		);
		$holders = implode( ',', array_fill( 0, count( $markers ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url, service, reason, last_error FROM {$table} WHERE status = 'held' AND last_error IN ({$holders}) ORDER BY id ASC LIMIT %d", array_merge( $markers, array( (int) $limit ) ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['hold'] = self::hold_code( (string) $row['last_error'] );
			$out[]       = $row;
		}
		return $out;
	}

	/**
	 * Cancels open rows of a URL/service with a different action (latest intent wins).
	 *
	 * @param string $url     URL.
	 * @param string $service Service.
	 * @param string $action  Action being queued now.
	 * @return int
	 */
	public function supersede( $url, $service, $action ) {
		global $wpdb;
		$table = Tables::queue();
		$now   = self::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'cancelled', last_error = %s, completed_at = %s, updated_at = %s WHERE url_hash = %s AND service = %s AND action <> %s AND status IN ('pending','held')", __( 'Superseded by a newer notification for the same URL.', 'searchpilot-pro' ), $now, $now, Url::hash( $url ), $service, $action ) );
	}

	/**
	 * Atomically claims due pending rows of a service.
	 *
	 * @param string $service  Service.
	 * @param int    $limit    Max rows.
	 * @param string $batch_id Batch id.
	 * @return array[] Raw claimed rows.
	 */
	public function claim( $service, $limit, $batch_id ) {
		global $wpdb;
		$limit = max( 0, (int) $limit );
		if ( ! $limit ) {
			return array();
		}
		$table = Tables::queue();
		$now   = self::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND service = %s AND available_at <= %s ORDER BY priority DESC, available_at ASC, id ASC LIMIT %d", $service, $now, $limit ) );
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', batch_id = %s, locked_at = %s, updated_at = %s WHERE id IN (" . implode( ',', $ids ) . ") AND status = 'pending'", $batch_id, $now, $now ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s AND status = 'processing' ORDER BY priority DESC, id ASC", $batch_id ), ARRAY_A );
		return (array) $rows;
	}

	/**
	 * Returns claimed rows to pending without counting an attempt.
	 *
	 * @param int[]       $ids          Ids.
	 * @param string|null $available_at When to retry (MySQL UTC; null = now).
	 * @return int
	 */
	public function unclaim( array $ids, $available_at = null ) {
		return $this->update_many(
			$ids,
			array(
				'status'       => 'pending',
				'batch_id'     => '',
				'locked_at'    => null,
				'available_at' => null === $available_at ? self::now() : $available_at,
			),
			'processing'
		);
	}

	/**
	 * Puts rows stuck in processing (crashed worker) back to pending.
	 *
	 * @param int $minutes Age.
	 * @return int
	 */
	public function recover_stale( $minutes = 15 ) {
		global $wpdb;
		$table = Tables::queue();
		$now   = self::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', batch_id = '', locked_at = NULL, available_at = %s, updated_at = %s WHERE status = 'processing' AND ( locked_at IS NULL OR locked_at < %s )", $now, $now, self::now( -60 * (int) $minutes ) ) );
	}

	/**
	 * Moves every pending row to held (the environment guard kicked in).
	 *
	 * @param string $hold Hold reason.
	 * @return int
	 */
	public function hold_pending( $hold ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'held', last_error = %s, updated_at = %s WHERE status = 'pending'", self::HOLD_PREFIX . sanitize_key( $hold ), self::now() ) );
	}

	/**
	 * Changes held rows with a given hold reason.
	 *
	 * @param string      $hold         Current hold reason.
	 * @param string[]    $services     Services ([] = all).
	 * @param array       $set          Columns to set.
	 * @param string|null $reason_is    Only rows with this event reason.
	 * @param string|null $reason_not   Exclude rows with this event reason.
	 * @return int
	 */
	public function update_held( $hold, array $services, array $set, $reason_is = null, $reason_not = null ) {
		global $wpdb;
		$table  = Tables::queue();
		$where  = array( "status = 'held'", 'last_error = %s' );
		$params = array( self::HOLD_PREFIX . sanitize_key( $hold ) );
		if ( $services ) {
			$services = array_values( array_intersect( $services, self::SERVICES ) );
			if ( empty( $services ) ) {
				return 0;
			}
			$where[] = 'service IN (' . implode( ',', array_fill( 0, count( $services ), '%s' ) ) . ')';
			$params  = array_merge( $params, $services );
		}
		if ( null !== $reason_is ) {
			$where[]  = 'reason = %s';
			$params[] = (string) $reason_is;
		}
		if ( null !== $reason_not ) {
			$where[]  = 'reason <> %s';
			$params[] = (string) $reason_not;
		}
		$set['updated_at'] = self::now();
		$sets              = array();
		$set_params        = array();
		foreach ( $set as $column => $value ) {
			$column = preg_replace( '/[^a-z_]/', '', (string) $column );
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
				continue;
			}
			$sets[]       = "{$column} = %s";
			$set_params[] = (string) $value;
		}
		$sql = "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( $set_params, $params ) ) );
	}

	/**
	 * Releases held rows (all, or the given ids) to pending.
	 *
	 * @param int[] $ids Ids ([] = all held rows).
	 * @return int
	 */
	public function release( array $ids = array() ) {
		global $wpdb;
		$now = self::now();
		if ( $ids ) {
			return $this->update_many(
				$ids,
				array(
					'status'       => 'pending',
					'last_error'   => null,
					'available_at' => $now,
				),
				'held'
			);
		}
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', last_error = NULL, available_at = %s, updated_at = %s WHERE status = 'held'", $now, $now ) );
	}

	/**
	 * Re-queues a finished row. Refused when a newer notification exists
	 * for the same URL and service (a stale "delete" must never follow a
	 * newer "update", and vice versa).
	 *
	 * @param int $id Id.
	 * @return true|\WP_Error
	 */
	public function retry( $id ) {
		$row = $this->raw( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'failed', 'cancelled', 'skipped' ), true ) ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Only failed, skipped or cancelled items can be retried.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( $this->has_newer( $row ) ) {
			return new \WP_Error( 'searchpilot_superseded', __( 'A newer notification for this URL exists, so this older one cannot be sent again.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$this->update(
			$id,
			array(
				'status'        => 'pending',
				'attempts'      => 0,
				'last_error'    => null,
				'response_code' => 0,
				'batch_id'      => '',
				'locked_at'     => null,
				'completed_at'  => null,
				'available_at'  => self::now(),
			)
		);
		return true;
	}

	/**
	 * Whether a newer row exists for the same URL and service.
	 *
	 * @param array $row Raw row.
	 * @return bool
	 */
	public function has_newer( array $row ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s AND service = %s AND id > %d LIMIT 1", (string) $row['url_hash'], (string) $row['service'], (int) $row['id'] ) );
	}

	/**
	 * Latest row id per URL+service for a set of raw rows.
	 *
	 * @param array[] $rows Raw rows.
	 * @return array<string,int> "url_hash|service" => max id.
	 */
	private function latest_ids( array $rows ) {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( array_column( $rows, 'url_hash' ) ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}
		$table   = Tables::queue();
		$holders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT url_hash, service, MAX(id) AS max_id FROM {$table} WHERE url_hash IN ({$holders}) GROUP BY url_hash, service", $hashes ), ARRAY_A );
		$out   = array();
		foreach ( (array) $found as $item ) {
			$out[ $item['url_hash'] . '|' . $item['service'] ] = (int) $item['max_id'];
		}
		return $out;
	}

	/**
	 * Cancels an open row.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function cancel( $id ) {
		$row = $this->raw( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'pending', 'held' ), true ) ) {
			return false;
		}
		return $this->update(
			$id,
			array(
				'status'       => 'cancelled',
				'last_error'   => __( 'Cancelled by an administrator.', 'searchpilot-pro' ),
				'completed_at' => self::now(),
			)
		);
	}

	/**
	 * Re-queues every failed row that is still the latest notification for
	 * its URL and service.
	 *
	 * @return int
	 */
	public function retry_failed() {
		global $wpdb;
		$table = Tables::queue();
		$total = 0;
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, url_hash, service FROM {$table} WHERE status = 'failed' AND id > %d ORDER BY id ASC LIMIT 500", $after ), ARRAY_A );
			if ( empty( $rows ) ) {
				break;
			}
			$latest = $this->latest_ids( $rows );
			$ids    = array();
			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				if ( ( $latest[ $row['url_hash'] . '|' . $row['service'] ] ?? 0 ) === (int) $row['id'] ) {
					$ids[] = (int) $row['id'];
				}
			}
			$total += $this->update_many(
				$ids,
				array(
					'status'        => 'pending',
					'attempts'      => 0,
					'last_error'    => null,
					'response_code' => 0,
					'batch_id'      => '',
					'locked_at'     => null,
					'completed_at'  => null,
					'available_at'  => self::now(),
				),
				'failed'
			);
		} while ( count( $rows ) === 500 );
		return $total;
	}

	/**
	 * Deletes finished rows (history).
	 *
	 * @return int
	 */
	public function delete_history() {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE status IN ('done','failed','skipped','cancelled')" );
	}

	/**
	 * Deletes finished rows older than a number of days.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public function prune( $days ) {
		global $wpdb;
		$table  = Tables::queue();
		$cutoff = self::now( -DAY_IN_SECONDS * max( 1, (int) $days ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('done','failed','skipped','cancelled') AND COALESCE(completed_at, updated_at) < %s", $cutoff ) );
	}

	/**
	 * Failed rows completed since a time.
	 *
	 * @param string $since MySQL UTC datetime.
	 * @return int
	 */
	public function failures_since( $since ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND completed_at >= %s", $since ) );
	}

	/**
	 * Most frequent recent failure messages.
	 *
	 * @param string $since MySQL UTC datetime.
	 * @param int    $limit Max rows.
	 * @return array[] [ service, last_error, n ]
	 */
	public function top_failures( $since, $limit = 3 ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT service, last_error, COUNT(*) AS n FROM {$table} WHERE status = 'failed' AND completed_at >= %s GROUP BY service, last_error ORDER BY n DESC LIMIT %d", $since, (int) $limit ), ARRAY_A );
		return array_map(
			static function ( $row ) {
				return array(
					'service'    => (string) $row['service'],
					'last_error' => (string) $row['last_error'],
					'n'          => (int) $row['n'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Number of due pending rows of a service.
	 *
	 * @param string $service Service.
	 * @return int
	 */
	public function due_count( $service ) {
		global $wpdb;
		$table = Tables::queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND service = %s AND available_at <= %s", $service, self::now() ) );
	}

	/**
	 * Lists rows.
	 *
	 * @param array $filters service (string[]), status (string[]), search, object_type, object_id, hold, page, per_page, orderby, order.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::queue();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );

		$services = array_values( array_intersect( (array) ( $filters['service'] ?? array() ), self::SERVICES ) );
		if ( $services ) {
			$where[] = 'service IN (' . implode( ',', array_fill( 0, count( $services ), '%s' ) ) . ')';
			$params  = array_merge( $params, $services );
		}
		$statuses = array_values( array_intersect( (array) ( $filters['status'] ?? array() ), self::STATUSES ) );
		if ( $statuses ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}
		if ( ! empty( $filters['hold'] ) ) {
			$where[]  = 'last_error = %s';
			$params[] = self::HOLD_PREFIX . sanitize_key( (string) $filters['hold'] );
		}
		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = (string) $filters['object_type'];
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $filters['object_id'];
		}
		if ( isset( $filters['search'] ) && '' !== (string) $filters['search'] ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( Url::encode_iri( (string) $filters['search'] ) ) . '%';
		}
		$orderable = array( 'id', 'created_at', 'updated_at', 'completed_at', 'available_at', 'priority', 'attempts', 'status', 'service' );
		$orderby   = in_array( $filters['orderby'] ?? '', $orderable, true ) ? $filters['orderby'] : 'id';
		$order     = 'ASC' === strtoupper( (string) ( $filters['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$rows   = (array) $rows;
		$latest = $this->latest_ids( $rows );
		$items  = array();
		foreach ( $rows as $row ) {
			$newest = ( $latest[ $row['url_hash'] . '|' . $row['service'] ] ?? (int) $row['id'] ) === (int) $row['id'];
			$item   = $this->hydrate( $row );
			// Retry is offered only for finished rows that are still the latest notification of their URL.
			$item['can_retry'] = $newest && in_array( $row['status'], array( 'failed', 'cancelled', 'skipped' ), true );
			$items[]           = $item;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Aggregate counts.
	 *
	 * @return array{pending:int,held:int,processing:int,failed:int,failed_24h:int,done_24h:int,by_service:array,held_by_reason:array,next_attempt_at:string|null}
	 */
	public function stats() {
		global $wpdb;
		$table = Tables::queue();
		$since = self::now( -DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT service, status, COUNT(*) AS n, SUM(CASE WHEN completed_at >= %s THEN 1 ELSE 0 END) AS recent FROM {$table} GROUP BY service, status", $since ), ARRAY_A );

		$out = array(
			'pending'    => 0,
			'held'       => 0,
			'processing' => 0,
			'failed'     => 0,
			'failed_24h' => 0,
			'done_24h'   => 0,
			'by_service' => array(),
		);
		foreach ( self::SERVICES as $service ) {
			$out['by_service'][ $service ] = array(
				'pending'    => 0,
				'held'       => 0,
				'done_24h'   => 0,
				'failed_24h' => 0,
			);
		}
		foreach ( (array) $rows as $row ) {
			$service = (string) $row['service'];
			$status  = (string) $row['status'];
			$n       = (int) $row['n'];
			$recent  = (int) $row['recent'];
			if ( isset( $out[ $status ] ) && in_array( $status, array( 'pending', 'held', 'processing', 'failed' ), true ) ) {
				$out[ $status ] += $n;
			}
			if ( 'failed' === $status ) {
				$out['failed_24h'] += $recent;
			}
			if ( 'done' === $status ) {
				$out['done_24h'] += $recent;
			}
			if ( ! isset( $out['by_service'][ $service ] ) ) {
				continue;
			}
			if ( 'pending' === $status || 'held' === $status ) {
				$out['by_service'][ $service ][ $status ] += $n;
			} elseif ( 'done' === $status ) {
				$out['by_service'][ $service ]['done_24h'] += $recent;
			} elseif ( 'failed' === $status ) {
				$out['by_service'][ $service ]['failed_24h'] += $recent;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$holds                 = $wpdb->get_results( "SELECT last_error AS hold, COUNT(*) AS n FROM {$table} WHERE status = 'held' GROUP BY last_error", ARRAY_A );
		$out['held_by_reason'] = array();
		foreach ( (array) $holds as $hold ) {
			$code                           = self::hold_code( (string) $hold['hold'] );
			$code                           = '' !== $code ? $code : 'approval';
			$out['held_by_reason'][ $code ] = ( $out['held_by_reason'][ $code ] ?? 0 ) + (int) $hold['n'];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$next                   = $wpdb->get_var( "SELECT MIN(available_at) FROM {$table} WHERE status = 'pending'" );
		$out['next_attempt_at'] = $next ? mysql_to_rfc3339( $next ) : null;
		return $out;
	}

	/**
	 * Hold code from a last_error value.
	 *
	 * @param string $value Stored value.
	 * @return string '' when not a hold marker.
	 */
	public static function hold_code( $value ) {
		$value = (string) $value;
		return 0 === strpos( $value, self::HOLD_PREFIX ) ? substr( $value, strlen( self::HOLD_PREFIX ) ) : '';
	}

	/**
	 * Row for the API.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		foreach ( array( 'id', 'priority', 'attempts', 'max_attempts', 'object_id', 'response_code' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		$hold               = self::hold_code( (string) ( $row['last_error'] ?? '' ) );
		$row['hold_reason'] = 'held' === ( $row['status'] ?? '' ) ? ( '' !== $hold ? $hold : 'approval' ) : '';
		if ( '' !== $hold ) {
			$row['last_error'] = null;
		}
		$row['display_url'] = Url::display( (string) ( $row['url'] ?? '' ) );
		foreach ( array( 'available_at', 'locked_at', 'created_at', 'updated_at', 'completed_at' ) as $date ) {
			$row[ $date ] = ! empty( $row[ $date ] ) ? mysql_to_rfc3339( $row[ $date ] ) : null;
		}
		unset( $row['url_hash'], $row['dedupe_key'] );
		return $row;
	}
}
