<?php
/**
 * 404 monitor.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Redirects;

use SearchPilot\Core\AutomationPolicy;
use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\ContentTypes;
use SearchPilot\Core\Lock;
use SearchPilot\Core\Logger;
use SearchPilot\Core\Settings;
use SearchPilot\Database\Tables;
use SearchPilot\Issues\IssueRegistry;
use SearchPilot\Issues\IssueRepository;
use SearchPilot\Support\Url;

defined( 'ABSPATH' ) || exit;

/**
 * Logs 404 responses into `searchpilot_not_found` (one row per URL, hits
 * aggregated with INSERT … ON DUPLICATE KEY UPDATE), keeps hourly/daily
 * counters for charts and spike alerts, prunes old rows and suggests
 * redirect targets.
 *
 * Cost on a 404 page: one option read + write (counters) and one upsert,
 * capped at 120 writes per minute so floods cannot hammer the database.
 */
class NotFoundMonitor {

	const COUNTERS         = 'searchpilot_404_counters';
	const WRITES_PER_MINUTE = 120;
	const MAX_ROWS         = 20000;
	const FREQUENT_HITS    = 10;

	const ASSET_EXTENSIONS = array( 'js', 'mjs', 'css', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'm4v', 'webm', 'ogg', 'ogv', 'wav', 'zip', 'gz', 'tar', 'rar', '7z', 'bak', 'old', 'sql', 'env', 'ini', 'log', 'swp', 'php', 'asp', 'aspx', 'jsp', 'cgi', 'txt', 'xml', 'json', 'yml', 'yaml' );

	const BOT_PATTERN = '#bot\b|bot/|crawl|spider|slurp|mediapartners|adsbot|bingpreview|facebookexternalhit|embedly|quora link preview|whatsapp|telegram|discord|google-inspectiontool|google-read-aloud|lighthouse|headless|python|curl/|wget|go-http-client|java/|okhttp|libwww|httpclient|axios|node-fetch|scrapy|semrush|ahrefs|mj12|dotbot|petalbot|yandex|baiduspider|bytespider|gptbot|claudebot|ccbot|amazonbot|applebot|duckduckbot#i';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Issues.
	 *
	 * @var IssueRepository
	 */
	private $issues;

	/**
	 * Content types.
	 *
	 * @var ContentTypes
	 */
	private $types;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Redirect module (set in register()).
	 *
	 * @var RedirectsModule|null
	 */
	private $module = null;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ConflictDetector $conflicts Conflict detector.
	 * @param IssueRepository  $issues    Issues.
	 * @param ContentTypes     $types     Content types.
	 * @param Logger           $logger    Logger.
	 */
	public function __construct( Settings $settings, ConflictDetector $conflicts, IssueRepository $issues, ContentTypes $types, Logger $logger ) {
		$this->settings  = $settings;
		$this->conflicts = $conflicts;
		$this->issues    = $issues;
		$this->types     = $types;
		$this->logger    = $logger;
	}

	/**
	 * Hooks (called by RedirectsModule).
	 *
	 * @param RedirectsModule $module Redirect module.
	 */
	public function register( RedirectsModule $module ) {
		$this->module = $module;
		add_action( 'template_redirect', array( $this, 'log_request' ), 9999 );
		add_action( 'searchpilot_hourly', array( $this, 'schedule_hourly' ), 35 );
		add_action( 'searchpilot_404_hourly', array( $this, 'hourly' ) );
		add_action( 'searchpilot_maintenance', array( $this, 'prune' ) );
		add_action( 'searchpilot_register_issue_types', array( $this, 'register_issue_types' ) );
		add_filter( 'searchpilot_dashboard_summary', array( $this, 'dashboard' ) );
	}

	/**
	 * Whether 404s are recorded on this site.
	 *
	 * @return bool
	 */
	public function active() {
		return (bool) $this->settings->get( 'redirects.monitor_404', true ) && $this->conflicts->owns( 'not_found' );
	}

