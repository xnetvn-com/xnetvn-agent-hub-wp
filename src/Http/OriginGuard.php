<?php
/**
 * Serialized-origin validation without network resolution.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Http;

/** Accepts non-browser clients or an explicitly allowed HTTP(S) origin. */
final class OriginGuard {
	/**
	 * Evaluate an Origin header against an exact normalized set.
	 *
	 * @param string|null   $origin Header, or absent.
	 * @param array<string> $allowed Explicit origins.
	 * @return bool
	 */
	public static function allows( ?string $origin, array $allowed ): bool {
		if ( null === $origin ) {
			return true;
		}
		$normalized = self::normalize( $origin );
		if ( null === $normalized ) {
			return false;
		}
		foreach ( $allowed as $value ) {
			if ( self::normalize( $value ) === $normalized ) {
				return true;
			}
		}
		return false;
	}
	/**
	 * Normalize a serialized origin; paths, credentials and opaque origins are forbidden.
	 *
	 * @param string $origin Candidate origin.
	 * @return string|null
	 */
	public static function normalize( string $origin ): ?string {
		if ( 1 === preg_match( '/[\x00-\x20\x7f]/', $origin ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure-PHP guard covers the supported PHP8.2+ URL behavior without bootstrapping WordPress.
		$parts = parse_url( $origin );
		if ( ! is_array( $parts ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host   = strtolower( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || 1 !== preg_match( '/\A(?:[a-z0-9.-]+|\[[a-f0-9:.]+\])\z/i', $host ) ) {
			return null;
		}
		$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		if ( $port < 1 || $port > 65535 ) {
			return null;
		}
		$suffix = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ? '' : ':' . $port;
		return $scheme . '://' . $host . $suffix;
	}
	/**
	 * Extract the origin from a trusted home/site URL with a subdirectory path.
	 *
	 * @param string $url Site URL.
	 * @return string|null
	 */
	public static function from_url( string $url ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Use the same validated pure-PHP parser as the header boundary.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		return self::normalize( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}
}
