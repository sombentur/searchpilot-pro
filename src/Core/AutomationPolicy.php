<?php
/**
 * Automation mode policy.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, per task, whether SearchPilot may act on its own, must ask for
 * approval, or only recommend:
 *
 *   Manual    — recommendations only; submissions are held for one-click release.
 *   Assisted  — safe technical/indexing work is automatic; content changes need approval.
 *   Autopilot — maximum safe automation inside search-engine guidelines.
 *
 * Editorial content (body text, titles written by people) is never
 * rewritten automatically in any mode.
 */
class AutomationPolicy {

	const AUTO     = 'auto';
	const APPROVAL = 'approval';
	const OFF      = 'off';

	// Notify search engines about new/changed/removed URLs.
	const TASK_SUBMIT_URLS = 'submit_urls';
	// Submit sitemaps to Google/Bing after meaningful changes.
	const TASK_SUBMIT_SITEMAPS = 'submit_sitemaps';
	// Non-destructive technical fixes (sitemap hygiene, cache purges, re-checks).
	const TASK_SAFE_FIXES = 'safe_fixes';
	// 301 redirect when a published URL changes.
	const TASK_SLUG_REDIRECTS = 'slug_redirects';
	// Fill EMPTY SEO meta (description from excerpt) — never overwrite.
	const TASK_FILL_META = 'fill_meta';
	// Fill EMPTY image ALT text from the attachment title — never overwrite.
	const TASK_FILL_ALT = 'fill_alt';
	// Insert internal links within the admin's limits.
	const TASK_INTERNAL_LINKS = 'internal_links';
	// Fixes that change behaviour visibly (redirect loops, canonical changes, noindex changes).
	const TASK_TECHNICAL_CHANGES = 'technical_changes';
	// Structure and search appearance without changing the words readers see: heading levels,
	// extra H1s, trimming an over-long SEO title (never the headline). Automatic in Autopilot only.
	const TASK_SEO_STRUCTURE = 'seo_structure';
	// Repairs around the article that never touch its text: shorten an over-long meta description
	// to whole sentences, use the first image as the featured image, re-compress oversized image
	// files (original kept). Automatic in Autopilot only.
	const TASK_PAGE_REPAIRS = 'page_repairs';
	// Editorial content (titles, body copy). Always approval.
	const TASK_CONTENT = 'content';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Mode matrix: task => [manual, assisted, autopilot].
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function matrix() {
		return array(
			self::TASK_SUBMIT_URLS        => array( self::APPROVAL, self::AUTO, self::AUTO ),
			self::TASK_SUBMIT_SITEMAPS    => array( self::APPROVAL, self::AUTO, self::AUTO ),
			self::TASK_SAFE_FIXES         => array( self::APPROVAL, self::AUTO, self::AUTO ),
			self::TASK_SLUG_REDIRECTS     => array( self::APPROVAL, self::AUTO, self::AUTO ),
			self::TASK_FILL_META          => array( self::APPROVAL, self::APPROVAL, self::AUTO ),
			self::TASK_FILL_ALT           => array( self::APPROVAL, self::APPROVAL, self::AUTO ),
			self::TASK_INTERNAL_LINKS     => array( self::APPROVAL, self::APPROVAL, self::AUTO ),
			self::TASK_SEO_STRUCTURE      => array( self::APPROVAL, self::APPROVAL, self::AUTO ),
			self::TASK_PAGE_REPAIRS       => array( self::APPROVAL, self::APPROVAL, self::AUTO ),
			self::TASK_TECHNICAL_CHANGES  => array( self::APPROVAL, self::APPROVAL, self::APPROVAL ),
			self::TASK_CONTENT            => array( self::APPROVAL, self::APPROVAL, self::APPROVAL ),
		);
	}

