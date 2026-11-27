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
	 * A completed mutation keeps its result: an unacknowledged release is announced
	 * instead of being reported as a failed write, and an operation exception always
	 * stays the exception the caller observes.
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
			if ( ! $this->release( $name ) ) {
				$this->announce_incomplete_release( $user_id );
			}
		}
	}

	/**
	 * Release the connection-bound lock without exposing its name or values.
	 *
	 * @param string $name Lock name.
	 * @return bool Whether the server acknowledged the release.
	 */
	private function release( string $name ): bool {
		try {
			$released = $this->run_query( fn() => $this->db->get_var( PreparedSql::make( $this->db, 'SELECT RELEASE_LOCK(%s)', array( $name ) ) ) );
			return '1' === (string) $released;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Announce an incomplete release without changing the operation outcome.
	 *
	 * @param int $user_id Owner.
	 * @return void
	 */
	private function announce_incomplete_release( int $user_id ): void {
		try {
			do_action( 'xnetvn_agent_hub_wp_lock_release_incomplete', $user_id );
		} catch ( \Throwable ) {
			// A listener must never replace a committed result or the original exception.
			return;
		}
	}
}
