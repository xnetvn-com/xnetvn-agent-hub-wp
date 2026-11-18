<?php
/**
 * Site credential authentication and cookie isolation tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteAuthenticator;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Auth\StoredSiteKey;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
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
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only budget double.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant shim.
	define( 'DAY_IN_SECONDS', 86400 );
}

/** Mutable budget decision. */
final class SiteAuthBudget implements RateLimiter {
	/** Whether the budget passes.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/** Recorded checks.
	 *
	 * @var array<array{0:string,1:string,2:int,3:bool}>
	 */
	public array $calls = array();

	/**
	 * Return the configured decision.
	 *
	 * @param string $scope Scope.
	 * @param string $id Identity.
	 * @param int    $limit Limit.
	 * @param bool   $increment Increment.
	 * @return RateLimitResult
	 */
	public function check( string $scope, string $id, int $limit, bool $increment = true ): RateLimitResult {
		$this->calls[] = array( $scope, $id, $limit, $increment );
		return new RateLimitResult( $this->allowed, 0, $this->allowed ? 99 : 0, 1.0 );
	}
}

/** Pins the site auth order, refusal shapes and cookie isolation. */
final class SiteAuthenticatorTest extends TestCase {
	/** Fixed clock. */
	private const NOW = 1791072000;
	/** Fixed peer. */
	private const PEER = '203.0.113.7';

	/** Current stub user.
	 *
	 * @var int
	 */
	private int $current_user = 0;
	/** Fixed clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Budget double.
	 *
	 * @var SiteAuthBudget
	 */
	private SiteAuthBudget $budget;
	/** Token codec.
	 *
	 * @var SiteTokenCodec
	 */
	private SiteTokenCodec $codec;
	/** Pepper store.
	 *
	 * @var PepperStore
	 */
	private PepperStore $peppers;
	/** Generated token.
	 *
	 * @var string
	 */
	private string $token;
	/** Stored credential.
	 *
	 * @var StoredSiteKey
	 */
	private StoredSiteKey $stored;
	/** Request state.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;

	/** Install WordPress substitutes and one valid credential. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		if ( ! defined( 'REST_REQUEST' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Isolated core request marker.
			define( 'REST_REQUEST', true );
		}
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn(
			array(
				'id'    => 1,
				'value' => base64_encode( str_repeat( 'P', 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixed non-production pepper bytes.
			)
		);
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $id ): void {
				$this->current_user = $id;
			}
		);
		$_SERVER['REMOTE_ADDR'] = self::PEER;
		$this->clock            = new FrozenClock( self::NOW );
		$this->settings         = new MemorySettings( array( 'allowed_origins' => array() ) );
		$this->budget           = new SiteAuthBudget();
		$this->codec            = new SiteTokenCodec();
		$this->peppers          = new PepperStore();
		$generated              = $this->codec->generate();
		$this->token            = $generated->reveal();
		$parsed                 = SiteTokenCodec::parse( $this->token );
		self::assertNotNull( $parsed );
		$scope        = new SiteScope(
			RiskTier::Write,
			array( 'content' ),
			array( 'xnetvn/create-post' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( self::NOW + 90 * DAY_IN_SECONDS ) )
		);
		$credential   = new SiteCredential( 1, $parsed->id, 1, 'Fixture site key', $scope, new DateTimeImmutable( '@' . self::NOW ), null, 7 );
		$this->stored = new StoredSiteKey( $credential, $generated->hash_with( $this->peppers->current() ), 1 );
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_XNETVN_API_KEY'], $GLOBALS['wp'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A valid token returns a bounded site context without a human user. */
	public function test_valid_token_returns_site_context(): void {
		$context = $this->service()->authenticate( $this->token, self::PEER, null, true );
		self::assertNotInstanceOf( \WP_Error::class, $context );
		self::assertSame( 1, $context->blog_id() );
		self::assertSame( 'xns:' . $this->stored->key->key_id->value, $context->credential_ref() );
		self::assertNotSame( '', $context->request_id );
		self::assertSame( self::PEER, $context->client_ip );
		self::assertSame( array( 'auth-fail', 'key' ), array_column( $this->budget->calls, 0 ) );
		self::assertSame( 'site:1:' . $this->stored->key->key_id->value, $this->budget->calls[1][1] );
	}

	/** Disabled site, insecure transport and a disallowed origin fail closed. */
	public function test_guard_failures(): void {
		$this->settings->update( array( 'enabled' => false ) );
		$this->assertError( 'forbidden', $this->service()->authenticate( $this->token, self::PEER, null, true ) );
		$this->settings->update( array( 'enabled' => true ) );
		$this->assertError( 'forbidden', $this->service()->authenticate( $this->token, self::PEER, null, false ) );
		$this->assertError( 'origin_denied', $this->service()->authenticate( $this->token, self::PEER, 'https://evil.test', true ) );
	}

	/** The shared failure budget returns 429 before a key lookup. */
	public function test_rate_limited_peer(): void {
		$this->budget->allowed = false;
		$this->assertError( 'rate_limited', $this->service()->authenticate( $this->token, self::PEER, null, true ) );
		self::assertSame( 1, count( $this->budget->calls ) );
	}

