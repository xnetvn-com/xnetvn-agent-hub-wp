<?php
/**
 * Real HTTP rejection and protocol matrix; run only inside the owned lab toolbox.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Tests\E2E\McpClient;

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone external HTTP evidence, without WordPress bootstrap.
$xnetvn_agent_hub_wp_state       = json_decode( file_get_contents( '/work/.local/http-matrix/state.json' ), true, 64, JSON_THROW_ON_ERROR );
$xnetvn_agent_hub_wp_host        = getenv( 'RTL_ALIAS_WP69' );
$xnetvn_agent_hub_wp_http        = new McpClient( 'https://' . $xnetvn_agent_hub_wp_host, array( $xnetvn_agent_hub_wp_host ), true );
$xnetvn_agent_hub_wp_plain       = new McpClient( 'http://wp69', array( 'wp69' ) );
$xnetvn_agent_hub_wp_route       = '/wp-json/xnetvn-agent-hub-wp/mcp';
$xnetvn_agent_hub_wp_rows        = array();
$xnetvn_agent_hub_wp_diagnostics = array();

/**
 * Build a modern request through the installed adapter's verified metadata contract.
 *
 * @param string              $method Method.
 * @param array<string,mixed> $params Parameters.
 * @param string              $version Protocol revision.
 * @return string
 */
function xnetvn_agent_hub_wp_http_message( string $method, array $params = array(), string $version = '2026-07-28' ): string {
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => $version,
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'agent-hub-http-matrix',
			'version' => '1.0',
		),
	);
	return json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => $method,
			'params'  => $params,
		),
		JSON_THROW_ON_ERROR
	);
}

/**
 * Record only status/boolean results; never retain a credential or raw response in reports.
 *
 * @param string   $id Original plan scenario.
 * @param callable $check Real request/check.
 * @throws RuntimeException For a fixture reset failure, captured into the case result.
 */
