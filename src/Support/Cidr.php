<?php
/**
 * Pure-PHP IPv4/IPv6 network boundaries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Support;

/** Compares packed IP ranges without platform-dependent integer arithmetic. */
final class Cidr {

	/**
	 * Validate a CIDR address and prefix length.
	 *
	 * @param string $cidr CIDR value.
	 * @return bool
	 */
	public static function valid( string $cidr ): bool {
		return null !== self::range( $cidr );
	}

	/**
	 * Match an IP against a validated network range.
	 *
	 * @param string $cidr Network constraint.
	 * @param string $ip Candidate address.
	 * @return bool
	 */
	public static function contains( string $cidr, string $ip ): bool {
		$range  = self::range( $cidr );
		$packed = inet_pton( $ip );
		return null !== $range && false !== $packed && strlen( $packed ) === strlen( $range[0] ) && strcmp( $packed, $range[0] ) >= 0 && strcmp( $packed, $range[1] ) <= 0;
	}

	/**
	 * Prove a new CIDR is fully covered by the union of older constraints.
	 *
	 * @param string        $cidr Candidate network.
	 * @param array<string> $allowed Existing allowed networks.
	 * @return bool
	 */
	public static function covered_by( string $cidr, array $allowed ): bool {
		$candidate = self::range( $cidr );
		if ( null === $candidate ) {
			return false;
		}
		$ranges = array();
		foreach ( $allowed as $old ) {
			$range = self::range( $old );
			if ( null !== $range && strlen( $range[0] ) === strlen( $candidate[0] ) ) {
				$ranges[] = $range;
			}
		}
		usort( $ranges, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );
		$cursor = $candidate[0];
		foreach ( $ranges as $range ) {
			if ( strcmp( $range[1], $cursor ) < 0 ) {
				continue;
			}
			if ( strcmp( $range[0], $cursor ) > 0 ) {
				return false;
			}
			if ( strcmp( $range[1], $candidate[1] ) >= 0 ) {
				return true;
			}
			$cursor = self::increment( $range[1] );
		}
		return false;
	}

	/**
	 * Return unsigned network endpoints as equal-length binary strings.
	 *
	 * @param string $cidr CIDR value.
	 * @return array{string,string}|null
	 */
	private static function range( string $cidr ): ?array {
		if ( 1 !== preg_match( '/\A([^\/]+)\/(0|[1-9][0-9]{0,2})\z/', $cidr, $parts ) ) {
			return null;
		}
		$packed = inet_pton( $parts[1] );
		if ( false === $packed ) {
			return null;
		}
		$prefix = (int) $parts[2];
		$length = strlen( $packed );
		if ( $prefix > $length * 8 ) {
			return null;
		}
		$start = '';
		$end   = '';
		for ( $i = 0; $i < $length; ++$i ) {
			$bits   = max( 0, min( 8, $prefix - 8 * $i ) );
			$mask   = ( 255 << ( 8 - $bits ) ) & 255;
			$low    = ord( $packed[ $i ] ) & $mask;
			$start .= chr( $low );
			$end   .= chr( $low | ( ~ $mask & 255 ) );
		}
		return array( $start, $end );
	}

	/**
	 * Advance a packed endpoint by one address (caller excludes maximum endpoints).
	 *
	 * @param string $packed Packed address.
	 * @return string
	 */
	private static function increment( string $packed ): string {
		for ( $i = strlen( $packed ) - 1; $i >= 0; --$i ) {
			$byte = ord( $packed[ $i ] );
			if ( $byte < 255 ) {
				$packed[ $i ] = chr( $byte + 1 );
				break;
			}
			$packed[ $i ] = chr( 0 );
		}
		return $packed;
	}
}
