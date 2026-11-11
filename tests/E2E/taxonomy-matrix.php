<?php
/**
 * Real HTTPS taxonomy round trips with role-scoped keys.
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
function xnetvn_agent_hub_wp_taxonomy_send( string $kind, string $method, array $params = array() ): array {
	global $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_state;
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'taxonomy-acceptance',
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
function xnetvn_agent_hub_wp_taxonomy_call( string $kind, string $name, array $args = array() ): array {
	$result = xnetvn_agent_hub_wp_taxonomy_send(
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
		foreach ( array( 'taxonomy_unavailable', 'duplicate_term', 'forbidden', 'invalid_params', 'invalid_state', 'not_found', 'unsupported_operation', 'internal_error', 'invalid_output' ) as $candidate ) {
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
function xnetvn_agent_hub_wp_taxonomy_check( bool $ok, string $name ): void {
	global $xnetvn_agent_hub_wp_cases;
	$xnetvn_agent_hub_wp_cases[ $name ] = $ok;
	if ( ! $ok ) {
		throw new RuntimeException( 'A taxonomy acceptance condition failed.' ); }
}
/**
 * Require a safe tool error without dumping responses or credentials.
 *
 * @param string              $kind Key kind.
 * @param string              $name Tool suffix.
 * @param array<string,mixed> $args Arguments.
 * @param string              $reason Expected safe reason.
 */
