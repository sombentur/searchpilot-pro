<?php
/**
 * robots.txt parser tests (RFC 9309 semantics).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Support\RobotsParser;

// phpcs:disable

class RobotsParserTest extends \PHPUnit\Framework\TestCase {

	public function test_groups_and_agent_precedence() {
		$robots = RobotsParser::parse(
			"User-agent: *\nDisallow: /private/\n\nUser-agent: Googlebot\nDisallow: /no-google/\n\nUser-agent: Googlebot-Image\nDisallow: /\n"
		);
		// Googlebot uses only its own group, not the * group.
		$this->assertTrue( $robots->is_allowed( '/private/x', 'Googlebot' ) );
		$this->assertFalse( $robots->is_allowed( '/no-google/x', 'Googlebot' ) );
		// The most specific token wins.
		$this->assertFalse( $robots->is_allowed( '/anything', 'Googlebot-Image' ) );
		// Unknown crawlers fall back to *.
		$this->assertFalse( $robots->is_allowed( '/private/x', 'bingbot' ) );
		$this->assertTrue( $robots->is_allowed( '/no-google/x', 'bingbot' ) );
	}

	public function test_consecutive_user_agents_share_a_group_and_groups_merge() {
		$robots = RobotsParser::parse( "User-agent: bingbot\nUser-agent: Googlebot\nDisallow: /a/\n\nUser-agent: googlebot\nDisallow: /b/\n" );
		$this->assertFalse( $robots->is_allowed( '/a/1', 'bingbot' ) );
		$this->assertFalse( $robots->is_allowed( '/a/1', 'Googlebot' ) );
		$this->assertFalse( $robots->is_allowed( '/b/1', 'Googlebot' ), 'Groups for the same agent are merged' );
		$this->assertTrue( $robots->is_allowed( '/b/1', 'bingbot' ) );
	}

	public function test_longest_match_wins() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow: /shop/\nAllow: /shop/public/\nDisallow: /shop/public/secret\n" );
		$this->assertFalse( $robots->is_allowed( '/shop/cart' ) );
		$this->assertTrue( $robots->is_allowed( '/shop/public/item' ) );
		$this->assertFalse( $robots->is_allowed( '/shop/public/secret-file' ) );
		$this->assertSame( array( 'allow', '/shop/public/' ), $robots->matching_rule( '/shop/public/item' ) );
	}

	public function test_allow_wins_ties() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow: /page\nAllow: /page\n" );
		$this->assertTrue( $robots->is_allowed( '/page' ) );
		$this->assertSame( 'allow', $robots->matching_rule( '/page' )[0] );
	}

	public function test_wildcards_and_end_anchor() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow: /*.pdf$\nDisallow: /*?replytocom=\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-admin/\n" );
		$this->assertFalse( $robots->is_allowed( '/files/report.pdf' ) );
		$this->assertTrue( $robots->is_allowed( '/files/report.pdf?download=1' ), '$ anchors the end' );
		$this->assertFalse( $robots->is_allowed( '/post/?replytocom=5' ) );
		$this->assertTrue( $robots->is_allowed( '/wp-admin/admin-ajax.php' ) );
		$this->assertFalse( $robots->is_allowed( '/wp-admin/options.php' ) );
		$this->assertTrue( $robots->is_allowed( 'https://example.com/files/report.html' ) );
	}

	public function test_empty_disallow_allows_everything() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow:\n" );
		$this->assertTrue( $robots->is_allowed( '/anything' ) );
		$this->assertNull( $robots->matching_rule( '/anything' ) );
	}

	public function test_robots_txt_itself_is_always_allowed() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow: /\n" );
		$this->assertFalse( $robots->is_allowed( '/' ) );
		$this->assertTrue( $robots->is_allowed( '/robots.txt' ) );
	}

	public function test_percent_encoding_is_normalized() {
		$robots = RobotsParser::parse( "User-agent: *\nDisallow: /ಸುದ್ದಿ/\nDisallow: /%e0%b2%95/\n" );
		// Raw UTF-8 in the rule matches the encoded path and vice versa, case-insensitively for escapes.
		$this->assertFalse( $robots->is_allowed( '/%E0%B2%B8%E0%B3%81%E0%B2%A6%E0%B3%8D%E0%B2%A6%E0%B2%BF/x' ) );
		$this->assertFalse( $robots->is_allowed( '/%E0%B2%95/y' ) );
		$this->assertFalse( $robots->is_allowed( '/ಕ/z' ) );
		$this->assertTrue( $robots->is_allowed( '/%E0%B2%96/' ) );
	}

	public function test_comments_bom_sitemaps_and_rules_without_group() {
		$robots = RobotsParser::parse( "\xEF\xBB\xBFDisallow: /ignored/\n# comment\nUser-agent: * # everyone\nDisallow: /tmp/ # temp\nSitemap: https://example.com/sitemap_index.xml\nsitemap: https://example.com/news.xml\n" );
		$this->assertTrue( $robots->is_allowed( '/ignored/x' ), 'Rules before any user-agent are ignored' );
		$this->assertFalse( $robots->is_allowed( '/tmp/x' ) );
		$this->assertSame( array( 'https://example.com/sitemap_index.xml', 'https://example.com/news.xml' ), $robots->sitemaps() );
	}

	public function test_custom_rules_keep_percent_encoded_paths() {
		// Saving and previewing both run Settings::sanitize_raw_text(); a Kannada path must survive
		// (sanitize_textarea_field() would turn it into "Disallow: /", blocking the whole site).
		$raw   = "User-agent: *\r\nDisallow: /%E0%B2%95%E0%B2%A8/\r\nDisallow: /ಸುದ್ದಿ/\r\n<b>Allow: /x</b>";
		$clean = \SearchPilot\Core\Settings::sanitize_raw_text( $raw );
		$this->assertStringContainsString( 'Disallow: /%E0%B2%95%E0%B2%A8/', $clean );
		$rules = \SearchPilot\Sitemap\RobotsTxt::clean_rules( $clean );
		$this->assertContains( 'Disallow: /%E0%B2%95%E0%B2%A8/', $rules['lines'] );
		$this->assertContains( 'Disallow: /%E0%B2%B8%E0%B3%81%E0%B2%A6%E0%B3%8D%E0%B2%A6%E0%B2%BF/', $rules['lines'] );
		$this->assertNotContains( 'Disallow: /', $rules['lines'] );
		$file = \SearchPilot\Sitemap\RobotsTxt::compose( "User-agent: *\nDisallow: /wp-admin/\n", $clean, 'https://example.com/sitemap.xml' );
		$robots = RobotsParser::parse( $file );
		$this->assertFalse( $robots->is_allowed( '/%E0%B2%95%E0%B2%A8/story' ) );
		$this->assertTrue( $robots->is_allowed( '/', 'Googlebot' ), 'The rest of the site stays crawlable' );
		$this->assertSame( array( 'https://example.com/sitemap.xml' ), $robots->sitemaps() );
	}

	public function test_no_rules_allows_all() {
		$robots = RobotsParser::parse( '' );
		$this->assertTrue( $robots->is_allowed( '/x', 'Googlebot' ) );
		$this->assertSame( array(), $robots->rules_for( 'Googlebot' ) );
	}
}
