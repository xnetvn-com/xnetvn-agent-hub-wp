<?php
/**
 * Strict content tool schemas.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use XNetVN\AgentHubWP\Abilities\SchemaFactory as S;

/** Keeps tool contracts independent of controller execution. */
final class ContentSchemas {
	/** Post output fields in projection order. */
	public const FIELDS = array( 'id', 'type', 'status', 'title', 'slug', 'link', 'date', 'modified', 'author', 'excerpt', 'content', 'featured_media', 'categories', 'tags', 'sticky', 'meta', 'date_gmt', 'modified_gmt', 'parent', 'menu_order', 'template', 'comment_status', 'ping_status', 'format' );
	/**
	 * Build a bounded string.
	 *
	 * @param int $max Maximum characters.
	 * @return array<string,mixed>
	 */
	public static function text( int $max = 262144 ): array {
		return array(
			'type'      => 'string',
			'maxLength' => $max,
		); }
	/**
	 * Build an integer.
	 *
	 * @param int $min Minimum.
	 * @return array<string,mixed>
	 */
	public static function integer( int $min = 1 ): array {
		return array(
			'type'    => 'integer',
			'minimum' => $min,
		); }
	/**
	 * Build a bounded identifier list.
	 *
	 * @param int $max Maximum count.
	 * @return array<string,mixed>
	 */
	public static function ids( int $max = 50 ): array {
		return array(
			'type'        => 'array',
			'items'       => self::integer(),
			'maxItems'    => $max,
			'uniqueItems' => true,
		); }
	/**
	 * Build an enumeration.
	 *
	 * @param array<string> $values Values.
	 * @return array<string,mixed>
	 */
	public static function choices( array $values ): array {
		return array(
			'type' => 'string',
			'enum' => $values,
		); }
	/**
	 * Timestamp requires an explicit offset.
	 *
	 * @return array<string,mixed>
	 */
	public static function date(): array {
		return array(
			'type'      => 'string',
			'format'    => 'date-time',
			'maxLength' => 40,
			'pattern'   => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$',
		); }
	/**
	 * Dynamic registered metadata is validated against core at execution.
	 *
	 * @return array<string,mixed>
	 */
	public static function meta(): array {
		return array(
			'type'          => 'object',
			'maxProperties' => 50,
		); }
	/**
	 * Shared content read arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function read(): array {
		return array(
			'content_format' => self::choices( array( 'raw', 'rendered', 'excerpt' ) ),
			'fields'         => array(
				'type'        => 'array',
				'items'       => self::choices( self::FIELDS ),
				'uniqueItems' => true,
				'maxItems'    => 24,
			),
		);
	}
	/**
	 * Allowed mutable content fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function changes(): array {
		return array(
			'title'          => self::text( 1000 ),
			'content'        => self::text(),
			'excerpt'        => self::text( 10000 ),
			'status'         => self::choices( array( 'draft', 'pending', 'private', 'publish', 'future' ) ),
			'slug'           => array_merge( self::text( 200 ), array( 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' ) ),
			'categories'     => self::ids(),
			'tags'           => self::ids(),
			'featured_media' => self::integer( 0 ),
			'meta'           => self::meta(),
			'date'           => self::date(),
			'sticky'         => array( 'type' => 'boolean' ),
			'parent'         => self::integer( 0 ),
			'menu_order'     => array( 'type' => 'integer' ),
			'template'       => self::text( 200 ),
			'comment_status' => self::choices( array( 'open', 'closed' ) ),
			'ping_status'    => self::choices( array( 'open', 'closed' ) ),
			'format'         => self::text( 20 ),
		);
	}
	/**
	 * Schema for each reviewed tool.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function input( string $name ): array {
		$type     = array(
			'post_type' => array(
				'type'    => 'string',
				'pattern' => '^[a-z0-9_-]{1,20}$',
				'default' => 'post',
			),
		);
		$id       = array_merge( $type, array( 'id' => self::integer() ) );
		$required = array( 'id' );
		switch ( $name ) {
			case 'list-post-types':
			case 'list-post-statuses':
				return S::object();
			case 'list-posts':
				$p        = array_merge(
					$type,
					S::pagination(),
					self::read(),
					array(
						'status'     => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,20}$',
							),
							'maxItems' => 10,
						),
						'search'     => self::text( 200 ),
						'author'     => self::ids( 20 ),
						'categories' => self::ids(),
						'tags'       => self::ids(),
						'after'      => self::date(),
						'before'     => self::date(),
						'orderby'    => self::choices( array( 'date', 'modified', 'title', 'id', 'slug', 'author', 'relevance', 'menu_order' ) ),
						'order'      => self::choices( array( 'asc', 'desc' ) ),
					)
				);
				$required = array();
				break;
			case 'get-post':
				$p = array_merge( $id, self::read() );
				break;
			case 'create-post':
				$p        = array_merge( $type, self::changes() );
				$required = array();
				break;
			case 'update-post':
				$p = array_merge( $id, self::changes(), array( 'expected_modified' => self::date() ) );
				break;
			case 'restore-post':
			case 'duplicate-post':
				$p = array_merge( $id, array( 'status' => self::choices( 'restore-post' === $name ? array( 'draft', 'publish' ) : array( 'draft', 'pending' ) ) ) );
				break;
			case 'bulk-update-posts':
				$changes           = array_intersect_key( self::changes(), array_flip( array( 'status', 'categories', 'tags', 'sticky' ) ) );
				$changes['author'] = self::integer();
				$p                 = array_merge(
					$type,
					array(
						'ids'     => array_merge( self::ids(), array( 'minItems' => 1 ) ),
						'changes' => array_merge( S::object( $changes ), array( 'minProperties' => 1 ) ),
					)
				);
				$required          = array( 'ids', 'changes' );
				break;
			case 'list-revisions':
				$p = array_merge( $id, S::pagination(), array( 'content_format' => self::read()['content_format'] ) );
				break;
			case 'get-revision':
			case 'restore-revision':
				$p          = array_merge(
					$id,
					array(
						'revision_id'    => self::integer(),
						'content_format' => self::read()['content_format'],
					)
				);
				$required[] = 'revision_id';
				break;
			case 'set-featured-media':
				$p          = array_merge( $id, array( 'media_id' => self::integer( 0 ) ) );
				$required[] = 'media_id';
				break;
			case 'stick-post':
				$p          = array_merge( $id, array( 'sticky' => array( 'type' => 'boolean' ) ) );
				$required[] = 'sticky';
				break;
			case 'get-post-meta':
				$p = array_merge(
					$id,
					array(
						'keys' => array(
							'type'        => 'array',
							'maxItems'    => 50,
							'items'       => array(
								'type'    => 'string',
								'pattern' => '^[A-Za-z0-9_-]{1,255}$',
							),
							'uniqueItems' => true,
						),
					)
				);
				break;
			case 'update-post-meta':
				$p          = array_merge( $id, array( 'values' => self::meta() ) );
				$required[] = 'values';
				break;
			case 'search-content':
				$p        = array_merge(
					S::pagination(),
					array(
						'search'  => self::text( 200 ),
						'subtype' => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9_-]{1,20}$',
						),
					)
				);
				$required = array( 'search' );
				break;
			default:
				$p = $id;
		}
		return S::object( $p, $required );
	}
	/**
	 * Typed post projection with optional supported fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function post(): array {
		$p = array_fill_keys( self::FIELDS, array( 'type' => 'string' ) );
		foreach ( array( 'id', 'author', 'featured_media', 'parent' ) as $field ) {
			$p[ $field ] = self::integer( 'id' === $field ? 1 : 0 ); }
		$p['menu_order'] = array( 'type' => 'integer' );
		$p['sticky']     = array( 'type' => 'boolean' );
		$p['meta']       = self::meta();
		$p['categories'] = self::ids();
		$p['tags']       = self::ids();
		return S::object( $p, array( 'id', 'type', 'status' ), true );
	}
	/**
	 * Root collection envelope.
	 *
	 * @param array<string,mixed> $item Record schema.
	 * @param bool                $paged Include core pagination.
	 * @return array<string,mixed>
	 */
	public static function collection( array $item, bool $paged = true ): array {
		$p = array(
			'items' => array(
				'type'  => 'array',
				'items' => $item,
			),
		);
		if ( $paged ) {
			$p += array(
				'total'       => self::integer( 0 ),
				'total_pages' => self::integer( 0 ),
				'page'        => self::integer(),
			); }
		return S::object( $p, array_keys( $p ), true );
	}
	/**
	 * Reviewed output by operation.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function output( string $name ): array {
		switch ( $name ) {
			case 'list-post-types':
				return self::collection(
					S::object(
						array(
							'slug'         => self::text( 20 ),
							'name'         => self::text(),
							'rest_base'    => self::text(),
							'hierarchical' => array( 'type' => 'boolean' ),
							'supports'     => array(
								'type'  => 'array',
								'items' => self::text(),
							),
						),
						array( 'slug', 'name', 'rest_base', 'hierarchical', 'supports' )
					),
					false
				);
			case 'list-post-statuses':
				return self::collection(
					S::object(
						array(
							'slug' => self::text(),
							'name' => self::text(),
						),
						array( 'slug', 'name' )
					),
					false
				);
			case 'list-posts':
				return self::collection( self::post() );
			case 'list-revisions':
				return self::collection( self::revision() );
			case 'get-revision':
				return self::revision();
			case 'search-content':
				return self::collection(
					S::object(
						array(
							'id'      => self::integer(),
							'title'   => self::text(),
							'url'     => self::text(),
							'type'    => self::text(),
							'subtype' => self::text(),
						),
						array( 'id', 'title', 'url', 'type', 'subtype' )
					)
				);
			case 'get-post-meta':
			case 'update-post-meta':
				return S::object( array( 'meta' => self::meta() ), array( 'meta' ), true );
			case 'trash-post':
				return S::object(
					array(
						'id'     => self::integer(),
						'status' => self::choices( array( 'trash' ) ),
					),
					array( 'id', 'status' )
				);
			case 'delete-post':
				return S::object(
					array(
						'id'      => self::integer(),
						'deleted' => array(
							'type' => 'boolean',
							'enum' => array( true ),
						),
					),
					array( 'id', 'deleted' )
				);
			case 'bulk-update-posts':
				return S::object(
					array(
						'updated' => self::ids(),
						'failed'  => array(
							'type'  => 'array',
							'items' => S::object(
								array(
									'id'   => self::integer(),
									'code' => self::text(),
								),
								array( 'id', 'code' )
							),
						),
					),
					array( 'updated', 'failed' ),
					true
				);
			default:
				return self::post();
		}
	}
	/**
	 * Revision fields with parent binding.
	 *
	 * @return array<string,mixed>
	 */
	public static function revision(): array {
		$p  = array_fill_keys( array( 'date', 'modified', 'slug', 'title', 'content', 'excerpt' ), array( 'type' => 'string' ) );
		$p += array(
			'id'     => self::integer(),
			'parent' => self::integer(),
			'author' => self::integer( 0 ),
		);
		return S::object( $p, array_keys( $p ), true );
	}
}
