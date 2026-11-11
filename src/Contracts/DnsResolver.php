<?php
/**
 * Complete bounded DNS answer contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

/** DNS failure never means a public address. */
interface DnsResolver {
	/**
	 * Resolve all A and AAAA addresses; caller validates every answer.
	 *
	 * @param string $host ASCII hostname.
	 * @return array<string>
	 */
	public function addresses( string $host ): array;
}
