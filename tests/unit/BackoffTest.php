<?php
/**
 * Tests for Queue\Backoff and the queue's dedupe key.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Queue\Backoff;
use SearchPilot\Queue\SubmissionQueue;

// phpcs:disable

class BackoffTest extends \PHPUnit\Framework\TestCase {

	public function test_exponential_growth_without_jitter() {
		// random = 0.5 → factor exactly 1.0.
		$this->assertSame( 120, Backoff::delay( 1, 0, 0.5 ) );
		$this->assertSame( 240, Backoff::delay( 2, 0, 0.5 ) );
		$this->assertSame( 480, Backoff::delay( 3, 0, 0.5 ) );
		$this->assertSame( 60 * 2 ** 5, Backoff::delay( 5, 0, 0.5 ) );
	}

	public function test_jitter_stays_within_twenty_percent() {
		$this->assertSame( 96, Backoff::delay( 1, 0, 0.0 ) );
		$this->assertSame( 144, Backoff::delay( 1, 0, 1.0 ) );
		for ( $i = 0; $i < 50; $i++ ) {
			$d = Backoff::delay( 4 );
			$this->assertGreaterThanOrEqual( 768, $d );
			$this->assertLessThanOrEqual( 1152, $d );
		}
	}

	public function test_capped_at_twelve_hours() {
		$this->assertSame( 43200, Backoff::delay( 20, 0, 1.0 ) );
		$this->assertSame( 43200, Backoff::delay( 100, 0, 0.5 ) );
		$this->assertLessThanOrEqual( 43200, Backoff::delay( 10, 0, 0.99 ) );
	}

	public function test_never_below_one_minute() {
		$this->assertSame( 60, Backoff::delay( 0, 0, 0.0 ) );
		$this->assertSame( 60, Backoff::delay( -3, 0, 0.5 ) );
	}

	public function test_retry_after_is_honoured() {
		$this->assertSame( 3600, Backoff::delay( 1, 3600, 0.5 ) );
		// A shorter Retry-After never shortens our own back-off.
		$this->assertSame( 480, Backoff::delay( 3, 30, 0.5 ) );
		// Absurd values are capped at 48 hours.
		$this->assertSame( 172800, Backoff::delay( 1, 10 * 86400, 0.5 ) );
	}

	public function test_next_attempt_at() {
		$this->assertSame( 1000 + 120, Backoff::next_attempt_at( 1, 0, 1000, 0.5 ) );
	}

	public function test_exhausted() {
		$this->assertFalse( Backoff::exhausted( 4, 5 ) );
		$this->assertTrue( Backoff::exhausted( 5, 5 ) );
		$this->assertTrue( Backoff::exhausted( 1, 0 ) );
	}

	public function test_classify() {
		$this->assertSame( 'ok', Backoff::classify( 200 ) );
		$this->assertSame( 'ok', Backoff::classify( 202 ) );
		$this->assertSame( 'retry', Backoff::classify( 0 ) );
		$this->assertSame( 'retry', Backoff::classify( 429 ) );
		$this->assertSame( 'retry', Backoff::classify( 503 ) );
		$this->assertSame( 'permanent', Backoff::classify( 400 ) );
		$this->assertSame( 'permanent', Backoff::classify( 403 ) );
		$this->assertSame( 'permanent', Backoff::classify( 422 ) );
	}

	public function test_dedupe_key_normalizes_urls() {
		$a = SubmissionQueue::dedupe_key( 'indexnow', 'update', 'https://Example.com/%e0%b2%95/?utm_source=x' );
		$b = SubmissionQueue::dedupe_key( 'indexnow', 'update', 'https://example.com/%E0%B2%95/' );
		$this->assertSame( $a, $b );
		$this->assertSame( 40, strlen( $a ) );
		$this->assertNotSame( $a, SubmissionQueue::dedupe_key( 'indexnow', 'delete', 'https://example.com/%E0%B2%95/' ) );
		$this->assertNotSame( $a, SubmissionQueue::dedupe_key( 'bing_url', 'update', 'https://example.com/%E0%B2%95/' ) );
	}

	public function test_default_priorities() {
		$this->assertGreaterThan( SubmissionQueue::default_priority( 'publish' ), SubmissionQueue::default_priority( 'manual' ) );
		$this->assertGreaterThan( SubmissionQueue::default_priority( 'update' ), SubmissionQueue::default_priority( 'publish' ) );
		$this->assertGreaterThan( SubmissionQueue::default_priority( 'update' ), SubmissionQueue::default_priority( 'delete' ) );
		$this->assertSame( 30, SubmissionQueue::default_priority( 'update', true ) );
	}
}
