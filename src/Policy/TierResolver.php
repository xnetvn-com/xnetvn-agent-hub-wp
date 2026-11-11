<?php
/**
 * Conservative external metadata classification and risk-raising extensions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use Closure;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\RiskTier;

/** The private descriptor map wins; undeclared external abilities never imply admin. */
final class TierResolver {
	/** Tier extension.
	 *
	 * @var Closure(RiskTier,string):mixed
	 */
	private Closure $extension;
	/**
	 * Bind descriptor lookup and the raising-only hook.
	 *
	 * @param Closure      $lookup Descriptor lookup.
	 * @param Closure|null $extension Additional risk classification.
	 * @phpstan-param Closure(string):?AbilityDescriptor $lookup
	 * @phpstan-param Closure(RiskTier,string):mixed|null $extension
	 */
	public function __construct( private Closure $lookup, ?Closure $extension = null ) {
		$this->extension = $extension ?? static fn( RiskTier $tier, string $name ): mixed => apply_filters( 'xnetvn_agent_hub_wp_ability_tier', $tier, $name );
	}
	/**
	 * Resolve authoritative or conservative external risk.
	 *
	 * @param string $name Ability name.
	 * @return RiskTier
	 */
	public function tier_for( string $name ): RiskTier {
		$descriptor = ( $this->lookup )( $name );
		if ( null !== $descriptor ) {
			$tier = $descriptor->tier;
		} else {
			$ability = function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ? wp_get_ability( $name ) : null;
			$meta    = null === $ability ? array() : $ability->get_meta();
			$tier    = self::from_annotations( isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : null );
		}
		$changed = ( $this->extension )( $tier, $name );
		$changed = is_string( $changed ) ? RiskTier::tryFrom( $changed ) : $changed;
		return $changed instanceof RiskTier && $tier->atMost( $changed ) ? $changed : $tier;
	}
	/**
	 * Infer only read/write/destructive from explicit boolean annotations.
	 *
	 * @param array<string,mixed>|null $annotations External metadata.
	 * @return RiskTier
	 */
	public static function from_annotations( ?array $annotations ): RiskTier {
		if ( null === $annotations || ! $annotations || ( isset( $annotations['readonly'] ) && ! is_bool( $annotations['readonly'] ) ) || ( isset( $annotations['destructive'] ) && ! is_bool( $annotations['destructive'] ) ) ) {
			return RiskTier::Destructive;
		}
		if ( true === ( $annotations['destructive'] ?? false ) ) {
			return RiskTier::Destructive;
		}
		return true === ( $annotations['readonly'] ?? false ) ? RiskTier::Read : RiskTier::Write;
	}
}
