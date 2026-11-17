<?php
/**
 * Accountless site credential generation and syntax gate.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;

/** Never parses or emits the human xnv_ credential shape. */
final class SiteTokenCodec {

	/** Fixed accountless site prefix. */
	public const PREFIX = 'xns_';

	/**
	 * Generate a new single-disclosure site credential.
	 *
	 * @return GeneratedSiteKey
	 */
	public function generate(): GeneratedSiteKey {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$id       = '';
		foreach ( str_split( random_bytes( 12 ) ) as $byte ) {
			$id .= $alphabet[ ord( $byte ) & 31 ];
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode random credential bytes, never executable code.
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		return new GeneratedSiteKey( new KeyId( $id ), new KeySecret( $secret ) );
	}

	/**
	 * Parse only the exact accountless site wire format.
	 *
	 * @param string $token Presented credential.
	 * @return ParsedToken|null
	 */
	public static function parse( #[\SensitiveParameter] string $token ): ?ParsedToken {
		if ( 60 !== strlen( $token ) || 1 !== preg_match( '/\Axns_([a-z2-7]{12})_([A-Za-z0-9_-]{43})\z/', $token, $parts ) ) {
			return null;
		}
		return new ParsedToken( new KeyId( $parts[1] ), new KeySecret( $parts[2] ) );
	}
}
