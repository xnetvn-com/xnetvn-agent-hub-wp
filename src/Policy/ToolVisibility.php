<?php
/**
 * Tool listing authorization.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\AuthContext;

/** Uses the same decision for discovery and execution without granting new rights. */
final class ToolVisibility {
	/**
	 * Bind authoritative policy.
	 *
	 * @param Policy $policy Policy.
	 */
	public function __construct( private Policy $policy ) {
	}
	/**
	 * Determine whether a descriptor is visible to the caller.
	 *
	 * @param AbilityDescriptor $ability Descriptor.
	 * @param AuthContext|null  $context Delegation.
	 * @param string            $transport Transport.
	 * @return bool
	 */
	public function visible( AbilityDescriptor $ability, ?AuthContext $context, string $transport ): bool {
		return $this->policy->evaluate( $ability, $context, $transport )->allowed;
	}
}
