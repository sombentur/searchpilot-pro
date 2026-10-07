<?php
/**
 * Tests for the on-page analyzer (pure evaluation).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Seo\Analysis\Analyzer;
use SearchPilot\Support\Text;

// phpcs:disable

/**
 * Analyzer::evaluate() on prepared contexts: checks, statuses, score, Kannada.
 */
class AnalyzerTest extends \PHPUnit\Framework\TestCase {

	const NOW = 1790000000; // 2026-09-21.

	private function find_check( array $result, $id ) {
		foreach ( $result['checks'] as $check ) {
			if ( $check['id'] === $id ) {
				return $check;
			}
		}
		return null;
	}

	private function english_body( $paragraphs = 8 ) {
		$sentences = array(
			'Running shoes protect your feet on long runs and short jogs alike.',
			'A good pair of shoes fits snugly at the heel and leaves room for your toes.',
			'Try them on in the afternoon, when your feet are slightly larger.',
			'Cushioning matters most for beginners who run on hard pavement.',
			'Replace your pair after about eight hundred kilometres of running.',
			'Trail models add grip, while road models are lighter and more flexible.',
		);
		$html = '';
		for ( $i = 0; $i < $paragraphs; $i++ ) {
			$html .= '<p>' . implode( ' ', array_slice( array_merge( $sentences, $sentences ), $i % 6, 5 ) ) . '</p>';
			if ( 2 === $i ) {
				$html .= '<h2>How to choose running shoes</h2>';
			}
			if ( 5 === $i ) {
				$html .= '<h3>Fit and comfort</h3>';
			}
		}
		return $html;
	}

	private function good_context() {
		$html  = '<p>Choosing running shoes is the first step for every new runner. This guide explains fit, cushioning and grip.</p>';
		$html .= $this->english_body();
		$html .= '<p>Read our <a href="https://example.com/marathon-training/">marathon training plan</a> and the <a href="https://example.com/stretching-guide/">stretching guide</a>, or see the <a href="https://www.nhs.uk/live-well/exercise/" rel="noopener" target="_blank">NHS advice on exercise</a>.</p>';
		$html .= '<div class="wp-block-image"><img class="wp-image-12" src="https://example.com/wp-content/uploads/shoes.webp" alt="Two pairs of running shoes on a track" srcset="a 300w"></div>';
		return array(
			'post_id'            => 5,
			'post_type'          => 'post',
			'status'             => 'publish',
			'title'              => 'Best Running Shoes for Beginners',
			'seo_title'          => 'Best Running Shoes for Beginners: How to Choose',
			'description'        => 'Running shoes for beginners: how to find the right fit, cushioning and grip, and when to replace your pair. A practical guide.',
			'description_source' => 'custom',
			'focus'              => 'running shoes',
			'slug'               => 'running-shoes-beginners',
			'url'                => 'https://example.com/running-shoes-beginners/',
			'html'               => $html,
			'featured'           => array( 'id' => 12, 'width' => 1600, 'height' => 900, 'alt' => 'Running shoes', 'filesize' => 120000, 'mime' => 'image/webp', 'file' => 'shoes.webp' ),
			'attachments'        => array( 12 => array( 'id' => 12, 'width' => 1600, 'filesize' => 120000, 'mime' => 'image/webp', 'file' => 'shoes.webp', 'alt' => 'Running shoes' ) ),
			'link_status'        => array(
				'https://example.com/marathon-training/' => array( 'target_id' => 7, 'status' => 'ok' ),
				'https://example.com/stretching-guide/'  => array( 'target_id' => 8, 'status' => 'ok' ),
			),
			'duplicates'         => array( 'title' => array(), 'description' => array(), 'content' => array() ),
			'modified'           => self::NOW - 10 * DAY_IN_SECONDS,
			'now'                => self::NOW,
			'thin_words'         => 300,
			'schema_type'        => 'Article',
		);
	}

