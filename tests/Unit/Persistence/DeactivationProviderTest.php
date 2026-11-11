<?php
/**
 * Deactivation cron cleanup tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Persistence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Persistence\DeactivationProvider;

/** Verifies the plugin removes its own cron events and restores the blog context. */
final class DeactivationProviderTest extends TestCase {

	/** Explicit hook names so a renamed constant cannot hide a stale event. */
	private const EXPECTED = array(
		'xnetvn_agent_hub_wp_purge_audit',
		'xnetvn_agent_hub_wp_purge_audit_backlog',
		'xnetvn_agent_hub_wp_notify_expiring_keys',
		'xnetvn_agent_hub_wp_notify_expiring_keys_batch',
	);

	/** Initialize isolated WordPress function substitutes. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/** Remove every function substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The provider binds the main plugin file to its unschedule callback. */
	public function test_register_binds_the_plugin_deactivation_hook(): void {
		$provider = new DeactivationProvider( '/tmp/xnetvn-agent-hub-wp.php' );
		Functions\expect( 'register_deactivation_hook' )
			->once()
			->with( '/tmp/xnetvn-agent-hub-wp.php', array( $provider, 'unschedule' ) );
		$provider->register();
		$this->addToAssertionCount( 1 );
	}

	/** A single-site deactivation clears every hook the plugin owns. */
	public function test_single_site_unschedules_the_four_owned_hooks(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$provider = new DeactivationProvider( '/tmp/xnetvn-agent-hub-wp.php' );
		foreach ( self::EXPECTED as $hook ) {
			Functions\expect( 'wp_unschedule_hook' )->once()->with( $hook );
		}
		$provider->unschedule( false );
		$this->addToAssertionCount( 1 );
	}

	/** A network deactivation visits every site and restores the original blog. */
	public function test_network_deactivation_visits_every_site_and_restores_the_blog(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );
		Functions\expect( 'switch_to_blog' )->twice()->with( \Mockery::type( 'int' ) );
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'wp_unschedule_hook' )->times( 8 )->with( \Mockery::type( 'string' ) );
		( new DeactivationProvider( '/tmp/xnetvn-agent-hub-wp.php' ) )->unschedule( true );
		$this->addToAssertionCount( 1 );
	}
}
