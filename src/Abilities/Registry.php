<?php
/**
 * Authoritative private definitions and guarded WordPress ability callbacks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\AbilityPack;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteRequestContext;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Policy\CapabilityMemo;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Policy\TierResolver;

/** All definition execution paths preserve policy, actor binding and output validation. */
final class Registry implements AbilityCatalog, ServiceProvider {
	/** Reviewed private definition map.
	 *
	 * @var array<string,AbilitySpec>
	 */
	private array $definitions = array();
	/** MCP-name to canonical-name map.
	 *
	 * @var array<string,string>
	 */
	private array $tools = array();
	/** Deferred available packs.
	 *
	 * @var array<AbilityPack>
	 */
	private array $packs = array();
	/** Bound core callback identities.
	 *
	 * @var array<string,\WP_Ability>
	 */
	private array $bound = array();
	/** Conflicted definition names, closed to all discovery/execution.
	 *
	 * @var array<string,bool>
	 */
	private array $blocked = array();
	/** Risk resolver tied to the authoritative map.
	 *
	 * @var TierResolver
	 */
	private TierResolver $tiers;
	/** Static reviewed-operation boundary.
	 *
	 * @var SafeMcpEnvelope
	 */
	private SafeMcpEnvelope $envelope;
	/**
	 * Bind policy, safe request identity and output enforcement.
	 *
	 * @param Policy                                            $policy Policy.
	 * @param RequestContext                                    $request Request identity.
	 * @param OutputPipeline                                    $output Logical output pipeline.
	 * @param \XNetVN\AgentHubWP\Contracts\ExpensiveBudget|null $expensive Atomic work budget.
	 * @param SafeMcpEnvelope|null                              $envelope Static reviewed-operation boundary.
	 * @param SitePolicy|null                                   $site_policy Machine policy.
	 * @param SiteDispatcher|null                               $site_dispatcher Machine dispatcher.
	 */
	public function __construct( private Policy $policy, private RequestContext $request, private OutputPipeline $output, private ?\XNetVN\AgentHubWP\Contracts\ExpensiveBudget $expensive = null, ?SafeMcpEnvelope $envelope = null, private ?SitePolicy $site_policy = null, private ?SiteDispatcher $site_dispatcher = null ) {
		$this->tiers    = new TierResolver( fn( string $name ): ?AbilityDescriptor => $this->get( $name )?->descriptor() );
		$this->envelope = $envelope ?? new SafeMcpEnvelope();
	}
	/**
	 * Build the effective descriptor with the resolved tier.
	 *
	 * @param string $name Canonical name.
	 * @return AbilityDescriptor|null
	 */
	public function resolved_descriptor( string $name ): ?AbilityDescriptor {
		$definition = $this->get( $name );
		if ( null === $definition ) {
			return null;
		}
		$declared = $definition->descriptor();
		return new AbilityDescriptor( $declared->name, $this->tiers->tier_for( $declared->name ), $declared->toolset, $declared->visibility_cap, $declared->third_party );
	}
	/**
	 * Detect an impossible dual-principal state.
	 *
	 * @return bool
	 */
	private function conflicted(): bool {
		return $this->request instanceof SiteRequestContext && $this->request->has_conflicting_principals();
	}
	/**
	 * Read the verified site principal when present.
	 *
	 * @return SiteAuthContext|null
	 */
	private function site_principal(): ?SiteAuthContext {
		return $this->request instanceof SiteRequestContext ? $this->request->site_current() : null;
	}
	/**
	 * List names that have a machine handler and pass the static scope.
	 *
	 * @param SiteScope $scope Machine restrictions.
	 * @return array<string>
	 */
	public function names_for_site_scope( SiteScope $scope ): array {
		if ( null === $this->site_policy || null === $this->site_dispatcher ) {
			return array();
		}
		$names = array();
		foreach ( $this->names() as $name ) {
			if ( ! $this->site_dispatcher->has_handler( $name ) ) {
				continue;
			}
			$descriptor = $this->resolved_descriptor( $name );
			if ( null === $descriptor ) {
				continue;
			}
			if ( ! $this->site_policy->evaluate_scope( $descriptor, $scope )->allowed ) {
				continue;
			}
			$names[] = $name;
		}
		return $names;
	}
	/**
	 * Authorize one site definition through the machine policy.
	 *
	 * @param AbilitySpec     $definition Definition.
	 * @param SiteAuthContext $site Verified site identity.
	 * @return bool
	 */
	private function site_allowed( AbilitySpec $definition, SiteAuthContext $site ): bool {
		if ( null === $this->site_policy || null === $this->site_dispatcher ) {
			return false;
		}
		$name = $definition->descriptor()->name;
		if ( ! $this->site_dispatcher->has_handler( $name ) ) {
			return false;
		}
		if ( isset( $this->bound[ $name ] ) && wp_get_ability( $name ) !== $this->bound[ $name ] ) {
			return false;
		}
		$descriptor = $this->resolved_descriptor( $name );
		if ( null === $descriptor ) {
			return false;
		}
		return $this->site_policy->evaluate( $descriptor, $site, 'http' )->allowed;
	}

