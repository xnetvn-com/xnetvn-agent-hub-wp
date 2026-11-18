<?php
/**
 * Machine policy and dispatcher boundary tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test-only doubles and the fixed handler signature.

/** Minimal reviewed definition used by the dispatcher tests. */
final class SiteDispatcherFixture implements AbilitySpec {
	/**
	 * Bind the descriptor and optional cost.
	 *
	 * @param bool $costly Whether the operation is expensive.
	 */
	public function __construct( private bool $costly = false ) {
	}

	/**
	 * Return the fixture descriptor.
	 *
	 * @return AbilityDescriptor
	 */
	public function descriptor(): AbilityDescriptor {
		return new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'edit_posts' );
	}

	/**
	 * Return the wire name.
	 *
	 * @return string
	 */
	public function tool_name(): string {
		return 'get-post';
	}

	/**
	 * Return the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Get post';
	}

	/**
	 * Return the description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Fixture';
	}

	/**
	 * Return the strict input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function input_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Return the output schema.
	 *
	 * @return array<string,mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Return annotations.
	 *
	 * @return array<string,mixed>
	 */
	public function annotations(): array {
		return array();
	}

	/**
	 * Return an example.
	 *
	 * @return mixed
	 */
	public function example(): mixed {
		return array();
	}

	/**
	 * Whether the operation needs the expensive budget.
	 *
	 * @return bool
	 */
	public function costly(): bool {
		return $this->costly;
	}

	/**
	 * Execute the human fixture path.
	 *
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed {
		return array( 'human' => true );
	}
}

/** Catalog returning one fixture definition. */
final class SiteDispatcherCatalog implements AbilityCatalog {
	/**
	 * Bind the fixture.
	 *
	 * @param AbilitySpec $definition Fixture.
	 */
	public function __construct( private AbilitySpec $definition ) {
	}

	/**
	 * List all definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function all(): array {
		return array( $this->definition );
	}

	/**
	 * Resolve one name.
	 *
	 * @param string $name Name.
	 * @return AbilitySpec|null
	 */
	public function get( string $name ): ?AbilitySpec {
		return $name === $this->definition->descriptor()->name ? $this->definition : null;
	}

	/**
	 * List visible definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function visible(): array {
		return array( $this->definition );
	}

	/**
	 * Execute the human fixture path.
	 *
	 * @param string              $name Name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed {
		return array( 'catalog' => true );
	}
}

/** Budget double recording the site identity. */
final class SiteDispatcherBudget implements ExpensiveBudget {
	/** Recorded identities.
	 *
	 * @var array<string>
	 */
	public array $identities = array();

	/**
	 * Run the work and record the identity.
	 *
	 * @param string  $identity Site identity.
	 * @param Closure $work Work.
	 * @return mixed
	 */
	public function run( string $identity, Closure $work ): mixed {
		$this->identities[] = $identity;
		return $work();
	}
}

/** Proves the machine policy, registration and dispatch boundaries. */
final class SiteDispatcherTest extends TestCase {
	/** Fixed blog id. */
	private const BLOG = 1;

	/** Settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Site policy.
	 *
	 * @var SitePolicy
	 */
	private SitePolicy $policy;
	/** Machine identity.
	 *
	 * @var SiteAuthContext
	 */
	private SiteAuthContext $context;
	/** Fixture definition.
	 *
	 * @var SiteDispatcherFixture
	 */
	private SiteDispatcherFixture $definition;
	/** Catalog.
	 *
	 * @var SiteDispatcherCatalog
	 */
	private SiteDispatcherCatalog $catalog;

	/** Install WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		$this->settings   = new MemorySettings();
		$this->policy     = new SitePolicy( $this->settings );
		$scope            = new SiteScope(
			RiskTier::Write,
			array( 'content' ),
			array( 'xnetvn/get-post' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential       = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), self::BLOG, 'Fixture', $scope, new DateTimeImmutable( '@' . time() ) );
		$this->context    = new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
		$this->definition = new SiteDispatcherFixture();
		$this->catalog    = new SiteDispatcherCatalog( $this->definition );
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Registration refuses unknown, duplicate, badly shaped and post-freeze handlers. */
	public function test_registration_boundaries(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$this->expectException( \InvalidArgumentException::class );
		$dispatcher->register( 'xnetvn/unknown', static fn( array $arguments, SiteAuthContext $context ): array => array() );
	}

