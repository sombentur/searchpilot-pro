<?php
/**
 * Fixers module: planners, fix modes and manual steps for issue types.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Fixes;

use SearchPilot\Container;
use SearchPilot\Contracts\Module;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Logger;
use SearchPilot\Fixes\Fixers\AdvisorySteps;
use SearchPilot\Fixes\Fixers\AltTextFixer;
use SearchPilot\Fixes\Fixers\DescriptionFixer;
use SearchPilot\Fixes\Fixers\FeaturedImageFixer;
use SearchPilot\Fixes\Fixers\HeadingFixer;
use SearchPilot\Fixes\Fixers\ImageCompressor;
use SearchPilot\Fixes\Fixers\ImageOptimizerAdvisor;
use SearchPilot\Fixes\Fixers\IndexNowFixer;
use SearchPilot\Fixes\Fixers\StraySitemapFixer;
use SearchPilot\Fixes\Fixers\TitleFixer;
use SearchPilot\Issues\IssueRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches fixers to issue types registered by other modules, through
 * IssueRegistry::extend() at priority 20 (contract C4/C6):
 *
 *   planner      callable( array $issue ): array|\WP_Error — a Plan (never writes).
 *   mode         deterministic (unattended in Autopilot) | assisted (preview +
 *                one click) | advisory (no fix button; the manual step is the
 *                primary action) — a callable when it depends on the issue.
 *   manual_step  the concrete thing a person does.
 *
 * Deterministic: heading_structure, multiple_h1, title_length (too long →
 * SEO title only). Assisted: title_length (too short, utility pages),
 * description_length (too long), missing_alt_text, missing_featured_image,
 * indexnow_duplicate_submitter. Advisory: large_images, thin_content,
 * crawled_not_indexed, and the cases above that no safe fix covers.
 */
