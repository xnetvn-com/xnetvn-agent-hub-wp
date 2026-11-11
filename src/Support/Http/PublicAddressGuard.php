<?php
/**
 * Public unicast address policy, independent of core HTTP filters.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

use XNetVN\AgentHubWP\Support\Cidr;

/** Rejects mapped and transitional IPv6 forms rather than guessing embedded IPs. */
final class PublicAddressGuard {
	/** Prohibited networks for remote fetches. */
	private const DENIED = array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8' );
	/**
	 * Decide only canonical IPv4/IPv6 literals returned by the DNS resolver.
	 *
	 * @param string $ip DNS address.
	 * @return bool
	 */
	public static function allows( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false; }
		foreach ( self::DENIED as $network ) {
			if ( Cidr::contains( $network, $ip ) ) {
				return false; }
		}
		return true;
	}
}
