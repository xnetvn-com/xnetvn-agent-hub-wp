<?php
/**
 * Real HTTPS comment round trips with role-scoped keys.
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
function xnetvn_agent_hub_wp_comments_send( string $kind, string $method, array $params = array() ): array {
	global $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_state;
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'comments-acceptance',
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
function xnetvn_agent_hub_wp_comments_call( string $kind, string $name, array $args = array() ): array {
	$result = xnetvn_agent_hub_wp_comments_send(
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
		foreach ( array( 'comments_closed', 'duplicate_comment', 'forbidden', 'invalid_params', 'invalid_state', 'not_found', 'unsupported_operation', 'internal_error', 'invalid_output' ) as $candidate ) {
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
function xnetvn_agent_hub_wp_comments_check( bool $ok, string $name ): void {
	global $xnetvn_agent_hub_wp_cases;
	$xnetvn_agent_hub_wp_cases[ $name ] = $ok;
	if ( ! $ok ) {
		throw new RuntimeException( 'A comments acceptance condition failed.' ); }
}
$xnetvn_agent_hub_wp_cases    = array();
$xnetvn_agent_hub_wp_expected = array( 'xnetvn-list-comments', 'xnetvn-get-comment', 'xnetvn-get-comment-counts', 'xnetvn-create-comment', 'xnetvn-reply-comment', 'xnetvn-update-comment', 'xnetvn-moderate-comment', 'xnetvn-bulk-moderate-comments', 'xnetvn-delete-comment', 'xnetvn-purge-comments' );
try {
	$xnetvn_agent_hub_wp_list  = xnetvn_agent_hub_wp_comments_send( 'comments_editor', 'tools/list' );
	$xnetvn_agent_hub_wp_tools = $xnetvn_agent_hub_wp_list['body']['result']['tools'] ?? array();
	$xnetvn_agent_hub_wp_names = array_column( $xnetvn_agent_hub_wp_tools, 'name' );
	foreach ( $xnetvn_agent_hub_wp_expected as $xnetvn_agent_hub_wp_tool ) {
		xnetvn_agent_hub_wp_comments_check( in_array( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_names, true ), 'visible-' . $xnetvn_agent_hub_wp_tool ); }
	$xnetvn_agent_hub_wp_author_list  = xnetvn_agent_hub_wp_comments_send( 'comments_author', 'tools/list' );
	$xnetvn_agent_hub_wp_author_names = array_column( $xnetvn_agent_hub_wp_author_list['body']['result']['tools'] ?? array(), 'name' );
	foreach ( $xnetvn_agent_hub_wp_expected as $xnetvn_agent_hub_wp_tool ) {
		xnetvn_agent_hub_wp_comments_check( ! in_array( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_author_names, true ), 'author-hidden-' . $xnetvn_agent_hub_wp_tool ); }
	$xnetvn_agent_hub_wp_post    = xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'create-post',
		array(
			'title'   => 'HTTP-MATRIX-CANARY comments',
			'content' => '<p>Round trip</p>',
			'status'  => 'publish',
		)
	);
	$xnetvn_agent_hub_wp_comment = xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'create-comment',
		array(
			'post'    => $xnetvn_agent_hub_wp_post['id'],
			'content' => 'Please review me',
		)
	);
	xnetvn_agent_hub_wp_comments_check( 'hold' === $xnetvn_agent_hub_wp_comment['status'], 'create-default-hold' );
	$xnetvn_agent_hub_wp_counts = xnetvn_agent_hub_wp_comments_call( 'comments_editor', 'get-comment-counts', array( 'post' => $xnetvn_agent_hub_wp_post['id'] ) );
	xnetvn_agent_hub_wp_comments_check( 1 === $xnetvn_agent_hub_wp_counts['hold'], 'counts-hold' );
	$xnetvn_agent_hub_wp_approved = xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'moderate-comment',
		array(
			'id'     => $xnetvn_agent_hub_wp_comment['id'],
			'action' => 'approve',
		)
	);
	xnetvn_agent_hub_wp_comments_check( 'approved' === $xnetvn_agent_hub_wp_approved['status'] && true === $xnetvn_agent_hub_wp_approved['changed'], 'approve-transition' );
	$xnetvn_agent_hub_wp_denied = xnetvn_agent_hub_wp_comments_send(
		'comments_author',
		'tools/call',
		array(
			'name'      => 'xnetvn-moderate-comment',
			'arguments' => array(
				'id'     => $xnetvn_agent_hub_wp_comment['id'],
				'action' => 'spam',
			),
		)
	);
	xnetvn_agent_hub_wp_comments_check( true === ( $xnetvn_agent_hub_wp_denied['body']['result']['isError'] ?? false ), 'author-moderate-denied' );
	xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'moderate-comment',
		array(
			'id'     => $xnetvn_agent_hub_wp_comment['id'],
			'action' => 'trash',
		)
	);
	xnetvn_agent_hub_wp_comments_call( 'comments_editor', 'delete-comment', array( 'id' => $xnetvn_agent_hub_wp_comment['id'] ) );
	$xnetvn_agent_hub_wp_closed_post = xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'create-post',
		array(
			'title'   => 'HTTP-MATRIX-CANARY closed',
			'content' => '<p>Closed</p>',
			'status'  => 'publish',
		)
	);
	xnetvn_agent_hub_wp_comments_call(
		'comments_editor',
		'update-post',
		array(
			'id'             => $xnetvn_agent_hub_wp_closed_post['id'],
			'comment_status' => 'closed',
		)
	);
	$xnetvn_agent_hub_wp_closed      = xnetvn_agent_hub_wp_comments_send(
		'comments_editor',
		'tools/call',
		array(
			'name'      => 'xnetvn-create-comment',
			'arguments' => array(
				'post'    => $xnetvn_agent_hub_wp_closed_post['id'],
				'content' => 'Knock knock',
			),
		)
	);
	$xnetvn_agent_hub_wp_closed_text = $xnetvn_agent_hub_wp_closed['body']['result']['content'][0]['text'] ?? '';
	xnetvn_agent_hub_wp_comments_check( true === ( $xnetvn_agent_hub_wp_closed['body']['result']['isError'] ?? false ) && str_contains( (string) $xnetvn_agent_hub_wp_closed_text, 'comments_closed' ), 'closed-post-refused' );
	xnetvn_agent_hub_wp_comments_call( 'comments_editor', 'delete-post', array( 'id' => $xnetvn_agent_hub_wp_post['id'] ) );
	xnetvn_agent_hub_wp_comments_call( 'comments_editor', 'delete-post', array( 'id' => $xnetvn_agent_hub_wp_closed_post['id'] ) );
	xnetvn_agent_hub_wp_comments_check( true, 'comments-lifecycle' );
	$xnetvn_agent_hub_wp_report = array(
		'passed'  => count( $xnetvn_agent_hub_wp_cases ),
		'cases'   => $xnetvn_agent_hub_wp_cases,
		'fixture' => 'wp71' === getenv( 'XNETVN_MATRIX_SITE' ) ? 'WordPress7.1.2' : 'WordPress6.9',
	);
	file_put_contents( '/work/.local/http-matrix/comments-results.json', json_encode( $xnetvn_agent_hub_wp_report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
} catch ( Throwable $xnetvn_agent_hub_wp_failure ) {
	file_put_contents(
		'/work/.local/http-matrix/comments-results.json',
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
