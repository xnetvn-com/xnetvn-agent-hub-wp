<?php
/**
 * Users pack permissions, protection and lifecycle.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Uses real core users, roles and the users controller. */
final class UsersPackTest extends WP_UnitTestCase {
	/**
	 * Tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();
	/**
	 * Settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;

	/** Real core server and an administrator actor. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$this->settings = new WpSiteSettings();
		foreach ( ( new UsersPack( new DefinitionFactory(), null ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool;
		}
	}

	/**
	 * Execute a schema-valid request through final output checks.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		$result = $tool->execute( $args );
		return ( new OutputPipeline( $this->settings ) )->process( $result, $tool->output_schema() );
	}

	/** Twelve strict contracts. */
	public function test_contract(): void {
		self::assertCount( 12, $this->tools );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( new UsersPack( new DefinitionFactory(), null ) );
	}

	/** Default list hides sensitive fields. */
	public function test_list_hides_sensitive_by_default(): void {
		$res = $this->call( 'list-users', array() );
		self::assertIsArray( $res );
		self::assertArrayHasKey( 'items', $res );
		self::assertNotEmpty( $res['items'] );
		$first = $res['items'][0];
		self::assertArrayHasKey( 'protected', $first );
		self::assertArrayNotHasKey( 'email', $first );
		self::assertArrayNotHasKey( 'username', $first );
	}

