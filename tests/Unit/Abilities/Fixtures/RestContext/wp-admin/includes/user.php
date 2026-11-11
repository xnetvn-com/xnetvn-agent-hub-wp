<?php
/**
 * Core admin user functions substitute for isolated loader tests.
 *
 * @package XNetVN\AgentHubWP
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Intentional WordPress core substitutes; this file is required only by isolated RoleGuard loader tests.

if ( ! function_exists( 'get_editable_roles' ) ) {
	/**
	 * Editable roles visible to the current owner in the fixture site.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function get_editable_roles(): array {
		return array(
			'author' => array(
				'name'         => 'Author',
				'capabilities' => array( 'edit_posts' => true ),
			),
			'editor' => array(
				'name'         => 'Editor',
				'capabilities' => array( 'edit_posts' => true ),
			),
		);
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	/**
	 * Role registry substitute for isolated loader tests.
	 *
	 * @return object Object with a roles property mirroring the core shape.
	 */
	function wp_roles(): object {
		static $roles = null;
		if ( null === $roles ) {
			$roles        = new stdClass();
			$roles->roles = array(
				'author' => array(
					'name'         => 'Author',
					'capabilities' => array( 'edit_posts' => true ),
				),
				'editor' => array(
					'name'         => 'Editor',
					'capabilities' => array( 'edit_posts' => true ),
				),
			);
		}
		return $roles;
	}
}
// phpcs:enable
