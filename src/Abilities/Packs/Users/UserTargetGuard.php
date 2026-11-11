<?php
/**
 * Protected account gate for user targets.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

/** User records and filters are untrusted data. */
final class UserTargetGuard {
	/**
	 * Evaluate a user target at call time without caching.
	 *
	 * @param int $user_id Target identifier.
	 * @return string One of missing, self, protected, denied, allowed.
	 */
	public static function evaluate( int $user_id ): string {
		try {
			if ( ! function_exists( 'get_userdata' ) || ! function_exists( 'get_current_user_id' ) ) {
				return 'missing';
			}
			$user = get_userdata( $user_id );
			if ( false === $user ) {
				return 'missing';
			}
			if ( (int) get_current_user_id() === $user_id ) {
				return 'self';
			}
			if ( self::is_protected_user( $user ) ) {
				return 'protected';
			}
			try {
				if ( ! function_exists( 'apply_filters' ) ) {
					return 'allowed';
				}
				$allowed = apply_filters( 'xnetvn_agent_hub_wp_user_allowed', true, $user );
			} catch ( \Throwable ) {
				return 'denied';
			}
			if ( true !== $allowed ) {
				return 'denied';
			}
			return 'allowed';
		} catch ( \Throwable ) {
			return 'missing';
		}
	}

	/**
	 * Whether step three of the guard marks this user protected.
	 *
	 * @param \WP_User $user Target.
	 * @return bool
	 */
	public static function is_protected_user( \WP_User $user ): bool {
		try {
			if ( function_exists( 'is_super_admin' ) && is_super_admin( $user->ID ) ) {
				return true;
			}
			if ( in_array( 'administrator', $user->roles, true ) ) {
				return true;
			}
			foreach ( (array) $user->roles as $slug ) {
				if ( RoleGuard::is_privileged( (string) $slug ) ) {
					return true;
				}
			}
			$caps = is_array( $user->caps ) ? $user->caps : array();
			$pcs  = RoleGuard::privileged_caps();
			foreach ( $caps as $cap => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}
				$name = (string) $cap;
				if ( function_exists( 'wp_roles' ) ) {
					try {
						$roles = wp_roles();
						if ( isset( $roles->roles[ $name ] ) ) {
							continue;
						}
					} catch ( \Throwable ) {
						continue;
					}
				}
				if ( in_array( $name, $pcs, true ) ) {
					return true;
				}
			}
			foreach ( $pcs as $cap ) {
				try {
					if ( $user->has_cap( $cap ) ) {
						return true;
					}
				} catch ( \Throwable ) {
					continue;
				}
			}
		} catch ( \Throwable ) {
			return true;
		}
		return false;
	}
}
