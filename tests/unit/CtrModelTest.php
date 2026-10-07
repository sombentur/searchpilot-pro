<?php
/**
 * Tests for Analytics\CtrModel.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Analytics\CtrModel;

/**
 * Expected CTR curve: fallback, interpolation, learning from site data.
 */
class CtrModelTest extends \PHPUnit\Framework\TestCase {

	private static function near( $expected, $actual, $delta = 0.0005 ) {
		return abs( $expected - $actual ) <= $delta;
	}

	public function test_fallback_curve() {
		$m = new CtrModel();
		$this->assertTrue( self::near( 0.28, $m->expected( 1 ) ) );
		$this->assertTrue( self::near( 0.15, $m->expected( 2 ) ) );
		$this->assertTrue( self::near( 0.025, $m->expected( 10 ) ) );
		$this->assertTrue( self::near( 0.01, $m->expected( 15 ) ) );
		$this->assertTrue( self::near( 0.004, $m->expected( 25 ) ) );
		$this->assertTrue( self::near( 0.28, $m->expected( 0.4 ) ), 'Positions below 1 are treated as 1' );
	}

	public function test_interpolation_between_positions() {
		$m = new CtrModel();
		$this->assertTrue( self::near( 0.215, $m->expected( 1.5 ) ) );
		$this->assertTrue( self::near( 0.0175, $m->expected( 10.5 ) ) );
		$this->assertGreaterThan( $m->expected( 3.2 ), $m->expected( 3.1 ) );
	}

	public function test_buckets() {
		$this->assertSame( 1, CtrModel::bucket( 1.2 ) );
		$this->assertSame( 2, CtrModel::bucket( 1.6 ) );
		$this->assertSame( 10, CtrModel::bucket( 10.4 ) );
		$this->assertSame( 11, CtrModel::bucket( 10.6 ) );
		$this->assertSame( 11, CtrModel::bucket( 20.4 ) );
		$this->assertSame( 21, CtrModel::bucket( 35 ) );
	}

	public function test_learns_site_median_per_bucket() {
		$rows = array();
		// Ten position-1 queries at ~12% CTR (a news site with top stories above results).
		foreach ( array( 0.10, 0.11, 0.12, 0.12, 0.13, 0.12, 0.14, 0.11, 0.12, 0.13 ) as $ctr ) {
			$rows[] = array(
				'position'    => 1.1,
				'impressions' => 1000,
				'clicks'      => (int) round( $ctr * 1000 ),
			);
		}
		// Too few samples at position 2 → fallback stays but is clamped below position 1.
		$rows[] = array(
			'position'    => 2,
			'impressions' => 1000,
			'clicks'      => 400,
		);
		// Ignored: too few impressions.
		$rows[] = array(
			'position'    => 1,
			'impressions' => 5,
			'clicks'      => 5,
		);
		$m = CtrModel::from_rows( $rows, 8, 20 );
		$this->assertTrue( self::near( 0.12, $m->expected( 1 ) ) );
		$this->assertTrue( $m->is_learned( 1 ) );
		$this->assertFalse( $m->is_learned( 2 ) );
		$this->assertLessThanOrEqual( $m->expected( 1 ), $m->expected( 2 ), 'A worse position never expects a higher CTR' );
		$curve = $m->to_array();
		$this->assertSame( 'site', $curve[0]['source'] );
		$this->assertSame( 10, $curve[0]['samples'] );
		$this->assertSame( 'typical', $curve[1]['source'] );
	}

	public function test_monotonic_clamp_raises_typical_bucket_below_a_higher_learned_one() {
		$full = CtrModel::monotonic( array( 5 => 0.15 ) );
		$this->assertGreaterThanOrEqual( 0.15, $full[4] );
		$this->assertLessThanOrEqual( 0.15, $full[6] );
		$this->assertTrue( self::near( 0.28, $full[1] ) );
	}

	public function test_ratio_and_missed_clicks() {
		$m = new CtrModel();
		$this->assertTrue( self::near( 0.5, $m->ratio( 0.14, 1 ), 0.001 ) );
		$this->assertSame( 180, $m->missed_clicks( 100, 1000, 1 ) );
		$this->assertSame( 0, $m->missed_clicks( 500, 1000, 1 ) );
	}

	public function test_median() {
		$this->assertSame( 2.0, CtrModel::median( array( 3, 1, 2 ) ) );
		$this->assertSame( 2.5, CtrModel::median( array( 4, 1, 2, 3 ) ) );
		$this->assertSame( 0.0, CtrModel::median( array() ) );
	}
}
