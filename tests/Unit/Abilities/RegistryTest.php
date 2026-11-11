<?php
/**
 * Authoritative ability registry and guarded callback tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Contracts\AbilityPack;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** The registry only exposes reviewed definitions and refuses ambiguous identity. */
final class RegistryTest extends TestCase {

	/** Prepare WordPress function mocks and the isolated error type. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/** Clean up mock state. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Definitions resolve by canonical name and exact wire name only. */
	public function test_resolves_definitions_by_canonical_and_wire_name(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$beta     = $this->definition( 'xnetvn/get-beta' );

		$registry->add( $alpha );
		$registry->add( $beta );

		self::assertSame( $alpha, $registry->get( 'xnetvn/get-alpha' ) );
		self::assertSame( $alpha, $registry->for_tool( 'xnetvn-get-alpha' ) );
		self::assertNull( $registry->get( 'xnetvn/get-missing' ) );
		self::assertNull( $registry->for_tool( 'xnetvn-get-missing' ) );
		self::assertSame( array( 'xnetvn/get-alpha', 'xnetvn/get-beta' ), $registry->names() );
		self::assertCount( 2, $registry->all() );
		self::assertSame( 2, count( $registry->visible() ) );
	}

	/** A duplicate canonical name or wire name is a developer error. */
	public function test_rejects_duplicate_canonical_and_wire_identity(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );

		try {
			$registry->add( $alpha );
			self::fail( 'A duplicate definition must be rejected.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'Duplicate ability or tool identity', $exception->getMessage() );
		}

		$collision = $this->definition( 'xnetvn/get-gamma', 'xnetvn-get-alpha' );
		$this->expectException( \InvalidArgumentException::class );
		$registry->add( $collision );
	}

	/** Deferred packs are read only once WordPress init has started. */
	public function test_loads_packs_only_after_init_started(): void {
		$registry = $this->registry();
		$pack     = $this->pack( $this->definition( 'xnetvn/get-packed' ) );
		$registry->add_pack( $pack );

		Functions\when( 'did_action' )->justReturn( 0 );
		$registry->load_packs();
		self::assertSame( array(), $registry->names() );

		Functions\when( 'did_action' )->justReturn( 1 );
		$registry->load_packs();
		self::assertSame( array( 'xnetvn/get-packed' ), $registry->names() );

		$registry->load_packs();
		self::assertSame( array( 'xnetvn/get-packed' ), $registry->names() );
	}

	/** Unavailable packs add no definition. */
	public function test_unavailable_pack_adds_nothing(): void {
		$registry = $this->registry();
		$registry->add_pack( $this->pack( $this->definition( 'xnetvn/get-hidden' ), false ) );

		Functions\when( 'did_action' )->justReturn( 1 );
		$registry->load_packs();

		self::assertSame( array(), $registry->names() );
	}

	/** Registration wires the documented private hooks and removes only its own. */
	public function test_registers_and_removes_private_hooks(): void {
		$registry = $this->registry();

		Actions\expectAdded( 'init' )->once()->with( array( $registry, 'load_packs' ), 0 );
		Actions\expectAdded( 'wp_abilities_api_categories_init' )->once()->with( array( $registry, 'register_categories' ) );
		Actions\expectAdded( 'wp_abilities_api_init' )->once()->with( array( $registry, 'register_abilities' ) );
		$registry->register();

		Actions\expectRemoved( 'init' )->once()->with( array( $registry, 'load_packs' ), 0 );
		Actions\expectRemoved( 'wp_abilities_api_categories_init' )->once()->with( array( $registry, 'register_categories' ) );
		Actions\expectRemoved( 'wp_abilities_api_init' )->once()->with( array( $registry, 'register_abilities' ) );
		$registry->unregister_hooks();
		$this->addToAssertionCount( 1 );
	}

