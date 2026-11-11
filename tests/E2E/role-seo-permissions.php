<?php
/**
 * Owned role/SEO permission matrix: AUTH-01 through AUTH-10 declarations and
 * the MCP-level cases executed through real HTTPS.
 *
 * Each AUTH row from the ticket is a separate recorded case (multiplied by
 * role/mode/target where the ticket requires it). Reports carry only case
 * ids, allowlisted reasons, counts, digests and boolean checks; no input,
 * response bodies, cookies, bearers, hashes or argument values are printed.
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

/**
 * Declare the ten permission cases with their owner and applicability.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_permissions_declare_cases(): array {
	return array(
		array(
			'id'           => 'AUTH-01',
			'owner'        => 'matrix',
			'roles'        => array( 'contributor' ),
			'scope_modes'  => array( 'read' ),
			'variants'     => array( 'list', 'direct', 'wrapper', 'info' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-02',
			'owner'        => 'matrix',
			'roles'        => array( 'editor', 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'own', 'other', 'wrapper' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-03',
			'owner'        => 'matrix',
			'roles'        => array( 'contributor', 'subscriber', 'banned' ),
			'scope_modes'  => array( 'write', 'read' ),
			'variants'     => array( 'publish', 'future', 'media', 'no-use' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-04',
			'owner'        => 'matrix',
			'roles'        => array( 'contributor', 'author', 'author_b', 'editor', 'admin' ),
			'scope_modes'  => array( 'write', 'destructive', 'admin' ),
			'variants'     => array( 'toolset', 'allow', 'deny', 'ceiling', 'admin-off', 'hidden' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-05',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'demote', 'restore' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-06',
			'owner'        => 'matrix',
			'roles'        => array( 'author', 'admin' ),
			'scope_modes'  => array( 'write', 'admin' ),
			'variants'     => array( 'ban', 'unban' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-07',
			'owner'        => 'matrix',
			'roles'        => array( 'author', 'admin' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'missing', 'malformed', 'expired', 'revoked', 'unknown', 'bad', 'cookie', 'cookie-bearer' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-08',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'exact', 'suffix', 'management' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-09',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'positive', 'null', 'unlisted', 'allowed', 'plain', 'oversize' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'AUTH-10',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'schema', 'unknown', 'canary', 'report' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
	);
}

$xnetvn_agent_hub_wp_started  = hrtime( true );
$xnetvn_agent_hub_wp_timings  = array();
$xnetvn_agent_hub_wp_site     = getenv( 'XNETVN_MATRIX_SITE' );
$xnetvn_agent_hub_wp_run_id   = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
$xnetvn_agent_hub_wp_blog_raw = getenv( 'XNETVN_ROLE_SEO_BLOG' );
$xnetvn_agent_hub_wp_blog     = ( is_string( $xnetvn_agent_hub_wp_blog_raw ) && '' !== $xnetvn_agent_hub_wp_blog_raw ) ? $xnetvn_agent_hub_wp_blog_raw : '1';
if ( ! in_array( $xnetvn_agent_hub_wp_site, array( 'wp69', 'wp71', 'network-main', 'network-child' ), true ) || ! is_string( $xnetvn_agent_hub_wp_run_id ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $xnetvn_agent_hub_wp_run_id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_blog ) ) {
	exit( 2 );
}
// The multisite lab serves one registered domain only, so the network
// targets use the canonical domain and keep the blog subdirectory in the
// route instead of an alias.
$xnetvn_agent_hub_wp_network  = in_array( $xnetvn_agent_hub_wp_site, array( 'network-main', 'network-child' ), true );
$xnetvn_agent_hub_wp_host_key = $xnetvn_agent_hub_wp_network ? 'RTL_DOMAIN_WPNETWORK' : ( 'wp71' === $xnetvn_agent_hub_wp_site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' );
$xnetvn_agent_hub_wp_host     = getenv( $xnetvn_agent_hub_wp_host_key );
if ( ! is_string( $xnetvn_agent_hub_wp_host ) || '' === $xnetvn_agent_hub_wp_host ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_base_path  = 'network-child' === $xnetvn_agent_hub_wp_site ? '/ui-second' : '';
$xnetvn_agent_hub_wp_state_file = '/work/.local/role-seo/private/' . $xnetvn_agent_hub_wp_site . '/' . $xnetvn_agent_hub_wp_run_id . '/state.json';

try {
	$xnetvn_agent_hub_wp_ledger = RoleSeoHarness::loadLedger( $xnetvn_agent_hub_wp_state_file );
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO fixture state unavailable; seed before running the matrix.\n";
	exit( 1 );
}
$xnetvn_agent_hub_wp_meta = $xnetvn_agent_hub_wp_ledger['meta'];
if ( $xnetvn_agent_hub_wp_meta['target'] !== $xnetvn_agent_hub_wp_site || $xnetvn_agent_hub_wp_meta['run_id'] !== $xnetvn_agent_hub_wp_run_id || (int) $xnetvn_agent_hub_wp_meta['blog'] !== (int) $xnetvn_agent_hub_wp_blog ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO fixture marker mismatch; reconcile before running the matrix.\n";
	exit( 2 );
}
$xnetvn_agent_hub_wp_client  = new McpClient( 'https://' . $xnetvn_agent_hub_wp_host, array( $xnetvn_agent_hub_wp_host ), true );
$xnetvn_agent_hub_wp_harness = new RoleSeoHarness(
	array(
		'target'        => $xnetvn_agent_hub_wp_site,
		'blog'          => (int) $xnetvn_agent_hub_wp_blog,
		'base_url'      => 'https://' . $xnetvn_agent_hub_wp_host,
		'base_path'     => $xnetvn_agent_hub_wp_base_path,
		'run_id'        => $xnetvn_agent_hub_wp_run_id,
		'private_state' => $xnetvn_agent_hub_wp_state_file,
	),
	$xnetvn_agent_hub_wp_client,
	$xnetvn_agent_hub_wp_ledger
);
$xnetvn_agent_hub_wp_failed  = false;

/**
 * Time one matrix-owned check.
 *
 * @param array<string,float> $timings Timing accumulator.
 * @param string              $case_id Case id.
 * @param callable            $check Check body.
 * @return bool
 */
