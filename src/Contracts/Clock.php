<?php
/**
 * Injectable wall clock.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

/** Supplies Unix seconds without coupling domain code to WordPress time. */
interface Clock {
	/**
	 * Return the current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int;
}
