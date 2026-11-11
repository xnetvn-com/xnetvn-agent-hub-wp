<?php
/**
 * Strict taxonomy tool schemas.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Taxonomy;

use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas as C;
use XNetVN\AgentHubWP\Abilities\SchemaFactory as S;

/** Keeps tool contracts independent of taxonomy execution. */
final class TaxonomySchemas {
	/**
	 * Taxonomy slug bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function taxonomy_slug(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9_-]{1,32}$',
		);
	}

	/**
	 * Term name bounded by the core column in characters.
	 *
	 * @return array<string,mixed>
	 */
	public static function term_name(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
	}

	/**
	 * Term slug bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function term_slug(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
	}

	/**
	 * Term description with a reviewed bound.
	 *
	 * @return array<string,mixed>
	 */
	public static function term_description(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 5000,
		);
	}

	/**
	 * Bounded term identifier list with unique integers only.
	 *
	 * @param int $max Maximum count.
	 * @return array<string,mixed>
	 */
	public static function term_ids( int $max = 100 ): array {
		return array(
			'type'        => 'array',
			'items'       => C::integer(),
			'maxItems'    => $max,
			'uniqueItems' => true,
		);
	}

	/**
	 * Term meta with a reviewed key bound; values validated against core at execution.
	 *
	 * @return array<string,mixed>
	 */
	public static function term_meta(): array {
		return array(
			'type'          => 'object',
			'maxProperties' => 20,
		);
	}

	/**
	 * Narrowing field list for the term DTO.
	 *
	 * @return array<string,mixed>
	 */
	public static function fields(): array {
		return array(
			'type'        => 'array',
			'items'       => C::choices( array( 'id', 'taxonomy', 'name', 'slug', 'description', 'description_truncated', 'parent', 'count', 'link', 'meta' ) ),
			'uniqueItems' => true,
			'maxItems'    => 9,
		);
	}

	/**
	 * Typed term projection with privacy-reviewed fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function term(): array {
		$p = array(
			'id'                    => C::integer(),
			'taxonomy'              => self::taxonomy_slug(),
			'name'                  => C::text( 500 ),
			'slug'                  => C::text( 200 ),
			'description'           => C::text( 5000 ),
			'description_truncated' => array( 'type' => 'boolean' ),
			'parent'                => C::integer( 0 ),
			'count'                 => C::integer( 0 ),
			'link'                  => C::text(),
			'meta'                  => self::term_meta(),
			'slug_adjusted'         => array( 'type' => 'boolean' ),
		);
		return S::object( $p, array( 'id', 'taxonomy' ), true );
	}

	/**
	 * Schema for each reviewed tool.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function input( string $name ): array {
		$taxonomy = array( 'taxonomy' => self::taxonomy_slug() );
		$id       = array( 'id' => C::integer() );
		switch ( $name ) {
			case 'list-taxonomies':
				return S::object(
					array(
						'post_type' => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9_-]{1,20}$',
						),
					)
				);
			case 'list-terms':
				return S::object(
					array_merge(
						$taxonomy,
						S::pagination(),
						array(
							'search'                => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'parent'                => C::integer( 0 ),
							'include'               => self::term_ids( 100 ),
							'exclude'               => self::term_ids( 100 ),
							'slug'                  => array(
								'type'        => 'array',
								'items'       => array(
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 200,
								),
								'maxItems'    => 50,
								'uniqueItems' => true,
							),
							'post'                  => C::integer(),
							'hide_empty'            => array( 'type' => 'boolean' ),
							'orderby'               => C::choices( array( 'id', 'name', 'slug', 'count', 'description', 'include' ) ),
							'order'                 => C::choices( array( 'asc', 'desc' ) ),
							'description_max_chars' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 5000,
							),
							'fields'                => self::fields(),
						)
					),
					array( 'taxonomy' )
				);
			case 'get-term':
				return S::object(
					array_merge( $taxonomy, $id, array( 'fields' => self::fields() ) ),
					array( 'taxonomy', 'id' )
				);
			case 'create-term':
				return S::object(
					array_merge(
						$taxonomy,
						array(
							'name'        => self::term_name(),
							'slug'        => self::term_slug(),
							'description' => self::term_description(),
							'parent'      => C::integer( 0 ),
							'meta'        => self::term_meta(),
						)
					),
					array( 'taxonomy', 'name' )
				);
			case 'update-term':
				return S::object(
					array_merge(
						$taxonomy,
						$id,
						array(
							'name'        => self::term_name(),
							'slug'        => self::term_slug(),
							'description' => self::term_description(),
							'parent'      => C::integer( 0 ),
							'meta'        => self::term_meta(),
						)
					),
					array( 'taxonomy', 'id' )
				);
			case 'assign-post-terms':
				return S::object(
					array(
						'post_id'  => C::integer(),
						'taxonomy' => self::taxonomy_slug(),
						'term_ids' => self::term_ids( 100 ),
						'mode'     => C::choices( array( 'replace', 'append', 'remove' ) ),
					),
					array( 'post_id', 'taxonomy', 'term_ids', 'mode' )
				);
			case 'bulk-create-terms':
				$item = S::object(
					array(
						'name'        => self::term_name(),
						'slug'        => self::term_slug(),
						'description' => self::term_description(),
						'parent'      => C::integer( 0 ),
					),
					array( 'name' )
				);
				return S::object(
					array_merge(
						$taxonomy,
						array(
							'items' => array(
								'type'     => 'array',
								'items'    => $item,
								'minItems' => 1,
								'maxItems' => 50,
							),
						)
					),
					array( 'taxonomy', 'items' )
				);
			case 'bulk-assign-post-terms':
				return S::object(
					array(
						'taxonomy' => self::taxonomy_slug(),
						'post_ids' => array_merge( C::ids( 50 ), array( 'minItems' => 1 ) ),
						'term_ids' => array_merge( self::term_ids( 100 ), array( 'minItems' => 1 ) ),
						'mode'     => C::choices( array( 'append', 'remove' ) ),
					),
					array( 'taxonomy', 'post_ids', 'term_ids', 'mode' )
				);
			case 'delete-term':
				return S::object(
					array_merge(
						$taxonomy,
						$id,
						array( 'dry_run' => array( 'type' => 'boolean' ) )
					),
					array( 'taxonomy', 'id' )
				);
			default:
				return S::object( array_merge( $taxonomy, $id ), array( 'taxonomy', 'id' ) );
		}
	}

	/**
	 * Reviewed output by operation.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function output( string $name ): array {
		switch ( $name ) {
			case 'list-taxonomies':
				$item = S::object(
					array(
						'slug'            => self::taxonomy_slug(),
						'name'            => C::text(),
						'description'     => C::text(),
						'hierarchical'    => array( 'type' => 'boolean' ),
						'rest_base'       => C::text( 200 ),
						'types'           => array(
							'type'  => 'array',
							'items' => C::text( 20 ),
						),
						'can_assign'      => array( 'type' => 'boolean' ),
						'can_edit'        => array( 'type' => 'boolean' ),
						'can_delete'      => array( 'type' => 'boolean' ),
						'default_term_id' => array(
							'type'    => array( 'integer', 'null' ),
							'minimum' => 0,
						),
					),
					array( 'slug', 'name', 'hierarchical', 'rest_base', 'types', 'can_assign', 'can_edit', 'can_delete' )
				);
				return S::object(
					array(
						'items' => array(
							'type'  => 'array',
							'items' => $item,
						),
					),
					array( 'items' ),
					true
				);
			case 'list-terms':
				return C::collection( self::term() );
			case 'assign-post-terms':
				return S::object(
					array(
						'post_id'              => C::integer(),
						'taxonomy'             => self::taxonomy_slug(),
						'mode'                 => C::choices( array( 'replace', 'append', 'remove' ) ),
						'term_ids'             => self::term_ids( 100 ),
						'added'                => self::term_ids( 100 ),
						'removed'              => self::term_ids( 100 ),
						'default_term_applied' => array( 'type' => 'boolean' ),
					),
					array( 'post_id', 'taxonomy', 'mode', 'term_ids', 'added', 'removed', 'default_term_applied' )
				);
			case 'bulk-assign-post-terms':
				return S::object(
					array(
						'taxonomy'  => self::taxonomy_slug(),
						'mode'      => C::choices( array( 'append', 'remove' ) ),
						'updated'   => C::ids( 50 ),
						'unchanged' => C::ids( 50 ),
						'failed'    => array(
							'type'  => 'array',
							'items' => S::object(
								array(
									'id'   => C::integer(),
									'code' => C::text(),
								),
								array( 'id', 'code' )
							),
						),
					),
					array( 'taxonomy', 'mode', 'updated', 'unchanged', 'failed' ),
					true
				);
			case 'bulk-create-terms':
				$entry = S::object(
					array(
						'index' => C::integer( 0 ),
						'id'    => C::integer(),
						'name'  => C::text( 500 ),
						'slug'  => C::text( 200 ),
						'code'  => C::text(),
					),
					array( 'index' )
				);
				return S::object(
					array(
						'taxonomy' => self::taxonomy_slug(),
						'created'  => array(
							'type'  => 'array',
							'items' => $entry,
						),
						'existing' => array(
							'type'  => 'array',
							'items' => $entry,
						),
						'failed'   => array(
							'type'  => 'array',
							'items' => $entry,
						),
					),
					array( 'taxonomy', 'created', 'existing', 'failed' ),
					true
				);
			case 'delete-term':
				return S::object(
					array(
						'id'                  => C::integer(),
						'taxonomy'            => self::taxonomy_slug(),
						'deleted'             => array( 'type' => 'boolean' ),
						'dry_run'             => array( 'type' => 'boolean' ),
						'children_reparented' => C::integer( 0 ),
						'impact'              => S::object(
							array(
								'children'                 => C::integer( 0 ),
								'new_parent_for_children'  => C::integer( 0 ),
								'published_count'          => C::integer( 0 ),
								'will_reassign_to_default' => array( 'type' => 'boolean' ),
							),
							array( 'children', 'new_parent_for_children', 'published_count', 'will_reassign_to_default' )
						),
					),
					array( 'id', 'taxonomy', 'deleted' )
				);
			default:
				return self::term();
		}
	}
}
