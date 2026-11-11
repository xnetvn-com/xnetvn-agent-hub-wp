<?php
/**
 * WordPress integration bootstrap.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

$xnetvn_agent_hub_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $xnetvn_agent_hub_wp_tests_dir ) {
	$xnetvn_agent_hub_wp_tests_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress test-suite configuration constant.
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}
require_once $xnetvn_agent_hub_wp_tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$xnetvn_agent_hub_wp_adapter = ABSPATH . 'wp-content/plugins/mcp-adapter/mcp-adapter.php';
		if ( is_readable( $xnetvn_agent_hub_wp_adapter ) ) {
			require_once $xnetvn_agent_hub_wp_adapter;
		}
		require dirname( __DIR__, 2 ) . '/xnetvn-agent-hub-wp.php';
	}
);
require $xnetvn_agent_hub_wp_tests_dir . '/includes/bootstrap.php';

// No integration fixture may deliver email outside the isolated local test environment.
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
