<?php
/**
 * Ban snapshot persistence and v1/v2 recovery on real user options.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** Exercises insert-once storage, actor attribution and corrupt-record refusal. */
final class BanSnapshotActorTest extends WP_UnitTestCase {

	/** Restore the current user; WordPress rolls back fixture rows. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** A v1 human snapshot round-trips through the real user option. */
	public function test_v1_human_snapshot_round_trips(): void {
		$user     = self::factory()->user->create( array( 'role' => 'author' ) );
		$snapshot = BanSnapshot::build(
			array( 'author' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			7,
			'spam'
		);
		$created  = false;

		self::assertTrue( BanSnapshot::save( $user, $snapshot, $created ) );
		self::assertTrue( $created );
		self::assertSame( $snapshot, BanSnapshot::load( $user ) );

		BanSnapshot::clear( $user );
		self::assertNull( BanSnapshot::load( $user ) );
	}

	/** A v2 site snapshot stores only the website and public key identity. */
	public function test_v2_site_snapshot_round_trips(): void {
		$user   = self::factory()->user->create( array( 'role' => 'author' ) );
		$target = get_userdata( $user );
		self::assertInstanceOf( \WP_User::class, $target );
		$snapshot = BanSnapshot::build_for_site( $target, $this->context(), 'security' );
		$created  = false;

		self::assertTrue( BanSnapshot::save( $user, $snapshot, $created ) );
		self::assertTrue( $created );
		$loaded = BanSnapshot::load( $user );
		self::assertIsArray( $loaded );
		self::assertSame( 2, $loaded['v'] );
		self::assertSame( 'site', $loaded['actor']['type'] );
		self::assertSame( 3, $loaded['actor']['blog_id'] );
		self::assertSame( 'abcdefghijkl', $loaded['actor']['key_id'] );
		self::assertArrayNotHasKey( 'user_id', $loaded['actor'] );
		self::assertArrayNotHasKey( 'by', $loaded );
	}

	/** Reusing an existing valid record never replaces its recovery point. */
	public function test_existing_snapshot_is_reused(): void {
		$user  = self::factory()->user->create( array( 'role' => 'author' ) );
		$v1    = BanSnapshot::build( array( 'author' ), array( 'edit_posts' => true ), 7, 'spam' );
		$v2    = BanSnapshot::build_for_user( array( 'author' ), array( 'edit_posts' => true ), 8, 'policy' );
		$first = false;
		$again = true;

		self::assertTrue( BanSnapshot::save( $user, $v1, $first ) );
		self::assertTrue( $first );
		self::assertTrue( BanSnapshot::save( $user, $v2, $again ) );
		self::assertFalse( $again );
		self::assertSame( $v1, BanSnapshot::load( $user ) );
	}

	/** Corrupt stored evidence is refused and never deleted by a read. */
	public function test_corrupt_snapshot_is_preserved(): void {
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_option( $user, BanSnapshot::KEY, '{broken', false );

		self::assertNull( BanSnapshot::load( $user ) );
		self::assertSame( '{broken', get_user_option( BanSnapshot::KEY, $user ) );
	}

	/** A privileged snapshot is refused before any user option is inserted. */
	public function test_privileged_snapshot_is_refused(): void {
		$user     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$snapshot = BanSnapshot::build_for_user( array( 'administrator' ), array(), 7, 'spam' );
		$created  = true;

		self::assertFalse( BanSnapshot::save( $user, $snapshot, $created ) );
		self::assertFalse( $created );
		self::assertFalse( get_user_option( BanSnapshot::KEY, $user ) );
	}

	/**
	 * Build a verified machine identity.
	 *
	 * @return SiteAuthContext
	 */
	private function context(): SiteAuthContext {
		$scope      = new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), expires_at: new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		$credential = new SiteCredential(
			1,
			new KeyId( 'abcdefghijkl' ),
			3,
			'Site fixture',
			$scope,
			new DateTimeImmutable( '2026-11-17T00:00:00Z' )
		);

		return new SiteAuthContext( $credential, '0fa0fa0fa0fa0fa0', '203.0.113.9' );
	}
}