	public function test_well_optimized_english_post_scores_good_with_contract_shape() {
		$r = Analyzer::evaluate( $this->good_context() );
		foreach ( array( 'score', 'grade', 'disclaimer', 'focus', 'effective', 'checks', 'groups', 'stats', 'queries' ) as $key ) {
			$this->assertArrayHasKey( $key, $r );
		}
		$this->assertGreaterThanOrEqual( 80, $r['score'], json_encode( array_values( array_filter( $r['checks'], function ( $c ) { return 'pass' !== $c['status']; } ) ) ) );
		$this->assertSame( 'good', $r['grade'] );
		$this->assertStringContainsString( 'not a Google or Bing ranking score', $r['disclaimer'] );
		$this->assertCount( 10, $r['groups'] );
		$this->assertSame( array( 'title', 'description', 'content', 'links', 'images', 'technical', 'social', 'schema', 'freshness', 'duplicates' ), array_column( $r['groups'], 'id' ) );
		foreach ( $r['checks'] as $check ) {
			foreach ( array( 'id', 'group', 'label', 'status', 'message', 'recommendation', 'weight' ) as $key ) {
				$this->assertArrayHasKey( $key, $check );
			}
			$this->assertContains( $check['status'], array( 'pass', 'warning', 'fail', 'info' ) );
		}
		$this->assertSame( 'pass', $this->find_check( $r, 'title_focus' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'focus_intro' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'internal_links' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'image_alt' )['status'] );
		$this->assertNotNull( $this->find_check( $r, 'readability' ) );
		$this->assertSame( 2, $r['stats']['internal_links'] );
		$this->assertSame( 1, $r['stats']['external_links'] );
		$this->assertTrue( $r['stats']['latin'] );
		$this->assertGreaterThan( 300, $r['stats']['words'] );
		$this->assertArrayHasKey( 'h2', $r['stats']['headings'] );
	}

	public function test_missing_basics_score_poor() {
		$r = Analyzer::evaluate(
			array(
				'post_type'          => 'post',
				'status'             => 'publish',
				'seo_title'          => '',
				'description'        => '',
				'description_source' => 'none',
				'html'               => '<p>Just a few words here, nothing else to read on this page at all.</p>',
				'url'                => 'https://example.com/x/',
				'now'                => self::NOW,
			)
		);
		$this->assertSame( 'poor', $r['grade'] );
		$this->assertSame( 'fail', $this->find_check( $r, 'title_present' )['status'] );
		$this->assertSame( 'fail', $this->find_check( $r, 'description_present' )['status'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'content_length' )['status'], 'Word count alone never fails a page.' );
		$this->assertSame( 'fail', $this->find_check( $r, 'internal_links' )['status'] );
		$this->assertSame( 'fail', $this->find_check( $r, 'featured_image' )['status'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'focus_set' )['status'] );
	}

	public function test_keyword_stuffing_warns_to_write_naturally() {
		$ctx         = $this->good_context();
		$ctx['html'] = '<p>' . str_repeat( 'Running shoes are great. Buy running shoes. ', 40 ) . '</p>';
		$r           = Analyzer::evaluate( $ctx );
		$density     = $this->find_check( $r, 'focus_density' );
		$this->assertSame( 'warning', $density['status'] );
		$this->assertStringContainsString( 'keyword stuffing', $density['message'] );
		$this->assertGreaterThan( 3, $r['stats']['focus_density'] );
	}

