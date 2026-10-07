<?php
/**
 * Background bulk fix runs.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\ActivityLog;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies many fixes in the background (Action Scheduler when available,
 * else WP-Cron, through Scheduler::enqueue_async), independent of the
 * browser tab:
 *
 * - A run stores its items (issue id, the fingerprint of the plan the
 *   person reviewed and the signatures/keys of its changes) and a progress
 *   record in a non-autoloaded option.
 * - Each batch takes a Lock and a TimeBudget and re-plans every item. A
 *   plan identical to the reviewed one is applied. A plan that differs is
 *   applied only with its reviewed changes (same signature, or the same
 *   kind/object/field on an object an earlier item of the run changed);
 *   if it would write anything that was not reviewed, the item is skipped
 *   ("review again"). The job re-queues itself until the run is done.
 * - Every change of a run shares one activity group, so the whole run can
 *   be undone in one click.
 * - A watchdog on `searchpilot_every_5min` resumes runs whose batch died
 *   (fatal error, timeout); an item interrupted twice is marked failed.
 * - Hourly, fixes whose verification is pending (a re-scan could not
 *   complete) are finished by FixEngine::sweep_pending() in their own
 *   request (`searchpilot_fix_verify`), independent of the re-scan queue.
 */
class BulkRunner implements Module {

	const HOOK = 'searchpilot_fix_run';

	const VERIFY_HOOK = 'searchpilot_fix_verify';

	const UNDO_HOOK = 'searchpilot_fix_run_undo';

	const INDEX_OPTION = 'searchpilot_fix_runs';

	const RUN_OPTION = 'searchpilot_fixrun_';

	const MAX_ITEMS = 500;

	const KEEP_RUNS = 20;

	const STALE_AFTER = 300;

	/**
	 * Seconds to wait before retrying a run whose fixes cannot be verified now.
	 */
	const DEFER_DELAY = 600;

	/**
	 * Engine.
	 *
	 * @var FixEngine
	 */
	private $engine;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $repo;

