<?php
/**
 * Single-disclosure website credential creation result.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\SiteCredential;

/** Keeps the full site token opaque until the management creation response. */
final readonly class IssuedSiteKey {
	/**
	 * Bind persisted safe metadata to its one-time credential.
	 *
	 * @param SiteCredential   $key Metadata.
	 * @param GeneratedSiteKey $credential Opaque generated credential.
	 */
	public function __construct( public SiteCredential $key, private GeneratedSiteKey $credential ) {
	}

	/**
	 * Reveal only once; listings and lifecycle hooks receive only metadata.
	 *
	 * @return string
	 */
	public function reveal(): string {
		return $this->credential->reveal();
	}

	/** Prevent a second credential disclosure budget through cloning. */
	private function __clone(): void {
	}
}
