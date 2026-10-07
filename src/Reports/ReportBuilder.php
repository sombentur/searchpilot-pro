<?php
/**
 * Builds and stores performance reports.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Reports;

use SearchPilot\Analytics\Opportunities;
use SearchPilot\Analytics\PerformanceRepository;
use SearchPilot\Analytics\Ranges;
use SearchPilot\Container;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A report is a JSON snapshot of one period: Google and Bing KPIs against
 * the previous period, the daily trend, top/rising/declining content, index
 * coverage, issues, automation activity, search-engine submissions and
 * sitemap health. Stored in the `reports` table so the e-mail, the admin
 * viewer and exports always show the same numbers.
 */
class ReportBuilder {

	const TYPES = array( 'weekly', 'monthly', 'custom' );

	/**
	 * Performance data.
	 *
	 * @var PerformanceRepository
	 */
	private $perf;

	/**
	 * Opportunities.
	 *
	 * @var Opportunities
	 */
	private $opportunities;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Issue registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param PerformanceRepository $perf          Performance data.
	 * @param Opportunities         $opportunities Opportunities.
	 * @param IssueRepository       $issues        Issues.
	 * @param IssueRegistry         $registry      Issue registry.
	 * @param UrlRepository         $urls          URL registry.
	 * @param Container             $container     Container.
	 */
	public function __construct( PerformanceRepository $perf, Opportunities $opportunities, IssueRepository $issues, IssueRegistry $registry, UrlRepository $urls, Container $container ) {
		$this->perf          = $perf;
		$this->opportunities = $opportunities;
		$this->issues        = $issues;
		$this->registry      = $registry;
		$this->urls          = $urls;
		$this->container     = $container;
	}

	/**
	 * Default period of a report type.
	 *
	 * @param string $type  weekly|monthly.
	 * @param string $today Today (site time zone).
	 * @return array{from:string,to:string,days:int}
	 */
	public function period( $type, $today = '' ) {
		$today = '' !== $today ? $today : wp_date( 'Y-m-d' );
		if ( 'monthly' === $type ) {
			return Ranges::last_full_month( $today );
		}
		return Ranges::last_full_week( $today, (int) get_option( 'start_of_week', 1 ) );
	}

