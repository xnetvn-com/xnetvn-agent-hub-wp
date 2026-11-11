<?php
/**
 * Kernel registration tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Plugin;

/** Checks provider ordering and the public loaded hook. */
final class PluginTest extends TestCase {

	/** Prepare WordPress hook mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/** Clean up mock state. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Register providers once in their supplied order. */
	public function test_provider_order_and_single_loaded_action(): void {
		$order  = array();
		$first  = new class( $order, 'first' ) implements ServiceProvider {
			/** Shared registration record.
			 *
			 * @var array<string>
			 */
			private array $order;
			/** Provider name.
			 *
			 * @var string
			 */
			private string $name;
			/**
			 * Capture the shared test record.
			 *
			 * @param array<string> $order Shared record.
			 * @param string        $name Provider name.
			 */
			public function __construct( array &$order, string $name ) {
				$this->order = &$order;
				$this->name  = $name;
			}
			/** Record registration. */
			public function register(): void {
				$this->order[] = $this->name;
			}
		};
		$second = new class( $order ) implements ServiceProvider {
			/** Shared registration record.
			 *
			 * @var array<string>
			 */
			private array $order;
			/**
			 * Capture the shared test record.
			 *
			 * @param array<string> $order Shared record.
			 */
			public function __construct( array &$order ) {
				$this->order = &$order;
			}
			/** Record registration. */
			public function register(): void {
				$this->order[] = 'second';
			}
		};
		$plugin = new Plugin( 'plugin.php', array( $first, $second ) );
		self::assertSame( 'plugin.php', $plugin->plugin_file );
		Actions\expectDone( 'xnetvn_agent_hub_wp_loaded' )->once()->with( $plugin );
		$plugin->register();
		$plugin->register();
		self::assertSame( array( 'first', 'second' ), $order );
	}
}