function xnetvn_agent_hub_wp_http_case( string $id, callable $check ): void {
	global $xnetvn_agent_hub_wp_rows;
	try {
		// Independent negative cases must not consume each other's shared failed-auth budget.
		exec( 'bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/http-fixture.php reset-rate --use-include', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Owned remote toolbox fixture, fixed argv and no credential arguments.
		if ( 0 !== $status ) {
			throw new RuntimeException( 'HTTP fixture reset failed.' );
		}
		$xnetvn_agent_hub_wp_rows[ $id ] = true === $check();
	} catch ( Throwable ) {
		$xnetvn_agent_hub_wp_rows[ $id ] = false;
	}
}

$xnetvn_agent_hub_wp_send   = static function ( ?string $kind, string $method = 'tools/list', array $params = array(), array $headers = array(), ?string $route = null ) use ( $xnetvn_agent_hub_wp_http, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_route ): array {
	$headers['MCP-Protocol-Version'] = '2026-07-28';
	$headers['Mcp-Method']           = $method;
	if ( isset( $params['name'] ) && is_string( $params['name'] ) ) {
		$headers['Mcp-Name'] = $params['name'];
	}
	return $xnetvn_agent_hub_wp_http->request( $route ?? $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( $method, $params ), null === $kind ? null : $xnetvn_agent_hub_wp_state['keys'][ $kind ]['token'], $headers );
};
$xnetvn_agent_hub_wp_denied = static fn( array $response ): bool => true === ( $response['body']['result']['isError'] ?? false ) || isset( $response['body']['error'] );

/**
 * Bounded read-only GET for the management key collection on the owned HTTPS alias.
 *
 * This is deliberately separate from McpClient: its POST-only request() must
 * never create a key, and its record-only decoder rejects a JSON list.
 *
 * @param string               $path Relative management route.
 * @param array<string,string> $headers Cookie/nonce headers kept in memory.
 * @return array{status:int,headers:array<string,string>,body:mixed}
 * @throws RuntimeException For a transport or decode failure.
 */
$xnetvn_agent_hub_wp_management_get = static function ( string $path, #[\SensitiveParameter] array $headers = array() ) use ( $xnetvn_agent_hub_wp_host ): array {
	if ( ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) ) {
		throw new RuntimeException( 'Invalid management test route.' );
	}
	$request_headers = array( 'Accept: application/json' );
	foreach ( $headers as $name => $value ) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9-]+\z/', $name ) || strpbrk( $value, "\r\n" ) !== false ) {
			throw new RuntimeException( 'Invalid management test header.' );
		}
		$request_headers[] = $name . ': ' . $value;
	}
	$curl = curl_init();
	if ( false === $curl ) {
		throw new RuntimeException( 'Management test transport unavailable.' );
	}
	$response_headers = array();
	$body             = '';
	try {
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => 'https://' . $xnetvn_agent_hub_wp_host . $path,
				CURLOPT_HTTPGET        => true,
				CURLOPT_HTTPHEADER     => $request_headers,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_HEADERFUNCTION => static function ( \CurlHandle $handle, string $line ) use ( &$response_headers ): int {
					if ( str_contains( $line, ':' ) ) {
						list($name, $value) = explode( ':', $line, 2 );
						$response_headers[ strtolower( trim( $name ) ) ] = trim( $value );
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( \CurlHandle $handle, string $chunk ) use ( &$body ): int {
					if ( strlen( $body ) + strlen( $chunk ) > 1048576 ) {
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			)
		);
		if ( false === curl_exec( $curl ) ) {
			throw new RuntimeException( 'Management test request did not complete.' );
		}
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$data   = null;
		if ( '' !== trim( $body ) ) {
			try {
				$data = json_decode( $body, true, 64, JSON_THROW_ON_ERROR );
			} catch ( \JsonException ) {
				throw new RuntimeException( 'Malformed management test response.' );
			}
		}
		return array(
			'status'  => $status,
			'headers' => $response_headers,
			'body'    => $data,
		);
	} finally {
		curl_reset( $curl );
	}
};

xnetvn_agent_hub_wp_http_case(
	'N-01',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		$r = $xnetvn_agent_hub_wp_send( null );
		return 401 === $r['status'] && str_contains( $r['headers']['www-authenticate'] ?? '', 'Bearer' );
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-02',
	static function () use ( $xnetvn_agent_hub_wp_http, $xnetvn_agent_hub_wp_route, $xnetvn_agent_hub_wp_state ): bool {
		$r = $xnetvn_agent_hub_wp_http->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list' ), 'malformed', array( 'X-Xnetvn-Test-Control' => $xnetvn_agent_hub_wp_state['control'] ) );
		return 401 === $r['status'] && '0' === ( $r['headers']['x-xnetvn-test-key-lookups'] ?? null );
	}
);
$xnetvn_agent_hub_wp_unknown = $xnetvn_agent_hub_wp_http->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list' ), 'xnv_aaaaaaaaaaaa_' . str_repeat( 'A', 43 ) );
$xnetvn_agent_hub_wp_wrong   = $xnetvn_agent_hub_wp_http->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list' ), substr( $xnetvn_agent_hub_wp_state['keys']['read']['token'], 0, 17 ) . str_repeat( 'A', 43 ) );
xnetvn_agent_hub_wp_http_case( 'N-03', static fn(): bool => 401 === $xnetvn_agent_hub_wp_unknown['status'] && $xnetvn_agent_hub_wp_unknown['body'] === $xnetvn_agent_hub_wp_wrong['body'] );
xnetvn_agent_hub_wp_http_case( 'N-04', static fn(): bool => 401 === $xnetvn_agent_hub_wp_wrong['status'] );
foreach ( array(
	'N-05' => 'revoked',
	'N-06' => 'expired',
	'N-09' => 'ip',
) as $xnetvn_agent_hub_wp_id => $xnetvn_agent_hub_wp_kind ) {
	xnetvn_agent_hub_wp_http_case( $xnetvn_agent_hub_wp_id, static fn(): bool => ( 'ip' === $xnetvn_agent_hub_wp_kind ? 403 : 401 ) === $xnetvn_agent_hub_wp_send( $xnetvn_agent_hub_wp_kind )['status'] );
}
xnetvn_agent_hub_wp_http_case( 'N-10', static fn(): bool => 403 === $xnetvn_agent_hub_wp_plain->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list' ) )['status'] );
xnetvn_agent_hub_wp_http_case( 'N-11', static fn(): bool => 403 === $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), array( 'Origin' => 'https://unapproved.example.test' ) )['status'] );
xnetvn_agent_hub_wp_http_case( 'N-12', static fn(): bool => 403 === $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), array( 'Origin' => 'null' ) )['status'] );
xnetvn_agent_hub_wp_http_case( 'N-13', static fn(): bool => 200 === $xnetvn_agent_hub_wp_send( 'read' )['status'] );
foreach ( array(
	'N-15' => '/wp-json/xnetvn-agent-hub-wp/v1/keys',
	'N-16' => '/wp-json/wp/v2/posts',
	'N-17' => '/wp-json/mcp/mcp-adapter-default-server',
) as $xnetvn_agent_hub_wp_id => $xnetvn_agent_hub_wp_path ) {
	xnetvn_agent_hub_wp_http_case(
		$xnetvn_agent_hub_wp_id,
		static function () use ( $xnetvn_agent_hub_wp_http, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_path ): bool {
			$r = $xnetvn_agent_hub_wp_http->request(
				$xnetvn_agent_hub_wp_path,
				json_encode(
					array(
						'name'  => 'Valid route probe',
						'title' => 'Valid route probe',
					),
					JSON_THROW_ON_ERROR
				),
				$xnetvn_agent_hub_wp_state['keys']['read']['token']
			);
			return in_array( $r['status'], array( 401, 403 ), true );
		}
	);
}
$xnetvn_agent_hub_wp_cookie_headers = array(
	'Cookie'     => $xnetvn_agent_hub_wp_state['cookie'],
	'X-WP-Nonce' => $xnetvn_agent_hub_wp_state['nonce'],
);
xnetvn_agent_hub_wp_http_case( 'N-18', static fn(): bool => 401 === $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), $xnetvn_agent_hub_wp_cookie_headers )['status'] );
xnetvn_agent_hub_wp_http_case(
	'N-19',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_cookie_headers, $xnetvn_agent_hub_wp_state ): bool {
		$r = $xnetvn_agent_hub_wp_send(
			'read',
			'tools/call',
			array(
				'name'      => 'xnetvn-get-session-info',
				'arguments' => (object) array(),
			),
			$xnetvn_agent_hub_wp_cookie_headers
		);
		return ( $r['body']['result']['structuredContent']['user']['id'] ?? null ) === $xnetvn_agent_hub_wp_state['users']['owner'];
	}
);
$xnetvn_agent_hub_wp_control = array( 'X-Xnetvn-Test-Control' => $xnetvn_agent_hub_wp_state['control'] );
xnetvn_agent_hub_wp_http_case( 'N-20', static fn(): bool => 200 !== $xnetvn_agent_hub_wp_send( 'read', 'tools/list', array(), array_merge( $xnetvn_agent_hub_wp_control, array( 'X-Xnetvn-Test-No-Context' => '1' ) ) )['status'] );
xnetvn_agent_hub_wp_http_case(
	'N-21',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		$r     = $xnetvn_agent_hub_wp_send( 'read' );
		$names = array_column( $r['body']['result']['tools'] ?? array(), 'name' );
		return 200 === $r['status'] && in_array( 'xnetvn-fixture-read', $names, true ) && ! array_intersect( array( 'xnetvn-fixture-write', 'xnetvn-fixture-bulk', 'xnetvn-delete-user' ), $names );
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-22',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_denied ): bool {
		global $xnetvn_agent_hub_wp_diagnostics;
		$hidden                                  = $xnetvn_agent_hub_wp_send(
			'system',
			'tools/call',
			array(
				'name'      => 'xnetvn-fixture-read',
				'arguments' => (object) array(),
			)
		);
		$unknown                                 = $xnetvn_agent_hub_wp_send(
			'system',
			'tools/call',
			array(
				'name'      => 'xnetvn-never-registered',
				'arguments' => (object) array(),
			)
		);
		$xnetvn_agent_hub_wp_diagnostics['N-22'] = array(
			'hidden_status'  => $hidden['status'],
			'unknown_status' => $unknown['status'],
			'hidden_code'    => $hidden['body']['error']['code'] ?? null,
			'unknown_code'   => $unknown['body']['error']['code'] ?? null,
		);
		return $xnetvn_agent_hub_wp_denied( $hidden ) && $hidden['body'] === $unknown['body'];
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-23',
	static fn(): bool => $xnetvn_agent_hub_wp_denied(
		$xnetvn_agent_hub_wp_send(
			'read',
			'tools/call',
			array(
				'name'      => 'xnetvn-fixture-write',
				'arguments' => (object) array(),
			)
		)
	)
);
xnetvn_agent_hub_wp_http_case(
	'N-27',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_control ): bool {
		global $xnetvn_agent_hub_wp_diagnostics;
		$r                                       = $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), array_merge( $xnetvn_agent_hub_wp_control, array( 'X-Xnetvn-Api-Key' => 'Bearer ' . $xnetvn_agent_hub_wp_state['keys']['read']['token'] ) ) );
		$xnetvn_agent_hub_wp_diagnostics['N-27'] = array(
			'status'           => $r['status'],
			'auth_present'     => $r['headers']['x-xnetvn-test-authorization-present'] ?? null,
			'auth_empty'       => $r['headers']['x-xnetvn-test-authorization-empty'] ?? null,
			'fallback_present' => $r['headers']['x-xnetvn-test-fallback-present'] ?? null,
		);
		return 200 === $r['status'];
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-29',
	static fn(): bool => $xnetvn_agent_hub_wp_denied(
		$xnetvn_agent_hub_wp_send(
			'write',
			'tools/call',
			array(
				'name'      => 'xnetvn-fixture-bulk',
				'arguments' => array( 'items' => range( 1, 21 ) ),
			)
		)
	)
);
xnetvn_agent_hub_wp_http_case(
	'N-31',
	static function () use ( $xnetvn_agent_hub_wp_http, $xnetvn_agent_hub_wp_route, $xnetvn_agent_hub_wp_state ): bool {
		$r = $xnetvn_agent_hub_wp_http->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list', array(), '1900-01-01' ), $xnetvn_agent_hub_wp_state['keys']['read']['token'], array( 'MCP-Protocol-Version' => '1900-01-01' ) );
		return -32022 === ( $r['body']['error']['code'] ?? null );
	}
);
xnetvn_agent_hub_wp_http_case( 'N-33', static fn(): bool => 403 === $xnetvn_agent_hub_wp_send( 'read', 'tools/list', array(), array( 'Origin' => 'https://unapproved.example.test' ) )['status'] );
xnetvn_agent_hub_wp_http_case( 'N-34', static fn(): bool => 403 === $xnetvn_agent_hub_wp_plain->request( $xnetvn_agent_hub_wp_route, xnetvn_agent_hub_wp_http_message( 'tools/list' ), $xnetvn_agent_hub_wp_state['keys']['read']['token'] )['status'] );
xnetvn_agent_hub_wp_http_case(
	'N-35',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_cookie_headers, $xnetvn_agent_hub_wp_control ): bool {
		$headers = array_merge( $xnetvn_agent_hub_wp_cookie_headers, $xnetvn_agent_hub_wp_control, array( 'X-Xnetvn-Test-Cache-Cookie' => '1' ) );
		$r       = $xnetvn_agent_hub_wp_send(
			'read',
			'tools/call',
			array(
				'name'      => 'xnetvn-get-session-info',
				'arguments' => (object) array(),
			),
			$headers
		);
		return (int) ( $r['headers']['x-xnetvn-test-cached-cookie-user'] ?? 0 ) === $xnetvn_agent_hub_wp_state['users']['cookie'] && ( $r['body']['result']['structuredContent']['user']['id'] ?? null ) === $xnetvn_agent_hub_wp_state['users']['owner'] && 401 === $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), $headers )['status'];
	}
);

