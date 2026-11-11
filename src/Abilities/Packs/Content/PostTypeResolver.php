<?php
/**
 * Deny-only runtime post-type selection.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use WP_Error;
use WP_Post_Type;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** General content tools cannot bypass other packs through system post types. */
final class PostTypeResolver {
	/** System and separately scoped content. */
	private const EXCLUDED = array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'customize_changeset', 'custom_css', 'oembed_cache', 'user_request' );
	/**
	 * Resolve an exact default-controller post type at invocation time.
	 *
	 * @param string $slug Candidate slug.
	 * @return WP_Post_Type|WP_Error
	 */
	public function resolve( string $slug ): WP_Post_Type|WP_Error {
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,20}\z/', $slug ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		$type = get_post_type_object( $slug );
		if ( null === $type || true !== $type->show_in_rest || in_array( $slug, self::EXCLUDED, true ) || 'WP_REST_Posts_Controller' !== ( $type->rest_controller_class ? $type->rest_controller_class : 'WP_REST_Posts_Controller' ) || '' === rest_get_route_for_post_type_items( $slug ) ) {
			return ReasonMapper::error( 'post_type_unavailable' );
		}
		try {
			$allowed = apply_filters( 'xnetvn_agent_hub_wp_post_type_allowed', true, $slug );
		} catch ( \Throwable ) {
			$allowed = false;
		}
		return true === $allowed ? $type : ReasonMapper::error( 'post_type_unavailable' );
	}
}
