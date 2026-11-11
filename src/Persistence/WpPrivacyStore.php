<?php
/**
 * Per-site privacy storage without credential columns.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\PrivacyStore;

/** Exports safe metadata and erases only the confirmed owner's records. */
final class WpPrivacyStore implements PrivacyStore {
	use RunsQueries;

	/**
	 * Bind the site database.
	 *
	 * @param \wpdb $db Connection.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Read deterministic bounded owner pages, omitting secret/pepper columns.
	 *
	 * @param int $user_id Owner.
	 * @param int $page Page.
	 * @return array{keys:array<array<string,mixed>>,audit:array<array<string,mixed>>,preferences:array<array<string,mixed>>,done:bool}
	 * @throws StorageException For unavailable storage.
	 */
	public function export_for_user( int $user_id, int $page ): array {
		$offset = ( max( 1, $page ) - 1 ) * 50;
		$keys   = $this->run_query( fn() => $this->db->get_results( PreparedSql::make( $this->db, 'SELECT id,key_id,user_id,name,tier_max,toolsets,ability_allow,ability_deny,ip_allowlist,expires_at,last_used_at,last_used_ip,last_used_ua,created_at,created_ip,revoked_at,revoked_by,revoke_reason FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT 50 OFFSET %d', array( $this->db->prefix . 'xnetvn_agent_hub_wp_keys', $user_id, $offset ) ), ARRAY_A ) );
		$audit  = $this->run_query( fn() => $this->db->get_results( PreparedSql::make( $this->db, 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT 50 OFFSET %d', array( $this->db->prefix . 'xnetvn_agent_hub_wp_audit', $user_id, $offset ) ), ARRAY_A ) );
		if ( ! is_array( $keys ) || ! is_array( $audit ) ) {
			throw new StorageException( 'Privacy export is unavailable.' );
		}
		$preference  = get_user_meta( $user_id, 'xnetvn_agent_hub_wp_notifications', true );
		$preferences = 1 === $page && in_array( $preference, array( '0', '1' ), true ) ? array(
			array(
				'id'            => $user_id,
				'notifications' => '0' === $preference ? 'disabled' : 'enabled',
			),
		) : array();
		return array(
			'keys'        => $keys,
			'audit'       => $audit,
			'preferences' => $preferences,
			'done'        => count( $keys ) < 50 && count( $audit ) < 50,
		);
	}

	/**
	 * Delete credentials and remove personal correlation from audit in batches.
	 *
	 * @param int $user_id Owner.
	 * @param int $limit Maximum per table.
	 * @return array{removed:bool,done:bool}
	 * @throws \InvalidArgumentException For invalid bounds.
	 * @throws StorageException For unavailable storage.
	 */
	public function erase_for_user( int $user_id, int $limit = 100 ): array {
		if ( $user_id < 1 || $limit < 1 || $limit > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid privacy batch.' );
		}
		$keys_table  = $this->db->prefix . 'xnetvn_agent_hub_wp_keys';
		$audit_table = $this->db->prefix . 'xnetvn_agent_hub_wp_audit';
		$ids         = $this->run_query( fn() => $this->db->get_col( PreparedSql::make( $this->db, 'SELECT key_id FROM %i WHERE user_id = %d ORDER BY id LIMIT %d', array( $keys_table, $user_id, $limit ) ) ) );
		if ( ! is_array( $ids ) ) {
			throw new StorageException( 'Privacy erasure is unavailable.' );
		}
		$notices_done       = $this->erase_notices( $ids );
		$deleted            = $notices_done ? $this->write_query( fn() => $this->db->query( PreparedSql::make( $this->db, 'DELETE FROM %i WHERE user_id = %d ORDER BY id LIMIT %d', array( $keys_table, $user_id, $limit ) ) ) ) : 0;
		$preference_removed = delete_user_meta( $user_id, 'xnetvn_agent_hub_wp_notifications' );
		$anonymized         = $this->write_query( fn() => $this->db->query( PreparedSql::make( $this->db, "UPDATE %i SET user_id = NULL,key_id = NULL,request_id = '0000000000000000',ip = NULL,ua_short = NULL,arg_keys = NULL WHERE user_id = %d ORDER BY id LIMIT %d", array( $audit_table, $user_id, $limit ) ) ) );
		$revoker_anonymized = $this->write_query( fn() => $this->db->query( PreparedSql::make( $this->db, 'UPDATE %i SET revoked_by = NULL WHERE revoked_by = %d ORDER BY id LIMIT %d', array( $keys_table, $user_id, $limit ) ) ) );
		$remaining          = $this->run_query( fn() => $this->db->get_var( PreparedSql::make( $this->db, 'SELECT (SELECT COUNT(*) FROM %i WHERE user_id = %d OR revoked_by = %d) + (SELECT COUNT(*) FROM %i WHERE user_id = %d)', array( $keys_table, $user_id, $user_id, $audit_table, $user_id ) ) ) );
		if ( ! is_numeric( $remaining ) ) {
			throw new StorageException( 'Privacy erasure is unavailable.' );
		}
		return array(
			'removed' => $preference_removed || $deleted + $anonymized + $revoker_anonymized > 0,
			'done'    => $notices_done && 0 === (int) $remaining,
		);
	}

	/**
	 * Erase bounded notification markers before deleting their owner binding.
	 *
	 * @param array<mixed> $ids Selected public key IDs.
	 * @return bool Whether no extra notice page is needed.
	 * @throws StorageException For unavailable marker persistence.
	 */
	private function erase_notices( array $ids ): bool {
		if ( ! $ids ) {
			return true;
		}
		$where = array();
		$args  = array( $this->db->options );
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				throw new StorageException( 'Privacy erasure is unavailable.' );
			}
			foreach ( array( 'expiry', 'ip' ) as $kind ) {
				$where[] = 'option_name LIKE %s';
				$args[]  = $this->db->esc_like( 'xnetvn_agent_hub_wp_notice_' . $kind . '_' . $id . '_' ) . '%';
			}
		}
		$sql   = PreparedSql::make( $this->db, 'SELECT option_name FROM %i WHERE (' . implode( ' OR ', $where ) . ') ORDER BY option_name LIMIT 1000', $args );
		$names = $this->run_query( fn() => $this->db->get_col( $sql ) );
		if ( ! is_array( $names ) ) {
			throw new StorageException( 'Privacy erasure is unavailable.' );
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! delete_option( $name ) ) {
				throw new StorageException( 'Privacy erasure is unavailable.' );
			}
		}
		return count( $names ) < 1000;
	}
}
