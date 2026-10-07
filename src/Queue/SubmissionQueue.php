<?php
/**
 * The only way to notify search engines.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

use SearchPilot\Api\Bing\BingWebmaster;
use SearchPilot\Api\Google\IndexingApi;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\IndexNow\IndexNow;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which services a URL goes to, prevents duplicates and applies
 * the environment guard and the automation policy:
 *
 *   - non-production / local / unfinished setup → `held` (hold reason stored)
 *   - policy "approval" (Manual mode)             → `held` for one-click release
 *   - policy "off"                                → `skipped`
 *   - otherwise                                   → `pending` for the worker
 *
 * `user_initiated` skips the approval hold, never the environment guard.
 * Rows are deduplicated by sha1(service|action|normalized URL): an open
 * row is refreshed instead of duplicated, and a URL sent successfully
 * within `indexing.resubmit_cooldown_minutes` is not sent again unless
 * `force` is set.
 *
 * Priority: higher values are processed first (manual 30, publish 20,
 * removals/redirects 15, updates 10).
 */
class SubmissionQueue {

	const URL_SERVICES = array( 'indexnow', 'bing_url', 'google_indexing' );

	const SITEMAP_SERVICES = array( 'gsc_sitemap', 'bing_sitemap' );

	const PROCESS_HOOK = 'searchpilot_process_queue';

	/**
	 * Hold reasons caused by the environment guard (never released while they apply).
	 */
	const GUARD_HOLDS = array( 'local', 'setup', 'discouraged', 'moved', 'environment' );

	/**
	 * Repository.
	 *
	 * @var QueueRepository
	 */
	private $repo;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Environment.
	 *
	 * @var Environment
	 */
	private $env;

	/**
	 * Automation policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * IndexNow.
	 *
	 * @var IndexNow
	 */
	private $indexnow;

	/**
	 * Bing.
	 *
	 * @var BingWebmaster
	 */
	private $bing;

	/**
	 * Indexing API.
	 *
	 * @var IndexingApi
	 */
	private $indexing;

	/**
	 * Search Console.
	 *
	 * @var SearchConsole
	 */
	private $gsc;

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
	 * Whether a background run was requested in this request.
	 *
	 * @var bool
	 */
	private $kicked = false;

	/**
	 * Constructor.
	 *
	 * @param QueueRepository  $repo      Repository.
	 * @param Settings         $settings  Settings.
	 * @param Environment      $env       Environment.
	 * @param AutomationPolicy $policy    Policy.
	 * @param IndexNow         $indexnow  IndexNow.
	 * @param BingWebmaster    $bing      Bing.
	 * @param IndexingApi      $indexing  Indexing API.
	 * @param SearchConsole    $gsc       Search Console.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( QueueRepository $repo, Settings $settings, Environment $env, AutomationPolicy $policy, IndexNow $indexnow, BingWebmaster $bing, IndexingApi $indexing, SearchConsole $gsc, Scheduler $scheduler, Logger $logger ) {
		$this->repo      = $repo;
		$this->settings  = $settings;
		$this->env       = $env;
		$this->policy    = $policy;
		$this->indexnow  = $indexnow;
		$this->bing      = $bing;
		$this->indexing  = $indexing;
		$this->gsc       = $gsc;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	/**
	 * Repository.
	 *
	 * @return QueueRepository
	 */
	public function repository() {
		return $this->repo;
	}

	/**
	 * Deduplication key.
	 *
	 * @param string $service Service.
	 * @param string $action  update|delete.
	 * @param string $url     URL.
	 * @return string 40 hex chars.
	 */
	public static function dedupe_key( $service, $action, $url ) {
		return sha1( (string) $service . '|' . (string) $action . '|' . Url::normalize( (string) $url ) );
	}

