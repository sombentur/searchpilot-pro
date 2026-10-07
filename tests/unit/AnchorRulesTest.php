<?php
/**
 * Tests for the in-text anchor rules (no padded anchors).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\LinkInserter;
use SearchPilot\Links\Suggester;

// phpcs:disable

/**
 * Rules: every content word of an anchor is a title/focus word (word forms match), no stop word at
 * either end, at least two content words unless the single word is rare and not the site's dominant
 * term. Regression for the 1.0.2 → 1.1.0 Autopilot links «across Bengaluru» / «Bengaluru describe».
 */
class AnchorRulesTest extends \PHPUnit\Framework\TestCase {

	const ADVISORY = 'Weekend traffic advisory for Bengaluru';
	const METRO    = 'Bengaluru Metro Phase Three Opens';

	/**
	 * Phrases of a target as phrases_for() builds them: the target's body bigrams include the
	 * shared sentence, and "Bengaluru" is in 60 of 100 posts (dominant), the rest rare.
	 */
	private function phrases( $title, array $body_bigrams, $focus = '', array $df = array() ) {
		$words = ContentIndex::key_words( $title . ' ' . $focus );
		$df    = $df ?: array( ContentIndex::stem( 'bengaluru' ) => 60, ContentIndex::stem( 'ಬೆಂಗಳೂರು' ) => 60 );
		$vocab = Suggester::anchor_vocabulary( $words, $df, 100 );
		$bi    = array();
		foreach ( $body_bigrams as $pair ) {
			$bi[] = array_map( array( ContentIndex::class, 'stem' ), $pair );
		}
		return Suggester::phrases( $focus, $title, $bi, $vocab['singles'], $vocab['generic'] );
	}

	private function anchors( $sentence, array $phrases ) {
		$segments = LinkInserter::segments( '<p>' . $sentence . '</p>' )['segments'];
		return array_map(
			function ( $c ) {
				return $c['anchor'];
			},
			Suggester::candidates( $segments, $phrases, array(), array() )
		);
	}

	public function test_the_three_padded_autopilot_anchors_are_rejected() {
		$bigrams = array( array( 'across', 'bengaluru' ), array( 'bengaluru', 'describe' ), array( 'longer', 'journeys' ) );
		// Post 4 → "Weekend traffic advisory for Bengaluru".
		$this->assertSame( array(), $this->anchors( 'Commuters across Bengaluru describe longer journeys…', $this->phrases( self::ADVISORY, $bigrams ) ) );
		// Posts 6 and 7 → "Bengaluru Metro Phase Three Opens".
		$this->assertSame( array(), $this->anchors( 'Commuters across Bengaluru describe longer journeys…', $this->phrases( self::METRO, $bigrams ) ) );
		$this->assertSame( array(), $this->anchors( 'Commuters across Bengaluru describe longer journeys…', $this->phrases( self::METRO, $bigrams ) ) );
		// Even as a stored suggestion (validated again before insertion), the padded texts never fit.
		foreach ( array( 'across Bengaluru', 'Bengaluru describe', 'Bengaluru' ) as $anchor ) {
			$this->assertSame( array(), Suggester::candidates( array( array( 'text' => $anchor, 'block' => 0 ) ), $this->phrases( self::METRO, $bigrams ), array(), array(), false ), $anchor );
		}
	}

	/**
	 * Every content word of every candidate is a word of the title.
	 */
	private function assert_only_title_words( array $anchors, $title ) {
		$heads = ContentIndex::key_words( $title );
		foreach ( $anchors as $anchor ) {
			foreach ( ContentIndex::key_words( $anchor ) as $word ) {
				$this->assertTrue( ContentIndex::matches_any( $word, $heads ), "«{$word}» in «{$anchor}» is not a title word" );
			}
		}
	}

	public function test_anchors_made_of_title_words_are_valid() {
		$metro = $this->anchors( 'Riders on the Bengaluru Metro praised the new line.', $this->phrases( self::METRO, array() ) );
		$this->assertSame( 'Bengaluru Metro', $metro[0] );
		$this->assert_only_title_words( $metro, self::METRO );
		$advisory = $this->anchors( 'Read the weekend traffic advisory before you leave.', $this->phrases( self::ADVISORY, array() ) );
		$this->assertSame( 'weekend traffic advisory', $advisory[0] );
		$this->assert_only_title_words( $advisory, self::ADVISORY );
		// A stop word between matched words is fine ("advisory for Bengaluru").
		$this->assertContains( 'advisory for Bengaluru', $this->anchors( 'Police issued an advisory for Bengaluru on Friday.', $this->phrases( self::ADVISORY, array() ) ) );
		// A body bigram counts only when both words are title words.
		$phrases = $this->phrases( self::METRO, array( array( 'metro', 'phase' ) ) );
		$this->assertContains( array( ContentIndex::stem( 'metro' ), ContentIndex::stem( 'phase' ) ), array_column( $phrases, 'stems' ) );
	}

