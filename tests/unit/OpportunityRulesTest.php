<?php
/**
 * Tests for the opportunity detection rules.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Analytics\CtrModel;
use SearchPilot\Analytics\Opportunities;

/**
 * Pure rule functions over snapshot rows.
 */
class OpportunityRulesTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Builds a page row.
	 *
	 * @param string $slug Slug.
	 * @param array  $v    Values.
	 * @param array  $ctx  Context.
	 * @return array
	 */
	private static function page( $slug, array $v, array $ctx = array() ) {
		return array_merge(
			array(
				'query'            => '',
				'page'             => 'https://example.com/' . $slug . '/',
				'object_id'        => 0,
				'clicks'           => 0,
				'impressions'      => 0,
				'ctr'              => 0.0,
				'position'         => 0.0,
				'prev_clicks'      => 0,
				'prev_impressions' => 0,
				'prev_ctr'         => 0.0,
				'prev_position'    => 0.0,
				'ctx'              => array_merge(
					array(
						'internal'  => true,
						'indexable' => true,
						'post_id'   => 0,
					),
					$ctx
				),
			),
			$v
		);
	}

	/**
	 * Default thresholds.
	 *
	 * @return array
	 */
	private static function t() {
		return array(
			'min_impressions'        => 100,
			'high_impressions'       => 1000,
			'meaningful_impressions' => 300,
			'min_clicks'             => 10,
			'pages'                  => 50,
		);
	}

	/**
	 * Types of findings.
	 *
	 * @param array $findings Findings.
	 * @return string[]
	 */
	private static function pages_of( array $findings ) {
		return array_column( $findings, 'page' );
	}

	public function test_thresholds_are_relative_to_site_size() {
		$pages = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$pages[] = self::page( 'p' . $i, array( 'impressions' => $i * 1000, 'prev_clicks' => $i * 10 ) );
		}
		$t = Opportunities::thresholds( $pages, 100 );
		$this->assertSame( 8000, $t['high_impressions'], 'Top 20% of pages by impressions' );
		$this->assertSame( 5000, $t['meaningful_impressions'] );
		$this->assertSame( 50, $t['min_clicks'] );
		// A tiny site never goes below the configured minimum.
		$t = Opportunities::thresholds( array( self::page( 'a', array( 'impressions' => 20 ) ) ), 100 );
		$this->assertSame( 100, $t['high_impressions'] );
		$this->assertSame( 10, $t['min_clicks'] );
		$this->assertSame( 0, Opportunities::percentile( array(), 0.5 ) );
	}

	public function test_low_ctr() {
		$model = new CtrModel();
		$pages = array(
			// Position 2, 5000 impressions, 1% CTR vs ~15% expected → flagged.
			self::page( 'low', array( 'impressions' => 5000, 'clicks' => 50, 'position' => 2.0 ), array( 'top_query' => array( 'query' => 'green tea', 'clicks' => 30, 'impressions' => 3000, 'position' => 1.8 ) ) ),
			// Healthy CTR.
			self::page( 'ok', array( 'impressions' => 5000, 'clicks' => 700, 'position' => 2.0 ) ),
			// Low CTR but too few impressions for this site.
			self::page( 'small', array( 'impressions' => 500, 'clicks' => 1, 'position' => 2.0 ) ),
			// Noindex pages are skipped.
			self::page( 'noindex', array( 'impressions' => 5000, 'clicks' => 10, 'position' => 2.0 ), array( 'indexable' => false ) ),
			// Another site's page (domain property subdomain) is skipped.
			self::page( 'external', array( 'impressions' => 5000, 'clicks' => 10, 'position' => 2.0 ), array( 'internal' => false ) ),
			// Position 40: no CTR expectation.
			self::page( 'deep', array( 'impressions' => 5000, 'clicks' => 0, 'position' => 40 ) ),
		);
		$found = Opportunities::rule_low_ctr( $pages, $model, self::t() );
		$this->assertSame( array( 'https://example.com/low/' ), self::pages_of( $found ) );
		$this->assertSame( array( 'update_title', 'improve_meta_description' ), $found[0]['actions'] );
		$this->assertSame( 'green tea', $found[0]['data']['query'] );
		$this->assertSame( 0.01, $found[0]['data']['ctr'] );
		$this->assertTrue( abs( 0.15 - $found[0]['data']['expected_ctr'] ) < 0.001 );
		$this->assertSame( 700, $found[0]['data']['missed_clicks'] );
		$this->assertSame( 'opportunity', $found[0]['severity'] );
	}

	public function test_striking_distance_and_page_two() {
		$pages = array(
			self::page( 'sd', array( 'impressions' => 2000, 'clicks' => 40, 'position' => 6.2 ) ),
			self::page( 'sd-edge', array( 'impressions' => 400, 'clicks' => 4, 'position' => 14.9 ) ),
			self::page( 'weak', array( 'impressions' => 150, 'clicks' => 1, 'position' => 12 ) ),
			self::page( 'p2', array( 'impressions' => 900, 'clicks' => 3, 'position' => 22 ) ),
			self::page( 'top', array( 'impressions' => 9000, 'clicks' => 900, 'position' => 2.1 ) ),
			self::page( 'deep', array( 'impressions' => 900, 'clicks' => 0, 'position' => 45 ) ),
		);
		$striking = Opportunities::rule_striking_distance( $pages, self::t(), new CtrModel() );
		$this->assertSame( array( 'https://example.com/sd/', 'https://example.com/sd-edge/' ), self::pages_of( $striking ) );
		$this->assertSame( array( 'refresh_content', 'add_internal_links', 'update_title' ), $striking[0]['actions'] );
		$this->assertGreaterThan( 0, $striking[0]['data']['potential_clicks'] );

		$exclude = array_fill_keys( self::pages_of( $striking ), true );
		$two     = Opportunities::rule_page_two( $pages, self::t(), $exclude );
		$this->assertSame( array( 'https://example.com/p2/', 'https://example.com/weak/' ), self::pages_of( $two ) );
		$this->assertContains( 'create_supporting_article', $two[0]['actions'] );
	}

	public function test_declining_versus_losing_clicks() {
		$pages = array(
			// Clicks −50%, impressions −50% → declining (visibility loss).
			self::page( 'declining', array( 'clicks' => 100, 'prev_clicks' => 200, 'impressions' => 5000, 'prev_impressions' => 10000, 'position' => 8, 'prev_position' => 5 ) ),
			// Clicks −40%, impressions held, position similar → losing clicks (CTR problem).
			self::page( 'ctr', array( 'clicks' => 60, 'prev_clicks' => 100, 'impressions' => 4000, 'prev_impressions' => 4100, 'position' => 3.2, 'prev_position' => 3.0 ) ),
			// Clicks −40%, impressions held, position dropped by 3 → losing clicks (ranking slip).
			self::page( 'slip', array( 'clicks' => 60, 'prev_clicks' => 100, 'impressions' => 4000, 'prev_impressions' => 4000, 'position' => 6.5, 'prev_position' => 3.5 ) ),
			// Too small to matter.
			self::page( 'tiny', array( 'clicks' => 1, 'prev_clicks' => 5, 'impressions' => 100, 'prev_impressions' => 400 ) ),
			// Slight dip.
			self::page( 'dip', array( 'clicks' => 90, 'prev_clicks' => 100, 'impressions' => 3000, 'prev_impressions' => 3000 ) ),
			// Registry says not indexed: declining with a fix-indexing action.
			self::page( 'gone', array( 'clicks' => 20, 'prev_clicks' => 100, 'impressions' => 800, 'prev_impressions' => 5000 ), array( 'index_status' => 'crawled_not_indexed' ) ),
		);
		$out = Opportunities::rule_declining_and_losing( $pages, self::t() );
		// Sorted by clicks lost: 100 before 80.
		$this->assertSame( array( 'https://example.com/declining/', 'https://example.com/gone/' ), self::pages_of( $out['declining_page'] ) );
		$this->assertSame( array( 'refresh_content' ), $out['declining_page'][0]['actions'] );
		$this->assertSame( array( 'refresh_content', 'fix_indexing' ), $out['declining_page'][1]['actions'] );
		$this->assertSame( 'medium', $out['declining_page'][1]['severity'] );
		$losing = array_column( $out['losing_clicks'], null, 'page' );
		$this->assertSame( 'ctr', $losing['https://example.com/ctr/']['data']['variant'] );
		$this->assertSame( array( 'update_title', 'improve_meta_description' ), $losing['https://example.com/ctr/']['actions'] );
		$this->assertSame( 'ranking', $losing['https://example.com/slip/']['data']['variant'] );
		$this->assertSame( array( 'refresh_content' ), $losing['https://example.com/slip/']['actions'] );
		// Excluded pages (already reported as indexing problems) are skipped.
		$out = Opportunities::rule_declining_and_losing( $pages, self::t(), array( 'https://example.com/gone/' => true ) );
		$this->assertSame( array( 'https://example.com/declining/' ), self::pages_of( $out['declining_page'] ) );
	}

	public function test_decline_severity_escalates_only_for_large_declines() {
		$t = self::t();
		$this->assertSame( 'opportunity', Opportunities::decline_severity( 20, 0.35, $t ) );
		$this->assertSame( 'medium', Opportunities::decline_severity( 60, 0.55, $t ) );
		$this->assertSame( 'high', Opportunities::decline_severity( 400, 0.8, $t ) );
		$this->assertSame( 'medium', Opportunities::decline_severity( 60, 0.8, $t ) );
	}

	public function test_growing_pages() {
		$pages = array(
			self::page( 'up', array( 'clicks' => 200, 'prev_clicks' => 100 ) ),
			self::page( 'new', array( 'clicks' => 40, 'prev_clicks' => 0 ) ),
			self::page( 'flat', array( 'clicks' => 110, 'prev_clicks' => 100 ) ),
			self::page( 'small', array( 'clicks' => 6, 'prev_clicks' => 1 ) ),
		);
		$found = Opportunities::rule_growing( $pages, self::t() );
		$this->assertSame( array( 'https://example.com/up/', 'https://example.com/new/' ), self::pages_of( $found ) );
		$this->assertSame( 1.0, $found[0]['data']['growth'] );
		$this->assertNull( $found[1]['data']['growth'] );
		$this->assertSame( 'add_internal_links', $found[0]['actions'][0] );
	}

	public function test_keyword_cannibalization() {
		$qp = array(
			array( 'query' => 'monsoon forecast', 'page' => 'https://example.com/a/', 'object_id' => 1, 'clicks' => 40, 'impressions' => 600, 'position' => 6.0 ),
			array( 'query' => 'monsoon forecast', 'page' => 'https://example.com/b/', 'object_id' => 2, 'clicks' => 20, 'impressions' => 300, 'position' => 9.0 ),
			array( 'query' => 'monsoon forecast', 'page' => 'https://example.com/c/', 'object_id' => 3, 'clicks' => 0, 'impressions' => 30, 'position' => 18.0 ),
			// Home page is never a competitor.
			array( 'query' => 'site name', 'page' => 'https://example.com/', 'object_id' => 0, 'clicks' => 300, 'impressions' => 900, 'position' => 1.0 ),
			array( 'query' => 'site name', 'page' => 'https://example.com/about/', 'object_id' => 9, 'clicks' => 10, 'impressions' => 300, 'position' => 2.0 ),
			// Two URLs of the same post (AMP) are one competitor.
			array( 'query' => 'rain today', 'page' => 'https://example.com/r/', 'object_id' => 5, 'clicks' => 10, 'impressions' => 500, 'position' => 4.0 ),
			array( 'query' => 'rain today', 'page' => 'https://example.com/r/amp/', 'object_id' => 5, 'clicks' => 5, 'impressions' => 300, 'position' => 5.0 ),
			// One page beyond position 30.
			array( 'query' => 'deep query', 'page' => 'https://example.com/x/', 'object_id' => 6, 'clicks' => 0, 'impressions' => 400, 'position' => 8.0 ),
			array( 'query' => 'deep query', 'page' => 'https://example.com/y/', 'object_id' => 7, 'clicks' => 0, 'impressions' => 400, 'position' => 45.0 ),
		);
		$ctx   = array(
			'https://example.com/a/' => array( 'post_id' => 1, 'h1' => 'Monsoon forecast 2026', 'internal' => true, 'indexable' => true ),
			'https://example.com/b/' => array( 'post_id' => 2, 'h1' => 'Monsoon update', 'internal' => true, 'indexable' => true ),
		);
		$found = Opportunities::rule_cannibalization( $qp, self::t(), $ctx, 'https://example.com/' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'monsoon forecast', $found[0]['query'] );
		$this->assertSame( 'query', $found[0]['object'] );
		$this->assertSame( array( 'differentiate_pages' ), $found[0]['actions'], 'Differentiating is the primary action; merging needs evergreen overlap' );
		$this->assertFalse( $found[0]['data']['merge_recommended'] );
		$this->assertCount( 2, $found[0]['data']['competing_pages'], 'The page with <10% of impressions is not a competitor' );
		$this->assertSame( 'Monsoon forecast 2026', $found[0]['data']['competing_pages'][0]['title'] );
		$this->assertSame( 0.645, $found[0]['data']['competing_pages'][0]['share'] );
	}

	public function test_cannibalization_ignores_daily_news_and_recommends_merge_only_for_overlapping_evergreen_pages() {
		$row = static function ( $query, $slug, $id, $impr, $pos ) {
			return array( 'query' => $query, 'page' => 'https://example.com/' . $slug . '/', 'object_id' => $id, 'clicks' => (int) ( $impr / 20 ), 'impressions' => $impr, 'position' => $pos );
		};
		$qp  = array(
			// Two daily gold-price reports: news published on different days → not cannibalization.
			$row( 'gold price today', 'gold-sep-28', 11, 600, 3.0 ),
			$row( 'gold price today', 'gold-sep-29', 12, 500, 3.5 ),
			// Two evergreen guides sharing most of their queries → merge recommended.
			$row( 'sambar recipe', 'sambar-guide', 21, 700, 4.0 ),
			$row( 'sambar recipe', 'easy-sambar', 22, 400, 6.0 ),
			$row( 'how to make sambar', 'sambar-guide', 21, 300, 5.0 ),
			$row( 'how to make sambar', 'easy-sambar', 22, 200, 7.0 ),
			$row( 'sambar powder', 'sambar-guide', 21, 100, 8.0 ),
			// Two news stories on the same day compete for real.
			$row( 'metro fare hike', 'metro-a', 31, 500, 4.0 ),
			$row( 'metro fare hike', 'metro-b', 32, 400, 5.0 ),
		);
		$ctx = array(
			'https://example.com/gold-sep-28/'  => array( 'post_id' => 11, 'post_type' => 'post', 'is_news' => true, 'published' => '2026-09-28' ),
			'https://example.com/gold-sep-29/'  => array( 'post_id' => 12, 'post_type' => 'post', 'is_news' => true, 'published' => '2026-09-29' ),
			'https://example.com/sambar-guide/' => array( 'post_id' => 21, 'post_type' => 'post', 'is_news' => false, 'published' => '2024-01-10' ),
			'https://example.com/easy-sambar/'  => array( 'post_id' => 22, 'post_type' => 'post', 'is_news' => false, 'published' => '2025-03-02' ),
			'https://example.com/metro-a/'      => array( 'post_id' => 31, 'post_type' => 'post', 'is_news' => true, 'published' => '2026-09-20' ),
			'https://example.com/metro-b/'      => array( 'post_id' => 32, 'post_type' => 'post', 'is_news' => true, 'published' => '2026-09-20' ),
		);
		$found = array_column( Opportunities::rule_cannibalization( $qp, self::t(), $ctx, 'https://example.com/' ), null, 'query' );
		$this->assertArrayNotHasKeyCompat( 'gold price today', $found );
		$this->assertSame( array( 'differentiate_pages', 'merge_articles' ), $found['sambar recipe']['actions'] );
		$this->assertTrue( $found['sambar recipe']['data']['merge_recommended'] );
		$this->assertSame( array( 'differentiate_pages' ), $found['metro fare hike']['actions'] );
		$this->assertTrue( $found['metro fare hike']['data']['news'] );
		// Without an explicit news flag, a time-bound query on dated posts is treated as news.
		$ctx['https://example.com/gold-sep-28/']['is_news'] = false;
		$ctx['https://example.com/gold-sep-29/']['is_news'] = false;
		$found = array_column( Opportunities::rule_cannibalization( $qp, self::t(), $ctx, 'https://example.com/' ), null, 'query' );
		$this->assertArrayNotHasKeyCompat( 'gold price today', $found );
		$this->assertTrue( Opportunities::is_temporal_query( 'Gold price today' ) );
		$this->assertTrue( Opportunities::is_temporal_query( 'ಇಂದಿನ ಚಿನ್ನದ ದರ' ) );
		$this->assertTrue( Opportunities::is_temporal_query( 'budget 2026 highlights' ) );
		$this->assertFalse( Opportunities::is_temporal_query( 'sambar recipe' ) );
		$this->assertTrue( Opportunities::dated_news_pair( array( 'news' => true, 'published' => '2026-09-01' ), array( 'news' => true, 'published' => '2026-09-02' ) ) );
		$this->assertFalse( Opportunities::dated_news_pair( array( 'news' => true, 'published' => '2026-09-01' ), array( 'news' => false, 'published' => '2026-09-02' ) ) );
	}

	/**
	 * assertArrayNotHasKey for the dependency-free runner.
	 *
	 * @param string $key   Key.
	 * @param array  $array Array.
	 */
	private function assertArrayNotHasKeyCompat( $key, array $array ) {
		$this->assertFalse( array_key_exists( $key, $array ), 'Unexpected key ' . $key );
	}

	public function test_query_without_landing_page() {
		$queries = array(
			array( 'query' => 'best budget phones', 'clicks' => 2, 'impressions' => 900, 'position' => 26.0 ),
			array( 'query' => 'phone reviews', 'clicks' => 1, 'impressions' => 800, 'position' => 24.0 ),
			array( 'query' => 'strong page', 'clicks' => 90, 'impressions' => 900, 'position' => 3.0 ),
			array( 'query' => 'rare thing', 'clicks' => 0, 'impressions' => 50, 'position' => 40.0 ),
		);
		$qp      = array(
			array( 'query' => 'best budget phones', 'page' => 'https://example.com/tablets/', 'object_id' => 1, 'clicks' => 2, 'impressions' => 900, 'position' => 26.0 ),
			array( 'query' => 'phone reviews', 'page' => 'https://example.com/phone-reviews/', 'object_id' => 2, 'clicks' => 1, 'impressions' => 800, 'position' => 24.0 ),
			array( 'query' => 'strong page', 'page' => 'https://example.com/strong/', 'object_id' => 3, 'clicks' => 90, 'impressions' => 900, 'position' => 3.0 ),
		);
		$ctx     = array(
			'https://example.com/tablets/'       => array( 'post_id' => 1, 'title' => 'Tablet buying guide | Site', 'h1' => 'Tablet buying guide' ),
			'https://example.com/phone-reviews/' => array( 'post_id' => 2, 'title' => 'Phone Reviews', 'h1' => 'Our phone reviews' ),
		);
		$found   = Opportunities::rule_query_without_landing_page( $queries, $qp, $ctx, self::t() );
		$this->assertCount( 1, $found );
		$this->assertSame( 'best budget phones', $found[0]['query'] );
		$this->assertSame( array( 'create_supporting_article' ), $found[0]['actions'] );
		$this->assertSame( 26.0, $found[0]['data']['best_position'] );
		$this->assertContains( 'budget', $found[0]['data']['missing_terms'] );
	}

	public function test_content_refresh() {
		$now   = strtotime( '2026-09-30 00:00:00 UTC' );
		$pages = array(
			self::page( 'old', array( 'impressions' => 600, 'prev_impressions' => 1000, 'clicks' => 20, 'prev_clicks' => 40 ), array( 'post_id' => 4, 'modified' => '2024-05-01 10:00:00' ) ),
			self::page( 'recent', array( 'impressions' => 600, 'prev_impressions' => 1000 ), array( 'post_id' => 5, 'modified' => '2026-06-01 10:00:00' ) ),
			self::page( 'stable', array( 'impressions' => 950, 'prev_impressions' => 1000 ), array( 'post_id' => 6, 'modified' => '2023-01-01 10:00:00' ) ),
			self::page( 'not-a-post', array( 'impressions' => 100, 'prev_impressions' => 1000 ), array( 'modified' => '' ) ),
		);
		$found = Opportunities::rule_content_refresh( $pages, self::t(), $now );
		$this->assertSame( array( 'https://example.com/old/' ), self::pages_of( $found ) );
		$this->assertSame( '2024-05-01', $found[0]['data']['modified'] );
		$this->assertSame( 0.4, $found[0]['data']['impressions_drop'] );
		$this->assertSame( array( 'refresh_content' ), $found[0]['actions'] );
	}

	public function test_fix_indexing() {
		$pages = array(
			self::page( 'dropped', array( 'impressions' => 3, 'prev_impressions' => 4000, 'prev_clicks' => 300 ), array( 'index_status' => 'excluded_noindex' ) ),
			self::page( 'error', array( 'impressions' => 0, 'prev_impressions' => 500, 'prev_clicks' => 12 ), array( 'http_status' => 500 ) ),
			self::page( 'unknown', array( 'impressions' => 0, 'prev_impressions' => 5000, 'prev_clicks' => 300 ), array( 'index_status' => 'indexed' ) ),
			self::page( 'still-shown', array( 'impressions' => 900, 'prev_impressions' => 1000 ), array( 'index_status' => 'not_indexed' ) ),
		);
		$found = Opportunities::rule_fix_indexing( $pages, self::t() );
		$this->assertSame( array( 'https://example.com/dropped/', 'https://example.com/error/' ), self::pages_of( $found ) );
		$this->assertSame( 'high', $found[0]['severity'] );
		$this->assertSame( 'medium', $found[1]['severity'] );
		$this->assertSame( array( 'fix_indexing' ), $found[0]['actions'] );
		$this->assertTrue( Opportunities::registry_problem( array( 'verdict' => 'fail' ) ) );
		$this->assertFalse( Opportunities::registry_problem( array( 'index_status' => 'indexed', 'http_status' => 200 ) ) );
	}

	public function test_structured_data() {
		$pages = array(
			self::page( 'no-article', array( 'impressions' => 5000 ), array( 'is_article' => true, 'schema_types' => array( 'WebPage', 'BreadcrumbList' ) ) ),
			self::page( 'has-issues', array( 'impressions' => 4000 ), array( 'is_article' => true, 'schema_types' => array( 'NewsArticle' ), 'schema_issues' => 2 ) ),
			self::page( 'fine', array( 'impressions' => 5000 ), array( 'is_article' => true, 'schema_types' => array( 'NewsArticle' ) ) ),
			self::page( 'not-crawled', array( 'impressions' => 5000 ), array( 'is_article' => true, 'schema_types' => null ) ),
			self::page( 'a-page', array( 'impressions' => 5000 ), array( 'is_article' => false, 'schema_types' => array( 'WebPage' ) ) ),
			self::page( 'quiet', array( 'impressions' => 100 ), array( 'is_article' => true, 'schema_types' => array() ) ),
		);
		$found = Opportunities::rule_structured_data( $pages, self::t() );
		$this->assertSame( array( 'https://example.com/no-article/', 'https://example.com/has-issues/' ), self::pages_of( $found ) );
		$this->assertTrue( $found[0]['data']['missing_article'] );
		$this->assertSame( 2, $found[1]['data']['schema_issues'] );
	}

	public function test_cap_and_best_pages() {
		$findings = array();
		for ( $i = 0; $i < 130; $i++ ) {
			$findings[] = array( 'type' => 'low_ctr' );
		}
		$findings[] = array( 'type' => 'growing_page' );
		$capped     = Opportunities::cap( $findings );
		$this->assertCount( Opportunities::MAX_PER_TYPE + 1, $capped );

		$best = Opportunities::best_pages(
			array(
				array( 'query' => 'Q', 'page' => 'https://example.com/a/', 'impressions' => 10, 'position' => 9.0 ),
				array( 'query' => 'q', 'page' => 'https://example.com/b/', 'impressions' => 10, 'position' => 4.0 ),
				array( 'query' => 'q', 'page' => 'https://example.com/c/', 'impressions' => 0, 'position' => 1.0 ),
			)
		);
		$this->assertSame( 'https://example.com/b/', $best['q']['page'] );
	}

	public function test_texts_use_the_evidence() {
		$what = Opportunities::what_text(
			'low_ctr',
			array(
				'impressions'   => 5000,
				'position'      => 2.0,
				'ctr'           => 0.01,
				'clicks'        => 50,
				'expected_ctr'  => 0.15,
				'missed_clicks' => 700,
				'query'         => 'green tea',
			)
		);
		$this->assertStringContainsString( '5,000', $what );
		$this->assertStringContainsString( '15.0%', $what );
		$this->assertStringContainsString( '“green tea”', $what );
		$fix = Opportunities::fix_text( 'keyword_cannibalization', array() );
		$this->assertStringContainsString( 'never merges', $fix );
		foreach ( Opportunities::TYPES as $type ) {
			$this->assertNotSame( '', Opportunities::why_text( $type, array() ), $type );
			$this->assertNotSame( '', Opportunities::fix_text( $type, array( 'query' => 'x' ) ), $type );
		}
	}
}
