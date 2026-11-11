<?php
/**
 * Acceptance manifest and verifier for the MCP role/SEO campaign.
 *
 * The tool has four offline modes and never touches WordPress, the network
 * or a lab:
 *
 * - `prepare` creates the owned report root of one campaign, computes the
 *   real code fingerprint and writes the expected manifest.
 * - `verify` reads the sanitized phase reports of a campaign, compares the
 *   expected manifest with the observed cases and scans every artifact for
 *   credential-shaped content.
 * - `fingerprint` prints the fingerprint of one source tree; the remote
 *   runner uses the same code path, so the local and the synced copy of a
 *   campaign always agree.
 * - `phase` merges the captured group reports of one phase into the single
 *   secret-free JSON block that travels between the two phases.
 *
 * `selftest` runs the negative paths offline: a missing or duplicated
 * report block, malformed JSON, a manifest with a missing case, a stale
 * fingerprint, a false cleanup flag and a synthetic canary in an artifact
 * must all be refused, and the refusal must never print the matched text.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone owned CLI report tool: no WordPress bootstrap, no logged credentials, fixed owned paths only.

/** Campaign id shape shared by the runner, the manifest and the verifier. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_CAMPAIGN_PATTERN = '/\Arole-seo-[0-9]{8}T[0-9]{6}Z\z/';

/** Marker that opens the single sanitized JSON block of one phase. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN = 'ROLE_SEO_REPORT_BEGIN';

/** Marker that closes the single sanitized JSON block of one phase. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END = 'ROLE_SEO_REPORT_END';

/** Source roots that take part in the code fingerprint, relative to the project root. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_FINGERPRINT_ROOTS = array(
	'composer.lock',
	'uninstall.php',
	'xnetvn-agent-hub-wp.php',
	'src',
	'tests',
	'infra/remote',
);

/** Directory names that never take part in the code fingerprint. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_FINGERPRINT_SKIP_DIRS = array( '__pycache__' );

/** Credential-shaped fragments that never belong in a report artifact. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_SECRET_PATTERNS = array(
	'authorization-header' => '/(?:^|[\s"\'=])(?:authorization|bearer|basic)\s*:\s*[^\s"\']{8,}/i',
	'bearer-value'         => '/\bbearer\s+[A-Za-z0-9\-._~+\/]{8,}/i',
	'cookie-header'        => '/\bset-cookie\s*:/i',
	'wordpress-cookie'     => '/\bwordpress_logged_in_[A-Za-z0-9]+/',
	'wp-settings-cookie'   => '/\bwp-settings-\d+/',
	'live-key'             => '/\bxnetvn_(?:live|sk|api)[A-Za-z0-9_\-]{8,}/i',
	'secret-json-value'    => '/"(?:token|secret|password|passwd|cookie|bearer|authorization|api_key)"\s*:\s*"[^"]{4,}"/i',
	'synthetic-canary'     => '/ACCEPTANCE-CANARY-[A-Za-z0-9\-]{4,}/',
);

/** Report keys that never belong in a phase report. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_FORBIDDEN_KEYS = array(
	'arguments',
	'authorization',
	'bearer',
	'body',
	'cookie',
	'digest',
	'hash',
	'password',
	'passwd',
	'payload',
	'raw',
	'response',
	'secret',
	'token',
);

/**
 * Read the command line of the standalone tool.
 *
 * @param array<int,string> $argv Raw arguments.
 * @return array{mode:string,options:array<string,string>}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_parse( array $argv ): array {
	$mode    = isset( $argv[1] ) && is_string( $argv[1] ) ? $argv[1] : '';
	$options = array();
	$count   = count( $argv );
	for ( $index = 2; $index < $count; $index++ ) {
		$name = $argv[ $index ];
		if ( ! is_string( $name ) || ! str_starts_with( $name, '--' ) ) {
			return array(
				'mode'    => $mode,
				'options' => array(),
			);
		}
		$value = $argv[ $index + 1 ] ?? '';
		if ( ! is_string( $value ) || str_starts_with( $value, '--' ) ) {
			return array(
				'mode'    => $mode,
				'options' => array(),
			);
		}
		$options[ substr( $name, 2 ) ] = $value;
		++$index;
	}
	return array(
		'mode'    => $mode,
		'options' => $options,
	);
}

/**
 * Print one machine-readable line and stop.
 *
 * @param array<string,mixed> $payload Safe payload.
 * @param int                 $status  Exit status.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_emit( array $payload, int $status ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Machine-readable numeric/boolean CLI evidence only.
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( (int) $status );
}

/**
 * Resolve a path without following a symlink in any component.
 *
 * @param string $path Candidate path.
 * @return string Absolute path with no trailing separator.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_absolute( string $path ): string {
	if ( '' === $path ) {
		return '';
	}
	$absolute = str_starts_with( $path, '/' ) ? $path : ( getcwd() . '/' . $path );
	$parts    = array();
	foreach ( explode( '/', $absolute ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			array_pop( $parts );
			continue;
		}
		$parts[] = $segment;
	}
	return '/' . implode( '/', $parts );
}

/**
 * Check that no component of a path is a symlink and that the leaf is owned by the caller.
 *
 * @param string   $path     Absolute path.
 * @param bool     $must_exist Whether the leaf has to exist.
 * @param int|null $owner    Expected owner uid, or null to skip the ownership check.
 * @return array{ok:bool,reason:string}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_own( string $path, bool $must_exist, ?int $owner ): array {
	if ( '' === $path ) {
		return array(
			'ok'     => false,
			'reason' => 'empty_path',
		);
	}
	$segments = explode( '/', trim( $path, '/' ) );
	$current  = '';
	foreach ( $segments as $segment ) {
		$current .= '/' . $segment;
		if ( is_link( $current ) ) {
			return array(
				'ok'     => false,
				'reason' => 'symlink_component',
			);
		}
	}
	if ( ! file_exists( $path ) ) {
		if ( $must_exist ) {
			return array(
				'ok'     => false,
				'reason' => 'missing_path',
			);
		}
		return array(
			'ok'     => true,
			'reason' => 'absent',
		);
	}
	if ( null !== $owner ) {
		$stat = stat( $path );
		if ( ! is_array( $stat ) || (int) ( $stat['uid'] ?? -1 ) !== $owner ) {
			return array(
				'ok'     => false,
				'reason' => 'foreign_ownership',
			);
		}
	}
	return array(
		'ok'     => true,
		'reason' => 'owned',
	);
}

/**
 * Check that every component of a path is safe to write into.
 *
 * A component may not be a symlink. A component that another user owns and
 * may write into is refused as well, unless it is sticky: a sticky
 * directory such as the system temporary directory keeps its own owner in
 * control of the files it holds.
 *
 * @param string   $path  Absolute path.
 * @param int|null $owner Expected owner uid, or null to skip the ownership check.
 * @return array{ok:bool,reason:string}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_components( string $path, ?int $owner ): array {
	if ( '' === $path ) {
		return array(
			'ok'     => false,
			'reason' => 'empty_path',
		);
	}
	$segments = explode( '/', trim( $path, '/' ) );
	$current  = '';
	foreach ( $segments as $segment ) {
		$current .= '/' . $segment;
		if ( is_link( $current ) ) {
			return array(
				'ok'     => false,
				'reason' => 'symlink_component',
			);
		}
		if ( ! is_dir( $current ) || null === $owner ) {
			continue;
		}
		$stat = stat( $current );
		if ( ! is_array( $stat ) ) {
			continue;
		}
		$mode = (int) ( $stat['mode'] ?? 0 );
		if ( (int) ( $stat['uid'] ?? -1 ) === $owner ) {
			continue;
		}
		if ( 0 === ( $mode & 0022 ) ) {
			continue;
		}
		if ( 0 !== ( $mode & 01000 ) ) {
			continue;
		}
		return array(
			'ok'     => false,
			'reason' => 'foreign_ownership',
		);
	}
	return array(
		'ok'     => true,
		'reason' => 'owned',
	);
}

/**
 * Decide whether a file holds text code that belongs to the fingerprint.
 *
 * @param string $path File path.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_is_text( string $path ): bool {
	$handle = fopen( $path, 'rb' );
	if ( false === $handle ) {
		return false;
	}
	$head = (string) fread( $handle, 8192 );
	fclose( $handle );
	return ! str_contains( $head, "\0" );
}

/**
 * List the fingerprint files of one source tree.
 *
 * @param string $root Project root.
 * @return array<int,string> Sorted relative paths.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint_files( string $root ): array {
	$files = array();
	foreach ( XNETVN_AGENT_HUB_WP_ROLE_SEO_FINGERPRINT_ROOTS as $entry ) {
		$path = $root . '/' . $entry;
		if ( is_file( $path ) && ! is_link( $path ) ) {
			$files[] = $entry;
			continue;
		}
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			continue;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$relative = ltrim( str_replace( $root, '', $item->getPathname() ), '/' );
			if ( str_contains( '/' . $relative . '/', '/__pycache__/' ) ) {
				continue;
			}
			$segments = explode( '/', $relative );
			if ( in_array( '__pycache__', $segments, true ) ) {
				continue;
			}
			if ( ! $item->isFile() || $item->isLink() ) {
				continue;
			}
			if ( ! xnetvn_agent_hub_wp_role_seo_acceptance_is_text( $item->getPathname() ) ) {
				continue;
			}
			$files[] = $relative;
		}
	}
	$files = array_values( array_unique( $files ) );
	sort( $files, SORT_STRING );
	return $files;
}

/**
 * Compute the real code fingerprint of one source tree.
 *
 * The digest covers the sorted relative paths and the bytes of the source
 * files, so it never depends on `.git`, never trusts a hash announced by a
 * caller and can be computed again on the synced copy of the same tree.
 *
 * @param string $root Project root.
 * @return array{fingerprint:string,files:array<int,string>,count:int}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint( string $root ): array {
	$root   = rtrim( xnetvn_agent_hub_wp_role_seo_acceptance_absolute( $root ), '/' );
	$files  = xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint_files( $root );
	$digest = hash_init( 'sha256' );
	foreach ( $files as $relative ) {
		$bytes = file_get_contents( $root . '/' . $relative );
		if ( false === $bytes ) {
			continue;
		}
		hash_update( $digest, $relative . "\0" . hash( 'sha256', $bytes ) . "\n" );
	}
	return array(
		'fingerprint' => hash_final( $digest ),
		'files'       => $files,
		'count'       => count( $files ),
	);
}

/**
 * Build the canonical text of one declared case.
 *
 * @param array<string,mixed> $entry Declared case.
 * @return string
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_canonical_case( array $entry ): string {
	$parts = array();
	foreach ( array( 'id', 'owner', 'roles', 'scope_modes', 'variants', 'targets' ) as $key ) {
		$value = $entry[ $key ] ?? array();
		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $entry ) {
				$items[] = (string) $entry;
			}
			sort( $items, SORT_STRING );
			$value = implode( ',', $items );
		}
		$parts[] = (string) $value;
	}
	$parts[] = (string) (int) ( $entry['expects_exit'] ?? 0 );
	return implode( ';', $parts );
}

/**
 * Canonicalize a declared case list.
 *
 * @param array<int,array<string,mixed>> $cases Declared cases.
 * @return array<int,string>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_canonical_cases( array $cases ): array {
	$out = array();
	foreach ( $cases as $case ) {
		if ( ! is_array( $case ) ) {
			continue;
		}
		$out[] = xnetvn_agent_hub_wp_role_seo_acceptance_canonical_case( $case );
	}
	sort( $out, SORT_STRING );
	return $out;
}

/**
 * Read the declarative registry of the A-G acceptance groups.
 *
 * The registry is a snapshot of the group declarations of the owned test
 * files. The verifier compares it with the declarations that the groups
 * announce at runtime, so a drift between the two never passes silently.
 *
 * @return array<string,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_registry(): array {
	return xnetvn_agent_hub_wp_role_seo_acceptance_registry_data();
}

/**
 * Read the declarative registry rows.
 *
 * @return array<string,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_registry_data(): array {
	return array(
		'fixture'     => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'FX-01',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array( 'guard-cli', 'guard-env', 'guard-host', 'guard-run-id' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 2,
				),
				array(
					'id'           => 'FX-02',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'seeded' ),
					'variants'     => array( 'shape', 'banned-no-capability', 'mail-sink' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-03',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'read', 'write', 'destructive', 'admin' ),
					'variants'     => array( 'identity', 'token-not-disclosed' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-04',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array( 'seeded' ),
					'variants'     => array( 'idempotent-same-run', 'refuse-foreign-run' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-05',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array( 'seeded' ),
					'variants'     => array( 'foreign-user', 'foreign-option', 'symlink-state' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 2,
				),
				array(
					'id'           => 'FX-06',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array( 'seeded' ),
					'variants'     => array( 'fail-mid-seed', 'restore-baseline' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-07',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'canary', 'forced-failure' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-08',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array( 'normal-exit', 'assertion-exit', 'sigterm-child', 'sigkill-recover' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'FX-09',
					'owner'        => 'runner',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array( 'lock-contention', 'unknown-group' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 2,
				),
			),
		),
		'permissions' => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'AUTH-01',
					'owner'        => 'matrix',
					'roles'        => array( 'contributor' ),
					'scope_modes'  => array( 'read' ),
					'variants'     => array( 'list', 'direct', 'wrapper', 'info' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-02',
					'owner'        => 'matrix',
					'roles'        => array( 'editor', 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'own', 'other', 'wrapper' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-03',
					'owner'        => 'matrix',
					'roles'        => array( 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'write', 'read' ),
					'variants'     => array( 'publish', 'future', 'media', 'no-use' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-04',
					'owner'        => 'matrix',
					'roles'        => array( 'contributor', 'author', 'author_b', 'editor', 'admin' ),
					'scope_modes'  => array( 'write', 'destructive', 'admin' ),
					'variants'     => array( 'toolset', 'allow', 'deny', 'ceiling', 'admin-off', 'hidden' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-05',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'demote', 'restore' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-06',
					'owner'        => 'matrix',
					'roles'        => array( 'author', 'admin' ),
					'scope_modes'  => array( 'write', 'admin' ),
					'variants'     => array( 'ban', 'unban' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-07',
					'owner'        => 'matrix',
					'roles'        => array( 'author', 'admin' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'missing', 'malformed', 'expired', 'revoked', 'unknown', 'bad', 'cookie', 'cookie-bearer' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-08',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'exact', 'suffix', 'management' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-09',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'positive', 'null', 'unlisted', 'allowed', 'plain', 'oversize' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'AUTH-10',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'schema', 'unknown', 'canary', 'report' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
			),
		),
		'content'     => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'SEO-01',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'create' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-02',
					'owner'        => 'matrix',
					'roles'        => array( 'editor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'excerpt-only' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-03',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'publish', 'pending', 'forbidden', 'no-use' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-04',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'create', 'denied', 'existing' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-05',
					'owner'        => 'matrix',
					'roles'        => array( 'editor', 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'replace', 'append', 'remove', 'default', 'binding', 'other-owner' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-06',
					'owner'        => 'matrix',
					'roles'        => array( 'editor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'schema', 'unicode' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-07',
					'owner'        => 'matrix',
					'roles'        => array( 'author', 'editor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'own-raw', 'other-raw', 'private', 'kses', 'rendered' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'SEO-08',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'canary', 'meta', 'search', 'report' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
			),
		),
		'media'       => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'IMG-01',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
					'scope_modes'  => array( 'write', 'read' ),
					'variants'     => array( 'limits', 'hidden' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-02',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'feature', 'inline' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-03',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'update', 'featured' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-04',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'inline' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-05',
					'owner'        => 'matrix',
					'roles'        => array( 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'write', 'read' ),
					'variants'     => array( 'upload', 'update', 'featured' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-06',
					'owner'        => 'matrix',
					'roles'        => array( 'author' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'update', 'delete', 'inline' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-07',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'invalid' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-08',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'ceiling', 'over', 'pixels', 'restored' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-09',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'url' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'IMG-10',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'reconcile', 'cleanup' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
			),
		),
		'lifecycle'   => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'LIFE-01',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'transition', 'denied' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-02',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'cross-owner', 'published-guard' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-03',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'utc', 'ict', 'invalid' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-04',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'cron-due-utc', 'cron-due-ict' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-05',
					'owner'        => 'matrix',
					'roles'        => array( 'editor', 'author' ),
					'scope_modes'  => array( 'write', 'destructive', 'read' ),
					'variants'     => array( 'trash', 'restore', 'delete', 'invalid' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-06',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write', 'read' ),
					'variants'     => array( 'duplicate', 'hidden', 'missing' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-07',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'binding', 'swap-denied', 'restore' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-08',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'conflict', 'fresh', 'concurrent' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'LIFE-09',
					'owner'        => 'matrix',
					'roles'        => array( 'author', 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'bulk', 'partial-create', 'partial-duplicate' ),
					'targets'      => array( 'wp69', 'wp71', 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
			),
		),
		'stability'   => array(
			'phase' => 'single',
			'cases' => array(
				array(
					'id'           => 'STAB-01',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber', 'banned' ),
					'scope_modes'  => array( 'read', 'write' ),
					'variants'     => array( 'round-1', 'round-2', 'round-3', 'round-4', 'round-5' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-02',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
					'scope_modes'  => array( 'read', 'write' ),
					'variants'     => array( 'latency', 'memory' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-03',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'read' ),
					'variants'     => array( 'prerequisite' ),
					'targets'      => array( 'wp69' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-04',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'read' ),
					'variants'     => array( 'boundary', 'retry-after', 'recover' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-05',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'contend', 'release', 'timeout', 'crash' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-06',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator' ),
					'scope_modes'  => array( 'write' ),
					'variants'     => array( 'faulted', 'lost' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'STAB-07',
					'owner'        => 'matrix',
					'roles'        => array( 'administrator', 'editor', 'author', 'author_b', 'contributor', 'subscriber' ),
					'scope_modes'  => array( 'read' ),
					'variants'     => array( 'concurrent', 'health' ),
					'targets'      => array( 'wp69', 'wp71' ),
					'expects_exit' => 0,
				),
			),
		),
		'network'     => array(
			'phase' => 'network',
			'cases' => array(
				array(
					'id'           => 'NET-01',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-02',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-03',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-04',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-05',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-06',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
				array(
					'id'           => 'NET-07',
					'owner'        => 'matrix',
					'roles'        => array(),
					'scope_modes'  => array(),
					'variants'     => array(),
					'targets'      => array( 'network-main', 'network-child' ),
					'expects_exit' => 0,
				),
			),
		),
	);
}

/**
 * Read the campaign plan of the two sequential phases.
 *
 * @return array<string,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_plan(): array {
	return array(
		'single'  => array(
			'targets' => array( 'wp69', 'wp71' ),
			'groups'  => array( 'fixture', 'permissions', 'content', 'media', 'lifecycle', 'stability' ),
		),
		'network' => array(
			'targets' => array( 'network-main', 'network-child' ),
			'groups'  => array( 'permissions', 'content', 'media', 'lifecycle', 'network' ),
		),
	);
}

/**
 * Build the run slots of one group of one phase.
 *
 * The single-site phase runs one group on one site at a time. The network
 * phase runs the B-E matrices one blog at a time, because the two blogs
 * share the network tables, and runs the network group once for both blogs.
 *
 * @param string              $phase Phase name.
 * @param string              $group Group name.
 * @param array<string,mixed> $plan  Campaign plan.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_slots( string $phase, string $group, array $plan ): array {
	$slots = array();
	if ( 'network' === $phase ) {
		if ( 'network' === $group ) {
			return array(
				array(
					'key'     => 'network',
					'targets' => $plan['network']['targets'],
				),
			);
		}
		foreach ( $plan['network']['targets'] as $target ) {
			$slots[] = array(
				'key'     => $target,
				'targets' => array( $target ),
			);
		}
		return $slots;
	}
	foreach ( $plan['single']['targets'] as $target ) {
		$slots[] = array(
			'key'     => $target,
			'targets' => array( $target ),
		);
	}
	return $slots;
}

/**
 * Project the declared targets of a case list onto the targets of one phase.
 *
 * A group declares the targets of its own file. A group that runs on every
 * target of a phase but declares none of them, like the B-E matrices on the
 * network blogs, is compared on the whole phase.
 *
 * @param array<int,array<string,mixed>> $cases         Declared cases.
 * @param array<int,string>              $phase_targets Targets of the phase.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_project_cases( array $cases, array $phase_targets ): array {
	$projected = array();
	foreach ( $cases as $case ) {
		if ( ! is_array( $case ) ) {
			continue;
		}
		$declared        = isset( $case['targets'] ) && is_array( $case['targets'] ) ? $case['targets'] : array();
		$named           = array_values( array_intersect( $declared, $phase_targets ) );
		$case['targets'] = array() === $named ? array_values( $phase_targets ) : $named;
		$projected[]     = $case;
	}
	return $projected;
}

/**
 * Map every acceptance group to the ticket group it covers.
 *
 * The single-site phase runs the literal B-E matrix files on wp69 and wp71.
 * The network phase runs the same four literal B-E matrix files once per
 * network blog, one blog at a time, and then the network matrix of the
 * network group, whose NET-02-auth, NET-02-seo, NET-02-img and NET-02-life
 * cases replay the same invariants across both blogs.
 *
 * @return array<string,array<string,string>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_coverage(): array {
	return array(
		'fixture'     => array(
			'wp69'          => 'A',
			'wp71'          => 'A',
			'network-main'  => 'A',
			'network-child' => 'A',
		),
		'permissions' => array(
			'wp69'          => 'B',
			'wp71'          => 'B',
			'network-main'  => 'B',
			'network-child' => 'B',
		),
		'content'     => array(
			'wp69'          => 'C',
			'wp71'          => 'C',
			'network-main'  => 'C',
			'network-child' => 'C',
		),
		'media'       => array(
			'wp69'          => 'D',
			'wp71'          => 'D',
			'network-main'  => 'D',
			'network-child' => 'D',
		),
		'lifecycle'   => array(
			'wp69'          => 'E',
			'wp71'          => 'E',
			'network-main'  => 'E',
			'network-child' => 'E',
		),
		'stability'   => array(
			'wp69' => 'F',
			'wp71' => 'F',
		),
		'network'     => array(
			'network-main'  => 'G',
			'network-child' => 'G',
		),
	);
}

/**
 * Check that the owned counts of a group are unchanged by its run.
 *
 * @param array<string,mixed> $counts Counts of the group report.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_owned_ok( array $counts ): bool {
	$pairs = xnetvn_agent_hub_wp_role_seo_acceptance_count_pairs( $counts );
	if ( array() === $pairs ) {
		return false;
	}
	foreach ( $pairs as $pair ) {
		foreach ( array( 'users', 'keys', 'posts', 'terms', 'media' ) as $key ) {
			if ( ( $pair[0][ $key ] ?? null ) !== ( $pair[1][ $key ] ?? null ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Check that one digest of a group is unchanged by its run.
 *
 * @param array<string,mixed> $counts Counts of the group report.
 * @param string              $key    Digest key.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_digest_ok( array $counts, string $key ): bool {
	$pairs = xnetvn_agent_hub_wp_role_seo_acceptance_count_pairs( $counts );
	if ( array() === $pairs ) {
		return false;
	}
	foreach ( $pairs as $pair ) {
		if ( ! array_key_exists( $key, $pair[0] ) || ! array_key_exists( $key, $pair[1] ) ) {
			return false;
		}
		if ( (string) $pair[0][ $key ] !== (string) $pair[1][ $key ] ) {
			return false;
		}
	}
	return true;
}

/**
 * List the baseline and final count pairs of one group report.
 *
 * The single-site groups and the network B-E matrices report one pair. The
 * network group runs two parts and reports the counts of both blogs, so it
 * carries one pair per blog and per part.
 *
 * @param array<string,mixed> $counts Counts of the group report.
 * @return array<int,array<int,array<string,mixed>>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_count_pairs( array $counts ): array {
	$pairs = array();
	$add   = static function ( $before, $after ) use ( &$pairs ): void {
		if ( is_array( $before ) && is_array( $after ) ) {
			$pairs[] = array( $before, $after );
		}
	};
	if ( isset( $counts['baseline'] ) && isset( $counts['final'] ) ) {
		$add( $counts['baseline'], $counts['final'] );
		return $pairs;
	}
	if ( isset( $counts['before'] ) && isset( $counts['final'] ) ) {
		$add( $counts['before'], $counts['final'] );
		return $pairs;
	}
	foreach ( $counts as $value ) {
		if ( ! is_array( $value ) ) {
			continue;
		}
		foreach ( array( 'main', 'child' ) as $blog ) {
			if ( isset( $value[ 'before_' . $blog ], $value[ 'final_' . $blog ] ) ) {
				$add( $value[ 'before_' . $blog ], $value[ 'final_' . $blog ] );
			}
		}
	}
	return $pairs;
}

/**
 * Read the open gates of one campaign.
 *
 * The list always comes from the code of this file, never from the manifest:
 * the manifest is an artifact a reviewer reads, and a campaign that could
 * declare its own gates would be able to hide a red case of itself. The
 * manifest still carries the list, and a manifest that disagrees with the
 * code is reported as a problem.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_gates(): array {
	return xnetvn_agent_hub_wp_role_seo_acceptance_open_gates();
}

/**
 * Check that every open gate of the manifest is declared by the code.
 *
 * A manifest that names a gate the code does not know is an attempt to
 * silence a red case of this campaign, so the verifier refuses it.
 *
 * @param array<string,mixed> $manifest Decoded manifest.
 * @return array<int,string>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_manifest_gates( array $manifest ): array {
	$declared = isset( $manifest['open_gates'] ) && is_array( $manifest['open_gates'] ) ? $manifest['open_gates'] : array();
	$known    = array();
	foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_open_gates() as $gate ) {
		$known[] = xnetvn_agent_hub_wp_role_seo_acceptance_gate_key( $gate );
	}
	$unknown = array();
	foreach ( $declared as $gate ) {
		if ( ! is_array( $gate ) ) {
			continue;
		}
		$key = xnetvn_agent_hub_wp_role_seo_acceptance_gate_key( $gate );
		if ( ! in_array( $key, $known, true ) ) {
			$unknown[] = $key;
		}
	}
	return $unknown;
}

/**
 * Read the stable key of one open gate.
 *
 * @param array<string,mixed> $gate Declared gate.
 * @return string
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_gate_key( array $gate ): string {
	$kind = (string) ( $gate['kind'] ?? '' );
	if ( 'cleanup_cron' === $kind ) {
		$groups = isset( $gate['groups'] ) && is_array( $gate['groups'] ) ? $gate['groups'] : array();
		sort( $groups, SORT_STRING );
		return $kind . ':' . implode( ',', $groups );
	}
	return $kind . ':' . (string) ( $gate['case_id'] ?? '' ) . '@' . (string) ( $gate['target'] ?? '' );
}

/**
 * Check that the owner of an open gate is a ticket of this repository.
 *
 * The owner of an open gate must exist and must still be open. A gate
 * without a ticket, or with a ticket that reached its final status, is not
 * applied: the red case it would cover stays a red case of this campaign.
 *
 * @param string      $owner       Owner ticket id.
 * @param string|null $issues_root Ticket root of the owner lookup, null for the local ticket store.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( string $owner, ?string $issues_root = null ): bool {
	if ( '' === $owner ) {
		return false;
	}
	$root  = null === $issues_root ? dirname( __DIR__, 2 ) . '/.local/docs/en/issues' : rtrim( $issues_root, '/' );
	$paths = glob( $root . '/*/*/' . $owner . '.md' );
	if ( ! is_array( $paths ) || array() === $paths ) {
		return false;
	}
	return in_array( xnetvn_agent_hub_wp_role_seo_acceptance_ticket_status( $paths[0] ), XNETVN_AGENT_HUB_WP_ROLE_SEO_OPEN_STATUSES, true );
}

