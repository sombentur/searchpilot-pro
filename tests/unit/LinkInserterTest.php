<?php
/**
 * Tests for the HTML-safe link inserter.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Core\Changes;
use SearchPilot\Links\LinkInserter;

// phpcs:disable

/**
 * LinkInserter: eligibility rules, Unicode boundaries, casing, undo markers.
 */
class LinkInserterTest extends \PHPUnit\Framework\TestCase {

	const URL = 'https://example.com/target/';

	private function insert( $html, $anchor, $id = 7 ) {
		return LinkInserter::insert( $html, $anchor, self::URL, $id );
	}

	public function test_links_first_occurrence_in_paragraph_and_keeps_case() {
		$html = '<p>We tested Running Shoes today. More running shoes later.</p>';
		$out  = $this->insert( $html, 'running shoes' );
		$this->assertSame( '<p>We tested <a href="https://example.com/target/" data-spp-link="7">Running Shoes</a> today. More running shoes later.</p>', $out );
	}

	public function test_only_first_eligible_occurrence_is_linked() {
		$out = $this->insert( '<p>alpha beta</p><p>alpha beta</p>', 'alpha beta' );
		$this->assertSame( 1, substr_count( $out, '<a ' ) );
		$this->assertStringContainsString( '<p>alpha beta</p>', substr( $out, strpos( $out, '</p>' ) ) );
	}

	public function test_never_inside_headings() {
		$this->assertNull( $this->insert( '<h2>running shoes</h2><h3>Running shoes guide</h3>', 'running shoes' ) );
		$out = $this->insert( '<h2>running shoes</h2><p>Buy running shoes.</p>', 'running shoes' );
		$this->assertStringContainsString( '<h2>running shoes</h2>', $out );
		$this->assertStringContainsString( '<p>Buy <a ', $out );
	}

	public function test_never_inside_existing_links() {
		$this->assertNull( $this->insert( '<p><a href="/x">running shoes</a></p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<p><a href="/x">the <strong>running shoes</strong> page</a></p>', 'running shoes' ) );
	}

