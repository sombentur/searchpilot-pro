<?php
/**
 * Tests for Support\Text.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Support\Text;

// phpcs:disable

class TextTest extends \PHPUnit\Framework\TestCase {

	public function test_kannada_words_keep_vowel_signs() {
		$words = Text::words( 'ಕರ್ನಾಟಕ ಸರ್ಕಾರದ ಹೊಸ ಯೋಜನೆ.' );
		$this->assertSame( array( 'ಕರ್ನಾಟಕ', 'ಸರ್ಕಾರದ', 'ಹೊಸ', 'ಯೋಜನೆ' ), $words );
	}

	public function test_word_count_mixed_scripts() {
		$this->assertSame( 5, Text::word_count( 'Hello world ಕನ್ನಡ ಸುದ್ದಿ today' ) );
	}

	public function test_sentences_split_on_danda_and_period() {
		$this->assertCount( 3, Text::sentences( 'ಮೊದಲ ವಾಕ್ಯ। Second one. Third?' ) );
	}

	public function test_contains_uses_unicode_boundaries() {
		$this->assertTrue( Text::contains( 'ಹೊಸ ಯೋಜನೆ ಘೋಷಣೆ', 'ಯೋಜನೆ' ) );
		// A longer inflected word must not match the shorter phrase.
		$this->assertFalse( Text::contains( 'ಯೋಜನೆಗಳು ಘೋಷಣೆ', 'ಯೋಜನೆ' ) );
		$this->assertTrue( Text::contains( 'Best SEO Tips here', 'seo tips' ) );
		$this->assertFalse( Text::contains( 'seotips', 'seo tips' ) );
	}

	public function test_count_phrase() {
		$this->assertSame( 2, Text::count_phrase( 'WordPress SEO and wordpress seo plugins', 'wordpress seo' ) );
	}

	public function test_strip_removes_scripts_and_keeps_spacing() {
		$this->assertSame( 'One Two', Text::strip( '<p>One</p><script>alert(1)</script><p>Two</p>' ) );
	}

	public function test_stem_and_stopwords() {
		$this->assertSame( 'story', Text::stem( 'stories' ) );
		$this->assertSame( 'class', Text::stem( 'class' ) );
		$this->assertSame( Text::stem( 'shoe' ), Text::stem( 'shoes' ) );
		$this->assertSame( 'box', Text::stem( 'boxes' ) );
		$this->assertSame( 'wish', Text::stem( 'wishes' ) );
		$this->assertSame( 'status', Text::stem( 'status' ) );
		$this->assertTrue( Text::is_stopword( 'the' ) );
		$this->assertTrue( Text::is_stopword( 'ಮತ್ತು' ) );
		$this->assertFalse( Text::is_stopword( 'indexing' ) );
	}

	public function test_truncate_on_word_boundary() {
		$this->assertSame( 'The quick brown…', Text::truncate( 'The quick brown fox jumps', 18 ) );
	}

	public function test_length_counts_grapheme_clusters() {
		$this->assertSame( 0, Text::length( '' ) );
		$this->assertSame( 5, Text::length( 'hello' ) );
		$this->assertSame( 4, Text::length( 'ಬೆಂಗಳೂರು' ), 'ಬೆಂ ಗ ಳೂ ರು' );
		$this->assertSame( 1, Text::length( "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}" ), 'An emoji family is one character.' );
		$this->assertSame( 1, Text::length( "e\u{0301}" ), 'Base letter + combining accent.' );
		$this->assertSame( array( 'ಬೆಂ', 'ಗ', 'ಳೂ', 'ರು' ), Text::graphemes( 'ಬೆಂಗಳೂರು' ) );
	}

	public function test_truncate_counts_the_ellipsis_and_returns_fitting_text_unchanged() {
		$this->assertSame( '  fits ', Text::truncate( '  fits ', 10 ) );
		$long = str_repeat( 'word ', 40 );
		foreach ( array( 10, 25, 60 ) as $max ) {
			$cut = Text::truncate( $long, $max );
			$this->assertLessThanOrEqual( $max, Text::length( $cut ) );
			$this->assertSame( '…', mb_substr( $cut, -1 ) );
		}
		$this->assertSame( 'a', Text::truncate( 'abcdef', 1 ), 'No room for the ellipsis: plain cut.' );
		$this->assertSame( 'abc def', Text::truncate( 'abc def ghi', 7, '' ) );
		$this->assertSame( '', Text::truncate( 'abc', 0 ) );
	}

	public function test_truncate_word_boundary_only_within_the_last_quarter() {
		// The only space is at 4 of 11 kept graphemes — too early, so the cut is hard (cluster-safe).
		$this->assertSame( 'aaaa bbbbbb…', Text::truncate( 'aaaa bbbbbbbbbbbbbbbbbbbb', 12 ) );
		// Scripts without spaces are cut at a grapheme boundary.
		$this->assertSame( '東京都渋谷区…', Text::truncate( '東京都渋谷区神南一丁目', 7 ) );
	}

	public function test_truncate_never_splits_kannada_clusters() {
		$text = 'ಬೆಂಗಳೂರು ನಗರದಲ್ಲಿ ಶುಕ್ರವಾರ ಭಾರಿ ಮಳೆಯಾಗಿದ್ದು ಹಲವು ಪ್ರದೇಶಗಳಲ್ಲಿ ನೀರು ನಿಂತಿದೆ';
		for ( $max = 3; $max < Text::length( $text ); $max += 4 ) {
			$cut  = Text::truncate( $text, $max );
			$body = mb_substr( $cut, 0, -1 );
			$this->assertLessThanOrEqual( $max, Text::length( $cut ) );
			$this->assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
			$this->assertSame( 0, strpos( $text, $body ), "max {$max}" );
			$this->assertSame( Text::graphemes( $body ), array_slice( Text::graphemes( $text ), 0, Text::length( $body ) ), "max {$max}: whole clusters only" );
		}
		// 1.0.2 trimmed with a byte list containing "–" (E2 80 93), which broke ಓ (E0 B2 93).
		$cut = Text::truncate( str_repeat( 'ಓ', 30 ), 10 );
		$this->assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'ಓ', 9 ) . '…', $cut );
	}

	public function test_serp_fit_uses_rendered_width() {
		$title = Text::serp_fit( 'Best Running Shoes for Beginners: How to Choose', 'title' );
		$this->assertTrue( $title['fits'] );
		$this->assertSame( 47, $title['graphemes'] );
		$this->assertSame( 580.0, $title['limit'] );
		$this->assertGreaterThan( 400, $title['width'] );
		$this->assertFalse( Text::serp_fit( str_repeat( 'Wide Words ', 8 ), 'title' )['fits'] );
		// Narrow and wide Latin letters differ (Arial metrics).
		$this->assertLessThan( Text::serp_width( 'WWWWWWWWWW', 'title' ), Text::serp_width( 'iiiiiiiiii', 'title' ) );
		// Kannada graphemes are wider than Latin letters (4 graphemes per word on both sides).
		$this->assertGreaterThan( Text::serp_width( str_repeat( 'abcd ', 10 ), 'description' ), Text::serp_width( str_repeat( 'ಕಮಲದ ', 10 ), 'description' ) );
		// An emoji ZWJ sequence is drawn once.
		$this->assertSame( Text::serp_width( "\u{1F468}", 'title' ), Text::serp_width( "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 'title' ) );
		$this->assertSame( array( 'fits', 'graphemes', 'width', 'limit' ), array_keys( Text::serp_fit( '', 'description' ) ) );
	}

	public function test_conjunct_width_does_not_depend_on_segmentation() {
		// ನ್ನ: the second ನ after the virama is a small subscript, not a full letter.
		$conjunct = Text::serp_width( 'ಕನ್ನಡ', 'title' );
		$plain    = Text::serp_width( 'ಕನನಡ', 'title' );
		$this->assertLessThan( $plain, $conjunct );
	}

	public function test_serp_truncate_fits_and_keeps_clusters() {
		$kn  = str_repeat( 'ಹವಾಮಾನ ಇಲಾಖೆ ಮುಂದಿನ ಮೂರು ದಿನಗಳ ಕಾಲ ಮಳೆ ಮುಂದುವರಿಯಲಿದೆ ಎಂದು ತಿಳಿಸಿದೆ. ', 4 );
		$cut = Text::serp_truncate( $kn, 'description' );
		$fit = Text::serp_fit( $cut, 'description' );
		$this->assertTrue( $fit['fits'] );
		$this->assertGreaterThan( 0.85 * $fit['limit'], $fit['width'], 'Uses most of the space.' );
		$this->assertSame( 0, strpos( $kn, mb_substr( $cut, 0, -1 ) ) );
		$this->assertSame( 'Short text', Text::serp_truncate( 'Short text', 'title' ) );
		$en = Text::serp_truncate( str_repeat( 'Kannada news from Bengaluru today. ', 6 ), 'title' );
		$this->assertTrue( Text::serp_fit( $en, 'title' )['fits'] );
		$this->assertSame( '…', mb_substr( $en, -1 ) );
	}

	public function test_replace_vars_drops_unknown() {
		$this->assertSame( 'Hello – Site', Text::replace_vars( '%title% %sep% %sitename% %unknown%', array( 'title' => 'Hello', 'sep' => '–', 'sitename' => 'Site' ) ) );
	}

	public function test_clean_keeps_template_variables() {
		$this->assertSame( '%title% %sep% %category% %date%', Text::clean( "  %title%  %sep%\n%category% <b>%date%</b> " ) );
		$this->assertSame( "Line one\n\nLine two", Text::clean( "Line one\r\n\r\n\r\nLine two<script>x</script>", true ) );
	}

	public function test_is_latin() {
		$this->assertTrue( Text::is_latin( 'Plain English text' ) );
		$this->assertFalse( Text::is_latin( 'ಕನ್ನಡ ಪಠ್ಯ' ) );
	}
}
