<?php
/**
 * Actual shared-cache atomicity and site-isolation assertions for WP-CLI workers.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}

use XNetVN\AgentHubWP\RateLimit\WpBucketStore;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Owned test-only state is private and must never require a filesystem credential prompt.
$xnetvn_agent_hub_wp_counter_file = '/work/.local/cache-fixture/counter.json';
if ( is_link( $xnetvn_agent_hub_wp_counter_file ) ) {
	throw new RuntimeException( 'Counter fixture state ownership mismatch.' );
}
$xnetvn_agent_hub_wp_counter_action = $args[0] ?? '';
$xnetvn_agent_hub_wp_counter_state  = is_file( $xnetvn_agent_hub_wp_counter_file ) ? json_decode( file_get_contents( $xnetvn_agent_hub_wp_counter_file ), true, 8, JSON_THROW_ON_ERROR ) : array();
global $wpdb;
$xnetvn_agent_hub_wp_counter_store = new WpBucketStore( $wpdb );
if ( ! wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'The atomicity fixture requires a persistent cache.' );
}
if ( 'setup' === $xnetvn_agent_hub_wp_counter_action ) {
	if ( $xnetvn_agent_hub_wp_counter_state ) {
		throw new RuntimeException( 'Reconcile the existing counter fixture.' );
	}
	$xnetvn_agent_hub_wp_counter_state = array(
		'name'   => 'xnetvn_agent_hub_wp_rl_fixture_' . bin2hex( random_bytes( 8 ) ),
		'prefix' => $wpdb->prefix,
	);
	if ( false === file_put_contents( $xnetvn_agent_hub_wp_counter_file, wp_json_encode( $xnetvn_agent_hub_wp_counter_state ), LOCK_EX ) ) {
		throw new RuntimeException( 'Counter fixture state is unavailable.' );
	}
	chmod( $xnetvn_agent_hub_wp_counter_file, 0600 );
} elseif ( ! $xnetvn_agent_hub_wp_counter_state || ! is_string( $xnetvn_agent_hub_wp_counter_state['name'] ?? null ) || ! str_starts_with( $xnetvn_agent_hub_wp_counter_state['name'], 'xnetvn_agent_hub_wp_rl_fixture_' ) ) {
	throw new RuntimeException( 'Counter fixture state is unavailable.' );
} elseif ( 'worker' === $xnetvn_agent_hub_wp_counter_action ) {
	if ( $wpdb->prefix !== $xnetvn_agent_hub_wp_counter_state['prefix'] ) {
		throw new RuntimeException( 'Counter fixture site mismatch.' );
	}
	for ( $xnetvn_agent_hub_wp_counter_i = 0; $xnetvn_agent_hub_wp_counter_i < 20; ++$xnetvn_agent_hub_wp_counter_i ) {
		$xnetvn_agent_hub_wp_counter_store->increment( $xnetvn_agent_hub_wp_counter_state['name'] );
	}
} elseif ( 'probe-write' === $xnetvn_agent_hub_wp_counter_action ) {
	if ( $wpdb->prefix === $xnetvn_agent_hub_wp_counter_state['prefix'] || 1 !== $xnetvn_agent_hub_wp_counter_store->increment( $xnetvn_agent_hub_wp_counter_state['name'] . '_probe' ) ) {
		throw new RuntimeException( 'Isolation backend positive control failed.' );
	}
} elseif ( 'assert-total' === $xnetvn_agent_hub_wp_counter_action || 'assert-isolated' === $xnetvn_agent_hub_wp_counter_action ) {
	if ( 'assert-isolated' === $xnetvn_agent_hub_wp_counter_action && ( $wpdb->prefix === $xnetvn_agent_hub_wp_counter_state['prefix'] || 1 !== $xnetvn_agent_hub_wp_counter_store->count( $xnetvn_agent_hub_wp_counter_state['name'] . '_probe' ) ) ) {
		throw new RuntimeException( 'Isolation backend readback failed.' );
	}
	$xnetvn_agent_hub_wp_counter_expected = 'assert-total' === $xnetvn_agent_hub_wp_counter_action ? 200 : 0;
	$xnetvn_agent_hub_wp_counter_actual   = $xnetvn_agent_hub_wp_counter_store->count( $xnetvn_agent_hub_wp_counter_state['name'] );
	if ( $xnetvn_agent_hub_wp_counter_expected !== $xnetvn_agent_hub_wp_counter_actual ) {
		throw new RuntimeException( 'Shared counter atomicity or site isolation failed.' );
	}
	WP_CLI::line(
		wp_json_encode(
			array(
				'expected' => $xnetvn_agent_hub_wp_counter_expected,
				'actual'   => $xnetvn_agent_hub_wp_counter_actual,
			)
		)
	);
} elseif ( 'cleanup-site' === $xnetvn_agent_hub_wp_counter_action ) {
	$xnetvn_agent_hub_wp_cleanup_probe = $wpdb->prefix . ':' . $xnetvn_agent_hub_wp_counter_state['name'] . '_cleanup';
	if ( ! wp_cache_set( $xnetvn_agent_hub_wp_cleanup_probe, 1, 'xnetvn_agent_hub_wp_rate', 60 ) || ! wp_cache_delete( $xnetvn_agent_hub_wp_cleanup_probe, 'xnetvn_agent_hub_wp_rate' ) ) {
		throw new RuntimeException( 'Counter cleanup backend is unavailable.' );
	}
	foreach ( array( '', '_probe', '_cleanup' ) as $xnetvn_agent_hub_wp_counter_suffix ) {
		$xnetvn_agent_hub_wp_counter_key = $wpdb->prefix . ':' . $xnetvn_agent_hub_wp_counter_state['name'] . $xnetvn_agent_hub_wp_counter_suffix;
		wp_cache_delete( $xnetvn_agent_hub_wp_counter_key, 'xnetvn_agent_hub_wp_rate' );
		wp_cache_get( $xnetvn_agent_hub_wp_counter_key, 'xnetvn_agent_hub_wp_rate', true, $xnetvn_agent_hub_wp_counter_found );
		if ( $xnetvn_agent_hub_wp_counter_found ) {
			throw new RuntimeException( 'Counter cleanup readback failed.' );
		}
	}
} else {
	throw new RuntimeException( 'Unsupported counter fixture action.' );
}
