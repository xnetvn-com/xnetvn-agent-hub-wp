<?php
/**
 * Machine content operations with explicit object authorization.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use Closure;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\SiteTaxonomyOperations;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;

/** Never borrows a WordPress user, a cookie identity or the human content path. */
final class SiteContentOperations {

	/** The nineteen reviewed machine content names in pack order. */
	public const NAMES = array(
		'xnetvn/list-post-types',
		'xnetvn/list-post-statuses',
		'xnetvn/list-posts',
		'xnetvn/get-post',
		'xnetvn/create-post',
		'xnetvn/update-post',
		'xnetvn/trash-post',
		'xnetvn/restore-post',
		'xnetvn/delete-post',
		'xnetvn/duplicate-post',
		'xnetvn/bulk-update-posts',
		'xnetvn/list-revisions',
		'xnetvn/get-revision',
		'xnetvn/restore-revision',
		'xnetvn/set-featured-media',
		'xnetvn/stick-post',
		'xnetvn/get-post-meta',
		'xnetvn/update-post-meta',
		'xnetvn/search-content',
	);

	/** Reviewed base capability class for the fixed editorial profile. */
	private const EDITORIAL_CLASS = array(
		'post' => array(
			'edit_post'              => 'edit_post',
			'read_post'              => 'read_post',
			'delete_post'            => 'delete_post',
			'edit_posts'             => 'edit_posts',
			'edit_others_posts'      => 'edit_others_posts',
			'delete_posts'           => 'delete_posts',
			'publish_posts'          => 'publish_posts',
			'read_private_posts'     => 'read_private_posts',
			'read'                   => 'read',
			'delete_private_posts'   => 'delete_private_posts',
			'delete_published_posts' => 'delete_published_posts',
			'delete_others_posts'    => 'delete_others_posts',
			'edit_private_posts'     => 'edit_private_posts',
			'edit_published_posts'   => 'edit_published_posts',
			'create_posts'           => 'edit_posts',
		),
		'page' => array(
			'edit_post'              => 'edit_page',
			'read_post'              => 'read_page',
			'delete_post'            => 'delete_page',
			'edit_posts'             => 'edit_pages',
			'edit_others_posts'      => 'edit_others_pages',
			'delete_posts'           => 'delete_pages',
			'publish_posts'          => 'publish_pages',
			'read_private_posts'     => 'read_private_pages',
			'read'                   => 'read',
			'delete_private_posts'   => 'delete_private_pages',
			'delete_published_posts' => 'delete_published_pages',
			'delete_others_posts'    => 'delete_others_pages',
			'edit_private_posts'     => 'edit_private_pages',
			'edit_published_posts'   => 'edit_published_pages',
			'create_posts'           => 'edit_pages',
		),
	);

	/** Reviewed readable statuses; trash, auto-draft and inherit are excluded. */
	private const READ_STATUSES = array( 'draft', 'pending', 'private', 'publish', 'future' );

	/** Fixed reviewed status labels. */
	private const STATUS_LABELS = array(
		'draft'   => 'Draft',
		'pending' => 'Pending Review',
		'private' => 'Privately Published',
		'publish' => 'Published',
		'future'  => 'Scheduled',
	);

	/** Allowlisted mutable properties. */
	private const CHANGE_FIELDS = array(
		'title',
		'content',
		'excerpt',
		'status',
		'slug',
		'categories',
		'tags',
		'featured_media',
		'meta',
		'date',
		'sticky',
		'parent',
		'menu_order',
		'template',
		'comment_status',
		'ping_status',
		'format',
	);

	/** Runtime type boundary.
	 *
	 * @var PostTypeResolver
	 */
	private PostTypeResolver $types;

	/** Scoped editorial HTML boundary.
	 *
	 * @var SafeEditorialHtml
	 */
	private SafeEditorialHtml $html;

	/** Attribution-only eligibility guard.
	 *
	 * @var EditorialAuthors
	 */
	private EditorialAuthors $authors;

	/** Shared taxonomy assignment seam.
	 *
	 * @var Closure(int,string,array<int>,bool):(array<string,mixed>|WP_Error)
	 */
	private Closure $assign_terms;

	/**
	 * Bind optional reviewed dependencies.
	 *
	 * @param PostTypeResolver|null  $types Runtime type boundary.
	 * @param SafeEditorialHtml|null $html Scoped HTML boundary.
	 * @param EditorialAuthors|null  $authors Attribution guard.
	 * @param Closure|null           $assign_terms Shared taxonomy assignment seam.
	 */
	public function __construct( ?PostTypeResolver $types = null, ?SafeEditorialHtml $html = null, ?EditorialAuthors $authors = null, ?Closure $assign_terms = null ) {
		$this->types        = $types ?? new PostTypeResolver();
		$this->html         = $html ?? new SafeEditorialHtml();
		$this->authors      = $authors ?? new EditorialAuthors();
		$this->assign_terms = $assign_terms ?? static fn( int $post_id, string $taxonomy, array $term_ids, bool $append ): array|WP_Error => SiteTaxonomyOperations::assign_verified_terms( $post_id, $taxonomy, $term_ids, $append );
	}

	/**
	 * Return the only machine handler for one canonical name.
	 *
	 * @param string $canonical_name Canonical operation name.
	 * @return Closure(array<string,mixed>,SiteAuthContext):mixed
	 */
	public static function handler( string $canonical_name ): Closure {
		return static fn( array $arguments, SiteAuthContext $context ): mixed => ( new self() )->execute( $canonical_name, $arguments, $context );
	}

	/**
	 * Run one reviewed machine operation without a human closure or a user.
	 *
	 * @param string              $canonical_name Canonical operation name.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	public function execute( string $canonical_name, array $arguments, SiteAuthContext $context ): mixed {
		if ( ! in_array( $canonical_name, self::NAMES, true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( $context->blog_id() !== get_current_blog_id() || 0 !== get_current_user_id() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		try {
			return match ( $canonical_name ) {
				'xnetvn/list-post-types'    => $this->list_post_types(),
				'xnetvn/list-post-statuses' => $this->list_post_statuses(),
				'xnetvn/list-posts'         => $this->list_posts( $arguments ),
				'xnetvn/get-post'           => $this->get_post( $arguments ),
				'xnetvn/create-post'        => $this->create_post( $arguments ),
				'xnetvn/update-post'        => $this->update_post( $arguments ),
				'xnetvn/trash-post'         => $this->trash_post( $arguments ),
				'xnetvn/restore-post'       => $this->restore_post( $arguments ),
				'xnetvn/delete-post'        => $this->delete_post( $arguments ),
				'xnetvn/duplicate-post'     => $this->duplicate_post( $arguments ),
				'xnetvn/bulk-update-posts'  => $this->bulk_update( $arguments ),
				'xnetvn/list-revisions'     => $this->list_revisions( $arguments ),
				'xnetvn/get-revision'       => $this->get_revision( $arguments ),
				'xnetvn/restore-revision'   => $this->restore_revision( $arguments ),
				'xnetvn/set-featured-media' => $this->set_featured_media( $arguments ),
				'xnetvn/stick-post'         => $this->stick_post( $arguments ),
				'xnetvn/get-post-meta'      => $this->get_post_meta( $arguments ),
				'xnetvn/update-post-meta'   => $this->update_post_meta( $arguments ),
				'xnetvn/search-content'     => $this->search_content( $arguments ),
			};
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * List the fixed editorial types only.
	 *
	 * @return array<string,mixed>
	 */
	private function list_post_types(): array {
		$items = array();
		foreach ( get_post_types( array(), 'objects' ) as $slug => $type ) {
			if ( ! is_string( $slug ) || ! $type instanceof WP_Post_Type ) {
				continue;
			}
			$checked = $this->editorial_type( $slug );
			if ( is_wp_error( $checked ) ) {
				continue;
			}
			$supports = array_keys( get_all_post_type_supports( $slug ) );
			sort( $supports, SORT_STRING );
			$items[] = array(
				'slug'         => $slug,
				'name'         => (string) ( $checked->labels->name ?? $checked->label ),
				'rest_base'    => '' !== (string) $checked->rest_base ? (string) $checked->rest_base : $slug,
				'hierarchical' => (bool) $checked->hierarchical,
				'supports'     => array_values( array_map( 'strval', $supports ) ),
			);
		}
		usort( $items, static fn( array $left, array $right ): int => strcmp( (string) $left['slug'], (string) $right['slug'] ) );
		return array( 'items' => $items );
	}