	/**
	 * Registry.
	 *
	 * @var IssueRegistry
	 */
	private $registry;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Activity log.
	 *
	 * @var ActivityLog
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param FixEngine       $engine    Engine.
	 * @param IssueRepository $repo      Issues.
	 * @param IssueRegistry   $registry  Registry.
	 * @param Scheduler       $scheduler Scheduler.
	 * @param Logger          $logger    Logger.
	 * @param ActivityLog     $log       Activity log.
	 */
	public function __construct( FixEngine $engine, IssueRepository $repo, IssueRegistry $registry, Scheduler $scheduler, Logger $logger, ActivityLog $log ) {
		$this->engine    = $engine;
		$this->repo      = $repo;
		$this->registry  = $registry;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
		$this->log       = $log;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'process' ) );
		add_action( 'searchpilot_every_5min', array( $this, 'watchdog' ) );
		add_action( 'searchpilot_hourly', array( $this, 'schedule_verify' ), 60 );
		add_action( self::VERIFY_HOOK, array( $this, 'verify_pending' ) );
		add_action( self::UNDO_HOOK, array( $this, 'process_undo' ) );
		// A person undoing a fix declines it: automatic fixing will not re-apply it.
		add_action( 'searchpilot_activity_undone', array( $this->engine, 'on_undone' ) );
	}

	/**
	 * Hourly: finishes fixes whose verification is pending, in its own request.
	 */
	public function schedule_verify() {
		if ( $this->engine->pending_verifications() ) {
			$this->scheduler->enqueue_async( self::VERIFY_HOOK, array(), 20 );
		}
	}

	/**
	 * Background sweep of pending verifications (Lock + small batch).
	 *
	 * @return array|null Sweep stats (null when another sweep holds the lock).
	 */
	public function verify_pending() {
		return Lock::run(
			'fix_verify',
			600,
			function () {
				$stats = $this->engine->sweep_pending( new TimeBudget( 25 ), 5 );
				if ( $stats['verified'] || $stats['unverified'] || $stats['given_up'] || $stats['retried'] ) {
					$this->logger->info( 'automation', 'Pending fix verifications checked.', $stats );
				}
				return $stats;
			}
		);
	}

	/**
	 * Starts a run.
	 *
	 * @param array $items Items: array( issue_id, fingerprint ('' = apply whatever the planner
	 *                     proposes then; only for deterministic types), title ).
	 * @param array $args  user_id, source (user|assisted|autopilot), label, type.
	 * @return array|\WP_Error Run.
	 */
	public function start( array $items, array $args = array() ) {
		$clean = array();
		$seen  = array();
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			$id = (int) ( $item['issue_id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$clean[]     = array(
				'issue_id'    => $id,
				'fingerprint' => preg_match( '/^[a-f0-9]{40}$/', (string) ( $item['fingerprint'] ?? '' ) ) ? (string) $item['fingerprint'] : '',
				// What the person reviewed: change signatures and their (kind|object|field) keys.
				'reviewed'    => self::clean_list( $item['reviewed'] ?? array(), '/^[a-f0-9]{40}$/' ),
				'keys'        => self::clean_list( $item['keys'] ?? array(), '/^[a-z_]{1,20}\|\d{1,20}\|[^|]{0,255}$/' ),
				'title'       => substr( (string) ( $item['title'] ?? '' ), 0, 200 ),
				'status'      => 'pending',
				'message'     => '',
				'introduced'  => array(),
				'attempts'    => 0,
			);
		}
		if ( ! $clean ) {
			return new \WP_Error( 'searchpilot_invalid', __( 'Choose at least one fix to apply.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$run = array(
			'id'          => substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 ),
			'group_id'    => ActivityLog::new_group_id(),
			'status'      => 'queued',
			'user_id'     => (int) ( $args['user_id'] ?? get_current_user_id() ),
			'source'      => sanitize_key( (string) ( $args['source'] ?? 'user' ) ),
			'label'       => substr( (string) ( $args['label'] ?? '' ), 0, 200 ),
			'type'        => sanitize_key( (string) ( $args['type'] ?? '' ) ),
			'total'       => count( $clean ),
			'items'       => $clean,
			'touched'     => array(),
			'created_at'  => $now,
			'started_at'  => null,
			'updated_at'  => $now,
			'finished_at' => null,
			'undone_at'   => null,
			'heartbeat'   => time(),
		);
		$this->save( $run );
		$index = (array) get_option( self::INDEX_OPTION, array() );
		array_unshift( $index, $run['id'] );
		$index = array_values( array_unique( array_map( 'strval', $index ) ) );
		foreach ( array_slice( $index, self::KEEP_RUNS ) as $old ) {
			delete_option( self::RUN_OPTION . $old );
		}
		update_option( self::INDEX_OPTION, array_slice( $index, 0, self::KEEP_RUNS ), false );
		$this->scheduler->enqueue_async( self::HOOK, array( $run['id'] ), 0 );
		$this->logger->info(
			'automation',
			sprintf( 'Bulk fix run %s queued (%d items).', $run['id'], $run['total'] ),
			array( 'group' => $run['group_id'] )
		);
		return $run;
	}

	/**
	 * Background batch of a run.
	 *
	 * @param string $run_id Run id.
	 */
	public function process( $run_id ) {
		$run_id = self::clean_id( $run_id );
		if ( '' === $run_id ) {
			return;
		}
		Lock::run(
			'fixrun_' . $run_id,
			self::STALE_AFTER,
			function () use ( $run_id ) {
				$this->batch( $run_id, new TimeBudget( 25 ) );
			}
		);
	}

	/**
	 * Processes items until the budget is spent.
	 *
	 * @param string     $run_id Run id.
	 * @param TimeBudget $budget Budget.
	 * @return array|null Run after the batch.
	 */
	public function batch( $run_id, TimeBudget $budget ) {
		$run = $this->get( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return $run;
		}
		$previous_user = get_current_user_id();
		if ( $run['user_id'] ) {
			if ( ! get_userdata( $run['user_id'] ) ) {
				// The person who started the run was deleted: never continue with nobody's rights.
				foreach ( $run['items'] as $i => $item ) {
					if ( in_array( $item['status'], array( 'pending', 'running' ), true ) ) {
						$run['items'][ $i ]['status']  = 'skipped';
						$run['items'][ $i ]['message'] = __( 'The person who started this run no longer has an account.', 'searchpilot-pro' );
					}
				}
				$run['status']      = 'cancelled';
				$run['finished_at'] = current_time( 'mysql', true );
				$this->save( $run );
				return $run;
			}
			// Changes are attributed to (and permission-checked as) the person who started the run.
			wp_set_current_user( $run['user_id'] );
		}
		$run['status']     = 'running';
		$run['started_at'] = $run['started_at'] ?: current_time( 'mysql', true );
		$deferred          = false;
		$this->save( $run );
		try {
			foreach ( $run['items'] as $i => $item ) {
				if ( ! in_array( $item['status'], array( 'pending', 'running' ), true ) ) {
					continue;
				}
				if ( $this->cancel_requested( $run_id ) ) {
					$run['status'] = 'cancelled';
					break;
				}
				if ( $budget->exhausted( 8.0 ) ) {
					break;
				}
				if ( 'running' === $item['status'] && $item['attempts'] >= 2 ) {
					// Interrupted twice (fatal error or timeout while applying): stop retrying.
					$item['status']  = 'failed';
					$item['message'] = __( 'The fix was interrupted twice. Check Automation → Activity for partial changes.', 'searchpilot-pro' );
					$run['items'][ $i ] = $item;
					$this->save( $run );
					continue;
				}
				$item['status']   = 'running';
				$item['attempts'] = (int) $item['attempts'] + 1;
				$run['items'][ $i ] = $item;
				$this->save( $run );

				$outcome = $this->run_item( $run, $item );
				if ( ! empty( $outcome['defer'] ) ) {
					// Page checks are unavailable: nothing could verify or undo this fix. Leave the
					// item waiting and try the run again later.
					$item['status']     = 'pending';
					$item['attempts']   = max( 0, (int) $item['attempts'] - 1 );
					$item['message']    = $outcome['item']['message'];
					$run['items'][ $i ] = $item;
					$this->save( $run );
					$deferred = true;
					break;
				}
				$run['items'][ $i ] = array_merge( $item, $outcome['item'] );
				$run['touched']     = array_values( array_unique( array_merge( (array) $run['touched'], $outcome['touched'] ) ) );
				$this->save( $run );
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'automation', sprintf( 'Bulk fix run %s stopped: %s', $run_id, $e->getMessage() ) );
		} finally {
			wp_set_current_user( $previous_user );
		}

		$pending = 0;
		foreach ( $run['items'] as $item ) {
			if ( in_array( $item['status'], array( 'pending', 'running' ), true ) ) {
				++$pending;
			}
		}
		if ( 'cancelled' === $run['status'] || $this->cancel_requested( $run_id ) ) {
			$run['status'] = 'cancelled';
			foreach ( $run['items'] as $i => $item ) {
				if ( 'pending' === $item['status'] ) {
					$run['items'][ $i ]['status']  = 'skipped';
					$run['items'][ $i ]['message'] = __( 'Cancelled before it ran.', 'searchpilot-pro' );
				}
			}
			$run['finished_at'] = current_time( 'mysql', true );
			Lock::release( 'fixrun_cancel_' . $run_id );
		} elseif ( ! $pending ) {
			$run['status']      = 'done';
			$run['finished_at'] = current_time( 'mysql', true );
		}
		$this->save( $run );

		if ( 'running' === $run['status'] ) {
			$this->scheduler->enqueue_async( self::HOOK, array( $run_id ), $deferred ? self::DEFER_DELAY : 2 );
		} else {
			$counts = $this->counts( $run );
			$this->logger->info(
				'automation',
				sprintf( 'Bulk fix run %s %s: %d verified, %d applied, %d pending verification, %d undone, %d failed, %d skipped.', $run_id, $run['status'], $counts['verified'], $counts['applied'], $counts['pending_verification'], $counts['introduced'], $counts['failed'], $counts['skipped'] ),
				array( 'group' => $run['group_id'] )
			);
			/**
			 * Fires when a bulk fix run finished or was cancelled.
			 *
			 * @param array $run Run.
			 */
			do_action( 'searchpilot_fix_run_finished', $run );
		}
		return $run;
	}

	/**
	 * Applies one item.
	 *
	 * @param array $run  Run.
	 * @param array $item Item.
	 * @return array{item:array,touched:int[]}
	 */
	private function run_item( array $run, array $item ) {
		$done  = static function ( $status, $message, array $extra = array() ) {
			return array_merge(
				array(
					'status'  => $status,
					'message' => (string) $message,
				),
				$extra
			);
		};
		$issue = $this->repo->get( (int) $item['issue_id'] );
		if ( ! $issue ) {
			return array(
				'item'    => $done( 'skipped', __( 'The issue no longer exists.', 'searchpilot-pro' ) ),
				'touched' => array(),
			);
		}
		if ( ! in_array( $issue['status'], IssueRepository::OPEN_STATUSES, true ) ) {
			return array(
				'item'    => $done( 'skipped', __( 'Already fixed or resolved.', 'searchpilot-pro' ) ),
				'touched' => array(),
			);
		}
		if ( $run['user_id'] && in_array( $issue['object_type'], array( 'post', 'attachment' ), true ) && $issue['object_id'] && ! Capabilities::can_edit( (int) $issue['object_id'] ) ) {
			return array(
				'item'    => $done( 'failed', __( 'You cannot edit this content.', 'searchpilot-pro' ) ),
				'touched' => array(),
			);
		}
		$plan = $this->engine->plan_for( $issue );
		if ( is_wp_error( $plan ) ) {
			$skip = in_array( $plan->get_error_code(), array( 'searchpilot_plan_empty', 'searchpilot_fix_not_open' ), true );
			return array(
				'item'    => $done( $skip ? 'skipped' : 'failed', $plan->get_error_message() ),
				'touched' => array(),
			);
		}
		$targets = $this->engine->rescan_targets( $plan );
		if ( '' === $item['fingerprint'] ) {
			$def = $this->registry->get( $issue['type'] );
			if ( ! $def || 'deterministic' !== $this->registry->mode_for( $def, $issue ) ) {
				return array(
					'item'    => $done( 'skipped', __( 'This fix needs a review before it is applied.', 'searchpilot-pro' ) ),
					'touched' => array(),
				);
			}
		} elseif ( ! hash_equals( $item['fingerprint'], (string) $plan['fingerprint'] ) ) {
			// The plan differs from what was reviewed. Apply only reviewed changes: the same
			// change, or the same (kind, object, field) on an object an earlier item of this run
			// changed. Anything else was never shown to the person: skip the whole item.
			$check = Plan::reviewed_changes( $plan, (array) ( $item['reviewed'] ?? array() ), (array) ( $item['keys'] ?? array() ), (array) $run['touched'] );
			if ( ! $check['ok'] ) {
				$new     = array();
				$changed = array();
				$keys    = array_flip( (array) ( $item['keys'] ?? array() ) );
				foreach ( $check['unreviewed'] as $change ) {
					$name = 'option' === $change['kind'] ? (string) $change['field'] : html_entity_decode( (string) get_the_title( (int) $change['object_id'] ), ENT_QUOTES, 'UTF-8' );
					if ( isset( $keys[ Plan::change_key( $change ) ] ) ) {
						$changed[] = $name;
					} else {
						$new[] = $name;
					}
				}
				$new     = array_slice( array_values( array_unique( array_filter( $new ) ) ), 0, 3 );
				$changed = array_slice( array_values( array_unique( array_filter( $changed ) ) ), 0, 3 );
				if ( $new ) {
					/* translators: %s: titles of the pages the fix would also change. */
					$message = sprintf( __( 'This fix now also changes “%s”, which you did not review. Review it again.', 'searchpilot-pro' ), implode( '”, “', $new ) );
				} elseif ( $changed ) {
					/* translators: %s: titles of the pages that changed. */
					$message = sprintf( __( '“%s” changed after you reviewed this fix. Review it again.', 'searchpilot-pro' ), implode( '”, “', $changed ) );
				} else {
					$message = __( 'The page changed after you reviewed this fix. Review it again.', 'searchpilot-pro' );
				}
				return array(
					'item'    => $done( 'skipped', $message ),
					'touched' => array(),
				);
			}
			$plan    = Plan::subset( $plan, $check['signatures'] );
			$targets = $this->engine->rescan_targets( $plan );
		}
		foreach ( $plan['changes'] as $change ) {
			if ( ! empty( $change['object_id'] ) ) {
				$targets[] = (int) $change['object_id'];
			}
		}
		$targets = array_values( array_unique( $targets ) );
		// Background run: nobody is watching, so posts someone is editing are skipped.
		$result = $this->engine->apply( $plan, $run['source'], $run['group_id'], array( 'unattended' => true ) );
		if ( $result['introduced'] ) {
			return array(
				'item'    => $done( 'introduced', $result['error'], array( 'introduced' => $result['introduced'] ) ),
				'touched' => $targets,
			);
		}
		if ( 'searchpilot_fix_deferred' === $result['code'] ) {
			return array(
				'item'    => $done( 'pending', $result['error'] ),
				'touched' => array(),
				'defer'   => true,
			);
		}
		if ( ! $result['applied'] ) {
			$skip = in_array( $result['code'], array( 'searchpilot_fix_gone', 'searchpilot_fix_not_open', 'searchpilot_fix_locked', 'searchpilot_fix_stale' ), true );
			return array(
				'item'    => $done( $skip ? 'skipped' : 'failed', $result['error'] ),
				'touched' => array(),
			);
		}
		if ( null === $result['verified'] ) {
			$status = 'pending_verification';
		} else {
			$status = $result['verified'] ? 'verified' : 'applied';
		}
		return array(
			'item'    => $done( $status, $result['error'] ),
			'touched' => $targets,
		);
	}

	/**
	 * Watchdog: resumes runs whose last batch stopped without re-queuing.
	 */
	public function watchdog() {
		foreach ( (array) get_option( self::INDEX_OPTION, array() ) as $id ) {
			$run = $this->get( (string) $id );
			if ( ! $run ) {
				continue;
			}
			$undo = $run['undo'] ?? array();
			if ( $undo && in_array( $undo['status'] ?? '', array( 'queued', 'running' ), true ) && time() - (int) $undo['heartbeat'] > self::STALE_AFTER && ! Lock::held( 'fixrun_undo_' . $run['id'] ) ) {
				$this->scheduler->enqueue_async( self::UNDO_HOOK, array( $run['id'] ), 1 );
			}
			if ( ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
				continue;
			}
			if ( time() - (int) $run['heartbeat'] > self::STALE_AFTER && ! Lock::held( 'fixrun_' . $run['id'] ) ) {
				$this->scheduler->enqueue_async( self::HOOK, array( $run['id'] ), 1 );
			}
		}
	}

	/**
	 * Re-queues an active run whose batches stopped (called when its
	 * progress is read, in addition to the watchdog).
	 *
	 * @param array $run Run.
	 * @return bool Whether it was re-queued.
	 */
	public function nudge( array $run ) {
		if ( ! in_array( $run['status'], array( 'queued', 'running' ), true ) || time() - (int) $run['heartbeat'] < 60 || Lock::held( 'fixrun_' . $run['id'] ) ) {
			return false;
		}
		$this->scheduler->enqueue_async( self::HOOK, array( $run['id'] ), 0 );
		if ( 'wp_cron' === Scheduler::backend() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return true;
	}

	/**
	 * Requests cancellation of a run.
	 *
	 * @param string $run_id Run id.
	 * @return array|null Run.
	 */
	public function cancel( $run_id ) {
		$run = $this->get( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return $run;
		}
		Lock::acquire( 'fixrun_cancel_' . $run['id'], DAY_IN_SECONDS );
		if ( 'queued' === $run['status'] && ! Lock::held( 'fixrun_' . $run['id'] ) ) {
			$this->batch( $run['id'], new TimeBudget( 5 ) );
		}
		return $this->get( $run_id );
	}

	/**
	 * Queues "Undo this run": every applied change of the run's group is
	 * undone in the background (Lock, TimeBudget, resume), as the person who
	 * asked, with the same per-object permission checks as a single undo.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $user_id Requesting user.
	 * @return array|\WP_Error Run.
	 */
	public function start_undo( $run_id, $user_id ) {
		$run = $this->get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'This run no longer exists.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return new \WP_Error( 'searchpilot_busy', __( 'This run is still applying fixes. Cancel it first, then undo it.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $run['undo'] ) && in_array( $run['undo']['status'] ?? '', array( 'queued', 'running', 'done' ), true ) ) {
			return $run; // Already undone or being undone.
		}
		$ids = array();
		for ( $page = 1; $page <= 25; $page++ ) {
			$batch = $this->log->query(
				array(
					'group_id'   => (string) $run['group_id'],
					'status'     => 'applied',
					'reversible' => 1,
					'per_page'   => 200,
					'page'       => $page,
				)
			);
			foreach ( $batch['items'] as $row ) {
				$ids[] = (int) $row['id'];
			}
			if ( count( $batch['items'] ) < 200 ) {
				break;
			}
		}
		$run['undo'] = array(
			'status'       => 'queued',
			'user_id'      => (int) $user_id,
			'ids'          => $ids,
			'pos'          => 0,
			'undone'       => 0,
			'failed'       => 0,
			'errors'       => array(),
			'posts'        => array(),
			'requested_at' => current_time( 'mysql', true ),
			'heartbeat'    => time(),
		);
		$this->save( $run );
		$this->scheduler->enqueue_async( self::UNDO_HOOK, array( $run['id'] ), 0 );
		return $run;
	}

	/**
	 * Re-queues an undo whose batches stopped (called when progress is read).
	 *
	 * @param array $run Run.
	 * @return bool
	 */
	public function nudge_undo( array $run ) {
		$undo = $run['undo'] ?? array();
		if ( ! $undo || ! in_array( $undo['status'] ?? '', array( 'queued', 'running' ), true ) || time() - (int) $undo['heartbeat'] < 60 || Lock::held( 'fixrun_undo_' . $run['id'] ) ) {
			return false;
		}
		$this->scheduler->enqueue_async( self::UNDO_HOOK, array( $run['id'] ), 0 );
		if ( 'wp_cron' === Scheduler::backend() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return true;
	}

	/**
	 * Background batch of a run undo.
	 *
	 * @param string $run_id Run id.
	 */
	public function process_undo( $run_id ) {
		$run_id = self::clean_id( $run_id );
		if ( '' === $run_id ) {
			return;
		}
		Lock::run(
			'fixrun_undo_' . $run_id,
			self::STALE_AFTER,
			function () use ( $run_id ) {
				$this->undo_batch( $run_id, new TimeBudget( 25 ) );
			}
		);
	}

	/**
	 * Undoes entries of a run until the budget is spent.
	 *
	 * @param string     $run_id Run id.
	 * @param TimeBudget $budget Budget.
	 * @return array|null Run.
	 */
	public function undo_batch( $run_id, TimeBudget $budget ) {
		$run = $this->get( $run_id );
		if ( ! $run || empty( $run['undo'] ) || ! in_array( $run['undo']['status'], array( 'queued', 'running' ), true ) ) {
			return $run;
		}
		$undo     = $run['undo'];
		$previous = get_current_user_id();
		if ( ! $undo['user_id'] || ! get_userdata( $undo['user_id'] ) ) {
			$undo['status']   = 'done';
			$undo['errors'][] = __( 'The person who asked for the undo no longer has an account.', 'searchpilot-pro' );
			$run['undo']      = $undo;
			$this->save( $run );
			return $run;
		}
		wp_set_current_user( $undo['user_id'] );
		$undo['status'] = 'running';
		$count          = count( $undo['ids'] );
		try {
			while ( $undo['pos'] < $count && ! $budget->exhausted( 5.0 ) ) {
				$row = $this->log->get( (int) $undo['ids'][ $undo['pos'] ] );
				++$undo['pos'];
				if ( ! $row || 'applied' !== $row->status || ! $row->reversible ) {
					continue;
				}
				$is_post = in_array( $row->object_type, array( 'post', 'attachment' ), true ) && $row->object_id;
				$allowed = $is_post ? Capabilities::can_edit( (int) $row->object_id ) : Capabilities::can_manage();
				$result  = $allowed ? $this->log->undo( (int) $row->id ) : new \WP_Error( 'rest_forbidden', __( 'You are not allowed to undo one of these changes.', 'searchpilot-pro' ) );
				if ( is_wp_error( $result ) ) {
					++$undo['failed'];
					$undo['errors'] = array_slice( array_values( array_unique( array_merge( $undo['errors'], array( $result->get_error_message() ) ) ) ), 0, 10 );
				} else {
					++$undo['undone'];
					if ( 'post' === $row->object_type ) {
						$undo['posts'][] = (int) $row->object_id;
					}
				}
				if ( 0 === $undo['pos'] % 10 ) {
					$run['undo'] = $undo;
					$this->save( $run );
				}
			}
		} finally {
			wp_set_current_user( $previous );
		}
		$undo['heartbeat'] = time();
		if ( $undo['pos'] >= $count ) {
			$undo['status']   = 'done';
			$run['undone_at'] = current_time( 'mysql', true );
			$this->engine->queue_rescan( array_merge( (array) $run['touched'], $undo['posts'] ) );
			$this->logger->info( 'automation', sprintf( 'Bulk fix run %s undone: %d changes undone, %d could not be undone.', $run['id'], $undo['undone'], $undo['failed'] ), array( 'group' => $run['group_id'] ) );
		}
		$run['undo'] = $undo;
		$this->save( $run );
		if ( 'running' === $undo['status'] ) {
			$this->scheduler->enqueue_async( self::UNDO_HOOK, array( $run['id'] ), 1 );
		}
		return $run;
	}

	/**
	 * A run.
	 *
	 * @param string $run_id Run id.
	 * @return array|null
	 */
	public function get( $run_id ) {
		$run_id = self::clean_id( $run_id );
		if ( '' === $run_id ) {
			return null;
		}
		wp_cache_delete( self::RUN_OPTION . $run_id, 'options' );
		$run = get_option( self::RUN_OPTION . $run_id, null );
		return is_array( $run ) && isset( $run['id'], $run['items'] ) ? $run : null;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $user_id Only runs of this user (0 = all).
	 * @return array[]
	 */
	public function runs( $user_id = 0 ) {
		$out = array();
		foreach ( (array) get_option( self::INDEX_OPTION, array() ) as $id ) {
			$run = $this->get( (string) $id );
			if ( $run && ( ! $user_id || (int) $run['user_id'] === (int) $user_id ) ) {
				$out[] = $run;
			}
		}
		return $out;
	}

	/**
	 * Run for the API.
	 *
	 * @param array $run          Run.
	 * @param bool  $with_items   Include items.
	 * @return array
	 */
	public function present( array $run, $with_items = true ) {
		$counts = $this->counts( $run );
		$total  = max( 1, (int) $run['total'] );
		$out    = array(
			'id'          => $run['id'],
			'group_id'    => $run['group_id'],
			'status'      => $run['status'],
			'label'       => $run['label'],
			'type'        => $run['type'],
			'source'      => $run['source'],
			'total'       => (int) $run['total'],
			'done'        => $counts['done'],
			'percent'     => (int) floor( ( $counts['done'] / $total ) * 100 ),
			'counts'      => $counts,
			'active'      => in_array( $run['status'], array( 'queued', 'running' ), true ),
			'undoable'    => ! $run['undone_at'] && empty( $run['undo'] ) && ( $counts['verified'] + $counts['applied'] + $counts['pending_verification'] ) > 0 && ! in_array( $run['status'], array( 'queued', 'running' ), true ),
			'undo'        => empty( $run['undo'] ) ? null : array(
				'status' => (string) $run['undo']['status'],
				'total'  => count( (array) $run['undo']['ids'] ),
				'done'   => (int) $run['undo']['pos'],
				'undone' => (int) $run['undo']['undone'],
				'failed' => (int) $run['undo']['failed'],
				'errors' => array_values( (array) $run['undo']['errors'] ),
				'active' => in_array( $run['undo']['status'], array( 'queued', 'running' ), true ),
			),
			'created_at'  => mysql_to_rfc3339( $run['created_at'] ),
			'started_at'  => $run['started_at'] ? mysql_to_rfc3339( $run['started_at'] ) : null,
			'updated_at'  => mysql_to_rfc3339( $run['updated_at'] ),
			'finished_at' => $run['finished_at'] ? mysql_to_rfc3339( $run['finished_at'] ) : null,
			'undone_at'   => $run['undone_at'] ? mysql_to_rfc3339( $run['undone_at'] ) : null,
		);
		if ( $with_items ) {
			$out['items'] = array_map(
				static function ( $item ) {
					return array(
						'issue_id'   => (int) $item['issue_id'],
						'title'      => (string) $item['title'],
						'status'     => (string) $item['status'],
						'message'    => (string) $item['message'],
						'introduced' => array_values( (array) $item['introduced'] ),
					);
				},
				$run['items']
			);
		}
		return $out;
	}

	/**
	 * Item counts.
	 *
	 * @param array $run Run.
	 * @return array{done:int,pending:int,verified:int,applied:int,pending_verification:int,introduced:int,failed:int,skipped:int}
	 */
	public function counts( array $run ) {
		$out = array(
			'done'                 => 0,
			'pending'              => 0,
			'verified'             => 0,
			'applied'              => 0,
			'pending_verification' => 0,
			'introduced'           => 0,
			'failed'               => 0,
			'skipped'              => 0,
		);
		foreach ( $run['items'] as $item ) {
			$status = (string) $item['status'];
			if ( in_array( $status, array( 'pending', 'running' ), true ) ) {
				++$out['pending'];
				continue;
			}
			++$out['done'];
			if ( isset( $out[ $status ] ) ) {
				++$out[ $status ];
			}
		}
		return $out;
	}

	/**
	 * Saves a run (heartbeat refreshed).
	 *
	 * @param array $run Run.
	 */
	private function save( array $run ) {
		$run['updated_at'] = current_time( 'mysql', true );
		$run['heartbeat']  = time();
		update_option( self::RUN_OPTION . $run['id'], $run, false );
	}

	/**
	 * Whether cancellation was requested.
	 *
	 * @param string $run_id Run id.
	 * @return bool
	 */
	private function cancel_requested( $run_id ) {
		return Lock::held( 'fixrun_cancel_' . $run_id );
	}

	/**
	 * Strings of a list that match a pattern (at most 100).
	 *
	 * @param mixed  $values  Values.
	 * @param string $pattern Regex.
	 * @return string[]
	 */
	private static function clean_list( $values, $pattern ) {
		$out = array();
		foreach ( array_slice( (array) $values, 0, 100 ) as $value ) {
			if ( is_scalar( $value ) && preg_match( $pattern, (string) $value ) ) {
				$out[] = (string) $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitised run id.
	 *
	 * @param mixed $run_id Run id.
	 * @return string
	 */
	public static function clean_id( $run_id ) {
		return substr( (string) preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $run_id ) ), 0, 32 );
	}
}
