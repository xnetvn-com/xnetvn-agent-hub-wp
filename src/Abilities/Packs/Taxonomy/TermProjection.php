<?php
/**
 * Pure, allowlisted term response shaping.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Taxonomy;

/** Term names, descriptions, slugs, links and meta are untrusted data. */
final readonly class TermProjection {
	/** Reviewed logical term DTO fields, in deterministic order. */
	public const FIELDS = array( 'id', 'taxonomy', 'name', 'slug', 'description', 'description_truncated', 'parent', 'count', 'link', 'meta', 'slug_adjusted' );

	/**
	 * Project a core REST record without forwarding private/arbitrary properties.
	 *
	 * @param array<string,mixed> $record Core REST record.
	 * @param array<string>       $fields Optional narrowing projection.
	 * @param int                 $description_max_chars Truncate description, zero disables.
	 * @param bool                $hierarchical Whether the taxonomy is hierarchical.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For unknown projection input.
	 * @throws \UnexpectedValueException For missing/malformed core output.
	 */
	public function project( array $record, array $fields = array(), int $description_max_chars = 0, bool $hierarchical = true ): array {
		if ( ! array_is_list( $fields ) || count( $fields ) > count( self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid term projection.' );
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid term projection field.' );
			}
		}
		if ( ! isset( $record['id'], $record['taxonomy'] ) || ! is_int( $record['id'] ) || $record['id'] < 1 || ! is_string( $record['taxonomy'] ) || '' === $record['taxonomy'] ) {
			throw new \UnexpectedValueException( 'Term identity is unavailable.' );
		}
		$selected = $fields ? array_values( array_unique( array_merge( array( 'id', 'taxonomy' ), $fields ) ) ) : array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count', 'link' );
		$result   = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! in_array( $field, $selected, true ) ) {
				continue;
			}
			switch ( $field ) {
				case 'id':
				case 'taxonomy':
				case 'name':
				case 'slug':
				case 'count':
					if ( array_key_exists( $field, $record ) ) {
						$result[ $field ] = $record[ $field ];
					}
					break;
				case 'description':
					if ( array_key_exists( 'description', $record ) && is_string( $record['description'] ) ) {
						$text = $record['description'];
						if ( $description_max_chars > 0 && mb_strlen( $text ) > $description_max_chars ) {
							$result['description']           = mb_substr( $text, 0, $description_max_chars );
							$result['description_truncated'] = true;
						} else {
							$result['description'] = $text;
							if ( in_array( 'description_truncated', $selected, true ) ) {
								$result['description_truncated'] = false;
							}
						}
					}
					break;
				case 'description_truncated':
					// Set alongside description; standalone request without description is ignored.
					break;
				case 'parent':
					if ( $hierarchical && array_key_exists( 'parent', $record ) && is_int( $record['parent'] ) ) {
						$result['parent'] = $record['parent'];
					}
					break;
				case 'link':
					if ( array_key_exists( 'link', $record ) && is_string( $record['link'] ) && '' !== $record['link'] ) {
						$result['link'] = $record['link'];
					}
					break;
				case 'meta':
					if ( array_key_exists( 'meta', $record ) && is_array( $record['meta'] ) ) {
						$result['meta'] = $record['meta'];
					}
					break;
				case 'slug_adjusted':
					if ( array_key_exists( 'slug_adjusted', $record ) && is_bool( $record['slug_adjusted'] ) ) {
						$result['slug_adjusted'] = $record['slug_adjusted'];
					}
					break;
			}
		}
		// Preserve deterministic field order defined by FIELDS.
		$ordered = array();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$ordered[ $field ] = $result[ $field ];
			}
		}
		return $ordered;
	}
}
