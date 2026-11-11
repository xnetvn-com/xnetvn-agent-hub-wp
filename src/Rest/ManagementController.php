<?php
/**
 * Common management controller boundaries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** Makes the cookie/nonce gate unavoidable for every management permission callback. */
abstract class ManagementController extends \WP_REST_Controller implements ServiceProvider {
	/**
	 * Bind shared authentication.
	 *
	 * @param ManagementGate $gate Cookie boundary.
	 */
	public function __construct( protected ManagementGate $gate ) {
		$this->namespace = ManagementGate::REST_NAMESPACE;
	}

	/** Register endpoints on the core REST lifecycle. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Permit any actual logged-in cookie session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_login( WP_REST_Request $request ): true|WP_Error {
		return $this->gate->check( $request );
	}

	/**
	 * Require delegated use or current site management.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_use( WP_REST_Request $request ): true|WP_Error {
		$cookie = $this->can_login( $request );
		return is_wp_error( $cookie ) ? $cookie : ( current_user_can( Capabilities::USE ) || current_user_can( Capabilities::MANAGE ) ? true : $this->failure( 403 ) );
	}

	/**
	 * Require current site management.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_manage( WP_REST_Request $request ): true|WP_Error {
		$cookie = $this->can_login( $request );
		return is_wp_error( $cookie ) ? $cookie : ( current_user_can( Capabilities::MANAGE ) ? true : $this->failure( 403 ) );
	}

	/**
	 * Require a JSON object with no undocumented fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array<string>   $allowed Field names.
	 * @return array<string,mixed>|WP_Error
	 */
	protected function body( WP_REST_Request $request, array $allowed ): array|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ( $body && array_is_list( $body ) ) || array_diff( array_keys( $body ), $allowed ) ) {
			return $this->failure( 400 );
		}
		return $body;
	}

	/**
	 * Return safe successful management data with no-store headers.
	 *
	 * @param mixed $data Logical response.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	protected function response( mixed $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response(
			$data,
			$status,
			array(
				'Cache-Control'          => 'no-store',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
	}

	/**
	 * Safe management error; values never appear in messages.
	 *
	 * @param int $status HTTP status.
	 * @return WP_Error
	 */
	protected function failure( int $status ): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_management_unavailable', 'The management operation is unavailable.', array( 'status' => $status ) );
	}
}