/**
 * Read the status of one ticket file.
 *
 * @param string $path Ticket path.
 * @return string Status of the ticket, empty when the file has none.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_ticket_status( string $path ): string {
	if ( ! is_file( $path ) || is_link( $path ) ) {
		return '';
	}
	$raw = file_get_contents( $path );
	if ( ! is_string( $raw ) ) {
		return '';
	}
	foreach ( preg_split( '/\R/', $raw ) as $line ) {
		if ( preg_match( '/^\|\s*(?:Status|Trạng thái)\b[^|]*\|\s*([^|]+?)\s*\|/u', $line, $matches ) ) {
			return trim( $matches[1] );
		}
	}
	return '';
}

/**
 * Find a ticket of this repository that is already closed.
 *
 * The offline selftest uses such a ticket to prove that a gate of a closed
 * owner never hides a red case of the campaign.
 *
 * @param string|null $issues_root Ticket root of the lookup, null for the local ticket store.
 * @return string Ticket id, empty when no closed ticket exists.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_closed_owner( ?string $issues_root = null ): string {
	$root = null === $issues_root ? dirname( __DIR__, 2 ) . '/.local/docs/en/issues' : rtrim( $issues_root, '/' );
	foreach ( array( 'others', 'bugs' ) as $folder ) {
		$paths = glob( $root . '/*/' . $folder . '/*.md' );
		if ( ! is_array( $paths ) ) {
			continue;
		}
		sort( $paths, SORT_STRING );
		foreach ( $paths as $path ) {
			$status = xnetvn_agent_hub_wp_role_seo_acceptance_ticket_status( $path );
			if ( '' !== $status && ! in_array( $status, XNETVN_AGENT_HUB_WP_ROLE_SEO_OPEN_STATUSES, true ) ) {
				return basename( $path, '.md' );
			}
		}
	}
	return '';
}

/**
 * Find the open gate that covers one red case.
 *
 * A case gate matches by case id and by target; the target never matches
 * every target of the campaign.
 *
 * @param array<int,array<string,mixed>> $gates       Open gates.
 * @param string                         $case_id     Case id.
 * @param string                         $target      Target name.
 * @param string|null                    $issues_root Ticket root of the owner lookup, null for the repository root.
 * @return array<string,mixed>|null
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array $gates, string $case_id, string $target, ?string $issues_root = null ): ?array {
	foreach ( $gates as $gate ) {
		if ( ! is_array( $gate ) || ( $gate['kind'] ?? '' ) !== 'case' ) {
			continue;
		}
		if ( (string) ( $gate['case_id'] ?? '' ) !== $case_id ) {
			continue;
		}
		if ( (string) ( $gate['target'] ?? '' ) !== $target ) {
			continue;
		}
		if ( ! xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( (string) ( $gate['owner'] ?? '' ), $issues_root ) ) {
			return null;
		}
		return $gate;
	}
	return null;
}

/**
 * Read the measured cause of one group cleanup flag.
 *
 * @param array<string,mixed> $report Phase report.
 * @param string              $group  Group name.
 * @param string              $target Target name.
 * @return array<string,bool>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_group_evidence( array $report, string $group, string $target ): array {
	$evidence = array(
		'owned_ok'    => false,
		'settings_ok' => false,
		'cron_ok'     => false,
	);
	foreach ( (array) ( $report['group_reports'] ?? array() ) as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		if ( (string) ( $entry['group'] ?? '' ) !== $group || (string) ( $entry['target'] ?? '' ) !== $target ) {
			continue;
		}
		return array(
			'owned_ok'    => true === ( $entry['owned_ok'] ?? false ),
			'settings_ok' => true === ( $entry['settings_ok'] ?? false ),
			'cron_ok'     => true === ( $entry['cron_ok'] ?? false ),
		);
	}
	return $evidence;
}

/**
 * Find the open gate that covers a cleanup failure explained by a cron drift.
 *
 * The gate only applies when the owned counts and the settings digest are
 * unchanged and the cron digest is the only difference, and when the owner
 * ticket of the group exists.
 *
 * @param array<int,array<string,mixed>> $gates       Open gates.
 * @param string                         $group       Group name.
 * @param string|null                    $issues_root Ticket root of the owner lookup, null for the repository root.
 * @return array<string,mixed>|null
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_cleanup_gate( array $gates, string $group, ?string $issues_root = null ): ?array {
	foreach ( $gates as $gate ) {
		if ( ! is_array( $gate ) || ( $gate['kind'] ?? '' ) !== 'cleanup_cron' ) {
			continue;
		}
		$groups = isset( $gate['groups'] ) && is_array( $gate['groups'] ) ? $gate['groups'] : array();
		if ( ! in_array( $group, $groups, true ) ) {
			continue;
		}
		if ( ! xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( (string) ( $gate['owner'] ?? '' ), $issues_root ) ) {
			return null;
		}
		return $gate;
	}
	return null;
}

/**
 * Read the open gates that belong to another ticket.
 *
 * A gate is a known red case, or a known red cleanup flag whose owner is a
 * different ticket and whose cause is measured, not guessed. Every gate
 * names one real ticket, one narrow key and one reason. The owner of a gate
 * must be a ticket that is still open: a closed ticket does not own an open
 * gate anymore, so its gate is not applied and the red case stays a
 * failure of this campaign. The verifier reports every gate separately and
 * never counts it as a pass of this campaign, but a gate never hides a
 * failure that is not declared here.
 *
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_open_gates(): array {
	return array(
		array(
			'kind'    => 'case',
			'case_id' => 'NET-03-ban-main-child',
			'target'  => 'network-main',
			'owner'   => 'ISSUE_v0.1_2026-10-21_02_xu-ly-4-ca-kiem-mcp-network-do-tren-hai-blog-multisite',
			'reason'  => 'The ban case of the network group is unstable on the shared lab; the open ticket takes the four failing network cases and their evidence.',
		),
		array(
			'kind'    => 'case',
			'case_id' => 'NET-03-ban-main-child',
			'target'  => 'network-child',
			'owner'   => 'ISSUE_v0.1_2026-10-21_02_xu-ly-4-ca-kiem-mcp-network-do-tren-hai-blog-multisite',
			'reason'  => 'The ban case of the network group is unstable on the shared lab; the open ticket takes the four failing network cases and their evidence.',
		),
		array(
			'kind'    => 'case',
			'case_id' => 'NET-04-collision-main-child',
			'target'  => 'network-main',
			'owner'   => 'ISSUE_v0.1_2026-10-21_02_xu-ly-4-ca-kiem-mcp-network-do-tren-hai-blog-multisite',
			'reason'  => 'The collision case of the network group is unstable on the shared lab; the open ticket takes the four failing network cases and their evidence.',
		),
		array(
			'kind'    => 'case',
			'case_id' => 'NET-04-collision-main-child',
			'target'  => 'network-child',
			'owner'   => 'ISSUE_v0.1_2026-10-21_02_xu-ly-4-ca-kiem-mcp-network-do-tren-hai-blog-multisite',
			'reason'  => 'The collision case of the network group is unstable on the shared lab; the open ticket takes the four failing network cases and their evidence.',
		),
	);
}

/**
 * Resolve the iteration axis of one declared case.
 *
 * The stability round case carries its five rounds as variants; the round
 * is the iteration of the manifest, so the variants collapse to the single
 * wildcard and the iterations run from one to five.
 *
 * @param array<string,mixed> $entry Declared case.
 * @return array<int,int>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_iterations( array $entry ): array {
	$variants = isset( $entry['variants'] ) && is_array( $entry['variants'] ) ? $entry['variants'] : array();
	$rounds   = array();
	foreach ( $variants as $variant ) {
		if ( is_string( $variant ) && 1 === preg_match( '/\Around-([1-5])\z/', $variant, $matches ) ) {
			$rounds[] = (int) $matches[1];
		}
	}
	if ( 5 !== count( $rounds ) ) {
		return array( 1 );
	}
	sort( $rounds, SORT_NUMERIC );
	return $rounds;
}

/**
 * Build the expected manifest of one campaign.
 *
 * @param array<string,array<string,mixed>> $registry Declarative registry.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_expected( array $registry ): array {
	$plan      = xnetvn_agent_hub_wp_role_seo_acceptance_plan();
	$instances = array();
	foreach ( $plan as $phase => $definition ) {
		foreach ( $definition['groups'] as $group ) {
			if ( ! isset( $registry[ $group ] ) || ! is_array( $registry[ $group ] ) ) {
				continue;
			}
			$cases = isset( $registry[ $group ]['cases'] ) && is_array( $registry[ $group ]['cases'] ) ? $registry[ $group ]['cases'] : array();
			foreach ( $cases as $case ) {
				if ( ! is_array( $case ) || ! isset( $case['id'] ) || ! is_string( $case['id'] ) ) {
					continue;
				}
				$declared = isset( $case['targets'] ) && is_array( $case['targets'] ) ? $case['targets'] : array();
				foreach ( $definition['targets'] as $target ) {
					if ( ! in_array( $target, $declared, true ) ) {
						continue;
					}
					$variants = isset( $case['variants'] ) && is_array( $case['variants'] ) ? $case['variants'] : array();
					if ( 5 === count( xnetvn_agent_hub_wp_role_seo_acceptance_iterations( $case ) ) ) {
						$variants = array( '*' );
					}
					$instances[] = array(
						'phase'        => $phase,
						'group'        => $group,
						'case_id'      => $case['id'],
						'target'       => $target,
						'owner'        => isset( $case['owner'] ) && is_string( $case['owner'] ) ? $case['owner'] : 'unknown',
						'roles'        => isset( $case['roles'] ) && is_array( $case['roles'] ) ? $case['roles'] : array(),
						'scope_modes'  => isset( $case['scope_modes'] ) && is_array( $case['scope_modes'] ) ? $case['scope_modes'] : array(),
						'variants'     => $variants,
						'iterations'   => xnetvn_agent_hub_wp_role_seo_acceptance_iterations( $case ),
						'expects_exit' => isset( $case['expects_exit'] ) ? (int) $case['expects_exit'] : 0,
					);
				}
			}
		}
	}
	return $instances;
}

/**
 * Count the manifest tuples of one expected instance list.
 *
 * @param array<int,array<string,mixed>> $instances Expected instances.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_matrix( array $instances ): array {
	$by_group   = array();
	$by_target  = array();
	$by_role    = array();
	$by_scope   = array();
	$by_variant = array();
	$total      = 0;
	foreach ( $instances as $instance ) {
		$roles                = is_array( $instance['roles'] ) && array() !== $instance['roles'] ? $instance['roles'] : array( '*' );
		$modes                = is_array( $instance['scope_modes'] ) && array() !== $instance['scope_modes'] ? $instance['scope_modes'] : array( '*' );
		$variants             = is_array( $instance['variants'] ) && array() !== $instance['variants'] ? $instance['variants'] : array( '*' );
		$loops                = is_array( $instance['iterations'] ) && array() !== $instance['iterations'] ? $instance['iterations'] : array( 1 );
		$tuples               = count( $roles ) * count( $modes ) * count( $variants ) * count( $loops );
		$total               += $tuples;
		$group                = (string) $instance['group'];
		$target               = (string) $instance['target'];
		$by_group[ $group ]   = ( $by_group[ $group ] ?? 0 ) + $tuples;
		$by_target[ $target ] = ( $by_target[ $target ] ?? 0 ) + $tuples;
		foreach ( $roles as $role ) {
			$by_role[ (string) $role ] = ( $by_role[ (string) $role ] ?? 0 ) + ( $tuples / count( $roles ) );
		}
		foreach ( $modes as $mode ) {
			$by_scope[ (string) $mode ] = ( $by_scope[ (string) $mode ] ?? 0 ) + ( $tuples / count( $modes ) );
		}
		foreach ( $variants as $variant ) {
			$by_variant[ (string) $variant ] = ( $by_variant[ (string) $variant ] ?? 0 ) + ( $tuples / count( $variants ) );
		}
	}
	$matrix = array();
	foreach ( array( 'by_group', 'by_target', 'by_role', 'by_scope', 'by_variant' ) as $bucket ) {
		$values = array();
		foreach ( ${$bucket} as $name => $count ) {
			$values[ (string) $name ] = (int) round( $count );
		}
		ksort( $values, SORT_STRING );
		$matrix[ $bucket ] = $values;
	}
	$matrix['total'] = $total;
	return $matrix;
}

/** Fixed vocabulary of the ticket states that still own an open gate. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_OPEN_STATUSES = array( 'Draft', 'Ready', 'In Progress', 'In Review' );

/** Fixed public vocabulary of the acceptance case reasons; mirrors the role/SEO harness. */
const XNETVN_AGENT_HUB_WP_ROLE_SEO_REASONS = array(
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
	'not_applicable_target',
	'unknown_case',
	'unknown_variant',
	'missing_case',
	'duplicate_case',
	'skipped',
);

