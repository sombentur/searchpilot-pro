<?php
/**
 * Tests for the URL Inspection status mapper.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Indexer\StatusMapper;

/**
 * StatusMapper tests.
 */
class StatusMapperTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Builds a normalized inspection result.
	 *
	 * @param string $coverage Coverage state.
	 * @param string $verdict  Verdict.
	 * @param array  $extra    Extra keys.
	 * @return array
	 */
	private function result( $coverage, $verdict = 'NEUTRAL', array $extra = array() ) {
		return array_merge(
			array(
				'verdict'          => $verdict,
				'coverage_state'   => $coverage,
				'indexing_state'   => 'INDEXING_ALLOWED',
				'robots_txt_state' => 'ALLOWED',
				'last_crawl_time'  => '2026-09-28T10:15:30Z',
				'google_canonical' => 'https://example.com/a/',
				'user_canonical'   => 'https://example.com/a/',
			),
			$extra
		);
	}

	public function test_indexed_states() {
		$this->assertSame( 'indexed', StatusMapper::map( $this->result( 'Submitted and indexed', 'PASS' ) )['index_status'] );
		$this->assertSame( 'indexed', StatusMapper::map( $this->result( 'Indexed, not submitted in sitemap', 'PASS' ) )['index_status'] );
	}

	public function test_indexed_blocked_by_robots_has_warning() {
		$m = StatusMapper::map( $this->result( 'Indexed, though blocked by robots.txt', 'PASS' ) );
		$this->assertSame( 'indexed', $m['index_status'] );
		$this->assertSame( 'indexed_blocked_robots', $m['warning'] );
	}

	public function test_not_indexed_states() {
		$this->assertSame( 'crawled_not_indexed', StatusMapper::map( $this->result( 'Crawled - currently not indexed' ) )['index_status'] );
		$this->assertSame( 'discovered_not_indexed', StatusMapper::map( $this->result( 'Discovered - currently not indexed' ) )['index_status'] );
		$this->assertSame( 'unknown_to_google', StatusMapper::map( $this->result( 'URL is unknown to Google' ) )['index_status'] );
	}

	public function test_dash_variants() {
		$this->assertSame( 'crawled_not_indexed', StatusMapper::map( $this->result( 'Crawled – currently not indexed' ) )['index_status'] );
		$this->assertSame( 'discovered_not_indexed', StatusMapper::map( $this->result( 'Discovered—currently not indexed' ) )['index_status'] );
	}

	public function test_curly_quotes_and_case() {
		$this->assertSame( 'excluded_noindex', StatusMapper::map( $this->result( 'Excluded by ‘noindex’ tag' ) )['index_status'] );
		$this->assertSame( 'excluded_noindex', StatusMapper::map( $this->result( "EXCLUDED BY 'NOINDEX' TAG" ) )['index_status'] );
		$this->assertSame( 'excluded_noindex', StatusMapper::map( $this->result( 'Excluded by “noindex” tag' ) )['index_status'] );
	}

	public function test_robots_blocked() {
		$this->assertSame( 'blocked_robots', StatusMapper::map( $this->result( 'Blocked by robots.txt' ) )['index_status'] );
	}

	public function test_duplicates() {
		foreach ( array( 'Duplicate, Google chose different canonical than user', 'Duplicate without user-selected canonical', 'Duplicate, submitted URL not selected as canonical' ) as $text ) {
			$this->assertSame( 'duplicate_canonical', StatusMapper::map( $this->result( $text ) )['index_status'], $text );
		}
		$this->assertSame( 'alternate_canonical', StatusMapper::map( $this->result( 'Alternate page with proper canonical tag' ) )['index_status'] );
	}

	public function test_errors() {
		$this->assertSame( 'redirect', StatusMapper::map( $this->result( 'Page with redirect' ) )['index_status'] );
		$this->assertSame( 'not_found', StatusMapper::map( $this->result( 'Not found (404)' ) )['index_status'] );
		$this->assertSame( 'soft_404', StatusMapper::map( $this->result( 'Soft 404' ) )['index_status'] );
		$this->assertSame( 'server_error', StatusMapper::map( $this->result( 'Server error (5xx)' ) )['index_status'] );
		$this->assertSame( 'not_found', StatusMapper::map( $this->result( 'Submitted URL not found (404)' ) )['index_status'] );
	}

	public function test_fallbacks() {
		$this->assertSame( 'indexed', StatusMapper::map( $this->result( '', 'PASS' ) )['index_status'] );
		$this->assertSame( 'excluded_noindex', StatusMapper::map( $this->result( 'Something new', 'NEUTRAL', array( 'indexing_state' => 'BLOCKED_BY_META_TAG' ) ) )['index_status'] );
		$this->assertSame( 'blocked_robots', StatusMapper::map( $this->result( '', 'FAIL', array( 'indexing_state' => 'BLOCKED_BY_ROBOTS_TXT' ) ) )['index_status'] );
		$this->assertSame( 'not_indexed', StatusMapper::map( $this->result( 'Blocked due to access forbidden (403)', 'FAIL' ) )['index_status'] );
		$this->assertSame( 'not_indexed', StatusMapper::map( array() )['index_status'] );
	}

	public function test_fields() {
		$m = StatusMapper::map( $this->result( 'Submitted and indexed', 'pass' ) );
		$this->assertSame( 'PASS', $m['verdict'] );
		$this->assertSame( '2026-09-28 10:15:30', $m['last_crawled'] );
		$this->assertSame( 'https://example.com/a/', $m['google_canonical'] );
		$this->assertSame( 'Submitted and indexed', $m['coverage_state'] );
		$this->assertNull( StatusMapper::map( $this->result( 'x', 'PASS', array( 'last_crawl_time' => null ) ) )['last_crawled'] );
		$this->assertNull( StatusMapper::mysql_time( 'not a date' ) );
	}

	public function test_normalize() {
		$this->assertSame( "excluded by 'noindex' tag", StatusMapper::normalize( '  Excluded   by ‘noindex’ tag. ' ) );
		$this->assertSame( 'crawled - currently not indexed', StatusMapper::normalize( 'Crawled-currently not indexed' ) );
	}
}
