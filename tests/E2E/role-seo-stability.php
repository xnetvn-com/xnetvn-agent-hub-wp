<?php
/**
 * Owned role/SEO stability matrix: STAB-01 through STAB-07 declarations and
 * the MCP-level cases executed through real HTTPS.
 *
 * Each STAB row from the ticket is a separately recorded case. Five
 * independent rounds replay the B-E matrices (permissions, content, media,
 * lifecycle) with a fresh run id, fresh round seed and cleanup before the
 * next round; every group additionally seeds its own isolated state like
 * the original per-group gates, so sweeps never inherit users, keys,
 * settings or budgets. Retries are zero and mutation groups never run in
 * parallel. Round one additionally carries the rate boundary, the costly
 * budget barrier, the uncertain-mutation reconcile and the concurrent-read
 * cases. Reports
 * carry only case ids, allowlisted reasons, counts, digests, timing
 * percentiles and numeric memory probes; no input, response bodies, post
 * bodies, cookies, bearers, hashes or argument values are printed. Missing
 * metrics stay unavailable, never zero. Teardown deletes only tracked
 * ledger-owned ids, never by query or prefix.
 *
 * The selftest mode runs offline without a lab, a network or a fixture: it
 * pins the measurement contract of the probe helper, so a latency sample
 * always describes the MCP request and never the preparation around it.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\E2E\McpClient;
use XNetVN\AgentHubWP\Tests\E2E\RoleSeoHarness;
use XNetVN\AgentHubWP\Tests\Support\BenchmarkSummary;

// The selftest mode runs offline inside the toolbox or a local checkout: it
// proves the measurement contract without a lab, a network or a fixture.
$xnetvn_agent_hub_wp_mode = isset( $argv[1] ) && is_string( $argv[1] ) ? $argv[1] : '';
if ( '1' !== getenv( 'XNETVN_RTL' ) && 'selftest' !== $xnetvn_agent_hub_wp_mode ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client; no WordPress bootstrap or logged credentials.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed owned toolbox fixture actions with no credential arguments.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open,WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close -- Bounded test-only worker lifecycle with fixed argv and no credentials.

/**
 * Declare the seven stability cases with their owner and applicability.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_stability_declare_cases(): array {
	return array(
		array(
			'id'           => 'STAB-01',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
			'scope_modes'  => array( 'read', 'write' ),
			'variants'     => array( 'round-1', 'round-2', 'round-3', 'round-4', 'round-5' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-02',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'scope_modes'  => array( 'read', 'write' ),
			'variants'     => array( 'latency', 'memory' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-03',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'read' ),
			'variants'     => array( 'prerequisite' ),
			'targets'      => array( 'wp69' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-04',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'read' ),
			'variants'     => array( 'boundary', 'retry-after', 'recover' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-05',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'contend', 'release', 'timeout', 'crash' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-06',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator' ),
			'scope_modes'  => array( 'write' ),
			'variants'     => array( 'faulted', 'lost' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
		array(
			'id'           => 'STAB-07',
			'owner'        => 'matrix',
			'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber' ),
			'scope_modes'  => array( 'read' ),
			'variants'     => array( 'concurrent', 'health' ),
			'targets'      => array( 'wp69', 'wp71' ),
			'expects_exit' => 0,
		),
	);
}

/**
 * Run one concurrent read worker: single owned read, numeric evidence only.
 *
 * Workers never receive credentials on argv; tokens are read from the
 * private fixture state owned by this run.
 *
 * @param array<string> $arguments Worker argv without credentials.
 *
 * @throws \RuntimeException When the fixture ids cannot be resolved.
 */
function xnetvn_agent_hub_wp_role_seo_stability_worker( array $arguments ): void {
	$site    = $arguments[2] ?? '';
	$run_id  = $arguments[3] ?? '';
	$blog    = $arguments[4] ?? '1';
	$index   = (int) ( $arguments[5] ?? 0 );
	$actor   = $arguments[6] ?? '';
	$logical = (int) ( $arguments[7] ?? 101 );
	if ( ! in_array( $site, array( 'wp69', 'wp71' ), true ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $run_id ) || 1 > $index || 10 < $index || '' === $actor ) {
		exit( 2 );
	}
	$host_key = 'wp71' === $site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69';
	$host     = getenv( $host_key );
	if ( ! is_string( $host ) || '' === $host ) {
		exit( 2 );
	}
	$state_file = '/work/.local/role-seo/private/' . $site . '/' . $run_id . '/state.json';
	try {
		$ledger = RoleSeoHarness::loadLedger( $state_file );
	} catch ( \Throwable ) {
		exit( 1 );
	}
	$tokens = $ledger['tokens'] ?? array();
	if ( ! is_array( $tokens ) || ! isset( $tokens[ $actor ] ) || ! is_string( $tokens[ $actor ] ) || '' === $tokens[ $actor ] ) {
		exit( 1 );
	}
	$envelope_id = 1000 + $index;
	$real_id     = 0;
	try {
		$ids     = ( new RoleSeoHarness(
			array(
				'target'        => $site,
				'blog'          => (int) $blog,
				'base_url'      => 'https://' . $host,
				'run_id'        => $run_id,
				'private_state' => $state_file,
			),
			new McpClient( 'https://' . $host, array( $host ), true ),
			$ledger
		) )->resolveIds( array( 'id' => $logical ) );
		$real_id = (int) ( $ids['id'] ?? 0 );
		if ( 1 > $real_id ) {
			throw new \RuntimeException( 'Unresolved fixture id.' );
		}
	} catch ( \Throwable ) {
		exit( 1 );
	}
	$body  = json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => $envelope_id,
			'method'  => 'tools/call',
			'params'  => array(
				'name'      => 'xnetvn-get-post',
				'arguments' => (object) array( 'id' => $real_id ),
				'_meta'     => array(
					'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities' => (object) array(),
					'io.modelcontextprotocol/clientInfo' => array(
						'name'    => 'role-seo-acceptance',
						'version' => '1',
					),
				),
			),
		),
		JSON_THROW_ON_ERROR
	);
	$start = hrtime( true );
	try {
		$response = ( new McpClient( 'https://' . $host, array( $host ), true ) )->request(
			'/wp-json/xnetvn-agent-hub-wp/mcp',
			$body,
			$tokens[ $actor ],
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => 'xnetvn-get-post',
			)
		);
	} catch ( \Throwable ) {
		exit( 1 );
	}
	$echo   = $response['body']['id'] ?? null;
	$result = $response['body']['result'] ?? null;
	$ok     = false;
	$reason = 'unavailable';
	if ( 200 === ( $response['status'] ?? 0 ) && is_array( $result ) ) {
		if ( true === ( $result['isError'] ?? false ) ) {
			$text = $result['content'][0]['text'] ?? '';
			if ( is_string( $text ) ) {
				foreach ( array( 'forbidden', 'not_found', 'rate_limited', 'internal_error', 'unavailable' ) as $candidate ) {
					if ( str_starts_with( $text, $candidate . ': ' ) ) {
						$ok     = true;
						$reason = $candidate;
						break;
					}
				}
			}
		} else {
			$data = $result['structuredContent'] ?? null;
			if ( is_array( $data ) && (int) ( $data['id'] ?? 0 ) === $real_id && $envelope_id === $echo ) {
				$ok     = true;
				$reason = 'unavailable';
			}
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric CLI evidence only.
	echo json_encode(
		array(
			'index'   => $index,
			'actor'   => $actor,
			'ok'      => $ok,
			'reason'  => $reason,
			'post_id' => $real_id,
			'ms'      => ( hrtime( true ) - $start ) / 1000000,
		),
		JSON_THROW_ON_ERROR
	) . "\n";
	exit( $ok ? 0 : 1 );
}

/**
 * Measure one owned request in milliseconds.
 *
 * The timer brackets the measured body only. Fixture work that runs before
 * the request stays outside the window, so a latency sample always describes
 * the request and never the preparation around it.
 *
 * @param callable $request Owned request body.
 * @return array{ms:float,value:mixed,failed:bool}
 */
function xnetvn_agent_hub_wp_role_seo_stability_measure( callable $request ): array {
	$start  = hrtime( true );
	$value  = null;
	$failed = false;
	try {
		$value = $request();
	} catch ( \Throwable ) {
		$failed = true;
	}
	return array(
		'ms'     => ( hrtime( true ) - $start ) / 1000000,
		'value'  => $value,
		'failed' => $failed,
	);
}

/**
 * Classify one measured MCP answer.
 *
 * An allowed answer means the operation ran, a denied answer means the
 * identity or the policy refused it, and an unavailable answer proves
 * neither. A refusal with a specific HTTP status is a denial: the actor
 * never reached the ability. An unavailable answer never becomes a
 * latency metric and always counts as an error.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @return array{class:string,reason:string}
 */
function xnetvn_agent_hub_wp_role_seo_stability_classify( array $response ): array {
	$status = (int) ( $response['status'] ?? 0 );
	if ( 200 !== $status ) {
		if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
			return array(
				'class'  => 'denied',
				'reason' => 'http_' . $status,
			);
		}
		return array(
			'class'  => 'unavailable',
			'reason' => 0 === $status ? 'transport' : 'http_' . $status,
		);
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) ) {
		return array(
			'class'  => 'unavailable',
			'reason' => 'no_result',
		);
	}
	if ( true !== ( $result['isError'] ?? false ) ) {
		return is_array( $result['structuredContent'] ?? null )
			? array(
				'class'  => 'allowed',
				'reason' => 'allowed',
			)
			: array(
				'class'  => 'unavailable',
				'reason' => 'no_data',
			);
	}
	$text = $result['content'][0]['text'] ?? null;
	if ( 'The tool is unavailable.' === $text ) {
		return array(
			'class'  => 'denied',
			'reason' => 'hidden',
		);
	}
	if ( is_string( $text ) ) {
		foreach ( array( 'forbidden', 'rate_limited' ) as $reason ) {
			if ( str_starts_with( $text, $reason . ': ' ) ) {
				return array(
					'class'  => 'denied',
					'reason' => $reason,
				);
			}
		}
	}
	return array(
		'class'  => 'unavailable',
		'reason' => 'unavailable',
	);
}

/**
 * Seed and verify one phase, then hand the state over.
 *
 * A phase that fails between the seed and the verify owns a half seeded
 * state. The cleanup runs before the error leaves the phase, so an aborted
 * phase never leaves users, keys or content in the shared lab. The runner
 * reconciles the phase run ids of a campaign as the second safety net.
 *
 * @param callable $action Fixture action runner.
 * @param string   $site   Lab target.
 * @param string   $run    Phase run id.
 * @throws \Throwable The original failure of the seed or the verify.
 */
function xnetvn_agent_hub_wp_role_seo_stability_seed( callable $action, string $site, string $run ): void {
	try {
		$action( $site, $run, 'seed' );
		$action( $site, $run, 'verify' );
	} catch ( \Throwable $error ) {
		try {
			$action( $site, $run, 'cleanup' );
		} catch ( \Throwable $cleanup_error ) {
			// The runner reconciles the phase run ids of the campaign.
			unset( $cleanup_error );
		}
		throw $error;
	}
}

/**
 * Report the class with the most observations.
 *
 * @param array<string,int> $classes Observed classes and their counts.
 * @return string
 */
function xnetvn_agent_hub_wp_role_seo_stability_class( array $classes ): string {
	$best  = 'unavailable';
	$count = 0;
	foreach ( array( 'allowed', 'denied', 'unavailable' ) as $candidate ) {
		$seen = (int) ( $classes[ $candidate ] ?? 0 );
		if ( $seen > $count ) {
			$count = $seen;
			$best  = $candidate;
		}
	}
	return $best;
}

/**
 * Reduce one actor and operation bucket to counts, percentiles and errors.
 *
 * A bucket without samples stays unavailable and is never zero-filled.
 *
 * @param array<string,mixed> $bucket Accumulated samples, classes, reasons and errors.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_stability_bucket( array $bucket ): array {
	$samples = ( isset( $bucket['ms'] ) && is_array( $bucket['ms'] ) ) ? $bucket['ms'] : array();
	$errors  = (int) ( $bucket['errors'] ?? 0 );
	$classes = ( isset( $bucket['classes'] ) && is_array( $bucket['classes'] ) ) ? $bucket['classes'] : array();
	$reasons = ( isset( $bucket['reasons'] ) && is_array( $bucket['reasons'] ) ) ? $bucket['reasons'] : array();
	if ( array() === $samples ) {
		return array(
			'samples' => 0,
			'errors'  => $errors,
			'classes' => $classes,
			'reasons' => $reasons,
			'class'   => 'unavailable',
			'latency' => 'unavailable',
		);
	}
	$summary = BenchmarkSummary::summarize( $samples );
	return array(
		'samples' => $summary['samples'],
		'errors'  => $errors,
		'classes' => $classes,
		'reasons' => $reasons,
		'class'   => xnetvn_agent_hub_wp_role_seo_stability_class( $classes ),
		'latency' => $summary,
	);
}

/**
 * Probe owned actors with the reviewed operations.
 *
 * Every task names its actor, its operation and its tool. The preparation
 * callable resolves the owned fixture ids once, before any measurement, so
 * the ledger work never enters a measured window. The sample of a task is
 * the elapsed time of the MCP request in milliseconds. Answers are
 * classified as allowed, denied or unavailable, and an unavailable answer
 * counts as an error instead of a metric.
 *
 * @param array<string,mixed> $context Probe context.
 * @return array{ok:bool,samples:array<string,array<string,mixed>>,owned:array<int,int>}
 * @throws \InvalidArgumentException For a missing sender or preparation.
 */
