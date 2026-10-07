<?php
/**
 * Storage for per-post content fingerprints, scores and link counts.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo\Analysis;

use SearchPilot\Database\Tables;
use SearchPilot\Support\SimHash;

defined( 'ABSPATH' ) || exit;

/**
 * The `content` table has one row per post: analysis results (word count,
 * content hash, SimHash + bands, title/description hashes, score, compact
 * failing checks) and content-graph numbers (inbound/outbound links, term
 * count, indexed_at). Rows are created on demand by either side.
 */
class AnalysisRepository {

	const COLUMNS = array( 'word_count', 'content_hash', 'simhash', 'band1', 'band2', 'band3', 'band4', 'title_hash', 'description_hash', 'seo_score', 'inbound_links', 'outbound_internal', 'outbound_external', 'term_count', 'analysis', 'analyzed_at', 'indexed_at' );

	/**
	 * Row for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public function get( $post_id ) {
		global $wpdb;
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", (int) $post_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Rows for several posts.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,array>
	 */
	public function get_many( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table = Tables::content();
		$in    = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE post_id IN ({$in})", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = $this->hydrate( $row );
		}
		return $out;
	}

	/**
	 * Updates columns, creating the row when needed.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  Column => value (analysis may be an array).
	 * @param bool  $create  Insert a row when missing.
	 * @return bool
	 */
	public function update_fields( $post_id, array $fields, $create = true ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		$clean = array();
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, self::COLUMNS, true ) ) {
				continue;
			}
			if ( 'analysis' === $key && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$clean[ $key ] = $value;
		}
		if ( ! $clean ) {
			return false;
		}
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );
		if ( $exists ) {
			return false !== $wpdb->update( $table, $clean, array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( ! $create ) {
			return false;
		}
		$clean['post_id'] = $post_id;
		return false !== $wpdb->insert( $table, $clean ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Stores an analysis result.
	 *
	 * @param int    $post_id     Post id.
	 * @param int    $word_count  Words.
	 * @param string $text_hash   md5 of the plain text.
	 * @param string $simhash     SimHash (16 hex chars or '').
	 * @param string $title       Effective title.
	 * @param string $description Effective description.
	 * @param int    $score       Score 0..100.
	 * @param array  $analysis    Compact analysis (failing checks, duplicates, rendered facts).
	 * @return bool
	 */
	public function save_analysis( $post_id, $word_count, $text_hash, $simhash, $title, $description, $score, array $analysis ) {
		$bands = SimHash::bands( $simhash );
		return $this->update_fields(
			$post_id,
			array(
				'word_count'       => max( 0, (int) $word_count ),
				'content_hash'     => (string) $text_hash,
				'simhash'          => (string) $simhash,
				'band1'            => $bands[0],
				'band2'            => $bands[1],
				'band3'            => $bands[2],
				'band4'            => $bands[3],
				'title_hash'       => self::text_hash( $title ),
				'description_hash' => self::text_hash( $description ),
				'seo_score'        => max( 0, min( 100, (int) $score ) ),
				'analysis'         => $analysis,
				'analyzed_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Merges rendered-page facts (from the crawler) into the stored analysis.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $rendered Facts (h1, titles, words, checked_at).
	 * @return bool
	 */
	public function set_rendered( $post_id, array $rendered ) {
		$row      = $this->get( $post_id );
		$analysis = $row && is_array( $row['analysis'] ) ? $row['analysis'] : array();

		$analysis['rendered'] = $rendered;
		return $this->update_fields( $post_id, array( 'analysis' => $analysis ) );
	}

	/**
	 * Deletes the row of a post.
	 *
	 * @param int $post_id Post id.
	 */
	public function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( Tables::content(), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Hash used for duplicate title/description detection ('' for empty text).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text_hash( $text ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( (string) $text, 'UTF-8' ) ) );
		return '' === $text ? '' : md5( $text );
	}

	/**
	 * Published posts sharing a title or description hash.
	 *
	 * @param string $column     title_hash|description_hash.
	 * @param string $hash       Hash.
	 * @param int    $exclude_id Post to exclude.
	 * @param int    $limit      Limit.
	 * @return int[]
	 */
	public function duplicates_of( $column, $hash, $exclude_id, $limit = 10 ) {
		global $wpdb;
		if ( '' === (string) $hash || ! in_array( $column, array( 'title_hash', 'description_hash', 'content_hash' ), true ) ) {
			return array();
		}
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.post_id FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
				WHERE c.{$column} = %s AND c.post_id <> %d AND p.post_status = 'publish'
				ORDER BY c.post_id ASC LIMIT %d",
				(string) $hash,
				(int) $exclude_id,
				(int) $limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Published posts whose SimHash is within a Hamming distance.
	 *
	 * Two hashes within distance 3 always share one 16-bit band, so only
	 * rows matching a band are compared.
	 *
	 * @param string $simhash      Hash.
	 * @param int    $exclude_id   Post to exclude.
	 * @param int    $max_distance Max distance.
	 * @param int    $min_words    Ignore short texts (unstable fingerprints).
	 * @param int    $limit        Limit.
	 * @return array<int,int> post_id => distance (closest first).
	 */
	public function near_duplicates( $simhash, $exclude_id, $max_distance = 3, $min_words = 100, $limit = 10 ) {
		global $wpdb;
		if ( 16 !== strlen( (string) $simhash ) ) {
			return array();
		}
		$bands = SimHash::bands( $simhash );
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.post_id, c.simhash FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
				WHERE ( c.band1 = %d OR c.band2 = %d OR c.band3 = %d OR c.band4 = %d )
				AND c.post_id <> %d AND c.word_count >= %d AND p.post_status = 'publish' AND c.simhash <> ''
				LIMIT 500",
				$bands[0],
				$bands[1],
				$bands[2],
				$bands[3],
				(int) $exclude_id,
				(int) $min_words
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$distance = SimHash::distance( $simhash, (string) $row->simhash );
			if ( $distance <= $max_distance ) {
				$out[ (int) $row->post_id ] = $distance;
			}
		}
		asort( $out );
		return array_slice( $out, 0, $limit, true );
	}

	/**
	 * Published post ids (of the given types) after a cursor that were never
	 * analyzed or were edited since their last analysis.
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $after      Cursor (post id).
	 * @param int      $limit      Limit.
	 * @return int[]
	 */
	public function backlog_ids( array $post_types, $after, $limit ) {
		global $wpdb;
		if ( ! $post_types ) {
			return array();
		}
		$table   = Tables::content();
		$holders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$params  = array_merge( $post_types, array( (int) $after, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$table} c ON c.post_id = p.ID
				WHERE p.post_type IN ({$holders}) AND p.post_status = 'publish' AND p.ID > %d
				AND ( c.post_id IS NULL OR c.analyzed_at IS NULL OR p.post_modified_gmt > c.analyzed_at )
				ORDER BY p.ID ASC LIMIT %d",
				$params
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Queues posts for analysis (analyzed_at = NULL; rows are created as needed).
	 *
	 * @param int[] $ids Post ids.
	 */
	public function mark_for_analysis( array $ids ) {
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 ) {
				$this->update_fields( $id, array( 'analyzed_at' => null ) );
			}
		}
	}

	/**
	 * Queued posts (analyzed_at NULL), newest first, excluding trashed ones.
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function pending_analysis( $limit ) {
		global $wpdb;
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT c.post_id FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE c.analyzed_at IS NULL AND p.post_status NOT IN ('trash','auto-draft','inherit') ORDER BY c.post_id DESC LIMIT %d", (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Marks a post as processed without results (not analyzable), so queues move on.
	 *
	 * @param int $post_id Post id.
	 */
	public function touch_analyzed( $post_id ) {
		$this->update_fields( (int) $post_id, array( 'analyzed_at' => current_time( 'mysql', true ) ), false );
	}

	/**
	 * Marks every analysis as outdated (forces a full re-analysis).
	 */
	public function invalidate_all() {
		global $wpdb;
		$table = Tables::content();
		$wpdb->query( "UPDATE {$table} SET analyzed_at = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Clears the index markers of every row (full link-index rebuild).
	 */
	public function clear_index_marks() {
		global $wpdb;
		$table = Tables::content();
		$wpdb->query( "UPDATE {$table} SET indexed_at = NULL, term_count = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Number of posts in the content-graph index.
	 *
	 * @return int
	 */
	public function count_indexed() {
		global $wpdb;
		$table = Tables::content();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE indexed_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Score summary of published posts.
	 *
	 * @return array{analyzed:int,avg_score:int|null,below_50:int,buckets:array<int,array{label:string,from:int,to:int,count:int}>}
	 */
	public function stats() {
		global $wpdb;
		$table = Tables::content();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS analyzed, AVG(c.seo_score) AS avg_score,
				SUM(CASE WHEN c.seo_score < 50 THEN 1 ELSE 0 END) AS below_50,
				SUM(CASE WHEN c.seo_score < 30 THEN 1 ELSE 0 END) AS b0,
				SUM(CASE WHEN c.seo_score >= 30 AND c.seo_score < 50 THEN 1 ELSE 0 END) AS b1,
				SUM(CASE WHEN c.seo_score >= 50 AND c.seo_score < 65 THEN 1 ELSE 0 END) AS b2,
				SUM(CASE WHEN c.seo_score >= 65 AND c.seo_score < 80 THEN 1 ELSE 0 END) AS b3,
				SUM(CASE WHEN c.seo_score >= 80 AND c.seo_score < 90 THEN 1 ELSE 0 END) AS b4,
				SUM(CASE WHEN c.seo_score >= 90 THEN 1 ELSE 0 END) AS b5
			FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
			WHERE c.analyzed_at IS NOT NULL AND p.post_status = 'publish' AND p.post_password = ''",
			ARRAY_A
		);
		$row     = (array) $row;
		$ranges  = array( array( 0, 29 ), array( 30, 49 ), array( 50, 64 ), array( 65, 79 ), array( 80, 89 ), array( 90, 100 ) );
		$buckets = array();
		foreach ( $ranges as $i => $range ) {
			$buckets[] = array(
				'label' => $range[0] . '–' . $range[1],
				'from'  => $range[0],
				'to'    => $range[1],
				'count' => (int) ( $row[ 'b' . $i ] ?? 0 ),
			);
		}
		$analyzed = (int) ( $row['analyzed'] ?? 0 );
		return array(
			'analyzed'  => $analyzed,
			'avg_score' => $analyzed ? (int) round( (float) $row['avg_score'] ) : null,
			'below_50'  => (int) ( $row['below_50'] ?? 0 ),
			'buckets'   => $buckets,
		);
	}

	/**
	 * Lowest-scoring published posts.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array{items:array,total:int}
	 */
	public function lowest( $page, $per_page ) {
		global $wpdb;
		$table  = Tables::content();
		$offset = ( max( 1, (int) $page ) - 1 ) * (int) $per_page;
		$where  = "c.analyzed_at IS NOT NULL AND p.post_status = 'publish' AND p.post_password = ''";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.* FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id WHERE {$where} ORDER BY c.seo_score ASC, c.post_id DESC LIMIT %d OFFSET %d",
				(int) $per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Formats a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function hydrate( array $row ) {
		foreach ( array( 'post_id', 'word_count', 'band1', 'band2', 'band3', 'band4', 'seo_score', 'inbound_links', 'outbound_internal', 'outbound_external', 'term_count' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		$analysis        = isset( $row['analysis'] ) ? json_decode( (string) $row['analysis'], true ) : null;
		$row['analysis'] = is_array( $analysis ) ? $analysis : array();
		return $row;
	}
}
