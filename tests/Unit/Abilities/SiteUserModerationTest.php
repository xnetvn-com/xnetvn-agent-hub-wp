<?php
/**
 * Machine moderation direction, validation and side-effect contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Isolated WordPress doubles must live beside the test.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Abilities\Packs\Users\SiteUserModeration;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal WordPress role registry double. */
final class SiteModerationRoles {
	/** Role definitions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $roles;

	/**
	 * Bind role definitions.
	 *
	 * @param array<string,array<string,mixed>> $roles Definitions.
	 */
	public function __construct( array $roles ) {
		$this->roles = $roles;
	}

	/**
	 * Whether a name is a registered role slug.
	 *
	 * @param string $name Candidate name.
	 * @return bool
	 */
	public function is_role( string $name ): bool {
		return isset( $this->roles[ $name ] );
	}
}

/** Minimal WordPress user double with core role and capability semantics. */
final class SiteModerationUser {
	/** User identity.
	 *
	 * @var int
	 */
	public int $ID;
	/** Assigned role slugs.
	 *
	 * @var array<string>
	 */
	public array $roles;
	/** Direct capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $caps;
	/** Effective capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $allcaps = array();
	/** Post-write capability overrides for the exact-map guards.
	 *
	 * A null value withholds the key; a boolean forces that value.
	 *
	 * @var array<string,bool|null>
	 */
	public array $withheld_caps = array();

	/**
	 * Bind fixture state.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Role slugs.
	 * @param array<string,bool> $caps Direct capabilities.
	 */
	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = array_values( $roles );
		$this->caps  = $caps;
		$this->refresh();
	}

	/**
	 * Return the fixed role definitions in the core registry shape.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function role_definitions(): array {
		$definitions = array();
		foreach ( self::role_caps() as $slug => $capabilities ) {
			$definitions[ $slug ] = array(
				'name'         => ucfirst( $slug ),
				'capabilities' => $capabilities,
			);
		}
		return $definitions;
	}

	/**
	 * Return the fixed role capability map.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function role_caps(): array {
		return array(
			'administrator' => array(
				'read'           => true,
				'edit_users'     => true,
				'promote_users'  => true,
				'manage_options' => true,
			),
			'editor'        => array(
				'read'       => true,
				'edit_posts' => true,
			),
			'author'        => array(
				'read'         => true,
				'edit_posts'   => true,
				'upload_files' => true,
			),
			'subscriber'    => array( 'read' => true ),
			'banned'        => array(),
		);
	}

	/** Recompute effective capabilities from roles and direct grants. */
	public function refresh(): void {
		$effective = array();
		$map       = self::role_caps();
		foreach ( $this->roles as $slug ) {
			foreach ( $map[ $slug ] ?? array() as $capability => $enabled ) {
				$effective[ $capability ] = (bool) $enabled;
			}
		}
		foreach ( $this->caps as $capability => $enabled ) {
			$effective[ $capability ] = (bool) $enabled;
		}
		$this->allcaps = $effective;
	}

	/**
	 * Replace the role set like WP_User::set_role().
	 *
	 * @param string $role Role slug.
	 * @return void
	 */
	public function set_role( string $role ): void {
		foreach ( $this->roles as $slug ) {
			unset( $this->caps[ $slug ] );
		}
		unset( $this->caps[ $role ] );
		$this->roles = array( $role );
		$this->refresh();
	}

	/**
	 * Add one role.
	 *
	 * @param string $role Role slug.
	 * @return void
	 */
	public function add_role( string $role ): void {
		if ( ! in_array( $role, $this->roles, true ) ) {
			$this->roles[] = $role;
		}
		$this->refresh();
	}

	/**
	 * Grant or block one direct capability.
	 *
	 * @param string $capability Capability.
	 * @param bool   $grant Grant state.
	 * @return void
	 */
	public function add_cap( string $capability, bool $grant = true ): void {
		if ( array_key_exists( $capability, $this->withheld_caps ) ) {
			$forced = $this->withheld_caps[ $capability ];
			if ( null === $forced ) {
				unset( $this->caps[ $capability ] );
			} else {
				$this->caps[ $capability ] = (bool) $forced;
			}
			$this->refresh();
			return;
		}
		$this->caps[ $capability ] = $grant;
		$this->refresh();
	}

	/**
	 * Remove one direct capability.
	 *
	 * @param string $capability Capability.
	 * @return void
	 */
	public function remove_cap( string $capability ): void {
		unset( $this->caps[ $capability ] );
		$this->refresh();
	}

	/**
	 * Whether the effective set contains the capability.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public function has_cap( string $capability ): bool {
		return ! empty( $this->allcaps[ $capability ] );
	}

	/**
	 * Accept a site switch without changing the fixture.
	 *
	 * @param int $blog_id Blog identity.
	 * @return void
	 */
	public function for_site( int $blog_id ): void {
		unset( $blog_id );
	}
}