function xnetvn_agent_hub_wp_role_seo_stability_probe( array $context ): array {
	$tasks   = ( isset( $context['tasks'] ) && is_array( $context['tasks'] ) ) ? $context['tasks'] : array();
	$tokens  = ( isset( $context['tokens'] ) && is_array( $context['tokens'] ) ) ? $context['tokens'] : array();
	$send    = $context['send'] ?? null;
	$prepare = $context['prepare'] ?? null;
	if ( ! is_callable( $send ) || ! is_callable( $prepare ) ) {
		throw new \InvalidArgumentException( 'Stability probe needs a sender and a preparation.' );
	}
	$samples = array();
	$owned   = array();
	$ok      = true;
	// The preparation runs once, outside every measured window.
	$prepared = xnetvn_agent_hub_wp_role_seo_stability_measure( $prepare );
	if ( $prepared['failed'] ) {
		return array(
			'ok'      => false,
			'samples' => $samples,
			'owned'   => $owned,
		);
	}
	$values = is_array( $prepared['value'] ) ? $prepared['value'] : array();
	foreach ( $tasks as $task ) {
		if ( ! is_array( $task ) || ! isset( $task['actor'], $task['operation'], $task['tool'] ) || ! is_string( $task['actor'] ) || ! is_string( $task['operation'] ) || ! is_string( $task['tool'] ) ) {
			$ok = false;
			continue;
		}
		$actor = $task['actor'];
		if ( ! isset( $tokens[ $actor ] ) || ! is_string( $tokens[ $actor ] ) || '' === $tokens[ $actor ] ) {
			$ok = false;
			continue;
		}
		$use       = ( isset( $task['use'] ) && is_array( $task['use'] ) ) ? $task['use'] : array();
		$arguments = ( isset( $task['arguments'] ) && is_array( $task['arguments'] ) ) ? $task['arguments'] : array();
		$resolved  = ( array() !== $use ) ? array_intersect_key( $values, array_flip( $use ) ) : array();
		$measured  = xnetvn_agent_hub_wp_role_seo_stability_measure(
			static function () use ( $send, $tokens, $actor, $task, $resolved, $arguments ) {
				return $send( $tokens[ $actor ], $task['tool'], array_merge( $resolved, $arguments ) );
			}
		);
		$value     = is_array( $measured['value'] ) ? $measured['value'] : array();
		$verdict   = $measured['failed']
			? array(
				'class'  => 'unavailable',
				'reason' => 'transport',
			)
			: xnetvn_agent_hub_wp_role_seo_stability_classify( $value );
		$class     = $verdict['class'];
		$samples[ $actor . '|' . $task['operation'] ] = array(
			'class'  => $class,
			'reason' => $verdict['reason'],
			'ms'     => $measured['ms'],
			'failed' => $measured['failed'],
		);
		if ( 'allowed' !== $class || empty( $task['owned'] ) ) {
			continue;
		}
		$data = $value['body']['result']['structuredContent'] ?? null;
		$id   = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;
		if ( 0 < $id ) {
			$owned[] = $id;
		}
	}
	return array(
		'ok'      => $ok,
		'samples' => $samples,
		'owned'   => $owned,
	);
}

/**
 * Build the STAB-02 probe tasks of the owned actors.
 *
 * Every actor is measured with the same three operations: a session read
 * that every key may call, a content read that only the readers of the
 * owned post may call, and a content write that only the writers may call.
 * The write task owns the created post, so the caller can delete it again.
 *
 * @param array<string,string> $actors Actor aliases keyed by their token.
 * @param string               $title  Owned title prefix.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_stability_tasks( array $actors, string $title ): array {
	$tasks = array();
	foreach ( array_keys( $actors ) as $actor ) {
		$tasks[] = array(
			'actor'     => (string) $actor,
			'operation' => 'session',
			'tool'      => 'xnetvn-get-session-info',
		);
		$tasks[] = array(
			'actor'     => (string) $actor,
			'operation' => 'post-read',
			'tool'      => 'xnetvn-get-post',
			'use'       => array( 'id' ),
		);
		$tasks[] = array(
			'actor'     => (string) $actor,
			'operation' => 'post-write',
			'tool'      => 'xnetvn-create-post',
			'arguments' => array( 'title' => $title . ' ' . $actor ),
			'owned'     => true,
		);
	}
	return $tasks;
}

/**
 * Build the per actor and operation report.
 *
 * Every actor keeps its own operations, and every operation keeps its own
 * sample count, error count and percentiles. A missing metric stays
 * unavailable; it is never replaced by zero.
 *
 * @param array<string,array<string,mixed>> $buckets Buckets keyed by actor|operation.
 * @param array<string,int>                 $memory  Peak delta bytes per actor.
 * @return array<string,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_stability_actor_report( array $buckets, array $memory ): array {
	$report = array();
	foreach ( $buckets as $key => $bucket ) {
		$parts = explode( '|', (string) $key );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			continue;
		}
		$actor     = $parts[0];
		$operation = $parts[1];
		if ( ! isset( $report[ $actor ] ) ) {
			$report[ $actor ] = array(
				'operations' => array(),
				'peak_bytes' => 'unavailable',
			);
		}
		$report[ $actor ]['operations'][ $operation ] = xnetvn_agent_hub_wp_role_seo_stability_bucket( is_array( $bucket ) ? $bucket : array() );
	}
	foreach ( $memory as $actor => $delta ) {
		if ( ! isset( $report[ $actor ] ) ) {
			continue;
		}
		$report[ $actor ]['peak_bytes'] = (int) $delta;
	}
	return $report;
}

/**
 * Run the offline contract checks of the stability harness.
 *
 * The checks never touch the network, a lab or a fixture: they prove that
 * the measured window covers the request and not the preparation around it,
 * that every actor and operation keeps its own metric, and that a missing
 * metric stays unavailable instead of zero.
 *
 * @return array{ok:bool,checks:array<string,bool>}
 */
function xnetvn_agent_hub_wp_role_seo_stability_selftest(): array {
	$checks           = array();
	$prepare_millis   = 250;
	$request_millis   = 10;
	$prepared         = array( 'id' => 4242 );
	$prepare_callable = static function () use ( $prepare_millis, $prepared ): array {
		usleep( $prepare_millis * 1000 );
		return $prepared;
	};
	$send_callable    = static function ( string $token, string $tool, array $arguments ) use ( $request_millis ): array {
		usleep( $request_millis * 1000 );
		if ( 'xnetvn-create-post' === $tool ) {
			return array(
				'status' => 200,
				'body'   => array(
					'result' => array(
						'isError' => true,
						'content' => array( array( 'text' => 'forbidden: The actor may not write this content.' ) ),
					),
				),
			);
		}
		return array(
			'status' => 200,
			'body'   => array( 'result' => array( 'structuredContent' => array( 'id' => (int) ( $arguments['id'] ?? 0 ) ) ) ),
		);
	};
	$actors           = array(
		'alpha' => 'token-alpha',
		'beta'  => 'token-beta',
	);
	$probe            = xnetvn_agent_hub_wp_role_seo_stability_probe(
		array(
			// The matrix builds the same tasks, so the contract check covers
			// the real probe and not a copy of it.
			'tasks'   => xnetvn_agent_hub_wp_role_seo_stability_tasks( $actors, 'Stability selftest' ),
			'tokens'  => $actors,
			'prepare' => $prepare_callable,
			'send'    => $send_callable,
		)
	);
	$alpha            = $probe['samples']['alpha|post-read'] ?? array();
	$beta             = $probe['samples']['beta|post-read'] ?? array();
	$write            = $probe['samples']['alpha|post-write'] ?? array();
	$alpha_ms         = ( isset( $alpha['ms'] ) && is_float( $alpha['ms'] ) ) ? $alpha['ms'] : 0.0;
	$beta_ms          = ( isset( $beta['ms'] ) && is_float( $beta['ms'] ) ) ? $beta['ms'] : 0.0;
	// The measured window must cover the request only: a sample that also
	// carries the preparation is larger than the preparation itself.
	$checks['allowed-read-measures-the-request']  = ( $alpha_ms >= $request_millis && $alpha_ms < $prepare_millis );
	$checks['per-actor-samples-are-separate']     = ( $alpha_ms > 0.0 && $beta_ms > 0.0 && 'alpha|post-read' !== 'beta|post-read' );
	$checks['allowed-and-denied-are-separated']   = ( 'allowed' === ( $alpha['class'] ?? '' ) && 'denied' === ( $write['class'] ?? '' ) );
	$checks['every-actor-keeps-every-operation']  = ( 6 === count( $probe['samples'] ) && 6 === count( xnetvn_agent_hub_wp_role_seo_stability_tasks( $actors, 'Stability selftest' ) ) );
	$checks['a-missing-metric-stays-unavailable'] = ( 'unavailable' === xnetvn_agent_hub_wp_role_seo_stability_bucket( array() )['latency'] );
	// A refusal with a specific HTTP status is a denial: the subscriber and
	// the banned actor never reach an ability, so the status is the reason.
	$refused                                  = xnetvn_agent_hub_wp_role_seo_stability_classify( array( 'status' => 403 ) );
	$checks['an-http-refusal-is-a-denial']    = ( 'denied' === ( $refused['class'] ?? '' ) && 'http_403' === ( $refused['reason'] ?? '' ) );
	$limited                                  = xnetvn_agent_hub_wp_role_seo_stability_classify( array( 'status' => 429 ) );
	$broken                                   = xnetvn_agent_hub_wp_role_seo_stability_classify( array( 'status' => 500 ) );
	$checks['a-broken-answer-is-unavailable'] = ( 'denied' === ( $limited['class'] ?? '' ) && 'unavailable' === ( $broken['class'] ?? '' ) );
	$bucket                                   = xnetvn_agent_hub_wp_role_seo_stability_bucket(
		array(
			'ms'      => array( 1.0, 2.0, 3.0 ),
			'classes' => array( 'allowed' => 3 ),
			'errors'  => 0,
		)
	);
	$checks['a-bucket-keeps-counts-and-percentiles'] = ( 3 === $bucket['samples'] && 0 === $bucket['errors'] && 2.0 === $bucket['latency']['p50'] && 3.0 === $bucket['latency']['p95'] && 'allowed' === $bucket['class'] );
	$report = function_exists( 'xnetvn_agent_hub_wp_role_seo_stability_actor_report' )
		? xnetvn_agent_hub_wp_role_seo_stability_actor_report(
			array(
				'alpha|post-read' => array(
					'ms'      => array( 4.0 ),
					'classes' => array( 'allowed' => 1 ),
					'errors'  => 0,
				),
				'beta|post-read'  => array(
					'ms'      => array( 5.0 ),
					'classes' => array( 'denied' => 1 ),
					'errors'  => 0,
				),
			),
			array( 'alpha' => 1024 )
		)
		: array();
	$checks['the-report-separates-actors-and-operations'] = ( isset( $report['alpha']['operations']['post-read'], $report['beta']['operations']['post-read'] )
		&& 1 === $report['alpha']['operations']['post-read']['samples']
		&& 1 === $report['beta']['operations']['post-read']['samples']
		&& 'allowed' === $report['alpha']['operations']['post-read']['class']
		&& 'denied' === $report['beta']['operations']['post-read']['class']
		&& 1024 === $report['alpha']['peak_bytes']
		&& 'unavailable' === $report['beta']['peak_bytes'] );
	// The crash case of the costly budget must stay declared and owned by
	// the suite instrumentation.
	$declared  = xnetvn_agent_hub_wp_role_seo_stability_declare_cases();
	$stab_five = array();
	foreach ( $declared as $declared_case ) {
		if ( 'STAB-05' === ( $declared_case['id'] ?? '' ) ) {
			$stab_five = $declared_case;
		}
	}
	$checks['the-crash-case-is-declared'] = in_array( 'crash', (array) ( $stab_five['variants'] ?? array() ), true );
	// A phase that fails between the seed and the verify owns a half seeded
	// state: the cleanup must run before the error leaves the phase.
	$phase_calls  = array();
	$phase_seed   = static function ( string $site, string $run, string ...$argv ) use ( &$phase_calls ): void {
		$phase_calls[] = $run . ':' . ( $argv[0] ?? '' );
		if ( 'verify' === ( $argv[0] ?? '' ) ) {
			throw new \RuntimeException( 'Owned phase verify fault.' );
		}
	};
	$phase_failed = false;
	if ( function_exists( 'xnetvn_agent_hub_wp_role_seo_stability_seed' ) ) {
		try {
			xnetvn_agent_hub_wp_role_seo_stability_seed( $phase_seed, 'wp69', 'owned-phase' );
		} catch ( \Throwable ) {
			$phase_failed = true;
		}
	}
	$checks['a-failed-phase-is-cleaned'] = ( $phase_failed && in_array( 'owned-phase:cleanup', $phase_calls, true ) );
	// Fake read-only observers cannot reach a fixture action. In particular,
	// integration core 7.1.2 does not make a 7.1.2 website acceptable.
	$guard_reads  = array();
	$guard_seen   = 0;
	$read_version = static function ( string $site ) use ( &$guard_reads ): string {
		$guard_reads[] = $site;
		return '7.1.2';
	};
	$exists       = static function () use ( &$guard_seen ): bool {
		++$guard_seen;
		return false;
	};

	$wrong_version = xnetvn_agent_hub_wp_role_seo_stability_phase_guard( 'wp71', 'fake-guard-run', $read_version, $exists );

	$checks['website-drift-blocks-before-fixture'] = false === $wrong_version['accepted'] && '7.1.4' === $wrong_version['expected_version'] && '7.1.2' === $wrong_version['actual_version'] && array( 'wp71' ) === $guard_reads && 0 === $guard_seen;

	$guard_reads  = array();
	$wrong_target = xnetvn_agent_hub_wp_role_seo_stability_phase_guard( 'wp70', 'fake-guard-run', $read_version, $exists );
	$wrong_run    = xnetvn_agent_hub_wp_role_seo_stability_phase_guard( 'wp69', 'FAKE-invalid', $read_version, $exists );

	$checks['invalid-target-or-run-never-falls-back'] = false === $wrong_target['accepted'] && false === $wrong_run['accepted'] && array() === $guard_reads && 0 === $guard_seen;

	$known_version = static fn(): string => '6.9';
	$existing      = static fn(): bool => true;
	$old_run       = xnetvn_agent_hub_wp_role_seo_stability_phase_guard( 'wp69', 'fake-existing', $known_version, $existing );

	$checks['existing-state-blocks-before-seed'] = false === $old_run['accepted'] && 'existing_run_requires_reconcile' === $old_run['status'];

	$ok = array() !== $checks;
	foreach ( $checks as $passed ) {
		if ( true !== $passed ) {
			$ok = false;
		}
	}
	return array(
		'ok'     => $ok,
		'checks' => $checks,
	);
}

if ( 'selftest' === $xnetvn_agent_hub_wp_mode ) {
	$xnetvn_agent_hub_wp_selftest = xnetvn_agent_hub_wp_role_seo_stability_selftest();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable boolean CLI evidence only.
	echo json_encode(
		array(
			'suite'  => 'role-seo-stability-selftest',
			'ok'     => $xnetvn_agent_hub_wp_selftest['ok'],
			'checks' => $xnetvn_agent_hub_wp_selftest['checks'],
		),
		JSON_THROW_ON_ERROR
	) . "\n";
	exit( $xnetvn_agent_hub_wp_selftest['ok'] ? 0 : 1 );
}

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	xnetvn_agent_hub_wp_role_seo_stability_worker( $argv );
	exit( 2 );
}

