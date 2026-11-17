<?php
/**
 * Persisted accountless website credential metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

use DateTimeImmutable;

/** Carries no WordPress user, secret, hash or pepper. */
final readonly class SiteCredential {

	/**
	 * Bind non-secret website credential metadata.
	 *
	 * @param int                    $id Database identity.
	 * @param KeyId                  $key_id Public lookup identity.
	 * @param int                    $blog_id Website identity.
	 * @param string                 $name User-visible name.
	 * @param SiteScope              $scope Machine restrictions.
	 * @param DateTimeImmutable      $created_at Creation time.
	 * @param DateTimeImmutable|null $revoked_at Revocation time.
	 * @param int|null               $created_by Manager attribution.
	 * @param int|null               $revoked_by Manager attribution.
	 * @param DateTimeImmutable|null $last_used_at Last authenticated use.
	 * @param string|null            $last_used_ip Privacy-masked last-use peer.
	 * @throws \InvalidArgumentException For invalid identities or names.
	 */
	public function __construct(
		public int $id,
		public KeyId $key_id,
		public int $blog_id,
		public string $name,
		public SiteScope $scope,
		public DateTimeImmutable $created_at,
		public ?DateTimeImmutable $revoked_at = null,
		public ?int $created_by = null,
		public ?int $revoked_by = null,
		public ?DateTimeImmutable $last_used_at = null,
		public ?string $last_used_ip = null
	) {
		if ( $id < 1 || $blog_id < 1 || '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid site credential metadata.' );
		}
		if ( ( null !== $created_by && $created_by < 1 ) || ( null !== $revoked_by && $revoked_by < 1 ) ) {
			throw new \InvalidArgumentException( 'Invalid site credential manager.' );
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
