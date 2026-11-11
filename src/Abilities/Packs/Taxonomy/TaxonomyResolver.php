<?php
/**
 * Deny-only runtime taxonomy selection.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Taxonomy;

use WP_Error;
use WP_Taxonomy;
use XNetVN\AgentHubWP\Abilities\Packs\Content\PostTypeResolver;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Managed tools cannot reach internal or foreign-pack taxonomies. */
final class TaxonomyResolver {
	/** System and separately scoped taxonomies. */
	private const EXCLUDED = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );

	/**
	 * Resolve an exact default-controller taxonomy at invocation time.
	 *
	 * @param string $slug Candidate slug.
	 * @return WP_Taxonomy|WP_Error
	 */
	public function resolve( string $slug ): WP_Taxonomy|WP_Error {
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $slug ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		$tax = get_taxonomy( $slug );
		if ( ! $tax instanceof WP_Taxonomy ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		if ( true !== $tax->show_in_rest ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		if ( in_array( $slug, self::EXCLUDED, true ) ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		$controller = $tax->rest_controller_class ? $tax->rest_controller_class : 'WP_REST_Terms_Controller';
		if ( 'WP_REST_Terms_Controller' !== $controller ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		$expected = $this->route( $tax );
		$actual   = rest_get_route_for_taxonomy_items( $slug );
		if ( '' === $actual || $expected !== $actual ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		$has_managed = false;
		foreach ( (array) $tax->object_type as $post_type ) {
			$resolved = ( new PostTypeResolver() )->resolve( (string) $post_type );
			if ( ! is_wp_error( $resolved ) ) {
				$has_managed = true;
				break;
			}
		}
		if ( ! $has_managed ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		try {
			$allowed = apply_filters( 'xnetvn_agent_hub_wp_taxonomy_allowed', true, $slug );
		} catch ( \Throwable ) {
			return ReasonMapper::error( 'taxonomy_unavailable' );
		}
		return true === $allowed ? $tax : ReasonMapper::error( 'taxonomy_unavailable' );
	}

	/**
	 * Build the expected core route from the taxonomy object.
	 *
	 * @param WP_Taxonomy $tax Taxonomy.
	 * @return string
	 */
	public function route( WP_Taxonomy $tax ): string {
		$namespace = $tax->rest_namespace ? (string) $tax->rest_namespace : 'wp/v2';
		$base      = $tax->rest_base ? (string) $tax->rest_base : (string) $tax->name;
		return '/' . $namespace . '/' . $base;
	}
}
