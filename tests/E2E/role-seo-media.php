<?php
/**
 * Owned role/SEO media matrix: IMG-01 through IMG-10 declarations and the
 * MCP-level cases executed through real HTTPS.
 *
 * Each IMG row from the ticket is a separate recorded case (multiplied by
 * role/tool variant where the ticket requires it). Reports carry only case
 * ids, allowlisted reasons, counts, digests and boolean checks; no input,
 * response bodies, cookies, bearers, hashes or argument values are printed.
 * Attachments and posts created by this matrix are deleted in teardown
 * before the report so the runner counts return to baseline.
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
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Generated synthetic image bytes, never executable code.

/**
 * Declare the ten media cases with their owner and applicability.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_media_declare_cases(): array {
	return array(
		array(
			'id'           => 'IMG-01',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
			'scope_modes'  => array( 'write', 'read' ),
			'variants'     => array( 'limits', 'hidden' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-02',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'feature', 'inline' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-03',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'update', 'featured' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-04',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'inline' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-05',
			'owner'        => 'matrix',
			'roles'        => array( 'contributor', 'subscriber', 'banned' ),
			'scope_modes'  => array( 'write', 'read' ),
			'variants'     => array( 'upload', 'update', 'featured' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-06',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'update', 'delete', 'inline' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-07',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'invalid' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-08',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'ceiling', 'over', 'pixels', 'restored' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-09',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'url' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'IMG-10',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'reconcile', 'cleanup' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
	);
}

/**
 * Track whether the shared lab refused this run with a rate limit.
 *
 * The authentication budget of the shared lab belongs to every matrix that
 * runs in the same minute, so a refusal with HTTP 429 is an environment
 * limit and never a product defect. The matrix names that reason instead
 * of blaming the product and keeps the run red.
 *
 * @param bool $limited True when a refusal was observed.
 * @return bool True when a refusal was observed in this run.
 */
function xnetvn_agent_hub_wp_role_seo_media_limited( bool $limited = false ): bool {
	static $seen = false;
	if ( $limited ) {
		$seen = true;
	}
	return $seen;
}

/**
 * Call one tool through the shared harness and watch for a rate limit.
 *
 * @param RoleSeoHarness      $harness Shared harness.
 * @param string              $actor Actor alias.
 * @param string              $tool Tool suffix or full xnetvn- name.
 * @param array<string,mixed> $args Tool arguments.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_media_call( RoleSeoHarness $harness, string $actor, string $tool, array $args = array() ): array {
	$response = $harness->call( $actor, $tool, $args );
	xnetvn_agent_hub_wp_role_seo_media_limited( 429 === ( $response['status'] ?? 0 ) );
	return $response;
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
	$response = $client->request(
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
	// A refusal with HTTP 429 belongs to the shared budget of the lab, not
	// to the product: the matrix names that reason and stays red.
	xnetvn_agent_hub_wp_role_seo_media_limited( 429 === ( $response['status'] ?? 0 ) );
	return $response;
};

/**
 * Call one tool with already-resolved real ids (no ledger band involved).
 *
 * @param McpClient           $client HTTP transport.
 * @param int                 $id Next envelope id.
 * @param string              $token Bearer token.
 * @param string              $tool Tool suffix or full xnetvn- name.
 * @param array<string,mixed> $args Tool arguments with real ids.
 * @return array<string,mixed>
 */
