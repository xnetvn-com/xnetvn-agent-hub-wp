<?php
/**
 * Explicit policy outcome without request argument values.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Carries only whether access was allowed and a safe reason identifier. */
final readonly class Decision {

	/**
	 * Bind the outcome.
	 *
	 * @param bool   $allowed Whether all policy terms succeeded.
	 * @param string $reason Stable reason identifier.
	 */
	private function __construct( public bool $allowed, public string $reason ) {
	}

	/**
	 * Represent successful intersection of all restrictions.
	 *
	 * @return self
	 */
	public static function permit(): self {
		return new self( true, 'allowed' );
	}

	/**
	 * Represent denial without revealing input or secret values.
	 *
	 * @param string $reason Stable reason identifier.
	 * @return self
	 */
	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}
}
