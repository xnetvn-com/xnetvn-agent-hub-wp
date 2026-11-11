<?php
/**
 * Owned MCP composition client for the role/SEO fixture suite.
 *
 * Wraps the reviewed McpClient transport with the fixed JSON-RPC envelope,
 * ledger id resolution, schema validation and secret-free reporting used by
 * the role-seo matrices. Never bootstraps WordPress and never calls packs
 * directly; every behavior is exercised through real HTTPS.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client; no WordPress bootstrap or logged credentials.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName -- Public method names are fixed by the approved role/SEO ticket contract.

/**
 * Compose MCP calls for one owned fixture run.
 */
final class RoleSeoHarness {
	/** Approved single-site lab targets for this milestone. */
	private const TARGETS = array( 'wp69', 'wp71' );

	/** Approved multisite network lab targets; each maps to one blog base path. */
	private const NETWORK_TARGETS = array( 'network-main', 'network-child' );

	/** Groups shared with the later role/SEO tickets. */
	private const GROUPS = array( 'fixture', 'permissions', 'content', 'media', 'lifecycle', 'stability' );

	/** Fixed public vocabulary for case reasons; mirrors ReasonMapper. */
	private const REASONS = array(
		'invalid_params',
		'forbidden',
		'not_found',
		'post_type_unavailable',
		'invalid_state',
		'unsupported_operation',
		'conflict',
		'partial_failure',
		'restore_failed',
		'payload_too_large',
		'file_type_not_allowed',
		'image_too_large',
		'quota_exceeded',
		'remote_fetch_disabled',
		'remote_fetch_refused',
		'remote_fetch_failed',
		'rate_limited',
		'duplicate_comment',
		'comments_closed',
		'taxonomy_unavailable',
		'duplicate_term',
		'duplicate_user',
		'protected_account',
		'role_unavailable',
		'cooldown_active',
		'internal_error',
		'unavailable',
	);

	/** Logical fixture ids live in this band and must resolve from the ledger. */
	private const LOGICAL_MIN = 100;
	private const LOGICAL_MAX = 999;

	/** MCP route shared by every matrix. */
	private const MCP_PATH = '/wp-json/xnetvn-agent-hub-wp/mcp';

	/**
	 * Check whether a target belongs to the multisite network lab.
	 *
	 * @param string $target Target name.
	 * @return bool
	 */
	public static function isNetworkTarget( string $target ): bool {
		return in_array( $target, self::NETWORK_TARGETS, true );
	}

	/** Case id shape: FX, AUTH, SEO, IMG, LIFE or NET plus an optional lowercase variant suffix. */
	private const CASE_PATTERN = '/\A(?:FX|AUTH|SEO|IMG|LIFE|NET)-[0-9]{2}(?:-[a-z0-9-]+)?\z/';

	/** Network base path shape: empty for a root site, slash segments otherwise. */
	private const BASE_PATH_PATTERN = '/\A(?:\/[a-z0-9-]+)*\z/';

	/** Run id shape shared with the fixture guard. */
	private const RUN_PATTERN = '/\A[a-z0-9-]{1,32}\z/';

	/** Next JSON-RPC id for this instance.
	 *
	 * @var int
	 */
	private int $next_id = 1;

	/** Recorded case outcomes, safe values only.
	 *
	 * @var array<string,array{ok:bool,reason:string}>
	 */
	private array $cases = array();