$xnetvn_agent_hub_wp_started  = hrtime( true );
$xnetvn_agent_hub_wp_timings  = array();
$xnetvn_agent_hub_wp_site     = getenv( 'XNETVN_MATRIX_SITE' );
$xnetvn_agent_hub_wp_base_run = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
$xnetvn_agent_hub_wp_blog_raw = getenv( 'XNETVN_ROLE_SEO_BLOG' );
$xnetvn_agent_hub_wp_blog     = ( is_string( $xnetvn_agent_hub_wp_blog_raw ) && '' !== $xnetvn_agent_hub_wp_blog_raw ) ? $xnetvn_agent_hub_wp_blog_raw : '1';
if ( ! in_array( $xnetvn_agent_hub_wp_site, array( 'wp69', 'wp71' ), true ) || ! is_string( $xnetvn_agent_hub_wp_base_run ) || 1 !== preg_match( '/\A[a-z0-9-]{1,28}\z/', $xnetvn_agent_hub_wp_base_run ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_blog ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_host_key = 'wp71' === $xnetvn_agent_hub_wp_site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69';
$xnetvn_agent_hub_wp_host     = getenv( $xnetvn_agent_hub_wp_host_key );
if ( ! is_string( $xnetvn_agent_hub_wp_host ) || '' === $xnetvn_agent_hub_wp_host ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_cases  = array();
$xnetvn_agent_hub_wp_failed = false;
// Owned tool-call log; proves reconcile paths never issue a second mutation.
$xnetvn_agent_hub_wp_call_log = array();

/**
 * Record a stability-owned case without raw values.
 *
 * STAB ids live in this matrix report; the shared harness vocabulary is
 * untouched. Missing metrics stay unavailable, never zero.
 *
 * @param string $case_id Case id.
 * @param bool   $ok Outcome.
 * @param string $reason Allowlisted reason, never raw input.
 */
$xnetvn_agent_hub_wp_record = static function ( string $case_id, bool $ok, string $reason = 'unavailable' ) use ( &$xnetvn_agent_hub_wp_cases ): void {
	$allowed_ids = array( 'STAB-01', 'STAB-02', 'STAB-03', 'STAB-04', 'STAB-05', 'STAB-06', 'STAB-07' );
	$allowed_why = array(
		'invalid_params',
		'forbidden',
		'not_found',
		'post_type_unavailable',
		'invalid_state',
		'unsupported_operation',
		'conflict',
		'partial_failure',
		'restore_failed',
		'payload_too_large',
		'file_type_not_allowed',
		'image_too_large',
		'quota_exceeded',
		'remote_fetch_disabled',
		'remote_fetch_refused',
		'remote_fetch_failed',
		'rate_limited',
		'duplicate_comment',
		'comments_closed',
		'taxonomy_unavailable',
		'duplicate_term',
		'duplicate_user',
		'protected_account',
		'role_unavailable',
		'cooldown_active',
		'internal_error',
		'unavailable',
	);
	if ( ! in_array( $case_id, $allowed_ids, true ) || ! in_array( $reason, $allowed_why, true ) ) {
		throw new \InvalidArgumentException( 'Unsafe stability case.' );
	}
	$xnetvn_agent_hub_wp_cases[ $case_id ] = array(
		'ok'     => $ok,
		'reason' => $reason,
	);
};

/**
 * Time one stability-owned check.
 *
 * @param array<string,float> $timings Timing accumulator.
 * @param string              $key Timing bucket.
 * @param callable            $check Check body.
 * @return bool
 */
$xnetvn_agent_hub_wp_time = static function ( array &$timings, string $key, callable $check ): bool {
	$start = hrtime( true );
	try {
		$ok = $check();
	} catch ( \Throwable ) {
		$ok = false;
	}
	$timings[ $key ] = ( hrtime( true ) - $start ) / 1000000;
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
$xnetvn_agent_hub_wp_send = static function ( McpClient $client, int &$id, ?string $token, string $method, array $params = array(), array $headers = array() ): array {
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
		'/wp-json/xnetvn-agent-hub-wp/mcp',
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
 * @param string $run_id Round run id.
 * @param string ...$argv Fixed action arguments.
 * @return array<string,mixed>
 * @throws \RuntimeException For a failed fixture control.
 */
$xnetvn_agent_hub_wp_action = static function ( string $site, string $run_id, string ...$argv ): array {
	$allowed_actions = array( 'seed', 'verify', 'snapshot', 'restore', 'reconcile', 'counts', 'cleanup', 'site-variant', 'key-temp', 'key-drop', 'content-digest', 'cron-drift', 'lifecycle-fault', 'stability-barrier', 'stability-probe' );
	$allowed_subs    = array( 'rate-high', 'rate-low', 'reset', 'prepare-fail-once', 'none', 'hold', 'hold-short', 'release', 'status' );
	$allowed_actors  = array( 'admin', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' );
	foreach ( $argv as $index => $part ) {
		if ( 0 === $index && ! in_array( $part, $allowed_actions, true ) ) {
			throw new \RuntimeException( 'Invalid fixture action.' );
		}
		if ( 0 < $index && ! in_array( $part, $allowed_subs, true ) && ! in_array( $part, $allowed_actors, true ) && 1 !== preg_match( '/\A(?:STAB-0[1-7]|round-[1-5])\z/', $part ) ) {
			throw new \RuntimeException( 'Invalid fixture action.' );
		}
	}
	$command = 'bash /work/infra/remote/run-wp.sh ' . escapeshellarg( $site ) . ' --url=' . escapeshellarg( 'https://' . getenv( 'wp71' === $site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' ) ) . ' eval-file /work/tests/E2E/role-seo-fixture.php';
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
 * Require a safe denial carrying the allowlisted reason prefix.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @param string              $reason Expected allowlisted reason.
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
 * Require the hidden-tool outcome: HTTP 200, isError and the fixed message.
 *
 * Keys without the toolset see no such tool; the adapter answers with the
 * fixed unavailable message instead of a policy reason.
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
 * Require a successful tools/list page without raw values.
 *
 * Listing responses carry a tools array instead of structured content, so
 * the tools/call shape check cannot apply; anything else is not a pass.
 *
 * @param array<string,mixed> $response Decoded MCP response.
 * @return bool
 */
$xnetvn_agent_hub_wp_listed = static function ( array $response ): bool {
	if ( 200 !== ( $response['status'] ?? 0 ) ) {
		return false;
	}
	$result = $response['body']['result'] ?? null;
	if ( ! is_array( $result ) || true === ( $result['isError'] ?? false ) ) {
		return false;
	}
	$tools = $result['tools'] ?? null;
	return is_array( $tools ) && array() !== $tools;
};

/**
 * Wait until the shared rate windows cool for the given actors.
 *
 * Neighbor matrices reset limits to the snapshot while their buckets are
 * still hot, so the next group must not mistake a 429 for a product
 * denial. Only an explicit 429 waits; any other non-200 outcome fails
 * fast so real defects stay visible. Valid probes never charge the
 * auth-fail budget, so waiting cannot extend IP poisoning.
 *
 * @param McpClient           $client HTTP transport.
 * @param array<string,mixed> $tokens Actor tokens, never printed.
 * @param array<string>       $actors Actor aliases to cool.
 * @param callable            $send Envelope sender.
 * @param callable            $listed Listing shape check.
 * @return bool
 */
$xnetvn_agent_hub_wp_quiet = static function ( McpClient $client, array $tokens, array $actors, callable $send, callable $listed ): bool {
	$envelope = 9000;
	$started  = time();
	$deadline = $started + 240;
	do {
		$cool = true;
		foreach ( $actors as $actor ) {
			if ( ! isset( $tokens[ $actor ] ) || ! is_string( $tokens[ $actor ] ) || '' === $tokens[ $actor ] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
				fwrite( STDERR, 'Role SEO quiet refused: actor unavailable after ' . ( time() - $started ) . "s\n" );
				return false;
			}
			try {
				$probe = $send( $client, $envelope, $tokens[ $actor ], 'tools/list', array() );
			} catch ( \Throwable ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
				fwrite( STDERR, 'Role SEO quiet transport failed after ' . ( time() - $started ) . "s\n" );
				return false;
			}
			if ( $listed( $probe ) ) {
				continue;
			}
			$status = $probe['status'] ?? 0;
			if ( 429 !== $status ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
				fwrite( STDERR, 'Role SEO quiet unexpected status ' . (int) $status . ' for ' . $actor . ' after ' . ( time() - $started ) . "s\n" );
				return false;
			}
			$cool = false;
			break;
		}
		if ( $cool ) {
			return true;
		}
		sleep( 10 );
	} while ( time() < $deadline );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	fwrite( STDERR, 'Role SEO quiet still limited after ' . ( time() - $started ) . "s\n" );
	return false;
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
 * Reduce one latency bucket to samples, percentiles and error count.
 *
 * Empty buckets stay unavailable, never zero-filled.
 *
 * @param array<int,float> $samples Measured milliseconds.
 * @param int              $errors Error count.
 * @return array<string,mixed>
 */
$xnetvn_agent_hub_wp_reduce = static function ( array $samples, int $errors ): array|string {
	if ( array() === $samples ) {
		return 'unavailable';
	}
	$summary           = BenchmarkSummary::summarize( $samples );
	$summary['errors'] = $errors;
	return $summary;
};

/**
 * Run one B-E matrix file as a subprocess and require a clean sweep.
 *
 * Zero retries: any nonzero exit, unreadable report or failing case ends
 * the round.
 *
 * @param string $group Matrix group file suffix.
 * @return array<string,mixed> Decoded matrix report.
 * @throws \RuntimeException For any matrix failure.
 */
$xnetvn_agent_hub_wp_matrix = static function ( string $group ): array {
	if ( ! in_array( $group, array( 'permissions', 'content', 'media', 'lifecycle' ), true ) ) {
		throw new \RuntimeException( 'Unknown stability group.' );
	}
	$command = 'php /work/tests/E2E/role-seo-' . $group . '.php';
	exec( $command, $output, $status );
	$decoded = json_decode( implode( "\n", $output ), true, 64 );
	if ( 0 !== $status || ! is_array( $decoded ) || ! isset( $decoded['cases'] ) || ! is_array( $decoded['cases'] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
		fwrite( STDERR, 'Role SEO group ' . $group . ' did not complete with exit ' . (int) $status . "\n" );
		$shown = 0;
		foreach ( $output as $line ) {
			$trimmed = trim( (string) $line );
			if ( '' === $trimmed || str_starts_with( $trimmed, '{' ) ) {
				continue;
			}
			if ( $shown >= 5 ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Matrix diagnostics carry only fixed text, never request values.
			fwrite( STDERR, 'Role SEO group ' . $group . ' says: ' . substr( $trimmed, 0, 160 ) . "\n" );
			++$shown;
		}
		// A group that answered with a report but left a case red still
		// names that case, so a flake can be told apart from a crash.
		if ( is_array( $decoded ) && isset( $decoded['cases'] ) && is_array( $decoded['cases'] ) ) {
			foreach ( $decoded['cases'] as $id => $result ) {
				if ( is_array( $result ) && true !== ( $result['ok'] ?? false ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Only the failing case id and allowlisted reason reach diagnostics.
					fwrite( STDERR, 'Role SEO group ' . $group . ' case failed: ' . $id . ' reason ' . (string) ( $result['reason'] ?? 'unknown' ) . "\n" );
				}
			}
		}
		throw new \RuntimeException( 'Stability group did not complete.' );
	}
	foreach ( $decoded['cases'] as $id => $result ) {
		if ( ! is_array( $result ) || true !== ( $result['ok'] ?? false ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Only the failing case id and allowlisted reason reach diagnostics.
			fwrite( STDERR, 'Role SEO group ' . $group . ' case failed: ' . $id . ' reason ' . (string) ( is_array( $result ) ? ( $result['reason'] ?? 'unknown' ) : 'unknown' ) . "\n" );
			throw new \RuntimeException( 'Stability group case failed.' );
		}
	}
	return $decoded;
};

/**
 * Seed, verify and load one isolated phase state.
 *
 * Every phase owns its users, keys, settings snapshot and budgets, so a
 * phase can never inherit heat or leftovers from the previous one. The
 * single-marker fixture guard forbids nested states; phases run strictly
 * one after another with cleanup in between.
 *
 * @param string   $round_run Round run id the phase belongs to.
 * @param string   $tag Short phase tag, keeps run ids within 32 chars.
 * @param callable $action Fixture action runner.
 * @param callable $reader Private state reader.
 * @return array{run:string,tokens:array<string,string>,users:array<string,int>,ledger:array<string,mixed>,harness:RoleSeoHarness,client:McpClient}
 * @throws \RuntimeException For a missing seed, actor or ledger.
 */
$xnetvn_agent_hub_wp_begin_phase = static function ( string $round_run, string $tag, callable $action, callable $reader ): array {
	$site = getenv( 'XNETVN_MATRIX_SITE' );
	$blog = getenv( 'XNETVN_ROLE_SEO_BLOG' );
	$host = getenv( 'wp69' === $site ? 'RTL_ALIAS_WP69' : 'RTL_ALIAS_WP71' );
	if ( ! is_string( $site ) || ! in_array( $site, array( 'wp69', 'wp71' ), true ) || ! is_string( $host ) || '' === $host ) {
		throw new \RuntimeException( 'Role SEO phase target unavailable.' );
	}
	$run = $round_run . '-' . $tag;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Phase run id must reach matrix children through the environment.
	putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $run );
	// The seed and the verify belong to the phase: a failure between them
	// cleans the half seeded state before the error leaves.
	xnetvn_agent_hub_wp_role_seo_stability_seed( $action, $site, $run );
	$state_file = '/work/.local/role-seo/private/' . $site . '/' . $run . '/state.json';
	$state      = $reader( $state_file );
	$tokens     = ( isset( $state['tokens'] ) && is_array( $state['tokens'] ) ) ? $state['tokens'] : array();
	$users      = ( isset( $state['users'] ) && is_array( $state['users'] ) ) ? $state['users'] : array();
	if ( ! isset( $tokens['admin'], $tokens['editor'], $users['admin'] ) ) {
		throw new \RuntimeException( 'Role SEO fixture actors unavailable.' );
	}
	$ledger  = RoleSeoHarness::loadLedger( $state_file );
	$client  = new McpClient( 'https://' . $host, array( $host ), true );
	$harness = new RoleSeoHarness(
		array(
			'target'        => $site,
			'blog'          => (int) ( is_string( $blog ) ? $blog : '1' ),
			'base_url'      => 'https://' . $host,
			'run_id'        => $run,
			'private_state' => $state_file,
		),
		$client,
		$ledger
	);
	return array(
		'run'     => $run,
		'tokens'  => $tokens,
		'users'   => $users,
		'ledger'  => $ledger,
		'harness' => $harness,
		'client'  => $client,
	);
};

/**
 * Clean one phase state and restore the round run id.
 *
 * @param string   $round_run Round run id to restore.
 * @param string   $run Phase run id to clean.
 * @param callable $action Fixture action runner.
 * @throws \RuntimeException For a failed phase cleanup.
 */
$xnetvn_agent_hub_wp_end_phase = static function ( string $round_run, string $run, callable $action ): void {
	try {
		$site = getenv( 'XNETVN_MATRIX_SITE' );
		if ( ! is_string( $site ) || ! in_array( $site, array( 'wp69', 'wp71' ), true ) ) {
			throw new \RuntimeException( 'Role SEO phase target unavailable.' );
		}
		$action( $site, $run, 'cleanup' );
	} finally {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the round run id after the phase sweep.
		putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $round_run );
	}
};

/**
 * Open one STAB-05 upload worker without credentials on argv or in reports.
 *
 * @param string $host Allowlisted lab host.
 * @param string $token Private actor credential.
 * @param string $filename Owned disposable filename.
 * @param string $pixel Fixed test image.
 * @param int    $envelope Envelope identifier.
 * @return CurlHandle
 * @throws RuntimeException When curl is unavailable.
 */
function xnetvn_agent_hub_wp_role_seo_stability_holder( string $host, #[SensitiveParameter] string $token, string $filename, string $pixel, int $envelope ): CurlHandle {
	$handle = curl_init();
	if ( false === $handle ) {
		throw new RuntimeException( 'Test transport unavailable.' );
	}
	curl_setopt_array(
		$handle,
		array(
			CURLOPT_URL            => 'https://' . $host . '/wp-json/xnetvn-agent-hub-wp/mcp',
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => $envelope,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'xnetvn-upload-media-from-data',
						'arguments' => (object) array(
							'filename'    => $filename,
							'mime_type'   => 'image/png',
							'data_base64' => $pixel,
						),
						'_meta'     => array(
							'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
							'io.modelcontextprotocol/clientCapabilities' => (object) array(),
							'io.modelcontextprotocol/clientInfo'         => array(
								'name'    => 'role-seo-acceptance',
								'version' => '1',
							),
						),
					),
				),
				JSON_THROW_ON_ERROR
			),
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'Accept: application/json, text/event-stream', 'Authorization: Bearer ' . $token, 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/call', 'Mcp-Name: xnetvn-upload-media-from-data' ),
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_RETURNTRANSFER => true,
		)
	);
	return $handle;
}

/**
 * Execute the actual STAB-05 two-holder receipt path, without repairing teardown.
 *
 * The optional callback is only a harness seam, invoked after both valid
 * receipts and before the next mutation/delete. Production leaves it null.
 * Closing workers, receipt validation and failure returns retain the original
 * order. The caller still owns the existing barrier finally and phase cleanup.
 *
 * @param callable      $action Fixture control.
 * @param string        $site Validated target.
 * @param string        $run Phase run.
 * @param callable      $open Holder factory.
 * @param callable      $read Completed response reader.
 * @param string        $token Private actor credential.
 * @param string        $pixel Fixed test image.
 * @param callable      $refuse Third-request denial probe.
 * @param callable      $equal Counts comparator.
 * @param callable|null $after_receipts Test-only abort callback.
 * @return array<int,int>|null Valid receipt identifiers or failed probe.
 */
function xnetvn_agent_hub_wp_role_seo_stability_held_uploads( callable $action, string $site, string $run, callable $open, callable $read, #[SensitiveParameter] string $token, string $pixel, callable $refuse, callable $equal, ?callable $after_receipts = null ): ?array {
	$before  = $action( $site, $run, 'counts' );
	$multi   = curl_multi_init();
	$holders = array(
		$open( $token, 'stab-hold-admin-a-' . $run . '.png', $pixel, 901 ),
		$open( $token, 'stab-hold-admin-b-' . $run . '.png', $pixel, 902 ),
	);
	foreach ( $holders as $holder ) {
		curl_multi_add_handle( $multi, $holder );
	}
	$wait_until = time() + 15;
	$waiting    = 0;
	do {
		curl_multi_exec( $multi, $active );
		curl_multi_select( $multi, 1 );
		try {
			$status  = $action( $site, $run, 'stability-barrier', 'status' );
			$waiting = (int) ( $status['waiting'] ?? 0 );
		} catch ( Throwable ) {
			$waiting = 0;
		}
		if ( 2 <= $waiting ) {
			break;
		}
	} while ( time() < $wait_until );
	if ( 2 > $waiting || ! $refuse() || ! $equal( $before, $action( $site, $run, 'counts' ) ) ) {
		foreach ( $holders as $holder ) {
			curl_multi_remove_handle( $multi, $holder );
			curl_close( $holder );
		}
		curl_multi_close( $multi );
		return null;
	}
	$action( $site, $run, 'stability-barrier', 'release' );
	$done   = array();
	$finish = time() + 45;
	do {
		curl_multi_exec( $multi, $active );
		if ( 0 === $active ) {
			break;
		}
		curl_multi_select( $multi, 1 );
	} while ( time() < $finish );
	foreach ( $holders as $holder ) {
		$done[] = $read( $holder );
		curl_multi_remove_handle( $multi, $holder );
		curl_close( $holder );
	}
	curl_multi_close( $multi );
	$ids = array();
	foreach ( $done as $body ) {
		$result = is_array( $body ) ? ( $body['result'] ?? null ) : null;
		if ( ! is_array( $result ) || true === ( $result['isError'] ?? false ) ) {
			return null;
		}
		$data = $result['structuredContent'] ?? null;
		if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
			return null;
		}
		$ids[] = (int) $data['id'];
	}
	if ( 2 !== count( $ids ) ) {
		return null;
	}
	if ( null !== $after_receipts ) {
		$after_receipts( $ids, 0 === $active );
	}
	return $ids;
}

/**
 * Check a disposable regression target before fixture actions.
 *
 * @param mixed    $site Website target from environment.
 * @param mixed    $run Root run identifier from environment.
 * @param callable $read_version Read-only website observer.
 * @param callable $exists Exact run directory observer.
 * @return array<string,mixed> Safe guard report, never private state.
 */
function xnetvn_agent_hub_wp_role_seo_stability_phase_guard( mixed $site, mixed $run, callable $read_version, callable $exists ): array {
	// The website expectation follows the live lab patch release, never the
	// 7.1.2 label of the integration test-suite directory.
	$versions = array(
		'wp69' => '6.9',
		'wp71' => '7.1.4',
	);
	$report   = array(
		'accepted'         => false,
		'status'           => 'invalid_target_or_run',
		'target'           => null,
		'run_id'           => null,
		'version'          => null,
		'expected_version' => null,
		'actual_version'   => null,
	);
	if ( ! is_string( $site ) || ! isset( $versions[ $site ] ) || ! is_string( $run ) || 1 !== preg_match( '/\A[a-z0-9-]{1,25}\z/', $run ) ) {
		return $report;
	}
	$report['target']           = $site;
	$report['run_id']           = $run;
	$report['expected_version'] = $versions[ $site ];
	$actual                     = $read_version( $site );
	// Report only known numeric version syntax, never raw CLI output.
	$report['actual_version'] = is_string( $actual ) && 1 === preg_match( '/\A[0-9]+(?:\.[0-9]+){1,2}\z/', $actual ) ? $actual : null;
	$report['version']        = $report['actual_version'];
	$report['status']         = 'version_mismatch';
	if ( $versions[ $site ] !== $actual ) {
		return $report;
	}
	foreach ( array( '', '-a', '-b', '-c' ) as $suffix ) {
		if ( $exists( $site, $run . $suffix ) ) {
			$report['status'] = 'existing_run_requires_reconcile';
			return $report;
		}
	}
	$report['accepted'] = true;
	$report['status']   = 'ready';
	return $report;
}

/**
 * Existing STAB-05 barrier finally, shared without changing cleanup policy.
 *
 * @param callable $action Fixture control.
 * @param string   $site Validated target.
 * @param string   $run Owned phase run.
 * @param bool     $released Whether the normal path released the barrier.
 * @throws RuntimeException When the barrier cannot be reset.
 */
function xnetvn_agent_hub_wp_role_seo_stability_reset_barrier( callable $action, string $site, string $run, bool $released ): void {
	if ( ! $released ) {
		try {
			$action( $site, $run, 'stability-barrier', 'release' );
		} catch ( Throwable ) {
			throw new RuntimeException( 'Stability barrier was left armed.' );
		}
	}
	try {
		$action( $site, $run, 'stability-barrier', 'none' );
	} catch ( Throwable ) {
		throw new RuntimeException( 'Stability barrier was left armed.' );
	}
}

// Regression of the phase lifecycle: a phase whose cron digest drifts or
// whose verify fails must leave nothing behind in the shared lab.
if ( 'phase-cleanup' === $xnetvn_agent_hub_wp_mode ) {
	$xnetvn_agent_hub_wp_phase_site  = getenv( 'XNETVN_MATRIX_SITE' );
	$xnetvn_agent_hub_wp_phase_stamp = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
	$xnetvn_agent_hub_wp_phase_guard = xnetvn_agent_hub_wp_role_seo_stability_phase_guard(
		$xnetvn_agent_hub_wp_phase_site,
		$xnetvn_agent_hub_wp_phase_stamp,
		static function ( string $site ): ?string {
			$output  = array();
			$status  = 0;
			$command = implode( ' ', array_map( 'escapeshellarg', array( 'bash', '/work/infra/remote/run-wp.sh', $site, 'core', 'version' ) ) );
			exec( $command, $output, $status );
			return 0 === $status ? trim( implode( "\n", $output ) ) : null;
		},
		static function ( string $site, string $run ): bool {
			$dir = '/work/.local/role-seo/private/' . $site . '/' . $run;
			return file_exists( $dir ) || is_link( $dir );
		}
	);
	if ( true !== $xnetvn_agent_hub_wp_phase_guard['accepted'] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Guard report contains only allowlisted target/run/version and status.
		echo json_encode( $xnetvn_agent_hub_wp_phase_guard, JSON_THROW_ON_ERROR ) . "\n";
		exit( 2 );
	}
	$xnetvn_agent_hub_wp_phase_dir = '/work/.local/role-seo/private/' . $xnetvn_agent_hub_wp_phase_site;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The phase run id must reach the fixture through the environment.
	putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_phase_stamp );
	$xnetvn_agent_hub_wp_phase_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_stamp, 'counts' );
	// Case A: the cron digest drifts between the seed and the verify. The
	// verify must report the drift instead of aborting the phase.
	$xnetvn_agent_hub_wp_phase_run_a          = $xnetvn_agent_hub_wp_phase_stamp . '-a';
	$xnetvn_agent_hub_wp_phase_drift          = false;
	$xnetvn_agent_hub_wp_phase_cleanup_failed = false;
	$xnetvn_agent_hub_wp_phase_verify_failed  = false;
	try {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The phase run id must reach the fixture through the environment.
		putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_phase_run_a );
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_a, 'seed' );
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_a, 'cron-drift' );
		$xnetvn_agent_hub_wp_phase_verify = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_a, 'verify' );
		$xnetvn_agent_hub_wp_phase_drift  = true === ( $xnetvn_agent_hub_wp_phase_verify['cron_drift'] ?? false );
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_phase_drift = false;
	}
	try {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The phase run id must reach the fixture through the environment.
		putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_phase_run_a );
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_a, 'cleanup' );
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_phase_cleanup_failed = true;
	}
	// Case B: the verify fails after the seed. The phase must clean itself.
	$xnetvn_agent_hub_wp_phase_run_b = $xnetvn_agent_hub_wp_phase_stamp . '-b';
	try {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The phase run id must reach the fixture through the environment.
		putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_phase_run_b );
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_b, 'seed' );
		// The rate limit variant drifts the settings digest, so the verify
		// of this phase must fail after the seed.
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_b, 'site-variant', 'rate-low' );
		xnetvn_agent_hub_wp_role_seo_stability_seed( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_b );
	} catch ( \Throwable ) {
		// The expected failure of the verify: the phase cleaned itself.
		$xnetvn_agent_hub_wp_phase_verify_failed = true;
	}
	// Case C uses the sweep's actual two-holder upload/receipt path. Its
	// injected exception occurs only after two valid completed receipts,
	// before the normal delete branch. Do not repair the leak in this RED.
	if ( $xnetvn_agent_hub_wp_phase_cleanup_failed || file_exists( $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_a . '/state.json' ) || file_exists( $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_b . '/state.json' ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed non-sensitive blocked outcome; never seed over an unreconciled phase.
		echo "{\"status\":\"prior_phase_requires_reconcile\",\"abort_observed\":false}\n";
		exit( 2 );
	}
	$xnetvn_agent_hub_wp_phase_run_c          = $xnetvn_agent_hub_wp_phase_stamp . '-c';
	$xnetvn_agent_hub_wp_phase_abort          = false;
	$xnetvn_agent_hub_wp_phase_abort_ids      = array();
	$xnetvn_agent_hub_wp_phase_workers_closed = false;
	$xnetvn_agent_hub_wp_phase_barrier_idle   = false;
	$xnetvn_agent_hub_wp_phase_abort_absent   = false;
	try {
		$xnetvn_agent_hub_wp_phase_ctx = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_phase_stamp, 'c', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
		if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_phase_ctx['client'], $xnetvn_agent_hub_wp_phase_ctx['tokens'], array( 'admin' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
			throw new RuntimeException( 'Role SEO rate windows stayed hot.' );
		}
		$xnetvn_agent_hub_wp_phase_pixel  = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
		$xnetvn_agent_hub_wp_phase_open   = static fn( string $token, string $filename, string $pixel, int $envelope ): CurlHandle => xnetvn_agent_hub_wp_role_seo_stability_holder( $xnetvn_agent_hub_wp_host, $token, $filename, $pixel, $envelope );
		$xnetvn_agent_hub_wp_phase_read   = static function ( CurlHandle $handle ): ?array {
			$raw     = curl_multi_getcontent( $handle );
			$decoded = is_string( $raw ) ? json_decode( $raw, true, 64 ) : null;
			return is_array( $decoded ) ? $decoded : null;
		};
		$xnetvn_agent_hub_wp_phase_refuse = static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_phase_ctx, $xnetvn_agent_hub_wp_phase_run_c, $xnetvn_agent_hub_wp_phase_pixel, $xnetvn_agent_hub_wp_denied ): bool {
			$envelope = 903;
			$reply    = $xnetvn_agent_hub_wp_tool(
				$xnetvn_agent_hub_wp_phase_ctx['client'],
				$envelope,
				$xnetvn_agent_hub_wp_phase_ctx['tokens']['admin'],
				'upload-media-from-data',
				array(
					'filename'    => 'stab-refused-' . $xnetvn_agent_hub_wp_phase_run_c . '.png',
					'mime_type'   => 'image/png',
					'data_base64' => $xnetvn_agent_hub_wp_phase_pixel,
				)
			);
			return $xnetvn_agent_hub_wp_denied( $reply, 'rate_limited' );
		};
		$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_c, 'stability-barrier', 'hold' );
		try {
			xnetvn_agent_hub_wp_role_seo_stability_held_uploads(
				$xnetvn_agent_hub_wp_action,
				$xnetvn_agent_hub_wp_phase_site,
				$xnetvn_agent_hub_wp_phase_run_c,
				$xnetvn_agent_hub_wp_phase_open,
				$xnetvn_agent_hub_wp_phase_read,
				$xnetvn_agent_hub_wp_phase_ctx['tokens']['admin'],
				$xnetvn_agent_hub_wp_phase_pixel,
				$xnetvn_agent_hub_wp_phase_refuse,
				$xnetvn_agent_hub_wp_counts_equal,
				static function ( array $ids, bool $closed ) use ( &$xnetvn_agent_hub_wp_phase_abort_ids, &$xnetvn_agent_hub_wp_phase_workers_closed, $xnetvn_agent_hub_wp_phase_dir, $xnetvn_agent_hub_wp_phase_run_c, $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_ctx ): void {
					$xnetvn_agent_hub_wp_phase_abort_ids      = $ids;
					$xnetvn_agent_hub_wp_phase_workers_closed = $closed;

					$binding_code = '$ids = array(' . implode( ',', $ids ) . '); $ok = true; foreach ($ids as $id) { $p = get_post($id); $ok = $ok && null !== $p && "attachment" === $p->post_type && ' . (int) $xnetvn_agent_hub_wp_phase_ctx['users']['admin'] . ' === (int) $p->post_author; } echo $ok ? "1" : "0";';
					$binding_argv = implode( ' ', array_map( 'escapeshellarg', array( 'bash', '/work/infra/remote/run-wp.sh', $xnetvn_agent_hub_wp_phase_site, 'eval', $binding_code ) ) );
					$binding_out  = array();
					$binding_exit = 0;
					exec( $binding_argv, $binding_out, $binding_exit );
					$binding = 0 === $binding_exit && '1' === trim( implode( "\n", $binding_out ) );
					// Separate private regression receipt, never write fixture state
					// from another process or silently enroll it in the seed ledger.
					$file = $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_c . '/abort-receipts.json';
					if ( false === file_put_contents(
						$file,
						json_encode(
							array(
								'target'           => $xnetvn_agent_hub_wp_phase_site,
								'blog'             => 1,
								'run_id'           => $xnetvn_agent_hub_wp_phase_run_c,
								'actor'            => $xnetvn_agent_hub_wp_phase_ctx['users']['admin'],
								'ids'              => $ids,
								'state'            => 'state.json',
								'binding_verified' => $binding,
							),
							JSON_THROW_ON_ERROR
						),
						LOCK_EX
					) || ! chmod( $file, 0600 ) ) {
						throw new RuntimeException( 'Abort receipt persistence failed.' );
					}
					if ( ! $binding ) {
						throw new RuntimeException( 'Abort receipt binding unavailable.' );
					}
					throw new RuntimeException( 'Owned STAB-05 receipt abort.' );
				}
			);
		} finally {
			xnetvn_agent_hub_wp_role_seo_stability_reset_barrier( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_c, false );
			$xnetvn_agent_hub_wp_phase_barrier      = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_run_c, 'stability-barrier', 'status' );
			$xnetvn_agent_hub_wp_phase_barrier_idle = 0 === ( $xnetvn_agent_hub_wp_phase_barrier['waiting'] ?? -1 );
		}
	} catch ( Throwable $fault ) {
		$xnetvn_agent_hub_wp_phase_abort = 'Owned STAB-05 receipt abort.' === $fault->getMessage();
	} finally {
		try {
			$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_phase_stamp, $xnetvn_agent_hub_wp_phase_run_c, $xnetvn_agent_hub_wp_action );
		} catch ( Throwable ) {
			$xnetvn_agent_hub_wp_phase_cleanup_failed = true;
		}
	}
	// Exact receipted IDs only; no prefix, trash scan, or deletion fallback.
	if ( 2 === count( $xnetvn_agent_hub_wp_phase_abort_ids ) ) {
		$xnetvn_agent_hub_wp_phase_observed = array();
		$xnetvn_agent_hub_wp_phase_status   = 0;
		$xnetvn_agent_hub_wp_phase_command  = implode( ' ', array_map( 'escapeshellarg', array( 'bash', '/work/infra/remote/run-wp.sh', $xnetvn_agent_hub_wp_phase_site, 'eval', '$ids = array(' . implode( ',', $xnetvn_agent_hub_wp_phase_abort_ids ) . '); echo count(array_filter($ids, static fn($id) => null !== get_post($id)));' ) ) );
		exec( $xnetvn_agent_hub_wp_phase_command, $xnetvn_agent_hub_wp_phase_observed, $xnetvn_agent_hub_wp_phase_status );
		$xnetvn_agent_hub_wp_phase_abort_absent = 0 === $xnetvn_agent_hub_wp_phase_status && '0' === trim( implode( "\n", $xnetvn_agent_hub_wp_phase_observed ) );
	}
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The phase run id must reach the fixture through the environment.
	putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_phase_stamp );
	$xnetvn_agent_hub_wp_phase_after      = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_phase_site, $xnetvn_agent_hub_wp_phase_stamp, 'counts' );
	$xnetvn_agent_hub_wp_phase_leftover_a = file_exists( $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_a . '/state.json' );
	$xnetvn_agent_hub_wp_phase_leftover_b = file_exists( $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_b . '/state.json' );
	$xnetvn_agent_hub_wp_phase_leftover_c = file_exists( $xnetvn_agent_hub_wp_phase_dir . '/' . $xnetvn_agent_hub_wp_phase_run_c . '/state.json' );
	$xnetvn_agent_hub_wp_phase_report     = array(
		'suite'                => 'role-seo-stability-phase-cleanup',
		'target'               => $xnetvn_agent_hub_wp_phase_site,
		'run_id'               => $xnetvn_agent_hub_wp_phase_stamp,
		'version'              => $xnetvn_agent_hub_wp_phase_guard['version'],
		'expected_version'     => $xnetvn_agent_hub_wp_phase_guard['expected_version'],
		'actual_version'       => $xnetvn_agent_hub_wp_phase_guard['actual_version'],
		'abort_observed'       => $xnetvn_agent_hub_wp_phase_abort,
		'abort_ids_absent'     => $xnetvn_agent_hub_wp_phase_abort_absent,
		'barrier_idle'         => $xnetvn_agent_hub_wp_phase_barrier_idle,
		'workers_closed'       => $xnetvn_agent_hub_wp_phase_workers_closed,
		'settings_restored'    => isset( $xnetvn_agent_hub_wp_phase_before['settings_digest'], $xnetvn_agent_hub_wp_phase_after['settings_digest'] ) && $xnetvn_agent_hub_wp_phase_before['settings_digest'] === $xnetvn_agent_hub_wp_phase_after['settings_digest'],
		'cron_drift_reported'  => $xnetvn_agent_hub_wp_phase_drift,
		'drift_case_clean'     => ( ! $xnetvn_agent_hub_wp_phase_leftover_a ) && ! $xnetvn_agent_hub_wp_phase_cleanup_failed,
		'failed_case_clean'    => ( ! $xnetvn_agent_hub_wp_phase_leftover_b ) && $xnetvn_agent_hub_wp_phase_verify_failed,
		'leftover_users'       => (int) ( $xnetvn_agent_hub_wp_phase_after['users'] ?? 0 ) - (int) ( $xnetvn_agent_hub_wp_phase_before['users'] ?? 0 ),
		'leftover_keys'        => (int) ( $xnetvn_agent_hub_wp_phase_after['keys'] ?? 0 ) - (int) ( $xnetvn_agent_hub_wp_phase_before['keys'] ?? 0 ),
		'leftover_posts'       => (int) ( $xnetvn_agent_hub_wp_phase_after['posts'] ?? 0 ) - (int) ( $xnetvn_agent_hub_wp_phase_before['posts'] ?? 0 ),
		'leftover_terms'       => (int) ( $xnetvn_agent_hub_wp_phase_after['terms'] ?? 0 ) - (int) ( $xnetvn_agent_hub_wp_phase_before['terms'] ?? 0 ),
		'leftover_media'       => (int) ( $xnetvn_agent_hub_wp_phase_after['media'] ?? 0 ) - (int) ( $xnetvn_agent_hub_wp_phase_before['media'] ?? 0 ),
		'leftover_state_files' => (int) $xnetvn_agent_hub_wp_phase_leftover_a + (int) $xnetvn_agent_hub_wp_phase_leftover_b + (int) $xnetvn_agent_hub_wp_phase_leftover_c,
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
	echo json_encode( $xnetvn_agent_hub_wp_phase_report, JSON_THROW_ON_ERROR ) . "\n";
	$xnetvn_agent_hub_wp_phase_ok = ( $xnetvn_agent_hub_wp_phase_drift && $xnetvn_agent_hub_wp_phase_report['drift_case_clean'] && $xnetvn_agent_hub_wp_phase_report['failed_case_clean'] && 0 === $xnetvn_agent_hub_wp_phase_report['leftover_state_files'] && 0 === $xnetvn_agent_hub_wp_phase_report['leftover_users'] );
	foreach ( array( 'keys', 'posts', 'terms', 'media' ) as $xnetvn_agent_hub_wp_phase_kind ) {
		$xnetvn_agent_hub_wp_phase_ok = $xnetvn_agent_hub_wp_phase_ok && 0 === $xnetvn_agent_hub_wp_phase_report[ 'leftover_' . $xnetvn_agent_hub_wp_phase_kind ];
	}
	$xnetvn_agent_hub_wp_phase_ok = $xnetvn_agent_hub_wp_phase_ok && $xnetvn_agent_hub_wp_phase_abort && $xnetvn_agent_hub_wp_phase_abort_absent && $xnetvn_agent_hub_wp_phase_barrier_idle && $xnetvn_agent_hub_wp_phase_workers_closed && $xnetvn_agent_hub_wp_phase_report['settings_restored'];
	exit( $xnetvn_agent_hub_wp_phase_ok ? 0 : 1 );
}

