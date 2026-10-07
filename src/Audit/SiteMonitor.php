<?php
/**
 * Scheduled site-wide search visibility monitoring.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Audit;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Credentials;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\Crawler;
use SearchPilot\Indexer\PageInspector;
use SearchPilot\Indexer\RegistrySync;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Critical checks run hourly, the full set daily and on demand. Every
 * check is stored (including passed ones) for the SEO Audit screen and
 * raises or resolves a site-level issue. A 0–100 health score weighted by
 * open issue severities is kept for the dashboard.
 */
class SiteMonitor {

	const CHECKS = 'monitor_checks';
	const RUNS   = 'monitor_runs';
	const SCORE  = 'health_score';
	const INDEX  = 'index_history';

	/**
	 * Checks run hourly (the rest run daily).
	 */
	const CRITICAL = array( 'site_noindexed', 'home_noindex', 'robots_txt', 'robots_blocks_important', 'cron_not_running', 'credentials_undecryptable', 'mass_server_errors', 'gsc_connectivity', 'loopback' );

	/**
	 * Site-level issue type per check.
	 */
	const ISSUE_FOR = array(
		'site_noindexed'            => 'site_noindexed',
		'home_noindex'              => 'home_noindex',
		'robots_blocks_important'   => 'robots_blocks_important',
		'gsc_connectivity'          => 'gsc_connectivity',
		'indexing_decline'          => 'indexing_decline',
		'traffic_decline'           => 'traffic_decline',
		'canonical_broken'          => 'canonical_broken',
		'mass_server_errors'        => 'mass_server_errors',
		'schema_failures'           => 'schema_failures',
		'cron_not_running'          => 'cron_not_running',
		'credentials_undecryptable' => 'credentials_undecryptable',
		'attachment_pages'          => 'attachment_pages_enabled',
	);

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
	 * Environment.
	 *
	 * @var Environment
	 */
	private $env;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Crawler.
	 *
	 * @var Crawler
	 */
	private $crawler;

	/**
	 * Registry.
	 *
	 * @var RegistrySync
	 */
	private $registry;

	/**
	 * Inspector.
	 *
	 * @var PageInspector
	 */
	private $inspector;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Time budget of the current run (bounds HTTP timeouts), null outside run().
	 *
	 * @var TimeBudget|null
	 */
	private $budget = null;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings    Settings.
	 * @param State            $state       State.
	 * @param Environment      $env         Environment.
	 * @param IssueRepository  $issues      Issues.
	 * @param UrlRepository    $urls        URLs.
	 * @param Scheduler        $scheduler   Scheduler.
	 * @param Credentials      $credentials Credentials.
	 * @param Crawler          $crawler     Crawler.
	 * @param RegistrySync     $registry    Registry.
	 * @param PageInspector    $inspector   Inspector.
	 * @param ConflictDetector $conflicts   Conflicts.
	 * @param Logger           $logger      Logger.
	 */
	public function __construct( Settings $settings, State $state, Environment $env, IssueRepository $issues, UrlRepository $urls, Scheduler $scheduler, Credentials $credentials, Crawler $crawler, RegistrySync $registry, PageInspector $inspector, ConflictDetector $conflicts, Logger $logger ) {
		$this->settings    = $settings;
		$this->state       = $state;
		$this->env         = $env;
		$this->issues      = $issues;
		$this->urls        = $urls;
		$this->scheduler   = $scheduler;
		$this->credentials = $credentials;
		$this->crawler     = $crawler;
		$this->registry    = $registry;
		$this->inspector   = $inspector;
		$this->conflicts   = $conflicts;
		$this->logger      = $logger;
	}

	/* --------------------------------------------------------------------- *
	 * Issue types
	 * --------------------------------------------------------------------- */

