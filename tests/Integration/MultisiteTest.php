<?php
/**
 * Network actor and per-site isolation acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\MigrationProvider;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/**
 * Uses only the core harness's isolated network tables.
 *
 * @group ms-required
 */
final class MultisiteTest extends WP_UnitTestCase {
	/** Network-only cases require the actual core multisite harness, not just a group label. */
	public function set_up(): void {
		parent::set_up();
		$this->skipWithoutMultisite();
	}
	/** Default super administrators retain use and site/network management. */
	public function test_super_admin_default_capabilities_do_not_rewrite_roles(): void {
		$user   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$before = wp_roles()->roles;
		grant_super_admin( $user );
		try {
			self::assertTrue( user_can( $user, Capabilities::USE ) );
			self::assertTrue( user_can( $user, Capabilities::MANAGE ) );
			self::assertSame( $before, wp_roles()->roles );
		} finally {
			revoke_super_admin( $user );
		}
	}

	/** Super-administrator shortcuts cannot bypass an explicit site role restriction. */
	public function test_super_admin_obeys_explicit_site_role_policy_and_corruption(): void {
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$settings = new WpSiteSettings();
		grant_super_admin( $user );
		try {
			$settings->update( array( 'allowed_roles_override' => array() ) );
			self::assertFalse( user_can( $user, Capabilities::USE ) );
			self::assertTrue( user_can( $user, Capabilities::MANAGE ) );
			$settings->update( array( 'allowed_roles_override' => array( 'subscriber' ) ) );
			self::assertTrue( user_can( $user, Capabilities::USE ) );
			update_option( WpSiteSettings::OPTION, array( 'enabled' => 'invalid' ), false );
			self::assertFalse( user_can( $user, Capabilities::USE ) );
			self::assertTrue( user_can( $user, Capabilities::MANAGE ) );
		} finally {
			revoke_super_admin( $user );
		}
	}

	/** One user receives each site's current role and role matrix without persisting caps. */
	public function test_role_and_settings_scope_follow_current_blog(): void {
		$user   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$site   = self::factory()->blog->create();
		$before = wp_roles()->roles;
		add_user_to_blog( $site, $user, 'subscriber' );
		self::assertTrue( user_can( $user, Capabilities::USE ) );
		switch_to_blog( $site );
		try {
			$other_roles = wp_roles()->roles;
			self::assertFalse( user_can( $user, Capabilities::USE ) );
			( new WpSiteSettings() )->update( array( 'allowed_roles_override' => array( 'subscriber' ) ) );
			self::assertTrue( user_can( $user, Capabilities::USE ) );
			self::assertSame( $other_roles, wp_roles()->roles );
		} finally {
			restore_current_blog();
		}
		self::assertNull( ( new WpSiteSettings() )->get( 'allowed_roles_override' ) );
		self::assertTrue( user_can( $user, Capabilities::USE ) );
		self::assertSame( $before, wp_roles()->roles );
	}

	/** New-site migration, pepper and matching key IDs remain bound to the active table prefix. */
	public function test_new_site_storage_is_independent(): void {
		global $wpdb;
		$clock = new FrozenClock( time() );
		$user  = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new Migrator( $wpdb, $clock ) )->migrate();
		$keys   = new WpdbKeyRepository( $wpdb, $clock );
		$id     = new KeyId( 'abcdefghijkl' );
		$root   = $keys->insert( $id, $user, 'Root site fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$pepper = ( new PepperStore() )->current()->reveal();
		$site   = self::factory()->blog->create();
		switch_to_blog( $site );
		try {
			self::assertFalse( get_option( Migrator::VERSION_OPTION ) );
			( new MigrationProvider( XNETVN_AGENT_HUB_WP_FILE, new Migrator( $wpdb, $clock ) ) )->ensure_schema();
			self::assertSame( 1, (int) get_option( Migrator::VERSION_OPTION ) );
			self::assertNull( $keys->find_by_key_id( $id ) );
			$other = $keys->insert( $id, $user, 'Other site fixture', new Scope( RiskTier::Read ), str_repeat( 'b', 64 ), 1 );
			self::assertSame( 'Other site fixture', $keys->find_by_id( $other->id )->name );
			self::assertFalse( hash_equals( $pepper, ( new PepperStore() )->current()->reveal() ) );
		} finally {
			restore_current_blog();
		}
		self::assertSame( 'Root site fixture', $keys->find_by_id( $root->id )->name );
	}
}
