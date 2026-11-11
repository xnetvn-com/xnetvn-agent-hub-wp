<?php
/**
 * Whole-catalog role/blog matrix executed through real HTTPS MCP (S5).
 *
 * Every business assertion goes through the installed MCP adapter with the
 * owned bearer token of one actor. Declarations come from
 * catalog-role-cases.php; fixture state comes from catalog-role-fixture.php.
 * The report keeps case ids, allowlisted reasons, status codes and boolean
 * checks only: no token, argument value, payload or response body.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Tests\E2E\McpClient;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned HTTP test client; no WordPress bootstrap or logged credentials.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed owned fixture variant actions with no user-supplied command.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Standalone matrix globals use the ticket prefix and never enter WordPress.
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Case rows are the fixed ticket vocabulary.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName -- Fixed test helper names.

if ( '1' !== getenv( 'XNETVN_RTL' ) ) {
	exit( 2 );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$GLOBALS['xnetvn_catalog_role_matrix_target']   = (string) getenv( 'XNETVN_MATRIX_TARGET' );
$GLOBALS['xnetvn_catalog_role_matrix_campaign'] = (string) getenv( 'XNETVN_MATRIX_CAMPAIGN' );
$GLOBALS['xnetvn_catalog_role_matrix_blog']     = (string) getenv( 'XNETVN_MATRIX_BLOG' );
$GLOBALS['xnetvn_catalog_role_matrix_base']     = (string) getenv( 'XNETVN_MATRIX_BASE_URL' );
$GLOBALS['xnetvn_catalog_role_matrix_other']    = (string) getenv( 'XNETVN_MATRIX_OTHER_URL' );
$GLOBALS['xnetvn_catalog_role_matrix_private']  = (string) getenv( 'XNETVN_MATRIX_PRIVATE_DIR' );
$GLOBALS['xnetvn_catalog_role_matrix_revision'] = (string) getenv( 'XNETVN_MATRIX_REVISION' );
$xnetvn_catalog_role_matrix_phase_env           = getenv( 'XNETVN_MATRIX_PHASE' );
$GLOBALS['xnetvn_catalog_role_matrix_phase']    = is_string( $xnetvn_catalog_role_matrix_phase_env ) && '' !== $xnetvn_catalog_role_matrix_phase_env ? $xnetvn_catalog_role_matrix_phase_env : 'all';
if ( ! in_array( $GLOBALS['xnetvn_catalog_role_matrix_phase'], array( 'all', 'main', 'auth-subscriber-1', 'auth-subscriber-2', 'auth-banned-1', 'auth-banned-2', 'auth-anonymous-1', 'auth-anonymous-2', 'finish' ), true ) ) {
	exit( 2 );
}

$GLOBALS['xnetvn_catalog_role_matrix_data'] = require __DIR__ . '/catalog-role-cases.php';
if ( ! is_array( $GLOBALS['xnetvn_catalog_role_matrix_data'] ) ) {
	exit( 2 );
}
$data = $GLOBALS['xnetvn_catalog_role_matrix_data'];
if ( ! xnetvn_agent_hub_wp_catalog_role_target_allowed( $GLOBALS['xnetvn_catalog_role_matrix_target'] ) || ! xnetvn_agent_hub_wp_catalog_role_campaign_valid( $GLOBALS['xnetvn_catalog_role_matrix_campaign'] ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $GLOBALS['xnetvn_catalog_role_matrix_blog'] ) || '' === $GLOBALS['xnetvn_catalog_role_matrix_base'] ) {
	exit( 2 );
}
$xnetvn_catalog_role_matrix_network = in_array( $GLOBALS['xnetvn_catalog_role_matrix_target'], $data['network_targets'], true );

/**
 * Read a JSON file without following a symlink.
 *
 * @param string $file File.
 * @return mixed
 * @throws RuntimeException For a missing or malformed file.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_json_file( string $file ): mixed {
	if ( is_link( $file ) || ! is_file( $file ) ) {
		throw new RuntimeException( 'Catalog role matrix input unavailable.' );
	}
	$raw = file_get_contents( $file );
	if ( ! is_string( $raw ) ) {
		throw new RuntimeException( 'Catalog role matrix input unreadable.' );
	}
	try {
		return json_decode( $raw, true, 128, JSON_THROW_ON_ERROR );
	} catch ( JsonException ) {
		throw new RuntimeException( 'Catalog role matrix input malformed.' );
	}
}

/**
 * Canonicalize a value for stable comparison and digests.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_c14n( mixed $value ): mixed {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_is_list( $value ) ) {
		return array_map( 'xnetvn_agent_hub_wp_catalog_role_matrix_c14n', $value );
	}
	ksort( $value );
	foreach ( $value as $key => $item ) {
		$value[ $key ] = xnetvn_agent_hub_wp_catalog_role_matrix_c14n( $item );
	}
	return $value;
}

/**
 * Hash a canonical value.
 *
 * @param mixed $value Value.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_hash( mixed $value ): string {
	try {
		return hash( 'sha256', Json::encode( xnetvn_agent_hub_wp_catalog_role_matrix_c14n( $value ) ) );
	} catch ( Throwable ) {
		return 'unavailable';
	}
}

/**
 * Resolve one declared target key to its real id.
 *
 * @param string              $key   Target key.
 * @param array<string,mixed> $state State.
 * @param array<string,mixed> $data  Declarations.
 * @return int
 * @throws RuntimeException For an unknown or missing target.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_target( string $key, array $state, array $data ): int {
	if ( 'none' === $key || '' === $key ) {
		return 0;
	}
	$logical = (int) ( $data['targets'][ $key ]['id'] ?? 0 );
	if ( 1 > $logical || ! isset( $state['ids'][ $logical ] ) || ! is_int( $state['ids'][ $logical ] ) ) {
		throw new RuntimeException( 'Catalog role matrix target unavailable.' );
	}
	return (int) $state['ids'][ $logical ];
}

/**
 * Build the payload context of one case.
 *
 * @param array<string,mixed> $case  Case.
 * @param array<string,mixed> $state State.
 * @param array<string,mixed> $data  Declarations.
 * @return array<string,mixed>
 * @throws RuntimeException For an unavailable target.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_context( array $case, array $state, array $data ): array {
	$target_key      = (string) ( $case['target'] ?? 'none' );
	$target          = 0;
	$user            = 0;
	$target_kind     = '';
	$target_taxonomy = '';
	if ( str_starts_with( $target_key, 'user:' ) ) {
		$alias = substr( $target_key, 5 );
		$user  = (int) ( $state['users'][ $alias ] ?? 0 );
		if ( 1 > $user ) {
			throw new RuntimeException( 'Catalog role matrix user target unavailable.' );
		}
		$target      = $user;
		$target_kind = 'user';
	} elseif ( 'none' !== $target_key && '' !== $target_key ) {
		$target          = xnetvn_agent_hub_wp_catalog_role_matrix_target( $target_key, $state, $data );
		$spec            = (array) ( $data['targets'][ $target_key ] ?? array() );
		$target_kind     = (string) ( $spec['kind'] ?? '' );
		$target_taxonomy = (string) ( $spec['taxonomy'] ?? '' );
	}
	$extra_kind     = '';
	$extra_taxonomy = '';
	$extra          = 0;
	if ( isset( $case['extra_target'] ) && is_string( $case['extra_target'] ) ) {
		$extra          = xnetvn_agent_hub_wp_catalog_role_matrix_target( $case['extra_target'], $state, $data );
		$extra_spec     = (array) ( $data['targets'][ $case['extra_target'] ] ?? array() );
		$extra_kind     = (string) ( $extra_spec['kind'] ?? '' );
		$extra_taxonomy = (string) ( $extra_spec['taxonomy'] ?? '' );
	}
	if ( isset( $case['extra_revision'] ) ) {
		$logical = (int) $case['extra_revision'];
		if ( ! isset( $state['ids'][ $logical ] ) || ! is_int( $state['ids'][ $logical ] ) ) {
			throw new RuntimeException( 'Catalog role matrix revision unavailable.' );
		}
		$extra = (int) $state['ids'][ $logical ];
	}
	return array(
		'target'          => $target,
		'extra'           => $extra,
		'user'            => $user,
		'target_kind'     => $target_kind,
		'target_taxonomy' => $target_taxonomy,
		'extra_kind'      => $extra_kind,
		'extra_taxonomy'  => $extra_taxonomy,
		'actor_user'      => (int) ( $state['users'][ 'super-admin' === (string) $case['actor'] ? 'super_admin' : (string) $case['actor'] ] ?? 0 ),
		'campaign'        => (string) $state['campaign'],
		'actor'           => (string) $case['actor'],
	);
}

/**
 * Build one JSON-RPC request with the reviewed protocol headers.
 *
 * @param McpClient           $client Transport.
 * @param string|null         $token  Bearer token, or null for anonymous.
 * @param string              $method MCP method.
 * @param array<string,mixed> $params Params.
 * @param int                 $id     JSON-RPC id.
 * @return array<string,mixed>
 * @throws RuntimeException For a transport failure.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_request( McpClient $client, ?string $token, string $method, array $params, int $id ): array {
	$params['_meta'] = array(
		'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
		'io.modelcontextprotocol/clientCapabilities' => (object) array(),
		'io.modelcontextprotocol/clientInfo'         => array(
			'name'    => 'catalog-role-matrix',
			'version' => '1',
		),
	);
	$headers         = array(
		'MCP-Protocol-Version' => '2026-07-28',
		'Mcp-Method'           => $method,
	);
	if ( isset( $params['name'] ) && is_string( $params['name'] ) ) {
		$headers['Mcp-Name'] = $params['name'];
	}
	try {
		$body = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			),
			JSON_THROW_ON_ERROR
		);
	} catch ( JsonException ) {
		throw new RuntimeException( 'Catalog role matrix request encode failed.' );
	}
	try {
		return $client->request( '/wp-json/xnetvn-agent-hub-wp/mcp', $body, $token, $headers );
	} catch ( Throwable ) {
		throw new RuntimeException( 'Catalog role matrix transport did not complete.' );
	}
}

/**
 * Call one tool through HTTP MCP.
 *
 * @param McpClient           $client Transport.
 * @param string|null         $token  Token.
 * @param string              $tool   Wire name.
 * @param array<string,mixed> $args   Arguments.
 * @param int                 $id     JSON-RPC id.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_call( McpClient $client, ?string $token, string $tool, array $args, int $id ): array {
	return xnetvn_agent_hub_wp_catalog_role_matrix_request(
		$client,
		$token,
		'tools/call',
		array(
			'name'      => $tool,
			'arguments' => (object) $args,
		),
		$id
	);
}

/**
 * List visible tools through HTTP MCP.
 *
 * @param McpClient   $client Transport.
 * @param string|null $token  Token.
 * @param int         $id     JSON-RPC id.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_tools( McpClient $client, ?string $token, int $id ): array {
	return xnetvn_agent_hub_wp_catalog_role_matrix_request( $client, $token, 'tools/list', array(), $id );
}

/**
 * Extract visible tool names from a tools/list response.
 *
 * @param array<string,mixed> $response Response.
 * @return array<string>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_tool_names( array $response ): array {
	$tools = $response['body']['result']['tools'] ?? $response['body']['result']['items'] ?? array();
	if ( ! is_array( $tools ) ) {
		return array();
	}
	$names = array();
	foreach ( $tools as $tool ) {
		if ( is_array( $tool ) && isset( $tool['name'] ) && is_string( $tool['name'] ) ) {
			$names[] = $tool['name'];
		}
	}
	sort( $names, SORT_STRING );
	return $names;
}

/**
 * Extract one allowlisted denial reason without copying values.
 *
 * @param array<string,mixed> $response Response.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_reason( array $response ): string {
	$text = $response['body']['result']['content'][0]['text'] ?? '';
	if ( ! is_string( $text ) ) {
		return 'unavailable';
	}
	if ( str_starts_with( $text, 'The tool is unavailable.' ) ) {
		return 'unavailable';
	}
	foreach ( array( 'invalid_params', 'forbidden', 'not_found', 'post_type_unavailable', 'invalid_state', 'unsupported_operation', 'conflict', 'partial_failure', 'restore_failed', 'payload_too_large', 'file_type_not_allowed', 'image_too_large', 'quota_exceeded', 'remote_fetch_disabled', 'remote_fetch_refused', 'remote_fetch_failed', 'rate_limited', 'duplicate_comment', 'comments_closed', 'taxonomy_unavailable', 'duplicate_term', 'duplicate_user', 'protected_account', 'role_unavailable', 'cooldown_active', 'internal_error' ) as $reason ) {
		if ( str_starts_with( $text, $reason . ': ' ) ) {
			return $reason;
		}
	}
	return 'unavailable';
}

/**
 * Validate one output value against the discovered strict schema subset.
 *
 * @param mixed               $data   Value.
 * @param array<string,mixed> $schema Schema.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_schema( mixed $data, array $schema ): bool {
	$type = $schema['type'] ?? null;
	if ( is_array( $type ) ) {
		foreach ( $type as $option ) {
			if ( xnetvn_agent_hub_wp_catalog_role_matrix_schema( $data, array_merge( $schema, array( 'type' => $option ) ) ) ) {
				return true;
			}
		}
		return false;
	}
	switch ( $type ) {
		case 'object':
			if ( ! is_array( $data ) ) {
				return false;
			}
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				if ( ! is_string( $required ) || ! array_key_exists( $required, $data ) ) {
					return false;
				}
			}
			$properties = (array) ( $schema['properties'] ?? array() );
			foreach ( $data as $key => $value ) {
				if ( ! is_string( $key ) ) {
					return false;
				}
				if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
					if ( ! xnetvn_agent_hub_wp_catalog_role_matrix_schema( $value, $properties[ $key ] ) ) {
						return false;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return false;
				}
			}
			return true;
		case 'array':
			if ( ! is_array( $data ) || ( ! array_is_list( $data ) && 0 < count( $data ) ) ) {
				return false;
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( array_values( $data ) as $item ) {
					if ( ! xnetvn_agent_hub_wp_catalog_role_matrix_schema( $item, $schema['items'] ) ) {
						return false;
					}
				}
			}
			return true;
		case 'string':
			return is_string( $data );
		case 'integer':
			return is_int( $data );
		case 'number':
			return is_int( $data ) || is_float( $data );
		case 'boolean':
			return is_bool( $data );
		case 'null':
			return null === $data;
		default:
			return true;
	}
}

/**
 * Check that a response carries no token and no case canary.
 *
 * @param array<string,mixed> $response Response.
 * @param string              $token    Bearer token.
 * @param string              $canary   Case marker.
 * @return bool
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_redacted( array $response, string $token, string $canary ): bool {
	try {
		$encoded = json_encode( $response['body'] ?? array(), JSON_THROW_ON_ERROR );
	} catch ( JsonException ) {
		return false;
	}
	if ( '' !== $token && str_contains( $encoded, $token ) ) {
		return false;
	}
	if ( '' !== $canary && str_contains( $encoded, $canary ) ) {
		return false;
	}
	return true;
}

/**
 * Append one owned object to the private journal immediately after creation.
 *
 * @param string              $file  Journal.
 * @param array<string,mixed> $entry Entry.
 * @return void
 * @throws RuntimeException For a failed write.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_journal( string $file, array $entry ): void {
	if ( is_link( $file ) ) {
		throw new RuntimeException( 'Catalog role matrix journal ownership mismatch.' );
	}
	$line = json_encode( $entry, JSON_THROW_ON_ERROR ) . "\n";
	if ( false === file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ) ) {
		throw new RuntimeException( 'Catalog role matrix journal write failed.' );
	}
	chmod( $file, 0600 );
}

/**
 * Record one case result and update tool coverage.
 *
 * @param array<string,mixed> $result   Result.
 * @param array<string,mixed> $coverage Coverage, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_record( array $result, array &$coverage ): void {
	$GLOBALS['xnetvn_catalog_role_matrix_results'][ $result['id'] ] = $result;
	$tool = (string) $result['tool'];
	if ( ! isset( $coverage[ $tool ] ) ) {
		$coverage[ $tool ] = array(
			'declared'     => 0,
			'executed'     => 0,
			'auth_covered' => 0,
			'na'           => 0,
			'allowed_seen' => 0,
			'denied_seen'  => 0,
		);
	}
	++$coverage[ $tool ]['declared'];
	if ( 'covered' === $result['outcome'] ) {
		++$coverage[ $tool ]['auth_covered'];
		++$coverage[ $tool ]['denied_seen'];
	} elseif ( 'na' === $result['outcome'] ) {
		++$coverage[ $tool ]['na'];
	} else {
		++$coverage[ $tool ]['executed'];
		if ( 'allowed' === $result['outcome'] ) {
			++$coverage[ $tool ]['allowed_seen'];
		} elseif ( 'denied' === $result['outcome'] ) {
			++$coverage[ $tool ]['denied_seen'];
		}
	}
}

/**
 * Run one fixed fixture variant action through the owned WP-CLI wrapper.
 *
 * @param string $variant Variant.
 * @return void
 * @throws RuntimeException For a refused variant.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_variant( string $variant ): void {
	$target = $GLOBALS['xnetvn_catalog_role_matrix_target'];
	$base   = $GLOBALS['xnetvn_catalog_role_matrix_base'];
	$file   = '/work/tests/E2E/catalog-role-fixture.php';
	if ( 'wp69' === $target || 'wp71' === $target ) {
		$command = 'bash /work/infra/remote/run-wp.sh ' . escapeshellarg( $target ) . ' --url=' . escapeshellarg( $base ) . ' eval-file ' . escapeshellarg( $file ) . ' variant ' . escapeshellarg( $variant ) . ' --use-include';
	} else {
		$command = 'WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path=/sites/wpnetwork --url=' . escapeshellarg( $base ) . ' eval-file ' . escapeshellarg( $file ) . ' variant ' . escapeshellarg( $variant ) . ' --use-include';
	}
	$output = array();
	$code   = 1;
	exec( $command . ' 2>/dev/null', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed allowlisted fixture action.
	if ( 0 !== $code ) {
		throw new RuntimeException( 'Catalog role matrix variant refused.' );
	}
}

/**
 * Select one read-only probe key so state checks never share a rate bucket.
 *
 * @param string              $case_id Case id.
 * @param array<string,mixed> $tokens  Tokens.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_probe( string $case_id, array $tokens ): string {
	$index = ( crc32( $case_id ) % 4 ) + 1;
	return (string) ( $tokens[ 'probe' . $index ] ?? '' );
}

/**
 * Classify the state effect of one payload factory.
 *
 * @param string $payload Payload key.
 * @return string read, create, update, delete, purge or effect.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_mutation( string $payload ): string {
	if ( in_array( $payload, array( 'content.create_post', 'content.duplicate_post', 'media.upload_data', 'taxonomy.create_term', 'taxonomy.bulk_create_terms', 'comments.create_comment', 'comments.reply_comment', 'users.create_user', 'media.upload_url', 'invalid.unregistered_meta', 'system.execute_denied' ), true ) ) {
		return 'create';
	}
	if ( in_array( $payload, array( 'content.delete_post', 'media.delete_media', 'comments.delete_comment' ), true ) ) {
		return 'delete';
	}
	if ( in_array( $payload, array( 'content.update_post', 'content.trash_post', 'content.restore_post', 'content.bulk_update_posts', 'content.restore_revision', 'content.set_featured_media', 'content.stick_post', 'content.update_post_meta', 'media.update_media', 'taxonomy.update_term', 'taxonomy.assign_post_terms', 'taxonomy.bulk_assign_post_terms', 'comments.update_comment', 'comments.moderate_comment', 'comments.bulk_moderate', 'users.update_user', 'users.update_profile', 'users.set_role', 'users.set_status', 'invalid.unregistered_meta_update' ), true ) ) {
		return 'update';
	}
	if ( in_array( $payload, array( 'taxonomy.delete_term', 'users.reset', 'users.sessions' ), true ) ) {
		return 'effect';
	}
	if ( 'comments.purge_comments' === $payload ) {
		return 'purge';
	}
	return 'read';
}

/**
 * Read one owned object through the reviewed read tool.
 *
 * @param McpClient     $client   Transport.
 * @param string        $token    Probe token.
 * @param string        $kind     Object kind.
 * @param int           $id       Real id.
 * @param string        $taxonomy Term taxonomy.
 * @param int           $request_id Request counter, by reference.
 * @param array<string> $fields Optional user projection fields.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_target( McpClient $client, string $token, string $kind, int $id, string $taxonomy, int &$request_id, array $fields = array() ): array {
	if ( 1 > $id ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'created_id_missing',
		);
	}
	$tool = '';
	$args = array();
	if ( 'post' === $kind ) {
		$tool = 'xnetvn-get-post';
		$args = array( 'id' => $id );
	} elseif ( 'post_meta' === $kind ) {
		$tool = 'xnetvn-get-post-meta';
		$args = array( 'id' => $id );
	} elseif ( 'media' === $kind ) {
		$tool = 'xnetvn-get-media';
		$args = array( 'id' => $id );
	} elseif ( 'term' === $kind ) {
		$tool = 'xnetvn-get-term';
		$args = array(
			'taxonomy' => '' === $taxonomy ? 'category' : $taxonomy,
			'id'       => $id,
		);
	} elseif ( 'comment' === $kind ) {
		$tool = 'xnetvn-get-comment';
		$args = array( 'id' => $id );
	} elseif ( 'user' === $kind ) {
		$tool = 'xnetvn-get-user';
		$args = array( 'id' => $id );
		if ( array() !== $fields ) {
			$args['fields'] = $fields;
		}
	} else {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_kind_unavailable',
		);
	}
	++$request_id;
	$response  = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, $tool, $args, $request_id );
	$body      = $response['body'] ?? null;
	$structure = $body['result']['structuredContent'] ?? null;
	$is_error  = true === ( $body['result']['isError'] ?? false );
	if ( 200 === (int) ( $response['status'] ?? 0 ) && ! $is_error && is_array( $structure ) ) {
		return array(
			'ok'     => true,
			'found'  => true,
			'absent' => false,
			'digest' => xnetvn_agent_hub_wp_catalog_role_matrix_hash( $structure ),
			'reason' => 'unavailable',
		);
	}
	$reason = xnetvn_agent_hub_wp_catalog_role_matrix_reason( $response );
	if ( 200 === (int) ( $response['status'] ?? 0 ) && $is_error && 'not_found' === $reason ) {
		return array(
			'ok'     => true,
			'found'  => false,
			'absent' => true,
			'digest' => 'absent',
			'reason' => 'not_found',
		);
	}
	return array(
		'ok'     => false,
		'found'  => false,
		'absent' => false,
		'digest' => '',
		'reason' => 'read_back_failed',
	);
}

/**
 * Read the current count of one pack to prove a denied create changed nothing.
 *
 * @param McpClient $client Transport.
 * @param string    $token Probe token.
 * @param string    $pack Pack.
 * @param int       $request_id Request counter, by reference.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_total( McpClient $client, string $token, string $pack, int &$request_id ): array {
	$tools = array(
		'content'  => array( 'xnetvn-list-posts', array( 'per_page' => 1 ) ),
		'media'    => array( 'xnetvn-list-media', array( 'per_page' => 1 ) ),
		'taxonomy' => array(
			'xnetvn-list-terms',
			array(
				'taxonomy' => 'category',
				'per_page' => 1,
			),
		),
		'comments' => array( 'xnetvn-list-comments', array( 'per_page' => 1 ) ),
		'users'    => array( 'xnetvn-list-users', array( 'per_page' => 1 ) ),
	);
	if ( ! isset( $tools[ $pack ] ) ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_list_unavailable',
		);
	}
	++$request_id;
	$response  = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, $tools[ $pack ][0], $tools[ $pack ][1], $request_id );
	$body      = $response['body'] ?? null;
	$structure = $body['result']['structuredContent'] ?? null;
	$is_error  = true === ( $body['result']['isError'] ?? false );
	if ( 200 !== (int) ( $response['status'] ?? 0 ) || $is_error || ! is_array( $structure ) || ! isset( $structure['total'] ) ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_back_failed',
		);
	}
	return array(
		'ok'     => true,
		'found'  => true,
		'absent' => false,
		'digest' => xnetvn_agent_hub_wp_catalog_role_matrix_hash( array( 'total' => (int) $structure['total'] ) ),
		'reason' => 'unavailable',
	);
}

/**
 * Read the objects a successful create returned.
 *
 * @param McpClient           $client Transport.
 * @param string              $token Probe token.
 * @param string              $payload Payload key.
 * @param array<string,mixed> $structured Created output.
 * @param int                 $request_id Request counter, by reference.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_created( McpClient $client, string $token, string $payload, array $structured, int &$request_id ): array {
	$reads = array();
	if ( in_array( $payload, array( 'content.create_post', 'content.duplicate_post' ), true ) ) {
		$reads[] = array(
			'kind'     => 'post',
			'id'       => (int) ( $structured['id'] ?? 0 ),
			'taxonomy' => '',
		);
	} elseif ( 'media.upload_data' === $payload ) {
		$reads[] = array(
			'kind'     => 'media',
			'id'       => (int) ( $structured['id'] ?? 0 ),
			'taxonomy' => '',
		);
	} elseif ( 'taxonomy.create_term' === $payload ) {
		$reads[] = array(
			'kind'     => 'term',
			'id'       => (int) ( $structured['id'] ?? 0 ),
			'taxonomy' => 'category',
		);
	} elseif ( 'taxonomy.bulk_create_terms' === $payload ) {
		foreach ( (array) ( $structured['created'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) ) {
				$reads[] = array(
					'kind'     => 'term',
					'id'       => (int) ( $entry['id'] ?? 0 ),
					'taxonomy' => 'category',
				);
			}
		}
	} elseif ( in_array( $payload, array( 'comments.create_comment', 'comments.reply_comment' ), true ) ) {
		$reads[] = array(
			'kind'     => 'comment',
			'id'       => (int) ( $structured['id'] ?? 0 ),
			'taxonomy' => '',
		);
	} elseif ( 'users.create_user' === $payload ) {
		$reads[] = array(
			'kind'     => 'user',
			'id'       => (int) ( $structured['id'] ?? 0 ),
			'taxonomy' => '',
		);
	}
	if ( array() === $reads ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'created_id_missing',
		);
	}
	$digests = array();
	foreach ( $reads as $read ) {
		$one = xnetvn_agent_hub_wp_catalog_role_matrix_read_target( $client, $token, (string) $read['kind'], (int) $read['id'], (string) $read['taxonomy'], $request_id );
		if ( true !== $one['ok'] || true !== $one['found'] ) {
			return array(
				'ok'     => false,
				'found'  => false,
				'absent' => false,
				'digest' => '',
				'reason' => 'created_read_back_failed',
			);
		}
		$digests[] = $one['digest'];
	}
	return array(
		'ok'     => true,
		'found'  => true,
		'absent' => false,
		'digest' => xnetvn_agent_hub_wp_catalog_role_matrix_hash( $digests ),
		'reason' => 'unavailable',
	);
}

/**
 * Read global comment spam/trash counts for a purge mutation.
 *
 * @param McpClient $client Transport.
 * @param string    $token Probe token.
 * @param int       $request_id Request counter, by reference.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_counts( McpClient $client, string $token, int &$request_id ): array {
	++$request_id;
	$response  = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, 'xnetvn-get-comment-counts', array(), $request_id );
	$body      = $response['body'] ?? null;
	$structure = $body['result']['structuredContent'] ?? null;
	$is_error  = true === ( $body['result']['isError'] ?? false );
	if ( 200 !== (int) ( $response['status'] ?? 0 ) || $is_error || ! is_array( $structure ) || ! isset( $structure['spam'], $structure['trash'] ) ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_back_failed',
		);
	}
	$counts = array(
		'spam'  => (int) $structure['spam'],
		'trash' => (int) $structure['trash'],
	);
	return array(
		'ok'     => true,
		'found'  => true,
		'absent' => false,
		'digest' => xnetvn_agent_hub_wp_catalog_role_matrix_hash( $counts ),
		'reason' => 'unavailable',
		'spam'   => $counts['spam'],
		'trash'  => $counts['trash'],
	);
}

/**
 * Plan the before/after read-back of one case.
 *
 * @param array<string,mixed> $case Case.
 * @param array<string,mixed> $context Context.
 * @param string              $mutation Mutation class.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_plan( array $case, array $context, string $mutation ): array {
	if ( 'read' === $mutation ) {
		return array( 'needed' => false );
	}
	$denied = 'denied' === $case['expected'];
	if ( 'create' === $mutation ) {
		$pack = 'system.execute_denied' === (string) $case['payload'] ? 'content' : (string) $case['pack'];
		return array(
			'needed'  => true,
			'mode'    => $denied ? 'total' : 'created',
			'before'  => $denied,
			'pack'    => $pack,
			'payload' => (string) $case['payload'],
		);
	}
	if ( $denied && 'policy' === (string) $case['layer'] ) {
		return array(
			'needed' => true,
			'mode'   => 'total',
			'before' => true,
			'pack'   => (string) $case['pack'],
		);
	}
	if ( 'purge' === $mutation ) {
		return array(
			'needed' => true,
			'mode'   => 'purge',
			'before' => true,
		);
	}
	if ( 'delete' === $mutation ) {
		return array(
			'needed'   => true,
			'mode'     => $denied ? 'target' : 'absent',
			'before'   => true,
			'kind'     => 'comments' === $case['pack'] ? 'comment' : ( 'media' === $case['pack'] ? 'media' : 'post' ),
			'id'       => (int) $context['target'],
			'taxonomy' => '',
			'extra'    => 0,
		);
	}
	$kind = (string) ( $context['target_kind'] ?? '' );
	$id   = (int) ( $context['target'] ?? 0 );
	if ( 'content.update_post_meta' === $case['payload'] ) {
		$kind = 'post_meta';
	}
	$fields = array();
	if ( 'users.update_profile' === $case['payload'] ) {
		$kind   = 'user';
		$id     = (int) ( $context['actor_user'] ?? 0 );
		$fields = array( 'name' );
	}
	if ( 'users.update_user' === $case['payload'] ) {
		$fields = array( 'name', 'description' );
	}
	return array(
		'needed'         => true,
		'mode'           => $denied ? 'target' : ( 'delete' === $mutation ? 'absent' : 'target' ),
		'before'         => true,
		'kind'           => $kind,
		'id'             => $id,
		'taxonomy'       => (string) ( $context['target_taxonomy'] ?? '' ),
		'extra'          => (int) ( $context['extra'] ?? 0 ),
		'extra_kind'     => (string) ( $context['extra_kind'] ?? '' ),
		'extra_taxonomy' => (string) ( $context['extra_taxonomy'] ?? '' ),
	);
}

/**
 * Execute one planned read-back.
 *
 * @param array<string,mixed> $plan Plan.
 * @param McpClient           $client Transport.
 * @param string              $token Probe token.
 * @param array<string,mixed> $structured Created output for mode created.
 * @param int                 $request_id Request counter, by reference.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_read_state( array $plan, McpClient $client, string $token, array $structured, int &$request_id ): array {
	if ( ! isset( $plan['mode'] ) ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_plan_missing',
		);
	}
	if ( 'total' === $plan['mode'] ) {
		return xnetvn_agent_hub_wp_catalog_role_matrix_read_total( $client, $token, (string) $plan['pack'], $request_id );
	}
	if ( 'created' === $plan['mode'] ) {
		return xnetvn_agent_hub_wp_catalog_role_matrix_read_created( $client, $token, (string) ( $plan['payload'] ?? '' ), $structured, $request_id );
	}
	if ( 'purge' === $plan['mode'] ) {
		return xnetvn_agent_hub_wp_catalog_role_matrix_read_counts( $client, $token, $request_id );
	}
	$first = xnetvn_agent_hub_wp_catalog_role_matrix_read_target( $client, $token, (string) $plan['kind'], (int) $plan['id'], (string) $plan['taxonomy'], $request_id, (array) ( $plan['fields'] ?? array() ) );
	if ( 'absent' === $plan['mode'] ) {
		return $first;
	}
	if ( true !== $first['ok'] || true !== $first['found'] ) {
		return array(
			'ok'     => false,
			'found'  => false,
			'absent' => false,
			'digest' => '',
			'reason' => 'read_back_failed',
		);
	}
	$digests = array( $first['digest'] );
	if ( isset( $plan['extra'] ) && (int) $plan['extra'] > 0 && '' !== (string) $plan['extra_kind'] ) {
		$extra = xnetvn_agent_hub_wp_catalog_role_matrix_read_target( $client, $token, (string) $plan['extra_kind'], (int) $plan['extra'], (string) $plan['extra_taxonomy'], $request_id );
		if ( true !== $extra['ok'] || true !== $extra['found'] ) {
			return array(
				'ok'     => false,
				'found'  => false,
				'absent' => false,
				'digest' => '',
				'reason' => 'read_back_failed',
			);
		}
		$digests[] = $extra['digest'];
	}
	return array(
		'ok'     => true,
		'found'  => true,
		'absent' => false,
		'digest' => xnetvn_agent_hub_wp_catalog_role_matrix_hash( $digests ),
		'reason' => 'unavailable',
	);
}

/**
 * Read the auth pacing state without following a symlink.
 *
 * @param string $file Pacing file.
 * @return array{count:int,next_at:int}
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_pacing( string $file ): array {
	if ( is_link( $file ) || ! is_file( $file ) ) {
		return array(
			'count'   => 0,
			'next_at' => 0,
		);
	}
	$raw  = file_get_contents( $file );
	$data = is_string( $raw ) ? json_decode( $raw, true, 16 ) : null;
	return is_array( $data ) ? array(
		'count'   => (int) ( $data['count'] ?? 0 ),
		'next_at' => (int) ( $data['next_at'] ?? 0 ),
	) : array(
		'count'   => 0,
		'next_at' => 0,
	);
}

/**
 * Persist the auth pacing state.
 *
 * @param string                       $file Pacing file.
 * @param array{count:int,next_at:int} $state State.
 * @return void
 * @throws RuntimeException When the pacing state cannot be written.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_pacing_save( string $file, array $state ): void {
	if ( is_link( $file ) || false === file_put_contents( $file, json_encode( $state, JSON_THROW_ON_ERROR ), LOCK_EX ) ) {
		throw new RuntimeException( 'Catalog role pacing write failed.' );
	}
	chmod( $file, 0600 );
}

/**
 * Wait until one batch of ten auth failures has left the sliding window.
 *
 * @param string                       $file Pacing file.
 * @param array{count:int,next_at:int} $state State, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_pacing_wait( string $file, array &$state ): void {
	if ( $state['count'] >= 10 ) {
		$wait = max( 0, $state['next_at'] - time() );
		if ( 0 < $wait ) {
			sleep( $wait );
		}
		$state = array(
			'count'   => 0,
			'next_at' => 0,
		);
		xnetvn_agent_hub_wp_catalog_role_matrix_pacing_save( $file, $state );
	}
}

/**
 * Record one charged auth failure.
 *
 * @param string                       $file Pacing file.
 * @param array{count:int,next_at:int} $state State, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_pacing_used( string $file, array &$state ): void {
	++$state['count'];
	if ( $state['count'] >= 10 ) {
		$state['next_at'] = time() + 121;
	}
	xnetvn_agent_hub_wp_catalog_role_matrix_pacing_save( $file, $state );
}

/**
 * Send two identical update-post calls at the same time on one key and object.
 *
 * @param string              $token Bearer token.
 * @param array<string,mixed> $args  Update arguments.
 * @param int                 $id_a  First JSON-RPC id.
 * @param int                 $id_b  Second JSON-RPC id.
 * @return array<string,mixed>
 * @throws RuntimeException When the concurrency transport is unavailable.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_parallel( string $token, array $args, int $id_a, int $id_b ): array {
	$url   = rtrim( $GLOBALS['xnetvn_catalog_role_matrix_base'], '/' ) . '/wp-json/xnetvn-agent-hub-wp/mcp';
	$multi = curl_multi_init();
	$items = array();
	foreach ( array(
		$id_a => 'a',
		$id_b => 'b',
	) as $id => $slot ) {
		$params = array(
			'name'      => 'xnetvn-update-post',
			'arguments' => (object) $args,
			'_meta'     => array(
				'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
				'io.modelcontextprotocol/clientCapabilities' => (object) array(),
				'io.modelcontextprotocol/clientInfo'      => array(
					'name'    => 'catalog-role-concurrency',
					'version' => '1',
				),
			),
		);
		$body   = json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => 'tools/call',
				'params'  => $params,
			),
			JSON_THROW_ON_ERROR
		);
		$handle = curl_init();
		if ( false === $handle ) {
			curl_multi_close( $multi );
			throw new RuntimeException( 'Catalog role concurrency transport unavailable.' );
		}
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'Accept: application/json, text/event-stream',
					'Authorization: Bearer ' . $token,
					'MCP-Protocol-Version: 2026-07-28',
					'Mcp-Method: tools/call',
					'Mcp-Name: xnetvn-update-post',
				),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_RETURNTRANSFER => true,
			)
		);
		curl_multi_add_handle( $multi, $handle );
		$items[ $slot ] = $handle;
	}
	$running = 0;
	do {
		$status = curl_multi_exec( $multi, $running );
		if ( 0 < $running ) {
			curl_multi_select( $multi, 1.0 );
		}
	} while ( 0 < $running && CURLM_OK === $status );
	$result = array();
	foreach ( $items as $slot => $handle ) {
		$raw     = (string) curl_multi_getcontent( $handle );
		$code    = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$decoded = null;
		try {
			$decoded = McpClient::decode( $raw );
		} catch ( Throwable ) {
			$decoded = null;
		}
		$result[ $slot ] = array(
			'status' => $code,
			'body'   => $decoded,
		);
		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
	}
	curl_multi_close( $multi );
	return $result;
}

/**
 * Execute the concurrent same-object update probe.
 *
 * @param array<string,mixed> $case       Case.
 * @param int                 $target     Real post id.
 * @param array<string,mixed> $tokens     Tokens.
 * @param McpClient           $client     Transport.
 * @param int                 $request_id Request counter, by reference.
 * @return array<string,mixed>
 * @throws RuntimeException When the concurrency fixture state is unavailable.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_concurrency( array $case, int $target, array $tokens, McpClient $client, int &$request_id ): array {
	$id     = (string) $case['id'];
	$actor  = (string) $case['actor'];
	$canary = 'CR-CANARY-' . $id . ' concurrent';
	$result = array(
		'id'              => $id,
		'tool'            => (string) $case['tool'],
		'pack'            => (string) $case['pack'],
		'actor'           => $actor,
		'expected'        => 'allowed',
		'layer'           => 'concurrency',
		'reason'          => 'unavailable',
		'ok'              => false,
		'outcome'         => 'failed',
		'http'            => null,
		'schema'          => null,
		'redacted'        => true,
		'state_unchanged' => false,
		'state_proof'     => 'none',
	);
	try {
		$token = (string) ( $tokens[ (string) $case['key'] ] ?? '' );
		if ( 1 > $target || '' === $token ) {
			throw new RuntimeException( 'Catalog role concurrency target unavailable.' );
		}
		++$request_id;
		$before      = xnetvn_agent_hub_wp_catalog_role_matrix_call(
			$client,
			$token,
			'xnetvn-get-post',
			array(
				'id'     => $target,
				'fields' => array( 'modified_gmt', 'content' ),
			),
			$request_id
		);
		$before_data = $before['body']['result']['structuredContent'] ?? null;
		$modified    = is_array( $before_data ) ? (string) ( $before_data['modified_gmt'] ?? '' ) : '';
		if ( '' === $modified ) {
			throw new RuntimeException( 'Catalog role concurrency snapshot unavailable.' );
		}
		++$request_id;
		$total_before = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, 'xnetvn-list-posts', array( 'per_page' => 1 ), $request_id );
		$total_before = (int) ( $total_before['body']['result']['structuredContent']['total'] ?? -1 );
		$args         = array(
			'id'                => $target,
			'content'           => $canary,
			'expected_modified' => $modified,
		);
		$id_a         = ++$request_id;
		$id_b         = ++$request_id;
		$pair         = xnetvn_agent_hub_wp_catalog_role_matrix_parallel( $token, $args, $id_a, $id_b );
		$statuses     = array( (int) ( $pair['a']['status'] ?? 0 ), (int) ( $pair['b']['status'] ?? 0 ) );
		$success      = 0;
		foreach ( array( 'a', 'b' ) as $slot ) {
			$body = $pair[ $slot ]['body'] ?? null;
			if ( 200 === (int) $pair[ $slot ]['status'] && is_array( $body ) && true !== ( $body['result']['isError'] ?? false ) && is_array( $body['result']['structuredContent'] ?? null ) ) {
				++$success;
			}
		}
		++$request_id;
		$after      = xnetvn_agent_hub_wp_catalog_role_matrix_call(
			$client,
			$token,
			'xnetvn-get-post',
			array(
				'id'     => $target,
				'fields' => array( 'content' ),
			),
			$request_id
		);
		$after_data = $after['body']['result']['structuredContent'] ?? null;
		$content    = is_array( $after_data ) ? (string) ( $after_data['content'] ?? '' ) : '';
		++$request_id;
		$total_after                 = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, 'xnetvn-list-posts', array( 'per_page' => 1 ), $request_id );
		$total_after                 = (int) ( $total_after['body']['result']['structuredContent']['total'] ?? -1 );
		$result['http']              = 200;
		$result['schema']            = is_array( $after_data );
		$result['parallel_statuses'] = $statuses;
		$result['success_count']     = $success;
		$result['post_total_before'] = $total_before;
		$result['post_total_after']  = $total_after;
		$result['ok']                = $success >= 1
			&& count( array_diff( $statuses, array( 200, 409 ) ) ) === 0
			&& is_array( $after_data )
			&& str_contains( $content, $canary )
			&& $total_before === $total_after;
		$result['outcome']           = $result['ok'] ? 'allowed' : 'failed';
		$result['reason']            = $result['ok'] ? 'unavailable' : 'internal_error';
		$result['observed_reason']   = $result['ok'] ? 'unavailable' : 'internal_error';
		$result['state_proof']       = 'concurrency_read_back';
		$result['state_changed']     = true;
	} catch ( Throwable ) {
		$result['ok']              = false;
		$result['outcome']         = 'failed';
		$result['reason']          = 'internal_error';
		$result['observed_reason'] = 'internal_error';
		$result['redacted']        = false;
	}
	return $result;
}

/**
 * Merge the main and auth phase reports into the final matrix report.
 *
 * @param string              $private Private run directory.
 * @param array<string,mixed> $data    Declarations.
 * @return array<string,mixed>
 * @throws RuntimeException When the main phase report is missing.
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_merge( string $private, array $data ): array {
	$read = static function ( string $file ): array {
		if ( is_link( $file ) || ! is_file( $file ) ) {
			return array();
		}
		$raw     = file_get_contents( $file );
		$decoded = is_string( $raw ) ? json_decode( $raw, true, 64 ) : null;
		return is_array( $decoded ) ? $decoded : array();
	};
	$main = $read( $private . '/matrix-main.json' );
	if ( array() === $main ) {
		throw new RuntimeException( 'Catalog role main phase report unavailable.' );
	}
	$files = glob( $private . '/matrix-auth-*.json' );
	if ( false === $files ) {
		$files = array();
	}
	sort( $files, SORT_STRING );
	$reports = array( $main );
	foreach ( $files as $file ) {
		$part = $read( (string) $file );
		if ( array() !== $part ) {
			$reports[] = $part;
		}
	}
	$cases       = array();
	$requests    = 0;
	$phase_files = array();
	foreach ( $reports as $report ) {
		foreach ( (array) ( $report['cases'] ?? array() ) as $id => $case ) {
			if ( is_array( $case ) && isset( $case['id'] ) ) {
				$cases[ (string) $id ] = $case;
			}
		}
		$requests     += (int) ( $report['counts']['requests'] ?? 0 );
		$phase_files[] = (string) ( $report['phase'] ?? 'main' );
	}
	ksort( $cases, SORT_STRING );
	$coverage = array();
	$na       = 0;
	$covered  = 0;
	$failed   = 0;
	foreach ( $cases as $case ) {
		$tool = (string) $case['tool'];
		if ( ! isset( $coverage[ $tool ] ) ) {
			$coverage[ $tool ] = array(
				'declared'     => 0,
				'executed'     => 0,
				'auth_covered' => 0,
				'na'           => 0,
				'allowed_seen' => 0,
				'denied_seen'  => 0,
			);
		}
		++$coverage[ $tool ]['declared'];
		if ( 'na' === $case['outcome'] ) {
			++$na;
			++$coverage[ $tool ]['na'];
		} elseif ( 'covered' === $case['outcome'] ) {
			++$covered;
			++$coverage[ $tool ]['auth_covered'];
			++$coverage[ $tool ]['denied_seen'];
		} else {
			++$coverage[ $tool ]['executed'];
			if ( 'allowed' === $case['outcome'] ) {
				++$coverage[ $tool ]['allowed_seen'];
			} elseif ( 'denied' === $case['outcome'] ) {
				++$coverage[ $tool ]['denied_seen'];
			}
		}
		if ( true !== ( $case['ok'] ?? false ) ) {
			++$failed;
		}
	}
	$a_h    = array();
	$a_h_ok = true;
	foreach ( (array) ( $data['a_h_linkage'] ?? array() ) as $test_id => $case_id ) {
		$linked = $cases[ (string) $case_id ] ?? null;
		$ok     = is_array( $linked ) && true === ( $linked['ok'] ?? false );
		$a_h_ok = $a_h_ok && $ok;
		$a_h[]  = array(
			'test_id' => (string) $test_id,
			'case'    => (string) $case_id,
			'ok'      => $ok,
			'outcome' => is_array( $linked ) ? (string) ( $linked['outcome'] ?? 'missing' ) : 'missing',
		);
	}
	$auth = array(
		'attempts' => 0,
		'http'     => array(
			401 => 0,
			403 => 0,
			429 => 0,
		),
	);
	foreach ( $cases as $case ) {
		if ( 'auth' !== ( $case['layer'] ?? '' ) || ! in_array( (string) ( $case['actor'] ?? '' ), array( 'subscriber', 'banned', 'anonymous' ), true ) ) {
			continue;
		}
		++$auth['attempts'];
		$code = (int) ( $case['http'] ?? 0 );
		if ( isset( $auth['http'][ $code ] ) ) {
			++$auth['http'][ $code ];
		}
	}
	$rate           = $cases['CAT-rate-auth-burst-denied'] ?? array( 'ok' => false );
	$report         = array(
		'suite'         => 'catalog-role-matrix',
		'version'       => 1,
		'target'        => (string) ( $main['target'] ?? '' ),
		'campaign'      => (string) ( $main['campaign'] ?? '' ),
		'blog'          => (int) ( $main['blog'] ?? 0 ),
		'network'       => (bool) ( $main['network'] ?? false ),
		'phase'         => 'finish',
		'phase_files'   => array_values( array_unique( $phase_files ) ),
		'revision'      => (string) ( $main['revision'] ?? 'unavailable' ),
		'inputs'        => $main['inputs'] ?? array(),
		'catalog'       => $main['catalog'] ?? array(),
		'cases'         => $cases,
		'coverage'      => $coverage,
		'counts'        => array(
			'declared' => count( $cases ),
			'executed' => count( $cases ) - $na - $covered,
			'covered'  => $covered,
			'na'       => $na,
			'failed'   => $failed,
			'requests' => $requests,
			'tools'    => count( $coverage ),
		),
		'rate'          => $rate,
		'auth'          => $auth,
		'a_h'           => $a_h,
		'scope_gaps'    => $main['scope_gaps'] ?? array(),
		'deferred'      => $main['deferred'] ?? array(),
		'schema_mode'   => (string) ( $main['schema_mode'] ?? 'subset' ),
		'foreign_purge' => (int) ( $main['foreign_purge'] ?? 0 ),
		'cleanup'       => false,
	);
	$report['gate'] = 0 === $failed && $a_h_ok && 0 === $auth['http'][429] && 186 === $auth['attempts'] && true === ( $rate['ok'] ?? false );
	return $report;
}

/**
 * Execute one declared case with before/after state read-backs.
 *
 * @param array<string,mixed> $case      Case.
 * @param array<string,mixed> $state     State.
 * @param array<string,mixed> $data      Declarations.
 * @param array<string,mixed> $catalog   Catalog map.
 * @param array<string,mixed> $tokens    Tokens.
 * @param array<string,mixed> $state_before State report.
 * @param McpClient           $client    Transport.
 * @param McpClient|null      $other     Other blog transport.
 * @param int                 $request_id Request counter, by reference.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_case( array $case, array $state, array $data, array $catalog, array $tokens, array $state_before, McpClient $client, ?McpClient $other, int &$request_id ): array {
	$id     = (string) $case['id'];
	$actor  = (string) $case['actor'];
	$token  = (string) ( $tokens[ (string) $case['key'] ] ?? '' );
	$canary = 'CR-CANARY-' . $id;
	$result = array(
		'id'              => $id,
		'tool'            => (string) $case['tool'],
		'pack'            => (string) $case['pack'],
		'actor'           => $actor,
		'expected'        => (string) $case['expected'],
		'layer'           => (string) $case['layer'],
		'reason'          => (string) $case['reason'],
		'ok'              => false,
		'outcome'         => 'failed',
		'http'            => null,
		'schema'          => null,
		'redacted'        => true,
		'state_unchanged' => null,
		'state_proof'     => 'none',
	);
	try {
		if ( isset( $case['variant_action'] ) ) {
			xnetvn_agent_hub_wp_catalog_role_matrix_variant( (string) $case['variant_action'] );
		}
		if ( 'catalog.tools_list_other' === $case['payload'] ) {
			++$request_id;
			$response                  = xnetvn_agent_hub_wp_catalog_role_matrix_tools( $other, $token, $request_id );
			$result['http']            = (int) ( $response['status'] ?? 0 );
			$visible                   = xnetvn_agent_hub_wp_catalog_role_matrix_tool_names( $response );
			$result['ok']              = in_array( $result['http'], array( 401, 403, 404 ), true ) && array() === $visible;
			$result['outcome']         = $result['ok'] ? 'denied' : 'failed';
			$result['reason']          = $result['ok'] ? 'authentication_required' : 'internal_error';
			$result['redacted']        = xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, $token, '' );
			$result['state_unchanged'] = true;
			$result['state_proof']     = 'no_execution';
			$result['observed_reason'] = $result['ok'] ? 'authentication_required' : 'internal_error';
			if ( isset( $case['variant_action'] ) ) {
				xnetvn_agent_hub_wp_catalog_role_matrix_variant( 'restore' );
			}
			return $result;
		}
		if ( 'catalog.tools_list' === $case['payload'] ) {
			++$request_id;
			$response                  = xnetvn_agent_hub_wp_catalog_role_matrix_tools( $client, $token, $request_id );
			$result['http']            = (int) ( $response['status'] ?? 0 );
			$visible                   = xnetvn_agent_hub_wp_catalog_role_matrix_tool_names( $response );
			$expected                  = xnetvn_agent_hub_wp_catalog_role_visible_tools( $data, $actor );
			$result['schema']          = true;
			$result['redacted']        = xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, $token, '' );
			$result['ok']              = 200 === $result['http'] && $visible === $expected && $result['redacted'];
			$result['outcome']         = $result['ok'] ? 'allowed' : 'failed';
			$result['reason']          = $result['ok'] ? 'unavailable' : 'internal_error';
			$result['observed_reason'] = $result['ok'] ? 'unavailable' : 'internal_error';
			$result['visible_count']   = count( $visible );
			$result['expected_count']  = count( $expected );
			if ( ! $result['ok'] ) {
				$result['mismatch'] = 'tools_list';
			}
			return $result;
		}
		$context     = xnetvn_agent_hub_wp_catalog_role_matrix_context( $case, $state, $data );
		$payload     = xnetvn_agent_hub_wp_catalog_role_payload( $case, $context );
		$payload     = xnetvn_agent_hub_wp_catalog_role_mark_logical( (string) $case['payload'], $payload );
		$payload     = xnetvn_agent_hub_wp_catalog_role_resolve( $payload, (array) $state['ids'] );
		$mutation    = xnetvn_agent_hub_wp_catalog_role_matrix_mutation( (string) $case['payload'] );
		$read_plan   = xnetvn_agent_hub_wp_catalog_role_matrix_read_plan( $case, $context, $mutation );
		$probe_token = xnetvn_agent_hub_wp_catalog_role_matrix_probe( $id, $tokens );
		$before_read = null;
		if ( isset( $read_plan['needed'] ) && true === $read_plan['needed'] && true === ( $read_plan['before'] ?? false ) ) {
			$before_read              = xnetvn_agent_hub_wp_catalog_role_matrix_read_state( $read_plan, $client, $probe_token, array(), $request_id );
			$result['before_digest']  = (string) ( $before_read['digest'] ?? '' );
			$result['read_before_ok'] = true === ( $before_read['ok'] ?? false );
		}
		if ( 'catalog.discover' === $case['payload'] ) {
			++$request_id;
			$response = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, (string) $case['tool'], $payload, $request_id );
		} elseif ( 'catalog.info' === $case['payload'] ) {
			++$request_id;
			$response = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, (string) $case['tool'], $payload, $request_id );
		} else {
			++$request_id;
			$response = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, (string) $case['tool'], $payload, $request_id );
		}
		$result['http'] = (int) ( $response['status'] ?? 0 );
		$body           = $response['body'] ?? null;
		$after_read     = null;
		if ( isset( $read_plan['needed'] ) && true === $read_plan['needed'] ) {
			$created_output          = is_array( $body['result']['structuredContent'] ?? null ) ? $body['result']['structuredContent'] : array();
			$after_read              = xnetvn_agent_hub_wp_catalog_role_matrix_read_state( $read_plan, $client, $probe_token, $created_output, $request_id );
			$result['after_digest']  = (string) ( $after_read['digest'] ?? '' );
			$result['read_after_ok'] = true === ( $after_read['ok'] ?? false );
			$result['read_back']     = (string) ( $read_plan['mode'] ?? '' );
		}
		if ( 'denied' === $case['expected'] && in_array( $case['payload'], array( 'taxonomy.bulk_create_terms', 'taxonomy.bulk_assign_post_terms', 'content.bulk_update_posts', 'comments.bulk_moderate' ), true ) && is_array( $body['result']['structuredContent'] ?? null ) ) {
			$structured = $body['result']['structuredContent'];
			$failed     = $structured['failed'] ?? array();
			$changed    = $structured['updated'] ?? $structured['created'] ?? array();
			$all_failed = is_array( $failed ) && array() !== $failed;
			foreach ( (array) $failed as $entry ) {
				if ( ! is_array( $entry ) || (string) ( $entry['code'] ?? '' ) !== (string) $case['reason'] ) {
					$all_failed = false;
				}
			}
			$schema                    = $catalog[ (string) $case['name'] ]['output_schema'] ?? null;
			$result['schema']          = is_array( $schema ) && xnetvn_agent_hub_wp_catalog_role_matrix_schema( $structured, $schema );
			$result['redacted']        = xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, $token, $canary );
			$partial_state             = is_array( $before_read ) && is_array( $after_read ) && true === ( $before_read['ok'] ?? false ) && true === ( $after_read['ok'] ?? false ) && hash_equals( (string) ( $before_read['digest'] ?? '' ), (string) ( $after_read['digest'] ?? '' ) );
			$result['ok']              = $all_failed && array() === $changed && true === $result['schema'] && true === $result['redacted'] && $partial_state;
			$result['outcome']         = $result['ok'] ? 'denied' : 'failed';
			$result['reason']          = $result['ok'] ? (string) $case['reason'] : 'internal_error';
			$result['observed_reason'] = $result['ok'] ? (string) $case['reason'] : 'internal_error';
			$result['state_unchanged'] = $partial_state;
			$result['state_proof']     = 'partial_failure_read_back';
			return $result;
		}
		if ( 'allowed' === $case['expected'] ) {
			$structured = $body['result']['structuredContent'] ?? null;
			$is_error   = true === ( $body['result']['isError'] ?? false );
			$text       = $body['result']['content'][0]['text'] ?? '';
			$schema     = null;
			if ( 'catalog.info' === $case['payload'] ) {
				$schema = $catalog['xnetvn/get-ability-info']['output_schema'] ?? null;
			} elseif ( 'catalog.discover' === $case['payload'] ) {
				$schema = $catalog['xnetvn/discover-abilities']['output_schema'] ?? null;
			} elseif ( 'system.site' === $case['payload'] ) {
				$schema = $catalog['xnetvn/get-site-info']['output_schema'] ?? null;
			} elseif ( isset( $catalog[ (string) $case['name'] ] ) ) {
				$schema = $catalog[ (string) $case['name'] ]['output_schema'] ?? null;
			}
			$result['schema']          = is_array( $schema ) ? ( is_array( $structured ) && xnetvn_agent_hub_wp_catalog_role_matrix_schema( $structured, $schema ) ) : false;
			$result['redacted']        = xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, $token, '' );
			$result['ok']              = 200 === $result['http'] && ! $is_error && is_array( $structured ) && true === $result['schema'] && is_string( $text ) && '' !== $text && $result['redacted'];
			$result['outcome']         = $result['ok'] ? 'allowed' : 'failed';
			$result['reason']          = $result['ok'] ? 'unavailable' : 'internal_error';
			$result['observed_reason'] = $result['ok'] ? 'unavailable' : 'internal_error';
			if ( $result['ok'] && 'catalog.info' === $case['payload'] ) {
				$info         = $catalog[ (string) $case['extra'] ] ?? null;
				$result['ok'] = is_array( $info ) && ( $structured['name'] ?? '' ) === ( $info['name'] ?? '' ) && ( $structured['tier'] ?? '' ) === ( $info['tier'] ?? '' ) && ( $structured['toolset'] ?? '' ) === ( $info['toolset'] ?? '' ) && ( $structured['visibility_cap'] ?? '' ) === ( $info['visibility_cap'] ?? '' ) && xnetvn_agent_hub_wp_catalog_role_matrix_hash( $structured['input_schema'] ?? array() ) === xnetvn_agent_hub_wp_catalog_role_matrix_hash( $info['input_schema'] ?? array() ) && xnetvn_agent_hub_wp_catalog_role_matrix_hash( $structured['output_schema'] ?? array() ) === xnetvn_agent_hub_wp_catalog_role_matrix_hash( $info['output_schema'] ?? array() ) && xnetvn_agent_hub_wp_catalog_role_matrix_hash( $structured['annotations'] ?? array() ) === xnetvn_agent_hub_wp_catalog_role_matrix_hash( $info['annotations'] ?? array() );
				if ( ! $result['ok'] ) {
					$result['mismatch'] = 'descriptor';
				}
			}
			if ( $result['ok'] && 'catalog.discover' === $case['payload'] ) {
				$expected = array();
				foreach ( $data['tools'] as $name => $tool ) {
					if ( $tool['pack'] === (string) $case['extra'] ) {
						$expected[] = $name;
					}
				}
				$items = $structured['items'] ?? array();
				$names = array();
				foreach ( (array) $items as $item ) {
					if ( is_array( $item ) && isset( $item['name'] ) && is_string( $item['name'] ) ) {
						$names[] = $item['name'];
					}
				}
				sort( $names, SORT_STRING );
				sort( $expected, SORT_STRING );
				$result['ok'] = (int) ( $structured['total'] ?? -1 ) === count( $expected ) && $names === $expected;
				if ( ! $result['ok'] ) {
					$result['mismatch'] = 'discover';
				}
			}
			if ( $result['ok'] && 'system.site' === $case['payload'] ) {
				$url          = (string) ( $structured['url'] ?? '' );
				$result['ok'] = rtrim( $url, '/' ) === rtrim( $GLOBALS['xnetvn_catalog_role_matrix_base'], '/' );
				if ( ! $result['ok'] ) {
					$result['mismatch'] = 'blog_url';
				}
			}
			if ( is_array( $structured ) && false === $is_error ) {
				$journal = $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/journal.jsonl';
				$created = xnetvn_agent_hub_wp_catalog_role_matrix_created( (string) $case['payload'], $structured, $actor, (string) $state['campaign'] );
				foreach ( $created as $entry ) {
					xnetvn_agent_hub_wp_catalog_role_matrix_journal( $journal, $entry );
				}
			}
			if ( true === $result['ok'] && isset( $read_plan['needed'] ) && true === $read_plan['needed'] ) {
				$before_ok  = is_array( $before_read ) && true === ( $before_read['ok'] ?? false );
				$after_ok   = is_array( $after_read ) && true === ( $after_read['ok'] ?? false );
				$same_state = $before_ok && $after_ok && hash_equals( (string) ( $before_read['digest'] ?? '' ), (string) ( $after_read['digest'] ?? '' ) );
				if ( 'create' === $mutation ) {
					$result['ok']              = $result['ok'] && $after_ok && true === ( $after_read['found'] ?? false );
					$result['state_unchanged'] = false;
					$result['state_proof']     = 'read_back_created';
				} elseif ( 'update' === $mutation ) {
					$result['ok']              = $result['ok'] && $before_ok && $after_ok && true === ( $after_read['found'] ?? false );
					$result['state_unchanged'] = $same_state;
					$result['state_changed']   = ! $same_state;
					$result['state_proof']     = 'read_back_found';
				} elseif ( 'purge' === $mutation ) {
					$before_total              = (int) ( $before_read['spam'] ?? 0 ) + (int) ( $before_read['trash'] ?? 0 );
					$after_total               = (int) ( $after_read['spam'] ?? 0 ) + (int) ( $after_read['trash'] ?? 0 );
					$purged                    = ( $before_total > 0 && $after_total < $before_total ) || ( 0 === $before_total && 0 === $after_total );
					$result['ok']              = $result['ok'] && $before_ok && $after_ok && $purged;
					$result['state_unchanged'] = 0 === $before_total && 0 === $after_total;
					$result['state_changed']   = ! $result['state_unchanged'];
					$result['state_proof']     = 'purge_read_back';
					$result['purge_noop']      = 0 === $before_total && 0 === $after_total;
					$result['before_total']    = $before_total;
					$result['after_total']     = $after_total;
				} elseif ( 'delete' === $mutation ) {
					$result['ok']              = $result['ok'] && $before_ok && true === ( $before_read['found'] ?? false ) && $after_ok && true === ( $after_read['absent'] ?? false );
					$result['state_unchanged'] = false;
					$result['state_proof']     = 'read_back_absent';
				} else {
					$result['ok']              = $result['ok'] && $same_state && true === ( $after_read['found'] ?? false );
					$result['state_unchanged'] = $same_state;
					$result['state_proof']     = 'read_back_unchanged';
				}
			}
			return $result;
		}
		$result['redacted'] = xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, $token, $canary );
		$observed           = xnetvn_agent_hub_wp_catalog_role_matrix_reason( $response );
		if ( 'schema' === $case['layer'] ) {
			$code                      = (int) ( $body['error']['code'] ?? 0 );
			$encoded                   = json_encode( $body, JSON_THROW_ON_ERROR );
			$result['ok']              = ( 400 === $result['http'] || -32602 === $code ) && ( -32602 === $code || str_contains( $encoded, 'invalid params' ) || str_contains( $encoded, '-32602' ) );
			$result['outcome']         = $result['ok'] ? 'denied' : 'failed';
			$result['reason']          = $result['ok'] ? 'invalid_params' : 'internal_error';
			$result['observed_reason'] = $result['ok'] ? 'invalid_params' : 'internal_error';
			$result['redacted']        = $result['ok'] && $result['redacted'];
			return $result;
		}
		$is_error = true === ( $body['result']['isError'] ?? false );
		$state_ok = true;
		if ( isset( $read_plan['needed'] ) && true === $read_plan['needed'] ) {
			$state_ok = is_array( $before_read ) && is_array( $after_read ) && true === ( $before_read['ok'] ?? false ) && true === ( $after_read['ok'] ?? false ) && hash_equals( (string) ( $before_read['digest'] ?? '' ), (string) ( $after_read['digest'] ?? '' ) );
		}
		$result['ok']              = 200 === $result['http'] && $is_error && true === $result['redacted'] && $observed === (string) $case['reason'] && $state_ok;
		$result['outcome']         = $result['ok'] ? 'denied' : 'failed';
		$result['reason']          = $result['ok'] ? (string) $case['reason'] : 'internal_error';
		$result['observed_reason'] = $observed;
		if ( isset( $read_plan['needed'] ) && true === $read_plan['needed'] ) {
			$result['state_unchanged'] = $state_ok;
			$result['state_proof']     = 'read_back';
		}
		return $result;
	} catch ( Throwable ) {
		$result['ok']              = false;
		$result['outcome']         = 'failed';
		$result['reason']          = 'internal_error';
		$result['observed_reason'] = 'internal_error';
		$result['redacted']        = false;
		return $result;
	} finally {
		if ( isset( $case['variant_action'] ) ) {
			try {
				xnetvn_agent_hub_wp_catalog_role_matrix_variant( 'restore' );
			} catch ( Throwable ) {
				$result['ok']      = false;
				$result['outcome'] = 'failed';
				$result['reason']  = 'internal_error';
			}
		}
	}
}

/**
 * Map one created success payload to owned journal entries.
 *
 * @param string              $payload    Payload key.
 * @param array<string,mixed> $structured Structured content.
 * @param string              $actor      Actor.
 * @param string              $campaign   Campaign.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_created( string $payload, array $structured, string $actor, string $campaign ): array {
	$entries = array();
	$add     = static function ( string $type, int $id ) use ( &$entries, $actor, $campaign ): void {
		if ( 1 <= $id ) {
			$entries[] = array(
				'type'   => $type,
				'id'     => $id,
				'owner'  => $actor,
				'marker' => $campaign,
			);
		}
	};
	if ( in_array( $payload, array( 'content.create_post', 'content.duplicate_post' ), true ) ) {
		$add( 'post', (int) ( $structured['id'] ?? 0 ) );
	} elseif ( 'media.upload_data' === $payload ) {
		$add( 'media', (int) ( $structured['id'] ?? 0 ) );
	} elseif ( 'taxonomy.create_term' === $payload ) {
		$add( 'term', (int) ( $structured['id'] ?? 0 ) );
	} elseif ( 'taxonomy.bulk_create_terms' === $payload ) {
		foreach ( (array) ( $structured['created'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) ) {
				$add( 'term', (int) ( $entry['id'] ?? 0 ) );
			}
		}
	} elseif ( in_array( $payload, array( 'comments.create_comment', 'comments.reply_comment' ), true ) ) {
		$add( 'comment', (int) ( $structured['id'] ?? 0 ) );
	} elseif ( 'users.create_user' === $payload ) {
		$add( 'user', (int) ( $structured['id'] ?? 0 ) );
	}
	return $entries;
}

/**
 * Build the report coverage block.
 *
 * @param array<string,mixed> $coverage Coverage.
 * @param array<string,mixed> $results  Results.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_matrix_coverage( array $coverage, array $results ): array {
	$missing_allowed = array();
	$missing_denied  = array();
	foreach ( $coverage as $tool => $row ) {
		if ( 0 === $row['allowed_seen'] && 'xnetvn-upload-media-from-url' !== $tool ) {
			$missing_allowed[] = $tool;
		}
		if ( 0 === $row['denied_seen'] ) {
			$missing_denied[] = $tool;
		}
	}
	$failed  = 0;
	$na      = 0;
	$covered = 0;
	foreach ( $results as $result ) {
		if ( 'na' === $result['outcome'] ) {
			++$na;
		} elseif ( 'covered' === $result['outcome'] ) {
			++$covered;
		} elseif ( true !== $result['ok'] ) {
			++$failed;
		}
	}
	return array(
		'coverage'              => $coverage,
		'tools_missing_allowed' => $missing_allowed,
		'tools_missing_denied'  => $missing_denied,
		'failed'                => $failed,
		'na'                    => $na,
		'covered'               => $covered,
	);
}

try {
	$state          = xnetvn_agent_hub_wp_catalog_role_matrix_json_file( $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/state.json' );
	$catalog_rows   = xnetvn_agent_hub_wp_catalog_role_matrix_json_file( $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/catalog.json' );
	$state_before   = is_file( $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/state-before.json' ) ? xnetvn_agent_hub_wp_catalog_role_matrix_json_file( $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/state-before.json' ) : array();
	$catalog_digest = is_file( $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/catalog.json' ) ? hash_file( 'sha256', $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/catalog.json' ) : '';
	if ( ! is_array( $state ) || 'catalog-role-fixture' !== ( $state['suite'] ?? '' ) || ( $state['target'] ?? '' ) !== $GLOBALS['xnetvn_catalog_role_matrix_target'] || ( $state['campaign'] ?? '' ) !== $GLOBALS['xnetvn_catalog_role_matrix_campaign'] || (int) ( $state['blog'] ?? 0 ) !== (int) $GLOBALS['xnetvn_catalog_role_matrix_blog'] ) {
		throw new RuntimeException( 'Catalog role matrix state ownership mismatch.' );
	}
	if ( ! is_array( $catalog_rows ) || 62 !== count( $catalog_rows ) ) {
		throw new RuntimeException( 'Catalog role matrix catalog shape mismatch.' );
	}
	$catalog = array();
	foreach ( $catalog_rows as $row ) {
		if ( is_array( $row ) && isset( $row['name'] ) && is_string( $row['name'] ) ) {
			$catalog[ $row['name'] ] = $row;
		}
	}
	if ( 62 !== count( $catalog ) ) {
		throw new RuntimeException( 'Catalog role matrix catalog shape mismatch.' );
	}
	$base_host      = (string) ( parse_url( $GLOBALS['xnetvn_catalog_role_matrix_base'], PHP_URL_HOST ) ?? '' );
	$other_host     = '' === $GLOBALS['xnetvn_catalog_role_matrix_other'] ? '' : (string) ( parse_url( $GLOBALS['xnetvn_catalog_role_matrix_other'], PHP_URL_HOST ) ?? '' );
	$hosts          = array_values( array_filter( array( $base_host, $other_host ) ) );
	$client         = new McpClient( $GLOBALS['xnetvn_catalog_role_matrix_base'], $hosts, true );
	$other          = '' === $GLOBALS['xnetvn_catalog_role_matrix_other'] ? null : new McpClient( $GLOBALS['xnetvn_catalog_role_matrix_other'], $hosts, true );
	$tokens         = (array) ( $state['tokens'] ?? array() );
	$phase          = (string) $GLOBALS['xnetvn_catalog_role_matrix_phase'];
	$cases          = (array) $data['cases'];
	$pacing         = $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/auth-pacing.json';
	$auth_cases_all = array();
	foreach ( $cases as $case ) {
		if ( 'auth' === ( $case['layer'] ?? '' ) && 'catalog.tools_list_other' !== ( $case['payload'] ?? '' ) && in_array( (string) ( $case['actor'] ?? '' ), array( 'subscriber', 'banned', 'anonymous' ), true ) ) {
			$auth_cases_all[] = $case;
		}
	}
	$auth_cases_phase = array();
	if ( str_starts_with( $phase, 'auth-' ) && 1 === preg_match( '/\Aauth-(subscriber|banned|anonymous)-([12])\z/', $phase, $phase_match ) ) {
		$actor_cases      = array_values(
			array_filter(
				$auth_cases_all,
				static fn( array $case ): bool => (string) $case['actor'] === $phase_match[1]
			)
		);
		$auth_cases_phase = array_slice( $actor_cases, ( (int) $phase_match[2] - 1 ) * 31, 31 );
	}
	$run_main      = in_array( $phase, array( 'main', 'all' ), true );
	$run_auth      = 'all' === $phase || str_starts_with( $phase, 'auth-' );
	$coverage      = array();
	$results       = array();
	$auth_sequence = array();
	$request_id    = 0;

	$GLOBALS['xnetvn_catalog_role_matrix_results'] = array();

	if ( 'finish' === $phase ) {
		$merged = xnetvn_agent_hub_wp_catalog_role_matrix_merge( $GLOBALS['xnetvn_catalog_role_matrix_private'], $data );
		$file   = $GLOBALS['xnetvn_catalog_role_matrix_private'] . '/matrix.json';
		if ( is_link( $file ) || false === file_put_contents( $file, Json::encode( $merged ), LOCK_EX ) ) {
			throw new RuntimeException( 'Catalog role matrix report write failed.' );
		}
		chmod( $file, 0600 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable JSON evidence only.
		echo Json::encode( $merged ) . "\n";
		exit( true === ( $merged['gate'] ?? false ) ? 0 : 1 );
	}

	$foreign_purge = (int) ( $state_before['foreign_purge']['spam'] ?? 0 ) + (int) ( $state_before['foreign_purge']['trash'] ?? 0 );
	$scope_gaps    = array(
		array(
			'tool'   => 'xnetvn-upload-media-from-url',
			'reason' => 'remote_fetch_disabled_default',
			'note'   => 'An allowed runtime path needs an approved public HTTPS origin and media_remote_fetch_enabled; neither belongs to the five-file safe fixture.',
		),
		array(
			'tool'   => 'xnetvn/get-post',
			'reason' => 'mcp_adapter_input_schema_not_enforced',
			'note'   => 'Malformed tools/call params reached the tool instead of returning JSON-RPC invalid params; the static input-schema contract stays pinned by CatalogRoleMatrixTest.',
		),
	);
	if ( 0 < $foreign_purge ) {
		$scope_gaps[] = array(
			'tool'   => 'xnetvn-purge-comments',
			'reason' => 'foreign_purge_candidates',
			'note'   => 'The lab holds ' . $foreign_purge . ' foreign spam/trash comments; the safe fixture records N/A instead of deleting them.',
		);
	}
	$deferred = array(
		array(
			'area'  => 'schema_validator',
			'owner' => 'reviewer/planner',
			'note'  => 'Runtime output validation uses the documented subset (type/properties/required/items); the integration test validates payloads against the full input schema.',
		),
	);
	if ( $run_main ) {
		foreach ( $cases as $case ) {
			if ( 'auth' === $case['layer'] ) {
				continue;
			}
			if ( 'na' === $case['expected'] ) {
				$row = array(
					'id'              => (string) $case['id'],
					'tool'            => (string) $case['tool'],
					'pack'            => (string) $case['pack'],
					'actor'           => (string) $case['actor'],
					'expected'        => 'na',
					'layer'           => 'na',
					'reason'          => (string) $case['reason'],
					'ok'              => true,
					'outcome'         => 'na',
					'http'            => null,
					'schema'          => null,
					'redacted'        => true,
					'state_unchanged' => true,
					'state_proof'     => 'n/a',
					'observed_reason' => (string) $case['reason'],
					'scope_gap'       => 'remote_fetch_disabled_default' === $case['reason'],
				);
				xnetvn_agent_hub_wp_catalog_role_matrix_record( $row, $coverage );
				continue;
			}
			if ( true === ( $case['network_only'] ?? false ) && ! $xnetvn_catalog_role_matrix_network ) {
				$row = array(
					'id'              => (string) $case['id'],
					'tool'            => (string) $case['tool'],
					'pack'            => (string) $case['pack'],
					'actor'           => (string) $case['actor'],
					'expected'        => 'na',
					'layer'           => 'na',
					'reason'          => 'multisite_only_actor',
					'ok'              => true,
					'outcome'         => 'na',
					'http'            => null,
					'schema'          => null,
					'redacted'        => true,
					'state_unchanged' => true,
					'state_proof'     => 'n/a',
					'observed_reason' => 'multisite_only_actor',
				);
				xnetvn_agent_hub_wp_catalog_role_matrix_record( $row, $coverage );
				continue;
			}
			if ( 'comments.purge_comments' === $case['payload'] && 'allowed' === $case['expected'] ) {
				$foreign = (int) ( $state_before['foreign_purge']['spam'] ?? 0 ) + (int) ( $state_before['foreign_purge']['trash'] ?? 0 );
				if ( 0 < $foreign ) {
					$row = array(
						'id'              => (string) $case['id'],
						'tool'            => (string) $case['tool'],
						'pack'            => (string) $case['pack'],
						'actor'           => (string) $case['actor'],
						'expected'        => 'na',
						'layer'           => 'na',
						'reason'          => 'foreign_purge_candidates',
						'ok'              => true,
						'outcome'         => 'na',
						'http'            => null,
						'schema'          => null,
						'redacted'        => true,
						'state_unchanged' => true,
						'state_proof'     => 'n/a_foreign_purge',
						'observed_reason' => 'foreign_purge_candidates',
					);
					xnetvn_agent_hub_wp_catalog_role_matrix_record( $row, $coverage );
					continue;
				}
			}
			if ( 'catalog.tools_list_other' === $case['payload'] && ! $xnetvn_catalog_role_matrix_network ) {
				$row = array(
					'id'              => (string) $case['id'],
					'tool'            => (string) $case['tool'],
					'pack'            => (string) $case['pack'],
					'actor'           => (string) $case['actor'],
					'expected'        => 'na',
					'layer'           => 'na',
					'reason'          => 'single_site_has_no_second_blog',
					'ok'              => true,
					'outcome'         => 'na',
					'http'            => null,
					'schema'          => null,
					'redacted'        => true,
					'state_unchanged' => true,
					'state_proof'     => 'n/a',
					'observed_reason' => 'single_site_has_no_second_blog',
				);
				xnetvn_agent_hub_wp_catalog_role_matrix_record( $row, $coverage );
				continue;
			}
			if ( 'catalog.tools_list_other' === $case['payload'] && null === $other ) {
				throw new RuntimeException( 'Catalog role matrix other blog unavailable.' );
			}
			if ( 'rate' === $case['layer'] ) {
				continue;
			}
			if ( 'concurrency' === $case['layer'] ) {
				$concurrency_target = xnetvn_agent_hub_wp_catalog_role_matrix_target( (string) $case['target'], $state, $data );
				$result             = xnetvn_agent_hub_wp_catalog_role_matrix_concurrency( $case, $concurrency_target, $tokens, $client, $request_id );
				xnetvn_agent_hub_wp_catalog_role_matrix_record( $result, $coverage );
				continue;
			}
			if ( $xnetvn_catalog_role_matrix_network && isset( $case['network_denied'] ) ) {
				$case['expected'] = 'denied';
				$case['reason']   = (string) $case['network_denied'];
				$case['layer']    = 'tool';
			}
			$result = xnetvn_agent_hub_wp_catalog_role_matrix_case( $case, $state, $data, $catalog, $tokens, $state_before, $client, $other, $request_id );
			xnetvn_agent_hub_wp_catalog_role_matrix_record( $result, $coverage );
		}
	}
	if ( $run_main ) {
		$cross = $cases['CAT-blog-cross-key-admin-denied'] ?? null;
		if ( is_array( $cross ) && $xnetvn_catalog_role_matrix_network ) {
			$cross_token = (string) ( $tokens[ (string) $cross['key'] ] ?? '' );
			++$request_id;
			$cross_response = xnetvn_agent_hub_wp_catalog_role_matrix_tools( $other, $cross_token, $request_id );
			$cross_http     = (int) ( $cross_response['status'] ?? 0 );
			$cross_ok       = 401 === $cross_http && array() === xnetvn_agent_hub_wp_catalog_role_matrix_tool_names( $cross_response ) && xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $cross_response, $cross_token, '' );
			$cross_row      = array(
				'id'              => (string) $cross['id'],
				'tool'            => (string) $cross['tool'],
				'pack'            => (string) $cross['pack'],
				'actor'           => (string) $cross['actor'],
				'expected'        => 'denied',
				'layer'           => 'auth',
				'reason'          => 'authentication_required',
				'ok'              => $cross_ok,
				'outcome'         => $cross_ok ? 'denied' : 'failed',
				'http'            => $cross_http,
				'schema'          => null,
				'redacted'        => $cross_ok,
				'state_unchanged' => true,
				'state_proof'     => 'auth_denied',
				'observed_reason' => $cross_ok ? 'authentication_required' : 'auth_probe_failed',
			);
			xnetvn_agent_hub_wp_catalog_role_matrix_record( $cross_row, $coverage );
		} elseif ( is_array( $cross ) ) {
			$cross_row = array(
				'id'              => (string) $cross['id'],
				'tool'            => (string) $cross['tool'],
				'pack'            => (string) $cross['pack'],
				'actor'           => (string) $cross['actor'],
				'expected'        => 'na',
				'layer'           => 'na',
				'reason'          => 'multisite_only_actor',
				'ok'              => true,
				'outcome'         => 'na',
				'http'            => null,
				'schema'          => null,
				'redacted'        => true,
				'state_unchanged' => true,
				'state_proof'     => 'n/a',
				'observed_reason' => 'multisite_only_actor',
			);
			xnetvn_agent_hub_wp_catalog_role_matrix_record( $cross_row, $coverage );
		}
		sleep( 121 );
		$rate_http = array();
		for ( $rate_i = 1; $rate_i <= 11; $rate_i++ ) {
			++$request_id;
			$rate_response = xnetvn_agent_hub_wp_catalog_role_matrix_tools( $client, null, $request_id );
			$rate_status   = (int) ( $rate_response['status'] ?? 0 );
			$rate_http[]   = $rate_status;
			if ( 429 === $rate_status ) {
				break;
			}
		}
		$rate_first = 0;
		$rate_count = 0;
		foreach ( $rate_http as $rate_index => $rate_status ) {
			if ( 429 === $rate_status ) {
				++$rate_count;
				if ( 0 === $rate_first ) {
					$rate_first = $rate_index + 1;
				}
			}
		}
		$rate_ok  = 11 === $rate_first && 1 === $rate_count && 10 === count( array_filter( $rate_http, static fn( int $code ): bool => 401 === $code ) );
		$rate_row = array(
			'id'              => 'CAT-rate-auth-burst-denied',
			'tool'            => 'xnetvn-get-session-info',
			'pack'            => 'system',
			'actor'           => 'anonymous',
			'expected'        => 'denied',
			'layer'           => 'rate',
			'reason'          => 'rate_limited',
			'ok'              => $rate_ok,
			'outcome'         => $rate_ok ? 'denied' : 'failed',
			'http'            => 429,
			'schema'          => null,
			'redacted'        => true,
			'state_unchanged' => true,
			'state_proof'     => 'rate_burst',
			'observed_reason' => 'rate_limited',
			'attempts'        => count( $rate_http ),
			'first_429'       => $rate_first,
			'count_429'       => $rate_count,
		);
		xnetvn_agent_hub_wp_catalog_role_matrix_record( $rate_row, $coverage );
		sleep( 121 );
	}
	// Run the auth rows after every valid-key case: the shared lab charges
	// each failed authentication against one IP budget, so keeping them last
	// prevents a 429 from masking unrelated role/tool assertions. The clean
	// 121-second window makes the burst prove the real 10/minute threshold.
	if ( $run_auth ) {
		$auth_list    = 'all' === $phase ? $auth_cases_all : $auth_cases_phase;
		$pacing_state = xnetvn_agent_hub_wp_catalog_role_matrix_pacing( $pacing );
		foreach ( $auth_list as $case ) {
			$token   = 'anonymous' === (string) $case['actor'] ? null : (string) ( $tokens[ (string) $case['key'] ] ?? '' );
			$retries = 0;
			do {
				xnetvn_agent_hub_wp_catalog_role_matrix_pacing_wait( $pacing, $pacing_state );
				++$request_id;
				$response  = xnetvn_agent_hub_wp_catalog_role_matrix_call( $client, $token, (string) $case['tool'], array(), $request_id );
				$auth_http = (int) ( $response['status'] ?? 0 );
				if ( 429 === $auth_http && $retries < 2 ) {
					sleep( 121 );
					$pacing_state = array(
						'count'   => 0,
						'next_at' => 0,
					);
					xnetvn_agent_hub_wp_catalog_role_matrix_pacing_save( $pacing, $pacing_state );
					++$retries;
					continue;
				}
				break;
			} while ( true );
			$expected = (int) ( $case['http'] ?? ( 'anonymous' === (string) $case['actor'] ? 401 : 403 ) );
			$reason   = 429 === $auth_http ? 'rate_limited' : ( 401 === $auth_http ? 'authentication_required' : ( 403 === $auth_http ? 'forbidden' : 'unavailable' ) );
			$ok       = in_array( $auth_http, array( 401, 403 ), true ) && $auth_http === $expected && array() === xnetvn_agent_hub_wp_catalog_role_matrix_tool_names( $response ) && xnetvn_agent_hub_wp_catalog_role_matrix_redacted( $response, (string) $token, '' );
			if ( $ok ) {
				xnetvn_agent_hub_wp_catalog_role_matrix_pacing_used( $pacing, $pacing_state );
			}
			$row             = array(
				'id'              => (string) $case['id'],
				'tool'            => (string) $case['tool'],
				'pack'            => (string) $case['pack'],
				'actor'           => (string) $case['actor'],
				'expected'        => 'denied',
				'layer'           => 'auth',
				'reason'          => (string) $case['reason'],
				'ok'              => $ok,
				'outcome'         => $ok ? 'denied' : 'failed',
				'http'            => $auth_http,
				'schema'          => null,
				'redacted'        => $ok,
				'state_unchanged' => true,
				'state_proof'     => 429 === $auth_http ? 'rate_limited' : ( 0 < $retries ? 'auth_denied_retry' : 'auth_denied' ),
				'observed_reason' => $reason,
				'retries'         => $retries,
			);
			$auth_sequence[] = array(
				'id'   => (string) $case['id'],
				'http' => $auth_http,
			);
			xnetvn_agent_hub_wp_catalog_role_matrix_record( $row, $coverage );
		}
	}
	$rate_ok    = true;
	$auth_total = count( $auth_sequence );
	$first_429  = 0;
	$count_429  = 0;
	foreach ( $auth_sequence as $index => $auth_row ) {
		if ( 429 === (int) $auth_row['http'] ) {
			++$count_429;
			if ( 0 === $first_429 ) {
				$first_429 = $index + 1;
			}
		}
	}
	$a_h     = array();
	$a_h_ok  = true;
	$results = $GLOBALS['xnetvn_catalog_role_matrix_results'];
	foreach ( (array) ( $data['a_h_linkage'] ?? array() ) as $test_id => $case_id ) {
		$linked = $results[ (string) $case_id ] ?? null;
		if ( ! is_array( $linked ) ) {
			continue;
		}
		$ok     = is_array( $linked ) && true === ( $linked['ok'] ?? false );
		$a_h_ok = $a_h_ok && $ok;
		$a_h[]  = array(
			'test_id' => (string) $test_id,
			'case'    => (string) $case_id,
			'ok'      => $ok,
			'outcome' => is_array( $linked ) ? (string) ( $linked['outcome'] ?? 'missing' ) : 'missing',
		);
	}
	$summary     = xnetvn_agent_hub_wp_catalog_role_matrix_coverage( $coverage, $GLOBALS['xnetvn_catalog_role_matrix_results'] );
	$report      = array(
		'suite'                 => 'catalog-role-matrix',
		'version'               => 1,
		'phase'                 => $phase,
		'target'                => $GLOBALS['xnetvn_catalog_role_matrix_target'],
		'campaign'              => $GLOBALS['xnetvn_catalog_role_matrix_campaign'],
		'blog'                  => (int) $GLOBALS['xnetvn_catalog_role_matrix_blog'],
		'network'               => $xnetvn_catalog_role_matrix_network,
		'revision'              => '' === $GLOBALS['xnetvn_catalog_role_matrix_revision'] ? 'unavailable' : $GLOBALS['xnetvn_catalog_role_matrix_revision'],
		'inputs'                => array(
			'cases'       => hash_file( 'sha256', __DIR__ . '/catalog-role-cases.php' ),
			'fixture'     => hash_file( 'sha256', __DIR__ . '/catalog-role-fixture.php' ),
			'matrix'      => hash_file( 'sha256', __FILE__ ),
			'runner'      => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/infra/remote/run-catalog-role.sh' ),
			'integration' => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/tests/Integration/CatalogRoleMatrixTest.php' ),
		),
		'catalog'               => array(
			'tools'  => count( $catalog ),
			'digest' => 'unavailable' === $catalog_digest ? '' : $catalog_digest,
		),
		'cases'                 => $GLOBALS['xnetvn_catalog_role_matrix_results'],
		'coverage'              => $summary['coverage'],
		'counts'                => array(
			'declared' => count( $GLOBALS['xnetvn_catalog_role_matrix_results'] ),
			'executed' => count( $GLOBALS['xnetvn_catalog_role_matrix_results'] ) - $summary['na'] - $summary['covered'],
			'covered'  => $summary['covered'],
			'na'       => $summary['na'],
			'failed'   => $summary['failed'],
			'requests' => $request_id,
			'tools'    => count( $coverage ),
		),
		'tools_missing_allowed' => $summary['tools_missing_allowed'],
		'tools_missing_denied'  => $summary['tools_missing_denied'],
		'scope_gaps'            => $scope_gaps,
		'a_h'                   => $a_h,
		'rate'                  => $rate_row ?? null,
		'auth'                  => array(
			'attempts'  => $auth_total,
			'first_429' => $first_429,
			'count_429' => $count_429,
		),
		'deferred'              => $deferred,
		'schema_mode'           => 'subset',
		'foreign_purge'         => $foreign_purge,
		'cleanup'               => false,
	);
	$matrix_file = $GLOBALS['xnetvn_catalog_role_matrix_private'] . ( 'all' === $phase ? '/matrix.json' : '/matrix-' . $phase . '.json' );
	if ( is_link( $matrix_file ) || false === file_put_contents( $matrix_file, Json::encode( $report ), LOCK_EX ) ) {
		throw new RuntimeException( 'Catalog role matrix report write failed.' );
	}
	chmod( $matrix_file, 0600 );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable JSON evidence only.
	echo Json::encode( $report ) . "\n";
	$ignored_missing = array( 'xnetvn-upload-media-from-url' );
	if ( 0 < $foreign_purge ) {
		$ignored_missing[] = 'xnetvn-purge-comments';
	}
	if ( 'main' === $phase ) {
		$fail = $summary['failed'] > 0 || ! $a_h_ok || ! $rate_ok;
	} elseif ( str_starts_with( $phase, 'auth-' ) ) {
		$fail = $summary['failed'] > 0;
	} else {
		$fail = $summary['failed'] > 0 || array() !== $summary['tools_missing_denied'] || array() !== array_diff( $summary['tools_missing_allowed'], $ignored_missing ) || ! $a_h_ok || ! $rate_ok;
	}
	exit( $fail ? 1 : 0 );
} catch ( Throwable $xnetvn_catalog_role_matrix_error ) {
	$report = array(
		'suite'    => 'catalog-role-matrix',
		'target'   => $GLOBALS['xnetvn_catalog_role_matrix_target'],
		'campaign' => $GLOBALS['xnetvn_catalog_role_matrix_campaign'],
		'blocked'  => true,
		'error'    => 'matrix_blocked',
		'cases'    => $GLOBALS['xnetvn_catalog_role_matrix_results'] ?? array(),
		'cleanup'  => false,
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable JSON evidence only.
	echo Json::encode( $report ) . "\n";
	exit( 2 );
}
