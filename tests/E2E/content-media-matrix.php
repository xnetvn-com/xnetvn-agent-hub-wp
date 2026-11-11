<?php
/**
 * Real HTTPS content/media round trips outside DIR_TESTDATA.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
use XNetVN\AgentHubWP\Tests\E2E\McpClient;
if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 ); }
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client; no WordPress bootstrap or logged credentials.
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
function xnetvn_agent_hub_wp_content_send( string $kind, string $method, array $params = array() ): array {
	global $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_state;
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'content-media-acceptance',
			'version' => '1',
		),
	);
	$headers         = array(
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => $method,
	);
	if ( isset( $params['name'] ) ) {
		$headers['Mcp-Name'] = $params['name']; }
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
 * Require successful structured data without dumping responses or credentials.
 *
 * @param string              $kind Key kind.
 * @param string              $name Tool suffix.
 * @param array<string,mixed> $args Arguments.
 * @return array<string,mixed>
 * @throws RuntimeException For a missing structured success.
 */
function xnetvn_agent_hub_wp_content_call( string $kind, string $name, array $args = array() ): array {
	$result = xnetvn_agent_hub_wp_content_send(
		$kind,
		'tools/call',
		array(
			'name'      => 'xnetvn-' . $name,
			'arguments' => (object) $args,
		)
	);
	$data   = $result['body']['result']['structuredContent'] ?? null;
	if ( 200 !== $result['status'] || ! is_array( $data ) || ( $result['body']['result']['isError'] ?? false ) ) {
		$reason = 'unavailable';
		$text   = $result['body']['result']['content'][0]['text'] ?? '';
		foreach ( array( 'conflict', 'invalid_params', 'forbidden', 'internal_error', 'invalid_output' ) as $candidate ) {
			if ( is_string( $text ) && str_contains( $text, $candidate ) ) {
				$reason = $candidate; }
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed allowlisted reason in a CLI-only fixture.
		throw new RuntimeException( 'Expected structured success for ' . htmlspecialchars( $name, ENT_QUOTES ) . '; safe reason: ' . $reason ); }
	return $data;
}
/**
 * Assert a semantic fact without retaining sensitive responses.
 *
 * @param bool   $ok Expected fact.
 * @param string $name Fixed case name.
 * @throws RuntimeException For a failed acceptance condition.
 */
function xnetvn_agent_hub_wp_content_check( bool $ok, string $name ): void {
	global $xnetvn_agent_hub_wp_cases;
	$xnetvn_agent_hub_wp_cases[ $name ] = $ok;
	if ( ! $ok ) {
		throw new RuntimeException( 'A content/media acceptance condition failed.' ); }
}
$xnetvn_agent_hub_wp_cases = array();
try {
	$xnetvn_agent_hub_wp_list  = xnetvn_agent_hub_wp_content_send( 'content_editor', 'tools/list' );
	$xnetvn_agent_hub_wp_tools = $xnetvn_agent_hub_wp_list['body']['result']['tools'] ?? array();
	xnetvn_agent_hub_wp_content_check( 31 === count( $xnetvn_agent_hub_wp_tools ), '31-real-tools' );
	foreach ( $xnetvn_agent_hub_wp_tools as $xnetvn_agent_hub_wp_tool ) {
		if ( str_starts_with( $xnetvn_agent_hub_wp_tool['name'], 'xnetvn-get-session' ) || in_array( $xnetvn_agent_hub_wp_tool['name'], array( 'xnetvn-get-site-info', 'xnetvn-discover-abilities', 'xnetvn-get-ability-info', 'xnetvn-execute-ability' ), true ) ) {
			continue; }
		xnetvn_agent_hub_wp_content_check( isset( $xnetvn_agent_hub_wp_tool['annotations']['openWorldHint'] ) && ( 'xnetvn-upload-media-from-url' === $xnetvn_agent_hub_wp_tool['name'] ) === $xnetvn_agent_hub_wp_tool['annotations']['openWorldHint'], 'annotations-' . $xnetvn_agent_hub_wp_tool['name'] );
	}
	$xnetvn_agent_hub_wp_post = xnetvn_agent_hub_wp_content_call(
		'content_editor',
		'create-post',
		array(
			'title'   => 'HTTP-MATRIX-CANARY content',
			'content' => '<p>Round trip</p>',
		)
	);
	xnetvn_agent_hub_wp_content_check( 'draft' === $xnetvn_agent_hub_wp_post['status'] && $xnetvn_agent_hub_wp_state['users']['owner'] === $xnetvn_agent_hub_wp_post['author'], 'create-owner-draft' );
	$xnetvn_agent_hub_wp_denied = xnetvn_agent_hub_wp_content_send(
		'content_author',
		'tools/call',
		array(
			'name'      => 'xnetvn-update-post',
			'arguments' => array(
				'id'    => $xnetvn_agent_hub_wp_post['id'],
				'title' => 'Denied',
			),
		)
	);
	xnetvn_agent_hub_wp_content_check( true === ( $xnetvn_agent_hub_wp_denied['body']['result']['isError'] ?? false ), 'cross-owner-denied' );
	$xnetvn_agent_hub_wp_denied = xnetvn_agent_hub_wp_content_send(
		'content_contributor',
		'tools/call',
		array(
			'name'      => 'xnetvn-create-post',
			'arguments' => array(
				'title'  => 'Denied',
				'status' => 'publish',
			),
		)
	);
	xnetvn_agent_hub_wp_content_check( true === ( $xnetvn_agent_hub_wp_denied['body']['result']['isError'] ?? false ), 'contributor-publish-denied' );
	$xnetvn_agent_hub_wp_read = xnetvn_agent_hub_wp_content_send( 'content_read', 'tools/list' );
	xnetvn_agent_hub_wp_content_check( ! in_array( 'xnetvn-create-post', array_column( $xnetvn_agent_hub_wp_read['body']['result']['tools'], 'name' ), true ), 'read-key-write-hidden' );
	$xnetvn_agent_hub_wp_updated = xnetvn_agent_hub_wp_content_call(
		'content_editor',
		'update-post',
		array(
			'id'                => $xnetvn_agent_hub_wp_post['id'],
			'title'             => 'Updated round trip',
			'expected_modified' => $xnetvn_agent_hub_wp_post['modified_gmt'],
		)
	);
	xnetvn_agent_hub_wp_content_check( 'Updated round trip' === $xnetvn_agent_hub_wp_updated['title'], 'update-conflict-token' );
	xnetvn_agent_hub_wp_content_call( 'write', 'fixture-bulk', array( 'items' => array( 1 ) ) );
	$xnetvn_agent_hub_wp_media = xnetvn_agent_hub_wp_content_call(
		'content_editor',
		'upload-media-from-data',
		array(
			'filename'    => 'HTTP-MATRIX-CANARY.png',
			'mime_type'   => 'image/png',
			'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=',
		)
	);
	xnetvn_agent_hub_wp_content_check( 'image/png' === $xnetvn_agent_hub_wp_media['mime_type'], 'raw-body-upload-no-DIR_TESTDATA' );
	xnetvn_agent_hub_wp_content_call(
		'content_editor',
		'set-featured-media',
		array(
			'id'       => $xnetvn_agent_hub_wp_post['id'],
			'media_id' => $xnetvn_agent_hub_wp_media['id'],
		)
	);
	xnetvn_agent_hub_wp_content_call(
		'content_editor',
		'update-media',
		array(
			'id'       => $xnetvn_agent_hub_wp_media['id'],
			'alt_text' => 'Small pixel',
		)
	);
	xnetvn_agent_hub_wp_content_call( 'content_editor', 'delete-media', array( 'id' => $xnetvn_agent_hub_wp_media['id'] ) );
	xnetvn_agent_hub_wp_content_call( 'content_editor', 'delete-post', array( 'id' => $xnetvn_agent_hub_wp_post['id'] ) );
	xnetvn_agent_hub_wp_content_check( true, 'media-and-post-delete' );
	$xnetvn_agent_hub_wp_samples = array();
	for ( $xnetvn_agent_hub_wp_i = 0; $xnetvn_agent_hub_wp_i < 20; ++$xnetvn_agent_hub_wp_i ) {
		$xnetvn_agent_hub_wp_start = hrtime( true );
		$xnetvn_agent_hub_wp_r     = xnetvn_agent_hub_wp_content_send( 'content_editor', 'tools/list' );
		xnetvn_agent_hub_wp_content_check( 31 === count( $xnetvn_agent_hub_wp_r['body']['result']['tools'] ?? array() ), 'list-sample-' . $xnetvn_agent_hub_wp_i );
		$xnetvn_agent_hub_wp_samples[] = ( hrtime( true ) - $xnetvn_agent_hub_wp_start ) / 1000000; }
	sort( $xnetvn_agent_hub_wp_samples );
	$xnetvn_agent_hub_wp_report = array(
		'passed'            => count( $xnetvn_agent_hub_wp_cases ),
		'cases'             => $xnetvn_agent_hub_wp_cases,
		'list_count'        => 31,
		'fixture'           => 'wp71' === getenv( 'XNETVN_MATRIX_SITE' ) ? 'WordPress7.1.2' : 'WordPress6.9',
		'list_bytes'        => strlen( json_encode( $xnetvn_agent_hub_wp_tools, JSON_THROW_ON_ERROR ) ),
		'https_list_p50_ms' => $xnetvn_agent_hub_wp_samples[9],
		'https_list_p95_ms' => $xnetvn_agent_hub_wp_samples[18],
	);
	file_put_contents( '/work/.local/http-matrix/content-media-results.json', json_encode( $xnetvn_agent_hub_wp_report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
	echo json_encode( $xnetvn_agent_hub_wp_report, JSON_THROW_ON_ERROR ) . "\n";
} catch ( Throwable $xnetvn_agent_hub_wp_error ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only fixture diagnostics are not HTML and contain no request values.
	echo htmlspecialchars( $xnetvn_agent_hub_wp_error->getMessage(), ENT_QUOTES ) . "\n";
	exit( 1 ); }
