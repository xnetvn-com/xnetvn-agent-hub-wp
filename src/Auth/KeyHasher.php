<?php
/**
 * Key hash and timing-safe verification.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

/** Implements the fixed HMAC-SHA256 credential contract. */
final class KeyHasher {

	/**
	 * Compute a hexadecimal HMAC without retaining credentials.
	 *
	 * @param string $secret Secret to authenticate.
	 * @param string $pepper Separate secret pepper.
	 * @return string
	 */
	public static function hash( #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $pepper ): string {
		return hash_hmac( 'sha256', $secret, $pepper );
	}

	/**
	 * Verify with a timing-safe comparison.
	 *
	 * @param string $secret Presented secret.
	 * @param string $pepper Separate pepper.
	 * @param string $stored_hash Stored HMAC.
	 * @return bool
	 */
	public static function verify( #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $pepper, #[\SensitiveParameter] string $stored_hash ): bool {
		return hash_equals( $stored_hash, self::hash( $secret, $pepper ) );
	}
}
