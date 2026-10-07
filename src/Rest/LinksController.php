<?php
/**
 * Internal links endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Settings;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\LinkGraph;
use SearchPilot\Links\LinksModule;
use SearchPilot\Links\SuggestionRepository;
use SearchPilot\Links\Suggester;
use SearchPilot\Links\TargetFinder;
use SearchPilot\Seo\Analysis\AnalysisRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /links/suggestions, POST /links/suggestions/{id}/apply|dismiss, POST /links/suggestions/bulk,
 * POST /links/generate, GET /links/orphans, GET /links/stats, GET /links/post/{id}, POST /links/reindex,
 * GET /links/weak, POST /links/weak/remove.
 */
class LinksController extends Controller {

	/**
	 * Suggestions.
	 *
	 * @var SuggestionRepository
	 */
	private $repo;

	/**
	 * Suggester.
	 *
	 * @var Suggester
	 */
	private $suggester;

	/**
	 * Graph.
	 *
	 * @var LinkGraph
	 */
	private $graph;

	/**
	 * Index.
	 *
	 * @var ContentIndex
	 */
	private $index;

	/**
	 * Module.
	 *
	 * @var LinksModule
	 */
	private $module;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param SuggestionRepository $repo      Suggestions.
	 * @param Suggester            $suggester Suggester.
	 * @param LinkGraph            $graph     Graph.
	 * @param ContentIndex         $index     Index.
	 * @param LinksModule          $module    Module.
	 * @param Settings             $settings  Settings.
	 * @param AutomationPolicy     $policy    Policy.
	 */
	public function __construct( SuggestionRepository $repo, Suggester $suggester, LinkGraph $graph, ContentIndex $index, LinksModule $module, Settings $settings, AutomationPolicy $policy ) {
		$this->repo      = $repo;
		$this->suggester = $suggester;
		$this->graph     = $graph;
		$this->index     = $index;
		$this->module    = $module;
		$this->settings  = $settings;
		$this->policy    = $policy;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/links/suggestions', 'GET', array( $this, 'suggestions' ), 'view' );
		$this->route( '/links/suggestions/(?P<id>\d+)/apply', 'POST', array( $this, 'apply' ), 'edit', $this->id_arg() );
		$this->route( '/links/suggestions/(?P<id>\d+)/dismiss', 'POST', array( $this, 'dismiss' ), 'edit', $this->id_arg() );
		$this->route( '/links/suggestions/bulk', 'POST', array( $this, 'bulk' ), 'edit' );
		$this->route(
			'/links/generate',
			'POST',
			array( $this, 'generate' ),
			'edit',
			array(
				'post_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			)
		);
		$this->route( '/links/orphans', 'GET', array( $this, 'orphans' ), 'view' );
		$this->route( '/links/related/(?P<id>\d+)', 'GET', array( $this, 'related' ), 'view', $this->id_arg() );
		$this->route( '/links/stats', 'GET', array( $this, 'stats' ), 'view' );
		$this->route( '/links/post/(?P<id>\d+)', 'GET', array( $this, 'post_links' ), 'view', $this->id_arg() );
		$this->route( '/links/reindex', 'POST', array( $this, 'reindex' ), 'manage' );
		$this->route( '/links/weak', 'GET', array( $this, 'weak' ), 'view' );
		$this->route( '/links/weak/remove', 'POST', array( $this, 'remove_weak' ), 'edit' );
	}

	/**
	 * GET /links/suggestions?post_id=&direction=outbound|inbound&status=pending&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function suggestions( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$post_id                 = absint( $request->get_param( 'post_id' ) );
		$status                  = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'pending' ) );
		if ( 'all' !== $status && ! in_array( $status, SuggestionRepository::STATUSES, true ) ) {
			$status = 'pending';
		}
		$direction = sanitize_key( (string) $request->get_param( 'direction' ) );
		$result    = $this->repo->query(
			array(
				'post_id'    => $post_id,
				'direction'  => in_array( $direction, array( 'outbound', 'inbound' ), true ) ? $direction : '',
				'status'     => $status,
				'trigger_id' => absint( $request->get_param( 'trigger_id' ) ),
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);
		$items = array();
		foreach ( $result['items'] as $row ) {
			$items[] = $this->repo->present( $row, $post_id );
		}
		return $this->paged( $items, $result['total'], $page, $per_page, array( 'counts' => $this->repo->counts() ) );
	}

	/**
	 * POST /links/suggestions/{id}/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		$check = $this->check( (int) $request['id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $this->suggester->apply( (int) $request['id'], 'user' );
	}

	/**
	 * POST /links/suggestions/{id}/dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function dismiss( \WP_REST_Request $request ) {
		$check = $this->check( (int) $request['id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$this->suggester->dismiss( (int) $request['id'] );
		return array( 'suggestion' => $this->repo->present( $this->repo->get( (int) $request['id'] ) ) );
	}

	/**
	 * POST /links/suggestions/bulk {ids, action: apply|dismiss}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) ), 0, 100 );
		if ( ! in_array( $action, array( 'apply', 'dismiss' ), true ) || ! $ids ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose suggestions and an action.', 'searchpilot-pro' ) );
		}
		$done   = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			$check = $this->check( $id );
			if ( is_wp_error( $check ) ) {
				$errors[ $id ] = $check->get_error_message();
				continue;
			}
			if ( 'apply' === $action ) {
				$result = $this->suggester->apply( $id, 'user' );
				if ( is_wp_error( $result ) ) {
					$errors[ $id ] = $result->get_error_message();
					continue;
				}
			} else {
				$this->suggester->dismiss( $id );
			}
			++$done;
		}
		return array(
			'done'   => $done,
			'errors' => (object) $errors,
		);
	}

	/**
	 * POST /links/generate {post_id} — finds link opportunities for a post now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function generate( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! Capabilities::can_edit( $post_id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		if ( ! $this->index->is_eligible( $post ) ) {
			return $this->error( 'searchpilot_not_eligible', __( 'This post is not part of internal linking: it must be published, indexable, of a post type selected in Internal Links → Settings, and not excluded.', 'searchpilot-pro' ), 409 );
		}
		$row = $this->service( AnalysisRepository::class )->get( $post_id );
		if ( ! $row || empty( $row['indexed_at'] ) || strtotime( $row['indexed_at'] . ' UTC' ) < strtotime( $post->post_modified_gmt . ' UTC' ) ) {
			$done = Lock::run(
				LinksModule::LOCK,
				120,
				function () use ( $post_id ) {
					$this->module->process_post( $post_id );
					return true;
				}
			);
			if ( null === $done && ! $row ) {
				return $this->error( 'searchpilot_busy', __( 'The link index is being updated. Try again in a minute.', 'searchpilot-pro' ), 409 );
			}
		}
		$result = $this->suggester->generate( $post_id );
		$items  = array();
		$kinds  = array(
			'inline'  => 0,
			'related' => 0,
		);
		foreach ( array_merge( $result['outbound'], $result['inbound'] ) as $id ) {
			$s = $this->repo->get( $id );
			if ( $s ) {
				$items[] = $this->repo->present( $s, $post_id );
				++$kinds[ $s['kind'] ];
			}
		}
		if ( ! $items ) {
			$message = __( 'No related published post can take a link right now: none was found, or they already have their maximum number of links. You can still add links by hand.', 'searchpilot-pro' );
		} elseif ( $kinds['related'] && $kinds['inline'] ) {
			/* translators: 1: number of in-text links, 2: number of related-list entries. */
			$message = sprintf( __( 'Link suggestions are ready for review: %1$d in the text and %2$d for a related-articles list.', 'searchpilot-pro' ), $kinds['inline'], $kinds['related'] );
		} elseif ( $kinds['related'] ) {
			/* translators: %d: number of related-list entries. */
			$message = sprintf( _n( 'No sentence names these topics, so %d related article is suggested for a related-articles list.', 'No sentence names these topics, so %d related articles are suggested for a related-articles list.', $kinds['related'], 'searchpilot-pro' ), $kinds['related'] );
		} else {
			$message = __( 'Link suggestions are ready for review.', 'searchpilot-pro' );
		}
		return array(
			'outbound'    => count( $result['outbound'] ),
			'inbound'     => count( $result['inbound'] ),
			'kinds'       => $kinds,
			'items'       => $items,
			'index_ready' => $this->index->is_ready(),
			'message'     => $message,
		);
	}

	/**
	 * GET /links/orphans — orphan pages with posts that could link to them.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function orphans( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 20 );
		$result                  = $this->graph->orphans( $page, $per_page );
		$items                   = array();
		foreach ( $result['ids'] as $id ) {
			$post = get_post( $id );
			// Only cached relations here (fast); the screen loads the rest per row via /links/related/{id}.
			$cached  = $this->service( TargetFinder::class )->cached( $id, 3 );
			$related = null === $cached ? null : $this->present_related( $cached );
			$items[] = array(
				'post_id'   => $id,
				'title'     => Suggester::plain_title( $id ),
				'url'       => (string) get_permalink( $id ),
				'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
				'post_type' => $post ? $post->post_type : '',
				'date'      => $post ? mysql_to_rfc3339( $post->post_date_gmt ) : null,
				'inbound'   => 0,
				'can_edit'  => current_user_can( 'edit_post', $id ),
				'related'   => $related,
			);
		}
		return $this->paged( $items, $result['total'], $page, $per_page, array( 'index' => $this->module->status() ) );
	}

	/**
	 * GET /links/related/{id}?limit= — posts related to a post (cached).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function related( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot view this content.', 'searchpilot-pro' ), 403 );
		}
		$limit = (int) $request->get_param( 'limit' );
		$limit = in_array( $limit, array( 3, 5, 8 ), true ) ? $limit : 3;
		return array(
			'post_id' => $id,
			'items'   => $this->present_related( $this->service( TargetFinder::class )->find( $id, $limit ) ),
		);
	}

	/**
	 * Formats related() rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private function present_related( array $rows ) {
		$out = array();
		foreach ( $rows as $rel ) {
			$out[] = array(
				'post_id'   => (int) $rel['post_id'],
				'title'     => Suggester::plain_title( (int) $rel['post_id'] ),
				'score'     => (float) $rel['score'],
				'tier'      => (string) ( $rel['tier'] ?? 'related' ),
				'edit_link' => (string) get_edit_post_link( $rel['post_id'], 'raw' ),
				'url'       => (string) get_permalink( $rel['post_id'] ),
			);
		}
		return $out;
	}

	/**
	 * GET /links/stats.
	 *
	 * @return array
	 */
	public function stats() {
		$stats                        = $this->graph->stats();
		$counts                       = $this->repo->counts();
		$stats['pending_suggestions'] = (int) $counts['pending'];
		$stats['suggestions']         = $counts;
		$stats['pending_by_kind']     = $this->repo->pending_by_kind();
		$weak                         = $this->module->weak_links_issue();
		$stats['weak_links']          = $weak ? (int) ( $weak['data']['count'] ?? 0 ) : 0;
		$stats['index']               = $this->module->status();
		$stats['auto']                = array(
			'mode'                     => $this->policy->mode(),
			'policy'                   => $this->policy->policy( AutomationPolicy::TASK_INTERNAL_LINKS ),
			'auto_insert'              => (bool) $this->settings->get( 'links.auto_insert' ),
			'auto_related_block'       => (bool) $this->settings->get( 'links.auto_related_block', false ),
			'related_max'              => $this->suggester->related_max(),
			'suggestions'              => (bool) $this->settings->get( 'links.suggestions', true ),
			'max_auto_per_post'        => (int) $this->settings->get( 'links.max_auto_per_post', 3 ),
			'max_links_per_post'       => (int) $this->settings->get( 'links.max_links_per_post', 12 ),
			'words_per_link'           => (int) $this->settings->get( 'links.words_per_link', 150 ),
			'max_inbound_per_new_post' => (int) $this->settings->get( 'links.max_inbound_per_new_post', 5 ),
			'min_relevance'            => (int) $this->settings->get( 'links.min_relevance', 25 ),
		);
		return $stats;
	}

	/**
	 * GET /links/post/{id} — inbound and outbound links of a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function post_links( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'searchpilot_not_found', __( 'Post not found.', 'searchpilot-pro' ), 404 );
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot view this content.', 'searchpilot-pro' ), 403 );
		}
		$row = $this->service( AnalysisRepository::class )->get( $id );
		return array(
			'post'     => array(
				'id'        => $id,
				'title'     => Suggester::plain_title( $post ),
				'url'       => (string) get_permalink( $post ),
				'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
				'indexed'   => $row && ! empty( $row['indexed_at'] ),
				'eligible'  => $this->index->is_eligible( $post ),
			),
			'inbound'  => $this->graph->edges_to( $id ),
			'outbound' => $this->graph->edges_from( $id ),
			'counts'   => array(
				'inbound'           => $row ? (int) $row['inbound_links'] : 0,
				'outbound_internal' => $row ? (int) $row['outbound_internal'] : 0,
				'outbound_external' => $row ? (int) $row['outbound_external'] : 0,
			),
		);
	}

	/**
	 * POST /links/reindex {truncate?} — rebuilds the content graph in the background.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function reindex( \WP_REST_Request $request ) {
		$this->module->start_build( rest_sanitize_boolean( $request->get_param( 'truncate' ) ) );
		return $this->module->run_build_batch( new TimeBudget( 10 ) );
	}

	/**
	 * GET /links/weak — links SearchPilot inserted automatically whose link text
	 * fails the current anchor rules (also refreshes the site-level issue, so
	 * the SEO Audit shows the same list).
	 *
	 * @return array
	 */
	public function weak() {
		$result = $this->module->refresh_weak_links_issue( 500 );
		$items  = array();
		foreach ( $result['items'] as $item ) {
			$item['can_edit'] = current_user_can( 'edit_post', $item['post_id'] );
			unset( $item['marker'], $item['html'] );
			$items[] = $item;
		}
		return array(
			'items' => $items,
			'total' => count( $items ),
			'more'  => count( $items ) >= 500,
			'issue' => $this->present_issue( $this->module->weak_links_issue() ),
		);
	}

	/**
	 * POST /links/weak/remove {keys: string[]} — removes the chosen links through
	 * the fix engine (preview-free single removals from the review panel; one
	 * activity group, undoable). "Remove all" uses the issue's fix preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function remove_weak( \WP_REST_Request $request ) {
		$keys = array();
		foreach ( (array) $request->get_param( 'keys' ) as $key ) {
			$key = (string) $key;
			if ( preg_match( '/^(?:[as][0-9]{1,20}|f[0-9]{1,20}-[a-f0-9]{10})$/', $key ) ) {
				$keys[] = $key;
			}
		}
		$keys = array_slice( array_values( array_unique( $keys ) ), 0, 100 );
		if ( ! $keys ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose the links to remove.', 'searchpilot-pro' ) );
		}
		$engine = '\SearchPilot\Fixes\FixEngine';
		if ( ! class_exists( $engine ) ) {
			return $this->error( 'searchpilot_unavailable', __( 'The fix engine is not available.', 'searchpilot-pro' ), 500 );
		}
		$engine = $this->service( $engine );
		$plan   = $engine->plan_for(
			array(
				'id'          => 0,
				'type'        => 'weak_auto_links',
				'status'      => 'open',
				'object_type' => 'site',
				'object_id'   => 0,
				'only'        => $keys,
			)
		);
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$result = $engine->apply( $plan, 'user' );
		if ( empty( $result['applied'] ) ) {
			return $this->error( '' !== (string) ( $result['code'] ?? '' ) ? (string) $result['code'] : 'searchpilot_fix_failed', (string) ( $result['error'] ?? __( 'The links could not be removed.', 'searchpilot-pro' ) ), 409 );
		}
		$left = $this->module->refresh_weak_links_issue( 500 );
		return array_merge(
			$result,
			array(
				'removed'   => count( $keys ),
				'remaining' => $left['count'],
				'issue'     => $this->present_issue( $this->module->weak_links_issue() ),
			)
		);
	}

	/**
	 * The issue fields the fix preview needs.
	 *
	 * @param array|null $issue Issue row.
	 * @return array|null
	 */
	private function present_issue( $issue ) {
		if ( ! $issue ) {
			return null;
		}
		return array(
			'id'          => (int) $issue['id'],
			'status'      => (string) $issue['status'],
			'label'       => __( 'Automatic links with weak link text', 'searchpilot-pro' ),
			'title'       => '',
			'count'       => (int) ( $issue['data']['count'] ?? 0 ),
			'fix'         => __( 'Remove these links. The words stay in the text — only the link is removed.', 'searchpilot-pro' ),
			'manual_step' => __( 'Remove the listed links one by one with “Remove link”.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Object-level permission for a suggestion (the post that would change).
	 *
	 * @param int $id Suggestion id.
	 * @return true|\WP_Error
	 */
	private function check( $id ) {
		$s = $this->repo->get( $id );
		if ( ! $s ) {
			return $this->error( 'searchpilot_not_found', __( 'Suggestion not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! Capabilities::can_edit( $s['source_id'] ) ) {
			return $this->error( 'rest_forbidden', __( 'You cannot edit the post this link would be added to.', 'searchpilot-pro' ), 403 );
		}
		return true;
	}
}
