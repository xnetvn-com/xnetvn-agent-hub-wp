<?php
/**
 * Owned multisite fixture for recoverable browser acceptance on the network lab.
 *
 * Usage (toolbox of the xnetvn-agent-hub-wp namespace only):
 * env WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path=/sites/wpnetwork eval-file /work/tests/E2E/ui-network-fixture.php <scenario> --use-include
 * Scenario is one of seed, snapshot, restore.
 *
 * The installer (infra/remote/ui-network-start.sh) owns the network itself,
 * the main site, the /ui-second/ child site and plugin activation. This
 * fixture only owns test users, per-site roles, one isolation key per site,
 * two audit canaries, the per-site retention value and the snapshot used to
 * restore what the fixture changed. Cleanup deletes only ledger-listed rows
 * whose names still carry the xnetvn-ui-net- marker, and only the audit rows
 * whose exact request id, owner and ability match the ledger.
 *
 * The ledger lives in the options of the main site, so both blogs share one
 * ownership record. A run identifier (XNETVN_UI_RUN_ID, optional) marks the
 * actors and derives the audit request ids of the canaries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Support\SystemClock;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture requires WP_CLI.' );
}
if ( '1' !== getenv( 'XNETVN_RTL' ) || 'local' !== wp_get_environment_type() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture requires the lab environment.' );
}
if ( ! is_multisite() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture requires a multisite install.' );
}
$xnetvn_agent_hub_wp_net_hosts = array();
foreach ( array( 'XNETVN_RTL_DOMAIN', 'RTL_DOMAIN_WPNETWORK', 'RTL_ALIAS_WPNETWORK' ) as $xnetvn_agent_hub_wp_net_name ) {
	$xnetvn_agent_hub_wp_net_value = getenv( $xnetvn_agent_hub_wp_net_name );
	if ( is_string( $xnetvn_agent_hub_wp_net_value ) && '' !== $xnetvn_agent_hub_wp_net_value ) {
		$xnetvn_agent_hub_wp_net_hosts[] = $xnetvn_agent_hub_wp_net_value;
	}
}
$xnetvn_agent_hub_wp_net_home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_hosts, true ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture domain mismatch.' );
}

// The top level of an eval-file script runs in the scope of the WP-CLI
// command, not in the global scope: a function of this file must never read
// these variables through `global`, or it reads nothing at all. The shared
// ledger key, the main blog id and the owned marker are therefore literals
// inside the functions that need them.
$xnetvn_agent_hub_wp_net_snapshot_key    = 'xnetvn_ui_network_snapshot';
$xnetvn_agent_hub_wp_net_main_canary     = 'xnetvn/ui-main-canary';
$xnetvn_agent_hub_wp_net_child_canary    = 'xnetvn/ui-child-canary';
$xnetvn_agent_hub_wp_net_main_retention  = 89;
$xnetvn_agent_hub_wp_net_child_retention = 88;
$xnetvn_agent_hub_wp_net_max_probes      = 20;

/**
 * Resolve the owned child site id by its subdirectory path.
 *
 * @return int Child blog id.
 * @throws \RuntimeException When the fixture cannot proceed safely.
 */
