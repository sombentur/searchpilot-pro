<?php
/**
 * Table name registry.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Returns prefixed table names for the current site. Always call these at
 * query time (never cache them in properties) so switch_to_blog() works.
 */
final class Tables {

	/**
	 * Short names of every table.
	 */
	const ALL = array(
		'urls',
		'queue',
		'issues',
		'logs',
		'activity',
		'redirects',
		'not_found',
		'links',
		'link_suggestions',
		'terms',
		'term_stats',
		'content',
		'perf_daily',
		'perf_rows',
		'reports',
	);

	/**
	 * Prefixed name for a short name.
	 *
	 * @param string $short Short name.
	 * @return string
	 */
	public static function name( $short ) {
		global $wpdb;
		return $wpdb->prefix . 'searchpilot_' . $short;
	}

	/**
	 * URL registry.
	 *
	 * @return string
	 */
	public static function urls() {
		return self::name( 'urls' );
	}

	/**
	 * Submission queue and history.
	 *
	 * @return string
	 */
	public static function queue() {
		return self::name( 'queue' );
	}

	/**
	 * Detected issues and opportunities.
	 *
	 * @return string
	 */
	public static function issues() {
		return self::name( 'issues' );
	}

	/**
	 * System logs.
	 *
	 * @return string
	 */
	public static function logs() {
		return self::name( 'logs' );
	}

	/**
	 * Activity / change log with rollback data.
	 *
	 * @return string
	 */
	public static function activity() {
		return self::name( 'activity' );
	}

	/**
	 * Redirect rules.
	 *
	 * @return string
	 */
	public static function redirects() {
		return self::name( 'redirects' );
	}

	/**
	 * 404 monitor.
	 *
	 * @return string
	 */
	public static function not_found() {
		return self::name( 'not_found' );
	}

	/**
	 * Link edges found in content.
	 *
	 * @return string
	 */
	public static function links() {
		return self::name( 'links' );
	}

	/**
	 * Internal link suggestions.
	 *
	 * @return string
	 */
	public static function link_suggestions() {
		return self::name( 'link_suggestions' );
	}

	/**
	 * Inverted term index (content graph).
	 *
	 * @return string
	 */
	public static function terms() {
		return self::name( 'terms' );
	}

	/**
	 * Term document frequencies.
	 *
	 * @return string
	 */
	public static function term_stats() {
		return self::name( 'term_stats' );
	}

	/**
	 * Per-post content fingerprints and scores.
	 *
	 * @return string
	 */
	public static function content() {
		return self::name( 'content' );
	}

	/**
	 * Site-level daily search performance.
	 *
	 * @return string
	 */
	public static function perf_daily() {
		return self::name( 'perf_daily' );
	}

	/**
	 * Query/page performance snapshots per range.
	 *
	 * @return string
	 */
	public static function perf_rows() {
		return self::name( 'perf_rows' );
	}

	/**
	 * Stored reports.
	 *
	 * @return string
	 */
	public static function reports() {
		return self::name( 'reports' );
	}
}
