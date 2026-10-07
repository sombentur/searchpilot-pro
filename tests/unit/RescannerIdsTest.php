<?php
/**
 * Tests for the re-scanner's id handling.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Audit\Rescanner;

/**
 * Rescanner::clean_ids() tests.
 */
class RescannerIdsTest extends \PHPUnit\Framework\TestCase {

	public function test_keeps_positive_unique_ids_in_order() {
		$this->assertSame( array( 12, 5, 7 ), Rescanner::clean_ids( array( 12, 5, '7', 12, '5' ) ) );
	}

	public function test_rejects_negative_zero_and_junk() {
		$this->assertSame( array( 9 ), Rescanner::clean_ids( array( -3, '-3', 0, '0', '', null, true, 'abc', '4.5', array( 1 ), 9 ) ) );
	}

	public function test_empty() {
		$this->assertSame( array(), Rescanner::clean_ids( array() ) );
	}
}