function xnetvn_agent_hub_wp_net_child_id(): int {
	$xnetvn_agent_hub_wp_net_sites = get_sites(
		array(
			'path'   => '/ui-second/',
			'number' => 1,
			'fields' => 'ids',
		)
	);
	if ( ! is_array( $xnetvn_agent_hub_wp_net_sites ) || 1 !== count( $xnetvn_agent_hub_wp_net_sites ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network child site /ui-second/ missing: run infra/remote/ui-network-start.sh first.' );
	}
	return (int) $xnetvn_agent_hub_wp_net_sites[0];
}

/**
 * Run a callback on one blog and always restore the original site.
 *
 * @param int      $xnetvn_agent_hub_wp_net_blog Blog id.
 * @param callable $xnetvn_agent_hub_wp_net_run  Callback receiving the blog id.
 * @return mixed Callback result.
 */
function xnetvn_agent_hub_wp_net_on_blog( int $xnetvn_agent_hub_wp_net_blog, callable $xnetvn_agent_hub_wp_net_run ) {
	switch_to_blog( $xnetvn_agent_hub_wp_net_blog );
	try {
		return $xnetvn_agent_hub_wp_net_run( $xnetvn_agent_hub_wp_net_blog );
	} finally {
		restore_current_blog();
	}
}

/**
 * Run a callback on the main site, where the shared ledger lives.
 *
 * @param callable $xnetvn_agent_hub_wp_net_run Callback.
 * @return mixed Callback result.
 */
function xnetvn_agent_hub_wp_net_on_main( callable $xnetvn_agent_hub_wp_net_run ) {
	switch_to_blog( 1 );
	try {
		return $xnetvn_agent_hub_wp_net_run();
	} finally {
		restore_current_blog();
	}
}

/**
 * Read the owned ledger of the main site.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_net_ledger(): array {
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_on_main(
		static function () {
			return get_option( 'xnetvn_ui_network_ledger', array() );
		}
	);
	return is_array( $xnetvn_agent_hub_wp_net_ledger ) ? $xnetvn_agent_hub_wp_net_ledger : array();
}

/**
 * Persist the owned ledger of the main site.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_ledger Ledger.
 */
function xnetvn_agent_hub_wp_net_save_ledger( array $xnetvn_agent_hub_wp_net_ledger ): void {
	xnetvn_agent_hub_wp_net_on_main(
		static function () use ( $xnetvn_agent_hub_wp_net_ledger ): void {
			update_option( 'xnetvn_ui_network_ledger', $xnetvn_agent_hub_wp_net_ledger, false );
		}
	);
}

/**
 * Resolve the fixture login password from the current test environment.
 *
 * @return string
 */
function xnetvn_agent_hub_wp_net_password(): string {
	$xnetvn_agent_hub_wp_net_password = getenv( 'XNETVN_TEST_PASSWORD' );
	if ( ! is_string( $xnetvn_agent_hub_wp_net_password ) || '' === $xnetvn_agent_hub_wp_net_password ) {
		$xnetvn_agent_hub_wp_net_password = getenv( 'RTL_ADMIN_PASSWORD' );
	}
	return is_string( $xnetvn_agent_hub_wp_net_password ) ? $xnetvn_agent_hub_wp_net_password : '';
}

/**
 * Fixed fixture actors: login suffix => per-site WordPress role.
 *
 * @return array<string,string>
 */
function xnetvn_agent_hub_wp_net_actors(): array {
	return array(
		'administrator' => 'administrator',
		'editor'        => 'editor',
		'author'        => 'author',
		'contributor'   => 'contributor',
		'subscriber'    => 'subscriber',
		'editor_b'      => 'editor',
	);
}

/**
 * Login of the owned super administrator actor.
 *
 * @return string
 */
function xnetvn_agent_hub_wp_net_super_admin_login(): string {
	return 'xnetvn_ui_super_admin';
}

/**
 * Every login the fixture may own.
 *
 * @return array<int,string>
 */
function xnetvn_agent_hub_wp_net_logins(): array {
	$xnetvn_agent_hub_wp_net_logins = array();
	foreach ( array_keys( xnetvn_agent_hub_wp_net_actors() ) as $xnetvn_agent_hub_wp_net_suffix ) {
		$xnetvn_agent_hub_wp_net_logins[] = 'xnetvn_ui_' . $xnetvn_agent_hub_wp_net_suffix;
	}
	$xnetvn_agent_hub_wp_net_logins[] = xnetvn_agent_hub_wp_net_super_admin_login();
	return $xnetvn_agent_hub_wp_net_logins;
}

/**
 * Per-blog capabilities meta key of one actor.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 * @return string Meta key.
 */
function xnetvn_agent_hub_wp_net_capabilities_key( int $xnetvn_agent_hub_wp_net_blog ): string {
	global $wpdb;
	return $wpdb->get_blog_prefix( $xnetvn_agent_hub_wp_net_blog ) . 'capabilities';
}

/**
 * Ensure the owned fixture users exist and carry their role on both sites.
 *
 * @param int $xnetvn_agent_hub_wp_net_child Child blog id.
 * @return array<string,int> Login => user ID.
 * @throws \RuntimeException When the fixture cannot proceed safely.
 */
function xnetvn_agent_hub_wp_net_seed_users( int $xnetvn_agent_hub_wp_net_child ): array {
	$xnetvn_agent_hub_wp_net_password = xnetvn_agent_hub_wp_net_password();
	if ( '' === $xnetvn_agent_hub_wp_net_password ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network fixture test password unavailable.' );
	}
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_users  = isset( $xnetvn_agent_hub_wp_net_ledger['users'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['users'] ) ? $xnetvn_agent_hub_wp_net_ledger['users'] : array();
	foreach ( xnetvn_agent_hub_wp_net_actors() as $xnetvn_agent_hub_wp_net_suffix => $xnetvn_agent_hub_wp_net_role ) {
		$xnetvn_agent_hub_wp_net_login = 'xnetvn_ui_' . $xnetvn_agent_hub_wp_net_suffix;
		$xnetvn_agent_hub_wp_net_user  = get_user_by( 'login', $xnetvn_agent_hub_wp_net_login );
		if ( false === $xnetvn_agent_hub_wp_net_user ) {
			$xnetvn_agent_hub_wp_net_id = wp_insert_user(
				array(
					'user_login' => $xnetvn_agent_hub_wp_net_login,
					'user_pass'  => $xnetvn_agent_hub_wp_net_password,
					'user_email' => $xnetvn_agent_hub_wp_net_login . '@example.test',
					'role'       => '',
				)
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_net_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
				throw new RuntimeException( 'UI network fixture user unavailable.' );
			}
			update_user_meta( (int) $xnetvn_agent_hub_wp_net_id, 'xnetvn_ui_network_fixture', '1' );
			$xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_login ] = (int) $xnetvn_agent_hub_wp_net_id;
			$xnetvn_agent_hub_wp_net_user                                    = get_user_by( 'id', (int) $xnetvn_agent_hub_wp_net_id );
		}
		if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture user unavailable.' );
		}
		if ( '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_net_user->ID, 'xnetvn_ui_network_fixture', true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture login exists without marker.' );
		}
		wp_set_password( $xnetvn_agent_hub_wp_net_password, $xnetvn_agent_hub_wp_net_user->ID );
		foreach ( array( 1, $xnetvn_agent_hub_wp_net_child ) as $xnetvn_agent_hub_wp_net_blog ) {
			add_user_to_blog( (int) $xnetvn_agent_hub_wp_net_blog, $xnetvn_agent_hub_wp_net_user->ID, $xnetvn_agent_hub_wp_net_role );
		}
		$xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_login ] = (int) $xnetvn_agent_hub_wp_net_user->ID;
	}
	$xnetvn_agent_hub_wp_net_ledger['users'] = $xnetvn_agent_hub_wp_net_users;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	return $xnetvn_agent_hub_wp_net_users;
}

/**
 * Ensure the owned super administrator exists, is marked and is confirmed by
 * the ledger. An existing login without the owned marker, or without a ledger
 * entry, stops the fixture instead of taking over a foreign account.
 *
 * @param int               $xnetvn_agent_hub_wp_net_child Child blog id.
 * @param array<string,int> $xnetvn_agent_hub_wp_net_users Owned users.
 * @param string            $xnetvn_agent_hub_wp_net_run_id Owned run identifier.
 * @return array<string,mixed> Ledger entry of the super administrator.
 * @throws \RuntimeException When the fixture cannot proceed safely.
 */
