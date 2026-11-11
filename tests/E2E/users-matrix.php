<?php
/**
 * Real HTTPS users round trips with role-scoped keys.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\E2E\McpClient;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 ); }
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client.
$xnetvn_agent_hub_wp_state  = json_decode( file_get_contents( '/work/.local/http-matrix/state.json' ), true, 64, JSON_THROW_ON_ERROR );
$xnetvn_agent_hub_wp_host   = getenv( 'wp71' === getenv( 'XNETVN_MATRIX_SITE' ) ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' );
$xnetvn_agent_hub_wp_client = new McpClient( 'https://' . $xnetvn_agent_hub_wp_host, array( $xnetvn_agent_hub_wp_host ), true );
/**
 * Send through real TLS and the installed MCP adapter.
 *
 * @param string              $kind Verified fixture key kind.
 * @param string              $method MCP method.
 * @param array<string,mixed> $params Parameters.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_users_send( string $kind, string $method, array $params = array() ): array {
	global $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_state;
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'users-acceptance',
			'version' => '1',
		),
	);
	$headers         = array(
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => $method,
	);
	if ( isset( $params['name'] ) ) {
		$headers['Mcp-Name'] = $params['name'];
	}
	return $xnetvn_agent_hub_wp_client->request(
		'/wp-json/xnetvn-agent-hub-wp/mcp',
		json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			),
			JSON_THROW_ON_ERROR
		),
		$xnetvn_agent_hub_wp_state['keys'][ $kind ]['token'],
		$headers
	);
}
/**
 * Require successful structured data.
 *
 * @param string              $kind Key kind.
 * @param string              $tool Tool name.
 * @param array<string,mixed> $args Tool args.
 * @return array<string,mixed>
 * @throws \RuntimeException When structured content is missing.
 */
function xnetvn_agent_hub_wp_users_call( string $kind, string $tool, array $args = array() ): array {
	$res = xnetvn_agent_hub_wp_users_send(
		$kind,
		'tools/call',
		array(
			'name'      => $tool,
			'arguments' => (object) $args,
		)
	);
	if ( ! isset( $res['body']['result']['structuredContent'] ) || ! is_array( $res['body']['result']['structuredContent'] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'Users matrix missing structured content for ' . $tool );
	}
	return $res['body']['result']['structuredContent'];
}

$xnetvn_agent_hub_wp_list = xnetvn_agent_hub_wp_users_call( 'users_admin', 'xnetvn-list-users', array() );
if ( ! isset( $xnetvn_agent_hub_wp_list['items'], $xnetvn_agent_hub_wp_list['total'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'Users list contract failed.' ); }
$xnetvn_agent_hub_wp_me = xnetvn_agent_hub_wp_users_call( 'users_contributor', 'xnetvn-get-my-profile', array() );
if ( ! isset( $xnetvn_agent_hub_wp_me['id'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'My profile contract failed.' ); }
$xnetvn_agent_hub_wp_roles = xnetvn_agent_hub_wp_users_call( 'users_admin', 'xnetvn-list-roles', array() );
if ( ! isset( $xnetvn_agent_hub_wp_roles['items'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'Roles contract failed.' ); }
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
echo "users-matrix ok\n";
