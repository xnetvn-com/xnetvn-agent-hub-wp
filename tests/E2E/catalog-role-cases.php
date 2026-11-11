<?php
/**
 * Declarations for the whole-catalog role/blog matrix (S5).
 *
 * Pure data and factories only: including this file performs no I/O, starts
 * no service and reads no environment value. The fixture, the standalone
 * matrix and the integration test share this single source of truth so the
 * declared tool/actor/scope set cannot drift from the executed cases.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone declarations shared by WP-CLI, a plain PHP runner and PHPUnit.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Script locals are not plugin globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Script locals are not core globals.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Case is the fixed ticket vocabulary.

/**
 * Actor capability model used for declaration and coverage checks.
 *
 * Only the capabilities that decide visibility or a declared denial are
 * listed. Real WordPress remains the authority at execution time; the
 * integration test compares this model with current_user_can().
 *
 * @return array<string,array<string,bool>>
 */
function xnetvn_agent_hub_wp_catalog_role_capabilities(): array {
	$all = array(
		'read'              => true,
		'edit_posts'        => true,
		'upload_files'      => true,
		'moderate_comments' => true,
		'list_users'        => true,
	);
	return array(
		'admin'       => $all,
		'editor'      => array_merge( $all, array( 'list_users' => false ) ),
		'author'      => array(
			'read'              => true,
			'edit_posts'        => true,
			'upload_files'      => true,
			'moderate_comments' => false,
			'list_users'        => false,
		),
		'contributor' => array(
			'read'              => true,
			'edit_posts'        => true,
			'upload_files'      => false,
			'moderate_comments' => false,
			'list_users'        => false,
		),
		'subscriber'  => array(
			'read'              => true,
			'edit_posts'        => false,
			'upload_files'      => false,
			'moderate_comments' => false,
			'list_users'        => false,
		),
		'banned'      => array(
			'read'              => false,
			'edit_posts'        => false,
			'upload_files'      => false,
			'moderate_comments' => false,
			'list_users'        => false,
		),
		'anonymous'   => array(
			'read'              => false,
			'edit_posts'        => false,
			'upload_files'      => false,
			'moderate_comments' => false,
			'list_users'        => false,
		),
		'super-admin' => $all,
	);
}

