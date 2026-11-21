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

	/** Register activation, new-blog and cheap per-request version checks. */
	public function register(): void {
		register_activation_hook( $this->plugin_file, array( $this, 'ensure_schema' ) );
		add_action( 'plugins_loaded', array( $this, 'ensure_schema' ), 1 );
		add_action( 'wp_initialize_site', array( $this, 'on_initialize_site' ), 20, 1 );
	}

	/**
	 * Migrate a newly created blog without ever aborting its creation.
	 *
	 * @param \WP_Site $site New site object.
	 */
	public function on_initialize_site( \WP_Site $site ): void {
		$blog_id = (int) $site->blog_id;
		if ( $blog_id < 1 ) {
			return;
		}
		$entry = get_current_blog_id();
		if ( $blog_id === $entry ) {
			$this->ensure_schema();
			return;
		}
		try {
			switch_to_blog( $blog_id );
			$this->ensure_schema();
		} finally {
			restore_current_blog();
		}
	}

	/** Migrate or display a deferred safe storage diagnostic. */
	public function ensure_schema(): void {
		try {
			$this->migrator->migrate();
		} catch ( StorageException ) {
			$notice = static function (): void {
				self::render_storage_notice();
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
		}
	}

	/** Render the deferred storage diagnostic for administrators only. */
	public static function render_storage_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Agent Hub storage is unavailable. MCP and credential operations remain closed until the database is ready. Next step: check the database user permissions and the Site Health info, then reload this page.', 'xnetvn-agent-hub-wp' ) . '</p></div>';
	}
}
