<?php
/**
 * Tests for Search Console response parsing and property selection.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Api\Google\IndexingApi;
use SearchPilot\Api\Google\SearchConsole;
use SearchPilot\Api\Google\ServiceAccount;

// phpcs:disable

class SearchConsoleParseTest extends \PHPUnit\Framework\TestCase {

	public function test_inspection_is_normalized() {
		$json = array(
			'inspectionResult' => array(
				'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=x',
				'indexStatusResult'    => array(
					'verdict'         => 'PASS',
					'coverageState'   => 'Submitted and indexed',
					'robotsTxtState'  => 'ALLOWED',
					'indexingState'   => 'INDEXING_ALLOWED',
					'lastCrawlTime'   => '2024-05-01T08:30:00Z',
					'pageFetchState'  => 'SUCCESSFUL',
					'googleCanonical' => 'https://example.com/a/',
					'userCanonical'   => 'https://example.com/a/',
					'sitemap'         => array( 'https://example.com/sitemap.xml' ),
					'referringUrls'   => array( 'https://example.com/' ),
					'crawledAs'       => 'MOBILE',
				),
				'richResultsResult'    => array(
					'verdict'       => 'PARTIAL',
					'detectedItems' => array(
						array(
							'richResultType' => 'Breadcrumbs',
							'items'          => array(
								array(
									'name'   => 'Unnamed item',
									'issues' => array(
										array(
											'issueMessage' => 'Missing field "item"',
											'severity'     => 'WARNING',
										),
									),
								),
							),
						),
						array( 'richResultType' => 'Article' ),
					),
				),
			),
		);
		$r = SearchConsole::parse_inspection( $json );
		$this->assertSame( 'PASS', $r['verdict'] );
		$this->assertSame( 'Submitted and indexed', $r['coverage_state'] );
		$this->assertSame( 'INDEXING_ALLOWED', $r['indexing_state'] );
		$this->assertSame( 'ALLOWED', $r['robots_txt_state'] );
		$this->assertSame( 'SUCCESSFUL', $r['page_fetch_state'] );
		$this->assertSame( '2024-05-01T08:30:00+00:00', $r['last_crawl_time'] );
		$this->assertSame( 'MOBILE', $r['crawled_as'] );
		$this->assertSame( array( 'https://example.com/sitemap.xml' ), $r['sitemaps'] );
		$this->assertSame( 'PARTIAL', $r['rich_results']['verdict'] );
		$this->assertSame( 'Breadcrumbs', $r['rich_results']['items'][0]['type'] );
		$this->assertSame( array( array( 'message' => 'Missing field "item"', 'severity' => 'WARNING' ) ), $r['rich_results']['items'][0]['issues'] );
		$this->assertSame( array(), $r['rich_results']['items'][1]['issues'] );
		$this->assertStringContainsString( 'search-console/inspect', $r['inspection_link'] );
		$this->assertIsArray( $r['raw'] );
	}

	public function test_unknown_page_inspection() {
		$r = SearchConsole::parse_inspection(
			array(
				'inspectionResult' => array(
					'indexStatusResult' => array(
						'verdict'       => 'NEUTRAL',
						'coverageState' => 'URL is unknown to Google',
					),
				),
			)
		);
		$this->assertSame( 'NEUTRAL', $r['verdict'] );
		$this->assertNull( $r['last_crawl_time'] );
		$this->assertSame( array(), $r['referring_urls'] );
		$this->assertSame( '', $r['rich_results']['verdict'] );
		$this->assertSame( 'VERDICT_UNSPECIFIED', SearchConsole::parse_inspection( array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'WEIRD' ) ) ) )['verdict'] );
	}

	public function test_analytics_rows() {
		$rows = SearchConsole::normalize_rows(
			array(
				array( 'keys' => array( 'ಕರ್ನಾಟಕ ಸುದ್ದಿ', 'https://example.com/a/' ), 'clicks' => 12, 'impressions' => 340, 'ctr' => 0.0352941, 'position' => 4.21 ),
				'junk',
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'ಕರ್ನಾಟಕ ಸುದ್ದಿ', 'https://example.com/a/' ), $rows[0]['keys'] );
		$this->assertSame( 12, $rows[0]['clicks'] );
		$this->assertSame( 340, $rows[0]['impressions'] );
		$this->assertSame( 0.0352941, $rows[0]['ctr'] );
		$this->assertSame( 4.21, $rows[0]['position'] );
	}

	public function test_sitemaps_parsing() {
		$rows = SearchConsole::parse_sitemaps(
			array(
				'sitemap' => array(
					array(
						'path'            => 'https://example.com/sitemap.xml',
						'lastSubmitted'   => '2024-05-01T10:00:00.000Z',
						'isPending'       => false,
						'isSitemapsIndex' => true,
						'type'            => 'sitemap',
						'lastDownloaded'  => '2024-05-02T10:00:00.000Z',
						'warnings'        => '2',
						'errors'          => '0',
						'contents'        => array(
							array(
								'type'      => 'web',
								'submitted' => '120',
								'indexed'   => '0',
							),
						),
					),
				),
			)
		);
		$this->assertSame( 'https://example.com/sitemap.xml', $rows[0]['path'] );
		$this->assertTrue( $rows[0]['is_sitemaps_index'] );
		$this->assertFalse( $rows[0]['is_pending'] );
		$this->assertSame( 2, $rows[0]['warnings'] );
		$this->assertSame( '2024-05-01T10:00:00+00:00', $rows[0]['last_submitted'] );
		$this->assertSame( array( array( 'type' => 'web', 'submitted' => 120, 'indexed' => 0 ) ), $rows[0]['contents'] );
		$this->assertSame( array(), SearchConsole::parse_sitemaps( array() ) );
	}

	public function test_site_url_is_encoded_as_one_path_segment() {
		$this->assertSame( 'sc-domain%3Aexample.com', SearchConsole::encode_site( 'sc-domain:example.com' ) );
		$this->assertSame( 'https%3A%2F%2Fexample.com%2F', SearchConsole::encode_site( 'https://example.com/' ) );
	}

	public function test_best_property_prefers_domain_property() {
		$sites = array(
			array( 'site_url' => 'https://example.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'sc-domain:example.com', 'permission_level' => 'siteFullUser' ),
			array( 'site_url' => 'https://other.com/', 'permission_level' => 'siteOwner' ),
		);
		$this->assertSame( 'sc-domain:example.com', SearchConsole::best_property( $sites, 'https://www.example.com/' ) );
	}

	public function test_url_prefix_must_match_scheme_and_host_exactly() {
		$sites = array(
			array( 'site_url' => 'http://example.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'https://www.example.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'https://example.com/', 'permission_level' => 'siteRestrictedUser' ),
		);
		$this->assertSame( 'https://example.com/', SearchConsole::best_property( $sites, 'https://example.com/' ), 'exact home match wins even with lower permission' );
		// A URL-prefix property covers only its own scheme + host: variants never match.
		$this->assertSame( '', SearchConsole::best_property( array_slice( $sites, 0, 2 ), 'https://example.com/' ) );
		$this->assertSame( '', SearchConsole::best_property( array( $sites[0] ), 'https://example.com/' ), 'http variant' );
		$this->assertSame( '', SearchConsole::best_property( array( $sites[1] ), 'https://example.com/' ), 'www variant' );
		$this->assertSame( '', SearchConsole::best_property( array( array( 'site_url' => 'https://example.com:8443/', 'permission_level' => 'siteOwner' ) ), 'https://example.com/' ), 'other port' );
	}

	public function test_domain_property_preferred_over_url_prefix() {
		$sites = array(
			array( 'site_url' => 'https://www.example.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'sc-domain:example.com', 'permission_level' => 'siteRestrictedUser' ),
		);
		$this->assertSame( 'sc-domain:example.com', SearchConsole::best_property( $sites, 'https://www.example.com/' ) );
		$this->assertSame( 'sc-domain:www.example.com', SearchConsole::best_property( array( array( 'site_url' => 'sc-domain:www.example.com', 'permission_level' => 'siteOwner' ) ), 'https://www.example.com/' ) );
	}

	public function test_best_property_skips_unverified_and_foreign() {
		$sites = array(
			array( 'site_url' => 'sc-domain:example.com', 'permission_level' => 'siteUnverifiedUser' ),
			array( 'site_url' => 'https://notexample.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'https://example.com/other-folder/', 'permission_level' => 'siteOwner' ),
		);
		$this->assertSame( '', SearchConsole::best_property( $sites, 'https://example.com/' ) );
	}

	public function test_best_property_subdirectory_and_parent_domain() {
		$sites = array(
			array( 'site_url' => 'https://example.com/', 'permission_level' => 'siteOwner' ),
			array( 'site_url' => 'https://example.com/news/', 'permission_level' => 'siteOwner' ),
		);
		$this->assertSame( 'https://example.com/news/', SearchConsole::best_property( $sites, 'https://example.com/news/' ) );
		$this->assertSame( 'sc-domain:example.com', SearchConsole::best_property( array( array( 'site_url' => 'sc-domain:example.com', 'permission_level' => 'siteOwner' ) ), 'https://kannada.example.com/' ) );
	}

	public function test_property_validation() {
		$this->assertTrue( SearchConsole::valid_property( 'sc-domain:example.com' ) );
		$this->assertTrue( SearchConsole::valid_property( 'https://example.com/' ) );
		$this->assertFalse( SearchConsole::valid_property( 'sc-domain:' ) );
		$this->assertFalse( SearchConsole::valid_property( 'example.com' ) );
		$this->assertFalse( SearchConsole::valid_property( 'javascript:alert(1)' ) );
	}

	public function test_service_account_json_validation() {
		$good = array(
			'type'           => 'service_account',
			'project_id'     => 'demo',
			'private_key_id' => 'abc123',
			'private_key'    => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
			'client_email'   => 'bot@demo.iam.gserviceaccount.com',
			'token_uri'      => 'https://oauth2.googleapis.com/token',
		);
		$parsed = ServiceAccount::parse( json_encode( $good ) );
		$this->assertSame( 'bot@demo.iam.gserviceaccount.com', $parsed['client_email'] );

		$this->assertSame( 'searchpilot_sa_json', ServiceAccount::parse( 'not json' )->get_error_code() );
		$this->assertSame( 'searchpilot_sa_type', ServiceAccount::parse( json_encode( array_merge( $good, array( 'type' => 'authorized_user' ) ) ) )->get_error_code() );
		$this->assertSame( 'searchpilot_sa_key', ServiceAccount::parse( json_encode( array_merge( $good, array( 'private_key' => '' ) ) ) )->get_error_code() );
		$this->assertSame( 'searchpilot_sa_token_uri', ServiceAccount::parse( json_encode( array_merge( $good, array( 'token_uri' => 'https://evil.example/token' ) ) ) )->get_error_code() );
		$this->assertSame( 'searchpilot_sa_email', ServiceAccount::parse( json_encode( array_merge( $good, array( 'client_email' => 'nope' ) ) ) )->get_error_code() );
		$this->assertSame( ServiceAccount::scope_key( array( 'b', 'a' ) ), ServiceAccount::scope_key( array( 'a', 'b', 'a' ) ) );
	}

	public function test_indexing_api_requires_real_structured_data() {
		$this->assertSame( 'JobPosting', IndexingApi::structured_data_type( array( array( '@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => 'Editor' ) ), array() ) );
		$this->assertSame( 'JobPosting', IndexingApi::structured_data_type( array( array( '@graph' => array( array( '@type' => 'WebPage' ), array( '@type' => array( 'Thing', 'https://schema.org/JobPosting' ) ) ) ) ), array() ) );
		// Livestream: BroadcastEvent inside a VideoObject (publication).
		$this->assertSame( 'BroadcastEvent', IndexingApi::structured_data_type( array( array( '@type' => 'VideoObject', 'name' => 'Live', 'publication' => array( '@type' => 'BroadcastEvent', 'isLiveBroadcast' => true ) ) ), array() ) );
		// A BroadcastEvent that is not part of a VideoObject is not a livestream video.
		$this->assertSame( '', IndexingApi::structured_data_type( array( array( '@type' => 'BroadcastEvent', 'isLiveBroadcast' => true ) ), array() ) );
		// Ordinary news markup never qualifies.
		$this->assertSame( '', IndexingApi::structured_data_type( array( array( '@type' => 'NewsArticle', 'headline' => 'We are hiring: JobPosting guide' ) ), array() ) );
		$this->assertSame( '', IndexingApi::structured_data_type( array( null, 'junk' ), array( 'Article' ) ) );
		// Microdata / flat type lists (crawler evidence).
		$this->assertSame( 'JobPosting', IndexingApi::structured_data_type( array(), array( 'https://schema.org/JobPosting' ) ) );
		$this->assertSame( 'BroadcastEvent', IndexingApi::structured_data_type( array(), array( 'VideoObject', 'BroadcastEvent' ) ) );
		$this->assertSame( '', IndexingApi::structured_data_type( array(), array( 'BroadcastEvent' ) ) );
	}

	public function test_bing_crawl_summary() {
		$rows = array();
		for ( $d = 1; $d <= 40; $d++ ) {
			$rows[] = array(
				'date'              => sprintf( '2026-08-%02d', min( 31, $d ) ),
				'crawled_pages'     => 10,
				'crawl_errors'      => 1,
				'in_index'          => 100 + $d,
				'code_4xx'          => 1,
				'code_5xx'          => 0,
				'blocked_by_robots' => 2,
			);
		}
		$rows   = array_slice( $rows, 0, 31 ); // 2026-08-01 … 2026-08-31.
		$shuffled = array_reverse( $rows );
		$sum    = \SearchPilot\Api\Bing\BingWebmaster::summarize_crawl( $shuffled, 30 );
		$this->assertSame( '2026-08-02', $sum['from'] );
		$this->assertSame( '2026-08-31', $sum['to'] );
		$this->assertCount( 30, $sum['series'] );
		$this->assertSame( 300, $sum['totals']['crawled_pages'] );
		$this->assertSame( 60, $sum['totals']['blocked_by_robots'] );
		$this->assertSame( 131, $sum['in_index'] );
		$this->assertSame( '2026-08-02', $sum['series'][0]['date'] );
		$empty = \SearchPilot\Api\Bing\BingWebmaster::summarize_crawl( array() );
		$this->assertSame( array(), $empty['series'] );
		$this->assertSame( 0, $empty['totals']['crawled_pages'] );
	}
}