$xnetvn_agent_hub_wp_time = static function ( array &$timings, string $case_id, callable $check ): bool {
	$start = hrtime( true );
	try {
		$ok = $check();
	} catch ( \Throwable ) {
		$ok = false;
	}
	$timings[ $case_id ] = ( hrtime( true ) - $start ) / 1000000;
	return $ok;
};

/**
 * Send one JSON-RPC envelope with an explicit bearer (or none).
 *
 * Mirrors the harness envelope and protocol headers without recording.
 *
 * @param McpClient           $client HTTP transport.
 * @param int                 $id Next envelope id.
 * @param string|null         $token Bearer token, null for no credential.
 * @param string              $method MCP method.
 * @param array<string,mixed> $params Method params.
 * @param array<string,string> $headers Extra protocol headers.
 * @return array<string,mixed>
 */
$xnetvn_agent_hub_wp_send = static function ( McpClient $client, int &$id, ?string $token, string $method, array $params = array(), array $headers = array() ) use ( $xnetvn_agent_hub_wp_base_path ): array {
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'role-seo-acceptance',
			'version' => '1',
		),
	);
	$request_headers = array(
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => $method,
	);
	if ( isset( $params['name'] ) && is_string( $params['name'] ) ) {
		$request_headers['Mcp-Name'] = $params['name'];
	}
	foreach ( $headers as $name => $value ) {
		$request_headers[ $name ] = $value;
	}
	return $client->request(
		$xnetvn_agent_hub_wp_base_path . '/wp-json/xnetvn-agent-hub-wp/mcp',
		json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id++,
				'method'  => $method,
				'params'  => $params,
			),
			JSON_THROW_ON_ERROR
		),
		$token,
		$request_headers
	);
};

/**
 * Run one fixed owned fixture action and decode its safe report.
 *
 * @param string $site Target site.
 * @param string $run_id Run id.
 * @param string ...$argv Fixed action arguments.
 * @return array<string,mixed>
 * @throws \RuntimeException For a failed fixture control.
 */
$xnetvn_agent_hub_wp_action = static function ( string $site, string $run_id, string ...$argv ) use ( $xnetvn_agent_hub_wp_host ): array {
	$allowed_actions = array( 'role-swap', 'site-variant', 'key-temp', 'key-drop', 'cookie', 'content-digest', 'ban-verify', 'ban-reset', 'counts' );
	$allowed_subs    = array( 'demote', 'restore', 'ceiling-write', 'ceiling-admin', 'admin-off', 'admin-on', 'rate-high', 'reset' );
	foreach ( $argv as $index => $part ) {
		if ( 0 === $index && ! in_array( $part, $allowed_actions, true ) ) {
			throw new \RuntimeException( 'Invalid fixture action.' );
		}
		if ( 0 < $index && ! in_array( $part, $allowed_subs, true ) ) {
			throw new \RuntimeException( 'Invalid fixture action.' );
		}
	}
	if ( in_array( $site, array( 'network-main', 'network-child' ), true ) ) {
		// The network lab owns one shared fixture prefix and one WP path,
		// so the fixture always runs against the blog URL of this target.
		$blog_url = 'network-child' === $site ? 'https://' . $xnetvn_agent_hub_wp_host . '/ui-second/' : 'https://' . $xnetvn_agent_hub_wp_host . '/';
		$command  = 'WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path=/sites/wpnetwork --url=' . escapeshellarg( $blog_url ) . ' eval-file /work/tests/E2E/role-seo-fixture.php';
	} else {
		$command = 'bash /work/infra/remote/run-wp.sh ' . escapeshellarg( $site ) . ' --url=' . escapeshellarg( 'https://' . getenv( 'wp71' === $site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' ) ) . ' eval-file /work/tests/E2E/role-seo-fixture.php';
	}
	foreach ( $argv as $part ) {
		$command .= ' ' . escapeshellarg( $part );
	}
	$command .= ' --use-include';
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		throw new \RuntimeException( 'Fixture action failed.' );
	}
	$decoded = json_decode( implode( "\n", $output ), true, 32 );
	if ( ! is_array( $decoded ) ) {
		throw new \RuntimeException( 'Fixture action unreadable.' );
	}
	return $decoded;
};

/**
 * Read owned temp tokens and cookie from the private state file.
 *
 * Values stay in local variables; they are never printed or recorded.
 *
 * @param string $state_file Private state path.
 * @return array<string,mixed>
 * @throws \RuntimeException For a missing or foreign state file.
 */
$xnetvn_agent_hub_wp_private = static function ( string $state_file ): array {
	if ( '' === $state_file || is_link( $state_file ) || ! is_file( $state_file ) ) {
		throw new \RuntimeException( 'Role SEO fixture state unavailable.' );
	}
	$state = json_decode( file_get_contents( $state_file ), true, 64, JSON_THROW_ON_ERROR );
	if ( ! is_array( $state ) ) {
		throw new \RuntimeException( 'Role SEO fixture state malformed.' );
	}
	return $state;
};

/**
 * Require the hidden-tool outcome: HTTP 200, isError and the fixed message.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @return bool
 */
$xnetvn_agent_hub_wp_hidden = static function ( array $response ): bool {
	if ( 200 !== ( $response['status'] ?? 0 ) ) {
		return false;
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) || true !== ( $result['isError'] ?? false ) ) {
		return false;
	}
	return 'The tool is unavailable.' === ( $result['content'][0]['text'] ?? null );
};

