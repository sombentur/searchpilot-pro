<?php
/**
 * System log endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Database\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * GET /logs, GET /logs/channels, DELETE /logs. Entries are already redacted when written.
 */
class LogsController extends Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/logs', 'GET', array( $this, 'list_logs' ), 'manage' );
		$this->route( '/logs', 'DELETE', array( $this, 'clear_logs' ), 'manage' );
		$this->route( '/logs/channels', 'GET', array( $this, 'channels' ), 'manage' );
	}

	/**
	 * GET /logs.
	 *
	 * @param \WP_REST_Request $request Request (level, channel, search, since, page, per_page).
	 * @return array
	 */
	public function list_logs( \WP_REST_Request $request ) {
		global $wpdb;
		list( $page, $per_page ) = $this->paging( $request, 50 );
		$table                   = Tables::logs();
		$where                   = array( '1=1' );
		$params                  = array();

		$level = sanitize_key( (string) $request->get_param( 'level' ) );
		if ( '' !== $level ) {
			$levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' );
			$index  = array_search( $level, $levels, true );
			if ( false !== $index ) {
				$min     = array_slice( $levels, $index );
				$where[] = 'level IN (' . implode( ',', array_fill( 0, count( $min ), '%s' ) ) . ')';
				$params  = array_merge( $params, $min );
			}
		}
		$channel = sanitize_key( (string) $request->get_param( 'channel' ) );
		if ( '' !== $channel ) {
			$where[]  = 'channel = %s';
			$params[] = $channel;
		}
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		$items = array();
		foreach ( (array) $rows as $row ) {
			$context = $row->context ? json_decode( $row->context, true ) : null;
			$items[] = array(
				'id'         => (int) $row->id,
				'level'      => $row->level,
				'channel'    => $row->channel,
				'message'    => $row->message,
				'context'    => is_array( $context ) ? $context : null,
				'created_at' => mysql_to_rfc3339( $row->created_at ),
			);
		}
		return $this->paged( $items, $total, $page, $per_page );
	}

	/**
	 * GET /logs/channels.
	 *
	 * @return array
	 */
	public function channels() {
		global $wpdb;
		$table = Tables::logs();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'channels' => array_values( array_filter( (array) $wpdb->get_col( "SELECT DISTINCT channel FROM {$table} ORDER BY channel" ) ) ) );
	}

	/**
	 * DELETE /logs.
	 *
	 * @return array
	 */
	public function clear_logs() {
		global $wpdb;
		$table = Tables::logs();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array( 'cleared' => true );
	}
}