// Campaign seed state: site totals before round one prove zero leakage.
$xnetvn_agent_hub_wp_latency        = array(
	'round_ms'             => array(),
	'matrix_ms'            => array(),
	'allowed_read_ms'      => array(),
	'denied_read_ms'       => array(),
	'allowed_write_ms'     => array(),
	'denied_write_ms'      => array(),
	'unavailable_read_ms'  => array(),
	'unavailable_write_ms' => array(),
);
$xnetvn_agent_hub_wp_latency_errors = array(
	'round_ms'             => 0,
	'matrix_ms'            => 0,
	'allowed_read_ms'      => 0,
	'denied_read_ms'       => 0,
	'allowed_write_ms'     => 0,
	'denied_write_ms'      => 0,
	'unavailable_read_ms'  => 0,
	'unavailable_write_ms' => 0,
);
// Per actor and operation buckets keep their own samples, classes and
// errors; a bucket without samples stays unavailable in the report.
$xnetvn_agent_hub_wp_actor_buckets  = array();
$xnetvn_agent_hub_wp_actor_memory   = array();
$xnetvn_agent_hub_wp_memory_samples = array();
$xnetvn_agent_hub_wp_rounds_ok      = 0;
$xnetvn_agent_hub_wp_bench          = array(
	'prerequisite' => false,
	'gates'        => 'unavailable',
);
try {
	$xnetvn_agent_hub_wp_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_base_run, 'counts' );
} catch ( \Throwable ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
	echo "Role SEO fixture counts unavailable; reconcile before running the matrix.\n";
	exit( 2 );
}