/**
 * Map one observed case id onto its declared case, role and variant.
 *
 * The role and the variant are resolved from the case id only; a case id
 * that names none of them keeps the wildcard, so the row covers every
 * declared role or variant of its declared case.
 *
 * @param string                         $observed_id Observed case id.
 * @param array<int,array<string,mixed>> $declared  Declared cases of the group.
 * @return array{declared:string,variant:string,role:string}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_resolve( string $observed_id, array $declared ): array {
	$best      = '';
	$best_case = array();
	foreach ( $declared as $case ) {
		if ( ! is_array( $case ) || ! isset( $case['id'] ) || ! is_string( $case['id'] ) ) {
			continue;
		}
		$id = $case['id'];
		if ( $observed_id !== $id && ! str_starts_with( $observed_id, $id . '-' ) ) {
			continue;
		}
		if ( strlen( $id ) > strlen( $best ) ) {
			$best      = $id;
			$best_case = $case;
		}
	}
	if ( '' === $best ) {
		return array(
			'declared' => '',
			'variant'  => '*',
			'role'     => '*',
		);
	}
	$suffix   = substr( $observed_id, strlen( $best ) );
	$suffix   = ltrim( $suffix, '-' );
	$variants = isset( $best_case['variants'] ) && is_array( $best_case['variants'] ) ? $best_case['variants'] : array();
	$variant  = '*';
	$winner   = 0;
	foreach ( $variants as $candidate ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			continue;
		}
		if ( $suffix === $candidate || str_ends_with( $suffix, '-' . $candidate ) || str_starts_with( $suffix, $candidate . '-' ) || str_contains( $suffix, '-' . $candidate . '-' ) ) {
			if ( strlen( $candidate ) > $winner ) {
				$winner  = strlen( $candidate );
				$variant = $candidate;
			}
		}
	}
	$roles = isset( $best_case['roles'] ) && is_array( $best_case['roles'] ) ? $best_case['roles'] : array();
	$role  = '*';
	foreach ( $roles as $candidate ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			continue;
		}
		$needle = str_replace( '_', '-', $candidate );
		if ( 'administrator' === $candidate && str_contains( $suffix, 'admin' ) ) {
			$role = $candidate;
			break;
		}
		if ( str_contains( $suffix, $needle ) ) {
			$role = $candidate;
			break;
		}
	}
	return array(
		'declared' => $best,
		'variant'  => $variant,
		'role'     => $role,
	);
}

/**
 * Resolve the target of one observed case id of the network group.
 *
 * The network matrix runs the main site and the child site in one process;
 * a case id that names a blog decides the target and a case id that names
 * none belongs to both declared blogs.
 *
 * @param string $observed_id Observed case id.
 * @param array  $targets     Declared targets of the case.
 * @return array<int,string>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_network_targets( string $observed_id, array $targets ): array {
	$has_main  = str_contains( $observed_id, 'main' );
	$has_child = str_contains( $observed_id, 'child' );
	$resolved  = array();
	foreach ( $targets as $target ) {
		if ( 'network-main' === $target && ! $has_child ) {
			$resolved[] = $target;
			continue;
		}
		if ( 'network-child' === $target && ! $has_main ) {
			$resolved[] = $target;
			continue;
		}
		if ( $has_main && $has_child ) {
			$resolved[] = $target;
		}
	}
	if ( array() === $resolved ) {
		return $targets;
	}
	return $resolved;
}

/**
 * Read one captured group report and return its safe parts.
 *
 * @param string $path Captured report path.
 * @return array{ok:bool,reason:string,report:array<string,mixed>}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_read_group( string $path ): array {
	if ( ! is_file( $path ) || is_link( $path ) ) {
		return array(
			'ok'     => false,
			'reason' => 'missing_group_report',
			'report' => array(),
		);
	}
	$raw = file_get_contents( $path );
	if ( ! is_string( $raw ) ) {
		return array(
			'ok'     => false,
			'reason' => 'unreadable_group_report',
			'report' => array(),
		);
	}
	$decoded = null;
	foreach ( preg_split( '/\R/', $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || ! str_starts_with( $line, '{' ) ) {
			continue;
		}
		$candidate = json_decode( $line, true );
		if ( is_array( $candidate ) && isset( $candidate['cases'] ) && is_array( $candidate['cases'] ) ) {
			$decoded = $candidate;
		}
	}
	if ( null === $decoded ) {
		return array(
			'ok'     => false,
			'reason' => 'malformed_group_report',
			'report' => array(),
		);
	}
	return array(
		'ok'     => true,
		'reason' => 'unavailable',
		'report' => $decoded,
	);
}

/**
 * Build the sanitized phase report of one campaign phase.
 *
 * Only case ids, roles, targets, allowlisted reasons, counts, timings and
 * boolean checks leave this function; the raw group reports stay in the
 * private run directory of the lab.
 *
 * @param string $phase    Phase name.
 * @param string $campaign Campaign id.
 * @param string $input    Directory with the captured group reports.
 * @param string $root     Source root of the fingerprint.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_phase( string $phase, string $campaign, string $input, string $root ): array {
	$plan     = xnetvn_agent_hub_wp_role_seo_acceptance_plan();
	$registry = xnetvn_agent_hub_wp_role_seo_acceptance_registry();
	if ( ! isset( $plan[ $phase ] ) ) {
		return array(
			'ok'     => false,
			'reason' => 'unknown_phase',
		);
	}
	$expected  = xnetvn_agent_hub_wp_role_seo_acceptance_expected( $registry );
	$instances = array();
	foreach ( $expected as $instance ) {
		if ( $instance['phase'] === $phase ) {
			$instances[] = $instance;
		}
	}
	$rows            = array();
	$group_rows      = array();
	$cleanup         = array();
	$metrics         = array();
	$drift           = array();
	$declared_groups = array();
	foreach ( $plan[ $phase ]['groups'] as $group ) {
		$declared = isset( $registry[ $group ]['cases'] ) && is_array( $registry[ $group ]['cases'] ) ? $registry[ $group ]['cases'] : array();
		foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_slots( $phase, $group, $plan ) as $slot ) {
			$target = (string) $slot['key'];
			$path   = rtrim( $input, '/' ) . '/' . $group . '__' . $target . '.json';
			$read   = xnetvn_agent_hub_wp_role_seo_acceptance_read_group( $path );
			$report = $read['report'];
			$owned  = false;
			foreach ( $declared as $case ) {
				if ( is_array( $case ) && isset( $case['targets'] ) && is_array( $case['targets'] ) ) {
					foreach ( (array) $slot['targets'] as $slot_target ) {
						if ( in_array( $slot_target, $case['targets'], true ) ) {
							$owned = true;
							break 2;
						}
					}
				}
			}
			if ( ! $owned ) {
				continue;
			}
			$cleanup[ $group . '/' . $target ] = false;
			$cases                             = $read['ok'] && isset( $read['report']['cases'] ) && is_array( $read['report']['cases'] ) ? $read['report']['cases'] : array();
			if ( ! $read['ok'] || array() === $cases ) {
				foreach ( $instances as $instance ) {
					if ( $instance['group'] === $group && in_array( $instance['target'], (array) $slot['targets'], true ) ) {
						$rows[] = array(
							'group'      => $group,
							'target'     => $target,
							'case_id'    => $instance['case_id'],
							'declared'   => $instance['case_id'],
							'role'       => '*',
							'scope_mode' => '*',
							'variant'    => '*',
							'iteration'  => 1,
							'status'     => 'skip',
							'reason'     => 'skipped',
						);
					}
				}
				$group_rows[] = array(
					'group'    => $group,
					'target'   => $target,
					'exit'     => 2,
					'read'     => $read['reason'],
					'declared' => array(),
				);
				continue;
			}
			$cleanup[ $group . '/' . $target ] = true === ( $report['cleanup'] ?? false );
			$observed_declared                 = isset( $report['declared'] ) && is_array( $report['declared'] ) ? $report['declared'] : array();
			if ( array() === $observed_declared ) {
				// The merged report of a group does not repeat its declaration;
				// the matrix file of the same run does. The declaration is
				// metadata only and lets the verifier notice a drift between
				// the group and the manifest of the campaign.
				$matrix_report = xnetvn_agent_hub_wp_role_seo_acceptance_read_group( rtrim( $input, '/' ) . '/' . $group . '__' . $target . '.matrix.json' );
				if ( $matrix_report['ok'] && isset( $matrix_report['report']['declared'] ) && is_array( $matrix_report['report']['declared'] ) ) {
					$observed_declared = $matrix_report['report']['declared'];
				}
			}
			if ( array() !== $observed_declared ) {
				$declared_groups[]  = $group . '/' . $target;
				$observed_projected = xnetvn_agent_hub_wp_role_seo_acceptance_project_cases( $observed_declared, $plan[ $phase ]['targets'] );
				$declared_projected = xnetvn_agent_hub_wp_role_seo_acceptance_project_cases( $declared, $plan[ $phase ]['targets'] );
				if ( xnetvn_agent_hub_wp_role_seo_acceptance_canonical_cases( $observed_projected ) !== xnetvn_agent_hub_wp_role_seo_acceptance_canonical_cases( $declared_projected ) ) {
					$drift[] = $group . '/' . $target;
				}
			}
			$group_counts = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
			$group_rows[] = array(
				'group'       => $group,
				'target'      => $target,
				'exit'        => 0,
				'read'        => 'unavailable',
				'declared'    => $observed_declared,
				'owned_ok'    => xnetvn_agent_hub_wp_role_seo_acceptance_owned_ok( $group_counts ),
				'settings_ok' => xnetvn_agent_hub_wp_role_seo_acceptance_digest_ok( $group_counts, 'settings_digest' ),
				'cron_ok'     => xnetvn_agent_hub_wp_role_seo_acceptance_digest_ok( $group_counts, 'cron_digest' ),
			);
			foreach ( $cases as $observed_id => $result ) {
				$observed_id = (string) $observed_id;
				$resolved    = xnetvn_agent_hub_wp_role_seo_acceptance_resolve( $observed_id, $declared );
				$reason      = is_array( $result ) && isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : 'unavailable';
				if ( ! in_array( $reason, XNETVN_AGENT_HUB_WP_ROLE_SEO_REASONS, true ) ) {
					$reason = 'unavailable';
				}
				$ok      = is_array( $result ) && true === ( $result['ok'] ?? false );
				$targets = (array) $slot['targets'];
				if ( 'network' === $group && '' !== $resolved['declared'] ) {
					$declared_case = array();
					foreach ( $declared as $case ) {
						if ( is_array( $case ) && isset( $case['id'] ) && $case['id'] === $resolved['declared'] ) {
							$declared_case = $case;
							break;
						}
					}
					$targets = xnetvn_agent_hub_wp_role_seo_acceptance_network_targets( $observed_id, isset( $declared_case['targets'] ) && is_array( $declared_case['targets'] ) ? $declared_case['targets'] : array( $target ) );
				}
				foreach ( $targets as $row_target ) {
					$status = 'pass';
					if ( '' === $resolved['declared'] ) {
						$status = 'fail';
						$reason = 'unknown_case';
					} else {
						$declared_targets = array();
						foreach ( $declared as $case ) {
							if ( is_array( $case ) && isset( $case['id'] ) && $case['id'] === $resolved['declared'] ) {
								$declared_targets = isset( $case['targets'] ) && is_array( $case['targets'] ) ? $case['targets'] : array();
								break;
							}
						}
						if ( ! in_array( $row_target, $declared_targets, true ) ) {
							$status = 'not_applicable';
							$reason = 'not_applicable_target';
						} elseif ( ! $ok ) {
							$status = 'fail';
						}
					}
					$rows[] = array(
						'group'      => $group,
						'target'     => $row_target,
						'case_id'    => $observed_id,
						'declared'   => $resolved['declared'],
						'role'       => $resolved['role'],
						'scope_mode' => '*',
						'variant'    => $resolved['variant'],
						'iteration'  => 1,
						'status'     => $status,
						'reason'     => $reason,
					);
				}
			}
			if ( 'stability' === $group ) {
				// The merged report of the stability group carries the counts of
				// the site; the matrix file of the same run carries the number
				// of rounds and the leak-free flag.
				$matrix_counts = array();
				if ( isset( $matrix_report['report']['counts'] ) && is_array( $matrix_report['report']['counts'] ) ) {
					$matrix_counts = $matrix_report['report']['counts'];
				}
				$metrics[ $target ] = array(
					'rounds'    => isset( $matrix_counts['rounds_ok'] ) ? (int) $matrix_counts['rounds_ok'] : 0,
					'leak_free' => true === ( $matrix_counts['leak_free'] ?? false ),
					'latency'   => isset( $report['latency'] ) && is_array( $report['latency'] ) ? $report['latency'] : array(),
					'memory'    => isset( $report['memory'] ) ? $report['memory'] : 'unavailable',
					'actors'    => isset( $report['actors'] ) && is_array( $report['actors'] ) ? $report['actors'] : array(),
					'bench'     => isset( $report['bench'] ) && is_array( $report['bench'] ) ? $report['bench'] : array(),
				);
			}
		}
	}
	$counts = array(
		'instances'      => count( $instances ),
		'rows'           => count( $rows ),
		'pass'           => 0,
		'fail'           => 0,
		'skip'           => 0,
		'not_applicable' => 0,
	);
	foreach ( $rows as $row ) {
		$counts[ (string) $row['status'] ] = ( $counts[ (string) $row['status'] ] ?? 0 ) + 1;
	}
	$environment = array();
	$env_path    = rtrim( $input, '/' ) . '/environment.json';
	if ( is_file( $env_path ) ) {
		$raw = file_get_contents( $env_path );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$environment = $decoded;
			}
		}
	}
	$fingerprint = xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint( $root );
	return array(
		'ok'                => true,
		'reason'            => 'unavailable',
		'suite'             => 'role-seo-acceptance',
		'phase'             => $phase,
		'campaign'          => $campaign,
		'fingerprint'       => $fingerprint['fingerprint'],
		'fingerprint_files' => $fingerprint['count'],
		'targets'           => $plan[ $phase ]['targets'],
		'groups'            => $plan[ $phase ]['groups'],
		'instances'         => $instances,
		'group_reports'     => $group_rows,
		'rows'              => $rows,
		'counts'            => $counts,
		'cleanup'           => $cleanup,
		'metrics'           => $metrics,
		'declaration_drift' => $drift,
		'declared_groups'   => $declared_groups,
		'environment'       => $environment,
	);
}

/**
 * List the regular files of one report directory.
 *
 * @param string $dir Report directory.
 * @return array<int,string> Relative paths.
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_artifacts( string $dir ): array {
	$files = array();
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return $files;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			continue;
		}
		if ( ! $item->isFile() ) {
			continue;
		}
		$files[] = ltrim( str_replace( $dir, '', $item->getPathname() ), '/' );
	}
	sort( $files, SORT_STRING );
	return $files;
}

/**
 * Collect the forbidden report keys of one decoded JSON value.
 *
 * @param mixed  $value Decoded value.
 * @param string $path  Key path for diagnostics.
 * @return array<int,string>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_forbidden_keys( mixed $value, string $path ): array {
	$found = array();
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $child ) {
			$child_path = $path . '.' . (string) $key;
			if ( is_string( $key ) && in_array( strtolower( $key ), XNETVN_AGENT_HUB_WP_ROLE_SEO_FORBIDDEN_KEYS, true ) && is_string( $child ) && '' !== $child ) {
				$found[] = $child_path;
			}
			$found = array_merge( $found, xnetvn_agent_hub_wp_role_seo_acceptance_forbidden_keys( $child, $child_path ) );
		}
	}
	return $found;
}

/**
 * Scan every artifact of one report directory for credential-shaped content.
 *
 * The scan covers every file extension and never prints the matched text:
 * a finding names the file and the pattern only.
 *
 * @param string $dir Report directory.
 * @return array{ok:bool,findings:array<int,array<string,string>>,scanned:int}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_scan( string $dir ): array {
	$findings = array();
	$scanned  = 0;
	foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_artifacts( $dir ) as $relative ) {
		$path = rtrim( $dir, '/' ) . '/' . $relative;
		if ( is_link( $path ) ) {
			$findings[] = array(
				'file'    => $relative,
				'pattern' => 'symlink_artifact',
			);
			continue;
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) ) {
			continue;
		}
		++$scanned;
		foreach ( XNETVN_AGENT_HUB_WP_ROLE_SEO_SECRET_PATTERNS as $name => $pattern ) {
			if ( 1 === preg_match( $pattern, $raw ) ) {
				$findings[] = array(
					'file'    => $relative,
					'pattern' => $name,
				);
			}
		}
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_forbidden_keys( $decoded, '$' ) as $path_hit ) {
				$findings[] = array(
					'file'    => $relative,
					'pattern' => 'forbidden_key',
					'path'    => $path_hit,
				);
			}
		}
	}
	return array(
		'ok'       => array() === $findings,
		'findings' => $findings,
		'scanned'  => $scanned,
	);
}

/**
 * Read the single sanitized report block of one phase log.
 *
 * @param string $raw Raw log content.
 * @return array{ok:bool,reason:string,report:array<string,mixed>}
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_block( string $raw ): array {
	$begins = array();
	$ends   = array();
	$offset = 0;
	$length = strlen( $raw );
	while ( $offset < $length ) {
		$begin = strpos( $raw, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN, $offset );
		if ( false === $begin ) {
			break;
		}
		$begins[] = $begin;
		$offset   = $begin + strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN );
	}
	$offset = 0;
	while ( $offset < $length ) {
		$end = strpos( $raw, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END, $offset );
		if ( false === $end ) {
			break;
		}
		$ends[] = $end;
		$offset = $end + strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END );
	}
	if ( array() === $begins ) {
		return array(
			'ok'     => false,
			'reason' => 'missing_report_block',
			'report' => array(),
		);
	}
	if ( 1 !== count( $begins ) || 1 !== count( $ends ) ) {
		return array(
			'ok'     => false,
			'reason' => 'duplicate_report_block',
			'report' => array(),
		);
	}
	if ( $ends[0] < $begins[0] ) {
		return array(
			'ok'     => false,
			'reason' => 'malformed_report_block',
			'report' => array(),
		);
	}
	$body    = substr( $raw, $begins[0] + strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ), $ends[0] - $begins[0] - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) );
	$decoded = json_decode( trim( $body ), true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['rows'] ) || ! is_array( $decoded['rows'] ) ) {
		return array(
			'ok'     => false,
			'reason' => 'malformed_report_block',
			'report' => array(),
		);
	}
	return array(
		'ok'     => true,
		'reason' => 'unavailable',
		'report' => $decoded,
	);
}

/**
 * Create the owned report root of one campaign and its expected manifest.
 *
 * @param string $campaign Campaign id.
 * @param string $dir      Report directory.
 * @param string $root     Project root of the fingerprint.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_prepare( string $campaign, string $dir, string $root ): array {
	if ( 1 !== preg_match( XNETVN_AGENT_HUB_WP_ROLE_SEO_CAMPAIGN_PATTERN, $campaign ) ) {
		return array(
			'ok'     => false,
			'reason' => 'invalid_campaign',
		);
	}
	$absolute   = xnetvn_agent_hub_wp_role_seo_acceptance_absolute( $dir );
	$owner      = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	$components = xnetvn_agent_hub_wp_role_seo_acceptance_components( $absolute, $owner );
	if ( ! $components['ok'] ) {
		return array(
			'ok'     => false,
			'reason' => $components['reason'],
		);
	}
	if ( is_dir( $absolute ) ) {
		$leaf = xnetvn_agent_hub_wp_role_seo_acceptance_own( $absolute, true, $owner );
		if ( ! $leaf['ok'] ) {
			return array(
				'ok'     => false,
				'reason' => $leaf['reason'],
			);
		}
	}
	if ( is_dir( $absolute ) && array() !== xnetvn_agent_hub_wp_role_seo_acceptance_artifacts( $absolute ) ) {
		return array(
			'ok'     => false,
			'reason' => 'campaign_has_data',
		);
	}
	if ( ! is_dir( $absolute ) && ! mkdir( $absolute, 0700, true ) ) {
		return array(
			'ok'     => false,
			'reason' => 'report_root_unavailable',
		);
	}
	chmod( $absolute, 0700 );
	$registry    = xnetvn_agent_hub_wp_role_seo_acceptance_registry();
	$instances   = xnetvn_agent_hub_wp_role_seo_acceptance_expected( $registry );
	$matrix      = xnetvn_agent_hub_wp_role_seo_acceptance_matrix( $instances );
	$fingerprint = xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint( $root );
	$manifest    = array(
		'suite'              => 'role-seo-acceptance-manifest',
		'coverage'           => xnetvn_agent_hub_wp_role_seo_acceptance_coverage(),
		'open_gates'         => xnetvn_agent_hub_wp_role_seo_acceptance_open_gates(),
		'campaign'           => $campaign,
		'plan'               => xnetvn_agent_hub_wp_role_seo_acceptance_plan(),
		'fingerprint'        => $fingerprint['fingerprint'],
		'fingerprint_files'  => $fingerprint['count'],
		'fingerprint_source' => $fingerprint['files'],
		'instances'          => $instances,
		'matrix'             => $matrix,
		'registry'           => $registry,
	);
	$path        = $absolute . '/manifest.expected.json';
	if ( false === file_put_contents( $path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
		return array(
			'ok'     => false,
			'reason' => 'manifest_unavailable',
		);
	}
	chmod( $path, 0600 );
	return array(
		'ok'                => true,
		'reason'            => 'unavailable',
		'campaign'          => $campaign,
		'report_dir'        => $absolute,
		'fingerprint'       => $fingerprint['fingerprint'],
		'fingerprint_files' => $fingerprint['count'],
		'instances'         => count( $instances ),
		'tuples'            => $matrix['total'],
	);
}

/**
 * Verify the sanitized phase reports of one campaign.
 *
 * @param string                              $campaign        Campaign id.
 * @param string                              $dir             Report directory.
 * @param array<int,array<string,mixed>>|null $gates_override Gate list of the verifier, null for the list of the code.
 * @param string|null                         $issues_root     Ticket root of the owner lookup, null for the repository root.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_verify( string $campaign, string $dir, ?array $gates_override = null, ?string $issues_root = null ): array {
	if ( 1 !== preg_match( XNETVN_AGENT_HUB_WP_ROLE_SEO_CAMPAIGN_PATTERN, $campaign ) ) {
		return array(
			'ok'     => false,
			'reason' => 'invalid_campaign',
		);
	}
	$absolute   = xnetvn_agent_hub_wp_role_seo_acceptance_absolute( $dir );
	$owner      = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	$components = xnetvn_agent_hub_wp_role_seo_acceptance_components( $absolute, $owner );
	if ( ! $components['ok'] ) {
		return array(
			'ok'     => false,
			'reason' => $components['reason'],
		);
	}
	$owned = xnetvn_agent_hub_wp_role_seo_acceptance_own( $absolute, true, $owner );
	if ( ! $owned['ok'] ) {
		return array(
			'ok'     => false,
			'reason' => $owned['reason'],
		);
	}
	$manifest_path = $absolute . '/manifest.expected.json';
	$raw           = file_get_contents( $manifest_path );
	if ( ! is_string( $raw ) ) {
		return array(
			'ok'     => false,
			'reason' => 'manifest_unavailable',
		);
	}
	$manifest = json_decode( $raw, true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['instances'] ) || ! is_array( $manifest['instances'] ) || ! isset( $manifest['fingerprint'] ) ) {
		return array(
			'ok'     => false,
			'reason' => 'malformed_manifest',
		);
	}
	if ( (string) ( $manifest['campaign'] ?? '' ) !== $campaign ) {
		return array(
			'ok'     => false,
			'reason' => 'campaign_mismatch',
		);
	}
	$problems  = array();
	$gates     = null !== $gates_override ? $gates_override : xnetvn_agent_hub_wp_role_seo_acceptance_gates();
	$gate_hits = array();
	foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_manifest_gates( $manifest ) as $unknown ) {
		$problems[] = 'gate_not_declared_by_code:' . $unknown;
	}
	$expected      = $manifest['instances'];
	$expected_keys = array();
	foreach ( $expected as $instance ) {
		$expected_keys[ $instance['phase'] . '|' . $instance['group'] . '|' . $instance['case_id'] . '|' . $instance['target'] ] = true;
	}
	$observed_keys = array();
	$observed_rows = array();
	$phases        = array();
	foreach ( array( 'single', 'network' ) as $phase ) {
		$path = $absolute . '/' . $phase . '.log';
		$log  = file_get_contents( $path );
		if ( ! is_string( $log ) ) {
			$problems[] = 'missing_phase_report:' . $phase;
			continue;
		}
		$block = xnetvn_agent_hub_wp_role_seo_acceptance_block( $log );
		if ( ! $block['ok'] ) {
			$problems[] = $block['reason'] . ':' . $phase;
			continue;
		}
		$report = $block['report'];
		if ( (string) ( $report['campaign'] ?? '' ) !== $campaign ) {
			$problems[] = 'campaign_mismatch:' . $phase;
		}
		if ( (string) ( $report['fingerprint'] ?? '' ) !== (string) $manifest['fingerprint'] ) {
			$problems[] = 'fingerprint_mismatch:' . $phase;
		}
		if ( ! empty( $report['declaration_drift'] ) ) {
			$problems[] = 'declaration_drift:' . $phase;
		}
		$counts = array(
			'pass'           => 0,
			'fail'           => 0,
			'skip'           => 0,
			'not_applicable' => 0,
			'open_gate'      => 0,
		);
		foreach ( $report['rows'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status            = isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : 'fail';
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
			if ( 'fail' === $status ) {
				$gate = xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( $gates, (string) ( $row['case_id'] ?? '' ), (string) ( $row['target'] ?? '' ), $issues_root );
				if ( null !== $gate ) {
					++$counts['open_gate'];
					--$counts['fail'];
					$gate_hits[] = 'case:' . (string) ( $row['case_id'] ?? '' ) . '@' . (string) ( $row['target'] ?? '' );
				}
			}
			$declared = isset( $row['declared'] ) && is_string( $row['declared'] ) ? $row['declared'] : '';
			$key      = (string) ( $row['group'] ?? '' ) . '|' . (string) ( $row['target'] ?? '' ) . '|' . (string) ( $row['case_id'] ?? '' );
			if ( isset( $observed_rows[ $key ] ) ) {
				$problems[] = 'duplicate_case:' . $key;
			}
			$observed_rows[ $key ] = true;
			if ( '' === $declared ) {
				$problems[] = 'unknown_case:' . (string) ( $row['case_id'] ?? '' );
				continue;
			}
			if ( 'not_applicable' === $status ) {
				$reason = isset( $row['reason'] ) && is_string( $row['reason'] ) ? $row['reason'] : '';
				if ( ! in_array( $reason, XNETVN_AGENT_HUB_WP_ROLE_SEO_REASONS, true ) ) {
					$problems[] = 'not_applicable_without_reason:' . (string) $row['case_id'];
				}
				continue;
			}
			$instance_key = $phase . '|' . (string) $row['group'] . '|' . $declared . '|' . (string) $row['target'];
			if ( ! isset( $expected_keys[ $instance_key ] ) ) {
				$problems[] = 'unknown_case:' . $instance_key;
				continue;
			}
			$observed_keys[ $instance_key ] = true;
		}
		foreach ( $expected_keys as $key => $seen ) {
			if ( ! isset( $observed_keys[ $key ] ) && str_starts_with( $key, $phase . '|' ) ) {
				$problems[] = 'missing_case:' . $key;
			}
		}
		foreach ( $report['cleanup'] as $name => $value ) {
			if ( true === $value ) {
				continue;
			}
			$gate_parts = explode( '/', (string) $name, 2 );
			$group      = (string) ( $gate_parts[0] ?? '' );
			$evidence   = xnetvn_agent_hub_wp_role_seo_acceptance_group_evidence( $report, $group, (string) ( $gate_parts[1] ?? '' ) );
			$gate       = xnetvn_agent_hub_wp_role_seo_acceptance_cleanup_gate( $gates, $group, $issues_root );
			if ( null !== $gate && true === ( $evidence['owned_ok'] ?? false ) && true === ( $evidence['settings_ok'] ?? false ) && false === ( $evidence['cron_ok'] ?? true ) ) {
				$gate_hits[] = 'cleanup_cron:' . (string) $name;
				continue;
			}
			$problems[] = 'cleanup_failed:' . (string) $name;
		}
		if ( $counts['fail'] > 0 ) {
			$problems[] = 'failed_cases:' . $phase . ':' . $counts['fail'];
		}
		if ( $counts['skip'] > 0 ) {
			$problems[] = 'skipped_cases:' . $phase . ':' . $counts['skip'];
		}
		$phases[ $phase ] = array(
			'counts'  => $counts,
			'targets' => $report['targets'] ?? array(),
			'groups'  => $report['groups'] ?? array(),
			'cleanup' => $report['cleanup'] ?? array(),
			'metrics' => $report['metrics'] ?? array(),
			'rows'    => count( $report['rows'] ),
		);
	}
	$scan = xnetvn_agent_hub_wp_role_seo_acceptance_scan( $absolute );
	if ( ! $scan['ok'] ) {
		foreach ( $scan['findings'] as $finding ) {
			$problems[] = 'unsafe_artifact:' . (string) $finding['file'] . ':' . (string) $finding['pattern'];
		}
	}
	$matrix = xnetvn_agent_hub_wp_role_seo_acceptance_matrix( $expected );
	return array(
		'ok'          => array() === $problems,
		'reason'      => array() === $problems ? 'unavailable' : 'verification_failed',
		'campaign'    => $campaign,
		'fingerprint' => (string) $manifest['fingerprint'],
		'expected'    => count( $expected ),
		'tuples'      => $matrix['total'],
		'matrix'      => $matrix,
		'phases'      => $phases,
		'open_gates'  => array(
			'declared' => $gates,
			'hit'      => $gate_hits,
		),
		'scanned'     => $scan['scanned'],
		'problems'    => $problems,
	);
}

/**
 * Write the synthetic group reports of one phase for the offline selftest.
 *
 * @param string $input  Directory of the synthetic group reports.
 * @param string $phase  Phase name.
 * @return bool
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_selftest_groups( string $input, string $phase ): bool {
	$plan     = xnetvn_agent_hub_wp_role_seo_acceptance_plan();
	$registry = xnetvn_agent_hub_wp_role_seo_acceptance_registry();
	if ( ! is_dir( $input ) && ! mkdir( $input, 0700, true ) ) {
		return false;
	}
	foreach ( $plan[ $phase ]['groups'] as $group ) {
		$declared = isset( $registry[ $group ]['cases'] ) && is_array( $registry[ $group ]['cases'] ) ? $registry[ $group ]['cases'] : array();
		foreach ( xnetvn_agent_hub_wp_role_seo_acceptance_slots( $phase, $group, $plan ) as $slot ) {
			$target = (string) $slot['key'];
			$owned  = false;
			foreach ( $declared as $case ) {
				if ( is_array( $case ) && isset( $case['targets'] ) && is_array( $case['targets'] ) ) {
					foreach ( (array) $slot['targets'] as $slot_target ) {
						if ( in_array( $slot_target, $case['targets'], true ) ) {
							$owned = true;
							break 2;
						}
					}
				}
			}
			if ( ! $owned ) {
				continue;
			}
			$cases = array();
			foreach ( $declared as $case ) {
				if ( is_array( $case ) && isset( $case['id'] ) && is_string( $case['id'] ) ) {
					$cases[ $case['id'] ] = array(
						'ok'     => true,
						'reason' => 'unavailable',
					);
				}
			}
			$merged = array(
				'suite'   => 'role-seo-' . $group,
				'target'  => $target,
				'cases'   => $cases,
				'cleanup' => true,
			);
			$path   = $input . '/' . $group . '__' . $target . '.json';
			if ( false === file_put_contents( $path, json_encode( $merged, JSON_UNESCAPED_SLASHES ) ) ) {
				return false;
			}
			// The merged report of a real group does not repeat its
			// declaration; the matrix file of the same run does.
			$matrix      = array(
				'suite'    => 'role-seo-' . $group,
				'target'   => $target,
				'cases'    => $cases,
				'declared' => $declared,
				'cleanup'  => true,
			);
			$matrix_path = $input . '/' . $group . '__' . $target . '.matrix.json';
			if ( false === file_put_contents( $matrix_path, json_encode( $matrix, JSON_UNESCAPED_SLASHES ) ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Run the offline contract checks of the acceptance tool.
 *
 * Every check proves that the verifier refuses one broken campaign; the
 * selftest never touches a lab, a network or a fixture.
 *
 * @return array{ok:bool,checks:array<string,bool>}
 */
