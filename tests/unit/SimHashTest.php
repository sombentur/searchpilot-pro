<?php
/**
 * Tests for the SimHash near-duplicate fingerprint.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Support\SimHash;

// phpcs:disable

/**
 * SimHash: identical, near-identical and different texts; bands; Unicode.
 */
class SimHashTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Deterministic pseudo-random text.
	 */
	private function text( $seed, $words = 500 ) {
		$vocabulary = array( 'river', 'market', 'station', 'monsoon', 'farmer', 'school', 'budget', 'hospital', 'council', 'bridge', 'festival', 'harvest', 'traffic', 'metro', 'water', 'power', 'village', 'district', 'election', 'minister', 'court', 'police', 'cricket', 'temple', 'museum', 'library', 'airport', 'highway', 'forest', 'lake', 'garden', 'factory', 'college', 'teacher', 'doctor', 'engineer', 'report', 'survey', 'price', 'rain' );
		mt_srand( $seed );
		$out = array();
		for ( $i = 0; $i < $words; $i++ ) {
			$out[] = $vocabulary[ mt_rand( 0, count( $vocabulary ) - 1 ) ];
		}
		mt_srand();
		return implode( ' ', $out );
	}

	public function test_identical_texts_have_identical_hashes() {
		$text = $this->text( 1 );
		$a    = SimHash::of( $text );
		$this->assertSame( 16, strlen( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );
		$this->assertSame( $a, SimHash::of( $text ) );
		$this->assertSame( 0, SimHash::distance( $a, SimHash::of( $text ) ) );
		$this->assertEquals( 1.0, SimHash::similarity( $a, $a ) );
		// Case and punctuation do not matter (tokens are lower-cased words).
		$this->assertSame( $a, SimHash::of( strtoupper( $text ) . '!' ) );
	}

	public function test_near_identical_texts_are_within_distance_three_and_share_a_band() {
		$text   = $this->text( 2, 800 );
		$words  = explode( ' ', $text );
		$words[400] = 'zebra';
		$near   = implode( ' ', $words );
		$a      = SimHash::of( $text );
		$b      = SimHash::of( $near );
		$this->assertLessThanOrEqual( 3, SimHash::distance( $a, $b ) );
		$this->assertNotEmpty( array_intersect_assoc( SimHash::bands( $a ), SimHash::bands( $b ) ), 'Distance ≤ 3 guarantees one identical 16-bit band.' );
	}

	public function test_different_texts_are_far_apart() {
		$a = SimHash::of( $this->text( 3 ) );
		$b = SimHash::of( $this->text( 4 ) );
		$this->assertGreaterThan( 10, SimHash::distance( $a, $b ) );
		$this->assertLessThan( 0.85, SimHash::similarity( $a, $b ) );
	}

	public function test_empty_text_and_invalid_hashes() {
		$this->assertSame( '', SimHash::of( '' ) );
		$this->assertSame( '', SimHash::of( ' ... ' ) );
		$this->assertSame( 64, SimHash::distance( '', 'abcdabcdabcdabcd' ) );
		$this->assertSame( array( 0, 0, 0, 0 ), SimHash::bands( 'xyz' ) );
		$this->assertSame( array( 65535, 0, 65535, 0 ), SimHash::bands( 'ffff0000ffff0000' ) );
	}

	public function test_kannada_text_is_fingerprinted_with_combining_marks() {
		$kn   = 'ಬೆಂಗಳೂರು ನಗರದಲ್ಲಿ ಇಂದು ಭಾರೀ ಮಳೆ ಸುರಿದಿದ್ದು ಹಲವು ರಸ್ತೆಗಳು ಜಲಾವೃತವಾಗಿವೆ ಸಂಚಾರ ಪೊಲೀಸರು ಪರ್ಯಾಯ ಮಾರ್ಗ ಸೂಚಿಸಿದ್ದಾರೆ';
		$hash = SimHash::of( $kn );
		$this->assertSame( 16, strlen( $hash ) );
		// Changing a vowel sign changes a word, so the hash changes too (marks are part of words).
		$this->assertNotSame( $hash, SimHash::of( str_replace( 'ಮಳೆ', 'ಮಳ', $kn ) ) );
		$this->assertSame( $hash, SimHash::of( $kn . ' ' ) );
	}
}
