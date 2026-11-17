<?php
/**
 * Ban snapshot storage and validation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use XNetVN\AgentHubWP\Domain\SiteAuthContext;

/** Snapshot content is untrusted data. */
final class BanSnapshot {
	/** Storage key without site prefix; WordPress adds the prefix. */
	public const KEY = 'xnetvn_agent_hub_wp_ban_snapshot';

	/** Allowed ban reasons. */
	public const REASONS = array( 'spam', 'abuse', 'security', 'policy', 'other' );

	/**
	 * Build a v1 human snapshot array from live user state.
	 *
	 * @param array<string>       $roles Direct roles.
	 * @param array<string,mixed> $caps Direct caps including false blocks.
	 * @param int                 $by Actor identifier.
	 * @param string              $reason Ban reason code.
	 * @return array<string,mixed>
	 */
	public static function build( array $roles, array $caps, int $by, string $reason ): array {
		$payload = self::payload( $roles, $caps, $reason );
		return array(
			'v'      => 1,
			'roles'  => $payload['roles'],
			'caps'   => $payload['caps'],
			'at'     => $payload['at'],
			'by'     => $by,
			'reason' => $payload['reason'],
		);
	}

	/**
	 * Build a v2 human snapshot with an explicit typed actor.
	 *
	 * @param array<string>       $roles Direct roles.
	 * @param array<string,mixed> $caps Direct caps including false blocks.
	 * @param int                 $user_id Positive human actor identity.
	 * @param string              $reason Ban reason code.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For a non-positive human actor.
	 */
	public static function build_for_user( array $roles, array $caps, int $user_id, string $reason ): array {
		if ( $user_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid ban snapshot actor.' );
		}
		$payload = self::payload( $roles, $caps, $reason );
		return array(
			'v'      => 2,
			'roles'  => $payload['roles'],
			'caps'   => $payload['caps'],
			'at'     => $payload['at'],
			'actor'  => array(
				'type'    => 'user',
				'user_id' => $user_id,
			),
			'reason' => $payload['reason'],
		);
	}