	/**
	 * List the five reviewed statuses only.
	 *
	 * @return array<string,mixed>
	 */
	private function list_post_statuses(): array {
		$items = array();
		foreach ( self::STATUS_LABELS as $slug => $name ) {
			$items[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * List persisted rows through the fixed object predicate.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function list_posts( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$pagination = $this->pagination( $arguments );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		$statuses = $arguments['status'] ?? array( 'publish' );
		if ( ! is_array( $statuses ) || array() === $statuses || count( $statuses ) > 10 ) {
			return R::error( 'invalid_params' );
		}
		$statuses = array_values( $statuses );
		foreach ( $statuses as $status ) {
			if ( ! is_string( $status ) || ! in_array( $status, self::READ_STATUSES, true ) ) {
				return R::error( 'invalid_params' );
			}
		}
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$fields = $this->projection_fields( $arguments );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$query = array(
			'post_type'           => $slug,
			'post_status'         => $statuses,
			'paged'               => $pagination['page'],
			'posts_per_page'      => $pagination['per_page'],
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		);
		if ( array_key_exists( 'search', $arguments ) ) {
			if ( ! is_string( $arguments['search'] ) || mb_strlen( $arguments['search'] ) > 200 ) {
				return R::error( 'invalid_params' );
			}
			$query['s'] = $arguments['search'];
		}
		if ( array_key_exists( 'author', $arguments ) ) {
			$authors = $this->positive_ids( $arguments['author'], 20 );
			if ( is_wp_error( $authors ) ) {
				return $authors;
			}
			$query['author__in'] = $authors;
		}
		$tax_query = $this->taxonomy_query( $slug, $arguments );
		if ( is_wp_error( $tax_query ) ) {
			return $tax_query;
		}
		if ( array() !== $tax_query ) {
			$query['tax_query'] = $tax_query;
		}
		$date_query = $this->date_query( $arguments );
		if ( is_wp_error( $date_query ) ) {
			return $date_query;
		}
		if ( array() !== $date_query ) {
			$query['date_query'] = $date_query;
		}
		$order = $arguments['order'] ?? 'desc';
		if ( ! is_string( $order ) || ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		$orderby = $arguments['orderby'] ?? 'date';
		if ( ! is_string( $orderby ) || ! in_array( $orderby, array( 'date', 'modified', 'title', 'id', 'slug', 'author', 'relevance', 'menu_order' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'relevance' === $orderby && ! isset( $query['s'] ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'menu_order' === $orderby && ! post_type_supports( $slug, 'page-attributes' ) ) {
			return R::error( 'invalid_params' );
		}
		$query['orderby'] = match ( $orderby ) {
			'id'    => 'ID',
			'slug'  => 'name',
			'date'  => 'date',
			default => $orderby,
		};
		$query['order'] = $order;

		$result      = new \WP_Query( $query );
		$items       = array();
		$removed     = 0;
		$total_found = (int) $result->found_posts;
		foreach ( (array) $result->posts as $post ) {
			if ( ! $post instanceof WP_Post || ! $this->readable( (int) $post->ID, $slug, false ) ) {
				++$removed;
				continue;
			}
			$projected = $this->project_post( $post, $slug, $format, $fields );
			if ( is_wp_error( $projected ) ) {
				return $projected;
			}
			$items[] = $projected;
		}
		$total = max( 0, $total_found - $removed );
		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 0,
			'page'        => $pagination['page'],
		);
	}

	/**
	 * Read one persisted post, refusing a protected body.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function get_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$fields = $this->projection_fields( $arguments );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$password = '' !== (string) $post->post_password;
		if ( 'raw' === $format ) {
			if ( $password || ! $this->readable( $id, $slug, true ) ) {
				return R::error( $password ? 'forbidden' : 'not_found' );
			}
			return $this->project_post( $post, $slug, $format, $fields );
		}
		if ( ! $this->visible( $id, $slug ) ) {
			return R::error( 'not_found' );
		}
		return $this->project_post( $post, $slug, $format, $fields );
	}

	/**
	 * Create one post with an eligible attribution author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function create_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$changes = $arguments;
		unset( $changes['post_type'], $changes['author'] );
		$prepared = $this->prepare_changes( $type, $changes, 0 );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$fields = $prepared['fields'];
		if ( ! array_intersect( array_keys( $fields ), array( 'post_title', 'post_content', 'post_excerpt' ) ) ) {
			return R::error( 'invalid_params' );
		}
		$author = $arguments['author'] ?? null;
		if ( ! is_int( $author ) || $author < 1 ) {
			return R::error( 'invalid_params' );
		}
		$eligible = $this->authors->require_eligible( $author );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}
		$fields['post_type']   = $slug;
		$fields['post_author'] = $author;
		if ( ! isset( $fields['post_status'] ) ) {
			$fields['post_status'] = 'draft';
		}
		$id = $this->insert( $fields );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$verified = $this->verify_post( $id, $slug, $fields, true );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$effects = $this->apply_post_effects( $id, $slug, $prepared, true );
		if ( is_wp_error( $effects ) ) {
			return $effects;
		}
		return MutationResult::project(
			$id,
			true,
			function () use ( $id, $slug ): array|WP_Error {
				$stored = get_post( $id );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Update one post while preserving the stored author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function update_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		if ( array_key_exists( 'expected_modified', $arguments ) ) {
			$conflict = $this->conflict( $post, $arguments['expected_modified'] );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}
		$changes = $arguments;
		unset( $changes['post_type'], $changes['id'], $changes['expected_modified'] );
		if ( array() === $changes ) {
			return R::error( 'invalid_params' );
		}
		$prepared = $this->prepare_changes( $type, $changes, $id );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$fields = $prepared['fields'];
		if ( array() !== $fields ) {
			$written = $this->html->run( fn(): mixed => wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $fields ) ), true ) );
			if ( is_wp_error( $written ) ) {
				return R::error( 'internal_error' );
			}
		}
		$verified = $this->verify_post( $id, $slug, $fields, false );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$effects = $this->apply_post_effects( $id, $slug, $prepared, false );
		if ( is_wp_error( $effects ) ) {
			return $effects;
		}
		return MutationResult::project(
			$id,
			false,
			function () use ( $id, $slug ): array|WP_Error {
				$stored = get_post( $id );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Trash one post without ever escalating to permanent deletion.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function trash_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, true ) ) {
			return R::error( 'forbidden' );
		}
		if ( defined( 'EMPTY_TRASH_DAYS' ) && 0 === (int) EMPTY_TRASH_DAYS ) {
			return R::error( 'unsupported_operation' );
		}
		$trashed = wp_trash_post( $id );
		if ( false === $trashed || null === $trashed ) {
			return R::error( 'internal_error' );
		}
		$stored = get_post( $id );
		if ( ! $stored instanceof WP_Post || 'trash' !== $stored->post_status ) {
			return new PartialFailure( $id, false );
		}
		return MutationResult::project(
			$id,
			false,
			static fn(): array => array(
				'id'     => $id,
				'status' => 'trash',
			)
		);
	}

	/**
	 * Restore one trashed post to a reviewed status.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function restore_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' !== $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$status = $arguments['status'] ?? 'draft';
		if ( ! is_string( $status ) || ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		$written = $this->html->run(
			fn(): mixed => wp_update_post(
				wp_slash(
					array(
						'ID'          => $id,
						'post_status' => $status,
					)
				),
				true
			)
		);
		if ( is_wp_error( $written ) ) {
			return R::error( 'internal_error' );
		}
		$verified = $this->verify_post( $id, $slug, array( 'post_status' => $status ), false );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		return MutationResult::project(
			$id,
			false,
			function () use ( $id, $slug ): array|WP_Error {
				$stored = get_post( $id );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Permanently delete one post after the site-page guard.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function delete_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( $this->protected_page( $id, true ) ) {
			return R::error( 'forbidden' );
		}
		$deleted = wp_delete_post( $id, true );
		if ( false === $deleted || null === $deleted ) {
			return R::error( 'internal_error' );
		}
		if ( null !== get_post( $id ) ) {
			return new PartialFailure( $id, false );
		}
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
	 * Duplicate one readable source with a separately eligible author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function duplicate_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$source = get_post( $id );
		if ( ! $source instanceof WP_Post || $source->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		if ( ! $this->readable( $id, $slug, false ) ) {
			return R::error( 'not_found' );
		}
		$author = $arguments['author'] ?? null;
		if ( ! is_int( $author ) || $author < 1 ) {
			return R::error( 'invalid_params' );
		}
		$changes  = array(
			'status'  => $arguments['status'] ?? 'draft',
			'title'   => (string) $source->post_title,
			'content' => (string) $source->post_content,
			'excerpt' => (string) $source->post_excerpt,
		);
		$terms    = $this->source_terms( $id, $slug );
		$changes += $terms;
		$featured = (int) get_post_thumbnail_id( $id );
		if ( $featured > 0 ) {
			$changes['featured_media'] = $featured;
		}
		$template = get_page_template_slug( $id );
		if ( is_string( $template ) && '' !== $template ) {
			$changes['template'] = $template;
		}
		$copyable = $this->copyable_meta( $id, $slug );
		if ( array() !== $copyable ) {
			$changes['meta'] = $copyable;
		}
		if ( post_type_supports( $slug, 'page-attributes' ) ) {
			$changes['parent']     = (int) $source->post_parent;
			$changes['menu_order'] = (int) $source->menu_order;
		}
		$prepared = $this->prepare_changes( $type, $changes, 0 );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$eligible = $this->authors->require_eligible( $author );
		if ( is_wp_error( $eligible ) ) {
			return $eligible;
		}
		$fields                = $prepared['fields'];
		$fields['post_type']   = $slug;
		$fields['post_author'] = $author;
		$id_new                = $this->insert( $fields );
		if ( is_wp_error( $id_new ) ) {
			return $id_new;
		}
		$verified = $this->verify_post( $id_new, $slug, $fields, true );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$effects = $this->apply_post_effects( $id_new, $slug, $prepared, true );
		if ( is_wp_error( $effects ) ) {
			return $effects;
		}
		return MutationResult::project(
			$id_new,
			true,
			function () use ( $id_new, $slug ): array|WP_Error {
				$stored = get_post( $id_new );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Update up to fifty posts with a per-item attribution recheck.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function bulk_update( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$ids = $this->positive_ids( $arguments['ids'] ?? array(), 50, true );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$changes = $arguments['changes'] ?? null;
		if ( ! is_array( $changes ) || array() === $changes ) {
			return R::error( 'invalid_params' );
		}
		if ( array() !== array_diff( array_keys( $changes ), array( 'status', 'categories', 'tags', 'sticky', 'author' ) ) ) {
			return R::error( 'invalid_params' );
		}
		$result = array(
			'updated' => array(),
			'failed'  => array(),
		);
		foreach ( $ids as $id ) {
			$item_changes = $changes;
			$author       = null;
			if ( array_key_exists( 'author', $item_changes ) ) {
				$author = $item_changes['author'];
				unset( $item_changes['author'] );
				if ( ! is_int( $author ) || $author < 1 ) {
					$result['failed'][] = array(
						'id'   => $id,
						'code' => 'invalid_params',
					);
					continue;
				}
				$eligible = $this->authors->require_eligible( $author );
				if ( is_wp_error( $eligible ) ) {
					$result['failed'][] = array(
						'id'   => $id,
						'code' => R::reason( (string) $eligible->get_error_code() ),
					);
					continue;
				}
			}
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => 'not_found',
				);
				continue;
			}
			if ( 'trash' === $post->post_status ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => 'invalid_state',
				);
				continue;
			}
			if ( $this->protected_page( $id, false ) ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => 'forbidden',
				);
				continue;
			}
			$prepared = $this->prepare_changes( $type, $item_changes, $id );
			if ( is_wp_error( $prepared ) ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => R::reason( (string) $prepared->get_error_code() ),
				);
				continue;
			}
			$fields = $prepared['fields'];
			if ( null !== $author ) {
				$fields['post_author'] = $author;
			}
			if ( array() !== $fields ) {
				$written = $this->html->run( fn(): mixed => wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $fields ) ), true ) );
				if ( is_wp_error( $written ) ) {
					$result['failed'][] = array(
						'id'   => $id,
						'code' => 'internal_error',
					);
					continue;
				}
			}
			$verified = $this->verify_post( $id, $slug, $fields, false );
			if ( is_wp_error( $verified ) ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => 'partial_failure',
				);
				continue;
			}
			$effects = $this->apply_post_effects( $id, $slug, $prepared, false );
			if ( is_wp_error( $effects ) ) {
				$result['failed'][] = array(
					'id'   => $id,
					'code' => 'partial_failure',
				);
				continue;
			}
			$result['updated'][] = $id;
		}
		return $result;
	}

	/**
	 * List the parent's revisions with the reviewed parent guard.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function list_revisions( array $arguments ): mixed {
		$parent = $this->revision_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		$pagination = $this->pagination( $arguments );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		$revisions = wp_get_post_revisions(
			$parent['id'],
			array(
				'order'   => 'DESC',
				'orderby' => 'date ID',
			)
		);
		$items     = array();
		foreach ( $revisions as $revision ) {
			if ( $revision instanceof WP_Post ) {
				$items[] = $this->revision_record( $revision, $format );
			}
		}
		$total = count( $items );
		$items = array_slice( $items, ( $pagination['page'] - 1 ) * $pagination['per_page'], $pagination['per_page'] );
		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 0,
			'page'        => $pagination['page'],
		);
	}

	/**
	 * Read one revision bound to the exact parent.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function get_revision( array $arguments ): mixed {
		$parent = $this->revision_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$revision = $this->revision_record_for( $parent['id'], $arguments['revision_id'] ?? 0 );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$format = $this->content_format( $arguments );
		if ( is_wp_error( $format ) ) {
			return $format;
		}
		return $this->revision_record( $revision, $format );
	}

	/**
	 * Restore one revision without changing the stored author.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function restore_revision( array $arguments ): mixed {
		$parent = $this->revision_parent( $arguments );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$revision = $this->revision_record_for( $parent['id'], $arguments['revision_id'] ?? 0 );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$stored = get_post( $parent['id'] );
		if ( ! $stored instanceof WP_Post ) {
			return R::error( 'not_found' );
		}
		$author   = (int) $stored->post_author;
		$restored = wp_restore_post_revision( (int) $revision->ID );
		if ( is_wp_error( $restored ) || ! is_int( $restored ) || $restored < 1 ) {
			return R::error( 'restore_failed' );
		}
		$after = get_post( $parent['id'] );
		if ( ! $after instanceof WP_Post || (int) $after->post_author !== $author ) {
			return new PartialFailure( $parent['id'], false );
		}
		$slug = (string) $parent['slug'];
		return MutationResult::project(
			$parent['id'],
			false,
			function () use ( $parent, $slug ): array|WP_Error {
				$current = get_post( $parent['id'] );
				return $current instanceof WP_Post ? $this->project_post( $current, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Set or remove the featured image after attachment validation.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function set_featured_media( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$media = $arguments['media_id'] ?? null;
		if ( ! is_int( $media ) || $media < 0 ) {
			return R::error( 'invalid_params' );
		}
		if ( ! post_type_supports( $slug, 'thumbnail' ) ) {
			return R::error( 'unsupported_operation' );
		}
		if ( $media > 0 ) {
			$attachment = get_post( $media );
			if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
				return R::error( 'invalid_params' );
			}
		}
		$written = 0 === $media ? delete_post_thumbnail( $id ) : set_post_thumbnail( $id, $media );
		if ( ! $written ) {
			return R::error( 'invalid_params' );
		}
		if ( (int) get_post_thumbnail_id( $id ) !== $media ) {
			return new PartialFailure( $id, false );
		}
		return MutationResult::project(
			$id,
			false,
			function () use ( $id, $slug ): array|WP_Error {
				$stored = get_post( $id );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Change the sticky flag for a password-free standard post.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function stick_post( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		if ( 'post' !== $slug ) {
			return R::error( 'post_type_unavailable' );
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$sticky = $arguments['sticky'] ?? null;
		if ( ! is_bool( $sticky ) ) {
			return R::error( 'invalid_params' );
		}
		if ( $sticky && '' !== (string) $post->post_password ) {
			return R::error( 'forbidden' );
		}
		if ( $sticky ) {
			stick_post( $id );
		} else {
			unstick_post( $id );
		}
		if ( is_sticky( $id ) !== $sticky ) {
			return new PartialFailure( $id, false );
		}
		return MutationResult::project(
			$id,
			false,
			function () use ( $id, $slug ): array|WP_Error {
				$stored = get_post( $id );
				return $stored instanceof WP_Post ? $this->project_post( $stored, $slug ) : R::error( 'internal_error' );
			}
		);
	}

	/**
	 * Read registered, unprotected metadata only.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function get_post_meta( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'not_found' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$keys = $arguments['keys'] ?? null;
		if ( null !== $keys && ! is_array( $keys ) ) {
			return R::error( 'invalid_params' );
		}
		$values = array();
		if ( is_array( $keys ) && array() !== $keys ) {
			if ( count( $keys ) > 50 ) {
				return R::error( 'invalid_params' );
			}
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) ) {
					return R::error( 'invalid_params' );
				}
				$field = $this->meta_field( $slug, $key );
				if ( is_wp_error( $field ) ) {
					return $field;
				}
				$single = $field['single'];
				$value  = get_post_meta( $id, $key, $single );
				if ( null === $value || '' === $value || array() === $value ) {
					continue;
				}
				$valid = rest_validate_value_from_schema( $value, $field['schema'] );
				if ( is_wp_error( $valid ) || true !== $valid ) {
					continue;
				}
				$values[ $key ] = $value;
			}
		} else {
			$values = $this->registered_meta( $id, $slug );
		}
		return array( 'meta' => (object) $values );
	}

	/**
	 * Write registered metadata one key at a time and read it back.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function update_post_meta( array $arguments ): mixed {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		$values = $arguments['values'] ?? null;
		if ( ! is_array( $values ) || array() === $values || count( $values ) > 50 ) {
			return R::error( 'invalid_params' );
		}
		$plan = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return R::error( 'invalid_params' );
			}
			$field = $this->meta_field( $slug, $key );
			if ( is_wp_error( $field ) ) {
				return $field;
			}
			if ( null === $value ) {
				$plan[ $key ] = array(
					'value'  => null,
					'single' => $field['single'],
				);
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, $field['schema'] );
			if ( is_wp_error( $valid ) || true !== $valid ) {
				return R::error( 'invalid_params' );
			}
			$filtered = $this->html->filter_meta( $field['schema'], $value );
			if ( is_wp_error( $filtered ) ) {
				return R::error( 'invalid_params' );
			}
			$checked = rest_validate_value_from_schema( $filtered, $field['schema'] );
			if ( is_wp_error( $checked ) || true !== $checked ) {
				return R::error( 'invalid_params' );
			}
			$plan[ $key ] = array(
				'value'  => $filtered,
				'single' => $field['single'],
			);
		}
		foreach ( $plan as $key => $entry ) {
			if ( ! $this->write_meta( $id, $key, $entry['value'], $entry['single'] ) ) {
				return new PartialFailure( $id, false );
			}
		}
		$result = array();
		foreach ( $plan as $key => $entry ) {
			$stored = get_post_meta( $id, $key, $entry['single'] );
			if ( null === $stored || '' === $stored || array() === $stored ) {
				continue;
			}
			$result[ $key ] = $stored;
		}
		return array( 'meta' => (object) $result );
	}

	/**
	 * Search published objects in the reviewed editorial types only.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	private function search_content( array $arguments ): mixed {
		$search = $arguments['search'] ?? null;
		if ( ! is_string( $search ) || '' === $search || mb_strlen( $search ) > 200 ) {
			return R::error( 'invalid_params' );
		}
		$pagination = $this->pagination( $arguments );
		if ( is_wp_error( $pagination ) ) {
			return $pagination;
		}
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $slug => $type ) {
			if ( is_string( $slug ) && $type instanceof WP_Post_Type && ! is_wp_error( $this->editorial_type( $slug ) ) ) {
				$types[] = $slug;
			}
		}
		$subtype = $arguments['subtype'] ?? 'any';
		if ( ! is_string( $subtype ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'any' !== $subtype ) {
			$checked = $this->editorial_type( $subtype );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			if ( ! in_array( $subtype, $types, true ) ) {
				return R::error( 'post_type_unavailable' );
			}
			$types = array( $subtype );
		}
		if ( array() === $types ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
				'page'        => $pagination['page'],
			);
		}
		$result  = new \WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				's'                   => $search,
				'paged'               => $pagination['page'],
				'posts_per_page'      => $pagination['per_page'],
				'ignore_sticky_posts' => true,
				'has_password'        => false,
			)
		);
		$items   = array();
		$removed = 0;
		foreach ( (array) $result->posts as $post ) {
			if ( ! $post instanceof WP_Post || ! $this->readable( (int) $post->ID, (string) $post->post_type, false ) ) {
				++$removed;
				continue;
			}
			$url     = get_permalink( $post );
			$items[] = array(
				'id'      => (int) $post->ID,
				'title'   => (string) $post->post_title,
				'url'     => is_string( $url ) ? $url : '',
				'type'    => 'post',
				'subtype' => (string) $post->post_type,
			);
		}
		$total = max( 0, (int) $result->found_posts - $removed );
		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 0,
			'page'        => $pagination['page'],
		);
	}

	/**
	 * Resolve one reviewed editorial post type or deny before any work.
	 *
	 * @param string $slug Candidate type.
	 * @return WP_Post_Type|WP_Error
	 */
	private function editorial_type( string $slug ): WP_Post_Type|WP_Error {
		$type = $this->types->resolve( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$base = is_array( $type->capability_type ) ? ( $type->capability_type[0] ?? '' ) : $type->capability_type;
		if ( ! is_string( $base ) || ! isset( self::EDITORIAL_CLASS[ $base ] ) ) {
			return R::error( 'post_type_unavailable' );
		}
		if ( true !== $type->map_meta_cap || ! is_object( $type->cap ) ) {
			return R::error( 'post_type_unavailable' );
		}
		$actual = (array) $type->cap;
		$review = self::EDITORIAL_CLASS[ $base ];
		if ( count( $actual ) !== count( $review ) ) {
			return R::error( 'post_type_unavailable' );
		}
		foreach ( $review as $slot => $expected ) {
			if ( ! array_key_exists( $slot, $actual ) || $actual[ $slot ] !== $expected ) {
				return R::error( 'post_type_unavailable' );
			}
		}
		return $type;
	}

	/**
	 * Validate and normalize every allowlisted change before a write.
	 *
	 * @param WP_Post_Type        $type Runtime type.
	 * @param array<string,mixed> $changes Submitted changes.
	 * @param int                 $id Existing post, zero for create.
	 * @return array{fields:array<string,mixed>,terms:array<string,array<int>>,meta:array<string,array{value:mixed,single:bool}>,template:string|null,featured:int|null,sticky:bool|null,format:string|null}|WP_Error
	 */
	private function prepare_changes( WP_Post_Type $type, array $changes, int $id ): array|WP_Error {
		if ( array() !== array_diff( array_keys( $changes ), self::CHANGE_FIELDS ) ) {
			return R::error( 'invalid_params' );
		}
		$fields = array();
		if ( array_key_exists( 'title', $changes ) ) {
			if ( ! is_string( $changes['title'] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_title'] = $this->html->filter_plain_text( $changes['title'] );
		}
		foreach ( array(
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $source => $target ) {
			if ( ! array_key_exists( $source, $changes ) ) {
				continue;
			}
			if ( ! is_string( $changes[ $source ] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields[ $target ] = $this->html->filter_post_html( $changes[ $source ] );
		}
		$timestamp = null;
		if ( array_key_exists( 'date', $changes ) ) {
			if ( ! is_string( $changes['date'] ) ) {
				return R::error( 'invalid_params' );
			}
			$timestamp = strtotime( $changes['date'] );
			if ( false === $timestamp ) {
				return R::error( 'invalid_params' );
			}
			$gmt                     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$fields['post_date_gmt'] = $gmt;
			$fields['post_date']     = get_date_from_gmt( $gmt );
		}
		if ( array_key_exists( 'status', $changes ) ) {
			if ( ! is_string( $changes['status'] ) || ! in_array( $changes['status'], self::READ_STATUSES, true ) ) {
				return R::error( 'invalid_params' );
			}
			$publish = $this->publish_class( $type );
			if ( is_wp_error( $publish ) ) {
				return $publish;
			}
			if ( 'future' === $changes['status'] && ( null === $timestamp || $timestamp <= time() ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_status'] = $changes['status'];
		}
		if ( array_key_exists( 'slug', $changes ) ) {
			if ( ! is_string( $changes['slug'] ) || 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $changes['slug'] ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['post_name'] = $changes['slug'];
		}
		if ( array_key_exists( 'parent', $changes ) ) {
			$parent = $this->parent_value( $type, $changes['parent'], $id );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			$fields['post_parent'] = $parent;
		}
		if ( array_key_exists( 'menu_order', $changes ) ) {
			if ( ! is_int( $changes['menu_order'] ) || ! post_type_supports( $type->name, 'page-attributes' ) ) {
				return R::error( 'invalid_params' );
			}
			$fields['menu_order'] = $changes['menu_order'];
		}
		foreach ( array(
			'comment_status' => 'comments',
			'ping_status'    => 'trackbacks',
		) as $field => $support ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			if ( ! is_string( $changes[ $field ] ) || ! in_array( $changes[ $field ], array( 'open', 'closed' ), true ) || ! post_type_supports( $type->name, $support ) ) {
				return R::error( 'invalid_params' );
			}
			$fields[ $field ] = $changes[ $field ];
		}
		$terms = array();
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $type->name, $taxonomy ) ) {
				return R::error( 'invalid_params' );
			}
			$ids = $this->positive_ids( $changes[ $field ], 50, false );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$valid = $this->validate_terms( $taxonomy, $ids );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$terms[ $taxonomy ] = $ids;
		}
		$meta = array();
		if ( array_key_exists( 'meta', $changes ) ) {
			if ( ! is_array( $changes['meta'] ) || count( $changes['meta'] ) > 50 ) {
				return R::error( 'invalid_params' );
			}
			foreach ( $changes['meta'] as $key => $value ) {
				if ( ! is_string( $key ) ) {
					return R::error( 'invalid_params' );
				}
				$field = $this->meta_field( $type->name, $key );
				if ( is_wp_error( $field ) ) {
					return $field;
				}
				if ( null === $value ) {
					$meta[ $key ] = array(
						'value'  => null,
						'single' => $field['single'],
					);
					continue;
				}
				$valid = rest_validate_value_from_schema( $value, $field['schema'] );
				if ( is_wp_error( $valid ) || true !== $valid ) {
					return R::error( 'invalid_params' );
				}
				$filtered = $this->html->filter_meta( $field['schema'], $value );
				if ( is_wp_error( $filtered ) ) {
					return R::error( 'invalid_params' );
				}
				$checked = rest_validate_value_from_schema( $filtered, $field['schema'] );
				if ( is_wp_error( $checked ) || true !== $checked ) {
					return R::error( 'invalid_params' );
				}
				$meta[ $key ] = array(
					'value'  => $filtered,
					'single' => $field['single'],
				);
			}
		}
		$featured = null;
		if ( array_key_exists( 'featured_media', $changes ) ) {
			$featured = $this->featured_value( $type, $changes['featured_media'] );
			if ( is_wp_error( $featured ) ) {
				return $featured;
			}
		}
		$sticky = null;
		if ( array_key_exists( 'sticky', $changes ) ) {
			if ( ! is_bool( $changes['sticky'] ) || 'post' !== $type->name ) {
				return R::error( 'post_type_unavailable' );
			}
			if ( $changes['sticky'] && $id > 0 ) {
				$existing = get_post( $id );
				if ( $existing instanceof WP_Post && '' !== (string) $existing->post_password ) {
					return R::error( 'forbidden' );
				}
			}
			$sticky = $changes['sticky'];
		}
		$template = null;
		if ( array_key_exists( 'template', $changes ) ) {
			if ( ! is_string( $changes['template'] ) || '' === $changes['template'] ) {
				return R::error( 'invalid_params' );
			}
			$templates = get_page_templates( null, $type->name );
			if ( ! is_array( $templates ) || ! array_key_exists( $changes['template'], $templates ) ) {
				return R::error( 'invalid_params' );
			}
			$template = $changes['template'];
		}
		$format = null;
		if ( array_key_exists( 'format', $changes ) ) {
			if ( ! is_string( $changes['format'] ) || ! post_type_supports( $type->name, 'post-formats' ) ) {
				return R::error( 'invalid_params' );
			}
			$clean = in_array( $changes['format'], array( '', 'standard' ), true ) ? '' : $changes['format'];
			if ( '' !== $clean && ! in_array( $clean, get_post_format_slugs(), true ) ) {
				return R::error( 'invalid_params' );
			}
			$format = $clean;
		}
		return array(
			'fields'   => $fields,
			'terms'    => $terms,
			'meta'     => $meta,
			'template' => $template,
			'featured' => $featured,
			'sticky'   => $sticky,
			'format'   => $format,
		);
	}

	/**
	 * Apply terms, metadata and fixed side effects after the post persists.
	 *
	 * @param int                 $id Post ID.
	 * @param string              $slug Post type.
	 * @param array<string,mixed> $prepared Prepared change set.
	 * @param bool                $created Whether this request created the post.
	 * @return true|WP_Error
	 */
	private function apply_post_effects( int $id, string $slug, array $prepared, bool $created ): true|WP_Error {
		foreach ( $this->prepared_terms( $prepared ) as $taxonomy => $term_ids ) {
			$result = ( $this->assign_terms )( $id, (string) $taxonomy, $term_ids, false );
			if ( is_wp_error( $result ) ) {
				return new PartialFailure( $id, $created );
			}
		}
		foreach ( $this->prepared_meta( $prepared ) as $key => $entry ) {
			if ( ! $this->write_meta( $id, (string) $key, $entry['value'], (bool) $entry['single'] ) ) {
				return new PartialFailure( $id, $created );
			}
		}
		$template = is_string( $prepared['template'] ?? null ) ? $prepared['template'] : null;
		if ( null !== $template ) {
			if ( false === update_metadata( 'post', $id, '_wp_page_template', wp_slash( $template ) ) && get_page_template_slug( $id ) !== $template ) {
				return new PartialFailure( $id, $created );
			}
			if ( get_page_template_slug( $id ) !== $template ) {
				return new PartialFailure( $id, $created );
			}
		}
		$featured = is_int( $prepared['featured'] ?? null ) ? $prepared['featured'] : null;
		if ( null !== $featured ) {
			$written = 0 === $featured ? delete_post_thumbnail( $id ) : set_post_thumbnail( $id, $featured );
			if ( ! $written || (int) get_post_thumbnail_id( $id ) !== $featured ) {
				return new PartialFailure( $id, $created );
			}
		}
		$sticky = is_bool( $prepared['sticky'] ?? null ) ? $prepared['sticky'] : null;
		if ( null !== $sticky ) {
			if ( $sticky ) {
				stick_post( $id );
			} else {
				unstick_post( $id );
			}
			if ( is_sticky( $id ) !== $sticky ) {
				return new PartialFailure( $id, $created );
			}
		}
		$format = is_string( $prepared['format'] ?? null ) ? $prepared['format'] : null;
		if ( null !== $format ) {
			$written = set_post_format( $id, $format );
			if ( is_wp_error( $written ) || false === $written ) {
				return new PartialFailure( $id, $created );
			}
			if ( $this->stored_format( $id ) !== ( '' === $format ? 'standard' : $format ) ) {
				return new PartialFailure( $id, $created );
			}
		}
		return true;
	}

	/**
	 * Verify the persisted post identity after a write.
	 *
	 * @param int                 $id Post ID.
	 * @param string              $slug Expected type.
	 * @param array<string,mixed> $fields Requested fields.
	 * @param bool                $created Whether this request created the post.
	 * @return true|WP_Error
	 */
	private function verify_post( int $id, string $slug, array $fields, bool $created ): true|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'internal_error' );
		}
		if ( array_key_exists( 'post_status', $fields ) && $post->post_status !== (string) $fields['post_status'] ) {
			return new PartialFailure( $id, $created );
		}
		if ( array_key_exists( 'post_author', $fields ) && (int) $post->post_author !== (int) $fields['post_author'] ) {
			return new PartialFailure( $id, $created );
		}
		return true;
	}

	/**
	 * Project one stored post through the fixed allowlist.
	 *
	 * @param WP_Post       $post Stored post.
	 * @param string        $slug Post type.
	 * @param string        $format Representation.
	 * @param array<string> $fields Selected fields.
	 * @return array<string,mixed>|WP_Error
	 */
	private function project_post( WP_Post $post, string $slug, string $format = 'rendered', array $fields = array() ): array|WP_Error {
		$selected = $fields;
		if ( '' !== (string) $post->post_password ) {
			$base     = array() === $selected ? ContentSchemas::FIELDS : $selected;
			$selected = array_values( array_diff( $base, array( 'content', 'excerpt' ) ) );
		}
		$projection = new PostProjection( fn( int $id ): bool => $this->readable( $id, $slug, true ) );
		try {
			return $projection->project( $this->record( $post, $slug ), $format, $selected );
		} catch ( \DomainException ) {
			return R::error( 'forbidden' );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * Build one allowlisted post record.
	 *
	 * @param WP_Post $post Stored post.
	 * @param string  $slug Post type.
	 * @return array<string,mixed>
	 */
	private function record( WP_Post $post, string $slug ): array {
		$link   = get_permalink( $post );
		$meta   = $this->registered_meta( (int) $post->ID, $slug );
		$record = array(
			'id'             => (int) $post->ID,
			'type'           => $slug,
			'status'         => (string) $post->post_status,
			'title'          => array(
				'raw'      => (string) $post->post_title,
				'rendered' => (string) $post->post_title,
			),
			'slug'           => (string) $post->post_name,
			'date'           => (string) $post->post_date,
			'modified'       => (string) $post->post_modified,
			'author'         => (int) $post->post_author,
			'excerpt'        => array(
				'raw'      => (string) $post->post_excerpt,
				'rendered' => (string) $post->post_excerpt,
			),
			'content'        => array(
				'raw'      => (string) $post->post_content,
				'rendered' => (string) $post->post_content,
			),
			'featured_media' => (int) get_post_thumbnail_id( (int) $post->ID ),
			'categories'     => $this->object_terms( (int) $post->ID, 'category' ),
			'tags'           => $this->object_terms( (int) $post->ID, 'post_tag' ),
			'sticky'         => is_sticky( (int) $post->ID ),
			'meta'           => (object) $meta,
			'date_gmt'       => (string) $post->post_date_gmt,
			'modified_gmt'   => (string) $post->post_modified_gmt,
			'parent'         => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
			'template'       => (string) get_page_template_slug( (int) $post->ID ),
			'comment_status' => (string) $post->comment_status,
			'ping_status'    => (string) $post->ping_status,
			'format'         => $this->stored_format( (int) $post->ID ),
		);
		if ( is_string( $link ) && '' !== $link ) {
			$record['link'] = $link;
		}
		return $record;
	}

	/**
	 * Read registered, unprotected metadata without evaluating a capability.
	 *
	 * @param int    $id Post ID.
	 * @param string $slug Post type.
	 * @return array<string,mixed>
	 */
	private function registered_meta( int $id, string $slug ): array {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $slug ) );
		$schema     = $this->meta_properties( $slug );
		$result     = array();
		foreach ( $registered as $key => $definition ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) || ! is_array( $definition ) || empty( $definition['show_in_rest'] ) ) {
				continue;
			}
			$callback = $definition['auth_callback'] ?? null;
			if ( null !== $callback && '__return_true' !== $callback ) {
				continue;
			}
			$field = $schema[ $key ] ?? null;
			if ( ! is_array( $field ) ) {
				continue;
			}
			$single = ! empty( $definition['single'] );
			$value  = get_post_meta( $id, $key, $single );
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$valid = rest_validate_value_from_schema( $value, $field );
			if ( is_wp_error( $valid ) || true !== $valid ) {
				continue;
			}
			$result[ $key ] = $value;
		}
		return $result;
	}

	/**
	 * Copy only metadata a user-less principal may read.
	 *
	 * @param int    $id Post ID.
	 * @param string $slug Post type.
	 * @return array<string,mixed>
	 */
	private function copyable_meta( int $id, string $slug ): array {
		return $this->registered_meta( $id, $slug );
	}

	/**
	 * Resolve one writable registered meta field.
	 *
	 * @param string $slug Post type.
	 * @param string $key Meta key.
	 * @return array{schema:array<string,mixed>,single:bool}|WP_Error
	 */
	private function meta_field( string $slug, string $key ): array|WP_Error {
		if ( '' === $key || mb_strlen( $key ) > 255 || str_starts_with( $key, '_' ) ) {
			return R::error( 'invalid_params' );
		}
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $slug ) );
		$definition = $registered[ $key ] ?? null;
		if ( ! is_array( $definition ) || empty( $definition['show_in_rest'] ) ) {
			return R::error( 'invalid_params' );
		}
		$callback = $definition['auth_callback'] ?? null;
		if ( null !== $callback && '__return_true' !== $callback ) {
			return R::error( 'forbidden' );
		}
		$schema = $this->meta_properties( $slug );
		$field  = $schema[ $key ] ?? null;
		if ( ! is_array( $field ) ) {
			return R::error( 'invalid_params' );
		}
		return array(
			'schema' => $field,
			'single' => ! empty( $definition['single'] ),
		);
	}

	/**
	 * Read the registered REST meta field schema.
	 *
	 * @param string $slug Post type.
	 * @return array<string,mixed>
	 */
	private function meta_properties( string $slug ): array {
		$schema     = ( new \WP_REST_Post_Meta_Fields( $slug ) )->get_field_schema();
		$properties = $schema['properties'] ?? null;
		return is_array( $properties ) ? $properties : array();
	}

	/**
	 * Write one registered metadata value and verify the stored state.
	 *
	 * @param int    $id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Value, null deletes.
	 * @param bool   $single Whether the key is single.
	 * @return bool
	 */
	private function write_meta( int $id, string $key, mixed $value, bool $single ): bool {
		if ( null === $value ) {
			delete_metadata( 'post', $id, $key );
			return ! metadata_exists( 'post', $id, $key );
		}
		if ( false === update_metadata( 'post', $id, $key, wp_slash( $value ) ) ) {
			return $this->same_value( get_post_meta( $id, $key, $single ), $value );
		}
		return $this->same_value( get_post_meta( $id, $key, $single ), $value );
	}

	/**
	 * Compare a stored value with the requested value.
	 *
	 * @param mixed $stored Stored value.
	 * @param mixed $value Requested value.
	 * @return bool
	 */
	private function same_value( mixed $stored, mixed $value ): bool {
		if ( is_array( $value ) ) {
			$stored = is_array( $stored ) ? array_values( $stored ) : array( $stored );
			$value  = array_values( $value );
			if ( count( $stored ) !== count( $value ) ) {
				return false;
			}
			foreach ( $value as $index => $item ) {
				if ( (string) $stored[ $index ] !== (string) $item ) {
					return false;
				}
			}
			return true;
		}
		return $stored === $value || ( is_scalar( $stored ) && is_scalar( $value ) && (string) $stored === (string) $value );
	}

	/**
	 * Validate existing integer term IDs before the first write.
	 *
	 * @param string     $taxonomy Taxonomy.
	 * @param array<int> $ids Term IDs.
	 * @return true|WP_Error
	 */
	private function validate_terms( string $taxonomy, array $ids ): true|WP_Error {
		if ( array() === $ids ) {
			return true;
		}
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $found ) ) {
			return R::error( 'invalid_params' );
		}
		$found = array_values( array_map( 'intval', (array) $found ) );
		$ids   = array_values( array_map( 'intval', $ids ) );
		sort( $found );
		sort( $ids );
		return $found === $ids ? true : R::error( 'invalid_params' );
	}

	/**
	 * Validate one parent binding.
	 *
	 * @param WP_Post_Type $type Runtime type.
	 * @param mixed        $value Parent ID.
	 * @param int          $id Current post.
	 * @return int|WP_Error
	 */
	private function parent_value( WP_Post_Type $type, mixed $value, int $id ): int|WP_Error {
		if ( ! is_int( $value ) || $value < 0 || ! post_type_supports( $type->name, 'page-attributes' ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 0 === $value ) {
			return 0;
		}
		$parent = get_post( $value );
		if ( ! $parent instanceof WP_Post || $parent->post_type !== $type->name || ( $id > 0 && $id === $value ) ) {
			return R::error( 'invalid_params' );
		}
		if ( $id > 0 && in_array( $id, array_map( 'intval', get_ancestors( $value, $type->name ) ), true ) ) {
			return R::error( 'invalid_params' );
		}
		return $value;
	}

	/**
	 * Validate one featured media binding.
	 *
	 * @param WP_Post_Type $type Runtime type.
	 * @param mixed        $value Attachment ID.
	 * @return int|WP_Error
	 */
	private function featured_value( WP_Post_Type $type, mixed $value ): int|WP_Error {
		if ( ! is_int( $value ) || $value < 0 || ! post_type_supports( $type->name, 'thumbnail' ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 0 === $value ) {
			return 0;
		}
		$attachment = get_post( $value );
		return $attachment instanceof WP_Post && 'attachment' === $attachment->post_type ? $value : R::error( 'invalid_params' );
	}

	/**
	 * Read the exact publish class gate for the type.
	 *
	 * @param WP_Post_Type $type Runtime type.
	 * @return true|WP_Error
	 */
	private function publish_class( WP_Post_Type $type ): true|WP_Error {
		$base = $this->capability_base( $type );
		return isset( self::EDITORIAL_CLASS[ $base ]['publish_posts'] ) ? true : R::error( 'forbidden' );
	}

	/**
	 * Return the reviewed capability base.
	 *
	 * @param WP_Post_Type $type Runtime type.
	 * @return string
	 */
	private function capability_base( WP_Post_Type $type ): string {
		return is_array( $type->capability_type ) ? (string) ( $type->capability_type[0] ?? '' ) : (string) $type->capability_type;
	}

	/**
	 * Whether one post may be read at all.
	 *
	 * @param int    $id Post ID.
	 * @param string $slug Expected type.
	 * @return bool
	 */
	private function visible( int $id, string $slug ): bool {
		if ( is_wp_error( $this->editorial_type( $slug ) ) ) {
			return false;
		}
		$post = get_post( $id );
		return $post instanceof WP_Post && $post->post_type === $slug && in_array( $post->post_status, self::READ_STATUSES, true );
	}

	/**
	 * Fixed object predicate: exact type, reviewed status and no password.
	 *
	 * @param int    $id Post ID.
	 * @param string $slug Expected type.
	 * @param bool   $raw Whether this is a raw projection.
	 * @return bool
	 */
	private function readable( int $id, string $slug, bool $raw ): bool {
		if ( ! $this->visible( $id, $slug ) ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || '' !== (string) $post->post_password ) {
			return false;
		}
		return ! $raw || in_array( $post->post_status, self::READ_STATUSES, true );
	}

	/**
	 * Site-page protection for the reviewed editorial operations.
	 *
	 * @param int  $id Post ID.
	 * @param bool $include_front Whether front and posts pages are protected.
	 * @return bool
	 */
	private function protected_page( int $id, bool $include_front ): bool {
		if ( $id < 1 ) {
			return false;
		}
		if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $id ) {
			return true;
		}
		return $include_front && in_array( $id, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true );
	}

	/**
	 * Detect an expected_modified conflict before any write.
	 *
	 * @param WP_Post $post Stored post.
	 * @param mixed   $value Submitted UTC timestamp.
	 * @return true|WP_Error
	 */
	private function conflict( WP_Post $post, mixed $value ): true|WP_Error {
		if ( ! is_string( $value ) ) {
			return R::error( 'invalid_params' );
		}
		$expected = strtotime( $value );
		$current  = strtotime( (string) $post->post_modified_gmt . 'Z' );
		if ( false === $expected || false === $current ) {
			return R::error( 'internal_error' );
		}
		return $expected === $current ? true : R::error( 'conflict' );
	}

	/**
	 * Resolve the revision parent with the reviewed object guard.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array{id:int,slug:string}|WP_Error
	 */
	private function revision_parent( array $arguments ): array|WP_Error {
		$slug = $this->type_slug( $arguments );
		$type = $this->editorial_type( $slug );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$id = $this->positive_integer( $arguments['id'] ?? 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $slug ) {
			return R::error( 'not_found' );
		}
		if ( 'trash' === $post->post_status ) {
			return R::error( 'invalid_state' );
		}
		if ( $this->protected_page( $id, false ) ) {
			return R::error( 'forbidden' );
		}
		if ( '' !== (string) $post->post_password ) {
			return R::error( 'forbidden' );
		}
		if ( ! post_type_supports( $slug, 'revisions' ) ) {
			return R::error( 'unsupported_operation' );
		}
		return array(
			'id'   => $id,
			'slug' => $slug,
		);
	}

	/**
	 * Resolve one revision bound to the exact parent.
	 *
	 * @param int   $parent_id Parent post ID.
	 * @param mixed $value Revision ID.
	 * @return WP_Post|WP_Error
	 */
	private function revision_record_for( int $parent_id, mixed $value ): WP_Post|WP_Error {
		$id = $this->positive_integer( $value );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$revision = get_post( $id );
		if ( ! $revision instanceof WP_Post || 'revision' !== $revision->post_type || (int) $revision->post_parent !== $parent_id ) {
			return R::error( 'not_found' );
		}
		return $revision;
	}

	/**
	 * Build the reviewed revision shape.
	 *
	 * @param WP_Post $revision Revision.
	 * @param string  $format Representation.
	 * @return array<string,mixed>
	 */
	private function revision_record( WP_Post $revision, string $format ): array {
		$content = 'excerpt' === $format ? (string) $revision->post_excerpt : (string) $revision->post_content;
		return array(
			'id'       => (int) $revision->ID,
			'parent'   => (int) $revision->post_parent,
			'author'   => (int) $revision->post_author,
			'date'     => (string) $revision->post_date,
			'modified' => (string) $revision->post_modified,
			'slug'     => (string) $revision->post_name,
			'title'    => (string) $revision->post_title,
			'content'  => $content,
			'excerpt'  => (string) $revision->post_excerpt,
		);
	}

	/**
	 * Copy supported taxonomy assignments from one source.
	 *
	 * @param int    $id Source post.
	 * @param string $slug Post type.
	 * @return array<string,array<int>>
	 */
	private function source_terms( int $id, string $slug ): array {
		$terms = array();
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy ) {
			if ( ! is_object_in_taxonomy( $slug, $taxonomy ) ) {
				continue;
			}
			$ids = $this->object_terms( $id, $taxonomy );
			if ( array() !== $ids ) {
				$terms[ $field ] = $ids;
			}
		}
		return $terms;
	}

	/**
	 * Read object term IDs without failing a projection.
	 *
	 * @param int    $id Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array<int>
	 */
	private function object_terms( int $id, string $taxonomy ): array {
		$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'intval', (array) $terms ) );
	}

	/**
	 * Validate positive bounded identifiers.
	 *
	 * @param mixed $value Submitted list.
	 * @param int   $max Maximum count.
	 * @param bool  $required Whether an empty list is invalid.
	 * @return array<int>|WP_Error
	 */
	private function positive_ids( mixed $value, int $max, bool $required = false ): array|WP_Error {
		if ( ! is_array( $value ) || ! $this->is_list( array_keys( $value ) ) ) {
			return R::error( 'invalid_params' );
		}
		if ( ( $required && array() === $value ) || count( $value ) > $max ) {
			return R::error( 'invalid_params' );
		}
		$ids = array();
		foreach ( $value as $item ) {
			if ( ! is_int( $item ) || $item < 1 || in_array( $item, $ids, true ) ) {
				return R::error( 'invalid_params' );
			}
			$ids[] = $item;
		}
		return $ids;
	}

	/**
	 * Validate one positive integer without schema coercion.
	 *
	 * @param mixed $value Submitted value.
	 * @return int|WP_Error
	 */
	private function positive_integer( mixed $value ): int|WP_Error {
		return is_int( $value ) && $value > 0 ? $value : R::error( 'invalid_params' );
	}

	/**
	 * Build the bounded page and page-size pair.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array{page:int,per_page:int}|WP_Error
	 */
	private function pagination( array $arguments ): array|WP_Error {
		$page     = $arguments['page'] ?? 1;
		$per_page = $arguments['per_page'] ?? 20;
		if ( ! is_int( $page ) || ! is_int( $per_page ) || $page < 1 || $per_page < 1 || $per_page > 100 || $page > intdiv( PHP_INT_MAX, $per_page ) ) {
			return R::error( 'invalid_params' );
		}
		return array(
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Validate the requested content representation.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return string|WP_Error
	 */
	private function content_format( array $arguments ): string|WP_Error {
		$format = $arguments['content_format'] ?? 'rendered';
		return is_string( $format ) && in_array( $format, array( 'raw', 'rendered', 'excerpt' ), true ) ? $format : R::error( 'invalid_params' );
	}

	/**
	 * Validate the optional field projection.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<string>|WP_Error
	 */
	private function projection_fields( array $arguments ): array|WP_Error {
		$fields = $arguments['fields'] ?? array();
		if ( ! is_array( $fields ) || ! $this->is_list( array_keys( $fields ) ) || count( $fields ) > count( ContentSchemas::FIELDS ) ) {
			return R::error( 'invalid_params' );
		}
		$selected = array();
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, ContentSchemas::FIELDS, true ) || in_array( $field, $selected, true ) ) {
				return R::error( 'invalid_params' );
			}
			$selected[] = $field;
		}
		return $selected;
	}

