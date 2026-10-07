<?php
/**
 * Retry back-off policy for submissions.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Exponential back-off with jitter: 60 s · 2^attempts, capped at 12 hours,
 * randomized by ±20 % so many failed rows do not retry in lock-step.
 * A provider's Retry-After is always honoured (never retried earlier).
 * Pure: no WordPress calls.
 */
final class Backoff {

	const BASE = 60;

	const CAP = 43200; // 12 hours.

	const RETRY_AFTER_CAP = 172800; // Honour Retry-After up to 48 hours.

	const JITTER = 0.2;

	/**
	 * Delay in seconds before the next attempt.
	 *
	 * @param int        $attempts    Attempts made so far (1 after the first failure).
	 * @param int        $retry_after Provider Retry-After in seconds (0 = none).
	 * @param float|null $random      Random number in [0,1] (tests); null = random.
	 * @return int
	 */
	public static function delay( $attempts, $retry_after = 0, $random = null ) {
		$attempts = max( 0, (int) $attempts );
		// 2^attempts grows quickly; clamp the exponent so the multiplication never overflows.
		$base = self::BASE * ( 2 ** min( $attempts, 16 ) );
		$base = min( self::CAP, $base );

		$random = null === $random ? mt_rand() / mt_getrandmax() : max( 0.0, min( 1.0, (float) $random ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Jitter only.
		$factor = 1 - self::JITTER + 2 * self::JITTER * $random;
		$delay  = (int) round( $base * $factor );
		$delay  = max( self::BASE, min( self::CAP, $delay ) );

		$retry_after = max( 0, min( self::RETRY_AFTER_CAP, (int) $retry_after ) );
		return max( $delay, $retry_after );
	}

	/**
	 * Unix time of the next attempt.
	 *
	 * @param int        $attempts    Attempts made so far.
	 * @param int        $retry_after Retry-After seconds.
	 * @param int|null   $now         Current time.
	 * @param float|null $random      Random number (tests).
	 * @return int
	 */
	public static function next_attempt_at( $attempts, $retry_after = 0, $now = null, $random = null ) {
		$now = null === $now ? time() : (int) $now;
		return $now + self::delay( $attempts, $retry_after, $random );
	}

	/**
	 * Whether a row has used up its attempts.
	 *
	 * @param int $attempts     Attempts made (including the one that just failed).
	 * @param int $max_attempts Maximum attempts.
	 * @return bool
	 */
	public static function exhausted( $attempts, $max_attempts ) {
		return (int) $attempts >= max( 1, (int) $max_attempts );
	}

	/**
	 * Classifies an HTTP status: permanent failures are never retried blindly.
	 *
	 * @param int $status HTTP status (0 = transport error).
	 * @return string retry|permanent|ok
	 */
	public static function classify( $status ) {
		$status = (int) $status;
		if ( $status >= 200 && $status < 300 ) {
			return 'ok';
		}
		if ( 0 === $status || in_array( $status, array( 408, 425, 429 ), true ) || $status >= 500 ) {
			return 'retry';
		}
		return 'permanent';
	}
}
