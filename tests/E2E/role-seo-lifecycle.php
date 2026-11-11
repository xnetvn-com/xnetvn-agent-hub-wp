<?php
/**
 * Owned role/SEO lifecycle matrix: LIFE-01 through LIFE-09 declarations and
 * the MCP-level cases executed through real HTTPS.
 *
 * Each LIFE row from the ticket is a separate recorded case (multiplied by
 * role/timezone variant where the ticket requires it). Reports carry only
 * case ids, allowlisted reasons, counts, digests and boolean checks; no
 * input, response bodies, cookies, bearers, hashes or argument values are
 * printed. Posts created by this matrix are deleted in teardown before the
 * report so the runner counts return to baseline. Teardown deletes only
 * tracked ledger-owned ids, never by query or prefix.
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

// All instant comparisons use offset-carrying strings, so no default
// timezone override is needed for deterministic parsing.

/**
 * Declare the nine lifecycle cases with their owner and applicability.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_lifecycle_declare_cases(): array {
	return array(
		array(
			'id'           => 'LIFE-01',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'transition', 'denied' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-02',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'cross-owner', 'published-guard' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-03',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'utc', 'ict', 'invalid' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-04',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'cron-due-utc', 'cron-due-ict' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-05',
			'owner'        => 'matrix',
			'roles'        => array( 'editor', 'author' ),
			'scope_modes'  => array( 'write', 'destructive', 'read' ),
			'variants'     => array( 'trash', 'restore', 'delete', 'invalid' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-06',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write', 'read' ),
			'variants'     => array( 'duplicate', 'hidden', 'missing' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-07',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'binding', 'swap-denied', 'restore' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-08',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'conflict', 'fresh', 'concurrent' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'LIFE-09',
			'owner'        => 'matrix',
			'roles'        => array( 'author', 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'bulk', 'partial-create', 'partial-duplicate' ),
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
// Owned tool-call log; proves reconcile paths never issue a second mutation.
$xnetvn_agent_hub_wp_call_log = array();

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
 * Call one tool with already-resolved real ids (no ledger band involved).
 *
 * Real owned ids may fall inside the ledger band, so they travel through
 * this direct path instead of ledger resolution. Every call is logged so
 * reconcile paths can prove they issue no second mutation.
 *
 * @param McpClient           $client HTTP transport.
 * @param int                 $id Next envelope id.
 * @param string              $token Bearer token.
 * @param string              $tool Tool suffix or full xnetvn- name.
 * @param array<string,mixed> $args Tool arguments with real ids.
 * @return array<string,mixed>
 */
