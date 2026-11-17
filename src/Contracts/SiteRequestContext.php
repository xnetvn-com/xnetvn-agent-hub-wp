<?php
/**
 * Additive machine request identity contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;

/** Adds accountless website identity without changing the human contract. */
interface SiteRequestContext extends RequestContext {
	/**
	 * Read the verified website identity.
	 *
	 * @return SiteAuthContext|null
	 */
	public function site_current(): ?SiteAuthContext;

	/**
	 * Bind the single human principal for this request.
	 *
	 * @param AuthContext $context Human identity.
	 * @return void
	 */
	public function bind_human( AuthContext $context ): void;

	/**
	 * Bind the single website principal for this request.
	 *
	 * @param SiteAuthContext $context Website identity.
	 * @return void
	 */
	public function bind_site( SiteAuthContext $context ): void;

	/**
	 * Report a direct dual-principal write that must fail closed.
	 *
	 * @return bool
	 */
	public function has_conflicting_principals(): bool;
}