	public function test_never_inside_code_pre_script_style_button_figcaption() {
		$this->assertNull( $this->insert( '<p><code>running shoes</code></p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<pre>running shoes</pre>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<p><script>var a = "running shoes";</script></p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<style>.running shoes{}</style>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<p><button>running shoes</button></p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '<figure><img src="a.jpg" alt=""><figcaption>running shoes</figcaption></figure>', 'running shoes' ) );
	}

	public function test_never_inside_attributes() {
		$html = '<p><img src="a.jpg" alt="running shoes" title="running shoes"> no match here</p>';
		$this->assertNull( $this->insert( $html, 'running shoes' ) );
		$out = $this->insert( '<p class="running shoes"><span data-x="running shoes">Our running shoes</span></p>', 'running shoes' );
		$this->assertStringContainsString( 'class="running shoes"', $out );
		$this->assertStringContainsString( 'data-x="running shoes"', $out );
		$this->assertStringContainsString( 'Our <a href=', $out );
	}

	public function test_never_inside_shortcodes() {
		$this->assertNull( $this->insert( '<p>[button text="running shoes"]</p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '[caption id="1"]<img src="a.jpg"> running shoes[/caption]', 'running shoes' ) );
		$this->assertNull( $this->insert( '<p>[note]Great running shoes[/note]</p>', 'running shoes' ) );
		$out = $this->insert( '<p>[note]x[/note] Great running shoes</p>', 'running shoes' );
		$this->assertStringContainsString( '[note]x[/note] Great <a ', $out );
	}

	public function test_never_inside_block_comment_delimiters_and_only_safe_blocks() {
		$html = '<!-- wp:paragraph {"placeholder":"running shoes"} --><p>Best running shoes</p><!-- /wp:paragraph -->';
		$out  = $this->insert( $html, 'running shoes' );
		$this->assertStringContainsString( '{"placeholder":"running shoes"}', $out );
		$this->assertStringContainsString( '<p>Best <a href=', $out );

		$custom = '<!-- wp:acme/callout --><div class="callout"><p>running shoes</p></div><!-- /wp:acme/callout -->';
		$this->assertNull( $this->insert( $custom, 'running shoes' ) );

		$list = '<!-- wp:list --><ul><!-- wp:list-item --><li>cheap running shoes</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
		$this->assertStringContainsString( '<li>cheap <a ', $this->insert( $list, 'running shoes' ) );

		$heading = '<!-- wp:heading --><h2 class="wp-block-heading">running shoes</h2><!-- /wp:heading -->';
		$this->assertNull( $this->insert( $heading, 'running shoes' ) );
	}

	public function test_classic_content_without_paragraph_tags() {
		$html = "First paragraph about running shoes.\n\nSecond paragraph.";
		$out  = $this->insert( $html, 'running shoes' );
		$this->assertStringContainsString( 'about <a href="https://example.com/target/" data-spp-link="7">running shoes</a>.', $out );
		$this->assertNull( $this->insert( '<table><tr><td>running shoes</td></tr></table>', 'running shoes' ) );
	}

	public function test_latin_word_boundaries() {
		$this->assertNull( $this->insert( '<p>Our shoestring budget and shoes.</p>', 'shoe' ) );
		$this->assertNull( $this->insert( '<p><strong>Bengal</strong>uru news</p>', 'uru news' ) );
		$out = $this->insert( '<p>A shoe, a hat.</p>', 'shoe' );
		$this->assertStringContainsString( 'A <a href="https://example.com/target/" data-spp-link="7">shoe</a>, a hat.', $out );
	}

	public function test_kannada_unicode_boundaries_with_combining_marks() {
		$html = '<p>ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆಯಿಂದ ತೊಂದರೆ. ಇಂದು ಬೆಂಗಳೂರು ಮಳೆ ಜೋರಾಗಿದೆ.</p>';
		$out  = $this->insert( $html, 'ಬೆಂಗಳೂರು ಮಳೆ' );
		$this->assertStringContainsString( 'ಇಂದು <a href="https://example.com/target/" data-spp-link="7">ಬೆಂಗಳೂರು ಮಳೆ</a> ಜೋರಾಗಿದೆ', $out );
		// "ಮಳೆ" must not match inside "ಮಳೆಯಿಂದ" (a vowel sign or letter follows).
		$this->assertNull( $this->insert( '<p>ಮಳೆಯಿಂದ ತೊಂದರೆ</p>', 'ಮಳೆ' ) );
		// A combining mark right after the phrase means the word continues.
		$this->assertNull( $this->insert( '<p>ಕನ್ನಡಿಗ</p>', 'ಕನ್ನಡ' ) );
	}

	public function test_entities_are_never_split_and_nbsp_counts_as_space() {
		$out = $this->insert( '<p>Buy running&nbsp;shoes now</p>', 'running shoes' );
		$this->assertStringContainsString( '<a href="https://example.com/target/" data-spp-link="7">running&nbsp;shoes</a>', $out );
		$out = $this->insert( '<p>a &amp; b amp</p>', 'amp' );
		$this->assertStringContainsString( 'a &amp; b <a ', $out );
		$this->assertNull( $this->insert( '<p>&amp;&amp;</p>', 'amp' ) );
	}

	public function test_returns_null_when_missing_and_marker_undo_roundtrip() {
		$this->assertNull( $this->insert( '<p>Nothing relevant.</p>', 'running shoes' ) );
		$this->assertNull( $this->insert( '', 'running shoes' ) );
		$this->assertNull( $this->insert( '<p>x</p>', '   ' ) );
		$html = '<!-- wp:paragraph --><p>Try Running Shoes.</p><!-- /wp:paragraph -->';
		$out  = $this->insert( $html, 'running shoes', 42 );
		$this->assertStringContainsString( 'data-spp-link="42"', $out );
		$this->assertSame( $html, Changes::remove_marked_links( $out, 'data-spp-link="42"' ) );
		// A different marker id is untouched.
		$this->assertSame( $out, Changes::remove_marked_links( $out, 'data-spp-link="4"' ) );
	}

	public function test_segments_report_only_eligible_text_with_paragraph_context() {
		$html = '<h2>Heading words</h2><p>Hello <a href="/x">linked text</a> world. Second sentence.</p><!-- wp:acme/box --><p>custom block</p><!-- /wp:acme/box -->';
		$info = LinkInserter::segments( $html );
		$texts = array_map( function ( $s ) { return $s['text']; }, $info['segments'] );
		$this->assertContains( 'Hello', $texts );
		$this->assertContains( 'world. Second sentence.', $texts );
		$this->assertNotContains( 'linked text', $texts );
		$this->assertNotContains( 'Heading words', $texts );
		$this->assertNotContains( 'custom block', $texts );
		$block = $info['segments'][0]['block'];
		$this->assertSame( 'Hello linked text world. Second sentence.', $info['blocks'][ $block ] );
	}

	public function test_string_marker_tokens_for_fix_plans() {
		$out = LinkInserter::insert( '<p>Try running shoes.</p>', 'running shoes', self::URL, 'fix-12' );
		$this->assertStringContainsString( 'data-spp-link="fix-12"', $out );
		$this->assertSame( '<p>Try running shoes.</p>', Changes::remove_marked_links( $out, 'data-spp-link="fix-12"' ) );
		// Anything else is cast to an integer (no attribute injection).
		$bad = LinkInserter::insert( '<p>Try running shoes.</p>', 'running shoes', self::URL, '7" onclick="x' );
		$this->assertStringContainsString( 'data-spp-link="7"', $bad );
		$this->assertStringNotContainsString( 'onclick', $bad );
	}

	public function test_never_inside_searchpilot_generated_regions() {
		$marked = "<!-- wp:paragraph -->\n<p>Read more on monsoon rain.</p>\n<!-- /wp:paragraph -->\n\n<!-- searchpilot:related-links -->\n<!-- wp:paragraph -->\n<p>Monsoon rain news</p>\n<!-- /wp:paragraph -->\n<!-- /searchpilot:related-links -->";
		$out    = $this->insert( $marked, 'monsoon rain' );
		$this->assertStringContainsString( 'Read more on <a ', $out, 'Text before the region is eligible' );
		$this->assertStringContainsString( "<p>Monsoon rain news</p>\n<!-- /wp:paragraph -->\n<!-- /searchpilot", $out );
		$this->assertNull( $this->insert( "<!-- searchpilot:x -->\n<p>monsoon rain</p>\n<!-- /searchpilot:x -->", 'monsoon rain' ) );
		// Elements with a searchpilot-… class (ContentEditor's wrapper, the list title).
		$this->assertNull( $this->insert( '<div class="wp-block-group searchpilot-related-links"><p>monsoon rain</p></div>', 'monsoon rain' ) );
		$this->assertNull( $this->insert( '<p class="searchpilot-related-links__title"><strong>monsoon rain</strong></p>', 'monsoon rain' ) );
		$after = $this->insert( '<div class="searchpilot-related-links"><p>x</p></div><p>monsoon rain</p>', 'monsoon rain' );
		$this->assertStringContainsString( '</div><p><a ', $after, 'Eligible again after the region closes' );
		// A class that merely contains the word is not ours.
		$this->assertNotNull( $this->insert( '<div class="nosearchpilot-x"><p>monsoon rain</p></div>', 'monsoon rain' ) );
	}
}
