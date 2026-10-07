<?php
/**
 * Tests for Analytics\Suggestions.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Analytics\Suggestions;
use SearchPilot\Support\Text;

/**
 * Title and description drafts: query once, length limits, brand suffix kept.
 */
class SuggestionsTest extends \PHPUnit\Framework\TestCase {

	public function test_split_brand() {
		$parts = Suggestions::split_brand( 'How to brew green tea | Tea Times', 'Tea Times' );
		$this->assertSame( 'How to brew green tea', $parts['core'] );
		$this->assertSame( ' | ', $parts['separator'] );
		$this->assertSame( 'Tea Times', $parts['brand'] );

		// A trailing phrase that is not the site name is part of the title.
		$parts = Suggestions::split_brand( 'Monsoon arrives – what to expect this week', 'Tea Times' );
		$this->assertSame( '', $parts['brand'] );
		$this->assertSame( 'Monsoon arrives – what to expect this week', $parts['core'] );

		// Without a site name a short trailing segment is treated as the brand.
		$parts = Suggestions::split_brand( 'Budget phones compared - TechDaily', '' );
		$this->assertSame( 'TechDaily', $parts['brand'] );
		$this->assertSame( ' - ', $parts['separator'] );
	}

	public function test_titles_add_the_query_once_and_keep_the_brand() {
		$titles = Suggestions::titles(
			'Hello world | Tea Times',
			'green tea benefits',
			array(
				'site_name'  => 'Tea Times',
				'post_title' => 'Hello world',
			)
		);
		$this->assertCount( 3, $titles );
		foreach ( $titles as $t ) {
			$this->assertLessThanOrEqual( 60, $t['length'], $t['text'] );
			$this->assertSame( 1, Text::count_phrase( $t['text'], 'green tea benefits' ), $t['text'] );
			$this->assertTrue( $t['has_query'] );
			$this->assertTrue( $t['fits'] );
			$this->assertStringContainsString( ' | Tea Times', $t['text'] );
			$this->assertNotSame( '', $t['label'] );
		}
		$this->assertSame( 'Green tea benefits: Hello world | Tea Times', $titles[0]['text'] );
	}

	public function test_titles_when_the_query_is_already_present() {
		$titles = Suggestions::titles(
			'Why green tea benefits your heart, according to doctors | Tea Times',
			'green tea benefits',
			array( 'site_name' => 'Tea Times' )
		);
		$this->assertNotEmpty( $titles );
		foreach ( $titles as $t ) {
			$this->assertSame( 1, Text::count_phrase( $t['text'], 'green tea benefits' ), $t['text'] );
			$this->assertLessThanOrEqual( 60, $t['length'], $t['text'] );
		}
		$this->assertSame( 0, strpos( $titles[0]['text'], 'Green tea benefits' ), $titles[0]['text'] );
	}

	public function test_no_colon_only_draft_when_the_title_starts_with_the_query() {
		$titles = Suggestions::titles( 'Gold price today in Bengaluru – City News', 'gold price today', array( 'site_name' => 'City News' ) );
		$this->assertNotEmpty( $titles );
		foreach ( $titles as $t ) {
			$this->assertStringNotContainsString( 'today: in', $t['text'] );
			$this->assertSame( 1, Text::count_phrase( $t['text'], 'gold price today' ), $t['text'] );
		}
		$this->assertNotContains( 'Query first', array_column( $titles, 'label' ) );
	}

	public function test_long_titles_are_shortened_without_cutting_the_query() {
		$titles = Suggestions::titles(
			'An extremely long headline about the many different ways to prepare a cup | The Very Long Brand Name Daily',
			'matcha latte recipe',
			array( 'site_name' => 'The Very Long Brand Name Daily' )
		);
		$this->assertNotEmpty( $titles );
		foreach ( $titles as $t ) {
			$this->assertLessThanOrEqual( 60, $t['length'], $t['text'] );
			$this->assertSame( 1, Text::count_phrase( $t['text'], 'matcha latte recipe' ), $t['text'] );
		}
	}

	public function test_titles_in_kannada() {
		$titles = Suggestions::titles(
			'ರಾಜ್ಯದಲ್ಲಿ ಭಾರಿ ಮಳೆ ಮುನ್ಸೂಚನೆ | ಕನ್ನಡ ಸುದ್ದಿ',
			'ಬೆಂಗಳೂರು ಮಳೆ',
			array( 'site_name' => 'ಕನ್ನಡ ಸುದ್ದಿ' )
		);
		$this->assertNotEmpty( $titles );
		foreach ( $titles as $t ) {
			$this->assertSame( 1, Text::count_phrase( $t['text'], 'ಬೆಂಗಳೂರು ಮಳೆ' ), $t['text'] );
			$this->assertLessThanOrEqual( 60, $t['length'] );
			$this->assertStringContainsString( 'ಕನ್ನಡ ಸುದ್ದಿ', $t['text'] );
		}
	}

	public function test_titles_without_query() {
		$titles = Suggestions::titles( str_repeat( 'Word ', 20 ) . '| Brand', '', array( 'site_name' => 'Brand' ) );
		$this->assertNotEmpty( $titles );
		$this->assertLessThanOrEqual( 60, $titles[0]['length'] );
		$this->assertFalse( $titles[0]['has_query'] );
	}

	public function test_descriptions() {
		$content = '<p>Green tea has been part of Asian diets for centuries. Researchers link a daily cup to better focus and heart health.</p>'
			. '<p>This guide explains the green tea benefits that are backed by studies, how much to drink and when to avoid it. We also compare it with black tea and coffee.</p>'
			. '<p>Finally, we share three simple ways to brew it well.</p>';
		$drafts  = Suggestions::descriptions( $content, 'green tea benefits', array( 'current' => '' ) );
		$this->assertCount( 3, $drafts );
		foreach ( $drafts as $d ) {
			$this->assertLessThanOrEqual( 155, $d['length'], $d['text'] );
			$this->assertTrue( $d['has_query'], $d['text'] );
			$this->assertLessThanOrEqual( 1, Text::count_phrase( $d['text'], 'green tea benefits' ), $d['text'] );
			$this->assertNotSame( '', $d['label'] );
		}
		$in_range = array_filter( array_column( $drafts, 'in_range' ) );
		$this->assertGreaterThanOrEqual( 2, count( $in_range ), 'Most drafts land in 120–155 characters' );
	}

	public function test_description_truncates_on_a_word_boundary() {
		$long = Suggestions::fit_description( str_repeat( 'lorem ipsum dolor ', 20 ), 155 );
		$this->assertLessThanOrEqual( 155, Text::length( $long ) );
		$this->assertSame( '…', mb_substr( $long, -1 ) );
	}

	public function test_phrase_and_without_phrase() {
		$this->assertSame( 'green tea', Suggestions::phrase( '  "green   tea?" ' ) );
		$this->assertSame( 'Why is good for you', Suggestions::without_phrase( 'Why green tea is good for you', 'green tea' ) );
		$this->assertSame( 'Guide', Suggestions::without_phrase( 'Green Tea: Guide', 'green tea' ) );
	}

	public function test_fit_title_drops_suffix_when_needed() {
		$fit = Suggestions::fit_title( 'Query phrase that is quite long indeed: ', 'main part words here and there', '', ' | A Very Long Brand Name Here', 60 );
		$this->assertLessThanOrEqual( 60, Text::length( $fit ) );
		$this->assertSame( 0, strpos( $fit, 'Query phrase that is quite long indeed' ), $fit );
	}
}
