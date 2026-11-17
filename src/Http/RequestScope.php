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
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Contracts\SiteRequestContext;

/** Retains no token, secret or hash and can be reset between simulated requests. */
final class RequestScope implements SiteRequestContext {
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
	/** Verified website principal.
	 *
	 * @var SiteAuthContext|null
	 */
	public ?SiteAuthContext $site_context = null;
	/**
	 * Read safe delegated identity.
	 *
	 * @return AuthContext|null
	 */
	public function current(): ?AuthContext {
		return $this->has_conflicting_principals() ? null : $this->context;
	}
	/**
	 * Read safe website identity.
	 *
	 * @return SiteAuthContext|null
	 */
	public function site_current(): ?SiteAuthContext {
		return $this->has_conflicting_principals() ? null : $this->site_context;
	}
	/**
	 * Bind the single human principal for this request.
	 *
	 * @param AuthContext $context Human identity.
	 * @return void
	 */
	public function bind_human( AuthContext $context ): void {
		$this->context      = $context;
		$this->site_context = null;
	}
	/**
	 * Bind the single website principal for this request.
	 *
	 * @param SiteAuthContext $context Website identity.
	 * @return void
	 */
	public function bind_site( SiteAuthContext $context ): void {
		$this->site_context = $context;
		$this->context      = null;
	}
	/**
	 * Report a direct dual-principal write that must fail closed.
	 *
	 * @return bool
	 */
	public function has_conflicting_principals(): bool {
		return null !== $this->context && null !== $this->site_context;
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
		$this->attempted    = false;
		$this->has_token    = false;
		$this->context      = null;
		$this->site_context = null;
		$this->failure      = null;
		$this->budget       = null;
	}
}
