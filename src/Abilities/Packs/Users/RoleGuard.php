<?php
/**
 * Privileged capability set and assignable role gate.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

/** Role names, capabilities and filters are untrusted data. */
final class RoleGuard {
	/** Privileged capabilities; one match is enough for protection. */
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
	 * Return the effective privileged set with add-only third-party widening.
	 *
	 * @return array<string>
	 */
	public static function privileged_caps(): array {
		$base = self::PRIVILEGED;
		try {
			if ( ! function_exists( 'apply_filters' ) ) {
				return $base;
			}
			$filtered = apply_filters( 'xnetvn_agent_hub_wp_user_privileged_caps', $base );
			if ( ! is_array( $filtered ) ) {
				return $base;
			}
			$extra = array();
			foreach ( $filtered as $cap ) {
				if ( ! is_string( $cap ) || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $cap ) ) {
					continue;
				}
				$extra[] = $cap;
			}
			$merged = array_values( array_unique( array_merge( $base, $extra ) ) );
			foreach ( $base as $required ) {
				if ( ! in_array( $required, $merged, true ) ) {
					return $base;
				}
			}
			return $merged;
		} catch ( \Throwable ) {
			return $base;
		}
	}

	/**
	 * Whether a role slug is privileged.
	 *
	 * @param string              $slug Role slug.
	 * @param array<string,mixed> $role_caps Optional raw role capabilities for tests.
	 * @return bool
	 */
	public static function is_privileged( string $slug, ?array $role_caps = null ): bool {
		if ( 'administrator' === $slug ) {
			return true;
		}
		$pcs = self::privileged_caps();
		if ( null !== $role_caps ) {
			foreach ( $role_caps as $cap => $enabled ) {
				if ( $enabled && in_array( (string) $cap, $pcs, true ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! function_exists( 'wp_roles' ) ) {
			return false;
		}
		try {
			$roles = wp_roles();
			if ( ! isset( $roles->roles[ $slug ]['capabilities'] ) || ! is_array( $roles->roles[ $slug ]['capabilities'] ) ) {
				return false;
			}
			foreach ( $roles->roles[ $slug ]['capabilities'] as $cap => $enabled ) {
				if ( $enabled && in_array( (string) $cap, $pcs, true ) ) {
					return true;
				}
			}
		} catch ( \Throwable ) {
			return false;
		}
		return false;
	}

	/**
	 * Load core editable-role helpers when REST/MCP runs without admin includes.
	 *
	 * Core itself requires wp-admin/includes/user.php lazily before calling
	 * get_editable_roles() on REST user updates; MCP tool calls run in the same
	 * admin-less context. Fail closed when the file is unavailable.
	 *
	 * @return bool Whether get_editable_roles() is now available.
	 */
	public static function ensure_editable_roles(): bool {
		try {
			if ( function_exists( 'get_editable_roles' ) ) {
				return true;
			}
			if ( ! defined( 'ABSPATH' ) ) {
				return false;
			}
			$admin_user_functions = ABSPATH . 'wp-admin/includes/user.php';
			if ( ! is_readable( $admin_user_functions ) ) {
				return false;
			}
			require_once $admin_user_functions;
			return function_exists( 'get_editable_roles' );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Whether a role may be assigned through MCP.
	 *
	 * @param string              $slug Role slug.
	 * @param array<string,mixed> $role_caps Optional raw capabilities for tests.
	 * @param array<string>       $editable Optional editable roles for tests.
	 * @return bool
	 */
	public static function assignable( string $slug, ?array $role_caps = null, ?array $editable = null ): bool {
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $slug ) ) {
			return false;
		}
		if ( 'banned' === $slug ) {
			return false;
		}
		if ( self::is_privileged( $slug, $role_caps ) ) {
			return false;
		}
		if ( null !== $editable ) {
			return in_array( $slug, $editable, true );
		}
		try {
			if ( ! self::ensure_editable_roles() ) {
				return false;
			}
			if ( ! function_exists( 'wp_roles' ) ) {
				return false;
			}
			$roles = wp_roles();
			if ( ! isset( $roles->roles[ $slug ] ) ) {
				return false;
			}
			$editable_roles = get_editable_roles();
			return isset( $editable_roles[ $slug ] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Ensure the capability-less banned role exists.
	 *
	 * @return string|\WP_Error Role slug or error.
	 */
	public static function ensure_banned_role() {
		try {
			if ( ! function_exists( 'wp_roles' ) || ! function_exists( 'add_role' ) ) {
				return \XNetVN\AgentHubWP\Abilities\ReasonMapper::error( 'internal_error' );
			}
			$roles = wp_roles();
			if ( isset( $roles->roles['banned'] ) ) {
				$caps = is_array( $roles->roles['banned']['capabilities'] ?? null ) ? $roles->roles['banned']['capabilities'] : array();
				foreach ( $caps as $enabled ) {
					if ( $enabled ) {
						return \XNetVN\AgentHubWP\Abilities\ReasonMapper::error( 'invalid_state' );
					}
				}
				return 'banned';
			}
			$created = add_role( 'banned', 'Banned', array() );
			if ( null === $created ) {
				return \XNetVN\AgentHubWP\Abilities\ReasonMapper::error( 'internal_error' );
			}
			return 'banned';
		} catch ( \Throwable ) {
			return \XNetVN\AgentHubWP\Abilities\ReasonMapper::error( 'internal_error' );
		}
	}
}