class FixersModule implements Module {

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'searchpilot_register_issue_types', array( $this, 'extend_types' ), 20 );
		// Rank Math's module list changed (IndexNow takeover, its undo, or Rank Math itself).
		add_action( 'update_option_' . IndexNowFixer::OPTION, array( $this, 'modules_changed' ), 20, 0 );
		add_action( 'add_option_' . IndexNowFixer::OPTION, array( $this, 'modules_changed' ), 20, 0 );
		add_action( 'delete_option_' . IndexNowFixer::OPTION, array( $this, 'modules_changed' ), 20, 0 );
		add_action( 'searchpilot_register_revert_handlers', array( ImageCompressor::class, 'register_handler' ) );
		add_action( 'delete_attachment', array( $this, 'delete_image_backup' ) );
	}

	/**
	 * Removes the kept original of a compressed image when the attachment is deleted.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function delete_image_backup( $attachment_id ) {
		$backup = get_post_meta( (int) $attachment_id, ImageCompressor::BACKUP_META, true );
		$file   = get_attached_file( (int) $attachment_id );
		if ( is_array( $backup ) && ! empty( $backup['file'] ) && $file && false !== strpos( (string) $backup['file'], ImageCompressor::SUFFIX ) ) {
			wp_delete_file( dirname( $file ) . '/' . wp_basename( (string) $backup['file'] ) );
		}
		// PNG files kept after a conversion to WebP belong to this attachment too.
		$old = get_post_meta( (int) $attachment_id, ImageCompressor::OLD_FILES, true );
		if ( is_array( $old ) && $file ) {
			foreach ( array_slice( $old, 0, 40 ) as $name ) {
				if ( '.png' === strtolower( substr( (string) $name, -4 ) ) ) {
					wp_delete_file( dirname( $file ) . '/' . wp_basename( (string) $name ) );
				}
			}
		}
	}

	/**
	 * Adds planners, modes and manual steps to the issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function extend_types( IssueRegistry $registry ) {
		if ( ! method_exists( $registry, 'extend' ) ) {
			return;
		}
		$structure = $this->call( HeadingFixer::class, 'mode', 'advisory' );
		$headings  = $this->call( AdvisorySteps::class, 'headings', '' );

		$definitions = array(
			'heading_structure'            => array(
				'mode'        => $structure,
				'planner'     => $this->planner( HeadingFixer::class, 'plan_structure' ),
				'manual_step' => $headings,
				'fix_label'   => __( 'Fix heading levels', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_SEO_STRUCTURE,
			),
			'multiple_h1'                  => array(
				'mode'        => $structure,
				'planner'     => $this->planner( HeadingFixer::class, 'plan_multiple_h1' ),
				'manual_step' => $headings,
				'fix_label'   => __( 'Demote extra H1 headings', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_SEO_STRUCTURE,
			),
			'title_length'                 => array(
				'mode'        => $this->call( TitleFixer::class, 'mode', 'advisory' ),
				'planner'     => $this->planner( TitleFixer::class, 'plan' ),
				'manual_step' => $this->call( TitleFixer::class, 'manual_step', '' ),
				'fix_label'   => __( 'Fix the SEO title', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_SEO_STRUCTURE,
				// Adding the site name to a short utility-page title may run alone; fixed-text cuts
				// of long headlines (clause, word cut) always wait for a person.
				'auto_ok'     => static function ( $issue ) {
					return 'short' === (string) ( $issue['data']['direction'] ?? '' );
				},
			),
			'description_length'           => array(
				'mode'        => $this->call( DescriptionFixer::class, 'mode', 'advisory' ),
				'planner'     => $this->planner( DescriptionFixer::class, 'plan' ),
				'manual_step' => $this->call( DescriptionFixer::class, 'manual_step', '' ),
				'fix_label'   => __( 'Shorten the description', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_PAGE_REPAIRS,
				'auto_ok'     => true,
			),
			'missing_alt_text'             => array(
				'mode'        => 'assisted',
				'planner'     => $this->planner( AltTextFixer::class, 'plan' ),
				'manual_step' => __( 'Describe each image in the editor (image block → ALT text) or in Media → Library. Purely decorative images may keep an empty ALT text.', 'searchpilot-pro' ),
				'fix_label'   => __( 'Fill ALT text', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_FILL_ALT,
				'auto_ok'     => true,
			),
			'missing_featured_image'       => array(
				'mode'        => $this->call( FeaturedImageFixer::class, 'mode', 'assisted' ),
				'planner'     => $this->planner( FeaturedImageFixer::class, 'plan' ),
				'manual_step' => __( 'Set a featured image at least 1200 px wide in the editor sidebar (Featured image → Set featured image).', 'searchpilot-pro' ),
				'fix_label'   => __( 'Use the first image', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_PAGE_REPAIRS,
				'auto_ok'     => true,
			),
			'indexnow_duplicate_submitter' => array(
				'mode'        => $this->call( IndexNowFixer::class, 'mode', 'advisory' ),
				'planner'     => $this->planner( IndexNowFixer::class, 'plan' ),
				'manual_step' => $this->call( IndexNowFixer::class, 'manual_step', '' ),
				'fix_label'   => __( 'Let SearchPilot send IndexNow', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'destructive' => true,
			),
			'large_images'                 => array(
				// JPEG/WebP originals are re-encoded and PNG originals converted to WebP (original
				// kept, undoable); other formats and unwritable files keep the manual step.
				'mode'        => $this->call( ImageCompressor::class, 'mode', 'advisory' ),
				'fixer'       => $this->planner( ImageCompressor::class, 'fix' ),
				'manual_step' => $this->call( ImageOptimizerAdvisor::class, 'manual_step', '' ),
				'fix_label'   => __( 'Compress images', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_PAGE_REPAIRS,
			),
			'sitemap_gsc_errors'           => array(
				// Article pages or ads.txt submitted as "sitemaps" are removed from Search Console;
				// real sitemaps with errors keep the manual step.
				'mode'        => $this->call( StraySitemapFixer::class, 'mode', 'advisory' ),
				'fixer'       => $this->planner( StraySitemapFixer::class, 'fix' ),
				'manual_step' => __( 'Open Search Console → Sitemaps, read the error details of the listed sitemap, fix the cause, then resubmit it from SearchPilot → Sitemaps.', 'searchpilot-pro' ),
				'fix_label'   => __( 'Remove entries that are not sitemaps', 'searchpilot-pro' ),
				'task'        => AutomationPolicy::TASK_SAFE_FIXES,
			),
			'thin_content'                 => array(
				'mode'        => 'advisory',
				'manual_step' => $this->call( AdvisorySteps::class, 'thin_content', '' ),
			),
			'crawled_not_indexed'          => array(
				'mode'        => 'advisory',
				'manual_step' => $this->call( AdvisorySteps::class, 'crawled_not_indexed', '' ),
			),
		);
		foreach ( $definitions as $type => $definition ) {
			// Types registered later are extended when they are registered.
			$registry->extend( $type, $definition );
		}
	}

	/**
	 * Rank Math's modules changed: re-evaluate the IndexNow issue.
	 */
	public function modules_changed() {
		try {
			$this->container->get( IndexNowFixer::class )->refresh_issue();
		} catch ( \Throwable $e ) {
			Logger::instance()->warning( 'indexnow', 'Could not re-check the IndexNow owner: ' . $e->getMessage() );
		}
	}

	/**
	 * Lazy planner callable (the fixer is built on first use; errors become WP_Error).
	 *
	 * @param string $class  Fixer class.
	 * @param string $method Method.
	 * @return \Closure
	 */
	private function planner( $class, $method ) {
		return function ( $issue ) use ( $class, $method ) {
			try {
				return $this->container->get( $class )->$method( (array) $issue );
			} catch ( \Throwable $e ) {
				Logger::instance()->warning( 'automation', sprintf( 'Planning a fix for %s failed: %s', (string) ( $issue['type'] ?? '' ), $e->getMessage() ) );
				return new \WP_Error( 'searchpilot_plan_failed', __( 'SearchPilot could not prepare this fix. Details are in SearchPilot → Logs.', 'searchpilot-pro' ), array( 'status' => 500 ) );
			}
		};
	}

	/**
	 * Lazy callable for modes and manual steps (falls back on errors).
	 *
	 * @param string $class    Class.
	 * @param string $method   Method.
	 * @param string $fallback Value on error.
	 * @return \Closure
	 */
	private function call( $class, $method, $fallback ) {
		return function ( $issue = array() ) use ( $class, $method, $fallback ) {
			try {
				return $this->container->get( $class )->$method( (array) $issue );
			} catch ( \Throwable $e ) {
				Logger::instance()->warning( 'automation', sprintf( '%s::%s failed: %s', $class, $method, $e->getMessage() ) );
				return $fallback;
			}
		};
	}
}
