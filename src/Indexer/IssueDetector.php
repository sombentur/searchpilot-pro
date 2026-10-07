<?php
/**
 * URL-level issue detection for the registry.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Settings;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a registry row (plus the latest crawl details and URL Inspection
 * result) into issues. Every evaluation re-checks all URL issue types from
 * the stored row, so issues that no longer apply are resolved automatically.
 */
class IssueDetector {

	/**
	 * Issue types evaluated per URL.
	 */
	// Google commonly needs up to two weeks to discover and crawl a new page of a young site.
	// "Unknown to Google" / "Discovered – currently not indexed" inside that window is normal
	// (the URL is already in the sitemap and was submitted), so it is not reported as an issue.
	const NEW_URL_GRACE = 1209600; // 14 days.

	const URL_TYPES = array(
		'url_redirect_loop',
		'url_redirect_chain',
		'http_5xx',
		'http_4xx',
		'soft_404',
		'accidental_noindex',
		'blocked_by_robots',
		'canonical_to_non_200',
		'multiple_canonicals',
		'canonical_mismatch',
		'pagination_canonical',
		'non_indexable_in_sitemap',
		'missing_from_sitemap',
		'crawled_not_indexed',
		'discovered_not_indexed',
		'not_indexed',
	);

	/**
	 * Recommended action per issue type (most severe issue wins).
	 */
	const ACTIONS = array(
		'url_redirect_loop'        => 'fix_redirect',
		'url_redirect_chain'       => 'update_redirects',
		'http_5xx'                 => 'fix_server_error',
		'http_4xx'                 => 'restore_or_redirect',
		'soft_404'                 => 'improve_content',
		'accidental_noindex'       => 'remove_noindex',
		'blocked_by_robots'        => 'edit_robots_txt',
		'canonical_to_non_200'     => 'fix_canonical',
		'multiple_canonicals'      => 'fix_canonical',
		'canonical_mismatch'       => 'fix_canonical',
		'pagination_canonical'     => 'fix_canonical',
		'non_indexable_in_sitemap' => 'remove_from_sitemap',
		'missing_from_sitemap'     => 'add_to_sitemap',
		'crawled_not_indexed'      => 'improve_content',
		'discovered_not_indexed'   => 'add_internal_links',
		'not_indexed'              => 'request_indexing',
		'loopback_blocked'         => 'allow_loopback',
		'attachment_pages_enabled' => 'disable_attachment_pages',
	);

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
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Fixers.
	 *
	 * @var Fixers
	 */
	private $fixers;

	/**
	 * Per-request memo (menu items, traffic, sitemap data availability).
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Constructor.
	 *
	 * @param IssueRepository  $issues    Issues.
	 * @param UrlRepository    $urls      URLs.
	 * @param ContentTypes     $types     Content types.
	 * @param MetaStore        $meta      Meta store.
	 * @param Settings         $settings  Settings.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param Fixers           $fixers    Fixers.
	 */
	public function __construct( IssueRepository $issues, UrlRepository $urls, ContentTypes $types, MetaStore $meta, Settings $settings, ConflictDetector $conflicts, Fixers $fixers ) {
		$this->issues    = $issues;
		$this->urls      = $urls;
		$this->types     = $types;
		$this->meta      = $meta;
		$this->settings  = $settings;
		$this->conflicts = $conflicts;
		$this->fixers    = $fixers;
	}

	/* --------------------------------------------------------------------- *
	 * Registration
	 * --------------------------------------------------------------------- */