/** Minimal WP_Session_Tokens double with a controllable failure. */
final class SiteModerationSessions {
	/** Destroyed user identities.
	 *
	 * @var array<int>
	 */
	public static array $destroyed = array();
	/** Whether the session store throws.
	 *
	 * @var bool
	 */
	public static bool $throws = false;
	/** Remaining sessions after the sweep.
	 *
	 * @var array<string,mixed>
	 */
	public static array $remaining = array();

	/** Bound user identity.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Bind the identity.
	 *
	 * @param int $user_id User identity.
	 */
	private function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Return the manager for one user.
	 *
	 * @param int $user_id User identity.
	 * @return self
	 */
	public static function get_instance( int $user_id ): self {
		return new self( $user_id );
	}

	/**
	 * Destroy every session or throw the configured failure.
	 *
	 * @return void
	 * @throws \RuntimeException When the fixture session store fails.
	 */
	public function destroy_all(): void {
		self::$destroyed[] = $this->user_id;
		if ( self::$throws ) {
			throw new \RuntimeException( 'Fixture session store failure.' );
		}
		self::$remaining = array();
	}

	/**
	 * Return the remaining sessions.
	 *
	 * @return array<string,mixed>
	 */
	public function get_all(): array {
		return self::$remaining;
	}
}

/** Recording expensive budget with a refusal switch. */
final class SiteModerationBudget implements ExpensiveBudget {
	/** Recorded identities.
	 *
	 * @var array<string>
	 */
	public array $identities = array();
	/** Whether the budget refuses the work.
	 *
	 * @var bool
	 */
	public bool $refuse = false;

	/**
	 * Run or refuse the work and record the identity.
	 *
	 * @param string  $identity Verified site identity.
	 * @param Closure $work Work.
	 * @return mixed
	 */
	public function run( string $identity, Closure $work ): mixed {
		$this->identities[] = $identity;
		if ( $this->refuse ) {
			return ReasonMapper::error( 'rate_limited' );
		}
		return $work();
	}
}