/**
 * Require a safe denial rendered by the installed adapter without data.
 *
 * Direct policy-hidden calls normalize to the fixed unavailable message;
 * wrapper, ability-info and strict-schema rejections surface as an
 * isError without structured content. Both shapes deny without leaking
 * descriptors, values or credentials.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @return bool
 */
$xnetvn_agent_hub_wp_rendered = static function ( array $response ): bool {
	if ( 200 !== ( $response['status'] ?? 0 ) ) {
		return false;
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) || true !== ( $result['isError'] ?? false ) || isset( $result['structuredContent'] ) ) {
		return false;
	}
	$text = $result['content'][0]['text'] ?? null;
	return is_string( $text ) && str_starts_with( $text, 'internal_error: ' );
};
/**
 * Require a tool denial carrying the allowlisted reason prefix.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @param string              $reason Allowlisted reason.
 * @return bool
 */
$xnetvn_agent_hub_wp_denied = static function ( array $response, string $reason ): bool {
	if ( 200 !== ( $response['status'] ?? 0 ) ) {
		return false;
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) || true !== ( $result['isError'] ?? false ) || isset( $result['structuredContent'] ) ) {
		return false;
	}
	$text = $result['content'][0]['text'] ?? null;
	return is_string( $text ) && str_starts_with( $text, $reason . ': ' );
};

// Setup: temp keys plus the admin cookie; budgets raised so neighboring
// denials never mask one another with rate limits.
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
	$xnetvn_agent_hub_wp_made = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'key-temp' );
	if ( 5 !== ( $xnetvn_agent_hub_wp_made['temp_keys'] ?? 0 ) ) {
		throw new \RuntimeException( 'Role SEO temp keys unavailable.' );
	}
	$xnetvn_agent_hub_wp_cookie_report = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'cookie' );
	if ( true !== ( $xnetvn_agent_hub_wp_cookie_report['cookie'] ?? false ) ) {
		throw new \RuntimeException( 'Role SEO cookie unavailable.' );
	}
	$xnetvn_agent_hub_wp_private_state = $xnetvn_agent_hub_wp_private( $xnetvn_agent_hub_wp_state_file );
	$xnetvn_agent_hub_wp_temp_tokens   = $xnetvn_agent_hub_wp_private_state['temp_tokens'] ?? array();
	$xnetvn_agent_hub_wp_cookie_pair   = $xnetvn_agent_hub_wp_private_state['cookie'] ?? array();
	foreach ( array( 'temp_read', 'temp_contrib_open', 'temp_expired', 'temp_revoked', 'temp_admin' ) as $xnetvn_agent_hub_wp_kind ) {
		if ( ! isset( $xnetvn_agent_hub_wp_temp_tokens[ $xnetvn_agent_hub_wp_kind ] ) || ! is_string( $xnetvn_agent_hub_wp_temp_tokens[ $xnetvn_agent_hub_wp_kind ] ) ) {
			throw new \RuntimeException( 'Role SEO temp token unavailable.' );
		}
	}
	if ( ! isset( $xnetvn_agent_hub_wp_cookie_pair['cookie'], $xnetvn_agent_hub_wp_cookie_pair['nonce'] ) || ! is_string( $xnetvn_agent_hub_wp_cookie_pair['cookie'] ) || ! is_string( $xnetvn_agent_hub_wp_cookie_pair['nonce'] ) ) {
		throw new \RuntimeException( 'Role SEO cookie unavailable.' );
	}
	$xnetvn_agent_hub_wp_envelope = 1000;
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO permissions setup failed.\n";
	exit( 1 );
}
$xnetvn_agent_hub_wp_users = $xnetvn_agent_hub_wp_ledger['users'];

