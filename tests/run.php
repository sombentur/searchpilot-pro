<?php
/**
 * Dependency-free test runner for environments without PHPUnit.
 *
 * Usage: php tests/run.php [filter]
 * With Composer: vendor/bin/phpunit (same test files).
 *
 * @package SearchPilot
 */

// phpcs:disable

if ( 'cli' !== PHP_SAPI ) {
	exit; // Tests never run over HTTP.
}

require __DIR__ . '/bootstrap.php';
if ( ! class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	require __DIR__ . '/lib/MiniUnit.php';
}

$filter = $argv[1] ?? '';
$files  = glob( __DIR__ . '/unit/*Test.php' );
$passed = 0;
$failed = 0;
$errors = array();

foreach ( $files as $file ) {
	require_once $file;
	$class = '\\SearchPilot\\Tests\\' . basename( $file, '.php' );
	if ( ! class_exists( $class ) ) {
		continue;
	}
	foreach ( get_class_methods( $class ) as $method ) {
		if ( 0 !== strpos( $method, 'test' ) || ( '' !== $filter && false === stripos( $class . '::' . $method, $filter ) ) ) {
			continue;
		}
		$test = new $class( $method );
		try {
			$call = static function ( $object, $name ) {
				if ( method_exists( $object, $name ) ) {
					$ref = new \ReflectionMethod( $object, $name );
					if ( PHP_VERSION_ID < 80100 ) {
						$ref->setAccessible( true ); // Needed before PHP 8.1, deprecated in 8.5.
					}
					$ref->invoke( $object );
				}
			};
			$call( $test, 'setUp' );
			$test->$method();
			$call( $test, 'tearDown' );
			++$passed;
			echo '.';
		} catch ( \Throwable $e ) {
			++$failed;
			echo 'F';
			$errors[] = sprintf( "%s::%s\n  %s: %s (%s:%d)", ltrim( $class, '\\' ), $method, get_class( $e ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
		}
	}
}

echo "\n\n";
foreach ( $errors as $error ) {
	echo $error, "\n\n";
}
printf( "%d passed, %d failed (PHP %s)\n", $passed, $failed, PHP_VERSION );
exit( $failed ? 1 : 0 );
