<?php
/**
 * Owner-scoped audit management listing.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XNetVN\AgentHubWP\Contracts\AuditRepository;

/** A requested key filter can only further narrow the permitted owner scope. */
final class AuditController extends ManagementController {
	/**
	 * Bind safe audit persistence.
	 *
	 * @param ManagementGate  $gate Cookie boundary.
	 * @param AuditRepository $audit Audit metadata.
	 */
	public function __construct( ManagementGate $gate, private AuditRepository $audit ) {
		parent::__construct( $gate );
	}

	/** Register bounded typed audit filters with management-only cross-owner access. */
	public function register_routes(): void {
		$args = KeysController::listing_args();
		unset( $args['state'] );
		$args['key_id']  = array(
			'type'    => 'string',
			'pattern' => '^[a-z2-7]{12}$',
		);
		$args['ability'] = array(
			'type'    => 'string',
			'pattern' => '^xnetvn/[a-z][a-z0-9-]{0,120}$',
		);
		$args['outcome'] = array(
			'type' => 'string',
			'enum' => array( 'success', 'denied', 'error', 'invalid_input', 'rate_limited', 'auth_failed' ),
		);
		foreach ( array( 'after', 'before' ) as $field ) {
			$args[ $field ] = array(
				'type'   => 'string',
				'format' => 'date-time',
			);
		}
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'can_list' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Check authenticated use and require management for cross-owner filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_list( WP_REST_Request $request ): true|WP_Error {
		$permission = $this->can_use( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$other = $request->get_param( 'user_id' );
		return true === $request->get_param( 'all' ) || ( null !== $other && get_current_user_id() !== (int) $other ) ? $this->can_manage( $request ) : true;
	}

	/**
	 * Return bounded whitelisted metadata, with no raw argument values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		try {
			$filter = array(
				'user_id'  => true === $request->get_param( 'all' ) ? 0 : ( $request->get_param( 'user_id' ) ?? get_current_user_id() ),
				'per_page' => $request->get_param( 'per_page' ),
			);
			foreach ( array( 'key_id', 'ability', 'outcome' ) as $name ) {
				$value = $request->get_param( $name );
				if ( null !== $value ) {
					$filter[ $name ] = $value;
				}
			}
			foreach ( array( 'after', 'before' ) as $name ) {
				$value = $request->get_param( $name );
				if ( is_string( $value ) ) {
					$filter[ $name ] = ( new \DateTimeImmutable( $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				}
			}
			$page = $this->audit->query( $filter, (int) $request->get_param( 'page' ) );
			return $this->response( $page );
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}
}
