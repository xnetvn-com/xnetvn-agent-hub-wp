<?php
/**
 * Whitelisted audit metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Audit;

use XNetVN\AgentHubWP\Support\RequestMetadata;

/** Accepts identifiers and timings, never argument values or request headers. */
final readonly class AuditEvent {
	/**
	 * Bind trusted event metadata.
	 *
	 * @param string      $occurred_at UTC timestamp.
	 * @param string      $request_id Request identifier.
	 * @param string      $transport HTTP or STDIO.
	 * @param string      $outcome Fixed outcome.
	 * @param string|null $key_id Public key identifier.
	 * @param int|null    $user_id Actor.
	 * @param string|null $ability Canonical ability.
	 * @param string|null $error_code Safe internal reason.
	 * @param int|null    $duration_ms Duration.
	 * @param string|null $protocol_version Negotiated version.
	 * @param string|null $mcp_method Protocol method.
	 * @throws \InvalidArgumentException For invalid metadata.
	 */
	public function __construct(
		private string $occurred_at,
		private string $request_id,
		private string $transport,
		private string $outcome,
		private ?string $key_id = null,
		private ?int $user_id = null,
		private ?string $ability = null,
		private ?string $error_code = null,
		private ?int $duration_ms = null,
		private ?string $protocol_version = null,
		private ?string $mcp_method = null
	) {
		if ( 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $occurred_at ) || 1 !== preg_match( '/\A[a-f0-9]{16}\z/', $request_id ) || ! in_array( $transport, array( 'http', 'stdio' ), true ) || ! in_array( $outcome, array( 'success', 'denied', 'error', 'invalid_input', 'rate_limited', 'auth_failed' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid audit metadata.' );
		}
		if ( ( null !== $key_id && 1 !== preg_match( '/\A[a-z2-7]{12}\z/', $key_id ) ) || ( null !== $user_id && $user_id < 1 ) || ( null !== $ability && 1 !== preg_match( '/\Axnetvn\/[a-z][a-z0-9-]{0,120}\z/', $ability ) ) || ( null !== $error_code && 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $error_code ) ) || ( null !== $duration_ms && $duration_ms < 0 ) || ( null !== $protocol_version && 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $protocol_version ) ) || ( null !== $mcp_method && ! in_array( $mcp_method, array( 'initialize', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'prompts/list', 'prompts/get' ), true ) ) ) {
			throw new \InvalidArgumentException( 'Invalid audit identity.' );
		}
	}

	/**
	 * Produce a safe persistence row.
	 *
	 * @param array<mixed> $arg_keys Top-level names only.
	 * @param string|null  $ip Socket peer.
	 * @param string       $ua User-agent metadata.
	 * @param bool         $anonymize Mask IP addresses.
	 * @return array<string,mixed>
	 */
	public function row( array $arg_keys, ?string $ip, string $ua, bool $anonymize ): array {
		$names = array();
		foreach ( array_slice( $arg_keys, 0, 100 ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/\A[a-zA-Z][a-zA-Z0-9_-]{0,99}\z/', $name ) && ! str_starts_with( $name, 'xnv_' ) && ! in_array( strtolower( $name ), array( 'authorization', 'password', 'current_password', 'secret', 'secret_hash', 'token', 'access_token', 'api_key', 'pepper', 'cookie' ), true ) ) {
				$names[ $name ] = true;
			}
		}
		ksort( $names, SORT_STRING );
		return array(
			'occurred_at'      => $this->occurred_at,
			'request_id'       => $this->request_id,
			'key_id'           => $this->key_id,
			'user_id'          => $this->user_id,
			'transport'        => $this->transport,
			'protocol_version' => $this->protocol_version,
			'mcp_method'       => $this->mcp_method,
			'ability'          => $this->ability,
			'outcome'          => $this->outcome,
			'error_code'       => $this->error_code,
			'duration_ms'      => $this->duration_ms,
			'ip'               => RequestMetadata::ip( $ip, $anonymize ),
			'ua_short'         => RequestMetadata::user_agent( $ua ),
			'arg_keys'         => array_keys( $names ),
		);
	}
}
