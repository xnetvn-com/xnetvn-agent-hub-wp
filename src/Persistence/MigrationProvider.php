<?php
/**
 * WordPress schema lifecycle hooks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** Preserves normal site loading when storage is temporarily unavailable. */
final class MigrationProvider implements ServiceProvider {
	/**
	 * Bind the plugin's lifecycle to its migrator.
	 *
	 * @param string   $plugin_file Main plugin file.
	 * @param Migrator $migrator Schema migrator.
	 */
	public function __construct( private string $plugin_file, private Migrator $migrator ) {
	}

	/** Register activation and cheap per-request version checks. */
	public function register(): void {
		register_activation_hook( $this->plugin_file, array( $this, 'ensure_schema' ) );
		add_action( 'plugins_loaded', array( $this, 'ensure_schema' ), 1 );
	}

	/** Migrate or display a deferred safe storage diagnostic. */
	public function ensure_schema(): void {
		try {
			$this->migrator->migrate();
		} catch ( StorageException ) {
			$notice = static function (): void {
				if ( current_user_can( 'manage_options' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Agent Hub storage is unavailable. MCP and credential operations remain closed until the database is ready.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
				}
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
		}
	}
}
