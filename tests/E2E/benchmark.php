<?php
/**
 * Real HTTPS samples and ten-worker load; never exports fixture credentials.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\E2E\McpClient;
use XNetVN\AgentHubWP\Tests\Support\BenchmarkSummary;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real transport and bounded worker processes run only inside the remote toolbox.
$xnetvn_agent_hub_wp_benchmark_state = json_decode( file_get_contents( '/work/.local/http-matrix/state.json' ), true, 64, JSON_THROW_ON_ERROR );
$xnetvn_agent_hub_wp_benchmark_host  = getenv( 'RTL_ALIAS_WP69' );
$xnetvn_agent_hub_wp_benchmark_http  = new McpClient( 'https://' . $xnetvn_agent_hub_wp_benchmark_host, array( $xnetvn_agent_hub_wp_benchmark_host ), true );

/**
 * Measure a single request, validate its expected result, retain numeric evidence only.
 *
 * @param string $kind Valid, unknown, wrong or outage case.
 * @return array<string,float>
 * @throws RuntimeException For unexpected or unavailable evidence.
 */
function xnetvn_agent_hub_wp_benchmark_sample( string $kind ): array {
	global $xnetvn_agent_hub_wp_benchmark_state, $xnetvn_agent_hub_wp_benchmark_http;
	$token = $xnetvn_agent_hub_wp_benchmark_state['keys']['read']['token'];
	if ( 'unknown' === $kind ) {
		$token = 'xnv_aaaaaaaaaaaa_' . str_repeat( 'A', 43 );
	} elseif ( 'wrong' === $kind ) {
		$token = substr( $token, 0, 17 ) . str_repeat( 'A', 43 );
	}
	$body    = json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
			'params'  => array(
				'_meta' => array(
					'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities' => (object) array(),
					'io.modelcontextprotocol/clientInfo' => array(
						'name'    => 'agent-hub-benchmark',
						'version' => '1.0',
					),
				),
			),
		),
		JSON_THROW_ON_ERROR
	);
	$start   = hrtime( true );
	$result  = $xnetvn_agent_hub_wp_benchmark_http->request(
		'/wp-json/xnetvn-agent-hub-wp/mcp',
		$body,
		$token,
		array(
			'MCP-Protocol-Version'  => '2026-07-28',
			'Mcp-Method'            => 'tools/list',
			'X-Xnetvn-Test-Control' => $xnetvn_agent_hub_wp_benchmark_state['control'],
		)
	);
	$elapsed = ( hrtime( true ) - $start ) / 1000000;
	if ( 'outage' === $kind ) {
		// A global cache drop-in can abort WordPress before this plugin loads (core500);
		// an unavailable request inside this plugin is503. Neither may expose a result.
		if ( ! in_array( $result['status'], array( 500, 503 ), true ) || isset( $result['body']['result'] ) ) {
			fwrite( STDERR, sprintf( "Unexpected outage status=%d.\n", $result['status'] ) );
			throw new RuntimeException( 'Cache outage did not close access.' );
		}
		return array(
			'status'  => (float) $result['status'],
			'http_ms' => $elapsed,
		);
	}
	$expected = in_array( $kind, array( 'unknown', 'wrong' ), true ) ? 401 : 200;
	if ( $expected !== $result['status'] || ( 200 === $expected && 60 !== count( $result['body']['result']['tools'] ?? array() ) ) || ( 401 === $expected && 'Authentication required.' !== ( $result['body']['message'] ?? null ) ) ) {
		fwrite( STDERR, sprintf( "Unexpected benchmark case=%s status=%d tool_count=%d.\n", $kind, $result['status'], count( $result['body']['result']['tools'] ?? array() ) ) );
		throw new RuntimeException( 'Unexpected benchmark response.' );
	}
	if ( '1' !== ( $result['headers']['x-xnetvn-benchmark-persistent-cache'] ?? '' ) ) {
		throw new RuntimeException( 'Persistent cache benchmark prerequisite failed.' );
	}
	if ( '6.9' !== ( $result['headers']['x-xnetvn-benchmark-wordpress'] ?? null ) || '0.7.0' !== ( $result['headers']['x-xnetvn-benchmark-adapter'] ?? null ) || ! str_starts_with( $result['headers']['x-xnetvn-benchmark-php'] ?? '', '8.2.' ) ) {
		throw new RuntimeException( 'Benchmark runtime versions are unavailable or unsupported.' );
	}
	$metrics = array(
		'http_ms'            => $elapsed,
		'logical_json_bytes' => (float) strlen( json_encode( $result['body'], JSON_THROW_ON_ERROR ) ),
	);
	foreach ( $result['transport'] as $name => $measurement ) {
		$metrics[ $name ] = $measurement;
	}
	$phase_headers = array(
		'auth_ms'      => 'auth-ms',
		'memory_bytes' => 'memory-bytes',
	);
	if ( 200 === $expected ) {
		$phase_headers['policy_ms'] = 'policy-ms';
	}
	foreach ( $phase_headers as $name => $header ) {
		$value = $result['headers'][ 'x-xnetvn-benchmark-' . $header ] ?? null;
		if ( ! is_string( $value ) || ! is_numeric( $value ) ) {
			throw new RuntimeException( 'Benchmark metric is unavailable.' );
		}
		$metrics[ $name ] = (float) $value;
	}
	// Analysis-only split of the same request; these values never feed a gate.
	$analysis_headers = array(
		'bootstrap_ms'                   => 'bootstrap-ms',
		'init_ms'                        => 'init-ms',
		'pre_dispatch_ms'                => 'pre-dispatch-ms',
		'mu_load_ms'                     => 'mu-load-ms',
		'memory_baseline_bytes'          => 'memory-baseline-bytes',
		'memory_plugins_bytes'           => 'memory-plugins-bytes',
		'peak_plugins_bytes'             => 'peak-plugins-bytes',
		'memory_init_bytes'              => 'memory-init-bytes',
		'peak_init_bytes'                => 'peak-init-bytes',
		'memory_request_bytes'           => 'memory-request-bytes',
		'peak_request_bytes'             => 'peak-request-bytes',
		'declared_classes'               => 'declared-classes',
		'declared_classes_init'          => 'declared-classes-init',
		'declared_classes_request'       => 'declared-classes-request',
		'phase_rest_api_init_ms'         => 'phase-rest-api-init-ms',
		'phase_mcp_adapter_init_ms'      => 'phase-mcp-adapter-init-ms',
		'phase_wp_abilities_api_init_ms' => 'phase-wp-abilities-api-init-ms',
		'phase_categories_init_ms'       => 'phase-wp-abilities-api-categories-init-ms',
		'probe_abilities_total'          => 'probe-abilities-total',
		'probe_abilities_ours'           => 'probe-abilities-ours',
		'probe_user_during_rest_init'    => 'probe-user-during-rest-init',
	);
	foreach ( $analysis_headers as $name => $header ) {
		$value = $result['headers'][ 'x-xnetvn-benchmark-' . $header ] ?? null;
		if ( ! is_string( $value ) || ! is_numeric( $value ) ) {
			throw new RuntimeException( 'Benchmark analysis metric is unavailable.' );
		}
		$metrics[ $name ] = (float) $value;
	}
	if ( 200 === $expected ) {
		$value = $result['headers']['x-xnetvn-benchmark-dispatch-ms'] ?? null;
		if ( ! is_string( $value ) || ! is_numeric( $value ) ) {
			throw new RuntimeException( 'Dispatch timing is unavailable.' );
		}
		$metrics['dispatch_ms']    = (float) $value;
		$metrics['auth_policy_ms'] = $metrics['auth_ms'] + $metrics['policy_ms'];
	}
	return $metrics;
}

