<?php
/**
 * E-mail alerts for new severe issues.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Audit;

use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Scheduler;
use SearchPilot\Core\Settings;
use SearchPilot\Core\State;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * New issues at or above the configured severity are collected into a
 * digest and mailed at most every 30 minutes (from a background event).
 * The same issue type on the same object is not repeated within 24 hours.
 */
class Alerts {

	const DIGEST    = 'alert_digest';
	const SENT      = 'alert_sent';
	const LAST_SENT = 'alert_last_sent';
	const HOOK      = 'searchpilot_send_alerts';
	const INTERVAL  = 30 * MINUTE_IN_SECONDS;
	const MAX_ITEMS = 50;

	/**
	 * Severity colours (e-mail clients need inline values).
	 */
	const COLORS = array(
		'critical'    => array( '#c62828', '#fdecec' ),
		'high'        => array( '#d9480f', '#fff0e5' ),
		'medium'      => array( '#b7860b', '#fff7dd' ),
		'opportunity' => array( '#2f6fde', '#eaf1fe' ),
	);

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
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

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
	 * Plain-text alternative for the message being sent.
	 *
	 * @var string
	 */
	private $alt_body = '';

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings  Settings.
	 * @param State           $state     State.
	 * @param IssueRepository $issues    Issues.
	 * @param Scheduler       $scheduler Scheduler.
	 * @param Logger          $logger    Logger.
	 */
	public function __construct( Settings $settings, State $state, IssueRepository $issues, Scheduler $scheduler, Logger $logger ) {
		$this->settings  = $settings;
		$this->state     = $state;
		$this->issues    = $issues;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	/**
	 * searchpilot_issue_detected: queues qualifying issues.
	 *
	 * @param int    $id       Issue id.
	 * @param string $type     Type.
	 * @param string $severity Severity.
	 * @param array  $args     Upsert args.
	 */
	public function on_issue_detected( $id, $type, $severity, $args = array() ) {
		if ( ! $this->settings->get( 'alerts.email' ) ) {
			return;
		}
		$min = (string) $this->settings->get( 'alerts.min_severity', 'critical' );
		if ( IssueRegistry::rank( (string) $severity ) > IssueRegistry::rank( $min ) || 'opportunity' === $severity ) {
			return;
		}
		$args = is_array( $args ) ? $args : array();
		if ( $this->recently_sent( self::dedupe_key( (string) $type, (string) ( $args['object_type'] ?? '' ), (int) ( $args['object_id'] ?? 0 ) ) ) ) {
			return;
		}
		$digest = array_map( 'intval', (array) $this->state->get( self::DIGEST, array() ) );
		if ( in_array( (int) $id, $digest, true ) ) {
			return;
		}
		$digest[] = (int) $id;
		$this->state->set( self::DIGEST, array_slice( $digest, -self::MAX_ITEMS ) );

		$last  = (int) $this->state->get( self::LAST_SENT, 0 );
		$delay = max( 60, $last + self::INTERVAL - time() );
		$this->scheduler->enqueue_async( self::HOOK, array(), $delay );
	}

	/**
	 * Sends the queued digest (background event).
	 *
	 * @return bool Whether an e-mail was sent.
	 */
	public function send_digest() {
		$result = Lock::run(
			'alerts',
			120,
			function () {
				$last = (int) $this->state->get( self::LAST_SENT, 0 );
				if ( time() - $last < self::INTERVAL ) {
					$this->scheduler->enqueue_async( self::HOOK, array(), max( 60, $last + self::INTERVAL - time() ) );
					return false;
				}
				$ids = array_map( 'intval', (array) $this->state->get( self::DIGEST, array() ) );
				if ( ! $ids ) {
					return false;
				}
				$issues = array();
				$keys   = array();
				foreach ( $ids as $id ) {
					$issue = $this->issues->get( $id );
					if ( ! $issue || ! in_array( $issue['status'], IssueRepository::OPEN_STATUSES, true ) || 'opportunity' === $issue['category'] ) {
						continue;
					}
					$key = self::dedupe_key( $issue['type'], $issue['object_type'], (int) $issue['object_id'] );
					if ( isset( $keys[ $key ] ) || $this->recently_sent( $key ) ) {
						continue;
					}
					$keys[ $key ] = true;
					$issues[]     = $issue;
				}
				$this->state->set( self::DIGEST, array() );
				if ( ! $issues ) {
					return false;
				}
				usort(
					$issues,
					static function ( $a, $b ) {
						return IssueRegistry::rank( $a['severity'] ) <=> IssueRegistry::rank( $b['severity'] );
					}
				);
				$sent = $this->mail( $issues, false );
				if ( is_wp_error( $sent ) ) {
					$this->logger->warning( 'audit', 'Alert e-mail could not be sent: ' . $sent->get_error_message(), array( 'issues' => count( $issues ) ) );
					return false;
				}
				$map = $this->sent_map();
				foreach ( array_keys( $keys ) as $key ) {
					$map[ $key ] = time();
				}
				$this->state->set_many(
					array(
						self::SENT      => $map,
						self::LAST_SENT => time(),
					)
				);
				$this->logger->info( 'audit', sprintf( 'Alert e-mail sent (%d issues).', count( $issues ) ) );
				return true;
			}
		);
		return (bool) $result;
	}

	/**
	 * Sends a sample alert (POST /audit/test-alert).
	 *
	 * @return array{sent:bool,recipients:string[]}|\WP_Error
	 */
	public function send_test() {
		$sample = array(
			array(
				'id'          => 0,
				'type'        => 'accidental_noindex',
				'severity'    => 'critical',
				'category'    => 'indexing',
				'label'       => __( 'Page is set to noindex', 'searchpilot-pro' ),
				'title'       => __( 'Example: Budget 2026 — live updates', 'searchpilot-pro' ),
				'url'         => home_url( '/example-article/' ),
				'what'        => __( 'WordPress and your SEO settings say this page should be indexed, but the page as served contains noindex. Something else — the theme, another plugin or a server rule — adds it.', 'searchpilot-pro' ),
				'why'         => __( 'A noindex directive removes the page from search results completely.', 'searchpilot-pro' ),
				'fix'         => __( 'Find what outputs the directive and remove it, then re-check the URL.', 'searchpilot-pro' ),
				'object_type' => 'post',
				'object_id'   => 0,
			),
			array(
				'id'          => 0,
				'type'        => 'http_5xx',
				'severity'    => 'high',
				'category'    => 'technical',
				'label'       => __( 'Page returns a server error (5xx)', 'searchpilot-pro' ),
				'title'       => __( 'Example: Category archive', 'searchpilot-pro' ),
				'url'         => home_url( '/category/example/' ),
				'what'        => __( 'The page returned HTTP 503 when SearchPilot checked it.', 'searchpilot-pro' ),
				'why'         => __( 'Server errors make Google slow down crawling of the whole site.', 'searchpilot-pro' ),
				'fix'         => __( 'Check the PHP error log and server resources, then re-check the URL.', 'searchpilot-pro' ),
				'object_type' => 'term',
				'object_id'   => 0,
			),
		);
		$sent = $this->mail( $sample, true );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return array(
			'sent'       => true,
			'recipients' => $this->recipients(),
		);
	}

	/**
	 * Alert recipients (settings or the admin e-mail).
	 *
	 * @return string[]
	 */
	public function recipients() {
		$list = array_values( array_filter( (array) $this->settings->get( 'alerts.recipients', array() ), 'is_email' ) );
		if ( ! $list ) {
			$admin = (string) get_option( 'admin_email' );
			$list  = is_email( $admin ) ? array( $admin ) : array();
		}
		return $list;
	}

	/**
	 * Status for the UI.
	 *
	 * @return array
	 */
	public function status() {
		$last = (int) $this->state->get( self::LAST_SENT, 0 );
		return array(
			'enabled'      => (bool) $this->settings->get( 'alerts.email' ),
			'min_severity' => (string) $this->settings->get( 'alerts.min_severity', 'critical' ),
			'recipients'   => count( $this->recipients() ),
			'pending'      => count( (array) $this->state->get( self::DIGEST, array() ) ),
			'last_sent'    => $last ? gmdate( 'c', $last ) : null,
		);
	}

	/**
	 * Builds and sends one e-mail.
	 *
	 * @param array[] $issues Issues (hydrated rows or samples).
	 * @param bool    $test   Test message.
	 * @return true|\WP_Error
	 */
	private function mail( array $issues, $test ) {
		$to = $this->recipients();
		if ( ! $to ) {
			return new \WP_Error( 'searchpilot_no_recipients', __( 'No valid alert recipient. Add one in SearchPilot → Settings (alerts).', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$message = $this->build( $issues, $test );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$this->alt_body = $message['text'];
		add_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		$sent = wp_mail( $to, $message['subject'], $message['html'], $headers );
		remove_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		$this->alt_body = '';

		if ( ! $sent ) {
			return new \WP_Error( 'searchpilot_mail_failed', __( 'WordPress could not send the e-mail. Check the site’s mail/SMTP configuration.', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * phpmailer_init: adds the plain-text part.
	 *
	 * @param object $phpmailer PHPMailer.
	 */
	public function add_alt_body( $phpmailer ) {
		if ( '' !== $this->alt_body && is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Subject, HTML and text of an alert.
	 *
	 * @param array[] $issues Issues.
	 * @param bool    $test   Test message.
	 * @return array{subject:string,html:string,text:string}
	 */
	public function build( array $issues, $test = false ) {
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$audit    = admin_url( 'admin.php?page=searchpilot-audit' );
		$settings = admin_url( 'admin.php?page=searchpilot-settings' );
		$counts   = array();
		foreach ( $issues as $issue ) {
			$counts[ $issue['severity'] ] = ( $counts[ $issue['severity'] ] ?? 0 ) + 1;
		}
		$labels = array(
			'critical'    => __( 'Critical', 'searchpilot-pro' ),
			'high'        => __( 'High', 'searchpilot-pro' ),
			'medium'      => __( 'Medium', 'searchpilot-pro' ),
			'opportunity' => __( 'Opportunity', 'searchpilot-pro' ),
		);
		$parts = array();
		foreach ( array( 'critical', 'high', 'medium' ) as $sev ) {
			if ( ! empty( $counts[ $sev ] ) ) {
				$parts[] = sprintf( '%d %s', $counts[ $sev ], strtolower( $labels[ $sev ] ) );
			}
		}
		$subject = $test
			/* translators: %s: site name. */
			? sprintf( __( '[%s] SearchPilot test alert', 'searchpilot-pro' ), $site )
			: sprintf(
				/* translators: 1: site name, 2: counts such as "2 critical, 1 high". */
				_n( '[%1$s] SearchPilot: %2$s search visibility issue needs attention', '[%1$s] SearchPilot: %2$s search visibility issues need attention', count( $issues ), 'searchpilot-pro' ),
				$site,
				implode( ', ', $parts )
			);

		$intro = $test
			? __( 'This is a test of SearchPilot alerts. Real alerts look like this and list new problems that can hurt your visibility in search.', 'searchpilot-pro' )
			: __( 'SearchPilot found new problems that can hurt your visibility in search. Each one explains what happened, why it matters and how to fix it.', 'searchpilot-pro' );

		$cards = '';
		$text  = $subject . "\n\n" . $intro . "\n\n";
		foreach ( $issues as $issue ) {
			list( $fg, $bg ) = self::COLORS[ $issue['severity'] ] ?? self::COLORS['medium'];
			$object          = (string) ( $issue['title'] ?? '' );
			$url             = (string) ( $issue['url'] ?? '' );
			$cards          .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #e6e1d6;border-left:4px solid ' . esc_attr( $fg ) . ';border-radius:10px;background:#ffffff;">'
				. '<tr><td style="padding:16px 18px;">'
				. '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';font-size:12px;font-weight:600;">' . esc_html( $labels[ $issue['severity'] ] ?? $issue['severity'] ) . '</span>'
				. '<h3 style="margin:10px 0 2px;font-size:16px;line-height:1.35;color:#1b2a4a;">' . esc_html( (string) ( $issue['label'] ?? $issue['type'] ) ) . '</h3>'
				. ( '' !== $object ? '<div style="font-size:13px;color:#7a8091;margin-bottom:10px;">' . esc_html( $object ) . ( '' !== $url ? ' — <a href="' . esc_url( $url ) . '" style="color:#2a3d66;">' . esc_html( Url::display( $url ) ) . '</a>' : '' ) . '</div>' : '' )
				. self::section( __( 'What happened', 'searchpilot-pro' ), (string) ( $issue['what'] ?? '' ) )
				. self::section( __( 'Why it matters', 'searchpilot-pro' ), (string) ( $issue['why'] ?? '' ) )
				. self::section( __( 'Recommended fix', 'searchpilot-pro' ), (string) ( $issue['fix'] ?? '' ) )
				. '</td></tr></table>';

			$text .= sprintf( "[%s] %s\n", $labels[ $issue['severity'] ] ?? $issue['severity'], (string) ( $issue['label'] ?? $issue['type'] ) );
			if ( '' !== $object ) {
				$text .= $object . ( '' !== $url ? ' — ' . Url::display( $url ) : '' ) . "\n";
			}
			$text .= __( 'What happened', 'searchpilot-pro' ) . ': ' . wp_strip_all_tags( (string) ( $issue['what'] ?? '' ) ) . "\n";
			$text .= __( 'Why it matters', 'searchpilot-pro' ) . ': ' . wp_strip_all_tags( (string) ( $issue['why'] ?? '' ) ) . "\n";
			$text .= __( 'Recommended fix', 'searchpilot-pro' ) . ': ' . wp_strip_all_tags( (string) ( $issue['fix'] ?? '' ) ) . "\n\n";
		}
		/* translators: %s: URL of the SEO Audit screen. */
		$text .= sprintf( __( 'Open the SEO Audit: %s', 'searchpilot-pro' ), $audit ) . "\n";
		/* translators: %s: URL of the settings screen. */
		$text .= sprintf( __( 'Change alert settings: %s', 'searchpilot-pro' ), $settings ) . "\n";

		$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f6f4ef;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1c2333;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4ef;"><tr><td align="center" style="padding:28px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;">'
			. '<tr><td style="padding:0 4px 18px;">'
			. '<div style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;color:#b08d57;font-weight:600;">SearchPilot Pro</div>'
			. '<h1 style="margin:6px 0 4px;font-size:22px;line-height:1.3;color:#1b2a4a;">' . esc_html( $test ? __( 'Test alert', 'searchpilot-pro' ) : __( 'Search visibility alert', 'searchpilot-pro' ) ) . '</h1>'
			. '<div style="font-size:14px;color:#464d5e;">' . esc_html( $site ) . ' · ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:18px 20px;background:#ffffff;border:1px solid #e6e1d6;border-radius:14px;">'
			. '<p style="margin:0 0 18px;font-size:14px;line-height:1.55;color:#464d5e;">' . esc_html( $intro ) . '</p>'
			. $cards
			. '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 4px;"><tr><td style="border-radius:9px;background:#1b2a4a;">'
			. '<a href="' . esc_url( $audit ) . '" style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">' . esc_html__( 'Open the SEO Audit', 'searchpilot-pro' ) . '</a>'
			. '</td></tr></table>'
			. '</td></tr>'
			. '<tr><td style="padding:16px 4px;font-size:12px;line-height:1.5;color:#7a8091;">'
			. esc_html__( 'SearchPilot checks your site with official search engine APIs and its own page checks. It never promises rankings.', 'searchpilot-pro' )
			. ' <a href="' . esc_url( $settings ) . '" style="color:#7a8091;">' . esc_html__( 'Alert settings', 'searchpilot-pro' ) . '</a>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return array(
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		);
	}

	/**
	 * One labelled paragraph.
	 *
	 * @param string $label Label.
	 * @param string $text  Text.
	 * @return string
	 */
	private static function section( $label, $text ) {
		if ( '' === trim( $text ) ) {
			return '';
		}
		return '<div style="margin:8px 0 0;"><div style="font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#9a7843;font-weight:600;">' . esc_html( $label ) . '</div>'
			. '<div style="font-size:14px;line-height:1.55;color:#1c2333;">' . esc_html( $text ) . '</div></div>';
	}

	/**
	 * Dedupe key: issue type + object.
	 *
	 * @param string $type        Type.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return string
	 */
	private static function dedupe_key( $type, $object_type, $object_id ) {
		return md5( $type . '|' . $object_type . '|' . (int) $object_id );
	}

	/**
	 * Sent map with entries older than 24 hours removed.
	 *
	 * @return array<string,int>
	 */
	private function sent_map() {
		$map = (array) $this->state->get( self::SENT, array() );
		$cut = time() - DAY_IN_SECONDS;
		foreach ( $map as $key => $ts ) {
			if ( (int) $ts < $cut ) {
				unset( $map[ $key ] );
			}
		}
		return $map;
	}

	/**
	 * Whether an alert with this key went out in the last 24 hours.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function recently_sent( $key ) {
		$map = (array) $this->state->get( self::SENT, array() );
		return isset( $map[ $key ] ) && (int) $map[ $key ] > time() - DAY_IN_SECONDS;
	}
}
