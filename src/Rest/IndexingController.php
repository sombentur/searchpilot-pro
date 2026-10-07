<?php
/**
 * Indexing endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Audit\Rescanner;
use SearchPilot\Audit\SiteMonitor;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\Crawler;
use SearchPilot\Indexer\InspectionSync;
use SearchPilot\Indexer\IssueDetector;
use SearchPilot\Indexer\RegistrySync;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * GET /indexing/summary, GET /indexing/urls, GET /indexing/urls/{id},
 * GET /indexing/object, POST /indexing/urls/{id}/(inspect|recheck|submit),
 * POST /indexing/rebuild, POST /indexing/crawl, POST /indexing/resume,
 * POST /indexing/rescan, GET /indexing/rescan, DELETE /indexing/rescan.
 */
class IndexingController extends Controller {

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param UrlRepository   $urls   URLs.
	 * @param IssueRepository $issues Issues.
	 */
	public function __construct( UrlRepository $urls, IssueRepository $issues ) {
		$this->urls   = $urls;
		$this->issues = $issues;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/indexing/summary', 'GET', array( $this, 'summary' ), 'view' );
		$this->route( '/indexing/urls', 'GET', array( $this, 'list_urls' ), 'view' );
		$this->route( '/indexing/urls/(?P<id>\d+)', 'GET', array( $this, 'get_url' ), 'view', $this->id_arg() );
		$this->route(
			'/indexing/object',
			'GET',
			array( $this, 'get_object' ),
			'view',
			array(
				'object_type' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'object_id'   => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route( '/indexing/urls/(?P<id>\d+)/inspect', 'POST', array( $this, 'inspect' ), 'edit', $this->id_arg() );
		$this->route( '/indexing/urls/(?P<id>\d+)/recheck', 'POST', array( $this, 'recheck' ), 'edit', $this->id_arg() );
		$this->route( '/indexing/urls/(?P<id>\d+)/submit', 'POST', array( $this, 'submit' ), 'edit', $this->id_arg() );
		$this->route( '/indexing/rebuild', 'POST', array( $this, 'rebuild' ), 'manage' );
		$this->route( '/indexing/crawl', 'POST', array( $this, 'crawl' ), 'manage' );
		$this->route( '/indexing/resume', 'POST', array( $this, 'resume' ), 'manage' );
		$this->route(
			'/indexing/rescan',
			'POST',
			array( $this, 'rescan' ),
			'edit',
			array(
				'post_ids' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'integer' ),
					'required' => true,
				),
			)
		);
		$this->route( '/indexing/rescan', 'GET', array( $this, 'rescan_status' ), 'view' );
		$this->route( '/indexing/rescan', 'DELETE', array( $this, 'rescan_cancel' ), 'manage' );
	}

	/**
	 * GET /indexing/summary.
	 *
	 * @return array
	 */
	public function summary() {
		/** @var Crawler $crawler */
		$crawler = $this->service( Crawler::class );
		/** @var InspectionSync $inspection */
		$inspection = $this->service( InspectionSync::class );
		/** @var State $state */
		$state = $this->service( State::class );
		/** @var ConflictDetector $conflicts */
		$conflicts = $this->service( ConflictDetector::class );
		/** @var Settings $settings */
		$settings = $this->service( Settings::class );
		$health   = $crawler->health();
		$paused   = $crawler->paused_until();
		$owner    = $conflicts->owner( 'sitemap' );
		$plugins  = $conflicts->detect();

		return array(
			'summary'   => $this->urls->summary(),
			'by_status' => $this->urls->count_by_status(),
			'by_type'   => $this->by_type(),
			'registry'  => $this->service( RegistrySync::class )->progress(),
			'reconcile' => $state->get( RegistrySync::RECONCILE ),
			'crawl'     => array(
				'last_run'     => $state->get( Crawler::LAST_RUN ),
				'paused_until' => $paused ? gmdate( 'c', $paused ) : null,
				'failures'     => (int) $health['consecutive'],
				'last_error'   => (string) $health['last_error'],
				'last_success' => $health['last_success_at'] ? gmdate( 'c', (int) $health['last_success_at'] ) : null,
				'backlog'      => $crawler->backlog(),
				'batch'        => (int) $settings->get( 'indexing.crawl_batch', 20 ),
				'recheck_days' => (int) $settings->get( 'indexing.recheck_days', 14 ),
				'origin_ip'    => '' !== (string) $settings->get( 'indexing.origin_ip', '' ),
			),
			'inspection' => array(
				'connected' => $inspection->available(),
				'quota'     => $inspection->quota(),
				'last_run'  => $state->get( InspectionSync::LAST_RUN ),
			),
			'robots'    => $crawler->robots_info(),
			'bing'      => $this->service( SiteMonitor::class )->bing_crawl(),
			'sitemap'   => array(
				'owner'      => $owner,
				'owner_name' => 'searchpilot' === $owner ? 'SearchPilot' : (string) ( $plugins[ $owner ]['name'] ?? $owner ),
				'owned'      => $conflicts->owns( 'sitemap' ),
			),
		);
	}