/**
 * Whether a target name belongs to the approved lab targets.
 *
 * @param string $target Target name.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_target_allowed( string $target ): bool {
	return in_array( $target, array( 'wp69', 'wp71', 'network-main', 'network-child' ), true );
}

/**
 * Whether a campaign name matches the reviewed timestamp shape.
 *
 * @param string $campaign Campaign name.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_campaign_valid( string $campaign ): bool {
	return 1 === preg_match( '/\Acat-[0-9]{8}T[0-9]{6}Z\z/', $campaign );
}

/**
 * Whether a login belongs to the exact run marker.
 *
 * @param string $campaign Campaign name.
 * @param string $login    Login.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_owned_login( string $campaign, string $login ): bool {
	if ( ! xnetvn_agent_hub_wp_catalog_role_campaign_valid( $campaign ) ) {
		return false;
	}
	return 1 === preg_match( '/\Acatalog-role-' . preg_quote( $campaign, '/' ) . '-[a-z0-9_-]{1,30}\z/i', $login );
}

/**
 * Whether a stored value carries the exact run marker.
 *
 * @param string $campaign Campaign name.
 * @param string $value    Candidate text.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_marked( string $campaign, string $value ): bool {
	if ( ! xnetvn_agent_hub_wp_catalog_role_campaign_valid( $campaign ) ) {
		return false;
	}
	return false !== stripos( $value, 'catalog-role-' . $campaign . '-' ) || false !== strpos( $value, 'CatalogRole ' . $campaign . ' ' );
}

/**
 * Build one tool declaration.
 *
 * @param string               $pack       Pack.
 * @param string               $tier       Risk tier.
 * @param string               $visibility Visibility capability.
 * @param array<string>        $allowed    Actors expected allowed.
 * @param string|array<string> $target    Target key or actor-keyed target map.
 * @param string               $payload    Payload factory key.
 * @param array<string,string> $denied     Extra tool-layer denials by actor.
 * @param array<string,mixed>  $extra      Extra declaration fields.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_tool( string $pack, string $tier, string $visibility, array $allowed, string|array $target, string $payload, array $denied = array(), array $extra = array() ): array {
	return array_merge(
		array(
			'pack'       => $pack,
			'tier'       => $tier,
			'visibility' => $visibility,
			'allowed'    => $allowed,
			'target'     => $target,
			'payload'    => $payload,
			'denied'     => $denied,
		),
		$extra
	);
}

/**
 * Static declarations: actors, users, keys, fixture objects and tools.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_data(): array {
	$toolsets    = array( 'system', 'content', 'media', 'taxonomy', 'comments', 'users' );
	$all_authed  = array( 'admin', 'editor', 'author', 'contributor' );
	$media       = array( 'admin', 'editor', 'author' );
	$editor      = array( 'admin', 'editor' );
	$admin       = array( 'admin' );
	$posts       = array(
		'admin'       => 'post_own_admin',
		'editor'      => 'post_own_editor',
		'author'      => 'post_own_author',
		'contributor' => 'post_own_contributor',
	);
	$trash       = array(
		'admin'       => 'post_trash_admin',
		'editor'      => 'post_trash_editor',
		'author'      => 'post_trash_author',
		'contributor' => 'post_trash_contributor',
		'super-admin' => 'post_trash_super_admin',
	);
	$restore     = array(
		'admin'       => 'post_restore_admin',
		'editor'      => 'post_restore_editor',
		'author'      => 'post_restore_author',
		'contributor' => 'post_restore_contributor',
		'super-admin' => 'post_restore_super_admin',
	);
	$delete      = array(
		'admin'       => 'post_delete_admin',
		'editor'      => 'post_delete_editor',
		'author'      => 'post_delete_author',
		'contributor' => 'post_delete_contributor',
		'super-admin' => 'post_delete_super_admin',
	);
	$duplicate   = array(
		'admin'       => 'post_duplicate_admin',
		'editor'      => 'post_duplicate_editor',
		'author'      => 'post_duplicate_author',
		'contributor' => 'post_duplicate_contributor',
	);
	$bulk        = array(
		'admin'       => 'post_bulk_a_admin',
		'editor'      => 'post_bulk_a_editor',
		'author'      => 'post_bulk_a_author',
		'contributor' => 'post_bulk_a_contributor',
	);
	$revision    = array(
		'admin'       => 'post_revision_admin',
		'editor'      => 'post_revision_editor',
		'author'      => 'post_revision_author',
		'contributor' => 'post_revision_contributor',
	);
	$meta        = array(
		'admin'       => 'post_meta_admin',
		'editor'      => 'post_meta_editor',
		'author'      => 'post_meta_author',
		'contributor' => 'post_meta_contributor',
	);
	$assign      = array(
		'admin'       => 'post_assign_admin',
		'editor'      => 'post_assign_editor',
		'author'      => 'post_assign_author',
		'contributor' => 'post_assign_contributor',
		'super-admin' => 'post_assign_super_admin',
	);
	$bulk_assign = array(
		'admin'       => 'post_bulk_assign_admin',
		'editor'      => 'post_bulk_assign_editor',
		'author'      => 'post_bulk_assign_author',
		'contributor' => 'post_bulk_assign_contributor',
		'super-admin' => 'post_bulk_assign_super_admin',
	);
	$featured    = array(
		'admin'  => 'post_featured_admin',
		'editor' => 'post_featured_editor',
		'author' => 'post_featured_author',
	);
	$sticky      = array(
		'admin'  => 'post_sticky_admin',
		'editor' => 'post_sticky_editor',
	);
	$media_own   = array(
		'admin'  => 'media_own_editor',
		'editor' => 'media_own_editor',
		'author' => 'media_own_author',
	);
	$media_upd   = array(
		'admin'  => 'media_update_editor',
		'editor' => 'media_update_editor',
		'author' => 'media_update_author',
	);
	$media_del   = array(
		'admin'       => 'media_delete_admin',
		'editor'      => 'media_delete_editor',
		'author'      => 'media_delete_author',
		'super-admin' => 'media_delete_super_admin',
	);
	$comments    = array(
		'admin'  => array(
			'parent'   => 'comment_parent_admin',
			'update'   => 'comment_update_admin',
			'moderate' => 'comment_moderate_admin',
			'bulk'     => 'comment_bulk_admin_a',
			'delete'   => 'comment_delete_admin',
			'purge'    => 'comment_purge_admin',
		),
		'editor' => array(
			'parent'   => 'comment_parent_editor',
			'update'   => 'comment_update_editor',
			'moderate' => 'comment_moderate_editor',
			'bulk'     => 'comment_bulk_editor_a',
			'delete'   => 'comment_delete_editor',
			'purge'    => 'comment_purge_editor',
		),
	);
	$comment_t   = array(
		'admin'  => 'comment_parent_admin',
		'editor' => 'comment_parent_editor',
	);
	$term        = array(
		'admin'  => 'term_update_admin',
		'editor' => 'term_update_editor',
	);
	$term_del    = array(
		'admin'  => 'term_delete_admin',
		'editor' => 'term_delete_editor',
	);

	return array(
		'suite'              => 'catalog-role-matrix',
		'version'            => 1,
		'lab_targets'        => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
		'network_targets'    => array( 'network-main', 'network-child' ),
		'a_h_linkage'        => array(
			'AUTH-01' => 'CAT-system-get-session-info-subscriber-denied',
			'AUTH-02' => 'CAT-system-get-session-info-banned-denied',
			'AUTH-03' => 'CAT-system-get-session-info-anonymous-denied',
			'FX-01'   => 'CAT-content-create-post-admin-allowed',
			'LIFE-01' => 'CAT-content-create-post-admin-allowed',
			'LIFE-04' => 'CAT-content-delete-post-admin-allowed',
			'IMG-01'  => 'CAT-media-get-media-admin-allowed',
			'SEO-03'  => 'CAT-taxonomy-assign-post-terms-admin-allowed',
			'NET-01'  => 'CAT-blog-site-info-admin-allowed',
			'STAB-01' => 'CAT-rate-auth-burst-denied',
			'STAB-02' => 'CAT-concurrency-content-update-post-admin-allowed',
		),
		'actors'             => array( 'admin', 'editor', 'author', 'contributor', 'subscriber', 'banned', 'anonymous', 'super-admin' ),
		'authenticated'      => $all_authed,
		'auth_denied'        => array( 'subscriber', 'banned', 'anonymous' ),
		'packs'              => array( 'system', 'content', 'media', 'taxonomy', 'comments', 'users' ),
		'toolsets'           => $toolsets,
		'capabilities'       => xnetvn_agent_hub_wp_catalog_role_capabilities(),
		'campaign_pattern'   => '/\Acat-[0-9]{8}T[0-9]{6}Z\z/',
		'logical_min'        => 5000,
		'logical_max'        => 5999,
		'marker_option'      => 'xnetvn_agent_hub_wp_catalog_role_fixture',
		'mail_count_option'  => 'xnetvn_catalog_role_mail_count',
		'mail_domain_option' => 'xnetvn_catalog_role_mail_domain',
		'maintenance_option' => 'xnetvn_catalog_role_maintenance',
		'mu_basename'        => 'xnetvn-catalog-role.php',
		'users'              => array(
			'admin'                 => array( 'role' => 'administrator' ),
			'editor'                => array( 'role' => 'editor' ),
			'author'                => array( 'role' => 'author' ),
			'contributor'           => array( 'role' => 'contributor' ),
			'subscriber'            => array( 'role' => 'subscriber' ),
			'banned'                => array( 'role' => 'banned' ),
			'protected_admin'       => array( 'role' => 'administrator' ),
			'user_target'           => array( 'role' => 'subscriber' ),
			'user_role'             => array( 'role' => 'subscriber' ),
			'user_ban'              => array( 'role' => 'subscriber' ),
			'user_reset'            => array( 'role' => 'subscriber' ),
			'user_sessions'         => array( 'role' => 'subscriber' ),
			'user_role_super_admin' => array( 'role' => 'subscriber' ),
			'user_ban_super_admin'  => array( 'role' => 'subscriber' ),
			'super_admin'           => array(
				'role'    => 'administrator',
				'super'   => true,
				'network' => true,
			),
		),
		'keys'               => array(
			'admin_core'   => array(
				'actor'    => 'admin',
				'tier'     => 'admin',
				'toolsets' => $toolsets,
			),
			'admin_users'  => array(
				'actor'    => 'admin',
				'tier'     => 'admin',
				'toolsets' => $toolsets,
			),
			'probe1'       => array(
				'actor'    => 'admin',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'probe2'       => array(
				'actor'    => 'admin',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'probe3'       => array(
				'actor'    => 'admin',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'probe4'       => array(
				'actor'    => 'admin',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'editor'       => array(
				'actor'    => 'editor',
				'tier'     => 'destructive',
				'toolsets' => $toolsets,
			),
			'author'       => array(
				'actor'    => 'author',
				'tier'     => 'destructive',
				'toolsets' => $toolsets,
			),
			'contributor'  => array(
				'actor'    => 'contributor',
				'tier'     => 'destructive',
				'toolsets' => $toolsets,
			),
			'subscriber'   => array(
				'actor'    => 'subscriber',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'banned'       => array(
				'actor'    => 'banned',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'super_admin'  => array(
				'actor'    => 'super_admin',
				'tier'     => 'admin',
				'toolsets' => $toolsets,
				'network'  => true,
			),
			'scope_read'   => array(
				'actor'    => 'editor',
				'tier'     => 'read',
				'toolsets' => $toolsets,
			),
			'scope_system' => array(
				'actor'    => 'editor',
				'tier'     => 'destructive',
				'toolsets' => array( 'system', 'users' ),
			),
			'scope_allow'  => array(
				'actor'    => 'editor',
				'tier'     => 'destructive',
				'toolsets' => $toolsets,
				'allow'    => array( 'xnetvn/get-session-info' ),
			),
			'scope_deny'   => array(
				'actor'    => 'editor',
				'tier'     => 'destructive',
				'toolsets' => $toolsets,
				'deny'     => array( 'xnetvn/get-post' ),
			),
		),
		'revisions'          => array(
			'admin'       => 5701,
			'editor'      => 5702,
			'author'      => 5703,
			'contributor' => 5704,
		),
		'targets'            => array(
			'post_own_admin'               => array(
				'id'     => 5101,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_concurrent_admin'        => array(
				'id'     => 5160,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_own_editor'              => array(
				'id'     => 5102,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_own_author'              => array(
				'id'     => 5103,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_own_contributor'         => array(
				'id'     => 5104,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_trash_admin'             => array(
				'id'     => 5105,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_trash_editor'            => array(
				'id'     => 5106,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_trash_author'            => array(
				'id'     => 5107,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_trash_contributor'       => array(
				'id'     => 5108,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_restore_admin'           => array(
				'id'     => 5109,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'trash',
			),
			'post_restore_editor'          => array(
				'id'     => 5110,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'trash',
			),
			'post_restore_author'          => array(
				'id'     => 5111,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'trash',
			),
			'post_restore_contributor'     => array(
				'id'     => 5112,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'trash',
			),
			'post_delete_admin'            => array(
				'id'     => 5113,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_delete_editor'           => array(
				'id'     => 5114,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_delete_author'           => array(
				'id'     => 5115,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_delete_contributor'      => array(
				'id'     => 5116,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_duplicate_admin'         => array(
				'id'        => 5117,
				'kind'      => 'post',
				'owner'     => 'admin',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_duplicate_editor'        => array(
				'id'        => 5118,
				'kind'      => 'post',
				'owner'     => 'editor',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_duplicate_author'        => array(
				'id'        => 5119,
				'kind'      => 'post',
				'owner'     => 'author',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_duplicate_contributor'   => array(
				'id'        => 5120,
				'kind'      => 'post',
				'owner'     => 'contributor',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_bulk_a_admin'            => array(
				'id'     => 5121,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_bulk_a_editor'           => array(
				'id'     => 5122,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_bulk_a_author'           => array(
				'id'     => 5123,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_bulk_a_contributor'      => array(
				'id'     => 5124,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_bulk_b_admin'            => array(
				'id'     => 5125,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_bulk_b_editor'           => array(
				'id'     => 5126,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_bulk_b_author'           => array(
				'id'     => 5127,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_bulk_b_contributor'      => array(
				'id'     => 5128,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_featured_admin'          => array(
				'id'     => 5129,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_featured_editor'         => array(
				'id'     => 5130,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_featured_author'         => array(
				'id'     => 5131,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_sticky_admin'            => array(
				'id'     => 5132,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'publish',
			),
			'post_sticky_editor'           => array(
				'id'     => 5133,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'publish',
			),
			'post_published'               => array(
				'id'     => 5134,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'publish',
			),
			'post_comment'                 => array(
				'id'       => 5135,
				'kind'     => 'post',
				'owner'    => 'editor',
				'status'   => 'publish',
				'comments' => 'open',
			),
			'post_revision_admin'          => array(
				'id'        => 5136,
				'kind'      => 'post',
				'owner'     => 'admin',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_revision_editor'         => array(
				'id'        => 5137,
				'kind'      => 'post',
				'owner'     => 'editor',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_revision_author'         => array(
				'id'        => 5138,
				'kind'      => 'post',
				'owner'     => 'author',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_revision_contributor'    => array(
				'id'        => 5139,
				'kind'      => 'post',
				'owner'     => 'contributor',
				'status'    => 'draft',
				'revisions' => true,
			),
			'post_meta_admin'              => array(
				'id'     => 5140,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_meta_editor'             => array(
				'id'     => 5141,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_meta_author'             => array(
				'id'     => 5142,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_meta_contributor'        => array(
				'id'     => 5143,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_assign_admin'            => array(
				'id'     => 5144,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_assign_editor'           => array(
				'id'     => 5145,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_assign_author'           => array(
				'id'     => 5146,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_assign_contributor'      => array(
				'id'     => 5147,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_bulk_assign_admin'       => array(
				'id'     => 5148,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_bulk_assign_editor'      => array(
				'id'     => 5149,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_bulk_assign_author'      => array(
				'id'     => 5150,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_bulk_assign_contributor' => array(
				'id'     => 5151,
				'kind'   => 'post',
				'owner'  => 'contributor',
				'status' => 'draft',
			),
			'post_owner_editor_private'    => array(
				'id'     => 5152,
				'kind'   => 'post',
				'owner'  => 'editor',
				'status' => 'draft',
			),
			'post_status_trashed'          => array(
				'id'     => 5153,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'trash',
			),
			'post_status_draft'            => array(
				'id'     => 5154,
				'kind'   => 'post',
				'owner'  => 'author',
				'status' => 'draft',
			),
			'post_trash_super_admin'       => array(
				'id'     => 5155,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_restore_super_admin'     => array(
				'id'     => 5156,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'trash',
			),
			'post_delete_super_admin'      => array(
				'id'     => 5157,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_assign_super_admin'      => array(
				'id'     => 5158,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'post_bulk_assign_super_admin' => array(
				'id'     => 5159,
				'kind'   => 'post',
				'owner'  => 'admin',
				'status' => 'draft',
			),
			'media_own_editor'             => array(
				'id'    => 5201,
				'kind'  => 'media',
				'owner' => 'editor',
			),
			'media_own_author'             => array(
				'id'    => 5202,
				'kind'  => 'media',
				'owner' => 'author',
			),
			'media_update_editor'          => array(
				'id'    => 5203,
				'kind'  => 'media',
				'owner' => 'editor',
			),
			'media_update_author'          => array(
				'id'    => 5204,
				'kind'  => 'media',
				'owner' => 'author',
			),
			'media_delete_admin'           => array(
				'id'    => 5205,
				'kind'  => 'media',
				'owner' => 'admin',
			),
			'media_delete_editor'          => array(
				'id'    => 5206,
				'kind'  => 'media',
				'owner' => 'editor',
			),
			'media_delete_author'          => array(
				'id'    => 5207,
				'kind'  => 'media',
				'owner' => 'author',
			),
			'media_featured_admin'         => array(
				'id'    => 5208,
				'kind'  => 'media',
				'owner' => 'admin',
			),
			'media_featured_editor'        => array(
				'id'    => 5209,
				'kind'  => 'media',
				'owner' => 'editor',
			),
			'media_featured_author'        => array(
				'id'    => 5210,
				'kind'  => 'media',
				'owner' => 'author',
			),
			'media_delete_super_admin'     => array(
				'id'    => 5211,
				'kind'  => 'media',
				'owner' => 'admin',
			),
			'term_category'                => array(
				'id'       => 5301,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Category',
			),
			'term_tag'                     => array(
				'id'       => 5302,
				'kind'     => 'term',
				'taxonomy' => 'post_tag',
				'name'     => 'CatalogRole Tag',
			),
			'term_update_admin'            => array(
				'id'       => 5303,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Update Admin',
			),
			'term_update_editor'           => array(
				'id'       => 5304,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Update Editor',
			),
			'term_delete_admin'            => array(
				'id'       => 5305,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Delete Admin',
			),
			'term_delete_editor'           => array(
				'id'       => 5306,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Delete Editor',
			),
			'term_assign'                  => array(
				'id'       => 5307,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Assign',
			),
			'term_bulk_assign'             => array(
				'id'       => 5308,
				'kind'     => 'term',
				'taxonomy' => 'post_tag',
				'name'     => 'CatalogRole Bulk Tag',
			),
			'term_read'                    => array(
				'id'       => 5309,
				'kind'     => 'term',
				'taxonomy' => 'category',
				'name'     => 'CatalogRole Read',
			),
			'comment_read'                 => array(
				'id'       => 5401,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 1,
			),
			'comment_parent_admin'         => array(
				'id'       => 5402,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 1,
			),
			'comment_parent_editor'        => array(
				'id'       => 5403,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 1,
			),
			'comment_update_admin'         => array(
				'id'       => 5404,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 1,
			),
			'comment_update_editor'        => array(
				'id'       => 5405,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 1,
			),
			'comment_moderate_admin'       => array(
				'id'       => 5406,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 0,
			),
			'comment_moderate_editor'      => array(
				'id'       => 5407,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 0,
			),
			'comment_bulk_admin_a'         => array(
				'id'       => 5408,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 1,
			),
			'comment_bulk_admin_b'         => array(
				'id'       => 5409,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 0,
			),
			'comment_bulk_editor_a'        => array(
				'id'       => 5410,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 1,
			),
			'comment_bulk_editor_b'        => array(
				'id'       => 5411,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 0,
			),
			'comment_delete_admin'         => array(
				'id'       => 5412,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 'trash',
			),
			'comment_delete_editor'        => array(
				'id'       => 5413,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 'trash',
			),
			'comment_purge_admin'          => array(
				'id'       => 5414,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 'spam',
			),
			'comment_purge_editor'         => array(
				'id'       => 5415,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'editor',
				'approved' => 'spam',
			),
			'comment_delete_super_admin'   => array(
				'id'       => 5416,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 'trash',
			),
			'comment_moderate_super_admin' => array(
				'id'       => 5417,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 0,
			),
			'comment_bulk_super_admin_a'   => array(
				'id'       => 5418,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 1,
			),
			'comment_bulk_super_admin_b'   => array(
				'id'       => 5419,
				'kind'     => 'comment',
				'post'     => 'post_comment',
				'owner'    => 'admin',
				'approved' => 0,
			),
		),
		'tools'              => array(
			'xnetvn/get-session-info'        => xnetvn_agent_hub_wp_catalog_role_tool( 'system', 'read', 'read', $all_authed, 'none', 'system.session' ),
			'xnetvn/get-site-info'           => xnetvn_agent_hub_wp_catalog_role_tool( 'system', 'read', 'read', $all_authed, 'none', 'system.site' ),
			'xnetvn/discover-abilities'      => xnetvn_agent_hub_wp_catalog_role_tool( 'system', 'read', 'read', $all_authed, 'none', 'system.discover' ),
			'xnetvn/get-ability-info'        => xnetvn_agent_hub_wp_catalog_role_tool( 'system', 'read', 'read', $all_authed, 'none', 'system.info' ),
			'xnetvn/execute-ability'         => xnetvn_agent_hub_wp_catalog_role_tool( 'system', 'read', 'read', $all_authed, 'none', 'system.execute' ),
			'xnetvn/list-post-types'         => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, 'none', 'content.list_post_types' ),
			'xnetvn/list-post-statuses'      => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, 'none', 'content.list_post_statuses' ),
			'xnetvn/list-posts'              => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, 'none', 'content.list_posts' ),
			'xnetvn/get-post'                => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, $posts, 'content.get_post' ),
			'xnetvn/create-post'             => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, 'none', 'content.create_post' ),
			'xnetvn/update-post'             => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $posts, 'content.update_post' ),
			'xnetvn/trash-post'              => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $trash, 'content.trash_post' ),
			'xnetvn/restore-post'            => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $restore, 'content.restore_post' ),
			'xnetvn/delete-post'             => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'destructive', 'edit_posts', $all_authed, $delete, 'content.delete_post' ),
			'xnetvn/duplicate-post'          => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $duplicate, 'content.duplicate_post' ),
			'xnetvn/bulk-update-posts'       => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $bulk, 'content.bulk_update_posts' ),
			'xnetvn/list-revisions'          => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, $revision, 'content.list_revisions' ),
			'xnetvn/get-revision'            => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, $revision, 'content.get_revision' ),
			'xnetvn/restore-revision'        => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $revision, 'content.restore_revision' ),
			'xnetvn/set-featured-media'      => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $media, $featured, 'content.set_featured_media', array( 'contributor' => 'forbidden' ) ),
			'xnetvn/stick-post'              => xnetvn_agent_hub_wp_catalog_role_tool(
				'content',
				'write',
				'edit_posts',
				$editor,
				$sticky,
				'content.stick_post',
				array(
					'author'      => 'forbidden',
					'contributor' => 'forbidden',
				)
			),
			'xnetvn/get-post-meta'           => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, $meta, 'content.get_post_meta' ),
			'xnetvn/update-post-meta'        => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'write', 'edit_posts', $all_authed, $meta, 'content.update_post_meta' ),
			'xnetvn/search-content'          => xnetvn_agent_hub_wp_catalog_role_tool( 'content', 'read', 'edit_posts', $all_authed, 'none', 'content.search_content' ),
			'xnetvn/list-media'              => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'read', 'upload_files', $media, 'none', 'media.list_media' ),
			'xnetvn/get-media'               => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'read', 'upload_files', $media, $media_own, 'media.get_media' ),
			'xnetvn/get-media-upload-limits' => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'read', 'upload_files', $media, 'none', 'media.limits' ),
			'xnetvn/update-media'            => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'write', 'upload_files', $media, $media_upd, 'media.update_media' ),
			'xnetvn/upload-media-from-data'  => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'write', 'upload_files', $media, 'none', 'media.upload_data' ),
			'xnetvn/upload-media-from-url'   => xnetvn_agent_hub_wp_catalog_role_tool(
				'media',
				'write',
				'upload_files',
				array(),
				'none',
				'media.upload_url',
				array(
					'admin'       => 'remote_fetch_disabled',
					'editor'      => 'remote_fetch_disabled',
					'author'      => 'remote_fetch_disabled',
					'contributor' => 'unavailable',
					'super-admin' => 'remote_fetch_disabled',
				)
			),
			'xnetvn/delete-media'            => xnetvn_agent_hub_wp_catalog_role_tool( 'media', 'destructive', 'upload_files', $media, $media_del, 'media.delete_media' ),
			'xnetvn/list-taxonomies'         => xnetvn_agent_hub_wp_catalog_role_tool( 'taxonomy', 'read', 'read', $all_authed, 'none', 'taxonomy.list_taxonomies' ),
			'xnetvn/list-terms'              => xnetvn_agent_hub_wp_catalog_role_tool( 'taxonomy', 'read', 'read', $all_authed, 'none', 'taxonomy.list_terms' ),
			'xnetvn/get-term'                => xnetvn_agent_hub_wp_catalog_role_tool( 'taxonomy', 'read', 'read', $all_authed, 'term_read', 'taxonomy.get_term' ),
			'xnetvn/create-term'             => xnetvn_agent_hub_wp_catalog_role_tool(
				'taxonomy',
				'write',
				'read',
				$editor,
				'none',
				'taxonomy.create_term',
				array(
					'author'      => 'forbidden',
					'contributor' => 'forbidden',
				)
			),
			'xnetvn/update-term'             => xnetvn_agent_hub_wp_catalog_role_tool(
				'taxonomy',
				'write',
				'read',
				$editor,
				$term,
				'taxonomy.update_term',
				array(
					'author'      => 'forbidden',
					'contributor' => 'forbidden',
				)
			),
			'xnetvn/assign-post-terms'       => xnetvn_agent_hub_wp_catalog_role_tool( 'taxonomy', 'write', 'read', $all_authed, $assign, 'taxonomy.assign_post_terms' ),
			'xnetvn/bulk-create-terms'       => xnetvn_agent_hub_wp_catalog_role_tool(
				'taxonomy',
				'write',
				'read',
				$editor,
				'none',
				'taxonomy.bulk_create_terms',
				array(
					'author'      => 'forbidden',
					'contributor' => 'forbidden',
				)
			),
			'xnetvn/bulk-assign-post-terms'  => xnetvn_agent_hub_wp_catalog_role_tool( 'taxonomy', 'write', 'read', $all_authed, $bulk_assign, 'taxonomy.bulk_assign_post_terms' ),
			'xnetvn/delete-term'             => xnetvn_agent_hub_wp_catalog_role_tool(
				'taxonomy',
				'destructive',
				'read',
				$editor,
				$term_del,
				'taxonomy.delete_term',
				array(
					'author'      => 'forbidden',
					'contributor' => 'forbidden',
				)
			),
			'xnetvn/list-comments'           => xnetvn_agent_hub_wp_catalog_role_tool( 'comments', 'read', 'moderate_comments', $editor, 'post_comment', 'comments.list_comments' ),
			'xnetvn/get-comment'             => xnetvn_agent_hub_wp_catalog_role_tool( 'comments', 'read', 'moderate_comments', $editor, 'comment_read', 'comments.get_comment' ),
			'xnetvn/get-comment-counts'      => xnetvn_agent_hub_wp_catalog_role_tool( 'comments', 'read', 'moderate_comments', $editor, 'post_comment', 'comments.counts' ),
			'xnetvn/create-comment'          => xnetvn_agent_hub_wp_catalog_role_tool( 'comments', 'write', 'moderate_comments', $editor, 'post_comment', 'comments.create_comment' ),
			'xnetvn/reply-comment'           => xnetvn_agent_hub_wp_catalog_role_tool( 'comments', 'write', 'moderate_comments', $editor, $comment_t, 'comments.reply_comment' ),
			'xnetvn/update-comment'          => xnetvn_agent_hub_wp_catalog_role_tool(
				'comments',
				'write',
				'moderate_comments',
				$editor,
				array(
					'admin'  => 'comment_update_admin',
					'editor' => 'comment_update_editor',
				),
				'comments.update_comment'
			),
			'xnetvn/moderate-comment'        => xnetvn_agent_hub_wp_catalog_role_tool(
				'comments',
				'write',
				'moderate_comments',
				$editor,
				array(
					'admin'       => 'comment_moderate_admin',
					'editor'      => 'comment_moderate_editor',
					'super-admin' => 'comment_moderate_super_admin',
				),
				'comments.moderate_comment'
			),
			'xnetvn/bulk-moderate-comments'  => xnetvn_agent_hub_wp_catalog_role_tool(
				'comments',
				'write',
				'moderate_comments',
				$editor,
				array(
					'admin'       => 'comment_bulk_admin_a',
					'editor'      => 'comment_bulk_editor_a',
					'super-admin' => 'comment_bulk_super_admin_a',
				),
				'comments.bulk_moderate'
			),
			'xnetvn/delete-comment'          => xnetvn_agent_hub_wp_catalog_role_tool(
				'comments',
				'destructive',
				'moderate_comments',
				$editor,
				array(
					'admin'       => 'comment_delete_admin',
					'editor'      => 'comment_delete_editor',
					'super-admin' => 'comment_delete_super_admin',
				),
				'comments.delete_comment'
			),
			'xnetvn/purge-comments'          => xnetvn_agent_hub_wp_catalog_role_tool(
				'comments',
				'destructive',
				'moderate_comments',
				$editor,
				array(
					'admin'  => 'comment_purge_admin',
					'editor' => 'comment_purge_editor',
				),
				'comments.purge_comments'
			),
			'xnetvn/list-users'              => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'read', 'list_users', $admin, 'none', 'users.list_users' ),
			'xnetvn/get-user'                => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'read', 'list_users', $admin, 'user:user_target', 'users.get_user' ),
			'xnetvn/list-roles'              => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'read', 'list_users', $admin, 'none', 'users.list_roles' ),
			'xnetvn/get-user-capabilities'   => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'read', 'list_users', $admin, 'user:user_target', 'users.get_caps', array(), array( 'network_denied' => array( 'admin' => 'forbidden' ) ) ),
			'xnetvn/get-my-profile'          => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'read', 'read', $all_authed, 'none', 'users.my_profile' ),
			'xnetvn/update-my-profile'       => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'write', 'read', $all_authed, 'none', 'users.update_profile' ),
			'xnetvn/create-user'             => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'admin', 'list_users', $admin, 'none', 'users.create_user', array(), array( 'network_denied' => array( 'admin' => 'forbidden' ) ) ),
			'xnetvn/update-user'             => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'admin', 'list_users', $admin, 'user:user_target', 'users.update_user', array(), array( 'network_denied' => array( 'admin' => 'forbidden' ) ) ),
			'xnetvn/set-user-role'           => xnetvn_agent_hub_wp_catalog_role_tool(
				'users',
				'admin',
				'list_users',
				$admin,
				array(
					'admin'       => 'user:user_role',
					'super-admin' => 'user:user_role_super_admin',
				),
				'users.set_role'
			),
			'xnetvn/set-user-status'         => xnetvn_agent_hub_wp_catalog_role_tool(
				'users',
				'admin',
				'list_users',
				$admin,
				array(
					'admin'       => 'user:user_ban',
					'super-admin' => 'user:user_ban_super_admin',
				),
				'users.set_status'
			),
			'xnetvn/send-password-reset'     => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'admin', 'list_users', $admin, 'user:user_reset', 'users.reset', array(), array( 'network_denied' => array( 'admin' => 'forbidden' ) ) ),
			'xnetvn/terminate-user-sessions' => xnetvn_agent_hub_wp_catalog_role_tool( 'users', 'admin', 'list_users', $admin, 'user:user_sessions', 'users.sessions', array(), array( 'network_denied' => array( 'admin' => 'forbidden' ) ) ),
		),
	);
}

/**
 * Key alias for one actor and pack.
 *
 * @param array<string,mixed> $data Data.
 * @param string              $actor Actor.
 * @param string              $pack Pack.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_key_for( array $data, string $actor, string $pack ): string {
	if ( 'super-admin' === $actor ) {
		return 'super_admin';
	}
	if ( 'admin' === $actor ) {
		return 'users' === $pack ? 'admin_users' : 'admin_core';
	}
	return $actor;
}

/**
 * Resolve the target key for one tool and actor.
 *
 * @param array<string,mixed> $tool Tool declaration.
 * @param string              $actor Actor.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_target_for( array $tool, string $actor ): string {
	$target = $tool['target'] ?? 'none';
	if ( is_array( $target ) ) {
		if ( isset( $target[ $actor ] ) ) {
			return (string) $target[ $actor ];
		}
		// A denied actor still needs a schema-valid payload; the policy or
		// core denial must be the observed layer, never an input-schema error.
		if ( isset( $target['admin'] ) ) {
			return (string) $target['admin'];
		}
		if ( isset( $target['editor'] ) ) {
			return (string) $target['editor'];
		}
		return 'none';
	}
	return (string) $target;
}

/**
 * Declared visibility for one actor.
 *
 * @param array<string,mixed> $data Data.
 * @param string              $tool Canonical tool name.
 * @param string              $actor Actor.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_visible( array $data, string $tool, string $actor ): bool {
	$declaration = $data['tools'][ $tool ] ?? null;
	if ( ! is_array( $declaration ) ) {
		return false;
	}
	if ( in_array( $actor, $data['auth_denied'], true ) ) {
		return false;
	}
	$key = xnetvn_agent_hub_wp_catalog_role_key_for( $data, $actor, $declaration['pack'] );
	if ( ! isset( $data['keys'][ $key ] ) ) {
		return false;
	}
	$scope = $data['keys'][ $key ];
	if ( ! in_array( $declaration['pack'], $scope['toolsets'], true ) ) {
		return false;
	}
	$tier_order = array(
		'read'        => 0,
		'write'       => 1,
		'destructive' => 2,
		'admin'       => 3,
	);
	if ( $tier_order[ $declaration['tier'] ] > $tier_order[ $scope['tier'] ] ) {
		return false;
	}
	if ( isset( $scope['allow'] ) && ! in_array( $tool, $scope['allow'], true ) ) {
		return false;
	}
	if ( isset( $scope['deny'] ) && in_array( $tool, $scope['deny'], true ) ) {
		return false;
	}
	$caps = $data['capabilities'][ $actor ] ?? array();
	return true === ( $caps[ $declaration['visibility'] ] ?? false );
}

/**
 * Compute the expected tools/list set for one actor.
 *
 * @param array<string,mixed> $data Data.
 * @param string              $actor Actor.
 * @return array<string>
 */
