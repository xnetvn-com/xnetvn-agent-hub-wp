<?php
/**
 * Local browser fixture with an explicit mail sink.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	exit( 1 );
}

$xnetvn_agent_hub_wp_sink = WPMU_PLUGIN_DIR . '/xnetvn-agent-hub-wp-e2e-mail-sink.php';
$xnetvn_agent_hub_wp_code = "<?php\n/** Local test mail sink. */\nadd_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );\n";
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the fixed local-test fixture, never a remote URL.
if ( is_file( $xnetvn_agent_hub_wp_sink ) && file_get_contents( $xnetvn_agent_hub_wp_sink ) !== $xnetvn_agent_hub_wp_code ) {
	exit( 1 );
}
wp_mkdir_p( WPMU_PLUGIN_DIR );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the fixed local-test fixture to the dedicated wp-env test site, never product or reference code.
if ( false === file_put_contents( $xnetvn_agent_hub_wp_sink, $xnetvn_agent_hub_wp_code ) ) {
	exit( 1 );
}
