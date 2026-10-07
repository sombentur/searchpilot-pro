<?php
/**
 * Search performance endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Analytics\PerformanceRepository;
use SearchPilot\Analytics\PerformanceSync;
use SearchPilot\Analytics\Ranges;
use SearchPilot\Analytics\UrlResolver;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * GET /performance, GET /performance/queries, GET /performance/pages,
 * GET /performance/page, POST /performance/sync, GET /performance/status.
 */
class PerformanceController extends Controller {

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
	 * Resolver.
	 *
	 * @var UrlResolver
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param PerformanceRepository $perf     Performance data.
	 * @param PerformanceSync       $sync     Sync.
	 * @param UrlResolver           $resolver Resolver.
	 */
	public function __construct( PerformanceRepository $perf, PerformanceSync $sync, UrlResolver $resolver ) {
		$this->perf     = $perf;
		$this->sync     = $sync;
		$this->resolver = $resolver;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$common = array(
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
		);
		$this->route(
			'/performance',
			'GET',
			array( $this, 'overview' ),
			'view',
			array_merge(
				$common,
				array(
					'compare' => array(
						'type'              => 'string',
						'default'           => 'previous',
						'sanitize_callback' => 'sanitize_key',
					),
				)
			)
		);
		$this->route( '/performance/queries', 'GET', array( $this, 'queries' ), 'view', $common );
		$this->route( '/performance/pages', 'GET', array( $this, 'pages' ), 'view', $common );
		$this->route(
			'/performance/page',
			'GET',
			array( $this, 'page' ),
			'view',
			array(
				'url'    => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				),
				'range'  => $common['range'],
				'engine' => $common['engine'],
			)
		);
		$this->route( '/performance/sync', 'POST', array( $this, 'start_sync' ), 'manage' );
		$this->route( '/performance/status', 'GET', array( $this, 'status' ), 'view' );
	}

	/**
	 * Engine from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function engine( \WP_REST_Request $request ) {
		$engine = (string) $request->get_param( 'engine' );
		return in_array( $engine, PerformanceRepository::ENGINES, true ) ? $engine : 'google';
	}

	/**
	 * GET /performance.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function overview( \WP_REST_Request $request ) {
		$range   = Ranges::sanitize( (string) $request->get_param( 'range' ) );
		$engine  = $this->engine( $request );
		$compare = in_array( $request->get_param( 'compare' ), array( 'previous', 'yoy', 'none' ), true ) ? (string) $request->get_param( 'compare' ) : 'previous';
		$lists   = DashboardController::lists_range( $this->perf, $engine, $range );
		$status  = $this->sync->status();
		return array(
			'range'       => $range,
			'engine'      => $engine,
			'compare'     => $compare,
			'totals'      => $this->perf->totals( $engine, $range ),
			'series'      => $this->perf->series( $engine, $range, $compare ),
			'lists_range'         => $lists,
			'lists_prev_complete' => $this->perf->prev_complete( $engine, $lists ),
			'snapshot'            => $this->perf->snapshot( $engine, $lists ),
			'connected'           => $status['connected'],
			'freshness'           => $status['freshness'][ $engine ],
			'last_sync'           => $status['last_sync'][ $engine ],
			'bing_crawl'          => 'bing' === $engine ? $this->perf->bing_crawl() : null,
		);
	}

	/**
	 * GET /performance/queries.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function queries( \WP_REST_Request $request ) {
		return $this->rows( $request, 'query' );
	}

	/**
	 * GET /performance/pages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function pages( \WP_REST_Request $request ) {
		return $this->rows( $request, 'page' );
	}

	/**
	 * Paged snapshot rows.
	 *
	 * @param \WP_REST_Request $request   Request.
	 * @param string           $dimension Dimension.
	 * @return array
	 */
	private function rows( \WP_REST_Request $request, $dimension ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$engine                  = $this->engine( $request );
		$range                   = DashboardController::lists_range( $this->perf, $engine, Ranges::sanitize( (string) $request->get_param( 'range' ) ) );
		$orderby                 = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$allowed                 = array( 'clicks', 'impressions', 'ctr', 'position', 'delta_clicks', 'delta_impressions', 'query' );
		$result                  = $this->perf->rows(
			$engine,
			$range,
			$dimension,
			array(
				'search'          => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'         => in_array( $orderby, $allowed, true ) ? $orderby : 'clicks',
				'order'           => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'asc' : 'desc',
				'page'            => $page,
				'per_page'        => $per_page,
				'min_impressions' => absint( $request->get_param( 'min_impressions' ) ),
				'include_lost'    => rest_sanitize_boolean( $request->get_param( 'include_lost' ) ),
			)
		);
		return $this->paged(
			$result['items'],
			$result['total'],
			$page,
			$per_page,
			array(
				'range'           => $range,
				'requested_range' => Ranges::sanitize( (string) $request->get_param( 'range' ) ),
				'engine'          => $engine,
				'prev_complete'   => ! empty( $result['prev_complete'] ),
				'snapshot'        => $this->perf->snapshot( $engine, $range ),
			)
		);
	}

	/**
	 * GET /performance/page?url=&range= — queries of one page and its trend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function page( \WP_REST_Request $request ) {
		$url = Url::normalize( (string) $request->get_param( 'url' ) );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Enter a valid page URL.', 'searchpilot-pro' ) );
		}
		$range     = Ranges::sanitize( (string) $request->get_param( 'range' ) );
		$engine    = $this->engine( $request );
		$lists     = DashboardController::lists_range( $this->perf, $engine, $range );
		$object_id = $this->resolver->resolve( $url );
		$row       = $this->perf->rows(
			$engine,
			$lists,
			'page',
			array(
				'page_url'     => $url,
				'per_page'     => 1,
				'include_lost' => true,
			)
		)['items'];
		// Per-page daily trends and queries per page exist only in Search Console.
		$series    = 'google' === $engine ? $this->perf->page_series( $url, $range ) : new \WP_Error( 'searchpilot_unsupported', __( 'Bing does not report a daily trend or the queries of a single page. Switch to Google to see them.', 'searchpilot-pro' ) );
		$post      = $object_id ? get_post( $object_id ) : null;
		return array(
			'url'          => $url,
			'display_url'  => Url::display( $url ),
			'object_id'    => $object_id,
			'title'        => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '',
			'edit_link'    => $post && current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			'engine'       => $engine,
			'range'        => $range,
			'lists_range'  => $lists,
			'totals'       => $row ? $row[0] : null,
			'queries'      => 'google' === $engine ? $this->perf->queries_for_page( $url, $lists, 50 ) : array(),
			'series'       => is_wp_error( $series ) ? null : $series,
			'series_error' => is_wp_error( $series ) ? $series->get_error_message() : '',
		);
	}

	/**
	 * POST /performance/sync — starts (or continues) a sync, runs a step now and schedules the rest.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function start_sync( \WP_REST_Request $request ) {
		$force = rest_sanitize_boolean( $request->get_param( 'restart' ) );
		$state = $this->sync->start( 'manual', $force );
		if ( 'running' === $state['status'] ) {
			$this->sync->work( 12 );
		}
		return $this->sync->status();
	}

	/**
	 * GET /performance/status.
	 *
	 * @return array
	 */
	public function status() {
		return $this->sync->status();
	}
}
