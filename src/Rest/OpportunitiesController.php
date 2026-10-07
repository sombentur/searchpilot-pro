<?php
/**
 * Content opportunity endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Analytics\Opportunities;
use SearchPilot\Analytics\PerformanceRepository;
use SearchPilot\Analytics\Suggestions;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Issues\Issues;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * GET /opportunities, GET /opportunities/summary,
 * GET /opportunities/{id}/suggestions, POST /opportunities/{id}/apply,
 * POST /opportunities/{id}/dismiss.
 *
 * Applying writes only the value the editor submitted, through MetaStore
 * (the owning SEO plugin's field in companion mode), logged and undoable.
 */
class OpportunitiesController extends Controller {

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
	private $repo;

	/**
	 * Issue actions.
	 *
	 * @var Issues
	 */
	private $issues;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Performance data.
	 *
	 * @var PerformanceRepository
	 */
	private $perf;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param Opportunities         $opportunities Opportunities.
	 * @param IssueRepository       $repo          Issues.
	 * @param Issues                $issues        Issue actions.
	 * @param MetaStore             $meta          Meta store.
	 * @param PerformanceRepository $perf          Performance data.
	 * @param ConflictDetector      $conflicts     Conflict detector.
	 */
	public function __construct( Opportunities $opportunities, IssueRepository $repo, Issues $issues, MetaStore $meta, PerformanceRepository $perf, ConflictDetector $conflicts ) {
		$this->opportunities = $opportunities;
		$this->repo          = $repo;
		$this->issues        = $issues;
		$this->meta          = $meta;
		$this->perf          = $perf;
		$this->conflicts     = $conflicts;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/opportunities', 'GET', array( $this, 'list_items' ), 'view' );
		$this->route( '/opportunities/summary', 'GET', array( $this, 'summary' ), 'view' );
		$this->route( '/opportunities/(?P<id>\d+)/suggestions', 'GET', array( $this, 'suggestions' ), 'edit', $this->id_arg() );
		$this->route(
			'/opportunities/(?P<id>\d+)/apply',
			'POST',
			array( $this, 'apply' ),
			'edit',
			array_merge(
				$this->id_arg(),
				array(
					'field' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'title', 'description' ),
					),
					'value' => array(
						'type'     => 'string',
						'required' => true,
					),
				)
			)
		);
		$this->route( '/opportunities/(?P<id>\d+)/dismiss', 'POST', array( $this, 'dismiss' ), 'edit', $this->id_arg() );
	}

	/**
	 * GET /opportunities?action=&severity=&type=&search=&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_items( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 20 );
		$csv                     = static function ( $value ) {
			$value = is_array( $value ) ? $value : explode( ',', (string) $value );
			return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
		};
		$action                  = sanitize_key( (string) $request->get_param( 'action' ) );
		$status                  = sanitize_key( (string) $request->get_param( 'status' ) );
		$result                  = $this->opportunities->query(
			array(
				'action'    => in_array( $action, Opportunities::ACTIONS, true ) ? $action : '',
				'severity'  => array_intersect( $csv( $request->get_param( 'severity' ) ), array( 'high', 'medium', 'opportunity' ) ),
				'type'      => array_intersect( $csv( $request->get_param( 'type' ) ), Opportunities::TYPES ),
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'object_id' => absint( $request->get_param( 'post_id' ) ),
				'status'    => in_array( $status, array( 'ignored', 'resolved', 'any' ), true ) ? $status : 'open',
				'page'      => $page,
				'per_page'  => $per_page,
			)
		);
		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * GET /opportunities/summary.
	 *
	 * @return array
	 */
	public function summary() {
		return $this->opportunities->summary();
	}

	/**
	 * Loads an opportunity on a post the user may edit.
	 *
	 * @param int $id Issue id.
	 * @return array|\WP_Error Issue.
	 */
	private function post_issue( $id ) {
		$issue = $this->repo->get( $id );
		if ( ! $issue || 'opportunity' !== $issue['category'] ) {
			return $this->error( 'searchpilot_not_found', __( 'Opportunity not found.', 'searchpilot-pro' ), 404 );
		}
		if ( 'post' !== $issue['object_type'] || ! $issue['object_id'] || ! get_post( $issue['object_id'] ) ) {
			return $this->error( 'searchpilot_invalid', __( 'This opportunity is not linked to a post, so there is no title or description to edit.', 'searchpilot-pro' ), 400 );
		}
		if ( ! Capabilities::can_edit( $issue['object_id'] ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		return $issue;
	}

	/**
	 * GET /opportunities/{id}/suggestions — current values, owning plugin and drafts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function suggestions( \WP_REST_Request $request ) {
		$issue = $this->post_issue( (int) $request['id'] );
		if ( is_wp_error( $issue ) ) {
			return $issue;
		}
		$post    = get_post( $issue['object_id'] );
		$url     = (string) ( $issue['url'] ? $issue['url'] : get_permalink( $post ) );
		$queries = $this->perf->queries_for_page( $url, '28d', 5 );
		$query   = (string) ( $issue['data']['query'] ?? '' );
		if ( '' === $query && $queries ) {
			$query = (string) $queries[0]['query'];
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title   = $this->meta->effective_title( $post );
		$desc    = $this->meta->effective_description( $post, false );
		$content = function_exists( 'excerpt_remove_blocks' ) ? excerpt_remove_blocks( $post->post_content ) : $post->post_content;
		$post_h  = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );

		return array(
			'issue_id'    => (int) $issue['id'],
			'post_id'     => (int) $post->ID,
			'post_title'  => $post_h,
			'url'         => $url,
			'edit_link'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'query'       => $query,
			'queries'     => $queries,
			'site_name'   => $site,
			'title'       => array(
				'current'     => $title,
				'raw'         => (string) $this->meta->get_post_field( $post->ID, 'title' ),
				'store'       => $this->meta->store_for( 'title' ),
				'store_name'  => $this->store_name( $this->meta->store_for( 'title' ) ),
				'max'         => Suggestions::TITLE_MAX,
				'suggestions' => Suggestions::titles(
					$title,
					$query,
					array(
						'site_name'  => $site,
						'post_title' => $post_h,
					)
				),
			),
			'description' => array(
				'current'     => $desc,
				'raw'         => (string) $this->meta->get_post_field( $post->ID, 'description' ),
				'store'       => $this->meta->store_for( 'description' ),
				'store_name'  => $this->store_name( $this->meta->store_for( 'description' ) ),
				'min'         => Suggestions::DESC_MIN,
				'max'         => Suggestions::DESC_MAX,
				'suggestions' => Suggestions::descriptions(
					$content,
					$query,
					array(
						'current' => $desc,
						'excerpt' => has_excerpt( $post ) ? $post->post_excerpt : '',
					)
				),
			),
			'applied'     => array(
				'at'       => isset( $issue['data']['applied_at'] ) ? mysql_to_rfc3339( (string) $issue['data']['applied_at'] ) : null,
				'field'    => (string) ( $issue['data']['applied_field'] ?? '' ),
				'activity' => (int) ( $issue['data']['applied_activity'] ?? 0 ),
			),
			'note'        => __( 'These are drafts built from your current title, the post’s opening sentences and the query people searched for. Review and edit them — nothing changes until you apply.', 'searchpilot-pro' ),
		);
	}

	/**
	 * POST /opportunities/{id}/apply {field, value}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		$issue = $this->post_issue( (int) $request['id'] );
		if ( is_wp_error( $issue ) ) {
			return $issue;
		}
		$field = (string) $request->get_param( 'field' );
		if ( ! in_array( $field, array( 'title', 'description' ), true ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose the title or the description.', 'searchpilot-pro' ) );
		}
		$value = \SearchPilot\Support\Text::clean( $request->get_param( 'value' ) );
		$value = mb_substr( $value, 0, 'title' === $field ? 200 : 400, 'UTF-8' );
		if ( '' === $value ) {
			return $this->error( 'searchpilot_invalid', __( 'Enter a value before applying.', 'searchpilot-pro' ) );
		}
		$post_id = (int) $issue['object_id'];
		$labels  = MetaStore::field_labels();
		$result  = $this->meta->set_post_field(
			$post_id,
			$field,
			$value,
			array(
				'source'      => 'user',
				/* translators: 1: field label, 2: post title. */
				'description' => sprintf( __( 'Updated the %1$s of “%2$s” from Content Opportunities', 'searchpilot-pro' ), $labels[ $field ] ?? $field, get_the_title( $post_id ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$activity_id = (int) $result;
		if ( $activity_id ) {
			$this->opportunities->mark_applied( (int) $issue['id'], $field, $activity_id );
			if ( class_exists( '\SearchPilot\Core\Compat\CachePurger' ) ) {
				try {
					$purger = $this->service( '\SearchPilot\Core\Compat\CachePurger' );
					if ( method_exists( $purger, 'purge_post' ) ) {
						$purger->purge_post( $post_id );
					}
				} catch ( \Throwable $e ) {
					unset( $e ); // Cache purging is best effort.
				}
			}
		}
		$post  = get_post( $post_id );
		$fresh = $this->repo->get( (int) $issue['id'] );
		return array(
			'activity_id' => $activity_id,
			'unchanged'   => 0 === $activity_id,
			'field'       => $field,
			'store'       => $this->meta->store_for( $field ),
			'effective'   => array(
				'title'       => $this->meta->effective_title( $post ),
				'description' => $this->meta->effective_description( $post, false ),
			),
			'issue'       => $fresh ? $this->opportunities->present( $fresh ) : null,
			'message'     => 0 === $activity_id ? __( 'That is already the current value — nothing changed.', 'searchpilot-pro' ) : __( 'Saved. The opportunity stays open until new search data shows the effect.', 'searchpilot-pro' ),
		);
	}

	/**
	 * POST /opportunities/{id}/dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function dismiss( \WP_REST_Request $request ) {
		$issue = $this->repo->get( (int) $request['id'] );
		if ( ! $issue || 'opportunity' !== $issue['category'] ) {
			return $this->error( 'searchpilot_not_found', __( 'Opportunity not found.', 'searchpilot-pro' ), 404 );
		}
		if ( 'post' === $issue['object_type'] && $issue['object_id'] ) {
			if ( ! Capabilities::can_edit( $issue['object_id'] ) ) {
				return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
			}
		} elseif ( ! Capabilities::can_manage() ) {
			return $this->error( 'rest_forbidden', __( 'Only administrators can dismiss site-wide opportunities.', 'searchpilot-pro' ), 403 );
		}
		$this->issues->ignore( (int) $issue['id'] );
		$fresh = $this->repo->get( (int) $issue['id'] );
		return array(
			'dismissed' => true,
			'issue'     => $fresh ? $this->opportunities->present( $fresh ) : null,
		);
	}

	/**
	 * Human name of a meta store.
	 *
	 * @param string $store Store id.
	 * @return string
	 */
	private function store_name( $store ) {
		$names = array(
			'searchpilot' => __( 'SearchPilot', 'searchpilot-pro' ),
			'yoast'       => 'Yoast SEO',
			'rank_math'   => 'Rank Math',
			'aioseo'      => 'All in One SEO',
			'seopress'    => 'SEOPress',
			'tsf'         => 'The SEO Framework',
		);
		$detected = $this->conflicts->detect();
		if ( isset( $detected[ $store ]['name'] ) && '' !== (string) $detected[ $store ]['name'] ) {
			return (string) $detected[ $store ]['name'];
		}
		return $names[ $store ] ?? $store;
	}
}