	/** Revoked, expired, wrong-blog and IP-denied credentials are refused. */
	public function test_credential_state_failures(): void {
		$this->assertError( 'authentication_required', $this->service( $this->repoFor( $this->revoked() ) )->authenticate( $this->token, self::PEER, null, true ) );
		$this->assertError( 'authentication_required', $this->service( $this->repoFor( $this->expired() ) )->authenticate( $this->token, self::PEER, null, true ) );
		$this->assertError( 'authentication_required', $this->service( $this->repoFor( $this->otherBlog() ) )->authenticate( $this->token, self::PEER, null, true ) );
		$this->assertError( 'forbidden', $this->service( $this->repoFor( $this->ipDenied() ) )->authenticate( $this->token, self::PEER, null, true ) );
	}

	/** Unknown ids and bad secrets have the same observable refusal. */
	public function test_unknown_and_bad_secret_share_the_shape(): void {
		$unknown = $this->service( $this->repoFor( null ) )->authenticate( $this->token, self::PEER, null, true );
		$bad     = $this->service()->authenticate( 'xns_' . $this->stored->key->key_id->value . '_' . str_repeat( 'B', 43 ), self::PEER, null, true );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( $unknown->get_error_code(), $bad->get_error_code() );
		self::assertSame( $unknown->data['status'], $bad->data['status'] );
	}

	/** Malformed tokens and empty peers are refused before binding. */
	public function test_malformed_and_empty_peer(): void {
		$this->assertError( 'authentication_required', $this->service()->authenticate( 'xns_bad', self::PEER, null, true ) );
		$this->assertError( 'authentication_required', $this->service()->authenticate( $this->token, '', null, true ) );
	}

