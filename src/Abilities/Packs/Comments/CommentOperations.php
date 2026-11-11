<?php
/**
 * Reviewed comment operations with core capability checks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Comments;

use DateTime;
use WP_Error;
use WP_REST_Request;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;

/** Reads and creates use core REST; moderation uses core admin functions. */
final class CommentOperations {
	/** Comment types visible to managed tools. */
	public const READ_TYPES = array( 'comment', 'pingback', 'trackback' );
	/** Internal dispatcher.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;
	/** Allowlisted output.
	 *
	 * @var CommentProjection
	 */
	private CommentProjection $projection;
	/** Reviewed comment-target selection.
	 *
	 * @var CommentTargetResolver
	 */
	private CommentTargetResolver $targets;
	/** Bind pure infrastructure. */
	public function __construct() {
		$this->rest       = new RestBackedAbility();
		$this->projection = new CommentProjection( static fn( int $id ): bool => current_user_can( 'edit_comment', $id ) );
		$this->targets    = new CommentTargetResolver();
	}
	/**
	 * Run a reviewed operation after registry schema/policy validation.
	 *
	 * @param string              $name Reviewed operation.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $args ): mixed {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return R::error( 'forbidden' ); }
		switch ( $name ) {
			case 'list-comments':
				return $this->listing( $args );
			case 'get-comment':
				return $this->read_one( (int) $args['id'], (string) ( $args['content_format'] ?? 'rendered' ), (array) ( $args['fields'] ?? array() ) );
			case 'get-comment-counts':
				return $this->counts( isset( $args['post'] ) ? (int) $args['post'] : 0 );
			case 'create-comment':
				return $this->create( (int) $args['post'], (string) $args['content'], (string) ( $args['status'] ?? 'hold' ), 0, false );
			case 'reply-comment':
				return $this->reply( (int) $args['parent_id'], (string) $args['content'], (string) ( $args['status'] ?? 'hold' ), (bool) ( $args['approve_parent'] ?? false ) );
			case 'update-comment':
				return $this->update( (int) $args['id'], $args );
			case 'moderate-comment':
				return $this->moderate_one( (int) $args['id'], (string) $args['action'] );
			case 'bulk-moderate-comments':
				return $this->bulk( (array) $args['ids'], (string) $args['action'] );
			case 'delete-comment':
				return $this->remove( (int) $args['id'] );
			case 'purge-comments':
				return $this->purge( (string) $args['status'], (int) ( $args['limit'] ?? 50 ), isset( $args['before'] ) ? (string) $args['before'] : null );
			default:
				return R::error( 'invalid_params' );
		}
	}
	/**
	 * List reviewed comments with core pagination.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function listing( array $args ): mixed {
		$format     = (string) ( $args['content_format'] ?? 'rendered' );
		$fields     = (array) ( $args['fields'] ?? array() );
		$needs_edit = 'raw' === $format || in_array( 'author_email', $fields, true );
		$status_map = array(
			'approved' => 'approve',
			'hold'     => 'hold',
			'spam'     => 'spam',
			'trash'    => 'trash',
			'all'      => 'all',
		);
		$params     = array(
			'status'   => $status_map[ $args['status'] ?? 'approved' ] ?? 'approve',
			'type'     => $args['type'] ?? 'comment',
			'context'  => $needs_edit ? 'edit' : 'view',
			'page'     => $args['page'] ?? 1,
			'per_page' => $args['per_page'] ?? 20,
		);
		foreach ( array( 'post', 'parent', 'author' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$params[ $key ] = $args[ $key ]; }
		}
		foreach ( array( 'author_email', 'search', 'orderby', 'order' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$params[ $key ] = $args[ $key ]; }
		}
		if ( 'date_gmt' === ( $params['orderby'] ?? '' ) ) {
			$params['orderby'] = 'date'; }
		foreach ( array( 'after', 'before' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$params[ $key ] = $this->site_time( (string) $args[ $key ] ); }
		}
		$max_chars = (int) ( $args['content_max_chars'] ?? 500 );
		$response  = $this->rest->dispatch( 'GET', '/wp/v2/comments', $params );
		if ( is_wp_error( $response ) ) {
			return $response; }
		$rows = $response->get_data();
		if ( $needs_edit ) {
			foreach ( $rows as $row ) {
				if ( ! isset( $row['id'] ) || ! current_user_can( 'edit_comment', (int) $row['id'] ) ) {
					return R::error( 'forbidden' ); }
			}
		}
		$items = array();
		foreach ( $rows as $row ) {
			try {
				$items[] = $this->projection->project( $row, $format, $fields, $max_chars );
			} catch ( \DomainException ) {
				return R::error( 'forbidden' );
			} catch ( \Throwable ) {
				return R::error( 'internal_error' ); }
		}
		$result = $this->rest->collection( $response, (int) $params['page'], static fn( array $row ): array => $row );
		if ( ! is_wp_error( $result ) ) {
			$result['items'] = $items;
		} return $result;
	}
	/**
	 * Read one reviewed comment.
	 *
	 * @param int           $id Comment.
	 * @param string        $format Representation.
	 * @param array<string> $fields Narrowing projection.
	 * @return mixed
	 */
	private function read_one( int $id, string $format, array $fields ): mixed {
		$comment = get_comment( $id );
		if ( null === $comment || ! $this->readable_type( $comment->comment_type ) ) {
			return R::error( 'not_found' ); }
		$needs_edit = 'raw' === $format || in_array( 'author_email', $fields, true );
		$response   = $this->rest->dispatch( 'GET', '/wp/v2/comments/' . $id, array( 'context' => $needs_edit ? 'edit' : 'view' ) );
		if ( is_wp_error( $response ) ) {
			return $response; }
		try {
			return $this->projection->project( $response->get_data(), $format, $fields );
		} catch ( \DomainException ) {
			return R::error( 'forbidden' );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' ); }
	}
	/**
	 * Count comments by status for the site or one post.
	 *
	 * @param int $post_id Optional post.
	 * @return mixed
	 */
	private function counts( int $post_id ): mixed {
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( null === $post ) {
				return R::error( 'not_found' ); }
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return R::error( 'forbidden' ); }
		}
		$counts = wp_count_comments( $post_id > 0 ? $post_id : 0 );
		return array(
			'approved'     => (int) $counts->approved,
			'hold'         => (int) $counts->moderated,
			'spam'         => (int) $counts->spam,
			'trash'        => (int) $counts->trash,
			'post_trashed' => (int) $counts->{'post-trashed'},
			'all'          => (int) $counts->approved + (int) $counts->moderated,
			'total'        => (int) $counts->total_comments,
		);
	}
	/**
	 * Create a comment on a reviewed post.
	 *
	 * @param int    $post_id Post.
	 * @param string $content Body.
	 * @param string $status Requested visibility.
	 * @param int    $parent_id Parent comment, zero for none.
	 * @param bool   $parent_approved Whether the parent was just approved.
	 * @return mixed
	 */
	private function create( int $post_id, string $content, string $status, int $parent_id, bool $parent_approved ): mixed {
		$checked = $this->check_content( $content );
		if ( is_wp_error( $checked ) ) {
			return $checked; }
		$post = $this->targets->resolve( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post; }
		$params = array(
			'post'    => $post_id,
			'content' => $content,
		);
		if ( $parent_id > 0 ) {
			$params['parent'] = $parent_id; }
		$force_hold = 'hold' === $status;
		$response   = $this->scoped_create(
			$force_hold,
			function () use ( $params ): mixed {
				$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
				$request->set_body_params( $params );
				return $this->rest->send( $request, 'comment' );
			}
		);
		if ( is_wp_error( $response ) ) {
			return $response; }
		if ( ! $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ) {
			return R::error( 'partial_failure' ); }
		return $response->project(
			function ( array $row ) use ( $response, $parent_id, $parent_approved ): mixed {
				try {
					$item = $this->projection->project( $row );
				} catch ( \Throwable ) {
					return new PartialFailure( $response->object_id, true );
				}
				if ( $parent_id > 0 ) {
					$item['parent_approved'] = $parent_approved; }
				return $item;
			}
		);
	}
	/**
	 * Reply to a reviewed comment.
	 *
	 * @param int    $parent_id Parent.
	 * @param string $content Body.
	 * @param string $status Requested visibility.
	 * @param bool   $approve_parent Approve a held parent first.
	 * @return mixed
	 */
	private function reply( int $parent_id, string $content, string $status, bool $approve_parent ): mixed {
		$parent_comment = get_comment( $parent_id );
		if ( null === $parent_comment || ! $this->readable_type( $parent_comment->comment_type ) ) {
			return R::error( 'not_found' ); }
		if ( ! current_user_can( 'edit_comment', $parent_id ) ) {
			return R::error( 'forbidden' ); }
		$parent_status = CommentStatus::normalize( (string) $parent_comment->comment_approved );
		if ( null === $parent_status || 'spam' === $parent_status || 'trash' === $parent_status ) {
			return R::error( 'invalid_state' ); }
		if ( 'hold' === $parent_status && ! $approve_parent ) {
			return R::error( 'invalid_state' ); }
		$approved = false;
		if ( 'hold' === $parent_status && $approve_parent ) {
			if ( ! wp_set_comment_status( $parent_id, 'approve', true ) ) {
				return R::error( 'internal_error' ); }
			$approved = true;
		}
		$result = $this->create( (int) $parent_comment->comment_post_ID, $content, $status, $parent_id, $approved );
		if ( is_wp_error( $result ) && $approved && 'xnetvn_agent_hub_wp_partial_failure' !== $result->get_error_code() ) {
			$check = get_comment( $parent_id );
			if ( null !== $check ) {
				return new PartialFailure( $parent_id, false ); }
		}
		return $result;
	}
	/**
	 * Update reviewed comment text and author display fields.
	 *
	 * @param int                 $id Comment.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function update( int $id, array $args ): mixed {
		$comment = get_comment( $id );
		if ( null === $comment || ! $this->readable_type( $comment->comment_type ) ) {
			return R::error( 'not_found' ); }
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return R::error( 'forbidden' ); }
		$data = array( 'comment_ID' => $id );
		if ( array_key_exists( 'content', $args ) ) {
			$checked = $this->check_content( (string) $args['content'] );
			if ( is_wp_error( $checked ) ) {
				return $checked; }
			$data['comment_content'] = (string) $args['content'];
		}
		if ( array_key_exists( 'author_name', $args ) ) {
			$name = (string) $args['author_name'];
			if ( '' === trim( $name ) || strlen( $name ) > 245 ) {
				return '' === trim( $name ) ? R::error( 'invalid_params' ) : R::error( 'payload_too_large' ); }
			$data['comment_author'] = $name;
		}
		if ( array_key_exists( 'author_url', $args ) ) {
			$url = (string) $args['author_url'];
			if ( '' !== $url && ( strlen( $url ) > 200 || 1 !== preg_match( '#\Ahttps?://#i', $url ) ) ) {
				return R::error( strlen( $url ) > 200 ? 'payload_too_large' : 'invalid_params' ); }
			$data['comment_author_url'] = $url;
		}
		if ( 1 === count( $data ) ) {
			return R::error( 'invalid_params' ); }
		$updated = $this->with_forced_kses(
			static function () use ( $data ): mixed {
				return wp_update_comment( wp_slash( $data ), true );
			}
		);
		if ( is_wp_error( $updated ) ) {
			return R::sanitize( $updated ); }
		if ( false === $updated ) {
			return R::error( 'internal_error' ); }
		return MutationResult::project(
			$id,
			false,
			fn(): mixed => $this->read_one( $id, 'rendered', array() )
		);
	}
	/**
	 * Moderate one comment through core admin functions.
	 *
	 * @param int    $id Comment.
	 * @param string $action Moderation action.
	 * @return mixed
	 */
	private function moderate_one( int $id, string $action ): mixed {
		$comment = get_comment( $id );
		if ( null === $comment || ! $this->readable_type( $comment->comment_type ) ) {
			return R::error( 'not_found' ); }
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return R::error( 'forbidden' ); }
		$previous = CommentStatus::normalize( (string) $comment->comment_approved );
		if ( null === $previous ) {
			return R::error( 'not_found' ); }
		$planned = CommentStatus::apply( $previous, $action );
		if ( is_wp_error( $planned ) ) {
			return $planned; }
		[$target, $changed] = $planned;
		if ( ! $changed && 'restore' !== $target ) {
			return MutationResult::project(
				$id,
				false,
				static fn(): array => array(
					'id'              => $id,
					'status'          => $target,
					'previous_status' => $previous,
					'changed'         => false,
				)
			);
		}
		if ( 'trash' === $target && ! $this->trash_available() ) {
			return R::error( 'unsupported_operation' ); }
		$ok = $this->apply_transition( $id, $action, CommentStatus::to_core( $target ) );
		if ( ! $ok ) {
			return R::error( 'internal_error' ); }
		$after = get_comment( $id );
		if ( null === $after ) {
			return new PartialFailure( $id, false ); }
		$status = CommentStatus::normalize( (string) $after->comment_approved );
		if ( null === $status ) {
			return new PartialFailure( $id, false ); }
		if ( 'restore' === $target && $status === $previous ) {
			return new PartialFailure( $id, false ); }
		return MutationResult::project(
			$id,
			false,
			static fn(): array => array(
				'id'              => $id,
				'status'          => $status,
				'previous_status' => $previous,
				'changed'         => true,
			)
		);
	}
	/**
	 * Moderate a bounded batch without stopping on single-item errors.
	 *
	 * @param array<int> $ids Comments.
	 * @param string     $action Moderation action.
	 * @return mixed
	 */
	private function bulk( array $ids, string $action ): mixed {
		if ( ! $ids || count( $ids ) > 50 ) {
			return R::error( 'invalid_params' ); }
		$result = array(
			'updated'   => array(),
			'unchanged' => array(),
			'failed'    => array(),
		);
		wp_defer_comment_counting( true );
		try {
			foreach ( $ids as $target ) {
				try {
					$item = $this->moderate_one( (int) $target, $action );
				} catch ( \Throwable ) {
					$item = R::error( 'partial_failure' ); }
				if ( is_wp_error( $item ) ) {
					$result['failed'][] = array(
						'id'   => (int) $target,
						'code' => R::reason( (string) $item->get_error_code() ),
					);
					continue;
				}
				if ( $item instanceof \XNetVN\AgentHubWP\Abilities\MutationResult ) {
					$data = $item->data;
					if ( ! empty( $data['changed'] ) ) {
						$result['updated'][] = (int) $target;
					} else {
						$result['unchanged'][] = (int) $target;
					}
					continue;
				}
				$result['failed'][] = array(
					'id'   => (int) $target,
					'code' => 'partial_failure',
				);
			}
		} finally {
			wp_defer_comment_counting( false );
		}
		return $result;
	}
	/**
	 * Permanently delete a comment already in spam or trash.
	 *
	 * @param int $id Comment.
	 * @return mixed
	 */
	private function remove( int $id ): mixed {
		$comment = get_comment( $id );
		if ( null === $comment || ! $this->readable_type( $comment->comment_type ) ) {
			return R::error( 'not_found' ); }
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return R::error( 'forbidden' ); }
		$status = CommentStatus::normalize( (string) $comment->comment_approved );
		if ( 'spam' !== $status && 'trash' !== $status ) {
			return R::error( 'invalid_state' ); }
		if ( ! wp_delete_comment( $id, true ) ) {
			return R::error( 'internal_error' ); }
		return MutationResult::project(
			$id,
			false,
			static fn(): array => array(
				'id'      => $id,
				'deleted' => true,
			)
		);
	}
	/**
	 * Delete a bounded batch of spam or trash older than a fixed cutoff.
	 *
	 * @param string      $status Spam or trash.
	 * @param int         $limit Maximum deletions.
	 * @param string|null $before Reused cutoff, null for now.
	 * @return mixed
	 */
	private function purge( string $status, int $limit, ?string $before ): mixed {
		if ( $limit < 1 || $limit > 100 ) {
			return R::error( 'invalid_params' ); }
		$now    = time();
		$cutoff = null !== $before ? strtotime( $before ) : $now;
		if ( false === $cutoff ) {
			return R::error( 'invalid_params' ); }
		$cutoff     = min( $cutoff, $now );
		$cutoff_sql = gmdate( 'Y-m-d H:i:s', $cutoff );
		$cutoff_iso = gmdate( 'Y-m-d\TH:i:s\Z', $cutoff );
		$deadline   = microtime( true ) + 20;
		$deleted    = 0;
		$skipped    = 0;
		$failed     = 0;
		$query_args = array(
			'status'        => $status,
			'type__in'      => self::READ_TYPES,
			'date_query'    => array(
				array(
					'column'    => 'comment_date_gmt',
					'before'    => $cutoff_sql,
					'inclusive' => false,
				),
			),
			'orderby'       => 'comment_ID',
			'order'         => 'ASC',
			'number'        => $limit,
			'fields'        => 'ids',
			'no_found_rows' => false,
		);
		$query      = new \WP_Comment_Query( $query_args );
		$ids        = $query->get_comments();
		wp_defer_comment_counting( true );
		try {
			foreach ( (array) $ids as $candidate ) {
				if ( microtime( true ) > $deadline ) {
					break; }
				if ( ! is_int( $candidate ) || $candidate < 1 ) {
					++$failed;
					continue; }
				$target = $candidate;
				if ( ! current_user_can( 'edit_comment', $target ) ) {
					++$skipped;
					continue; }
				if ( wp_delete_comment( $target, true ) ) {
					++$deleted;
				} else {
					++$failed; }
			}
		} finally {
			wp_defer_comment_counting( false );
		}
		$remaining_query = new \WP_Comment_Query(
			array_merge(
				$query_args,
				array(
					'number' => 0,
					'fields' => 'ids',
					'count'  => true,
				)
			)
		);
		$remaining_count = $remaining_query->get_comments();
		$remaining       = is_int( $remaining_count ) ? $remaining_count : 0;
		return array(
			'status'    => $status,
			'deleted'   => $deleted,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'remaining' => $remaining,
			'cutoff'    => $cutoff_iso,
		);
	}
	/**
	 * Whether the trash transition is available on this site.
	 *
	 * @return bool
	 */
	private function trash_available(): bool {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
			return false; }
		try {
			$allowed = apply_filters( 'rest_comment_trashable', true, get_comment( 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core trash gate.
		} catch ( \Throwable ) {
			return false;
		}
		return true === $allowed;
	}
	/**
	 * Apply one core state transition.
	 *
	 * @param int    $id Comment.
	 * @param string $action Requested action.
	 * @param string $target Planned target or restore marker.
	 * @return bool
	 */
	private function apply_transition( int $id, string $action, string $target ): bool {
		switch ( $action ) {
			case 'approve':
			case 'hold':
				if ( ! in_array( $target, array( 'approve', 'hold' ), true ) ) {
					return false; }
				return (bool) wp_set_comment_status( $id, $target, true );
			case 'spam':
				return (bool) wp_spam_comment( $id );
			case 'trash':
				return (bool) wp_trash_comment( $id );
			case 'unspam':
				return (bool) wp_unspam_comment( $id );
			case 'untrash':
				return (bool) wp_untrash_comment( $id );
			default:
				return false;
		}
	}
	/**
	 * Whether a stored comment type is managed by these tools.
	 *
	 * @param string $type Stored type.
	 * @return bool
	 */
	private function readable_type( string $type ): bool {
		return '' === $type || in_array( $type, self::READ_TYPES, true );
	}
	/**
	 * Validate comment body length in bytes.
	 *
	 * @param string $content Body.
	 * @return bool|WP_Error True, or a safe error.
	 */
	private function check_content( string $content ): bool|WP_Error {
		if ( '' === trim( $content ) ) {
			return R::error( 'invalid_params' ); }
		if ( strlen( $content ) > 65525 ) {
			return R::error( 'payload_too_large' ); }
		return true;
	}
	/**
	 * Convert an explicit-offset timestamp to site time without offset.
	 *
	 * @param string $iso Timestamp with timezone.
	 * @return string
	 */
	private function site_time( string $iso ): string {
		$date = new DateTime( $iso );
		$date->setTimezone( wp_timezone() );
		return $date->format( 'Y-m-d H:i:s' );
	}
	/**
	 * Run comment creation with call-scoped approval and KSES filters.
	 *
	 * @param bool     $force_hold Hold content the core would auto-approve.
	 * @param callable $operation Creation.
	 * @return mixed
	 */
	private function scoped_create( bool $force_hold, callable $operation ): mixed {
		$approval   = static function ( mixed $approved ) use ( $force_hold ): mixed {
			if ( $force_hold && ( 1 === $approved || '1' === $approved ) ) {
				return 0; }
			return $approved;
		};
		$kses_added = false;
		if ( ! has_filter( 'pre_comment_content', 'wp_filter_kses' ) ) {
			add_filter( 'pre_comment_content', 'wp_filter_kses' );
			$kses_added = true;
		}
		add_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );
		try {
			return $operation();
		} finally {
			remove_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );
			if ( $kses_added ) {
				remove_filter( 'pre_comment_content', 'wp_filter_kses' ); }
		}
	}
	/**
	 * Run a direct comment write with forced KSES filtering.
	 *
	 * @param callable $operation Write.
	 * @return mixed
	 */
	private function with_forced_kses( callable $operation ): mixed {
		$added = false;
		if ( ! has_filter( 'pre_comment_content', 'wp_filter_kses' ) ) {
			add_filter( 'pre_comment_content', 'wp_filter_kses' );
			$added = true;
		}
		try {
			return $operation();
		} finally {
			if ( $added ) {
				remove_filter( 'pre_comment_content', 'wp_filter_kses' ); }
		}
	}
}