function xnetvn_agent_hub_wp_catalog_role_visible_tools( array $data, string $actor ): array {
	$names = array();
	foreach ( array_keys( $data['tools'] ) as $tool ) {
		if ( xnetvn_agent_hub_wp_catalog_role_visible( $data, $tool, $actor ) ) {
			$names[] = str_replace( '/', '-', $tool );
		}
	}
	sort( $names, SORT_STRING );
	return $names;
}

/**
 * Attach the secondary owned target or revision a factory needs.
 *
 * @param array<string,mixed> $case  Case row.
 * @param string              $actor Actor.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_attach_extra( array $case, string $actor ): array {
	if ( 'content.bulk_update_posts' === $case['payload'] ) {
		$case['extra_target'] = 'post_bulk_b_' . ( in_array( $actor, array( 'admin', 'editor', 'author', 'contributor' ), true ) ? $actor : 'admin' );
	}
	if ( 'content.set_featured_media' === $case['payload'] ) {
		$case['extra_target'] = 'media_featured_' . ( in_array( $actor, array( 'admin', 'editor', 'author' ), true ) ? $actor : 'admin' );
	}
	if ( 'comments.bulk_moderate' === $case['payload'] ) {
		$comment_actor        = 'super-admin' === $actor ? 'super_admin' : $actor;
		$case['extra_target'] = 'comment_bulk_' . ( in_array( $comment_actor, array( 'admin', 'editor', 'super_admin' ), true ) ? $comment_actor : 'admin' ) . '_b';
	}
	if ( in_array( $case['payload'], array( 'content.get_revision', 'content.restore_revision' ), true ) ) {
		$index                  = array_search( $actor, array( 'admin', 'editor', 'author', 'contributor' ), true );
		$case['extra_revision'] = false === $index ? 5701 : 5701 + (int) $index;
	}
	return $case;
}

/**
 * Generate one case row per tool and actor plus the explicit extra cases.
 *
 * @param array<string,mixed> $data Data.
 * @return array<string,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_catalog_role_cases( array $data ): array {
	$cases = array();
	foreach ( $data['tools'] as $name => $tool ) {
		$wire = str_replace( '/', '-', $name );
		$slug = (string) substr( $name, strlen( 'xnetvn/' ) );
		foreach ( $data['actors'] as $actor ) {
			$id     = 'CAT-' . $tool['pack'] . '-' . $slug . '-' . $actor . '-';
			$target = xnetvn_agent_hub_wp_catalog_role_target_for( $tool, $actor );
			$key    = xnetvn_agent_hub_wp_catalog_role_key_for( $data, $actor, $tool['pack'] );
			if ( in_array( $actor, $data['auth_denied'], true ) ) {
				$case                 = array(
					'id'       => $id . 'denied',
					'tool'     => $wire,
					'name'     => $name,
					'pack'     => $tool['pack'],
					'actor'    => $actor,
					'key'      => $key,
					'expected' => 'denied',
					'reason'   => 'anonymous' === $actor ? 'authentication_required' : 'forbidden',
					'layer'    => 'auth',
					'target'   => $target,
					'payload'  => $tool['payload'],
					'variant'  => 'denied',
					'http'     => 'anonymous' === $actor ? 401 : 403,
				);
				$case                 = xnetvn_agent_hub_wp_catalog_role_attach_extra( $case, $actor );
				$cases[ $case['id'] ] = $case;
				continue;
			}
			if ( ( 'super-admin' === $actor && ! isset( $tool['denied'][ $actor ] ) ) || in_array( $actor, $tool['allowed'], true ) ) {
				$case = array(
					'id'       => $id . 'allowed',
					'tool'     => $wire,
					'name'     => $name,
					'pack'     => $tool['pack'],
					'actor'    => $actor,
					'key'      => $key,
					'expected' => 'allowed',
					'reason'   => 'unavailable',
					'layer'    => 'tool',
					'target'   => $target,
					'payload'  => $tool['payload'],
					'variant'  => 'allowed',
				);
				if ( 'super-admin' === $actor ) {
					$case['network_only'] = true;
				}
				if ( isset( $tool['network_denied'][ $actor ] ) ) {
					$case['network_denied'] = (string) $tool['network_denied'][ $actor ];
				}
				$case                 = xnetvn_agent_hub_wp_catalog_role_attach_extra( $case, $actor );
				$cases[ $case['id'] ] = $case;
				continue;
			}
			$reason = (string) ( $tool['denied'][ $actor ] ?? 'unavailable' );
			$case   = array(
				'id'       => $id . 'denied',
				'tool'     => $wire,
				'name'     => $name,
				'pack'     => $tool['pack'],
				'actor'    => $actor,
				'key'      => $key,
				'expected' => 'denied',
				'reason'   => $reason,
				'layer'    => isset( $tool['denied'][ $actor ] ) && 'unavailable' !== $reason ? 'tool' : 'policy',
				'target'   => $target,
				'payload'  => $tool['payload'],
				'variant'  => 'denied',
			);
			if ( 'super-admin' === $actor ) {
				$case['network_only'] = true;
			}
			if ( isset( $tool['network_denied'][ $actor ] ) ) {
				$case['network_denied'] = (string) $tool['network_denied'][ $actor ];
			}
			$case                 = xnetvn_agent_hub_wp_catalog_role_attach_extra( $case, $actor );
			$cases[ $case['id'] ] = $case;
		}
	}
	$extras = array(
		array(
			'id'       => 'CAT-media-upload-media-from-url-admin-allowed-na',
			'tool'     => 'xnetvn-upload-media-from-url',
			'name'     => 'xnetvn/upload-media-from-url',
			'pack'     => 'media',
			'actor'    => 'admin',
			'key'      => 'admin_core',
			'expected' => 'na',
			'reason'   => 'remote_fetch_disabled_default',
			'layer'    => 'na',
			'target'   => 'none',
			'payload'  => 'media.upload_url',
			'variant'  => 'na',
		),
	);
	foreach ( array( 'admin', 'editor', 'author', 'contributor' ) as $actor ) {
		$extras[] = array(
			'id'       => 'CAT-catalog-tools-list-' . $actor . '-allowed',
			'tool'     => 'xnetvn-discover-abilities',
			'name'     => 'xnetvn/discover-abilities',
			'pack'     => 'system',
			'actor'    => $actor,
			'key'      => xnetvn_agent_hub_wp_catalog_role_key_for( $data, $actor, 'system' ),
			'expected' => 'allowed',
			'reason'   => 'unavailable',
			'layer'    => 'tool',
			'target'   => 'none',
			'payload'  => 'catalog.tools_list',
			'variant'  => 'allowed',
		);
	}
	$extras[] = array(
		'id'           => 'CAT-catalog-tools-list-super-admin-allowed',
		'tool'         => 'xnetvn-discover-abilities',
		'name'         => 'xnetvn/discover-abilities',
		'pack'         => 'system',
		'actor'        => 'super-admin',
		'key'          => 'super_admin',
		'expected'     => 'allowed',
		'reason'       => 'unavailable',
		'layer'        => 'tool',
		'target'       => 'none',
		'payload'      => 'catalog.tools_list',
		'variant'      => 'allowed',
		'network_only' => true,
	);
	foreach ( $data['packs'] as $pack ) {
		$extras[] = array(
			'id'       => 'CAT-catalog-discover-' . $pack . '-admin-allowed',
			'tool'     => 'xnetvn-discover-abilities',
			'name'     => 'xnetvn/discover-abilities',
			'pack'     => $pack,
			'actor'    => 'admin',
			'key'      => 'admin_users',
			'expected' => 'allowed',
			'reason'   => 'unavailable',
			'layer'    => 'tool',
			'target'   => 'none',
			'payload'  => 'catalog.discover',
			'variant'  => 'allowed',
			'extra'    => $pack,
		);
	}
	foreach ( array_keys( $data['tools'] ) as $name ) {
		$slug     = (string) substr( $name, strlen( 'xnetvn/' ) );
		$extras[] = array(
			'id'       => 'CAT-catalog-info-' . $slug . '-admin-allowed',
			'tool'     => 'xnetvn-get-ability-info',
			'name'     => 'xnetvn/get-ability-info',
			'pack'     => 'system',
			'actor'    => 'admin',
			'key'      => 'admin_users',
			'expected' => 'allowed',
			'reason'   => 'unavailable',
			'layer'    => 'tool',
			'target'   => 'none',
			'payload'  => 'catalog.info',
			'variant'  => 'allowed',
			'extra'    => $name,
		);
	}
	$extras = array_merge(
		$extras,
		array(
			array(
				'id'       => 'CAT-scope-tier-content-create-post-editor-denied',
				'tool'     => 'xnetvn-create-post',
				'name'     => 'xnetvn/create-post',
				'pack'     => 'content',
				'actor'    => 'editor',
				'key'      => 'scope_read',
				'expected' => 'denied',
				'reason'   => 'unavailable',
				'layer'    => 'policy',
				'target'   => 'none',
				'payload'  => 'content.create_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-scope-toolset-content-list-posts-editor-denied',
				'tool'     => 'xnetvn-list-posts',
				'name'     => 'xnetvn/list-posts',
				'pack'     => 'content',
				'actor'    => 'editor',
				'key'      => 'scope_system',
				'expected' => 'denied',
				'reason'   => 'unavailable',
				'layer'    => 'policy',
				'target'   => 'none',
				'payload'  => 'content.list_posts',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-scope-toolset-users-get-my-profile-editor-allowed',
				'tool'     => 'xnetvn-get-my-profile',
				'name'     => 'xnetvn/get-my-profile',
				'pack'     => 'users',
				'actor'    => 'editor',
				'key'      => 'scope_system',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'users.my_profile',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-scope-allow-content-get-post-editor-denied',
				'tool'     => 'xnetvn-get-post',
				'name'     => 'xnetvn/get-post',
				'pack'     => 'content',
				'actor'    => 'editor',
				'key'      => 'scope_allow',
				'expected' => 'denied',
				'reason'   => 'unavailable',
				'layer'    => 'policy',
				'target'   => 'post_own_editor',
				'payload'  => 'content.get_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-scope-allow-system-get-session-editor-allowed',
				'tool'     => 'xnetvn-get-session-info',
				'name'     => 'xnetvn/get-session-info',
				'pack'     => 'system',
				'actor'    => 'editor',
				'key'      => 'scope_allow',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'system.session',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-scope-deny-content-get-post-editor-denied',
				'tool'     => 'xnetvn-get-post',
				'name'     => 'xnetvn/get-post',
				'pack'     => 'content',
				'actor'    => 'editor',
				'key'      => 'scope_deny',
				'expected' => 'denied',
				'reason'   => 'unavailable',
				'layer'    => 'policy',
				'target'   => 'post_own_editor',
				'payload'  => 'content.get_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-scope-deny-content-list-posts-editor-allowed',
				'tool'     => 'xnetvn-list-posts',
				'name'     => 'xnetvn/list-posts',
				'pack'     => 'content',
				'actor'    => 'editor',
				'key'      => 'scope_deny',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'content.list_posts',
				'variant'  => 'allowed',
			),
			array(
				'id'             => 'CAT-scope-ceiling-content-delete-post-admin-denied',
				'tool'           => 'xnetvn-delete-post',
				'name'           => 'xnetvn/delete-post',
				'pack'           => 'content',
				'actor'          => 'admin',
				'key'            => 'admin_core',
				'expected'       => 'denied',
				'reason'         => 'unavailable',
				'layer'          => 'policy',
				'target'         => 'post_delete_admin',
				'payload'        => 'content.delete_post',
				'variant'        => 'denied',
				'variant_action' => 'ceiling-write',
			),
			array(
				'id'             => 'CAT-scope-admin-off-users-create-user-admin-denied',
				'tool'           => 'xnetvn-create-user',
				'name'           => 'xnetvn/create-user',
				'pack'           => 'users',
				'actor'          => 'admin',
				'key'            => 'admin_users',
				'expected'       => 'denied',
				'reason'         => 'unavailable',
				'layer'          => 'policy',
				'target'         => 'none',
				'payload'        => 'users.create_user',
				'variant'        => 'denied',
				'variant_action' => 'admin-off',
			),
			array(
				'id'       => 'CAT-wrapper-system-execute-ability-editor-denied',
				'tool'     => 'xnetvn-execute-ability',
				'name'     => 'xnetvn/execute-ability',
				'pack'     => 'system',
				'actor'    => 'editor',
				'key'      => 'scope_read',
				'expected' => 'denied',
				'reason'   => 'internal_error',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'system.execute_denied',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-hidden-system-get-ability-info-editor-denied',
				'tool'     => 'xnetvn-get-ability-info',
				'name'     => 'xnetvn/get-ability-info',
				'pack'     => 'system',
				'actor'    => 'editor',
				'key'      => 'editor',
				'expected' => 'denied',
				'reason'   => 'internal_error',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'system.info_hidden',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-owner-content-get-post-author-denied',
				'tool'     => 'xnetvn-get-post',
				'name'     => 'xnetvn/get-post',
				'pack'     => 'content',
				'actor'    => 'author',
				'key'      => 'author',
				'expected' => 'denied',
				'reason'   => 'forbidden',
				'layer'    => 'tool',
				'target'   => 'post_owner_editor_private',
				'payload'  => 'content.get_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-owner-content-update-post-author-denied',
				'tool'     => 'xnetvn-update-post',
				'name'     => 'xnetvn/update-post',
				'pack'     => 'content',
				'actor'    => 'author',
				'key'      => 'author',
				'expected' => 'denied',
				'reason'   => 'forbidden',
				'layer'    => 'tool',
				'target'   => 'post_owner_editor_private',
				'payload'  => 'content.update_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-owner-content-delete-post-author-denied',
				'tool'     => 'xnetvn-delete-post',
				'name'     => 'xnetvn/delete-post',
				'pack'     => 'content',
				'actor'    => 'author',
				'key'      => 'author',
				'expected' => 'denied',
				'reason'   => 'forbidden',
				'layer'    => 'tool',
				'target'   => 'post_owner_editor_private',
				'payload'  => 'content.delete_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-owner-users-set-user-status-admin-denied',
				'tool'     => 'xnetvn-set-user-status',
				'name'     => 'xnetvn/set-user-status',
				'pack'     => 'users',
				'actor'    => 'admin',
				'key'      => 'admin_users',
				'expected' => 'denied',
				'reason'   => 'protected_account',
				'layer'    => 'tool',
				'target'   => 'user:protected_admin',
				'payload'  => 'users.set_status',
				'variant'  => 'denied-protected',
			),
			array(
				'id'       => 'CAT-status-content-trash-post-author-denied',
				'tool'     => 'xnetvn-trash-post',
				'name'     => 'xnetvn/trash-post',
				'pack'     => 'content',
				'actor'    => 'author',
				'key'      => 'author',
				'expected' => 'denied',
				'reason'   => 'invalid_state',
				'layer'    => 'tool',
				'target'   => 'post_status_trashed',
				'payload'  => 'content.trash_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-status-content-restore-post-author-denied',
				'tool'     => 'xnetvn-restore-post',
				'name'     => 'xnetvn/restore-post',
				'pack'     => 'content',
				'actor'    => 'author',
				'key'      => 'author',
				'expected' => 'denied',
				'reason'   => 'invalid_state',
				'layer'    => 'tool',
				'target'   => 'post_status_draft',
				'payload'  => 'content.restore_post',
				'variant'  => 'denied',
			),
			array(
				'id'       => 'CAT-invalid-content-create-post-admin-denied',
				'tool'     => 'xnetvn-create-post',
				'name'     => 'xnetvn/create-post',
				'pack'     => 'content',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'denied',
				'reason'   => 'invalid_params',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'invalid.unregistered_meta',
				'variant'  => 'denied-invalid',
			),
			array(
				'id'       => 'CAT-invalid-content-update-post-meta-admin-denied',
				'tool'     => 'xnetvn-update-post-meta',
				'name'     => 'xnetvn/update-post-meta',
				'pack'     => 'content',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'denied',
				'reason'   => 'invalid_params',
				'layer'    => 'tool',
				'target'   => 'post_meta_admin',
				'payload'  => 'invalid.unregistered_meta_update',
				'variant'  => 'denied-invalid',
			),
			array(
				'id'       => 'CAT-blog-site-info-admin-allowed',
				'tool'     => 'xnetvn-get-site-info',
				'name'     => 'xnetvn/get-site-info',
				'pack'     => 'system',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'system.site',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-comments-purge-comments-admin-allowed',
				'tool'     => 'xnetvn-purge-comments',
				'name'     => 'xnetvn/purge-comments',
				'pack'     => 'comments',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'comment_purge_admin',
				'payload'  => 'comments.purge_comments',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-comments-purge-comments-editor-allowed',
				'tool'     => 'xnetvn-purge-comments',
				'name'     => 'xnetvn/purge-comments',
				'pack'     => 'comments',
				'actor'    => 'editor',
				'key'      => 'editor',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'comment_purge_editor',
				'payload'  => 'comments.purge_comments',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-pagination-content-list-posts-admin-allowed',
				'tool'     => 'xnetvn-list-posts',
				'name'     => 'xnetvn/list-posts',
				'pack'     => 'content',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'content.list_posts_page2',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-size-content-list-posts-admin-allowed',
				'tool'     => 'xnetvn-list-posts',
				'name'     => 'xnetvn/list-posts',
				'pack'     => 'content',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'tool',
				'target'   => 'none',
				'payload'  => 'content.list_posts_max',
				'variant'  => 'allowed',
			),
			array(
				'id'       => 'CAT-rate-auth-burst-denied',
				'tool'     => 'xnetvn-get-session-info',
				'name'     => 'xnetvn/get-session-info',
				'pack'     => 'system',
				'actor'    => 'anonymous',
				'key'      => 'anonymous',
				'expected' => 'denied',
				'reason'   => 'rate_limited',
				'layer'    => 'rate',
				'target'   => 'none',
				'payload'  => 'catalog.tools_list',
				'variant'  => 'denied',
				'http'     => 429,
			),
			array(
				'id'       => 'CAT-concurrency-content-update-post-admin-allowed',
				'tool'     => 'xnetvn-update-post',
				'name'     => 'xnetvn/update-post',
				'pack'     => 'content',
				'actor'    => 'admin',
				'key'      => 'admin_core',
				'expected' => 'allowed',
				'reason'   => 'unavailable',
				'layer'    => 'concurrency',
				'target'   => 'post_concurrent_admin',
				'payload'  => 'content.concurrent_update',
				'variant'  => 'allowed',
			),
			array(
				'id'           => 'CAT-blog-cross-key-admin-denied',
				'tool'         => 'xnetvn-get-session-info',
				'name'         => 'xnetvn/get-session-info',
				'pack'         => 'system',
				'actor'        => 'admin',
				'key'          => 'admin_core',
				'expected'     => 'denied',
				'reason'       => 'authentication_required',
				'layer'        => 'auth',
				'target'       => 'none',
				'payload'      => 'catalog.tools_list_other',
				'variant'      => 'denied',
				'network_only' => true,
				'http'         => 401,
			),
		)
	);
	foreach ( $extras as $extra ) {
		$cases[ $extra['id'] ] = $extra;
	}
	ksort( $cases, SORT_STRING );
	return $cases;
}

/**
 * Return the declaration set.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_declarations(): array {
	$data          = xnetvn_agent_hub_wp_catalog_role_data();
	$data['cases'] = xnetvn_agent_hub_wp_catalog_role_cases( $data );
	return $data;
}

/**
 * Build one valid or deliberately invalid payload for a declared factory.
 *
 * Only an explicit `$logical` marker is resolved by the matrix through the
 * owned fixture ledger; raw integers, including those in the 5000-5999
 * band, pass through unchanged so a real post id is never rewritten.
 *
 * @param array<string,mixed> $case Case row.
 * @param array<string,mixed> $ctx  Resolved context.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_payload( array $case, array $ctx ): array {
	$target   = (int) ( $ctx['target'] ?? 0 );
	$extra    = (int) ( $ctx['extra'] ?? 0 );
	$user     = (int) ( $ctx['user'] ?? 0 );
	$campaign = (string) ( $ctx['campaign'] ?? 'cat-20260101T000000Z' );
	$actor    = (string) ( $ctx['actor'] ?? 'admin' );
	$canary   = 'CR-CANARY-' . (string) ( $case['id'] ?? 'unknown' );
	switch ( (string) $case['payload'] ) {
		case 'system.session':
		case 'system.site':
			return array();
		case 'system.discover':
			return array( 'per_page' => 100 );
		case 'system.info':
			return array( 'name' => 'xnetvn/get-site-info' );
		case 'system.execute':
			return array(
				'name'      => 'xnetvn/get-site-info',
				'arguments' => array(),
			);
		case 'system.execute_denied':
			return array(
				'name'      => 'xnetvn/create-post',
				'arguments' => array(
					'title'   => $canary,
					'content' => $canary,
				),
			);
		case 'system.info_hidden':
			return array( 'name' => 'xnetvn/create-user' );
		case 'catalog.tools_list':
		case 'catalog.tools_list_other':
			return array();
		case 'catalog.discover':
			return array(
				'toolset'  => (string) ( $case['extra'] ?? 'system' ),
				'per_page' => 100,
			);
		case 'catalog.info':
			return array( 'name' => (string) ( $case['extra'] ?? 'xnetvn/get-site-info' ) );
		case 'content.list_post_types':
		case 'content.list_post_statuses':
			return array();
		case 'content.list_posts':
			return array(
				'per_page' => 5,
				'status'   => array( 'draft' ),
			);
		case 'content.get_post':
			return array( 'id' => $target );
		case 'content.list_posts_page2':
			return array(
				'per_page' => 1,
				'page'     => 2,
				'status'   => array( 'draft' ),
			);
		case 'content.list_posts_max':
			return array(
				'per_page' => 100,
				'status'   => array( 'draft' ),
				'fields'   => array( 'id', 'type', 'status', 'title', 'slug', 'link', 'date', 'modified', 'author', 'excerpt', 'content', 'featured_media', 'categories', 'tags', 'sticky', 'meta', 'date_gmt', 'modified_gmt', 'parent', 'menu_order', 'template', 'comment_status', 'ping_status', 'format' ),
			);
		case 'content.create_post':
			return array(
				'title'   => $canary . ' catalog-role-' . $campaign . '-' . $actor . ' post',
				'content' => $canary . ' content',
				'status'  => 'draft',
			);
		case 'content.update_post':
			return array(
				'id'    => $target,
				'title' => $canary . ' updated',
			);
		case 'content.trash_post':
			return array( 'id' => $target );
		case 'content.restore_post':
			return array(
				'id'     => $target,
				'status' => 'draft',
			);
		case 'content.delete_post':
			return array( 'id' => $target );
		case 'content.duplicate_post':
			return array(
				'id'     => $target,
				'status' => 'draft',
			);
		case 'content.bulk_update_posts':
			return array(
				'ids'     => array( $target, $extra ),
				'changes' => array( 'categories' => array( 5307 ) ),
			);
		case 'content.list_revisions':
			return array(
				'id'       => $target,
				'per_page' => 5,
			);
		case 'content.get_revision':
			return array(
				'id'          => $target,
				'revision_id' => $extra,
			);
		case 'content.restore_revision':
			return array(
				'id'          => $target,
				'revision_id' => $extra,
			);
		case 'content.set_featured_media':
			return array(
				'id'       => $target,
				'media_id' => $extra,
			);
		case 'content.stick_post':
			return array(
				'id'     => $target,
				'sticky' => true,
			);
		case 'content.concurrent_update':
			return array(
				'id'      => $target,
				'content' => $canary . ' concurrent',
			);
		case 'content.get_post_meta':
			return array(
				'id'   => $target,
				'keys' => array( 'catalog_role_note' ),
			);
		case 'content.update_post_meta':
			return array(
				'id'     => $target,
				'values' => array( 'catalog_role_note' => $canary ),
			);
		case 'content.search_content':
			return array(
				'search'  => 'CatalogRole ' . $campaign,
				'subtype' => 'post',
			);
		case 'media.list_media':
			return array( 'per_page' => 5 );
		case 'media.get_media':
			return array( 'id' => $target );
		case 'media.limits':
			return array();
		case 'media.update_media':
			return array(
				'id'    => $target,
				'title' => $canary . ' media',
			);
		case 'media.upload_data':
			return array(
				'filename'    => 'catalog-role-' . $campaign . '-' . $actor . '.png',
				'mime_type'   => 'image/png',
				'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=',
			);
		case 'media.upload_url':
			return array( 'url' => 'https://example.test/catalog-role-' . $campaign . '.png' );
		case 'media.delete_media':
			return array( 'id' => $target );
		case 'taxonomy.list_taxonomies':
			return array( 'post_type' => 'post' );
		case 'taxonomy.list_terms':
			return array(
				'taxonomy' => 'category',
				'per_page' => 5,
			);
		case 'taxonomy.get_term':
			return array(
				'taxonomy' => 'category',
				'id'       => $target,
			);
		case 'taxonomy.create_term':
			return array(
				'taxonomy' => 'category',
				'name'     => $canary . ' Term',
				'slug'     => 'catalog-role-' . strtolower( $campaign . '-' . $actor ) . '-term',
			);
		case 'taxonomy.update_term':
			return array(
				'taxonomy'    => 'category',
				'id'          => $target,
				'description' => $canary . ' description',
			);
		case 'taxonomy.assign_post_terms':
			return array(
				'post_id'  => $target,
				'taxonomy' => 'category',
				'term_ids' => array( 5307 ),
				'mode'     => 'replace',
			);
		case 'taxonomy.bulk_create_terms':
			return array(
				'taxonomy' => 'category',
				'items'    => array(
					array(
						'name' => $canary . ' Bulk A',
						'slug' => 'catalog-role-' . strtolower( $campaign . '-' . $actor ) . '-bulk-a',
					),
					array(
						'name' => $canary . ' Bulk B',
						'slug' => 'catalog-role-' . strtolower( $campaign . '-' . $actor ) . '-bulk-b',
					),
				),
			);
		case 'taxonomy.bulk_assign_post_terms':
			return array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( $target ),
				'term_ids' => array( 5308 ),
				'mode'     => 'append',
			);
		case 'taxonomy.delete_term':
			return array(
				'taxonomy' => 'category',
				'id'       => $target,
				'dry_run'  => true,
			);
		case 'comments.list_comments':
			return array(
				'post'     => array( $target ),
				'per_page' => 5,
			);
		case 'comments.get_comment':
			return array( 'id' => $target );
		case 'comments.counts':
			return array( 'post' => $target );
		case 'comments.create_comment':
			return array(
				'post'    => $target,
				'content' => $canary . ' catalog-role-' . $campaign . '-' . $actor . ' comment',
				'status'  => 'approved',
			);
		case 'comments.reply_comment':
			return array(
				'parent_id' => $target,
				'content'   => $canary . ' catalog-role-' . $campaign . '-' . $actor . ' reply',
				'status'    => 'approved',
			);
		case 'comments.update_comment':
			return array(
				'id'      => $target,
				'content' => $canary . ' catalog-role-' . $campaign . '-' . $actor . ' updated',
			);
		case 'comments.moderate_comment':
			return array(
				'id'     => $target,
				'action' => 'approve',
			);
		case 'comments.bulk_moderate':
			return array(
				'ids'    => array( $target, $extra ),
				'action' => 'approve',
			);
		case 'comments.delete_comment':
			return array( 'id' => $target );
		case 'comments.purge_comments':
			return array(
				'status' => 'spam',
				'limit'  => 1,
			);
		case 'users.list_users':
			return array( 'per_page' => 5 );
		case 'users.get_user':
		case 'users.get_caps':
			return array( 'id' => $user );
		case 'users.list_roles':
			return array();
		case 'users.my_profile':
			return array();
		case 'users.update_profile':
			return array( 'name' => $canary . ' profile' );
		case 'users.create_user':
			$suffix = 'super-admin' === $actor ? 'sa' : 'createdadmin';
			return array(
				'username' => 'catalogrole' . str_replace( '-', '', strtolower( $campaign ) ) . $suffix,
				'email'    => 'catalogrole' . $suffix . '-' . substr( hash( 'sha256', $campaign ), 0, 8 ) . '@example.test',
				'role'     => 'subscriber',
				'notify'   => 'none',
			);
		case 'users.update_user':
			return array(
				'id'          => $user,
				'name'        => $canary . ' User',
				'description' => $canary . ' description',
			);
		case 'users.set_role':
			return array(
				'id'   => $user,
				'role' => 'author',
			);
		case 'users.set_status':
			return array(
				'id'          => $user,
				'status'      => 'banned',
				'reason_code' => 'policy',
			);
		case 'users.reset':
		case 'users.sessions':
			return array( 'id' => $user );
		case 'invalid.unregistered_meta':
			return array(
				'title'   => $canary . ' invalid meta',
				'content' => $canary . ' invalid meta',
				'status'  => 'draft',
				'meta'    => array( 'seo08_unregistered' => 'x' ),
			);
		case 'invalid.unregistered_meta_update':
			return array(
				'id'     => $target,
				'values' => array( 'seo08_unregistered' => 'x' ),
			);
		default:
			return array();
	}
}

/**
 * Export the declarations for include-only consumers.
 */