// AUTH-01: a read key hides write tools in listing, direct calls, wrapper and info.
// Direct hidden calls normalize to the fixed unavailable message; the
// installed adapter renders wrapper and ability-info denials as a
// structured isError without data, which still leaks nothing.
$xnetvn_agent_hub_wp_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-01-list',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope ): bool {
		$response = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens['temp_read'], 'tools/list', array() );
		if ( 200 !== ( $response['status'] ?? 0 ) ) {
			return false;
		}
		$names = array_column( $response['body']['result']['tools'] ?? $response['body']['result']['items'] ?? array(), 'name' );
		if ( ! is_array( $names ) || ! in_array( 'xnetvn-get-session-info', $names, true ) ) {
			return false;
		}
		foreach ( array( 'xnetvn-create-post', 'xnetvn-update-post', 'xnetvn-delete-post', 'xnetvn-set-user-status', 'xnetvn-delete-user' ) as $hidden ) {
			if ( in_array( $hidden, $names, true ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-01-list', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-01-direct',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-create-post',
				'arguments' => (object) array( 'title' => 'Denied' ),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-01-direct', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-01-wrapper',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_rendered ): bool {
		$ids = $xnetvn_agent_hub_wp_ledger['ids'] ?? array();
		if ( ! isset( $ids[202] ) || ! is_int( $ids[202] ) ) {
			return false;
		}
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-execute-ability',
				'arguments' => (object) array(
					'name'      => 'xnetvn/update-post',
					'arguments' => (object) array(
						'id'    => $ids[202],
						'title' => 'Denied',
					),
				),
			)
		);
		return $xnetvn_agent_hub_wp_rendered( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-01-wrapper', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-01-info',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_rendered ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-get-ability-info',
				'arguments' => (object) array( 'name' => 'xnetvn/create-post' ),
			)
		);
		return $xnetvn_agent_hub_wp_rendered( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-01-info', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-01',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-01-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-01', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-02: object ownership decides; the wrapper preserves the target denial.
$xnetvn_agent_hub_wp_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-02-own',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_before ): bool {
		$updated = $xnetvn_agent_hub_wp_harness->call(
			'editor',
			'update-post',
			array(
				'id'    => 101,
				'title' => 'Own change',
			)
		);
		if ( 200 !== ( $updated['status'] ?? 0 ) || true === ( $updated['body']['result']['isError'] ?? false ) ) {
			return false;
		}
		$reverted = $xnetvn_agent_hub_wp_harness->call(
			'editor',
			'update-post',
			array(
				'id'    => 101,
				'title' => 'Role SEO ' . getenv( 'XNETVN_ROLE_SEO_RUN_ID' ) . ' 101',
			)
		);
		if ( 200 !== ( $reverted['status'] ?? 0 ) || true === ( $reverted['body']['result']['isError'] ?? false ) ) {
			return false;
		}
		$after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
		return ( $after['content'][101] ?? null ) === ( $xnetvn_agent_hub_wp_before['content'][101] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-02-own', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-02-other',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'update-post',
			array(
				'id'    => 202,
				'title' => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-02-other', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-02-author-other',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'update-post',
			array(
				'id'    => 101,
				'title' => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-02-author-other', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-02-wrapper',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'execute-ability',
			array(
				'name'      => 'xnetvn/update-post',
				'arguments' => array(
					'id'    => 202,
					'title' => 'Denied',
				),
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-02-wrapper', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-02',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-02-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return ( $xnetvn_agent_hub_wp_before['content'][202] ?? null ) === ( $xnetvn_agent_hub_wp_after['content'][202] ?? null )
			&& ( $xnetvn_agent_hub_wp_before['content'][101] ?? null ) === ( $xnetvn_agent_hub_wp_after['content'][101] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-02', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-03: weak roles cannot publish, upload or enter without use.
// Contributor publish is denied by core as forbidden; contributor future
// is denied by the installed adapter as a structured isError without
// data. Both shapes create nothing, proven by digests and counts.
$xnetvn_agent_hub_wp_before        = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok            = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-03-publish',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_denied ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'tools/call',
			array(
				'name'      => 'xnetvn-create-post',
				'arguments' => (object) array(
					'title'   => 'Denied',
					'content' => 'Denied',
					'status'  => 'publish',
				),
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-03-publish', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-03-future',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_rendered ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'tools/call',
			array(
				'name'      => 'xnetvn-create-post',
				'arguments' => (object) array(
					'title'   => 'Denied',
					'content' => 'Denied',
					'status'  => 'future',
					'date'    => gmdate( 'Y-m-d H:i:s', time() + 172800 ),
				),
			)
		);
		return $xnetvn_agent_hub_wp_rendered( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-03-future', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-03-media',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call( 'contributor', 'upload-media-from-data', array() );
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-03-media', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-03-no-use',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		$subscriber = $xnetvn_agent_hub_wp_harness->call( 'subscriber', 'get-session-info', array() );
		$banned     = $xnetvn_agent_hub_wp_harness->call( 'banned', 'get-session-info', array() );
		return 403 === ( $subscriber['status'] ?? 0 ) && 403 === ( $banned['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-03-no-use', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-03',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after, $xnetvn_agent_hub_wp_counts_before, $xnetvn_agent_hub_wp_counts ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-03-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		if ( $xnetvn_agent_hub_wp_before !== $xnetvn_agent_hub_wp_after ) {
			return false;
		}
		foreach ( array( 'users', 'keys', 'posts', 'terms', 'media' ) as $kind ) {
			if ( ( $xnetvn_agent_hub_wp_counts_before[ $kind ] ?? null ) !== ( $xnetvn_agent_hub_wp_counts[ $kind ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-03', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-04: every restriction dimension denies on its own policy term.
$xnetvn_agent_hub_wp_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-toolset',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'contributor',
			'create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-toolset', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-allow',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden ): bool {
		$positive = $xnetvn_agent_hub_wp_harness->call( 'author_b', 'get-session-info', array() );
		if ( 200 !== ( $positive['status'] ?? 0 ) ) {
			return false;
		}
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author_b',
			'update-post',
			array(
				'id'    => 101,
				'title' => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-allow', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-deny',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call( 'author', 'delete-post', array( 'id' => 101 ) );
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-deny', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-ceiling',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_hidden ): bool {
		try {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'ceiling-write' );
			$response = $xnetvn_agent_hub_wp_harness->call( 'editor', 'delete-post', array( 'id' => 202 ) );
			return $xnetvn_agent_hub_wp_hidden( $response );
		} finally {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
		}
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-ceiling', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-admin-off',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_hidden ): bool {
		try {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'ceiling-admin' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'admin-off' );
			$response = $xnetvn_agent_hub_wp_send(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_temp_tokens['temp_admin'],
				'tools/call',
				array(
					'name'      => 'xnetvn-set-user-status',
					'arguments' => (object) array(
						'id'          => $xnetvn_agent_hub_wp_users['author'],
						'status'      => 'banned',
						'reason_code' => 'policy',
					),
				)
			);
			return $xnetvn_agent_hub_wp_hidden( $response );
		} finally {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
		}
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-admin-off', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04-hidden',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_rendered ): bool {
		$list = $xnetvn_agent_hub_wp_harness->request( 'author', 'tools/list', array() );
		if ( 200 !== ( $list['status'] ?? 0 ) ) {
			return false;
		}
		$names = array_column( $list['body']['result']['tools'] ?? $list['body']['result']['items'] ?? array(), 'name' );
		if ( ! is_array( $names ) || in_array( 'xnetvn-delete-user', $names, true ) || in_array( 'xnetvn-set-user-status', $names, true ) ) {
			return false;
		}
		$unknown = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-no-such-tool',
				'arguments' => (object) array(),
			)
		);
		if ( ! $xnetvn_agent_hub_wp_hidden( $unknown ) ) {
			return false;
		}
		$info = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-get-ability-info',
				'arguments' => (object) array( 'name' => 'xnetvn/delete-user' ),
			)
		);
		return $xnetvn_agent_hub_wp_rendered( $info );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04-hidden', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-04',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-04-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-04', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-05: a demoted token stops at 403 and works again after restore.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-05-demote',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		$positive = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		if ( 200 !== ( $positive['status'] ?? 0 ) ) {
			return false;
		}
		try {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'role-swap', 'demote' );
			$session = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
			$update  = $xnetvn_agent_hub_wp_harness->call(
				'author',
				'update-post',
				array(
					'id'    => 101,
					'title' => 'Denied',
				)
			);
			return 403 === ( $session['status'] ?? 0 ) && 403 === ( $update['status'] ?? 0 );
		} finally {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'role-swap', 'restore' );
		}
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-05-demote', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-05-restore',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$session = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		if ( 200 !== ( $session['status'] ?? 0 ) ) {
			return false;
		}
		$update = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'update-post',
			array(
				'id'    => 101,
				'title' => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_denied( $update, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-05-restore', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-05',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( array( 'AUTH-05-demote', 'AUTH-05-restore' ) as $id ) {
			$cases = $xnetvn_agent_hub_wp_harness->cases();
			if ( true !== ( $cases[ $id ]['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-05', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-06: a real MCP ban ends sessions and tokens; unban restores the author.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-06-ban',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_users ): bool {
		$positive = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		if ( 200 !== ( $positive['status'] ?? 0 ) ) {
			return false;
		}
		try {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'ceiling-admin' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'admin-on' );
			$ban = $xnetvn_agent_hub_wp_send(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_temp_tokens['temp_admin'],
				'tools/call',
				array(
					'name'      => 'xnetvn-set-user-status',
					'arguments' => (object) array(
						'id'          => $xnetvn_agent_hub_wp_users['author'],
						'status'      => 'banned',
						'reason_code' => 'policy',
					),
				)
			);
			if ( 200 !== ( $ban['status'] ?? 0 ) || 'banned' !== ( $ban['body']['result']['structuredContent']['status'] ?? null ) ) {
				return false;
			}
			$verify = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'ban-verify' );
			if ( array( 'banned' ) !== ( $verify['roles'] ?? null ) || true !== ( $verify['caps_empty'] ?? null ) || 0 !== ( $verify['sessions'] ?? -1 ) || false !== ( $verify['use'] ?? null ) ) {
				return false;
			}
			$denied = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
			return 403 === ( $denied['status'] ?? 0 );
		} finally {
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'ceiling-admin' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'admin-on' );
			$unban = $xnetvn_agent_hub_wp_send(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_temp_tokens['temp_admin'],
				'tools/call',
				array(
					'name'      => 'xnetvn-set-user-status',
					'arguments' => (object) array(
						'id'     => $xnetvn_agent_hub_wp_users['author'],
						'status' => 'active',
					),
				)
			);
			if ( 200 !== ( $unban['status'] ?? 0 ) ) {
				throw new \RuntimeException( 'Role SEO unban failed.' );
			}
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
			$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
		}
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-06-ban', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-06-unban',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_users ): bool {
		$session = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		if ( 200 !== ( $session['status'] ?? 0 ) || ( $session['body']['result']['structuredContent']['user']['id'] ?? null ) !== $xnetvn_agent_hub_wp_users['author'] ) {
			return false;
		}
		$verify = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'ban-verify' );
		return array( 'author' ) === ( $verify['roles'] ?? null ) && true !== ( $verify['caps_empty'] ?? null ) && true === ( $verify['use'] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-06-unban', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-06',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( array( 'AUTH-06-ban', 'AUTH-06-unban' ) as $id ) {
			$cases = $xnetvn_agent_hub_wp_harness->cases();
			if ( true !== ( $cases[ $id ]['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-06', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// Harness hygiene after the ban defect: the MCP unban path currently
// answers invalid_state (RoleGuard::assignable is false without
// get_editable_roles in REST context), so the owned author is restored
// directly to keep later cases on a known state. The AUTH-06-unban
// failure above stays as the defect evidence for a separate product
// issue; this step never masks it.
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'ban-reset' );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}

// AUTH-07: credential shapes map to 401 with a challenge; cookie never substitutes.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-missing',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope ): bool {
		$response  = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, null, 'tools/list', array() );
		$challenge = $response['headers']['www-authenticate'] ?? '';
		return 401 === ( $response['status'] ?? 0 ) && str_contains( $challenge, 'Bearer' ) && ! str_contains( $challenge, 'invalid_token' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-missing', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-malformed',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope ): bool {
		$response  = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, 'malformed', 'tools/list', array() );
		$challenge = $response['headers']['www-authenticate'] ?? '';
		return 401 === ( $response['status'] ?? 0 ) && str_contains( $challenge, 'invalid_token' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-malformed', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-expired',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens ): bool {
		$expired = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens['temp_expired'], 'tools/list', array() );
		return 401 === ( $expired['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-expired', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-revoked',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens ): bool {
		$revoked = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens['temp_revoked'], 'tools/list', array() );
		return 401 === ( $revoked['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-revoked', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-unknown',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens ): bool {
		$unknown = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, 'xnv_aaaaaaaaaaaa_' . str_repeat( 'A', 43 ), 'tools/list', array() );
		$bad     = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, substr( $xnetvn_agent_hub_wp_temp_tokens['temp_expired'], 0, 17 ) . str_repeat( 'A', 43 ), 'tools/list', array() );
		return 401 === ( $unknown['status'] ?? 0 ) && 401 === ( $bad['status'] ?? 0 ) && $unknown['body'] === $bad['body'];
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-unknown', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-bad',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens ): bool {
		$tampered = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, substr( $xnetvn_agent_hub_wp_temp_tokens['temp_read'], 0, 17 ) . str_repeat( 'A', 43 ), 'tools/list', array() );
		$revoked  = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens['temp_revoked'], 'tools/list', array() );
		return 401 === ( $tampered['status'] ?? 0 ) && 401 === ( $revoked['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-bad', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-cookie',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_cookie_pair ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			null,
			'tools/list',
			array(),
			array(
				'Cookie'     => $xnetvn_agent_hub_wp_cookie_pair['cookie'],
				'X-WP-Nonce' => $xnetvn_agent_hub_wp_cookie_pair['nonce'],
			)
		);
		return 401 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-cookie', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07-cookie-bearer',
	static function () use ( $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_cookie_pair, $xnetvn_agent_hub_wp_denied ): bool {
		$tokens = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$ids    = $xnetvn_agent_hub_wp_ledger['ids'] ?? array();
		if ( ! isset( $tokens['author'] ) || ! is_string( $tokens['author'] ) || ! isset( $ids[202] ) || ! is_int( $ids[202] ) ) {
			return false;
		}
		$mixed = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$tokens['author'],
			'tools/call',
			array(
				'name'      => 'xnetvn-get-session-info',
				'arguments' => (object) array(),
			),
			array(
				'Cookie'     => $xnetvn_agent_hub_wp_cookie_pair['cookie'],
				'X-WP-Nonce' => $xnetvn_agent_hub_wp_cookie_pair['nonce'],
			)
		);
		if ( 200 !== ( $mixed['status'] ?? 0 ) || ( $mixed['body']['result']['structuredContent']['user']['id'] ?? null ) !== $xnetvn_agent_hub_wp_users['author'] ) {
			return false;
		}
		$denied = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$tokens['author'],
			'tools/call',
			array(
				'name'      => 'xnetvn-update-post',
				'arguments' => (object) array(
					'id'    => $ids[202],
					'title' => 'Denied',
				),
			),
			array(
				'Cookie'     => $xnetvn_agent_hub_wp_cookie_pair['cookie'],
				'X-WP-Nonce' => $xnetvn_agent_hub_wp_cookie_pair['nonce'],
			)
		);
		return $xnetvn_agent_hub_wp_denied( $denied, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07-cookie-bearer', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-07',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-07-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-07', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-08: only the exact MCP route honors key auth; management needs cookie+nonce.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-08-exact',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		return 200 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-08-exact', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-08-suffix',
	static function () use ( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_host, $xnetvn_agent_hub_wp_base_path ): bool {
		$response = $xnetvn_agent_hub_wp_client->request(
			$xnetvn_agent_hub_wp_base_path . '/wp-json/xnetvn-agent-hub-wp/mcpx',
			json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
					'params'  => array(),
				),
				JSON_THROW_ON_ERROR
			),
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/list',
			)
		);
		if ( 404 !== ( $response['status'] ?? 0 ) ) {
			return false;
		}
		$encoded = json_encode( $response['body'] );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		foreach ( $xnetvn_agent_hub_wp_temp_tokens as $token ) {
			if ( is_string( $token ) && '' !== $token && str_contains( $encoded, $token ) ) {
				return false;
			}
		}
		return ! str_contains( $encoded, 'structuredContent' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-08-suffix', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-08-management',
	static function () use ( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_base_path ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$token    = ( isset( $tokens['author'] ) && is_string( $tokens['author'] ) ) ? $tokens['author'] : null;
		$response = $xnetvn_agent_hub_wp_client->request(
			$xnetvn_agent_hub_wp_base_path . '/wp-json/xnetvn-agent-hub-wp/v1/keys',
			json_encode( array( 'name' => 'Role SEO management probe' ), JSON_THROW_ON_ERROR ),
			$token,
			array()
		);
		if ( 401 !== ( $response['status'] ?? 0 ) || isset( $response['body']['result']['structuredContent'] ) ) {
			return false;
		}
		return 'xnetvn_agent_hub_wp_cookie_required' === ( $response['body']['code'] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-08-management', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-08',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( array( 'AUTH-08-exact', 'AUTH-08-suffix', 'AUTH-08-management' ) as $id ) {
			$cases = $xnetvn_agent_hub_wp_harness->cases();
			if ( true !== ( $cases[ $id ]['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-08', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-09: origin, TLS and size guards refuse before any mutation.
$xnetvn_agent_hub_wp_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-positive',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-session-info', array() );
		return 200 === ( $response['status'] ?? 0 ) && true !== ( $response['body']['result']['isError'] ?? false );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-positive', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-null',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_ledger ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$response = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null, 'tools/list', array(), array( 'Origin' => 'null' ) );
		return 403 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-null', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-unlisted',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_ledger ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$response = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null, 'tools/list', array(), array( 'Origin' => 'https://unlisted.example.test' ) );
		return 403 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-unlisted', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-allowed',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_host ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$response = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null, 'tools/list', array(), array( 'Origin' => 'https://' . $xnetvn_agent_hub_wp_host ) );
		return 200 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-allowed', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-plain',
	static function () use ( $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_host, $xnetvn_agent_hub_wp_base_path, $xnetvn_agent_hub_wp_network ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$envelope = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(),
			),
			JSON_THROW_ON_ERROR
		);
		if ( $xnetvn_agent_hub_wp_network ) {
			// The network lab serves the MCP route on its registered
			// domain only, so the plain-HTTP probe addresses the compose
			// service name and carries the canonical Host header and the
			// path of this blog.
			$plain    = new McpClient( 'http://wpnetwork', array( 'wpnetwork' ) );
			$response = $plain->request(
				$xnetvn_agent_hub_wp_base_path . '/wp-json/xnetvn-agent-hub-wp/mcp',
				$envelope,
				is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null,
				array(
					'MCP-Protocol-Version' => '2026-07-28',
					'Mcp-Method'           => 'tools/list',
					'Host'                 => $xnetvn_agent_hub_wp_host,
				)
			);
			return 403 === ( $response['status'] ?? 0 );
		}
		$plain    = new McpClient( 'http://' . $xnetvn_agent_hub_wp_site, array( $xnetvn_agent_hub_wp_site ) );
		$response = $plain->request(
			'/wp-json/xnetvn-agent-hub-wp/mcp',
			$envelope,
			is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null,
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/list',
			)
		);
		return 403 === ( $response['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-plain', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09-oversize',
	static function () use ( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_host, $xnetvn_agent_hub_wp_base_path ): bool {
		$tokens   = $xnetvn_agent_hub_wp_ledger['tokens'] ?? array();
		$response = $xnetvn_agent_hub_wp_client->request(
			$xnetvn_agent_hub_wp_base_path . '/wp-json/xnetvn-agent-hub-wp/mcp',
			str_repeat( 'X', 16777217 ),
			is_string( $tokens['author'] ?? null ) ? $tokens['author'] : null,
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => 'xnetvn-update-post',
			)
		);
		if ( 413 !== ( $response['status'] ?? 0 ) ) {
			return false;
		}
		return 'xnetvn_agent_hub_wp_payload_limit' === ( $response['body']['code'] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09-oversize', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-09',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'AUTH-09-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-09', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// AUTH-10: schema and unknown tools fail closed without leaking secrets.
// The installed adapter renders strict-schema violations as a structured
// isError without data; that shape must never be confused with a
// permission PASS, and the report must carry no secret or canary.
$xnetvn_agent_hub_wp_canaries      = array( 'AUTH10-CANARY-TITLE', 'AUTH10-CANARY-ARG', 'AUTH10-CANARY-COOKIE', 'AUTH10-CANARY-HASH' );
$xnetvn_agent_hub_wp_before        = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok            = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-10-schema',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_rendered ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'update-post',
			array(
				'id'      => 101,
				'unknown' => 'AUTH10-CANARY-ARG',
			)
		);
		if ( $xnetvn_agent_hub_wp_denied( $response, 'forbidden' ) ) {
			return false;
		}
		return $xnetvn_agent_hub_wp_rendered( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-10-schema', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-10-unknown',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-no-such-tool',
				'arguments' => (object) array(),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-10-unknown', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-10-canary',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_temp_tokens, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_denied ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'tools/call',
			array(
				'name'      => 'xnetvn-create-post',
				'arguments' => (object) array(
					'title'   => 'AUTH10-CANARY-TITLE',
					'content' => 'Denied',
					'status'  => 'publish',
				),
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-10-canary', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed       = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after        = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts_after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok           = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-10-report',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after, $xnetvn_agent_hub_wp_canaries, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_cookie_pair, $xnetvn_agent_hub_wp_counts_before, $xnetvn_agent_hub_wp_counts_after ): bool {
		if ( $xnetvn_agent_hub_wp_before !== $xnetvn_agent_hub_wp_after ) {
			return false;
		}
		foreach ( array( 'users', 'keys', 'posts', 'terms', 'media' ) as $kind ) {
			if ( ( $xnetvn_agent_hub_wp_counts_before[ $kind ] ?? null ) !== ( $xnetvn_agent_hub_wp_counts_after[ $kind ] ?? null ) ) {
				return false;
			}
		}
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( ( str_starts_with( $id, 'AUTH-10-' ) && 'AUTH-10-report' !== $id ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		$report_json = json_encode( $xnetvn_agent_hub_wp_harness->cases(), JSON_THROW_ON_ERROR );
		foreach ( $xnetvn_agent_hub_wp_canaries as $canary ) {
			if ( str_contains( $report_json, $canary ) ) {
				return false;
			}
		}
		foreach ( $xnetvn_agent_hub_wp_temp_tokens as $token ) {
			if ( is_string( $token ) && '' !== $token && str_contains( $report_json, $token ) ) {
				return false;
			}
		}
		foreach ( $xnetvn_agent_hub_wp_cookie_pair as $value ) {
			if ( is_string( $value ) && '' !== $value && str_contains( $report_json, $value ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-10-report', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'AUTH-10',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( array( 'AUTH-10-schema', 'AUTH-10-unknown', 'AUTH-10-canary', 'AUTH-10-report' ) as $id ) {
			$cases = $xnetvn_agent_hub_wp_harness->cases();
			if ( true !== ( $cases[ $id ]['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'AUTH-10', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// Teardown: drop temp keys and restore site settings; the runner owns final cleanup.
try {
	$xnetvn_agent_hub_wp_dropped = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'key-drop' );
	if ( 5 !== ( $xnetvn_agent_hub_wp_dropped['dropped'] ?? -1 ) ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}

$xnetvn_agent_hub_wp_report = array(
	'suite'     => 'role-seo-permissions',
	'target'    => $xnetvn_agent_hub_wp_site,
	'run_id'    => $xnetvn_agent_hub_wp_run_id,
	'blog'      => (int) $xnetvn_agent_hub_wp_blog,
	'cases'     => $xnetvn_agent_hub_wp_harness->cases(),
	'declared'  => xnetvn_agent_hub_wp_role_seo_permissions_declare_cases(),
	'counts'    => array(
		'actors'     => count( $xnetvn_agent_hub_wp_ledger['users'] ),
		'ledger_ids' => count( $xnetvn_agent_hub_wp_ledger['ids'] ),
	),
	'timing_ms' => $xnetvn_agent_hub_wp_timings,
	'cleanup'   => false,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
echo json_encode( $xnetvn_agent_hub_wp_report, JSON_THROW_ON_ERROR ) . "\n";
exit( $xnetvn_agent_hub_wp_failed ? 1 : 0 );

/**
 * Record a matrix-owned case without raw values.
 *
 * @param RoleSeoHarness $harness Harness.
 * @param string         $case_id Case id.
 * @param bool           $ok Outcome.
 * @param string         $reason Allowlisted reason.
 */
function xnetvn_agent_hub_wp_role_seo_record( RoleSeoHarness $harness, string $case_id, bool $ok, string $reason = 'unavailable' ): void {
	$harness->recordCase( $case_id, $ok, $reason );
}
