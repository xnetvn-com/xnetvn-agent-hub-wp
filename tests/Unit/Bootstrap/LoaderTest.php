<?php
/**
 * Fail-closed loader tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Bootstrap;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Bootstrap\Loader;

/** Ensures failed requirements cannot boot the kernel. */
final class LoaderTest extends TestCase {

	/** Set up hook mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/** Reset hook mocks. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Injected failures only register notices. */
	public function test_failure_does_not_boot_kernel(): void {
		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'network_admin_notices' )->once();
		Actions\expectDone( 'xnetvn_agent_hub_wp_loaded' )->never();
		self::assertFalse(
			Loader::run(
				'missing/plugin.php',
				array(
					array(
						'code'     => 'php_version',
						'required' => '8.2.0',
						'actual'   => '7.4.0',
					),
				)
			)
		);
	}
}