for ( $xnetvn_agent_hub_wp_round = 1; $xnetvn_agent_hub_wp_round <= 5; ++$xnetvn_agent_hub_wp_round ) {
	$xnetvn_agent_hub_wp_run_id    = $xnetvn_agent_hub_wp_base_run . '-r' . $xnetvn_agent_hub_wp_round;
	$xnetvn_agent_hub_wp_round_run = $xnetvn_agent_hub_wp_run_id;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Round run id must reach matrix children through the environment.
	putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_run_id );
	$xnetvn_agent_hub_wp_round_ok = true;
	try {
		$xnetvn_agent_hub_wp_round_counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );

		// Phase s4 doubles as the STAB-04 state (round 1): virgin keys, no
		// shared state with any other phase.
		if ( 1 === $xnetvn_agent_hub_wp_round ) {
			$xnetvn_agent_hub_wp_phase_run = $xnetvn_agent_hub_wp_round_run . '-s4';
			try {
				$xnetvn_agent_hub_wp_ctx     = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, 's4', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
				$xnetvn_agent_hub_wp_run_id  = $xnetvn_agent_hub_wp_ctx['run'];
				$xnetvn_agent_hub_wp_tokens  = $xnetvn_agent_hub_wp_ctx['tokens'];
				$xnetvn_agent_hub_wp_users   = $xnetvn_agent_hub_wp_ctx['users'];
				$xnetvn_agent_hub_wp_ledger  = $xnetvn_agent_hub_wp_ctx['ledger'];
				$xnetvn_agent_hub_wp_harness = $xnetvn_agent_hub_wp_ctx['harness'];
				$xnetvn_agent_hub_wp_client  = $xnetvn_agent_hub_wp_ctx['client'];
				// Cool the shared rate windows before measuring: neighboring
				// suites reset limits while buckets are still hot. The editor
				// key probes so the admin key stays virgin for STAB-04.
				if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
					throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
				}
				// STAB-04 runs first on a fresh key bucket, before B-E traffic.
				$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
					$xnetvn_agent_hub_wp_timings,
					'STAB-04',
					static function () use ( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_host, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ): bool {
						$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'rate-low' );
						try {
							$envelope = 1;
							$allowed  = 0;
							for ( $request = 0; $request < 5; ++$request ) {
								$response = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'tools/list', array() );
								if ( ! $xnetvn_agent_hub_wp_listed( $response ) ) {
									return false;
								}
								++$allowed;
							}
							if ( 5 !== $allowed ) {
								return false;
							}
							$limited = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'tools/list', array() );
							if ( 429 !== ( $limited['status'] ?? 0 ) ) {
								return false;
							}
							$retry_after = $limited['headers']['retry-after'] ?? '';
							if ( ! is_string( $retry_after ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $retry_after ) ) {
								return false;
							}
							// Every probe consumes budget too, so fixed-interval
							// polling would refill the window it waits on. Sleep
							// past the advertised window once, then probe a
							// single time; the intermediate minute stays empty.
							$probe = array( 'status' => 429 );
							for ( $wait = 0; $wait < 3 && 429 === ( $probe['status'] ?? 0 ); ++$wait ) {
								sleep( (int) $retry_after + 5 );
								$probe = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'tools/list', array() );
								if ( 429 === ( $probe['status'] ?? 0 ) ) {
									$retry_after = $probe['headers']['retry-after'] ?? '';
									if ( ! is_string( $retry_after ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $retry_after ) ) {
										return false;
									}
								}
							}
							return $xnetvn_agent_hub_wp_listed( $probe );
						} finally {
							$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'site-variant', 'reset' );
						}
					}
				);
				$xnetvn_agent_hub_wp_record( 'STAB-04', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'rate_limited' );
				$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
			} finally {
				$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_phase_run, $xnetvn_agent_hub_wp_action );
				// Restore the round run id: the s4 phase overwrote the run
				// variable, and the B-E sweep below must parent on the round,
				// never nest inside s4 (which would push run ids to 32 chars
				// and leak s4 state into the sweep).
				$xnetvn_agent_hub_wp_run_id = $xnetvn_agent_hub_wp_round_run;
			}
		}

		// STAB-01: one clean sweep of every B-E matrix, sequentially. Each
		// group seeds its own state like the original per-group gates, so a
		// group can never inherit users, keys, settings or budgets from the
		// previous sweep; phases run strictly one after another because the
		// single-marker fixture forbids nested states.
		$xnetvn_agent_hub_wp_ok                    = $xnetvn_agent_hub_wp_time(
			$xnetvn_agent_hub_wp_timings,
			'round-' . $xnetvn_agent_hub_wp_round,
			static function () use ( $xnetvn_agent_hub_wp_matrix, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_round_run, &$xnetvn_agent_hub_wp_latency, $xnetvn_agent_hub_wp_quiet, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed, $xnetvn_agent_hub_wp_begin_phase, $xnetvn_agent_hub_wp_end_phase, $xnetvn_agent_hub_wp_private ): bool {
				foreach ( array( 'permissions', 'content', 'media', 'lifecycle' ) as $xnetvn_agent_hub_wp_group ) {
					$xnetvn_agent_hub_wp_tag       = $xnetvn_agent_hub_wp_group[0];
					$xnetvn_agent_hub_wp_group_ok  = false;
					$xnetvn_agent_hub_wp_group_run = $xnetvn_agent_hub_wp_round_run . '-' . $xnetvn_agent_hub_wp_tag;
					try {
						$xnetvn_agent_hub_wp_ctx = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_tag, $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
						// Cool the shared windows first: the previous phase
						// reset limits while buckets stay hot. The editor key
						// probes so every other key stays virgin.
						if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_ctx['client'], $xnetvn_agent_hub_wp_ctx['tokens'], array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
							throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
						}
						$start = hrtime( true );
						try {
							$xnetvn_agent_hub_wp_matrix( $xnetvn_agent_hub_wp_group );
							$xnetvn_agent_hub_wp_group_ok = true;
						} finally {
							$xnetvn_agent_hub_wp_latency['matrix_ms'][] = ( hrtime( true ) - $start ) / 1000000;
						}
					} finally {
						try {
							$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_group_run, $xnetvn_agent_hub_wp_action );
						} catch ( \Throwable ) {
							$xnetvn_agent_hub_wp_group_ok = false;
						}
					}
					if ( ! $xnetvn_agent_hub_wp_group_ok ) {
						return false;
					}
				}
				return true;
			}
		);
		$xnetvn_agent_hub_wp_latency['round_ms'][] = $xnetvn_agent_hub_wp_timings[ 'round-' . $xnetvn_agent_hub_wp_round ];
		if ( ! $xnetvn_agent_hub_wp_ok ) {
			++$xnetvn_agent_hub_wp_latency_errors['round_ms'];
			$xnetvn_agent_hub_wp_round_ok = false;
		}

		// Phase x hosts the round probes on its own isolated state.
		$xnetvn_agent_hub_wp_phase_run = $xnetvn_agent_hub_wp_round_run . '-x';
		try {
			$xnetvn_agent_hub_wp_ctx     = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, 'x', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
			$xnetvn_agent_hub_wp_run_id  = $xnetvn_agent_hub_wp_ctx['run'];
			$xnetvn_agent_hub_wp_tokens  = $xnetvn_agent_hub_wp_ctx['tokens'];
			$xnetvn_agent_hub_wp_users   = $xnetvn_agent_hub_wp_ctx['users'];
			$xnetvn_agent_hub_wp_ledger  = $xnetvn_agent_hub_wp_ctx['ledger'];
			$xnetvn_agent_hub_wp_harness = $xnetvn_agent_hub_wp_ctx['harness'];
			$xnetvn_agent_hub_wp_client  = $xnetvn_agent_hub_wp_ctx['client'];
			if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
				throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
			}
			// STAB-02 probes: every declared actor is measured with the same
			// three operations, so the report separates the actors and the
			// operations instead of one admin read and one denied write.
			// The denied write stays denied under any allowlisted reason; the
			// exact reason per actor is pinned by the permission matrix.
			$xnetvn_agent_hub_wp_probe_ok = $xnetvn_agent_hub_wp_time(
				$xnetvn_agent_hub_wp_timings,
				'probe-round-' . $xnetvn_agent_hub_wp_round,
				static function () use ( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_harness, $xnetvn_agent_hub_wp_run_id, &$xnetvn_agent_hub_wp_latency, &$xnetvn_agent_hub_wp_latency_errors, &$xnetvn_agent_hub_wp_actor_buckets ): bool {
					$envelope = 500;
					$actors   = array();
					foreach ( array( 'admin', 'editor', 'author', 'contributor', 'subscriber' ) as $xnetvn_agent_hub_wp_actor ) {
						if ( isset( $xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_actor ] ) && is_string( $xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_actor ] ) && '' !== $xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_actor ] ) {
							$actors[ $xnetvn_agent_hub_wp_actor ] = $xnetvn_agent_hub_wp_tokens[ $xnetvn_agent_hub_wp_actor ];
						}
					}
					if ( array() === $actors ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
						fwrite( STDERR, "Role SEO probe actors unavailable.\n" );
						return false;
					}
					$probe = xnetvn_agent_hub_wp_role_seo_stability_probe(
						array(
							'tasks'   => xnetvn_agent_hub_wp_role_seo_stability_tasks( $actors, 'Stability probe ' . $xnetvn_agent_hub_wp_run_id ),
							'tokens'  => $actors,
							'prepare' => static function () use ( $xnetvn_agent_hub_wp_harness ): array {
								return $xnetvn_agent_hub_wp_harness->resolveIds( array( 'id' => 101 ) );
							},
							'send'    => static function ( string $token, string $tool, array $arguments ) use ( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_send, &$envelope ): array {
								return $xnetvn_agent_hub_wp_send(
									$xnetvn_agent_hub_wp_client,
									$envelope,
									$token,
									'tools/call',
									array(
										'name'      => $tool,
										'arguments' => (object) $arguments,
									)
								);
							},
						)
					);
					if ( ! $probe['ok'] ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
						fwrite( STDERR, "Role SEO probe ids unavailable.\n" );
						return false;
					}
					$ok = true;
					foreach ( $probe['samples'] as $xnetvn_agent_hub_wp_key => $xnetvn_agent_hub_wp_sample ) {
						$xnetvn_agent_hub_wp_parts     = explode( '|', (string) $xnetvn_agent_hub_wp_key );
						$xnetvn_agent_hub_wp_operation = $xnetvn_agent_hub_wp_parts[1] ?? '';
						$xnetvn_agent_hub_wp_class     = (string) ( $xnetvn_agent_hub_wp_sample['class'] ?? 'unavailable' );
						$xnetvn_agent_hub_wp_read      = in_array( $xnetvn_agent_hub_wp_operation, array( 'session', 'post-read' ), true );
						if ( ! isset( $xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ] ) ) {
							$xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ] = array(
								'ms'      => array(),
								'classes' => array(),
								'reasons' => array(),
								'errors'  => 0,
							);
						}
						if ( 'unavailable' === $xnetvn_agent_hub_wp_class ) {
							// An answer that proves neither an allowed nor a
							// denied outcome never becomes a metric.
							++$xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['errors'];
							++$xnetvn_agent_hub_wp_latency_errors[ $xnetvn_agent_hub_wp_read ? 'unavailable_read_ms' : 'unavailable_write_ms' ];
							$ok = false;
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
							fwrite( STDERR, 'Role SEO probe outcome unavailable for actor ' . (string) ( $xnetvn_agent_hub_wp_parts[0] ?? '' ) . ' operation ' . $xnetvn_agent_hub_wp_operation . '.' . "\n" );
							continue;
						}
						$xnetvn_agent_hub_wp_bucket                                   = 'allowed' === $xnetvn_agent_hub_wp_class
							? ( $xnetvn_agent_hub_wp_read ? 'allowed_read_ms' : 'allowed_write_ms' )
							: ( $xnetvn_agent_hub_wp_read ? 'denied_read_ms' : 'denied_write_ms' );
						$xnetvn_agent_hub_wp_latency[ $xnetvn_agent_hub_wp_bucket ][] = (float) $xnetvn_agent_hub_wp_sample['ms'];
						$xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['ms'][]                                  = (float) $xnetvn_agent_hub_wp_sample['ms'];
						$xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['classes'][ $xnetvn_agent_hub_wp_class ] = (int) ( $xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['classes'][ $xnetvn_agent_hub_wp_class ] ?? 0 ) + 1;
						$xnetvn_agent_hub_wp_reason = (string) ( $xnetvn_agent_hub_wp_sample['reason'] ?? 'unavailable' );
						$xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['reasons'][ $xnetvn_agent_hub_wp_reason ] = (int) ( $xnetvn_agent_hub_wp_actor_buckets[ $xnetvn_agent_hub_wp_key ]['reasons'][ $xnetvn_agent_hub_wp_reason ] ?? 0 ) + 1;
					}
					// The created posts are owned by the suite: the editor key
					// removes them again so the round stays leak free.
					foreach ( $probe['owned'] as $xnetvn_agent_hub_wp_owned ) {
						$deleted = $xnetvn_agent_hub_wp_send(
							$xnetvn_agent_hub_wp_client,
							$envelope,
							$actors['editor'],
							'tools/call',
							array(
								'name'      => 'xnetvn-delete-post',
								'arguments' => (object) array( 'id' => (int) $xnetvn_agent_hub_wp_owned ),
							)
						);
						$gone    = $xnetvn_agent_hub_wp_success( $deleted );
						if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
							$ok = false;
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
							fwrite( STDERR, "Role SEO probe cleanup failed.\n" );
						}
					}
					return $ok;
				}
			);
			if ( ! $xnetvn_agent_hub_wp_probe_ok ) {
				$xnetvn_agent_hub_wp_round_ok = false;
			}
			try {
				$xnetvn_agent_hub_wp_probe = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-probe', 'round-' . $xnetvn_agent_hub_wp_round, 'admin' );
				if ( isset( $xnetvn_agent_hub_wp_probe['baseline_bytes'], $xnetvn_agent_hub_wp_probe['peak_bytes'], $xnetvn_agent_hub_wp_probe['samples'] ) && is_int( $xnetvn_agent_hub_wp_probe['baseline_bytes'] ) && is_int( $xnetvn_agent_hub_wp_probe['peak_bytes'] ) && is_int( $xnetvn_agent_hub_wp_probe['samples'] ) ) {
					$xnetvn_agent_hub_wp_memory_samples[] = array(
						'round'    => $xnetvn_agent_hub_wp_round,
						'baseline' => $xnetvn_agent_hub_wp_probe['baseline_bytes'],
						'peak'     => $xnetvn_agent_hub_wp_probe['peak_bytes'],
					);
				}
			} catch ( \Throwable ) {
				$xnetvn_agent_hub_wp_memory_samples[] = 'unavailable';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
				fwrite( STDERR, "Role SEO memory probe unavailable.\n" );
			}
			// The numeric memory probe runs once per actor, so every actor of
			// the report carries its own peak delta bytes.
			foreach ( array( 'admin', 'editor', 'author', 'contributor', 'subscriber' ) as $xnetvn_agent_hub_wp_actor ) {
				try {
					$xnetvn_agent_hub_wp_actor_probe = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-probe', 'round-' . $xnetvn_agent_hub_wp_round, $xnetvn_agent_hub_wp_actor );
					if ( isset( $xnetvn_agent_hub_wp_actor_probe['baseline_bytes'], $xnetvn_agent_hub_wp_actor_probe['peak_bytes'] ) && is_int( $xnetvn_agent_hub_wp_actor_probe['baseline_bytes'] ) && is_int( $xnetvn_agent_hub_wp_actor_probe['peak_bytes'] ) ) {
						$xnetvn_agent_hub_wp_actor_memory[ $xnetvn_agent_hub_wp_actor ] = max( (int) ( $xnetvn_agent_hub_wp_actor_memory[ $xnetvn_agent_hub_wp_actor ] ?? 0 ), (int) $xnetvn_agent_hub_wp_actor_probe['peak_bytes'] - (int) $xnetvn_agent_hub_wp_actor_probe['baseline_bytes'] );
					}
				} catch ( \Throwable ) {
					// A missing probe keeps the actor memory unavailable.
					continue;
				}
			}

			// STAB-03 prerequisite probe on wp69; gates stay with the standalone
			// benchmark run after role-seo cleanup, never from this matrix.
			if ( 'wp69' === $xnetvn_agent_hub_wp_site && 1 === $xnetvn_agent_hub_wp_round ) {
				try {
					$xnetvn_agent_hub_wp_bench_envelope        = 800;
					$xnetvn_agent_hub_wp_bench_probe           = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_bench_envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'tools/list', array() );
					$xnetvn_agent_hub_wp_bench_headers         = $xnetvn_agent_hub_wp_bench_probe['headers'] ?? array();
					$xnetvn_agent_hub_wp_bench['prerequisite'] = 200 === ( $xnetvn_agent_hub_wp_bench_probe['status'] ?? 0 )
						&& '1' === ( $xnetvn_agent_hub_wp_bench_headers['x-xnetvn-benchmark-persistent-cache'] ?? '' )
						&& '6.9' === ( $xnetvn_agent_hub_wp_bench_headers['x-xnetvn-benchmark-wordpress'] ?? '' )
						&& '0.7.0' === ( $xnetvn_agent_hub_wp_bench_headers['x-xnetvn-benchmark-adapter'] ?? '' )
						&& str_starts_with( (string) ( $xnetvn_agent_hub_wp_bench_headers['x-xnetvn-benchmark-php'] ?? '' ), '8.2.' );
				} catch ( \Throwable ) {
					$xnetvn_agent_hub_wp_bench['prerequisite'] = false;
				}
			}
		} finally {
			$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_phase_run, $xnetvn_agent_hub_wp_action );
		}

		if ( 1 === $xnetvn_agent_hub_wp_round ) {
			// Phase s5 hosts the STAB-05 costly-budget contention on its own isolated state.
			$xnetvn_agent_hub_wp_phase_run = $xnetvn_agent_hub_wp_round_run . '-s5';
			try {
				$xnetvn_agent_hub_wp_ctx     = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, 's5', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
				$xnetvn_agent_hub_wp_run_id  = $xnetvn_agent_hub_wp_ctx['run'];
				$xnetvn_agent_hub_wp_tokens  = $xnetvn_agent_hub_wp_ctx['tokens'];
				$xnetvn_agent_hub_wp_users   = $xnetvn_agent_hub_wp_ctx['users'];
				$xnetvn_agent_hub_wp_ledger  = $xnetvn_agent_hub_wp_ctx['ledger'];
				$xnetvn_agent_hub_wp_harness = $xnetvn_agent_hub_wp_ctx['harness'];
				$xnetvn_agent_hub_wp_client  = $xnetvn_agent_hub_wp_ctx['client'];
				if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
					throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
				}
				// STAB-05: hold two costly uploads at the owned barrier.
				$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
					$xnetvn_agent_hub_wp_timings,
					'STAB-05',
					static function () use ( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_host, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_counts_equal ): bool {
						$pixel         = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
						$open_holder   = static fn( string $token, string $filename, string $pixel, int $envelope ): CurlHandle => xnetvn_agent_hub_wp_role_seo_stability_holder( $xnetvn_agent_hub_wp_host, $token, $filename, $pixel, $envelope );
						$read_response = static function ( $handle ): ?array {
							$raw = curl_multi_getcontent( $handle );
							if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
								return null;
							}
							$decoded = json_decode( $raw, true, 64 );
							return is_array( $decoded ) ? $decoded : null;
						};
						$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'hold' );
						$released = false;
						try {
							$counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
							$envelope      = 903;
							$refuse        = static function () use ( $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_client, &$envelope, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_run_id, $pixel, $xnetvn_agent_hub_wp_denied ): bool {
								$refused = $xnetvn_agent_hub_wp_tool(
									$xnetvn_agent_hub_wp_client,
									$envelope,
									$xnetvn_agent_hub_wp_tokens['admin'],
									'upload-media-from-data',
									array(
										'filename'    => 'stab-refused-' . $xnetvn_agent_hub_wp_run_id . '.png',
										'mime_type'   => 'image/png',
										'data_base64' => $pixel,
									)
								);
								return $xnetvn_agent_hub_wp_denied( $refused, 'rate_limited' );
							};
							$held_ids      = xnetvn_agent_hub_wp_role_seo_stability_held_uploads( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $open_holder, $read_response, $xnetvn_agent_hub_wp_tokens['admin'], $pixel, $refuse, $xnetvn_agent_hub_wp_counts_equal );
							if ( null === $held_ids ) {
								return false;
							}
							$released = true;
							// Slots are free again on the same key: one more costly request succeeds.
							$freed      = $xnetvn_agent_hub_wp_tool(
								$xnetvn_agent_hub_wp_client,
								$envelope,
								$xnetvn_agent_hub_wp_tokens['admin'],
								'upload-media-from-data',
								array(
									'filename'    => 'stab-freed-' . $xnetvn_agent_hub_wp_run_id . '.png',
									'mime_type'   => 'image/png',
									'data_base64' => $pixel,
								)
							);
							$freed_data = $xnetvn_agent_hub_wp_success( $freed );
							if ( ! is_array( $freed_data ) || 1 > (int) ( $freed_data['id'] ?? 0 ) ) {
								return false;
							}
							$held_ids[] = (int) $freed_data['id'];
							foreach ( $held_ids as $owned_id ) {
								$deleted = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'delete-media', array( 'id' => $owned_id ) );
								$gone    = $xnetvn_agent_hub_wp_success( $deleted );
								if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
									return false;
								}
							}
							// Timeout path: a short barrier unblocks its holder without guessing the lock.
							$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'hold-short' );
							$slow   = $open_holder( $xnetvn_agent_hub_wp_tokens['admin'], 'stab-timeout-' . $xnetvn_agent_hub_wp_run_id . '.png', $pixel, 904 );
							$multi2 = curl_multi_init();
							if ( false === $multi2 ) {
								return false;
							}
							curl_multi_add_handle( $multi2, $slow );
							$finish2 = time() + 40;
							do {
								curl_multi_exec( $multi2, $active2 );
								if ( 0 === $active2 ) {
									break;
								}
								curl_multi_select( $multi2, 1 );
							} while ( time() < $finish2 );
							$slow_body = $read_response( $slow );
							curl_multi_remove_handle( $multi2, $slow );
							curl_close( $slow );
							curl_multi_close( $multi2 );
							$slow_result = is_array( $slow_body ) ? ( $slow_body['result'] ?? null ) : null;
							$slow_data   = is_array( $slow_result ) && true !== ( $slow_result['isError'] ?? false ) ? ( $slow_result['structuredContent'] ?? null ) : null;
							if ( ! is_array( $slow_data ) || 1 > (int) ( $slow_data['id'] ?? 0 ) ) {
								return false;
							}
							$slow_deleted = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'delete-media', array( 'id' => (int) $slow_data['id'] ) );
							$slow_gone    = $xnetvn_agent_hub_wp_success( $slow_deleted );
							if ( ! is_array( $slow_gone ) || true !== ( $slow_gone['deleted'] ?? false ) ) {
								return false;
							}
							// Crash path: a holder dies with its connection while the
							// expensive slot is still acquired. The slot must come
							// back with the connection, and the cap must still
							// hold while the surviving job keeps running.
							$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'hold' );
							$survivor = $open_holder( $xnetvn_agent_hub_wp_tokens['admin'], 'stab-survivor-a-' . $xnetvn_agent_hub_wp_run_id . '.png', $pixel, 907 );
							$doomed   = $open_holder( $xnetvn_agent_hub_wp_tokens['admin'], 'stab-crash-holder-b-' . $xnetvn_agent_hub_wp_run_id . '.png', $pixel, 908 );
							$multi3   = curl_multi_init();
							if ( false === $multi3 ) {
								return false;
							}
							curl_multi_add_handle( $multi3, $survivor );
							curl_multi_add_handle( $multi3, $doomed );
							$crashed     = false;
							$crash_until = time() + 60;
							do {
								curl_multi_exec( $multi3, $active3 );
								curl_multi_select( $multi3, 1 );
								try {
									$crash_status = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'status' );
									$crashed      = true === ( $crash_status['crashed'] ?? false );
								} catch ( \Throwable ) {
									$crashed = false;
								}
								if ( $crashed ) {
									break;
								}
							} while ( time() < $crash_until );
							if ( ! $crashed ) {
								curl_multi_remove_handle( $multi3, $survivor );
								curl_multi_remove_handle( $multi3, $doomed );
								curl_close( $survivor );
								curl_close( $doomed );
								curl_multi_close( $multi3 );
								return false;
							}
							// The crashed request never produced an attachment.
							$doomed_body = $read_response( $doomed );
							$doomed_id   = is_array( $doomed_body ) ? (int) ( $doomed_body['result']['structuredContent']['id'] ?? 0 ) : 0;
							if ( 0 < $doomed_id ) {
								curl_multi_remove_handle( $multi3, $survivor );
								curl_multi_remove_handle( $multi3, $doomed );
								curl_close( $survivor );
								curl_close( $doomed );
								curl_multi_close( $multi3 );
								return false;
							}
							curl_multi_remove_handle( $multi3, $doomed );
							curl_close( $doomed );
							// The recovered slot accepts one more costly request while
							// the survivor still runs: the probe waits at the barrier.
							$recovered = $open_holder( $xnetvn_agent_hub_wp_tokens['admin'], 'stab-recovered-c-' . $xnetvn_agent_hub_wp_run_id . '.png', $pixel, 909 );
							$multi4    = curl_multi_init();
							if ( false === $multi4 ) {
								curl_multi_remove_handle( $multi3, $survivor );
								curl_close( $survivor );
								curl_multi_close( $multi3 );
								return false;
							}
							curl_multi_add_handle( $multi4, $recovered );
							$waiting    = 0;
							$wait_until = time() + 30;
							do {
								curl_multi_exec( $multi4, $active4 );
								curl_multi_select( $multi4, 1 );
								try {
									$hold_status = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'status' );
									$waiting     = (int) ( $hold_status['waiting'] ?? 0 );
								} catch ( \Throwable ) {
									$waiting = 0;
								}
								if ( 2 <= $waiting ) {
									break;
								}
							} while ( time() < $wait_until );
							if ( 2 > $waiting ) {
								curl_multi_remove_handle( $multi4, $recovered );
								curl_close( $recovered );
								curl_multi_close( $multi4 );
								curl_multi_remove_handle( $multi3, $survivor );
								curl_close( $survivor );
								curl_multi_close( $multi3 );
								return false;
							}
							// The third costly request must be refused: two jobs run
							// at most, so the slot of the crashed holder is the only
							// one that came back.
							$exceeded = $xnetvn_agent_hub_wp_tool(
								$xnetvn_agent_hub_wp_client,
								$envelope,
								$xnetvn_agent_hub_wp_tokens['admin'],
								'upload-media-from-data',
								array(
									'filename'    => 'stab-cap-d-' . $xnetvn_agent_hub_wp_run_id . '.png',
									'mime_type'   => 'image/png',
									'data_base64' => $pixel,
								)
							);
							if ( ! $xnetvn_agent_hub_wp_denied( $exceeded, 'rate_limited' ) ) {
								curl_multi_remove_handle( $multi4, $recovered );
								curl_close( $recovered );
								curl_multi_close( $multi4 );
								curl_multi_remove_handle( $multi3, $survivor );
								curl_close( $survivor );
								curl_multi_close( $multi3 );
								return false;
							}
							// Release: the survivor and the recovered probe finish.
							$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'stability-barrier', 'release' );
							$released = true;
							$finish4  = time() + 60;
							do {
								curl_multi_exec( $multi4, $active4 );
								curl_multi_exec( $multi3, $active3 );
								if ( 0 === $active4 && 0 === $active3 ) {
									break;
								}
								curl_multi_select( $multi4, 1 );
							} while ( time() < $finish4 );
							$crash_ids = array();
							foreach ( array( $recovered, $survivor ) as $finished ) {
								$body   = $read_response( $finished );
								$result = is_array( $body ) ? ( $body['result'] ?? null ) : null;
								$data   = is_array( $result ) && true !== ( $result['isError'] ?? false ) ? ( $result['structuredContent'] ?? null ) : null;
								if ( ! is_array( $data ) || 1 > (int) ( $data['id'] ?? 0 ) ) {
									curl_multi_remove_handle( $multi4, $recovered );
									curl_close( $recovered );
									curl_multi_close( $multi4 );
									curl_multi_remove_handle( $multi3, $survivor );
									curl_close( $survivor );
									curl_multi_close( $multi3 );
									return false;
								}
								$crash_ids[] = (int) $data['id'];
							}
							curl_multi_remove_handle( $multi4, $recovered );
							curl_close( $recovered );
							curl_multi_close( $multi4 );
							curl_multi_remove_handle( $multi3, $survivor );
							curl_close( $survivor );
							curl_multi_close( $multi3 );
							foreach ( $crash_ids as $owned_id ) {
								$deleted = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'delete-media', array( 'id' => $owned_id ) );
								$gone    = $xnetvn_agent_hub_wp_success( $deleted );
								if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
									return false;
								}
							}
							// Every slot is free again after the crash and the close.
							$after_crash = $xnetvn_agent_hub_wp_tool(
								$xnetvn_agent_hub_wp_client,
								$envelope,
								$xnetvn_agent_hub_wp_tokens['admin'],
								'upload-media-from-data',
								array(
									'filename'    => 'stab-after-crash-' . $xnetvn_agent_hub_wp_run_id . '.png',
									'mime_type'   => 'image/png',
									'data_base64' => $pixel,
								)
							);
							$after_data  = $xnetvn_agent_hub_wp_success( $after_crash );
							if ( ! is_array( $after_data ) || 1 > (int) ( $after_data['id'] ?? 0 ) ) {
								return false;
							}
							$after_deleted = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'delete-media', array( 'id' => (int) $after_data['id'] ) );
							$after_gone    = $xnetvn_agent_hub_wp_success( $after_deleted );
							if ( ! is_array( $after_gone ) || true !== ( $after_gone['deleted'] ?? false ) ) {
								return false;
							}
							return $xnetvn_agent_hub_wp_counts_equal( $counts_before, $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' ) );
						} finally {
							xnetvn_agent_hub_wp_role_seo_stability_reset_barrier( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $released );
						}
					}
				);
				$xnetvn_agent_hub_wp_record( 'STAB-05', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'rate_limited' );
				$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
			} finally {
				$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_phase_run, $xnetvn_agent_hub_wp_action );
			}

			// Phase s6 hosts the STAB-06 uncertain-mutation reconcile on its own isolated state.
			$xnetvn_agent_hub_wp_phase_run = $xnetvn_agent_hub_wp_round_run . '-s6';
			try {
				$xnetvn_agent_hub_wp_ctx     = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, 's6', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
				$xnetvn_agent_hub_wp_run_id  = $xnetvn_agent_hub_wp_ctx['run'];
				$xnetvn_agent_hub_wp_tokens  = $xnetvn_agent_hub_wp_ctx['tokens'];
				$xnetvn_agent_hub_wp_users   = $xnetvn_agent_hub_wp_ctx['users'];
				$xnetvn_agent_hub_wp_ledger  = $xnetvn_agent_hub_wp_ctx['ledger'];
				$xnetvn_agent_hub_wp_harness = $xnetvn_agent_hub_wp_ctx['harness'];
				$xnetvn_agent_hub_wp_client  = $xnetvn_agent_hub_wp_ctx['client'];
				if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
					throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
				}
				// STAB-06: faulted projection reconciles by receipt; lost response by marker.
				$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
					$xnetvn_agent_hub_wp_timings,
					'STAB-06',
					static function () use ( $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_users, $xnetvn_agent_hub_wp_tool, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_success, $xnetvn_agent_hub_wp_denied, $xnetvn_agent_hub_wp_counts_equal, &$xnetvn_agent_hub_wp_call_log ): bool {
						$counts_before = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
						$log_before    = count( $xnetvn_agent_hub_wp_call_log );
						$envelope      = 1;
						try {
							$armed = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'lifecycle-fault', 'prepare-fail-once' );
							if ( true !== ( $armed['armed'] ?? false ) ) {
								return false;
							}
							$marker = 'Stability fault marker ' . $xnetvn_agent_hub_wp_run_id;
							$fault  = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'create-post', array( 'title' => $marker ) );
							if ( ! $xnetvn_agent_hub_wp_denied( $fault, 'partial_failure' ) ) {
								return false;
							}
							// Exactly one mutation attempt: never resend blindly.
							$segment = array_slice( $xnetvn_agent_hub_wp_call_log, $log_before );
							if ( 1 !== count( array_filter( $segment, static fn( $name ): bool => 'xnetvn-create-post' === $name ) ) ) {
								return false;
							}
							$text = $fault['body']['result']['content'][0]['text'] ?? '';
							if ( ! is_string( $text ) || 1 !== preg_match( '/created_id=([1-9][0-9]*)/', $text, $matches ) ) {
								// Receipt without a numeric id cannot be reconciled; no guessing.
								return false;
							}
							$receipt_id = (int) $matches[1];
							$found      = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'get-post', array( 'id' => $receipt_id ) );
							$found_data = $xnetvn_agent_hub_wp_success( $found );
							if ( ! is_array( $found_data ) || (int) ( $found_data['id'] ?? 0 ) !== $receipt_id || (int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 ) !== (int) ( $found_data['author'] ?? 0 ) ) {
								return false;
							}
							$deleted = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['editor'], 'delete-post', array( 'id' => $receipt_id ) );
							$gone    = $xnetvn_agent_hub_wp_success( $deleted );
							if ( ! is_array( $gone ) || true !== ( $gone['deleted'] ?? false ) ) {
								return false;
							}
							// Lost response: the client discards the reply and reconciles by marker.
							$lost_marker = 'Stability lost marker ' . $xnetvn_agent_hub_wp_run_id;
							$xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'create-post', array( 'title' => $lost_marker ) );
							$listed = $xnetvn_agent_hub_wp_tool(
								$xnetvn_agent_hub_wp_client,
								$envelope,
								$xnetvn_agent_hub_wp_tokens['editor'],
								'list-posts',
								array(
									'search'         => $lost_marker,
									'status'         => array( 'draft' ),
									'per_page'       => 100,
									'content_format' => 'excerpt',
								)
							);
							$items  = $xnetvn_agent_hub_wp_success( $listed );
							$hits   = array();
							foreach ( ( is_array( $items ) ? ( $items['items'] ?? array() ) : array() ) as $item ) {
								if ( is_array( $item ) && isset( $item['id'] ) && is_int( $item['id'] ) ) {
									$hits[] = $item['id'];
								}
							}
							if ( 1 !== count( $hits ) ) {
								return false;
							}
							$confirm      = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'get-post', array( 'id' => $hits[0] ) );
							$confirm_data = $xnetvn_agent_hub_wp_success( $confirm );
							if ( ! is_array( $confirm_data ) || ( $confirm_data['title'] ?? null ) !== $lost_marker || (int) ( $xnetvn_agent_hub_wp_users['admin'] ?? 0 ) !== (int) ( $confirm_data['author'] ?? 0 ) ) {
								return false;
							}
							$removed = $xnetvn_agent_hub_wp_tool( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['editor'], 'delete-post', array( 'id' => $hits[0] ) );
							$cleared = $xnetvn_agent_hub_wp_success( $removed );
							if ( ! is_array( $cleared ) || true !== ( $cleared['deleted'] ?? false ) ) {
								return false;
							}
							return $xnetvn_agent_hub_wp_counts_equal( $counts_before, $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' ) );
						} finally {
							try {
								$xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'lifecycle-fault', 'none' );
							} catch ( \Throwable ) {
								return false;
							}
						}
					}
				);
				$xnetvn_agent_hub_wp_record( 'STAB-06', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'partial_failure' );
				$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
			} finally {
				$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_phase_run, $xnetvn_agent_hub_wp_action );
			}

			// Phase s7 hosts the STAB-07 concurrent reads on its own isolated state.
			$xnetvn_agent_hub_wp_phase_run = $xnetvn_agent_hub_wp_round_run . '-s7';
			try {
				$xnetvn_agent_hub_wp_ctx     = $xnetvn_agent_hub_wp_begin_phase( $xnetvn_agent_hub_wp_round_run, 's7', $xnetvn_agent_hub_wp_action, $xnetvn_agent_hub_wp_private );
				$xnetvn_agent_hub_wp_run_id  = $xnetvn_agent_hub_wp_ctx['run'];
				$xnetvn_agent_hub_wp_tokens  = $xnetvn_agent_hub_wp_ctx['tokens'];
				$xnetvn_agent_hub_wp_users   = $xnetvn_agent_hub_wp_ctx['users'];
				$xnetvn_agent_hub_wp_ledger  = $xnetvn_agent_hub_wp_ctx['ledger'];
				$xnetvn_agent_hub_wp_harness = $xnetvn_agent_hub_wp_ctx['harness'];
				$xnetvn_agent_hub_wp_client  = $xnetvn_agent_hub_wp_ctx['client'];
				if ( ! $xnetvn_agent_hub_wp_quiet( $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, array( 'editor' ), $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed ) ) {
					throw new \RuntimeException( 'Role SEO rate windows stayed hot.' );
				}
				// STAB-07: ten concurrent owned reads, then a health check.
				$xnetvn_agent_hub_wp_ok = $xnetvn_agent_hub_wp_time(
					$xnetvn_agent_hub_wp_timings,
					'STAB-07',
					static function () use ( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_blog, $xnetvn_agent_hub_wp_client, $xnetvn_agent_hub_wp_tokens, $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_listed, $xnetvn_agent_hub_wp_action ): bool {
						// Only keys that can actually read take part: the limited
						// and subscriber keys deny the read by product design and
						// could never prove response isolation.
						$actors = array( 'admin', 'editor', 'author', 'contributor', 'admin', 'editor', 'author', 'contributor', 'admin', 'editor' );
						foreach ( $actors as $actor ) {
							if ( ! isset( $xnetvn_agent_hub_wp_tokens[ $actor ] ) ) {
								return false;
							}
						}
						$workers = array();
						for ( $index = 1; $index <= 10; ++$index ) {
							$process = proc_open(
								array( PHP_BINARY, __FILE__, 'worker', $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, $xnetvn_agent_hub_wp_blog, (string) $index, $actors[ $index - 1 ], '101' ),
								array(
									1 => array( 'pipe', 'w' ),
									2 => array( 'pipe', 'w' ),
								),
								$pipes
							);
							if ( ! is_resource( $process ) ) {
								foreach ( $workers as $open ) {
									proc_terminate( $open[0] );
									proc_close( $open[0] );
								}
								return false;
							}
							$workers[] = array( $process, $pipes, $index );
						}
						$concurrent_ok = true;
						foreach ( $workers as list($process, $pipes, $index) ) {
							$output = stream_get_contents( $pipes[1] );
							$errors = stream_get_contents( $pipes[2] );
							fclose( $pipes[1] );
							fclose( $pipes[2] );
							$status = proc_close( $process );
							$sample = json_decode( is_string( $output ) ? $output : '', true, 16 );
							if ( 0 !== $status || '' !== $errors || ! is_array( $sample ) || (int) ( $sample['index'] ?? 0 ) !== $index || true !== ( $sample['ok'] ?? false ) ) {
								$concurrent_ok = false;
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI-only diagnostic without request values.
								fwrite( STDERR, 'Role SEO concurrent worker ' . $index . ' failed with exit ' . (int) $status . ' error ' . substr( is_string( $errors ) ? $errors : '', 0, 120 ) . "\n" );
							}
						}
						if ( ! $concurrent_ok ) {
							return false;
						}
						$envelope = 700;
						$health   = $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_client, $envelope, $xnetvn_agent_hub_wp_tokens['admin'], 'tools/list', array() );
						if ( ! $xnetvn_agent_hub_wp_listed( $health ) ) {
							return false;
						}
						return true;
					}
				);
				$xnetvn_agent_hub_wp_record( 'STAB-07', $xnetvn_agent_hub_wp_ok, $xnetvn_agent_hub_wp_ok ? 'unavailable' : 'internal_error' );
				$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_ok;
			} finally {
				$xnetvn_agent_hub_wp_end_phase( $xnetvn_agent_hub_wp_round_run, $xnetvn_agent_hub_wp_phase_run, $xnetvn_agent_hub_wp_action );
			}
		}
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_round_ok = false;
	}

		// Round teardown: every phase cleaned its own state; the entity
		// totals must match the round start, or the round is not clean.
	try {
		$xnetvn_agent_hub_wp_round_counts_after = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_run_id, 'counts' );
		if ( ! $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_round_counts_before, $xnetvn_agent_hub_wp_round_counts_after ) ) {
			$xnetvn_agent_hub_wp_round_ok = false;
		}
	} catch ( \Throwable ) {
		$xnetvn_agent_hub_wp_round_ok = false;
	}
	if ( ! $xnetvn_agent_hub_wp_round_ok ) {
		break;
	}
	++$xnetvn_agent_hub_wp_rounds_ok;
}
	unset( $xnetvn_agent_hub_wp_round );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the runner run id after the final round.
	putenv( 'XNETVN_ROLE_SEO_RUN_ID=' . $xnetvn_agent_hub_wp_base_run );

