<?php
/**
 * Site management settings, status and safe diagnostics.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Sensitive configuration is accessible only through cookie-authenticated site management. */
final class SettingsController extends ManagementController {
	/**
	 * Bind reviewed management services.
	 *
	 * @param ManagementGate $gate Cookie boundary.
	 * @param SiteSettings   $settings Site options.
	 * @param AbilityCatalog $catalog Reviewed metadata.
	 * @param KeyRepository  $keys Metadata counts.
	 * @param ServerGateway  $gateway Adapter diagnostics boundary.
	 * @param TransportGuard $transport Transport policy.
	 */
	public function __construct( ManagementGate $gate, private SiteSettings $settings, private AbilityCatalog $catalog, private KeyRepository $keys, private ServerGateway $gateway, private TransportGuard $transport ) {
		parent::__construct( $gate );
	}

	/** Register settings and diagnostics routes with explicit permission callbacks. */
	public function register_routes(): void {
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::setting_args(),
				),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/diagnostics/auth-header',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'auth_header' ),
				'permission_callback' => array( $this, 'can_login' ),
			)
		);
		register_rest_route(
			ManagementGate::REST_NAMESPACE,
			'/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tools' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Return validated site configuration, never the separate pepper.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return $this->response( $this->settings->all() );
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}

	/**
	 * Validate explicit partial settings updates without silently repairing policy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request, array_keys( self::setting_args() ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		try {
			return $this->response( $this->settings->update( $body ) );
		} catch ( \InvalidArgumentException ) {
			return $this->failure( 400 );
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}

	/**
	 * Return useful operational facts without credentials or server configuration dumps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$version = get_option( Migrator::VERSION_OPTION, null );
			$next    = wp_next_scheduled( Retention::HOOK );
			return $this->response(
				array(
					'endpoint'          => rest_url( 'xnetvn-agent-hub-wp/mcp' ),
					'https'             => is_ssl(),
					'transport_allowed' => $this->transport->is_secure(),
					'adapter'           => $this->gateway->diagnostics(),
					'ability_count'     => count( $this->catalog->all() ),
					'key_count'         => $this->keys->count_all(),
					'db_version'        => is_numeric( $version ) ? (int) $version : null,
					'audit_cron_next'   => false === $next ? null : gmdate( DATE_ATOM, $next ),
				)
			);
		} catch ( \Throwable ) {
			return $this->failure( 503 );
		}
	}

	/**
	 * Report header presence only; never return, hash or log any value.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function auth_header( WP_REST_Request $request ): WP_REST_Response {
		return $this->response(
			array(
				'authorization_present'          => isset( $_SERVER['HTTP_AUTHORIZATION'] ),
				'redirect_authorization_present' => isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
				'fallback_header_present'        => isset( $_SERVER['HTTP_X_XNETVN_API_KEY'] ),
			)
		);
	}

	/**
	 * Return reviewed tool metadata for the management catalog.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function tools( WP_REST_Request $request ): WP_REST_Response {
		$items = array();
		foreach ( $this->catalog->all() as $definition ) {
			$descriptor = $definition->descriptor();
			$items[]    = array(
				'name'           => $descriptor->name,
				'tool_name'      => $definition->tool_name(),
				'label'          => $definition->label(),
				'description'    => $definition->description(),
				'tier'           => $descriptor->tier->value,
				'toolset'        => $descriptor->toolset,
				'visibility_cap' => $descriptor->visibility_cap,
			);
		}
		return $this->response( $items );
	}

	/**
	 * Generate known-field schemas from security defaults; values are also service-validated.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function setting_args(): array {
		$args = array();
		foreach ( WpSiteSettings::DEFAULTS as $name => $default ) {
			if ( is_bool( $default ) ) {
				$args[ $name ] = array( 'type' => 'boolean' );
			} elseif ( is_int( $default ) ) {
				$args[ $name ] = array(
					'type'    => 'integer',
					'minimum' => WpSiteSettings::numeric_range( $name )[0],
					'maximum' => WpSiteSettings::numeric_range( $name )[1],
				);
			} elseif ( 'tier_ceiling' === $name ) {
				$args[ $name ] = array(
					'type' => 'string',
					'enum' => array( 'read', 'write', 'destructive', 'admin' ),
				);
			} else {
				$args[ $name ] = array(
					'type'     => null === $default ? array( 'array', 'null' ) : 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 191,
					),
				);
			}
		}
		return $args;
	}
}
