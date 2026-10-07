<?php
/**
 * Expected click-through rate by ranking position.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * A site-specific CTR curve. Each position bucket (1 … 10, 11–20, 21+) uses
 * the site's own median CTR from its query rows when there are enough
 * samples; otherwise an industry-typical fallback. Fractional positions
 * are interpolated between neighbouring buckets (position 1.5 expects less
 * than position 1 and more than position 2).
 *
 * Pure: no WordPress calls, so the curve is unit tested.
 */
final class CtrModel {

	/**
	 * Fallback curve: bucket => CTR (fraction). 11 = positions 11–20, 21 = 21+.
	 */
	const FALLBACK = array(
		1  => 0.28,
		2  => 0.15,
		3  => 0.11,
		4  => 0.08,
		5  => 0.065,
		6  => 0.05,
		7  => 0.04,
		8  => 0.032,
		9  => 0.028,
		10 => 0.025,
		11 => 0.01,
		21 => 0.004,
	);

	/**
	 * Curve in use: bucket => CTR.
	 *
	 * @var array<int,float>
	 */
	private $curve;

	/**
	 * Buckets learned from the site's own data: bucket => sample count.
	 *
	 * @var array<int,int>
	 */
	private $learned = array();

	/**
	 * Constructor.
	 *
	 * @param array<int,float> $curve   Bucket => CTR (missing buckets use the fallback).
	 * @param array<int,int>   $learned Bucket => samples for buckets taken from site data.
	 */
	public function __construct( array $curve = array(), array $learned = array() ) {
		$this->curve = self::FALLBACK;
		foreach ( $curve as $bucket => $ctr ) {
			$bucket = (int) $bucket;
			if ( isset( self::FALLBACK[ $bucket ] ) && is_numeric( $ctr ) && $ctr > 0 && $ctr < 1 ) {
				$this->curve[ $bucket ] = (float) $ctr;
			}
		}
		$this->learned = array_map( 'intval', $learned );
	}

	/**
	 * Builds a model from query rows (the site's own median CTR per bucket).
	 *
	 * @param array $rows            Rows with position, clicks, impressions (ctr optional).
	 * @param int   $min_samples     Rows needed in a bucket before its median replaces the fallback.
	 * @param int   $min_impressions Rows with fewer impressions are too noisy and ignored.
	 * @return CtrModel
	 */
	public static function from_rows( array $rows, $min_samples = 8, $min_impressions = 20 ) {
		$samples = array();
		foreach ( $rows as $row ) {
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			if ( $impressions < $min_impressions || $position <= 0 ) {
				continue;
			}
			$bucket               = self::bucket( $position );
			$samples[ $bucket ][] = (float) ( $row['clicks'] ?? 0 ) / $impressions;
		}
		$curve   = array();
		$learned = array();
		foreach ( $samples as $bucket => $values ) {
			if ( count( $values ) < $min_samples ) {
				continue;
			}
			$median = self::median( $values );
			if ( $median > 0 ) {
				$curve[ $bucket ]   = $median;
				$learned[ $bucket ] = count( $values );
			}
		}
		return new self( self::monotonic( $curve ), $learned );
	}

	/**
	 * Bucket of a position: 1 … 10, 11 (11–20) or 21 (21+).
	 *
	 * @param float $position Average position.
	 * @return int
	 */
	public static function bucket( $position ) {
		$position = (float) $position;
		if ( $position < 1.5 ) {
			return 1;
		}
		if ( $position < 10.5 ) {
			return (int) round( $position );
		}
		return $position < 20.5 ? 11 : 21;
	}

	/**
	 * Expected CTR for an average position (interpolated).
	 *
	 * @param float $position Average position (1 = top).
	 * @return float Fraction 0..1.
	 */
	public function expected( $position ) {
		$position = max( 1.0, (float) $position );
		if ( $position >= 20.5 ) {
			return $this->curve[21];
		}
		if ( $position >= 11 ) {
			return $this->curve[11];
		}
		$low  = (int) floor( $position );
		$frac = $position - $low;
		$a    = $this->curve[ $low ];
		$b    = $low >= 10 ? $this->curve[11] : $this->curve[ $low + 1 ];
		return $a + ( $b - $a ) * $frac;
	}

	/**
	 * Actual CTR relative to the expectation (1.0 = as expected).
	 *
	 * @param float $ctr      Actual CTR (fraction).
	 * @param float $position Average position.
	 * @return float
	 */
	public function ratio( $ctr, $position ) {
		$expected = $this->expected( $position );
		return $expected > 0 ? (float) $ctr / $expected : 0.0;
	}

	/**
	 * Clicks the page would get at the expected CTR minus the clicks it got.
	 *
	 * @param int   $clicks      Clicks.
	 * @param int   $impressions Impressions.
	 * @param float $position    Position.
	 * @return int
	 */
	public function missed_clicks( $clicks, $impressions, $position ) {
		return (int) max( 0, round( $this->expected( $position ) * (float) $impressions - (float) $clicks ) );
	}

	/**
	 * Whether a bucket comes from the site's own data.
	 *
	 * @param int $bucket Bucket.
	 * @return bool
	 */
	public function is_learned( $bucket ) {
		return isset( $this->learned[ (int) $bucket ] );
	}

	/**
	 * Curve for display: [ [ bucket, label, ctr, source, samples ] ].
	 *
	 * @return array
	 */
	public function to_array() {
		$out = array();
		foreach ( $this->curve as $bucket => $ctr ) {
			$out[] = array(
				'bucket'  => $bucket,
				'label'   => 11 === $bucket ? '11–20' : ( 21 === $bucket ? '21+' : (string) $bucket ),
				'ctr'     => round( $ctr, 4 ),
				'source'  => $this->is_learned( $bucket ) ? 'site' : 'typical',
				'samples' => $this->learned[ $bucket ] ?? 0,
			);
		}
		return $out;
	}

	/**
	 * Median of numbers.
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	public static function median( array $values ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );
		if ( ! $n ) {
			return 0.0;
		}
		sort( $values );
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Keeps a learned curve plausible: a worse position never expects a higher
	 * CTR than a better one (small sites produce noisy medians). Learned
	 * buckets are made monotonic among themselves; typical (fallback) buckets
	 * are then clamped between their learned neighbours.
	 *
	 * @param array<int,float> $curve Learned buckets.
	 * @return array<int,float> Full curve.
	 */
	public static function monotonic( array $curve ) {
		ksort( $curve );
		$prev = null;
		foreach ( $curve as $bucket => $ctr ) {
			if ( null !== $prev && $ctr > $prev ) {
				$curve[ $bucket ] = $prev;
			}
			$prev = $curve[ $bucket ];
		}
		$full = array();
		foreach ( self::FALLBACK as $bucket => $fallback ) {
			if ( isset( $curve[ $bucket ] ) ) {
				$full[ $bucket ] = $curve[ $bucket ];
				continue;
			}
			$max = null;
			$min = null;
			foreach ( $curve as $learned_bucket => $ctr ) {
				if ( $learned_bucket < $bucket ) {
					$max = null === $max ? $ctr : min( $max, $ctr );
				} elseif ( $learned_bucket > $bucket ) {
					$min = null === $min ? $ctr : max( $min, $ctr );
				}
			}
			$value = $fallback;
			if ( null !== $max ) {
				$value = min( $value, $max );
			}
			if ( null !== $min ) {
				$value = max( $value, $min );
			}
			$full[ $bucket ] = $value;
		}
		return $full;
	}
}
