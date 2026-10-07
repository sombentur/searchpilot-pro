<?php
/**
 * Tests for Core\Crypto and log redaction.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Core\Crypto;
use SearchPilot\Core\Logger;

// phpcs:disable

class CryptoTest extends \PHPUnit\Framework\TestCase {

	public function test_round_trip() {
		$crypto = new Crypto( str_repeat( 'k', 32 ) );
		$cipher = $crypto->encrypt( '{"api_key":"secret-value"}' );
		$this->assertIsString( $cipher );
		$this->assertStringNotContainsString( 'secret-value', $cipher );
		$this->assertSame( '{"api_key":"secret-value"}', $crypto->decrypt( $cipher ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce() {
		$crypto = new Crypto( str_repeat( 'k', 32 ) );
		$this->assertNotSame( $crypto->encrypt( 'same' ), $crypto->encrypt( 'same' ) );
	}

	public function test_wrong_key_fails_cleanly() {
		$cipher = ( new Crypto( str_repeat( 'a', 32 ) ) )->encrypt( 'data' );
		$result = ( new Crypto( str_repeat( 'b', 32 ) ) )->decrypt( $cipher );
		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_tampering_is_detected() {
		$crypto = new Crypto( str_repeat( 'k', 32 ) );
		$cipher = $crypto->encrypt( 'data' );
		$prefix = substr( $cipher, 0, 6 );
		$raw    = base64_decode( substr( $cipher, 6 ) );
		$last   = strlen( $raw ) - 1;
		$raw[ $last ] = chr( ord( $raw[ $last ] ) ^ 1 );
		$this->assertTrue( is_wp_error( $crypto->decrypt( $prefix . base64_encode( $raw ) ) ) );
	}

	public function test_logger_redacts_keys_and_token_strings() {
		$out = Logger::redact(
			array(
				'api_key'       => 'abc',
				'refresh_token' => '1//0gabcdefghijklmnopqrstuvwxyz',
				'nested'        => array( 'Authorization' => 'Bearer xyz' ),
				'url'           => 'https://ssl.bing.com/webmaster/api.svc/json/GetUserSites?apikey=SECRET123&x=1',
				'note'          => 'token ya29.a0AfH6SMBexample in text',
				'count'         => 3,
			)
		);
		$this->assertSame( '[redacted]', $out['api_key'] );
		$this->assertSame( '[redacted]', $out['refresh_token'] );
		$this->assertSame( '[redacted]', $out['nested']['Authorization'] );
		$this->assertStringNotContainsString( 'SECRET123', $out['url'] );
		$this->assertStringNotContainsString( 'ya29.a0AfH6SMBexample', $out['note'] );
		$this->assertSame( 3, $out['count'] );
	}

	public function test_redacts_private_keys_in_text() {
		$text = "x -----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY----- y";
		$this->assertStringNotContainsString( 'MIIEvQ', Logger::redact_string( $text ) );
	}
}