$xnetvn_agent_hub_wp_tool = static function ( McpClient $client, int &$id, string $token, string $tool, array $args = array() ) use ( $xnetvn_agent_hub_wp_send ): array {
	$name = str_starts_with( $tool, 'xnetvn-' ) ? $tool : 'xnetvn-' . $tool;
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
 * @throws RuntimeException For a failed fixture control.
 */
$xnetvn_agent_hub_wp_action = static function ( string $site, string $run_id, string ...$argv ) use ( $xnetvn_agent_hub_wp_host ): array {
	$allowed_actions = array( 'site-variant', 'key-temp', 'key-drop', 'content-digest', 'counts' );
	$allowed_subs    = array( 'rate-high', 'media-small', 'reset' );
	foreach ( $argv as $index => $part ) {
		if ( 0 === $index && ! in_array( $part, $allowed_actions, true ) ) {
			throw new RuntimeException( 'Invalid fixture action.' );
		}
		if ( 0 < $index && ! in_array( $part, $allowed_subs, true ) ) {
			throw new RuntimeException( 'Invalid fixture action.' );
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
		throw new RuntimeException( 'Fixture action failed.' );
	}
	$decoded = json_decode( implode( "\n", $output ), true, 32 );
	if ( ! is_array( $decoded ) ) {
		throw new RuntimeException( 'Fixture action unreadable.' );
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
 * @throws RuntimeException For a missing or foreign state file.
 */
$xnetvn_agent_hub_wp_private = static function ( string $state_file ): array {
	if ( '' === $state_file || is_link( $state_file ) || ! is_file( $state_file ) ) {
		throw new RuntimeException( 'Role SEO fixture state unavailable.' );
	}
	$state = json_decode( file_get_contents( $state_file ), true, 64, JSON_THROW_ON_ERROR );
	if ( ! is_array( $state ) ) {
		throw new RuntimeException( 'Role SEO fixture state malformed.' );
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
 * resource totals are the stable denial signal. Settings restoration has
 * its own explicit case.
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
 * Build one exact-size valid PNG by padding the fixed pixel with tEXt.
 *
 * Keeps a decodable 1x1 image so ceiling tests measure policy, never
 * corruption. Needs no image library on the lab host.
 *
 * @param string $pixel_b64 Fixed valid PNG base64.
 * @param int    $target Exact byte size.
 * @return string Canonical base64 of the tuned image.
 * @throws RuntimeException For an impossible target size.
 */
$xnetvn_agent_hub_wp_exact_png = static function ( string $pixel_b64, int $target ): string {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode a fixed test pixel, never executable code.
	$pixel = base64_decode( $pixel_b64, true );
	if ( ! is_string( $pixel ) || $target < strlen( $pixel ) + 21 ) {
		throw new RuntimeException( 'Role SEO exact image unavailable.' );
	}
	$text  = str_repeat( 'M', $target - strlen( $pixel ) - 12 - 8 );
	$data  = 'Comment' . "\0" . $text;
	$chunk = pack( 'N', strlen( $data ) ) . 'tEXt' . $data . pack( 'N', crc32( 'tEXt' . $data ) & 0xffffffff );
	return base64_encode( substr( $pixel, 0, -12 ) . $chunk . substr( $pixel, -12 ) );
};

// Setup: temp keys for the contributor/read paths; budgets raised so
// neighboring denials never mask one another with rate limits.
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
	$xnetvn_agent_hub_wp_made = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'key-temp' );
	if ( 5 !== ( $xnetvn_agent_hub_wp_made['temp_keys'] ?? 0 ) ) {
		throw new RuntimeException( 'Role SEO temp keys unavailable.' );
	}
	$xnetvn_agent_hub_wp_private_state = $xnetvn_agent_hub_wp_private( $xnetvn_agent_hub_wp_state_file );
	$xnetvn_agent_hub_wp_temp_tokens   = $xnetvn_agent_hub_wp_private_state['temp_tokens'] ?? array();
	if ( ! isset( $xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'] ) || ! is_string( $xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'] ) || ! isset( $xnetvn_agent_hub_wp_temp_tokens['temp_read'] ) || ! is_string( $xnetvn_agent_hub_wp_temp_tokens['temp_read'] ) ) {
		throw new RuntimeException( 'Role SEO temp token unavailable.' );
	}
	$xnetvn_agent_hub_wp_envelope = 2000;
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO media setup failed.\n";
	exit( 1 );
}
$xnetvn_agent_hub_wp_users         = $xnetvn_agent_hub_wp_ledger['users'];
$xnetvn_agent_hub_wp_tokens        = $xnetvn_agent_hub_wp_ledger['tokens'];
$xnetvn_agent_hub_wp_ids           = $xnetvn_agent_hub_wp_ledger['ids'];
$xnetvn_agent_hub_wp_before        = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
// Owned ids created by this matrix for teardown; never printed.
$xnetvn_agent_hub_wp_owned_posts = array();
$xnetvn_agent_hub_wp_owned_media = array();

// Fixed synthetic samples: a 1px PNG feature and a 2x2 JPEG inline image.
// Both are generated once, small, and carry distinct content and alt text.
// Byte size, MIME and dimensions are measured locally before upload; the
// base64 itself is never logged.
$xnetvn_agent_hub_wp_pixel_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
$xnetvn_agent_hub_wp_photo_b64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODUK/9sAQwAFAwQEBAMFBAQEBQUFBgcMCAcHBwcPCwsJDBEPEhIRDxERExYcFxMUGhURERghGBodHR8fHxMXIiQiHiQcHh8e/9sAQwEFBQUHBgcOCAgOHhQRFB4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4e/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A4Xxp4n8S2/jHWoIPEOrRRR6hOkcaXsiqqiRgAADwAO1FFFfPx2R7D3P/2Q==';
$xnetvn_agent_hub_wp_samples   = array();
foreach ( array(
	'pixel' => $xnetvn_agent_hub_wp_pixel_b64,
	'photo' => $xnetvn_agent_hub_wp_photo_b64,
) as $xnetvn_agent_hub_wp_kind => $xnetvn_agent_hub_wp_encoded ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validate fixed synthetic samples, never executable code.
	$xnetvn_agent_hub_wp_raw = base64_decode( $xnetvn_agent_hub_wp_encoded, true );
	if ( ! is_string( $xnetvn_agent_hub_wp_raw ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
		echo "Role SEO media sample unavailable.\n";
		exit( 1 );
	}
	$xnetvn_agent_hub_wp_info = getimagesizefromstring( $xnetvn_agent_hub_wp_raw );
	if ( ! is_array( $xnetvn_agent_hub_wp_info ) || 1 > $xnetvn_agent_hub_wp_info[0] || 1 > $xnetvn_agent_hub_wp_info[1] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
		echo "Role SEO media sample invalid.\n";
		exit( 1 );
	}
	$xnetvn_agent_hub_wp_samples[ $xnetvn_agent_hub_wp_kind ] = array(
		'bytes'  => strlen( $xnetvn_agent_hub_wp_raw ),
		'mime'   => ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $xnetvn_agent_hub_wp_raw ),
		'width'  => $xnetvn_agent_hub_wp_info[0],
		'height' => $xnetvn_agent_hub_wp_info[1],
	);
}
unset( $xnetvn_agent_hub_wp_kind, $xnetvn_agent_hub_wp_encoded, $xnetvn_agent_hub_wp_raw, $xnetvn_agent_hub_wp_info );

// IMG-01: every writable-media role reads its own limits; the contributor
// key without a media toolset sees no media tool in listing or direct call.
$xnetvn_agent_hub_wp_admin_limits = null;
foreach ( array( 'admin', 'editor', 'author' ) as $xnetvn_agent_hub_wp_role ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'IMG-01-limits-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role ): bool {
			$response = xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_role, 'get-media-upload-limits', array() );
			$data     = $xnetvn_agent_hub_wp_success( $response );
			if ( ! is_array( $data ) ) {
				return false;
			}
			foreach ( array( 'max_upload_bytes', 'data_upload_max_bytes', 'remote_fetch_max_bytes', 'pixels_max', 'remote_fetch_enabled', 'allowed_mime_types' ) as $field ) {
				if ( ! array_key_exists( $field, $data ) ) {
					return false;
				}
			}
			if ( true === ( $data['remote_fetch_enabled'] ?? true ) ) {
				return false;
			}
			if ( 1 > (int) ( $data['data_upload_max_bytes'] ?? 0 ) || 1 > (int) ( $data['pixels_max'] ?? 0 ) ) {
				return false;
			}
			return in_array( 'image/png', (array) ( $data['allowed_mime_types'] ?? array() ), true ) && in_array( 'image/jpeg', (array) ( $data['allowed_mime_types'] ?? array() ), true );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-01-limits-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
	if ( 'admin' === $xnetvn_agent_hub_wp_role && $xnetvn_agent_hub_wp_ok ) {
		$xnetvn_agent_hub_wp_admin_limits = $xnetvn_agent_hub_wp_success( xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, 'admin', 'get-media-upload-limits', array() ) );
	}
}
unset( $xnetvn_agent_hub_wp_role );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-01-hidden-contributor',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_hidden ): bool {
		$list = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'], 'tools/list', array() );
		if ( 200 !== ( $list['status'] ?? 0 ) ) {
			return false;
		}
		$names = array_column( $list['body']['result']['tools'] ?? $list['body']['result']['items'] ?? array(), 'name' );
		if ( ! is_array( $names ) ) {
			return false;
		}
		foreach ( array( 'xnetvn-upload-media-from-data', 'xnetvn-upload-media-from-url', 'xnetvn-get-media', 'xnetvn-list-media', 'xnetvn-update-media', 'xnetvn-delete-media', 'xnetvn-get-media-upload-limits' ) as $hidden ) {
			if ( in_array( $hidden, $names, true ) ) {
				return false;
			}
		}
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'tools/call',
			array(
				'name'      => 'xnetvn-upload-media-from-data',
				'arguments' => (object) array(
					'filename'  => 'denied.png',
					'mime_type' => 'image/png',
				),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-01-hidden-contributor', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-01',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-01-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-01', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-02: each writable-media role uploads a PNG feature and a JPEG inline
// image; ids, MIME, owner and size hold and the two attachments stay apart.
foreach ( array( 'admin', 'editor', 'author' ) as $xnetvn_agent_hub_wp_role ) {
	foreach ( array(
		'feature' => 'png',
		'inline'  => 'jpg',
	) as $xnetvn_agent_hub_wp_kind => $xnetvn_agent_hub_wp_ext ) {
		$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
			$xnetvn_agent_hub_wp_timings,
			'IMG-02-upload-' . $xnetvn_agent_hub_wp_role . '-' . $xnetvn_agent_hub_wp_kind,
			static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_kind, $xnetvn_agent_hub_wp_ext, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_pixel_b64, $xnetvn_agent_hub_wp_photo_b64, &$xnetvn_agent_hub_wp_owned_media ): bool {
				$is_feature = 'feature' === $xnetvn_agent_hub_wp_kind;
				$created    = xnetvn_agent_hub_wp_role_seo_media_call(
					$xnetvn_agent_hub_wp_harness,
					$xnetvn_agent_hub_wp_role,
					'upload-media-from-data',
					array(
						'filename'    => 'lamp-' . $xnetvn_agent_hub_wp_kind . '-' . $xnetvn_agent_hub_wp_run_id . '-' . $xnetvn_agent_hub_wp_role . '.' . $xnetvn_agent_hub_wp_ext,
						'mime_type'   => $is_feature ? 'image/png' : 'image/jpeg',
						'data_base64' => $is_feature ? $xnetvn_agent_hub_wp_pixel_b64 : $xnetvn_agent_hub_wp_photo_b64,
						'alt_text'    => $is_feature ? 'Desk lamp on a study table' : 'Lamp beside a book',
					)
				);
				$data       = $xnetvn_agent_hub_wp_success( $created );
				if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || 1 > (int) ( $data['media_details']['filesize'] ?? 0 ) ) {
					return false;
				}
				if ( ( $data['mime_type'] ?? '' ) !== ( $is_feature ? 'image/png' : 'image/jpeg' ) ) {
					return false;
				}
				if ( (int) ( $data['author'] ?? 0 ) !== (int) ( $xnetvn_agent_hub_wp_users[ $xnetvn_agent_hub_wp_role ] ?? 0 ) ) {
					return false;
				}
				$xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ][ $xnetvn_agent_hub_wp_kind ] = (int) $data['id'];
				return true;
			}
		);
		xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-02-upload-' . $xnetvn_agent_hub_wp_role . '-' . $xnetvn_agent_hub_wp_kind, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
		$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
	}
}
unset( $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_kind, $xnetvn_agent_hub_wp_ext );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-02',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_owned_media ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-02-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		foreach ( array( 'admin', 'editor', 'author' ) as $role ) {
			if ( ( $xnetvn_agent_hub_wp_owned_media[ $role ]['feature'] ?? 0 ) === ( $xnetvn_agent_hub_wp_owned_media[ $role ]['inline'] ?? 0 ) ) {
				return false;
			}
		}
		$after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
		return $xnetvn_agent_hub_wp_before === $after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-02', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-03: the owner updates alt text and caption on its feature image, pins
