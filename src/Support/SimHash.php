<?php
/**
 * 64-bit SimHash for near-duplicate detection.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Support;

defined( 'ABSPATH' ) || exit;

/**
 * SimHash over word 3-shingles. Two texts whose hashes differ in only a few
 * bits (Hamming distance ≤ 3 of 64) are near-duplicates. The hash is kept as
 * 16 hex characters and processed as two 32-bit halves, so it works the
 * same on 32- and 64-bit PHP builds.
 *
 * For fast candidate lookup the hash is split into four 16-bit bands; two
 * hashes within distance 3 always share at least one identical band.
 */
final class SimHash {

	/**
	 * Hash of a text.
	 *
	 * @param string $text Plain text.
	 * @return string 16 hex chars ('' when the text is empty).
	 */
	public static function of( $text ) {
		$words = Text::words( (string) $text );
		$count = count( $words );
		if ( 0 === $count ) {
			return '';
		}
		$shingles = array();
		if ( $count < 3 ) {
			$shingles[] = implode( ' ', $words );
		} else {
			for ( $i = 0; $i <= $count - 3; $i++ ) {
				$shingles[] = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
			}
		}
		$vector = array_fill( 0, 64, 0 );
		foreach ( array_count_values( $shingles ) as $shingle => $weight ) {
			$md5 = md5( (string) $shingle );
			// Two 32-bit halves from the first 16 hex chars.
			$halves = array( hexdec( substr( $md5, 0, 8 ) ), hexdec( substr( $md5, 8, 8 ) ) );
			foreach ( $halves as $h => $half ) {
				for ( $bit = 0; $bit < 32; $bit++ ) {
					$vector[ $h * 32 + $bit ] += ( ( (int) $half >> $bit ) & 1 ) ? $weight : -$weight;
				}
			}
		}
		$out = '';
		foreach ( array( 0, 1 ) as $h ) {
			$half = 0;
			for ( $bit = 31; $bit >= 0; $bit-- ) {
				$half = $half * 2 + ( $vector[ $h * 32 + $bit ] > 0 ? 1 : 0 );
			}
			$out .= str_pad( dechex( $half ), 8, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * Hamming distance between two hashes (64 when either is empty).
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 * @return int
	 */
	public static function distance( $a, $b ) {
		if ( 16 !== strlen( (string) $a ) || 16 !== strlen( (string) $b ) ) {
			return 64;
		}
		$distance = 0;
		for ( $i = 0; $i < 16; $i += 4 ) {
			$x = hexdec( substr( $a, $i, 4 ) ) ^ hexdec( substr( $b, $i, 4 ) );
			while ( $x ) {
				$distance += $x & 1;
				$x       >>= 1;
			}
		}
		return $distance;
	}

	/**
	 * Four 16-bit bands for candidate lookup.
	 *
	 * @param string $hash Hash.
	 * @return int[]
	 */
	public static function bands( $hash ) {
		if ( 16 !== strlen( (string) $hash ) ) {
			return array( 0, 0, 0, 0 );
		}
		return array(
			(int) hexdec( substr( $hash, 0, 4 ) ),
			(int) hexdec( substr( $hash, 4, 4 ) ),
			(int) hexdec( substr( $hash, 8, 4 ) ),
			(int) hexdec( substr( $hash, 12, 4 ) ),
		);
	}

	/**
	 * Similarity 0..1 (1 - distance / 64).
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 * @return float
	 */
	public static function similarity( $a, $b ) {
		return 1.0 - ( self::distance( $a, $b ) / 64.0 );
	}
}
