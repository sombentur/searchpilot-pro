<?php
/**
 * Content analysis module: background analysis, content issues, fixers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo\Analysis;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\State;
use SearchPilot\Core\TimeBudget;
use SearchPilot\Fixes\ContentEditor;
use SearchPilot\Fixes\Fixers\DescriptionFixer;
use SearchPilot\Fixes\Fixers\HeadingFixer;
use SearchPilot\Fixes\Fixers\TitleFixer;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Seo\MetaStore;
use SearchPilot\Support\HtmlDocument;
use SearchPilot\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Analyzes posts in the background (shortly after each save, plus an
 * hourly backlog pass over posts never analyzed or edited since), stores
 * fingerprints and scores in the `content` table and keeps the content
 * issues of every published post current.
 *
 * Every analysis rewrites the `data` of each issue it reports from that
 * fresh result (never merged with an older snapshot), so planners in
 * Fixes\Fixers (attached by FixersModule) always see what the page shows
 * now. The legacy one-click fixer for an EMPTY meta description stays
 * here; everything else is planned, previewed and verified through the
 * fix engine. Body text is never rewritten.
 */
class AnalysisModule implements Module {

	/**
	 * Issue types this module keeps in sync after each analysis.
	 */
	const ISSUE_TYPES = array( 'missing_meta_description', 'title_length', 'description_length', 'duplicate_title', 'duplicate_description', 'thin_content', 'near_duplicate_content', 'heading_structure', 'missing_alt_text', 'missing_featured_image', 'no_internal_links', 'outdated_title_year', 'stale_content', 'large_images' );

