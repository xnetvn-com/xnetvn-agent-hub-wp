<?php
/**
 * Machine moderation against real roles, capabilities, sessions and tables.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Isolated session and budget doubles must live beside the test.

use Closure;
use DateTimeImmutable;
use WP_UnitTestCase;
use WP_User;
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
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;

/** Counts the real session sweeps without changing their effect. */
final class SiteUserModerationCountingSessions extends \WP_User_Meta_Session_Tokens {
	/** Recorded sweeps.
	 *
	 * @var int
	 */
	public static int $destroys = 0;

	/**
	 * Count an empty replacement, which only destroy_all() produces.
	 *
	 * @param array<string,mixed> $sessions Session rows.
	 * @return bool|int
	 */
	protected function update_sessions( $sessions ) {
		if ( array() === $sessions ) {
			++self::$destroys;
		}
		return parent::update_sessions( $sessions );
	}
}

/** Fails the session sweep after the role write. */
final class SiteUserModerationFailingSessions extends \WP_User_Meta_Session_Tokens {
	/**
	 * Refuse the sweep without touching stored sessions.
	 *
	 * @param array<string,mixed> $sessions Session rows.
	 * @return void
	 * @throws \RuntimeException Always, to simulate a failed session store.
	 */
	protected function update_sessions( $sessions ) {
		throw new \RuntimeException( 'Fixture session store failure.' );
	}
}

/** Records the site budget identity and can refuse the work. */
final class SiteUserModerationIntegrationBudget implements ExpensiveBudget {
	/** Recorded identities.
	 *
	 * @var array<string>
	 */
	public array $identities = array();
	/** Whether every request is refused.
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

/** Uses the real registry, roles, user options and network tables. */
final class SiteUserModerationTest extends WP_UnitTestCase {
	/** Operation under test. */
	private const NAME = 'xnetvn/set-user-status';
	/** Public key identity. */
	private const KEY_ID = 'abcdefghijkl';

	/** Fixture users removed after the test.
	 *
	 * @var array<int>
	 */
	private array $fixtures = array();

