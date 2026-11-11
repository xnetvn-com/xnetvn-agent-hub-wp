<?php
/**
 * Strict media contracts and safe output examples.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Media;

use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas as C;
use XNetVN\AgentHubWP\Abilities\SchemaFactory as S;

/** Upload byte ceilings are also rechecked dynamically before decoding. */
final class MediaSchemas {
	/**
	 * Allowed editable metadata, without file replacement or author assignment.
	 *
	 * @return array<string,mixed>
	 */
	public static function changes(): array {
		return array(
			'title'       => C::text( 1000 ),
			'alt_text'    => C::text( 1000 ),
			'caption'     => C::text( 5000 ),
			'description' => C::text( 10000 ),
			'post'        => C::integer( 0 ),
		); }
	/**
	 * Select input by reviewed operation.
	 *
	 * @param string $name Tool.
	 * @return array<string,mixed>
	 */
	public static function input( string $name ): array {
		$read = array(
			'content_format' => C::read()['content_format'],
			'fields'         => array(
				'type'        => 'array',
				'items'       => C::choices( MediaProjection::FIELDS ),
				'maxItems'    => count( MediaProjection::FIELDS ),
				'uniqueItems' => true,
			),
		);
		switch ( $name ) {
			case 'get-media-upload-limits':
				return S::object();
			case 'list-media':
				return S::object(
					array_merge(
						S::pagination(),
						$read,
						array(
							'search'     => C::text( 200 ),
							'media_type' => array(
								'type'     => 'array',
								'maxItems' => 5,
								'items'    => C::choices( array( 'image', 'video', 'audio', 'text', 'application' ) ),
							),
							'mime_type'  => array(
								'type'     => 'array',
								'maxItems' => 10,
								'items'    => array(
									'type'    => 'string',
									'pattern' => '^[a-z0-9.+-]+/[a-z0-9.+-]+$',
								),
							),
							'parent'     => C::integer( 0 ),
							'author'     => C::ids( 20 ),
							'after'      => C::date(),
							'before'     => C::date(),
							'status'     => C::choices( array( 'inherit', 'private' ) ),
							'orderby'    => C::choices( array( 'date', 'modified', 'title', 'id', 'slug', 'author', 'relevance' ) ),
							'order'      => C::choices( array( 'asc', 'desc' ) ),
						)
					)
				);
			case 'get-media':
				return S::object( array_merge( array( 'id' => C::integer() ), $read ), array( 'id' ) );
			case 'update-media':
				return S::object( array_merge( array( 'id' => C::integer() ), self::changes() ), array( 'id' ) );
			case 'delete-media':
				return S::object( array( 'id' => C::integer() ), array( 'id' ) );
			default:
				$p = array_merge(
					self::changes(),
					array(
						'filename' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 255,
						),
					)
				);
				if ( 'upload-media-from-data' === $name ) {
					$p       += array(
						'mime_type'   => array(
							'type'      => 'string',
							'maxLength' => 127,
							'pattern'   => '^[a-z0-9.+-]+/[a-z0-9.+-]+$',
						),
						'data_base64' => array(
							'type'      => 'string',
							'maxLength' => 11184812,
						),
					);
					$required = array( 'filename', 'mime_type', 'data_base64' );
				} else {
					$p['url'] = array(
						'type'      => 'string',
						'pattern'   => '^https://',
						'maxLength' => 2048,
					);
					$required = array( 'url' ); }
				return S::object( $p, $required );
		}
	}
	/**
	 * Strict nested output with dynamic size names and no EXIF metadata.
	 *
	 * @return array<string,mixed>
	 */
	public static function media(): array {
		$p = array_fill_keys( MediaProjection::FIELDS, C::text() );
		foreach ( array( 'id', 'author', 'post' ) as $key ) {
			$p[ $key ] = C::integer( 'id' === $key ? 1 : 0 ); }
		$size               = S::object(
			array(
				'width'      => C::integer( 0 ),
				'height'     => C::integer( 0 ),
				'mime_type'  => C::text(),
				'source_url' => C::text(),
			)
		);
		$p['media_details'] = S::object(
			array(
				'width'    => C::integer( 0 ),
				'height'   => C::integer( 0 ),
				'filesize' => C::integer( 0 ),
				'file'     => C::text(),
				'sizes'    => array(
					'type'                 => 'object',
					'additionalProperties' => $size,
				),
			)
		);
		return S::object( $p, array( 'id', 'status' ), true );
	}
	/**
	 * Select strict root output.
	 *
	 * @param string $name Tool.
	 * @return array<string,mixed>
	 */
	public static function output( string $name ): array {
		if ( 'list-media' === $name ) {
			return C::collection( self::media() ); }
		if ( 'delete-media' === $name ) {
			return C::output( 'delete-post' ); }
		if ( 'get-media-upload-limits' === $name ) {
			$p  = array_fill_keys( array( 'max_upload_bytes', 'data_upload_max_bytes', 'remote_fetch_max_bytes', 'pixels_max' ), C::integer( 0 ) );
			$p += array(
				'remote_fetch_enabled' => array( 'type' => 'boolean' ),
				'allowed_mime_types'   => array(
					'type'  => 'array',
					'items' => C::text( 127 ),
				),
			);
			return S::object( $p, array_keys( $p ) ); }
		return self::media();
	}
}
