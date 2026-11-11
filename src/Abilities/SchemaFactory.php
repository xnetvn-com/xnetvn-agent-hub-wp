<?php
/**
 * Shared strict JSON-schema primitives.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

/** Root outputs explicitly permit declared truncation metadata. */
final class SchemaFactory {
	/**
	 * Build a strict object schema.
	 *
	 * @param array<string,mixed> $properties Named fields.
	 * @param array<string>       $required Required fields.
	 * @param bool                $output Permit output truncation metadata.
	 * @return array<string,mixed>
	 */
	public static function object( array $properties = array(), array $required = array(), bool $output = false ): array {
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
		if ( $output ) {
			$properties['truncated'] = array( 'type' => 'boolean' );
		}
		if ( $properties ) {
			$schema['properties'] = $properties;
		}
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}
	/**
	 * Return the standard bounded pagination inputs.
	 *
	 * @return array<string,mixed>
	 */
	public static function pagination(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
		);
	}
}
