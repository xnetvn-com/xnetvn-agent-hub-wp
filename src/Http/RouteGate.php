<?php
/**
 * Origin/TLS errors before credential authentication.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Http;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;

/** Guards only the plugin MCP route and preserves earlier failures. */
final class RouteGate implements ServiceProvider {
	/**
	 * Bind validated configuration and transport policy.
	 *
	 * @param SiteSettings   $settings Site policy.
	 * @param TransportGuard $transport Transport policy.
	 */
	public function __construct( private SiteSettings $settings, private TransportGuard $transport ) {
	}

	/** Register the guard before key authentication and cookie authentication. */
	public function register(): void {
		add_filter( 'rest_authentication_errors', array( $this, 'check' ), 5 );
	}

	/**
	 * Apply the exact-route guard without treating prior success as a bypass.
	 *
	 * @param mixed $result Prior authentication result.
	 * @return mixed
	 */
	public function check( mixed $result ): mixed {
		if ( ! RouteMatcher::is_mcp_route() || is_wp_error( $result ) ) {
			return $result;
		}
		try {
			$allowed = $this->settings->get( 'allowed_origins' );
			$allowed = is_array( $allowed ) ? $allowed : array();
			foreach ( array( home_url(), site_url() ) as $url ) {
				$origin = OriginGuard::from_url( $url );
				if ( null !== $origin ) {
					$allowed[] = $origin;
				}
			}
			$allowed = apply_filters( 'xnetvn_agent_hub_wp_allowed_origins', $allowed );
			if ( ! is_array( $allowed ) ) {
				return new WP_Error( 'xnetvn_agent_hub_wp_origin_denied', 'Origin is not allowed.', array( 'status' => 403 ) );
			}
			$allowed = array_values( array_filter( $allowed, 'is_string' ) );
			$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? ( is_string( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : 'null' ) : null;
			return self::evaluate( $origin, $this->transport->is_secure(), $allowed ) ?? $result;
		} catch ( \Throwable ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_configuration_unavailable', 'Server configuration is unavailable.', array( 'status' => 503 ) );
		}
	}

	/**
	 * Return a safe guard error, or null when both terms succeed.
	 *
	 * @param string|null   $origin Origin header.
	 * @param bool          $secure Secure transport.
	 * @param array<string> $allowed Explicit origin set.
	 * @return WP_Error|null
	 */
	public static function evaluate( ?string $origin, bool $secure, array $allowed ): ?WP_Error {
		if ( ! OriginGuard::allows( $origin, $allowed ) ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_origin_denied', 'Origin is not allowed.', array( 'status' => 403 ) );
		}
		if ( ! $secure ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_insecure_transport', 'A secure transport is required.', array( 'status' => 403 ) );
		}
		return null;
	}
}
