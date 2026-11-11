<?php
/**
 * Strict JSON serialization boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support;

/** Never repairs invalid bytes or silently substitutes an empty value. */
final class Json {
	/**
	 * Encode data without silently losing bytes.
	 *
	 * @param mixed $value Data.
	 * @return string
	 * @throws \JsonException For non-serializable data.
	 */
	public static function encode( mixed $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Strict pure-PHP encoding must reject invalid bytes rather than repair them.
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	/**
	 * Decode persisted arrays with explicit type validation.
	 *
	 * @param string $value JSON data.
	 * @return array<mixed>
	 * @throws \JsonException For invalid JSON or an unexpected root type.
	 */
	public static function array( string $value ): array {
		$data = json_decode( $value, true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) ) {
			throw new \JsonException( 'Expected a JSON array or object.' );
		}
		return $data;
	}
}
