<?php
/**
 * Tests for Api\Google\Jwt (RS256 service-account assertions) and PKCE helpers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Api\Google\Jwt;
use SearchPilot\Api\Google\OAuthClient;

// phpcs:disable

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz0123456789', 5 ), 0, $length );
	}
}

class JwtTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Generated key pair (per run) or null when OpenSSL cannot generate keys.
	 *
	 * @var array|null
	 */
	private static $pair = false;

	private static function pair() {
		if ( false !== self::$pair ) {
			return self::$pair;
		}
		self::$pair = null;
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		// Some runtimes (e.g. php-wasm) ship without openssl.cnf; a minimal one is enough.
		$cnf = sys_get_temp_dir() . '/spp-test-openssl-' . getmypid() . '.cnf';
		@file_put_contents( $cnf, "[ req ]
distinguished_name = req_dn
[ req_dn ]
" );
		$opts = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);
		if ( is_readable( $cnf ) ) {
			$opts['config'] = $cnf;
		}
		$res = @openssl_pkey_new( $opts );
		if ( ! $res ) {
			@unlink( $cnf );
			return null;
		}
		$private = '';
		@openssl_pkey_export( $res, $private, null, isset( $opts['config'] ) ? array( 'config' => $cnf ) : array() );
		@unlink( $cnf );
		while ( openssl_error_string() ) {
			// Drain OpenSSL's error queue (missing default config noise).
		}
		if ( '' === $private ) {
			return null;
		}
		$details    = openssl_pkey_get_details( $res );
		self::$pair = array(
			'private' => $private,
			'public'  => $details['key'],
		);
		return self::$pair;
	}

	public function test_base64url_round_trip_without_padding() {
		foreach ( array( '', 'f', 'fo', 'foo', "\xff\xfe\xfd\x00binary", str_repeat( 'x', 97 ) ) as $value ) {
			$encoded = Jwt::base64url_encode( $value );
			$this->assertFalse( strpos( $encoded, '=' ), 'no padding' );
			$this->assertFalse( strpos( $encoded, '+' ), 'url safe +' );
			$this->assertFalse( strpos( $encoded, '/' ), 'url safe /' );
			$this->assertSame( $value, Jwt::base64url_decode( $encoded ) );
		}
		$this->assertSame( 'Pz8_', Jwt::base64url_encode( '???' ) );
	}

	public function test_service_account_claims() {
		$claims = Jwt::service_account_claims(
			'bot@project.iam.gserviceaccount.com',
			array( 'https://www.googleapis.com/auth/webmasters', 'https://www.googleapis.com/auth/indexing', 'https://www.googleapis.com/auth/webmasters' ),
			'https://oauth2.googleapis.com/token',
			1700000000
		);
		$this->assertSame( 'bot@project.iam.gserviceaccount.com', $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/indexing', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertSame( 1700000000, $claims['iat'] );
		$this->assertSame( 1700003600, $claims['exp'] );
	}

	public function test_lifetime_is_capped_at_one_hour() {
		$claims = Jwt::service_account_claims( 'a@b.iam.gserviceaccount.com', array( 's' ), 'aud', 100, 99999 );
		$this->assertSame( 3700, $claims['exp'] );
	}

	public function test_signed_assertion_verifies_with_public_key() {
		$pair = self::pair();
		if ( null === $pair ) {
			$this->assertTrue( true );
			return; // OpenSSL key generation unavailable in this runtime.
		}
		$jwt = Jwt::service_account_assertion( 'bot@project.iam.gserviceaccount.com', $pair['private'], array( 'https://www.googleapis.com/auth/webmasters' ), 'https://oauth2.googleapis.com/token', 1700000000, 'abc123' );
		$this->assertIsString( $jwt );
		$this->assertCount( 3, explode( '.', $jwt ) );
		$this->assertTrue( Jwt::verify( $jwt, $pair['public'] ) );

		$decoded = Jwt::decode_unverified( $jwt );
		$this->assertSame( 'RS256', $decoded['header']['alg'] );
		$this->assertSame( 'JWT', $decoded['header']['typ'] );
		$this->assertSame( 'abc123', $decoded['header']['kid'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $decoded['claims']['aud'] );
		$this->assertSame( 1700003600, $decoded['claims']['exp'] );
		$this->assertSame( 256, strlen( $decoded['signature'] ), '2048-bit RSA signature' );
	}

	public function test_tampered_token_fails_verification() {
		$pair = self::pair();
		if ( null === $pair ) {
			$this->assertTrue( true );
			return;
		}
		$jwt   = Jwt::service_account_assertion( 'bot@project.iam.gserviceaccount.com', $pair['private'], array( 's' ), 'aud', 1700000000 );
		$parts = explode( '.', $jwt );
		$parts[1] = Jwt::base64url_encode( '{"iss":"attacker@example.com","aud":"aud","iat":1,"exp":2,"scope":"s"}' );
		$this->assertFalse( Jwt::verify( implode( '.', $parts ), $pair['public'] ) );
	}

	public function test_invalid_private_key_returns_error() {
		$result = Jwt::encode( array( 'iss' => 'x' ), "-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----\n" );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'searchpilot_jwt_key', $result->get_error_code() );
	}

	public function test_decode_rejects_malformed_tokens() {
		$this->assertNull( Jwt::decode_unverified( 'abc' ) );
		$this->assertNull( Jwt::decode_unverified( 'a.b' ) );
		$this->assertNull( Jwt::decode_unverified( Jwt::base64url_encode( 'nope' ) . '.' . Jwt::base64url_encode( '{}' ) . '.sig' ) );
	}

	public function test_pkce_s256_challenge() {
		// Expected value computed independently (Node.js crypto: sha256 → base64url).
		$this->assertSame( 'CuYeBxgMHIaud_elY4XgF_yt03_3FEY_KoHG8_ezZBE', OAuthClient::pkce_challenge( 'SearchPilot-PKCE-test-verifier-0123456789-abcdefghij' ) );
		$verifier = OAuthClient::pkce_verifier();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-_]{43,128}$/', $verifier );
	}

	public function test_auth_url_contains_required_parameters() {
		$url = OAuthClient::build_auth_url(
			array(
				'client_id'             => '123456-abc.apps.googleusercontent.com',
				'redirect_uri'          => 'https://example.com/wp-admin/admin-post.php?action=searchpilot_google_oauth',
				'response_type'         => 'code',
				'scope'                 => 'https://www.googleapis.com/auth/webmasters',
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'state'                 => 'abc',
				'code_challenge_method' => 'S256',
			)
		);
		$this->assertStringContainsString( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		$this->assertStringContainsString( 'redirect_uri=https%3A%2F%2Fexample.com%2Fwp-admin%2Fadmin-post.php%3Faction%3Dsearchpilot_google_oauth', $url );
		$this->assertStringContainsString( 'scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fwebmasters', $url );
		$this->assertStringContainsString( 'access_type=offline', $url );
		$this->assertStringContainsString( 'code_challenge_method=S256', $url );
	}

	public function test_client_id_format() {
		$this->assertTrue( OAuthClient::valid_client_id( '123456789012-abcdefghijklmnopqrstuvwxyz012345.apps.googleusercontent.com' ) );
		$this->assertFalse( OAuthClient::valid_client_id( 'my-client' ) );
		$this->assertFalse( OAuthClient::valid_client_id( '123456789012-abc.apps.googleusercontent.com.evil.com' ) );
	}

	public function test_token_response_parsing() {
		$ok = OAuthClient::parse_token_response(
			200,
			array(
				'access_token'  => 'ya29.token',
				'expires_in'    => 3599,
				'refresh_token' => '1//refresh',
				'scope'         => 'https://www.googleapis.com/auth/webmasters',
				'token_type'    => 'Bearer',
			),
			'',
			1000
		);
		$this->assertSame( 'ya29.token', $ok['access_token'] );
		$this->assertSame( '1//refresh', $ok['refresh_token'] );
		$this->assertSame( 1000 + 3599 - 60, $ok['expires_at'] );

		$grant = OAuthClient::parse_token_response( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
		$this->assertSame( 'invalid_grant', $grant->get_error_code() );

		$client = OAuthClient::parse_token_response( 401, array( 'error' => 'invalid_client' ) );
		$this->assertSame( 'invalid_client', $client->get_error_code() );

		$down = OAuthClient::parse_token_response( 0, null, 'cURL error 28' );
		$this->assertSame( 'searchpilot_google_unreachable', $down->get_error_code() );
		$this->assertTrue( $down->get_error_data()['retryable'] );
	}
}
