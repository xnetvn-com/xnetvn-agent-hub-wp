<?php
/**
 * Site gateway admission, scope-hint and bound-path tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Mcp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Auth\StoredSiteKey;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Minimal core substitutes and a fixture definition for the unit harness.

if ( ! class_exists( 'WP_REST_Server', false ) ) {
	/** Minimal REST server substitute for the unit harness. */
	class WP_REST_Server {
	}
}

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	/** Minimal REST request substitute for the unit harness. */
	class WP_REST_Request {
		/**
		 * Bind the raw body.
		 *
		 * @param string $body Raw body.
		 */
		public function __construct( private string $body = '' ) {
		}

		/**
		 * Return the exact MCP route.
		 *
		 * @return string
		 */
		public function get_route(): string {
			return RouteMatcher::MCP_ROUTE;
		}

		/**
		 * Return the raw body.
		 *
		 * @return string
		 */
		public function get_body(): string {
			return $this->body;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server', false ) ) {
	class_alias( __NAMESPACE__ . '\\WP_REST_Server', 'WP_REST_Server' );
}

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class_alias( __NAMESPACE__ . '\\WP_REST_Request', 'WP_REST_Request' );
}

if ( ! class_exists( 'WP_Error', false ) ) {
	require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
}

/** The gateway admits only a verified site principal or the unchanged human principal. */
final class SiteGatewayTest extends TestCase {

	/** Blog under test. */
	private const BLOG = 1;

	/** Set up WordPress function mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		if ( ! defined( 'XNETVN_AGENT_HUB_WP_VERSION' ) ) {
			define( 'XNETVN_AGENT_HUB_WP_VERSION', '1.0.0' );
		}
		if ( ! defined( 'REST_REQUEST' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core defines this request constant.
			define( 'REST_REQUEST', true );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate the core route in this unit harness.
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => RouteMatcher::MCP_ROUTE ) );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_XNETVN_API_KEY'] );
	}

	/** Reset WordPress function mocks and globals. */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_XNETVN_API_KEY'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Only the matching human or a same-blog user-0 site principal is admitted. */
	public function test_admission_matrix_denies_every_other_state(): void {
		$human = $this->human_context( 5 );
		$site  = $this->site_context( self::BLOG );

		$request = new RequestScope();
		$request->bind_human( $human );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		self::assertTrue( $this->admits( $this->gateway( $request ) ) );

		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		self::assertFalse( $this->admits( $this->gateway( $request ) ) );

		$request = new RequestScope();
		$request->bind_site( $site );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		self::assertTrue( $this->admits( $this->gateway( $request ) ) );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		self::assertFalse( $this->admits( $this->gateway( $request ) ) );

		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		self::assertFalse( $this->admits( $this->gateway( $request ) ) );

		$conflict               = new RequestScope();
		$conflict->context      = $human;
		$conflict->site_context = $site;
		self::assertFalse( $this->admits( $this->gateway( $conflict ) ) );

		self::assertFalse( $this->admits( $this->gateway( new RequestScope() ) ) );
	}

	/** The transport callback is the actual admission closure. */
	public function test_create_transport_callback_uses_the_admission_contract(): void {
		$site = $this->site_context( self::BLOG );

		$request = new RequestScope();
		$request->bind_site( $site );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		$adapter = $this->fake_adapter();
		$this->gateway( $request )->create( $adapter );
		self::assertTrue( ( $adapter->args[12] )() );

		$conflict               = new RequestScope();
		$conflict->context      = $this->human_context( 5 );
		$conflict->site_context = $site;
		$adapter                = $this->fake_adapter();
		$this->gateway( $conflict )->create( $adapter );
		self::assertFalse( ( $adapter->args[12] )() );

		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$adapter = $this->fake_adapter();
		$this->gateway( $request )->create( $adapter );
		self::assertFalse( ( $adapter->args[12] )() );

		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$adapter = $this->fake_adapter();
		$this->gateway( $request )->create( $adapter );
		self::assertFalse( ( $adapter->args[12] )() );

		$adapter = $this->fake_adapter();
		$this->gateway( new RequestScope() )->create( $adapter );
		self::assertFalse( ( $adapter->args[12] )() );
	}

