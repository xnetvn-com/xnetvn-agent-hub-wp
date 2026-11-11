<?php
/**
 * Owned multisite fixture for role/SEO MCP isolation on the network lab.
 *
 * Usage (toolbox of the network stack only):
 * env WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path=/sites/wpnetwork --url=<blog-url> eval-file /work/tests/E2E/role-seo-network-fixture.php <scenario> [args] --use-include
 *
 * Scenarios: seed, verify, snapshot, restore, reconcile, counts, cleanup,
 * content-digest, role-blog, ban-verify, ban-reset, collision-pick,
 * collision-make, quota-set, settings-variant, net-read, super.
 * Per-blog scenarios act on the current blog from --url; collision-pick and
 * collision-make switch to both blogs internally with try/finally restore.
 *
 * This fixture only owns test users with the role-seo-net- login prefix and
 * marker meta, per-site roles, per-site keys whose names carry the
 * Role SEO net marker, per-blog settings snapshots and ledger-listed posts,
 * terms and media. Cleanup deletes only ledger-listed ids after rechecking
 * the marker, blog ownership and author binding; no bulk or prefix deletes.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}

use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Support\SystemClock;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only test fixture files require definite POSIX permissions, not an interactive filesystem transport.

$xnetvn_agent_hub_wp_net_action = $args[0] ?? 'seed';
if ( ! in_array( $xnetvn_agent_hub_wp_net_action, array( 'seed', 'verify', 'snapshot', 'restore', 'reconcile', 'counts', 'cleanup', 'content-digest', 'role-blog', 'ban-verify', 'ban-reset', 'collision-pick', 'collision-make', 'quota-set', 'quota-restore', 'settings-variant', 'net-read', 'super', 'blogname-set' ), true ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_net_run = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
if ( ! is_string( $xnetvn_agent_hub_wp_net_run ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $xnetvn_agent_hub_wp_net_run ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_net_allowed_hosts = array();
foreach ( array( getenv( 'XNETVN_RTL_DOMAIN' ), getenv( 'XNETVN_RTL_ALIAS' ), getenv( 'RTL_DOMAIN_WPNETWORK' ), getenv( 'RTL_ALIAS_WPNETWORK' ) ) as $xnetvn_agent_hub_wp_net_host ) {
	if ( is_string( $xnetvn_agent_hub_wp_net_host ) && '' !== $xnetvn_agent_hub_wp_net_host ) {
		$xnetvn_agent_hub_wp_net_allowed_hosts[] = $xnetvn_agent_hub_wp_net_host;
	}
}
$xnetvn_agent_hub_wp_net_home = parse_url( home_url(), PHP_URL_HOST );
if ( ! is_string( $xnetvn_agent_hub_wp_net_home ) || ! in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_allowed_hosts, true ) ) {
	exit( 2 );
}
if ( ! is_multisite() ) {
	exit( 2 );
}
global $wpdb;
if ( 'ui_network_' !== $wpdb->base_prefix ) {
	exit( 2 );
}

$xnetvn_agent_hub_wp_net_aliases       = array(
	'admin'       => 'administrator',
	'editor'      => 'editor',
	'author'      => 'author',
	'author_b'    => 'author',
	'contributor' => 'contributor',
	'subscriber'  => 'subscriber',
	'banned'      => 'banned',
);
$xnetvn_agent_hub_wp_net_key_kinds     = array(
	'admin_full'       => array( 'admin', 'admin', array( 'system', 'content', 'media', 'taxonomy' ), array(), array() ),
	'admin_users'      => array( 'admin', 'admin', array( 'system', 'users' ), array(), array() ),
	'editor_all'       => array( 'editor', 'destructive', array( 'system', 'content', 'media', 'taxonomy' ), array(), array() ),
	'author_write'     => array( 'author', 'write', array( 'system', 'content', 'media' ), array(), array( 'xnetvn/delete-post' ) ),
	'contributor_read' => array( 'contributor', 'read', array( 'system' ), array(), array() ),
	'sub_read'         => array( 'subscriber', 'read', array( 'system' ), array(), array() ),
	'banned_read'      => array( 'banned', 'read', array( 'system' ), array(), array() ),
	'super_read'       => array( 'super', 'read', array( 'system' ), array(), array() ),
	'super_write'      => array( 'super', 'write', array( 'system', 'content' ), array(), array() ),
);
$xnetvn_agent_hub_wp_net_marker_option = 'xnetvn_agent_hub_wp_role_seo_network';
$xnetvn_agent_hub_wp_net_meta_key      = 'xnetvn_role_seo_network_fixture';
$xnetvn_agent_hub_wp_net_private_dir   = '/work/.local/role-seo-network/private/' . $xnetvn_agent_hub_wp_net_run;
$xnetvn_agent_hub_wp_net_state_file    = $xnetvn_agent_hub_wp_net_private_dir . '/state.json';

if ( is_link( $xnetvn_agent_hub_wp_net_private_dir ) || is_link( $xnetvn_agent_hub_wp_net_state_file ) ) {
	exit( 2 );
}

/**
 * Canonicalize a value for stable digests.
 *
 * @param mixed $xnetvn_agent_hub_wp_net_value Value.
 * @return mixed
 */
function xnetvn_agent_hub_wp_net_c14n( mixed $xnetvn_agent_hub_wp_net_value ): mixed {
	if ( ! is_array( $xnetvn_agent_hub_wp_net_value ) ) {
		return $xnetvn_agent_hub_wp_net_value;
	}
	if ( array_is_list( $xnetvn_agent_hub_wp_net_value ) ) {
		return array_map( 'xnetvn_agent_hub_wp_net_c14n', $xnetvn_agent_hub_wp_net_value );
	}
	ksort( $xnetvn_agent_hub_wp_net_value );
	foreach ( $xnetvn_agent_hub_wp_net_value as $xnetvn_agent_hub_wp_net_k => $xnetvn_agent_hub_wp_net_v ) {
		$xnetvn_agent_hub_wp_net_value[ $xnetvn_agent_hub_wp_net_k ] = xnetvn_agent_hub_wp_net_c14n( $xnetvn_agent_hub_wp_net_v );
	}
	return $xnetvn_agent_hub_wp_net_value;
}

/**
 * Digest a state fragment without secrets.
 *
 * @param mixed $xnetvn_agent_hub_wp_net_value Value.
 * @return string
 */
function xnetvn_agent_hub_wp_net_digest( mixed $xnetvn_agent_hub_wp_net_value ): string {
	return hash( 'sha256', Json::encode( xnetvn_agent_hub_wp_net_c14n( $xnetvn_agent_hub_wp_net_value ) ) );
}

/**
 * Persist progress for interruption recovery.
 *
 * @param string              $xnetvn_agent_hub_wp_net_file Private destination.
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_state Ephemeral owned state.
 * @throws RuntimeException For unavailable fixture persistence.
 */
function xnetvn_agent_hub_wp_net_save( string $xnetvn_agent_hub_wp_net_file, #[SensitiveParameter] array $xnetvn_agent_hub_wp_net_state ): void {
	if ( is_link( $xnetvn_agent_hub_wp_net_file ) ) {
		throw new RuntimeException( 'Role SEO network fixture state ownership mismatch.' );
	}
	if ( false === file_put_contents( $xnetvn_agent_hub_wp_net_file, Json::encode( $xnetvn_agent_hub_wp_net_state ), LOCK_EX ) ) {
		throw new RuntimeException( 'Role SEO network fixture persistence failed.' );
	}
	chmod( $xnetvn_agent_hub_wp_net_file, 0600 );
}

/**
 * Print one safe report line.
 *
 * @param array<string,mixed> $xnetvn_agent_hub_wp_net_report Safe report.
 */
function xnetvn_agent_hub_wp_net_report( array $xnetvn_agent_hub_wp_net_report ): void {
	WP_CLI::line( Json::encode( $xnetvn_agent_hub_wp_net_report ) );
}