// it as the featured image of its own post, and the inline image never
// takes the featured slot.
foreach ( array(
	'admin'  => 101,
	'editor' => 101,
	'author' => null,
) as $xnetvn_agent_hub_wp_role => $xnetvn_agent_hub_wp_logical_post ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'IMG-03-update-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_owned_media ): bool {
			if ( ! isset( $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'] ) ) {
				return false;
			}
			// Real owned ids may fall inside the ledger band, so they travel
			// through the direct tool path instead of ledger resolution.
			$updated = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'update-media',
				array(
					'id'       => $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'],
					'alt_text' => 'Lamp beside a book',
					'caption'  => 'Placement example',
				)
			);
			$data    = $xnetvn_agent_hub_wp_success( $updated );
			return is_array( $data ) && 'Lamp beside a book' === ( $data['alt_text'] ?? null ) && str_contains( (string) ( $data['caption'] ?? '' ), 'Placement example' );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-03-update-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
	$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'IMG-03-featured-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_logical_post, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_owned_media, &$xnetvn_agent_hub_wp_owned_posts ): bool {
			if ( ! isset( $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'], $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['inline'] ) ) {
				return false;
			}
			$post_id = $xnetvn_agent_hub_wp_logical_post;
			if ( null === $post_id ) {
				$created = xnetvn_agent_hub_wp_role_seo_media_call(
					$xnetvn_agent_hub_wp_harness,
					$xnetvn_agent_hub_wp_role,
					'create-post',
					array(
						'title' => 'Lamp placement draft',
						'slug'  => 'lamp-draft-' . $xnetvn_agent_hub_wp_run_id . '-' . $xnetvn_agent_hub_wp_role,
					)
				);
				$data    = $xnetvn_agent_hub_wp_success( $created );
				if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
					return false;
				}
				$post_id = (int) $data['id'];
				$xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_role ] = $post_id;
			} else {
				$post_id = (int) ( $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => $xnetvn_agent_hub_wp_logical_post ) )['id'] ?? 0 );
				if ( 1 > $post_id ) {
					return false;
				}
			}
			$pinned      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'set-featured-media',
				array(
					'id'       => $post_id,
					'media_id' => $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'],
				)
			);
			$pinned_data = $xnetvn_agent_hub_wp_success( $pinned );
			if ( ! is_array( $pinned_data ) || (int) ( $pinned_data['featured_media'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'] ) {
				return false;
			}
			$read      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'get-media',
				array( 'id' => $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'] )
			);
			$read_data = $xnetvn_agent_hub_wp_success( $read );
			if ( ! is_array( $read_data ) || 'Lamp beside a book' !== ( $read_data['alt_text'] ?? null ) || ! str_contains( (string) ( $read_data['caption'] ?? '' ), 'Placement example' ) ) {
				return false;
			}
			return (int) ( $pinned_data['featured_media'] ?? 0 ) !== $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['inline'];
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-03-featured-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_logical_post );
// Restore the fixture post to no featured image before the aggregate.
try {
	$xnetvn_agent_hub_wp_restored = xnetvn_agent_hub_wp_role_seo_media_call(
		$xnetvn_agent_hub_wp_harness,
		'editor',
		'set-featured-media',
		array(
			'id'       => 101,
			'media_id' => 0,
		)
	);
	if ( 200 !== ( $xnetvn_agent_hub_wp_restored['status'] ?? 0 ) || true === ( $xnetvn_agent_hub_wp_restored['body']['result']['isError'] ?? false ) ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_failed = true;
}
$xnetvn_agent_hub_wp_after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok    = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-03',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-03-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-03', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-04: the owned post carries both images inline; raw and rendered reads
// keep the figure, source, alt text and caption while excerpt, status and
// terms stay exactly as before the content-only update.
foreach ( array( 'admin', 'editor', 'author' ) as $xnetvn_agent_hub_wp_role ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'IMG-04-inline-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_owned_media, $xnetvn_agent_hub_wp_owned_posts ): bool {
			if ( ! isset( $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'], $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['inline'] ) ) {
				return false;
			}
			if ( 'author' === $xnetvn_agent_hub_wp_role && ! isset( $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_role ] ) ) {
				return false;
			}
			$post_id = 'author' === $xnetvn_agent_hub_wp_role ? $xnetvn_agent_hub_wp_owned_posts[ $xnetvn_agent_hub_wp_role ] : (int) ( $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) )['id'] ?? 0 );
			if ( 1 > $post_id ) {
				return false;
			}
			// Pin an explicit excerpt first: content-derived auto excerpts
			// would otherwise move with the inline update on empty posts.
			$excerpted      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'update-post',
				array(
					'id'      => $post_id,
					'excerpt' => 'Lamp summary.',
				)
			);
			$excerpted_data = $xnetvn_agent_hub_wp_success( $excerpted );
			if ( ! is_array( $excerpted_data ) || ! str_contains( (string) ( $excerpted_data['excerpt'] ?? '' ), 'Lamp summary.' ) ) {
				return false;
			}
			$feature      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'get-media',
				array( 'id' => $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['feature'] )
			);
			$feature_data = $xnetvn_agent_hub_wp_success( $feature );
			$inline       = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'get-media',
				array( 'id' => $xnetvn_agent_hub_wp_owned_media[ $xnetvn_agent_hub_wp_role ]['inline'] )
			);
			$inline_data  = $xnetvn_agent_hub_wp_success( $inline );
			if ( ! is_array( $feature_data ) || ! is_array( $inline_data ) || '' === ( $feature_data['source_url'] ?? '' ) || '' === ( $inline_data['source_url'] ?? '' ) ) {
				return false;
			}
			$before      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'get-post',
				array( 'id' => $post_id )
			);
			$before_data = $xnetvn_agent_hub_wp_success( $before );
			if ( ! is_array( $before_data ) ) {
				return false;
			}
			$html         = '<figure><img src="' . $feature_data['source_url'] . '" alt="Desk lamp on a study table"><figcaption>Placement example</figcaption></figure><figure><img src="' . $inline_data['source_url'] . '" alt="Lamp beside a book"><figcaption>Second example</figcaption></figure>';
			$updated      = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
				'update-post',
				array(
					'id'      => $post_id,
					'content' => $html,
				)
			);
			$updated_data = $xnetvn_agent_hub_wp_success( $updated );
			if ( ! is_array( $updated_data ) || ! str_contains( (string) ( $updated_data['content'] ?? '' ), $feature_data['source_url'] ) || ! str_contains( (string) ( $updated_data['content'] ?? '' ), $inline_data['source_url'] ) ) {
				return false;
			}
			foreach ( array( 'title', 'excerpt', 'status', 'author' ) as $field ) {
				if ( ( $updated_data[ $field ] ?? null ) !== ( $before_data[ $field ] ?? null ) ) {
					return false;
				}
			}
			foreach ( array( 'raw', 'rendered' ) as $format ) {
				$read      = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_role ],
					'get-post',
					array(
						'id'             => $post_id,
						'content_format' => $format,
					)
				);
				$read_data = $xnetvn_agent_hub_wp_success( $read );
				if ( ! is_array( $read_data ) || ! str_contains( (string) ( $read_data['content'] ?? '' ), $inline_data['source_url'] ) || ! str_contains( (string) ( $read_data['content'] ?? '' ), 'Desk lamp on a study table' ) || ! str_contains( (string) ( $read_data['content'] ?? '' ), 'Placement example' ) ) {
					return false;
				}
			}
			return true;
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-04-inline-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_role );
$xnetvn_agent_hub_wp_after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok    = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-04',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-04-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-04', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-05: narrowed scopes are refused before any file lands. The contributor
// and read keys hide media tools; subscriber and banned stop at HTTP 403.
// A positive control proves the sample and the fixture post are valid.
$xnetvn_agent_hub_wp_counts_05_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok               = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-05-upload-contributor',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_pixel_b64 ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_contrib_open'],
			'tools/call',
			array(
				'name'      => 'xnetvn-upload-media-from-data',
				'arguments' => (object) array(
					'filename'    => 'denied-contrib.png',
					'mime_type'   => 'image/png',
					'data_base64' => $xnetvn_agent_hub_wp_pixel_b64,
				),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05-upload-contributor', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
foreach ( array( 'subscriber', 'banned' ) as $xnetvn_agent_hub_wp_role ) {
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		'IMG-05-upload-' . $xnetvn_agent_hub_wp_role,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_pixel_b64 ): bool {
			$response = xnetvn_agent_hub_wp_role_seo_media_call(
				$xnetvn_agent_hub_wp_harness,
				$xnetvn_agent_hub_wp_role,
				'upload-media-from-data',
				array(
					'filename'    => 'denied-' . $xnetvn_agent_hub_wp_role . '.png',
					'mime_type'   => 'image/png',
					'data_base64' => $xnetvn_agent_hub_wp_pixel_b64,
				)
			);
			return 403 === ( $response['status'] ?? 0 );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05-upload-' . $xnetvn_agent_hub_wp_role, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_role );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-05-update-readkey',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_harness ): bool {
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-update-media',
				'arguments' => (object) array(
					'id'       => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 401 ) )['id'],
					'alt_text' => 'Denied',
				),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05-update-readkey', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-05-featured-readkey',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_temp_tokens, $xnetvn_agent_hub_wp_hidden, $xnetvn_agent_hub_wp_harness ): bool {
		$ids      = $xnetvn_agent_hub_wp_harness->resolveIds(
			array(
				'post'  => 101,
				'media' => 401,
			)
		);
		$response = $xnetvn_agent_hub_wp_send(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_temp_tokens['temp_read'],
			'tools/call',
			array(
				'name'      => 'xnetvn-set-featured-media',
				'arguments' => (object) array(
					'id'       => $ids['post'],
					'media_id' => $ids['media'],
				),
			)
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05-featured-readkey', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-05-control',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_pixel_b64 ): bool {
		$created = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'editor',
			'upload-media-from-data',
			array(
				'filename'    => 'control-sample.png',
				'mime_type'   => 'image/png',
				'data_base64' => $xnetvn_agent_hub_wp_pixel_b64,
			)
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$deleted = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['editor'],
			'delete-media',
			array( 'id' => (int) $data['id'] )
		);
		$gone    = $xnetvn_agent_hub_wp_success( $deleted );
		return is_array( $gone ) && true === ( $gone['deleted'] ?? false );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05-control', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-05',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_counts_05_before, $xnetvn_agent_hub_wp_counts_equal ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-05-' ) && 'IMG-05-control' !== $id && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		if ( true !== ( $xnetvn_agent_hub_wp_harness->cases()['IMG-05-control']['ok'] ?? false ) ) {
			return false;
		}
		$after_digest = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
		if ( $xnetvn_agent_hub_wp_before !== $after_digest ) {
			return false;
		}
		$after_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		return $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_05_before, $after_counts );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-05', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-06: the author cannot rewrite the other author's attachment, and the
