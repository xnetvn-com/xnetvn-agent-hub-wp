<?php
/**
 * Cookie-only user-owned API key REST endpoints.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Contracts\KeyRepository;

/** Every route uses the management gate and mutation services recheck ownership. */
final class KeysController extends ManagementController {
	/**
	 * Bind lifecycle services and safe listing persistence.
	 *
	 * @param ManagementGate $gate Cookie boundary.
	 * @param KeyService     $service Lifecycle.
	 * @param KeyRepository  $keys Metadata persistence.
	 */
	public function __construct( ManagementGate $gate, private KeyService $service, private KeyRepository $keys ) {
		parent::__construct( $gate );
		$this->rest_base = 'keys';
	}

	/** Register collection, individual and rotation endpoints with strict field schemas. */
	public function register_routes(): void {
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_list' ),
					'args'                => self::listing_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_use' ),
					'args'                => self::mutation_args( true ),
				),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/keys/(?P<id>[1-9][0-9]*)',
			array(
				'args' => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_login' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_login' ),
					'args'                => self::mutation_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'can_login' ),
				),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/keys/(?P<id>[1-9][0-9]*)/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate' ),
				'permission_callback' => array( $this, 'can_use' ),
				'args'                => array(
					'id'               => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'grace_hours'      => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 24,
					),
					'current_password' => array(
						'type'      => 'string',
						'maxLength' => 4096,
					),
				),
			)
		);
	}

	/**
	 * Restrict cross-owner collection filters to managers.
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
		return ( true === $request->get_param( 'all' ) || ( null !== $other && get_current_user_id() !== (int) $other ) ) && ! current_user_can( Capabilities::MANAGE ) ? $this->failure( 403 ) : true;
	}

	/**
	 * List only the current owner unless a manager explicitly selects otherwise.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		try {
			$filter = array(
				'user_id'  => true === $request->get_param( 'all' ) ? 0 : ( $request->get_param( 'user_id' ) ?? get_current_user_id() ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
				'state'    => $request->get_param( 'state' ),
			);
			$result = $this->response( array_map( KeyView::data( ... ), $this->keys->list_all( $filter ) ) );
			$total  = $this->keys->count_all( $filter );
			$result->header( 'X-WP-Total', (string) $total );
			$result->header( 'X-WP-TotalPages', (string) ceil( $total / (int) $filter['per_page'] ) );
			return $result;
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}

	/**
	 * Read authorized safe metadata.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$key = $this->service->get( (int) $request['id'] );
		return is_wp_error( $key ) ? $key : $this->response( KeyView::data( $key ) );
	}

	/**
	 * Return the full token only in this creation response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request, array_keys( self::mutation_args( true ) ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$issued = $this->service->create( get_current_user_id(), $body );
		return is_wp_error( $issued ) ? $issued : $this->response(
			array(
				'key'    => KeyView::data( $issued->key ),
				'secret' => $issued->reveal(),
			),
			201
		);
	}

	/**
	 * Rename or narrow without ever returning a secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request, array_keys( self::mutation_args( false ) ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$key = $this->service->patch( (int) $request['id'], $body );
		return is_wp_error( $key ) ? $key : $this->response( KeyView::data( $key ) );
	}

	/**
	 * Revoke only after service ownership checks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$revoked = $this->service->revoke( (int) $request['id'] );
		return is_wp_error( $revoked ) ? $revoked : $this->response(
			array(
				'id'      => (int) $request['id'],
				'revoked' => $revoked,
			)
		);
	}

	/**
	 * Return a newly rotated credential once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rotate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request, array( 'current_password', 'grace_hours' ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$issued = $this->service->rotate( (int) $request['id'], $body );
		return is_wp_error( $issued ) ? $issued : $this->response(
			array(
				'key'    => KeyView::data( $issued->key ),
				'secret' => $issued->reveal(),
			),
			201
		);
	}

	/**
	 * Shared bounded collection filters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function listing_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 1000000,
				'default' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
			'state'    => array(
				'type'    => 'string',
				'enum'    => array( 'active', 'expired', 'revoked', 'all' ),
				'default' => 'all',
			),
			'user_id'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'all'      => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Exact creation/patch schemas; no broad credential or owner field.
	 *
	 * @param bool $create Require a name and permit password reauthentication.
	 * @return array<string,array<string,mixed>>
	 */
	private static function mutation_args( bool $create ): array {
		$fields = array(
			'name'            => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 100,
				'required'  => $create,
			),
			'tier_max'        => array(
				'type' => 'string',
				'enum' => array( 'read', 'write', 'destructive', 'admin' ),
			),
			'expires_in_days' => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 1,
				'maximum' => 3650,
			),
		);
		foreach ( array( 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist' ) as $name ) {
			$fields[ $name ] = array(
				'type'     => 'array',
				'maxItems' => 100,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
			);
		}
		if ( $create ) {
			$fields['current_password'] = array(
				'type'      => 'string',
				'maxLength' => 4096,
			);
		}
		return $fields;
	}
}
