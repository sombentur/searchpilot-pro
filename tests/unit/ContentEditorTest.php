<?php
/**
 * Tests for the block/classic-aware content editor.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Fixes\ContentEditor;

// phpcs:disable

/**
 * ContentEditor: heading traversal, block comment + tag sync, classic
 * content, skipped regions, idempotent marked blocks, diff fragments.
 */
class ContentEditorTest extends \PHPUnit\Framework\TestCase {

	private static function block( $level, $text, $attrs = null ) {
		$json = null === $attrs ? ( 2 === $level ? '' : '{"level":' . $level . '} ' ) : $attrs . ' ';
		return '<!-- wp:heading ' . $json . "-->\n<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>\n<!-- /wp:heading -->";
	}

	private static function para( $text ) {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
	}

	private static function starts( $prefix, $string ) {
		self::assertTrue( 0 === strpos( (string) $string, $prefix ), 'Expected to start with ' . $prefix . ': ' . substr( (string) $string, 0, 80 ) );
	}

	private static function ends( $suffix, $string ) {
		self::assertTrue( substr( (string) $string, -strlen( $suffix ) ) === $suffix, 'Expected to end with ' . $suffix . ': ' . substr( (string) $string, -80 ) );
	}

	private static function levels( $content ) {
		return array_map(
			static function ( $h ) {
				return $h['level'];
			},
			ContentEditor::headings( $content )
		);
	}

	/* ---------------- headings() ---------------- */

	public function test_headings_lists_block_and_classic_headings_in_order() {
		$content = self::block( 2, 'Intro' ) . "\n\n" . self::para( 'Text' ) . "\n\n" . self::block( 4, 'Deep &amp; <em>detail</em>' );
		$h       = ContentEditor::headings( $content );
		$this->assertCount( 2, $h );
		$this->assertSame( array( 0, 2, 'Intro', true, 'core/heading' ), array( $h[0]['index'], $h[0]['level'], $h[0]['text'], $h[0]['editable'], $h[0]['block'] ) );
		$this->assertSame( 4, $h[1]['level'] );
		$this->assertSame( 'Deep & detail', $h[1]['text'] );
		$this->assertSame( 1, $h[1]['index'] );
	}

	public function test_headings_in_classic_content() {
		$content = "<h1>Title</h1>\n<p>Intro</p>\n<H3 id=\"x\">Upper case tag</H3>\n<h5>Five</h5>";
		$this->assertSame( array( 1, 3, 5 ), self::levels( $content ) );
		$h = ContentEditor::headings( $content );
		$this->assertTrue( $h[1]['editable'] );
		$this->assertSame( '', $h[1]['block'] );
	}

