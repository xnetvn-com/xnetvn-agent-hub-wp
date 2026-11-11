<?php
/**
 * Dynamic role and settings policy tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Ensures delegated use follows current capabilities without rewriting roles. */
final class CapabilitiesTest extends WP_UnitTestCase {
	/** Role changes take effect immediately and roles are never persisted by the filter. */
	public function test_default_roles_and_changes(): void {
		$settings = new WpSiteSettings();
		$provider = new Capabilities( $settings );
		$provider->register();
		$before = wp_roles()->roles;
		try {
			foreach ( array(
				'subscriber'    => false,
				'contributor'   => true,
				'author'        => true,
				'editor'        => true,
				'administrator' => true,
			) as $role => $expected ) {
				$id = self::factory()->user->create( array( 'role' => $role ) );
				self::assertSame( $expected, user_can( $id, Capabilities::USE ) );
				self::assertSame( 'administrator' === $role, user_can( $id, Capabilities::MANAGE ) );
			}
			$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
			self::assertTrue( user_can( $user, Capabilities::USE ) );
			$user->set_role( 'subscriber' );
			self::assertFalse( user_can( $user, Capabilities::USE ) );
			$user->set_role( 'editor' );
			self::assertTrue( user_can( $user, Capabilities::USE ) );
			self::assertSame( $before, wp_roles()->roles );
		} finally {
			remove_filter( 'user_has_cap', array( $provider, 'filter' ), 10 );
		}
	}

	/** Role overrides can explicitly grant subscriber use and remove editor use. */
	public function test_role_override(): void {
		$settings = new WpSiteSettings();
		$settings->update( array( 'allowed_roles_override' => array( 'subscriber' ) ) );
		$provider = new Capabilities( $settings );
		$provider->register();
		try {
			self::assertTrue( user_can( self::factory()->user->create( array( 'role' => 'subscriber' ) ), Capabilities::USE ) );
			self::assertFalse( user_can( self::factory()->user->create( array( 'role' => 'editor' ) ), Capabilities::USE ) );
		} finally {
			remove_filter( 'user_has_cap', array( $provider, 'filter' ), 10 );
		}
	}

	/** Custom roles follow edit_posts rather than hardcoded role names. */
	public function test_custom_role_capabilities(): void {
		add_role(
			'xnetvn_test_shop_manager',
			'Shop manager fixture',
			array(
				'read'       => true,
				'edit_posts' => true,
			)
		);
		add_role( 'xnetvn_test_customer', 'Customer fixture', array( 'read' => true ) );
		$provider = new Capabilities( new WpSiteSettings() );
		$provider->register();
		try {
			self::assertTrue( user_can( self::factory()->user->create( array( 'role' => 'xnetvn_test_shop_manager' ) ), Capabilities::USE ) );
			self::assertFalse( user_can( self::factory()->user->create( array( 'role' => 'xnetvn_test_customer' ) ), Capabilities::USE ) );
		} finally {
			remove_filter( 'user_has_cap', array( $provider, 'filter' ), 10 );
			remove_role( 'xnetvn_test_shop_manager' );
			remove_role( 'xnetvn_test_customer' );
		}
	}

	/** Settings reject malformed values instead of coercing them into grants. */
	public function test_settings_are_strict(): void {
		$settings = new WpSiteSettings();
		self::assertSame( 'destructive', $settings->get( 'tier_ceiling' ) );
		self::assertFalse( $settings->get( 'allow_admin_tier' ) );
		$this->expectException( \InvalidArgumentException::class );
		$settings->update( array( 'allow_admin_tier' => 'true' ) );
	}

	/** Invalid stored policy closes delegated use while managers can repair it. */
	public function test_corrupt_policy_does_not_lock_out_management(): void {
		update_option( WpSiteSettings::OPTION, array( 'allow_admin_tier' => 'wrong-type' ), false );
		$provider = new Capabilities( new WpSiteSettings() );
		$provider->register();
		try {
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			self::assertFalse( user_can( $admin, Capabilities::USE ) );
			self::assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		} finally {
			remove_filter( 'user_has_cap', array( $provider, 'filter' ), 10 );
		}
	}

	/** Valid local origins need syntax validation, not a network/SSRF fetch. */
	public function test_local_origin_configuration(): void {
		$settings = new WpSiteSettings();
		$settings->update( array( 'allowed_origins' => array( 'http://localhost:3000' ) ) );
		self::assertSame( array( 'http://localhost:3000' ), $settings->get( 'allowed_origins' ) );
	}

	/** A manager can repair corrupted stored settings with an explicit update. */
	public function test_corrupt_settings_can_be_repaired(): void {
		update_option( WpSiteSettings::OPTION, array( 'allow_admin_tier' => 'wrong-type' ), false );
		$settings = new WpSiteSettings();
		$settings->update( array( 'allow_admin_tier' => false ) );
		self::assertFalse( $settings->get( 'allow_admin_tier' ) );
		self::assertSame( 'destructive', $settings->get( 'tier_ceiling' ) );
	}

	/** Repairing one invalid field must preserve other valid restrictions. */
	public function test_repair_preserves_existing_restrictions(): void {
		update_option(
			WpSiteSettings::OPTION,
			array(
				'allow_admin_tier'       => 'wrong-type',
				'allowed_roles_override' => array( 'editor' ),
				'tier_ceiling'           => 'read',
				'site_ip_allowlist'      => array( '203.0.113.0/24' ),
			),
			false
		);
		$settings = new WpSiteSettings();
		$settings->update( array( 'allow_admin_tier' => false ) );
		self::assertSame( array( 'editor' ), $settings->get( 'allowed_roles_override' ) );
		self::assertSame( 'read', $settings->get( 'tier_ceiling' ) );
		self::assertSame( array( '203.0.113.0/24' ), $settings->get( 'site_ip_allowlist' ) );
	}

	/** A partial repair cannot silently enable a server with unknown enabled state. */
	public function test_invalid_fields_require_explicit_replacement(): void {
		update_option( WpSiteSettings::OPTION, array( 'enabled' => 'invalid' ), false );
		$this->expectException( \InvalidArgumentException::class );
		( new WpSiteSettings() )->update( array( 'allow_admin_tier' => false ) );
	}
}
