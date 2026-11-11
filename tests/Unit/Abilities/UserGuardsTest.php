<?php
/**
 * Users guards, snapshot, projection and schemas.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UserProjection;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UserSchemas;

/** Guards deny privilege escalation; projection never leaks secrets. */
final class UserGuardsTest extends TestCase {
	/** Privileged set contains user management but not unfiltered html. */
	public function test_privileged_set_contents(): void {
		$caps = RoleGuard::privileged_caps();
		foreach ( array( 'edit_users', 'create_users', 'delete_users', 'promote_users', 'manage_options', 'install_plugins', 'manage_network' ) as $cap ) {
			self::assertContains( $cap, $caps );
		}
		self::assertNotContains( 'unfiltered_html', $caps );
		self::assertNotContains( 'unfiltered_upload', $caps );
	}

	/** Role privilege and assignability. */
	public function test_role_privilege_and_assignable(): void {
		self::assertTrue( RoleGuard::is_privileged( 'administrator' ) );
		self::assertTrue( RoleGuard::is_privileged( 'administrator', array( 'read' => true ) ) );
		self::assertFalse(
			RoleGuard::is_privileged(
				'editor',
				array(
					'edit_posts'      => true,
					'unfiltered_html' => true,
				)
			)
		);
		self::assertTrue( RoleGuard::is_privileged( 'shop_manager', array( 'promote_users' => true ) ) );
		self::assertFalse( RoleGuard::assignable( 'editor', array( 'edit_posts' => true ), array( 'editor', 'author' ) ) === false );
		self::assertTrue( RoleGuard::assignable( 'editor', array( 'edit_posts' => true ), array( 'editor', 'author' ) ) );
		self::assertFalse( RoleGuard::assignable( 'administrator', array( 'promote_users' => true ), array( 'administrator', 'editor' ) ) );
		self::assertFalse( RoleGuard::assignable( 'banned', array(), array( 'banned', 'editor' ) ) );
		self::assertFalse( RoleGuard::assignable( 'nope-missing', null, array( 'editor' ) ) );
		self::assertFalse( RoleGuard::assignable( 'Bad Name', array(), array( 'Bad Name' ) ) );
	}

	/** Snapshot validation rejects oversized or privileged payloads. */
	public function test_snapshot_validation(): void {
		$good = BanSnapshot::validate(
			array(
				'v'      => 1,
				'roles'  => array( 'author' ),
				'caps'   => array( 'edit_posts' => true ),
				'at'     => '2026-10-09T10:00:00Z',
				'by'     => 7,
				'reason' => 'spam',
			)
		);
		self::assertTrue( $good );
		self::assertFalse(
			BanSnapshot::validate(
				array(
					'v'      => 1,
					'roles'  => array_fill( 0, 21, 'author' ),
					'caps'   => array(),
					'at'     => '2026-10-09T10:00:00Z',
					'by'     => 7,
					'reason' => 'spam',
				)
			)
		);
		self::assertFalse(
			BanSnapshot::validate(
				array(
					'v'      => 1,
					'roles'  => array( 'administrator' ),
					'caps'   => array(),
					'at'     => '2026-10-09T10:00:00Z',
					'by'     => 7,
					'reason' => 'spam',
				)
			)
		);
		self::assertFalse(
			BanSnapshot::validate(
				array(
					'v'      => 2,
					'roles'  => array(),
					'caps'   => array(),
					'at'     => 'nope',
					'by'     => 7,
					'reason' => 'spam',
				)
			)
		);
	}

	/** Projection allowlist and privacy groups. */
	public function test_projection_allowlist(): void {
		$record = array(
			'id'             => 42,
			'name'           => 'Mai Tran',
			'slug'           => 'mai-tran',
			'status'         => 'active',
			'roles'          => array( 'author' ),
			'protected'      => false,
			'email'          => 'mai@example.test',
			'username'       => 'mai.tran',
			'user_pass'      => 'secret',
			'avatar_urls'    => array( 'x' ),
			'capabilities'   => array( 'edit_posts' => true ),
			'session_tokens' => array( 'x' ),
		);
		$proj   = new UserProjection( static fn( int $id ): bool => $id >= 0 );
		$out    = $proj->project( $record, array() );
		self::assertArrayNotHasKey( 'email', $out );
		self::assertArrayNotHasKey( 'username', $out );
		self::assertArrayNotHasKey( 'user_pass', $out );
		self::assertArrayNotHasKey( 'avatar_urls', $out );
		self::assertArrayNotHasKey( 'capabilities', $out );
		self::assertArrayNotHasKey( 'session_tokens', $out );
		self::assertSame( 42, $out['id'] );
		$with = $proj->project( $record, array( 'email', 'username' ) );
		self::assertSame( 'mai@example.test', $with['email'] );
	}

	/** Schemas are strict and never contain passwords. */
	public function test_schemas_strict_no_password(): void {
		foreach ( array( 'list-users', 'get-user', 'create-user', 'update-user', 'set-user-role', 'set-user-status', 'send-password-reset', 'terminate-user-sessions', 'get-my-profile', 'update-my-profile', 'list-roles', 'get-user-capabilities' ) as $tool ) {
			$in  = UserSchemas::input( $tool );
			$out = UserSchemas::output( $tool );
			self::assertSame( 'object', $in['type'] );
			self::assertFalse( $in['additionalProperties'] );
			self::assertSame( 'object', $out['type'] );
			self::assertFalse( $out['additionalProperties'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
			$json = json_encode( array( $in, $out ) );
			self::assertStringNotContainsStringIgnoringCase( 'password', is_string( $json ) ? $json : '' );
		}
		$create = UserSchemas::input( 'create-user' );
		self::assertArrayHasKey( 'role', $create['properties'] );
		self::assertArrayNotHasKey( 'roles', $create['properties'] );
	}
}
