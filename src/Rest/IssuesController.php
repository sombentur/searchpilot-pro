<?php
/**
 * Issue endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Fixes\BulkRunner;
use SearchPilot\Fixes\FixEngine;
use SearchPilot\Fixes\Plan;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Issues\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /issues, /issues/counts, /issues/{id}
 * POST /issues/{id}/(fix|ignore|reopen), /issues/bulk (assisted planner fixes need a reviewed plan)
 * Fix engine (contract C4):
 * GET  /issues/{id}/plan                 → { plan, plan_id, issue }
 * POST /issues/{id}/apply                {plan_id} or {plan} → engine result + issue
 * GET  /issues/fixable-types             open issue counts of types with a previewable fix
 * POST /issues/plan-bulk                 {type|ids, page} → plans to review (advisory issues counted in `manual`)
 * POST /issues/apply-bulk                {items:[{issue_id,fingerprint}]} or {ids} (deterministic) → run
 * GET  /issues/runs, /issues/runs/{run}  progress
 * POST /issues/runs/{run}/cancel, /issues/runs/{run}/undo (background undo, progress in `undo`)
 * POST /issues/fix-groups/{group}/undo   undo one fix (per-row object checks)
 */
class IssuesController extends Controller {

	/**
	 * Repository.
	 *
	 * @var IssueRepository
	 */
	private $repo;

	/**
	 * Service.
	 *
	 * @var Issues
	 */
	private $issues;

	/**
	 * Registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * Fix engine.
	 *
	 * @var FixEngine
	 */
	private $engine;

