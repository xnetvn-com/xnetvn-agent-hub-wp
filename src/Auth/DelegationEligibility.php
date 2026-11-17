<?php
/**
 * Protected-account eligibility for personal MCP credentials.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\Decision;

/** Re-reads live role and capability evidence; unknown state is denial. */
final class DelegationEligibility {

	/** Protected capability baseline; unfiltered_html is handled by the HTML envelope. */
	private const PRIVILEGED = array(
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'remove_users',
		'add_users',
		'manage_options',
		'import',
		'export',
		'manage_privacy_options',
		'export_others_personal_data',
		'erase_others_personal_data',
		'update_core',
		'update_php',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'update_plugins',
		'delete_plugins',
		'resume_plugins',
		'install_themes',
		'switch_themes',
		'edit_themes',
		'update_themes',
		'delete_themes',
		'resume_themes',
		'edit_files',
		'edit_theme_options',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'setup_network',
		'upgrade_network',
		'create_sites',
		'delete_sites',
	);

	/**
	 * Decide whether a user may hold a personal MCP credential.
	 *
	 * @param int $user_id Owner identity.
	 * @return Decision
	 */
	public function check( int $user_id ): Decision {
		try {
			if ( $user_id < 1 ) {
				return Decision::deny( 'delegation_owner_unavailable' );
			}
			$user = get_userdata( $user_id );
			if ( ! $user instanceof \WP_User || (int) $user->ID !== $user_id ) {
				return Decision::deny( 'delegation_owner_unavailable' );
			}
			if ( is_multisite() && is_super_admin( $user_id ) ) {
				return Decision::deny( 'delegation_protected_account' );
			}
			$wp_roles = wp_roles();
			if ( ! $wp_roles instanceof \WP_Roles || ! is_array( $wp_roles->roles ) || ! is_array( $user->roles ) || ! is_array( $user->caps ) ) {
				return Decision::deny( 'delegation_role_state_unavailable' );
			}
			$privileged = $this->privileged_caps();
			if ( null === $privileged ) {
				return Decision::deny( 'delegation_check_failed' );
			}
			if ( in_array( 'administrator', $user->roles, true ) ) {
				return Decision::deny( 'delegation_protected_account' );
			}
			foreach ( $user->roles as $role ) {
				if ( ! isset( $wp_roles->roles[ $role ] ) ) {
					return Decision::deny( 'delegation_role_state_unavailable' );
				}
				$role_caps = $wp_roles->roles[ $role ]['capabilities'] ?? null;
				if ( ! is_array( $role_caps ) ) {
					return Decision::deny( 'delegation_role_state_unavailable' );
				}
				foreach ( $privileged as $capability ) {
					if ( ! empty( $role_caps[ $capability ] ) ) {
						return Decision::deny( 'delegation_protected_account' );
					}
				}
			}
			foreach ( $privileged as $capability ) {
				if ( ! empty( $user->caps[ $capability ] ) ) {
					return Decision::deny( 'delegation_protected_account' );
				}
			}
			foreach ( $privileged as $capability ) {
				if ( user_can( $user, $capability ) ) {
					return Decision::deny( 'delegation_protected_account' );
				}
			}
			return user_can( $user, Capabilities::USE ) ? Decision::permit() : Decision::deny( 'delegation_capability_missing' );
		} catch ( \Throwable ) {
			return Decision::deny( 'delegation_check_failed' );
		}
	}

	/**
	 * Return the immutable baseline plus add-only extension capabilities.
	 *
	 * @return array<string>|null Null when the extension state is unusable.
	 */
	private function privileged_caps(): ?array {
		try {
			$filtered = apply_filters( 'xnetvn_agent_hub_wp_user_privileged_caps', self::PRIVILEGED );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_array( $filtered ) ) {
			return null;
		}
		$caps = self::PRIVILEGED;
		foreach ( $filtered as $capability ) {
			if ( ! is_string( $capability ) ) {
				return null;
			}
			$caps[] = $capability;
		}
		return array_values( array_unique( $caps ) );
	}
}
