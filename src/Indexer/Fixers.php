<?php
/**
 * Automatic fixes and safe follow-ups for indexing issues.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Indexer;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Changes;
use SearchPilot\Core\Compat\CachePurger;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Database\Tables;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Every change goes through MetaStore/Changes so it appears in the
 * activity log and can be undone. Fixers are called by Issues::fix()
 * (explicit approval) or Issues::auto_fix() (automation policy).
 */
class Fixers {

	/**
	 * Meta store.
	 *
	 * @var MetaStore
	 */
	private $meta;

	/**
	 * Changes.
	 *
	 * @var Changes
	 */
	private $changes;

	/**
	 * URL registry.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Cache purger.
	 *
	 * @var CachePurger
	 */
	private $purger;

	/**
	 * Automation policy.
	 *
	 * @var AutomationPolicy
	 */
	private $policy;

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

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
	 * Issue types whose fix changes how search engines should treat the URL.
	 */
	const RESUBMIT_TYPES = array( 'accidental_noindex', 'canonical_to_non_200', 'missing_from_sitemap', 'blocked_by_robots', 'http_4xx', 'http_5xx', 'soft_404', 'url_redirect_loop', 'canonical_mismatch', 'multiple_canonicals' );

	/**
	 * Constructor.
	 *
	 * @param MetaStore        $meta      Meta store.
	 * @param Changes          $changes   Changes.
	 * @param UrlRepository    $urls      URLs.
	 * @param CachePurger      $purger    Cache purger.
	 * @param AutomationPolicy $policy    Policy.
	 * @param ConflictDetector $conflicts Conflicts.
	 * @param ContentTypes     $types     Content types.
	 * @param Scheduler        $scheduler Scheduler.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( MetaStore $meta, Changes $changes, UrlRepository $urls, CachePurger $purger, AutomationPolicy $policy, ConflictDetector $conflicts, ContentTypes $types, Scheduler $scheduler, Logger $logger ) {
		$this->meta      = $meta;
		$this->changes   = $changes;
		$this->urls      = $urls;
		$this->purger    = $purger;
		$this->policy    = $policy;
		$this->conflicts = $conflicts;
		$this->types     = $types;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	/**
	 * Fixer: clears an explicit noindex through the SEO plugin that owns robots meta.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_accidental_noindex( array $issue ) {
		$post_id = $this->post_id( $issue );
		if ( ! $post_id ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'This URL is not a post or page, so its robots directive cannot be changed automatically. Follow the recommended fix.', 'searchpilot-pro' ) );
		}
		if ( true !== $this->meta->explicit_noindex( $post_id ) ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'The noindex is not stored in this page’s SEO settings — it is added by the theme, another plugin or a server rule, so SearchPilot cannot remove it. Follow the recommended fix, then re-check the URL.', 'searchpilot-pro' ) );
		}
		$result = $this->meta->set_post_field(
			$post_id,
			'noindex',
			false,
			array(
				'source'      => $this->source( $issue ),
				'action'      => 'seo_noindex_removed',
				/* translators: %s: post title. */
				'description' => sprintf( __( 'Removed noindex from “%s” (SEO Audit)', 'searchpilot-pro' ), get_the_title( $post_id ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->purger->purge_post( $post_id );
		$this->recheck( (string) ( $issue['url'] ?? '' ) );
		return true;
	}

	/**
	 * Fixer: points the custom canonical at the final indexable URL, or clears it.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_canonical_target( array $issue ) {
		$post_id = $this->post_id( $issue );
		if ( ! $post_id ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'This URL is not a post or page, so its canonical cannot be changed automatically. Follow the recommended fix.', 'searchpilot-pro' ) );
		}
		$custom = $this->meta->canonical( $post_id );
		if ( '' === $custom ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'This page has no custom canonical in its SEO settings — the wrong canonical is printed by the theme or another plugin. Follow the recommended fix.', 'searchpilot-pro' ) );
		}
		$data      = (array) ( $issue['data'] ?? array() );
		$final     = (string) ( $data['target_final'] ?? '' );
		$status    = (int) ( $data['target_status'] ?? 0 );
		$permalink = (string) get_permalink( $post_id );
		$new       = '';
		if ( '' !== $final && 200 === $status && empty( $data['target_noindex'] ) && Crawler::is_fetchable( $final ) ) {
			$new = $final;
		}
		if ( '' !== $new && Url::normalize( $new ) === Url::normalize( $permalink ) ) {
			$new = ''; // Self-referencing: no custom canonical needed.
		}
		$result = $this->meta->set_post_field(
			$post_id,
			'canonical',
			$new,
			array(
				'source'      => $this->source( $issue ),
				'action'      => 'seo_canonical_corrected',
				'description' => '' === $new
					/* translators: %s: post title. */
					? sprintf( __( 'Removed the broken custom canonical of “%s” (SEO Audit)', 'searchpilot-pro' ), get_the_title( $post_id ) )
					/* translators: 1: post title, 2: URL. */
					: sprintf( __( 'Pointed the canonical of “%1$s” at %2$s (SEO Audit)', 'searchpilot-pro' ), get_the_title( $post_id ), Url::display( $new ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->purger->purge_post( $post_id );
		$this->recheck( (string) ( $issue['url'] ?? '' ) );
		return true;
	}

	/**
	 * Fixer: regenerates SearchPilot's sitemaps (cache version bump + page cache purge).
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_missing_from_sitemap( array $issue ) {
		if ( ! $this->conflicts->owns( 'sitemap' ) ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'Your sitemap is generated by another plugin. Check its sitemap settings for this page.', 'searchpilot-pro' ) );
		}
		$regenerated = $this->regenerate_sitemaps();
		if ( is_wp_error( $regenerated ) ) {
			return $regenerated;
		}

		$row = '' !== (string) ( $issue['url'] ?? '' ) ? $this->urls->get_by_url( (string) $issue['url'] ) : null;
		if ( $row && 'post' === $row['object_type'] && $row['object_id'] ) {
			$this->urls->update( (int) $row['id'], array( 'in_sitemap' => $this->types->post_in_sitemap( (int) $row['object_id'] ) ) );
		}
		$this->recheck( (string) ( $issue['url'] ?? '' ) );
		return true;
	}

	/**
	 * Fixer: turns WordPress attachment pages off.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_attachment_pages( array $issue ) {
		$result = $this->changes->update_option(
			'wp_attachment_pages_enabled',
			0,
			array(
				'source'      => $this->source( $issue ),
				'action'      => 'attachment_pages_disabled',
				'description' => __( 'Disabled WordPress attachment pages (SEO Audit)', 'searchpilot-pro' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		global $wpdb;
		$table = Tables::urls();
		// Attachment URLs now redirect to the file: they are no longer pages of the registry.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'removed', in_sitemap = 0, updated_at = %s WHERE object_type = 'attachment' AND status = 'active'", current_time( 'mysql', true ) ) );
		return true;
	}

	/**
	 * Safe follow-up after any fix: re-check the URL soon and, when the fix
	 * changed how search engines should see it (or it changed since the last
	 * submission), queue a notification through the submission queue.
	 *
	 * @param array  $issue  Fixed issue.
	 * @param string $source Source (user|assisted|autopilot).
	 */
	public function follow_up( array $issue, $source = 'user' ) {
		if ( $this->policy->disabled( AutomationPolicy::TASK_SAFE_FIXES ) ) {
			return;
		}
		$url = (string) ( $issue['url'] ?? '' );
		if ( '' === $url ) {
			return;
		}
		$row = $this->urls->get_by_url( $url );
		if ( ! $row || 'active' !== $row['status'] ) {
			return;
		}
		$this->recheck( $url, $row );

		// Only fixes that change how search engines should treat the URL are worth a
		// notification. Content fixes (ALT text, meta fills) of old posts never are:
		// resubmitting unchanged pages would only flood IndexNow/Bing.
		if ( ! in_array( (string) ( $issue['type'] ?? '' ), self::RESUBMIT_TYPES, true ) ) {
			return;
		}
		if ( empty( $row['indexable'] ) || ! class_exists( '\SearchPilot\Queue\SubmissionQueue' ) ) {
			return;
		}
		try {
			$queue = \SearchPilot\Plugin::instance()->get( '\SearchPilot\Queue\SubmissionQueue' );
			if ( is_object( $queue ) && method_exists( $queue, 'enqueue_url' ) ) {
				$queue->enqueue_url(
					$url,
					'update',
					array(
						'object_type' => (string) $row['object_type'],
						'object_id'   => (int) $row['object_id'],
						'reason'      => 'fix',
						// Queue order is "higher = sooner" (manual 30, publish 20, update 10).
						'priority'    => 15,
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'indexer',
				'Could not queue a submission after a fix: ' . $e->getMessage(),
				array(
					'url'    => $url,
					'source' => $source,
				)
			);
		}
	}

	/**
	 * Marks a URL for an early re-check (the crawler picks never-checked rows first)
	 * and schedules a background check of that row.
	 *
	 * @param string     $url URL.
	 * @param array|null $row Row when already loaded.
	 */
	public function recheck( $url, $row = null ) {
		if ( null === $row && '' !== $url ) {
			$row = $this->urls->get_by_url( $url );
		}
		if ( ! $row ) {
			return;
		}
		if ( in_array( (string) ( $row['object_type'] ?? '' ), array( 'post', 'attachment' ), true ) && \SearchPilot\Audit\Rescanner::scanned_recently( (int) $row['object_id'] ) ) {
			return; // The fix engine just re-checked this page past page caches: no second fetch.
		}
		Crawler::request_recheck( (int) $row['id'] );
		Crawler::schedule_recheck( $this->scheduler, (int) $row['id'], 60 );
	}

	/**
	 * Regenerates SearchPilot's sitemaps through the sitemap module
	 * (Sitemap\SitemapModule::regenerate()), never by touching its cache state.
	 *
	 * @return true|\WP_Error
	 */
	public function regenerate_sitemaps() {
		$class = '\SearchPilot\Sitemap\SitemapModule';
		if ( ! class_exists( $class ) ) {
			return new \WP_Error( 'searchpilot_not_fixable', __( 'The sitemap module is not available, so the sitemap cannot be regenerated.', 'searchpilot-pro' ) );
		}
		try {
			$module = \SearchPilot\Plugin::instance()->get( $class );
			if ( ! is_object( $module ) || ! method_exists( $module, 'regenerate' ) ) {
				return new \WP_Error( 'searchpilot_not_fixable', __( 'The sitemap module cannot regenerate sitemaps.', 'searchpilot-pro' ) );
			}
			// The sitemap module resets its builder, bumps every cache version, purges every
			// child sitemap URL and fires searchpilot_sitemap_changed itself.
			$module->regenerate();
		} catch ( \Throwable $e ) {
			$this->logger->error( 'sitemap', 'Sitemap regeneration failed: ' . $e->getMessage() );
			return new \WP_Error( 'searchpilot_fix_failed', __( 'The sitemap could not be regenerated. Details were written to SearchPilot → System Logs.', 'searchpilot-pro' ) );
		}
		$this->logger->info( 'sitemap', 'Sitemaps regenerated by the SEO Audit.' );
		return true;
	}

	/**
	 * Post id of a post issue (0 otherwise).
	 *
	 * @param array $issue Issue.
	 * @return int
	 */
	private function post_id( array $issue ) {
		if ( in_array( (string) ( $issue['object_type'] ?? '' ), array( 'post', 'attachment' ), true ) && ! empty( $issue['object_id'] ) && get_post( (int) $issue['object_id'] ) ) {
			return (int) $issue['object_id'];
		}
		return 0;
	}

	/**
	 * Activity source of a fix.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	private function source( array $issue ) {
		$source = (string) ( $issue['source'] ?? 'user' );
		return in_array( $source, array( 'user', 'manual', 'assisted', 'autopilot' ), true ) ? $source : 'user';
	}
}