function xnetvn_agent_hub_wp_net_seed_super_admin( int $xnetvn_agent_hub_wp_net_child, array $xnetvn_agent_hub_wp_net_users, string $xnetvn_agent_hub_wp_net_run_id ): array {
	$xnetvn_agent_hub_wp_net_password = xnetvn_agent_hub_wp_net_password();
	$xnetvn_agent_hub_wp_net_login    = xnetvn_agent_hub_wp_net_super_admin_login();
	$xnetvn_agent_hub_wp_net_ledger   = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_user     = get_user_by( 'login', $xnetvn_agent_hub_wp_net_login );
	$xnetvn_agent_hub_wp_net_created  = false;
	if ( false === $xnetvn_agent_hub_wp_net_user ) {
		$xnetvn_agent_hub_wp_net_id = wp_insert_user(
			array(
				'user_login' => $xnetvn_agent_hub_wp_net_login,
				'user_pass'  => $xnetvn_agent_hub_wp_net_password,
				'user_email' => $xnetvn_agent_hub_wp_net_login . '@example.test',
				'role'       => '',
			)
		);
		if ( is_wp_error( $xnetvn_agent_hub_wp_net_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture super administrator unavailable.' );
		}
		update_user_meta( (int) $xnetvn_agent_hub_wp_net_id, 'xnetvn_ui_network_fixture', '1' );
		update_user_meta( (int) $xnetvn_agent_hub_wp_net_id, 'xnetvn_ui_network_super_admin', $xnetvn_agent_hub_wp_net_run_id );
		$xnetvn_agent_hub_wp_net_user    = get_user_by( 'id', (int) $xnetvn_agent_hub_wp_net_id );
		$xnetvn_agent_hub_wp_net_created = true;
	}
	if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network fixture super administrator unavailable.' );
	}
	if ( '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_net_user->ID, 'xnetvn_ui_network_fixture', true ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network fixture super administrator exists without marker.' );
	}
	if ( ! $xnetvn_agent_hub_wp_net_created ) {
		// The marker metas are the ownership proof: only this fixture writes
		// them, so an account that carries them belongs to the fixture. The
		// ledger may never name a different actor for the network role, and an
		// account without the network role marker is never taken over.
		$xnetvn_agent_hub_wp_net_owned = isset( $xnetvn_agent_hub_wp_net_ledger['super_admin'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['super_admin'] ) ? $xnetvn_agent_hub_wp_net_ledger['super_admin'] : array();
		if ( (int) ( $xnetvn_agent_hub_wp_net_owned['user_id'] ?? 0 ) > 0 && (int) $xnetvn_agent_hub_wp_net_owned['user_id'] !== (int) $xnetvn_agent_hub_wp_net_user->ID ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture super administrator is not confirmed by the owned ledger.' );
		}
		if ( '' === (string) get_user_meta( $xnetvn_agent_hub_wp_net_user->ID, 'xnetvn_ui_network_super_admin', true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture super administrator has no owned network role marker.' );
		}
	}
	wp_set_password( $xnetvn_agent_hub_wp_net_password, $xnetvn_agent_hub_wp_net_user->ID );
	foreach ( array( 1, $xnetvn_agent_hub_wp_net_child ) as $xnetvn_agent_hub_wp_net_blog ) {
		add_user_to_blog( (int) $xnetvn_agent_hub_wp_net_blog, $xnetvn_agent_hub_wp_net_user->ID, 'administrator' );
	}
	$xnetvn_agent_hub_wp_net_granted = false;
	if ( ! is_super_admin( $xnetvn_agent_hub_wp_net_user->ID ) ) {
		if ( ! grant_super_admin( $xnetvn_agent_hub_wp_net_user->ID ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI network fixture super administrator grant failed.' );
		}
		$xnetvn_agent_hub_wp_net_granted = true;
	}
	$xnetvn_agent_hub_wp_net_ledger['users'][ $xnetvn_agent_hub_wp_net_login ] = (int) $xnetvn_agent_hub_wp_net_user->ID;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	return array(
		'login'   => $xnetvn_agent_hub_wp_net_login,
		'user_id' => (int) $xnetvn_agent_hub_wp_net_user->ID,
		'created' => $xnetvn_agent_hub_wp_net_created,
		'granted' => $xnetvn_agent_hub_wp_net_granted,
		'owned'   => true,
		'run_id'  => $xnetvn_agent_hub_wp_net_run_id,
	);
}

/**
 * Count owned isolation rows currently stored on one blog.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 * @return int Owned key count on that blog.
 */
function xnetvn_agent_hub_wp_net_count_owned( int $xnetvn_agent_hub_wp_net_blog ): int {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_blogs  = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_ids    = isset( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) && is_array( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) ? array_values( array_unique( array_map( 'intval', $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) ) ) : array();
	$xnetvn_agent_hub_wp_net_found  = 0;
	foreach ( $xnetvn_agent_hub_wp_net_ids as $xnetvn_agent_hub_wp_net_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture reconciliation by owned ID only.
		$xnetvn_agent_hub_wp_net_name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_net_id ) );
		if ( is_string( $xnetvn_agent_hub_wp_net_name ) && str_starts_with( $xnetvn_agent_hub_wp_net_name, 'xnetvn-ui-net-' ) ) {
			++$xnetvn_agent_hub_wp_net_found;
		}
	}
	return $xnetvn_agent_hub_wp_net_found;
}

/**
 * Delete only ledger-listed rows on one blog after rechecking the marker.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 */
