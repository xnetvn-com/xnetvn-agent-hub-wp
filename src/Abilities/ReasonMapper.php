<?php
/**
 * Allowlisted logical execution errors, independent of provider messages.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use WP_Error;

/** Never forwards upstream messages, status metadata or request values. */
final class ReasonMapper {

	/** Fixed public vocabulary. */
	private const MESSAGES = array(
		'invalid_params'        => 'The tool arguments are invalid.',
		'forbidden'             => 'You are not allowed to perform this operation.',
		'not_found'             => 'The requested object is unavailable.',
		'post_type_unavailable' => 'The post type is unavailable.',
		'invalid_state'         => 'The object is not in the required state.',
		'unsupported_operation' => 'This operation is not supported.',
		'conflict'              => 'The object has changed. Read it again before editing.',
		'partial_failure'       => 'The operation partially completed. Reconcile before retrying.',
		'restore_failed'        => 'The revision could not be restored.',
		'payload_too_large'     => 'The payload exceeds the permitted size.',
		'file_type_not_allowed' => 'The file type is not allowed.',
		'image_too_large'       => 'The image exceeds the processing budget.',
		'quota_exceeded'        => 'The upload quota is exceeded.',
		'remote_fetch_disabled' => 'Remote fetching is unavailable.',
		'remote_fetch_refused'  => 'The remote resource is not permitted.',
		'remote_fetch_failed'   => 'The remote resource could not be retrieved.',
		'rate_limited'          => 'The expensive operation budget is exhausted.',
		'duplicate_comment'     => 'An identical comment already exists.',
		'comments_closed'       => 'Comments are closed for this item.',
		'taxonomy_unavailable'  => 'The taxonomy is unavailable.',
		'duplicate_term'        => 'A term with this name or slug already exists.',
		'duplicate_user'        => 'A user with this login, email or slug already exists.',
		'protected_account'     => 'This account cannot be changed through this interface.',
		'role_unavailable'      => 'The role is unavailable.',
		'cooldown_active'       => 'A recent request for this account is still cooling down.',
		'internal_error'        => 'The outcome could not be confirmed. Reconcile before retrying any mutation.',
	);

