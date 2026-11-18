<?php
/**
 * Bounded owner-specific privacy persistence.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Contracts;

/** Does not expose secret-hash or pepper columns to privacy exporters. */
interface PrivacyStore {
	/**
	 * Export at most 50 personal keys, 50 site-key attribution records and 50 audit records in stable ID order; site-key attribution is merged into the keys group so the exporter shape does not change.
	 *
	 * @param int $user_id Owner.
	 * @param int $page One-based page.
	 * @return array{keys:array<array<string,mixed>>,audit:array<array<string,mixed>>,preferences:array<array<string,mixed>>,done:bool}
	 */
	public function export_for_user( int $user_id, int $page ): array;

	/**
	 * Consume the first remaining batch without offsetting mutated rows.
	 *
	 * @param int $user_id Owner.
	 * @param int $limit Bound per table.
	 * @return array{removed:bool,done:bool}
	 */
	public function erase_for_user( int $user_id, int $limit = 100 ): array;
}
