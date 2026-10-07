<?php
/**
 * Submission queue endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Queue\QueueRepository;
use SearchPilot\Queue\QueueWorker;
use SearchPilot\Queue\SubmissionQueue;

defined( 'ABSPATH' ) || exit;

/**
 * GET /queue, GET /queue/stats, POST /queue/process, POST /queue/release,
 * POST /queue/{id}/retry, POST /queue/{id}/cancel, POST /queue/retry-failed,
 * DELETE /queue/history.
 */
class QueueController extends Controller {

	/**
	 * Repository.
	 *
	 * @var QueueRepository
	 */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param QueueRepository $repo Repository.
	 */
	public function __construct( QueueRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/queue', 'GET', array( $this, 'list_items' ), 'view' );
		$this->route( '/queue/stats', 'GET', array( $this, 'stats' ), 'view' );
		$this->route( '/queue/process', 'POST', array( $this, 'process' ), 'manage' );
		$this->route( '/queue/release', 'POST', array( $this, 'release' ), 'manage' );
		$this->route( '/queue/(?P<id>\d+)/retry', 'POST', array( $this, 'retry' ), 'manage', $this->id_arg() );
		$this->route( '/queue/(?P<id>\d+)/cancel', 'POST', array( $this, 'cancel' ), 'manage', $this->id_arg() );
		$this->route( '/queue/retry-failed', 'POST', array( $this, 'retry_failed' ), 'manage' );
		$this->route( '/queue/history', 'DELETE', array( $this, 'delete_history' ), 'manage' );
	}

	/**
	 * Submission queue service.
	 *
	 * @return SubmissionQueue
	 */
	private function queue() {
		return $this->service( SubmissionQueue::class );
	}

	/**
	 * Comma list → sanitized keys.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function csv( $value ) {
		$value = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}

	/**
	 * GET /queue (service, status, search, hold, page, per_page, orderby, order).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function list_items( \WP_REST_Request $request ) {
		list( $page, $per_page ) = $this->paging( $request, 25 );
		$result                  = $this->repo->query(
			array(
				'service'  => self::csv( $request->get_param( 'service' ) ),
				'status'   => self::csv( $request->get_param( 'status' ) ),
				'hold'     => sanitize_key( (string) $request->get_param( 'hold' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => sanitize_key( (string) $request->get_param( 'order' ) ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$queue    = $this->queue();
		$messages = array();
		$items    = array();
		foreach ( $result['items'] as $item ) {
			if ( '' !== $item['hold_reason'] ) {
				if ( ! isset( $messages[ $item['hold_reason'] ] ) ) {
					$messages[ $item['hold_reason'] ] = $queue->hold_message( $item['hold_reason'] );
				}
				$item['hold_message'] = $messages[ $item['hold_reason'] ];
			}
			$items[] = $item;
		}
		return $this->paged( $items, $result['total'], $page, $per_page );
	}

	/**
	 * GET /queue/stats.
	 *
	 * @return array
	 */
	public function stats() {
		$queue  = $this->queue();
		$reason = $queue->block_reason();
		$stats  = $this->repo->stats();
		$holds  = array();
		foreach ( array_keys( $stats['held_by_reason'] ) as $hold ) {
			$holds[ $hold ] = $queue->hold_message( $hold );
		}
		/** Policy. @var AutomationPolicy $policy */
		$policy = $this->service( AutomationPolicy::class );
		return array_merge(
			$stats,
			array(
				'held_by_reason' => (object) $stats['held_by_reason'],
				'hold_messages'  => (object) $holds,
				'submissions'    => array(
					'allowed' => '' === $reason,
					'reason'  => $reason,
					'message' => '' !== $reason ? $queue->hold_message( $reason ) : '',
				),
				'mode'           => $policy->mode(),
				'policy'         => array(
					'submit_urls'     => $policy->policy( AutomationPolicy::TASK_SUBMIT_URLS ),
					'submit_sitemaps' => $policy->policy( AutomationPolicy::TASK_SUBMIT_SITEMAPS ),
				),
				'quotas'         => EnginesController::quota_rows( $this->service( RateLimiter::class ) ),
				'next_run'       => self::next_run(),
			)
		);
	}

	/**
	 * Next scheduled worker run (ISO) or null.
	 *
	 * @return string|null
	 */
	private static function next_run() {
		$times = array_filter(
			array(
				wp_next_scheduled( SubmissionQueue::PROCESS_HOOK ),
				wp_next_scheduled( 'searchpilot_every_5min' ),
			)
		);
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			foreach ( array( SubmissionQueue::PROCESS_HOOK, 'searchpilot_every_5min' ) as $hook ) {
				$next = as_next_scheduled_action( $hook, array(), 'searchpilot' );
				if ( is_int( $next ) ) {
					$times[] = $next;
				}
			}
		}
		return $times ? gmdate( 'c', (int) min( $times ) ) : null;
	}

	/**
	 * POST /queue/process — runs the worker now.
	 *
	 * @return array|\WP_Error
	 */
	public function process() {
		$result = $this->service( QueueWorker::class )->run( 20 );
		if ( null === $result ) {
			return $this->error( 'searchpilot_queue_busy', __( 'The queue is already being processed. Try again in a moment.', 'searchpilot-pro' ), 409 );
		}
		$reason = $this->queue()->block_reason();
		return array(
			'ran'     => true,
			'result'  => $result,
			'stats'   => $this->repo->stats(),
			'message' => '' !== $reason ? $this->queue()->hold_message( $reason ) : '',
		);
	}

	/**
	 * POST /queue/release {ids?}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function release( \WP_REST_Request $request ) {
		$ids    = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 1000 );
		$result = $this->queue()->release( $ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'released' => (int) $result,
			'stats'    => $this->repo->stats(),
		);
	}

	/**
	 * POST /queue/{id}/retry.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function retry( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->repo->raw( $id ) ) {
			return $this->error( 'searchpilot_not_found', __( 'Queue item not found.', 'searchpilot-pro' ), 404 );
		}
		$retried = $this->repo->retry( $id );
		if ( is_wp_error( $retried ) ) {
			return $retried;
		}
		$queue = $this->queue();
		if ( '' !== $queue->block_reason() ) {
			$this->repo->update(
				$id,
				array(
					'status'     => 'held',
					'last_error' => QueueRepository::HOLD_PREFIX . $queue->block_reason(),
				)
			);
		} else {
			$queue->kick();
		}
		return array( 'item' => $this->repo->get( $id ) );
	}

	/**
	 * POST /queue/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function cancel( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->repo->raw( $id ) ) {
			return $this->error( 'searchpilot_not_found', __( 'Queue item not found.', 'searchpilot-pro' ), 404 );
		}
		if ( ! $this->repo->cancel( $id ) ) {
			return $this->error( 'searchpilot_invalid', __( 'Only pending or held items can be cancelled.', 'searchpilot-pro' ), 409 );
		}
		return array( 'item' => $this->repo->get( $id ) );
	}

	/**
	 * POST /queue/retry-failed.
	 *
	 * @return array
	 */
	public function retry_failed() {
		$count = $this->repo->retry_failed();
		$queue = $this->queue();
		if ( $count && '' !== $queue->block_reason() ) {
			$this->repo->hold_pending( $queue->block_reason() );
		} elseif ( $count ) {
			$queue->kick();
		}
		return array(
			'retried' => $count,
			'stats'   => $this->repo->stats(),
		);
	}

	/**
	 * DELETE /queue/history.
	 *
	 * @return array
	 */
	public function delete_history() {
		return array(
			'deleted' => $this->repo->delete_history(),
			'stats'   => $this->repo->stats(),
		);
	}
}
