<?php
/**
 * Owned role/SEO network matrix: NET-01 through NET-07 and the per-blog
 * replay of the B–E invariants through real HTTPS on the multisite lab.
 *
 * Modes: `preflight` runs transport checks without any fixture; `matrix`
 * runs the full NET suite against the owned seed. Every case is recorded
 * with safe values only: case ids, allowlisted reasons, counts, digests
 * and boolean checks. No input, response bodies, cookies, bearers, hashes
 * or argument values are printed. Posts and media created by this matrix
 * are deleted in teardown before the report; teardown deletes only
 * matrix-tracked ids plus the fixture ledger owned ids, never by query.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\E2E\McpClient;
use XNetVN\AgentHubWP\Tests\E2E\RoleSeoHarness;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client; no WordPress bootstrap or logged credentials.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed owned toolbox fixture actions with no credential arguments.

$xnetvn_agent_hub_wp_net_mode = $argv[1] ?? '';
if ( ! in_array( $xnetvn_agent_hub_wp_net_mode, array( 'preflight', 'matrix' ), true ) ) {
	exit( 2 );
}

/**
 * Declare the network cases with their owner and applicability.
 *
 * Literal AUTH-/SEO-/IMG-/LIFE-* replay needs the four B–E matrix files to
 * accept network targets; those files are outside this ticket, so NET-02
 * replays the same invariants per blog under NET-02-* case ids.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_net_declare_cases(): array {
	return array(
		array(
			'id'           => 'NET-01',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-02',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-03',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-04',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-05',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-06',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'NET-07',
			'owner'        => 'matrix',
			'targets'      => array( 'network-main', 'network-child' ),
			'expects_exit' => 0,
		),
	);
}

$xnetvn_agent_hub_wp_net_started = hrtime( true );
$xnetvn_agent_hub_wp_net_timings = array();
$xnetvn_agent_hub_wp_net_run_id  = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
$xnetvn_agent_hub_wp_net_domain  = getenv( 'RTL_DOMAIN_WPNETWORK' );
$xnetvn_agent_hub_wp_net_alias   = getenv( 'RTL_ALIAS_WPNETWORK' );
if ( ! is_string( $xnetvn_agent_hub_wp_net_run_id ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $xnetvn_agent_hub_wp_net_run_id ) ) {
	exit( 2 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_net_domain ) || '' === $xnetvn_agent_hub_wp_net_domain || ! is_string( $xnetvn_agent_hub_wp_net_alias ) || '' === $xnetvn_agent_hub_wp_net_alias ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_net_hosts      = array( $xnetvn_agent_hub_wp_net_domain, $xnetvn_agent_hub_wp_net_alias );
$xnetvn_agent_hub_wp_net_bases      = array(
	'main'  => 'https://' . $xnetvn_agent_hub_wp_net_domain,
	'child' => 'https://' . $xnetvn_agent_hub_wp_net_domain . '/ui-second/',
);
$xnetvn_agent_hub_wp_net_state_file = '/work/.local/role-seo-network/private/' . $xnetvn_agent_hub_wp_net_run_id . '/state.json';

/**
 * Time one matrix-owned check.
 *
 * @param array<string,float> $xnetvn_agent_hub_wp_net_timings Timing accumulator.
 * @param string              $xnetvn_agent_hub_wp_net_case_id Case id.
 * @param callable            $xnetvn_agent_hub_wp_net_check Check body.
 * @return bool
 */
$xnetvn_agent_hub_wp_net_time = static function ( array &$xnetvn_agent_hub_wp_net_timings, string $xnetvn_agent_hub_wp_net_case_id, callable $xnetvn_agent_hub_wp_net_check ): bool {
	$xnetvn_agent_hub_wp_net_start = hrtime( true );
	try {
		$xnetvn_agent_hub_wp_net_ok = $xnetvn_agent_hub_wp_net_check();
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_net_ok = false;
	}
	$xnetvn_agent_hub_wp_net_timings[ $xnetvn_agent_hub_wp_net_case_id ] = ( hrtime( true ) - $xnetvn_agent_hub_wp_net_start ) / 1000000;
	return $xnetvn_agent_hub_wp_net_ok;
};

/**
 * Send one JSON-RPC envelope with an explicit bearer (or none).
 *
 * Mirrors the harness envelope and protocol headers without recording.
 *
 * @param McpClient           $xnetvn_agent_hub_wp_net_client HTTP transport.
 * @param int                 $xnetvn_agent_hub_wp_net_id Next envelope id.
 * @param string|null         $xnetvn_agent_hub_wp_net_token Bearer token, null for no credential.
 * @param string              $xnetvn_agent_hub_wp_net_method MCP method.
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_params Method params.
 * @param array<string,string> $xnetvn_agent_hub_wp_net_headers Extra protocol headers.
 * @return array<string,mixed>
 */
$xnetvn_agent_hub_wp_net_send = static function ( McpClient $xnetvn_agent_hub_wp_net_client, int &$xnetvn_agent_hub_wp_net_id, ?string $xnetvn_agent_hub_wp_net_token, string $xnetvn_agent_hub_wp_net_method, array $xnetvn_agent_hub_wp_net_params = array(), array $xnetvn_agent_hub_wp_net_headers = array() ): array {
	$xnetvn_agent_hub_wp_net_params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'role-seo-acceptance',
			'version' => '1',
		),
	);
	$xnetvn_agent_hub_wp_net_request_headers = array(
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => $xnetvn_agent_hub_wp_net_method,
	);
	if ( isset( $xnetvn_agent_hub_wp_net_params['name'] ) && is_string( $xnetvn_agent_hub_wp_net_params['name'] ) ) {
		$xnetvn_agent_hub_wp_net_request_headers['Mcp-Name'] = $xnetvn_agent_hub_wp_net_params['name'];
	}
	foreach ( $xnetvn_agent_hub_wp_net_headers as $xnetvn_agent_hub_wp_net_name => $xnetvn_agent_hub_wp_net_value ) {
		$xnetvn_agent_hub_wp_net_request_headers[ $xnetvn_agent_hub_wp_net_name ] = $xnetvn_agent_hub_wp_net_value;
	}
	return $xnetvn_agent_hub_wp_net_client->request(
		'/wp-json/xnetvn-agent-hub-wp/mcp',
		json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $xnetvn_agent_hub_wp_net_id++,
				'method'  => $xnetvn_agent_hub_wp_net_method,
				'params'  => $xnetvn_agent_hub_wp_net_params,
			),
			JSON_THROW_ON_ERROR
		),
		$xnetvn_agent_hub_wp_net_token,
		$xnetvn_agent_hub_wp_net_request_headers
	);
};

/**
 * Require the hidden-tool outcome: HTTP 200, isError and the fixed message.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_response Decoded MCP response.
 * @return bool
 */
$xnetvn_agent_hub_wp_net_hidden = static function ( array $xnetvn_agent_hub_wp_net_response ): bool {
	if ( 200 !== ( $xnetvn_agent_hub_wp_net_response['status'] ?? 0 ) ) {
		return false;
	}
	$xnetvn_agent_hub_wp_net_result = $xnetvn_agent_hub_wp_net_response['body']['result'] ?? null;
	if ( ! is_array( $xnetvn_agent_hub_wp_net_result ) || true !== ( $xnetvn_agent_hub_wp_net_result['isError'] ?? false ) ) {
		return false;
	}
	return 'The tool is unavailable.' === ( $xnetvn_agent_hub_wp_net_result['content'][0]['text'] ?? null );
};

/**
 * Require a tool denial without structured content.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_response Decoded MCP response.
 * @return bool
 */
$xnetvn_agent_hub_wp_net_denied = static function ( array $xnetvn_agent_hub_wp_net_response ): bool {
	if ( 200 !== ( $xnetvn_agent_hub_wp_net_response['status'] ?? 0 ) ) {
		return false;
	}
	$xnetvn_agent_hub_wp_net_result = $xnetvn_agent_hub_wp_net_response['body']['result'] ?? null;
	return is_array( $xnetvn_agent_hub_wp_net_result ) && true === ( $xnetvn_agent_hub_wp_net_result['isError'] ?? false ) && ! isset( $xnetvn_agent_hub_wp_net_result['structuredContent'] );
};

/**
 * Require a successful structured result.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_response Decoded MCP response.
 * @return array<string,mixed>|null Structured content or null.
 */
$xnetvn_agent_hub_wp_net_check_ok = static function ( array $xnetvn_agent_hub_wp_net_response ): ?array {
	if ( 200 !== ( $xnetvn_agent_hub_wp_net_response['status'] ?? 0 ) ) {
		return null;
	}
	$xnetvn_agent_hub_wp_net_result = $xnetvn_agent_hub_wp_net_response['body']['result'] ?? null;
	if ( ! is_array( $xnetvn_agent_hub_wp_net_result ) || ( $xnetvn_agent_hub_wp_net_result['isError'] ?? false ) || ! is_array( $xnetvn_agent_hub_wp_net_result['structuredContent'] ?? null ) ) {
		return null;
	}
	return $xnetvn_agent_hub_wp_net_result['structuredContent'];
};

