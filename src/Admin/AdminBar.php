<?php
/**
 * Admin bar menu.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Admin;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Settings;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "SearchPilot" admin-bar item with open critical/high counts and, on
 * front-end singular pages, a link to that page's indexing details.
 */
class AdminBar implements Module {

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
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'menu' ), 90 );
	}

	/**
	 * Adds nodes.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function menu( $bar ) {
		if ( ! $this->settings->get( 'general.admin_bar' ) || ! Capabilities::can_view() ) {
			return;
		}
		$counts = get_transient( 'searchpilot_adminbar_counts' );
		if ( ! is_array( $counts ) ) {
			$counts = \SearchPilot\Plugin::instance()->get( IssueRepository::class )->counts( null, array( 'opportunity' ) );
			set_transient( 'searchpilot_adminbar_counts', $counts, 5 * MINUTE_IN_SECONDS );
		}
		$urgent = (int) $counts['critical'] + (int) $counts['high'];
		$badge  = $urgent ? ' <span class="count" style="display:inline-block;min-width:18px;padding:0 5px;margin-left:4px;border-radius:9px;background:' . ( $counts['critical'] ? '#c62828' : '#e8590c' ) . ';color:#fff;font-size:11px;line-height:18px;text-align:center">' . (int) $urgent . '</span>' : '';

		$bar->add_node(
			array(
				'id'    => 'searchpilot',
				'title' => esc_html__( 'SearchPilot', 'searchpilot-pro' ) . $badge,
				'href'  => admin_url( 'admin.php?page=searchpilot' ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'searchpilot',
				'id'     => 'searchpilot-audit',
				/* translators: 1: critical count, 2: high count. */
				'title'  => esc_html( sprintf( __( 'Issues: %1$d critical, %2$d high', 'searchpilot-pro' ), (int) $counts['critical'], (int) $counts['high'] ) ),
				'href'   => admin_url( 'admin.php?page=searchpilot-audit' ),
			)
		);
		if ( ! is_admin() && is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$bar->add_node(
				array(
					'parent' => 'searchpilot',
					'id'     => 'searchpilot-this-page',
					'title'  => esc_html__( 'Indexing status of this page', 'searchpilot-pro' ),
					'href'   => add_query_arg(
						array(
							'page'    => 'searchpilot-indexing',
							'post_id' => $post_id,
						),
						admin_url( 'admin.php' )
					),
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'searchpilot',
				'id'     => 'searchpilot-performance',
				'title'  => esc_html__( 'Search performance', 'searchpilot-pro' ),
				'href'   => admin_url( 'admin.php?page=searchpilot-performance' ),
			)
		);
	}
}