/**
 * Build the synthetic gate list of the offline selftest.
 *
 * The selftest proves both directions of the gate mechanism without
 * depending on the real ticket store: the owner is a synthetic ticket
 * inside the temporary ticket root of the selftest.
 *
 * @param string $owner Synthetic owner ticket id.
 * @return array<int,array<string,mixed>>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_selftest_gates( string $owner ): array {
	return array(
		array(
			'kind'    => 'case',
			'case_id' => 'STAB-03',
			'target'  => 'wp69',
			'owner'   => $owner,
			'reason'  => 'Synthetic gate of the offline selftest; it never covers a real campaign.',
		),
	);
}

/**
 * Run the offline selftest of the acceptance tool.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_selftest(): array {
	$checks = array();
	$root   = dirname( __DIR__, 2 );
	$base   = $root . '/.local/role-seo-acceptance-selftest-' . bin2hex( random_bytes( 6 ) );
	$work   = $base . '/work';
	$report = $base . '/report';
	$canary = 'ACCEPTANCE-CANARY-' . bin2hex( random_bytes( 6 ) );
	if ( ! is_dir( $root . '/.local' ) ) {
		mkdir( $root . '/.local', 0700, true );
	}
	mkdir( $work, 0700, true );
	mkdir( $report, 0700, true );
	// The two gate checks below run against these synthetic tickets, so they
	// prove the mechanism without reading the state of a real ticket.
	$tickets      = $base . '/tickets';
	$open_owner   = 'ISSUE_selftest_gate_owner_open';
	$closed_owner = 'ISSUE_selftest_gate_owner_closed';
	$status_row   = '| Status (`Draft`, `Ready`, `In Progress`, `In Review`, `Done`, `Cancelled`) | ';
	mkdir( $tickets . '/v0.1/others', 0700, true );
	file_put_contents( $tickets . '/v0.1/others/' . $open_owner . '.md', "# Synthetic open owner\n\n" . $status_row . "In Progress |\n" );
	file_put_contents( $tickets . '/v0.1/others/' . $closed_owner . '.md', "# Synthetic closed owner\n\n" . $status_row . "Done |\n" );
	$campaign                               = 'role-seo-20260101T000000Z';
	$prepared                               = xnetvn_agent_hub_wp_role_seo_acceptance_prepare( $campaign, $report, $root );
	$checks['prepare-creates-the-manifest'] = true === ( $prepared['ok'] ?? false );
	$checks['the-fingerprint-covers-the-source-tree'] = 0 < (int) ( $prepared['fingerprint_files'] ?? 0 )
		&& 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' !== (string) ( $prepared['fingerprint'] ?? '' );
	// The manifest of a campaign expects the B-E matrices of both network
	// blogs: 37 declared cases of the four groups on each of the two blogs.
	$manifest_raw  = file_get_contents( $report . '/manifest.expected.json' );
	$manifest_data = is_string( $manifest_raw ) ? json_decode( $manifest_raw, true ) : null;
	$network_be    = 0;
	if ( is_array( $manifest_data ) && isset( $manifest_data['instances'] ) && is_array( $manifest_data['instances'] ) ) {
		foreach ( $manifest_data['instances'] as $instance ) {
			if ( 'network' !== ( $instance['phase'] ?? '' ) ) {
				continue;
			}
			if ( ! in_array( (string) ( $instance['group'] ?? '' ), array( 'permissions', 'content', 'media', 'lifecycle' ), true ) ) {
				continue;
			}
			if ( in_array( (string) ( $instance['target'] ?? '' ), array( 'network-main', 'network-child' ), true ) ) {
				++$network_be;
			}
		}
	}
	$checks['the-manifest-expects-the-b-e-groups-on-both-network-blogs'] = 74 === $network_be;
	foreach ( array( 'single', 'network' ) as $phase ) {
		$input = $work . '/' . $phase;
		if ( ! xnetvn_agent_hub_wp_role_seo_acceptance_selftest_groups( $input, $phase ) ) {
			return array(
				'ok'     => false,
				'checks' => array( 'fixture-built' => false ),
			);
		}
		$built = xnetvn_agent_hub_wp_role_seo_acceptance_phase( $phase, $campaign, $input, $root );
		if ( ! $built['ok'] ) {
			return array(
				'ok'     => false,
				'checks' => array( 'phase-built' => false ),
			);
		}
		if ( 'single' === $phase ) {
			$checks['the-group-declaration-comes-from-the-matrix-file'] = array() === $built['declaration_drift'] && array() !== $built['declared_groups'];
		}
		$block = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $built, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/' . $phase . '.log', $block );
	}
	$verified                               = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
	$checks['a-complete-campaign-verifies'] = true === ( $verified['ok'] ?? false );
	$details                                = array(
		'problems' => array_slice( (array) ( $verified['problems'] ?? array() ), 0, 20 ),
	);

	$single = (string) file_get_contents( $report . '/single.log' );
	file_put_contents( $report . '/single.log', "no report block here\n" );
	$broken                                      = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
	$checks['a-missing-report-block-is-refused'] = false === ( $broken['ok'] ?? true ) && in_array( 'missing_report_block:single', (array) ( $broken['problems'] ?? array() ), true );

	file_put_contents( $report . '/single.log', $single . $single );
	$broken                                        = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
	$checks['a-duplicate-report-block-is-refused'] = false === ( $broken['ok'] ?? true ) && in_array( 'duplicate_report_block:single', (array) ( $broken['problems'] ?? array() ), true );

	file_put_contents( $report . '/single.log', XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n{not json\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n" );
	$broken                                        = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
	$checks['a-malformed-report-block-is-refused'] = false === ( $broken['ok'] ?? true ) && in_array( 'malformed_report_block:single', (array) ( $broken['problems'] ?? array() ), true );

	file_put_contents( $report . '/single.log', $single );
	$decoded = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true );
	if ( is_array( $decoded ) ) {
		array_pop( $decoded['rows'] );
		$mutated = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/single.log', $mutated );
		$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$found  = false;
		foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
			if ( str_starts_with( (string) $problem, 'missing_case:' ) ) {
				$found = true;
			}
		}
		$checks['a-missing-case-is-refused'] = false === ( $broken['ok'] ?? true ) && $found;
	} else {
		$checks['a-missing-case-is-refused'] = false;
	}

	file_put_contents( $report . '/single.log', $single );
	$decoded = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true );
	if ( is_array( $decoded ) ) {
		$decoded['fingerprint'] = str_repeat( '0', 64 );
		$mutated                = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/single.log', $mutated );
		$broken                                   = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$checks['a-stale-fingerprint-is-refused'] = false === ( $broken['ok'] ?? true ) && in_array( 'fingerprint_mismatch:single', (array) ( $broken['problems'] ?? array() ), true );

		$decoded['fingerprint'] = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true )['fingerprint'];
		foreach ( $decoded['cleanup'] as $name => $value ) {
			if ( str_starts_with( (string) $name, 'stability/' ) ) {
				$decoded['cleanup'][ $name ] = false;
			}
		}
		$mutated = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/single.log', $mutated );
		$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$found  = false;
		foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
			if ( str_starts_with( (string) $problem, 'cleanup_failed:' ) ) {
				$found = true;
			}
		}
		$checks['a-false-cleanup-flag-is-refused'] = false === ( $broken['ok'] ?? true ) && $found;
	} else {
		$checks['a-stale-fingerprint-is-refused']  = false;
		$checks['a-false-cleanup-flag-is-refused'] = false;
	}

	file_put_contents( $report . '/single.log', $single );
	$decoded = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true );
	if ( is_array( $decoded ) ) {
		$gate_case     = null;
			$open_case = null;
		foreach ( $decoded['rows'] as $index => $row ) {
			if ( 'STAB-03' === ( $row['case_id'] ?? '' ) && 'wp69' === ( $row['target'] ?? '' ) ) {
				$gate_case = $index;
			}
			if ( 'STAB-01' === ( $row['case_id'] ?? '' ) && 'wp69' === ( $row['target'] ?? '' ) ) {
				$open_case = $index;
			}
		}
		if ( null !== $open_case ) {
			$decoded['rows'][ $open_case ]['status'] = 'fail';
			$mutated                                 = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
			file_put_contents( $report . '/single.log', $mutated );
			$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
			$found  = false;
			foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
				if ( str_starts_with( (string) $problem, 'failed_cases:single:' ) ) {
					$found = true;
				}
			}
			$checks['a-red-case-outside-the-declared-gates-stays-a-failure'] = false === ( $broken['ok'] ?? true ) && $found;
			$decoded['rows'][ $open_case ]['status']                         = 'pass';
		}
		if ( null !== $gate_case ) {
			$decoded['rows'][ $gate_case ]['status'] = 'fail';
			$mutated                                 = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
			file_put_contents( $report . '/single.log', $mutated );
			$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report, xnetvn_agent_hub_wp_role_seo_acceptance_selftest_gates( $open_owner ), $tickets );
			$gated  = false;
			foreach ( (array) ( $broken['open_gates']['hit'] ?? array() ) as $hit ) {
				if ( 'case:STAB-03@wp69' === (string) $hit ) {
					$gated = true;
				}
			}
			$checks['a-declared-gate-is-reported-separately'] = true === ( $broken['ok'] ?? false ) && $gated;
		}
		// Mutant: the same red case behind a gate whose synthetic owner
		// ticket is already closed must stay a failure of the campaign.
		if ( null !== $gate_case ) {
			$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report, xnetvn_agent_hub_wp_role_seo_acceptance_selftest_gates( $closed_owner ), $tickets );
			$failed = false;
			foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
				if ( str_starts_with( (string) $problem, 'failed_cases:single:' ) ) {
					$failed = true;
				}
			}
			$gated = false;
			foreach ( (array) ( $broken['open_gates']['hit'] ?? array() ) as $hit ) {
				if ( 'case:STAB-03@wp69' === (string) $hit ) {
					$gated = true;
				}
			}
			$checks['a-gate-of-a-closed-owner-does-not-hide-a-red-case'] = false === ( $broken['ok'] ?? true ) && $failed && ! $gated;
		} else {
			$checks['a-gate-of-a-closed-owner-does-not-hide-a-red-case'] = false;
		}
	} else {
		$checks['a-red-case-outside-the-declared-gates-stays-a-failure'] = false;
		$checks['a-declared-gate-is-reported-separately']                = false;
		$checks['a-gate-of-a-closed-owner-does-not-hide-a-red-case']     = false;
	}

	file_put_contents( $report . '/single.log', $single );
	$manifest_path = $report . '/manifest.expected.json';
	$manifest_raw  = file_get_contents( $manifest_path );
	$manifest      = is_string( $manifest_raw ) ? json_decode( $manifest_raw, true ) : null;
	if ( is_array( $manifest ) ) {
		// Mutant M2: the manifest declares a gate that the code of the
		// verifier does not know, so a red case could silence itself.
		$manifest['open_gates'][] = array(
			'kind'    => 'case',
			'case_id' => 'IMG-08-restored',
			'target'  => 'wp69',
			'owner'   => 'ISSUE_v0.1_2026-10-20_04_gate-cleanup-chi-so-owned-cho-moi-nhom-ma-tran-role-seo',
			'reason'  => 'A gate the code does not declare.',
		);
		file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$found  = false;
		foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
			if ( str_starts_with( (string) $problem, 'gate_not_declared_by_code:' ) ) {
				$found = true;
			}
		}
		$checks['a-manifest-gate-that-the-code-does-not-declare-is-refused'] = false === ( $broken['ok'] ?? true ) && $found;
		file_put_contents( $manifest_path, $manifest_raw );
	}

	$decoded = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true );
	if ( is_array( $decoded ) ) {
		// Mutant M3: a cleanup failure of a group whose owned counts did not
		// come back to the baseline is a real leak, not a cron drift.
		foreach ( $decoded['cleanup'] as $name => $value ) {
			if ( str_starts_with( (string) $name, 'media/' ) ) {
				$decoded['cleanup'][ $name ] = false;
				break;
			}
		}
		$mutated = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/single.log', $mutated );
		$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$found  = false;
		foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
			if ( str_starts_with( (string) $problem, 'cleanup_failed:' ) ) {
				$found = true;
			}
		}
		$checks['a-cleanup-failure-that-is-not-a-cron-drift-is-refused'] = false === ( $broken['ok'] ?? true ) && $found;
	}

	file_put_contents( $report . '/single.log', $single );
	$decoded = json_decode( trim( substr( $single, strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) + 1, strpos( $single, XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END ) - strlen( XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN ) - 1 ) ), true );
	if ( is_array( $decoded ) ) {
		$decoded['declaration_drift'] = array( 'fixture/wp69' );
		$mutated                      = XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_BEGIN . "\n" . json_encode( $decoded, JSON_UNESCAPED_SLASHES ) . "\n" . XNETVN_AGENT_HUB_WP_ROLE_SEO_REPORT_END . "\n";
		file_put_contents( $report . '/single.log', $mutated );
		$broken                                   = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
		$checks['a-declaration-drift-is-refused'] = false === ( $broken['ok'] ?? true ) && in_array( 'declaration_drift:single', (array) ( $broken['problems'] ?? array() ), true );
	} else {
		$checks['a-declaration-drift-is-refused'] = false;
	}
	file_put_contents( $report . '/single.log', $single );
	file_put_contents( $report . '/notes.md', 'the canary value is ' . $canary . "\n" );
	$broken = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $report );
	$found  = false;
	$leaked = false;
	foreach ( (array) ( $broken['problems'] ?? array() ) as $problem ) {
		if ( str_starts_with( (string) $problem, 'unsafe_artifact:' ) ) {
			$found = true;
		}
		if ( str_contains( (string) $problem, $canary ) ) {
			$leaked = true;
		}
	}
	$checks['a-synthetic-canary-is-refused']     = false === ( $broken['ok'] ?? true ) && $found;
	$checks['a-refusal-never-prints-the-secret'] = ! $leaked;
	unlink( $report . '/notes.md' );

	$owner                                     = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	$own                                       = xnetvn_agent_hub_wp_role_seo_acceptance_own( '/', true, $owner );
	$checks['a-foreign-owned-path-is-refused'] = false === $own['ok'];

	$link = $base . '/linked';
	symlink( $report, $link );
	$own                                   = xnetvn_agent_hub_wp_role_seo_acceptance_own( $link, true, $owner );
	$checks['a-symlinked-path-is-refused'] = false === $own['ok'];

	$refused                                   = xnetvn_agent_hub_wp_role_seo_acceptance_prepare( $campaign, $report, $root );
	$checks['a-campaign-with-data-is-refused'] = false === ( $refused['ok'] ?? true ) && 'campaign_has_data' === ( $refused['reason'] ?? '' );

	$bad                                      = xnetvn_agent_hub_wp_role_seo_acceptance_prepare( 'not-a-campaign', $base . '/other', $root );
	$checks['an-invalid-campaign-is-refused'] = false === ( $bad['ok'] ?? true ) && 'invalid_campaign' === ( $bad['reason'] ?? '' );

	foreach ( array( $base ) as $cleanup ) {
		if ( is_dir( $cleanup ) ) {
			xnetvn_agent_hub_wp_role_seo_acceptance_remove( $cleanup );
		}
	}
	$ok = true;
	foreach ( $checks as $value ) {
		if ( true !== $value ) {
			$ok = false;
		}
	}
	return array(
		'ok'      => $ok,
		'checks'  => $checks,
		'details' => $details,
	);
}

/**
 * Remove one owned temporary directory of the selftest.
 *
 * @param string $dir Owned directory.
 * @return void
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_remove( string $dir ): void {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			unlink( $item->getPathname() );
			continue;
		}
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
			continue;
		}
		unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/**
 * Run the acceptance tool from the command line.
 *
 * @param array<int,string> $argv Raw arguments.
 * @return void
 */
