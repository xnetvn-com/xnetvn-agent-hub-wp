<?php
/**
 * Immutable delegation restrictions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

use DateTimeImmutable;
use XNetVN\AgentHubWP\Support\Cidr;

/** Scope edits may only reduce the set of permitted operations. */
final readonly class Scope {

	/** Maximum risk.
	 *
	 * @var RiskTier
	 */
	public RiskTier $tier_max;
	/** Enabled toolsets including system.
	 *
	 * @var array<string>
	 */
	public array $toolsets;
	/** Optional explicit ability allowlist.
	 *
	 * @var array<string>
	 */
	public array $ability_allow;
	/** Ability denylist.
	 *
	 * @var array<string>
	 */
	public array $ability_deny;
	/** Allowed IP networks; empty means unrestricted.
	 *
	 * @var array<string>
	 */
	public array $ip_allowlist;
	/** Expiry in UTC, or no expiry.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $expires_at;

	/**
	 * Validate and normalize delegation syntax.
	 *
	 * @param RiskTier               $tier_max Maximum tier.
	 * @param array<string>          $toolsets Enabled packs.
	 * @param array<string>          $ability_allow Explicit allowed abilities.
	 * @param array<string>          $ability_deny Denied abilities.
	 * @param array<string>          $ip_allowlist IP network constraints.
	 * @param DateTimeImmutable|null $expires_at Expiry.
	 * @throws \InvalidArgumentException For invalid scope syntax.
	 */
	public function __construct( RiskTier $tier_max, array $toolsets = array(), array $ability_allow = array(), array $ability_deny = array(), array $ip_allowlist = array(), ?DateTimeImmutable $expires_at = null ) {
		foreach ( $toolsets as $toolset ) {
			new Toolset( $toolset );
		}
		foreach ( array_merge( $ability_allow, $ability_deny ) as $ability ) {
			if ( 1 !== preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $ability ) ) {
				throw new \InvalidArgumentException( 'Invalid ability scope.' );
			}
		}
		foreach ( $ip_allowlist as $cidr ) {
			if ( ! Cidr::valid( $cidr ) ) {
				throw new \InvalidArgumentException( 'Invalid IP scope.' );
			}
		}
		$this->tier_max      = $tier_max;
		$this->toolsets      = self::normalized( array_merge( $toolsets, array( 'system' ) ) );
		$this->ability_allow = self::normalized( $ability_allow );
		$this->ability_deny  = self::normalized( $ability_deny );
		$this->ip_allowlist  = self::normalized( $ip_allowlist );
		$this->expires_at    = $expires_at?->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Determine whether an update can only narrow the previous scope.
	 *
	 * @param self $old Previous scope.
	 * @return bool
	 */
	public function is_narrower_than( self $old ): bool {
		if ( ! $this->tier_max->atMost( $old->tier_max ) || array_diff( $this->toolsets, $old->toolsets ) || array_diff( $old->ability_deny, $this->ability_deny ) ) {
			return false;
		}
		if ( $old->ability_allow && ( ! $this->ability_allow || array_diff( $this->ability_allow, $old->ability_allow ) ) ) {
			return false;
		}
		if ( null !== $old->expires_at && ( null === $this->expires_at || $this->expires_at > $old->expires_at ) ) {
			return false;
		}
		if ( $old->ip_allowlist ) {
			if ( ! $this->ip_allowlist ) {
				return false;
			}
			foreach ( $this->ip_allowlist as $cidr ) {
				if ( ! Cidr::covered_by( $cidr, $old->ip_allowlist ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Return unique, sorted scope elements.
	 *
	 * @param array<string> $values Scope elements.
	 * @return array<string>
	 */
	private static function normalized( array $values ): array {
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}
}
