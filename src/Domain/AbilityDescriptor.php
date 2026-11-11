<?php
/**
 * Transport-independent ability authorization metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Captures immutable risk, pack and visibility requirements. */
final readonly class AbilityDescriptor {
	/**
	 * Bind authorization metadata.
	 *
	 * @param string   $name Canonical ability name.
	 * @param RiskTier $tier Declared risk.
	 * @param string   $toolset Pack identifier.
	 * @param string   $visibility_cap Required capability.
	 * @param bool     $third_party Whether explicit approval is required.
	 * @throws \InvalidArgumentException For malformed metadata.
	 */
	public function __construct( public string $name, public RiskTier $tier, public string $toolset, public string $visibility_cap, public bool $third_party = false ) {
		if ( 1 !== preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $name ) || '' === $visibility_cap ) {
			throw new \InvalidArgumentException( 'Invalid ability authorization metadata.' );
		}
		new Toolset( $toolset );
	}
}
