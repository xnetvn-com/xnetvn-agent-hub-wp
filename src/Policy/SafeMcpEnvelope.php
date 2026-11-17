<?php
/**
 * Static reviewed-operation envelope for every MCP execution path.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use XNetVN\AgentHubWP\Domain\Decision;

/** No key, tier, setting or hook may widen this code-owned allowlist. */
final class SafeMcpEnvelope {

	/** Human principal kind. */
	public const PRINCIPAL_USER = 'user';
	/** Accountless website principal kind. */
	public const PRINCIPAL_SITE = 'site';

	/** Reviewed editorial and system operations, including the FR_13 author lookup. */
	public const REVIEWED_EDITORIAL_SYSTEM_OPERATIONS = array(
		'xnetvn/assign-post-terms',
		'xnetvn/bulk-assign-post-terms',
		'xnetvn/bulk-create-terms',
		'xnetvn/bulk-moderate-comments',
		'xnetvn/bulk-update-posts',
		'xnetvn/create-comment',
		'xnetvn/create-post',
		'xnetvn/create-term',
		'xnetvn/delete-comment',
		'xnetvn/delete-media',
		'xnetvn/delete-post',
		'xnetvn/delete-term',
		'xnetvn/discover-abilities',
		'xnetvn/duplicate-post',
		'xnetvn/execute-ability',
		'xnetvn/get-ability-info',
		'xnetvn/get-comment',
		'xnetvn/get-comment-counts',
		'xnetvn/get-media',
		'xnetvn/get-media-upload-limits',
		'xnetvn/get-post',
		'xnetvn/get-post-meta',
		'xnetvn/get-revision',
		'xnetvn/get-session-info',
		'xnetvn/get-site-info',
		'xnetvn/get-term',
		'xnetvn/list-comments',
		'xnetvn/list-content-authors',
		'xnetvn/list-media',
		'xnetvn/list-post-statuses',
		'xnetvn/list-post-types',
		'xnetvn/list-posts',
		'xnetvn/list-revisions',
		'xnetvn/list-taxonomies',
		'xnetvn/list-terms',
		'xnetvn/moderate-comment',
		'xnetvn/purge-comments',
		'xnetvn/reply-comment',
		'xnetvn/restore-post',
		'xnetvn/restore-revision',
		'xnetvn/search-content',
		'xnetvn/set-featured-media',
		'xnetvn/stick-post',
		'xnetvn/trash-post',
		'xnetvn/update-comment',
		'xnetvn/update-media',
		'xnetvn/update-post',
		'xnetvn/update-post-meta',
		'xnetvn/update-term',
		'xnetvn/upload-media-from-data',
		'xnetvn/upload-media-from-url',
	);

	/** Human self-profile operations. */
	public const HUMAN_ONLY_PROFILE_OPERATIONS = array(
		'xnetvn/get-my-profile',
		'xnetvn/update-my-profile',
	);

	/** Site moderation operation, still gated by an explicit grant. */
	public const SITE_MODERATION_OPERATIONS = array(
		'xnetvn/set-user-status',
	);

	/**
	 * Decide whether one canonical operation is inside the reviewed envelope.
	 *
	 * @param string $canonical_name Canonical operation name.
	 * @param string $principal_type Principal kind.
	 * @return Decision
	 */
	public function evaluate( string $canonical_name, string $principal_type ): Decision {
		if ( ! in_array( $principal_type, array( self::PRINCIPAL_USER, self::PRINCIPAL_SITE ), true ) ) {
			return Decision::deny( 'operation_outside_safe_envelope' );
		}
		if ( in_array( $canonical_name, self::REVIEWED_EDITORIAL_SYSTEM_OPERATIONS, true ) ) {
			return Decision::permit();
		}
		if ( self::PRINCIPAL_USER === $principal_type && in_array( $canonical_name, self::HUMAN_ONLY_PROFILE_OPERATIONS, true ) ) {
			return Decision::permit();
		}
		if ( self::PRINCIPAL_SITE === $principal_type && in_array( $canonical_name, self::SITE_MODERATION_OPERATIONS, true ) ) {
			return Decision::permit();
		}
		return Decision::deny( 'operation_outside_safe_envelope' );
	}
}
