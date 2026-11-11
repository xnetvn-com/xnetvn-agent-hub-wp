<?php
/**
 * Reviewed taxonomy operations with core capability checks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Taxonomy;

use WP_Error;
use WP_Taxonomy;
use WP_Term;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\MutationResponse;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\Packs\Content\PostTypeResolver;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;

/** Reads, writes and deletes use core REST; assignment uses core term functions. */
final class TaxonomyOperations {
	/** Internal dispatcher.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;
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

	/** Bind pure infrastructure. */
	public function __construct() {
		$this->rest       = new RestBackedAbility();
		$this->taxonomies = new TaxonomyResolver();
		$this->types      = new PostTypeResolver();
		$this->projection = new TermProjection();
	}

	/**
	 * Run a reviewed operation after registry schema/policy validation.
	 *
	 * @param string              $name Reviewed operation.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $args ): mixed {
		switch ( $name ) {
			case 'list-taxonomies':
				return $this->list_taxonomies( $args );
			case 'list-terms':
				return $this->list_terms( $args );
			case 'get-term':
				return $this->get_term( $args );
			case 'create-term':
				return $this->create_term( $args );
			case 'update-term':
				return $this->update_term( $args );
			case 'assign-post-terms':
				return $this->assign_terms( $args );
			case 'bulk-create-terms':
				return $this->bulk_create( $args );
			case 'bulk-assign-post-terms':
				return $this->bulk_assign( $args );
			case 'delete-term':
				return $this->delete_term( $args );
			default:
				return R::error( 'invalid_params' );
		}
	}

	/**
	 * List managed taxonomies with capability hints.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function list_taxonomies( array $args ): mixed {
		$filter_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : null;
		if ( null !== $filter_type ) {
			$resolved = $this->types->resolve( $filter_type );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
		}
		$params = array( 'context' => 'view' );
		if ( null !== $filter_type ) {
			$params['type'] = $filter_type;
		}
		$response = $this->rest->dispatch( 'GET', '/wp/v2/taxonomies', $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data  = $response->get_data();
		$items = array();
		foreach ( (array) $data as $slug => $row ) {
			$tax = $this->taxonomies->resolve( (string) $slug );
			if ( is_wp_error( $tax ) ) {
				continue;
			}
			$managed_types = array();
			foreach ( (array) $tax->object_type as $post_type ) {
				if ( ! is_wp_error( $this->types->resolve( (string) $post_type ) ) ) {
					$managed_types[] = (string) $post_type;
				}
			}
			if ( null !== $filter_type && ! in_array( $filter_type, $managed_types, true ) ) {
				continue;
			}
			$items[] = array(
				'slug'            => (string) $tax->name,
				'name'            => is_string( $tax->label ) ? $tax->label : (string) $tax->name,
				'description'     => is_string( $tax->description ) ? $tax->description : '',
				'hierarchical'    => (bool) $tax->hierarchical,
				'rest_base'       => $tax->rest_base ? (string) $tax->rest_base : (string) $tax->name,
				'types'           => $managed_types,
				'can_assign'      => current_user_can( $tax->cap->assign_terms ),
				'can_edit'        => current_user_can( $tax->cap->edit_terms ),
				'can_delete'      => current_user_can( $tax->cap->delete_terms ),
				'default_term_id' => $this->default_term_id( (string) $tax->name, $tax ),
			);
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['slug'], (string) $b['slug'] ) );
		if ( count( $items ) > 100 ) {
			$items = array_slice( $items, 0, 100 );
			return array(
				'items'     => $items,
				'truncated' => true,
			);
		}
		return array( 'items' => $items );
	}

	/**
	 * List terms with reviewed filters and pagination.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function list_terms( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$hierarchical = (bool) $tax->hierarchical;
		if ( isset( $args['parent'] ) && ! $hierarchical ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['orderby'] ) && 'include' === $args['orderby'] && empty( $args['include'] ) ) {
			return R::error( 'invalid_params' );
		}
		$route  = $this->taxonomies->route( $tax );
		$params = array( 'context' => 'view' );
		foreach ( array( 'search', 'parent', 'include', 'exclude', 'slug', 'post', 'hide_empty', 'orderby', 'order' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}
		$page               = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$per_page           = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$params['page']     = $page;
		$params['per_page'] = $per_page;
		if ( $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return R::error( 'invalid_params' );
		}
		$response = $this->rest->dispatch( 'GET', $route, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$fields   = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : array();
		$desc_max = isset( $args['description_max_chars'] ) ? (int) $args['description_max_chars'] : 300;
		if ( $desc_max < 1 || $desc_max > 5000 ) {
			return R::error( 'invalid_params' );
		}
		$items = array();
		foreach ( (array) $response->get_data() as $row ) {
			if ( ! is_array( $row ) ) {
				return R::error( 'internal_error' );
			}
			try {
				$item = $this->projection->project( $row, $fields, $desc_max, $hierarchical );
			} catch ( \Throwable ) {
				return R::error( 'internal_error' );
			}
			$item    = $this->filter_meta( $item, (string) $tax->name, $fields );
			$items[] = $item;
		}
		$result = $this->rest->collection( $response, $page, static fn( array $row ): array => $row );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$total_pages = (int) $result['total_pages'];
		if ( $page > max( 1, $total_pages ) ) {
			return R::error( 'invalid_params' );
		}
		$result['items'] = $items;
		return $result;
	}

	/**
	 * Read one term after verifying its taxonomy binding.
	 *
	 * @param array<string,mixed> $args Arguments.
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
		$route    = $this->taxonomies->route( $tax ) . '/' . $id;
		$response = $this->rest->dispatch( 'GET', $route, array( 'context' => 'view' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return R::error( 'internal_error' );
		}
		$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : array();
		try {
			$item = $this->projection->project( $data, $fields, 0, (bool) $tax->hierarchical );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		return $this->filter_meta( $item, (string) $tax->name, $fields );
	}

	/**
	 * Create one term with parent and meta prechecks.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function create_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( '' === trim( $name ) || mb_strlen( $name ) > 200 ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['slug'] ) && ( '' === (string) $args['slug'] || mb_strlen( (string) $args['slug'] ) > 200 ) ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['description'] ) && mb_strlen( (string) $args['description'] ) > 5000 ) {
			return R::error( 'invalid_params' );
		}
		$parent_check = $this->check_parent( $tax, $args['parent'] ?? null, 0 );
		if ( is_wp_error( $parent_check ) ) {
			return $parent_check;
		}
		if ( isset( $args['meta'] ) ) {
			$meta_check = $this->check_meta_keys( (string) $tax->name, $args['meta'] );
			if ( is_wp_error( $meta_check ) ) {
				return $meta_check;
			}
		}
		$params = array( 'name' => $name );
		foreach ( array( 'slug', 'description', 'parent', 'meta' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}
		$route    = $this->taxonomies->route( $tax );
		$response = $this->rest->dispatch( 'POST', $route, $params, (string) $tax->name, 'term' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse ) {
			return R::error( 'partial_failure' );
		}
		$sent_slug = isset( $args['slug'] ) ? (string) $args['slug'] : null;
		return $response->project(
			function ( array $row ) use ( $tax, $sent_slug ): mixed {
				try {
					$item = $this->projection->project( $row, array(), 0, (bool) $tax->hierarchical );
				} catch ( \Throwable ) {
					return new PartialFailure( (int) ( $row['id'] ?? 0 ) > 0 ? (int) $row['id'] : 1, true );
				}
				if ( null !== $sent_slug ) {
					$saved                 = isset( $item['slug'] ) ? (string) $item['slug'] : '';
					$item['slug_adjusted'] = sanitize_title( $sent_slug ) !== $saved;
				} else {
					$item['slug_adjusted'] = false;
				}
				$item = $this->filter_meta( $item, (string) $tax->name, array( 'meta' ) );
				return $item;
			}
		);
	}

	/**
	 * Update one term with parent and meta prechecks.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function update_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$id   = (int) ( $args['id'] ?? 0 );
		$term = get_term( $id, (string) $tax->name );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
			return R::error( 'not_found' );
		}
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
		if ( isset( $args['name'] ) ) {
			$name = (string) $args['name'];
			if ( '' === trim( $name ) || mb_strlen( $name ) > 200 ) {
				return R::error( 'invalid_params' );
			}
		}
		if ( isset( $args['slug'] ) && ( '' === (string) $args['slug'] || mb_strlen( (string) $args['slug'] ) > 200 ) ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['description'] ) && mb_strlen( (string) $args['description'] ) > 5000 ) {
			return R::error( 'invalid_params' );
		}
		if ( array_key_exists( 'parent', $args ) ) {
			$parent_check = $this->check_parent( $tax, $args['parent'], $id );
			if ( is_wp_error( $parent_check ) ) {
				return $parent_check;
			}
		}
		if ( isset( $args['meta'] ) ) {
			$meta_check = $this->check_meta_keys( (string) $tax->name, $args['meta'] );
			if ( is_wp_error( $meta_check ) ) {
				return $meta_check;
			}
		}
		$params = array();
		foreach ( array( 'name', 'slug', 'description', 'parent', 'meta' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}
		$route    = $this->taxonomies->route( $tax ) . '/' . $id;
		$response = $this->rest->dispatch( 'POST', $route, $params, (string) $tax->name, 'term' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse ) {
			return R::error( 'partial_failure' );
		}
		$sent_slug = array_key_exists( 'slug', $args ) ? (string) $args['slug'] : null;
		return $response->project(
			function ( array $row ) use ( $tax, $id, $sent_slug ): mixed {
				try {
					$item = $this->projection->project( $row, array(), 0, (bool) $tax->hierarchical );
				} catch ( \Throwable ) {
					return new PartialFailure( $id, false );
				}
				if ( null !== $sent_slug ) {
					$saved                 = isset( $item['slug'] ) ? (string) $item['slug'] : '';
					$item['slug_adjusted'] = sanitize_title( $sent_slug ) !== $saved;
				} else {
					$item['slug_adjusted'] = false;
				}
				$item = $this->filter_meta( $item, (string) $tax->name, array( 'meta' ) );
				return $item;
			}
		);
	}

	/**
	 * Assign terms to one post with the reviewed 13-step chain.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function assign_terms( array $args ): mixed {
		$post_id  = (int) ( $args['post_id'] ?? 0 );
		$mode     = (string) ( $args['mode'] ?? '' );
		$term_ids = isset( $args['term_ids'] ) && is_array( $args['term_ids'] ) ? array_values( $args['term_ids'] ) : null;
		if ( null === $term_ids || ! in_array( $mode, array( 'replace', 'append', 'remove' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		foreach ( $term_ids as $tid ) {
			if ( ! is_int( $tid ) || $tid < 1 ) {
				return R::error( 'invalid_params' );
			}
		}
		if ( count( $term_ids ) !== count( array_unique( $term_ids ) ) || count( $term_ids ) > 100 ) {
			return R::error( 'invalid_params' );
		}
		if ( ( 'append' === $mode || 'remove' === $mode ) && 0 === count( $term_ids ) ) {
			return R::error( 'invalid_params' );
		}
		$result = $this->assign_single( $post_id, (string) ( $args['taxonomy'] ?? '' ), $term_ids, $mode );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return MutationResult::project(
			$post_id,
			false,
			static fn(): array => $result
		);
	}

	/**
	 * Assign or remove terms for up to 50 posts.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function bulk_assign( array $args ): mixed {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		$tax      = $this->taxonomies->resolve( $taxonomy );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$mode     = (string) ( $args['mode'] ?? '' );
		$post_ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? array_values( $args['post_ids'] ) : array();
		$term_ids = isset( $args['term_ids'] ) && is_array( $args['term_ids'] ) ? array_values( $args['term_ids'] ) : array();
		if ( ! in_array( $mode, array( 'append', 'remove' ), true ) || 0 === count( $post_ids ) || 0 === count( $term_ids ) ) {
			return R::error( 'invalid_params' );
		}
		// Validate terms once before writing any post.
		foreach ( $term_ids as $tid ) {
			if ( ! is_int( $tid ) || $tid < 1 ) {
				return R::error( 'invalid_params' );
			}
			$term = get_term( $tid, $taxonomy );
			if ( ! $term instanceof WP_Term || $term->taxonomy !== $taxonomy ) {
				return R::error( 'not_found' );
			}
			if ( ! current_user_can( 'assign_term', $tid ) ) {
				return R::error( 'forbidden' );
			}
		}
		$result = array(
			'taxonomy'  => $taxonomy,
			'mode'      => $mode,
			'updated'   => array(),
			'unchanged' => array(),
			'failed'    => array(),
		);
		wp_defer_term_counting( true );
		try {
			foreach ( $post_ids as $post_id ) {
				try {
					$single = $this->assign_single( (int) $post_id, $taxonomy, $term_ids, $mode );
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
				$added   = isset( $single['added'] ) && is_array( $single['added'] ) ? $single['added'] : array();
				$removed = isset( $single['removed'] ) && is_array( $single['removed'] ) ? $single['removed'] : array();
				$filled  = ! empty( $single['default_term_applied'] );
				if ( ! empty( $added ) || ! empty( $removed ) || $filled ) {
					$result['updated'][] = (int) $post_id;
					continue;
				}
				$result['unchanged'][] = (int) $post_id;
			}
		} finally {
			wp_defer_term_counting( false );
		}
		return $result;
	}

	/**
	 * Ensure up to 50 terms exist and return their identities.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function bulk_create( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
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
			try {
				if ( ! is_array( $item ) || ! isset( $item['name'] ) ) {
					$result['failed'][] = array(
						'index' => (int) $index,
						'code'  => 'invalid_params',
					);
					continue;
				}
				$single = $this->create_term(
					array_merge(
						array( 'taxonomy' => (string) $tax->name ),
						$item
					)
				);
			} catch ( \Throwable ) {
				$result['failed'][] = array(
					'index' => (int) $index,
					'code'  => 'partial_failure',
				);
				continue;
			}
			if ( is_wp_error( $single ) ) {
				$code = R::reason( (string) $single->get_error_code() );
				if ( 'duplicate_term' === $code ) {
					$existing = $this->find_existing_term( $tax, $item );
					if ( null !== $existing ) {
						$result['existing'][] = array(
							'index' => (int) $index,
							'id'    => (int) $existing->term_id,
							'name'  => $existing->name,
							'slug'  => $existing->slug,
						);
						continue;
					}
				}
				if ( $single instanceof PartialFailure ) {
					$code = 'partial_failure';
				}
				$result['failed'][] = array(
					'index' => (int) $index,
					'code'  => $code,
				);
				continue;
			}
			$data = $single instanceof MutationResult ? $single->data : $single;
			if ( isset( $data['id'] ) ) {
				$result['created'][] = array(
					'index' => (int) $index,
					'id'    => (int) $data['id'],
					'name'  => isset( $data['name'] ) ? (string) $data['name'] : (string) $item['name'],
					'slug'  => isset( $data['slug'] ) ? (string) $data['slug'] : '',
				);
				continue;
			}
			$result['failed'][] = array(
				'index' => (int) $index,
				'code'  => 'partial_failure',
			);
		}
		return $result;
	}

	/**
	 * Delete one term with default-term guard and dry-run.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function delete_term( array $args ): mixed {
		$tax = $this->taxonomies->resolve( (string) ( $args['taxonomy'] ?? '' ) );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$id   = (int) ( $args['id'] ?? 0 );
		$term = get_term( $id, (string) $tax->name );
		if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
			return R::error( 'not_found' );
		}
		if ( $this->is_default_term( (string) $tax->name, $id ) ) {
			return R::error( 'invalid_state' );
		}
		if ( ! current_user_can( 'delete_term', $id ) ) {
			return R::error( 'forbidden' );
		}
		$children = 0;
		if ( (bool) $tax->hierarchical ) {
			$count    = get_terms(
				array(
					'taxonomy'   => (string) $tax->name,
					'parent'     => $id,
					'hide_empty' => false,
					'fields'     => 'count',
				)
			);
			$children = is_int( $count ) ? $count : 0;
		}
		$published_count         = (int) $term->count;
		$will_reassign           = 0 !== $this->default_term_id( (string) $tax->name, $tax );
		$dry_run                 = isset( $args['dry_run'] ) ? (bool) $args['dry_run'] : false;
		$new_parent_for_children = (int) $term->parent;
		if ( $dry_run ) {
			return array(
				'id'       => $id,
				'taxonomy' => (string) $tax->name,
				'deleted'  => false,
				'dry_run'  => true,
				'impact'   => array(
					'children'                 => $children,
					'new_parent_for_children'  => $new_parent_for_children,
					'published_count'          => $published_count,
					'will_reassign_to_default' => $will_reassign,
				),
			);
		}
		$route    = $this->taxonomies->route( $tax ) . '/' . $id;
		$response = $this->rest->dispatch( 'DELETE', $route, array( 'force' => true ), (string) $tax->name, 'term' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse || $response->object_id !== $id ) {
			// Core term deletion answers {deleted, previous} without a top-level id,
			// so the observed delete_term receipt is authoritative here.
			return new PartialFailure( $id, false );
		}
		return MutationResult::project(
			$id,
			false,
			static fn(): array => array(
				'id'                  => $id,
				'taxonomy'            => (string) $tax->name,
				'deleted'             => true,
				'children_reparented' => $children,
			)
		);
	}

	/**
	 * Assign terms to a single post; the bulk caller wraps counting.
	 *
	 * @param int        $post_id Post.
	 * @param string     $taxonomy_slug Taxonomy.
	 * @param array<int> $term_ids Term IDs.
	 * @param string     $mode Replace, append or remove.
	 * @return array<string,mixed>|WP_Error
	 */
	private function assign_single( int $post_id, string $taxonomy_slug, array $term_ids, string $mode ): array|WP_Error {
		$tax = $this->taxonomies->resolve( $taxonomy_slug );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return R::error( 'not_found' );
		}
		$post_type = $this->types->resolve( (string) $post->post_type );
		if ( is_wp_error( $post_type ) ) {
			return R::error( 'post_type_unavailable' );
		}
		if ( in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return R::error( 'invalid_state' );
		}
		if ( ! is_object_in_taxonomy( (string) $post->post_type, (string) $tax->name ) ) {
			return R::error( 'unsupported_operation' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( $tax->cap->assign_terms ) ) {
			return R::error( 'forbidden' );
		}
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid, (string) $tax->name );
			if ( ! $term instanceof WP_Term || $term->taxonomy !== (string) $tax->name ) {
				return R::error( 'not_found' );
			}
			if ( ! current_user_can( 'assign_term', $tid ) ) {
				return R::error( 'forbidden' );
			}
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
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
		sort( $term_ids );
		if ( 'replace' === $mode ) {
			$expected = $term_ids;
		} elseif ( 'append' === $mode ) {
			$expected = array_values( array_unique( array_merge( $before, $term_ids ) ) );
		} else {
			$expected = array_values( array_diff( $before, $term_ids ) );
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
		if ( 0 === count( $expected ) ) {
			$default_id = $this->default_for_empty_assignment( $tax, (string) $post->post_type );
			if ( $default_id > 0 ) {
				$expected        = array( $default_id );
				$default_applied = true;
			}
		}
		$needs_counting = ! doing_action( 'bulk_assign' );
		// Bulk callers already defer counting; single calls defer here.
		$deferred_here = false;
		if ( $needs_counting && function_exists( 'wp_defer_term_counting' ) ) {
			// Only defer when not already inside a bulk defer; wp_defer_term_counting stacks safely.
			$deferred_here = true;
			wp_defer_term_counting( true );
		}
		try {
			if ( 'remove' === $mode && ! $default_applied ) {
				$to_remove = array_values( array_diff( $before, $expected ) );
				$result    = empty( $to_remove ) ? true : wp_remove_object_terms( $post_id, $to_remove, (string) $tax->name );
			} else {
				$append = 'append' === $mode;
				// Replace and default-fill always overwrite; append merges.
				$result = wp_set_object_terms( $post_id, $expected, (string) $tax->name, $append && ! $default_applied );
				// Append via wp_set_object_terms with append=true merges; recompute expected as union for comparison.
				if ( 'append' === $mode && ! $default_applied ) {
					$expected = array_values( array_unique( array_merge( $before, $term_ids ) ) );
					sort( $expected );
				}
			}
			if ( is_wp_error( $result ) || false === $result ) {
				return R::error( 'internal_error' );
			}
			if ( $default_applied ) {
				$fill = wp_set_object_terms( $post_id, $expected, (string) $tax->name, false );
				if ( is_wp_error( $fill ) || false === $fill ) {
					return R::error( 'internal_error' );
				}
			}
		} finally {
			if ( $deferred_here ) {
				wp_defer_term_counting( false );
			}
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
	 * Check parent preconditions without writing anything.
	 *
	 * @param WP_Taxonomy $tax Taxonomy.
	 * @param mixed       $parent_id Candidate parent.
	 * @param int         $exclude_id Term being updated, zero when creating.
	 * @return bool|WP_Error True or a safe error.
	 */
	private function check_parent( WP_Taxonomy $tax, mixed $parent_id, int $exclude_id ): bool|WP_Error {
		if ( null === $parent_id ) {
			return true;
		}
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
	 * Check term-meta keys before writing anything.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param mixed  $meta Candidate meta.
	 * @return bool|WP_Error True or a safe error.
	 */
	private function check_meta_keys( string $taxonomy, mixed $meta ): bool|WP_Error {
		if ( ! is_array( $meta ) || count( $meta ) > 20 ) {
			return R::error( 'invalid_params' );
		}
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || str_starts_with( $key, '_' ) ) {
				return R::error( 'invalid_params' );
			}
			if ( ! array_key_exists( $key, $registered ) ) {
				return R::error( 'invalid_params' );
			}
			$def = $registered[ $key ];
			if ( empty( $def['show_in_rest'] ) ) {
				return R::error( 'invalid_params' );
			}
			unset( $value );
		}
		return true;
	}

	/**
	 * Filter projected meta to registered show_in_rest keys.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param string              $taxonomy Taxonomy.
	 * @param array<string>       $fields Requested fields.
	 * @return array<string,mixed>
	 */
	private function filter_meta( array $item, string $taxonomy, array $fields ): array {
		if ( ! in_array( 'meta', $fields, true ) ) {
			unset( $item['meta'] );
			return $item;
		}
		if ( ! isset( $item['meta'] ) ) {
			$item['meta'] = array();
			return $item;
		}
		if ( ! is_array( $item['meta'] ) ) {
			$item['meta'] = array();
			return $item;
		}
		$registered = get_registered_meta_keys( 'term', $taxonomy );
		$filtered   = array();
		foreach ( $item['meta'] as $key => $value ) {
			if ( ! is_string( $key ) || str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $registered ) ) {
				continue;
			}
			$def = $registered[ $key ];
			if ( empty( $def['show_in_rest'] ) ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}
		$item['meta'] = $filtered;
		return $item;
	}

	/**
	 * Read the default term ID for listings and guards.
	 *
	 * @param string      $taxonomy Taxonomy slug.
	 * @param WP_Taxonomy $tax Taxonomy.
	 * @return int|null
	 */
	private function default_term_id( string $taxonomy, WP_Taxonomy $tax ): ?int {
		$candidates = array(
			(int) get_option( 'default_' . $taxonomy ),
			(int) get_option( 'default_term_' . $taxonomy ),
		);
		foreach ( $candidates as $candidate ) {
			if ( $candidate > 0 ) {
				$term = get_term( $candidate, $taxonomy );
				if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy ) {
					return $candidate;
				}
			}
		}
		unset( $tax );
		return null;
	}

