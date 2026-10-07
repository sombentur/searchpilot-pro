<?php
/**
 * Encrypted credential vault.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores API secrets encrypted in a non-autoloaded option. Secrets are
 * never returned by REST endpoints, rendered into HTML/JS, or logged —
 * use masked() for display. wp-config.php constants take precedence:
 *
 *   SEARCHPILOT_BING_API_KEY
 *   SEARCHPILOT_GOOGLE_CLIENT_ID / SEARCHPILOT_GOOGLE_CLIENT_SECRET
 *   SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT  (JSON string or absolute path to the JSON key file)
 */
class Credentials {

	const OPTION = 'searchpilot_credentials';

	/**
	 * Constant overrides: service => field => constant name.
	 */
	const CONSTANTS = array(
		'bing'      => array( 'api_key' => 'SEARCHPILOT_BING_API_KEY' ),
		'google'    => array(
			'client_id'     => 'SEARCHPILOT_GOOGLE_CLIENT_ID',
			'client_secret' => 'SEARCHPILOT_GOOGLE_CLIENT_SECRET',
		),
		'google_sa' => array( 'json' => 'SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT' ),
	);

	/**
	 * Crypto.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * Decrypted cache.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Last decryption error.
	 *
	 * @var \WP_Error|null
	 */
	private $error = null;

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Crypto.
	 */
	public function __construct( Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * Reads a credential field (constants first).
	 *
	 * @param string $service Service id: google, google_sa, bing.
	 * @param string $field   Field.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $service, $field, $default = null ) {
		$constant = self::CONSTANTS[ $service ][ $field ] ?? '';
		if ( '' !== $constant && defined( $constant ) && '' !== (string) constant( $constant ) ) {
			$value = (string) constant( $constant );
			if ( 'google_sa' === $service && 'json' === $field && '{' !== substr( ltrim( $value ), 0, 1 ) && is_readable( $value ) ) {
				$value = (string) file_get_contents( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local key file path from wp-config.php.
			}
			return $value;
		}
		$all = $this->load();
		return $all[ $service ][ $field ] ?? $default;
	}

	/**
	 * All fields of a service (constants merged).
	 *
	 * @param string $service Service.
	 * @return array
	 */
	public function service( $service ) {
		$all    = $this->load();
		$fields = $all[ $service ] ?? array();
		foreach ( self::CONSTANTS[ $service ] ?? array() as $field => $constant ) {
			if ( defined( $constant ) ) {
				$fields[ $field ] = $this->get( $service, $field );
			}
		}
		return $fields;
	}

	/**
	 * Whether a field is set.
	 *
	 * @param string $service Service.
	 * @param string $field   Field.
	 * @return bool
	 */
	public function has( $service, $field ) {
		$value = $this->get( $service, $field );
		return null !== $value && '' !== $value;
	}

	/**
	 * Whether a field is provided by a wp-config constant.
	 *
	 * @param string $service Service.
	 * @param string $field   Field.
	 * @return bool
	 */
	public function is_constant( $service, $field ) {
		$constant = self::CONSTANTS[ $service ][ $field ] ?? '';
		return '' !== $constant && defined( $constant );
	}

	/**
	 * Merges fields into a service and saves.
	 *
	 * @param string $service Service.
	 * @param array  $fields  Fields (null values delete).
	 * @return true|\WP_Error
	 */
	public function set( $service, array $fields ) {
		$all = $this->load();
		if ( $this->error ) {
			// Never overwrite undecryptable data silently with a partial set.
			$all = array();
		}
		$current = $all[ $service ] ?? array();
		foreach ( $fields as $field => $value ) {
			if ( null === $value ) {
				unset( $current[ $field ] );
			} else {
				$current[ $field ] = $value;
			}
		}
		$all[ $service ] = $current;
		return $this->save( $all );
	}

	/**
	 * Removes a service.
	 *
	 * @param string $service Service.
	 * @return true|\WP_Error
	 */
	public function clear( $service ) {
		$all = $this->load();
		unset( $all[ $service ] );
		return $this->save( $all );
	}

	/**
	 * Removes everything.
	 */
	public function clear_all() {
		delete_option( self::OPTION );
		$this->cache = array();
		$this->error = null;
	}

	/**
	 * Masked representation for UIs: field => "••••1a2b" (never the secret).
	 *
	 * @param string   $service Service.
	 * @param string[] $fields  Fields to include.
	 * @return array<string,array{set:bool,masked:string,constant:bool}>
	 */
	public function masked( $service, array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			$value         = (string) $this->get( $service, $field, '' );
			$out[ $field ] = array(
				'set'      => '' !== $value,
				'masked'   => self::mask( $value ),
				'constant' => $this->is_constant( $service, $field ),
			);
		}
		return $out;
	}

	/**
	 * Masks a secret.
	 *
	 * @param string $value Secret.
	 * @return string
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$len = strlen( $value );
		return $len <= 8 ? str_repeat( '•', 8 ) : str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	/**
	 * Last decryption problem, if any.
	 *
	 * @return \WP_Error|null
	 */
	public function error() {
		$this->load();
		return $this->error;
	}

	/**
	 * Loads and decrypts.
	 *
	 * @return array
	 */
	private function load() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$this->error = null;
		$stored      = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			$this->cache = array();
			return $this->cache;
		}
		$plain = $this->crypto->decrypt( $stored );
		if ( is_wp_error( $plain ) ) {
			$this->error = $plain;
			$this->cache = array();
			return $this->cache;
		}
		$data        = json_decode( $plain, true );
		$this->cache = is_array( $data ) ? $data : array();
		return $this->cache;
	}

	/**
	 * Encrypts and saves.
	 *
	 * @param array $all All services.
	 * @return true|\WP_Error
	 */
	private function save( array $all ) {
		$cipher = $this->crypto->encrypt( (string) wp_json_encode( $all ) );
		if ( is_wp_error( $cipher ) ) {
			return $cipher;
		}
		// Created non-autoloaded, so secrets are not loaded on every request.
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $cipher, '', false );
		} else {
			update_option( self::OPTION, $cipher, false );
		}
		$this->cache = $all;
		$this->error = null;
		return true;
	}
}