function xnetvn_agent_hub_wp_taxonomy_denied( string $kind, string $name, array $args, string $reason ): void {
	$denied = xnetvn_agent_hub_wp_taxonomy_send(
		$kind,
		'tools/call',
		array(
			'name'      => 'xnetvn-' . $name,
			'arguments' => $args,
		)
	);
	$text   = $denied['body']['result']['content'][0]['text'] ?? '';
	xnetvn_agent_hub_wp_taxonomy_check( true === ( $denied['body']['result']['isError'] ?? false ) && str_contains( (string) $text, $reason ), $name . '-refused-' . $reason );
}
$xnetvn_agent_hub_wp_cases    = array();
$xnetvn_agent_hub_wp_expected = array( 'xnetvn-list-taxonomies', 'xnetvn-list-terms', 'xnetvn-get-term', 'xnetvn-create-term', 'xnetvn-update-term', 'xnetvn-assign-post-terms', 'xnetvn-bulk-create-terms', 'xnetvn-bulk-assign-post-terms', 'xnetvn-delete-term' );
try {
	$xnetvn_agent_hub_wp_nonce = 'e2e' . substr( (string) time(), -6 );
	$xnetvn_agent_hub_wp_list  = xnetvn_agent_hub_wp_taxonomy_send( 'taxonomy_editor', 'tools/list' );
	$xnetvn_agent_hub_wp_tools = $xnetvn_agent_hub_wp_list['body']['result']['tools'] ?? array();
	$xnetvn_agent_hub_wp_names = array_column( $xnetvn_agent_hub_wp_tools, 'name' );
	foreach ( $xnetvn_agent_hub_wp_expected as $xnetvn_agent_hub_wp_tool ) {
		xnetvn_agent_hub_wp_taxonomy_check( in_array( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_names, true ), 'visible-' . $xnetvn_agent_hub_wp_tool ); }
	$xnetvn_agent_hub_wp_taxonomies = xnetvn_agent_hub_wp_taxonomy_call( 'taxonomy_editor', 'list-taxonomies', array() );
	$xnetvn_agent_hub_wp_slugs      = array_column( $xnetvn_agent_hub_wp_taxonomies['items'], 'slug' );
	xnetvn_agent_hub_wp_taxonomy_check( in_array( 'category', $xnetvn_agent_hub_wp_slugs, true ) && in_array( 'post_tag', $xnetvn_agent_hub_wp_slugs, true ), 'survey-managed' );
	xnetvn_agent_hub_wp_taxonomy_check( ! in_array( 'nav_menu', $xnetvn_agent_hub_wp_slugs, true ), 'survey-hides-internal' );
	$xnetvn_agent_hub_wp_parent = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'create-term',
		array(
			'taxonomy' => 'category',
			'name'     => 'E2E Parent ' . $xnetvn_agent_hub_wp_nonce,
		)
	);
	$xnetvn_agent_hub_wp_child  = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'create-term',
		array(
			'taxonomy' => 'category',
			'name'     => 'E2E Child ' . $xnetvn_agent_hub_wp_nonce,
			'parent'   => $xnetvn_agent_hub_wp_parent['id'],
		)
	);
	xnetvn_agent_hub_wp_taxonomy_check( $xnetvn_agent_hub_wp_parent['id'] === $xnetvn_agent_hub_wp_child['parent'], 'child-parent-link' );
	$xnetvn_agent_hub_wp_bulk = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'bulk-create-terms',
		array(
			'taxonomy' => 'post_tag',
			'items'    => array(
				array( 'name' => 'E2E Tag A ' . $xnetvn_agent_hub_wp_nonce ),
				array( 'name' => 'E2E Tag B ' . $xnetvn_agent_hub_wp_nonce ),
			),
		)
	);
	xnetvn_agent_hub_wp_taxonomy_check( 2 === count( $xnetvn_agent_hub_wp_bulk['created'] ), 'bulk-created' );
	$xnetvn_agent_hub_wp_tag_a    = $xnetvn_agent_hub_wp_bulk['created'][0]['id'];
	$xnetvn_agent_hub_wp_tag_b    = $xnetvn_agent_hub_wp_bulk['created'][1]['id'];
	$xnetvn_agent_hub_wp_post     = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'create-post',
		array(
			'title'   => 'HTTP-MATRIX-CANARY taxonomy',
			'content' => '<p>Round trip</p>',
			'status'  => 'publish',
		)
	);
	$xnetvn_agent_hub_wp_assigned = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'assign-post-terms',
		array(
			'post_id'  => $xnetvn_agent_hub_wp_post['id'],
			'taxonomy' => 'post_tag',
			'term_ids' => array( $xnetvn_agent_hub_wp_tag_a, $xnetvn_agent_hub_wp_tag_b ),
			'mode'     => 'append',
		)
	);
	xnetvn_agent_hub_wp_taxonomy_check( 2 === count( $xnetvn_agent_hub_wp_assigned['added'] ), 'assign-appended' );
	$xnetvn_agent_hub_wp_mine = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_contributor',
		'create-term',
		array(
			'taxonomy' => 'post_tag',
			'name'     => 'E2E Contributor Tag ' . $xnetvn_agent_hub_wp_nonce,
		)
	);
	xnetvn_agent_hub_wp_taxonomy_check( $xnetvn_agent_hub_wp_mine['id'] > 0, 'contributor-tag-allowed' );
	xnetvn_agent_hub_wp_taxonomy_denied(
		'taxonomy_contributor',
		'create-term',
		array(
			'taxonomy' => 'category',
			'name'     => 'E2E Contributor Cat ' . $xnetvn_agent_hub_wp_nonce,
		),
		'forbidden'
	);
	$xnetvn_agent_hub_wp_dry = xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'post_tag',
			'id'       => $xnetvn_agent_hub_wp_tag_b,
			'dry_run'  => true,
		)
	);
	xnetvn_agent_hub_wp_taxonomy_check( false === $xnetvn_agent_hub_wp_dry['deleted'] && true === $xnetvn_agent_hub_wp_dry['dry_run'], 'dry-run-preview' );
	xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'post_tag',
			'id'       => $xnetvn_agent_hub_wp_tag_b,
		)
	);
	$xnetvn_agent_hub_wp_contributor_delete = xnetvn_agent_hub_wp_taxonomy_send(
		'taxonomy_contributor',
		'tools/call',
		array(
			'name'      => 'xnetvn-delete-term',
			'arguments' => array(
				'taxonomy' => 'post_tag',
				'id'       => $xnetvn_agent_hub_wp_tag_a,
			),
		)
	);
	// A write-tier key never reaches the destructive tool; the gateway answers with the generic refusal.
	xnetvn_agent_hub_wp_taxonomy_check( true === ( $xnetvn_agent_hub_wp_contributor_delete['body']['result']['isError'] ?? false ), 'delete-term-refused-forbidden' );
	xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'post_tag',
			'id'       => $xnetvn_agent_hub_wp_tag_a,
		)
	);
	xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'post_tag',
			'id'       => $xnetvn_agent_hub_wp_mine['id'],
		)
	);
	xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'category',
			'id'       => $xnetvn_agent_hub_wp_child['id'],
		)
	);
	xnetvn_agent_hub_wp_taxonomy_call(
		'taxonomy_editor',
		'delete-term',
		array(
			'taxonomy' => 'category',
			'id'       => $xnetvn_agent_hub_wp_parent['id'],
		)
	);
	xnetvn_agent_hub_wp_taxonomy_call( 'taxonomy_editor', 'delete-post', array( 'id' => $xnetvn_agent_hub_wp_post['id'] ) );
	xnetvn_agent_hub_wp_taxonomy_check( true, 'taxonomy-lifecycle' );
	$xnetvn_agent_hub_wp_report = array(
		'passed'  => count( $xnetvn_agent_hub_wp_cases ),
		'cases'   => $xnetvn_agent_hub_wp_cases,
		'fixture' => 'wp71' === getenv( 'XNETVN_MATRIX_SITE' ) ? 'WordPress7.1.2' : 'WordPress6.9',
	);
	file_put_contents( '/work/.local/http-matrix/taxonomy-results.json', json_encode( $xnetvn_agent_hub_wp_report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
} catch ( Throwable $xnetvn_agent_hub_wp_failure ) {
	file_put_contents(
		'/work/.local/http-matrix/taxonomy-results.json',
		json_encode(
			array(
				'passed' => count( $xnetvn_agent_hub_wp_cases ),
				'cases'  => $xnetvn_agent_hub_wp_cases,
				'failed' => substr( $xnetvn_agent_hub_wp_failure->getMessage(), 0, 200 ),
			),
			JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
		)
	);
	throw $xnetvn_agent_hub_wp_failure;
}
