<?php
/**
 * Real WordPress boot tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Plugin;

/** Validates successful boot against WordPress. */
final class PluginBootTest extends WP_UnitTestCase {

	/** Bootstrap defines identifiers and dispatches its hook once. */
	public function test_plugin_boots(): void {
		self::assertSame( '1.0.0', XNETVN_AGENT_HUB_WP_VERSION );
		self::assertFileExists( XNETVN_AGENT_HUB_WP_FILE );
		self::assertSame( 1, did_action( 'xnetvn_agent_hub_wp_loaded' ) );
		self::assertTrue( class_exists( Plugin::class ) );
	}

	/** Migrations are connected to plugins_loaded without a manual caller. */
	public function test_migration_hook_is_registered_on_boot(): void {
		global $wp_filter;
		$found = false;
		foreach ( $wp_filter['plugins_loaded']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && $callback[0] instanceof \XNetVN\AgentHubWP\Persistence\MigrationProvider ) {
					$found = true;
				}
			}
		}
		self::assertTrue( $found );
	}

	/** Delegated-use capabilities are available without a manual test provider. */
	public function test_capability_provider_is_registered_on_boot(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertTrue( user_can( $user, \XNetVN\AgentHubWP\Auth\Capabilities::USE ) );
	}

	/** The system pack is registered as private core abilities during normal boot. */
	public function test_system_abilities_are_registered_on_boot(): void {
		self::assertTrue( wp_has_ability( 'xnetvn/get-session-info' ) );
		self::assertFalse( wp_get_ability( 'xnetvn/get-session-info' )->get_meta()['show_in_rest'] );
	}
}
