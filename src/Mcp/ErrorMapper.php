<?php
/**
 * Safe tool error payloads.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Mcp;

/** Does not include input values, internal errors or hidden tool identities. */
final class ErrorMapper {
	/**
	 * Produce one uniform unavailable execution result.
	 *
	 * @return array<string,mixed>
	 */
	public static function unavailable(): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'The tool is unavailable.',
				),
			),
			'isError' => true,
		);
	}
}
