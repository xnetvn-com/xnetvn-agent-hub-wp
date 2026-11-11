<?php
/**
 * Role/SEO permission matrix contracts at integration level.
 *
 * Covers the AUTH-01..AUTH-10 vocabulary used by the remote permissions
 * matrix: harness case ids, per-dimension policy denials, HTTP status
 * mapping and hidden-tool handling. Real HTTPS behavior stays in
 * tests/E2E/role-seo-permissions.php; this file pins the mechanism that
 * the matrix relies on.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\FailureReason;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\E2E\RoleSeoHarness;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Pins AUTH policy, status and hidden-tool contracts without network. */
final class RoleSeoPermissionsTest extends WP_UnitTestCase {
	/**
	 * Build one key-scoped HTTP context.
	 *
	 * @param Scope $scope Key scope.
	 * @param int   $user_id Key owner id.
	 * @return AuthContext
	 */
	private static function context( Scope $scope, int $user_id = 42 ): AuthContext {
		return new AuthContext(
			new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $user_id, 'Permissions fixture', $scope, new \DateTimeImmutable( '2026-10-04T00:00:00Z' ) ),
			'0123456789abcdef'
		);
	}

	/**
	 * Evaluate one ability against scope and site overrides.
	 *
	 * @param AbilityDescriptor   $ability Ability.
	 * @param Scope               $scope Key scope.
	 * @param array<string,mixed> $overrides Site setting overrides.
	 * @param int                 $user_id Key owner id.
	 * @return \XNetVN\AgentHubWP\Domain\Decision
	 */
	private static function decide( AbilityDescriptor $ability, Scope $scope, array $overrides = array(), int $user_id = 42 ) {
		return ( new Policy( new MemorySettings( $overrides ) ) )->evaluate( $ability, self::context( $scope, $user_id ), 'http' );
	}

	/** AUTH ids are accepted alongside the FX vocabulary. */
	public function test_harness_accepts_auth_case_ids(): void {
		$harness = new RoleSeoHarness(
			array(
				'target'        => 'wp69',
				'blog'          => 1,
				'base_url'      => 'https://example.test',
				'run_id'        => 'perm-vocab',
				'private_state' => '/tmp/role-seo-perm-vocab',
			),
			new \XNetVN\AgentHubWP\Tests\E2E\McpClient( 'https://example.test', array( 'example.test' ), true )
		);
		foreach ( array( 'AUTH-01', 'AUTH-02', 'AUTH-05-demote', 'AUTH-10-canary' ) as $id ) {
			$harness->recordCase( $id, true );
		}
		self::assertSame( 4, $harness->caseCount() );
		foreach ( array( 'AUTH-01', 'AUTH-02', 'AUTH-05-demote', 'AUTH-10-canary' ) as $id ) {
			self::assertTrue( $harness->cases()[ $id ]['ok'] );
		}
	}

	/** Unsafe case ids are still refused. */
	public function test_harness_rejects_unsafe_case_ids(): void {
		$harness = new RoleSeoHarness(
			array(
				'target'        => 'wp69',
				'blog'          => 1,
				'base_url'      => 'https://example.test',
				'run_id'        => 'perm-vocab-bad',
				'private_state' => '/tmp/role-seo-perm-vocab-bad',
			),
			new \XNetVN\AgentHubWP\Tests\E2E\McpClient( 'https://example.test', array( 'example.test' ), true )
		);
		$this->expectException( \InvalidArgumentException::class );
		$harness->recordCase( 'BAD-01', true );
	}

	/** Unknown groups are refused before any file access. */
	public function test_harness_rejects_unknown_group(): void {
		$harness = new RoleSeoHarness(
			array(
				'target'        => 'wp69',
				'blog'          => 1,
				'base_url'      => 'https://example.test',
				'run_id'        => 'perm-group',
				'private_state' => '/tmp/role-seo-perm-group',
			),
			new \XNetVN\AgentHubWP\Tests\E2E\McpClient( 'https://example.test', array( 'example.test' ), true )
		);
		$this->expectException( \InvalidArgumentException::class );
		$harness->runGroup( 'bogus-group' );
	}

	/** A read key cannot reach a write tool. */
	public function test_read_scope_denies_write_tool(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/create-post', RiskTier::Write, 'content', 'edit_posts' ),
			new Scope( RiskTier::Read, array( 'system', 'content' ) )
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'key_tier', $decision->reason );
	}

	/** A write key cannot reach an admin tool. */
	public function test_write_scope_denies_admin_tool(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/set-user-status', RiskTier::Admin, 'users', 'promote_users' ),
			new Scope( RiskTier::Write, array( 'system', 'users' ) ),
			array(
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
			)
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'key_tier', $decision->reason );
	}

	/** Removing a toolset denies every tool inside it. */
	public function test_missing_toolset_denies_tool(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/upload-media-from-data', RiskTier::Write, 'media', 'upload_files' ),
			new Scope( RiskTier::Write, array( 'system', 'content' ) )
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'toolset_denied', $decision->reason );
	}

	/** An explicit deny wins over a matching allow. */
	public function test_deny_wins_over_allow(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/delete-post', RiskTier::Destructive, 'content', 'delete_posts' ),
			new Scope( RiskTier::Destructive, array( 'system', 'content' ), array( 'xnetvn/delete-post' ), array( 'xnetvn/delete-post' ) )
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'ability_denied', $decision->reason );
	}

	/** An allow list hides every tool outside it. */
	public function test_allow_list_hides_other_tools(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/update-post', RiskTier::Write, 'content', 'edit_posts' ),
			new Scope( RiskTier::Write, array( 'system', 'content' ), array( 'xnetvn/get-session-info' ), array() )
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'ability_denied', $decision->reason );
	}

	/** A lowered site ceiling denies destructive tools. */
	public function test_site_ceiling_denies_destructive_tool(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/delete-post', RiskTier::Destructive, 'content', 'delete_posts' ),
			new Scope( RiskTier::Destructive, array( 'system', 'content' ) ),
			array( 'tier_ceiling' => 'write' )
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'site_tier', $decision->reason );
	}

	/** A disabled admin tier denies admin tools. */
	public function test_admin_off_denies_admin_tool(): void {
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/set-user-status', RiskTier::Admin, 'users', 'promote_users' ),
			new Scope( RiskTier::Admin, array( 'system', 'users' ) ),
			array(
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => false,
			)
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'admin_disabled', $decision->reason );
	}

	/** An enabled admin tier admits admin tools for an admin key. */
	public function test_admin_on_admits_admin_tool(): void {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$decision = self::decide(
			new AbilityDescriptor( 'xnetvn/set-user-status', RiskTier::Admin, 'users', 'promote_users' ),
			new Scope( RiskTier::Admin, array( 'system', 'users' ) ),
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			),
			$admin
		);
		self::assertTrue( $decision->allowed );
	}

	/** Credential failures are 401, never 403 or 200. */
	public function test_credential_failures_map_to_401(): void {
		foreach ( array(
			FailureReason::Missing,
			FailureReason::Malformed,
			FailureReason::UnknownKey,
			FailureReason::BadSecret,
			FailureReason::Revoked,
			FailureReason::Expired,
			FailureReason::OwnerMissing,
		) as $reason ) {
			self::assertSame( 401, $reason->status(), $reason->value );
		}
	}

	/** Owner and site failures are 403; budgets are 429; outages are 503. */
	public function test_denial_and_budget_statuses(): void {
		foreach ( array(
			FailureReason::OwnerForbidden,
			FailureReason::IpDenied,
			FailureReason::SiteDisabled,
			FailureReason::InsecureTransport,
			FailureReason::OriginDenied,
		) as $reason ) {
			self::assertSame( 403, $reason->status(), $reason->value );
		}
		self::assertSame( 429, FailureReason::RateLimited->status() );
		self::assertSame( 503, FailureReason::Unavailable->status() );
	}

	/** HTTP without an identity context cannot evaluate abilities. */
	public function test_http_without_context_denies(): void {
		$decision = ( new Policy( new MemorySettings() ) )->evaluate(
			new AbilityDescriptor( 'xnetvn/get-session-info', RiskTier::Read, 'system', 'read' ),
			null,
			'http'
		);
		self::assertFalse( $decision->allowed );
		self::assertSame( 'identity_required', $decision->reason );
	}

	/** STDIO keeps working without a delegated identity. */
	public function test_stdio_without_context_permits_read_tool(): void {
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $reader );
		$decision = ( new Policy( new MemorySettings() ) )->evaluate(
			new AbilityDescriptor( 'xnetvn/get-session-info', RiskTier::Read, 'system', 'read' ),
			null,
			'stdio'
		);
		self::assertTrue( $decision->allowed );
	}
}
