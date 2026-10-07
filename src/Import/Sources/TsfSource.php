<?php
/**
 * The SEO Framework import source.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Import\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Reads The SEO Framework post meta (_genesis_*, _open_graph_*, _twitter_*),
 * term settings (autodescription-term-settings) and site settings.
 *
 * TSF titles are plain text and TSF appends the site name itself, so
 * imported titles get " %sep% %sitename%" unless TSF was told not to.
 */
class TsfSource extends Source {

	const SEPARATORS = array(
		'hyphen' => '-',
		'dash'   => '–',
		'ndash'  => '–',
		'mdash'  => '—',
		'pipe'   => '|',
		'bull'   => '•',
		'middot' => '·',
		'lsaquo' => '‹',
		'rsaquo' => '›',
		'laquo'  => '«',
		'raquo'  => '»',
		'tilde'  => '~',
		'colon'  => ':',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'tsf';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'The SEO Framework';
	}

	/**
	 * Active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_meta_keys() {
		return array( '_genesis_title', '_genesis_description', '_genesis_canonical_uri', '_genesis_noindex', '_genesis_nofollow', '_open_graph_title', '_open_graph_description', '_social_image_url', '_social_image_id', '_twitter_title', '_twitter_description', '_primary_term_category' );
	}

	/**
	 * Term meta keys.
	 *
	 * @return string[]
	 */
	protected function term_meta_keys() {
		return array( 'autodescription-term-settings' );
	}

	/**
	 * Site settings.
	 *
	 * @return array
	 */
	private function site() {
		$opts = get_option( 'autodescription-site-settings', array() );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * TSF title → SearchPilot template (adds the site name TSF would add).
	 *
	 * @param string $title   Title.
	 * @param bool   $no_blog TSF was told not to add the site name.
	 * @return string
	 */
	private function title( $title, $no_blog ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return '';
		}
		$site  = $this->site();
		if ( $no_blog || ! empty( $site['title_rem_additions'] ) ) {
			return $title;
		}
		return $title . ' %sep% %sitename%';
	}

	/**
	 * Post data.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function read_post( $post_id ) {
		$noindex  = self::meta( $post_id, '_genesis_noindex' );
		$image_id = self::meta( $post_id, '_social_image_id' );
		return array(
			'title'               => $this->title( self::meta( $post_id, '_genesis_title' ), '1' === self::meta( $post_id, '_tsf_title_no_blogname' ) ),
			'description'         => self::meta( $post_id, '_genesis_description' ),
			'canonical'           => self::meta( $post_id, '_genesis_canonical_uri' ),
			'noindex'             => '1' === $noindex ? '1' : ( '-1' === $noindex ? '0' : '' ),
			'nofollow'            => '1' === self::meta( $post_id, '_genesis_nofollow' ) ? '1' : '',
			'og_title'            => self::meta( $post_id, '_open_graph_title' ),
			'og_description'      => self::meta( $post_id, '_open_graph_description' ),
			'og_image'            => ctype_digit( $image_id ) && (int) $image_id ? $image_id : self::meta( $post_id, '_social_image_url' ),
			'twitter_title'       => self::meta( $post_id, '_twitter_title' ),
			'twitter_description' => self::meta( $post_id, '_twitter_description' ),
			'primary_category'    => (int) self::meta( $post_id, '_primary_term_category' ),
		);
	}

	/**
	 * Term data.
	 *
	 * @param int $term_id Term id.
	 * @return array
	 */
	public function read_term( $term_id ) {
		$data = get_term_meta( (int) $term_id, 'autodescription-term-settings', true );
		$data = is_array( $data ) ? $data : array();
		$no   = (string) ( $data['noindex'] ?? '' );
		return array(
			'title'       => $this->title( (string) ( $data['doctitle'] ?? '' ), ! empty( $data['title_no_blog_name'] ) ),
			'description' => trim( (string) ( $data['description'] ?? '' ) ),
			'canonical'   => trim( (string) ( $data['canonical'] ?? '' ) ),
			'noindex'     => '1' === $no ? '1' : ( '-1' === $no ? '0' : '' ),
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public function read_settings() {
		$site = $this->site();
		if ( ! $site ) {
			return array();
		}
		$type    = (string) ( $site['knowledge_type'] ?? '' );
		$twitter = self::twitter( (string) ( $site['twitter_site'] ?? '' ) );
		$same_as = array();
		foreach ( array( 'knowledge_facebook', 'knowledge_twitter', 'knowledge_instagram', 'knowledge_youtube', 'knowledge_linkedin', 'knowledge_pinterest', 'knowledge_soundcloud', 'knowledge_tumblr' ) as $key ) {
			$same_as[] = (string) ( $site[ $key ] ?? '' );
		}
		$out = array(
			'meta.separator'        => self::SEPARATORS[ (string) ( $site['title_separator'] ?? '' ) ] ?? '',
			'meta.home_title'       => trim( (string) ( $site['homepage_title'] ?? '' ) ),
			'meta.home_description' => trim( (string) ( $site['homepage_description'] ?? '' ) ),
			'meta.twitter_site'     => $twitter,
			'meta.facebook_app_id'  => trim( (string) ( $site['facebook_appid'] ?? '' ) ),
			'meta.default_image'    => (int) ( $site['social_image_fb_id'] ?? 0 ),
			'schema.entity'         => 'person' === $type ? 'person' : ( 'organization' === $type ? 'organization' : '' ),
			'schema.org_name'       => trim( (string) ( $site['knowledge_name'] ?? '' ) ),
			'schema.org_logo'       => (int) ( $site['knowledge_logo_id'] ?? 0 ) ? (int) $site['knowledge_logo_id'] : self::attachment_id( (string) ( $site['knowledge_logo_url'] ?? '' ) ),
			'schema.same_as'        => self::urls( $same_as ),
		);
		return array_filter(
			$out,
			static function ( $v ) {
				return '' !== $v && 0 !== $v && array() !== $v;
			}
		);
	}
}