$xnetvn_agent_hub_wp_tool = static function ( McpClient $client, int &$id, string $token, string $tool, array $args = array() ) use ( $xnetvn_agent_hub_wp_send, &$xnetvn_agent_hub_wp_call_log ): array {
	$name                           = str_starts_with( $tool, 'xnetvn-' ) ? $tool : 'xnetvn-' . $tool;
	$xnetvn_agent_hub_wp_call_log[] = $name;
	return $xnetvn_agent_hub_wp_send(
		$client,
		$id,
		$token,
		'tools/call',
		array(
			'name'      => $name,
			'arguments' => (object) $args,
		)
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
	$allowed_actions = array( 'site-variant', 'key-temp', 'key-drop', 'content-digest', 'counts', 'timezone', 'cron-due', 'lifecycle-fault' );
	$allowed_subs    = array( 'rate-high', 'reset', 'UTC', 'Asia/Ho_Chi_Minh', 'restore', 'prepare-fail-once', 'none' );
	foreach ( $argv as $index => $part ) {
		if ( 0 === $index && ! in_array( $part, $allowed_actions, true ) ) {
			throw new \RuntimeException( 'Invalid fixture action.' );
		}
		if ( 0 < $index && ! in_array( $part, $allowed_subs, true ) && ! ( 'cron-due' === $argv[0] && 1 === $index && 1 === preg_match( '/\A[1-9][0-9]*\z/', $part ) ) ) {
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
 * Read owned temp tokens from the private state file.
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
 * Policy-hidden wrappers and strict-schema rejections surface as an
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

/**
 * Require a successful structured result without raw values.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @return array<string,mixed>|null Structured content or null.
 */
$xnetvn_agent_hub_wp_success = static function ( array $response ): ?array {
	if ( 200 !== ( $response['status'] ?? 0 ) ) {
		return null;
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) || true === ( $result['isError'] ?? false ) ) {
		return null;
	}
	$data = $result['structuredContent'] ?? null;
	return is_array( $data ) ? $data : null;
};

/**
 * Compare entity totals of two fixture counts, ignoring digest noise.
 *
 * Cron and settings digests drift from platform activity; ownership and
 * resource totals are the stable denial signal.
 *
 * @param array<string,mixed> $before Counts before.
 * @param array<string,mixed> $after Counts after.
 * @return bool
 */
$xnetvn_agent_hub_wp_counts_equal = static function ( array $before, array $after ): bool {
	foreach ( array( 'users', 'keys', 'posts', 'terms', 'media' ) as $kind ) {
		if ( ( $before[ $kind ] ?? null ) !== ( $after[ $kind ] ?? null ) ) {
			return false;
		}
	}
	return true;
};

/**
 * Normalize an optimistic token for the strict schema wire shape.
 *
 * DTO datetimes carry a Z suffix already; raw core values without one gain
 * it so the comparison sees the same instant the DTO reported.
 *
 * @param string $token Reported modified_gmt value.
 * @return string Schema-valid token for the same instant.
 */
$xnetvn_agent_hub_wp_token = static function ( string $token ): string {
	return str_ends_with( $token, 'Z' ) ? $token : $token . 'Z';
};

// Setup: temp keys for the contributor/read paths; budgets raised so
// neighboring denials never mask one another with rate limits.
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
	$xnetvn_agent_hub_wp_made = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'key-temp' );
	if ( 5 !== ( $xnetvn_agent_hub_wp_made['temp_keys'] ?? 0 ) ) {
		throw new \RuntimeException( 'Role SEO temp keys unavailable.' );
	}
	$xnetvn_agent_hub_wp_private_state = $xnetvn_agent_hub_wp_private( $xnetvn_agent_hub_wp_state_file );
	$xnetvn_agent_hub_wp_temp_tokens   = $xnetvn_agent_hub_wp_private_state['temp_tokens'] ?? array();
	if ( ! isset( $xnetvn_agent_hub_wp_temp_tokens['temp_read'] ) || ! is_string( $xnetvn_agent_hub_wp_temp_tokens['temp_read'] ) ) {
		throw new \RuntimeException( 'Role SEO temp token unavailable.' );
	}
	$xnetvn_agent_hub_wp_envelope = 2000;
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO lifecycle setup failed.\n";
	exit( 1 );
}
$xnetvn_agent_hub_wp_users         = $xnetvn_agent_hub_wp_ledger['users'];
$xnetvn_agent_hub_wp_tokens        = $xnetvn_agent_hub_wp_ledger['tokens'];
$xnetvn_agent_hub_wp_ids           = $xnetvn_agent_hub_wp_ledger['ids'];
$xnetvn_agent_hub_wp_before        = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
// Owned ids created by this matrix for teardown; never printed.
$xnetvn_agent_hub_wp_owned_posts = array();

// LIFE-01: every publish-capable role walks its own post through the
// draft/pending/publish/private/draft cycle; the contributor reaches pending
// but publish, private and future stay forbidden without the publish cap.
foreach ( array( 'admin', 'editor', 'author' ) as $xnetvn_agent_hub_wp_role ) {
	$xnetvn_agent_hub_wp_post_id = 0;
	$xnetvn_agent_hub_wp_ok      = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-01-transition-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_users, &$xnetvn_agent_hub_wp_post_id, &$xnetvn_agent_hub_wp_owned_posts ): bool {
			$created = $xnetvn_agent_hub_wp_harness->call(
				$xnetvn_agent_hub_wp_role,
				'create-post',
				array(
					'title'   => 'Lifecycle sample',
					'excerpt' => 'Lifecycle excerpt.',
				)
			);
			$data    = $xnetvn_agent_hub_wp_success( $created );
			if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || 'draft' !== ( $data['status'] ?? '' ) || (int) ( $data['author'] ?? 0 ) !== (int) ( $xnetvn_agent_hub_wp_users[ $xnetvn_agent_hub_wp_role ] ?? 0 ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_post_id       = (int) $data['id'];
			$xnetvn_agent_hub_wp_owned_posts[] = $xnetvn_agent_hub_wp_post_id;
			foreach ( array( 'pending', 'publish', 'private', 'draft' ) as $status ) {
				$updated = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
					'update-post',
					array(
						'id'     => $xnetvn_agent_hub_wp_post_id,
						'status' => $status,
					)
				);
				$next    = $xnetvn_agent_hub_wp_success( $updated );
				if ( ! is_array( $next ) || ( $next['status'] ?? '' ) !== $status || (int) ( $next['author'] ?? 0 ) !== (int) ( $xnetvn_agent_hub_wp_users[ $xnetvn_agent_hub_wp_role ] ?? 0 ) ) {
					return false;
				}
				$read      = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
					'get-post',
					array( 'id' => $xnetvn_agent_hub_wp_post_id )
				);
				$read_data = $xnetvn_agent_hub_wp_success( $read );
				if ( ! is_array( $read_data ) || ( $read_data['status'] ?? '' ) !== $status ) {
					return false;
				}
			}
			return true;
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-01-transition-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_role );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-01-transition-contributor',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		// The ledger contributor key denies create-post, so the open temp
		// key carries every contributor step; the owner stays the
		// contributor user either way.
		$created = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'create-post',
			array( 'title' => 'Contributor lifecycle' )
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || (int) ( $data['author'] ?? 0 ) !== (int) ( $xnetvn_agent_hub_wp_users['contributor'] ?? 0 ) ) {
			return false;
		}
		$id                                = (int) $data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $id;
		$pending                           = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'update-post',
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);
		$pending_data                      = $xnetvn_agent_hub_wp_success( $pending );
		if ( ! is_array( $pending_data ) || 'pending' !== ( $pending_data['status'] ?? '' ) ) {
			return false;
		}
		foreach ( array( 'publish', 'private' ) as $denied_status ) {
			$response = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
				'update-post',
				array(
					'id'     => $id,
					'status' => $denied_status,
				)
			);
			if ( ! $xnetvn_agent_hub_wp_denied( $response, 'forbidden' ) ) {
				return false;
			}
		}
		$future = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'update-post',
			array(
				'id'     => $id,
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 ),
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $future, 'forbidden' ) ) {
			return false;
		}
		$read      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'get-post',
			array( 'id' => $id )
		);
		$read_data = $xnetvn_agent_hub_wp_success( $read );
		return is_array( $read_data ) && 'pending' === ( $read_data['status'] ?? '' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-01-transition-contributor', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-01',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-01-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-01', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-02: admins and editors may edit another author's published post and
// restore it; authors and contributors without the matching cap are refused
// and the published post is untouched. An author editing their own
// published post stays the positive control.
$xnetvn_agent_hub_wp_before_02 = $xnetvn_agent_hub_wp_harness->call(
	'editor',
	'get-post',
	array( 'id' => 202 )
);
$xnetvn_agent_hub_wp_title_202 = $xnetvn_agent_hub_wp_success( $xnetvn_agent_hub_wp_before_02 )['title'] ?? null;
if ( ! is_string( $xnetvn_agent_hub_wp_title_202 ) ) {
	$xnetvn_agent_hub_wp_failed = true;
}
foreach ( array( 'admin', 'editor' ) as $xnetvn_agent_hub_wp_role ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-02-cross-owner-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_title_202, $xnetvn_agent_hub_wp_run_id ): bool {
			$marked      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'update-post',
				array(
					'id'    => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 202 ) )['id'],
					'title' => 'Owned edit sample',
				)
			);
			$marked_data = $xnetvn_agent_hub_wp_success( $marked );
			if ( ! is_array( $marked_data ) || 'Owned edit sample' !== ( $marked_data['title'] ?? null ) ) {
				return false;
			}
			$reverted      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'update-post',
				array(
					'id'    => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 202 ) )['id'],
					'title' => $xnetvn_agent_hub_wp_title_202,
				)
			);
			$reverted_data = $xnetvn_agent_hub_wp_success( $reverted );
			return is_array( $reverted_data ) && ( $reverted_data['title'] ?? null ) === $xnetvn_agent_hub_wp_title_202;
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-02-cross-owner-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_role );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-02-cross-owner-author',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_title_202 ): bool {
		$response = $xnetvn_agent_hub_wp_harness->call(
			'author',
			'update-post',
			array(
				'id'    => 202,
				'title' => 'Denied edit',
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $response, 'forbidden' ) ) {
			return false;
		}
		$read      = $xnetvn_agent_hub_wp_harness->call( 'editor', 'get-post', array( 'id' => 202 ) );
		$read_data = $read['body']['result']['structuredContent'] ?? null;
		return is_array( $read_data ) && ( $read_data['title'] ?? null ) === $xnetvn_agent_hub_wp_title_202 && 'draft' === ( $read_data['status'] ?? '' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-02-cross-owner-author', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-02-published-guard',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$created = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'create-post',
			array( 'title' => 'Contributor publish guard' )
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$id                                = (int) $data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $id;
		$published                         = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'update-post',
			array(
				'id'     => $id,
				'status' => 'publish',
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $published ) ) ) {
			return false;
		}
		$locked = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'update-post',
			array(
				'id'    => $id,
				'title' => 'Denied published edit',
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $locked, 'forbidden' ) ) {
			return false;
		}
		$read      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'get-post',
			array( 'id' => $id )
		);
		$read_data = $xnetvn_agent_hub_wp_success( $read );
		if ( ! is_array( $read_data ) || 'publish' !== ( $read_data['status'] ?? '' ) ) {
			return false;
		}
		$own      = $xnetvn_agent_hub_wp_harness->call( 'author', 'create-post', array( 'title' => 'Author published control' ) );
		$own_data = $xnetvn_agent_hub_wp_success( $own );
		if ( ! is_array( $own_data ) || 1 > (int) ( $own_data['id'] ?? 0 ) ) {
			return false;
		}
		$own_id                            = (int) $own_data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $own_id;
		$own_published                     = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'update-post',
			array(
				'id'     => $own_id,
				'status' => 'publish',
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $own_published ) ) ) {
			return false;
		}
		$own_edited      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'update-post',
			array(
				'id'    => $own_id,
				'title' => 'Author published edit',
			)
		);
		$own_edited_data = $xnetvn_agent_hub_wp_success( $own_edited );
		return is_array( $own_edited_data ) && 'Author published edit' === ( $own_edited_data['title'] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-02-published-guard', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-02',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-02-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-02', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-03: future posts carry an explicit-offset date in both the UTC and
// Asia/Ho_Chi_Minh site timezones; the DTO date_gmt always resolves to the
// same UTC instant that was sent. Missing, offsetless and past dates are
// refused by the schema or the product without writing anything.
foreach ( array( 'UTC', 'Asia/Ho_Chi_Minh' ) as $xnetvn_agent_hub_wp_zone ) {
	$xnetvn_agent_hub_wp_variant = 'UTC' === $xnetvn_agent_hub_wp_zone ? 'utc' : 'ict';
	try {
		$xnetvn_agent_hub_wp_zoned = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'timezone', $xnetvn_agent_hub_wp_zone );
		if ( ( $xnetvn_agent_hub_wp_zoned['zone'] ?? '' ) !== $xnetvn_agent_hub_wp_zone ) {
			throw new \RuntimeException( 'Role SEO timezone unavailable.' );
		}
	} catch ( \Throwable ) {
		xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-03-future-' . $xnetvn_agent_hub_wp_variant, false );
		$xnetvn_agent_hub_wp_failed = true;
		continue;
	}
	$xnetvn_agent_hub_wp_future_id = 0;
	$xnetvn_agent_hub_wp_ok        = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-03-future-' . $xnetvn_agent_hub_wp_variant,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_zone, &$xnetvn_agent_hub_wp_future_id, &$xnetvn_agent_hub_wp_owned_posts ): bool {
			$input   = 'UTC' === $xnetvn_agent_hub_wp_zone ? gmdate( 'Y-m-d\TH:i:s\Z', time() + 2592000 ) : gmdate( 'Y-m-d\TH:i:s', time() + 2592000 + 25200 ) . '+07:00';
			$created = $xnetvn_agent_hub_wp_harness->call(
				'admin',
				'create-post',
				array(
					'title'  => 'Future lifecycle sample',
					'status' => 'future',
					'date'   => $input,
				)
			);
			$data    = $xnetvn_agent_hub_wp_success( $created );
			if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || 'future' !== ( $data['status'] ?? '' ) ) {
				return false;
			}
			if ( strtotime( $data['date_gmt'] ?? '' ) !== strtotime( $input ) ) {
				return false;
			}
			$id                                = (int) $data['id'];
			$xnetvn_agent_hub_wp_future_id     = $id;
			$xnetvn_agent_hub_wp_owned_posts[] = $id;
			$read                              = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'get-post',
				array( 'id' => $id )
			);
			$read_data                         = $xnetvn_agent_hub_wp_success( $read );
			return is_array( $read_data ) && 'future' === ( $read_data['status'] ?? '' ) && strtotime( $read_data['date_gmt'] ?? '' ) === strtotime( $input );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-03-future-' . $xnetvn_agent_hub_wp_variant, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
	$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-03-invalid-' . $xnetvn_agent_hub_wp_variant,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_rendered, $xnetvn_agent_hub_wp_counts_equal, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
			$counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
			foreach ( array(
				array(
					'title'  => 'Missing date future',
					'status' => 'future',
				),
				array(
					'title'  => 'Past date future',
					'status' => 'future',
					'date'   => '2000-01-01T00:00:00Z',
				),
			) as $invalid ) {
				$response = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens['admin'],
					'create-post',
					$invalid
				);
				if ( ! $xnetvn_agent_hub_wp_denied( $response, 'invalid_params' ) ) {
					return false;
				}
			}
			// An offsetless date never reaches the product: the adapter
			// rejects the wire shape without data, like other strict-schema
			// rejections on the installed adapter.
			$offsetless = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'create-post',
				array(
					'title'  => 'Offsetless date future',
					'status' => 'future',
					'date'   => '2026-10-08T12:00:00',
				)
			);
			if ( ! $xnetvn_agent_hub_wp_rendered( $offsetless ) ) {
				return false;
			}
			return $xnetvn_agent_hub_wp_counts_equal( $counts_before, $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' ) );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-03-invalid-' . $xnetvn_agent_hub_wp_variant, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_zone, $xnetvn_agent_hub_wp_variant );
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'timezone', 'restore' );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-03',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-03-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-03', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-04: a far-future owned post reads back future; the fixture moves only
// that post to its due date through core APIs, unschedules only its
// publish_future_post event and fires the core action. The post then reads
// back publish without touching any other event. This is a controlled cron
// event check, not proof of host cron behavior.
foreach ( array( 'UTC', 'Asia/Ho_Chi_Minh' ) as $xnetvn_agent_hub_wp_zone ) {
	$xnetvn_agent_hub_wp_variant = 'UTC' === $xnetvn_agent_hub_wp_zone ? 'utc' : 'ict';
	try {
		$xnetvn_agent_hub_wp_zoned = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'timezone', $xnetvn_agent_hub_wp_zone );
		if ( ( $xnetvn_agent_hub_wp_zoned['zone'] ?? '' ) !== $xnetvn_agent_hub_wp_zone ) {
			throw new \RuntimeException( 'Role SEO timezone unavailable.' );
		}
	} catch ( \Throwable ) {
		xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-04-cron-due-' . $xnetvn_agent_hub_wp_variant, false );
		$xnetvn_agent_hub_wp_failed = true;
		continue;
	}
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-04-cron-due-' . $xnetvn_agent_hub_wp_variant,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_zone, &$xnetvn_agent_hub_wp_owned_posts ): bool {
			$input   = 'UTC' === $xnetvn_agent_hub_wp_zone ? gmdate( 'Y-m-d\TH:i:s\Z', time() + 2592000 ) : gmdate( 'Y-m-d\TH:i:s', time() + 2592000 + 25200 ) . '+07:00';
			$created = $xnetvn_agent_hub_wp_harness->call(
				'admin',
				'create-post',
				array(
					'title'  => 'Cron lifecycle sample',
					'status' => 'future',
					'date'   => $input,
				)
			);
			$data    = $xnetvn_agent_hub_wp_success( $created );
			if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
				return false;
			}
			$id                                = (int) $data['id'];
			$xnetvn_agent_hub_wp_owned_posts[] = $id;
			$before                            = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'get-post',
				array( 'id' => $id )
			);
			$before_data                       = $xnetvn_agent_hub_wp_success( $before );
			if ( ! is_array( $before_data ) || 'future' !== ( $before_data['status'] ?? '' ) ) {
				return false;
			}
			$due = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'cron-due', (string) $id );
			if ( true !== ( $due['due'] ?? false ) || 'publish' !== ( $due['status'] ?? '' ) || 1 !== ( $due['events_removed'] ?? 0 ) ) {
				return false;
			}
			if ( (int) ( $due['events_after'] ?? -1 ) !== (int) ( $due['events_before'] ?? 0 ) - 1 ) {
				return false;
			}
			$after      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'get-post',
				array( 'id' => $id )
			);
			$after_data = $xnetvn_agent_hub_wp_success( $after );
			return is_array( $after_data ) && 'publish' === ( $after_data['status'] ?? '' );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-04-cron-due-' . $xnetvn_agent_hub_wp_variant, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_zone, $xnetvn_agent_hub_wp_variant );
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'timezone', 'restore' );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-04',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-04-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-04', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-05: trash and restore need the write tier, delete needs the
// destructive tier. Restoring a live post is an invalid state, a read key
// and a write key without the delete grant both see the destructive tool
// hidden, and deleting a missing object is not found.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-05-trash-restore-delete',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$created = $xnetvn_agent_hub_wp_harness->call( 'editor', 'create-post', array( 'title' => 'Trash lifecycle' ) );
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$id           = (int) $data['id'];
		$trashed      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'trash-post',
			array( 'id' => $id )
		);
		$trashed_data = $xnetvn_agent_hub_wp_success( $trashed );
		if ( ! is_array( $trashed_data ) || 'trash' !== ( $trashed_data['status'] ?? '' ) ) {
			return false;
		}
		$double = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'trash-post',
			array( 'id' => $id )
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $double, 'invalid_state' ) ) {
			return false;
		}
		$restored      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'restore-post',
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);
		$restored_data = $xnetvn_agent_hub_wp_success( $restored );
		if ( ! is_array( $restored_data ) || 'draft' !== ( $restored_data['status'] ?? '' ) ) {
			return false;
		}
		$live = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'restore-post',
			array( 'id' => $id )
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $live, 'invalid_state' ) ) {
			return false;
		}
		$deleted      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'delete-post',
			array( 'id' => $id )
		);
		$deleted_data = $xnetvn_agent_hub_wp_success( $deleted );
		if ( ! is_array( $deleted_data ) || true !== ( $deleted_data['deleted'] ?? false ) ) {
			return false;
		}
		$gone = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'get-post',
			array( 'id' => $id )
		);
		return $xnetvn_agent_hub_wp_denied( $gone, 'not_found' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-05-trash-restore-delete', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-05-scope-guard',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_denied, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$created = $xnetvn_agent_hub_wp_harness->call( 'author', 'create-post', array( 'title' => 'Author trash control' ) );
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$id                                = (int) $data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $id;
		$trashed                           = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'trash-post',
			array( 'id' => $id )
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $trashed ) ) ) {
			return false;
		}
		$restored = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'restore-post',
			array(
				'id'     => $id,
				'status' => 'draft',
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $restored ) ) ) {
			return false;
		}
		$read_denied = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-trash-post',
				'arguments' => (object) array( 'id' => $id ),
			)
		);
		if ( ! $xnetvn_agent_hub_wp_hidden( $read_denied ) ) {
			return false;
		}
		$write_denied = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'delete-post',
			array( 'id' => $id )
		);
		if ( ! $xnetvn_agent_hub_wp_hidden( $write_denied ) ) {
			return false;
		}
		$missing = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'delete-post',
			array( 'id' => 999999 )
		);
		return $xnetvn_agent_hub_wp_denied( $missing, 'not_found' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-05-scope-guard', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed          = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_counts_05_after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok              = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-05',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_counts_before, $xnetvn_agent_hub_wp_counts_05_after, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-05-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		if ( $xnetvn_agent_hub_wp_before !== $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' ) ) {
			return false;
		}
		// Matrix-owned posts stay alive until teardown, so only the
		// no-child-file signal is compared here: media and terms totals.
		foreach ( array( 'users', 'keys', 'terms', 'media' ) as $kind ) {
			if ( ( $xnetvn_agent_hub_wp_counts_before[ $kind ] ?? null ) !== ( $xnetvn_agent_hub_wp_counts_05_after[ $kind ] ?? null ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-05', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-06: duplicating an owned post with terms and a featured image keeps
// the allowed fields under the acting owner and the requested status. A
// read-scoped key sees the tool hidden, and a missing source is not found.
// The fixture post is restored afterwards, proven by the digest aggregate.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-06-duplicate',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_hidden, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$source      = $xnetvn_agent_hub_wp_harness->call( 'editor', 'get-post', array( 'id' => 101 ) );
		$source_data = $xnetvn_agent_hub_wp_success( $source );
		if ( ! is_array( $source_data ) ) {
			return false;
		}
		$prepared = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'update-post',
			array(
				'id'         => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				'categories' => array( $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 301 ) )['id'] ),
				'tags'       => array( $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 302 ) )['id'] ),
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $prepared ) ) ) {
			return false;
		}
		$pinned = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'set-featured-media',
			array(
				'id'       => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				'media_id' => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 401 ) )['id'],
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $pinned ) ) ) {
			return false;
		}
		$copy      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'duplicate-post',
			array(
				'id'     => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				'status' => 'draft',
			)
		);
		$copy_data = $xnetvn_agent_hub_wp_success( $copy );
		if ( ! is_array( $copy_data ) || 1 > (int) ( $copy_data['id'] ?? 0 ) ) {
			return false;
		}
		$copy_id                           = (int) $copy_data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $copy_id;
		if ( $copy_id === $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'] ) {
			return false;
		}
		if ( (int) ( $copy_data['author'] ?? 0 ) !== (int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 ) || 'draft' !== ( $copy_data['status'] ?? '' ) ) {
			return false;
		}
		if ( ( $copy_data['title'] ?? null ) !== ( $source_data['title'] ?? null ) ) {
			return false;
		}
		if ( (int) ( $copy_data['featured_media'] ?? 0 ) !== $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 401 ) )['id'] ) {
			return false;
		}
		if ( array( (int) $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 301 ) )['id'] ) !== array_map( 'intval', (array) ( $copy_data['categories'] ?? array() ) ) ) {
			return false;
		}
		if ( array( (int) $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 302 ) )['id'] ) !== array_map( 'intval', (array) ( $copy_data['tags'] ?? array() ) ) ) {
			return false;
		}
		$deleted      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'delete-post',
			array( 'id' => $copy_id )
		);
		$deleted_data = $xnetvn_agent_hub_wp_success( $deleted );
		if ( ! is_array( $deleted_data ) || true !== ( $deleted_data['deleted'] ?? false ) ) {
			return false;
		}
		array_pop( $xnetvn_agent_hub_wp_owned_posts );
		$restored_terms = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'update-post',
			array(
				'id'         => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				'categories' => (array) ( $source_data['categories'] ?? array() ),
				'tags'       => (array) ( $source_data['tags'] ?? array() ),
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $restored_terms ) ) ) {
			return false;
		}
		$restored_featured = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'set-featured-media',
			array(
				'id'       => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				'media_id' => (int) ( $source_data['featured_media'] ?? 0 ),
			)
		);
		return is_array( $xnetvn_agent_hub_wp_success( $restored_featured ) );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-06-duplicate', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-06-denied',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_denied ): bool {
		$read = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-duplicate-post',
				'arguments' => (object) array(
					'id' => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'],
				),
			)
		);
		if ( ! $xnetvn_agent_hub_wp_hidden( $read ) ) {
			return false;
		}
		$missing = $xnetvn_agent_hub_wp_harness->call(
			'admin',
			'duplicate-post',
			array(
				'id'     => 999999,
				'status' => 'draft',
			)
		);
		return $xnetvn_agent_hub_wp_denied( $missing, 'not_found' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-06-denied', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-06',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-06-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-06', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-07: each post binds only its own revisions. Reading or restoring a
// revision through the other post is not found, and neither post changes.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-07-binding',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		foreach ( array(
			'first'  => '<p>Lifecycle revision v1</p>',
			'second' => '<p>Other revision v1</p>',
		) as $slot => $body ) {
			$created = $xnetvn_agent_hub_wp_harness->call(
				'admin',
				'create-post',
				array(
					'title'   => 'Revision ' . $slot,
					'content' => $body,
				)
			);
			$data    = $xnetvn_agent_hub_wp_success( $created );
			if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
				return false;
			}
			$xnetvn_agent_hub_wp_owned_posts[ $slot ] = (int) $data['id'];
		}
		foreach ( array(
			'first'  => '<p>Lifecycle revision v2</p>',
			'second' => '<p>Other revision v2</p>',
		) as $slot => $body ) {
			$updated = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'update-post',
				array(
					'id'      => $xnetvn_agent_hub_wp_owned_posts[ $slot ],
					'content' => $body,
				)
			);
			if ( ! is_array( $xnetvn_agent_hub_wp_success( $updated ) ) ) {
				return false;
			}
		}
		$mine      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'list-revisions',
			array( 'id' => $xnetvn_agent_hub_wp_owned_posts['first'] )
		);
		$mine_data = $xnetvn_agent_hub_wp_success( $mine );
		if ( ! is_array( $mine_data ) || 1 > count( $mine_data['items'] ?? array() ) ) {
			return false;
		}
		foreach ( (array) ( $mine_data['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['parent'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_posts['first'] ) {
				return false;
			}
		}
		$theirs      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'list-revisions',
			array( 'id' => $xnetvn_agent_hub_wp_owned_posts['second'] )
		);
		$theirs_data = $xnetvn_agent_hub_wp_success( $theirs );
		if ( ! is_array( $theirs_data ) || 1 > count( $theirs_data['items'] ?? array() ) ) {
			return false;
		}
		foreach ( (array) ( $theirs_data['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['parent'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_posts['second'] ) {
				return false;
			}
		}
		$xnetvn_agent_hub_wp_owned_posts['first_rev']  = (int) $mine_data['items'][0]['id'];
		$xnetvn_agent_hub_wp_owned_posts['second_rev'] = (int) $theirs_data['items'][0]['id'];
		$read      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-revision',
			array(
				'id'          => $xnetvn_agent_hub_wp_owned_posts['first'],
				'revision_id' => $xnetvn_agent_hub_wp_owned_posts['first_rev'],
			)
		);
		$read_data = $xnetvn_agent_hub_wp_success( $read );
		if ( ! is_array( $read_data ) || (int) ( $read_data['parent'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_posts['first'] ) {
			return false;
		}
		$restored      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'restore-revision',
			array(
				'id'          => $xnetvn_agent_hub_wp_owned_posts['first'],
				'revision_id' => $xnetvn_agent_hub_wp_owned_posts['first_rev'],
			)
		);
		$restored_data = $xnetvn_agent_hub_wp_success( $restored );
		if ( ! is_array( $restored_data ) || (int) ( $restored_data['id'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_posts['first'] ) {
			return false;
		}
		$check      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array( 'id' => $xnetvn_agent_hub_wp_owned_posts['first'] )
		);
		$check_data = $xnetvn_agent_hub_wp_success( $check );
		return is_array( $check_data ) && str_contains( (string) ( $check_data['content'] ?? '' ), 'Lifecycle revision v' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-07-binding', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-07-swap-denied',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		if ( ! isset( $xnetvn_agent_hub_wp_owned_posts['first'], $xnetvn_agent_hub_wp_owned_posts['second'], $xnetvn_agent_hub_wp_owned_posts['second_rev'] ) ) {
			return false;
		}
		$swapped_read = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-revision',
			array(
				'id'          => $xnetvn_agent_hub_wp_owned_posts['first'],
				'revision_id' => $xnetvn_agent_hub_wp_owned_posts['second_rev'],
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $swapped_read, 'not_found' ) ) {
			return false;
		}
		$swapped_restore = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'restore-revision',
			array(
				'id'          => $xnetvn_agent_hub_wp_owned_posts['first'],
				'revision_id' => $xnetvn_agent_hub_wp_owned_posts['second_rev'],
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $swapped_restore, 'not_found' ) ) {
			return false;
		}
		$first       = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array( 'id' => $xnetvn_agent_hub_wp_owned_posts['first'] )
		);
		$first_data  = $xnetvn_agent_hub_wp_success( $first );
		$second      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array( 'id' => $xnetvn_agent_hub_wp_owned_posts['second'] )
		);
		$second_data = $xnetvn_agent_hub_wp_success( $second );
		return is_array( $first_data ) && str_contains( (string) ( $first_data['content'] ?? '' ), 'Lifecycle revision v' )
			&& is_array( $second_data ) && str_contains( (string) ( $second_data['content'] ?? '' ), 'Other revision v2' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-07-swap-denied', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
foreach ( array( 'first', 'second' ) as $xnetvn_agent_hub_wp_slot ) {
	if ( isset( $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_slot ] ) && is_int( $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_slot ] ) ) {
		try {
			$xnetvn_agent_hub_wp_deleted = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'delete-post',
				array( 'id' => $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_slot ] )
			);
			if ( ! is_array( $xnetvn_agent_hub_wp_success( $xnetvn_agent_hub_wp_deleted ) ) ) {
				$xnetvn_agent_hub_wp_failed = true;
			} else {
				unset( $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_slot ] );
			}
		} catch ( \Throwable ) {
			$xnetvn_agent_hub_wp_failed = true;
		}
	}
}
unset( $xnetvn_agent_hub_wp_slot, $xnetvn_agent_hub_wp_owned_posts['first_rev'], $xnetvn_agent_hub_wp_owned_posts['second_rev'] );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-07',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-07-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-07', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-08: a stale optimistic token conflicts without overwriting, while the
// fresh token wins. The comparison is best-effort by contract: two rapid
// writes with the same token record their actual outcome, and only the end
// state integrity (valid DTO, owner, one of the two titles) is asserted,
// never exactly-once delivery.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-08-conflict-stale',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_token, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$created = $xnetvn_agent_hub_wp_harness->call( 'admin', 'create-post', array( 'title' => 'Conflict sample' ) );
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$id                                = (int) $data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $id;
		$before                            = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		$before_data                       = $xnetvn_agent_hub_wp_success( $before );
		if ( ! is_array( $before_data ) || ! is_string( $before_data['modified_gmt'] ?? null ) ) {
			return false;
		}
		$stale = $xnetvn_agent_hub_wp_token( $before_data['modified_gmt'] );
		// Wait for the wall clock to leave the token's second so the next
		// write provably lands in a later one-second bucket. This is a
		// bounded wait on the observed clock, not a fixed sleep guessing
		// job completion; the store resolution is one second.
		$clock_deadline = hrtime( true ) + 5000000000;
		while ( time() <= strtotime( $stale ) && hrtime( true ) < $clock_deadline ) {
			usleep( 100000 );
		}
		if ( time() <= strtotime( $stale ) ) {
			return false;
		}
		$bumped = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'update-post',
			array(
				'id'    => $id,
				'title' => 'Conflict bumped',
			)
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $bumped ) ) ) {
			return false;
		}
		$current      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		$current_data = $xnetvn_agent_hub_wp_success( $current );
		if ( ! is_array( $current_data ) || ! is_string( $current_data['modified_gmt'] ?? null ) || $xnetvn_agent_hub_wp_token( $current_data['modified_gmt'] ) === $stale ) {
			return false;
		}
		$xnetvn_agent_hub_wp_owned_posts['conflict_id']    = $id;
		$xnetvn_agent_hub_wp_owned_posts['conflict_stale'] = $stale;
		$xnetvn_agent_hub_wp_owned_posts['conflict_fresh'] = $xnetvn_agent_hub_wp_token( $current_data['modified_gmt'] );
		$conflict = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'update-post',
			array(
				'id'                => $id,
				'title'             => 'Stale write',
				'expected_modified' => $stale,
			)
		);
		if ( ! $xnetvn_agent_hub_wp_denied( $conflict, 'conflict' ) ) {
			return false;
		}
		$check      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array( 'id' => $id )
		);
		$check_data = $xnetvn_agent_hub_wp_success( $check );
		return is_array( $check_data ) && 'Conflict bumped' === ( $check_data['title'] ?? null );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-08-conflict-stale', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-08-conflict-fresh',
	static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_users, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		if ( ! isset( $xnetvn_agent_hub_wp_owned_posts['conflict_id'], $xnetvn_agent_hub_wp_owned_posts['conflict_fresh'] ) ) {
			return false;
		}
		$id         = (int) $xnetvn_agent_hub_wp_owned_posts['conflict_id'];
		$fresh      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'update-post',
			array(
				'id'                => $id,
				'title'             => 'Fresh write',
				'expected_modified' => $xnetvn_agent_hub_wp_owned_posts['conflict_fresh'],
			)
		);
		$fresh_data = $xnetvn_agent_hub_wp_success( $fresh );
		return is_array( $fresh_data ) && 'Fresh write' === ( $fresh_data['title'] ?? null ) && (int) ( $fresh_data['author'] ?? 0 ) === (int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-08-conflict-fresh', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-08-concurrent',
	static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_users, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		if ( ! isset( $xnetvn_agent_hub_wp_owned_posts['conflict_id'] ) ) {
			return false;
		}
		$id        = (int) $xnetvn_agent_hub_wp_owned_posts['conflict_id'];
		$read      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		$read_data = $xnetvn_agent_hub_wp_success( $read );
		if ( ! is_array( $read_data ) || ! is_string( $read_data['modified_gmt'] ?? null ) ) {
			return false;
		}
		$token = str_ends_with( $read_data['modified_gmt'], 'Z' ) ? $read_data['modified_gmt'] : $read_data['modified_gmt'] . 'Z';
		foreach ( array( 'Race A', 'Race B' ) as $title ) {
			$xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				'update-post',
				array(
					'id'                => $id,
					'title'             => $title,
					'expected_modified' => $token,
				)
			);
		}
		$final      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-post',
			array( 'id' => $id )
		);
		$final_data = $xnetvn_agent_hub_wp_success( $final );
		return is_array( $final_data ) && (int) ( $final_data['id'] ?? 0 ) === $id
			&& (int) ( $final_data['author'] ?? 0 ) === (int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 )
			&& in_array( $final_data['title'] ?? '', array( 'Race A', 'Race B' ), true );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-08-concurrent', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-08',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-08-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-08', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// LIFE-09: bulk answers per object with safe reasons and no transaction, and
