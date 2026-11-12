<?php
/**
 * Fail-closed adapter boundary tests for a missing or unsupported companion.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Mcp;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** The adapter boundary must never take down a site that lacks the companion. */
final class ServerGatewayAdapterGuardTest extends TestCase {

	/** Set up WordPress function mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'did_action' )->justReturn( 0 );
		if ( ! defined( 'XNETVN_AGENT_HUB_WP_VERSION' ) ) {
			define( 'XNETVN_AGENT_HUB_WP_VERSION', '1.0.0' );
		}
	}

	/** Reset WordPress function mocks. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A missing hook argument is reported, never thrown as a TypeError. */
	public function test_missing_adapter_argument_fails_closed_without_throwing(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'network_admin_notices' )->once();
		$gateway = $this->gateway( null );

		$gateway->create( null );

		self::assertFalse( $gateway->ready() );
	}

	/** An argument-less hook call is the operative fail-closed path. */
	public function test_zero_argument_hook_call_fails_closed(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'network_admin_notices' )->once();
		$gateway = $this->gateway( '0.7.0' );

		$gateway->create();

		self::assertFalse( $gateway->ready() );
	}

	/** An object without the facade method is reported, never invoked. */
	public function test_object_without_create_server_is_rejected(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'network_admin_notices' )->once();
		$gateway = $this->gateway( '0.7.0' );

		$gateway->create( new \stdClass() );

		self::assertFalse( $gateway->ready() );
	}

	/** An unsupported runtime version never reaches create_server. */
	public function test_unsupported_adapter_is_not_invoked(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'network_admin_notices' )->once();
		$gateway = $this->gateway( '0.8.0' );
		$adapter = new class() {
			/** Whether the adapter was called.
			 *
			 * @var bool
			 */
			public bool $called = false;

			/**
			 * Fail if an unsupported facade invokes the adapter.
			 *
			 * @param mixed ...$args Arguments.
			 */
			public function create_server( mixed ...$args ): void {
				$this->called = true;
			}
		};

		$gateway->create( $adapter );

		self::assertFalse( $adapter->called );
		self::assertFalse( $gateway->ready() );
	}

	/** A supported adapter still receives the exact server contract. */
	public function test_supported_adapter_still_creates_the_server(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$gateway = $this->gateway( '0.7.0' );
		$adapter = new class() {
			/** Captured call arguments.
			 *
			 * @var array<mixed>
			 */
			public array $arguments = array();

			/**
			 * Capture the known versioned API call.
			 *
			 * @param mixed ...$args Arguments.
			 * @return self
			 */
			public function create_server( mixed ...$args ): self {
				$this->arguments = $args;
				return $this;
			}
		};

		$gateway->create( $adapter );

		self::assertTrue( $gateway->ready() );
		self::assertSame( 'xnetvn-agent-hub-wp', $adapter->arguments[0] );
		self::assertSame( 'xnetvn-agent-hub-wp', $adapter->arguments[1] );
		self::assertSame( 'mcp', $adapter->arguments[2] );
	}

	/**
	 * Build a gateway without WordPress or the adapter.
	 *
	 * @param string|null $version Runtime adapter version.
	 * @return ServerGateway
	 */
	private function gateway( ?string $version ): ServerGateway {
		$settings = new MemorySettings( array( 'enabled' => true ) );
		$request  = new class() implements RequestContext {
			/** No delegated identity in this fixture. */
			public function current(): ?AuthContext {
				return null;
			}

			/** Keep the boundary out of the HTTP route matcher. */
			public function transport(): string {
				return 'stdio';
			}

			/** No measured budget in this fixture. */
			public function rate_budget(): ?RateLimitResult {
				return null;
			}
		};
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		return new ServerGateway( $registry, $request, $settings, static fn(): ?string => $version );
	}
}
