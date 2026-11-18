<?php
/**
 * Network-wide administrator protection tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\NetworkUserProtection;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress doubles must live beside the test.

/** Minimal user double with a per-site capability map. */
final class NetworkUser {
	/** User identity.
	 *
	 * @var int
	 */
	public int $ID;
	/** Role slugs for the current site.
	 *
	 * @var array<string>
	 */
	public array $roles = array();
	/** Direct capabilities for the current site.
	 *
	 * @var array<string,bool>
	 */
	public array $caps = array();
	/** Simulated current site.
	 *
	 * @var int
	 */
	public static int $site = 1;
	/** Role maps by site.
	 *
	 * @var array<int,array<string>>
	 */
	public static array $roles_by_site = array();
	/** Direct-cap maps by site.
	 *
	 * @var array<int,array<string,bool>>
	 */
	public static array $caps_by_site = array();
	/** Effective-cap maps by site.
	 *
	 * @var array<int,array<string>>
	 */
	public static array $effective_by_site = array();
	/** Simulate a failure while loading a site identity.
	 *
	 * @var bool
	 */
	public static bool $throw_on_site = false;
	/** Simulated switch stack.
	 *
	 * @var array<int>
	 */
	public static array $stack = array();
	/** Whether the next switch succeeds.
	 *
	 * @var bool
	 */
	public static bool $switch_ok = true;

	/**
	 * Bind the fixture identity and current-site state.
	 *
	 * @param int $id Identity.
	 */
	public function __construct( int $id ) {
		$this->ID = $id;
		$this->for_site( self::$site );
	}

	/**
	 * Push and switch to a site.
	 *
	 * @param int $site_id Site.
	 * @return bool
	 */
	public static function switch_to( int $site_id ): bool {
		if ( ! self::$switch_ok ) {
			return false;
		}
		self::$stack[] = self::$site;
		self::$site    = $site_id;
		return true;
	}

	/** Pop and restore the previous site. */
	public static function restore(): void {
		$previous = array_pop( self::$stack );
		if ( null !== $previous ) {
			self::$site = $previous;
		}
	}

	/**
	 * Reload the site-specific role and direct-cap state.
	 *
	 * @param int $site_id Site.
	 * @return void
	 * @throws \RuntimeException For the simulated failure fixture.
	 */
	public function for_site( int $site_id = 0 ): void {
		if ( self::$throw_on_site && 2 === $site_id ) {
			throw new \RuntimeException( 'unexpected state' );
		}
		$this->roles = self::$roles_by_site[ $site_id ] ?? array();
		$this->caps  = self::$caps_by_site[ $site_id ] ?? array();
	}
}

/** Minimal role registry double. */
final class NetworkRoles {
	/** Role definitions.
	 *
	 * @var array<string,mixed>
	 */
	public array $roles;

	/**
	 * Bind role definitions.
	 *
	 * @param array<string,mixed> $roles Roles.
	 */
	public function __construct( array $roles ) {
		$this->roles = $roles;
	}
}

/** Pins network-wide protection and the blog-stack contract.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled */
final class NetworkUserProtectionTest extends TestCase {

	/** Role definitions used by wp_roles().
	 *
	 * @var array<string,mixed>
	 */
	private array $role_map = array();

	/** Prepare WordPress function doubles. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( NetworkUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( NetworkRoles::class, 'WP_Roles' );
		}
		NetworkUser::$site              = 1;
		NetworkUser::$roles_by_site     = array();
		NetworkUser::$caps_by_site      = array();
		NetworkUser::$effective_by_site = array();
		NetworkUser::$throw_on_site     = false;
		$this->role_map                 = array(
			'administrator' => array(
				'capabilities' => array(
					'manage_options' => true,
					'edit_posts'     => true,
				),
			),
			'editor'        => array(
				'capabilities' => array(
					'edit_posts'      => true,
					'unfiltered_html' => true,
				),
			),
			'author'        => array( 'capabilities' => array( 'edit_posts' => true ) ),
			'subscriber'    => array( 'capabilities' => array( 'read' => true ) ),
		);
		Functions\when( 'apply_filters' )->returnArg();
		Functions\when( 'get_current_blog_id' )->alias( static fn(): int => NetworkUser::$site );
		Functions\when( 'ms_is_switched' )->alias( static fn(): bool => array() !== NetworkUser::$stack );
		Functions\when( 'switch_to_blog' )->alias( static fn( int $site_id ): bool => NetworkUser::switch_to( $site_id ) );
		Functions\when( 'restore_current_blog' )->alias( static fn() => NetworkUser::restore() );
		Functions\when( 'wp_roles' )->alias(
			fn(): NetworkRoles => new NetworkRoles( $this->role_map )
		);
		Functions\when( 'user_can' )->alias(
			static fn( mixed $user, string $capability ): bool => ( is_int( $user ) || $user instanceof NetworkUser ) && in_array( $capability, NetworkUser::$effective_by_site[ NetworkUser::$site ] ?? array(), true )
		);
	}

	/** Clean up mocks. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A single-site administrator is protected. */
	public function test_single_site_administrator_is_denied(): void {
		$this->single_site( array( 'administrator' ), array() );
		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** A single-site Editor is not an administrator because of unfiltered_html. */
	public function test_single_site_editor_is_permitted(): void {
		$this->single_site( array( 'editor' ), array( 'unfiltered_html' => true ) );
		self::assertTrue( ( new NetworkUserProtection() )->evaluate( 7 )->allowed );
	}

	/** A Super Admin is rejected before site enumeration. */
	public function test_multisite_super_admin_is_denied(): void {
		$this->multisite( array( 1 ), 7 );
		Functions\when( 'is_super_admin' )->justReturn( true );
		self::assertSame( 'target_protected_super_admin', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** An administrator on another subsite is protected. */
	public function test_multisite_cross_site_administrator_is_denied(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'subscriber' );
		NetworkUser::$roles_by_site[2] = array( 'administrator' );
		Functions\expect( 'get_blogs_of_user' )->once()->with( 7, true )->andReturn( $this->sites( array( 1, 2 ) ) );

		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
		self::assertSame( 1, NetworkUser::$site );
	}

	/** A non-admin Editor across sites is permitted and the stack is restored. */
	public function test_multisite_editor_is_permitted(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'editor' );
		NetworkUser::$roles_by_site[2] = array( 'editor' );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );

		self::assertTrue( ( new NetworkUserProtection() )->evaluate( 7 )->allowed );
		self::assertSame( 1, NetworkUser::$site );
		self::assertSame( array(), NetworkUser::$stack );
	}

