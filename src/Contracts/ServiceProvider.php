<?php
/**
 * Service registration contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Registers a cohesive set of WordPress hooks. */
interface ServiceProvider {

	/** Register the service. */
	public function register(): void;
}
