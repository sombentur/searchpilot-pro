<?php
/**
 * PSR-4 autoloader (no Composer required at runtime).
 *
 * @package SearchPilot
 */

namespace SearchPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Maps SearchPilot\Foo\Bar to src/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Registered prefix => base directory pairs.
	 *
	 * @var array<string,string>
	 */
	private static $prefixes = array();

	/**
	 * Registers a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix with trailing backslash.
	 * @param string $base_dir Directory with trailing slash.
	 */
	public static function register( $prefix, $base_dir ) {
		if ( empty( self::$prefixes ) ) {
			spl_autoload_register( array( __CLASS__, 'load' ) );
		}
		self::$prefixes[ $prefix ] = $base_dir;
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( $class ) {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				continue;
			}
			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}
	}
}
