<?php
/**
 * Exact bearer-authentication boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Http;

/** Never applies credential authentication to a namespace prefix. */
final class RouteMatcher {
	/** Exact normalized WordPress REST route. */
	public const MCP_ROUTE = '/xnetvn-agent-hub-wp/mcp';
	/**
	 * Match a normalized route within an actual REST request.
	 *
	 * @param string $route WordPress REST route.
	 * @param bool   $is_rest REST request marker.
	 * @return bool
	 */
	public static function matches( string $route, bool $is_rest ): bool {
		return $is_rest && self::MCP_ROUTE === rtrim( $route, '/\\' );
	}
	/**
	 * Read the current WordPress routing boundary.
	 *
	 * @return bool
	 */
	public static function is_mcp_route(): bool {
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
		return self::matches( is_string( $route ) ? $route : '', defined( 'REST_REQUEST' ) && REST_REQUEST );
	}
}
