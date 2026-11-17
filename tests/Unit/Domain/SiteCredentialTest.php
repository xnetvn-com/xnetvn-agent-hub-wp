<?php
/**
 * Accountless site credential, scope and request-context contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** Pins the machine-principal value objects before persistence can consume them. */
final class SiteCredentialTest extends TestCase {

	/** A site credential carries a website identity and no WordPress user. */
	public function test_site_credential_constructs_accountless_identity(): void {
		$scope      = $this->scope();
		$credential = new SiteCredential(
			7,
			new KeyId( 'abcdefghijkl' ),
			3,
			'Editorial website key',
			$scope,
			new DateTimeImmutable( '2026-11-17T00:00:00Z' ),
			null,
			12,
			null,
			null,
			'203.0.113.9'
		);

		self::assertSame( 7, $credential->id );
		self::assertSame( 3, $credential->blog_id );
		self::assertSame( 'abcdefghijkl', $credential->key_id->value );
		self::assertSame( 'Editorial website key', $credential->name );
		self::assertSame( $scope, $credential->scope );
		self::assertSame( 12, $credential->created_by );
		self::assertNull( $credential->revoked_at );
		self::assertNull( $credential->revoked_by );
		self::assertSame( '203.0.113.9', $credential->last_used_ip );
		self::assertFalse( $credential->is_revoked() );
		self::assertFalse( property_exists( $credential, 'user_id' ) );
	}

