<?php
/**
 * Structured data module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Schema;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Settings;
use SearchPilot\Seo\Breadcrumbs;
use SearchPilot\Seo\HeadOutput;
use SearchPilot\Seo\TitleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one JSON-LD graph in the head when SearchPilot owns structured
 * data, the "displayed" BreadcrumbList in the footer once breadcrumbs were
 * actually shown, and runs the conflict checks on crawled pages.
 */
class SchemaModule implements Module {

	/**
	 * Conflicts.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Builder.
	 *
	 * @var SchemaBuilder
	 */
	private $builder;

	/**
	 * Conflict checks.
	 *
	 * @var SchemaConflicts
	 */
	private $checks;

	/**
	 * Breadcrumbs.
	 *
	 * @var Breadcrumbs
	 */
	private $breadcrumbs;

	/**
	 * Titles/context.
	 *
	 * @var TitleResolver
	 */
	private $titles;

	/**
	 * Head output.
	 *
	 * @var HeadOutput
	 */
	private $head;

	/**
	 * Whether the head graph already contained the breadcrumb list.
	 *
	 * @var bool
	 */
	private $head_breadcrumbs = false;

	/**
	 * Constructor.
	 *
	 * @param ConflictDetector $conflicts   Conflicts.
	 * @param Settings         $settings    Settings.
	 * @param SchemaBuilder    $builder     Builder.
	 * @param SchemaConflicts  $checks      Checks.
	 * @param Breadcrumbs      $breadcrumbs Breadcrumbs.
	 * @param TitleResolver    $titles      Titles.
	 * @param HeadOutput       $head        Head output.
	 */
	public function __construct( ConflictDetector $conflicts, Settings $settings, SchemaBuilder $builder, SchemaConflicts $checks, Breadcrumbs $breadcrumbs, TitleResolver $titles, HeadOutput $head ) {
		$this->conflicts   = $conflicts;
		$this->settings    = $settings;
		$this->builder     = $builder;
		$this->checks      = $checks;
		$this->breadcrumbs = $breadcrumbs;
		$this->titles      = $titles;
		$this->head        = $head;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		$this->checks->register();
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'print_graph' ), 90 );
		add_action( 'wp_footer', array( $this, 'print_footer_breadcrumbs' ), 20 );
	}

	/**
	 * Whether JSON-LD may be printed on this request.
	 *
	 * @return bool
	 */
	private function should_print() {
		if ( is_feed() || is_embed() || is_robots() || is_trackback() || is_preview() ) {
			return false;
		}
		return $this->conflicts->owns( 'schema' );
	}

	/**
	 * wp_head: the page graph.
	 */
	public function print_graph() {
		if ( ! $this->should_print() ) {
			return;
		}
		try {
			$graph = $this->builder->for_current();
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->error( 'schema', 'Could not build the structured data graph: ' . $e->getMessage(), array( 'line' => $e->getLine() ) );
			return;
		}
		foreach ( (array) ( $graph['@graph'] ?? array() ) as $node ) {
			if ( is_array( $node ) && 'BreadcrumbList' === ( $node['@type'] ?? '' ) ) {
				$this->head_breadcrumbs = true;
			}
		}
		echo SchemaBuilder::script( $graph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with </ escaped.
	}

	/**
	 * wp_footer: BreadcrumbList in "displayed" mode when a trail was shown after wp_head.
	 */
	public function print_footer_breadcrumbs() {
		if ( $this->head_breadcrumbs || 'displayed' !== $this->settings->get( 'schema.breadcrumbs', 'displayed' ) || ! $this->should_print() ) {
			return;
		}
		$trail = $this->breadcrumbs->was_rendered() ? $this->breadcrumbs->last_trail() : array();
		if ( count( $trail ) < 2 ) {
			return;
		}
		$context = $this->titles->current_context();
		$url     = $this->head->canonical( $context );
		if ( '' === $url && 'search' === $context['type'] ) {
			$url = get_search_link( $context['search'] );
		}
		if ( '' === $url ) {
			return;
		}
		echo SchemaBuilder::script( $this->builder->breadcrumb_graph( $trail, $url ), 'searchpilot-schema-breadcrumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with </ escaped.
	}
}