function xnetvn_agent_hub_wp_net_delete_owned( int $xnetvn_agent_hub_wp_net_blog ): void {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_blogs  = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_ids    = isset( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) && is_array( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) ? array_values( array_unique( array_map( 'intval', $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] ) ) ) : array();
	foreach ( $xnetvn_agent_hub_wp_net_ids as $xnetvn_agent_hub_wp_net_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture cleanup by owned ID only.
		$xnetvn_agent_hub_wp_net_name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_net_id ) );
		if ( is_string( $xnetvn_agent_hub_wp_net_name ) && str_starts_with( $xnetvn_agent_hub_wp_net_name, 'xnetvn-ui-net-' ) ) {
			$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', array( 'id' => $xnetvn_agent_hub_wp_net_id ), array( '%d' ) );
		}
	}
	$xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['keys'] = array();
	$xnetvn_agent_hub_wp_net_ledger['blogs']                                = $xnetvn_agent_hub_wp_net_blogs;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
}

/**
 * Insert one owned isolation key on the current blog.
 *
 * @param int    $xnetvn_agent_hub_wp_net_owner Owner of the key.
 * @param string $xnetvn_agent_hub_wp_net_name  Owned key name.
 * @return int Owned key id.
 */
function xnetvn_agent_hub_wp_net_insert_owned_key( int $xnetvn_agent_hub_wp_net_owner, string $xnetvn_agent_hub_wp_net_name ): int {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_generated                      = ( new KeyGenerator() )->generate();
	$xnetvn_agent_hub_wp_net_pepper                         = ( new PepperStore() )->current();
	$xnetvn_agent_hub_wp_net_row                            = ( new WpdbKeyRepository( $wpdb, new SystemClock() ) )->insert( $xnetvn_agent_hub_wp_net_generated->id, $xnetvn_agent_hub_wp_net_owner, $xnetvn_agent_hub_wp_net_name, new Scope( RiskTier::Read, array( 'system' ), array(), array(), array(), new DateTimeImmutable( '+30 days' ) ), $xnetvn_agent_hub_wp_net_generated->hash_with( $xnetvn_agent_hub_wp_net_pepper ), $xnetvn_agent_hub_wp_net_pepper->id );
	$xnetvn_agent_hub_wp_net_ledger                         = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_blogs                          = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_entry                          = isset( $xnetvn_agent_hub_wp_net_blogs[ get_current_blog_id() ] ) && is_array( $xnetvn_agent_hub_wp_net_blogs[ get_current_blog_id() ] ) ? $xnetvn_agent_hub_wp_net_blogs[ get_current_blog_id() ] : array();
	$xnetvn_agent_hub_wp_net_keys                           = isset( $xnetvn_agent_hub_wp_net_entry['keys'] ) && is_array( $xnetvn_agent_hub_wp_net_entry['keys'] ) ? $xnetvn_agent_hub_wp_net_entry['keys'] : array();
	$xnetvn_agent_hub_wp_net_keys[]                         = (int) $xnetvn_agent_hub_wp_net_row->id;
	$xnetvn_agent_hub_wp_net_entry['keys']                  = array_values( array_unique( array_map( 'intval', $xnetvn_agent_hub_wp_net_keys ) ) );
	$xnetvn_agent_hub_wp_net_blogs[ get_current_blog_id() ] = $xnetvn_agent_hub_wp_net_entry;
	$xnetvn_agent_hub_wp_net_ledger['blogs']                = $xnetvn_agent_hub_wp_net_blogs;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	return (int) $xnetvn_agent_hub_wp_net_row->id;
}

/**
 * Check whether one blog stores a key row with the given id.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 * @param int $xnetvn_agent_hub_wp_net_id    Key id.
 * @return bool True when the blog owns a row with that id.
 */
function xnetvn_agent_hub_wp_net_blog_owns_key( int $xnetvn_agent_hub_wp_net_blog, int $xnetvn_agent_hub_wp_net_id ): bool {
	global $wpdb;
	return xnetvn_agent_hub_wp_net_on_blog(
		$xnetvn_agent_hub_wp_net_blog,
		static function () use ( $wpdb, $xnetvn_agent_hub_wp_net_id ): bool {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture collision check by exact id only.
			$xnetvn_agent_hub_wp_net_found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_net_id ) );
			return null !== $xnetvn_agent_hub_wp_net_found;
		}
	);
}

/**
 * Seed the owned audit canary of one blog and record it in the ledger.
 *
 * @param int    $xnetvn_agent_hub_wp_net_blog    Blog id.
 * @param int    $xnetvn_agent_hub_wp_net_owner   Owner of the canary.
 * @param string $xnetvn_agent_hub_wp_net_tag     Blog tag of the canary.
 * @param string $xnetvn_agent_hub_wp_net_ability Ability of the canary.
 * @param string $xnetvn_agent_hub_wp_net_run_id  Owned run identifier.
 * @return string Request id of the canary.
 */
