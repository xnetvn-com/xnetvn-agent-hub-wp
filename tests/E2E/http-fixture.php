<?php
/**
 * WP-CLI control for disposable HTTP evidence; credentials never reach stdout.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}

use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Support\SystemClock;
use XNetVN\AgentHubWP\Support\Json;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only test fixture files require definite POSIX permissions, not an interactive filesystem transport.

$xnetvn_agent_hub_wp_action = $args[0] ?? 'setup';
$xnetvn_agent_hub_wp_dir    = '/work/.local/http-matrix';
$xnetvn_agent_hub_wp_file   = $xnetvn_agent_hub_wp_dir . '/state.json';
if ( is_link( $xnetvn_agent_hub_wp_dir ) || is_link( $xnetvn_agent_hub_wp_file ) ) {
	throw new RuntimeException( 'HTTP fixture state ownership mismatch.' );
}
if ( 'setup' === $xnetvn_agent_hub_wp_action && file_exists( $xnetvn_agent_hub_wp_file ) ) {
	throw new RuntimeException( 'Reconcile the existing HTTP fixture before setup.' );
}
$xnetvn_agent_hub_wp_state = is_file( $xnetvn_agent_hub_wp_file ) ? Json::array( file_get_contents( $xnetvn_agent_hub_wp_file ) ) : array();
global $wpdb;
$xnetvn_agent_hub_wp_keys = new WpdbKeyRepository( $wpdb, new SystemClock() );


/**
 * Persist progress before the next mutation for interruption reconciliation.
 *
 * @param string              $file Private destination.
 * @param array<string,mixed> $state Ephemeral owned state.
 * @throws RuntimeException For unavailable fixture persistence.
 */
function xnetvn_agent_hub_wp_http_save( string $file, #[SensitiveParameter] array $state ): void {
	if ( false === file_put_contents( $file, Json::encode( $state ), LOCK_EX ) ) {
		throw new RuntimeException( 'HTTP fixture persistence failed.' );
	}
	chmod( $file, 0600 );
}

