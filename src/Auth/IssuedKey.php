<?php
/**
 * Single-disclosure creation result.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\ApiKey;

/** Keeps the full token opaque until the management creation response. */
final readonly class IssuedKey {
	/**
	 * Bind persisted safe metadata to its one-time credential.
	 *
	 * @param ApiKey       $key Metadata.
	 * @param GeneratedKey $credential Opaque generated credential.
	 */
	public function __construct( public ApiKey $key, private GeneratedKey $credential ) {
	}

	/**
	 * Reveal only once; listings and lifecycle hooks receive only key metadata.
	 *
	 * @return string
	 */
	public function reveal(): string {
		return $this->credential->reveal();
	}
}
