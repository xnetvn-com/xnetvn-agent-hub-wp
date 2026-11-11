<?php
/**
 * Uninstall entry point; PHP 7.2 syntax only.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'xnetvn_agent_hub_wp_uninstall_site' ) ) {
	/**
	 * Remove only this site's owned data; explicit preservation retains it.
	 *
	 * @return bool Whether the site chose deletion.
	 */
	function xnetvn_agent_hub_wp_uninstall_site(): bool {
		global $wpdb;
		foreach ( array( 'xnetvn_agent_hub_wp_purge_audit', 'xnetvn_agent_hub_wp_purge_audit_backlog', 'xnetvn_agent_hub_wp_notify_expiring_keys', 'xnetvn_agent_hub_wp_notify_expiring_keys_batch' ) as $xnetvn_agent_hub_wp_hook ) {
			wp_unschedule_hook( $xnetvn_agent_hub_wp_hook );
		}
		$xnetvn_agent_hub_wp_settings = get_option( 'xnetvn_agent_hub_wp_settings', array() );
		if ( is_array( $xnetvn_agent_hub_wp_settings ) && isset( $xnetvn_agent_hub_wp_settings['delete_data_on_uninstall'] ) && false === $xnetvn_agent_hub_wp_settings['delete_data_on_uninstall'] ) {
			return false;
		}
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/', $wpdb->prefix ) ) {
			wp_die( esc_html__( 'Agent Hub storage configuration is unavailable.', 'xnetvn-agent-hub-wp' ) );
		}
		foreach ( array( 'keys', 'audit' ) as $xnetvn_agent_hub_wp_suffix ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $xnetvn_agent_hub_wp_suffix ) ) ) {
				wp_die( esc_html__( 'Agent Hub data removal could not complete.', 'xnetvn-agent-hub-wp' ) );
			}
		}
		$xnetvn_agent_hub_wp_like = $wpdb->esc_like( 'xnetvn_agent_hub_wp_' ) . '%';
		do {
			$xnetvn_agent_hub_wp_options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name LIMIT 500', $wpdb->options, $xnetvn_agent_hub_wp_like ) );
			if ( '' !== $wpdb->last_error ) {
				wp_die( esc_html__( 'Agent Hub data removal could not complete.', 'xnetvn-agent-hub-wp' ) );
			}
			foreach ( $xnetvn_agent_hub_wp_options as $xnetvn_agent_hub_wp_option ) {
				if ( ! delete_option( $xnetvn_agent_hub_wp_option ) ) {
					wp_die( esc_html__( 'Agent Hub data removal could not complete.', 'xnetvn-agent-hub-wp' ) );
				}
			}
			$xnetvn_agent_hub_wp_more_options = count( $xnetvn_agent_hub_wp_options ) === 500;
		} while ( $xnetvn_agent_hub_wp_more_options );
		return true;
	}
}

$xnetvn_agent_hub_wp_preserve_preferences = false;
if ( is_multisite() ) {
	$xnetvn_agent_hub_wp_offset = 0;
	do {
		$xnetvn_agent_hub_wp_sites = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $xnetvn_agent_hub_wp_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		foreach ( $xnetvn_agent_hub_wp_sites as $xnetvn_agent_hub_wp_site ) {
			switch_to_blog( $xnetvn_agent_hub_wp_site );
			try {
				if ( ! xnetvn_agent_hub_wp_uninstall_site() ) {
					$xnetvn_agent_hub_wp_preserve_preferences = true;
				}
			} finally {
				restore_current_blog();
			}
		}
		$xnetvn_agent_hub_wp_offset    += 100;
		$xnetvn_agent_hub_wp_more_sites = count( $xnetvn_agent_hub_wp_sites ) === 100;
	} while ( $xnetvn_agent_hub_wp_more_sites );
} else {
	$xnetvn_agent_hub_wp_preserve_preferences = ! xnetvn_agent_hub_wp_uninstall_site();
}

if ( ! $xnetvn_agent_hub_wp_preserve_preferences ) {
	do {
		$xnetvn_agent_hub_wp_users = get_users(
			array(
				'fields'   => 'ID',
				'number'   => 100,
				'meta_key' => 'xnetvn_agent_hub_wp_notifications',
				'blog_id'  => 0,
			)
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall consumes bounded plugin-owned metadata batches.
		foreach ( $xnetvn_agent_hub_wp_users as $xnetvn_agent_hub_wp_user ) {
			if ( ! delete_user_meta( (int) $xnetvn_agent_hub_wp_user, 'xnetvn_agent_hub_wp_notifications' ) && '' !== get_user_meta( (int) $xnetvn_agent_hub_wp_user, 'xnetvn_agent_hub_wp_notifications', true ) ) {
				wp_die( esc_html__( 'Agent Hub data removal could not complete.', 'xnetvn-agent-hub-wp' ) );
			}
		}
		$xnetvn_agent_hub_wp_more_users = count( $xnetvn_agent_hub_wp_users ) === 100;
	} while ( $xnetvn_agent_hub_wp_more_users );
}
