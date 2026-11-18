<?php
/**
 * Accountless machine taxonomy operations inside the fixed editorial map.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Taxonomy;

use Closure;
use WP_Error;
use WP_REST_Term_Meta_Fields;
use WP_Taxonomy;
use WP_Term;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\Packs\Content\PostTypeResolver;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;

/** Term names, descriptions, slugs and meta remain untrusted data. */
final class SiteTaxonomyOperations {
	/** The nine reviewed machine taxonomy names. */
	public const NAMES = array(
		'xnetvn/list-taxonomies',
		'xnetvn/list-terms',
		'xnetvn/get-term',
		'xnetvn/create-term',
		'xnetvn/update-term',
		'xnetvn/assign-post-terms',
		'xnetvn/bulk-create-terms',
		'xnetvn/bulk-assign-post-terms',
		'xnetvn/delete-term',
	);

	/** Reviewed capability families that core resolves to the editorial primitives. */
	private const REVIEWED_TERM_CAPS = array(
		'manage_terms' => array( 'manage_categories', 'manage_post_tags' ),
		'edit_terms'   => array( 'manage_categories', 'manage_post_tags', 'edit_categories', 'edit_post_tags' ),
		'delete_terms' => array( 'manage_categories', 'manage_post_tags', 'delete_categories', 'delete_post_tags' ),
		'assign_terms' => array( 'edit_posts', 'assign_categories', 'assign_post_tags' ),
	);

	/** Reviewed taxonomy selection.
	 *
	 * @var TaxonomyResolver
	 */
	private TaxonomyResolver $taxonomies;

	/** Runtime post-type boundary.
	 *
	 * @var PostTypeResolver
	 */
	private PostTypeResolver $types;

	/** Allowlisted output.
	 *
	 * @var TermProjection
	 */
	private TermProjection $projection;

	/** Scoped editorial HTML boundary.
	 *
	 * @var SafeEditorialHtml
	 */
	private SafeEditorialHtml $html;

	/** Reviewed read dispatch.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;

	/**
	 * Bind pure infrastructure with optional reviewed seams.
	 *
	 * @param TaxonomyResolver|null  $taxonomies Reviewed taxonomy selection.
	 * @param PostTypeResolver|null  $types      Runtime post-type boundary.
	 * @param TermProjection|null    $projection Allowlisted output.
	 * @param SafeEditorialHtml|null $html      Scoped editorial HTML boundary.
	 * @param RestBackedAbility|null $rest      Reviewed read dispatch.
	 */
	public function __construct(
		?TaxonomyResolver $taxonomies = null,
		?PostTypeResolver $types = null,
		?TermProjection $projection = null,
		?SafeEditorialHtml $html = null,
		?RestBackedAbility $rest = null
	) {
		$this->taxonomies = $taxonomies ?? new TaxonomyResolver();
		$this->types      = $types ?? new PostTypeResolver();
		$this->projection = $projection ?? new TermProjection();
		$this->html       = $html ?? new SafeEditorialHtml();
		$this->rest       = $rest ?? new RestBackedAbility();
	}

