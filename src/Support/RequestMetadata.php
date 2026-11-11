<?php
/**
 * Bounded non-authoritative request metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Support;

/** Shared privacy transformations for audit and last-use metadata. */
final class RequestMetadata {
	/**
	 * Validate and optionally mask an IP after all authorization checks.
	 *
	 * @param string|null $ip Socket peer.
	 * @param bool        $anonymize Clear host bits.
	 * @return string|null
	 */
	public static function ip( ?string $ip, bool $anonymize ): ?string {
		if ( null === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		if ( $anonymize ) {
			$keep   = 4 === strlen( $packed ) ? 3 : 6;
			$packed = substr( $packed, 0, $keep ) . str_repeat( "\0", strlen( $packed ) - $keep );
		}
		$result = inet_ntop( $packed );
		return false === $result ? null : $result;
	}

	/**
	 * Redact credential-shaped fragments and bound UTF-8 without requiring mbstring.
	 *
	 * @param string $ua User agent.
	 * @param int    $limit Maximum bytes, capped at the key table's 255.
	 * @return string
	 */
	public static function user_agent( string $ua, int $limit = 120 ): string {
		$ua = substr( $ua, 0, 512 );
		$ua = preg_replace( '/xnv_[a-z2-7]{12}_[A-Za-z0-9_-]+|Bearer\s+[^\s]+/i', '[redacted]', $ua ) ?? '';
		$ua = preg_replace( '/[\x00-\x1f\x7f]/', '', $ua ) ?? '';
		$ua = substr( $ua, 0, max( 0, min( 255, $limit ) ) );
		while ( '' !== $ua && 1 !== preg_match( '//u', $ua ) ) {
			$ua = substr( $ua, 0, -1 );
		}
		return $ua;
	}
}
