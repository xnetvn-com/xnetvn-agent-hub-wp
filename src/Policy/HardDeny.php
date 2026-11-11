<?php
/**
 * Permanent prohibited-operation and explicit admin-risk rosters.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

/** No setting or key scope enables these prohibited capabilities. */
final class HardDeny {
	/** Permanently excluded local operation names. */
	private const FORBIDDEN = array( 'execute-php', 'run-php', 'execute-sql', 'run-sql', 'execute-shell', 'run-shell', 'edit-plugin-file', 'edit-theme-file', 'write-wp-config', 'write-htaccess', 'read-secret', 'write-secret', 'create-api-key', 'update-api-key', 'revoke-api-key', 'create-key', 'update-key', 'revoke-key', 'get-user-password', 'get-user-password-hash', 'get-application-password', 'create-application-password', 'delete-application-password', 'disable-agent-hub-security', 'install-plugin-from-zip', 'install-theme-from-zip' );
	/** Explicit reviewed admin operations; network names are added by its own approved spec. */
	private const ADMIN = array( 'create-user', 'update-user', 'set-user-role', 'delete-user', 'send-password-reset', 'set-user-status', 'terminate-user-sessions', 'activate-plugin', 'deactivate-plugin', 'install-plugin', 'update-plugin', 'delete-plugin', 'get-debug-log-tail', 'export-content', 'update-core', 'update-theme', 'update-translations', 'list-privacy-requests', 'export-personal-data', 'update-settings-sensitive' );
	/**
	 * Determine whether an operation is permanently prohibited.
	 *
	 * @param string $name Canonical name.
	 * @return bool
	 */
	public static function forbidden( string $name ): bool {
		$local = substr( $name, (int) strpos( $name, '/' ) + 1 );
		return in_array( $local, self::FORBIDDEN, true );
	}
	/**
	 * Determine whether admin risk is explicitly assigned to this reviewed name.
	 *
	 * @param string $name Canonical name.
	 * @return bool
	 */
	public static function reviewed_admin( string $name ): bool {
		return in_array( substr( $name, (int) strpos( $name, '/' ) + 1 ), self::ADMIN, true );
	}
}
