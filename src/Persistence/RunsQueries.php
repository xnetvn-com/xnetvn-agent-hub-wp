<?php
/**
 * Safe query error boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

/** Suppresses raw SQL errors and distinguishes failure from a missing/empty row. */
trait RunsQueries {
	/**
	 * Execute a database operation without exposing its SQL or parameter values.
	 *
	 * @template T
	 * @param callable $operation Database operation.
	 * @return mixed
	 * @phpstan-param callable():T $operation
	 * @phpstan-return T
	 * @throws StorageException For a database failure.
	 */
	private function run_query( callable $operation ): mixed {
		$previous    = $this->db->suppress_errors( true );
		$query_count = is_array( $this->db->queries ) ? count( $this->db->queries ) : 0;
		try {
			$result = $operation();
			if ( '' !== $this->db->last_error || false === $result ) {
				throw new StorageException( 'Storage is unavailable.' );
			}
			return $result;
		} finally {
			if ( is_array( $this->db->queries ) ) {
				$query_total = count( $this->db->queries );
				for ( $i = $query_count; $i < $query_total; ++$i ) {
					if ( isset( $this->db->queries[ $i ] ) ) {
						$entry                   = $this->db->queries[ $i ];
						$this->db->queries[ $i ] = array( '[xnetvn_agent_hub_wp prepared query; values redacted]', $entry[1], $entry[2], $entry[3], $entry[4] );
					}
				}
			}
			$this->db->suppress_errors( $previous );
		}
	}

	/**
	 * Require a definite affected-row count from a database write.
	 *
	 * @param callable $operation Database write.
	 * @return int
	 * @phpstan-param callable():(int|bool) $operation
	 * @throws StorageException For an unavailable write result.
	 */
	private function write_query( callable $operation ): int {
		$result = $this->run_query( $operation );
		if ( ! is_int( $result ) ) {
			throw new StorageException( 'Storage write outcome is unavailable.' );
		}
		return $result;
	}
}
