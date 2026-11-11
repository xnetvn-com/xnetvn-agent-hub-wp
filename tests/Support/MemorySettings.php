<?php
/**
 * In-memory policy fixture.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Support;

use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Exercises policy rules without booting WordPress. */
final class MemorySettings implements SiteSettings {
	/** Fixture settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;
	/**
	 * Bind overrides.
	 *
	 * @param array<string,mixed> $data Overrides.
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge( WpSiteSettings::DEFAULTS, $data );
	}
	/**
	 * Get a fixture setting.
	 *
	 * @param string $name Setting.
	 * @return mixed
	 */
	public function get( string $name ): mixed {
		return $this->data[ $name ];
	}
	/**
	 * Get fixture data.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->data;
	}
	/**
	 * Apply fixture overrides.
	 *
	 * @param array<string,mixed> $changes Overrides.
	 * @return array<string,mixed>
	 */
	public function update( array $changes ): array {
		$this->data = array_merge( $this->data, $changes );
		return $this->data;
	}
}
