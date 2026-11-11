<?php
/**
 * Request-local audit buffering.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Audit;

use XNetVN\AgentHubWP\Contracts\AuditRepository;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\RequestContext;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Http\RouteMatcher;

/** Uses one bounded INSERT at shutdown and never retains arguments or tokens. */
final class AuditLogger implements ServiceProvider {
	/** Safe pending rows.
	 *
	 * @var array<array<string,mixed>>
	 */
	private array $events = array();
	/** Start timestamps and argument names.
	 *
	 * @var array<string,array{time:float,keys:array<mixed>}>
	 */
	private array $started = array();
	/** Generated correlation for anonymous failures.
	 *
	 * @var string|null
	 */
	private ?string $request_id = null;
	/** Whether the current protocol call was already recorded.
	 *
	 * @var bool
	 */
	private bool $call_recorded = false;
	/**
	 * Bind persistence and safe request identity.
	 *
	 * @param AuditRepository     $audit Persistence.
	 * @param RequestContext      $request Identity.
	 * @param SiteSettings        $settings Privacy policy.
	 * @param Clock               $clock UTC clock.
	 * @param AbilityCatalog|null $catalog Reviewed metadata for safe names.
	 */
	public function __construct( private AuditRepository $audit, private RequestContext $request, private SiteSettings $settings, private Clock $clock, private ?AbilityCatalog $catalog = null ) {
	}

