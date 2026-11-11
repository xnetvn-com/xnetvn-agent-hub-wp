<?php
/**
 * Ban snapshot storage and validation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

/** Snapshot content is untrusted data. */
final class BanSnapshot {
	/** Storage key without site prefix; WordPress adds the prefix. */
	public const KEY = 'xnetvn_agent_hub_wp_ban_snapshot';

	/** Allowed ban reasons. */
	public const REASONS = array( 'spam', 'abuse', 'security', 'policy', 'other' );

	/**
	 * Build a snapshot array from live user state.
	 *
	 * @param array<string>       $roles Direct roles.
	 * @param array<string,mixed> $caps Direct caps including false blocks.
	 * @param int                 $by Actor identifier.
	 * @param string              $reason Ban reason code.
	 * @return array<string,mixed>
	 */
	public static function build( array $roles, array $caps, int $by, string $reason ): array {
		$clean_roles = array();
		foreach ( array_values( $roles ) as $slug ) {
			if ( is_string( $slug ) && 1 === preg_match( '/\A[a-z0-9_-]{1,64}\z/', $slug ) && count( $clean_roles ) < 20 ) {
				$clean_roles[] = $slug;
			}
		}
		$clean_caps = array();
		foreach ( $caps as $cap => $enabled ) {
			if ( count( $clean_caps ) >= 200 ) {
				break;
			}
			if ( ! is_string( $cap ) || 1 !== preg_match( '/\A[A-Za-z0-9_:.\-]{1,100}\z/', $cap ) ) {
				continue;
			}
			$clean_caps[ $cap ] = (bool) $enabled;
		}
		return array(
			'v'      => 1,
			'roles'  => $clean_roles,
			'caps'   => $clean_caps,
			'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'by'     => $by,
			'reason' => in_array( $reason, self::REASONS, true ) ? $reason : 'other',
		);
	}

	/**
	 * Validate a decoded snapshot without trusting stored values.
	 *
	 * @param mixed $data Decoded value.
	 * @return bool
	 */
	public static function validate( mixed $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( ( $data['v'] ?? null ) !== 1 ) {
			return false;
		}
		$roles = $data['roles'] ?? null;
		$caps  = $data['caps'] ?? null;
		if ( ! is_array( $roles ) || count( $roles ) > 20 || ! is_array( $caps ) || count( $caps ) > 200 ) {
			return false;
		}
		foreach ( $roles as $slug ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $slug ) ) {
				return false;
			}
			if ( 'banned' === $slug || 'administrator' === $slug || RoleGuard::is_privileged( $slug, array() ) ) {
				if ( 'administrator' === $slug ) {
					return false;
				}
			}
		}
		foreach ( $caps as $cap => $enabled ) {
			if ( ! is_string( $cap ) || 1 !== preg_match( '/\A[A-Za-z0-9_:.\-]{1,100}\z/', $cap ) || ! is_bool( $enabled ) ) {
				return false;
			}
		}
		$at = $data['at'] ?? null;
		if ( ! is_string( $at ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $at ) ) {
			return false;
		}
		if ( ! isset( $data['by'] ) || ! is_int( $data['by'] ) || $data['by'] < 1 ) {
			return false;
		}
		if ( ! in_array( $data['reason'] ?? null, self::REASONS, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Load and validate the stored snapshot.
	 *
	 * @param int $user_id Target.
	 * @return array<string,mixed>|null
	 */
	public static function load( int $user_id ): ?array {
		try {
			if ( ! function_exists( 'get_user_option' ) ) {
				return null;
			}
			$raw = get_user_option( self::KEY, $user_id );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return null;
			}
			$data = json_decode( $raw, true, 8 );
			if ( ! is_array( $data ) || ! self::validate( $data ) ) {
				return null;
			}
			return $data;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Save a snapshot only when none valid exists.
	 *
	 * @param int                 $user_id Target.
	 * @param array<string,mixed> $snapshot Validated snapshot.
	 * @return bool
	 */
	public static function save( int $user_id, array $snapshot ): bool {
		try {
			if ( ! self::validate( $snapshot ) ) {
				return false;
			}
			if ( null !== self::load( $user_id ) ) {
				return true;
			}
			if ( ! function_exists( 'update_user_option' ) ) {
				return false;
			}
			$json = wp_json_encode( $snapshot );
			if ( ! is_string( $json ) ) {
				return false;
			}
			return (bool) update_user_option( $user_id, self::KEY, $json, false );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Delete the stored snapshot.
	 *
	 * @param int $user_id Target.
	 * @return void
	 */
	public static function clear( int $user_id ): void {
		try {
			if ( function_exists( 'delete_user_option' ) ) {
				delete_user_option( $user_id, self::KEY, false );
			}
		} catch ( \Throwable ) {
			return;
		}
	}
}