	/** A duplicate and a bad signature are refused; freeze blocks late registration. */
	public function test_duplicate_signature_and_freeze(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$handler = static fn( array $arguments, SiteAuthContext $context ): array => array( 'ok' => true );
		try {
			$dispatcher->register( 'xnetvn/get-post', static fn( string $arguments, SiteAuthContext $context ): array => array() );
			self::fail( 'Bad signature was accepted.' );
		} catch ( \InvalidArgumentException ) {
			self::assertFalse( $dispatcher->has_handler( 'xnetvn/get-post' ) );
		}
		$dispatcher->register( 'xnetvn/get-post', $handler );
		try {
			$dispatcher->register( 'xnetvn/get-post', $handler );
			self::fail( 'Duplicate registration was accepted.' );
		} catch ( \InvalidArgumentException ) {
			self::assertTrue( $dispatcher->has_handler( 'xnetvn/get-post' ) );
		}
		$dispatcher->freeze();
		$this->expectException( \LogicException::class );
		$dispatcher->register( 'xnetvn/get-post', $handler );
	}

	/** Unhandled names and an unbound dispatcher are indistinguishable. */
	public function test_unavailable_paths(): void {
		$dispatcher = $this->dispatcher();
		$result     = $dispatcher->execute( 'xnetvn/get-post', array(), $this->context );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $dispatcher->execute( 'xnetvn/get-post', array(), $this->context )->get_error_code() );
	}

