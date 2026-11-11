<?php
/**
 * Controlled read-only load and timing instrumentation for the disposable lab.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) || ! get_option( 'xnetvn_agent_hub_wp_http_fixture', false ) ) {
	return;
}

use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaFactory;
use XNetVN\AgentHubWP\Domain\RiskTier;

$GLOBALS['xnetvn_agent_hub_wp_benchmark_memory']          = memory_get_usage();
$GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_ns']         = 0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_observed']   = 0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start']   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_mu_load_ms']      = 0.0 < $GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start']
	? ( microtime( true ) - $GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start'] ) * 1000
	: 0.0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_bootstrap_ms']    = 0.0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_plugins']  = memory_get_usage();
$GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_plugins']    = memory_get_peak_usage();
$GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_plugins'] = count( get_declared_classes() );
$GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_init']     = 0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_init']       = 0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_init']    = 0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_init_ms']         = 0.0;
$GLOBALS['xnetvn_agent_hub_wp_benchmark_pre_dispatch_ms'] = 0.0;

add_action(
	'plugins_loaded',
	static function (): void {
		$start = $GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start'];
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_bootstrap_ms']    = 0.0 < $start ? ( microtime( true ) - $start ) * 1000 : 0.0;
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_plugins']  = memory_get_usage();
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_plugins']    = memory_get_peak_usage();
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_plugins'] = count( get_declared_classes() );
	},
	0
);
add_action(
	'init',
	static function (): void {
		// Priority 1 runs just after the ability packs are loaded at priority 0.
		$start = $GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start'];
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_init']  = memory_get_usage();
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_init']    = memory_get_peak_usage();
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_init'] = count( get_declared_classes() );
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_init_ms']      = 0.0 < $start ? ( microtime( true ) - $start ) * 1000 : 0.0;
	},
	1
);
foreach ( array(
	'determine_current_user'     => 20,
	'rest_authentication_errors' => 80,
) as $xnetvn_agent_hub_wp_hook => $xnetvn_agent_hub_wp_priority ) {
	add_filter(
		$xnetvn_agent_hub_wp_hook,
		static function ( mixed $value ): mixed {
			$GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_start'] = hrtime( true );
			return $value;
		},
		$xnetvn_agent_hub_wp_priority - 1
	);
	add_filter(
		$xnetvn_agent_hub_wp_hook,
		static function ( mixed $value ): mixed {
			$GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_ns'] += hrtime( true ) - $GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_start'];
			++$GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_observed'];
			return $value;
		},
		$xnetvn_agent_hub_wp_priority + 1
	);
}
add_filter(
	'determine_current_user',
	static function ( mixed $value ): mixed {
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_user_during_rest_init'] = (bool) doing_action( 'rest_api_init' );
		return $value;
	},
	1
);
add_action(
	'xnetvn_agent_hub_wp_loaded',
	static function ( object $kernel ): void {
		foreach ( ( new ReflectionProperty( $kernel, 'providers' ) )->getValue( $kernel ) as $provider ) {
			if ( ! $provider instanceof Registry ) {
				continue;
			}
			// Five system descriptors and the existing read fixture make six visible tools.
			for ( $i = 0; $i < 54; ++$i ) {
				$provider->add(
					( new DefinitionFactory() )->create(
						array(
							'name'          => sprintf( 'xnetvn/benchmark-read-%02d', $i ),
							'label'         => 'Benchmark read fixture',
							'description'   => 'Read-only synthetic descriptor for a controlled sixty-tool benchmark.',
							'inputSchema'   => SchemaFactory::object( array() ),
							'outputSchema'  => SchemaFactory::object( array( 'ok' => array( 'type' => 'boolean' ) ), array( 'ok' ), true ),
							'tier'          => RiskTier::Read,
							'toolset'       => 'fixture',
							'visibilityCap' => 'use_xnetvn_agent_hub_wp',
							'annotations'   => array(
								'readonly'    => true,
								'destructive' => false,
								'idempotent'  => true,
							),
							'handler'       => static fn(): array => array( 'ok' => true ),
							'example'       => array( 'ok' => true ),
						)
					)
				);
			}
		}
	}
);

add_filter(
	'mcp_adapter_tools_list',
	static function ( array $tools ): array {
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_policy_start'] = hrtime( true );
		return $tools;
	},
	9
);
add_filter(
	'mcp_adapter_tools_list',
	static function ( array $tools ): array {
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_policy_ns'] = hrtime( true ) - $GLOBALS['xnetvn_agent_hub_wp_benchmark_policy_start'];
		return $tools;
	},
	11
);
add_filter(
	'rest_pre_dispatch',
	static function ( mixed $result ): mixed {
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_dispatch_start'] = hrtime( true );
		$start = $GLOBALS['xnetvn_agent_hub_wp_benchmark_request_start'];
		$GLOBALS['xnetvn_agent_hub_wp_benchmark_pre_dispatch_ms'] = 0.0 < $start ? ( microtime( true ) - $start ) * 1000 : 0.0;
		return $result;
	},
	0
);
$GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'] = array();
foreach ( array( 'rest_api_init', 'wp_abilities_api_init', 'wp_abilities_api_categories_init', 'mcp_adapter_init' ) as $xnetvn_agent_hub_wp_probe_hook ) {
	add_action(
		$xnetvn_agent_hub_wp_probe_hook,
		static function () use ( $xnetvn_agent_hub_wp_probe_hook ): void {
			$GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'][ $xnetvn_agent_hub_wp_probe_hook . '_start' ] = hrtime( true );
		},
		1
	);
	add_action(
		$xnetvn_agent_hub_wp_probe_hook,
		static function () use ( $xnetvn_agent_hub_wp_probe_hook ): void {
			$key = $xnetvn_agent_hub_wp_probe_hook . '_start';
			if ( isset( $GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'][ $key ] ) ) {
				$GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'][ $xnetvn_agent_hub_wp_probe_hook . '_ns' ] = hrtime( true ) - $GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'][ $key ];
			}
		},
		9999
	);
}
add_filter(
	'rest_post_dispatch',
	static function ( WP_REST_Response $response ): WP_REST_Response {
		if ( function_exists( 'xnetvn_agent_hub_wp_http_fixture_control' ) && xnetvn_agent_hub_wp_http_fixture_control() ) {
			if ( $GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_observed'] > 0 ) {
				$response->header( 'X-Xnetvn-Benchmark-Auth-Ms', (string) ( $GLOBALS['xnetvn_agent_hub_wp_benchmark_auth_ns'] / 1000000 ) );
			}
			if ( isset( $GLOBALS['xnetvn_agent_hub_wp_benchmark_policy_ns'] ) ) {
				$response->header( 'X-Xnetvn-Benchmark-Policy-Ms', (string) ( $GLOBALS['xnetvn_agent_hub_wp_benchmark_policy_ns'] / 1000000 ) );
			}
			if ( isset( $GLOBALS['xnetvn_agent_hub_wp_benchmark_dispatch_start'] ) ) {
				$response->header( 'X-Xnetvn-Benchmark-Dispatch-Ms', (string) ( ( hrtime( true ) - $GLOBALS['xnetvn_agent_hub_wp_benchmark_dispatch_start'] ) / 1000000 ) );
			}
			$response->header( 'X-Xnetvn-Benchmark-Memory-Bytes', (string) max( 0, memory_get_peak_usage() - $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory'] ) );
			$response->header( 'X-Xnetvn-Benchmark-Memory-Baseline-Bytes', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory'] );
			$response->header( 'X-Xnetvn-Benchmark-Memory-Plugins-Bytes', (string) max( 0, $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_plugins'] - $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory'] ) );
			$response->header( 'X-Xnetvn-Benchmark-Peak-Plugins-Bytes', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_plugins'] );
			$response->header( 'X-Xnetvn-Benchmark-Memory-Init-Bytes', (string) max( 0, $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_init'] - $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_plugins'] ) );
			$response->header( 'X-Xnetvn-Benchmark-Peak-Init-Bytes', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_init'] );
			$response->header( 'X-Xnetvn-Benchmark-Memory-Request-Bytes', (string) max( 0, memory_get_peak_usage() - $GLOBALS['xnetvn_agent_hub_wp_benchmark_memory_init'] ) );
			$response->header( 'X-Xnetvn-Benchmark-Peak-Request-Bytes', (string) max( 0, memory_get_peak_usage() - $GLOBALS['xnetvn_agent_hub_wp_benchmark_peak_init'] ) );
			$response->header( 'X-Xnetvn-Benchmark-Bootstrap-Ms', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_bootstrap_ms'] );
			$response->header( 'X-Xnetvn-Benchmark-Init-Ms', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_init_ms'] );
			$response->header( 'X-Xnetvn-Benchmark-Pre-Dispatch-Ms', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_pre_dispatch_ms'] );
			$response->header( 'X-Xnetvn-Benchmark-Mu-Load-Ms', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_mu_load_ms'] );
			$response->header( 'X-Xnetvn-Benchmark-Declared-Classes', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_plugins'] );
			$response->header( 'X-Xnetvn-Benchmark-Declared-Classes-Init', (string) $GLOBALS['xnetvn_agent_hub_wp_benchmark_classes_init'] );
			$response->header( 'X-Xnetvn-Benchmark-Declared-Classes-Request', (string) count( get_declared_classes() ) );
			$response->header( 'X-Xnetvn-Benchmark-Persistent-Cache', wp_using_ext_object_cache() ? '1' : '0' );
			$response->header( 'X-Xnetvn-Benchmark-WordPress', get_bloginfo( 'version' ) );
			$response->header( 'X-Xnetvn-Benchmark-PHP', PHP_VERSION );
			$response->header( 'X-Xnetvn-Benchmark-Adapter', defined( 'WP_MCP_VERSION' ) && is_string( WP_MCP_VERSION ) ? WP_MCP_VERSION : 'unavailable' );
			$xnetvn_agent_hub_wp_phase = $GLOBALS['xnetvn_agent_hub_wp_benchmark_phase'] ?? array();
			foreach ( $xnetvn_agent_hub_wp_phase as $xnetvn_agent_hub_wp_name => $xnetvn_agent_hub_wp_value ) {
				if ( str_ends_with( (string) $xnetvn_agent_hub_wp_name, '_ns' ) ) {
					$response->header( 'X-Xnetvn-Benchmark-Phase-' . str_replace( '_', '-', substr( (string) $xnetvn_agent_hub_wp_name, 0, -3 ) ) . '-Ms', (string) ( $xnetvn_agent_hub_wp_value / 1000000 ) );
				}
			}
			$xnetvn_agent_hub_wp_all  = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
			$xnetvn_agent_hub_wp_ours = 0;
			foreach ( $xnetvn_agent_hub_wp_all as $xnetvn_agent_hub_wp_ability ) {
				if ( is_object( $xnetvn_agent_hub_wp_ability ) && is_callable( array( $xnetvn_agent_hub_wp_ability, 'get_name' ) ) && 0 === strpos( (string) call_user_func( array( $xnetvn_agent_hub_wp_ability, 'get_name' ) ), 'xnetvn/' ) ) {
					++$xnetvn_agent_hub_wp_ours;
				}
			}
			$response->header( 'X-Xnetvn-Benchmark-Probe-Abilities-Total', (string) count( $xnetvn_agent_hub_wp_all ) );
			$response->header( 'X-Xnetvn-Benchmark-Probe-Abilities-Ours', (string) $xnetvn_agent_hub_wp_ours );
			$response->header( 'X-Xnetvn-Benchmark-Probe-User-During-Rest-Init', ! empty( $GLOBALS['xnetvn_agent_hub_wp_benchmark_user_during_rest_init'] ) ? '1' : '0' );
		}
		return $response;
	},
	PHP_INT_MAX
);
