<?php
/**
 * Public key lookup identifier.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Validates a non-secret, lowercase base32 identifier. */
final readonly class KeyId {

	/** Identifier value.
	 *
	 * @var string
	 */
	public string $value;

	/**
	 * Validate the public identifier.
	 *
	 * @param string $value Public identifier.
	 * @throws \InvalidArgumentException For an invalid identifier.
	 */
	public function __construct( string $value ) {
		if ( 1 !== preg_match( '/\A[a-z2-7]{12}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid key identifier.' );
		}
		$this->value = $value;
	}
}