	/**
	 * Whether a user agent looks like a bot (pure).
	 *
	 * @param string $user_agent User agent.
	 * @return bool
	 */
	public static function is_bot( $user_agent ) {
		$user_agent = trim( (string) $user_agent );
		return '' === $user_agent || (bool) preg_match( self::BOT_PATTERN, $user_agent );
	}

	/**
	 * Whether a path is a static-asset probe (pure).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_asset( $path ) {
		list( $p ) = RedirectMatcher::split( (string) $path );
		$ext       = strtolower( (string) pathinfo( $p, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, self::ASSET_EXTENSIONS, true ) ) {
			return true;
		}
		return (bool) preg_match( '#^/(wp-content|wp-includes)/#i', $p );
	}

	/**
	 * template_redirect (late): records the current 404.
	 */
	public function log_request() {
		if ( ! is_404() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( $this->module && $this->module->is_gone() ) {
			return;
		}
		if ( ! $this->active() || current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalized and escaped below; never output raw.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = RedirectMatcher::normalize_path( $uri );
		if ( '' === $path || self::is_asset( $path ) || RedirectMatcher::is_excluded( $path, array(), RedirectsModule::home_path() ) ) {
			return;
		}
		if ( RedirectMatcher::wildcard_match( $path, (string) $this->settings->get( 'redirects.ignore_patterns', '' ) ) ) {
			return;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( false !== stripos( $agent, 'SearchPilotPro/' ) ) {
			return; // SearchPilot's own loopback checks.
		}
		$is_bot = self::is_bot( $agent );
		if ( $is_bot && ! $this->settings->get( 'redirects.log_bots', true ) ) {
			return;
		}
		if ( ! $this->count_hit( $is_bot ) ) {
			return; // Flood protection.
		}
		$referrer = (string) wp_get_raw_referer();
		$this->record( Url::origin( home_url( '/' ) ) . $path, $referrer, $agent, $is_bot );
	}

	/**
	 * Updates the minute/hour/day counters. Returns false when the per-minute cap is reached.
	 *
	 * @param bool $is_bot Bot hit.
	 * @return bool
	 */
	private function count_hit( $is_bot ) {
		$counters = $this->counters();
		$minute   = gmdate( 'YmdHi' );
		$hour     = gmdate( 'YmdH' );
		$day      = gmdate( 'Y-m-d' );
		if ( ( $counters['m'][0] ?? '' ) === $minute ) {
			if ( (int) $counters['m'][1] >= self::WRITES_PER_MINUTE ) {
				return false;
			}
			++$counters['m'][1];
		} else {
			$counters['m'] = array( $minute, 1 );
		}
		$counters['h'][ $hour ] = (int) ( $counters['h'][ $hour ] ?? 0 ) + 1;
		if ( ! isset( $counters['d'][ $day ] ) ) {
			$counters['d'][ $day ] = array( 0, 0 );
		}
		++$counters['d'][ $day ][0];
		if ( $is_bot ) {
			++$counters['d'][ $day ][1];
		}
		// Keep 72 hours and 35 days.
		$min_hour = gmdate( 'YmdH', time() - 72 * HOUR_IN_SECONDS );
		foreach ( array_keys( $counters['h'] ) as $key ) {
			if ( (string) $key < $min_hour ) {
				unset( $counters['h'][ $key ] );
			}
		}
		$min_day = gmdate( 'Y-m-d', time() - 35 * DAY_IN_SECONDS );
		foreach ( array_keys( $counters['d'] ) as $key ) {
			if ( (string) $key < $min_day ) {
				unset( $counters['d'][ $key ] );
			}
		}
		update_option( self::COUNTERS, $counters, false );
		return true;
	}

	/**
	 * Counters option.
	 *
	 * @return array{m:array,h:array,d:array}
	 */
	private function counters() {
		$counters = get_option( self::COUNTERS, array() );
		$counters = is_array( $counters ) ? $counters : array();
		return array(
			'm' => isset( $counters['m'] ) && is_array( $counters['m'] ) ? $counters['m'] : array( '', 0 ),
			'h' => isset( $counters['h'] ) && is_array( $counters['h'] ) ? $counters['h'] : array(),
			'd' => isset( $counters['d'] ) && is_array( $counters['d'] ) ? $counters['d'] : array(),
		);
	}

	/**
	 * Upserts a 404 row.
	 *
	 * @param string $url      Absolute URL.
	 * @param string $referrer Referrer.
	 * @param string $agent    User agent.
	 * @param bool   $is_bot   Bot.
	 */
	public function record( $url, $referrer, $agent, $is_bot ) {
		global $wpdb;
		$url      = mb_substr( Url::normalize( $url ), 0, 2000 );
		$referrer = mb_substr( esc_url_raw( (string) $referrer ), 0, 2000 );
		$agent    = mb_substr( (string) $agent, 0, 255 );
		$now      = current_time( 'mysql', true );
		$table    = Tables::not_found();
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (url_hash, url, referrer, user_agent, is_bot, hits, status, redirect_id, first_seen, last_seen)
				VALUES (%s, %s, %s, %s, %d, 1, 'open', 0, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = COALESCE(NULLIF(VALUES(referrer), ''), referrer), user_agent = VALUES(user_agent), is_bot = VALUES(is_bot)",
				Url::hash( $url ),
				$url,
				$referrer,
				$agent,
				$is_bot ? 1 : 0,
				$now,
				$now
			)
		);
		$wpdb->suppress_errors( $suppress );
	}

	/* ------------------------------------------------------------------ *
	 * Queries
	 * ------------------------------------------------------------------ */

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = Tables::not_found();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->present( $row ) : null;
	}

	/**
	 * Lists rows.
	 *
	 * @param array $filters search, bots (include|exclude|only), status (open|ignored|redirected|any), orderby (hits|last_seen|first_seen), order, page, per_page.
	 * @return array{items:array,total:int}
	 */
	public function query( array $filters = array() ) {
		global $wpdb;
		$table    = Tables::not_found();
		$where    = array( '1=1' );
		$params   = array();
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 200, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );
		$status   = (string) ( $filters['status'] ?? 'open' );
		if ( in_array( $status, array( 'open', 'ignored', 'redirected' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$bots = (string) ( $filters['bots'] ?? 'include' );
		if ( 'exclude' === $bots ) {
			$where[] = 'is_bot = 0';
		} elseif ( 'only' === $bots ) {
			$where[] = 'is_bot = 1';
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = '(url LIKE %s OR url LIKE %s OR referrer LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$params[] = '%' . $wpdb->esc_like( Url::encode_iri( (string) $filters['search'] ) ) . '%';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'last_seen >= %s';
			$params[] = (string) $filters['since'];
		}
		$orders    = array(
			'hits'       => 'hits',
			'last_seen'  => 'last_seen',
			'first_seen' => 'first_seen',
			'url'        => 'url',
		);
		$orderby   = $orders[ $filters['orderby'] ?? 'last_seen' ] ?? 'last_seen';
		$order     = 'ASC' === strtoupper( (string) ( $filters['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( $this, 'present' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Row formatting.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public function present( array $row ) {
		$referrer = (string) ( $row['referrer'] ?? '' );
		return array(
			'id'                => (int) $row['id'],
			'url'               => (string) $row['url'],
			'path'              => Url::to_path( (string) $row['url'] ),
			'display'           => Url::display( (string) $row['url'] ),
			'referrer'          => $referrer,
			'referrer_internal' => '' !== $referrer && Url::is_internal( $referrer ),
			'user_agent'        => (string) $row['user_agent'],
			'is_bot'            => (bool) (int) $row['is_bot'],
			'hits'              => (int) $row['hits'],
			'status'            => (string) $row['status'],
			'redirect_id'       => (int) $row['redirect_id'],
			'first_seen'        => mysql_to_rfc3339( $row['first_seen'] ),
			'last_seen'         => mysql_to_rfc3339( $row['last_seen'] ),
		);
	}

	/**
	 * Changes the status of rows.
	 *
	 * @param int[]  $ids         Ids.
	 * @param string $status      open|ignored|redirected.
	 * @param int    $redirect_id Rule id when redirected.
	 * @return int
	 */
	public function set_status( array $ids, $status, $redirect_id = 0 ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) || ! in_array( $status, array( 'open', 'ignored', 'redirected' ), true ) ) {
			return 0;
		}
		$table = Tables::not_found();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, redirect_id = %d WHERE id IN (" . implode( ',', $ids ) . ')', $status, (int) $redirect_id ) );
	}

	/**
	 * Marks every logged variant of a path (with any query string) as redirected.
	 *
	 * @param string $bare_path   Path without query.
	 * @param int    $redirect_id Rule id.
	 * @return int[] Affected row ids.
	 */
	public function mark_redirected( $bare_path, $redirect_id ) {
		global $wpdb;
		$table = Tables::not_found();
		$url   = Url::normalize( Url::origin( home_url( '/' ) ) . $bare_path );
		$alt   = '/' !== substr( $url, -1 ) ? $url . '/' : rtrim( $url, '/' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE url = %s OR url = %s OR url LIKE %s OR url LIKE %s LIMIT 500", $url, $alt, $wpdb->esc_like( $url . '?' ) . '%', $wpdb->esc_like( $alt . '?' ) . '%' ) ) );
		if ( $ids ) {
			$this->set_status( $ids, 'redirected', (int) $redirect_id );
			foreach ( $ids as $id ) {
				$this->issues->resolve_stale_for_object( 'url', $id, array( 'frequent_404' ), array() );
			}
		}
		return $ids;
	}

	/**
	 * Deletes rows.
	 *
	 * @param int[] $ids Ids.
	 * @return int
	 */
	public function delete( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table = Tables::not_found();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' );
	}

	/**
	 * Clears the log (optionally only rows not seen for a number of days).
	 *
	 * @param int $older_than_days Days (0 = everything).
	 * @return int Rows deleted.
	 */
	public function clear( $older_than_days = 0 ) {
		global $wpdb;
		$table = Tables::not_found();
		if ( $older_than_days > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - (int) $older_than_days * DAY_IN_SECONDS ) ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->query( "DELETE FROM {$table}" );
		delete_option( self::COUNTERS );
		return $n;
	}

	/**
	 * Statistics for the 404 screen.
	 *
	 * @return array
	 */
	public function stats() {
		global $wpdb;
		$table    = Tables::not_found();
		$counters = $this->counters();
		$labels   = array();
		$hits     = array();
		$bots     = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$day      = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$labels[] = $day;
			$hits[]   = (int) ( $counters['d'][ $day ][0] ?? 0 );
			$bots[]   = (int) ( $counters['d'][ $day ][1] ?? 0 );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$totals = (array) $wpdb->get_row( "SELECT COUNT(*) AS urls, SUM(status = 'open') AS open_n, SUM(status = 'ignored') AS ignored_n, SUM(status = 'redirected') AS redirected_n, SUM(is_bot = 1) AS bot_urls FROM {$table}", ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$refs = $wpdb->get_results( "SELECT referrer, SUM(hits) AS hits, COUNT(*) AS urls FROM {$table} WHERE referrer IS NOT NULL AND referrer <> '' AND status = 'open' GROUP BY referrer ORDER BY hits DESC LIMIT 10", ARRAY_A );
		$top  = array();
		foreach ( (array) $refs as $ref ) {
			$top[] = array(
				'referrer' => (string) $ref['referrer'],
				'display'  => Url::display( (string) $ref['referrer'] ),
				'internal' => Url::is_internal( (string) $ref['referrer'] ),
				'hits'     => (int) $ref['hits'],
				'urls'     => (int) $ref['urls'],
			);
		}
		$summary = $this->summary();
		return array(
			'last_24h'      => $summary['last_24h'],
			'last_7d'       => $summary['last_7d'],
			'open'          => (int) ( $totals['open_n'] ?? 0 ),
			'ignored'       => (int) ( $totals['ignored_n'] ?? 0 ),
			'redirected'    => (int) ( $totals['redirected_n'] ?? 0 ),
			'urls'          => (int) ( $totals['urls'] ?? 0 ),
			'bot_urls'      => (int) ( $totals['bot_urls'] ?? 0 ),
			'series'        => array(
				'labels' => $labels,
				'hits'   => $hits,
				'bots'   => $bots,
			),
			'top_referrers' => $top,
			'active'        => $this->active(),
			'owner'         => $this->conflicts->owner( 'not_found' ),
			'monitoring'    => (bool) $this->settings->get( 'redirects.monitor_404', true ),
			'log_bots'      => (bool) $this->settings->get( 'redirects.log_bots', true ),
			'retention'     => (int) $this->settings->get( 'redirects.retention_404_days', 60 ),
			'ignore'        => (string) $this->settings->get( 'redirects.ignore_patterns', '' ),
		);
	}

	/**
	 * Hits in the last 24 hours / 7 days and open URLs.
	 *
	 * @return array{last_24h:int,last_7d:int,open:int}
	 */
	public function summary() {
		global $wpdb;
		$counters = $this->counters();
		$last_24  = 0;
		for ( $i = 0; $i < 24; $i++ ) {
			$last_24 += (int) ( $counters['h'][ gmdate( 'YmdH', time() - $i * HOUR_IN_SECONDS ) ] ?? 0 );
		}
		$last_7 = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$last_7 += (int) ( $counters['d'][ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ][0] ?? 0 );
		}
		$table = Tables::not_found();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" );
		return array(
			'last_24h' => $last_24,
			'last_7d'  => $last_7,
			'open'     => $open,
		);
	}

	/**
	 * searchpilot_dashboard_summary filter.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	public function dashboard( $summary ) {
		$summary              = is_array( $summary ) ? $summary : array();
		$summary['not_found'] = $this->summary();
		return $summary;
	}

	/* ------------------------------------------------------------------ *
	 * Suggestions
	 * ------------------------------------------------------------------ */

	/**
	 * Redirect target suggestions for a 404 URL: old slugs, identical slugs,
	 * similar slugs (similar_text/levenshtein over a small candidate set) and terms.
	 *
	 * @param string $url   URL.
	 * @param int    $limit Max suggestions.
	 * @return array<int,array{object_type:string,object_id:int,title:string,url:string,score:float,reason:string}>
	 */
	public function suggest( $url, $limit = 5 ) {
		global $wpdb;
		list( $path ) = RedirectMatcher::split( Url::to_path( (string) $url ) );
		$segments     = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		while ( $segments && preg_match( '/^(amp|feed|page|embed|\d+|trackback|comment-page-\d+)$/i', (string) end( $segments ) ) ) {
			array_pop( $segments );
		}
		if ( empty( $segments ) ) {
			return array();
		}
		$slug  = strtolower( (string) end( $segments ) );
		$slug  = (string) preg_replace( '/\.(html?|php|aspx?)$/', '', $slug );
		$types = $this->types->indexable_post_types();
		if ( '' === $slug || empty( $types ) ) {
			return array();
		}
		$holders    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$candidates = array();
		$add        = static function ( $post_id, $score, $reason ) use ( &$candidates ) {
			$post_id = (int) $post_id;
			if ( ! isset( $candidates[ 'p' . $post_id ] ) || $candidates[ 'p' . $post_id ]['score'] < $score ) {
				$candidates[ 'p' . $post_id ] = array(
					'object_type' => 'post',
					'object_id'   => $post_id,
					'score'       => $score,
					'reason'      => $reason,
				);
			}
		};

		// Old slugs WordPress remembers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 5", $slug ) );
		foreach ( (array) $old as $post_id ) {
			$add( $post_id, 1.0, 'old_slug' );
		}
		// Same slug elsewhere (date or category changed).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$same = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish' AND post_type IN ({$holders}) LIMIT 5", array_merge( array( $slug ), $types ) ) );
		foreach ( (array) $same as $post_id ) {
			$add( $post_id, 0.95, 'same_slug' );
		}
		// Similar slugs: the two longest words as LIKE filters, ranked in PHP.
		$words = array_filter(
			preg_split( '/[-_]+/', $slug ),
			static function ( $w ) {
				return strlen( $w ) >= 3;
			}
		);
		usort(
			$words,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$words = array_slice( array_values( $words ), 0, 2 );
		if ( $words ) {
			$likes  = implode( ' OR ', array_fill( 0, count( $words ), 'post_name LIKE %s' ) );
			$params = array_merge(
				array_map(
					static function ( $w ) use ( $wpdb ) {
						return '%' . $wpdb->esc_like( $w ) . '%';
					},
					$words
				),
				$types
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE ({$likes}) AND post_status = 'publish' AND post_type IN ({$holders}) ORDER BY post_date_gmt DESC LIMIT 30", $params ) );
			foreach ( (array) $rows as $row ) {
				$score = self::similarity( $slug, (string) $row->post_name );
				if ( $score >= 0.35 ) {
					$add( $row->ID, round( $score * 0.9, 3 ), 'similar' );
				}
			}
		}
		// Terms with this exact slug (category or tag archives).
		$taxonomies = array_keys( $this->types->taxonomies() );
		if ( $taxonomies ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomies,
					'slug'       => $slug,
					'hide_empty' => true,
					'number'     => 3,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$candidates[ 't' . $term->term_id ] = array(
						'object_type' => 'term',
						'object_id'   => (int) $term->term_id,
						'score'       => 0.9,
						'reason'      => 'term',
					);
				}
			}
		}

		uasort(
			$candidates,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$out = array();
		foreach ( $candidates as $candidate ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( 'post' === $candidate['object_type'] ) {
				$post = get_post( $candidate['object_id'] );
				if ( ! $post || ! $this->types->is_post_indexable( $post ) ) {
					continue;
				}
				$link  = get_permalink( $post );
				$title = get_the_title( $post );
			} else {
				$term = get_term( $candidate['object_id'] );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$link  = get_term_link( $term );
				$title = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
			if ( ! $link || is_wp_error( $link ) || Url::normalize( $link ) === Url::normalize( $url ) ) {
				continue;
			}
			$out[] = array(
				'object_type' => $candidate['object_type'],
				'object_id'   => $candidate['object_id'],
				'title'       => wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ),
				'url'         => $link,
				'path'        => Url::to_path( $link ),
				'score'       => (float) $candidate['score'],
				'reason'      => $candidate['reason'],
			);
		}
		return $out;
	}

	/**
	 * Similarity of two slugs, 0..1 (pure).
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return float
	 */
	public static function similarity( $a, $b ) {
		$a = strtolower( (string) $a );
		$b = strtolower( (string) $b );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return 1.0;
		}
		$percent = 0.0;
		similar_text( $a, $b, $percent );
		$score = $percent / 100;
		if ( strlen( $a ) <= 255 && strlen( $b ) <= 255 ) {
			$lev   = levenshtein( $a, $b );
			$score = max( $score, 1 - $lev / max( strlen( $a ), strlen( $b ) ) );
		}
		return max( 0.0, min( 1.0, $score ) );
	}

	/* ------------------------------------------------------------------ *
	 * Background
	 * ------------------------------------------------------------------ */

	/**
	 * Daily prune (retention days, then a hard cap keeping the most-hit URLs).
	 */
	public function prune() {
		global $wpdb;
		$table = Tables::not_found();
		$days  = max( 1, (int) $this->settings->get( 'redirects.retention_404_days', 60 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$threshold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$table} ORDER BY hits DESC, last_seen DESC LIMIT 1 OFFSET %d", self::MAX_ROWS - 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE hits < %d AND status <> 'ignored'", $threshold ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$excess = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) - self::MAX_ROWS;
		while ( $excess > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY hits ASC, last_seen ASC LIMIT %d", min( 1000, $excess ) ) );
			if ( empty( $ids ) ) {
				break;
			}
			$excess -= $this->delete( array_map( 'intval', $ids ) );
		}
	}

	/**
	 * searchpilot_hourly: runs the checks in their own request (TimeBudget is request-relative).
	 */
	public function schedule_hourly() {
		if ( $this->active() && $this->module ) {
			\SearchPilot\Plugin::instance()->get( \SearchPilot\Core\Scheduler::class )->enqueue_async( 'searchpilot_404_hourly', array(), 20 );
		}
	}

	/**
	 * Hourly (async): spike detection and frequent 404s with internal referrers.
	 */
	public function hourly() {
		if ( ! $this->active() ) {
			return;
		}
		Lock::run(
			'not_found_hourly',
			300,
			function () {
				$this->detect_spike();
				$this->detect_frequent();
			}
		);
	}

	/**
	 * spike_404: last full hour above alerts.error_spike and 5× the 24-hour baseline.
	 */
	private function detect_spike() {
		global $wpdb;
		$counters  = $this->counters();
		$threshold = max( 5, (int) $this->settings->get( 'alerts.error_spike', 50 ) );
		$hour_ts   = time() - HOUR_IN_SECONDS;
		$last      = (int) ( $counters['h'][ gmdate( 'YmdH', $hour_ts ) ] ?? 0 );
		$sum       = 0;
		for ( $i = 1; $i <= 24; $i++ ) {
			$sum += (int) ( $counters['h'][ gmdate( 'YmdH', $hour_ts - $i * HOUR_IN_SECONDS ) ] ?? 0 );
		}
		$baseline = $sum / 24;
		if ( $last > $threshold && $last > 5 * max( 1.0, $baseline ) ) {
			$table = Tables::not_found();
			$since = gmdate( 'Y-m-d H:00:00', $hour_ts );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$top = $wpdb->get_results( $wpdb->prepare( "SELECT url, hits FROM {$table} WHERE last_seen >= %s ORDER BY hits DESC LIMIT 5", $since ), ARRAY_A );
			$this->issues->upsert(
				'spike_404',
				array(
					'object_type' => 'site',
					'title'       => __( 'Spike in “not found” errors', 'searchpilot-pro' ),
					'data'        => array(
						'count'    => $last,
						'baseline' => round( $baseline, 1 ),
						'hour'     => gmdate( 'c', strtotime( gmdate( 'Y-m-d H:00:00', $hour_ts ) . ' UTC' ) ),
						'top'      => array_map(
							static function ( $r ) {
								return array(
									'url'  => (string) $r['url'],
									'hits' => (int) $r['hits'],
								);
							},
							(array) $top
						),
					),
				)
			);
			$this->logger->warning( 'redirects', sprintf( '404 spike: %d in the last hour (baseline %.1f).', $last, $baseline ) );
			return;
		}
		// Resolve once three quiet hours have passed.
		$quiet = true;
		for ( $i = 0; $i < 3; $i++ ) {
			if ( (int) ( $counters['h'][ gmdate( 'YmdH', $hour_ts - $i * HOUR_IN_SECONDS ) ] ?? 0 ) > $threshold ) {
				$quiet = false;
			}
		}
		if ( $quiet ) {
			$this->issues->resolve( 'spike_404', 'site' );
		}
	}

	/**
	 * frequent_404: often-hit URLs reached from this site's own pages (broken internal links).
	 */
	private function detect_frequent() {
		global $wpdb;
		$start = current_time( 'mysql', true );
		$table = Tables::not_found();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'open' AND hits >= %d AND referrer IS NOT NULL AND referrer <> '' ORDER BY hits DESC LIMIT 200", self::FREQUENT_HITS ), ARRAY_A );
		$found = 0;
		foreach ( (array) $rows as $row ) {
			if ( $found >= 50 ) {
				break;
			}
			$referrer = (string) $row['referrer'];
			if ( ! Url::is_internal( $referrer ) || Url::normalize( $referrer ) === Url::normalize( (string) $row['url'] ) ) {
				continue;
			}
			++$found;
			$suggestions = $this->suggest( (string) $row['url'], 1 );
			$this->issues->upsert(
				'frequent_404',
				array(
					'object_type' => 'url',
					'object_id'   => (int) $row['id'],
					'url'         => (string) $row['url'],
					'title'       => Url::display( Url::to_path( (string) $row['url'] ) ),
					'data'        => array(
						'hits'       => (int) $row['hits'],
						'referrer'   => $referrer,
						'suggestion' => $suggestions ? $suggestions[0] : null,
					),
				)
			);
		}
		$this->issues->resolve_unseen( 'frequent_404', $start );
	}

	/**
	 * Registers 404 issue types.
	 *
	 * @param IssueRegistry $registry Registry.
	 */
	public function register_issue_types( IssueRegistry $registry ) {
		$registry->register(
			'spike_404',
			array(
				'label'    => __( 'Sudden rise in 404 errors', 'searchpilot-pro' ),
				'category' => 'technical',
				'severity' => 'critical',
				'what'     => static function ( $issue ) {
					/* translators: 1: 404 count, 2: normal hourly count. */
					return sprintf( __( '%1$d “not found” errors in one hour, against a normal %2$s per hour.', 'searchpilot-pro' ), (int) ( $issue['data']['count'] ?? 0 ), (string) ( $issue['data']['baseline'] ?? 0 ) );
				},
				'why'      => __( 'A sudden spike usually means a broken menu, a changed permalink structure, a deleted section or a bot probing the site. Real visitors and crawlers hit dead ends.', 'searchpilot-pro' ),
				'fix'      => __( 'Open the 404 Monitor, check the most-hit addresses and their referrers, and add redirects or fix the links. If one bot causes it, block it at your firewall.', 'searchpilot-pro' ),
				'action'   => 'review_404',
			)
		);
		$registry->register(
			'frequent_404',
			array(
				'label'     => __( 'Broken internal link leads to a 404', 'searchpilot-pro' ),
				'category'  => 'technical',
				'severity'  => 'medium',
				'what'      => static function ( $issue ) {
					/* translators: 1: hits, 2: referring page. */
					return sprintf( __( 'This address returned “not found” %1$d times; visitors came from %2$s on this site.', 'searchpilot-pro' ), (int) ( $issue['data']['hits'] ?? 0 ), Url::display( (string) ( $issue['data']['referrer'] ?? '' ) ) );
				},
				'why'       => __( 'A link on your own site points to a missing page, which frustrates readers and wastes crawl budget.', 'searchpilot-pro' ),
				'fix'       => static function ( $issue ) {
					$suggestion = $issue['data']['suggestion'] ?? null;
					if ( is_array( $suggestion ) && ! empty( $suggestion['url'] ) ) {
						/* translators: %s: suggested page. */
						return sprintf( __( 'Fix the link on the referring page, or redirect this address to the likely match “%s”.', 'searchpilot-pro' ), (string) $suggestion['title'] );
					}
					return __( 'Fix the link on the referring page, or create a redirect to the right page from the 404 Monitor.', 'searchpilot-pro' );
				},
				'fixer'     => array( $this, 'fix_frequent' ),
				'fix_label' => __( 'Redirect to suggested page', 'searchpilot-pro' ),
				'task'      => AutomationPolicy::TASK_TECHNICAL_CHANGES,
				'action'    => 'create_redirect',
			)
		);
	}

	/**
	 * Fixer: frequent_404 → 301 to the suggested page.
	 *
	 * @param array $issue Issue.
	 * @return true|\WP_Error
	 */
	public function fix_frequent( array $issue ) {
		$suggestion = $issue['data']['suggestion'] ?? null;
		if ( ! is_array( $suggestion ) || empty( $suggestion['url'] ) || ! $this->module ) {
			return new \WP_Error( 'searchpilot_no_suggestion', __( 'There is no confident match for this address. Choose a target in the 404 Monitor.', 'searchpilot-pro' ) );
		}
		// The bare path: a rule with ?fbclid=… would only match that one query.
		$bare   = RedirectMatcher::split( Url::to_path( (string) $issue['url'] ) )[0];
		$result = $this->module->create_rule(
			array(
				'source'      => $bare,
				'target'      => (string) $suggestion['url'],
				'status_code' => 301,
			),
			array(
				'origin'         => '404',
				'source'         => (string) ( $issue['source'] ?? 'user' ),
				'allow_external' => false,
			)
		);
		if ( is_wp_error( $result ) ) {
			return 'searchpilot_duplicate' === $result->get_error_code() ? true : $result;
		}
		$this->set_status( array( (int) $issue['object_id'] ), 'redirected', (int) $result['id'] );
		$this->mark_redirected( $bare, (int) $result['id'] );
		return true;
	}
}
