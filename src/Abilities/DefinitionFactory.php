<?php
/**
 * Private ability contract factory.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use XNetVN\AgentHubWP\Contracts\AbilityFactory;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;

/** Keeps pack imports on the shared contract surface. */
final class DefinitionFactory implements AbilityFactory {
	/**
	 * Create a validated immutable definition.
	 *
	 * @param array<string,mixed> $data Fields.
	 * @return AbilitySpec
	 */
	public function create( array $data ): AbilitySpec {
		return new AbilityDefinition( $data );
	}
}