	public function test_kannada_post_uses_unicode_tokenization_and_skips_english_formulas() {
		$title = 'ಬೆಂಗಳೂರು ಮಳೆ: ನಗರದಲ್ಲಿ ಭಾರೀ ಮಳೆಯಿಂದ ಸಂಚಾರ ಅಸ್ತವ್ಯಸ್ತ';
		$p1    = 'ಬೆಂಗಳೂರು ಮಳೆ ಇಂದು ಬೆಳಿಗ್ಗೆಯಿಂದ ಜೋರಾಗಿ ಸುರಿಯುತ್ತಿದೆ. ನಗರದ ಹಲವು ರಸ್ತೆಗಳಲ್ಲಿ ನೀರು ನಿಂತಿದ್ದು ವಾಹನ ಸವಾರರು ಪರದಾಡುತ್ತಿದ್ದಾರೆ.';
		$p2    = 'ಹವಾಮಾನ ಇಲಾಖೆ ಮುಂದಿನ ಮೂರು ದಿನಗಳ ಕಾಲ ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ ಮುಂದುವರಿಯಲಿದೆ ಎಂದು ತಿಳಿಸಿದೆ। ಜನರು ಅನಗತ್ಯವಾಗಿ ಮನೆಯಿಂದ ಹೊರಗೆ ಬರಬಾರದು ಎಂದು ಸಲಹೆ ನೀಡಲಾಗಿದೆ.';
		$p3    = 'ಮೆಜೆಸ್ಟಿಕ್, ಕೆ.ಆರ್. ಮಾರುಕಟ್ಟೆ ಮತ್ತು ಸಿಲ್ಕ್ ಬೋರ್ಡ್ ಬಳಿ ಭಾರೀ ಸಂಚಾರ ದಟ್ಟಣೆ ಉಂಟಾಗಿದೆ. ಸಂಚಾರ ಪೊಲೀಸರು ಪರ್ಯಾಯ ಮಾರ್ಗಗಳನ್ನು ಸೂಚಿಸಿದ್ದಾರೆ.';
		$html  = '<p>' . $p1 . '</p><p>' . $p2 . '</p><h2>ಬೆಂಗಳೂರು ಮಳೆ: ಸಂಚಾರ ದಟ್ಟಣೆ</h2><p>' . $p3 . '</p>'
			. '<p><a href="https://example.com/%e0%b2%b9%e0%b2%b5%e0%b2%be%e0%b2%ae%e0%b2%be%e0%b2%a8/">ಹವಾಮಾನ ಮುನ್ಸೂಚನೆ</a> ಮತ್ತು <a href="https://example.com/traffic/">ಸಂಚಾರ ಮಾಹಿತಿ</a> ಓದಿ.</p>'
			. '<p><a href="https://example.com/more/">ಇಲ್ಲಿ ಕ್ಲಿಕ್ ಮಾಡಿ</a></p>';
		$r = Analyzer::evaluate(
			array(
				'post_type'          => 'post',
				'status'             => 'publish',
				'title'              => $title,
				'seo_title'          => $title,
				'description'        => 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ ಅಬ್ಬರ: ನಗರದ ಹಲವು ರಸ್ತೆಗಳು ಜಲಾವೃತ, ಸಂಚಾರ ದಟ್ಟಣೆ. ಹವಾಮಾನ ಇಲಾಖೆ ಮುಂದಿನ ಮೂರು ದಿನ ಮಳೆ ಮುನ್ಸೂಚನೆ ನೀಡಿದೆ. ಸಂಚಾರ ಪೊಲೀಸರ ಸಲಹೆ ಇಲ್ಲಿದೆ, ಓದಿ.',
				'description_source' => 'custom',
				'focus'              => 'ಬೆಂಗಳೂರು ಮಳೆ',
				'slug'               => '%e0%b2%ac%e0%b3%86%e0%b2%82%e0%b2%97%e0%b2%b3%e0%b3%82%e0%b2%b0%e0%b3%81-%e0%b2%ae%e0%b2%b3%e0%b3%86',
				'url'                => 'https://example.com/%e0%b2%ac%e0%b3%86%e0%b2%82%e0%b2%97%e0%b2%b3%e0%b3%82%e0%b2%b0%e0%b3%81-%e0%b2%ae%e0%b2%b3%e0%b3%86/',
				'html'               => $html,
				'thin_words'         => 50,
				'now'                => self::NOW,
				'featured'           => array( 'id' => 3, 'width' => 1200, 'alt' => 'ಮಳೆ', 'filesize' => 90000, 'mime' => 'image/jpeg' ),
			)
		);
		$this->assertFalse( $r['stats']['latin'] );
		$this->assertNull( $r['stats']['flesch'] );
		$this->assertSame( 'info', $this->find_check( $r, 'readability' )['status'] );
		$this->assertSame( 0, $this->find_check( $r, 'readability' )['weight'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'title_focus' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'focus_intro' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'focus_subheadings' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'description_focus' )['status'], 'Inflected ಬೆಂಗಳೂರಿನಲ್ಲಿ ... ಮಳೆ matches the focus stems.' );
		// ಬೆಂಗಳೂರು ಮಳೆ ×2 in text + ಬೆಂಗಳೂರಿನಲ್ಲಿ ಮಳೆ + heading occurrence.
		$this->assertGreaterThanOrEqual( 3, $r['stats']['focus_occurrences'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'slug' )['status'], 'Kannada slugs are not penalized.' );
		$this->assertSame( 'warning', $this->find_check( $r, 'anchor_text' )['status'], 'Kannada generic anchor detected.' );
		$this->assertSame( 3, $r['stats']['internal_links'] );
		$this->assertLessThan( mb_strlen( $title, 'UTF-8' ), Analyzer::chars( $title ), 'Title length counts graphemes, not code points.' );
		$this->assertGreaterThan( 50, $r['stats']['words'] );
		$this->assertLessThan( 80, $r['stats']['words'] );
	}

