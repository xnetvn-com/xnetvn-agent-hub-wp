<?php
/**
 * Opaque secret value object.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Keeps credentials out of object exports as well as debug/JSON surfaces. */
final class KeySecret implements \JsonSerializable {

	/** Out-of-object storage; entries disappear when their key objects are collected.
	 *
	 * @var \WeakMap<self,string>|null
	 */
	private static ?\WeakMap $values = null;

	/**
	 * Validate and retain the secret without storing it as an object property.
	 *
	 * @param string $value Base64url secret.
	 * @throws \InvalidArgumentException For malformed input.
	 */
	public function __construct( #[\SensitiveParameter] string $value ) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid key secret.' );
		}
		self::$values        ??= new \WeakMap();
		self::$values[ $this ] = $value;
	}

	/**
	 * Reveal only at the deliberate credential boundary.
	 *
	 * @return string
	 * @throws \LogicException If the object has no live secret.
	 */
	public function reveal(): string {
		if ( null === self::$values || ! isset( self::$values[ $this ] ) ) {
			throw new \LogicException( 'Secret is unavailable.' );
		}
		return self::$values[ $this ];
	}

	/**
	 * Return safe debugging metadata.
	 *
	 * @return array{value:string}
	 */
	public function __debugInfo(): array {
		return array( 'value' => '[redacted]' );
	}

	/**
	 * Return a safe JSON value.
	 *
	 * @return string
	 */
	public function jsonSerialize(): string {
		return '[redacted]';
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Serialization always throws under the magic-method array contract.
	/**
	 * Prohibit persistence of a secret object.
	 *
	 * @return array<never>
	 * @throws \LogicException Always: secrets cannot be serialized.
	 */
	public function __serialize(): array {
		throw new \LogicException( 'Secret serialization is not permitted.' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}