	/** More than the inspected bound fails closed. */
	public function test_multisite_overflow_is_denied(): void {
		$this->multisite( array( 1 ), 7 );
		Functions\when( 'get_blogs_of_user' )->justReturn( array_fill( 0, NetworkUserProtection::MAX_INSPECTED_SITES + 1, (object) array( 'userblog_id' => 2 ) ) );
		self::assertSame( 'network_enumeration_incomplete', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** A failed switch fails closed and restores the initial blog. */
	public function test_switch_failure_is_denied(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'editor' );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );
		NetworkUser::$switch_ok = false;

		self::assertSame( 'network_enumeration_incomplete', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
		self::assertSame( 1, NetworkUser::$site );
	}

	/** An exception during inspection still restores the initial blog. */
	public function test_exception_restores_the_blog_stack(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'editor' );
		NetworkUser::$roles_by_site[2] = array( 'editor' );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );
		NetworkUser::$throw_on_site = true;

		self::assertSame( 'network_check_failed', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
		self::assertSame( 1, NetworkUser::$site );
	}

	/** A missing user is refused. */
	public function test_missing_target_is_denied(): void {
		$this->single_site( array( 'editor' ), array(), false );
		self::assertSame( 'target_missing', ( new NetworkUserProtection() )->evaluate( 99 )->reason );
	}

	/** A corrupt role map fails closed. */
	public function test_broken_role_map_is_denied(): void {
		$this->single_site( array( 'editor' ), array() );
		Functions\when( 'wp_roles' )->justReturn( new NetworkRoles( array( 'editor' => array() ) ) );
		self::assertSame( 'network_role_state_unavailable', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** A direct administrative capability on another site is protected. */
	public function test_multisite_cross_site_direct_capability_is_denied(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'editor' );
		NetworkUser::$roles_by_site[2] = array( 'editor' );
		NetworkUser::$caps_by_site[2]  = array( 'manage_options' => true );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );

		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** An effective administrative capability on a single site is protected. */
	public function test_single_site_effective_admin_capability_is_denied(): void {
		$this->single_site( array( 'editor' ), array() );
		NetworkUser::$effective_by_site[1] = array( 'manage_options' );
		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** An effective administrative capability on another site is protected. */
	public function test_multisite_cross_site_effective_capability_is_denied(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1]     = array( 'editor' );
		NetworkUser::$roles_by_site[2]     = array( 'editor' );
		NetworkUser::$effective_by_site[2] = array( 'manage_options' );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );

		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
		self::assertSame( 1, NetworkUser::$site );
	}

	/** A non-array enumeration result fails closed. */
	public function test_multisite_non_array_enumeration_is_denied(): void {
		$this->multisite( array( 1 ), 7 );
		Functions\when( 'get_blogs_of_user' )->justReturn( 'broken' );
		self::assertSame( 'network_enumeration_incomplete', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/** A later role elevation is rechecked; no negative verdict is cached. */
	public function test_multisite_rechecks_after_role_elevation(): void {
		$this->multisite( array( 1, 2 ), 7 );
		NetworkUser::$roles_by_site[1] = array( 'editor' );
		NetworkUser::$roles_by_site[2] = array( 'editor' );
		Functions\when( 'get_blogs_of_user' )->justReturn( $this->sites( array( 1, 2 ) ) );

		self::assertTrue( ( new NetworkUserProtection() )->evaluate( 7 )->allowed );
		NetworkUser::$roles_by_site[2] = array( 'administrator' );
		self::assertSame( 'target_protected_account', ( new NetworkUserProtection() )->evaluate( 7 )->reason );
	}

	/**
	 * Configure a single-site target.
	 *
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct caps.
	 * @param bool               $exists Whether the target exists.
	 */
	private function single_site( array $roles, array $caps, bool $exists = true ): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		NetworkUser::$roles_by_site[1] = $roles;
		NetworkUser::$caps_by_site[1]  = $caps;
		$user                          = $exists ? new NetworkUser( 7 ) : false;
		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	/**
	 * Configure a multisite target with a valid editor baseline.
	 *
	 * @param array<int> $site_ids Sites.
	 * @param int        $target Target ID.
	 */
	private function multisite( array $site_ids, int $target ): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'get_userdata' )->justReturn( new NetworkUser( $target ) );
		foreach ( $site_ids as $site_id ) {
			NetworkUser::$roles_by_site[ $site_id ] = array( 'editor' );
		}
	}

	/**
	 * Build site objects.
	 *
	 * @param array<int> $site_ids Sites.
	 * @return array<object>
	 */
	private function sites( array $site_ids ): array {
		return array_map( static fn( int $id ): object => (object) array( 'userblog_id' => $id ), $site_ids );
	}
}