	/** Unknown users are not found. */
	public function test_get_unknown_is_not_found(): void {
		$res = $this->call( 'get-user', array( 'id' => 999999 ) );
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $res->get_error_code() );
	}

	/** Roles carry privilege flags. */
	public function test_list_roles_flags(): void {
		$res = $this->call( 'list-roles', array() );
		self::assertIsArray( $res );
		$by_slug = array_column( $res['items'], null, 'slug' );
		self::assertTrue( $by_slug['administrator']['privileged'] );
		self::assertFalse( $by_slug['administrator']['assignable'] );
		self::assertFalse( $by_slug['editor']['privileged'] );
		self::assertTrue( $by_slug['editor']['assignable'] );
	}

	/** My profile is readable. */
	public function test_my_profile_readable(): void {
		$res = $this->call( 'get-my-profile', array() );
		self::assertIsArray( $res );
		self::assertSame( get_current_user_id(), $res['id'] );
	}

	/** My profile rejects email. */
	public function test_my_profile_rejects_email_schema(): void {
		$res = $this->call( 'update-my-profile', array( 'email' => 'x@example.test' ) );
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $res->get_error_code() );
	}

	/** My profile updates display name. */
	public function test_my_profile_updates_name(): void {
		$res = $this->call( 'update-my-profile', array( 'name' => 'Admin Updated' ) );
		self::assertIsArray( $res );
		self::assertContains( 'name', $res['updated_fields'] );
	}

	/** Creating administrators is refused. */
	public function test_create_administrator_is_refused(): void {
		$res = $this->call(
			'create-user',
			array(
				'username' => 'uadmin' . wp_rand(),
				'email'    => 'uadmin' . wp_rand() . '@example.test',
				'role'     => 'administrator',
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_role_unavailable', $res->get_error_code() );
	}

	/** Creating an author works without leaking secrets. */
	public function test_create_author_lifecycle(): void {
		$username = 'mautran' . wp_rand();
		$res      = $this->call(
			'create-user',
			array(
				'username' => $username,
				'email'    => $username . '@example.test',
				'role'     => 'author',
				'notify'   => 'none',
			)
		);
		self::assertIsArray( $res );
		self::assertSame( array( 'author' ), $res['roles'] );
		self::assertArrayNotHasKey( 'email', $res );
		$uid = (int) $res['id'];
		self::assertGreaterThan( 0, $uid );
		$dup = $this->call(
			'create-user',
			array(
				'username' => $username,
				'email'    => $username . '@example.test',
				'role'     => 'author',
				'notify'   => 'none',
			)
		);
		self::assertWPError( $dup );
		self::assertSame( 'xnetvn_agent_hub_wp_duplicate_user', $dup->get_error_code() );
	}

	/** Protected accounts cannot be updated. */
	public function test_update_admin_is_protected(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$res   = $this->call(
			'update-user',
			array(
				'id'   => $other,
				'name' => 'Someone Else',
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $res->get_error_code() );
	}

	/** Role replacement is atomic and idempotent. */
	public function test_set_role_lifecycle(): void {
		$uid   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$first = $this->call(
			'set-user-role',
			array(
				'id'   => $uid,
				'role' => 'author',
			)
		);
		self::assertIsArray( $first );
		self::assertTrue( $first['changed'] );
		self::assertSame( array( 'author' ), $first['roles'] );
		$again = $this->call(
			'set-user-role',
			array(
				'id'   => $uid,
				'role' => 'author',
			)
		);
		self::assertIsArray( $again );
		self::assertFalse( $again['changed'] );
		$bad = $this->call(
			'set-user-role',
			array(
				'id'   => $uid,
				'role' => 'administrator',
			)
		);
		self::assertWPError( $bad );
		self::assertSame( 'xnetvn_agent_hub_wp_role_unavailable', $bad->get_error_code() );
	}

	/** Ban removes caps and sessions, unban restores. */
	public function test_ban_unban_lifecycle(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		self::assertSame( 'banned', $ban['status'] );
		self::assertTrue( $ban['changed'] );
		$fresh = get_userdata( $uid );
		self::assertSame( array( 'banned' ), array_values( $fresh->roles ) );
		self::assertFalse( user_can( $uid, 'edit_posts' ) );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'     => $uid,
				'status' => 'active',
			)
		);
		self::assertIsArray( $unban );
		self::assertSame( 'active', $unban['status'] );
		$restored = get_userdata( $uid );
		self::assertSame( array( 'author' ), array_values( $restored->roles ) );
		self::assertNull( BanSnapshot::load( $uid ) );
	}

	/** Banning administrators is refused. */
	public function test_ban_admin_is_protected(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$res   = $this->call(
			'set-user-status',
			array(
				'id'          => $other,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $res->get_error_code() );
	}

	/** A denial during the post-snapshot reguard clears the stale snapshot. */
	public function test_ban_reguard_failure_after_snapshot_clears_it(): void {
		$uid   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$calls = 0;
		$deny  = static function ( bool $allowed ) use ( &$calls ): bool {
			++$calls;
			return $calls < 3 && $allowed;
		};
		add_filter( 'xnetvn_agent_hub_wp_user_allowed', $deny, 10, 1 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'          => $uid,
					'status'      => 'banned',
					'reason_code' => 'spam',
				)
			);
		} finally {
			remove_filter( 'xnetvn_agent_hub_wp_user_allowed', $deny, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $res->get_error_code() );
		self::assertSame( array( 'subscriber' ), array_values( get_userdata( $uid )->roles ) );
		self::assertNull( BanSnapshot::load( $uid ) );
	}

	/** A refused re-ban keeps the recovery snapshot that already existed. */
	public function test_ban_reguard_failure_keeps_existing_snapshot(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		$original = BanSnapshot::load( $uid );
		self::assertIsArray( $original );
		self::assertSame( array( 'author' ), $original['roles'] );
		$banned = get_userdata( $uid );
		self::assertInstanceOf( \WP_User::class, $banned );
		$banned->add_cap( 'read' );
		clean_user_cache( $uid );
		$before_roles = array_values( (array) get_userdata( $uid )->roles );
		$before_caps  = (array) get_userdata( $uid )->caps;
		self::assertArrayHasKey( 'read', $before_caps );
		$calls = 0;
		$deny  = static function ( bool $allowed ) use ( &$calls ): bool {
			++$calls;
			return $calls < 3 && $allowed;
		};
		add_filter( 'xnetvn_agent_hub_wp_user_allowed', $deny, 10, 1 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'          => $uid,
					'status'      => 'banned',
					'reason_code' => 'spam',
				)
			);
		} finally {
			remove_filter( 'xnetvn_agent_hub_wp_user_allowed', $deny, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_protected_account', $res->get_error_code() );
		$after = get_userdata( $uid );
		self::assertSame( $before_roles, array_values( (array) $after->roles ) );
		self::assertSame( $before_caps, (array) $after->caps );
		self::assertSame( $original, BanSnapshot::load( $uid ) );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'     => $uid,
				'status' => 'active',
			)
		);
		self::assertIsArray( $unban );
		self::assertSame( 'active', $unban['status'] );
		self::assertSame( array( 'author' ), array_values( get_userdata( $uid )->roles ) );
	}

	/** A failed capability write clears only the snapshot this attempt created. */
	public function test_ban_role_write_failure_clears_new_snapshot(): void {
		$uid  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$fail = static function ( $check, $object_id, $meta_key ) use ( $uid ) {
			if ( (int) $object_id === $uid && 'wp_capabilities' === $meta_key ) {
				throw new \RuntimeException( 'Fixture capability write failure.' );
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'          => $uid,
					'status'      => 'banned',
					'reason_code' => 'spam',
				)
			);
		} finally {
			remove_filter( 'update_user_metadata', $fail, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $res->get_error_code() );
		self::assertSame( array( 'subscriber' ), array_values( get_userdata( $uid )->roles ) );
		self::assertNull( BanSnapshot::load( $uid ) );
	}

	/** A late loss of promote_user is rechecked immediately before role writes. */
	public function test_set_role_rechecks_promote_before_write(): void {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// A plain site administrator keeps normal cap checks on multisite, where the
		// super-admin owner from set_up would have every cap forced back on.
		$owner         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$previous_user = get_current_user_id();
		wp_set_current_user( $owner );
		$calls             = 0;
		$deny_late_promote = static function ( $allcaps, $required, $args, $user ) use ( $owner, &$calls ): array {
			unset( $required );
			if ( $user instanceof \WP_User && (int) $user->ID === $owner && isset( $args[0] ) && 'promote_user' === $args[0] ) {
				++$calls;
				if ( $calls > 1 ) {
					$allcaps['promote_users'] = false;
				}
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny_late_promote, 10, 4 );
		try {
			$res = $this->call(
				'set-user-role',
				array(
					'id'   => $uid,
					'role' => 'author',
				)
			);
		} finally {
			remove_filter( 'user_has_cap', $deny_late_promote, 10 );
			wp_set_current_user( $previous_user );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $res->get_error_code() );
		self::assertSame( array( 'subscriber' ), array_values( get_userdata( $uid )->roles ) );
	}

	/** Unban with an explicit restore role switches to that role. */
	public function test_unban_with_restore_role(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		self::assertSame( 'banned', $ban['status'] );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'           => $uid,
				'status'       => 'active',
				'restore_role' => 'editor',
			)
		);
		self::assertIsArray( $unban );
		self::assertSame( 'active', $unban['status'] );
		self::assertTrue( $unban['changed'] );
		self::assertSame( array( 'editor' ), array_values( get_userdata( $uid )->roles ) );
		self::assertNull( BanSnapshot::load( $uid ) );
		$back = $this->call(
			'set-user-role',
			array(
				'id'   => $uid,
				'role' => 'author',
			)
		);
		self::assertIsArray( $back );
		self::assertSame( array( 'author' ), $back['roles'] );
	}

	/** Unban restores every snapshotted role in order. */
	public function test_unban_restores_all_snapshot_roles(): void {
		$uid  = self::factory()->user->create( array( 'role' => 'author' ) );
		$user = get_userdata( $uid );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_role( 'editor' );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'     => $uid,
				'status' => 'active',
			)
		);
		self::assertIsArray( $unban );
		self::assertSame( 'active', $unban['status'] );
		self::assertTrue( $unban['changed'] );
		self::assertSame( array( 'author', 'editor' ), array_values( get_userdata( $uid )->roles ) );
	}

	/** Unban with an empty snapshot stays fail-closed. */
	public function test_unban_empty_snapshot_is_invalid_state(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		BanSnapshot::clear( $uid );
		$saved = BanSnapshot::save(
			$uid,
			array(
				'v'      => 1,
				'roles'  => array(),
				'caps'   => array(),
				'at'     => '2026-10-17T00:00:00Z',
				'by'     => get_current_user_id(),
				'reason' => 'spam',
			)
		);
		self::assertTrue( $saved );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'     => $uid,
				'status' => 'active',
			)
		);
		self::assertWPError( $unban );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $unban->get_error_code() );
		self::assertSame( array( 'banned' ), array_values( get_userdata( $uid )->roles ) );
	}

	/** Unban without any snapshot stays fail-closed. */
	public function test_unban_missing_snapshot_is_invalid_state(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		BanSnapshot::clear( $uid );
		$unban = $this->call(
			'set-user-status',
			array(
				'id'     => $uid,
				'status' => 'active',
			)
		);
		self::assertWPError( $unban );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $unban->get_error_code() );
		self::assertSame( array( 'banned' ), array_values( get_userdata( $uid )->roles ) );
	}

	/** A protected post-restore state keeps the recovery snapshot. */
	public function test_unban_keeps_snapshot_when_post_restore_state_is_protected(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		$elevate = static function ( int $user_id ): void {
			$target = get_userdata( $user_id );
			if ( $target instanceof \WP_User ) {
				$target->add_cap( 'edit_users' );
			}
		};
		add_action( 'set_user_role', $elevate, 10, 1 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'     => $uid,
					'status' => 'active',
				)
			);
		} finally {
			remove_action( 'set_user_role', $elevate, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $res->get_error_code() );
		$snapshot = BanSnapshot::load( $uid );
		self::assertIsArray( $snapshot );
		self::assertSame( array( 'author' ), $snapshot['roles'] );
		self::assertContains( 'author', array_values( (array) get_userdata( $uid )->roles ) );
	}

	/** A capability write that is not persisted keeps the recovery snapshot. */
	public function test_unban_keeps_snapshot_when_cap_write_is_not_persisted(): void {
		$uid  = self::factory()->user->create( array( 'role' => 'author' ) );
		$user = get_userdata( $uid );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xnetvn_demo_cap', true );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		$stored = BanSnapshot::load( $uid );
		self::assertIsArray( $stored );
		self::assertTrue( $stored['caps']['xnetvn_demo_cap'] ?? null );
		$drop = static function ( mixed $check, int $object_id, string $meta_key, mixed $meta_value ) use ( $uid ): mixed {
			if ( $uid !== $object_id || ! str_ends_with( $meta_key, 'capabilities' ) || ! is_array( $meta_value ) ) {
				return $check;
			}
			return array_key_exists( 'xnetvn_demo_cap', $meta_value ) ? false : $check;
		};
		add_filter( 'update_user_metadata', $drop, 10, 4 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'     => $uid,
					'status' => 'active',
				)
			);
		} finally {
			remove_filter( 'update_user_metadata', $drop, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $res->get_error_code() );
		self::assertIsArray( BanSnapshot::load( $uid ) );
		self::assertArrayNotHasKey( 'xnetvn_demo_cap', (array) get_userdata( $uid )->caps );
	}

	/** A write failure in the middle of the restore keeps the recovery snapshot. */
	public function test_unban_keeps_snapshot_when_restore_write_fails(): void {
		$uid  = self::factory()->user->create( array( 'role' => 'author' ) );
		$user = get_userdata( $uid );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_role( 'editor' );
		$ban = $this->call(
			'set-user-status',
			array(
				'id'          => $uid,
				'status'      => 'banned',
				'reason_code' => 'spam',
			)
		);
		self::assertIsArray( $ban );
		$fail = static function (): void {
			throw new \RuntimeException( 'simulated restore write failure' );
		};
		add_action( 'add_user_role', $fail, 10, 0 );
		try {
			$res = $this->call(
				'set-user-status',
				array(
					'id'     => $uid,
					'status' => 'active',
				)
			);
		} finally {
			remove_action( 'add_user_role', $fail, 10 );
		}
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $res->get_error_code() );
		$snapshot = BanSnapshot::load( $uid );
		self::assertIsArray( $snapshot );
		self::assertSame( array( 'author', 'editor' ), $snapshot['roles'] );
		self::assertSame( array( 'author' ), array_values( (array) get_userdata( $uid )->roles ) );
	}

	/** Sessions terminate with counts. */
	public function test_terminate_sessions_counts(): void {
		$uid   = self::factory()->user->create( array( 'role' => 'author' ) );
		$first = $this->call( 'terminate-user-sessions', array( 'id' => $uid ) );
		self::assertIsArray( $first );
		self::assertSame( 0, $first['terminated'] );
	}

	/** Password reset requests without leaking links. */
	public function test_password_reset_requested(): void {
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$res = $this->call( 'send-password-reset', array( 'id' => $uid ) );
		if ( is_wp_error( $res ) ) {
			self::assertContains( $res->get_error_code(), array( 'xnetvn_agent_hub_wp_cooldown_active', 'xnetvn_agent_hub_wp_internal_error' ) );
			return;
		}
		self::assertTrue( $res['requested'] );
		$again = $this->call( 'send-password-reset', array( 'id' => $uid ) );
		self::assertWPError( $again );
		self::assertSame( 'xnetvn_agent_hub_wp_cooldown_active', $again->get_error_code() );
	}

	/** Canary values never reach audit. */
	public function test_canary_absent_from_audit(): void {
		global $wpdb;
		$canary = 'CANARY-' . wp_rand();
		$this->call( 'list-users', array( 'search' => $canary ) );
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_audit';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Canary audit check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			self::markTestSkipped( 'Audit table unavailable.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name via %i placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 20', $table ), ARRAY_A );
		self::assertStringNotContainsString( $canary, wp_json_encode( $rows ? $rows : array() ) );
	}
}