// faulted single writes return a partial-failure receipt carrying the owned
// id. Reconcile paths only read and delete that id; the call log proves no
// second mutation is ever issued on an unknown outcome.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-09-bulk',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, &$xnetvn_agent_hub_wp_owned_posts ): bool {
		$created = $xnetvn_agent_hub_wp_harness->call( 'author', 'create-post', array( 'title' => 'Bulk lifecycle own' ) );
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$own_id                            = (int) $data['id'];
		$xnetvn_agent_hub_wp_owned_posts[] = $own_id;
		$other_id                          = (int) $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 202 ) )['id'];
		$bulk                              = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'bulk-update-posts',
			array(
				'ids'     => array( $own_id, $other_id, 999999 ),
				'changes' => array( 'status' => 'pending' ),
			)
		);
		$bulk_data                         = $xnetvn_agent_hub_wp_success( $bulk );
		if ( ! is_array( $bulk_data ) || array( $own_id ) !== array_map( 'intval', (array) ( $bulk_data['updated'] ?? null ) ) ) {
			return false;
		}
		$failed = array();
		foreach ( (array) ( $bulk_data['failed'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['code'] ) ) {
				return false;
			}
			$failed[ (int) $entry['id'] ] = $entry['code'];
		}
		if ( array(
			$other_id => 'forbidden',
			999999    => 'not_found',
		) !== $failed ) {
			return false;
		}
		$own      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'get-post',
			array( 'id' => $own_id )
		);
		$own_data = $xnetvn_agent_hub_wp_success( $own );
		if ( ! is_array( $own_data ) || 'pending' !== ( $own_data['status'] ?? '' ) ) {
			return false;
		}
		$other      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'get-post',
			array( 'id' => $other_id )
		);
		$other_data = $xnetvn_agent_hub_wp_success( $other );
		return is_array( $other_data ) && 'draft' === ( $other_data['status'] ?? '' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-09-bulk', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

/**
 * Reconcile one faulted write without a second mutation.
 *
 * Parses the owned created id from the partial-failure text, or falls back
 * to a title search excluding known ids when the adapter shortens the
 * message. Only get-post, list-posts and delete-post are issued; create,
 * duplicate and bulk tools never run here.
 *
 * @param callable            $tool Call helper.
 * @param McpClient           $client HTTP transport.
 * @param int                 $envelope Next envelope id.
 * @param string              $token Admin bearer.
 * @param callable            $success Success helper.
 * @param callable            $denied Denial helper.
 * @param array<string,mixed> $fault Faulted tool response.
 * @param int                 $owner Expected owner user id.
 * @param string              $marker Title to match in fallback search.
 * @param array<int>          $exclude Known ids excluded from fallback search.
 * @return bool
 */
$xnetvn_agent_hub_wp_reconcile = static function ( callable $tool, McpClient $client, int &$envelope, string $token, callable $success, callable $denied, array $fault, int $owner, string $marker, array $exclude = array() ): bool {
	$text = $fault['body']['result']['content'][0]['text'] ?? '';
	$id   = 0;
	if ( is_string( $text ) && 1 === preg_match( '/created_id=(\d+)/', $text, $matches ) ) {
		$id = (int) $matches[1];
	}
	if ( 1 > $id ) {
		$search = $tool(
			$client,
			$envelope,
			$token,
			'list-posts',
			array(
				'search' => $marker,
				'status' => array( 'draft', 'pending', 'publish', 'private', 'future' ),
			)
		);
		$found  = $success( $search );
		if ( ! is_array( $found ) ) {
			return false;
		}
		foreach ( (array) ( $found['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && ( $item['title'] ?? null ) === $marker && ! in_array( (int) ( $item['id'] ?? 0 ), $exclude, true ) ) {
				if ( 0 < $id ) {
					return false;
				}
				$id = (int) ( $item['id'] ?? 0 );
			}
		}
		if ( 1 > $id ) {
			return false;
		}
	}
	$read      = $tool( $client, $envelope, $token, 'get-post', array( 'id' => $id ) );
	$read_data = $success( $read );
	if ( ! is_array( $read_data ) || (int) ( $read_data['id'] ?? 0 ) !== $id || (int) ( $read_data['author'] ?? 0 ) !== $owner ) {
		return false;
	}
	$deleted      = $tool( $client, $envelope, $token, 'delete-post', array( 'id' => $id ) );
	$deleted_data = $success( $deleted );
	if ( ! is_array( $deleted_data ) || true !== ( $deleted_data['deleted'] ?? false ) ) {
		return false;
	}
	$gone = $tool( $client, $envelope, $token, 'get-post', array( 'id' => $id ) );
	return $denied( $gone, 'not_found' );
};

foreach ( array(
	'create'    => 'create-post',
	'duplicate' => 'duplicate-post',
) as $xnetvn_agent_hub_wp_fault_kind => $xnetvn_agent_hub_wp_fault_tool ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'LIFE-09-partial-' . $xnetvn_agent_hub_wp_fault_kind,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_reconcile, $xnetvn_agent_hub_wp_fault_tool, &$xnetvn_agent_hub_wp_call_log ): bool {
			$armed = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'lifecycle-fault', 'prepare-fail-once' );
			if ( true !== ( $armed['armed'] ?? false ) ) {
				return false;
			}
			$source_id = $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'];
			$marker    = 'Partial lifecycle marker';
			$exclude   = array();
			if ( 'duplicate-post' === $xnetvn_agent_hub_wp_fault_tool ) {
				$known      = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens['admin'],
					'get-post',
					array( 'id' => $source_id )
				);
				$known_data = $xnetvn_agent_hub_wp_success( $known );
				if ( ! is_array( $known_data ) || ! is_string( $known_data['title'] ?? null ) || '' === $known_data['title'] ) {
					return false;
				}
				$marker  = $known_data['title'];
				$exclude = array( $source_id );
			}
			$args            = 'create-post' === $xnetvn_agent_hub_wp_fault_tool ? array( 'title' => $marker ) : array(
				'id'     => $source_id,
				'status' => 'draft',
			);
			$log_before      = count( $xnetvn_agent_hub_wp_call_log );
			$envelope_before = $xnetvn_agent_hub_wp_envelope;
			$fault           = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				$xnetvn_agent_hub_wp_fault_tool,
				$args
			);
			if ( ! $xnetvn_agent_hub_wp_denied( $fault, 'partial_failure' ) ) {
				return false;
			}
			foreach ( array_slice( $xnetvn_agent_hub_wp_call_log, $log_before ) as $called ) {
				if ( 'xnetvn-' . $xnetvn_agent_hub_wp_fault_tool !== $called ) {
					return false;
				}
			}
			$ok = $xnetvn_agent_hub_wp_reconcile(
				$xnetvn_agent_hub_wp_tool,
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				$xnetvn_agent_hub_wp_success,
				$xnetvn_agent_hub_wp_denied,
				$fault,
				(int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 ),
				$marker,
				$exclude
			);
			if ( ! $ok ) {
				return false;
			}
			// Exactly one faulted mutation plus read-only reconcile calls.
			$segment = array_slice( $xnetvn_agent_hub_wp_call_log, $log_before );
			if ( 1 !== count( array_filter( $segment, static fn( $name ): bool => 'xnetvn-' . $xnetvn_agent_hub_wp_fault_tool === $name ) ) ) {
				return false;
			}
			foreach ( $segment as $called ) {
				if ( ! in_array( $called, array( 'xnetvn-' . $xnetvn_agent_hub_wp_fault_tool, 'xnetvn-get-post', 'xnetvn-delete-post', 'xnetvn-list-posts' ), true ) ) {
					return false;
				}
			}
			return ( $xnetvn_agent_hub_wp_envelope - $envelope_before ) === count( $segment );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-09-partial-' . $xnetvn_agent_hub_wp_fault_kind, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_fault_kind, $xnetvn_agent_hub_wp_fault_tool );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'LIFE-09',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'LIFE-09-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'LIFE-09', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// Teardown: delete every post this matrix created, drop temp keys and
