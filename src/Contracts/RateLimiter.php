<?php
/**
 * Shared rate-budget contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

use XNetVN\AgentHubWP\Domain\RateLimitResult;

/** Allows preflight checks of failed-auth budgets without charging successful auth. */
interface RateLimiter {
	/**
	 * Evaluate or consume a shared sliding-window budget.
	 *
	 * @param string $scope Counter namespace.
	 * @param string $id Public identifier or hashed peer identifier.
	 * @param int    $limit Requests per minute.
	 * @param bool   $increment Whether to charge one request.
	 * @return RateLimitResult
	 */
	public function check( string $scope, string $id, int $limit, bool $increment = true ): RateLimitResult;
}
