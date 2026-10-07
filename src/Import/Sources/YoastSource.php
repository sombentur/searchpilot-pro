<?php
/**
 * Yoast SEO import source.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Yoast SEO post meta (_yoast_wpseo_*), term data (option
 * wpseo_taxonomy_meta) and settings (wpseo_titles, wpseo_social).
 */
class YoastSource extends Source {

	const SEPARATORS = array(
		'sc-dash'   => '-',
		'sc-ndash'  => '–',
		'sc-mdash'  => '—',
		'sc-colon'  => ':',
		'sc-middot' => '·',
		'sc-bull'   => '•',
		'sc-star'   => '*',
		'sc-smstar' => '⋆',
		'sc-pipe'   => '|',
		'sc-tilde'  => '~',
		'sc-laquo'  => '«',
		'sc-raquo'  => '»',
		'sc-lt'     => '<',
		'sc-gt'     => '>',
	);

	/**
	 * Flattened term data (term id => fields), memoized.
	 *
	 * @var array|null
	 */
	private $terms = null;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'yoast';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Yoast SEO';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_meta_keys() {
		return array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_meta-robots-nofollow', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', '_yoast_wpseo_bctitle', '_yoast_wpseo_primary_category', '_yoast_wpseo_is_cornerstone' );
	}

	/**
	 * Post data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function read_post( $post_id ) {
		$noindex  = self::meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
		$image_id = self::meta( $post_id, '_yoast_wpseo_opengraph-image-id' );
		return array(
			'title'               => $this->template( self::meta( $post_id, '_yoast_wpseo_title' ) ),
			'description'         => $this->template( self::meta( $post_id, '_yoast_wpseo_metadesc' ) ),
			'focus'               => self::meta( $post_id, '_yoast_wpseo_focuskw' ),
			'canonical'           => self::meta( $post_id, '_yoast_wpseo_canonical' ),
			'noindex'             => '1' === $noindex ? '1' : ( '2' === $noindex ? '0' : '' ),
			'nofollow'            => '1' === self::meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' ) ? '1' : '',
			'og_title'            => $this->template( self::meta( $post_id, '_yoast_wpseo_opengraph-title' ) ),
			'og_description'      => $this->template( self::meta( $post_id, '_yoast_wpseo_opengraph-description' ) ),
			'og_image'            => ctype_digit( $image_id ) && (int) $image_id ? $image_id : self::meta( $post_id, '_yoast_wpseo_opengraph-image' ),
			'twitter_title'       => $this->template( self::meta( $post_id, '_yoast_wpseo_twitter-title' ) ),
			'twitter_description' => $this->template( self::meta( $post_id, '_yoast_wpseo_twitter-description' ) ),
			'breadcrumb_title'    => self::meta( $post_id, '_yoast_wpseo_bctitle' ),
			'primary_category'    => (int) self::meta( $post_id, '_yoast_wpseo_primary_category' ),
			'cornerstone'         => '1' === self::meta( $post_id, '_yoast_wpseo_is_cornerstone' ),
		);
	}

	/**
	 * Term data from wpseo_taxonomy_meta (term id => fields).
	 *
	 * @return array<int,array>
	 */
	private function terms() {
		if ( null !== $this->terms ) {
			return $this->terms;
		}
		$this->terms = array();
		$all         = get_option( 'wpseo_taxonomy_meta', array() );
		foreach ( is_array( $all ) ? $all : array() as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $data ) {
				if ( is_array( $data ) && (int) $term_id ) {
					$this->terms[ (int) $term_id ] = $data;
				}
			}
		}
		ksort( $this->terms );
		return $this->terms;
	}

	/**
	 * Terms with data.
	 *
	 * @return int
	 */
	public function count_terms() {
		$n = 0;
		foreach ( $this->terms() as $data ) {
			foreach ( array( 'wpseo_title', 'wpseo_desc', 'wpseo_focuskw', 'wpseo_canonical', 'wpseo_noindex' ) as $key ) {
				if ( '' !== trim( (string) ( $data[ $key ] ?? '' ) ) && 'default' !== ( $data[ $key ] ?? '' ) ) {
					++$n;
					break;
				}
			}
		}
		return $n;
	}

	/**
	 * Next term ids.
	 *
	 * @param int $after After.
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public function term_ids_after( $after, $limit ) {
		$ids = array_filter(
			array_keys( $this->terms() ),
			static function ( $id ) use ( $after ) {
				return $id > (int) $after;
			}
		);
		return array_slice( array_values( $ids ), 0, (int) $limit );
	}

	/**
	 * Term data.
	 *
	 * @param int $term_id Term id.
	 * @return array
	 */
	public function read_term( $term_id ) {
		$data    = $this->terms()[ (int) $term_id ] ?? array();
		$noindex = (string) ( $data['wpseo_noindex'] ?? '' );
		return array(
			'title'       => $this->template( (string) ( $data['wpseo_title'] ?? '' ), 'term' ),
			'description' => $this->template( (string) ( $data['wpseo_desc'] ?? '' ), 'term' ),
			'focus'       => trim( (string) ( $data['wpseo_focuskw'] ?? '' ) ),
			'canonical'   => trim( (string) ( $data['wpseo_canonical'] ?? '' ) ),
			'noindex'     => 'noindex' === $noindex ? '1' : ( 'index' === $noindex ? '0' : '' ),
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function read_settings() {
		$titles = get_option( 'wpseo_titles', array() );
		$social = get_option( 'wpseo_social', array() );
		$titles = is_array( $titles ) ? $titles : array();
		$social = is_array( $social ) ? $social : array();
		if ( ! $titles && ! $social ) {
			return array();
		}
		$out = array(
			'meta.separator'        => self::SEPARATORS[ (string) ( $titles['separator'] ?? '' ) ] ?? '',
			'meta.title_post'       => $this->template( (string) ( $titles['title-post'] ?? '' ) ),
			'meta.title_page'       => $this->template( (string) ( $titles['title-page'] ?? '' ) ),
			'meta.title_term'       => $this->template( (string) ( $titles['title-tax-category'] ?? '' ), 'term' ),
			'meta.title_author'     => $this->template( (string) ( $titles['title-author-wpseo'] ?? '' ), 'author' ),
			'meta.title_archive'    => $this->template( (string) ( $titles['title-archive-wpseo'] ?? '' ), 'archive' ),
			'meta.title_search'     => $this->template( (string) ( $titles['title-search-wpseo'] ?? '' ), 'search' ),
			'meta.title_404'        => $this->template( (string) ( $titles['title-404-wpseo'] ?? '' ), '404' ),
			'meta.home_title'       => $this->template( (string) ( $titles['title-home-wpseo'] ?? '' ), 'home' ),
			'meta.home_description' => $this->template( (string) ( $titles['metadesc-home-wpseo'] ?? '' ), 'home' ),
			'meta.twitter_site'     => self::twitter( (string) ( $social['twitter_site'] ?? '' ) ),
			'meta.default_image'    => (int) ( $social['og_default_image_id'] ?? 0 ),
			'schema.entity'         => 'person' === ( $titles['company_or_person'] ?? '' ) ? 'person' : ( isset( $titles['company_or_person'] ) ? 'organization' : '' ),
			'schema.org_name'       => trim( (string) ( $titles['company_name'] ?? '' ) ),
			'schema.org_logo'       => (int) ( $titles['company_logo_id'] ?? 0 ) ? (int) $titles['company_logo_id'] : self::attachment_id( (string) ( $titles['company_logo'] ?? '' ) ),
			'schema.person_user'    => (int) ( $titles['company_or_person_user_id'] ?? 0 ),
			'schema.same_as'        => self::urls( array_merge( array( (string) ( $social['facebook_site'] ?? '' ) ), (array) ( $social['other_social_urls'] ?? array() ) ) ),
			'breadcrumbs.separator'  => html_entity_decode( trim( (string) ( $titles['breadcrumbs-sep'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ),
			'breadcrumbs.home_label' => trim( (string) ( $titles['breadcrumbs-home'] ?? '' ) ),
			'breadcrumbs.prefix'     => trim( (string) ( $titles['breadcrumbs-prefix'] ?? '' ) ),
		);
		$twitter = (string) ( $social['twitter_site'] ?? '' );
		if ( '' !== $twitter && '' !== self::twitter( $twitter ) ) {
			$out['schema.same_as'][] = 'https://x.com/' . self::twitter( $twitter );
		}
		return array_filter(
			$out,
			static function ( $v ) {
				return '' !== $v && 0 !== $v && array() !== $v;
			}
		);
	}
}
