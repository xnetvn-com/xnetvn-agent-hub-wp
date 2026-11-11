<?php
/**
 * Deterministic test clock.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Support;

use XNetVN\AgentHubWP\Contracts\Clock;

/** Allows expiry/touch tests without sleeps. */
final class FrozenClock implements Clock {
	/**
	 * Set the clock.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public function __construct( public int $timestamp ) {
	}

	/**
	 * Return the fixed timestamp.
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->timestamp;
	}
}
