<?php
/**
 * Tests for the structured data validator.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Schema\SchemaValidator;

// phpcs:disable

class SchemaValidatorTest extends \PHPUnit\Framework\TestCase {

	private function validator() {
		return new SchemaValidator();
	}

	private function props( array $entries ) {
		return array_map(
			static function ( $e ) {
				return $e['type'] . '.' . $e['property'];
			},
			$entries
		);
	}

	private function article( array $override = array() ) {
		return array_merge(
			array(
				'@type'         => 'Article',
				'@id'           => 'https://example.com/post/#article',
				'headline'      => 'A good headline',
				'image'         => array( '@id' => 'https://example.com/post/#primaryimage' ),
				'datePublished' => '2026-09-30T08:00:00+05:30',
				'dateModified'  => '2026-09-30T09:00:00+05:30',
				'author'        => array( '@id' => 'https://example.com/#/schema/person/abc' ),
			),
			$override
		);
	}

	private function graph( array $nodes ) {
		$base = array(
			array(
				'@type'     => 'WebSite',
				'@id'       => 'https://example.com/#website',
				'url'       => 'https://example.com/',
				'name'      => 'Example',
				'publisher' => array( '@id' => 'https://example.com/#organization' ),
			),
			array(
				'@type' => 'Organization',
				'@id'   => 'https://example.com/#organization',
				'name'  => 'Example Media',
				'url'   => 'https://example.com/',
				'logo'  => array(
					'@type'  => 'ImageObject',
					'url'    => 'https://example.com/logo.png',
					'width'  => 512,
					'height' => 512,
				),
			),
			array(
				'@type'  => 'ImageObject',
				'@id'    => 'https://example.com/post/#primaryimage',
				'url'    => 'https://example.com/img.jpg',
				'width'  => 1600,
				'height' => 900,
			),
			array(
				'@type' => 'Person',
				'@id'   => 'https://example.com/#/schema/person/abc',
				'name'  => 'Asha',
			),
		);
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array_merge( $base, $nodes ),
		);
	}

	public function test_complete_graph_is_valid() {
		$result = $this->validator()->validate( $this->graph( array( $this->article() ) ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertTrue( $result['valid'] );
		$this->assertContains( 'Article', $result['types'] );
	}

	public function test_missing_headline_is_an_error() {
		$article = $this->article();
		unset( $article['headline'] );
		$result = $this->validator()->validate( $this->graph( array( $article ) ) );
		$this->assertContains( 'Article.headline', $this->props( $result['errors'] ) );
		$this->assertFalse( $result['valid'] );
	}

	public function test_long_headline_is_a_warning() {
		$result = $this->validator()->validate( $this->graph( array( $this->article( array( 'headline' => str_repeat( 'a', 111 ) ) ) ) ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertContains( 'Article.headline', $this->props( $result['warnings'] ) );
	}

	public function test_headline_length_counts_characters_not_bytes() {
		$kannada = str_repeat( 'ಕನ್ನಡ', 20 ); // 100 characters, 300 bytes.
		$result  = $this->validator()->validate( $this->graph( array( $this->article( array( 'headline' => $kannada ) ) ) ) );
		$this->assertNotContains( 'Article.headline', $this->props( $result['warnings'] ) );
	}

	public function test_missing_or_small_image_is_a_warning() {
		$article = $this->article( array( '@type' => 'NewsArticle' ) );
		unset( $article['image'] );
		$result = $this->validator()->validate( $this->graph( array( $article ) ) );
		$this->assertContains( 'NewsArticle.image', $this->props( $result['warnings'] ) );

		$small  = $this->article( array( 'image' => array( '@type' => 'ImageObject', 'url' => 'https://example.com/s.jpg', 'width' => 800, 'height' => 450 ) ) );
		$result = $this->validator()->validate( $this->graph( array( $small ) ) );
		$this->assertContains( 'Article.image', $this->props( $result['warnings'] ) );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_author_reference_without_name_is_an_error() {
		$graph = $this->graph(
			array(
				$this->article( array( 'author' => array( '@id' => 'https://example.com/#/schema/person/nameless' ) ) ),
				array(
					'@type' => 'Person',
					'@id'   => 'https://example.com/#/schema/person/nameless',
					'url'   => 'https://example.com/author/x/',
				),
			)
		);
		$result = $this->validator()->validate( $graph );
		$this->assertContains( 'Article.author.name', $this->props( $result['errors'] ) );
		$this->assertContains( 'Person.name', $this->props( $result['errors'] ) );
	}

	public function test_string_author_is_accepted_and_missing_author_warns() {
		$result = $this->validator()->validate( $this->graph( array( $this->article( array( 'author' => 'Asha' ) ) ) ) );
		$this->assertSame( array(), $result['errors'] );
		$article = $this->article( array( '@type' => 'BlogPosting' ) );
		unset( $article['author'] );
		$result = $this->validator()->validate( $this->graph( array( $article ) ) );
		$this->assertContains( 'BlogPosting.author', $this->props( $result['warnings'] ) );
	}

	public function test_invalid_dates_are_errors() {
		$result = $this->validator()->validate( $this->graph( array( $this->article( array( 'datePublished' => '30/09/2026' ) ) ) ) );
		$this->assertContains( 'Article.datePublished', $this->props( $result['errors'] ) );
		$this->assertTrue( SchemaValidator::is_date( '2026-09-30' ) );
		$this->assertTrue( SchemaValidator::is_date( '2026-09-30T08:00:00Z' ) );
		$this->assertTrue( SchemaValidator::is_date( '2026-09-30T08:00:00+05:30' ) );
		$this->assertFalse( SchemaValidator::is_date( 'yesterday' ) );
	}

	public function test_organization_logo_rules() {
		$graph = array(
			'@type' => 'Organization',
			'name'  => 'Tiny Logo Co',
			'url'   => 'https://example.com/',
			'logo'  => array(
				'@type'  => 'ImageObject',
				'url'    => 'https://example.com/logo.png',
				'width'  => 100,
				'height' => 100,
			),
		);
		$result = $this->validator()->validate( $graph );
		$this->assertContains( 'Organization.logo', $this->props( $result['errors'] ) );

		unset( $graph['logo'], $graph['name'] );
		$result = $this->validator()->validate( $graph );
		$this->assertContains( 'Organization.logo', $this->props( $result['warnings'] ) );
		$this->assertContains( 'Organization.name', $this->props( $result['errors'] ) );

		$result = $this->validator()->validate( array( '@type' => 'NewsMediaOrganization', 'name' => 'Paper', 'logo' => 'https://example.com/l.png' ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertContains( 'NewsMediaOrganization.url', $this->props( $result['warnings'] ) );
	}

	public function test_breadcrumb_list_rules() {
		$valid  = array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array(
				array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://example.com/' ),
				array( '@type' => 'ListItem', 'position' => 2, 'name' => 'News', 'item' => 'https://example.com/news/' ),
				array( '@type' => 'ListItem', 'position' => 3, 'name' => 'Current' ),
			),
		);
		$result = $this->validator()->validate( $valid );
		$this->assertSame( array(), $result['errors'] );

		$broken                                  = $valid;
		$broken['itemListElement'][1]['item']    = '';
		unset( $broken['itemListElement'][0]['position'] );
		unset( $broken['itemListElement'][2]['name'] );
		$result = $this->validator()->validate( $broken );
		$props  = $this->props( $result['errors'] );
		$this->assertContains( 'BreadcrumbList.position', $props );
		$this->assertContains( 'BreadcrumbList.item', $props );
		$this->assertContains( 'BreadcrumbList.name', $props );

		$result = $this->validator()->validate( array( '@type' => 'BreadcrumbList', 'itemListElement' => array() ) );
		$this->assertContains( 'BreadcrumbList.itemListElement', $this->props( $result['errors'] ) );
	}

	public function test_faq_rules_with_type_array() {
		$page   = array(
			'@type'      => array( 'WebPage', 'FAQPage' ),
			'@id'        => 'https://example.com/faq/#webpage',
			'mainEntity' => array(
				array(
					'@type'          => 'Question',
					'name'           => 'What is it?',
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A plugin.' ),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'No answer?',
					'acceptedAnswer' => array( '@type' => 'Answer' ),
				),
				array(
					'@type' => 'Question',
				),
			),
		);
		$result = $this->validator()->validate( $page );
		$props  = $this->props( $result['errors'] );
		$this->assertContains( 'Question.acceptedAnswer.text', $props );
		$this->assertContains( 'Question.name', $props );
		$this->assertContains( 'WebPage', $result['types'] );
		$this->assertContains( 'FAQPage', $result['types'] );
	}

	public function test_video_rules() {
		$result = $this->validator()->validate(
			array(
				'@type'    => 'VideoObject',
				'name'     => 'Clip',
				'duration' => '4 minutes',
			)
		);
		$errors   = $this->props( $result['errors'] );
		$warnings = $this->props( $result['warnings'] );
		$this->assertContains( 'VideoObject.thumbnailUrl', $errors );
		$this->assertContains( 'VideoObject.uploadDate', $errors );
		$this->assertContains( 'VideoObject.description', $warnings );
		$this->assertContains( 'VideoObject.contentUrl', $warnings );
		$this->assertContains( 'VideoObject.duration', $warnings );

		$result = $this->validator()->validate(
			array(
				'@type'        => 'VideoObject',
				'name'         => 'Clip',
				'description'  => 'A clip.',
				'thumbnailUrl' => 'https://i.ytimg.com/vi/x/hqdefault.jpg',
				'uploadDate'   => '2026-09-01T10:00:00+00:00',
				'embedUrl'     => 'https://www.youtube.com/embed/x',
				'duration'     => 'PT4M13S',
			)
		);
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_website_and_image_rules() {
		$result = $this->validator()->validate( array( '@type' => 'WebSite', 'url' => 'https://example.com/' ) );
		$this->assertContains( 'WebSite.name', $this->props( $result['errors'] ) );
		$result = $this->validator()->validate( array( '@type' => 'ImageObject', 'caption' => 'x' ) );
		$this->assertContains( 'ImageObject.url', $this->props( $result['errors'] ) );
	}

	public function test_dangling_reference_warns() {
		$graph  = $this->graph( array( $this->article( array( 'isPartOf' => array( '@id' => 'https://example.com/post/#webpage' ) ) ) ) );
		$result = $this->validator()->validate( $graph );
		$this->assertContains( 'Thing.@id', $this->props( $result['warnings'] ) );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_empty_input_is_an_error() {
		$result = $this->validator()->validate( array() );
		$this->assertFalse( $result['valid'] );
	}

	public function test_top_nodes_and_durations() {
		$this->assertCount( 2, SchemaValidator::top_nodes( array( '@graph' => array( array( '@type' => 'A' ), array( '@type' => 'B' ) ) ) ) );
		$this->assertCount( 2, SchemaValidator::top_nodes( array( array( '@type' => 'A' ), array( '@context' => 'https://schema.org', '@type' => 'B' ) ) ) );
		$this->assertCount( 1, SchemaValidator::top_nodes( array( '@type' => 'A' ) ) );
		$this->assertCount( 0, SchemaValidator::top_nodes( 'nope' ) );
		$this->assertTrue( SchemaValidator::is_duration( 'PT1H2M3S' ) );
		$this->assertTrue( SchemaValidator::is_duration( 'P1D' ) );
		$this->assertFalse( SchemaValidator::is_duration( 'PT' ) );
		$this->assertFalse( SchemaValidator::is_duration( '90' ) );
	}
}
