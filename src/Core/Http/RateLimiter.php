<?php
/**
 * Per-service request budgets and back-off.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Core\Http;

use SearchPilot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks per-minute and per-day request counts for each external API and
 * honours back-off windows after 429/quota responses. Counters are
 * approximate across concurrent PHP processes, so budgets are kept below
 * the providers' published quotas.
 *
 * Daily windows use US Pacific time because Google resets its API quotas
 * at midnight Pacific.
 */
class RateLimiter {

	const OPTION = 'searchpilot_quota';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Budgets per key: per_minute / per_day (0 = unlimited).
	 *
	 * @return array<string,array{per_minute:int,per_day:int}>
	 */
	public function limits() {
		$limits = array(
			'google_inspection' => array(
				'per_minute' => 500,
				'per_day'    => min( 2000, (int) $this->settings->get( 'indexing.inspection_daily_budget', 400 ) ),
			),
			'google_indexing'   => array(
				'per_minute' => 60,
				'per_day'    => 190,
			),
			'google_analytics'  => array(
				'per_minute' => 100,
				'per_day'    => 5000,
			),
			'google_sitemaps'   => array(
				'per_minute' => 10,
				'per_day'    => 100,
			),
			'google_oauth'      => array(
				'per_minute' => 20,
				'per_day'    => 1000,
			),
			'bing_api'          => array(
				'per_minute' => 30,
				'per_day'    => 5000,
			),
			'bing_url'          => array(
				'per_minute' => 30,
				'per_day'    => (int) get_option( 'searchpilot_bing_daily_quota', 10 ),
			),
			'indexnow'          => array(
				'per_minute' => 10,
				'per_day'    => 2000,
			),
			'self_audit'        => array(
				'per_minute' => 60,
				'per_day'    => 20000,
			),
		);
		/**
		 * Filters API request budgets.
		 *
		 * @param array $limits Limits.
		 */
		return (array) apply_filters( 'searchpilot_rate_limits', $limits );
	}

	/**
	 * Consumes budget if available.
	 *
	 * @param string $key  Budget key.
	 * @param int    $cost Units.
	 * @return bool True when the request may proceed.
	 */
	public function attempt( $key, $cost = 1 ) {
		if ( $this->blocked_until( $key ) > time() ) {
			return false;
		}
		$limits = $this->limits()[ $key ] ?? array(
			'per_minute' => 0,
			'per_day'    => 0,
		);
		$data   = $this->load();
		$minute = gmdate( 'YmdHi' );
		$day    = $this->day();
		$entry  = $data[ $key ] ?? array();

		$minute_used = ( $entry['m'] ?? '' ) === $minute ? (int) ( $entry['mc'] ?? 0 ) : 0;
		$day_used    = ( $entry['d'] ?? '' ) === $day ? (int) ( $entry['dc'] ?? 0 ) : 0;

		if ( $limits['per_minute'] > 0 && $minute_used + $cost > $limits['per_minute'] ) {
			return false;
		}
		if ( $limits['per_day'] > 0 && $day_used + $cost > $limits['per_day'] ) {
			return false;
		}

		$entry['m']   = $minute;
		$entry['mc']  = $minute_used + $cost;
		$entry['d']   = $day;
		$entry['dc']  = $day_used + $cost;
		$data[ $key ] = $entry;
		$this->save( $data );
		return true;
	}

	/**
	 * Units used today.
	 *
	 * @param string $key Budget key.
	 * @return int
	 */
	public function used_today( $key ) {
		$entry = $this->load()[ $key ] ?? array();
		return ( $entry['d'] ?? '' ) === $this->day() ? (int) ( $entry['dc'] ?? 0 ) : 0;
	}

	/**
	 * Units remaining today (PHP_INT_MAX when unlimited).
	 *
	 * @param string $key Budget key.
	 * @return int
	 */
	public function remaining_today( $key ) {
		$limit = (int) ( $this->limits()[ $key ]['per_day'] ?? 0 );
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limit - $this->used_today( $key ) );
	}

	/**
	 * Blocks a key until a timestamp (after 429 / quota errors).
	 *
	 * @param string $key       Budget key.
	 * @param int    $timestamp Unix time.
	 */
	public function block( $key, $timestamp ) {
		$data                    = $this->load();
		$data[ $key ]            = $data[ $key ] ?? array();
		$data[ $key ]['blocked'] = (int) $timestamp;
		$this->save( $data );
	}

	/**
	 * Blocks a key until the next daily reset.
	 *
	 * @param string $key Budget key.
	 */
	public function block_until_reset( $key ) {
		try {
			$tz   = new \DateTimeZone( 'America/Los_Angeles' );
			$next = new \DateTime( 'tomorrow', $tz );
			$this->block( $key, $next->getTimestamp() + 60 );
		} catch ( \Exception $e ) {
			$this->block( $key, time() + DAY_IN_SECONDS );
		}
	}

	/**
	 * Unix time until which a key is blocked (0 = not blocked).
	 *
	 * @param string $key Budget key.
	 * @return int
	 */
	public function blocked_until( $key ) {
		$until = (int) ( $this->load()[ $key ]['blocked'] ?? 0 );
		return $until > time() ? $until : 0;
	}

	/**
	 * Usage snapshot for the UI.
	 *
	 * @return array
	 */
	public function snapshot() {
		$out = array();
		foreach ( $this->limits() as $key => $limit ) {
			$out[ $key ] = array(
				'used_today'    => $this->used_today( $key ),
				'limit_day'     => (int) $limit['per_day'],
				'blocked_until' => $this->blocked_until( $key ) ? gmdate( 'c', $this->blocked_until( $key ) ) : null,
			);
		}
		return $out;
	}

	/**
	 * Current quota day (Pacific).
	 *
	 * @return string
	 */
	private function day() {
		try {
			$now = new \DateTime( 'now', new \DateTimeZone( 'America/Los_Angeles' ) );
			return $now->format( 'Ymd' );
		} catch ( \Exception $e ) {
			return gmdate( 'Ymd' );
		}
	}

	/**
	 * Loads counters (bypassing stale object cache).
	 *
	 * @return array
	 */
	private function load() {
		wp_cache_delete( self::OPTION, 'options' );
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Saves counters.
	 *
	 * @param array $data Counters.
	 */
	private function save( array $data ) {
		update_option( self::OPTION, $data, false );
	}
}
