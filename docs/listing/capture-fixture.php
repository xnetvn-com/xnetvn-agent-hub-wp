<?php
/**
 * Owned demo data for the WordPress.org listing screenshots.
 *
 * Usage: wp eval-file docs/listing/capture-fixture.php <seed|purge> --use-include
 *
 * The script is lab-only and stores no plaintext key: it inserts hashed keys,
 * activity rows and two marked users. Only rows recorded in the owned ledger
 * are deleted, so a rerun never touches other fixtures or real content.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Support\SystemClock;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'The listing fixture requires WP_CLI.' );
}
if ( '1' !== getenv( 'XNETVN_RTL' ) || 'local' !== wp_get_environment_type() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'The listing fixture requires the lab environment.' );
}

const XNETVN_LISTING_LEDGER = 'xnetvn_listing_fixture_ledger';

/**
 * Read the owned ledger.
 *
 * @return array<string,mixed>
 */
function xnetvn_listing_ledger(): array {
	$value = get_option( XNETVN_LISTING_LEDGER, array() );
	return is_array( $value ) ? $value : array();
}

/**
 * Persist the owned ledger without autoload.
 *
 * @param array<string,mixed> $ledger Ledger.
 */
function xnetvn_listing_save_ledger( array $ledger ): void {
	update_option( XNETVN_LISTING_LEDGER, $ledger, false );
}

/**
 * Delete every row this fixture owns.
 */
function xnetvn_listing_purge(): void {
	global $wpdb;
	$ledger = xnetvn_listing_ledger();
	$keys   = isset( $ledger['keys'] ) && is_array( $ledger['keys'] ) ? array_map( 'intval', $ledger['keys'] ) : array();
	$audit  = isset( $ledger['audit'] ) && is_array( $ledger['audit'] ) ? array_map( 'strval', $ledger['audit'] ) : array();
	$users  = isset( $ledger['users'] ) && is_array( $ledger['users'] ) ? array_map( 'intval', $ledger['users'] ) : array();
	foreach ( $keys as $id ) {
		$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', array( 'id' => $id ), array( '%d' ) );
	}
	foreach ( $audit as $request_id ) {
		$wpdb->delete( $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', array( 'request_id' => $request_id ), array( '%s' ) );
	}
	// Also find marked users directly, so a lost or partially written ledger
	// cannot leave sealed fixture accounts behind.
	$marked = get_users(
		array(
			'fields'   => 'ID',
			'number'   => 100,
			'meta_key' => 'xnetvn_listing_fixture',
			'blog_id'  => 0,
		)
	); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI fixture cleans bounded plugin-owned users.
	$users = array_values( array_unique( array_merge( $users, array_map( 'intval', $marked ) ) ) );
	$removed = 0;
	if ( $users ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $users as $user_id ) {
			if ( $user_id < 2 ) {
				continue;
			}
			$user = get_userdata( $user_id );
			if ( false !== $user && '1' === (string) get_user_meta( $user_id, 'xnetvn_listing_fixture', true ) && wp_delete_user( $user_id ) ) {
				++$removed;
			}
		}
	}
	if ( isset( $ledger['blogname'] ) && is_string( $ledger['blogname'] ) && '' !== $ledger['blogname'] ) {
		update_option( 'blogname', $ledger['blogname'] );
	}
	delete_option( XNETVN_LISTING_LEDGER );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
	echo "LISTING-FIX purge keys=" . count( $keys ) . ' audit=' . count( $audit ) . ' users=' . $removed . "\n";
}

/**
 * Create one marked demo user and return its ID.
 *
 * @param string $login Login.
 * @param string $role  Role.
 * @param string $name  Display name.
 * @return int
 */
function xnetvn_listing_user( string $login, string $role, string $name ): int {
	$user = get_user_by( 'login', $login );
	if ( false === $user ) {
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 40, true, true ),
				'user_email'   => $login . '@example.test',
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
			throw new RuntimeException( 'Listing fixture user unavailable.' );
		}
		update_user_meta( (int) $id, 'xnetvn_listing_fixture', '1' );
		return (int) $id;
	}
	if ( '1' !== (string) get_user_meta( $user->ID, 'xnetvn_listing_fixture', true ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
		throw new RuntimeException( 'Listing fixture login exists without its marker.' );
	}
	$user->set_role( $role );
	wp_update_user(
		array(
			'ID'           => $user->ID,
			'display_name' => $name,
			'first_name'   => $name,
		)
	);
	return (int) $user->ID;
}

xnetvn_listing_purge();
$scenario = isset( $args[0] ) ? (string) $args[0] : '';
if ( 'purge' === $scenario ) {
	return;
}
if ( 'seed' !== $scenario ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'Usage: capture-fixture.php <seed|purge>.' );
}

