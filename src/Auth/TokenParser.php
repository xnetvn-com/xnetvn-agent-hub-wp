<?php
/**
 * Fixed-length credential syntax gate.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;

/** Rejects malformed credentials before any database work. */
final class TokenParser {

	/**
	 * Parse only the exact credential wire format.
	 *
	 * @param string $token Presented credential.
	 * @return ParsedToken|null
	 */
	public static function parse( #[\SensitiveParameter] string $token ): ?ParsedToken {
		if ( 60 !== strlen( $token ) || 1 !== preg_match( '/\Axnv_([a-z2-7]{12})_([A-Za-z0-9_-]{43})\z/', $token, $parts ) ) {
			return null;
		}
		return new ParsedToken( new KeyId( $parts[1] ), new KeySecret( $parts[2] ) );
	}
}
