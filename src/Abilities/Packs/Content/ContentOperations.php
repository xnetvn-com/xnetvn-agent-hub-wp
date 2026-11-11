<?php
/**
 * Allowlisted content operations through core REST controllers.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use WP_Error;
use WP_Post_Type;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;

/** Never changes user identity or bypasses core REST permissions. */
final class ContentOperations {
	/** Internal dispatcher.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;
	/** Runtime type boundary.
	 *
	 * @var PostTypeResolver
	 */
	private PostTypeResolver $types;
	/** Allowlisted output.
	 *
	 * @var PostProjection
	 */
	private PostProjection $projection;
	/** Bind pure infrastructure. */
	public function __construct() {
		$this->rest       = new RestBackedAbility();
		$this->types      = new PostTypeResolver();
		$this->projection = new PostProjection( static fn( int $id ): bool => current_user_can( 'edit_post', $id ) );
	}
	/**
	 * Run a reviewed operation after registry schema/policy validation.
	 *
	 * @param string              $name Reviewed operation.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $args ): mixed {
		if ( in_array( $name, array( 'list-post-types', 'list-post-statuses' ), true ) ) {
			return $this->inventory( $name ); }
		if ( 'search-content' === $name ) {
			return $this->search( $args ); }
		$slug = (string) ( $args['post_type'] ?? 'post' );
		$type = $this->types->resolve( $slug );
		if ( is_wp_error( $type ) ) {
			return $type; }
		$route  = rest_get_route_for_post_type_items( $slug );
		$id     = (int) ( $args['id'] ?? 0 );
		$format = (string) ( $args['content_format'] ?? 'rendered' );
		if ( $id && ( null === get_post( $id ) || get_post_type( $id ) !== $slug ) ) {
			return R::error( 'not_found' ); }
		if ( 'raw' === $format && ( $id ? ! current_user_can( 'edit_post', $id ) : ! current_user_can( $type->cap->edit_posts ) ) ) {
			return R::error( 'forbidden' ); }
		if ( in_array( $name, array( 'list-revisions', 'get-revision', 'restore-revision' ), true ) ) {
			return $this->revisions( $name, $route, $slug, $id, $args ); }
		if ( 'duplicate-post' === $name ) {
			return $this->duplicate( $type, $route, $id, $args ); }
		if ( 'bulk-update-posts' === $name ) {
			$result = array(
				'updated' => array(),
				'failed'  => array(),
			);
			foreach ( $args['ids'] as $target ) {
				$changes = $args['changes'];
				// Author is an explicitly allowed bulk field; core checks assignment permissions.
				try {
					$item = $this->write( $type, $route, (int) $target, $changes );
				} catch ( \Throwable ) {
					$item = R::error( 'partial_failure' ); }
				if ( is_wp_error( $item ) ) {
					$result['failed'][] = array(
						'id'   => $target,
						'code' => R::reason( (string) $item->get_error_code() ),
					);
				} else {
					$result['updated'][] = $target; }
			}
			return $result;
		}
		if ( 'stick-post' === $name && 'post' !== $slug ) {
			return R::error( 'unsupported_operation' ); }
		if ( 'set-featured-media' === $name && ! post_type_supports( $slug, 'thumbnail' ) ) {
			return R::error( 'unsupported_operation' ); }
		if ( in_array( $name, array( 'get-post-meta', 'update-post-meta' ), true ) ) {
			return $this->metadata( $name, $type, $route, $id, $args ); }
		if ( 'list-posts' === $name || 'get-post' === $name ) {
			$params            = array_diff_key( $args, array_flip( array( 'post_type', 'id', 'fields', 'content_format' ) ) );
			$params['context'] = 'raw' === $format ? 'edit' : 'view';
			if ( 'list-posts' === $name ) {
				$params += array(
					'page'     => 1,
					'per_page' => 20,
					'status'   => array( 'publish' ),
				); }
			if ( ! $this->supported( $type, $params ) || ( 'relevance' === ( $params['orderby'] ?? '' ) && empty( $params['search'] ) ) ) {
				return R::error( 'invalid_params' ); }
			$response = $this->rest->dispatch( 'GET', $route . ( $id ? '/' . $id : '' ), $params );
			if ( is_wp_error( $response ) ) {
				return $response; }
			$project = fn( array $row ): array|WP_Error => $this->project( $row, $format, $args['fields'] ?? array() );
			return $id ? $project( $response->get_data() ) : $this->rest->collection( $response, (int) $params['page'], $project );
		}
		if ( in_array( $name, array( 'delete-post', 'trash-post' ), true ) ) {
			$response = $this->rest->dispatch( 'DELETE', $route . '/' . $id, array( 'force' => 'delete-post' === $name ), $type->name );
			if ( is_wp_error( $response ) ) {
				return $response; }
			return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? \XNetVN\AgentHubWP\Abilities\MutationResult::project(
				$response->object_id,
				false,
				static fn(): array => 'delete-post' === $name ? array(
					'id'      => $id,
					'deleted' => true,
				) : array(
					'id'     => $id,
					'status' => 'trash',
				)
			) : R::error( 'partial_failure' );
		}
		if ( 'restore-post' === $name ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return R::error( 'forbidden' ); }
			if ( 'trash' !== get_post_status( $id ) ) {
				return R::error( 'invalid_state' ); }
			return $this->write( $type, $route, $id, array( 'status' => $args['status'] ?? 'draft' ) );
		}
		if ( 'set-featured-media' === $name ) {
			return $this->write( $type, $route, $id, array( 'featured_media' => $args['media_id'] ) ); }
		if ( 'stick-post' === $name ) {
			return $this->write( $type, $route, $id, array( 'sticky' => $args['sticky'] ) ); }
		$changes = array_diff_key( $args, array_flip( array( 'id', 'post_type', 'expected_modified' ) ) );
		if ( 'create-post' === $name && ! array_intersect( array_keys( $changes ), array( 'title', 'content', 'excerpt' ) ) ) {
			return R::error( 'invalid_params' ); }
		if ( ! $changes ) {
			return R::error( 'invalid_params' ); }
		if ( isset( $args['expected_modified'] ) ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return R::error( 'forbidden' ); }
			$snapshot = $this->rest->dispatch( 'GET', $route . '/' . $id, array( 'context' => 'edit' ) );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot; }
			$current_gmt = $snapshot->get_data()['modified_gmt'] ?? null;
			if ( ! is_string( $current_gmt ) ) {
				return R::error( 'internal_error' ); }
			if ( strtotime( $args['expected_modified'] ) !== strtotime( rtrim( $current_gmt, 'Z' ) . 'Z' ) ) {
				return R::error( 'conflict' ); }
		}
		if ( 'create-post' === $name ) {
			$changes += array( 'status' => 'draft' ); }
		return $this->write( $type, $route, $id, $changes );
	}
	/**
	 * Project supported post data and only core-registered metadata.
	 *
	 * @param array<string,mixed> $row Record.
	 * @param string              $format Representation.
	 * @param array<string>       $fields Selected fields.
	 * @return array<string,mixed>
	 */
	private function project( array $row, string $format = 'rendered', array $fields = array() ): array|WP_Error {
		if ( isset( $row['meta'] ) ) {
			$row['meta'] = $this->safe_meta( (string) $row['type'], (array) $row['meta'] ); }
		try {
			return $this->projection->project( $row, $format, $fields );
		} catch ( \DomainException ) {
			return R::error( 'forbidden' ); }
	}
	/**
	 * Ensure optional type-specific fields are not silently ignored by core.
	 *
	 * @param WP_Post_Type        $type Type.
	 * @param array<string,mixed> $changes Changes.
	 * @return bool
	 */
	private function supported( WP_Post_Type $type, array $changes ): bool {
		foreach ( array(
			'categories' => 'category',
			'tags'       => 'post_tag',
		) as $field => $taxonomy ) {
			if ( isset( $changes[ $field ] ) && ! is_object_in_taxonomy( $type->name, $taxonomy ) ) {
				return false; }
		}
		foreach ( array(
			'featured_media' => 'thumbnail',
			'parent'         => 'page-attributes',
			'menu_order'     => 'page-attributes',
			'comment_status' => 'comments',
			'ping_status'    => 'trackbacks',
			'format'         => 'post-formats',
		) as $field => $support ) {
			if ( array_key_exists( $field, $changes ) && ! post_type_supports( $type->name, $support ) ) {
				return false; }
		}
		return ! isset( $changes['sticky'] ) || 'post' === $type->name;
	}
	/**
	 * Validate before any mutation, then let core check actual permissions.
	 *
	 * @param WP_Post_Type        $type Type.
	 * @param string              $route Route.
	 * @param int                 $id Object, zero for create.
	 * @param array<string,mixed> $changes Changes.
	 * @return mixed
	 */
	private function write( WP_Post_Type $type, string $route, int $id, array $changes ): mixed {
		if ( $id && ( null === get_post( $id ) || get_post_type( $id ) !== $type->name ) ) {
			return R::error( 'not_found' ); }
		if ( ! $this->supported( $type, $changes ) ) {
			return R::error( 'invalid_params' ); }
		if ( isset( $changes['meta'] ) && ! $this->valid_meta( $type->name, (array) $changes['meta'] ) ) {
			return R::error( 'invalid_params' ); }
		if ( 'future' === ( $changes['status'] ?? '' ) && ( ! isset( $changes['date'] ) || strtotime( $changes['date'] ) <= time() ) ) {
			return R::error( 'invalid_params' ); }
		if ( isset( $changes['date'] ) ) {
			$changes['date_gmt'] = gmdate( 'Y-m-d\TH:i:s', strtotime( $changes['date'] ) );
			unset( $changes['date'] ); }
		$response = $this->rest->dispatch( 'POST', $route . ( $id ? '/' . $id : '' ), $changes, $type->name );
		if ( is_wp_error( $response ) ) {
			return $response; }
		return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? $response->project( fn( array $row ): mixed => $this->project( $row ) ) : R::error( 'partial_failure' );
	}
	/**
	 * List core types/statuses without exposing excluded object kinds.
	 *
	 * @param string $name Operation.
	 * @return mixed
	 */
	private function inventory( string $name ): mixed {
		$is_type  = 'list-post-types' === $name;
		$response = $this->rest->dispatch( 'GET', $is_type ? '/wp/v2/types' : '/wp/v2/statuses', array( 'context' => 'edit' ) );
		if ( is_wp_error( $response ) ) {
			return $response; }
		$items = array();
		foreach ( $response->get_data() as $slug => $row ) {
			if ( $is_type ) {
				$type = $this->types->resolve( (string) $slug );
				if ( is_wp_error( $type ) || ! current_user_can( $type->cap->edit_posts ) ) {
					continue; }
				$supports = array_keys( get_all_post_type_supports( $slug ) );
				sort( $supports, SORT_STRING );
				$items[ $slug ] = array(
					'slug'         => $slug,
					'name'         => $row['name'],
					'rest_base'    => $row['rest_base'],
					'hierarchical' => $row['hierarchical'],
					'supports'     => $supports,
				);
			} else {
				$items[ $slug ] = array(
					'slug' => $slug,
					'name' => $row['name'],
				); }
		}
		ksort( $items, SORT_STRING );
		return array( 'items' => array_values( $items ) );
	}
	/**
	 * Execute revision reads/restoration with exact parent binding.
	 *
	 * @param string              $name Operation.
	 * @param string              $route Parent collection.
	 * @param string              $slug Type.
	 * @param int                 $id Parent ID.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function revisions( string $name, string $route, string $slug, int $id, array $args ): mixed {
		if ( ! post_type_supports( $slug, 'revisions' ) ) {
			return R::error( 'unsupported_operation' ); }
		$rid = (int) ( $args['revision_id'] ?? 0 );
		if ( $rid ) {
			$revision = get_post( $rid );
			if ( null === $revision || 'revision' !== $revision->post_type || $id !== (int) $revision->post_parent ) {
				return R::error( 'not_found' ); }
		}
		if ( 'restore-revision' === $name ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return R::error( 'forbidden' ); }
			if ( ! wp_restore_post_revision( $rid ) ) {
				return R::error( 'restore_failed' ); }
			return \XNetVN\AgentHubWP\Abilities\MutationResult::project(
				$id,
				false,
				fn(): mixed => $this->execute(
					'get-post',
					array(
						'id'        => $id,
						'post_type' => $slug,
					)
				)
			);
		}
		$format = (string) ( $args['content_format'] ?? 'rendered' );
		if ( 'raw' === $format && ! current_user_can( 'edit_post', $id ) ) {
			return R::error( 'forbidden' ); }
		$params   = array(
			'context'  => 'raw' === $format ? 'edit' : 'view',
			'page'     => $args['page'] ?? 1,
			'per_page' => $args['per_page'] ?? 20,
		);
		$response = $this->rest->dispatch( 'GET', $route . '/' . $id . '/revisions' . ( $rid ? '/' . $rid : '' ), $params );
		if ( is_wp_error( $response ) ) {
			return $response; }
		$project = static function ( array $row ) use ( $format ): array {
			$result = array_intersect_key( $row, array_flip( array( 'id', 'parent', 'author', 'date', 'modified', 'slug' ) ) );
			foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
				$source           = 'excerpt' === $format && 'content' === $field ? 'excerpt' : $field;
				$result[ $field ] = $row[ $source ][ 'raw' === $format ? 'raw' : 'rendered' ]; }
			return $result;
		};
		return $rid ? $project( $response->get_data() ) : $this->rest->collection( $response, (int) $params['page'], $project );
	}
	/**
	 * Registered REST-visible metadata only, validated through core's schema.
	 *
	 * @param string              $slug Type.
	 * @param array<string,mixed> $meta Values.
	 * @return bool
	 */
	private function valid_meta( string $slug, array $meta ): bool {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $slug ) );
		if ( $meta && ! post_type_supports( $slug, 'custom-fields' ) ) {
			return false; }
		foreach ( $meta as $key => $value ) {
			if ( ! isset( $registered[ $key ] ) || empty( $registered[ $key ]['show_in_rest'] ) ) {
				return false; }
		}
		return true;
	}
	/**
	 * Restrict core output to registered keys; never expose arbitrary extension data.
	 *
	 * @throws \UnexpectedValueException For malformed registered values.
	 *
	 * @param string              $slug Type.
	 * @param array<string,mixed> $meta Core values.
	 * @return object
	 */
	private function safe_meta( string $slug, array $meta ): object {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $slug ) );
		$result     = array();
		$schema     = ( new \WP_REST_Post_Meta_Fields( $slug ) )->get_field_schema();
		foreach ( $meta as $key => $value ) {
			if ( ! empty( $registered[ $key ]['show_in_rest'] ) ) {
				if ( ! isset( $schema['properties'][ $key ] ) || true !== rest_validate_value_from_schema( $value, $schema['properties'][ $key ] ) ) {
					throw new \UnexpectedValueException( 'Registered metadata is unavailable.' ); }
				$result[ $key ] = $value; }
		}
		return (object) $result;
	}
	/**
	 * Core metadata operations without unknown-key silent success.
	 *
	 * @param string              $name Operation.
	 * @param WP_Post_Type        $type Type.
	 * @param string              $route Route.
	 * @param int                 $id Object.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function metadata( string $name, WP_Post_Type $type, string $route, int $id, array $args ): mixed {
		if ( 'update-post-meta' === $name ) {
			if ( ! $this->valid_meta( $type->name, (array) $args['values'] ) ) {
				return R::error( 'invalid_params' ); }
			$response = $this->rest->dispatch( 'POST', $route . '/' . $id, array( 'meta' => $args['values'] ), $type->name );
		} else {
			$response = $this->rest->dispatch( 'GET', $route . '/' . $id, array( 'context' => 'view' ) ); }
		if ( is_wp_error( $response ) ) {
			return $response; }
		$project = function ( array $row ) use ( $type, $args ): array {
			$meta = (array) $this->safe_meta( $type->name, (array) ( $row['meta'] ?? array() ) );
			if ( ! empty( $args['keys'] ) ) {
				$meta = array_intersect_key( $meta, array_flip( $args['keys'] ) ); }
			return array( 'meta' => (object) $meta );
		};
		return $response instanceof \XNetVN\AgentHubWP\Abilities\MutationResponse ? $response->project( $project ) : $project( $response->get_data() );
	}
	/**
	 * Duplicate only approved raw fields, then let core check creation rights.
	 *
	 * @param WP_Post_Type        $type Type.
	 * @param string              $route Route.
	 * @param int                 $id Source.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function duplicate( WP_Post_Type $type, string $route, int $id, array $args ): mixed {
		if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( $type->cap->create_posts ) ) {
			return R::error( 'forbidden' ); }
		$response = $this->rest->dispatch( 'GET', $route . '/' . $id, array( 'context' => 'edit' ) );
		if ( is_wp_error( $response ) ) {
			return $response; }
		$row     = $response->get_data();
		$changes = array_intersect_key( $row, array_flip( array( 'categories', 'tags', 'featured_media', 'parent', 'menu_order', 'template', 'comment_status', 'ping_status', 'format' ) ) );
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			if ( isset( $row[ $field ]['raw'] ) ) {
				$changes[ $field ] = $row[ $field ]['raw']; }
		}
		$changes['meta']   = array_filter( (array) $this->safe_meta( $type->name, (array) ( $row['meta'] ?? array() ) ), static fn( string $key ): bool => ! str_starts_with( $key, '_' ), ARRAY_FILTER_USE_KEY );
		$changes['status'] = $args['status'] ?? 'draft';
		return $this->write( $type, $route, 0, $changes );
	}
	/**
	 * Search public manageable types only, including core pagination.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function search( array $args ): mixed {
		$subtype = (string) ( $args['subtype'] ?? 'any' );
		$types   = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $slug ) {
			$type = $this->types->resolve( $slug );
			if ( ! is_wp_error( $type ) && current_user_can( $type->cap->edit_posts ) ) {
				$types[] = $slug; }
		}
		if ( 'any' !== $subtype ) {
			$type = $this->types->resolve( $subtype );
			if ( is_wp_error( $type ) ) {
				return $type;
			} $types = in_array( $subtype, $types, true ) ? array( $subtype ) : array(); }
		if ( ! $types ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'total_pages' => 0,
				'page'        => $args['page'] ?? 1,
			); }
		$params   = array_merge(
			array(
				'page'     => 1,
				'per_page' => 20,
			),
			$args,
			array(
				'type'    => 'post',
				'subtype' => $types,
			)
		);
		$response = $this->rest->dispatch( 'GET', '/wp/v2/search', $params );
		if ( is_wp_error( $response ) ) {
			return $response; }
		return $this->rest->collection(
			$response,
			(int) $params['page'],
			static function ( array $row ) use ( $types ): array|WP_Error {
				if ( ! isset( $row['id'], $row['type'], $row['subtype'] ) || ! is_int( $row['id'] ) || $row['id'] < 1 || 'post' !== $row['type'] || ! in_array( $row['subtype'], $types, true ) ) {
					return R::error( 'internal_error' ); }
				$post = get_post( $row['id'] );
				if ( null === $post || $post->post_type !== $row['subtype'] || 'publish' !== $post->post_status ) {
					return R::error( 'internal_error' ); }
				return array_intersect_key( $row, array_flip( array( 'id', 'title', 'url', 'type', 'subtype' ) ) );
			}
		);
	}
}