	/**
	 * Labels and descriptions for the UI.
	 *
	 * @return array<string,array{label:string,description:string,locked:bool}>
	 */
	public static function tasks() {
		return array(
			self::TASK_SUBMIT_URLS       => array(
				'label'       => __( 'Notify search engines', 'searchpilot-pro' ),
				'description' => __( 'Send new, updated and removed URLs to IndexNow, Bing and (for eligible pages only) the Google Indexing API.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_SUBMIT_SITEMAPS   => array(
				'label'       => __( 'Submit sitemaps', 'searchpilot-pro' ),
				'description' => __( 'Resubmit sitemaps to Google Search Console and Bing Webmaster Tools after meaningful changes (throttled).', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_SAFE_FIXES        => array(
				'label'       => __( 'Safe technical fixes', 'searchpilot-pro' ),
				'description' => __( 'Keep sitemaps clean, purge stale caches, re-check URLs and re-queue failed submissions.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_SLUG_REDIRECTS    => array(
				'label'       => __( 'Redirect changed URLs', 'searchpilot-pro' ),
				'description' => __( 'Create a 301 redirect when the address of a published post or page changes.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_FILL_META         => array(
				'label'       => __( 'Fill missing meta descriptions', 'searchpilot-pro' ),
				'description' => __( 'Only empty descriptions are filled, from the excerpt or opening paragraph. Existing text is never changed.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_FILL_ALT          => array(
				'label'       => __( 'Fill missing image ALT text', 'searchpilot-pro' ),
				'description' => __( 'Only empty ALT text is filled, from the image title. Existing ALT text is never changed.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_INTERNAL_LINKS    => array(
				'label'       => __( 'Insert internal links', 'searchpilot-pro' ),
				'description' => __( 'Link existing phrases to related articles within your limits. Requires “Automatic insertion” in Internal Links settings.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_SEO_STRUCTURE     => array(
				'label'       => __( 'Headings and SEO titles', 'searchpilot-pro' ),
				'description' => __( 'Fix skipped heading levels and extra H1s, and shorten over-long SEO titles. The headline and the article text are never changed. Every change is previewed in the activity log and can be undone.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_PAGE_REPAIRS      => array(
				'label'       => __( 'Descriptions, featured images and image files', 'searchpilot-pro' ),
				'description' => __( 'Shorten over-long meta descriptions to whole sentences, use the article’s first image as its featured image and re-compress oversized image files (the original file is kept). The article text is never changed. Every change is checked on the live page, logged and can be undone.', 'searchpilot-pro' ),
				'locked'      => false,
			),
			self::TASK_TECHNICAL_CHANGES => array(
				'label'       => __( 'Visible technical changes', 'searchpilot-pro' ),
				'description' => __( 'Changing canonicals, robots directives or existing redirects always asks first.', 'searchpilot-pro' ),
				'locked'      => true,
			),
			self::TASK_CONTENT           => array(
				'label'       => __( 'Editorial content', 'searchpilot-pro' ),
				'description' => __( 'Titles and body copy are never rewritten without your approval.', 'searchpilot-pro' ),
				'locked'      => true,
			),
		);
	}

	/**
	 * Current mode.
	 *
	 * @return string manual|assisted|autopilot
	 */
	public function mode() {
		return (string) $this->settings->get( 'general.mode', 'assisted' );
	}

	/**
	 * Effective policy for a task.
	 *
	 * @param string $task Task constant.
	 * @return string auto|approval|off
	 */
	public function policy( $task ) {
		$matrix = self::matrix();
		if ( ! isset( $matrix[ $task ] ) ) {
			return self::APPROVAL;
		}
		$index  = array( 'manual' => 0, 'assisted' => 1, 'autopilot' => 2 )[ $this->mode() ] ?? 1;
		$policy = $matrix[ $task ][ $index ];

		$tasks = self::tasks();
		if ( empty( $tasks[ $task ]['locked'] ) ) {
			$override = (string) ( $this->settings->get( 'automation.tasks' )[ $task ]['policy'] ?? 'default' );
			if ( in_array( $override, array( self::AUTO, self::APPROVAL, self::OFF ), true ) ) {
				$policy = $override;
			}
		}
		if ( self::TASK_INTERNAL_LINKS === $task && self::AUTO === $policy && ! $this->settings->get( 'links.auto_insert' ) ) {
			$policy = self::APPROVAL;
		}
		/**
		 * Filters the automation policy of a task.
		 *
		 * @param string $policy auto|approval|off.
		 * @param string $task   Task.
		 * @param string $mode   Mode.
		 */
		return (string) apply_filters( 'searchpilot_automation_policy', $policy, $task, $this->mode() );
	}

	/**
	 * Whether the task may run without asking.
	 *
	 * @param string $task Task.
	 * @return bool
	 */
	public function allows( $task ) {
		return self::AUTO === $this->policy( $task );
	}

	/**
	 * Whether the task needs approval first.
	 *
	 * @param string $task Task.
	 * @return bool
	 */
	public function needs_approval( $task ) {
		return self::APPROVAL === $this->policy( $task );
	}

	/**
	 * Whether the task is disabled.
	 *
	 * @param string $task Task.
	 * @return bool
	 */
	public function disabled( $task ) {
		return self::OFF === $this->policy( $task );
	}

	/**
	 * Activity "source" label for automatic changes.
	 *
	 * @return string assisted|autopilot|manual
	 */
	public function source() {
		return $this->mode();
	}
}
