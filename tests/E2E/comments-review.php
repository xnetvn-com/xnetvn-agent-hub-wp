<?php
/**
 * Retained comment lifecycle review through real HTTPS MCP calls.
 *
 * The matrix never deletes a retained review comment. Only explicitly marked
 * disposable comments move through delete-comment or purge-comments.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E;

use RuntimeException;
use Throwable;
use XNetVN\AgentHubWP\Support\Json;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTPS test client; no WordPress bootstrap or logged credentials.

/**
 * Run the comment review matrix for one lab site.
 */
final class CommentsReviewRun {

	/** Exact reviewed tool suffixes. */
	private const TOOL_NAMES = array(
		'list-comments',
		'get-comment',
		'get-comment-counts',
		'create-comment',
		'reply-comment',
		'update-comment',
		'moderate-comment',
		'bulk-moderate-comments',
		'delete-comment',
		'purge-comments',
	);

	/** Safe error vocabulary used for assertions and artifacts. */
	private const SAFE_CODES = array(
		'comments_closed',
		'duplicate_comment',
		'forbidden',
		'invalid_params',
		'invalid_state',
		'not_found',
		'unsupported_operation',
		'internal_error',
		'invalid_output',
		'partial_failure',
		'payload_too_large',
		'unknown_tool',
		'rate_limited',
		'unauthenticated',
		'unavailable',
	);

	/**
	 * Lab site.
	 *
	 * @var string
	 */
	private string $site;
	/**
	 * Run identifier.
	 *
	 * @var string
	 */
	private string $run_id;
	/**
	 * Artifact directory.
	 *
	 * @var string
	 */
	private string $run_dir;
	/**
	 * Retention mode.
	 *
	 * @var string
	 */
	private string $retention;
	/**
	 * Review marker root.
	 *
	 * @var string
	 */
	private string $marker;
	/**
	 * Public fixture summary.
	 *
	 * @var array<string,mixed>
	 */
	private array $setup;
	/**
	 * Private key state.
	 *
	 * @var array<string,mixed>
	 */
	private array $state;
	/**
	 * HTTPS client.
	 *
	 * @var McpClient
	 */
	private McpClient $client;
	/**
	 * Reviewed cases.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $cases = array();
	/**
	 * Retained review comments by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $review = array();
	/**
	 * Disposable comments by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $ephemeral = array();
	/**
	 * Intentionally deleted disposable comments by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $deleted = array();
	/**
	 * Count snapshots.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $counts = array();
	/**
	 * Purge evidence.
	 *
	 * @var array<string,mixed>
	 */
	private array $purge = array();
	/**
	 * Non-fatal notes.
	 *
	 * @var array<int,string>
	 */
	private array $notes = array();
	/**
	 * Whether every review comment survived the run.
	 *
	 * @var bool
	 */
	private bool $retained = false;

	/**
	 * Load fixture state and bind one site.
	 *
	 * @throws RuntimeException For missing or unsafe input.
	 */
	public function __construct() {
		$this->site      = (string) getenv( 'XNETVN_MATRIX_SITE' );
		$this->run_id    = (string) getenv( 'COMMENTS_REVIEW_RUN_ID' );
		$this->run_dir   = (string) getenv( 'COMMENTS_REVIEW_RUN_DIR' );
		$this->retention = (string) getenv( 'COMMENTS_REVIEW_RETENTION' );
		if ( ! in_array( $this->site, array( 'wp69', 'wp71' ), true ) || ! in_array( $this->retention, array( 'all', 'purge-safe' ), true ) || '' === $this->run_dir ) {
			throw new RuntimeException( 'Comments review matrix input is invalid.' );
		}
		$state_path = (string) getenv( 'COMMENTS_REVIEW_STATE' );
		$setup_path = $this->run_dir . '/setup.json';
		if ( ! is_file( $state_path ) || ! is_file( $setup_path ) ) {
			throw new RuntimeException( 'Comments review fixture output is missing.' );
		}
		$this->state = Json::array( (string) file_get_contents( $state_path ) );
		$this->setup = Json::array( (string) file_get_contents( $setup_path ) );
		if ( ( $this->state['site'] ?? '' ) !== $this->site || ( $this->setup['site'] ?? '' ) !== $this->site ) {
			throw new RuntimeException( 'Comments review fixture binding mismatch.' );
		}
		$this->marker = (string) ( $this->setup['marker'] ?? '' );
		$host         = getenv( 'wp71' === $this->site ? 'RTL_ALIAS_WP71' : 'RTL_ALIAS_WP69' );
		if ( false === $host || '' === $host ) {
			throw new RuntimeException( 'Comments review host alias is unavailable.' );
		}
		$this->client = new McpClient( 'https://' . $host, array( $host ), true );
		foreach ( (array) ( $this->setup['review_ids'] ?? array() ) as $id ) {
			$this->review[ (int) $id ] = array(
				'id'               => (int) $id,
				'post'             => 0,
				'status'           => (string) ( $this->setup['review_statuses'][ $id ] ?? 'hold' ),
				'created_this_run' => false,
			);
		}
		$pingback = (int) ( $this->setup['pingback'] ?? 0 );
		$sentinel = (int) ( $this->setup['sentinel'] ?? 0 );
		if ( $pingback > 0 ) {
			$this->ephemeral[ $pingback ] = array(
				'id'      => $pingback,
				'kind'    => 'pingback',
				'post'    => (int) $this->setup['posts']['review'],
				'status'  => 'approved',
				'deleted' => false,
			);
		}
		if ( $sentinel > 0 ) {
			$this->ephemeral[ $sentinel ] = array(
				'id'      => $sentinel,
				'kind'    => 'sentinel',
				'post'    => (int) $this->setup['posts']['review'],
				'status'  => 'spam',
				'deleted' => false,
			);
		}
	}

	/**
	 * Execute every phase and write sanitized artifacts.
	 *
	 * @return int 0 pass, 1 failed, 2 blocked.
	 */
	public function run(): int {
		try {
			$this->refresh_prior_review();
			$this->counts['before'] = $this->counts_snapshot();
			$this->phase_visibility();
			$this->phase_create_read();
			$this->phase_update();
			$this->phase_reply();
			$this->phase_moderate();
			$this->phase_bulk();
			$this->phase_permissions();
			$this->phase_delete();
			if ( 'purge-safe' === $this->retention ) {
				$this->phase_restore();
			}
			$this->phase_purge();
			$this->phase_retention();
			$this->counts['final'] = $this->counts_snapshot();
			$this->write_artifacts( null, null, true, 'pass' );
			return 0;
		} catch ( CommentsReviewFailure $failure ) {
			$this->cases[ $failure->case_id ] = array(
				'ok'     => false,
				'reason' => $failure->reason,
			);
			$this->counts['final']            = $this->safe_counts();
			$this->write_artifacts( $failure->case_id, $failure->reason, $failure->blocked, $failure->blocked ? 'blocked' : 'failed' );
			return $failure->blocked ? 2 : 1;
		} catch ( Throwable ) {
			$this->cases['transport'] = array(
				'ok'     => false,
				'reason' => 'unexpected_failure',
			);
			$this->counts['final']    = $this->safe_counts();
			$this->write_artifacts( 'transport', 'unexpected_failure', false, 'failed' );
			return 1;
		}
	}

