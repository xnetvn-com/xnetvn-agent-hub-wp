<?php
/**
 * One-time credential disclosure.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;

/** Stores only an opaque secret and permits one full-token disclosure. */
final class GeneratedKey {

	/** Public lookup identifier.
	 *
	 * @var KeyId
	 */
	public readonly KeyId $id;

	/** Opaque credential value.
	 *
	 * @var KeySecret
	 */
	private readonly KeySecret $secret;

	/** Whether the disclosure has been consumed.
	 *
	 * @var bool
	 */
	private bool $revealed = false;

	/**
	 * Bind the newly generated identifier and secret.
	 *
	 * @param KeyId     $id Public identifier.
	 * @param KeySecret $secret Opaque secret.
	 */
	public function __construct( KeyId $id, KeySecret $secret ) {
		$this->id     = $id;
		$this->secret = $secret;
	}

	/**
	 * Return the complete credential once.
	 *
	 * @return string
	 * @throws \LogicException If disclosure was already consumed.
	 */
	public function reveal(): string {
		if ( $this->revealed ) {
			throw new \LogicException( 'Credential was already disclosed.' );
		}
		$this->revealed = true;
		return 'xnv_' . $this->id->value . '_' . $this->secret->reveal();
	}

	/**
	 * Hash the opaque secret without consuming its one-time disclosure.
	 *
	 * @param Pepper $pepper Separate random pepper.
	 * @return string
	 */
	public function hash_with( Pepper $pepper ): string {
		return KeyHasher::hash( $this->secret->reveal(), $pepper->reveal() );
	}

	/** Prevent a second credential disclosure budget through cloning. */
	private function __clone(): void {
	}
}