	/**
	 * Default priority for an event reason (higher = sooner).
	 *
	 * @param string $reason         Reason.
	 * @param bool   $user_initiated User initiated.
	 * @return int
	 */
	public static function default_priority( $reason, $user_initiated = false ) {
		if ( $user_initiated || 'manual' === $reason ) {
			return 30;
		}
		switch ( (string) $reason ) {
			case 'publish':
				return 20;
			case 'delete':
			case 'unpublish':
			case 'redirect':
			case 'noindex':
				return 15;
			case 'backfill':
				return 5;
		}
		return 10;
	}

	/**
	 * Why submissions are blocked right now ('' when allowed).
	 *
	 * @return string local|setup|discouraged|moved|environment|''
	 */
	public function block_reason() {
		if ( method_exists( $this->env, 'submission_block_reason' ) ) {
			return (string) $this->env->submission_block_reason();
		}
		return $this->env->submissions_allowed() ? '' : 'environment';
	}

	/**
	 * Human explanation of a hold reason.
	 *
	 * @param string $hold local|setup|environment|approval.
	 * @return string
	 */
	public function hold_message( $hold ) {
		if ( 'approval' === $hold ) {
			return __( 'Waiting for your approval (Manual mode, or “Notify search engines” is set to ask first). Release it to send.', 'searchpilot-pro' );
		}
		if ( $hold === $this->block_reason() && method_exists( $this->env, 'submission_block_message' ) ) {
			return (string) $this->env->submission_block_message();
		}
		switch ( $hold ) {
			case 'local':
				return __( 'This site has a local or private address, so nothing is sent to search engines.', 'searchpilot-pro' );
			case 'setup':
				return __( 'Submissions start once the setup wizard is finished.', 'searchpilot-pro' );
			case 'discouraged':
				return __( 'Settings → Reading → “Discourage search engines” is on, so nothing is sent to search engines.', 'searchpilot-pro' );
			case 'moved':
				return __( 'This site’s address changed since setup was finished, so submissions are held until the setup wizard is finished again on this address.', 'searchpilot-pro' );
		}
		return __( 'This is not the production site, so submissions are held (Settings → General → Environment guard).', 'searchpilot-pro' );
	}

	/**
	 * Decision for a new row.
	 *
	 * @param string $task           AutomationPolicy task.
	 * @param bool   $user_initiated User initiated.
	 * @return array{status:string,hold:string}
	 */
	public function decide( $task, $user_initiated ) {
		$policy = $user_initiated ? AutomationPolicy::AUTO : $this->policy->policy( $task );
		if ( AutomationPolicy::OFF === $policy ) {
			return array(
				'status' => 'skipped',
				'hold'   => '',
			);
		}
		$block = $this->block_reason();
		if ( '' !== $block ) {
			return array(
				'status' => 'held',
				'hold'   => $block,
			);
		}
		if ( AutomationPolicy::APPROVAL === $policy ) {
			return array(
				'status' => 'held',
				'hold'   => 'approval',
			);
		}
		return array(
			'status' => 'pending',
			'hold'   => '',
		);
	}

	/**
	 * Services a URL would be sent to right now.
	 *
	 * @param string $url     URL.
	 * @param string $action  update|delete.
	 * @param array  $context Context (object_type, object_id, services).
	 * @return string[]
	 */
	public function services_for_url( $url, $action = 'update', array $context = array() ) {
		$services = array();
		if ( $this->indexnow->is_active() ) {
			$services[] = 'indexnow';
		}
		if ( $this->settings->get( 'indexing.bing_url_submission', false ) && $this->bing->is_connected() ) {
			$services[] = 'bing_url';
		}
		$object_type = (string) ( $context['object_type'] ?? '' );
		$object_id   = (int) ( $context['object_id'] ?? 0 );
		if ( 'post' === $object_type && $object_id && $this->indexing->is_enabled() && $this->indexing->is_candidate( $object_id ) ) {
			$services[] = 'google_indexing';
		}
		if ( ! empty( $context['services'] ) && is_array( $context['services'] ) ) {
			$services = array_values( array_intersect( $services, $context['services'] ) );
		}
		return $services;
	}