	public function test_single_words_only_when_rare_and_not_dominant() {
		$vocab = Suggester::anchor_vocabulary(
			array( 'bengaluru', 'chinnaswamy', 'metro', 'opens' ),
			array(
				ContentIndex::stem( 'bengaluru' )   => 60,
				ContentIndex::stem( 'chinnaswamy' ) => 2,
				ContentIndex::stem( 'metro' )       => 9,
				ContentIndex::stem( 'opens' )       => 1,
			),
			100
		);
		$this->assertSame( array( 'bengaluru' ), $vocab['generic'], 'In more than 20 % of posts' );
		$this->assertSame( array( 'chinnaswamy', 'opens' ), $vocab['singles'] );
		$this->assertNotContains( 'metro', $vocab['singles'], 'Not rare enough to stand alone' );
		// A dominant focus keyword never becomes a one-word anchor either.
		$phrases = $this->phrases( 'Traffic news', array(), 'Bengaluru' );
		$this->assertSame( array(), $this->anchors( 'Roads in Bengaluru were busy.', $phrases ) );
		// Leading/trailing stop words of a focus keyword are not part of the anchor.
		$phrases = $this->phrases( 'Road works', array(), 'the outer ring road' );
		$this->assertSame( array( 'outer ring road' ), $this->anchors( 'Work on the outer ring road resumes.', $phrases ) );
		// Young sites are judged like a 50-post site: a word in 3 of 5 posts is not dominant.
		$young = Suggester::anchor_vocabulary( array( 'bengaluru' ), array( ContentIndex::stem( 'bengaluru' ) => 3 ), 5 );
		$this->assertSame( array(), $young['generic'] );
	}

	public function test_kannada_padded_anchors_are_rejected_and_word_forms_accepted() {
		$advisory = 'ಬೆಂಗಳೂರು ವಾರಾಂತ್ಯ ಸಂಚಾರ ಸಲಹೆ';
		$metro    = 'ಬೆಂಗಳೂರು ಮೆಟ್ರೋ ಮೂರನೇ ಹಂತ ಆರಂಭ';
		$sentence = 'ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು ದೀರ್ಘ ಪ್ರಯಾಣದ ಬಗ್ಗೆ ವಿವರಿಸುತ್ತಾರೆ.';
		$bigrams  = array( array( 'ಬೆಂಗಳೂರಿನ', 'ಪ್ರಯಾಣಿಕರು' ), array( 'ದೀರ್ಘ', 'ಪ್ರಯಾಣದ' ) );
		$this->assertSame( array(), $this->anchors( $sentence, $this->phrases( $advisory, $bigrams ) ), '«ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು» pads the dominant city name' );
		$this->assertSame( array(), $this->anchors( $sentence, $this->phrases( $metro, $bigrams ) ) );
		// Case-suffixed forms of title words are matched words.
		$list = $this->anchors( 'ಬೆಂಗಳೂರು ಮೆಟ್ರೋದಲ್ಲಿ ಪ್ರಯಾಣಿಸುವವರ ಸಂಖ್ಯೆ ಹೆಚ್ಚಿದೆ.', $this->phrases( $metro, array() ) );
		$this->assertSame( 'ಬೆಂಗಳೂರು ಮೆಟ್ರೋದಲ್ಲಿ', $list[0] );
		$this->assert_only_title_words( $list, $metro );
		$list = $this->anchors( 'ಪ್ರಯಾಣಕ್ಕೂ ಮುನ್ನ ವಾರಾಂತ್ಯದ ಸಂಚಾರ ಸಲಹೆಯನ್ನು ಓದಿ.', $this->phrases( $advisory, array() ) );
		$this->assertSame( 'ವಾರಾಂತ್ಯದ ಸಂಚಾರ ಸಲಹೆಯನ್ನು', $list[0] );
		$this->assert_only_title_words( $list, $advisory );
		// A postposition at the edge is never part of the anchor.
		$this->assertNotContains( 'ಸಲಹೆ ಬಗ್ಗೆ', $this->anchors( 'ಸಂಚಾರ ಸಲಹೆ ಬಗ್ಗೆ ಮಾಹಿತಿ.', $this->phrases( $advisory, array() ) ) );
	}
}
