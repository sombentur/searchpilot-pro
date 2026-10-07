<?php
/**
 * Rank Math import source.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Rank Math post and term meta (rank_math_*) and its title,
 * knowledge-graph and breadcrumb options.
 */
class RankMathSource extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'rank_math';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Rank Math SEO';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' );
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_meta_keys() {
		return array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url', 'rank_math_robots', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_facebook_image', 'rank_math_facebook_image_id', 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_breadcrumb_title', 'rank_math_primary_category', 'rank_math_pillar_content' );
	}

	/**
	 * Term meta keys.
	 *
	 * @return string[]
	 */
	protected function term_meta_keys() {
		return array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url', 'rank_math_robots' );
	}

	/**
	 * Robots list decoder.
	 *
	 * @param mixed $raw Stored value.
	 * @return array{noindex:string,nofollow:string}
	 */
	private static function robots( $raw ) {
		// get_post_meta() already unserialized real arrays; a string that still looks serialized is user text.
		$list = is_array( $raw ) ? array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) : array();
		return array(
			'noindex'  => in_array( 'noindex', $list, true ) ? '1' : ( in_array( 'index', $list, true ) ? '0' : '' ),
			'nofollow' => in_array( 'nofollow', $list, true ) ? '1' : '',
		);
	}

	/**
	 * Post data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function read_post( $post_id ) {
		$robots   = self::robots( get_post_meta( (int) $post_id, 'rank_math_robots', true ) );
		$image_id = self::meta( $post_id, 'rank_math_facebook_image_id' );
		$own_tw   = 'off' === self::meta( $post_id, 'rank_math_twitter_use_facebook' );
		return array(
			'title'               => $this->template( self::meta( $post_id, 'rank_math_title' ) ),
			'description'         => $this->template( self::meta( $post_id, 'rank_math_description' ) ),
			'focus'               => self::first_keyword( self::meta( $post_id, 'rank_math_focus_keyword' ) ),
			'canonical'           => self::meta( $post_id, 'rank_math_canonical_url' ),
			'noindex'             => $robots['noindex'],
			'nofollow'            => $robots['nofollow'],
			'og_title'            => $this->template( self::meta( $post_id, 'rank_math_facebook_title' ) ),
			'og_description'      => $this->template( self::meta( $post_id, 'rank_math_facebook_description' ) ),
			'og_image'            => ctype_digit( $image_id ) && (int) $image_id ? $image_id : self::meta( $post_id, 'rank_math_facebook_image' ),
			'twitter_title'       => $own_tw ? $this->template( self::meta( $post_id, 'rank_math_twitter_title' ) ) : '',
			'twitter_description' => $own_tw ? $this->template( self::meta( $post_id, 'rank_math_twitter_description' ) ) : '',
			'breadcrumb_title'    => self::meta( $post_id, 'rank_math_breadcrumb_title' ),
			'primary_category'    => (int) self::meta( $post_id, 'rank_math_primary_category' ),
			'cornerstone'         => 'on' === self::meta( $post_id, 'rank_math_pillar_content' ),
		);
	}

	/**
	 * Term data.
	 *
	 * @param int $term_id Term id.
	 * @return array
	 */
	public function read_term( $term_id ) {
		$robots = self::robots( get_term_meta( (int) $term_id, 'rank_math_robots', true ) );
		return array(
			'title'       => $this->template( self::term_meta( $term_id, 'rank_math_title' ), 'term' ),
			'description' => $this->template( self::term_meta( $term_id, 'rank_math_description' ), 'term' ),
			'focus'       => self::first_keyword( self::term_meta( $term_id, 'rank_math_focus_keyword' ) ),
			'canonical'   => self::term_meta( $term_id, 'rank_math_canonical_url' ),
			'noindex'     => $robots['noindex'],
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function read_settings() {
		$titles  = get_option( 'rank-math-options-titles', array() );
		$general = get_option( 'rank-math-options-general', array() );
		$titles  = is_array( $titles ) ? $titles : array();
		$general = is_array( $general ) ? $general : array();
		if ( ! $titles && ! $general ) {
			return array();
		}
		$type    = (string) ( $titles['knowledgegraph_type'] ?? '' );
		$logo_id = (int) ( $titles['knowledgegraph_logo_id'] ?? 0 );
		$twitter = self::twitter( (string) ( $titles['twitter_author_names'] ?? '' ) );
		$same_as = self::urls( array( (string) ( $titles['social_url_facebook'] ?? '' ), (string) ( $titles['social_additional_profiles'] ?? '' ) ) );
		if ( '' !== $twitter ) {
			$same_as[] = 'https://x.com/' . $twitter;
		}
		$out = array(
			'meta.separator'         => html_entity_decode( trim( (string) ( $titles['title_separator'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'meta.title_post'        => $this->template( (string) ( $titles['pt_post_title'] ?? '' ) ),
			'meta.title_page'        => $this->template( (string) ( $titles['pt_page_title'] ?? '' ) ),
			'meta.title_term'        => $this->template( (string) ( $titles['tax_category_title'] ?? '' ), 'term' ),
			'meta.title_author'      => $this->template( (string) ( $titles['author_archive_title'] ?? '' ), 'author' ),
			'meta.title_archive'     => $this->template( (string) ( $titles['date_archive_title'] ?? '' ), 'archive' ),
			'meta.title_search'      => $this->template( (string) ( $titles['search_title'] ?? '' ), 'search' ),
			'meta.title_404'         => $this->template( (string) ( $titles['404_title'] ?? '' ), '404' ),
			'meta.home_title'        => $this->template( (string) ( $titles['homepage_title'] ?? '' ), 'home' ),
			'meta.home_description'  => $this->template( (string) ( $titles['homepage_description'] ?? '' ), 'home' ),
			'meta.twitter_site'      => $twitter,
			'meta.facebook_app_id'   => trim( (string) ( $titles['facebook_app_id'] ?? '' ) ),
			'meta.default_image'     => (int) ( $titles['open_graph_image_id'] ?? 0 ),
			'schema.entity'          => 'person' === $type ? 'person' : ( 'company' === $type ? 'organization' : '' ),
			'schema.org_name'        => trim( (string) ( $titles['knowledgegraph_name'] ?? '' ) ),
			'schema.org_logo'        => $logo_id ? $logo_id : self::attachment_id( (string) ( $titles['knowledgegraph_logo'] ?? '' ) ),
			'schema.same_as'         => $same_as,
			'breadcrumbs.separator'  => html_entity_decode( trim( (string) ( $general['breadcrumbs_separator'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'breadcrumbs.home_label' => trim( (string) ( $general['breadcrumbs_home_label'] ?? '' ) ),
		);
		return array_filter(
			$out,
			static function ( $v ) {
				return '' !== $v && 0 !== $v && array() !== $v;
			}
		);
	}
}
