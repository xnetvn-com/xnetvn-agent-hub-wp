<?php
/**
 * Connection-bound owner mutation lock.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\OwnerLock;

/** No expiring lease can let a slow writer overlap a second quota mutation. */
final class WpOwnerLock implements OwnerLock {
	use RunsQueries;

	/**
	 * Bind the same authoritative database connection as the key repository.
	 *
	 * @param \wpdb $db Shared database.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Acquire a non-blocking owner lock and release it even on exceptions.
	 *
	 * @template T
	 * @param int      $user_id Owner.
	 * @param callable $operation Bounded mutation.
	 * @return mixed
	 * @phpstan-param callable():T $operation
	 * @phpstan-return T
	 * @throws StorageException For contention or unavailable locks.
	 */
	public function run( int $user_id, callable $operation ): mixed {
		$database = $this->run_query( fn() => $this->db->get_var( 'SELECT DATABASE()' ) );
		if ( $user_id < 1 || ! is_string( $database ) || '' === $database ) {
			throw new StorageException( 'Credential mutation is unavailable.' );
		}
		$name    = 'xnv-key-' . substr( hash( 'sha256', $database . ':' . $this->db->prefix . ':' . $user_id ), 0, 48 );
		$claimed = $this->run_query( fn() => $this->db->get_var( PreparedSql::make( $this->db, 'SELECT GET_LOCK(%s,0)', array( $name ) ) ) );
		if ( '1' !== (string) $claimed ) {
			throw new StorageException( 'Credential mutation is unavailable.' );
		}
		try {
			return $operation();
		} finally {
			$released = $this->run_query( fn() => $this->db->get_var( PreparedSql::make( $this->db, 'SELECT RELEASE_LOCK(%s)', array( $name ) ) ) );
			if ( '1' !== (string) $released ) {
				throw new StorageException( 'Credential mutation is unavailable.' );
			}
		}
	}
}