if ( 'preflight' === $xnetvn_agent_hub_wp_net_mode ) {
	$xnetvn_agent_hub_wp_net_cases  = array();
	$xnetvn_agent_hub_wp_net_failed = false;
	foreach ( $xnetvn_agent_hub_wp_net_bases as $xnetvn_agent_hub_wp_net_key => $xnetvn_agent_hub_wp_net_base ) {
		$xnetvn_agent_hub_wp_net_client                                 = new McpClient( $xnetvn_agent_hub_wp_net_base, $xnetvn_agent_hub_wp_net_hosts, true );
		$xnetvn_agent_hub_wp_net_id                                     = 1;
		$xnetvn_agent_hub_wp_net_case                                   = 'NET-01-transport-' . $xnetvn_agent_hub_wp_net_key;
		$xnetvn_agent_hub_wp_net_ok                                     = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_client, $xnetvn_agent_hub_wp_net_send, &$xnetvn_agent_hub_wp_net_id ): bool {
				$xnetvn_agent_hub_wp_net_response = $xnetvn_agent_hub_wp_net_send( $xnetvn_agent_hub_wp_net_client, $xnetvn_agent_hub_wp_net_id, null, 'initialize', array() );
				$xnetvn_agent_hub_wp_net_status   = (int) ( $xnetvn_agent_hub_wp_net_response['status'] ?? 0 );
				if ( 401 !== $xnetvn_agent_hub_wp_net_status ) {
					return false;
				}
				if ( isset( $xnetvn_agent_hub_wp_net_response['headers']['location'] ) ) {
					return false;
				}
				return true;
			}
		);
		$xnetvn_agent_hub_wp_net_cases[ $xnetvn_agent_hub_wp_net_case ] = array(
			'ok'     => $xnetvn_agent_hub_wp_net_ok,
			'reason' => $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error',
		);
		$xnetvn_agent_hub_wp_net_failed                                 = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
	echo json_encode(
		array(
			'suite'     => 'role-seo-network-preflight',
			'target'    => 'network',
			'run_id'    => $xnetvn_agent_hub_wp_net_run_id,
			'cases'     => $xnetvn_agent_hub_wp_net_cases,
			'declared'  => xnetvn_agent_hub_wp_net_declare_cases(),
			'timing_ms' => $xnetvn_agent_hub_wp_net_timings,
			'cleanup'   => false,
		),
		JSON_THROW_ON_ERROR
	) . "\n";
	exit( $xnetvn_agent_hub_wp_net_failed ? 1 : 0 );
}

