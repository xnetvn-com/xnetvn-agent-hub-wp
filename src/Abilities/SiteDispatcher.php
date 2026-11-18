<?php
/**
 * Registered machine handlers with policy, schema and budget enforcement.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use WP_Error;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Policy\SitePolicy;

/** Never falls back to a human closure, a user, or an unregistered handler. */
final class SiteDispatcher {

	/** Fixed, code-owned built-in author lookup. */
	private const EDITORIAL_AUTHOR_LOOKUP = 'xnetvn/list-content-authors';

	/** Bound authoritative catalog.
	 *
	 * @var AbilityCatalog|null
	 */
	private ?AbilityCatalog $catalog = null;

	/** Server-owned resolved-descriptor callable.
	 *
	 * @var Closure(string):?AbilityDescriptor|null
	 */
	private ?Closure $resolved_descriptor = null;

	/** Registered machine handlers.
	 *
	 * @var array<string,Closure(array<string,mixed>,SiteAuthContext):mixed>
	 */
	private array $handlers = array();

	/** Whether registration is closed.
	 *
	 * @var bool
	 */
	private bool $frozen = false;

	/**
	 * Bind policy, output and the optional expensive budget.
	 *
	 * @param SitePolicy           $policy Machine policy.
	 * @param OutputPipeline       $output Output boundary.
	 * @param ExpensiveBudget|null $expensive Atomic work budget.
	 */
	public function __construct( private SitePolicy $policy, private OutputPipeline $output, private ?ExpensiveBudget $expensive = null ) {
	}

	/**
	 * Bind the authoritative catalog and the resolved-descriptor callable once.
	 *
	 * @param AbilityCatalog $catalog Catalog.
	 * @param callable       $resolved_descriptor Resolved descriptor callable.
	 * @return void
	 * @throws \LogicException For a frozen or different catalog.
	 */
	public function bind_catalog( AbilityCatalog $catalog, callable $resolved_descriptor ): void {
		if ( $this->frozen ) {
			throw new \LogicException( 'The site dispatcher is frozen.' );
		}
		if ( null !== $this->catalog && $this->catalog !== $catalog ) {
			throw new \LogicException( 'A different catalog is already bound.' );
		}
		$this->catalog             = $catalog;
		$this->resolved_descriptor = Closure::fromCallable( $resolved_descriptor );
	}

	/**
	 * Register one server-owned handler.
	 *
	 * @param string   $canonical_name Canonical operation name.
	 * @param callable $handler Handler.
	 * @return void
	 * @throws \LogicException For a frozen or unbound dispatcher.
	 * @throws \InvalidArgumentException For an unknown, duplicate or badly shaped handler.
	 */
	public function register( string $canonical_name, callable $handler ): void {
		if ( $this->frozen ) {
			throw new \LogicException( 'The site dispatcher is frozen.' );
		}
		if ( null === $this->catalog ) {
			throw new \LogicException( 'The catalog is not bound.' );
		}
		if ( self::EDITORIAL_AUTHOR_LOOKUP === $canonical_name ) {
			throw new \InvalidArgumentException( 'Duplicate site handler.' );
		}
		if ( null === $this->catalog->get( $canonical_name ) ) {
			throw new \InvalidArgumentException( 'Unknown site handler.' );
		}
		if ( isset( $this->handlers[ $canonical_name ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate site handler.' );
		}
		$closure    = Closure::fromCallable( $handler );
		$reflection = new ReflectionFunction( $closure );
		if ( $reflection->getNumberOfParameters() < 2 ) {
			throw new \InvalidArgumentException( 'Invalid site handler signature.' );
		}
		$parameters = $reflection->getParameters();
		$arguments  = $parameters[0]->getType();
		$context    = $parameters[1]->getType();
		if ( ! $arguments instanceof ReflectionNamedType || 'array' !== $arguments->getName()
			|| ! $context instanceof ReflectionNamedType || SiteAuthContext::class !== $context->getName() ) {
			throw new \InvalidArgumentException( 'Invalid site handler signature.' );
		}
		$this->handlers[ $canonical_name ] = $closure;
	}

	/** Close registration. */
	public function freeze(): void {
		$this->frozen = true;
	}

	/**
	 * Whether an exact explicit or fixed built-in handler is available.
	 *
	 * @param string $canonical_name Canonical operation name.
	 * @return bool
	 */
	public function has_handler( string $canonical_name ): bool {
		if ( isset( $this->handlers[ $canonical_name ] ) ) {
			return true;
		}
		return self::EDITORIAL_AUTHOR_LOOKUP === $canonical_name && null !== $this->catalog?->get( $canonical_name );
	}

	/**
	 * Resolve the exact explicit or fixed built-in handler without input selection.
	 *
	 * @param string $canonical_name Canonical operation name.
	 * @return Closure(array<string,mixed>,SiteAuthContext):mixed|null
	 */
	private function handler_for( string $canonical_name ): ?Closure {
		if ( isset( $this->handlers[ $canonical_name ] ) ) {
			return $this->handlers[ $canonical_name ];
		}
		if ( self::EDITORIAL_AUTHOR_LOOKUP === $canonical_name && null !== $this->catalog?->get( $canonical_name ) ) {
			return EditorialAuthors::handler();
		}
		return null;
	}

	/**
	 * Dispatch one machine operation.
	 *
	 * @param string              $canonical_name Canonical operation name.
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Verified site identity.
	 * @return mixed
	 */
	public function execute( string $canonical_name, array $arguments, SiteAuthContext $context ): mixed {
		$catalog  = $this->catalog;
		$resolver = $this->resolved_descriptor;
		if ( null === $catalog || null === $resolver ) {
			return $this->unavailable();
		}
		$descriptor = $resolver( $canonical_name );
		if ( null === $descriptor ) {
			return $this->unavailable();
		}
		$definition = $catalog->get( $canonical_name );
		if ( null === $definition ) {
			return $this->unavailable();
		}
		$handler = $this->handler_for( $canonical_name );
		if ( null === $handler ) {
			return $this->unavailable();
		}
		if ( get_current_blog_id() !== $context->blog_id() ) {
			return $this->unavailable();
		}
		if ( ! $this->policy->evaluate( $descriptor, $context, 'http' )->allowed ) {
			return $this->unavailable();
		}
		if ( true !== rest_validate_value_from_schema( $arguments, $definition->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		try {
			$work   = static fn(): mixed => $handler( $arguments, $context );
			$result = $definition->costly() && null !== $this->expensive
				? $this->expensive->run( 'site:' . $context->blog_id() . ':' . $context->credential->key_id->value, $work )
				: $work();
			return $this->output->process( $result, $definition->output_schema() );
		} catch ( \Throwable ) {
			return ReasonMapper::error( 'internal_error' );
		}
	}

	/**
	 * One indistinguishable unavailability error.
	 *
	 * @return WP_Error
	 */
	private function unavailable(): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
	}
}