	/**
	 * Build a v2 accountless website snapshot for a machine principal.
	 *
	 * @param \WP_User        $target Ban target.
	 * @param SiteAuthContext $context Verified machine identity.
	 * @param string          $reason Ban reason code.
	 * @return array<string,mixed>
	 */
	public static function build_for_site( \WP_User $target, SiteAuthContext $context, string $reason ): array {
		$payload = self::payload( array_values( (array) $target->roles ), (array) $target->caps, $reason );
		return array(
			'v'      => 2,
			'roles'  => $payload['roles'],
			'caps'   => $payload['caps'],
			'at'     => $payload['at'],
			'actor'  => array(
				'type'    => 'site',
				'blog_id' => $context->blog_id(),
				'key_id'  => $context->credential->key_id->value,
			),
			'reason' => $payload['reason'],
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
		$version = $data['v'] ?? null;
		if ( 1 === $version ) {
			if ( ! self::has_exact_keys( $data, array( 'v', 'roles', 'caps', 'at', 'by', 'reason' ) ) || array_key_exists( 'actor', $data ) ) {
				return false;
			}
			if ( ! isset( $data['by'] ) || ! is_int( $data['by'] ) || $data['by'] < 1 ) {
				return false;
			}
		} elseif ( 2 === $version ) {
			if ( ! self::has_exact_keys( $data, array( 'v', 'roles', 'caps', 'at', 'actor', 'reason' ) ) || array_key_exists( 'by', $data ) ) {
				return false;
			}
			if ( ! self::valid_actor( $data['actor'] ?? null ) ) {
				return false;
			}
		} else {
			return false;
		}
		$roles = $data['roles'] ?? null;
		$caps  = $data['caps'] ?? null;
		if ( ! is_array( $roles ) || count( $roles ) > 20 || ! is_array( $caps ) || count( $caps ) > 200 ) {
			return false;
		}
		$privileged = RoleGuard::privileged_caps();
		foreach ( $roles as $slug ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $slug ) ) {
				return false;
			}
			if ( 'banned' === $slug || 'administrator' === $slug || RoleGuard::is_privileged( $slug ) ) {
				return false;
			}
		}
		foreach ( $caps as $cap => $enabled ) {
			if ( ! is_string( $cap ) || 1 !== preg_match( '/\A[A-Za-z0-9_:.\-]{1,100}\z/', $cap ) || ! is_bool( $enabled ) ) {
				return false;
			}
			if ( self::is_role_name( $cap ) || in_array( $cap, $privileged, true ) ) {
				return false;
			}
		}
		if ( ! self::valid_at( $data['at'] ?? null ) ) {
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
	 * @phpstan-impure The stored value can change between calls in one request.
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
	 * The optional `$created` output tells a caller whether this invocation
	 * inserted the stored record, so cleanup can remove its own record without
	 * deleting a recovery point that already existed or that a concurrent ban
	 * now relies on.
	 *
	 * @param int                 $user_id Target.
	 * @param array<string,mixed> $snapshot Validated snapshot.
	 * @param bool                $created Set to true only when this call inserted the record.
	 * @return bool
	 */
	public static function save( int $user_id, array $snapshot, bool &$created = false ): bool {
		$created = false;
		try {
			if ( ! self::validate( $snapshot ) ) {
				return false;
			}
			if ( null !== self::load( $user_id ) ) {
				return true;
			}
			if ( ! function_exists( 'add_user_meta' ) ) {
				return false;
			}
			$json = wp_json_encode( $snapshot );
			if ( ! is_string( $json ) ) {
				return false;
			}
			$key = self::storage_key();
			if ( null === $key ) {
				return false;
			}
			if ( ! add_user_meta( $user_id, $key, $json, true ) ) {
				// A concurrent ban inserted first, or an unreadable value already owns the key.
				return null !== self::load( $user_id );
			}
			$created = true;
			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Resolve the blog-prefixed usermeta key that update_user_option() writes.
	 *
	 * @return string|null
	 */
	private static function storage_key(): ?string {
		try {
			global $wpdb;
			if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_blog_prefix' ) ) {
				return null;
			}
			return (string) $wpdb->get_blog_prefix() . self::KEY;
		} catch ( \Throwable ) {
			return null;
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

	/**
	 * Sanitize shared snapshot fields.
	 *
	 * @param array<string>       $roles Direct roles.
	 * @param array<string,mixed> $caps Direct caps.
	 * @param string              $reason Reason code.
	 * @return array{roles:array<string>,caps:array<string,bool>,at:string,reason:string}
	 */
	private static function payload( array $roles, array $caps, string $reason ): array {
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
			if ( ! is_string( $cap ) || 1 !== preg_match( '/\A[A-Za-z0-9_:.\-]{1,100}\z/', $cap ) || self::is_role_name( $cap ) ) {
				continue;
			}
			$clean_caps[ $cap ] = (bool) $enabled;
		}
		return array(
			'roles'  => $clean_roles,
			'caps'   => $clean_caps,
			'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'reason' => in_array( $reason, self::REASONS, true ) ? $reason : 'other',
		);
	}

	/**
	 * Require an exact key set without trusting order.
	 *
	 * @param array<string,mixed> $data Data.
	 * @param array<string>       $expected Expected keys.
	 * @return bool
	 */
	private static function has_exact_keys( array $data, array $expected ): bool {
		$keys = array_keys( $data );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	/**
	 * Validate one typed v2 actor shape.
	 *
	 * @param mixed $actor Actor candidate.
	 * @return bool
	 */
	private static function valid_actor( mixed $actor ): bool {
		if ( ! is_array( $actor ) ) {
			return false;
		}
		$type = $actor['type'] ?? null;
		if ( 'user' === $type ) {
			return self::has_exact_keys( $actor, array( 'type', 'user_id' ) ) && isset( $actor['user_id'] ) && is_int( $actor['user_id'] ) && $actor['user_id'] > 0;
		}
		if ( 'site' === $type ) {
			return self::has_exact_keys( $actor, array( 'type', 'blog_id', 'key_id' ) )
				&& isset( $actor['blog_id'], $actor['key_id'] )
				&& is_int( $actor['blog_id'] )
				&& $actor['blog_id'] > 0
				&& is_string( $actor['key_id'] )
				&& 1 === preg_match( '/\A[a-z2-7]{12}\z/', $actor['key_id'] );
		}
		return false;
	}

	/**
	 * Whether a capability key is actually a registered role slug.
	 *
	 * WordPress turns a capability key that names a role into role membership
	 * during restore, so such keys are never direct capabilities.
	 *
	 * @param string $name Capability key.
	 * @return bool
	 */
	private static function is_role_name( string $name ): bool {
		if ( 'administrator' === $name || 'banned' === $name ) {
			return true;
		}
		try {
			if ( ! function_exists( 'wp_roles' ) ) {
				return false;
			}
			$roles = wp_roles();
			if ( ! is_object( $roles ) ) {
				return false;
			}
			$map = get_object_vars( $roles );
			return isset( $map['roles'] ) && is_array( $map['roles'] ) && array_key_exists( $name, $map['roles'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Validate a real UTC timestamp.
	 *
	 * @param mixed $at Timestamp candidate.
	 * @return bool
	 */
	private static function valid_at( mixed $at ): bool {
		if ( ! is_string( $at ) || 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $at ) ) {
			return false;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $at, new \DateTimeZone( 'UTC' ) );
		return $parsed instanceof \DateTimeImmutable && $parsed->format( 'Y-m-d\TH:i:s\Z' ) === $at;
	}
}
