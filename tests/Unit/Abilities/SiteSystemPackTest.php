<?php
/**
 * Machine session, registration and nested-authority contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only core ability double.

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Plugin;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Rest\SiteKeyView;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal REST controller base used only by the provider-graph unit check. */
class SiteSystemRestController {
	/** REST namespace set by the management controllers.
	 *
	 * @var string
	 */
	public string $namespace = '';
	/** REST base set by the concrete controllers.
	 *
	 * @var string
	 */
	public string $rest_base = '';
}

/** Executes the callback captured by the registry at registration time. */
final class SiteSystemRegisteredAbility {
	/** Captured registration arguments.
	 *
	 * @var array<string,mixed>
	 */
	private array $arguments;
	/**
	 * Bind the captured registration arguments.
	 *
	 * @param array<string,mixed> $arguments Registration arguments.
	 */
	public function __construct( array $arguments ) {
		$this->arguments = $arguments;
	}
	/**
	 * Return the private metadata used by the registration guard.
	 *
	 * @return array<string,bool>
	 */
	public function get_meta(): array {
		return array(
			'public'       => false,
			'show_in_rest' => false,
		);
	}
	/**
	 * Run the reviewed registry callback.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed {
		return ( $this->arguments['execute_callback'] )( $arguments );
	}
}

/** Proves the site principal, the five registrations and the nested authority boundary. */
final class SiteSystemPackTest extends TestCase {
	/** Fixed current blog. */
	private const BLOG = 1;
	/** Fixed human identity. */
	private const HUMAN = 7;
	/** Fixed public key id. */
	private const KEY_ID = 'abcdefghijkl';

	/** Policy fixture.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Request identity.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;
	/** Current WordPress user substitute.
	 *
	 * @var int
	 */
	private int $current_user = 0;
	/** Human user substitute.
	 *
	 * @var object
	 */
	private object $wp_user;
	/** Captured init registration.
	 *
	 * @var Closure|null
	 */
	private ?Closure $init_registration = null;
	/** Registered core ability doubles.
	 *
	 * @var array<string,SiteSystemRegisteredAbility>
	 */
	private array $abilities = array();