// STAB-01: all five rounds swept every B-E matrix with no carry-over.
$xnetvn_agent_hub_wp_record( 'STAB-01', 5 === $xnetvn_agent_hub_wp_rounds_ok, 5 === $xnetvn_agent_hub_wp_rounds_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || 5 !== $xnetvn_agent_hub_wp_rounds_ok;

// STAB-02: latency percentiles plus numeric memory probes, never zero-filled.
$xnetvn_agent_hub_wp_latency_report = array();
foreach ( array( 'round_ms', 'matrix_ms', 'allowed_read_ms', 'denied_read_ms', 'allowed_write_ms', 'denied_write_ms', 'unavailable_read_ms', 'unavailable_write_ms' ) as $xnetvn_agent_hub_wp_bucket ) {
	$xnetvn_agent_hub_wp_latency_report[ $xnetvn_agent_hub_wp_bucket ] = $xnetvn_agent_hub_wp_reduce( $xnetvn_agent_hub_wp_latency[ $xnetvn_agent_hub_wp_bucket ], $xnetvn_agent_hub_wp_latency_errors[ $xnetvn_agent_hub_wp_bucket ] );
}
$xnetvn_agent_hub_wp_peaks = array();
foreach ( $xnetvn_agent_hub_wp_memory_samples as $xnetvn_agent_hub_wp_sample ) {
	if ( ! is_array( $xnetvn_agent_hub_wp_sample ) || 0 > $xnetvn_agent_hub_wp_sample['peak'] - $xnetvn_agent_hub_wp_sample['baseline'] ) {
		continue;
	}
	$xnetvn_agent_hub_wp_peaks[] = $xnetvn_agent_hub_wp_sample['peak'] - $xnetvn_agent_hub_wp_sample['baseline'];
}
if ( array() === $xnetvn_agent_hub_wp_peaks || in_array( 'unavailable', $xnetvn_agent_hub_wp_memory_samples, true ) ) {
	$xnetvn_agent_hub_wp_memory_report = 'unavailable';
} else {
	$xnetvn_agent_hub_wp_memory_report = BenchmarkSummary::summarize( $xnetvn_agent_hub_wp_peaks );
}
// Every actor and operation keeps its own metric; a missing one stays
// unavailable instead of zero.
$xnetvn_agent_hub_wp_actor_report = xnetvn_agent_hub_wp_role_seo_stability_actor_report( $xnetvn_agent_hub_wp_actor_buckets, $xnetvn_agent_hub_wp_actor_memory );
$xnetvn_agent_hub_wp_actor_errors = 0;
foreach ( $xnetvn_agent_hub_wp_actor_buckets as $xnetvn_agent_hub_wp_bucket ) {
	$xnetvn_agent_hub_wp_actor_errors += (int) ( $xnetvn_agent_hub_wp_bucket['errors'] ?? 0 );
}
$xnetvn_agent_hub_wp_actor_classes = array();
foreach ( $xnetvn_agent_hub_wp_actor_buckets as $xnetvn_agent_hub_wp_bucket ) {
	$xnetvn_agent_hub_wp_bucket_classes = ( isset( $xnetvn_agent_hub_wp_bucket['classes'] ) && is_array( $xnetvn_agent_hub_wp_bucket['classes'] ) ) ? $xnetvn_agent_hub_wp_bucket['classes'] : array();
	foreach ( $xnetvn_agent_hub_wp_bucket_classes as $xnetvn_agent_hub_wp_class => $xnetvn_agent_hub_wp_seen ) {
		$xnetvn_agent_hub_wp_actor_classes[ $xnetvn_agent_hub_wp_class ] = (int) ( $xnetvn_agent_hub_wp_actor_classes[ $xnetvn_agent_hub_wp_class ] ?? 0 ) + (int) $xnetvn_agent_hub_wp_seen;
	}
}
$xnetvn_agent_hub_wp_probe_ok = 'unavailable' !== $xnetvn_agent_hub_wp_memory_report
	&& 'unavailable' !== $xnetvn_agent_hub_wp_latency_report['allowed_read_ms']
	&& 'unavailable' !== $xnetvn_agent_hub_wp_latency_report['denied_write_ms']
	&& 0 === $xnetvn_agent_hub_wp_actor_errors
	&& 0 < (int) ( $xnetvn_agent_hub_wp_actor_classes['allowed'] ?? 0 )
	&& 0 < (int) ( $xnetvn_agent_hub_wp_actor_classes['denied'] ?? 0 );
foreach ( array( 'admin', 'editor', 'author', 'contributor', 'subscriber' ) as $xnetvn_agent_hub_wp_actor ) {
	if ( ! isset( $xnetvn_agent_hub_wp_actor_report[ $xnetvn_agent_hub_wp_actor ] ) ) {
		$xnetvn_agent_hub_wp_probe_ok = false;
	}
}
$xnetvn_agent_hub_wp_record( 'STAB-02', $xnetvn_agent_hub_wp_probe_ok, $xnetvn_agent_hub_wp_probe_ok ? 'unavailable' : 'internal_error' );
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_probe_ok;

// STAB-03: benchmark prerequisites on wp69; gates come from the standalone
// benchmark run after role-seo cleanup, never from this matrix.
$xnetvn_agent_hub_wp_record( 'STAB-03', false, 'unavailable' );

// Final campaign totals prove zero leakage across all five rounds.
try {
	$xnetvn_agent_hub_wp_counts_final = $xnetvn_agent_hub_wp_action( $xnetvn_agent_hub_wp_site, $xnetvn_agent_hub_wp_base_run, 'counts' );
	$xnetvn_agent_hub_wp_leak_free    = $xnetvn_agent_hub_wp_counts_equal( $xnetvn_agent_hub_wp_counts_before, $xnetvn_agent_hub_wp_counts_final );
} catch ( \Throwable ) {
	$xnetvn_agent_hub_wp_leak_free = false;
}
$xnetvn_agent_hub_wp_failed = $xnetvn_agent_hub_wp_failed || ! $xnetvn_agent_hub_wp_leak_free;

$xnetvn_agent_hub_wp_report = array(
	'suite'     => 'role-seo-stability',
	'target'    => $xnetvn_agent_hub_wp_site,
	'run_id'    => $xnetvn_agent_hub_wp_base_run,
	'blog'      => (int) $xnetvn_agent_hub_wp_blog,
	'cases'     => $xnetvn_agent_hub_wp_cases,
	'declared'  => xnetvn_agent_hub_wp_role_seo_stability_declare_cases(),
	'counts'    => array(
		'rounds_ok' => $xnetvn_agent_hub_wp_rounds_ok,
		'leak_free' => $xnetvn_agent_hub_wp_leak_free,
	),
	'timing_ms' => $xnetvn_agent_hub_wp_timings,
	'latency'   => $xnetvn_agent_hub_wp_latency_report,
	'actors'    => $xnetvn_agent_hub_wp_actor_report,
	'memory'    => $xnetvn_agent_hub_wp_memory_report,
	'bench'     => $xnetvn_agent_hub_wp_bench,
	'cleanup'   => false,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
echo json_encode( $xnetvn_agent_hub_wp_report, JSON_THROW_ON_ERROR ) . "\n";
exit( $xnetvn_agent_hub_wp_failed ? 1 : 0 );