	/**
	 * Counts per object type/subtype.
	 *
	 * @return array[]
	 */
	private function by_type() {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT object_type, object_subtype, COUNT(*) AS total,
				SUM( CASE WHEN index_status = 'indexed' THEN 1 ELSE 0 END ) AS indexed,
				SUM( CASE WHEN in_sitemap = 1 THEN 1 ELSE 0 END ) AS in_sitemap,
				SUM( CASE WHEN http_status >= 400 THEN 1 ELSE 0 END ) AS errors
			FROM {$table} WHERE status = 'active' GROUP BY object_type, object_subtype ORDER BY total DESC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'object_type'    => (string) $row['object_type'],
				'object_subtype' => (string) $row['object_subtype'],
				'label'          => self::type_label( (string) $row['object_type'], (string) $row['object_subtype'] ),
				'total'          => (int) $row['total'],
				'indexed'        => (int) $row['indexed'],
				'in_sitemap'     => (int) $row['in_sitemap'],
				'errors'         => (int) $row['errors'],
			);
		}
		return $out;
	}

	/**
	 * Human label of an object type/subtype.
	 *
	 * @param string $type    Object type.
	 * @param string $subtype Subtype.
	 * @return string
	 */
	public static function type_label( $type, $subtype ) {
		switch ( $type ) {
			case 'post':
			case 'attachment':
				$object = get_post_type_object( $subtype );
				return $object ? (string) $object->labels->name : $subtype;
			case 'term':
				$object = get_taxonomy( $subtype );
				return $object ? (string) $object->labels->name : $subtype;
			case 'author':
				return __( 'Author archives', 'searchpilot-pro' );
			case 'home':
				return __( 'Home page', 'searchpilot-pro' );
			case 'archive':
				$object = get_post_type_object( $subtype );
				/* translators: %s: post type name. */
				return $object ? sprintf( __( '%s archive', 'searchpilot-pro' ), $object->labels->name ) : __( 'Archives', 'searchpilot-pro' );
			case 'pagination':
				if ( 'home' === $subtype ) {
					$where = __( 'Home page', 'searchpilot-pro' );
				} elseif ( 'blog' === $subtype ) {
					$where = __( 'Posts page', 'searchpilot-pro' );
				} else {
					$object = get_taxonomy( $subtype );
					$where  = $object ? (string) $object->labels->name : $subtype;
				}
				/* translators: %s: where the paginated archive is (Home page, Categories…). */
				return sprintf( __( 'Paginated archives — %s', 'searchpilot-pro' ), $where );
		}
		return '' !== $subtype ? $subtype : $type;
	}

	/**
	 * GET /indexing/urls.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_urls( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$statuses                = array_values( array_intersect( array_filter( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'index_status' ) ) ) ), UrlRepository::INDEX_STATUSES ) );
		$bool                    = static function ( $value ) {
			$value = (string) $value;
			return in_array( $value, array( '0', '1' ), true ) ? $value : '';
		};
		$http                    = (string) $request->get_param( 'http' );
		$filters                 = array(
			'index_status'       => $statuses,
			'object_type'        => sanitize_key( (string) $request->get_param( 'object_type' ) ),
			'object_subtype'     => sanitize_key( (string) $request->get_param( 'object_subtype' ) ),
			'indexable'          => $bool( $request->get_param( 'indexable' ) ),
			'in_sitemap'         => $bool( $request->get_param( 'in_sitemap' ) ),
			'robots_txt_blocked' => $bool( $request->get_param( 'robots_txt_blocked' ) ),
			'http'               => in_array( $http, array( '2xx', '3xx', '4xx', '5xx' ), true ) ? $http : '',
			'has_errors'         => rest_sanitize_boolean( $request->get_param( 'has_errors' ) ),
			'recommended_action' => sanitize_key( (string) $request->get_param( 'recommended_action' ) ),
			'search'             => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'            => sanitize_key( (string) $request->get_param( 'orderby' ) ),
			'order'              => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
			'page'               => $page,
			'per_page'           => $per_page,
		);
		// http=error: 4xx and 5xx together (the "4xx / 5xx errors" tile).
		$result   = 'error' === $http ? $this->query_http_errors( $filters ) : $this->urls->query( $filters );
		$items    = $result['items'];
		$post_ids = array();
		foreach ( $items as $item ) {
			if ( in_array( $item['object_type'], array( 'post', 'attachment' ), true ) && $item['object_id'] ) {
				$post_ids[] = (int) $item['object_id'];
			}
		}
		if ( $post_ids ) {
			_prime_post_caches( $post_ids, false, false );
		}
		return $this->paged( array_map( array( $this, 'present' ), $items ), $result['total'], $page, $per_page );
	}

	/**
	 * Active rows answering HTTP 400 or higher, with the same filters as
	 * UrlRepository::query() (which only filters one status class at a time).
	 *
	 * @param array $f Sanitized filters.
	 * @return array{items:array,total:int}
	 */
	private function query_http_errors( array $f ) {
		global $wpdb;
		$table  = Tables::urls();
		$where  = array( "status = 'active'", 'http_status >= 400' );
		$params = array();
		if ( ! empty( $f['index_status'] ) ) {
			$where[] = 'index_status IN (' . implode( ',', array_fill( 0, count( $f['index_status'] ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'strval', $f['index_status'] ) );
		}
		foreach ( array( 'object_type', 'object_subtype', 'recommended_action' ) as $field ) {
			if ( '' !== (string) $f[ $field ] ) {
				$where[]  = "{$field} = %s";
				$params[] = (string) $f[ $field ];
			}
		}
		foreach ( array( 'indexable', 'in_sitemap', 'robots_txt_blocked' ) as $field ) {
			if ( '' !== (string) $f[ $field ] ) {
				$where[]  = "{$field} = %d";
				$params[] = (int) $f[ $field ];
			}
		}
		if ( ! empty( $f['has_errors'] ) ) {
			$where[] = "errors IS NOT NULL AND errors NOT IN ('', '[]')";
		}
		if ( '' !== (string) $f['search'] ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( Url::encode_iri( (string) $f['search'] ) ) . '%';
		}
		$orderable = array( 'id', 'url', 'index_status', 'last_submitted', 'last_crawled', 'last_modified', 'last_checked', 'last_inspected', 'http_status', 'priority', 'updated_at' );
		$orderby   = in_array( $f['orderby'], $orderable, true ) ? $f['orderby'] : 'last_modified';
		$order     = 'ASC' === $f['order'] ? 'ASC' : 'DESC';
		$where_sql = implode( ' AND ', $where );
		$offset    = ( (int) $f['page'] - 1 ) * (int) $f['per_page'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( (int) $f['per_page'], $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( $this->urls, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Adds title, edit link and type label to a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function present( array $row ) {
		$id    = (int) $row['object_id'];
		$title = '';
		$edit  = '';
		switch ( $row['object_type'] ) {
			case 'post':
			case 'attachment':
				$title = $id ? get_the_title( $id ) : '';
				$edit  = $id && current_user_can( 'edit_post', $id ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
				break;
			case 'term':
				$term  = $id ? get_term( $id ) : null;
				$title = $term && ! is_wp_error( $term ) ? $term->name : '';
				$edit  = $term && ! is_wp_error( $term ) && current_user_can( 'edit_term', $id ) ? (string) get_edit_term_link( $term ) : '';
				break;
			case 'author':
				$user  = $id ? get_userdata( $id ) : null;
				$title = $user ? $user->display_name : '';
				break;
			case 'home':
				$title = __( 'Home page', 'searchpilot-pro' );
				break;
			case 'pagination':
				$title = __( 'Page 2', 'searchpilot-pro' );
				break;
		}
		$row['title']          = wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );
		$row['edit_link']      = $edit;
		$row['type_label']     = self::type_label( (string) $row['object_type'], (string) $row['object_subtype'] );
		$row['recheck_queued'] = ! empty( $row['last_checked'] ) && 0 === strpos( (string) $row['last_checked'], '2000-01-01' );
		return $row;
	}

	/**
	 * GET /indexing/urls/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_url( \WP_REST_Request $request ) {
		$row = $this->urls->get( (int) $request['id'] );
		if ( ! $row ) {
			return $this->error( 'searchpilot_not_found', __( 'This URL is not in the registry.', 'searchpilot-pro' ), 404 );
		}
		return $this->details( $row );
	}

	/**
	 * Full details payload for a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function details( array $row ) {
		/** @var IssueDetector $detector */
		$detector = $this->service( IssueDetector::class );
		/** @var InspectionSync $inspection */
		$inspection = $this->service( InspectionSync::class );
		/** @var Crawler $crawler */
		$crawler = $this->service( Crawler::class );

		list( $object_type, $object_id ) = $detector->issue_object( $row );
		$stored = $inspection->stored_result( (int) $row['id'] );
		$crawl  = get_transient( 'searchpilot_crawl_' . (int) $row['id'] );
		$link   = is_array( $stored ) && ! empty( $stored['inspection_link'] ) ? (string) $stored['inspection_link'] : $this->inspection_link( (string) $row['url'] );

		return array(
			'url'                => $this->present( $row ),
			'inspection'         => $stored,
			'crawl'              => is_array( $crawl ) ? $crawl : null,
			'issues'             => $this->issues->for_object( $object_type, $object_id ),
			'expected_canonical' => $detector->expected_canonical( $row ),
			'robots'             => $crawler->robots_status( (string) $row['url'] ),
			'inspection_link'    => $link,
			'can_inspect'        => $inspection->available(),
			'submissions'        => array(
				'available' => class_exists( '\SearchPilot\Queue\SubmissionQueue' ),
				'allowed'   => $this->service( Environment::class )->submissions_allowed(),
			),
		);
	}

	/**
	 * Search Console inspection link for a URL ('' when no property is known).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function inspection_link( $url ) {
		$property = '';
		$class    = '\SearchPilot\Api\Google\SearchConsole';
		if ( class_exists( $class ) ) {
			try {
				$gsc = $this->service( $class );
				if ( is_object( $gsc ) && method_exists( $gsc, 'property' ) ) {
					$property = (string) $gsc->property();
				}
			} catch ( \Throwable $e ) {
				$property = '';
			}
		}
		if ( '' === $property ) {
			$property = (string) $this->service( Settings::class )->get( 'engines.google_property', '' );
		}
		if ( '' === $property ) {
			return '';
		}
		return add_query_arg(
			array(
				'resource_id' => rawurlencode( $property ),
				'id'          => rawurlencode( $url ),
			),
			'https://search.google.com/search-console/inspect'
		);
	}

	/**
	 * GET /indexing/object?object_type=&object_id=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_object( \WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$id   = absint( $request->get_param( 'object_id' ) );
		if ( ! in_array( $type, array( 'post', 'attachment', 'term', 'author' ), true ) || ! $id ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose a post, term or author.', 'searchpilot-pro' ) );
		}
		$row = $this->urls->get_by_object( $type, $id );
		if ( ! $row && 'post' === $type ) {
			$row = $this->urls->get_by_object( 'attachment', $id );
		}
		return array(
			'url'    => $row ? $this->present( $row ) : null,
			'issues' => $this->issues->for_object( $type, $id ),
		);
	}

	/**
	 * POST /indexing/urls/{id}/inspect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function inspect( \WP_REST_Request $request ) {
		$row = $this->row_for_action( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$result = $this->service( InspectionSync::class )->inspect_row( $row );
		if ( is_wp_error( $result ) ) {
			if ( 'searchpilot_quota' === $result->get_error_code() ) {
				$result->add_data( array( 'status' => 429 ) );
			}
			return $result;
		}
		return $this->details( $result['row'] );
	}

	/**
	 * POST /indexing/urls/{id}/recheck.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function recheck( \WP_REST_Request $request ) {
		$row = $this->row_for_action( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// A manual re-check follows a change the user made: look past page caches.
		$result = $this->service( Crawler::class )->check_row( $row, new TimeBudget( 40 ), array( 'bust_cache' => true ) );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => 502 ) );
			}
			return $result;
		}
		return $this->details( $result['row'] );
	}

	/**
	 * POST /indexing/urls/{id}/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$row = $this->row_for_action( (int) $request['id'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$class = '\SearchPilot\Queue\SubmissionQueue';
		if ( ! class_exists( $class ) ) {
			return $this->error( 'searchpilot_unavailable', __( 'The submission queue is not available.', 'searchpilot-pro' ), 501 );
		}
		$queue = $this->service( $class );
		if ( ! is_object( $queue ) || ! method_exists( $queue, 'enqueue_url' ) ) {
			return $this->error( 'searchpilot_unavailable', __( 'The submission queue is not available.', 'searchpilot-pro' ), 501 );
		}
		$queued  = (array) $queue->enqueue_url(
			(string) $row['url'],
			'update',
			array(
				'object_type'    => (string) $row['object_type'],
				'object_id'      => (int) $row['object_id'],
				'reason'         => 'manual',
				'user_initiated' => true,
			)
		);
		$allowed = $this->service( Environment::class )->submissions_allowed();
		$count   = count( array_filter( $queued ) );
		return array(
			'queued'  => $queued,
			'held'    => ! $allowed,
			'message' => ! $allowed
				? __( 'Queued, but held: this site is not a production site (or setup is not finished), so nothing is sent to search engines.', 'searchpilot-pro' )
				: ( $count
					/* translators: %d: number of services. */
					? sprintf( _n( 'Queued for %d search engine service.', 'Queued for %d search engine services.', $count, 'searchpilot-pro' ), $count )
					: __( 'Nothing was queued: the URL was notified recently or no search engine service is enabled.', 'searchpilot-pro' ) ),
		);
	}

	/**
	 * POST /indexing/rebuild.
	 *
	 * @return array
	 */
	public function rebuild() {
		/** @var RegistrySync $registry */
		$registry = $this->service( RegistrySync::class );
		$registry->start_build( 'manual' );
		$progress = Lock::run(
			'registry',
			120,
			static function () use ( $registry ) {
				return $registry->run_build( new TimeBudget( 15 ) );
			}
		);
		if ( $registry->is_building() ) {
			$this->service( \SearchPilot\Core\Scheduler::class )->enqueue_async( 'searchpilot_indexer_refresh', array(), 30 );
		}
		return array(
			'registry' => is_array( $progress ) ? $progress : $registry->progress(),
			'summary'  => $this->urls->summary(),
		);
	}

	/**
	 * POST /indexing/crawl.
	 *
	 * @return array
	 */
	public function crawl() {
		$stats = $this->service( Crawler::class )->run_batch( 25, 0, 'manual' );
		return array(
			'crawl'   => $stats,
			'summary' => $this->urls->summary(),
		);
	}

	/**
	 * POST /indexing/rescan {post_ids:[…]} — real re-scans (live page past page
	 * caches, content analysis, link checks): synchronous for up to
	 * Rescanner::SYNC_MAX posts (posts the time budget does not reach are
	 * queued), queued in the background otherwise. Users who cannot manage
	 * SearchPilot may send at most Rescanner::EDITOR_MAX posts per request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function rescan( \WP_REST_Request $request ) {
		$ids = Rescanner::clean_ids( (array) $request->get_param( 'post_ids' ) );
		if ( ! $ids ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose at least one post to re-scan.', 'searchpilot-pro' ) );
		}
		$too_many = Rescanner::request_size_error( $ids );
		if ( $too_many ) {
			return $too_many;
		}
		$allowed = array();
		$denied  = array();
		foreach ( array_slice( $ids, 0, Rescanner::QUEUE_MAX ) as $id ) {
			if ( get_post( $id ) && Capabilities::can_edit( $id ) ) {
				$allowed[] = $id;
			} else {
				$denied[] = $id;
			}
		}
		/** @var Rescanner $rescanner */
		$rescanner = $this->service( Rescanner::class );
		if ( count( $allowed ) > Rescanner::SYNC_MAX ) {
			return array(
				'results'  => array(),
				'queued'   => $rescanner->queue_rescan( $allowed ),
				'skipped'  => $denied,
				'progress' => $rescanner->queue_status(),
			);
		}
		$batch = $allowed ? $rescanner->rescan_objects( $allowed, new TimeBudget( 40 ) ) : array(
			'results'   => array(),
			'remaining' => array(),
		);
		return array(
			'results'  => array_values( $batch['results'] ),
			'queued'   => $batch['remaining'] ? $rescanner->queue_rescan( $batch['remaining'] ) : 0,
			'skipped'  => $denied,
			'progress' => $rescanner->queue_status(),
		);
	}

	/**
	 * GET /indexing/rescan — progress of the background re-scan queue.
	 *
	 * @return array
	 */
	public function rescan_status() {
		return $this->service( Rescanner::class )->queue_status();
	}

	/**
	 * DELETE /indexing/rescan — empties the background re-scan queue.
	 *
	 * @return array
	 */
	public function rescan_cancel() {
		/** @var Rescanner $rescanner */
		$rescanner = $this->service( Rescanner::class );
		$cancelled = $rescanner->cancel();
		return array(
			'cancelled' => $cancelled,
			'progress'  => $rescanner->queue_status(),
		);
	}

	/**
	 * POST /indexing/resume (clears a crawl pause).
	 *
	 * @return array
	 */
	public function resume() {
		$this->service( Crawler::class )->resume();
		return array( 'resumed' => true );
	}

	/**
	 * Loads a row for an action and checks object-level rights.
	 *
	 * @param int $id Row id.
	 * @return array|\WP_Error
	 */
	private function row_for_action( $id ) {
		$row = $this->urls->get( $id );
		if ( ! $row ) {
			return $this->error( 'searchpilot_not_found', __( 'This URL is not in the registry.', 'searchpilot-pro' ), 404 );
		}
		if ( in_array( $row['object_type'], array( 'post', 'attachment' ), true ) && $row['object_id'] ) {
			if ( ! Capabilities::can_edit( (int) $row['object_id'] ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
			}
		} elseif ( ! Capabilities::can_manage() ) {
			return $this->error( 'rest_forbidden', __( 'Only administrators can run this action for site-wide URLs.', 'searchpilot-pro' ), 403 );
		}
		if ( ! Url::is_internal( (string) $row['url'] ) ) {
			return $this->error( 'searchpilot_invalid', __( 'This URL does not belong to the site.', 'searchpilot-pro' ) );
		}
		return $row;
	}
}
