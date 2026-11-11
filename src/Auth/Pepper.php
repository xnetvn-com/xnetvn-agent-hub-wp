<?php
/**
 * Opaque separate credential pepper.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

/** Retains pepper bytes outside exportable object properties. */
final class Pepper implements \JsonSerializable {
	/** Private weakly owned pepper material.
	 *
	 * @var \WeakMap<self,string>|null
	 */
	private static ?\WeakMap $values = null;
	/**
	 * Bind validated pepper bytes.
	 *
	 * @param int    $id Version.
	 * @param string $value Raw random bytes.
	 * @throws \InvalidArgumentException For invalid material.
	 */
	public function __construct( public readonly int $id, #[\SensitiveParameter] string $value ) {
		if ( $id < 1 || $id > 255 || 32 !== strlen( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid credential pepper.' );
		}
		self::$values        ??= new \WeakMap();
		self::$values[ $this ] = $value;
	}
	/**
	 * Reveal only at the deliberate HMAC boundary.
	 *
	 * @return string
	 * @throws \LogicException For an unavailable value.
	 */
	public function reveal(): string {
		if ( null === self::$values || ! isset( self::$values[ $this ] ) ) {
			throw new \LogicException( 'Pepper is unavailable.' );
		}
		return self::$values[ $this ];
	}
	/**
	 * Return safe JSON data.
	 *
	 * @return array{id:int,value:string}
	 */
	public function jsonSerialize(): array {
		return array(
			'id'    => $this->id,
			'value' => '[redacted]',
		);
	}
}