try {
	$xnetvn_agent_hub_wp_net_raw_state = file_get_contents( $xnetvn_agent_hub_wp_net_state_file );
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO network fixture state unavailable; seed before running the matrix.\n";
	exit( 1 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_net_raw_state ) || is_link( $xnetvn_agent_hub_wp_net_state_file ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO network fixture state unavailable; seed before running the matrix.\n";
	exit( 1 );
}
try {
	$xnetvn_agent_hub_wp_net_net_state = json_decode( $xnetvn_agent_hub_wp_net_raw_state, true, 64, JSON_THROW_ON_ERROR );
} catch ( \JsonException ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO network fixture state malformed.\n";
	exit( 1 );
}
if ( ! is_array( $xnetvn_agent_hub_wp_net_net_state ) || 'role-seo-network' !== ( $xnetvn_agent_hub_wp_net_net_state['suite'] ?? '' ) || ( $xnetvn_agent_hub_wp_net_net_state['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_net_run_id ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO network fixture marker mismatch; reconcile before running the matrix.\n";
	exit( 2 );
}
$xnetvn_agent_hub_wp_net_net_blogs = $xnetvn_agent_hub_wp_net_net_state['blogs'] ?? array();
if ( ! isset( $xnetvn_agent_hub_wp_net_net_blogs['main'], $xnetvn_agent_hub_wp_net_net_blogs['child'] ) || ! is_int( $xnetvn_agent_hub_wp_net_net_blogs['main'] ) || ! is_int( $xnetvn_agent_hub_wp_net_net_blogs['child'] ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO network fixture blogs unavailable.\n";
	exit( 2 );
}

/**
 * Run one fixed owned network fixture action and decode its safe report.
 *
 * @param string $xnetvn_agent_hub_wp_net_blog_key main or child.
 * @param string ...$argv Fixed action arguments.
 * @return array<string,mixed>
 * @throws RuntimeException For a failed fixture control.
 */
$xnetvn_agent_hub_wp_net_action = static function ( string $xnetvn_agent_hub_wp_net_blog_key, string ...$argv ) use ( $xnetvn_agent_hub_wp_net_bases, $xnetvn_agent_hub_wp_net_run_id ): array {
	$xnetvn_agent_hub_wp_net_allowed_actions = array( 'verify', 'snapshot', 'counts', 'content-digest', 'role-blog', 'ban-verify', 'ban-reset', 'collision-pick', 'collision-make', 'quota-set', 'quota-restore', 'settings-variant', 'net-read', 'super', 'blogname-set' );
	$xnetvn_agent_hub_wp_net_allowed_subs    = array( 'deny', 'corrupt', 'admin-off', 'admin-on', 'reset', 'grant', 'revoke', 'editor', 'author', 'contributor', 'subscriber', 'banned', 'administrator', 'author_b', 'admin', 'super' );
	foreach ( $argv as $xnetvn_agent_hub_wp_net_index => $xnetvn_agent_hub_wp_net_part ) {
		if ( 0 === $xnetvn_agent_hub_wp_net_index && ! in_array( $xnetvn_agent_hub_wp_net_part, $xnetvn_agent_hub_wp_net_allowed_actions, true ) ) {
			throw new RuntimeException( 'Invalid fixture action.' );
		}
		if ( 0 < $xnetvn_agent_hub_wp_net_index && ! in_array( $xnetvn_agent_hub_wp_net_part, $xnetvn_agent_hub_wp_net_allowed_subs, true ) && 1 !== preg_match( '/\A([0-9]{1,3}|[1-9][0-9]{0,8}|[a-z0-9-]{1,16})\z/', $xnetvn_agent_hub_wp_net_part ) ) {
			throw new RuntimeException( 'Invalid fixture action.' );
		}
	}
	$xnetvn_agent_hub_wp_net_command = 'WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path=/sites/wpnetwork --url=' . escapeshellarg( $xnetvn_agent_hub_wp_net_bases[ $xnetvn_agent_hub_wp_net_blog_key ] ) . ' eval-file /work/tests/E2E/role-seo-network-fixture.php';
	foreach ( $argv as $xnetvn_agent_hub_wp_net_part ) {
		$xnetvn_agent_hub_wp_net_command .= ' ' . escapeshellarg( $xnetvn_agent_hub_wp_net_part );
	}
	$xnetvn_agent_hub_wp_net_command .= ' --use-include';
	exec( $xnetvn_agent_hub_wp_net_command, $xnetvn_agent_hub_wp_net_output, $xnetvn_agent_hub_wp_net_status );
	if ( 0 !== $xnetvn_agent_hub_wp_net_status ) {
		throw new RuntimeException( 'Fixture action failed.' );
	}
	$xnetvn_agent_hub_wp_net_decoded = json_decode( implode( "\n", $xnetvn_agent_hub_wp_net_output ), true, 32 );
	if ( ! is_array( $xnetvn_agent_hub_wp_net_decoded ) ) {
		throw new RuntimeException( 'Fixture action unreadable.' );
	}
	return $xnetvn_agent_hub_wp_net_decoded;
};

/**
 * Record one matrix-owned case without raw values.
 *
 * @param RoleSeoHarness $xnetvn_agent_hub_wp_net_harness Harness.
 * @param string         $xnetvn_agent_hub_wp_net_case_id Case id.
 * @param bool           $xnetvn_agent_hub_wp_net_ok Outcome.
 * @param string         $xnetvn_agent_hub_wp_net_reason Allowlisted reason.
 */
$xnetvn_agent_hub_wp_net_record = static function ( RoleSeoHarness $xnetvn_agent_hub_wp_net_harness, string $xnetvn_agent_hub_wp_net_case_id, bool $xnetvn_agent_hub_wp_net_ok, string $xnetvn_agent_hub_wp_net_reason = 'unavailable' ): void {
	$xnetvn_agent_hub_wp_net_harness->recordCase( $xnetvn_agent_hub_wp_net_case_id, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_reason );
};

$xnetvn_agent_hub_wp_net_clients  = array();
$xnetvn_agent_hub_wp_net_harness  = array();
$xnetvn_agent_hub_wp_net_envelope = array();
foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
	$xnetvn_agent_hub_wp_net_base_path = 'child' === $xnetvn_agent_hub_wp_net_blog_key ? '/ui-second' : '';
	$xnetvn_agent_hub_wp_net_client    = new McpClient( $xnetvn_agent_hub_wp_net_bases[ $xnetvn_agent_hub_wp_net_blog_key ], $xnetvn_agent_hub_wp_net_hosts, true );
	// The harness joins base_path onto its transport base URL, so the
	// harness transport must use the site root; the full per-blog URL stays
	// on the raw client used for direct envelope sends. Passing the child
	// URL here would double the subdirectory and 404 every child call.
	$xnetvn_agent_hub_wp_net_harness_client                                = 'child' === $xnetvn_agent_hub_wp_net_blog_key ? new McpClient( $xnetvn_agent_hub_wp_net_bases['main'], $xnetvn_agent_hub_wp_net_hosts, true ) : $xnetvn_agent_hub_wp_net_client;
	$xnetvn_agent_hub_wp_net_harness_obj                                   = new RoleSeoHarness(
		array(
			'target'        => 'network-' . $xnetvn_agent_hub_wp_net_blog_key,
			'blog'          => (int) $xnetvn_agent_hub_wp_net_net_blogs[ $xnetvn_agent_hub_wp_net_blog_key ],
			'base_url'      => 'child' === $xnetvn_agent_hub_wp_net_blog_key ? $xnetvn_agent_hub_wp_net_bases['main'] : $xnetvn_agent_hub_wp_net_bases[ $xnetvn_agent_hub_wp_net_blog_key ],
			'base_path'     => $xnetvn_agent_hub_wp_net_base_path,
			'run_id'        => $xnetvn_agent_hub_wp_net_run_id,
			'private_state' => $xnetvn_agent_hub_wp_net_state_file,
		),
		$xnetvn_agent_hub_wp_net_harness_client,
		array(
			'users'  => $xnetvn_agent_hub_wp_net_net_state['users'],
			'tokens' => $xnetvn_agent_hub_wp_net_net_state['tokens'][ (int) $xnetvn_agent_hub_wp_net_net_blogs[ $xnetvn_agent_hub_wp_net_blog_key ] ] ?? array(),
			'ids'    => $xnetvn_agent_hub_wp_net_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_blog_key ] ?? array(),
			'meta'   => array(
				'target' => 'network',
				'blog'   => (int) $xnetvn_agent_hub_wp_net_net_blogs[ $xnetvn_agent_hub_wp_net_blog_key ],
				'run_id' => $xnetvn_agent_hub_wp_net_run_id,
			),
		)
	);
	$xnetvn_agent_hub_wp_net_clients[ $xnetvn_agent_hub_wp_net_blog_key ]  = $xnetvn_agent_hub_wp_net_client;
	$xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ]  = $xnetvn_agent_hub_wp_net_harness_obj;
	$xnetvn_agent_hub_wp_net_envelope[ $xnetvn_agent_hub_wp_net_blog_key ] = 1;
}
$xnetvn_agent_hub_wp_net_failed  = false;
$xnetvn_agent_hub_wp_net_tracked = array(
	'main'  => array(),
	'child' => array(),
);

/**
 * Delete one matrix-created post or attachment on a blog.
 *
 * @param RoleSeoHarness $xnetvn_agent_hub_wp_net_harness Blog harness.
 * @param string         $xnetvn_agent_hub_wp_net_actor Actor alias with a destructive key.
 * @param string         $xnetvn_agent_hub_wp_net_kind post or media.
 * @param int            $xnetvn_agent_hub_wp_net_id Logical id resolving to the real id.
 * @return bool
 */
$xnetvn_agent_hub_wp_net_delete = static function ( RoleSeoHarness $xnetvn_agent_hub_wp_net_harness, string $xnetvn_agent_hub_wp_net_actor, string $xnetvn_agent_hub_wp_net_kind, int $xnetvn_agent_hub_wp_net_id ): bool {
	try {
		$xnetvn_agent_hub_wp_net_tool     = 'media' === $xnetvn_agent_hub_wp_net_kind ? 'delete-media' : 'delete-post';
		$xnetvn_agent_hub_wp_net_response = $xnetvn_agent_hub_wp_net_harness->call( $xnetvn_agent_hub_wp_net_actor, $xnetvn_agent_hub_wp_net_tool, array( 'id' => $xnetvn_agent_hub_wp_net_id ) );
		return 200 === ( $xnetvn_agent_hub_wp_net_response['status'] ?? 0 ) && true !== ( $xnetvn_agent_hub_wp_net_response['body']['result']['isError'] ?? false );
	} catch ( \Throwable ) {
		return false;
	}
};

/**
 * Alias a runtime-created real id behind a free logical id.
 *
 * @param RoleSeoHarness $xnetvn_agent_hub_wp_net_harness Blog harness owning the ledger.
 * @param int            $xnetvn_agent_hub_wp_net_real Real id returned by the lab.
 * @return int Logical id safe for follow-up harness calls.
 */
$xnetvn_agent_hub_wp_net_runtime_logical = 901;
$xnetvn_agent_hub_wp_net_alias           = static function ( RoleSeoHarness $xnetvn_agent_hub_wp_net_harness, int $xnetvn_agent_hub_wp_net_real ) use ( &$xnetvn_agent_hub_wp_net_runtime_logical ): int {
	$xnetvn_agent_hub_wp_net_logical = $xnetvn_agent_hub_wp_net_runtime_logical++;
	$xnetvn_agent_hub_wp_net_harness->aliasId( $xnetvn_agent_hub_wp_net_logical, $xnetvn_agent_hub_wp_net_real );
	return $xnetvn_agent_hub_wp_net_logical;
};

/**
 * Select the matrix part: `a` runs the per-blog isolation groups
 * (NET-01/02/06/07), `b` runs the cross-blog policy groups (NET-03/04/05)
 * with a budget-decay pause before the explicit-deny probes. The runner
 * passes the part through; an absent or unknown value keeps the legacy
 * full run.
 */
$xnetvn_agent_hub_wp_net_part = getenv( 'XNETVN_ROLE_SEO_NET_PART' );
if ( ! is_string( $xnetvn_agent_hub_wp_net_part ) || ! in_array( $xnetvn_agent_hub_wp_net_part, array( 'all', 'a', 'b' ), true ) ) {
	$xnetvn_agent_hub_wp_net_part = 'all';
}
$xnetvn_agent_hub_wp_net_run_isolation = 'all' === $xnetvn_agent_hub_wp_net_part || 'a' === $xnetvn_agent_hub_wp_net_part;
$xnetvn_agent_hub_wp_net_run_policy    = 'all' === $xnetvn_agent_hub_wp_net_part || 'b' === $xnetvn_agent_hub_wp_net_part;

if ( $xnetvn_agent_hub_wp_net_run_isolation ) {
	// Part-gated: isolation groups NET-01/02 run in part a..
	// NET-01: site identity per blog over HTTPS with owned keys.
	foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
		$xnetvn_agent_hub_wp_net_harness_single = $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ];
		$xnetvn_agent_hub_wp_net_case           = 'NET-01-version-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok             = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_bases, $xnetvn_agent_hub_wp_net_blog_key ): bool {
				$xnetvn_agent_hub_wp_net_response = $xnetvn_agent_hub_wp_net_harness_single->call( 'admin', 'get-site-info', array() );
				$xnetvn_agent_hub_wp_net_info     = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_response );
				if ( null === $xnetvn_agent_hub_wp_net_info ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_url = isset( $xnetvn_agent_hub_wp_net_info['url'] ) && is_string( $xnetvn_agent_hub_wp_net_info['url'] ) ? rtrim( $xnetvn_agent_hub_wp_net_info['url'], '/' ) : '';
				if ( rtrim( $xnetvn_agent_hub_wp_net_bases[ $xnetvn_agent_hub_wp_net_blog_key ], '/' ) !== $xnetvn_agent_hub_wp_net_url ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_version = isset( $xnetvn_agent_hub_wp_net_info['wp_version'] ) && is_string( $xnetvn_agent_hub_wp_net_info['wp_version'] ) ? $xnetvn_agent_hub_wp_net_info['wp_version'] : '';
				return '' !== $xnetvn_agent_hub_wp_net_version && str_starts_with( $xnetvn_agent_hub_wp_net_version, '7.1.' );
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
	}

	// NET-02: representative B–E invariant replay per blog.
	foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
		$xnetvn_agent_hub_wp_net_harness_single = $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ];

		$xnetvn_agent_hub_wp_net_case = 'NET-02-auth-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok ): bool {
				$xnetvn_agent_hub_wp_net_positive = $xnetvn_agent_hub_wp_net_harness_single->call( 'admin', 'get-session-info', array() );
				if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_positive ) ) {
					return false;
				}
				// The subscriber lacks USE on this blog, so the authenticator
				// denies at the HTTP layer (OwnerForbidden → 403) before MCP
				// dispatch; there is no tool-shaped denial to expect here.
				$xnetvn_agent_hub_wp_net_subscriber = $xnetvn_agent_hub_wp_net_harness_single->call(
					'subscriber',
					'create-post',
					array(
						'title'  => 'Net auth probe',
						'status' => 'draft',
					)
				);
				if ( 403 !== ( $xnetvn_agent_hub_wp_net_subscriber['status'] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_banned = $xnetvn_agent_hub_wp_net_harness_single->call( 'banned', 'get-session-info', array() );
				if ( 403 !== ( $xnetvn_agent_hub_wp_net_banned['status'] ?? 0 ) ) {
					return false;
				}
				// The read-scoped subscriber key also lacks USE, so delete-post
				// fails at the authenticator with 403 instead of the hidden-tool
				// shape reserved for keys that pass the gate.
				$xnetvn_agent_hub_wp_net_sub_read = $xnetvn_agent_hub_wp_net_harness_single->call( 'sub_read', 'delete-post', array( 'id' => 101 ) );
				return 403 === ( $xnetvn_agent_hub_wp_net_sub_read['status'] ?? 0 );
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

		$xnetvn_agent_hub_wp_net_case = 'NET-02-seo-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_denied, $xnetvn_agent_hub_wp_net_delete, $xnetvn_agent_hub_wp_net_alias, $xnetvn_agent_hub_wp_net_blog_key, &$xnetvn_agent_hub_wp_net_tracked ): bool {
				$xnetvn_agent_hub_wp_net_created = $xnetvn_agent_hub_wp_net_harness_single->call(
					'author',
					'create-post',
					array(
						'title'  => 'Net seo probe ' . $xnetvn_agent_hub_wp_net_blog_key,
						'status' => 'draft',
					)
				);
				$xnetvn_agent_hub_wp_net_data    = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_created );
				$xnetvn_agent_hub_wp_net_real    = isset( $xnetvn_agent_hub_wp_net_data['id'] ) && is_int( $xnetvn_agent_hub_wp_net_data['id'] ) ? $xnetvn_agent_hub_wp_net_data['id'] : 0;
				if ( 1 > $xnetvn_agent_hub_wp_net_real ) {
					return false;
				}
				// Lab real ids may fall inside the logical band; alias the fresh
				// id so follow-up calls cannot be mistaken for fixture ids.
				$xnetvn_agent_hub_wp_net_id = $xnetvn_agent_hub_wp_net_alias( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_real );
				$xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ][] = array( 'post', $xnetvn_agent_hub_wp_net_id );
				$xnetvn_agent_hub_wp_net_read = $xnetvn_agent_hub_wp_net_harness_single->call( 'author', 'get-post', array( 'id' => $xnetvn_agent_hub_wp_net_id ) );
				$xnetvn_agent_hub_wp_net_seen = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_read );
				if ( null === $xnetvn_agent_hub_wp_net_seen ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_updated = $xnetvn_agent_hub_wp_net_harness_single->call(
					'author',
					'update-post',
					array(
						'id'    => $xnetvn_agent_hub_wp_net_id,
						'title' => 'Net seo probe revised ' . $xnetvn_agent_hub_wp_net_blog_key,
					)
				);
				if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_updated ) ) {
					return false;
				}
				if ( ! $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_harness_single, 'editor', 'post', $xnetvn_agent_hub_wp_net_id ) ) {
					return false;
				}
				array_pop( $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] );
				$xnetvn_agent_hub_wp_net_gone = $xnetvn_agent_hub_wp_net_harness_single->call( 'author', 'get-post', array( 'id' => $xnetvn_agent_hub_wp_net_id ) );
				return $xnetvn_agent_hub_wp_net_denied( $xnetvn_agent_hub_wp_net_gone );
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

		$xnetvn_agent_hub_wp_net_case = 'NET-02-img-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_delete, $xnetvn_agent_hub_wp_net_alias, $xnetvn_agent_hub_wp_net_blog_key, &$xnetvn_agent_hub_wp_net_tracked ): bool {
				$xnetvn_agent_hub_wp_net_pixel  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
				$xnetvn_agent_hub_wp_net_upload = $xnetvn_agent_hub_wp_net_harness_single->call(
					'admin',
					'upload-media-from-data',
					array(
						'filename'    => 'net-probe.png',
						'mime_type'   => 'image/png',
						'data_base64' => $xnetvn_agent_hub_wp_net_pixel,
					)
				);
				$xnetvn_agent_hub_wp_net_data   = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_upload );
				$xnetvn_agent_hub_wp_net_real   = isset( $xnetvn_agent_hub_wp_net_data['id'] ) && is_int( $xnetvn_agent_hub_wp_net_data['id'] ) ? $xnetvn_agent_hub_wp_net_data['id'] : 0;
				if ( 1 > $xnetvn_agent_hub_wp_net_real || 'image/png' !== ( $xnetvn_agent_hub_wp_net_data['mime_type'] ?? null ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_id = $xnetvn_agent_hub_wp_net_alias( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_real );
				$xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ][] = array( 'media', $xnetvn_agent_hub_wp_net_id );
				// The subscriber lacks USE on this blog, so the upload fails at
				// the authenticator with 403 before any policy evaluation.
				$xnetvn_agent_hub_wp_net_refused = $xnetvn_agent_hub_wp_net_harness_single->call(
					'subscriber',
					'upload-media-from-data',
					array(
						'filename'    => 'net-probe-sub.png',
						'mime_type'   => 'image/png',
						'data_base64' => $xnetvn_agent_hub_wp_net_pixel,
					)
				);
				if ( 403 !== ( $xnetvn_agent_hub_wp_net_refused['status'] ?? 0 ) ) {
					return false;
				}
				if ( ! $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_harness_single, 'admin', 'media', $xnetvn_agent_hub_wp_net_id ) ) {
					return false;
				}
				array_pop( $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] );
				return true;
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

		$xnetvn_agent_hub_wp_net_case = 'NET-02-life-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_delete, $xnetvn_agent_hub_wp_net_alias, $xnetvn_agent_hub_wp_net_blog_key, &$xnetvn_agent_hub_wp_net_tracked ): bool {
				$xnetvn_agent_hub_wp_net_created = $xnetvn_agent_hub_wp_net_harness_single->call(
					'editor',
					'create-post',
					array(
						'title'  => 'Net life probe ' . $xnetvn_agent_hub_wp_net_blog_key,
						'status' => 'draft',
					)
				);
				$xnetvn_agent_hub_wp_net_data    = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_created );
				$xnetvn_agent_hub_wp_net_real    = isset( $xnetvn_agent_hub_wp_net_data['id'] ) && is_int( $xnetvn_agent_hub_wp_net_data['id'] ) ? $xnetvn_agent_hub_wp_net_data['id'] : 0;
				if ( 1 > $xnetvn_agent_hub_wp_net_real ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_id = $xnetvn_agent_hub_wp_net_alias( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_real );
				$xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ][] = array( 'post', $xnetvn_agent_hub_wp_net_id );
				$xnetvn_agent_hub_wp_net_pending                                        = $xnetvn_agent_hub_wp_net_harness_single->call(
					'editor',
					'update-post',
					array(
						'id'     => $xnetvn_agent_hub_wp_net_id,
						'status' => 'pending',
					)
				);
				$xnetvn_agent_hub_wp_net_seen = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_pending );
				if ( null === $xnetvn_agent_hub_wp_net_seen || 'pending' !== ( $xnetvn_agent_hub_wp_net_seen['status'] ?? null ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_trashed = $xnetvn_agent_hub_wp_net_harness_single->call( 'editor', 'trash-post', array( 'id' => $xnetvn_agent_hub_wp_net_id ) );
				if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_trashed ) ) {
					return false;
				}
				if ( ! $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_harness_single, 'editor', 'post', $xnetvn_agent_hub_wp_net_id ) ) {
					return false;
				}
				array_pop( $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] );
				return true;
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
	}
}