	/** One private category is created per toolset and never duplicated. */
	public function test_registers_one_category_per_toolset(): void {
		$registry = $this->registry();
		$registry->add( $this->definition( 'xnetvn/get-alpha', 'xnetvn-get-alpha', 'system' ) );
		$registry->add( $this->definition( 'xnetvn/get-beta', 'xnetvn-get-beta', 'content' ) );

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability_category' )->alias( static fn( string $slug ): bool => 'xnetvn-content' === $slug );
		Functions\expect( 'wp_register_ability_category' )
			->once()
			->with( 'xnetvn-system', \Mockery::type( 'array' ) );

		$registry->register_categories();
		$this->addToAssertionCount( 1 );
	}

	/** Private metadata is bound; public metadata is blocked and unregistered. */
	public function test_registers_private_callbacks_and_blocks_public_metadata(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$beta     = $this->definition( 'xnetvn/get-beta' );
		$registry->add( $alpha );
		$registry->add( $beta );

		$private = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);
		$public  = $this->ability(
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->alias(
			static function ( string $name ) use ( $private, $public ) {
				return 'xnetvn/get-alpha' === $name ? $private : $public;
			}
		);
		Functions\expect( 'wp_unregister_ability' )->once()->with( 'xnetvn/get-beta' );

		$registry->register_abilities();

		Functions\when( 'wp_get_ability' )->alias( static fn( string $name ) => 'xnetvn/get-alpha' === $name ? $private : null );
		self::assertSame( array( 'xnetvn/get-alpha' ), $registry->names() );
		self::assertTrue( $registry->allowed( $alpha ) );
		self::assertFalse( $registry->allowed( $beta ) );
	}

	/** An existing foreign ability with the same name is closed, not overwritten. */
	public function test_existing_foreign_ability_blocks_the_definition(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );
		$foreign = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->justReturn( $foreign );
		Functions\expect( 'wp_register_ability' )->never();

		$registry->register_abilities();