	/**
	 * Return the only machine handler for one fixed built-in name.
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
	 * @param array<string,mixed> $arguments      Logical arguments.
	 * @param SiteAuthContext     $context        Verified machine identity.
	 * @return mixed
	 */
	public function execute( string $canonical_name, array $arguments, SiteAuthContext $context ): mixed {
		if ( ! in_array( $canonical_name, self::NAMES, true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( $context->blog_id() !== get_current_blog_id() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		switch ( $canonical_name ) {
			case 'xnetvn/list-taxonomies':
				return $this->list_taxonomies( $arguments, $context );
			case 'xnetvn/list-terms':
				return $this->list_terms( $arguments );
			case 'xnetvn/get-term':
				return $this->get_term( $arguments );
			case 'xnetvn/create-term':
				return $this->create_term( $arguments );
			case 'xnetvn/update-term':
				return $this->update_term( $arguments );
			case 'xnetvn/assign-post-terms':
				return $this->assign_terms( $arguments );
			case 'xnetvn/bulk-create-terms':
				return $this->bulk_create( $arguments );
			case 'xnetvn/bulk-assign-post-terms':
				return $this->bulk_assign( $arguments );
			case 'xnetvn/delete-term':
				return $this->delete_term( $arguments );
			default:
				return R::error( 'invalid_params' );
		}
	}

	/**
	 * Assign verified integers to one post for the shared editorial path.
	 *
	 * @param int        $post_id  Target post.
	 * @param string     $taxonomy Taxonomy slug.
	 * @param array<int> $term_ids Positive term identifiers.
	 * @param bool       $append   Replace when false, append when true.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function assign_verified_terms( int $post_id, string $taxonomy, array $term_ids, bool $append = false ): array|WP_Error {
		return ( new self() )->assign_verified( $post_id, $taxonomy, $term_ids, $append );
	}

	/**
	 * List managed taxonomies with fixed-map hints only.
	 *
	 * @param array<string,mixed> $args    Reviewed arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	private function list_taxonomies( array $args, SiteAuthContext $context ): mixed {
		$filter_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : null;
		if ( null !== $filter_type ) {
			$resolved = $this->types->resolve( $filter_type );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
		}
		$items = array();
		foreach ( (array) get_taxonomies( array(), 'names' ) as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			$tax = $this->taxonomies->resolve( $slug );
			if ( is_wp_error( $tax ) ) {
				continue;
			}
			$managed_types = array();
			foreach ( (array) $tax->object_type as $post_type ) {
				if ( ! is_string( $post_type ) ) {
					continue;
				}
				if ( ! is_wp_error( $this->types->resolve( $post_type ) ) ) {
					$managed_types[] = $post_type;
				}
			}
			if ( null !== $filter_type && ! in_array( $filter_type, $managed_types, true ) ) {
				continue;
			}
			$reviewed = null !== $this->editorial_capabilities( $tax );
			$items[]  = array(
				'slug'            => (string) $tax->name,
				'name'            => is_string( $tax->label ) ? $tax->label : (string) $tax->name,
				'description'     => is_string( $tax->description ) ? $tax->description : '',
				'hierarchical'    => (bool) $tax->hierarchical,
				'rest_base'       => $tax->rest_base ? (string) $tax->rest_base : (string) $tax->name,
				'types'           => $managed_types,
				'can_assign'      => $reviewed && $this->scope_allows( $context, 'xnetvn/assign-post-terms' ),
				'can_edit'        => $reviewed && $this->scope_allows( $context, 'xnetvn/update-term' ),
				'can_delete'      => $reviewed && $this->scope_allows( $context, 'xnetvn/delete-term' ),
				'default_term_id' => $this->default_term_id( (string) $tax->name, $tax ),
			);
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['slug'], (string) $b['slug'] ) );
		if ( count( $items ) > 100 ) {
			return array(
				'items'     => array_slice( $items, 0, 100 ),
				'truncated' => true,
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * List terms with exact totals, reviewed filters and bounded pages.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function list_terms( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$hierarchical = (bool) $tax->hierarchical;
		if ( array_key_exists( 'parent', $args ) && ! $hierarchical ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['orderby'] ) && 'include' === $args['orderby'] && empty( $args['include'] ) ) {
			return R::error( 'invalid_params' );
		}
		$page     = array_key_exists( 'page', $args ) ? $args['page'] : 1;
		$per_page = array_key_exists( 'per_page', $args ) ? $args['per_page'] : 20;
		if ( ! is_int( $page ) || ! is_int( $per_page ) || $page < 1 || $page > 100 || $per_page < 1 || $per_page > 100 || $page > intdiv( PHP_INT_MAX, $per_page ) ) {
			return R::error( 'invalid_params' );
		}
		$fields = $this->requested_fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$description_max = array_key_exists( 'description_max_chars', $args ) ? $args['description_max_chars'] : 300;
		if ( ! is_int( $description_max ) || $description_max < 1 || $description_max > 5000 ) {
			return R::error( 'invalid_params' );
		}
		$query = $this->term_query_args( $tax, $args );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$total = get_terms(
			array_merge(
				$query,
				array(
					'fields' => 'count',
					'number' => 0,
					'offset' => 0,
				)
			)
		);
		if ( is_wp_error( $total ) ) {
			return R::sanitize( $total );
		}
		if ( ! is_numeric( $total ) ) {
			return R::error( 'internal_error' );
		}
		$total       = (int) $total;
		$total_pages = (int) ceil( $total / $per_page );
		if ( $page > max( 1, $total_pages ) ) {
			return R::error( 'invalid_params' );
		}
		$offset = ( $page - 1 ) * $per_page;
		// Core skips LIMIT when a parent filter is present, so it is sliced here instead.
		$sliced = array_key_exists( 'parent', $args );
		$terms  = get_terms(
			array_merge(
				$query,
				array(
					'fields' => 'all',
					'number' => $sliced ? 0 : $per_page,
					'offset' => $sliced ? 0 : $offset,
				)
			)
		);
		if ( is_wp_error( $terms ) ) {
			return R::sanitize( $terms );
		}
		if ( ! is_array( $terms ) ) {
			return R::error( 'internal_error' );
		}
		if ( $sliced ) {
			$terms = array_slice( $terms, $offset, $per_page );
		}
		$with_meta = in_array( 'meta', $fields, true );
		$items     = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				return R::error( 'internal_error' );
			}
			try {
				$item = $this->projection->project( $this->term_record( $term, $with_meta ), $fields, $description_max, $hierarchical );
			} catch ( \Throwable ) {
				return R::error( 'internal_error' );
			}
			if ( $with_meta ) {
				$item = $this->filter_projected_meta( $item, (string) $tax->name );
			}
			$items[] = $item;
		}
		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
		);
	}

	/**
	 * Read one term after verifying its taxonomy binding.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function get_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$id   = (int) ( $args['id'] ?? 0 );
		$term = get_term( $id, (string) $tax->name );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
			return R::error( 'not_found' );
		}
		$fields = $this->requested_fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$route    = $this->taxonomies->route( $tax ) . '/' . $id;
		$response = $this->rest->dispatch( 'GET', $route, array( 'context' => 'view' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return R::error( 'internal_error' );
		}
		try {
			$item = $this->projection->project( $data, $fields, 0, (bool) $tax->hierarchical );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		return in_array( 'meta', $fields, true ) ? $this->filter_projected_meta( $item, (string) $tax->name ) : $item;
	}

	/**
	 * Create one term with filtered HTML, parent and meta prechecks.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function create_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'create' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$input = $this->term_input( $tax, $args, 0, true );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		if ( null === $input['name'] ) {
			return R::error( 'invalid_params' );
		}
		$params  = $this->term_write_args( $input, false );
		$name    = $input['name'];
		$created = $this->html->run( fn(): mixed => wp_insert_term( wp_slash( $name ), (string) $tax->name, wp_slash( $params ) ) );
		if ( is_wp_error( $created ) ) {
			return R::sanitize( $created );
		}
		if ( ! is_array( $created ) || ! isset( $created['term_id'] ) ) {
			return R::error( 'internal_error' );
		}
		$term_id = (int) $created['term_id'];
		if ( $term_id < 1 ) {
			return R::error( 'internal_error' );
		}
		if ( null !== $input['meta'] ) {
			foreach ( $input['meta'] as $key => $value ) {
				if ( ! $this->write_meta( $term_id, $key, $value ) ) {
					return new PartialFailure( $term_id, true );
				}
			}
		}
		$stored = get_term( $term_id, (string) $tax->name );
		if ( ! $stored instanceof WP_Term || $stored->taxonomy !== (string) $tax->name ) {
			return new PartialFailure( $term_id, true );
		}
		$item = $this->project_written( $stored, (bool) $tax->hierarchical, $input['slug'] );
		return is_wp_error( $item ) ? new PartialFailure( $term_id, true ) : $item;
	}

	/**
	 * Update one term with filtered HTML, parent and meta prechecks.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function update_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'update' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$id         = (int) ( $args['id'] ?? 0 );
		$has_change = false;
		foreach ( array( 'name', 'slug', 'description', 'parent', 'meta' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$has_change = true;
				break;
			}
		}
		if ( ! $has_change ) {
			return R::error( 'invalid_params' );
		}
		$term = get_term( $id, (string) $tax->name );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
			return R::error( 'not_found' );
		}
		$input = $this->term_input( $tax, $args, $id, true );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$params = $this->term_write_args( $input );
		if ( array() !== $params ) {
			$updated = $this->html->run( fn(): mixed => wp_update_term( $id, (string) $tax->name, wp_slash( $params ) ) );
			if ( is_wp_error( $updated ) ) {
				return R::sanitize( $updated );
			}
			if ( ! is_array( $updated ) || ! isset( $updated['term_id'] ) ) {
				return R::error( 'internal_error' );
			}
		}
		if ( null !== $input['meta'] ) {
			foreach ( $input['meta'] as $key => $value ) {
				if ( ! $this->write_meta( $id, $key, $value ) ) {
					return new PartialFailure( $id, false );
				}
			}
		}
		$stored = get_term( $id, (string) $tax->name );
		if ( ! $stored instanceof WP_Term || $stored->taxonomy !== (string) $tax->name ) {
			return new PartialFailure( $id, false );
		}
		$item = $this->project_written( $stored, (bool) $tax->hierarchical, $input['slug'] );
		return is_wp_error( $item ) ? new PartialFailure( $id, false ) : $item;
	}

	/**
	 * Assign replacement, append or removal integers to one post.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function assign_terms( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'assign' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$mode = (string) ( $args['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'replace', 'append', 'remove' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		$term_ids = $this->validated_term_ids( $args['term_ids'] ?? null );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		if ( 'replace' !== $mode && array() === $term_ids ) {
			return R::error( 'invalid_params' );
		}
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id < 1 ) {
			return R::error( 'not_found' );
		}
		$result = $this->assign_post( $tax, $post_id, $term_ids, $mode );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return MutationResult::project( $post_id, false, static fn(): array => $result );
	}

	/**
	 * Ensure up to fifty terms exist without aborting the batch.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function bulk_create( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'create' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_values( $args['items'] ) : array();
		if ( 0 === count( $items ) || count( $items ) > 50 ) {
			return R::error( 'invalid_params' );
		}
		$result = array(
			'taxonomy' => (string) $tax->name,
			'created'  => array(),
			'existing' => array(),
			'failed'   => array(),
		);
		foreach ( $items as $index => $item ) {
			$failure = array(
				'index' => (int) $index,
				'code'  => 'invalid_params',
			);
			if ( ! is_array( $item ) ) {
				$result['failed'][] = $failure;
				continue;
			}
			try {
				$input = $this->term_input( $tax, $item, 0, false );
				if ( is_wp_error( $input ) ) {
					$failure['code']    = R::reason( (string) $input->get_error_code() );
					$result['failed'][] = $failure;
					continue;
				}
				if ( null === $input['name'] ) {
					$result['failed'][] = $failure;
					continue;
				}
				$name    = $input['name'];
				$created = $this->html->run( fn(): mixed => wp_insert_term( wp_slash( $name ), (string) $tax->name, wp_slash( $this->term_write_args( $input, false ) ) ) );
				if ( is_wp_error( $created ) ) {
					$code = R::reason( (string) $created->get_error_code() );
					if ( 'duplicate_term' === $code ) {
						$existing = $this->find_existing_term( $tax, $input );
						if ( null !== $existing ) {
							$result['existing'][] = array(
								'index' => (int) $index,
								'id'    => (int) $existing->term_id,
								'name'  => (string) $existing->name,
								'slug'  => (string) $existing->slug,
							);
							continue;
						}
					}
					$failure['code']    = $code;
					$result['failed'][] = $failure;
					continue;
				}
				if ( ! is_array( $created ) || ! isset( $created['term_id'] ) ) {
					$failure['code']    = 'partial_failure';
					$result['failed'][] = $failure;
					continue;
				}
				$stored = get_term( (int) $created['term_id'], (string) $tax->name );
				if ( ! $stored instanceof WP_Term || $stored->taxonomy !== (string) $tax->name ) {
					$failure['code']    = 'partial_failure';
					$result['failed'][] = $failure;
					continue;
				}
				$result['created'][] = array(
					'index' => (int) $index,
					'id'    => (int) $stored->term_id,
					'name'  => (string) $stored->name,
					'slug'  => (string) $stored->slug,
				);
			} catch ( \Throwable ) {
				$result['failed'][] = array(
					'index' => (int) $index,
					'code'  => 'partial_failure',
				);
			}
		}
		return $result;
	}

	/**
	 * Append or remove validated integers for up to fifty posts.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function bulk_assign( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'assign' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$mode     = (string) ( $args['mode'] ?? '' );
		$post_ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? array_values( $args['post_ids'] ) : array();
		$term_ids = $this->validated_term_ids( $args['term_ids'] ?? null );
		if ( is_wp_error( $term_ids ) ) {
			return $term_ids;
		}
		if ( ! in_array( $mode, array( 'append', 'remove' ), true ) || array() === $post_ids || array() === $term_ids ) {
			return R::error( 'invalid_params' );
		}
		if ( count( $post_ids ) > 50 ) {
			return R::error( 'invalid_params' );
		}
		foreach ( $post_ids as $post_id ) {
			if ( ! is_int( $post_id ) || $post_id < 1 ) {
				return R::error( 'invalid_params' );
			}
		}
		$terms = $this->require_terms( $tax, $term_ids );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$result       = array(
			'taxonomy'  => (string) $tax->name,
			'mode'      => $mode,
			'updated'   => array(),
			'unchanged' => array(),
			'failed'    => array(),
		);
		$was_deferred = wp_defer_term_counting();
		wp_defer_term_counting( true );
		try {
			foreach ( $post_ids as $post_id ) {
				try {
					$single = $this->assign_post( $tax, (int) $post_id, $term_ids, $mode );
				} catch ( \Throwable ) {
					$single = R::error( 'partial_failure' );
				}
				if ( is_wp_error( $single ) ) {
					$result['failed'][] = array(
						'id'   => (int) $post_id,
						'code' => R::reason( (string) $single->get_error_code() ),
					);
					continue;
				}
				$changed = array() !== $single['added'] || array() !== $single['removed'] || ! empty( $single['default_term_applied'] );
				if ( $changed ) {
					$result['updated'][] = (int) $post_id;
					continue;
				}
				$result['unchanged'][] = (int) $post_id;
			}
		} finally {
			if ( true !== $was_deferred ) {
				wp_defer_term_counting( false );
			}
		}
		return $result;
	}

	/**
	 * Delete one term with the default guard before dry runs and writes.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return mixed
	 */
	private function delete_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'delete' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$id       = (int) ( $args['id'] ?? 0 );
		$taxonomy = (string) $tax->name;
		if ( '' === $taxonomy ) {
			return R::error( 'taxonomy_unavailable' );
		}
		$term = get_term( $id, $taxonomy );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
			return R::error( 'not_found' );
		}
		if ( $this->is_default_term( $taxonomy, $id ) ) {
			return R::error( 'invalid_state' );
		}
		$children   = $this->children_count( $tax, $id );
		$default_id = $this->default_term_id( $taxonomy, $tax );
		$dry_run    = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : false;
		if ( $dry_run ) {
			return array(
				'id'       => $id,
				'taxonomy' => $taxonomy,
				'deleted'  => false,
				'dry_run'  => true,
				'impact'   => array(
					'children'                 => $children,
					'new_parent_for_children'  => (int) $term->parent,
					'published_count'          => (int) $term->count,
					'will_reassign_to_default' => null !== $default_id,
				),
			);
		}
		$deleted = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $deleted ) ) {
			return R::sanitize( $deleted );
		}
		if ( true !== $deleted ) {
			return R::error( 'internal_error' );
		}
		return array(
			'id'                  => $id,
			'taxonomy'            => $taxonomy,
			'deleted'             => true,
			'children_reparented' => $children,
		);
	}

	/**
	 * Assign verified integers to one post for FR_14.
	 *
	 * @param int        $post_id  Target post.
	 * @param string     $taxonomy Taxonomy slug.
	 * @param array<int> $term_ids Positive term identifiers.
	 * @param bool       $append   Replace when false, append when true.
	 * @return array<string,mixed>|WP_Error
	 */
	private function assign_verified( int $post_id, string $taxonomy, array $term_ids, bool $append ): array|WP_Error {
		$tax = $this->taxonomies->resolve( $taxonomy );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$access = $this->require_write_access( $tax, 'assign' );
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		$ids = $this->validated_term_ids( $term_ids );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		if ( $append && array() === $ids ) {
			return R::error( 'invalid_params' );
		}
		if ( $post_id < 1 ) {
			return R::error( 'not_found' );
		}
		return $this->assign_post( $tax, $post_id, $ids, $append ? 'append' : 'replace' );
	}

	/**
	 * Assign one post through the validated integer path.
	 *
	 * @param WP_Taxonomy $tax      Resolved taxonomy.
	 * @param int         $post_id  Target post.
	 * @param array<int>  $term_ids Verified positive identifiers.
	 * @param string      $mode     Replace, append or remove.
	 * @return array<string,mixed>|WP_Error
	 */
	private function assign_post( WP_Taxonomy $tax, int $post_id, array $term_ids, string $mode ): array|WP_Error {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return R::error( 'not_found' );
		}
		$post_type = $this->types->resolve( (string) $post->post_type );
		if ( is_wp_error( $post_type ) ) {
			return R::error( 'post_type_unavailable' );
		}
		if ( ! in_array( $this->capability_base( $post_type ), array( 'post', 'page' ), true ) ) {
			return R::error( 'post_type_unavailable' );
		}
		if ( in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return R::error( 'invalid_state' );
		}
		if ( ! is_object_in_taxonomy( (string) $post->post_type, (string) $tax->name ) ) {
			return R::error( 'unsupported_operation' );
		}
		$terms = $this->require_terms( $tax, $term_ids );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$before = wp_get_object_terms(
			$post_id,
			(string) $tax->name,
			array(
				'fields'  => 'ids',
				'orderby' => 'none',
			)
		);
		if ( is_wp_error( $before ) ) {
			return R::error( 'internal_error' );
		}
		$before = array_values( array_unique( array_map( 'intval', (array) $before ) ) );
		sort( $before );
		$ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		sort( $ids );
		if ( 'replace' === $mode ) {
			$expected = $ids;
		} elseif ( 'append' === $mode ) {
			$expected = array_values( array_unique( array_merge( $before, $ids ) ) );
		} else {
			$expected = array_values( array_diff( $before, $ids ) );
		}
		sort( $expected );
		if ( $expected === $before ) {
			return array(
				'post_id'              => $post_id,
				'taxonomy'             => (string) $tax->name,
				'mode'                 => $mode,
				'term_ids'             => $expected,
				'added'                => array(),
				'removed'              => array(),
				'default_term_applied' => false,
			);
		}
		$default_applied = false;
		if ( array() === $expected ) {
			$default_id = $this->default_for_empty_assignment( $tax, (string) $post->post_type );
			if ( $default_id > 0 ) {
				$expected        = array( $default_id );
				$default_applied = true;
			}
		}
		if ( $default_applied ) {
			$written = wp_set_object_terms( $post_id, $expected, (string) $tax->name, false );
		} elseif ( 'remove' === $mode ) {
			$to_remove = array_values( array_diff( $before, $expected ) );
			$written   = array() === $to_remove ? true : wp_remove_object_terms( $post_id, $to_remove, (string) $tax->name );
		} else {
			$written = wp_set_object_terms( $post_id, $expected, (string) $tax->name, 'append' === $mode );
		}
		if ( is_wp_error( $written ) || false === $written ) {
			return R::error( 'internal_error' );
		}
		$after = wp_get_object_terms(
			$post_id,
			(string) $tax->name,
			array(
				'fields'  => 'ids',
				'orderby' => 'none',
			)
		);
		if ( is_wp_error( $after ) ) {
			return new PartialFailure( $post_id, false );
		}
		$after = array_values( array_unique( array_map( 'intval', (array) $after ) ) );
		sort( $after );
		if ( $after !== $expected ) {
			return new PartialFailure( $post_id, false );
		}
		return array(
			'post_id'              => $post_id,
			'taxonomy'             => (string) $tax->name,
			'mode'                 => $mode,
			'term_ids'             => $after,
			'added'                => array_values( array_diff( $after, $before ) ),
			'removed'              => array_values( array_diff( $before, $after ) ),
			'default_term_applied' => $default_applied,
		);
	}

	/**
	 * Map reviewed inputs onto one bounded term query.
	 *
	 * @param WP_Taxonomy         $tax  Resolved taxonomy.
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	private function term_query_args( WP_Taxonomy $tax, array $args ): array|WP_Error {
		$query = array(
			'taxonomy'   => (string) $tax->name,
			'hide_empty' => false,
		);
		if ( array_key_exists( 'hide_empty', $args ) ) {
			if ( ! is_bool( $args['hide_empty'] ) ) {
				return R::error( 'invalid_params' );
			}
			$query['hide_empty'] = $args['hide_empty'];
		}
		foreach ( array( 'include', 'exclude' ) as $key ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}
			if ( ! is_array( $args[ $key ] ) ) {
				return R::error( 'invalid_params' );
			}
			$query[ $key ] = array_values( array_map( 'intval', $args[ $key ] ) );
		}
		if ( array_key_exists( 'slug', $args ) ) {
			if ( ! is_array( $args['slug'] ) ) {
				return R::error( 'invalid_params' );
			}
			$query['slug'] = array_values( array_map( 'strval', $args['slug'] ) );
		}
		if ( array_key_exists( 'search', $args ) ) {
			if ( ! is_string( $args['search'] ) ) {
				return R::error( 'invalid_params' );
			}
			$query['search'] = $args['search'];
		}
		if ( array_key_exists( 'parent', $args ) ) {
			if ( ! is_int( $args['parent'] ) || $args['parent'] < 0 ) {
				return R::error( 'invalid_params' );
			}
			$query['parent'] = $args['parent'];
		}
		if ( array_key_exists( 'post', $args ) ) {
			if ( ! is_int( $args['post'] ) || $args['post'] < 1 ) {
				return R::error( 'invalid_params' );
			}
			$query['object_ids'] = $args['post'];
		}
		if ( array_key_exists( 'orderby', $args ) ) {
			$orderby = (string) $args['orderby'];
			if ( ! in_array( $orderby, array( 'id', 'name', 'slug', 'count', 'description', 'include' ), true ) ) {
				return R::error( 'invalid_params' );
			}
			$query['orderby'] = $orderby;
		}
		if ( array_key_exists( 'order', $args ) ) {
			$order = (string) $args['order'];
			if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
				return R::error( 'invalid_params' );
			}
			$query['order'] = $order;
		}
		return $query;
	}

	/**
	 * Validate and filter one term payload before any write.
	 *
	 * @param WP_Taxonomy         $tax        Resolved taxonomy.
	 * @param array<string,mixed> $args       Reviewed arguments.
	 * @param int                 $exclude_id Term being updated, zero when creating.
	 * @param bool                $with_meta  Whether registered meta is accepted.
	 * @return array{name:string|null,slug:string|null,description:string|null,parent:int|null,meta:array<string,mixed>|null}|WP_Error
	 */
	private function term_input( WP_Taxonomy $tax, array $args, int $exclude_id, bool $with_meta ): array|WP_Error {
		$name        = null;
		$slug        = null;
		$description = null;
		$parent      = null;
		$meta        = null;
		if ( array_key_exists( 'name', $args ) ) {
			if ( ! is_string( $args['name'] ) ) {
				return R::error( 'invalid_params' );
			}
			$name = $this->html->filter_plain_text( $args['name'] );
			if ( '' === trim( $name ) || mb_strlen( $name ) > 200 ) {
				return R::error( 'invalid_params' );
			}
		}
		if ( array_key_exists( 'slug', $args ) ) {
			if ( ! is_string( $args['slug'] ) || '' === $args['slug'] || mb_strlen( $args['slug'] ) > 200 ) {
				return R::error( 'invalid_params' );
			}
			$slug = $args['slug'];
		}
		if ( array_key_exists( 'description', $args ) ) {
			if ( ! is_string( $args['description'] ) ) {
				return R::error( 'invalid_params' );
			}
			$description = $this->html->filter_post_html( $args['description'] );
			if ( mb_strlen( $description ) > 5000 ) {
				return R::error( 'invalid_params' );
			}
		}
		if ( array_key_exists( 'parent', $args ) ) {
			$checked = $this->check_parent( $tax, $args['parent'], $exclude_id );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$parent = (int) $args['parent'];
		}
		if ( $with_meta && array_key_exists( 'meta', $args ) ) {
			$filtered = $this->filter_meta_values( (string) $tax->name, $args['meta'] );
			if ( is_wp_error( $filtered ) ) {
				return $filtered;
			}
			$meta = $filtered;
		}
		return array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => $description,
			'parent'      => $parent,
			'meta'        => $meta,
		);
	}

	/**
	 * Build the core term write arguments from one filtered payload.
	 *
	 * @param array{name:string|null,slug:string|null,description:string|null,parent:int|null,meta:array<string,mixed>|null} $input Filtered payload.
	 * @param bool                                                                                                           $with_name Whether the name is passed in the argument array.
	 * @return array<string,mixed>
	 */
	private function term_write_args( array $input, bool $with_name = true ): array {
		$params = array();
		if ( $with_name && null !== $input['name'] ) {
			$params['name'] = $input['name'];
		}
		if ( null !== $input['slug'] ) {
			$params['slug'] = $input['slug'];
		}
		if ( null !== $input['description'] ) {
			$params['description'] = $input['description'];
		}
		if ( null !== $input['parent'] ) {
			$params['parent'] = $input['parent'];
		}
		return $params;
	}

	/**
	 * Write one reviewed meta value and report only a real failure.
	 *
	 * @param int    $term_id Term.
	 * @param string $key     Registered key.
	 * @param mixed  $value   Filtered value.
	 * @return bool
	 */
	private function write_meta( int $term_id, string $key, mixed $value ): bool {
		if ( false !== update_term_meta( $term_id, $key, wp_slash( $value ) ) ) {
			return true;
		}
		// update_term_meta() also reports false when the stored value is unchanged.
		$stored = get_term_meta( $term_id, $key, true );
		if ( $stored === $value ) {
			return true;
		}
		return is_scalar( $value ) && is_scalar( $stored ) && (string) $stored === (string) $value;
	}

	/**
	 * Filter registered term meta against the core REST term schema.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param mixed  $meta     Submitted meta.
	 * @return array<string,mixed>|WP_Error
	 */
	private function filter_meta_values( string $taxonomy, mixed $meta ): array|WP_Error {
		if ( ! is_array( $meta ) || count( $meta ) > 20 ) {
			return R::error( 'invalid_params' );
		}
		$schema     = ( new WP_REST_Term_Meta_Fields( $taxonomy ) )->get_field_schema();
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		$filtered   = array();
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || str_starts_with( $key, '_' ) ) {
				return R::error( 'invalid_params' );
			}
			if ( ! array_key_exists( $key, $registered ) || empty( $registered[ $key ]['show_in_rest'] ) ) {
				return R::error( 'invalid_params' );
			}
			$field = $properties[ $key ] ?? null;
			if ( ! is_array( $field ) ) {
				return R::error( 'invalid_params' );
			}
			$valid = rest_validate_value_from_schema( $value, $field );
			if ( is_wp_error( $valid ) || true !== $valid ) {
				return R::error( 'invalid_params' );
			}
			$value   = rest_sanitize_value_from_schema( $value, $field );
			$value   = $this->html->filter_meta( $field, $value );
			$checked = is_wp_error( $value ) ? $value : rest_validate_value_from_schema( $value, $field );
			if ( is_wp_error( $checked ) || true !== $checked ) {
				return R::error( 'invalid_params' );
			}
			$filtered[ $key ] = $value;
		}
		return $filtered;
	}

	/**
	 * Narrow registered show_in_rest term meta to reviewed keys.
	 *
	 * @param array<string,mixed> $item     Projected term.
	 * @param string              $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	private function filter_projected_meta( array $item, string $taxonomy ): array {
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		$values     = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array();
		$filtered   = array();
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $registered ) || empty( $registered[ $key ]['show_in_rest'] ) ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}
		if ( array() === $filtered ) {
			unset( $item['meta'] );
			return $item;
		}
		$item['meta'] = $filtered;
		return $item;
	}

	/**
	 * Read registered show_in_rest term meta for one term.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	private function registered_meta( int $term_id, string $taxonomy ): array {
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		$meta       = array();
		foreach ( $registered as $key => $definition ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) || empty( $definition['show_in_rest'] ) ) {
				continue;
			}
			$single = ! empty( $definition['single'] );
			$value  = get_term_meta( $term_id, $key, $single );
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$meta[ $key ] = $value;
		}
		return $meta;
	}

	/**
	 * Build one allowlisted projection record from a stored term.
	 *
	 * @param WP_Term $term      Stored term.
	 * @param bool    $with_meta Whether registered meta is read.
	 * @return array<string,mixed>
	 */
	private function term_record( WP_Term $term, bool $with_meta ): array {
		$record = array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
		$link   = get_term_link( $term );
		if ( is_string( $link ) && '' !== $link ) {
			$record['link'] = $link;
		}
		if ( $with_meta ) {
			$record['meta'] = $this->registered_meta( (int) $term->term_id, (string) $term->taxonomy );
		}
		return $record;
	}

	/**
	 * Project one written term and report slug adjustment.
	 *
	 * @param WP_Term     $term         Stored term.
	 * @param bool        $hierarchical Whether the taxonomy is hierarchical.
	 * @param string|null $sent_slug    Submitted slug.
	 * @return array<string,mixed>|WP_Error
	 */
	private function project_written( WP_Term $term, bool $hierarchical, ?string $sent_slug ): array|WP_Error {
		try {
			$item = $this->projection->project( $this->term_record( $term, false ), array(), 0, $hierarchical );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		$item['slug_adjusted'] = null !== $sent_slug && sanitize_title( $sent_slug ) !== (string) ( $item['slug'] ?? '' );
		$meta                  = $this->registered_meta( (int) $term->term_id, (string) $term->taxonomy );
		if ( array() !== $meta ) {
			$item['meta'] = $meta;
		}
		return $item;
	}

	/**
	 * Read the reviewed capability values from one taxonomy.
	 *
	 * The reviewed families mirror what core `map_meta_cap()` resolves for the
	 * built-in editorial capabilities, so the accountless principal never
	 * evaluates a WordPress user capability.
	 *
	 * @param WP_Taxonomy $tax Taxonomy.
	 * @return array{manage_terms:string,edit_terms:string,delete_terms:string,assign_terms:string}|null
	 */
	private function editorial_capabilities( WP_Taxonomy $tax ): ?array {
		if ( ! is_object( $tax->cap ) ) {
			return null;
		}
		$reviewed = array();
		foreach ( self::REVIEWED_TERM_CAPS as $key => $allowed ) {
			$value = $tax->cap->{$key} ?? null;
			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
				return null;
			}
			$reviewed[ $key ] = $value;
		}
		return $reviewed;
	}

	/**
	 * Require the complete reviewed map and the exact operation capability.
	 *
	 * @param WP_Taxonomy $tax       Taxonomy.
	 * @param string      $operation create, update, delete or assign.
	 * @return bool|WP_Error
	 */
	private function require_write_access( WP_Taxonomy $tax, string $operation ): bool|WP_Error {
		$capabilities = $this->editorial_capabilities( $tax );
		if ( null === $capabilities ) {
			return R::error( 'taxonomy_unavailable' );
		}
		$required = match ( $operation ) {
			'create' => (bool) $tax->hierarchical ? 'edit_terms' : 'assign_terms',
			'update' => 'edit_terms',
			'delete' => 'delete_terms',
			'assign' => 'assign_terms',
			default  => '',
		};
		if ( '' === $required || ! isset( $capabilities[ $required ] ) ) {
			return R::error( 'taxonomy_unavailable' );
		}
		return true;
	}

	/**
	 * Check whether the key scope carries one reviewed operation name.
	 *
	 * @param SiteAuthContext $context        Verified machine identity.
	 * @param string          $canonical_name Canonical operation name.
	 * @return bool
	 */
	private function scope_allows( SiteAuthContext $context, string $canonical_name ): bool {
		$scope = $context->credential->scope;
		return in_array( $canonical_name, $scope->allowed_abilities, true ) && ! in_array( $canonical_name, $scope->denied_abilities, true );
	}

	/**
	 * Normalize reviewed integer term identifiers.
	 *
	 * @param mixed $value Submitted identifiers.
	 * @return array<int>|WP_Error
	 */
	private function validated_term_ids( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) ) {
			return R::error( 'invalid_params' );
		}
		$ids = array();
		foreach ( array_values( $value ) as $candidate ) {
			if ( ! is_int( $candidate ) || $candidate < 1 ) {
				return R::error( 'invalid_params' );
			}
			$ids[] = $candidate;
		}
		if ( count( $ids ) !== count( array_unique( $ids ) ) || count( $ids ) > 100 ) {
			return R::error( 'invalid_params' );
		}
		return $ids;
	}

	/**
	 * Require every term to exist in the requested taxonomy.
	 *
	 * @param WP_Taxonomy $tax      Resolved taxonomy.
	 * @param array<int>  $term_ids Verified identifiers.
	 * @return bool|WP_Error
	 */
	private function require_terms( WP_Taxonomy $tax, array $term_ids ): bool|WP_Error {
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, (string) $tax->name );
			if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
				return R::error( 'not_found' );
			}
		}
		return true;
	}

	/**
	 * Check parent preconditions without writing anything.
	 *
	 * @param WP_Taxonomy $tax        Taxonomy.
	 * @param mixed       $parent_id  Candidate parent.
	 * @param int         $exclude_id Term being updated, zero when creating.
	 * @return bool|WP_Error
	 */
	private function check_parent( WP_Taxonomy $tax, mixed $parent_id, int $exclude_id ): bool|WP_Error {
		if ( ! is_int( $parent_id ) || $parent_id < 0 ) {
			return R::error( 'invalid_params' );
		}
		if ( ! (bool) $tax->hierarchical ) {
			return R::error( 'invalid_params' );
		}
		if ( 0 === $parent_id ) {
			return true;
		}
		$parent_term = get_term( $parent_id, (string) $tax->name );
		if ( ! $parent_term instanceof WP_Term || $parent_term->taxonomy !== (string) $tax->name ) {
			return R::error( 'invalid_params' );
		}
		if ( $exclude_id > 0 ) {
			if ( $parent_id === $exclude_id ) {
				return R::error( 'invalid_params' );
			}
			$ancestors = get_ancestors( $parent_id, (string) $tax->name, 'taxonomy' );
			if ( is_array( $ancestors ) && in_array( $exclude_id, array_map( 'intval', $ancestors ), true ) ) {
				return R::error( 'invalid_params' );
			}
		}
		return true;
	}

	/**
	 * Read the default term ID for listings and guards.
	 *
	 * @param string      $taxonomy Taxonomy slug.
	 * @param WP_Taxonomy $tax      Taxonomy.
	 * @return int|null
	 */
	private function default_term_id( string $taxonomy, WP_Taxonomy $tax ): ?int {
		unset( $tax );
		foreach ( array( (int) get_option( 'default_' . $taxonomy ), (int) get_option( 'default_term_' . $taxonomy ) ) as $candidate ) {
			if ( $candidate > 0 ) {
				$term = get_term( $candidate, $taxonomy );
				if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * Whether a term is a protected default term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term.
	 * @return bool
	 */
	private function is_default_term( string $taxonomy, int $term_id ): bool {
		return (int) get_option( 'default_' . $taxonomy ) === $term_id || (int) get_option( 'default_term_' . $taxonomy ) === $term_id;
	}

	/**
	 * Default term to apply when an assignment empties a taxonomy.
	 *
	 * @param WP_Taxonomy $tax       Taxonomy.
	 * @param string      $post_type Post type.
	 * @return int
	 */
	private function default_for_empty_assignment( WP_Taxonomy $tax, string $post_type ): int {
		$taxonomy = (string) $tax->name;
		if ( 'category' === $taxonomy ) {
			$extra = array();
			try {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for default category post types.
				$filtered = apply_filters( 'default_category_post_types', array() );
				if ( is_array( $filtered ) ) {
					$extra = $filtered;
				}
			} catch ( \Throwable ) {
				$extra = array();
			}
			if ( ! in_array( $post_type, array_merge( $extra, array( 'post' ) ), true ) ) {
				return 0;
			}
			$candidate = (int) get_option( 'default_category' );
			if ( $candidate > 0 ) {
				$term = get_term( $candidate, $taxonomy );
				if ( $term instanceof WP_Term ) {
					return $candidate;
				}
			}
			return 0;
		}
		if ( ! empty( $tax->default_term ) ) {
			$candidate = (int) get_option( 'default_term_' . $taxonomy );
			if ( $candidate > 0 ) {
				$term = get_term( $candidate, $taxonomy );
				if ( $term instanceof WP_Term ) {
					return $candidate;
				}
			}
		}
		return 0;
	}

	/**
	 * Find an existing term after a duplicate report without trusting error data.
	 *
	 * @param WP_Taxonomy                                                                                                    $tax   Taxonomy.
	 * @param array{name:string|null,slug:string|null,description:string|null,parent:int|null,meta:array<string,mixed>|null} $input Filtered payload.
	 * @return WP_Term|null
	 */
	private function find_existing_term( WP_Taxonomy $tax, array $input ): ?WP_Term {
		$taxonomy = (string) $tax->name;
		$name     = null === $input['name'] ? '' : $input['name'];
		$parent   = null === $input['parent'] ? 0 : $input['parent'];
		if ( '' === $name ) {
			return null;
		}
		$found = term_exists( $name, $taxonomy, $parent > 0 ? $parent : null );
		if ( is_array( $found ) && isset( $found['term_id'] ) ) {
			$term = get_term( (int) $found['term_id'], $taxonomy );
			if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy && (int) $term->parent === $parent ) {
				return $term;
			}
		}
		if ( null !== $input['slug'] && '' !== $input['slug'] ) {
			$by_slug = get_term_by( 'slug', $input['slug'], $taxonomy );
			if ( $by_slug instanceof WP_Term && $by_slug->taxonomy === $taxonomy && (int) $by_slug->parent === $parent ) {
				return $by_slug;
			}
		}
		return null;
	}

	/**
	 * Count the direct children of one term.
	 *
	 * @param WP_Taxonomy $tax Taxonomy.
	 * @param int         $id  Term.
	 * @return int
	 */
	private function children_count( WP_Taxonomy $tax, int $id ): int {
		if ( ! (bool) $tax->hierarchical ) {
			return 0;
		}
		$count = get_terms(
			array(
				'taxonomy'   => (string) $tax->name,
				'parent'     => $id,
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Return the post type capability base.
	 *
	 * @param \WP_Post_Type $type Post type.
	 * @return string
	 */
	private function capability_base( \WP_Post_Type $type ): string {
		$capability = $type->capability_type;
		if ( is_array( $capability ) ) {
			$capability = $capability[0] ?? '';
		}
		return is_string( $capability ) ? $capability : '';
	}

	/**
	 * Validate the optional narrowing field list.
	 *
	 * @param array<string,mixed> $args Reviewed arguments.
	 * @return array<string>|WP_Error
	 */
	private function requested_fields( array $args ): array|WP_Error {
		if ( ! array_key_exists( 'fields', $args ) ) {
			return array();
		}
		if ( ! is_array( $args['fields'] ) ) {
			return R::error( 'invalid_params' );
		}
		$fields = array();
		foreach ( array_values( $args['fields'] ) as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, TermProjection::FIELDS, true ) ) {
				return R::error( 'invalid_params' );
			}
			$fields[] = $field;
		}
		return $fields;
	}
}
