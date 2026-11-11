<?php
/**
 * Pack-facing schema adapter.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

/** Implements the pack contract with the canonical schema factory. */
final class SchemaBuilder implements \XNetVN\AgentHubWP\Contracts\SchemaBuilder {
	/**
	 * Build strict object metadata.
	 *
	 * @param array<string,mixed> $properties Fields.
	 * @param array<string>       $required Required fields.
	 * @param bool                $output Output schema.
	 * @return array<string,mixed>
	 */
	public function object( array $properties = array(), array $required = array(), bool $output = false ): array {
		return SchemaFactory::object( $properties, $required, $output );
	}
}
