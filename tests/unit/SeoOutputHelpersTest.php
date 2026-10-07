<?php
/**
 * Tests for the pure helpers of the front-end output, schema builder and suppressor.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Schema\SchemaBuilder;
use SearchPilot\Seo\Breadcrumbs;
use SearchPilot\Seo\HeadOutput;
use SearchPilot\Seo\Suppressor;

// phpcs:disable

class SeoOutputHelpersTest extends \PHPUnit\Framework\TestCase {

	public function test_noindex_adds_follow_and_drops_large_preview() {
		$robots = HeadOutput::apply_directives( array( 'max-image-preview' => 'large' ), array( 'noindex' => true, 'nofollow' => false ), true, true );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );
		$this->assertFalse( isset( $robots['max-image-preview'] ) );
	}

	public function test_nofollow_without_noindex() {
		$robots = HeadOutput::apply_directives( array(), array( 'noindex' => false, 'nofollow' => true ), true, true );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertFalse( isset( $robots['noindex'] ) );
		$this->assertSame( 'large', $robots['max-image-preview'] );
	}

	public function test_large_preview_is_not_duplicated_or_forced() {
		$robots = HeadOutput::apply_directives( array( 'max-image-preview' => 'large' ), array(), true, true );
		$this->assertSame( array( 'max-image-preview' => 'large' ), $robots );
		$robots = HeadOutput::apply_directives( array( 'max-image-preview' => 'large' ), array(), false, true );
		$this->assertSame( array(), $robots );
		// Private sites (blog_public = 0): no preview directive added.
		$robots = HeadOutput::apply_directives( array( 'noindex' => true, 'nofollow' => true ), array(), true, false );
		$this->assertFalse( isset( $robots['max-image-preview'] ) );
		$this->assertFalse( isset( $robots['follow'] ) );
	}

	public function test_replace_mode_drops_other_plugins_values() {
		$robots = HeadOutput::apply_directives( array( 'noindex' => true, 'follow' => true ), array( 'noindex' => false ), true, true, true );
		$this->assertFalse( isset( $robots['noindex'] ) );
		$this->assertSame( 'large', $robots['max-image-preview'] );
		// …but a private site stays noindex, nofollow.
		$robots = HeadOutput::apply_directives( array(), array(), true, false, true );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
	}

	public function test_paged_urls() {
		$this->assertSame( 'https://example.com/category/news/page/2/', HeadOutput::build_paged_url( 'https://example.com/category/news/', 2, true ) );
		$this->assertSame( 'https://example.com/category/news/page/3', HeadOutput::build_paged_url( 'https://example.com/category/news', 3, true, 'page', false ) );
		$this->assertSame( 'https://example.com/?cat=3&paged=2', HeadOutput::build_paged_url( 'https://example.com/?cat=3', 2, false ) );
		$this->assertSame( 'https://example.com/?paged=4', HeadOutput::build_paged_url( 'https://example.com/', 4, false ) );
		$this->assertSame( 'https://example.com/news/', HeadOutput::build_paged_url( 'https://example.com/news/', 1, true ) );
		$this->assertSame( 'https://example.com/seite/2/', HeadOutput::build_paged_url( 'https://example.com/', 2, true, 'seite' ) );
	}

	public function test_og_locale() {
		$this->assertSame( 'en_US', HeadOutput::og_locale( 'en_US' ) );
		$this->assertSame( 'kn_IN', HeadOutput::og_locale( 'kn' ) );
		$this->assertSame( 'de_DE', HeadOutput::og_locale( 'de_DE_formal' ) );
		$this->assertSame( 'fi_FI', HeadOutput::og_locale( 'fi' ) );
		$this->assertSame( 'en_US', HeadOutput::og_locale( 'xx' ) );
	}

	public function test_twitter_handle() {
		$this->assertSame( '@example', HeadOutput::twitter_handle( 'example' ) );
		$this->assertSame( '@example', HeadOutput::twitter_handle( '@example' ) );
		$this->assertSame( '@example_1', HeadOutput::twitter_handle( 'https://x.com/example_1' ) );
		$this->assertSame( '@abc', HeadOutput::twitter_handle( 'https://twitter.com/abc?lang=en' ) );
		$this->assertSame( '', HeadOutput::twitter_handle( 'not a handle!' ) );
		$this->assertSame( '', HeadOutput::twitter_handle( '' ) );
	}

	public function test_yoast_presenters_are_filtered_by_feature() {
		$presenters = array(
			'Yoast\WP\SEO\Presenters\Title_Presenter',
			'Yoast\WP\SEO\Presenters\Meta_Description_Presenter',
			'Yoast\WP\SEO\Presenters\Canonical_Presenter',
			'Yoast\WP\SEO\Presenters\Robots_Presenter',
			'Yoast\WP\SEO\Presenters\Open_Graph\Title_Presenter',
			'Yoast\WP\SEO\Presenters\Twitter\Card_Presenter',
			'Yoast\WP\SEO\Presenters\Schema_Presenter',
		);
		$kept = Suppressor::filter_yoast_presenters( $presenters, array( 'social', 'schema' ) );
		$this->assertCount( 4, $kept );
		$this->assertNotContains( 'Yoast\WP\SEO\Presenters\Open_Graph\Title_Presenter', $kept );
		$this->assertNotContains( 'Yoast\WP\SEO\Presenters\Schema_Presenter', $kept );
		$this->assertContains( 'Yoast\WP\SEO\Presenters\Title_Presenter', $kept );

		$kept = Suppressor::filter_yoast_presenters( $presenters, array( 'title' ) );
		$this->assertNotContains( 'Yoast\WP\SEO\Presenters\Title_Presenter', $kept );
		// The Open Graph title presenter belongs to "social", not "title".
		$this->assertContains( 'Yoast\WP\SEO\Presenters\Open_Graph\Title_Presenter', $kept );
		$this->assertCount( 7, Suppressor::filter_yoast_presenters( $presenters, array() ) );
	}

	public function test_iso_duration() {
		$this->assertSame( 'PT4M13S', SchemaBuilder::iso_duration( 253 ) );
		$this->assertSame( 'PT1H', SchemaBuilder::iso_duration( 3600 ) );
		$this->assertSame( 'PT1H1M1S', SchemaBuilder::iso_duration( 3661 ) );
		$this->assertSame( '', SchemaBuilder::iso_duration( 0 ) );
	}

	public function test_clean_removes_empty_values_recursively() {
		$clean = SchemaBuilder::clean(
			array(
				'@type'  => 'Organization',
				'name'   => 'X',
				'legal'  => '',
				'sameAs' => array(),
				'logo'   => array(
					'url'   => 'https://example.com/l.png',
					'width' => null,
				),
				'count'  => 0,
				'list'   => array( 'a', '', 'b' ),
			)
		);
		$this->assertSame(
			array(
				'@type' => 'Organization',
				'name'  => 'X',
				'logo'  => array( 'url' => 'https://example.com/l.png' ),
				'count' => 0,
				'list'  => array( 'a', 'b' ),
			),
			$clean
		);
	}

	public function test_answer_html_keeps_only_allowed_tags() {
		if ( ! function_exists( 'wp_kses' ) ) {
			$this->assertTrue( true );
			return;
		}
		$this->assertSame( '<p>Yes <strong>really</strong></p>', SchemaBuilder::answer_html( '<p>Yes <strong>really</strong><script>x</script></p>' ) );
	}

	public function test_breadcrumb_markup_is_accessible_and_escaped() {
		$html = Breadcrumbs::markup(
			array(
				array( 'name' => 'Home', 'url' => 'https://example.com/' ),
				array( 'name' => 'News & <Views>', 'url' => 'https://example.com/news/' ),
				array( 'name' => 'Current', 'url' => 'https://example.com/news/current/' ),
			),
			'›',
			'You are here:'
		);
		$this->assertStringContainsString( '<nav class="searchpilot-breadcrumbs" aria-label="Breadcrumb">', $html );
		$this->assertStringContainsString( '<ol>', $html );
		$this->assertStringContainsString( 'aria-current="page">Current</span>', $html );
		$this->assertStringContainsString( 'News &amp; &lt;Views&gt;', $html );
		$this->assertStringContainsString( 'searchpilot-breadcrumbs-prefix', $html );
		$this->assertSame( 2, substr_count( $html, 'aria-hidden="true">›</span>' ) );

		$without = Breadcrumbs::markup(
			array(
				array( 'name' => 'Home', 'url' => 'https://example.com/' ),
				array( 'name' => 'Current', 'url' => 'https://example.com/c/' ),
			),
			'/',
			'',
			false
		);
		$this->assertStringNotContainsString( 'Current', $without );
		$this->assertStringNotContainsString( 'aria-current', $without );
		$this->assertSame( '', Breadcrumbs::markup( array(), '/' ) );
	}
}
