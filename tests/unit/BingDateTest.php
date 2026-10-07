<?php
/**
 * Tests for the Bing Webmaster API parsers (dates, {"d":…} envelopes, errors, mappers).
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Api\Bing\BingWebmaster;

// phpcs:disable

class BingDateTest extends \PHPUnit\Framework\TestCase {

	public function test_microsoft_json_date_uses_its_own_offset() {
		// 1316156400000 ms = 2011-09-16T07:00:00Z = 2011-09-16T00:00:00-07:00.
		$this->assertSame( '2011-09-16', BingWebmaster::parse_date( '/Date(1316156400000-0700)/' ) );
		$this->assertSame( '2011-09-16T00:00:00-07:00', BingWebmaster::parse_datetime( '/Date(1316156400000-0700)/' ) );
	}

	public function test_date_without_offset_is_utc() {
		$this->assertSame( '2011-09-16', BingWebmaster::parse_date( '/Date(1316156400000)/' ) );
		$this->assertSame( '2011-09-16T07:00:00+00:00', BingWebmaster::parse_datetime( '/Date(1316156400000)/' ) );
	}

	public function test_positive_offset_can_change_the_day() {
		// 2024-03-10T22:30:00Z at +0530 is already 2024-03-11 in India.
		$ms = strtotime( '2024-03-10T22:30:00Z' ) * 1000;
		$this->assertSame( '2024-03-11', BingWebmaster::parse_date( '/Date(' . $ms . '+0530)/' ) );
	}

	public function test_iso_dates_and_garbage() {
		$this->assertSame( '2024-01-05', BingWebmaster::parse_date( '2024-01-05T10:00:00Z' ) );
		$this->assertSame( '', BingWebmaster::parse_date( '' ) );
		$this->assertSame( '', BingWebmaster::parse_date( 'yesterday' ) );
		$this->assertNull( BingWebmaster::parse_datetime( '/Date(-62135596800000)/' ), 'DateTime.MinValue means no date' );
	}

	public function test_unwrap_envelope() {
		$this->assertSame( array( 'DailyQuota' => 10, 'MonthlyQuota' => 300 ), BingWebmaster::unwrap( 200, array( 'd' => array( 'DailyQuota' => 10, 'MonthlyQuota' => 300 ) ), '{}' ) );
		$this->assertNull( BingWebmaster::unwrap( 200, array( 'd' => null ), '{"d":null}' ) );
		$this->assertNull( BingWebmaster::unwrap( 200, null, '' ) );
		$bad = BingWebmaster::unwrap( 200, null, '<html>' );
		$this->assertSame( 'searchpilot_bing_response', $bad->get_error_code() );
	}

	public function test_error_mapping() {
		$auth = BingWebmaster::unwrap( 400, array( 'ErrorCode' => 3, 'Message' => 'ERROR!!! InvalidApiKey' ), '' );
		$this->assertSame( 'searchpilot_bing_auth', $auth->get_error_code() );
		$this->assertFalse( $auth->get_error_data()['retryable'] );

		$throttle = BingWebmaster::unwrap( 400, array( 'ErrorCode' => 4, 'Message' => 'ThrottleUser' ), '' );
		$this->assertSame( 'searchpilot_quota', $throttle->get_error_code() );
		$this->assertTrue( $throttle->get_error_data()['retryable'] );

		$quota = BingWebmaster::unwrap( 400, array( 'ErrorCode' => 2, 'Message' => 'ERROR!!! Quota remaining for today: 0, Submitted: 3' ), '' );
		$this->assertSame( 'searchpilot_quota', $quota->get_error_code() );

		$invalid = BingWebmaster::unwrap( 400, array( 'ErrorCode' => 8, 'Message' => 'InvalidParameter' ), '' );
		$this->assertSame( 'searchpilot_bing_invalid', $invalid->get_error_code() );
		$this->assertFalse( $invalid->get_error_data()['retryable'] );

		$server = BingWebmaster::unwrap( 503, null, 'Service Unavailable' );
		$this->assertSame( 'searchpilot_bing_api', $server->get_error_code() );
		$this->assertTrue( $server->get_error_data()['retryable'] );
	}

	public function test_traffic_mapping_is_sorted_by_date() {
		$rows = BingWebmaster::map_traffic(
			array(
				array( 'Date' => '/Date(1700092800000-0800)/', 'Clicks' => 5, 'Impressions' => 100 ),
				array( 'Date' => '/Date(1700006400000-0800)/', 'Clicks' => 3, 'Impressions' => 80 ),
				array( 'Date' => 'garbage', 'Clicks' => 1, 'Impressions' => 1 ),
			)
		);
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'date' => '2023-11-14', 'clicks' => 3, 'impressions' => 80 ), $rows[0] );
		$this->assertSame( '2023-11-15', $rows[1]['date'] );
	}

	public function test_page_stats_use_page_key() {
		$rows = BingWebmaster::map_query_stats(
			array(
				array( 'Query' => 'https://example.com/a/', 'Date' => '/Date(1700006400000-0800)/', 'Clicks' => 2, 'Impressions' => 40, 'AvgClickPosition' => 3, 'AvgImpressionPosition' => -1 ),
			),
			'page'
		);
		$this->assertSame( 'https://example.com/a/', $rows[0]['page'] );
		$this->assertSame( 3.0, $rows[0]['avg_click_position'] );
		$this->assertSame( 0.0, $rows[0]['avg_impression_position'], '-1 means no data' );
		$this->assertArrayHasKey( 'date', $rows[0] );
	}

	public function test_crawl_stats_mapping() {
		$rows = BingWebmaster::map_crawl_stats(
			array(
				array( 'Date' => '/Date(1700006400000-0800)/', 'CrawledPages' => 120, 'CrawlErrors' => 4, 'InIndex' => 900, 'Code4xx' => 3, 'Code5xx' => 1, 'BlockedByRobotsTxt' => 2 ),
			)
		);
		$this->assertSame(
			array(
				'date'              => '2023-11-14',
				'crawled_pages'     => 120,
				'crawl_errors'      => 4,
				'in_index'          => 900,
				'code_4xx'          => 3,
				'code_5xx'          => 1,
				'blocked_by_robots' => 2,
			),
			$rows[0]
		);
	}

	public function test_find_site_tolerates_scheme_and_slash() {
		$sites = array(
			array( 'url' => 'http://www.example.com/', 'verified' => true ),
			array( 'url' => 'https://example.com', 'verified' => false ),
		);
		$this->assertSame( 'https://example.com', BingWebmaster::find_site( $sites, 'https://example.com/' )['url'] );
		$this->assertSame( 'http://www.example.com/', BingWebmaster::find_site( array( $sites[0] ), 'https://example.com/' )['url'] );
		$this->assertNull( BingWebmaster::find_site( $sites, 'https://other.com/' ) );
	}
}
