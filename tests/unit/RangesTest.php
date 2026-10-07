<?php
/**
 * Tests for Analytics\Ranges.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Analytics\Ranges;

/**
 * Range keys → date windows, previous period, year over year, lag.
 */
class RangesTest extends \PHPUnit\Framework\TestCase {

	public function test_keys_and_sanitize() {
		$this->assertSame( array( '7d', '28d', '3m', '6m', '12m' ), Ranges::keys() );
		$this->assertSame( '3m', Ranges::sanitize( '3M' ) );
		$this->assertSame( '28d', Ranges::sanitize( 'bogus' ) );
		$this->assertSame( '7d', Ranges::sanitize( array( 'x' ), '7d' ) );
		$this->assertTrue( Ranges::is_valid( '12m' ) );
		$this->assertFalse( Ranges::is_valid( '1y' ) );
	}

	public function test_lengths_are_whole_weeks() {
		foreach ( Ranges::keys() as $key ) {
			$this->assertSame( 0, Ranges::days( $key ) % 7, $key );
		}
		$this->assertSame( 91, Ranges::days( '3m' ) );
		$this->assertSame( 364, Ranges::days( '12m' ) );
	}

	public function test_end_date_uses_search_console_lag() {
		$this->assertSame( '2026-09-27', Ranges::end_date( '2026-09-30', false ) );
		$this->assertSame( '2026-09-29', Ranges::end_date( '2026-09-30', true ) );
		$this->assertSame( '2026-02-26', Ranges::end_date( '2026-03-01', false ) );
		$this->assertSame( 3, Ranges::lag_days( false ) );
		$this->assertSame( 1, Ranges::lag_days( true ) );
	}

	public function test_window_previous_and_yoy() {
		$w = Ranges::window( '28d', '2026-09-27' );
		$this->assertSame( '2026-08-31', $w['from'] );
		$this->assertSame( '2026-09-27', $w['to'] );
		$this->assertSame( 28, $w['days'] );
		$this->assertCount( 28, Ranges::dates( $w['from'], $w['to'] ) );

		$p = Ranges::previous( $w );
		$this->assertSame( '2026-08-03', $p['from'] );
		$this->assertSame( '2026-08-30', $p['to'] );
		$this->assertCount( 28, Ranges::dates( $p['from'], $p['to'] ) );

		$y = Ranges::year_over_year( $w );
		$this->assertSame( '2025-09-01', $y['from'] );
		$this->assertSame( '2025-09-28', $y['to'] );
		// Same weekday one year earlier.
		$this->assertSame( gmdate( 'N', strtotime( $w['from'] ) ), gmdate( 'N', strtotime( $y['from'] ) ) );

		$all = Ranges::windows( '7d', '2026-09-27' );
		$this->assertSame( '2026-09-21', $all['current']['from'] );
		$this->assertSame( '2026-09-14', $all['previous']['from'] );
		$this->assertSame( '2026-09-20', $all['previous']['to'] );
		$this->assertNull( Ranges::comparison( $w, 'none' ) );
		$this->assertSame( $p, Ranges::comparison( $w, 'previous' ) );
	}

	public function test_window_across_year_and_leap_day() {
		$w = Ranges::window( '7d', '2028-03-02' );
		$this->assertSame( '2028-02-25', $w['from'] );
		$this->assertContains( '2028-02-29', Ranges::dates( $w['from'], $w['to'] ) );
		$w = Ranges::window( '28d', '2026-01-10' );
		$this->assertSame( '2025-12-14', $w['from'] );
	}

	public function test_buckets() {
		$w       = Ranges::window( '12m', '2026-09-27' );
		$buckets = Ranges::buckets( $w['from'], $w['to'], 7 );
		$this->assertCount( 52, $buckets );
		$this->assertSame( $w['from'], $buckets[0]['from'] );
		$this->assertSame( $w['to'], $buckets[51]['to'] );
		$this->assertSame( 'week', Ranges::bucket( '6m' ) );
		$this->assertSame( 'day', Ranges::bucket( '3m' ) );
		$daily = Ranges::buckets( '2026-09-01', '2026-09-03', 1 );
		$this->assertCount( 3, $daily );
		$this->assertSame( '2026-09-02', $daily[1]['label'] );
		$this->assertSame( array(), Ranges::dates( '2026-09-05', '2026-09-01' ) );
	}

