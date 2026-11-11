<?php
/**
 * Capability memo regression tests for the MCP tool list.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\CapabilityMemo;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves the memo changes no decision and never replaces a live capability check. */
final class CapabilityMemoRegressionTest extends WP_UnitTestCase {

	/** The five standard roles of the capability matrix. */
	private const ROLES = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/** Every role and every catalog capability keeps the same decision with and without the memo. */
	public function test_allowed_matches_the_live_decision_for_every_role_and_capability(): void {
		$capabilities = $this->catalog_capabilities();
		self::assertNotEmpty( $capabilities, 'The catalog must expose at least one visibility capability.' );
		foreach ( self::ROLES as $role ) {
			$actor            = self::factory()->user->create( array( 'role' => $role ) );
			$request          = new RequestScope();
			$request->context = $this->context( $actor );
			$calls            = array();
			$policy           = new Policy(
				new MemorySettings(),
				static function ( string $capability, ?AuthContext $context ) use ( &$calls ): bool {
					$calls[] = ( null === $context ? 0 : $context->user_id ) . '|' . $capability;
					return null === $context ? current_user_can( $capability ) : user_can( $context->user_id, $capability );
				}
			);
			$registry         = new Registry( $policy, $request, new OutputPipeline( new MemorySettings() ) );
			$definitions      = array();
			foreach ( $capabilities as $index => $capability ) {
				$definition    = $this->definition( 'xnetvn/test-capability-' . $index, $capability );
				$definitions[] = $definition;
				$registry->add( $definition );
			}
			wp_set_current_user( $actor );
			$memo = new CapabilityMemo();
			foreach ( $definitions as $definition ) {
				$live   = $registry->allowed( $definition );
				$memoed = $registry->allowed( $definition, $memo );
				self::assertSame( $live, $memoed, 'The memo must not change the decision of ' . $definition->descriptor()->visibility_cap . ' for ' . $role . '.' );
			}
			$checked = array();
			foreach ( $calls as $call ) {
				$checked[ $call ] = true;
			}
			foreach ( $capabilities as $capability ) {
				self::assertArrayHasKey( $actor . '|' . $capability, $checked, 'Every distinct pair must be checked live for ' . $role . '.' );
			}
			wp_set_current_user( 0 );
		}
	}

	/** One tool list round checks every distinct pair live once and keeps the tool list unchanged. */
	public function test_tools_list_checks_every_distinct_pair_live_once_per_round(): void {
		$actor            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$request          = new RequestScope();
		$request->context = $this->context( $actor );
		$allow            = true;
		$calls            = 0;
		$policy           = new Policy(
			new MemorySettings(),
			static function ( string $capability, ?AuthContext $context ) use ( &$allow, &$calls ): bool {
				++$calls;
				return $allow && ( null === $context ? current_user_can( $capability ) : user_can( $context->user_id, $capability ) );
			}
		);
		$registry         = new Registry( $policy, $request, new OutputPipeline( new MemorySettings() ) );
		$names            = array( 'xnetvn/test-capability-read', 'xnetvn/test-capability-read-two', 'xnetvn/test-capability-edit' );
		$caps             = array( 'read', 'read', 'edit_posts' );
		foreach ( $names as $index => $name ) {
			$registry->add( $this->definition( $name, $caps[ $index ] ) );
		}
		wp_set_current_user( $actor );
		$gateway = new ServerGateway( $registry, $request, new MemorySettings(), static fn(): ?string => '0.7.0' );
		$server  = $this->server();
		$tools   = array();
		foreach ( $names as $index => $name ) {
			$tools[] = $this->tool( str_replace( '/', '-', $name ) );
		}
		$listed = $gateway->tools_list( $tools, $server, new \stdClass() );
		self::assertCount( 3, $listed );
		self::assertSame( 2, $calls, 'One round must check every distinct pair live exactly once.' );
		$allow = false;
		self::assertWPError( $gateway->pre_call( array(), 'xnetvn/test-capability-read', $tools[0], $server ) );
		$calls = 0;
		self::assertSame( array(), $gateway->tools_list( $tools, $server, new \stdClass() ) );
		self::assertSame( 2, $calls, 'A new round must check every distinct pair live again.' );
		wp_set_current_user( 0 );
	}

	/**
	 * The visibility capabilities of the registered catalog.
	 *
	 * @return array<string,string> Visibility capability per tool name.
	 */
	private function catalog_capabilities(): array {
		$capabilities = array();
		foreach ( wp_get_abilities() as $ability ) {
			$meta       = $ability->get_meta();
			$capability = $meta['xnetvn']['visibility_cap'] ?? null;
			if ( is_string( $capability ) && '' !== $capability ) {
				$capabilities[ $capability ] = true;
			}
		}
		$capabilities = array_keys( $capabilities );
		sort( $capabilities, SORT_STRING );
		return $capabilities;
	}

	/**
	 * A delegated identity of the actor with a read scope.
	 *
	 * @param int $actor Actor identity.
	 * @return AuthContext
	 */
	private function context( int $actor ): AuthContext {
		return new AuthContext(
			new ApiKey( 1, new KeyId( 'abcdefghijkl' ), $actor, 'Memo fixture', new Scope( RiskTier::Read, array( 'content' ) ), new \DateTimeImmutable( '@1791072000' ) ),
			str_repeat( 'a', 16 )
		);
	}

	/**
	 * A private fixture definition bound to one visibility capability.
	 *
	 * @param string $name Canonical name.
	 * @param string $capability Visibility capability.
	 * @return AbilitySpec
	 */
	private function definition( string $name, string $capability ): AbilitySpec {
		return ( new DefinitionFactory() )->create(
			array(
				'name'          => $name,
				'label'         => 'Memo fixture',
				'description'   => 'Returns a safe private fixture. Use this only to verify the capability decision of the memoized tool list.',
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
				'visibilityCap' => $capability,
				'handler'       => static fn(): array => array( 'id' => 7 ),
				'example'       => array( 'id' => 7 ),
			)
		);
	}

	/**
	 * The facade server identity the gateway owns.
	 *
	 * @return object Facade server identity.
	 */
	private function server(): object {
		return new class() {
			/** The reviewed server identity.
			 *
			 * @return string
			 */
			public function get_server_id(): string {
				return ServerGateway::SERVER_ID;
			}
		};
	}

	/**
	 * One advertised tool record of the adapter schema.
	 *
	 * @param string $name Wire name.
	 * @return object
	 */
	private function tool( string $name ): object {
		return new class( $name ) {
			/** Wire name.
			 *
			 * @var string
			 */
			private string $name;
			/**
			 * Bind the wire name.
			 *
			 * @param string $name Wire name.
			 */
			public function __construct( string $name ) {
				$this->name = $name;
			}
			/** The exact wire name.
			 *
			 * @return string
			 */
			public function getName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The adapter tool record exposes this exact method name.
				return $this->name;
			}
		};
	}
}
