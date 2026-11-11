<?php
/**
 * Fail-closed bootstrap; PHP 7.2 syntax only.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Bootstrap;

/** Loads modern code only after runtime requirements are met. */
final class Loader {

	/**
	 * Check requirements, register diagnostics or boot the kernel.
	 *
	 * @param string                                                       $plugin_file Main plugin file.
	 * @param array<array{code:string,required:string,actual:string}>|null $failures Injected failures for testing.
	 * @return bool Whether the kernel was loaded.
	 */
	public static function run( string $plugin_file, ?array $failures = null ): bool {
		$autoload = dirname( $plugin_file ) . '/vendor/autoload.php';
		if ( null === $failures ) {
			$failures = Requirements::failures( PHP_VERSION, (string) ( $GLOBALS['wp_version'] ?? '' ), function_exists( 'wp_register_ability' ) );
			if ( ! is_readable( $autoload ) ) {
				$failures[] = array(
					'code'     => 'autoloader',
					'required' => 'vendor/autoload.php',
					'actual'   => 'unavailable',
				);
			}
		}
		if ( array() !== $failures ) {
			Notice::register( $failures );
			return false;
		}
		require_once $autoload;
		\XNetVN\AgentHubWP\Plugin::boot( $plugin_file );
		return true;
	}
}
