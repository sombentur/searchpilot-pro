<?php
/**
 * Tests for the related-articles list (block + classic markup, idempotency).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Links\LinkInserter;
use SearchPilot\Links\RelatedBlock;

// phpcs:disable

/**
 * RelatedBlock: Gutenberg-valid markup, classic equivalent, merge/replace via marked regions.
 */
class RelatedBlockTest extends \PHPUnit\Framework\TestCase {

	const BLOCKS  = "<!-- wp:paragraph -->\n<p>ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ ಸುರಿದಿದೆ.</p>\n<!-- /wp:paragraph -->";
	const CLASSIC = "Heavy rain flooded several roads.\n\nCrews worked through the night.";

	private function item( $n, $title = null ) {
		return array(
			'url'   => 'https://example.com/2026/10/0' . $n . '/post-' . $n . '/',
			'title' => null === $title ? 'Post ' . $n : $title,
			'link'  => 'r' . $n,
		);
	}

	public function test_block_markup_is_what_the_editor_serializes() {
		$html = RelatedBlock::build( array( $this->item( 1 ), $this->item( 2, 'ರಣಜಿ ಟ್ರೋಫಿ: ಕರ್ನಾಟಕ ತಂಡ' ) ), 'Related articles', true );
		$expected = '<!-- wp:paragraph {"className":"searchpilot-related-links__title"} -->' . "\n"
			. '<p class="searchpilot-related-links__title"><strong>Related articles</strong></p>' . "\n"
			. "<!-- /wp:paragraph -->\n\n"
			. '<!-- wp:list {"className":"searchpilot-related-links__list"} -->' . "\n"
			. '<ul class="wp-block-list searchpilot-related-links__list"><!-- wp:list-item -->' . "\n"
			. '<li><a href="https://example.com/2026/10/01/post-1/" data-spp-link="r1">Post 1</a></li>' . "\n"
			. "<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n"
			. '<li><a href="https://example.com/2026/10/02/post-2/" data-spp-link="r2">ರಣಜಿ ಟ್ರೋಫಿ: ಕರ್ನಾಟಕ ತಂಡ</a></li>' . "\n"
			. "<!-- /wp:list-item --></ul>\n"
			. '<!-- /wp:list -->';
		$this->assertSame( $expected, $html );
		// Block delimiters are balanced.
		$this->assertSame( substr_count( $html, '<!-- wp:list-item -->' ), substr_count( $html, '<!-- /wp:list-item -->' ) );
		$this->assertStringNotContainsString( '<h', $html, 'No heading: the outline and heading checks stay unchanged' );
	}

	public function test_list_class_for_wordpress_before_6_6() {
		$html = RelatedBlock::build( array( $this->item( 1 ) ), 'Related articles', true, false );
		$this->assertStringContainsString( '<ul class="searchpilot-related-links__list">', $html );
	}

	public function test_classic_markup() {
		$html = RelatedBlock::build( array( $this->item( 1 ) ), 'ಸಂಬಂಧಿತ ಸುದ್ದಿಗಳು', false );
		$this->assertSame(
			'<p class="searchpilot-related-links__title"><strong>ಸಂಬಂಧಿತ ಸುದ್ದಿಗಳು</strong></p>' . "\n"
			. '<ul class="searchpilot-related-links__list">' . "\n"
			. '<li><a href="https://example.com/2026/10/01/post-1/" data-spp-link="r1">Post 1</a></li>' . "\n</ul>",
			$html
		);
		$this->assertStringNotContainsString( '<!-- wp:', $html );
	}

