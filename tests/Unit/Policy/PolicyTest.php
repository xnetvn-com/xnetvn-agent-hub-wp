<?php
/**
 * Delegation intersection truth table.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Policy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Every independent policy term must succeed; filters may only deny more. */
final class PolicyTest extends TestCase {
	/**
	 * Check all64 ability/key/site tier combinations.
	 *
	 * @dataProvider tiers
	 * @param RiskTier $ability_tier Ability risk.
	 * @param RiskTier $key_tier Key ceiling.
	 * @param RiskTier $site_tier Site ceiling.
	 * @param bool     $expected Expected result.
	 */
	public function test_tier_intersection( RiskTier $ability_tier, RiskTier $key_tier, RiskTier $site_tier, bool $expected ): void {
		$policy  = new Policy(
			new MemorySettings(
				array(
					'tier_ceiling'     => $site_tier->value,
					'allow_admin_tier' => true,
				)
			),
			static fn(): bool => true,
			static fn( Decision $decision ): Decision => $decision
		);
		$ability = new AbilityDescriptor( 'xnetvn/get-post', $ability_tier, 'content', 'edit_posts' );
		self::assertSame( $expected, $policy->evaluate( $ability, $this->context( new Scope( $key_tier, array( 'content' ) ) ), 'http' )->allowed );
	}
	/**
	 * Independent numerical truth table.
	 *
	 * @return array<array{RiskTier,RiskTier,RiskTier,bool}>
	 */
	public function tiers(): array {
		$values = array( RiskTier::Read, RiskTier::Write, RiskTier::Destructive, RiskTier::Admin );
		$cases  = array();
		foreach ( $values as $i => $ability ) {
			foreach ( $values as $j => $key ) {
				foreach ( $values as $k => $site ) {
					$cases[] = array( $ability, $key, $site, $i <= $j && $i <= $k );
				}
			}
		}
		return $cases;
	}
	/** Missing HTTP identity cannot be granted by a filter. */
	public function test_http_requires_context_even_if_filter_grants(): void {
		$policy  = new Policy( new MemorySettings(), static fn(): bool => true, static fn(): Decision => Decision::permit() );
		$ability = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read' );
		self::assertFalse( $policy->evaluate( $ability, null, 'http' )->allowed );
		self::assertTrue( $policy->evaluate( $ability, null, 'stdio' )->allowed );
		self::assertFalse( $policy->evaluate( $ability, null, 'unknown' )->allowed );
	}
	/** Capability, toolset, allowlist, denylist, site switch and admin flag are independent. */
	public function test_independent_denial_terms(): void {
		$ability  = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read' );
		$pass     = static fn(): bool => true;
		$identity = static fn( Decision $decision ): Decision => $decision;
		$policy   = new Policy( new MemorySettings(), $pass, $identity );
		self::assertFalse( $policy->evaluate( $ability, $this->context( new Scope( RiskTier::Read, array( 'media' ) ) ), 'http' )->allowed );
		self::assertFalse( $policy->evaluate( $ability, $this->context( new Scope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-site-info' ) ) ), 'http' )->allowed );
		self::assertFalse( $policy->evaluate( $ability, $this->context( new Scope( RiskTier::Read, array( 'content' ), array(), array( 'xnetvn/get-post' ) ) ), 'http' )->allowed );
		self::assertFalse( ( new Policy( new MemorySettings(), static fn(): bool => false, $identity ) )->evaluate( $ability, $this->context( new Scope( RiskTier::Read, array( 'content' ) ) ), 'http' )->allowed );
		self::assertFalse( ( new Policy( new MemorySettings( array( 'enabled' => false ) ), $pass, $identity ) )->evaluate( $ability, null, 'stdio' )->allowed );
		$admin = new AbilityDescriptor( 'xnetvn/update-post', RiskTier::Admin, 'content', 'manage_options' );
		self::assertFalse( ( new Policy( new MemorySettings( array( 'tier_ceiling' => 'admin' ) ), $pass, $identity ) )->evaluate( $admin, $this->context( new Scope( RiskTier::Admin, array( 'content' ) ) ), 'http' )->allowed );
		$denied = new Policy( new MemorySettings(), $pass, static fn(): Decision => Decision::deny( 'extension_denied' ) );
		self::assertSame( 'extension_denied', $denied->evaluate( $ability, $this->context( new Scope( RiskTier::Read, array( 'content' ) ) ), 'http' )->reason );
	}
	/** The static envelope admits only reviewed names per principal kind. */
	public function test_safe_envelope_truth_table(): void {
		$envelope = new SafeMcpEnvelope();

		foreach ( array( 'xnetvn/get-post', 'xnetvn/discover-abilities', 'xnetvn/list-content-authors' ) as $name ) {
			self::assertTrue( $envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_USER )->allowed );
			self::assertTrue( $envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_SITE )->allowed );
		}
		self::assertTrue( $envelope->evaluate( 'xnetvn/get-my-profile', SafeMcpEnvelope::PRINCIPAL_USER )->allowed );
		self::assertFalse( $envelope->evaluate( 'xnetvn/get-my-profile', SafeMcpEnvelope::PRINCIPAL_SITE )->allowed );
		self::assertTrue( $envelope->evaluate( 'xnetvn/set-user-status', SafeMcpEnvelope::PRINCIPAL_SITE )->allowed );
		self::assertFalse( $envelope->evaluate( 'xnetvn/set-user-status', SafeMcpEnvelope::PRINCIPAL_USER )->allowed );

		foreach ( array( 'xnetvn/create-user', 'xnetvn/list-users', 'xnetvn/fixture-read', 'other/get-data' ) as $name ) {
			$decision = $envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_SITE );
			self::assertFalse( $decision->allowed );
			self::assertSame( 'operation_outside_safe_envelope', $decision->reason );
		}
		self::assertFalse( $envelope->evaluate( 'xnetvn/get-post', 'unknown' )->allowed );
	}

