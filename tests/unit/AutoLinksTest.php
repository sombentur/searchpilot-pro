<?php
/**
 * Tests for the review/removal of links earlier versions inserted with weak link text.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Links\AutoLinks;
use SearchPilot\Links\ContentIndex;
use SearchPilot\Links\Suggester;

// phpcs:disable

/**
 * AutoLinks (find/unwrap by marker) and Suggester::anchor_matches (1.1 rules) on 1.0.2-style links.
 */
class AutoLinksTest extends \PHPUnit\Framework\TestCase {

	const URL = 'https://example.com/2026/10/01/target/';

	private function phrases( $title ) {
		$words = ContentIndex::key_words( $title );
		$vocab = Suggester::anchor_vocabulary( $words, array( ContentIndex::stem( 'bengaluru' ) => 60, ContentIndex::stem( 'ಬೆಂಗಳೂರು' ) => 60 ), 100 );
		return Suggester::phrases( '', $title, array(), $vocab['singles'], $vocab['generic'] );
	}

	private function content( $anchor_html ) {
		return "<!-- wp:paragraph -->\n<p>" . $anchor_html . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph with a <a href=\"https://example.com/by-hand/\">link a person added</a>.</p>\n<!-- /wp:paragraph -->";
	}

	public function test_the_102_autopilot_anchors_fail_the_11_rules() {
		$metro    = $this->phrases( 'Bengaluru Metro Phase Three Opens' );
		$advisory = $this->phrases( 'Weekend traffic advisory for Bengaluru' );
		foreach ( array( 'across Bengaluru', 'Commuters across', 'Bengaluru describe', 'Bengaluru' ) as $anchor ) {
			$this->assertFalse( Suggester::anchor_matches( $anchor, $metro ), $anchor );
			$this->assertFalse( Suggester::anchor_matches( $anchor, $advisory ), $anchor );
		}
		$this->assertTrue( Suggester::anchor_matches( 'Bengaluru Metro', $metro ) );
		$this->assertTrue( Suggester::anchor_matches( 'weekend traffic advisory', $advisory ) );
		$this->assertTrue( Suggester::anchor_matches( '  Weekend   Traffic Advisory ', $advisory ), 'Case and spacing do not matter' );
		// Kannada: padded around the city name vs a real title phrase in an inflected form.
		$kn = $this->phrases( 'ಬೆಂಗಳೂರು ಮೆಟ್ರೋ ಮೂರನೇ ಹಂತ ಆರಂಭ' );
		$this->assertFalse( Suggester::anchor_matches( 'ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು', $kn ) );
		$this->assertFalse( Suggester::anchor_matches( 'ಬೆಂಗಳೂರಿನಲ್ಲಿ', $kn ), 'The dominant city name alone' );
		$this->assertTrue( Suggester::anchor_matches( 'ಬೆಂಗಳೂರು ಮೆಟ್ರೋದಲ್ಲಿ', $kn ) );
	}

	public function test_find_reads_the_marked_link_only() {
		$content = $this->content( 'Commuters <a href="' . self::URL . '" data-spp-link="12">across Bengaluru</a> describe longer journeys. More text.' );
		$link    = AutoLinks::find( $content, 'data-spp-link="12"' );
		$this->assertSame( 'across Bengaluru', $link['anchor'] );
		$this->assertSame( self::URL, $link['href'] );
		$this->assertSame( 'Commuters across Bengaluru describe longer journeys.', $link['context'] );
		$this->assertNull( AutoLinks::find( $content, 'data-spp-link="1"' ), 'Marker 1 is not marker 12' );
		$this->assertNull( AutoLinks::find( $content, 'data-spp-link="99"' ) );
		// Links a person added carry no marker and are never found.
		$this->assertNull( AutoLinks::find( $content, 'href="https://example.com/by-hand/"' ) );
		// Attributes in another order and inner markup.
		$other = AutoLinks::find( '<p>x <a data-spp-link="fix-7" href="/a?b=1&amp;c=2"><strong>ಬೆಂಗಳೂರಿನ</strong> ಪ್ರಯಾಣಿಕರು</a> y</p>', 'data-spp-link="fix-7"' );
		$this->assertSame( 'ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು', $other['anchor'] );
		$this->assertSame( '/a?b=1&c=2', $other['href'] );
	}