	public function test_density_never_asks_for_more_repetitions() {
		$ctx         = $this->good_context();
		$ctx['html'] = '<p>' . str_repeat( 'Good shoes help beginners run in comfort every single day. ', 30 ) . ' Running shoes matter.</p>';
		$density     = $this->find_check( Analyzer::evaluate( $ctx ), 'focus_density' );
		$this->assertSame( 'pass', $density['status'], 'Low density is fine.' );

		$ctx['html'] = '<p>' . str_repeat( 'Good shoes help beginners run in comfort every single day. ', 30 ) . '</p>';
		$density     = $this->find_check( Analyzer::evaluate( $ctx ), 'focus_density' );
		$this->assertSame( 'info', $density['status'] );
		$this->assertSame( 0, $density['weight'] );
	}

	public function test_short_news_reports_are_not_flagged() {
		$ctx            = $this->good_context();
		$ctx['html']    = '<p>The Karnataka cabinet approved the new metro line on Tuesday. Work starts next month and should finish by 2029, the minister said.</p>';
		$ctx['is_news'] = true;
		$check          = $this->find_check( Analyzer::evaluate( $ctx ), 'content_length' );
		$this->assertSame( 'info', $check['status'] );
		$this->assertSame( 0, $check['weight'] );
		$ctx['is_news'] = false;
		$this->assertSame( 'warning', $this->find_check( Analyzer::evaluate( $ctx ), 'content_length' )['status'] );
	}

	public function test_heading_structure_and_single_h1() {
		$ctx         = $this->good_context();
		$ctx['html'] = '<h1>Another title</h1><p>' . str_repeat( 'Running shoes words here. ', 20 ) . '</p><h2>Part</h2><h4>Skipped</h4><h3></h3>';
		$r           = Analyzer::evaluate( $ctx );
		$headings    = $this->find_check( $r, 'heading_structure' );
		$this->assertSame( 'warning', $headings['status'] );
		$this->assertStringContainsString( 'H2 → H4', $headings['message'] );
		$this->assertStringContainsString( 'empty', $headings['message'] );
		$this->assertSame(
			array(
				array( 'level' => 1, 'empty' => false ),
				array( 'level' => 2, 'empty' => false ),
				array( 'level' => 4, 'empty' => false ),
				array( 'level' => 3, 'empty' => true ),
			),
			$r['stats']['outline'],
			'The outline the check judged, in document order.'
		);
		$h1 = $this->find_check( $r, 'single_h1' );
		$this->assertSame( 'info', $h1['status'], 'Several H1s are an accessibility note, not a ranking warning.' );
		$this->assertSame( 0, $h1['weight'] );

		$ctx['rendered'] = array( 'h1' => 1, 'titles' => 1 );
		$r               = Analyzer::evaluate( $ctx );
		$this->assertSame( 'pass', $this->find_check( $r, 'single_h1' )['status'], 'Rendered facts win over the content guess.' );
		$this->assertSame( 'pass', $this->find_check( $r, 'title_tag' )['status'] );
	}

	public function test_link_problems_are_reported() {
		$ctx                = $this->good_context();
		$ctx['html']        = '<p>' . str_repeat( 'Running shoes text. ', 30 ) . '<a href="/gone/">click here</a> <a href="https://example.com/old/" rel="nofollow">old guide</a> <a href="https://other.org/" target="_blank">source</a></p>';
		$ctx['link_status'] = array(
			'https://example.com/gone/' => array( 'target_id' => 0, 'status' => 'missing' ),
			'https://example.com/old/'  => array( 'target_id' => 9, 'status' => 'redirect' ),
		);
		$r = Analyzer::evaluate( $ctx );
		$this->assertSame( 'warning', $this->find_check( $r, 'anchor_text' )['status'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'internal_nofollow' )['status'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'target_blank' )['status'] );
		$broken = $this->find_check( $r, 'broken_internal' );
		$this->assertSame( 'fail', $broken['status'] );
		$this->assertContains( 'https://example.com/gone/', $broken['items'] );
	}

