<?php
/**
 * Disposable Content/Media HTTP fixture; extends the existing owned lifecycle.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Support\SystemClock;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 ); }
// phpcs:disable WordPress.WP.AlternativeFunctions -- Fixed root-only private test fixture state, never product persistence.
$xnetvn_agent_hub_wp_file = '/work/.local/http-matrix/state.json';
if ( ! is_file( $xnetvn_agent_hub_wp_file ) || is_link( $xnetvn_agent_hub_wp_file ) ) {
	throw new RuntimeException( 'Owned HTTP fixture required.' ); }
$xnetvn_agent_hub_wp_state  = json_decode( file_get_contents( $xnetvn_agent_hub_wp_file ), true, 64, JSON_THROW_ON_ERROR );
$xnetvn_agent_hub_wp_action = $args[0] ?? 'setup';
if ( 'enable-remote' === $xnetvn_agent_hub_wp_action ) {
	( new WpSiteSettings() )->update( array( 'media_remote_fetch_enabled' => true ) );
	return; }
if ( 'setup' !== $xnetvn_agent_hub_wp_action || isset( $xnetvn_agent_hub_wp_state['keys']['content_editor'] ) ) {
	throw new RuntimeException( 'Reconcile existing content fixture.' ); }
foreach ( array(
	'author'      => 'author',
	'contributor' => 'contributor',
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
		throw new RuntimeException( 'Content fixture user unavailable.' ); }
	$xnetvn_agent_hub_wp_state['users'][ $xnetvn_agent_hub_wp_kind ] = $xnetvn_agent_hub_wp_user;
	file_put_contents( $xnetvn_agent_hub_wp_file, json_encode( $xnetvn_agent_hub_wp_state, JSON_THROW_ON_ERROR ), LOCK_EX );
}
global $wpdb;
$xnetvn_agent_hub_wp_repository = new WpdbKeyRepository( $wpdb, new SystemClock() );
$xnetvn_agent_hub_wp_pepper     = ( new PepperStore() )->current();
foreach ( array(
	'content_editor'      => array( 'owner', 'destructive' ),
	'content_author'      => array( 'author', 'destructive' ),
	'content_contributor' => array( 'contributor', 'write' ),
	'content_read'        => array( 'owner', 'read' ),
) as $xnetvn_agent_hub_wp_kind => $xnetvn_agent_hub_wp_config ) {
	$xnetvn_agent_hub_wp_key                                        = ( new KeyGenerator() )->generate();
	$xnetvn_agent_hub_wp_row                                        = $xnetvn_agent_hub_wp_repository->insert( $xnetvn_agent_hub_wp_key->id, $xnetvn_agent_hub_wp_state['users'][ $xnetvn_agent_hub_wp_config[0] ], 'HTTP matrix ' . $xnetvn_agent_hub_wp_kind, new Scope( RiskTier::from( $xnetvn_agent_hub_wp_config[1] ), array( 'content', 'media' ), array(), array(), array(), new DateTimeImmutable( '+1 day' ) ), $xnetvn_agent_hub_wp_key->hash_with( $xnetvn_agent_hub_wp_pepper ), $xnetvn_agent_hub_wp_pepper->id );
	$xnetvn_agent_hub_wp_state['keys'][ $xnetvn_agent_hub_wp_kind ] = array(
		'id'    => $xnetvn_agent_hub_wp_row->id,
		'token' => $xnetvn_agent_hub_wp_key->reveal(),
	);
	file_put_contents( $xnetvn_agent_hub_wp_file, json_encode( $xnetvn_agent_hub_wp_state, JSON_THROW_ON_ERROR ), LOCK_EX );
}
chmod( $xnetvn_agent_hub_wp_file, 0600 );
