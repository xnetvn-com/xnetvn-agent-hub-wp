<?php
/**
 * Immutable delegation and actor identity tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\Toolset;

/** Pins the scope narrowing contract before persistence can consume it. */
final class ScopeTest extends TestCase {

	/** System discovery remains available and toolsets are deterministic. */
	public function test_scope_normalizes_toolsets(): void {
		$scope = new Scope( RiskTier::Write, array( 'media', 'content', 'content' ) );
		self::assertSame( array( 'content', 'media', 'system' ), $scope->toolsets );
		self::assertSame( 'content', ( new Toolset( 'content' ) )->value );
	}

	/** Narrowing every grant is permitted; removing a restriction is forbidden. */
	public function test_narrowing_grants_and_restrictions(): void {
		$expiry = new DateTimeImmutable( '2027-01-01T00:00:00Z' );
		$old    = new Scope( RiskTier::Destructive, array( 'content', 'media' ), array(), array( 'xnetvn/delete-post' ), array( '203.0.113.0/24' ), $expiry );
		$new    = new Scope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), array( 'xnetvn/delete-post', 'xnetvn/create-post' ), array( '203.0.113.128/25' ), $expiry->modify( '-1 day' ) );
		self::assertTrue( $new->is_narrower_than( $old ) );
		self::assertFalse( $old->is_narrower_than( $new ) );
		self::assertFalse( ( new Scope( RiskTier::Destructive, array( 'content', 'media' ), array(), array(), array( '203.0.113.0/24' ), $expiry ) )->is_narrower_than( $old ) );
		self::assertFalse( ( new Scope( RiskTier::Read, array( 'content' ), array(), array( 'xnetvn/delete-post' ), array(), $expiry ) )->is_narrower_than( $old ) );
		self::assertFalse( ( new Scope( RiskTier::Read, array( 'content' ), array(), array( 'xnetvn/delete-post' ), array( '203.0.112.0/23' ), $expiry ) )->is_narrower_than( $old ) );
		self::assertFalse( ( new Scope( RiskTier::Read, array( 'content' ), array(), array( 'xnetvn/delete-post' ), array( '203.0.113.0/24' ), null ) )->is_narrower_than( $old ) );
	}

	/** An explicit ability allowlist cannot be widened by an empty list. */
	public function test_allowlist_cannot_be_removed(): void {
		$old = new Scope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ) );
		self::assertFalse( ( new Scope( RiskTier::Read, array( 'content' ) ) )->is_narrower_than( $old ) );
		self::assertTrue( $old->is_narrower_than( $old ) );
	}

	/** IPv6 subnet restrictions narrow using bit prefixes, not string prefixes. */
	public function test_ipv6_scope_narrowing(): void {
		$old = new Scope( RiskTier::Read, array(), array(), array(), array( '2001:db8::/32' ) );
		self::assertTrue( ( new Scope( RiskTier::Read, array(), array(), array(), array( '2001:db8:abcd::/48' ) ) )->is_narrower_than( $old ) );
		self::assertFalse( ( new Scope( RiskTier::Read, array(), array(), array(), array( '2001:db9::/32' ) ) )->is_narrower_than( $old ) );
	}

	/** An actor cannot disagree with the key owner or retain credential material. */
	public function test_api_key_and_context_identity(): void {
		$key        = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 42, 'Test key', new Scope( RiskTier::Read ), new DateTimeImmutable( '@1791072000' ) );
		$request_id = bin2hex( random_bytes( 8 ) );
		$context    = new AuthContext( $key, $request_id );
		self::assertSame( 42, $context->user_id );
		self::assertSame( $key, $context->key );
		self::assertSame( $request_id, $context->request_id );
		self::assertFalse( $key->is_revoked() );
		self::assertTrue( Decision::permit()->allowed );
		self::assertFalse( Decision::deny( 'scope_denied' )->allowed );
		self::assertSame( 'scope_denied', Decision::deny( 'scope_denied' )->reason );
	}

	/** A 100-character Unicode name follows VARCHAR character semantics. */
	public function test_key_name_counts_characters(): void {
		$key = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 42, str_repeat( 'Việt', 25 ), new Scope( RiskTier::Read ), new DateTimeImmutable() );
		self::assertSame( str_repeat( 'Việt', 25 ), $key->name );
	}

	/**
	 * Invalid scope syntax is rejected before storage.
	 *
	 * @dataProvider invalid_scopes
	 * @param array<string> $toolsets Requested toolsets.
	 * @param array<string> $allow Requested ability allowlist.
	 * @param array<string> $ips Requested CIDRs.
	 */
	public function test_invalid_scope( array $toolsets, array $allow, array $ips ): void {
		$this->expectException( \InvalidArgumentException::class );
		new Scope( RiskTier::Read, $toolsets, $allow, array(), $ips );
	}

	/**
	 * Invalid caller-controlled syntax.
	 *
	 * @return array<string,array{array<string>,array<string>,array<string>}>
	 */
	public function invalid_scopes(): array {
		return array(
			'toolset whitespace'        => array( array( 'not valid' ), array(), array() ),
			'ability without namespace' => array( array(), array( 'get-post' ), array() ),
			'invalid IPv4 length'       => array( array(), array(), array( '203.0.113.0/33' ) ),
			'invalid IPv6 length'       => array( array(), array(), array( '2001:db8::/129' ) ),
			'non-IP host'               => array( array(), array(), array( 'example.com/24' ) ),
			'negative mask'             => array( array(), array(), array( '203.0.113.0/-1' ) ),
		);
	}
}