	/**
	 * Builds and stores a report.
	 *
	 * @param string $type  weekly|monthly|custom.
	 * @param string $start First day (custom; defaults to the type's last full period).
	 * @param string $end   Last day.
	 * @return array|\WP_Error Stored report (id, type, period_start, period_end, data…).
	 */
	public function build( $type, $start = '', $end = '' ) {
		$type = in_array( $type, self::TYPES, true ) ? $type : 'weekly';
		if ( '' !== (string) $start || '' !== (string) $end ) {
			if ( ! Ranges::is_date( (string) $start ) || ! Ranges::is_date( (string) $end ) ) {
				return new \WP_Error( 'searchpilot_invalid', __( 'Choose a valid start and end date (YYYY-MM-DD).', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
			$window = Ranges::between( $start, $end );
			if ( $window['days'] > 366 ) {
				return new \WP_Error( 'searchpilot_invalid', __( 'A report can cover at most one year.', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
			if ( $window['to'] > wp_date( 'Y-m-d' ) ) {
				return new \WP_Error( 'searchpilot_invalid', __( 'The report period cannot end in the future.', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
		} else {
			if ( 'custom' === $type ) {
				return new \WP_Error( 'searchpilot_invalid', __( 'Choose the start and end date of the custom report.', 'searchpilot-pro' ), array( 'status' => 400 ) );
			}
			$window = $this->period( $type );
		}
		$data = $this->compile( $type, $window['from'], $window['to'] );
		$id   = $this->store( $type, $window['from'], $window['to'], $data );
		if ( ! $id ) {
			return new \WP_Error( 'searchpilot_db', __( 'The report could not be saved.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		return $this->get( $id );
	}

	/**
	 * Collects the report data (nothing is stored).
	 *
	 * @param string $type Type.
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array
	 */
	public function compile( $type, $from, $to ) {
		$window   = Ranges::between( $from, $to );
		$previous = Ranges::previous( $window );
		$range    = Ranges::closest( $window['days'] );
		$size     = $window['days'] > 92 ? 7 : 1;
		$conn     = $this->perf->connections();
		$engines  = array();
		foreach ( PerformanceRepository::ENGINES as $engine ) {
			$current  = $this->perf->totals_between( $engine, $window['from'], $window['to'] );
			$prev     = $this->perf->totals_between( $engine, $previous['from'], $previous['to'] );
			$latest   = $this->perf->latest_date( $engine );
			$cov_now  = $this->perf->coverage( $engine, $window );
			$cov_prev = $this->perf->coverage( $engine, $previous );

			// A change is only reported when both periods are fully covered by stored data.
			$engines[ $engine ] = array(
				'connected'         => (bool) $conn[ $engine ],
				'has_data'          => $current['days'] > 0,
				'current'           => $current,
				'previous'          => $prev,
				'latest'            => $latest,
				'partial'           => $latest && $latest < $window['to'],
				'covered_days'      => $cov_now['covered_days'],
				'previous_complete' => $cov_prev['complete'],
				'previous_covered'  => $cov_prev['covered_days'],
				'comparable'        => $cov_now['complete'] && $cov_prev['complete'],
				'series'            => $current['days'] > 0 ? $this->perf->series_between( $engine, $window['from'], $window['to'], $size ) : null,
			);
		}
		$lists_engine = $engines['google']['has_data'] || ! $engines['bing']['has_data'] ? 'google' : 'bing';
		$snapshot     = $this->perf->snapshot( $lists_engine, $range );
		$lists_match  = self::snapshot_matches( $snapshot, $window );
		$index        = $this->urls->summary();

		$index['by_status'] = $this->urls->count_by_status();

		return array(
			'type'           => $type,
			'period'         => $window,
			'previous'       => $previous,
			'generated_at'   => gmdate( 'c' ),
			'site'           => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'engines'        => $engines,
			'lists'          => array(
				'engine'      => $lists_engine,
				'range'       => $range,
				'from'          => (string) ( $snapshot['from'] ?? '' ),
				'to'            => (string) ( $snapshot['to'] ?? '' ),
				'matches'       => $lists_match,
				'prev_complete' => $lists_match && $this->perf->prev_complete( $lists_engine, $range ),
				'top_queries'   => $lists_match ? self::slim( $this->perf->top_queries( $lists_engine, $range, 10 ) ) : array(),
				'top_pages'     => $lists_match ? self::slim( $this->perf->top_pages( $lists_engine, $range, 10 ) ) : array(),
				'rising'        => $lists_match ? self::slim( $this->perf->rising( 5, $lists_engine, $range ) ) : array(),
				'declining'     => $lists_match ? self::slim( $this->perf->declining( 5, $lists_engine, $range ) ) : array(),
			),
			'index'          => $index,
			'issues'         => $this->issue_summary( $window ),
			'opportunities'  => array_intersect_key( $this->opportunities->summary(), array_flip( array( 'total', 'by_action', 'by_type', 'action_labels' ) ) ),
			'automation'     => $this->automation( $window ),
			'submissions'    => $this->submissions( $window ),
			'sitemaps'       => $this->sitemaps(),
			'disclaimer'     => self::disclaimer(),
		);
	}

	/**
	 * Whether a stored snapshot describes (almost) the same period as a report:
	 * the same length class and an end date within three days. Otherwise its
	 * top/rising/declining lists would belong to other dates.
	 *
	 * @param array|null $snapshot Snapshot info (from, to).
	 * @param array      $window   Report window.
	 * @return bool
	 */
	public static function snapshot_matches( $snapshot, array $window ) {
		if ( ! is_array( $snapshot ) || empty( $snapshot['from'] ) || empty( $snapshot['to'] ) ) {
			return false;
		}
		$days = Ranges::diff_days( (string) $snapshot['from'], (string) $snapshot['to'] ) + 1;
		return abs( Ranges::diff_days( (string) $snapshot['to'], (string) $window['to'] ) ) <= 3 && abs( $days - (int) $window['days'] ) <= 3;
	}

	/**
	 * Standard disclaimer.
	 *
	 * @return string
	 */
	public static function disclaimer() {
		return __( 'Search data comes from Google Search Console and Bing Webmaster Tools and is typically a few days behind. SearchPilot works inside search-engine guidelines and cannot guarantee rankings, indexing or traffic.', 'searchpilot-pro' );
	}

	/**
	 * Issue counts and issues first detected in the period.
	 *
	 * @param array $window Window.
	 * @return array
	 */
	private function issue_summary( array $window ) {
		global $wpdb;
		list( $from, $to ) = self::utc_bounds( $window );
		$table             = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type, severity, title, url, detected_at FROM {$table} WHERE detected_at BETWEEN %s AND %s AND severity IN ('critical','high') AND category <> 'opportunity' ORDER BY severity_rank ASC, detected_at DESC LIMIT 200", $from, $to ), ARRAY_A );
		$new  = array();
		$cnt  = array(
			'critical' => 0,
			'high'     => 0,
		);
		foreach ( (array) $rows as $row ) {
			++$cnt[ $row['severity'] ];
			if ( count( $new ) < 10 ) {
				$def   = $this->registry->get( $row['type'] );
				$new[] = array(
					'type'        => $row['type'],
					'label'       => $def['label'] ?? $row['type'],
					'severity'    => $row['severity'],
					'title'       => (string) $row['title'],
					'url'         => (string) $row['url'],
					'detected_at' => mysql_to_rfc3339( $row['detected_at'] ),
				);
			}
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$resolved = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE resolved_at BETWEEN %s AND %s AND status IN ('fixed','resolved') AND category <> 'opportunity'", $from, $to ) );
		return array(
			'open'         => $this->issues->counts( null, array( 'opportunity' ) ),
			'new_counts'   => $cnt,
			'new_critical' => $new,
			'resolved'     => $resolved,
		);
	}

	/**
	 * Activity entries by source.
	 *
	 * @param array $window Window.
	 * @return array
	 */
	private function automation( array $window ) {
		global $wpdb;
		list( $from, $to ) = self::utc_bounds( $window );
		$table             = Tables::activity();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source, status, COUNT(*) AS n FROM {$table} WHERE created_at BETWEEN %s AND %s AND action <> 'undo' GROUP BY source, status", $from, $to ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$actions   = $wpdb->get_results( $wpdb->prepare( "SELECT action, COUNT(*) AS n FROM {$table} WHERE created_at BETWEEN %s AND %s AND action <> 'undo' GROUP BY action ORDER BY n DESC LIMIT 8", $from, $to ) );
		$by_source = array();
		$total     = 0;
		$reverted  = 0;
		foreach ( (array) $rows as $row ) {
			$by_source[ $row->source ] = ( $by_source[ $row->source ] ?? 0 ) + (int) $row->n;
			$total                    += (int) $row->n;
			if ( 'reverted' === $row->status ) {
				$reverted += (int) $row->n;
			}
		}
		$top = array();
		foreach ( (array) $actions as $row ) {
			$top[ $row->action ] = (int) $row->n;
		}
		return array(
			'total'       => $total,
			'reverted'    => $reverted,
			'by_source'   => $by_source,
			'automatic'   => (int) ( ( $by_source['assisted'] ?? 0 ) + ( $by_source['autopilot'] ?? 0 ) + ( $by_source['system'] ?? 0 ) ),
			'top_actions' => $top,
		);
	}

	/**
	 * Search-engine submissions by service.
	 *
	 * @param array $window Window.
	 * @return array
	 */
	private function submissions( array $window ) {
		global $wpdb;
		list( $from, $to ) = self::utc_bounds( $window );
		$table             = Tables::queue();
		$suppress          = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT service, status, COUNT(*) AS n FROM {$table} WHERE updated_at BETWEEN %s AND %s AND status IN ('done','failed','held') GROUP BY service, status", $from, $to ) );
		$wpdb->suppress_errors( $suppress );
		$out = array(
			'done'       => 0,
			'failed'     => 0,
			'held'       => 0,
			'by_service' => array(),
		);
		foreach ( (array) $rows as $row ) {
			$service = (string) $row->service;
			$status  = (string) $row->status;
			if ( ! isset( $out['by_service'][ $service ] ) ) {
				$out['by_service'][ $service ] = array(
					'done'   => 0,
					'failed' => 0,
					'held'   => 0,
				);
			}
			$out['by_service'][ $service ][ $status ] += (int) $row->n;
			$out[ $status ]                           += (int) $row->n;
		}
		return $out;
	}

	/**
	 * Sitemap health (when the sitemap module is active).
	 *
	 * @return array|null
	 */
	private function sitemaps() {
		$class = 'SearchPilot\Sitemap\SitemapHealth';
		if ( ! $this->container->has( $class ) ) {
			return null;
		}
		try {
			$health = $this->container->get( $class );
			if ( ! method_exists( $health, 'status' ) ) {
				return null;
			}
			$status = (array) $health->status();
			return array_intersect_key( $status, array_flip( array( 'owner', 'owner_name', 'index_url', 'status', 'checked_at', 'url_count', 'errors' ) ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Keeps the fields reports need.
	 *
	 * @param array $rows Presented rows.
	 * @return array
	 */
	private static function slim( array $rows ) {
		$keep = array_flip( array( 'query', 'page', 'display_page', 'title', 'object_id', 'clicks', 'impressions', 'ctr', 'position', 'prev_clicks', 'prev_impressions', 'prev_position', 'delta_clicks', 'delta_impressions', 'delta_position' ) );
		return array_map(
			static function ( $row ) use ( $keep ) {
				return array_intersect_key( $row, $keep );
			},
			$rows
		);
	}

	/**
	 * UTC datetime bounds of a site-local date window.
	 *
	 * @param array $window Window.
	 * @return array{0:string,1:string}
	 */
	private static function utc_bounds( array $window ) {
		return array(
			get_gmt_from_date( $window['from'] . ' 00:00:00' ),
			get_gmt_from_date( $window['to'] . ' 23:59:59' ),
		);
	}

	/* --------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Stores a report.
	 *
	 * @param string $type Type.
	 * @param string $from From.
	 * @param string $to   To.
	 * @param array  $data Data.
	 * @return int Report id.
	 */
	public function store( $type, $from, $to, array $data ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::reports(),
			array(
				'type'         => $type,
				'period_start' => $from,
				'period_end'   => $to,
				'data'         => (string) wp_json_encode( $data ),
				'emailed'      => 0,
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A stored report.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::reports();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'id'           => (int) $row['id'],
			'type'         => (string) $row['type'],
			'period_start' => substr( (string) $row['period_start'], 0, 10 ),
			'period_end'   => substr( (string) $row['period_end'], 0, 10 ),
			'emailed'      => (bool) $row['emailed'],
			'created_at'   => mysql_to_rfc3339( $row['created_at'] ),
			'data'         => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Stored reports, newest first, with headline numbers.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 * @return array{items:array,total:int}
	 */
	public function query( $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table    = Tables::reports();
		$page     = max( 1, (int) $page );
		$per_page = min( 100, max( 1, (int) $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$data    = json_decode( (string) $row['data'], true );
			$data    = is_array( $data ) ? $data : array();
			$google  = (array) ( $data['engines']['google'] ?? array() );
			$items[] = array(
				'id'           => (int) $row['id'],
				'type'         => (string) $row['type'],
				'period_start' => substr( (string) $row['period_start'], 0, 10 ),
				'period_end'   => substr( (string) $row['period_end'], 0, 10 ),
				'emailed'      => (bool) $row['emailed'],
				'created_at'   => mysql_to_rfc3339( $row['created_at'] ),
				'summary'      => array(
					'clicks'           => (int) ( $google['current']['clicks'] ?? 0 ),
					'prev_clicks'      => (int) ( $google['previous']['clicks'] ?? 0 ),
					'impressions'      => (int) ( $google['current']['impressions'] ?? 0 ),
					'prev_impressions' => (int) ( $google['previous']['impressions'] ?? 0 ),
					'has_data'         => ! empty( $google['has_data'] ),
					'issues'           => (int) ( $data['issues']['open']['total'] ?? 0 ),
					'opportunities'    => (int) ( $data['opportunities']['total'] ?? 0 ),
				),
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Id of a stored report for exactly this period, or 0.
	 *
	 * @param string $type Type.
	 * @param string $from From.
	 * @param string $to   To.
	 * @return int
	 */
	public function find( $type, $from, $to ) {
		global $wpdb;
		$table = Tables::reports();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND period_start = %s AND period_end = %s ORDER BY id DESC LIMIT 1", $type, $from, $to ) );
	}

	/**
	 * Rebuilds a stored report for its own period (same id), e.g. when it was
	 * generated before search data covered the period end.
	 *
	 * @param int $id Report id.
	 * @return array|null Fresh report.
	 */
	public function rebuild( $id ) {
		global $wpdb;
		$report = $this->get( $id );
		if ( ! $report ) {
			return null;
		}
		$data = $this->compile( $report['type'], $report['period_start'], $report['period_end'] );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::reports(),
			array(
				'data'       => (string) wp_json_encode( $data ),
				'emailed'    => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		return $this->get( $id );
	}

	/**
	 * Whether a stored report was built before the stored data covered its
	 * period (a connected engine reported only up to an earlier day).
	 *
	 * @param array $report Report.
	 * @return bool
	 */
	public function is_stale( array $report ) {
		foreach ( (array) ( $report['data']['engines'] ?? array() ) as $engine => $block ) {
			if ( empty( $block['connected'] ) && empty( $block['has_data'] ) ) {
				continue;
			}
			$then = (string) ( $block['latest'] ?? '' );
			$now  = (string) $this->perf->latest_date( (string) $engine );
			if ( ! empty( $block['partial'] ) && $now > $then ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Flags a report as e-mailed.
	 *
	 * @param int $id Id.
	 */
	public function mark_emailed( $id ) {
		global $wpdb;
		$wpdb->update( Tables::reports(), array( 'emailed' => 1 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Keeps the newest reports only.
	 *
	 * @param int $keep Reports to keep.
	 */
	public function prune( $keep = 120 ) {
		global $wpdb;
		$table = Tables::reports();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", max( 1, (int) $keep ) ) );
		if ( $cutoff ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
		}
	}
}
