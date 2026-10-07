<?php
/**
 * Tests for the pure parts of the content graph (terms, weights, anchors).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\LinkInserter;
use SearchPilot\Links\Suggester;

// phpcs:disable

/**
 * ContentIndex term extraction, stemming, TF-IDF; Suggester phrase/anchor ranking.
 */
class ContentIndexTest extends \PHPUnit\Framework\TestCase {

	public function test_stemming_english_and_indic_inflections() {
		$this->assertSame( ContentIndex::stem( 'shoes' ), ContentIndex::stem( 'shoe' ) );
		$this->assertSame( ContentIndex::stem( 'elections' ), ContentIndex::stem( 'election' ) );
		$this->assertSame( 'run', ContentIndex::stem( 'running' ) );
		$this->assertSame( 'run', ContentIndex::stem( 'runs' ) );
		$this->assertSame( ContentIndex::stem( 'story' ), ContentIndex::stem( 'stories' ) );
		$this->assertSame( ContentIndex::stem( 'box' ), ContentIndex::stem( 'boxes' ) );
		$this->assertSame( ContentIndex::stem( 'class' ), ContentIndex::stem( 'classes' ) );
		$this->assertSame( 'status', ContentIndex::stem( 'status' ) );
		$this->assertSame( 'stop', ContentIndex::stem( 'stopped' ) );
		// Kannada suffixes: "Bengaluru" / "in Bengaluru", "government" / "of the government".
		$this->assertSame( ContentIndex::stem( 'ಬೆಂಗಳೂರು' ), ContentIndex::stem( 'ಬೆಂಗಳೂರಿನಲ್ಲಿ' ) );
		$this->assertSame( ContentIndex::stem( 'ಸರ್ಕಾರ' ), ContentIndex::stem( 'ಸರ್ಕಾರದ' ) );
		$this->assertSame( ContentIndex::stem( 'ಕರ್ನಾಟಕ' ), ContentIndex::stem( 'ಕರ್ನಾಟಕದಲ್ಲಿ' ) );
		$this->assertNotSame( ContentIndex::stem( 'ಚುನಾವಣೆ' ), ContentIndex::stem( 'ಸರ್ಕಾರ' ) );
		// Hindi works the same way.
		$this->assertSame( ContentIndex::stem( 'सरकार' ), ContentIndex::stem( 'सरकारी' ) );
		// Non-ASCII Latin words are kept as they are.
		$this->assertSame( 'café', ContentIndex::stem( 'café' ) );
	}

	public function test_keywords_drop_stopwords_in_english_and_kannada() {
		$this->assertSame( array( ContentIndex::stem( 'monsoon' ), ContentIndex::stem( 'karnataka' ) ), ContentIndex::keywords( 'The monsoon and the Karnataka' ) );
		$kn = ContentIndex::keywords( 'ಮಳೆ ಮತ್ತು ಪ್ರವಾಹ' );
		$this->assertCount( 2, $kn, 'ಮತ್ತು (and) is a stop word.' );
	}

	public function test_extract_terms_boosts_title_and_builds_bigrams() {
		$terms = ContentIndex::extract_terms( 'Monsoon Forecast Karnataka', array( 'Rainfall outlook' ), 'The monsoon forecast predicts heavy rain. Farmers welcome rain.' );
		$monsoon  = ContentIndex::stem( 'monsoon' );
		$forecast = ContentIndex::stem( 'forecast' );
		$this->assertArrayHasKey( $monsoon, $terms );
		// Title (×3) + body (×1).
		$this->assertSame( 4.0, $terms[ $monsoon ] );
		$this->assertArrayHasKey( $monsoon . ' ' . $forecast, $terms );
		$this->assertSame( 4.0, $terms[ $monsoon . ' ' . $forecast ] );
		// Headings count double.
		$this->assertSame( 2.0, $terms[ ContentIndex::stem( 'rainfall' ) ] );
		// No bigram across a sentence break ("rain. Farmers").
		$this->assertFalse( isset( $terms[ 'rain ' . ContentIndex::stem( 'farmers' ) ] ) );
		// Stop words never become terms, and do not form bigrams.
		$this->assertFalse( isset( $terms['the'] ) );
		foreach ( array_keys( $terms ) as $term ) {
			$this->assertStringNotContainsString( 'the ', $term );
		}
	}

