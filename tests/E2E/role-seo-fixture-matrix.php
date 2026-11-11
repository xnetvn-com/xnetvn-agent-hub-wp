<?php
/**
 * Owned role/SEO fixture matrix: FX-01 through FX-09 declarations and the
 * MCP-level cases executed through real HTTPS.
 *
 * Lifecycle cases (FX-01, FX-04, FX-05, FX-06, FX-08, FX-09) are declared
 * here and executed by infra/remote/run-role-seo.sh, which owns process exit
 * codes, traps and locks. This file executes FX-02, FX-03 and FX-07 against
 * the owned fixture state and prints one secret-free report.
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

/**
 * Declare the nine fixture cases with their owner and applicability.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_declare_cases(): array {
	return array(
		array(
			'id'           => 'FX-01',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array(),
			'variants'     => array( 'guard-cli', 'guard-env', 'guard-host', 'guard-run-id' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 2,
		),
		array(
			'id'           => 'FX-02',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
			'scope_modes'  => array( 'seeded' ),
			'variants'     => array( 'shape', 'banned-no-capability', 'mail-sink' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-03',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
			'scope_modes'  => array( 'read', 'write', 'destructive', 'admin' ),
			'variants'     => array( 'identity', 'token-not-disclosed' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-04',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array( 'seeded' ),
			'variants'     => array( 'idempotent-same-run', 'refuse-foreign-run' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-05',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array( 'seeded' ),
			'variants'     => array( 'foreign-user', 'foreign-option', 'symlink-state' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 2,
		),
		array(
			'id'           => 'FX-06',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array( 'seeded' ),
			'variants'     => array( 'fail-mid-seed', 'restore-baseline' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-07',
			'owner'        => 'matrix',
			'roles'        => array( 'author' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'canary', 'forced-failure' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-08',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array(),
			'variants'     => array( 'normal-exit', 'assertion-exit', 'sigterm-child', 'sigkill-recover' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'FX-09',
			'owner'        => 'runner',
			'roles'        => array(),
			'scope_modes'  => array(),
			'variants'     => array( 'lock-contention', 'unknown-group' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 2,
		),
	);
}

/**
 * Fixed actor expectations seeded by role-seo-fixture.php.
 *
 * Tool names use the installed adapter mapping: the ticket shorthand
 * xnetvn-get-session resolves to xnetvn-get-session-info via discovery.
 *
 * @return array<string,array{role:string,tier:string}>
 */
function xnetvn_agent_hub_wp_role_seo_actor_plan(): array {
	return array(
		'admin'       => array(
			'role' => 'administrator',
			'tier' => 'admin',
		),
		'editor'      => array(
			'role' => 'editor',
			'tier' => 'destructive',
		),
		'author'      => array(
			'role' => 'author',
			'tier' => 'write',
		),
		'author_b'    => array(
			'role' => 'author',
			'tier' => 'write',
		),
		'contributor' => array(
			'role' => 'contributor',
			'tier' => 'write',
		),
		'subscriber'  => array(
			'role' => 'subscriber',
			'tier' => 'read',
		),
		'banned'      => array(
			'role' => 'banned',
			'tier' => 'read',
		),
	);
}

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

