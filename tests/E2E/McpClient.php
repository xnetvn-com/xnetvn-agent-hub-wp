<?php
/**
 * Bounded test-only HTTP client for JSON-RPC and REST rejection evidence.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone HTTP evidence must not bootstrap WordPress or replace real transport with internal dispatch.

/** Never follows redirects, dumps request credentials or retains a bearer token. */
final readonly class McpClient {
	/** Reuse only connection/DNS state; reset all request options after every operation.
	 *
	 * @var \CurlHandle
	 */
	private \CurlHandle $curl;
	/**
	 * Bind only an explicitly allowed test host; self-signed trust is scoped to this instance.
	 *
	 * @param string        $base_url Owned test base.
	 * @param array<string> $allowed_hosts Reviewed hosts.
	 * @param bool          $self_signed Lab certificate exception.
	 * @throws \InvalidArgumentException For an unapproved destination.
	 * @throws \RuntimeException For unavailable cURL transport.
	 */
	public function __construct( private string $base_url, array $allowed_hosts, private bool $self_signed = false ) {
		$parts = parse_url( $base_url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || ! in_array( $parts['host'] ?? '', $allowed_hosts, true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			throw new \InvalidArgumentException( 'Unapproved test destination.' );
		}
		$curl = curl_init();
		if ( false === $curl ) {
			throw new \RuntimeException( 'Test transport unavailable.' );
		}
		$this->curl = $curl;
	}

	/**
	 * Execute one bounded request without exposing credentials in diagnostics.
	 *
	 * @param string               $path Relative route.
	 * @param string               $body JSON request.
	 * @param string|null          $token Optional bearer.
	 * @param array<string,string> $headers Extra request headers.
	 * @return array{status:int,headers:array<string,string>,body:array<string,mixed>|null,transport:array<string,float>}
	 * @throws \RuntimeException For a transport failure.
	 * @throws \InvalidArgumentException For a nonrelative path or invalid header.
	 */
	public function request( string $path, #[\SensitiveParameter] string $body, #[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $headers = array() ): array {
		if ( ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) ) {
			throw new \InvalidArgumentException( 'Invalid relative test route.' );
		}
		$request_headers = array( 'Content-Type: application/json', 'Accept: application/json, text/event-stream' );
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		foreach ( $headers as $name => $value ) {
			if ( 1 !== preg_match( '/\A[A-Za-z0-9-]+\z/', $name ) || strpbrk( $value, "\r\n" ) !== false ) {
				throw new \InvalidArgumentException( 'Invalid test header.' );
			}
			$request_headers[] = $name . ': ' . $value;
		}
		$curl = $this->curl;
		curl_reset( $curl );
		$response_headers = array();
		$response         = '';
		try {
			curl_setopt_array(
				$curl,
				array(
					CURLOPT_URL            => rtrim( $this->base_url, '/' ) . $path,
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_HTTPHEADER     => $request_headers,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_TIMEOUT        => 20,
					CURLOPT_SSL_VERIFYPEER => ! $this->self_signed,
					CURLOPT_SSL_VERIFYHOST => $this->self_signed ? 0 : 2,
					CURLOPT_HEADERFUNCTION => static function ( \CurlHandle $handle, string $line ) use ( &$response_headers ): int {
						if ( str_contains( $line, ':' ) ) {
							list($name, $value) = explode( ':', $line, 2 );
							$response_headers[ strtolower( trim( $name ) ) ] = trim( $value );
						}
						return strlen( $line );
					},
					CURLOPT_WRITEFUNCTION  => static function ( \CurlHandle $handle, string $chunk ) use ( &$response ): int {
						if ( strlen( $response ) + strlen( $chunk ) > 1048576 ) {
							return 0;
						}
						$response .= $chunk;
						return strlen( $chunk );
					},
				)
			);
			if ( false === curl_exec( $curl ) ) {
				throw new \RuntimeException( 'Test HTTP request did not complete.' );
			}
			$status  = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
			$type    = $response_headers['content-type'] ?? '';
			$decoded = $status >= 400 && ! str_contains( $type, 'json' ) && ! str_contains( $type, 'event-stream' ) ? null : self::decode( $response );
			return array(
				'status'    => $status,
				'headers'   => $response_headers,
				'body'      => $decoded,
				'transport' => array(
					'dns_ms'          => 1000 * (float) curl_getinfo( $curl, CURLINFO_NAMELOOKUP_TIME ),
					'connect_ms'      => 1000 * (float) curl_getinfo( $curl, CURLINFO_CONNECT_TIME ),
					'tls_ready_ms'    => 1000 * (float) curl_getinfo( $curl, CURLINFO_APPCONNECT_TIME ),
					'ttfb_ms'         => 1000 * (float) curl_getinfo( $curl, CURLINFO_STARTTRANSFER_TIME ),
					'curl_total_ms'   => 1000 * (float) curl_getinfo( $curl, CURLINFO_TOTAL_TIME ),
					'new_connections' => (float) curl_getinfo( $curl, CURLINFO_NUM_CONNECTS ),
				),
			);
		} finally {
			// Authorization, Cookie, test controls and POSTFIELDS are not retained in the handle.
			curl_reset( $curl );
		}
	}

	/**
	 * Decode a bounded JSON record or an SSE response frame.
	 *
	 * @param string $body Transport response.
	 * @return array<string,mixed>|null
	 * @throws \UnexpectedValueException For malformed framing.
	 */
	public static function decode( #[\SensitiveParameter] string $body ): ?array {
		if ( '' === trim( $body ) ) {
			return null;
		}
		if ( strlen( $body ) > 1048576 ) {
			throw new \UnexpectedValueException( 'Test response exceeded its budget.' );
		}
		if ( preg_match_all( '/^data: ?(.+)$/m', $body, $frames ) && $frames[1] ) {
			$body = end( $frames[1] );
		}
		try {
			$result = json_decode( $body, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \UnexpectedValueException( 'Malformed test response.' );
		}
		if ( ! is_array( $result ) || ! str_starts_with( ltrim( $body ), '{' ) ) {
			throw new \UnexpectedValueException( 'Test response is not a record.' );
		}
		return $result;
	}
}