	const BACKLOG_MAX = 100;

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Container $container, Scheduler $scheduler ) {
		$this->container = $container;
		$this->scheduler = $scheduler;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'save_post', array( $this, 'on_save' ), 25, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 20, 1 );

		add_action( 'searchpilot_analysis_drain', array( $this, 'drain' ) );
		add_action( 'searchpilot_analysis_backlog', array( $this, 'run_backlog' ) );
		// Heavy work runs in its own event, so it gets a fresh request time budget.
		add_action( 'searchpilot_hourly', array( $this, 'schedule_backlog' ), 40 );
		add_action( 'searchpilot_initial_sync', array( $this, 'schedule_backlog' ), 40 );
		// Events scheduled by earlier versions (one per post) are folded into the queue.
		add_action( 'searchpilot_analyze_post', array( $this, 'legacy_analyze_post' ), 10, 1 );

		add_action( 'searchpilot_page_crawled', array( $this, 'on_page_crawled' ), 20, 1 );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard' ) );
	}

	/**
	 * Service shortcut.
	 *
	 * @param string $class Class.
	 * @return object
	 */
	private function get( $class ) {
		return $this->container->get( $class );
	}

	/**
	 * Whether a post can be analyzed (public type, real content).
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function is_analyzable( $post ) {
		return $post instanceof \WP_Post
			&& ! in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true )
			&& isset( $this->get( ContentTypes::class )->post_types()[ $post->post_type ] );
	}

	/* ------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * Marks a saved post for analysis; one deduplicated drain event does the work.
	 * Imports and autosaves are skipped (the hourly backlog catches those posts).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ) {
		if ( ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $this->is_analyzable( $post ) ) {
			return;
		}
		$this->mark( array( (int) $post_id ) );
	}

	/**
	 * Queues posts for analysis (analyzed_at = NULL in the content table) and
	 * schedules the single drain event.
	 *
	 * @param int[] $ids   Post ids.
	 * @param int   $delay Seconds before the drain runs.
	 */
	public function mark( array $ids, $delay = 30 ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$this->get( AnalysisRepository::class )->mark_for_analysis( $ids );
		$this->scheduler->enqueue_async( 'searchpilot_analysis_drain', array(), $delay );
	}

	/**
	 * Legacy per-post event: fold into the queue.
	 *
	 * @param int $post_id Post id.
	 */
	public function legacy_analyze_post( $post_id ) {
		$this->mark( array( (int) $post_id ), 5 );
	}

	/**
	 * Drains queued posts within a time budget, re-queueing itself while work remains.
	 */
	public function drain() {
		$more = Lock::run(
			'analysis',
			300,
			function () {
				$budget = new TimeBudget( 20 );
				$repo   = $this->get( AnalysisRepository::class );
				foreach ( $repo->pending_analysis( 25 ) as $id ) {
					if ( $budget->exhausted() ) {
						break;
					}
					if ( null === $this->run_analyze_post( $id ) ) {
						$repo->touch_analyzed( $id ); // Not analyzable (or failed): do not retry forever.
					}
				}
				return (bool) $repo->pending_analysis( 1 );
			}
		);
		if ( null === $more || $more ) {
			$this->scheduler->enqueue_async( 'searchpilot_analysis_drain', array(), null === $more ? 60 : 15 );
		}
	}

	/**
	 * Unpublished posts lose their content issues.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && $post instanceof \WP_Post ) {
			$this->resolve_all( (int) $post->ID );
		}
	}

	/**
	 * Deleted posts: drop the row and the issues.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! isset( $this->get( ContentTypes::class )->post_types()[ $post->post_type ] ) ) {
			return;
		}
		$this->get( AnalysisRepository::class )->delete( $post_id );
		$this->resolve_all( (int) $post_id );
	}

	/**
	 * Resolves every issue this module reports for a post.
	 *
	 * @param int $post_id Post id.
	 */
	private function resolve_all( $post_id ) {
		$this->get( IssueRepository::class )->resolve_stale_for_object( 'post', $post_id, array_merge( self::ISSUE_TYPES, array( 'multiple_h1' ) ), array() );
	}

	/* ------------------------------------------------------------------
	 * Analysis
	 * ------------------------------------------------------------------ */

	/**
	 * Analyzes a post, logging (never throwing) errors.
	 *
	 * @param int $post_id Post id.
	 * @return array|null Analysis, or null when not analyzable or failed.
	 */
	public function run_analyze_post( $post_id ) {
		try {
			return $this->analyze_post( (int) $post_id );
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->warning( 'audit', sprintf( 'Content analysis of post %d failed: %s', (int) $post_id, $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Analyzes a stored post, saves the result and syncs its issues.
	 *
	 * @param int $post_id Post id.
	 * @return array|null Analysis (contract shape) or null.
	 */
	public function analyze_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $this->is_analyzable( $post ) ) {
			return null;
		}
		$repo     = $this->get( AnalysisRepository::class );
		$result   = $this->get( Analyzer::class )->analyze( array( 'post_id' => $post->ID ) );
		$fp       = $result['fingerprint'];
		$previous = $repo->get( $post->ID );
		$old_dups = is_array( $previous['analysis']['dups'] ?? null ) ? $previous['analysis']['dups'] : array();
		$new_dups = is_array( $fp['duplicate_ids'] ?? null ) ? $fp['duplicate_ids'] : array();

		$failing = array();
		foreach ( $result['checks'] as $check ) {
			if ( in_array( $check['status'], array( 'fail', 'warning' ), true ) ) {
				$failing[] = array(
					'id'     => $check['id'],
					'group'  => $check['group'],
					'status' => $check['status'],
				);
			}
		}
		$analysis = array(
			'v'            => 1,
			'score'        => $result['score'],
			'grade'        => $result['grade'],
			'failing'      => $failing,
			'stats'        => array_intersect_key( $result['stats'], array_flip( array( 'words', 'internal_links', 'external_links', 'images', 'images_missing_alt', 'focus_density', 'reading_time', 'page_builder' ) ) ),
			'focus'        => $result['focus'],
			'dups'         => $new_dups,
			'rendered'     => $previous['analysis']['rendered'] ?? null,
			'description'  => $result['effective']['description'],
			'title'        => $result['effective']['title'],
			'analyzed_ver' => SEARCHPILOT_VERSION,
		);
		$repo->save_analysis( $post->ID, (int) $result['stats']['words'], (string) $fp['content_hash'], (string) $fp['simhash'], $result['effective']['title'], $result['effective']['description'], (int) $result['score'], $analysis );

		$this->sync_issues( $post, $result );

		// Duplicate relations are symmetric: re-check the other side when they change.
		$others = array();
		foreach ( array( 'title', 'description', 'content' ) as $kind ) {
			$before = array_map( 'intval', (array) ( $old_dups[ $kind ] ?? array() ) );
			$after  = array_map( 'intval', (array) ( $new_dups[ $kind ] ?? array() ) );
			$others = array_merge( $others, array_diff( $before, $after ), array_diff( $after, $before ) );
		}
		$others = array_diff( array_slice( array_unique( array_map( 'intval', $others ) ), 0, 10 ), array( (int) $post->ID ) );
		if ( $others ) {
			$this->mark( $others, 60 );
		}
		return $result;
	}

	/**
	 * Upserts/resolves the content issues of a post from its analysis.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $result Analysis.
	 */
	private function sync_issues( \WP_Post $post, array $result ) {
		$issues = $this->get( IssueRepository::class );
		if ( 'publish' !== $post->post_status || ! $this->get( ContentTypes::class )->is_post_indexable( $post ) ) {
			$this->resolve_all( (int) $post->ID );
			return;
		}
		$checks = array();
		foreach ( $result['checks'] as $check ) {
			$checks[ $check['id'] ] = $check;
		}
		$status = static function ( $id ) use ( $checks ) {
			return $checks[ $id ]['status'] ?? '';
		};
		$fp        = $result['fingerprint'];
		$url       = (string) get_permalink( $post );
		$title     = get_the_title( $post );
		$effective = (array) ( $result['effective'] ?? array() );
		$keep      = array();
		// The data of every issue is rebuilt from this analysis (upsert replaces it).
		$up = static function ( $type, array $data, $severity = null ) use ( $issues, $post, $url, $title, &$keep ) {
			$args = array(
				'object_type' => 'post',
				'object_id'   => (int) $post->ID,
				'url'         => $url,
				'title'       => $title,
				'data'        => $data,
			);
			if ( $severity ) {
				$args['severity'] = $severity;
			}
			$keep[] = $issues->upsert( $type, $args );
		};

		// Only when no description is output at all: an automatic fallback (SearchPilot's
		// or the SEO plugin's excerpt template) follows the article and is fine.
		if ( 'fail' === $status( 'description_present' ) ) {
			$up( 'missing_meta_description', array( 'source' => (string) ( $effective['description_source'] ?? '' ) ) );
		}
		if ( in_array( $status( 'title_length' ), array( 'fail', 'warning' ), true ) ) {
			$served = (string) ( $effective['title'] ?? '' );
			$rule   = (array) ( $checks['title_length']['data'] ?? array() );
			if ( ! isset( $rule['direction'] ) ) {
				$rule = TitleFixer::rule( $served, TitleFixer::type_rule( $post ) );
			}
			$data = array(
				'length'    => isset( $rule['graphemes'] ) ? (int) $rule['graphemes'] : Text::length( $served ),
				'title'     => $served,
				'direction' => in_array( $rule['direction'] ?? '', array( 'long', 'short' ), true ) ? $rule['direction'] : ( Text::length( $served ) > 60 ? 'long' : 'short' ),
				'ratio'     => isset( $rule['ratio'] ) ? (float) $rule['ratio'] : null,
				'source'    => (string) ( $effective['title_source'] ?? '' ),
				'origin'    => (string) ( $effective['title_origin'] ?? '' ),
				'post_type' => (string) $post->post_type,
				'message'   => (string) ( $checks['title_length']['message'] ?? '' ),
			);
			if ( 'long' === $data['direction'] ) {
				// Which shorter title the fix would use decides its mode (natural phrase:
				// unattended; word cut: one click; none: manual) — cached so they agree.
				try {
					$choice            = $this->get( TitleFixer::class )->choose_long( $post, $served );
					$data['fix_kind']  = $choice['kind'];
					$data['fix_title'] = $choice['text'];
				} catch ( \Throwable $e ) {
					unset( $data['fix_kind'], $data['fix_title'] );
				}
			}
			$up( 'title_length', $data, 'fail' === $status( 'title_length' ) ? 'medium' : 'opportunity' );
		}
		if ( in_array( $status( 'description_length' ), array( 'fail', 'warning' ), true ) ) {
			$served = (string) ( $effective['description'] ?? '' );
			$rule   = (array) ( $checks['description_length']['data'] ?? array() );
			if ( ! isset( $rule['direction'] ) ) {
				$rule = DescriptionFixer::rule( $served );
			}
			$up(
				'description_length',
				array(
					'length'      => isset( $rule['graphemes'] ) ? (int) $rule['graphemes'] : Text::length( $served ),
					'description' => $served,
					'direction'   => in_array( $rule['direction'] ?? '', array( 'long', 'short' ), true ) ? $rule['direction'] : ( Text::length( $served ) > 156 ? 'long' : 'short' ),
					'ratio'       => isset( $rule['ratio'] ) ? (float) $rule['ratio'] : null,
					'source'      => (string) ( $effective['description_source'] ?? '' ),
					'origin'      => (string) ( $effective['description_origin'] ?? '' ),
					'message'     => (string) ( $checks['description_length']['message'] ?? '' ),
				),
				'fail' === $status( 'description_length' ) ? 'medium' : 'opportunity'
			);
		}
		if ( 'fail' === $status( 'duplicate_title' ) ) {
			$up( 'duplicate_title', array( 'others' => $this->post_refs( (array) ( $fp['duplicate_ids']['title'] ?? array() ) ) ) );
		}
		if ( 'fail' === $status( 'duplicate_description' ) ) {
			$up( 'duplicate_description', array( 'others' => $this->post_refs( (array) ( $fp['duplicate_ids']['description'] ?? array() ) ) ) );
		}
		if ( 'warning' === $status( 'content_length' ) ) {
			$up(
				'thin_content',
				array(
					'words'     => (int) ( $checks['content_length']['data']['words'] ?? $result['stats']['words'] ),
					'threshold' => (int) ( $checks['content_length']['data']['threshold'] ?? $this->get( \SearchPilot\Core\Settings::class )->get( 'content.thin_words', 300 ) ),
					'post_type' => (string) $post->post_type,
				),
				'opportunity'
			);
		}
		if ( 'fail' === $status( 'near_duplicate' ) ) {
			$up( 'near_duplicate_content', array( 'others' => $this->post_refs( (array) ( $fp['duplicate_ids']['content'] ?? array() ) ) ) );
		}
		if ( 'warning' === $status( 'heading_structure' ) ) {
			$up( 'heading_structure', $this->heading_data( $post, $result, (array) ( $checks['heading_structure']['items'] ?? array() ) ) );
		}
		if ( 'warning' === $status( 'image_alt' ) ) {
			$up(
				'missing_alt_text',
				array(
					'count'       => count( (array) ( $checks['image_alt']['items'] ?? array() ) ),
					'images'      => (array) ( $checks['image_alt']['items'] ?? array() ),
					'attachments' => $this->attachments_without_alt( array_merge( (array) ( $fp['attachments'] ?? array() ), array( (int) ( $fp['featured_id'] ?? 0 ) ) ) ),
					'featured_id' => (int) ( $fp['featured_id'] ?? 0 ),
				)
			);
		}
		if ( in_array( $status( 'featured_image' ), array( 'fail', 'warning' ), true ) && empty( $fp['featured_id'] ) ) {
			// Media-library images the page shows, in order: the planner promotes the first one.
			$up(
				'missing_featured_image',
				array(
					'post_type'  => (string) $post->post_type,
					'candidates' => array_slice( array_values( array_map( 'intval', (array) ( $fp['attachments'] ?? array() ) ) ), 0, 5 ),
				)
			);
		}
		if ( 'fail' === $status( 'internal_links' ) ) {
			$up( 'no_internal_links', array( 'words' => (int) $result['stats']['words'] ) );
		}
		if ( 'warning' === $status( 'title_year' ) ) {
			$up( 'outdated_title_year', array( 'title' => $result['effective']['title'] ) );
		}
		if ( 'warning' === $status( 'last_modified' ) && $this->evergreen( $post, $result ) ) {
			$up( 'stale_content', array( 'modified' => mysql_to_rfc3339( $post->post_modified_gmt ) ) );
		}
		if ( 'warning' === $status( 'image_size' ) ) {
			$up(
				'large_images',
				array(
					'images'      => (array) ( $checks['image_size']['items'] ?? array() ),
					'attachments' => $this->large_attachments( array_merge( (array) ( $fp['attachments'] ?? array() ), array( (int) ( $fp['featured_id'] ?? 0 ) ) ) ),
				)
			);
		}
		$issues->resolve_stale_for_object( 'post', (int) $post->ID, self::ISSUE_TYPES, array_filter( $keep ) );
	}

	/**
	 * Whether an old post should get a "refresh" opportunity: pages,
	 * cornerstone content and posts that still receive search traffic.
	 * (Old news reports are left alone.)
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $result Analysis.
	 * @return bool
	 */
	private function evergreen( \WP_Post $post, array $result ) {
		if ( 'page' === $post->post_type ) {
			return true;
		}
		if ( $this->get( MetaStore::class )->get_post_field( $post->ID, 'cornerstone' ) ) {
			return true;
		}
		foreach ( (array) $result['queries'] as $q ) {
			if ( (int) $q['clicks'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * [ id, title, edit ] rows for post ids.
	 *
	 * @param int[] $ids Ids.
	 * @return array
	 */
	private function post_refs( array $ids ) {
		$out = array();
		foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 10 ) as $id ) {
			$out[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'url'   => (string) get_permalink( $id ),
				'edit'  => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		return $out;
	}

	/**
	 * Attachments whose media-library ALT text is empty.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[]
	 */
	private function attachments_without_alt( array $ids ) {
		$out = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id && 'attachment' === get_post_type( $id ) && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
				$out[] = $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * heading_structure issue data: the outline the check saw on the rendered
	 * page and the outline stored in the post content, so the fixer runs
	 * unattended only when both agree (no headings from synced patterns,
	 * shortcodes or other blocks) and a level is really skipped.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $result   Analysis.
	 * @param string[] $problems Check items.
	 * @return array
	 */
	private function heading_data( \WP_Post $post, array $result, array $problems ) {
		$data = array(
			'problems' => $problems,
			'levels'   => (array) ( $result['stats']['headings'] ?? array() ),
		);
		try {
			$rendered = array();
			if ( isset( $result['stats']['outline'] ) && is_array( $result['stats']['outline'] ) ) {
				foreach ( $result['stats']['outline'] as $heading ) {
					$rendered[] = is_array( $heading ) ? array(
						'level' => (int) ( $heading['level'] ?? 0 ),
						'text'  => ! empty( $heading['empty'] ) ? '' : 'x',
					) : array(
						'level' => (int) $heading,
						'text'  => 'x',
					);
				}
			} else {
				$html     = $this->get( ContentRenderer::class )->render( (string) $post->post_content, $post );
				$rendered = HtmlDocument::parse( '<div>' . $html . '</div>', (string) get_permalink( $post ) )->headings();
			}
			$outline = array();
			$empty   = 0;
			$subs    = 0;
			foreach ( $rendered as $heading ) {
				$outline[] = (int) $heading['level'];
				if ( '' === trim( (string) $heading['text'] ) ) {
					++$empty;
				}
				if ( (int) $heading['level'] >= 2 ) {
					++$subs;
				}
			}
			$data['outline']        = $outline;
			$data['empty']          = $empty;
			$data['no_subheadings'] = ! $subs && (int) ( $result['stats']['words'] ?? 0 ) > 300;
			if ( class_exists( ContentEditor::class ) ) {
				$raw                 = ContentEditor::headings( (string) $post->post_content );
				$data['raw_outline'] = HeadingFixer::levels( $raw );
				$data['raw_fixed']   = (bool) HeadingFixer::fixed( $raw );
			}
		} catch ( \Throwable $e ) {
			unset( $data['outline'], $data['raw_outline'], $data['raw_fixed'] );
		}
		return $data;
	}

	/**
	 * Attachments whose file is larger than the large-image threshold.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return int[]
	 */
	private function large_attachments( array $ids ) {
		$analyzer = $this->get( Analyzer::class );
		$out      = array();
		foreach ( array_slice( array_unique( array_filter( array_map( 'intval', $ids ) ) ), 0, 40 ) as $id ) {
			$info = $analyzer->attachment_info( $id );
			if ( is_array( $info ) && (int) ( $info['filesize'] ?? 0 ) > Analyzer::LARGE_IMAGE_BYTES ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Hourly / initial sync: run the backlog in its own event (fresh time budget).
	 */
	public function schedule_backlog() {
		$this->scheduler->enqueue_async( 'searchpilot_analysis_backlog', array(), 10 );
	}

	/**
	 * Backlog event: posts never analyzed or edited since; re-queues itself
	 * while work remains (also when a run was cut short by the time budget).
	 */
	public function run_backlog() {
		$result = $this->backlog( self::BACKLOG_MAX );
		if ( null === $result ) {
			$this->scheduler->enqueue_async( 'searchpilot_analysis_backlog', array(), 300 );
		} elseif ( $result['more'] ) {
			$this->scheduler->enqueue_async( 'searchpilot_analysis_backlog', array(), 60 );
		}
	}

	/**
	 * Analyzes up to $max published posts that need it, walking post IDs upwards.
	 *
	 * @param int $max Max analyses.
	 * @return array{done:int,more:bool}|null Null when another run holds the lock.
	 */
	public function backlog( $max = self::BACKLOG_MAX ) {
		return Lock::run(
			'analysis',
			300,
			function () use ( $max ) {
				$budget = new TimeBudget( 20 );
				$state  = $this->get( State::class );
				$repo   = $this->get( AnalysisRepository::class );
				$types  = array_keys( $this->get( ContentTypes::class )->post_types() );
				$cursor = (int) $state->get( 'analysis_cursor', 0 );
				$done   = 0;
				$more   = false;
				while ( true ) {
					if ( $budget->exhausted() || $done >= $max ) {
						$more = true;
						break;
					}
					$ids = $repo->backlog_ids( $types, $cursor, 25 );
					if ( ! $ids ) {
						$cursor = 0; // A full pass finished.
						break;
					}
					foreach ( $ids as $id ) {
						if ( $budget->exhausted() || $done >= $max ) {
							$more = true;
							break 2;
						}
						if ( null === $this->run_analyze_post( $id ) ) {
							$repo->touch_analyzed( $id );
						}
						++$done;
						$cursor = $id;
					}
				}
				$state->set( 'analysis_cursor', $cursor );
				return array(
					'done' => $done,
					'more' => $more,
				);
			}
		);
	}

	/**
	 * Queues re-analysis.
	 *
	 * @param int[] $ids Post ids (empty = all posts).
	 * @return int Posts queued (-1 = all).
	 */
	public function queue( array $ids ) {
		if ( ! $ids ) {
			$this->get( AnalysisRepository::class )->invalidate_all();
			$this->get( State::class )->set( 'analysis_cursor', 0 );
			$this->schedule_backlog();
			$this->scheduler->enqueue_async( 'searchpilot_analysis_drain', array(), 5 );
			return -1;
		}
		$this->mark( $ids, 5 );
		return count( $ids );
	}

	/* ------------------------------------------------------------------
	 * Rendered pages (crawler)
	 * ------------------------------------------------------------------ */

	/**
	 * Records H1 count, title tags and visible word count of a crawled page.
	 *
	 * @param array $page Crawled page (see contracts: url, status, html, doc, object_type, object_id…).
	 */
	public function on_page_crawled( $page ) {
		if ( ! is_array( $page ) || 'post' !== ( $page['object_type'] ?? '' ) || empty( $page['object_id'] ) || 200 !== (int) ( $page['status'] ?? 0 ) ) {
			return;
		}
		$post = get_post( (int) $page['object_id'] );
		if ( ! $this->is_analyzable( $post ) ) {
			return;
		}
		$doc = ( $page['doc'] ?? null ) instanceof HtmlDocument ? $page['doc'] : null;
		if ( ! $doc && ! empty( $page['html'] ) ) {
			$doc = HtmlDocument::parse( (string) $page['html'], (string) ( $page['final_url'] ?? $page['url'] ?? '' ) );
		}
		if ( ! $doc ) {
			return;
		}
		// Read-only XPath: the shared document must not be modified for other listeners.
		$h1     = $doc->query( '//h1' )->length;
		$titles = $doc->query( '//head/title' )->length;
		if ( 0 === $titles ) {
			$titles = $doc->query( '//title[not(ancestor::svg)]' )->length;
		}
		$main  = $doc->main_node();
		$words = 0;
		if ( $main ) {
			$text = '';
			foreach ( $doc->query( './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template) and not(ancestor::svg)]', $main ) as $node ) {
				$text .= ' ' . $node->nodeValue;
			}
			$words = Text::word_count( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ) );
		}
		$this->get( AnalysisRepository::class )->set_rendered(
			$post->ID,
			array(
				'h1'         => (int) $h1,
				'titles'     => (int) $titles,
				'words'      => (int) $words,
				'checked_at' => gmdate( 'c' ),
			)
		);

		$issues = $this->get( IssueRepository::class );
		if ( 'publish' === $post->post_status && $h1 > 1 ) {
			$issues->upsert(
				'multiple_h1',
				array(
					'object_type' => 'post',
					'object_id'   => (int) $post->ID,
					'url'         => (string) get_permalink( $post ),
					'title'       => get_the_title( $post ),
					'data'        => array( 'h1' => (int) $h1 ),
				)
			);
		} else {
			$issues->resolve_stale_for_object( 'post', (int) $post->ID, array( 'multiple_h1' ), array() );
		}
	}

	/* ------------------------------------------------------------------
	 * Fixers
	 * ------------------------------------------------------------------ */

	/**
	 * Fills an EMPTY meta description from the excerpt/opening text (owner's field in companion mode).
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_meta_description( array $issue ) {
		$post_id = (int) ( $issue['object_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'searchpilot_no_post', __( 'The post no longer exists.', 'searchpilot-pro' ) );
		}
		$meta = $this->get( MetaStore::class );
		if ( '' !== trim( (string) $meta->get_post_field( $post_id, 'description' ) ) ) {
			return true; // Someone wrote one meanwhile; never overwrite.
		}
		$auto = $meta->auto_description( $post );
		if ( '' === $auto ) {
			return new \WP_Error( 'searchpilot_nothing_to_fill', __( 'The post has no text to build a description from. Write one in the editor.', 'searchpilot-pro' ) );
		}
		$result = $meta->set_post_field(
			$post_id,
			'description',
			$auto,
			array(
				'source'      => (string) ( $issue['source'] ?? 'user' ),
				'action'      => 'meta_description_filled',
				/* translators: %s: post title. */
				'description' => sprintf( __( 'Filled the empty meta description of “%s”', 'searchpilot-pro' ), get_the_title( $post ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->mark( array( $post_id ), 10 );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Issue types & dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Registers content issue types.
	 *
	 * @param IssueRegistry $r Registry.
	 */
	public function register_issue_types( IssueRegistry $r ) {
		$r->register(
			'missing_meta_description',
			array(
				'label'     => __( 'No meta description is output', 'searchpilot-pro' ),
				'category'  => 'content',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					/* translators: %s: post title. */
					return sprintf( __( '“%s” has no meta description, and no automatic description is output either.', 'searchpilot-pro' ), $issue['title'] );
				},
				'why'       => __( 'The description is the text under your title in search results. Without one, search engines pick a snippet themselves, which often earns fewer clicks.', 'searchpilot-pro' ),
				'fix'       => __( 'Write a 120–156 character summary in the SEO panel of the editor, or turn on automatic descriptions (SearchPilot → Settings, or the description template of your SEO plugin) so every post gets one from its opening text. The one-click fix copies the opening text into the description field once — that copy no longer follows later edits of the article, so review it after big updates.', 'searchpilot-pro' ),
				'fixer'     => array( $this, 'fix_meta_description' ),
				'fix_label' => __( 'Copy the opening text', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_CONTENT,
				'action'    => 'update_description',
			)
		);
		$r->register(
			'title_length',
			array(
				'label'    => __( 'SEO title too long or too short', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: 1: title, 2: characters. */
					return sprintf( __( 'The search title “%1$s” has %2$d characters.', 'searchpilot-pro' ), (string) ( $issue['data']['title'] ?? $issue['title'] ), (int) ( $issue['data']['length'] ?? 0 ) );
				},
				'why'      => __( 'Titles longer than about 60 characters are cut off in search results; very short titles do not tell searchers enough to click.', 'searchpilot-pro' ),
				'fix'      => __( 'Keep the SEO title to about 30–60 characters with the main topic first. An over-long title can be shortened automatically — only the SEO title changes, never the post title, and only when the shorter title is not used by another page. A short utility-page title can get the site name added. Short article headlines are editorial: rewrite them yourself.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'update_title',
			)
		);
		$r->register(
			'description_length',
			array(
				'label'    => __( 'Meta description length', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: %d: characters. */
					return sprintf( __( 'The meta description has %d characters.', 'searchpilot-pro' ), (int) ( $issue['data']['length'] ?? 0 ) );
				},
				'why'      => __( 'Search engines show about 120–156 characters. Longer descriptions are cut off mid-sentence; short ones waste space that could persuade searchers.', 'searchpilot-pro' ),
				'fix'      => __( 'Edit the description in the SEO panel to about 120–156 characters, key point first. An over-long description can be shortened (whole sentences first) after you review the preview; a short one needs your own words.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'update_description',
			)
		);
		$r->register(
			'duplicate_title',
			array(
				'label'    => __( 'Duplicate SEO title', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$others = wp_list_pluck( (array) ( $issue['data']['others'] ?? array() ), 'title' );
					/* translators: 1: post title, 2: other titles. */
					return sprintf( __( '“%1$s” uses the same search title as: %2$s.', 'searchpilot-pro' ), $issue['title'], implode( ', ', array_slice( $others, 0, 5 ) ) );
				},
				'why'      => __( 'When several pages share one title, search engines struggle to decide which to show, and searchers cannot tell them apart.', 'searchpilot-pro' ),
				'fix'      => __( 'Give each page a title that names what makes it different (place, date, angle). If the pages really cover the same thing, merge them and redirect one.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'update_title',
			)
		);
		$r->register(
			'duplicate_description',
			array(
				'label'    => __( 'Duplicate meta description', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					$others = wp_list_pluck( (array) ( $issue['data']['others'] ?? array() ), 'title' );
					/* translators: 1: post title, 2: other titles. */
					return sprintf( __( '“%1$s” has the same meta description as: %2$s.', 'searchpilot-pro' ), $issue['title'], implode( ', ', array_slice( $others, 0, 5 ) ) );
				},
				'why'      => __( 'Identical descriptions make different pages look the same in search results, and search engines usually replace them with their own snippet.', 'searchpilot-pro' ),
				'fix'      => __( 'Write a description that summarizes this page specifically.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'update_description',
			)
		);
		$r->register(
			'thin_content',
			array(
				'label'    => __( 'Short page — check it is complete', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: 1: post title, 2: words, 3: guideline. */
					return sprintf( __( '“%1$s” has %2$d words (the site guideline is %3$d). News reports are not included in this check.', 'searchpilot-pro' ), $issue['title'], (int) ( $issue['data']['words'] ?? 0 ), (int) ( $issue['data']['threshold'] ?? 300 ) );
				},
				'why'      => __( 'Word count is not a ranking factor. What counts is whether the page fully answers what visitors come for — very short pages often leave out the details, examples or context readers need.', 'searchpilot-pro' ),
				'fix'      => __( 'Read the page as a visitor: add what is missing, merge it into a stronger related page, or set it to noindex if it is only a utility page. Never pad text to reach a word count.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'improve_content',
			)
		);
		$r->register(
			'near_duplicate_content',
			array(
				'label'    => __( 'Near-duplicate content', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'high',
				'what'     => static function ( $issue ) {
					$others = wp_list_pluck( (array) ( $issue['data']['others'] ?? array() ), 'title' );
					/* translators: 1: post title, 2: other titles. */
					return sprintf( __( 'The text of “%1$s” is almost identical to: %2$s.', 'searchpilot-pro' ), $issue['title'], implode( ', ', array_slice( $others, 0, 5 ) ) );
				},
				'why'      => __( 'Search engines show only one of several near-identical pages and may pick the wrong one; the copies compete with each other for the same searches.', 'searchpilot-pro' ),
				'fix'      => __( 'Keep the better page, merge any unique details into it and redirect the other; or rewrite one so it has a clearly different purpose. Syndicated copies should point a canonical to the original.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'merge_content',
			)
		);
		$r->register(
			'heading_structure',
			array(
				'label'    => __( 'Heading structure problems', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					$problems = (array) ( $issue['data']['problems'] ?? array() );
					/* translators: %s: post title. */
					return sprintf( __( '“%s”:', 'searchpilot-pro' ), $issue['title'] ) . ' ' . implode( ' ', $problems );
				},
				'why'      => __( 'This does not affect rankings — search engines read pages fine with any heading order. Headings are the outline readers and screen readers use to scan the page, so skipped levels or empty headings make it harder to navigate.', 'searchpilot-pro' ),
				'fix'      => __( 'Use H2 for main sections and H3 for sub-sections, in order, each with a short descriptive text. Skipped levels can be fixed automatically: each heading moves up to one level below the previous heading (only the level changes, never the text).', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'improve_content',
			)
		);
		$r->register(
			'missing_alt_text',
			array(
				'label'     => __( 'Images without ALT text', 'searchpilot-pro' ),
				'category'  => 'content',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					/* translators: 1: number of images, 2: post title. */
					return sprintf( _n( '%1$d image in “%2$s” has no ALT text.', '%1$d images in “%2$s” have no ALT text.', (int) ( $issue['data']['count'] ?? 0 ), 'searchpilot-pro' ), (int) ( $issue['data']['count'] ?? 0 ), $issue['title'] );
				},
				'why'       => __( 'ALT text describes images to people using screen readers and to search engines (image search). Missing ALT text is an accessibility problem.', 'searchpilot-pro' ),
				'fix'       => __( 'Describe each image in the editor. The fix fills only EMPTY ALT text — in the media library and in the post — from the image caption, the figure caption, the image title or a descriptive file name, and shows every text before anything changes. Existing ALT text is never replaced.', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_FILL_ALT,
				'action'    => 'fill_alt',
			)
		);
		$r->register(
			'missing_featured_image',
			array(
				'label'    => __( 'No featured image', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					/* translators: %s: post title. */
					return sprintf( __( '“%s” has no featured image.', 'searchpilot-pro' ), $issue['title'] );
				},
				'why'      => __( 'Article structured data needs an image to be eligible for article rich results (including Top stories and Discover), and social networks show a blank card without one.', 'searchpilot-pro' ),
				'fix'      => __( 'Set a featured image at least 1200 px wide in the editor sidebar. The fix can promote the first image of the article after you confirm it.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'add_image',
			)
		);
		$r->register(
			'no_internal_links',
			array(
				'label'    => __( 'No internal links in the content', 'searchpilot-pro' ),
				'category' => 'links',
				'severity' => 'medium',
				'what'     => static function ( $issue ) {
					/* translators: %s: post title. */
					return sprintf( __( '“%s” does not link to any other page of this site.', 'searchpilot-pro' ), $issue['title'] );
				},
				'why'      => __( 'Internal links help readers continue to related content and help search engines discover pages and understand how topics relate.', 'searchpilot-pro' ),
				'fix'      => __( 'Add links to two or three related articles. SearchPilot → Internal Links suggests sentences and targets.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_INTERNAL_LINKS,
				'action'   => 'add_internal_links',
			)
		);
		$r->register(
			'outdated_title_year',
			array(
				'label'    => __( 'Title mentions a past year', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: %s: title. */
					return sprintf( __( 'The title “%s” mentions a year that has passed.', 'searchpilot-pro' ), (string) ( $issue['data']['title'] ?? $issue['title'] ) );
				},
				'why'      => __( 'Searchers skip results that look outdated. If the page is still relevant, a current year signals fresh information.', 'searchpilot-pro' ),
				'fix'      => __( 'Update the content first, then the year in the title. Leave historical years (events, reports) unchanged.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'content_refresh',
			)
		);
		$r->register(
			'stale_content',
			array(
				'label'    => __( 'Evergreen content not updated in a year', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: %s: post title. */
					return sprintf( __( '“%s” was last updated more than 12 months ago.', 'searchpilot-pro' ), $issue['title'] );
				},
				'why'      => __( 'Facts, prices and screenshots age. Reviewing important pages keeps them accurate and competitive.', 'searchpilot-pro' ),
				'fix'      => __( 'Review the page: update outdated facts, add what is new, and remove what no longer applies.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'content_refresh',
			)
		);
		$r->register(
			'large_images',
			array(
				'label'    => __( 'Large image files', 'searchpilot-pro' ),
				'category' => 'performance',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					$images = (array) ( $issue['data']['images'] ?? array() );
					/* translators: 1: post title, 2: files. */
					return sprintf( __( '“%1$s” uses images larger than 300 KB: %2$s', 'searchpilot-pro' ), $issue['title'], implode( ', ', array_slice( $images, 0, 5 ) ) );
				},
				'why'      => __( 'Heavy images slow the page down, especially on mobile data, which hurts visitors and Core Web Vitals.', 'searchpilot-pro' ),
				'fix'      => __( 'Compress the images or serve WebP/AVIF versions. SearchPilot names the setting of your image optimisation plugin that keeps these files large and how to re-optimise them.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'optimize_images',
			)
		);
		$r->register(
			'multiple_h1',
			array(
				'label'    => __( 'Several H1 headings (accessibility)', 'searchpilot-pro' ),
				'category' => 'content',
				'severity' => 'opportunity',
				'what'     => static function ( $issue ) {
					/* translators: 1: post title, 2: number of H1s. */
					return sprintf( __( 'The published page “%1$s” has %2$d H1 headings.', 'searchpilot-pro' ), $issue['title'], (int) ( $issue['data']['h1'] ?? 0 ) );
				},
				'why'      => __( 'This does not affect rankings — search engines handle several H1 headings fine. People using screen readers jump between headings, and one H1 with the page title makes the page easiest to navigate.', 'searchpilot-pro' ),
				'fix'      => __( 'Keep the page title as the only H1 and use H2 for sections. H1 headings inside the post content can be demoted automatically; headings in the theme or a page-builder layout are changed there.', 'searchpilot-pro' ),
				'task'     => AutomationPolicy::TASK_CONTENT,
				'action'   => 'improve_content',
			)
		);
	}

	/**
	 * Dashboard section.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard( $summary ) {
		$summary = is_array( $summary ) ? $summary : array();
		try {
			$stats              = $this->get( AnalysisRepository::class )->stats();
			$summary['content'] = array(
				'analyzed'  => (int) $stats['analyzed'],
				'avg_score' => $stats['avg_score'],
				'below_50'  => (int) $stats['below_50'],
			);
		} catch ( \Throwable $e ) {
			$summary['content'] = array(
				'analyzed'  => 0,
				'avg_score' => null,
				'below_50'  => 0,
			);
		}
		return $summary;
	}
}