	/**
	 * Bulk runner.
	 *
	 * @var BulkRunner
	 */
	private $runner;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param IssueRepository $repo     Repository.
	 * @param Issues          $issues   Service.
	 * @param IssueRegistry   $registry Registry.
	 * @param FixEngine       $engine   Fix engine.
	 * @param BulkRunner      $runner   Bulk runner.
	 * @param ActivityLog     $log      Activity log.
	 */
	public function __construct( IssueRepository $repo, Issues $issues, IssueRegistry $registry, FixEngine $engine, BulkRunner $runner, ActivityLog $log ) {
		$this->repo     = $repo;
		$this->issues   = $issues;
		$this->registry = $registry;
		$this->engine   = $engine;
		$this->runner   = $runner;
		$this->log      = $log;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$run_arg   = array(
			'run' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$group_arg = array(
			'group' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$this->route( '/issues', 'GET', array( $this, 'list_issues' ), 'view' );
		$this->route( '/issues/counts', 'GET', array( $this, 'counts' ), 'view' );
		$this->route( '/issues/fixable-types', 'GET', array( $this, 'fixable_types' ), 'edit' );
		$this->route( '/issues/plan-bulk', 'POST', array( $this, 'plan_bulk' ), 'edit' );
		$this->route( '/issues/apply-bulk', 'POST', array( $this, 'apply_bulk' ), 'edit' );
		$this->route( '/issues/runs', 'GET', array( $this, 'list_runs' ), 'edit' );
		$this->route( '/issues/runs/(?P<run>[a-z0-9]+)', 'GET', array( $this, 'get_run' ), 'edit', $run_arg );
		$this->route( '/issues/runs/(?P<run>[a-z0-9]+)/cancel', 'POST', array( $this, 'cancel_run' ), 'edit', $run_arg );
		$this->route( '/issues/runs/(?P<run>[a-z0-9]+)/undo', 'POST', array( $this, 'undo_run' ), 'edit', $run_arg );
		$this->route( '/issues/fix-groups/(?P<group>[a-z0-9]+)/undo', 'POST', array( $this, 'undo_fix_group' ), 'edit', $group_arg );
		$this->route( '/issues/(?P<id>\d+)', 'GET', array( $this, 'get_issue' ), 'view', $this->id_arg() );
		$this->route( '/issues/(?P<id>\d+)/plan', 'GET', array( $this, 'plan_issue' ), 'edit', $this->id_arg() );
		$this->route( '/issues/(?P<id>\d+)/apply', 'POST', array( $this, 'apply_issue' ), 'edit', $this->id_arg() );
		$this->route( '/issues/(?P<id>\d+)/fix', 'POST', array( $this, 'fix' ), 'edit', $this->id_arg() );
		$this->route( '/issues/(?P<id>\d+)/ignore', 'POST', array( $this, 'ignore' ), 'edit', $this->id_arg() );
		$this->route( '/issues/(?P<id>\d+)/reopen', 'POST', array( $this, 'reopen' ), 'edit', $this->id_arg() );
		$this->route( '/issues/bulk', 'POST', array( $this, 'bulk' ), 'edit' );
	}

	/**
	 * GET /issues.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_issues( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$status                  = (string) ( $request->get_param( 'status' ) ?: 'open' );
		$result                  = $this->repo->query(
			array(
				'status'           => in_array( $status, array( 'open', 'any' ), true ) ? $status : self::keys( $status ),
				'severity'         => self::keys( $request->get_param( 'severity' ) ),
				'category'         => self::keys( $request->get_param( 'category' ) ),
				'exclude_category' => self::keys( $request->get_param( 'exclude_category' ) ),
				'type'             => self::keys( $request->get_param( 'type' ) ),
				'object_type'      => self::keys( $request->get_param( 'object_type' ) ),
				'object_id'        => absint( $request->get_param( 'object_id' ) ),
				'search'           => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'          => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'page'             => $page,
				'per_page'         => $per_page,
			)
		);
		return $this->paged( $result['items'], $result['total'], $page, $per_page );
	}

	/**
	 * GET /issues/counts.
	 *
	 * @param \WP_REST_Request $request Request (category, exclude_category).
	 * @return array
	 */
	public function counts( \WP_REST_Request $request ) {
		$category = sanitize_key( (string) $request->get_param( 'category' ) );
		$exclude  = array_filter( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'exclude_category' ) ) ) );
		return $this->repo->counts( '' !== $category ? $category : null, $exclude );
	}

	/**
	 * GET /issues/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_issue( \WP_REST_Request $request ) {
		$issue = $this->repo->get( (int) $request['id'] );
		return $issue ? $issue : $this->error( 'searchpilot_not_found', __( 'Issue not found.', 'searchpilot-pro' ), 404 );
	}

	/* ------------------------------------------------------------------
	 * One issue
	 * ------------------------------------------------------------------ */

	/**
	 * GET /issues/{id}/plan — the fix preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function plan_issue( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$check = $this->check_object( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$plan = $this->engine->plan( $id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		return array(
			'plan'    => Plan::public_view( $plan ),
			'plan_id' => $this->engine->store_plan( $plan ),
			'issue'   => $this->repo->get( $id ),
		);
	}

	/**
	 * POST /issues/{id}/apply {plan_id} | {plan} [signatures].
	 *
	 * A plan sent by the browser is never trusted: the server plans again
	 * and applies its own plan when the fingerprint (or the chosen change
	 * signatures) still match.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function apply_issue( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$check = $this->check_object( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$plan_id    = preg_replace( '/[^a-f0-9]/', '', (string) $request->get_param( 'plan_id' ) );
		$signatures = array_values( array_filter( array_map( array( __CLASS__, 'hex' ), (array) $request->get_param( 'signatures' ) ) ) );
		if ( '' !== $plan_id ) {
			$plan = $this->engine->load_plan( $plan_id );
			$this->engine->forget_plan( $plan_id );
			if ( ! $plan ) {
				return $this->error( 'searchpilot_plan_expired', __( 'This preview expired. Preview the fix again.', 'searchpilot-pro' ), 409 );
			}
		} else {
			$sent = $request->get_param( 'plan' );
			if ( ! is_array( $sent ) ) {
				return $this->error( 'searchpilot_invalid', __( 'Preview the fix first.', 'searchpilot-pro' ) );
			}
			$plan = $this->engine->plan( $id );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			if ( ! hash_equals( (string) $plan['fingerprint'], self::hex( $sent['fingerprint'] ?? '' ) ) ) {
				$sent_signatures = array_values( array_filter( array_map( array( __CLASS__, 'hex' ), wp_list_pluck( array_filter( (array) ( $sent['changes'] ?? array() ), 'is_array' ), 'signature' ) ) ) );
				if ( ! $signatures ) {
					$signatures = $sent_signatures;
				}
				if ( ! $signatures ) {
					return $this->error( 'searchpilot_fix_stale', __( 'The page changed after this fix was prepared, so nothing was changed. Preview the fix again.', 'searchpilot-pro' ), 409 );
				}
			}
		}
		if ( (int) $plan['issue_id'] !== $id ) {
			return $this->error( 'searchpilot_invalid', __( 'The fix plan does not belong to this issue.', 'searchpilot-pro' ) );
		}
		if ( $signatures ) {
			$known = array_map( array( Plan::class, 'signature' ), (array) $plan['changes'] );
			if ( array_diff( $signatures, $known ) ) {
				return $this->error( 'searchpilot_fix_stale', __( 'The page changed after this fix was prepared, so nothing was changed. Preview the fix again.', 'searchpilot-pro' ), 409 );
			}
			$plan = Plan::subset( $plan, $signatures );
			if ( empty( $plan['changes'] ) ) {
				return $this->error( 'searchpilot_invalid', __( 'Choose at least one change to apply.', 'searchpilot-pro' ) );
			}
		}
		$result = $this->engine->apply( $plan, 'user' );
		if ( ! $result['applied'] && ! $result['introduced'] ) {
			return new \WP_Error( '' !== $result['code'] ? $result['code'] : 'searchpilot_fix_failed', $result['error'], array( 'status' => 'rest_forbidden' === $result['code'] ? 403 : 409 ) );
		}
		return $this->with_issue( $result, $id );
	}

	/**
	 * POST /issues/{id}/fix [plan_id|plan] — fixes directly (approval, legacy fixers,
	 * deterministic planners). Assisted planners need the reviewed plan (plan_id/plan,
	 * handled like /apply); without it the request is refused with searchpilot_review_required.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function fix( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$check = $this->check_object( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( '' !== (string) $request->get_param( 'plan_id' ) || is_array( $request->get_param( 'plan' ) ) ) {
			// A reviewed plan: same rules as /apply.
			$out = $this->apply_issue( $request );
			if ( is_array( $out ) ) {
				$out['fixed'] = ! empty( $out['applied'] );
			}
			return $out;
		}
		// Without a reviewed plan only legacy fixers and deterministic planners run.
		$result = $this->issues->fix_result( $id, 'user' );
		if ( ! $result['applied'] && ! $result['introduced'] ) {
			return new \WP_Error( '' !== $result['code'] ? $result['code'] : 'searchpilot_fix_failed', '' !== $result['error'] ? $result['error'] : __( 'The fix could not be applied.', 'searchpilot-pro' ), array( 'status' => 'rest_forbidden' === $result['code'] ? 403 : 409 ) );
		}
		$out          = $this->with_issue( $result, $id );
		$out['fixed'] = $result['applied'];
		return $out;
	}

	/**
	 * POST /issues/{id}/ignore.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function ignore( \WP_REST_Request $request ) {
		$check = $this->check_object( (int) $request['id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$this->issues->ignore( (int) $request['id'] );
		return array( 'issue' => $this->repo->get( (int) $request['id'] ) );
	}

	/**
	 * POST /issues/{id}/reopen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function reopen( \WP_REST_Request $request ) {
		$check = $this->check_object( (int) $request['id'] );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$this->issues->reopen( (int) $request['id'] );
		return array( 'issue' => $this->repo->get( (int) $request['id'] ) );
	}

	/**
	 * POST /issues/bulk {ids: int[], action: fix|ignore|reopen}.
	 *
	 * Fixes run synchronously within a time budget; ids not reached are
	 * returned in `remaining` (use apply-bulk for large fix batches).
	 * Assisted planner fixes are refused per id (they need a review: apply-bulk
	 * with fingerprints, or the preview).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 200 );
		if ( ! in_array( $action, array( 'fix', 'ignore', 'reopen' ), true ) || empty( $ids ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose issues and an action.', 'searchpilot-pro' ) );
		}
		$budget    = new TimeBudget( 25 );
		$done      = 0;
		$errors    = array();
		$remaining = array();
		$group     = 'fix' === $action ? ActivityLog::new_group_id() : '';
		foreach ( $ids as $i => $id ) {
			if ( 'fix' === $action && $budget->exhausted( 5.0 ) ) {
				$remaining = array_slice( $ids, $i );
				break;
			}
			$check = $this->check_object( $id );
			if ( is_wp_error( $check ) ) {
				$errors[ $id ] = $check->get_error_message();
				continue;
			}
			if ( 'fix' === $action ) {
				$r = $this->issues->fix_result( $id, 'user', $group );
				if ( ! $r['applied'] ) {
					$errors[ $id ] = $r['error'];
					continue;
				}
			} elseif ( 'ignore' === $action ) {
				$this->issues->ignore( $id );
			} else {
				$this->issues->reopen( $id );
			}
			++$done;
		}
		return array(
			'done'      => $done,
			'errors'    => $errors,
			'remaining' => $remaining,
			'group_id'  => $done && $group ? $group : '',
		);
	}

	/* ------------------------------------------------------------------
	 * Bulk review
	 * ------------------------------------------------------------------ */

	/**
	 * GET /issues/fixable-types {category, exclude_category} — types with a
	 * previewable fix that have open issues.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function fixable_types( \WP_REST_Request $request ) {
		$types = array();
		foreach ( $this->registry->all() as $type => $def ) {
			if ( IssueRegistry::has_planner( $def ) && 'advisory' !== ( is_string( $def['mode'] ) ? $def['mode'] : '' ) ) {
				$types[ $type ] = $def;
			}
		}
		$counts = $types ? $this->repo->type_counts(
			array_keys( $types ),
			array(
				'category'         => self::keys( $request->get_param( 'category' ) ),
				'exclude_category' => self::keys( $request->get_param( 'exclude_category' ) ),
			)
		) : array();
		$items  = array();
		foreach ( $counts as $type => $count ) {
			$def     = $types[ $type ];
			$items[] = array(
				'type'     => $type,
				'label'    => IssueRepository::plain( (string) $def['label'] ),
				'category' => (string) $def['category'],
				'severity' => (string) $def['severity'],
				'mode'     => $this->registry->mode_for( $def ),
				'count'    => (int) $count,
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return array( 'items' => $items );
	}

	/**
	 * POST /issues/plan-bulk {type, page} | {ids} — plans to review.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function plan_bulk( \WP_REST_Request $request ) {
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$ids      = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 100 );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 50, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		if ( '' !== $type ) {
			$def = $this->registry->get( $type );
			if ( ! $def || ! IssueRegistry::has_planner( $def ) ) {
				return $this->error( 'searchpilot_not_fixable', __( 'This kind of issue has no automatic fix to review.', 'searchpilot-pro' ) );
			}
			$result = $this->repo->query(
				array(
					'status'   => 'open',
					'type'     => array( $type ),
					'page'     => $page,
					'per_page' => $per_page,
					'orderby'  => 'severity',
				)
			);
			$issues = $result['items'];
			$total  = (int) $result['total'];
		} elseif ( $ids ) {
			$issues   = array_values( array_filter( array_map( array( $this->repo, 'get' ), $ids ) ) );
			$total    = count( $issues );
			$per_page = max( 1, $total );
			$page     = 1;
		} else {
			return $this->error( 'searchpilot_invalid', __( 'Choose a kind of issue to review.', 'searchpilot-pro' ) );
		}
		$budget  = new TimeBudget( 20 );
		$items   = array();
		$partial = false;
		$manual  = 0;
		foreach ( $issues as $issue ) {
			if ( $budget->exhausted( 3.0 ) ) {
				$partial = true;
				break;
			}
			$def = $this->registry->get( $issue['type'] );
			if ( ! $def || ! IssueRegistry::has_planner( $def ) || 'advisory' === $this->registry->mode_for( $def, $issue ) ) {
				++$manual;
				continue;
			}
			$item  = array(
				'issue'       => self::summary( $issue ),
				'plan'        => null,
				'fingerprint' => '',
				'error'       => '',
				'code'        => '',
			);
			$check = $this->check_object( (int) $issue['id'] );
			if ( is_wp_error( $check ) ) {
				$item['error'] = $check->get_error_message();
				$item['code']  = (string) $check->get_error_code();
				$items[]       = $item;
				continue;
			}
			$plan = $this->engine->plan_for( $issue );
			if ( is_wp_error( $plan ) ) {
				$item['error'] = $plan->get_error_message();
				$item['code']  = (string) $plan->get_error_code();
			} else {
				$item['plan']        = Plan::public_view( $plan );
				$item['fingerprint'] = (string) $plan['fingerprint'];
			}
			$items[] = $item;
		}
		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
			'partial'  => $partial,
			'manual'   => $manual,
			'type'     => $type,
		);
	}

	/**
	 * POST /issues/apply-bulk {items:[{issue_id,fingerprint}]} | {ids} — queues a background run.
	 * Items without a fingerprint (not reviewed) are accepted only for deterministic fixes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function apply_bulk( \WP_REST_Request $request ) {
		$raw = array();
		foreach ( (array) $request->get_param( 'items' ) as $item ) {
			if ( is_array( $item ) ) {
				$raw[] = array(
					'issue_id'    => absint( $item['issue_id'] ?? 0 ),
					'fingerprint' => self::hex( $item['fingerprint'] ?? '' ),
				);
			}
		}
		foreach ( (array) $request->get_param( 'ids' ) as $id ) {
			$raw[] = array(
				'issue_id'    => absint( $id ),
				'fingerprint' => '',
			);
		}
		$raw = array_slice( $raw, 0, BulkRunner::MAX_ITEMS );
		if ( ! $raw ) {
			return $this->error( 'searchpilot_invalid', __( 'Choose at least one fix to apply.', 'searchpilot-pro' ) );
		}
		$valid    = array();
		$rejected = array();
		$types    = array();
		$budget   = new TimeBudget( 20 );
		foreach ( $raw as $item ) {
			$issue = $item['issue_id'] ? $this->repo->get( $item['issue_id'] ) : null;
			if ( ! $issue || ! in_array( $issue['status'], IssueRepository::OPEN_STATUSES, true ) ) {
				$rejected[ $item['issue_id'] ] = __( 'Already fixed, resolved or removed.', 'searchpilot-pro' );
				continue;
			}
			$def = $this->registry->get( $issue['type'] );
			if ( ! $def || ! IssueRegistry::has_planner( $def ) ) {
				$rejected[ $issue['id'] ] = __( 'This issue has no automatic fix.', 'searchpilot-pro' );
				continue;
			}
			$mode = $this->registry->mode_for( $def, $issue );
			if ( 'advisory' === $mode || ( '' === $item['fingerprint'] && 'deterministic' !== $mode ) ) {
				$rejected[ $issue['id'] ] = __( 'Review this fix before applying it.', 'searchpilot-pro' );
				continue;
			}
			$check = $this->check_object( (int) $issue['id'] );
			if ( is_wp_error( $check ) ) {
				$rejected[ $issue['id'] ] = $check->get_error_message();
				continue;
			}
			$reviewed = array();
			$keys     = array();
			if ( '' !== $item['fingerprint'] ) {
				// Record exactly what was reviewed (from the server's own plan, never the browser's):
				// the background run may only apply these changes.
				if ( $budget->exhausted( 3.0 ) ) {
					$rejected[ $issue['id'] ] = __( 'Too many fixes at once — apply the rest in a second batch.', 'searchpilot-pro' );
					continue;
				}
				$plan = $this->engine->plan_for( $issue );
				if ( is_wp_error( $plan ) || ! hash_equals( (string) $plan['fingerprint'], $item['fingerprint'] ) ) {
					$rejected[ $issue['id'] ] = is_wp_error( $plan ) ? $plan->get_error_message() : __( 'The page changed after you reviewed this fix. Review it again.', 'searchpilot-pro' );
					continue;
				}
				foreach ( $plan['changes'] as $change ) {
					$reviewed[] = Plan::signature( $change );
					$keys[]     = Plan::change_key( $change );
				}
			}
			$types[ $issue['type'] ] = IssueRepository::plain( (string) $def['label'] );
			$valid[]                 = array(
				'issue_id'    => (int) $issue['id'],
				'fingerprint' => $item['fingerprint'],
				'reviewed'    => $reviewed,
				'keys'        => $keys,
				'title'       => '' !== (string) $issue['title'] ? (string) $issue['title'] : rawurldecode( (string) $issue['url'] ),
			);
		}
		if ( ! $valid ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'None of the chosen fixes can be applied.', 'searchpilot-pro' ), array( 'status' => 400, 'rejected' => $rejected ) );
		}
		$run = $this->runner->start(
			$valid,
			array(
				'user_id' => get_current_user_id(),
				'source'  => 'user',
				'label'   => 1 === count( $types ) ? (string) reset( $types ) : __( 'SEO fixes', 'searchpilot-pro' ),
				'type'    => 1 === count( $types ) ? (string) key( $types ) : '',
			)
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$out             = $this->runner->present( $run );
		$out['rejected'] = $rejected;
		return $out;
	}

	/**
	 * GET /issues/runs {active} — recent runs (own runs unless administrator).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_runs( \WP_REST_Request $request ) {
		$active = (bool) $request->get_param( 'active' );
		$items  = array();
		foreach ( $this->runner->runs( Capabilities::can_manage() ? 0 : get_current_user_id() ) as $run ) {
			$view = $this->runner->present( $run, false );
			if ( $active && ! $view['active'] ) {
				continue;
			}
			if ( $view['active'] ) {
				$this->runner->nudge( $run );
			}
			$this->runner->nudge_undo( $run );
			$items[] = $view;
		}
		return array( 'items' => $items );
	}

	/**
	 * GET /issues/runs/{run} — progress.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function get_run( \WP_REST_Request $request ) {
		$run = $this->load_run( (string) $request['run'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$this->runner->nudge( $run );
		$this->runner->nudge_undo( $run );
		return $this->runner->present( $run );
	}

	/**
	 * POST /issues/runs/{run}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function cancel_run( \WP_REST_Request $request ) {
		$run = $this->load_run( (string) $request['run'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$run = $this->runner->cancel( $run['id'] );
		return $run ? $this->runner->present( $run ) : $this->error( 'searchpilot_not_found', __( 'This run no longer exists.', 'searchpilot-pro' ), 404 );
	}

	/**
	 * POST /issues/runs/{run}/undo — queues the undo of every change of a
	 * finished run (background; progress in the run's `undo`).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function undo_run( \WP_REST_Request $request ) {
		$run = $this->load_run( (string) $request['run'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		// Large runs take minutes to undo: done in the background, with progress on the run.
		$run = $this->runner->start_undo( $run['id'], get_current_user_id() );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$this->runner->nudge_undo( $run );
		return $this->runner->present( $run, false );
	}

	/**
	 * POST /issues/fix-groups/{group}/undo — undoes one fix (or bulk action).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function undo_fix_group( \WP_REST_Request $request ) {
		return $this->undo_group( sanitize_key( (string) $request['group'] ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Undoes the applied entries of an activity group the current user may
	 * undo (post/attachment rows: can edit that post; others: administrators),
	 * then queues re-scans of the affected posts.
	 *
	 * @param string $group Group id.
	 * @param int[]  $posts Extra posts to re-scan.
	 * @return array{undone:int,failed:int,errors:string[]}
	 */
	private function undo_group( $group, array $posts = array() ) {
		$out = array(
			'undone' => 0,
			'failed' => 0,
			'errors' => array(),
		);
		if ( '' === $group ) {
			return $out;
		}
		$rows = array();
		for ( $page = 1; $page <= 10; $page++ ) {
			$batch = $this->log->query(
				array(
					'group_id'   => $group,
					'status'     => 'applied',
					'reversible' => 1,
					'per_page'   => 200,
					'page'       => $page,
				)
			);
			$rows  = array_merge( $rows, $batch['items'] );
			if ( count( $batch['items'] ) < 200 ) {
				break;
			}
		}
		foreach ( $rows as $row ) {
			$is_post = in_array( $row['object_type'], array( 'post', 'attachment' ), true ) && $row['object_id'];
			$allowed = $is_post ? Capabilities::can_edit( (int) $row['object_id'] ) : Capabilities::can_manage();
			if ( ! $allowed ) {
				++$out['failed'];
				$out['errors'][] = __( 'You are not allowed to undo one of these changes.', 'searchpilot-pro' );
				continue;
			}
			$result = $this->log->undo( (int) $row['id'] );
			if ( is_wp_error( $result ) ) {
				++$out['failed'];
				$out['errors'][] = $result->get_error_message();
				continue;
			}
			++$out['undone'];
			if ( 'post' === $row['object_type'] ) {
				$posts[] = (int) $row['object_id'];
			}
		}
		$out['errors'] = array_values( array_unique( $out['errors'] ) );
		$posts         = array_values( array_unique( array_filter( array_map( 'intval', $posts ) ) ) );
		if ( $posts && count( $posts ) <= 3 ) {
			// One fix: re-check now so the issue reopens before the screen reloads.
			foreach ( $posts as $post_id ) {
				$this->engine->rescan( $post_id );
			}
		} else {
			$this->engine->queue_rescan( $posts );
		}
		return $out;
	}

	/**
	 * A run the current user may see.
	 *
	 * @param string $run_id Run id.
	 * @return array|\WP_Error
	 */
	private function load_run( $run_id ) {
		$run = $this->runner->get( BulkRunner::clean_id( $run_id ) );
		if ( ! $run ) {
			return $this->error( 'searchpilot_not_found', __( 'This run no longer exists.', 'searchpilot-pro' ), 404 );
		}
		if ( (int) $run['user_id'] !== get_current_user_id() && ! Capabilities::can_manage() ) {
			return $this->error( 'rest_forbidden', __( 'You are not allowed to see this run.', 'searchpilot-pro' ), 403 );
		}
		return $run;
	}

	/**
	 * Engine result plus the fresh issue and readable labels.
	 *
	 * @param array $result Engine result.
	 * @param int   $id     Issue id.
	 * @return array
	 */
	private function with_issue( array $result, $id ) {
		$result['introduced_labels'] = $this->engine->type_labels( (array) $result['introduced'] );
		$result['issue']             = $this->repo->get( $id );
		unset( $result['activity'] );
		return $result;
	}

	/**
	 * Issue summary for review lists.
	 *
	 * @param array $issue Issue.
	 * @return array
	 */
	private static function summary( array $issue ) {
		return array(
			'id'          => (int) $issue['id'],
			'type'        => (string) $issue['type'],
			'label'       => (string) $issue['label'],
			'title'       => (string) $issue['title'],
			'url'         => (string) $issue['url'],
			'status'      => (string) $issue['status'],
			'severity'    => (string) $issue['severity'],
			'object_type' => (string) $issue['object_type'],
			'object_id'   => (int) $issue['object_id'],
			'edit_link'   => (string) $issue['edit_link'],
			'mode'        => (string) ( $issue['mode'] ?? '' ),
		);
	}

	/**
	 * Comma list / array → sanitised keys.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function keys( $value ) {
		$value = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}

	/**
	 * Lower-case hex string (signatures, fingerprints) or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function hex( $value ) {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		return preg_match( '/^[a-f0-9]{40}$/', $value ) ? $value : '';
	}

	/**
	 * Object-level permission for an issue.
	 *
	 * @param int $id Issue id.
	 * @return true|\WP_Error
	 */
	private function check_object( $id ) {
		$issue = $this->repo->get( $id );
		if ( ! $issue ) {
			return $this->error( 'searchpilot_not_found', __( 'Issue not found.', 'searchpilot-pro' ), 404 );
		}
		if ( in_array( $issue['object_type'], array( 'post', 'attachment' ), true ) && $issue['object_id'] ) {
			return Capabilities::can_edit( $issue['object_id'] ) ? true : $this->error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), 403 );
		}
		// Site-wide issues are for administrators.
		return Capabilities::can_manage() ? true : $this->error( 'rest_forbidden', __( 'Only administrators can change site-wide issues.', 'searchpilot-pro' ), 403 );
	}
}
