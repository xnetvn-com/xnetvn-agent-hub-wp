<?php
/**
 * Opaque stored site credential verifier.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\SiteCredential;

/** Keeps the stored HMAC out of object exports, JSON surfaces and log output. */
final class StoredSiteKey {

	/** Credential material outside exportable object properties.
	 *
	 * @var \WeakMap<self,string>|null
	 */
	private static ?\WeakMap $hashes = null;

	/**
	 * Bind safe website metadata to opaque verification data.
	 *
	 * @param SiteCredential $key Safe website credential metadata.
	 * @param string         $hash Stored HMAC.
	 * @param int            $pepper_id Pepper version.
	 * @throws \InvalidArgumentException For invalid credential metadata.
	 */
	public function __construct( public readonly SiteCredential $key, #[\SensitiveParameter] string $hash, public readonly int $pepper_id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) || $pepper_id < 1 || $pepper_id > 255 ) {
			throw new \InvalidArgumentException( 'Invalid site credential metadata.' );
		}
		self::$hashes        ??= new \WeakMap();
		self::$hashes[ $this ] = $hash;
	}

	/**
	 * Compare a presented credential without exposing the stored hash.
	 *
	 * @param string $secret Presented secret.
	 * @param Pepper $pepper Separate pepper.
	 * @return bool
	 */
	public function verify_secret( #[\SensitiveParameter] string $secret, #[\SensitiveParameter] Pepper $pepper ): bool {
		return null !== self::$hashes && isset( self::$hashes[ $this ] ) && KeyHasher::verify( $secret, $pepper->reveal(), self::$hashes[ $this ] );
	}

	/**
	 * Return safe debugging metadata.
	 *
	 * @return array{key:SiteCredential,pepper_id:int}
	 */
	public function __debugInfo(): array {
		return array(
			'key'       => $this->key,
			'pepper_id' => $this->pepper_id,
		);
	}

	/**
	 * Serialize only non-secret metadata.
	 *
	 * @return array{key:SiteCredential,pepper_id:int}
	 */
	public function __serialize(): array {
		return array(
			'key'       => $this->key,
			'pepper_id' => $this->pepper_id,
		);
	}
}
