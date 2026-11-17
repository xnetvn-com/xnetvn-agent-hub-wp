<?php
/**
 * Network-wide administrator protection before account moderation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use WP_User;
use XNetVN\AgentHubWP\Domain\Decision;

/** Fails closed when a target may hold administrative authority on any site. */
final class NetworkUserProtection {

	/** Maximum inspected site memberships before the check refuses. */
	public const MAX_INSPECTED_SITES = 1000;

	/**
	 * Decide whether a target is safe to moderate on the current site.
	 *
	 * @param int $target_id Target user.
	 * @return Decision
	 */
	public function evaluate( int $target_id ): Decision {
		try {
			if ( $target_id < 1 ) {
				return Decision::deny( 'target_missing' );
			}
			$target = get_userdata( $target_id );
			if ( ! $target instanceof WP_User || (int) $target->ID !== $target_id ) {
				return Decision::deny( 'target_missing' );
			}
			if ( ! is_multisite() ) {
				return $this->inspect( $target );
			}
			if ( is_super_admin( $target_id ) ) {
				return Decision::deny( 'target_protected_super_admin' );
			}
			$sites = get_blogs_of_user( $target_id, true );
			if ( ! is_array( $sites ) || count( $sites ) > self::MAX_INSPECTED_SITES ) {
				return Decision::deny( 'network_enumeration_incomplete' );
			}
			$initial = get_current_blog_id();
			try {
				foreach ( $sites as $site ) {
					$site_id = is_object( $site ) && isset( $site->userblog_id ) ? (int) $site->userblog_id : 0;
					if ( $site_id < 1 || ! switch_to_blog( $site_id ) ) {
						return Decision::deny( 'network_enumeration_incomplete' );
					}
					$user = new WP_User( $target_id );
					$user->for_site( $site_id );
					$verdict = $this->inspect( $user );
					if ( ! $verdict->allowed ) {
						return $verdict;
					}
				}
				return Decision::permit();
			} finally {
				while ( ms_is_switched() && get_current_blog_id() !== $initial ) {
					restore_current_blog();
				}
			}
		} catch ( \Throwable ) {
			return Decision::deny( 'network_check_failed' );
		}
	}

	/**
	 * Inspect one fresh user object for the current site.
	 *
	 * @param WP_User $user Fresh target.
	 * @return Decision
	 */
	private function inspect( WP_User $user ): Decision {
		$wp_roles = wp_roles();
		if ( ! $wp_roles instanceof \WP_Roles || ! is_array( $wp_roles->roles ) || ! is_array( $user->roles ) || ! is_array( $user->caps ) ) {
			return Decision::deny( 'network_role_state_unavailable' );
		}
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return Decision::deny( 'target_protected_account' );
		}
		$privileged = RoleGuard::privileged_caps();
		foreach ( $user->roles as $role ) {
			if ( ! isset( $wp_roles->roles[ $role ] ) ) {
				return Decision::deny( 'network_role_state_unavailable' );
			}
			$role_caps = $wp_roles->roles[ $role ]['capabilities'] ?? null;
			if ( ! is_array( $role_caps ) ) {
				return Decision::deny( 'network_role_state_unavailable' );
			}
			foreach ( $privileged as $capability ) {
				if ( ! empty( $role_caps[ $capability ] ) ) {
					return Decision::deny( 'target_protected_account' );
				}
			}
		}
		foreach ( $privileged as $capability ) {
			if ( ! empty( $user->caps[ $capability ] ) || user_can( $user, $capability ) ) {
				return Decision::deny( 'target_protected_account' );
			}
		}
		return Decision::permit();
	}
}
