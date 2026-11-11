<?php
/**
 * Background cost probe for one plain WordPress request; prints aggregate numbers only.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\Support\BenchmarkSummary;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Real transport measurements run only inside the remote toolbox.

/**
 * Measure one plain WordPress request and keep its transport numbers.
 *
 * The response body is never decoded, so an HTML page cannot fail the probe the way
 * `McpClient::decode()` fails on a non-record body; only the timings are kept.
 *
 * @param \CurlHandle $curl Reused transport handle.
 * @param string      $url  Probe target.
 * @param string      $method HTTP method.
 * @return array{status:int,metrics:array<string,float>}
 * @throws RuntimeException For a transport failure.
 */
function xnetvn_agent_hub_wp_probe_request( \CurlHandle $curl, string $url, string $method = 'POST' ): array {
	curl_reset( $curl );
	$started  = hrtime( true );
	$response = '';
	$options  = array(
		CURLOPT_URL            => $url,
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/x-www-form-urlencoded' ),
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0,
		CURLOPT_WRITEFUNCTION  => static function ( \CurlHandle $handle, string $chunk ) use ( &$response ): int {
			$response .= $chunk;
			return strlen( $chunk );
		},
	);
	if ( 'GET' === $method ) {
		$options[ CURLOPT_HTTPGET ] = true;
	} else {
		$options[ CURLOPT_POST ]       = true;
		$options[ CURLOPT_POSTFIELDS ] = '';
	}
	curl_setopt_array( $curl, $options );
	if ( false === curl_exec( $curl ) ) {
		throw new RuntimeException( 'Probe HTTP request did not complete.' );
	}
	$elapsed = ( hrtime( true ) - $started ) / 1000000;
	$status  = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	return array(
		'status'  => $status,
		'metrics' => array(
			'http_ms'         => $elapsed,
			'dns_ms'          => 1000 * (float) curl_getinfo( $curl, CURLINFO_NAMELOOKUP_TIME ),
			'connect_ms'      => 1000 * (float) curl_getinfo( $curl, CURLINFO_CONNECT_TIME ),
			'tls_ready_ms'    => 1000 * (float) curl_getinfo( $curl, CURLINFO_APPCONNECT_TIME ),
			'ttfb_ms'         => 1000 * (float) curl_getinfo( $curl, CURLINFO_STARTTRANSFER_TIME ),
			'curl_total_ms'   => 1000 * (float) curl_getinfo( $curl, CURLINFO_TOTAL_TIME ),
			'new_connections' => (float) curl_getinfo( $curl, CURLINFO_NUM_CONNECTS ),
		),
	);
}

/**
 * Collect the bounded samples of one probe target and reduce them.
 *
 * @param \CurlHandle $curl Reused transport handle.
 * @param string      $url Probe target.
 * @param string      $method HTTP method.
 * @return array{status_counts:array<string,int>,latencies:array<string,array<string,int|float>>}
 * @throws RuntimeException For an unavailable transport or an invalid sample.
 */
function xnetvn_agent_hub_wp_probe_collect( \CurlHandle $curl, string $url, string $method ): array {
	$samples  = array();
	$statuses = array();
	for ( $i = 0; $i < 110; ++$i ) {
		$result = xnetvn_agent_hub_wp_probe_request( $curl, $url, $method );
		if ( 10 > $i ) {
			continue;
		}
		$statuses[ (string) $result['status'] ] = ( $statuses[ (string) $result['status'] ] ?? 0 ) + 1;
		$samples[]                              = $result['metrics'];
	}
	$metrics = array();
	foreach ( array_keys( $samples[0] ) as $name ) {
		$metrics[ $name ] = BenchmarkSummary::summarize( array_column( $samples, $name ) );
	}
	return array(
		'status_counts' => $statuses,
		'latencies'     => $metrics,
	);
}

/**
 * Run the bounded probe and report only aggregated evidence.
 *
 * @return void
 * @throws RuntimeException For an unavailable transport or an invalid sample.
 */
function xnetvn_agent_hub_wp_probe_main(): void {
	try {
		$host = getenv( 'RTL_ALIAS_WP69' );
		if ( ! is_string( $host ) || '' === $host ) {
			throw new RuntimeException( 'Probe host is unavailable.' );
		}
		$url  = 'https://' . $host . '/wp-login.php';
		$curl = curl_init();
		if ( false === $curl ) {
			throw new RuntimeException( 'Probe transport unavailable.' );
		}
		$login = xnetvn_agent_hub_wp_probe_collect( $curl, $url, 'POST' );
		// A plain read of the same site: the reference for the cost outside the MCP route.
		$root = xnetvn_agent_hub_wp_probe_collect( $curl, 'https://' . $host . '/', 'GET' );
		curl_reset( $curl );
		echo json_encode(
			array(
				'probe'           => 'wp69-wp-login-empty-post',
				'warmup_per_case' => 10,
				'status_counts'   => $login['status_counts'],
				'latencies'       => $login['latencies'],
				'reference'       => array(
					'probe'         => 'wp69-root-get',
					'status_counts' => $root['status_counts'],
					'latencies'     => $root['latencies'],
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		) . "\n";
	} catch ( Throwable $exception ) {
		// Static messages only; the probe never prints bodies, headers or credentials.
		fwrite( STDERR, 'Probe did not produce complete validated evidence.' . "\n" );
		exit( 1 );
	}
}

xnetvn_agent_hub_wp_probe_main();