/**
 * Resolve the owned child site id by its subdirectory path.
 *
 * @return int Child blog id.
 * @throws RuntimeException When the child site is unavailable.
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
		throw new RuntimeException( 'Role SEO network child site /ui-second/ unavailable.' );
	}
	return (int) $xnetvn_agent_hub_wp_net_sites[0];
}

/**
 * Run a callback on one blog and always restore the original site.
 *
 * @param int      $xnetvn_agent_hub_wp_net_blog Blog id.
 * @param callable $xnetvn_agent_hub_wp_net_run Callback receiving the blog id.
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
 * Snapshot the restorable per-blog settings surface.
 *
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_net_snapshot_blog(): array {
	$xnetvn_agent_hub_wp_net_settings = new WpSiteSettings();
	return array(
		'settings'        => $xnetvn_agent_hub_wp_net_settings->all(),
		'settings_digest' => xnetvn_agent_hub_wp_net_digest( $xnetvn_agent_hub_wp_net_settings->all() ),
		'cron'            => get_option( 'cron', array() ),
		'cron_digest'     => xnetvn_agent_hub_wp_net_digest( get_option( 'cron', array() ) ),
		'upload_space'    => get_option( 'blog_upload_space' ),
		'blogname'        => get_option( 'blogname' ),
	);
}

/**
 * Count fixture-relevant rows on the current blog.
 *
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_net_counts(): array {
	global $wpdb;
	$xnetvn_agent_hub_wp_net_keys  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' );
	$xnetvn_agent_hub_wp_net_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post','attachment')" );
	$xnetvn_agent_hub_wp_net_terms = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy );
	$xnetvn_agent_hub_wp_net_media = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'" );
	return array(
		'keys'  => $xnetvn_agent_hub_wp_net_keys,
		'posts' => $xnetvn_agent_hub_wp_net_posts,
		'terms' => $xnetvn_agent_hub_wp_net_terms,
		'media' => $xnetvn_agent_hub_wp_net_media,
	);
}

$xnetvn_agent_hub_wp_net_keys_repo = new WpdbKeyRepository( $wpdb, new SystemClock() );
$xnetvn_agent_hub_wp_net_state     = is_file( $xnetvn_agent_hub_wp_net_state_file ) ? Json::array( file_get_contents( $xnetvn_agent_hub_wp_net_state_file ) ) : array();

try {
	$xnetvn_agent_hub_wp_net_child        = xnetvn_agent_hub_wp_net_child_id();
	$xnetvn_agent_hub_wp_net_blogs        = array(
		'main'  => 1,
		'child' => $xnetvn_agent_hub_wp_net_child,
	);
	$xnetvn_agent_hub_wp_net_current_blog = get_current_blog_id();
	$xnetvn_agent_hub_wp_net_current_key  = array_search( $xnetvn_agent_hub_wp_net_current_blog, $xnetvn_agent_hub_wp_net_blogs, true );
	if ( ! is_string( $xnetvn_agent_hub_wp_net_current_key ) ) {
		throw new RuntimeException( 'Role SEO network fixture requires a test blog URL.' );
	}

	if ( 'counts' === $xnetvn_agent_hub_wp_net_action ) {
		$xnetvn_agent_hub_wp_net_settings = new WpSiteSettings();
		xnetvn_agent_hub_wp_net_report(
			array_merge(
				array(
					'counts'          => true,
					'settings_digest' => xnetvn_agent_hub_wp_net_digest( $xnetvn_agent_hub_wp_net_settings->all() ),
				),
				xnetvn_agent_hub_wp_net_counts()
			)
		);
		return;
	}

	if ( 'reconcile' === $xnetvn_agent_hub_wp_net_action ) {
		$xnetvn_agent_hub_wp_net_remaining = array(
			'users' => 0,
			'keys'  => 0,
			'posts' => 0,
			'terms' => 0,
			'media' => 0,
		);
		if ( $xnetvn_agent_hub_wp_net_state ) {
			foreach ( $xnetvn_agent_hub_wp_net_state['ledger'] ?? array() as $xnetvn_agent_hub_wp_net_entry ) {
				$xnetvn_agent_hub_wp_net_type = $xnetvn_agent_hub_wp_net_entry['type'] ?? '';
				$xnetvn_agent_hub_wp_net_id   = (int) ( $xnetvn_agent_hub_wp_net_entry['id'] ?? 0 );
				$xnetvn_agent_hub_wp_net_blog = (int) ( $xnetvn_agent_hub_wp_net_entry['blog'] ?? 0 );
				xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function () use ( $xnetvn_agent_hub_wp_net_type, $xnetvn_agent_hub_wp_net_id, &$xnetvn_agent_hub_wp_net_remaining, $xnetvn_agent_hub_wp_net_keys_repo ): void {
						if ( 'user' === $xnetvn_agent_hub_wp_net_type && get_userdata( $xnetvn_agent_hub_wp_net_id ) instanceof WP_User ) {
							++$xnetvn_agent_hub_wp_net_remaining['users'];
						} elseif ( 'key' === $xnetvn_agent_hub_wp_net_type && null !== $xnetvn_agent_hub_wp_net_keys_repo->find_by_id( $xnetvn_agent_hub_wp_net_id ) ) {
							++$xnetvn_agent_hub_wp_net_remaining['keys'];
						} elseif ( ( 'post' === $xnetvn_agent_hub_wp_net_type || 'media' === $xnetvn_agent_hub_wp_net_type ) && null !== get_post( $xnetvn_agent_hub_wp_net_id ) ) {
							++$xnetvn_agent_hub_wp_net_remaining[ 'media' === $xnetvn_agent_hub_wp_net_type ? 'media' : 'posts' ];
						} elseif ( 'term' === $xnetvn_agent_hub_wp_net_type && null !== get_term( $xnetvn_agent_hub_wp_net_id ) ) {
							++$xnetvn_agent_hub_wp_net_remaining['terms'];
						}
					}
				);
			}
		}
		$xnetvn_agent_hub_wp_net_clean = ( 0 === array_sum( $xnetvn_agent_hub_wp_net_remaining ) );
		xnetvn_agent_hub_wp_net_report(
			array(
				'reconciled' => $xnetvn_agent_hub_wp_net_clean,
				'remaining'  => $xnetvn_agent_hub_wp_net_remaining,
			)
		);
		if ( ! $xnetvn_agent_hub_wp_net_clean ) {
			exit( 2 );
		}
		return;
	}

	if ( 'seed' === $xnetvn_agent_hub_wp_net_action ) {
		if ( $xnetvn_agent_hub_wp_net_state ) {
			exit( 2 );
		}
		foreach ( $xnetvn_agent_hub_wp_net_aliases as $xnetvn_agent_hub_wp_net_alias => $xnetvn_agent_hub_wp_net_role ) {
			if ( get_user_by( 'login', 'role-seo-net-' . $xnetvn_agent_hub_wp_net_run . '-' . $xnetvn_agent_hub_wp_net_alias ) instanceof WP_User ) {
				exit( 2 );
			}
		}
		if ( get_user_by( 'login', 'role-seo-net-' . $xnetvn_agent_hub_wp_net_run . '-super' ) instanceof WP_User ) {
			exit( 2 );
		}
		foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
			$xnetvn_agent_hub_wp_net_marker = xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blog,
				static function (): mixed {
					return get_option( 'xnetvn_agent_hub_wp_role_seo_network', false );
				}
			);
			if ( false !== $xnetvn_agent_hub_wp_net_marker ) {
				exit( 2 );
			}
		}
		wp_mkdir_p( $xnetvn_agent_hub_wp_net_private_dir );
		chmod( $xnetvn_agent_hub_wp_net_private_dir, 0700 );
		$xnetvn_agent_hub_wp_net_control = bin2hex( random_bytes( 24 ) );
		$xnetvn_agent_hub_wp_net_short   = substr( $xnetvn_agent_hub_wp_net_control, 0, 8 );
		$xnetvn_agent_hub_wp_net_state   = array(
			'suite'    => 'role-seo-network',
			'target'   => 'network',
			'run_id'   => $xnetvn_agent_hub_wp_net_run,
			'control'  => $xnetvn_agent_hub_wp_net_control,
			'blogs'    => $xnetvn_agent_hub_wp_net_blogs,
			'snapshot' => array(),
			'users'    => array(),
			'roles'    => array(),
			'keys'     => array(),
			'tokens'   => array(),
			'content'  => array(
				'ids' => array(),
			),
			'ledger'   => array(),
			'restored' => false,
		);
		xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		$xnetvn_agent_hub_wp_net_banned_role = RoleGuard::ensure_banned_role();
		if ( 'banned' !== $xnetvn_agent_hub_wp_net_banned_role ) {
			throw new RuntimeException( 'Role SEO network banned role unavailable.' );
		}
		foreach ( $xnetvn_agent_hub_wp_net_aliases as $xnetvn_agent_hub_wp_net_alias => $xnetvn_agent_hub_wp_net_role ) {
			$xnetvn_agent_hub_wp_net_uid = wp_insert_user(
				array(
					'user_login' => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_run . '-' . $xnetvn_agent_hub_wp_net_alias,
					'user_pass'  => wp_generate_password( 48, true, true ),
					'user_email' => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_alias . '-' . $xnetvn_agent_hub_wp_net_short . '@example.test',
					'role'       => '',
				)
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_net_uid ) ) {
				throw new RuntimeException( 'Role SEO network fixture user unavailable.' );
			}
			update_user_meta( (int) $xnetvn_agent_hub_wp_net_uid, $xnetvn_agent_hub_wp_net_meta_key, '1' );
			$xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ] = (int) $xnetvn_agent_hub_wp_net_uid;
			$xnetvn_agent_hub_wp_net_state['ledger'][]                                = array(
				'type' => 'user',
				'blog' => 0,
				'ref'  => $xnetvn_agent_hub_wp_net_alias,
				'id'   => (int) $xnetvn_agent_hub_wp_net_uid,
			);
			xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		}
		$xnetvn_agent_hub_wp_net_super_uid = wp_insert_user(
			array(
				'user_login' => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_run . '-super',
				'user_pass'  => wp_generate_password( 48, true, true ),
				'user_email' => 'role-seo-net-super-' . $xnetvn_agent_hub_wp_net_short . '@example.test',
				'role'       => '',
			)
		);
		if ( is_wp_error( $xnetvn_agent_hub_wp_net_super_uid ) ) {
			throw new RuntimeException( 'Role SEO network fixture user unavailable.' );
		}
		update_user_meta( (int) $xnetvn_agent_hub_wp_net_super_uid, $xnetvn_agent_hub_wp_net_meta_key, '1' );
		$xnetvn_agent_hub_wp_net_state['users']['super'] = (int) $xnetvn_agent_hub_wp_net_super_uid;
		$xnetvn_agent_hub_wp_net_state['ledger'][]       = array(
			'type' => 'user',
			'blog' => 0,
			'ref'  => 'super',
			'id'   => (int) $xnetvn_agent_hub_wp_net_super_uid,
		);
		xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_key => $xnetvn_agent_hub_wp_net_blog ) {
			xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blog,
				static function ( int $xnetvn_agent_hub_wp_net_blog ) use ( $xnetvn_agent_hub_wp_net_aliases, $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_marker_option, $xnetvn_agent_hub_wp_net_run, $xnetvn_agent_hub_wp_net_short, $xnetvn_agent_hub_wp_net_key_kinds, $xnetvn_agent_hub_wp_net_keys_repo, $xnetvn_agent_hub_wp_net_key ): void {
					global $wpdb;
					$xnetvn_agent_hub_wp_net_banned_role = RoleGuard::ensure_banned_role();
					if ( 'banned' !== $xnetvn_agent_hub_wp_net_banned_role ) {
						throw new RuntimeException( 'Role SEO network banned role unavailable.' );
					}
					$xnetvn_agent_hub_wp_net_repo   = new WpdbKeyRepository( $wpdb, new SystemClock() );
					$xnetvn_agent_hub_wp_net_state  = Json::array( file_get_contents( $xnetvn_agent_hub_wp_net_state_file ) );
					$xnetvn_agent_hub_wp_net_users  = $xnetvn_agent_hub_wp_net_state['users'];
					$xnetvn_agent_hub_wp_net_pepper = ( new PepperStore() )->current();
					foreach ( $xnetvn_agent_hub_wp_net_aliases as $xnetvn_agent_hub_wp_net_alias => $xnetvn_agent_hub_wp_net_role ) {
						add_user_to_blog( $xnetvn_agent_hub_wp_net_blog, (int) $xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_alias ], $xnetvn_agent_hub_wp_net_role );
					}
					add_user_to_blog( $xnetvn_agent_hub_wp_net_blog, (int) $xnetvn_agent_hub_wp_net_users['super'], 'administrator' );
					$xnetvn_agent_hub_wp_net_roles = array_merge( $xnetvn_agent_hub_wp_net_aliases, array( 'super' => 'administrator' ) );
					$xnetvn_agent_hub_wp_net_state['roles'][ $xnetvn_agent_hub_wp_net_blog ]    = $xnetvn_agent_hub_wp_net_roles;
					$xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_blog ] = xnetvn_agent_hub_wp_net_snapshot_blog();
					update_option(
						$xnetvn_agent_hub_wp_net_marker_option,
						array(
							'suite'   => 'role-seo-network',
							'run_id'  => $xnetvn_agent_hub_wp_net_run,
							'blog'    => $xnetvn_agent_hub_wp_net_blog,
							'control' => $xnetvn_agent_hub_wp_net_state['control'],
						),
						false
					);
					$xnetvn_agent_hub_wp_net_state['keys'][ $xnetvn_agent_hub_wp_net_blog ]   = array();
					$xnetvn_agent_hub_wp_net_state['tokens'][ $xnetvn_agent_hub_wp_net_blog ] = array();
					$xnetvn_agent_hub_wp_net_by_owner = array();
					foreach ( $xnetvn_agent_hub_wp_net_key_kinds as $xnetvn_agent_hub_wp_net_kind => $xnetvn_agent_hub_wp_net_config ) {
						list( $xnetvn_agent_hub_wp_net_owner, $xnetvn_agent_hub_wp_net_tier, $xnetvn_agent_hub_wp_net_toolsets, $xnetvn_agent_hub_wp_net_allow, $xnetvn_agent_hub_wp_net_deny ) = $xnetvn_agent_hub_wp_net_config;
						$xnetvn_agent_hub_wp_net_owner_id  = (int) $xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_owner ];
						$xnetvn_agent_hub_wp_net_generated = ( new KeyGenerator() )->generate();
						$xnetvn_agent_hub_wp_net_row       = $xnetvn_agent_hub_wp_net_repo->insert(
							$xnetvn_agent_hub_wp_net_generated->id,
							$xnetvn_agent_hub_wp_net_owner_id,
							'Role SEO net ' . $xnetvn_agent_hub_wp_net_key . ' ' . $xnetvn_agent_hub_wp_net_kind . ' ' . $xnetvn_agent_hub_wp_net_short,
							new Scope( RiskTier::from( $xnetvn_agent_hub_wp_net_tier ), $xnetvn_agent_hub_wp_net_toolsets, $xnetvn_agent_hub_wp_net_allow, $xnetvn_agent_hub_wp_net_deny, array(), new DateTimeImmutable( '+1 day' ) ),
							$xnetvn_agent_hub_wp_net_generated->hash_with( $xnetvn_agent_hub_wp_net_pepper ),
							$xnetvn_agent_hub_wp_net_pepper->id
						);
						$xnetvn_agent_hub_wp_net_state['keys'][ $xnetvn_agent_hub_wp_net_blog ][ $xnetvn_agent_hub_wp_net_kind ] = array(
							'id'     => $xnetvn_agent_hub_wp_net_row->id,
							'key_id' => $xnetvn_agent_hub_wp_net_row->key_id->value,
							'owner'  => $xnetvn_agent_hub_wp_net_owner_id,
							'name'   => $xnetvn_agent_hub_wp_net_row->name,
						);
						$xnetvn_agent_hub_wp_net_state['ledger'][] = array(
							'type' => 'key',
							'blog' => $xnetvn_agent_hub_wp_net_blog,
							'ref'  => $xnetvn_agent_hub_wp_net_key . ':' . $xnetvn_agent_hub_wp_net_kind,
							'id'   => $xnetvn_agent_hub_wp_net_row->id,
						);
						$xnetvn_agent_hub_wp_net_token             = $xnetvn_agent_hub_wp_net_generated->reveal();
						if ( ! isset( $xnetvn_agent_hub_wp_net_by_owner[ $xnetvn_agent_hub_wp_net_owner ] ) ) {
							$xnetvn_agent_hub_wp_net_by_owner[ $xnetvn_agent_hub_wp_net_owner ] = $xnetvn_agent_hub_wp_net_token;
						}
						$xnetvn_agent_hub_wp_net_by_owner[ $xnetvn_agent_hub_wp_net_kind ] = $xnetvn_agent_hub_wp_net_token;
						xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
					}
					$xnetvn_agent_hub_wp_net_state = Json::array( file_get_contents( $xnetvn_agent_hub_wp_net_state_file ) );
					$xnetvn_agent_hub_wp_net_state['tokens'][ $xnetvn_agent_hub_wp_net_blog ] = $xnetvn_agent_hub_wp_net_by_owner;
					unset( $xnetvn_agent_hub_wp_net_by_owner );
					$xnetvn_agent_hub_wp_net_posts = array(
						101 => 'editor',
						202 => 'author_b',
					);
					foreach ( $xnetvn_agent_hub_wp_net_posts as $xnetvn_agent_hub_wp_net_logical => $xnetvn_agent_hub_wp_net_post_owner ) {
						$xnetvn_agent_hub_wp_net_post_id = wp_insert_post(
							array(
								'post_title'  => 'Role SEO net ' . $xnetvn_agent_hub_wp_net_run . ' ' . $xnetvn_agent_hub_wp_net_key . ' ' . $xnetvn_agent_hub_wp_net_logical,
								'post_name'   => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_short . '-' . $xnetvn_agent_hub_wp_net_key . '-' . $xnetvn_agent_hub_wp_net_logical,
								'post_status' => 'draft',
								'post_author' => (int) $xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_post_owner ],
								'post_type'   => 'post',
							),
							true
						);
						if ( is_wp_error( $xnetvn_agent_hub_wp_net_post_id ) ) {
							throw new RuntimeException( 'Role SEO network fixture post unavailable.' );
						}
						$xnetvn_agent_hub_wp_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_key ][ $xnetvn_agent_hub_wp_net_logical ] = $xnetvn_agent_hub_wp_net_post_id;
						$xnetvn_agent_hub_wp_net_state['ledger'][] = array(
							'type' => 'post',
							'blog' => $xnetvn_agent_hub_wp_net_blog,
							'ref'  => $xnetvn_agent_hub_wp_net_key . ':' . $xnetvn_agent_hub_wp_net_logical,
							'id'   => $xnetvn_agent_hub_wp_net_post_id,
						);
						xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
					}
					$xnetvn_agent_hub_wp_net_terms = array(
						301 => array( 'category', 'Role SEO Net Cat' ),
						302 => array( 'post_tag', 'Role SEO Net Tag' ),
					);
					foreach ( $xnetvn_agent_hub_wp_net_terms as $xnetvn_agent_hub_wp_net_logical => $xnetvn_agent_hub_wp_net_spec ) {
						list( $xnetvn_agent_hub_wp_net_taxonomy, $xnetvn_agent_hub_wp_net_term_name ) = $xnetvn_agent_hub_wp_net_spec;
						$xnetvn_agent_hub_wp_net_term = wp_insert_term(
							$xnetvn_agent_hub_wp_net_term_name . ' ' . $xnetvn_agent_hub_wp_net_short . ' ' . $xnetvn_agent_hub_wp_net_key,
							$xnetvn_agent_hub_wp_net_taxonomy,
							array( 'slug' => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_short . '-' . $xnetvn_agent_hub_wp_net_key . '-' . $xnetvn_agent_hub_wp_net_logical )
						);
						if ( is_wp_error( $xnetvn_agent_hub_wp_net_term ) ) {
							throw new RuntimeException( 'Role SEO network fixture term unavailable.' );
						}
						$xnetvn_agent_hub_wp_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_key ][ $xnetvn_agent_hub_wp_net_logical ] = $xnetvn_agent_hub_wp_net_term['term_id'];
						$xnetvn_agent_hub_wp_net_state['ledger'][] = array(
							'type' => 'term',
							'blog' => $xnetvn_agent_hub_wp_net_blog,
							'ref'  => $xnetvn_agent_hub_wp_net_key . ':' . $xnetvn_agent_hub_wp_net_logical,
							'id'   => $xnetvn_agent_hub_wp_net_term['term_id'],
						);
						xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
					}
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$xnetvn_agent_hub_wp_net_pixel = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode a fixed 1px test pixel, never executable code.
					foreach ( array(
						401 => 'editor',
						402 => 'author_b',
					) as $xnetvn_agent_hub_wp_net_logical => $xnetvn_agent_hub_wp_net_media_owner ) {
						$xnetvn_agent_hub_wp_net_upload = wp_upload_bits( 'role-seo-net-' . $xnetvn_agent_hub_wp_net_short . '-' . $xnetvn_agent_hub_wp_net_key . '-' . $xnetvn_agent_hub_wp_net_logical . '.png', null, $xnetvn_agent_hub_wp_net_pixel );
						if ( ! empty( $xnetvn_agent_hub_wp_net_upload['error'] ) ) {
							throw new RuntimeException( 'Role SEO network fixture upload unavailable.' );
						}
						$xnetvn_agent_hub_wp_net_attach_id = wp_insert_attachment(
							array(
								'post_title'     => 'Role SEO net ' . $xnetvn_agent_hub_wp_net_run . ' ' . $xnetvn_agent_hub_wp_net_key . ' ' . $xnetvn_agent_hub_wp_net_logical,
								'post_name'      => 'role-seo-net-' . $xnetvn_agent_hub_wp_net_short . '-' . $xnetvn_agent_hub_wp_net_key . '-' . $xnetvn_agent_hub_wp_net_logical,
								'post_status'    => 'inherit',
								'post_author'    => (int) $xnetvn_agent_hub_wp_net_users[ $xnetvn_agent_hub_wp_net_media_owner ],
								'post_mime_type' => 'image/png',
							),
							$xnetvn_agent_hub_wp_net_upload['file']
						);
						if ( 0 === $xnetvn_agent_hub_wp_net_attach_id || is_wp_error( $xnetvn_agent_hub_wp_net_attach_id ) ) {
							throw new RuntimeException( 'Role SEO network fixture media unavailable.' );
						}
						wp_update_attachment_metadata( $xnetvn_agent_hub_wp_net_attach_id, wp_generate_attachment_metadata( $xnetvn_agent_hub_wp_net_attach_id, $xnetvn_agent_hub_wp_net_upload['file'] ) );
						$xnetvn_agent_hub_wp_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_key ][ $xnetvn_agent_hub_wp_net_logical ] = $xnetvn_agent_hub_wp_net_attach_id;
						$xnetvn_agent_hub_wp_net_state['ledger'][] = array(
							'type' => 'media',
							'blog' => $xnetvn_agent_hub_wp_net_blog,
							'ref'  => $xnetvn_agent_hub_wp_net_key . ':' . $xnetvn_agent_hub_wp_net_logical,
							'id'   => $xnetvn_agent_hub_wp_net_attach_id,
						);
						xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
					}
					unset( $xnetvn_agent_hub_wp_net_pixel );
					xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
				}
			);
		}
		$xnetvn_agent_hub_wp_net_state = Json::array( file_get_contents( $xnetvn_agent_hub_wp_net_state_file ) );
		if ( 7 !== count( $xnetvn_agent_hub_wp_net_state['users'] ) - 1 || ! isset( $xnetvn_agent_hub_wp_net_state['users']['super'] ) ) {
			throw new RuntimeException( 'Role SEO network fixture actor mismatch.' );
		}
		xnetvn_agent_hub_wp_net_report(
			array(
				'seeded' => true,
				'actors' => count( $xnetvn_agent_hub_wp_net_state['users'] ),
				'blogs'  => $xnetvn_agent_hub_wp_net_blogs,
			)
		);
		return;
	}

	if ( ! $xnetvn_agent_hub_wp_net_state || 'role-seo-network' !== ( $xnetvn_agent_hub_wp_net_state['suite'] ?? '' ) || ( $xnetvn_agent_hub_wp_net_state['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_net_run ) {
		throw new RuntimeException( 'Role SEO network fixture state unavailable.' );
	}

	if ( 'verify' === $xnetvn_agent_hub_wp_net_action ) {
		$xnetvn_agent_hub_wp_net_marker = get_option( $xnetvn_agent_hub_wp_net_marker_option, false );
		if ( ! is_array( $xnetvn_agent_hub_wp_net_marker ) || ( $xnetvn_agent_hub_wp_net_marker['control'] ?? '' ) !== $xnetvn_agent_hub_wp_net_state['control'] || ( $xnetvn_agent_hub_wp_net_marker['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_net_run || (int) ( $xnetvn_agent_hub_wp_net_marker['blog'] ?? 0 ) !== $xnetvn_agent_hub_wp_net_current_blog ) {
			throw new RuntimeException( 'Role SEO network fixture marker mismatch.' );
		}
		foreach ( $xnetvn_agent_hub_wp_net_state['users'] as $xnetvn_agent_hub_wp_net_alias => $xnetvn_agent_hub_wp_net_uid ) {
			$xnetvn_agent_hub_wp_net_profile = get_userdata( (int) $xnetvn_agent_hub_wp_net_uid );
			if ( ! $xnetvn_agent_hub_wp_net_profile instanceof WP_User || 'role-seo-net-' . $xnetvn_agent_hub_wp_net_run . '-' . $xnetvn_agent_hub_wp_net_alias !== $xnetvn_agent_hub_wp_net_profile->user_login || '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_net_profile->ID, $xnetvn_agent_hub_wp_net_meta_key, true ) ) {
				throw new RuntimeException( 'Role SEO network fixture actor mismatch.' );
			}
			if ( ! is_user_member_of_blog( (int) $xnetvn_agent_hub_wp_net_uid, $xnetvn_agent_hub_wp_net_current_blog ) ) {
				throw new RuntimeException( 'Role SEO network fixture membership mismatch.' );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_net_state['keys'][ $xnetvn_agent_hub_wp_net_current_blog ] ?? array() as $xnetvn_agent_hub_wp_net_kind => $xnetvn_agent_hub_wp_net_key ) {
			$xnetvn_agent_hub_wp_net_row = $xnetvn_agent_hub_wp_net_keys_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_net_key['id'] ?? 0 ) );
			if ( null === $xnetvn_agent_hub_wp_net_row || $xnetvn_agent_hub_wp_net_row->user_id !== (int) $xnetvn_agent_hub_wp_net_key['owner'] || $xnetvn_agent_hub_wp_net_row->name !== $xnetvn_agent_hub_wp_net_key['name'] || ! str_starts_with( $xnetvn_agent_hub_wp_net_row->name, 'Role SEO net ' ) || $xnetvn_agent_hub_wp_net_row->is_revoked() ) {
				throw new RuntimeException( 'Role SEO network fixture key mismatch.' );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_current_key ] ?? array() as $xnetvn_agent_hub_wp_net_logical => $xnetvn_agent_hub_wp_net_real ) {
			if ( 100 < (int) $xnetvn_agent_hub_wp_net_logical && 300 > (int) $xnetvn_agent_hub_wp_net_logical ) {
				$xnetvn_agent_hub_wp_net_post = get_post( (int) $xnetvn_agent_hub_wp_net_real );
				if ( ! $xnetvn_agent_hub_wp_net_post instanceof WP_Post || ! in_array( (int) $xnetvn_agent_hub_wp_net_post->post_author, $xnetvn_agent_hub_wp_net_state['users'], true ) ) {
					throw new RuntimeException( 'Role SEO network fixture content mismatch.' );
				}
			}
		}
		$xnetvn_agent_hub_wp_net_settings = new WpSiteSettings();
		if ( xnetvn_agent_hub_wp_net_digest( $xnetvn_agent_hub_wp_net_settings->all() ) !== ( $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ]['settings_digest'] ?? '' ) ) {
			throw new RuntimeException( 'Role SEO network settings drift detected.' );
		}
		xnetvn_agent_hub_wp_net_report( array( 'verified' => true ) );
		return;
	}

	if ( 'snapshot' === $xnetvn_agent_hub_wp_net_action ) {
		$xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ] = xnetvn_agent_hub_wp_net_snapshot_blog();
		xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		xnetvn_agent_hub_wp_net_report( array( 'snapshot' => true ) );
		return;
	}

	if ( in_array( $xnetvn_agent_hub_wp_net_action, array( 'role-blog', 'ban-verify', 'ban-reset', 'quota-set', 'quota-restore', 'settings-variant', 'net-read', 'super', 'content-digest', 'collision-pick', 'collision-make', 'blogname-set' ), true ) ) {
		$xnetvn_agent_hub_wp_net_marker = get_option( $xnetvn_agent_hub_wp_net_marker_option, false );
		if ( ! is_array( $xnetvn_agent_hub_wp_net_marker ) || ( $xnetvn_agent_hub_wp_net_marker['control'] ?? '' ) !== $xnetvn_agent_hub_wp_net_state['control'] || ( $xnetvn_agent_hub_wp_net_marker['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_net_run ) {
			exit( 2 );
		}
		$xnetvn_agent_hub_wp_net_sub = $args[1] ?? '';
		if ( 'role-blog' === $xnetvn_agent_hub_wp_net_action ) {
			$xnetvn_agent_hub_wp_net_alias = $args[1] ?? '';
			$xnetvn_agent_hub_wp_net_role  = $args[2] ?? '';
			if ( ! isset( $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ] ) || ! in_array( $xnetvn_agent_hub_wp_net_role, array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'banned' ), true ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_uid = (int) $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ];
			if ( ! is_user_member_of_blog( $xnetvn_agent_hub_wp_net_uid, $xnetvn_agent_hub_wp_net_current_blog ) ) {
				add_user_to_blog( $xnetvn_agent_hub_wp_net_current_blog, $xnetvn_agent_hub_wp_net_uid, $xnetvn_agent_hub_wp_net_role );
			} else {
				$xnetvn_agent_hub_wp_net_user = get_userdata( $xnetvn_agent_hub_wp_net_uid );
				if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
					throw new RuntimeException( 'Role SEO network fixture actor unavailable.' );
				}
				$xnetvn_agent_hub_wp_net_user->set_role( $xnetvn_agent_hub_wp_net_role );
			}
			clean_user_cache( $xnetvn_agent_hub_wp_net_uid );
			$xnetvn_agent_hub_wp_net_fresh = get_userdata( $xnetvn_agent_hub_wp_net_uid );
			if ( ! $xnetvn_agent_hub_wp_net_fresh instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO network fixture actor unavailable.' );
			}
			xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
			xnetvn_agent_hub_wp_net_report( array( 'role' => array_values( (array) $xnetvn_agent_hub_wp_net_fresh->roles ) ) );
			return;
		}
		if ( 'ban-verify' === $xnetvn_agent_hub_wp_net_action ) {
			$xnetvn_agent_hub_wp_net_alias = $xnetvn_agent_hub_wp_net_sub;
			if ( '' === $xnetvn_agent_hub_wp_net_alias || ! isset( $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ] ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_uid  = (int) $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ];
			$xnetvn_agent_hub_wp_net_user = get_userdata( $xnetvn_agent_hub_wp_net_uid );
			if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO network fixture actor unavailable.' );
			}
			$xnetvn_agent_hub_wp_net_caps = false;
			foreach ( (array) $xnetvn_agent_hub_wp_net_user->allcaps as $xnetvn_agent_hub_wp_net_cap => $xnetvn_agent_hub_wp_net_enabled ) {
				if ( ! $xnetvn_agent_hub_wp_net_enabled || 'banned' === (string) $xnetvn_agent_hub_wp_net_cap ) {
					continue;
				}
				$xnetvn_agent_hub_wp_net_caps = true;
			}
			$xnetvn_agent_hub_wp_net_sessions = WP_Session_Tokens::get_instance( $xnetvn_agent_hub_wp_net_uid )->get_all();
			xnetvn_agent_hub_wp_net_report(
				array(
					'roles'      => array_values( (array) $xnetvn_agent_hub_wp_net_user->roles ),
					'caps_empty' => ! $xnetvn_agent_hub_wp_net_caps,
					'sessions'   => is_array( $xnetvn_agent_hub_wp_net_sessions ) ? count( $xnetvn_agent_hub_wp_net_sessions ) : 0,
					'use'        => user_can( $xnetvn_agent_hub_wp_net_uid, Capabilities::USE ),
				)
			);
			return;
		}
		if ( 'ban-reset' === $xnetvn_agent_hub_wp_net_action ) {
			$xnetvn_agent_hub_wp_net_alias = $xnetvn_agent_hub_wp_net_sub;
			$xnetvn_agent_hub_wp_net_roles = $xnetvn_agent_hub_wp_net_state['roles'][ $xnetvn_agent_hub_wp_net_current_blog ][ $xnetvn_agent_hub_wp_net_alias ] ?? null;
			if ( '' === $xnetvn_agent_hub_wp_net_alias || ! isset( $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ] ) || ! is_string( $xnetvn_agent_hub_wp_net_roles ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_uid  = (int) $xnetvn_agent_hub_wp_net_state['users'][ $xnetvn_agent_hub_wp_net_alias ];
			$xnetvn_agent_hub_wp_net_user = get_userdata( $xnetvn_agent_hub_wp_net_uid );
			if ( ! $xnetvn_agent_hub_wp_net_user instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO network fixture actor unavailable.' );
			}
			$xnetvn_agent_hub_wp_net_user->set_role( $xnetvn_agent_hub_wp_net_roles );
			BanSnapshot::clear( $xnetvn_agent_hub_wp_net_uid );
			clean_user_cache( $xnetvn_agent_hub_wp_net_uid );
			$xnetvn_agent_hub_wp_net_fresh = get_userdata( $xnetvn_agent_hub_wp_net_uid );
			xnetvn_agent_hub_wp_net_report( array( 'reset' => $xnetvn_agent_hub_wp_net_fresh instanceof WP_User && array( $xnetvn_agent_hub_wp_net_roles ) === array_values( (array) $xnetvn_agent_hub_wp_net_fresh->roles ) ) );
			return;
		}
		if ( 'quota-set' === $xnetvn_agent_hub_wp_net_action ) {
			if ( 1 !== preg_match( '/\A[0-9]{1,3}\z/', $xnetvn_agent_hub_wp_net_sub ) ) {
				exit( 2 );
			}
			// The lab network disables upload-space checks, so the quota case
			// would never trigger without a temporary owned toggle. Back up
			// both values first; quota-restore and cleanup put them back.
			$xnetvn_agent_hub_wp_net_state['quota_backup'] = array(
				'blog'           => $xnetvn_agent_hub_wp_net_current_blog,
				'upload_space'   => get_option( 'blog_upload_space' ),
				'check_disabled' => get_site_option( 'upload_space_check_disabled', '__role_seo_net_absent__' ),
			);
			xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
			update_option( 'blog_upload_space', (int) $xnetvn_agent_hub_wp_net_sub );
			update_site_option( 'upload_space_check_disabled', '0' );
			xnetvn_agent_hub_wp_net_report(
				array(
					'upload_space'   => get_option( 'blog_upload_space' ),
					'check_disabled' => get_site_option( 'upload_space_check_disabled' ),
					'available'      => is_upload_space_available(),
				)
			);
			return;
		}
		if ( 'quota-restore' === $xnetvn_agent_hub_wp_net_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_net_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_backup = $xnetvn_agent_hub_wp_net_state['quota_backup'] ?? null;
			if ( is_array( $xnetvn_agent_hub_wp_net_backup ) ) {
				if ( (int) ( $xnetvn_agent_hub_wp_net_backup['blog'] ?? 0 ) === (int) $xnetvn_agent_hub_wp_net_current_blog && array_key_exists( 'upload_space', $xnetvn_agent_hub_wp_net_backup ) ) {
					update_option( 'blog_upload_space', $xnetvn_agent_hub_wp_net_backup['upload_space'] );
				}
				if ( array_key_exists( 'check_disabled', $xnetvn_agent_hub_wp_net_backup ) ) {
					if ( '__role_seo_net_absent__' === $xnetvn_agent_hub_wp_net_backup['check_disabled'] ) {
						delete_site_option( 'upload_space_check_disabled' );
					} else {
						update_site_option( 'upload_space_check_disabled', $xnetvn_agent_hub_wp_net_backup['check_disabled'] );
					}
				}
				unset( $xnetvn_agent_hub_wp_net_state['quota_backup'] );
				xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
			}
			xnetvn_agent_hub_wp_net_report(
				array(
					'upload_space'   => get_option( 'blog_upload_space' ),
					'check_disabled' => get_site_option( 'upload_space_check_disabled' ),
					'available'      => is_upload_space_available(),
				)
			);
			return;
		}
		if ( 'settings-variant' === $xnetvn_agent_hub_wp_net_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_net_sub, array( 'deny', 'corrupt', 'admin-off', 'admin-on', 'reset', 'ceiling-admin' ), true ) ) {
				exit( 2 );
			}
			if ( 'reset' === $xnetvn_agent_hub_wp_net_sub ) {
				( new WpSiteSettings() )->update( $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ]['settings'] );
				update_option( 'cron', $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ]['cron'], false );
				if ( array_key_exists( 'upload_space', $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ] ) ) {
					update_option( 'blog_upload_space', $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ]['upload_space'] );
				}
				if ( array_key_exists( 'blogname', $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ] ) ) {
					update_option( 'blogname', $xnetvn_agent_hub_wp_net_state['snapshot'][ $xnetvn_agent_hub_wp_net_current_blog ]['blogname'] );
				}
			} elseif ( 'deny' === $xnetvn_agent_hub_wp_net_sub ) {
				( new WpSiteSettings() )->update( array( 'allowed_roles_override' => array() ) );
			} elseif ( 'corrupt' === $xnetvn_agent_hub_wp_net_sub ) {
				update_option( WpSiteSettings::OPTION, array( 'enabled' => 'invalid' ), false );
			} elseif ( 'admin-off' === $xnetvn_agent_hub_wp_net_sub ) {
				( new WpSiteSettings() )->update( array( 'allow_admin_tier' => false ) );
			} elseif ( 'ceiling-admin' === $xnetvn_agent_hub_wp_net_sub ) {
				( new WpSiteSettings() )->update( array( 'tier_ceiling' => 'admin' ) );
			} else {
				( new WpSiteSettings() )->update( array( 'allow_admin_tier' => true ) );
			}
			try {
				$xnetvn_agent_hub_wp_net_digest = xnetvn_agent_hub_wp_net_digest( ( new WpSiteSettings() )->all() );
			} catch ( \Throwable ) {
				$xnetvn_agent_hub_wp_net_digest = 'unavailable';
			}
			xnetvn_agent_hub_wp_net_report(
				array(
					'variant'         => $xnetvn_agent_hub_wp_net_sub,
					'settings_digest' => $xnetvn_agent_hub_wp_net_digest,
				)
			);
			return;
		}
		if ( 'net-read' === $xnetvn_agent_hub_wp_net_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_net_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_settings = new WpSiteSettings();
			$xnetvn_agent_hub_wp_net_keys_ok  = true;
			$xnetvn_agent_hub_wp_net_key_ids  = array();
			foreach ( $xnetvn_agent_hub_wp_net_state['keys'][ $xnetvn_agent_hub_wp_net_current_blog ] ?? array() as $xnetvn_agent_hub_wp_net_kind => $xnetvn_agent_hub_wp_net_key ) {
				$xnetvn_agent_hub_wp_net_row = $xnetvn_agent_hub_wp_net_keys_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_net_key['id'] ?? 0 ) );
				if ( null === $xnetvn_agent_hub_wp_net_row || $xnetvn_agent_hub_wp_net_row->user_id !== (int) $xnetvn_agent_hub_wp_net_key['owner'] || $xnetvn_agent_hub_wp_net_row->name !== $xnetvn_agent_hub_wp_net_key['name'] ) {
					$xnetvn_agent_hub_wp_net_keys_ok = false;
				} else {
					$xnetvn_agent_hub_wp_net_key_ids[] = $xnetvn_agent_hub_wp_net_row->id;
				}
			}
			$xnetvn_agent_hub_wp_net_audit = 0;
			foreach ( $xnetvn_agent_hub_wp_net_state['users'] as $xnetvn_agent_hub_wp_net_uid ) {
				$xnetvn_agent_hub_wp_net_audit += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', (int) $xnetvn_agent_hub_wp_net_uid ) );
			}
			xnetvn_agent_hub_wp_net_report(
				array(
					'settings_digest' => xnetvn_agent_hub_wp_net_digest( $xnetvn_agent_hub_wp_net_settings->all() ),
					'keys_match'      => $xnetvn_agent_hub_wp_net_keys_ok,
					'key_count'       => count( $xnetvn_agent_hub_wp_net_key_ids ),
					'audit_rows'      => $xnetvn_agent_hub_wp_net_audit,
					'pepper_set'      => 0 < (int) ( new PepperStore() )->current()->id,
					'upload_space'    => get_option( 'blog_upload_space' ),
					'check_disabled'  => get_site_option( 'upload_space_check_disabled' ),
					'blogname_set'    => is_string( get_option( 'blogname' ) ) && '' !== (string) get_option( 'blogname' ),
				)
			);
			return;
		}
		if ( 'super' === $xnetvn_agent_hub_wp_net_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_net_sub, array( 'grant', 'revoke' ), true ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_uid = (int) ( $xnetvn_agent_hub_wp_net_state['users']['super'] ?? 0 );
			if ( 1 > $xnetvn_agent_hub_wp_net_uid ) {
				throw new RuntimeException( 'Role SEO network fixture actor unavailable.' );
			}
			if ( 'grant' === $xnetvn_agent_hub_wp_net_sub ) {
				if ( ! is_super_admin( $xnetvn_agent_hub_wp_net_uid ) ) {
					grant_super_admin( $xnetvn_agent_hub_wp_net_uid );
				}
			} elseif ( is_super_admin( $xnetvn_agent_hub_wp_net_uid ) ) {
				revoke_super_admin( $xnetvn_agent_hub_wp_net_uid );
			}
			xnetvn_agent_hub_wp_net_report( array( 'super_admin' => is_super_admin( $xnetvn_agent_hub_wp_net_uid ) ) );
			return;
		}
		if ( 'blogname-set' === $xnetvn_agent_hub_wp_net_action ) {
			if ( 1 !== preg_match( '/\A[a-z0-9-]{1,16}\z/', $xnetvn_agent_hub_wp_net_sub ) ) {
				exit( 2 );
			}
			update_option( 'blogname', 'Role SEO net ' . $xnetvn_agent_hub_wp_net_sub );
			xnetvn_agent_hub_wp_net_report( array( 'blogname_set' => true ) );
			return;
		}
		if ( 'content-digest' === $xnetvn_agent_hub_wp_net_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_net_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_digests = array();
			foreach ( $xnetvn_agent_hub_wp_net_state['content']['ids'][ $xnetvn_agent_hub_wp_net_current_key ] ?? array() as $xnetvn_agent_hub_wp_net_logical => $xnetvn_agent_hub_wp_net_real ) {
				$xnetvn_agent_hub_wp_net_logical_int = (int) $xnetvn_agent_hub_wp_net_logical;
				if ( ( 100 < $xnetvn_agent_hub_wp_net_logical_int && 300 > $xnetvn_agent_hub_wp_net_logical_int ) || ( 400 < $xnetvn_agent_hub_wp_net_logical_int && 500 > $xnetvn_agent_hub_wp_net_logical_int ) ) {
					$xnetvn_agent_hub_wp_net_post = get_post( (int) $xnetvn_agent_hub_wp_net_real );
					if ( ! $xnetvn_agent_hub_wp_net_post instanceof WP_Post ) {
						throw new RuntimeException( 'Role SEO network fixture content unavailable.' );
					}
					$xnetvn_agent_hub_wp_net_digests[ $xnetvn_agent_hub_wp_net_logical ] = array(
						'owner'  => (int) $xnetvn_agent_hub_wp_net_post->post_author,
						'digest' => xnetvn_agent_hub_wp_net_digest(
							array(
								'title'  => $xnetvn_agent_hub_wp_net_post->post_title,
								'name'   => $xnetvn_agent_hub_wp_net_post->post_name,
								'status' => $xnetvn_agent_hub_wp_net_post->post_status,
								'author' => (int) $xnetvn_agent_hub_wp_net_post->post_author,
								'type'   => $xnetvn_agent_hub_wp_net_post->post_type,
							)
						),
					);
				} else {
					$xnetvn_agent_hub_wp_net_term = get_term( (int) $xnetvn_agent_hub_wp_net_real );
					if ( ! $xnetvn_agent_hub_wp_net_term instanceof WP_Term ) {
						throw new RuntimeException( 'Role SEO network fixture term unavailable.' );
					}
					$xnetvn_agent_hub_wp_net_digests[ $xnetvn_agent_hub_wp_net_logical ] = array(
						'owner'  => 0,
						'digest' => xnetvn_agent_hub_wp_net_digest(
							array(
								'name'     => $xnetvn_agent_hub_wp_net_term->name,
								'slug'     => $xnetvn_agent_hub_wp_net_term->slug,
								'taxonomy' => $xnetvn_agent_hub_wp_net_term->taxonomy,
							)
						),
					);
				}
			}
			xnetvn_agent_hub_wp_net_report( array( 'content' => $xnetvn_agent_hub_wp_net_digests ) );
			return;
		}
		if ( 'collision-pick' === $xnetvn_agent_hub_wp_net_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_net_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_max = 0;
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_top = (int) xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function (): int {
						global $wpdb;
						return (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->posts" );
					}
				);
				if ( $xnetvn_agent_hub_wp_net_top > $xnetvn_agent_hub_wp_net_max ) {
					$xnetvn_agent_hub_wp_net_max = $xnetvn_agent_hub_wp_net_top;
				}
			}
			$xnetvn_agent_hub_wp_net_candidate = $xnetvn_agent_hub_wp_net_max + 1;
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_taken = xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function () use ( $xnetvn_agent_hub_wp_net_candidate ): bool {
						return null !== get_post( $xnetvn_agent_hub_wp_net_candidate );
					}
				);
				if ( $xnetvn_agent_hub_wp_net_taken ) {
					exit( 2 );
				}
			}
			xnetvn_agent_hub_wp_net_report( array( 'candidate' => $xnetvn_agent_hub_wp_net_candidate ) );
			return;
		}
		if ( 'collision-make' === $xnetvn_agent_hub_wp_net_action ) {
			if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_net_sub ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_net_candidate = (int) $xnetvn_agent_hub_wp_net_sub;
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_taken = xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function () use ( $xnetvn_agent_hub_wp_net_candidate ): bool {
						return null !== get_post( $xnetvn_agent_hub_wp_net_candidate );
					}
				);
				if ( $xnetvn_agent_hub_wp_net_taken ) {
					exit( 2 );
				}
			}
			$xnetvn_agent_hub_wp_net_made = array();
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_key => $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_post_id                              = xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function () use ( $xnetvn_agent_hub_wp_net_candidate, $xnetvn_agent_hub_wp_net_key, $xnetvn_agent_hub_wp_net_run, $xnetvn_agent_hub_wp_net_state ): int {
						$xnetvn_agent_hub_wp_net_owner   = (int) ( $xnetvn_agent_hub_wp_net_state['users']['editor'] ?? 0 );
						$xnetvn_agent_hub_wp_net_post_id = wp_insert_post(
							array(
								'import_id'   => $xnetvn_agent_hub_wp_net_candidate,
								'post_title'  => 'Role SEO net collision ' . $xnetvn_agent_hub_wp_net_run . ' ' . $xnetvn_agent_hub_wp_net_key,
								'post_name'   => 'role-seo-net-' . substr( $xnetvn_agent_hub_wp_net_state['control'], 0, 8 ) . '-collision-' . $xnetvn_agent_hub_wp_net_key,
								'post_status' => 'draft',
								'post_author' => $xnetvn_agent_hub_wp_net_owner,
								'post_type'   => 'post',
							),
							true
						);
						if ( is_wp_error( $xnetvn_agent_hub_wp_net_post_id ) || $xnetvn_agent_hub_wp_net_candidate !== (int) $xnetvn_agent_hub_wp_net_post_id ) {
							throw new RuntimeException( 'Role SEO network fixture collision mismatch.' );
						}
						return (int) $xnetvn_agent_hub_wp_net_post_id;
					}
				);
				$xnetvn_agent_hub_wp_net_made[ $xnetvn_agent_hub_wp_net_key ] = $xnetvn_agent_hub_wp_net_post_id;
				$xnetvn_agent_hub_wp_net_state['ledger'][]                    = array(
					'type' => 'post',
					'blog' => $xnetvn_agent_hub_wp_net_blog,
					'ref'  => $xnetvn_agent_hub_wp_net_key . ':collision',
					'id'   => $xnetvn_agent_hub_wp_net_post_id,
				);
				xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
			}
			$xnetvn_agent_hub_wp_net_check = xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blogs['main'],
				static function () use ( $xnetvn_agent_hub_wp_net_candidate ): string {
					$xnetvn_agent_hub_wp_net_post = get_post( $xnetvn_agent_hub_wp_net_candidate );
					return $xnetvn_agent_hub_wp_net_post instanceof WP_Post ? (string) $xnetvn_agent_hub_wp_net_post->post_title : '';
				}
			);
			$xnetvn_agent_hub_wp_net_other = xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blogs['child'],
				static function () use ( $xnetvn_agent_hub_wp_net_candidate ): string {
					$xnetvn_agent_hub_wp_net_post = get_post( $xnetvn_agent_hub_wp_net_candidate );
					return $xnetvn_agent_hub_wp_net_post instanceof WP_Post ? (string) $xnetvn_agent_hub_wp_net_post->post_title : '';
				}
			);
			if ( '' === $xnetvn_agent_hub_wp_net_check || '' === $xnetvn_agent_hub_wp_net_other || $xnetvn_agent_hub_wp_net_check === $xnetvn_agent_hub_wp_net_other ) {
				throw new RuntimeException( 'Role SEO network fixture collision mismatch.' );
			}
			xnetvn_agent_hub_wp_net_report( array( 'collision' => $xnetvn_agent_hub_wp_net_made ) );
			return;
		}
	}

	if ( 'restore' === $xnetvn_agent_hub_wp_net_action || 'cleanup' === $xnetvn_agent_hub_wp_net_action ) {
		$xnetvn_agent_hub_wp_net_removed = array(
			'users' => 0,
			'keys'  => 0,
			'posts' => 0,
			'terms' => 0,
			'media' => 0,
		);
		if ( ! $xnetvn_agent_hub_wp_net_state ) {
			$xnetvn_agent_hub_wp_net_leftover = false;
			foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
				$xnetvn_agent_hub_wp_net_has = xnetvn_agent_hub_wp_net_on_blog(
					$xnetvn_agent_hub_wp_net_blog,
					static function (): bool {
						return false !== get_option( 'xnetvn_agent_hub_wp_role_seo_network', false );
					}
				);
				if ( $xnetvn_agent_hub_wp_net_has ) {
					$xnetvn_agent_hub_wp_net_leftover = true;
				}
			}
			if ( $xnetvn_agent_hub_wp_net_leftover ) {
				exit( 2 );
			}
			xnetvn_agent_hub_wp_net_report(
				array(
					'restored' => true,
					'removed'  => $xnetvn_agent_hub_wp_net_removed,
				)
			);
			if ( 'cleanup' === $xnetvn_agent_hub_wp_net_action && file_exists( $xnetvn_agent_hub_wp_net_state_file ) ) {
				unlink( $xnetvn_agent_hub_wp_net_state_file );
			}
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
			xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blog,
				static function () use ( $xnetvn_agent_hub_wp_net_state, $xnetvn_agent_hub_wp_net_marker_option ): void {
					if ( isset( $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ]['settings'] ) ) {
						( new WpSiteSettings() )->update( $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ]['settings'] );
						update_option( 'cron', $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ]['cron'], false );
						if ( array_key_exists( 'upload_space', $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ] ) ) {
							update_option( 'blog_upload_space', $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ]['upload_space'] );
						}
						if ( array_key_exists( 'blogname', $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ] ) ) {
							update_option( 'blogname', $xnetvn_agent_hub_wp_net_state['snapshot'][ get_current_blog_id() ]['blogname'] );
						}
					}
					delete_option( $xnetvn_agent_hub_wp_net_marker_option );
				}
			);
		}
		// Backstop for the temporary quota toggle: restore the network-wide
		// upload-space check flag even when the matrix exits abnormally.
		$xnetvn_agent_hub_wp_net_quota_backup = $xnetvn_agent_hub_wp_net_state['quota_backup'] ?? null;
		if ( is_array( $xnetvn_agent_hub_wp_net_quota_backup ) && array_key_exists( 'check_disabled', $xnetvn_agent_hub_wp_net_quota_backup ) ) {
			if ( '__role_seo_net_absent__' === $xnetvn_agent_hub_wp_net_quota_backup['check_disabled'] ) {
				delete_site_option( 'upload_space_check_disabled' );
			} else {
				update_site_option( 'upload_space_check_disabled', $xnetvn_agent_hub_wp_net_quota_backup['check_disabled'] );
			}
		}
		while ( array() !== $xnetvn_agent_hub_wp_net_state['ledger'] ) {
			$xnetvn_agent_hub_wp_net_entry = array_pop( $xnetvn_agent_hub_wp_net_state['ledger'] );
			$xnetvn_agent_hub_wp_net_type  = $xnetvn_agent_hub_wp_net_entry['type'] ?? '';
			$xnetvn_agent_hub_wp_net_id    = (int) ( $xnetvn_agent_hub_wp_net_entry['id'] ?? 0 );
			$xnetvn_agent_hub_wp_net_blog  = (int) ( $xnetvn_agent_hub_wp_net_entry['blog'] ?? 0 );
			if ( 'user' === $xnetvn_agent_hub_wp_net_type ) {
				$xnetvn_agent_hub_wp_net_profile = get_userdata( $xnetvn_agent_hub_wp_net_id );
				if ( false !== $xnetvn_agent_hub_wp_net_profile ) {
					if ( 1 !== preg_match( '/\Arole-seo-net-' . preg_quote( $xnetvn_agent_hub_wp_net_run, '/' ) . '-(?:[a-z_]+|super)\z/', $xnetvn_agent_hub_wp_net_profile->user_login ) ) {
						throw new RuntimeException( 'Role SEO network fixture cleanup binding mismatch.' );
					}
					if ( '1' !== (string) get_user_meta( $xnetvn_agent_hub_wp_net_id, $xnetvn_agent_hub_wp_net_meta_key, true ) ) {
						throw new RuntimeException( 'Role SEO network fixture cleanup binding mismatch.' );
					}
					foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_member_blog ) {
						if ( is_user_member_of_blog( $xnetvn_agent_hub_wp_net_id, $xnetvn_agent_hub_wp_net_member_blog ) ) {
							remove_user_from_blog( $xnetvn_agent_hub_wp_net_id, $xnetvn_agent_hub_wp_net_member_blog );
						}
					}
					$xnetvn_agent_hub_wp_net_remaining_blogs = get_blogs_of_user( $xnetvn_agent_hub_wp_net_id );
					if ( is_array( $xnetvn_agent_hub_wp_net_remaining_blogs ) && array() !== $xnetvn_agent_hub_wp_net_remaining_blogs ) {
						throw new RuntimeException( 'Role SEO network fixture user still has foreign membership.' );
					}
					if ( is_super_admin( $xnetvn_agent_hub_wp_net_id ) ) {
						revoke_super_admin( $xnetvn_agent_hub_wp_net_id );
					}
					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_net_id ) ) ) {
						throw new RuntimeException( 'Role SEO network fixture audit cleanup failed.' );
					}
					if ( ! wp_delete_user( $xnetvn_agent_hub_wp_net_id ) ) {
						throw new RuntimeException( 'Role SEO network fixture user cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_net_removed['users'];
				}
				xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
				continue;
			}
			xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blog,
				static function () use ( $xnetvn_agent_hub_wp_net_type, $xnetvn_agent_hub_wp_net_id, $xnetvn_agent_hub_wp_net_state, $xnetvn_agent_hub_wp_net_run, &$xnetvn_agent_hub_wp_net_removed ): void {
					global $wpdb;
					$xnetvn_agent_hub_wp_net_repo = new WpdbKeyRepository( $wpdb, new SystemClock() );
					if ( 'media' === $xnetvn_agent_hub_wp_net_type ) {
						$xnetvn_agent_hub_wp_net_attach = get_post( $xnetvn_agent_hub_wp_net_id );
						if ( null !== $xnetvn_agent_hub_wp_net_attach ) {
							if ( 'attachment' !== $xnetvn_agent_hub_wp_net_attach->post_type || ! in_array( (int) $xnetvn_agent_hub_wp_net_attach->post_author, $xnetvn_agent_hub_wp_net_state['users'], true ) ) {
								throw new RuntimeException( 'Role SEO network fixture cleanup binding mismatch.' );
							}
							if ( ! wp_delete_attachment( $xnetvn_agent_hub_wp_net_id, true ) ) {
								throw new RuntimeException( 'Role SEO network fixture media cleanup failed.' );
							}
							++$xnetvn_agent_hub_wp_net_removed['media'];
						}
					} elseif ( 'post' === $xnetvn_agent_hub_wp_net_type ) {
						$xnetvn_agent_hub_wp_net_post = get_post( $xnetvn_agent_hub_wp_net_id );
						if ( null !== $xnetvn_agent_hub_wp_net_post ) {
							if ( ! in_array( (int) $xnetvn_agent_hub_wp_net_post->post_author, $xnetvn_agent_hub_wp_net_state['users'], true ) ) {
								throw new RuntimeException( 'Role SEO network fixture cleanup binding mismatch.' );
							}
							if ( ! wp_delete_post( $xnetvn_agent_hub_wp_net_id, true ) ) {
								throw new RuntimeException( 'Role SEO network fixture post cleanup failed.' );
							}
							++$xnetvn_agent_hub_wp_net_removed['posts'];
						}
					} elseif ( 'term' === $xnetvn_agent_hub_wp_net_type ) {
						$xnetvn_agent_hub_wp_net_term = get_term( $xnetvn_agent_hub_wp_net_id );
						if ( null !== $xnetvn_agent_hub_wp_net_term && ! is_wp_error( $xnetvn_agent_hub_wp_net_term ) ) {
							$xnetvn_agent_hub_wp_net_deleted = wp_delete_term( $xnetvn_agent_hub_wp_net_id, $xnetvn_agent_hub_wp_net_term->taxonomy );
							if ( false === $xnetvn_agent_hub_wp_net_deleted || is_wp_error( $xnetvn_agent_hub_wp_net_deleted ) ) {
								throw new RuntimeException( 'Role SEO network fixture term cleanup failed.' );
							}
							++$xnetvn_agent_hub_wp_net_removed['terms'];
						}
					} elseif ( 'key' === $xnetvn_agent_hub_wp_net_type ) {
						$xnetvn_agent_hub_wp_net_row = $xnetvn_agent_hub_wp_net_repo->find_by_id( $xnetvn_agent_hub_wp_net_id );
						if ( null !== $xnetvn_agent_hub_wp_net_row ) {
							if ( ! in_array( $xnetvn_agent_hub_wp_net_row->user_id, $xnetvn_agent_hub_wp_net_state['users'], true ) || ! str_starts_with( $xnetvn_agent_hub_wp_net_row->name, 'Role SEO net ' ) ) {
								throw new RuntimeException( 'Role SEO network fixture cleanup binding mismatch.' );
							}
							if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_net_row->id, $xnetvn_agent_hub_wp_net_row->key_id->value, $xnetvn_agent_hub_wp_net_row->user_id ) ) || null !== $xnetvn_agent_hub_wp_net_repo->find_by_id( $xnetvn_agent_hub_wp_net_row->id ) ) {
								throw new RuntimeException( 'Role SEO network fixture key cleanup failed.' );
							}
							++$xnetvn_agent_hub_wp_net_removed['keys'];
						}
					}
				}
			);
			xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		}
		foreach ( $xnetvn_agent_hub_wp_net_blogs as $xnetvn_agent_hub_wp_net_blog ) {
			xnetvn_agent_hub_wp_net_on_blog(
				$xnetvn_agent_hub_wp_net_blog,
				static function (): void {
					$xnetvn_agent_hub_wp_net_trashed = get_posts(
						array(
							'post_type'   => array( 'post', 'attachment' ),
							'post_status' => 'trash',
							'numberposts' => -1,
							'fields'      => 'ids',
						)
					);
					foreach ( $xnetvn_agent_hub_wp_net_trashed as $xnetvn_agent_hub_wp_net_trash_id ) {
						wp_delete_post( (int) $xnetvn_agent_hub_wp_net_trash_id, true );
					}
				}
			);
		}
		$xnetvn_agent_hub_wp_net_state['restored'] = true;
		xnetvn_agent_hub_wp_net_save( $xnetvn_agent_hub_wp_net_state_file, $xnetvn_agent_hub_wp_net_state );
		xnetvn_agent_hub_wp_net_report(
			array(
				'restored' => true,
				'removed'  => $xnetvn_agent_hub_wp_net_removed,
			)
		);
		if ( 'cleanup' === $xnetvn_agent_hub_wp_net_action ) {
			if ( ! unlink( $xnetvn_agent_hub_wp_net_state_file ) ) {
				throw new RuntimeException( 'Role SEO network fixture file cleanup failed.' );
			}
		}
		return;
	}
} finally {
	if ( get_current_blog_id() !== $xnetvn_agent_hub_wp_net_current_blog && is_multisite() ) {
		restore_current_blog();
	}
}
