<?php
/**
 * Cross-instance sliding-window rate tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Verifies a fresh limiter cannot evade a persisted per-site budget. */
final class RateLimiterTest extends WP_UnitTestCase {
	/** A shared fixed bucket denies after the configured number of requests. */
	public function test_budget_is_shared_across_instances(): void {
		global $wpdb;
		$clock = new FrozenClock( 1791072000 );
		$id    = 'fixture-' . bin2hex( random_bytes( 8 ) );
		$one   = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock );
		$two   = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock );
		self::assertTrue( $one->check( 'key', $id, 2 )->allowed );
		self::assertTrue( $two->check( 'key', $id, 2 )->allowed );
		self::assertFalse( $one->check( 'key', $id, 2 )->allowed );
		self::assertGreaterThan( 0, $two->check( 'key', $id, 2 )->retry_after );
	}

	/** The previous bucket decays while a new bucket starts. */
	public function test_previous_bucket_is_weighted(): void {
		global $wpdb;
		$clock   = new FrozenClock( 1791072000 );
		$id      = 'fixture-' . bin2hex( random_bytes( 8 ) );
		$limiter = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock );
		self::assertTrue( $limiter->check( 'key', $id, 2 )->allowed );
		self::assertTrue( $limiter->check( 'key', $id, 2 )->allowed );
		$clock->timestamp += 60;
		self::assertFalse( $limiter->check( 'key', $id, 2, false )->allowed );
		$clock->timestamp += 30;
		self::assertTrue( $limiter->check( 'key', $id, 2 )->allowed );
		self::assertFalse( $limiter->check( 'key', $id, 2 )->allowed );
	}

	/** Read-only auth-budget checks do not consume failures. */
	public function test_non_incrementing_checks(): void {
		global $wpdb;
		$id      = 'fixture-' . bin2hex( random_bytes( 8 ) );
		$limiter = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), new FrozenClock( 1791072000 ) );
		self::assertTrue( $limiter->check( 'auth-fail', $id, 1, false )->allowed );
		self::assertTrue( $limiter->check( 'auth-fail', $id, 1, false )->allowed );
		self::assertTrue( $limiter->check( 'auth-fail', $id, 1 )->allowed );
		self::assertFalse( $limiter->check( 'auth-fail', $id, 1, false )->allowed );
	}
}