if ( $xnetvn_agent_hub_wp_net_run_policy ) {
	// Part-gated: policy groups NET-03/04/05 run in part b..
	// NET-03: per-blog roles and wrong-site tokens, both directions.
	$xnetvn_agent_hub_wp_net_case = 'NET-03-role-main-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok ): bool {
			$xnetvn_agent_hub_wp_net_main  = $xnetvn_agent_hub_wp_net_harness['main'];
			$xnetvn_agent_hub_wp_net_child = $xnetvn_agent_hub_wp_net_harness['child'];
			// Same user is editor on main and subscriber on child.
			$xnetvn_agent_hub_wp_net_main_role  = $xnetvn_agent_hub_wp_net_action( 'main', 'role-blog', 'editor', 'editor' );
			$xnetvn_agent_hub_wp_net_child_role = $xnetvn_agent_hub_wp_net_action( 'child', 'role-blog', 'editor', 'subscriber' );
			if ( array( 'editor' ) !== ( $xnetvn_agent_hub_wp_net_main_role['role'] ?? null ) || array( 'subscriber' ) !== ( $xnetvn_agent_hub_wp_net_child_role['role'] ?? null ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_main_update = $xnetvn_agent_hub_wp_net_main->call(
				'editor',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Net role main probe',
				)
			);
			if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_main_update ) ) {
				return false;
			}
			// On child the same owner is a subscriber without USE, so the
			// authenticator answers 403 before MCP dispatch.
			$xnetvn_agent_hub_wp_net_child_denied = $xnetvn_agent_hub_wp_net_child->call(
				'editor',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Net role child probe',
				)
			);
			if ( 403 !== ( $xnetvn_agent_hub_wp_net_child_denied['status'] ?? 0 ) ) {
				return false;
			}
			// Swap: subscriber on main, editor on child, then repeat.
			$xnetvn_agent_hub_wp_net_main_swap  = $xnetvn_agent_hub_wp_net_action( 'main', 'role-blog', 'editor', 'subscriber' );
			$xnetvn_agent_hub_wp_net_child_swap = $xnetvn_agent_hub_wp_net_action( 'child', 'role-blog', 'editor', 'editor' );
			if ( array( 'subscriber' ) !== ( $xnetvn_agent_hub_wp_net_main_swap['role'] ?? null ) || array( 'editor' ) !== ( $xnetvn_agent_hub_wp_net_child_swap['role'] ?? null ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_child_update = $xnetvn_agent_hub_wp_net_child->call(
				'editor',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Net role child probe',
				)
			);
			if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_child_update ) ) {
				return false;
			}
			// After the swap the same owner is a subscriber on main, so the
			// authenticator answers 403 there as well.
			$xnetvn_agent_hub_wp_net_main_denied = $xnetvn_agent_hub_wp_net_main->call(
				'editor',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Net role main probe',
				)
			);
			if ( 403 !== ( $xnetvn_agent_hub_wp_net_main_denied['status'] ?? 0 ) ) {
				return false;
			}
			// Restore the seeded roles on both blogs.
			$xnetvn_agent_hub_wp_net_action( 'main', 'role-blog', 'editor', 'editor' );
			$xnetvn_agent_hub_wp_net_action( 'child', 'role-blog', 'editor', 'editor' );
			return true;
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['main'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	$xnetvn_agent_hub_wp_net_case = 'NET-03-token-main-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_clients, $xnetvn_agent_hub_wp_net_send, &$xnetvn_agent_hub_wp_net_envelope, $xnetvn_agent_hub_wp_net_net_state, $xnetvn_agent_hub_wp_net_net_blogs ): bool {
			// A main-blog bearer is unknown on the child blog and vice versa.
			$xnetvn_agent_hub_wp_net_main_token  = $xnetvn_agent_hub_wp_net_net_state['tokens'][ (int) $xnetvn_agent_hub_wp_net_net_blogs['main'] ]['admin'] ?? null;
			$xnetvn_agent_hub_wp_net_child_token = $xnetvn_agent_hub_wp_net_net_state['tokens'][ (int) $xnetvn_agent_hub_wp_net_net_blogs['child'] ]['admin'] ?? null;
			if ( ! is_string( $xnetvn_agent_hub_wp_net_main_token ) || '' === $xnetvn_agent_hub_wp_net_main_token || ! is_string( $xnetvn_agent_hub_wp_net_child_token ) || '' === $xnetvn_agent_hub_wp_net_child_token ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_main_id           = $xnetvn_agent_hub_wp_net_envelope['main'];
			$xnetvn_agent_hub_wp_net_child_id          = $xnetvn_agent_hub_wp_net_envelope['child'];
			$xnetvn_agent_hub_wp_net_foreign_on_child  = $xnetvn_agent_hub_wp_net_send(
				$xnetvn_agent_hub_wp_net_clients['child'],
				$xnetvn_agent_hub_wp_net_child_id,
				$xnetvn_agent_hub_wp_net_main_token,
				'tools/call',
				array(
					'name'      => 'xnetvn-get-site-info',
					'arguments' => (object) array(),
				)
			);
			$xnetvn_agent_hub_wp_net_foreign_on_main   = $xnetvn_agent_hub_wp_net_send(
				$xnetvn_agent_hub_wp_net_clients['main'],
				$xnetvn_agent_hub_wp_net_main_id,
				$xnetvn_agent_hub_wp_net_child_token,
				'tools/call',
				array(
					'name'      => 'xnetvn-get-site-info',
					'arguments' => (object) array(),
				)
			);
			$xnetvn_agent_hub_wp_net_envelope['main']  = $xnetvn_agent_hub_wp_net_main_id;
			$xnetvn_agent_hub_wp_net_envelope['child'] = $xnetvn_agent_hub_wp_net_child_id;
			return 401 === ( $xnetvn_agent_hub_wp_net_foreign_on_child['status'] ?? 0 ) && 401 === ( $xnetvn_agent_hub_wp_net_foreign_on_main['status'] ?? 0 );
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['main'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	$xnetvn_agent_hub_wp_net_case = 'NET-03-ban-main-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_hidden, $xnetvn_agent_hub_wp_net_delete, $xnetvn_agent_hub_wp_net_alias, &$xnetvn_agent_hub_wp_net_tracked ): bool {
			$xnetvn_agent_hub_wp_net_main  = $xnetvn_agent_hub_wp_net_harness['main'];
			$xnetvn_agent_hub_wp_net_child = $xnetvn_agent_hub_wp_net_harness['child'];
			// Same user is author on main and banned on child.
			$xnetvn_agent_hub_wp_net_action( 'main', 'role-blog', 'author', 'author' );
			$xnetvn_agent_hub_wp_net_action( 'child', 'role-blog', 'author', 'banned' );
			try {
				// The same user keeps author powers on main: create, revise
				// and remove an owned probe post (post 101 belongs to the
				// editor, so updating it would be forbidden for the author).
				$xnetvn_agent_hub_wp_net_main_created = $xnetvn_agent_hub_wp_net_main->call(
					'author',
					'create-post',
					array(
						'title'  => 'Net ban main probe',
						'status' => 'draft',
					)
				);
				$xnetvn_agent_hub_wp_net_main_data    = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_main_created );
				$xnetvn_agent_hub_wp_net_main_real    = isset( $xnetvn_agent_hub_wp_net_main_data['id'] ) && is_int( $xnetvn_agent_hub_wp_net_main_data['id'] ) ? $xnetvn_agent_hub_wp_net_main_data['id'] : 0;
				if ( 1 > $xnetvn_agent_hub_wp_net_main_real ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_main_id           = $xnetvn_agent_hub_wp_net_alias( $xnetvn_agent_hub_wp_net_main, $xnetvn_agent_hub_wp_net_main_real );
				$xnetvn_agent_hub_wp_net_tracked['main'][] = array( 'post', $xnetvn_agent_hub_wp_net_main_id );
				$xnetvn_agent_hub_wp_net_main_updated      = $xnetvn_agent_hub_wp_net_main->call(
					'author',
					'update-post',
					array(
						'id'    => $xnetvn_agent_hub_wp_net_main_id,
						'title' => 'Net ban main probe revised',
					)
				);
				if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_main_updated ) ) {
					return false;
				}
				if ( ! $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_main, 'editor', 'post', $xnetvn_agent_hub_wp_net_main_id ) ) {
					return false;
				}
				array_pop( $xnetvn_agent_hub_wp_net_tracked['main'] );
				// On main the author passes the gate but delete-post stays
				// hidden by key scope (mirrors single-site AUTH-04-deny); on
				// child the same owner is banned without USE, so the
				// authenticator answers 403. The denied calls mutate nothing.
				$xnetvn_agent_hub_wp_net_main_hidden = $xnetvn_agent_hub_wp_net_main->call( 'author', 'delete-post', array( 'id' => 101 ) );
				if ( ! $xnetvn_agent_hub_wp_net_hidden( $xnetvn_agent_hub_wp_net_main_hidden ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_child_forbidden = $xnetvn_agent_hub_wp_net_child->call( 'author', 'delete-post', array( 'id' => 101 ) );
				if ( 403 !== ( $xnetvn_agent_hub_wp_net_child_forbidden['status'] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_child_verify = $xnetvn_agent_hub_wp_net_action( 'child', 'ban-verify', 'author' );
				if ( array( 'banned' ) !== ( $xnetvn_agent_hub_wp_net_child_verify['roles'] ?? null ) || false !== ( $xnetvn_agent_hub_wp_net_child_verify['use'] ?? null ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_child_denied = $xnetvn_agent_hub_wp_net_child->call( 'author', 'get-session-info', array() );
				return 403 === ( $xnetvn_agent_hub_wp_net_child_denied['status'] ?? 0 );
			} finally {
				$xnetvn_agent_hub_wp_net_action( 'main', 'role-blog', 'author', 'author' );
				$xnetvn_agent_hub_wp_net_action( 'child', 'role-blog', 'author', 'author' );
			}
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['main'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	// NET-04: same numeric id is a different resource per blog.
	$xnetvn_agent_hub_wp_net_case = 'NET-04-collision-main-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_send, $xnetvn_agent_hub_wp_net_clients, &$xnetvn_agent_hub_wp_net_envelope, $xnetvn_agent_hub_wp_net_net_state, $xnetvn_agent_hub_wp_net_net_blogs ): bool {
			$xnetvn_agent_hub_wp_net_main                = $xnetvn_agent_hub_wp_net_harness['main'];
			$xnetvn_agent_hub_wp_net_child               = $xnetvn_agent_hub_wp_net_harness['child'];
			$xnetvn_agent_hub_wp_net_main_digest_before  = $xnetvn_agent_hub_wp_net_action( 'main', 'content-digest' );
			$xnetvn_agent_hub_wp_net_child_digest_before = $xnetvn_agent_hub_wp_net_action( 'child', 'content-digest' );
			$xnetvn_agent_hub_wp_net_main_title_before   = $xnetvn_agent_hub_wp_net_main_digest_before['content'][101]['digest'] ?? '';
			$xnetvn_agent_hub_wp_net_child_title_before  = $xnetvn_agent_hub_wp_net_child_digest_before['content'][101]['digest'] ?? '';
			if ( '' === $xnetvn_agent_hub_wp_net_main_title_before || '' === $xnetvn_agent_hub_wp_net_child_title_before ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_main_read  = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_main->call( 'editor', 'get-post', array( 'id' => 101 ) ) );
			$xnetvn_agent_hub_wp_net_child_read = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_child->call( 'editor', 'get-post', array( 'id' => 101 ) ) );
			if ( null === $xnetvn_agent_hub_wp_net_main_read || null === $xnetvn_agent_hub_wp_net_child_read ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_main_title  = $xnetvn_agent_hub_wp_net_main_read['title'] ?? null;
			$xnetvn_agent_hub_wp_net_child_title = $xnetvn_agent_hub_wp_net_child_read['title'] ?? null;
			if ( ! is_string( $xnetvn_agent_hub_wp_net_main_title ) || ! is_string( $xnetvn_agent_hub_wp_net_child_title ) || $xnetvn_agent_hub_wp_net_main_title === $xnetvn_agent_hub_wp_net_child_title ) {
				return false;
			}
			// A write on main must not touch the child resource with the same id.
			$xnetvn_agent_hub_wp_net_updated = $xnetvn_agent_hub_wp_net_main->call(
				'editor',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Net collision main probe',
				)
			);
			if ( null === $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_updated ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_child_digest_after = $xnetvn_agent_hub_wp_net_action( 'child', 'content-digest' );
			if ( ( $xnetvn_agent_hub_wp_net_child_digest_after['content'][101]['digest'] ?? null ) !== $xnetvn_agent_hub_wp_net_child_title_before ) {
				return false;
			}
			// A child-blog bearer is unknown on the main blog.
			$xnetvn_agent_hub_wp_net_child_token = $xnetvn_agent_hub_wp_net_net_state['tokens'][ (int) $xnetvn_agent_hub_wp_net_net_blogs['child'] ]['admin'] ?? null;
			if ( ! is_string( $xnetvn_agent_hub_wp_net_child_token ) || '' === $xnetvn_agent_hub_wp_net_child_token ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_main_id          = $xnetvn_agent_hub_wp_net_envelope['main'];
			$xnetvn_agent_hub_wp_net_foreign          = $xnetvn_agent_hub_wp_net_send(
				$xnetvn_agent_hub_wp_net_clients['main'],
				$xnetvn_agent_hub_wp_net_main_id,
				$xnetvn_agent_hub_wp_net_child_token,
				'tools/call',
				array(
					'name'      => 'xnetvn-get-post',
					'arguments' => (object) array( 'id' => 101 ),
				)
			);
			$xnetvn_agent_hub_wp_net_envelope['main'] = $xnetvn_agent_hub_wp_net_main_id;
			if ( 401 !== ( $xnetvn_agent_hub_wp_net_foreign['status'] ?? 0 ) ) {
				return false;
			}
			// Free id on both blogs, then two owned posts with that numeric id.
			$xnetvn_agent_hub_wp_net_pick      = $xnetvn_agent_hub_wp_net_action( 'main', 'collision-pick' );
			$xnetvn_agent_hub_wp_net_candidate = isset( $xnetvn_agent_hub_wp_net_pick['candidate'] ) && is_int( $xnetvn_agent_hub_wp_net_pick['candidate'] ) ? $xnetvn_agent_hub_wp_net_pick['candidate'] : 0;
			if ( 1 > $xnetvn_agent_hub_wp_net_candidate ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_made = $xnetvn_agent_hub_wp_net_action( 'main', 'collision-make', (string) $xnetvn_agent_hub_wp_net_candidate );
			return isset( $xnetvn_agent_hub_wp_net_made['collision']['main'], $xnetvn_agent_hub_wp_net_made['collision']['child'] );
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['main'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	// Let the per-minute auth-failure budget decay before the explicit-deny
	// probes: each split run then stays under the lab limit on its own window.
	sleep( 120 );

	// NET-05: super administrator obeys explicit deny and corrupt settings.
	foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
		$xnetvn_agent_hub_wp_net_harness_single = $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ];
		foreach ( array( 'deny', 'corrupt' ) as $xnetvn_agent_hub_wp_net_variant ) {
			$xnetvn_agent_hub_wp_net_case = 'NET-05-' . $xnetvn_agent_hub_wp_net_variant . '-' . $xnetvn_agent_hub_wp_net_blog_key;
			$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
				$xnetvn_agent_hub_wp_net_timings,
				$xnetvn_agent_hub_wp_net_case,
				static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_blog_key, $xnetvn_agent_hub_wp_net_variant ): bool {
					$xnetvn_agent_hub_wp_net_grant = $xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'super', 'grant' );
					if ( true !== ( $xnetvn_agent_hub_wp_net_grant['super_admin'] ?? null ) ) {
						return false;
					}
					try {
						$xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'settings-variant', $xnetvn_agent_hub_wp_net_variant );
						// Explicit deny empties the allowed roles, so even the
						// super-admin owner loses USE and the authenticator
						// answers 403 before MCP dispatch. Corrupt settings fail
						// closed one layer later: the owner keeps USE but settings
						// reads throw, so the authenticator answers 503. Either
						// way no tool-shaped denial applies here.
						$xnetvn_agent_hub_wp_net_write_denied = $xnetvn_agent_hub_wp_net_harness_single->call( 'super_write', 'get-post', array( 'id' => 101 ) );
						if ( 'corrupt' === $xnetvn_agent_hub_wp_net_variant ? 503 !== ( $xnetvn_agent_hub_wp_net_write_denied['status'] ?? 0 ) : 403 !== ( $xnetvn_agent_hub_wp_net_write_denied['status'] ?? 0 ) ) {
							return false;
						}
						$xnetvn_agent_hub_wp_net_read_denied = $xnetvn_agent_hub_wp_net_harness_single->call( 'super_read', 'get-post', array( 'id' => 101 ) );
						if ( 'corrupt' === $xnetvn_agent_hub_wp_net_variant ? 503 !== ( $xnetvn_agent_hub_wp_net_read_denied['status'] ?? 0 ) : 403 !== ( $xnetvn_agent_hub_wp_net_read_denied['status'] ?? 0 ) ) {
							return false;
						}
						// An admin-tier tool stays out of reach for the write key.
						$xnetvn_agent_hub_wp_net_tier_denied = $xnetvn_agent_hub_wp_net_harness_single->call(
							'super_write',
							'set-user-status',
							array(
								'id'     => 1,
								'status' => 'active',
							)
						);
						if ( 'corrupt' === $xnetvn_agent_hub_wp_net_variant ? 503 !== ( $xnetvn_agent_hub_wp_net_tier_denied['status'] ?? 0 ) : 403 !== ( $xnetvn_agent_hub_wp_net_tier_denied['status'] ?? 0 ) ) {
							return false;
						}
						return true;
					} finally {
						// Direct management repair is not blocked by the MCP deny.
						$xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'settings-variant', 'reset' );
					}
				}
			);
			$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
			$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
			// The repair must restore reads for the scope-limited super key.
			// get-session-info fits the read/system scope; get-post stays hidden
			// for that key even at baseline, so it cannot prove the repair.
			$xnetvn_agent_hub_wp_net_case_repair = 'NET-05-repair-' . $xnetvn_agent_hub_wp_net_variant . '-' . $xnetvn_agent_hub_wp_net_blog_key;
			$xnetvn_agent_hub_wp_net_ok_repair   = $xnetvn_agent_hub_wp_net_time(
				$xnetvn_agent_hub_wp_net_timings,
				$xnetvn_agent_hub_wp_net_case_repair,
				static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok ): bool {
					$xnetvn_agent_hub_wp_net_response = $xnetvn_agent_hub_wp_net_harness_single->call( 'super_read', 'get-session-info', array() );
					return null !== $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_response );
				}
			);
			$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case_repair, $xnetvn_agent_hub_wp_net_ok_repair, $xnetvn_agent_hub_wp_net_ok_repair ? 'unavailable' : 'internal_error' );
			$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok_repair;
		}
		$xnetvn_agent_hub_wp_net_revoke    = $xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'super', 'revoke' );
		$xnetvn_agent_hub_wp_net_revoke_ok = false === ( $xnetvn_agent_hub_wp_net_revoke['super_admin'] ?? null );
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, 'NET-05-revoke-' . $xnetvn_agent_hub_wp_net_blog_key, $xnetvn_agent_hub_wp_net_revoke_ok, $xnetvn_agent_hub_wp_net_revoke_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_revoke_ok;
	}
}