	/**
	 * Registers the indexer's issue types.
	 *
	 * @param IssueRegistry $r Registry.
	 */
	public function register_types( IssueRegistry $r ) {
		$owns_sitemap = $this->conflicts->owns( 'sitemap' );

		$r->register(
			'not_indexed',
			array(
				'label'    => __( 'Page is not indexed by Google', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					return sprintf(
						/* translators: %s: Search Console coverage state. */
						__( 'Google’s URL Inspection reports “%s”. The page is not in Google’s index, so it cannot appear in Google Search.', 'searchpilot-pro' ),
						(string) ( $d['coverage_state'] ?? __( 'URL is not on Google', 'searchpilot-pro' ) )
					);
				},
				'why'      => __( 'Pages that are not indexed get no search traffic. When an important page stays out of the index, readers searching for it find other sites instead.', 'searchpilot-pro' ),
				'fix'      => static function ( $issue ) {
					$d      = $issue['data'];
					$status = (string) ( $d['index_status'] ?? '' );
					if ( 'unknown_to_google' === $status ) {
						return __( 'Google has not discovered this URL yet. Keep it in the XML sitemap, link to it from the home page, category pages and related articles, then use “Notify search engines”. Indexing is decided by Google and is never guaranteed.', 'searchpilot-pro' );
					}
					if ( in_array( $status, array( 'excluded_noindex', 'blocked_robots', 'redirect', 'not_found', 'server_error' ), true ) ) {
						return __( 'Google’s last visit found a problem (see “What happened”). SearchPilot’s own check shows the page is reachable and indexable now, so notify search engines and use “Inspect now” in a few days to confirm Google re-crawled it.', 'searchpilot-pro' );
					}
					return __( 'Make sure the page returns HTTP 200, is not noindexed or blocked by robots.txt, has a self-referencing canonical and is linked from other pages and the sitemap. Then notify search engines and inspect it again in a few days.', 'searchpilot-pro' );
				},
				'action'   => self::ACTIONS['not_indexed'],
			)
		);

		$r->register(
			'crawled_not_indexed',
			array(
				'label'    => __( 'Crawled – currently not indexed', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					if ( ! empty( $d['last_crawled'] ) ) {
						/* translators: %s: date of Google's last crawl. */
						return sprintf( __( 'Google crawled this page (last crawl %s) but decided not to add it to its index.', 'searchpilot-pro' ), self::human_date( $d['last_crawled'] ) );
					}
					return __( 'Google crawled this page but decided not to add it to its index.', 'searchpilot-pro' );
				},
				'why'      => __( 'Google usually leaves out pages it considers thin, low value or too similar to other pages. The page brings no Google traffic until it is indexed.', 'searchpilot-pro' ),
				'fix'      => __( 'Improve the quality and uniqueness of the content: add original reporting, facts, quotes, images or analysis, and make sure it is not a near-copy of another page. Link to it from related articles and category pages, then notify search engines. Google re-evaluates over days or weeks; indexing is never guaranteed.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['crawled_not_indexed'],
			)
		);

		$r->register(
			'discovered_not_indexed',
			array(
				'label'    => __( 'Discovered – currently not indexed', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'medium',
				'what'     => __( 'Google knows this URL but has not crawled it yet.', 'searchpilot-pro' ),
				'why'      => __( 'Google postpones crawling when a page looks unimportant or when the server seems slow or overloaded. Until it is crawled, the page cannot be indexed.', 'searchpilot-pro' ),
				'fix'      => __( 'Link to the page from prominent pages (home, category and related articles), keep it in the XML sitemap and check server response times — slow responses make Google reduce its crawl rate for the whole site.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['discovered_not_indexed'],
			)
		);

		$r->register(
			'accidental_noindex',
			array(
				'label'       => __( 'Page is set to noindex', 'searchpilot-pro' ),
				'category'    => 'indexing',
				'severity'    => 'high',
				'what'        => static function ( $issue ) {
					$d = $issue['data'];
					if ( 'injected' === ( $d['case'] ?? '' ) ) {
						$source = array(
							'meta'   => __( 'a robots meta tag', 'searchpilot-pro' ),
							'header' => __( 'an X-Robots-Tag HTTP header', 'searchpilot-pro' ),
							'both'   => __( 'a robots meta tag and an X-Robots-Tag header', 'searchpilot-pro' ),
						)[ $d['source'] ?? 'meta' ] ?? __( 'a robots directive', 'searchpilot-pro' );
						/* translators: %s: where the noindex comes from. */
						return sprintf( __( 'WordPress and your SEO settings say this page should be indexed, but the page as served contains noindex (%s). Something else — the theme, another plugin, a server rule or the CDN — adds it.', 'searchpilot-pro' ), $source );
					}
					/* translators: %s: reasons the page looks important. */
					return sprintf( __( 'This page has an explicit noindex setting, but it looks important (%s).', 'searchpilot-pro' ), implode( ', ', (array) ( $d['reasons'] ?? array() ) ) );
				},
				'why'         => __( 'A noindex directive removes the page from search results completely, no matter how good the content is.', 'searchpilot-pro' ),
				'fix'         => static function ( $issue ) {
					if ( 'injected' === ( $issue['data']['case'] ?? '' ) ) {
						return __( 'Find what outputs the directive: the theme’s header template, other SEO, membership or maintenance plugins, and server or CDN rules that add an X-Robots-Tag header. SearchPilot cannot remove directives it does not output. Re-check the URL afterwards.', 'searchpilot-pro' );
					}
					return __( 'If the page should appear in search results, remove the noindex setting. “Fix automatically” clears it in the plugin that manages your robots meta — the change is recorded and can be undone.', 'searchpilot-pro' );
				},
				'fixer'       => array( $this->fixers, 'fix_accidental_noindex' ),
				'fix_label'   => __( 'Remove noindex', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'destructive' => true,
				'action'      => self::ACTIONS['accidental_noindex'],
			)
		);

		$r->register(
			'blocked_by_robots',
			array(
				'label'    => __( 'Blocked by robots.txt', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$d      = $issue['data'];
					$agents = implode( ', ', (array) ( $d['agents'] ?? array( 'Googlebot' ) ) );
					if ( ! empty( $d['rule'] ) ) {
						/* translators: 1: crawler names, 2: robots.txt rule. */
						return sprintf( __( 'robots.txt blocks %1$s from crawling this page (rule “%2$s”).', 'searchpilot-pro' ), $agents, $d['rule'] );
					}
					/* translators: %s: crawler names. */
					return sprintf( __( 'robots.txt blocks %s from crawling this page.', 'searchpilot-pro' ), $agents );
				},
				'why'      => __( 'Blocked pages cannot be crawled, so search engines never see their content. They are dropped or shown without a description.', 'searchpilot-pro' ),
				'fix'      => __( 'Change the robots.txt rule so it no longer matches this URL (SearchPilot → Settings → robots.txt, or the physical robots.txt file on the server), then notify search engines.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['blocked_by_robots'],
			)
		);

		$r->register(
			'canonical_mismatch',
			array(
				'label'    => __( 'Canonical URL mismatch', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					if ( 'google' === ( $d['source'] ?? '' ) ) {
						/* translators: 1: Google's canonical, 2: declared canonical. */
						return sprintf( __( 'Google chose %1$s as the canonical URL instead of the one this page declares (%2$s).', 'searchpilot-pro' ), Url::display( (string) ( $d['google'] ?? '' ) ), Url::display( (string) ( $d['declared'] ?? '' ) ) );
					}
					/* translators: 1: canonical found on the page, 2: expected canonical. */
					return sprintf( __( 'The page declares %1$s as its canonical URL, but WordPress expects %2$s.', 'searchpilot-pro' ), Url::display( (string) ( $d['found'] ?? '' ) ), Url::display( (string) ( $d['expected'] ?? '' ) ) );
				},
				'why'      => __( 'Search engines index the canonical URL. When the page points elsewhere, or Google disagrees with it, this URL can drop out of the results and its ranking signals go to the other URL.', 'searchpilot-pro' ),
				'fix'      => static function ( $issue ) {
					if ( 'google' === ( $issue['data']['source'] ?? '' ) ) {
						return __( 'Make the page clearly distinct from the URL Google picked, and keep internal links, the sitemap and the canonical tag consistently pointing at this URL. If the other URL really is the better page, redirect this one to it.', 'searchpilot-pro' );
					}
					return __( 'Find what prints the wrong canonical — usually the theme or a second SEO plugin — and turn its canonical output off, or correct the canonical field of the post.', 'searchpilot-pro' );
				},
				'action'   => self::ACTIONS['canonical_mismatch'],
			)
		);

		$r->register(
			'canonical_to_non_200',
			array(
				'label'       => __( 'Canonical points to a redirect, error or noindexed page', 'searchpilot-pro' ),
				'category'    => 'indexing',
				'severity'    => 'high',
				'what'        => static function ( $issue ) {
					$d      = $issue['data'];
					$target = Url::display( (string) ( $d['canonical'] ?? '' ) );
					if ( ! empty( $d['target_noindex'] ) ) {
						/* translators: %s: canonical URL. */
						return sprintf( __( 'The canonical tag points to %s, which is set to noindex.', 'searchpilot-pro' ), $target );
					}
					if ( ! empty( $d['target_final'] ) && Url::normalize( (string) $d['target_final'] ) !== Url::normalize( (string) ( $d['canonical'] ?? '' ) ) ) {
						/* translators: 1: canonical URL, 2: final URL, 3: HTTP status. */
						return sprintf( __( 'The canonical tag points to %1$s, which redirects to %2$s (HTTP %3$d).', 'searchpilot-pro' ), $target, Url::display( (string) $d['target_final'] ), (int) ( $d['target_status'] ?? 0 ) );
					}
					/* translators: 1: canonical URL, 2: HTTP status. */
					return sprintf( __( 'The canonical tag points to %1$s, which returns HTTP %2$d.', 'searchpilot-pro' ), $target, (int) ( $d['target_status'] ?? 0 ) );
				},
				'why'         => __( 'Google ignores canonicals that point to redirects, errors or noindexed pages, and may then pick a canonical you did not intend — or drop both URLs.', 'searchpilot-pro' ),
				'fix'         => __( 'Point the canonical at the final, indexable URL, or remove the custom canonical so the page canonicalizes to itself. “Fix automatically” does this in the post’s SEO settings (recorded and undoable).', 'searchpilot-pro' ),
				'fixer'       => array( $this->fixers, 'fix_canonical_target' ),
				'fix_label'   => __( 'Correct canonical', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'destructive' => true,
				'action'      => self::ACTIONS['canonical_to_non_200'],
			)
		);

		$r->register(
			'multiple_canonicals',
			array(
				'label'    => __( 'Conflicting canonical tags', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$list = array_map( array( Url::class, 'display' ), (array) ( $issue['data']['canonicals'] ?? array() ) );
					/* translators: 1: number of canonicals, 2: list. */
					return sprintf( __( 'The page contains %1$d different canonical tags: %2$s.', 'searchpilot-pro' ), count( $list ), implode( ' | ', $list ) );
				},
				'why'      => __( 'With conflicting canonicals Google ignores all of them and chooses a canonical itself, which may not be this page.', 'searchpilot-pro' ),
				'fix'      => __( 'Keep one source of canonical tags: switch canonical output off in every plugin or theme except one. SearchPilot prints a canonical only when it owns that feature (Settings → Feature ownership).', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['multiple_canonicals'],
			)
		);

		$r->register(
			'url_redirect_chain',
			array(
				'label'    => __( 'Redirect chain', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					/* translators: 1: number of redirects, 2: final URL. */
					return sprintf( __( 'Opening this URL passes through %1$d redirects before reaching %2$s.', 'searchpilot-pro' ), (int) ( $d['hops'] ?? 2 ), Url::display( (string) ( $d['final'] ?? '' ) ) );
				},
				'why'      => __( 'Every hop slows page loads and crawling. Long chains waste crawl budget and some crawlers give up before the end.', 'searchpilot-pro' ),
				'fix'      => __( 'Point links, the sitemap and redirect rules directly at the final URL, and replace the chain with a single 301 redirect.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['url_redirect_chain'],
			)
		);

		$r->register(
			'url_redirect_loop',
			array(
				'label'    => __( 'Redirect loop', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					$chain = array_map( array( Url::class, 'display' ), (array) ( $issue['data']['chain'] ?? array() ) );
					if ( $chain ) {
						/* translators: %s: redirect chain. */
						return sprintf( __( 'Opening this URL never reaches a page: the redirects go round in a circle (%s).', 'searchpilot-pro' ), implode( ' → ', $chain ) );
					}
					return __( 'Opening this URL never reaches a page: the redirects go round in a circle or never end.', 'searchpilot-pro' );
				},
				'why'      => __( 'Neither readers nor search engines can open the page, and Google removes it from its index.', 'searchpilot-pro' ),
				'fix'      => __( 'Check the redirect rules (SearchPilot → Redirects, other redirect plugins, .htaccess or server configuration, Cloudflare rules) and remove the rule that sends the URL back to itself.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['url_redirect_loop'],
			)
		);

		$r->register(
			'http_4xx',
			array(
				'label'    => __( 'Page returns a client error (4xx)', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					if ( ! empty( $d['via_redirect'] ) ) {
						/* translators: 1: final URL, 2: status. */
						return sprintf( __( 'This URL redirects to %1$s, which returns HTTP %2$d.', 'searchpilot-pro' ), Url::display( (string) ( $d['final'] ?? '' ) ), (int) ( $d['status'] ?? 404 ) );
					}
					/* translators: %d: HTTP status. */
					return sprintf( __( 'The page returns HTTP %d when SearchPilot opens it.', 'searchpilot-pro' ), (int) ( $d['status'] ?? 404 ) );
				},
				'why'      => __( 'Pages that return client errors are removed from search results, and links pointing to them are wasted.', 'searchpilot-pro' ),
				'fix'      => __( 'Restore the content, or 301-redirect the URL to the closest relevant page and update internal links. If the page was removed on purpose, make sure it is no longer in the sitemap.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['http_4xx'],
			)
		);

		$r->register(
			'http_5xx',
			array(
				'label'    => __( 'Page returns a server error (5xx)', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					/* translators: %d: HTTP status. */
					return sprintf( __( 'The page returned HTTP %d when SearchPilot checked it.', 'searchpilot-pro' ), (int) ( $issue['data']['status'] ?? 500 ) );
				},
				'why'      => __( 'Server errors make Google slow down crawling of the whole site, and pages that keep failing are dropped from the index.', 'searchpilot-pro' ),
				'fix'      => __( 'Check the PHP error log and server resources (memory, timeouts), recently updated plugins and the status of your host and CDN. Re-check the URL once it is fixed.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['http_5xx'],
			)
		);

		$r->register(
			'soft_404',
			array(
				'label'    => __( 'Soft 404', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					if ( 'google' === ( $issue['data']['source'] ?? '' ) ) {
						return __( 'Google classifies this page as a soft 404: it answers HTTP 200 but looks like an error or empty page to Google.', 'searchpilot-pro' );
					}
					return __( 'The page answers HTTP 200 but looks like an error or empty page (it uses the “not found” template or has a “page not found” title).', 'searchpilot-pro' );
				},
				'why'      => __( 'Search engines treat soft 404s like missing pages: they are not indexed, and many of them make the site look low quality.', 'searchpilot-pro' ),
				'fix'      => __( 'If the page is gone, let it return a real 404 or 410, or redirect it to a relevant page. If it should exist, give it substantial, useful content.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['soft_404'],
			)
		);

		$r->register(
			'missing_from_sitemap',
			array(
				'label'     => __( 'Indexable page missing from the sitemap', 'searchpilot-pro' ),
				'category'  => 'sitemap',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					if ( ! empty( $issue['data']['stale'] ) ) {
						return __( 'This page is published and indexable, but the XML sitemap that search engines receive does not list it yet. The cached sitemap looks out of date.', 'searchpilot-pro' );
					}
					if ( ! empty( $issue['data']['owner'] ) ) {
						/* translators: %s: plugin generating the sitemap. */
						return sprintf( __( 'This page is published and indexable, but the sitemap generated by %s does not list it.', 'searchpilot-pro' ), $issue['data']['owner'] );
					}
					return __( 'This page is published and indexable, but the XML sitemap does not list it.', 'searchpilot-pro' );
				},
				'why'       => __( 'Sitemaps help search engines discover new and updated pages quickly — essential for news, where the first hours matter most.', 'searchpilot-pro' ),
				'fix'       => $owns_sitemap
					? __( 'Regenerate the sitemap. “Fix automatically” clears SearchPilot’s sitemap cache and purges cached sitemap pages so the next request lists the page.', 'searchpilot-pro' )
					: __( 'Check the sitemap settings of the plugin that generates your sitemap: this content type or page may be excluded there.', 'searchpilot-pro' ),
				'fixer'     => $owns_sitemap ? array( $this->fixers, 'fix_missing_from_sitemap' ) : null,
				'fix_label' => __( 'Regenerate sitemap', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_SAFE_FIXES,
				'action'    => self::ACTIONS['missing_from_sitemap'],
			)
		);

		$r->register(
			'non_indexable_in_sitemap',
			array(
				'label'    => __( 'Non-indexable URL listed in the sitemap', 'searchpilot-pro' ),
				'category' => 'sitemap',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$reasons = array(
						'noindex'   => __( 'is set to noindex', 'searchpilot-pro' ),
						'redirect'  => __( 'redirects to another URL', 'searchpilot-pro' ),
						'error'     => __( 'returns an error', 'searchpilot-pro' ),
						'canonical' => __( 'declares another URL as canonical', 'searchpilot-pro' ),
						'soft_404'  => __( 'looks like an error page', 'searchpilot-pro' ),
						'excluded'  => __( 'is excluded from indexing in WordPress', 'searchpilot-pro' ),
					);
					$reason  = $reasons[ $issue['data']['reason'] ?? 'noindex' ] ?? $reasons['noindex'];
					/* translators: %s: reason. */
					return sprintf( __( 'The XML sitemap lists this URL, but it %s.', 'searchpilot-pro' ), $reason );
				},
				'why'      => __( 'Sitemaps should contain only canonical, indexable pages that return HTTP 200. Listing redirects, errors or noindexed pages sends mixed signals and makes search engines trust the sitemap less.', 'searchpilot-pro' ),
				'fix'      => __( 'Fix the page so it is indexable again, or exclude it from the sitemap.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['non_indexable_in_sitemap'],
			)
		);

		$r->register(
			'pagination_canonical',
			array(
				'label'    => __( 'Paginated page canonicalized to page 1', 'searchpilot-pro' ),
				'category' => 'indexing',
				'severity' => 'medium',
				'what'     => __( 'Page 2 of this archive declares page 1 as its canonical URL.', 'searchpilot-pro' ),
				'why'      => __( 'Google then ignores the links on deeper archive pages, so older articles are discovered and refreshed less often.', 'searchpilot-pro' ),
				'fix'      => __( 'Paginated archive pages should use a self-referencing canonical. Check the pagination/canonical setting of your SEO plugin or theme.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['pagination_canonical'],
			)
		);

		$r->register(
			'attachment_pages_enabled',
			array(
				'label'       => __( 'Attachment pages are enabled', 'searchpilot-pro' ),
				'category'    => 'technical',
				'severity'    => 'medium',
				'what'        => static function ( $issue ) {
					/* translators: %s: number of media files. */
					return sprintf( __( 'WordPress attachment pages are switched on: each of your %s uploaded files has its own, almost empty page.', 'searchpilot-pro' ), number_format_i18n( (int) ( $issue['data']['attachments'] ?? 0 ) ) );
				},
				'why'         => __( 'Attachment pages have almost no content. Search engines spend crawl budget on them instead of your articles and may index them as thin pages.', 'searchpilot-pro' ),
				'fix'         => __( 'Turn attachment pages off. “Fix automatically” sets the WordPress option wp_attachment_pages_enabled to 0 so attachment URLs redirect to the file — recorded and undoable.', 'searchpilot-pro' ),
				'fixer'       => array( $this->fixers, 'fix_attachment_pages' ),
				'fix_label'   => __( 'Disable attachment pages', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'destructive' => true,
				'action'      => self::ACTIONS['attachment_pages_enabled'],
			)
		);

		$r->register(
			'loopback_blocked',
			array(
				'label'    => __( 'SearchPilot cannot check your pages', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$d = $issue['data'];
					return sprintf(
						/* translators: 1: number of failures, 2: error message, 3: time. */
						__( 'SearchPilot could not open pages of your own site %1$d times in a row (%2$s). Page checks are paused until %3$s and then retried.', 'searchpilot-pro' ),
						(int) ( $d['failures'] ?? 5 ),
						(string) ( $d['error'] ?? __( 'no response', 'searchpilot-pro' ) ),
						self::human_date( (string) ( $d['paused_until'] ?? '' ) )
					);
				},
				'why'      => __( 'Without its own page checks SearchPilot cannot see noindex tags, broken canonicals, redirects or errors added by the theme, other plugins or the server.', 'searchpilot-pro' ),
				'fix'      => __( 'Allow requests from your server to itself: whitelist the server IP or the SearchPilot user agent in security plugins and firewalls, exclude loopback requests from HTTP basic authentication, and let your own origin pass Cloudflare bot protection. You can also enter the origin server IP in Settings → Indexing so SearchPilot connects to the server directly.', 'searchpilot-pro' ),
				'action'   => self::ACTIONS['loopback_blocked'],
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Detection
	 * --------------------------------------------------------------------- */

	/**
	 * Evaluates every URL issue type for a row, upserts what applies and
	 * resolves what no longer does. Stores the recommended action.
	 *
	 * @param array      $row        Hydrated registry row.
	 * @param array|null $details    Latest crawl details (null = load stored ones).
	 * @param array|null $inspection Latest URL Inspection result (null = load stored one).
	 * @return array{types:string[],action:string}
	 */
	public function detect( array $row, $details = null, $inspection = null ) {
		list( $object_type, $object_id ) = $this->issue_object( $row );

		if ( 'active' !== ( $row['status'] ?? 'active' ) ) {
			$this->issues->resolve_stale_for_object( $object_type, $object_id, self::URL_TYPES, array() );
			return array(
				'types'  => array(),
				'action' => '',
			);
		}

		if ( null === $details ) {
			$details = get_transient( 'searchpilot_crawl_' . (int) $row['id'] );
			$details = is_array( $details ) ? $details : array();
		}
		if ( null === $inspection ) {
			$inspection = get_transient( 'searchpilot_inspection_' . (int) $row['id'] );
			$inspection = is_array( $inspection ) ? $inspection : array();
		}

		$found   = $this->evaluate( $row, $details, $inspection );
		$checked = self::URL_TYPES;
		if ( $this->conflicts->owns( 'sitemap' ) ) {
			// In SearchPilot's own sitemap, membership is verified against the published output by the monitor.
			$checked = array_values( array_diff( $checked, array( 'missing_from_sitemap' ) ) );
			unset( $found['missing_from_sitemap'] );
		}

		$ids   = array();
		$title = $this->title_for( $row );
		foreach ( $found as $type => $issue ) {
			$ids[] = $this->issues->upsert(
				$type,
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'url'         => (string) $row['url'],
					'severity'    => $issue['severity'],
					'title'       => $title,
					'data'        => array_merge( $issue['data'], array( 'row_id' => (int) $row['id'] ) ),
				)
			);
		}
		$this->issues->resolve_stale_for_object( $object_type, $object_id, $checked, $ids );

		$action = self::recommended_action( $found );
		if ( ( $row['recommended_action'] ?? '' ) !== $action ) {
			$this->urls->update( (int) $row['id'], array( 'recommended_action' => $action ) );
		}
		return array(
			'types'  => array_keys( $found ),
			'action' => $action,
		);
	}

	/**
	 * Computes applicable issues for a row (no writes).
	 *
	 * @param array $row        Row.
	 * @param array $details    Crawl details.
	 * @param array $inspection Inspection result.
	 * @return array<string,array{severity:string,data:array}>
	 */
	public function evaluate( array $row, array $details, array $inspection ) {
		$found      = array();
		$codes      = (array) ( $row['errors'] ?? array() );
		$has        = static function ( $code ) use ( $codes ) {
			return in_array( $code, $codes, true );
		};
		$indexable  = ! empty( $row['indexable'] );
		$in_sitemap = ! empty( $row['in_sitemap'] ) && $this->sitemap_data_trusted();
		$checked    = ! empty( $row['last_checked'] );
		$important  = $this->importance( $row );
		$is_home    = $important['home'];
		$status     = (int) ( $details['status'] ?? $row['http_status'] ?? 0 );
		$final      = (string) ( $details['final_url'] ?? $row['redirect_to'] ?? '' );
		$discouraged = '0' === (string) get_option( 'blog_public', '1' );

		if ( $checked ) {
			if ( $has( 'redirect_loop' ) || $has( 'too_many_redirects' ) ) {
				$chain = array();
				foreach ( (array) ( $details['hops'] ?? array() ) as $hop ) {
					if ( ! empty( $hop['url'] ) ) {
						$chain[] = (string) $hop['url'];
					}
				}
				$found['url_redirect_loop'] = array(
					'severity' => 'critical',
					'data'     => array(
						'chain'    => array_slice( $chain, 0, 8 ),
						'too_many' => $has( 'too_many_redirects' ),
					),
				);
			} elseif ( $has( 'redirect_chain' ) ) {
				$found['url_redirect_chain'] = array(
					'severity' => $important['important'] && $in_sitemap ? 'high' : 'medium',
					'data'     => array(
						'hops'  => max( 2, (int) ( $details['hop_count'] ?? 2 ) ),
						'final' => $final,
						'chain' => array_slice( array_values( array_filter( wp_list_pluck( (array) ( $details['hops'] ?? array() ), 'url' ) ) ), 0, 8 ),
					),
				);
			}

			if ( $has( 'http_5xx' ) ) {
				$found['http_5xx'] = array(
					'severity' => $is_home ? 'critical' : 'high',
					'data'     => array( 'status' => $status ),
				);
			} elseif ( $has( 'http_4xx' ) ) {
				$found['http_4xx'] = array(
					'severity' => $is_home ? 'critical' : ( ( $indexable || $in_sitemap ) ? 'high' : 'medium' ),
					'data'     => array(
						'status'       => $status,
						'final'        => $final,
						'via_redirect' => $has( 'redirect_to_error' ),
					),
				);
			}

			if ( $has( 'soft_404' ) && $indexable ) {
				$found['soft_404'] = array(
					'severity' => 'high',
					'data'     => array(
						'source' => 'crawl',
						'reason' => (string) ( $details['soft_404_reason'] ?? '' ),
					),
				);
			}

			if ( $has( 'noindex' ) && $indexable && ! $discouraged ) {
				$found['accidental_noindex'] = array(
					'severity' => 'critical',
					'data'     => array(
						'case'       => 'injected',
						'source'     => (string) ( $details['noindex_source'] ?? 'meta' ),
						'directives' => array_slice( (array) ( $details['directives'] ?? array() ), 0, 10 ),
					),
				);
			}

			if ( $has( 'canonical_to_non_200' ) ) {
				$target                        = (array) ( $details['canonical_target'] ?? array() );
				$found['canonical_to_non_200'] = array(
					'severity' => 'high',
					'data'     => array(
						'canonical'      => (string) ( $target['url'] ?? $row['canonical_url'] ?? '' ),
						'target_status'  => (int) ( $target['status'] ?? 0 ),
						'target_final'   => (string) ( $target['final'] ?? '' ),
						'target_noindex' => ! empty( $target['noindex'] ),
						'custom'         => $this->custom_canonical( $row ),
					),
				);
			}

			if ( $has( 'multiple_canonicals' ) ) {
				$list = array();
				foreach ( (array) ( $details['canonicals'] ?? array() ) as $c ) {
					$list[ Url::normalize( (string) ( $c['url'] ?? '' ) ) ] = (string) ( $c['url'] ?? '' );
				}
				$found['multiple_canonicals'] = array(
					'severity' => 'high',
					'data'     => array( 'canonicals' => array_values( array_slice( $list, 0, 5 ) ) ),
				);
			}

			if ( $has( 'canonical_elsewhere' ) && $indexable && ! isset( $found['multiple_canonicals'] ) ) {
				$found['canonical_mismatch'] = array(
					'severity' => 'high',
					'data'     => array(
						'source'   => 'page',
						'found'    => (string) ( $row['canonical_url'] ?? '' ),
						'expected' => $this->expected_canonical( $row ),
					),
				);
			}

			if ( $has( 'pagination_canonical' ) && 'pagination' === ( $row['object_type'] ?? '' ) ) {
				$found['pagination_canonical'] = array(
					'severity' => 'medium',
					'data'     => array( 'canonical' => (string) ( $row['canonical_url'] ?? '' ) ),
				);
			}

			$page_ok = $status >= 200 && $status < 300 && ! $has( 'noindex' ) && ! $has( 'canonical_elsewhere' ) && ! $has( 'soft_404' ) && ! $has( 'redirect' ) && ! $has( 'redirect_chain' ) && empty( $row['robots_txt_blocked'] );

			if ( $in_sitemap ) {
				$reason = '';
				if ( ! $indexable ) {
					$reason = 'excluded';
				} elseif ( $has( 'http_4xx' ) || $has( 'http_5xx' ) || $has( 'redirect_loop' ) || $has( 'too_many_redirects' ) ) {
					$reason = 'error';
				} elseif ( $has( 'redirect' ) || $has( 'redirect_chain' ) || $has( 'redirect_external' ) ) {
					$reason = 'redirect';
				} elseif ( $has( 'noindex' ) ) {
					$reason = 'noindex';
				} elseif ( $has( 'canonical_elsewhere' ) ) {
					$reason = 'canonical';
				} elseif ( $has( 'soft_404' ) ) {
					$reason = 'soft_404';
				}
				if ( '' !== $reason ) {
					$found['non_indexable_in_sitemap'] = array(
						'severity' => 'medium',
						'data'     => array( 'reason' => $reason ),
					);
				}
			} elseif ( $page_ok && $indexable && in_array( $row['object_type'] ?? '', array( 'post', 'term', 'author' ), true ) && $this->sitemap_data_trusted() && $this->older_than( $row, DAY_IN_SECONDS ) && ! $this->intentionally_excluded( $row ) ) {
				// A day of grace: the sitemap owner's contents are re-read periodically.
				$owner                         = $this->conflicts->owner( 'sitemap' );
				$plugins                       = $this->conflicts->detect();
				$found['missing_from_sitemap'] = array(
					'severity' => 'medium',
					'data'     => array( 'owner' => (string) ( $plugins[ $owner ]['name'] ?? '' ) ),
				);
			}
		}

		// Explicit noindex on a page that looks important (no crawl needed).
		if ( ! isset( $found['accidental_noindex'] ) && 'post' === ( $row['object_type'] ?? '' ) && 'noindex' === ( $row['indexability_reason'] ?? '' ) && $important['strong'] && ! $discouraged ) {
			$found['accidental_noindex'] = array(
				'severity' => 'high',
				'data'     => array(
					'case'    => 'explicit',
					'reasons' => $important['reasons'],
				),
			);
		}

		if ( ! empty( $row['robots_txt_blocked'] ) && $indexable ) {
			$robots                     = (array) ( $details['robots'] ?? array() );
			$found['blocked_by_robots'] = array(
				'severity' => ( $is_home || (int) ( $row['priority'] ?? 0 ) >= 90 ) ? 'critical' : 'high',
				'data'     => array(
					'agents' => ! empty( $robots['agents'] ) ? array_values( (array) $robots['agents'] ) : array( 'Googlebot' ),
					'rule'   => (string) ( $robots['rule'] ?? '' ),
				),
			);
		}

		// Google's view (URL Inspection).
		if ( ! empty( $row['last_inspected'] ) && $indexable ) {
			$index_status = (string) ( $row['index_status'] ?? 'unknown' );
			$coverage     = (string) ( $row['coverage_state'] ?? '' );
			$google       = (string) ( $row['google_canonical'] ?? '' );
			$declared     = (string) ( $inspection['user_canonical'] ?? '' );
			if ( '' === $declared ) {
				$declared = '' !== (string) ( $row['canonical_url'] ?? '' ) ? (string) $row['canonical_url'] : (string) $row['url'];
			}
			$explained = isset( $found['accidental_noindex'] ) || isset( $found['http_4xx'] ) || isset( $found['http_5xx'] ) || isset( $found['url_redirect_loop'] ) || isset( $found['blocked_by_robots'] ) || isset( $found['canonical_mismatch'] );

			if ( 'soft_404' === $index_status && ! isset( $found['soft_404'] ) ) {
				$found['soft_404'] = array(
					'severity' => 'high',
					'data'     => array(
						'source'         => 'google',
						'coverage_state' => $coverage,
					),
				);
			} elseif ( 'duplicate_canonical' === $index_status && '' !== $google && Url::normalize( $google ) !== Url::normalize( $declared ) && ! isset( $found['canonical_mismatch'] ) ) {
				$found['canonical_mismatch'] = array(
					'severity' => $important['important'] ? 'high' : 'medium',
					'data'     => array(
						'source'         => 'google',
						'google'         => $google,
						'declared'       => $declared,
						'coverage_state' => $coverage,
					),
				);
			} elseif ( 'crawled_not_indexed' === $index_status ) {
				$found['crawled_not_indexed'] = array(
					'severity' => $important['important'] ? 'high' : 'medium',
					'data'     => array(
						'coverage_state' => $coverage,
						'last_crawled'   => (string) ( $row['last_crawled'] ?? '' ),
					),
				);
			} elseif ( 'discovered_not_indexed' === $index_status && $this->older_than( $row, self::NEW_URL_GRACE ) ) {
				$found['discovered_not_indexed'] = array(
					'severity' => $important['important'] ? 'high' : 'medium',
					'data'     => array( 'coverage_state' => $coverage ),
				);
			} elseif ( in_array( $index_status, array( 'not_indexed', 'unknown_to_google', 'excluded_noindex', 'blocked_robots', 'redirect', 'not_found', 'server_error' ), true ) && ! $explained ) {
				if ( 'unknown_to_google' !== $index_status || $this->older_than( $row, self::NEW_URL_GRACE ) ) {
					$found['not_indexed'] = array(
						'severity' => $important['important'] ? 'high' : 'medium',
						'data'     => array(
							'index_status'   => $index_status,
							'coverage_state' => '' !== $coverage ? $coverage : (string) ( $inspection['coverage_state'] ?? '' ),
						),
					);
				}
			}
		}

		return $found;
	}

	/**
	 * Resolves every URL issue of a row (row removed).
	 *
	 * @param array $row Row.
	 */
	public function resolve_all( array $row ) {
		list( $object_type, $object_id ) = $this->issue_object( $row );
		$this->issues->resolve_stale_for_object( $object_type, $object_id, self::URL_TYPES, array() );
	}

	/**
	 * Object key used for a row's issues. WordPress objects use their own id
	 * (so the editor sidebar finds them); other URLs use the registry row id.
	 *
	 * @param array $row Row.
	 * @return array{0:string,1:int}
	 */
	public function issue_object( array $row ) {
		$type = (string) ( $row['object_type'] ?? '' );
		$id   = (int) ( $row['object_id'] ?? 0 );
		if ( $id > 0 && in_array( $type, array( 'post', 'attachment', 'term', 'author' ), true ) ) {
			return array( $type, $id );
		}
		return array( 'url', (int) ( $row['id'] ?? 0 ) );
	}

	/**
	 * How important a URL is, with human-readable reasons.
	 *
	 * @param array $row Row.
	 * @return array{important:bool,home:bool,reasons:string[],clicks:int}
	 */
	public function importance( array $row ) {
		$reasons = array();
		$home    = 'home' === ( $row['object_type'] ?? '' ) || Url::normalize( (string) ( $row['url'] ?? '' ) ) === Url::normalize( home_url( '/' ) );
		if ( $home ) {
			$reasons[] = __( 'home page', 'searchpilot-pro' );
		}
		$post_id = 'post' === ( $row['object_type'] ?? '' ) ? (int) ( $row['object_id'] ?? 0 ) : 0;
		if ( $post_id && $this->meta->get_post_field( $post_id, 'cornerstone' ) ) {
			$reasons[] = __( 'cornerstone content', 'searchpilot-pro' );
		}
		if ( $post_id && $this->in_menu( $post_id ) ) {
			$reasons[] = __( 'linked in a navigation menu', 'searchpilot-pro' );
		}
		$clicks = $post_id ? $this->clicks( $post_id ) : 0;
		if ( $clicks >= 10 ) {
			/* translators: %s: number of clicks. */
			$reasons[] = sprintf( __( '%s Google clicks recently', 'searchpilot-pro' ), number_format_i18n( $clicks ) );
		}
		$strong = ! empty( $reasons );
		if ( ! $strong && (int) ( $row['priority'] ?? 0 ) >= 70 ) {
			$reasons[] = 'page' === ( $row['object_subtype'] ?? '' ) ? __( 'a static page', 'searchpilot-pro' ) : __( 'high priority URL', 'searchpilot-pro' );
		}
		return array(
			'important' => ! empty( $reasons ),
			'strong'    => $strong,
			'home'      => $home,
			'reasons'   => $reasons,
			'clicks'    => $clicks,
		);
	}

	/**
	 * Recommended action code for a set of found issues.
	 *
	 * @param array $found type => {severity, data}.
	 * @return string
	 */
	public static function recommended_action( array $found ) {
		$best      = '';
		$best_rank = 99;
		foreach ( self::URL_TYPES as $type ) {
			if ( ! isset( $found[ $type ] ) ) {
				continue;
			}
			$rank = IssueRegistry::rank( $found[ $type ]['severity'] );
			if ( $rank < $best_rank ) {
				$best_rank = $rank;
				$best      = self::ACTIONS[ $type ] ?? '';
			}
		}
		return $best;
	}

	/**
	 * Canonical WordPress/the SEO plugin should output for a row.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public function expected_canonical( array $row ) {
		$type = (string) ( $row['object_type'] ?? '' );
		$id   = (int) ( $row['object_id'] ?? 0 );
		if ( 'post' === $type && $id ) {
			$custom = $this->meta->canonical( $id );
			if ( '' !== $custom ) {
				return $custom;
			}
			$link = get_permalink( $id );
			return $link ? (string) $link : (string) $row['url'];
		}
		if ( 'term' === $type && $id ) {
			$custom = trim( (string) $this->meta->get_term_field( $id, 'canonical' ) );
			if ( '' !== $custom ) {
				return $custom;
			}
		}
		return (string) ( $row['url'] ?? '' );
	}

	/**
	 * Custom canonical stored in the post's SEO settings ('' = none).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private function custom_canonical( array $row ) {
		if ( 'post' === ( $row['object_type'] ?? '' ) && ! empty( $row['object_id'] ) ) {
			return $this->meta->canonical( (int) $row['object_id'] );
		}
		return '';
	}

	/**
	 * Whether the URL is kept out of the sitemap on purpose.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private function intentionally_excluded( array $row ) {
		$type = (string) ( $row['object_type'] ?? '' );
		if ( 'post' === $type ) {
			if ( $this->meta->get_post_field( (int) $row['object_id'], 'sitemap_exclude' ) ) {
				return true;
			}
			$types = $this->types->post_types();
			return empty( $types[ $row['object_subtype'] ?? '' ]['sitemap'] );
		}
		if ( 'term' === $type ) {
			$taxes = $this->types->taxonomies();
			return empty( $taxes[ $row['object_subtype'] ?? '' ]['sitemap'] );
		}
		if ( 'author' === $type ) {
			return ! $this->settings->get( 'content.authors_sitemap' );
		}
		return true;
	}

	/**
	 * Whether the registry's in_sitemap flags can be trusted: always for
	 * SearchPilot's own sitemap; in companion mode only once the sitemap
	 * module recorded the owner's sitemap contents.
	 *
	 * @return bool
	 */
	public function sitemap_data_trusted() {
		if ( isset( $this->memo['sitemap_trusted'] ) ) {
			return $this->memo['sitemap_trusted'];
		}
		$owner = $this->conflicts->owner( 'sitemap' );
		if ( 'off' === $owner ) {
			$trusted = false;
		} elseif ( $this->conflicts->owns( 'sitemap' ) ) {
			$trusted = true;
		} else {
			global $wpdb;
			$table = Tables::urls();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$trusted = class_exists( '\SearchPilot\Sitemap\SitemapHealth' ) && (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE status = 'active' AND in_sitemap = 1 LIMIT 1" );
		}
		$this->memo['sitemap_trusted'] = $trusted;
		return $trusted;
	}

	/**
	 * Whether a post is linked from a navigation menu.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private function in_menu( $post_id ) {
		if ( ! isset( $this->memo['menu'] ) ) {
			global $wpdb;
			// Menu items that link to posts (not terms or custom links). phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids                = $wpdb->get_col( "SELECT DISTINCT o.meta_value FROM {$wpdb->postmeta} o INNER JOIN {$wpdb->postmeta} t ON t.post_id = o.post_id AND t.meta_key = '_menu_item_type' AND t.meta_value = 'post_type' WHERE o.meta_key = '_menu_item_object_id' LIMIT 2000" );
			$this->memo['menu'] = array_flip( array_map( 'intval', (array) $ids ) );
		}
		return isset( $this->memo['menu'][ (int) $post_id ] );
	}

	/**
	 * Recent Google clicks of a post from stored performance snapshots.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function clicks( $post_id ) {
		$key = 'clicks_' . (int) $post_id;
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		global $wpdb;
		$table    = Tables::perf_rows();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(clicks) FROM {$table} WHERE object_id = %d AND engine = 'google' AND dimension = 'page'", (int) $post_id ) );
		$wpdb->suppress_errors( $suppress );
		$this->memo[ $key ] = $clicks;
		return $clicks;
	}

	/**
	 * Whether the URL's content is older than a number of seconds.
	 *
	 * @param array $row     Row.
	 * @param int   $seconds Seconds.
	 * @return bool
	 */
	private function older_than( array $row, $seconds ) {
		$ref = ! empty( $row['created_at'] ) ? $row['created_at'] : ( $row['last_modified'] ?? '' );
		$ts  = $ref ? strtotime( (string) $ref ) : 0;
		return ! $ts || ( time() - $ts ) > $seconds;
	}

	/**
	 * Issue title for a row.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private function title_for( array $row ) {
		$id = (int) ( $row['object_id'] ?? 0 );
		switch ( $row['object_type'] ?? '' ) {
			case 'post':
			case 'attachment':
				$title = $id ? get_the_title( $id ) : '';
				break;
			case 'term':
				$term  = $id ? get_term( $id ) : null;
				$title = $term && ! is_wp_error( $term ) ? $term->name : '';
				break;
			case 'author':
				$user  = $id ? get_userdata( $id ) : null;
				$title = $user ? $user->display_name : '';
				break;
			case 'home':
				$title = __( 'Home page', 'searchpilot-pro' );
				break;
			default:
				$title = '';
		}
		$title = wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );
		return '' !== $title ? $title : Url::display( (string) ( $row['url'] ?? '' ) );
	}

	/**
	 * Formats a stored date for issue texts (site timezone).
	 *
	 * @param string $value ISO or MySQL UTC date.
	 * @return string
	 */
	public static function human_date( $value ) {
		$ts = $value ? strtotime( (string) $value . ( preg_match( '/(Z|[+\-]\d{2}:?\d{2})$/', (string) $value ) ? '' : ' UTC' ) ) : false;
		if ( ! $ts ) {
			return __( 'unknown', 'searchpilot-pro' );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}
}
