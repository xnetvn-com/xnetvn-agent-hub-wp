<?php
/**
 * Clears owned cron events when the plugin is deactivated.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Persistence;

use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Auth\Notifications;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** Removes every cron event the plugin owns on single-site and network deactivation. */
final class DeactivationProvider implements ServiceProvider {

	/** Sites requested per network pagination page. */
	private const PER_PAGE = 100;

	/** Cron hooks owned by the plugin. */
	public const HOOKS = array(
		Retention::HOOK,
		Retention::CONTINUE_HOOK,
		Notifications::HOOK,
		Notifications::BATCH_HOOK,
	);

	/**
	 * Bind the deactivation callback to the main plugin file.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public function __construct( private string $plugin_file ) {
	}

	/** Register the plugin deactivation hook. */
	public function register(): void {
		register_deactivation_hook( $this->plugin_file, array( $this, 'unschedule' ) );
	}

	/**
	 * Clear owned cron events on the current site or on every site in the network.
	 *
	 * WordPress passes the network-wide flag once instead of calling the callback per blog.
	 *
	 * @param bool $network_wide Whether the plugin was network deactivated.
	 */
	public function unschedule( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			do {
				$sites     = get_sites(
					array(
						'fields'  => 'ids',
						'number'  => self::PER_PAGE,
						'offset'  => $offset,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				);
				$page_size = count( $sites );
				foreach ( $sites as $site_id ) {
					switch_to_blog( (int) $site_id );
					try {
						$this->unschedule_current_site();
					} finally {
						restore_current_blog();
					}
				}
				$offset += self::PER_PAGE;
			} while ( self::PER_PAGE === $page_size );
			return;
		}
		$this->unschedule_current_site();
	}

	/** Clear owned cron events for the current site. */
	private function unschedule_current_site(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