	public function test_extract_terms_limits_the_vocabulary() {
		$body = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$body .= 'word' . $i . 'x ';
		}
		$terms = ContentIndex::extract_terms( 'Title here', array(), $body, 40 );
		$this->assertCount( 40, $terms );
		$this->assertArrayHasKey( ContentIndex::stem( 'title' ), $terms, 'Boosted title terms survive the cut.' );
	}

	public function test_kannada_terms_and_bigrams() {
		$terms = ContentIndex::extract_terms( 'ಬೆಂಗಳೂರು ಮಳೆ', array(), 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ ಜೋರಾಗಿದೆ.' );
		$bigram = ContentIndex::stem( 'ಬೆಂಗಳೂರು' ) . ' ' . ContentIndex::stem( 'ಮಳೆ' );
		$this->assertArrayHasKey( $bigram, $terms );
		$this->assertSame( 4.0, $terms[ $bigram ], 'Inflected body occurrence adds to the title bigram.' );
	}

	public function test_weights_are_normalized_and_rare_terms_weigh_more() {
		$w = ContentIndex::weigh( array( 'rare' => 1, 'common' => 1 ), array( 'rare' => 1, 'common' => 90 ), 100 );
		$norm = 0;
		foreach ( $w as $v ) {
			$norm += $v * $v;
		}
		$this->assertLessThan( 0.0001, abs( 1.0 - $norm ) );
		$this->assertGreaterThan( $w['common'], $w['rare'] );
		$this->assertSame( array(), ContentIndex::weigh( array(), array(), 10 ) );
	}

	public function test_relevance_scale_and_normalized_detection() {
		$this->assertSame( 0.5, ContentIndex::relevance( 0.25 ) );
		$this->assertSame( 0.0, ContentIndex::relevance( -1 ) );
		$this->assertSame( 1.0, ContentIndex::relevance( 3 ) );
		$this->assertGreaterThan( 0.25, ContentIndex::relevance( 0.07 ), 'Related articles (cosine ≈ 0.07+) pass the default 25% threshold.' );
		$this->assertLessThan( 0.25, ContentIndex::relevance( 0.02 ), 'Unrelated articles stay below it.' );
		$weighted = ContentIndex::weigh( array( 'a' => 3, 'b' => 1, 'c' => 2 ), array( 'a' => 2, 'b' => 5, 'c' => 1 ), 50 );
		$this->assertTrue( ContentIndex::is_normalized( $weighted ) );
		$this->assertFalse( ContentIndex::is_normalized( array( 'a' => 3.0, 'b' => 1.0 ) ), 'Raw build frequencies are detected.' );
		$this->assertTrue( ContentIndex::is_normalized( array( 'only' => 1.0 ) ) );
	}

	public function test_similarity_barely_depends_on_corpus_size() {
		$a  = ContentIndex::extract_terms( 'Bengaluru metro extends timings', array(), 'Namma Metro trains will run until midnight to ease evening traffic in Bengaluru for commuters.' );
		$b  = ContentIndex::extract_terms( 'Traffic in Bengaluru: metro helps commuters', array(), 'Metro ridership grows as commuters avoid evening traffic in Bengaluru.' );
		$df = array();
		foreach ( array( $a, $b ) as $tf ) {
			foreach ( array_keys( $tf ) as $t ) {
				$df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
			}
		}
		$small = ContentIndex::cosine( ContentIndex::weigh( $a, $df, 4 ), ContentIndex::weigh( $b, $df, 4 ) );
		$large = ContentIndex::cosine( ContentIndex::weigh( $a, $df, 2000 ), ContentIndex::weigh( $b, $df, 2000 ) );
		$this->assertGreaterThan( 0.25, ContentIndex::relevance( $small ), 'Related posts on a 4-post site pass the default threshold.' );
		$this->assertLessThan( 0.15, abs( ContentIndex::relevance( $large ) - ContentIndex::relevance( $small ) ) );
	}

	public function test_cosine_similarity() {
		$a = array( 'x' => 0.6, 'y' => 0.8 );
		$this->assertLessThan( 0.0001, abs( 1.0 - ContentIndex::cosine( $a, $a ) ) );
		$this->assertSame( 0.0, ContentIndex::cosine( $a, array( 'z' => 1.0 ) ) );
		$this->assertLessThan( 0.0001, abs( 0.6 - ContentIndex::cosine( $a, array( 'x' => 1.0 ) ) ) );
	}

	public function test_title_phrases_skip_stopword_edges() {
		$phrases = Suggester::phrases( 'running shoes', 'The Best Running Shoes of the Year' );
		$this->assertSame( 'focus', $phrases[0]['kind'] );
		$keys = array_map(
			function ( $p ) {
				return implode( ' ', $p['stems'] );
			},
			$phrases
		);
		$this->assertContains( implode( ' ', array( ContentIndex::stem( 'best' ), ContentIndex::stem( 'running' ), ContentIndex::stem( 'shoes' ) ) ), $keys );
		foreach ( $phrases as $p ) {
			$this->assertNotSame( 'the', $p['stems'][0] );
			$this->assertNotSame( 'of', end( $p['stems'] ) );
		}
	}

	public function test_candidates_prefer_unused_anchor_variants_and_respect_the_cap() {
		$html     = '<p>Our guide to running shoes helps. The best running shoes of the year are listed.</p>';
		$segments = LinkInserter::segments( $html )['segments'];
		$phrases  = Suggester::phrases( 'running shoes', 'Best Running Shoes of the Year' );
		$list     = Suggester::candidates( $segments, $phrases, array(), array() );
		$this->assertSame( 'running shoes', $list[0]['anchor'], 'Focus keyword first when unused.' );

		// The exact focus anchor is already used twice for this target: prefer a variant.
		$list = Suggester::candidates( $segments, $phrases, array( 'running shoes' => 2 ), array() );
		$this->assertNotSame( 'running shoes', $list[0]['anchor'] );
		$this->assertSame( 0, $list[0]['uses'] );

		// Used three times: never proposed again.
		$list = Suggester::candidates( $segments, $phrases, array( 'running shoes' => 3 ), array() );
		foreach ( $list as $c ) {
			$this->assertNotSame( 'running shoes', mb_strtolower( $c['anchor'] ) );
		}
	}

	public function test_candidates_match_inflected_kannada_and_keep_original_text() {
		$html     = '<p>ಇಂದು ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ ಜೋರಾಗಿದೆ.</p>';
		$segments = LinkInserter::segments( $html )['segments'];
		$list     = Suggester::candidates( $segments, Suggester::phrases( 'ಬೆಂಗಳೂರು ಮಳೆ', 'ಬೆಂಗಳೂರು ಮಳೆ ಮುನ್ಸೂಚನೆ' ), array(), array() );
		$this->assertNotEmpty( $list );
		$this->assertSame( 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ', $list[0]['anchor'] );
		$this->assertNotNull( LinkInserter::insert( $html, $list[0]['anchor'], 'https://example.com/x/', 1 ) );
	}

	public function test_candidates_match_word_forms_both_ways_in_kannada() {
		// Target title in the nominative, source text inflected: ಮಳೆಯಿಂದ (because of the rain), ಬೆಂಗಳೂರಿನ (of Bengaluru).
		$html     = '<p>ಬೆಂಗಳೂರಿನ ಹಲವು ರಸ್ತೆಗಳು ಮಳೆಯಿಂದ ಜಲಾವೃತಗೊಂಡಿವೆ. ಕರಾವಳಿಯಲ್ಲಿ ಮುಂಗಾರು ಚುರುಕಾಗಿದೆ.</p>';
		$segments = LinkInserter::segments( $html )['segments'];
		$list     = Suggester::candidates( $segments, Suggester::phrases( '', 'ಕರಾವಳಿ ಮುಂಗಾರು ಚುರುಕು' ), array(), array() );
		$this->assertNotEmpty( $list );
		$this->assertSame( 'ಕರಾವಳಿಯಲ್ಲಿ ಮುಂಗಾರು ಚುರುಕಾಗಿದೆ', $list[0]['anchor'], 'Every word inflected, original text kept' );
		$this->assertNotNull( LinkInserter::insert( $html, $list[0]['anchor'], 'https://example.com/x/', 1 ) );
	}

	public function test_compounds_and_unrelated_kannada_words_are_no_anchor() {
		$html     = '<p>ಸಂಚಾರ ಪೊಲೀಸರು ಹೊಸ ಮಾರ್ಗಸೂಚಿ ಬಿಡುಗಡೆ ಮಾಡಿದ್ದಾರೆ. ಮಳಿಗೆಗಳು ಮುಚ್ಚಿದ್ದವು.</p>';
		$segments = LinkInserter::segments( $html )['segments'];
		// Title "metro yellow route": ಮಾರ್ಗ (route) must not match ಮಾರ್ಗಸೂಚಿ (guideline); ಮಳೆ (rain) not ಮಳಿಗೆ (shop).
		$list = Suggester::candidates( $segments, Suggester::phrases( '', 'ಹಳದಿ ಮಾರ್ಗ ಮಳೆ', array(), array( 'ಮಾರ್ಗ', 'ಮಳೆ' ) ), array(), array() );
		$this->assertSame( array(), $list );
	}

	public function test_single_word_anchors_need_a_rare_word_and_in_english_a_name() {
		$html     = '<p>The squad trains at the Chinnaswamy Stadium this week. Three games follow. Chinnaswamy hosts the final.</p>';
		$segments = LinkInserter::segments( $html )['segments'];
		$phrases  = Suggester::phrases( '', 'RCB beat rivals by three wickets at Chinnaswamy', array(), array( 'chinnaswamy', 'three' ) );
		$anchors  = array_map(
			function ( $c ) {
				return $c['anchor'];
			},
			Suggester::candidates( $segments, $phrases, array(), array() )
		);
		$this->assertContains( 'Chinnaswamy', $anchors, 'A name in running text' );
		$this->assertNotContains( 'Three', $anchors, 'A common word that only starts a sentence is no name' );
		$this->assertNotContains( 'three', $anchors );
		$this->assertTrue( Suggester::is_name_at( 'Trains at Chinnaswamy today', 10, 'Chinnaswamy' ) );
		$this->assertFalse( Suggester::is_name_at( 'Trains today. Three games', 14, 'Three' ) );
		$this->assertTrue( Suggester::is_name_at( 'Polls in BBMP wards', 9, 'BBMP' ) );
		$this->assertTrue( Suggester::is_name_at( 'BBMP polls soon', 0, 'BBMP' ), 'Acronyms anywhere' );
		$this->assertFalse( Suggester::is_name_at( 'the stadium', 4, 'stadium' ) );
	}

	public function test_title_phrases_of_only_common_words_are_skipped() {
		$phrases = Suggester::phrases( '', 'ಮುಂದಿನ ಮೂರು ದಿನ ಮಳೆ', array(), array(), array( 'ಮುಂದಿನ', 'ಮೂರು', 'ದಿನ' ) );
		$keys    = array_map(
			function ( $p ) {
				return implode( ' ', $p['words'] ?? $p['stems'] );
			},
			$phrases
		);
		$this->assertNotContains( 'ಮುಂದಿನ ಮೂರು', $keys, '"next three" is no anchor' );
		$this->assertContains( 'ದಿನ ಮಳೆ', $keys, 'A phrase with one specific word stays' );
		$this->assertTrue( Suggester::distinctive( 'ಚಿನ್ನಸ್ವಾಮಿ' ) );
		$this->assertFalse( Suggester::distinctive( 'ಮಳೆ' ), 'Two aksharas are too short to stand alone' );
		$this->assertFalse( Suggester::distinctive( 'the' ) );
	}

	public function test_context_sentence_contains_anchor() {
		$text = 'First sentence here. The monsoon forecast arrives today. Last one.';
		$this->assertSame( 'The monsoon forecast arrives today.', Suggester::context_sentence( $text, 'monsoon forecast' ) );
		$long = str_repeat( 'word ', 120 ) . 'target phrase ' . str_repeat( 'more ', 120 );
		$ctx  = Suggester::context_sentence( $long, 'target phrase' );
		$this->assertStringContainsString( 'target phrase', $ctx );
		$this->assertLessThan( 340, mb_strlen( $ctx ) );
	}
}
