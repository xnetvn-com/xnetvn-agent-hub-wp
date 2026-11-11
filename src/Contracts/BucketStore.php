<?php
/**
 * Atomic shared minute-bucket contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Counter operations must share state across PHP workers. */
interface BucketStore {
	/**
	 * Read a bucket, or zero only when it definitively does not exist.
	 *
	 * @param string $name Bucket identity.
	 * @return int
	 */
	public function count( string $name ): int;
	/**
	 * Increment a bucket atomically.
	 *
	 * @param string $name Bucket identity.
	 * @return int New shared count.
	 */
	public function increment( string $name ): int;
}
