<?php
/**
 * Admin notices and the WordPress dashboard widget.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Admin;

use SearchPilot\Contracts\Module;
use SearchPilot\Core\Capabilities;
use SearchPilot\Core\Credentials;
use SearchPilot\Core\Environment;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Database\Tables;
use SearchPilot\Indexer\UrlRepository;
use SearchPilot\Issues\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces critical problems (site noindexed, sitemap down, Search Console
 * disconnected…) across wp-admin, dismissible per user for 24 hours.
 */
class Notices implements Module {

	const DISMISS_META = 'searchpilot_notice_dismissed';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param IssueRepository $issues   Issues.
	 */
	public function __construct( Settings $settings, IssueRepository $issues ) {
		$this->settings = $settings;
		$this->issues   = $issues;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'network_admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_ajax_searchpilot_dismiss_notice', array( $this, 'dismiss' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	/**
	 * Prints notices.
	 */
	public function notices() {
		if ( ! Capabilities::can_manage() ) {
			return;
		}
		$plugin  = \SearchPilot\Plugin::instance();
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours    = $screen && false !== strpos( (string) $screen->id, 'searchpilot' );
		$state   = $plugin->get( State::class );

		// Setup reminder (not on our own pages — they show their own banner).
		if ( ! $ours && ! $state->get( 'wizard_completed', false ) && 'plugins' === ( $screen->id ?? '' ) ) {
			printf(
				'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a class="button button-primary" href="%3$s">%4$s</a></p></div>',
				esc_html__( 'SearchPilot Pro is almost ready.', 'searchpilot-pro' ),
				esc_html__( 'Connect Search Console, Bing and IndexNow in a few guided steps.', 'searchpilot-pro' ),
				esc_url( admin_url( 'admin.php?page=searchpilot-setup' ) ),
				esc_html__( 'Start setup', 'searchpilot-pro' )
			);
		}

		$error = $plugin->get( Credentials::class )->error();
		if ( $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>SearchPilot:</strong> %1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( $error->get_error_message() ),
				esc_url( admin_url( 'admin.php?page=searchpilot-engines' ) ),
				esc_html__( 'Open Search Engines', 'searchpilot-pro' )
			);
		}

		$env = $plugin->get( Environment::class );
		if ( $ours && ! $env->submissions_allowed() && 'setup' !== $env->submission_block_reason() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>SearchPilot:</strong> %s %s</p></div>',
				esc_html( $env->submission_block_message() ),
				esc_html__( 'Submissions are kept in the queue.', 'searchpilot-pro' )
			);
		}

		if ( ! $this->settings->get( 'alerts.admin_notices' ) || $ours ) {
			return;
		}
		$dismissed = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		if ( $dismissed && ( time() - $dismissed ) < DAY_IN_SECONDS ) {
			return;
		}
		$critical = $this->issues->query(
			array(
				'status'           => 'open',
				'severity'         => array( 'critical' ),
				'exclude_category' => array( 'opportunity' ),
				'per_page'         => 3,
			)
		);
		if ( empty( $critical['items'] ) ) {
			return;
		}
		$labels = array();
		foreach ( $critical['items'] as $issue ) {
			$labels[] = '<li>' . esc_html( $issue['label'] . ( $issue['title'] ? ' — ' . $issue['title'] : '' ) ) . '</li>';
		}
		$nonce = wp_create_nonce( 'searchpilot_dismiss_notice' );
		printf(
			'<div class="notice notice-error is-dismissible searchpilot-critical-notice" data-nonce="%1$s"><p><strong>%2$s</strong></p><ul style="list-style:disc;margin-left:20px">%3$s</ul><p><a class="button button-primary" href="%4$s">%5$s</a></p></div>',
			esc_attr( $nonce ),
			/* translators: %d: number of critical issues. */
			esc_html( sprintf( _n( 'SearchPilot found %d critical search visibility problem', 'SearchPilot found %d critical search visibility problems', (int) $critical['total'], 'searchpilot-pro' ), (int) $critical['total'] ) ),
			implode( '', $labels ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_url( admin_url( 'admin.php?page=searchpilot-audit' ) ),
			esc_html__( 'Review and fix', 'searchpilot-pro' )
		);
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.searchpilot-critical-notice .notice-dismiss' );
				if ( ! btn ) { return; }
				var box = btn.closest( '.searchpilot-critical-notice' );
				var body = new FormData();
				body.append( 'action', 'searchpilot_dismiss_notice' );
				body.append( '_ajax_nonce', box.getAttribute( 'data-nonce' ) );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * AJAX: dismiss the critical notice for 24 hours.
	 */
	public function dismiss() {
		check_ajax_referer( 'searchpilot_dismiss_notice' );
		if ( ! Capabilities::can_manage() ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::DISMISS_META, time() );
		wp_send_json_success();
	}

	/**
	 * Registers the WordPress dashboard widget.
	 */
	public function dashboard_widget() {
		if ( ! Capabilities::can_view() ) {
			return;
		}
		wp_add_dashboard_widget( 'searchpilot_overview', __( 'SearchPilot — search visibility', 'searchpilot-pro' ), array( $this, 'render_widget' ) );
	}

	/**
	 * Renders the widget (cheap queries only).
	 */
	public function render_widget() {
		global $wpdb;
		$cached = get_transient( 'searchpilot_dashboard_widget' );
		if ( is_array( $cached ) && isset( $cached['counts'], $cached['urls'] ) ) {
			$counts = $cached['counts'];
			$urls   = $cached['urls'];
			$perf   = (object) $cached['perf'];
		} else {
			$counts = $this->issues->counts( null, array( 'opportunity' ) );
			$urls   = \SearchPilot\Plugin::instance()->get( UrlRepository::class )->summary();
			$table  = Tables::perf_daily();
			$since  = gmdate( 'Y-m-d', time() - 31 * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$perf = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM {$table} WHERE engine = 'google' AND date >= %s", $since ) );
			set_transient(
				'searchpilot_dashboard_widget',
				array(
					'counts' => $counts,
					'urls'   => $urls,
					'perf'   => (array) $perf,
				),
				10 * MINUTE_IN_SECONDS
			);
		}
		$rows = array(
			__( 'Critical issues', 'searchpilot-pro' )         => (int) $counts['critical'],
			__( 'High-priority issues', 'searchpilot-pro' )    => (int) $counts['high'],
			__( 'Indexed URLs (inspected)', 'searchpilot-pro' ) => (int) $urls['indexed'],
			__( 'Not indexed', 'searchpilot-pro' )             => (int) $urls['not_indexed'],
		);
		if ( $perf && null !== $perf->clicks ) {
			$rows[ __( 'Google clicks, last 30 days', 'searchpilot-pro' ) ]      = number_format_i18n( (int) $perf->clicks );
			$rows[ __( 'Google impressions, last 30 days', 'searchpilot-pro' ) ] = number_format_i18n( (int) $perf->impressions );
		}
		echo '<table class="widefat striped" style="border:0"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><td>%s</td><td style="text-align:right"><strong>%s</strong></td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=searchpilot' ) ), esc_html__( 'Open SearchPilot', 'searchpilot-pro' ) );
	}
}
