<?php
/**
 * Dashboard summary endpoint.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Analytics\Opportunities;
use SearchPilot\Analytics\PerformanceRepository;
use SearchPilot\Analytics\PerformanceSync;
use SearchPilot\Analytics\Ranges;
use SearchPilot\Core\ActivityLog;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /dashboard?range=28d&engine=google — one payload for the main
 * dashboard. Other modules add their sections (engines, queue, sitemaps,
 * not_found, content, links, indexing, monitor) through the
 * `searchpilot_dashboard_summary` filter.
 */
class DashboardController extends Controller {

	/**
	 * Performance data.
	 *
	 * @var PerformanceRepository
	 */
	private $perf;

	/**
	 * Sync.
	 *
	 * @var PerformanceSync
	 */
	private $sync;

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
	 * Activity.
	 *
	 * @var ActivityLog
	 */
	private $activity;

	/**
	 * Constructor.
	 *
	 * @param PerformanceRepository $perf          Performance data.
	 * @param PerformanceSync       $sync          Sync.
	 * @param Opportunities         $opportunities Opportunities.
	 * @param IssueRepository       $issues        Issues.
	 * @param ActivityLog           $activity      Activity.
	 */
	public function __construct( PerformanceRepository $perf, PerformanceSync $sync, Opportunities $opportunities, IssueRepository $issues, ActivityLog $activity ) {
		$this->perf          = $perf;
		$this->sync          = $sync;
		$this->opportunities = $opportunities;
		$this->issues        = $issues;
		$this->activity      = $activity;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route(
			'/dashboard',
			'GET',
			array( $this, 'summary' ),
			'view',
			array(
				'range'  => array(
					'type'              => 'string',
					'default'           => '28d',
					'sanitize_callback' => 'sanitize_key',
				),
				'engine' => array(
					'type'              => 'string',
					'default'           => 'google',
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * GET /dashboard.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function summary( \WP_REST_Request $request ) {
		$range  = Ranges::sanitize( (string) $request->get_param( 'range' ) );
		$engine = in_array( $request->get_param( 'engine' ), PerformanceRepository::ENGINES, true ) ? (string) $request->get_param( 'engine' ) : 'google';
		$lists  = self::lists_range( $this->perf, $engine, $range );
		$status = $this->sync->status();
		$opps   = $this->opportunities->summary();

		$data = array(
			'range'           => $range,
			'engine'          => $engine,
			'connected'       => $status['connected'],
			'performance'     => array(
				'totals'   => $this->perf->totals( $engine, $range ),
				'series'   => $this->perf->series( $engine, $range, 'previous' ),
				'snapshot' => $this->perf->snapshot( $engine, $lists ),
			),
			'lists_range'     => $lists,
			'lists_complete'  => $this->perf->prev_complete( $engine, $lists ),
			'top_queries'     => $this->perf->top_queries( $engine, $lists, 5 ),
			'top_pages'       => $this->perf->top_pages( $engine, $lists, 5 ),
			'rising'          => $this->perf->rising( 5, $engine, $lists ),
			'declining'       => $this->perf->declining( 5, $engine, $lists ),
			'opportunities'   => array(
				'total'            => $opps['total'],
				'counts_by_action' => $opps['by_action'],
				'counts_by_type'   => $opps['by_type'],
				'labels'           => $opps['action_labels'],
				'top'              => $this->opportunities->top( 5 ),
			),
			'issues'          => $this->issues->counts( null, array( 'opportunity' ) ),
			'critical_issues' => $this->issues->query(
				array(
					'severity'         => array( 'critical', 'high' ),
					'exclude_category' => array( 'opportunity' ),
					'per_page'         => 5,
				)
			)['items'],
			'activity'        => $this->activity->query( array( 'per_page' => 5 ) )['items'],
			'last_sync'       => array(
				'google'   => $status['last_sync']['google'],
				'bing'     => $status['last_sync']['bing'],
				'status'   => $status['status'],
				'progress' => $status['progress'],
			),
			'freshness'       => $status['freshness'],
		);

		/**
		 * Filters the dashboard summary. Modules add their own sections.
		 *
		 * @param array  $data   Summary.
		 * @param string $range  Range key.
		 * @param string $engine google|bing.
		 */
		$data = (array) apply_filters( 'searchpilot_dashboard_summary', $data, $range, $engine );

		// Bing crawl statistics (the Bing module's cached summary), in one normalized shape.
		$crawl              = $data['bing_crawl'] ?? ( $data['engines']['bing_crawl'] ?? null );
		$data['bing_crawl'] = is_array( $crawl ) && $crawl ? PerformanceRepository::normalize_crawl( $crawl ) : null;
		return $data;
	}

	/**
	 * Snapshot range to use for top lists: the requested one, else the nearest
	 * stored one (Bing has 28d/3m/6m only).
	 *
	 * @param PerformanceRepository $perf   Repository.
	 * @param string                $engine Engine.
	 * @param string                $range  Range.
	 * @return string
	 */
	public static function lists_range( PerformanceRepository $perf, $engine, $range ) {
		if ( $perf->snapshot( $engine, $range ) ) {
			return $range;
		}
		$order = array(
			'7d'  => array( '28d', '3m' ),
			'28d' => array( '3m', '7d' ),
			'3m'  => array( '28d', '6m' ),
			'6m'  => array( '3m', '12m' ),
			'12m' => array( '6m', '3m' ),
		);
		foreach ( $order[ $range ] ?? array() as $candidate ) {
			if ( $perf->snapshot( $engine, $candidate ) ) {
				return $candidate;
			}
		}
		return $range;
	}
}
