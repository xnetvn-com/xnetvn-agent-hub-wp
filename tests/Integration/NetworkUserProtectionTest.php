<?php
/**
 * Multisite administrator protection acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\NetworkUserProtection;

/**
 * Uses the core multisite harness and its isolated network tables.
 *
 * @group ms-required
 */
final class NetworkUserProtectionTest extends WP_UnitTestCase {

	/** Multisite-only cases need the actual network harness. */
	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A multisite harness is required.' );
		}
	}

	/** An administrator on another subsite is protected. */
	public function test_cross_site_administrator_is_protected(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$blog = self::factory()->blog->create();
		add_user_to_blog( $blog, $user, 'administrator' );
		$initial = get_current_blog_id();

		$decision = ( new NetworkUserProtection() )->evaluate( $user );
		self::assertFalse( $decision->allowed );
		self::assertSame( 'target_protected_account', $decision->reason );
		self::assertSame( $initial, get_current_blog_id() );
	}

	/** A Super Admin is protected. */
	public function test_super_admin_is_protected(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		grant_super_admin( $user );
		try {
			$decision = ( new NetworkUserProtection() )->evaluate( $user );
			self::assertFalse( $decision->allowed );
			self::assertSame( 'target_protected_super_admin', $decision->reason );
		} finally {
			revoke_super_admin( $user );
		}
	}

	/** A non-admin Editor across two sites is permitted and the blog is restored. */
	public function test_cross_site_editor_is_permitted(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$blog = self::factory()->blog->create();
		add_user_to_blog( $blog, $user, 'editor' );
		$initial = get_current_blog_id();

		$decision = ( new NetworkUserProtection() )->evaluate( $user );
		self::assertTrue( $decision->allowed );
		self::assertSame( $initial, get_current_blog_id() );
	}

	/** A missing target is refused without leaking account state. */
	public function test_missing_target_is_denied(): void {
		$decision = ( new NetworkUserProtection() )->evaluate( 999999 );
		self::assertFalse( $decision->allowed );
		self::assertSame( 'target_missing', $decision->reason );
	}
}
