<?php
/**
 * Plan → preview → apply → verify.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes;

use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Logger;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * Runs fixes declared on issue types (contract C4).
 *
 * plan( $issue_id ) asks the type's planner for a Plan, then stamps every
 * change with the hash of the value stored right now (`expect`), fills
 * missing diff fragments and checks that SearchPilot may write each field.
 *
 * apply( $plan ) re-checks ownership and `expect`, re-scans the touched
 * posts for a baseline, writes every change through Changes / MetaStore
 * with one activity group, purges caches, re-scans the posts again and:
 *   - when an issue type is now open on a touched post that was not open
 *     before (e.g. duplicate_title), undoes this apply's writes and
 *     reports the types in `introduced`;
 *   - otherwise marks the issue fixed only when it is no longer open
 *     after the re-scan (`verified`).
 *
 * Legacy `fixer` callables run through apply_legacy(): their writes are
 * captured (and grouped), logged, and verified/undone the same way when
 * the issue sits on a post.
 */
class FixEngine {

	const PLAN_TTL = 7200;

	const PENDING_OPTION = 'searchpilot_fix_pending';

	const PENDING_RETRY = 900;

	const PENDING_ATTEMPTS = 3;

	const PENDING_MAX = 500;

	/**
	 * Built-in "only measurable after" relations: type => types whose fix makes it measurable.
	 */
	const MEASURABLE_AFTER = array(
		'description_length' => array( 'missing_meta_description' ),
	);

	/**
	 * Registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $repo;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Activity ids captured while a legacy fixer runs.
	 *
	 * @var int[]|null
	 */
	private $captured = null;

	/**
	 * Group assigned to captured entries ('' keeps theirs).
	 *
	 * @var string
	 */
	private $capture_group = '';

	/**
	 * Groups of the captured entries.
	 *
	 * @var string[]
	 */
	private $capture_groups = array();

	/**
	 * True while the engine undoes its own changes (not a person declining a fix).
	 *
	 * @var bool
	 */
	private $self_undo = false;

	/**
	 * Constructor.
	 *
	 * @param IssueRegistry    $registry  Registry.
	 * @param IssueRepository  $repo      Issues.
	 * @param ActivityLog      $log       Activity log.
	 * @param Changes          $changes   Changes.
	 * @param MetaStore        $meta      Meta store.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param CachePurger      $purger    Cache purger.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( IssueRegistry $registry, IssueRepository $repo, ActivityLog $log, Changes $changes, MetaStore $meta, ConflictDetector $conflicts, CachePurger $purger, Logger $logger ) {
		$this->registry  = $registry;
		$this->repo      = $repo;
		$this->log       = $log;
		$this->changes   = $changes;
		$this->meta      = $meta;
		$this->conflicts = $conflicts;
		$this->purger    = $purger;
		$this->logger    = $logger;
	}

	/* ------------------------------------------------------------------
	 * Planning
	 * ------------------------------------------------------------------ */