	/**
	 * Build the reviewed taxonomy query clauses.
	 *
	 * @param string              $slug Post type.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<mixed>|WP_Error
	 */
	private function taxonomy_query( string $slug, array $arguments ): array|WP_Error {
		$clauses = array( 'relation' => 'AND' );
		$added   = false;
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy ) {
			if ( ! array_key_exists( $field, $arguments ) ) {
				continue;
			}
			if ( ! is_object_in_taxonomy( $slug, $taxonomy ) ) {
				return R::error( 'invalid_params' );
			}
			$ids = $this->positive_ids( $arguments[ $field ], 50 );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			$clauses[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $ids,
			);
			$added     = true;
		}
		return $added ? $clauses : array();
	}

	/**
	 * Build the bounded date query.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private function date_query( array $arguments ): array|WP_Error {
		$query = array( 'inclusive' => true );
		foreach ( array( 'after', 'before' ) as $key ) {
			if ( ! array_key_exists( $key, $arguments ) ) {
				continue;
			}
			if ( ! is_string( $arguments[ $key ] ) || false === strtotime( $arguments[ $key ] ) ) {
				return R::error( 'invalid_params' );
			}
			$query[ $key ] = $arguments[ $key ];
		}
		return count( $query ) > 1 ? $query : array();
	}

	/**
	 * Resolve the requested post type slug.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return string
	 */
	private function type_slug( array $arguments ): string {
		$slug = $arguments['post_type'] ?? 'post';
		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Read typed term assignments from a prepared change set.
	 *
	 * @param array<string,mixed> $prepared Prepared change set.
	 * @return array<string,array<int>>
	 */
	private function prepared_terms( array $prepared ): array {
		$terms = $prepared['terms'] ?? null;
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$result = array();
		foreach ( $terms as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $ids ) ) {
				continue;
			}
			$result[ $taxonomy ] = array_values( array_map( 'intval', $ids ) );
		}
		return $result;
	}

	/**
	 * Read typed metadata entries from a prepared change set.
	 *
	 * @param array<string,mixed> $prepared Prepared change set.
	 * @return array<string,array{value:mixed,single:bool}>
	 */
	private function prepared_meta( array $prepared ): array {
		$meta = $prepared['meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			return array();
		}
		$result = array();
		foreach ( $meta as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}
			$result[ $key ] = array(
				'value'  => $entry['value'] ?? null,
				'single' => ! empty( $entry['single'] ),
			);
		}
		return $result;
	}

	/**
	 * Return the stored post format or the standard marker.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	private function stored_format( int $id ): string {
		$format = get_post_format( $id );
		return is_string( $format ) && '' !== $format ? $format : 'standard';
	}

	/**
	 * Insert one allowlisted post.
	 *
	 * @param array<string,mixed> $fields Allowlisted fields.
	 * @return int|WP_Error
	 */
	private function insert( array $fields ): int|WP_Error {
		$slashed = wp_slash( $fields );
		$fields  = is_array( $slashed ) ? $slashed : $fields;
		$id      = $this->html->run( fn(): mixed => wp_insert_post( $fields, true ) );
		if ( is_wp_error( $id ) ) {
			return R::error( 'internal_error' );
		}
		$id = (int) $id;
		return $id > 0 ? $id : R::error( 'internal_error' );
	}

	/**
	 * Whether a value is a list.
	 *
	 * @param array<int|string> $keys Array keys.
	 * @return bool
	 */
	private function is_list( array $keys ): bool {
		return array_values( $keys ) === $keys;
	}
}
