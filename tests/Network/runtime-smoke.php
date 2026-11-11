<?php
/**
 * Standalone real TLS acceptance for isolated PHP CLI versions, without a database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}

require '/sites/wp69/wp-includes/class-wp-hook.php';
require '/sites/wp69/wp-includes/plugin.php';
require '/sites/wp69/wp-includes/class-wp-error.php';
require '/sites/wp69/wp-includes/Requests/src/Autoload.php';
\WpOrg\Requests\Autoload::register();
require '/work/vendor/autoload.php';

// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- CLI acceptance must fail on runtime warnings/deprecations.
set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Isolated CLI diagnostics, never rendered HTML.
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

// Fixed synthetic fixture and operator-generated public CA; no product URL policy is bypassed.
$xnetvn_agent_hub_wp_transport = new \XNetVN\AgentHubWP\Support\Http\RequestsDownloadTransport( '/work/.local/mcp-clients-trust/lab-public-cert.pem' );
if ( ! $xnetvn_agent_hub_wp_transport->available() ) {
	throw new RuntimeException( 'Required native transport controls unavailable.' );
}
$xnetvn_agent_hub_wp_ip = gethostbyname( 'wp69' );
if ( 'wp69' === $xnetvn_agent_hub_wp_ip || 'xnv-pinning.test' !== gethostbyname( 'xnv-pinning.test' ) ) {
	throw new RuntimeException( 'Unexpected fixture DNS state.' );
}
$xnetvn_agent_hub_wp_results = array();
foreach ( array( 'png', 'private', 'gzip', 'oversize', 'slow' ) as $xnetvn_agent_hub_wp_scenario ) {
	$xnetvn_agent_hub_wp_file = tempnam( '/tmp', 'xnetvn-php-matrix-' );
	if ( false === $xnetvn_agent_hub_wp_file ) {
		throw new RuntimeException( 'Cannot create owned fixture temporary file.' );
	}
	try {
		$xnetvn_agent_hub_wp_start   = microtime( true );
		$xnetvn_agent_hub_wp_reply   = $xnetvn_agent_hub_wp_transport->request(
			array(
				'url'  => 'https://xnv-pinning.test/xnetvn-fetch-fixture?case=' . $xnetvn_agent_hub_wp_scenario,
				'host' => 'xnv-pinning.test',
				'ip'   => $xnetvn_agent_hub_wp_ip,
			),
			$xnetvn_agent_hub_wp_file,
			'oversize' === $xnetvn_agent_hub_wp_scenario ? 1024 : 16384,
			'slow' === $xnetvn_agent_hub_wp_scenario ? 0.4 : 3.0
		);
		$xnetvn_agent_hub_wp_elapsed = microtime( true ) - $xnetvn_agent_hub_wp_start;
		if ( in_array( $xnetvn_agent_hub_wp_scenario, array( 'oversize', 'slow' ), true ) ) {
			$xnetvn_agent_hub_wp_reason = 'oversize' === $xnetvn_agent_hub_wp_scenario ? 'payload_too_large' : 'remote_fetch_failed';
			if ( ! $xnetvn_agent_hub_wp_reply instanceof WP_Error || 'xnetvn_agent_hub_wp_' . $xnetvn_agent_hub_wp_reason !== $xnetvn_agent_hub_wp_reply->get_error_code() || $xnetvn_agent_hub_wp_elapsed >= 1.5 || ( 'oversize' === $xnetvn_agent_hub_wp_scenario && filesize( $xnetvn_agent_hub_wp_file ) > 1024 ) ) {
				throw new RuntimeException( 'Native abort/deadline acceptance failed.' );
			}
		} elseif ( $xnetvn_agent_hub_wp_reply instanceof WP_Error ) {
			throw new RuntimeException( 'Native fixture response failed.' );
		} elseif ( 'private' === $xnetvn_agent_hub_wp_scenario ) {
			if ( 302 !== $xnetvn_agent_hub_wp_reply->status || 'https://127.0.0.1/never' !== $xnetvn_agent_hub_wp_reply->location ) {
				throw new RuntimeException( 'Native transport followed a redirect.' ); }
		} elseif ( 200 !== $xnetvn_agent_hub_wp_reply->status || ( 'gzip' === $xnetvn_agent_hub_wp_scenario && ( ! $xnetvn_agent_hub_wp_reply->encoded || filesize( $xnetvn_agent_hub_wp_file ) >= 4096 ) ) ) {
			throw new RuntimeException( 'Native response/encoding acceptance failed.' );
		}
		$xnetvn_agent_hub_wp_results[ $xnetvn_agent_hub_wp_scenario ] = true;
	} finally {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this standalone test-owned temporary file.
		unlink( $xnetvn_agent_hub_wp_file );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CLI emits fixed JSON without WordPress formatting functions.
echo json_encode(
	array(
		'php'    => PHP_VERSION,
		'curl'   => curl_version()['version'],
		'passed' => $xnetvn_agent_hub_wp_results,
		'scope'  => 'Native transport with installed core Requests; not full WordPress integration.',
	),
	JSON_THROW_ON_ERROR
) . "\n";
