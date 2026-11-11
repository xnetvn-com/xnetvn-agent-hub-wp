<?php
/**
 * Minimal error value for an isolated service failure test, not an authentication emulator.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Isolated test substitute of the core error type; never loaded in production or integration tests.
if ( ! class_exists( 'WP_Error', false ) ) {
	/** Stores only the constructor arguments needed to inspect service error status. */
	class WP_Error {
		/**
		 * Capture the service's error arguments.
		 *
		 * @param string              $code Safe reason.
		 * @param string              $message Safe message.
		 * @param array<string,mixed> $data Status metadata.
		 */
		public function __construct( public string $code, public string $message, public array $data = array() ) {
		}

		/**
		 * Read the safe reason code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
// phpcs:enable