	public function test_report_periods() {
		// Wednesday 30 Sep 2026, weeks start on Monday.
		$week = Ranges::last_full_week( '2026-09-30', 1 );
		$this->assertSame( '2026-09-21', $week['from'] );
		$this->assertSame( '2026-09-27', $week['to'] );
		// Weeks starting Sunday.
		$week = Ranges::last_full_week( '2026-09-30', 0 );
		$this->assertSame( '2026-09-20', $week['from'] );
		$this->assertSame( '2026-09-26', $week['to'] );
		// On a Monday the previous week is complete.
		$week = Ranges::last_full_week( '2026-09-28', 1 );
		$this->assertSame( '2026-09-21', $week['from'] );

		$month = Ranges::last_full_month( '2026-09-30' );
		$this->assertSame( '2026-08-01', $month['from'] );
		$this->assertSame( '2026-08-31', $month['to'] );
		$this->assertSame( 31, $month['days'] );
		$feb = Ranges::last_full_month( '2028-03-05' );
		$this->assertSame( '2028-02-29', $feb['to'] );
		$this->assertSame( 29, $feb['days'] );
		$jan = Ranges::last_full_month( '2027-01-15' );
		$this->assertSame( '2026-12-01', $jan['from'] );
	}

	public function test_coverage_against_stored_history() {
		// 16 months of history ending 2026-09-27 starts 2025-05-30.
		$end     = '2026-09-27';
		$first   = Ranges::shift( $end, -( Ranges::HISTORY_DAYS - 1 ) );
		$windows = Ranges::windows( '12m', $end );
		$current = Ranges::coverage( $windows['current'], $first, $end );
		$this->assertTrue( $current['complete'] );
		$this->assertSame( 364, $current['covered_days'] );
		$prev = Ranges::coverage( $windows['previous'], $first, $end );
		$this->assertFalse( $prev['complete'], 'The 12-month previous period is older than Search Console keeps' );
		$this->assertSame( 122, $prev['covered_days'] );
		$this->assertSame( $first, $prev['covered_from'] );
		$this->assertFalse( Ranges::coverage( $windows['yoy'], $first, $end )['complete'] );
		// 28 days: previous and year-over-year are both inside 16 months.
		$w28 = Ranges::windows( '28d', $end );
		$this->assertTrue( Ranges::coverage( $w28['previous'], $first, $end )['complete'] );
		$this->assertTrue( Ranges::coverage( $w28['yoy'], $first, $end )['complete'] );
		// A property connected 40 days ago: 28-day comparison is partial, 7-day is fine.
		$new = Ranges::shift( $end, -39 );
		$p28 = Ranges::coverage( $w28['previous'], $new, $end );
		$this->assertFalse( $p28['complete'] );
		$this->assertSame( 12, $p28['covered_days'] );
		$this->assertTrue( Ranges::coverage( Ranges::windows( '7d', $end )['previous'], $new, $end )['complete'] );
		// No data at all.
		$none = Ranges::coverage( $w28['previous'], null );
		$this->assertSame( 0, $none['covered_days'] );
		$this->assertNull( $none['covered_from'] );
		// Window entirely before the history.
		$this->assertSame( 0, Ranges::coverage( $windows['previous'], '2026-01-01', $end )['covered_days'] );
	}

	public function test_between_closest_and_validation() {
		$b = Ranges::between( '2026-09-10', '2026-09-01' );
		$this->assertSame( '2026-09-01', $b['from'] );
		$this->assertSame( 10, $b['days'] );
		$this->assertSame( '7d', Ranges::closest( 7 ) );
		$this->assertSame( '28d', Ranges::closest( 31 ) );
		$this->assertSame( '3m', Ranges::closest( 95 ) );
		$this->assertSame( '12m', Ranges::closest( 400 ) );
		$this->assertTrue( Ranges::is_date( '2026-02-28' ) );
		$this->assertFalse( Ranges::is_date( '2026-02-30' ) );
		$this->assertFalse( Ranges::is_date( '2026-9-1' ) );
		$this->assertSame( 27, Ranges::diff_days( '2026-08-31', '2026-09-27' ) );
		$this->assertSame( '2026-03-01', Ranges::shift( '2026-02-28', 1 ) );
	}
}
