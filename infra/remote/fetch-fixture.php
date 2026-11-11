<?php
/**
 * Fixed, container-private HTTPS responses for network acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// This file is aliased only by the owned test container's TLS virtual host.
$xnetvn_agent_hub_wp_case = $_GET['case'] ?? 'png';
if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_string( $xnetvn_agent_hub_wp_case ) || ! in_array( $xnetvn_agent_hub_wp_case, array( 'png', 'redirect', 'private', 'gzip', 'oversize', 'slow', 'headers' ), true ) ) {
	http_response_code( 400 );
	exit;
}
if ( 'redirect' === $xnetvn_agent_hub_wp_case || 'private' === $xnetvn_agent_hub_wp_case ) {
	header( 'Location: ' . ( 'private' === $xnetvn_agent_hub_wp_case ? 'https://127.0.0.1/never' : '/xnetvn-fetch-fixture?case=png' ), true, 302 );
	exit;
}
if ( 'headers' === $xnetvn_agent_hub_wp_case ) {
	header( 'Content-Type: application/json' );
	// Inspect raw Apache headers, reporting only presence and never values.
	$xnetvn_agent_hub_wp_headers = array_change_key_case( getallheaders(), CASE_LOWER );
	echo json_encode( array( 'authorization_present' => array_key_exists( 'authorization', $xnetvn_agent_hub_wp_headers ), 'cookie_present' => array_key_exists( 'cookie', $xnetvn_agent_hub_wp_headers ) ), JSON_THROW_ON_ERROR );
	exit;
}
if ( 'gzip' === $xnetvn_agent_hub_wp_case ) {
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Encoding: gzip' );
	echo gzencode( str_repeat( 'x', 1048576 ) );
	exit;
}
if ( 'slow' === $xnetvn_agent_hub_wp_case ) {
	sleep( 2 );
}
if ( 'oversize' === $xnetvn_agent_hub_wp_case ) {
	while ( ob_get_level() > 0 ) { ob_end_flush(); }
	ob_implicit_flush( true );
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: 8192' );
	echo str_repeat( 'x', 2048 );
	flush();
	sleep( 2 );
	echo str_repeat( 'x', 6144 );
	exit;
}
header( 'Content-Type: image/png' );
echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=', true );
