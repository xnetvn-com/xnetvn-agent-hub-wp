<?php
/**
 * Production wall clock.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support;

use XNetVN\AgentHubWP\Contracts\Clock;
/** Returns server time as UTC-independent Unix seconds. */
final class SystemClock implements Clock {
	/**
	 * Return the current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int {
		return time();
	}
}
