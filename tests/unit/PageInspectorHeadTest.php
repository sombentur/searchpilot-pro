<?php
/**
 * Tests for the rendered-head facts of the page inspector (what a page serves).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Indexer\PageInspector;

/**
 * PageInspector title/description facts.
 */
class PageInspectorHeadTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Analyses a 200 HTML page.
	 *
	 * @param string $head Head markup.
	 * @return array
	 */
	private function facts( $head ) {
		$GLOBALS['spp_test_home'] = 'https://example.com/';
		$html                     = '<!doctype html><html><head><meta charset="utf-8">' . $head . '</head><body><main><h1>X</h1><p>Words.</p></main></body></html>';
		return ( new PageInspector() )->analyze(
			array(
				'url'          => 'https://example.com/a/',
				'final_url'    => 'https://example.com/a/',
				'status'       => 200,
				'first_status' => 200,
				'headers'      => array( 'content-type' => 'text/html; charset=UTF-8' ),
				'body'         => $html,
				'hops'         => array(),
			),
			array( 'indexable' => true )
		);
	}

	public function test_served_description_is_recorded_decoded() {
		$f = $this->facts( '<title>Rain &#8211; City</title><meta name="description" content="It&#8217;s raining  in&#10;Bengaluru &amp; Mysuru">' );
		$this->assertSame( 'Rain – City', $f['title'] );
		$this->assertSame( 'It’s raining in Bengaluru & Mysuru', $f['description'] );
		$this->assertSame( 1, $f['description_count'] );
	}

	public function test_kannada_description_keeps_combining_marks() {
		$f = $this->facts( '<title>ಕನ್ನಡ ಸುದ್ದಿ</title><meta name="description" content="ಬೆಂಗಳೂರಿನ ಮಳೆ ಮತ್ತು ಕೆರೆಗಳ ಬಗ್ಗೆ">' );
		$this->assertSame( 'ಕನ್ನಡ ಸುದ್ದಿ', $f['title'] );
		$this->assertSame( 'ಬೆಂಗಳೂರಿನ ಮಳೆ ಮತ್ತು ಕೆರೆಗಳ ಬಗ್ಗೆ', $f['description'] );
	}

	public function test_first_of_several_descriptions_and_none() {
		$f = $this->facts( '<title>T</title><meta name="description" content="first"><meta name="description" content="second">' );
		$this->assertSame( 'first', $f['description'] );
		$this->assertSame( 2, $f['description_count'] );
		$g = $this->facts( '<title>T</title>' );
		$this->assertSame( '', $g['description'] );
		$this->assertSame( 0, $g['description_count'] );
	}

	public function test_storable_keeps_description() {
		$f = PageInspector::storable( $this->facts( '<title>T</title><meta name="description" content="kept">' ) );
		$this->assertSame( 'kept', $f['description'] );
		$this->assertArrayHasKey( 'title', $f );
	}
}
