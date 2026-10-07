<?php
/**
 * Tests for Api\IndexNow\IndexNow pure helpers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Api\IndexNow\IndexNow;

// phpcs:disable

class IndexNowPayloadTest extends \PHPUnit\Framework\TestCase {

	public function test_payload_shape() {
		$built = IndexNow::build_payload(
			array( 'https://example.com/a/', 'https://example.com/b/' ),
			'https://example.com/',
			'0c1caaefd7b84aea82b1f4b62b414bcf',
			'https://example.com/0c1caaefd7b84aea82b1f4b62b414bcf.txt'
		);
		$this->assertSame(
			array(
				'host'        => 'example.com',
				'key'         => '0c1caaefd7b84aea82b1f4b62b414bcf',
				'keyLocation' => 'https://example.com/0c1caaefd7b84aea82b1f4b62b414bcf.txt',
				'urlList'     => array( 'https://example.com/a/', 'https://example.com/b/' ),
			),
			$built['payload']
		);
		$this->assertSame( array(), $built['skipped'] );
	}

	public function test_only_urls_of_the_home_host_are_sent() {
		$built = IndexNow::build_payload(
			array( 'https://example.com/ok/', 'https://www.example.com/www-is-another-host/', 'https://evil.com/x', 'ftp://example.com/file', '' ),
			'https://example.com/',
			'k1234567',
			'https://example.com/k1234567.txt'
		);
		$this->assertSame( array( 'https://example.com/ok/' ), $built['payload']['urlList'] );
		$this->assertCount( 4, $built['skipped'] );
	}

	public function test_urls_are_normalized_and_deduplicated() {
		$built = IndexNow::build_payload(
			array( 'https://example.com/%e0%b2%95/', 'https://EXAMPLE.com/%E0%B2%95/', 'https://example.com/%E0%B2%95/?utm_source=news', '/relative/' ),
			'https://example.com/',
			'k1234567',
			'https://example.com/k1234567.txt'
		);
		$this->assertSame( array( 'https://example.com/%E0%B2%95/', 'https://example.com/relative/' ), $built['payload']['urlList'] );
	}

	public function test_url_list_is_capped_at_ten_thousand() {
		$urls = array();
		for ( $i = 0; $i < 10050; $i++ ) {
			$urls[] = 'https://example.com/p/' . $i . '/';
		}
		$built = IndexNow::build_payload( $urls, 'https://example.com/', 'k1234567', 'https://example.com/k1234567.txt' );
		$this->assertCount( 10000, $built['payload']['urlList'] );
	}

	public function test_key_validation() {
		$this->assertTrue( IndexNow::valid_key( '0c1caaefd7b84aea82b1f4b62b414bcf' ) );
		$this->assertTrue( IndexNow::valid_key( 'Abc-1234' ) );
		$this->assertFalse( IndexNow::valid_key( 'short' ) );
		$this->assertFalse( IndexNow::valid_key( str_repeat( 'a', 129 ) ) );
		$this->assertFalse( IndexNow::valid_key( 'bad key!' ) );
		$this->assertFalse( IndexNow::valid_key( '../../etc/passwd' ) );
	}

	public function test_key_file_path_matching() {
		$key = '0c1caaefd7b84aea82b1f4b62b414bcf';
		$this->assertTrue( IndexNow::matches_key_path( '/' . $key . '.txt', '/', $key ) );
		$this->assertTrue( IndexNow::matches_key_path( '/' . $key . '.txt?ver=1', '/', $key ) );
		$this->assertFalse( IndexNow::matches_key_path( '/other.txt', '/', $key ) );
		$this->assertFalse( IndexNow::matches_key_path( '/' . $key . '.txt/', '/', $key ) );
		$this->assertFalse( IndexNow::matches_key_path( '/blog/' . $key . '.txt', '/', $key ) );
		// Subdirectory install: the key file lives under the home path.
		$this->assertTrue( IndexNow::matches_key_path( '/blog/' . $key . '.txt', '/blog/', $key ) );
		$this->assertTrue( IndexNow::matches_key_path( '/blog/' . $key . '.txt', '/blog', $key ) );
		$this->assertFalse( IndexNow::matches_key_path( '/' . $key . '.txt', '/blog/', $key ) );
	}

	public function test_status_interpretation() {
		$this->assertTrue( IndexNow::interpret_status( 200 )['ok'] );
		$this->assertTrue( IndexNow::interpret_status( 202 )['ok'] );
		foreach ( array( 400, 403, 422 ) as $status ) {
			$r = IndexNow::interpret_status( $status );
			$this->assertFalse( $r['ok'] );
			$this->assertFalse( $r['retry'], 'permanent ' . $status );
		}
		$this->assertStringContainsString( 'key', IndexNow::interpret_status( 403 )['message'] );
		$this->assertTrue( IndexNow::interpret_status( 429 )['retry'] );
		$this->assertTrue( IndexNow::interpret_status( 503 )['retry'] );
		$this->assertTrue( IndexNow::interpret_status( 0 )['retry'] );
		$this->assertFalse( IndexNow::interpret_status( 404 )['retry'] );
	}
}
