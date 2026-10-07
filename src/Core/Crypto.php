<?php
/**
 * Authenticated encryption for stored credentials.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts with libsodium secretbox (XSalsa20-Poly1305) when the extension
 * is available, otherwise AES-256-GCM through OpenSSL. The key comes from
 * the SEARCHPILOT_ENCRYPTION_KEY constant when defined, else it is derived
 * from the site's secure-auth salt (so a leaked database alone cannot
 * decrypt credentials).
 */
class Crypto {

	const PREFIX_SODIUM  = 'spv1s:';
	const PREFIX_OPENSSL = 'spv1o:';

	/**
	 * Optional explicit key (tests).
	 *
	 * @var string|null
	 */
	private $key_override;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_override 32-byte binary key (tests only).
	 */
	public function __construct( $key_override = null ) {
		$this->key_override = $key_override;
	}

	/**
	 * Whether encryption is available on this server.
	 *
	 * @return bool
	 */
	public static function available() {
		return self::sodium_native() || self::openssl_gcm() || function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Encrypts a string.
	 *
	 * @param string $plaintext Plain text.
	 * @return string|\WP_Error Prefixed base64 ciphertext.
	 */
	public function encrypt( $plaintext ) {
		$key = $this->key();
		if ( self::sodium_native() || ( ! self::openssl_gcm() && function_exists( 'sodium_crypto_secretbox' ) ) ) {
			try {
				$nonce  = random_bytes( 24 );
				$cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );
				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			} catch ( \Throwable $e ) {
				return new \WP_Error( 'searchpilot_crypto', $e->getMessage() );
			}
		}
		if ( self::openssl_gcm() ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $cipher ) {
				return new \WP_Error( 'searchpilot_crypto', 'OpenSSL encryption failed.' );
			}
			return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return new \WP_Error( 'searchpilot_crypto', __( 'This server has neither libsodium nor OpenSSL, so credentials cannot be stored securely.', 'searchpilot-pro' ) );
	}

	/**
	 * Decrypts a string produced by encrypt().
	 *
	 * @param string $payload Prefixed ciphertext.
	 * @return string|\WP_Error
	 */
	public function decrypt( $payload ) {
		$payload = (string) $payload;
		$key     = $this->key();
		if ( 0 === strpos( $payload, self::PREFIX_SODIUM ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 40 || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return new \WP_Error( 'searchpilot_crypto', 'Malformed ciphertext.' );
			}
			try {
				$plain = sodium_crypto_secretbox_open( substr( $raw, 24 ), substr( $raw, 0, 24 ), $key );
			} catch ( \Throwable $e ) {
				$plain = false;
			}
			return false === $plain ? new \WP_Error( 'searchpilot_crypto_key', __( 'Stored credentials could not be decrypted (the site salts or encryption key changed). Reconnect the service.', 'searchpilot-pro' ) ) : $plain;
		}
		if ( 0 === strpos( $payload, self::PREFIX_OPENSSL ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 29 || ! self::openssl_gcm() ) {
				return new \WP_Error( 'searchpilot_crypto', 'Malformed ciphertext.' );
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? new \WP_Error( 'searchpilot_crypto_key', __( 'Stored credentials could not be decrypted (the site salts or encryption key changed). Reconnect the service.', 'searchpilot-pro' ) ) : $plain;
		}
		return new \WP_Error( 'searchpilot_crypto', 'Unknown ciphertext format.' );
	}

	/**
	 * 32-byte key.
	 *
	 * @return string
	 */
	private function key() {
		if ( null !== $this->key_override ) {
			return $this->key_override;
		}
		if ( defined( 'SEARCHPILOT_ENCRYPTION_KEY' ) && '' !== (string) SEARCHPILOT_ENCRYPTION_KEY ) {
			return hash( 'sha256', 'searchpilot|' . SEARCHPILOT_ENCRYPTION_KEY, true );
		}
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : '';
		if ( '' === $salt && defined( 'SECURE_AUTH_KEY' ) ) {
			$salt = SECURE_AUTH_KEY;
		}
		return hash_hmac( 'sha256', 'searchpilot-credentials-v1', $salt, true );
	}

	/**
	 * Native sodium extension present.
	 *
	 * @return bool
	 */
	private static function sodium_native() {
		return extension_loaded( 'sodium' ) && function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * OpenSSL with AES-256-GCM present.
	 *
	 * @return bool
	 */
	private static function openssl_gcm() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}
}
