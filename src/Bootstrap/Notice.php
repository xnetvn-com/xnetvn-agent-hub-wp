<?php
/**
 * Delayed administrative diagnostics; PHP 7.2 syntax only.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Bootstrap;

/** Displays escaped requirement failures to plugin administrators. */
final class Notice {

	/**
	 * Register notices without translating before the notice hook runs.
	 *
	 * @param array<array{code:string,required:string,actual:string}> $failures Requirement failures.
	 */
	public static function register( array $failures ): void {
		$render = static function () use ( $failures ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			foreach ( $failures as $failure ) {
				$code     = isset( $failure['code'] ) ? (string) $failure['code'] : '';
				$required = isset( $failure['required'] ) ? (string) $failure['required'] : '';
				$actual   = isset( $failure['actual'] ) ? (string) $failure['actual'] : '';
				if ( 'php_version' === $code ) {
					/* translators: 1: Required PHP version, 2: Actual PHP version. */
					$message = sprintf( __( 'Requirement not met. xNetVN Agent Hub requires PHP %1$s or higher; you are running %2$s. Next step: ask your administrator to upgrade PHP.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'wp_version' === $code ) {
					/* translators: 1: Required WordPress version, 2: Actual WordPress version. */
					$message = sprintf( __( 'Requirement not met. xNetVN Agent Hub requires WordPress %1$s or higher; you are running %2$s. Next step: ask your administrator to upgrade WordPress.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'adapter_missing' === $code ) {
					/* translators: 1: Required adapter version range, 2: Actual adapter version. */
					$message = sprintf( __( 'Requirement not met. Agent Hub MCP is disabled. The official MCP Adapter %1$s is required; current version: %2$s. Next step: install and activate the official MCP Adapter.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'adapter_version' === $code ) {
					/* translators: 1: Required adapter version range, 2: Actual adapter version. */
					$message = sprintf( __( 'Requirement not met. Agent Hub MCP is disabled. The installed MCP Adapter version %2$s is outside the required range %1$s. Next step: update the official MCP Adapter to a supported version.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'autoloader' === $code ) {
					/* translators: 1: Required autoloader path, 2: Actual autoloader status. */
					$message = sprintf( __( 'Requirement not met. xNetVN Agent Hub dependencies are missing (%1$s: %2$s). Next step: reinstall the production-built release ZIP.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'abilities_api' === $code ) {
					/* translators: 1: Required API name, 2: Actual API status. */
					$message = sprintf( __( 'Requirement not met. xNetVN Agent Hub requires the WordPress Abilities API (required: %1$s; current: %2$s). Next step: update WordPress to a version that includes the core Abilities API.', 'xnetvn-agent-hub-wp' ), $required, $actual );
				} elseif ( 'bootstrap' === $code ) {
					$message = __( 'Requirement not met. xNetVN Agent Hub could not start. Next step: reinstall the production release ZIP and contact your administrator if the problem continues.', 'xnetvn-agent-hub-wp' );
				} else {
					$message = __( 'Requirement not met. An environment requirement is not met. Next step: contact your administrator.', 'xnetvn-agent-hub-wp' );
				}
				echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			}
		};
		add_action( 'admin_notices', $render );
		add_action( 'network_admin_notices', $render );
	}
}
