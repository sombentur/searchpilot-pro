<?php
/**
 * Tests for the page inspector.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Indexer\PageInspector;

/**
 * PageInspector tests.
 */
class PageInspectorTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Inspector.
	 *
	 * @var PageInspector
	 */
	private $inspector;

	protected function setUp(): void {
		$GLOBALS['spp_test_home'] = 'https://example.com/';
		$this->inspector          = new PageInspector();
	}

	/**
	 * Builds an HTML page.
	 *
	 * @param string $head Extra head markup.
	 * @param string $body Body markup.
	 * @param string $class Body class.
	 * @return string
	 */
	private function page( $head, $body = '<h1>Hello</h1><p>Some words here for the page.</p>', $class = 'single' ) {
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Test page</title>' . $head . '</head><body class="' . $class . '"><main>' . $body . '</main></body></html>';
	}

	/**
	 * Runs the inspector on a 200 HTML response.
	 *
	 * @param string $html    HTML.
	 * @param array  $context Context.
	 * @param array  $headers Headers.
	 * @param string $url     URL.
	 * @return array
	 */
	private function run_page( $html, array $context = array(), array $headers = array(), $url = 'https://example.com/a/' ) {
		return $this->inspector->analyze(
			array(
				'url'          => $url,
				'final_url'    => $url,
				'status'       => 200,
				'first_status' => 200,
				'headers'      => array_merge( array( 'content-type' => 'text/html; charset=UTF-8' ), $headers ),
				'body'         => $html,
				'hops'         => array(),
			),
			array_merge( array( 'indexable' => true ), $context )
		);
	}

	public function test_clean_page() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://example.com/a/"><meta name="description" content="d"><meta property="og:title" content="t">' ) );
		$this->assertTrue( $f['is_html'] );
		$this->assertSame( array(), $f['codes'] );
		$this->assertSame( 1, $f['title_count'] );
		$this->assertSame( 1, $f['description_count'] );
		$this->assertSame( 1, $f['og_title_count'] );
		$this->assertSame( 1, $f['h1_count'] );
		$this->assertSame( 'https://example.com/a/', $f['canonical'] );
		$this->assertFalse( $f['noindex'] );
		$this->assertGreaterThan( 3, $f['word_count'] );
	}

	public function test_meta_noindex_on_indexable_page() {
		$f = $this->run_page( $this->page( '<meta name="robots" content="noindex, follow"><link rel="canonical" href="https://example.com/a/">' ) );
		$this->assertTrue( $f['noindex'] );
		$this->assertSame( 'meta', $f['noindex_source'] );
		$this->assertContains( 'noindex', $f['codes'] );
	}

	public function test_googlebot_meta_and_header_noindex() {
		$f = $this->run_page( $this->page( '<meta name="googlebot" content="none">' ), array(), array( 'x-robots-tag' => 'noindex' ) );
		$this->assertTrue( $f['noindex'] );
		$this->assertSame( 'both', $f['noindex_source'] );
	}

	public function test_noindex_not_flagged_when_intended() {
		$f = $this->run_page( $this->page( '<meta name="robots" content="noindex">' ), array( 'indexable' => false ) );
		$this->assertTrue( $f['noindex'] );
		$this->assertNotContains( 'noindex', $f['codes'] );
	}

	public function test_noindex_missing_when_expected() {
		$f = $this->run_page( $this->page( '' ), array( 'indexable' => false, 'expect_noindex' => true ) );
		$this->assertContains( 'noindex_missing', $f['codes'] );
	}

	public function test_x_robots_parsing() {
		$this->assertSame( array( 'noindex', 'nofollow' ), PageInspector::parse_x_robots( 'noindex, nofollow' ) );
		$this->assertSame( array(), PageInspector::parse_x_robots( 'otherbot: noindex' ) );
		$this->assertSame( array( 'noindex' ), PageInspector::parse_x_robots( array( 'bingbot: noarchive', 'googlebot: noindex' ) ) );
		$this->assertSame( array( 'unavailable_after: 25 jun 2030 15:00:00 pst' ), PageInspector::parse_x_robots( 'unavailable_after: 25 Jun 2030 15:00:00 PST' ) );
		$this->assertSame( array( 'max-snippet:-1', 'noimageindex' ), PageInspector::parse_x_robots( 'max-snippet:-1, noimageindex' ) );
	}

	public function test_multiple_and_relative_canonicals() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="/a/"><link rel="canonical" href="https://example.com/b/">' ) );
		$this->assertSame( 2, $f['canonical_count'] );
		$this->assertSame( 2, $f['canonical_distinct'] );
		$this->assertContains( 'multiple_canonicals', $f['codes'] );
		$this->assertContains( 'canonical_relative', $f['codes'] );
		$this->assertSame( 'https://example.com/a/', $f['canonicals'][0]['url'] );
	}

	public function test_identical_duplicate_canonicals_are_not_a_conflict() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://example.com/a/"><link rel="canonical" href="https://example.com/a/">' ) );
		$this->assertSame( 2, $f['canonical_count'] );
		$this->assertNotContains( 'multiple_canonicals', $f['codes'] );
	}

	public function test_cross_domain_and_elsewhere() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://other.org/a/">' ) );
		$this->assertContains( 'canonical_cross_domain', $f['codes'] );
		$this->assertContains( 'canonical_elsewhere', $f['codes'] );
		$www = $this->run_page( $this->page( '<link rel="canonical" href="https://www.example.com/a/">' ) );
		$this->assertNotContains( 'canonical_cross_domain', $www['codes'] );
	}

	public function test_canonical_on_another_port_is_cross_domain() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://example.com:8080/a/">' ) );
		$this->assertContains( 'canonical_cross_domain', $f['codes'] );
		$this->assertTrue( PageInspector::is_cross_domain( 'http://example.com:6379/x', 'http://example.com/a/' ) );
		$this->assertFalse( PageInspector::is_cross_domain( 'https://example.com:443/a/', 'http://example.com/a/' ) );
		$this->assertFalse( PageInspector::is_cross_domain( 'https://www.example.com/a/', 'https://example.com/a/' ) );
		$this->assertTrue( PageInspector::is_cross_domain( 'http://127.0.0.1/a/', 'http://127.0.0.1:9400/a/' ) );
	}

	public function test_crawler_fetches_only_the_site_origin() {
		$this->assertTrue( \SearchPilot\Indexer\Crawler::is_fetchable( 'https://example.com/a/' ) );
		$this->assertTrue( \SearchPilot\Indexer\Crawler::is_fetchable( 'http://www.example.com/a/' ) );
		$this->assertTrue( \SearchPilot\Indexer\Crawler::is_fetchable( 'https://example.com:443/a/' ) );
		$this->assertTrue( \SearchPilot\Indexer\Crawler::is_fetchable( '/relative/path/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'http://example.com:6379/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'https://other.org/a/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'https://user:pass@example.com/a/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'ftp://example.com/a/' ) );
		$GLOBALS['spp_test_home'] = 'http://127.0.0.1:9400/';
		$this->assertTrue( \SearchPilot\Indexer\Crawler::is_fetchable( 'http://127.0.0.1:9400/a/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'http://127.0.0.1/a/' ) );
		$this->assertFalse( \SearchPilot\Indexer\Crawler::is_fetchable( 'http://127.0.0.1:22/' ) );
		$GLOBALS['spp_test_home'] = 'https://example.com/';
	}

	public function test_expected_canonical_respected() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://example.com/a/">' ), array( 'expected_canonical' => 'https://example.com/a/' ) );
		$this->assertNotContains( 'canonical_elsewhere', $f['codes'] );
	}

	public function test_pagination_canonical() {
		$f = $this->run_page( $this->page( '<link rel="canonical" href="https://example.com/news/">' ), array( 'pagination_root' => 'https://example.com/news/' ), array(), 'https://example.com/news/page/2/' );
		$this->assertContains( 'pagination_canonical', $f['codes'] );
		$this->assertNotContains( 'canonical_elsewhere', $f['codes'] );
	}

	public function test_link_header_canonical() {
		$this->assertSame( 'https://example.com/doc/', PageInspector::canonical_from_link_header( '<https://example.com/doc/>; rel="canonical"', 'https://example.com/x' ) );
		$this->assertSame( '', PageInspector::canonical_from_link_header( '<https://example.com/a>; rel="preload"', 'https://example.com/x' ) );
	}

	public function test_title_and_json_ld() {
		$html = '<!doctype html><html><head><title>A</title><title>B</title><script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script><script type="application/ld+json">{bad json</script></head><body><h1>x</h1><h1>y</h1></body></html>';
		$f    = $this->run_page( $html );
		$this->assertSame( 2, $f['title_count'] );
		$this->assertContains( 'multiple_titles', $f['codes'] );
		$this->assertSame( 2, $f['json_ld']['count'] );
		$this->assertSame( 1, $f['json_ld']['invalid'] );
		$this->assertContains( 'Article', $f['json_ld']['types'] );
		$this->assertContains( 'invalid_json_ld', $f['codes'] );
		$this->assertSame( 2, $f['h1_count'] );
	}

	public function test_soft_404() {
		$f = $this->run_page( $this->page( '', '<p>Sorry.</p>', 'error404' ) );
		$this->assertTrue( $f['soft_404'] );
		$this->assertContains( 'soft_404', $f['codes'] );
		$g = $this->run_page( '<!doctype html><html><head><title>Page not found – Site</title></head><body><p>Nothing here</p></body></html>' );
		$this->assertTrue( $g['soft_404'] );
		$news = $this->run_page( '<!doctype html><html><head><title>404 new jobs announced</title></head><body><p>Short story.</p></body></html>' );
		$this->assertFalse( $news['soft_404'] );
	}

	public function test_http_errors_and_redirects() {
		$f = $this->inspector->analyze(
			array(
				'url'          => 'https://example.com/old/',
				'final_url'    => 'https://example.com/gone/',
				'status'       => 404,
				'first_status' => 301,
				'headers'      => array( 'content-type' => 'text/html' ),
				'body'         => '<html></html>',
				'hops'         => array(
					array( 'url' => 'https://example.com/old/', 'status' => 301, 'location' => 'https://example.com/mid/' ),
					array( 'url' => 'https://example.com/mid/', 'status' => 302, 'location' => 'https://example.com/gone/' ),
				),
			)
		);
		$this->assertContains( 'redirect_chain', $f['codes'] );
		$this->assertContains( 'http_4xx', $f['codes'] );
		$this->assertContains( 'redirect_to_error', $f['codes'] );
		$this->assertSame( 2, $f['hop_count'] );
		$this->assertNull( $f['doc'] );
	}

	public function test_redirected_page_canonical_judged_on_destination() {
		$f = $this->inspector->analyze(
			array(
				'url'          => 'https://example.com/old/',
				'final_url'    => 'https://example.com/new/',
				'status'       => 200,
				'first_status' => 301,
				'headers'      => array( 'content-type' => 'text/html' ),
				'body'         => $this->page( '<link rel="canonical" href="https://example.com/new/">' ),
				'hops'         => array( array( 'url' => 'https://example.com/old/', 'status' => 301, 'location' => 'https://example.com/new/' ) ),
			),
			array(
				'indexable'          => true,
				'expected_canonical' => 'https://example.com/old/',
			)
		);
		$this->assertContains( 'redirect', $f['codes'] );
		$this->assertNotContains( 'canonical_elsewhere', $f['codes'] );
	}

	public function test_loop_and_transport_failure() {
		$loop = $this->inspector->analyze( array( 'url' => 'https://example.com/a/', 'status' => 301, 'hops' => array( array(), array() ), 'loop' => true ) );
		$this->assertSame( array( 'redirect_loop' ), $loop['codes'] );
		$fail = $this->inspector->analyze( array( 'url' => 'https://example.com/a/', 'status' => 0 ) );
		$this->assertSame( array( 'fetch_failed' ), $fail['codes'] );
		$blocked = $this->inspector->analyze( array( 'url' => 'https://example.com/a/', 'status' => 403, 'headers' => array( 'content-type' => 'text/html' ), 'body' => 'Forbidden' ) );
		$this->assertSame( array( 'fetch_blocked' ), $blocked['codes'] );
		$server = $this->inspector->analyze( array( 'url' => 'https://example.com/a/', 'status' => 503 ) );
		$this->assertSame( array( 'http_5xx' ), $server['codes'] );
	}

	public function test_not_html() {
		$f = $this->run_page( '%PDF-1.4', array(), array( 'content-type' => 'application/pdf' ) );
		$this->assertFalse( $f['is_html'] );
		$this->assertSame( array( 'not_html' ), $f['codes'] );
	}

	public function test_kannada_word_count_and_hreflang() {
		$html = '<!doctype html><html><head><title>ಸುದ್ದಿ</title><link rel="alternate" hreflang="en" href="https://example.com/en/a/"><link rel="alternate" hreflang="kn" href="https://example.com/a/"></head><body><article><h1>ಕರ್ನಾಟಕ</h1><p>ಕರ್ನಾಟಕ ಸರ್ಕಾರ ಹೊಸ ಯೋಜನೆ ಘೋಷಿಸಿದೆ</p></article></body></html>';
		$f    = $this->run_page( $html );
		$this->assertSame( 6, $f['word_count'] );
		$this->assertSame( 2, count( $f['hreflang'] ) );
	}

	public function test_storable_drops_dom() {
		$f = $this->run_page( $this->page( '' ) );
		$this->assertNotNull( $f['doc'] );
		$s = PageInspector::storable( $f );
		$this->assertFalse( array_key_exists( 'doc', $s ) );
	}
}