/**
 * Run only a fixed, owned fixture action without credential command arguments.
 *
 * @param string $action Allowlisted action.
 * @return array<string,mixed>|null Safe diagnostic output.
 * @throws RuntimeException For failed fixture control.
 */
function xnetvn_agent_hub_wp_http_action( string $action ): ?array {
	if ( ! in_array( $action, array( 'delete-owner', 'demote', 'restore-role', 'admin-disabled', 'key-rate', 'restore-rate', 'status' ), true ) ) {
		throw new RuntimeException( 'Invalid fixture action.' );
	}
	exec( 'bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/http-fixture.php ' . $action . ' --use-include', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed owned toolbox actions with no credential arguments.
	if ( 0 !== $status ) {
		throw new RuntimeException( 'Fixture action failed.' );
	}
	return 'status' === $action ? json_decode( implode( "\n", $output ), true, 32, JSON_THROW_ON_ERROR ) : null;
}

xnetvn_agent_hub_wp_http_case(
	'N-07',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		xnetvn_agent_hub_wp_http_action( 'delete-owner' );
		return 401 === $xnetvn_agent_hub_wp_send( 'deleted' )['status'];
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-08',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		xnetvn_agent_hub_wp_http_action( 'demote' );
		try {
			$denied = 403 === $xnetvn_agent_hub_wp_send( 'read' )['status'];
		} finally {
			xnetvn_agent_hub_wp_http_action( 'restore-role' );
		}
		return $denied && 200 === $xnetvn_agent_hub_wp_send( 'read' )['status'];
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-14',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		for ( $attempt = 0; $attempt < 11; ++$attempt ) {
			$r = $xnetvn_agent_hub_wp_send( null ); }
		return 429 === $r['status'] && (int) ( $r['headers']['retry-after'] ?? 0 ) > 0;
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-24',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_denied ): bool {
		xnetvn_agent_hub_wp_http_action( 'admin-disabled' );
		return $xnetvn_agent_hub_wp_denied(
			$xnetvn_agent_hub_wp_send(
				'admin',
				'tools/call',
				array(
					'name'      => 'xnetvn-delete-user',
					'arguments' => (object) array(),
				)
			)
		);
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-25',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		xnetvn_agent_hub_wp_http_action( 'key-rate' );
		try {
			$first = $xnetvn_agent_hub_wp_send( 'read' );
			$next  = $xnetvn_agent_hub_wp_send( 'read' );
			return 200 === $first['status'] && 429 === $next['status'] && (int) ( $next['headers']['retry-after'] ?? 0 ) > 0;
		} finally {
			xnetvn_agent_hub_wp_http_action( 'restore-rate' ); }
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-26',
	static function () use ( $xnetvn_agent_hub_wp_http, $xnetvn_agent_hub_wp_route, $xnetvn_agent_hub_wp_state ): bool {
		$deep = array();
		for ( $depth = 0; $depth < 40; ++$depth ) {
			$deep = array( 'nested' => $deep ); }
		$r     = $xnetvn_agent_hub_wp_http->request(
			$xnetvn_agent_hub_wp_route,
			xnetvn_agent_hub_wp_http_message(
				'tools/call',
				array(
					'name'      => 'xnetvn-fixture-read',
					'arguments' => $deep,
				)
			),
			$xnetvn_agent_hub_wp_state['keys']['read']['token'],
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => 'xnetvn-fixture-read',
			)
		);
		$large = $xnetvn_agent_hub_wp_http->request(
			$xnetvn_agent_hub_wp_route,
			str_repeat( 'X', 16777217 ),
			$xnetvn_agent_hub_wp_state['keys']['read']['token'],
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/call',
				'Mcp-Name'             => 'xnetvn-fixture-read',
			)
		);
		return 413 === $large['status'] && 400 === $r['status'] && 'xnetvn_agent_hub_wp_invalid_payload' === ( $r['body']['code'] ?? null );
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-28',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		$r      = $xnetvn_agent_hub_wp_send(
			'read',
			'tools/call',
			array(
				'name'      => 'xnetvn-fixture-read',
				'arguments' => array( 'message' => 'HTTP-MATRIX-CANARY' ),
			)
		);
		$status = xnetvn_agent_hub_wp_http_action( 'status' );
		return 200 === $r['status'] && true === ( $r['body']['result']['structuredContent']['ok'] ?? false ) && false === $status['canary_found'];
	}
);
xnetvn_agent_hub_wp_http_case(
	'N-30',
	static function () use ( $xnetvn_agent_hub_wp_send ): bool {
		$r      = $xnetvn_agent_hub_wp_send( 'write' );
		$names  = array_column( $r['body']['result']['tools'] ?? array(), 'name' );
		$status = xnetvn_agent_hub_wp_http_action( 'status' );
		return 200 === $r['status'] && in_array( 'xnetvn-fixture-write', $names, true ) && ! array_intersect( array( 'xnetvn-execute-php', 'xnetvn-execute-sql', 'xnetvn-create-api-key', 'xnetvn-read-secret' ), $names ) && false === $status['forbidden_registered'];
	}
);

