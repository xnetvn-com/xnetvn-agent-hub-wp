<?php
/**
 * Safe persisted key identity and delegation metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

use DateTimeImmutable;

/** Carries no secret, hash, pepper or full token. */
final readonly class ApiKey {

	/**
	 * Bind non-secret key metadata.
	 *
	 * @param int                    $id Database identity.
	 * @param KeyId                  $key_id Public lookup identity.
	 * @param int                    $user_id Owner identity.
	 * @param string                 $name User-visible name.
	 * @param Scope                  $scope Delegation restrictions.
	 * @param DateTimeImmutable      $created_at Creation time.
	 * @param DateTimeImmutable|null $revoked_at Revocation time.
	 * @param DateTimeImmutable|null $last_used_at Last authenticated use.
	 * @param string|null            $last_used_ip Privacy-masked last-use peer.
	 * @throws \InvalidArgumentException For invalid identities or names.
	 */
	public function __construct(
		public int $id,
		public KeyId $key_id,
		public int $user_id,
		public string $name,
		public Scope $scope,
		public DateTimeImmutable $created_at,
		public ?DateTimeImmutable $revoked_at = null,
		public ?DateTimeImmutable $last_used_at = null,
		public ?string $last_used_ip = null
	) {
		if ( $id < 1 || $user_id < 1 || '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid key metadata.' );
		}
	}

	/**
	 * Check the explicit revocation state.
	 *
	 * @return bool
	 */
	public function is_revoked(): bool {
		return null !== $this->revoked_at;
	}
}
