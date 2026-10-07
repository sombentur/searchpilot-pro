<?php
/**
 * Tests for Support\Url.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Support\Url;

// phpcs:disable

class UrlTest extends \PHPUnit\Framework\TestCase {

	public function test_normalize_lowercases_host_and_scheme() {
		$this->assertSame( 'https://example.com/Path/', Url::normalize( 'HTTPS://EXAMPLE.com/Path/' ) );
	}

	public function test_normalize_removes_fragment_default_port_and_tracking() {
		$this->assertSame( 'https://example.com/a?b=1', Url::normalize( 'https://example.com:443/a?utm_source=x&b=1&fbclid=abc#top' ) );
	}

	public function test_percent_encoding_case_is_normalized() {
		// WordPress stores Kannada slugs lower-case encoded, Search Console reports upper-case.
		$wp  = 'https://example.com/%e0%b2%95%e0%b2%b0/';
		$gsc = 'https://example.com/%E0%B2%95%E0%B2%B0/';
		$this->assertSame( Url::hash( $wp ), Url::hash( $gsc ) );
	}

	public function test_raw_unicode_is_encoded() {
		$this->assertSame( 'https://example.com/%E0%B2%95/', Url::normalize( 'https://example.com/ಕ/' ) );
	}

	public function test_relative_urls_become_absolute() {
		$this->assertSame( 'https://example.com/about/', Url::absolute( '/about/' ) );
		$this->assertSame( 'https://cdn.example.org/x.js', Url::absolute( '//cdn.example.org/x.js' ) );
		$this->assertSame( 'https://example.com/blog/b', Url::absolute( 'b', 'https://example.com/blog/a' ) );
		$this->assertSame( 'https://example.com/b', Url::absolute( '../b', 'https://example.com/blog/a' ) );
	}

	public function test_internal_detection_ignores_www() {
		$this->assertTrue( Url::is_internal( 'https://www.example.com/x' ) );
		$this->assertTrue( Url::is_internal( '/x' ) );
		$this->assertFalse( Url::is_internal( 'https://other.com/x' ) );
		$this->assertFalse( Url::is_internal( 'mailto:a@example.com' ) );
		$this->assertFalse( Url::is_internal( '#section' ) );
		$this->assertTrue( Url::is_internal( 'http://example.com/legacy-http-link/' ) );
		$this->assertFalse( Url::is_internal( 'http://example.com:6379/internal' ) );
		$this->assertFalse( Url::is_internal( 'https://example.com:8443/x' ) );
	}

	public function test_to_path_keeps_query() {
		$this->assertSame( '/a/b?c=1', Url::to_path( 'https://example.com/a/b?c=1' ) );
	}

	public function test_display_decodes() {
		$this->assertSame( 'https://example.com/ಕ/', Url::display( 'https://example.com/%E0%B2%95/' ) );
	}
}