if ( $xnetvn_agent_hub_wp_net_run_isolation ) {
	// Part-gated: isolation groups NET-06/07 run in part a..
	// NET-06: upload quota and HTML filtering on the network.
	$xnetvn_agent_hub_wp_net_case = 'NET-06-quota-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_denied, $xnetvn_agent_hub_wp_net_delete ): bool {
			$xnetvn_agent_hub_wp_net_child = $xnetvn_agent_hub_wp_net_harness['child'];
			$xnetvn_agent_hub_wp_net_pixel = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
			// Exhaust the child quota within the lab; no disk is filled. The
			// fixture also enables the network-wide upload-space check that the
			// lab disables, and quota-restore puts everything back.
			$xnetvn_agent_hub_wp_net_quota = $xnetvn_agent_hub_wp_net_action( 'child', 'quota-set', '0' );
			if ( false !== ( $xnetvn_agent_hub_wp_net_quota['available'] ?? null ) ) {
				return false;
			}
			try {
				$xnetvn_agent_hub_wp_net_refused = $xnetvn_agent_hub_wp_net_child->call(
					'author',
					'upload-media-from-data',
					array(
						'filename'    => 'net-quota.png',
						'mime_type'   => 'image/png',
						'data_base64' => $xnetvn_agent_hub_wp_net_pixel,
					)
				);
				if ( 200 !== ( $xnetvn_agent_hub_wp_net_refused['status'] ?? 0 ) || true !== ( $xnetvn_agent_hub_wp_net_refused['body']['result']['isError'] ?? false ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_text = $xnetvn_agent_hub_wp_net_refused['body']['result']['content'][0]['text'] ?? '';
				if ( ! is_string( $xnetvn_agent_hub_wp_net_text ) || ! str_contains( $xnetvn_agent_hub_wp_net_text, 'quota_exceeded' ) ) {
					return false;
				}
				return true;
			} finally {
				$xnetvn_agent_hub_wp_net_action( 'child', 'quota-restore' );
			}
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['child'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'quota_exceeded' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
		$xnetvn_agent_hub_wp_net_harness_single = $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ];
		$xnetvn_agent_hub_wp_net_case           = 'NET-06-html-' . $xnetvn_agent_hub_wp_net_blog_key;
		$xnetvn_agent_hub_wp_net_ok             = $xnetvn_agent_hub_wp_net_time(
			$xnetvn_agent_hub_wp_net_timings,
			$xnetvn_agent_hub_wp_net_case,
			static function () use ( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_check_ok, $xnetvn_agent_hub_wp_net_delete, $xnetvn_agent_hub_wp_net_alias, $xnetvn_agent_hub_wp_net_blog_key, &$xnetvn_agent_hub_wp_net_tracked ): bool {
				$xnetvn_agent_hub_wp_net_created = $xnetvn_agent_hub_wp_net_harness_single->call(
					'author',
					'create-post',
					array(
						'title'   => 'Net html probe ' . $xnetvn_agent_hub_wp_net_blog_key,
						'status'  => 'draft',
						'content' => '<b>bold</b><script>alert(1)</script>',
					)
				);
				$xnetvn_agent_hub_wp_net_data    = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_created );
				$xnetvn_agent_hub_wp_net_real    = isset( $xnetvn_agent_hub_wp_net_data['id'] ) && is_int( $xnetvn_agent_hub_wp_net_data['id'] ) ? $xnetvn_agent_hub_wp_net_data['id'] : 0;
				if ( 1 > $xnetvn_agent_hub_wp_net_real ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_id = $xnetvn_agent_hub_wp_net_alias( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_real );
				$xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ][] = array( 'post', $xnetvn_agent_hub_wp_net_id );
				try {
					$xnetvn_agent_hub_wp_net_read = $xnetvn_agent_hub_wp_net_harness_single->call( 'author', 'get-post', array( 'id' => $xnetvn_agent_hub_wp_net_id ) );
					$xnetvn_agent_hub_wp_net_seen = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_read );
					if ( null === $xnetvn_agent_hub_wp_net_seen ) {
						return false;
					}
					$xnetvn_agent_hub_wp_net_content = '';
					if ( isset( $xnetvn_agent_hub_wp_net_seen['content'] ) && is_string( $xnetvn_agent_hub_wp_net_seen['content'] ) ) {
						$xnetvn_agent_hub_wp_net_content = $xnetvn_agent_hub_wp_net_seen['content'];
					} elseif ( isset( $xnetvn_agent_hub_wp_net_seen['content']['rendered'] ) && is_string( $xnetvn_agent_hub_wp_net_seen['content']['rendered'] ) ) {
						$xnetvn_agent_hub_wp_net_content = $xnetvn_agent_hub_wp_net_seen['content']['rendered'];
					}
					if ( str_contains( $xnetvn_agent_hub_wp_net_content, '<script' ) ) {
						return false;
					}
					return true;
				} finally {
					if ( $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_harness_single, 'editor', 'post', $xnetvn_agent_hub_wp_net_id ) ) {
						array_pop( $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] );
					}
				}
			}
		);
		$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness_single, $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
	}

	// NET-07: options, keys and audit stay scoped to their blog.
	$xnetvn_agent_hub_wp_net_net_baseline = array();
	foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
		$xnetvn_agent_hub_wp_net_net_baseline[ $xnetvn_agent_hub_wp_net_blog_key ] = $xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'net-read' );
	}
	$xnetvn_agent_hub_wp_net_case = 'NET-07-option-main-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_check_ok ): bool {
			$xnetvn_agent_hub_wp_net_main  = $xnetvn_agent_hub_wp_net_harness['main'];
			$xnetvn_agent_hub_wp_net_child = $xnetvn_agent_hub_wp_net_harness['child'];
			$xnetvn_agent_hub_wp_net_action( 'main', 'blogname-set', 'a-probe' );
			try {
				$xnetvn_agent_hub_wp_net_main_info  = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_main->call( 'admin', 'get-site-info', array() ) );
				$xnetvn_agent_hub_wp_net_child_info = $xnetvn_agent_hub_wp_net_check_ok( $xnetvn_agent_hub_wp_net_child->call( 'admin', 'get-site-info', array() ) );
				if ( null === $xnetvn_agent_hub_wp_net_main_info || null === $xnetvn_agent_hub_wp_net_child_info ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_main_name  = $xnetvn_agent_hub_wp_net_main_info['name'] ?? null;
				$xnetvn_agent_hub_wp_net_child_name = $xnetvn_agent_hub_wp_net_child_info['name'] ?? null;
				if ( ! is_string( $xnetvn_agent_hub_wp_net_main_name ) || ! str_contains( $xnetvn_agent_hub_wp_net_main_name, 'a-probe' ) ) {
					return false;
				}
				return is_string( $xnetvn_agent_hub_wp_net_child_name ) && ! str_contains( $xnetvn_agent_hub_wp_net_child_name, 'a-probe' );
			} finally {
				$xnetvn_agent_hub_wp_net_action( 'main', 'settings-variant', 'reset' );
			}
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['main'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;

	$xnetvn_agent_hub_wp_net_case = 'NET-07-ban-child';
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_harness, $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_send, $xnetvn_agent_hub_wp_net_clients, &$xnetvn_agent_hub_wp_net_envelope, $xnetvn_agent_hub_wp_net_net_state ): bool {
			$xnetvn_agent_hub_wp_net_main  = $xnetvn_agent_hub_wp_net_harness['main'];
			$xnetvn_agent_hub_wp_net_child = $xnetvn_agent_hub_wp_net_harness['child'];
			$xnetvn_agent_hub_wp_net_users = $xnetvn_agent_hub_wp_net_net_state['users'] ?? array();
			if ( ! isset( $xnetvn_agent_hub_wp_net_users['contributor'], $xnetvn_agent_hub_wp_net_users['admin'] ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_net_action( 'child', 'settings-variant', 'ceiling-admin' );
			$xnetvn_agent_hub_wp_net_action( 'child', 'settings-variant', 'admin-on' );
			try {
				$xnetvn_agent_hub_wp_net_admin_token = $xnetvn_agent_hub_wp_net_net_state['tokens'][ $xnetvn_agent_hub_wp_net_net_state['blogs']['child'] ]['admin_users'] ?? null;
				if ( ! is_string( $xnetvn_agent_hub_wp_net_admin_token ) || '' === $xnetvn_agent_hub_wp_net_admin_token ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_child_users_token = $xnetvn_agent_hub_wp_net_admin_token;
				$xnetvn_agent_hub_wp_net_child_envelope_id = $xnetvn_agent_hub_wp_net_envelope['child'];
				$xnetvn_agent_hub_wp_net_ban               = $xnetvn_agent_hub_wp_net_send(
					$xnetvn_agent_hub_wp_net_clients['child'],
					$xnetvn_agent_hub_wp_net_child_envelope_id,
					$xnetvn_agent_hub_wp_net_child_users_token,
					'tools/call',
					array(
						'name'      => 'xnetvn-set-user-status',
						'arguments' => (object) array(
							'id'          => (int) $xnetvn_agent_hub_wp_net_users['contributor'],
							'status'      => 'banned',
							'reason_code' => 'policy',
						),
					)
				);
				$xnetvn_agent_hub_wp_net_envelope['child'] = $xnetvn_agent_hub_wp_net_child_envelope_id;
				if ( 200 !== ( $xnetvn_agent_hub_wp_net_ban['status'] ?? 0 ) || 'banned' !== ( $xnetvn_agent_hub_wp_net_ban['body']['result']['structuredContent']['status'] ?? null ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_verify = $xnetvn_agent_hub_wp_net_action( 'child', 'ban-verify', 'contributor' );
				if ( array( 'banned' ) !== ( $xnetvn_agent_hub_wp_net_verify['roles'] ?? null ) || 0 !== ( $xnetvn_agent_hub_wp_net_verify['sessions'] ?? -1 ) ) {
					return false;
				}
				// The same user keeps access on the other blog.
				$xnetvn_agent_hub_wp_net_main_still_ok = $xnetvn_agent_hub_wp_net_main->call( 'contributor', 'get-session-info', array() );
				if ( 200 !== ( $xnetvn_agent_hub_wp_net_main_still_ok['status'] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_child_denied = $xnetvn_agent_hub_wp_net_child->call( 'contributor', 'get-session-info', array() );
				if ( 403 !== ( $xnetvn_agent_hub_wp_net_child_denied['status'] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_unban_envelope_id = $xnetvn_agent_hub_wp_net_envelope['child'];
				$xnetvn_agent_hub_wp_net_unban             = $xnetvn_agent_hub_wp_net_send(
					$xnetvn_agent_hub_wp_net_clients['child'],
					$xnetvn_agent_hub_wp_net_unban_envelope_id,
					$xnetvn_agent_hub_wp_net_child_users_token,
					'tools/call',
					array(
						'name'      => 'xnetvn-set-user-status',
						'arguments' => (object) array(
							'id'     => (int) $xnetvn_agent_hub_wp_net_users['contributor'],
							'status' => 'active',
						),
					)
				);
				$xnetvn_agent_hub_wp_net_envelope['child'] = $xnetvn_agent_hub_wp_net_unban_envelope_id;
				if ( 200 !== ( $xnetvn_agent_hub_wp_net_unban['status'] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_net_restored = $xnetvn_agent_hub_wp_net_action( 'child', 'ban-verify', 'contributor' );
				return array( 'contributor' ) === ( $xnetvn_agent_hub_wp_net_restored['roles'] ?? null );
			} finally {
				$xnetvn_agent_hub_wp_net_action( 'child', 'settings-variant', 'reset' );
			}
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness['child'], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok, $xnetvn_agent_hub_wp_net_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok;
}

// Teardown: delete matrix-tracked ids, then compare owned digests.
$xnetvn_agent_hub_wp_net_teardown_ok = true;
foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
	while ( array() !== $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] ) {
		$xnetvn_agent_hub_wp_net_item = array_pop( $xnetvn_agent_hub_wp_net_tracked[ $xnetvn_agent_hub_wp_net_blog_key ] );
		if ( ! $xnetvn_agent_hub_wp_net_delete( $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ], 'admin', $xnetvn_agent_hub_wp_net_item[0], $xnetvn_agent_hub_wp_net_item[1] ) ) {
			$xnetvn_agent_hub_wp_net_teardown_ok = false;
		}
	}
}
foreach ( array( 'main', 'child' ) as $xnetvn_agent_hub_wp_net_blog_key ) {
	$xnetvn_agent_hub_wp_net_case = 'NET-07-digest-' . $xnetvn_agent_hub_wp_net_blog_key;
	$xnetvn_agent_hub_wp_net_ok   = $xnetvn_agent_hub_wp_net_time(
		$xnetvn_agent_hub_wp_net_timings,
		$xnetvn_agent_hub_wp_net_case,
		static function () use ( $xnetvn_agent_hub_wp_net_action, $xnetvn_agent_hub_wp_net_blog_key ): bool {
			$xnetvn_agent_hub_wp_net_after = $xnetvn_agent_hub_wp_net_action( $xnetvn_agent_hub_wp_net_blog_key, 'content-digest' );
			foreach ( array( 101, 202 ) as $xnetvn_agent_hub_wp_net_logical ) {
				$xnetvn_agent_hub_wp_net_post = $xnetvn_agent_hub_wp_net_after['content'][ $xnetvn_agent_hub_wp_net_logical ] ?? null;
				if ( ! is_array( $xnetvn_agent_hub_wp_net_post ) || ! isset( $xnetvn_agent_hub_wp_net_post['owner'], $xnetvn_agent_hub_wp_net_post['digest'] ) ) {
					return false;
				}
			}
			return true;
		}
	);
	$xnetvn_agent_hub_wp_net_record( $xnetvn_agent_hub_wp_net_harness[ $xnetvn_agent_hub_wp_net_blog_key ], $xnetvn_agent_hub_wp_net_case, $xnetvn_agent_hub_wp_net_ok && $xnetvn_agent_hub_wp_net_teardown_ok, $xnetvn_agent_hub_wp_net_ok && $xnetvn_agent_hub_wp_net_teardown_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_net_failed = $xnetvn_agent_hub_wp_net_failed || ! $xnetvn_agent_hub_wp_net_ok || ! $xnetvn_agent_hub_wp_net_teardown_ok;
}

$xnetvn_agent_hub_wp_net_net_cases = array();
foreach ( $xnetvn_agent_hub_wp_net_harness as $xnetvn_agent_hub_wp_net_harness ) {
	foreach ( $xnetvn_agent_hub_wp_net_harness->cases() as $xnetvn_agent_hub_wp_net_id => $xnetvn_agent_hub_wp_net_result ) {
		$xnetvn_agent_hub_wp_net_net_cases[ $xnetvn_agent_hub_wp_net_id ] = $xnetvn_agent_hub_wp_net_result;
	}
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
echo json_encode(
	array(
		'suite'     => 'role-seo-network',
		'target'    => 'network',
		'run_id'    => $xnetvn_agent_hub_wp_net_run_id,
		'cases'     => $xnetvn_agent_hub_wp_net_net_cases,
		'declared'  => xnetvn_agent_hub_wp_net_declare_cases(),
		'counts'    => array(
			'actors'     => count( $xnetvn_agent_hub_wp_net_net_state['users'] ?? array() ),
			'ledger_ids' => count( $xnetvn_agent_hub_wp_net_net_state['ledger'] ?? array() ),
		),
		'timing_ms' => $xnetvn_agent_hub_wp_net_timings,
		'cleanup'   => false,
	),
	JSON_THROW_ON_ERROR
) . "\n";
exit( $xnetvn_agent_hub_wp_net_failed ? 1 : 0 );
