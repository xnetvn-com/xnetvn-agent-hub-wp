<?php
/**
 * Plain-text profile fields and bounded registered user metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;

/** Applies the narrower user-profile policy without a second KSES context. */
final class ProfileFieldPolicy {

	/** Reviewed profile fields accepted by `update-my-profile`. */
	public const FIELDS = array( 'name', 'first_name', 'last_name', 'nickname', 'description', 'url', 'slug', 'locale', 'meta' );

	/** Maximum registered meta keys accepted in one write. */
	private const MAX_META_KEYS = 20;
	/** Maximum scalar leaves in one meta value. */
	private const MAX_LEAVES = 100;
	/** Maximum nested containers in one meta value. */
	private const MAX_DEPTH = 3;
	/** Maximum profile URL length. */
	private const MAX_URL_LENGTH = 100;

	/** Shared plain-text boundary.
	 *
	 * @var SafeEditorialHtml
	 */
	private SafeEditorialHtml $html;

	/**
	 * Bind the reviewed plain-text boundary.
	 *
	 * @param SafeEditorialHtml|null $html Optional injected boundary.
	 */
	public function __construct( ?SafeEditorialHtml $html = null ) {
		$this->html = $html ?? new SafeEditorialHtml();
	}

	/**
	 * Filter the reviewed profile fields before core REST dispatch.
	 *
	 * @param array<string,mixed> $changes Profile changes.
	 * @param array<string,mixed> $registered Effective registered meta schemas.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function changes( array $changes, array $registered ): array|\WP_Error {
		if ( array_diff( array_keys( $changes ), self::FIELDS ) ) {
			return R::error( 'invalid_params' );
		}
		try {
			foreach ( $changes as $field => $value ) {
				switch ( $field ) {
					case 'name':
						$name = $this->plain( $value );
						if ( '' === trim( $name ) ) {
							return R::error( 'invalid_params' );
						}
						$changes[ $field ] = $name;
						break;
					case 'first_name':
					case 'last_name':
					case 'nickname':
						$changes[ $field ] = $this->plain( $value );
						break;
					case 'url':
						$changes[ $field ] = $this->url( $this->text( $value ) );
						break;
					case 'meta':
						$changes[ $field ] = $this->meta( $value, $registered );
						break;
					case 'description':
					case 'slug':
					case 'locale':
						$this->text( $value );
						break;
				}
			}
		} catch ( \DomainException ) {
			return R::error( 'invalid_params' );
		}
		return $changes;
	}

	/**
	 * Require a string value.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 * @throws \DomainException For a non-string value.
	 */
	private function text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			throw new \DomainException( 'Profile field is not a string.' );
		}
		return $value;
	}

	/**
	 * Filter one plain-text profile field.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 * @throws \DomainException For a non-string value.
	 */
	private function plain( mixed $value ): string {
		return $this->html->filter_plain_text( $this->text( $value ) );
	}

	/**
	 * Apply the reviewed absolute-URL rule.
	 *
	 * @param string $url Candidate.
	 * @return string
	 * @throws \DomainException For an unsafe or rewritten value.
	 */
	private function url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $url ) : strlen( $url );
		if ( $length > self::MAX_URL_LENGTH ) {
			throw new \DomainException( 'Profile URL is too long.' );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			throw new \DomainException( 'Profile URL is malformed.' );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! isset( $parts['host'] ) || '' === $parts['host'] || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new \DomainException( 'Profile URL scheme or authority is unsafe.' );
		}
		$normalized = preg_replace( '/\A[A-Za-z][A-Za-z0-9+.\-]*:/', $scheme . ':', $url, 1 );
		if ( ! is_string( $normalized ) ) {
			throw new \DomainException( 'Profile URL cannot be normalized.' );
		}
		$escaped = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( ! is_string( $escaped ) || '' === $escaped || $escaped !== $normalized ) {
			throw new \DomainException( 'Profile URL would be rewritten.' );
		}
		return $normalized;
	}

	/**
	 * Filter registered meta against its effective REST schema.
	 *
	 * @param mixed               $value Candidate meta object.
	 * @param array<string,mixed> $registered Effective schemas.
	 * @return array<string,mixed>
	 * @throws \DomainException For an unsupported or undeclared value.
	 */
	private function meta( mixed $value, array $registered ): array {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_META_KEYS ) {
			throw new \DomainException( 'Profile meta is not a bounded object.' );
		}
		$out    = array();
		$leaves = 0;
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) || ! isset( $registered[ $key ] ) || ! is_array( $registered[ $key ] ) ) {
				throw new \DomainException( 'Profile meta key is not writable.' );
			}
			$cleaned = $this->clean( $item, $registered[ $key ], 0, $leaves );
			if ( null !== $cleaned && is_wp_error( rest_validate_value_from_schema( $cleaned, $registered[ $key ] ) ) ) {
				throw new \DomainException( 'Filtered profile meta breaks its schema.' );
			}
			$out[ $key ] = $cleaned;
		}
		return $out;
	}

	/**
	 * Walk one meta value with the registered schema.
	 *
	 * @param mixed               $value Candidate.
	 * @param array<string,mixed> $schema Effective schema.
	 * @param int                 $depth Container depth.
	 * @param int                 $leaves Counted scalar leaves.
	 * @return mixed
	 * @throws \DomainException For an unsupported value.
	 */
	private function clean( mixed $value, array $schema, int $depth, int &$leaves ): mixed {
		if ( null === $value ) {
			++$leaves;
			$this->assert_leaves( $leaves );
			return null;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			if ( 'string' === ( $schema['type'] ?? null ) ) {
				throw new \DomainException( 'Typed meta cannot be coerced to text.' );
			}
			++$leaves;
			$this->assert_leaves( $leaves );
			return $value;
		}
		if ( is_string( $value ) ) {
			++$leaves;
			$this->assert_leaves( $leaves );
			$format = is_string( $schema['format'] ?? null ) ? $schema['format'] : '';
			if ( 'uri' === $format ) {
				return $this->url( $value );
			}
			if ( 'textarea-field' === $format ) {
				return sanitize_textarea_field( wp_strip_all_tags( $value ) );
			}
			return $this->html->filter_plain_text( $value );
		}
		if ( ! is_array( $value ) ) {
			throw new \DomainException( 'Unsupported profile meta type.' );
		}
		if ( $depth >= self::MAX_DEPTH ) {
			throw new \DomainException( 'Profile meta nests too deeply.' );
		}
		$type = $schema['type'] ?? null;
		if ( 'object' === $type ) {
			$properties = $schema['properties'] ?? null;
			if ( ! is_array( $properties ) ) {
				throw new \DomainException( 'Object meta requires declared properties.' );
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) || ! isset( $properties[ $key ] ) || ! is_array( $properties[ $key ] ) ) {
					throw new \DomainException( 'Object meta property is not declared.' );
				}
				$out[ $key ] = $this->clean( $item, $properties[ $key ], $depth + 1, $leaves );
			}
			return $out;
		}
		if ( 'array' === $type ) {
			$items = $schema['items'] ?? null;
			if ( ! is_array( $items ) ) {
				throw new \DomainException( 'Array meta requires an items schema.' );
			}
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->clean( $item, $items, $depth + 1, $leaves );
			}
			return $out;
		}
		throw new \DomainException( 'Unsupported array meta schema.' );
	}

	/**
	 * Refuse more than the reviewed scalar-leaf bound.
	 *
	 * @param int $leaves Current count.
	 * @return void
	 * @throws \DomainException For too many leaves.
	 */
	private function assert_leaves( int $leaves ): void {
		if ( $leaves > self::MAX_LEAVES ) {
			throw new \DomainException( 'Profile meta has too many leaves.' );
		}
	}
}
