<?php
/**
 * All in One SEO import source.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads All in One SEO data from its own tables ({prefix}aioseo_posts and,
 * in AIOSEO Pro, {prefix}aioseo_terms) and its JSON options.
 */
class AioseoSource extends Source {

	/**
	 * Memoized table existence.
	 *
	 * @var array<string,bool>
	 */
	private $tables = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'aioseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'All in One SEO';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
	}

	/**
	 * AIOSEO keeps post data in a table, not in post meta.
	 *
	 * @return string[]
	 */
	protected function post_meta_keys() {
		return array();
	}

	/**
	 * Table name (or '' when missing).
	 *
	 * @param string $short posts|terms.
	 * @return string
	 */
	private function table( $short ) {
		global $wpdb;
		$name = $wpdb->prefix . 'aioseo_' . $short;
		if ( ! isset( $this->tables[ $name ] ) ) {
			$this->tables[ $name ] = self::table_exists( $name );
		}
		return $this->tables[ $name ] ? $name : '';
	}

	/**
	 * Posts with data.
	 *
	 * @return int
	 */
	public function count_posts() {
		global $wpdb;
		$table = $this->table( 'posts' );
		if ( '' === $table ) {
			return 0;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE COALESCE(title, '') <> '' OR COALESCE(description, '') <> '' OR COALESCE(canonical_url, '') <> '' OR COALESCE(og_title, '') <> '' OR robots_default = 0" );
		if ( null === $count ) {
			// Older table layouts: count every row.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->suppress_errors( $suppress );
		return (int) $count;
	}

	/**
	 * Next post ids.
	 *
	 * @param int $after After.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function post_ids_after( $after, $limit ) {
		global $wpdb;
		$table = $this->table( 'posts' );
		if ( '' === $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id > %d ORDER BY post_id ASC LIMIT %d", (int) $after, (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Row of a post or term.
	 *
	 * @param string $short  posts|terms.
	 * @param string $column post_id|term_id.
	 * @param int    $id     Id.
	 * @return array
	 */
	private function row( $short, $column, $id ) {
		global $wpdb;
		$table = $this->table( $short );
		if ( '' === $table ) {
			return array();
		}
		$column = 'term_id' === $column ? 'term_id' : 'post_id';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/**
	 * Robots from a row.
	 *
	 * @param array $row Row.
	 * @return array{noindex:string,nofollow:string}
	 */
	private static function robots( array $row ) {
		if ( ! isset( $row['robots_default'] ) || (int) $row['robots_default'] ) {
			return array(
				'noindex'  => '',
				'nofollow' => '',
			);
		}
		return array(
			'noindex'  => ! empty( $row['robots_noindex'] ) ? '1' : '0',
			'nofollow' => ! empty( $row['robots_nofollow'] ) ? '1' : '',
		);
	}

	/**
	 * Post data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function read_post( $post_id ) {
		$row = $this->row( 'posts', 'post_id', $post_id );
		if ( ! $row ) {
			return array();
		}
		$robots  = self::robots( $row );
		$phrases = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
		$primary = json_decode( (string) ( $row['primary_term'] ?? '' ), true );
		$image   = trim( (string) ( $row['og_image_custom_url'] ?? '' ) );
		if ( '' === $image && 'custom_image' === ( $row['og_image_type'] ?? '' ) ) {
			$image = trim( (string) ( $row['og_image_url'] ?? '' ) );
		}
		return array(
			'title'               => $this->template( (string) ( $row['title'] ?? '' ) ),
			'description'         => $this->template( (string) ( $row['description'] ?? '' ) ),
			'focus'               => is_array( $phrases ) ? trim( (string) ( $phrases['focus']['keyphrase'] ?? '' ) ) : '',
			'canonical'           => trim( (string) ( $row['canonical_url'] ?? '' ) ),
			'noindex'             => $robots['noindex'],
			'nofollow'            => $robots['nofollow'],
			'og_title'            => $this->template( (string) ( $row['og_title'] ?? '' ) ),
			'og_description'      => $this->template( (string) ( $row['og_description'] ?? '' ) ),
			'og_image'            => $image,
			'twitter_title'       => empty( $row['twitter_use_og'] ) ? $this->template( (string) ( $row['twitter_title'] ?? '' ) ) : '',
			'twitter_description' => empty( $row['twitter_use_og'] ) ? $this->template( (string) ( $row['twitter_description'] ?? '' ) ) : '',
			'primary_category'    => is_array( $primary ) ? (int) ( $primary['category'] ?? 0 ) : 0,
			'cornerstone'         => ! empty( $row['pillar_content'] ),
		);
	}

	/**
	 * Terms with data (AIOSEO Pro).
	 *
	 * @return int
	 */
	public function count_terms() {
		global $wpdb;
		$table = $this->table( 'terms' );
		if ( '' === $table ) {
			return 0;
		}
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE COALESCE(title, '') <> '' OR COALESCE(description, '') <> '' OR COALESCE(canonical_url, '') <> '' OR robots_default = 0" );
		if ( null === $count ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->suppress_errors( $suppress );
		return (int) $count;
	}

	/**
	 * Next term ids.
	 *
	 * @param int $after After.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function term_ids_after( $after, $limit ) {
		global $wpdb;
		$table = $this->table( 'terms' );
		if ( '' === $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$table} WHERE term_id > %d ORDER BY term_id ASC LIMIT %d", (int) $after, (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Term data.
	 *
	 * @param int $term_id Term id.
	 * @return array
	 */
	public function read_term( $term_id ) {
		$row = $this->row( 'terms', 'term_id', $term_id );
		if ( ! $row ) {
			return array();
		}
		$robots = self::robots( $row );
		return array(
			'title'       => $this->template( (string) ( $row['title'] ?? '' ), 'term' ),
			'description' => $this->template( (string) ( $row['description'] ?? '' ), 'term' ),
			'canonical'   => trim( (string) ( $row['canonical_url'] ?? '' ) ),
			'noindex'     => $robots['noindex'],
		);
	}

	/**
	 * Settings from aioseo_options / aioseo_options_dynamic.
	 *
	 * @return array
	 */
	public function read_settings() {
		$opts    = json_decode( (string) get_option( 'aioseo_options', '' ), true );
		$dynamic = json_decode( (string) get_option( 'aioseo_options_dynamic', '' ), true );
		if ( ! is_array( $opts ) ) {
			return array();
		}
		$dynamic = is_array( $dynamic ) ? $dynamic : array();
		$global  = (array) ( $opts['searchAppearance']['global'] ?? array() );
		$schema  = (array) ( $global['schema'] ?? array() );
		$archive = (array) ( $opts['searchAppearance']['archives'] ?? array() );
		$types   = (array) ( $dynamic['searchAppearance']['postTypes'] ?? array() );
		$taxes   = (array) ( $dynamic['searchAppearance']['taxonomies'] ?? array() );
		$urls    = (array) ( $opts['social']['profiles']['urls'] ?? array() );
		$extra   = (string) ( $opts['social']['profiles']['additionalUrls'] ?? '' );
		$twitter = self::twitter( (string) ( $urls['twitterUrl'] ?? '' ) );
		$repr    = (string) ( $schema['siteRepresents'] ?? '' );
		$logo    = (string) ( $schema['organizationLogo'] ?? '' );
		$out     = array(
			'meta.separator'        => html_entity_decode( trim( (string) ( $global['separator'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'meta.home_title'       => $this->template( (string) ( $global['siteTitle'] ?? '' ), 'home' ),
			'meta.home_description' => $this->template( (string) ( $global['metaDescription'] ?? '' ), 'home' ),
			'meta.title_post'       => $this->template( (string) ( $types['post']['title'] ?? '' ) ),
			'meta.title_page'       => $this->template( (string) ( $types['page']['title'] ?? '' ) ),
			'meta.title_term'       => $this->template( (string) ( $taxes['category']['title'] ?? '' ), 'term' ),
			'meta.title_author'     => $this->template( (string) ( $archive['author']['title'] ?? '' ), 'author' ),
			'meta.title_archive'    => $this->template( (string) ( $archive['date']['title'] ?? '' ), 'archive' ),
			'meta.title_search'     => $this->template( (string) ( $archive['search']['title'] ?? '' ), 'search' ),
			'meta.twitter_site'     => $twitter,
			'schema.entity'         => 'person' === $repr ? 'person' : ( 'organization' === $repr ? 'organization' : '' ),
			'schema.org_name'       => trim( (string) ( $schema['organizationName'] ?? '' ) ),
			'schema.org_logo'       => self::attachment_id( $logo ),
			'schema.person_user'    => is_numeric( $schema['person'] ?? '' ) ? (int) $schema['person'] : 0,
			'schema.same_as'        => self::urls( array_merge( array_values( array_filter( $urls, 'is_string' ) ), array( $extra ) ) ),
		);
		return array_filter(
			$out,
			static function ( $v ) {
				return '' !== $v && 0 !== $v && array() !== $v;
			}
		);
	}
}
