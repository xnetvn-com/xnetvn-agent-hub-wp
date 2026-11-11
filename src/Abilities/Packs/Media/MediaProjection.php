<?php
/**
 * Attachment projection without EXIF, binary data or server paths.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Media;

use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Nested attachment data is allowlisted independently of the root record. */
final class MediaProjection {
	/** Public fields. */
	public const FIELDS = array( 'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'status', 'link', 'title', 'caption', 'description', 'alt_text', 'media_type', 'mime_type', 'source_url', 'author', 'post', 'media_details' );
	/**
	 * Project one attachment; refused raw access never falls back.
	 *
	 * @param array<string,mixed> $row Core record.
	 * @param string              $format Requested representation.
	 * @param array<string>       $fields Narrowing projection.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function project( array $row, string $format = 'rendered', array $fields = array() ): array|\WP_Error {
		if ( 'raw' === $format && ! current_user_can( 'edit_post', $row['id'] ) ) {
			return ReasonMapper::error( 'forbidden' ); }
		if ( array_key_exists( 'post', $row ) && null === $row['post'] ) {
			$row['post'] = 0; }
		foreach ( array( 'date_gmt', 'modified_gmt' ) as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] && ! str_ends_with( $row[ $field ], 'Z' ) ) {
				$row[ $field ] .= 'Z'; }
		}
		$selected = $fields ? array_unique( array_merge( array( 'id', 'status' ), $fields ) ) : self::FIELDS;
		$result   = array_intersect_key( $row, array_flip( array_intersect( self::FIELDS, $selected ) ) );
		foreach ( array( 'title', 'caption', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $result ) ) {
				continue; }
			$source = 'excerpt' === $format && 'description' === $field ? 'caption' : $field;
			$key    = 'raw' === $format ? 'raw' : 'rendered';
			if ( ! isset( $row[ $source ][ $key ] ) || ! is_string( $row[ $source ][ $key ] ) ) {
				return ReasonMapper::error( 'internal_error' ); }
			$result[ $field ] = $row[ $source ][ $key ];
		}
		if ( isset( $result['media_details'] ) ) {
			$details = array_intersect_key( (array) $result['media_details'], array_flip( array( 'width', 'height', 'filesize', 'file' ) ) );
			if ( isset( $details['file'] ) && ( ! is_string( $details['file'] ) || str_starts_with( $details['file'], '/' ) || str_contains( $details['file'], '..' ) || str_contains( $details['file'], '\\' ) || 1 === preg_match( '/^[A-Za-z]:/', $details['file'] ) ) ) {
				unset( $details['file'] ); }
			$sizes = array();
			foreach ( (array) ( $row['media_details']['sizes'] ?? array() ) as $key => $size ) {
				if ( is_array( $size ) ) {
					$sizes[ $key ] = array_intersect_key( $size, array_flip( array( 'width', 'height', 'mime_type', 'source_url' ) ) ); }
			}
			$details['sizes']        = (object) $sizes;
			$result['media_details'] = (object) $details;
		}
		return $result;
	}
}
