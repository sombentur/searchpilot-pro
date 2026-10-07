<?php
/**
 * Tests for the job names accepted by POST /system/run.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Rest\SystemController;

/**
 * SystemController::job_hook() tests.
 */
class SystemJobTest extends \PHPUnit\Framework\TestCase {

	public function test_short_and_full_names() {
		$this->assertSame( 'searchpilot_daily', SystemController::job_hook( 'daily' ) );
		$this->assertSame( 'searchpilot_daily', SystemController::job_hook( 'searchpilot_daily' ) );
		$this->assertSame( 'searchpilot_every_5min', SystemController::job_hook( 'every_5min' ) );
		$this->assertSame( 'searchpilot_every_5min', SystemController::job_hook( 'searchpilot_every_5min' ) );
		$this->assertSame( 'searchpilot_weekly', SystemController::job_hook( ' Weekly ' ) );
		$this->assertSame( 'searchpilot_hourly', SystemController::job_hook( 'SEARCHPILOT_HOURLY' ) );
	}

	public function test_unknown_names() {
		foreach ( array( '', 'monthly', 'searchpilot_monthly', 'searchpilot_', 'searchpilot_searchpilot_daily', 'daily; drop', 'wp_version_check', null, array( 'daily' ) ) as $name ) {
			$this->assertSame( '', SystemController::job_hook( $name ), wp_json_encode_safe( $name ) );
		}
	}

	public function test_accepted_values_list_both_forms() {
		$accepted = SystemController::accepted_jobs();
		foreach ( array( 'every_5min', 'hourly', 'daily', 'weekly', 'searchpilot_every_5min', 'searchpilot_hourly', 'searchpilot_daily', 'searchpilot_weekly' ) as $name ) {
			$this->assertContains( $name, $accepted );
			$this->assertNotSame( '', SystemController::job_hook( $name ) );
		}
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wp_json_encode_safe' ) ) {
	/**
	 * JSON for assertion messages.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode_safe( $value ) {
		return (string) json_encode( $value );
	}
}
