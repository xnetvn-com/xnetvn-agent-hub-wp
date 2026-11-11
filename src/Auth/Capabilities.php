<?php
/**
 * Dynamic effective WordPress capabilities.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;

/** Derives effective permissions without mutating persistent WordPress roles. */
final class Capabilities implements ServiceProvider {
	/** Delegated key use capability. */
	public const USE = 'use_xnetvn_agent_hub_wp';
	/** Key/configuration management capability. */
	public const MANAGE = 'manage_xnetvn_agent_hub_wp';
	/**
	 * Bind current site policy.
	 *
	 * @param SiteSettings $settings Validated settings.
	 */
	public function __construct( private SiteSettings $settings ) {
	}
	/** Register the dynamic capability filter. */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'restrict_delegation' ), PHP_INT_MAX, 4 );
		add_filter( 'user_has_cap', array( $this, 'filter' ), 10, 4 );
	}
	/**
	 * Preserve explicit site denial before core's multisite super-administrator shortcut.
	 *
	 * @param array<string> $caps Existing primitive requirements.
	 * @param string        $cap Requested capability.
	 * @param int           $user_id Actor.
	 * @param array<mixed>  $args Object context.
	 * @return array<string>
	 */
	public function restrict_delegation( array $caps, string $cap, int $user_id, array $args ): array {
		if ( self::USE !== $cap || in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}
		try {
			$user = get_userdata( $user_id );
			if ( false !== $user && in_array( 'banned', (array) $user->roles, true ) ) {
				return array( 'do_not_allow' );
			}
			$override = $this->settings->get( 'allowed_roles_override' );
			if ( null === $override ) {
				return $caps;
			}
			return false !== $user && is_array( $override ) && array_intersect( $user->roles, $override ) ? $caps : array( 'do_not_allow' );
		} catch ( \Throwable ) {
			return array( 'do_not_allow' );
		}
	}
	/**
	 * Add only the two plugin capability decisions.
	 *
	 * @param array<string,bool> $allcaps Current role/user capabilities.
	 * @param array<string>      $caps Required primitive capabilities.
	 * @param array<mixed>       $args Original capability arguments.
	 * @param \WP_User           $user Actor.
	 * @return array<string,bool>
	 */
	public function filter( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! in_array( self::USE, $caps, true ) && ! in_array( self::MANAGE, $caps, true ) ) {
			return $allcaps;
		}
		if ( in_array( 'banned', (array) $user->roles, true ) ) {
			$allcaps[ self::USE ]    = false;
			$allcaps[ self::MANAGE ] = false;
			return $allcaps;
		}
		$allcaps[ self::MANAGE ] = ! empty( $allcaps[ is_network_admin() ? 'manage_network_options' : 'manage_options' ] );
		try {
			$override             = $this->settings->get( 'allowed_roles_override' );
			$allcaps[ self::USE ] = null === $override ? ! empty( $allcaps['edit_posts'] ) : ( is_array( $override ) && (bool) array_intersect( $user->roles, $override ) );
		} catch ( \Throwable ) {
			$allcaps[ self::USE ] = false;
		}
		return $allcaps;
	}
}