// destructive media tool stays policy-hidden once delete_post is denied,
// while embedding its readable source in the author's own post stays allowed.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-06-update-denied',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$response = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'author',
			'update-media',
			array(
				'id'       => 402,
				'alt_text' => 'Denied',
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'forbidden' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-06-update-denied', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-06-delete-denied',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_hidden ): bool {
		$response = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'author',
			'delete-media',
			array( 'id' => 402 )
		);
		return $xnetvn_agent_hub_wp_hidden( $response );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-06-delete-denied', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-06-inline-allowed',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_owned_posts ): bool {
		if ( ! isset( $xnetvn_agent_hub_wp_owned_posts['author'] ) || ! is_int( $xnetvn_agent_hub_wp_owned_posts['author'] ) ) {
			return false;
		}
		$media      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'get-media',
			array( 'id' => $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 402 ) )['id'] )
		);
		$media_data = $xnetvn_agent_hub_wp_success( $media );
		if ( ! is_array( $media_data ) || '' === ( $media_data['source_url'] ?? '' ) ) {
			return false;
		}
		$updated      = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['author'],
			'update-post',
			array(
				'id'      => $xnetvn_agent_hub_wp_owned_posts['author'],
				'content' => '<p>Reference</p><figure><img src="' . $media_data['source_url'] . '" alt="Shared fixture image"><figcaption>Shared example</figcaption></figure>',
			)
		);
		$updated_data = $xnetvn_agent_hub_wp_success( $updated );
		return is_array( $updated_data ) && str_contains( (string) ( $updated_data['content'] ?? '' ), $media_data['source_url'] );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-06-inline-allowed', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-06',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-06-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return $xnetvn_agent_hub_wp_before === $xnetvn_agent_hub_wp_after;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-06', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-07: every invalid input is refused with its own reason before any