	/** Policy denial and invalid arguments never run the handler. */
	public function test_policy_and_schema_failures(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$called = 0;
		$dispatcher->register(
			'xnetvn/get-post',
			static function ( array $arguments, SiteAuthContext $context ) use ( &$called ): array {
				++$called;
				return array( 'ok' => true );
			}
		);
		$narrow     = new SiteScope(
			RiskTier::Write,
			array( 'content' ),
			array( 'xnetvn/list-posts' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), self::BLOG, 'Fixture', $narrow, new DateTimeImmutable( '@' . time() ) );
		$denied     = new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $dispatcher->execute( 'xnetvn/get-post', array(), $denied )->get_error_code() );
		self::assertSame( 0, $called );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( false );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $dispatcher->execute( 'xnetvn/get-post', array(), $this->context )->get_error_code() );
		self::assertSame( 0, $called );
	}

	/** A valid call reaches only the machine handler and uses the site budget identity. */
	public function test_valid_dispatch_and_budget_identity(): void {
		$budget     = new SiteDispatcherBudget();
		$dispatcher = $this->dispatcher( true, $budget );
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$called = 0;
		$dispatcher->register(
			'xnetvn/get-post',
			static function ( array $arguments, SiteAuthContext $context ) use ( &$called ): array {
				++$called;
				return array( 'machine' => true );
			}
		);
		self::assertSame( array( 'machine' => true ), $dispatcher->execute( 'xnetvn/get-post', array(), $this->context ) );
		self::assertSame( 1, $called );
		self::assertSame( array( 'site:1:abcdefghijkl' ), $budget->identities );
	}

	/** Registry hides unhandled names, routes site calls to the dispatcher and fails conflicts closed. */
	public function test_registry_site_paths(): void {
		$request = new RequestScope();
		$request->bind_site( $this->context );
		$output     = new OutputPipeline( $this->settings );
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$registry = new Registry( new Policy( $this->settings ), $request, $output, null, new SafeMcpEnvelope(), $this->policy, $dispatcher );
		$registry->add( $this->definition );
		self::assertSame( array(), $registry->names_for_site_scope( $this->context->credential->scope ) );
		self::assertFalse( $registry->allowed( $this->definition ) );
		$dispatcher->register(
			'xnetvn/get-post',
			static fn( array $arguments, SiteAuthContext $context ): array => array( 'machine' => true )
		);
		self::assertSame( array( 'xnetvn/get-post' ), $registry->names_for_site_scope( $this->context->credential->scope ) );
		self::assertTrue( $registry->allowed( $this->definition ) );
		self::assertSame( array( 'machine' => true ), $registry->execute( 'xnetvn/get-post', array() ) );
		$api              = new ApiKey( 1, new KeyId( 'hijklmnopqrs' ), 7, 'Human', new Scope( RiskTier::Read ), new DateTimeImmutable( '@' . time() ) );
		$request->context = new AuthContext( $api, '0123456789abcdef' );
		self::assertFalse( $registry->allowed( $this->definition ) );
		self::assertSame( array(), $registry->visible() );
		$denied = $registry->execute( 'xnetvn/get-post', array() );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
	}

	/** The tier-raising hook changes the resolved descriptor and the machine decision. */
	public function test_resolved_descriptor_uses_tier_hook(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				return 'xnetvn_agent_hub_wp_ability_tier' === $tag ? RiskTier::Admin : $value;
			}
		);
		$registry = new Registry( new Policy( $this->settings ), new RequestScope(), new OutputPipeline( $this->settings ), null, new SafeMcpEnvelope(), $this->policy, $this->dispatcher() );
		$registry->add( $this->definition );
		self::assertSame( RiskTier::Admin, $registry->resolved_descriptor( 'xnetvn/get-post' )?->tier );
		self::assertSame( array(), $registry->names_for_site_scope( $this->context->credential->scope ) );
	}

	/** Registration without a bound catalog is refused. */
	public function test_unbound_registration_is_refused(): void {
		$this->expectException( \LogicException::class );
		$this->dispatcher()->register( 'xnetvn/get-post', static fn( array $arguments, SiteAuthContext $context ): array => array() );
	}

	/** Every independent policy term denies, and the moderation grant is explicit. */
	public function test_policy_terms(): void {
		$read  = $this->definition->descriptor();
		$write = new AbilityDescriptor( 'xnetvn/update-post', RiskTier::Write, 'content', 'edit_posts' );
		$third = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'edit_posts', true );
		$this->settings->update( array( 'enabled' => false ) );
		self::assertSame( 'site_disabled', $this->policy->evaluate_scope( $read, $this->context->credential->scope )->reason );
		$this->settings->update(
			array(
				'enabled'      => true,
				'tier_ceiling' => 'read',
			)
		);
		$write_scope = new SiteScope( RiskTier::Write, array( 'content' ), array( 'xnetvn/update-post' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		self::assertSame( 'site_tier', $this->policy->evaluate_scope( $write, $write_scope )->reason );
		$this->settings->update(
			array(
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => false,
			)
		);
		$admin_scope = new SiteScope( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		$admin       = new AbilityDescriptor( 'xnetvn/set-user-status', RiskTier::Admin, 'users', 'edit_users' );
		self::assertSame( 'admin_disabled', $this->policy->evaluate_scope( $admin, $admin_scope )->reason );
		self::assertSame( 'approval_required', $this->policy->evaluate_scope( $third, $this->context->credential->scope )->reason );
		$this->settings->update( array( 'allow_admin_tier' => true ) );
		self::assertSame( 'permission_required', $this->policy->evaluate( $admin, $this->context_for( $admin_scope ), 'http' )->reason );
		$ban_scope = new SiteScope( RiskTier::Admin, array( 'users' ), array( 'xnetvn/set-user-status' ), array(), array( 'ban-users' ), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		self::assertTrue( $this->policy->evaluate( $admin, $this->context_for( $ban_scope ), 'http' )->allowed );
	}

	/** The site filter can only deny; a permitting hook cannot widen a scope denial. */
	public function test_policy_deny_only_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				return 'xnetvn_agent_hub_wp_site_policy_decision' === $tag ? Decision::deny( 'extension_denied' ) : $value;
			}
		);
		$decision = $this->policy->evaluate( $this->definition->descriptor(), $this->context, 'http' );
		self::assertFalse( $decision->allowed );
		self::assertSame( 'extension_denied', $decision->reason );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => 'xnetvn_agent_hub_wp_site_policy_decision' === $tag ? false : $value
		);
		self::assertSame( 'extension_denied', $this->policy->evaluate( $this->definition->descriptor(), $this->context, 'http' )->reason );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value ): mixed => 'xnetvn_agent_hub_wp_site_policy_decision' === $tag ? true : $value
		);
		$narrow     = new SiteScope( RiskTier::Write, array( 'content' ), array( 'xnetvn/list-posts' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), self::BLOG, 'Narrow', $narrow, new DateTimeImmutable( '@' . time() ) );
		$denied     = new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
		self::assertFalse( $this->policy->evaluate( $this->definition->descriptor(), $denied, 'http' )->allowed );
	}

	/** A cross-blog site context never dispatches. */
	public function test_cross_blog_dispatch_is_refused(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$dispatcher->register( 'xnetvn/get-post', static fn( array $arguments, SiteAuthContext $context ): array => array( 'machine' => true ) );
		$scope      = new SiteScope( RiskTier::Write, array( 'content' ), array( 'xnetvn/get-post' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), 2, 'Other blog', $scope, new DateTimeImmutable( '@' . time() ) );
		$other      = new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
		$result     = $dispatcher->execute( 'xnetvn/get-post', array(), $other );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
	}

	/** A tier-raising hook removes a registered handler from the site listing and denies it. */
	public function test_tier_hook_excludes_a_registered_handler(): void {
		$request = new RequestScope();
		$request->bind_site( $this->context );
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$dispatcher->register( 'xnetvn/get-post', static fn( array $arguments, SiteAuthContext $context ): array => array( 'machine' => true ) );
		$registry = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ), null, new SafeMcpEnvelope(), $this->policy, $dispatcher );
		$registry->add( $this->definition );
		self::assertSame( array( 'xnetvn/get-post' ), $registry->names_for_site_scope( $this->context->credential->scope ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value ) {
				return 'xnetvn_agent_hub_wp_ability_tier' === $tag ? RiskTier::Admin : $value;
			}
		);
		self::assertSame( array(), $registry->names_for_site_scope( $this->context->credential->scope ) );
		self::assertFalse( $registry->allowed( $this->definition ) );
	}

	/** A revoked credential is denied before any scope term. */
	public function test_revoked_credential_is_denied(): void {
		$revoked = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), self::BLOG, 'Revoked', $this->context->credential->scope, new DateTimeImmutable( '@' . time() ), new DateTimeImmutable( '@' . time() ) );
		$context = new SiteAuthContext( $revoked, '0123456789abcdef', '203.0.113.7' );
		self::assertSame( 'credential_revoked', $this->policy->evaluate( $this->definition->descriptor(), $context, 'http' )->reason );
	}

	/** A site principal without the machine dependencies fails closed everywhere. */
	public function test_unwired_site_dependencies_fail_closed(): void {
		$request = new RequestScope();
		$request->bind_site( $this->context );
		$registry = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ), null, new SafeMcpEnvelope() );
		$registry->add( $this->definition );
		self::assertFalse( $registry->allowed( $this->definition ) );
		self::assertSame( array(), $registry->visible() );
		$result = $registry->execute( 'xnetvn/get-post', array() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
	}

	/** The core execute callback (invoke) routes the site principal to the dispatcher. */
	public function test_core_invoke_site_route(): void {
		$request = new RequestScope();
		$request->bind_site( $this->context );
		$dispatcher = $this->dispatcher();
		$dispatcher->bind_catalog( $this->catalog, fn( string $name ): ?AbilityDescriptor => $this->catalog->get( $name )?->descriptor() );
		$dispatcher->register( 'xnetvn/get-post', static fn( array $arguments, SiteAuthContext $context ): array => array( 'machine' => true ) );
		$registry = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ), null, new SafeMcpEnvelope(), $this->policy, $dispatcher );
		$registry->add( $this->definition );
		$method = new \ReflectionMethod( Registry::class, 'invoke' );
		$method->setAccessible( true );
		self::assertSame( array( 'machine' => true ), $method->invoke( $registry, $this->definition, array() ) );
	}

	/**
	 * Build a site context for a scope.
	 *
	 * @param SiteScope $scope Scope.
	 * @return SiteAuthContext
	 */
	private function context_for( SiteScope $scope ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), self::BLOG, 'Fixture', $scope, new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
	}

	/**
	 * Build the dispatcher.
	 *
	 * @param bool                 $costly Whether the fixture is costly.
	 * @param ExpensiveBudget|null $budget Optional budget.
	 * @return SiteDispatcher
	 */
	private function dispatcher( bool $costly = false, ?ExpensiveBudget $budget = null ): SiteDispatcher {
		$this->definition = new SiteDispatcherFixture( $costly );
		$this->catalog    = new SiteDispatcherCatalog( $this->definition );
		return new SiteDispatcher( $this->policy, new OutputPipeline( $this->settings ), $budget );
	}
}