	/** English prose cut to exactly $len characters (no ellipsis). */
	private function english_text( $len ) {
		$prose = str_repeat( 'Running shoes for beginners: how to find the right fit, cushioning and grip, and when to replace your pair of trainers. ', 6 );
		return implode( '', array_slice( Text::graphemes( $prose ), 0, $len ) );
	}

	/** Kannada news prose cut to exactly $graphemes grapheme clusters. */
	private function kannada_text( $graphemes ) {
		$prose = str_repeat( 'ಬೆಂಗಳೂರು ನಗರದಲ್ಲಿ ಶುಕ್ರವಾರ ಭಾರಿ ಮಳೆಯಾಗಿದ್ದು, ಹಲವು ಪ್ರದೇಶಗಳಲ್ಲಿ ನೀರು ನಿಂತಿದೆ. ಹವಾಮಾನ ಇಲಾಖೆ ಮುಂದಿನ ಮೂರು ದಿನಗಳ ಕಾಲ ಮಳೆ ಮುಂದುವರಿಯಲಿದೆ ಎಂದು ತಿಳಿಸಿದೆ. ', 4 );
		return implode( '', array_slice( Text::graphemes( $prose ), 0, $graphemes ) );
	}

	public function test_description_length_bands_follow_rendered_width() {
		$lengths = array(
			40  => 'fail',
			100 => 'warning',
			150 => 'pass',
			180 => 'warning',
			260 => 'fail',
		);
		foreach ( $lengths as $len => $expected ) {
			$ctx                = $this->good_context();
			$ctx['description'] = $this->english_text( $len );
			$this->assertSame( $len, Text::length( $ctx['description'] ) );
			$check = $this->find_check( Analyzer::evaluate( $ctx ), 'description_length' );
			$this->assertSame( $expected, $check['status'], "length {$len}: " . $check['message'] );
			$this->assertSame( $len, $check['data']['graphemes'] );
			$this->assertSame( 990.0, $check['data']['limit'] );
		}
		$ctx                       = $this->good_context();
		$ctx['description_source'] = 'fallback';
		$check                     = $this->find_check( Analyzer::evaluate( $ctx ), 'description_present' );
		$this->assertSame( 'pass', $check['status'], 'An automatic description that follows the text is fine.' );
		$this->assertStringContainsString( 'Automatic description from the opening text', $check['message'] );
		$ctx['description']        = '';
		$ctx['description_source'] = 'none';
		$this->assertSame( 'fail', $this->find_check( Analyzer::evaluate( $ctx ), 'description_present' )['status'] );
	}

	public function test_b1_kannada_auto_description_is_never_cut_below_the_minimum() {
		// The owner's case: a 146-grapheme Kannada excerpt. 1.0.2 cut it at 155 code points
		// (≈98 graphemes) and then required 120 graphemes, so it could never pass.
		$excerpt = $this->kannada_text( 146 );
		$this->assertSame( 146, Text::length( $excerpt ) );
		$served = Text::serp_truncate( $excerpt, 'description' );
		$fit    = Text::serp_fit( $served, 'description' );
		$this->assertTrue( $fit['fits'] );
		$this->assertGreaterThan( 98, $fit['graphemes'], 'The width-based cut keeps more text than the old 155-code-point cut.' );
		$this->assertSame( '…', mb_substr( $served, -1 ) );
		$this->assertSame( 0, strpos( $excerpt, mb_substr( $served, 0, -1 ) ), 'The cut never splits a grapheme cluster.' );

		$ctx                       = $this->good_context();
		$ctx['description']        = $served;
		$ctx['description_source'] = 'searchpilot';
		$ctx['description_origin'] = 'auto';
		$r                         = Analyzer::evaluate( $ctx );
		$this->assertSame( 'pass', $this->find_check( $r, 'description_length' )['status'], 'The length check is satisfiable for Kannada.' );
		$this->assertSame( 'pass', $this->find_check( $r, 'description_present' )['status'] );

		// The full excerpt (what Rank Math serves from a manual excerpt) is wider than Google shows.
		$ctx['description']        = $excerpt;
		$ctx['description_source'] = 'rank_math';
		$ctx['description_origin'] = 'template';
		$check                     = $this->find_check( Analyzer::evaluate( $ctx ), 'description_length' );
		$this->assertContains( $check['status'], array( 'warning', 'fail' ) );
		$this->assertSame( 'long', $check['data']['direction'] );
		$this->assertSame( 'rank_math', $check['data']['source'] );
	}

