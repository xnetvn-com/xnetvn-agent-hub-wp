<?php
/**
 * Real uninstall behavior in the isolated test database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Includes the actual PHP 7.2-safe entry point with its core-only guard. */
final class UninstallTest extends WP_UnitTestCase {
	/** Restore real plugin tables even if an assertion fails after uninstall. */
	public function tear_down(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		( new Migrator( $wpdb, new FrozenClock( time() ) ) )->migrate();
		parent::tear_down();
	}
	/** A deliberate preservation choice keeps data while all cron is removed. */
	public function test_preservation_keeps_tables_and_options(): void {
		global $wpdb;
		update_option( WpSiteSettings::OPTION, array( 'delete_data_on_uninstall' => false ), false );
		add_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture', '1', '', false );
		wp_schedule_single_event( time() + 60, Retention::HOOK );
		$this->run_uninstall();
		self::assertFalse( wp_next_scheduled( Retention::HOOK ) );
		self::assertSame( '1', get_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture' ) );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) ) ) );
	}

	/** Default deletion affects only exact plugin-owned names and tables. */
	public function test_default_deletion_is_owned_and_complete(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		update_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', '0' );
		delete_option( WpSiteSettings::OPTION );
		update_option( 'xnetvn_agent_hub_wp_pepper', 'synthetic', false );
		update_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture', '1', false );
		update_option( 'unrelated_xnetvn_agent_hub_wp_fixture', 'keep', false );
		update_option( 'xnetvnXagentXhubXwpXfixture', 'keep', false );
		wp_schedule_single_event( time() + 60, Retention::CONTINUE_HOOK );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) );
		$this->run_uninstall();
		self::assertSame( '', get_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', true ) );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_pepper' ) );
		self::assertFalse( get_option( Migrator::VERSION_OPTION ) );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture' ) );
		self::assertSame( 'keep', get_option( 'unrelated_xnetvn_agent_hub_wp_fixture' ) );
		self::assertSame( 'keep', get_option( 'xnetvnXagentXhubXwpXfixture' ) );
		self::assertFalse( wp_next_scheduled( Retention::CONTINUE_HOOK ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) ) ) );
		( new Migrator( $wpdb, new FrozenClock( time() ) ) )->migrate();
	}

	/** Run only with the WordPress uninstall guard in the test environment. */
	private function run_uninstall(): void {
		// Core's test harness rewrites DROP into DROP TEMPORARY; this test must exercise the real plugin tables in the isolated test database.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core uninstall guard for the actual entry point test.
			define( 'WP_UNINSTALL_PLUGIN', 'xnetvn-agent-hub-wp/xnetvn-agent-hub-wp.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
