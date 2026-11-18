<?php
/**
 * Site authentication against real storage and WordPress user state.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_REST_Request;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteAuthenticator;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves accountless site identity, cookie clearing and refusal shapes on the real database. */
final class SiteAuthenticatorTest extends WP_UnitTestCase {
	/** Fixed clock. */
	private const NOW = 1791072000;
	/** Fixture peer. */
	private const PEER = '203.0.113.7';

	/** Original server values.
	 *
	 * @var array<mixed>
	 */
	private array $server;
	/** Original routing object.
	 *
	 * @var mixed
	 */
	private mixed $wp;
	/** Fixed clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Policy fixture.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Real site repository.
	 *
	 * @var WpdbSiteKeyRepository
	 */
	private WpdbSiteKeyRepository $repo;
	/** Shared limiter.
	 *
	 * @var SlidingWindowLimiter
	 */
	private SlidingWindowLimiter $limits;
	/** Pepper storage.
	 *
	 * @var PepperStore
	 */
	private PepperStore $peppers;
	/** Token codec.
	 *
	 * @var SiteTokenCodec
	 */
	private SiteTokenCodec $codec;
	/** Request state.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;
	/** Administrator used as fixture author.
	 *
	 * @var int
	 */
	private int $admin;
	/** Editor used as a second cookie state.
	 *
	 * @var int
	 */
	private int $editor;

	/** Install the real site-key schema, route markers and fixture policy. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Integration route marker.
		}
		$this->server                  = $_SERVER;
		$this->wp                      = $GLOBALS['wp'] ?? null;
		$_SERVER['HTTPS']              = 'on';
		$_SERVER['REMOTE_ADDR']        = self::PEER;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xns_aaaaaaaaaaaa_' . str_repeat( 'A', 43 );
		$this->clock                   = new FrozenClock( self::NOW );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$this->repo     = new WpdbSiteKeyRepository( $wpdb, $this->clock, 1 );
		$this->settings = new MemorySettings( array( 'allowed_origins' => array() ) );
		$this->limits   = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock );
		$this->peppers  = new PepperStore();
		$this->codec    = new SiteTokenCodec();
		$this->request  = new RequestScope();
		$this->admin    = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor   = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$GLOBALS['wp']  = (object) array( 'query_vars' => array( 'rest_route' => RouteMatcher::MCP_ROUTE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Integration route fixture.
	}

	/** Restore globals and remove only this test's site rows. */
	public function tear_down(): void {
		global $wpdb;
		$_SERVER = $this->server;
		if ( null === $this->wp ) {
			unset( $GLOBALS['wp'] );
		} else {
			$GLOBALS['wp'] = $this->wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the integration routing object.
		}
		$this->request->reset();
		wp_set_current_user( 0 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		parent::tear_down();
	}

	/** A valid site token never inherits or retains an Administrator cookie. */
	public function test_site_token_clears_cookie_identity(): void {
		[ $token ]                     = $this->issue();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$seen                          = array();
		foreach ( array( 0, $this->admin, $this->editor ) as $cookie ) {
			wp_set_current_user( $cookie );
			$this->request->reset();
			$auth = $this->authenticator();
			self::assertSame( 0, $auth->determine_user( $cookie ) );
			self::assertTrue( $auth->rest_result( true ) );
			self::assertSame( 0, get_current_user_id() );
			self::assertFalse( current_user_can( 'manage_options' ) );
			self::assertFalse( current_user_can( 'edit_posts' ) );
			self::assertSame( array(), array_diff_key( wp_get_current_user()->allcaps, array( 'exist' => true ) ) );
			$context = $this->request->site_current();
			self::assertNotNull( $context );
			$seen[] = array( $context->credential_ref(), $context->blog_id() );
		}
		self::assertCount( 1, array_unique( $seen, SORT_REGULAR ) );
	}

	/** The token routes to the site authenticator without a personal-key lookup. */
	public function test_site_token_skips_human_repository(): void {
		[ $token ]                     = $this->issue();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$human                         = $this->createMock( KeyRepository::class );
		$human->expects( self::never() )->method( 'find_by_key_id' );
		$auth = $this->authenticator( $human );
		self::assertSame( 0, $auth->determine_user( $this->admin ) );
		self::assertTrue( $auth->rest_result( true ) );
	}

	/** Unknown ids and bad secrets share the safe refusal and bind no context. */
	public function test_unknown_and_bad_secret_share_the_shape(): void {
		$codec        = $this->codec->generate();
		$unknown      = $this->service()->authenticate( 'xns_' . $codec->id->value . '_' . str_repeat( 'B', 43 ), self::PEER, null, true );
		[ , $stored ] = $this->issue();
		$bad          = $this->service()->authenticate( 'xns_' . $stored->key_id->value . '_' . str_repeat( 'B', 43 ), self::PEER, null, true );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( $unknown->get_error_code(), $bad->get_error_code() );
		self::assertSame( $unknown->get_error_data()['status'], $bad->get_error_data()['status'] );
		self::assertNull( $this->request->site_current() );
	}

	/** A recorded site failure is returned once and does not survive a reset. */
	public function test_site_failure_is_not_sticky(): void {
		[ $token, $credential ] = $this->issue();
		$stored                 = $this->repo->find_by_key_id( $credential->key_id );
		self::assertNotNull( $stored );
		$site = $this->createMock( SiteKeyRepository::class );
		$site->method( 'find_by_key_id' )->willReturnOnConsecutiveCalls( null, $stored );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$auth                          = $this->authenticator( null, $site );
		self::assertSame( 0, $auth->determine_user( $this->admin ) );
		self::assertInstanceOf( \WP_Error::class, $auth->rest_result( true ) );
		self::assertNull( $this->request->site_current() );
		$this->request->reset();
		self::assertSame( 0, $auth->determine_user( $this->admin ) );
		self::assertTrue( $auth->rest_result( true ) );
		self::assertSame( 0, get_current_user_id() );
	}

	/** A throwing human authenticated hook cannot leave a REST success behind. */
	public function test_human_hook_failure_does_not_become_success(): void {
		global $wpdb;
		$keys   = new WpdbKeyRepository( $wpdb, $this->clock );
		$pepper = $this->peppers->current();
		$secret = str_repeat( 'H', 43 );
		$owner  = $this->editor;
		$keys->insert( new KeyId( 'hijklmnopqrs' ), $owner, 'Hook fixture', new Scope( RiskTier::Read ), KeyHasher::hash( $secret, $pepper->reveal() ), $pepper->id );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_hijklmnopqrs_' . $secret;
		$auth                          = new Authenticator( $keys, $this->peppers, $this->settings, new HeaderExtractor( $this->settings ), $this->limits, new TransportGuard( $this->settings ), $this->request, $this->clock, null, $this->service() );
		$hook                          = static function (): void {
			throw new \RuntimeException( 'fixture' );
		};
		add_action( 'xnetvn_agent_hub_wp_key_authenticated', $hook );
		try {
			self::assertSame( 0, $auth->determine_user( $owner ) );
			$result = $auth->rest_result( true );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 503, $result->get_error_data()['status'] );
			self::assertNull( $this->request->context );
			self::assertNull( $this->request->site_current() );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_key_authenticated', $hook );
		}
	}

