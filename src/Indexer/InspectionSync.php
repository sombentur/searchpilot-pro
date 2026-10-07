<?php
/**
 * Search Console URL Inspection sync.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Core\Http\RateLimiter;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects registry URLs with the official URL Inspection API within the
 * daily budget (spread over the day: about budget/24 per hourly run, at
 * most 60), maps Google's coverage state to an index status and keeps the
 * latest full result per URL for the details view.
 */
class InspectionSync {

	const LAST_RUN    = 'inspection_last_run';
	const RESULT_TTL  = 7 * DAY_IN_SECONDS;
	const MAX_PER_RUN = 60;
	const LIMIT_KEY   = 'google_inspection';

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * State.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Issue detector.
	 *
	 * @var IssueDetector
	 */
	private $detector;

	/**
	 * Constructor.
	 *
	 * @param UrlRepository $urls     URLs.
	 * @param RateLimiter   $limiter  Rate limiter.
	 * @param Settings      $settings Settings.
	 * @param State         $state    State.
	 * @param Logger        $logger   Logger.
	 * @param IssueDetector $detector Detector.
	 */
	public function __construct( UrlRepository $urls, RateLimiter $limiter, Settings $settings, State $state, Logger $logger, IssueDetector $detector ) {
		$this->urls     = $urls;
		$this->limiter  = $limiter;
		$this->settings = $settings;
		$this->state    = $state;
		$this->logger   = $logger;
		$this->detector = $detector;
	}

	/**
	 * Search Console client when it is connected, else null.
	 *
	 * @return object|null
	 */
	public function client() {
		$class = '\SearchPilot\Api\Google\SearchConsole';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		try {
			$client = \SearchPilot\Plugin::instance()->get( $class );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_object( $client ) || ! method_exists( $client, 'inspect_url' ) || ! method_exists( $client, 'is_connected' ) ) {
			return null;
		}
		return $client->is_connected() ? $client : null;
	}

	/**
	 * Whether inspections can run.
	 *
	 * @return bool
	 */
	public function available() {
		return null !== $this->client();
	}

	/**
	 * Inspections per hourly run.
	 *
	 * @return int
	 */
	public function per_run() {
		$budget = (int) $this->settings->get( 'indexing.inspection_daily_budget', 400 );
		return $budget > 0 ? min( self::MAX_PER_RUN, max( 1, (int) ceil( $budget / 24 ) ) ) : 0;
	}

	/**
	 * Daily quota snapshot.
	 *
	 * @return array{limit:int,used:int,remaining:int,blocked_until:string|null,per_run:int}
	 */
	public function quota() {
		$limit     = (int) ( $this->limiter->limits()[ self::LIMIT_KEY ]['per_day'] ?? 0 );
		$used      = $this->limiter->used_today( self::LIMIT_KEY );
		$remaining = $this->limiter->remaining_today( self::LIMIT_KEY );
		$blocked   = $this->limiter->blocked_until( self::LIMIT_KEY );
		return array(
			'limit'         => $limit,
			'used'          => $used,
			'remaining'     => PHP_INT_MAX === $remaining ? $limit : $remaining,
			'blocked_until' => $blocked ? gmdate( 'c', $blocked ) : null,
			'per_run'       => $this->per_run(),
		);
	}

	/**
	 * Hourly run.
	 *
	 * @param int $seconds Time budget.
	 * @return array{status:string,inspected:int,errors:int}
	 */
	public function run( $seconds = 25 ) {
		$client = $this->client();
		if ( ! $client ) {
			return $this->finish( 'not_connected', 0, 0 );
		}
		$per_run = $this->per_run();
		if ( $per_run <= 0 ) {
			return $this->finish( 'disabled', 0, 0 );
		}
		$quota = $this->quota();
		$limit = min( $per_run, (int) $quota['remaining'] );
		if ( $limit <= 0 || $quota['blocked_until'] ) {
			return $this->finish( 'quota', 0, 0 );
		}
		$result = Lock::run(
			'inspection',
			300,
			function () use ( $seconds, $limit ) {
				$budget      = new TimeBudget( (int) $seconds );
				$inspected   = 0;
				$errors      = 0;
				$consecutive = 0;
				$status      = 'ok';
				foreach ( $this->urls->due_for_inspection( $limit ) as $row ) {
					if ( $budget->exhausted( 3 ) ) {
						$status = 'budget';
						break;
					}
					$r = $this->inspect_row( $row );
					if ( is_wp_error( $r ) ) {
						if ( 'searchpilot_quota' === $r->get_error_code() ) {
							$status = 'quota';
							break;
						}
						++$errors;
						++$consecutive;
						if ( $consecutive >= 3 ) {
							$status = 'errors';
							break;
						}
						continue;
					}
					$consecutive = 0;
					++$inspected;
				}
				return $this->finish( $status, $inspected, $errors );
			}
		);
		return null === $result ? $this->finish( 'busy', 0, 0, false ) : $result;
	}

