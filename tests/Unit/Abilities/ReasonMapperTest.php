<?php
/**
 * Safe upstream reason classification.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Unknown providers cannot introduce arbitrary errors or messages. */
final class ReasonMapperTest extends TestCase {

	/** Reviewed plugin reasons survive; arbitrary suffixes do not. */
	public function test_reviewed_reasons_only(): void {
		self::assertSame( 'conflict', ReasonMapper::reason( 'xnetvn_agent_hub_wp_conflict' ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'xnetvn_agent_hub_wp_PASSWORD_CANARY' ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'provider_secret_CANARY' ) );
	}

	/** Core permissions, object IDs, state and quota have distinct safe reasons. */
	public function test_core_reason_classification(): void {
		self::assertSame( 'forbidden', ReasonMapper::reason( 'rest_cannot_edit', 403 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_post_invalid_id', 404 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'rest_invalid_param', 400 ) );
		self::assertSame( 'invalid_state', ReasonMapper::reason( 'rest_already_trashed', 410 ) );
		self::assertSame( 'unsupported_operation', ReasonMapper::reason( 'rest_trash_not_supported', 501 ) );
		self::assertSame( 'quota_exceeded', ReasonMapper::reason( 'rest_upload_file_too_big', 400 ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'untrusted_plugin', 403 ) );
	}

	/** Comment controller codes map to reviewed comment reasons. */
	public function test_comment_reason_classification(): void {
		self::assertSame( 'duplicate_comment', ReasonMapper::reason( 'comment_duplicate', 409 ) );
		self::assertSame( 'comments_closed', ReasonMapper::reason( 'rest_comment_closed', 403 ) );
		self::assertSame( 'invalid_state', ReasonMapper::reason( 'rest_comment_draft_post', 403 ) );
		self::assertSame( 'invalid_state', ReasonMapper::reason( 'rest_comment_trash_post', 403 ) );
		self::assertSame( 'unsupported_operation', ReasonMapper::reason( 'rest_comment_not_supported_post_type', 403 ) );
		self::assertSame( 'payload_too_large', ReasonMapper::reason( 'comment_content_column_length', 400 ) );
		self::assertSame( 'payload_too_large', ReasonMapper::reason( 'comment_author_column_length', 400 ) );
		self::assertSame( 'rate_limited', ReasonMapper::reason( 'comment_flood', 429 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_comment_invalid_id', 404 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_comment_invalid_post_id', 403 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'rest_comment_content_invalid', 400 ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'rest_cannot_delete', 500 ) );
		self::assertSame( 'forbidden', ReasonMapper::reason( 'rest_cannot_delete', 403 ) );
	}

	/** Taxonomy controller codes map to reviewed taxonomy reasons. */
	public function test_taxonomy_reason_classification(): void {
		self::assertSame( 'duplicate_term', ReasonMapper::reason( 'term_exists', 400 ) );
		self::assertSame( 'duplicate_term', ReasonMapper::reason( 'duplicate_term_slug', 400 ) );
		self::assertSame( 'taxonomy_unavailable', ReasonMapper::reason( 'invalid_taxonomy', 404 ) );
		self::assertSame( 'taxonomy_unavailable', ReasonMapper::reason( 'rest_taxonomy_invalid', 404 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'rest_taxonomy_not_hierarchical', 400 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'empty_term_name', 400 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'invalid_term_name', 400 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'missing_parent', 400 ) );
		self::assertSame( 'invalid_state', ReasonMapper::reason( 'rest_invalid_stored_value', 500 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_term_invalid', 404 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_term_invalid', 400 ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'rest_cannot_delete', 500 ) );
		self::assertSame( 'forbidden', ReasonMapper::reason( 'rest_cannot_delete', 403 ) );
	}
}
