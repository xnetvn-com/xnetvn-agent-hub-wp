<?php
/**
 * Effective WordPress capability and delegated-scope intersection.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use Closure;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\RiskTier;

/** Extensions can only deny access after the complete base decision succeeds. */
final class Policy {
	/** Capability resolver.
	 *
	 * @var Closure(string,?AuthContext):bool
	 */
	private Closure $capability;
	/** Additional restriction hook.
	 *
	 * @var Closure(Decision,AbilityDescriptor,?AuthContext,string):mixed
	 */
	private Closure $restriction;
	/** Site setting values already read during this request.
	 *
	 * @var array<string,mixed>
	 */
	private array $site = array();
	/**
	 * Bind site policy and optional pure test seams.
	 *
	 * @param SiteSettings $settings Validated policy.
	 * @param Closure|null $capability Capability resolver.
	 * @param Closure|null $restriction Additional restriction.
	 * @phpstan-param Closure(string,?AuthContext):bool|null $capability
	 * @phpstan-param Closure(Decision,AbilityDescriptor,?AuthContext,string):mixed|null $restriction
	 */
	public function __construct( private SiteSettings $settings, ?Closure $capability = null, ?Closure $restriction = null ) {
		$this->capability  = $capability ?? static fn( string $cap, ?AuthContext $context ): bool => null === $context ? current_user_can( $cap ) : user_can( $context->user_id, $cap );
		$this->restriction = $restriction ?? static fn( Decision $decision, AbilityDescriptor $ability, ?AuthContext $context, string $transport ): mixed => apply_filters( 'xnetvn_agent_hub_wp_policy_decision', $decision, $ability, $context, $transport );
	}
	/**
	 * Evaluate every independent access term and any additional denial.
	 *
	 * @param AbilityDescriptor   $ability  Ability metadata.
	 * @param AuthContext|null    $context  Verified delegation or null for STDIO.
	 * @param string              $transport Explicit transport.
	 * @param CapabilityMemo|null $memo     Optional request-local capability memo.
	 * @return Decision
	 */
	public function evaluate( AbilityDescriptor $ability, ?AuthContext $context, string $transport, ?CapabilityMemo $memo = null ): Decision {
		try {
			if ( ! in_array( $transport, array( 'http', 'stdio' ), true ) || ( 'http' === $transport && null === $context ) ) {
				return Decision::deny( 'identity_required' );
			}
			if ( true !== $this->site( 'enabled' ) ) {
				return Decision::deny( 'site_disabled' );
			}
			$ceiling = $this->site( 'tier_ceiling' );
			$tier    = is_string( $ceiling ) ? RiskTier::tryFrom( $ceiling ) : null;
			if ( null === $tier || ! $ability->tier->atMost( $tier ) ) {
				return Decision::deny( 'site_tier' );
			}
			if ( RiskTier::Admin === $ability->tier && true !== $this->site( 'allow_admin_tier' ) ) {
				return Decision::deny( 'admin_disabled' );
			}
			if ( null !== $context ) {
				$scope = $context->key->scope;
				if ( ! $ability->tier->atMost( $scope->tier_max ) ) {
					return Decision::deny( 'key_tier' );
				}
				if ( ! in_array( $ability->toolset, $scope->toolsets, true ) ) {
					return Decision::deny( 'toolset_denied' );
				}
				if ( in_array( $ability->name, $scope->ability_deny, true ) || ( $scope->ability_allow && ! in_array( $ability->name, $scope->ability_allow, true ) ) ) {
					return Decision::deny( 'ability_denied' );
				}
			}
			if ( $ability->third_party ) {
				$approved = $this->site( 'exposed_third_party_abilities' );
				if ( ! is_array( $approved ) || ! in_array( $ability->name, $approved, true ) ) {
					return Decision::deny( 'approval_required' );
				}
			}
			if ( ! $this->permitted( $ability, $context, $memo ) ) {
				return Decision::deny( 'capability_denied' );
			}
			$base  = Decision::permit();
			$extra = ( $this->restriction )( $base, $ability, $context, $transport );
			if ( $extra instanceof Decision ) {
				return $extra->allowed ? $base : $extra;
			}
			return true === $extra ? $base : Decision::deny( 'extension_denied' );
		} catch ( \Throwable ) {
			return Decision::deny( 'policy_unavailable' );
		}
	}
	/**
	 * Check the visibility capability, reusing one request-local decision when a memo is given.
	 *
	 * Without a memo, or without a delegation, the capability is resolved live exactly as
	 * before. With a memo, the resolver of a decision always describes the same subject as
	 * the key of that decision, so a remembered value can never belong to another user.
	 *
	 * @param AbilityDescriptor   $ability  Ability metadata.
	 * @param AuthContext|null    $context  Verified delegation or null for STDIO.
	 * @param CapabilityMemo|null $memo     Optional request-local capability memo.
	 * @return bool
	 */
	private function permitted( AbilityDescriptor $ability, ?AuthContext $context, ?CapabilityMemo $memo ): bool {
		if ( null === $memo || null === $context ) {
			return ( $this->capability )( $ability->visibility_cap, $context );
		}
		$resolver = fn( int $user_id, string $capability, int $object_id ): bool => ( $this->capability )( $capability, $context ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The memo key fixes this resolver signature.
		return $memo->allows( $context->user_id, $ability->visibility_cap, 0, $resolver );
	}
	/**
	 * Read one site setting once per request and reuse the same value afterwards.
	 *
	 * @param string $name Setting name.
	 * @return mixed
	 */
	private function site( string $name ): mixed {
		if ( ! array_key_exists( $name, $this->site ) ) {
			$this->site[ $name ] = $this->settings->get( $name );
		}
		return $this->site[ $name ];
	}
}
