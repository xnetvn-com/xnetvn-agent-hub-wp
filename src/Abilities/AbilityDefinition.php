<?php
/**
 * Immutable private ability definition and logical handler.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities;

use Closure;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Policy\HardDeny;

/** Keeps fixed metadata separate from the wrapped WordPress permission callback. */
final readonly class AbilityDefinition implements AbilitySpec {
	/** Policy descriptor.
	 *
	 * @var AbilityDescriptor
	 */
	private AbilityDescriptor $policy;
	/** Validated definition fields.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;
	/** Logical operation handler.
	 *
	 * @var Closure(array<string,mixed>):mixed
	 */
	private Closure $handler;
	/**
	 * Validate canonical metadata before it enters WordPress's registry.
	 *
	 * @param array<string,mixed> $data Definition fields.
	 * @throws \InvalidArgumentException For malformed, public or inconsistent metadata.
	 */
	public function __construct( array $data ) {
		$allowed = array( 'name', 'label', 'description', 'inputSchema', 'outputSchema', 'tier', 'toolset', 'annotations', 'visibilityCap', 'handler', 'example', 'costly' );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown or public definition fields.' );
		}
		foreach ( array( 'name', 'label', 'description', 'toolset', 'visibilityCap' ) as $name ) {
			if ( ! isset( $data[ $name ] ) || ! is_string( $data[ $name ] ) || '' === $data[ $name ] ) {
				throw new \InvalidArgumentException( 'Invalid definition text.' );
			}
		}
		if ( 1 !== preg_match( '/\Axnetvn\/[a-z0-9-]+\z/', $data['name'] ) || strlen( $data['name'] ) > 128 || strlen( $data['description'] ) < 32 || ! isset( $data['tier'] ) || ! $data['tier'] instanceof RiskTier ) {
			throw new \InvalidArgumentException( 'Invalid definition identity or tier.' );
		}
		foreach ( array( 'inputSchema', 'outputSchema' ) as $name ) {
			if ( ! isset( $data[ $name ] ) || ! is_array( $data[ $name ] ) || 'object' !== ( $data[ $name ]['type'] ?? null ) || false !== ( $data[ $name ]['additionalProperties'] ?? null ) ) {
				throw new \InvalidArgumentException( 'Definitions require strict object schemas.' );
			}
		}
		$annotations = $data['annotations'] ?? null;
		if ( ! is_array( $annotations ) || ! isset( $annotations['readonly'], $annotations['destructive'], $annotations['idempotent'] ) || ! is_bool( $annotations['readonly'] ) || ! is_bool( $annotations['destructive'] ) || ! is_bool( $annotations['idempotent'] ) || ( $annotations['readonly'] && RiskTier::Read !== $data['tier'] ) || ( $annotations['destructive'] && $data['tier']->atMost( RiskTier::Write ) ) ) {
			throw new \InvalidArgumentException( 'Invalid behavior annotations.' );
		}
		if ( ! isset( $data['handler'] ) || ! is_callable( $data['handler'] ) ) {
			throw new \InvalidArgumentException( 'Missing logical handler.' );
		}
		if ( HardDeny::forbidden( $data['name'] ) || ( RiskTier::Admin === $data['tier'] && ! HardDeny::reviewed_admin( $data['name'] ) ) ) {
			throw new \InvalidArgumentException( 'Unreviewed or permanently excluded ability operation.' );
		}
		$this->policy  = new AbilityDescriptor( $data['name'], $data['tier'], $data['toolset'], $data['visibilityCap'] );
		$this->handler = Closure::fromCallable( $data['handler'] );
		unset( $data['handler'] );
		$this->data = $data;
	}
	/**
	 * Return immutable authorization metadata.
	 *
	 * @return AbilityDescriptor
	 */
	public function descriptor(): AbilityDescriptor {
		return $this->policy;
	}
	/**
	 * Return canonical MCP name.
	 *
	 * @return string
	 */
	public function tool_name(): string {
		return str_replace( '/', '-', $this->policy->name );
	}
	/**
	 * Return the UI label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->data['label'];
	}
	/**
	 * Return the fixed English description.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->data['description'];
	}
	/**
	 * Return strict input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function input_schema(): array {
		return $this->data['inputSchema'];
	}
	/**
	 * Return strict output schema.
	 *
	 * @return array<string,mixed>
	 */
	public function output_schema(): array {
		return $this->data['outputSchema'];
	}
	/**
	 * Return behavior hints.
	 *
	 * @return array<string,mixed>
	 */
	public function annotations(): array {
		return $this->data['annotations'];
	}
	/**
	 * Return a contract output example.
	 *
	 * @return mixed
	 */
	public function example(): mixed {
		return $this->data['example'] ?? null;
	}
	/**
	 * Determine whether bounded expensive-work handling is required.
	 *
	 * @return bool
	 */
	public function costly(): bool {
		return true === ( $this->data['costly'] ?? false );
	}
	/**
	 * Invoke the logical handler after registry validation and authorization.
	 *
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed {
		return ( $this->handler )( $arguments );
	}
}