if ( 'setup' === $xnetvn_agent_hub_wp_action ) {
	if ( $xnetvn_agent_hub_wp_state || file_exists( ABSPATH . 'wp-content/mu-plugins/xnetvn-http-matrix.php' ) || is_link( ABSPATH . 'wp-content/mu-plugins/xnetvn-http-matrix.php' ) ) {
		throw new RuntimeException( 'Reconcile the existing HTTP fixture before setup.' );
	}
	wp_mkdir_p( $xnetvn_agent_hub_wp_dir );
	chmod( $xnetvn_agent_hub_wp_dir, 0700 );
	$xnetvn_agent_hub_wp_state = array(
		'control'  => bin2hex( random_bytes( 24 ) ),
		'settings' => ( new WpSiteSettings() )->all(),
		'users'    => array(),
		'keys'     => array(),
	);
	xnetvn_agent_hub_wp_http_save( $xnetvn_agent_hub_wp_file, $xnetvn_agent_hub_wp_state );
	foreach ( array(
		'owner'   => 'editor',
		'cookie'  => 'administrator',
		'deleted' => 'editor',
	) as $xnetvn_agent_hub_wp_kind => $xnetvn_agent_hub_wp_role ) {
		$xnetvn_agent_hub_wp_user = wp_insert_user(
			array(
				'user_login' => 'http-' . $xnetvn_agent_hub_wp_kind . '-' . substr( $xnetvn_agent_hub_wp_state['control'], 0, 12 ),
				'user_pass'  => wp_generate_password( 48, true, true ),
				'user_email' => $xnetvn_agent_hub_wp_kind . '-' . substr( $xnetvn_agent_hub_wp_state['control'], 0, 12 ) . '@example.test',
				'role'       => $xnetvn_agent_hub_wp_role,
			)
		);
		if ( is_wp_error( $xnetvn_agent_hub_wp_user ) ) {
			throw new RuntimeException( 'HTTP fixture user creation failed.' );
		}
		$xnetvn_agent_hub_wp_state['users'][ $xnetvn_agent_hub_wp_kind ] = $xnetvn_agent_hub_wp_user;
		xnetvn_agent_hub_wp_http_save( $xnetvn_agent_hub_wp_file, $xnetvn_agent_hub_wp_state );
	}
	$xnetvn_agent_hub_wp_pepper = ( new PepperStore() )->current();
	foreach ( array( 'read', 'system', 'write', 'admin', 'revoked', 'expired', 'ip', 'deleted' ) as $xnetvn_agent_hub_wp_kind ) {
		$xnetvn_agent_hub_wp_owner                                      = $xnetvn_agent_hub_wp_state['users'][ 'admin' === $xnetvn_agent_hub_wp_kind ? 'cookie' : ( 'deleted' === $xnetvn_agent_hub_wp_kind ? 'deleted' : 'owner' ) ];
		$xnetvn_agent_hub_wp_tier                                       = RiskTier::tryFrom( $xnetvn_agent_hub_wp_kind ) ?? RiskTier::Read;
		$xnetvn_agent_hub_wp_scope                                      = new Scope( $xnetvn_agent_hub_wp_tier, 'system' === $xnetvn_agent_hub_wp_kind ? array() : array( 'fixture' ), array(), array(), 'ip' === $xnetvn_agent_hub_wp_kind ? array( '198.51.100.0/24' ) : array(), new DateTimeImmutable( 'expired' === $xnetvn_agent_hub_wp_kind ? '-1 hour' : '+1 day' ) );
		$xnetvn_agent_hub_wp_key                                        = ( new KeyGenerator() )->generate();
		$xnetvn_agent_hub_wp_row                                        = $xnetvn_agent_hub_wp_keys->insert( $xnetvn_agent_hub_wp_key->id, $xnetvn_agent_hub_wp_owner, 'HTTP matrix ' . $xnetvn_agent_hub_wp_kind, $xnetvn_agent_hub_wp_scope, $xnetvn_agent_hub_wp_key->hash_with( $xnetvn_agent_hub_wp_pepper ), $xnetvn_agent_hub_wp_pepper->id );
		$xnetvn_agent_hub_wp_state['keys'][ $xnetvn_agent_hub_wp_kind ] = array(
			'id'    => $xnetvn_agent_hub_wp_row->id,
			'token' => $xnetvn_agent_hub_wp_key->reveal(),
		);
		xnetvn_agent_hub_wp_http_save( $xnetvn_agent_hub_wp_file, $xnetvn_agent_hub_wp_state );
		if ( 'revoked' === $xnetvn_agent_hub_wp_kind ) {
			$xnetvn_agent_hub_wp_keys->revoke( $xnetvn_agent_hub_wp_row->id, $xnetvn_agent_hub_wp_owner, 'http_fixture' );
		}
	}
	wp_set_current_user( $xnetvn_agent_hub_wp_state['users']['cookie'] );
	$xnetvn_agent_hub_wp_session         = WP_Session_Tokens::get_instance( get_current_user_id() )->create( time() + HOUR_IN_SECONDS );
	$xnetvn_agent_hub_wp_cookie          = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in', $xnetvn_agent_hub_wp_session );
	$_COOKIE[ LOGGED_IN_COOKIE ]         = $xnetvn_agent_hub_wp_cookie;
	$xnetvn_agent_hub_wp_state['cookie'] = LOGGED_IN_COOKIE . '=' . $xnetvn_agent_hub_wp_cookie;
	$xnetvn_agent_hub_wp_state['nonce']  = wp_create_nonce( 'wp_rest' );
	// Persist only inside the root-only toolbox directory; no raw token is returned to a caller.
	xnetvn_agent_hub_wp_http_save( $xnetvn_agent_hub_wp_file, $xnetvn_agent_hub_wp_state );
	( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => 1000 ) );
	update_option( 'xnetvn_agent_hub_wp_http_fixture', array( 'control' => $xnetvn_agent_hub_wp_state['control'] ), false );
	update_option( 'xnetvn_agent_hub_wp_http_execution_counts', array(), false );
	update_option( 'xnetvn_agent_hub_wp_http_client_denials', array(), false );
	if ( ! copy( '/work/tests/E2E/http-mu-plugin.php', ABSPATH . 'wp-content/mu-plugins/xnetvn-http-matrix.php' ) ) {
		throw new RuntimeException( 'HTTP fixture instrumentation could not be installed.' );
	}
} elseif ( 'cleanup' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	$xnetvn_agent_hub_wp_mu_file = ABSPATH . 'wp-content/mu-plugins/xnetvn-http-matrix.php';
	if ( is_link( $xnetvn_agent_hub_wp_mu_file ) ) {
		throw new RuntimeException( 'HTTP fixture instrumentation ownership mismatch.' );
	}
	if ( file_exists( $xnetvn_agent_hub_wp_mu_file ) ) {
		$xnetvn_agent_hub_wp_actual_digest   = hash_file( 'sha256', $xnetvn_agent_hub_wp_mu_file );
		$xnetvn_agent_hub_wp_expected_digest = hash_file( 'sha256', '/work/tests/E2E/http-mu-plugin.php' );
		if ( ! is_string( $xnetvn_agent_hub_wp_actual_digest ) || ! is_string( $xnetvn_agent_hub_wp_expected_digest ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $xnetvn_agent_hub_wp_actual_digest ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $xnetvn_agent_hub_wp_expected_digest ) || ! hash_equals( $xnetvn_agent_hub_wp_expected_digest, $xnetvn_agent_hub_wp_actual_digest ) ) {
			throw new RuntimeException( 'HTTP fixture instrumentation ownership mismatch.' );
		}
	}
	( new WpSiteSettings() )->update( $xnetvn_agent_hub_wp_state['settings'] );
	delete_option( 'xnetvn_agent_hub_wp_http_fixture' );
	delete_option( 'xnetvn_agent_hub_wp_http_execution_counts' );
	delete_option( 'xnetvn_agent_hub_wp_http_client_denials' );
	foreach ( $xnetvn_agent_hub_wp_state['keys'] as $xnetvn_agent_hub_wp_key ) {
		$xnetvn_agent_hub_wp_row = $xnetvn_agent_hub_wp_keys->find_by_id( $xnetvn_agent_hub_wp_key['id'] );
		if ( null !== $xnetvn_agent_hub_wp_row ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_row->user_id, $xnetvn_agent_hub_wp_state['users'], true ) || ! str_starts_with( $xnetvn_agent_hub_wp_row->name, 'HTTP matrix ' ) ) {
				throw new RuntimeException( 'HTTP fixture cleanup binding mismatch.' );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_row->id, $xnetvn_agent_hub_wp_row->key_id->value, $xnetvn_agent_hub_wp_row->user_id ) ) || null !== $xnetvn_agent_hub_wp_keys->find_by_id( $xnetvn_agent_hub_wp_row->id ) ) {
				throw new RuntimeException( 'HTTP fixture key cleanup failed.' );
			}
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $xnetvn_agent_hub_wp_state['users'] as $xnetvn_agent_hub_wp_kind => $xnetvn_agent_hub_wp_user ) {
		$xnetvn_agent_hub_wp_profile = get_userdata( $xnetvn_agent_hub_wp_user );
		if ( false !== $xnetvn_agent_hub_wp_profile && 'http-' . $xnetvn_agent_hub_wp_kind . '-' . substr( $xnetvn_agent_hub_wp_state['control'], 0, 12 ) !== $xnetvn_agent_hub_wp_profile->user_login ) {
			throw new RuntimeException( 'HTTP fixture user cleanup binding mismatch.' );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_user ) ) ) {
			throw new RuntimeException( 'HTTP fixture audit cleanup failed.' );
		}
		if ( false !== $xnetvn_agent_hub_wp_profile && ! wp_delete_user( $xnetvn_agent_hub_wp_user ) ) {
			throw new RuntimeException( 'HTTP fixture user cleanup failed.' );
		}
	}
	wp_delete_file( $xnetvn_agent_hub_wp_mu_file );
	if ( file_exists( $xnetvn_agent_hub_wp_mu_file ) || is_link( $xnetvn_agent_hub_wp_mu_file ) || ! unlink( $xnetvn_agent_hub_wp_file ) ) {
		throw new RuntimeException( 'HTTP fixture file cleanup failed.' );
	}
} elseif ( 'reset-rate' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'xnetvn_agent_hub_wp_rl_' ) . '%' ) );
	if ( wp_using_ext_object_cache() ) {
		// This provider returns false for an empty group. An owned sentinel proves a real deletion;
		// supports() prevents WordPress's compatibility fallback from flushing unrelated groups.
		if ( ! wp_cache_supports( 'flush_group' ) || ! wp_cache_set( 'fixture-reset-probe', 1, 'xnetvn_agent_hub_wp_rate', 60 ) || ! wp_cache_flush_group( 'xnetvn_agent_hub_wp_rate' ) ) {
			throw new RuntimeException( 'Scoped fixture rate reset is unavailable.' );
		}
		wp_cache_get( 'fixture-reset-probe', 'xnetvn_agent_hub_wp_rate', true, $xnetvn_agent_hub_wp_found );
		if ( $xnetvn_agent_hub_wp_found ) {
			throw new RuntimeException( 'Scoped fixture rate reset did not remove its sentinel.' );
		}
	}
} elseif ( in_array( $xnetvn_agent_hub_wp_action, array( 'demote', 'restore-role' ), true ) && $xnetvn_agent_hub_wp_state ) {
	( new WP_User( $xnetvn_agent_hub_wp_state['users']['owner'] ) )->set_role( 'demote' === $xnetvn_agent_hub_wp_action ? 'subscriber' : 'editor' );
} elseif ( 'delete-owner' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $xnetvn_agent_hub_wp_state['users']['deleted'] );
} elseif ( 'admin-disabled' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	( new WpSiteSettings() )->update(
		array(
			'tier_ceiling'     => 'admin',
			'allow_admin_tier' => false,
		)
	);
} elseif ( 'key-rate' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => 1 ) );
} elseif ( 'restore-rate' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => 1000 ) );
} elseif ( 'revoke-read' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	$xnetvn_agent_hub_wp_read_row = $xnetvn_agent_hub_wp_keys->find_by_id( $xnetvn_agent_hub_wp_state['keys']['read']['id'] );
	if ( null === $xnetvn_agent_hub_wp_read_row || $xnetvn_agent_hub_wp_read_row->user_id !== $xnetvn_agent_hub_wp_state['users']['owner'] || 'HTTP matrix read' !== $xnetvn_agent_hub_wp_read_row->name ) {
		throw new RuntimeException( 'Client revocation fixture binding mismatch.' );
	}
	if ( ! $xnetvn_agent_hub_wp_keys->revoke( $xnetvn_agent_hub_wp_read_row->id, $xnetvn_agent_hub_wp_read_row->user_id, 'client_fixture' ) ) {
		throw new RuntimeException( 'Client revocation failed.' );
	}
} elseif ( 'client-denials' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	WP_CLI::line( Json::encode( get_option( 'xnetvn_agent_hub_wp_http_client_denials', array() ) ) );
} elseif ( 'refresh-cookie' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	wp_set_current_user( $xnetvn_agent_hub_wp_state['users']['cookie'] );
	$xnetvn_agent_hub_wp_session         = WP_Session_Tokens::get_instance( get_current_user_id() )->create( time() + HOUR_IN_SECONDS );
	$xnetvn_agent_hub_wp_cookie          = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in', $xnetvn_agent_hub_wp_session );
	$_COOKIE[ LOGGED_IN_COOKIE ]         = $xnetvn_agent_hub_wp_cookie;
	$xnetvn_agent_hub_wp_state['cookie'] = LOGGED_IN_COOKIE . '=' . $xnetvn_agent_hub_wp_cookie;
	$xnetvn_agent_hub_wp_state['nonce']  = wp_create_nonce( 'wp_rest' );
	xnetvn_agent_hub_wp_http_save( $xnetvn_agent_hub_wp_file, $xnetvn_agent_hub_wp_state );
} elseif ( 'status' === $xnetvn_agent_hub_wp_action && $xnetvn_agent_hub_wp_state ) {
	$xnetvn_agent_hub_wp_forbidden = false;
	foreach ( ( new ReflectionClass( \XNetVN\AgentHubWP\Policy\HardDeny::class ) )->getConstant( 'FORBIDDEN' ) as $xnetvn_agent_hub_wp_name ) {
		$xnetvn_agent_hub_wp_forbidden = wp_has_ability( 'xnetvn/' . $xnetvn_agent_hub_wp_name ) || $xnetvn_agent_hub_wp_forbidden;
	}
	$xnetvn_agent_hub_wp_canary = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE arg_keys LIKE %s OR ua_short LIKE %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', '%HTTP-MATRIX-CANARY%', '%HTTP-MATRIX-CANARY%' ) );
	WP_CLI::line(
		Json::encode(
			array(
				'canary_found'         => (int) $xnetvn_agent_hub_wp_canary > 0,
				'executions'           => get_option( 'xnetvn_agent_hub_wp_http_execution_counts', array() ),
				'forbidden_registered' => $xnetvn_agent_hub_wp_forbidden,
			)
		)
	);
} else {
	throw new RuntimeException( 'Unknown HTTP fixture action.' );
}