$xnetvn_agent_hub_wp_started  = hrtime( true );
$xnetvn_agent_hub_wp_timings  = array();
$xnetvn_agent_hub_wp_site     = getenv( 'XNETVN_MATRIX_SITE' );
$xnetvn_agent_hub_wp_run_id   = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
$xnetvn_agent_hub_wp_blog_raw = getenv( 'XNETVN_ROLE_SEO_BLOG' );
$xnetvn_agent_hub_wp_blog     = ( is_string( $xnetvn_agent_hub_wp_blog_raw ) && '' !== $xnetvn_agent_hub_wp_blog_raw ) ? $xnetvn_agent_hub_wp_blog_raw : '1';
if ( ! in_array( $xnetvn_agent_hub_wp_site, array( 'wp69', 'wp71' ), true ) || ! is_string( $xnetvn_agent_hub_wp_run_id ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $xnetvn_agent_hub_wp_run_id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_blog ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_host = getenv( 'wp71' === $xnetvn_agent_hub_wp_site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' );
if ( ! is_string( $xnetvn_agent_hub_wp_host ) || '' === $xnetvn_agent_hub_wp_host ) {
	exit( 2 );
}
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
		'run_id'        => $xnetvn_agent_hub_wp_run_id,
		'private_state' => $xnetvn_agent_hub_wp_state_file,
	),
	$xnetvn_agent_hub_wp_client,
	$xnetvn_agent_hub_wp_ledger
);
$xnetvn_agent_hub_wp_plan    = xnetvn_agent_hub_wp_role_seo_actor_plan();
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

// FX-02: owned shape, distinct authors and a capability-less banned actor.
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'FX-02',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_plan ): bool {
		$users = $xnetvn_agent_hub_wp_ledger['users'];
		if ( array_keys( $users ) !== array_keys( $xnetvn_agent_hub_wp_plan ) ) {
			return false;
		}
		$ids = array_values( $users );
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || 1 > $id ) {
				return false;
			}
		}
		if ( count( array_unique( $ids ) ) !== count( $ids ) || $users['author'] === $users['author_b'] ) {
			return false;
		}
		foreach ( array_keys( $xnetvn_agent_hub_wp_plan ) as $alias ) {
			$tokens = $xnetvn_agent_hub_wp_ledger['tokens'];
			if ( ! isset( $tokens[ $alias ] ) || ! is_string( $tokens[ $alias ] ) || '' === $tokens[ $alias ] ) {
				return false;
			}
		}
		$denied = $xnetvn_agent_hub_wp_harness->call( 'banned', 'get-session-info', array() );
		return 403 === ( $denied['status'] ?? 0 );
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'FX-02', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// FX-03: per-actor session identity; subscribers and banned stay at HTTP 403.
foreach ( $xnetvn_agent_hub_wp_plan as $xnetvn_agent_hub_wp_alias => $xnetvn_agent_hub_wp_expect ) {
	$xnetvn_agent_hub_wp_case = 'FX-03-' . str_replace( '_', '-', $xnetvn_agent_hub_wp_alias );
	$xnetvn_agent_hub_wp_ok   = $xnetvn_agent_hub_wp_time(
		$xnetvn_agent_hub_wp_timings,
		$xnetvn_agent_hub_wp_case,
		static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_ledger, $xnetvn_agent_hub_wp_alias, $xnetvn_agent_hub_wp_expect ): bool {
			$response = $xnetvn_agent_hub_wp_harness->call( $xnetvn_agent_hub_wp_alias, 'get-session-info', array() );
			if ( in_array( $xnetvn_agent_hub_wp_alias, array( 'subscriber', 'banned' ), true ) ) {
				return 403 === ( $response['status'] ?? 0 );
			}
			if ( 200 !== ( $response['status'] ?? 0 ) ) {
				return false;
			}
			$data = $response['body']['result']['structuredContent'] ?? null;
			if ( ! is_array( $data ) || true === ( $response['body']['result']['isError'] ?? false ) ) {
				return false;
			}
			$user = $data['user'] ?? null;
			$key  = $data['key'] ?? null;
			if ( ! is_array( $user ) || ! is_array( $key ) ) {
				return false;
			}
			if ( ( $user['id'] ?? 0 ) !== $xnetvn_agent_hub_wp_ledger['users'][ $xnetvn_agent_hub_wp_alias ] ) {
				return false;
			}
			if ( ! in_array( $xnetvn_agent_hub_wp_expect['role'], (array) ( $user['roles'] ?? array() ), true ) ) {
				return false;
			}
			return ( $key['tier_max'] ?? '' ) === $xnetvn_agent_hub_wp_expect['tier'];
		}
	);
	xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_case, $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
	$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
}
$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'FX-03',
	static function () use ( $xnetvn_agent_hub_wp_harness ): bool {
		foreach ( $xnetvn_agent_hub_wp_harness->cases() as $id => $result ) {
			if ( str_starts_with( $id, 'FX-03-' ) && true !== ( $result['ok'] ?? false ) ) {
				return false;
			}
		}
		return true;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'FX-03', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// FX-07: synthetic canaries plus one forced failure; nothing secret reaches output.
$xnetvn_agent_hub_wp_canaries = array( 'FX07-CANARY-BEARER', 'FX07-CANARY-COOKIE', 'FX07-CANARY-HASH', 'FX07-CANARY-ARG' );
$xnetvn_agent_hub_wp_ok       = $xnetvn_agent_hub_wp_time(
	$xnetvn_agent_hub_wp_timings,
	'FX-07',
	static function () use ( $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_canaries ): bool {
		try {
			$denied = $xnetvn_agent_hub_wp_harness->call( 'author', 'get-user', array( 'id' => 101 ) );
			$xnetvn_agent_hub_wp_harness->assertSuccess( 'FX-07-probe', $denied );
		} catch ( \Throwable $error ) {
			$message = $error->getMessage();
			foreach ( $xnetvn_agent_hub_wp_canaries as $canary ) {
				if ( str_contains( $message, $canary ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}
);
xnetvn_agent_hub_wp_role_seo_record( $xnetvn_agent_hub_wp_harness, 'FX-07', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;

// Token non-disclosure across the whole report surface.
$xnetvn_agent_hub_wp_report_json = json_encode( $xnetvn_agent_hub_wp_harness->cases(), JSON_THROW_ON_ERROR );
foreach ( $xnetvn_agent_hub_wp_ledger['tokens'] as $xnetvn_agent_hub_wp_token ) {
	if ( is_string( $xnetvn_agent_hub_wp_token ) && '' !== $xnetvn_agent_hub_wp_token && str_contains( $xnetvn_agent_hub_wp_report_json, $xnetvn_agent_hub_wp_token ) ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
}
foreach ( $xnetvn_agent_hub_wp_canaries as $xnetvn_agent_hub_wp_canary ) {
	if ( str_contains( $xnetvn_agent_hub_wp_report_json, $xnetvn_agent_hub_wp_canary ) ) {
		$xnetvn_agent_hub_wp_failed = true;
	}
}

$xnetvn_agent_hub_wp_report = array(
	'suite'     => 'role-seo-fixture',
	'target'    => $xnetvn_agent_hub_wp_site,
	'run_id'    => $xnetvn_agent_hub_wp_run_id,
	'blog'      => (int) $xnetvn_agent_hub_wp_blog,
	'cases'     => $xnetvn_agent_hub_wp_harness->cases(),
	'declared'  => xnetvn_agent_hub_wp_role_seo_declare_cases(),
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