xnetvn_agent_hub_wp_http_case(
	'N-32',
	static function () use ( $xnetvn_agent_hub_wp_send, $xnetvn_agent_hub_wp_control, $xnetvn_agent_hub_wp_cookie_headers ): bool {
		$headers    = array_merge( $xnetvn_agent_hub_wp_control, array( 'X-Xnetvn-Test-Adapter' => 'unsupported' ) );
		$mcp        = $xnetvn_agent_hub_wp_send( 'read', 'tools/list', array(), $headers );
		$diagnostic = $xnetvn_agent_hub_wp_send( null, 'tools/list', array(), array_merge( $headers, $xnetvn_agent_hub_wp_cookie_headers ), '/wp-json/xnetvn-agent-hub-wp-test/v1/adapter' );
		global $xnetvn_agent_hub_wp_diagnostics;
		$xnetvn_agent_hub_wp_diagnostics['N-32'] = array(
			'mcp_status'         => $mcp['status'],
			'diagnostic_status'  => $diagnostic['status'],
			'base_loaded'        => $diagnostic['body']['base_loaded'] ?? null,
			'mcp_present'        => $diagnostic['body']['mcp_present'] ?? null,
			'unsupported_notice' => $diagnostic['body']['unsupported_notice'] ?? null,
		);
		return 404 === $mcp['status'] && 200 === $diagnostic['status'] && true === $diagnostic['body']['base_loaded'] && false === $diagnostic['body']['mcp_present'] && true === $diagnostic['body']['unsupported_notice'];
	}
);