	/** Subscribe without receiving credential or argument values. */
	public function register(): void {
		add_action( 'xnetvn_agent_hub_wp_tool_started', array( $this, 'started' ), 10, 2 );
		add_action( 'xnetvn_agent_hub_wp_tool_finished', array( $this, 'finished' ), 10, 2 );
		add_action( 'xnetvn_agent_hub_wp_tool_denied', array( $this, 'denied' ), 10, 2 );
		add_action( 'xnetvn_agent_hub_wp_auth_failed', array( $this, 'auth_failed' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'flush' ), 100 );
		add_filter( 'rest_pre_dispatch', array( $this, 'prepare' ), 40, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'observe' ), 90, 3 );
	}

	/**
	 * Capture only already-separated top-level names.
	 *
	 * @param string       $ability Canonical identity.
	 * @param array<mixed> $arg_keys Names.
	 */
	public function started( string $ability, array $arg_keys ): void {
		$this->started[ $ability ] = array(
			'time' => microtime( true ),
			'keys' => array_slice( $arg_keys, 0, 100 ),
		);
	}

	/**
	 * Finish a logical tool operation.
	 *
	 * @param string|null $ability Canonical identity, absent for unknown tools.
	 * @param string      $outcome Safe outcome.
	 */
	public function finished( ?string $ability, string $outcome ): void {
		$this->call_recorded = true;
		$start               = null === $ability ? null : ( $this->started[ $ability ] ?? null );
		if ( null !== $ability ) {
			unset( $this->started[ $ability ] );
		}
		$duration = null === $start ? null : min( 4294967295, (int) max( 0, round( ( microtime( true ) - $start['time'] ) * 1000 ) ) );
		$this->append( $ability, $outcome, $start['keys'] ?? array(), null, $duration );
	}

	/**
	 * Record a pre-execution denial without storing submitted values.
	 *
	 * @param string|null  $ability Approved identity only.
	 * @param array<mixed> $arg_keys Names only.
	 */
	public function denied( ?string $ability, array $arg_keys ): void {
		$this->call_recorded = true;
		$this->append( $ability, 'denied', $arg_keys );
	}

	/**
	 * Record a fixed authentication reason, never the presented credential.
	 *
	 * @param string      $reason Internal reason.
	 * @param string|null $peer Validated socket peer.
	 */
	public function auth_failed( string $reason, ?string $peer ): void {
		$this->call_recorded = true;
		$this->append( null, 'rate_limited' === $reason ? 'rate_limited' : 'auth_failed', array(), $reason, null, $peer );
	}

	/**
	 * Reset per-call deduplication before a dispatched protocol operation.
	 *
	 * @param mixed            $result Existing short circuit.
	 * @param \WP_REST_Server  $server REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function prepare( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null === $result && RouteMatcher::matches( $request->get_route(), true ) ) {
			$this->call_recorded = false;
		}
		return $result;
	}

	/**
	 * Cover protocol/schema failures that never reach the adapter call hook.
	 *
	 * @param \WP_REST_Response $response Serialized response.
	 * @param \WP_REST_Server   $server REST server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_REST_Response
	 */
	public function observe( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		if ( $this->call_recorded || ! RouteMatcher::matches( $request->get_route(), true ) ) {
			return $response;
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || 'tools/call' !== ( $payload['method'] ?? null ) ) {
			return $response;
		}
		$params              = is_array( $payload['params'] ?? null ) ? $payload['params'] : array();
		$name                = is_string( $params['name'] ?? null ) && str_starts_with( $params['name'], 'xnetvn-' ) ? 'xnetvn/' . substr( $params['name'], 7 ) : '';
		$known               = '' !== $name && null !== $this->catalog ? $this->catalog->get( $name ) : null;
		$keys                = is_array( $params['arguments'] ?? null ) ? array_keys( $params['arguments'] ) : array();
		$data                = json_decode( (string) wp_json_encode( $response->get_data() ), true );
		$code                = is_array( $data ) && is_array( $data['error'] ?? null ) ? ( $data['error']['code'] ?? null ) : null;
		$failed              = ! is_array( $data ) || isset( $data['error'] ) || true === ( $data['result']['isError'] ?? false ) || $response->get_status() >= 400;
		$this->call_recorded = true;
		$this->append( $known?->descriptor()->name, -32602 === $code ? 'invalid_input' : ( $failed ? 'error' : 'success' ), $keys );
		return $response;
	}

	/** Flush at most one INSERT; failure emits no SQL or raw exception. */
	public function flush(): void {
		$events              = $this->events;
		$this->events        = array();
		$this->started       = array();
		$this->request_id    = null;
		$this->call_recorded = false;
		if ( ! $events ) {
			return;
		}
		try {
			$this->audit->insert_batch( $events );
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_audit_unavailable' );
		}
	}

	/**
	 * Construct a whitelisted row and enforce the per-request memory bound.
	 *
	 * @param string|null  $ability Approved identity.
	 * @param string       $outcome Outcome.
	 * @param array<mixed> $arg_keys Argument names.
	 * @param string|null  $reason Reason.
	 * @param int|null     $duration Duration.
	 * @param string|null  $peer Explicit failure peer.
	 */
	private function append( ?string $ability, string $outcome, array $arg_keys, ?string $reason = null, ?int $duration = null, ?string $peer = null ): void {
		if ( count( $this->events ) >= 1000 ) {
			do_action( 'xnetvn_agent_hub_wp_audit_unavailable' );
			return;
		}
		try {
			$context          = $this->request->current();
			$this->request_id = $context->request_id ?? $this->request_id ?? bin2hex( random_bytes( 8 ) );
			$actor            = $context->user_id ?? ( 'stdio' === $this->request->transport() ? get_current_user_id() : 0 );
			$event            = new AuditEvent( gmdate( 'Y-m-d H:i:s', $this->clock->now() ), $this->request_id, $this->request->transport(), $outcome, $context?->key->key_id->value, $actor > 0 ? $actor : null, $ability, $reason, $duration, null, null === $ability ? null : 'tools/call' );
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- AuditEvent validates/masks the peer and bounds/redacts the user agent; neither affects authorization.
			$socket = $peer ?? ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null );
			$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( substr( $_SERVER['HTTP_USER_AGENT'], 0, 512 ) ) : '';
			// phpcs:enable
			$this->events[] = $event->row( $arg_keys, 'http' === $this->request->transport() ? $socket : null, $ua, true === $this->settings->get( 'anonymize_ip' ) );
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_audit_unavailable' );
		}
	}
}
