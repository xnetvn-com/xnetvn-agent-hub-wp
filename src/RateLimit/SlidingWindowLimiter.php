<?php
/**
 * Shared weighted sliding-minute window.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\RateLimit;

use XNetVN\AgentHubWP\Contracts\BucketStore;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Domain\RateLimitResult;

/** Bounds current usage plus the undecayed fraction of the preceding minute. */
final class SlidingWindowLimiter implements RateLimiter {
	/**
	 * Bind shared storage and time.
	 *
	 * @param BucketStore $store Atomic storage.
	 * @param Clock       $clock Time source.
	 */
	public function __construct( private BucketStore $store, private Clock $clock ) {
	}
	/**
	 * Evaluate the current sliding window with stable per-site bucket names.
	 *
	 * @param string $scope Counter namespace.
	 * @param string $id Public or hashed identity.
	 * @param int    $limit Per-minute budget.
	 * @param bool   $increment Consume one request.
	 * @return RateLimitResult
	 * @throws \InvalidArgumentException For invalid budget configuration.
	 */
	public function check( string $scope, string $id, int $limit, bool $increment = true ): RateLimitResult {
		if ( $limit < 1 || '' === $id || strlen( $id ) > 200 || 1 !== preg_match( '/\A[a-z0-9-]{1,40}\z/', $scope ) ) {
			throw new \InvalidArgumentException( 'Invalid rate budget.' );
		}
		$time          = $this->clock->now();
		$minute        = intdiv( $time, 60 );
		$identity      = hash( 'sha256', $scope . ':' . $id );
		$current_name  = sprintf( 'xnetvn_agent_hub_wp_rl_%010d_%s', $minute, $identity );
		$previous_name = sprintf( 'xnetvn_agent_hub_wp_rl_%010d_%s', $minute - 1, $identity );
		$current       = $increment ? $this->store->increment( $current_name ) : $this->store->count( $current_name );
		$previous      = $this->store->count( $previous_name );
		$used          = $current + $previous * ( 1 - ( $time % 60 ) / 60 );
		$allowed       = $increment ? $used <= $limit : $used < $limit;
		$retry         = $allowed ? 0 : 120 - ( $time % 60 );
		return new RateLimitResult( $allowed, $retry, max( 0, (int) floor( $limit - $used ) ), (float) $used );
	}
}