	/** Site policy.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;

	/** Keep the machine principal accountless and enable the reviewed tier. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		$this->settings = new WpSiteSettings();
		$this->settings->update(
			array(
				'enabled'          => true,
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
			)
		);
		SiteUserModerationCountingSessions::$destroys = 0;
	}

	/** Remove filters a test added, delete its fixtures and restore the accountless principal. */
	public function tear_down(): void {
		remove_all_filters( 'session_token_manager' );
		remove_all_filters( 'xnetvn_agent_hub_wp_user_allowed' );
		remove_all_filters( 'delete_user_metadata' );
		foreach ( $this->fixtures as $fixture ) {
			wp_delete_user( $fixture );
		}
		$this->fixtures = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Registration needs the populated catalog and stays discoverable afterwards. */
	public function test_registration_requires_a_populated_catalog(): void {
		self::assertTrue( class_exists( SiteUserModeration::class ), 'The machine moderation handler class must exist.' );
		[ $registry, $dispatcher ] = $this->build( null, false );

		try {
			$dispatcher->register( self::NAME, SiteUserModeration::handler() );
			self::fail( 'Registration before the catalog is populated must throw.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		self::assertFalse( $dispatcher->has_handler( self::NAME ) );

		$registry->load_packs();
		$dispatcher->register( self::NAME, SiteUserModeration::handler() );
		self::assertTrue( $dispatcher->has_handler( self::NAME ) );

		try {
			$dispatcher->register( self::NAME, SiteUserModeration::handler() );
			self::fail( 'A duplicate registration must throw.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		self::assertSame( array( self::NAME ), $registry->names_for_site_scope( $this->scope( array( 'ban-users' ) ) ) );
		// Discovery follows the key's declared ability allowlist; the directional grants narrow execution, never listing.
		self::assertContains( self::NAME, $registry->names_for_site_scope( $this->scope( array() ) ) );
	}

	/** A roleless member is refused before the ban writes anything. */
	public function test_ban_refuses_a_target_with_no_captured_roles_without_writing(): void {
		$target = self::factory()->user->create();
		$user   = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $user );
		$user->set_role( '' );
		$user->add_cap( 'read', true );
		$session = \WP_Session_Tokens::get_instance( $target )->create( time() + 3600 );
		self::assertNotEmpty( $session );
		$roles_before  = array_values( (array) get_userdata( $target )->roles );
		$caps_before   = (array) get_userdata( $target )->caps;
		$tokens_before = \WP_Session_Tokens::get_instance( $target )->get_all();
		self::assertNotSame( array(), $tokens_before );
		add_filter(
			'session_token_manager',
			static fn(): string => SiteUserModerationCountingSessions::class
		);
		[ , $dispatcher ] = $this->build();

		$refused = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$this->context( array( 'ban-users' ) )
		);

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertNull( BanSnapshot::load( $target ) );
		self::assertSame( $roles_before, array_values( (array) get_userdata( $target )->roles ) );
		self::assertSame( $caps_before, (array) get_userdata( $target )->caps );
		self::assertSame( $tokens_before, \WP_Session_Tokens::get_instance( $target )->get_all() );
		self::assertSame( 0, SiteUserModerationCountingSessions::$destroys );
		$this->fixtures[] = $target;
	}

	/** A repeated ban repairs from the stored snapshot and stays idempotent. */
	public function test_repeated_ban_repairs_from_the_stored_snapshot(): void {
		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		$user   = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'edit_posts', true );
		$snapshot = BanSnapshot::build_for_site( $user, $this->context( array( 'ban-users' ) ), 'policy' );
		$created  = false;
		self::assertTrue( BanSnapshot::save( $target, $snapshot, $created ) );
		self::assertTrue( $created );
		$user->set_role( 'banned' );
		$user->add_cap( 'edit_posts', true );
		$session = \WP_Session_Tokens::get_instance( $target )->create( time() + 3600 );
		self::assertNotEmpty( $session );
		$bytes = get_user_option( BanSnapshot::KEY, $target );
		self::assertIsString( $bytes );
		add_filter(
			'session_token_manager',
			static fn(): string => SiteUserModerationCountingSessions::class
		);
		[ , $dispatcher ] = $this->build();
		$ban_only         = $this->context( array( 'ban-users' ) );

		$repaired = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$ban_only
		);

		self::assertIsArray( $repaired, $repaired instanceof \WP_Error ? $repaired->get_error_message() : '' );
		self::assertTrue( $repaired['changed'] );
		self::assertSame( array( 'banned' ), $repaired['roles'] );
		self::assertSame( $bytes, get_user_option( BanSnapshot::KEY, $target ) );
		self::assertArrayNotHasKey( 'edit_posts', (array) get_userdata( $target )->caps );
		self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );
		self::assertSame( 1, SiteUserModerationCountingSessions::$destroys );

		$again = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'policy',
			),
			$ban_only
		);

		self::assertIsArray( $again, $again instanceof \WP_Error ? $again->get_error_message() : '' );
		self::assertFalse( $again['changed'] );
		self::assertSame( 'banned', $again['previous_status'] );
		self::assertSame( $bytes, get_user_option( BanSnapshot::KEY, $target ) );
		self::assertSame( 1, SiteUserModerationCountingSessions::$destroys );