// file lands; the attachment count and the fixture featured slot never move.
$xnetvn_agent_hub_wp_counts_07_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_featured_before  = xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, 'editor', 'get-post', array( 'id' => 101 ) );
$xnetvn_agent_hub_wp_featured_slot    = ( 200 === ( $xnetvn_agent_hub_wp_featured_before['status'] ?? 0 ) ) ? ( $xnetvn_agent_hub_wp_featured_before['body']['result']['structuredContent']['featured_media'] ?? null ) : null;
foreach ( array(
	array( 'IMG-07-malformed-base64', 'invalid_params', array( 'data_base64' => '!!!' ) ),
	array( 'IMG-07-noncanonical-base64', 'invalid_params', array( 'data_base64' => 'AB==' ) ),
	array( 'IMG-07-mime-mismatch', 'file_type_not_allowed', array( 'mime_type' => 'image/jpeg' ) ),
	array( 'IMG-07-traversal-filename', 'file_type_not_allowed', array( 'filename' => '../x.png' ) ),
	array( 'IMG-07-double-extension', 'file_type_not_allowed', array( 'filename' => 'a.php.png' ) ),
	array( 'IMG-07-script-extension', 'file_type_not_allowed', array( 'filename' => 'shell.php' ) ),
) as $xnetvn_agent_hub_wp_probe ) {
	list( $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_change ) = $xnetvn_agent_hub_wp_probe;
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		$xnetvn_agent_hub_wp_case,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_change, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_pixel_b64 ): bool {
			$response = xnetvn_agent_hub_wp_role_seo_media_call(
				$xnetvn_agent_hub_wp_harness,
				'admin',
				'upload-media-from-data',
				array_merge(
					array(
						'filename'    => 'lamp-invalid-' . $xnetvn_agent_hub_wp_run_id . '.png',
						'mime_type'   => 'image/png',
						'data_base64' => $xnetvn_agent_hub_wp_pixel_b64,
					),
					$xnetvn_agent_hub_wp_change
				)
			);
			return $xnetvn_agent_hub_wp_denied( $response, $xnetvn_agent_hub_wp_reason );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_probe, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_change );
