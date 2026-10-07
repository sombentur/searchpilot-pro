<?php
/**
 * Sitemap XML writer tests.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Sitemap\SitemapXml;

// phpcs:disable

class SitemapXmlTest extends \PHPUnit\Framework\TestCase {

	private function parse( $xml ) {
		$doc = new \DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ), 'Output must be well-formed XML' );
		return $doc;
	}

	public function test_escape_handles_special_and_invalid_characters() {
		$this->assertSame( 'Tom &amp; Jerry &lt;b&gt; &quot;x&quot; &apos;y&apos;', SitemapXml::escape( "Tom & Jerry <b> \"x\" 'y'" ) );
		// Control characters are not allowed in XML 1.0.
		$this->assertSame( 'ab', SitemapXml::escape( "a\x01\x0Bb" ) );
		// Kannada text survives untouched.
		$this->assertSame( 'ಕರ್ನಾಟಕ ಸುದ್ದಿ', SitemapXml::escape( 'ಕರ್ನಾಟಕ ಸುದ್ದಿ' ) );
		// Invalid UTF-8 never breaks the document.
		$this->assertNotSame( '', SitemapXml::escape( "abc\xC3" ) );
	}

	public function test_loc_encodes_iri_and_escapes_ampersands() {
		$this->assertSame( 'https://example.com/%E0%B2%95/?a=1&amp;b=2', SitemapXml::loc( 'https://example.com/ಕ/?a=1&b=2' ) );
		$this->assertSame( '', SitemapXml::loc( '/relative' ) );
		$this->assertSame( '', SitemapXml::loc( 'javascript:alert(1)' ) );
	}

	public function test_w3c_date() {
		$this->assertSame( '2026-09-30T03:05:56+00:00', SitemapXml::w3c_date( '2026-09-30 03:05:56' ) );
		$this->assertSame( '', SitemapXml::w3c_date( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', SitemapXml::w3c_date( '' ) );
		$this->assertSame( '1970-01-02T00:00:00+00:00', SitemapXml::w3c_date( 86400 ) );
		$this->assertSame( '2026-09-29T22:05:56+00:00', SitemapXml::w3c_date( '2026-09-30T03:35:56+05:30' ) );
	}

	public function test_index_document() {
		$xml = SitemapXml::index(
			array(
				array( 'loc' => 'https://example.com/sitemap-pt-post-1.xml', 'lastmod' => '2026-09-01 10:00:00' ),
				array( 'loc' => 'https://example.com/sitemap-pt-post-1.xml', 'lastmod' => '2026-09-01 10:00:00' ),
				array( 'loc' => 'https://example.com/sitemap-tax-category-1.xml', 'lastmod' => '' ),
			),
			'/sitemap.xsl',
			'Generated -- test'
		);
		$doc = $this->parse( $xml );
		$this->assertSame( 'sitemapindex', $doc->documentElement->localName );
		$this->assertSame( SitemapXml::NS_SITEMAP, $doc->documentElement->namespaceURI );
		$this->assertSame( 2, $doc->getElementsByTagName( 'sitemap' )->length, 'Duplicates are removed' );
		$this->assertStringContainsString( '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>', $xml );
		$this->assertStringNotContainsString( 'Generated -- test', $xml );
		$this->assertSame( 1, $doc->getElementsByTagName( 'lastmod' )->length );
	}

	public function test_urlset_declares_only_used_namespaces_and_never_writes_priority() {
		$xml = SitemapXml::urlset( array( array( 'loc' => 'https://example.com/', 'lastmod' => '2026-09-01 10:00:00' ) ) );
		$this->assertStringNotContainsString( 'xmlns:image', $xml );
		$this->assertStringNotContainsString( 'xmlns:video', $xml );
		$this->assertStringNotContainsString( 'priority', $xml );
		$this->assertStringNotContainsString( 'changefreq', $xml );
		$this->parse( $xml );
	}

	public function test_urlset_with_images_video_and_news() {
		$stats   = null;
		$entries = array(
			array(
				'loc'     => 'https://example.com/ಸುದ್ದಿ/',
				'lastmod' => '2026-09-30 03:05:56',
				'images'  => array(
					array( 'loc' => 'https://example.com/wp-content/uploads/a.jpg' ),
					array( 'loc' => 'https://example.com/wp-content/uploads/a.jpg' ),
					array( 'loc' => 'https://example.com/wp-content/uploads/b&c.jpg' ),
				),
				'videos'  => array(
					array(
						'thumbnail_loc' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
						'title'         => 'Video & more',
						'description'   => 'A <b>video</b>',
						'player_loc'    => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
						'duration'      => 212,
					),
					// Missing description: skipped.
					array(
						'thumbnail_loc' => 'https://i.ytimg.com/vi/x/hqdefault.jpg',
						'title'         => 'Incomplete',
						'player_loc'    => 'https://www.youtube.com/embed/x',
					),
				),
				'news'    => array(
					'name'             => 'Prajavani & Co',
					'language'         => 'kn',
					'publication_date' => '2026-09-30 03:00:00',
					'title'            => 'ಹೊಸ ಯೋಜನೆ',
				),
			),
			array( 'loc' => 'not a url' ),
		);
		$xml = SitemapXml::urlset( $entries, array( 'stylesheet' => '/sitemap.xsl' ), $stats );
		$doc = $this->parse( $xml );
		$this->assertSame( 1, $stats['urls'] );
		$this->assertSame( 2, $stats['images'] );
		$this->assertSame( 1, $stats['videos'] );
		$this->assertSame( 1, $stats['news'] );
		$this->assertSame( 2, $doc->getElementsByTagNameNS( SitemapXml::NS_IMAGE, 'image' )->length );
		$this->assertSame( 1, $doc->getElementsByTagNameNS( SitemapXml::NS_VIDEO, 'video' )->length );
		$this->assertSame( 'kn', $doc->getElementsByTagNameNS( SitemapXml::NS_NEWS, 'language' )->item( 0 )->textContent );
		$this->assertSame( 'ಹೊಸ ಯೋಜನೆ', $doc->getElementsByTagNameNS( SitemapXml::NS_NEWS, 'title' )->item( 0 )->textContent );
		$this->assertSame( 'https://example.com/%E0%B2%B8%E0%B3%81%E0%B2%A6%E0%B3%8D%E0%B2%A6%E0%B2%BF/', $doc->getElementsByTagName( 'loc' )->item( 0 )->textContent );
		$this->assertSame( '212', $doc->getElementsByTagNameNS( SitemapXml::NS_VIDEO, 'duration' )->item( 0 )->textContent );
	}

	public function test_image_cap_per_url() {
		$images = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$images[] = array( 'loc' => 'https://example.com/i' . $i . '.jpg' );
		}
		$stats = null;
		SitemapXml::urlset( array( array( 'loc' => 'https://example.com/p', 'images' => $images ) ), array( 'max_images' => 50 ), $stats );
		$this->assertSame( 50, $stats['images'] );
	}

	public function test_size_cap_drops_media_before_urls() {
		$images = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$images[] = array( 'loc' => 'https://example.com/wp-content/uploads/image-' . $i . '.jpg' );
		}
		$entries = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$entries[] = array( 'loc' => 'https://example.com/post-' . $i . '/', 'images' => $images );
		}
		$stats = null;
		$xml   = SitemapXml::urlset( $entries, array( 'max_bytes' => 4000 ), $stats );
		$this->assertLessThanOrEqual( 4000, strlen( $xml ) );
		$this->assertSame( 5, $stats['urls'], 'Every URL is kept while media is dropped' );
		$this->parse( $xml );
	}

	public function test_news_language_codes() {
		$this->assertSame( 'kn', SitemapXml::news_language( 'kn' ) );
		$this->assertSame( 'kn', SitemapXml::news_language( 'kn_IN' ) );
		$this->assertSame( 'en', SitemapXml::news_language( 'en_US' ) );
		$this->assertSame( 'zh-cn', SitemapXml::news_language( 'zh_CN' ) );
		$this->assertSame( 'zh-tw', SitemapXml::news_language( 'zh_TW' ) );
		$this->assertSame( 'zh-tw', SitemapXml::news_language( 'zh_HK' ) );
		$this->assertSame( 'pt', SitemapXml::news_language( 'pt_BR' ) );
		$this->assertSame( 'en', SitemapXml::news_language( '' ) );
	}

	public function test_validate() {
		$ok = SitemapXml::validate( SitemapXml::urlset( array( array( 'loc' => 'https://example.com/' ) ) ) );
		$this->assertTrue( $ok['valid'] );
		$this->assertSame( 'urlset', $ok['root'] );
		$idx = SitemapXml::validate( SitemapXml::index( array( array( 'loc' => 'https://example.com/a.xml' ) ) ) );
		$this->assertSame( 'sitemapindex', $idx['root'] );
		$bad = SitemapXml::validate( '<urlset><url>' );
		$this->assertFalse( $bad['valid'] );
		$ns = SitemapXml::validate( '<?xml version="1.0"?><urlset><url><loc>https://x/</loc></url></urlset>' );
		$this->assertFalse( $ns['valid'] );
		$this->assertSame( 'namespace', $ns['error'] );
		$html = SitemapXml::validate( '<html><body>Not found</body></html>' );
		$this->assertFalse( $html['valid'] );
		$this->assertSame( 'root', $html['error'] );
		$this->assertFalse( SitemapXml::validate( '' )['valid'] );
	}

	public function test_video_validity() {
		$this->assertTrue( SitemapXml::video_is_valid( array( 'thumbnail_loc' => 'https://x.com/t.jpg', 'title' => 'T', 'description' => 'D', 'content_loc' => 'https://x.com/v.mp4' ) ) );
		$this->assertFalse( SitemapXml::video_is_valid( array( 'thumbnail_loc' => '', 'title' => 'T', 'description' => 'D', 'content_loc' => 'https://x.com/v.mp4' ) ) );
		$this->assertFalse( SitemapXml::video_is_valid( array( 'thumbnail_loc' => 'https://x.com/t.jpg', 'title' => 'T', 'description' => 'D' ) ) );
	}
}
