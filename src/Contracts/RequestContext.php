<?php
/**
 * Read-only request identity contract for packs.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\RateLimitResult;

/** Exposes only safe identity/budget data, never credential inputs. */
interface RequestContext {
	/**
	 * Read the verified delegated actor.
	 *
	 * @return AuthContext|null
	 */
	public function current(): ?AuthContext;
	/**
	 * Read the explicit current transport.
	 *
	 * @return string
	 */
	public function transport(): string;
	/**
	 * Read the measured budget if available.
	 *
	 * @return RateLimitResult|null
	 */
	public function rate_budget(): ?RateLimitResult;
}