	public function test_kannada_heading_text_is_kept_intact() {
		$text    = 'ಕನ್ನಡ ಸುದ್ದಿ – ಮುಖ್ಯಾಂಶಗಳು';
		$content = self::block( 3, $text );
		$h       = ContentEditor::headings( $content );
		$this->assertSame( $text, $h[0]['text'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function () {
				return 2;
			}
		);
		$this->assertStringContainsString( '<h2 class="wp-block-heading">' . $text . '</h2>', $out );
	}

	public function test_skips_html_blocks_shortcodes_pre_code_comments_and_marked_regions() {
		$content = implode(
			"\n\n",
			array(
				self::block( 2, 'Real' ),
				"<!-- wp:html -->\n<h1>Inside custom HTML</h1>\n<!-- /wp:html -->",
				"<!-- wp:shortcode -->\n[box]<h2>In shortcode block</h2>[/box]\n<!-- /wp:shortcode -->",
				'[accordion title="x"]<h3>In shortcode</h3>[/accordion]',
				'<pre><h4>In pre</h4></pre>',
				'<code><h4>In code</h4></code>',
				'<!-- <h2>commented out</h2> -->',
				"<!-- searchpilot:related-links -->\n<h2>Related</h2>\n<!-- /searchpilot:related-links -->",
				'<script>var x = "<h2>no</h2>";</script>',
				self::block( 3, 'Also real' ),
			)
		);
		$h = ContentEditor::headings( $content );
		$this->assertCount( 2, $h );
		$this->assertSame( 'Real', $h[0]['text'] );
		$this->assertSame( 'Also real', $h[1]['text'] );
		$this->assertSame( 1, $h[1]['index'] );
	}

	public function test_headings_in_nested_layout_blocks_are_editable() {
		$content = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\">" . self::block( 4, 'Nested' ) . "</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns --></div>\n<!-- /wp:group -->";
		$h       = ContentEditor::headings( $content );
		$this->assertCount( 1, $h );
		$this->assertTrue( $h[0]['editable'] );
		$this->assertSame( 'core/heading', $h[0]['block'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function () {
				return 3;
			}
		);
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading">Nested</h3>', $out );
		$this->assertStringContainsString( '<!-- wp:group {"layout":{"type":"constrained"}} -->', $out );
	}

	public function test_third_party_block_headings_are_traversed_but_not_editable() {
		$content = "<!-- wp:kadence/advancedheading {\"level\":3,\"uniqueID\":\"_a1\"} -->\n<h3 class=\"kt-adv-heading_a1\">Third party</h3>\n<!-- /wp:kadence/advancedheading -->\n\n" . self::block( 5, 'Core' );
		$h       = ContentEditor::headings( $content );
		$this->assertCount( 2, $h );
		$this->assertFalse( $h[0]['editable'] );
		$this->assertSame( 'kadence/advancedheading', $h[0]['block'] );
		$calls = array();
		$out   = ContentEditor::set_heading_levels(
			$content,
			static function ( $level, $index, $text ) use ( &$calls ) {
				$calls[] = array( $level, $index, $text );
				return 2;
			}
		);
		$this->assertSame( array( array( 3, 0, 'Third party' ), array( 5, 1, 'Core' ) ), $calls );
		$this->assertStringContainsString( '<h3 class="kt-adv-heading_a1">Third party</h3>', $out );
		$this->assertStringContainsString( "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Core</h2>", $out );
	}

	/* ---------------- RV2: malformed headings are never mis-paired ---------------- */

	public function test_closer_hidden_in_a_shortcode_does_not_pair_with_the_next_block() {
		// rv2_ce case C: the h2's closer sits inside a shortcode (skipped), the next block is an h4.
		$content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Title [tip]x</h2>[/tip]\n<!-- /wp:heading -->\n\n" . self::block( 4, 'Next' );
		$h       = ContentEditor::headings( $content );
		$this->assertCount( 2, $h );
		$this->assertFalse( $h[0]['editable'], 'Interrupted by a block boundary: never edited' );
		$this->assertTrue( $h[1]['editable'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return 2 === $level ? 1 : 3;
			}
		);
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Title [tip]x</h2>[/tip]', $out );
		$this->assertStringContainsString( self::block( 3, 'Next' ), $out );
		$this->assertStringNotContainsString( '</h4>', $out );
		$this->assertSame( ContentEditor::heading_errors( $content ), ContentEditor::heading_errors( $out ) );
	}

	public function test_unclosed_classic_heading_is_traversed_but_not_edited() {
		$content = "<h4>Unclosed heading\n<p>Text</p>\n<h5>Next</h5>\n<p>More</p>";
		$h       = ContentEditor::headings( $content );
		$this->assertSame( array( 4, 5 ), array_column( $h, 'level' ) );
		$this->assertFalse( $h[0]['editable'] );
		$this->assertTrue( $h[1]['editable'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return $level - 1;
			}
		);
		$this->assertSame( "<h4>Unclosed heading\n<p>Text</p>\n<h4>Next</h4>\n<p>More</p>", $out );
	}

	public function test_only_a_same_level_closer_ends_a_heading() {
		$content = "<h2>Title</h3>\n<h3>Sub</h3>";
		$h       = ContentEditor::headings( $content );
		$this->assertFalse( $h[0]['editable'] );
		$this->assertTrue( $h[1]['editable'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return 3 === $level ? 4 : 1;
			}
		);
		$this->assertSame( "<h2>Title</h3>\n<h4>Sub</h4>", $out );
		// Inline code inside a heading is fine; a heading tag hidden in it is not.
		$ok = ContentEditor::headings( '<h3>Use <code>x</code> here</h3>' );
		$this->assertTrue( $ok[0]['editable'] );
		$tainted = ContentEditor::headings( '<h3>Raw <code></h3></code> text</h3>' );
		$this->assertFalse( $tainted[0]['editable'] );
	}

	public function test_comment_straddling_a_heading_makes_it_read_only() {
		$content = "<h3>Start <!-- note </h3> -->\n<p>x</p>";
		$h       = ContentEditor::headings( $content );
		$this->assertCount( 1, $h );
		$this->assertFalse( $h[0]['editable'] );
		$this->assertSame(
			$content,
			ContentEditor::set_heading_levels(
				$content,
				static function () {
					return 2;
				}
			)
		);
	}

	public function test_heading_errors_counts_imbalance_and_level_mismatch() {
		$this->assertSame( 0, ContentEditor::heading_errors( self::block( 3, 'Fine' ) . "\n<h2>Classic</h2>" ) );
		$this->assertSame( 0, ContentEditor::heading_errors( '<p>No headings</p>' ) );
		$this->assertGreaterThan( 0, ContentEditor::heading_errors( "<!-- wp:heading {\"level\":3} -->\n<h2>Mismatch</h2>\n<!-- /wp:heading -->" ) );
		$this->assertGreaterThan( 0, ContentEditor::heading_errors( '<h2>Open</h4>' ) );
		// Fixing an already-mismatched block (attr 3, tag 2) brings both to the new level.
		$bad = "<!-- wp:heading {\"level\":3} -->\n<h2 class=\"wp-block-heading\">Mismatch</h2>\n<!-- /wp:heading -->";
		$out = ContentEditor::set_heading_levels(
			$bad,
			static function () {
				return 4;
			}
		);
		$this->assertSame( 0, ContentEditor::heading_errors( $out ) );
		$this->assertStringContainsString( '{"level":4}', $out );
	}

	/* ---------------- set_heading_levels() ---------------- */

	public function test_map_is_called_once_per_heading_in_document_order() {
		$content = "<h2>A</h2>\n" . self::block( 3, 'B' ) . "\n<h4>C</h4>";
		$calls   = array();
		ContentEditor::set_heading_levels(
			$content,
			static function ( $level, $index, $text ) use ( &$calls ) {
				$calls[] = $index . ':' . $level . ':' . $text;
				return $level;
			}
		);
		$this->assertSame( array( '0:2:A', '1:3:B', '2:4:C' ), $calls );
	}

	public function test_unchanged_content_is_byte_identical() {
		$content = self::block( 2, 'A' ) . "\r\n\r\n" . "<h3 data-x='1'>B</h3>" . "\n" . self::block( 4, 'C', '{"textAlign":"center","level":4}' );
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return $level;
			}
		);
		$this->assertSame( $content, $out );
		// Invalid return values leave headings untouched too.
		$out = ContentEditor::set_heading_levels(
			$content,
			static function () {
				return 9;
			}
		);
		$this->assertSame( $content, $out );
	}