	/**
	 * Bind an owned run context, the reviewed transport and the id ledger.
	 *
	 * Network targets keep their subdirectory in `base_path` (for example
	 * `/ui-second` on the child site) so the MCP route never loses the blog
	 * prefix; single-site targets leave it empty.
	 *
	 * @param array<string,mixed> $context Target, blog, base URL, base path, run id and private state path.
	 * @param McpClient           $client Reviewed bounded HTTP transport.
	 * @param array<string,mixed> $ledger Owned users, tokens and logical id map.
	 * @throws \InvalidArgumentException For an unapproved target, blog, URL or run id.
	 */
	public function __construct( private array $context, private McpClient $client, private array $ledger = array() ) {
		$target = $context['target'] ?? '';
		if ( ! in_array( $target, array_merge( self::TARGETS, self::NETWORK_TARGETS ), true ) ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO target.' );
		}
		$blog = $context['blog'] ?? 0;
		if ( ! is_int( $blog ) || 1 > $blog ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO blog.' );
		}
		$base_url = $context['base_url'] ?? '';
		if ( ! is_string( $base_url ) || '' === $base_url ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO base URL.' );
		}
		$base_path = $context['base_path'] ?? '';
		if ( ! is_string( $base_path ) || 1 !== preg_match( self::BASE_PATH_PATTERN, $base_path ) ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO base path.' );
		}
		if ( 'network-child' === $target && '' === $base_path ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO base path.' );
		}
		if ( ( 'network-main' === $target || in_array( $target, self::TARGETS, true ) ) && '' !== $base_path ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO base path.' );
		}
		$this->context['base_path'] = $base_path;
		$run_id                     = $context['run_id'] ?? '';
		if ( ! is_string( $run_id ) || 1 !== preg_match( self::RUN_PATTERN, $run_id ) ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO run id.' );
		}
	}

	/**
	 * Load the owned ledger from the private state file without exposing it.
	 *
	 * @param string $path Private state path.
	 * @return array<string,mixed>
	 * @throws \RuntimeException For a missing, foreign or unreadable state file.
	 */
	public static function loadLedger( string $path ): array {
		if ( '' === $path || is_link( $path ) || ! is_file( $path ) ) {
			throw new \RuntimeException( 'Role SEO fixture state unavailable.' );
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			throw new \RuntimeException( 'Role SEO fixture state unreadable.' );
		}
		try {
			$state = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \RuntimeException( 'Role SEO fixture state malformed.' );
		}
		if ( ! is_array( $state ) || 'role-seo-fixture' !== ( $state['suite'] ?? '' ) || ! isset( $state['users'], $state['tokens'], $state['content']['ids'] ) || ! is_array( $state['users'] ) || ! is_array( $state['tokens'] ) || ! is_array( $state['content']['ids'] ) ) {
			throw new \RuntimeException( 'Role SEO fixture state ownership mismatch.' );
		}
		$meta = array(
			'suite'  => $state['suite'],
			'target' => $state['target'] ?? '',
			'blog'   => $state['blog'] ?? 0,
			'run_id' => $state['run_id'] ?? '',
		);
		if ( ! is_string( $meta['target'] ) || ! is_int( $meta['blog'] ) || ! is_string( $meta['run_id'] ) ) {
			throw new \RuntimeException( 'Role SEO fixture state ownership mismatch.' );
		}
		return array(
			'users'  => $state['users'],
			'tokens' => $state['tokens'],
			'ids'    => $state['content']['ids'],
			'meta'   => $meta,
		);
	}

