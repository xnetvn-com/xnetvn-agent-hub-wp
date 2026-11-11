<?php
/**
 * Cryptographically secure key generation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;

/** Creates independent public identifiers and 256-bit secrets. */
final class KeyGenerator {

	/**
	 * Generate a new single-disclosure credential.
	 *
	 * @return GeneratedKey
	 */
	public function generate(): GeneratedKey {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$id       = '';
		foreach ( str_split( random_bytes( 12 ) ) as $byte ) {
			$id .= $alphabet[ ord( $byte ) & 31 ];
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode random credential bytes, never executable code.
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		return new GeneratedKey( new KeyId( $id ), new KeySecret( $secret ) );
	}
}
