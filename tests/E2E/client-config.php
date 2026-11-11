<?php
/**
 * Private real-client config from the already owned ephemeral HTTP fixture.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}
// phpcs:disable WordPress.WP.AlternativeFunctions -- Only fixed private paths in the owned toolbox; no credential leaves the server.
$xnetvn_agent_hub_wp_client_dir = '/work/.local/mcp-clients-run';
if ( file_exists( $xnetvn_agent_hub_wp_client_dir ) || is_link( $xnetvn_agent_hub_wp_client_dir ) ) {
	throw new RuntimeException( 'Reconcile the existing real-client fixture.' );
}
$xnetvn_agent_hub_wp_client_state = json_decode( file_get_contents( '/work/.local/http-matrix/state.json' ), true, 64, JSON_THROW_ON_ERROR );
if ( ! is_array( $xnetvn_agent_hub_wp_client_state ) || ! isset( $xnetvn_agent_hub_wp_client_state['keys']['read']['token'], $xnetvn_agent_hub_wp_client_state['users']['owner'] ) ) {
	throw new RuntimeException( 'Private HTTP fixture is unavailable.' );
}
if ( '/work/.local' !== realpath( '/work/.local' ) || ! mkdir( $xnetvn_agent_hub_wp_client_dir, 0700 ) ) {
	throw new RuntimeException( 'Private client directory unavailable.' );
}
$xnetvn_agent_hub_wp_client_host = '1' === getenv( 'XNETVN_CLIENT_TLS_FIXTURE' ) ? 'wp69' : getenv( 'RTL_ALIAS_WP69' );
if ( ! is_string( $xnetvn_agent_hub_wp_client_host ) || false === filter_var( 'https://' . $xnetvn_agent_hub_wp_client_host, FILTER_VALIDATE_URL ) ) {
	throw new RuntimeException( 'Reviewed test destination is unavailable.' );
}
$xnetvn_agent_hub_wp_client_entry = array(
	'type'    => 'http',
	'url'     => 'https://' . $xnetvn_agent_hub_wp_client_host . '/wp-json/xnetvn-agent-hub-wp/mcp',
	'headers' => array( 'Authorization' => 'Bearer ' . $xnetvn_agent_hub_wp_client_state['keys']['read']['token'] ),
);
foreach ( array(
	'inspector' => array(
		'protocolEra'       => 'modern',
		'modernLogLevel'    => 'off',
		'connectionTimeout' => 15000,
		'requestTimeout'    => 15000,
	),
	'fastmcp'   => array(),
) as $xnetvn_agent_hub_wp_client_name => $xnetvn_agent_hub_wp_client_extra ) {
	$xnetvn_agent_hub_wp_client_config = array( 'mcpServers' => array( 'agent' => array_merge( $xnetvn_agent_hub_wp_client_entry, $xnetvn_agent_hub_wp_client_extra ) ) );
	$xnetvn_agent_hub_wp_client_file   = $xnetvn_agent_hub_wp_client_dir . '/' . $xnetvn_agent_hub_wp_client_name . '.json';
	if ( false === file_put_contents( $xnetvn_agent_hub_wp_client_file, json_encode( $xnetvn_agent_hub_wp_client_config, JSON_THROW_ON_ERROR ), LOCK_EX ) || ! chmod( $xnetvn_agent_hub_wp_client_file, 0600 ) ) {
		throw new RuntimeException( 'Private client config could not be recorded.' );
	}
}
WP_CLI::line( 'Private real-client configs prepared.' );
