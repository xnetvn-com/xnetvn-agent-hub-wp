<?php
/**
 * Owned UI fixture for recoverable browser tests on the shared lab site.
 *
 * Usage: wp eval-file tests/E2E/ui-fixture.php <scenario> [count] --use-include
 * Scenario is one of seed, empty, pagination, snapshot, restore, notice.
 * Only pagination accepts a second argument, either 21 or 41 (default 21).
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Support\SystemClock;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI fixture requires WP_CLI.' );
}
if ( '1' !== getenv( 'XNETVN_RTL' ) || 'local' !== wp_get_environment_type() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI fixture requires the lab environment.' );
}
$xnetvn_agent_hub_wp_ui_hosts = array();
foreach ( array( 'XNETVN_RTL_DOMAIN', 'RTL_DOMAIN_WP69', 'RTL_DOMAIN_WP71', 'RTL_ALIAS_WP69', 'RTL_ALIAS_WP71' ) as $xnetvn_agent_hub_wp_ui_name ) {
	$xnetvn_agent_hub_wp_ui_value = getenv( $xnetvn_agent_hub_wp_ui_name );
	if ( is_string( $xnetvn_agent_hub_wp_ui_value ) && '' !== $xnetvn_agent_hub_wp_ui_value ) {
		$xnetvn_agent_hub_wp_ui_hosts[] = $xnetvn_agent_hub_wp_ui_value;
	}
}
$xnetvn_agent_hub_wp_ui_home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $xnetvn_agent_hub_wp_ui_home, $xnetvn_agent_hub_wp_ui_hosts, true ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI fixture domain mismatch.' );
}

/**
 * Read the owned ledger without autoloading it on every request.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_ui_ledger(): array {
	$xnetvn_agent_hub_wp_ui_ledger = get_option( 'xnetvn_ui_fixture_ledger', array() );
	return is_array( $xnetvn_agent_hub_wp_ui_ledger ) ? $xnetvn_agent_hub_wp_ui_ledger : array();
}

/**
 * Persist the owned ledger without autoload.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_ui_ledger Ledger.
 */
function xnetvn_agent_hub_wp_ui_save_ledger( array $xnetvn_agent_hub_wp_ui_ledger ): void {
	update_option( 'xnetvn_ui_fixture_ledger', $xnetvn_agent_hub_wp_ui_ledger, false );
}

/**
 * Resolve the fixture login password from the current test environment.
 *
 * @return string
 */
function xnetvn_agent_hub_wp_ui_password(): string {
	$xnetvn_agent_hub_wp_ui_password = getenv( 'XNETVN_TEST_PASSWORD' );
	if ( ! is_string( $xnetvn_agent_hub_wp_ui_password ) || '' === $xnetvn_agent_hub_wp_ui_password ) {
		$xnetvn_agent_hub_wp_ui_password = getenv( 'RTL_ADMIN_PASSWORD' );
	}
	return is_string( $xnetvn_agent_hub_wp_ui_password ) ? $xnetvn_agent_hub_wp_ui_password : '';
}

/**
 * Fixed fixture actors: login suffix => WordPress role.
 *
 * @return array<string,string>
 */
