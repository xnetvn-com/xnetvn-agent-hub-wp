<?php
/**
 * Environment notice rendering tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Bootstrap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Bootstrap\Notice;

/** Ensures environment failures render a cause and a next step for administrators only. */
final class NoticeTest extends TestCase {

	/**
	 * Captured hook callbacks.
	 *
	 * @var array<string,array<int,callable>>
	 */
	private $callbacks = array();

	/**
	 * Whether the current actor may activate plugins.
	 *
	 * @var bool
	 */
	private $can_activate = true;

	/** Set up hook mocks. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->callbacks    = array();
		$this->can_activate = true;
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ): void {
				$this->callbacks[ $hook ][] = $callback;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ): bool {
				return $this->can_activate && 'activate_plugins' === $capability;
			}
		);
		Functions\when( '__' )->alias(
			function ( $text ): string {
				return (string) $text;
			}
		);
		Functions\when( 'esc_html' )->alias(
			function ( $text ): string {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);
	}

	/** Reset hook mocks. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the registered admin notice callbacks.
	 *
	 * @param array<array{code:string,required:string,actual:string}> $failures Requirement failures.
	 * @return string
	 */
	private function render_admin_notices( array $failures ): string {
		Notice::register( $failures );
		self::assertArrayHasKey( 'admin_notices', $this->callbacks );
		self::assertArrayHasKey( 'network_admin_notices', $this->callbacks );
		self::assertCount( 1, $this->callbacks['admin_notices'] );
		self::assertCount( 1, $this->callbacks['network_admin_notices'] );
		ob_start();
		try {
			foreach ( $this->callbacks['admin_notices'] as $callback ) {
				call_user_func( $callback );
			}
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Every known failure code names its component, values and next step.
	 *
	 * @dataProvider failure_cases
	 * @param string $code Failure code.
	 * @param string $required Required value.
	 * @param string $actual Actual value.
	 * @param string $component Component keyword.
	 * @param string $next_step Next-step keyword.
	 */
	public function test_failure_renders_cause_and_next_step( string $code, string $required, string $actual, string $component, string $next_step ): void {
		$notice = $this->render_admin_notices(
			array(
				array(
					'code'     => $code,
					'required' => $required,
					'actual'   => $actual,
				),
			)
		);
		self::assertStringContainsString( 'notice notice-error', $notice );
		self::assertStringContainsString( 'Requirement not met', $notice );
		self::assertStringContainsString( $component, $notice );
		$escaped_required = htmlspecialchars( $required, ENT_QUOTES, 'UTF-8' );
		$escaped_actual   = htmlspecialchars( $actual, ENT_QUOTES, 'UTF-8' );
		self::assertStringContainsString( $escaped_required, $notice );
		self::assertStringContainsString( $escaped_actual, $notice );
		if ( $escaped_required !== $required ) {
			self::assertStringNotContainsString( $required, $notice );
		}
		if ( $escaped_actual !== $actual ) {
			self::assertStringNotContainsString( $actual, $notice );
		}
		self::assertStringContainsString( 'Next step', $notice );
		self::assertStringContainsString( $next_step, $notice );
		self::assertStringNotContainsString( 'is-dismissible', $notice );
		self::assertStringNotContainsString( '<script', $notice );
		self::assertStringNotContainsString( '<link', $notice );
	}

	/**
	 * Return the six supported failure codes with expected keywords.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function failure_cases(): array {
		return array(
			'php_version'     => array( 'php_version', '8.2.0', '8.1.9', 'PHP', 'upgrade PHP' ),
			'wp_version'      => array( 'wp_version', '6.9', '6.8.3', 'WordPress', 'upgrade WordPress' ),
			'abilities_api'   => array( 'abilities_api', 'WordPress Abilities API', 'unavailable', 'Abilities API', 'Abilities API' ),
			'adapter_missing' => array( 'adapter_missing', 'MCP Adapter >=0.7.0 <0.8.0', 'unavailable', 'MCP Adapter', 'install and activate' ),
			'adapter_version' => array( 'adapter_version', '>=0.7.0 <0.8.0', '0.6.1', 'MCP Adapter', 'update' ),
			'autoloader'      => array( 'autoloader', 'vendor/autoload.php', 'unavailable', 'release ZIP', 'reinstall' ),
		);
	}

	/** Unknown codes keep a safe generic message without echoing the code. */
	public function test_unknown_code_renders_safe_message(): void {
		$notice = $this->render_admin_notices(
			array(
				array(
					'code'     => 'mystery_code',
					'required' => 'something',
					'actual'   => 'something-else',
				),
			)
		);
		self::assertStringContainsString( 'Requirement not met', $notice );
		self::assertStringNotContainsString( 'mystery_code', $notice );
	}

	/** Actors without activate_plugins see no notice. */
	public function test_notice_hidden_without_capability(): void {
		$this->can_activate = false;
		$notice             = $this->render_admin_notices(
			array(
				array(
					'code'     => 'php_version',
					'required' => '8.2.0',
					'actual'   => '8.1.9',
				),
			)
		);
		self::assertSame( '', $notice );
	}

	/** Markup in actual values is escaped, never executed or inserted. */
	public function test_actual_markup_is_escaped(): void {
		$notice = $this->render_admin_notices(
			array(
				array(
					'code'     => 'php_version',
					'required' => '8.2.0',
					'actual'   => '<script>7.4.0</script>',
				),
			)
		);
		self::assertStringContainsString( '&lt;script&gt;', $notice );
		self::assertStringNotContainsString( '<script>', $notice );
	}
}
