<?php
/**
 * Stored search performance: daily series and query/page snapshots.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

use SearchPilot\Container;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Database\Tables;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the `perf_daily` (site totals per day and engine) and
 * `perf_rows` (query / page / query+page snapshots per range, each row
 * holding the current and the previous equal-length period) tables.
 *
 * Every read works on stored data — no API calls — except page_series(),
 * which asks Search Console for one page's daily trend on demand and caches
 * it for 12 hours.
 */
class PerformanceRepository {

	const ENGINES    = array( 'google', 'bing' );
	const DIMENSIONS = array( 'query', 'page', 'query_page' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * URL resolver.
	 *
	 * @var UrlResolver
	 */
	private $resolver;

	/**
	 * Container (API clients are resolved lazily and may not exist).
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Per-request cache of the first/last stored day per engine.
	 *
	 * @var array<string,array{0:string|null,1:string|null}>
	 */
	private $bounds = array();

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings  Settings.
	 * @param State       $state     State.
	 * @param UrlResolver $resolver  URL resolver.
	 * @param Container   $container Container.
	 */
	public function __construct( Settings $settings, State $state, UrlResolver $resolver, Container $container ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->resolver  = $resolver;
		$this->container = $container;
	}

	/* --------------------------------------------------------------------
	 * API clients (optional modules)
	 * ------------------------------------------------------------------ */

	/**
	 * Connected Search Console client or null.
	 *
	 * @return object|null
	 */
	public function google() {
		return $this->client( '\SearchPilot\Api\Google\SearchConsole' );
	}

	/**
	 * Connected Bing Webmaster client or null.
	 *
	 * @return object|null
	 */
	public function bing() {
		return $this->client( '\SearchPilot\Api\Bing\BingWebmaster' );
	}

	/**
	 * Connection flags.
	 *
	 * @return array{google:bool,bing:bool}
	 */
	public function connections() {
		return array(
			'google' => null !== $this->google(),
			'bing'   => null !== $this->bing(),
		);
	}

	/**
	 * Resolves an API client when its module is installed and connected.
	 *
	 * @param string $class Class.
	 * @return object|null
	 */
	private function client( $class ) {
		$class = ltrim( $class, '\\' );
		if ( ! $this->container->has( $class ) ) {
			return null;
		}
		try {
			$client = $this->container->get( $class );
			return is_object( $client ) && method_exists( $client, 'is_connected' ) && $client->is_connected() ? $client : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/* --------------------------------------------------------------------
	 * Writes (sync)
	 * ------------------------------------------------------------------ */

	/**
	 * Inserts or replaces daily totals.
	 *
	 * @param string $engine Engine.
	 * @param array  $rows   Rows: date (Y-m-d), clicks, impressions, ctr (optional), position.
	 * @return int Rows written.
	 */
	public function upsert_daily( $engine, array $rows ) {
		global $wpdb;
		$table   = Tables::perf_daily();
		$now     = current_time( 'mysql', true );
		$written = 0;
		$clean   = array();
		unset( $this->bounds[ $engine ] );
		foreach ( $rows as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			if ( ! Ranges::is_date( $date ) ) {
				continue;
			}
			$clicks      = max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$impressions = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$clean[ $date ] = array(
				$date,
				$clicks,
				$impressions,
				$impressions ? $clicks / $impressions : 0.0,
				max( 0.0, (float) ( $row['position'] ?? 0 ) ),
			);
		}
		foreach ( array_chunk( array_values( $clean ), 100 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%s,%s,%d,%d,%f,%f,%s)';
				array_push( $params, $engine, $r[0], $r[1], $r[2], $r[3], $r[4], $now );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ok = $wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (engine, date, clicks, impressions, ctr, position, updated_at) VALUES " . implode( ',', $values ), $params ) );
			if ( false !== $ok ) {
				$written += count( $chunk );
			}
		}
		return $written;
	}

	/**
	 * Replaces one snapshot (engine + range + dimension) atomically enough:
	 * new rows are written first, then rows from older fetches are deleted,
	 * so readers never see an empty snapshot.
	 *
	 * @param string $engine     Engine.
	 * @param string $range      Range key.
	 * @param string $dimension  query|page|query_page.
	 * @param array  $rows       Merged rows: query, page, object_id, clicks, impressions, ctr, position, prev_*.
	 * @param string $fetched_at MySQL UTC datetime of this fetch.
	 * @return int Rows written.
	 */
	public function replace_rows( $engine, $range, $dimension, array $rows, $fetched_at ) {
		global $wpdb;
		$table   = Tables::perf_rows();
		$written = 0;
		$seen    = array();
		$clean   = array();
		foreach ( $rows as $row ) {
			$query = mb_substr( (string) ( $row['query'] ?? '' ), 0, 255, 'UTF-8' );
			$page  = 'query' === $dimension ? '' : Url::normalize( (string) ( $row['page'] ?? '' ) );
			$hash  = self::row_hash( $dimension, $query, $page );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$clean[]       = array(
				$hash,
				$query,
				$page,
				(int) ( $row['object_id'] ?? 0 ),
				max( 0, (int) ( $row['clicks'] ?? 0 ) ),
				max( 0, (int) ( $row['impressions'] ?? 0 ) ),
				(float) ( $row['ctr'] ?? 0 ),
				(float) ( $row['position'] ?? 0 ),
				max( 0, (int) ( $row['prev_clicks'] ?? 0 ) ),
				max( 0, (int) ( $row['prev_impressions'] ?? 0 ) ),
				(float) ( $row['prev_ctr'] ?? 0 ),
				(float) ( $row['prev_position'] ?? 0 ),
			);
		}
		foreach ( array_chunk( $clean, 100 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $r ) {
				$values[] = '(%s,%s,%s,%s,%s,%s,%d,%d,%d,%f,%f,%d,%d,%f,%f,%s)';
				array_push( $params, $engine, $range, $dimension, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[10], $r[11], $fetched_at );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ok = $wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (engine, range_key, dimension, row_hash, query, page, object_id, clicks, impressions, ctr, position, prev_clicks, prev_impressions, prev_ctr, prev_position, fetched_at) VALUES " . implode( ',', $values ), $params ) );
			if ( false !== $ok ) {
				$written += count( $chunk );
			}
		}
		if ( $written === count( $clean ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE engine = %s AND range_key = %s AND dimension = %s AND fetched_at < %s", $engine, $range, $dimension, $fetched_at ) );
		}
		return $written;
	}

	/**
	 * Stable row identity.
	 *
	 * @param string $dimension Dimension.
	 * @param string $query     Query.
	 * @param string $page      Page URL.
	 * @return string
	 */
	public static function row_hash( $dimension, $query, $page ) {
		return sha1( $dimension . '|' . $query . '|' . ( '' !== (string) $page ? Url::normalize( $page ) : '' ) );
	}

	/**
	 * Records the window of a stored snapshot.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @param array  $info   from, to, prev_from, prev_to, fetched_at, counts (dimension => rows), note.
	 */
	public function set_snapshot( $engine, $range, array $info ) {
		$all = (array) $this->state->get( 'analytics_snapshots', array() );

		$existing                 = isset( $all[ $engine ][ $range ] ) ? (array) $all[ $engine ][ $range ] : array();
		$info['counts']           = array_merge( (array) ( $existing['counts'] ?? array() ), (array) ( $info['counts'] ?? array() ) );
		$all[ $engine ][ $range ] = array_merge( $existing, $info );
		$this->state->set( 'analytics_snapshots', $all );
	}

	/**
	 * Snapshot info or null.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @return array|null
	 */
	public function snapshot( $engine, $range ) {
		$all = (array) $this->state->get( 'analytics_snapshots', array() );
		return isset( $all[ $engine ][ $range ] ) ? (array) $all[ $engine ][ $range ] : null;
	}

	/**
	 * Whether a snapshot's previous period is fully covered, so its deltas
	 * (and rising/declining lists) mean something. Older snapshots without
	 * the flag are judged by the stored history.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @return bool
	 */
	public function prev_complete( $engine, $range ) {
		$snapshot = $this->snapshot( $engine, $range );
		if ( ! $snapshot ) {
			return false;
		}
		if ( isset( $snapshot['prev_complete'] ) ) {
			return (bool) $snapshot['prev_complete'];
		}
		$earliest = $this->earliest_date( $engine );
		return $earliest && ! empty( $snapshot['prev_from'] ) && $earliest <= (string) $snapshot['prev_from'];
	}

	/* --------------------------------------------------------------------
	 * Daily series
	 * ------------------------------------------------------------------ */

	/**
	 * Latest day with data.
	 *
	 * @param string $engine Engine.
	 * @return string|null Y-m-d.
	 */
	public function latest_date( $engine ) {
		return $this->bounds( $engine )[1];
	}

	/**
	 * First and last stored day of an engine (cached for the request).
	 *
	 * @param string $engine Engine.
	 * @return array{0:string|null,1:string|null}
	 */
	private function bounds( $engine ) {
		global $wpdb;
		if ( ! isset( $this->bounds[ $engine ] ) ) {
			$table = Tables::perf_daily();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date) AS mn, MAX(date) AS mx FROM {$table} WHERE engine = %s", $engine ), ARRAY_A );

			$this->bounds[ $engine ] = array(
				! empty( $row['mn'] ) ? substr( (string) $row['mn'], 0, 10 ) : null,
				! empty( $row['mx'] ) ? substr( (string) $row['mx'], 0, 10 ) : null,
			);
		}
		return $this->bounds[ $engine ];
	}

	/**
	 * How much of a window the stored daily data covers.
	 *
	 * @param string $engine Engine.
	 * @param array  $window Window (from, to, days).
	 * @return array{days:int,covered_days:int,complete:bool,covered_from:string|null,covered_to:string|null}
	 */
	public function coverage( $engine, array $window ) {
		list( $earliest, $latest ) = $this->bounds( $engine );
		return Ranges::coverage( $window, $earliest, $latest );
	}

	/**
	 * Earliest day with data.
	 *
	 * @param string $engine Engine.
	 * @return string|null
	 */
	public function earliest_date( $engine ) {
		return $this->bounds( $engine )[0];
	}

	/**
	 * Whether any data is stored for an engine.
	 *
	 * @param string $engine Engine.
	 * @return bool
	 */
	public function has_data( $engine ) {
		return null !== $this->latest_date( $engine );
	}

	/**
	 * Last day of the reporting windows: the latest day with data, else today minus the lag.
	 *
	 * @param string $engine Engine.
	 * @return string
	 */
	public function end_date( $engine ) {
		$latest = $this->latest_date( $engine );
		if ( $latest ) {
			return $latest;
		}
		return Ranges::end_date( wp_date( 'Y-m-d' ), (bool) $this->settings->get( 'analytics.fresh_data', false ) );
	}

	/**
	 * Current / previous / year-over-year windows for an engine's data.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range key.
	 * @return array{current:array,previous:array,yoy:array}
	 */
	public function windows( $engine, $range ) {
		return Ranges::windows( Ranges::sanitize( $range ), $this->end_date( $engine ) );
	}

	/**
	 * Totals between two days.
	 *
	 * @param string $engine Engine.
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @return array{clicks:int,impressions:int,ctr:float,position:float,days:int}
	 */
	public function totals_between( $engine, $from, $to ) {
		global $wpdb;
		$table = Tables::perf_daily();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions,
					SUM(CASE WHEN position > 0 THEN position * impressions ELSE 0 END) AS pw,
					SUM(CASE WHEN position > 0 THEN impressions ELSE 0 END) AS pi,
					COUNT(*) AS days
				FROM {$table} WHERE engine = %s AND date BETWEEN %s AND %s",
				$engine,
				$from,
				$to
			),
			ARRAY_A
		);
		$row         = (array) $row;
		$clicks      = (int) ( $row['clicks'] ?? 0 );
		$impressions = (int) ( $row['impressions'] ?? 0 );
		$pi          = (float) ( $row['pi'] ?? 0 );
		return array(
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions ? round( $clicks / $impressions, 5 ) : 0.0,
			'position'    => $pi > 0 ? round( (float) $row['pw'] / $pi, 2 ) : 0.0,
			'days'        => (int) ( $row['days'] ?? 0 ),
		);
	}

	/**
	 * Totals for a range with the previous period and the same period last year.
	 *
	 * Each comparison carries its coverage: `complete` is false when stored
	 * history (Search Console keeps ~16 months; new properties less) does not
	 * reach back over the whole window, and `covered_days` says how much does.
	 * Callers must not show a change against an incomplete period — `comparable`
	 * sums that up. yoy is null when no stored day falls into it.
	 *
	 * @param string $engine Engine (google|bing).
	 * @param string $range  Range key.
	 * @return array{clicks:int,impressions:int,ctr:float,position:float,prev:array,yoy:array|null,from:string,to:string,comparable:bool,complete:bool,covered_days:int}
	 */
	public function totals( $engine, $range ) {
		$range   = Ranges::sanitize( $range );
		$windows = $this->windows( $engine, $range );
		$current = $this->totals_between( $engine, $windows['current']['from'], $windows['current']['to'] );
		$prev    = $this->totals_between( $engine, $windows['previous']['from'], $windows['previous']['to'] );
		$yoy     = $this->totals_between( $engine, $windows['yoy']['from'], $windows['yoy']['to'] );
		$cov_now = $this->coverage( $engine, $windows['current'] );
		$cov_pre = $this->coverage( $engine, $windows['previous'] );
		$cov_yoy = $this->coverage( $engine, $windows['yoy'] );
		$keep    = array_flip( array( 'complete', 'covered_days', 'covered_from', 'window_days' ) );
		$cov_now = array_merge( $cov_now, array( 'window_days' => $cov_now['days'] ) );
		$cov_pre = array_merge( $cov_pre, array( 'window_days' => $cov_pre['days'] ) );
		$cov_yoy = array_merge( $cov_yoy, array( 'window_days' => $cov_yoy['days'] ) );
		return array_merge(
			$current,
			array_intersect_key( $cov_now, $keep ),
			array(
				'prev'       => array_merge( $prev, array_intersect_key( $cov_pre, $keep ), array( 'from' => $windows['previous']['from'], 'to' => $windows['previous']['to'] ) ),
				'yoy'        => $cov_yoy['covered_days'] > 0 ? array_merge( $yoy, array_intersect_key( $cov_yoy, $keep ), array( 'from' => $windows['yoy']['from'], 'to' => $windows['yoy']['to'] ) ) : null,
				'from'       => $windows['current']['from'],
				'to'         => $windows['current']['to'],
				'range'      => $range,
				'engine'     => $engine,
				'has_data'   => $current['days'] > 0,
				'comparable' => $cov_now['complete'] && $cov_pre['complete'],
				'history'    => array(
					'earliest' => $this->earliest_date( $engine ),
					'latest'   => $this->latest_date( $engine ),
				),
			)
		);
	}

	/**
	 * Chart series for a range, aligned day by day (weekly buckets for 6m/12m)
	 * with an optional comparison period.
	 *
	 * @param string $engine  Engine.
	 * @param string $range   Range key.
	 * @param string $compare previous|yoy|none.
	 * @return array
	 */
	public function series( $engine, $range, $compare = 'previous' ) {
		$range   = Ranges::sanitize( $range );
		$windows = $this->windows( $engine, $range );
		$compare = in_array( $compare, array( 'previous', 'yoy', 'none' ), true ) ? $compare : 'previous';
		$other   = 'none' === $compare ? null : ( 'yoy' === $compare ? $windows['yoy'] : $windows['previous'] );
		return $this->series_between( $engine, $windows['current']['from'], $windows['current']['to'], 'week' === Ranges::bucket( $range ) ? 7 : 1, $other, $compare );
	}

	/**
	 * Chart series between two days.
	 *
	 * @param string     $engine  Engine.
	 * @param string     $from    First day.
	 * @param string     $to      Last day.
	 * @param int        $size    Bucket size in days (1 or 7).
	 * @param array|null $other   Comparison window (same length) or null.
	 * @param string     $compare Comparison label.
	 * @return array{labels:string[],bucket:string,from:string,to:string,clicks:array,impressions:array,ctr:array,position:array,compare:array|null}
	 */
	public function series_between( $engine, $from, $to, $size = 1, $other = null, $compare = 'previous' ) {
		$daily    = $this->daily_rows( $engine, $from, $to );
		$buckets  = Ranges::buckets( $from, $to, $size );
		$earliest = $this->earliest_date( $engine );
		$out      = array_merge(
			array(
				'labels' => wp_list_pluck( $buckets, 'label' ),
				'bucket' => $size > 1 ? 'week' : 'day',
				'from'   => $from,
				'to'     => $to,
			),
			self::aggregate_buckets( $buckets, $daily, $earliest )
		);

		$out['compare'] = null;
		if ( $other ) {
			$other_rows    = $this->daily_rows( $engine, $other['from'], $other['to'] );
			$other_buckets = Ranges::buckets( $other['from'], $other['to'], $size );
			$coverage       = $this->coverage( $engine, $other );
			$out['compare'] = array_merge(
				array(
					'type'         => $compare,
					'from'         => $other['from'],
					'to'           => $other['to'],
					'labels'       => wp_list_pluck( $other_buckets, 'label' ),
					'complete'     => $coverage['complete'],
					'covered_days' => $coverage['covered_days'],
				),
				self::aggregate_buckets( $other_buckets, $other_rows, $earliest )
			);
			if ( ! $other_rows || ! $coverage['covered_days'] ) {
				$out['compare']['empty'] = true;
			}
		}
		return $out;
	}

	/**
	 * Sums daily rows into buckets. Buckets that start before the stored
	 * history are null (unknown), not zero, so charts never show a fake dip.
	 *
	 * @param array       $buckets  Buckets (from, to).
	 * @param array       $daily    date => row.
	 * @param string|null $earliest First stored day (null = do not clamp).
	 * @return array{clicks:array,impressions:array,ctr:array,position:array}
	 */
	public static function aggregate_buckets( array $buckets, array $daily, $earliest = null ) {
		$out = array(
			'clicks'      => array(),
			'impressions' => array(),
			'ctr'         => array(),
			'position'    => array(),
		);
		foreach ( $buckets as $bucket ) {
			if ( $earliest && $bucket['from'] < $earliest ) {
				$out['clicks'][]      = null;
				$out['impressions'][] = null;
				$out['ctr'][]         = null;
				$out['position'][]    = null;
				continue;
			}
			$clicks = 0;
			$impr   = 0;
			$pw     = 0.0;
			$pi     = 0;
			foreach ( Ranges::dates( $bucket['from'], $bucket['to'] ) as $date ) {
				if ( ! isset( $daily[ $date ] ) ) {
					continue;
				}
				$row     = $daily[ $date ];
				$clicks += (int) $row['clicks'];
				$impr   += (int) $row['impressions'];
				if ( (float) $row['position'] > 0 ) {
					$pw += (float) $row['position'] * (int) $row['impressions'];
					$pi += (int) $row['impressions'];
				}
			}
			$out['clicks'][]      = $clicks;
			$out['impressions'][] = $impr;
			$out['ctr'][]         = $impr ? round( $clicks / $impr, 5 ) : null;
			$out['position'][]    = $pi ? round( $pw / $pi, 2 ) : null;
		}
		return $out;
	}

	/**
	 * Daily rows keyed by date.
	 *
	 * @param string $engine Engine.
	 * @param string $from   From.
	 * @param string $to     To.
	 * @return array<string,array>
	 */
	public function daily_rows( $engine, $from, $to ) {
		global $wpdb;
		$table = Tables::perf_daily();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT date, clicks, impressions, ctr, position FROM {$table} WHERE engine = %s AND date BETWEEN %s AND %s ORDER BY date ASC", $engine, $from, $to ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ substr( (string) $row['date'], 0, 10 ) ] = $row;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Snapshot rows
	 * ------------------------------------------------------------------ */

	/**
	 * Paged snapshot rows with deltas.
	 *
	 * @param string $engine    Engine.
	 * @param string $range     Range key.
	 * @param string $dimension query|page|query_page.
	 * @param array  $args      search, orderby (clicks|impressions|ctr|position|delta_clicks|delta_impressions),
	 *                          order (asc|desc), page, per_page, min_impressions, object_id, include_lost (bool),
	 *                          change (rising|declining).
	 * @return array{items:array,total:int}
	 */
	public function rows( $engine, $range, $dimension, array $args = array() ) {
		global $wpdb;
		$table     = Tables::perf_rows();
		$dimension = in_array( $dimension, self::DIMENSIONS, true ) ? $dimension : 'query';
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = min( 500, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
		$compare   = $this->prev_complete( (string) $engine, Ranges::sanitize( $range ) );
		if ( ! empty( $args['change'] ) && ! $compare ) {
			// Rising/declining needs a fully covered previous period.
			return array(
				'items'         => array(),
				'total'         => 0,
				'prev_complete' => false,
			);
		}
		$where     = array( 'engine = %s', 'range_key = %s', 'dimension = %s' );
		$params    = array( (string) $engine, Ranges::sanitize( $range ), $dimension );

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			if ( 'query' === $dimension ) {
				$where[]  = 'query LIKE %s';
				$params[] = $like;
			} elseif ( 'page' === $dimension ) {
				$where[]  = '(page LIKE %s OR page LIKE %s)';
				$params[] = $like;
				$params[] = '%' . $wpdb->esc_like( Url::encode_iri( $search ) ) . '%';
			} else {
				$where[]  = '(query LIKE %s OR page LIKE %s)';
				$params[] = $like;
				$params[] = '%' . $wpdb->esc_like( Url::encode_iri( $search ) ) . '%';
			}
		}
		if ( ! empty( $args['min_impressions'] ) ) {
			$where[]  = 'impressions >= %d';
			$params[] = (int) $args['min_impressions'];
		}
		if ( empty( $args['include_lost'] ) && empty( $args['change'] ) ) {
			$where[] = 'impressions > 0';
		}
		if ( ! empty( $args['object_id'] ) ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		if ( ! empty( $args['page_url'] ) ) {
			$where[]  = 'page = %s';
			$params[] = Url::normalize( (string) $args['page_url'] );
		}
		if ( ! empty( $args['change'] ) ) {
			$min_impr = max( 1, (int) $this->settings->get( 'analytics.min_impressions', 100 ) );
			$where[]  = '(impressions >= %d OR prev_impressions >= %d OR clicks >= 10 OR prev_clicks >= 10)';
			$params[] = $min_impr;
			$params[] = $min_impr;
			$where[]  = 'rising' === $args['change'] ? 'clicks > prev_clicks' : 'clicks < prev_clicks';
		}

		$order   = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$orderby = (string) ( $args['orderby'] ?? 'clicks' );
		if ( ! $compare && in_array( $orderby, array( 'delta_clicks', 'delta_impressions' ), true ) ) {
			$orderby = 'delta_clicks' === $orderby ? 'clicks' : 'impressions';
		}
		switch ( $orderby ) {
			case 'impressions':
				$order_sql = "impressions {$order}, clicks DESC";
				break;
			case 'ctr':
				$order_sql = "ctr {$order}, impressions DESC";
				break;
			case 'position':
				$order_sql = "(position = 0) ASC, position {$order}, impressions DESC";
				break;
			case 'delta_clicks':
				$order_sql = "(clicks * 1.0 - prev_clicks) {$order}, clicks DESC";
				break;
			case 'delta_impressions':
				$order_sql = "(impressions * 1.0 - prev_impressions) {$order}, impressions DESC";
				break;
			case 'query':
				$order_sql = "query {$order}";
				break;
			default:
				$order_sql = "clicks {$order}, impressions DESC";
		}
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql}, id ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items'         => $this->present_rows( (array) $rows, $compare ),
			'total'         => $total,
			'prev_complete' => $compare,
		);
	}

	/**
	 * Raw snapshot rows as typed arrays (for the opportunity engine and exports).
	 *
	 * @param string $engine    Engine.
	 * @param string $range     Range.
	 * @param string $dimension Dimension.
	 * @param int    $limit     Max rows (highest impressions first).
	 * @return array[]
	 */
	public function all_rows( $engine, $range, $dimension, $limit = 10000 ) {
		global $wpdb;
		$table = Tables::perf_rows();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT query, page, object_id, clicks, impressions, ctr, position, prev_clicks, prev_impressions, prev_ctr, prev_position FROM {$table} WHERE engine = %s AND range_key = %s AND dimension = %s ORDER BY impressions DESC, prev_impressions DESC LIMIT %d", $engine, $range, $dimension, (int) $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'typed' ), (array) $rows );
	}

	/**
	 * Casts a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function typed( array $row ) {
		foreach ( array( 'object_id', 'clicks', 'impressions', 'prev_clicks', 'prev_impressions' ) as $int ) {
			if ( isset( $row[ $int ] ) ) {
				$row[ $int ] = (int) $row[ $int ];
			}
		}
		foreach ( array( 'ctr', 'position', 'prev_ctr', 'prev_position' ) as $float ) {
			if ( isset( $row[ $float ] ) ) {
				$row[ $float ] = (float) $row[ $float ];
			}
		}
		$row['query'] = (string) ( $row['query'] ?? '' );
		$row['page']  = (string) ( $row['page'] ?? '' );
		return $row;
	}

	/**
	 * Adds deltas, titles and display URLs.
	 *
	 * @param array $rows    DB rows.
	 * @param bool  $compare Whether the previous period is complete (else prev_* and deltas are null).
	 * @return array
	 */
	public function present_rows( array $rows, $compare = true ) {
		$rows = array_map( array( __CLASS__, 'typed' ), $rows );
		$ids  = array_filter( array_unique( wp_list_pluck( $rows, 'object_id' ) ) );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, false );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$item = array(
				'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'query'             => $row['query'],
				'page'              => $row['page'],
				'display_page'      => '' !== $row['page'] ? Url::display( $row['page'] ) : '',
				'object_id'         => $row['object_id'],
				'title'             => '',
				'edit_link'         => '',
				'clicks'            => $row['clicks'],
				'impressions'       => $row['impressions'],
				'ctr'               => round( $row['ctr'], 5 ),
				'position'          => round( $row['position'], 2 ),
				'prev_clicks'       => $row['prev_clicks'],
				'prev_impressions'  => $row['prev_impressions'],
				'prev_ctr'          => round( $row['prev_ctr'], 5 ),
				'prev_position'     => round( $row['prev_position'], 2 ),
				'delta_clicks'      => $row['clicks'] - $row['prev_clicks'],
				'delta_impressions' => $row['impressions'] - $row['prev_impressions'],
				'delta_ctr'         => round( $row['ctr'] - $row['prev_ctr'], 5 ),
				'delta_position'    => $row['position'] > 0 && $row['prev_position'] > 0 ? round( $row['position'] - $row['prev_position'], 2 ) : null,
			);
			if ( ! $compare ) {
				foreach ( array( 'prev_clicks', 'prev_impressions', 'prev_ctr', 'prev_position', 'delta_clicks', 'delta_impressions', 'delta_ctr', 'delta_position' ) as $key ) {
					$item[ $key ] = null;
				}
			}
			if ( $row['object_id'] ) {
				$post = get_post( $row['object_id'] );
				if ( $post ) {
					$item['title']     = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
					$item['edit_link'] = current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '';
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Top queries by clicks.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public function top_queries( $engine, $range, $limit = 5 ) {
		return $this->rows(
			$engine,
			$range,
			'query',
			array(
				'per_page' => $limit,
				'orderby'  => 'clicks',
			)
		)['items'];
	}

	/**
	 * Top pages by clicks.
	 *
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public function top_pages( $engine, $range, $limit = 5 ) {
		return $this->rows(
			$engine,
			$range,
			'page',
			array(
				'per_page' => $limit,
				'orderby'  => 'clicks',
			)
		)['items'];
	}

	/**
	 * Pages gaining the most clicks (minimum volume: analytics.min_impressions impressions or 10 clicks).
	 *
	 * @param int    $limit  Limit.
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @return array
	 */
	public function rising( $limit = 5, $engine = 'google', $range = '28d' ) {
		return $this->rows(
			$engine,
			$range,
			'page',
			array(
				'per_page' => $limit,
				'orderby'  => 'delta_clicks',
				'order'    => 'desc',
				'change'   => 'rising',
			)
		)['items'];
	}

	/**
	 * Pages losing the most clicks.
	 *
	 * @param int    $limit  Limit.
	 * @param string $engine Engine.
	 * @param string $range  Range.
	 * @return array
	 */
	public function declining( $limit = 5, $engine = 'google', $range = '28d' ) {
		return $this->rows(
			$engine,
			$range,
			'page',
			array(
				'per_page' => $limit,
				'orderby'  => 'delta_clicks',
				'order'    => 'asc',
				'change'   => 'declining',
			)
		)['items'];
	}

	/**
	 * Search queries a page ranks for (from the stored query+page snapshot; no API call).
	 *
	 * @param string $url   Page URL.
	 * @param string $range Range (28d or 3m have query+page data; others use the nearest).
	 * @param int    $limit Max queries.
	 * @return array<int,array{query:string,clicks:int,impressions:int,ctr:float,position:float}>
	 */
	public function queries_for_page( $url, $range = '28d', $limit = 10 ) {
		global $wpdb;
		$range = Ranges::sanitize( $range );
		if ( ! in_array( $range, array( '28d', '3m' ), true ) ) {
			$range = in_array( $range, array( '6m', '12m' ), true ) ? '3m' : '28d';
		}
		$normalized = Url::normalize( (string) $url );
		if ( '' === $normalized ) {
			return array();
		}
		$table     = Tables::perf_rows();
		$object_id = $this->resolver->resolve( $normalized );
		$match     = $object_id ? 'object_id = %d' : 'page = %s';
		$value     = $object_id ? $object_id : $normalized;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, SUM(position * impressions) AS pw
				FROM {$table} WHERE engine = 'google' AND range_key = %s AND dimension = 'query_page' AND {$match} AND impressions > 0
				GROUP BY query ORDER BY clicks DESC, impressions DESC LIMIT %d",
				$range,
				$value,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$impressions = (int) $row['impressions'];
			$clicks      = (int) $row['clicks'];
			$out[]       = array(
				'query'       => (string) $row['query'],
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'ctr'         => $impressions ? round( $clicks / $impressions, 5 ) : 0.0,
				'position'    => $impressions ? round( (float) $row['pw'] / $impressions, 2 ) : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Daily trend of one page (Search Console, on demand, cached 12 hours).
	 *
	 * @param string $url   Page URL.
	 * @param string $range Range key.
	 * @return array|\WP_Error Series shape of series(), plus totals.
	 */
	public function page_series( $url, $range = '28d' ) {
		$range      = Ranges::sanitize( $range );
		$normalized = Url::normalize( (string) $url );
		if ( '' === $normalized || ! preg_match( '#^https?://#i', $normalized ) ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Enter a valid page URL.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$windows = $this->windows( 'google', $range );
		$cache   = 'searchpilot_ps_' . md5( $normalized . '|' . $range . '|' . $windows['current']['to'] );
		$cached  = get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$client = $this->google();
		if ( ! $client || ! method_exists( $client, 'search_analytics' ) ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Connect Google Search Console to see the daily trend of a page.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$fresh = (bool) $this->settings->get( 'analytics.fresh_data', false );
		$rows  = $client->search_analytics(
			array(
				'startDate'             => $windows['previous']['from'],
				'endDate'               => $windows['current']['to'],
				'dimensions'            => array( 'date' ),
				'type'                  => 'web',
				'dataState'             => $fresh ? 'all' : 'final',
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'page',
								'operator'   => 'equals',
								'expression' => $normalized,
							),
						),
					),
				),
			),
			1000
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$daily = array();
		foreach ( (array) $rows as $row ) {
			$date = (string) ( $row['keys'][0] ?? '' );
			if ( Ranges::is_date( $date ) ) {
				$daily[ $date ] = array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'position'    => (float) ( $row['position'] ?? 0 ),
				);
			}
		}
		$size          = 'week' === Ranges::bucket( $range ) ? 7 : 1;
		$buckets       = Ranges::buckets( $windows['current']['from'], $windows['current']['to'], $size );
		$prev_buckets  = Ranges::buckets( $windows['previous']['from'], $windows['previous']['to'], $size );
		$result        = array_merge(
			array(
				'url'    => $normalized,
				'labels' => wp_list_pluck( $buckets, 'label' ),
				'bucket' => $size > 1 ? 'week' : 'day',
				'from'   => $windows['current']['from'],
				'to'     => $windows['current']['to'],
			),
			self::aggregate_buckets( $buckets, $daily, $this->earliest_date( 'google' ) )
		);
		$prev_cov          = $this->coverage( 'google', $windows['previous'] );
		$result['compare'] = array_merge(
			array(
				'type'         => 'previous',
				'from'         => $windows['previous']['from'],
				'to'           => $windows['previous']['to'],
				'labels'       => wp_list_pluck( $prev_buckets, 'label' ),
				'complete'     => $prev_cov['complete'],
				'covered_days' => $prev_cov['covered_days'],
			),
			self::aggregate_buckets( $prev_buckets, $daily, $this->earliest_date( 'google' ) )
		);
		if ( ! $prev_cov['covered_days'] ) {
			$result['compare']['empty'] = true;
		}
		set_transient( $cache, $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Bing crawl statistics (cached by the Bing module), normalized for the admin.
	 *
	 * @return array|null
	 */
	public function bing_crawl() {
		$client = $this->bing();
		if ( ! $client || ! method_exists( $client, 'crawl_summary' ) ) {
			return null;
		}
		try {
			$summary = $client->crawl_summary();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $summary ) && $summary ? self::normalize_crawl( $summary ) : null;
	}

	/**
	 * Normalizes a Bing crawl summary: a list of daily rows (date, crawled_pages,
	 * crawl_errors, in_index, code_4xx, code_5xx, blocked_by_robots), or an array
	 * holding such rows under days/rows/series/daily/stats and optional totals.
	 * Flows are summed over the last 30 days; pages in the index is the latest level.
	 *
	 * @param array $summary Raw summary.
	 * @return array|null {checked_at, from, to, days, totals{…}, series{labels, crawled_pages, crawl_errors}}
	 */
	public static function normalize_crawl( array $summary ) {
		$flows = array( 'crawled_pages', 'crawl_errors', 'code_4xx', 'code_5xx', 'blocked_by_robots' );
		$rows  = array();
		if ( isset( $summary[0] ) && is_array( $summary[0] ) ) {
			$rows = $summary;
		} else {
			foreach ( array( 'days', 'rows', 'series', 'daily', 'stats', 'trend' ) as $key ) {
				if ( ! empty( $summary[ $key ] ) && is_array( $summary[ $key ] ) && isset( $summary[ $key ][0] ) && is_array( $summary[ $key ][0] ) ) {
					$rows = $summary[ $key ];
					break;
				}
			}
		}
		$daily = array();
		foreach ( $rows as $row ) {
			$date = substr( (string) ( $row['date'] ?? '' ), 0, 10 );
			if ( Ranges::is_date( $date ) ) {
				$daily[ $date ] = $row;
			}
		}
		ksort( $daily );
		if ( $daily ) {
			$last  = array_key_last( $daily );
			$first = Ranges::shift( $last, -29 );
			$daily = array_filter(
				$daily,
				static function ( $date ) use ( $first ) {
					return $date >= $first;
				},
				ARRAY_FILTER_USE_KEY
			);
		}
		$totals = array_fill_keys( array_merge( $flows, array( 'in_index' ) ), null );
		$given  = array();
		foreach ( array( 'totals', 'last_30_days', 'summary' ) as $key ) {
			if ( ! empty( $summary[ $key ] ) && is_array( $summary[ $key ] ) ) {
				$given = $summary[ $key ];
				break;
			}
		}
		if ( ! $given && ! isset( $summary[0] ) ) {
			$given = $summary;
		}
		foreach ( array_keys( $totals ) as $field ) {
			if ( isset( $given[ $field ] ) && is_numeric( $given[ $field ] ) ) {
				$totals[ $field ] = (int) $given[ $field ];
			}
		}
		foreach ( $flows as $field ) {
			if ( null === $totals[ $field ] && $daily ) {
				$totals[ $field ] = (int) array_sum( array_map( 'intval', array_column( $daily, $field ) ) );
			}
		}
		if ( null === $totals['in_index'] && $daily ) {
			$latest             = end( $daily );
			$totals['in_index'] = isset( $latest['in_index'] ) ? (int) $latest['in_index'] : null;
		}
		if ( ! $daily && ! array_filter( $totals, 'is_int' ) ) {
			return null;
		}
		return array(
			'checked_at' => isset( $summary['checked_at'] ) ? (string) $summary['checked_at'] : null,
			'from'       => $daily ? array_key_first( $daily ) : null,
			'to'         => $daily ? array_key_last( $daily ) : null,
			'days'       => count( $daily ),
			'totals'     => $totals,
			'series'     => array(
				'labels'        => array_keys( $daily ),
				'crawled_pages' => array_map( 'intval', array_column( $daily, 'crawled_pages' ) ),
				'crawl_errors'  => array_map( 'intval', array_column( $daily, 'crawl_errors' ) ),
			),
		);
	}

	/**
	 * Deletes stored data of an engine (e.g. after switching property).
	 *
	 * @param string $engine Engine.
	 */
	public function purge( $engine ) {
		global $wpdb;
		unset( $this->bounds[ $engine ] );
		$wpdb->delete( Tables::perf_daily(), array( 'engine' => $engine ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Tables::perf_rows(), array( 'engine' => $engine ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$all = (array) $this->state->get( 'analytics_snapshots', array() );
		unset( $all[ $engine ] );
		$this->state->set( 'analytics_snapshots', $all );
	}
}