function xnetvn_agent_hub_wp_ui_actors(): array {
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
 * Ensure the six owned fixture users exist with the current test password.
 *
 * @return array<string,int> Login => user ID.
 * @throws \RuntimeException When the fixture cannot proceed safely.
 */
function xnetvn_agent_hub_wp_ui_seed_users(): array {
	$xnetvn_agent_hub_wp_ui_password = xnetvn_agent_hub_wp_ui_password();
	if ( '' === $xnetvn_agent_hub_wp_ui_password ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI fixture test password unavailable.' );
	}
	$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
	$xnetvn_agent_hub_wp_ui_users  = isset( $xnetvn_agent_hub_wp_ui_ledger['users'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['users'] ) ? $xnetvn_agent_hub_wp_ui_ledger['users'] : array();
	foreach ( xnetvn_agent_hub_wp_ui_actors() as $xnetvn_agent_hub_wp_ui_suffix => $xnetvn_agent_hub_wp_ui_role ) {
		$xnetvn_agent_hub_wp_ui_login = 'xnetvn_ui_' . $xnetvn_agent_hub_wp_ui_suffix;
		$xnetvn_agent_hub_wp_ui_user  = get_user_by( 'login', $xnetvn_agent_hub_wp_ui_login );
		if ( false === $xnetvn_agent_hub_wp_ui_user ) {
			$xnetvn_agent_hub_wp_ui_id = wp_insert_user(
				array(
					'user_login' => $xnetvn_agent_hub_wp_ui_login,
					'user_pass'  => $xnetvn_agent_hub_wp_ui_password,
					'user_email' => $xnetvn_agent_hub_wp_ui_login . '@example.test',
					'role'       => $xnetvn_agent_hub_wp_ui_role,
				)
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_ui_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
				throw new RuntimeException( 'UI fixture user unavailable.' );
			}
			update_user_meta( (int) $xnetvn_agent_hub_wp_ui_id, 'xnetvn_ui_fixture', '1' );
			$xnetvn_agent_hub_wp_ui_users[ $xnetvn_agent_hub_wp_ui_login ] = (int) $xnetvn_agent_hub_wp_ui_id;
			continue;
		}
		if ( '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_ui_user->ID, 'xnetvn_ui_fixture', true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI fixture login exists without marker.' );
		}
		if ( ! in_array( $xnetvn_agent_hub_wp_ui_role, (array) $xnetvn_agent_hub_wp_ui_user->roles, true ) ) {
			$xnetvn_agent_hub_wp_ui_user->set_role( $xnetvn_agent_hub_wp_ui_role );
		}
		wp_set_password( $xnetvn_agent_hub_wp_ui_password, $xnetvn_agent_hub_wp_ui_user->ID );
		$xnetvn_agent_hub_wp_ui_users[ $xnetvn_agent_hub_wp_ui_login ] = (int) $xnetvn_agent_hub_wp_ui_user->ID;
	}
	$xnetvn_agent_hub_wp_ui_ledger['users'] = $xnetvn_agent_hub_wp_ui_users;
	xnetvn_agent_hub_wp_ui_save_ledger( $xnetvn_agent_hub_wp_ui_ledger );
	return $xnetvn_agent_hub_wp_ui_users;
}

/**
 * Ensure the owned mail sink MU file with exact fixed content.
 *
 * @throws \RuntimeException When the fixture cannot proceed safely.
 */
function xnetvn_agent_hub_wp_ui_ensure_sink(): void {
	$xnetvn_agent_hub_wp_ui_path = trailingslashit( WPMU_PLUGIN_DIR ) . 'xnetvn-agent-hub-wp-ui-fixture-mail-sink.php';
	$xnetvn_agent_hub_wp_ui_code = "<?php\n/** Owned UI fixture mail sink for the shared test lab. */\nadd_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );\n";
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned lab mail sink.
	$xnetvn_agent_hub_wp_ui_current = is_file( $xnetvn_agent_hub_wp_ui_path ) ? file_get_contents( $xnetvn_agent_hub_wp_ui_path ) : null;
	if ( $xnetvn_agent_hub_wp_ui_code !== $xnetvn_agent_hub_wp_ui_current ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the owned lab mail sink.
		if ( false === file_put_contents( $xnetvn_agent_hub_wp_ui_path, $xnetvn_agent_hub_wp_ui_code ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'UI fixture mail sink unavailable.' );
		}
	}
	$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
	$xnetvn_agent_hub_wp_ui_mu     = isset( $xnetvn_agent_hub_wp_ui_ledger['mu'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['mu'] ) ? $xnetvn_agent_hub_wp_ui_ledger['mu'] : array();
	$xnetvn_agent_hub_wp_ui_mu['xnetvn-agent-hub-wp-ui-fixture-mail-sink.php'] = true;
	$xnetvn_agent_hub_wp_ui_ledger['mu']                                       = $xnetvn_agent_hub_wp_ui_mu;
	xnetvn_agent_hub_wp_ui_save_ledger( $xnetvn_agent_hub_wp_ui_ledger );
}

/**
 * Count owned fixture rows currently stored.
 *
 * @return array{keys:int,audit:int}
 */
function xnetvn_agent_hub_wp_ui_count_owned(): array {
	global $wpdb;
	$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
	$xnetvn_agent_hub_wp_ui_keys   = isset( $xnetvn_agent_hub_wp_ui_ledger['keys'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['keys'] ) ? array_values( array_unique( array_map( 'intval', $xnetvn_agent_hub_wp_ui_ledger['keys'] ) ) ) : array();
	$xnetvn_agent_hub_wp_ui_audit  = isset( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) ? array_values( array_unique( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) ) : array();
	$xnetvn_agent_hub_wp_ui_found  = 0;
	foreach ( $xnetvn_agent_hub_wp_ui_keys as $xnetvn_agent_hub_wp_ui_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture reconciliation by owned ID only.
		$xnetvn_agent_hub_wp_ui_name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_ui_id ) );
		if ( is_string( $xnetvn_agent_hub_wp_ui_name ) && str_starts_with( $xnetvn_agent_hub_wp_ui_name, 'xnetvn-ui-fx-' ) ) {
			++$xnetvn_agent_hub_wp_ui_found;
		}
	}
	$xnetvn_agent_hub_wp_ui_events = 0;
	foreach ( $xnetvn_agent_hub_wp_ui_audit as $xnetvn_agent_hub_wp_ui_request ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture reconciliation by owned request ID only.
		$xnetvn_agent_hub_wp_ui_hit = $wpdb->get_var( $wpdb->prepare( 'SELECT request_id FROM %i WHERE request_id = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_ui_request ) );
		if ( $xnetvn_agent_hub_wp_ui_hit === $xnetvn_agent_hub_wp_ui_request ) {
			++$xnetvn_agent_hub_wp_ui_events;
		}
	}
	return array(
		'keys'  => $xnetvn_agent_hub_wp_ui_found,
		'audit' => $xnetvn_agent_hub_wp_ui_events,
	);
}

/**
 * Delete only rows listed in the ledger after rechecking the ownership marker.
 */
function xnetvn_agent_hub_wp_ui_delete_owned(): void {
	global $wpdb;
	$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
	$xnetvn_agent_hub_wp_ui_keys   = isset( $xnetvn_agent_hub_wp_ui_ledger['keys'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['keys'] ) ? array_values( array_unique( array_map( 'intval', $xnetvn_agent_hub_wp_ui_ledger['keys'] ) ) ) : array();
	$xnetvn_agent_hub_wp_ui_audit  = isset( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) ? array_values( array_unique( $xnetvn_agent_hub_wp_ui_ledger['audit'] ) ) : array();
	foreach ( $xnetvn_agent_hub_wp_ui_keys as $xnetvn_agent_hub_wp_ui_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture cleanup by owned ID only.
		$xnetvn_agent_hub_wp_ui_name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_ui_id ) );
		if ( is_string( $xnetvn_agent_hub_wp_ui_name ) && str_starts_with( $xnetvn_agent_hub_wp_ui_name, 'xnetvn-ui-fx-' ) ) {
			$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', array( 'id' => $xnetvn_agent_hub_wp_ui_id ), array( '%d' ) );
		}
	}
	foreach ( $xnetvn_agent_hub_wp_ui_audit as $xnetvn_agent_hub_wp_ui_request ) {
		if ( is_string( $xnetvn_agent_hub_wp_ui_request ) && 1 === preg_match( '/\A[a-f0-9]{16}\z/', $xnetvn_agent_hub_wp_ui_request ) ) {
			$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', array( 'request_id' => $xnetvn_agent_hub_wp_ui_request ), array( '%s' ) );
		}
	}
	$xnetvn_agent_hub_wp_ui_ledger['keys']     = array();
	$xnetvn_agent_hub_wp_ui_ledger['audit']    = array();
	$xnetvn_agent_hub_wp_ui_ledger['scenario'] = 'empty';
	$xnetvn_agent_hub_wp_ui_ledger['count']    = 0;
	xnetvn_agent_hub_wp_ui_save_ledger( $xnetvn_agent_hub_wp_ui_ledger );
}

/**
 * Read the active key quota without changing any setting.
 *
 * @return int
 */
function xnetvn_agent_hub_wp_ui_quota(): int {
	$xnetvn_agent_hub_wp_ui_settings = get_option( WpSiteSettings::OPTION, array() );
	$xnetvn_agent_hub_wp_ui_quota    = is_array( $xnetvn_agent_hub_wp_ui_settings ) && isset( $xnetvn_agent_hub_wp_ui_settings['max_keys_per_user'] ) ? (int) $xnetvn_agent_hub_wp_ui_settings['max_keys_per_user'] : 10;
	if ( $xnetvn_agent_hub_wp_ui_quota < 1 ) {
		$xnetvn_agent_hub_wp_ui_quota = 1;
	}
	if ( $xnetvn_agent_hub_wp_ui_quota > 100 ) {
		$xnetvn_agent_hub_wp_ui_quota = 100;
	}
	return $xnetvn_agent_hub_wp_ui_quota;
}

/**
 * Build exactly $count owned key and audit rows across the six fixture owners.
 *
 * @param int $xnetvn_agent_hub_wp_ui_count 21 or 41.
 */
function xnetvn_agent_hub_wp_ui_paginate( int $xnetvn_agent_hub_wp_ui_count ): void {
	global $wpdb;
	$xnetvn_agent_hub_wp_ui_users = xnetvn_agent_hub_wp_ui_seed_users();
	xnetvn_agent_hub_wp_ui_ensure_sink();
	$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
	if ( ( $xnetvn_agent_hub_wp_ui_ledger['scenario'] ?? '' ) === 'pagination-' . $xnetvn_agent_hub_wp_ui_count ) {
		$xnetvn_agent_hub_wp_ui_owned = xnetvn_agent_hub_wp_ui_count_owned();
		if ( $xnetvn_agent_hub_wp_ui_count === $xnetvn_agent_hub_wp_ui_owned['keys'] && $xnetvn_agent_hub_wp_ui_count === $xnetvn_agent_hub_wp_ui_owned['audit'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
			echo 'UI-FIX pagination-' . $xnetvn_agent_hub_wp_ui_count . ' users=6 keys=' . $xnetvn_agent_hub_wp_ui_count . ' audit=' . $xnetvn_agent_hub_wp_ui_count . "\n";
			return;
		}
	}
	xnetvn_agent_hub_wp_ui_delete_owned();
	$xnetvn_agent_hub_wp_ui_quota       = xnetvn_agent_hub_wp_ui_quota();
	$xnetvn_agent_hub_wp_ui_active_each = min( $xnetvn_agent_hub_wp_ui_quota, 21 === $xnetvn_agent_hub_wp_ui_count ? 3 : 6 );
	$xnetvn_agent_hub_wp_ui_logins      = array_keys( $xnetvn_agent_hub_wp_ui_users );
	$xnetvn_agent_hub_wp_ui_repository  = new WpdbKeyRepository( $wpdb, new SystemClock() );
	$xnetvn_agent_hub_wp_ui_pepper      = ( new PepperStore() )->current();
	$xnetvn_agent_hub_wp_ui_key_ids     = array();
	$xnetvn_agent_hub_wp_ui_events      = array();
	$xnetvn_agent_hub_wp_ui_index       = 0;
	foreach ( $xnetvn_agent_hub_wp_ui_logins as $xnetvn_agent_hub_wp_ui_owner => $xnetvn_agent_hub_wp_ui_login ) {
		$xnetvn_agent_hub_wp_ui_owner_id = (int) $xnetvn_agent_hub_wp_ui_users[ $xnetvn_agent_hub_wp_ui_login ];
		for ( $xnetvn_agent_hub_wp_ui_k = 0; $xnetvn_agent_hub_wp_ui_k < $xnetvn_agent_hub_wp_ui_active_each && $xnetvn_agent_hub_wp_ui_index < $xnetvn_agent_hub_wp_ui_count; ++$xnetvn_agent_hub_wp_ui_k ) {
			++$xnetvn_agent_hub_wp_ui_index;
			$xnetvn_agent_hub_wp_ui_name = 'xnetvn-ui-fx-p' . $xnetvn_agent_hub_wp_ui_count . '-o' . $xnetvn_agent_hub_wp_ui_owner . '-k' . $xnetvn_agent_hub_wp_ui_k;
			if ( 1 === $xnetvn_agent_hub_wp_ui_index ) {
				$xnetvn_agent_hub_wp_ui_name = $xnetvn_agent_hub_wp_ui_name . '-' . str_repeat( 'l', 99 - strlen( $xnetvn_agent_hub_wp_ui_name ) );
			} elseif ( 2 === $xnetvn_agent_hub_wp_ui_index ) {
				$xnetvn_agent_hub_wp_ui_name = 'xnetvn-ui-fx-Dữ liệu thử UI khóa mở rộng';
			}
			$xnetvn_agent_hub_wp_ui_generated = ( new KeyGenerator() )->generate();
			$xnetvn_agent_hub_wp_ui_row       = $xnetvn_agent_hub_wp_ui_repository->insert( $xnetvn_agent_hub_wp_ui_generated->id, $xnetvn_agent_hub_wp_ui_owner_id, $xnetvn_agent_hub_wp_ui_name, new Scope( RiskTier::Read, array( 'system' ), array(), array(), array(), new DateTimeImmutable( '+30 days' ) ), $xnetvn_agent_hub_wp_ui_generated->hash_with( $xnetvn_agent_hub_wp_ui_pepper ), $xnetvn_agent_hub_wp_ui_pepper->id );
			$xnetvn_agent_hub_wp_ui_key_ids[] = $xnetvn_agent_hub_wp_ui_row->id;
			$xnetvn_agent_hub_wp_ui_request   = substr( bin2hex( random_bytes( 16 ) ), 0, 16 );
			$xnetvn_agent_hub_wp_ui_events[]  = array(
				'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
				'request_id'  => $xnetvn_agent_hub_wp_ui_request,
				'key_id'      => $xnetvn_agent_hub_wp_ui_row->key_id->value,
				'user_id'     => $xnetvn_agent_hub_wp_ui_owner_id,
				'transport'   => 'http',
				'ability'     => 'system/ui-fixture',
				'outcome'     => 0 === $xnetvn_agent_hub_wp_ui_index % 7 ? 'denied' : 'success',
				'duration_ms' => 5,
				'ip'          => '203.0.113.7',
				'ua_short'    => 'UI fixture',
				'arg_keys'    => array(),
			);
		}
	}
	while ( $xnetvn_agent_hub_wp_ui_index < $xnetvn_agent_hub_wp_ui_count ) {
		++$xnetvn_agent_hub_wp_ui_index;
		$xnetvn_agent_hub_wp_ui_login     = $xnetvn_agent_hub_wp_ui_logins[ ( $xnetvn_agent_hub_wp_ui_index - 1 ) % count( $xnetvn_agent_hub_wp_ui_logins ) ];
		$xnetvn_agent_hub_wp_ui_owner_id  = (int) $xnetvn_agent_hub_wp_ui_users[ $xnetvn_agent_hub_wp_ui_login ];
		$xnetvn_agent_hub_wp_ui_retired   = 0 === $xnetvn_agent_hub_wp_ui_index % 2;
		$xnetvn_agent_hub_wp_ui_name      = 'xnetvn-ui-fx-p' . $xnetvn_agent_hub_wp_ui_count . '-retired-' . $xnetvn_agent_hub_wp_ui_index;
		$xnetvn_agent_hub_wp_ui_generated = ( new KeyGenerator() )->generate();
		$xnetvn_agent_hub_wp_ui_row       = $xnetvn_agent_hub_wp_ui_repository->insert( $xnetvn_agent_hub_wp_ui_generated->id, $xnetvn_agent_hub_wp_ui_owner_id, $xnetvn_agent_hub_wp_ui_name, new Scope( RiskTier::Read, array( 'system' ), array(), array(), array(), $xnetvn_agent_hub_wp_ui_retired ? new DateTimeImmutable( '+30 days' ) : new DateTimeImmutable( '-1 day' ) ), $xnetvn_agent_hub_wp_ui_generated->hash_with( $xnetvn_agent_hub_wp_ui_pepper ), $xnetvn_agent_hub_wp_ui_pepper->id );
		if ( $xnetvn_agent_hub_wp_ui_retired ) {
			$xnetvn_agent_hub_wp_ui_repository->revoke( $xnetvn_agent_hub_wp_ui_row->id, $xnetvn_agent_hub_wp_ui_owner_id, 'ui_fixture_retired' );
		}
		$xnetvn_agent_hub_wp_ui_key_ids[] = $xnetvn_agent_hub_wp_ui_row->id;
		$xnetvn_agent_hub_wp_ui_request   = substr( bin2hex( random_bytes( 16 ) ), 0, 16 );
		$xnetvn_agent_hub_wp_ui_events[]  = array(
			'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			'request_id'  => $xnetvn_agent_hub_wp_ui_request,
			'key_id'      => $xnetvn_agent_hub_wp_ui_row->key_id->value,
			'user_id'     => $xnetvn_agent_hub_wp_ui_owner_id,
			'transport'   => 'http',
			'ability'     => 'system/ui-fixture',
			'outcome'     => $xnetvn_agent_hub_wp_ui_retired ? 'denied' : 'success',
			'duration_ms' => 5,
			'ip'          => '203.0.113.7',
			'ua_short'    => 'UI fixture',
			'arg_keys'    => array(),
		);
	}
	( new WpdbAuditRepository( $wpdb ) )->insert_batch( $xnetvn_agent_hub_wp_ui_events );
	$xnetvn_agent_hub_wp_ui_ledger             = xnetvn_agent_hub_wp_ui_ledger();
	$xnetvn_agent_hub_wp_ui_ledger['keys']     = $xnetvn_agent_hub_wp_ui_key_ids;
	$xnetvn_agent_hub_wp_ui_ledger['audit']    = array_map(
		static function ( array $xnetvn_agent_hub_wp_ui_event ): string {
			return $xnetvn_agent_hub_wp_ui_event['request_id'];
		},
		$xnetvn_agent_hub_wp_ui_events
	);
	$xnetvn_agent_hub_wp_ui_ledger['scenario'] = 'pagination-' . $xnetvn_agent_hub_wp_ui_count;
	$xnetvn_agent_hub_wp_ui_ledger['count']    = $xnetvn_agent_hub_wp_ui_count;
	xnetvn_agent_hub_wp_ui_save_ledger( $xnetvn_agent_hub_wp_ui_ledger );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo 'UI-FIX pagination-' . $xnetvn_agent_hub_wp_ui_count . ' users=6 keys=' . $xnetvn_agent_hub_wp_ui_count . ' audit=' . $xnetvn_agent_hub_wp_ui_count . "\n";
}

$xnetvn_agent_hub_wp_ui_scenario = $args[0] ?? '';
if ( ! in_array( $xnetvn_agent_hub_wp_ui_scenario, array( 'seed', 'empty', 'pagination', 'snapshot', 'restore', 'notice' ), true ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI fixture unknown scenario.' );
}
if ( 'pagination' === $xnetvn_agent_hub_wp_ui_scenario ) {
	$xnetvn_agent_hub_wp_ui_count_arg = $args[1] ?? '21';
	if ( '21' !== (string) $xnetvn_agent_hub_wp_ui_count_arg && '41' !== (string) $xnetvn_agent_hub_wp_ui_count_arg ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI fixture pagination count must be 21 or 41.' );
	}
	if ( count( $args ) > 2 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI fixture unexpected argument.' );
	}
	xnetvn_agent_hub_wp_ui_paginate( (int) $xnetvn_agent_hub_wp_ui_count_arg );
	return;
}
if ( count( $args ) > 1 ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'UI fixture unexpected argument.' );
}
if ( 'seed' === $xnetvn_agent_hub_wp_ui_scenario ) {
	$xnetvn_agent_hub_wp_ui_users = xnetvn_agent_hub_wp_ui_seed_users();
	xnetvn_agent_hub_wp_ui_ensure_sink();
	$xnetvn_agent_hub_wp_ui_owned = xnetvn_agent_hub_wp_ui_count_owned();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo 'UI-FIX seed users=' . count( $xnetvn_agent_hub_wp_ui_users ) . ' keys=' . $xnetvn_agent_hub_wp_ui_owned['keys'] . ' audit=' . $xnetvn_agent_hub_wp_ui_owned['audit'] . "\n";
	return;
}
if ( 'empty' === $xnetvn_agent_hub_wp_ui_scenario ) {
	xnetvn_agent_hub_wp_ui_seed_users();
	xnetvn_agent_hub_wp_ui_ensure_sink();
	xnetvn_agent_hub_wp_ui_delete_owned();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo "UI-FIX empty users=6 keys=0 audit=0\n";
	return;
}
if ( 'snapshot' === $xnetvn_agent_hub_wp_ui_scenario ) {
	$xnetvn_agent_hub_wp_ui_users  = xnetvn_agent_hub_wp_ui_seed_users();
	$xnetvn_agent_hub_wp_ui_notify = array();
	foreach ( $xnetvn_agent_hub_wp_ui_users as $xnetvn_agent_hub_wp_ui_user_id ) {
		$xnetvn_agent_hub_wp_ui_notify[ (int) $xnetvn_agent_hub_wp_ui_user_id ] = get_user_meta( (int) $xnetvn_agent_hub_wp_ui_user_id, 'xnetvn_agent_hub_wp_notifications', true );
	}
	$xnetvn_agent_hub_wp_ui_mu = array();
	foreach ( array( 'xnetvn-agent-hub-wp-ui-fixture-mail-sink.php', 'xnetvn-agent-hub-wp-ui-fixture-notice.php' ) as $xnetvn_agent_hub_wp_ui_file ) {
		$xnetvn_agent_hub_wp_ui_mu[ $xnetvn_agent_hub_wp_ui_file ] = is_file( trailingslashit( WPMU_PLUGIN_DIR ) . $xnetvn_agent_hub_wp_ui_file );
	}
	update_option(
		'xnetvn_ui_fixture_snapshot',
		array(
			'settings' => get_option( WpSiteSettings::OPTION, array() ),
			'notify'   => $xnetvn_agent_hub_wp_ui_notify,
			'ledger'   => xnetvn_agent_hub_wp_ui_ledger(),
			'mu'       => $xnetvn_agent_hub_wp_ui_mu,
		),
		false
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo 'UI-FIX snapshot users=' . count( $xnetvn_agent_hub_wp_ui_users ) . " ok=1\n";
	return;
}
if ( 'restore' === $xnetvn_agent_hub_wp_ui_scenario ) {
	$xnetvn_agent_hub_wp_ui_snapshot = get_option( 'xnetvn_ui_fixture_snapshot', null );
	if ( is_array( $xnetvn_agent_hub_wp_ui_snapshot ) ) {
		if ( isset( $xnetvn_agent_hub_wp_ui_snapshot['settings'] ) && is_array( $xnetvn_agent_hub_wp_ui_snapshot['settings'] ) ) {
			update_option( WpSiteSettings::OPTION, $xnetvn_agent_hub_wp_ui_snapshot['settings'], false );
		}
		if ( isset( $xnetvn_agent_hub_wp_ui_snapshot['notify'] ) && is_array( $xnetvn_agent_hub_wp_ui_snapshot['notify'] ) ) {
			foreach ( $xnetvn_agent_hub_wp_ui_snapshot['notify'] as $xnetvn_agent_hub_wp_ui_user_id => $xnetvn_agent_hub_wp_ui_value ) {
				update_user_meta( (int) $xnetvn_agent_hub_wp_ui_user_id, 'xnetvn_agent_hub_wp_notifications', $xnetvn_agent_hub_wp_ui_value );
			}
		}
		if ( isset( $xnetvn_agent_hub_wp_ui_snapshot['mu'] ) && is_array( $xnetvn_agent_hub_wp_ui_snapshot['mu'] ) ) {
			foreach ( $xnetvn_agent_hub_wp_ui_snapshot['mu'] as $xnetvn_agent_hub_wp_ui_file => $xnetvn_agent_hub_wp_ui_existed ) {
				if ( ! $xnetvn_agent_hub_wp_ui_existed && ( 'xnetvn-agent-hub-wp-ui-fixture-mail-sink.php' === $xnetvn_agent_hub_wp_ui_file || 'xnetvn-agent-hub-wp-ui-fixture-notice.php' === $xnetvn_agent_hub_wp_ui_file ) ) {
					$xnetvn_agent_hub_wp_ui_path = trailingslashit( WPMU_PLUGIN_DIR ) . $xnetvn_agent_hub_wp_ui_file;
					if ( is_file( $xnetvn_agent_hub_wp_ui_path ) ) {
						wp_delete_file( $xnetvn_agent_hub_wp_ui_path );
					}
				}
			}
		}
		delete_option( 'xnetvn_ui_fixture_snapshot' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
		echo "UI-FIX restore settings=1\n";
	} else {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
		echo "UI-FIX restore settings=0\n";
	}
	xnetvn_agent_hub_wp_ui_delete_owned();
	return;
}
$xnetvn_agent_hub_wp_ui_notice_path = trailingslashit( WPMU_PLUGIN_DIR ) . 'xnetvn-agent-hub-wp-ui-fixture-notice.php';
$xnetvn_agent_hub_wp_ui_notice_code = <<<'PHP'
<?php
/** Owned UI fixture notice for fixture actors on the lab site only. */
add_action(
	'admin_init',
	function () {
		if ( 'local' !== wp_get_environment_type() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || 0 !== strpos( $user->user_login, 'xnetvn_ui_' ) ) {
			return;
		}
		if ( class_exists( 'XNetVN\\AgentHubWP\\Bootstrap\\Notice' ) ) {
			\XNetVN\AgentHubWP\Bootstrap\Notice::register(
				array(
					array(
						'code'     => 'php_version',
						'required' => '99.0-fixture',
						'actual'   => '0.0-fixture',
					),
				)
			);
		}
	}
);
PHP;
wp_mkdir_p( WPMU_PLUGIN_DIR );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the owned lab notice fixture.
$xnetvn_agent_hub_wp_ui_notice_current = is_file( $xnetvn_agent_hub_wp_ui_notice_path ) ? file_get_contents( $xnetvn_agent_hub_wp_ui_notice_path ) : null;
if ( $xnetvn_agent_hub_wp_ui_notice_code !== $xnetvn_agent_hub_wp_ui_notice_current ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the owned lab notice fixture.
	if ( false === file_put_contents( $xnetvn_agent_hub_wp_ui_notice_path, $xnetvn_agent_hub_wp_ui_notice_code ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'UI fixture notice unavailable.' );
	}
}
$xnetvn_agent_hub_wp_ui_ledger = xnetvn_agent_hub_wp_ui_ledger();
$xnetvn_agent_hub_wp_ui_mu     = isset( $xnetvn_agent_hub_wp_ui_ledger['mu'] ) && is_array( $xnetvn_agent_hub_wp_ui_ledger['mu'] ) ? $xnetvn_agent_hub_wp_ui_ledger['mu'] : array();
$xnetvn_agent_hub_wp_ui_mu['xnetvn-agent-hub-wp-ui-fixture-notice.php'] = true;
$xnetvn_agent_hub_wp_ui_ledger['mu']                                    = $xnetvn_agent_hub_wp_ui_mu;
xnetvn_agent_hub_wp_ui_save_ledger( $xnetvn_agent_hub_wp_ui_ledger );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
echo "UI-FIX notice mu=1\n";