	/**
	 * Plan for an issue.
	 *
	 * @param int $issue_id Issue id.
	 * @return array|\WP_Error
	 */
	public function plan( $issue_id ) {
		$issue = $this->repo->get( (int) $issue_id );
		if ( ! $issue ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'Issue not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		return $this->plan_for( $issue );
	}

	/**
	 * Plan for a loaded issue row.
	 *
	 * @param array $issue Issue (hydrated).
	 * @return array|\WP_Error
	 */
	public function plan_for( array $issue ) {
		if ( ! in_array( $issue['status'] ?? '', IssueRepository::OPEN_STATUSES, true ) ) {
			return new \WP_Error( 'searchpilot_fix_not_open', __( 'This issue is no longer open, so there is nothing to fix.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$def = $this->registry->get( (string) $issue['type'] );
		if ( ! $def ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'This issue type is no longer registered.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$mode = $this->registry->mode_for( $def, $issue );
		if ( 'advisory' === $mode || empty( $def['planner'] ) || ! is_callable( $def['planner'] ) ) {
			return $this->not_plannable( $def, $issue );
		}
		try {
			$raw = call_user_func( $def['planner'], $issue );
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'issues', sprintf( 'Planner for %s failed: %s', $issue['type'], $e->getMessage() ), array( 'issue' => (int) $issue['id'] ) );
			return new \WP_Error( 'searchpilot_plan_failed', __( 'SearchPilot could not prepare this fix. Details were written to SearchPilot → System Logs.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'searchpilot_plan_failed', __( 'SearchPilot could not prepare this fix.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		// The plan always belongs to this issue.
		$base = Plan::create( $issue, '' );
		$raw  = array_merge( $base, $raw );

		$raw['issue_id'] = (int) $issue['id'];
		$raw['type']     = (string) $issue['type'];
		$plan            = Plan::normalize( $raw );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$changes = array();
		foreach ( $plan['changes'] as $change ) {
			$check = $this->check_target( $change, $plan );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$current = $this->current_value( $change );
			// A change that writes what is already stored is not a change.
			if ( Plan::hash( $current ) === Plan::hash( $change['value'] ) || ( 'featured_image' === $change['kind'] && (int) $current === (int) $change['value'] ) ) {
				continue;
			}
			$change['expect'] = Plan::hash( $current );
			if ( 'post_content' === $change['kind'] && '' === $change['before'] && '' === $change['after'] ) {
				list( $change['before'], $change['after'] ) = ContentEditor::fragments( (string) $current, (string) $change['value'] );
			} elseif ( '' === $change['before'] && is_scalar( $current ) ) {
				$change['before'] = (string) $current;
			}
			if ( '' === $change['label'] ) {
				$change['label'] = $this->default_label( $change );
			}
			$changes[] = $change;
		}
		if ( ! $changes ) {
			return new \WP_Error( 'searchpilot_plan_empty', __( 'Nothing needs to change — the page may already be fixed. Re-check it to update the issue.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		$plan['changes']     = $changes;
		$plan['mode']        = $mode;
		$plan['label']       = (string) ( $issue['label'] ?? $def['label'] );
		$plan['fingerprint'] = Plan::fingerprint( $plan );
		if ( '' === $plan['summary'] ) {
			$plan['summary'] = $plan['label'];
		}
		return $plan;
	}

	/**
	 * Current stored value a change would replace (what `expect` hashes).
	 *
	 * @param array $change Change.
	 * @return mixed
	 */
	public function current_value( array $change ) {
		$id    = (int) ( $change['object_id'] ?? 0 );
		$field = (string) ( $change['field'] ?? '' );
		switch ( (string) ( $change['kind'] ?? '' ) ) {
			case 'seo_field':
				return $this->meta->get_post_field( $id, $field );
			case 'post_content':
				$post = get_post( $id );
				return $post ? (string) $post->post_content : '';
			case 'post_meta':
				return get_post_meta( $id, $field, true );
			case 'attachment_alt':
				return get_post_meta( $id, '_wp_attachment_image_alt', true );
			case 'featured_image':
				return get_post_meta( $id, '_thumbnail_id', true );
			case 'option':
				return get_option( $field );
		}
		return null;
	}

	/**
	 * Who owns an SEO field and whether SearchPilot can write it
	 * (MetaStore::field_owner() when available).
	 *
	 * @param string $field Field.
	 * @return array{owner:string,owner_name:string,writable:bool,reason:string}
	 */
	public function field_owner( $field ) {
		if ( method_exists( $this->meta, 'field_owner' ) ) {
			$owner = (array) $this->meta->field_owner( $field );
			return array(
				'owner'      => (string) ( $owner['owner'] ?? '' ),
				'owner_name' => (string) ( $owner['owner_name'] ?? '' ),
				'writable'   => ! empty( $owner['writable'] ),
				'reason'     => (string) ( $owner['reason'] ?? '' ),
			);
		}
		$feature = MetaStore::FIELD_FEATURE[ $field ] ?? '';
		$owner   = '' !== $feature ? $this->conflicts->owner( $feature ) : 'searchpilot';
		$store   = $this->meta->store_for( $field );
		$plugins = $this->conflicts->detect();
		$name    = 'searchpilot' === $owner ? 'SearchPilot' : (string) ( $plugins[ $owner ]['name'] ?? $owner );
		if ( 'off' === $owner ) {
			return array(
				'owner'      => 'off',
				'owner_name' => __( 'nobody (output switched off)', 'searchpilot-pro' ),
				'writable'   => false,
				'reason'     => __( 'Output of this field is switched off in SearchPilot → Settings → Feature ownership, so changing it would not change the page.', 'searchpilot-pro' ),
			);
		}
		if ( 'searchpilot' !== $store ) {
			$writable = 'aioseo' === $store || '' !== MetaStore::post_key( $store, $field );
			return array(
				'owner'      => $store,
				'owner_name' => $name,
				'writable'   => $writable,
				/* translators: %s: plugin name. */
				'reason'     => $writable ? '' : sprintf( __( '%s has no per-post setting for this field.', 'searchpilot-pro' ), $name ),
			);
		}
		$writable = 'searchpilot' === $owner || '' === $feature;
		return array(
			'owner'      => $owner,
			'owner_name' => $name,
			'writable'   => $writable,
			/* translators: %s: plugin name. */
			'reason'     => $writable ? '' : sprintf( __( '%s outputs this field and SearchPilot cannot write into its settings.', 'searchpilot-pro' ), $name ),
		);
	}

	/* ------------------------------------------------------------------
	 * Applying
	 * ------------------------------------------------------------------ */

	/**
	 * Applies a plan.
	 *
	 * @param array  $plan     Plan (as returned by plan()).
	 * @param string $source   Activity source (user|assisted|autopilot).
	 * @param string $group_id Activity group shared by a batch ('' = new group).
	 * @param array  $opts     {
	 *     @type bool $unattended      Nobody is watching (automation, background runs): posts with an
	 *                                 active edit lock are skipped. Default: source is not 'user'.
	 *     @type bool $undo_unverified Undo the fix when the re-scan still finds the problem
	 *                                 (unattended deterministic fixes). Default false.
	 * }
	 * @return array{applied:bool,group_id:string,verified:bool|null,introduced:string[],error:string,code:string,issue_status:string,activity:int[]}
	 *         `verified` is null when a re-scan could not complete (code searchpilot_fix_pending): the change
	 *         is kept, the issue is not marked fixed and a background re-scan is queued.
	 */
	public function apply( array $plan, $source = 'user', $group_id = '', array $opts = array() ) {
		$opts = array_merge(
			array(
				'unattended'      => 'user' !== $source,
				'undo_unverified' => false,
			),
			$opts
		);
		$plan = Plan::normalize( $plan );
		if ( is_wp_error( $plan ) ) {
			return $this->failure( $plan );
		}
		$issue = null;
		if ( $plan['issue_id'] ) {
			$issue = $this->repo->get( $plan['issue_id'] );
			if ( ! $issue ) {
				return $this->failure( new \WP_Error( 'searchpilot_not_found', __( 'Issue not found.', 'searchpilot-pro' ) ) );
			}
			if ( $issue['type'] !== $plan['type'] ) {
				return $this->failure( new \WP_Error( 'searchpilot_plan_invalid', __( 'The fix plan does not belong to this issue.', 'searchpilot-pro' ) ) );
			}
			if ( ! in_array( $issue['status'], IssueRepository::OPEN_STATUSES, true ) ) {
				return $this->failure( new \WP_Error( 'searchpilot_fix_not_open', __( 'This issue is no longer open, so nothing was changed.', 'searchpilot-pro' ) ), $issue['status'] );
			}
		}
		// Pre-flight: nothing is written unless every change can be applied.
		foreach ( $plan['changes'] as $change ) {
			$check = $this->check_target( $change, $plan );
			if ( is_wp_error( $check ) ) {
				return $this->failure( $check );
			}
			if ( '' === $change['expect'] ) {
				return $this->failure( new \WP_Error( 'searchpilot_plan_invalid', __( 'The fix plan is incomplete. Preview the fix again.', 'searchpilot-pro' ) ) );
			}
			if ( ! hash_equals( $change['expect'], Plan::hash( $this->current_value( $change ) ) ) ) {
				return $this->failure( new \WP_Error( 'searchpilot_fix_stale', __( 'The page changed after this fix was prepared, so nothing was changed. Preview the fix again.', 'searchpilot-pro' ), array( 'status' => 409 ) ) );
			}
			if ( $opts['unattended'] ) {
				$locked = $this->locked( $change );
				if ( is_wp_error( $locked ) ) {
					return $this->failure( $locked );
				}
			}
		}

		$posts = $this->rescan_targets( $plan );
		/**
		 * Filters whether touched posts are re-scanned before a fix for an
		 * accurate baseline (stale issue rows never cause a false undo).
		 *
		 * @param bool  $prescan Pre-scan.
		 * @param array $plan    Plan.
		 */
		$prescan = $posts && (bool) apply_filters( 'searchpilot_fix_prescan', true, $plan );
		if ( $opts['unattended'] && $posts && $this->checks_paused() ) {
			// Nobody is watching and nothing could verify (or undo) the change: try again later.
			return $this->failure( self::deferred() );
		}
		$unchecked = array();
		$before    = $this->open_types_of( $posts, $prescan, $unchecked );
		if ( $opts['unattended'] && $unchecked ) {
			return $this->failure( self::deferred() );
		}
		if ( $prescan && $issue ) {
			$fresh = $this->repo->get( $issue['id'] );
			if ( ! $fresh || ! in_array( $fresh['status'], IssueRepository::OPEN_STATUSES, true ) ) {
				return $this->failure( new \WP_Error( 'searchpilot_fix_gone', __( 'A fresh check shows this problem is already gone, so nothing was changed.', 'searchpilot-pro' ) ), $fresh ? $fresh['status'] : '' );
			}
			// The plan may have been made from data the fresh scan replaced: plan again and
			// apply only if every planned change is still exactly what the planner proposes.
			$again = $this->plan_for( $fresh );
			if ( is_wp_error( $again ) || ! self::is_subset( $plan, $again ) ) {
				return $this->failure(
					new \WP_Error(
						'searchpilot_fix_stale',
						is_wp_error( $again ) && 'searchpilot_plan_empty' === $again->get_error_code()
							? __( 'A fresh check shows nothing needs to change any more, so nothing was changed.', 'searchpilot-pro' )
							: __( 'A fresh check changed what this fix would do, so nothing was changed. Preview the fix again.', 'searchpilot-pro' ),
						array( 'status' => 409 )
					)
				);
			}
		}

		$group_id = '' !== (string) $group_id ? substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $group_id ) ), 0, 40 ) : ActivityLog::new_group_id();
		$ids      = array();
		foreach ( $plan['changes'] as $change ) {
			// The pre-scan took seconds: re-read the value from the database (not the request
			// cache) right before writing, so an editor's save made meanwhile is never overwritten.
			$refused = null;
			if ( ! hash_equals( $change['expect'], Plan::hash( $this->fresh_value( $change ) ) ) ) {
				$refused = new \WP_Error( 'searchpilot_fix_stale', __( 'The page was edited while the fix was being applied, so nothing was changed. Preview the fix again.', 'searchpilot-pro' ), array( 'status' => 409 ) );
			} elseif ( $opts['unattended'] ) {
				$locked  = $this->locked( $change );
				$refused = is_wp_error( $locked ) ? $locked : null;
			}
			if ( $refused ) {
				$this->undo_ids( $ids );
				return $this->failure( $refused );
			}
			$written = $this->write( $change, $plan, $source, $group_id );
			if ( is_wp_error( $written ) ) {
				$this->undo_ids( $ids );
				$this->logger->warning( 'issues', sprintf( 'Fix for %s failed while writing: %s', $plan['type'], $written->get_error_message() ), array( 'issue' => $plan['issue_id'] ) );
				return $this->failure( $written );
			}
			if ( (int) $written > 0 ) {
				$ids[] = (int) $written;
			}
		}
		foreach ( $posts as $post_id ) {
			$this->purger->purge_post( $post_id );
		}
		/**
		 * Fires after a plan was written and before it is verified, so modules can
		 * re-evaluate issues the post re-scans do not cover (e.g. site-level issues).
		 *
		 * @param array  $plan     Plan.
		 * @param int[]  $ids      Activity ids written.
		 * @param string $group_id Activity group.
		 */
		do_action( 'searchpilot_fix_applied', $plan, $ids, $group_id );

		$result = $this->success( $group_id, $ids );
		$after  = array();
		foreach ( $posts as $post_id ) {
			$scan = $this->rescan( $post_id );
			if ( ! $scan['checked'] ) {
				$unchecked[] = $scan['error'];
			}
			$after[ $post_id ] = $scan['types'];
		}
		// Compared over whatever the scans could evaluate (types of stages that did not run
		// keep their stored state, so they never look "new").
		$introduced = self::introduced( $before, $after, $this->not_introduced_by( $plan['type'], self::writes_other_content( $plan ) ) );
		if ( $unchecked && ! $introduced ) {
			// A re-scan did not complete (self-checks paused, time budget…): keep the change,
			// neither mark the issue fixed nor undo it, and let a background re-scan decide.
			return $this->pending( $result, $plan, $issue, $posts, $unchecked, $source, $before );
		}

		if ( $introduced ) {
			$undo = $this->undo_ids( $ids );
			foreach ( $posts as $post_id ) {
				$this->purger->purge_post( $post_id );
				$this->rescan( $post_id );
			}
			$labels = $this->type_labels( $introduced );
			$this->logger->warning(
				'issues',
				sprintf( 'Fix for %s was undone automatically: it introduced %s.', $plan['type'], implode( ', ', $introduced ) ),
				array( 'issue' => $plan['issue_id'] )
			);
			$result['applied']    = false;
			$result['introduced'] = $introduced;
			$result['code']       = 'searchpilot_fix_introduced';
			$result['error']      = sprintf(
				/* translators: %s: list of issue labels. */
				__( 'The fix was undone automatically because it caused a new problem: %s.', 'searchpilot-pro' ),
				implode( ', ', $labels )
			) . ( $undo['failed'] ? ' ' . __( 'Some changes could not be undone — check Automation → Activity.', 'searchpilot-pro' ) : '' );
			$result['issue_status'] = $issue ? (string) ( $this->repo->get( $issue['id'] )['status'] ?? '' ) : '';
			return $result;
		}

		$result['verified'] = $this->verified( $plan, $issue, $after );
		if ( $issue && ! $result['verified'] && $opts['undo_unverified'] ) {
			// Unattended: an edit that did not solve the problem is not kept.
			$this->undo_ids( $ids );
			foreach ( $posts as $post_id ) {
				$this->purger->purge_post( $post_id );
				$this->rescan( $post_id );
			}
			$result['applied']      = false;
			$result['code']         = 'searchpilot_fix_unverified_undone';
			$result['error']        = __( 'Undone automatically: a fresh check still found the problem, so the change was not kept.', 'searchpilot-pro' );
			$result['issue_status'] = (string) ( $this->repo->get( $issue['id'] )['status'] ?? '' );
			return $result;
		}
		if ( $issue && $ids ) {
			$this->repo->remember_fix( (int) $issue['id'], Plan::fingerprint( $plan ), $ids );
		}
		if ( $issue ) {
			if ( $result['verified'] ) {
				$this->mark_fixed( $issue, $source );
			} else {
				$result['code']  = 'searchpilot_fix_unverified';
				$result['error'] = __( 'The fix was applied, but a fresh check still finds the problem. Review the page manually.', 'searchpilot-pro' );
			}
			$result['issue_status'] = (string) ( $this->repo->get( $issue['id'] )['status'] ?? '' );
		}
		$this->logger->info(
			'issues',
			sprintf( 'Applied fix for %s (%s)%s.', $plan['type'], $source, $result['verified'] ? ', verified' : ', not verified' ),
			array(
				'issue'   => $plan['issue_id'],
				'group'   => $group_id,
				'changes' => count( $ids ),
			)
		);
		return $result;
	}

	/**
	 * Plans and applies a fix for an issue (planner), or runs its legacy fixer.
	 *
	 * @param array  $issue    Issue (hydrated).
	 * @param string $source   Source.
	 * @param string $group_id Group.
	 * @param array  $opts     apply() options (unattended, undo_unverified, skip_declined).
	 * @return array Same shape as apply(), plus `fingerprint` when planned.
	 */
	public function fix_issue( array $issue, $source = 'user', $group_id = '', array $opts = array() ) {
		$def = $this->registry->get( (string) $issue['type'] );
		if ( $def && ! empty( $def['planner'] ) && is_callable( $def['planner'] ) && 'advisory' !== $this->registry->mode_for( $def, $issue ) ) {
			$plan = $this->plan_for( $issue );
			if ( is_wp_error( $plan ) ) {
				return $this->failure( $plan );
			}
			if ( ! empty( $opts['skip_declined'] ) && $this->declined( $issue, (string) $plan['fingerprint'] ) ) {
				return $this->failure( new \WP_Error( 'searchpilot_fix_declined', __( 'You undid this fix, so SearchPilot does not apply it automatically again.', 'searchpilot-pro' ) ) );
			}
			$result                = $this->apply( $plan, $source, $group_id, $opts );
			$result['fingerprint'] = $plan['fingerprint'];
			return $result;
		}
		if ( ! empty( $opts['skip_declined'] ) && $this->declined( $issue, null ) ) {
			return $this->failure( new \WP_Error( 'searchpilot_fix_declined', __( 'You undid this fix, so SearchPilot does not apply it automatically again.', 'searchpilot-pro' ) ) );
		}
		return $this->apply_legacy( $issue, $source, $group_id, $opts );
	}

	/**
	 * Whether a person declined this fix: they undid it (recorded on undo), or
	 * the planner proposes exactly the fix applied before (so the change was
	 * reverted by hand). Declined fixes are never re-applied automatically
	 * until what the planner proposes changes.
	 *
	 * @param array       $issue       Issue.
	 * @param string|null $fingerprint Fingerprint of the plan proposed now (null: legacy fixer).
	 * @return bool
	 */
	public function declined( array $issue, $fingerprint ) {
		if ( $this->repo->fix_declined( $issue, $fingerprint ) ) {
			return true;
		}
		if ( null !== $fingerprint ) {
			$applied = $this->repo->applied_fix( (int) $issue['id'] );
			if ( null !== $applied && '' !== $applied && hash_equals( $applied, $fingerprint ) ) {
				// The same change is proposed again: the person reverted it by hand.
				$this->repo->decline_fix( (int) $issue['id'] );
				return true;
			}
		}
		return false;
	}

	/**
	 * Records that a person undid a fix (hooked to searchpilot_activity_undone).
	 *
	 * @param object $row Activity row.
	 */
	public function on_undone( $row ) {
		if ( $this->self_undo || ! is_object( $row ) ) {
			return;
		}
		$issue_id = $this->repo->issue_for_activity( (int) $row->id );
		if ( $issue_id ) {
			$this->repo->decline_fix( $issue_id );
		}
	}

	/**
	 * Runs a legacy `fixer` callable with logging, grouping and — for
	 * issues on posts — re-scan verification and automatic undo.
	 *
	 * @param array  $issue    Issue.
	 * @param string $source   Source.
	 * @param string $group_id Group to put the fixer's ungrouped changes in ('' = a new group).
	 * @param array  $opts     unattended (default: source is not 'user').
	 * @return array Same shape as apply().
	 */
	public function apply_legacy( array $issue, $source = 'user', $group_id = '', array $opts = array() ) {
		$def = $this->registry->get( (string) $issue['type'] );
		if ( ! $def || empty( $def['fixer'] ) || ! is_callable( $def['fixer'] ) || 'advisory' === $this->registry->mode_for( $def, $issue ) ) {
			return $this->failure( $this->not_plannable( (array) $def, $issue ) );
		}
		$posts = array();
		if ( 'post' === $issue['object_type'] && $issue['object_id'] && get_post( (int) $issue['object_id'] ) ) {
			$posts[] = (int) $issue['object_id'];
		}
		if ( $posts && ( $opts['unattended'] ?? 'user' !== $source ) ) {
			$locked = $this->locked(
				array(
					'kind'      => 'post_meta',
					'object_id' => $posts[0],
				)
			);
			if ( is_wp_error( $locked ) ) {
				return $this->failure( $locked );
			}
		}
		$prescan   = $posts && (bool) apply_filters( 'searchpilot_fix_prescan', true, array( 'issue_id' => (int) $issue['id'], 'type' => $issue['type'] ) );
		$started   = time();
		$unchecked = array();
		$before    = $this->open_types_of( $posts, $prescan, $unchecked );

		$issue['source'] = $source;
		$this->captured  = array();
		// Every change of the fix lands in one group, so it can be undone in one click.
		$group_id = '' !== (string) $group_id ? (string) $group_id : ActivityLog::new_group_id();

		$this->capture_group  = $group_id;
		$this->capture_groups = array();
		add_action( 'searchpilot_activity_recorded', array( $this, 'capture' ), 10, 2 );
		try {
			$outcome = call_user_func( $def['fixer'], $issue );
		} catch ( \Throwable $e ) {
			$outcome = new \WP_Error( 'searchpilot_fix_failed', $e->getMessage() );
		}
		remove_action( 'searchpilot_activity_recorded', array( $this, 'capture' ), 10 );
		$ids    = (array) $this->captured;
		$groups = array_values( array_unique( $this->capture_groups ) );
		// A fixer that groups its own changes (e.g. ALT text fills) keeps its group.
		if ( 1 === count( $groups ) ) {
			$group_id = $groups[0];
		}
		$this->captured       = null;
		$this->capture_group  = '';
		$this->capture_groups = array();

		if ( is_wp_error( $outcome ) ) {
			$this->logger->warning( 'issues', sprintf( 'Fix failed for %s: %s', $issue['type'], $outcome->get_error_message() ), array( 'issue' => (int) $issue['id'] ) );
			return $this->failure( $outcome );
		}
		$result = $this->success( (string) $group_id, $ids );
		if ( $posts ) {
			$this->purger->purge_post( $posts[0] );
			$scan = $this->rescan( $posts[0] );
			if ( ! $scan['checked'] ) {
				$unchecked[] = $scan['error'];
			}
			if ( $unchecked && $ids ) {
				return $this->pending( $result, array( 'type' => $issue['type'] ), $issue, $posts, $unchecked, $source, $before );
			}
			$new = self::introduced( $before, array( $posts[0] => $scan['types'] ), $this->not_introduced_by( (string) $issue['type'], false ) );
			if ( $new && $ids ) {
				$this->undo_ids( $ids );
				$this->purger->purge_post( $posts[0] );
				$this->rescan( $posts[0] );
				$result['applied']    = false;
				$result['introduced'] = $new;
				$result['code']       = 'searchpilot_fix_introduced';
				/* translators: %s: list of issue labels. */
				$result['error'] = sprintf( __( 'The fix was undone automatically because it caused a new problem: %s.', 'searchpilot-pro' ), implode( ', ', $this->type_labels( $new ) ) );
				return $result;
			}
			$fresh = $this->repo->get( (int) $issue['id'] );
			$open  = $fresh && in_array( $fresh['status'], IssueRepository::OPEN_STATUSES, true );
			// Seen by the scans around the fix = this type is re-evaluated by a re-scan.
			$evaluated          = $open && ! empty( $fresh['last_seen_at'] ) && strtotime( (string) $fresh['last_seen_at'] ) >= $started;
			$result['verified'] = ! $open;
			if ( $evaluated ) {
				// Re-detected after the fix: keep it open and say so.
				$result['code']         = 'searchpilot_fix_unverified';
				$result['error']        = __( 'The fix was applied, but a fresh check still finds the problem. Review the page manually.', 'searchpilot-pro' );
				$result['issue_status'] = $fresh['status'];
				return $result;
			}
		}
		if ( $ids ) {
			$this->repo->remember_fix( (int) $issue['id'], '', $ids );
		}
		if ( ! $posts ) {
			// Site-level fixers have no page to re-scan: their own success is the confirmation
			// (the module that raised the issue re-evaluates it on its next check).
			$result['verified'] = true;
		}
		$this->mark_fixed( $issue, $source );
		$result['issue_status'] = 'fixed';
		$this->logger->info(
			'issues',
			sprintf( 'Fixed %s (%s).', $issue['type'], $source ),
			array(
				'issue' => (int) $issue['id'],
				'url'   => $issue['url'] ?? '',
			)
		);
		return $result;
	}

	/**
	 * Captures activity entries recorded by a legacy fixer.
	 *
	 * @param int   $id  Activity id.
	 * @param array $row Row.
	 */
	public function capture( $id, $row ) {
		if ( null === $this->captured || ! $id ) {
			return;
		}
		$this->captured[]       = (int) $id;
		$this->capture_groups[] = '' !== (string) ( $row['group_id'] ?? '' ) ? (string) $row['group_id'] : $this->capture_group;
		if ( '' !== $this->capture_group && '' === (string) ( $row['group_id'] ?? '' ) ) {
			global $wpdb;
			$wpdb->update( \SearchPilot\Database\Tables::activity(), array( 'group_id' => $this->capture_group ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ------------------------------------------------------------------
	 * Stored plans (preview → apply)
	 * ------------------------------------------------------------------ */

	/**
	 * Stores a plan for the current user; returns its id.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	public function store_plan( array $plan ) {
		$id = substr( sha1( wp_generate_password( 20, false ) . ( $plan['fingerprint'] ?? '' ) ), 0, 24 );
		set_transient(
			'searchpilot_fixplan_' . $id,
			array(
				'user' => get_current_user_id(),
				'plan' => $plan,
			),
			self::PLAN_TTL
		);
		return $id;
	}

	/**
	 * A stored plan of the current user, or null.
	 *
	 * @param string $id Plan id.
	 * @return array|null
	 */
	public function load_plan( $id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $id );
		if ( '' === $id ) {
			return null;
		}
		$stored = get_transient( 'searchpilot_fixplan_' . $id );
		if ( ! is_array( $stored ) || (int) ( $stored['user'] ?? -1 ) !== get_current_user_id() || ! is_array( $stored['plan'] ?? null ) ) {
			return null;
		}
		return $stored['plan'];
	}

	/**
	 * Forgets a stored plan.
	 *
	 * @param string $id Plan id.
	 */
	public function forget_plan( $id ) {
		delete_transient( 'searchpilot_fixplan_' . preg_replace( '/[^a-f0-9]/', '', (string) $id ) );
	}

	/* ------------------------------------------------------------------
	 * Re-scans
	 * ------------------------------------------------------------------ */

	/**
	 * Re-scans one post (Audit\Rescanner when available, else content
	 * analysis + link checks) and returns its open issue types.
	 *
	 * @param int $post_id Post id.
	 * @return array{checked:bool,types:string[],error:string}
	 */
	public function rescan( $post_id ) {
		$post_id = (int) $post_id;
		$out     = $this->run_rescan( $post_id );
		/**
		 * Fires after the fix engine re-scanned a post (before and after a fix), so
		 * modules whose issue types the re-scanner does not re-evaluate can re-check
		 * them for this post. Open types are re-read afterwards.
		 *
		 * @param int   $post_id Post id.
		 * @param array $out     Scan result {checked, types, error}.
		 */
		do_action( 'searchpilot_fix_rescanned', $post_id, $out );
		if ( has_action( 'searchpilot_fix_rescanned' ) ) {
			$out['types'] = $this->open_types( $post_id );
		}
		return $out;
	}

	/**
	 * The actual re-scan.
	 *
	 * @param int $post_id Post id.
	 * @return array{checked:bool,types:string[],error:string}
	 */
	private function run_rescan( $post_id ) {
		$class = '\SearchPilot\Audit\Rescanner';
		try {
			if ( class_exists( $class ) ) {
				$scanner = \SearchPilot\Plugin::instance()->get( $class );
				if ( is_object( $scanner ) && method_exists( $scanner, 'rescan_post' ) ) {
					/**
					 * Filters the options of the re-scans around a fix (Rescanner::rescan_post()
					 * options: crawl, analysis, links).
					 *
					 * @param array $opts    Options.
					 * @param int   $post_id Post id.
					 */
					$opts = (array) apply_filters( 'searchpilot_fix_rescan_opts', array(), $post_id );
					$out  = (array) $scanner->rescan_post( $post_id, $opts );
					return array(
						'checked' => ! empty( $out['checked'] ),
						'types'   => isset( $out['types'] ) ? array_values( array_unique( array_map( 'strval', (array) $out['types'] ) ) ) : $this->open_types( $post_id ),
						'error'   => (string) ( $out['error'] ?? '' ),
					);
				}
			}
			$checked  = false;
			$analysis = '\SearchPilot\Seo\Analysis\AnalysisModule';
			if ( class_exists( $analysis ) && method_exists( $analysis, 'run_analyze_post' ) ) {
				$checked = null !== \SearchPilot\Plugin::instance()->get( $analysis )->run_analyze_post( $post_id );
			}
			$links = '\SearchPilot\Links\LinksModule';
			if ( class_exists( $links ) && method_exists( $links, 'refresh_link_issues' ) ) {
				\SearchPilot\Plugin::instance()->get( $links )->refresh_link_issues( array( $post_id ) );
			}
			return array(
				'checked' => $checked,
				'types'   => $this->open_types( $post_id ),
				'error'   => $checked ? '' : __( 'The page could not be analysed.', 'searchpilot-pro' ),
			);
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'issues', sprintf( 'Re-scan of post %d after a fix failed: %s', $post_id, $e->getMessage() ) );
			return array(
				'checked' => false,
				'types'   => $this->open_types( $post_id ),
				'error'   => __( 'The re-check failed.', 'searchpilot-pro' ),
			);
		}
	}

	/**
	 * Queues background re-scans (after an undo or an unverified fix).
	 *
	 * @param int[] $post_ids Post ids.
	 */
	public function queue_rescan( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( ! $post_ids ) {
			return;
		}
		try {
			$class = '\SearchPilot\Audit\Rescanner';
			if ( class_exists( $class ) ) {
				$scanner = \SearchPilot\Plugin::instance()->get( $class );
				if ( is_object( $scanner ) && method_exists( $scanner, 'queue_rescan' ) ) {
					$scanner->queue_rescan( $post_ids );
					return;
				}
			}
			$analysis = '\SearchPilot\Seo\Analysis\AnalysisModule';
			if ( class_exists( $analysis ) && method_exists( $analysis, 'mark' ) ) {
				\SearchPilot\Plugin::instance()->get( $analysis )->mark( $post_ids, 10 );
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'issues', 'Could not queue re-scans: ' . $e->getMessage() );
		}
	}

	/**
	 * Open issue types of a post.
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	public function open_types( $post_id ) {
		$types = array();
		foreach ( $this->repo->for_object( 'post', (int) $post_id ) as $row ) {
			$types[] = (string) $row['type'];
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Undoes activity entries (newest first).
	 *
	 * @param int[] $ids Activity ids.
	 * @return array{undone:int,failed:int}
	 */
	public function undo_ids( array $ids ) {
		$out = array(
			'undone' => 0,
			'failed' => 0,
		);
		rsort( $ids );
		$this->self_undo = true;
		try {
			foreach ( $ids as $id ) {
				$r = $this->log->undo( (int) $id );
				if ( is_wp_error( $r ) ) {
					++$out['failed'];
				} else {
					++$out['undone'];
				}
			}
		} finally {
			$this->self_undo = false;
		}
		return $out;
	}

	/**
	 * Issue types a fix of $type is not blamed for when they appear:
	 * cross-object types (unless the plan writes other posts' content) and
	 * types that only become measurable once the fixed problem is gone
	 * (`measurable_after` on their definition, e.g. description_length after
	 * missing_meta_description: an empty description has no length).
	 *
	 * @param string $type           Fixed issue type.
	 * @param bool   $writes_others  Whether the plan writes other posts' content.
	 * @return string[]
	 */
	public function not_introduced_by( $type, $writes_others ) {
		$out = $writes_others ? array() : $this->incoming_types();
		foreach ( $this->registry->all() as $other => $def ) {
			$after = (array) ( $def['measurable_after'] ?? array() );
			if ( isset( self::MEASURABLE_AFTER[ $other ] ) ) {
				$after = array_merge( $after, self::MEASURABLE_AFTER[ $other ] );
			}
			if ( in_array( (string) $type, $after, true ) ) {
				$out[] = (string) $other;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the plan's changes are all part of a freshly made plan.
	 *
	 * @param array $plan  Plan being applied (possibly a reviewed subset).
	 * @param array $fresh Plan made now.
	 * @return bool
	 */
	public static function is_subset( array $plan, array $fresh ) {
		$known = array_flip( array_map( array( Plan::class, 'signature' ), (array) $fresh['changes'] ) );
		foreach ( (array) $plan['changes'] as $change ) {
			if ( ! isset( $known[ Plan::signature( (array) $change ) ] ) ) {
				return false;
			}
		}
		return ! empty( $plan['changes'] );
	}

	/**
	 * Whether SearchPilot's own page checks are paused (the crawler's circuit
	 * breaker after repeated failures), so a fix could not be verified.
	 *
	 * @return bool
	 */
	private function checks_paused() {
		$class = '\SearchPilot\Indexer\Crawler';
		try {
			if ( class_exists( $class ) && method_exists( $class, 'paused_until' ) ) {
				return (int) \SearchPilot\Plugin::instance()->get( $class )->paused_until() > 0;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Error for an unattended fix postponed because it could not be verified now.
	 *
	 * @return \WP_Error
	 */
	private static function deferred() {
		return new \WP_Error( 'searchpilot_fix_deferred', __( 'Postponed: SearchPilot cannot re-check pages right now, so the fix could not be verified. It will be tried again later.', 'searchpilot-pro' ), array( 'status' => 409 ) );
	}

	/**
	 * A value read from the database, bypassing the request's object cache
	 * (another process may have saved the post meanwhile).
	 *
	 * @param array $change Change.
	 * @return mixed
	 */
	public function fresh_value( array $change ) {
		global $wpdb;
		$id = (int) ( $change['object_id'] ?? 0 );
		switch ( (string) ( $change['kind'] ?? '' ) ) {
			case 'post_content':
				clean_post_cache( $id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
			case 'option':
				$name = (string) $change['field'];
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return get_option( $name );
		}
		wp_cache_delete( $id, 'post_meta' );
		return $this->current_value( $change );
	}

	/**
	 * Error when someone is editing the post a change writes (an active edit
	 * lock held by another user), else true.
	 *
	 * @param array $change Change.
	 * @return true|\WP_Error
	 */
	private function locked( array $change ) {
		if ( 'option' === ( $change['kind'] ?? '' ) || empty( $change['object_id'] ) ) {
			return true;
		}
		$id = (int) $change['object_id'];
		wp_cache_delete( $id, 'post_meta' );
		$lock = explode( ':', (string) get_post_meta( $id, '_edit_lock', true ) );
		$time = (int) ( $lock[0] ?? 0 );
		$user = isset( $lock[1] ) ? (int) $lock[1] : (int) get_post_meta( $id, '_edit_last', true );
		/** This filter is documented in wp-admin/includes/post.php */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 );
		if ( ! $time || $time <= time() - $window || ! $user || get_current_user_id() === $user ) {
			return true;
		}
		$who = get_userdata( $user );
		return new \WP_Error(
			'searchpilot_fix_locked',
			/* translators: 1: post title, 2: user name. */
			sprintf( __( 'Skipped: “%1$s” is being edited by %2$s right now. It will be tried again later.', 'searchpilot-pro' ), html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), $who ? $who->display_name : __( 'someone', 'searchpilot-pro' ) ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Issue types open after a fix that were not open before, per post.
	 *
	 * @param array<int,string[]> $before Types per post before.
	 * @param array<int,string[]> $after  Types per post after.
	 * @param string[]            $ignore Types that cannot be caused by the fix (see incoming_types()).
	 * @return string[]
	 */
	public static function introduced( array $before, array $after, array $ignore = array() ) {
		$out = array();
		foreach ( $after as $post_id => $types ) {
			$out = array_merge( $out, array_diff( array_map( 'strval', (array) $types ), array_map( 'strval', (array) ( $before[ $post_id ] ?? array() ) ) ) );
		}
		$out = array_values( array_diff( array_unique( $out ), array_map( 'strval', $ignore ) ) );
		sort( $out );
		return $out;
	}

	/**
	 * Whether a plan writes the content of a post other than the issue's
	 * object (link plans: links are added in SOURCE posts for a TARGET).
	 *
	 * @param array $plan Plan.
	 * @return bool
	 */
	public static function writes_other_content( array $plan ) {
		foreach ( (array) ( $plan['changes'] ?? array() ) as $change ) {
			if ( 'post_content' === ( $change['kind'] ?? '' ) && (int) ( $change['object_id'] ?? 0 ) !== (int) ( $plan['object_id'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cross-object issue types (they depend on OTHER posts, e.g. incoming
	 * links), which a change to the fixed post itself cannot introduce:
	 * orphan_page and every type registered with `scope => 'incoming'`.
	 *
	 * @return string[]
	 */
	public function incoming_types() {
		$out = array( 'orphan_page' );
		foreach ( $this->registry->all() as $type => $def ) {
			if ( 'incoming' === ( $def['scope'] ?? '' ) ) {
				$out[] = (string) $type;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Posts a plan touches that can be re-scanned.
	 *
	 * @param array $plan Plan.
	 * @return int[]
	 */
	public function rescan_targets( array $plan ) {
		$out = array();
		foreach ( Plan::objects( $plan ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'attachment' !== $post->post_type && 'revision' !== $post->post_type ) {
				$out[] = (int) $id;
			}
		}
		return array_slice( $out, 0, 25 );
	}

	/* ------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Checks that a change's target exists and may be written.
	 *
	 * @param array $change Change.
	 * @param array $plan   Plan.
	 * @return true|\WP_Error
	 */
	private function check_target( array $change, array $plan ) {
		$kind = $change['kind'];
		if ( 'option' === $kind ) {
			if ( get_current_user_id() && ! Capabilities::can_manage() ) {
				return new \WP_Error( 'rest_forbidden', __( 'Only administrators can apply fixes that change site settings.', 'searchpilot-pro' ), array( 'status' => 403 ) );
			}
			return true;
		}
		$post = get_post( (int) $change['object_id'] );
		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists, so there is nothing to fix.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		if ( 'attachment_alt' === $kind && 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'searchpilot_plan_invalid', __( 'The fix plan changes ALT text of something that is not an image.', 'searchpilot-pro' ) );
		}
		if ( 'featured_image' === $kind && $change['value'] && ( 'attachment' !== get_post_type( (int) $change['value'] ) || ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( (int) $change['value'] ) ) ) ) {
			return new \WP_Error( 'searchpilot_plan_invalid', __( 'The chosen featured image is not an image in the media library.', 'searchpilot-pro' ) );
		}
		if ( get_current_user_id() && ! Capabilities::can_edit( (int) $post->ID ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You cannot edit this content.', 'searchpilot-pro' ), array( 'status' => 403 ) );
		}
		if ( 'seo_field' === $kind ) {
			if ( ! isset( MetaStore::FIELD_FEATURE[ $change['field'] ] ) && ! in_array( $change['field'], MetaStore::OWN_FIELDS, true ) ) {
				return new \WP_Error( 'searchpilot_plan_invalid', __( 'The fix plan names an unknown SEO field.', 'searchpilot-pro' ) );
			}
			$owner = $this->field_owner( $change['field'] );
			if ( ! $owner['writable'] ) {
				$labels  = MetaStore::field_labels();
				$message = '' !== $owner['reason'] ? $owner['reason'] : sprintf(
					/* translators: 1: field label, 2: plugin name. */
					__( 'The %1$s is managed by %2$s, and SearchPilot cannot change it there. Change it in %2$s.', 'searchpilot-pro' ),
					$labels[ $change['field'] ] ?? $change['field'],
					'' !== $owner['owner_name'] ? $owner['owner_name'] : $owner['owner']
				);
				return new \WP_Error( 'searchpilot_fix_not_owner', $message, array( 'status' => 409 ) );
			}
		}
		if ( 'post_content' === $kind ) {
			$builder = self::builder_of( $post );
			if ( '' !== $builder ) {
				return new \WP_Error(
					'searchpilot_fix_builder',
					/* translators: %s: page builder name. */
					sprintf( __( 'This page is built with %s, which does not display the WordPress content field. Make this change in %s.', 'searchpilot-pro' ), $builder, $builder ),
					array( 'status' => 409 )
				);
			}
		}
		return true;
	}

	/**
	 * Page builder whose layout replaces post_content ('' when none).
	 *
	 * @param \WP_Post $post Post.
	 * @return string Builder name.
	 */
	private static function builder_of( \WP_Post $post ) {
		$renderer = '\SearchPilot\Seo\Analysis\ContentRenderer';
		if ( class_exists( $renderer ) && method_exists( $renderer, 'builder_of' ) ) {
			$id = (string) $renderer::builder_of( $post );
			if ( '' === $id ) {
				return '';
			}
			$name = method_exists( $renderer, 'builder_name' ) ? (string) $renderer::builder_name( $id ) : '';
			return '' !== $name ? $name : $id;
		}
		$id = (int) $post->ID;
		if ( 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
			return 'Elementor';
		}
		if ( get_post_meta( $id, '_fl_builder_enabled', true ) ) {
			return 'Beaver Builder';
		}
		if ( 'bricks' === get_post_meta( $id, '_bricks_editor_mode', true ) ) {
			return 'Bricks';
		}
		if ( '' !== (string) get_post_meta( $id, 'ct_builder_shortcodes', true ) ) {
			return 'Oxygen';
		}
		if ( get_post_meta( $id, 'tcb_editor_enabled', true ) ) {
			return 'Thrive Architect';
		}
		if ( get_post_meta( $id, 'brizy_post_uid', true ) ) {
			return 'Brizy';
		}
		return '';
	}

	/**
	 * Writes one change through Changes / MetaStore.
	 *
	 * @param array  $change   Change.
	 * @param array  $plan     Plan.
	 * @param string $source   Source.
	 * @param string $group_id Group.
	 * @return int|\WP_Error Activity id (0 when unchanged).
	 */
	private function write( array $change, array $plan, $source, $group_id ) {
		$id     = (int) $change['object_id'];
		$target = 'option' === $change['kind'] ? get_bloginfo( 'name' ) : get_the_title( 'attachment_alt' === $change['kind'] && $plan['object_id'] ? $plan['object_id'] : $id );
		$opts   = array(
			'source'      => (string) $source,
			'group_id'    => (string) $group_id,
			'action'      => substr( 'fix_' . $plan['type'], 0, 64 ),
			'description' => sprintf(
				/* translators: 1: issue label, 2: changed item, 3: post or site title. */
				__( 'SEO fix (%1$s): %2$s of “%3$s”', 'searchpilot-pro' ),
				(string) ( $plan['label'] ?? $plan['type'] ),
				'' !== $change['label'] ? $change['label'] : $change['field'],
				html_entity_decode( (string) $target, ENT_QUOTES, 'UTF-8' )
			),
		);
		switch ( $change['kind'] ) {
			case 'seo_field':
				return $this->meta->set_post_field( $id, $change['field'], $change['value'], $opts );
			case 'post_content':
				if ( isset( $change['marker'] ) ) {
					$opts['marker'] = (string) $change['marker'];
				}
				return $this->changes->update_post_content( $id, (string) $change['value'], $opts );
			case 'post_meta':
				return $this->changes->update_post_meta( $id, $change['field'], $change['value'], $opts );
			case 'attachment_alt':
				$opts['object_type'] = 'attachment';
				return $this->changes->update_post_meta( $id, '_wp_attachment_image_alt', (string) $change['value'], $opts );
			case 'featured_image':
				return $this->changes->update_post_meta( $id, '_thumbnail_id', $change['value'] ? (int) $change['value'] : null, $opts );
			case 'option':
				return $this->changes->update_option( $change['field'], $change['value'], $opts );
		}
		return new \WP_Error( 'searchpilot_plan_invalid', __( 'The fix plan contains an unknown kind of change.', 'searchpilot-pro' ) );
	}

	/**
	 * Open issue types per post, optionally after a fresh scan.
	 *
	 * @param int[]    $posts     Post ids.
	 * @param bool     $scan      Re-scan first.
	 * @param string[] $unchecked Receives the errors of scans that did not complete.
	 * @return array<int,string[]>
	 */
	private function open_types_of( array $posts, $scan, array &$unchecked = array() ) {
		$out = array();
		foreach ( $posts as $post_id ) {
			if ( ! $scan ) {
				$out[ $post_id ] = $this->open_types( $post_id );
				continue;
			}
			$result = $this->rescan( $post_id );
			if ( ! $result['checked'] ) {
				$unchecked[] = $result['error'];
			}
			$out[ $post_id ] = $result['types'];
		}
		return $out;
	}

	/**
	 * Result for a fix whose verification could not run (a re-scan did not
	 * complete): the change is kept, the issue is not marked fixed, nothing
	 * is undone, the activity entries say so and a background re-scan is
	 * queued. `verified` is null.
	 *
	 * @param array      $result    Result so far.
	 * @param array      $plan      Plan (or array( verify => objects ) for legacy fixes).
	 * @param array|null $issue     Issue.
	 * @param int[]      $posts     Touched posts.
	 * @param string[]   $unchecked Scan errors.
	 * @param string     $source    Source.
	 * @param array      $before    Open types per post before the fix (baseline for the sweep).
	 * @return array
	 */
	private function pending( array $result, array $plan, $issue, array $posts, array $unchecked, $source, array $before = array() ) {
		$targets = array_values( array_unique( array_filter( array_map( 'intval', array_merge( $posts, (array) ( $plan['verify']['objects'] ?? array() ) ) ) ) ) );
		$this->queue_rescan( $targets );
		$this->note_activity( (array) $result['activity'], self::note_text( 'pending' ) );
		if ( $issue && $result['activity'] ) {
			$this->repo->remember_fix( (int) $issue['id'], isset( $plan['changes'] ) ? Plan::fingerprint( $plan ) : '', (array) $result['activity'] );
		}
		$this->remember_pending(
			array(
				'issue_id' => $issue ? (int) $issue['id'] : 0,
				'type'     => (string) ( $plan['type'] ?? ( $issue['type'] ?? '' ) ),
				'posts'    => $targets,
				'activity' => array_values( array_map( 'intval', (array) $result['activity'] ) ),
				'group_id' => (string) $result['group_id'],
				'source'   => (string) $source,
				'before'   => $before,
				'writes'   => self::writes_other_content( $plan ),
				'at'       => time(),
				'last'     => time(),
				'attempts' => 0,
			)
		);
		$detail             = trim( implode( ' ', array_unique( array_filter( array_map( 'strval', $unchecked ) ) ) ) );
		$result['verified'] = null;
		$result['code']     = 'searchpilot_fix_pending';
		$result['error']    = __( 'Applied — verification is pending because SearchPilot could not re-check the page right now (for example, self-checks are paused). It will be re-checked automatically.', 'searchpilot-pro' ) . ( '' !== $detail ? ' ' . $detail : '' );
		if ( $issue ) {
			$result['issue_status'] = (string) ( $this->repo->get( (int) $issue['id'] )['status'] ?? '' );
		}
		$this->logger->info(
			'issues',
			sprintf( 'Applied fix for %s (%s); verification pending: %s', (string) ( $plan['type'] ?? ( $issue['type'] ?? '' ) ), $source, '' !== $detail ? $detail : 'scan incomplete' ),
			array(
				'issue' => $issue ? (int) $issue['id'] : 0,
				'group' => $result['group_id'],
			)
		);
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Pending verifications (self-healing)
	 * ------------------------------------------------------------------ */

	/**
	 * Activity note texts.
	 *
	 * @param string $state pending|verified|unverified|failed|introduced.
	 * @return string
	 */
	public static function note_text( $state ) {
		switch ( $state ) {
			case 'verified':
				return __( 'verified', 'searchpilot-pro' );
			case 'unverified':
				return __( 'not confirmed: a fresh check still finds the problem', 'searchpilot-pro' );
			case 'failed':
				return __( 'could not verify (the page could not be re-checked); the change was kept', 'searchpilot-pro' );
			case 'introduced':
				return __( 'undone automatically after verification: it caused a new problem', 'searchpilot-pro' );
		}
		return __( 'verification pending', 'searchpilot-pro' );
	}

	/**
	 * Stores a pending verification so the periodic sweep can finish it even
	 * when its queued re-scan is lost (e.g. the re-scan queue was cancelled).
	 *
	 * @param array $record issue_id, type, posts, activity, group_id, source, at, last, attempts.
	 */
	public function remember_pending( array $record ) {
		$all = $this->pending_verifications();
		$key = substr( sha1( $record['group_id'] . '|' . $record['issue_id'] . '|' . implode( ',', $record['posts'] ) ), 0, 16 );

		$all[ $key ] = $record;
		if ( count( $all ) > self::PENDING_MAX ) {
			// Oldest first out: they are past any useful retry window.
			uasort(
				$all,
				static function ( $a, $b ) {
					return (int) $a['at'] - (int) $b['at'];
				}
			);
			foreach ( array_slice( $all, 0, count( $all ) - self::PENDING_MAX, true ) as $old_key => $old ) {
				$this->replace_note( (array) $old['activity'], 'failed' );
				unset( $all[ $old_key ] );
			}
		}
		update_option( self::PENDING_OPTION, $all, false );
	}

	/**
	 * Pending verifications.
	 *
	 * @return array<string,array>
	 */
	public function pending_verifications() {
		$all = get_option( self::PENDING_OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * What to do with a pending verification.
	 *
	 * @param array $record     Record (at, last, attempts).
	 * @param bool  $issue_open Whether the planned issue is still open (false also when there is no issue
	 *                          and none of its types is open).
	 * @param bool  $scanned    Whether a complete re-scan ran in this sweep.
	 * @param int   $now        Now (Unix time).
	 * @return string verified|unverified|wait|retry|give_up
	 */
	public static function pending_decision( array $record, $issue_open, $scanned, $now ) {
		if ( ! $issue_open ) {
			return 'verified';
		}
		if ( $scanned ) {
			return 'unverified';
		}
		if ( $now - (int) ( $record['last'] ?? 0 ) < self::PENDING_RETRY ) {
			return 'wait';
		}
		return (int) ( $record['attempts'] ?? 0 ) + 1 >= self::PENDING_ATTEMPTS ? 'give_up' : 'retry';
	}

	/**
	 * Periodic sweep: finishes pending verifications older than ~15 minutes.
	 * Each record's posts are re-scanned here (no dependence on the queue);
	 * a record whose scans still cannot complete is re-queued, and after a
	 * few attempts marked "could not verify" (the change is kept).
	 *
	 * @param TimeBudget $budget Budget.
	 * @param int        $limit  Max records per sweep.
	 * @return array{verified:int,unverified:int,undone:int,retried:int,given_up:int,waiting:int}
	 */
	public function sweep_pending( $budget, $limit = 5 ) {
		$stats = array(
			'verified'   => 0,
			'unverified' => 0,
			'undone'     => 0,
			'retried'    => 0,
			'given_up'   => 0,
			'waiting'    => 0,
		);
		$all   = $this->pending_verifications();
		$done  = 0;
		$now   = time();
		foreach ( $all as $key => $record ) {
			if ( $done >= $limit || $budget->exhausted( 10.0 ) ) {
				break;
			}
			if ( $now - (int) $record['last'] < self::PENDING_RETRY ) {
				++$stats['waiting'];
				continue;
			}
			++$done;
			$scanned = true;
			$after   = array();
			foreach ( (array) $record['posts'] as $post_id ) {
				if ( ! get_post( (int) $post_id ) ) {
					continue;
				}
				$scan                    = $this->rescan( (int) $post_id );
				$scanned                 = $scanned && $scan['checked'];
				$after[ (int) $post_id ] = $scan['types'];
			}
			$issue = $record['issue_id'] ? $this->repo->get( (int) $record['issue_id'] ) : null;
			$open  = $this->pending_open( $record, $issue );
			if ( $scanned && isset( $record['before'] ) ) {
				$introduced = self::introduced( (array) $record['before'], $after, $this->not_introduced_by( (string) $record['type'], ! empty( $record['writes'] ) ) );
				if ( $introduced ) {
					$this->undo_ids( (array) $record['activity'] );
					foreach ( array_keys( $after ) as $post_id ) {
						$this->purger->purge_post( $post_id );
						$this->rescan( $post_id );
					}
					$this->replace_note( (array) $record['activity'], 'introduced' );
					$this->logger->warning( 'issues', sprintf( 'Fix for %s undone after verification: it introduced %s.', $record['type'], implode( ', ', $introduced ) ), array( 'issue' => (int) $record['issue_id'] ) );
					unset( $all[ $key ] );
					++$stats['undone'];
					continue;
				}
			}
			$decision = self::pending_decision( $record, $open, $open && $scanned && $record['posts'], time() );
			if ( 'verified' === $decision && ! $this->resolved_by_scan( $record, $issue ) ) {
				// Resolved because the post was trashed, unpublished or deleted — not a fix.
				$decision = 'gone';
			}
			if ( 'gone' === $decision ) {
				$this->replace_note( (array) $record['activity'], 'failed' );
				unset( $all[ $key ] );
				++$stats['given_up'];
			} elseif ( 'verified' === $decision ) {
				if ( $issue && 'resolved' === $issue['status'] ) {
					$this->mark_fixed( $issue, (string) $record['source'] );
				}
				$this->replace_note( (array) $record['activity'], 'verified' );
				unset( $all[ $key ] );
				++$stats['verified'];
			} elseif ( 'unverified' === $decision ) {
				$this->replace_note( (array) $record['activity'], 'unverified' );
				unset( $all[ $key ] );
				++$stats['unverified'];
			} elseif ( 'give_up' === $decision ) {
				$this->replace_note( (array) $record['activity'], 'failed' );
				$this->logger->warning( 'issues', sprintf( 'Could not verify the fix for %s after %d attempts; the change was kept.', $record['type'], self::PENDING_ATTEMPTS ), array( 'issue' => (int) $record['issue_id'] ) );
				unset( $all[ $key ] );
				++$stats['given_up'];
			} else {
				$all[ $key ]['attempts'] = (int) $record['attempts'] + 1;
				$all[ $key ]['last']     = time();
				$this->queue_rescan( (array) $record['posts'] );
				++$stats['retried'];
			}
		}
		update_option( self::PENDING_OPTION, $all, false );
		return $stats;
	}

	/**
	 * Whether a pending record's issue went away because a scan found the
	 * problem fixed: every touched post still published, and the issue
	 * resolved (or marked fixed) after the fix.
	 *
	 * @param array      $record Record.
	 * @param array|null $issue  Issue row (null when the record has no issue).
	 * @return bool
	 */
	private function resolved_by_scan( array $record, $issue ) {
		foreach ( (array) $record['posts'] as $post_id ) {
			if ( 'publish' !== get_post_status( (int) $post_id ) ) {
				return false;
			}
		}
		if ( ! $record['issue_id'] ) {
			return true;
		}
		if ( ! $issue ) {
			return false; // The issue row was deleted with its object.
		}
		if ( 'fixed' === $issue['status'] ) {
			return true;
		}
		return 'resolved' === $issue['status'] && ! empty( $issue['resolved_at'] ) && strtotime( (string) $issue['resolved_at'] ) >= (int) $record['at'];
	}

	/**
	 * Whether a pending record's problem is still open.
	 *
	 * @param array      $record Record.
	 * @param array|null $issue  Issue row.
	 * @return bool
	 */
	private function pending_open( array $record, $issue ) {
		if ( $record['issue_id'] ) {
			return $issue && in_array( $issue['status'], IssueRepository::OPEN_STATUSES, true );
		}
		foreach ( (array) $record['posts'] as $post_id ) {
			if ( in_array( (string) $record['type'], $this->open_types( (int) $post_id ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replaces the "verification pending" note of activity entries.
	 *
	 * @param int[]  $ids   Activity ids.
	 * @param string $state verified|unverified|failed.
	 */
	private function replace_note( array $ids, $state ) {
		global $wpdb;
		$table = \SearchPilot\Database\Tables::activity();
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET description = REPLACE(description, %s, %s) WHERE id = %d", ' — ' . self::note_text( 'pending' ), ' — ' . self::note_text( $state ), $id ) );
		}
	}

	/**
	 * Appends a note to the description of activity entries.
	 *
	 * @param int[]  $ids  Activity ids.
	 * @param string $note Note.
	 */
	private function note_activity( array $ids, $note ) {
		global $wpdb;
		$table = \SearchPilot\Database\Tables::activity();
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET description = CONCAT(description, %s) WHERE id = %d", ' — ' . $note, $id ) );
		}
	}

	/**
	 * Whether a plan is verified: the planned issue is no longer open when
	 * re-read after the re-scans (plans without an issue: none of the
	 * verify types is open on the plan's post).
	 *
	 * @param array      $plan  Plan.
	 * @param array|null $issue Issue.
	 * @param array      $after Types per re-scanned post after the fix.
	 * @return bool
	 */
	private function verified( array $plan, $issue, array $after ) {
		if ( $issue ) {
			$fresh = $this->repo->get( (int) $issue['id'] );
			return ! $fresh || ! in_array( $fresh['status'], IssueRepository::OPEN_STATUSES, true );
		}
		if ( 'post' === $plan['object_type'] && isset( $after[ $plan['object_id'] ] ) ) {
			return ! array_intersect( $plan['verify']['types'], $after[ $plan['object_id'] ] );
		}
		return true;
	}

	/**
	 * Marks an issue fixed and fires searchpilot_issue_fixed.
	 *
	 * @param array  $issue  Issue.
	 * @param string $source Source.
	 */
	private function mark_fixed( array $issue, $source ) {
		$this->repo->set_status( (int) $issue['id'], 'fixed' );
		/**
		 * Fires after an issue was fixed.
		 *
		 * @param array  $issue  Issue.
		 * @param string $source Source.
		 */
		do_action( 'searchpilot_issue_fixed', $issue, $source );
	}

	/**
	 * Labels of issue types.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public function type_labels( array $types ) {
		$out = array();
		foreach ( $types as $type ) {
			$def   = $this->registry->get( (string) $type );
			$out[] = html_entity_decode( (string) ( $def['label'] ?? $type ), ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * Default label for a change.
	 *
	 * @param array $change Change.
	 * @return string
	 */
	private function default_label( array $change ) {
		switch ( $change['kind'] ) {
			case 'seo_field':
				$labels = MetaStore::field_labels();
				return (string) ( $labels[ $change['field'] ] ?? $change['field'] );
			case 'post_content':
				return __( 'Content', 'searchpilot-pro' );
			case 'attachment_alt':
				return __( 'Image ALT text', 'searchpilot-pro' );
			case 'featured_image':
				return __( 'Featured image', 'searchpilot-pro' );
			case 'option':
				return __( 'Setting', 'searchpilot-pro' );
		}
		return (string) $change['field'];
	}

	/**
	 * Error for a type without a planner.
	 *
	 * @param array $def   Definition.
	 * @param array $issue Issue.
	 * @return \WP_Error
	 */
	private function not_plannable( array $def, array $issue ) {
		if ( ! empty( $def['fixer'] ) && empty( $def['planner'] ) && 'advisory' !== $this->registry->mode_for( $def, $issue ) ) {
			return new \WP_Error( 'searchpilot_no_preview', __( 'This fix has no preview; it is applied directly with “Fix automatically”.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$step = (string) ( $issue['manual_step'] ?? '' );
		return new \WP_Error(
			'searchpilot_not_fixable',
			__( 'This issue has no automatic fix.', 'searchpilot-pro' ) . ' ' . ( '' !== $step ? $step : __( 'Follow the recommendation to fix it manually.', 'searchpilot-pro' ) ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Result skeleton for a successful write.
	 *
	 * @param string $group_id Group.
	 * @param int[]  $ids      Activity ids.
	 * @return array
	 */
	private function success( $group_id, array $ids ) {
		return array(
			'applied'      => true,
			'group_id'     => (string) $group_id,
			'verified'     => false,
			'introduced'   => array(),
			'error'        => '',
			'code'         => '',
			'issue_status' => '',
			'activity'     => array_values( $ids ),
		);
	}

	/**
	 * Result for a refused or failed fix.
	 *
	 * @param \WP_Error $error        Error.
	 * @param string    $issue_status Issue status.
	 * @return array
	 */
	private function failure( \WP_Error $error, $issue_status = '' ) {
		return array(
			'applied'      => false,
			'group_id'     => '',
			'verified'     => false,
			'introduced'   => array(),
			'error'        => $error->get_error_message(),
			'code'         => (string) $error->get_error_code(),
			'issue_status' => (string) $issue_status,
			'activity'     => array(),
		);
	}
}