	/**
	 * CMT-01: visibility, tier, toolset and strict schemas.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_visibility(): void {
		$all       = self::TOOL_NAMES;
		$moderator = array_values( array_diff( $all, array( 'delete-comment', 'purge-comments' ) ) );
		$read      = array( 'list-comments', 'get-comment', 'get-comment-counts' );
		$this->assert_case( 'CMT-01-editor-visible', $this->same_set( $all, $this->tool_names( 'editor' ) ), 'editor_tools_mismatch' );
		$this->assert_case( 'CMT-01-moderator-visible', $this->same_set( $moderator, $this->tool_names( 'moderator' ) ), 'moderator_tools_mismatch' );
		$this->assert_case( 'CMT-01-read-visible', $this->same_set( $read, $this->tool_names( 'read' ) ), 'read_tools_mismatch' );
		$this->assert_case( 'CMT-01-author-hidden', array() === $this->tool_names( 'author' ), 'author_tools_visible' );
		$this->assert_case( 'CMT-01-content-hidden', array() === $this->tool_names( 'content' ), 'content_tools_visible' );
		$defs = $this->tool_defs( 'editor' );
		foreach ( array( 'list-comments', 'get-comment', 'get-comment-counts', 'create-comment', 'reply-comment', 'update-comment', 'moderate-comment', 'bulk-moderate-comments', 'delete-comment', 'purge-comments' ) as $name ) {
			$strict = $defs[ $name ]['inputSchema']['additionalProperties'] ?? true;
			$this->assert_case( 'CMT-01-schema-' . $name, false === $strict, 'schema_not_strict' );
		}
		$purge = $defs['purge-comments']['inputSchema']['properties'] ?? array();
		$this->assert_case( 'CMT-01-purge-enum', array( 'spam', 'trash' ) === ( $purge['status']['enum'] ?? array() ), 'purge_status_enum_mismatch' );
		$this->assert_case( 'CMT-01-purge-limit', 1 === ( $purge['limit']['minimum'] ?? 0 ) && 100 === ( $purge['limit']['maximum'] ?? 0 ), 'purge_limit_bounds_mismatch' );
		$this->assert_case( 'CMT-01-annotations', true === ( $defs['delete-comment']['annotations']['destructiveHint'] ?? false ) && true === ( $defs['purge-comments']['annotations']['destructiveHint'] ?? false ) && true === ( $defs['list-comments']['annotations']['readOnlyHint'] ?? false ) && false === ( $defs['create-comment']['annotations']['idempotentHint'] ?? true ), 'annotation_mismatch' );
	}

	/**
	 * CMT-02: create, read, list, counts, bounds and the type allowlist.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_create_read(): void {
		$post  = (int) $this->setup['posts']['review'];
		$token = 'cmt-search-' . $this->run_id;
		$r1    = $this->invoke(
			'CMT-02-create-hold',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'create-hold', 'First retained comment ' . $token ),
				'status'  => 'hold',
			)
		);
		$this->assert_case( 'CMT-02-create-hold', 'hold' === ( $r1['status'] ?? '' ) && (int) ( $r1['post'] ?? 0 ) === $post && 'comment' === ( $r1['type'] ?? '' ), 'create_hold_mismatch' );
		$this->review_add( (int) $r1['id'], $post, 'hold', 'CMT-02-create-hold' );
		$r2 = $this->invoke(
			'CMT-02-create-default',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'create-default', 'Default hold comment' ),
			)
		);
		$this->assert_case( 'CMT-02-create-default', 'hold' === ( $r2['status'] ?? '' ), 'default_not_hold' );
		$this->review_add( (int) $r2['id'], $post, 'hold', 'CMT-02-create-default' );
		$r3 = $this->invoke(
			'CMT-02-create-approved',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'create-approved', 'Approved comment' ),
				'status'  => 'approved',
			)
		);
		$this->assert_case( 'CMT-02-create-approved', 'approved' === ( $r3['status'] ?? '' ), 'approved_not_honored' );
		$this->review_add( (int) $r3['id'], $post, 'approved', 'CMT-02-create-approved' );
		$r4 = $this->invoke(
			'CMT-02-create-kses',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'create-kses', '<strong>Safe</strong><script>alert(1)</script><a href="https://example.test" onclick="x">Link</a>' ),
				'status'  => 'hold',
			)
		);
		$this->assert_case( 'CMT-02-create-kses', str_contains( (string) $r4['content'], '<strong>Safe</strong>' ) && ! str_contains( (string) $r4['content'], '<script' ) && ! str_contains( (string) $r4['content'], 'onclick' ), 'create_kses_mismatch' );
		$this->review_add( (int) $r4['id'], $post, 'hold', 'CMT-02-create-kses' );
		$get        = $this->invoke( 'CMT-02-get', 'editor', 'get-comment', array( 'id' => (int) $r1['id'] ) );
		$get_id     = (int) ( $get['id'] ?? 0 );
		$get_status = (string) ( $get['status'] ?? '' );
		$get_post   = (int) ( $get['post'] ?? 0 );
		$this->assert_case( 'CMT-02-get', (int) $r1['id'] === $get_id && 'hold' === $get_status && $get_post === $post && ! array_key_exists( 'author_email', $get ), 'get_mismatch' );
		$this->assert_list_contains(
			'CMT-02-list-hold',
			array(
				'post'     => $post,
				'status'   => 'hold',
				'per_page' => 100,
			),
			array( (int) $r1['id'], (int) $r2['id'], (int) $r4['id'] )
		);
		$this->assert_list_contains(
			'CMT-02-list-approved',
			array(
				'post'     => $post,
				'status'   => 'approved',
				'per_page' => 100,
			),
			array( (int) $r3['id'] )
		);
		$this->assert_list_contains(
			'CMT-02-list-all',
			array(
				'post'     => $post,
				'status'   => 'all',
				'per_page' => 100,
			),
			array( (int) $r1['id'], (int) $r2['id'], (int) $r3['id'], (int) $r4['id'] )
		);
		$page = $this->invoke(
			'CMT-02-list-pagination',
			'editor',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assert_case( 'CMT-02-list-pagination', count( (array) ( $page['items'] ?? array() ) ) <= 2 && (int) ( $page['page'] ?? 0 ) === 2 && (int) ( $page['total_pages'] ?? 0 ) >= 2, 'pagination_mismatch' );
		$this->assert_list_contains(
			'CMT-02-list-search',
			array(
				'post'     => $post,
				'status'   => 'all',
				'search'   => $token,
				'per_page' => 100,
			),
			array( (int) $r1['id'] )
		);
		$fields = $this->invoke(
			'CMT-02-list-fields',
			'editor',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'fields'   => array( 'id', 'status' ),
				'per_page' => 1,
			)
		);
		$item   = (array) ( $fields['items'][0] ?? array() );
		$this->assert_case( 'CMT-02-list-fields', array() === array_diff( array_keys( $item ), array( 'id', 'status' ) ), 'fields_not_narrowed' );
		$short      = $this->invoke(
			'CMT-02-list-maxchars',
			'editor',
			'list-comments',
			array(
				'post'              => $post,
				'status'            => 'all',
				'content_max_chars' => 10,
				'per_page'          => 1,
			)
		);
		$short_item = (array) ( $short['items'][0] ?? array() );
		$this->assert_case( 'CMT-02-list-maxchars', true === ( $short_item['content_truncated'] ?? false ) && strlen( (string) ( $short_item['content'] ?? '' ) ) <= 10, 'maxchars_mismatch' );
		$ordered = $this->invoke(
			'CMT-02-list-order',
			'editor',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'orderby'  => 'id',
				'order'    => 'desc',
				'per_page' => 100,
			)
		);
		$ids     = array_map( 'intval', array_column( (array) ( $ordered['items'] ?? array() ), 'id' ) );
		$this->assert_case( 'CMT-02-list-order', ! empty( $ids ) && $ids[0] >= $ids[ count( $ids ) - 1 ], 'order_mismatch' );
		$after = $this->invoke(
			'CMT-02-list-after',
			'editor',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'after'    => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 ),
				'per_page' => 100,
			)
		);
		$this->assert_case( 'CMT-02-list-after', (int) ( $after['total'] ?? 0 ) >= 4, 'after_missing' );
		$before = $this->invoke(
			'CMT-02-list-before',
			'editor',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'before'   => '2000-01-01T00:00:00Z',
				'per_page' => 100,
			)
		);
		$this->assert_case( 'CMT-02-list-before', 0 === (int) ( $before['total'] ?? -1 ), 'before_not_empty' );
		$this->expect_error( 'CMT-02-invalid-status', 'editor', 'list-comments', array( 'status' => 'pending' ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-type', 'editor', 'list-comments', array( 'type' => 'note' ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-page', 'editor', 'list-comments', array( 'page' => 0 ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-per-page', 'editor', 'list-comments', array( 'per_page' => 101 ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-search', 'editor', 'list-comments', array( 'search' => str_repeat( 's', 201 ) ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-posts', 'editor', 'list-comments', array( 'post' => range( 1, 21 ) ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-fields', 'editor', 'list-comments', array( 'fields' => array( 'ip' ) ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error( 'CMT-02-invalid-date', 'editor', 'list-comments', array( 'after' => 'not-a-date' ), array( 'invalid_params', 'internal_error' ) );
		$closed = (int) $this->setup['posts']['closed'];
		$this->expect_error(
			'CMT-02-closed',
			'editor',
			'create-comment',
			array(
				'post'    => $closed,
				'content' => 'Closed post',
			),
			array( 'comments_closed' )
		);
		$this->expect_error(
			'CMT-02-blank',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => '   ',
			),
			array( 'invalid_params' )
		);
		$this->expect_error(
			'CMT-02-oversize',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => str_repeat( 'X', 65526 ),
			),
			array( 'payload_too_large', 'internal_error' )
		);
		$this->expect_error(
			'CMT-02-unknown-field',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => 'Unknown field',
				'author'  => 'x',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error( 'CMT-02-get-unknown', 'editor', 'get-comment', array( 'id' => 999999999 ), array( 'not_found' ) );
		$this->assert_list_contains(
			'CMT-02-author-filter',
			array(
				'post'     => $post,
				'status'   => 'all',
				'author'   => array( (int) $this->setup['users']['editor'] ),
				'per_page' => 100,
			),
			array( (int) $r1['id'] )
		);
		$this->assert_list_contains(
			'CMT-02-parent-filter',
			array(
				'post'     => $post,
				'status'   => 'all',
				'parent'   => array( 0 ),
				'per_page' => 100,
			),
			array( (int) $r1['id'] )
		);
		$boundary = $this->ephemeral_content( 'oversize-ok', str_repeat( 'B', max( 0, 65525 - strlen( $this->ephemeral_marker( 'oversize-ok' ) ) ) ) );
		$large    = $this->invoke(
			'CMT-02-boundary',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $boundary,
				'status'  => 'hold',
			)
		);
		$this->assert_case( 'CMT-02-boundary', 65525 === strlen( $boundary ) && 'hold' === ( $large['status'] ?? '' ), 'boundary_mismatch' );
		$this->ephemeral_add( (int) $large['id'], 'boundary', $post, 'hold' );
		$pingback = (int) ( $this->setup['pingback'] ?? 0 );
		$this->assert_list_missing(
			'CMT-02-type-default',
			array(
				'post'     => $post,
				'status'   => 'approved',
				'per_page' => 100,
			),
			array( $pingback )
		);
		$this->assert_list_contains(
			'CMT-02-type-pingback',
			array(
				'post'     => $post,
				'status'   => 'approved',
				'type'     => 'pingback',
				'per_page' => 100,
			),
			array( $pingback )
		);
		$pingget = $this->invoke( 'CMT-02-pingback-get', 'editor', 'get-comment', array( 'id' => $pingback ) );
		$this->assert_case( 'CMT-02-pingback-get', 'pingback' === ( $pingget['type'] ?? '' ), 'pingback_type_mismatch' );
		$moderate = $this->invoke(
			'CMT-02-pingback-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $pingback,
				'action' => 'spam',
			)
		);
		$this->assert_case( 'CMT-02-pingback-spam', 'spam' === ( $moderate['status'] ?? '' ), 'pingback_spam_mismatch' );
		$delete = $this->invoke( 'CMT-02-pingback-delete', 'editor', 'delete-comment', array( 'id' => $pingback ) );
		$this->assert_case( 'CMT-02-pingback-delete', true === ( $delete['deleted'] ?? false ), 'pingback_delete_mismatch' );
		$this->expect_error( 'CMT-02-pingback-gone', 'editor', 'get-comment', array( 'id' => $pingback ), array( 'not_found' ) );
		$this->mark_deleted( $pingback, 'CMT-02-pingback-delete' );
		$this->counts['after_pingback'] = $this->counts_snapshot();
		$this->assert_delta( 'CMT-02-counts', $this->counts['after_pingback'], $this->counts['before'], 'hold', 4 );
	}

	/**
	 * CMT-03: update content, KSES, author fields and immutability.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_update(): void {
		$r1      = $this->first_review_id( 'CMT-02-create-hold' );
		$r2      = $this->first_review_id( 'CMT-02-create-default' );
		$before  = $this->invoke( 'CMT-03-immutable-before', 'editor', 'get-comment', array( 'id' => $r1 ) );
		$updated = $this->invoke(
			'CMT-03-update-content',
			'editor',
			'update-comment',
			array(
				'id'      => $r1,
				'content' => $this->review_content( 'update', '<strong>Safe</strong><script>alert(1)</script><a href="https://example.test" onclick="x">Link</a>' ),
			)
		);
		$this->assert_case( 'CMT-03-update-content', (int) ( $updated['id'] ?? 0 ) === $r1 && 'hold' === ( $updated['status'] ?? '' ), 'update_response_mismatch' );
		$raw = $this->invoke(
			'CMT-03-update-readback',
			'editor',
			'get-comment',
			array(
				'id'             => $r1,
				'content_format' => 'raw',
			)
		);
		$this->assert_case( 'CMT-03-update-readback', str_contains( (string) $raw['content'], '<strong>Safe</strong>' ) && ! str_contains( (string) $raw['content'], '<script' ) && ! str_contains( (string) $raw['content'], 'onclick' ) && str_contains( (string) $raw['content'], 'Link' ), 'update_kses_mismatch' );
		$this->assert_case( 'CMT-03-immutable', (int) ( $before['post'] ?? 0 ) === (int) ( $raw['post'] ?? 0 ) && (int) ( $before['parent'] ?? 0 ) === (int) ( $raw['parent'] ?? 0 ) && (string) ( $before['status'] ?? '' ) === (string) ( $raw['status'] ?? '' ) && (int) ( $before['author'] ?? 0 ) === (int) ( $raw['author'] ?? 0 ), 'immutable_field_changed' );
		$name    = str_repeat( 'A', 245 );
		$name_ok = $this->invoke(
			'CMT-03-name-245',
			'editor',
			'update-comment',
			array(
				'id'          => $r2,
				'author_name' => $name,
			)
		);
		$this->assert_case( 'CMT-03-name-245', 245 === strlen( (string) ( $name_ok['author_name'] ?? '' ) ), 'name_245_mismatch' );
		$this->expect_error(
			'CMT-03-name-246',
			'editor',
			'update-comment',
			array(
				'id'          => $r2,
				'author_name' => str_repeat( 'A', 246 ),
			),
			array( 'payload_too_large', 'internal_error' )
		);
		$this->expect_error(
			'CMT-03-name-blank',
			'editor',
			'update-comment',
			array(
				'id'          => $r2,
				'author_name' => '   ',
			),
			array( 'invalid_params' )
		);
		$url_prefix = 'https://example.test/';
		$url        = $url_prefix . str_repeat( 'u', 200 - strlen( $url_prefix ) );
		$url_ok     = $this->invoke(
			'CMT-03-url-200',
			'editor',
			'update-comment',
			array(
				'id'         => $r2,
				'author_url' => $url,
			)
		);
		$this->assert_case( 'CMT-03-url-200', 200 === strlen( (string) ( $url_ok['author_url'] ?? '' ) ) && str_starts_with( (string) ( $url_ok['author_url'] ?? '' ), $url_prefix ), 'url_200_mismatch' );
		$this->expect_error(
			'CMT-03-url-201',
			'editor',
			'update-comment',
			array(
				'id'         => $r2,
				'author_url' => $url . 'x',
			),
			array( 'payload_too_large', 'internal_error' )
		);
		$this->expect_error(
			'CMT-03-url-scheme',
			'editor',
			'update-comment',
			array(
				'id'         => $r2,
				'author_url' => 'ftp://example.test',
			),
			array( 'invalid_params' )
		);
		$empty = $this->invoke(
			'CMT-03-url-empty',
			'editor',
			'update-comment',
			array(
				'id'         => $r2,
				'author_url' => '',
			)
		);
		$this->assert_case( 'CMT-03-url-empty', '' === (string) ( $empty['author_url'] ?? 'x' ), 'url_empty_mismatch' );
		$this->expect_error( 'CMT-03-empty', 'editor', 'update-comment', array( 'id' => $r2 ), array( 'invalid_params', 'internal_error' ) );
		$this->expect_error(
			'CMT-03-forbidden-field',
			'editor',
			'update-comment',
			array(
				'id'     => $r2,
				'status' => 'approved',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-03-unknown',
			'editor',
			'update-comment',
			array(
				'id'      => 999999999,
				'content' => 'x',
			),
			array( 'not_found' )
		);
		$this->expect_error(
			'CMT-03-forbidden-owner',
			'moderator',
			'update-comment',
			array(
				'id'      => $r1,
				'content' => 'x',
			),
			array( 'forbidden' )
		);
	}

	/**
	 * CMT-04: reply binding and approve_parent.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_reply(): void {
		$r3   = $this->first_review_id( 'CMT-02-create-approved' );
		$post = (int) $this->setup['posts']['review'];
		$rp1  = $this->invoke(
			'CMT-04-reply',
			'editor',
			'reply-comment',
			array(
				'parent_id' => $r3,
				'content'   => $this->review_content( 'reply-1', 'Retained reply' ),
				'status'    => 'hold',
			)
		);
		$this->assert_case( 'CMT-04-reply', 'hold' === ( $rp1['status'] ?? '' ) && false === ( $rp1['parent_approved'] ?? true ) && (int) ( $rp1['parent'] ?? 0 ) === $r3 && (int) ( $rp1['post'] ?? 0 ) === $post, 'reply_mismatch' );
		$this->review_add( (int) $rp1['id'], $post, 'hold', 'CMT-04-reply' );
		$approve = $this->invoke(
			'CMT-04-approve-reply',
			'editor',
			'moderate-comment',
			array(
				'id'     => (int) $rp1['id'],
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-04-approve-reply', 'approved' === ( $approve['status'] ?? '' ), 'reply_approve_mismatch' );
		$this->review_status( (int) $rp1['id'], 'approved' );
		$r5 = $this->invoke(
			'CMT-04-hold-parent',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'hold-parent', 'Held parent' ),
				'status'  => 'hold',
			)
		);
		$this->review_add( (int) $r5['id'], $post, 'hold', 'CMT-04-hold-parent' );
		$this->expect_error(
			'CMT-04-hold-denied',
			'editor',
			'reply-comment',
			array(
				'parent_id' => (int) $r5['id'],
				'content'   => 'No approval',
			),
			array( 'invalid_state' )
		);
		$rp2 = $this->invoke(
			'CMT-04-approve-parent',
			'editor',
			'reply-comment',
			array(
				'parent_id'      => (int) $r5['id'],
				'content'        => $this->review_content( 'reply-2', 'Approved parent reply' ),
				'status'         => 'hold',
				'approve_parent' => true,
			)
		);
		$this->assert_case( 'CMT-04-approve-parent', true === ( $rp2['parent_approved'] ?? false ) && 'hold' === ( $rp2['status'] ?? '' ), 'approve_parent_mismatch' );
		$this->review_add( (int) $rp2['id'], $post, 'hold', 'CMT-04-approve-parent' );
		$parent = $this->invoke( 'CMT-04-parent-readback', 'editor', 'get-comment', array( 'id' => (int) $r5['id'] ) );
		$this->assert_case( 'CMT-04-parent-readback', 'approved' === ( $parent['status'] ?? '' ), 'parent_not_approved' );
		$this->review_status( (int) $r5['id'], 'approved' );
		$approve2 = $this->invoke(
			'CMT-04-approve-reply-2',
			'editor',
			'moderate-comment',
			array(
				'id'     => (int) $rp2['id'],
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-04-approve-reply-2', 'approved' === ( $approve2['status'] ?? '' ), 'reply_2_approve_mismatch' );
		$this->review_status( (int) $rp2['id'], 'approved' );
		$r6 = $this->invoke(
			'CMT-04-spam-parent',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'spam-parent', 'Spam parent' ),
				'status'  => 'approved',
			)
		);
		$this->review_add( (int) $r6['id'], $post, 'approved', 'CMT-04-spam-parent' );
		$this->invoke(
			'CMT-04-spam-parent-set',
			'editor',
			'moderate-comment',
			array(
				'id'     => (int) $r6['id'],
				'action' => 'spam',
			)
		);
		$this->review_status( (int) $r6['id'], 'spam' );
		$this->expect_error(
			'CMT-04-spam-denied',
			'editor',
			'reply-comment',
			array(
				'parent_id' => (int) $r6['id'],
				'content'   => 'Spam reply',
			),
			array( 'invalid_state' )
		);
		$r7 = $this->invoke(
			'CMT-04-trash-parent',
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'trash-parent', 'Trash parent' ),
				'status'  => 'approved',
			)
		);
		$this->review_add( (int) $r7['id'], $post, 'approved', 'CMT-04-trash-parent' );
		$this->invoke(
			'CMT-04-trash-parent-set',
			'editor',
			'moderate-comment',
			array(
				'id'     => (int) $r7['id'],
				'action' => 'trash',
			)
		);
		$this->review_status( (int) $r7['id'], 'trash' );
		$this->expect_error(
			'CMT-04-trash-denied',
			'editor',
			'reply-comment',
			array(
				'parent_id' => (int) $r7['id'],
				'content'   => 'Trash reply',
			),
			array( 'invalid_state' )
		);
		$this->expect_error(
			'CMT-04-unknown-parent',
			'editor',
			'reply-comment',
			array(
				'parent_id' => 999999999,
				'content'   => 'Missing',
			),
			array( 'not_found' )
		);
		$this->expect_error(
			'CMT-04-forbidden-parent',
			'moderator',
			'reply-comment',
			array(
				'parent_id' => $r3,
				'content'   => 'Other owner',
			),
			array( 'forbidden' )
		);
		$this->expect_error(
			'CMT-04-forbidden-field',
			'editor',
			'reply-comment',
			array(
				'parent_id' => $r3,
				'content'   => 'Post field',
				'post'      => $post,
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-04-blank',
			'editor',
			'reply-comment',
			array(
				'parent_id' => $r3,
				'content'   => '   ',
			),
			array( 'invalid_params' )
		);
		$this->assert_list_contains(
			'CMT-04-parent-list',
			array(
				'post'     => $post,
				'status'   => 'all',
				'parent'   => array( $r3 ),
				'per_page' => 100,
			),
			array( (int) $rp1['id'] )
		);
	}

	/**
	 * CMT-05: every moderation transition and its refusal.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_moderate(): void {
		$post = (int) $this->setup['posts']['review'];
		$base = $this->counts_snapshot();
		$r8   = $this->create_review( 'CMT-05-r8', $post, 'Transition hold', 'approved' );
		$noop = $this->invoke(
			'CMT-05-approve-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-05-approve-noop', false === ( $noop['changed'] ?? true ) && 'approved' === ( $noop['status'] ?? '' ), 'approve_noop_mismatch' );
		$held = $this->invoke(
			'CMT-05-hold',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'hold',
			)
		);
		$this->assert_case( 'CMT-05-hold', true === ( $held['changed'] ?? false ) && 'hold' === ( $held['status'] ?? '' ) && 'approved' === ( $held['previous_status'] ?? '' ), 'hold_mismatch' );
		$this->review_status( $r8, 'hold' );
		$held2 = $this->invoke(
			'CMT-05-hold-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'hold',
			)
		);
		$this->assert_case( 'CMT-05-hold-noop', false === ( $held2['changed'] ?? true ), 'hold_noop_mismatch' );
		$approved = $this->invoke(
			'CMT-05-approve',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-05-approve', true === ( $approved['changed'] ?? false ) && 'approved' === ( $approved['status'] ?? '' ), 'approve_mismatch' );
		$this->review_status( $r8, 'approved' );
		$r9   = $this->create_review( 'CMT-05-r9', $post, 'Transition spam', 'approved' );
		$spam = $this->invoke(
			'CMT-05-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r9,
				'action' => 'spam',
			)
		);
		$this->assert_case( 'CMT-05-spam', true === ( $spam['changed'] ?? false ) && 'spam' === ( $spam['status'] ?? '' ) && 'approved' === ( $spam['previous_status'] ?? '' ), 'spam_mismatch' );
		$this->review_status( $r9, 'spam' );
		$spam2 = $this->invoke(
			'CMT-05-spam-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r9,
				'action' => 'spam',
			)
		);
		$this->assert_case( 'CMT-05-spam-noop', false === ( $spam2['changed'] ?? true ), 'spam_noop_mismatch' );
		$unspam = $this->invoke(
			'CMT-05-unspam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r9,
				'action' => 'unspam',
			)
		);
		$this->assert_case( 'CMT-05-unspam', 'approved' === ( $unspam['status'] ?? '' ), 'unspam_mismatch' );
		$this->review_status( $r9, 'approved' );
		$this->invoke(
			'CMT-05-respam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r9,
				'action' => 'spam',
			)
		);
		$this->review_status( $r9, 'spam' );
		$r10   = $this->create_review( 'CMT-05-r10', $post, 'Transition trash', 'approved' );
		$trash = $this->invoke(
			'CMT-05-trash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r10,
				'action' => 'trash',
			)
		);
		$this->assert_case( 'CMT-05-trash', true === ( $trash['changed'] ?? false ) && 'trash' === ( $trash['status'] ?? '' ) && 'approved' === ( $trash['previous_status'] ?? '' ), 'trash_mismatch' );
		$this->review_status( $r10, 'trash' );
		$trash2 = $this->invoke(
			'CMT-05-trash-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r10,
				'action' => 'trash',
			)
		);
		$this->assert_case( 'CMT-05-trash-noop', false === ( $trash2['changed'] ?? true ), 'trash_noop_mismatch' );
		$untrash = $this->invoke(
			'CMT-05-untrash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r10,
				'action' => 'untrash',
			)
		);
		$this->assert_case( 'CMT-05-untrash', 'approved' === ( $untrash['status'] ?? '' ), 'untrash_mismatch' );
		$this->review_status( $r10, 'approved' );
		$this->invoke(
			'CMT-05-retrash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r10,
				'action' => 'trash',
			)
		);
		$this->review_status( $r10, 'trash' );
		$r11 = $this->create_review( 'CMT-05-r11', $post, 'Spam terminal', 'approved' );
		$this->invoke(
			'CMT-05-r11-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r11,
				'action' => 'spam',
			)
		);
		$this->review_status( $r11, 'spam' );
		$this->expect_error(
			'CMT-05-spam-trash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r11,
				'action' => 'trash',
			),
			array( 'invalid_state' )
		);
		$this->expect_error(
			'CMT-05-spam-approve',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r11,
				'action' => 'approve',
			),
			array( 'invalid_state' )
		);
		$this->invoke(
			'CMT-05-r11-unspam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r11,
				'action' => 'unspam',
			)
		);
		$this->review_status( $r11, 'approved' );
		$r12 = $this->create_review( 'CMT-05-r12', $post, 'Trash terminal', 'approved' );
		$this->invoke(
			'CMT-05-r12-trash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r12,
				'action' => 'trash',
			)
		);
		$this->review_status( $r12, 'trash' );
		$this->expect_error(
			'CMT-05-trash-approve',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r12,
				'action' => 'approve',
			),
			array( 'invalid_state' )
		);
		$this->invoke(
			'CMT-05-r12-untrash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r12,
				'action' => 'untrash',
			)
		);
		$this->review_status( $r12, 'approved' );
		$unspam2 = $this->invoke(
			'CMT-05-unspam-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'unspam',
			)
		);
		$this->assert_case( 'CMT-05-unspam-noop', false === ( $unspam2['changed'] ?? true ) && 'approved' === ( $unspam2['status'] ?? '' ), 'unspam_noop_mismatch' );
		$untrash2 = $this->invoke(
			'CMT-05-untrash-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'untrash',
			)
		);
		$this->assert_case( 'CMT-05-untrash-noop', false === ( $untrash2['changed'] ?? true ) && 'approved' === ( $untrash2['status'] ?? '' ), 'untrash_noop_mismatch' );
		$this->expect_error(
			'CMT-05-invalid-action',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'feature',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-05-unknown',
			'editor',
			'moderate-comment',
			array(
				'id'     => 999999999,
				'action' => 'approve',
			),
			array( 'not_found' )
		);
		$this->expect_error(
			'CMT-05-forbidden-owner',
			'moderator',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'spam',
			),
			array( 'forbidden' )
		);
		$this->assert_delta( 'CMT-05-counts', $this->counts_snapshot(), $base, 'spam', 1 );
		$this->assert_delta( 'CMT-05-counts-trash', $this->counts_snapshot(), $base, 'trash', 1 );
	}

	/**
	 * CMT-06: bounded bulk moderation and per-item results.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_bulk(): void {
		$post    = (int) $this->setup['posts']['review'];
		$r13     = $this->create_review( 'CMT-06-r13', $post, 'Bulk one', 'hold' );
		$r14     = $this->create_review( 'CMT-06-r14', $post, 'Bulk two', 'hold' );
		$r15     = $this->create_review( 'CMT-06-r15', $post, 'Bulk three', 'hold' );
		$approve = $this->invoke(
			'CMT-06-approve',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13, $r14, $r15 ),
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-06-approve', array( $r13, $r14, $r15 ) === array_map( 'intval', (array) ( $approve['updated'] ?? array() ) ) && array() === (array) ( $approve['unchanged'] ?? array() ) && array() === (array) ( $approve['failed'] ?? array() ), 'bulk_approve_mismatch' );
		foreach ( array( $r13, $r14, $r15 ) as $id ) {
			$this->review_status( $id, 'approved' );
		}
		$unchanged = $this->invoke(
			'CMT-06-unchanged',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13, $r14, $r15 ),
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-06-unchanged', 3 === count( (array) ( $unchanged['unchanged'] ?? array() ) ) && array() === (array) ( $unchanged['updated'] ?? array() ), 'bulk_unchanged_mismatch' );
		$this->expect_error(
			'CMT-06-duplicate',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13, $r13 ),
				'action' => 'approve',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-06-over-limit',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => range( 1, 51 ),
				'action' => 'approve',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$trash = $this->invoke(
			'CMT-06-trash',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13, $r14 ),
				'action' => 'trash',
			)
		);
		$this->assert_case( 'CMT-06-trash', 2 === count( (array) ( $trash['updated'] ?? array() ) ), 'bulk_trash_mismatch' );
		$this->review_status( $r13, 'trash' );
		$this->review_status( $r14, 'trash' );
		$untrash = $this->invoke(
			'CMT-06-untrash',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13, $r14 ),
				'action' => 'untrash',
			)
		);
		$this->assert_case( 'CMT-06-untrash', 2 === count( (array) ( $untrash['updated'] ?? array() ) ), 'bulk_untrash_mismatch' );
		$this->review_status( $r13, 'approved' );
		$this->review_status( $r14, 'approved' );
		$partial = $this->invoke(
			'CMT-06-partial',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r15, 999999999 ),
				'action' => 'spam',
			)
		);
		$failed  = (array) ( $partial['failed'] ?? array() );
		$this->assert_case( 'CMT-06-partial', array( $r15 ) === array_map( 'intval', (array) ( $partial['updated'] ?? array() ) ) && 1 === count( $failed ) && 999999999 === (int) ( $failed[0]['id'] ?? 0 ) && 'not_found' === ( $failed[0]['code'] ?? '' ), 'bulk_partial_mismatch' );
		$this->review_status( $r15, 'spam' );
		$this->invoke(
			'CMT-06-partial-restore',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r15,
				'action' => 'unspam',
			)
		);
		$this->review_status( $r15, 'approved' );
		$forbidden = $this->invoke(
			'CMT-06-forbidden',
			'moderator',
			'bulk-moderate-comments',
			array(
				'ids'    => array( $r13 ),
				'action' => 'spam',
			)
		);
		$failed    = (array) ( $forbidden['failed'] ?? array() );
		$this->assert_case( 'CMT-06-forbidden', 1 === count( $failed ) && 'forbidden' === ( $failed[0]['code'] ?? '' ) && array() === (array) ( $forbidden['updated'] ?? array() ), 'bulk_forbidden_mismatch' );
		$this->assert_case( 'CMT-06-forbidden-status', 'approved' === $this->status_of( 'CMT-06-forbidden-status', 'editor', $r13 ), 'forbidden_changed_status' );
		$this->expect_error(
			'CMT-06-empty',
			'editor',
			'bulk-moderate-comments',
			array(
				'ids'    => array(),
				'action' => 'approve',
			),
			array( 'invalid_params', 'internal_error' )
		);
	}

	/**
	 * CMT-07: key scope, visibility, ownership and raw/email gates.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_permissions(): void {
		$post = (int) $this->setup['posts']['review'];
		$own  = (int) $this->setup['posts']['moderator'];
		$r8   = $this->first_review_id( 'CMT-05-r8' );
		$r3   = $this->first_review_id( 'CMT-02-create-approved' );
		$this->expect_denied(
			'CMT-07-author-moderate',
			'author',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'spam',
			)
		);
		$this->expect_denied(
			'CMT-07-author-create',
			'author',
			'create-comment',
			array(
				'post'    => $post,
				'content' => 'Author create',
			)
		);
		$this->expect_denied( 'CMT-07-author-list', 'author', 'list-comments', array( 'post' => $post ) );
		$rendered = $this->invoke( 'CMT-07-mod-get', 'moderator', 'get-comment', array( 'id' => $r8 ) );
		$this->assert_case( 'CMT-07-mod-get', (int) ( $rendered['id'] ?? 0 ) === $r8, 'moderator_get_mismatch' );
		$this->expect_error(
			'CMT-07-mod-raw',
			'moderator',
			'get-comment',
			array(
				'id'             => $r8,
				'content_format' => 'raw',
			),
			array( 'forbidden' )
		);
		$this->expect_error(
			'CMT-07-mod-email',
			'moderator',
			'get-comment',
			array(
				'id'     => $r8,
				'fields' => array( 'author_email' ),
			),
			array( 'forbidden' )
		);
		$this->expect_error(
			'CMT-07-mod-update',
			'moderator',
			'update-comment',
			array(
				'id'      => $r8,
				'content' => 'Other owner',
			),
			array( 'forbidden' )
		);
		$this->expect_error(
			'CMT-07-mod-moderate',
			'moderator',
			'moderate-comment',
			array(
				'id'     => $r8,
				'action' => 'spam',
			),
			array( 'forbidden' )
		);
		$this->expect_error( 'CMT-07-mod-counts', 'moderator', 'get-comment-counts', array( 'post' => $post ), array( 'forbidden' ) );
		$other = $this->invoke(
			'CMT-07-mod-create-other',
			'moderator',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( 'moderator-other-post', 'Moderator comment on another owner post' ),
				'status'  => 'hold',
			)
		);
		$this->assert_case( 'CMT-07-mod-create-other', 'hold' === ( $other['status'] ?? '' ) && (int) ( $other['post'] ?? 0 ) === $post, 'moderator_other_create_mismatch' );
		$this->review_add( (int) $other['id'], $post, 'hold', 'CMT-07-mod-create-other' );
		$r16 = $this->invoke(
			'CMT-07-mod-create-own',
			'moderator',
			'create-comment',
			array(
				'post'    => $own,
				'content' => $this->review_content( 'moderator-own', 'Moderator own comment' ),
				'status'  => 'hold',
			)
		);
		$this->assert_case( 'CMT-07-mod-create-own', 'hold' === ( $r16['status'] ?? '' ) && (int) ( $r16['post'] ?? 0 ) === $own, 'moderator_own_create_mismatch' );
		$this->review_add( (int) $r16['id'], $own, 'hold', 'CMT-07-mod-create-own' );
		$updated = $this->invoke(
			'CMT-07-mod-update-own',
			'moderator',
			'update-comment',
			array(
				'id'      => (int) $r16['id'],
				'content' => $this->review_content( 'moderator-own-update', 'Moderator own updated' ),
			)
		);
		$this->assert_case( 'CMT-07-mod-update-own', (int) (int) ( $updated['id'] ?? 0 ) === (int) $r16['id'], 'moderator_own_update_mismatch' );
		$approved = $this->invoke(
			'CMT-07-mod-moderate-own',
			'moderator',
			'moderate-comment',
			array(
				'id'     => (int) $r16['id'],
				'action' => 'approve',
			)
		);
		$this->assert_case( 'CMT-07-mod-moderate-own', 'approved' === ( $approved['status'] ?? '' ), 'moderator_own_moderate_mismatch' );
		$this->review_status( (int) $r16['id'], 'approved' );
		$raw_own = $this->invoke(
			'CMT-07-mod-raw-own',
			'moderator',
			'get-comment',
			array(
				'id'             => (int) $r16['id'],
				'content_format' => 'raw',
			)
		);
		$this->assert_case( 'CMT-07-mod-raw-own', str_contains( (string) ( $raw_own['content'] ?? '' ), '[CMT-REVIEW:' ), 'moderator_raw_own_mismatch' );
		$email_own = $this->invoke(
			'CMT-07-mod-email-own',
			'moderator',
			'get-comment',
			array(
				'id'     => (int) $r16['id'],
				'fields' => array( 'id', 'author_email' ),
			)
		);
		$this->assert_case( 'CMT-07-mod-email-own', true === str_ends_with( (string) ( $email_own['author_email'] ?? '' ), '@example.test' ), 'moderator_email_own_mismatch' );
		$read = $this->invoke(
			'CMT-07-read-list',
			'read',
			'list-comments',
			array(
				'post'     => $post,
				'status'   => 'all',
				'per_page' => 10,
			)
		);
		$this->assert_case( 'CMT-07-read-list', (int) ( $read['total'] ?? 0 ) >= 1, 'read_list_mismatch' );
		$raw_read = $this->invoke(
			'CMT-07-read-raw',
			'read',
			'get-comment',
			array(
				'id'             => $r8,
				'content_format' => 'raw',
			)
		);
		$this->assert_case( 'CMT-07-read-raw', (int) ( $raw_read['id'] ?? 0 ) === $r8, 'read_raw_mismatch' );
		$this->expect_denied(
			'CMT-07-read-create',
			'read',
			'create-comment',
			array(
				'post'    => $post,
				'content' => 'Read create',
			)
		);
		$this->expect_denied( 'CMT-07-content-list', 'content', 'list-comments', array( 'post' => $post ) );
		$raw_editor = $this->invoke(
			'CMT-07-editor-raw',
			'editor',
			'get-comment',
			array(
				'id'             => $r8,
				'content_format' => 'raw',
			)
		);
		$this->assert_case( 'CMT-07-editor-raw', (int) ( $raw_editor['id'] ?? 0 ) === $r8, 'editor_raw_mismatch' );
		$email_editor = $this->invoke(
			'CMT-07-editor-email',
			'editor',
			'get-comment',
			array(
				'id'     => $r8,
				'fields' => array( 'id', 'author_email' ),
			)
		);
		$this->assert_case( 'CMT-07-editor-email', true === str_ends_with( (string) ( $email_editor['author_email'] ?? '' ), '@example.test' ), 'editor_email_mismatch' );
		$email    = 'cmt-review-' . $this->site . '-editor@example.test';
		$filtered = $this->invoke(
			'CMT-07-email-filter',
			'editor',
			'list-comments',
			array(
				'post'         => $post,
				'status'       => 'all',
				'author_email' => $email,
				'per_page'     => 100,
			)
		);
		$items    = (array) ( $filtered['items'] ?? array() );
		$ids      = array_map( 'intval', array_column( $items, 'id' ) );
		$this->assert_case( 'CMT-07-email-filter', in_array( $r8, $ids, true ) && $this->no_email_field( $items ), 'email_filter_mismatch' );
		$this->assert_case( 'CMT-07-default-no-email', ! array_key_exists( 'author_email', $this->invoke( 'CMT-07-default-no-email', 'editor', 'get-comment', array( 'id' => $r3 ) ) ), 'email_leaked_by_default' );
	}

	/**
	 * CMT-08: permanent deletion only from spam or trash and only for
	 * explicitly disposable comments.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_delete(): void {
		$post = (int) $this->setup['posts']['review'];
		$e1   = $this->create_ephemeral( 'CMT-08-e1', $post, 'Delete approved', 'approved' );
		$this->expect_error( 'CMT-08-approved-denied', 'editor', 'delete-comment', array( 'id' => $e1 ), array( 'invalid_state' ) );
		$this->invoke(
			'CMT-08-e1-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e1,
				'action' => 'spam',
			)
		);
		$this->ephemeral_status( $e1, 'spam' );
		$deleted = $this->invoke( 'CMT-08-spam-delete', 'editor', 'delete-comment', array( 'id' => $e1 ) );
		$this->assert_case( 'CMT-08-spam-delete', true === ( $deleted['deleted'] ?? false ), 'delete_spam_mismatch' );
		$this->expect_error( 'CMT-08-spam-gone', 'editor', 'get-comment', array( 'id' => $e1 ), array( 'not_found' ) );
		$this->mark_deleted( $e1, 'CMT-08-spam-delete' );
		$e2 = $this->create_ephemeral( 'CMT-08-e2', $post, 'Delete trash', 'approved' );
		$this->invoke(
			'CMT-08-e2-trash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e2,
				'action' => 'trash',
			)
		);
		$this->ephemeral_status( $e2, 'trash' );
		$deleted = $this->invoke( 'CMT-08-trash-delete', 'editor', 'delete-comment', array( 'id' => $e2 ) );
		$this->assert_case( 'CMT-08-trash-delete', true === ( $deleted['deleted'] ?? false ), 'delete_trash_mismatch' );
		$this->expect_error( 'CMT-08-trash-gone', 'editor', 'get-comment', array( 'id' => $e2 ), array( 'not_found' ) );
		$this->mark_deleted( $e2, 'CMT-08-trash-delete' );
		$e3 = $this->create_ephemeral( 'CMT-08-e3', $post, 'Delete hold', 'hold' );
		$this->expect_error( 'CMT-08-hold-denied', 'editor', 'delete-comment', array( 'id' => $e3 ), array( 'invalid_state' ) );
		$this->invoke(
			'CMT-08-e3-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e3,
				'action' => 'spam',
			)
		);
		$deleted = $this->invoke( 'CMT-08-e3-delete', 'editor', 'delete-comment', array( 'id' => $e3 ) );
		$this->assert_case( 'CMT-08-e3-delete', true === ( $deleted['deleted'] ?? false ), 'delete_e3_mismatch' );
		$this->expect_error( 'CMT-08-e3-gone', 'editor', 'get-comment', array( 'id' => $e3 ), array( 'not_found' ) );
		$this->mark_deleted( $e3, 'CMT-08-e3-delete' );
		$e4 = $this->create_ephemeral( 'CMT-08-e4', $post, 'Delete forbidden', 'approved' );
		$this->invoke(
			'CMT-08-e4-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e4,
				'action' => 'spam',
			)
		);
		$this->expect_denied( 'CMT-08-forbidden', 'moderator', 'delete-comment', array( 'id' => $e4 ) );
		$deleted = $this->invoke( 'CMT-08-e4-delete', 'editor', 'delete-comment', array( 'id' => $e4 ) );
		$this->assert_case( 'CMT-08-e4-delete', true === ( $deleted['deleted'] ?? false ), 'delete_e4_mismatch' );
		$this->expect_error( 'CMT-08-e4-gone', 'editor', 'get-comment', array( 'id' => $e4 ), array( 'not_found' ) );
		$this->mark_deleted( $e4, 'CMT-08-e4-delete' );
		$this->expect_error( 'CMT-08-unknown', 'editor', 'delete-comment', array( 'id' => 999999999 ), array( 'not_found' ) );
		$e5 = $this->first_ephemeral_id( 'boundary' );
		$this->invoke(
			'CMT-08-boundary-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e5,
				'action' => 'spam',
			)
		);
		$deleted = $this->invoke( 'CMT-08-boundary-delete', 'editor', 'delete-comment', array( 'id' => $e5 ) );
		$this->assert_case( 'CMT-08-boundary-delete', true === ( $deleted['deleted'] ?? false ), 'delete_boundary_mismatch' );
		$this->mark_deleted( $e5, 'CMT-08-boundary-delete' );
	}

	/**
	 * CMT-11: restore review spam/trash before the wp71 purge phase.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_restore(): void {
		$restored = 0;
		foreach ( $this->review as $id => $row ) {
			if ( 'spam' !== $row['status'] && 'trash' !== $row['status'] ) {
				continue;
			}
			$action = 'spam' === $row['status'] ? 'unspam' : 'untrash';
			$case   = 'CMT-11-restore-' . $action . '-' . $id;
			$data   = $this->invoke(
				$case,
				'editor',
				'moderate-comment',
				array(
					'id'     => (int) $id,
					'action' => $action,
				)
			);
			$this->assert_case( $case, in_array( (string) ( $data['status'] ?? '' ), array( 'approved', 'hold' ), true ), 'restore_mismatch' );
			$this->review[ $id ]['status'] = (string) $data['status'];
			++$restored;
		}
		$this->assert_case( 'CMT-11-restore-all', $restored >= 4, 'restore_count_mismatch' );
		$r9      = $this->first_review_id( 'CMT-05-r9' );
		$r10     = $this->first_review_id( 'CMT-05-r10' );
		$unspam2 = $this->invoke(
			'CMT-11-unspam-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r9,
				'action' => 'unspam',
			)
		);
		$this->assert_case( 'CMT-11-unspam-noop', false === ( $unspam2['changed'] ?? true ) && 'approved' === ( $unspam2['status'] ?? '' ), 'restore_unspam_noop_mismatch' );
		$untrash2 = $this->invoke(
			'CMT-11-untrash-noop',
			'editor',
			'moderate-comment',
			array(
				'id'     => $r10,
				'action' => 'untrash',
			)
		);
		$this->assert_case( 'CMT-11-untrash-noop', false === ( $untrash2['changed'] ?? true ) && 'approved' === ( $untrash2['status'] ?? '' ), 'restore_untrash_noop_mismatch' );
	}

	/**
	 * CMT-09: purge refusals, foreign blocking and wp71 disposable deletion.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_purge(): void {
		$sentinel    = (int) ( $this->setup['sentinel'] ?? 0 );
		$spam        = $this->audit_purge( 'spam' );
		$trash_audit = $this->audit_purge( 'trash' );
		if ( $spam['foreign'] > 0 || $trash_audit['foreign'] > 0 ) {
			throw new CommentsReviewFailure( 'CMT-09-foreign', 'purge_blocked_foreign', true );
		}
		if ( 'purge-safe' === $this->retention && ( count( $spam['review_ids'] ) > 0 || count( $trash_audit['review_ids'] ) > 0 ) ) {
			throw new CommentsReviewFailure( 'CMT-09-review', 'purge_blocked_review', true );
		}
		$this->assert_case( 'CMT-09-audit-sentinel', in_array( $sentinel, $spam['sentinel_ids'], true ), 'sentinel_not_visible' );
		if ( $sentinel > 0 ) {
			$this->invoke( 'CMT-09-sentinel-delete', 'editor', 'delete-comment', array( 'id' => $sentinel ) );
			$this->expect_error( 'CMT-09-sentinel-gone', 'editor', 'get-comment', array( 'id' => $sentinel ), array( 'not_found' ) );
			$this->mark_deleted( $sentinel, 'CMT-09-sentinel-delete' );
		}
		$after_spam  = $this->audit_purge( 'spam' );
		$after_trash = $this->audit_purge( 'trash' );
		$this->assert_case( 'CMT-09-sentinel-clear', 0 === $after_spam['sentinel'] && 0 === $after_spam['foreign'] && 0 === $after_trash['sentinel'] && 0 === $after_trash['foreign'], 'sentinel_still_present' );
		if ( 'all' === $this->retention ) {
			$r9  = $this->first_review_id( 'CMT-05-r9' );
			$r10 = $this->first_review_id( 'CMT-05-r10' );
			$this->assert_case( 'CMT-09-review-block', in_array( $r9, $spam['review_ids'], true ) && in_array( $r10, $trash_audit['review_ids'], true ), 'review_not_blocked' );
			$this->notes[]       = 'purge-delete: blocked_by_design on wp69 because retained review spam/trash would be selected first; no purge call is made on this site.';
			$this->purge['wp69'] = array(
				'decision' => 'blocked_by_design',
				'review'   => $spam['review_ids'],
				'trash'    => $trash_audit['review_ids'],
			);
			return;
		}
		$this->assert_case( 'CMT-09-restore-clean', 0 === count( $after_spam['review_ids'] ) && 0 === count( $after_trash['review_ids'] ), 'review_still_spam_or_trash' );
		$this->expect_error(
			'CMT-09-limit-zero',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 0,
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-09-limit-high',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 101,
			),
			array( 'invalid_params', 'internal_error' )
		);
		$this->expect_error(
			'CMT-09-invalid-before',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'before' => 'not-a-date',
			),
			array( 'invalid_params', 'internal_error' )
		);
		$empty = $this->invoke(
			'CMT-09-empty-spam',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'before' => '2000-01-01T00:00:00Z',
			)
		);
		$this->assert_case( 'CMT-09-empty-spam', 0 === (int) ( $empty['deleted'] ?? -1 ) && 0 === (int) ( $empty['remaining'] ?? -1 ) && true === str_ends_with( (string) ( $empty['cutoff'] ?? '' ), 'Z' ), 'purge_empty_spam_mismatch' );
		$empty = $this->invoke(
			'CMT-09-empty-trash',
			'editor',
			'purge-comments',
			array(
				'status' => 'trash',
				'before' => '2000-01-01T00:00:00Z',
			)
		);
		$this->assert_case( 'CMT-09-empty-trash', 0 === (int) ( $empty['deleted'] ?? -1 ) && 0 === (int) ( $empty['remaining'] ?? -1 ), 'purge_empty_trash_mismatch' );
		$e6a = $this->create_ephemeral( 'CMT-09-e6a', (int) $this->setup['posts']['review'], 'Purge spam one', 'approved' );
		$e6b = $this->create_ephemeral( 'CMT-09-e6b', (int) $this->setup['posts']['review'], 'Purge spam two', 'approved' );
		$e7  = $this->create_ephemeral( 'CMT-09-e7', (int) $this->setup['posts']['review'], 'Purge trash one', 'approved' );
		$this->invoke(
			'CMT-09-e6a-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e6a,
				'action' => 'spam',
			)
		);
		$this->invoke(
			'CMT-09-e6b-spam',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e6b,
				'action' => 'spam',
			)
		);
		$this->invoke(
			'CMT-09-e7-trash',
			'editor',
			'moderate-comment',
			array(
				'id'     => $e7,
				'action' => 'trash',
			)
		);
		$this->ephemeral_status( $e6a, 'spam' );
		$this->ephemeral_status( $e6b, 'spam' );
		$this->ephemeral_status( $e7, 'trash' );
		sleep( 1 );
		$audit       = $this->audit_purge( 'spam' );
		$audit_trash = $this->audit_purge( 'trash' );
		$this->assert_case( 'CMT-09-audit', 0 === $audit['foreign'] && 0 === $audit['sentinel'] && 0 === count( $audit['review_ids'] ) && 0 === count( $audit_trash['review_ids'] ) && in_array( $e6a, $audit['ephemeral_ids'], true ) && in_array( $e6b, $audit['ephemeral_ids'], true ) && in_array( $e7, $audit_trash['ephemeral_ids'], true ), 'purge_audit_mismatch' );
		$cutoff = gmdate( 'Y-m-d\TH:i:s\Z' );
		$this->assert_case( 'CMT-09-guard-spam', 0 === $audit['review'] && 0 === $audit['foreign'] && 0 === $audit['sentinel'], 'purge_guard_mismatch' );
		$first = $this->invoke(
			'CMT-09-purge-limit',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 1,
				'before' => $cutoff,
			)
		);
		$this->assert_case( 'CMT-09-purge-limit', 1 === (int) ( $first['deleted'] ?? -1 ) && 1 === (int) ( $first['remaining'] ?? -1 ) && 0 === (int) ( $first['failed'] ?? -1 ), 'purge_limit_mismatch' );
		$this->purge_guard( 'CMT-09-guard-spam-2', 'spam' );
		$second = $this->invoke(
			'CMT-09-purge-spam',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 10,
				'before' => $cutoff,
			)
		);
		$this->assert_case( 'CMT-09-purge-spam', 1 === (int) ( $second['deleted'] ?? -1 ) && 0 === (int) ( $second['remaining'] ?? -1 ) && 0 === (int) ( $second['failed'] ?? -1 ), 'purge_spam_mismatch' );
		$this->expect_error( 'CMT-09-e6a-gone', 'editor', 'get-comment', array( 'id' => $e6a ), array( 'not_found' ) );
		$this->expect_error( 'CMT-09-e6b-gone', 'editor', 'get-comment', array( 'id' => $e6b ), array( 'not_found' ) );
		$this->mark_deleted( $e6a, 'CMT-09-purge-spam' );
		$this->mark_deleted( $e6b, 'CMT-09-purge-spam' );
		$this->purge_guard( 'CMT-09-guard-trash', 'trash' );
		$trash = $this->invoke(
			'CMT-09-purge-trash',
			'editor',
			'purge-comments',
			array(
				'status' => 'trash',
				'limit'  => 10,
				'before' => $cutoff,
			)
		);
		$this->assert_case( 'CMT-09-purge-trash', 1 === (int) ( $trash['deleted'] ?? -1 ) && 0 === (int) ( $trash['remaining'] ?? -1 ), 'purge_trash_mismatch' );
		$this->expect_error( 'CMT-09-e7-gone', 'editor', 'get-comment', array( 'id' => $e7 ), array( 'not_found' ) );
		$this->mark_deleted( $e7, 'CMT-09-purge-trash' );
		$this->purge_guard( 'CMT-09-guard-again', 'spam' );
		$again = $this->invoke(
			'CMT-09-purge-again',
			'editor',
			'purge-comments',
			array(
				'status' => 'spam',
				'limit'  => 10,
				'before' => $cutoff,
			)
		);
		$this->assert_case( 'CMT-09-purge-again', 0 === (int) ( $again['deleted'] ?? -1 ) && 0 === (int) ( $again['remaining'] ?? -1 ), 'purge_again_mismatch' );
		$this->assert_case( 'CMT-09-review-survives', 'approved' === $this->status_of( 'CMT-09-review-survives', 'editor', $this->first_review_id( 'CMT-05-r9' ) ) && 'approved' === $this->status_of( 'CMT-09-review-survives-trash', 'editor', $this->first_review_id( 'CMT-05-r10' ) ), 'review_lost_by_purge' );
		$this->purge['wp71'] = array(
			'decision' => 'executed',
			'cutoff'   => $cutoff,
			'first'    => $first,
			'second'   => $second,
			'trash'    => $trash,
			'again'    => $again,
		);
	}

	/**
	 * Re-audit immediately before one purge call and block on any candidate.
	 *
	 * @param string $case_id Reviewed case.
	 * @param string $status Status.
	 * @throws CommentsReviewFailure For review, sentinel, foreign or unreadable candidates.
	 */
	private function purge_guard( string $case_id, string $status ): void {
		$audit = $this->purge_audit_or_block( $case_id, $status );
		if ( $audit['review'] > 0 || $audit['foreign'] > 0 || $audit['sentinel'] > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, 'purge_blocked_candidate', true );
		}
		$this->assert_case( $case_id, 0 === $audit['review'] && 0 === $audit['foreign'] && 0 === $audit['sentinel'], 'purge_guard_mismatch' );
	}

	/**
	 * Audit immediately before purge with one bounded read-only retry.
	 *
	 * @param string $case_id Reviewed case.
	 * @param string $status Status.
	 * @return array<string,mixed>
	 * @throws CommentsReviewFailure For an unavailable or unsafe audit.
	 */
	private function purge_audit_or_block( string $case_id, string $status ): array {
		$attempt = 0;
		while ( true ) {
			++$attempt;
			try {
				return $this->audit_purge( $status );
			} catch ( CommentsReviewFailure $failure ) {
				if ( $attempt < 2 && in_array( $failure->reason, array( 'rate_limited', 'internal_error', 'unavailable' ), true ) ) {
					usleep( 500000 );
					continue;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
				throw new CommentsReviewFailure( $case_id, 'purge_audit_' . $failure->reason, true );
			}
		}
	}

	/**
	 * CMT-10: prove every retained review comment survived.
	 *
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function phase_retention(): void {
		$alive = 0;
		foreach ( $this->review as $id => $row ) {
			$data = $this->invoke(
				'CMT-10-review-alive',
				'editor',
				'get-comment',
				array(
					'id'             => (int) $id,
					'content_format' => 'raw',
				)
			);
			if ( (int) ( $data['id'] ?? 0 ) === (int) $id && (string) ( $data['status'] ?? '' ) === (string) $row['status'] ) {
				++$alive;
			} else {
				throw new CommentsReviewFailure( 'CMT-10-status', 'review_status_mismatch' );
			}
		}
		$this->assert_case( 'CMT-10-review-alive', count( $this->review ) === $alive, 'review_not_alive' );
		$this->assert_case( 'CMT-10-review-not-deleted', array() === array_intersect( array_keys( $this->review ), array_keys( $this->deleted ) ), 'review_in_deleted_ledger' );
		$this->retained = count( $this->review ) === $alive && array() === array_intersect( array_keys( $this->review ), array_keys( $this->deleted ) );
		$expected_spam  = array();
		$expected_trash = array();
		foreach ( $this->review as $id => $row ) {
			if ( 'spam' === $row['status'] ) {
				$expected_spam[] = (int) $id;
			} elseif ( 'trash' === $row['status'] ) {
				$expected_trash[] = (int) $id;
			}
		}
		$spam  = $this->status_ids( 'spam' );
		$trash = $this->status_ids( 'trash' );
		$spam  = array_values( array_intersect( $spam, array_keys( $this->review ) ) );
		$trash = array_values( array_intersect( $trash, array_keys( $this->review ) ) );
		sort( $expected_spam );
		sort( $expected_trash );
		sort( $spam );
		sort( $trash );
		$this->assert_case( 'CMT-10-spam-set', $expected_spam === $spam, 'spam_set_mismatch' );
		$this->assert_case( 'CMT-10-trash-set', $expected_trash === $trash, 'trash_set_mismatch' );
		$journal = $this->journal( null, null, true, 'pass' );
		$encoded = Json::encode( $journal );
		$email   = 'cmt-review-' . $this->site . '-editor@example.test';
		$this->assert_case( 'CMT-10-journal-safe', ! str_contains( $encoded, '"token"' ) && ! str_contains( $encoded, 'Bearer ' ) && ! str_contains( $encoded, $email ) && ! str_contains( $encoded, 'author_email"' ), 'journal_secret_leak' );
		$this->assert_case( 'CMT-10-retained', $this->retained, 'retention_not_recorded' );
	}

	/**
	 * CMT-03 helper: create a retained review comment.
	 *
	 * @param string $case_id Reviewed case.
	 * @param int    $post Post.
	 * @param string $body Body.
	 * @param string $status Status.
	 * @return int
	 */
	private function create_review( string $case_id, int $post, string $body, string $status ): int {
		$data           = $this->invoke(
			$case_id,
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->review_content( $case_id, $body ),
				'status'  => $status,
			)
		);
		$id             = (int) ( $data['id'] ?? 0 );
		$created_status = (string) ( $data['status'] ?? '' );
		$this->assert_case( $case_id, 0 < $id && $created_status === $status, 'create_review_mismatch' );
		$this->review_add( $id, $post, $status, $case_id );
		return $id;
	}

	/**
	 * Create one disposable comment.
	 *
	 * @param string $case_id Reviewed case.
	 * @param int    $post Post.
	 * @param string $body Body.
	 * @param string $status Status.
	 * @return int
	 */
	private function create_ephemeral( string $case_id, int $post, string $body, string $status ): int {
		$data = $this->invoke(
			$case_id,
			'editor',
			'create-comment',
			array(
				'post'    => $post,
				'content' => $this->ephemeral_content( $case_id, $body ),
				'status'  => $status,
			)
		);
		$id   = (int) ( $data['id'] ?? 0 );
		$this->assert_case( $case_id, $id > 0, 'create_ephemeral_mismatch' );
		$this->ephemeral_add( $id, 'comment', $post, $status );
		return $id;
	}

	/**
	 * Send one MCP request.
	 *
	 * @param string              $kind Key kind.
	 * @param string              $method MCP method.
	 * @param array<string,mixed> $params Parameters.
	 * @return array<string,mixed>
	 * @throws RuntimeException For unavailable transport or state.
	 */
	private function send( string $kind, string $method, array $params = array() ): array {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => (object) array(),
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'comments-review',
				'version' => '1',
			),
		);
		$headers         = array(
			'MCP-Protocol-Version' => '2026-07-28',
			'Mcp-Method'           => $method,
		);
		if ( isset( $params['name'] ) ) {
			$headers['Mcp-Name'] = $params['name'];
		}
		if ( ! isset( $this->state['keys'][ $kind ]['token'] ) ) {
			throw new RuntimeException( 'Comments review key is unavailable.' );
		}
		$result = $this->client->request(
			'/wp-json/xnetvn-agent-hub-wp/mcp',
			Json::encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => $params,
				)
			),
			(string) $this->state['keys'][ $kind ]['token'],
			$headers
		);
		return $result;
	}

	/**
	 * Invoke one reviewed tool and require a structured success.
	 *
	 * @param string              $case_id Reviewed case.
	 * @param string              $kind Key kind.
	 * @param string              $name Tool suffix.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 * @throws CommentsReviewFailure For an unexpected refusal or missing data.
	 */
	private function invoke( string $case_id, string $kind, string $name, array $args = array() ): array {
		$result = $this->send(
			$kind,
			'tools/call',
			array(
				'name'      => 'xnetvn-' . $name,
				'arguments' => (object) $args,
			)
		);
		$data   = $result['body']['result']['structuredContent'] ?? null;
		if ( 200 !== $result['status'] || ( $result['body']['result']['isError'] ?? false ) || ! is_array( $data ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, $this->safe_reason( $result ) );
		}
		return $data;
	}

	/**
	 * Require one expected refusal with a matching safe code.
	 *
	 * @param string              $case_id Reviewed case.
	 * @param string              $kind Key kind.
	 * @param string              $name Tool suffix.
	 * @param array<string,mixed> $args Arguments.
	 * @param array<string>       $allowed Accepted safe codes.
	 * @throws CommentsReviewFailure For a success or an unexpected code.
	 */
	private function expect_error( string $case_id, string $kind, string $name, array $args, array $allowed ): void {
		$result   = $this->send(
			$kind,
			'tools/call',
			array(
				'name'      => 'xnetvn-' . $name,
				'arguments' => (object) $args,
			)
		);
		$is_error = 200 !== $result['status'] || ( $result['body']['result']['isError'] ?? false ) || isset( $result['body']['error'] );
		if ( ! $is_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, 'expected_refusal' );
		}
		$reason = $this->safe_reason( $result );
		if ( ! in_array( $reason, $allowed, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, 'unexpected_refusal_' . $reason );
		}
		$this->assert_case( $case_id, true );
	}

	/**
	 * Require one hidden-tool refusal without guessing an adapter code.
	 *
	 * @param string              $case_id Reviewed case.
	 * @param string              $kind Key kind.
	 * @param string              $name Tool suffix.
	 * @param array<string,mixed> $args Arguments.
	 * @throws CommentsReviewFailure When the call unexpectedly succeeds.
	 */
	private function expect_denied( string $case_id, string $kind, string $name, array $args ): void {
		$result   = $this->send(
			$kind,
			'tools/call',
			array(
				'name'      => 'xnetvn-' . $name,
				'arguments' => (object) $args,
			)
		);
		$is_error = 200 !== $result['status'] || ( $result['body']['result']['isError'] ?? false ) || isset( $result['body']['error'] );
		if ( ! $is_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, 'expected_refusal' );
		}
		$observed = $this->safe_reason( $result );
		if ( ! in_array( $observed, array( 'forbidden', 'unknown_tool', 'invalid_params', 'unavailable' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, 'unexpected_refusal_' . $observed );
		}
		$this->cases[ $case_id ] = array(
			'ok'       => true,
			'observed' => $observed,
		);
	}

	/**
	 * Extract one allowlisted error code from a response.
	 *
	 * @param array<string,mixed> $result HTTP result.
	 * @return string
	 */
	private function safe_reason( array $result ): string {
		$encoded = Json::encode( $result['body'] ?? array() );
		foreach ( self::SAFE_CODES as $candidate ) {
			if ( str_contains( $encoded, $candidate ) ) {
				return $candidate;
			}
		}
		if ( str_contains( $encoded, '-32602' ) || false !== stripos( $encoded, 'invalid params' ) ) {
			return 'invalid_params';
		}
		if ( str_contains( $encoded, '-32601' ) ) {
			return 'unknown_tool';
		}
		return 'unavailable';
	}

	/**
	 * Return visible tool suffixes for a key.
	 *
	 * @param string $kind Key kind.
	 * @return array<string>
	 * @throws CommentsReviewFailure For an unavailable tools/list response.
	 */
	private function tool_names( string $kind ): array {
		$result = $this->send( $kind, 'tools/list' );
		if ( 200 !== $result['status'] ) {
			throw new CommentsReviewFailure( 'CMT-01-tools', 'tools_list_unavailable' );
		}
		$tools = (array) ( $result['body']['result']['tools'] ?? array() );
		$names = array();
		foreach ( $tools as $tool ) {
			$name   = (string) ( $tool['name'] ?? '' );
			$suffix = str_starts_with( $name, 'xnetvn-' ) ? substr( $name, 7 ) : '';
			if ( in_array( $suffix, self::TOOL_NAMES, true ) ) {
				$names[] = $suffix;
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Return tool definitions by suffix.
	 *
	 * @param string $kind Key kind.
	 * @return array<string,array<string,mixed>>
	 * @throws CommentsReviewFailure For an unavailable tools/list response.
	 */
	private function tool_defs( string $kind ): array {
		$result = $this->send( $kind, 'tools/list' );
		if ( 200 !== $result['status'] ) {
			throw new CommentsReviewFailure( 'CMT-01-tools', 'tools_list_unavailable' );
		}
		$defs = array();
		foreach ( (array) ( $result['body']['result']['tools'] ?? array() ) as $tool ) {
			$name   = (string) ( $tool['name'] ?? '' );
			$suffix = str_starts_with( $name, 'xnetvn-' ) ? substr( $name, 7 ) : '';
			if ( in_array( $suffix, self::TOOL_NAMES, true ) ) {
				$defs[ $suffix ] = $tool;
			}
		}
		return $defs;
	}

	/**
	 * Compare two string sets.
	 *
	 * @param array<string> $expected Expected.
	 * @param array<string> $actual Actual.
	 * @return bool
	 */
	private function same_set( array $expected, array $actual ): bool {
		sort( $expected );
		sort( $actual );
		return $expected === $actual;
	}

	/**
	 * Assert one list response contains every expected ID.
	 *
	 * @param string              $case_id Reviewed case.
	 * @param array<string,mixed> $args Arguments.
	 * @param array<int>          $ids Expected IDs.
	 * @throws CommentsReviewFailure For a missing ID.
	 */
	private function assert_list_contains( string $case_id, array $args, array $ids ): void {
		$data = $this->invoke( $case_id, 'editor', 'list-comments', $args );
		$got  = array_map( 'intval', array_column( (array) ( $data['items'] ?? array() ), 'id' ) );
		$this->assert_case( $case_id, array() === array_diff( $ids, $got ), 'list_missing_id' );
	}

	/**
	 * Assert one list response omits every unexpected ID.
	 *
	 * @param string              $case_id Reviewed case.
	 * @param array<string,mixed> $args Arguments.
	 * @param array<int>          $ids Unexpected IDs.
	 * @throws CommentsReviewFailure For a visible ID.
	 */
	private function assert_list_missing( string $case_id, array $args, array $ids ): void {
		$data = $this->invoke( $case_id, 'editor', 'list-comments', $args );
		$got  = array_map( 'intval', array_column( (array) ( $data['items'] ?? array() ), 'id' ) );
		$this->assert_case( $case_id, array() === array_intersect( $ids, $got ), 'list_unexpected_id' );
	}

	/**
	 * Return one comment status.
	 *
	 * @param string $case_id Reviewed case.
	 * @param string $kind Key kind.
	 * @param int    $id Comment.
	 * @return string
	 */
	private function status_of( string $case_id, string $kind, int $id ): string {
		$data = $this->invoke(
			$case_id,
			$kind,
			'get-comment',
			array(
				'id'             => $id,
				'content_format' => 'raw',
			)
		);
		return (string) ( $data['status'] ?? '' );
	}

	/**
	 * Return all comment IDs in one status across readable types.
	 *
	 * @param string $status Status.
	 * @return array<int>
	 * @throws CommentsReviewFailure For an unbounded audit page count.
	 */
	private function status_ids( string $status ): array {
		$ids = array();
		foreach ( array( 'comment', 'pingback', 'trackback' ) as $type ) {
			$page = 1;
			do {
				$data = $this->invoke(
					'CMT-10-list-' . $status,
					'editor',
					'list-comments',
					array(
						'status'   => $status,
						'type'     => $type,
						'per_page' => 100,
						'page'     => $page,
						'fields'   => array( 'id', 'status' ),
					)
				);
				foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
					$ids[] = (int) ( $item['id'] ?? 0 );
				}
				$pages = (int) ( $data['total_pages'] ?? 1 );
				if ( $pages > 20 ) {
					throw new CommentsReviewFailure( 'CMT-09-audit', 'audit_pagination_bound', true );
				}
				++$page;
			} while ( $page <= $pages );
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Build an MCP-side purge audit.
	 *
	 * @param string $status Status.
	 * @return array<string,mixed>
	 */
	private function audit_purge( string $status ): array {
		$ids      = $this->status_ids( $status );
		$review   = array();
		$ephem    = array();
		$sentinel = array();
		$foreign  = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->review[ $id ] ) ) {
				$review[] = $id;
			} elseif ( isset( $this->ephemeral[ $id ] ) && 'sentinel' === $this->ephemeral[ $id ]['kind'] ) {
				$sentinel[] = $id;
			} elseif ( isset( $this->ephemeral[ $id ] ) ) {
				$ephem[] = $id;
			} else {
				$foreign[] = $id;
			}
		}
		return array(
			'status'        => $status,
			'ids'           => $ids,
			'review_ids'    => $review,
			'ephemeral_ids' => $ephem,
			'sentinel_ids'  => $sentinel,
			'foreign'       => count( $foreign ),
			'review'        => count( $review ),
			'ephemeral'     => count( $ephem ),
			'sentinel'      => count( $sentinel ),
		);
	}

	/**
	 * Sum core counts over both retained review posts.
	 *
	 * @return array<string,int>
	 */
	private function counts_snapshot(): array {
		$sum = array(
			'approved' => 0,
			'hold'     => 0,
			'spam'     => 0,
			'trash'    => 0,
			'all'      => 0,
			'total'    => 0,
		);
		foreach ( array( (int) $this->setup['posts']['review'], (int) $this->setup['posts']['moderator'] ) as $post ) {
			$data = $this->invoke( 'CMT-02-counts', 'editor', 'get-comment-counts', array( 'post' => $post ) );
			foreach ( array_keys( $sum ) as $field ) {
				$sum[ $field ] += (int) ( $data[ $field ] ?? 0 );
			}
		}
		return $sum;
	}

	/**
	 * Compare one count field against a delta.
	 *
	 * @param string            $case_id Reviewed case.
	 * @param array<string,int> $after After snapshot.
	 * @param array<string,int> $before Before snapshot.
	 * @param string            $field Field.
	 * @param int               $delta Expected delta.
	 * @throws CommentsReviewFailure For a mismatch.
	 */
	private function assert_delta( string $case_id, array $after, array $before, string $field, int $delta ): void {
		$this->assert_case( $case_id, (int) ( $after[ $field ] ?? 0 ) - (int) ( $before[ $field ] ?? 0 ) === $delta, 'count_delta_' . $field );
	}

	/**
	 * Refresh prior review statuses after the wp71 preflight restore.
	 */
	private function refresh_prior_review(): void {
		foreach ( $this->review as $id => $row ) {
			$data                          = $this->invoke(
				'CMT-00-prior-review',
				'editor',
				'get-comment',
				array(
					'id'             => (int) $id,
					'content_format' => 'raw',
				)
			);
			$this->review[ $id ]['status'] = (string) ( $data['status'] ?? $row['status'] );
			$this->review[ $id ]['post']   = (int) ( $data['post'] ?? 0 );
		}
	}

	/**
	 * Add one retained review comment to the ledger.
	 *
	 * @param int    $id Comment.
	 * @param int    $post Post.
	 * @param string $status Status.
	 * @param string $case_id Case.
	 */
	private function review_add( int $id, int $post, string $status, string $case_id ): void {
		$this->review[ $id ] = array(
			'id'               => $id,
			'post'             => $post,
			'status'           => $status,
			'created_this_run' => true,
			'case'             => $case_id,
		);
	}

	/**
	 * Update one retained review status.
	 *
	 * @param int    $id Comment.
	 * @param string $status Status.
	 */
	private function review_status( int $id, string $status ): void {
		if ( isset( $this->review[ $id ] ) ) {
			$this->review[ $id ]['status'] = $status;
		}
	}

	/**
	 * Add one disposable comment to the ledger.
	 *
	 * @param int    $id Comment.
	 * @param string $kind Kind.
	 * @param int    $post Post.
	 * @param string $status Status.
	 */
	private function ephemeral_add( int $id, string $kind, int $post, string $status ): void {
		$this->ephemeral[ $id ] = array(
			'id'      => $id,
			'kind'    => $kind,
			'post'    => $post,
			'status'  => $status,
			'deleted' => false,
		);
	}

	/**
	 * Update one disposable status.
	 *
	 * @param int    $id Comment.
	 * @param string $status Status.
	 */
	private function ephemeral_status( int $id, string $status ): void {
		if ( isset( $this->ephemeral[ $id ] ) ) {
			$this->ephemeral[ $id ]['status'] = $status;
		}
	}

	/**
	 * Move one disposable comment into the intentional deletion ledger.
	 *
	 * @param int    $id Comment.
	 * @param string $case_id Case.
	 */
	private function mark_deleted( int $id, string $case_id ): void {
		if ( isset( $this->ephemeral[ $id ] ) ) {
			$this->ephemeral[ $id ]['deleted'] = true;
		}
		$this->deleted[ $id ] = array(
			'id'         => $id,
			'case'       => $case_id,
			'intent'     => 'intentional_destructive_test',
			'deleted_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Return the first retained comment created by one case.
	 *
	 * @param string $case_id Case.
	 * @return int
	 * @throws RuntimeException For a missing case.
	 */
	private function first_review_id( string $case_id ): int {
		foreach ( $this->review as $id => $row ) {
			if ( ( $row['case'] ?? '' ) === $case_id ) {
				return (int) $id;
			}
		}
		throw new RuntimeException( 'Comments review case is missing.' );
	}

	/**
	 * Return the first disposable comment of one kind.
	 *
	 * @param string $kind Kind.
	 * @return int
	 * @throws RuntimeException For a missing kind.
	 */
	private function first_ephemeral_id( string $kind ): int {
		foreach ( $this->ephemeral as $id => $row ) {
			if ( ( $row['kind'] ?? '' ) === $kind && empty( $row['deleted'] ) ) {
				return (int) $id;
			}
		}
		throw new RuntimeException( 'Comments review disposable kind is missing.' );
	}

	/**
	 * Return the retained review marker plus one case.
	 *
	 * @param string $case_id Case.
	 * @return string
	 */
	private function review_marker( string $case_id ): string {
		return '[CMT-REVIEW:' . $this->site . ':' . $this->run_id . ':' . $case_id . '] ';
	}

	/**
	 * Return the disposable marker plus one case.
	 *
	 * @param string $case_id Case.
	 * @return string
	 */
	private function ephemeral_marker( string $case_id ): string {
		return '[CMT-EPHEMERAL:' . $this->site . ':' . $this->run_id . ':' . $case_id . '] ';
	}

	/**
	 * Build retained content.
	 *
	 * @param string $case_id Case.
	 * @param string $body Body.
	 * @return string
	 */
	private function review_content( string $case_id, string $body ): string {
		return $this->review_marker( $case_id ) . $body;
	}

	/**
	 * Build disposable content.
	 *
	 * @param string $case_id Case.
	 * @param string $body Body.
	 * @return string
	 */
	private function ephemeral_content( string $case_id, string $body ): string {
		return $this->ephemeral_marker( $case_id ) . $body;
	}

	/**
	 * Check that no item exposes author_email.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return bool
	 */
	private function no_email_field( array $items ): bool {
		foreach ( $items as $item ) {
			if ( array_key_exists( 'author_email', $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Record one passed case.
	 *
	 * @param string $case_id Case.
	 * @param bool   $ok Condition.
	 * @param string $reason Safe reason.
	 * @throws CommentsReviewFailure For a failed condition.
	 */
	private function assert_case( string $case_id, bool $ok, string $reason = 'assertion_failed' ): void {
		if ( ! $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
			throw new CommentsReviewFailure( $case_id, $reason );
		}
		$this->cases[ $case_id ] = array( 'ok' => true );
	}

	/**
	 * Build the sanitized journal.
	 *
	 * @param string|null $failure_case Case.
	 * @param string|null $failure_reason Reason.
	 * @param bool        $cleanup_safe Whether key cleanup is safe.
	 * @param string      $result Result.
	 * @return array<string,mixed>
	 */
	private function journal( ?string $failure_case, ?string $failure_reason, bool $cleanup_safe, string $result ): array {
		return array(
			'schema_version'  => 1,
			'suite'           => 'comments-review',
			'site'            => $this->site,
			'run_id'          => $this->run_id,
			'retention'       => $this->retention,
			'revision'        => (string) getenv( 'XNETVN_REVISION' ),
			'fingerprint'     => (string) getenv( 'XNETVN_FINGERPRINT' ),
			'marker'          => $this->marker,
			'posts'           => $this->setup['posts'] ?? array(),
			'users'           => $this->setup['users'] ?? array(),
			'cases'           => $this->cases,
			'review'          => $this->review,
			'ephemeral'       => $this->ephemeral,
			'deleted'         => $this->deleted,
			'counts'          => $this->counts,
			'purge'           => $this->purge,
			'notes'           => $this->notes,
			'retention_state' => array(
				'review_total'          => count( $this->review ),
				'review_this_run'       => count( array_filter( $this->review, static fn( array $row ): bool => ! empty( $row['created_this_run'] ) ) ),
				'retained'              => $this->retained,
				'intentionally_deleted' => count( $this->deleted ),
			),
			'cleanup_safe'    => $cleanup_safe,
			'result'          => $result,
			'failure'         => null === $failure_case ? null : array(
				'case'   => $failure_case,
				'reason' => (string) $failure_reason,
			),
		);
	}

	/**
	 * Write sanitized results, journal and ledger.
	 *
	 * @param string|null $failure_case Case.
	 * @param string|null $failure_reason Reason.
	 * @param bool        $cleanup_safe Whether key cleanup is safe.
	 * @param string      $result Result.
	 * @throws RuntimeException For unavailable artifact persistence.
	 */
	private function write_artifacts( ?string $failure_case, ?string $failure_reason, bool $cleanup_safe, string $result ): void {
		$journal = $this->journal( $failure_case, $failure_reason, $cleanup_safe, $result );
		$results = array(
			'suite'           => 'comments-review',
			'site'            => $this->site,
			'run_id'          => $this->run_id,
			'retention'       => $this->retention,
			'result'          => $result,
			'cleanup_safe'    => $cleanup_safe,
			'passed'          => count( array_filter( $this->cases, static fn( array $row ): bool => ! empty( $row['ok'] ) ) ),
			'failed'          => null === $failure_case ? 0 : 1,
			'blocked'         => 'blocked' === $result ? 1 : 0,
			'cases'           => $this->cases,
			'failure'         => $journal['failure'],
			'retention_state' => $journal['retention_state'],
			'purge'           => $this->purge,
			'counts'          => $this->counts,
		);
		$ledger  = array(
			'schema_version' => 1,
			'review'         => array_keys( $this->review ),
			'ephemeral'      => array_keys( $this->ephemeral ),
			'deleted'        => array_keys( $this->deleted ),
		);
		foreach ( array(
			'results.json' => $results,
			'journal.json' => $journal,
			'ledger.json'  => $ledger,
		) as $file => $data ) {
			if ( false === file_put_contents( $this->run_dir . '/' . $file, Json::encode( $data ), LOCK_EX ) ) {
				throw new RuntimeException( 'Comments review artifact persistence failed.' );
			}
		}
	}

	/**
	 * Return counts without failing artifact writing.
	 *
	 * @return array<string,mixed>
	 */
	private function safe_counts(): array {
		try {
			return $this->counts_snapshot();
		} catch ( Throwable ) {
			return $this->counts;
		}
	}
}

try {
	$xnetvn_agent_hub_wp_comments_review_run  = new CommentsReviewRun();
	$xnetvn_agent_hub_wp_comments_review_exit = $xnetvn_agent_hub_wp_comments_review_run->run();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Integer exit code, not output.
	exit( $xnetvn_agent_hub_wp_comments_review_exit );
} catch ( \Throwable $xnetvn_agent_hub_wp_comments_review_failure ) {
	fwrite( STDERR, 'comments-review: setup or transport unavailable' . PHP_EOL );
	exit( 1 );
}
