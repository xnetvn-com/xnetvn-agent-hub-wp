<?php
/**
 * Availability and private-pack registration contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Groups related private capabilities without depending on auth or transport. */
interface AbilityPack {
	/**
	 * Determine whether this pack is available in the current site.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Build this pack's immutable definitions.
	 *
	 * @return iterable<AbilitySpec>
	 */
	public function definitions(): iterable;
}