function xnetvn_agent_hub_wp_net_seed_canary( int $xnetvn_agent_hub_wp_net_blog, int $xnetvn_agent_hub_wp_net_owner, string $xnetvn_agent_hub_wp_net_tag, string $xnetvn_agent_hub_wp_net_ability, string $xnetvn_agent_hub_wp_net_run_id ): string {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_request_id = substr( hash( 'sha256', $xnetvn_agent_hub_wp_net_run_id . ':' . $xnetvn_agent_hub_wp_net_tag ), 0, 16 );
	xnetvn_agent_hub_wp_net_on_blog(
		$xnetvn_agent_hub_wp_net_blog,
		static function () use ( $wpdb, $xnetvn_agent_hub_wp_net_owner, $xnetvn_agent_hub_wp_net_ability, $xnetvn_agent_hub_wp_net_request_id ): void {
			$xnetvn_agent_hub_wp_net_repository = new WpdbAuditRepository( $wpdb );
			$xnetvn_agent_hub_wp_net_repository->insert_batch(
				array(
					array(
						'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
						'request_id'  => $xnetvn_agent_hub_wp_net_request_id,
						'user_id'     => $xnetvn_agent_hub_wp_net_owner,
						'transport'   => 'http',
						'ability'     => $xnetvn_agent_hub_wp_net_ability,
						'outcome'     => 'success',
						'arg_keys'    => array(),
					),
				)
			);
		}
	);
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_blogs  = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_list   = isset( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] ) && is_array( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] ) ? $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] : array();
	$xnetvn_agent_hub_wp_net_list[] = array(
		'request_id' => $xnetvn_agent_hub_wp_net_request_id,
		'ability'    => $xnetvn_agent_hub_wp_net_ability,
		'user_id'    => $xnetvn_agent_hub_wp_net_owner,
		'blog'       => $xnetvn_agent_hub_wp_net_blog,
	);
	$xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] = $xnetvn_agent_hub_wp_net_list;
	$xnetvn_agent_hub_wp_net_ledger['blogs']                                    = $xnetvn_agent_hub_wp_net_blogs;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	return $xnetvn_agent_hub_wp_net_request_id;
}

/**
 * Delete the audit canaries of one blog that the ledger confirms.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 */
function xnetvn_agent_hub_wp_net_delete_canaries( int $xnetvn_agent_hub_wp_net_blog ): void {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_ledger = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_blogs  = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_list   = isset( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] ) && is_array( $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] ) ? $xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] : array();
	foreach ( $xnetvn_agent_hub_wp_net_list as $xnetvn_agent_hub_wp_net_canary ) {
		if ( ! is_array( $xnetvn_agent_hub_wp_net_canary ) ) {
			continue;
		}
		$xnetvn_agent_hub_wp_net_request_id = isset( $xnetvn_agent_hub_wp_net_canary['request_id'] ) && is_string( $xnetvn_agent_hub_wp_net_canary['request_id'] ) ? $xnetvn_agent_hub_wp_net_canary['request_id'] : '';
		$xnetvn_agent_hub_wp_net_ability    = isset( $xnetvn_agent_hub_wp_net_canary['ability'] ) && is_string( $xnetvn_agent_hub_wp_net_canary['ability'] ) ? $xnetvn_agent_hub_wp_net_canary['ability'] : '';
		$xnetvn_agent_hub_wp_net_user_id    = isset( $xnetvn_agent_hub_wp_net_canary['user_id'] ) ? (int) $xnetvn_agent_hub_wp_net_canary['user_id'] : 0;
		if ( '' === $xnetvn_agent_hub_wp_net_request_id || '' === $xnetvn_agent_hub_wp_net_ability || $xnetvn_agent_hub_wp_net_user_id < 1 ) {
			continue;
		}
		xnetvn_agent_hub_wp_net_on_blog(
			$xnetvn_agent_hub_wp_net_blog,
			static function () use ( $wpdb, $xnetvn_agent_hub_wp_net_request_id, $xnetvn_agent_hub_wp_net_ability, $xnetvn_agent_hub_wp_net_user_id ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture cleanup by owned request id and owner only.
				$xnetvn_agent_hub_wp_net_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, ability FROM %i WHERE request_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_net_request_id, $xnetvn_agent_hub_wp_net_user_id ) );
				if ( ! is_array( $xnetvn_agent_hub_wp_net_rows ) ) {
					return;
				}
				foreach ( $xnetvn_agent_hub_wp_net_rows as $xnetvn_agent_hub_wp_net_row ) {
					if ( (string) $xnetvn_agent_hub_wp_net_row->ability !== $xnetvn_agent_hub_wp_net_ability ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
						throw new RuntimeException( 'UI network fixture audit canary marker mismatch.' );
					}
					$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', array( 'id' => (int) $xnetvn_agent_hub_wp_net_row->id ), array( '%d' ) );
				}
			}
		);
	}
	$xnetvn_agent_hub_wp_net_blogs[ $xnetvn_agent_hub_wp_net_blog ]['canaries'] = array();
	$xnetvn_agent_hub_wp_net_ledger['blogs']                                    = $xnetvn_agent_hub_wp_net_blogs;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
}

/**
 * Seed the owned retention value of one blog and keep every other key.
 *
 * @param int $xnetvn_agent_hub_wp_net_blog Blog id.
 * @param int $xnetvn_agent_hub_wp_net_value Retention value.
 */
function xnetvn_agent_hub_wp_net_seed_retention( int $xnetvn_agent_hub_wp_net_blog, int $xnetvn_agent_hub_wp_net_value ): void {
	xnetvn_agent_hub_wp_net_on_blog(
		$xnetvn_agent_hub_wp_net_blog,
		static function () use ( $xnetvn_agent_hub_wp_net_value ): void {
			$xnetvn_agent_hub_wp_net_settings = get_option( WpSiteSettings::OPTION, array() );
			if ( ! is_array( $xnetvn_agent_hub_wp_net_settings ) ) {
				$xnetvn_agent_hub_wp_net_settings = array();
			}
			$xnetvn_agent_hub_wp_net_settings['audit_retention_days'] = $xnetvn_agent_hub_wp_net_value;
			// The autoload flag of the option is never touched by the fixture:
			// the third argument stays unset so the stored flag survives.
			update_option( WpSiteSettings::OPTION, $xnetvn_agent_hub_wp_net_settings );
		}
	);
}

/**
 * Capture the state of the owned actors before the fixture changes it.
 *
 * @param array<int,int> $xnetvn_agent_hub_wp_net_blogs Blogs of the network.
 * @return array<string,array<string,mixed>> Actor state.
 */