	/** A direct dual-principal write fails closed before any execution. */
	public function test_conflicting_principals_fail_closed(): void {
		[ $token ]                     = $this->issue();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$auth                          = $this->authenticator();
		self::assertSame( 0, $auth->determine_user( $this->admin ) );
		$site = $this->request->site_current();
		self::assertNotNull( $site );
		$api                         = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $this->admin, 'Conflict fixture', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . self::NOW ) );
		$this->request->context      = new AuthContext( $api, '0123456789abcdef' );
		$this->request->site_context = $site;
		$result                      = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertNull( $this->request->site_current() );
	}

	/** The site token never authenticates management or a non-exact MCP neighbor. */
	public function test_management_and_non_exact_route_probes(): void {
		[ $token ]                     = $this->issue();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$cookies                       = $_COOKIE;
		$_COOKIE                       = array();
		$GLOBALS['wp']                 = $this->wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the real routing object for REST probes.
		try {
			wp_set_current_user( 0 );
			$management = new WP_REST_Request( 'GET', '/xnetvn-agent-hub-wp/v1/keys' );
			$management->set_header( 'Authorization', 'Bearer ' . $token );
			self::assertContains( rest_do_request( $management )->get_status(), array( 401, 403 ) );
			$extra = new WP_REST_Request( 'GET', '/xnetvn-agent-hub-wp/mcp/extra' );
			$extra->set_header( 'Authorization', 'Bearer ' . $token );
			self::assertSame( 404, rest_do_request( $extra )->get_status() );
		} finally {
			$_COOKIE = $cookies;
		}
	}

	/** Disabled site, insecure transport and an unknown origin fail closed. */
	public function test_guard_failures(): void {
		[ $token ] = $this->issue();
		$this->settings->update( array( 'enabled' => false ) );
		$this->assertError( 'forbidden', 403, $this->service()->authenticate( $token, self::PEER, null, true ) );
		$this->settings->update( array( 'enabled' => true ) );
		$this->assertError( 'forbidden', 403, $this->service()->authenticate( $token, self::PEER, null, false ) );
		$this->assertError( 'origin_denied', 403, $this->service()->authenticate( $token, self::PEER, 'https://evil.test', true ) );
	}

	/** Revoked, expired and IP-denied credentials are refused. */
	public function test_credential_state_failures(): void {
		[ $token, $stored ] = $this->issue();
		self::assertTrue( $this->repo->revoke( $stored->id, $this->admin, 'fixture' ) );
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( $token, self::PEER, null, true ) );
		[ $expired ] = $this->issue( array( 'expires_at' => new DateTimeImmutable( '@' . ( self::NOW - 1 ) ) ) );
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( $expired, self::PEER, null, true ) );
		[ $narrow ] = $this->issue( array( 'ip_allowlist' => array( '10.0.0.0/8' ) ) );
		$this->assertError( 'forbidden', 403, $this->service()->authenticate( $narrow, self::PEER, null, true ) );
	}

	/** The per-peer failure budget turns a repeated bad credential into 429. */
	public function test_failure_budget_is_enforced(): void {
		$this->settings->update( array( 'rate_limit_auth_fail_per_min_ip' => 1 ) );
		$bad  = 'xns_aaaaaaaaaaaa_' . str_repeat( 'A', 43 );
		$peer = '198.51.100.9';
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( $bad, $peer, null, true ) );
		$this->assertError( 'rate_limited', 429, $this->service()->authenticate( $bad, $peer, null, true ) );
	}

	/** The key bucket is charged only after a valid secret and never reveals key existence. */
	public function test_key_budget_does_not_reveal_key_existence(): void {
		$this->settings->update( array( 'rate_limit_key_per_min' => 1 ) );
		[ $token, $credential ] = $this->issue();
		$peer                   = '198.51.100.10';
		self::assertNotInstanceOf( \WP_Error::class, $this->service()->authenticate( $token, $peer, null, true ) );
		$unknown = 'xns_' . $this->codec->generate()->id->value . '_' . str_repeat( 'B', 43 );
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( $unknown, $peer, null, true ) );
		$wrong = 'xns_' . $credential->key_id->value . '_' . str_repeat( 'B', 43 );
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( $wrong, $peer, null, true ) );
		$this->assertError( 'rate_limited', 429, $this->service()->authenticate( $token, $peer, null, true ) );
	}

	/** Human tokens and non-exact routes never bind machine identity. */
	public function test_human_tokens_and_foreign_routes_are_not_site_authenticated(): void {
		$this->assertError( 'authentication_required', 401, $this->service()->authenticate( 'xnv_abcdefghijkl_' . str_repeat( 'A', 43 ), self::PEER, null, true ) );
		self::assertFalse( RouteMatcher::matches( RouteMatcher::MCP_ROUTE . '/extra', true ) );
		$human = $this->createMock( KeyRepository::class );
		$human->expects( self::never() )->method( 'find_by_key_id' );
		$site = $this->createMock( SiteKeyRepository::class );
		$site->expects( self::never() )->method( 'find_by_key_id' );
		$GLOBALS['wp']->query_vars['rest_route'] = '/xnetvn-agent-hub-wp/keys'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Integration route fixture.
		$auth                                    = $this->authenticator( $human, $site );
		self::assertSame( $this->admin, $auth->determine_user( $this->admin ) );
		self::assertNull( $this->request->site_current() );
	}

	/**
	 * Build the site authenticator.
	 *
	 * @param SiteKeyRepository|null $site Optional site repository.
	 * @return SiteAuthenticator
	 */
	private function service( ?SiteKeyRepository $site = null ): SiteAuthenticator {
		return new SiteAuthenticator( $site ?? $this->repo, $this->peppers, $this->settings, $this->limits, new TransportGuard( $this->settings ), $this->clock );
	}

	/**
	 * Build the shared human/site router.
	 *
	 * @param KeyRepository|null     $human Optional human repository.
	 * @param SiteKeyRepository|null $site Optional site repository.
	 * @return Authenticator
	 */
	private function authenticator( ?KeyRepository $human = null, ?SiteKeyRepository $site = null ): Authenticator {
		return new Authenticator( $human ?? $this->createMock( KeyRepository::class ), $this->peppers, $this->settings, new HeaderExtractor( $this->settings ), $this->limits, new TransportGuard( $this->settings ), $this->request, $this->clock, null, $this->service( $site ) );
	}

	/**
	 * Issue one real site credential.
	 *
	 * @param array{ip_allowlist?:array<string>,expires_at?:DateTimeImmutable} $overrides Scope overrides.
	 * @return array{0:string,1:\XNetVN\AgentHubWP\Domain\SiteCredential}
	 */
	private function issue( array $overrides = array() ): array {
		$generated = $this->codec->generate();
		$pepper    = $this->peppers->current();
		$scope     = new SiteScope(
			RiskTier::Read,
			array( 'content' ),
			array( 'xnetvn/list-posts' ),
			array(),
			array(),
			$overrides['ip_allowlist'] ?? array(),
			$overrides['expires_at'] ?? new DateTimeImmutable( '@' . ( self::NOW + 90 * DAY_IN_SECONDS ) )
		);
		$stored    = $this->repo->insert( $generated->id, $scope, 'Integration fixture', $generated->hash_with( $pepper ), $pepper->id, $this->admin );
		$token     = $generated->reveal();
		return array( $token, $stored );
	}

	/**
	 * Assert one safe error shape.
	 *
	 * @param string $code Expected suffix.
	 * @param int    $status Expected status.
	 * @param mixed  $value Actual value.
	 * @return void
	 */
	private function assertError( string $code, int $status, mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $code, $value->get_error_code() );
		self::assertSame( $status, $value->get_error_data()['status'] );
	}
}