return xnetvn_agent_hub_wp_catalog_role_declarations();

/**
 * Wrap one declared logical fixture identifier in an explicit marker.
 *
 * Only a value produced here is resolved through the fixture ledger. A bare
 * integer always passes through untouched, so a real WordPress identifier can
 * never be mistaken for a logical one.
 *
 * @param int $id Declared logical identifier.
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_catalog_role_logical( int $id ): array {
	return array( '$logical' => $id );
}

/**
 * Resolve logical markers through the owned fixture ledger.
 *
 * @param mixed          $value Payload fragment.
 * @param array<int,int> $ids   Ledger of logical to real identifiers.
 * @return mixed
 * @throws RuntimeException For a malformed or unresolved marker.
 */
function xnetvn_agent_hub_wp_catalog_role_resolve( mixed $value, array $ids ): mixed {
	if ( is_array( $value ) ) {
		if ( array_key_exists( '$logical', $value ) ) {
			$key = $value['$logical'];
			if ( ! is_int( $key ) || ! isset( $ids[ $key ] ) || ! is_int( $ids[ $key ] ) ) {
				throw new RuntimeException( 'Catalog role matrix unresolved id.' );
			}
			return $ids[ $key ];
		}
		$resolved = array();
		foreach ( $value as $index => $item ) {
			$resolved[ $index ] = xnetvn_agent_hub_wp_catalog_role_resolve( $item, $ids );
		}
		return $resolved;
	}
	return $value;
}