function xnetvn_agent_hub_wp_net_snapshot_actors( array $xnetvn_agent_hub_wp_net_blogs ): array {
	$xnetvn_agent_hub_wp_net_actors = array();
	foreach ( xnetvn_agent_hub_wp_net_logins() as $xnetvn_agent_hub_wp_net_login ) {
		$xnetvn_agent_hub_wp_net_entry = array(
			'existed'     => false,
			'user_id'     => 0,
			'super_admin' => false,
			'roles'       => array(),
		);
		$xnetvn_agent_hub_wp_net_user  = get_user_by( 'login', $xnetvn_agent_hub_wp_net_login );
		if ( $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
			$xnetvn_agent_hub_wp_net_entry['existed']     = true;
			$xnetvn_agent_hub_wp_net_entry['user_id']     = (int) $xnetvn_agent_hub_wp_net_user->ID;
			$xnetvn_agent_hub_wp_net_entry['super_admin'] = is_super_admin( $xnetvn_agent_hub_wp_net_user->ID );
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_caps = get_user_meta( $xnetvn_agent_hub_wp_net_user->ID, xnetvn_agent_hub_wp_net_capabilities_key( (int) $xnetvn_agent_hub_wp_net_blog ), true );
				$xnetvn_agent_hub_wp_net_entry['roles'][ (int) $xnetvn_agent_hub_wp_net_blog ] = is_array( $xnetvn_agent_hub_wp_net_caps ) ? array_values( $xnetvn_agent_hub_wp_net_caps ) : array();
			}
		}
		$xnetvn_agent_hub_wp_net_actors[ $xnetvn_agent_hub_wp_net_login ] = $xnetvn_agent_hub_wp_net_entry;
	}
	return $xnetvn_agent_hub_wp_net_actors;
}

/**
 * Restore the state of the owned actors.
 *
 * @param array<string,array<string,mixed>> $xnetvn_agent_hub_wp_net_actors Actor state.
 * @param array<int,int>                    $xnetvn_agent_hub_wp_net_blogs   Blogs of the network.
 */
function xnetvn_agent_hub_wp_net_restore_actors( array $xnetvn_agent_hub_wp_net_actors, array $xnetvn_agent_hub_wp_net_blogs ): void {
	foreach ( $xnetvn_agent_hub_wp_net_actors as $xnetvn_agent_hub_wp_net_login => $xnetvn_agent_hub_wp_net_entry ) {
		if ( ! is_array( $xnetvn_agent_hub_wp_net_entry ) ) {
			continue;
		}
		$xnetvn_agent_hub_wp_net_user = get_user_by( 'login', (string) $xnetvn_agent_hub_wp_net_login );
		if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
			continue;
		}
		if ( empty( $xnetvn_agent_hub_wp_net_entry['existed'] ) ) {
			if ( is_super_admin( $xnetvn_agent_hub_wp_net_user->ID ) ) {
				revoke_super_admin( $xnetvn_agent_hub_wp_net_user->ID );
			}
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				remove_user_from_blog( $xnetvn_agent_hub_wp_net_user->ID, (int) $xnetvn_agent_hub_wp_net_blog );
			}
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $xnetvn_agent_hub_wp_net_user->ID );
			continue;
		}
		$xnetvn_agent_hub_wp_net_was_super = ! empty( $xnetvn_agent_hub_wp_net_entry['super_admin'] );
		if ( is_super_admin( $xnetvn_agent_hub_wp_net_user->ID ) !== $xnetvn_agent_hub_wp_net_was_super ) {
			if ( is_super_admin( $xnetvn_agent_hub_wp_net_user->ID ) ) {
				revoke_super_admin( $xnetvn_agent_hub_wp_net_user->ID );
			} else {
				grant_super_admin( $xnetvn_agent_hub_wp_net_user->ID );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
			$xnetvn_agent_hub_wp_net_key   = xnetvn_agent_hub_wp_net_capabilities_key( (int) $xnetvn_agent_hub_wp_net_blog );
			$xnetvn_agent_hub_wp_net_roles = isset( $xnetvn_agent_hub_wp_net_entry['roles'][ (int) $xnetvn_agent_hub_wp_net_blog ] ) && is_array( $xnetvn_agent_hub_wp_net_entry['roles'][ (int) $xnetvn_agent_hub_wp_net_blog ] ) ? $xnetvn_agent_hub_wp_net_entry['roles'][ (int) $xnetvn_agent_hub_wp_net_blog ] : array();
			if ( array() === $xnetvn_agent_hub_wp_net_roles ) {
				delete_user_meta( $xnetvn_agent_hub_wp_net_user->ID, $xnetvn_agent_hub_wp_net_key );
				continue;
			}
			update_user_meta( $xnetvn_agent_hub_wp_net_user->ID, $xnetvn_agent_hub_wp_net_key, $xnetvn_agent_hub_wp_net_roles );
		}
	}
}

/**
 * Remove the actors the fixture owns when no snapshot is available.
 *
 * @param array<int,int> $xnetvn_agent_hub_wp_net_blogs Blogs of the network.
 */
function xnetvn_agent_hub_wp_net_remove_owned_actors( array $xnetvn_agent_hub_wp_net_blogs ): void {
	$xnetvn_agent_hub_wp_net_actors = array();
	foreach ( xnetvn_agent_hub_wp_net_logins() as $xnetvn_agent_hub_wp_net_login ) {
		$xnetvn_agent_hub_wp_net_user = get_user_by( 'login', $xnetvn_agent_hub_wp_net_login );
		if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
			continue;
		}
		if ( '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_net_user->ID, 'xnetvn_ui_network_fixture', true ) ) {
			continue;
		}
		$xnetvn_agent_hub_wp_net_actors[ $xnetvn_agent_hub_wp_net_login ] = array(
			'existed'     => false,
			'user_id'     => (int) $xnetvn_agent_hub_wp_net_user->ID,
			'super_admin' => false,
			'roles'       => array(),
		);
	}
	xnetvn_agent_hub_wp_net_restore_actors( $xnetvn_agent_hub_wp_net_actors, $xnetvn_agent_hub_wp_net_blogs );
}