/**
 * Proves the directional grant, the cross-field rules and recovery-safe writes.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SiteUserModerationTest extends TestCase {
	/** Current blog. */
	private const BLOG = 1;
	/** Primary target. */
	private const TARGET = 9;
	/** Secondary target. */
	private const OTHER = 10;
	/** Public key identity. */
	private const KEY_ID = 'abcdefghijkl';

	/** Site policy fixture.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Machine dispatcher under test.
	 *
	 * @var SiteDispatcher
	 */
	private SiteDispatcher $dispatcher;
	/** Recording budget.
	 *
	 * @var SiteModerationBudget
	 */
	private SiteModerationBudget $budget;
	/** Role registry substitute.
	 *
	 * @var SiteModerationRoles
	 */
	private SiteModerationRoles $registry_roles;
	/** User rows.
	 *
	 * @var array<int,SiteModerationUser>
	 */
	private array $users = array();
	/** Membership answers.
	 *
	 * @var array<int,bool>
	 */
	private array $members = array();
	/** Stored snapshot JSON per user.
	 *
	 * @var array<int,string>
	 */
	private array $snapshots = array();
	/** Whether the delete boundary refuses the snapshot removal.
	 *
	 * @var bool
	 */
	private bool $refuse_delete = false;
	/** Deny-only target filter answer.
	 *
	 * @var bool|Closure
	 */
	private bool|Closure $user_allowed = true;
	/** Target filter invocations.
	 *
	 * @var int
	 */
	private int $filter_calls = 0;
	/** Current blog substitute.
	 *
	 * @var int
	 */
	private int $blog_id = self::BLOG;

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		require_once dirname( __DIR__, 2 ) . '/Support/WpdbStub.php';
		Monkey\setUp();
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( SiteModerationUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( SiteModerationRoles::class, 'WP_Roles' );
		}
		if ( ! class_exists( 'WP_Session_Tokens', false ) ) {
			class_alias( SiteModerationSessions::class, 'WP_Session_Tokens' );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture for the per-site snapshot key.
		$GLOBALS['wpdb']                   = new \wpdb();
		$this->settings                    = new MemorySettings(
			array(
				'enabled'          => true,
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
				'output_max_bytes' => 262144,
			)
		);
		$this->budget                      = new SiteModerationBudget();
		$this->registry_roles              = new SiteModerationRoles( SiteModerationUser::role_definitions() );
		SiteModerationSessions::$destroyed = array();
		SiteModerationSessions::$throws    = false;
		SiteModerationSessions::$remaining = array();
		$this->wordpress();
		$this->dispatcher = $this->dispatcher();
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Each grant reaches only its own direction and never the human capability path. */
	public function test_directional_grants_reach_only_their_own_direction(): void {
		$this->add_user( self::TARGET, array( 'author' ) );
		$ban_only   = $this->context( array( 'ban-users' ) );
		$unban_only = $this->context( array( 'unban-users' ) );
		$no_grant   = $this->context( array() );

		$wrong_direction = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $wrong_direction );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $wrong_direction->get_error_code() );
		self::assertSame( array(), SiteModerationSessions::$destroyed );

		$banned = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'security',
			),
			$ban_only
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->message : '' );
		self::assertSame( array( 'id', 'status', 'previous_status', 'changed', 'roles' ), array_keys( $banned ) );
		self::assertSame( self::TARGET, $banned['id'] );
		self::assertSame( 'banned', $banned['status'] );
		self::assertSame( 'active', $banned['previous_status'] );
		self::assertTrue( $banned['changed'] );
		self::assertSame( array( 'banned' ), $banned['roles'] );
		self::assertSame( array( self::TARGET ), SiteModerationSessions::$destroyed );

		$cannot_ban = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $cannot_ban );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $cannot_ban->get_error_code() );

		$restored = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->message : '' );
		self::assertSame( 'active', $restored['status'] );
		self::assertSame( 'banned', $restored['previous_status'] );
		self::assertTrue( $restored['changed'] );
		self::assertSame( array( 'author' ), $restored['roles'] );

		$no_permission = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$no_grant
		);
		self::assertInstanceOf( \WP_Error::class, $no_permission );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $no_permission->get_error_code() );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Cross-field rules refuse unsafe input without a write. */
	public function test_cross_field_validation_refuses_without_writing(): void {
		$this->add_user( self::TARGET, array( 'author' ) );
		$ban_only = $this->context( array( 'ban-users' ) );

		$restore_role = $this->dispatch(
			array(
				'id'           => self::TARGET,
				'status'       => 'banned',
				'reason_code'  => 'spam',
				'restore_role' => 'subscriber',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $restore_role );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $restore_role->get_error_code() );

		$active_reason = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'active',
				'reason_code' => 'spam',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $active_reason );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $active_reason->get_error_code() );

		$unknown_reason = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'because',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $unknown_reason );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unknown_reason->get_error_code() );

		$invalid_id = $this->dispatch(
			array(
				'id'     => 0,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $invalid_id );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $invalid_id->get_error_code() );

		$invalid_status = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'zombie',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $invalid_status );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $invalid_status->get_error_code() );

		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertNull( BanSnapshot::load( self::TARGET ) );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
	}

	/** Ban and unban restore the exact roles and direct capabilities. */
	public function test_ban_and_unban_round_trip_restores_roles_and_caps(): void {
		$this->add_user(
			self::TARGET,
			array( 'author' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			)
		);
		$both = $this->context( array( 'ban-users', 'unban-users' ) );

		$banned = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$both
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->message : '' );
		self::assertTrue( $banned['changed'] );
		self::assertSame( array( 'banned' ), $this->users[ self::TARGET ]->roles );
		self::assertSame( array(), $this->users[ self::TARGET ]->caps );
		self::assertSame( array( self::TARGET ), SiteModerationSessions::$destroyed );
		self::assertSame( array( 'site:' . self::BLOG . ':' . self::KEY_ID ), $this->budget->identities );

		$snapshot = BanSnapshot::load( self::TARGET );
		self::assertIsArray( $snapshot );
		self::assertSame( 2, $snapshot['v'] );
		self::assertSame( 'site', $snapshot['actor']['type'] );
		self::assertSame( self::BLOG, $snapshot['actor']['blog_id'] );
		self::assertSame( self::KEY_ID, $snapshot['actor']['key_id'] );
		self::assertSame( array( 'author' ), $snapshot['roles'] );
		self::assertSame(
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			$snapshot['caps']
		);
		self::assertArrayNotHasKey( 'by', $snapshot );
		self::assertSame( 'policy', $snapshot['reason'] );

		$again = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$both
		);
		self::assertIsArray( $again, $again instanceof \WP_Error ? $again->message : '' );
		self::assertFalse( $again['changed'] );
		self::assertSame( array( 'banned' ), $again['roles'] );
		self::assertSame( $snapshot, BanSnapshot::load( self::TARGET ) );
		self::assertCount( 1, SiteModerationSessions::$destroyed );

		$restored = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$both
		);
		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->message : '' );
		self::assertTrue( $restored['changed'] );
		self::assertSame( array( 'author' ), $restored['roles'] );
		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertSame(
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			$this->users[ self::TARGET ]->caps
		);
		self::assertNull( BanSnapshot::load( self::TARGET ) );

		$repeated = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$both
		);
		self::assertIsArray( $repeated, $repeated instanceof \WP_Error ? $repeated->message : '' );
		self::assertFalse( $repeated['changed'] );
		self::assertSame( array( 'author' ), $repeated['roles'] );
		self::assertCount( 1, SiteModerationSessions::$destroyed );
	}

	/** A roleless target is refused before the ban writes anything. */
	public function test_ban_refuses_a_target_with_no_captured_roles_without_writing(): void {
		$target                            = $this->add_user( self::TARGET, array(), array( 'read' => true ) );
		$tokens                            = array( 'session' => array( 'expiration' => 1900000000 ) );
		SiteModerationSessions::$destroyed = array();
		SiteModerationSessions::$remaining = $tokens;

		$refused = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertArrayNotHasKey( self::TARGET, $this->snapshots );
		self::assertSame( array(), $target->roles );
		self::assertSame( array( 'read' => true ), $target->caps );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
		self::assertSame( $tokens, SiteModerationSessions::$remaining );
	}

	/** A recovery point the unban cannot consume is refused before every write. */
	public function test_ban_refuses_a_recovery_point_the_unban_cannot_consume(): void {
		$this->registry_roles->roles['content_editor'] = array(
			'name'         => 'Content Editor',
			'capabilities' => array(
				'read'       => true,
				'edit_posts' => true,
			),
		);
		$live                              = $this->add_user( self::TARGET, array( 'content_editor' ) );
		$token                             = array( 'live' => array( 'expiration' => 1900000000 ) );
		SiteModerationSessions::$destroyed = array();
		SiteModerationSessions::$remaining = $token;
		$unconsumable                      = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);
		$live_tokens                       = SiteModerationSessions::$remaining;

		$stored  = $this->add_user( self::OTHER, array() );
		$record  = BanSnapshot::build( array( 'reviewer' ), array( 'edit_posts' => true ), 7, 'spam' );
		$created = false;
		self::assertTrue( BanSnapshot::save( self::OTHER, $record, $created ) );
		self::assertTrue( $created );
		$bytes                             = $this->snapshots[ self::OTHER ];
		SiteModerationSessions::$destroyed = array();
		SiteModerationSessions::$remaining = array( 'stored' => array( 'expiration' => 1900000000 ) );
		$retained                          = $this->dispatch(
			array(
				'id'          => self::OTHER,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);

		self::assertInstanceOf( \WP_Error::class, $unconsumable );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $unconsumable->get_error_code() );
		self::assertArrayNotHasKey( self::TARGET, $this->snapshots );
		self::assertSame( array( 'content_editor' ), $live->roles );
		self::assertSame( array(), $live->caps );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
		self::assertSame( $live_tokens, $token );

		self::assertInstanceOf( \WP_Error::class, $retained );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $retained->get_error_code() );
		self::assertSame( $bytes, $this->snapshots[ self::OTHER ] );
		self::assertSame( array(), $stored->roles );
		self::assertSame( array(), $stored->caps );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
		self::assertSame( array( 'stored' => array( 'expiration' => 1900000000 ) ), SiteModerationSessions::$remaining );
	}

	/** A repeated ban repairs the leftover capability from the stored snapshot. */
	public function test_repeated_ban_repairs_from_the_stored_snapshot(): void {
		$target  = $this->add_user( self::TARGET, array( 'banned' ), array( 'edit_posts' => true ) );
		$record  = BanSnapshot::build(
			array( 'author' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			7,
			'policy'
		);
		$created = false;
		self::assertTrue( BanSnapshot::save( self::TARGET, $record, $created ) );
		self::assertTrue( $created );
		$bytes                             = $this->snapshots[ self::TARGET ];
		SiteModerationSessions::$destroyed = array();

		$repaired = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);

		self::assertIsArray( $repaired, $repaired instanceof \WP_Error ? $repaired->message : '' );
		self::assertTrue( $repaired['changed'] );
		self::assertSame( 'banned', $repaired['status'] );
		self::assertSame( array( 'banned' ), $target->roles );
		self::assertSame( array(), $target->caps );
		self::assertSame( array( self::TARGET ), SiteModerationSessions::$destroyed );
		self::assertSame( $bytes, $this->snapshots[ self::TARGET ] );

		$again = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);

		self::assertIsArray( $again, $again instanceof \WP_Error ? $again->message : '' );
		self::assertFalse( $again['changed'] );
		self::assertSame( 'banned', $again['status'] );
		self::assertSame( 'banned', $again['previous_status'] );
		self::assertSame( array( 'banned' ), $again['roles'] );
		self::assertCount( 1, SiteModerationSessions::$destroyed );
		self::assertSame( $bytes, $this->snapshots[ self::TARGET ] );
	}

	/** A successful unban requires the exact direct capability map. */
	public function test_unban_requires_the_exact_direct_capability_map(): void {
		$extra = $this->add_user( self::TARGET, array( 'banned' ), array( 'edit_posts' => true ) );
		$extra->add_cap( 'upload_files', true );
		$extra_record = BanSnapshot::build( array( 'author' ), array( 'edit_posts' => true ), 7, 'spam' );
		$created      = false;
		self::assertTrue( BanSnapshot::save( self::TARGET, $extra_record, $created ) );
		$extra_bytes = $this->snapshots[ self::TARGET ];

		$missing        = $this->add_user( self::OTHER, array( 'banned' ) );
		$missing_record = BanSnapshot::build(
			array( 'author' ),
			array(
				'edit_posts'   => true,
				'upload_files' => true,
			),
			7,
			'spam'
		);
		$created        = false;
		self::assertTrue( BanSnapshot::save( self::OTHER, $missing_record, $created ) );
		$missing_bytes          = $this->snapshots[ self::OTHER ];
		$missing->withheld_caps = array( 'upload_files' => null );

		$differing        = $this->add_user( self::TARGET + 2, array( 'banned' ) );
		$differing_record = BanSnapshot::build(
			array( 'author' ),
			array(
				'edit_posts'         => true,
				'comment_moderation' => false,
			),
			7,
			'spam'
		);
		$created          = false;
		self::assertTrue( BanSnapshot::save( self::TARGET + 2, $differing_record, $created ) );
		$differing_bytes          = $this->snapshots[ self::TARGET + 2 ];
		$differing->withheld_caps = array( 'comment_moderation' => true );

		$extra_result     = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		$missing_result   = $this->dispatch(
			array(
				'id'     => self::OTHER,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		$differing_result = $this->dispatch(
			array(
				'id'     => self::TARGET + 2,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);

		self::assertInstanceOf( \WP_Error::class, $missing_result );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $missing_result->get_error_code() );
		self::assertSame( $missing_bytes, $this->snapshots[ self::OTHER ] );
		self::assertIsArray( BanSnapshot::load( self::OTHER ) );

		self::assertInstanceOf( \WP_Error::class, $differing_result );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $differing_result->get_error_code() );
		self::assertSame( $differing_bytes, $this->snapshots[ self::TARGET + 2 ] );
		self::assertIsArray( BanSnapshot::load( self::TARGET + 2 ) );

		self::assertInstanceOf( \WP_Error::class, $extra_result );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $extra_result->get_error_code() );
		self::assertSame( $extra_bytes, $this->snapshots[ self::TARGET ] );
		self::assertIsArray( BanSnapshot::load( self::TARGET ) );
		self::assertSame( array( 'author' ), $extra->roles );
		self::assertSame(
			array(
				'edit_posts'   => true,
				'upload_files' => true,
			),
			$extra->caps
		);
	}

	/** A validated v1 snapshot restores its recorded role and capabilities. */
	public function test_unban_accepts_a_validated_v1_snapshot(): void {
		$this->add_user( self::TARGET, array( 'banned' ) );
		$snapshot = BanSnapshot::build(
			array( 'editor' ),
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			7,
			'spam'
		);
		$created  = false;
		self::assertTrue( BanSnapshot::save( self::TARGET, $snapshot, $created ) );

		$restored = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->message : '' );
		self::assertSame( 'active', $restored['status'] );
		self::assertSame( array( 'editor' ), $restored['roles'] );
		self::assertSame(
			array(
				'edit_posts'   => true,
				'upload_files' => false,
			),
			$this->users[ self::TARGET ]->caps
		);
		self::assertNull( BanSnapshot::load( self::TARGET ) );
	}

	/** An active target with a retained snapshot is refused and left untouched. */
	public function test_active_target_with_a_retained_snapshot_is_refused(): void {
		$user    = $this->add_user( self::TARGET, array( 'author' ) );
		$site    = BanSnapshot::build_for_site(
			new SiteModerationUser( self::TARGET, array( 'author' ) ),
			$this->context( array( 'ban-users' ) ),
			'spam'
		);
		$created = false;
		self::assertTrue( BanSnapshot::save( self::TARGET, $site, $created ) );
		$stored = $this->snapshots[ self::TARGET ];

		$refused = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertSame( array( 'author' ), $user->roles );
		self::assertSame( $stored, $this->snapshots[ self::TARGET ] );
	}

	/** Corrupt, privileged and empty recovery data are refused without a write. */
	public function test_invalid_recovery_data_is_refused_and_preserved(): void {
		$this->add_user( self::TARGET, array( 'banned' ) );
		$unban_only = $this->context( array( 'unban-users' ) );

		$this->snapshots[ self::TARGET ] = '{broken';
		$corrupt                         = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $corrupt );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $corrupt->get_error_code() );
		self::assertSame( '{broken', $this->snapshots[ self::TARGET ] );
		self::assertSame( array( 'banned' ), $this->users[ self::TARGET ]->roles );

		$privileged                      = BanSnapshot::build_for_user( array( 'administrator' ), array(), 7, 'spam' );
		$this->snapshots[ self::TARGET ] = (string) wp_json_encode( $privileged );
		$staleprivileged                 = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $staleprivileged );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $staleprivileged->get_error_code() );
		self::assertSame( (string) wp_json_encode( $privileged ), $this->snapshots[ self::TARGET ] );
		self::assertSame( array( 'banned' ), $this->users[ self::TARGET ]->roles );

		$empty                           = BanSnapshot::build( array(), array(), 7, 'spam' );
		$this->snapshots[ self::TARGET ] = (string) wp_json_encode( $empty );
		$emptysnapshot                   = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $emptysnapshot );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $emptysnapshot->get_error_code() );
		self::assertSame( (string) wp_json_encode( $empty ), $this->snapshots[ self::TARGET ] );
	}

	/** Membership, protected targets and a denied filter leave no side effect. */
	public function test_target_refusals_leave_no_persisted_change(): void {
		$this->members[ self::TARGET ] = false;
		$this->add_user( self::TARGET, array( 'author' ) );
		$ban_only = $this->context( array( 'ban-users' ) );

		$non_member = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $non_member );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $non_member->get_error_code() );

		$this->members[ self::TARGET ] = true;
		$this->add_user( self::OTHER, array( 'administrator' ) );
		$administrator = $this->dispatch(
			array(
				'id'     => self::OTHER,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $administrator );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $administrator->get_error_code() );

		$this->user_allowed = false;
		$denied             = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $denied->get_error_code() );

		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertSame( array( 'administrator' ), $this->users[ self::OTHER ]->roles );
		self::assertSame( array(), $this->snapshots );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
	}

	/** A late denial clears only the snapshot this call created; a veto keeps recovery. */
	public function test_late_denials_keep_or_clear_only_their_own_snapshot(): void {
		$this->add_user( self::TARGET, array( 'author' ) );
		$this->user_allowed = static fn( int $call ): bool => $call < 2;

		$refused = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'spam',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $refused->get_error_code() );
		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertNull( BanSnapshot::load( self::TARGET ) );
		self::assertSame( array(), SiteModerationSessions::$destroyed );

		$this->user_allowed = true;
		$banned             = $this->dispatch(
			array(
				'id'          => self::TARGET,
				'status'      => 'banned',
				'reason_code' => 'spam',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->message : '' );
		$snapshot = $this->snapshots[ self::TARGET ];
		self::assertNotSame( '', $snapshot );

		$this->filter_calls = 0;
		$this->user_allowed = static fn( int $call ): bool => $call < 2;
		$vetoed             = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $vetoed );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $vetoed->get_error_code() );
		self::assertSame( array( 'banned' ), $this->users[ self::TARGET ]->roles );
		self::assertSame( $snapshot, $this->snapshots[ self::TARGET ] );
	}

	/** A wrong-blog request and a disabled Admin tier never reach the handler. */
	public function test_wrong_blog_and_disabled_admin_tier_are_unavailable(): void {
		$this->add_user( self::TARGET, array( 'author' ) );
		$this->blog_id = self::BLOG + 1;

		$wrong_blog = $this->dispatcher->execute(
			'xnetvn/set-user-status',
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ), self::BLOG )
		);
		self::assertInstanceOf( \WP_Error::class, $wrong_blog );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $wrong_blog->get_error_code() );

		$this->blog_id = self::BLOG;
		$this->settings->update( array( 'allow_admin_tier' => false ) );
		$admin_off = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $admin_off );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $admin_off->get_error_code() );

		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertSame( array(), $this->snapshots );
		self::assertSame( array(), SiteModerationSessions::$destroyed );
	}

	/** The moderation names run inside the existing site budget identity. */
	public function test_the_expensive_budget_uses_the_site_identity(): void {
		$this->add_user( self::TARGET, array( 'author' ) );
		$ban_only             = $this->context( array( 'ban-users' ) );
		$this->budget->refuse = true;

		$refused = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $refused->get_error_code() );
		self::assertSame( array( 'site:' . self::BLOG . ':' . self::KEY_ID ), $this->budget->identities );
		self::assertSame( array( 'author' ), $this->users[ self::TARGET ]->roles );
		self::assertSame( array(), $this->snapshots );
		self::assertSame( array(), SiteModerationSessions::$destroyed );

		$this->budget->refuse = false;
		$banned               = $this->dispatch(
			array(
				'id'     => self::TARGET,
				'status' => 'banned',
			),
			$ban_only
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->message : '' );
		self::assertSame(
			array(
				'site:' . self::BLOG . ':' . self::KEY_ID,
				'site:' . self::BLOG . ':' . self::KEY_ID,
			),
			$this->budget->identities
		);
	}

	/** Registration needs the populated catalog and stays discoverable afterwards. */
	public function test_registration_requires_the_populated_catalog(): void {
		self::assertTrue( class_exists( SiteUserModeration::class ), 'The machine moderation handler class must exist.' );

		$policy     = new SitePolicy( $this->settings );
		$output     = new OutputPipeline( $this->settings );
		$dispatcher = new SiteDispatcher( $policy, $output );
		$registry   = new Registry( new Policy( $this->settings ), new RequestScope(), $output, null, new SafeMcpEnvelope(), $policy, $dispatcher );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), null ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?AbilityDescriptor => $registry->resolved_descriptor( $name ) );

		try {
			$dispatcher->register( 'xnetvn/set-user-status', SiteUserModeration::handler() );
			self::fail( 'Registration before the catalog is populated must throw.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		self::assertFalse( $dispatcher->has_handler( 'xnetvn/set-user-status' ) );

		$registry->load_packs();
		$dispatcher->register( 'xnetvn/set-user-status', SiteUserModeration::handler() );
		self::assertTrue( $dispatcher->has_handler( 'xnetvn/set-user-status' ) );

		try {
			$dispatcher->register( 'xnetvn/set-user-status', SiteUserModeration::handler() );
			self::fail( 'A duplicate registration must throw.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		self::assertSame(
			array( 'xnetvn/set-user-status' ),
			$registry->names_for_site_scope( $this->scope( array( 'ban-users' ) ) )
		);
	}

	/**
	 * Register the moderation handler after packs load.
	 *
	 * @return SiteDispatcher
	 */
	private function dispatcher(): SiteDispatcher {
		$policy     = new SitePolicy( $this->settings );
		$output     = new OutputPipeline( $this->settings );
		$dispatcher = new SiteDispatcher( $policy, $output, $this->budget );
		$registry   = new Registry( new Policy( $this->settings ), new RequestScope(), $output, $this->budget, new SafeMcpEnvelope(), $policy, $dispatcher );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), null ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		$registry->load_packs();
		if ( class_exists( SiteUserModeration::class ) ) {
			$dispatcher->register( 'xnetvn/set-user-status', SiteUserModeration::handler() );
		}
		return $dispatcher;
	}

	/**
	 * Dispatch one moderation request through the machine boundary.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Machine identity.
	 * @return mixed
	 */
	private function dispatch( array $arguments, SiteAuthContext $context ): mixed {
		return $this->dispatcher->execute( 'xnetvn/set-user-status', $arguments, $context );
	}

	/**
	 * Add one user row.
	 *
	 * @param int                $user_id Identity.
	 * @param array<string>      $roles Role slugs.
	 * @param array<string,bool> $caps Direct capabilities.
	 * @return SiteModerationUser
	 */
	private function add_user( int $user_id, array $roles, array $caps = array() ): SiteModerationUser {
		$user                    = new SiteModerationUser( $user_id, $roles, $caps );
		$this->users[ $user_id ] = $user;
		return $user;
	}

	/**
	 * Build a moderation scope.
	 *
	 * @param array<string> $permissions Directional grants.
	 * @return SiteScope
	 */
	private function scope( array $permissions ): SiteScope {
		return new SiteScope(
			RiskTier::Admin,
			array( 'users' ),
			array( 'xnetvn/set-user-status' ),
			array(),
			$permissions,
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
	}

	/**
	 * Build one verified machine identity.
	 *
	 * @param array<string> $permissions Directional grants.
	 * @param int|null      $blog_id Optional conflicting site identity.
	 * @return SiteAuthContext
	 */
	private function context( array $permissions, ?int $blog_id = null ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( self::KEY_ID ), $blog_id ?? $this->blog_id, 'Moderation fixture', $this->scope( $permissions ), new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, 'moderation-request-id', '203.0.113.7' );
	}

	/** Install the fixed WordPress substitutes. */
	private function wordpress(): void {
		Functions\when( 'get_current_blog_id' )->alias( fn(): int => $this->blog_id );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'rest_do_request' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( '__' )->returnArg();
		Functions\when( 'clean_user_cache' )->justReturn( null );
		Functions\when( 'get_userdata' )->alias( fn( int $user_id ): mixed => $this->users[ $user_id ] ?? false );
		Functions\when( 'is_user_member_of_blog' )->alias(
			function ( int $user_id, int $blog_id = 0 ): bool {
				unset( $blog_id );
				return $this->members[ $user_id ] ?? true;
			}
		);
		Functions\when( 'wp_roles' )->alias( fn(): SiteModerationRoles => $this->registry_roles );
		Functions\when( 'get_editable_roles' )->alias(
			static fn(): array => array(
				'author'     => array( 'name' => 'Author' ),
				'editor'     => array( 'name' => 'Editor' ),
				'subscriber' => array( 'name' => 'Subscriber' ),
			)
		);
		Functions\when( 'add_role' )->alias(
			function ( string $role, string $name, array $caps = array() ): object {
				$this->registry_roles->roles[ $role ] = array(
					'name'         => $name,
					'capabilities' => $caps,
				);
				return (object) $this->registry_roles->roles[ $role ];
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $value, int $flags = 0 ): string|false => json_encode( $value, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		Functions\when( 'apply_filters' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Fixed WordPress hook signature.
			function ( string $tag, mixed $value, mixed ...$arguments ): mixed {
				if ( 'xnetvn_agent_hub_wp_user_allowed' === $tag ) {
					++$this->filter_calls;
					if ( $this->user_allowed instanceof Closure ) {
						return ( $this->user_allowed )( $this->filter_calls );
					}
					return $this->user_allowed;
				}
				return $value;
			}
		);
		Functions\when( 'get_user_option' )->alias( fn( string $option, int $user_id ): mixed => $this->snapshots[ $user_id ] ?? false );
		Functions\when( 'add_user_meta' )->alias(
			function ( int $user_id, string $meta_key, mixed $meta_value, bool $unique = false ): bool {
				unset( $meta_key );
				if ( $unique && isset( $this->snapshots[ $user_id ] ) ) {
					return false;
				}
				$this->snapshots[ $user_id ] = (string) $meta_value;
				return true;
			}
		);
		Functions\when( 'delete_user_option' )->alias(
			function ( int $user_id, string $option, bool $network_wide = false ): bool {
				unset( $option, $network_wide );
				if ( $this->refuse_delete ) {
					return false;
				}
				unset( $this->snapshots[ $user_id ] );
				return true;
			}
		);
		Functions\when( 'rest_validate_value_from_schema' )->alias(
			static function ( mixed $value, array $schema ): mixed {
				$validator = new \Opis\JsonSchema\Validator();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
				$document = json_decode( (string) json_encode( $value ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
				$contract = json_decode( (string) json_encode( $schema ) );
				return $validator->validate( $document, $contract )->isValid() ? true : 'Invalid parameter.';
			}
		);
	}
}