	/**
	 * Registers the site-level issue types.
	 *
	 * @param IssueRegistry $r Registry.
	 */
	public function register_types( IssueRegistry $r ) {
		$reading = __( 'Settings → Reading', 'searchpilot-pro' );

		$r->register(
			'site_noindexed',
			array(
				'label'    => __( 'The whole site asks search engines not to index it', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'critical',
				/* translators: %s: settings screen. */
				'what'     => sprintf( __( '“Discourage search engines from indexing this site” is switched on in %s, on a production site.', 'searchpilot-pro' ), $reading ),
				'why'      => __( 'Every page tells search engines not to index it. The site disappears from search results within days.', 'searchpilot-pro' ),
				/* translators: %s: settings screen. */
				'fix'      => sprintf( __( 'Open %s and untick “Discourage search engines from indexing this site”, then save. This is usually left on by accident after a launch or migration.', 'searchpilot-pro' ), $reading ),
				'action'   => 'enable_indexing',
			)
		);

		$r->register(
			'home_noindex',
			array(
				'label'    => __( 'Home page is set to noindex', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$source = (string) ( $issue['data']['source'] ?? 'meta' );
					return 'header' === $source
						? __( 'The home page is served with an X-Robots-Tag: noindex HTTP header.', 'searchpilot-pro' )
						: __( 'The home page contains a robots meta tag with noindex.', 'searchpilot-pro' );
				},
				'why'      => __( 'The home page is usually the most linked and most visited page. Without it, search engines lose the main entry point to the whole site.', 'searchpilot-pro' ),
				'fix'      => __( 'Check the home page’s SEO settings, the SEO plugin’s home page and archive robots settings, the theme and server/CDN rules that add X-Robots-Tag headers. Re-run the audit afterwards.', 'searchpilot-pro' ),
				'action'   => 'remove_noindex',
			)
		);

		$r->register(
			'robots_blocks_important',
			array(
				'label'    => __( 'robots.txt blocks important pages or files', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$items = array();
					foreach ( (array) ( $issue['data']['blocked'] ?? array() ) as $b ) {
						$items[] = (string) ( $b['label'] ?? '' ) . ( ! empty( $b['rule'] ) ? ' (' . $b['rule'] . ')' : '' );
					}
					/* translators: %s: blocked items. */
					return sprintf( __( 'robots.txt blocks Googlebot from: %s.', 'searchpilot-pro' ), implode( '; ', array_slice( $items, 0, 8 ) ) );
				},
				'why'      => __( 'Blocked pages cannot be crawled. Blocked images, CSS or JavaScript stop Google from rendering pages correctly and keep images out of Google Images and Discover.', 'searchpilot-pro' ),
				'fix'      => __( 'Remove or narrow the Disallow rules that match these URLs (SearchPilot → Settings → robots.txt, or the physical robots.txt file). Never block /wp-content/uploads/, theme files or wp-includes scripts.', 'searchpilot-pro' ),
				'action'   => 'edit_robots_txt',
			)
		);

		$r->register(
			'gsc_connectivity',
			array(
				'label'    => __( 'Google Search Console connection is failing', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					/* translators: %s: error message. */
					return sprintf( __( 'SearchPilot was connected to Search Console, but requests now fail: %s', 'searchpilot-pro' ), (string) ( $issue['data']['error'] ?? '' ) );
				},
				'why'      => __( 'Without the connection SearchPilot cannot inspect URLs, read performance data or submit sitemaps to Google.', 'searchpilot-pro' ),
				'fix'      => __( 'Open SearchPilot → Search Engines and reconnect Google. If you use a service account, check it is still an owner or full user of the property.', 'searchpilot-pro' ),
				'action'   => 'reconnect_google',
			)
		);

		$r->register(
			'indexing_decline',
			array(
				'label'    => __( 'Indexed pages are declining', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					/* translators: 1: source, 2: previous count, 3: current count, 4: percent. */
					return sprintf( __( '%1$s: indexed pages fell from %2$s to %3$s in a week (−%4$s%%).', 'searchpilot-pro' ), (string) ( $d['source_label'] ?? '' ), number_format_i18n( (int) ( $d['previous'] ?? 0 ) ), number_format_i18n( (int) ( $d['current'] ?? 0 ) ), number_format_i18n( (float) ( $d['drop'] ?? 0 ), 1 ) );
				},
				'why'      => __( 'A sudden drop in indexed pages is often caused by an accidental noindex, robots.txt change, canonical problem or server errors — and is followed by a traffic drop.', 'searchpilot-pro' ),
				'fix'      => __( 'Review the Critical and High issues in this audit, the Indexing screen filtered by “Not indexed”, and recent changes in the activity log. Fix the cause, then notify search engines.', 'searchpilot-pro' ),
				'action'   => 'investigate_indexing',
			)
		);

		$r->register(
			'traffic_decline',
			array(
				'label'    => __( 'Google traffic dropped sharply', 'searchpilot-pro' ),
				'category' => 'performance',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					/* translators: 1: previous clicks, 2: current clicks, 3: percent. */
					return sprintf( __( 'Google clicks fell from %1$s to %2$s week over week (−%3$s%%), comparing the latest complete 7 days with the 7 days before.', 'searchpilot-pro' ), number_format_i18n( (int) ( $d['previous'] ?? 0 ) ), number_format_i18n( (int) ( $d['current'] ?? 0 ) ), number_format_i18n( (float) ( $d['drop'] ?? 0 ), 1 ) );
				},
				'why'      => __( 'A sudden fall in clicks usually has a technical cause (de-indexing, noindex, robots.txt, server errors) or follows a Google update. The sooner it is investigated, the less traffic is lost.', 'searchpilot-pro' ),
				'fix'      => __( 'Check this audit for new critical issues, compare top pages in Search Performance to find which pages lost clicks, and review recent site changes. SearchPilot never promises rankings, but technical causes can be fixed quickly.', 'searchpilot-pro' ),
				'action'   => 'investigate_traffic',
			)
		);

		$r->register(
			'canonical_broken',
			array(
				'label'    => __( 'Canonical tags are broken on many pages', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					/* translators: 1: pages with problems, 2: pages checked, 3: percent. */
					return sprintf( __( '%1$s of %2$s recently checked pages (%3$s%%) have conflicting canonicals or canonicals pointing to the wrong URL.', 'searchpilot-pro' ), number_format_i18n( (int) ( $d['bad'] ?? 0 ) ), number_format_i18n( (int) ( $d['total'] ?? 0 ) ), number_format_i18n( (float) ( $d['percent'] ?? 0 ), 1 ) );
				},
				'why'      => __( 'A site-wide canonical problem (theme, a second SEO plugin, a caching layer) can drop many pages from the index at once.', 'searchpilot-pro' ),
				'fix'      => __( 'Open the Indexing screen filtered by errors to see the affected pages, then switch canonical output off in every plugin or theme except one (SearchPilot → Settings → Feature ownership).', 'searchpilot-pro' ),
				'action'   => 'fix_canonical',
			)
		);

		$r->register(
			'mass_server_errors',
			array(
				'label'    => __( 'Many server errors', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					/* translators: 1: number of errors, 2: number of checks. */
					return sprintf( __( '%1$s of %2$s page checks in the last 24 hours returned a server error (5xx).', 'searchpilot-pro' ), number_format_i18n( (int) ( $d['errors'] ?? 0 ) ), number_format_i18n( (int) ( $d['total'] ?? 0 ) ) );
				},
				'why'      => __( 'Frequent server errors make Google reduce its crawl rate for the whole site and eventually drop failing pages.', 'searchpilot-pro' ),
				'fix'      => __( 'Check the hosting status, PHP error log, memory and execution time limits, and recently updated plugins. Consider a page cache and database optimisation if the server is overloaded.', 'searchpilot-pro' ),
				'action'   => 'fix_server_error',
			)
		);

		$r->register(
			'schema_failures',
			array(
				'label'    => __( 'Many structured data problems', 'searchpilot-pro' ),
				'category' => 'schema',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					/* translators: %s: number of issues. */
					return sprintf( __( '%s structured data (schema) issues are open.', 'searchpilot-pro' ), number_format_i18n( (int) ( $issue['data']['open'] ?? 0 ) ) );
				},
				'why'      => __( 'Invalid or duplicated structured data makes pages lose rich results (article, breadcrumb, FAQ, video) in Google.', 'searchpilot-pro' ),
				'fix'      => __( 'Open the Schema tab of this audit, fix the most common problem first (often two plugins printing the same schema) and re-check a few pages.', 'searchpilot-pro' ),
				'action'   => 'fix_schema',
			)
		);

