<?php
/**
 * Protected-account exclusion for personal MCP credentials.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress doubles must live beside the test.

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\DelegationEligibility;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\OwnerLock;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal user double for the standalone eligibility check. */
final class EligibilityUser {
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
	 * Bind the fixture identity.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct capabilities.
	 */
	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = $roles;
		$this->caps  = $caps;
	}
}

/** Minimal role registry double. */
final class EligibilityRoles {
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

/** Pins the non-protected personal-key boundary before KeyService consumes it. */
final class DelegationEligibilityTest extends TestCase {

	/** Prepare WordPress function mocks and the local class aliases. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( EligibilityUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( EligibilityRoles::class, 'WP_Roles' );
		}
	}

	/** Clean up mock state. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Administrator role is always protected. */
	public function test_administrator_is_denied(): void {
		$this->configure( new EligibilityUser( 7, array( 'administrator' ) ), $this->administrator_roles(), array( Capabilities::USE, 'manage_options' ) );
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 7 )->reason );
	}

	/** Multisite Super Admin is always protected. */
	public function test_super_admin_is_denied(): void {
		$this->configure( new EligibilityUser( 8, array( 'editor' ) ), $this->editor_roles(), array( Capabilities::USE ), true, true );
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 8 )->reason );
	}

	/** An Editor keeps a personal key; unfiltered_html is not identity administration. */
	public function test_editor_with_unfiltered_html_is_permitted(): void {
		$this->configure( new EligibilityUser( 9, array( 'editor' ) ), $this->editor_roles(), array( Capabilities::USE ) );
		self::assertTrue( ( new DelegationEligibility() )->check( 9 )->allowed );
	}

	/** An Author keeps a personal key. */
	public function test_author_is_permitted(): void {
		$this->configure( new EligibilityUser( 10, array( 'author' ) ), $this->author_roles(), array( Capabilities::USE ) );
		self::assertTrue( ( new DelegationEligibility() )->check( 10 )->allowed );
	}

	/** A hidden administrator role in a multi-role user is still protected. */
	public function test_dual_role_administrator_is_denied(): void {
		$roles = array_merge( $this->editor_roles(), $this->administrator_roles() );
		$this->configure( new EligibilityUser( 11, array( 'editor', 'administrator' ) ), $roles, array( Capabilities::USE ) );
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 11 )->reason );
	}

	/** An unknown role slug is unusable evidence, not an allow. */
	public function test_unknown_role_is_denied(): void {
		$this->configure( new EligibilityUser( 12, array( 'mystery' ) ), $this->editor_roles(), array( Capabilities::USE ) );
		self::assertSame( 'delegation_role_state_unavailable', ( new DelegationEligibility() )->check( 12 )->reason );
	}

	/** A role without a usable capability map is denied. */
	public function test_broken_role_map_is_denied(): void {
		$this->configure( new EligibilityUser( 13, array( 'editor' ) ), array( 'editor' => array() ), array( Capabilities::USE ) );
		self::assertSame( 'delegation_role_state_unavailable', ( new DelegationEligibility() )->check( 13 )->reason );
	}

	/** A direct administrative capability is protected even without the role. */
	public function test_direct_admin_capability_is_denied(): void {
		$this->configure( new EligibilityUser( 14, array( 'editor' ), array( 'manage_options' => true ) ), $this->editor_roles(), array( Capabilities::USE ) );
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 14 )->reason );
	}

	/** Effective administrative capability is protected even without a direct cap. */
	public function test_effective_admin_capability_is_denied(): void {
		$this->configure( new EligibilityUser( 15, array( 'editor' ) ), $this->editor_roles(), array( Capabilities::USE, 'manage_options' ) );
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 15 )->reason );
	}

	/** The add-only privileged filter cannot be bypassed. */
	public function test_filter_added_capability_is_denied(): void {
		$roles = array(
			'editor' => array(
				'capabilities' => array(
					'edit_posts'   => true,
					'custom_admin' => true,
				),
			),
		);
		$this->configure(
			new EligibilityUser( 16, array( 'editor' ) ),
			$roles,
			array( Capabilities::USE ),
			false,
			false,
			static fn( string $tag, mixed $value ): mixed => array_merge( (array) $value, array( 'custom_admin' ) )
		);
		self::assertSame( 'delegation_protected_account', ( new DelegationEligibility() )->check( 16 )->reason );
	}

	/** A throwing extension fails closed. */
	public function test_filter_exception_fails_closed(): void {
		$this->configure(
			new EligibilityUser( 17, array( 'editor' ) ),
			$this->editor_roles(),
			array( Capabilities::USE ),
			false,
			false,
			static function (): void {
				throw new \RuntimeException( 'extension failure' );
			}
		);
		self::assertSame( 'delegation_check_failed', ( new DelegationEligibility() )->check( 17 )->reason );
	}

	/** A missing user is refused without leaking account state. */
	public function test_missing_user_is_denied(): void {
		$this->configure( null, $this->editor_roles(), array( Capabilities::USE ) );
		self::assertSame( 'delegation_owner_unavailable', ( new DelegationEligibility() )->check( 99 )->reason );
	}

	/** Missing the plugin use capability is refused. */
	public function test_missing_use_capability_is_denied(): void {
		$this->configure( new EligibilityUser( 18, array( 'editor' ) ), $this->editor_roles(), array() );
		self::assertSame( 'delegation_capability_missing', ( new DelegationEligibility() )->check( 18 )->reason );
	}

	/** KeyService create refuses a protected owner before any repository write. */
	public function test_key_service_create_refuses_protected_owner(): void {
		$this->configure( new EligibilityUser( 7, array( 'administrator' ) ), $this->administrator_roles(), array( Capabilities::USE ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$keys = $this->createMock( KeyRepository::class );
		$keys->expects( self::never() )->method( 'insert' );

		$result = $this->service( $keys )->create( 7, array( 'name' => 'Protected key' ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $result->code );
		self::assertSame( 403, $result->data['status'] );
	}

	/** KeyService rotate refuses before the quota count and rotation insert. */
	public function test_key_service_rotate_refuses_before_quota(): void {
		$this->configure( new EligibilityUser( 7, array( 'administrator' ) ), $this->administrator_roles(), array( Capabilities::USE ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		$old  = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable( '@1791158400' ) );
		$keys = $this->createMock( KeyRepository::class );
		$keys->method( 'find_by_id' )->willReturn( $old );
		$keys->expects( self::never() )->method( 'count_active_for_user' );
		$keys->expects( self::never() )->method( 'insert_rotation' );

		$result = $this->service( $keys )->rotate( 1, array() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->data['status'] );
	}

	/** KeyService patch refuses an extension before any row update. */
	public function test_key_service_patch_refuses_extension(): void {
		$this->configure( new EligibilityUser( 7, array( 'administrator' ) ), $this->administrator_roles(), array( Capabilities::USE ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		$old  = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable( '@1791158400' ) );
		$keys = $this->createMock( KeyRepository::class );
		$keys->method( 'find_by_id' )->willReturn( $old );
		$keys->expects( self::never() )->method( 'update_key' );

		$result = $this->service( $keys )->patch( 1, array( 'expires_in_days' => 30 ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->data['status'] );
	}

	/**
	 * Build a KeyService with pure seams.
	 *
	 * @param KeyRepository $keys Persistence.
	 * @return KeyService
	 */
	private function service( KeyRepository $keys ): KeyService {
		$catalog = $this->createMock( AbilityCatalog::class );
		$catalog->method( 'all' )->willReturn( array() );
		$lock = $this->createMock( OwnerLock::class );
		$lock->method( 'run' )->willReturnCallback( static fn( int $owner, callable $operation ): mixed => $operation() );

		return new KeyService( $keys, new PepperStore(), new KeyGenerator(), new MemorySettings(), $catalog, $this->createMock( RateLimiter::class ), $lock, new FrozenClock( 1791158400 ) );
	}

	/**
	 * Configure the WordPress function doubles.
	 *
	 * @param EligibilityUser|null $user User or null for a missing account.
	 * @param array<string,mixed>  $roles Role map.
	 * @param array<string>        $effective Effective capabilities.
	 * @param bool                 $multisite Multisite flag.
	 * @param bool                 $super_admin Super Admin flag.
	 * @param callable|null        $filter Privileged-caps filter.
	 */
	private function configure( ?EligibilityUser $user, array $roles, array $effective, bool $multisite = false, bool $super_admin = false, ?callable $filter = null ): void {
		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ): mixed => null !== $user && $user->ID === $id ? $user : false
		);
		Functions\when( 'is_multisite' )->justReturn( $multisite );
		Functions\when( 'is_super_admin' )->justReturn( $super_admin );
		Functions\when( 'wp_roles' )->justReturn( new EligibilityRoles( $roles ) );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => null === $filter ? $value : $filter( $tag, $value )
		);
		Functions\when( 'user_can' )->alias(
			static fn( mixed $subject, string $capability ): bool => in_array( $capability, $effective, true )
		);
	}

	/**
	 * Return the administrator role map.
	 *
	 * @return array<string,array{capabilities:array<string,bool>}>
	 */
	private function administrator_roles(): array {
		return array(
			'administrator' => array(
				'capabilities' => array(
					'manage_options' => true,
					'edit_posts'     => true,
				),
			),
		);
	}

	/**
	 * Return the editor role map.
	 *
	 * @return array<string,array{capabilities:array<string,bool>}>
	 */
	private function editor_roles(): array {
		return array(
			'editor' => array(
				'capabilities' => array(
					'edit_posts'      => true,
					'unfiltered_html' => true,
				),
			),
		);
	}

	/**
	 * Return the author role map.
	 *
	 * @return array<string,array{capabilities:array<string,bool>}>
	 */
	private function author_roles(): array {
		return array( 'author' => array( 'capabilities' => array( 'edit_posts' => true ) ) );
	}
}
