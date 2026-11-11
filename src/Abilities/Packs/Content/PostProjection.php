<?php
/**
 * Pure, allowlisted content response shaping.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use Closure;

/** Preserves object identity and never converts a refused raw read into success. */
final readonly class PostProjection {
	/** Reviewed logical post DTO fields, in deterministic order. */
	private const FIELDS = array( 'id', 'type', 'status', 'title', 'slug', 'link', 'date', 'modified', 'author', 'excerpt', 'content', 'featured_media', 'categories', 'tags', 'sticky', 'meta', 'date_gmt', 'modified_gmt', 'parent', 'menu_order', 'template', 'comment_status', 'ping_status', 'format' );
	/**
	 * Bind the infrastructure's exact object-edit permission callback.
	 *
	 * @param Closure $can_edit Object permission checker.
	 * @phpstan-param Closure(int):bool $can_edit
	 */
	public function __construct( private Closure $can_edit ) {
	}

	/**
	 * Project a core REST record without forwarding protected/arbitrary properties.
	 *
	 * @param array<string,mixed> $record REST record.
	 * @param string              $format Requested text representation.
	 * @param array<string>       $fields Optional projection.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For unknown projection input.
	 * @throws \UnexpectedValueException For missing/malformed core output.
	 * @throws \DomainException For refused raw access.
	 */
	public function project( array $record, string $format = 'rendered', array $fields = array() ): array {
		if ( ! in_array( $format, array( 'raw', 'rendered', 'excerpt' ), true ) || ! array_is_list( $fields ) || count( $fields ) > count( self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid post projection.' );
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid post projection field.' );
			}
		}
		if ( ! isset( $record['id'], $record['type'], $record['status'] ) || ! is_int( $record['id'] ) || $record['id'] < 1 || ! is_string( $record['type'] ) || '' === $record['type'] || ! is_string( $record['status'] ) || '' === $record['status'] ) {
			throw new \UnexpectedValueException( 'Post identity is unavailable.' );
		}
		if ( 'raw' === $format && ! ( $this->can_edit )( $record['id'] ) ) {
			throw new \DomainException( 'Raw post data is unavailable.' );
		}
		foreach ( array( 'date_gmt', 'modified_gmt' ) as $field ) {
			if ( isset( $record[ $field ] ) && is_string( $record[ $field ] ) && '' !== $record[ $field ] && ! str_ends_with( $record[ $field ], 'Z' ) ) {
				$record[ $field ] .= 'Z'; }
		}
		$selected = $fields ? array_values( array_unique( array_merge( array( 'id', 'type', 'status' ), $fields ) ) ) : self::FIELDS;
		$result   = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! in_array( $field, $selected, true ) || ! array_key_exists( $field, $record ) ) {
				continue;
			}
			if ( in_array( $field, array( 'title', 'excerpt', 'content' ), true ) ) {
				$source = 'excerpt' === $format && 'content' === $field ? ( $record['excerpt'] ?? null ) : $record[ $field ];
				$key    = 'raw' === $format ? 'raw' : 'rendered';
				if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) || ! is_string( $source[ $key ] ) ) {
					throw new \UnexpectedValueException( 'Post text representation is unavailable.' );
				}
				$result[ $field ] = $source[ $key ];
			} else {
				$result[ $field ] = $record[ $field ];
			}
		}
		return $result;
	}
}