// restore site settings; the runner owns final cleanup.
foreach ( $xnetvn_agent_hub_wp_owned_posts as $xnetvn_agent_hub_wp_owned_id ) {
	if ( ! is_int( $xnetvn_agent_hub_wp_owned_id ) ) {
		continue;
	}
	try {
		$xnetvn_agent_hub_wp_deleted = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'delete-post',
			array( 'id' => $xnetvn_agent_hub_wp_owned_id )
		);
		$xnetvn_agent_hub_wp_gone    = $xnetvn_agent_hub_wp_success( $xnetvn_agent_hub_wp_deleted );
		if ( is_array( $xnetvn_agent_hub_wp_gone ) && true === ( $xnetvn_agent_hub_wp_gone['deleted'] ?? false ) ) {
			continue;
		}
		if ( ! $xnetvn_agent_hub_wp_denied( $xnetvn_agent_hub_wp_deleted, 'not_found' ) ) {
			$xnetvn_agent_hub_wp_failed = true;
		}
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
}
unset( $xnetvn_agent_hub_wp_owned_id );
try {
	$xnetvn_agent_hub_wp_dropped = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'key-drop' );
	if ( 5 !== ( $xnetvn_agent_hub_wp_dropped['dropped'] ?? -1 ) ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'timezone', 'restore' );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}

$xnetvn_agent_hub_wp_report = array(
	'suite'     => 'role-seo-lifecycle',
	'target'    => $xnetvn_agent_hub_wp_site,
	'run_id'    => $xnetvn_agent_hub_wp_run_id,
	'blog'      => (int) $xnetvn_agent_hub_wp_blog,
	'cases'     => $xnetvn_agent_hub_wp_harness->cases(),
	'declared'  => xnetvn_agent_hub_wp_role_seo_lifecycle_declare_cases(),
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
 * @param string         $reason Allowlisted reason, never raw input.
 */
function xnetvn_agent_hub_wp_role_seo_record( RoleSeoHarness $harness, string $case_id, bool $ok, string $reason = 'unavailable' ): void {
	$harness->recordCase( $case_id, $ok, $reason );
}