	public function test_kannada_is_judged_by_width_not_latin_character_counts() {
		// ≈100 Kannada graphemes fill the description space; the same count of Latin characters does not.
		$kn = Analyzer::description_length_status( $this->kannada_text( 100 ) );
		$en = Analyzer::description_length_status( $this->english_text( 100 ) );
		$this->assertSame( 'pass', $kn['status'], $kn['message'] );
		$this->assertSame( 'warning', $en['status'] );
		$this->assertSame( 'short', $en['direction'] );
		$this->assertGreaterThan( $en['width'], $kn['width'] );
		// Titles: 50 Kannada graphemes overflow 580 px a little (tolerated: the width is an estimate
		// and Google cuts at a word); 70 are well beyond it. 50 Latin characters fit.
		$slightly = Analyzer::title_length_status( $this->kannada_text( 50 ) );
		$this->assertGreaterThan( 1.0, $slightly['ratio'] );
		$this->assertLessThanOrEqual( Analyzer::TITLE_LONG, $slightly['ratio'] );
		$this->assertSame( 'pass', $slightly['status'], $slightly['message'] );
		$this->assertSame( 'ok', $slightly['direction'] );
		$this->assertSame( 'long', Analyzer::title_length_status( $this->kannada_text( 70 ) )['direction'] );
		$this->assertSame( 'pass', Analyzer::title_length_status( 'Best Running Shoes for Beginners: How to Choose' )['status'] );
	}

	public function test_finding_4_over_length_manual_excerpts_are_flagged_and_good_ones_are_not() {
		foreach ( array( 207, 250, 303 ) as $len ) {
			$status = Analyzer::description_length_status( $this->english_text( $len ) );
			$this->assertSame( 'long', $status['direction'], "{$len} characters" );
			$this->assertContains( $status['status'], array( 'warning', 'fail' ) );
		}
		foreach ( array( 130, 145, 155 ) as $len ) {
			$this->assertSame( 'pass', Analyzer::description_length_status( $this->english_text( $len ) )['status'], "{$len} characters" );
		}
	}

	public function test_length_status_shape() {
		foreach ( array( Analyzer::title_length_status( 'Hello world' ), Analyzer::description_length_status( 'Hello world' ) ) as $status ) {
			foreach ( array( 'status', 'direction', 'graphemes', 'width', 'limit', 'ratio', 'message', 'recommendation' ) as $key ) {
				$this->assertArrayHasKey( $key, $status );
			}
			$this->assertSame( 11, $status['graphemes'] );
			$this->assertSame( 'short', $status['direction'] );
		}
	}

	public function test_short_titles_on_utility_pages_are_an_opportunity() {
		$page = Analyzer::post_type_rule( 'page', array() );
		$this->assertTrue( $page['utility'] );
		$this->assertSame( 'warning', Analyzer::title_length_status( 'Contact', $page )['status'] );
		$this->assertStringContainsString( 'site name', Analyzer::title_length_status( 'Contact', $page )['recommendation'] );
		$this->assertSame( 'fail', Analyzer::title_length_status( 'Contact', Analyzer::post_type_rule( 'post', array() ) )['status'] );
		// The suggested "{title} – {site}" must verify on utility pages (both scripts); bare titles keep warning.
		foreach ( array( 'Contact – The Voice of Human', 'ಸಂಪರ್ಕ – ದಿ ವಾಯ್ಸ್ ಆಫ್ ಕನ್ನಡಿಗ' ) as $title ) {
			$status = Analyzer::title_length_status( $title, $page );
			$this->assertSame( 'pass', $status['status'], $title . ': ' . $status['message'] );
			$this->assertSame( 'ok', $status['direction'] );
			$this->assertLessThan( Analyzer::TITLE_SHORT, $status['ratio'] );
			$this->assertSame( 'warning', Analyzer::title_length_status( $title, Analyzer::post_type_rule( 'post', array() ) )['status'], 'Non-utility posts unchanged.' );
		}
		foreach ( array( 'Contact', 'ಸಂಪರ್ಕ' ) as $title ) {
			$status = Analyzer::title_length_status( $title, $page );
			$this->assertSame( 'warning', $status['status'], $title );
			$this->assertSame( 'short', $status['direction'] );
		}

		$ctx              = $this->good_context();
		$ctx['post_type'] = 'page';
		$ctx['seo_title'] = 'Corrections';
		$check            = $this->find_check( Analyzer::evaluate( $ctx ), 'title_length' );
		$this->assertSame( 'warning', $check['status'] );
		$this->assertSame( 'short', $check['data']['direction'] );
		$this->assertTrue( $check['data']['utility'] );
	}

