<?php
/**
 * Adapter facade registration and scoped listing contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** A fake boundary pins call signatures; installed-adapter E2E is a separate gate. */
final class ServerGatewayTest extends WP_UnitTestCase {
	/** Explicit server registration uses exact namespace/route and fails closed without context. */
	public function test_server_registration_contract(): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$gateway  = new ServerGateway( $registry, $request, $settings, static fn(): ?string => '0.7.0' );
		$adapter  = new class() {
			/** Captured API arguments.
			 *
			 * @var array<mixed>
			 */
			public array $arguments = array();
			/**
			 * Capture the known versioned API call.
			 *
			 * @param mixed ...$args Call arguments.
			 * @return self
			 */
			public function create_server( mixed ...$args ): self {
				$this->arguments = $args;
				return $this;
			}
		};
		$gateway->create( $adapter );
		self::assertSame( 'xnetvn-agent-hub-wp', $adapter->arguments[0] );
		self::assertSame( 'xnetvn-agent-hub-wp', $adapter->arguments[1] );
		self::assertSame( 'mcp', $adapter->arguments[2] );
		self::assertSame( array(), $adapter->arguments[10] );
		self::assertSame( array(), $adapter->arguments[11] );
		self::assertFalse( $adapter->arguments[12]() );
	}

	/** Unsupported runtime versions leave the plugin loaded but never call the adapter. */
	public function test_unsupported_adapter_does_not_create_server(): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$gateway  = new ServerGateway( new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) ), $request, $settings, static fn(): ?string => '0.8.0' );
		$adapter  = new class() {
			/**
			 * Fail if called by an unsupported facade.
			 *
			 * @param mixed ...$args Arguments.
			 * @throws \RuntimeException If an unsupported adapter is invoked.
			 */
			public function create_server( mixed ...$args ): void {
				throw new \RuntimeException( 'Unsupported adapter was invoked.' );
			}
		};
		$gateway->create( $adapter );
		self::assertFalse( $gateway->ready() );
	}

	/** The advertised tool list follows the transport and the route, and never the other way round. */
	public function test_advertised_tool_list_follows_transport_and_route(): void {
		$settings  = new MemorySettings();
		$transport = 'stdio';
		$request   = new class( $transport ) implements \XNetVN\AgentHubWP\Contracts\RequestContext {
			/** Current transport.
			 *
			 * @var string
			 */
			public string $transport;
			/**
			 * Bind the transport under test.
			 *
			 * @param string $transport Transport name.
			 */
			public function __construct( string $transport ) {
				$this->transport = $transport;
			}
			/**
			 * No delegated identity in this fixture.
			 *
			 * @return null
			 */
			public function current(): ?\XNetVN\AgentHubWP\Domain\AuthContext {
				return null;
			}
			/**
			 * Read the transport under test.
			 *
			 * @return string
			 */
			public function transport(): string {
				return $this->transport;
			}
			/**
			 * No measured budget in this fixture.
			 *
			 * @return null
			 */
			public function rate_budget(): ?\XNetVN\AgentHubWP\Domain\RateLimitResult {
				return null;
			}
		};
		$registry  = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$registry->add(
			( new \XNetVN\AgentHubWP\Abilities\DefinitionFactory() )->create(
				array(
					'name'          => 'xnetvn/test-gateway-tools',
					'label'         => 'Gateway fixture',
					'description'   => 'Returns a safe private fixture. Use this to verify the advertised tool list of the MCP server.',
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
					'tier'          => \XNetVN\AgentHubWP\Domain\RiskTier::Read,
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
			)
		);
		$adapter = new class() {
			/** Captured API arguments.
			 *
			 * @var array<mixed>
			 */
			public array $arguments = array();
			/**
			 * Capture the known versioned API call.
			 *
			 * @param mixed ...$args Call arguments.
			 * @return self
			 */
			public function create_server( mixed ...$args ): self {
				$this->arguments = $args;
				return $this;
			}
		};
		$gateway = new ServerGateway( $registry, $request, $settings, static fn(): ?string => '0.7.0' );
		$gateway->create( $adapter );
		self::assertSame( array( 'xnetvn/test-gateway-tools' ), $adapter->arguments[9] );
		$request->transport = 'http';
		$gateway->create( $adapter );
		self::assertSame( array(), $adapter->arguments[9] );
	}

	/**
	 * Upstream schema-record objects must normalize identically across protocol HTTP statuses.
	 *
	 * @param int $upstream_status Upstream status.
	 * @dataProvider upstream_tool_statuses
	 */
	public function test_unknown_tool_record_is_uniform( int $upstream_status ): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$gateway  = new ServerGateway( new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) ), $request, $settings, static fn(): ?string => '0.7.0' );
		$http     = new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/mcp' );
		$http->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'xnetvn-unknown',
						'arguments' => array(),
					),
				)
			)
		);
		$response   = new \WP_REST_Response(
			(object) array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'error'   => (object) array(
					'code'    => -32602,
					'message' => 'Tool not found: xnetvn-unknown',
				),
			),
			$upstream_status
		);
		$normalized = $gateway->normalize_denied( $response, rest_get_server(), $http );
		self::assertSame( 200, $normalized->get_status() );
		$result = $normalized->get_data();
		self::assertIsArray( $result );
		self::assertTrue( $result['result']['isError'] );
		self::assertSame( 'The tool is unavailable.', $result['result']['content'][0]['text'] );
	}

	/**
	 * Legacy and modern upstream error status variants.
	 *
	 * @return array<string,array{int}>
	 */
	public static function upstream_tool_statuses(): array {
		return array(
			'legacy' => array( 200 ),
			'modern' => array( 400 ),
		);
	}

	/**
	 * Invalid parameter envelopes remain wire errors when the named tool is unavailable.
	 *
	 * @param string $message Upstream wire failure.
	 * @dataProvider unrelated_parameter_errors
	 */
	public function test_unrelated_parameter_error_is_preserved( string $message ): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$gateway  = new ServerGateway( new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) ), $request, $settings, static fn(): ?string => '0.7.0' );
		$http     = new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/mcp' );
		$http->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'xnetvn-unknown',
						'arguments' => 'malformed',
					),
				)
			)
		);
		$payload    = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'error'   => array(
				'code'    => -32602,
				'message' => $message,
			),
		);
		$normalized = $gateway->normalize_denied( new \WP_REST_Response( $payload, 400 ), rest_get_server(), $http );
		self::assertSame( 400, $normalized->get_status() );
		self::assertSame( $payload, $normalized->get_data() );
	}

	/**
	 * Distinct upstream errors share INVALID_PARAMS but are not unavailable-tool outcomes.
	 *
	 * @return array<string,array{string}>
	 */
	public static function unrelated_parameter_errors(): array {
		return array(
			'params'     => array( 'Invalid params: malformed arguments' ),
			'validation' => array( 'Validation error: expected object' ),
			'missing'    => array( 'Missing required parameter: arguments' ),
		);
	}
}