	/**
	 * Whether a term is a protected default term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id Term.
	 * @return bool
	 */
	private function is_default_term( string $taxonomy, int $term_id ): bool {
		return (int) get_option( 'default_' . $taxonomy ) === $term_id || (int) get_option( 'default_term_' . $taxonomy ) === $term_id;
	}

	/**
	 * Default term to apply when an assignment empties a taxonomy.
	 *
	 * @param WP_Taxonomy $tax Taxonomy.
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
			$allowed = array_merge( $extra, array( 'post' ) );
			if ( in_array( $post_type, $allowed, true ) ) {
				$candidate = (int) get_option( 'default_category' );
				if ( $candidate > 0 ) {
					$term = get_term( $candidate, $taxonomy );
					if ( $term instanceof WP_Term ) {
						return $candidate;
					}
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
	 * Find an existing term after a duplicate report without trusting core error data.
	 *
	 * @param WP_Taxonomy         $tax Taxonomy.
	 * @param array<string,mixed> $item Requested item.
	 * @return WP_Term|null
	 */
	private function find_existing_term( WP_Taxonomy $tax, array $item ): ?WP_Term {
		$taxonomy = (string) $tax->name;
		$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
		$parent   = isset( $item['parent'] ) ? (int) $item['parent'] : 0;
		if ( '' === $name ) {
			return null;
		}
		$found = term_exists( $name, $taxonomy, $parent > 0 ? $parent : null );
		if ( is_array( $found ) && isset( $found['term_id'] ) ) {
			$term = get_term( (int) $found['term_id'], $taxonomy );
			if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy ) {
				return $term;
			}
		}
		if ( isset( $item['slug'] ) && '' !== (string) $item['slug'] ) {
			$by_slug = get_term_by( 'slug', (string) $item['slug'], $taxonomy );
			if ( $by_slug instanceof WP_Term && $by_slug->taxonomy === $taxonomy ) {
				$check = get_term( (int) $by_slug->term_id, $taxonomy );
				if ( $check instanceof WP_Term ) {
					return $check;
				}
			}
		}
		return null;
	}
}
