<?php
/**
 * Request-scoped authentication state.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Http;

use XNetVN\AgentHubWP\Auth\FailureReason;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Contracts\RequestContext;

/** Retains no token, secret or hash and can be reset between simulated requests. */
final class RequestScope implements RequestContext {
	/** Whether auth already ran for this request.
	 *
	 * @var bool
	 */
	public bool $attempted = false;
	/** Whether a credential header was present.
	 *
	 * @var bool
	 */
	public bool $has_token = false;
	/** Verified delegated actor.
	 *
	 * @var AuthContext|null
	 */
	public ?AuthContext $context = null;
	/** Internal failure reason.
	 *
	 * @var FailureReason|null
	 */
	public ?FailureReason $failure = null;
	/** Measured rate budget.
	 *
	 * @var RateLimitResult|null
	 */
	public ?RateLimitResult $budget = null;
	/**
	 * Read safe delegated identity.
	 *
	 * @return AuthContext|null
	 */
	public function current(): ?AuthContext {
		return $this->context;
	}
	/**
	 * Identify the actual adapter CLI or HTTP transport.
	 *
	 * @return string
	 */
	public function transport(): string {
		return defined( 'WP_CLI' ) && WP_CLI ? 'stdio' : 'http';
	}
	/**
	 * Read the measured rate result.
	 *
	 * @return RateLimitResult|null
	 */
	public function rate_budget(): ?RateLimitResult {
		return $this->budget;
	}
	/** Erase all state at request shutdown or test teardown. */
	public function reset(): void {
		$this->attempted = false;
		$this->has_token = false;
		$this->context   = null;
		$this->failure   = null;
		$this->budget    = null;
	}
}
