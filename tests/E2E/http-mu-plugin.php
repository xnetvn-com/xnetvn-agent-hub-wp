<?php
/**
 * Disposable local-environment HTTP instrumentation, never distributed in a release.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! get_option( 'xnetvn_agent_hub_wp_http_fixture', false ) ) {
	return;
}

use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\SchemaFactory;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Http\RequestScope;

/** Validate an ephemeral test-control header without logging its value. */
function xnetvn_agent_hub_wp_http_fixture_control(): bool {
	$state = get_option( 'xnetvn_agent_hub_wp_http_fixture', array() );
	$input = $_SERVER['HTTP_X_XNETVN_TEST_CONTROL'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Exact constant-time test marker validation below.
	return is_array( $state ) && isset( $state['control'] ) && is_string( $state['control'] ) && is_string( $input ) && hash_equals( $state['control'], $input );
}

if ( xnetvn_agent_hub_wp_http_fixture_control() && 'unsupported' === ( $_SERVER['HTTP_X_XNETVN_TEST_ADAPTER'] ?? '' ) ) {
	define( 'WP_MCP_VERSION', '0.8.0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Test-only dependency version before ordinary plugins load.
	add_filter( 'option_active_plugins', static fn( array $plugins ): array => array_values( array_diff( $plugins, array( 'mcp-adapter/mcp-adapter.php' ) ) ) );
}

$GLOBALS['xnetvn_agent_hub_wp_http_key_lookups'] = 0;
add_filter(
	'query',
	static function ( string $sql ): string {
		if ( 0 === stripos( ltrim( $sql ), 'SELECT ' ) && str_contains( $sql, 'xnetvn_agent_hub_wp_keys' ) && str_contains( $sql, 'key_id' ) ) {
			++$GLOBALS['xnetvn_agent_hub_wp_http_key_lookups'];
		}
		return $sql;
	}
);

add_action(
	'xnetvn_agent_hub_wp_loaded',
	static function ( object $kernel ): void {
		// Test-only inspection preserves the production kernel's private API until the extension milestone.
		$providers = ( new ReflectionProperty( $kernel, 'providers' ) )->getValue( $kernel );
		$registry  = null;
		$request   = null;
		$gateway   = null;
		foreach ( $providers as $provider ) {
			if ( $provider instanceof Registry ) {
				$registry = $provider;
			}
			if ( $provider instanceof \XNetVN\AgentHubWP\Auth\Authenticator ) {
				$request = ( new ReflectionProperty( $provider, 'request' ) )->getValue( $provider );
			}
			if ( $provider instanceof \XNetVN\AgentHubWP\Mcp\ServerGateway ) {
				$gateway = $provider;
			}
		}
		if ( ! $registry instanceof Registry || ! $request instanceof RequestScope ) {
			return;
		}
		add_action(
			'rest_api_init',
			static function () use ( $gateway ): void {
				register_rest_route(
					'xnetvn-agent-hub-wp-test/v1',
					'/adapter',
					array(
						'methods'             => 'POST',
						'permission_callback' => static function ( WP_REST_Request $http ): bool {
							return xnetvn_agent_hub_wp_http_fixture_control() && true === ( new \XNetVN\AgentHubWP\Rest\ManagementGate() )->check( $http ) && current_user_can( 'manage_options' );
						},
						'callback'            => static function () use ( $gateway ): array {
							if ( $gateway instanceof \XNetVN\AgentHubWP\Mcp\ServerGateway ) {
								$gateway->dependency_notice(); }
							// This controlled REST diagnostic exercises admin notices; load the core
							// screen helpers that real wp-admin normally provides to all listeners.
							require_once ABSPATH . 'wp-admin/includes/screen.php';
							ob_start();
							try {
								do_action( 'admin_notices' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise real core notice lifecycle.
								$notice = (string) ob_get_contents();
							} finally {
								ob_end_clean(); }
							return array(
								'base_loaded'        => did_action( 'xnetvn_agent_hub_wp_loaded' ) > 0,
								'mcp_present'        => isset( rest_get_server()->get_routes()['/xnetvn-agent-hub-wp/mcp'] ),
								'unsupported_notice' => str_contains( $notice, 'MCP is disabled' ) && str_contains( $notice, '0.8.0' ),
							);
						},
					)
				);
			}
		);
		add_filter(
			'rest_post_dispatch',
			static function ( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $http ) use ( $request ): WP_REST_Response {
				$case = $_SERVER['HTTP_X_XNETVN_TEST_CLIENT'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Fixed allowlist and ephemeral control below.
				if ( '/xnetvn-agent-hub-wp/mcp' === $http->get_route() && xnetvn_agent_hub_wp_http_fixture_control() && in_array( $case, array( 'inspector', 'fastmcp' ), true ) ) {
					$evidence          = get_option( 'xnetvn_agent_hub_wp_http_client_denials', array() );
					$evidence[ $case ] = array(
						'status'    => $response->get_status(),
						'revoked'   => \XNetVN\AgentHubWP\Auth\FailureReason::Revoked === $request->failure,
						'anonymous' => null === $request->context && 0 === get_current_user_id(),
					);
					update_option( 'xnetvn_agent_hub_wp_http_client_denials', $evidence, false );
				}
				return $response;
			},
			100,
			3
		);
		$factory = new DefinitionFactory();
		// delete-user squats a reviewed ADMIN name with no real pack yet (D4 deferred) so the fixture passes AbilityDefinition checks without colliding.
		foreach ( array(
			'fixture-read'  => RiskTier::Read,
			'fixture-write' => RiskTier::Write,
			'fixture-bulk'  => RiskTier::Write,
			'delete-user'   => RiskTier::Admin,
		) as $name => $tier ) {
			$properties = 'fixture-bulk' === $name ? array(
				'items' => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array( 'type' => 'integer' ),
				),
			) : array(
				'message' => array(
					'type'      => 'string',
					'maxLength' => 1024,
				),
			);
			$registry->add(
				$factory->create(
					array(
						'name'          => 'xnetvn/' . $name,
						'label'         => 'HTTP matrix fixture',
						'description'   => 'Use only to verify HTTP permission and schema boundaries in the disposable test lab.',
						'inputSchema'   => SchemaFactory::object( $properties ),
						'outputSchema'  => SchemaFactory::object( array( 'ok' => array( 'type' => 'boolean' ) ), array( 'ok' ), true ),
						'tier'          => $tier,
						'toolset'       => 'fixture',
						'costly'        => 'fixture-bulk' === $name,
						'visibilityCap' => RiskTier::Admin === $tier ? 'manage_options' : 'use_xnetvn_agent_hub_wp',
						'annotations'   => array(
							'readonly'    => RiskTier::Read === $tier,
							'destructive' => RiskTier::Admin === $tier,
							'idempotent'  => true,
						),
						'handler'       => static function () use ( $name ): array {
							$counts = get_option( 'xnetvn_agent_hub_wp_http_execution_counts', array() );
							$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
							update_option( 'xnetvn_agent_hub_wp_http_execution_counts', $counts, false );
							return array( 'ok' => true );
						},
						'example'       => array( 'ok' => true ),
					)
				)
			);
		}
		add_filter(
			'rest_pre_dispatch',
			static function ( mixed $result ) use ( $request ): mixed {
				if ( xnetvn_agent_hub_wp_http_fixture_control() && '1' === ( $_SERVER['HTTP_X_XNETVN_TEST_NO_CONTEXT'] ?? '' ) ) {
					$request->context = null;
				}
				return $result;
			},
			6
		);
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( xnetvn_agent_hub_wp_http_fixture_control() && '1' === ( $_SERVER['HTTP_X_XNETVN_TEST_CACHE_COOKIE'] ?? '' ) ) {
			$GLOBALS['xnetvn_agent_hub_wp_http_cached_user'] = wp_get_current_user()->ID;
		}
	},
	0
);

add_filter(
	'rest_post_dispatch',
	static function ( WP_REST_Response $response ): WP_REST_Response {
		if ( xnetvn_agent_hub_wp_http_fixture_control() ) {
			$response->header( 'X-Xnetvn-Test-Authorization-Present', isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? '1' : '0' );
			$response->header( 'X-Xnetvn-Test-Authorization-Empty', '' === ( $_SERVER['HTTP_AUTHORIZATION'] ?? null ) ? '1' : '0' );
			$response->header( 'X-Xnetvn-Test-Fallback-Present', isset( $_SERVER['HTTP_X_XNETVN_API_KEY'] ) ? '1' : '0' );
			$response->header( 'X-Xnetvn-Test-Cached-Cookie-User', (string) ( $GLOBALS['xnetvn_agent_hub_wp_http_cached_user'] ?? 0 ) );
			$response->header( 'X-Xnetvn-Test-Key-Lookups', (string) $GLOBALS['xnetvn_agent_hub_wp_http_key_lookups'] );
		}
		return $response;
	}
);
