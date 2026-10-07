<?php
/**
 * Internal link suggestion storage.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Links;

use SearchPilot\Database\Tables;
use SearchPilot\Seo\Analysis\ContentRenderer;

defined( 'ABSPATH' ) || exit;

/**
 * One suggestion per (source, target) pair. Statuses:
 * pending (waiting for approval), applied (link inserted), dismissed (never
 * suggest again), stale (content changed; anchor gone), failed (insertion
 * failed). Direction is relative to the triggering post: outbound = the
 * trigger links to others, inbound = others link to the trigger. Kind:
 * inline (a phrase in the text becomes the link) or related (an entry with
 * the target's title in the related-articles list at the end of the source).
 */
class SuggestionRepository {

	const STATUSES = array( 'pending', 'applied', 'dismissed', 'stale', 'failed' );

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Row by pair.
	 *
	 * @param int $source_id Source.
	 * @param int $target_id Target.
	 * @return array|null
	 */
	public function find_pair( $source_id, $target_id ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d AND target_id = %d", (int) $source_id, (int) $target_id ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Creates or refreshes a suggestion. Applied and dismissed pairs are left alone.
	 *
	 * @param array $s source_id, target_id, anchor, context, score, direction, trigger_id.
	 * @return int Suggestion id (0 when skipped).
	 */
	public function upsert( array $s ) {
		global $wpdb;
		$table    = Tables::link_suggestions();
		$now      = current_time( 'mysql', true );
		$fields   = array(
			'kind'       => 'related' === ( $s['kind'] ?? '' ) ? 'related' : 'inline',
			'anchor'     => mb_substr( (string) $s['anchor'], 0, 250, 'UTF-8' ),
			'context'    => mb_substr( (string) ( $s['context'] ?? '' ), 0, 600, 'UTF-8' ),
			'score'      => round( max( 0.0, min( 1.0, (float) ( $s['score'] ?? 0 ) ) ), 4 ),
			'direction'  => 'inbound' === ( $s['direction'] ?? '' ) ? 'inbound' : 'outbound',
			'trigger_id' => (int) ( $s['trigger_id'] ?? 0 ),
			'updated_at' => $now,
		);
		$existing = $this->find_pair( (int) $s['source_id'], (int) $s['target_id'] );
		if ( $existing ) {
			if ( in_array( $existing['status'], array( 'applied', 'dismissed' ), true ) ) {
				return 0;
			}
			$fields['status'] = 'pending';
			$wpdb->update( $table, $fields, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $existing['id'];
		}
		$fields['source_id']  = (int) $s['source_id'];
		$fields['target_id']  = (int) $s['target_id'];
		$fields['status']     = 'pending';
		$fields['created_at'] = $now;
		$ok                   = $wpdb->insert( $table, $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Changes the status.
	 *
	 * @param int    $id          Id.
	 * @param string $status      Status.
	 * @param int    $activity_id Activity id (applied).
	 * @return bool
	 */
	public function set_status( $id, $status, $activity_id = null ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$fields = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $activity_id ) {
			$fields['activity_id'] = (int) $activity_id;
		}
		return false !== $wpdb->update( Tables::link_suggestions(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lists suggestions.
	 *
	 * @param array $filters post_id, direction (outbound|inbound), status (string|'all'), trigger_id, page, per_page.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::link_suggestions();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );

		$status = (string) ( $filters['status'] ?? 'pending' );
		if ( 'all' !== $status && '' !== $status ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}
		$post_id = (int) ( $filters['post_id'] ?? 0 );
		if ( $post_id ) {
			$direction = (string) ( $filters['direction'] ?? '' );
			if ( 'outbound' === $direction ) {
				$where[]  = 's.source_id = %d';
				$params[] = $post_id;
			} elseif ( 'inbound' === $direction ) {
				$where[]  = 's.target_id = %d';
				$params[] = $post_id;
			} else {
				$where[]  = '( s.source_id = %d OR s.target_id = %d )';
				$params[] = $post_id;
				$params[] = $post_id;
			}
		} elseif ( ! empty( $filters['direction'] ) && in_array( $filters['direction'], array( 'outbound', 'inbound' ), true ) ) {
			$where[]  = 's.direction = %s';
			$params[] = (string) $filters['direction'];
		}
		if ( ! empty( $filters['trigger_id'] ) ) {
			$where[]  = 's.trigger_id = %d';
			$params[] = (int) $filters['trigger_id'];
		}
		// Only suggestions between posts that still exist and are published.
		$where[]   = "src.post_status = 'publish' AND tgt.post_status = 'publish'";
		$where_sql = implode( ' AND ', $where );
		$from      = "{$table} s INNER JOIN {$wpdb->posts} src ON src.ID = s.source_id INNER JOIN {$wpdb->posts} tgt ON tgt.ID = s.target_id";
		$offset    = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$from} WHERE {$where_sql} ORDER BY s.trigger_id DESC, s.score DESC, s.id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( $this, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Formats a row for the API (contract shape plus trigger and edit info).
	 *
	 * @param array $row          Row.
	 * @param int   $perspective  Post the list is viewed from (direction relative to it); 0 = stored direction.
	 * @return array
	 */
	public function present( array $row, $perspective = 0 ) {
		$direction = $row['direction'];
		if ( $perspective ) {
			$direction = (int) $row['source_id'] === (int) $perspective ? 'outbound' : 'inbound';
		}
		$source  = (int) $row['source_id'];
		$target  = (int) $row['target_id'];
		$trigger = (int) $row['trigger_id'];
		$builder = ContentRenderer::builder_of( $source );
		$kind    = 'related' === ( $row['kind'] ?? '' ) ? 'related' : 'inline';
		$context = (string) $row['context'];
		if ( 'related' === $kind && '' === $context ) {
			/* translators: 1: target title, 2: source title. */
			$context = sprintf( __( 'No sentence in “%2$s” mentions this topic, so “%1$s” is added to a short related-articles list at the end of the post.', 'searchpilot-pro' ), Suggester::plain_title( $target ), Suggester::plain_title( $source ) );
		}
		return array(
			'id'            => (int) $row['id'],
			'kind'          => $kind,
			'source_id'     => $source,
			'source_title'  => Suggester::plain_title( $source ),
			'source_edit'   => (string) get_edit_post_link( $source, 'raw' ),
			'source_url'    => (string) get_permalink( $source ),
			'target_id'     => $target,
			'target_title'  => Suggester::plain_title( $target ),
			'target_url'    => (string) get_permalink( $target ),
			'anchor'        => (string) $row['anchor'],
			'context'       => $context,
			'score'         => (float) $row['score'],
			'direction'     => $direction,
			'status'        => (string) $row['status'],
			'trigger_id'    => $trigger,
			'trigger_title' => $trigger ? Suggester::plain_title( $trigger ) : '',
			'manual'        => '' !== $builder,
			'builder'       => ContentRenderer::builder_name( $builder ),
			'can_apply'     => current_user_can( 'edit_post', $source ),
			'activity_id'   => (int) $row['activity_id'],
			'created_at'    => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}

	/**
	 * Pending suggestions where a post is the source (highest score first).
	 *
	 * @param int $source_id Source.
	 * @return array[]
	 */
	public function pending_for_source( $source_id ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_id = %d AND status = 'pending' ORDER BY score DESC, id ASC", (int) $source_id ), ARRAY_A );
		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Applied suggestions whose link was inserted into a source post.
	 *
	 * @param int $source_id Source.
	 * @return int
	 */
	public function applied_count( $source_id ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND status = 'applied'", (int) $source_id ) );
	}

	/**
	 * Pending/applied anchors pointing to a target (lower-case anchor => count).
	 *
	 * @param int $target_id Target.
	 * @return array<string,int>
	 */
	public function anchor_uses( $target_id ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT anchor, COUNT(*) AS n FROM {$table} WHERE target_id = %d AND status = 'pending' GROUP BY anchor", (int) $target_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$anchor         = mb_strtolower( trim( (string) $row->anchor ), 'UTF-8' );
			$out[ $anchor ] = ( $out[ $anchor ] ?? 0 ) + (int) $row->n;
		}
		return $out;
	}

	/**
	 * Suggestion created for an activity entry.
	 *
	 * @param int $activity_id Activity id.
	 * @return array|null
	 */
	public function by_activity( $activity_id ) {
		global $wpdb;
		if ( ! $activity_id ) {
			return null;
		}
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE activity_id = %d LIMIT 1", (int) $activity_id ), ARRAY_A );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Every suggestion applied by one activity entry (a related-list edit can add several).
	 *
	 * @param int $activity_id Activity id.
	 * @return array[]
	 */
	public function all_by_activity( $activity_id ) {
		global $wpdb;
		if ( ! $activity_id ) {
			return array();
		}
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE activity_id = %d ORDER BY id ASC LIMIT 50", (int) $activity_id ), ARRAY_A );
		return array_map( array( $this, 'cast' ), (array) $rows );
	}

	/**
	 * Pending suggestions per kind (published posts only).
	 *
	 * @return array{inline:int,related:int}
	 */
	public function pending_by_kind() {
		global $wpdb;
		$table = Tables::link_suggestions();
		$out   = array(
			'inline'  => 0,
			'related' => 0,
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT s.kind, COUNT(*) AS n FROM {$table} s INNER JOIN {$wpdb->posts} src ON src.ID = s.source_id INNER JOIN {$wpdb->posts} tgt ON tgt.ID = s.target_id WHERE s.status = 'pending' AND src.post_status = 'publish' AND tgt.post_status = 'publish' GROUP BY s.kind" );
		foreach ( (array) $rows as $row ) {
			$out[ 'related' === $row->kind ? 'related' : 'inline' ] += (int) $row->n;
		}
		return $out;
	}

	/**
	 * Deletes suggestions from or to a post.
	 *
	 * @param int  $post_id    Post id.
	 * @param bool $keep_final Keep applied/dismissed rows (history).
	 */
	public function delete_for_post( $post_id, $keep_final = false ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		$extra = $keep_final ? " AND status NOT IN ('applied','dismissed')" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ( source_id = %d OR target_id = %d ){$extra}", (int) $post_id, (int) $post_id ) );
	}

	/**
	 * Counts per status (published posts only).
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT s.status, COUNT(*) AS n FROM {$table} s INNER JOIN {$wpdb->posts} src ON src.ID = s.source_id INNER JOIN {$wpdb->posts} tgt ON tgt.ID = s.target_id WHERE src.post_status = 'publish' AND tgt.post_status = 'publish' GROUP BY s.status" );
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Deletes old stale/failed suggestions.
	 *
	 * @param int $days Days.
	 */
	public function prune( $days = 30 ) {
		global $wpdb;
		$table = Tables::link_suggestions();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('stale','failed') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Casts a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function cast( array $row ) {
		foreach ( array( 'id', 'source_id', 'target_id', 'trigger_id', 'activity_id' ) as $int ) {
			$row[ $int ] = (int) ( $row[ $int ] ?? 0 );
		}
		$row['score'] = (float) ( $row['score'] ?? 0 );
		$row['kind']  = 'related' === ( $row['kind'] ?? '' ) ? 'related' : 'inline';
		return $row;
	}
}