	/**
	 * Classify a reviewed code, without trusting arbitrary status values.
	 *
	 * @param string $code Provider code.
	 * @param int    $status Core response status.
	 * @return string
	 */
	public static function reason( string $code, int $status = 0 ): string {
		$prefix = 'xnetvn_agent_hub_wp_';
		if ( str_starts_with( $code, $prefix ) ) {
			$reason = substr( $code, strlen( $prefix ) );
			return isset( self::MESSAGES[ $reason ] ) ? $reason : 'internal_error';
		}
		if ( in_array( $code, array( 'rest_upload_limited_space', 'rest_upload_file_too_big', 'rest_upload_user_quota_exceeded' ), true ) ) {
			return 'quota_exceeded';
		}
		if ( 'rest_trash_not_supported' === $code ) {
			return 'unsupported_operation';
		}
		if ( 'comment_duplicate' === $code ) {
			return 'duplicate_comment';
		}
		if ( 'comment_flood' === $code ) {
			return 'rate_limited';
		}
		if ( in_array( $code, array( 'comment_author_column_length', 'comment_author_email_column_length', 'comment_author_url_column_length', 'comment_content_column_length' ), true ) ) {
			return 'payload_too_large';
		}
		if ( 'rest_comment_closed' === $code ) {
			return 'comments_closed';
		}
		if ( in_array( $code, array( 'rest_comment_draft_post', 'rest_comment_trash_post' ), true ) ) {
			return 'invalid_state';
		}
		if ( 'rest_comment_not_supported_post_type' === $code ) {
			return 'unsupported_operation';
		}
		if ( in_array( $code, array( 'rest_comment_invalid_id', 'rest_comment_invalid_post_id', 'rest_comment_invalid_type' ), true ) ) {
			return 'not_found';
		}
		if ( 'rest_cannot_delete' === $code && $status >= 500 ) {
			return 'internal_error';
		}
		if ( in_array( $code, array( 'term_exists', 'duplicate_term_slug' ), true ) ) {
			return 'duplicate_term';
		}
		if ( in_array( $code, array( 'invalid_taxonomy', 'rest_taxonomy_invalid' ), true ) ) {
			return 'taxonomy_unavailable';
		}
		if ( 'rest_term_invalid' === $code ) {
			return 'not_found';
		}
		if ( 'rest_invalid_stored_value' === $code ) {
			return 'invalid_state';
		}
		if ( in_array( $code, array( 'rest_taxonomy_not_hierarchical', 'empty_term_name', 'invalid_term_name', 'missing_parent', 'rest_missing_callback_param' ), true ) ) {
			return 'invalid_params';
		}
		if ( in_array( $code, array( 'rest_comment_content_invalid', 'rest_comment_author_data_required', 'rest_comment_author_invalid', 'rest_invalid_comment_type' ), true ) ) {
			return 'invalid_params';
		}
		if ( in_array( $code, array( 'rest_comment_login_required', 'rest_comment_invalid_status', 'rest_comment_invalid_author', 'rest_comment_invalid_author_ip', 'rest_cannot_create_note' ), true ) ) {
			return 'forbidden';
		}
		if ( 'rest_already_trashed' === $code ) {
			return 'invalid_state';
		}
		if ( 'rest_user_invalid_id' === $code ) {
			return 'not_found';
		}
		if ( in_array( $code, array( 'rest_user_cannot_view', 'rest_user_cannot_delete', 'rest_cannot_create_user', 'rest_cannot_edit', 'rest_cannot_edit_roles', 'rest_forbidden_context', 'rest_forbidden_orderby', 'rest_forbidden_who', 'no_password_reset' ), true ) ) {
			return 'forbidden';
		}
		if ( 'rest_user_invalid_role' === $code ) {
			return 'role_unavailable';
		}
		if ( in_array( $code, array( 'rest_user_invalid_email', 'rest_user_invalid_slug', 'existing_user_login', 'existing_user_email' ), true ) ) {
			return 'duplicate_user';
		}
		if ( in_array( $code, array( 'rest_user_invalid_username', 'rest_user_invalid_argument', 'rest_user_invalid_password', 'rest_user_exists', 'empty_user_login', 'user_login_too_long', 'invalid_username', 'empty_user_nicename', 'user_nicename_too_long', 'user_url_too_long', 'invalid_email', 'empty_username' ), true ) ) {
			return 'invalid_params';
		}
		if ( 'invalidcombo' === $code ) {
			return 'not_found';
		}
		if ( in_array( $code, array( 'rest_user_create', 'retrieve_password_email_failure' ), true ) ) {
			return 'internal_error';
		}
		if ( 'rest_post_invalid_id' === $code || ( 404 === $status && str_starts_with( $code, 'rest_' ) ) ) {
			return 'not_found';
		}
		if ( str_starts_with( $code, 'rest_cannot_' ) || str_starts_with( $code, 'rest_forbidden' ) || 'rest_user_cannot_delete_post' === $code ) {
			return 'forbidden';
		}
		if ( str_starts_with( $code, 'rest_invalid_' ) || 'rest_post_invalid_page_number' === $code ) {
			return 'invalid_params';
		}
		return 'internal_error';
	}

	/**
	 * Create a fixed error; the message includes the machine-readable reason.
	 *
	 * @param string $reason Safe reason.
	 * @return WP_Error
	 */
	public static function error( string $reason ): WP_Error {
		if ( ! isset( self::MESSAGES[ $reason ] ) ) {
			$reason = 'internal_error';
		}
		return new WP_Error( 'xnetvn_agent_hub_wp_' . $reason, $reason . ': ' . self::MESSAGES[ $reason ] );
	}

	/**
	 * Discard arbitrary provider details, including data attached to safe codes.
	 *
	 * @param WP_Error $error Provider error.
	 * @param int      $status Core response status.
	 * @return WP_Error
	 */
	public static function sanitize( WP_Error $error, int $status = 0 ): WP_Error {
		if ( $error instanceof PartialFailure ) {
			return new PartialFailure( $error->object_id, $error->created ); }
		return self::error( self::reason( (string) $error->get_error_code(), $status ) );
	}
}