	public function test_post_type_rules_skip_thin_content_and_featured_image_on_pages() {
		$ctx             = $this->good_context();
		$ctx['html']     = '<p>Write to us at the address below. We answer within two working days.</p>';
		$ctx['featured'] = null;
		$post            = Analyzer::evaluate( $ctx );
		$this->assertSame( 'warning', $this->find_check( $post, 'content_length' )['status'] );
		$this->assertSame( 'fail', $this->find_check( $post, 'featured_image' )['status'] );

		$ctx['post_type'] = 'page';
		$page             = Analyzer::evaluate( $ctx );
		$content          = $this->find_check( $page, 'content_length' );
		$this->assertSame( 'info', $content['status'], 'Contact/Corrections pages are short by design.' );
		$this->assertSame( 0, $content['weight'] );
		$this->assertSame( 0, $content['data']['threshold'] );
		$this->assertSame( 'info', $this->find_check( $page, 'featured_image' )['status'], 'Pages need no featured image.' );

		// Settings override: pages judged again at 100 words; built-in defaults stay for the other keys.
		$rule = Analyzer::post_type_rule( 'page', array( 'page' => array( 'thin_words' => 100 ) ) );
		$this->assertSame( 100, $rule['thin_words'] );
		$this->assertFalse( $rule['featured_image'] );
		$this->assertTrue( $rule['utility'] );
		$ctx['type_rule'] = $rule;
		$check            = $this->find_check( Analyzer::evaluate( $ctx ), 'content_length' );
		$this->assertSame( 'warning', $check['status'] );
		$this->assertSame( 100, $check['data']['threshold'] );

		// Custom post types inherit the global guideline (-1) and expect a featured image.
		$this->assertSame( array( 'thin_words' => -1, 'featured_image' => true, 'utility' => false ), Analyzer::post_type_rule( 'event', array() ) );
	}

	public function test_effective_reports_sources() {
		$ctx                       = $this->good_context();
		$ctx['title_source']       = 'rendered';
		$ctx['title_origin']       = 'template';
		$ctx['description_source'] = 'rank_math';
		$ctx['description_origin'] = 'template';
		$ctx['companion']          = 'Rank Math SEO';
		$ctx['rendered_at']        = '2026-09-30T10:00:00+00:00';
		$r                         = Analyzer::evaluate( $ctx );
		$this->assertSame( 'rendered', $r['effective']['title_source'] );
		$this->assertSame( 'rank_math', $r['effective']['description_source'] );
		$this->assertSame( 'template', $r['effective']['description_origin'] );
		$this->assertSame( '2026-09-30T10:00:00+00:00', $r['effective']['rendered_at'] );
		$this->assertStringContainsString( 'Rank Math SEO builds the description', $this->find_check( $r, 'description_present' )['message'] );
		$this->assertStringContainsString( 'published page', $this->find_check( $r, 'title_present' )['message'] );

		$ctx['description']        = '';
		$ctx['description_source'] = 'slim_seo';
		$ctx['description_origin'] = 'unknown';
		$ctx['companion']          = 'Slim SEO';
		$check                     = $this->find_check( Analyzer::evaluate( $ctx ), 'description_present' );
		$this->assertSame( 'info', $check['status'], 'A description another plugin prints is not reported missing before a crawl.' );
	}

