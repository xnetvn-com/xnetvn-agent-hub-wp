<?php
/**
 * Bounded audit persistence contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

/** Persists whitelisted event metadata, never raw arguments or credentials. */
interface AuditRepository {
	/**
	 * Insert a validated batch in one statement.
	 *
	 * @param array<array<string,mixed>> $events Events.
	 * @return int Inserted rows.
	 */
	public function insert_batch( array $events ): int;
	/**
	 * Query a bounded page.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @param int                 $page Page.
	 * @return array{items:array<array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public function query( array $filter = array(), int $page = 1 ): array;
	/**
	 * Purge only a bounded older batch.
	 *
	 * @param string $cutoff UTC cutoff.
	 * @param int    $limit Maximum rows.
	 * @return int Deleted rows.
	 */
	public function purge_older_than( string $cutoff, int $limit ): int;
}
