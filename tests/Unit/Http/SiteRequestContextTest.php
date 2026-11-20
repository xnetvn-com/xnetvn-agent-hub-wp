<?php
/**
 * Additive site request identity and metadata redaction.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\FailureReason;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Contracts\SiteRequestContext;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Support\RequestMetadata;

/** Pins mutual exclusion, conflict refusal and credential-shaped metadata redaction. */
final class SiteRequestContextTest extends TestCase {

	/** The extended contract stays additive for human-only consumers. */
	public function test_request_scope_implements_the_additive_site_contract(): void {
		$scope = new RequestScope();

		self::assertInstanceOf( RequestContext::class, $scope );
		self::assertInstanceOf( SiteRequestContext::class, $scope );
		self::assertFalse( $scope->has_conflicting_principals() );
		self::assertNull( $scope->current() );
		self::assertNull( $scope->site_current() );
	}

	/** Binding a website principal clears the human principal. */
	public function test_bind_site_clears_the_human_principal(): void {
		$scope = new RequestScope();
		$scope->bind_human( $this->human() );
		$scope->bind_site( $this->site() );

		self::assertFalse( $scope->has_conflicting_principals() );
		self::assertNull( $scope->current() );
		self::assertSame( 1, $scope->site_current()?->blog_id() );
	}

	/** Binding a human principal clears the website principal. */
	public function test_bind_human_clears_the_site_principal(): void {
		$scope = new RequestScope();
		$scope->bind_site( $this->site() );
		$scope->bind_human( $this->human() );

		self::assertFalse( $scope->has_conflicting_principals() );
		self::assertNull( $scope->site_current() );
		self::assertSame( 7, $scope->current()?->user_id );
	}

	/** Direct dual-field writes fail closed on both getters. */
	public function test_direct_dual_assignment_fails_closed(): void {
		$scope               = new RequestScope();
		$scope->context      = $this->human();
		$scope->site_context = $this->site();

		self::assertTrue( $scope->has_conflicting_principals() );
		self::assertNull( $scope->current() );
		self::assertNull( $scope->site_current() );
	}

	/** Reset removes both principals and all request state. */
	public function test_reset_clears_both_principals_and_request_state(): void {
		$scope               = new RequestScope();
		$scope->attempted    = true;
		$scope->has_token    = true;
		$scope->context      = $this->human();
		$scope->site_context = $this->site();
		$scope->failure      = FailureReason::Missing;
		$scope->budget       = new RateLimitResult( true, 0, 9, 1.0 );

		$scope->reset();

		self::assertFalse( $scope->attempted );
		self::assertFalse( $scope->has_token );
		self::assertNull( $scope->context );
		self::assertNull( $scope->site_context );
		self::assertFalse( $scope->has_conflicting_principals() );
		self::assertNull( $scope->current() );
		self::assertNull( $scope->site_current() );
		self::assertNull( $scope->failure );
		self::assertNull( $scope->budget );
	}

	/** Human-only test doubles remain valid implementors. */
	public function test_human_only_implementations_remain_compatible(): void {
		$request = new class() implements RequestContext {
			/**
			 * Return no identity.
			 *
			 * @return \XNetVN\AgentHubWP\Domain\AuthContext|null
			 */
			public function current(): ?\XNetVN\AgentHubWP\Domain\AuthContext {
				return null;
			}

			/**
			 * Report the transport.
			 *
			 * @return string
			 */
			public function transport(): string {
				return 'stdio';
			}

			/**
			 * Report no budget.
			 *
			 * @return \XNetVN\AgentHubWP\Domain\RateLimitResult|null
			 */
			public function rate_budget(): ?\XNetVN\AgentHubWP\Domain\RateLimitResult {
				return null;
			}
		};

		self::assertInstanceOf( RequestContext::class, $request );
		self::assertNotInstanceOf( SiteRequestContext::class, $request );
	}

	/** Redacts xnv_, xns_ and raw Bearer fragments without leaking a marker. */
	public function test_user_agent_redacts_personal_site_and_bearer_credentials(): void {
		$value = RequestMetadata::user_agent( 'Client xnv_abcdefghijkl_secretCANARY xns_bcdefghijkl_secretCANARY Bearer CANARYTOKEN' );

		self::assertStringNotContainsString( 'CANARY', $value );
		self::assertSame( 3, substr_count( $value, '[redacted]' ) );
	}

	/** Redaction keeps the existing byte and bounded-output behavior. */
	public function test_user_agent_remains_bounded(): void {
		self::assertSame( 120, strlen( RequestMetadata::user_agent( str_repeat( 'a', 200 ) ) ) );
	}

	/**
	 * Build an eligible human identity.
	 *
	 * @return AuthContext
	 */
	private function human(): AuthContext {
		return new AuthContext(
			new ApiKey(
				1,
				new KeyId( 'abcdefghijkl' ),
				7,
				'Human key',
				new Scope( RiskTier::Read ),
				new DateTimeImmutable( '2026-11-17T00:00:00Z' )
			),
			'0123456789abcdef'
		);
	}

	/**
	 * Build an accountless website identity.
	 *
	 * @return SiteAuthContext
	 */
	private function site(): SiteAuthContext {
		$credential = new SiteCredential(
			2,
			new KeyId( 'bcdefghijklm' ),
			1,
			'Site key',
			new SiteScope( RiskTier::Read, array( 'content' ), array( 'xnetvn/get-post' ), expires_at: new DateTimeImmutable( '2027-01-01T00:00:00Z' ) ),
			new DateTimeImmutable( '2026-11-17T00:00:00Z' )
		);

		return new SiteAuthContext( $credential, '0fb0fb0fb0fb0fb0', '203.0.113.9' );
	}
}
