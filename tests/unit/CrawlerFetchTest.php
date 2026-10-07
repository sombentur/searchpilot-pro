<?php
/**
 * Tests for the crawler's cache-bypassing fetch.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Core\Http\HttpResponse;
use SearchPilot\Indexer\Crawler;

/**
 * Scripted HTTP client: answers each requested URL with a callback.
 */
class CrawlerFetchFakeHttp {

	/**
	 * Requested URLs with their headers.
	 *
	 * @var array[]
	 */
	public $requests = array();

	/**
	 * Responder: callable( string $url ): HttpResponse.
	 *
	 * @var callable
	 */
	private $responder;

	/**
	 * Constructor.
	 *
	 * @param callable $responder Responder.
	 */
	public function __construct( callable $responder ) {
		$this->responder = $responder;
	}

	/**
	 * GET.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return HttpResponse
	 */
	public function get( $url, array $args = array() ) {
		$this->requests[] = array(
			'url'     => $url,
			'headers' => $args['headers'] ?? array(),
		);
		return call_user_func( $this->responder, $url );
	}
}

/**
 * Settings stand-in.
 */
class CrawlerFetchFakeSettings {

	/**
	 * Get.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return $default;
	}
}

/**
 * Crawler::fetch() / needs_fresh_copy() tests.
 */
class CrawlerFetchTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Crawler with a scripted client.
	 *
	 * @param CrawlerFetchFakeHttp $http Client.
	 * @return Crawler
	 */
	private function crawler( CrawlerFetchFakeHttp $http ) {
		$GLOBALS['spp_test_home'] = 'https://example.com/';
		$crawler                  = ( new \ReflectionClass( Crawler::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'http'     => $http,
			'settings' => new CrawlerFetchFakeSettings(),
		) as $name => $value ) {
			$prop = new \ReflectionProperty( Crawler::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$prop->setAccessible( true );
			}
			$prop->setValue( $crawler, $value );
		}
		return $crawler;
	}

	/**
	 * HTML page.
	 *
	 * @param string $url Self URL echoed in the page.
	 * @return HttpResponse
	 */
	private static function page( $url ) {
		return new HttpResponse( 200, '<html><head><title>T</title><link rel="canonical" href="' . $url . '"></head><body></body></html>', array( 'content-type' => 'text/html' ) );
	}

	public function test_busted_fetch_is_clean_and_marked_bypassed() {
		$http  = new CrawlerFetchFakeHttp(
			static function ( $url ) {
				return self::page( $url );
			}
		);
		$chain = $this->crawler( $http )->fetch( 'https://example.com/a/', null, array( 'bust_cache' => true ) );
		$this->assertCount( 1, $http->requests );
		$this->assertStringContainsString( '?nocache=spp', $http->requests[0]['url'] );
		$this->assertSame( 'no-cache', $http->requests[0]['headers']['Pragma'] ?? '' );
		$this->assertSame( 200, $chain['status'] );
		$this->assertSame( 'https://example.com/a/', $chain['final_url'] );
		$this->assertTrue( $chain['cache_bypassed'] );
		$this->assertStringNotContainsString( 'nocache', $chain['body'] );
		$this->assertFalse( $chain['loop'] );
	}

	public function test_server_dropping_the_argument_is_not_a_loop() {
		// A server that 301s /a/?nocache=… to /a/ (drops unknown query arguments).
		$http  = new CrawlerFetchFakeHttp(
			static function ( $url ) {
				if ( false !== strpos( $url, 'nocache=' ) ) {
					return new HttpResponse( 301, '', array( 'location' => 'https://example.com/a/' ) );
				}
				return self::page( $url );
			}
		);
		$chain = $this->crawler( $http )->fetch( 'https://example.com/a/', null, array( 'bust_cache' => true ) );
		$this->assertCount( 2, $http->requests );
		$this->assertSame( 'https://example.com/a/', $http->requests[1]['url'] );
		$this->assertSame( 'no-cache', $http->requests[1]['headers']['Cache-Control'] ?? '' );
		$this->assertFalse( $chain['loop'] );
		$this->assertSame( array(), $chain['hops'] );
		$this->assertSame( 200, $chain['status'] );
		$this->assertSame( 200, $chain['first_status'] );
		$this->assertFalse( $chain['cache_bypassed'] );
	}

	public function test_real_redirect_with_the_argument_kept_is_followed_cleanly() {
		// /a → /a/ (canonical trailing slash) keeping the query string, like WordPress does.
		$http  = new CrawlerFetchFakeHttp(
			static function ( $url ) {
				$parts = explode( '?', $url, 2 );
				if ( 'https://example.com/a' === $parts[0] ) {
					return new HttpResponse( 301, '', array( 'location' => 'https://example.com/a/' . ( isset( $parts[1] ) ? '?' . $parts[1] : '' ) ) );
				}
				return self::page( $url );
			}
		);
		$chain = $this->crawler( $http )->fetch( 'https://example.com/a', null, array( 'bust_cache' => true ) );
		$this->assertFalse( $chain['loop'] );
		$this->assertSame( 301, $chain['first_status'] );
		$this->assertSame( 'https://example.com/a/', $chain['final_url'] );
		$this->assertSame( 'https://example.com/a/', $chain['hops'][0]['location'] );
		$this->assertTrue( $chain['cache_bypassed'] );
	}

	public function test_genuine_self_redirect_without_busting_is_still_a_loop() {
		$http  = new CrawlerFetchFakeHttp(
			static function ( $url ) {
				return new HttpResponse( 301, '', array( 'location' => 'https://example.com/a/' ) );
			}
		);
		$chain = $this->crawler( $http )->fetch( 'https://example.com/a/' );
		$this->assertTrue( $chain['loop'] );
	}

	public function test_needs_fresh_copy() {
		$this->assertTrue( Crawler::needs_fresh_copy( array( 'last_checked' => null ) ) );
		$this->assertTrue( Crawler::needs_fresh_copy( array( 'last_checked' => '2000-01-01T00:00:00' ) ) );
		$this->assertTrue(
			Crawler::needs_fresh_copy(
				array(
					'last_checked'  => '2026-09-01T10:00:00',
					'last_modified' => '2026-09-02T10:00:00',
				)
			)
		);
		$this->assertFalse(
			Crawler::needs_fresh_copy(
				array(
					'last_checked'  => '2026-09-02T10:00:00',
					'last_modified' => '2026-09-01T10:00:00',
				)
			)
		);
		$this->assertFalse( Crawler::needs_fresh_copy( array( 'last_checked' => '2026-09-02T10:00:00' ) ) );
	}
}