	public function test_titles_and_urls_are_escaped() {
		$html = RelatedBlock::build( array( array( 'url' => 'https://example.com/a?b=1&c="x"', 'title' => 'Tom & Jerry <script>', 'link' => 'r1" onclick="x' ) ), 'A & B', false );
		$this->assertStringContainsString( 'Tom &amp; Jerry &lt;script&gt;', $html );
		$this->assertStringContainsString( '<strong>A &amp; B</strong>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '" onclick="', $html );
	}

	public function test_add_appends_one_region_and_rerun_replaces_it() {
		$first = RelatedBlock::add( self::BLOCKS, array( $this->item( 1 ) ), 'Related articles' );
		$this->assertSame( array( $this->item( 1 )['url'] ), $first['added'] );
		$this->assertSame( 0, strpos( $first['content'], self::BLOCKS ), 'Original content kept in front' );
		$this->assertSame( 1, substr_count( $first['content'], '<ul ' ) );

		// Second run: one list with both items, never a second list.
		$second = RelatedBlock::add( $first['content'], array( $this->item( 1 ), $this->item( 2 ) ), 'Related articles' );
		$this->assertSame( array( $this->item( 2 )['url'] ), $second['added'], 'Duplicate URL skipped' );
		$this->assertSame( 1, substr_count( $second['content'], '<ul ' ), 'One list, never a second one' );
		$items = RelatedBlock::items( $second['content'] );
		$this->assertCount( 2, $items );
		$this->assertSame( 'Post 1', $items[0]['title'] );
		$this->assertSame( 'r2', $items[1]['link'] );
		$this->assertTrue( $items[1]['linked'] );

		// Nothing to add: content byte-identical.
		$same = RelatedBlock::add( $second['content'], array( $this->item( 2 ) ), 'Related articles' );
		$this->assertSame( $second['content'], $same['content'] );
		$this->assertSame( array(), $same['added'] );
	}

	public function test_max_items_respected() {
		$out = RelatedBlock::add( self::BLOCKS, array( $this->item( 1 ), $this->item( 2 ), $this->item( 3 ), $this->item( 4 ) ), 'Related articles', array( 'max' => 3 ) );
		$this->assertCount( 3, $out['added'] );
		$this->assertCount( 3, RelatedBlock::items( $out['content'] ) );
		$more = RelatedBlock::add( $out['content'], array( $this->item( 5 ) ), 'Related articles', array( 'max' => 3 ) );
		$this->assertSame( array(), $more['added'] );
	}

	public function test_classic_content_gets_classic_markup() {
		$out = RelatedBlock::add( self::CLASSIC, array( $this->item( 1 ) ), 'Related articles' );
		$this->assertStringNotContainsString( '<!-- wp:paragraph', $out['content'] );
		$this->assertStringContainsString( '<ul class="searchpilot-related-links__list">', $out['content'] );
		$this->assertCount( 1, RelatedBlock::items( $out['content'] ) );
	}

	public function test_replace_with_no_items_removes_the_region() {
		$out = RelatedBlock::add( self::BLOCKS, array( $this->item( 1 ) ), 'Related articles' );
		$this->assertSame( self::BLOCKS, rtrim( RelatedBlock::replace( $out['content'], array(), 'Related articles' ) ) );
		$this->assertSame( array(), RelatedBlock::items( self::BLOCKS ) );
	}

	public function test_unlinked_entries_are_reported() {
		$out     = RelatedBlock::add( self::CLASSIC, array( $this->item( 1 ), $this->item( 2 ) ), 'Related articles' );
		$content = preg_replace( '#<a [^>]*data-spp-link="r1"[^>]*>(.*?)</a>#', '$1', $out['content'] ); // Surgical undo of r1.
		$items   = RelatedBlock::items( $content );
		$this->assertCount( 2, $items );
		$this->assertFalse( $items[0]['linked'] );
		$this->assertSame( 'Post 1', $items[0]['title'] );
		$this->assertTrue( $items[1]['linked'] );
		// Rewriting with only linked entries drops the leftover.
		$linked = array_values( array_filter( $items, function ( $i ) { return $i['linked']; } ) );
		$this->assertCount( 1, RelatedBlock::items( RelatedBlock::replace( $content, $linked, 'Related articles' ) ) );
	}

	public function test_region_is_a_group_block_in_block_content_and_a_div_in_classic() {
		$blocks = RelatedBlock::add( self::BLOCKS, array( $this->item( 1 ) ), 'Related articles' )['content'];
		$this->assertSame( 1, preg_match_all( '#<!-- wp:group \{[^}]*"className":"searchpilot-related-links"#', $blocks ) );
		$this->assertSame( 1, substr_count( $blocks, '<div class="wp-block-group searchpilot-related-links">' ) );
		$this->assertStringNotContainsString( '<!-- searchpilot:', $blocks, 'No loose marker comments (they become Classic blocks in the editor)' );
		$classic = RelatedBlock::add( self::CLASSIC, array( $this->item( 1 ) ), 'Related articles' )['content'];
		$this->assertSame( 1, substr_count( $classic, '<div class="searchpilot-related-links">' ) );
	}

	public function test_legacy_comment_regions_are_replaced_in_place() {
		// What an editor save made of a 1.1-beta comment-marked list.
		$legacy = self::BLOCKS . "\n\n<p><!-- searchpilot:related-links --></p>\n\n" . RelatedBlock::build( array( $this->item( 1 ) ), 'Related articles', true ) . "\n\n<p><!-- /searchpilot:related-links --></p>";
		$again  = RelatedBlock::add( $legacy, array( $this->item( 2 ) ), 'Related articles' );
		$this->assertCount( 2, RelatedBlock::items( $again['content'] ) );
		$this->assertSame( 1, substr_count( $again['content'], '<ul ' ) );
		$this->assertStringNotContainsString( 'searchpilot:related-links', $again['content'] );
	}

	public function test_inline_links_never_go_into_the_list() {
		$out = RelatedBlock::add( self::CLASSIC, array( $this->item( 1, 'Heavy rain in the city' ) ), 'Related articles' );
		// The only "Heavy rain" text outside the list is in the first paragraph.
		$new = LinkInserter::insert( $out['content'], 'heavy rain', 'https://example.com/target/', 9 );
		$this->assertNotNull( $new );
		$this->assertSame( 0, strpos( $new, '<a href="https://example.com/target/" data-spp-link="9">Heavy rain</a>' ) );
		// When the phrase exists only inside the list, nothing is linked.
		$only = RelatedBlock::add( 'Crews worked.', array( $this->item( 1, 'Monsoon update' ) ), 'Related articles' );
		$this->assertNull( LinkInserter::insert( $only['content'], 'monsoon update', 'https://example.com/target/', 9 ) );
		$this->assertNull( LinkInserter::insert( $only['content'], 'related articles', 'https://example.com/target/', 9 ) );
	}

	public function test_default_heading_follows_the_script_without_a_translation() {
		$this->assertSame( 'ಸಂಬಂಧಿತ ಸುದ್ದಿಗಳು', RelatedBlock::default_heading( 'kannada', 'Related articles' ) );
		$this->assertSame( 'Related articles', RelatedBlock::default_heading( 'latin', 'Related articles' ) );
		$this->assertSame( 'Lire aussi', RelatedBlock::default_heading( 'kannada', 'Lire aussi' ), 'A real translation wins' );
	}
}
