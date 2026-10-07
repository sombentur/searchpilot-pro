<?php
/**
 * Reporting ranges and date windows.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Pure date arithmetic for the performance screens. Every range is a fixed
 * number of days that is a multiple of seven (7, 28, 91, 182, 364), so the
 * previous period has exactly the same length and weekday mix, weekly
 * buckets align perfectly and the year-over-year window (364 days earlier)
 * compares Mondays with Mondays.
 *
 * Search Console data lags: final data is about three days behind, "fresh"
 * (all) data about one day. The end of a window is therefore "today minus
 * the lag" in the site's time zone, or the latest day that actually has
 * data when the caller knows it.
 */
final class Ranges {

	/**
	 * Range key => length in days.
	 */
	const DAYS = array(
		'7d'  => 7,
		'28d' => 28,
		'3m'  => 91,
		'6m'  => 182,
		'12m' => 364,
	);

	/**
	 * Ranges that are charted in weekly buckets.
	 */
	const WEEKLY = array( '6m', '12m' );

	/**
	 * Days between the same weekday one year apart.
	 */
	const YEAR_DAYS = 364;

	/**
	 * Days Search Console keeps (16 months ≈ 486 days).
	 */
	const HISTORY_DAYS = 486;

	/**
	 * All range keys, shortest first.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::DAYS );
	}

	/**
	 * Whether a range key is known.
	 *
	 * @param string $key Range key.
	 * @return bool
	 */
	public static function is_valid( $key ) {
		return is_string( $key ) && isset( self::DAYS[ $key ] );
	}

	/**
	 * Returns a known range key or the default.
	 *
	 * @param mixed  $key     Candidate.
	 * @param string $default Default.
	 * @return string
	 */
	public static function sanitize( $key, $default = '28d' ) {
		$key = is_string( $key ) ? strtolower( trim( $key ) ) : '';
		return self::is_valid( $key ) ? $key : $default;
	}

	/**
	 * Length of a range in days.
	 *
	 * @param string $key Range key.
	 * @return int
	 */
	public static function days( $key ) {
		return self::DAYS[ self::sanitize( $key ) ];
	}

	/**
	 * Chart bucket size for a range.
	 *
	 * @param string $key Range key.
	 * @return string day|week
	 */
	public static function bucket( $key ) {
		return in_array( self::sanitize( $key ), self::WEEKLY, true ) ? 'week' : 'day';
	}

	/**
	 * Search Console reporting lag in days.
	 *
	 * @param bool $fresh Whether fresh (dataState=all) data is used.
	 * @return int
	 */
	public static function lag_days( $fresh ) {
		return $fresh ? 1 : 3;
	}

	/**
	 * Latest complete day to request (today minus the lag).
	 *
	 * @param string $today Today (Y-m-d, site time zone).
	 * @param bool   $fresh Fresh data.
	 * @return string Y-m-d
	 */
	public static function end_date( $today, $fresh ) {
		return self::shift( $today, -self::lag_days( $fresh ) );
	}

	/**
	 * Window of a range ending on a day.
	 *
	 * @param string $key Range key.
	 * @param string $end Last day (Y-m-d).
	 * @return array{from:string,to:string,days:int}
	 */
	public static function window( $key, $end ) {
		$days = self::days( $key );
		return array(
			'from' => self::shift( $end, -( $days - 1 ) ),
			'to'   => self::normalize_date( $end ),
			'days' => $days,
		);
	}

	/**
	 * Window between two dates (inclusive), for custom reports.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array{from:string,to:string,days:int}
	 */
	public static function between( $from, $to ) {
		$from = self::normalize_date( $from );
		$to   = self::normalize_date( $to );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array(
			'from' => $from,
			'to'   => $to,
			'days' => self::diff_days( $from, $to ) + 1,
		);
	}

	/**
	 * The equal-length period immediately before a window.
	 *
	 * @param array $window Window (from, to, days).
	 * @return array{from:string,to:string,days:int}
	 */
	public static function previous( array $window ) {
		$days = (int) $window['days'];
		$to   = self::shift( $window['from'], -1 );
		return array(
			'from' => self::shift( $to, -( $days - 1 ) ),
			'to'   => $to,
			'days' => $days,
		);
	}

	/**
	 * The same window one year (52 weeks) earlier.
	 *
	 * @param array $window Window.
	 * @return array{from:string,to:string,days:int}
	 */
	public static function year_over_year( array $window ) {
		return array(
			'from' => self::shift( $window['from'], -self::YEAR_DAYS ),
			'to'   => self::shift( $window['to'], -self::YEAR_DAYS ),
			'days' => (int) $window['days'],
		);
	}

	/**
	 * Current, previous and year-over-year windows for a range.
	 *
	 * @param string $key Range key.
	 * @param string $end Last day with data (Y-m-d).
	 * @return array{current:array,previous:array,yoy:array}
	 */
	public static function windows( $key, $end ) {
		$current = self::window( $key, $end );
		return array(
			'current'  => $current,
			'previous' => self::previous( $current ),
			'yoy'      => self::year_over_year( $current ),
		);
	}

	/**
	 * Comparison window for a mode.
	 *
	 * @param array  $window  Current window.
	 * @param string $compare previous|yoy|none.
	 * @return array|null
	 */
	public static function comparison( array $window, $compare ) {
		if ( 'yoy' === $compare ) {
			return self::year_over_year( $window );
		}
		if ( 'previous' === $compare ) {
			return self::previous( $window );
		}
		return null;
	}

