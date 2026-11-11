<?php
/**
 * Versioned per-site product tables.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

/** Supplies dbDelta-compatible DDL, containing no caller-controlled SQL. */
final class Schema {
	/** Current forward-only schema version. */
	public const VERSION = 1;
	/**
	 * Describe the two per-site tables.
	 *
	 * @param \wpdb $db Database connection.
	 * @return array<string>
	 * @throws StorageException For an invalid database prefix.
	 */
	public static function statements( \wpdb $db ): array {
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/', $db->prefix ) ) {
			throw new StorageException( 'Storage configuration is unavailable.' );
		}
		$keys    = $db->prefix . 'xnetvn_agent_hub_wp_keys';
		$audit   = $db->prefix . 'xnetvn_agent_hub_wp_audit';
		$collate = $db->get_charset_collate();
		return array(
			"CREATE TABLE $keys (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
key_id char(12) NOT NULL,
user_id bigint(20) unsigned NOT NULL,
name varchar(100) NOT NULL,
secret_hash char(64) NOT NULL,
pepper_id tinyint(3) unsigned NOT NULL,
tier_max varchar(12) NOT NULL,
toolsets longtext NOT NULL,
ability_allow longtext NULL,
ability_deny longtext NULL,
ip_allowlist longtext NULL,
expires_at datetime NULL,
last_used_at datetime NULL,
last_used_ip varchar(45) NULL,
last_used_ua varchar(255) NULL,
created_at datetime NOT NULL,
created_ip varchar(45) NULL,
revoked_at datetime NULL,
revoked_by bigint(20) unsigned NULL,
revoke_reason varchar(100) NULL,
PRIMARY KEY  (id),
UNIQUE KEY key_id (key_id),
KEY user_revocation (user_id,revoked_at),
KEY expires_at (expires_at)
) $collate;",
			"CREATE TABLE $audit (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
occurred_at datetime NOT NULL,
request_id char(16) NOT NULL,
key_id char(12) NULL,
user_id bigint(20) unsigned NULL,
transport varchar(8) NOT NULL,
protocol_version varchar(16) NULL,
mcp_method varchar(40) NULL,
ability varchar(191) NULL,
outcome varchar(16) NOT NULL,
error_code varchar(64) NULL,
duration_ms int(10) unsigned NULL,
ip varchar(45) NULL,
ua_short varchar(120) NULL,
arg_keys text NULL,
PRIMARY KEY  (id),
KEY occurred_at (occurred_at),
KEY key_time (key_id,occurred_at),
KEY user_time (user_id,occurred_at),
KEY ability_time (ability,occurred_at)
) $collate;",
		);
	}
}
