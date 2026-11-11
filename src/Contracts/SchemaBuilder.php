<?php
/**
 * Strict schema construction contract for packs.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Lets packs share schema construction without importing the Abilities layer. */
interface SchemaBuilder {
	/**
	 * Build a strict object schema.
	 *
	 * @param array<string,mixed> $properties Fields.
	 * @param array<string>       $required Required fields.
	 * @param bool                $output Permit declared truncation metadata.
	 * @return array<string,mixed>
	 */
	public function object( array $properties = array(), array $required = array(), bool $output = false ): array;
}