$xnetvn_agent_hub_wp_net_scenario = $args[0] ?? '';
if ( ! in_array( $xnetvn_agent_hub_wp_net_scenario, array( 'seed', 'snapshot', 'restore' ), true ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture unknown scenario.' );
}
if ( count( $args ) > 1 ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture unexpected argument.' );
}
$xnetvn_agent_hub_wp_net_run_id = getenv( 'XNETVN_UI_RUN_ID' );
if ( ! is_string( $xnetvn_agent_hub_wp_net_run_id ) || '' === $xnetvn_agent_hub_wp_net_run_id ) {
	$xnetvn_agent_hub_wp_net_run_id = 'ui-net-fixture';
}
if ( 1 !== preg_match( '/\A[a-z0-9][a-z0-9-]{0,25}\z/', $xnetvn_agent_hub_wp_net_run_id ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI network fixture run id is not a bounded identifier.' );
}
$xnetvn_agent_hub_wp_net_child = xnetvn_agent_hub_wp_net_child_id();
$xnetvn_agent_hub_wp_net_blogs = array( 1, $xnetvn_agent_hub_wp_net_child );

if ( 'snapshot' === $xnetvn_agent_hub_wp_net_scenario ) {
	$xnetvn_agent_hub_wp_net_settings = array();
	foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
		$xnetvn_agent_hub_wp_net_settings[ $xnetvn_agent_hub_wp_net_blog ] = xnetvn_agent_hub_wp_net_on_blog(
			$xnetvn_agent_hub_wp_net_blog,
			static function () {
				return get_option( WpSiteSettings::OPTION, array() );
			}
		);
	}
	$xnetvn_agent_hub_wp_net_snapshot = array(
		'settings' => $xnetvn_agent_hub_wp_net_settings,
		'actors'   => xnetvn_agent_hub_wp_net_snapshot_actors( $xnetvn_agent_hub_wp_net_blogs ),
		'ledger'   => xnetvn_agent_hub_wp_net_ledger(),
		'run_id'   => $xnetvn_agent_hub_wp_net_run_id,
	);
	xnetvn_agent_hub_wp_net_on_main(
		static function () use ( $xnetvn_agent_hub_wp_net_snapshot_key, $xnetvn_agent_hub_wp_net_snapshot ): void {
			update_option( $xnetvn_agent_hub_wp_net_snapshot_key, $xnetvn_agent_hub_wp_net_snapshot, false );
		}
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo 'UI-NET snapshot blogs=' . count( $xnetvn_agent_hub_wp_net_blogs ) . " ok=1\n";
	return;
}

if ( 'seed' === $xnetvn_agent_hub_wp_net_scenario ) {
	$xnetvn_agent_hub_wp_net_users = xnetvn_agent_hub_wp_net_seed_users( $xnetvn_agent_hub_wp_net_child );
	$xnetvn_agent_hub_wp_net_super = xnetvn_agent_hub_wp_net_seed_super_admin( $xnetvn_agent_hub_wp_net_child, $xnetvn_agent_hub_wp_net_users, $xnetvn_agent_hub_wp_net_run_id );
	$xnetvn_agent_hub_wp_net_owner = isset( $xnetvn_agent_hub_wp_net_users['xnetvn_ui_administrator'] ) ? (int) $xnetvn_agent_hub_wp_net_users['xnetvn_ui_administrator'] : 0;
	if ( $xnetvn_agent_hub_wp_net_owner < 1 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network fixture owner unavailable.' );
	}
	$xnetvn_agent_hub_wp_net_ledger                = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_ledger['run_id']      = $xnetvn_agent_hub_wp_net_run_id;
	$xnetvn_agent_hub_wp_net_ledger['super_admin'] = $xnetvn_agent_hub_wp_net_super;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
		xnetvn_agent_hub_wp_net_delete_canaries( $xnetvn_agent_hub_wp_net_blog );
		xnetvn_agent_hub_wp_net_on_blog(
			$xnetvn_agent_hub_wp_net_blog,
			static function ( int $xnetvn_agent_hub_wp_net_blog ): void {
				xnetvn_agent_hub_wp_net_delete_owned( $xnetvn_agent_hub_wp_net_blog );
			}
		);
	}
	// The child site owns its own isolation row first, so the main probe can be
	// chosen by the ids the child site really stores.
	xnetvn_agent_hub_wp_net_on_blog(
		$xnetvn_agent_hub_wp_net_child,
		static function () use ( $xnetvn_agent_hub_wp_net_owner ): void {
			xnetvn_agent_hub_wp_net_insert_owned_key( $xnetvn_agent_hub_wp_net_owner, 'xnetvn-ui-net-child-k0' );
		}
	);
	$xnetvn_agent_hub_wp_net_probe = 0;
	for ( $xnetvn_agent_hub_wp_net_attempt = 0; $xnetvn_agent_hub_wp_net_attempt < $xnetvn_agent_hub_wp_net_max_probes; $xnetvn_agent_hub_wp_net_attempt++ ) {
		$xnetvn_agent_hub_wp_net_candidate = xnetvn_agent_hub_wp_net_on_blog(
			1,
			static function () use ( $xnetvn_agent_hub_wp_net_owner, $xnetvn_agent_hub_wp_net_attempt ): int {
				return xnetvn_agent_hub_wp_net_insert_owned_key( $xnetvn_agent_hub_wp_net_owner, 'xnetvn-ui-net-main-k' . $xnetvn_agent_hub_wp_net_attempt );
			}
		);
		if ( ! xnetvn_agent_hub_wp_net_blog_owns_key( $xnetvn_agent_hub_wp_net_child, $xnetvn_agent_hub_wp_net_candidate ) ) {
			$xnetvn_agent_hub_wp_net_probe = $xnetvn_agent_hub_wp_net_candidate;
			break;
		}
	}
	if ( $xnetvn_agent_hub_wp_net_probe < 1 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI network fixture could not own a main probe id the child site does not use.' );
	}
	$xnetvn_agent_hub_wp_net_ledger                   = xnetvn_agent_hub_wp_net_ledger();
	$xnetvn_agent_hub_wp_net_ledger_blogs             = isset( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) && is_array( $xnetvn_agent_hub_wp_net_ledger['blogs'] ) ? $xnetvn_agent_hub_wp_net_ledger['blogs'] : array();
	$xnetvn_agent_hub_wp_net_ledger_blogs[1]['probe'] = $xnetvn_agent_hub_wp_net_probe;
	$xnetvn_agent_hub_wp_net_ledger['blogs']          = $xnetvn_agent_hub_wp_net_ledger_blogs;
	xnetvn_agent_hub_wp_net_save_ledger( $xnetvn_agent_hub_wp_net_ledger );
	xnetvn_agent_hub_wp_net_seed_retention( 1, $xnetvn_agent_hub_wp_net_main_retention );
	xnetvn_agent_hub_wp_net_seed_retention( $xnetvn_agent_hub_wp_net_child, $xnetvn_agent_hub_wp_net_child_retention );
	xnetvn_agent_hub_wp_net_seed_canary( 1, $xnetvn_agent_hub_wp_net_owner, 'main', $xnetvn_agent_hub_wp_net_main_canary, $xnetvn_agent_hub_wp_net_run_id );
	xnetvn_agent_hub_wp_net_seed_canary( $xnetvn_agent_hub_wp_net_child, $xnetvn_agent_hub_wp_net_owner, 'child', $xnetvn_agent_hub_wp_net_child_canary, $xnetvn_agent_hub_wp_net_run_id );
	$xnetvn_agent_hub_wp_net_marks = array();
	foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
		$xnetvn_agent_hub_wp_net_marks[ $xnetvn_agent_hub_wp_net_blog ] = xnetvn_agent_hub_wp_net_on_blog(
			$xnetvn_agent_hub_wp_net_blog,
			static function ( int $xnetvn_agent_hub_wp_net_blog ): int {
				return xnetvn_agent_hub_wp_net_count_owned( $xnetvn_agent_hub_wp_net_blog );
			}
		);
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo 'UI-NET seed users=' . count( $xnetvn_agent_hub_wp_net_users ) . ' super=' . (int) $xnetvn_agent_hub_wp_net_super['user_id'] . ' main=' . (int) $xnetvn_agent_hub_wp_net_marks[1] . ' child=' . (int) $xnetvn_agent_hub_wp_net_marks[ $xnetvn_agent_hub_wp_net_child ] . "\n";
	return;
}

$xnetvn_agent_hub_wp_net_snapshot = xnetvn_agent_hub_wp_net_on_main(
	static function () use ( $xnetvn_agent_hub_wp_net_snapshot_key ) {
		return get_option( $xnetvn_agent_hub_wp_net_snapshot_key, null );
	}
);
if ( is_array( $xnetvn_agent_hub_wp_net_snapshot ) && isset( $xnetvn_agent_hub_wp_net_snapshot['settings'] ) && is_array( $xnetvn_agent_hub_wp_net_snapshot['settings'] ) ) {
	foreach ( $xnetvn_agent_hub_wp_net_snapshot['settings'] as $xnetvn_agent_hub_wp_net_blog => $xnetvn_agent_hub_wp_net_settings ) {
		if ( is_array( $xnetvn_agent_hub_wp_net_settings ) ) {
			xnetvn_agent_hub_wp_net_on_blog(
				(int) $xnetvn_agent_hub_wp_net_blog,
				static function () use ( $xnetvn_agent_hub_wp_net_settings ): void {
					// The autoload flag of the option is never touched by the fixture.
					update_option( WpSiteSettings::OPTION, $xnetvn_agent_hub_wp_net_settings );
				}
			);
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo "UI-NET restore settings=1\n";
} else {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo "UI-NET restore settings=0\n";
}
foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
	xnetvn_agent_hub_wp_net_delete_canaries( $xnetvn_agent_hub_wp_net_blog );
	xnetvn_agent_hub_wp_net_on_blog(
		$xnetvn_agent_hub_wp_net_blog,
		static function ( int $xnetvn_agent_hub_wp_net_blog ): void {
			xnetvn_agent_hub_wp_net_delete_owned( $xnetvn_agent_hub_wp_net_blog );
		}
	);
}
if ( is_array( $xnetvn_agent_hub_wp_net_snapshot ) && isset( $xnetvn_agent_hub_wp_net_snapshot['actors'] ) && is_array( $xnetvn_agent_hub_wp_net_snapshot['actors'] ) ) {
	xnetvn_agent_hub_wp_net_restore_actors( $xnetvn_agent_hub_wp_net_snapshot['actors'], $xnetvn_agent_hub_wp_net_blogs );
} else {
	// Without a snapshot the fixture still owns the marked actors, so they are
	// removed instead of being left behind for the next run.
	xnetvn_agent_hub_wp_net_remove_owned_actors( $xnetvn_agent_hub_wp_net_blogs );
}
xnetvn_agent_hub_wp_net_on_main(
	static function () use ( $xnetvn_agent_hub_wp_net_snapshot_key ): void {
		delete_option( $xnetvn_agent_hub_wp_net_snapshot_key );
		delete_option( 'xnetvn_ui_network_ledger' );
	}
);
