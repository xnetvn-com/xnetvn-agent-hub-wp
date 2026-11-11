<?php
/**
 * Persistent object-cache counters with atomic database fallback.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\RateLimit;

use XNetVN\AgentHubWP\Contracts\BucketStore;
use XNetVN\AgentHubWP\Persistence\PreparedSql;
use XNetVN\AgentHubWP\Persistence\RunsQueries;
use XNetVN\AgentHubWP\Persistence\StorageException;

/** Avoids transient read/modify/write races when there is no persistent cache. */
final class WpBucketStore implements BucketStore {
	use RunsQueries;

	/** Shared cache group. */
	private const GROUP = 'xnetvn_agent_hub_wp_rate';
	/**
	 * Bind the current-site options table.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private \wpdb $db ) {
	}
	/**
	 * Read a counter; unavailable/corrupt values cannot become zero.
	 *
	 * @param string $name Bucket name.
	 * @return int
	 * @throws StorageException For corrupt or unavailable storage.
	 */
	public function count( string $name ): int {
		if ( wp_using_ext_object_cache() ) {
			$value = wp_cache_get( $this->cache_key( $name ), self::GROUP, false, $found );
			if ( ! $found ) {
				return 0;
			}
		} else {
			$value = $this->run_query( fn() => $this->db->get_var( $this->db->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->db->options, $name ) ) );
			if ( null === $value ) {
				return 0;
			}
		}
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/\A[0-9]+\z/', (string) $value ) ) {
			throw new StorageException( 'Rate storage is unavailable.' );
		}
		return (int) $value;
	}
	/**
	 * Consume a shared counter using native atomic cache or SQL increment.
	 *
	 * @param string $name Bucket name.
	 * @return int
	 * @throws StorageException For a failed atomic increment.
	 */
	public function increment( string $name ): int {
		if ( wp_using_ext_object_cache() ) {
			$key = $this->cache_key( $name );
			wp_cache_add( $key, 0, self::GROUP, 180 );
			$value = wp_cache_incr( $key, 1, self::GROUP );
			if ( false === $value ) {
				throw new StorageException( 'Rate storage is unavailable.' );
			}
			return $value;
		}
		$sql = PreparedSql::make( $this->db, "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", array( $this->db->options, $name ) );
		$this->write_query( fn() => $this->db->query( $sql ) );
		return $this->count( $name );
	}
	/**
	 * Purge a bounded older batch without touching unrelated options.
	 *
	 * @param int $before_minute Exclusive bucket boundary.
	 * @param int $limit Maximum rows.
	 * @return int
	 * @throws \InvalidArgumentException For an invalid batch.
	 */
	public function purge( int $before_minute, int $limit = 1000 ): int {
		if ( $limit < 1 || $limit > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid rate cleanup batch.' );
		}
		$cutoff = sprintf( 'xnetvn_agent_hub_wp_rl_%010d_', $before_minute );
		$like   = $this->db->esc_like( 'xnetvn_agent_hub_wp_rl_' ) . '%';
		$sql    = PreparedSql::make( $this->db, 'DELETE FROM %i WHERE option_name LIKE %s AND option_name < %s ORDER BY option_name LIMIT %d', array( $this->db->options, $like, $cutoff, $limit ) );
		return $this->write_query( fn() => $this->db->query( $sql ) );
	}
	/**
	 * Remove only expired notification markers, including individual option cache entries.
	 *
	 * @param int $now UTC timestamp.
	 * @param int $limit Batch size.
	 * @return int Removed markers.
	 * @throws \InvalidArgumentException For invalid bounds.
	 * @throws StorageException For unavailable storage.
	 */
	public function purge_notices( int $now, int $limit = 1000 ): int {
		if ( $limit < 1 || $limit > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid notification cleanup batch.' );
		}
		$like  = $this->db->esc_like( 'xnetvn_agent_hub_wp_notice_' ) . '%';
		$sql   = PreparedSql::make( $this->db, 'SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_name LIMIT %d', array( $this->db->options, $like, $now, $limit ) );
		$names = $this->run_query( fn() => $this->db->get_col( $sql ) );
		if ( ! is_array( $names ) ) {
			throw new StorageException( 'Notification cleanup is unavailable.' );
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! delete_option( $name ) ) {
				throw new StorageException( 'Notification cleanup is unavailable.' );
			}
		}
		return count( $names );
	}
	/**
	 * Add a site boundary even when cache groups are configured as global.
	 *
	 * @param string $name Bucket name.
	 * @return string
	 */
	private function cache_key( string $name ): string {
		return $this->db->prefix . ':' . $name;
	}
}
