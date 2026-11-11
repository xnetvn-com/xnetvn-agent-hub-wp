<?php
/**
 * Explicit rate-budget outcome.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Never turns unavailable storage into an empty or unlimited budget. */
final readonly class RateLimitResult {
	/**
	 * Bind a measured budget decision.
	 *
	 * @param bool  $allowed Whether the request fits.
	 * @param int   $retry_after Conservative retry delay.
	 * @param int   $remaining Remaining whole requests.
	 * @param float $used Weighted usage.
	 */
	public function __construct( public bool $allowed, public int $retry_after, public int $remaining, public float $used ) {
	}
}