	/** Install WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		require_once dirname( __DIR__, 2 ) . '/Support/WpdbStub.php';
		Monkey\setUp();
		$this->settings = new MemorySettings(
			array(
				'enabled'          => true,
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
				'output_max_bytes' => 262144,
			)
		);
		$this->request  = new RequestScope();
		$this->wp_user  = (object) array(
			'ID'           => self::HUMAN,
			'display_name' => 'Fixture Editor',
			'roles'        => array( 'editor' ),
		);
		$this->wordpress();
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The reviewed names and the exact machine handler signature. */
	public function test_names_and_machine_handlers_match_the_five_system_definitions(): void {
		[ , , $pack ] = $this->build();
		$reviewed     = array();
		foreach ( $pack->definitions() as $definition ) {
			$reviewed[] = $definition->descriptor()->name;
		}
		sort( $reviewed );
		$names = SystemPack::NAMES;
		sort( $names );
		self::assertCount( 5, SystemPack::NAMES );
		self::assertSame( $reviewed, $names );
		foreach ( $pack->definitions() as $definition ) {
			$this->assert_schema( (array) $definition->example(), $definition );
		}
		foreach ( SystemPack::NAMES as $name ) {
			$handler    = $pack->machine_handler( $name );
			$reflection = new \ReflectionFunction( $handler );
			self::assertCount( 2, $reflection->getParameters() );
			$arguments = $reflection->getParameters()[0]->getType();
			$context   = $reflection->getParameters()[1]->getType();
			self::assertInstanceOf( \ReflectionNamedType::class, $arguments );
			self::assertInstanceOf( \ReflectionNamedType::class, $context );
			self::assertSame( 'array', $arguments->getName() );
			self::assertSame( SiteAuthContext::class, $context->getName() );
		}
		try {
			$pack->machine_handler( 'xnetvn/create-user' );
			self::fail( 'A name outside the system machine list must be refused.' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
	}

	/** Registration runs after packs load, is idempotent and skips missing definitions. */
	public function test_registers_the_five_handlers_once_after_packs_load(): void {
		[ $registry, $dispatcher, $pack ] = $this->build();
		$scope                            = $this->scope();
		foreach ( SystemPack::NAMES as $name ) {
			self::assertFalse( $dispatcher->has_handler( $name ), $name );
		}
		self::assertSame( array(), $registry->names_for_site_scope( $scope ) );
		$pack->register_machine_handlers( $dispatcher );
		foreach ( SystemPack::NAMES as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		$expected = SystemPack::NAMES;
		sort( $expected );
		self::assertSame( $expected, $registry->names_for_site_scope( $scope ) );
		$pack->register_machine_handlers( $dispatcher );
		foreach ( SystemPack::NAMES as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}

		$empty_policy     = new SitePolicy( $this->settings );
		$empty_dispatcher = new SiteDispatcher( $empty_policy, new OutputPipeline( $this->settings ) );
		$empty_registry   = new Registry( new Policy( $this->settings ), new RequestScope(), new OutputPipeline( $this->settings ), null, new SafeMcpEnvelope(), $empty_policy, $empty_dispatcher );
		$empty_pack       = new SystemPack( new DefinitionFactory(), $empty_registry, new RequestScope(), new SchemaBuilder() );
		$empty_dispatcher->bind_catalog( $empty_registry, static fn( string $name ): ?AbilityDescriptor => $empty_registry->get( $name )?->descriptor() );
		$empty_pack->register_machine_handlers( $empty_dispatcher );
		foreach ( SystemPack::NAMES as $name ) {
			self::assertFalse( $empty_dispatcher->has_handler( $name ), $name );
		}
	}

	/** A human session keeps the user object and adds the user principal. */
	public function test_human_session_keeps_the_user_object_and_reports_a_user_principal(): void {
		[ $registry ] = $this->build();
		$this->bind_core_abilities( $registry );
		$this->current_user = self::HUMAN;
		$this->request->bind_human( $this->human_context() );
		$payload = $registry->execute( 'xnetvn/get-session-info', array() );
		self::assertIsArray( $payload );
		self::assertSame( array( 'user', 'principal', 'key', 'site', 'rate_limit' ), array_keys( $payload ) );
		self::assertSame(
			array(
				'id'           => self::HUMAN,
				'display_name' => 'Fixture Editor',
				'roles'        => array( 'editor' ),
			),
			$payload['user']
		);
		self::assertSame( array( 'type', 'user_id' ), array_keys( $payload['principal'] ) );
		self::assertSame( 'user', $payload['principal']['type'] );
		self::assertSame( self::HUMAN, $payload['principal']['user_id'] );
		self::assertSame( self::KEY_ID, $payload['key']['key_id'] );
		self::assertSame( 'read', $payload['key']['tier_max'] );
		self::assertNull( $payload['rate_limit']['remaining'] );
		$this->assert_schema( $payload, $registry->get( 'xnetvn/get-session-info' ) );
	}

	/** A verified site session reports the machine principal and no secret. */
	public function test_site_session_reports_the_machine_principal_without_secrets(): void {
		[ $registry, $dispatcher, $pack ] = $this->build();
		$pack->register_machine_handlers( $dispatcher );
		$this->bind_core_abilities( $registry );
		$context = $this->context();
		$this->request->bind_site( $context );
		$payload = $registry->execute( 'xnetvn/get-session-info', array() );
		self::assertIsArray( $payload );
		self::assertSame( array( 'user', 'principal', 'key', 'site', 'rate_limit' ), array_keys( $payload ) );
		self::assertNull( $payload['user'] );
		self::assertSame( array( 'type', 'blog_id', 'key_id', 'credential_ref' ), array_keys( $payload['principal'] ) );
		self::assertSame( 'site', $payload['principal']['type'] );
		self::assertSame( self::BLOG, $payload['principal']['blog_id'] );
		self::assertSame( self::KEY_ID, $payload['principal']['key_id'] );
		self::assertSame( 'xns:' . self::KEY_ID, $payload['principal']['credential_ref'] );
		self::assertSame( array( 'key_id', 'name', 'tier_max', 'toolsets', 'expires_at' ), array_keys( $payload['key'] ) );
		self::assertSame( self::KEY_ID, $payload['key']['key_id'] );
		self::assertSame( 'Site fixture', $payload['key']['name'] );
		self::assertSame( 'read', $payload['key']['tier_max'] );
		self::assertSame( array( 'system' ), $payload['key']['toolsets'] );
		self::assertSame( $context->credential->scope->expires_at->format( DATE_ATOM ), $payload['key']['expires_at'] );
		self::assertSame( array( 'remaining' => null ), $payload['rate_limit'] );
		self::assertSame(
			array(
				'name'       => 'Fixture site',
				'url'        => 'https://example.test',
				'wp_version' => '6.9',
			),
			$payload['site']
		);
		$this->assert_schema( $payload, $registry->get( 'xnetvn/get-session-info' ) );

		$json = (string) wp_json_encode( $payload );
		foreach ( array( 'secret', 'hash', 'pepper', 'token', 'key_hash', 'user_pass', 'bearer', 'created_by', 'revoked_by', 'last_used_ip', '203.0.113.9' ) as $needle ) {
			self::assertStringNotContainsString( $needle, strtolower( $json ), $needle );
		}
		$forbidden = array( 'password', 'secret', 'token', 'hash', 'salt', 'api_key', 'secret_hash', 'user_pass', 'application_passwords', 'user_activation_key', 'session_tokens', 'created_by', 'revoked_by', 'last_used_ip', 'issuer' );
		self::assertSame( array(), array_intersect( array_keys( $payload ), $forbidden ) );
		self::assertSame( array(), array_intersect( array_keys( $payload['principal'] ), $forbidden ) );
		self::assertSame( array(), array_intersect( array_keys( $payload['key'] ), $forbidden ) );

		$view = SiteKeyView::data( $context->credential );
		self::assertSame(
			array( 'id', 'key_id', 'credential_ref', 'principal_type', 'blog_id', 'name', 'profile', 'toolsets', 'allowed_abilities', 'denied_abilities', 'permissions', 'ip_allowlist', 'tier_ceiling', 'expires_at', 'created_at', 'revoked_at', 'created_by', 'revoked_by', 'last_used_at', 'last_used_ip' ),
			array_keys( $view )
		);
		self::assertSame( array(), array_intersect( array_keys( $view ), array( 'secret', 'hash', 'pepper', 'token', 'key_hash', 'secret_hash', 'user_pass' ) ) );
	}

	/** Site discovery and ability info expose only installed, permitted handlers. */
	public function test_site_discovery_and_ability_info_expose_only_installed_handlers(): void {
		[ $registry, $dispatcher, $pack ] = $this->build( true );
		$pack->register_machine_handlers( $dispatcher );
		$this->bind_core_abilities( $registry );
		$context = $this->context();
		$this->request->bind_site( $context );
		$expected = SystemPack::NAMES;
		sort( $expected );
		self::assertSame( $expected, $registry->names_for_site_scope( $context->credential->scope ) );
		$discover = $registry->execute( 'xnetvn/discover-abilities', array( 'per_page' => 100 ) );
		self::assertIsArray( $discover );
		self::assertSame( $expected, array_column( $discover['items'], 'name' ) );
		self::assertSame( 5, $discover['total'] );
		$reference = $registry->execute( 'xnetvn/get-ability-info', array( 'name' => 'xnetvn/does-not-exist' ) );
		self::assertInstanceOf( \WP_Error::class, $reference );
		foreach ( array( 'xnetvn/get-my-profile', 'xnetvn/update-my-profile', 'xnetvn/set-user-status' ) as $hidden ) {
			$error = $registry->execute( 'xnetvn/get-ability-info', array( 'name' => $hidden ) );
			self::assertInstanceOf( \WP_Error::class, $error, $hidden );
			self::assertSame( $reference->get_error_code(), $error->get_error_code(), $hidden );
			self::assertSame( $reference->message, $error->message, $hidden );
		}
		$known = $registry->execute( 'xnetvn/get-ability-info', array( 'name' => 'xnetvn/get-session-info' ) );
		self::assertIsArray( $known );
		self::assertSame( 'xnetvn/get-session-info', $known['name'] );
	}

	/** The read-tier wrapper never widens the target policy. */
	public function test_execute_ability_keeps_the_target_policy(): void {
		[ $registry, $dispatcher, $pack ] = $this->build( true );
		$pack->register_machine_handlers( $dispatcher );
		$this->bind_core_abilities( $registry );
		$this->request->bind_site( $this->context() );
		$reference = $registry->execute(
			'xnetvn/execute-ability',
			array(
				'name'      => 'xnetvn/does-not-exist',
				'arguments' => array(),
			)
		);
		self::assertInstanceOf( \WP_Error::class, $reference );
		foreach ( array( 'xnetvn/update-my-profile', 'xnetvn/create-user', 'xnetvn/set-user-status' ) as $forbidden ) {
			$error = $registry->execute(
				'xnetvn/execute-ability',
				array(
					'name'      => $forbidden,
					'arguments' => array( 'user_id' => 9 ),
				)
			);
			self::assertInstanceOf( \WP_Error::class, $error, $forbidden );
			self::assertSame( $reference->get_error_code(), $error->get_error_code(), $forbidden );
			self::assertSame( $reference->message, $error->message, $forbidden );
		}
		$control = $registry->execute(
			'xnetvn/execute-ability',
			array(
				'name'      => 'xnetvn/get-site-info',
				'arguments' => array(),
			)
		);
		self::assertIsArray( $control );
		self::assertSame( array( 'name', 'url', 'wp_version' ), array_keys( $control['result'] ) );
	}

	/** The plugin schedules the same registration on init priority one. */
	public function test_plugin_schedules_machine_registration_on_init_priority_one(): void {
		if ( ! class_exists( 'WP_REST_Controller', false ) ) {
			class_alias( SiteSystemRestController::class, 'WP_REST_Controller' );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated unit fixture for the provider graph.
		$GLOBALS['wpdb'] = new \wpdb();
		Actions\expectAdded( 'init' )->atLeast()->once()->with(
			\Mockery::on(
				function ( mixed $handler ): bool {
					if ( $handler instanceof Closure ) {
						$this->init_registration = $handler;
					}
					return true;
				}
			),
			1
		);
		$method = new \ReflectionMethod( Plugin::class, 'providers' );
		$method->setAccessible( true );
		$providers = $method->invoke( null, '/tmp/xnetvn-agent-hub-wp.php' );
		self::assertIsArray( $providers );
		self::assertInstanceOf( Closure::class, $this->init_registration, 'The plugin must schedule the machine registration on init.' );
		$registry = null;
		foreach ( $providers as $provider ) {
			if ( $provider instanceof Registry ) {
				$registry = $provider;
			}
		}
		self::assertInstanceOf( Registry::class, $registry );
		$registry->load_packs();
		( $this->init_registration )();
		$property = new \ReflectionProperty( Registry::class, 'site_dispatcher' );
		$property->setAccessible( true );
		$dispatcher = $property->getValue( $registry );
		self::assertInstanceOf( SiteDispatcher::class, $dispatcher );
		foreach ( SystemPack::NAMES as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		$expected = SystemPack::NAMES;
		sort( $expected );
		self::assertSame( $expected, $registry->names_for_site_scope( $this->scope() ) );
	}

	/** Install the fixed WordPress substitutes. */
	private function wordpress(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'get_bloginfo' )->alias( static fn( string $key ): string => 'version' === $key ? '6.9' : 'Fixture site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user );
		Functions\when( 'wp_get_current_user' )->alias( fn(): object => $this->wp_user );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $value, int $flags = 0 ): string|false => json_encode( $value, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	/**
	 * Build the authoritative registry, dispatcher and pack.
	 *
	 * @param bool $users Whether the Users pack is present.
	 * @return array{Registry,SiteDispatcher,SystemPack}
	 */
	private function build( bool $users = false ): array {
		$policy     = new SitePolicy( $this->settings );
		$output     = new OutputPipeline( $this->settings );
		$dispatcher = new SiteDispatcher( $policy, $output );
		$registry   = new Registry( new Policy( $this->settings ), $this->request, $output, null, new SafeMcpEnvelope(), $policy, $dispatcher );
		$pack       = new SystemPack( new DefinitionFactory(), $registry, $this->request, new SchemaBuilder() );
		$registry->add_pack( $pack );
		if ( $users ) {
			$registry->add_pack( new UsersPack( new DefinitionFactory(), null ) );
		}
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		$registry->load_packs();
		return array( $registry, $dispatcher, $pack );
	}

	/**
	 * Bind the reviewed core abilities to the isolated registry.
	 *
	 * @param Registry $registry Registry.
	 * @return void
	 */
	private function bind_core_abilities( Registry $registry ): void {
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->alias(
			function ( string $name, array $arguments ): SiteSystemRegisteredAbility {
				$ability                  = new SiteSystemRegisteredAbility( $arguments );
				$this->abilities[ $name ] = $ability;
				return $ability;
			}
		);
		$registry->register_abilities();
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->alias( fn( string $name ): ?SiteSystemRegisteredAbility => $this->abilities[ $name ] ?? null );
	}

	/**
	 * Validate one payload against its declared output schema.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param AbilitySpec|null    $definition Definition.
	 * @return void
	 */
	private function assert_schema( array $payload, ?AbilitySpec $definition ): void {
		self::assertInstanceOf( AbilitySpec::class, $definition );
		$validator = new \Opis\JsonSchema\Validator();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		$schema = json_decode( (string) json_encode( $definition->output_schema() ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		$data = json_decode( (string) json_encode( $payload ) );
		self::assertTrue( $validator->validate( $data, $schema )->isValid() );
	}

	/**
	 * Build the human identity.
	 *
	 * @return AuthContext
	 */
	private function human_context(): AuthContext {
		return new AuthContext( new ApiKey( 1, new KeyId( self::KEY_ID ), self::HUMAN, 'Fixture', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . time() ) ), '0123456789abcdef' );
	}

	/**
	 * Build a bounded system scope.
	 *
	 * @param array<string> $allowed Explicit allowlist.
	 * @return SiteScope
	 */
	private function scope( array $allowed = SystemPack::NAMES ): SiteScope {
		return new SiteScope( RiskTier::Read, array( 'system' ), $allowed, array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
	}

	/**
	 * Build the verified machine identity.
	 *
	 * @param SiteScope|null $scope Optional scope.
	 * @return SiteAuthContext
	 */
	private function context( ?SiteScope $scope = null ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( self::KEY_ID ), self::BLOG, 'Site fixture', $scope ?? $this->scope(), new DateTimeImmutable( '@' . time() ), null, self::HUMAN, null, null, '203.0.113.9' );
		return new SiteAuthContext( $credential, 'fr19-request-id', '203.0.113.7' );
	}
}
