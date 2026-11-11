<?php
/**
 * Shared owner mutation exclusion.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Serializes quota-sensitive writes across PHP workers using shared storage. */
interface OwnerLock {
	/**
	 * Execute without waiting if the owner's mutation slot can be acquired.
	 *
	 * @template T
	 * @param int      $user_id Owner.
	 * @param callable $operation Short local database operation.
	 * @return mixed
	 * @phpstan-param callable():T $operation
	 * @phpstan-return T
	 */
	public function run( int $user_id, callable $operation ): mixed;
}
