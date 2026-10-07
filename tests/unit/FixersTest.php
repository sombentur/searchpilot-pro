<?php
/**
 * Pure logic of the issue planners (Fixes\Fixers).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Fixes\Fixers\AdvisorySteps;
use SearchPilot\Fixes\Fixers\AltTextFixer;
use SearchPilot\Fixes\Fixers\DescriptionFixer;
use SearchPilot\Fixes\Fixers\HeadingFixer;
use SearchPilot\Fixes\Fixers\ImageOptimizerAdvisor;
use SearchPilot\Fixes\Fixers\IndexNowFixer;
use SearchPilot\Fixes\Fixers\TitleFixer;
use SearchPilot\Support\Text;

// phpcs:disable

class FixersTest extends \PHPUnit\Framework\TestCase {

	/* ---------- Headings ---------- */

	public function test_clamp_levels_keeps_relative_outline() {
		$this->assertSame( array( 2, 3, 4 ), HeadingFixer::clamp_levels( array( 2, 4, 5 ) ) );
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::clamp_levels( array( 4, 5, 2, 4 ) ) );
		$this->assertSame( array( 2, 3, 3 ), HeadingFixer::clamp_levels( array( 2, 4, 3 ) ) );
		$this->assertSame( array( 2, 3, 4, 2 ), HeadingFixer::clamp_levels( array( 2, 3, 4, 2 ) ), 'Valid outlines are unchanged.' );
		$this->assertSame( array( 1, 2 ), HeadingFixer::clamp_levels( array( 1, 3 ) ) );
		$this->assertSame( array(), HeadingFixer::clamp_levels( array() ) );
	}

	public function test_fixed_headings_keep_their_level() {
		$headings = array(
			array( 'level' => 2, 'editable' => true ),
			array( 'level' => 5, 'editable' => false ),
			array( 'level' => 6, 'editable' => true ),
		);
		$fixed    = HeadingFixer::fixed( $headings );
		$this->assertSame( array( 1 ), $fixed );
		$this->assertSame( array( 2, 5, 6 ), HeadingFixer::clamp_levels( HeadingFixer::levels( $headings ), 1, $fixed ) );
		$this->assertTrue( HeadingFixer::has_skip( array( 2, 5, 6 ) ) );
		$this->assertFalse( HeadingFixer::has_skip( array( 2, 3, 2 ) ) );
		$this->assertSame( array( 2, 1, 2 ), HeadingFixer::demote_h1_levels( array( 1, 1, 2 ), false, array( 1 ) ), 'A locked H1 stays.' );
	}

	public function test_normalize_shifts_outline_before_clamping() {
		// Newsrooms using H3 (or H3/H4) for every subhead: siblings stay siblings.
		$this->assertSame( array( 2, 2, 2 ), HeadingFixer::normalize_levels( array( 3, 3, 3 ) ) );
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::normalize_levels( array( 3, 4, 3, 4 ) ) );
		$this->assertSame( array( 2 ), HeadingFixer::normalize_levels( array( 4 ) ) );
		// Mixed: shift, then clamp only the real skips.
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::normalize_levels( array( 3, 5, 3, 4 ) ) );
		$this->assertSame( array( 2, 3, 4 ), HeadingFixer::normalize_levels( array( 2, 4, 5 ) ) );
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::normalize_levels( array( 4, 5, 2, 4 ) ) );
		$this->assertSame( array( 1, 2 ), HeadingFixer::normalize_levels( array( 1, 3 ) ), 'A content H1 keeps the outline from shifting.' );
		$this->assertSame( array( 2, 3, 3, 2 ), HeadingFixer::normalize_levels( array( 2, 3, 3, 2 ) ), 'A valid outline is unchanged.' );
		$this->assertSame( array( 3, 2 ), HeadingFixer::normalize_levels( array( 3, 3 ), 1, array( 0 ) ), 'A locked heading keeps its level.' );
		$this->assertSame( array(), HeadingFixer::normalize_levels( array() ) );
	}

	public function test_heading_fix_through_content_editor_en_blocks_and_kn_classic() {
		$blocks = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Why traffic is bad</h3>\n<!-- /wp:heading -->\n<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->\n<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">Metro</h4>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">What next</h3>\n<!-- /wp:heading -->\n<!-- wp:heading {\"level\":4} -->\n<h4 class=\"wp-block-heading\">Buses</h4>\n<!-- /wp:heading -->";
		$new    = HeadingFixer::normalize_levels( HeadingFixer::levels( \SearchPilot\Fixes\ContentEditor::headings( $blocks ) ) );
		$this->assertSame( array( 2, 3, 2, 3 ), $new );
		$out = \SearchPilot\Fixes\ContentEditor::set_heading_levels(
			$blocks,
			static function ( $level, $index ) use ( $new ) {
				return $new[ $index ];
			}
		);
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::levels( \SearchPilot\Fixes\ContentEditor::headings( $out ) ) );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading">Metro</h3>', $out, 'Block attribute and tag change together.' );
		$this->assertStringNotContainsString( '<h4', $out );

		$classic = "<h3>ಸಂಚಾರ ದಟ್ಟಣೆಗೆ ಕಾರಣಗಳು</h3>\n<p>ಪಠ್ಯ</p>\n<h3>ಮೆಟ್ರೋ ವಿಳಂಬ</h3>\n<p>ಪಠ್ಯ</p>\n<h3>ಮುಂದೇನು</h3>";
		$new     = HeadingFixer::normalize_levels( HeadingFixer::levels( \SearchPilot\Fixes\ContentEditor::headings( $classic ) ) );
		$this->assertSame( array( 2, 2, 2 ), $new, 'Kannada article with H3 subheads: all become H2, none nested.' );
		$out = \SearchPilot\Fixes\ContentEditor::set_heading_levels(
			$classic,
			static function ( $level, $index ) use ( $new ) {
				return $new[ $index ];
			}
		);
		$this->assertSame( "<h2>ಸಂಚಾರ ದಟ್ಟಣೆಗೆ ಕಾರಣಗಳು</h2>\n<p>ಪಠ್ಯ</p>\n<h2>ಮೆಟ್ರೋ ವಿಳಂಬ</h2>\n<p>ಪಠ್ಯ</p>\n<h2>ಮುಂದೇನು</h2>", $out );
	}

	public function test_structure_mode_from_rendered_and_raw_outline() {
		$this->assertSame( 'deterministic', HeadingFixer::structure_mode_for( array( 'outline' => array( 3, 3 ), 'raw_outline' => array( 3, 3 ), 'raw_fixed' => false ) ) );
		$this->assertSame( 'assisted', HeadingFixer::structure_mode_for( array( 'outline' => array( 2, 3, 4, 2, 4 ), 'raw_outline' => array( 2, 4 ), 'raw_fixed' => false ) ), 'A pattern or shortcode adds headings: not unattended.' );
		$this->assertSame( 'assisted', HeadingFixer::structure_mode_for( array( 'outline' => array( 2, 4 ), 'raw_outline' => array( 2, 4 ), 'raw_fixed' => true ) ), 'Non-editable headings: not unattended.' );
		$this->assertSame( 'advisory', HeadingFixer::structure_mode_for( array( 'outline' => array( 2, 3, 2 ), 'raw_outline' => array( 2, 3, 2 ), 'empty' => 1 ) ), 'Only an empty heading: manual step.' );
		$this->assertSame( 'advisory', HeadingFixer::structure_mode_for( array( 'outline' => array(), 'raw_outline' => array(), 'no_subheadings' => true ) ), 'No subheadings: manual step.' );
		$this->assertSame( 'assisted', HeadingFixer::structure_mode_for( array( 'problems' => array( 'x' ) ) ), 'Data from before 1.1: never unattended.' );
	}

	public function test_demote_single_content_h1() {
		// One H1 duplicating the title, then H2 sections: only the H1 moves.
		$this->assertSame( array( 2, 2, 2, 3 ), HeadingFixer::demote_h1_levels( array( 1, 2, 2, 3 ) ) );
		$this->assertSame( array( 2, 2, 2 ), HeadingFixer::demote_h1_levels( array( 2, 1, 2 ) ) );
	}

	public function test_demote_h1_sections_shift_whole_outline() {
		// Several H1 sections with H2 sub-sections: everything moves down one level.
		$this->assertSame( array( 2, 3, 2, 3 ), HeadingFixer::demote_h1_levels( array( 1, 2, 1, 2 ) ) );
		$this->assertSame( array( 2, 3, 2, 3, 4 ), HeadingFixer::demote_h1_levels( array( 1, 2, 1, 4, 6 ) ), 'Shift is followed by the clamp.' );
		$this->assertSame( array( 2, 2, 3 ), HeadingFixer::demote_h1_levels( array( 1, 2, 6 ) ), 'One H1: demote only, then clamp.' );
	}

	public function test_demote_keeps_first_h1_when_layout_hides_title() {
		$this->assertSame( array( 1, 2, 2 ), HeadingFixer::demote_h1_levels( array( 1, 2, 1 ), true ) );
		$this->assertSame( array( 2, 3 ), HeadingFixer::demote_h1_levels( array( 2, 3 ), true ), 'No H1: unchanged.' );
	}

	public function test_describe_lists_only_changed_headings() {
		$headings = array(
			array( 'level' => 2, 'text' => 'Intro' ),
			array( 'level' => 4, 'text' => 'ಮೈಸೂರು ದಸರಾ' ),
			array( 'level' => 4, 'text' => '' ),
		);
		$diff     = HeadingFixer::describe( $headings, array( 2, 3, 3 ) );
		$this->assertCount( 2, $diff['before'] );
		$this->assertSame( 'H4 “ಮೈಸೂರು ದಸರಾ”', $diff['before'][0] );
		$this->assertSame( 'H3 “ಮೈಸೂರು ದಸರಾ”', $diff['after'][0] );
		$this->assertSame( 'H3 (empty heading)', $diff['after'][1] );
	}

	/* ---------- Titles ---------- */

	public function test_leading_clause_delimiters() {
		$this->assertSame( 'Why is Bengaluru traffic so bad?', TitleFixer::leading_clause( 'Why is Bengaluru traffic so bad? Experts explain the five main reasons' ) );
		$this->assertSame( 'Explained', TitleFixer::leading_clause( 'Explained: why the monsoon is late this year' ) );
		$this->assertSame( 'Monsoon arrives in Kerala', TitleFixer::leading_clause( 'Monsoon arrives in Kerala – what it means for Karnataka farmers' ) );
		$this->assertSame( 'Budget 2026', TitleFixer::leading_clause( 'Budget 2026 — highlights' ) );
		$this->assertSame( 'ದಸರಾ 2026', TitleFixer::leading_clause( 'ದಸರಾ 2026: ಮೈಸೂರು ಅರಮನೆ ದೀಪಾಲಂಕಾರ' ) );
	}

	public function test_leading_clause_ignores_times_ranges_and_hyphenated_words() {
		$this->assertSame( '', TitleFixer::leading_clause( 'Train at 10:30 cancelled after heavy rain in Bengaluru' ) );
		$this->assertSame( 'The 2019–2020 season in review', TitleFixer::leading_clause( 'The 2019–2020 season in review: every match, every goal' ) );
		$this->assertSame( '', TitleFixer::leading_clause( 'Covid-19 cases rise again in Karnataka districts' ) );
		$this->assertSame( '', TitleFixer::leading_clause( 'No delimiter at all in this title' ) );
	}

	public function test_strip_site_suffix() {
		$this->assertSame( 'Karnataka budget highlights', TitleFixer::strip_site_suffix( 'Karnataka budget highlights – The Daily Example', 'The Daily Example' ) );
		$this->assertSame( 'Karnataka budget highlights', TitleFixer::strip_site_suffix( 'Karnataka budget highlights - the daily example', 'The Daily Example' ) );
		$this->assertSame( 'Karnataka budget highlights', TitleFixer::strip_site_suffix( 'The Daily Example | Karnataka budget highlights', 'The Daily Example' ) );
		$this->assertSame( 'No site name here', TitleFixer::strip_site_suffix( 'No site name here', 'The Daily Example' ) );
		$this->assertSame( 'ಮೈಸೂರು ದಸರಾ', TitleFixer::strip_site_suffix( 'ಮೈಸೂರು ದಸರಾ - ಕನ್ನಡ ದಿನಪತ್ರಿಕೆ', 'ಕನ್ನಡ ದಿನಪತ್ರಿಕೆ' ) );
	}

	public function test_trim_to_fit_cuts_on_word_boundary() {
		$passes = static function ( $t ) {
			$n = Text::length( $t );
			return $n >= 30 && $n <= 60;
		};
		$title  = 'Heavy rain lashes Bengaluru as the city records its wettest October day in nearly a decade';
		$cut    = TitleFixer::trim_to_fit( $title, $passes );
		$this->assertNotSame( '', $cut );
		$this->assertTrue( $passes( $cut ) );
		$this->assertSame( 0, strpos( $title, $cut ), 'The cut is a prefix of the title.' );
		$this->assertSame( ' ', substr( $title, strlen( $cut ), 1 ), 'The cut ends at a word boundary.' );
		$this->assertStringNotContainsString( '…', $cut );
	}

	public function test_trim_to_fit_kannada_never_splits_a_word() {
		$passes = static function ( $t ) {
			$n = Text::length( $t );
			return $n >= 20 && $n <= 45;
		};
		$title  = 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ ನಗರದಲ್ಲಿ ದಶಕದಲ್ಲೇ ಅತಿ ಹೆಚ್ಚು ಅಕ್ಟೋಬರ್ ಮಳೆ ದಾಖಲಾಗಿದೆ ಎಂದು ಹವಾಮಾನ ಇಲಾಖೆ ತಿಳಿಸಿದೆ';
		$cut    = TitleFixer::trim_to_fit( $title, $passes, 10 );
		$this->assertNotSame( '', $cut );
		$this->assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertSame( 0, strpos( $title, $cut ) );
		$this->assertSame( ' ', substr( $title, strlen( $cut ), 1 ), 'Kannada words (with their vowel signs) stay whole.' );
	}

	public function test_clean_tail_removes_dangling_words_and_punctuation() {
		$this->assertSame( 'Budget highlights', TitleFixer::clean_tail( 'Budget highlights for the' ) );
		$this->assertSame( 'Top stories of the week in Karnataka', TitleFixer::clean_tail( 'Top stories of the week in Karnataka –' ) );
		$this->assertSame( 'Best phones under Rs 20000', TitleFixer::clean_tail( 'Best phones under Rs 20000,' ) );
		$this->assertSame( 'India vs Australia Test 10', TitleFixer::clean_tail( 'India vs Australia Test 10' ), 'Numbers are kept.' );
	}

	public function test_title_rule_direction() {
		$long  = TitleFixer::rule( 'Heavy rain lashes Bengaluru as the city records its wettest October day in nearly a decade, officials say' );
		$short = TitleFixer::rule( 'Contact' );
		$ok    = TitleFixer::rule( 'Heavy rain lashes Bengaluru, schools closed' );
		$this->assertSame( 'long', $long['direction'] );
		$this->assertNotSame( 'pass', $long['status'] );
		$this->assertSame( 'short', $short['direction'] );
		$this->assertSame( 'pass', $ok['status'] );
		$this->assertSame( '', $ok['direction'] );
	}

	/**
	 * Length rule accepting from $min up to $max graphemes.
	 */
	private static function between( $min, $max ) {
		return static function ( $t ) use ( $min, $max ) {
			$n = Text::length( $t );
			return $n >= $min && $n <= $max;
		};
	}

	public function test_candidate_kinds_english() {
		$site = 'The Daily Example';
		// 1. Site-name suffix removed: a natural phrase.
		$title = 'Karnataka budget highlights for farmers and students – The Daily Example';
		$c     = TitleFixer::candidates( $title, $site, self::between( 20, 55 ) );
		$this->assertSame( 'site', $c[0]['kind'] );
		$this->assertSame( 'Karnataka budget highlights for farmers and students', $c[0]['text'] );
		$this->assertSame( 'deterministic', TitleFixer::mode_for_kind( $c[0]['kind'] ) );
		// 2. Leading clause: a natural phrase.
		$title = 'Why is Bengaluru traffic so bad? Experts explain the five main reasons behind the daily gridlock';
		$c     = TitleFixer::candidates( $title, $site, self::between( 20, 60 ) );
		$this->assertSame( 'clause', $c[0]['kind'] );
		$this->assertSame( 'Why is Bengaluru traffic so bad?', $c[0]['text'] );
		$this->assertSame( 'assisted', TitleFixer::mode_for_kind( $c[0]['kind'] ) ); // Fixed text never runs unattended.
		$this->assertSame( 'trim', end( $c )['kind'], 'A word cut is still offered last.' );
		// 3. Only a word cut fits: needs a person.
		$title = 'Heavy rain lashes Bengaluru as the city records its wettest October day in nearly a decade';
		$c     = TitleFixer::candidates( $title, $site, self::between( 20, 60 ) );
		$this->assertCount( 1, $c );
		$this->assertSame( 'trim', $c[0]['kind'] );
		$this->assertSame( 'assisted', TitleFixer::mode_for_kind( $c[0]['kind'] ) );
		// Nothing fits.
		$this->assertSame( array(), TitleFixer::candidates( $title, $site, self::between( 200, 300 ) ) );
	}

	public function test_candidate_kinds_kannada() {
		$site = 'ಕನ್ನಡ ದಿನಪತ್ರಿಕೆ';
		$core = 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ ಶಾಲೆಗಳಿಗೆ ರಜೆ ಘೋಷಣೆ';
		$c    = TitleFixer::candidates( $core . ' – ' . $site, $site, self::between( 5, Text::length( $core ) ) );
		$this->assertSame( 'site', $c[0]['kind'] );
		$this->assertSame( $core, $c[0]['text'] );

		$clause = 'ದಸರಾ ಉತ್ಸವಕ್ಕೆ ಮೈಸೂರು ಸಜ್ಜು';
		$title  = $clause . ': ಅರಮನೆಗೆ ದೀಪಾಲಂಕಾರ, ಜಂಬೂ ಸವಾರಿಗೆ ಸಿದ್ಧತೆ ಪೂರ್ಣ';
		$c      = TitleFixer::candidates( $title, $site, self::between( 5, Text::length( $clause ) + 5 ) );
		$this->assertSame( 'clause', $c[0]['kind'] );
		$this->assertSame( $clause, $c[0]['text'] );

		$title = 'ಬೆಂಗಳೂರಿನಲ್ಲಿ ಭಾರಿ ಮಳೆ ನಗರದಲ್ಲಿ ದಶಕದಲ್ಲೇ ಅತಿ ಹೆಚ್ಚು ಅಕ್ಟೋಬರ್ ಮಳೆ ದಾಖಲಾಗಿದೆ ಎಂದು ಹವಾಮಾನ ಇಲಾಖೆ ತಿಳಿಸಿದೆ';
		$c     = TitleFixer::candidates( $title, $site, self::between( 10, 40 ) );
		$this->assertCount( 1, $c );
		$this->assertSame( 'trim', $c[0]['kind'] );
		$this->assertSame( ' ', substr( $title, strlen( $c[0]['text'] ), 1 ), 'The Kannada cut ends at a word boundary.' );
	}

	public function test_long_title_mode_from_cached_kind() {
		$this->assertSame( 'deterministic', TitleFixer::long_mode( array( 'fix_kind' => 'site' ) ) );
		$this->assertSame( 'assisted', TitleFixer::long_mode( array( 'fix_kind' => 'clause' ) ), 'A leading clause is fixed text: one click.' );
		$this->assertSame( 'assisted', TitleFixer::long_mode( array( 'fix_kind' => 'site_literal' ) ), 'Suffix removed as fixed text: one click.' );
		$this->assertSame( 'assisted', TitleFixer::long_mode( array( 'fix_kind' => 'trim' ) ) );
		$this->assertSame( 'advisory', TitleFixer::long_mode( array( 'fix_kind' => '' ) ), 'No safe shorter title: manual step only.' );
		$this->assertSame( 'assisted', TitleFixer::long_mode( array() ), 'Data without the cached kind is never unattended.' );
	}

	public function test_headline_variables_per_owner() {
		$this->assertSame( '%title%', TitleFixer::variable_for_store( 'searchpilot' ) );
		$this->assertSame( '%title%', TitleFixer::variable_for_store( 'rank_math' ) );
		$this->assertSame( '%%title%%', TitleFixer::variable_for_store( 'yoast' ) );
		$this->assertSame( '#post_title', TitleFixer::variable_for_store( 'aioseo' ) );
		$this->assertSame( '', TitleFixer::variable_for_store( 'seopress' ), 'Unverified variable syntax: never unattended.' );
		$this->assertSame( '', TitleFixer::variable_for_store( 'tsf' ) );
		$this->assertSame( 'deterministic', TitleFixer::mode_for_kind( 'site' ) );
	}

	public function test_direction_of_legacy_issue_data() {
		$this->assertSame( 'long', TitleFixer::direction_of( array( 'data' => array( 'direction' => 'long' ) ) ) );
		$this->assertSame( 'short', TitleFixer::direction_of( array( 'data' => array( 'length' => 9 ) ) ) );
		$this->assertSame( 'long', TitleFixer::direction_of( array( 'data' => array( 'length' => 84 ) ) ) );
	}

	public function test_site_name_and_separators() {
		$this->assertSame( 'Contact – The Daily Example', TitleFixer::with_site_name( ' Contact ', '–', 'The Daily Example' ) );
		$this->assertSame( '|', TitleFixer::yoast_separator( 'sc-pipe' ) );
		$this->assertSame( '–', TitleFixer::yoast_separator( 'sc-ndash' ) );
		$this->assertSame( '-', TitleFixer::yoast_separator( 'unknown' ) );
	}

	/* ---------- Descriptions ---------- */

	public function test_shorten_description_prefers_whole_sentences() {
		$passes = static function ( $t ) {
			$n = Text::length( $t );
			return $n >= 80 && $n <= 156;
		};
		$desc   = 'The state cabinet approved a new drought relief package on Monday. Farmers in 23 districts will receive direct payments before Deepavali. Officials said the list of beneficiaries will be published online next week, along with a helpline number.';
		$short  = DescriptionFixer::shorten( $desc, $passes );
		$this->assertSame( 'The state cabinet approved a new drought relief package on Monday. Farmers in 23 districts will receive direct payments before Deepavali.', $short );
	}

	public function test_shorten_description_falls_back_to_word_cut_with_ellipsis() {
		$passes = static function ( $t ) {
			$n = Text::length( $t );
			return $n >= 80 && $n <= 156;
		};
		$desc   = 'A single very long sentence about the monsoon session of the legislature that keeps going with clause after clause about bills, debates, walkouts and adjournments without ever stopping for breath';
		$short  = DescriptionFixer::shorten( $desc, $passes );
		$this->assertNotSame( '', $short );
		$this->assertTrue( $passes( $short ) );
		$this->assertSame( '…', mb_substr( $short, -1 ) );
		$this->assertSame( 0, strpos( $desc, rtrim( mb_substr( $short, 0, -1 ) ) ) );
	}

	public function test_description_direction() {
		$this->assertSame( 'long', DescriptionFixer::direction_of( array( 'data' => array( 'length' => 240 ) ) ) );
		$this->assertSame( 'short', DescriptionFixer::direction_of( array( 'data' => array( 'length' => 73 ) ) ) );
	}

	/* ---------- ALT text ---------- */

	public function test_content_images_and_figure_captions() {
		$content = '<!-- wp:image {"id":12,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.com/wp-content/uploads/2026/10/dasara-1024x768.jpg" alt="" class="wp-image-12"/><figcaption class="wp-element-caption">Elephants at the <strong>Mysuru</strong> palace. (Photo: PTI)</figcaption></figure><!-- /wp:image -->'
			. '<p>Text</p><img src="/x/plain.png"><figure><img src="a.jpg" alt="Already described"></figure>';
		$images  = AltTextFixer::content_images( $content );
		$this->assertCount( 3, $images );
		$this->assertSame( 12, $images[0]['id'] );
		$this->assertSame( '', $images[0]['alt'] );
		$this->assertSame( 'Elephants at the Mysuru palace. (Photo: PTI)', $images[0]['caption'] );
		$this->assertNull( $images[1]['alt'] );
		$this->assertSame( '', $images[1]['caption'], 'A caption of a later figure does not belong to an image outside it.' );
		$this->assertSame( 'Already described', $images[2]['alt'] );
	}

	public function test_gallery_captions_belong_to_their_own_image() {
		$content = '<figure class="wp-block-gallery"><figure class="wp-block-image"><img src="1.jpg" alt="" class="wp-image-1"/><figcaption>First</figcaption></figure><figure class="wp-block-image"><img src="2.jpg" alt="" class="wp-image-2"/></figure><figcaption class="blocks-gallery-caption">Gallery</figcaption></figure>';
		$images  = AltTextFixer::content_images( $content );
		$this->assertSame( 'First', $images[0]['caption'] );
		$this->assertSame( '', $images[1]['caption'] );
	}

	public function test_images_are_edited_only_in_html_sourced_blocks() {
		$content = '<!-- wp:image {"id":1} --><figure class="wp-block-image"><img src="1.jpg" alt="" class="wp-image-1"/></figure><!-- /wp:image -->'
			. '<!-- wp:uagb/image {"block_id":"a1","alt":""} --><div class="wp-block-uagb-image"><img src="2.jpg" alt=""/></div><!-- /wp:uagb/image -->'
			. '<!-- wp:paragraph --><p>Inline <img src="3.jpg"> image</p><!-- /wp:paragraph -->'
			. '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":4} --><figure class="wp-block-image"><img src="4.jpg" alt="" class="wp-image-4"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'
			. '<!-- wp:kadence/image {"id":5} --><figure><img src="5.jpg" alt="" class="kb-img wp-image-5"/></figure><!-- /wp:kadence/image -->'
			. '<!-- wp:separator /--><p>ಕ್ಲಾಸಿಕ್ <img src="6.jpg"></p>';
		$blocks  = array_column( AltTextFixer::content_images( $content ), 'block' );
		$this->assertSame( array( 'core/image', 'uagb/image', 'core/paragraph', 'core/image', 'kadence/image', '' ), $blocks );
		$editable = array_map( array( AltTextFixer::class, 'editable_block' ), $blocks );
		$this->assertSame( array( true, false, true, true, false, true ), $editable );
		$this->assertTrue( AltTextFixer::editable_block( 'core/media-text' ) );
		$this->assertTrue( AltTextFixer::editable_block( 'core/cover' ) );
		$this->assertFalse( AltTextFixer::editable_block( 'core/html' ) );
	}

	public function test_set_alt() {
		$this->assertSame( '<img src="a.jpg" alt="Dasara &amp; elephants" class="wp-image-3"/>', AltTextFixer::set_alt( '<img src="a.jpg" alt="" class="wp-image-3"/>', 'Dasara & elephants' ) );
		$this->assertSame( '<img alt="Rain" src="a.jpg">', AltTextFixer::set_alt( '<img src="a.jpg">', 'Rain' ) );
		$this->assertSame( '<img src="a.jpg" alt="Rain">', AltTextFixer::set_alt( '<img src="a.jpg" alt>', 'Rain' ) );
		$this->assertSame( '<img src="a.jpg" alt="Keep me">', AltTextFixer::set_alt( '<img src="a.jpg" alt="Keep me">', 'Rain' ), 'Existing ALT text is never replaced.' );
		// "alt" inside another attribute's value is not an attribute.
		$this->assertSame( '<img alt="Rain" title="the alt text" src="a.jpg">', AltTextFixer::set_alt( '<img title="the alt text" src="a.jpg">', 'Rain' ) );
		$this->assertSame( '<img src="a.jpg" alt="ಮೈಸೂರು ದಸರಾ"/>', AltTextFixer::set_alt( "<img src=\"a.jpg\" alt=''/>", 'ಮೈಸೂರು ದಸರಾ' ) );
	}

	public function test_apply_replacements_from_the_end() {
		$content = '<img src="1.jpg" alt=""> and <img src="2.jpg">';
		$images  = AltTextFixer::content_images( $content );
		$replace = array();
		foreach ( $images as $i => $image ) {
			$replace[ $image['offset'] ] = array( $image['tag'], AltTextFixer::set_alt( $image['tag'], 'Image ' . ( $i + 1 ) ) );
		}
		$this->assertSame( '<img src="1.jpg" alt="Image 1"> and <img alt="Image 2" src="2.jpg">', AltTextFixer::apply_replacements( $content, $replace ) );
	}

	public function test_alt_from_filename() {
		$this->assertSame( 'Monsoon rain bengaluru', AltTextFixer::alt_from_filename( 'https://example.com/wp-content/uploads/2026/10/monsoon-rain-bengaluru-1024x768.jpg' ) );
		$this->assertSame( 'Dasara elephants', AltTextFixer::alt_from_filename( '/var/www/uploads/dasara_elephants-scaled.jpeg' ) );
		$this->assertSame( 'Cm siddaramaiah', AltTextFixer::alt_from_filename( 'cm-siddaramaiah-e1696157200123-300x200.webp' ) );
		$this->assertSame( 'ಮೈಸೂರು ದಸರಾ', AltTextFixer::alt_from_filename( 'https://example.com/uploads/%E0%B2%AE%E0%B3%88%E0%B2%B8%E0%B3%82%E0%B2%B0%E0%B3%81-%E0%B2%A6%E0%B2%B8%E0%B2%B0%E0%B2%BE-1.jpg' ) );
		foreach ( array( 'IMG_20240101_123456.jpg', 'DSC_0042-scaled.jpg', 'a3f9c0e1b2d4e5f6a7b8c9d0.jpg', 'WhatsApp-Image-2024-01-01-at-10.30.15.jpeg', 'Screenshot-2026-10-01.png', '20240101_123456.jpg', 'image-1.png', 'x.jpg' ) as $raw ) {
			$this->assertSame( '', AltTextFixer::alt_from_filename( $raw ), $raw );
		}
	}

	public function test_alt_from_title_and_caption_cleanup() {
		$this->assertSame( 'Chief Minister at the rally', AltTextFixer::alt_from_text( 'Chief Minister at the rally' ) );
		$this->assertSame( '', AltTextFixer::alt_from_text( 'IMG_4031' ) );
		$this->assertSame( 'CM addresses a rally in Mysuru.', AltTextFixer::clean_caption( 'CM addresses a rally in <em>Mysuru</em>. (Photo: PTI)' ) );
		$this->assertSame( 'Flooded road in Hebbal', AltTextFixer::clean_caption( 'Flooded road in Hebbal | Photo credit: Reader' ) );
		$this->assertSame( '', AltTextFixer::clean_caption( 'Photo: PTI' ) );
		$this->assertSame( 'ಮೈಸೂರು ಅರಮನೆ', AltTextFixer::clean_caption( 'ಮೈಸೂರು ಅರಮನೆ (ಚಿತ್ರ: ಸಂಗ್ರಹ)' ) );
	}

	public function test_media_alt_uses_only_what_the_attachment_owns() {
		$this->assertSame( 'Mysuru palace lit up for Dasara.', AltTextFixer::media_alt( 'Mysuru palace lit up for Dasara. (Photo: PTI)', 'IMG_4031', 'IMG_4031.jpg' ) );
		$this->assertSame( 'ಮೈಸೂರು ಅರಮನೆ ದೀಪಾಲಂಕಾರ', AltTextFixer::media_alt( '', 'ಮೈಸೂರು ಅರಮನೆ ದೀಪಾಲಂಕಾರ', 'IMG_20240101_123456.jpg' ) );
		$this->assertSame( 'Flooded underpass', AltTextFixer::media_alt( '', 'IMG_4031', '/uploads/flooded-underpass-1024x768.jpg' ) );
		// Nothing the attachment owns describes it: the shared ALT stays empty (an article's caption is never used).
		$this->assertSame( '', AltTextFixer::media_alt( '', 'IMG_4031', 'DSC_0042.jpg' ) );
	}

	public function test_choose_takes_first_usable_source() {
		$this->assertSame( 'Caption text', AltTextFixer::choose( array( '', '  ', 'Caption text', 'Title' ) ) );
		$this->assertSame( '', AltTextFixer::choose( array( '', '1234' ) ) );
		$long = str_repeat( 'word ', 60 );
		$this->assertLessThanOrEqual( AltTextFixer::MAX_ALT, Text::length( AltTextFixer::choose( array( $long ) ) ) );
	}

	/* ---------- Image optimisers, IndexNow, Search Console ---------- */

	public function test_imagify_advice_reads_lossless_and_resize() {
		$advice = ImageOptimizerAdvisor::imagify(
			array(
				'lossless'        => 1,
				'resize_larger'   => 0,
				'resize_larger_w' => 0,
				'convert_to_webp' => 0,
			)
		);
		$this->assertSame( 'Imagify', $advice['name'] );
		$this->assertStringContainsString( 'lossless', $advice['current'] );
		$this->assertStringContainsString( 'Lossless compression', implode( ' ', $advice['steps'] ) );
		$this->assertStringContainsString( 'Resize larger images', implode( ' ', $advice['steps'] ) );
		$smart = ImageOptimizerAdvisor::imagify( array( 'lossless' => 0, 'resize_larger' => 1, 'resize_larger_w' => 1920 ) );
		$this->assertStringContainsString( 'Smart Compression', $smart['current'] );
		$this->assertStringNotContainsString( 'switch off', implode( ' ', $smart['steps'] ) );
	}

	public function test_other_optimisers_name_their_setting() {
		$this->assertStringContainsString( 'Lossless', ImageOptimizerAdvisor::shortpixel( array( 'compressionType' => 0 ) )['current'] );
		$this->assertStringContainsString( 'Glossy', ImageOptimizerAdvisor::shortpixel( array( 'compressionType' => 2, 'resizeImages' => true, 'resizeWidth' => 1920 ) )['current'] );
		$this->assertStringContainsString( 'Basic', ImageOptimizerAdvisor::smush( array( 'lossy' => false, 'resize' => false ) )['current'] );
		$this->assertStringContainsString( 'Pixel Perfect', ImageOptimizerAdvisor::ewww( array( 'jpg_level' => 10, 'max_width' => 0 ) )['current'] );
	}

	public function test_rank_math_module_list_without_instant_indexing() {
		$this->assertSame( array( 'sitemap', 'rich-snippet' ), IndexNowFixer::without_module( array( 'sitemap', 'instant-indexing', 'rich-snippet' ) ) );
		$this->assertSame( array( 'sitemap' ), IndexNowFixer::without_module( array( 2 => 'sitemap' ) ) );
	}

	public function test_search_console_inspect_link() {
		$this->assertSame( 'https://search.google.com/search-console/inspect?resource_id=sc-domain%3Aexample.com&id=https%3A%2F%2Fexample.com%2Fa%2F', AdvisorySteps::inspect_url( 'sc-domain:example.com', 'https://example.com/a/' ) );
		$this->assertSame( '', AdvisorySteps::inspect_url( '', 'https://example.com/a/' ) );
		$this->assertSame( '', AdvisorySteps::inspect_url( 'javascript:alert(1)', 'https://example.com/a/' ) );
	}
}