global $wpdb;
$admin = get_userdata( 1 );
if ( false === $admin || ! in_array( 'administrator', (array) $admin->roles, true ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only message.
	throw new RuntimeException( 'The lab administrator account is unavailable.' );
}
$owner_id  = (int) $admin->ID;
$editor_id = xnetvn_listing_user( 'xnetvn_demo_editor', 'editor', 'Content Editor' );
$clock     = new SystemClock();
$keys      = new WpdbKeyRepository( $wpdb, $clock );
$pepper    = ( new PepperStore() )->current();
$generator = new KeyGenerator();

$definitions = array(
	array( 'Content editor', $editor_id, RiskTier::Write, array( 'system', 'content', 'taxonomy' ), '+30 days' ),
	array( 'Weekly export', $owner_id, RiskTier::Read, array( 'system', 'content' ), '+90 days' ),
	array( 'Media import', $editor_id, RiskTier::Write, array( 'system', 'media' ), '+7 days' ),
	array( 'Legacy reports', $owner_id, RiskTier::Read, array( 'system', 'content' ), '+30 days' ),
	array( 'Publishing bot', $editor_id, RiskTier::Write, array( 'system', 'content', 'comments' ), '-1 day' ),
);

$ledger = array( 'blogname' => (string) get_option( 'blogname' ), 'keys' => array(), 'audit' => array(), 'users' => array( $editor_id ) );
update_option( 'blogname', 'Agent Hub Demo' );
// Persist the owned ledger before the first mutation so an interrupted seed can
// still be purged.
xnetvn_listing_save_ledger( $ledger );
$stored = array();
foreach ( $definitions as $index => $definition ) {
	list( $name, $user_id, $tier, $toolsets, $expiry ) = $definition;
	$generated = $generator->generate();
	$key       = $keys->insert(
		$generated->id,
		$user_id,
		$name,
		new Scope( $tier, $toolsets, array(), array(), array(), new DateTimeImmutable( $expiry ) ),
		$generated->hash_with( $pepper ),
		$pepper->id,
		'203.0.113.10'
	);
	$ledger['keys'][] = $key->id;
	xnetvn_listing_save_ledger( $ledger );
	$stored[ $index ] = $key->key_id->value;
}

$keys->revoke( $ledger['keys'][3], $owner_id, 'listing_fixture_rotated' );
$keys->touch_last_used( $ledger['keys'][0], '203.0.113.21', 'MCP client' );
$keys->touch_last_used( $ledger['keys'][1], '203.0.113.22', 'MCP client' );
$keys->touch_last_used( $ledger['keys'][2], '203.0.113.23', 'MCP client' );

$rows = array(
	array( 3, 0, 0, 'xnetvn/get-session-info', 'success', '', array() ),
	array( 6, 0, 0, 'xnetvn/list-posts', 'success', '', array( 'status', 'fields' ) ),
	array( 9, 0, 0, 'xnetvn/get-post', 'success', '', array( 'id', 'fields' ) ),
	array( 12, 0, 0, 'xnetvn/update-post', 'success', '', array( 'id', 'title', 'status' ) ),
	array( 15, 0, 0, 'xnetvn/set-featured-media', 'success', '', array( 'id', 'media_id' ) ),
	array( 18, 0, 2, 'xnetvn/upload-media-from-data', 'success', '', array( 'filename', 'mime_type' ) ),
	array( 22, 0, 0, 'xnetvn/create-post', 'success', '', array( 'title', 'content', 'status' ) ),
	array( 26, 0, 0, 'xnetvn/assign-post-terms', 'success', '', array( 'id', 'taxonomy', 'terms' ) ),
	array( 30, 0, 0, 'xnetvn/moderate-comment', 'denied', 'forbidden', array( 'id', 'status' ) ),
	array( 34, 0, 0, 'xnetvn/delete-comment', 'denied', 'forbidden', array( 'id' ) ),
	array( 38, 0, 0, 'xnetvn/create-user', 'denied', 'forbidden', array( 'username', 'email', 'role' ) ),
	array( 42, 0, 2, 'xnetvn/list-media', 'success', '', array( 'fields' ) ),
	array( 47, 1, 1, 'xnetvn/get-post', 'success', '', array( 'id' ) ),
	array( 52, 1, 1, 'xnetvn/search-content', 'success', '', array( 'query', 'fields' ) ),
	array( 58, 1, 1, 'xnetvn/update-post', 'denied', 'forbidden', array( 'id', 'status' ) ),
	array( 63, 3, 3, 'xnetvn/get-post', 'auth_failed', 'revoked', array( 'id' ) ),
	array( 70, 0, 0, 'xnetvn/get-post', 'success', '', array( 'id', 'fields' ) ),
	array( 85, 0, 0, 'xnetvn/discover-abilities', 'success', '', array( 'toolset', 'tier' ) ),
);

$events = array();
foreach ( $rows as $row ) {
	list( $minutes, $owner, $key_index, $ability, $outcome, $error, $arguments ) = $row;
	$request_id       = substr( bin2hex( random_bytes( 16 ) ), 0, 16 );
	$ledger['audit'][] = $request_id;
	$events[]          = array(
		'occurred_at' => gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) ),
		'request_id'  => $request_id,
		'key_id'      => $stored[ $key_index ],
		'user_id'     => 0 === $owner ? $editor_id : $owner_id,
		'transport'   => 'http',
		'mcp_method'  => 'tools/call',
		'ability'     => $ability,
		'outcome'     => $outcome,
		'error_code'  => $error,
		'duration_ms' => 5 + $minutes % 17,
		'ip'          => '203.0.113.' . ( 20 + $owner ),
		'ua_short'    => 'MCP client',
		'arg_keys'    => $arguments,
	);
}

// Record every audit request id before the batch insert, so an interruption
// still leaves a complete ledger for purge.
xnetvn_listing_save_ledger( $ledger );
( new WpdbAuditRepository( $wpdb ) )->insert_batch( $events );
xnetvn_listing_save_ledger( $ledger );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only success marker.
echo 'LISTING-FIX seed users=2 keys=' . count( $ledger['keys'] ) . ' audit=' . count( $ledger['audit'] ) . "\n";