foreach ( array(
	array( 'IMG-07-unknown-attachment', 'not_found', 'get-media' ),
	array( 'IMG-07-post-as-attachment', 'not_found', 'get-media' ),
) as $xnetvn_agent_hub_wp_probe ) {
	list( $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_tool_name ) = $xnetvn_agent_hub_wp_probe;
	$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		$xnetvn_agent_hub_wp_case,
		static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_tool_name, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_ids ): bool {
			$response = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_tokens['admin'],
				$xnetvn_agent_hub_wp_tool_name,
				array( 'id' => 'IMG-07-unknown-attachment' === $xnetvn_agent_hub_wp_case ? 999999999 : (int) $xnetvn_agent_hub_wp_ids[101] )
			);
			return $xnetvn_agent_hub_wp_denied( $response, $xnetvn_agent_hub_wp_reason );
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
unset( $xnetvn_agent_hub_wp_probe, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_reason, $xnetvn_agent_hub_wp_tool_name );
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-07',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_counts_07_before, $xnetvn_agent_hub_wp_featured_slot, $xnetvn_agent_hub_wp_counts_equal ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-07-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		$after_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		if ( ! $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_07_before, $after_counts ) ) {
			return false;
		}
		$read = xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, 'editor', 'get-post', array( 'id' => 101 ) );
		if ( 200 !== ( $read['status'] ?? 0 ) ) {
			return false;
		}
		return ( $read['body']['result']['structuredContent']['featured_media'] ?? null ) === $xnetvn_agent_hub_wp_featured_slot;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-07', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-08: the owned small ceiling accepts an exact-size image, refuses one
