<?php
/**
 * Strict comment tool schemas.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Comments;

use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas as C;
use XNetVN\AgentHubWP\Abilities\SchemaFactory as S;

/** Keeps tool contracts independent of comment execution. */
final class CommentSchemas {
	/**
	 * Shared comment read arguments.
	 *
	 * @return array<string,mixed>
	 */
	public static function read(): array {
		return array(
			'content_format' => C::choices( array( 'raw', 'rendered' ) ),
			'fields'         => array(
				'type'        => 'array',
				'items'       => C::choices( CommentProjection::FIELDS ),
				'uniqueItems' => true,
				'maxItems'    => 14,
			),
		);
	}
	/**
	 * Comment body bounded by the core column in bytes.
	 *
	 * @return array<string,mixed>
	 */
	public static function text(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 65525,
		);
	}
	/**
	 * Schema for each reviewed tool.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function input( string $name ): array {
		$id       = array( 'id' => C::integer() );
		$required = array( 'id' );
		switch ( $name ) {
			case 'get-comment-counts':
				return S::object( array( 'post' => C::integer() ) );
			case 'list-comments':
				return S::object(
					array_merge(
						S::pagination(),
						self::read(),
						array(
							'post'              => C::ids( 20 ),
							'status'            => C::choices( array( 'approved', 'hold', 'spam', 'trash', 'all' ) ),
							'type'              => C::choices( array( 'comment', 'pingback', 'trackback' ) ),
							'parent'            => array(
								'type'        => 'array',
								'items'       => C::integer( 0 ),
								'maxItems'    => 20,
								'uniqueItems' => true,
							),
							'author'            => C::ids( 20 ),
							'author_email'      => array(
								'type'      => 'string',
								'format'    => 'email',
								'maxLength' => 100,
							),
							'search'            => C::text( 200 ),
							'after'             => C::date(),
							'before'            => C::date(),
							'orderby'           => C::choices( array( 'date_gmt', 'id', 'post', 'parent' ) ),
							'order'             => C::choices( array( 'asc', 'desc' ) ),
							'content_max_chars' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 5000,
							),
						)
					)
				);
			case 'get-comment':
				return S::object( array_merge( $id, self::read() ), $required );
			case 'create-comment':
				return S::object(
					array(
						'post'    => C::integer(),
						'content' => self::text(),
						'status'  => C::choices( array( 'approved', 'hold' ) ),
					),
					array( 'post', 'content' )
				);
			case 'reply-comment':
				return S::object(
					array(
						'parent_id'      => C::integer(),
						'content'        => self::text(),
						'status'         => C::choices( array( 'approved', 'hold' ) ),
						'approve_parent' => array( 'type' => 'boolean' ),
					),
					array( 'parent_id', 'content' )
				);
			case 'update-comment':
				return S::object(
					array_merge(
						$id,
						array(
							'content'     => self::text(),
							'author_name' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 245,
							),
							'author_url'  => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
						)
					),
					$required
				);
			case 'moderate-comment':
				return S::object(
					array_merge( $id, array( 'action' => C::choices( CommentStatus::ACTIONS ) ) ),
					array( 'id', 'action' )
				);
			case 'bulk-moderate-comments':
				return S::object(
					array(
						'ids'    => array_merge( C::ids(), array( 'minItems' => 1 ) ),
						'action' => C::choices( CommentStatus::ACTIONS ),
					),
					array( 'ids', 'action' )
				);
			case 'delete-comment':
				return S::object( $id, $required );
			case 'purge-comments':
				return S::object(
					array(
						'status' => C::choices( array( 'spam', 'trash' ) ),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
						'before' => C::date(),
					),
					array( 'status' )
				);
			default:
				return S::object( $id, $required );
		}
	}
	/**
	 * Typed comment projection with privacy-reviewed fields.
	 *
	 * @return array<string,mixed>
	 */
	public static function comment(): array {
		$p = array_fill_keys( CommentProjection::FIELDS, C::text() );
		foreach ( array( 'id', 'post', 'parent', 'author' ) as $field ) {
			$p[ $field ] = C::integer( 'id' === $field ? 1 : 0 ); }
		$p['content_truncated'] = array( 'type' => 'boolean' );
		return S::object( $p, array( 'id', 'status' ), true );
	}
	/**
	 * Reviewed output by operation.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function output( string $name ): array {
		switch ( $name ) {
			case 'list-comments':
				return C::collection( self::comment() );
			case 'get-comment-counts':
				$p = array_fill_keys( array( 'approved', 'hold', 'spam', 'trash', 'post_trashed', 'all', 'total' ), C::integer( 0 ) );
				return S::object( $p, array_keys( $p ) );
			case 'reply-comment':
				$p                    = self::comment()['properties'];
				$p['parent_approved'] = array( 'type' => 'boolean' );
				return S::object( $p, array( 'id', 'status', 'parent_approved' ), true );
			case 'moderate-comment':
				return S::object(
					array(
						'id'              => C::integer(),
						'status'          => C::choices( CommentStatus::STATES ),
						'previous_status' => C::choices( CommentStatus::STATES ),
						'changed'         => array( 'type' => 'boolean' ),
					),
					array( 'id', 'status', 'previous_status', 'changed' )
				);
			case 'bulk-moderate-comments':
				return S::object(
					array(
						'updated'   => C::ids(),
						'unchanged' => C::ids(),
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
					array( 'updated', 'unchanged', 'failed' ),
					true
				);
			case 'delete-comment':
				return C::output( 'delete-post' );
			case 'purge-comments':
				$p  = array_fill_keys( array( 'deleted', 'skipped', 'failed', 'remaining' ), C::integer( 0 ) );
				$p += array(
					'status' => C::choices( array( 'spam', 'trash' ) ),
					'cutoff' => C::date(),
				);
				return S::object( $p, array_keys( $p ) );
			default:
				return self::comment();
		}
	}
}