	/** Envelope refusal precedes every configurable policy term. */
	public function test_safe_envelope_denies_before_policy_terms(): void {
		$policy  = new Policy(
			new MemorySettings(
				array(
					'tier_ceiling'     => 'admin',
					'allow_admin_tier' => true,
				)
			),
			static fn(): bool => true,
			static fn( Decision $decision ): Decision => $decision
		);
		$ability = new AbilityDescriptor( 'xnetvn/create-user', RiskTier::Read, 'users', 'read' );
		$context = $this->context( new Scope( RiskTier::Admin, array( 'users' ) ) );
		$denied  = $policy->evaluate( $ability, $context, 'http' );
		self::assertFalse( $denied->allowed );
		self::assertSame( 'operation_outside_safe_envelope', $denied->reason );

		$reviewed = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read' );
		self::assertTrue( $policy->evaluate( $reviewed, $this->context( new Scope( RiskTier::Read, array( 'content' ) ) ), 'http' )->allowed );
		$profile = new AbilityDescriptor( 'xnetvn/update-my-profile', RiskTier::Read, 'users', 'read' );
		self::assertTrue( $policy->evaluate( $profile, $this->context( new Scope( RiskTier::Admin, array( 'users' ) ) ), 'http' )->allowed );
	}

	/** A third-party definition must be explicitly approved. */
	public function test_third_party_requires_approval(): void {
		$ability = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read', true );
		$context = $this->context( new Scope( RiskTier::Read, array( 'content' ) ) );
		$policy  = new Policy( new MemorySettings(), static fn(): bool => true, static fn( Decision $decision ): Decision => $decision );
		self::assertFalse( $policy->evaluate( $ability, $context, 'http' )->allowed );
	}
	/**
	 * Build a safe delegated actor.
	 *
	 * @param Scope $scope Scope.
	 * @return AuthContext
	 */
	private function context( Scope $scope ): AuthContext {
		return new AuthContext( new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 42, 'Policy fixture', $scope, new DateTimeImmutable( '2026-10-04T00:00:00Z' ) ), '0123456789abcdef' );
	}
}