	public function test_block_level_attribute_and_tag_stay_in_sync() {
		$content = self::block( 2, 'Intro' ) . "\n\n" . self::block( 4, 'Skipped level' );
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function ( $level, $index ) {
				return 1 === $index ? 3 : $level;
			}
		);
		$this->assertSame( self::block( 2, 'Intro' ) . "\n\n" . self::block( 3, 'Skipped level' ), $out );
		$this->assertSame( array( 2, 3 ), self::levels( $out ) );
	}

	public function test_level_two_drops_the_attribute_and_other_attributes_survive() {
		$content = self::block( 1, 'Big', '{"textAlign":"center","level":1,"className":"x--y"}' );
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function () {
				return 2;
			}
		);
		$this->assertStringContainsString( '<!-- wp:heading {"textAlign":"center","className":"x\u002d\u002dy"} -->', $out );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Big</h2>', $out );

		$plain = ContentEditor::set_heading_levels(
			self::block( 1, 'Only level' ),
			static function () {
				return 2;
			}
		);
		self::starts( "<!-- wp:heading -->\n<h2", $plain );
	}

	public function test_default_level_two_block_gets_an_attribute_when_changed() {
		$out = ContentEditor::set_heading_levels(
			self::block( 2, 'Default' ),
			static function () {
				return 3;
			}
		);
		$this->assertSame( self::block( 3, 'Default' ), $out );
	}

	public function test_classic_content_changes_tags_only_and_keeps_attributes() {
		$content = "<p>x</p>\n<h1 class=\"title\" id=\"t\">Title</h1>\n<p>y</p>\n<h4 style=\"color:red\">Sub</h4 >";
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return 1 === $level ? 2 : 3;
			}
		);
		$this->assertSame( "<p>x</p>\n<h2 class=\"title\" id=\"t\">Title</h2>\n<p>y</p>\n<h3 style=\"color:red\">Sub</h3 >", $out );
		$this->assertStringNotContainsString( 'wp:heading', $out );
	}

	public function test_demote_h1_and_clamp_skipped_levels_like_a_planner() {
		$content = self::block( 1, 'Extra H1' ) . "\n" . self::block( 2, 'Section' ) . "\n" . self::block( 5, 'Too deep' ) . "\n" . self::block( 6, 'Deeper' );
		$prev    = 1;
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) use ( &$prev ) {
				$new  = max( 2, min( $level, $prev + 1 ) );
				$prev = $new;
				return $new;
			}
		);
		$this->assertSame( array( 2, 2, 3, 4 ), self::levels( $out ) );
		// Idempotent: a second pass changes nothing.
		$prev  = 1;
		$again = ContentEditor::set_heading_levels(
			$out,
			static function ( $level ) use ( &$prev ) {
				$new  = max( 2, min( $level, $prev + 1 ) );
				$prev = $new;
				return $new;
			}
		);
		$this->assertSame( $out, $again );
	}

	public function test_core_namespace_spelling_and_unreadable_attributes() {
		$content = "<!-- wp:core/heading {\"level\":4} -->\n<h4>Spelled out</h4>\n<!-- /wp:core/heading -->";
		$out     = ContentEditor::set_heading_levels(
			$content,
			static function () {
				return 3;
			}
		);
		$this->assertSame( "<!-- wp:core/heading {\"level\":3} -->\n<h3>Spelled out</h3>\n<!-- /wp:core/heading -->", $out );

		// Attributes that are not valid JSON: neither the comment nor the tag is changed.
		$broken = "<!-- wp:heading {\"level\":4,} -->\n<h4>Broken</h4>\n<!-- /wp:heading -->";
		$same   = ContentEditor::set_heading_levels(
			$broken,
			static function () {
				return 3;
			}
		);
		$this->assertSame( $broken, $same );
	}

	public function test_mixed_block_and_freeform_content() {
		$content = self::para( 'a' ) . "\n\n<h4>Freeform between blocks</h4>\n\n" . self::block( 2, 'b' );
		$h       = ContentEditor::headings( $content );
		$this->assertTrue( $h[0]['editable'] );
		$this->assertSame( '', $h[0]['block'] );
		$out = ContentEditor::set_heading_levels(
			$content,
			static function ( $level ) {
				return 4 === $level ? 3 : $level;
			}
		);
		$this->assertStringContainsString( '<h3>Freeform between blocks</h3>', $out );
		$this->assertStringContainsString( self::block( 2, 'b' ), $out );
	}

	public function test_is_block_content() {
		$this->assertTrue( ContentEditor::is_block_content( self::para( 'x' ) ) );
		$this->assertFalse( ContentEditor::is_block_content( '<p>classic</p><!-- more -->' ) );
	}

	/* ---------------- replace_marked_block() ---------------- */

	private static function group( $marker, $inner ) {
		return '<!-- wp:group {"className":"searchpilot-' . $marker . '","metadata":{"name":"SearchPilot: ' . $marker . '"}} --><div class="wp-block-group searchpilot-' . $marker . '">' . $inner . '</div><!-- /wp:group -->';
	}

	public function test_block_content_region_is_a_core_group_and_idempotent() {
		$content = self::para( 'Body' );
		$list    = "<!-- wp:list -->\n<ul><li><a href=\"https://example.com/a/\">A</a></li></ul>\n<!-- /wp:list -->";
		$once    = ContentEditor::replace_marked_block( $content, 'related-links', $list );
		$this->assertSame( $content . "\n\n" . self::group( 'related-links', $list ), $once );
		$this->assertStringNotContainsString( '<!-- searchpilot:', $once );
		$twice = ContentEditor::replace_marked_block( $once, 'related-links', $list );
		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'wp:group {"className":"searchpilot-related-links"' ) );
		$this->assertSame( $list, ContentEditor::get_marked_block( $twice, 'related-links' ) );

		$new     = "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->";
		$changed = ContentEditor::replace_marked_block( $once, 'related-links', $new );
		$this->assertSame( $content . "\n\n" . self::group( 'related-links', $new ), $changed );
		$this->assertTrue( ContentEditor::has_marked_block( $changed, 'related-links' ) );
		$this->assertFalse( ContentEditor::has_marked_block( $changed, 'other' ) );
	}

	public function test_block_region_already_wrapped_is_not_double_wrapped() {
		$region = self::group( 'related-links', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$out    = ContentEditor::replace_marked_block( self::para( 'Body' ), 'related-links', $region );
		$this->assertSame( 1, substr_count( $out, '<!-- wp:group' ) );
		$this->assertSame( $out, ContentEditor::replace_marked_block( $out, 'related-links', $region ) );
	}

	public function test_block_region_with_nested_groups_is_found_depth_aware() {
		$inner   = "<!-- wp:group {\"className\":\"cards\"} --><div class=\"wp-block-group cards\"><!-- wp:group --><div class=\"wp-block-group\"><!-- wp:paragraph --><p>Card</p><!-- /wp:paragraph --></div><!-- /wp:group --></div><!-- /wp:group -->";
		$content = self::para( 'Body' ) . "\n\n" . self::group( 'related-links', $inner ) . "\n\n" . self::para( 'After' );
		$this->assertSame( $inner, ContentEditor::get_marked_block( $content, 'related-links' ) );
		$out = ContentEditor::replace_marked_block( $content, 'related-links', '<!-- wp:paragraph --><p>Swap</p><!-- /wp:paragraph -->' );
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::group( 'related-links', '<!-- wp:paragraph --><p>Swap</p><!-- /wp:paragraph -->' ) . "\n\n" . self::para( 'After' ), $out );
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::para( 'After' ), ContentEditor::replace_marked_block( $content, 'related-links', '' ) );
		// A group with another class, or the same class as part of a longer one, is not the region.
		$other = '<!-- wp:group {"className":"searchpilot-related-links-old"} --><div class="wp-block-group searchpilot-related-links-old"></div><!-- /wp:group -->';
		$this->assertFalse( ContentEditor::has_marked_block( $other, 'related-links' ) );
	}

	public function test_classic_content_region_is_a_div_and_depth_aware() {
		$content = "<p>Classic body</p>";
		$html    = '<div class="card"><div class="inner"><a href="https://example.com/a/">A</a></div></div><p>More</p>';
		$once    = ContentEditor::replace_marked_block( $content, 'related-links', $html );
		$this->assertSame( $content . "\n\n" . '<div class="searchpilot-related-links">' . $html . '</div>', $once );
		$this->assertSame( $once, ContentEditor::replace_marked_block( $once, 'related-links', $html ) );
		$this->assertSame( $html, ContentEditor::get_marked_block( $once, 'related-links' ) );
		$swap = ContentEditor::replace_marked_block( $once . "\n<p>Tail</p>", 'related-links', '<p>New</p>' );
		$this->assertSame( $content . "\n\n" . '<div class="searchpilot-related-links"><p>New</p></div>' . "\n<p>Tail</p>", $swap );
		$this->assertStringNotContainsString( 'wp:group', $swap );
		// Class attribute with other classes and single quotes is recognised.
		$single = "<p>x</p>\n\n<div class='box searchpilot-related-links' id='r'><p>Old</p></div>";
		$this->assertSame( "<p>x</p>\n\n<div class=\"searchpilot-related-links\"><p>N</p></div>", ContentEditor::replace_marked_block( $single, 'related-links', '<p>N</p>' ) );
	}

	public function test_legacy_comment_regions_are_replaced_by_the_new_form() {
		$legacy = "<!-- searchpilot:related-links -->\n<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->\n<!-- /searchpilot:related-links -->";
		$block  = self::para( 'Body' ) . "\n\n" . $legacy;
		$new    = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->';
		$out    = ContentEditor::replace_marked_block( $block, 'related-links', $new );
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::group( 'related-links', $new ), $out );
		$this->assertSame( self::para( 'Body' ), ContentEditor::replace_marked_block( $block, 'related-links', '' ) );

		$classic = "<p>Classic</p>\n\n<!-- searchpilot:related-links -->\n<p>Old</p>\n<!-- /searchpilot:related-links -->";
		$this->assertSame( "<p>Classic</p>\n\n<div class=\"searchpilot-related-links\"><p>New</p></div>", ContentEditor::replace_marked_block( $classic, 'related-links', '<p>New</p>' ) );
		$this->assertSame( '<p>Old</p>', ContentEditor::get_marked_block( $classic, 'related-links' ) );
	}

	public function test_editor_mangled_legacy_markers_are_replaced_and_removed() {
		// What the block editor makes of bare marker comments between blocks after a save.
		$group   = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$mangled = self::para( 'Body' ) . "\n\n<p><!-- searchpilot:related-links --></p>\n\n" . $group . "\n\n<p>\n<!-- /searchpilot:related-links -->\n</p>\n\n" . self::para( 'After' );
		$new     = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->';
		$out     = ContentEditor::replace_marked_block( $mangled, 'related-links', $new );
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::group( 'related-links', $new ) . "\n\n" . self::para( 'After' ), $out );
		$this->assertStringNotContainsString( '<p><!--', $out );
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::para( 'After' ), ContentEditor::replace_marked_block( $mangled, 'related-links', '' ) );
		// Also when the markers ended up in paragraph blocks.
		$para_form = self::para( 'Body' ) . "\n\n<!-- wp:paragraph -->\n<p><!-- searchpilot:related-links --></p>\n<!-- /wp:paragraph -->\n\n" . $group . "\n\n<!-- wp:paragraph -->\n<p><!-- /searchpilot:related-links --></p>\n<!-- /wp:paragraph -->";
		$this->assertSame( self::para( 'Body' ) . "\n\n" . self::group( 'related-links', $new ), ContentEditor::replace_marked_block( $para_form, 'related-links', $new ) );
	}

	public function test_empty_html_removes_the_region_and_round_trips_byte_identically() {
		foreach ( array( "<p>Classic body</p>\n", self::para( 'Block body' ) ) as $content ) {
			$with = ContentEditor::replace_marked_block( $content, 'related-links', '<p>x</p>' );
			$this->assertNotSame( $content, $with );
			$this->assertSame( $content, ContentEditor::replace_marked_block( $with, 'related-links', '' ) );
			// Removing a region that does not exist changes nothing.
			$this->assertSame( $content, ContentEditor::replace_marked_block( $content, 'related-links', '' ) );
		}
		// A region at the very start.
		$start = "<div class=\"searchpilot-x\"><p>a</p></div>\n\n<p>Body</p>";
		$this->assertSame( '<p>Body</p>', ContentEditor::replace_marked_block( $start, 'x', '' ) );
	}

	public function test_duplicate_regions_of_any_form_collapse_to_one() {
		$legacy  = "<!-- searchpilot:related-links -->\n<p>Copy</p>\n<!-- /searchpilot:related-links -->";
		$content = self::para( 'A' ) . "\n\n" . self::group( 'related-links', '<p>One</p>' ) . "\n\n" . self::para( 'B' ) . "\n\n" . $legacy;
		$out     = ContentEditor::replace_marked_block( $content, 'related-links', '<p>Fresh</p>' );
		$this->assertSame( self::para( 'A' ) . "\n\n" . self::group( 'related-links', '<p>Fresh</p>' ) . "\n\n" . self::para( 'B' ), $out );
	}

	public function test_markers_are_independent_and_sanitised() {
		$content = ContentEditor::replace_marked_block( '<p>x</p>', 'related-links', '<p>r</p>' );
		$content = ContentEditor::replace_marked_block( $content, 'faq', '<p>f</p>' );
		$this->assertSame( '<p>r</p>', ContentEditor::get_marked_block( $content, 'related-links' ) );
		$this->assertSame( '<p>f</p>', ContentEditor::get_marked_block( $content, 'faq' ) );
		// Regex characters are stripped from markers; an empty marker changes nothing.
		$this->assertSame( '<p>x</p>', ContentEditor::replace_marked_block( '<p>x</p>', '.*', '<p>y</p>' ) );
		$this->assertSame( 'searchpilot-related-links', ContentEditor::marker_class( 'Related-Links' ) );
	}

	public function test_empty_content_gets_only_the_region() {
		$this->assertSame( '<div class="searchpilot-m"><p>x</p></div>', ContentEditor::replace_marked_block( '', 'm', "\n<p>x</p>\n" ) );
	}

	public function test_headings_inside_marked_regions_of_every_form_are_not_traversed() {
		$block = ContentEditor::replace_marked_block( self::block( 3, 'Body heading' ), 'related-links', self::block( 2, 'Related articles' ) );
		$this->assertSame( array( 3 ), self::levels( $block ) );
		$classic = ContentEditor::replace_marked_block( '<h3>Body</h3>', 'related-links', '<h2>Related</h2><div><h4>Nested</h4></div>' );
		$this->assertSame( array( 3 ), self::levels( $classic ) );
		$mangled = "<h3>Body</h3>\n<p><!-- searchpilot:related-links --></p>\n<h2>Related</h2>\n<p><!-- /searchpilot:related-links --></p>\n<h4>After</h4>";
		$this->assertSame( array( 3, 4 ), self::levels( $mangled ) );
		// set_heading_levels() leaves the region alone.
		$out = ContentEditor::set_heading_levels(
			$block,
			static function () {
				return 4;
			}
		);
		$this->assertStringContainsString( self::block( 2, 'Related articles' ), $out );
	}

	/* ---------------- fragments() ---------------- */

	public function test_fragments_show_changed_lines_with_context() {
		$old = implode( "\n", array( 'l1', 'l2', 'l3', '<h4>Old</h4>', 'l5', 'l6', 'l7' ) );
		$new = implode( "\n", array( 'l1', 'l2', 'l3', '<h3>Old</h3>', 'l5', 'l6', 'l7' ) );
		list( $before, $after ) = ContentEditor::fragments( $old, $new );
		$this->assertSame( "…\nl3\n<h4>Old</h4>\nl5\n…", $before );
		$this->assertSame( "…\nl3\n<h3>Old</h3>\nl5\n…", $after );
	}

	public function test_fragments_of_separate_changes_are_joined_with_gaps() {
		$lines = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$lines[] = 'line ' . $i;
		}
		$new      = $lines;
		$new[2]   = 'changed 2';
		$new[15]  = 'changed 15';
		list( $before, $after ) = ContentEditor::fragments( implode( "\n", $lines ), implode( "\n", $new ) );
		$this->assertStringContainsString( "line 2\n", $before );
		$this->assertStringContainsString( 'line 15', $before );
		$this->assertStringContainsString( 'changed 2', $after );
		$this->assertStringContainsString( 'changed 15', $after );
		$this->assertStringContainsString( "…", $after );
		$this->assertStringNotContainsString( 'line 9', $after );
	}

	public function test_fragments_of_identical_content_are_empty_and_long_lines_are_clipped() {
		$this->assertSame( array( '', '' ), ContentEditor::fragments( 'same', 'same' ) );
		$long = str_repeat( 'ಕನ್ನಡ ', 300 );
		list( $before, $after ) = ContentEditor::fragments( $long . 'END', $long . 'FIN' );
		$this->assertLessThan( 600, strlen( $before ) );
		self::starts( '…', $before );
		self::ends( 'END', $before );
		self::ends( 'FIN', $after );
		$this->assertTrue( mb_check_encoding( $before, 'UTF-8' ) && mb_check_encoding( $after, 'UTF-8' ) );
	}

	public function test_appended_region_fragment() {
		$old = self::para( 'Body' );
		$new = ContentEditor::replace_marked_block( $old, 'related-links', '<p>Related</p>' );
		list( $before, $after ) = ContentEditor::fragments( $old, $new );
		$this->assertStringContainsString( 'searchpilot-related-links', $after );
		$this->assertStringNotContainsString( 'searchpilot', $before );
	}
}
