<?php
/**
 * Minimal PHPUnit-compatible TestCase used by tests/run.php when PHPUnit is not installed.
 *
 * @package SearchPilot
 */

// phpcs:disable

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

abstract class TestCase {

	private $name;

	public function __construct( $name = '' ) {
		$this->name = $name;
	}

	protected function setUp(): void {}

	protected function tearDown(): void {}

	public static function fail( $message = '' ) {
		throw new AssertionFailedError( $message ?: 'Failed.' );
	}

	private static function check( $condition, $message, $default ) {
		if ( ! $condition ) {
			throw new AssertionFailedError( $message ? $message . ' — ' . $default : $default );
		}
	}

	private static function export( $value ) {
		return is_scalar( $value ) || null === $value ? var_export( $value, true ) : json_encode( $value );
	}

	public static function assertTrue( $v, $message = '' ) {
		self::check( true === $v, $message, 'Expected true, got ' . self::export( $v ) );
	}

	public static function assertFalse( $v, $message = '' ) {
		self::check( false === $v, $message, 'Expected false, got ' . self::export( $v ) );
	}

	public static function assertSame( $expected, $actual, $message = '' ) {
		self::check( $expected === $actual, $message, 'Expected ' . self::export( $expected ) . ', got ' . self::export( $actual ) );
	}

	public static function assertNotSame( $expected, $actual, $message = '' ) {
		self::check( $expected !== $actual, $message, 'Did not expect ' . self::export( $actual ) );
	}

	public static function assertEquals( $expected, $actual, $message = '' ) {
		self::check( $expected == $actual, $message, 'Expected ' . self::export( $expected ) . ', got ' . self::export( $actual ) );
	}

	public static function assertNotEquals( $expected, $actual, $message = '' ) {
		self::check( $expected != $actual, $message, 'Did not expect ' . self::export( $actual ) );
	}

	public static function assertNull( $v, $message = '' ) {
		self::check( null === $v, $message, 'Expected null, got ' . self::export( $v ) );
	}

	public static function assertNotNull( $v, $message = '' ) {
		self::check( null !== $v, $message, 'Expected a value, got null' );
	}

	public static function assertEmpty( $v, $message = '' ) {
		self::check( empty( $v ), $message, 'Expected empty, got ' . self::export( $v ) );
	}

	public static function assertNotEmpty( $v, $message = '' ) {
		self::check( ! empty( $v ), $message, 'Expected non-empty value' );
	}

	public static function assertCount( $count, $v, $message = '' ) {
		self::check( count( $v ) === $count, $message, 'Expected count ' . $count . ', got ' . count( $v ) );
	}

	public static function assertContains( $needle, $haystack, $message = '' ) {
		self::check( in_array( $needle, (array) $haystack, true ), $message, self::export( $needle ) . ' not found in ' . self::export( $haystack ) );
	}

	public static function assertNotContains( $needle, $haystack, $message = '' ) {
		self::check( ! in_array( $needle, (array) $haystack, true ), $message, self::export( $needle ) . ' unexpectedly found' );
	}

	public static function assertArrayHasKey( $key, $array, $message = '' ) {
		self::check( is_array( $array ) && array_key_exists( $key, $array ), $message, 'Missing key ' . self::export( $key ) );
	}

	public static function assertStringContainsString( $needle, $haystack, $message = '' ) {
		self::check( false !== strpos( (string) $haystack, (string) $needle ), $message, self::export( $needle ) . ' not in ' . self::export( $haystack ) );
	}

	public static function assertStringNotContainsString( $needle, $haystack, $message = '' ) {
		self::check( false === strpos( (string) $haystack, (string) $needle ), $message, self::export( $needle ) . ' unexpectedly in ' . self::export( $haystack ) );
	}

	public static function assertMatchesRegularExpression( $pattern, $string, $message = '' ) {
		self::check( 1 === preg_match( $pattern, (string) $string ), $message, self::export( $string ) . ' does not match ' . $pattern );
	}

	public static function assertGreaterThan( $expected, $actual, $message = '' ) {
		self::check( $actual > $expected, $message, self::export( $actual ) . ' is not > ' . self::export( $expected ) );
	}

	public static function assertGreaterThanOrEqual( $expected, $actual, $message = '' ) {
		self::check( $actual >= $expected, $message, self::export( $actual ) . ' is not >= ' . self::export( $expected ) );
	}

	public static function assertLessThan( $expected, $actual, $message = '' ) {
		self::check( $actual < $expected, $message, self::export( $actual ) . ' is not < ' . self::export( $expected ) );
	}

	public static function assertLessThanOrEqual( $expected, $actual, $message = '' ) {
		self::check( $actual <= $expected, $message, self::export( $actual ) . ' is not <= ' . self::export( $expected ) );
	}

	public static function assertInstanceOf( $class, $object, $message = '' ) {
		self::check( $object instanceof $class, $message, 'Not an instance of ' . $class );
	}

	public static function assertIsArray( $v, $message = '' ) {
		self::check( is_array( $v ), $message, 'Expected array' );
	}

	public static function assertIsString( $v, $message = '' ) {
		self::check( is_string( $v ), $message, 'Expected string' );
	}

	public static function assertIsInt( $v, $message = '' ) {
		self::check( is_int( $v ), $message, 'Expected int' );
	}
}
