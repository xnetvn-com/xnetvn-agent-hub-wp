<?php
/**
 * Isolated lab URLs and explicitly bounded reverse-proxy TLS recognition.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

$xnetvn_agent_hub_wp_domain = getenv( 'XNETVN_RTL_DOMAIN' );
$xnetvn_agent_hub_wp_alias  = getenv( 'XNETVN_RTL_ALIAS' );
$xnetvn_agent_hub_wp_host   = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
$xnetvn_agent_hub_wp_host   = preg_replace( '/:443$/', '', $xnetvn_agent_hub_wp_host );
$xnetvn_agent_hub_wp_site   = in_array( $xnetvn_agent_hub_wp_host, array( $xnetvn_agent_hub_wp_domain, $xnetvn_agent_hub_wp_alias ), true ) ? $xnetvn_agent_hub_wp_host : $xnetvn_agent_hub_wp_domain;
if ( is_string( $xnetvn_agent_hub_wp_site ) && '' !== $xnetvn_agent_hub_wp_site ) {
	define( 'WP_HOME', 'https://' . $xnetvn_agent_hub_wp_site );
	define( 'WP_SITEURL', WP_HOME );
}
$xnetvn_agent_hub_wp_peer = $_SERVER['REMOTE_ADDR'] ?? '';
// Apache-controlled native TLS is distinct from caller-supplied proxy headers.
$xnetvn_agent_hub_wp_native_tls = '443' === (string) ( $_SERVER['SERVER_PORT'] ?? '' ) && 'on' === ( $_SERVER['HTTPS'] ?? '' );
$_SERVER['HTTPS']              = $xnetvn_agent_hub_wp_native_tls ? 'on' : 'off';
require_once __DIR__ . '/Cidr.php';
$xnetvn_agent_hub_wp_cidrs = getenv( 'XNETVN_RTL_PROXY_CIDRS' );
if ( is_string( $xnetvn_agent_hub_wp_peer ) && is_string( $xnetvn_agent_hub_wp_cidrs ) && 'https' === ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null ) ) {
	foreach ( explode( ',', $xnetvn_agent_hub_wp_cidrs ) as $xnetvn_agent_hub_wp_cidr ) {
		if ( \XNetVN\AgentHubWP\Support\Cidr::contains( trim( $xnetvn_agent_hub_wp_cidr ), $xnetvn_agent_hub_wp_peer ) ) {
			$_SERVER['HTTPS'] = 'on';
			break;
		}
	}
}
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'XNETVN_AGENT_HUB_WP_ALLOW_INSECURE', false );

$xnetvn_agent_hub_wp_cache_host = getenv( 'XNETVN_RTL_CACHE_HOST' );
if ( is_string( $xnetvn_agent_hub_wp_cache_host ) && '' !== $xnetvn_agent_hub_wp_cache_host ) {
	define( 'WP_REDIS_HOST', $xnetvn_agent_hub_wp_cache_host );
	define( 'WP_REDIS_PASSWORD', getenv( 'XNETVN_RTL_CACHE_PASSWORD' ) );
	define( 'WP_REDIS_CLIENT', 'predis' );
	define( 'WP_REDIS_PREFIX', 'xnv-rtl:' . ( $table_prefix ?? 'unknown' ) . ':' );
	define( 'WP_REDIS_TIMEOUT', 0.25 );
	// The provider restores this value through stream_set_timeout(seconds) after group flush.
	// A fractional second is truncated to zero there; keep its supported integer default.
	define( 'WP_REDIS_READ_TIMEOUT', 1 );
	define( 'WP_REDIS_DISABLE_METRICS', true );
	define( 'WP_REDIS_DISABLE_DROPIN_AUTOUPDATE', true );
}
