<?php
/**
 * Tests for structured data / head tag conflict detection on crawled HTML.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Schema\SchemaConflicts;
use SearchPilot\Schema\SchemaValidator;
use SearchPilot\Support\HtmlDocument;

// phpcs:disable

class SchemaConflictsTest extends \PHPUnit\Framework\TestCase {

	private function page( $head, $body = '' ) {
		return HtmlDocument::parse( '<!doctype html><html><head><title>T</title>' . $head . '</head><body>' . $body . '</body></html>', 'https://example.com/' );
	}

	private function script( array $data, $class = '' ) {
		return '<script type="application/ld+json"' . ( '' !== $class ? ' class="' . $class . '"' : '' ) . '>' . json_encode( $data, JSON_UNESCAPED_SLASHES ) . '</script>';
	}

	private function inspect( HtmlDocument $doc, array $sources = array() ) {
		return SchemaConflicts::inspect(
			$doc,
			array(
				'validator' => new SchemaValidator(),
				'names'     => array(
					'searchpilot' => 'SearchPilot',
					'yoast'       => 'Yoast SEO',
				),
				'sources'   => $sources,
			)
		);
	}

	private function our_graph() {
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebSite', '@id' => 'https://example.com/#website', 'url' => 'https://example.com/', 'name' => 'Example' ),
				array( '@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'Example', 'url' => 'https://example.com/', 'logo' => 'https://example.com/logo.png' ),
				array( '@type' => 'WebPage', '@id' => 'https://example.com/p/#webpage', 'url' => 'https://example.com/p/', 'name' => 'P' ),
			),
		);
	}

	public function test_clean_page_has_no_findings() {
		$doc = $this->page( '<link rel="canonical" href="https://example.com/p/"><meta name="description" content="d"><meta property="og:title" content="t">' . $this->script( $this->our_graph(), 'searchpilot-schema' ) );
		$this->assertSame( array(), $this->inspect( $doc ) );
	}

	public function test_invalid_json_is_reported_with_source() {
		$doc      = $this->page( '<script type="application/ld+json" class="yoast-schema-graph">{"@context": "https://schema.org", "@type": "WebSite",}</script>' );
		$findings = $this->inspect( $doc );
		$this->assertArrayHasKey( 'schema_invalid_json', $findings );
		$this->assertSame( 'yoast', $findings['schema_invalid_json']['blocks'][0]['source'] );
		$this->assertSame( 'Yoast SEO', $findings['schema_invalid_json']['blocks'][0]['source_name'] );
	}

	public function test_duplicate_organization_from_two_plugins() {
		$yoast    = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'Organization', '@id' => 'https://example.com/#org', 'name' => 'Example' ),
			),
		);
		$doc      = $this->page( $this->script( $this->our_graph(), 'searchpilot-schema' ) . $this->script( $yoast, 'yoast-schema-graph' ) );
		$findings = $this->inspect( $doc );
		$this->assertArrayHasKey( 'schema_duplicate', $findings );
		$dup = $findings['schema_duplicate']['duplicates'][0];
		$this->assertSame( 'organization', $dup['group'] );
		$this->assertContains( 'SearchPilot', $dup['sources'] );
		$this->assertContains( 'Yoast SEO', $dup['sources'] );
	}

	public function test_theme_article_script_duplicates_article() {
		$ours           = $this->our_graph();
		$ours['@graph'][] = array( '@type' => 'Article', '@id' => 'https://example.com/p/#article', 'headline' => 'P', 'author' => 'A', 'image' => 'https://example.com/i.jpg', 'datePublished' => '2026-09-30' );
		$theme          = array( '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'P' );
		$findings       = $this->inspect( $this->page( $this->script( $ours, 'searchpilot-schema' ) . $this->script( $theme ) ) );
		$this->assertArrayHasKey( 'schema_duplicate', $findings );
		$groups = array_column( $findings['schema_duplicate']['duplicates'], 'group' );
		$this->assertContains( 'article', $groups );
		$this->assertNotContains( 'organization', $groups );
	}

	public function test_nested_publisher_is_not_a_duplicate() {
		$theme    = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Product',
			'name'      => 'Thing',
			'publisher' => array( '@type' => 'Organization', 'name' => 'Example' ),
		);
		$findings = $this->inspect( $this->page( $this->script( $this->our_graph(), 'searchpilot-schema' ) . $this->script( $theme ) ) );
		$this->assertFalse( isset( $findings[ 'schema_duplicate' ] ) );
	}

	public function test_microdata_conflict_only_when_json_ld_has_the_same_entity() {
		$micro    = '<article itemscope itemtype="https://schema.org/BlogPosting"><h1 itemprop="headline">P</h1></article>';
		$ours     = $this->our_graph();
		$findings = $this->inspect( $this->page( $this->script( $ours, 'searchpilot-schema' ), $micro ) );
		$this->assertFalse( isset( $findings[ 'schema_conflict_microdata' ] ) );

		$ours['@graph'][] = array( '@type' => 'Article', '@id' => 'https://example.com/p/#article', 'headline' => 'P', 'author' => 'A', 'image' => 'https://example.com/i.jpg', 'datePublished' => '2026-09-30' );
		$findings         = $this->inspect( $this->page( $this->script( $ours, 'searchpilot-schema' ), $micro ) );
		$this->assertArrayHasKey( 'schema_conflict_microdata', $findings );
		$this->assertContains( 'BlogPosting', $findings['schema_conflict_microdata']['types'] );
	}

	public function test_own_graph_errors_are_reported() {
		$ours             = $this->our_graph();
		$ours['@graph'][] = array( '@type' => 'Article', '@id' => 'https://example.com/p/#article' );
		$findings         = $this->inspect( $this->page( $this->script( $ours, 'searchpilot-schema' ) ) );
		$this->assertArrayHasKey( 'schema_missing_required', $findings );
		$this->assertGreaterThan( 0, $findings['schema_missing_required']['count'] );
		$types = array_column( $findings['schema_missing_required']['errors'], 'property' );
		$this->assertContains( 'headline', $types );
	}

	public function test_other_plugins_graph_is_not_validated_as_ours() {
		$broken   = array( '@context' => 'https://schema.org', '@type' => 'Article' );
		$findings = $this->inspect( $this->page( $this->script( $broken, 'yoast-schema-graph' ) ) );
		$this->assertFalse( isset( $findings[ 'schema_missing_required' ] ) );
	}

	public function test_duplicate_head_tags_name_sources() {
		$head     = '<link rel="canonical" href="https://example.com/p/"><link rel="canonical" href="https://example.com/p/?x=1">'
			. '<meta name="description" content="a"><meta name="description" content="b">'
			. '<meta property="og:title" content="t">';
		$findings = $this->inspect(
			$this->page( $head ),
			array(
				'canonical'   => array( 'SearchPilot', 'Yoast SEO' ),
				'description' => array( 'SearchPilot' ),
			)
		);
		$this->assertArrayHasKey( 'duplicate_meta_output', $findings );
		$tags = array_column( $findings['duplicate_meta_output']['tags'], 'sources', 'tag' );
		$this->assertArrayHasKey( 'canonical', $tags );
		$this->assertArrayHasKey( 'description', $tags );
		$this->assertFalse( isset( $tags[ 'og:title' ] ) );
		$this->assertSame( array( 'SearchPilot', 'Yoast SEO' ), $tags['canonical'] );
		$this->assertCount( 2, $tags['description'] );
	}

	public function test_group_of() {
		$this->assertSame( 'organization', SchemaConflicts::group_of( 'NewsMediaOrganization' ) );
		$this->assertSame( 'article', SchemaConflicts::group_of( 'NewsArticle' ) );
		$this->assertSame( 'breadcrumb', SchemaConflicts::group_of( 'BreadcrumbList' ) );
		$this->assertSame( '', SchemaConflicts::group_of( 'Person' ) );
	}
}
