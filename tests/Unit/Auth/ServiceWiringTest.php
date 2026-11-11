<?php
/**
 * Authentication service wiring tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Support\SystemClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Registration wires only the documented late-authentication hooks. */
final class ServiceWiringTest extends TestCase {

	/** Prepare hook mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/** Close Mockery expectations. */
	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The authenticator registers four hooks and never a cookie bridge. */
	public function test_authenticator_registers_late_identity_hooks(): void {
		$settings = new MemorySettings();
		$scope    = new RequestScope();
		$auth     = new Authenticator(
			Mockery::mock( KeyRepository::class ),
			new PepperStore(),
			$settings,
			new HeaderExtractor( $settings ),
			Mockery::mock( RateLimiter::class ),
			new TransportGuard( $settings ),
			$scope,
			new SystemClock()
		);

		Filters\expectAdded( 'determine_current_user' )->once()->with( array( $auth, 'determine_user' ), 20 );
		Filters\expectAdded( 'rest_authentication_errors' )->once()->with( array( $auth, 'rest_result' ), 80 );
		Filters\expectAdded( 'rest_post_dispatch' )->once()->with( array( $auth, 'response_headers' ), 10, 3 );
		Actions\expectAdded( 'shutdown' )->once()->with( array( $scope, 'reset' ), PHP_INT_MAX );

		$auth->register();
		$this->addToAssertionCount( 1 );
	}
}
