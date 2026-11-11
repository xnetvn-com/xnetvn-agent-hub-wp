<?php
/**
 * Real WordPress failure notice tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Bootstrap\Loader;

/** Checks capability gates and output escaping. */
final class LoaderFailureTest extends WP_UnitTestCase {

	/**
	 * Capture output without leaking an output buffer on failure.
	 *
	 * @return string
	 */
	private function render_notices(): string {
		ob_start();
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the core admin notice lifecycle.
			do_action( 'admin_notices' );
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/** Failed requirements cannot dispatch another loaded action. */
	public function test_failure_notice_is_escaped_and_capability_gated(): void {
		$before = did_action( 'xnetvn_agent_hub_wp_loaded' );
		self::assertFalse(
			Loader::run(
				XNETVN_AGENT_HUB_WP_FILE,
				array(
					array(
						'code'     => 'php_version',
						'required' => '8.2.0',
						'actual'   => '<script>7.4.0</script>',
					),
				)
			)
		);
		self::assertSame( $before, did_action( 'xnetvn_agent_hub_wp_loaded' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		try {
			$notice = $this->render_notices();
		} finally {
			if ( is_multisite() ) {
				revoke_super_admin( $admin );
			}
		}
		self::assertStringContainsString( 'requires PHP 8.2.0', $notice );
		self::assertStringContainsString( '&lt;script&gt;', $notice );
		self::assertStringNotContainsString( '<script>', $notice );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertSame( '', $this->render_notices() );
	}
}
