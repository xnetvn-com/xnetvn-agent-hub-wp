<?php
/**
 * Ban snapshot actor, compatibility and restore-safety tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress double must live beside the test.

/** Minimal WP_User double for the site snapshot builder. */
final class BanSnapshotUser {
	/** User identity.
	 *
	 * @var int
	 */
	public int $ID;
	/** Role slugs.
	 *
	 * @var array<string>
	 */
	public array $roles;
	/** Direct capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $caps;

	/**
	 * Bind fixture state.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct caps.
	 */
	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = $roles;
		$this->caps  = $caps;
	}
}

/**
 * Pins v1 compatibility and the typed v2 machine/human actor boundary.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BanSnapshotActorTest extends TestCase {

	/** Install isolated WordPress function substitutes. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( BanSnapshotUser::class, 'WP_User' );
		}
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_roles' )->justReturn(
			(object) array(
				'roles' => array(
					'editor' => array( 'capabilities' => array( 'edit_posts' => true ) ),
					'author' => array( 'capabilities' => array( 'edit_posts' => true ) ),
				),
			)
		);
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Existing v1 human snapshots remain valid and preserve false caps. */
	public function test_v1_human_snapshot_stays_compatible(): void {
		$snapshot = BanSnapshot::build(
			array( 'editor' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			7,
			'spam'
		);

		self::assertSame( 1, $snapshot['v'] );
		self::assertSame( 7, $snapshot['by'] );
		self::assertFalse( $snapshot['caps']['upload_files'] );
		self::assertTrue( BanSnapshot::validate( $snapshot ) );
	}

	/** A v1 snapshot needs one positive human actor and no v2 actor. */
	public function test_v1_requires_a_positive_human_actor(): void {
		$snapshot = BanSnapshot::build( array( 'editor' ), array(), 0, 'spam' );
		self::assertFalse( BanSnapshot::validate( $snapshot ) );

		unset( $snapshot['by'] );
		self::assertFalse( BanSnapshot::validate( $snapshot ) );

		$snapshot['by']    = 7;
		$snapshot['actor'] = array(
			'type'    => 'user',
			'user_id' => 7,
		);
		self::assertFalse( BanSnapshot::validate( $snapshot ) );
	}

	/** A v2 human snapshot uses only the typed actor and no legacy by field. */
	public function test_v2_user_actor_is_typed_and_has_no_by(): void {
		$snapshot = BanSnapshot::build_for_user(
			array( 'author' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			7,
			'policy'
		);

		self::assertSame( 2, $snapshot['v'] );
		self::assertSame(
			array(
				'type'    => 'user',
				'user_id' => 7,
			),
			$snapshot['actor']
		);
		self::assertArrayNotHasKey( 'by', $snapshot );
		self::assertFalse( $snapshot['caps']['upload_files'] );
		self::assertTrue( BanSnapshot::validate( $snapshot ) );
	}

	/** A v2 machine snapshot identifies the site and key without a human user. */
	public function test_v2_site_actor_is_accountless(): void {
		$target   = new BanSnapshotUser( 9, array( 'author' ), array( 'edit_posts' => true ) );
		$snapshot = BanSnapshot::build_for_site( $target, $this->context(), 'security' );

		self::assertSame( 2, $snapshot['v'] );
		self::assertSame( 'site', $snapshot['actor']['type'] );
		self::assertSame( 3, $snapshot['actor']['blog_id'] );
		self::assertSame( 'abcdefghijkl', $snapshot['actor']['key_id'] );
		self::assertArrayNotHasKey( 'user_id', $snapshot['actor'] );
		self::assertArrayNotHasKey( 'by', $snapshot );
		self::assertSame( array( 'author' ), $snapshot['roles'] );
		self::assertTrue( BanSnapshot::validate( $snapshot ) );
	}

	/** Conflicting, unknown or extra actor fields fail closed. */
	public function test_v2_rejects_conflicting_or_extra_actor_fields(): void {
		$site                     = BanSnapshot::build_for_site( new BanSnapshotUser( 9, array( 'author' ) ), $this->context(), 'spam' );
		$site['actor']['user_id'] = 9;
		self::assertFalse( BanSnapshot::validate( $site ) );

		$user                     = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$user['actor']['blog_id'] = 3;
		self::assertFalse( BanSnapshot::validate( $user ) );

		$extra                   = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$extra['actor']['extra'] = 1;
		self::assertFalse( BanSnapshot::validate( $extra ) );

		$invalid_key                    = BanSnapshot::build_for_site( new BanSnapshotUser( 9, array( 'author' ) ), $this->context(), 'spam' );
		$invalid_key['actor']['key_id'] = 'ABCDEFGHIJKL';
		self::assertFalse( BanSnapshot::validate( $invalid_key ) );

		$missing = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		unset( $missing['actor'] );
		self::assertFalse( BanSnapshot::validate( $missing ) );

		$legacy       = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$legacy['by'] = 7;
		self::assertFalse( BanSnapshot::validate( $legacy ) );

		$unknown                  = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$unknown['actor']['type'] = 'robot';
		self::assertFalse( BanSnapshot::validate( $unknown ) );
	}

	/** Privileged roles and caps, including explicit false grants, are refused. */
	public function test_privileged_roles_and_caps_are_rejected(): void {
		self::assertFalse( BanSnapshot::validate( BanSnapshot::build_for_user( array( 'administrator' ), array(), 7, 'spam' ) ) );
		self::assertFalse( BanSnapshot::validate( BanSnapshot::build_for_user( array( 'banned' ), array(), 7, 'spam' ) ) );
		self::assertFalse( BanSnapshot::validate( BanSnapshot::build_for_user( array( 'author' ), array( 'manage_options' => true ), 7, 'spam' ) ) );
		self::assertFalse( BanSnapshot::validate( BanSnapshot::build_for_user( array( 'author' ), array( 'manage_options' => false ), 7, 'spam' ) ) );

		foreach ( array(
			'administrator'       => true,
			'administrator_false' => false,
			'editor'              => true,
			'banned'              => false,
		) as $name => $enabled ) {
			$snapshot = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
			$snapshot['caps'][ 'administrator' === $name || 'administrator_false' === $name ? 'administrator' : $name ] = $enabled;
			self::assertFalse( BanSnapshot::validate( $snapshot ) );
		}
	}

	/** Builders strip role-name keys so stored caps stay direct capabilities. */
	public function test_site_builder_strips_role_name_caps(): void {
		$target   = new BanSnapshotUser(
			9,
			array( 'author' ),
			array(
				'edit_posts' => true,
				'editor'     => true,
			)
		);
		$snapshot = BanSnapshot::build_for_site( $target, $this->context(), 'spam' );
		self::assertSame( array( 'edit_posts' => true ), $snapshot['caps'] );
		self::assertTrue( BanSnapshot::validate( $snapshot ) );

		$user = BanSnapshot::build_for_user(
			array( 'author' ),
			array(
				'edit_posts' => true,
				'editor'     => false,
			),
			7,
			'spam'
		);
		self::assertArrayNotHasKey( 'editor', $user['caps'] );
		self::assertTrue( BanSnapshot::validate( $user ) );
	}

	/** Unknown reasons, impossible dates and malformed shapes fail closed. */
	public function test_invalid_reason_timestamp_and_shape_are_rejected(): void {
		$snapshot           = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$snapshot['reason'] = 'because';
		self::assertFalse( BanSnapshot::validate( $snapshot ) );

		$snapshot       = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$snapshot['at'] = '2026-02-30T00:00:00Z';
		self::assertFalse( BanSnapshot::validate( $snapshot ) );

		$snapshot       = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		$snapshot['at'] = 'not-a-date';
		self::assertFalse( BanSnapshot::validate( $snapshot ) );

		$snapshot = BanSnapshot::build_for_user( array( 'author' ), array(), 7, 'spam' );
		unset( $snapshot['reason'] );
		self::assertFalse( BanSnapshot::validate( $snapshot ) );
	}

	/** A corrupt stored snapshot is not deleted or repaired into a usable record. */
	public function test_corrupt_stored_snapshot_is_preserved(): void {
		Functions\when( 'get_user_option' )->justReturn( '{broken' );
		Functions\expect( 'delete_user_option' )->never();

		self::assertNull( BanSnapshot::load( 7 ) );
	}

	/** Saving a privileged snapshot refuses before any storage write. */
	public function test_save_refuses_privileged_snapshot_without_inserting(): void {
		$snapshot = BanSnapshot::build_for_user( array( 'administrator' ), array(), 7, 'spam' );
		$created  = true;

		self::assertFalse( BanSnapshot::save( 7, $snapshot, $created ) );
		self::assertFalse( $created );
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

		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.9' );
	}
}