	public function test_title_year_freshness_duplicates_and_technical() {
		$ctx               = $this->good_context();
		$ctx['seo_title']  = 'Best Running Shoes of 2024: Our Top Picks';
		$ctx['modified']   = self::NOW - 400 * DAY_IN_SECONDS;
		$ctx['duplicates'] = array(
			'title'       => array( array( 'id' => 9, 'title' => 'Other post' ) ),
			'description' => array(),
			'content'     => array( array( 'id' => 11, 'title' => 'Copy', 'distance' => 2 ) ),
		);
		$ctx['noindex']    = true;
		$ctx['canonical']  = 'https://example.com/elsewhere/';
		$r                 = Analyzer::evaluate( $ctx );
		$this->assertSame( 'warning', $this->find_check( $r, 'title_year' )['status'] );
		$this->assertStringContainsString( '2024', $this->find_check( $r, 'title_year' )['message'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'last_modified' )['status'] );
		$this->assertSame( 'fail', $this->find_check( $r, 'duplicate_title' )['status'] );
		$this->assertSame( 'pass', $this->find_check( $r, 'duplicate_description' )['status'] );
		$this->assertSame( 'fail', $this->find_check( $r, 'near_duplicate' )['status'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'noindex' )['status'] );
		$this->assertStringContainsString( 'won’t appear', $this->find_check( $r, 'noindex' )['message'] );
		$this->assertSame( 'warning', $this->find_check( $r, 'canonical' )['status'] );
	}

	public function test_query_coverage_flags_uncovered_queries() {
		$ctx            = $this->good_context();
		$ctx['queries'] = array(
			array( 'query' => 'running shoes for beginners', 'clicks' => 10, 'impressions' => 500, 'position' => 8.2 ),
			array( 'query' => 'waterproof hiking boots', 'clicks' => 0, 'impressions' => 300, 'position' => 31 ),
		);
		$r = Analyzer::evaluate( $ctx );
		$this->assertCount( 2, $r['queries'] );
		$this->assertTrue( $r['queries'][0]['covered'] );
		$this->assertSame( 'title', $r['queries'][0]['where'] );
		$this->assertFalse( $r['queries'][1]['covered'] );
		$coverage = $this->find_check( $r, 'query_coverage' );
		$this->assertSame( 'warning', $coverage['status'] );
		$this->assertContains( 'waterproof hiking boots', $coverage['items'] );
	}

	public function test_companion_mode_social_and_schema_are_info() {
		$ctx              = $this->good_context();
		$ctx['owns']      = array( 'title' => false, 'description' => false, 'social' => false, 'schema' => false );
		$ctx['companion'] = 'Rank Math SEO';
		$r                = Analyzer::evaluate( $ctx );
		$this->assertSame( 'info', $this->find_check( $r, 'social_preview' )['status'] );
		$this->assertStringContainsString( 'Rank Math SEO', $this->find_check( $r, 'social_preview' )['message'] );
		$this->assertSame( 'info', $this->find_check( $r, 'schema_article' )['status'] );
	}

	public function test_score_is_weighted_and_ignores_info() {
		$checks = array(
			array( 'group' => 'title', 'status' => 'pass', 'weight' => 6 ),
			array( 'group' => 'title', 'status' => 'fail', 'weight' => 2 ),
			array( 'group' => 'links', 'status' => 'warning', 'weight' => 4 ),
			array( 'group' => 'links', 'status' => 'info', 'weight' => 0 ),
		);
		$scored = Analyzer::score( $checks );
		// (6 + 0 + 2) / 12 = 66.7%.
		$this->assertSame( 67, $scored['score'] );
		$groups = array_column( $scored['groups'], 'score', 'id' );
		$this->assertSame( 75, $groups['title'] );
		$this->assertSame( 50, $groups['links'] );
		$this->assertNull( $groups['schema'] );
		$this->assertSame( 'good', Analyzer::grade( 80 ) );
		$this->assertSame( 'ok', Analyzer::grade( 50 ) );
		$this->assertSame( 'poor', Analyzer::grade( 49 ) );
	}

	public function test_positions_and_grapheme_length() {
		$this->assertSame( array( 1, 4 ), Analyzer::positions( array( 'a', 'b', 'c', 'x', 'b', 'c' ), array( 'b', 'c' ) ) );
		$this->assertSame( array(), Analyzer::positions( array( 'a' ), array( 'a', 'b' ) ) );
		$this->assertSame( 5, Analyzer::chars( 'hello' ) );
		$this->assertSame( 4, Analyzer::chars( 'ಬೆಂಗಳೂರು' ), 'ಬೆಂ ಗ ಳೂ ರು' );
	}
}