	/**
	 * Add an immutable definition, rejecting duplicate names and wire mappings.
	 *
	 * @param AbilitySpec $definition Reviewed definition.
	 * @throws \InvalidArgumentException For ambiguous identity.
	 */
	public function add( AbilitySpec $definition ): void {
		$name = $definition->descriptor()->name;
		$tool = $definition->tool_name();
		if ( isset( $this->definitions[ $name ], $this->tools[ $tool ] ) || isset( $this->definitions[ $name ] ) || isset( $this->tools[ $tool ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal developer error with fixed prefixes.
			throw new \InvalidArgumentException( 'Duplicate ability or tool identity: ' . $name . ' / ' . $tool );
		}
		$this->definitions[ $name ] = $definition;
		$this->tools[ $tool ]       = $name;
	}
	/**
	 * Add an available pack through its contract only.
	 *
	 * @param AbilityPack $pack Pack.
	 */
	public function add_pack( AbilityPack $pack ): void {
		$this->packs[] = $pack;
	}
	/** Build pack labels and definitions only after WordPress init starts. */
	public function load_packs(): void {
		if ( ! did_action( 'init' ) ) {
			return;
		}
		$packs       = $this->packs;
		$this->packs = array();
		foreach ( $packs as $pack ) {
			if ( $pack->is_available() ) {
				foreach ( $pack->definitions() as $definition ) {
					$this->add( $definition );
				}
			}
		}
	}
	/** Register before core's lazy category and ability initialization. */
	public function register(): void {
		add_action( 'init', array( $this, 'load_packs' ), 0 );
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}
	/** Remove only this registry's temporary hooks in isolated tests. */
	public function unregister_hooks(): void {
		remove_action( 'init', array( $this, 'load_packs' ), 0 );
		remove_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		remove_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}
	/** Register one stable private-pack category for each toolset. */
	public function register_categories(): void {
		$this->load_packs();
		foreach ( $this->definitions as $definition ) {
			$slug = 'xnetvn-' . $definition->descriptor()->toolset;
			if ( ! wp_has_ability_category( $slug ) ) {
				wp_register_ability_category(
					$slug,
					array(
						'label'       => __( 'Agent Hub tools', 'xnetvn-agent-hub-wp' ),
						'description' => __( 'Private scoped WordPress management capabilities.', 'xnetvn-agent-hub-wp' ),
					)
				);
			}
		}
	}
	/** Register private callbacks without public or show_in_rest exposure arguments. */
	public function register_abilities(): void {
		$this->load_packs();
		foreach ( $this->definitions as $name => $definition ) {
			if ( '' === $name || '0' === $name ) {
				continue;
			}
			if ( wp_has_ability( $name ) ) {
				if ( ! isset( $this->bound[ $name ] ) || wp_get_ability( $name ) !== $this->bound[ $name ] ) {
					$this->blocked[ $name ] = true;
				}
				continue;
			}
			$descriptor = $definition->descriptor();
			$registered = wp_register_ability(
				strtolower( $name ),
				array(
					'label'               => $definition->label(),
					'description'         => $definition->description(),
					'category'            => 'xnetvn-' . $descriptor->toolset,
					'input_schema'        => $definition->input_schema(),
					'output_schema'       => $definition->output_schema(),
					'permission_callback' => fn(): bool => $this->allowed( $definition ),
					'execute_callback'    => fn( mixed $arguments ): mixed => $this->invoke( $definition, $arguments ),
					'meta'                => array(
						'annotations' => $definition->annotations(),
						'xnetvn'      => array(
							'tier'           => $descriptor->tier->value,
							'toolset'        => $descriptor->toolset,
							'visibility_cap' => $descriptor->visibility_cap,
						),
					),
				)
			);
			if ( null !== $registered && false === ( $registered->get_meta()['public'] ?? false ) && false === ( $registered->get_meta()['show_in_rest'] ?? false ) ) {
				$this->bound[ $name ] = $registered;
			} else {
				$this->blocked[ $name ] = true;
				if ( null !== $registered ) {
					wp_unregister_ability( $name );
				}
			}
		}
		$this->site_dispatcher?->freeze();
	}
	/**
	 * Read only from the authoritative map.
	 *
	 * @param string $name Canonical name.
	 * @return AbilitySpec|null
	 */
	public function get( string $name ): ?AbilitySpec {
		return $this->definitions[ $name ] ?? null;
	}
	/**
	 * Resolve an exact wire name without guessing names or namespace prefixes.
	 *
	 * @param string $name MCP tool name.
	 * @return AbilitySpec|null
	 */
	public function for_tool( string $name ): ?AbilitySpec {
		return isset( $this->tools[ $name ] ) ? $this->get( $this->tools[ $name ] ) : null;
	}
	/**
	 * List authoritative canonical names in deterministic order.
	 *
	 * @return array<string>
	 */
	public function names(): array {
		$this->load_packs();
		$names = array_keys( array_diff_key( $this->definitions, $this->blocked ) );
		sort( $names, SORT_STRING );
		return $names;
	}
	/**
	 * List only the names a delegation scope can never use.
	 *
	 * The returned set is a superset of every decision {@see allowed()} can make, so it
	 * only removes names the same scope terms already deny. It never grants access.
	 *
	 * @param Scope $scope Delegation restrictions.
	 * @return array<string>
	 */
	public function names_for_scope( Scope $scope ): array {
		$names = array();
		foreach ( $this->names() as $name ) {
			$definition = $this->definitions[ $name ] ?? null;
			if ( null === $definition ) {
				continue;
			}
			if ( ! $this->envelope->evaluate( $name, SafeMcpEnvelope::PRINCIPAL_USER )->allowed ) {
				continue;
			}
			$descriptor = $definition->descriptor();
			if ( ! $descriptor->tier->atMost( $scope->tier_max ) ) {
				continue;
			}
			if ( ! in_array( $descriptor->toolset, $scope->toolsets, true ) ) {
				continue;
			}
			if ( in_array( $name, $scope->ability_deny, true ) ) {
				continue;
			}
			if ( $scope->ability_allow && ! in_array( $name, $scope->ability_allow, true ) ) {
				continue;
			}
			$names[] = $name;
		}
		return $names;
	}
	/**
	 * Discover only currently permitted definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function visible(): array {
		if ( $this->conflicted() ) {
			return array();
		}
		$visible = array();
		foreach ( $this->names() as $name ) {
			$definition = $this->definitions[ $name ];
			if ( $this->allowed( $definition ) ) {
				$visible[] = $definition;
			}
		}
		return $visible;
	}
	/**
	 * Read reviewed metadata without granting execution permission.
	 *
	 * @return array<AbilitySpec>
	 */
	public function all(): array {
		return array_map( fn( string $name ): AbilitySpec => $this->definitions[ $name ], $this->names() );
	}
	/**
	 * Execute only an authorized mapped core ability.
	 *
	 * @param string              $name Canonical name.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed {
		if ( $this->conflicted() ) {
			return $this->denied();
		}
		$site       = $this->site_principal();
		$definition = $this->get( $name );
		if ( null === $definition || ! $this->allowed( $definition ) || ! wp_has_ability( $name ) ) {
			return $this->denied();
		}
		if ( null !== $site ) {
			if ( null === $this->site_dispatcher ) {
				return $this->denied();
			}
			do_action( 'xnetvn_agent_hub_wp_tool_started', $name, array_keys( $arguments ) );
			try {
				$result = $this->site_dispatcher->execute( $name, $arguments, $site );
			} catch ( \Throwable ) {
				$result = ReasonMapper::error( 'internal_error' );
			}
			do_action( 'xnetvn_agent_hub_wp_tool_finished', $name, is_wp_error( $result ) ? 'error' : 'success' );
			return $result;
		}
		$ability = wp_get_ability( $name );
		if ( null === $ability || ( $this->bound[ $name ] ?? null ) !== $ability ) {
			return $this->denied();
		}
		// Explicit catalog execution is a meta-tool target; direct adapter calls use the facade events.
		do_action( 'xnetvn_agent_hub_wp_tool_started', $name, array_keys( $arguments ) );
		try {
			$result = $ability->execute( $arguments );
		} catch ( \Throwable ) {
			$result = ReasonMapper::error( 'internal_error' );
		}
		do_action( 'xnetvn_agent_hub_wp_tool_finished', $name, is_wp_error( $result ) ? 'error' : 'success' );
		return $result;
	}
	/**
	 * Authorize current risk and actor binding through the same policy.
	 *
	 * The optional memo is only used by one advertised tool list round. Every other
	 * decision path calls this method without a memo and stays live.
	 *
	 * @param AbilitySpec         $definition Definition.
	 * @param CapabilityMemo|null $memo      Optional request-local capability memo.
	 * @return bool
	 */
	public function allowed( AbilitySpec $definition, ?CapabilityMemo $memo = null ): bool {
		if ( isset( $this->blocked[ $definition->descriptor()->name ] ) ) {
			return false;
		}
		if ( $this->conflicted() ) {
			return false;
		}
		$site = $this->site_principal();
		if ( null !== $site ) {
			return $this->site_allowed( $definition, $site );
		}
		if ( ! $this->envelope->evaluate( $definition->descriptor()->name, SafeMcpEnvelope::PRINCIPAL_USER )->allowed ) {
			return false;
		}
		$name = $definition->descriptor()->name;
		if ( isset( $this->bound[ $name ] ) && wp_get_ability( $name ) !== $this->bound[ $name ] ) {
			return false;
		}
		$context   = $this->request->current();
		$transport = $this->request->transport();
		if ( 'http' === $transport && ( null === $context || get_current_user_id() !== $context->user_id ) ) {
			return false;
		}
		$declared  = $definition->descriptor();
		$effective = new AbilityDescriptor( $declared->name, $this->tiers->tier_for( $declared->name ), $declared->toolset, $declared->visibility_cap, $declared->third_party );
		return $this->policy->evaluate( $effective, $context, $transport, $memo )->allowed;
	}
	/**
	 * Recheck policy before logical execution and enforce final output.
	 *
	 * @param AbilitySpec $definition Definition.
	 * @param mixed       $arguments Core-normalized arguments.
	 * @return mixed
	 */
	private function invoke( AbilitySpec $definition, mixed $arguments ): mixed {
		if ( $this->conflicted() || ! $this->allowed( $definition ) || ! is_array( $arguments ) ) {
			return $this->denied();
		}
		$site = $this->site_principal();
		if ( null !== $site ) {
			if ( null === $this->site_dispatcher || null === $this->site_policy ) {
				return $this->denied();
			}
			return $this->site_dispatcher->execute( $definition->descriptor()->name, $arguments, $site );
		}
		try {
			$work = fn(): mixed => $definition->execute( $arguments );
			if ( $definition->costly() && null !== $this->expensive ) {
				$identity = $this->request->current()?->key->key_id->value ?? 'stdio-' . get_current_user_id();
				$result   = $this->expensive->run( $identity, $work );
			} else {
				$result = $work();
			}
			return $this->output->process( $result, $definition->output_schema() );
		} catch ( \Throwable ) {
			return ReasonMapper::error( 'internal_error' );
		}
	}
	/**
	 * Deny hidden/unknown tools without revealing which one exists.
	 *
	 * @return WP_Error
	 */
	private function denied(): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
	}
}
