<?php
/**
 * Private definition construction contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Lets packs depend on the contract rather than the Abilities implementation. */
interface AbilityFactory {
	/**
	 * Create one validated private definition.
	 *
	 * @param array<string,mixed> $data Definition fields.
	 * @return AbilitySpec
	 */
	public function create( array $data ): AbilitySpec;
}
