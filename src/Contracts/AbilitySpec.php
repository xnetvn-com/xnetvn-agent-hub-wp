<?php
/**
 * Transport-independent ability contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Provides private ability data and logical execution without adapter imports. */
interface AbilitySpec {
	/**
	 * Authorization metadata.
	 *
	 * @return \XNetVN\AgentHubWP\Domain\AbilityDescriptor
	 */
	public function descriptor(): \XNetVN\AgentHubWP\Domain\AbilityDescriptor;

	/**
	 * Canonical MCP name.
	 *
	 * @return string
	 */
	public function tool_name(): string;

	/**
	 * User interface label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Fixed English tool description.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Strict input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function input_schema(): array;

	/**
	 * Strict output schema.
	 *
	 * @return array<string,mixed>
	 */
	public function output_schema(): array;

	/**
	 * Behavior hints.
	 *
	 * @return array<string,mixed>
	 */
	public function annotations(): array;

	/**
	 * Contract output example.
	 *
	 * @return mixed
	 */
	public function example(): mixed;

	/**
	 * Whether the operation requires an expensive-work budget.
	 *
	 * @return bool
	 */
	public function costly(): bool;

	/**
	 * Execute already validated logical arguments.
	 *
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed;
}
