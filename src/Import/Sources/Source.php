<?php
/**
 * Base class of an import source (another SEO plugin's stored data).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

use SearchPilot\Import\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * A source reads another plugin's post, term and settings data and returns
 * it in SearchPilot's format (field => value, templates converted). Sources
 * only ever read: the other plugin's data is never modified. Data is found
 * even when that plugin is deactivated.
 *
 * Post field values: title, description, focus, canonical, og_title,
 * og_description, twitter_title, twitter_description, breadcrumb_title
 * (strings); noindex ('1' noindex, '0' explicit index, '' default);
 * nofollow ('1' or ''); og_image (attachment id or URL); primary_category
 * (int); cornerstone (bool).
 */
abstract class Source {

	/**
	 * Source id (yoast|rank_math|aioseo|seopress|tsf).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Plugin name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether the plugin is currently active.
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Post meta keys holding importable data.
	 *
	 * @return string[]
	 */
	abstract protected function post_meta_keys();

	/**
	 * Post data in SearchPilot's format.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>
	 */
	abstract public function read_post( $post_id );

	/**
	 * Term meta keys holding importable data ([] when terms live elsewhere).
	 *
	 * @return string[]
	 */
	protected function term_meta_keys() {
		return array();
	}

	/**
	 * Term data in SearchPilot's format (title, description, focus, canonical, noindex).
	 *
	 * @param int $term_id Term id.
	 * @return array<string,mixed>
	 */
	public function read_term( $term_id ) {
		return array();
	}

	/**
	 * Settings as SearchPilot dot keys => values.
	 *
	 * @return array<string,mixed>
	 */
	public function read_settings() {
		return array();
	}

	/**
	 * Posts with importable data.
	 *
	 * @return int
	 */
	public function count_posts() {
		global $wpdb;
		$keys = $this->post_meta_keys();
		if ( ! $keys ) {
			return 0;
		}
		$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ({$holders}) AND pm.meta_value <> '' AND p.post_type NOT IN ('revision','nav_menu_item')", $keys ) );
	}

	/**
	 * Next post ids with data after an id.
	 *
	 * @param int $after Last processed id.
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	public function post_ids_after( $after, $limit ) {
		global $wpdb;
		$keys = $this->post_meta_keys();
		if ( ! $keys ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params  = array_merge( $keys, array( (int) $after, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ({$holders}) AND pm.meta_value <> '' AND p.post_type NOT IN ('revision','nav_menu_item') AND pm.post_id > %d ORDER BY pm.post_id ASC LIMIT %d", $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Terms with importable data.
	 *
	 * @return int
	 */
	public function count_terms() {
		global $wpdb;
		$keys = $this->term_meta_keys();
		if ( ! $keys ) {
			return 0;
		}
		$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT term_id) FROM {$wpdb->termmeta} WHERE meta_key IN ({$holders}) AND meta_value <> ''", $keys ) );
	}

	/**
	 * Next term ids with data after an id.
	 *
	 * @param int $after Last processed id.
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	public function term_ids_after( $after, $limit ) {
		global $wpdb;
		$keys = $this->term_meta_keys();
		if ( ! $keys ) {
			return array();
		}
		$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$params  = array_merge( $keys, array( (int) $after, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ({$holders}) AND meta_value <> '' AND term_id > %d ORDER BY term_id ASC LIMIT %d", $params ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether any data is stored (even with the plugin inactive).
	 *
	 * @return bool
	 */
	public function has_settings() {
		return (bool) $this->read_settings();
	}

	/**
	 * Trimmed scalar post meta.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Key.
	 * @return string
	 */
	protected static function meta( $post_id, $key ) {
		$value = get_post_meta( (int) $post_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Trimmed scalar term meta.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Key.
	 * @return string
	 */
	protected static function term_meta( $term_id, $key ) {
		$value = get_term_meta( (int) $term_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Converts a template to SearchPilot variables.
	 *
	 * @param string $value   Template.
	 * @param string $context post|term|author|archive|search|404|home.
	 * @return string
	 */
	protected function template( $value, $context = 'post' ) {
		return Importer::convert_template( (string) $value, $this->id(), $context );
	}

	/**
	 * First keyword of a comma-separated list.
	 *
	 * @param string $value List.
	 * @return string
	 */
	protected static function first_keyword( $value ) {
		$parts = array_map( 'trim', explode( ',', (string) $value ) );
		return (string) ( $parts[0] ?? '' );
	}

	/**
	 * Attachment id of an uploaded image URL (0 when not in the media library).
	 *
	 * @param string $url URL.
	 * @return int
	 */
	protected static function attachment_id( $url ) {
		$url = trim( (string) $url );
		return '' !== $url && function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		$wpdb->suppress_errors( $suppress );
		return (string) $found === (string) $table;
	}

	/**
	 * Keeps non-empty URLs of a list.
	 *
	 * @param array $urls URLs.
	 * @return string[]
	 */
	protected static function urls( array $urls ) {
		$out = array();
		foreach ( $urls as $url ) {
			foreach ( preg_split( '/[\r\n]+/', (string) $url ) as $line ) {
				$line = esc_url_raw( trim( $line ), array( 'http', 'https' ) );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * X/Twitter handle from a username or URL.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected static function twitter( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#(?:twitter|x)\.com/@?([A-Za-z0-9_]{1,15})#i', $value, $m ) ) {
			return $m[1];
		}
		$value = ltrim( $value, '@' );
		return preg_match( '/^[A-Za-z0-9_]{1,15}$/', $value ) ? $value : '';
	}
}
