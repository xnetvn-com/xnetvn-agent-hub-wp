<?php
/**
 * Per-site configuration contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Supplies validated defaults and current site restrictions. */
interface SiteSettings {
	/**
	 * Read one validated setting.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	public function get( string $name ): mixed;
	/**
	 * Read the current validated settings snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array;
	/**
	 * Update explicitly specified validated fields.
	 *
	 * @param array<string,mixed> $changes Changes.
	 * @return array<string,mixed>
	 */
	public function update( array $changes ): array;
}