	/**
	 * Inspects one registry row now (counts against the daily budget).
	 *
	 * @param array $row Hydrated row.
	 * @return array{row:array,result:array,mapped:array,issues:string[]}|\WP_Error
	 */
	public function inspect_row( array $row ) {
		$client = $this->client();
		if ( ! $client ) {
			return new \WP_Error( 'searchpilot_not_connected', __( 'Connect Google Search Console (SearchPilot → Search Engines) to inspect URLs.', 'searchpilot-pro' ), array( 'status' => 409 ) );
		}
		try {
			$result = $client->inspect_url( (string) $row['url'] );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'searchpilot_inspection_failed', __( 'The URL Inspection request failed.', 'searchpilot-pro' ) );
			$this->logger->error( 'google', 'URL Inspection threw: ' . $e->getMessage(), array( 'url' => $row['url'] ) );
		}
		if ( is_wp_error( $result ) ) {
			if ( 'searchpilot_quota' !== $result->get_error_code() ) {
				$this->logger->warning( 'google', 'URL Inspection failed: ' . $result->get_error_message(), array( 'url' => $row['url'] ) );
			}
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'searchpilot_inspection_invalid', __( 'Search Console returned an unexpected answer.', 'searchpilot-pro' ) );
		}

		$mapped = StatusMapper::map( $result );
		$update = array(
			'index_status'     => $mapped['index_status'],
			'coverage_state'   => $mapped['coverage_state'],
			'verdict'          => $mapped['verdict'],
			'google_canonical' => '' !== $mapped['google_canonical'] ? mb_substr( $mapped['google_canonical'], 0, 2000 ) : null,
			'last_inspected'   => current_time( 'mysql', true ),
		);
		if ( null !== $mapped['last_crawled'] ) {
			$update['last_crawled'] = $mapped['last_crawled'];
		}
		$this->urls->update( (int) $row['id'], $update );

		$stored = self::storable( $result );
		$stored['mapped']       = $mapped;
		$stored['inspected_at'] = gmdate( 'c' );
		set_transient( 'searchpilot_inspection_' . (int) $row['id'], $stored, self::RESULT_TTL );

		$fresh    = $this->urls->get( (int) $row['id'] );
		$detected = $fresh ? $this->detector->detect( $fresh, null, $stored ) : array( 'types' => array() );
		return array(
			'row'    => $fresh ? $this->urls->get( (int) $row['id'] ) : $row,
			'result' => $stored,
			'mapped' => $mapped,
			'issues' => $detected['types'],
		);
	}

	/**
	 * Latest stored inspection of a row (null when none within 7 days).
	 *
	 * @param int $row_id Row id.
	 * @return array|null
	 */
	public function stored_result( $row_id ) {
		$stored = get_transient( 'searchpilot_inspection_' . (int) $row_id );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Keeps the normalized fields of a result (drops the raw API payload).
	 *
	 * @param array $result Normalized result.
	 * @return array
	 */
	public static function storable( array $result ) {
		$keys = array( 'verdict', 'coverage_state', 'indexing_state', 'robots_txt_state', 'page_fetch_state', 'last_crawl_time', 'google_canonical', 'user_canonical', 'crawled_as', 'sitemaps', 'referring_urls', 'rich_results', 'inspection_link' );
		$out  = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$out[ $key ] = $result[ $key ];
			}
		}
		$out['sitemaps']       = array_slice( (array) ( $out['sitemaps'] ?? array() ), 0, 20 );
		$out['referring_urls'] = array_slice( (array) ( $out['referring_urls'] ?? array() ), 0, 20 );
		return $out;
	}

	/**
	 * Records a run.
	 *
	 * @param string $status    Status.
	 * @param int    $inspected Inspected.
	 * @param int    $errors    Errors.
	 * @param bool   $save      Store as last run.
	 * @return array
	 */
	private function finish( $status, $inspected, $errors, $save = true ) {
		$run = array(
			'status'    => $status,
			'inspected' => (int) $inspected,
			'errors'    => (int) $errors,
			'at'        => gmdate( 'c' ),
		);
		if ( $save ) {
			$this->state->set( self::LAST_RUN, $run );
		}
		if ( $inspected || $errors ) {
			$this->logger->info( 'google', sprintf( 'URL Inspection: %d inspected, %d errors (%s).', $inspected, $errors, $status ) );
		}
		return $run;
	}
}
