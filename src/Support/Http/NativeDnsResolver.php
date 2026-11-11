<?php
/**
 * Native A and AAAA resolution.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

use XNetVN\AgentHubWP\Contracts\DnsResolver;
/** Returns an empty set on DNS errors, which the policy refuses. */
final class NativeDnsResolver implements DnsResolver {
	/**
	 * Resolve both address families together without following caller-provided IPs.
	 *
	 * @param string $host Host.
	 * @return array<string>
	 */
	public function addresses( string $host ): array {
		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( false === $records || count( $records ) > 16 ) {
			return array(); }
		$result = array();
		foreach ( $records as $record ) {
			$value = $record['ip'] ?? $record['ipv6'] ?? null;
			if ( ! is_string( $value ) ) {
				return array(); }
			$result[] = $value;
		}
		return $result;
	}
}