$xnetvn_agent_hub_wp_management_path    = '/wp-json/xnetvn-agent-hub-wp/v1/keys?per_page=100';
$xnetvn_agent_hub_wp_management_headers = array(
	'Cookie'     => $xnetvn_agent_hub_wp_state['cookie'],
	'X-WP-Nonce' => $xnetvn_agent_hub_wp_state['nonce'],
);
$xnetvn_agent_hub_wp_management_row     = static function ( array $response, string $id ): ?array {
	if ( ! is_array( $response['body'] ) ) {
		return null;
	}
	foreach ( $response['body'] as $row ) {
		if ( is_array( $row ) && (string) ( $row['id'] ?? '' ) === $id ) {
			return $row;
		}
	}
	return null;
};
$xnetvn_agent_hub_wp_management_before  = null;
xnetvn_agent_hub_wp_http_case(
	'NNC-H01',
	static function () use ( $xnetvn_agent_hub_wp_management_get, $xnetvn_agent_hub_wp_management_path, $xnetvn_agent_hub_wp_management_headers, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_management_row, &$xnetvn_agent_hub_wp_management_before ): bool {
		$r                                     = $xnetvn_agent_hub_wp_management_get( $xnetvn_agent_hub_wp_management_path, $xnetvn_agent_hub_wp_management_headers );
		$row                                   = $xnetvn_agent_hub_wp_management_row( $r, (string) $xnetvn_agent_hub_wp_state['keys']['admin']['id'] );
		$xnetvn_agent_hub_wp_management_before = $row;
		return 200 === $r['status'] && is_array( $row ) && ! array_key_exists( 'secret', $row );
	}
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H02',
	static fn(): bool => 401 === $xnetvn_agent_hub_wp_management_get( $xnetvn_agent_hub_wp_management_path, array( 'Cookie' => $xnetvn_agent_hub_wp_state['cookie'] ) )['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H03',
	static fn(): bool => 403 === $xnetvn_agent_hub_wp_management_get(
		$xnetvn_agent_hub_wp_management_path,
		array(
			'Cookie'     => $xnetvn_agent_hub_wp_state['cookie'],
			'X-WP-Nonce' => 'invalid',
		)
	)['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H04',
	static fn(): bool => 403 === $xnetvn_agent_hub_wp_management_get( $xnetvn_agent_hub_wp_management_path . '&_wpnonce=invalid', $xnetvn_agent_hub_wp_management_headers )['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H05',
	static fn(): bool => 403 === $xnetvn_agent_hub_wp_management_get(
		$xnetvn_agent_hub_wp_management_path . '&_wpnonce=invalid',
		array(
			'Cookie'     => $xnetvn_agent_hub_wp_state['cookie'],
			'X-WP-Nonce' => 'invalid',
		)
	)['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H06',
	static fn(): bool => 403 === $xnetvn_agent_hub_wp_management_get( $xnetvn_agent_hub_wp_management_path, array( 'X-WP-Nonce' => $xnetvn_agent_hub_wp_state['nonce'] ) )['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H07',
	static fn(): bool => 403 === $xnetvn_agent_hub_wp_management_get(
		$xnetvn_agent_hub_wp_management_path,
		array(
			'Cookie'     => $xnetvn_agent_hub_wp_state['cookie'],
			'X-WP-Nonce' => '<b>' . $xnetvn_agent_hub_wp_state['nonce'] . '</b>',
		)
	)['status']
);
xnetvn_agent_hub_wp_http_case(
	'NNC-H08',
	static function () use ( $xnetvn_agent_hub_wp_management_get, $xnetvn_agent_hub_wp_management_path, $xnetvn_agent_hub_wp_management_headers, $xnetvn_agent_hub_wp_state, $xnetvn_agent_hub_wp_management_row, &$xnetvn_agent_hub_wp_management_before ): bool {
		$r   = $xnetvn_agent_hub_wp_management_get( $xnetvn_agent_hub_wp_management_path, $xnetvn_agent_hub_wp_management_headers );
		$row = $xnetvn_agent_hub_wp_management_row( $r, (string) $xnetvn_agent_hub_wp_state['keys']['admin']['id'] );
		return 200 === $r['status'] && is_array( $row ) && $row === $xnetvn_agent_hub_wp_management_before;
	}
);

$xnetvn_agent_hub_wp_required              = array_merge(
	array_map( static fn( int $i ): string => sprintf( 'N-%02d', $i ), range( 1, 35 ) ),
	array_map( static fn( int $i ): string => sprintf( 'NNC-H%02d', $i ), range( 1, 8 ) )
);
$xnetvn_agent_hub_wp_report                = array(
	'cases'     => $xnetvn_agent_hub_wp_rows,
	'passed'    => count( array_filter( $xnetvn_agent_hub_wp_rows ) ),
	'failed'    => count( array_filter( $xnetvn_agent_hub_wp_rows, static fn( bool $v ): bool => ! $v ) ),
	'remaining' => array_values( array_diff( $xnetvn_agent_hub_wp_required, array_keys( $xnetvn_agent_hub_wp_rows ) ) ),
);
$xnetvn_agent_hub_wp_probe                 = $xnetvn_agent_hub_wp_send( 'read' );
$xnetvn_agent_hub_wp_report['probe']       = array(
	'status'     => $xnetvn_agent_hub_wp_probe['status'],
	'error_code' => $xnetvn_agent_hub_wp_probe['body']['error']['code'] ?? $xnetvn_agent_hub_wp_probe['body']['code'] ?? null,
);
$xnetvn_agent_hub_wp_report['diagnostics'] = $xnetvn_agent_hub_wp_diagnostics;
file_put_contents( '/work/.local/http-matrix/results.json', json_encode( $xnetvn_agent_hub_wp_report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) );
echo json_encode( $xnetvn_agent_hub_wp_report, JSON_THROW_ON_ERROR ) . "\n";
exit( $xnetvn_agent_hub_wp_report['failed'] || $xnetvn_agent_hub_wp_report['remaining'] ? 1 : 0 );
