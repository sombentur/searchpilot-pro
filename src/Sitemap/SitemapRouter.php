<?php
/**
 * Sitemap URLs, rewrite rules and HTTP serving.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Sitemap;

use SearchPilot\Core\Compat\ConflictDetector;
use SearchPilot\Core\CoreModule;

defined( 'ABSPATH' ) || exit;

/**
 * URL scheme (pretty permalinks):
 *   /sitemap.xml                       index
 *   /sitemap-pt-{post_type}-{n}.xml    posts of a type
 *   /sitemap-tax-{taxonomy}-{n}.xml    terms of a taxonomy
 *   /sitemap-authors-{n}.xml           author archives
 *   /sitemap-video-{n}.xml             video sitemap
 *   /sitemap-news.xml                  Google News sitemap
 *   /sitemap.xsl                       browser stylesheet
 * Plain permalinks use ?spp_sitemap={type}&spp_sitemap_page={n}.
 *
 * Documents are served on `parse_request` (the main query never runs)
 * with conditional-GET support (ETag / Last-Modified → 304).
 */
class SitemapRouter {

	const QV      = 'spp_sitemap';
	const QV_PAGE = 'spp_sitemap_page';

	/**
	 * Option remembering the rule set we registered (autoloaded, tiny).
	 */
	const RULES_OPTION = 'searchpilot_sitemap_rules';

	/**
	 * Builder.
	 *
	 * @var SitemapBuilder
	 */
	private $builder;

	/**
	 * Conflict detector.
	 *
	 * @var ConflictDetector
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param SitemapBuilder   $builder   Builder.
	 * @param ConflictDetector $conflicts Conflict detector.
	 */
	public function __construct( SitemapBuilder $builder, ConflictDetector $conflicts ) {
		$this->builder   = $builder;
		$this->conflicts = $conflicts;
	}

