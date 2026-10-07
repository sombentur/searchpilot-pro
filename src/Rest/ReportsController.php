<?php
/**
 * Report and export endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Analytics\Opportunities;
use SearchPilot\Analytics\PerformanceRepository;
use SearchPilot\Analytics\Ranges;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Reports\ReportBuilder;
use SearchPilot\Reports\ReportMailer;

defined( 'ABSPATH' ) || exit;

/**
 * GET /reports, GET /reports/{id}, POST /reports/generate,
 * POST /reports/{id}/email, GET /reports/export.
 *
 * Exports return rows + columns; the browser builds the CSV with
 * SPP.util.toCsv(), which neutralizes spreadsheet formula injection.
 */
class ReportsController extends Controller {

	const EXPORT_CAP = 5000;

	/**
	 * Builder.
	 *
	 * @var ReportBuilder
	 */
	private $builder;

	/**
	 * Mailer.
	 *
	 * @var ReportMailer
	 */
	private $mailer;

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
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

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
	 * Constructor.
	 *
	 * @param ReportBuilder         $builder       Builder.
	 * @param ReportMailer          $mailer        Mailer.
	 * @param PerformanceRepository $perf          Performance data.
	 * @param Opportunities         $opportunities Opportunities.
	 * @param IssueRepository       $issues        Issues.
	 * @param UrlRepository         $urls          URL registry.
	 * @param Settings              $settings      Settings.
	 * @param State                 $state         State.
	 */
	public function __construct( ReportBuilder $builder, ReportMailer $mailer, PerformanceRepository $perf, Opportunities $opportunities, IssueRepository $issues, UrlRepository $urls, Settings $settings, State $state ) {
		$this->builder       = $builder;
		$this->mailer        = $mailer;
		$this->perf          = $perf;
		$this->opportunities = $opportunities;
		$this->issues        = $issues;
		$this->urls          = $urls;
		$this->settings      = $settings;
		$this->state         = $state;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/reports', 'GET', array( $this, 'list_reports' ), 'view' );
		$this->route( '/reports/export', 'GET', array( $this, 'export' ), 'view' );
		$this->route( '/reports/(?P<id>\d+)', 'GET', array( $this, 'get_report' ), 'view', $this->id_arg() );
		$this->route(
			'/reports/generate',
			'POST',
			array( $this, 'generate' ),
			'manage',
			array(
				'type'  => array(
					'type'    => 'string',
					'default' => 'weekly',
					'enum'    => ReportBuilder::TYPES,
				),
				'start' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'end'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
		$this->route( '/reports/(?P<id>\d+)/email', 'POST', array( $this, 'email' ), 'manage', $this->id_arg() );
	}

	/**
	 * GET /reports.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_reports( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 20 );
		$result                  = $this->builder->query( $page, $per_page );
		$type                    = (string) $this->settings->get( 'alerts.report', 'off' );
		$schedule                = array(
			'frequency' => $type,
			'last_sent' => (array) $this->state->get( 'reports_last', array() ),
			'next'      => in_array( $type, array( 'weekly', 'monthly' ), true ) ? $this->builder->period( $type ) : null,
		);
		if ( Capabilities::can_manage() ) {
			$schedule['recipients']       = array_values( (array) $this->settings->get( 'alerts.report_recipients', array() ) );
			$schedule['alert_recipients'] = array_values( (array) $this->settings->get( 'alerts.recipients', array() ) );
			$schedule['effective']        = $this->mailer->recipients();
			$schedule['admin_fallback']   = ! $schedule['recipients'] && ! $schedule['alert_recipients'];
		}
		return $this->paged( $result['items'], $result['total'], $page, $per_page, array( 'schedule' => $schedule ) );
	}

	/**
	 * GET /reports/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_report( \WP_REST_Request $request ) {
		$report = $this->builder->get( (int) $request['id'] );
		return $report ? $report : $this->error( 'searchpilot_not_found', __( 'Report not found.', 'searchpilot-pro' ), 404 );
	}

	/**
	 * POST /reports/generate {type, start?, end?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function generate( \WP_REST_Request $request ) {
		$type  = (string) $request->get_param( 'type' );
		$start = (string) $request->get_param( 'start' );
		$end   = (string) $request->get_param( 'end' );
		return $this->builder->build( $type, $start, $end );
	}

	/**
	 * POST /reports/{id}/email.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function email( \WP_REST_Request $request ) {
		$report = $this->builder->get( (int) $request['id'] );
		if ( ! $report ) {
			return $this->error( 'searchpilot_not_found', __( 'Report not found.', 'searchpilot-pro' ), 404 );
		}
		$result = $this->mailer->send( $report );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'sent'       => true,
			'recipients' => count( $this->mailer->recipients() ),
			'report'     => $this->builder->get( (int) $report['id'] ),
		);
	}

	/**
	 * GET /reports/export?type=queries|pages|opportunities|issues|urls&range=&engine=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error {filename, columns: [{key,label}], rows}
	 */
	public function export( \WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$range  = Ranges::sanitize( (string) $request->get_param( 'range' ) );
		$engine = in_array( $request->get_param( 'engine' ), PerformanceRepository::ENGINES, true ) ? (string) $request->get_param( 'engine' ) : 'google';
		$stamp  = wp_date( 'Y-m-d' );
		switch ( $type ) {
			case 'queries':
			case 'pages':
				return $this->export_rows( 'queries' === $type ? 'query' : 'page', $engine, DashboardController::lists_range( $this->perf, $engine, $range ), $stamp );
			case 'opportunities':
				return $this->export_opportunities( $stamp );
			case 'issues':
				return $this->export_issues( $stamp );
			case 'urls':
				return $this->export_urls( $stamp );
		}
		return $this->error( 'searchpilot_invalid', __( 'Choose what to export: queries, pages, opportunities, issues or urls.', 'searchpilot-pro' ) );
	}

	/**
	 * Query/page export.
	 *
	 * @param string $dimension query|page.
	 * @param string $engine    Engine.
	 * @param string $range     Range.
	 * @param string $stamp     Date stamp.
	 * @return array
	 */
	private function export_rows( $dimension, $engine, $range, $stamp ) {
		$rows = array();
		for ( $page = 1; count( $rows ) < self::EXPORT_CAP && $page <= 10; $page++ ) {
			$batch = $this->perf->rows(
				$engine,
				$range,
				$dimension,
				array(
					'page'         => $page,
					'per_page'     => 500,
					'include_lost' => true,
				)
			);
			foreach ( $batch['items'] as $item ) {
				$rows[] = array(
					'query'             => $item['query'],
					'page'              => $item['display_page'],
					'title'             => $item['title'],
					'clicks'            => $item['clicks'],
					'prev_clicks'       => $item['prev_clicks'],
					'delta_clicks'      => $item['delta_clicks'],
					'impressions'       => $item['impressions'],
					'prev_impressions'  => $item['prev_impressions'],
					'delta_impressions' => $item['delta_impressions'],
					'ctr'               => round( $item['ctr'] * 100, 2 ),
					'position'          => $item['position'],
					'prev_position'     => $item['prev_position'],
				);
			}
			if ( count( $batch['items'] ) < 500 ) {
				break;
			}
		}
		$columns = array(
			array(
				'key'   => 'clicks',
				'label' => __( 'Clicks', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'prev_clicks',
				'label' => __( 'Clicks (previous period)', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'delta_clicks',
				'label' => __( 'Change in clicks', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'impressions',
				'label' => __( 'Impressions', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'prev_impressions',
				'label' => __( 'Impressions (previous period)', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'delta_impressions',
				'label' => __( 'Change in impressions', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'ctr',
				'label' => __( 'CTR (%)', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'position',
				'label' => __( 'Average position', 'searchpilot-pro' ),
			),
			array(
				'key'   => 'prev_position',
				'label' => __( 'Average position (previous period)', 'searchpilot-pro' ),
			),
		);
		$lead    = 'query' === $dimension
			? array(
				array(
					'key'   => 'query',
					'label' => __( 'Query', 'searchpilot-pro' ),
				),
			)
			: array(
				array(
					'key'   => 'page',
					'label' => __( 'Page', 'searchpilot-pro' ),
				),
				array(
					'key'   => 'title',
					'label' => __( 'Title', 'searchpilot-pro' ),
				),
			);
		$snap    = $this->perf->snapshot( $engine, $range );
		return array(
			'filename' => sprintf( 'searchpilot-%s-%s-%s-%s.csv', 'query' === $dimension ? 'queries' : 'pages', $engine, $range, $stamp ),
			'columns'  => array_merge( $lead, $columns ),
			'rows'     => $rows,
			'meta'     => array(
				'from' => (string) ( $snap['from'] ?? '' ),
				'to'   => (string) ( $snap['to'] ?? '' ),
			),
		);
	}

	/**
	 * Opportunities export.
	 *
	 * @param string $stamp Date stamp.
	 * @return array
	 */
	private function export_opportunities( $stamp ) {
		$rows   = array();
		$types  = Opportunities::type_labels();
		$labels = Opportunities::action_labels();
		for ( $page = 1; $page <= 20 && count( $rows ) < self::EXPORT_CAP; $page++ ) {
			$batch = $this->opportunities->query(
				array(
					'page'     => $page,
					'per_page' => 100,
				)
			);
			foreach ( $batch['items'] as $item ) {
				$d      = (array) $item['data'];
				$rows[] = array(
					'type'         => $types[ $item['type'] ] ?? $item['type'],
					'severity'     => $item['severity'],
					'title'        => $item['title'],
					'url'          => isset( $d['page'] ) ? rawurldecode( (string) $d['page'] ) : '',
					'query'        => (string) ( $d['query'] ?? '' ),
					'clicks'       => $d['clicks'] ?? '',
					'impressions'  => $d['impressions'] ?? '',
					'ctr'          => isset( $d['ctr'] ) ? round( (float) $d['ctr'] * 100, 2 ) : '',
					'position'     => $d['position'] ?? '',
					'delta_clicks' => $d['delta_clicks'] ?? '',
					'actions'      => implode(
						', ',
						array_map(
							static function ( $code ) use ( $labels ) {
								return $labels[ $code ] ?? $code;
							},
							(array) ( $d['actions'] ?? array() )
						)
					),
					'what'         => $item['what'],
					'detected_at'  => $item['detected_at'],
				);
			}
			if ( count( $batch['items'] ) < 100 ) {
				break;
			}
		}
		return array(
			'filename' => 'searchpilot-opportunities-' . $stamp . '.csv',
			'columns'  => self::columns(
				array(
					'type'         => __( 'Opportunity', 'searchpilot-pro' ),
					'severity'     => __( 'Severity', 'searchpilot-pro' ),
					'title'        => __( 'Page / query', 'searchpilot-pro' ),
					'url'          => __( 'URL', 'searchpilot-pro' ),
					'query'        => __( 'Top query', 'searchpilot-pro' ),
					'clicks'       => __( 'Clicks', 'searchpilot-pro' ),
					'impressions'  => __( 'Impressions', 'searchpilot-pro' ),
					'ctr'          => __( 'CTR (%)', 'searchpilot-pro' ),
					'position'     => __( 'Average position', 'searchpilot-pro' ),
					'delta_clicks' => __( 'Change in clicks', 'searchpilot-pro' ),
					'actions'      => __( 'Recommended actions', 'searchpilot-pro' ),
					'what'         => __( 'What happened', 'searchpilot-pro' ),
					'detected_at'  => __( 'Detected', 'searchpilot-pro' ),
				)
			),
			'rows'     => $rows,
		);
	}

	/**
	 * Issues export (excluding opportunities).
	 *
	 * @param string $stamp Date stamp.
	 * @return array
	 */
	private function export_issues( $stamp ) {
		$rows = array();
		for ( $page = 1; $page <= 10 && count( $rows ) < self::EXPORT_CAP; $page++ ) {
			$batch = $this->issues->query(
				array(
					'exclude_category' => array( 'opportunity' ),
					'page'             => $page,
					'per_page'         => 500,
				)
			);
			foreach ( $batch['items'] as $item ) {
				$rows[] = array(
					'severity'     => $item['severity'],
					'label'        => $item['label'],
					'category'     => $item['category'],
					'title'        => $item['title'],
					'url'          => $item['url'] ? rawurldecode( (string) $item['url'] ) : '',
					'status'       => $item['status'],
					'what'         => $item['what'],
					'fix'          => $item['fix'],
					'detected_at'  => $item['detected_at'],
					'last_seen_at' => $item['last_seen_at'],
				);
			}
			if ( count( $batch['items'] ) < 500 ) {
				break;
			}
		}
		return array(
			'filename' => 'searchpilot-issues-' . $stamp . '.csv',
			'columns'  => self::columns(
				array(
					'severity'     => __( 'Severity', 'searchpilot-pro' ),
					'label'        => __( 'Issue', 'searchpilot-pro' ),
					'category'     => __( 'Category', 'searchpilot-pro' ),
					'title'        => __( 'Object', 'searchpilot-pro' ),
					'url'          => __( 'URL', 'searchpilot-pro' ),
					'status'       => __( 'Status', 'searchpilot-pro' ),
					'what'         => __( 'What happened', 'searchpilot-pro' ),
					'fix'          => __( 'Recommended fix', 'searchpilot-pro' ),
					'detected_at'  => __( 'Detected', 'searchpilot-pro' ),
					'last_seen_at' => __( 'Last seen', 'searchpilot-pro' ),
				)
			),
			'rows'     => $rows,
		);
	}

	/**
	 * URL registry export.
	 *
	 * @param string $stamp Date stamp.
	 * @return array
	 */
	private function export_urls( $stamp ) {
		$rows = array();
		for ( $page = 1; $page <= 10 && count( $rows ) < self::EXPORT_CAP; $page++ ) {
			$batch = $this->urls->query(
				array(
					'page'     => $page,
					'per_page' => 500,
					'orderby'  => 'id',
					'order'    => 'ASC',
				)
			);
			foreach ( $batch['items'] as $item ) {
				$rows[] = array(
					'url'            => $item['display_url'],
					'object_type'    => $item['object_type'],
					'index_status'   => $item['index_status'],
					'coverage_state' => $item['coverage_state'],
					'http_status'    => $item['http_status'],
					'indexable'      => $item['indexable'] ? 'yes' : 'no',
					'in_sitemap'     => $item['in_sitemap'] ? 'yes' : 'no',
					'canonical_url'  => (string) $item['canonical_url'],
					'last_modified'  => $item['last_modified'],
					'last_submitted' => $item['last_submitted'],
					'last_crawled'   => $item['last_crawled'],
					'last_inspected' => $item['last_inspected'],
				);
			}
			if ( count( $batch['items'] ) < 500 ) {
				break;
			}
		}
		return array(
			'filename' => 'searchpilot-urls-' . $stamp . '.csv',
			'columns'  => self::columns(
				array(
					'url'            => __( 'URL', 'searchpilot-pro' ),
					'object_type'    => __( 'Type', 'searchpilot-pro' ),
					'index_status'   => __( 'Index status', 'searchpilot-pro' ),
					'coverage_state' => __( 'Coverage (Search Console)', 'searchpilot-pro' ),
					'http_status'    => __( 'HTTP status', 'searchpilot-pro' ),
					'indexable'      => __( 'Indexable', 'searchpilot-pro' ),
					'in_sitemap'     => __( 'In sitemap', 'searchpilot-pro' ),
					'canonical_url'  => __( 'Canonical', 'searchpilot-pro' ),
					'last_modified'  => __( 'Last modified', 'searchpilot-pro' ),
					'last_submitted' => __( 'Last submitted', 'searchpilot-pro' ),
					'last_crawled'   => __( 'Last crawled', 'searchpilot-pro' ),
					'last_inspected' => __( 'Last inspected', 'searchpilot-pro' ),
				)
			),
			'rows'     => $rows,
		);
	}

	/**
	 * key => label map to columns.
	 *
	 * @param array $map Map.
	 * @return array
	 */
	private static function columns( array $map ) {
		$out = array();
		foreach ( $map as $key => $label ) {
			$out[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return $out;
	}
}
