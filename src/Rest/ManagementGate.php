<?php
/**
 * Cookie and nonce boundary for credential management.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** A core cookie session is mandatory; API keys and Application Passwords cannot substitute. */
final class ManagementGate implements ServiceProvider {
	/** Management namespace, separate from the exact MCP route. */
	public const REST_NAMESPACE = 'xnetvn-agent-hub-wp/v1';

	/** Apply no-store headers even on management failures. */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'headers' ), 10, 3 );
	}

	/**
	 * Validate the actual logged-in cookie and its REST nonce against current identity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check( WP_REST_Request $request ): true|WP_Error {
		$user = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! is_int( $user ) || $user < 1 || get_current_user_id() !== $user ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_cookie_required', 'A logged-in cookie session is required.', array( 'status' => 401 ) );
		}
		$nonce = $this->nonce_candidate( $request );
		if ( null === $nonce ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_nonce_required', 'A valid REST nonce is required.', array( 'status' => 403 ) );
		}
		try {
			$verified = wp_verify_nonce( $nonce, 'wp_rest' );
		} catch ( \Throwable ) {
			$verified = false;
		}
		if ( 1 !== $verified && 2 !== $verified ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_nonce_required', 'A valid REST nonce is required.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Select one unchanged nonce string from the header or parameter source.
	 *
	 * A present header is authoritative even when malformed; it never falls
	 * back to the parameter. Sanitization is a rejection-only check and its
	 * output is never handed to the verifier.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|null
	 */
	private function nonce_candidate( WP_REST_Request $request ): ?string {
		$header = $request->get_header_as_array( 'x-wp-nonce' );
		if ( null !== $header ) {
			if ( ! is_array( $header ) ) {
				return null;
			}
			$values = array_values( $header );
			if ( 1 !== count( $values ) || ! is_string( $values[0] ) || '' === $values[0] ) {
				return null;
			}
			$candidate = $values[0];
		} else {
			$candidate = $request->get_param( '_wpnonce' );
			if ( ! is_string( $candidate ) || '' === $candidate ) {
				return null;
			}
		}
		try {
			$sanitized = sanitize_text_field( $candidate );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_string( $sanitized ) || $candidate !== $sanitized ) {
			return null;
		}
		return $candidate;
	}

	/**
	 * Attach headers to the management namespace only.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server Server.
	 * @param WP_REST_Request   $request Request.
	 * @return \WP_REST_Response
	 */
	public function headers( \WP_REST_Response $response, \WP_REST_Server $server, WP_REST_Request $request ): \WP_REST_Response {
		if ( str_starts_with( $request->get_route(), '/' . self::REST_NAMESPACE . '/' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}
}
