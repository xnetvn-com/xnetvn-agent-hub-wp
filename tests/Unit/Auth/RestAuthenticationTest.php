<?php
/**
 * Late authentication and failure semantics with isolated external WordPress functions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\FailureReason;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\StoredKey;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/**
 * Real token/HMAC/request logic; function substitutes are not hosted HTTP evidence.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RestAuthenticationTest extends TestCase {
	/** Request under test.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;
	/** Current external WordPress identity.
	 *
	 * @var int
	 */
	private int $current_user = 99;
	/** Shared budget boundary substitute.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limits;
	/** Credential repository boundary substitute.
	 *
	 * @var KeyRepository
	 */
	private KeyRepository $keys;
	/** Actual authenticator.
	 *
	 * @var Authenticator
	 */
	private Authenticator $auth;
	/** Mutable site settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/** Bind deterministic crypto and substitute only external storage/platform operations. */
	protected function setUp(): void {
		parent::setUp();
		require dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Isolated core request marker.
		$GLOBALS['wp']                 = (object) array( 'query_vars' => array( 'rest_route' => RouteMatcher::MCP_ROUTE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- An isolated unit process has no WordPress routing object.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'A', 43 );
		$_SERVER['REMOTE_ADDR']        = '203.0.113.7';
		Functions\when( 'get_option' )->justReturn(
			array(
				'id'    => 1,
				'value' => base64_encode( str_repeat( 'P', 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixed non-production pepper bytes.
			)
		);
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $id ): void {
				$this->current_user = $id;
			}
		);
		$this->keys = $this->createMock( KeyRepository::class );
		$key        = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Auth REST fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable( '@1791072000' ) );
		$this->keys->method( 'find_by_key_id' )->willReturn( new StoredKey( $key, KeyHasher::hash( str_repeat( 'A', 43 ), str_repeat( 'P', 32 ) ), 1 ) );
		$this->limits = $this->createMock( RateLimiter::class );
		$this->limits->method( 'check' )->willReturn( new RateLimitResult( true, 0, 99, 1.0 ) );
		$this->request  = new RequestScope();
		$this->settings = new MemorySettings();
		$this->auth     = $this->authenticator( $this->limits );
	}

	/** Remove all substitutes; process isolation owns the core constant and server globals. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	/** A late cached cookie is replaced only by the verified key owner. */
	public function test_late_authentication_replaces_cookie_actor(): void {
		self::assertTrue( $this->auth->rest_result( null ) );
		self::assertSame( 7, $this->current_user );
		self::assertSame( 7, $this->request->context->user_id );
		self::assertSame( 7, $this->auth->determine_user( 99 ) );
		self::assertTrue( $this->auth->rest_result( null ) );
		self::assertSame( 7, $this->current_user );
	}
	/** Earlier Origin/transport denials cannot be overwritten by a valid credential. */
	public function test_prior_error_is_preserved_without_authentication(): void {
		$error = new \WP_Error( 'origin_denied', 'Forbidden', array( 'status' => 403 ) );
		self::assertSame( $error, $this->auth->rest_result( $error ) );
		self::assertFalse( $this->request->attempted );
		self::assertSame( 99, $this->current_user );
	}
	/** Both early/late hooks leave other REST and management identities untouched. */
	public function test_other_route_does_not_authenticate(): void {
		$GLOBALS['wp']->query_vars['rest_route'] = '/xnetvn-agent-hub-wp/v1/keys';
		self::assertSame( 99, $this->auth->determine_user( 99 ) );
		self::assertNull( $this->auth->rest_result( null ) );
		self::assertFalse( $this->request->attempted );
	}
	/** Missing credentials clear cookie identity and return the generic401 challenge category. */
	public function test_missing_key_clears_cookie_identity(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$result = $this->auth->rest_result( null );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 401, $result->data['status'] );
		self::assertSame( 'Authentication required.', $result->message );
		self::assertSame( 0, $this->current_user );
		self::assertNull( $this->request->context );
	}
	/** Unavailable shared storage produces503, never an empty budget or success. */
	public function test_unavailable_storage_is_not_a_zero_budget(): void {
		$unavailable = $this->createMock( RateLimiter::class );
		$unavailable->method( 'check' )->willThrowException( new StorageException( 'Unavailable test backend' ) );
		$result = $this->authenticator( $unavailable )->rest_result( null );
		self::assertSame( 503, $result->data['status'] );
		self::assertSame( FailureReason::Unavailable, $this->request->failure );
		self::assertNull( $this->request->context );
		self::assertSame( 0, $this->current_user );
	}
	/** A exhausted key budget returns429 with the measured retry delay available. */
	public function test_key_budget_denies_verified_credentials(): void {
		$limited = $this->createMock( RateLimiter::class );
		$limited->method( 'check' )->willReturnCallback( static fn( string $scope ): RateLimitResult => 'key' === $scope ? new RateLimitResult( false, 75, 0, 121.0 ) : new RateLimitResult( true, 0, 10, 0.0 ) );
		$result = $this->authenticator( $limited )->rest_result( null );
		self::assertSame( 429, $result->data['status'] );
		self::assertSame( FailureReason::RateLimited, $this->request->failure );
		self::assertSame( 75, $this->request->budget->retry_after );
		self::assertNull( $this->request->context );
	}
	/** WordPress slashing is removed once at the request boundary before metadata is stored. */
	public function test_last_used_user_agent_is_unslashed(): void {
		Functions\when( 'wp_unslash' )->alias( static fn( string $value ): string => stripslashes( $value ) );
		$_SERVER['HTTP_USER_AGENT'] = 'Client \\"quoted\\" \\\\ path';
		$this->keys->expects( self::once() )->method( 'touch_last_used' )->with( 1, '203.0.113.7', 'Client "quoted" \\ path' );
		self::assertSame( 7, $this->auth->authenticate() );
	}
	/** Site disablement and insecure transport remain independent of a valid secret. */
	public function test_site_and_transport_can_deny_a_valid_key(): void {
		$this->settings->update( array( 'enabled' => false ) );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( FailureReason::SiteDisabled, $this->request->failure );
		$this->settings->update( array( 'enabled' => true ) );
		$this->request->reset();
		Functions\when( 'is_ssl' )->justReturn( false );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( FailureReason::InsecureTransport, $this->request->failure );
	}

	/**
	 * Reuse real request/header/crypto code with the selected external budget boundary.
	 *
	 * @param RateLimiter $limits Shared budget dependency.
	 * @return Authenticator
	 */
	private function authenticator( RateLimiter $limits ): Authenticator {
		return new Authenticator( $this->keys, new PepperStore(), $this->settings, new HeaderExtractor( $this->settings ), $limits, new TransportGuard( $this->settings ), $this->request, new FrozenClock( 1791072000 ) );
	}
}
