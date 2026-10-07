<?php
/**
 * Tests for the link-target ranking (Kannada and English).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\Ranker;

// phpcs:disable

/**
 * ContentIndex::words_match (inflection-tolerant word forms), Ranker signals and ranking tiers.
 */
class LinkRankerTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Features of a fixture post.
	 */
	private function post( $id, $title, $slug, array $cats, array $tags, $time = 0, $topic = 0.0 ) {
		return array(
			'id'          => $id,
			'title_words' => ContentIndex::key_words( $title ),
			'slug_tokens' => Ranker::slug_tokens( $slug ),
			'categories'  => $cats,
			'tags'        => $tags,
			'script'      => Ranker::script( $title ),
			'time'        => $time ?: 1700000000 + $id,
			'topic'       => $topic,
		);
	}

	public function test_kannada_word_forms_match_across_suffixes() {
		$this->assertTrue( ContentIndex::words_match( 'ಬೆಂಗಳೂರು', 'ಬೆಂಗಳೂರಿನಲ್ಲಿ' ), 'Locative with the root vowel change ು → ಿ' );
		$this->assertTrue( ContentIndex::words_match( 'ಬೆಂಗಳೂರಿನಲ್ಲಿ', 'ಬೆಂಗಳೂರು' ), 'Symmetric' );
		$this->assertTrue( ContentIndex::words_match( 'ಮಳೆ', 'ಮಳೆಯಿಂದ' ), 'Two-akshara root + ablative' );
		$this->assertTrue( ContentIndex::words_match( 'ಮಳೆ', 'ಮಳೆಗೆ' ), 'Dative' );
		$this->assertTrue( ContentIndex::words_match( 'ಸರ್ಕಾರ', 'ಸರ್ಕಾರಕ್ಕೆ' ) );
		$this->assertTrue( ContentIndex::words_match( 'ಸರ್ಕಾರ', 'ಸರ್ಕಾರದ' ) );
		$this->assertTrue( ContentIndex::words_match( 'ಚುನಾವಣೆ', 'ಚುನಾವಣಾ' ), 'Election / electoral' );
		$this->assertTrue( ContentIndex::words_match( 'ಚುರುಕು', 'ಚುರುಕಾಗಿದೆ' ) );
		$this->assertTrue( ContentIndex::words_match( 'ಊರು', 'ಊರಿನ' ), 'u-ending two-akshara noun' );
		$this->assertTrue( ContentIndex::words_match( 'ಕರ್ನಾಟಕ', 'ಕರ್ನಾಟಕದಲ್ಲಿ' ) );
	}

	public function test_kannada_unrelated_words_and_compounds_do_not_match() {
		$this->assertFalse( ContentIndex::words_match( 'ಮಳೆ', 'ಮಳಿಗೆ' ), 'Rain ≠ shop: only the first akshara is shared' );
		$this->assertFalse( ContentIndex::words_match( 'ಮನೆ', 'ಮನು' ), 'Two different two-akshara words' );
		$this->assertFalse( ContentIndex::words_match( 'ಮನು', 'ಮನೆ' ) );
		$this->assertFalse( ContentIndex::words_match( 'ಮಾರ್ಗ', 'ಮಾರ್ಗಸೂಚಿ' ), 'Route ≠ guideline (compound, not a case suffix)' );
		$this->assertFalse( ContentIndex::words_match( 'ಪ್ರಯಾಣ', 'ಪ್ರವಾಸ' ), 'Travel ≠ tour' );
		$this->assertFalse( ContentIndex::words_match( 'ಚುನಾವಣೆ', 'ಸರ್ಕಾರ' ) );
		$this->assertFalse( ContentIndex::words_match( 'ಮಳೆ', 'rain' ), 'Different scripts' );
		$this->assertFalse( ContentIndex::words_match( 'ಮ', 'ಮಳೆ' ), 'A single akshara is no root' );
	}

	public function test_english_word_forms() {
		$this->assertTrue( ContentIndex::words_match( 'elections', 'election' ) );
		$this->assertTrue( ContentIndex::words_match( 'running', 'run' ) );
		$this->assertTrue( ContentIndex::words_match( 'stories', 'story' ) );
		$this->assertFalse( ContentIndex::words_match( 'rain', 'railway' ) );
		$this->assertFalse( ContentIndex::words_match( 'polls', 'bypolls' ) );
		$this->assertTrue( ContentIndex::matches_any( 'showers', array( 'heavy', 'shower' ) ) );
	}

	public function test_slug_tokens_decode_kannada_and_drop_noise() {
		$this->assertSame( array( 'heavy', 'rain', 'floods', 'several', 'bengaluru', 'roads' ), Ranker::slug_tokens( 'l-heavy-rain-floods-several-bengaluru-roads' ) );
		$this->assertSame( array( 'karnataka', 'startup', 'policy', 'tier' ), Ranker::slug_tokens( 'karnataka-startup-policy-tier-2' ) );
		$this->assertSame( array( 'ಕರಾವಳಿ', 'ಜಿಲ್ಲೆಗಳಲ್ಲಿ' ), Ranker::slug_tokens( rawurlencode( 'ಕರಾವಳಿ' ) . '-' . rawurlencode( 'ಜಿಲ್ಲೆಗಳಲ್ಲಿ' ) . '-2' ) );
		$this->assertSame( array(), Ranker::slug_tokens( '' ) );
	}

	public function test_script_detection() {
		$this->assertSame( 'kannada', Ranker::script( '[L] ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ' ) );
		$this->assertSame( 'latin', Ranker::script( 'Heavy rain floods Bengaluru' ) );
		$this->assertSame( 'devanagari', Ranker::script( 'बेंगलुरु में भारी बारिश' ) );
		$this->assertSame( 'other', Ranker::script( '2026 — 42' ) );
	}

	public function test_kannada_titles_share_words_despite_inflection() {
		$a = $this->post( 1, 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ', 'bengaluru-heavy-rain', array(), array() );
		$b = $this->post( 2, 'ಬೆಂಗಳೂರು ಮಳೆಯಿಂದ ಸಂಚಾರ ಅಸ್ತವ್ಯಸ್ತ', '', array(), array() );
		$c = $this->post( 3, 'ರಣಜಿ ಟ್ರೋಫಿ: ಕರ್ನಾಟಕ ತಂಡ ಪ್ರಕಟ', '', array(), array() );
		$ab = Ranker::score( $a, $b );
		$ac = Ranker::score( $a, $c );
		$this->assertGreaterThan( 0.4, $ab['title'], 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ↔ ಬೆಂಗಳೂರು and ಮಳೆ ↔ ಮಳೆಯಿಂದ are shared words' );
		$this->assertSame( 0.0, $ac['title'] );
		$this->assertGreaterThan( $ac['score'], $ab['score'] );
	}

	public function test_categories_weigh_more_than_tags_and_catch_alls_little() {
		$src = $this->post( 1, 'Heavy rain floods several Bengaluru roads', '', array( 10 ), array( 20 ) );
		$cat = $this->post( 2, 'Reservoir levels rise after showers', '', array( 10 ), array() );
		$tag = $this->post( 3, 'Yellow Line ridership crosses one lakh', '', array( 11 ), array( 20 ) );
		$ctx = array( 'sizes' => array( 10 => 12, 11 => 15, 20 => 18 ) );
		$this->assertGreaterThan( Ranker::taxonomy( $src, $tag, $ctx ), Ranker::taxonomy( $src, $cat, $ctx ) );
		$this->assertLessThan( 0.001, abs( 0.6 - Ranker::taxonomy( $src, $cat, $ctx ) ) );
		$this->assertLessThan( 0.001, abs( 0.3 - Ranker::taxonomy( $src, $tag, $ctx ) ) );
		// A catch-all category with 20 000 posts weighs far less than a focused one.
		$this->assertLessThan( 0.25, Ranker::taxonomy( $src, $cat, array( 'sizes' => array( 10 => 20000 ) ) ) );
		// The default category says nothing.
		$this->assertSame( 0.0, Ranker::taxonomy( $src, $cat, array( 'sizes' => $ctx['sizes'], 'default_category' => 10 ) ) );
		$this->assertSame( 1.0, Ranker::specificity( 5 ) );
		$this->assertSame( 0.3, Ranker::specificity( 10000000 ) );
	}

	public function test_shared_category_and_tag_reach_the_default_threshold() {
		$src  = $this->post( 1, 'Bypolls in three Assembly seats on Saturday', '', array( 30 ), array( 40 ) );
		$peer = $this->post( 2, 'BBMP polls: ward reservation list nears completion', 'bbmp-ward-reservation-list', array( 30 ), array( 40 ) );
		$s    = Ranker::score( $src, $peer, array( 'sizes' => array( 30 => 9, 40 => 9 ) ) );
		$this->assertGreaterThanOrEqual( 0.25, $s['score'] );
		$this->assertTrue( $s['shares_category'] );
	}

	public function test_rare_shared_title_words_count_more_than_common_ones() {
		$src    = $this->post( 1, 'Chinnaswamy pitch report for Bengaluru', '', array(), array() );
		$rare   = $this->post( 2, 'Chinnaswamy drainage praised', '', array(), array() );
		$common = $this->post( 3, 'Bengaluru drainage praised', '', array(), array() );
		$ctx    = array(
			'df' => array(
				ContentIndex::stem( 'chinnaswamy' ) => 2,
				ContentIndex::stem( 'bengaluru' )   => 900,
			),
			'n'  => 1000,
		);
		$this->assertGreaterThan( Ranker::score( $src, $common, $ctx )['title'], Ranker::score( $src, $rare, $ctx )['title'] );
	}

	public function test_other_script_candidates_are_scaled_down() {
		$kn = $this->post( 1, 'ರಾಜ್ಯದ ಹೊಸ ಸ್ಟಾರ್ಟ್‌ಅಪ್ ನೀತಿ ಜಾರಿ', 'karnataka-startup-policy', array( 50 ), array( 60 ) );
		$en = $this->post( 2, 'State unveils startup policy for tier-2 cities', 'karnataka-startup-policy-tier-2', array( 50 ), array( 60 ) );
		$kn2 = $this->post( 3, 'ಸ್ಟಾರ್ಟ್‌ಅಪ್ ಉದ್ಯಮಿಗಳಿಗೆ ತರಬೇತಿ', 'karnataka-startup-policy-training', array( 50 ), array( 60 ) );
		$cross = Ranker::score( $kn, $en );
		$same  = Ranker::score( $kn, $kn2 );
		$this->assertFalse( $cross['same_script'] );
		$this->assertGreaterThan( $cross['score'], $same['score'] );
	}

	public function test_rank_tiers_threshold_fallback_and_self() {
		$src   = $this->post( 1, 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ', 'bengaluru-heavy-rain-alert', array( 7 ), array( 8 ) );
		$cands = array(
			$this->post( 1, 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ', 'bengaluru-heavy-rain-alert', array( 7 ), array( 8 ) ), // Itself.
			$this->post( 2, 'ಕರಾವಳಿ ಜಿಲ್ಲೆಗಳಲ್ಲಿ ಮುಂಗಾರು ಚುರುಕು', '', array( 7 ), array( 8 ), 100 ),
			$this->post( 3, 'ಮೈಸೂರು ದಸರಾ ಸಿದ್ಧತೆ', '', array( 7 ), array(), 300 ),            // Same category only, newest.
			$this->post( 4, 'ಹುಬ್ಬಳ್ಳಿ ಜಾತ್ರೆ', '', array( 7 ), array(), 200 ),                  // Same category only, older.
			$this->post( 5, 'Heavy rain floods several Bengaluru roads', 'heavy-rain-bengaluru', array( 7 ), array( 8 ) ), // Other script.
			$this->post( 6, 'ಚಿನ್ನದ ಬೆಲೆ ಏರಿಕೆ', '', array( 9 ), array(), 400 ),                // Unrelated.
		);
		$ctx    = array(
			'sizes'       => array( 7 => 10, 8 => 10, 9 => 10 ),
			'threshold'   => 0.25,
			'min_related' => 3,
		);
		$ranked = Ranker::rank( $src, $cands, $ctx );
		$ids    = array_column( $ranked, 'post_id' );
		$this->assertNotContains( 1, $ids, 'Never the post itself' );
		$this->assertNotContains( 6, $ids, 'Nothing in common' );
		$this->assertSame( 2, $ids[0], 'Category + tag: related tier first' );
		$this->assertSame( 'related', $ranked[0]['tier'] );
		// Fewer than three related: the same-category fallback fills up, newest first.
		$this->assertSame( array( 2, 3, 4 ), array_slice( $ids, 0, 3 ) );
		$this->assertSame( 'recent', $ranked[1]['tier'] );
		$this->assertCount( 3, $ranked, 'The other-script post does not pass the threshold and is never a fallback' );

		// With a lower threshold the other-script post qualifies as related, and the fallback is not needed.
		$low = Ranker::rank( $src, $cands, array_merge( $ctx, array( 'threshold' => 0.05, 'min_related' => 1 ) ) );
		$this->assertContains( 5, array_column( $low, 'post_id' ) );
		foreach ( $low as $row ) {
			$this->assertSame( 'related', $row['tier'] );
		}
	}

	public function test_english_ranking_prefers_topical_partners() {
		$src   = $this->post( 1, 'Heavy rain floods several Bengaluru roads', 'heavy-rain-floods-several-bengaluru-roads', array( 7 ), array( 8, 9 ), 0, 0.0 );
		$cands = array(
			$this->post( 2, 'Monsoon forecast for Bengaluru: heavy rain this week', 'monsoon-forecast-bengaluru-heavy-rain', array( 7 ), array( 9 ), 0, 0.45 ),
			$this->post( 3, 'Reservoir levels rise after a week of steady showers', 'reservoir-levels-rise', array( 7 ), array( 9 ), 0, 0.2 ),
			$this->post( 4, 'RCB beat rivals by six wickets at Chinnaswamy', 'rcb-beat-rivals', array( 12 ), array( 8 ), 0, 0.05 ),
		);
		$ranked = Ranker::rank( $src, $cands, array( 'sizes' => array( 7 => 10, 8 => 40, 9 => 10, 12 => 10 ) ) );
		$this->assertSame( array( 2, 3 ), array_slice( array_column( $ranked, 'post_id' ), 0, 2 ) );
		$this->assertGreaterThan( 0.5, $ranked[0]['score'] );
	}
}
