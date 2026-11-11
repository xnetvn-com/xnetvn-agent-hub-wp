<?php
/**
 * Pure, allowlisted comment response shaping.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Comments;

use Closure;

/** Email, IP, user agent and avatars never leave except reviewed email. */
final readonly class CommentProjection {
	/** Reviewed logical comment DTO fields, in deterministic order. */
	public const FIELDS = array( 'id', 'post', 'parent', 'type', 'status', 'author', 'author_name', 'author_url', 'author_email', 'content', 'content_truncated', 'date', 'date_gmt', 'link' );
	/**
	 * Bind the infrastructure's exact object-edit permission callback.
	 *
	 * @param Closure $can_edit Object permission checker.
	 * @phpstan-param Closure(int):bool $can_edit
	 */
	public function __construct( private Closure $can_edit ) {
	}

	/**
	 * Project a core REST record without forwarding private/arbitrary properties.
	 *
	 * @param array<string,mixed> $record REST record.
	 * @param string              $format Requested text representation.
	 * @param array<string>       $fields Optional projection.
	 * @param int                 $content_max_chars Truncate content, zero disables.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For unknown projection input.
	 * @throws \UnexpectedValueException For missing/malformed core output.
	 * @throws \DomainException For refused raw or email access.
	 */
	public function project( array $record, string $format = 'rendered', array $fields = array(), int $content_max_chars = 0 ): array {
		if ( ! in_array( $format, array( 'raw', 'rendered' ), true ) || ! array_is_list( $fields ) || count( $fields ) > count( self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid comment projection.' );
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid comment projection field.' );
			}
		}
		if ( ! isset( $record['id'], $record['status'] ) || ! is_int( $record['id'] ) || $record['id'] < 1 || ! is_string( $record['status'] ) || '' === $record['status'] ) {
			throw new \UnexpectedValueException( 'Comment identity is unavailable.' );
		}
		$wants_email = in_array( 'author_email', $fields, true );
		if ( 'raw' === $format && ! ( $this->can_edit )( $record['id'] ) ) {
			throw new \DomainException( 'Raw comment data is unavailable.' );
		}
		if ( $wants_email && ! ( $this->can_edit )( $record['id'] ) ) {
			throw new \DomainException( 'Comment email is unavailable.' );
		}
		if ( isset( $record['date_gmt'] ) && is_string( $record['date_gmt'] ) && '' !== $record['date_gmt'] && ! str_ends_with( $record['date_gmt'], 'Z' ) ) {
			$record['date_gmt'] .= 'Z';
		}
		$selected = $fields ? array_values( array_unique( array_merge( array( 'id', 'status' ), $fields ) ) ) : array_values( array_diff( self::FIELDS, array( 'author_email' ) ) );
		$result   = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! in_array( $field, $selected, true ) || ! array_key_exists( $field, $record ) ) {
				continue;
			}
			if ( 'content' === $field ) {
				$source = $record['content'];
				$key    = 'raw' === $format ? 'raw' : 'rendered';
				if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) || ! is_string( $source[ $key ] ) ) {
					throw new \UnexpectedValueException( 'Comment text representation is unavailable.' );
				}
				$text                        = $source[ $key ];
				$result['content']           = $text;
				$result['content_truncated'] = false;
				if ( $content_max_chars > 0 && mb_strlen( $text ) > $content_max_chars ) {
					$result['content']           = mb_substr( $text, 0, $content_max_chars );
					$result['content_truncated'] = true;
				}
			} else {
				$result[ $field ] = $record[ $field ];
			}
		}
		return $result;
	}
}
