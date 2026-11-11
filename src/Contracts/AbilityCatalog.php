<?php
/**
 * Scoped discovery and logical execution contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Meta tools cannot bypass the same registry/policy used by direct tools. */
interface AbilityCatalog {
	/**
	 * List registered metadata for authorized management, without invoking tools.
	 *
	 * @return array<AbilitySpec>
	 */
	public function all(): array;
	/**
	 * Get a definition only from the authoritative map.
	 *
	 * @param string $name Canonical name.
	 * @return AbilitySpec|null
	 */
	public function get( string $name ): ?AbilitySpec;
	/**
	 * List only currently visible definitions in stable order.
	 *
	 * @return array<AbilitySpec>
	 */
	public function visible(): array;
	/**
	 * Execute only through the authorized core ability.
	 *
	 * @param string              $name Canonical name.
	 * @param array<string,mixed> $arguments Validated logical arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed;
}