function xnetvn_agent_hub_wp_role_seo_acceptance_main( array $argv ): void {
	$parsed  = xnetvn_agent_hub_wp_role_seo_acceptance_parse( $argv );
	$mode    = $parsed['mode'];
	$options = $parsed['options'];
	$root    = dirname( __DIR__, 2 );
	switch ( $mode ) {
		case 'selftest':
			$selftest = xnetvn_agent_hub_wp_role_seo_acceptance_selftest();
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'   => 'role-seo-acceptance-selftest',
					'ok'      => $selftest['ok'],
					'checks'  => $selftest['checks'],
					'details' => $selftest['details'] ?? array(),
				),
				$selftest['ok'] ? 0 : 1
			);
			return;
		case 'fingerprint':
			$target = $options['root'] ?? $root;
			$hash   = xnetvn_agent_hub_wp_role_seo_acceptance_fingerprint( $target );
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'       => 'role-seo-acceptance-fingerprint',
					'fingerprint' => $hash['fingerprint'],
					'files'       => $hash['count'],
				),
				0
			);
			return;
		case 'prepare':
			$campaign = $options['campaign'] ?? '';
			$dir      = $options['report-dir'] ?? '';
			$prepared = xnetvn_agent_hub_wp_role_seo_acceptance_prepare( $campaign, $dir, $root );
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'    => 'role-seo-acceptance-prepare',
					'ok'       => $prepared['ok'] ?? false,
					'reason'   => $prepared['reason'] ?? 'unavailable',
					'campaign' => $campaign,
					'details'  => $prepared,
				),
				( $prepared['ok'] ?? false ) ? 0 : 2
			);
			return;
		case 'verify':
			$campaign = $options['campaign'] ?? '';
			$dir      = $options['report-dir'] ?? '';
			$verified = xnetvn_agent_hub_wp_role_seo_acceptance_verify( $campaign, $dir );
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'    => 'role-seo-acceptance-verify',
					'ok'       => $verified['ok'] ?? false,
					'reason'   => $verified['reason'] ?? 'unavailable',
					'campaign' => $campaign,
					'details'  => $verified,
				),
				( $verified['ok'] ?? false ) ? 0 : 1
			);
			return;
		case 'phase':
			$phase    = $options['phase'] ?? '';
			$campaign = $options['campaign'] ?? '';
			$input    = $options['input'] ?? '';
			$source   = $options['root'] ?? $root;
			$built    = xnetvn_agent_hub_wp_role_seo_acceptance_phase( $phase, $campaign, $input, $source );
			if ( ! ( $built['ok'] ?? false ) ) {
				xnetvn_agent_hub_wp_role_seo_acceptance_emit(
					array(
						'suite'  => 'role-seo-acceptance-phase',
						'ok'     => false,
						'reason' => $built['reason'] ?? 'unavailable',
					),
					2
				);
				return;
			}
			$encoded = json_encode( $built, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				xnetvn_agent_hub_wp_role_seo_acceptance_emit(
					array(
						'suite'  => 'role-seo-acceptance-phase',
						'ok'     => false,
						'reason' => 'unavailable',
					),
					2
				);
				return;
			}
			if ( isset( $options['output'] ) ) {
				file_put_contents( $options['output'], $encoded );
			}
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'  => 'role-seo-acceptance-phase',
					'ok'     => true,
					'reason' => 'unavailable',
					'rows'   => $built['counts']['rows'] ?? 0,
					'fail'   => $built['counts']['fail'] ?? 0,
				),
				0
			);
			return;
		default:
			xnetvn_agent_hub_wp_role_seo_acceptance_emit(
				array(
					'suite'  => 'role-seo-acceptance',
					'ok'     => false,
					'reason' => 'unknown_mode',
				),
				2
			);
			return;
	}
}

if ( PHP_SAPI === 'cli' && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	xnetvn_agent_hub_wp_role_seo_acceptance_main( $argv );
}
