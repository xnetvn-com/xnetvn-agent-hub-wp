<?php
/**
 * Fail-closed repair of an unavailable policy snapshot.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Persistence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Uses no service or WordPress bootstrap. */
final class SettingsRepairTest extends TestCase {
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

	/** An unrelated partial preference must not rebuild an unknown security policy. */
	public function test_partial_repair_of_non_array_snapshot_is_rejected(): void {
		Functions\when( 'get_option' )->justReturn( 'invalid-policy-snapshot' );
		$this->expectException( \InvalidArgumentException::class );
		( new WpSiteSettings() )->prepare_update( array( 'notify_key_created' => false ) );
	}

	/** A complete explicit replacement may choose a restrictive new policy. */
	public function test_complete_explicit_replacement_is_accepted(): void {
		Functions\when( 'get_option' )->justReturn( 'invalid-policy-snapshot' );
		$replacement = array_merge(
			WpSiteSettings::DEFAULTS,
			array(
				'enabled'                => false,
				'tier_ceiling'           => 'read',
				'allowed_roles_override' => array(),
			)
		);
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'roles' => array() ) );
		$result = ( new WpSiteSettings() )->prepare_update( $replacement );
		self::assertFalse( $result['enabled'] );
		self::assertSame( 'read', $result['tier_ceiling'] );
		self::assertSame( array(), $result['allowed_roles_override'] );
	}
}