	/**
	 * Send one JSON-RPC envelope through real HTTPS with the actor key.
	 *
	 * @param string               $actor Actor alias owning the key.
	 * @param string               $method MCP method.
	 * @param array<string,mixed>  $params Method params without envelope keys.
	 * @param array<string,string> $headers Extra protocol headers.
	 * @return array<string,mixed> Decoded response with status and body.
	 * @throws \RuntimeException For an unresolved actor or a transport failure.
	 */
	public function request( string $actor, string $method, array $params = array(), array $headers = array() ): array {
		$tokens = $this->ledger['tokens'] ?? array();
		$token  = ( is_array( $tokens ) && isset( $tokens[ $actor ] ) && is_string( $tokens[ $actor ] ) ) ? $tokens[ $actor ] : null;
		if ( null === $token || '' === $token ) {
			throw new \RuntimeException( 'Unresolved role SEO actor.' );
		}
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => (object) array(),
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'role-seo-acceptance',
				'version' => '1',
			),
		);
		$envelope        = array(
			'jsonrpc' => '2.0',
			'id'      => $this->next_id++,
			'method'  => $method,
			'params'  => $params,
		);
		$request_headers = array(
			'MCP-Protocol-Version' => '2026-07-28',
			'Mcp-Method'           => $method,
		);
		if ( isset( $params['name'] ) && is_string( $params['name'] ) ) {
			$request_headers['Mcp-Name'] = $params['name'];
		}
		foreach ( $headers as $name => $value ) {
			$request_headers[ $name ] = $value;
		}
		try {
			$body = json_encode( $envelope, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \RuntimeException( 'Role SEO request could not be encoded.' );
		}
		try {
			return $this->client->request( $this->networkPath( self::MCP_PATH ), $body, $token, $request_headers );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Role SEO test transport did not complete.' );
		}
	}

	/**
	 * Call one MCP tool with ledger-resolved arguments.
	 *
	 * @param string              $actor Actor alias.
	 * @param string              $tool Tool suffix or full xnetvn- name.
	 * @param array<string,mixed> $args Tool arguments with logical ids.
	 * @return array<string,mixed> Decoded response with status and body.
	 */
	public function call( string $actor, string $tool, array $args = array() ): array {
		$name = str_starts_with( $tool, 'xnetvn-' ) ? $tool : 'xnetvn-' . $tool;
		return $this->request(
			$actor,
			'tools/call',
			array(
				'name'      => $name,
				'arguments' => (object) $this->resolveIds( $args ),
			)
		);
	}

	/**
	 * Alias a runtime-created real id behind a free logical id.
	 *
	 * Posts and media created during a run receive real ids from the lab
	 * database; on long-lived sites those ids fall inside the logical band
	 * and would be mistaken for unresolved fixture ids. Registering the
	 * real id under a free logical id keeps follow-up calls unambiguous
	 * without weakening the unresolved-id guard.
	 *
	 * @param int $logical Free logical id inside the logical band.
	 * @param int $real Real id returned by the lab.
	 * @throws \InvalidArgumentException For an out-of-band logical, a non-positive real id or a logical already owned by the ledger.
	 */
	public function aliasId( int $logical, int $real ): void {
		if ( $logical < self::LOGICAL_MIN || $logical > self::LOGICAL_MAX || 1 > $real ) {
			throw new \InvalidArgumentException( 'Unapproved role SEO runtime id.' );
		}
		if ( ! isset( $this->ledger['ids'] ) || ! is_array( $this->ledger['ids'] ) ) {
			$this->ledger['ids'] = array();
		}
		if ( array_key_exists( $logical, $this->ledger['ids'] ) ) {
			throw new \InvalidArgumentException( 'Role SEO runtime id already owned.' );
		}
		$this->ledger['ids'][ $logical ] = $real;
	}

	/**
	 * Resolve logical fixture ids from the ledger before serialization.
	 *
	 * Integers inside the logical band must be ledger-known; anything else
	 * passes through untouched.
	 *
	 * @param array<string,mixed> $args Raw arguments.
	 * @return array<string,mixed>
	 * @throws \RuntimeException For a logical id missing from the ledger.
	 */
	public function resolveIds( array $args ): array {
		$ids      = ( isset( $this->ledger['ids'] ) && is_array( $this->ledger['ids'] ) ) ? $this->ledger['ids'] : array();
		$resolved = array();
		foreach ( $args as $key => $value ) {
			if ( is_array( $value ) ) {
				$resolved[ $key ] = $this->resolveIds( $value );
				continue;
			}
			if ( is_int( $value ) && self::LOGICAL_MIN <= $value && $value <= self::LOGICAL_MAX ) {
				if ( ! array_key_exists( $value, $ids ) || ! is_int( $ids[ $value ] ) ) {
					throw new \RuntimeException( 'Unresolved role SEO fixture id.' );
				}
				$resolved[ $key ] = $ids[ $value ];
				continue;
			}
			$resolved[ $key ] = $value;
		}
		return $resolved;
	}

	/**
	 * Require a successful structured result validated against its schema.
	 *
	 * @param string                   $case_id Case id.
	 * @param array<string,mixed>      $response Decoded MCP response.
	 * @param array<string,mixed>|null $schema Output schema from discovery, or null.
	 * @return array<string,mixed> Validated structured content.
	 * @throws \RuntimeException For a missing or invalid success payload.
	 */
	public function assertSuccess( string $case_id, array $response, ?array $schema = null ): array {
		$data = ( 200 === ( $response['status'] ?? 0 ) ) ? ( $response['body']['result']['structuredContent'] ?? null ) : null;
		if ( ! is_array( $data ) || ( $response['body']['result']['isError'] ?? false ) ) {
			throw new \RuntimeException( 'Expected structured success.' );
		}
		if ( null !== $schema ) {
			try {
				$this->validateAgainstSchema( $data, $schema );
			} catch ( \RuntimeException ) {
				throw new \RuntimeException( 'Structured content failed schema validation.' );
			}
		}
		$this->recordCase( $case_id, true );
		return $data;
	}

	/**
	 * Require a tool error with the expected allowlisted reason.
	 *
	 * @param string              $case_id Case id.
	 * @param array<string,mixed> $response Decoded MCP response.
	 * @param string              $reason Expected allowlisted reason.
	 * @throws \RuntimeException For a missing denial or a reason mismatch.
	 */
	public function assertToolError( string $case_id, array $response, string $reason ): void {
		$actual = $this->safeReason( $response );
		if ( 200 !== ( $response['status'] ?? 0 ) || true !== ( $response['body']['result']['isError'] ?? false ) || $actual !== $reason ) {
			throw new \RuntimeException( 'Expected tool denial.' );
		}
		$this->recordCase( $case_id, true, $reason );
	}

	/**
	 * Require two resource snapshots to match on identity fields.
	 *
	 * Only the compared digests, counts and owners travel; raw values never
	 * reach the report.
	 *
	 * @param string              $case_id Case id.
	 * @param array<string,mixed> $before Snapshot before the denied attempt.
	 * @param array<string,mixed> $after Snapshot after the denied attempt.
	 * @throws \RuntimeException For any identity drift.
	 */
	public function assertUnchanged( string $case_id, array $before, array $after ): void {
		$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		foreach ( $keys as $key ) {
			if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
				throw new \RuntimeException( 'Owned resource changed after denial.' );
			}
		}
		$this->recordCase( $case_id, true );
	}

	/**
	 * Record one safe case outcome for the public report.
	 *
	 * @param string $case_id Case id.
	 * @param bool   $ok Outcome.
	 * @param string $reason Allowlisted reason, never raw input.
	 * @throws \InvalidArgumentException For an unsafe case id or reason.
	 */
	public function recordCase( string $case_id, bool $ok, string $reason = 'unavailable' ): void {
		if ( 1 !== preg_match( self::CASE_PATTERN, $case_id ) ) {
			throw new \InvalidArgumentException( 'Unsafe role SEO case id.' );
		}
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			throw new \InvalidArgumentException( 'Unsafe role SEO reason.' );
		}
		$this->cases[ $case_id ] = array(
			'ok'     => $ok,
			'reason' => $ok ? 'unavailable' : $reason,
		);
	}

	/** Count recorded cases. */
	public function caseCount(): int {
		return count( $this->cases );
	}

	/**
	 * Read recorded cases with safe values only.
	 *
	 * @return array<string,array{ok:bool,reason:string}>
	 */
	public function cases(): array {
		return $this->cases;
	}

	/**
	 * Prefix the shared MCP route with the owned network base path.
	 *
	 * The base path is empty on root sites and keeps the subdirectory on
	 * child sites, so the route join never drops `/ui-second/`.
	 *
	 * @param string $route Shared absolute route.
	 * @return string Blog-scoped route.
	 */
	public function networkPath( string $route ): string {
		return ( $this->context['base_path'] ?? '' ) . $route;
	}

	/**
	 * Run one declared group file in this same lifecycle.
	 *
	 * Group files only declare metadata and a run callable; they never seed,
	 * bootstrap or request on include.
	 *
	 * @param string $group Group name.
	 * @throws \InvalidArgumentException For an unknown group.
	 * @throws \RuntimeException For a missing or malformed group file.
	 */
	public function runGroup( string $group ): void {
		if ( ! in_array( $group, self::GROUPS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown role SEO group.' );
		}
		$file = __DIR__ . '/role-seo-' . $group . '.php';
		if ( ! is_file( $file ) || is_link( $file ) ) {
			throw new \RuntimeException( 'Role SEO group runner unavailable.' );
		}
		$declared = require $file;
		if ( ! is_array( $declared ) || ! isset( $declared['cases'], $declared['run'] ) || ! is_array( $declared['cases'] ) || ! is_callable( $declared['run'] ) ) {
			throw new \RuntimeException( 'Role SEO group declaration malformed.' );
		}
		foreach ( $declared['cases'] as $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['id'] ) || ! is_string( $meta['id'] ) || 1 !== preg_match( self::CASE_PATTERN, $meta['id'] ) ) {
				throw new \RuntimeException( 'Role SEO group case metadata malformed.' );
			}
		}
		$declared['run']( $this );
	}

	/**
	 * Extract the allowlisted reason from a denial without raw values.
	 *
	 * @param array<string,mixed> $response Decoded MCP response.
	 * @return string
	 */
	private function safeReason( array $response ): string {
		$text = $response['body']['result']['content'][0]['text'] ?? '';
		if ( ! is_string( $text ) ) {
			return 'unavailable';
		}
		foreach ( self::REASONS as $candidate ) {
			if ( str_contains( $text, $candidate ) ) {
				return $candidate;
			}
		}
		return 'unavailable';
	}

	/**
	 * Validate structured output against its discovery schema.
	 *
	 * Supports the object, array, string, integer, number, boolean and null
	 * shapes emitted by the installed adapter. Only key paths travel in
	 * failures, never values.
	 *
	 * @param mixed               $data Decoded structured content.
	 * @param array<string,mixed> $schema Output schema.
	 * @param string              $path Key path for diagnostics.
	 * @throws \RuntimeException For a schema mismatch.
	 */
	private function validateAgainstSchema( mixed $data, array $schema, string $path = '$' ): void {
		$type = $schema['type'] ?? null;
		if ( is_array( $type ) ) {
			$matched = false;
			foreach ( $type as $option ) {
				try {
					$this->validateAgainstSchema( $data, array_merge( $schema, array( 'type' => $option ) ), $path );
					$matched = true;
					break;
				} catch ( \RuntimeException ) {
					continue;
				}
			}
			if ( ! $matched ) {
				throw new \RuntimeException( 'Structured content failed schema validation.' );
			}
			return;
		}
		switch ( $type ) {
			case 'object':
				if ( ! is_array( $data ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				foreach ( $schema['required'] ?? array() as $required ) {
					if ( ! is_string( $required ) || ! array_key_exists( $required, $data ) ) {
						throw new \RuntimeException( 'Structured content failed schema validation.' );
					}
				}
				$properties = $schema['properties'] ?? array();
				foreach ( $data as $key => $value ) {
					if ( ! is_string( $key ) ) {
						throw new \RuntimeException( 'Structured content failed schema validation.' );
					}
					if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
						$this->validateAgainstSchema( $value, $properties[ $key ], $path . '.' . $key );
					} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
						throw new \RuntimeException( 'Structured content failed schema validation.' );
					}
				}
				break;
			case 'array':
				if ( ! is_array( $data ) || ( array_is_list( $data ) === false && 0 < count( $data ) ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
					foreach ( array_values( $data ) as $index => $item ) {
						$this->validateAgainstSchema( $item, $schema['items'], $path . '[' . (int) $index . ']' );
					}
				}
				break;
			case 'string':
				if ( ! is_string( $data ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				break;
			case 'integer':
				if ( ! is_int( $data ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				break;
			case 'number':
				if ( ! is_int( $data ) && ! is_float( $data ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				break;
			case 'boolean':
				if ( ! is_bool( $data ) ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				break;
			case 'null':
				if ( null !== $data ) {
					throw new \RuntimeException( 'Structured content failed schema validation.' );
				}
				break;
			default:
				break;
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
			throw new \RuntimeException( 'Structured content failed schema validation.' );
		}
	}
}