/**
 * Mark the declared logical identifiers of the three payloads that embed one.
 *
 * The factories must keep returning plain integers because the integration
 * test validates them against the real input schemas; only the harness wraps
 * the declared logical sites before they reach the ledger resolver.
 *
 * @param string              $payload Payload name.
 * @param array<string,mixed> $data    Payload data.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_mark_logical( string $payload, array $data ): array {
	$paths = array(
		'content.bulk_update_posts'       => array( array( 'changes', 'categories' ) ),
		'taxonomy.assign_post_terms'      => array( array( 'term_ids' ) ),
		'taxonomy.bulk_assign_post_terms' => array( array( 'term_ids' ) ),
	);
	if ( ! isset( $paths[ $payload ] ) ) {
		return $data;
	}
	foreach ( $paths[ $payload ] as $path ) {
		$node = &$data;
		foreach ( $path as $key ) {
			if ( ! isset( $node[ $key ] ) || ! is_array( $node[ $key ] ) ) {
				unset( $node );
				continue 2;
			}
			$node = &$node[ $key ];
		}
		foreach ( $node as $index => $value ) {
			if ( is_int( $value ) ) {
				$node[ $index ] = xnetvn_agent_hub_wp_catalog_role_logical( $value );
			}
		}
		unset( $node );
	}
	return $data;
}