	/** Revocation is explicit and positive manager identities are enforced. */
	public function test_site_credential_rejects_invalid_identity_and_name(): void {
		$scope = $this->scope();

		foreach ( array( 0, -1 ) as $invalid_id ) {
			try {
				new SiteCredential( $invalid_id, new KeyId( 'abcdefghijkl' ), 3, 'Key', $scope, new DateTimeImmutable() );
				self::fail( 'A non-positive id must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );
		new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), 0, 'Key', $scope, new DateTimeImmutable() );
	}

	/** Names are bounded like the existing personal key contract. */
	public function test_site_credential_rejects_empty_and_oversized_names(): void {
		$scope = $this->scope();

		foreach ( array( '', '   ', str_repeat( 'a', 101 ) ) as $name ) {
			try {
				new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), 1, $name, $scope, new DateTimeImmutable() );
				self::fail( 'An invalid name must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}
	}

	/** The scope normalizes members and always keeps system discovery. */
	public function test_site_scope_normalizes_toolsets_and_abilities(): void {
		$scope = $this->scope();

		self::assertSame( 'editorial-v1', $scope->profile );
		self::assertSame( array( 'content', 'media', 'system' ), $scope->toolsets );
		self::assertSame( array( 'xnetvn/get-post', 'xnetvn/update-post' ), $scope->allowed_abilities );
		self::assertSame( array( 'xnetvn/delete-post' ), $scope->denied_abilities );
		self::assertSame( array(), $scope->permissions );
		self::assertSame( '2027-01-01T00:00:00Z', $scope->expires_at->format( 'Y-m-d\TH:i:s\Z' ) );
	}

	/** An empty allowlist can never become a wildcard. */
	public function test_site_scope_rejects_empty_allowlist(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SiteScope( RiskTier::Read, array( 'content' ), array(), expires_at: new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
	}

	/** An expiry is mandatory and must parse as UTC. */
	public function test_site_scope_rejects_missing_expiry(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ) );
	}

	/** Unknown toolsets, permissions and ability syntax are rejected. */
	public function test_site_scope_rejects_invalid_members(): void {
		foreach (
			array(
				array( array( 'not valid' ), array( 'xnetvn/get-post' ), array(), array(), array() ),
				array( array( 'content' ), array( 'get-post' ), array(), array(), array() ),
				array( array( 'content' ), array( 'xnetvn/get-post' ), array(), array( 'publish-everything' ), array() ),
				array( array( 'content' ), array( 'xnetvn/get-post' ), array(), array(), array( 'example.com/24' ) ),
			) as $case
		) {
			try {
				new SiteScope( RiskTier::Read, $case[0], $case[1], $case[2], $case[3], $case[4], new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
				self::fail( 'An invalid scope member must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}
	}

	/** Moderation grants require an admin ceiling, users pack and the ability. */
	public function test_site_scope_moderation_consistency(): void {
		$expiry = new DateTimeImmutable( '2027-01-01T00:00:00Z' );

		$invalid = array(
			array( RiskTier::Read, array( 'users' ), array( 'xnetvn/set-user-status' ), array( 'ban-users' ), array() ),
			array( RiskTier::Admin, array( 'content' ), array( 'xnetvn/set-user-status' ), array( 'ban-users' ), array() ),
			array( RiskTier::Admin, array( 'users' ), array( 'xnetvn/get-user' ), array( 'ban-users' ), array() ),
			array( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array( 'ban-users' ), array( 'xnetvn/set-user-status' ) ),
		);

		foreach ( $invalid as $case ) {
			try {
				new SiteScope( $case[0], $case[1], $case[2], $case[4] ?? array(), $case[3], array(), $expiry );
				self::fail( 'An inconsistent moderation scope must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}

		$valid = new SiteScope( RiskTier::Admin, array( 'users', 'content' ), array( 'xnetvn/set-user-status' ), array(), array( 'ban-users' ), array(), $expiry );
		self::assertSame( array( 'ban-users' ), $valid->permissions );
		self::assertSame( RiskTier::Admin, $valid->tier_ceiling );
	}

	/** Narrowing is monotone; any expansion is refused. */
	public function test_site_scope_narrowing_lattice(): void {
		$expiry = new DateTimeImmutable( '2027-01-01T00:00:00Z' );
		$old    = new SiteScope( RiskTier::Write, array( 'content', 'media' ), array( 'xnetvn/get-post', 'xnetvn/update-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.113.0/24' ), $expiry );
		$new    = new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post', 'xnetvn/create-post' ), array(), array( '203.0.113.128/25' ), $expiry->modify( '-1 day' ) );

		self::assertTrue( $new->is_narrower_than( $old ) );
		self::assertFalse( $old->is_narrower_than( $new ) );

		$expansions = array(
			new SiteScope( RiskTier::Read, array( 'content', 'media', 'taxonomy' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.113.128/25' ), $expiry ),
			new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post', 'xnetvn/create-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.113.128/25' ), $expiry ),
			new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), array(), array(), array( '203.0.113.128/25' ), $expiry ),
			new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.112.0/23' ), $expiry ),
			new SiteScope( RiskTier::Write, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.113.128/25' ), $expiry->modify( '+1 day' ) ),
			new SiteScope( RiskTier::Destructive, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post' ), array(), array( '203.0.113.128/25' ), $expiry ),
			new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post' ), array(), array(), $expiry ),
		);

		foreach ( $expansions as $candidate ) {
			self::assertFalse( $candidate->is_narrower_than( $old ) );
		}

		$old_moderation = new SiteScope( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array(), array(), array(), $expiry );
		$new_moderation = new SiteScope( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array(), array( 'unban-users' ), array(), $expiry );
		self::assertFalse( $new_moderation->is_narrower_than( $old_moderation ) );
	}

	/** Array round-tripping is strict and fails closed on corruption. */
	public function test_site_scope_array_round_trip_and_corruption(): void {
		$scope = $this->scope();
		$data  = $scope->to_array();

		self::assertSame( 'editorial-v1', $data['profile'] );
		self::assertSame( 'write', $data['tier_ceiling'] );
		self::assertSame( '2027-01-01T00:00:00Z', $data['expires_at'] );
		self::assertSame( $scope->to_array(), SiteScope::from_array( $data )->to_array() );

		$corrupt = array(
			array_merge( $data, array( 'unknown' => true ) ),
			array_merge( $data, array( 'tier_ceiling' => 'root' ) ),
			array_merge( $data, array( 'expires_at' => 'not-a-date' ) ),
			array_merge( $data, array( 'allowed_abilities' => array() ) ),
			array_merge( $data, array( 'profile' => 'editorial-v2' ) ),
		);

		foreach ( $corrupt as $candidate ) {
			try {
				SiteScope::from_array( $candidate );
				self::fail( 'A corrupt scope must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}
	}

	/** The request context exposes safe derived identity only. */
	public function test_site_auth_context_derives_safe_identity(): void {
		$credential = new SiteCredential( 7, new KeyId( 'abcdefghijkl' ), 3, 'Key', $this->scope(), new DateTimeImmutable( '2026-11-17T00:00:00Z' ) );
		$context    = new SiteAuthContext( $credential, 'request-1', '203.0.113.9' );

		self::assertSame( 3, $context->blog_id() );
		self::assertSame( 'xns:abcdefghijkl', $context->credential_ref() );
		self::assertSame( 'site', $context->principal_type );
		self::assertFalse( $context->has_permission( 'ban-users' ) );

		$debug = $context->__debugInfo();
		self::assertSame( array( 'principal_type', 'blog_id', 'credential_ref', 'request_id', 'client_ip' ), array_keys( $debug ) );
		self::assertSame( 'site', $debug['principal_type'] );

		$moderation_scope = new SiteScope( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array(), array( 'ban-users' ), array(), new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		$moderation       = new SiteAuthContext( new SiteCredential( 8, new KeyId( 'mnopqrstuvwx' ), 3, 'Moderation key', $moderation_scope, new DateTimeImmutable( '2026-11-17T00:00:00Z' ) ), 'request-2', '203.0.113.9' );
		self::assertTrue( $moderation->has_permission( 'ban-users' ) );
		self::assertFalse( $moderation->has_permission( 'unban-users' ) );
	}

	/** Empty request and peer identities are rejected before policy runs. */
	public function test_site_auth_context_rejects_empty_identity(): void {
		$credential = new SiteCredential( 7, new KeyId( 'abcdefghijkl' ), 3, 'Key', $this->scope(), new DateTimeImmutable( '2026-11-17T00:00:00Z' ) );

		foreach ( array( '', '   ' ) as $value ) {
			try {
				new SiteAuthContext( $credential, $value, '203.0.113.9' );
				self::fail( 'An empty request id must be rejected.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'Invalid', $exception->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );
		new SiteAuthContext( $credential, 'request-1', '' );
	}

	/** A reusable valid scope for the construction tests. */
	private function scope(): SiteScope {
		return new SiteScope(
			RiskTier::Write,
			array( 'media', 'content', 'content' ),
			array( 'xnetvn/update-post', 'xnetvn/get-post' ),
			array( 'xnetvn/delete-post' ),
			array(),
			array( '203.0.113.0/24' ),
			new DateTimeImmutable( '2027-01-01T00:00:00Z' )
		);
	}
}
