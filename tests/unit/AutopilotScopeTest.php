<?php
/**
 * Tests for what Autopilot may do alone (1.1.1): auto_ok and stray "sitemaps".
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Fixes\Fixers\StraySitemapFixer;
use SearchPilot\Issues\IssueRegistry;

/**
 * IssueRegistry::auto_ok() and StraySitemapFixer tests.
 */
class AutopilotScopeTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		$GLOBALS['spp_test_home'] = 'https://example.com/';
	}

	public function test_auto_ok_is_off_unless_declared() {
		$this->assertFalse( IssueRegistry::auto_ok( array(), array( 'id' => 1 ) ) );
		$this->assertFalse( IssueRegistry::auto_ok( array( 'auto_ok' => false ), array() ) );
		$this->assertFalse( IssueRegistry::auto_ok( array( 'auto_ok' => 'yes' ), array() ), 'Only a real true or a callable counts.' );
		$this->assertTrue( IssueRegistry::auto_ok( array( 'auto_ok' => true ), array() ) );
	}

	public function test_auto_ok_callable_decides_per_issue() {
		$def = array(
			'auto_ok' => static function ( $issue ) {
				return 'short' === ( $issue['data']['direction'] ?? '' );
			},
		);
		$this->assertTrue( IssueRegistry::auto_ok( $def, array( 'data' => array( 'direction' => 'short' ) ) ) );
		$this->assertFalse( IssueRegistry::auto_ok( $def, array( 'data' => array( 'direction' => 'long' ) ) ) );
		$this->assertFalse( IssueRegistry::auto_ok( $def, null ), 'No issue, no automatic run.' );
		$broken = array(
			'auto_ok' => static function () {
				throw new \RuntimeException( 'boom' );
			},
		);
		$this->assertFalse( IssueRegistry::auto_ok( $broken, array() ) );
	}

	public function test_pages_and_ads_txt_are_never_sitemaps() {
		foreach ( array(
			'https://example.com/india-pakistan-asian-games-final/',
			'https://www.example.com/why-ats-rejects-your-resume/',
			'https://example.com/2026/10/02/some-story',
			'https://example.com/ads.txt',
			'https://example.com/robots.txt',
			'https://example.com/about.html',
			'https://example.com/%E0%B2%95%E0%B2%A8%E0%B3%8D%E0%B2%A8%E0%B2%A1/',
		) as $url ) {
			$this->assertTrue( StraySitemapFixer::is_stray( $url ), $url );
		}
	}

	public function test_real_sitemaps_feeds_and_foreign_urls_are_kept() {
		foreach ( array(
			'https://example.com/sitemap.xml',
			'https://example.com/sitemap_index.xml',
			'https://example.com/post-sitemap.xml',
			'https://example.com/wp-sitemap.xml',
			'https://example.com/news-sitemap.xml.gz',
			'https://example.com/urllist.txt',
			'https://example.com/feed/',
			'https://example.com/category/news/feed/',
			'https://example.com/?feed=sitemap',
			'https://example.com/sitemap/',
			'https://other.example.org/some-page/',
			'',
		) as $url ) {
			$this->assertFalse( StraySitemapFixer::is_stray( $url ), $url );
		}
	}

	public function test_only_erroring_non_index_entries_are_removed() {
		$issue = array(
			'data' => array(
				'sitemaps' => array(
					array( 'path' => 'https://example.com/a-story/', 'errors' => 1, 'is_sitemaps_index' => false ),
					array( 'path' => 'https://example.com/b-story/', 'errors' => 0, 'is_sitemaps_index' => false ),
					array( 'path' => 'https://example.com/sitemap_index.xml', 'errors' => 2, 'is_sitemaps_index' => true ),
					array( 'path' => 'https://example.com/ads.txt', 'errors' => 1 ),
				),
			),
		);
		$this->assertSame( array( 'https://example.com/a-story/', 'https://example.com/ads.txt' ), StraySitemapFixer::stray_entries( $issue ) );
		$this->assertSame( array(), StraySitemapFixer::stray_entries( array( 'data' => array() ) ) );
	}
}
