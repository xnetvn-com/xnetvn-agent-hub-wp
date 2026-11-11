<?php
/**
 * Atomic migration lease and verified schema version.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\Clock;
/** Moves forward only and never records success from a failed DDL operation. */
final class Migrator {
	/** Migration version option. */
	public const VERSION_OPTION = 'xnetvn_agent_hub_wp_db_version';
	/** Per-site migration lease. */
	public const LOCK_OPTION = 'xnetvn_agent_hub_wp_migration_lock';
	/**
	 * Bind database and clock.
	 *
	 * @param \wpdb $db Database connection.
	 * @param Clock $clock Wall clock.
	 */
	public function __construct( private \wpdb $db, private Clock $clock ) {
	}
	/**
	 * Install the current schema once under an ownership-bound atomic lease.
	 *
	 * @return bool False only when another worker currently owns the lease.
	 * @throws StorageException For unsupported versions or failed storage operations.
	 */
	public function migrate(): bool {
		$current = get_option( self::VERSION_OPTION, 0 );
		if ( ! is_scalar( $current ) || 1 !== preg_match( '/\A[0-9]+\z/', (string) $current ) || Schema::VERSION < (int) $current ) {
			throw new StorageException( 'The database version is unsupported.' );
		}
		if ( Schema::VERSION === (int) $current ) {
			return true;
		}
		$lease = ( $this->clock->now() + 120 ) . ':' . bin2hex( random_bytes( 16 ) );
		if ( ! add_option( self::LOCK_OPTION, $lease, '', false ) ) {
			$old = get_option( self::LOCK_OPTION );
			if ( ! is_string( $old ) || (int) strtok( $old, ':' ) > $this->clock->now() ) {
				return false;
			}
			$claimed = $this->db->query( PreparedSql::make( $this->db, 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', array( $this->db->options, $lease, self::LOCK_OPTION, $old ) ) );
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			if ( 1 !== $claimed ) {
				return false;
			}
		}
		$previous = $this->db->suppress_errors( true );
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( Schema::statements( $this->db ) as $statement ) {
				dbDelta( $statement );
				if ( '' !== $this->db->last_error ) {
					throw new StorageException( 'Database migration is unavailable.' );
				}
			}
			foreach ( array( 'keys', 'audit' ) as $suffix ) {
				$table = $this->db->prefix . 'xnetvn_agent_hub_wp_' . $suffix;
				if ( $table !== $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) ) ) {
					throw new StorageException( 'Database migration is incomplete.' );
				}
			}
			update_option( self::VERSION_OPTION, Schema::VERSION, false );
			if ( (int) get_option( self::VERSION_OPTION ) !== Schema::VERSION ) {
				throw new StorageException( 'Database migration could not be recorded.' );
			}
			return true;
		} finally {
			$this->db->query( PreparedSql::make( $this->db, 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', array( $this->db->options, self::LOCK_OPTION, $lease ) ) );
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			$this->db->suppress_errors( $previous );
		}
	}
}
