<?php
/**
 * Cookie-only accountless site key management REST endpoints.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XNetVN\AgentHubWP\Auth\SiteKeyService;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** Every route uses the inherited cookie gate and delegates to the blog-bound service. */
final class SiteKeysController extends ManagementController {
	/** Exact creation fields. */
	private const CREATE_FIELDS = array( 'name', 'permissions', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'expires_in_days', 'current_password' );

	/** Exact patch fields; no password field. */
	private const PATCH_FIELDS = array( 'name', 'permissions', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'expires_in_days' );

	/** Exact rotation fields. */
	private const ROTATE_FIELDS = array( 'current_password', 'grace_hours' );

	/** Exact collection query allowlist. */
	private const QUERY_FIELDS = array( 'page', 'per_page', 'state', '_wpnonce', 'rest_route', '_fields', '_envelope', '_locale' );

	/** Exact item query allowlist: core pass-through parameters only. */
	private const ITEM_QUERY_FIELDS = array( '_wpnonce', 'rest_route', '_fields', '_envelope', '_locale' );

	/** Reviewed moderation permissions. */
	private const PERMISSIONS = array( SiteScope::PERMISSION_BAN_USERS, SiteScope::PERMISSION_UNBAN_USERS );

	/**
	 * Bind the cookie gate, the lifecycle service and the boot blog.
	 *
	 * @param ManagementGate $gate Cookie boundary.
	 * @param SiteKeyService $service Blog-bound lifecycle.
	 * @param int            $blog_id Boot blog identity.
	 * @throws \InvalidArgumentException For an invalid blog identity.
	 */
	public function __construct( ManagementGate $gate, private SiteKeyService $service, private int $blog_id ) {
		parent::__construct( $gate );
		if ( $blog_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid site key management blog.' );
		}
		$this->rest_base = 'site-keys';
	}

	/** Register the six cookie-only routes with strict field schemas. */
	public function register_routes(): void {
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/site-keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::listing_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::mutation_args( true ),
				),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/site-keys/(?P<id>[1-9][0-9]*)',
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
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::mutation_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/site-keys/(?P<id>[1-9][0-9]*)/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					array(
						'id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					self::rotation_args()
				),
			)
		);
	}

	/**
	 * List bounded site key metadata without totals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$guard = $this->query_guard( $request, self::QUERY_FIELDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$query    = $request->get_query_params();
		$page     = (int) ( $query['page'] ?? 1 );
		$per_page = (int) ( $query['per_page'] ?? 20 );
		$state    = (string) ( $query['state'] ?? 'all' );
		$items    = $this->service->list(
			array(
				'page'     => $page,
				'per_page' => $per_page,
				'state'    => 'all' === $state ? '' : $state,
			)
		);
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		return $this->response(
			array(
				'items'    => array_map( SiteKeyView::data( ... ), $items ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Issue one site key and disclose its secret exactly once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$body = $this->object_body( $request, self::CREATE_FIELDS );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$issued = $this->service->create( $body );
		return is_wp_error( $issued ) ? $issued : $this->response(
			array(
				'key'    => SiteKeyView::data( $issued->key ),
				'secret' => $issued->reveal(),
			),
			201
		);
	}

	/**
	 * Read authorized site key metadata, including revoked and expired rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$guard = $this->query_guard( $request, self::ITEM_QUERY_FIELDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$key = $this->service->get( (int) $request->get_url_params()['id'] );
		return is_wp_error( $key ) ? $key : $this->response( SiteKeyView::data( $key ) );
	}

	/**
	 * Rename or narrow one active site key without returning a secret.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$guard = $this->query_guard( $request, self::ITEM_QUERY_FIELDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$body = $this->object_body( $request, self::PATCH_FIELDS );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$key = $this->service->patch( (int) $request->get_url_params()['id'], $body );
		return is_wp_error( $key ) ? $key : $this->response( SiteKeyView::data( $key ) );
	}

	/**
	 * Revoke one site key; a second revocation reports false without a write.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$guard = $this->query_guard( $request, self::ITEM_QUERY_FIELDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$id      = (int) $request->get_url_params()['id'];
		$revoked = $this->service->revoke( $id );
		return is_wp_error( $revoked ) ? $revoked : $this->response(
			array(
				'id'      => $id,
				'revoked' => $revoked,
			)
		);
	}

	/**
	 * Rotate one site key and disclose the replacement secret exactly once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rotate( $request ): WP_REST_Response|WP_Error {
		$guard = $this->blog_guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$guard = $this->query_guard( $request, self::ITEM_QUERY_FIELDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$body = $this->object_body( $request, self::ROTATE_FIELDS );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$issued = $this->service->rotate( (int) $request->get_url_params()['id'], $body );
		return is_wp_error( $issued ) ? $issued : $this->response(
			array(
				'key'    => SiteKeyView::data( $issued->key ),
				'secret' => $issued->reveal(),
			),
			201
		);
	}

	/**
	 * Refuse a stale controller before any service call.
	 *
	 * @return true|WP_Error
	 */
	private function blog_guard(): true|WP_Error {
		return get_current_blog_id() === $this->blog_id ? true : $this->failure( 404 );
	}

	/**
	 * Reject any query key outside the reviewed allowlist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array<string>   $allowed Allowed query keys.
	 * @return true|WP_Error
	 */
	private function query_guard( WP_REST_Request $request, array $allowed ): true|WP_Error {
		return array_diff( array_keys( $request->get_query_params() ), $allowed ) ? $this->failure( 400 ) : true;
	}

	/**
	 * Require one JSON object with no undocumented field.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array<string>   $allowed Exact body fields.
	 * @return array<string,mixed>|WP_Error
	 */
	private function object_body( WP_REST_Request $request, array $allowed ): array|WP_Error {
		$trimmed = ltrim( $request->get_body() );
		if ( '' !== $trimmed && '[' === $trimmed[0] ) {
			return $this->failure( 400 );
		}
		return $this->body( $request, $allowed );
	}

	/**
	 * Bounded collection filters plus the core pass-through parameters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function listing_args(): array {
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
				'enum'    => array( 'active', 'revoked', 'expired', 'all' ),
				'default' => 'all',
			),
		);
	}

	/**
	 * Exact creation or patch schema; the live expiry cap and the service decide statuses.
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
			'permissions'     => array(
				'type'     => 'array',
				'minItems' => 0,
				'items'    => array(
					'type' => 'string',
					'enum' => self::PERMISSIONS,
				),
			),
			'expires_in_days' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		foreach ( array( 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist' ) as $name ) {
			$fields[ $name ] = array(
				'type'     => 'array',
				'maxItems' => 100,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 191,
				),
			);
		}
		if ( $create ) {
			$fields['current_password'] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 4096,
				'required'  => true,
			);
		}
		return $fields;
	}

	/**
	 * Exact rotation schema; the service applies the omitted grace window.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function rotation_args(): array {
		return array(
			'current_password' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 4096,
				'required'  => true,
			),
			'grace_hours'      => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => SiteKeyService::MAX_ROTATE_GRACE_HOURS,
			),
		);
	}
}
