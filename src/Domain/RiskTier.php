<?php
/**
 * Ordered delegation risk tiers.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext, PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.SelfOutsideClassScopeFound -- PHPCompatibility 9 does not recognize enum scope; this file requires PHP 8.2.

/** A scope may lower, but never raise, the caller's effective authority. */
enum RiskTier: string {
	case Read        = 'read';
	case Write       = 'write';
	case Destructive = 'destructive';
	case Admin       = 'admin';

	/**
	 * Return the fixed ordering.
	 *
	 * @return int
	 */
	public function rank(): int {
		return match ( $this ) {
			self::Read => 0,
			self::Write => 1,
			self::Destructive => 2,
			self::Admin => 3,
		};
	}

	/**
	 * Check whether this tier fits beneath a ceiling.
	 *
	 * @param self $other Ceiling tier.
	 * @return bool
	 */
	public function atMost( self $other ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the specified RiskTier contract.
		return $this->rank() <= $other->rank();
	}
}
