<?php
/**
 * Front-end SEO output module.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Seo;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires titles, meta description, canonical, robots and social tags into
 * WordPress. Ownership is decided on `wp` (after every plugin finished
 * loading) and each output is attached only for the features SearchPilot
 * owns; the callbacks re-check ownership themselves.
 */
class FrontendModule implements Module {

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
	 * Titles.
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
	 * Suppressor.
	 *
	 * @var Suppressor
	 */
	private $suppressor;

	/**
	 * Constructor.
	 *
	 * @param ConflictDetector $conflicts  Conflicts.
	 * @param Settings         $settings   Settings.
	 * @param TitleResolver    $titles     Titles.
	 * @param HeadOutput       $head       Head output.
	 * @param Suppressor       $suppressor Suppressor.
	 */
	public function __construct( ConflictDetector $conflicts, Settings $settings, TitleResolver $titles, HeadOutput $head, Suppressor $suppressor ) {
		$this->conflicts  = $conflicts;
		$this->settings   = $settings;
		$this->titles     = $titles;
		$this->head       = $head;
		$this->suppressor = $suppressor;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp', array( $this, 'setup' ), 20 );
		add_action( 'template_redirect', array( $this, 'attachment_redirect' ), 1 );
	}

	/**
	 * Attaches the outputs SearchPilot owns (runs once the main query is known).
	 */
	public function setup() {
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		try {
			$this->suppressor->apply();
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->warning( 'frontend', 'Could not switch off another SEO plugin\'s output: ' . $e->getMessage() );
		}
		$this->head->set_replace_robots( $this->suppressor->replaces_robots() );

		if ( $this->conflicts->owns( 'title' ) ) {
			add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 15 );
			add_filter( 'document_title_separator', array( $this, 'separator' ), 15 );
			add_filter( 'document_title_parts', array( $this, 'title_parts' ), 15 );
			if ( ! current_theme_supports( 'title-tag' ) && ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
				add_filter( 'wp_title', array( $this, 'wp_title' ), 15, 3 );
			}
		}
		if ( $this->conflicts->owns( 'canonical' ) ) {
			remove_action( 'wp_head', 'rel_canonical' );
			add_filter( 'get_canonical_url', array( $this->head, 'filter_canonical_url' ), 10, 2 );
		}
		if ( $this->conflicts->owns( 'robots' ) ) {
			add_filter( 'wp_robots', array( $this->head, 'filter_robots' ), $this->suppressor->replaces_robots() ? PHP_INT_MAX : 20 );
			if ( ! $this->settings->get( 'content.search_noindex' ) ) {
				remove_filter( 'wp_robots', 'wp_robots_noindex_search' );
			}
			if ( ! $this->settings->get( 'meta.max_image_preview_large' ) ) {
				remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
			}
		}
		if ( $this->conflicts->owns( 'social' ) ) {
			// Jetpack's tags would duplicate ours.
			add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );
			add_filter( 'jetpack_disable_twitter_cards', '__return_true', 99 );
		}
		if ( $this->conflicts->owns( 'description' ) || $this->conflicts->owns( 'canonical' ) || $this->conflicts->owns( 'social' ) ) {
			add_action( 'wp_head', array( $this, 'render_head' ), 1 );
		}
	}

	/**
	 * wp_head: prints SearchPilot's tags (never breaks the page).
	 */
	public function render_head() {
		try {
			$this->head->render();
		} catch ( \Throwable $e ) {
			\SearchPilot\Core\Logger::instance()->error( 'frontend', 'Head output failed: ' . $e->getMessage(), array( 'line' => $e->getLine() ) );
		}
	}

	/**
	 * pre_get_document_title.
	 *
	 * @param string $title Title from earlier filters.
	 * @return string Escaped title.
	 */
	public function document_title( $title ) {
		if ( ! $this->conflicts->owns( 'title' ) ) {
			return $title;
		}
		try {
			$ours = $this->titles->title();
		} catch ( \Throwable $e ) {
			return $title;
		}
		return '' !== $ours ? esc_html( $ours ) : $title;
	}

	/**
	 * document_title_separator.
	 *
	 * @param string $sep Separator.
	 * @return string
	 */
	public function separator( $sep ) {
		return $this->conflicts->owns( 'title' ) ? esc_html( $this->titles->separator() ) : $sep;
	}

	/**
	 * document_title_parts (used when another filter emptied pre_get_document_title).
	 *
	 * @param array $parts Parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		if ( ! $this->conflicts->owns( 'title' ) ) {
			return $parts;
		}
		$ours = $this->titles->title();
		return '' !== $ours ? array( 'title' => $ours ) : $parts;
	}

	/**
	 * wp_title for legacy themes without title-tag support.
	 *
	 * @param string $title       Title.
	 * @param string $sep         Separator.
	 * @param string $seplocation Separator location.
	 * @return string
	 */
	public function wp_title( $title, $sep = '', $seplocation = '' ) {
		if ( ! $this->conflicts->owns( 'title' ) || is_feed() ) {
			return $title;
		}
		$ours = $this->titles->title();
		return '' !== $ours ? esc_html( $ours ) : $title;
	}

	/**
	 * Redirects attachment pages to the file (or the parent post) when enabled.
	 */
	public function attachment_redirect() {
		if ( ! is_attachment() || ! $this->settings->get( 'content.attachment_redirect' ) ) {
			return;
		}
		if ( ! $this->conflicts->owns( 'robots' ) && ! $this->conflicts->owns( 'redirects' ) ) {
			return;
		}
		// WordPress 6.4+ already redirects attachment pages when they are disabled.
		if ( '0' === (string) get_option( 'wp_attachment_pages_enabled', '1' ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}
		$target = (string) wp_get_attachment_url( $post->ID );
		if ( '' === $target && $post->post_parent && 'publish' === get_post_status( $post->post_parent ) ) {
			$target = (string) get_permalink( $post->post_parent );
		}
		if ( '' === $target ) {
			return;
		}
		$host  = (string) wp_parse_url( $target, PHP_URL_HOST );
		$allow = static function ( $hosts ) use ( $host ) {
			$hosts   = (array) $hosts;
			$hosts[] = $host;
			return $hosts;
		};
		// The file may live on a CDN host; allow exactly that host for this redirect.
		add_filter( 'allowed_redirect_hosts', $allow );
		wp_safe_redirect( $target, 301, 'SearchPilot' );
		remove_filter( 'allowed_redirect_hosts', $allow );
		exit;
	}
}
