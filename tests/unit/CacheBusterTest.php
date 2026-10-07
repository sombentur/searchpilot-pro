<?php
/**
 * Tests for the re-scan cache buster.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Indexer\CacheBuster;

/**
 * CacheBuster tests.
 */
class CacheBusterTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Fixed token.
	 */
	const T = 'spp0a1b2c3d4e5f';

	public function test_token_is_well_formed_and_unique() {
		$a = CacheBuster::token();
		$b = CacheBuster::token();
		$this->assertTrue( CacheBuster::valid( $a ) );
		$this->assertSame( 0, strpos( $a, 'spp' ) );
		$this->assertNotSame( $a, $b );
		$this->assertFalse( CacheBuster::valid( 'bad token!' ) );
		$this->assertFalse( CacheBuster::valid( '' ) );
	}

	public function test_add_without_and_with_query() {
		$this->assertSame( 'https://example.com/a/?nocache=' . self::T, CacheBuster::add( 'https://example.com/a/', self::T ) );
		$this->assertSame( 'https://example.com/?p=5&nocache=' . self::T, CacheBuster::add( 'https://example.com/?p=5', self::T ) );
		$this->assertSame( 'https://example.com/?nocache=' . self::T, CacheBuster::add( 'https://example.com/?', self::T ) );
		$this->assertSame( 'https://example.com/a/?nocache=' . self::T . '#top', CacheBuster::add( 'https://example.com/a/#top', self::T ) );
	}

	public function test_add_keeps_kannada_paths_untouched() {
		$url = 'https://example.com/%E0%B2%95%E0%B2%A8%E0%B3%8D%E0%B2%A8%E0%B2%A1/';
		$this->assertSame( $url . '?nocache=' . self::T, CacheBuster::add( $url, self::T ) );
		$this->assertSame( $url, CacheBuster::strip( CacheBuster::add( $url, self::T ), self::T ) );
	}

	public function test_invalid_token_changes_nothing() {
		$this->assertSame( 'https://example.com/a/', CacheBuster::add( 'https://example.com/a/', 'x y' ) );
		$this->assertSame( 'https://example.com/a/?nocache=x', CacheBuster::strip( 'https://example.com/a/?nocache=x', 'x' ) );
	}

	public function test_strip_round_trips_urls() {
		foreach ( array( 'https://example.com/a/', 'https://example.com/?p=5', 'https://example.com/a/?x=1&y=2', 'https://example.com/a/#frag' ) as $url ) {
			$this->assertSame( $url, CacheBuster::strip( CacheBuster::add( $url, self::T ), self::T ), $url );
		}
	}

	public function test_strip_first_argument_followed_by_others() {
		$this->assertSame( 'https://example.com/a/?x=1', CacheBuster::strip( 'https://example.com/a/?nocache=' . self::T . '&x=1', self::T ) );
		$this->assertSame( '/a/?x=1', CacheBuster::strip( '/a/?nocache=' . self::T . '&amp;x=1', self::T ) );
		$this->assertSame( '/a/?x=1', CacheBuster::strip( '/a/?nocache=' . self::T . '&#038;x=1', self::T ) );
	}

	public function test_strip_html_and_json_forms() {
		$html = '<link rel="canonical" href="https://example.com/a/?nocache=' . self::T . '">'
			. '<a href="https://example.com/a/?x=1&amp;nocache=' . self::T . '">share</a>'
			. '<a href="https://twitter.com/intent?url=https%3A%2F%2Fexample.com%2Fa%2F%3Fnocache%3D' . self::T . '">tw</a>'
			. '<script type="application/ld+json">{"url":"https:\/\/example.com\/a\/?nocache=' . self::T . '"}</script>';
		$out  = CacheBuster::strip( $html, self::T );
		$this->assertStringNotContainsString( self::T, $out );
		$this->assertStringContainsString( 'href="https://example.com/a/"', $out );
		$this->assertStringContainsString( 'href="https://example.com/a/?x=1"', $out );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Fa%2F"', $out );
		$this->assertStringContainsString( '"url":"https:\/\/example.com\/a\/"', $out );
	}

	public function test_strip_leaves_other_nocache_values_alone() {
		$text = 'https://example.com/a/?nocache=1';
		$this->assertSame( $text, CacheBuster::strip( $text, self::T ) );
	}

	public function test_strip_deep_handles_header_arrays() {
		$headers = array(
			'location' => 'https://example.com/b/?nocache=' . self::T,
			'link'     => array( '<https://example.com/b/?nocache=' . self::T . '>; rel="canonical"', '<https://example.com/>; rel="shortlink"' ),
			'x-num'    => 5,
		);
		$out     = CacheBuster::strip_deep( $headers, self::T );
		$this->assertSame( 'https://example.com/b/', $out['location'] );
		$this->assertSame( '<https://example.com/b/>; rel="canonical"', $out['link'][0] );
		$this->assertSame( 5, $out['x-num'] );
	}

	public function test_request_headers() {
		$this->assertSame(
			array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			),
			CacheBuster::headers()
		);
	}
}