/**
 * Reduce complete numeric samples to declared percentiles.
 *
 * @param array<array<string,float>> $samples Samples.
 * @return array<string,array<string,int|float>>
 */
function xnetvn_agent_hub_wp_benchmark_reduce( array $samples ): array {
	$metrics = array();
	foreach ( array_keys( $samples[0] ) as $name ) {
		$metrics[ $name ] = BenchmarkSummary::summarize( array_column( $samples, $name ) );
	}
	return $metrics;
}

/**
 * Run the bounded benchmark command and report only numeric evidence.
 *
 * @param array<string> $arguments Command arguments, without credentials.
 * @throws RuntimeException For incomplete evidence, caught and reported as a failure exit.
 */
function xnetvn_agent_hub_wp_benchmark_main( array $arguments ): void {
	try {
		$mode = $arguments[1] ?? 'all';
		if ( 'outage' === $mode ) {
			$result = xnetvn_agent_hub_wp_benchmark_sample( 'outage' );
			echo json_encode(
				array(
					'outage_denied' => true,
					'evidence'      => $result,
				),
				JSON_THROW_ON_ERROR
			) . "\n";
			exit( 0 );
		}
		if ( 'positive' === $mode ) {
			echo json_encode( array( 'positive_control' => xnetvn_agent_hub_wp_benchmark_sample( 'valid' ) ), JSON_THROW_ON_ERROR ) . "\n";
			exit( 0 );
		}
		if ( 'worker' === $mode ) {
			$samples = array();
			for ( $i = 0; $i < 10; ++$i ) {
				$samples[] = xnetvn_agent_hub_wp_benchmark_sample( 'valid' );
			}
			echo json_encode( $samples, JSON_THROW_ON_ERROR ) . "\n";
			exit( 0 );
		}
		if ( 'all' !== $mode ) {
			throw new RuntimeException( 'Unsupported benchmark mode.' );
		}
		$results = array();
		foreach ( array( 'valid', 'unknown', 'wrong' ) as $kind ) {
			for ( $i = 0; $i < 10; ++$i ) {
				xnetvn_agent_hub_wp_benchmark_sample( $kind );
			}
		}
		$samples = array(
			'valid'   => array(),
			'unknown' => array(),
			'wrong'   => array(),
		);
		// Alternate failure classes to reduce clock/load drift in the comparison.
		for ( $i = 0; $i < 100; ++$i ) {
			foreach ( array( 'valid', 'unknown', 'wrong' ) as $kind ) {
				$samples[ $kind ][] = xnetvn_agent_hub_wp_benchmark_sample( $kind );
			}
		}
		foreach ( $samples as $kind => $measurements ) {
			$results[ $kind ] = xnetvn_agent_hub_wp_benchmark_reduce( $measurements );
		}
		$workers = array();
		for ( $i = 0; $i < 10; ++$i ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fixed test-only worker argv, no credentials.
			$process = proc_open(
				array( PHP_BINARY, __FILE__, 'worker' ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Benchmark worker is unavailable.' );
			}
			$workers[] = array( $process, $pipes );
		}
		$concurrent = array();
		foreach ( $workers as list($process, $pipes) ) {
			$output = stream_get_contents( $pipes[1] );
			$errors = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			if ( 0 !== proc_close( $process ) || '' !== $errors ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_close -- Bound test-only worker lifecycle.
				throw new RuntimeException( 'Concurrent benchmark did not complete.' );
			}
			$concurrent = array_merge( $concurrent, json_decode( $output, true, 64, JSON_THROW_ON_ERROR ) );
		}
		$results['concurrent_10'] = xnetvn_agent_hub_wp_benchmark_reduce( $concurrent );
		$ratio                    = $results['wrong']['auth_ms']['p50'] / max( 0.000001, $results['unknown']['auth_ms']['p50'] );
		$report                   = array(
			'format'                  => 1,
			'run_id'                  => false !== getenv( 'XNETVN_RTL_RUN_ID' ) && '' !== getenv( 'XNETVN_RTL_RUN_ID' ) ? (string) getenv( 'XNETVN_RTL_RUN_ID' ) : ( 'local-' . gmdate( 'Ymd\THis\Z' ) ),
			'wordpress'               => '6.9',
			'client_php'              => PHP_VERSION,
			'adapter'                 => '0.7.0',
			'pinned_cache'            => 'Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis',
			'visible_tools'           => 60,
			'warmup_per_case'         => 10,
			'http_connection_policy'  => 'Reuse connection and DNS caches; reset credential/body/header options after every request.',
			'request_errors'          => 0,
			'memory_scope'            => 'Peak minus MU-plugin baseline; includes ordinary plugins, core dispatch, adapter and synthetic fixture.',
			'analysis_scope'          => 'Analysis only, no gate reads it: bootstrap_ms is request start to plugins_loaded; init_ms is request start to the init mark; pre_dispatch_ms is request start to rest_pre_dispatch; memory_baseline_bytes is the MU-plugin baseline; memory_plugins_bytes is the plugins_loaded mark minus the MU-plugin baseline; peak_plugins_bytes is the absolute peak at plugins_loaded; memory_init_bytes is the init mark minus the plugins_loaded mark; peak_init_bytes is the absolute peak at init; memory_request_bytes is the request peak minus the init mark; peak_request_bytes is the request peak minus the init peak; phase_rest_api_init_ms is the rest_api_init action; phase_mcp_adapter_init_ms is the mcp_adapter_init action; phase_wp_abilities_api_init_ms is the wp_abilities_api_init action; phase_categories_init_ms is the wp_abilities_api_categories_init action; probe_abilities_total is the number of abilities known to core; probe_abilities_ours is the number of xnetvn abilities known to core; probe_user_during_rest_init is 1 when the current user was already resolved inside rest_api_init.',
			'latencies'               => $results,
			'wrong_unknown_p50_ratio' => $ratio,
			'targets'                 => array(
				'auth_median_lt_5_ms'        => $results['valid']['auth_ms']['p50'] < 5,
				'policy_median_lt_5_ms'      => $results['valid']['policy_ms']['p50'] < 5,
				'auth_policy_median_lt_5_ms' => $results['valid']['auth_policy_ms']['p50'] < 5,
				'list_dispatch_p50_lt_50_ms' => $results['valid']['dispatch_ms']['p50'] < 50,
				'list_http_p50_lt_55_ms'     => $results['valid']['http_ms']['p50'] < 55,
				'memory_max_lt_8_mib'        => $results['valid']['memory_bytes']['max'] < 8 * 1024 * 1024,
			),
		);
		echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	} catch ( Throwable $exception ) {
		// Static messages only; no exception trace can expose credential-bearing locals.
		$reason = in_array( $exception->getMessage(), array( 'Unexpected benchmark response.', 'Persistent cache benchmark prerequisite failed.', 'Benchmark metric is unavailable.', 'Benchmark analysis metric is unavailable.', 'Dispatch timing is unavailable.', 'Concurrent benchmark did not complete.', 'Test HTTP request did not complete.' ), true ) ? $exception->getMessage() : 'Benchmark did not produce complete validated evidence.';
		fwrite( STDERR, $reason . "\n" );
		exit( 1 );
	}
}

xnetvn_agent_hub_wp_benchmark_main( $argv );