	/**
	 * Queues a URL notification.
	 *
	 * @param string $url     URL of this site.
	 * @param string $action  update|delete.
	 * @param array  $context object_type, object_id, reason, priority, services, force, user_initiated.
	 * @return array<string,int> service => queue id (0 = not queued: cool-down, skipped or not applicable).
	 */
	public function enqueue_url( $url, $action = 'update', array $context = array() ) {
		$url = Url::normalize( esc_url_raw( (string) $url ) );
		if ( '' === $url || ! Url::is_internal( $url ) ) {
			return array();
		}
		$action   = 'delete' === $action ? 'delete' : 'update';
		$services = $this->services_for_url( $url, $action, $context );
		if ( empty( $services ) ) {
			return array();
		}
		$user     = ! empty( $context['user_initiated'] );
		$reason   = sanitize_key( (string) ( $context['reason'] ?? ( $user ? 'manual' : $action ) ) );
		$priority = isset( $context['priority'] ) ? (int) $context['priority'] : self::default_priority( $reason, $user );
		$decision = $this->decide( AutomationPolicy::TASK_SUBMIT_URLS, $user );
		$cooldown = max( 1, (int) $this->settings->get( 'indexing.resubmit_cooldown_minutes', 60 ) ) * MINUTE_IN_SECONDS;

		$out = array();
		foreach ( $services as $service ) {
			$out[ $service ] = $this->enqueue_one(
				$service,
				$url,
				$action,
				$decision,
				array(
					'object_type' => substr( sanitize_key( (string) ( $context['object_type'] ?? '' ) ), 0, 20 ),
					'object_id'   => (int) ( $context['object_id'] ?? 0 ),
					'reason'      => $reason,
					'priority'    => $priority,
				),
				! empty( $context['force'] ) ? 0 : $cooldown
			);
		}
		return $out;
	}

	/**
	 * Queues a sitemap submission for Search Console and Bing.
	 *
	 * @param string $sitemap_url    Sitemap URL on this site.
	 * @param string $reason         Reason.
	 * @param bool   $user_initiated User initiated (skips approval and the throttle).
	 * @return array<string,int> service => queue id.
	 */
	public function enqueue_sitemap( $sitemap_url, $reason = 'sitemap_change', $user_initiated = false ) {
		$url = Url::normalize( esc_url_raw( (string) $sitemap_url ) );
		if ( '' === $url || ! Url::is_internal( $url ) ) {
			return array();
		}
		if ( ! $user_initiated && 'sitemap_change' === $reason && ! $this->settings->get( 'sitemap.submit_on_change', true ) ) {
			return array();
		}
		$services = array();
		if ( $this->gsc->is_connected() ) {
			$services[] = 'gsc_sitemap';
		}
		if ( $this->bing->is_connected() ) {
			$services[] = 'bing_sitemap';
		}
		if ( empty( $services ) ) {
			return array();
		}
		$decision = $this->decide( AutomationPolicy::TASK_SUBMIT_SITEMAPS, (bool) $user_initiated );
		$throttle = $user_initiated ? 5 * MINUTE_IN_SECONDS : max( 1, (int) $this->settings->get( 'sitemap.submit_throttle_hours', 6 ) ) * HOUR_IN_SECONDS;
		$reason   = sanitize_key( (string) $reason );
		$out      = array();
		foreach ( $services as $service ) {
			$out[ $service ] = $this->enqueue_one(
				$service,
				$url,
				'update',
				$decision,
				array(
					'object_type' => 'sitemap',
					'object_id'   => 0,
					'reason'      => $user_initiated ? 'manual' : $reason,
					'priority'    => $user_initiated ? 30 : 10,
				),
				$throttle
			);
		}
		return $out;
	}