	/** The Authenticator routes xns_ to the site path and clears an early cookie user. */
	public function test_authenticator_clears_cookie_identity(): void {
		$GLOBALS['wp']                 = (object) array( 'query_vars' => array( 'rest_route' => '/xnetvn-agent-hub-wp/mcp' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit process.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		$this->current_user            = 7;
		$auth                          = $this->authenticator();
		self::assertSame( 0, $auth->determine_user( 7 ) );
		self::assertTrue( $auth->rest_result( true ) );
		self::assertSame( 0, $this->current_user );
	}

	/** A site failure is returned and cannot leak into a later attempt after reset. */
	public function test_site_failure_is_not_sticky(): void {
		$GLOBALS['wp']                 = (object) array( 'query_vars' => array( 'rest_route' => '/xnetvn-agent-hub-wp/mcp' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit process.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		$repo                          = $this->createMock( SiteKeyRepository::class );
		$repo->method( 'find_by_key_id' )->willReturnOnConsecutiveCalls( null, $this->stored );
		$auth = $this->authenticator( $repo );
		self::assertSame( 0, $auth->determine_user( 7 ) );
		self::assertInstanceOf( \WP_Error::class, $auth->rest_result( true ) );
		$api = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Pre-existing fixture', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . self::NOW ) );
		$this->request->bind_human( new AuthContext( $api, '0123456789abcdef' ) );
		self::assertInstanceOf( \WP_Error::class, $auth->rest_result( true ) );
		self::assertNull( $this->request->context );
		self::assertNull( $this->request->site_current() );
		$this->request->reset();
		self::assertSame( 0, $auth->determine_user( 7 ) );
		self::assertTrue( $auth->rest_result( true ) );
	}

	/** A conflict present before the first attempt fails closed without lookup or binding. */
	public function test_fresh_conflict_fails_closed(): void {
		$GLOBALS['wp']                 = (object) array( 'query_vars' => array( 'rest_route' => \XNetVN\AgentHubWP\Http\RouteMatcher::MCP_ROUTE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit process.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		$human                         = $this->createMock( KeyRepository::class );
		$human->expects( self::never() )->method( 'find_by_key_id' );
		$site = $this->createMock( SiteKeyRepository::class );
		$site->expects( self::never() )->method( 'find_by_key_id' );
		$auth                        = $this->authenticator( $site, $human );
		$api                         = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Fresh conflict fixture', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . self::NOW ) );
		$this->request->context      = new AuthContext( $api, '0123456789abcdef' );
		$this->request->site_context = new SiteAuthContext( $this->stored->key, '0123456789abcdef', self::PEER );
		self::assertSame( 0, $auth->determine_user( 7 ) );
		self::assertNull( $this->request->site_current() );
		self::assertNull( $this->request->current() );
		$result = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->data['status'] );
	}

	/** A fallback header alongside a primary is rejected even when fallback extraction is disabled. */
	public function test_fallback_ambiguity_rejected_when_disabled(): void {
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => \XNetVN\AgentHubWP\Http\RouteMatcher::MCP_ROUTE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit process.
		$this->settings->update( array( 'fallback_header_enabled' => false ) );
		$_SERVER['HTTP_AUTHORIZATION']    = 'Bearer ' . $this->token;
		$_SERVER['HTTP_X_XNETVN_API_KEY'] = 'Bearer ' . $this->token;
		$auth                             = $this->authenticator();
		self::assertSame( 0, $auth->determine_user( 7 ) );
		self::assertSame( 'malformed', $this->request->failure?->value );
		self::assertNull( $this->request->site_current() );
		$result = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 401, $result->data['status'] );
	}

	/** A direct dual-principal write fails closed at the REST boundary. */
	public function test_conflicting_principals_fail_closed(): void {
		$GLOBALS['wp']                 = (object) array( 'query_vars' => array( 'rest_route' => \XNetVN\AgentHubWP\Http\RouteMatcher::MCP_ROUTE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit process.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		$auth                          = $this->authenticator();
		self::assertSame( 0, $auth->determine_user( 7 ) );
		$site = $this->request->site_current();
		self::assertNotNull( $site );
		$api                         = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Conflict fixture', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . self::NOW ) );
		$this->request->context      = new AuthContext( $api, '0123456789abcdef' );
		$this->request->site_context = $site;
		self::assertSame( 0, $auth->determine_user( 7 ) );
		$result = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->data['status'] );
		self::assertNull( $this->request->site_current() );
	}

	/**
	 * Build the authenticator service.
	 *
	 * @param SiteKeyRepository|null $repo Optional repository.
	 * @return SiteAuthenticator
	 */
	private function service( ?SiteKeyRepository $repo = null ): SiteAuthenticator {
		return new SiteAuthenticator( $repo ?? $this->repoFor( $this->stored ), $this->peppers, $this->settings, $this->budget, new TransportGuard( $this->settings ), $this->clock );
	}

	/**
	 * Build the human/site router.
	 *
	 * @param SiteKeyRepository|null $repo Optional site repository.
	 * @param KeyRepository|null     $human Optional human repository.
	 * @return Authenticator
	 */
	private function authenticator( ?SiteKeyRepository $repo = null, ?KeyRepository $human = null ): Authenticator {
		$this->request = new RequestScope();
		return new Authenticator( $human ?? $this->createMock( KeyRepository::class ), $this->peppers, $this->settings, new HeaderExtractor( $this->settings ), $this->budget, new TransportGuard( $this->settings ), $this->request, $this->clock, null, $this->service( $repo ) );
	}

	/**
	 * Build a repository returning one stored value.
	 *
	 * @param StoredSiteKey|null $stored Stored value.
	 * @return SiteKeyRepository
	 */
	private function repoFor( ?StoredSiteKey $stored ): SiteKeyRepository {
		$repo = $this->createMock( SiteKeyRepository::class );
		$repo->method( 'find_by_key_id' )->willReturn( $stored );
		return $repo;
	}

	/**
	 * Return a revoked credential.
	 *
	 * @return StoredSiteKey
	 */
	private function revoked(): StoredSiteKey {
		return $this->rewrite( null, 1, array(), new DateTimeImmutable( '@' . self::NOW ) );
	}

	/**
	 * Return an expired credential.
	 *
	 * @return StoredSiteKey
	 */
	private function expired(): StoredSiteKey {
		return $this->rewrite( new DateTimeImmutable( '@' . ( self::NOW - 1 ) ) );
	}

	/**
	 * Return a credential for another blog.
	 *
	 * @return StoredSiteKey
	 */
	private function otherBlog(): StoredSiteKey {
		return $this->rewrite( null, 2 );
	}

	/**
	 * Return a credential with a denying IP allowlist.
	 *
	 * @return StoredSiteKey
	 */
	private function ipDenied(): StoredSiteKey {
		return $this->rewrite( null, 1, array( '10.0.0.0/8' ) );
	}

	/**
	 * Rebuild the stored credential with one changed property.
	 *
	 * @param DateTimeImmutable|null $expiry Expiry.
	 * @param int                    $blog Blog id.
	 * @param array<string>          $ips IP allowlist.
	 * @param DateTimeImmutable|null $revoked Revocation time.
	 * @return StoredSiteKey
	 */
	private function rewrite( ?DateTimeImmutable $expiry, int $blog = 1, array $ips = array(), ?DateTimeImmutable $revoked = null ): StoredSiteKey {
		$key    = $this->stored->key;
		$scope  = new SiteScope( $key->scope->tier_ceiling, $key->scope->toolsets, $key->scope->allowed_abilities, $key->scope->denied_abilities, $key->scope->permissions, $ips, $expiry ?? $key->scope->expires_at );
		$row    = new SiteCredential( $key->id, $key->key_id, $blog, $key->name, $scope, $key->created_at, $revoked, $key->created_by );
		$pepper = $this->peppers->current();
		$parsed = SiteTokenCodec::parse( $this->token );
		$hash   = null === $parsed ? $this->codec->generate()->hash_with( $pepper ) : KeyHasher::hash( $parsed->secret->reveal(), $pepper->reveal() );
		return new StoredSiteKey( $row, $hash, $pepper->id );
	}

	/**
	 * Assert one safe error.
	 *
	 * @param string $code Expected suffix.
	 * @param mixed  $value Actual value.
	 * @return void
	 */
	private function assertError( string $code, mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $code, $value->get_error_code() );
	}
}