	/**
	 * How much of a window lies inside the stored history. Search Console
	 * keeps about 16 months and a new property has less, so the previous
	 * period of a 12-month range (and year-over-year windows) are often only
	 * partly covered — comparing against them would show fake growth.
	 *
	 * @param array       $window   Window (from, to, days).
	 * @param string|null $earliest First day with data (null = no data at all).
	 * @param string|null $latest   Last day with data (null = up to the window end).
	 * @return array{days:int,covered_days:int,complete:bool,covered_from:string|null,covered_to:string|null}
	 */
	public static function coverage( array $window, $earliest, $latest = null ) {
		$days = (int) ( $window['days'] ?? ( self::diff_days( $window['from'], $window['to'] ) + 1 ) );
		$out  = array(
			'days'         => $days,
			'covered_days' => 0,
			'complete'     => false,
			'covered_from' => null,
			'covered_to'   => null,
		);
		if ( ! $earliest || ! self::is_date( substr( (string) $earliest, 0, 10 ) ) ) {
			return $out;
		}
		$from = max( (string) $window['from'], substr( (string) $earliest, 0, 10 ) );
		$to   = $latest && self::is_date( substr( (string) $latest, 0, 10 ) ) ? min( (string) $window['to'], substr( (string) $latest, 0, 10 ) ) : (string) $window['to'];
		if ( $from > $to ) {
			return $out;
		}
		$covered             = self::diff_days( $from, $to ) + 1;
		$out['covered_days'] = $covered;
		$out['complete']     = $covered >= $days;
		$out['covered_from'] = $from;
		$out['covered_to']   = $to;
		return $out;
	}

	/**
	 * Every day of a window.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return string[]
	 */
	public static function dates( $from, $to ) {
		$out   = array();
		$start = self::to_time( $from );
		$end   = self::to_time( $to );
		if ( $start > $end ) {
			return $out;
		}
		// Hard cap (two years) so a bad input can never build a huge list.
		for ( $t = $start, $i = 0; $t <= $end && $i < 800; $t += 86400, $i++ ) {
			$out[] = gmdate( 'Y-m-d', $t );
		}
		return $out;
	}

	/**
	 * Splits a window into consecutive buckets of $size days (the last one may be shorter).
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @param int    $size Days per bucket (1 or 7).
	 * @return array<int,array{label:string,from:string,to:string}>
	 */
	public static function buckets( $from, $to, $size ) {
		$size    = max( 1, (int) $size );
		$dates   = self::dates( $from, $to );
		$buckets = array();
		foreach ( array_chunk( $dates, $size ) as $chunk ) {
			$buckets[] = array(
				'label' => $chunk[0],
				'from'  => $chunk[0],
				'to'    => $chunk[ count( $chunk ) - 1 ],
			);
		}
		return $buckets;
	}

	/**
	 * Adds days to a date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days (negative to go back).
	 * @return string Y-m-d
	 */
	public static function shift( $date, $days ) {
		return gmdate( 'Y-m-d', self::to_time( $date ) + (int) $days * 86400 );
	}

	/**
	 * Days from $from to $to (negative when $to is earlier).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public static function diff_days( $from, $to ) {
		return (int) round( ( self::to_time( $to ) - self::to_time( $from ) ) / 86400 );
	}

	/**
	 * Whether a string is a valid Y-m-d date.
	 *
	 * @param mixed $date Candidate.
	 * @return bool
	 */
	public static function is_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Normalizes a date string to Y-m-d (UTC midnight arithmetic).
	 *
	 * @param string $date Date.
	 * @return string
	 */
	public static function normalize_date( $date ) {
		return gmdate( 'Y-m-d', self::to_time( $date ) );
	}

	/**
	 * Last completed Monday–Sunday style week before a day.
	 *
	 * @param string $today      Today (Y-m-d).
	 * @param int    $week_start First day of the week (0 = Sunday … 6 = Saturday, WordPress start_of_week).
	 * @return array{from:string,to:string,days:int}
	 */
	public static function last_full_week( $today, $week_start = 1 ) {
		$t       = self::to_time( $today );
		$weekday = (int) gmdate( 'w', $t );
		$back    = ( $weekday - ( (int) $week_start % 7 ) + 7 ) % 7;
		$this_wk = $t - $back * 86400;
		$from    = gmdate( 'Y-m-d', $this_wk - 7 * 86400 );
		return array(
			'from' => $from,
			'to'   => gmdate( 'Y-m-d', $this_wk - 86400 ),
			'days' => 7,
		);
	}

	/**
	 * The calendar month before the month of a day.
	 *
	 * @param string $today Today (Y-m-d).
	 * @return array{from:string,to:string,days:int}
	 */
	public static function last_full_month( $today ) {
		$t     = self::to_time( $today );
		$first = gmmktime( 0, 0, 0, (int) gmdate( 'n', $t ), 1, (int) gmdate( 'Y', $t ) );
		$to    = gmdate( 'Y-m-d', $first - 86400 );
		$from  = gmdate( 'Y-m-01', $first - 86400 );
		return array(
			'from' => $from,
			'to'   => $to,
			'days' => self::diff_days( $from, $to ) + 1,
		);
	}

	/**
	 * The snapshot range whose length is closest to a number of days.
	 *
	 * @param int $days Days.
	 * @return string Range key.
	 */
	public static function closest( $days ) {
		$best      = '28d';
		$best_diff = PHP_INT_MAX;
		foreach ( self::DAYS as $key => $length ) {
			$diff = abs( $length - (int) $days );
			if ( $diff < $best_diff ) {
				$best      = $key;
				$best_diff = $diff;
			}
		}
		return $best;
	}

	/**
	 * Unix time (UTC midnight) of a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return int
	 */
	private static function to_time( $date ) {
		$date = (string) $date;
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $date, $m ) ) {
			return gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		}
		$t = strtotime( $date . ' UTC' );
		return false === $t ? 0 : (int) ( floor( $t / 86400 ) * 86400 );
	}
}