		self::assertSame( array(), $registry->names() );
		self::assertFalse( $registry->allowed( $alpha ) );
	}

	/** Re-registering the same bound object stays allowed. */
	public function test_re_registration_keeps_the_same_bound_object(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );
		$ability = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->justReturn( $ability );

		$registry->register_abilities();

		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->justReturn( $ability );
		$registry->register_abilities();

		self::assertSame( array( 'xnetvn/get-alpha' ), $registry->names() );
	}

	/** Wire execution reaches only the exact bound ability. */
	public function test_execute_reaches_only_the_exact_bound_ability(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );
		$ability = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			),
			array( 'value' => 'safe' )
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->justReturn( $ability );
		$registry->register_abilities();

		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->justReturn( $ability );
		Actions\expectDone( 'xnetvn_agent_hub_wp_tool_started' )->once()->with( 'xnetvn/get-alpha', array( 'id' ) );
		Actions\expectDone( 'xnetvn_agent_hub_wp_tool_finished' )->once()->with( 'xnetvn/get-alpha', 'success' );

		self::assertSame( array( 'value' => 'safe' ), $registry->execute( 'xnetvn/get-alpha', array( 'id' => 7 ) ) );
	}

	/** Unknown, unbound or substituted abilities are denied without detail. */
	public function test_execute_denies_unknown_unbound_and_substituted_abilities(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );

		$unknown = $registry->execute( 'xnetvn/get-missing', array() );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $unknown->code );

		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->justReturn(
			$this->ability(
				array(
					'public'       => false,
					'show_in_rest' => false,
				)
			)
		);
		$not_bound = $registry->execute( 'xnetvn/get-alpha', array() );
		self::assertInstanceOf( \WP_Error::class, $not_bound );
	}

	/** Executing a bound ability reports failure without leaking the throwable. */
	public function test_execute_maps_a_thrown_error_to_a_safe_result(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );
		$ability = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			),
			null,
			static function (): void {
				throw new \RuntimeException( 'secret detail' );
			}
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->justReturn( $ability );
		$registry->register_abilities();

		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'wp_get_ability' )->justReturn( $ability );
		Actions\expectDone( 'xnetvn_agent_hub_wp_tool_finished' )->once()->with( 'xnetvn/get-alpha', 'error' );

		$result = $registry->execute( 'xnetvn/get-alpha', array() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringNotContainsString( 'secret detail', (string) $result->code . $result->message );
	}

	/** A bound ability registered by another object is denied. */
	public function test_bound_ability_replacement_is_denied(): void {
		$registry = $this->registry();
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );
		$ability = $this->ability(
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_has_ability' )->justReturn( false );
		Functions\when( 'wp_get_ability' )->justReturn( null );
		Functions\when( 'wp_register_ability' )->justReturn( $ability );
		$registry->register_abilities();

		Functions\when( 'wp_get_ability' )->justReturn(
			$this->ability(
				array(
					'public'       => false,
					'show_in_rest' => false,
				)
			)
		);
		self::assertFalse( $registry->allowed( $alpha ) );
	}

	/** HTTP policy requires a live identity that matches the current user. */
	public function test_http_without_matching_identity_is_denied(): void {
		$registry = $this->registry( 'http', null );
		$alpha    = $this->definition( 'xnetvn/get-alpha' );
		$registry->add( $alpha );

		self::assertFalse( $registry->allowed( $alpha ) );
	}

	/** Delegated scope removes only names the same scope already denies. */
	public function test_names_for_scope_applies_tier_toolset_and_deny_lists(): void {
		$registry = $this->registry();
		$registry->add( $this->definition( 'xnetvn/get-alpha', 'xnetvn-get-alpha', 'system' ) );
		$registry->add( $this->definition( 'xnetvn/get-beta', 'xnetvn-get-beta', 'content' ) );
		$registry->add( $this->definition( 'xnetvn/get-gamma', 'xnetvn-get-gamma', 'system' ) );

		$scope = new Scope( RiskTier::Read, array( 'system' ), array(), array( 'xnetvn/get-gamma' ) );
		self::assertSame( array( 'xnetvn/get-alpha' ), $registry->names_for_scope( $scope ) );

		$allow = new Scope( RiskTier::Read, array( 'system' ), array( 'xnetvn/get-alpha' ) );
		self::assertSame( array( 'xnetvn/get-alpha' ), $registry->names_for_scope( $allow ) );
	}

	/**
	 * Build a registry with pure policy seams.
	 *
	 * @param string $transport Transport.
	 * @param mixed  $context   Current identity.
	 * @return Registry
	 */
	private function registry( string $transport = 'stdio', mixed $context = null ): Registry {
		$settings = new MemorySettings(
			array(
				'enabled'          => true,
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
				'output_max_bytes' => 8192,
			)
		);
		$request  = new class( $transport, $context ) implements RequestContext {
			/**
			 * Capture the fixture identity.
			 *
			 * @param string                                     $transport Transport.
			 * @param \XNetVN\AgentHubWP\Domain\AuthContext|null $context Identity.
			 */
			public function __construct( private string $transport, private ?\XNetVN\AgentHubWP\Domain\AuthContext $context ) {
			}
			/** Read the fixture identity. */
			public function current(): ?\XNetVN\AgentHubWP\Domain\AuthContext {
				return $this->context;
			}
			/** Read the fixture transport. */
			public function transport(): string {
				return $this->transport;
			}
			/** No measured budget in this fixture. */
			public function rate_budget(): ?\XNetVN\AgentHubWP\Domain\RateLimitResult {
				return null;
			}
		};
		$policy   = new Policy( $settings, static fn(): bool => true, static fn( $decision ) => $decision );

		return new Registry( $policy, $request, new OutputPipeline( $settings ) );
	}

	/**
	 * Build a reviewed read-only definition.
	 *
	 * @param string      $name      Canonical name.
	 * @param string|null $tool_name Optional wire name override.
	 * @param string      $toolset   Toolset.
	 * @param mixed       $handler   Optional handler.
	 * @return AbilitySpec
	 */
	private function definition( string $name, ?string $tool_name = null, string $toolset = 'system', mixed $handler = null ): AbilitySpec {
		$definition = ( new DefinitionFactory() )->create(
			array(
				'name'          => $name,
				'label'         => 'Read fixture',
				'description'   => 'Returns a safe fixture value. Use this tool to verify read-only structured output.',
				'inputSchema'   => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'outputSchema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array( 'value' => array( 'type' => 'string' ) ),
					'required'             => array( 'value' ),
				),
				'tier'          => RiskTier::Read,
				'toolset'       => $toolset,
				'annotations'   => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'visibilityCap' => 'read',
				'handler'       => $handler ?? static fn(): array => array( 'value' => 'safe' ),
				'example'       => array( 'value' => 'safe' ),
			)
		);
		if ( null === $tool_name ) {
			return $definition;
		}

		return new class( $definition, $tool_name ) implements AbilitySpec {
			/**
			 * Capture the wrapped definition and wire name.
			 *
			 * @param AbilitySpec $definition Wrapped definition.
			 * @param string      $tool_name  Wire name.
			 */
			public function __construct( private AbilitySpec $definition, private string $tool_name ) {
			}
			/** Delegated descriptor. */
			public function descriptor(): \XNetVN\AgentHubWP\Domain\AbilityDescriptor {
				return $this->definition->descriptor();
			}
			/** Overridden wire name. */
			public function tool_name(): string {
				return $this->tool_name;
			}
			/** Delegated label. */
			public function label(): string {
				return $this->definition->label();
			}
			/** Delegated description. */
			public function description(): string {
				return $this->definition->description();
			}
			/** Delegated input schema. */
			public function input_schema(): array {
				return $this->definition->input_schema();
			}
			/** Delegated output schema. */
			public function output_schema(): array {
				return $this->definition->output_schema();
			}
			/** Delegated annotations. */
			public function annotations(): array {
				return $this->definition->annotations();
			}
			/** Delegated example. */
			public function example(): mixed {
				return $this->definition->example();
			}
			/** Delegated cost flag. */
			public function costly(): bool {
				return $this->definition->costly();
			}
			/**
			 * Delegated execution.
			 *
			 * @param array<string,mixed> $arguments Logical arguments.
			 */
			public function execute( array $arguments ): mixed {
				return $this->definition->execute( $arguments );
			}
		};
	}

	/**
	 * Build a pack fixture.
	 *
	 * @param AbilitySpec $definition Definition.
	 * @param bool        $available  Whether the pack is available.
	 * @return AbilityPack
	 */
	private function pack( AbilitySpec $definition, bool $available = true ): AbilityPack {
		return new class( $definition, $available ) implements AbilityPack {
			/**
			 * Capture the pack fixture.
			 *
			 * @param AbilitySpec $definition Definition.
			 * @param bool        $available  Availability.
			 */
			public function __construct( private AbilitySpec $definition, private bool $available ) {
			}
			/** Pack availability. */
			public function is_available(): bool {
				return $this->available;
			}
			/** Pack definitions. */
			public function definitions(): array {
				return array( $this->definition );
			}
		};
	}

	/**
	 * Build a core-ability double.
	 *
	 * @param array<string,mixed> $meta    Ability meta.
	 * @param mixed               $result  Execution result.
	 * @param callable|null       $execute Optional execution callback.
	 * @return object
	 */
	private function ability( array $meta, mixed $result = null, ?callable $execute = null ): object {
		return new class( $meta, $result, $execute ) {
			/**
			 * Capture the fixture values.
			 *
			 * @param array<string,mixed> $meta    Ability meta.
			 * @param mixed               $result  Result.
			 * @param callable|null       $execute Optional callback.
			 */
			public function __construct( private array $meta, private mixed $result, private mixed $execute ) {
			}
			/** Read ability meta. */
			public function get_meta(): array {
				return $this->meta;
			}
			/**
			 * Execute or throw through the fixture.
			 *
			 * @param array<string,mixed> $arguments Logical arguments.
			 */
			public function execute( array $arguments ): mixed {
				if ( null !== $this->execute ) {
					return ( $this->execute )( $arguments );
				}

				return $this->result;
			}
		};
	}
}
