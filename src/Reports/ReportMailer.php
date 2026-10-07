<?php
/**
 * Report e-mails.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Reports;

use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a stored report as a premium HTML e-mail (inline CSS for e-mail
 * clients: ivory background, white card, navy headings, gold accents, KPI
 * tiles with coloured ▲▼ deltas) plus a plain-text alternative, and sends
 * it to `alerts.recipients` (or the site administrator).
 */
class ReportMailer {

	const IVORY  = '#f6f4ef';
	const NAVY   = '#1b2a4a';
	const GOLD   = '#b08d57';
	const TEXT   = '#1c2333';
	const MUTED  = '#7a8091';
	const BORDER = '#e6e1d6';
	const GOOD   = '#2e7d32';
	const BAD    = '#c62828';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Report builder.
	 *
	 * @var ReportBuilder
	 */
	private $builder;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Plain-text body for the current send (added as AltBody).
	 *
	 * @var string
	 */
	private $alt_body = '';

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param ReportBuilder $builder  Builder.
	 * @param Logger        $logger   Logger.
	 */
	public function __construct( Settings $settings, ReportBuilder $builder, Logger $logger ) {
		$this->settings = $settings;
		$this->builder  = $builder;
		$this->logger   = $logger;
	}

	/**
	 * Recipients: alerts.report_recipients, else the alert recipients, else the administrator.
	 *
	 * @return string[]
	 */
	public function recipients() {
		$list = array_values( array_filter( (array) $this->settings->get( 'alerts.report_recipients', array() ), 'is_email' ) );
		if ( ! $list ) {
			$list = array_values( array_filter( (array) $this->settings->get( 'alerts.recipients', array() ), 'is_email' ) );
		}
		if ( ! $list ) {
			$admin = (string) get_option( 'admin_email' );
			if ( is_email( $admin ) ) {
				$list[] = $admin;
			}
		}
		return $list;
	}

