<?php
/**
 * Validated extensible toolset identifier.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Validates syntax; registry availability is checked by the service layer. */
final readonly class Toolset {

	/** Canonical toolset name.
	 *
	 * @var string
	 */
	public string $value;

	/**
	 * Validate a toolset identifier.
	 *
	 * @param string $value Identifier.
	 * @throws \InvalidArgumentException For malformed identifiers.
	 */
	public function __construct( string $value ) {
		if ( 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,39}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid toolset.' );
		}
		$this->value = $value;
	}
}
