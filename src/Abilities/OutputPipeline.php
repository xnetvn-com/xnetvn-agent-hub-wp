<?php
/**
 * Schema-preserving redaction and bounded logical ability outputs.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Support\Json;

/** Produces safe raw data; the official adapter assembles protocol content afterward. */
final class OutputPipeline {
	/** Sensitive property names, independent of user capability. */
	private const SENSITIVE = array( 'password', 'secret', 'token', 'hash', 'salt', 'api_key', 'secret_hash', 'user_pass', 'application_passwords', 'user_activation_key', 'session_tokens' );
	/**
	 * Bind the configured byte ceiling.
	 *
	 * @param SiteSettings $settings Current policy.
	 */
	public function __construct( private SiteSettings $settings ) {
	}
	/**
	 * Validate, redact, bound and validate the final logical result.
	 *
	 * @param mixed               $result Handler output.
	 * @param array<string,mixed> $schema Declared output schema.
	 * @return mixed Safe raw result or an execution error.
	 */
	public function process( mixed $result, array $schema ): mixed {
		if ( $result instanceof MutationResult ) {
			$output = $this->process( $result->data, $schema );
			return is_wp_error( $output ) ? new PartialFailure( $result->object_id, $result->created ) : $output;
		}
		if ( is_wp_error( $result ) ) {
			return ReasonMapper::sanitize( $result );
		}
		try {
			if ( true !== rest_validate_value_from_schema( $result, $schema ) ) {
				return $this->invalid();
			}
			$data = $this->redact( $result, 0 );
			if ( true !== rest_validate_value_from_schema( $data, $schema ) ) {
				return $this->invalid();
			}
			$limit = $this->settings->get( 'output_max_bytes' );
			if ( ! is_int( $limit ) || $limit < 1 ) {
				return $this->invalid();
			}
			if ( strlen( Json::encode( $data ) ) <= $limit ) {
				return $data;
			}
			if ( ! is_array( $data ) || ! isset( $schema['properties']['truncated'] ) ) {
				return $this->invalid();
			}
			$data['truncated'] = true;
			for ( $attempt = 0; $attempt < 64; ++$attempt ) {
				if ( strlen( Json::encode( $data ) ) <= $limit ) {
					return true === rest_validate_value_from_schema( $data, $schema ) ? $data : $this->invalid();
				}
				list( $changed, $next ) = $this->reduce( $data, $schema, 0 );
				if ( ! $changed ) {
					return $this->invalid();
				}
				$data = $next;
			}
			return $this->invalid();
		} catch ( \Throwable ) {
			return $this->invalid();
		}
	}
	/**
	 * Remove sensitive keys recursively without treating content as instructions.
	 *
	 * @param mixed $value Data.
	 * @param int   $depth Current depth.
	 * @return mixed
	 * @throws \LengthException For excessive output depth.
	 */
	private function redact( mixed $value, int $depth ): mixed {
		if ( $depth > 32 ) {
			throw new \LengthException( 'Output depth limit exceeded.' );
		}
		$object = $value instanceof \stdClass;
		if ( $object ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SENSITIVE, true ) ) {
				continue;
			}
			$result[ $key ] = $this->redact( $item, $depth + 1 );
		}
		return $object ? (object) $result : $result;
	}
	/**
	 * Reduce the largest eligible subtree, respecting declared minimum sizes.
	 *
	 * @param mixed               $value Data.
	 * @param array<string,mixed> $schema Schema.
	 * @param int                 $depth Depth.
	 * @return array{bool,mixed} Whether a safe reduction occurred and the result.
	 */
	private function reduce( mixed $value, array $schema, int $depth ): array {
		if ( $depth > 32 ) {
			return array( false, $value );
		}
		if ( is_string( $value ) ) {
			$length  = mb_strlen( $value, 'UTF-8' );
			$minimum = (int) ( $schema['minLength'] ?? 0 );
			$next    = max( $minimum, intdiv( $length, 2 ) );
			return $next < $length ? array( true, mb_substr( $value, 0, $next, 'UTF-8' ) ) : array( false, $value );
		}
		$object = $value instanceof \stdClass;
		if ( $object ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return array( false, $value );
		}
		if ( 'array' === ( $schema['type'] ?? null ) && count( $value ) > (int) ( $schema['minItems'] ?? 0 ) ) {
			$length = max( (int) ( $schema['minItems'] ?? 0 ), intdiv( count( $value ), 2 ) );
			return array( true, array_slice( $value, 0, $length ) );
		}
		$keys = array_keys( $value );
		usort( $keys, static fn( int|string $a, int|string $b ): int => strlen( Json::encode( $value[ $b ] ) ) <=> strlen( Json::encode( $value[ $a ] ) ) );
		foreach ( $keys as $key ) {
			$child = 'array' === ( $schema['type'] ?? null ) ? ( $schema['items'] ?? array() ) : ( $schema['properties'][ $key ] ?? array() );
			if ( ! is_array( $child ) ) {
				continue;
			}
			list( $changed, $next ) = $this->reduce( $value[ $key ], $child, $depth + 1 );
			if ( $changed ) {
				$value[ $key ] = $next;
				return array( true, $object ? (object) $value : $value );
			}
		}
		return array( false, $object ? (object) $value : $value );
	}
	/**
	 * Return a safe error without the original values or schema details.
	 *
	 * @return WP_Error
	 */
	private function invalid(): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_invalid_output', 'The output cannot be safely returned.' );
	}
}
