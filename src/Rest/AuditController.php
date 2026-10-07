<?php
/**
 * SEO Audit endpoints.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Rest;

use SearchPilot\Audit\Alerts;
use SearchPilot\Audit\Rescanner;
use SearchPilot\Audit\SiteMonitor;
use SearchPilot\Core\Lock;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Indexer\Crawler;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /audit, POST /audit/run, POST /audit/test-alert.
 */
class AuditController extends Controller {

	/**
	 * Issue categories shown on the SEO Audit screen.
	 */
	const CATEGORIES = array( 'indexing', 'technical', 'sitemap', 'content', 'links', 'schema', 'performance', 'security', 'opportunity' );

	/**
	 * Monitor.
	 *
	 * @var SiteMonitor
	 */
	private $monitor;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param SiteMonitor     $monitor Monitor.
	 * @param IssueRepository $issues  Issues.
	 */
	public function __construct( SiteMonitor $monitor, IssueRepository $issues ) {
		$this->monitor = $monitor;
		$this->issues  = $issues;
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		$this->route( '/audit', 'GET', array( $this, 'get_audit' ), 'view' );
		$this->route( '/audit/run', 'POST', array( $this, 'run' ), 'manage' );
		$this->route( '/audit/test-alert', 'POST', array( $this, 'test_alert' ), 'manage' );
	}

	/**
	 * GET /audit.
	 *
	 * @return array
	 */
	public function get_audit() {
		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$categories[ $category ] = $this->issues->counts( $category );
		}
		/** @var Crawler $crawler */
		$crawler = $this->service( Crawler::class );
		$paused  = $crawler->paused_until();
		$checks  = $this->monitor->checks();
		$passed  = 0;
		foreach ( $checks as $check ) {
			if ( 'passed' === $check['status'] ) {
				++$passed;
			}
		}
		return array(
			'checks'     => $checks,
			'passed'     => $passed,
			'score'      => $this->monitor->score(),
			'counts'     => $this->issues->counts( null, array( 'opportunity' ) ),
			'categories' => $categories,
			'runs'       => $this->monitor->runs(),
			'alerts'     => $this->service( Alerts::class )->status(),
			'crawl'      => array(
				'paused_until' => $paused ? gmdate( 'c', $paused ) : null,
				'last_run'     => $this->service( \SearchPilot\Core\State::class )->get( Crawler::LAST_RUN ),
			),
		);
	}

	/**
	 * POST /audit/run: all monitor checks, then the issue rows themselves are
	 * brought up to date — rows of deleted content retired, link checks re-run
	 * from the live link graph (the one the Internal Links screen shows), one
	 * crawl batch of due pages, and the content analysis of the posts whose
	 * content issues were seen longest ago (the rest is queued) — so "Seen …"
	 * times and states change with every audit.
	 *
	 * @return array|\WP_Error
	 */
	public function run() {
		// One budget for the whole request (monitor included), so the response comes back well
		// before proxy time-outs (Cloudflare gives up after 100 seconds); leftovers are queued.
		$budget = new TimeBudget( 55 );
		$ran    = Lock::run(
			'monitor',
			600,
			function () {
				return $this->monitor->run( 'full' );
			}
		);
		if ( null === $ran ) {
			return $this->error( 'searchpilot_busy', __( 'An audit is already running. Try again in a minute.', 'searchpilot-pro' ), 409 );
		}
		/** @var Rescanner $rescanner */
		$rescanner = $this->service( Rescanner::class );
		$refresh   = $rescanner->refresh_audit( $budget, true );
		$crawl     = is_array( $refresh['crawl'] ) ? $refresh['crawl'] : array(
			'status'  => 'skipped',
			'checked' => 0,
			'failed'  => 0,
			'issues'  => 0,
		);
		unset( $refresh['crawl'] );
		// The crawl and the re-checks may have changed issues: refresh the score.
		$this->monitor->update_score();
		return array_merge(
			$this->get_audit(),
			array(
				'crawl'   => $crawl,
				'refresh' => $refresh,
			)
		);
	}

	/**
	 * POST /audit/test-alert.
	 *
	 * @return array|\WP_Error
	 */
	public function test_alert() {
		return $this->service( Alerts::class )->send_test();
	}
}
