<?php
/**
 * Minimal RS256 JSON Web Token builder.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Api\Google;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the signed assertion Google's OAuth 2.0 JWT bearer grant expects
 * for service accounts (RFC 7523). Pure: no WordPress calls, no network,
 * so it is unit tested directly. Signing uses openssl_sign() with SHA-256.
 */
final class Jwt {

	/**
	 * Default token lifetime (Google accepts at most one hour).
	 */
	const TTL = 3600;

	/**
	 * Base64url encoding without padding (RFC 4648 §5).
	 *
	 * @param string $data Binary data.
	 * @return string
	 */
	public static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64url decoding.
	 *
	 * @param string $data Encoded data.
	 * @return string|false
	 */
	public static function base64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Whether RS256 signing is possible on this server.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_sign' ) && function_exists( 'openssl_pkey_get_private' ) && defined( 'OPENSSL_ALGO_SHA256' );
	}

	/**
	 * Encodes and signs a JWT with RS256.
	 *
	 * @param array  $claims      Payload claims.
	 * @param string $private_key PEM-encoded RSA private key.
	 * @param array  $header      Extra header fields (e.g. kid).
	 * @return string|\WP_Error Compact serialization header.payload.signature.
	 */
	public static function encode( array $claims, $private_key, array $header = array() ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'searchpilot_jwt_unavailable', __( 'The PHP OpenSSL extension is required to sign service-account requests. Ask your host to enable it, or connect with OAuth instead.', 'searchpilot-pro' ) );
		}
		$header = array_merge(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
			),
			$header
		);
		$json_header = json_encode( $header, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, no WordPress dependency.
		$json_claims = json_encode( $claims, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $json_header || false === $json_claims ) {
			return new \WP_Error( 'searchpilot_jwt_encode', __( 'The token claims could not be encoded.', 'searchpilot-pro' ) );
		}
		$signing_input = self::base64url_encode( $json_header ) . '.' . self::base64url_encode( $json_claims );

		$key = openssl_pkey_get_private( (string) $private_key );
		if ( false === $key ) {
			return new \WP_Error( 'searchpilot_jwt_key', __( 'The service-account private key could not be read. Paste the complete JSON key file downloaded from Google Cloud.', 'searchpilot-pro' ) );
		}
		$signature = '';
		$ok        = openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 );
		if ( ! $ok || '' === $signature ) {
			return new \WP_Error( 'searchpilot_jwt_sign', __( 'Signing the service-account token failed.', 'searchpilot-pro' ) );
		}
		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Claims for a service-account access-token request.
	 *
	 * @param string   $client_email Service account e-mail (issuer).
	 * @param string[] $scopes       OAuth scopes.
	 * @param string   $audience     Token endpoint (token_uri from the key file).
	 * @param int|null $now          Current Unix time (tests).
	 * @param int      $ttl          Lifetime in seconds (max 3600).
	 * @return array
	 */
	public static function service_account_claims( $client_email, array $scopes, $audience, $now = null, $ttl = self::TTL ) {
		$now = null === $now ? time() : (int) $now;
		$ttl = max( 60, min( self::TTL, (int) $ttl ) );
		return array(
			'iss'   => (string) $client_email,
			'scope' => implode( ' ', array_values( array_unique( array_map( 'strval', $scopes ) ) ) ),
			'aud'   => (string) $audience,
			'iat'   => $now,
			'exp'   => $now + $ttl,
		);
	}

	/**
	 * Signed service-account assertion.
	 *
	 * @param string   $client_email Service account e-mail.
	 * @param string   $private_key  PEM private key.
	 * @param string[] $scopes       Scopes.
	 * @param string   $audience     Token endpoint.
	 * @param int|null $now          Current time (tests).
	 * @param string   $key_id       Optional private_key_id (JWT "kid").
	 * @return string|\WP_Error
	 */
	public static function service_account_assertion( $client_email, $private_key, array $scopes, $audience, $now = null, $key_id = '' ) {
		$header = '' !== (string) $key_id ? array( 'kid' => (string) $key_id ) : array();
		return self::encode( self::service_account_claims( $client_email, $scopes, $audience, $now ), $private_key, $header );
	}

	/**
	 * Decodes a JWT without verifying it (diagnostics and tests only).
	 *
	 * @param string $jwt Token.
	 * @return array{header:array,claims:array,signature:string}|null
	 */
	public static function decode_unverified( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$header = json_decode( (string) self::base64url_decode( $parts[0] ), true );
		$claims = json_decode( (string) self::base64url_decode( $parts[1] ), true );
		$sig    = self::base64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $sig ) {
			return null;
		}
		return array(
			'header'    => $header,
			'claims'    => $claims,
			'signature' => $sig,
		);
	}

	/**
	 * Verifies an RS256 signature with a public key (tests and diagnostics).
	 *
	 * @param string $jwt        Token.
	 * @param string $public_key PEM public key.
	 * @return bool
	 */
	public static function verify( $jwt, $public_key ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		$sig = self::base64url_decode( $parts[2] );
		if ( false === $sig ) {
			return false;
		}
		$key = openssl_pkey_get_public( (string) $public_key );
		if ( false === $key ) {
			return false;
		}
		return 1 === openssl_verify( $parts[0] . '.' . $parts[1], $sig, $key, OPENSSL_ALGO_SHA256 );
	}
}
