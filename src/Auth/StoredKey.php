<?php
/**
 * Opaque stored credential verifier.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\ApiKey;
/** Keeps the stored HMAC out of object exports, API-key DTOs and log surfaces. */
final class StoredKey {
	/** Credential material outside exportable object properties.
	 *
	 * @var \WeakMap<self,string>|null
	 */
	private static ?\WeakMap $hashes = null;
	/**
	 * Bind safe identity to opaque verification data.
	 *
	 * @param ApiKey $key Safe key metadata.
	 * @param string $hash Stored HMAC.
	 * @param int    $pepper_id Pepper version.
	 * @throws \InvalidArgumentException For invalid credential metadata.
	 */
	public function __construct( public readonly ApiKey $key, #[\SensitiveParameter] string $hash, public readonly int $pepper_id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) || $pepper_id < 1 || $pepper_id > 255 ) {
			throw new \InvalidArgumentException( 'Invalid credential metadata.' );
		}
		self::$hashes        ??= new \WeakMap();
		self::$hashes[ $this ] = $hash;
	}
	/**
	 * Compare a presented credential without exposing the stored hash.
	 *
	 * @param string $secret Presented secret.
	 * @param string $pepper Separate pepper.
	 * @return bool
	 */
	public function verify_secret( #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $pepper ): bool {
		return null !== self::$hashes && isset( self::$hashes[ $this ] ) && KeyHasher::verify( $secret, $pepper, self::$hashes[ $this ] );
	}
}