	/**
	 * Sends a stored report.
	 *
	 * @param array|int $report     Report (from ReportBuilder::get) or id.
	 * @param string[]  $recipients Override recipients.
	 * @return true|\WP_Error
	 */
	public function send( $report, array $recipients = array() ) {
		if ( ! is_array( $report ) ) {
			$report = $this->builder->get( (int) $report );
		}
		if ( ! $report ) {
			return new \WP_Error( 'searchpilot_not_found', __( 'Report not found.', 'searchpilot-pro' ), array( 'status' => 404 ) );
		}
		$to = $recipients ? array_values( array_filter( $recipients, 'is_email' ) ) : $this->recipients();
		if ( ! $to ) {
			return new \WP_Error( 'searchpilot_no_recipients', __( 'Add at least one report recipient in Settings → Alerts & reports.', 'searchpilot-pro' ), array( 'status' => 400 ) );
		}
		$this->alt_body = $this->render_text( $report );
		add_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		$sent = wp_mail( $to, $this->subject( $report ), $this->render_html( $report ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', array( $this, 'add_alt_body' ) );
		$this->alt_body = '';
		if ( ! $sent ) {
			$this->logger->warning( 'reports', sprintf( 'Report #%d could not be e-mailed.', (int) $report['id'] ), array( 'recipients' => count( $to ) ) );
			return new \WP_Error( 'searchpilot_mail_failed', __( 'WordPress could not send the e-mail. Check the site’s mail configuration (an SMTP plugin is recommended).', 'searchpilot-pro' ), array( 'status' => 500 ) );
		}
		$this->builder->mark_emailed( (int) $report['id'] );
		$this->logger->info( 'reports', sprintf( 'Report #%1$d e-mailed to %2$d recipient(s).', (int) $report['id'], count( $to ) ) );
		return true;
	}

	/**
	 * Adds the plain-text alternative to the outgoing message.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 */
	public function add_alt_body( $phpmailer ) {
		if ( '' !== $this->alt_body && is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Subject line.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public function subject( array $report ) {
		$d    = (array) $report['data'];
		$name = (string) ( $d['site']['name'] ?? get_bloginfo( 'name' ) );
		$type = self::type_label( (string) $report['type'] );
		$g    = (array) ( $d['engines']['google'] ?? array() );
		$tail = '';
		if ( ! empty( $g['has_data'] ) ) {
			if ( self::comparable( $g ) ) {
				$delta = self::delta( (float) ( $g['current']['clicks'] ?? 0 ), (float) ( $g['previous']['clicks'] ?? 0 ) );
				/* translators: 1: clicks, 2: change. */
				$tail = ' — ' . sprintf( __( '%1$s clicks (%2$s)', 'searchpilot-pro' ), number_format_i18n( (int) $g['current']['clicks'] ), $delta['text'] );
			} else {
				/* translators: %s: clicks. */
				$tail = ' — ' . sprintf( __( '%s clicks', 'searchpilot-pro' ), number_format_i18n( (int) $g['current']['clicks'] ) );
			}
		}
		/* translators: 1: report type, 2: site name, 3: period. */
		return wp_specialchars_decode( sprintf( __( '%1$s SEO report for %2$s: %3$s', 'searchpilot-pro' ), $type, $name, self::period_label( $report ) ), ENT_QUOTES ) . $tail;
	}

	/**
	 * HTML body.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public function render_html( array $report ) {
		$d    = (array) $report['data'];
		$site = (array) ( $d['site'] ?? array() );
		$out  = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $this->subject( $report ) ) . '</title></head>';
		$out .= '<body style="margin:0;padding:0;background:' . self::IVORY . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:' . self::TEXT . ';">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::IVORY . ';padding:28px 12px;"><tr><td align="center">';
		$out .= '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid ' . self::BORDER . ';border-radius:14px;overflow:hidden;">';

		// Header.
		$out .= '<tr><td style="background:' . self::NAVY . ';padding:26px 30px 22px;border-bottom:3px solid ' . self::GOLD . ';">';
		$out .= '<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:' . self::GOLD . ';font-weight:700;">' . esc_html__( 'SearchPilot Pro', 'searchpilot-pro' ) . ' · ' . esc_html( self::type_label( (string) $report['type'] ) ) . '</div>';
		$out .= '<div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:6px;">' . esc_html( (string) ( $site['name'] ?? '' ) ) . '</div>';
		$out .= '<div style="font-size:13px;color:#d9dde8;margin-top:4px;">' . esc_html( self::period_label( $report ) ) . ' · ' . esc_html__( 'compared with the previous period', 'searchpilot-pro' ) . '</div>';
		$out .= '</td></tr>';

		// Google KPIs.
		$out .= '<tr><td style="padding:24px 30px 6px;">';
		$out .= self::h2( __( 'Google Search', 'searchpilot-pro' ) );
		$out .= $this->kpi_block( (array) ( $d['engines']['google'] ?? array() ), true );
		$out .= '</td></tr>';

		// Bing KPIs.
		$bing = (array) ( $d['engines']['bing'] ?? array() );
		if ( ! empty( $bing['has_data'] ) || ! empty( $bing['connected'] ) ) {
			$out .= '<tr><td style="padding:14px 30px 6px;">';
			$out .= self::h2( __( 'Bing', 'searchpilot-pro' ) );
			$out .= $this->kpi_block( $bing, false );
			$out .= '</td></tr>';
		}

		// Top lists.
		$lists = (array) ( $d['lists'] ?? array() );
		$out  .= '<tr><td style="padding:14px 30px 6px;">';
		if ( isset( $lists['matches'] ) && ! $lists['matches'] ) {
			$out .= '<p style="font-size:12px;color:' . self::MUTED . ';margin:0 0 10px;">' . esc_html__( 'Top queries, top pages and rising/declining content are stored for the last 7 days, 28 days and 3, 6 or 12 months only, so they are not shown for this custom period.', 'searchpilot-pro' ) . '</p>';
		}
		$compare = ! isset( $lists['prev_complete'] ) || ! empty( $lists['prev_complete'] );
		$out    .= self::h2( __( 'Top queries', 'searchpilot-pro' ) );
		$out    .= $this->rows_table( (array) ( $lists['top_queries'] ?? array() ), 'query', $compare );
		$out    .= self::h2( __( 'Top pages', 'searchpilot-pro' ) );
		$out    .= $this->rows_table( (array) ( $lists['top_pages'] ?? array() ), 'page', $compare );
		if ( ! empty( $lists['range'] ) && ! empty( $lists['from'] ) ) {
			/* translators: 1: first day, 2: last day. */
			$out .= '<p style="font-size:11.5px;color:' . self::MUTED . ';margin:6px 0 0;">' . esc_html( sprintf( __( 'Top lists use the Search Console snapshot for %1$s – %2$s.', 'searchpilot-pro' ), self::date( $lists['from'] ), self::date( $lists['to'] ) ) ) . '</p>';
		}
		$out .= '</td></tr>';

		// Rising / declining.
		$out .= '<tr><td style="padding:14px 30px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
		$out .= '<td valign="top" width="50%" style="padding-right:10px;">' . self::h3( __( 'Rising content', 'searchpilot-pro' ) ) . $this->change_list( (array) ( $lists['rising'] ?? array() ), $compare ) . '</td>';
		$out .= '<td valign="top" width="50%" style="padding-left:10px;">' . self::h3( __( 'Declining content', 'searchpilot-pro' ) ) . $this->change_list( (array) ( $lists['declining'] ?? array() ), $compare ) . '</td>';
		$out .= '</tr></table></td></tr>';

		// Health.
		$out .= '<tr><td style="padding:14px 30px 6px;">';
		$out .= self::h2( __( 'Site health', 'searchpilot-pro' ) );
		$out .= $this->health_table( $d );
		$out .= '</td></tr>';

		// New critical/high issues.
		$new = (array) ( $d['issues']['new_critical'] ?? array() );
		if ( $new ) {
			$out .= '<tr><td style="padding:10px 30px 6px;">' . self::h3( __( 'New critical and high issues', 'searchpilot-pro' ) ) . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">';
			foreach ( $new as $issue ) {
				$color = 'critical' === $issue['severity'] ? self::BAD : '#d9480f';
				$out  .= '<tr><td style="padding:7px 0;border-bottom:1px solid ' . self::BORDER . ';"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $color . ';margin-right:8px;"></span><strong>' . esc_html( (string) $issue['label'] ) . '</strong><br><span style="color:' . self::MUTED . ';font-size:12px;">' . esc_html( '' !== (string) $issue['title'] ? (string) $issue['title'] : rawurldecode( (string) $issue['url'] ) ) . '</span></td></tr>';
			}
			$out .= '</table></td></tr>';
		}

		// Call to action + disclaimer.
		$out .= '<tr><td style="padding:22px 30px 26px;" align="left">';
		$out .= '<a href="' . esc_url( admin_url( 'admin.php?page=searchpilot' ) ) . '" style="display:inline-block;background:' . self::NAVY . ';color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:9px;">' . esc_html__( 'Open the SearchPilot dashboard', 'searchpilot-pro' ) . '</a>';
		$out .= '<p style="font-size:11.5px;line-height:1.55;color:' . self::MUTED . ';margin:18px 0 0;border-top:1px solid ' . self::BORDER . ';padding-top:14px;">' . esc_html( (string) ( $d['disclaimer'] ?? ReportBuilder::disclaimer() ) ) . '</p>';
		$out .= '</td></tr>';

		$out .= '</table>';
		/* translators: %s: site URL. */
		$out .= '<p style="font-size:11px;color:' . self::MUTED . ';margin:14px 0 0;">' . esc_html( sprintf( __( 'Sent by SearchPilot Pro on %s. Change recipients or turn reports off under SearchPilot → Reports.', 'searchpilot-pro' ), (string) ( $site['url'] ?? home_url( '/' ) ) ) ) . '</p>';
		$out .= '</td></tr></table></body></html>';
		return $out;
	}

	/**
	 * Plain-text body.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public function render_text( array $report ) {
		$d     = (array) $report['data'];
		$lines = array();

		$lines[] = strtoupper( self::type_label( (string) $report['type'] ) ) . ' — ' . wp_specialchars_decode( (string) ( $d['site']['name'] ?? '' ), ENT_QUOTES );
		$lines[] = self::period_label( $report ) . ' (' . __( 'compared with the previous period', 'searchpilot-pro' ) . ')';
		$lines[] = '';
		foreach ( array(
			'google' => __( 'Google Search', 'searchpilot-pro' ),
			'bing'   => __( 'Bing', 'searchpilot-pro' ),
		) as $engine => $label ) {
			$e = (array) ( $d['engines'][ $engine ] ?? array() );
			if ( 'bing' === $engine && empty( $e['has_data'] ) && empty( $e['connected'] ) ) {
				continue;
			}
			$lines[] = $label;
			if ( empty( $e['has_data'] ) ) {
				$lines[] = '  ' . ( empty( $e['connected'] ) ? __( 'Not connected.', 'searchpilot-pro' ) : __( 'No data for this period yet.', 'searchpilot-pro' ) );
				$lines[] = '';
				continue;
			}
			foreach ( $this->kpis( $e, 'google' === $engine ) as $kpi ) {
				$lines[] = sprintf( '  %-18s %12s  %s', $kpi['label'], $kpi['value'], $kpi['delta']['text'] );
			}
			if ( ! self::comparable( $e ) ) {
				$lines[] = '  ' . self::no_comparison_note( $e );
			}
			$lines[] = '';
		}
		$lists = (array) ( $d['lists'] ?? array() );
		foreach ( array(
			'top_queries' => __( 'Top queries', 'searchpilot-pro' ),
			'top_pages'   => __( 'Top pages', 'searchpilot-pro' ),
			'rising'      => __( 'Rising content', 'searchpilot-pro' ),
			'declining'   => __( 'Declining content', 'searchpilot-pro' ),
		) as $key => $label ) {
			$rows = (array) ( $lists[ $key ] ?? array() );
			if ( ! $rows ) {
				continue;
			}
			$lines[] = $label;
			foreach ( $rows as $i => $row ) {
				$name    = 'top_queries' === $key ? (string) $row['query'] : self::page_label( $row );
				if ( null === ( $row['prev_clicks'] ?? null ) || ( isset( $lists['prev_complete'] ) && ! $lists['prev_complete'] ) ) {
					/* translators: 1: rank, 2: name, 3: clicks. */
					$lines[] = sprintf( __( '  %1$d. %2$s — %3$s clicks', 'searchpilot-pro' ), $i + 1, $name, number_format_i18n( (int) $row['clicks'] ) );
					continue;
				}
				$lines[] = sprintf(
					/* translators: 1: rank, 2: name, 3: clicks, 4: change. */
					__( '  %1$d. %2$s — %3$s clicks (%4$s)', 'searchpilot-pro' ),
					$i + 1,
					$name,
					number_format_i18n( (int) $row['clicks'] ),
					self::delta( (float) $row['clicks'], (float) $row['prev_clicks'] )['text']
				);
			}
			$lines[] = '';
		}
		$lines[] = __( 'Site health', 'searchpilot-pro' );
		foreach ( $this->health_rows( $d ) as $row ) {
			$lines[] = '  ' . $row[0] . ': ' . $row[1];
		}
		$lines[] = '';
		foreach ( (array) ( $d['issues']['new_critical'] ?? array() ) as $issue ) {
			$lines[] = '  [' . strtoupper( (string) $issue['severity'] ) . '] ' . $issue['label'] . ' — ' . ( '' !== (string) $issue['title'] ? $issue['title'] : rawurldecode( (string) $issue['url'] ) );
		}
		$lines[] = '';
		$lines[] = __( 'Dashboard:', 'searchpilot-pro' ) . ' ' . admin_url( 'admin.php?page=searchpilot' );
		$lines[] = '';
		$lines[] = (string) ( $d['disclaimer'] ?? ReportBuilder::disclaimer() );
		return wp_specialchars_decode( implode( "\n", $lines ), ENT_QUOTES );
	}

	/* --------------------------------------------------------------------
	 * Building blocks
	 * ------------------------------------------------------------------ */

	/**
	 * KPI values for an engine.
	 *
	 * @param array $engine  Engine block.
	 * @param bool  $with_position Include CTR/position (Google).
	 * @return array[]
	 */
	private function kpis( array $engine, $with_position ) {
		$c   = (array) ( $engine['current'] ?? array() );
		$p   = (array) ( $engine['previous'] ?? array() );
		$out = self::comparable( $engine ) ? $this->kpis_with_deltas( $c, $p, $with_position ) : array();
		if ( $out ) {
			return $out;
		}
		$none = array(
			'text'  => __( 'no comparison', 'searchpilot-pro' ),
			'arrow' => '',
			'color' => self::MUTED,
		);
		$out  = array(
			array(
				'label' => __( 'Clicks', 'searchpilot-pro' ),
				'value' => number_format_i18n( (int) ( $c['clicks'] ?? 0 ) ),
				'delta' => $none,
			),
			array(
				'label' => __( 'Impressions', 'searchpilot-pro' ),
				'value' => number_format_i18n( (int) ( $c['impressions'] ?? 0 ) ),
				'delta' => $none,
			),
			array(
				'label' => __( 'CTR', 'searchpilot-pro' ),
				'value' => number_format_i18n( (float) ( $c['ctr'] ?? 0 ) * 100, 1 ) . '%',
				'delta' => $none,
			),
		);
		if ( $with_position ) {
			$out[] = array(
				'label' => __( 'Avg. position', 'searchpilot-pro' ),
				'value' => ! empty( $c['position'] ) ? number_format_i18n( (float) $c['position'], 1 ) : '—',
				'delta' => $none,
			);
		}
		return $out;
	}

	/**
	 * Whether an engine block's previous period is fully covered by stored data
	 * (reports built before the coverage flags existed count as comparable).
	 *
	 * @param array $engine Engine block.
	 * @return bool
	 */
	public static function comparable( array $engine ) {
		return ! isset( $engine['comparable'] ) || ! empty( $engine['comparable'] );
	}

	/**
	 * KPI values with changes against the previous period.
	 *
	 * @param array $c             Current totals.
	 * @param array $p             Previous totals.
	 * @param bool  $with_position Include position.
	 * @return array[]
	 */
	private function kpis_with_deltas( array $c, array $p, $with_position ) {
		$out = array(
			array(
				'label' => __( 'Clicks', 'searchpilot-pro' ),
				'value' => number_format_i18n( (int) ( $c['clicks'] ?? 0 ) ),
				'delta' => self::delta( (float) ( $c['clicks'] ?? 0 ), (float) ( $p['clicks'] ?? 0 ) ),
			),
			array(
				'label' => __( 'Impressions', 'searchpilot-pro' ),
				'value' => number_format_i18n( (int) ( $c['impressions'] ?? 0 ) ),
				'delta' => self::delta( (float) ( $c['impressions'] ?? 0 ), (float) ( $p['impressions'] ?? 0 ) ),
			),
			array(
				'label' => __( 'CTR', 'searchpilot-pro' ),
				'value' => number_format_i18n( (float) ( $c['ctr'] ?? 0 ) * 100, 1 ) . '%',
				'delta' => self::delta( (float) ( $c['ctr'] ?? 0 ), (float) ( $p['ctr'] ?? 0 ) ),
			),
		);
		if ( $with_position ) {
			$out[] = array(
				'label' => __( 'Avg. position', 'searchpilot-pro' ),
				'value' => ! empty( $c['position'] ) ? number_format_i18n( (float) $c['position'], 1 ) : '—',
				'delta' => self::position_delta( (float) ( $c['position'] ?? 0 ), (float) ( $p['position'] ?? 0 ) ),
			);
		}
		return $out;
	}

	/**
	 * KPI tiles (HTML).
	 *
	 * @param array $engine        Engine block.
	 * @param bool  $with_position Include position.
	 * @return string
	 */
	private function kpi_block( array $engine, $with_position ) {
		if ( empty( $engine['has_data'] ) ) {
			$msg = empty( $engine['connected'] )
				? __( 'Not connected — connect it under SearchPilot → Search Engines to include its data.', 'searchpilot-pro' )
				: __( 'No data for this period yet (search engines report with a delay).', 'searchpilot-pro' );
			return '<p style="font-size:13px;color:' . self::MUTED . ';margin:4px 0 12px;">' . esc_html( $msg ) . '</p>';
		}
		$kpis  = $this->kpis( $engine, $with_position );
		$width = (int) floor( 100 / count( $kpis ) );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;"><tr>';
		foreach ( $kpis as $i => $kpi ) {
			$pad   = 0 === $i ? 'padding:0 5px 0 0;' : ( count( $kpis ) - 1 === $i ? 'padding:0 0 0 5px;' : 'padding:0 5px;' );
			$html .= '<td width="' . $width . '%" valign="top" style="' . $pad . '"><div style="border:1px solid ' . self::BORDER . ';border-top:3px solid ' . self::GOLD . ';border-radius:10px;padding:12px 12px 10px;background:#fbfaf7;">';
			$html .= '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . self::MUTED . ';font-weight:700;">' . esc_html( $kpi['label'] ) . '</div>';
			$html .= '<div style="font-size:22px;font-weight:700;color:' . self::NAVY . ';margin:4px 0 2px;">' . esc_html( $kpi['value'] ) . '</div>';
			$html .= '<div style="font-size:12.5px;font-weight:700;color:' . esc_attr( $kpi['delta']['color'] ) . ';">' . esc_html( $kpi['delta']['text'] ) . '</div>';
			$html .= '</div></td>';
		}
		$html .= '</tr></table>';
		if ( ! self::comparable( $engine ) ) {
			$html .= '<p style="font-size:11.5px;color:' . self::MUTED . ';margin:8px 0 0;">' . esc_html( self::no_comparison_note( $engine ) ) . '</p>';
		}
		if ( ! empty( $engine['partial'] ) && ! empty( $engine['latest'] ) ) {
			/* translators: %s: date. */
			$html .= '<p style="font-size:11.5px;color:' . self::MUTED . ';margin:8px 0 12px;">' . esc_html( sprintf( __( 'Data available up to %s; the last days of the period are not reported yet.', 'searchpilot-pro' ), self::date( $engine['latest'] ) ) ) . '</p>';
		} else {
			$html .= '<div style="height:12px;"></div>';
		}
		return $html;
	}

	/**
	 * Query / page table (HTML).
	 *
	 * @param array  $rows    Rows.
	 * @param string $kind    query|page.
	 * @param bool   $compare Show change arrows (previous period fully covered).
	 * @return string
	 */
	private function rows_table( array $rows, $kind, $compare = true ) {
		if ( ! $rows ) {
			return '<p style="font-size:13px;color:' . self::MUTED . ';margin:4px 0 14px;">' . esc_html__( 'No data yet.', 'searchpilot-pro' ) . '</p>';
		}
		$th   = 'text-align:left;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:' . self::MUTED . ';padding:6px 6px;border-bottom:2px solid ' . self::BORDER . ';';
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:4px 0 14px;">';
		$html .= '<tr><th style="' . $th . '">' . esc_html( 'query' === $kind ? __( 'Query', 'searchpilot-pro' ) : __( 'Page', 'searchpilot-pro' ) ) . '</th><th style="' . $th . 'text-align:right;">' . esc_html__( 'Clicks', 'searchpilot-pro' ) . '</th><th style="' . $th . 'text-align:right;">' . esc_html__( 'Impr.', 'searchpilot-pro' ) . '</th><th style="' . $th . 'text-align:right;">' . esc_html__( 'Pos.', 'searchpilot-pro' ) . '</th></tr>';
		foreach ( $rows as $row ) {
			$delta = $compare && null !== ( $row['prev_clicks'] ?? null ) ? self::delta( (float) $row['clicks'], (float) $row['prev_clicks'] ) : array(
				'text'  => '',
				'arrow' => '',
				'color' => self::MUTED,
			);
			if ( 'query' === $kind ) {
				$name = esc_html( (string) $row['query'] );
			} else {
				$name = '<a href="' . esc_url( (string) $row['page'] ) . '" style="color:' . self::NAVY . ';text-decoration:none;">' . esc_html( self::truncate( self::page_label( $row ), 60 ) ) . '</a>';
			}
			$td    = 'padding:7px 6px;border-bottom:1px solid ' . self::BORDER . ';';
			$html .= '<tr><td style="' . $td . '">' . $name . '</td>';
			$html .= '<td style="' . $td . 'text-align:right;white-space:nowrap;"><strong>' . esc_html( number_format_i18n( (int) $row['clicks'] ) ) . '</strong> <span style="font-size:11.5px;color:' . esc_attr( $delta['color'] ) . ';">' . esc_html( $delta['arrow'] ) . '</span></td>';
			$html .= '<td style="' . $td . 'text-align:right;color:' . self::MUTED . ';">' . esc_html( number_format_i18n( (int) $row['impressions'] ) ) . '</td>';
			$html .= '<td style="' . $td . 'text-align:right;color:' . self::MUTED . ';">' . esc_html( ! empty( $row['position'] ) ? number_format_i18n( (float) $row['position'], 1 ) : '—' ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Rising/declining list (HTML).
	 *
	 * @param array $rows    Rows.
	 * @param bool  $compare Previous period fully covered.
	 * @return string
	 */
	private function change_list( array $rows, $compare = true ) {
		if ( ! $compare ) {
			return '<p style="font-size:13px;color:' . self::MUTED . ';margin:4px 0 12px;">' . esc_html__( 'Not available: the previous period is not fully covered by stored search data.', 'searchpilot-pro' ) . '</p>';
		}
		if ( ! $rows ) {
			return '<p style="font-size:13px;color:' . self::MUTED . ';margin:4px 0 12px;">' . esc_html__( 'No notable changes.', 'searchpilot-pro' ) . '</p>';
		}
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12.5px;margin-bottom:12px;">';
		foreach ( $rows as $row ) {
			$delta = (int) $row['delta_clicks'];
			$color = $delta >= 0 ? self::GOOD : self::BAD;
			$html .= '<tr><td style="padding:6px 0;border-bottom:1px solid ' . self::BORDER . ';">' . esc_html( self::truncate( self::page_label( $row ), 48 ) ) . '</td><td style="padding:6px 0 6px 8px;border-bottom:1px solid ' . self::BORDER . ';text-align:right;white-space:nowrap;font-weight:700;color:' . $color . ';">' . esc_html( ( $delta >= 0 ? '▲ +' : '▼ ' ) . number_format_i18n( $delta ) ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Health rows: label => value.
	 *
	 * @param array $d Report data.
	 * @return array<int,array{0:string,1:string}>
	 */
	private function health_rows( array $d ) {
		$index = (array) ( $d['index'] ?? array() );
		$iss   = (array) ( $d['issues'] ?? array() );
		$open  = (array) ( $iss['open'] ?? array() );
		$auto  = (array) ( $d['automation'] ?? array() );
		$sub   = (array) ( $d['submissions'] ?? array() );
		$opps  = (array) ( $d['opportunities'] ?? array() );
		$rows  = array(
			array( __( 'Indexed URLs', 'searchpilot-pro' ), number_format_i18n( (int) ( $index['indexed'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $index['total'] ?? 0 ) ) ),
			array( __( 'Not indexed', 'searchpilot-pro' ), number_format_i18n( (int) ( $index['not_indexed'] ?? 0 ) ) ),
			array( __( 'Crawl errors (4xx/5xx)', 'searchpilot-pro' ), number_format_i18n( (int) ( $index['errors_4xx'] ?? 0 ) + (int) ( $index['errors_5xx'] ?? 0 ) ) ),
			array(
				__( 'Open issues', 'searchpilot-pro' ),
				sprintf(
					/* translators: 1: total, 2: critical, 3: high. */
					__( '%1$s (%2$s critical, %3$s high)', 'searchpilot-pro' ),
					number_format_i18n( (int) ( $open['total'] ?? 0 ) ),
					number_format_i18n( (int) ( $open['critical'] ?? 0 ) ),
					number_format_i18n( (int) ( $open['high'] ?? 0 ) )
				),
			),
			array(
				__( 'New critical / high issues', 'searchpilot-pro' ),
				number_format_i18n( (int) ( $iss['new_counts']['critical'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $iss['new_counts']['high'] ?? 0 ) ),
			),
			array( __( 'Issues resolved', 'searchpilot-pro' ), number_format_i18n( (int) ( $iss['resolved'] ?? 0 ) ) ),
			array( __( 'Content opportunities', 'searchpilot-pro' ), number_format_i18n( (int) ( $opps['total'] ?? 0 ) ) ),
			array(
				__( 'Automation', 'searchpilot-pro' ),
				sprintf(
					/* translators: 1: changes, 2: automatic changes, 3: undone. */
					__( '%1$s changes (%2$s automatic, %3$s undone)', 'searchpilot-pro' ),
					number_format_i18n( (int) ( $auto['total'] ?? 0 ) ),
					number_format_i18n( (int) ( $auto['automatic'] ?? 0 ) ),
					number_format_i18n( (int) ( $auto['reverted'] ?? 0 ) )
				),
			),
			array(
				__( 'Search-engine submissions', 'searchpilot-pro' ),
				sprintf(
					/* translators: 1: done, 2: failed, 3: held. */
					__( '%1$s sent, %2$s failed, %3$s held', 'searchpilot-pro' ),
					number_format_i18n( (int) ( $sub['done'] ?? 0 ) ),
					number_format_i18n( (int) ( $sub['failed'] ?? 0 ) ),
					number_format_i18n( (int) ( $sub['held'] ?? 0 ) )
				),
			),
		);
		if ( ! empty( $d['sitemaps'] ) ) {
			$labels = array(
				'healthy'  => __( 'Healthy', 'searchpilot-pro' ),
				'warning'  => __( 'Warnings', 'searchpilot-pro' ),
				'error'    => __( 'Errors', 'searchpilot-pro' ),
				'disabled' => __( 'Disabled', 'searchpilot-pro' ),
				'unknown'  => __( 'Not checked yet', 'searchpilot-pro' ),
			);
			$status = (string) ( $d['sitemaps']['status'] ?? 'unknown' );
			$rows[] = array( __( 'Sitemaps', 'searchpilot-pro' ), ( $labels[ $status ] ?? $status ) . ( isset( $d['sitemaps']['url_count'] ) ? ' · ' . sprintf( /* translators: %s: URLs. */ __( '%s URLs', 'searchpilot-pro' ), number_format_i18n( (int) $d['sitemaps']['url_count'] ) ) : '' ) );
		}
		return $rows;
	}

	/**
	 * Health table (HTML).
	 *
	 * @param array $d Report data.
	 * @return string
	 */
	private function health_table( array $d ) {
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:4px 0 12px;">';
		foreach ( $this->health_rows( $d ) as $row ) {
			$html .= '<tr><td style="padding:7px 0;border-bottom:1px solid ' . self::BORDER . ';color:' . self::MUTED . ';">' . esc_html( $row[0] ) . '</td><td style="padding:7px 0;border-bottom:1px solid ' . self::BORDER . ';text-align:right;font-weight:600;color:' . self::NAVY . ';">' . esc_html( $row[1] ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Explains a missing comparison.
	 *
	 * @param array $engine Engine block.
	 * @return string
	 */
	private static function no_comparison_note( array $engine ) {
		$days = (int) ( $engine['previous']['days'] ?? 0 );
		return sprintf(
			/* translators: %s: number of days. */
			__( 'No change is shown: the stored search data covers only %s days of the previous period (Search Console keeps about 16 months; newly connected sites have less).', 'searchpilot-pro' ),
			number_format_i18n( (int) ( $engine['previous_covered'] ?? $days ) )
		);
	}

	/**
	 * Section heading.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function h2( $text ) {
		return '<h2 style="font-size:16px;font-weight:700;color:' . self::NAVY . ';margin:6px 0 10px;padding-left:10px;border-left:3px solid ' . self::GOLD . ';">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Sub heading.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function h3( $text ) {
		return '<h3 style="font-size:13.5px;font-weight:700;color:' . self::NAVY . ';margin:6px 0 6px;">' . esc_html( $text ) . '</h3>';
	}

	/**
	 * Relative change with arrow and colour (higher is better).
	 *
	 * @param float $current  Current.
	 * @param float $previous Previous.
	 * @return array{text:string,arrow:string,color:string}
	 */
	public static function delta( $current, $previous ) {
		if ( $previous <= 0 ) {
			return array(
				'text'  => $current > 0 ? __( 'new', 'searchpilot-pro' ) : '—',
				'arrow' => $current > 0 ? '▲' : '',
				'color' => $current > 0 ? self::GOOD : self::MUTED,
			);
		}
		$pct = ( $current - $previous ) / $previous;
		if ( abs( $pct ) < 0.005 ) {
			return array(
				'text'  => '±0%',
				'arrow' => '■',
				'color' => self::MUTED,
			);
		}
		$up = $pct > 0;
		return array(
			'text'  => ( $up ? '▲ +' : '▼ −' ) . number_format_i18n( abs( $pct ) * 100, abs( $pct ) < 0.1 ? 1 : 0 ) . '%',
			'arrow' => $up ? '▲' : '▼',
			'color' => $up ? self::GOOD : self::BAD,
		);
	}

	/**
	 * Position change (lower is better).
	 *
	 * @param float $current  Current.
	 * @param float $previous Previous.
	 * @return array{text:string,arrow:string,color:string}
	 */
	public static function position_delta( $current, $previous ) {
		if ( $current <= 0 || $previous <= 0 ) {
			return array(
				'text'  => '—',
				'arrow' => '',
				'color' => self::MUTED,
			);
		}
		$diff = $current - $previous;
		if ( abs( $diff ) < 0.05 ) {
			return array(
				'text'  => '±0.0',
				'arrow' => '■',
				'color' => self::MUTED,
			);
		}
		$better = $diff < 0;
		return array(
			'text'  => ( $better ? '▲ ' : '▼ ' ) . number_format_i18n( abs( $diff ), 1 ),
			'arrow' => $better ? '▲' : '▼',
			'color' => $better ? self::GOOD : self::BAD,
		);
	}

	/**
	 * Report type label.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'weekly'  => __( 'Weekly', 'searchpilot-pro' ),
			'monthly' => __( 'Monthly', 'searchpilot-pro' ),
			'custom'  => __( 'Custom', 'searchpilot-pro' ),
		);
		return $labels[ $type ] ?? $labels['custom'];
	}

	/**
	 * "1 Sep – 7 Sep 2026".
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function period_label( array $report ) {
		return self::date( (string) $report['period_start'] ) . ' – ' . self::date( (string) $report['period_end'] );
	}

	/**
	 * Formats a Y-m-d date with the site's date format.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private static function date( $date ) {
		$time = strtotime( substr( (string) $date, 0, 10 ) . ' 12:00:00 UTC' );
		return $time ? date_i18n( (string) get_option( 'date_format', 'j M Y' ), $time, true ) : (string) $date;
	}

	/**
	 * Post title, "Home page" or the readable URL of a page row.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function page_label( array $row ) {
		if ( '' !== (string) ( $row['title'] ?? '' ) ) {
			return (string) $row['title'];
		}
		if ( Url::normalize( (string) ( $row['page'] ?? '' ) ) === Url::normalize( home_url( '/' ) ) ) {
			return __( 'Home page', 'searchpilot-pro' );
		}
		return (string) ( $row['display_page'] ?? ( $row['page'] ?? '' ) );
	}

	/**
	 * Truncates for tables.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		$text = (string) $text;
		return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…' : $text;
	}
}
