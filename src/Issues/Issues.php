<?php
/**
 * Issue actions: fix, approve, ignore, automatic fixing.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Issues;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Logger;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Fixes\FixEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Applies fixes under the automation policy. Planner-based fixes run
 * through Fixes\FixEngine (plan → apply → re-scan → verify / auto-undo);
 * legacy `fixer` callables run through FixEngine::apply_legacy().
 */
class Issues {

	/**
	 * Automatic attempts that failed (rolled back / not verified) are not
	 * repeated for the same plan for this long.
	 */
	const RETRY_AFTER = WEEK_IN_SECONDS;

	/**
	 * Option holding the last issue id the automatic pass handled.
	 */
	const CURSOR_OPTION = 'searchpilot_autofix_cursor';

	/**
	 * Registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * Repository.
	 *
	 * @var IssueRepository
	 */
	private $repo;

	/**
	 * Policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Fix engine.
	 *
	 * @var FixEngine
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param IssueRegistry    $registry Registry.
	 * @param IssueRepository  $repo     Repository.
	 * @param AutomationPolicy $policy   Policy.
	 * @param Logger           $logger   Logger.
	 * @param FixEngine        $engine   Fix engine.
	 */
	public function __construct( IssueRegistry $registry, IssueRepository $repo, AutomationPolicy $policy, Logger $logger, FixEngine $engine ) {
		$this->registry = $registry;
		$this->repo     = $repo;
		$this->policy   = $policy;
		$this->logger   = $logger;
		$this->engine   = $engine;
	}