	/**
	 * Whether pretty permalinks are on.
	 *
	 * @return bool
	 */
	private static function pretty() {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * Public URL of a sitemap document.
	 *
	 * @param string $type Type id (index, pt-post, tax-category, authors, video, news, xsl).
	 * @param int    $page Page.
	 * @return string
	 */
	public static function url( $type, $page = 1 ) {
		$type = (string) $type;
		$page = max( 1, (int) $page );
		if ( ! self::pretty() ) {
			$args = array( self::QV => $type );
			if ( ! in_array( $type, array( 'index', 'news', 'xsl' ), true ) ) {
				$args[ self::QV_PAGE ] = $page;
			}
			return add_query_arg( $args, home_url( '/' ) );
		}
		switch ( $type ) {
			case 'index':
				return home_url( '/sitemap.xml' );
			case 'xsl':
				return home_url( '/sitemap.xsl' );
			case 'news':
				return home_url( '/sitemap-news.xml' );
			case 'authors':
			case 'video':
				return home_url( '/sitemap-' . $type . '-' . $page . '.xml' );
		}
		return home_url( '/sitemap-' . $type . '-' . $page . '.xml' );
	}

	/**
	 * Root-relative stylesheet URL (same origin, as browsers require for XSLT).
	 *
	 * @return string
	 */
	public static function stylesheet_url() {
		return wp_make_link_relative( self::url( 'xsl' ) );
	}

	/**
	 * init: registers rewrite rules while SearchPilot owns sitemaps and
	 * requests a rewrite flush when that changed.
	 */
	public function register_rules() {
		$owns      = $this->conflicts->owns( 'sitemap' );
		$signature = $owns ? 'searchpilot-2' : 'off';
		if ( $owns ) {
			add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QV . '=index', 'top' );
			add_rewrite_rule( '^sitemap\.xsl$', 'index.php?' . self::QV . '=xsl', 'top' );
			add_rewrite_rule( '^sitemap-news\.xml$', 'index.php?' . self::QV . '=news', 'top' );
			add_rewrite_rule( '^sitemap-(pt|tax)-([a-z0-9_\-]+)-([0-9]+)\.xml$', 'index.php?' . self::QV . '=$matches[1]-$matches[2]&' . self::QV_PAGE . '=$matches[3]', 'top' );
			add_rewrite_rule( '^sitemap-(authors|video)-([0-9]+)\.xml$', 'index.php?' . self::QV . '=$matches[1]&' . self::QV_PAGE . '=$matches[2]', 'top' );
			add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . self::QV . '=legacy', 'top' );
		}
		if ( get_option( self::RULES_OPTION ) !== $signature ) {
			update_option( self::RULES_OPTION, $signature, true );
			CoreModule::request_rewrite_flush();
		}
	}

	/**
	 * query_vars filter.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QV;
		$vars[] = self::QV_PAGE;
		return $vars;
	}

	/**
	 * parse_request: serves sitemap documents and redirects core's sitemap URLs.
	 *
	 * @param \WP $wp Request.
	 */
	public function maybe_serve( $wp ) {
		$vars = is_object( $wp ) && is_array( $wp->query_vars ) ? $wp->query_vars : array();
		if ( empty( $vars[ self::QV ] ) ) {
			// Core sitemaps (/wp-sitemap.xml and children) → our index.
			if ( ! empty( $vars['sitemap'] ) && empty( $vars['sitemap-stylesheet'] ) && $this->conflicts->owns( 'sitemap' ) ) {
				$this->redirect_to_index();
			}
			return;
		}
		if ( ! $this->conflicts->owns( 'sitemap' ) ) {
			// Stale rules from before another plugin took over sitemaps.
			CoreModule::request_rewrite_flush();
			return;
		}
		$type = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $vars[ self::QV ] ) );
		$page = isset( $vars[ self::QV_PAGE ] ) ? max( 1, absint( $vars[ self::QV_PAGE ] ) ) : 1;

		if ( 'xsl' === $type ) {
			$this->serve_xsl();
		}
		if ( 'legacy' === $type ) {
			$this->redirect_to_index();
		}
		if ( 'index' !== $type && ! $this->builder->is_type( $type ) ) {
			$this->not_found();
		}
		$result = $this->builder->render( $type, 'news' === $type || 'index' === $type ? 1 : $page );
		if ( null === $result ) {
			$this->not_found();
		}
		$this->serve_xml( $result );
	}

	/**
	 * 301 from a legacy sitemap address to our index. The redirect carries a
	 * marker: when another plugin or a server rule sends /sitemap.xml back to
	 * the legacy address, the marked request is answered with the index
	 * directly instead of looping, and the loop is reported by the health check.
	 */
	private function redirect_to_index() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only loop marker.
		if ( isset( $_GET['spp_via'] ) ) {
			set_transient( 'searchpilot_sitemap_redirect_loop', 1, DAY_IN_SECONDS );
			$result = $this->builder->render( 'index', 1 );
			if ( null !== $result ) {
				$this->serve_xml( $result );
			}
			$this->not_found();
		}
		wp_safe_redirect( add_query_arg( 'spp_via', 'legacy', self::url( 'index' ) ), 301, 'SearchPilot' );
		exit;
	}

	/**
	 * Sends an XML document with conditional-GET handling and exits.
	 *
	 * @param array $result Rendered document.
	 */
	private function serve_xml( array $result ) {
		$generated = (int) ( $result['generated'] ?? time() );
		$etag      = '"' . (string) ( $result['etag'] ?? md5( (string) $result['xml'] ) ) . '"';
		$modified  = gmdate( 'D, d M Y H:i:s', $generated ) . ' GMT';

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
			header( 'Last-Modified: ' . $modified );
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: no-cache' );
		}
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		$if_modified   = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		$not_modified  = '' !== $if_none_match ? in_array( $etag, array_map( 'trim', explode( ',', str_replace( 'W/', '', $if_none_match ) ) ), true ) : ( $if_modified && $if_modified >= $generated );
		if ( $not_modified ) {
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			exit;
		}
		echo $result['xml']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by SitemapXml.
		exit;
	}

	/**
	 * Serves the XSL stylesheet with translated labels and exits.
	 */
	private function serve_xsl() {
		$file = SEARCHPILOT_DIR . 'assets/sitemap.xsl';
		$xsl  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $xsl ) {
			$this->not_found();
		}
		$labels = array(
			'%%TITLE%%'         => __( 'XML Sitemap', 'searchpilot-pro' ),
			'%%INDEX_INTRO%%'   => __( 'This sitemap index lists the sitemaps search engines use to discover the pages of this site. It is generated by SearchPilot Pro.', 'searchpilot-pro' ),
			'%%URLSET_INTRO%%'  => __( 'This sitemap lists pages of this site for search engines. It is generated by SearchPilot Pro and updated automatically.', 'searchpilot-pro' ),
			'%%SITEMAP%%'       => __( 'Sitemap', 'searchpilot-pro' ),
			'%%URL%%'           => __( 'URL', 'searchpilot-pro' ),
			'%%IMAGES%%'        => __( 'Images', 'searchpilot-pro' ),
			'%%VIDEOS%%'        => __( 'Videos', 'searchpilot-pro' ),
			'%%NEWS%%'          => __( 'News title', 'searchpilot-pro' ),
			'%%LASTMOD%%'       => __( 'Last modified', 'searchpilot-pro' ),
			'%%COUNT_SITEMAPS%%' => __( 'Sitemaps in this index:', 'searchpilot-pro' ),
			'%%COUNT_URLS%%'    => __( 'URLs in this sitemap:', 'searchpilot-pro' ),
			'%%BACK%%'          => __( 'Back to the sitemap index', 'searchpilot-pro' ),
			'%%INDEX_URL%%'     => wp_make_link_relative( self::url( 'index' ) ),
		);
		$escaped = array_map( array( SitemapXml::class, 'escape' ), $labels );
		$xsl     = strtr( $xsl, $escaped );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/xsl; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Cache-Control: public, max-age=86400' );
		}
		status_header( 200 );
		echo $xsl; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static stylesheet; labels escaped above.
		exit;
	}

	/**
	 * Minimal 404 for unknown or empty sitemap pages, then exits.
	 */
	private function not_found() {
		status_header( 404 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}
		echo esc_html__( 'Sitemap not found.', 'searchpilot-pro' );
		exit;
	}
}
