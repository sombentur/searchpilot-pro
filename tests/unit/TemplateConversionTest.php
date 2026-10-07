<?php
/**
 * Tests for importing title templates and title helpers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Import\Importer;
use SearchPilot\Seo\TitleResolver;

// phpcs:disable

class TemplateConversionTest extends \PHPUnit\Framework\TestCase {

	public function test_yoast_variables() {
		$this->assertSame( '%title% %page% %sep% %sitename%', Importer::convert_template( '%%title%% %%page%% %%sep%% %%sitename%%', 'yoast' ) );
		$this->assertSame( '%primary_category% – %excerpt%', Importer::convert_template( '%%primary_category%% – %%excerpt%%', 'yoast' ) );
		$this->assertSame( '%term% %sep% %sitename%', Importer::convert_template( '%%term_title%% %%sep%% %%sitename%%', 'yoast', 'term' ) );
		$this->assertSame( '%author%, %tagline%', Importer::convert_template( '%%name%%, %%sitedesc%%', 'yoast', 'author' ) );
		$this->assertSame( '%search% %sep% %sitename%', Importer::convert_template( '%%searchphrase%% %%sep%% %%sitename%%', 'yoast', 'search' ) );
	}

	public function test_yoast_date_depends_on_context() {
		$this->assertSame( '%date%', Importer::convert_template( '%%date%%', 'yoast', 'post' ) );
		$this->assertSame( '%archive% %sep% %sitename%', Importer::convert_template( '%%date%% %%sep%% %%sitename%%', 'yoast', 'archive' ) );
	}

	public function test_unknown_variables_are_dropped() {
		$this->assertSame( '%title%', Importer::convert_template( '%%title%% %%cf_price%% %%ct_genre%%', 'yoast' ) );
		$this->assertSame( '%title% %sep% %sitename%', Importer::convert_template( '%title% %customfield(price)% %sep% %sitename%', 'rank_math' ) );
	}

	public function test_rank_math_variables() {
		$this->assertSame( '%title% %sep% %sitename%', Importer::convert_template( '%title% %sep% %sitename%', 'rank_math' ) );
		$this->assertSame( '%author% %sep% %tagline%', Importer::convert_template( '%name% %sep% %sitedesc%', 'rank_math', 'author' ) );
		$this->assertSame( 'Results for %search%', Importer::convert_template( 'Results for %search_query%', 'rank_math', 'search' ) );
		$this->assertSame( '%archive% %page% %sep% %sitename%', Importer::convert_template( '%date% %page% %sep% %sitename%', 'rank_math', 'archive' ) );
	}

	public function test_aioseo_tags() {
		$this->assertSame( '%title% %sep% %sitename%', Importer::convert_template( '#post_title #separator_sa #site_title', 'aioseo' ) );
		$this->assertSame( '%term% %sep% %sitename%', Importer::convert_template( '#taxonomy_title #separator_sa #site_title', 'aioseo', 'term' ) );
		$this->assertSame( '%excerpt_only%', Importer::convert_template( '#post_excerpt_only', 'aioseo' ) );
		// Unknown tags are removed, plain hashtags are kept.
		$this->assertSame( '%title% #wordpress', Importer::convert_template( '#post_title #current_date #wordpress', 'aioseo' ) );
	}

	public function test_seopress_variables() {
		$this->assertSame( '%title% %sep% %sitename%', Importer::convert_template( '%%post_title%% %%sep%% %%sitetitle%%', 'seopress' ) );
		$this->assertSame( '%term% %sep% %sitename%', Importer::convert_template( '%%_category_title%% %%sep%% %%sitetitle%%', 'seopress', 'term' ) );
		$this->assertSame( '%search%', Importer::convert_template( '%%search_keywords%%', 'seopress', 'search' ) );
	}

	public function test_tsf_titles_are_literal() {
		$this->assertSame( 'My custom title', Importer::convert_template( '  My  custom title ', 'tsf' ) );
	}

	public function test_empty_and_separator_only_templates() {
		$this->assertSame( '', Importer::convert_template( '', 'yoast' ) );
		$this->assertSame( '', Importer::convert_template( '%%sep%% %%cf_x%%', 'yoast' ) );
		$this->assertSame( '', Importer::convert_template( ' - | ', 'rank_math' ) );
	}

	public function test_kannada_text_survives() {
		$this->assertSame( 'ಸುದ್ದಿ %sep% %sitename%', Importer::convert_template( 'ಸುದ್ದಿ %%sep%% %%sitename%%', 'yoast' ) );
	}

	public function test_normalize_syntax() {
		$this->assertSame( '%title% %sep% %sitename%', TitleResolver::normalize_syntax( '%%title%% %%sep%% %%sitename%%' ) );
		$this->assertSame( '%title% %sep% %sitename%', TitleResolver::normalize_syntax( '#post_title #separator_sa #site_title' ) );
	}

	public function test_insert_page_before_site_name() {
		$this->assertSame( 'News – Page 2 of 5 – Example', TitleResolver::insert_page( 'News – Example', 'Page 2 of 5', '–', 'Example' ) );
		$this->assertSame( 'News – Page 2 of 5', TitleResolver::insert_page( 'News', 'Page 2 of 5', '–', 'Example' ) );
		// Already present (template used %page%): unchanged.
		$this->assertSame( 'News Page 2 of 5 – Example', TitleResolver::insert_page( 'News Page 2 of 5 – Example', 'Page 2 of 5', '–', 'Example' ) );
		$this->assertSame( 'Page 3 of 3', TitleResolver::insert_page( '', 'Page 3 of 3', '|', 'Example' ) );
	}

	public function test_page_suffix_and_count() {
		$this->assertSame( 'Page 2 of 5', TitleResolver::page_suffix( 2, 5 ) );
		$this->assertSame( 'Page 7 of 7', TitleResolver::page_suffix( 7, 3 ) );
		$this->assertSame( 3, TitleResolver::count_pages( "a<!--nextpage-->b<!--nextpage-->c" ) );
		$this->assertSame( 1, TitleResolver::count_pages( 'single' ) );
	}
}
