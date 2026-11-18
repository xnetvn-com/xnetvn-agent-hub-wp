<?php
/**
 * Per-blog site credential mutation lock.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

/** Serializes site-credential mutations for one blog with a non-expiring server lock. */
final class SiteKeyLock {
	use RunsQueries;

	/** Lock-name namespace for accountless site credentials. */
	private const PREFIX = 'xnetvn_ahwp_sk_';

	/**
	 * Bind the same authoritative database connection as the site repository.
	 *
	 * @param \wpdb $db Shared database.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Run one mutation under the current blog's lock and always release it.
	 *
	 * The lock is deliberately non-transactional: rotation asserts autocommit on
	 * this same connection, so opening a transaction here would make it
	 * unavailable. A failed release is announced without replacing the result.
	 *
	 * @template T
	 * @param callable $operation Bounded mutation.
	 * @return mixed
	 * @phpstan-param callable():T $operation
	 * @phpstan-return T
	 * @throws StorageException For an invalid blog, contention or an unavailable lock.
	 */
	public function run( callable $operation ): mixed {
		$blog_id = get_current_blog_id();
		if ( $blog_id < 1 ) {
			throw new StorageException( 'Site credential mutation is unavailable.' );
		}
		$name    = self::PREFIX . $blog_id;
		$claimed = $this->run_query( fn() => $this->db->get_var( PreparedSql::make( $this->db, 'SELECT GET_LOCK(%s,0)', array( $name ) ) ) );
		if ( '1' !== (string) $claimed ) {
			throw new StorageException( 'Site credential mutation is unavailable.' );
		}
		try {
			return $operation();
		} finally {
			if ( ! $this->release( $name ) ) {
				$this->announce_incomplete_release( $blog_id );
			}
		}
	}

	/**
	 * Release the connection-bound lock without exposing its name.
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
	 * @param int $blog_id Blog identity.
	 * @return void
	 */
	private function announce_incomplete_release( int $blog_id ): void {
		try {
			do_action( 'xnetvn_agent_hub_wp_site_lock_release_incomplete', $blog_id );
		} catch ( \Throwable ) {
			// A listener must never replace a committed result or the original exception.
			return;
		}
	}
}
