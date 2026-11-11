<?php
/**
 * Sole facade for the separately installed official MCP Adapter.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Mcp;

use Closure;
use WP_Error;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\TokenParser;
use XNetVN\AgentHubWP\Bootstrap\Notice;
use XNetVN\AgentHubWP\Bootstrap\Requirements;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Policy\CapabilityMemo;

/** Validates dynamic calls at the external plugin boundary using verified0.7 signatures. */
final class ServerGateway implements ServiceProvider {
	/** Stable server identity. */
	public const SERVER_ID = 'xnetvn-agent-hub-wp';
	/** Bounded HTTP payload; media upload limits remain stricter in its pack. */
	private const MAX_INPUT_BYTES = 16777216;
	/** Runtime version reader.
	 *
	 * @var Closure():?string
	 */
	private Closure $version;
	/** Whether server creation succeeded.
	 *
	 * @var bool
	 */
	private bool $created = false;
	/**
	 * Bind the reviewed registry and current request boundary.
	 *
	 * @param Registry             $registry Authoritative definitions.
	 * @param RequestContext       $request Safe actor identity.
	 * @param SiteSettings         $settings Site policy.
	 * @param Closure|null         $version Version reader for isolated tests.
	 * @param KeyRepository|null   $keys Optional credential store for the scope hint.
	 * @param HeaderExtractor|null $headers Optional credential header reader for the scope hint.
	 * @phpstan-param Closure():?string|null $version
	 */
	public function __construct( private Registry $registry, private RequestContext $request, private SiteSettings $settings, ?Closure $version = null, private ?KeyRepository $keys = null, private ?HeaderExtractor $headers = null ) {
		$this->version = $version ?? static function (): ?string {
			return defined( 'WP_MCP_VERSION' ) && is_string( WP_MCP_VERSION ) ? WP_MCP_VERSION : null;
		};
	}
	/** Register only versioned facade hooks; other adapter servers remain unaffected. */
	public function register(): void {
		add_action( 'mcp_adapter_init', array( $this, 'create' ) );
		add_filter( 'mcp_adapter_tools_list', array( $this, 'tools_list' ), 10, 3 );
		add_filter( 'mcp_adapter_pre_tool_call', array( $this, 'pre_call' ), 10, 4 );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'tool_result' ), 10, 5 );
		add_filter( 'mcp_adapter_create_default_server', array( $this, 'default_server' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'input_guard' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'normalize_denied' ), 20, 3 );
		add_action( 'admin_init', array( $this, 'dependency_notice' ) );
	}
	/**
	 * Read dependency status through the sole adapter boundary.
	 *
	 * @return array{version:?string,supported:bool,server_created:bool}
	 */
	public function diagnostics(): array {
		$version = ( $this->version )();
		return array(
			'version'        => $version,
			'supported'      => ! Requirements::adapter_failures( $version ),
			'server_created' => $this->created,
		);
	}
	/**
	 * Create the exact server only when the verified runtime is compatible.
	 *
	 * @param object $adapter Installed adapter registry.
	 */
	public function create( object $adapter ): void {
		$failures = Requirements::adapter_failures( ( $this->version )() );
		if ( $failures || ! is_callable( array( $adapter, 'create_server' ) ) ) {
			Notice::register(
				$failures ? $failures : array(
					array(
						'code'     => 'adapter_missing',
						'required' => 'verified MCP Adapter API',
						'actual'   => 'unavailable',
					),
				)
			);
			return;
		}
		try {
			if ( true !== $this->settings->get( 'enabled' ) ) {
				return;
			}
			$result        = call_user_func_array(
				array( $adapter, 'create_server' ),
				array(
					self::SERVER_ID,
					'xnetvn-agent-hub-wp',
					'mcp',
					'xNetVN Agent Hub WP',
					'Private WordPress abilities with user capability and delegated-key scope enforcement.',
					XNETVN_AGENT_HUB_WP_VERSION,
					array( 'WP\\MCP\\Transport\\HttpTransport' ),
					null,
					null,
					$this->tool_names(),
					array(),
					array(),
					fn(): bool => null !== $this->request->current() && get_current_user_id() === $this->request->current()->user_id,
				)
			);
			$this->created = ! is_wp_error( $result );
		} catch ( \Throwable ) {
			$this->created = false;
		}
	}
	/**
	 * Advertise only the tool objects the current request can use.
	 *
	 * The server itself is created for every REST request so the route stays published,
	 * but the tool objects are only useful on the exact MCP route. Outside that route the
	 * list stays empty; on it the list is narrowed to the presented delegation scope. A
	 * STDIO server has no route at all and keeps the complete catalog.
	 *
	 * @return array<string>
	 */
	private function tool_names(): array {
		if ( 'http' !== $this->request->transport() ) {
			return $this->registry->names();
		}
		if ( ! RouteMatcher::is_mcp_route() ) {
			return array();
		}
		$scope = $this->scope_hint();
		return null === $scope ? $this->registry->names() : $this->registry->names_for_scope( $scope );
	}
	/**
	 * Read the delegation scope of the presented credential without authorizing it.
	 *
	 * The hint only narrows the advertised tool list, so an unknown, malformed or
	 * unauthorized credential falls back to the complete catalog. The authoritative
	 * decision stays in {@see Registry::allowed()} for every advertised tool.
	 *
	 * @return Scope|null
	 */
	private function scope_hint(): ?Scope {
		if ( null === $this->keys || null === $this->headers ) {
			return null;
		}
		$token = $this->headers->bearer();
		if ( null === $token ) {
			return null;
		}
		$parsed = TokenParser::parse( $token );
		if ( null === $parsed ) {
			return null;
		}
		$stored = $this->keys->find_by_key_id( $parsed->id );
		return null === $stored ? null : $stored->key->scope;
	}
	/** Emit a deferred diagnostic when the separate dependency is unavailable. */
	public function dependency_notice(): void {
		$failures = Requirements::adapter_failures( ( $this->version )() );
		if ( $failures ) {
			Notice::register( $failures );
		}
	}
	/**
	 * Report definite server creation status.
	 *
	 * @return bool
	 */
	public function ready(): bool {
		return $this->created;
	}
	/**
	 * Filter only this server's schema Tool records and sort by exact wire name.
	 *
	 * One advertised list round uses one request-local capability memo, so a repeated
	 * capability is checked live once for its user, capability and object. The memo never
	 * leaves this call: a later round, a tool call and every other decision path check the
	 * capability live again.
	 *
	 * @param array<mixed> $tools Tool records.
	 * @param object       $server Adapter server.
	 * @param object       $schema Selected protocol schema.
	 * @return array<mixed>
	 */
	public function tools_list( array $tools, object $server, object $schema ): array {
		if ( ! $this->owned( $server ) ) {
			return $tools;
		}
		$memo   = new CapabilityMemo();
		$result = array();
		foreach ( $tools as $tool ) {
			if ( ! is_object( $tool ) || ! is_callable( array( $tool, 'getName' ) ) ) {
				continue;
			}
			$name       = call_user_func( array( $tool, 'getName' ) );
			$definition = is_string( $name ) ? $this->registry->for_tool( $name ) : null;
			if ( null !== $definition && $this->registry->allowed( $definition, $memo ) ) {
				$result[ $name ] = $tool;
			}
		}
		ksort( $result, SORT_STRING );
		return array_values( $result );
	}
	/**
	 * Recheck scoped access immediately before a direct tool call.
	 *
	 * @param mixed  $arguments Validated arguments or prior denial.
	 * @param string $name Tool name.
	 * @param object $tool Tool object.
	 * @param object $server Adapter server.
	 * @return mixed
	 */
	public function pre_call( mixed $arguments, string $name, object $tool, object $server ): mixed {
		if ( ! $this->owned( $server ) || is_wp_error( $arguments ) ) {
			return $arguments;
		}
		$definition = $this->registry->for_tool( $name );
		if ( null === $definition || ! $this->registry->allowed( $definition ) || ! is_array( $arguments ) ) {
			do_action( 'xnetvn_agent_hub_wp_tool_denied', $definition?->descriptor()->name, is_array( $arguments ) ? array_keys( $arguments ) : array() );
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		do_action( 'xnetvn_agent_hub_wp_tool_started', $definition->descriptor()->name, array_keys( $arguments ) );
		return $arguments;
	}
	/**
	 * Keep the verified adapter raw-result contract and finalize safe audit metadata.
	 *
	 * @param mixed               $result Logical result.
	 * @param array<string,mixed> $arguments Arguments, values never logged.
	 * @param string              $name Tool name.
	 * @param object              $tool Adapter tool.
	 * @param object              $server Adapter server.
	 * @return mixed
	 */
	public function tool_result( mixed $result, array $arguments, string $name, object $tool, object $server ): mixed {
		if ( ! $this->owned( $server ) ) {
			return $result;
		}
		$definition = $this->registry->for_tool( $name );
		do_action( 'xnetvn_agent_hub_wp_tool_finished', null === $definition ? null : $definition->descriptor()->name, is_wp_error( $result ) ? 'error' : 'success' );
		return is_wp_error( $result ) ? \XNetVN\AgentHubWP\Abilities\ReasonMapper::sanitize( $result ) : $result;
	}
	/**
	 * Preserve the default server unless explicitly disabled by site policy.
	 *
	 * @param bool $enabled Upstream default.
	 * @return bool
	 */
	public function default_server( bool $enabled ): bool {
		try {
			return $enabled && true !== $this->settings->get( 'disable_adapter_default_server' );
		} catch ( \Throwable ) {
			return $enabled;
		}
	}
	/**
	 * Bound request bytes/depth before adapter execution without interpreting credentials.
	 *
	 * @param mixed            $result Prior dispatch result.
	 * @param \WP_REST_Server  $server REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function input_guard( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( ! RouteMatcher::matches( $request->get_route(), true ) || null !== $result ) {
			return $result;
		}
		$body = $request->get_body();
		if ( strlen( $body ) > self::MAX_INPUT_BYTES ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_payload_limit', 'Request payload exceeds the limit.', array( 'status' => 413 ) );
		}
		if ( '' !== $body ) {
			try {
				json_decode( $body, false, 32, JSON_THROW_ON_ERROR );
			} catch ( \JsonException ) {
				return new WP_Error( 'xnetvn_agent_hub_wp_invalid_payload', 'Request payload is invalid.', array( 'status' => 400 ) );
			}
		}
		return $result;
	}
	/**
	 * Normalize hidden/unknown tool outcomes after upstream wire validation.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server REST server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_REST_Response
	 */
	public function normalize_denied( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! RouteMatcher::matches( $request->get_route(), true ) || ! in_array( $response->get_status(), array( 200, 400 ), true ) ) {
			return $response;
		}
		$body = json_decode( $request->get_body(), true, 32 );
		if ( ! is_array( $body ) || 'tools/call' !== ( $body['method'] ?? null ) || ! isset( $body['params']['name'] ) || ! is_string( $body['params']['name'] ) ) {
			return $response;
		}
		$definition = $this->registry->for_tool( $body['params']['name'] );
		if ( null === $definition || ! $this->registry->allowed( $definition ) ) {
			$data = $response->get_data();
			if ( ! is_array( $data ) ) {
				$encoded = wp_json_encode( $data );
				$data    = is_string( $encoded ) ? json_decode( $encoded, true, 32 ) : null;
			}
			if ( is_array( $data ) && isset( $data['id'] ) && ( $this->unavailable_wire_error( $data, $body['params']['name'] ) || isset( $data['result'] ) ) ) {
				$response->set_status( 200 );
				$response->set_data(
					array(
						'jsonrpc' => '2.0',
						'id'      => $data['id'],
						'result'  => ErrorMapper::unavailable(),
					)
				);
			}
		}
		return $response;
	}
	/**
	 * Match only the installed adapter's translated unavailable-tool envelope.
	 *
	 * @param array<string,mixed> $data Wire response.
	 * @param string              $name Requested tool.
	 * @return bool
	 */
	private function unavailable_wire_error( array $data, string $name ): bool {
		$factory = 'WP\\MCP\\Infrastructure\\ErrorHandling\\McpErrorFactory';
		if ( ! is_callable( array( $factory, 'tool_not_found' ) ) || ! isset( $data['error'] ) || ! is_array( $data['error'] ) ) {
			return false;
		}
		try {
			$expected = call_user_func( array( $factory, 'tool_not_found' ), $data['id'], $name );
			return is_array( $expected ) && ( $expected['error']['code'] ?? null ) === ( $data['error']['code'] ?? null ) && ( $expected['error']['message'] ?? null ) === ( $data['error']['message'] ?? null );
		} catch ( \Throwable ) {
			return false;
		}
	}
	/**
	 * Identify this facade's server without importing adapter types elsewhere.
	 *
	 * @param object $server Adapter server.
	 * @return bool
	 */
	private function owned( object $server ): bool {
		return is_callable( array( $server, 'get_server_id' ) ) && self::SERVER_ID === call_user_func( array( $server, 'get_server_id' ) );
	}
}