	/**
	 * Fixes an issue on behalf of the current user (explicit approval).
	 *
	 * @param int $id Issue id.
	 * @return true|\WP_Error True when the change was applied (see fix_result() for verification).
	 */
	public function fix( $id ) {
		$result = $this->fix_result( $id );
		if ( ! $result['applied'] ) {
			return new \WP_Error( '' !== $result['code'] ? $result['code'] : 'searchpilot_fix_failed', '' !== $result['error'] ? $result['error'] : __( 'The fix could not be applied.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Fixes an issue without a reviewed plan and returns the engine result.
	 * Only legacy fixers and `deterministic` planners may run this way;
	 * `assisted` planners must be applied from their preview
	 * (FixEngine::apply() with the reviewed plan).
	 *
	 * @param int    $id       Issue id.
	 * @param string $source   Source.
	 * @param string $group_id Activity group.
	 * @return array{applied:bool,group_id:string,verified:bool|null,introduced:string[],error:string,code:string,issue_status:string} verified null = verification pending.
	 */
	public function fix_result( $id, $source = 'user', $group_id = '' ) {
		$issue = $this->repo->get( $id );
		if ( ! $issue ) {
			return self::refusal( 'searchpilot_not_found', __( 'Issue not found.', 'searchpilot-pro' ) );
		}
		$def = $this->registry->get( $issue['type'] );
		if ( $def && IssueRegistry::has_planner( $def ) && 'assisted' === $this->registry->mode_for( $def, $issue ) ) {
			return self::refusal( 'searchpilot_review_required', __( 'This fix changes the page in a way you should check first. Open “Preview fix”, review the changes and apply them there.', 'searchpilot-pro' ) );
		}
		return $this->engine->fix_issue( $issue, $source, $group_id );
	}

	/**
	 * Engine-shaped result for a fix that was not attempted.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private static function refusal( $code, $message ) {
		return array(
			'applied'      => false,
			'group_id'     => '',
			'verified'     => false,
			'introduced'   => array(),
			'error'        => $message,
			'code'         => $code,
			'issue_status' => '',
		);
	}

	/**
	 * Marks an issue ignored.
	 *
	 * @param int $id Issue id.
	 * @return bool
	 */
	public function ignore( $id ) {
		return $this->repo->set_status( $id, 'ignored' );
	}

	/**
	 * Reopens an ignored issue.
	 *
	 * @param int $id Issue id.
	 * @return bool
	 */
	public function reopen( $id ) {
		return $this->repo->set_status( $id, 'open' );
	}

	/**
	 * Automatic pass: applies `deterministic` planners and legacy fixers
	 * whose task the policy allows; flags everything else that can be fixed
	 * `pending_approval`. `assisted` planners always wait for approval.
	 *
	 * @param TimeBudget|null $budget Time budget.
	 * @param int             $limit  Max issues to process.
	 * @return array{fixed:int,pending:int,failed:int,unverified:int,pending_verification:int,skipped:int}
	 */
	public function auto_fix( $budget = null, $limit = 50 ) {
		$budget = $budget ?: new TimeBudget( 20 );
		$stats  = array(
			'fixed'                => 0,
			'pending'              => 0,
			'failed'               => 0,
			'unverified'           => 0,
			'pending_verification' => 0,
			'skipped'              => 0,
		);
		$fixable = array();
		$allowed = array();
		foreach ( $this->registry->all() as $type => $def ) {
			$planner = IssueRegistry::has_planner( $def );
			$fixer   = ! empty( $def['fixer'] ) && is_callable( $def['fixer'] );
			if ( ( ! $planner && ! $fixer ) || 'advisory' === ( is_string( $def['mode'] ) ? $def['mode'] : '' ) || $this->policy->disabled( (string) $def['task'] ) ) {
				continue;
			}
			$fixable[] = $type;
			if ( empty( $def['destructive'] ) && $this->policy->allows( (string) $def['task'] ) ) {
				$allowed[] = $type;
			}
		}
		if ( empty( $fixable ) ) {
			return $stats;
		}
		// Walk all open rows with a cursor that persists across runs, so issues that are
		// skipped (paused, declined, advisory, being edited) never hold the window and
		// starve fixable ones on a large archive.
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$rows   = $this->window( $fixable, $cursor, $limit );
		if ( count( $rows ) < $limit && $cursor > 0 ) {
			$rows = array_merge( $rows, $this->window( $fixable, 0, $limit - count( $rows ) ) );
		}
		$last     = $cursor;
		$complete = true;
		foreach ( $rows as $issue ) {
			if ( $budget->exhausted() ) {
				$complete = false;
				break;
			}
			$previous = $last;
			$last     = (int) $issue['id'];
			$def      = $this->registry->get( $issue['type'] );
			if ( ! $def ) {
				continue;
			}
			$mode = $this->registry->mode_for( $def, $issue );
			if ( 'advisory' === $mode ) {
				continue;
			}
			$task    = (string) $def['task'];
			$planner = IssueRegistry::has_planner( $def );
			// Unattended: deterministic fixes, legacy fixers, and one-click fixes the type marks as
			// safe to run alone (`auto_ok`) — each only when the automation mode allows its task.
			$auto    = empty( $def['destructive'] ) && $this->policy->allows( $task ) && ( ! $planner || 'deterministic' === $mode || ( 'assisted' === $mode && IssueRegistry::auto_ok( $def, $issue ) ) );
			if ( 'pending_approval' === $issue['status'] && ! in_array( $issue['type'], $allowed, true ) ) {
				continue; // Waiting for a person.
			}
			if ( ! $auto ) {
				if ( 'pending_approval' !== $issue['status'] && ! $this->policy->disabled( $task ) ) {
					$this->repo->set_status( $issue['id'], 'pending_approval' );
					++$stats['pending'];
				}
				continue;
			}
			if ( get_transient( self::block_key( $issue['id'] ) ) ) {
				continue; // An automatic attempt failed recently; a person has to look at it.
			}
			$result = $this->engine->fix_issue(
				$issue,
				$this->policy->source(),
				'',
				array(
					'unattended'      => true,
					'undo_unverified' => true,
					'skip_declined'   => true,
				)
			);
			if ( $result['applied'] && true === $result['verified'] ) {
				++$stats['fixed'];
				continue;
			}
			if ( in_array( $result['code'], array( 'searchpilot_fix_declined', 'searchpilot_fix_locked', 'searchpilot_fix_deferred' ), true ) ) {
				++$stats['skipped'];
				// Declined by a person, being edited right now, or page checks unavailable:
				// no pause, the next cycle tries again. While checks are paused, stop here.
				if ( 'searchpilot_fix_deferred' === $result['code'] ) {
					$last     = $previous; // Retry this one first next time.
					$complete = false;
					break;
				}
				continue;
			}
			if ( $result['applied'] && null === $result['verified'] ) {
				// Applied; a background re-scan confirms it. Do not re-apply meanwhile.
				++$stats['pending_verification'];
				set_transient(
					self::block_key( $issue['id'] ),
					array(
						'code' => $result['code'],
						'at'   => time(),
					),
					DAY_IN_SECONDS
				);
				continue;
			}
			if ( 'searchpilot_fix_unverified_undone' === $result['code'] ) {
				++$stats['unverified'];
			} else {
				++$stats['failed'];
			}
			// Do not repeat a fix that was rolled back, refused or not confirmed every hour.
			set_transient(
				self::block_key( $issue['id'] ),
				array(
					'code' => $result['code'],
					'at'   => time(),
				),
				self::RETRY_AFTER
			);
			$this->logger->info(
				'automation',
				sprintf( 'Automatic fix of %s not completed: %s', $issue['type'], $result['error'] ),
				array( 'issue' => (int) $issue['id'] )
			);
		}
		// Whole window processed and shorter than the limit: the next run starts over.
		update_option( self::CURSOR_OPTION, $complete && count( $rows ) < $limit ? 0 : $last, false );
		return $stats;
	}

	/**
	 * Open (and awaiting-approval) issues of the given types after an id.
	 *
	 * @param string[] $types  Types.
	 * @param int      $cursor Last id handled.
	 * @param int      $limit  Rows.
	 * @return array[]
	 */
	private function window( array $types, $cursor, $limit ) {
		if ( $limit <= 0 ) {
			return array();
		}
		return $this->repo->query(
			array(
				'status'   => IssueRepository::OPEN_STATUSES,
				'type'     => $types,
				'min_id'   => (int) $cursor,
				'orderby'  => 'id',
				'per_page' => (int) $limit,
			)
		)['items'];
	}

	/**
	 * Transient key that pauses automatic attempts for an issue.
	 *
	 * @param int $issue_id Issue id.
	 * @return string
	 */
	public static function block_key( $issue_id ) {
		return 'searchpilot_fixblock_' . (int) $issue_id;
	}
}