// byte over and a pixel-spoofed header, then settings return to the snapshot.
$xnetvn_agent_hub_wp_counts_08_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
try {
	$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'media-small' );
	$xnetvn_agent_hub_wp_small      = xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, 'admin', 'get-media-upload-limits', array() );
	$xnetvn_agent_hub_wp_small_data = ( 200 === ( $xnetvn_agent_hub_wp_small['status'] ?? 0 ) ) ? ( $xnetvn_agent_hub_wp_small['body']['result']['structuredContent'] ?? null ) : null;
	if ( ! is_array( $xnetvn_agent_hub_wp_small_data ) || 1 > (int) ( $xnetvn_agent_hub_wp_small_data['data_upload_max_bytes'] ?? 0 ) || 4096 < (int) ( $xnetvn_agent_hub_wp_small_data['data_upload_max_bytes'] ?? 0 ) ) {
		throw new RuntimeException( 'Role SEO small ceiling unavailable.' );
	}
	$xnetvn_agent_hub_wp_ceiling = (int) $xnetvn_agent_hub_wp_small_data['data_upload_max_bytes'];
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO media ceiling setup failed.\n";
	exit( 1 );
}
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-08-at-ceiling',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_exact_png, $xnetvn_agent_hub_wp_pixel_b64, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_ceiling ): bool {
		$created = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-ceiling-' . $xnetvn_agent_hub_wp_run_id . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => $xnetvn_agent_hub_wp_exact_png( $xnetvn_agent_hub_wp_pixel_b64, $xnetvn_agent_hub_wp_ceiling ),
			)
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || (int) ( $data['media_details']['filesize'] ?? 0 ) !== $xnetvn_agent_hub_wp_ceiling ) {
			return false;
		}
		$deleted = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'delete-media',
			array( 'id' => (int) $data['id'] )
		);
		$gone    = $xnetvn_agent_hub_wp_success( $deleted );
		return is_array( $gone ) && true === ( $gone['deleted'] ?? false );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-08-at-ceiling', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-08-over-ceiling',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_ceiling ): bool {
		$over     = base64_encode( str_repeat( "\0", $xnetvn_agent_hub_wp_ceiling + 1 ) );
		$response = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-over-' . $xnetvn_agent_hub_wp_run_id . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => $over,
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'payload_too_large' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-08-over-ceiling', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-08-pixel-spoof',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_pixel_b64 ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed synthetic PNG header, never executable code.
		$png = base64_decode( $xnetvn_agent_hub_wp_pixel_b64, true );
		if ( ! is_string( $png ) ) {
			return false;
		}
		$header   = pack( 'NN', 100000, 100000 ) . substr( $png, 24, 5 );
		$spoof    = substr( $png, 0, 16 ) . $header . pack( 'N', crc32( 'IHDR' . $header ) ) . substr( $png, 33 );
		$response = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-spoof-' . $xnetvn_agent_hub_wp_run_id . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $spoof ),
			)
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'image_too_large' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-08-pixel-spoof', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-08-restored',
	static function () use ( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_admin_limits ): bool {
		// The reset restores the upload ceilings this case checks, but it also
		// restores the default rate-limit budget of the site while the matrix
		// still has cases to run: the auth-layer denials this matrix charged
		// into the shared per-IP bucket stay there, so every later request
		// would be refused with 429. The matrix owns its budget for the whole
		// run and re-raises it right after the reset; the fixture cleanup
		// restores the site snapshot afterwards.
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-high' );
		$limits = xnetvn_agent_hub_wp_role_seo_media_call( $xnetvn_agent_hub_wp_harness, 'admin', 'get-media-upload-limits', array() );
		if ( 200 !== ( $limits['status'] ?? 0 ) ) {
			return false;
		}
		$data = $limits['body']['result']['structuredContent'] ?? null;
		if ( ! is_array( $data ) || ! is_array( $xnetvn_agent_hub_wp_admin_limits ) ) {
			return false;
		}
		return (int) ( $data['data_upload_max_bytes'] ?? 0 ) === (int) ( $xnetvn_agent_hub_wp_admin_limits['data_upload_max_bytes'] ?? -1 )
			&& (int) ( $data['pixels_max'] ?? 0 ) === (int) ( $xnetvn_agent_hub_wp_admin_limits['pixels_max'] ?? -1 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-08-restored', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_after  = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-08',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_after, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_counts_08_before, $xnetvn_agent_hub_wp_counts_equal ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-08-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		if ( $xnetvn_agent_hub_wp_before !== $xnetvn_agent_hub_wp_after ) {
			return false;
		}
		$after_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		return $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_08_before, $after_counts );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-08', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-09: remote fetching stays disabled by default; the URL tool is refused
// before any DNS or download and no attachment appears.
$xnetvn_agent_hub_wp_counts_09_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok               = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-09-url-disabled',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_denied ): bool {
		$response = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-url',
			array( 'url' => 'https://example.test/image.png' )
		);
		return $xnetvn_agent_hub_wp_denied( $response, 'remote_fetch_disabled' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-09-url-disabled', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-09',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_counts_09_before, $xnetvn_agent_hub_wp_counts_equal ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'IMG-09-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		$after_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		return $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_09_before, $after_counts );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-09', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// IMG-10: reconcile every uncertain mutation through the ledger receipt and
// prove teardown removes originals, generated sizes and temp state while
// options return to the snapshot. Multisite quota belongs to ticket G and is
// recorded NOT_APPLICABLE on single-site, never counted as a pass.
$xnetvn_agent_hub_wp_counts_10_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
$xnetvn_agent_hub_wp_ok               = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-10-reconcile',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_pixel_b64, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site ): bool {
		$created = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-reconcile-' . $xnetvn_agent_hub_wp_run_id . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => $xnetvn_agent_hub_wp_pixel_b64,
			)
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return false;
		}
		$receipt = (int) $data['id'];
		$read    = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-media',
			array( 'id' => $receipt )
		);
		if ( ! is_array( $xnetvn_agent_hub_wp_success( $read ) ) ) {
			return false;
		}
		$deleted = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'delete-media',
			array( 'id' => $receipt )
		);
		$gone    = $xnetvn_agent_hub_wp_success( $deleted );
		if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
			return false;
		}
		$reread = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-media',
			array( 'id' => $receipt )
		);
		return $xnetvn_agent_hub_wp_denied( $reread, 'not_found' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-10-reconcile', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-10-cleanup',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$xnetvn_agent_hub_wp_envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site ): bool {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}
		$image = imagecreatetruecolor( 600, 400 );
		if ( false === $image ) {
			return false;
		}
		imagefilledrectangle( $image, 0, 0, 599, 399, imagecolorallocate( $image, 222, 184, 135 ) );
		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();
		imagedestroy( $image );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return false;
		}
		$created = xnetvn_agent_hub_wp_role_seo_media_call(
			$xnetvn_agent_hub_wp_harness,
			'admin',
			'upload-media-from-data',
			array(
				'filename'    => 'lamp-sizes-' . $xnetvn_agent_hub_wp_run_id . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => base64_encode( $bytes ),
			)
		);
		$data    = $xnetvn_agent_hub_wp_success( $created );
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) || array() === ( $data['media_details']['sizes'] ?? array() ) ) {
			return false;
		}
		$deleted = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'delete-media',
			array( 'id' => (int) $data['id'] )
		);
		$gone    = $xnetvn_agent_hub_wp_success( $deleted );
		if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
			return false;
		}
		$reread = $xnetvn_agent_hub_wp_tool(
			$xnetvn_agent_hub_wp_client,
			$xnetvn_agent_hub_wp_envelope,
			$xnetvn_agent_hub_wp_tokens['admin'],
			'get-media',
			array( 'id' => (int) $data['id'] )
		);
		return $xnetvn_agent_hub_wp_denied( $reread, 'not_found' );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-10-cleanup', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
