<?php
/**
 * TLS and trusted-proxy transport policy.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Http;

use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Support\Cidr;

/** Prevents arbitrary forwarded headers from asserting a secure connection. */
final class TransportGuard {
	/**
	 * Bind explicit proxy policy.
	 *
	 * @param SiteSettings $settings Site settings.
	 */
	public function __construct( private SiteSettings $settings ) {
	}
	/**
	 * Read the current platform and evaluate its transport.
	 *
	 * @return bool
	 */
	public function is_secure(): bool {
		$headers = $this->settings->get( 'trusted_proxy_headers' );
		$cidrs   = $this->settings->get( 'trusted_proxy_cidrs' );
		return self::secure( is_ssl(), wp_get_environment_type(), defined( 'XNETVN_AGENT_HUB_WP_ALLOW_INSECURE' ) && true === XNETVN_AGENT_HUB_WP_ALLOW_INSECURE, $_SERVER, is_array( $headers ) ? $headers : array(), is_array( $cidrs ) ? $cidrs : array() );
	}
	/**
	 * Evaluate transport without mutable global state.
	 *
	 * @param bool                $ssl Real TLS.
	 * @param string              $environment WordPress environment.
	 * @param bool                $local_flag Explicit local opt-in.
	 * @param array<string,mixed> $server Server headers and peer address.
	 * @param array<string>       $headers Approved proxy header names.
	 * @param array<string>       $cidrs Approved proxy addresses.
	 * @return bool
	 */
	public static function secure( bool $ssl, string $environment, bool $local_flag, array $server, array $headers, array $cidrs ): bool {
		if ( $ssl || ( $local_flag && in_array( $environment, array( 'local', 'development' ), true ) ) ) {
			return true;
		}
		$peer  = $server['REMOTE_ADDR'] ?? null;
		$proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
		if ( ! is_string( $peer ) || ! is_string( $proto ) || 'https' !== strtolower( $proto ) || ! in_array( 'X-Forwarded-Proto', $headers, true ) ) {
			return false;
		}
		foreach ( $cidrs as $cidr ) {
			if ( Cidr::contains( $cidr, $peer ) ) {
				return true;
			}
		}
		return false;
	}
}