		$r->register(
			'cron_not_running',
			array(
				'label'    => __( 'Background jobs are not running', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => __( 'SearchPilot’s hourly background job has not run for more than three hours.', 'searchpilot-pro' ),
				'why'      => __( 'Submissions, sitemap updates, page checks, inspections and alerts all run in the background. Nothing is sent to search engines while jobs are stuck.', 'searchpilot-pro' ),
				'fix'      => __( 'Make sure WP-Cron is not disabled in wp-config.php, or set up a server cron that calls wp-cron.php every 5 minutes. Hosting with page caching can also stop WP-Cron from being triggered.', 'searchpilot-pro' ),
				'action'   => 'fix_cron',
			)
		);

		$r->register(
			'credentials_undecryptable',
			array(
				'label'    => __( 'Stored API credentials cannot be read', 'searchpilot-pro' ),
				'category' => 'security',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					/* translators: %s: error message. */
					return sprintf( __( 'SearchPilot could not decrypt its stored credentials: %s', 'searchpilot-pro' ), (string) ( $issue['data']['error'] ?? '' ) );
				},
				'why'      => __( 'Search engine connections stop working until the credentials can be read again.', 'searchpilot-pro' ),
				'fix'      => __( 'This happens when the security keys in wp-config.php changed (for example after a migration). Restore the previous keys, or reconnect the search engines in SearchPilot → Search Engines.', 'searchpilot-pro' ),
				'action'   => 'reconnect',
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Running checks
	 * --------------------------------------------------------------------- */

	/**
	 * Runs checks and stores the results.
	 *
	 * @param string $scope critical|full.
	 * @return array{checks:array,score:array,runs:array}
	 */
	public function run( $scope = 'full' ) {
		$started      = current_time( 'mysql', true );
		$ids          = 'critical' === $scope ? self::CRITICAL : array_keys( $this->definitions() );
		$stored       = (array) $this->state->get( self::CHECKS, array() );
		$this->budget = new TimeBudget( 'critical' === $scope ? 20 : 45 );
		$skipped      = array();
		foreach ( $ids as $id ) {
			// Several hourly jobs share one request: stop cleanly and keep the
			// previous results of the remaining checks (they run next time).
			if ( $this->budget->exhausted( 6 ) ) {
				$skipped[] = $id;
				continue;
			}
			try {
				$check = $this->run_check( $id );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'audit', sprintf( 'Monitor check %s failed: %s', $id, $e->getMessage() ) );
				continue;
			}
			$stored[ $id ] = $check;
		}
		$this->budget = null;
		// Drop checks that no longer exist.
		$stored = array_intersect_key( $stored, $this->definitions() );
		$this->state->set( self::CHECKS, $stored );

		$fresh_ran = isset( $stored['sitemap_fresh'] ) && ! in_array( 'sitemap_fresh', $skipped, true ) && 'skipped' !== $stored['sitemap_fresh']['status'];
		if ( 'full' === $scope && $fresh_ran && $this->conflicts->owns( 'sitemap' ) ) {
			$this->issues->resolve_unseen( 'missing_from_sitemap', $started );
		}

		$runs = (array) $this->state->get( self::RUNS, array() );
		if ( ! $skipped ) {
			$runs[ $scope . '_at' ] = gmdate( 'c' );
			if ( 'full' === $scope ) {
				$runs['critical_at'] = $runs['full_at'];
			}
			unset( $runs['partial'], $runs['partial_at'] );
		} else {
			$runs['partial_at'] = gmdate( 'c' );
			$runs['partial']    = $skipped;
			$this->logger->info( 'audit', sprintf( 'Monitor run (%s) stopped early for time; %d checks keep their previous result.', $scope, count( $skipped ) ) );
		}
		$this->state->set( self::RUNS, $runs );
		$score = $this->update_score();
		return array(
			'checks' => $this->checks(),
			'score'  => $score,
			'runs'   => $runs,
		);
	}

	/**
	 * Check definitions (id => label).
	 *
	 * @return array<string,string>
	 */
	public function definitions() {
		return array(
			'site_noindexed'            => __( 'Search engine visibility', 'searchpilot-pro' ),
			'home_noindex'              => __( 'Home page can be indexed', 'searchpilot-pro' ),
			'robots_txt'                => __( 'robots.txt is reachable', 'searchpilot-pro' ),
			'robots_blocks_important'   => __( 'robots.txt allows important pages and files', 'searchpilot-pro' ),
			'sitemap'                   => __( 'XML sitemap', 'searchpilot-pro' ),
			'sitemap_fresh'             => __( 'Sitemap lists new articles', 'searchpilot-pro' ),
			'gsc_connectivity'          => __( 'Google Search Console connection', 'searchpilot-pro' ),
			'indexing_decline'          => __( 'Indexed pages are stable', 'searchpilot-pro' ),
			'traffic_decline'           => __( 'Google clicks are stable', 'searchpilot-pro' ),
			'canonical_broken'          => __( 'Canonical tags', 'searchpilot-pro' ),
			'mass_server_errors'        => __( 'Server errors', 'searchpilot-pro' ),
			'redirect_loops'            => __( 'Redirect loops', 'searchpilot-pro' ),
			'schema_failures'           => __( 'Structured data', 'searchpilot-pro' ),
			'cron_not_running'          => __( 'Background jobs', 'searchpilot-pro' ),
			'credentials_undecryptable' => __( 'Stored credentials', 'searchpilot-pro' ),
			'loopback'                  => __( 'Page self-checks (loopback)', 'searchpilot-pro' ),
			'attachment_pages'          => __( 'Attachment pages', 'searchpilot-pro' ),
			'bing_crawl_errors'         => __( 'Bing crawl errors', 'searchpilot-pro' ),
		);
	}

	/**
	 * Runs one check, raising or resolving its issue.
	 *
	 * @param string $id Check id.
	 * @return array Check record.
	 */
	public function run_check( $id ) {
		$method = 'check_' . $id;
		$result = method_exists( $this, $method ) ? $this->$method() : array(
			'status'  => 'skipped',
			'message' => '',
		);
		$result = array_merge(
			array(
				'status'  => 'passed',
				'message' => '',
				'details' => array(),
				'issue'   => null,
				'keep'    => false,
			),
			$result
		);
		$type = self::ISSUE_FOR[ $id ] ?? '';
		if ( '' !== $type ) {
			if ( is_array( $result['issue'] ) ) {
				$this->issues->upsert(
					$type,
					array(
						'object_type' => 'site',
						'object_id'   => 0,
						'url'         => (string) ( $result['issue']['url'] ?? home_url( '/' ) ),
						'severity'    => (string) ( $result['issue']['severity'] ?? '' ) ? (string) $result['issue']['severity'] : null,
						'title'       => get_bloginfo( 'name' ),
						'data'        => (array) ( $result['issue']['data'] ?? array() ),
					)
				);
			} elseif ( ! $result['keep'] ) {
				$this->issues->resolve( $type, 'site', 0, home_url( '/' ) );
			}
		}
		return array(
			'id'         => $id,
			'label'      => $this->definitions()[ $id ] ?? $id,
			'status'     => (string) $result['status'],
			'message'    => (string) $result['message'],
			'checked_at' => gmdate( 'c' ),
			'details'    => (array) $result['details'],
			'issue'      => '' !== $type ? $type : null,
		);
	}

	/**
	 * Stored checks (list, most severe first; passed last).
	 *
	 * @return array[]
	 */
	public function checks() {
		$checks = array_values( (array) $this->state->get( self::CHECKS, array() ) );
		$order  = array(
			'critical' => 1,
			'high'     => 2,
			'medium'   => 3,
			'skipped'  => 4,
			'passed'   => 5,
		);
		usort(
			$checks,
			static function ( $a, $b ) use ( $order ) {
				return ( $order[ $a['status'] ] ?? 9 ) <=> ( $order[ $b['status'] ] ?? 9 );
			}
		);
		return $checks;
	}

	/**
	 * Last run times.
	 *
	 * @return array
	 */
	public function runs() {
		return (array) $this->state->get( self::RUNS, array() );
	}

	/**
	 * Recomputes and stores the health score.
	 *
	 * Weighted by open issues (opportunities excluded): each critical −20
	 * (max −60), high −3 (max −30), medium −0.5 (max −15).
	 *
	 * @return array{score:int,at:string,counts:array}
	 */
	public function update_score() {
		$counts = $this->issues->counts( null, array( 'opportunity' ) );
		$score  = 100 - min( 60, 20 * $counts['critical'] ) - min( 30, 3 * $counts['high'] ) - min( 15, 0.5 * $counts['medium'] );
		$result = array(
			'score'  => (int) max( 0, round( $score ) ),
			'at'     => gmdate( 'c' ),
			'counts' => $counts,
		);
		$this->state->set( self::SCORE, $result );
		return $result;
	}

	/**
	 * Stored health score (computed on first use).
	 *
	 * @return array{score:int,at:string,counts:array}
	 */
	public function score() {
		$score = $this->state->get( self::SCORE );
		if ( ! is_array( $score ) || ! isset( $score['score'] ) ) {
			return $this->update_score();
		}
		// Fixes, ignores and new findings change the counts between runs: keep the ring current.
		if ( $this->issues->counts( null, array( 'opportunity' ) ) !== ( $score['counts'] ?? null ) ) {
			return $this->update_score();
		}
		return $score;
	}

	/* --------------------------------------------------------------------- *
	 * Checks
	 * --------------------------------------------------------------------- */

	/**
	 * blog_public = 0.
	 *
	 * @return array
	 */
	protected function check_site_noindexed() {
		if ( ! $this->env->search_engines_discouraged() ) {
			return array(
				'status'  => 'passed',
				'message' => __( 'Search engines are allowed to index the site.', 'searchpilot-pro' ),
			);
		}
		if ( 'production' !== $this->env->type() ) {
			return array(
				'status'  => 'passed',
				/* translators: %s: environment type. */
				'message' => sprintf( __( 'Search engines are discouraged, which is expected on this %s site.', 'searchpilot-pro' ), $this->env->type() ),
			);
		}
		return array(
			'status'  => 'critical',
			'message' => __( 'Settings → Reading → “Discourage search engines from indexing this site” is ON on a production site.', 'searchpilot-pro' ),
			'issue'   => array( 'data' => array( 'environment' => $this->env->type() ) ),
		);
	}

	/**
	 * Home page noindex (fetches the home page).
	 *
	 * @return array
	 */
	protected function check_home_noindex() {
		$home  = home_url( '/' );
		$chain = $this->crawler->fetch( $home, $this->budget );
		if ( 0 === (int) $chain['status'] ) {
			return array(
				'status'  => 'medium',
				/* translators: %s: error. */
				'message' => sprintf( __( 'The home page could not be fetched to check it (%s).', 'searchpilot-pro' ), $chain['error'] ),
				'keep'    => true,
			);
		}
		$facts = $this->inspector->analyze( $chain, array( 'indexable' => true ) );
		if ( (int) $facts['status'] >= 400 ) {
			return array(
				'status'  => 'critical',
				/* translators: %d: status. */
				'message' => sprintf( __( 'The home page returns HTTP %d.', 'searchpilot-pro' ), (int) $facts['status'] ),
				'details' => array( 'status' => (int) $facts['status'] ),
				'keep'    => true,
			);
		}
		if ( $facts['noindex'] ) {
			$discouraged = $this->env->search_engines_discouraged();
			return array(
				'status'  => 'critical',
				'message' => $discouraged
					? __( 'The home page is noindexed because search engines are discouraged for the whole site.', 'searchpilot-pro' )
					: __( 'The home page tells search engines not to index it.', 'searchpilot-pro' ),
				'details' => array(
					'directives' => $facts['directives'],
					'source'     => $facts['noindex_source'],
				),
				// The site-wide setting is reported by site_noindexed; don't duplicate it.
				'issue'   => $discouraged ? null : array(
					'data' => array(
						'source'     => $facts['noindex_source'],
						'directives' => $facts['directives'],
					),
				),
			);
		}
		return array(
			'status'  => 'passed',
			/* translators: 1: status, 2: milliseconds. */
			'message' => sprintf( __( 'The home page answers HTTP %1$d in %2$d ms and can be indexed.', 'searchpilot-pro' ), (int) $facts['status'], (int) $chain['duration_ms'] ),
			'details' => array( 'directives' => $facts['directives'] ),
		);
	}

	/**
	 * robots.txt reachability.
	 *
	 * @return array
	 */
	protected function check_robots_txt() {
		$info   = $this->crawler->refresh_robots();
		$status = (int) ( $info['status'] ?? 0 );
		if ( 0 === $status ) {
			return array(
				'status'  => 'medium',
				/* translators: %s: error. */
				'message' => sprintf( __( 'robots.txt could not be fetched (%s).', 'searchpilot-pro' ), (string) ( $info['error'] ?? '' ) ),
			);
		}
		if ( $status >= 500 ) {
			return array(
				'status'  => 'high',
				/* translators: %d: status. */
				'message' => sprintf( __( 'robots.txt returns HTTP %d. Google stops crawling the site while robots.txt answers with a server error.', 'searchpilot-pro' ), $status ),
			);
		}
		if ( $status >= 400 ) {
			return array(
				'status'  => 'passed',
				/* translators: %d: status. */
				'message' => sprintf( __( 'No robots.txt (HTTP %d): crawlers may crawl everything.', 'searchpilot-pro' ), $status ),
			);
		}
		return array(
			'status'  => 'passed',
			/* translators: %s: size. */
			'message' => sprintf( __( 'robots.txt is reachable (%s).', 'searchpilot-pro' ), size_format( (int) ( $info['size'] ?? 0 ) ) ),
			'details' => array( 'sitemaps' => (array) ( $info['sitemaps'] ?? array() ) ),
		);
	}

	/**
	 * robots.txt rules against important URLs.
	 *
	 * @return array
	 */
	protected function check_robots_blocks_important() {
		$this->crawler->refresh_robots();
		if ( ! $this->crawler->robots_parser() ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'robots.txt has not been fetched yet.', 'searchpilot-pro' ),
				'keep'    => true,
			);
		}
		$items = array(
			array( __( 'Home page', 'searchpilot-pro' ), home_url( '/' ), 'critical' ),
		);
		foreach ( get_posts(
			array(
				'numberposts'      => 5,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'suppress_filters' => true,
			)
		) as $post ) {
			/* translators: %s: post title. */
			$items[] = array( sprintf( __( 'Article “%s”', 'searchpilot-pro' ), wp_strip_all_tags( get_the_title( $post ) ) ), (string) get_permalink( $post ), 'high' );
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 5,
				'hide_empty' => true,
			)
		);
		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat ) {
				$link = get_term_link( $cat );
				if ( ! is_wp_error( $link ) ) {
					/* translators: %s: category name. */
					$items[] = array( sprintf( __( 'Category “%s”', 'searchpilot-pro' ), $cat->name ), (string) $link, 'high' );
				}
			}
		}
		$uploads = wp_get_upload_dir();
		$items[] = array( __( 'Uploaded images (/wp-content/uploads/)', 'searchpilot-pro' ), trailingslashit( (string) $uploads['baseurl'] ) . gmdate( 'Y/m' ) . '/image.jpg', 'high' );
		$items[] = array( __( 'Theme stylesheet', 'searchpilot-pro' ), (string) get_stylesheet_uri(), 'high' );
		$items[] = array( __( 'Theme scripts', 'searchpilot-pro' ), trailingslashit( get_template_directory_uri() ) . 'script.js', 'high' );
		$items[] = array( __( 'WordPress scripts (wp-includes)', 'searchpilot-pro' ), includes_url( 'js/wp-embed.min.js' ), 'high' );

		$blocked  = array();
		$severity = '';
		foreach ( $items as $item ) {
			list( $label, $url, $level ) = $item;
			if ( '' === $url || ! Url::is_internal( $url ) ) {
				continue;
			}
			$status = $this->crawler->robots_status( $url );
			if ( in_array( 'Googlebot', $status['agents'], true ) ) {
				$blocked[] = array(
					'label' => $label,
					'url'   => $url,
					'rule'  => $status['rule'],
				);
				$severity  = 'critical' === $level || 'critical' === $severity ? 'critical' : 'high';
			}
		}
		if ( ! $blocked ) {
			return array(
				'status'  => 'passed',
				'message' => __( 'Googlebot may crawl the home page, the newest articles, the main categories, images, CSS and JavaScript.', 'searchpilot-pro' ),
			);
		}
		return array(
			'status'  => $severity,
			/* translators: %d: number of blocked items. */
			'message' => sprintf( _n( 'robots.txt blocks %d important URL for Googlebot.', 'robots.txt blocks %d important URLs for Googlebot.', count( $blocked ), 'searchpilot-pro' ), count( $blocked ) ),
			'details' => array( 'blocked' => $blocked ),
			'issue'   => array(
				'severity' => $severity,
				'data'     => array( 'blocked' => $blocked ),
			),
		);
	}

	/**
	 * Sitemap health (reflects the sitemap module; its own issues are not duplicated).
	 *
	 * @return array
	 */
	protected function check_sitemap() {
		$owner = $this->conflicts->owner( 'sitemap' );
		if ( 'off' === $owner ) {
			return array(
				'status'  => 'medium',
				'message' => __( 'No XML sitemap is generated. Search engines discover new pages more slowly without one.', 'searchpilot-pro' ),
			);
		}
		$class = '\SearchPilot\Sitemap\SitemapHealth';
		if ( class_exists( $class ) ) {
			try {
				$health = \SearchPilot\Plugin::instance()->get( $class );
				$status = is_object( $health ) && method_exists( $health, 'status' ) ? (array) $health->status() : array();
			} catch ( \Throwable $e ) {
				$status = array();
			}
			if ( $status ) {
				$map = array(
					'healthy'  => 'passed',
					'warning'  => 'medium',
					'error'    => 'high',
					'disabled' => 'medium',
					'unknown'  => 'skipped',
				);
				$errors = array_slice( array_map( 'strval', (array) ( $status['errors'] ?? array() ) ), 0, 5 );
				return array(
					'status'  => $map[ $status['status'] ?? 'unknown' ] ?? 'skipped',
					'message' => $errors
						? implode( ' ', $errors )
						/* translators: 1: sitemap URL, 2: number of URLs. */
						: sprintf( __( 'Sitemap %1$s lists %2$s URLs.', 'searchpilot-pro' ), Url::display( (string) ( $status['index_url'] ?? '' ) ), number_format_i18n( (int) ( $status['url_count'] ?? 0 ) ) ),
					'details' => array(
						'owner'     => (string) ( $status['owner_name'] ?? $owner ),
						'index_url' => (string) ( $status['index_url'] ?? '' ),
					),
				);
			}
		}
		// Fallback: fetch the most likely index URL.
		$candidates = 'searchpilot' === $owner ? array( home_url( '/sitemap.xml' ), home_url( '/wp-sitemap.xml' ) ) : array( home_url( '/sitemap_index.xml' ), home_url( '/sitemap.xml' ), home_url( '/wp-sitemap.xml' ) );
		foreach ( $candidates as $url ) {
			$chain = $this->crawler->fetch( $url, $this->budget );
			if ( 200 === (int) $chain['status'] && preg_match( '/<(urlset|sitemapindex)\b/i', substr( (string) $chain['body'], 0, 4096 ) ) ) {
				return array(
					'status'  => 'passed',
					/* translators: %s: sitemap URL. */
					'message' => sprintf( __( 'The sitemap %s is reachable.', 'searchpilot-pro' ), Url::display( (string) $chain['final_url'] ) ),
					'details' => array( 'index_url' => (string) $chain['final_url'] ),
				);
			}
		}
		return array(
			'status'  => 'high',
			'message' => __( 'No reachable XML sitemap was found.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Whether the newest articles appear in SearchPilot's published sitemap
	 * (catches stale sitemap caches). Raises missing_from_sitemap per URL.
	 *
	 * @return array
	 */
	protected function check_sitemap_fresh() {
		if ( ! $this->conflicts->owns( 'sitemap' ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Your sitemap is generated by another plugin; SearchPilot monitors its health instead.', 'searchpilot-pro' ),
			);
		}
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'active' AND object_type = 'post' AND in_sitemap = 1 AND last_modified <= %s AND last_modified >= %s ORDER BY last_modified DESC LIMIT 5", gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A );
		if ( ! $rows ) {
			return array(
				'status'  => 'passed',
				'message' => __( 'No articles were published or updated in the last 7 days.', 'searchpilot-pro' ),
			);
		}
		$index = $this->sitemap_index_url();
		$locs  = $this->sitemap_locations( $index );
		if ( null === $locs ) {
			return array(
				'status'  => 'skipped',
				/* translators: %s: sitemap URL. */
				'message' => sprintf( __( 'The sitemap %s could not be read.', 'searchpilot-pro' ), Url::display( $index ) ),
			);
		}
		$missing = array();
		foreach ( $rows as $raw ) {
			$row = $this->urls->hydrate( $raw );
			if ( isset( $locs[ Url::normalize( $row['url'] ) ] ) ) {
				$this->issues->resolve( 'missing_from_sitemap', 'post', (int) $row['object_id'], (string) $row['url'] );
				continue;
			}
			$missing[] = $row['url'];
			$this->issues->upsert(
				'missing_from_sitemap',
				array(
					'object_type' => 'post',
					'object_id'   => (int) $row['object_id'],
					'url'         => (string) $row['url'],
					'title'       => wp_strip_all_tags( get_the_title( (int) $row['object_id'] ) ),
					'data'        => array(
						'stale'   => true,
						'subtype' => (string) $row['object_subtype'],
						'row_id'  => (int) $row['id'],
					),
				)
			);
		}
		if ( $missing ) {
			return array(
				'status'  => 'medium',
				/* translators: %d: number of articles. */
				'message' => sprintf( _n( '%d recent article is missing from the published sitemap.', '%d recent articles are missing from the published sitemap.', count( $missing ), 'searchpilot-pro' ), count( $missing ) ),
				'details' => array( 'missing' => $missing ),
			);
		}
		return array(
			'status'  => 'passed',
			/* translators: %d: number of articles. */
			'message' => sprintf( __( 'The %d newest articles are listed in the sitemap.', 'searchpilot-pro' ), count( $rows ) ),
		);
	}

	/**
	 * Search Console connection health.
	 *
	 * @return array
	 */
	protected function check_gsc_connectivity() {
		$class = '\SearchPilot\Api\Connections';
		if ( ! class_exists( $class ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'The search engine connection module is not available.', 'searchpilot-pro' ),
			);
		}
		try {
			$connections = \SearchPilot\Plugin::instance()->get( $class );
			$status      = is_object( $connections ) && method_exists( $connections, 'status' ) ? (array) $connections->status() : array();
		} catch ( \Throwable $e ) {
			$status = array();
		}
		$google    = (array) ( $status['google'] ?? array() );
		$connected = ! empty( $google['connected'] );
		$error     = trim( (string) ( $google['error'] ?? '' ) );
		if ( $connected && '' === $error ) {
			if ( ! $this->state->get( 'gsc_was_connected' ) ) {
				$this->state->set( 'gsc_was_connected', true );
			}
			return array(
				'status'  => 'passed',
				/* translators: %s: property. */
				'message' => sprintf( __( 'Connected to %s.', 'searchpilot-pro' ), (string) ( $google['property'] ?? '' ) ),
			);
		}
		if ( '' !== $error && ( $connected || $this->state->get( 'gsc_was_connected' ) ) ) {
			return array(
				'status'  => 'high',
				'message' => $error,
				'issue'   => array( 'data' => array( 'error' => mb_substr( $error, 0, 300 ) ) ),
			);
		}
		return array(
			'status'  => 'medium',
			'message' => __( 'Google Search Console is not connected. Connect it to see Google’s index status and search performance.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Week-over-week drop of indexed pages (URL Inspection + GSC sitemap counts).
	 *
	 * @return array
	 */
	protected function check_indexing_decline() {
		$threshold = (int) $this->settings->get( 'alerts.index_drop_percent', 20 );
		$history   = (array) $this->state->get( self::INDEX, array() );
		$today     = gmdate( 'Y-m-d' );
		$current   = array(
			'indexed' => (int) $this->urls->summary()['indexed'],
			'gsc'     => $this->gsc_sitemap_indexed(),
		);
		$history[ $today ] = $current;
		ksort( $history );
		$history = array_slice( $history, -60, null, true );
		$this->state->set( self::INDEX, $history );

		$previous = null;
		foreach ( array( 7, 8, 6, 9, 5 ) as $days ) {
			$key = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
			if ( isset( $history[ $key ] ) ) {
				$previous = $history[ $key ];
				break;
			}
		}
		if ( null === $previous ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Collecting a week of history before comparing.', 'searchpilot-pro' ),
				'details' => array( 'indexed' => $current['indexed'] ),
				'keep'    => true,
			);
		}
		$checks = array(
			array( 'indexed', __( 'URL Inspection', 'searchpilot-pro' ), 20 ),
			array( 'gsc', __( 'Search Console sitemaps', 'searchpilot-pro' ), 50 ),
		);
		foreach ( $checks as $c ) {
			list( $key, $label, $min ) = $c;
			$prev = (int) ( $previous[ $key ] ?? 0 );
			$cur  = (int) ( $current[ $key ] ?? 0 );
			if ( 'gsc' === $key && 0 === $cur ) {
				continue; // Google no longer reports indexed counts for some sitemaps.
			}
			if ( $prev >= $min && $cur < $prev ) {
				$drop = 100 * ( $prev - $cur ) / $prev;
				if ( $drop >= $threshold ) {
					return array(
						'status'  => 'high',
						/* translators: 1: source, 2: percent. */
						'message' => sprintf( __( '%1$s: indexed pages fell %2$s%% in a week.', 'searchpilot-pro' ), $label, number_format_i18n( $drop, 1 ) ),
						'details' => array(
							'previous' => $prev,
							'current'  => $cur,
						),
						'issue'   => array(
							'data' => array(
								'source'       => $key,
								'source_label' => $label,
								'previous'     => $prev,
								'current'      => $cur,
								'drop'         => round( $drop, 1 ),
							),
						),
					);
				}
			}
		}
		return array(
			'status'  => 'passed',
			/* translators: 1: current count, 2: count a week ago. */
			'message' => sprintf( __( '%1$s indexed pages (a week ago: %2$s).', 'searchpilot-pro' ), number_format_i18n( $current['indexed'] ), number_format_i18n( (int) ( $previous['indexed'] ?? 0 ) ) ),
		);
	}

	/**
	 * Google clicks t-9…t-3 vs t-16…t-10.
	 *
	 * @return array
	 */
	protected function check_traffic_decline() {
		global $wpdb;
		$table    = Tables::perf_daily();
		$day      = static function ( $n ) {
			return gmdate( 'Y-m-d', time() - $n * DAY_IN_SECONDS );
		};
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(clicks) FROM {$table} WHERE engine = 'google' AND date BETWEEN %s AND %s", $day( 9 ), $day( 3 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(clicks) FROM {$table} WHERE engine = 'google' AND date BETWEEN %s AND %s", $day( 16 ), $day( 10 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$days = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE engine = 'google' AND date BETWEEN %s AND %s", $day( 16 ), $day( 3 ) ) );
		$wpdb->suppress_errors( $suppress );

		if ( $days < 10 || $previous < 100 ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Not enough Search Console click data to compare yet (needs 100+ clicks a week).', 'searchpilot-pro' ),
				'details' => array(
					'current'  => $current,
					'previous' => $previous,
				),
			);
		}
		$drop      = $previous > 0 ? 100 * ( $previous - $current ) / $previous : 0;
		$threshold = (int) $this->settings->get( 'alerts.traffic_drop_percent', 40 );
		if ( $drop >= $threshold ) {
			return array(
				'status'  => 'critical',
				/* translators: %s: percent. */
				'message' => sprintf( __( 'Google clicks fell %s%% week over week.', 'searchpilot-pro' ), number_format_i18n( $drop, 1 ) ),
				'details' => array(
					'current'  => $current,
					'previous' => $previous,
				),
				'issue'   => array(
					'data' => array(
						'current'  => $current,
						'previous' => $previous,
						'drop'     => round( $drop, 1 ),
					),
				),
			);
		}
		return array(
			'status'  => 'passed',
			/* translators: 1: clicks, 2: clicks the week before. */
			'message' => sprintf( __( '%1$s Google clicks in the latest complete week (week before: %2$s).', 'searchpilot-pro' ), number_format_i18n( $current ), number_format_i18n( $previous ) ),
		);
	}

	/**
	 * Share of recently checked pages with canonical problems.
	 *
	 * @return array
	 */
	protected function check_canonical_broken() {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM( CASE WHEN errors LIKE %s OR errors LIKE %s OR errors LIKE %s THEN 1 ELSE 0 END ) AS bad
				FROM {$table} WHERE status = 'active' AND last_checked >= %s AND http_status BETWEEN 200 AND 299",
				'%"multiple_canonicals"%',
				'%"canonical_elsewhere"%',
				'%"canonical_to_non_200"%',
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		$total = (int) ( $row['total'] ?? 0 );
		$bad   = (int) ( $row['bad'] ?? 0 );
		if ( $total < 20 ) {
			return array(
				'status'  => $bad ? 'medium' : 'passed',
				/* translators: 1: pages with problems, 2: pages checked. */
				'message' => sprintf( __( '%1$d of %2$d recently checked pages have canonical problems.', 'searchpilot-pro' ), $bad, $total ),
			);
		}
		$percent = 100 * $bad / $total;
		if ( $percent >= 10 ) {
			return array(
				'status'  => 'high',
				/* translators: %s: percent. */
				'message' => sprintf( __( '%s%% of recently checked pages have canonical problems.', 'searchpilot-pro' ), number_format_i18n( $percent, 1 ) ),
				'issue'   => array(
					'data' => array(
						'bad'     => $bad,
						'total'   => $total,
						'percent' => round( $percent, 1 ),
					),
				),
			);
		}
		return array(
			'status'  => $bad ? 'medium' : 'passed',
			/* translators: 1: pages with problems, 2: pages checked. */
			'message' => sprintf( __( '%1$s of %2$s recently checked pages have canonical problems.', 'searchpilot-pro' ), number_format_i18n( $bad ), number_format_i18n( $total ) ),
		);
	}

	/**
	 * 5xx responses in the last 24 hours.
	 *
	 * @return array
	 */
	protected function check_mass_server_errors() {
		global $wpdb;
		$table = Tables::urls();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row       = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM( CASE WHEN http_status >= 500 OR errors LIKE %s THEN 1 ELSE 0 END ) AS errors FROM {$table} WHERE status = 'active' AND last_checked >= %s", '%"http_5xx"%', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A );
		$total     = (int) ( $row['total'] ?? 0 );
		$errors    = (int) ( $row['errors'] ?? 0 );
		$threshold = max( 3, (int) ceil( (int) $this->settings->get( 'alerts.error_spike', 50 ) / 5 ) );
		$spike     = $errors >= $threshold || ( $total >= 10 && $errors >= 3 && $errors / $total >= 0.1 );
		if ( $spike ) {
			return array(
				'status'  => 'critical',
				/* translators: 1: errors, 2: checks. */
				'message' => sprintf( __( '%1$s of %2$s page checks in the last 24 hours returned a server error.', 'searchpilot-pro' ), number_format_i18n( $errors ), number_format_i18n( $total ) ),
				'issue'   => array(
					'data' => array(
						'errors' => $errors,
						'total'  => $total,
					),
				),
			);
		}
		return array(
			'status'  => $errors ? 'medium' : 'passed',
			/* translators: 1: errors, 2: checks. */
			'message' => sprintf( _n( '%1$s server error in %2$s page checks in the last 24 hours.', '%1$s server errors in %2$s page checks in the last 24 hours.', $errors, 'searchpilot-pro' ), number_format_i18n( $errors ), number_format_i18n( $total ) ),
		);
	}

	/**
	 * Redirect loops (reflects open issues; no new issue).
	 *
	 * @return array
	 */
	protected function check_redirect_loops() {
		$open = $this->issues->query(
			array(
				'status'   => 'open',
				'type'     => array( 'url_redirect_loop', 'redirect_loop' ),
				'per_page' => 1,
			)
		)['total'];
		if ( $open ) {
			return array(
				'status'  => 'critical',
				/* translators: %d: number of loops. */
				'message' => sprintf( _n( '%d URL is caught in a redirect loop.', '%d URLs are caught in redirect loops.', $open, 'searchpilot-pro' ), $open ),
			);
		}
		return array(
			'status'  => 'passed',
			'message' => __( 'No redirect loops found.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Open structured data issues.
	 *
	 * @return array
	 */
	protected function check_schema_failures() {
		$open = (int) $this->issues->counts( 'schema' )['total'];
		$own  = $this->issues->query(
			array(
				'status'   => 'open',
				'type'     => array( 'schema_failures' ),
				'per_page' => 1,
			)
		)['total'];
		$open = max( 0, $open - $own );
		if ( $open >= 10 ) {
			return array(
				'status'  => 'high',
				/* translators: %d: number of issues. */
				'message' => sprintf( __( '%d structured data issues are open.', 'searchpilot-pro' ), $open ),
				'issue'   => array( 'data' => array( 'open' => $open ) ),
			);
		}
		return array(
			'status'  => $open ? 'medium' : 'passed',
			/* translators: %d: number of issues. */
			'message' => $open ? sprintf( _n( '%d structured data issue is open.', '%d structured data issues are open.', $open, 'searchpilot-pro' ), $open ) : __( 'No structured data problems found.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Background processing.
	 *
	 * @return array
	 */
	protected function check_cron_not_running() {
		if ( $this->scheduler->healthy() ) {
			$runs = $this->scheduler->last_runs();
			return array(
				'status'  => 'passed',
				'message' => ! empty( $runs['searchpilot_hourly'] )
					/* translators: %s: time ago. */
					? sprintf( __( 'The hourly job last ran %s ago.', 'searchpilot-pro' ), human_time_diff( (int) $runs['searchpilot_hourly'] ) )
					: __( 'Background jobs are scheduled.', 'searchpilot-pro' ),
			);
		}
		return array(
			'status'  => 'high',
			'message' => __( 'The hourly background job has not run for more than three hours.', 'searchpilot-pro' ),
			'issue'   => array( 'data' => array( 'backend' => Scheduler::backend() ) ),
		);
	}

	/**
	 * Credential decryption.
	 *
	 * @return array
	 */
	protected function check_credentials_undecryptable() {
		$error = $this->credentials->error();
		if ( $error ) {
			return array(
				'status'  => 'high',
				'message' => $error->get_error_message(),
				'issue'   => array( 'data' => array( 'error' => mb_substr( $error->get_error_message(), 0, 300 ) ) ),
			);
		}
		return array(
			'status'  => 'passed',
			'message' => __( 'Stored credentials can be read.', 'searchpilot-pro' ),
		);
	}

	/**
	 * Crawler health (the crawler raises loopback_blocked itself).
	 *
	 * @return array
	 */
	protected function check_loopback() {
		$health = $this->crawler->health();
		$paused = $this->crawler->paused_until();
		if ( $paused ) {
			return array(
				'status'  => 'high',
				/* translators: %s: error. */
				'message' => sprintf( __( 'Page checks are paused: the site could not reach itself (%s).', 'searchpilot-pro' ), $health['last_error'] ),
			);
		}
		if ( $health['consecutive'] > 0 ) {
			return array(
				'status'  => 'medium',
				/* translators: %d: failures. */
				'message' => sprintf( __( 'The last %d page checks failed.', 'searchpilot-pro' ), (int) $health['consecutive'] ),
			);
		}
		$run = (array) $this->state->get( Crawler::LAST_RUN, array() );
		return array(
			'status'  => 'passed',
			'message' => ! empty( $health['last_success_at'] )
				/* translators: %s: time ago. */
				? sprintf( __( 'SearchPilot can open your pages (last successful check %s ago).', 'searchpilot-pro' ), human_time_diff( (int) $health['last_success_at'] ) )
				: __( 'No page checks have run yet.', 'searchpilot-pro' ),
			'details' => $run,
		);
	}

	/**
	 * WordPress attachment pages.
	 *
	 * @return array
	 */
	protected function check_attachment_pages() {
		if ( ! $this->registry->attachment_pages_live() ) {
			return array(
				'status'  => 'passed',
				'message' => __( 'Attachment pages are off (attachment URLs lead to the file).', 'searchpilot-pro' ),
			);
		}
		$count = (int) ( wp_count_posts( 'attachment' )->inherit ?? 0 );
		if ( $count < 1 ) {
			return array(
				'status'  => 'passed',
				'message' => __( 'Attachment pages are on, but there are no media files yet.', 'searchpilot-pro' ),
			);
		}
		return array(
			'status'  => 'medium',
			/* translators: %s: number of files. */
			'message' => sprintf( __( 'Attachment pages are on for %s media files.', 'searchpilot-pro' ), number_format_i18n( $count ) ),
			'issue'   => array( 'data' => array( 'attachments' => $count ) ),
		);
	}

	/**
	 * Bing crawl errors (reflects Bing Webmaster Tools crawl stats; no issue of its own).
	 *
	 * @return array
	 */
	protected function check_bing_crawl_errors() {
		$bing = $this->bing_crawl();
		if ( null === $bing ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'Bing Webmaster Tools is not connected, or it has not reported crawl statistics yet.', 'searchpilot-pro' ),
			);
		}
		$errors  = (int) $bing['code_4xx'] + (int) $bing['code_5xx'];
		$details = $bing;
		if ( null !== $bing['recent_7'] ) {
			$recent   = (int) $bing['recent_7'];
			$previous = (int) $bing['previous_7'];
			$floor    = max( 10, (int) ceil( (int) $this->settings->get( 'alerts.error_spike', 50 ) / 5 ) );
			if ( $recent >= $floor && $recent >= 2 * max( 1, $previous ) ) {
				return array(
					'status'  => 'high',
					/* translators: 1: errors this week, 2: errors the week before. */
					'message' => sprintf( __( 'Bing reports a spike in crawl errors: %1$s in the last 7 days (the 7 days before: %2$s). Check the Indexing screen filtered by 4xx/5xx and your server logs.', 'searchpilot-pro' ), number_format_i18n( $recent ), number_format_i18n( $previous ) ),
					'details' => $details,
				);
			}
		} elseif ( $errors >= (int) $this->settings->get( 'alerts.error_spike', 50 ) ) {
			return array(
				'status'  => 'medium',
				/* translators: %s: number of errors. */
				'message' => sprintf( __( 'Bing reported %s crawl errors (4xx/5xx) in the last 30 days.', 'searchpilot-pro' ), number_format_i18n( $errors ) ),
				'details' => $details,
			);
		}
		return array(
			'status'  => 'passed',
			/* translators: 1: 4xx, 2: 5xx, 3: blocked by robots.txt. */
			'message' => sprintf( __( 'Bing crawl errors in the last 30 days: %1$s client (4xx), %2$s server (5xx), %3$s blocked by robots.txt.', 'searchpilot-pro' ), number_format_i18n( (int) $bing['code_4xx'] ), number_format_i18n( (int) $bing['code_5xx'] ), number_format_i18n( (int) $bing['blocked_by_robots'] ) ),
			'details' => $details,
		);
	}

	/**
	 * Bing crawl errors of the last 30 days from Api\Bing\BingWebmaster::crawl_summary()
	 * (cached by the Bing module; never an API call from here). Accepts daily rows
	 * ([ date, crawled_pages, crawl_errors, code_4xx, code_5xx, blocked_by_robots ])
	 * or totals. Null when Bing is not connected or has no data.
	 *
	 * @return array{code_4xx:int,code_5xx:int,blocked_by_robots:int,crawl_errors:int,crawled_pages:int,recent_7:int|null,previous_7:int|null,days:int}|null
	 */
	public function bing_crawl() {
		$class = '\SearchPilot\Api\Bing\BingWebmaster';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		try {
			$bing = \SearchPilot\Plugin::instance()->get( $class );
			if ( ! is_object( $bing ) || ! method_exists( $bing, 'crawl_summary' ) ) {
				return null;
			}
			if ( method_exists( $bing, 'is_connected' ) && ! $bing->is_connected() ) {
				return null;
			}
			$raw = $bing->crawl_summary();
		} catch ( \Throwable $e ) {
			$this->logger->debug( 'audit', 'Bing crawl summary unavailable: ' . $e->getMessage() );
			return null;
		}
		if ( ! is_array( $raw ) || ! $raw ) {
			return null;
		}
		$keys = array( 'code_4xx', 'code_5xx', 'blocked_by_robots', 'crawl_errors', 'crawled_pages' );
		$out  = array_fill_keys( $keys, 0 ) + array(
			'recent_7'   => null,
			'previous_7' => null,
			'days'       => 0,
		);
		// BingWebmaster::crawl_summary() returns the daily rows under 'series'.
		$rows = array();
		foreach ( array( 'series', 'daily', 'rows' ) as $list_key ) {
			if ( isset( $raw[ $list_key ] ) && is_array( $raw[ $list_key ] ) && $raw[ $list_key ] ) {
				$rows = $raw[ $list_key ];
				break;
			}
		}
		if ( ! $rows && isset( $raw[0] ) && is_array( $raw[0] ) ) {
			$rows = $raw;
		}
		if ( $rows ) {
			$now    = time();
			$recent = 0;
			$prev   = 0;
			foreach ( $rows as $row ) {
				$ts = ! empty( $row['date'] ) ? strtotime( (string) $row['date'] . ' 12:00:00 UTC' ) : 0;
				if ( ! $ts || $ts < $now - 31 * DAY_IN_SECONDS ) {
					continue;
				}
				foreach ( $keys as $key ) {
					$out[ $key ] += (int) ( $row[ $key ] ?? 0 );
				}
				$errors = (int) ( $row['code_4xx'] ?? 0 ) + (int) ( $row['code_5xx'] ?? 0 );
				if ( $ts >= $now - 7 * DAY_IN_SECONDS ) {
					$recent += $errors;
				} elseif ( $ts >= $now - 14 * DAY_IN_SECONDS ) {
					$prev += $errors;
				}
				++$out['days'];
			}
			if ( ! $out['days'] ) {
				return null;
			}
			$out['recent_7']   = $recent;
			$out['previous_7'] = $prev;
			return $out;
		}
		$totals = isset( $raw['totals'] ) && is_array( $raw['totals'] ) ? $raw['totals'] : $raw;
		$found  = false;
		foreach ( $keys as $key ) {
			if ( isset( $totals[ $key ] ) && is_numeric( $totals[ $key ] ) ) {
				$out[ $key ] = (int) $totals[ $key ];
				$found       = true;
			}
		}
		foreach ( array( 'recent_7', 'previous_7' ) as $key ) {
			if ( isset( $totals[ $key ] ) && is_numeric( $totals[ $key ] ) ) {
				$out[ $key ] = (int) $totals[ $key ];
			}
		}
		$out['days'] = (int) ( $totals['days'] ?? 30 );
		return $found ? $out : null;
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Sum of Search Console "indexed" counts over submitted sitemaps (0 = unknown).
	 *
	 * @return int
	 */
	private function gsc_sitemap_indexed() {
		$class = '\SearchPilot\Sitemap\SitemapHealth';
		if ( ! class_exists( $class ) ) {
			return 0;
		}
		try {
			$health = \SearchPilot\Plugin::instance()->get( $class );
			$status = is_object( $health ) && method_exists( $health, 'status' ) ? (array) $health->status() : array();
		} catch ( \Throwable $e ) {
			return 0;
		}
		$total = 0;
		foreach ( (array) ( $status['gsc'] ?? array() ) as $sitemap ) {
			foreach ( (array) ( $sitemap['contents'] ?? array() ) as $content ) {
				if ( 'web' === ( $content['type'] ?? 'web' ) ) {
					$total += (int) ( $content['indexed'] ?? 0 );
				}
			}
		}
		return $total;
	}

	/**
	 * SearchPilot's sitemap index URL.
	 *
	 * @return string
	 */
	private function sitemap_index_url() {
		$class = '\SearchPilot\Sitemap\SitemapModule';
		if ( class_exists( $class ) ) {
			try {
				$module = \SearchPilot\Plugin::instance()->get( $class );
				if ( is_object( $module ) && method_exists( $module, 'index_url' ) ) {
					return (string) $module->index_url();
				}
			} catch ( \Throwable $e ) {
				$this->logger->debug( 'audit', 'Sitemap module lookup failed: ' . $e->getMessage() );
			}
		}
		return home_url( '/sitemap.xml' );
	}

	/**
	 * Normalized URLs listed in the sitemap index and its three freshest
	 * child sitemaps (null when the index cannot be read).
	 *
	 * @param string $index Index URL.
	 * @return array<string,bool>|null
	 */
	private function sitemap_locations( $index ) {
		$chain = $this->crawler->fetch( $index, $this->budget );
		if ( 200 !== (int) $chain['status'] ) {
			return null;
		}
		$body = (string) $chain['body'];
		if ( ! preg_match( '/<(urlset|sitemapindex)\b/i', substr( $body, 0, 8192 ), $root ) ) {
			return null;
		}
		$locs = array();
		if ( 'urlset' === strtolower( $root[1] ) ) {
			return self::parse_locs( $body );
		}
		$children = array();
		if ( preg_match_all( '#<sitemap\b[^>]*>(.*?)</sitemap>#is', $body, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				if ( preg_match( '#<loc>\s*(.*?)\s*</loc>#is', $block, $loc ) ) {
					$lastmod    = preg_match( '#<lastmod>\s*(.*?)\s*</lastmod>#is', $block, $m ) ? strtotime( $m[1] ) : 0;
					$children[] = array( html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ), (int) $lastmod );
				}
			}
		}
		usort(
			$children,
			static function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		foreach ( array_slice( $children, 0, 3 ) as $child ) {
			if ( ! Crawler::is_fetchable( $child[0] ) ) {
				continue;
			}
			if ( $this->budget && $this->budget->exhausted( 8 ) ) {
				break;
			}
			$c = $this->crawler->fetch( $child[0], $this->budget );
			if ( 200 === (int) $c['status'] ) {
				$locs += self::parse_locs( (string) $c['body'] );
			}
		}
		return $locs;
	}

	/**
	 * <loc> URLs of a urlset.
	 *
	 * @param string $xml XML.
	 * @return array<string,bool>
	 */
	private static function parse_locs( $xml ) {
		$out = array();
		if ( preg_match_all( '#<url\b[^>]*>.*?<loc>\s*(.*?)\s*</loc>#is', (string) $xml, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$out[ Url::normalize( html_entity_decode( trim( $loc ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) ] = true;
			}
		}
		return $out;
	}

	/**
	 * Summary for the dashboard.
	 *
	 * @return array{checked_at:string|null,critical:int,high:int,medium:int,passed:int,score:int}
	 */
	public function summary() {
		$counts = array(
			'critical' => 0,
			'high'     => 0,
			'medium'   => 0,
			'passed'   => 0,
		);
		foreach ( $this->checks() as $check ) {
			if ( isset( $counts[ $check['status'] ] ) ) {
				++$counts[ $check['status'] ];
			}
		}
		$runs = $this->runs();
		return array_merge(
			array( 'checked_at' => $runs['critical_at'] ?? ( $runs['full_at'] ?? null ) ),
			$counts,
			array( 'score' => (int) $this->score()['score'] )
		);
	}
}
