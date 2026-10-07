<?php
/**
 * Redirect matcher tests.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Redirects\RedirectMatcher;

// phpcs:disable

class RedirectMatcherTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		$GLOBALS['spp_test_home'] = 'https://example.com/';
	}

	private function rule( $id, $source, $target, $type = 'exact', $code = 301 ) {
		return array(
			'id'          => $id,
			'source'      => $source,
			'target'      => $target,
			'match_type'  => $type,
			'status_code' => $code,
			'enabled'     => 1,
		);
	}

	public function test_normalize_path() {
		$this->assertSame( '/old-page/', RedirectMatcher::normalize_path( 'https://example.com/old-page/#top' ) );
		$this->assertSame( '/old-page', RedirectMatcher::normalize_path( 'old-page' ) );
		$this->assertSame( '/%E0%B2%95?x=%E0%B2%95', RedirectMatcher::normalize_path( '/ಕ?x=%e0%b2%95' ) );
		$this->assertSame( '', RedirectMatcher::normalize_path( '  ' ) );
	}

	public function test_key_ignores_case_and_trailing_slash_but_keeps_percent_case() {
		$this->assertSame( RedirectMatcher::key( '/Old-Page/' ), RedirectMatcher::key( '/old-page' ) );
		$this->assertSame( '/%E0%B2%95', RedirectMatcher::key( '/%e0%b2%95/' ) );
		$this->assertSame( RedirectMatcher::key( 'https://example.com/%E0%B2%95' ), RedirectMatcher::key( '/ಕ' ) );
		$this->assertSame( '/', RedirectMatcher::key( '/' ) );
		$this->assertSame( '/a/b', RedirectMatcher::key( '//a//b/' ) );
		$this->assertSame( '/a?ID=5', RedirectMatcher::key( '/a/?ID=5' ) );
	}

	public function test_request_hashes() {
		$h = RedirectMatcher::request_hashes( '/Old/?utm=1' );
		$this->assertSame( RedirectMatcher::hash( '/old' ), $h['path'] );
		$this->assertSame( RedirectMatcher::hash( '/old?utm=1' ), $h['full'] );
		$this->assertSame( 'utm=1', $h['query'] );
	}

	public function test_exact_match_passes_query_through() {
		$rules = array( $this->rule( 1, '/old', '/new' ) );
		$m     = RedirectMatcher::match( '/old/?ref=x', $rules );
		$this->assertSame( 1, $m['rule']['id'] );
		$this->assertSame( '/new?ref=x', $m['target'] );
		$this->assertSame( 'exact', $m['matched'] );
		$this->assertNull( RedirectMatcher::match( '/older', $rules ) );
	}

	public function test_exact_rule_with_query_wins_over_path_rule() {
		$rules = array( $this->rule( 1, '/page', '/generic' ), $this->rule( 2, '/page?id=7', '/specific' ) );
		$m     = RedirectMatcher::match( '/page?id=7', $rules );
		$this->assertSame( 2, $m['rule']['id'] );
		$this->assertSame( '/specific', $m['target'] );
		$m2 = RedirectMatcher::match( '/page?id=8', $rules );
		$this->assertSame( 1, $m2['rule']['id'] );
	}

	public function test_exact_wins_over_prefix_and_longest_prefix_wins() {
		$rules = array(
			$this->rule( 1, '/blog/', '/news/*', 'prefix' ),
			$this->rule( 2, '/blog/archive/', '/archive/', 'prefix' ),
			$this->rule( 3, '/blog/special', '/special-page' ),
		);
		$this->assertSame( '/news/2020/post', RedirectMatcher::match( '/blog/2020/post', $rules )['target'] );
		$this->assertSame( 2, RedirectMatcher::match( '/blog/archive/x', $rules )['rule']['id'] );
		$this->assertSame( 3, RedirectMatcher::match( '/blog/special/', $rules )['rule']['id'] );
	}

	public function test_regex_with_captures_and_unicode() {
		$rules = array( $this->rule( 1, '^/(\d{4})/(\d{2})/([a-z0-9-]+)/?$', '/$3/', 'regex' ) );
		$m     = RedirectMatcher::match( '/2020/05/hello-world/', $rules );
		$this->assertSame( '/hello-world/', $m['target'] );
		$uni = array( $this->rule( 2, '^/ಸುದ್ದಿ/(.*)$', '/news/$1', 'regex' ) );
		$m2  = RedirectMatcher::match( '/%E0%B2%B8%E0%B3%81%E0%B2%A6%E0%B3%8D%E0%B2%A6%E0%B2%BF/abc', $uni );
		$this->assertSame( '/news/abc', $m2['target'] );
	}

	public function test_internal_targets_cannot_become_external() {
		$rules = array( $this->rule( 1, '^/go/(.*)$', '/$1', 'regex' ) );
		$this->assertSame( '/evil.com/x', RedirectMatcher::match( '/go//evil.com/x', $rules )['target'] );
		$m = RedirectMatcher::match( '/go/http://evil.com', $rules );
		$this->assertSame( '/', substr( $m['target'], 0, 1 ) );
		$this->assertFalse( RedirectMatcher::is_external( $m['target'] ) );
		$prefix = array( $this->rule( 2, '/p/', '/new/*', 'prefix' ) );
		$this->assertSame( '/new/evil.com', RedirectMatcher::match( '/p//evil.com', $prefix )['target'] );
	}

	public function test_clean_input_keeps_percent_encoding() {
		$this->assertSame( '/%E0%B2%95-news/?a=1', RedirectMatcher::clean_input( " /%E0%B2%95-news/?a=1\n" ) );
		$this->assertSame( '/x', RedirectMatcher::clean_input( '<script>/x' ) );
		$this->assertSame( '^/(\d+)$', RedirectMatcher::clean_input( '^/(\d+)$' ) );
		list( $bare ) = RedirectMatcher::split( '/old-post/?fbclid=abc' );
		$this->assertSame( '/old-post/', $bare );
	}

	public function test_gone_rules_have_no_target() {
		$m = RedirectMatcher::match( '/removed', array( $this->rule( 1, '/removed', '', 'exact', 410 ) ) );
		$this->assertSame( '', $m['target'] );
		$this->assertSame( 410, $m['rule']['status_code'] );
	}

	public function test_disabled_rules_are_ignored() {
		$rule            = $this->rule( 1, '/old', '/new' );
		$rule['enabled'] = 0;
		$this->assertNull( RedirectMatcher::match( '/old', array( $rule ) ) );
	}

	public function test_compile_rejects_invalid_regex() {
		$this->assertSame( '', RedirectMatcher::compile( '([a-z' ) );
		$this->assertSame( '', RedirectMatcher::compile( str_repeat( 'a', 600 ) ) );
		$this->assertNotSame( '', RedirectMatcher::compile( '^/a~b/(.*)' ) );
	}

	public function test_validate() {
		$ok = RedirectMatcher::validate( array( 'source' => 'https://example.com/Old/', 'target' => 'https://example.com/new/', 'status_code' => 301 ) );
		$this->assertIsArray( $ok );
		$this->assertSame( '/Old/', $ok['source'] );
		$this->assertSame( '/new/', $ok['target'] );
		$this->assertSame( RedirectMatcher::hash( '/old' ), $ok['source_hash'] );

		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/a', 'target' => '/a/', 'status_code' => 301 ) ), 'Self redirect' );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/wp-admin/x', 'target' => '/b' ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/a', 'target' => 'javascript:alert(1)' ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/a', 'target' => 'https://other.org/x' ) ), 'External needs admin' );
		$this->assertIsArray( RedirectMatcher::validate( array( 'source' => '/a', 'target' => 'https://other.org/x' ), array( 'allow_external' => true ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/a', 'target' => '/b', 'status_code' => 303 ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => 'https://other.org/a', 'target' => '/b' ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '/', 'target' => '/b', 'match_type' => 'prefix' ) ) );
		$this->assertInstanceOf( \WP_Error::class, RedirectMatcher::validate( array( 'source' => '([a', 'target' => '/b', 'match_type' => 'regex' ) ) );
		$gone = RedirectMatcher::validate( array( 'source' => '/gone', 'target' => '/ignored', 'status_code' => 410 ) );
		$this->assertSame( '', $gone['target'] );
	}

	public function test_excluded_paths() {
		foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/wp-cron.php', '/sitemap.xml', '/sitemap-pt-post-1.xml', '/wp-sitemap.xml', '/sitemap_index.xml', '/robots.txt', '/?rest_route=/x', '/sitemap.xsl', '/post-sitemap.xml' ) as $path ) {
			$this->assertTrue( RedirectMatcher::is_excluded( $path ), $path );
		}
		$this->assertTrue( RedirectMatcher::is_excluded( '/blog/wp-admin/', array(), '/blog' ) );
		$this->assertTrue( RedirectMatcher::is_excluded( '/abc123.txt', array( '/abc123.txt' ) ) );
		$this->assertFalse( RedirectMatcher::is_excluded( '/about/' ) );
		$this->assertFalse( RedirectMatcher::is_excluded( '/sitemap-page/' ) );
	}

	public function test_wildcard_ignore_patterns() {
		$patterns = "/wp-content/*.map\n/favicon.ico\n# comment\n/apple-touch-icon*";
		$this->assertTrue( RedirectMatcher::wildcard_match( '/wp-content/themes/x/app.js.map', $patterns ) );
		$this->assertTrue( RedirectMatcher::wildcard_match( '/favicon.ico', $patterns ) );
		$this->assertTrue( RedirectMatcher::wildcard_match( '/apple-touch-icon-120x120.png', $patterns ) );
		$this->assertFalse( RedirectMatcher::wildcard_match( '/favicon.ico.bak', $patterns ) );
		$this->assertFalse( RedirectMatcher::wildcard_match( '/about', $patterns ) );
	}

	public function test_analyze_finds_chains_and_loops() {
		$rules  = array(
			$this->rule( 1, '/a', '/b' ),
			$this->rule( 2, '/b', '/c/' ),
			$this->rule( 3, '/x', '/y' ),
			$this->rule( 4, '/y', 'https://example.com/x/' ),
			$this->rule( 5, '/p', 'https://other.org/p' ),
			$this->rule( 6, '/q', '/r', 'prefix' ),
		);
		$result = RedirectMatcher::analyze( $rules );
		$this->assertCount( 1, $result['loops'] );
		$loop = $result['loops'][0];
		sort( $loop );
		$this->assertSame( array( 3, 4 ), $loop );
		$this->assertCount( 1, $result['chains'] );
		$this->assertSame( array( 1, 2 ), $result['chains'][0]['ids'] );
		$this->assertSame( '/c/', $result['chains'][0]['final'] );
	}

	public function test_resolve_target_keeps_fragment_after_query() {
		$this->assertSame( '/new?x=1#part', RedirectMatcher::resolve_target( $this->rule( 1, '/old', '/new#part' ), array(), 'x=1' ) );
		$this->assertSame( '/new?keep=1', RedirectMatcher::resolve_target( $this->rule( 1, '/old', '/new?keep=1' ), array(), 'x=1' ) );
	}
}
