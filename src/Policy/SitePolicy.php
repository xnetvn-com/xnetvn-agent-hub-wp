<?php
/**
 * Accountless site policy terms without a WordPress user.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** Evaluates the reviewed machine terms; extensions may only deny. */
final class SitePolicy {

	/** Static envelope.
	 *
	 * @var SafeMcpEnvelope
	 */
	private SafeMcpEnvelope $envelope;

	/**
	 * Bind site settings and an optional static envelope.
	 *
	 * @param SiteSettings         $settings Current site policy.
	 * @param SafeMcpEnvelope|null $envelope Static reviewed operation boundary.
	 */
	public function __construct( private SiteSettings $settings, ?SafeMcpEnvelope $envelope = null ) {
		$this->envelope = $envelope ?? new SafeMcpEnvelope();
	}

	/**
	 * Decide one machine operation with the typed site principal.
	 *
	 * @param AbilityDescriptor $ability Resolved descriptor.
	 * @param SiteAuthContext   $context Verified site identity.
	 * @param string            $transport Actual transport.
	 * @return Decision
	 */
	public function evaluate( AbilityDescriptor $ability, SiteAuthContext $context, string $transport ): Decision {
		try {
			if ( 'http' !== $transport ) {
				return Decision::deny( 'identity_required' );
			}
			if ( $context->credential->is_revoked() ) {
				return Decision::deny( 'credential_revoked' );
			}
			$decision = $this->evaluate_scope( $ability, $context->credential->scope );
			if ( ! $decision->allowed ) {
				return $decision;
			}
			if ( 'xnetvn/set-user-status' === $ability->name
				&& ! $context->has_permission( SiteScope::PERMISSION_BAN_USERS )
				&& ! $context->has_permission( SiteScope::PERMISSION_UNBAN_USERS ) ) {
				return Decision::deny( 'permission_required' );
			}
			$extra = apply_filters( 'xnetvn_agent_hub_wp_site_policy_decision', Decision::permit(), $ability, $context, $transport );
			if ( $extra instanceof Decision ) {
				return $extra->allowed ? Decision::permit() : $extra;
			}
			return true === $extra ? Decision::permit() : Decision::deny( 'extension_denied' );
		} catch ( \Throwable ) {
			return Decision::deny( 'policy_unavailable' );
		}
	}

	/**
	 * Evaluate the static scope terms without a bound request.
	 *
	 * @param AbilityDescriptor $ability Resolved descriptor.
	 * @param SiteScope         $scope Machine restrictions.
	 * @return Decision
	 */
	public function evaluate_scope( AbilityDescriptor $ability, SiteScope $scope ): Decision {
		try {
			$safe = $this->envelope->evaluate( $ability->name, SafeMcpEnvelope::PRINCIPAL_SITE );
			if ( ! $safe->allowed ) {
				return $safe;
			}
			if ( SiteScope::PROFILE !== $scope->profile ) {
				return Decision::deny( 'profile_denied' );
			}
			if ( ! in_array( $ability->toolset, $scope->toolsets, true ) ) {
				return Decision::deny( 'toolset_denied' );
			}
			if ( in_array( $ability->name, $scope->denied_abilities, true ) || ! in_array( $ability->name, $scope->allowed_abilities, true ) ) {
				return Decision::deny( 'ability_denied' );
			}
			if ( true !== $this->settings->get( 'enabled' ) ) {
				return Decision::deny( 'site_disabled' );
			}
			$ceiling   = $this->settings->get( 'tier_ceiling' );
			$site_tier = is_string( $ceiling ) ? RiskTier::tryFrom( $ceiling ) : null;
			if ( null === $site_tier || ! $ability->tier->atMost( $site_tier ) ) {
				return Decision::deny( 'site_tier' );
			}
			if ( RiskTier::Admin === $ability->tier && true !== $this->settings->get( 'allow_admin_tier' ) ) {
				return Decision::deny( 'admin_disabled' );
			}
			if ( ! $ability->tier->atMost( $scope->tier_ceiling ) ) {
				return Decision::deny( 'key_tier' );
			}
			if ( $ability->third_party ) {
				$approved = $this->settings->get( 'exposed_third_party_abilities' );
				if ( ! is_array( $approved ) || ! in_array( $ability->name, $approved, true ) ) {
					return Decision::deny( 'approval_required' );
				}
			}
			return Decision::permit();
		} catch ( \Throwable ) {
			return Decision::deny( 'policy_unavailable' );
		}
	}
}
