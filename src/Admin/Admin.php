<?php
/**
 * Admin menu, pages and assets.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Admin;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;

defined( 'ABSPATH' ) || exit;

/**
 * Every SearchPilot screen is a small shell (<div id="spp-app" data-view="…">)
 * rendered by assets/js/core.js plus one view script from assets/js/views/.
 * All data flows through the searchpilot/v1 REST API.
 */
class Admin implements Module {

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
	 * Hook suffixes of our pages.
	 *
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param State    $state    State.
	 */
	public function __construct( Settings $settings, State $state ) {
		$this->settings = $settings;
		$this->state    = $state;
	}

	/**
	 * Page slug => definition. Capability levels: view|manage.
	 *
	 * @return array<string,array{view:string,title:string,menu:string,level:string,hidden?:bool,extra?:string[]}>
	 */
	public static function pages() {
		$pages = array(
			'searchpilot'               => array(
				'view'  => 'dashboard',
				'title' => __( 'Dashboard', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-performance'   => array(
				'view'  => 'performance',
				'title' => __( 'Search Performance', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-indexing'      => array(
				'view'  => 'indexing',
				'title' => __( 'Indexing', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-audit'         => array(
				'view'  => 'audit',
				'title' => __( 'SEO Audit', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-opportunities' => array(
				'view'  => 'opportunities',
				'title' => __( 'Content Opportunities', 'searchpilot-pro' ),
				'menu'  => __( 'Opportunities', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-links'         => array(
				'view'  => 'links',
				'title' => __( 'Internal Links', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-sitemaps'      => array(
				'view'  => 'sitemaps',
				'title' => __( 'Sitemaps', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-schema'        => array(
				'view'  => 'schema',
				'title' => __( 'Schema', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-redirects'     => array(
				'view'  => 'redirects',
				'title' => __( 'Redirects', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-404'           => array(
				'view'  => 'notfound',
				'title' => __( '404 Monitor', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-engines'       => array(
				'view'  => 'engines',
				'title' => __( 'Search Engines', 'searchpilot-pro' ),
				'level' => 'manage',
				'extra' => array( 'engines-panels' ),
			),
			'searchpilot-automation'    => array(
				'view'  => 'automation',
				'title' => __( 'Automation', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-reports'       => array(
				'view'  => 'reports',
				'title' => __( 'Reports', 'searchpilot-pro' ),
				'level' => 'view',
			),
			'searchpilot-settings'      => array(
				'view'  => 'settings',
				'title' => __( 'Settings', 'searchpilot-pro' ),
				'level' => 'manage',
				'extra' => array( 'import' ),
			),
			'searchpilot-logs'          => array(
				'view'  => 'logs',
				'title' => __( 'System Logs', 'searchpilot-pro' ),
				'level' => 'manage',
			),
			'searchpilot-setup'         => array(
				'view'   => 'setup',
				'title'  => __( 'Setup Wizard', 'searchpilot-pro' ),
				'level'  => 'manage',
				'hidden' => true,
				'extra'  => array( 'engines-panels' ),
			),
		);
		/**
		 * Filters admin pages.
		 *
		 * @param array $pages Pages.
		 */
		return (array) apply_filters( 'searchpilot_admin_pages', $pages );
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_filter( 'plugin_action_links_' . SEARCHPILOT_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ), 20 );
		add_filter( 'admin_title', array( $this, 'admin_title' ), 10, 2 );
		add_filter( 'parent_file', array( $this, 'parent_file' ) );
	}

	/**
	 * Document title for pages without a menu entry (the setup wizard).
	 *
	 * @param string $admin_title Full title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public function admin_title( $admin_title, $title ) {
		$slug = $this->current_page();
		if ( '' === $slug || '' !== trim( (string) $title ) ) {
			return $admin_title;
		}
		/* translators: 1: page title, 2: site name. */
		return sprintf( __( '%1$s ‹ SearchPilot ‹ %2$s — WordPress', 'searchpilot-pro' ), self::pages()[ $slug ]['title'], get_bloginfo( 'name' ) );
	}

	/**
	 * Keeps the SearchPilot menu open on hidden pages.
	 *
	 * @param string $parent_file Parent file.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		$slug = $this->current_page();
		if ( '' !== $slug && ! empty( self::pages()[ $slug ]['hidden'] ) ) {
			global $submenu_file;
			$submenu_file = 'searchpilot'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return 'searchpilot';
		}
		return $parent_file;
	}

	/**
	 * Registers menus.
	 */
	public function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zm2.6 1.6-1.3 4.2-4.2 1.3 1.3-4.2 4.2-1.3z"/></svg>'
		);
		$pages = self::pages();
		$first = $pages['searchpilot'];

		$this->hooks['searchpilot'] = add_menu_page(
			__( 'SearchPilot Pro', 'searchpilot-pro' ),
			__( 'SearchPilot', 'searchpilot-pro' ),
			$this->cap( $first['level'] ),
			'searchpilot',
			array( $this, 'render' ),
			$icon,
			58
		);

		foreach ( $pages as $slug => $page ) {
			$parent               = empty( $page['hidden'] ) ? 'searchpilot' : '';
			$this->hooks[ $slug ] = add_submenu_page(
				$parent,
				$page['title'] . ' ‹ ' . __( 'SearchPilot', 'searchpilot-pro' ),
				$page['menu'] ?? $page['title'],
				$this->cap( $page['level'] ),
				$slug,
				array( $this, 'render' )
			);
			if ( ! empty( $page['hidden'] ) && $this->hooks[ $slug ] ) {
				// Pages without a menu entry have no title; admin-header.php would pass null to strip_tags().
				$page_title = $page['title'];
				add_action(
					'load-' . $this->hooks[ $slug ],
					static function () use ( $page_title ) {
						global $title;
						$title = $page_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					}
				);
			}
		}
	}

	/**
	 * Capability for a level.
	 *
	 * @param string $level view|manage.
	 * @return string
	 */
	private function cap( $level ) {
		return 'view' === $level ? Capabilities::VIEW : Capabilities::MANAGE;
	}

	/**
	 * Current SearchPilot page slug or ''.
	 *
	 * @return string
	 */
	private function current_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array_key_exists( $page, self::pages() ) ? $page : '';
	}

	/**
	 * Renders a page shell.
	 */
	public function render() {
		$slug = $this->current_page();
		if ( '' === $slug ) {
			return;
		}
		$page = self::pages()[ $slug ];
		?>
		<div class="wrap spp-wrap">
			<h1 class="screen-reader-text"><?php echo esc_html( $page['title'] ); ?></h1>
			<hr class="wp-header-end">
			<div id="spp-app" class="spp-app" data-view="<?php echo esc_attr( $page['view'] ); ?>">
				<div class="spp-boot">
					<span class="spp-spinner" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Loading SearchPilot…', 'searchpilot-pro' ); ?></span>
				</div>
			</div>
			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'SearchPilot needs JavaScript enabled in your browser.', 'searchpilot-pro' ); ?></p></div></noscript>
		</div>
		<?php
	}

	/**
	 * Enqueues assets on SearchPilot pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		$slug = array_search( $hook, $this->hooks, true );
		if ( false === $slug ) {
			return;
		}
		$page = self::pages()[ $slug ];
		$ver  = SEARCHPILOT_VERSION;

		wp_enqueue_style( 'searchpilot-admin', SEARCHPILOT_URL . 'assets/css/admin.css', array(), $ver );
		wp_enqueue_script( 'searchpilot-core', SEARCHPILOT_URL . 'assets/js/core.js', array( 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ), $ver, true );
		wp_set_script_translations( 'searchpilot-core', 'searchpilot-pro', SEARCHPILOT_DIR . 'languages' );
		wp_add_inline_script( 'searchpilot-core', 'window.SearchPilotBoot = ' . wp_json_encode( $this->boot_data( $slug ) ) . ';', 'before' );

		$views = array_merge( (array) ( $page['extra'] ?? array() ), array( $page['view'] ) );
		foreach ( $views as $view ) {
			$file = 'assets/js/views/' . $view . '.js';
			if ( ! is_readable( SEARCHPILOT_DIR . $file ) ) {
				continue;
			}
			wp_enqueue_script( 'searchpilot-view-' . $view, SEARCHPILOT_URL . $file, array( 'searchpilot-core' ), $ver, true );
			wp_set_script_translations( 'searchpilot-view-' . $view, 'searchpilot-pro', SEARCHPILOT_DIR . 'languages' );
		}
		if ( in_array( $page['view'], array( 'settings', 'setup', 'schema' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Data every view receives as window.SearchPilotBoot. Never contains secrets.
	 *
	 * @param string $slug Page slug.
	 * @return array
	 */
	private function boot_data( $slug ) {
		$plugin    = \SearchPilot\Plugin::instance();
		$env       = $plugin->get( Environment::class );
		$conflicts = $plugin->get( ConflictDetector::class );
		$pages     = array();
		foreach ( self::pages() as $page_slug => $page ) {
			$pages[ $page['view'] ] = admin_url( 'admin.php?page=' . $page_slug );
		}
		return array(
			'version'         => SEARCHPILOT_VERSION,
			'namespace'       => 'searchpilot/v1',
			'page'            => $slug,
			'view'            => self::pages()[ $slug ]['view'],
			'pages'           => $pages,
			'adminUrl'        => admin_url(),
			'assetsUrl'       => SEARCHPILOT_URL . 'assets/',
			'homeUrl'         => home_url( '/' ),
			'siteName'        => get_bloginfo( 'name' ),
			'locale'          => get_user_locale(),
			'timezone'        => wp_timezone_string(),
			'mode'            => $plugin->get( AutomationPolicy::class )->mode(),
			'wizardCompleted' => (bool) $this->state->get( 'wizard_completed', false ),
			'submissions'     => $env->submissions_allowed(),
			'heldReason'      => $env->submission_block_message(),
			'heldCode'        => $env->submission_block_reason(),
			'environment'     => $env->type(),
			'companion'       => $conflicts->primary_seo_plugin(),
			'companionName'   => $conflicts->detect()[ $conflicts->primary_seo_plugin() ]['name'] ?? '',
			'caps'            => array(
				'manage' => Capabilities::can_manage(),
				'view'   => Capabilities::can_view(),
				'edit'   => Capabilities::can_edit(),
			),
			'user'            => array(
				'id'   => get_current_user_id(),
				'name' => wp_get_current_user()->display_name,
			),
		);
	}

	/**
	 * Sends the activating administrator to the setup wizard once.
	 */
	public function activation_redirect() {
		$user = (int) get_transient( 'searchpilot_activation_redirect' );
		if ( ! $user || get_current_user_id() !== $user ) {
			return;
		}
		// Background requests (AJAX, heartbeat, iframes, form posts) leave the redirect for the next page view.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( wp_doing_ajax() || defined( 'IFRAME_REQUEST' ) || 'GET' !== $method ) {
			return;
		}
		delete_transient( 'searchpilot_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_network_admin() || isset( $_GET['activate-multi'] ) || ! Capabilities::can_manage() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=searchpilot-setup' ) );
		exit;
	}

	/**
	 * Plugin list links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$extra = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=searchpilot' ) ) . '">' . esc_html__( 'Dashboard', 'searchpilot-pro' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=searchpilot-settings' ) ) . '">' . esc_html__( 'Settings', 'searchpilot-pro' ) . '</a>',
		);
		if ( ! $this->state->get( 'wizard_completed', false ) ) {
			$extra[] = '<a href="' . esc_url( admin_url( 'admin.php?page=searchpilot-setup' ) ) . '"><strong>' . esc_html__( 'Run setup', 'searchpilot-pro' ) . '</strong></a>';
		}
		return array_merge( $extra, $links );
	}

	/**
	 * Body class on our pages.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		if ( '' !== $this->current_page() ) {
			$classes .= ' searchpilot-admin searchpilot-view-' . self::pages()[ $this->current_page() ]['view'];
		}
		return $classes;
	}

	/**
	 * Footer text on our pages.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function footer_text( $text ) {
		if ( '' === $this->current_page() ) {
			return $text;
		}
		/* translators: %s: version. */
		return esc_html( sprintf( __( 'SearchPilot Pro %s — works inside Google and Bing webmaster guidelines. Scores are internal diagnostics, not search-engine rankings.', 'searchpilot-pro' ), SEARCHPILOT_VERSION ) );
	}
}