	/** An `xns_` hint uses only the site repository; a missing repository is a safe null. */
	public function test_site_hint_never_reads_the_human_repository(): void {
		$site                          = $this->site_context( self::BLOG );
		$scope                         = $site->credential->scope;
		$token                         = 'xns_' . str_repeat( 'a', 12 ) . '_' . str_repeat( 'A', 43 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

		$site_repo = $this->createMock( SiteKeyRepository::class );
		$site_repo->expects( self::once() )->method( 'find_by_key_id' )->willReturn( new StoredSiteKey( $site->credential, str_repeat( 'a', 64 ), 1 ) );
		$human_repo = $this->createMock( KeyRepository::class );
		$human_repo->expects( self::never() )->method( 'find_by_key_id' );

		self::assertSame( $scope, $this->scope_hint( $this->gateway( new RequestScope(), $site_repo, $human_repo ) ) );
		self::assertNull( $this->scope_hint( $this->gateway( new RequestScope(), null, $human_repo ) ) );

		$human_once = $this->createMock( KeyRepository::class );
		$human_once->expects( self::once() )->method( 'find_by_key_id' )->willReturn( null );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_' . str_repeat( 'a', 12 ) . '_' . str_repeat( 'A', 43 );
		self::assertNull( $this->scope_hint( $this->gateway( new RequestScope(), $site_repo, $human_once ) ) );
	}

	/** A malformed `xns_` token reads neither repository and falls back safely. */
	public function test_malformed_site_token_reads_no_repository(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xns_not-a-valid-token';
		$site_repo                     = $this->createMock( SiteKeyRepository::class );
		$site_repo->expects( self::never() )->method( 'find_by_key_id' );
		$human_repo = $this->createMock( KeyRepository::class );
		$human_repo->expects( self::never() )->method( 'find_by_key_id' );

		$registry = new Registry( new Policy( new MemorySettings() ), new RequestScope(), new OutputPipeline( new MemorySettings() ) );
		$registry->add( $this->definition( 'xnetvn/get-site-info' ) );
		$gateway = new ServerGateway( $registry, new RequestScope(), new MemorySettings(), static fn(): string => '0.7.0', $human_repo, new HeaderExtractor( new MemorySettings( array( 'fallback_header_enabled' => false ) ) ), $site_repo );

		self::assertNull( $this->scope_hint( $gateway ) );
		self::assertSame( array( 'xnetvn/get-site-info' ), $this->tool_names( $gateway ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xns_' . str_repeat( 'a', 12 ) . '_' . str_repeat( 'A', 43 );
		$unwired                       = new ServerGateway( $registry, new RequestScope(), new MemorySettings(), static fn(): string => '0.7.0', null, new HeaderExtractor( new MemorySettings( array( 'fallback_header_enabled' => false ) ) ), null );
		self::assertNull( $this->scope_hint( $unwired ) );
		self::assertSame( array( 'xnetvn/get-site-info' ), $this->tool_names( $unwired ) );
	}

	/** A site scope is dispatched to the site catalog and differs from the human catalog. */
	public function test_tool_names_dispatch_the_site_scope(): void {
		$dispatcher = $this->dispatcher();
		$registry   = new Registry( new Policy( new MemorySettings() ), new RequestScope(), new OutputPipeline( new MemorySettings() ), null, null, new SitePolicy( new MemorySettings() ), $dispatcher );
		$registry->add( $this->definition( 'xnetvn/get-site-info' ) );
		$registry->add( $this->definition( 'xnetvn/list-posts' ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?\XNetVN\AgentHubWP\Domain\AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		$dispatcher->register( 'xnetvn/get-site-info', static fn( array $arguments, SiteAuthContext $context ): array => array( 'id' => 7 ) ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The dispatcher contract requires both parameters.

		$site                          = $this->site_context( self::BLOG );
		$token                         = 'xns_' . str_repeat( 'a', 12 ) . '_' . str_repeat( 'A', 43 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$site_repo                     = $this->createMock( SiteKeyRepository::class );
		$site_repo->method( 'find_by_key_id' )->willReturn( new StoredSiteKey( $site->credential, str_repeat( 'a', 64 ), 1 ) );
		$request = new RequestScope();
		$request->bind_site( $site );
		$gateway = new ServerGateway( $registry, $request, new MemorySettings( array( 'fallback_header_enabled' => false ) ), static fn(): string => '0.7.0', null, new HeaderExtractor( new MemorySettings( array( 'fallback_header_enabled' => false ) ) ), $site_repo );

		self::assertSame( $registry->names_for_site_scope( $site->credential->scope ), $this->tool_names( $gateway ) );
		self::assertSame( array( 'xnetvn/get-site-info' ), $this->tool_names( $gateway ) );
		self::assertNotSame( $registry->names(), $this->tool_names( $gateway ) );

		$human = new RequestScope();
		$human->bind_human( $this->human_context( 5 ) );
		$gateway = new ServerGateway( $registry, $human, new MemorySettings(), static fn(): string => '0.7.0' );
		self::assertSame( array( 'xnetvn/get-site-info', 'xnetvn/list-posts' ), $this->tool_names( $gateway ) );
	}

	/** The site path keeps the permitted tool and never reads a human identity. */
	public function test_tools_list_and_pre_call_site_paths(): void {
		$dispatcher = $this->dispatcher();
		$site       = $this->site_context( self::BLOG );
		$request    = new RequestScope();
		$request->bind_site( $site );
		$registry = new Registry( new Policy( new MemorySettings() ), $request, new OutputPipeline( new MemorySettings() ), null, null, new SitePolicy( new MemorySettings() ), $dispatcher );
		$registry->add( $this->definition( 'xnetvn/get-site-info' ) );
		$registry->add( $this->definition( 'xnetvn/list-posts' ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?\XNetVN\AgentHubWP\Domain\AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		$dispatcher->register( 'xnetvn/get-site-info', static fn( array $arguments, SiteAuthContext $context ): array => array( 'id' => 7 ) ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The dispatcher contract requires both parameters.

		$token                         = 'xns_' . str_repeat( 'a', 12 ) . '_' . str_repeat( 'A', 43 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$site_repo                     = $this->createMock( SiteKeyRepository::class );
		$site_repo->method( 'find_by_key_id' )->willReturn( new StoredSiteKey( $site->credential, str_repeat( 'a', 64 ), 1 ) );
		$gateway = new ServerGateway( $registry, $request, new MemorySettings( array( 'fallback_header_enabled' => false ) ), static fn(): string => '0.7.0', null, new HeaderExtractor( new MemorySettings( array( 'fallback_header_enabled' => false ) ) ), $site_repo );
		$server  = $this->fake_server();

		Functions\expect( 'get_current_user_id' )->never();
		$tools  = array( $this->fake_tool( 'xnetvn-get-site-info' ), $this->fake_tool( 'xnetvn-list-posts' ) );
		$listed = $gateway->tools_list( $tools, $server, new \stdClass() );
		self::assertCount( 1, $listed );
		self::assertSame( $tools[0], $listed[0] );
		self::assertSame( array( 'id' => 7 ), $gateway->pre_call( array( 'id' => 7 ), 'xnetvn-get-site-info', $tools[0], $server ) );
	}

	/** The result and body bounds never require a human identity. */
	public function test_bound_paths_do_not_read_a_human_identity(): void {
		Functions\expect( 'get_current_user_id' )->never();
		$gateway = $this->gateway( new RequestScope() );
		$server  = $this->fake_server();

		self::assertSame( array( 'ok' => 1 ), $gateway->tool_result( array( 'ok' => 1 ), array(), 'xnetvn/get-site-info', new \stdClass(), $server ) );

		$sanitized = $gateway->tool_result( new \WP_Error( 'rest_forbidden', 'Provider detail' ), array(), 'xnetvn/get-site-info', new \stdClass(), $server );
		self::assertInstanceOf( \WP_Error::class, $sanitized );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $sanitized->get_error_code() );
		self::assertStringNotContainsString( 'Provider detail', $sanitized->message );
		self::assertStringNotContainsString( 'rest_forbidden', $sanitized->get_error_code() . $sanitized->message );

		$rest_server = new \WP_REST_Server();
		self::assertNull( $gateway->input_guard( null, $rest_server, new \WP_REST_Request( '{"jsonrpc":"2.0"}' ) ) );

		$oversized = $gateway->input_guard( null, $rest_server, new \WP_REST_Request( str_repeat( 'a', 16777217 ) ) );
		self::assertInstanceOf( \WP_Error::class, $oversized );
		self::assertSame( 'xnetvn_agent_hub_wp_payload_limit', $oversized->get_error_code() );
		self::assertSame( 413, $oversized->data['status'] );

		$invalid = $gateway->input_guard( null, $rest_server, new \WP_REST_Request( '{' ) );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_payload', $invalid->get_error_code() );
		self::assertSame( 400, $invalid->data['status'] );
	}

	/**
	 * Build the gateway with optional credential stores.
	 *
	 * @param RequestScope           $request   Request boundary.
	 * @param SiteKeyRepository|null $site_keys Site store.
	 * @param KeyRepository|null     $keys      Human store.
	 * @return ServerGateway
	 */
	private function gateway( RequestScope $request, ?SiteKeyRepository $site_keys = null, ?KeyRepository $keys = null ): ServerGateway {
		$settings = new MemorySettings(
			array(
				'enabled'                 => true,
				'fallback_header_enabled' => false,
			)
		);
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		return new ServerGateway( $registry, $request, $settings, static fn(): string => '0.7.0', $keys, new HeaderExtractor( $settings ), $site_keys );
	}

	/**
	 * Build a site dispatcher.
	 *
	 * @return SiteDispatcher
	 */
	private function dispatcher(): SiteDispatcher {
		return new SiteDispatcher( new SitePolicy( new MemorySettings() ), new OutputPipeline( new MemorySettings() ) );
	}

	/**
	 * Build a human context.
	 *
	 * @param int $user_id User id.
	 * @return AuthContext
	 */
	private function human_context( int $user_id ): AuthContext {
		return new AuthContext( new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $user_id, 'Fixture', new Scope( RiskTier::Read ), new DateTimeImmutable() ), '0123456789abcdef' );
	}

	/**
	 * Build a site context.
	 *
	 * @param int $blog_id Blog id.
	 * @return SiteAuthContext
	 */
	private function site_context( int $blog_id ): SiteAuthContext {
		$scope      = new SiteScope( RiskTier::Read, array( 'system' ), array( 'xnetvn/get-site-info' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), $blog_id, 'Fixture', $scope, new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
	}

	/**
	 * Build a reviewed fixture definition.
	 *
	 * @param string $name Canonical name.
	 * @return \XNetVN\AgentHubWP\Contracts\AbilitySpec
	 */
	private function definition( string $name ): \XNetVN\AgentHubWP\Contracts\AbilitySpec {
		return ( new DefinitionFactory() )->create(
			array(
				'name'          => $name,
				'label'         => 'Site info',
				'description'   => 'Returns safe site information for the scope test.',
				'inputSchema'   => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'outputSchema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'id' ),
				),
				'tier'          => RiskTier::Read,
				'toolset'       => 'system',
				'annotations'   => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'visibilityCap' => 'read',
				'handler'       => static fn(): array => array( 'id' => 7 ),
				'example'       => array( 'id' => 7 ),
			)
		);
	}

	/**
	 * Build a fake adapter capturing create_server arguments.
	 *
	 * @return object
	 */
	private function fake_adapter(): object {
		return new class() {
			/** Captured arguments.
			 *
			 * @var array<mixed>
			 */
			public array $args = array();

			/**
			 * Capture the create_server call.
			 *
			 * @param mixed ...$args Arguments.
			 * @return object
			 */
			public function create_server( mixed ...$args ): object {
				$this->args = $args;
				return new \stdClass();
			}
		};
	}

	/**
	 * Build a fake adapter tool.
	 *
	 * @param string $name Wire name.
	 * @return object
	 */
	private function fake_tool( string $name ): object {
		return new class( $name ) {
			/**
			 * Bind the wire name.
			 *
			 * @param string $name Wire name.
			 */
			public function __construct( private string $name ) {
			}

			/**
			 * Return the wire name.
			 *
			 * @return string
			 */
			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The adapter tool contract exposes getName().
				return $this->name;
			}
		};
	}

	/**
	 * Build a fake adapter server.
	 *
	 * @return object
	 */
	private function fake_server(): object {
		return new class() {
			/**
			 * Return the owned server id.
			 *
			 * @return string
			 */
			public function get_server_id(): string {
				return ServerGateway::SERVER_ID;
			}
		};
	}

	/**
	 * Call the private admission method.
	 *
	 * @param ServerGateway $gateway Gateway.
	 * @return bool
	 */
	private function admits( ServerGateway $gateway ): bool {
		return (bool) $this->call( $gateway, 'admits_request' );
	}

	/**
	 * Call the private scope hint.
	 *
	 * @param ServerGateway $gateway Gateway.
	 * @return mixed
	 */
	private function scope_hint( ServerGateway $gateway ): mixed {
		return $this->call( $gateway, 'scope_hint' );
	}

	/**
	 * Call the private tool-name list.
	 *
	 * @param ServerGateway $gateway Gateway.
	 * @return array<string>
	 */
	private function tool_names( ServerGateway $gateway ): array {
		return (array) $this->call( $gateway, 'tool_names' );
	}

	/**
	 * Invoke one private method.
	 *
	 * @param ServerGateway $gateway Gateway.
	 * @param string        $method  Method.
	 * @return mixed
	 */
	private function call( ServerGateway $gateway, string $method ): mixed {
		$reflection = new ReflectionMethod( ServerGateway::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $gateway );
	}
}