	/**
	 * Inserts or refreshes one row.
	 *
	 * @param string $service  Service.
	 * @param string $url      Normalized URL.
	 * @param string $action   Action.
	 * @param array  $decision decide() result.
	 * @param array  $fields   object_type, object_id, reason, priority.
	 * @param int    $cooldown Seconds a successful submission suppresses a new one (0 = none).
	 * @return int Queue id (0 = not queued).
	 */
	private function enqueue_one( $service, $url, $action, array $decision, array $fields, $cooldown ) {
		$key = self::dedupe_key( $service, $action, $url );
		$now = QueueRepository::now();

		$this->repo->supersede( $url, $service, $action );

		$open = $this->repo->find_open( $key );
		if ( $open ) {
			$update = array(
				'priority' => max( (int) $open['priority'], (int) $fields['priority'] ),
				'reason'   => $fields['reason'],
			);
			if ( $fields['object_id'] ) {
				$update['object_type'] = $fields['object_type'];
				$update['object_id']   = $fields['object_id'];
			}
			if ( 'held' === $open['status'] && 'pending' === $decision['status'] ) {
				$update['status']       = 'pending';
				$update['last_error']   = null;
				$update['available_at'] = $now;
			} elseif ( 'pending' === $open['status'] && 'held' === $decision['status'] && 'approval' !== $decision['hold'] ) {
				// The environment guard always wins.
				$update['status']     = 'held';
				$update['last_error'] = QueueRepository::HOLD_PREFIX . $decision['hold'];
			} elseif ( 'held' === $open['status'] && 'held' === $decision['status'] ) {
				$update['last_error'] = QueueRepository::HOLD_PREFIX . $decision['hold'];
			} elseif ( 'pending' === $open['status'] ) {
				// Fresh content: send soon even if a retry was scheduled far out.
				$update['available_at'] = min( (string) $open['available_at'], $now );
			}
			$this->repo->update( (int) $open['id'], $update );
			if ( 'pending' === ( $update['status'] ?? $open['status'] ) ) {
				$this->kick();
			}
			return (int) $open['id'];
		}

		if ( $cooldown > 0 && $this->repo->find_recent( $key, 'done', QueueRepository::now( -$cooldown ) ) ) {
			return 0;
		}

		if ( 'skipped' === $decision['status'] ) {
			$recent = $this->repo->find_recent( $key, 'skipped', QueueRepository::now( -max( $cooldown, HOUR_IN_SECONDS ) ) );
			$note   = __( 'Not sent: “Notify search engines” is switched off in Automation.', 'searchpilot-pro' );
			if ( in_array( $service, self::SITEMAP_SERVICES, true ) ) {
				$note = __( 'Not sent: “Submit sitemaps” is switched off in Automation.', 'searchpilot-pro' );
			}
			if ( $recent ) {
				$this->repo->update(
					(int) $recent['id'],
					array(
						'completed_at' => $now,
						'reason'       => $fields['reason'],
					)
				);
			} else {
				$this->repo->insert(
					array_merge(
						$fields,
						array(
							'service'      => $service,
							'action'       => $action,
							'url'          => $url,
							'dedupe_key'   => $key,
							'status'       => 'skipped',
							'last_error'   => $note,
							'completed_at' => $now,
						)
					)
				);
			}
			return 0;
		}

		$id = $this->repo->insert(
			array_merge(
				$fields,
				array(
					'service'    => $service,
					'action'     => $action,
					'url'        => $url,
					'dedupe_key' => $key,
					'status'     => $decision['status'],
					'last_error' => 'held' === $decision['status'] ? QueueRepository::HOLD_PREFIX . $decision['hold'] : null,
				)
			)
		);
		if ( $id && 'pending' === $decision['status'] ) {
			$this->kick();
		}
		return $id;
	}

	/**
	 * Schedules a background worker run (once per request).
	 */
	public function kick() {
		if ( $this->kicked ) {
			return;
		}
		$this->kicked = true;
		$this->scheduler->enqueue_async( self::PROCESS_HOOK, array(), 15 );
	}

