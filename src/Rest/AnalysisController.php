<?php
/**
 * Content analysis endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Audit\Rescanner;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\Analysis\AnalysisModule;
use SearchPilot\Seo\Analysis\AnalysisRepository;
use SearchPilot\Seo\Analysis\Analyzer;

defined( 'ABSPATH' ) || exit;

/**
 * POST /analysis/live, GET /analysis/post/{id}, POST /analysis/post/{id}/rescan,
 * GET /analysis/overview, POST /analysis/run.
 */
class AnalysisController extends Controller {

	/**
	 * Analyzer.
	 *
	 * @var Analyzer
	 */
	private $analyzer;

	/**
	 * Content table.
	 *
	 * @var AnalysisRepository
	 */
	private $repo;

	/**
	 * Module.
	 *
	 * @var AnalysisModule
	 */
	private $module;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Re-scanner.
	 *
	 * @var Rescanner
	 */
	private $rescanner;

	/**
	 * Constructor.
	 *
	 * @param Analyzer           $analyzer  Analyzer.
	 * @param AnalysisRepository $repo      Content table.
	 * @param AnalysisModule     $module    Module.
	 * @param IssueRepository    $issues    Issues.
	 * @param Rescanner          $rescanner Re-scanner.
	 */
	public function __construct( Analyzer $analyzer, AnalysisRepository $repo, AnalysisModule $module, IssueRepository $issues, Rescanner $rescanner ) {
		$this->analyzer  = $analyzer;
		$this->repo      = $repo;
		$this->module    = $module;
		$this->issues    = $issues;
		$this->rescanner = $rescanner;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route(
			'/analysis/live',
			'POST',
			array( $this, 'live' ),
			'author',
			array(
				'post_id'        => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'featured_media' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route( '/analysis/post/(?P<id>\d+)', 'GET', array( $this, 'post_analysis' ), 'view', $this->id_arg() );
		$this->route( '/analysis/post/(?P<id>\d+)/rescan', 'POST', array( $this, 'rescan_post' ), 'edit', $this->id_arg() );
		$this->route( '/analysis/overview', 'GET', array( $this, 'overview' ), 'view' );
		$this->route( '/analysis/run', 'POST', array( $this, 'run' ), 'edit' );
	}

	/**
	 * Permission callback; adds the "author" level (edit_posts) used by the live
	 * analysis, which every post author may run in the editor.
	 *
	 * @param string $level manage|view|edit|author.
	 * @return callable
	 */
	protected function permission( $level ) {
		if ( 'author' !== $level ) {
			return parent::permission( $level );
		}
		return static function () {
			return current_user_can( 'edit_posts' ) ? true : new \WP_Error( 'rest_forbidden', __( 'You are not allowed to do that.', 'searchpilot-pro' ), array( 'status' => rest_authorization_required_code() ) );
		};
	}

	/**
	 * POST /analysis/live — analyzes unsaved editor content.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function live( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id ) {
			if ( ! get_post( $post_id ) ) {
				return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
			}
			// Authors analyze their own posts in the editor sidebar: WordPress' own edit_post check applies.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
			}
		}
		$input   = array( 'post_id' => $post_id );
		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$content = is_scalar( $content ) ? (string) $content : '';
			if ( strlen( $content ) > 2 * MB_IN_BYTES ) {
				return $this->error( 'searchpilot_too_large', __( 'The content is too large to analyze (2 MB limit).', 'searchpilot-pro' ), 413 );
			}
			// Analysis renders the content; users without unfiltered_html get the same filtering as on save.
			$input['content'] = current_user_can( 'unfiltered_html' ) ? $content : wp_kses_post( $content );
		}
		// Text::clean() keeps template variables such as %category% intact.
		foreach ( array( 'title', 'focus', 'seo_title' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && is_scalar( $value ) ) {
				$input[ $key ] = \SearchPilot\Support\Text::clean( $value );
			}
		}
		foreach ( array( 'excerpt', 'description' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && is_scalar( $value ) ) {
				$input[ $key ] = \SearchPilot\Support\Text::clean( $value, true );
			}
		}
		$slug = $request->get_param( 'slug' );
		if ( null !== $slug && is_scalar( $slug ) ) {
			$input['slug'] = sanitize_title( (string) $slug );
		}
		$canonical = $request->get_param( 'canonical' );
		if ( null !== $canonical && is_scalar( $canonical ) ) {
			$input['canonical'] = esc_url_raw( trim( (string) $canonical ) );
		}
		$noindex = $request->get_param( 'noindex' );
		if ( null !== $noindex && '' !== $noindex ) {
			$input['noindex'] = rest_sanitize_boolean( $noindex );
		}
		if ( null !== $request->get_param( 'featured_media' ) ) {
			$input['featured_media'] = absint( $request->get_param( 'featured_media' ) );
		}
		$type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( '' !== $type && post_type_exists( $type ) ) {
			$input['post_type'] = $type;
		}
		return $this->respond( $this->analyzer->analyze( $input ) );
	}

	/**
	 * GET /analysis/post/{id} — stored summary plus a fresh analysis.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function post_analysis( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || ! $this->module->is_analyzable( $post ) ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot view this content.', 'searchpilot-pro' ), 403 );
		}
		$stored = $this->repo->get( $id );
		return array(
			'post'     => array(
				'id'        => $id,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'url'       => (string) get_permalink( $post ),
				'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
			),
			'stored'   => $stored ? array(
				'score'             => (int) $stored['seo_score'],
				'word_count'        => (int) $stored['word_count'],
				'inbound_links'     => (int) $stored['inbound_links'],
				'outbound_internal' => (int) $stored['outbound_internal'],
				'outbound_external' => (int) $stored['outbound_external'],
				'failing'           => (array) ( $stored['analysis']['failing'] ?? array() ),
				'rendered'          => $stored['analysis']['rendered'] ?? null,
				'analyzed_at'       => ! empty( $stored['analyzed_at'] ) ? mysql_to_rfc3339( $stored['analyzed_at'] ) : null,
			) : null,
			'analysis' => $this->respond( $this->analyzer->analyze( array( 'post_id' => $id ) ) ),
			'issues'   => $this->issues->for_object( 'post', $id ),
		);
	}

	/**
	 * GET /analysis/overview — score distribution, lowest-scoring posts, issue counts.
	 *
	 * @param \WP_REST_Request $request Request (page, per_page).
	 * @return array
	 */
	public function overview( \WP_REST_Request $request ) {
		global $wpdb;
		list( $page, $per_page ) = $this->paging( $request, 20 );
		$stats                   = $this->repo->stats();
		$lowest                  = $this->repo->lowest( $page, $per_page );
		$items                   = array();
		foreach ( $lowest['items'] as $row ) {
			$id      = (int) $row['post_id'];
			$items[] = array(
				'post_id'     => $id,
				'title'       => get_the_title( $id ),
				'post_type'   => (string) get_post_type( $id ),
				'url'         => (string) get_permalink( $id ),
				'edit_link'   => (string) get_edit_post_link( $id, 'raw' ),
				'score'       => (int) $row['seo_score'],
				'grade'       => Analyzer::grade( (int) $row['seo_score'] ),
				'words'       => (int) $row['word_count'],
				'failing'     => array_values( wp_list_pluck( (array) ( $row['analysis']['failing'] ?? array() ), 'id' ) ),
				'analyzed_at' => ! empty( $row['analyzed_at'] ) ? mysql_to_rfc3339( $row['analyzed_at'] ) : null,
			);
		}
		$types   = array_merge( AnalysisModule::ISSUE_TYPES, array( 'multiple_h1', 'orphan_page', 'broken_internal_link' ) );
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$table   = Tables::issues();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM {$table} WHERE status IN ('open','pending_approval') AND type IN ({$holders}) GROUP BY type", $types ) );
		$counts = array_fill_keys( $types, 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->type ] = (int) $row->n;
		}
		return array(
			'analyzed'     => (int) $stats['analyzed'],
			'average'      => $stats['avg_score'],
			'below_50'     => (int) $stats['below_50'],
			'distribution' => $stats['buckets'],
			'lowest'       => $this->paged( $items, $lowest['total'], $page, $per_page ),
			'issues'       => $counts,
			'disclaimer'   => Analyzer::disclaimer(),
		);
	}

	/**
	 * POST /analysis/post/{id}/rescan — re-fetches the live page past page
	 * caches, re-runs the content analysis and the link checks, and returns
	 * the issues open on the post afterwards (contract C3).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function rescan_post( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || ! $this->module->is_analyzable( $post ) ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! Capabilities::can_edit( $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		$result           = $this->rescanner->rescan_post( $id, array( 'budget' => new TimeBudget( 40 ) ) );
		$result['issues'] = $this->issues->for_object( 'post', $id );
		return $result;
	}

	/**
	 * POST /analysis/run {post_ids?} — re-scans posts for real (B3): the live
	 * page is fetched again past page caches before the analysis runs, so a
	 * fixed page loses its old defects now instead of after the next crawl.
	 * Up to Rescanner::SYNC_MAX posts are re-scanned in this request (posts the
	 * time budget does not reach are queued); larger sets and the whole site
	 * (empty post_ids, administrators only) go to the background queue. Users
	 * who cannot manage SearchPilot may send at most Rescanner::EDITOR_MAX posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function run( \WP_REST_Request $request ) {
		$ids = Rescanner::clean_ids( (array) $request->get_param( 'post_ids' ) );
		if ( ! $ids ) {
			if ( ! Capabilities::can_manage() ) {
				return $this->error( 'rest_forbidden', __( 'Only administrators can re-analyze the whole site.', 'searchpilot-pro' ), 403 );
			}
			// Quick pass first (scores update within minutes), then the real page checks.
			$this->module->queue( array() );
			$total = $this->rescanner->queue_all();
			return array(
				'analyzed' => array(),
				'queued'   => 'all',
				'total'    => $total,
				'skipped'  => array(),
				'progress' => $this->rescanner->queue_status(),
				'message'  => __( 'Every published post will be re-scanned in the background: SearchPilot re-checks the live page, the content analysis and the links of each one. Large sites take a while, because SearchPilot checks at most about one page per second.', 'searchpilot-pro' ),
			);
		}
		$too_many = Rescanner::request_size_error( $ids );
		if ( $too_many ) {
			return $too_many;
		}
		$ids     = array_slice( $ids, 0, Rescanner::QUEUE_MAX );
		$allowed = array();
		$denied  = array();
		foreach ( $ids as $id ) {
			if ( get_post( $id ) && Capabilities::can_edit( $id ) ) {
				$allowed[] = $id;
			} else {
				$denied[] = $id;
			}
		}
		if ( count( $allowed ) > Rescanner::SYNC_MAX ) {
			return array(
				'analyzed' => array(),
				'queued'   => $this->rescanner->queue_rescan( $allowed ),
				'skipped'  => $denied,
				'progress' => $this->rescanner->queue_status(),
			);
		}
		$batch   = $this->rescanner->rescan_objects( $allowed, new TimeBudget( 40 ) );
		$results = array();
		foreach ( $batch['results'] as $id => $result ) {
			$results[] = array(
				'post_id' => (int) $id,
				'score'   => $result['score'],
				'grade'   => '' !== $result['grade'] ? $result['grade'] : null,
				'checked' => (bool) $result['checked'],
				'types'   => $result['types'],
				'error'   => $result['error'],
			);
		}
		return array(
			'analyzed' => $results,
			'queued'   => $batch['remaining'] ? $this->rescanner->queue_rescan( $batch['remaining'] ) : 0,
			'skipped'  => $denied,
		);
	}

	/**
	 * Strips internal keys from an analysis.
	 *
	 * @param array $analysis Analysis.
	 * @return array
	 */
	private function respond( array $analysis ) {
		unset( $analysis['fingerprint'] );
		return $analysis;
	}
}