		$restored = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);

		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->get_error_message() : '' );
		self::assertSame( array( 'author' ), $restored['roles'] );
		self::assertTrue( (bool) get_userdata( $target )->caps['edit_posts'] );
		self::assertNull( BanSnapshot::load( $target ) );
		$this->fixtures[] = $target;
	}

	/** A capability map that is not exact blocks the clean unban. */
	public function test_unban_requires_the_exact_direct_capability_map(): void {
		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		$user   = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'edit_posts', true );
		$snapshot = BanSnapshot::build_for_site( $user, $this->context( array( 'ban-users' ) ), 'spam' );
		$created  = false;
		self::assertTrue( BanSnapshot::save( $target, $snapshot, $created ) );
		self::assertTrue( $created );
		$user->set_role( 'banned' );
		$user->add_cap( 'upload_files', true );
		$bytes = get_user_option( BanSnapshot::KEY, $target );
		self::assertIsString( $bytes );
		[ , $dispatcher ] = $this->build();

		$retained = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);

		self::assertInstanceOf( \WP_Error::class, $retained );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $retained->get_error_code() );
		self::assertSame( $bytes, get_user_option( BanSnapshot::KEY, $target ) );
		self::assertIsArray( BanSnapshot::load( $target ) );
		self::assertTrue( (bool) get_userdata( $target )->caps['upload_files'] );
		$this->fixtures[] = $target;
	}

	/** Ban and unban restore roles, caps, content and the single recovery point. */
	public function test_machine_ban_and_unban_lifecycle(): void {
		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		$user   = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'upload_files', true );
		$user->add_cap( 'site_fixture_blocked', false );
		$post    = self::factory()->post->create( array( 'post_author' => $target ) );
		$comment = self::factory()->comment->create(
			array(
				'user_id'         => $target,
				'comment_post_ID' => $post,
			)
		);
		$session = \WP_Session_Tokens::get_instance( $target )->create( time() + 3600 );
		self::assertNotEmpty( $session );
		self::assertNotSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );

		add_filter(
			'session_token_manager',
			static fn(): string => SiteUserModerationCountingSessions::class
		);
		[ , $dispatcher ] = $this->build();
		$both             = $this->context( array( 'ban-users', 'unban-users' ) );

		$banned = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'security',
			),
			$both
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->get_error_message() : '' );
		self::assertSame( array( 'id', 'status', 'previous_status', 'changed', 'roles' ), array_keys( $banned ) );
		self::assertSame( $target, $banned['id'] );
		self::assertSame( 'banned', $banned['status'] );
		self::assertSame( 'active', $banned['previous_status'] );
		self::assertTrue( $banned['changed'] );
		self::assertSame( array( 'banned' ), $banned['roles'] );
		self::assertSame( 0, get_current_user_id() );
		self::assertSame( 1, SiteUserModerationCountingSessions::$destroys );
		self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );

		$banned_role = get_role( 'banned' );
		self::assertNotNull( $banned_role );
		self::assertSame( array(), array_filter( (array) $banned_role->capabilities ) );
		$fresh = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $fresh );
		self::assertSame( array( 'banned' ), array_values( (array) $fresh->roles ) );
		self::assertFalse( user_can( $target, 'edit_posts' ) );
		self::assertFalse( user_can( $target, 'upload_files' ) );

		$snapshot = BanSnapshot::load( $target );
		self::assertIsArray( $snapshot );
		self::assertSame( 2, $snapshot['v'] );
		self::assertSame( 'site', $snapshot['actor']['type'] );
		self::assertSame( get_current_blog_id(), $snapshot['actor']['blog_id'] );
		self::assertSame( self::KEY_ID, $snapshot['actor']['key_id'] );
		self::assertSame( array( 'author' ), $snapshot['roles'] );
		self::assertSame(
			array(
				'upload_files'         => true,
				'site_fixture_blocked' => false,
			),
			$snapshot['caps']
		);
		self::assertSame( 'security', $snapshot['reason'] );
		self::assertArrayNotHasKey( 'by', $snapshot );

		self::assertSame( $post, get_post( $post )->ID );
		self::assertSame( $target, (int) get_post( $post )->post_author );
		self::assertSame( $comment, (int) get_comment( $comment )->comment_ID );
		self::assertSame( $target, (int) get_comment( $comment )->user_id );

		$again = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'banned',
			),
			$both
		);
		self::assertIsArray( $again, $again instanceof \WP_Error ? $again->get_error_message() : '' );
		self::assertFalse( $again['changed'] );
		self::assertSame( $snapshot, BanSnapshot::load( $target ) );
		self::assertSame( 1, SiteUserModerationCountingSessions::$destroys );

		$restored = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$both
		);
		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->get_error_message() : '' );
		self::assertSame( 'active', $restored['status'] );
		self::assertSame( 'banned', $restored['previous_status'] );
		self::assertTrue( $restored['changed'] );
		self::assertSame( array( 'author' ), $restored['roles'] );
		self::assertNull( BanSnapshot::load( $target ) );
		$after = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $after );
		self::assertSame( array( 'author' ), array_values( (array) $after->roles ) );
		self::assertTrue( user_can( $target, 'upload_files' ) );
		self::assertArrayHasKey( 'site_fixture_blocked', (array) $after->caps );
		self::assertFalse( (bool) $after->caps['site_fixture_blocked'] );

		$repeated = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$both
		);
		self::assertIsArray( $repeated, $repeated instanceof \WP_Error ? $repeated->get_error_message() : '' );
		self::assertFalse( $repeated['changed'] );
		self::assertSame( 1, SiteUserModerationCountingSessions::$destroys );
	}

	/** Pre-write refusals leave roles, caps, snapshots and sessions untouched. */
	public function test_refusals_leave_no_persisted_change(): void {
		$target           = self::factory()->user->create( array( 'role' => 'author' ) );
		[ , $dispatcher ] = $this->build();

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$protected     = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $administrator,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $protected );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $protected->get_error_code() );

		$wrong_blog = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ), get_current_blog_id() + 1 )
		);
		self::assertInstanceOf( \WP_Error::class, $wrong_blog );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $wrong_blog->get_error_code() );

		$this->settings->update( array( 'allow_admin_tier' => false ) );
		try {
			$admin_off = $dispatcher->execute(
				self::NAME,
				array(
					'id'     => $target,
					'status' => 'banned',
				),
				$this->context( array( 'ban-users' ) )
			);
			self::assertInstanceOf( \WP_Error::class, $admin_off );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $admin_off->get_error_code() );
		} finally {
			$this->settings->update( array( 'allow_admin_tier' => true ) );
		}

		$no_grant = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'banned',
			),
			$this->context( array() )
		);
		self::assertInstanceOf( \WP_Error::class, $no_grant );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $no_grant->get_error_code() );

		$forbidden = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $forbidden );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $forbidden->get_error_code() );

		$fresh = get_userdata( $target );
		self::assertInstanceOf( WP_User::class, $fresh );
		self::assertSame( array( 'author' ), array_values( (array) $fresh->roles ) );
		self::assertSame( array( 'administrator' ), array_values( (array) get_userdata( $administrator )->roles ) );
		self::assertNull( BanSnapshot::load( $target ) );
		self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );
	}

	/** Late denials clear only their own snapshot and never a recovery point. */
	public function test_late_denials_clear_or_keep_the_snapshot(): void {
		$target           = self::factory()->user->create( array( 'role' => 'author' ) );
		[ , $dispatcher ] = $this->build();
		$calls            = 0;
		add_filter(
			'xnetvn_agent_hub_wp_user_allowed',
			static function ( bool $allowed ) use ( &$calls ): bool {
				++$calls;
				return $calls < 2 && $allowed;
			}
		);

		$refused = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'spam',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $refused->get_error_code() );
		self::assertSame( array( 'author' ), array_values( (array) get_userdata( $target )->roles ) );
		self::assertNull( BanSnapshot::load( $target ) );
		self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );

		remove_all_filters( 'xnetvn_agent_hub_wp_user_allowed' );
		$banned = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'spam',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->get_error_message() : '' );
		$snapshot = BanSnapshot::load( $target );
		self::assertIsArray( $snapshot );

		$calls = 0;
		add_filter(
			'xnetvn_agent_hub_wp_user_allowed',
			static function ( bool $allowed ) use ( &$calls ): bool {
				++$calls;
				return $calls < 2 && $allowed;
			}
		);
		$vetoed = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $vetoed );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $vetoed->get_error_code() );
		self::assertSame( array( 'banned' ), array_values( (array) get_userdata( $target )->roles ) );
		self::assertSame( $snapshot, BanSnapshot::load( $target ) );
	}

	/** A persisted sub-step failure keeps the snapshot and reports the target. */
	public function test_partial_failures_keep_the_recovery_point(): void {
		$target           = self::factory()->user->create( array( 'role' => 'author' ) );
		[ , $dispatcher ] = $this->build();
		$ban_only         = $this->context( array( 'ban-users' ) );

		add_filter(
			'session_token_manager',
			static fn(): string => SiteUserModerationFailingSessions::class
		);
		$failed = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'spam',
			),
			$ban_only
		);
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $failed->get_error_code() );
		self::assertSame( $target, $failed->get_error_data()['affected_id'] );
		self::assertIsArray( BanSnapshot::load( $target ) );
		self::assertSame( array( 'banned' ), array_values( (array) get_userdata( $target )->roles ) );

		remove_all_filters( 'session_token_manager' );
		$refuse_clear = static function ( mixed $check, int $object_id, string $meta_key ) use ( $target ): mixed {
			return $object_id === $target && str_ends_with( $meta_key, BanSnapshot::KEY ) ? false : $check;
		};
		add_filter( 'delete_user_metadata', $refuse_clear, 10, 3 );
		try {
			$retained = $dispatcher->execute(
				self::NAME,
				array(
					'id'     => $target,
					'status' => 'active',
				),
				$this->context( array( 'unban-users' ) )
			);
			self::assertInstanceOf( \WP_Error::class, $retained );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $retained->get_error_code() );
			self::assertSame( $target, $retained->get_error_data()['affected_id'] );
			self::assertIsArray( BanSnapshot::load( $target ) );
			self::assertSame( array( 'author' ), array_values( (array) get_userdata( $target )->roles ) );
		} finally {
			remove_filter( 'delete_user_metadata', $refuse_clear, 10 );
		}

		$cleared = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $cleared );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $cleared->get_error_code() );
		self::assertIsArray( BanSnapshot::load( $target ) );
	}

	/** Corrupt, privileged and empty recovery data are refused and preserved. */
	public function test_invalid_recovery_data_is_refused(): void {
		// Roles are a per-site option, so reload the registry before seeding: a stale
		// in-memory WP_Roles object would hide the missing option and get_role_caps()
		// would then filter the stored role out of WP_User::$roles.
		unset( $GLOBALS['wp_roles'] );
		if ( null === get_role( 'banned' ) ) {
			add_role( 'banned', 'Banned', array() );
		}
		self::assertInstanceOf( \WP_Role::class, get_role( 'banned' ) );
		$target = self::factory()->user->create();
		$seeded = new \WP_User( $target );
		$seeded->set_role( 'banned' );
		clean_user_cache( $target );
		[ , $dispatcher ] = $this->build();
		$unban_only       = $this->context( array( 'unban-users' ) );

		$privileged = BanSnapshot::build_for_user( array( 'administrator' ), array(), 7, 'spam' );
		$encoded    = (string) wp_json_encode( $privileged );
		update_user_option( $target, BanSnapshot::KEY, $encoded, false );
		$refused = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertSame( $encoded, (string) get_user_option( BanSnapshot::KEY, $target ) );
		clean_user_cache( $target );
		self::assertSame( array( 'banned' ), array_values( (array) get_userdata( $target )->roles ) );

		$empty   = BanSnapshot::build( array(), array(), 7, 'spam' );
		$encoded = (string) wp_json_encode( $empty );
		update_user_option( $target, BanSnapshot::KEY, $encoded, false );
		$refused = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$unban_only
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertSame( $encoded, (string) get_user_option( BanSnapshot::KEY, $target ) );
	}

	/** The existing site budget refuses before the handler and changes nothing. */
	public function test_budget_refusal_happens_before_the_handler(): void {
		$target           = self::factory()->user->create( array( 'role' => 'author' ) );
		$budget           = new SiteUserModerationIntegrationBudget();
		[ , $dispatcher ] = $this->build( $budget );
		$budget->refuse   = true;

		$refused = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $refused->get_error_code() );
		self::assertSame( array( 'site:' . get_current_blog_id() . ':' . self::KEY_ID ), $budget->identities );
		self::assertSame( array( 'author' ), array_values( (array) get_userdata( $target )->roles ) );
		self::assertNull( BanSnapshot::load( $target ) );
	}

	/**
	 * Cross-site administrators, non-members and a network-wide session sweep.
	 *
	 * @group ms-required
	 */
	public function test_multisite_protection_and_network_session_sweep(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A multisite harness is required.' );
		}
		$initial          = get_current_blog_id();
		$blog             = self::factory()->blog->create();
		[ , $dispatcher ] = $this->build();
		self::assertSame( $initial, get_current_blog_id() );

		$cross_site = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( $blog, $cross_site, 'administrator' );
		$protected = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $cross_site,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $protected );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $protected->get_error_code() );
		self::assertSame( $initial, get_current_blog_id() );
		self::assertNull( BanSnapshot::load( $cross_site ) );

		$non_member = self::factory()->user->create( array( 'role' => 'author' ) );
		remove_user_from_blog( $non_member, $initial );
		$not_member = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $non_member,
				'status' => 'banned',
			),
			$this->context( array( 'ban-users' ) )
		);
		self::assertInstanceOf( \WP_Error::class, $not_member );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $not_member->get_error_code() );

		$target = self::factory()->user->create( array( 'role' => 'author' ) );
		add_user_to_blog( $blog, $target, 'subscriber' );
		switch_to_blog( $blog );
		try {
			$token = \WP_Session_Tokens::get_instance( $target )->create( time() + 3600 );
			self::assertNotEmpty( $token );
			self::assertNotSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );
		} finally {
			restore_current_blog();
		}
		self::assertSame( $initial, get_current_blog_id() );
		self::assertNotSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );

		$banned = $dispatcher->execute(
			self::NAME,
			array(
				'id'          => $target,
				'status'      => 'banned',
				'reason_code' => 'security',
			),
			$this->context( array( 'ban-users', 'unban-users' ) )
		);
		self::assertIsArray( $banned, $banned instanceof \WP_Error ? $banned->get_error_message() : '' );
		self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );
		switch_to_blog( $blog );
		try {
			self::assertSame( array(), \WP_Session_Tokens::get_instance( $target )->get_all() );
			self::assertSame( array( 'subscriber' ), array_values( (array) get_userdata( $target )->roles ) );
		} finally {
			restore_current_blog();
		}
		self::assertSame( array( 'banned' ), array_values( (array) get_userdata( $target )->roles ) );
		self::assertSame( $initial, get_current_blog_id() );

		$restored = $dispatcher->execute(
			self::NAME,
			array(
				'id'     => $target,
				'status' => 'active',
			),
			$this->context( array( 'unban-users' ) )
		);
		self::assertIsArray( $restored, $restored instanceof \WP_Error ? $restored->get_error_message() : '' );
		self::assertSame( array( 'author' ), $restored['roles'] );
		self::assertNull( BanSnapshot::load( $target ) );
	}

	/**
	 * Build the authoritative registry and dispatcher with the real users pack.
	 *
	 * @param ExpensiveBudget|null $budget Optional budget.
	 * @param bool                 $load Whether to populate the catalog before registering.
	 * @return array{Registry,SiteDispatcher}
	 */
	private function build( ?ExpensiveBudget $budget = null, bool $load = true ): array {
		$policy     = new SitePolicy( $this->settings );
		$output     = new OutputPipeline( $this->settings );
		$dispatcher = new SiteDispatcher( $policy, $output, $budget );
		$registry   = new Registry( new Policy( $this->settings ), new RequestScope(), $output, $budget, new SafeMcpEnvelope(), $policy, $dispatcher );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), null ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		if ( $load ) {
			$registry->load_packs();
			if ( class_exists( SiteUserModeration::class ) ) {
				$dispatcher->register( self::NAME, SiteUserModeration::handler() );
			}
		}
		return array( $registry, $dispatcher );
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
			array( self::NAME ),
			array(),
			$permissions,
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
	}

	/**
	 * Build one verified machine identity for the bound site.
	 *
	 * @param array<string> $permissions Directional grants.
	 * @param int|null      $blog_id Optional conflicting site identity.
	 * @return SiteAuthContext
	 */
	private function context( array $permissions, ?int $blog_id = null ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( self::KEY_ID ), $blog_id ?? get_current_blog_id(), 'Moderation fixture', $this->scope( $permissions ), new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, 'moderation-request-id', '203.0.113.7' );
	}
}
