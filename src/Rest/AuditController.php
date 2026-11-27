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
	/** Wire allowlist; mirrors WpdbAuditRepository::LIST_COLUMNS and changes only with it. */
	private const ITEM_FIELDS = array( 'occurred_at', 'user_id', 'ability', 'outcome', 'transport', 'principal_type', 'blog_id', 'credential_ref' );

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
		$args['principal_type'] = array(
			'type' => 'string',
			'enum' => array( 'user', 'site' ),
		);
		$args['credential_ref'] = array(
			'type'      => 'string',
			'maxLength' => 16,
			'pattern'   => '^(?:xnv|xns):[a-z2-7]{12}$',
		);
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
	 * Check authenticated use and require management for site, cross-owner and all-row filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_list( WP_REST_Request $request ): true|WP_Error {
		$permission = $this->can_use( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$principal = $request->get_param( 'principal_type' );
		$reference = $request->get_param( 'credential_ref' );
		$other     = $request->get_param( 'user_id' );
		$site      = 'site' === $principal || ( is_string( $reference ) && str_starts_with( $reference, 'xns:' ) );
		return true === $request->get_param( 'all' ) || $site || ( null !== $other && get_current_user_id() !== (int) $other ) ? $this->can_manage( $request ) : true;
	}

	/**
	 * Return bounded whitelisted metadata, with no raw argument values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		try {
			$principal = $request->get_param( 'principal_type' );
			$principal = is_string( $principal ) ? $principal : null;
			$reference = $request->get_param( 'credential_ref' );
			$reference = is_string( $reference ) ? $reference : null;
			$key_id    = $request->get_param( 'key_id' );
			$key_id    = is_string( $key_id ) ? $key_id : null;
			$other     = $request->get_param( 'user_id' );
			$other     = null === $other ? null : (int) $other;
			$conflict  = ( 'site' === $principal && null !== $other )
				|| ( 'user' === $principal && null !== $reference && str_starts_with( $reference, 'xns:' ) )
				|| ( 'site' === $principal && null !== $reference && str_starts_with( $reference, 'xnv:' ) )
				|| ( 'site' === $principal && null !== $key_id )
				|| ( null !== $key_id && null !== $reference && 'xnv:' . $key_id !== $reference );
			if ( $conflict ) {
				return $this->response(
					array(
						'code'    => 'rest_invalid_param',
						'message' => 'The requested audit filters conflict.',
						'data'    => array( 'status' => 400 ),
					),
					400
				);
			}
			$unscoped = true === $request->get_param( 'all' ) || 'site' === $principal || ( null !== $reference && str_starts_with( $reference, 'xns:' ) );
			$filter   = array(
				'user_id'  => $unscoped ? 0 : ( $other ?? get_current_user_id() ),
				'per_page' => $request->get_param( 'per_page' ),
			);
			if ( null !== $principal ) {
				$filter['principal_type'] = $principal;
			} elseif ( null !== $key_id ) {
				$filter['principal_type'] = 'user';
			}
			if ( null !== $reference ) {
				$filter['credential_ref'] = $reference;
			}
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
			return $this->response(
				array(
					'items'    => $this->project( $page['items'] ),
					'total'    => (int) $page['total'],
					'page'     => (int) $page['page'],
					'per_page' => (int) $page['per_page'],
				)
			);
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}

	/**
	 * Project stored rows onto the reviewed wire fields with explicit scalar types.
	 *
	 * @param array<array<string,mixed>> $rows Stored rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function project( array $rows ): array {
		$items = array();
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( self::ITEM_FIELDS as $field ) {
				$value          = $row[ $field ] ?? null;
				$item[ $field ] = match ( $field ) {
					'user_id' => null === $value ? null : (int) $value,
					'blog_id' => (int) $value,
					'ability', 'credential_ref' => null === $value ? null : (string) $value,
					default => (string) $value,
				};
			}
			$items[] = $item;
		}
		return $items;
	}
}