	public function test_remove_unwraps_and_keeps_the_words() {
		$content = $this->content(
			'Commuters <a href="' . self::URL . '" data-spp-link="12">across Bengaluru</a> describe longer journeys, '
			. 'see the <a href="https://example.com/m/" data-spp-link="13">Bengaluru Metro</a> report.'
		);
		$out = AutoLinks::remove( $content, array( 'data-spp-link="12"' ) );
		$this->assertStringContainsString( '<p>Commuters across Bengaluru describe longer journeys, see the <a href="https://example.com/m/" data-spp-link="13">Bengaluru Metro</a> report.</p>', $out );
		$this->assertStringContainsString( '<a href="https://example.com/by-hand/">link a person added</a>', $out, 'Hand-made links untouched' );
		$this->assertSame( substr_count( $content, '<!-- wp:' ), substr_count( $out, '<!-- wp:' ), 'Blocks unchanged' );
		// Kannada text survives byte for byte.
		$kn  = '<p>ನಗರದಲ್ಲಿ <a href="' . self::URL . '" data-spp-link="5">ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು</a> ದೀರ್ಘ ಪ್ರಯಾಣದ ಬಗ್ಗೆ ಹೇಳುತ್ತಾರೆ.</p>';
		$this->assertSame( '<p>ನಗರದಲ್ಲಿ ಬೆಂಗಳೂರಿನ ಪ್ರಯಾಣಿಕರು ದೀರ್ಘ ಪ್ರಯಾಣದ ಬಗ್ಗೆ ಹೇಳುತ್ತಾರೆ.</p>', AutoLinks::remove( $kn, array( 'data-spp-link="5"' ) ) );
		// Invalid markers are ignored (no regex or attribute injection).
		$this->assertSame( $content, AutoLinks::remove( $content, array( 'href', 'data-spp-link="1" onclick="x"', '' ) ) );
		$this->assertFalse( AutoLinks::is_marker( 'data-spp-link="12" x' ) );
		$this->assertTrue( AutoLinks::is_marker( 'data-spp-link="fix-12"' ) );
	}

	public function test_fix_links_are_listed_one_by_one_without_the_related_list() {
		$good    = '<a href="' . self::URL . '" data-spp-link="fix-7">Namma Metro</a>';
		$weak    = '<a href="' . self::URL . '" data-spp-link="fix-7">huge</a>';
		$content = "<!-- wp:paragraph -->\n<p>Riders of " . $good . " waited.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>A " . $weak . " crowd came. <a href=\"https://example.com/by-hand/\">By hand</a> and <a href=\"https://example.com/x/\" data-spp-link=\"12\">a suggestion</a>.</p>\n<!-- /wp:paragraph -->";
		$found   = AutoLinks::fix_links( $content );
		$this->assertSame( array( 'Namma Metro', 'huge' ), array_column( $found, 'anchor' ) );
		$this->assertSame( 'data-spp-link="fix-7"', $found[1]['marker'] );
		$this->assertSame( self::URL, $found[1]['href'] );

		$after = AutoLinks::unwrap( $content, $found[1]['html'] );
		$this->assertSame( str_replace( $weak, 'huge', $content ), $after );
		$this->assertStringContainsString( $good, $after );
		// Not a marked link, or not in the content: nothing changes.
		$this->assertSame( $content, AutoLinks::unwrap( $content, '<a href="https://example.com/by-hand/">By hand</a>' ) );
		$this->assertSame( $content, AutoLinks::unwrap( $content, '<a href="#" data-spp-link="fix-9">gone</a>' ) );
		$this->assertSame( array(), AutoLinks::fix_links( '<p>No <a href="/x/" data-spp-link="12">fix</a> links.</p>' ) );
	}
}
