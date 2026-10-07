<?php
/**
 * SEOPress import source.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads SEOPress post and term meta (_seopress_*) and its titles, social and
 * breadcrumb options.
 */
class SeopressSource extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'seopress';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'SEOPress';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_meta_keys() {
		return array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw', '_seopress_robots_canonical', '_seopress_robots_index', '_seopress_robots_follow', '_seopress_social_fb_title', '_seopress_social_fb_desc', '_seopress_social_fb_img', '_seopress_social_fb_img_attachment_id', '_seopress_social_twitter_title', '_seopress_social_twitter_desc', '_seopress_robots_breadcrumbs', '_seopress_robots_primary_cat' );
	}

	/**
	 * Term meta keys.
	 *
	 * @return string[]
	 */
	protected function term_meta_keys() {
		return array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_robots_index', '_seopress_robots_canonical' );
	}

	/**
	 * Post data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function read_post( $post_id ) {
		$image_id = self::meta( $post_id, '_seopress_social_fb_img_attachment_id' );
		return array(
			'title'               => $this->template( self::meta( $post_id, '_seopress_titles_title' ) ),
			'description'         => $this->template( self::meta( $post_id, '_seopress_titles_desc' ) ),
			'focus'               => self::first_keyword( self::meta( $post_id, '_seopress_analysis_target_kw' ) ),
			'canonical'           => self::meta( $post_id, '_seopress_robots_canonical' ),
			'noindex'             => 'yes' === self::meta( $post_id, '_seopress_robots_index' ) ? '1' : '',
			'nofollow'            => 'yes' === self::meta( $post_id, '_seopress_robots_follow' ) ? '1' : '',
			'og_title'            => $this->template( self::meta( $post_id, '_seopress_social_fb_title' ) ),
			'og_description'      => $this->template( self::meta( $post_id, '_seopress_social_fb_desc' ) ),
			'og_image'            => ctype_digit( $image_id ) && (int) $image_id ? $image_id : self::meta( $post_id, '_seopress_social_fb_img' ),
			'twitter_title'       => $this->template( self::meta( $post_id, '_seopress_social_twitter_title' ) ),
			'twitter_description' => $this->template( self::meta( $post_id, '_seopress_social_twitter_desc' ) ),
			'breadcrumb_title'    => self::meta( $post_id, '_seopress_robots_breadcrumbs' ),
			'primary_category'    => (int) self::meta( $post_id, '_seopress_robots_primary_cat' ),
		);
	}

	/**
	 * Term data.
	 *
	 * @param int $term_id Term id.
	 * @return array
	 */
	public function read_term( $term_id ) {
		return array(
			'title'       => $this->template( self::term_meta( $term_id, '_seopress_titles_title' ), 'term' ),
			'description' => $this->template( self::term_meta( $term_id, '_seopress_titles_desc' ), 'term' ),
			'canonical'   => self::term_meta( $term_id, '_seopress_robots_canonical' ),
			'noindex'     => 'yes' === self::term_meta( $term_id, '_seopress_robots_index' ) ? '1' : '',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function read_settings() {
		$titles = get_option( 'seopress_titles_option_name', array() );
		$social = get_option( 'seopress_social_option_name', array() );
		$pro    = get_option( 'seopress_pro_option_name', array() );
		$titles = is_array( $titles ) ? $titles : array();
		$social = is_array( $social ) ? $social : array();
		$pro    = is_array( $pro ) ? $pro : array();
		if ( ! $titles && ! $social ) {
			return array();
		}
		$single  = (array) ( $titles['seopress_titles_single_titles'] ?? array() );
		$tax     = (array) ( $titles['seopress_titles_tax_titles'] ?? array() );
		$type    = strtolower( (string) ( $social['seopress_social_knowledge_type'] ?? '' ) );
		$twitter = self::twitter( (string) ( $social['seopress_social_accounts_twitter'] ?? '' ) );
		$same_as = self::urls(
			array(
				(string) ( $social['seopress_social_accounts_facebook'] ?? '' ),
				(string) ( $social['seopress_social_accounts_pinterest'] ?? '' ),
				(string) ( $social['seopress_social_accounts_instagram'] ?? '' ),
				(string) ( $social['seopress_social_accounts_youtube'] ?? '' ),
				(string) ( $social['seopress_social_accounts_linkedin'] ?? '' ),
				(string) ( $social['seopress_social_accounts_extra'] ?? '' ),
			)
		);
		if ( '' !== $twitter ) {
			$same_as[] = 'https://x.com/' . $twitter;
		}
		$out = array(
			'meta.separator'         => html_entity_decode( trim( (string) ( $titles['seopress_titles_sep'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'meta.home_title'        => $this->template( (string) ( $titles['seopress_titles_home_site_title'] ?? '' ), 'home' ),
			'meta.home_description'  => $this->template( (string) ( $titles['seopress_titles_home_site_desc'] ?? '' ), 'home' ),
			'meta.title_post'        => $this->template( (string) ( $single['post']['title'] ?? '' ) ),
			'meta.title_page'        => $this->template( (string) ( $single['page']['title'] ?? '' ) ),
			'meta.title_term'        => $this->template( (string) ( $tax['category']['title'] ?? '' ), 'term' ),
			'meta.title_author'      => $this->template( (string) ( $titles['seopress_titles_archives_author_title'] ?? '' ), 'author' ),
			'meta.title_archive'     => $this->template( (string) ( $titles['seopress_titles_archives_date_title'] ?? '' ), 'archive' ),
			'meta.title_search'      => $this->template( (string) ( $titles['seopress_titles_archives_search_title'] ?? '' ), 'search' ),
			'meta.title_404'         => $this->template( (string) ( $titles['seopress_titles_archives_404_title'] ?? '' ), '404' ),
			'meta.twitter_site'      => $twitter,
			'meta.default_image'     => self::attachment_id( (string) ( $social['seopress_social_facebook_img'] ?? '' ) ),
			'schema.entity'          => 'person' === $type ? 'person' : ( 'organization' === $type ? 'organization' : '' ),
			'schema.org_name'        => trim( (string) ( $social['seopress_social_knowledge_name'] ?? '' ) ),
			'schema.org_logo'        => self::attachment_id( (string) ( $social['seopress_social_knowledge_img'] ?? '' ) ),
			'schema.same_as'         => $same_as,
			'breadcrumbs.separator'  => html_entity_decode( trim( (string) ( $pro['seopress_breadcrumbs_separator'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'breadcrumbs.home_label' => trim( (string) ( $pro['seopress_breadcrumbs_i18n_home'] ?? '' ) ),
		);
		return array_filter(
			$out,
			static function ( $v ) {
				return '' !== $v && 0 !== $v && array() !== $v;
			}
		);
	}
}