	/**
	 * Releases held rows (one click in Manual mode). Refused while the
	 * environment guard blocks submissions.
	 *
	 * @param int[] $ids Ids ([] = all).
	 * @return int|\WP_Error Rows released.
	 */
	public function release( array $ids = array() ) {
		$block = $this->block_reason();
		if ( '' !== $block ) {
			return new \WP_Error( 'searchpilot_submissions_held', $this->hold_message( $block ), array( 'status' => 409 ) );
		}
		$count = $this->repo->release( $ids );
		if ( $count ) {
			$this->logger->info( 'queue', sprintf( 'Released %d held submission(s).', $count ) );
			$this->kick();
		}
		return $count;
	}

	/**
	 * After the setup wizard: rows held only because setup was unfinished
	 * follow the automation policy now (Manual mode keeps them held for approval).
	 *
	 * @return int Rows moved to pending.
	 */
	public function release_after_setup() {
		return $this->reevaluate_held( 'setup' );
	}

	/**
	 * Re-applies the environment guard and the automation policy to held
	 * rows (after setup, a mode/task-policy change, the environment guard or
	 * "Discourage search engines" changing, and daily).
	 *
	 * While a guard applies nothing is released; held rows only get the
	 * current reason. Without a guard, rows of a previous site address are
	 * cancelled, user-initiated rows are released, and the rest follow the
	 * task policy: auto → pending, approval → held for approval, off → skipped.
	 *
	 * @param string $context Why (for the log): setup|settings|visibility|daily.
	 * @return int Rows moved to pending.
	 */
	public function reevaluate_held( $context = 'settings' ) {
		$now   = QueueRepository::now();
		$block = $this->block_reason();
		if ( '' !== $block ) {
			foreach ( array_merge( self::GUARD_HOLDS, array( 'approval' ) ) as $hold ) {
				if ( $hold !== $block ) {
					$this->repo->update_held( $hold, array(), array( 'last_error' => QueueRepository::HOLD_PREFIX . $block ) );
				}
			}
			return 0;
		}

		// URLs queued under a previous address (staging copy, migration) are never sent.
		$foreign = array();
		foreach ( $this->repo->held_rows( self::GUARD_HOLDS ) as $row ) {
			if ( ! Url::is_internal( (string) $row['url'] ) ) {
				$foreign[] = (int) $row['id'];
			}
		}
		if ( $foreign ) {
			$this->repo->update_many(
				$foreign,
				array(
					'status'       => 'cancelled',
					'last_error'   => __( 'Not sent: this URL belongs to a previous site address.', 'searchpilot-pro' ),
					'completed_at' => $now,
				),
				'held'
			);
		}

		$pending = array(
			'status'       => 'pending',
			'last_error'   => null,
			'available_at' => $now,
		);
		$skipped = array(
			'status'       => 'skipped',
			'last_error'   => __( 'Not sent: automation for this task is switched off.', 'searchpilot-pro' ),
			'completed_at' => $now,
		);
		$groups  = array(
			AutomationPolicy::TASK_SUBMIT_URLS     => self::URL_SERVICES,
			AutomationPolicy::TASK_SUBMIT_SITEMAPS => self::SITEMAP_SERVICES,
		);

		$released = 0;
		foreach ( array_merge( self::GUARD_HOLDS, array( 'approval' ) ) as $hold ) {
			if ( 'approval' !== $hold ) {
				// User-initiated submissions never needed approval.
				$released += $this->repo->update_held( $hold, array(), $pending, 'manual' );
			}
			foreach ( $groups as $task => $services ) {
				$policy = $this->policy->policy( $task );
				if ( AutomationPolicy::AUTO === $policy ) {
					$released += $this->repo->update_held( $hold, $services, $pending );
				} elseif ( AutomationPolicy::OFF === $policy ) {
					$this->repo->update_held( $hold, $services, $skipped );
				} elseif ( 'approval' !== $hold ) {
					$this->repo->update_held( $hold, $services, array( 'last_error' => QueueRepository::HOLD_PREFIX . 'approval' ) );
				}
			}
		}
		if ( $released ) {
			$this->logger->info( 'queue', sprintf( 'Held submissions re-evaluated (%1$s): %2$d released.', sanitize_key( $context ), $released ) );
			$this->kick();
		}
		return $released;
	}
}
