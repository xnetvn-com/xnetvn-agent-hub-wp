<?php
/**
 * Reviewed comment-target post selection.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Comments;

use WP_Error;
use WP_Post;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Comments attach to editable commenting posts, never to system storage. */
final class CommentTargetResolver {
	/** Post types that never accept managed comments. */
	private const EXCLUDED = array( 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'customize_changeset', 'custom_css', 'oembed_cache', 'user_request' );
	/**
	 * Resolve a post that may receive managed comments at invocation time.
	 *
	 * @param int $post_id Candidate post.
	 * @return WP_Post|WP_Error
	 */
	public function resolve( int $post_id ): WP_Post|WP_Error {
		if ( $post_id < 1 ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return ReasonMapper::error( 'not_found' );
		}
		if ( in_array( $post->post_type, self::EXCLUDED, true ) || ! post_type_supports( $post->post_type, 'comments' ) ) {
			return ReasonMapper::error( 'unsupported_operation' );
		}
		if ( in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'trash' ), true ) ) {
			return ReasonMapper::error( 'invalid_state' );
		}
		return $post;
	}
}