$xnetvn_agent_hub_wp_ok     = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'IMG-10',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_before, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_counts_10_before, $xnetvn_agent_hub_wp_counts_equal ): bool {
		foreach ( array( 'IMG-10-reconcile', 'IMG-10-cleanup' ) as $id ) {
			$cases = $xnetvn_agent_hub_wp_harness->cases();
			if ( true !== ( $cases[ $id ]['ok'] ?? false ) ) {
				return false;
			}
		}
		$after_digest = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'content-digest' );
		if ( $xnetvn_agent_hub_wp_before !== $after_digest ) {
			return false;
		}
		$after_counts = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		return $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_10_before, $after_counts );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'IMG-10', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// Teardown: delete every attachment and post this matrix created, drop temp
// keys and restore site settings; the runner owns final cleanup.
foreach ( $xnetvn_agent_hub_wp_owned_media as $xnetvn_agent_hub_wp_owned_role => $xnetvn_agent_hub_wp_owned_pair ) {
	foreach ( (array) $xnetvn_agent_hub_wp_owned_pair as $xnetvn_agent_hub_wp_owned_id ) {
		if ( ! is_int( $xnetvn_agent_hub_wp_owned_id ) ) {
			$xnetvn_agent_hub_wp_failed = true;
			continue;
		}
		try {
			// delete-media is a destructive-tier tool, so teardown always uses
			// a destructive-tier key (editor, admin fallback) instead of the
			// owner-role token: author keys only hold the write tier and see
			// this tool policy-hidden.
			$xnetvn_agent_hub_wp_cleanup_token = $xnetvn_agent_hub_wp_tokens['editor'] ?? null;
			if ( ! is_string( $xnetvn_agent_hub_wp_cleanup_token ) || '' === $xnetvn_agent_hub_wp_cleanup_token ) {
				$xnetvn_agent_hub_wp_failed = true;
				continue;
			}
			$xnetvn_agent_hub_wp_deleted = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_client,
				$xnetvn_agent_hub_wp_envelope,
				$xnetvn_agent_hub_wp_cleanup_token,
				'delete-media',
				array( 'id' => $xnetvn_agent_hub_wp_owned_id )
			);
			if ( $xnetvn_agent_hub_wp_hidden( $xnetvn_agent_hub_wp_deleted ) && isset( $xnetvn_agent_hub_wp_tokens['admin'] ) && is_string( $xnetvn_agent_hub_wp_tokens['admin'] ) ) {
				$xnetvn_agent_hub_wp_deleted = $xnetvn_agent_hub_wp_tool(
					$xnetvn_agent_hub_wp_client,
					$xnetvn_agent_hub_wp_envelope,
					$xnetvn_agent_hub_wp_tokens['admin'],
					'delete-media',
					array( 'id' => $xnetvn_agent_hub_wp_owned_id )
				);
			}
			$xnetvn_agent_hub_wp_gone = $xnetvn_agent_hub_wp_success( $xnetvn_agent_hub_wp_deleted );
			if ( is_array( $xnetvn_agent_hub_wp_gone ) && true === ( $xnetvn_agent_hub_wp_gone['deleted'] ?? false ) ) {
				continue;
			}
			// An attachment that is already gone counts as cleaned up: the
			// teardown only has to leave nothing behind, never to prove that
			// this matrix performed the deletion itself.
			if ( ! $xnetvn_agent_hub_wp_denied( $xnetvn_agent_hub_wp_deleted, 'not_found' ) ) {
				$xnetvn_agent_hub_wp_failed = true;
			}
		} catch ( \Throwable ) {
			$xnetvn_agent_hub_wp_failed = true;
		}
	}
}
unset( $xnetvn_agent_hub_wp_owned_role, $xnetvn_agent_hub_wp_owned_pair, $xnetvn_agent_hub_wp_owned_id, $xnetvn_agent_hub_wp_cleanup_token, $xnetvn_agent_hub_wp_deleted, $xnetvn_agent_hub_wp_gone );
foreach ( $xnetvn_agent_hub_wp_owned_posts as $xnetvn_agent_hub_wp_owned_id ) {
	if ( ! is_int( $xnetvn_agent_hub_wp_owned_id ) ) {
		$xnetvn_agent_hub_wp_failed = true;
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
		// A post that another actor already deleted answers not_found. The
		// teardown only has to leave nothing behind, so that outcome is
		// already clean; every other failure stays red.
		if ( ! $xnetvn_agent_hub_wp_denied( $xnetvn_agent_hub_wp_deleted, 'not_found' ) ) {
			$xnetvn_agent_hub_wp_failed = true;
		}
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
}
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
	'suite'     => 'role-seo-media',
	'target'    => $xnetvn_agent_hub_wp_site,
	'run_id'    => $xnetvn_agent_hub_wp_run_id,
	'blog'      => (int) $xnetvn_agent_hub_wp_blog,
	'cases'     => $xnetvn_agent_hub_wp_harness->cases(),
	'declared'  => xnetvn_agent_hub_wp_role_seo_media_declare_cases(),
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
 * A case that failed while the shared lab refused the run with a rate limit
 * reports that environment limit instead of a product defect. The case
 * stays red, so the run never turns green on a refusal of the lab.
 *
 * @param RoleSeoHarness $harness Harness.
 * @param string         $case_id Case id.
 * @param bool           $ok Outcome.
 * @param string         $reason Allowlisted reason, never raw input.
 */
function xnetvn_agent_hub_wp_role_seo_record( RoleSeoHarness $harness, string $case_id, bool $ok, string $reason = 'unavailable' ): void {
	if ( ! $ok && xnetvn_agent_hub_wp_role_seo_media_limited() ) {
		$reason = 'rate_limited';
	}
	$harness->recordCase( $case_id, $ok, $reason );
}
