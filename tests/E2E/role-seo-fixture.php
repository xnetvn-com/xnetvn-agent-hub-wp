<?php
/**
 * WP-CLI control for the owned role/SEO fixture lifecycle.
 *
 * Fixed scenarios only: seed, verify, snapshot, restore, reconcile, counts
 * and cleanup. Credentials never reach stdout; reports carry safe counts and
 * digests.
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
use XNetVN\AgentHubWP\Support\SystemClock;
use XNetVN\AgentHubWP\Support\Json;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only test fixture files require definite POSIX permissions, not an interactive filesystem transport.

$xnetvn_agent_hub_wp_role_seo_action = $args[0] ?? 'seed';
if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_action, array( 'seed', 'verify', 'snapshot', 'restore', 'reconcile', 'counts', 'cleanup', 'role-swap', 'site-variant', 'key-temp', 'key-drop', 'cookie', 'content-digest', 'ban-verify', 'ban-reset', 'timezone', 'cron-due', 'cron-drift', 'lifecycle-fault', 'stability-barrier', 'stability-probe' ), true ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_role_seo_target = getenv( 'XNETVN_MATRIX_SITE' );
$xnetvn_agent_hub_wp_role_seo_run    = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
$xnetvn_agent_hub_wp_role_seo_blog   = getenv( 'XNETVN_ROLE_SEO_BLOG' );
$xnetvn_agent_hub_wp_role_seo_fail   = getenv( 'XNETVN_ROLE_SEO_FAIL_AFTER' );
// Network lab targets reuse the same per-blog flow: the runner passes the
// owned blog id and the blog URL, while the host guard below already
// accepts the network domain/alias. Matrix scripts keep their own target
// allowlist, so this alone does not change which suites can run where.
if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_target ) || ! in_array( $xnetvn_agent_hub_wp_role_seo_target, array( 'wp69', 'wp71', 'network-main', 'network-child' ), true ) ) {
	exit( 2 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_run ) || 1 !== preg_match( '/\A[a-z0-9-]{1,32}\z/', $xnetvn_agent_hub_wp_role_seo_run ) ) {
	exit( 2 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_blog ) || '' === $xnetvn_agent_hub_wp_role_seo_blog ) {
	$xnetvn_agent_hub_wp_role_seo_blog = '1';
}
if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_role_seo_blog ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_role_seo_blog_id = (int) $xnetvn_agent_hub_wp_role_seo_blog;
if ( 1 < $xnetvn_agent_hub_wp_role_seo_blog_id && ! is_multisite() ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_role_seo_fail_after = 0;
if ( is_string( $xnetvn_agent_hub_wp_role_seo_fail ) && '' !== $xnetvn_agent_hub_wp_role_seo_fail ) {
	if ( 'seed' !== $xnetvn_agent_hub_wp_role_seo_action || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_role_seo_fail ) ) {
		exit( 2 );
	}
	$xnetvn_agent_hub_wp_role_seo_fail_after = (int) $xnetvn_agent_hub_wp_role_seo_fail;
}
$xnetvn_agent_hub_wp_role_seo_allowed_hosts = array();
foreach ( array( getenv( 'RTL_ALIAS_WP69' ), getenv( 'RTL_ALIAS_WP71' ), getenv( 'XNETVN_RTL_ALIAS' ), getenv( 'XNETVN_RTL_DOMAIN' ), getenv( 'RTL_DOMAIN_WPNETWORK' ), getenv( 'RTL_ALIAS_WPNETWORK' ) ) as $xnetvn_agent_hub_wp_role_seo_host ) {
	if ( is_string( $xnetvn_agent_hub_wp_role_seo_host ) && '' !== $xnetvn_agent_hub_wp_role_seo_host ) {
		$xnetvn_agent_hub_wp_role_seo_allowed_hosts[] = $xnetvn_agent_hub_wp_role_seo_host;
	}
}
$xnetvn_agent_hub_wp_role_seo_home = parse_url( home_url(), PHP_URL_HOST );
if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_home ) || ! in_array( $xnetvn_agent_hub_wp_role_seo_home, $xnetvn_agent_hub_wp_role_seo_allowed_hosts, true ) ) {
	exit( 2 );
}

$xnetvn_agent_hub_wp_role_seo_aliases       = array(
	'admin'       => 'administrator',
	'editor'      => 'editor',
	'author'      => 'author',
	'author_b'    => 'author',
	'contributor' => 'contributor',
	'subscriber'  => 'subscriber',
	'banned'      => 'banned',
);
$xnetvn_agent_hub_wp_role_seo_keys          = array(
	'admin_full'       => array( 'admin', 'admin', array( 'system', 'content', 'media', 'taxonomy' ), array(), array() ),
	'admin_ban'        => array( 'admin', 'admin', array( 'system', 'users' ), array(), array() ),
	'editor_all'       => array( 'editor', 'destructive', array( 'system', 'content', 'media', 'taxonomy' ), array(), array() ),
	'author_write'     => array( 'author', 'write', array( 'system', 'content', 'media' ), array(), array( 'xnetvn/delete-post' ) ),
	'author_b_limited' => array( 'author_b', 'write', array( 'system', 'content' ), array( 'xnetvn/get-session-info' ), array() ),
	'contrib_write'    => array( 'contributor', 'write', array( 'system', 'content' ), array(), array( 'xnetvn/create-post' ) ),
	'sub_read'         => array( 'subscriber', 'read', array( 'system' ), array(), array() ),
	'banned_read'      => array( 'banned', 'read', array( 'system' ), array(), array() ),
);
$xnetvn_agent_hub_wp_role_seo_marker_option = 'xnetvn_agent_hub_wp_role_seo_fixture';
$xnetvn_agent_hub_wp_role_seo_sink_option   = 'xnetvn_agent_hub_wp_role_seo_mail_sink';
$xnetvn_agent_hub_wp_role_seo_mu_source     = '/work/tests/E2E/role-seo-mu-plugin.php';
$xnetvn_agent_hub_wp_role_seo_mu_file       = ABSPATH . 'wp-content/mu-plugins/xnetvn-role-seo.php';
$xnetvn_agent_hub_wp_role_seo_private_dir   = '/work/.local/role-seo/private/' . $xnetvn_agent_hub_wp_role_seo_target . '/' . $xnetvn_agent_hub_wp_role_seo_run;
$xnetvn_agent_hub_wp_role_seo_state_file    = $xnetvn_agent_hub_wp_role_seo_private_dir . '/state.json';

if ( is_link( $xnetvn_agent_hub_wp_role_seo_private_dir ) || is_link( $xnetvn_agent_hub_wp_role_seo_state_file ) ) {
	exit( 2 );
}

/**
 * Canonicalize a value for stable digests.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function xnetvn_agent_hub_wp_role_seo_c14n( mixed $value ): mixed {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_is_list( $value ) ) {
		return array_map( 'xnetvn_agent_hub_wp_role_seo_c14n', $value );
	}
	ksort( $value );
	foreach ( $value as $k => $v ) {
		$value[ $k ] = xnetvn_agent_hub_wp_role_seo_c14n( $v );
	}
	return $value;
}

/**
 * Digest a state fragment without secrets.
 *
 * @param mixed $value Value.
 * @return string
 */
function xnetvn_agent_hub_wp_role_seo_digest( mixed $value ): string {
	return hash( 'sha256', Json::encode( xnetvn_agent_hub_wp_role_seo_c14n( $value ) ) );
}

/**
 * Persist progress for interruption recovery.
 *
 * @param string              $file Private destination.
 * @param array<string,mixed> $state Ephemeral owned state.
 * @throws RuntimeException For unavailable fixture persistence.
 */
function xnetvn_agent_hub_wp_role_seo_save( string $file, #[SensitiveParameter] array $state ): void {
	if ( is_link( $file ) ) {
		throw new RuntimeException( 'Role SEO fixture state ownership mismatch.' );
	}
	if ( false === file_put_contents( $file, Json::encode( $state ), LOCK_EX ) ) {
		throw new RuntimeException( 'Role SEO fixture persistence failed.' );
	}
	chmod( $file, 0600 );
}

/**
 * Print one secret-free JSON report line.
 *
 * @param array<string,mixed> $report Safe report.
 */
function xnetvn_agent_hub_wp_role_seo_report( array $report ): void {
	WP_CLI::line( Json::encode( $report ) );
}

/**
 * Count site-wide fixture-relevant rows.
 *
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_role_seo_counts(): array {
	global $wpdb;
	$users = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->users );
	$keys  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' );
	$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post','attachment')" );
	$terms = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy );
	$media = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'" );
	return array(
		'users' => $users,
		'keys'  => $keys,
		'posts' => $posts,
		'terms' => $terms,
		'media' => $media,
	);
}

global $wpdb;
$xnetvn_agent_hub_wp_role_seo_keys_repo = new WpdbKeyRepository( $wpdb, new SystemClock() );
$xnetvn_agent_hub_wp_role_seo_state     = is_file( $xnetvn_agent_hub_wp_role_seo_state_file ) ? Json::array( file_get_contents( $xnetvn_agent_hub_wp_role_seo_state_file ) ) : array();

try {
	if ( 1 < $xnetvn_agent_hub_wp_role_seo_blog_id ) {
		switch_to_blog( $xnetvn_agent_hub_wp_role_seo_blog_id );
	}

	if ( 'counts' === $xnetvn_agent_hub_wp_role_seo_action ) {
		$xnetvn_agent_hub_wp_role_seo_current_settings = new WpSiteSettings();
		xnetvn_agent_hub_wp_role_seo_report(
			array_merge(
				array( 'counts' => true ),
				xnetvn_agent_hub_wp_role_seo_counts(),
				array(
					'settings_digest' => xnetvn_agent_hub_wp_role_seo_digest( $xnetvn_agent_hub_wp_role_seo_current_settings->all() ),
					'cron_digest'     => xnetvn_agent_hub_wp_role_seo_digest( get_option( 'cron', array() ) ),
				)
			)
		);
		return;
	}

	if ( 'reconcile' === $xnetvn_agent_hub_wp_role_seo_action ) {
		$xnetvn_agent_hub_wp_role_seo_remaining = array(
			'users' => 0,
			'keys'  => 0,
			'posts' => 0,
			'terms' => 0,
			'media' => 0,
		);
		if ( $xnetvn_agent_hub_wp_role_seo_state ) {
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['ledger'] ?? array() as $xnetvn_agent_hub_wp_role_seo_entry ) {
				$xnetvn_agent_hub_wp_role_seo_type = $xnetvn_agent_hub_wp_role_seo_entry['type'] ?? '';
				$xnetvn_agent_hub_wp_role_seo_id   = $xnetvn_agent_hub_wp_role_seo_entry['id'] ?? 0;
				if ( 'user' === $xnetvn_agent_hub_wp_role_seo_type && get_userdata( (int) $xnetvn_agent_hub_wp_role_seo_id ) instanceof WP_User ) {
					++$xnetvn_agent_hub_wp_role_seo_remaining['users'];
				} elseif ( 'key' === $xnetvn_agent_hub_wp_role_seo_type && null !== $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( (int) $xnetvn_agent_hub_wp_role_seo_id ) ) {
					++$xnetvn_agent_hub_wp_role_seo_remaining['keys'];
				} elseif ( ( 'post' === $xnetvn_agent_hub_wp_role_seo_type || 'media' === $xnetvn_agent_hub_wp_role_seo_type ) && null !== get_post( (int) $xnetvn_agent_hub_wp_role_seo_id ) ) {
					++$xnetvn_agent_hub_wp_role_seo_remaining[ 'media' === $xnetvn_agent_hub_wp_role_seo_type ? 'media' : 'posts' ];
				} elseif ( 'term' === $xnetvn_agent_hub_wp_role_seo_type && null !== get_term( (int) $xnetvn_agent_hub_wp_role_seo_id ) ) {
					++$xnetvn_agent_hub_wp_role_seo_remaining['terms'];
				}
			}
			if ( false !== get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false ) ) {
				++$xnetvn_agent_hub_wp_role_seo_remaining['users'];
			}
		}
		$xnetvn_agent_hub_wp_role_seo_clean = ( 0 === array_sum( $xnetvn_agent_hub_wp_role_seo_remaining ) );
		xnetvn_agent_hub_wp_role_seo_report(
			array(
				'reconciled' => $xnetvn_agent_hub_wp_role_seo_clean,
				'remaining'  => $xnetvn_agent_hub_wp_role_seo_remaining,
			)
		);
		if ( ! $xnetvn_agent_hub_wp_role_seo_clean ) {
			exit( 2 );
		}
		return;
	}

	if ( 'seed' === $xnetvn_agent_hub_wp_role_seo_action ) {
		if ( $xnetvn_agent_hub_wp_role_seo_state ) {
			$xnetvn_agent_hub_wp_role_seo_same = ( $xnetvn_agent_hub_wp_role_seo_state['run_id'] ?? '' ) === $xnetvn_agent_hub_wp_role_seo_run
				&& ( $xnetvn_agent_hub_wp_role_seo_state['target'] ?? '' ) === $xnetvn_agent_hub_wp_role_seo_target
				&& (int) ( $xnetvn_agent_hub_wp_role_seo_state['blog'] ?? 0 ) === $xnetvn_agent_hub_wp_role_seo_blog_id
				&& true !== ( $xnetvn_agent_hub_wp_role_seo_state['restored'] ?? false );
			if ( ! $xnetvn_agent_hub_wp_role_seo_same ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_idem = 7 === count( $xnetvn_agent_hub_wp_role_seo_state['users'] ?? array() ) && 8 === count( $xnetvn_agent_hub_wp_role_seo_state['keys'] ?? array() );
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['users'] ?? array() as $xnetvn_agent_hub_wp_role_seo_alias => $xnetvn_agent_hub_wp_role_seo_uid ) {
				$xnetvn_agent_hub_wp_role_seo_profile = get_userdata( (int) $xnetvn_agent_hub_wp_role_seo_uid );
				if ( ! $xnetvn_agent_hub_wp_role_seo_profile instanceof WP_User || 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_run . '-' . $xnetvn_agent_hub_wp_role_seo_alias !== $xnetvn_agent_hub_wp_role_seo_profile->user_login ) {
					$xnetvn_agent_hub_wp_role_seo_idem = false;
				}
			}
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['keys'] ?? array() as $xnetvn_agent_hub_wp_role_seo_key ) {
				if ( null === $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_role_seo_key['id'] ?? 0 ) ) ) {
					$xnetvn_agent_hub_wp_role_seo_idem = false;
				}
			}
			if ( ! $xnetvn_agent_hub_wp_role_seo_idem ) {
				exit( 2 );
			}
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'seeded'     => true,
					'idempotent' => true,
				)
			);
			return;
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_aliases as $xnetvn_agent_hub_wp_role_seo_alias => $xnetvn_agent_hub_wp_role_seo_role ) {
			if ( get_user_by( 'login', 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_run . '-' . $xnetvn_agent_hub_wp_role_seo_alias ) instanceof WP_User ) {
				exit( 2 );
			}
		}
		$xnetvn_agent_hub_wp_role_seo_marker = get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false );
		if ( false !== $xnetvn_agent_hub_wp_role_seo_marker ) {
			exit( 2 );
		}
		if ( file_exists( $xnetvn_agent_hub_wp_role_seo_mu_file ) || is_link( $xnetvn_agent_hub_wp_role_seo_mu_file ) ) {
			exit( 2 );
		}
		wp_mkdir_p( $xnetvn_agent_hub_wp_role_seo_private_dir );
		chmod( $xnetvn_agent_hub_wp_role_seo_private_dir, 0700 );
		$xnetvn_agent_hub_wp_role_seo_control  = bin2hex( random_bytes( 24 ) );
		$xnetvn_agent_hub_wp_role_seo_settings = new WpSiteSettings();
		$xnetvn_agent_hub_wp_role_seo_state    = array(
			'suite'    => 'role-seo-fixture',
			'target'   => $xnetvn_agent_hub_wp_role_seo_target,
			'blog'     => $xnetvn_agent_hub_wp_role_seo_blog_id,
			'run_id'   => $xnetvn_agent_hub_wp_role_seo_run,
			'control'  => $xnetvn_agent_hub_wp_role_seo_control,
			'snapshot' => array(
				'settings'        => $xnetvn_agent_hub_wp_role_seo_settings->all(),
				'settings_digest' => xnetvn_agent_hub_wp_role_seo_digest( $xnetvn_agent_hub_wp_role_seo_settings->all() ),
				'cron'            => get_option( 'cron', array() ),
				'cron_digest'     => xnetvn_agent_hub_wp_role_seo_digest( get_option( 'cron', array() ) ),
				'mu'              => 'absent',
			),
			'users'    => array(),
			'keys'     => array(),
			'tokens'   => array(),
			'content'  => array(
				'ids'   => array(),
				'posts' => array(),
				'terms' => array(),
				'media' => array(),
			),
			'ledger'   => array(),
			'restored' => false,
		);
		xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		update_option(
			$xnetvn_agent_hub_wp_role_seo_marker_option,
			array(
				'suite'   => 'role-seo-fixture',
				'run_id'  => $xnetvn_agent_hub_wp_role_seo_run,
				'target'  => $xnetvn_agent_hub_wp_role_seo_target,
				'blog'    => $xnetvn_agent_hub_wp_role_seo_blog_id,
				'control' => $xnetvn_agent_hub_wp_role_seo_control,
				'users'   => array(),
			),
			false
		);
		$xnetvn_agent_hub_wp_role_seo_made   = 0;
		$xnetvn_agent_hub_wp_role_seo_bump   = static function () use ( &$xnetvn_agent_hub_wp_role_seo_made, $xnetvn_agent_hub_wp_role_seo_fail_after ): void {
			++$xnetvn_agent_hub_wp_role_seo_made;
			if ( 0 < $xnetvn_agent_hub_wp_role_seo_fail_after && $xnetvn_agent_hub_wp_role_seo_made >= $xnetvn_agent_hub_wp_role_seo_fail_after ) {
				throw new RuntimeException( 'Role SEO seed fault injection.' );
			}
		};
		$xnetvn_agent_hub_wp_role_seo_banned = RoleGuard::ensure_banned_role();
		if ( 'banned' !== $xnetvn_agent_hub_wp_role_seo_banned ) {
			throw new RuntimeException( 'Role SEO banned role unavailable.' );
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_aliases as $xnetvn_agent_hub_wp_role_seo_alias => $xnetvn_agent_hub_wp_role_seo_role ) {
			$xnetvn_agent_hub_wp_role_seo_uid = wp_insert_user(
				array(
					'user_login' => 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_run . '-' . $xnetvn_agent_hub_wp_role_seo_alias,
					'user_pass'  => wp_generate_password( 48, true, true ),
					'user_email' => 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_alias . '-' . substr( $xnetvn_agent_hub_wp_role_seo_control, 0, 8 ) . '@example.test',
					'role'       => $xnetvn_agent_hub_wp_role_seo_role,
				)
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_role_seo_uid ) ) {
				throw new RuntimeException( 'Role SEO fixture user unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_alias ] = $xnetvn_agent_hub_wp_role_seo_uid;
			$xnetvn_agent_hub_wp_role_seo_state['ledger'][]                                     = array(
				'type' => 'user',
				'ref'  => $xnetvn_agent_hub_wp_role_seo_alias,
				'id'   => $xnetvn_agent_hub_wp_role_seo_uid,
			);
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			$xnetvn_agent_hub_wp_role_seo_bump();
		}
		$xnetvn_agent_hub_wp_role_seo_quota           = (int) $xnetvn_agent_hub_wp_role_seo_settings->get( 'max_keys_per_user' );
		$xnetvn_agent_hub_wp_role_seo_pepper          = ( new PepperStore() )->current();
		$xnetvn_agent_hub_wp_role_seo_tokens_by_owner = array();
		foreach ( $xnetvn_agent_hub_wp_role_seo_keys as $xnetvn_agent_hub_wp_role_seo_kind => $xnetvn_agent_hub_wp_role_seo_config ) {
			list( $xnetvn_agent_hub_wp_role_seo_owner, $xnetvn_agent_hub_wp_role_seo_tier, $xnetvn_agent_hub_wp_role_seo_toolsets, $xnetvn_agent_hub_wp_role_seo_allow, $xnetvn_agent_hub_wp_role_seo_deny ) = $xnetvn_agent_hub_wp_role_seo_config;
			$xnetvn_agent_hub_wp_role_seo_owner_id = $xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_owner ];
			$xnetvn_agent_hub_wp_role_seo_existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_role_seo_owner_id ) );
			if ( $xnetvn_agent_hub_wp_role_seo_existing >= $xnetvn_agent_hub_wp_role_seo_quota ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_generated = ( new KeyGenerator() )->generate();
			$xnetvn_agent_hub_wp_role_seo_row       = $xnetvn_agent_hub_wp_role_seo_keys_repo->insert(
				$xnetvn_agent_hub_wp_role_seo_generated->id,
				$xnetvn_agent_hub_wp_role_seo_owner_id,
				'Role SEO ' . $xnetvn_agent_hub_wp_role_seo_kind . ' ' . substr( $xnetvn_agent_hub_wp_role_seo_control, 0, 8 ),
				new Scope( RiskTier::from( $xnetvn_agent_hub_wp_role_seo_tier ), $xnetvn_agent_hub_wp_role_seo_toolsets, $xnetvn_agent_hub_wp_role_seo_allow, $xnetvn_agent_hub_wp_role_seo_deny, array(), new DateTimeImmutable( '+1 day' ) ),
				$xnetvn_agent_hub_wp_role_seo_generated->hash_with( $xnetvn_agent_hub_wp_role_seo_pepper ),
				$xnetvn_agent_hub_wp_role_seo_pepper->id
			);
			$xnetvn_agent_hub_wp_role_seo_token     = $xnetvn_agent_hub_wp_role_seo_generated->reveal();
			$xnetvn_agent_hub_wp_role_seo_state['keys'][ $xnetvn_agent_hub_wp_role_seo_kind ] = array(
				'id'     => $xnetvn_agent_hub_wp_role_seo_row->id,
				'key_id' => $xnetvn_agent_hub_wp_role_seo_row->key_id->value,
				'owner'  => $xnetvn_agent_hub_wp_role_seo_owner_id,
				'name'   => $xnetvn_agent_hub_wp_role_seo_row->name,
			);
			$xnetvn_agent_hub_wp_role_seo_state['ledger'][]                                   = array(
				'type' => 'key',
				'ref'  => $xnetvn_agent_hub_wp_role_seo_kind,
				'id'   => $xnetvn_agent_hub_wp_role_seo_row->id,
			);
			if ( ! isset( $xnetvn_agent_hub_wp_role_seo_tokens_by_owner[ $xnetvn_agent_hub_wp_role_seo_owner ] ) ) {
				$xnetvn_agent_hub_wp_role_seo_tokens_by_owner[ $xnetvn_agent_hub_wp_role_seo_owner ] = $xnetvn_agent_hub_wp_role_seo_token;
			}
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			$xnetvn_agent_hub_wp_role_seo_bump();
		}
		$xnetvn_agent_hub_wp_role_seo_state['tokens'] = $xnetvn_agent_hub_wp_role_seo_tokens_by_owner;
		unset( $xnetvn_agent_hub_wp_role_seo_tokens_by_owner, $xnetvn_agent_hub_wp_role_seo_token );
		xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		if ( ! copy( $xnetvn_agent_hub_wp_role_seo_mu_source, $xnetvn_agent_hub_wp_role_seo_mu_file ) ) {
			throw new RuntimeException( 'Role SEO fixture instrumentation could not be installed.' );
		}
		$xnetvn_agent_hub_wp_role_seo_state['mu'] = array(
			'file'   => $xnetvn_agent_hub_wp_role_seo_mu_file,
			'digest' => hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_source ),
		);
		xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		$xnetvn_agent_hub_wp_role_seo_short = substr( $xnetvn_agent_hub_wp_role_seo_control, 0, 8 );
		$xnetvn_agent_hub_wp_role_seo_posts = array(
			101 => array( 'editor', 'post' ),
			202 => array( 'author_b', 'post' ),
		);
		foreach ( $xnetvn_agent_hub_wp_role_seo_posts as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_spec ) {
			list( $xnetvn_agent_hub_wp_role_seo_post_owner, $xnetvn_agent_hub_wp_role_seo_post_type ) = $xnetvn_agent_hub_wp_role_seo_spec;
			$xnetvn_agent_hub_wp_role_seo_post_id = wp_insert_post(
				array(
					'post_title'  => 'Role SEO ' . $xnetvn_agent_hub_wp_role_seo_run . ' ' . $xnetvn_agent_hub_wp_role_seo_logical,
					'post_name'   => 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_short . '-' . $xnetvn_agent_hub_wp_role_seo_logical,
					'post_status' => 'draft',
					'post_author' => $xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_post_owner ],
					'post_type'   => $xnetvn_agent_hub_wp_role_seo_post_type,
				),
				true
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_role_seo_post_id ) ) {
				throw new RuntimeException( 'Role SEO fixture post unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_state['content']['ids'][ $xnetvn_agent_hub_wp_role_seo_logical ]   = $xnetvn_agent_hub_wp_role_seo_post_id;
			$xnetvn_agent_hub_wp_role_seo_state['content']['posts'][ $xnetvn_agent_hub_wp_role_seo_logical ] = $xnetvn_agent_hub_wp_role_seo_post_id;
			$xnetvn_agent_hub_wp_role_seo_state['ledger'][] = array(
				'type' => 'post',
				'ref'  => (string) $xnetvn_agent_hub_wp_role_seo_logical,
				'id'   => $xnetvn_agent_hub_wp_role_seo_post_id,
			);
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			$xnetvn_agent_hub_wp_role_seo_bump();
		}
		$xnetvn_agent_hub_wp_role_seo_terms = array(
			301 => array( 'category', 'Role SEO Cat' ),
			302 => array( 'post_tag', 'Role SEO Tag' ),
		);
		foreach ( $xnetvn_agent_hub_wp_role_seo_terms as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_spec ) {
			list( $xnetvn_agent_hub_wp_role_seo_taxonomy, $xnetvn_agent_hub_wp_role_seo_term_name ) = $xnetvn_agent_hub_wp_role_seo_spec;
			$xnetvn_agent_hub_wp_role_seo_term = wp_insert_term(
				$xnetvn_agent_hub_wp_role_seo_term_name . ' ' . $xnetvn_agent_hub_wp_role_seo_short,
				$xnetvn_agent_hub_wp_role_seo_taxonomy,
				array( 'slug' => 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_short . '-' . $xnetvn_agent_hub_wp_role_seo_logical )
			);
			if ( is_wp_error( $xnetvn_agent_hub_wp_role_seo_term ) ) {
				throw new RuntimeException( 'Role SEO fixture term unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_state['content']['ids'][ $xnetvn_agent_hub_wp_role_seo_logical ]   = $xnetvn_agent_hub_wp_role_seo_term['term_id'];
			$xnetvn_agent_hub_wp_role_seo_state['content']['terms'][ $xnetvn_agent_hub_wp_role_seo_logical ] = $xnetvn_agent_hub_wp_role_seo_term['term_id'];
			$xnetvn_agent_hub_wp_role_seo_state['ledger'][] = array(
				'type' => 'term',
				'ref'  => (string) $xnetvn_agent_hub_wp_role_seo_logical,
				'id'   => $xnetvn_agent_hub_wp_role_seo_term['term_id'],
			);
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			$xnetvn_agent_hub_wp_role_seo_bump();
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$xnetvn_agent_hub_wp_role_seo_pixel = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode a fixed 1px test pixel, never executable code.
		foreach ( array(
			401 => 'editor',
			402 => 'author_b',
		) as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_media_owner ) {
			$xnetvn_agent_hub_wp_role_seo_upload = wp_upload_bits( 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_short . '-' . $xnetvn_agent_hub_wp_role_seo_logical . '.png', null, $xnetvn_agent_hub_wp_role_seo_pixel );
			if ( ! empty( $xnetvn_agent_hub_wp_role_seo_upload['error'] ) ) {
				throw new RuntimeException( 'Role SEO fixture upload unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_attach_id = wp_insert_attachment(
				array(
					'post_title'     => 'Role SEO ' . $xnetvn_agent_hub_wp_role_seo_run . ' ' . $xnetvn_agent_hub_wp_role_seo_logical,
					'post_name'      => 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_short . '-' . $xnetvn_agent_hub_wp_role_seo_logical,
					'post_status'    => 'inherit',
					'post_author'    => $xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_media_owner ],
					'post_mime_type' => 'image/png',
				),
				$xnetvn_agent_hub_wp_role_seo_upload['file']
			);
			if ( 0 === $xnetvn_agent_hub_wp_role_seo_attach_id || is_wp_error( $xnetvn_agent_hub_wp_role_seo_attach_id ) ) {
				throw new RuntimeException( 'Role SEO fixture media unavailable.' );
			}
			wp_update_attachment_metadata( $xnetvn_agent_hub_wp_role_seo_attach_id, wp_generate_attachment_metadata( $xnetvn_agent_hub_wp_role_seo_attach_id, $xnetvn_agent_hub_wp_role_seo_upload['file'] ) );
			$xnetvn_agent_hub_wp_role_seo_state['content']['ids'][ $xnetvn_agent_hub_wp_role_seo_logical ]   = $xnetvn_agent_hub_wp_role_seo_attach_id;
			$xnetvn_agent_hub_wp_role_seo_state['content']['media'][ $xnetvn_agent_hub_wp_role_seo_logical ] = $xnetvn_agent_hub_wp_role_seo_attach_id;
			$xnetvn_agent_hub_wp_role_seo_state['ledger'][] = array(
				'type' => 'media',
				'ref'  => (string) $xnetvn_agent_hub_wp_role_seo_logical,
				'id'   => $xnetvn_agent_hub_wp_role_seo_attach_id,
			);
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			$xnetvn_agent_hub_wp_role_seo_bump();
		}
		unset( $xnetvn_agent_hub_wp_role_seo_pixel );
		update_option(
			$xnetvn_agent_hub_wp_role_seo_marker_option,
			array(
				'suite'   => 'role-seo-fixture',
				'run_id'  => $xnetvn_agent_hub_wp_role_seo_run,
				'target'  => $xnetvn_agent_hub_wp_role_seo_target,
				'blog'    => $xnetvn_agent_hub_wp_role_seo_blog_id,
				'control' => $xnetvn_agent_hub_wp_role_seo_control,
				'users'   => $xnetvn_agent_hub_wp_role_seo_state['users'],
			),
			false
		);
		xnetvn_agent_hub_wp_role_seo_report(
			array(
				'seeded'     => true,
				'idempotent' => false,
				'actors'     => count( $xnetvn_agent_hub_wp_role_seo_state['users'] ),
				'keys'       => count( $xnetvn_agent_hub_wp_role_seo_state['keys'] ),
				'content'    => count( $xnetvn_agent_hub_wp_role_seo_state['content']['ids'] ),
			)
		);
		return;
	}

	if ( 'snapshot' === $xnetvn_agent_hub_wp_role_seo_action ) {
		if ( ! $xnetvn_agent_hub_wp_role_seo_state ) {
			throw new RuntimeException( 'Role SEO fixture state unavailable.' );
		}
		$xnetvn_agent_hub_wp_role_seo_settings_all      = ( new WpSiteSettings() )->all();
		$xnetvn_agent_hub_wp_role_seo_state['snapshot'] = array(
			'settings'        => $xnetvn_agent_hub_wp_role_seo_settings_all,
			'settings_digest' => xnetvn_agent_hub_wp_role_seo_digest( $xnetvn_agent_hub_wp_role_seo_settings_all ),
			'cron'            => get_option( 'cron', array() ),
			'cron_digest'     => xnetvn_agent_hub_wp_role_seo_digest( get_option( 'cron', array() ) ),
			'mu'              => file_exists( $xnetvn_agent_hub_wp_role_seo_mu_file ) ? hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_file ) : 'absent',
		);
		xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		xnetvn_agent_hub_wp_role_seo_report(
			array(
				'snapshot'        => true,
				'settings_digest' => $xnetvn_agent_hub_wp_role_seo_state['snapshot']['settings_digest'],
				'cron_digest'     => $xnetvn_agent_hub_wp_role_seo_state['snapshot']['cron_digest'],
			)
		);
		return;
	}

	if ( in_array( $xnetvn_agent_hub_wp_role_seo_action, array( 'role-swap', 'site-variant', 'key-temp', 'key-drop', 'cookie', 'content-digest', 'ban-verify', 'ban-reset', 'timezone', 'cron-due', 'cron-drift', 'lifecycle-fault', 'stability-barrier', 'stability-probe' ), true ) ) {
		if ( ! $xnetvn_agent_hub_wp_role_seo_state ) {
			throw new RuntimeException( 'Role SEO fixture state unavailable.' );
		}
		$xnetvn_agent_hub_wp_role_seo_marker = get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false );
		if ( ! is_array( $xnetvn_agent_hub_wp_role_seo_marker ) || ( $xnetvn_agent_hub_wp_role_seo_marker['control'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_state['control'] || ( $xnetvn_agent_hub_wp_role_seo_marker['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_run ) {
			exit( 2 );
		}
		$xnetvn_agent_hub_wp_role_seo_sub = $args[1] ?? '';
		if ( 'role-swap' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_sub, array( 'demote', 'restore' ), true ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_author = (int) ( $xnetvn_agent_hub_wp_role_seo_state['users']['author'] ?? 0 );
			$xnetvn_agent_hub_wp_role_seo_user   = get_userdata( $xnetvn_agent_hub_wp_role_seo_author );
			if ( ! $xnetvn_agent_hub_wp_role_seo_user instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_current = array_values( (array) $xnetvn_agent_hub_wp_role_seo_user->roles );
			if ( 'demote' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				if ( array( 'author' ) !== $xnetvn_agent_hub_wp_role_seo_current ) {
					throw new RuntimeException( 'Role SEO fixture role mismatch.' );
				}
				$xnetvn_agent_hub_wp_role_seo_user->set_role( 'subscriber' );
			} else {
				if ( array( 'subscriber' ) !== $xnetvn_agent_hub_wp_role_seo_current ) {
					throw new RuntimeException( 'Role SEO fixture role mismatch.' );
				}
				$xnetvn_agent_hub_wp_role_seo_user->set_role( 'author' );
			}
			clean_user_cache( $xnetvn_agent_hub_wp_role_seo_author );
			$xnetvn_agent_hub_wp_role_seo_fresh = get_userdata( $xnetvn_agent_hub_wp_role_seo_author );
			if ( ! $xnetvn_agent_hub_wp_role_seo_fresh instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
			}
			xnetvn_agent_hub_wp_role_seo_report( array( 'role' => array_values( (array) $xnetvn_agent_hub_wp_role_seo_fresh->roles ) ) );
			return;
		}
		if ( 'site-variant' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_sub, array( 'ceiling-write', 'ceiling-admin', 'admin-off', 'admin-on', 'rate-high', 'rate-low', 'media-small', 'reset' ), true ) ) {
				exit( 2 );
			}
			if ( 'reset' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				( new WpSiteSettings() )->update( $xnetvn_agent_hub_wp_role_seo_state['snapshot']['settings'] );
				update_option( 'cron', $xnetvn_agent_hub_wp_role_seo_state['snapshot']['cron'], false );
				delete_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault' );
				delete_option( 'xnetvn_agent_hub_wp_role_seo_stability_barrier' );
				delete_option( 'xnetvn_agent_hub_wp_role_seo_stability_crash' );
				if ( isset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) && is_array( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) ) {
					update_option( 'timezone_string', (string) ( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['timezone_string'] ?? '' ) );
					update_option( 'gmt_offset', $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['gmt_offset'] ?? 0 );
					unset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] );
					xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
				}
			} elseif ( 'ceiling-write' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				( new WpSiteSettings() )->update( array( 'tier_ceiling' => 'write' ) );
			} elseif ( 'ceiling-admin' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				( new WpSiteSettings() )->update( array( 'tier_ceiling' => 'admin' ) );
			} elseif ( 'admin-off' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				( new WpSiteSettings() )->update( array( 'allow_admin_tier' => false ) );
			} elseif ( 'rate-high' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				( new WpSiteSettings() )->update(
					array(
						'rate_limit_auth_fail_per_min_ip' => 1000,
						'rate_limit_key_per_min'          => 1000,
					)
				);
			} elseif ( 'rate-low' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				// Owned tight key budget for the STAB-04 boundary: five
				// requests fit the window and the sixth is refused. The
				// auth-fail IP bucket is shared lab state and stays
				// untouched; reset restores the snapshot afterwards.
				( new WpSiteSettings() )->update(
					array(
						'rate_limit_key_per_min' => 5,
					)
				);
			} elseif ( 'media-small' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				// Owned small media ceilings for IMG-08: valid minima so the
				// matrix can upload at the ceiling and one byte over it with
				// tiny fixtures. The shared reset restores the snapshot.
				( new WpSiteSettings() )->update(
					array(
						'media_data_max_bytes' => 4096,
						'media_pixels_max'     => 1000000,
					)
				);
			} else {
				( new WpSiteSettings() )->update( array( 'allow_admin_tier' => true ) );
			}
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'variant'         => $xnetvn_agent_hub_wp_role_seo_sub,
					'settings_digest' => xnetvn_agent_hub_wp_role_seo_digest( ( new WpSiteSettings() )->all() ),
				)
			);
			return;
		}
		if ( 'key-temp' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub || isset( $xnetvn_agent_hub_wp_role_seo_state['temp_keys'] ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_quota                = (int) ( new WpSiteSettings() )->get( 'max_keys_per_user' );
			$xnetvn_agent_hub_wp_role_seo_pepper               = ( new PepperStore() )->current();
			$xnetvn_agent_hub_wp_role_seo_temp                 = array(
				'temp_read'         => array( 'contributor', RiskTier::Read, array( 'system', 'content' ), '+1 day', false ),
				'temp_contrib_open' => array( 'contributor', RiskTier::Write, array( 'system', 'content' ), '+1 day', false ),
				'temp_expired'      => array( 'author', RiskTier::Write, array( 'system', 'content', 'media' ), '-1 day', false ),
				'temp_revoked'      => array( 'author', RiskTier::Write, array( 'system', 'content', 'media' ), '+1 day', true ),
				'temp_admin'        => array( 'admin', RiskTier::Admin, array( 'system', 'users' ), '+1 day', false ),
			);
			$xnetvn_agent_hub_wp_role_seo_state['temp_keys']   = array();
			$xnetvn_agent_hub_wp_role_seo_state['temp_tokens'] = array();
			foreach ( $xnetvn_agent_hub_wp_role_seo_temp as $xnetvn_agent_hub_wp_role_seo_kind => $xnetvn_agent_hub_wp_role_seo_config ) {
				list( $xnetvn_agent_hub_wp_role_seo_owner, $xnetvn_agent_hub_wp_role_seo_tier, $xnetvn_agent_hub_wp_role_seo_toolsets, $xnetvn_agent_hub_wp_role_seo_expiry, $xnetvn_agent_hub_wp_role_seo_revoke ) = $xnetvn_agent_hub_wp_role_seo_config;
				$xnetvn_agent_hub_wp_role_seo_owner_id = (int) ( $xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_owner ] ?? 0 );
				if ( 1 > $xnetvn_agent_hub_wp_role_seo_owner_id ) {
					throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
				}
				$xnetvn_agent_hub_wp_role_seo_existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_role_seo_owner_id ) );
				if ( $xnetvn_agent_hub_wp_role_seo_existing >= $xnetvn_agent_hub_wp_role_seo_quota ) {
					exit( 2 );
				}
				$xnetvn_agent_hub_wp_role_seo_generated = ( new KeyGenerator() )->generate();
				$xnetvn_agent_hub_wp_role_seo_row       = $xnetvn_agent_hub_wp_role_seo_keys_repo->insert(
					$xnetvn_agent_hub_wp_role_seo_generated->id,
					$xnetvn_agent_hub_wp_role_seo_owner_id,
					'Role SEO temp ' . $xnetvn_agent_hub_wp_role_seo_kind . ' ' . substr( $xnetvn_agent_hub_wp_role_seo_state['control'], 0, 8 ),
					new Scope( $xnetvn_agent_hub_wp_role_seo_tier, $xnetvn_agent_hub_wp_role_seo_toolsets, array(), array(), array(), new DateTimeImmutable( $xnetvn_agent_hub_wp_role_seo_expiry ) ),
					$xnetvn_agent_hub_wp_role_seo_generated->hash_with( $xnetvn_agent_hub_wp_role_seo_pepper ),
					$xnetvn_agent_hub_wp_role_seo_pepper->id
				);
				if ( true === $xnetvn_agent_hub_wp_role_seo_revoke ) {
					$xnetvn_agent_hub_wp_role_seo_keys_repo->revoke( $xnetvn_agent_hub_wp_role_seo_row->id, (int) ( $xnetvn_agent_hub_wp_role_seo_state['users']['admin'] ?? 0 ), 'role_seo_fixture' );
				}
				$xnetvn_agent_hub_wp_role_seo_state['temp_keys'][ $xnetvn_agent_hub_wp_role_seo_kind ]   = array(
					'id'     => $xnetvn_agent_hub_wp_role_seo_row->id,
					'key_id' => $xnetvn_agent_hub_wp_role_seo_row->key_id->value,
					'owner'  => $xnetvn_agent_hub_wp_role_seo_owner_id,
					'name'   => $xnetvn_agent_hub_wp_role_seo_row->name,
				);
				$xnetvn_agent_hub_wp_role_seo_state['temp_tokens'][ $xnetvn_agent_hub_wp_role_seo_kind ] = $xnetvn_agent_hub_wp_role_seo_generated->reveal();
				$xnetvn_agent_hub_wp_role_seo_state['ledger'][] = array(
					'type' => 'key',
					'ref'  => $xnetvn_agent_hub_wp_role_seo_kind,
					'id'   => $xnetvn_agent_hub_wp_role_seo_row->id,
				);
				xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			}
			unset( $xnetvn_agent_hub_wp_role_seo_generated );
			xnetvn_agent_hub_wp_role_seo_report( array( 'temp_keys' => count( $xnetvn_agent_hub_wp_role_seo_state['temp_keys'] ) ) );
			return;
		}
		if ( 'key-drop' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub || ! isset( $xnetvn_agent_hub_wp_role_seo_state['temp_keys'] ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_dropped = 0;
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['temp_keys'] as $xnetvn_agent_hub_wp_role_seo_kind => $xnetvn_agent_hub_wp_role_seo_key ) {
				$xnetvn_agent_hub_wp_role_seo_row = $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_role_seo_key['id'] ?? 0 ) );
				if ( null !== $xnetvn_agent_hub_wp_role_seo_row ) {
					if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_row->user_id, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) || ! str_starts_with( $xnetvn_agent_hub_wp_role_seo_row->name, 'Role SEO temp ' ) ) {
						throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
					}
					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_role_seo_row->id, $xnetvn_agent_hub_wp_role_seo_row->key_id->value, $xnetvn_agent_hub_wp_role_seo_row->user_id ) ) || null !== $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( $xnetvn_agent_hub_wp_role_seo_row->id ) ) {
						throw new RuntimeException( 'Role SEO fixture key cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_dropped;
				}
			}
			$xnetvn_agent_hub_wp_role_seo_state['ledger'] = array_values(
				array_filter(
					$xnetvn_agent_hub_wp_role_seo_state['ledger'],
					static function ( array $entry ): bool {
						return ! ( 'key' === ( $entry['type'] ?? '' ) && is_string( $entry['ref'] ?? '' ) && str_starts_with( $entry['ref'], 'temp_' ) );
					}
				)
			);
			unset( $xnetvn_agent_hub_wp_role_seo_state['temp_keys'], $xnetvn_agent_hub_wp_role_seo_state['temp_tokens'] );
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			xnetvn_agent_hub_wp_role_seo_report( array( 'dropped' => $xnetvn_agent_hub_wp_role_seo_dropped ) );
			return;
		}
		if ( 'cookie' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_admin = (int) ( $xnetvn_agent_hub_wp_role_seo_state['users']['admin'] ?? 0 );
			if ( 1 > $xnetvn_agent_hub_wp_role_seo_admin ) {
				throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
			}
			wp_set_current_user( $xnetvn_agent_hub_wp_role_seo_admin );
			$xnetvn_agent_hub_wp_role_seo_session         = WP_Session_Tokens::get_instance( $xnetvn_agent_hub_wp_role_seo_admin )->create( time() + HOUR_IN_SECONDS );
			$xnetvn_agent_hub_wp_role_seo_cookie          = wp_generate_auth_cookie( $xnetvn_agent_hub_wp_role_seo_admin, time() + HOUR_IN_SECONDS, 'logged_in', $xnetvn_agent_hub_wp_role_seo_session );
			$_COOKIE[ LOGGED_IN_COOKIE ]                  = $xnetvn_agent_hub_wp_role_seo_cookie;
			$xnetvn_agent_hub_wp_role_seo_state['cookie'] = array(
				'cookie' => LOGGED_IN_COOKIE . '=' . $xnetvn_agent_hub_wp_role_seo_cookie,
				'nonce'  => wp_create_nonce( 'wp_rest' ),
			);
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			xnetvn_agent_hub_wp_role_seo_report( array( 'cookie' => true ) );
			return;
		}
		if ( 'content-digest' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_digests = array();
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['posts'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_post_id ) {
				$xnetvn_agent_hub_wp_role_seo_post = get_post( (int) $xnetvn_agent_hub_wp_role_seo_post_id );
				if ( ! $xnetvn_agent_hub_wp_role_seo_post instanceof WP_Post ) {
					throw new RuntimeException( 'Role SEO fixture content unavailable.' );
				}
				$xnetvn_agent_hub_wp_role_seo_digests[ $xnetvn_agent_hub_wp_role_seo_logical ] = array(
					'owner'  => (int) $xnetvn_agent_hub_wp_role_seo_post->post_author,
					'digest' => xnetvn_agent_hub_wp_role_seo_digest(
						array(
							'title'  => $xnetvn_agent_hub_wp_role_seo_post->post_title,
							'name'   => $xnetvn_agent_hub_wp_role_seo_post->post_name,
							'status' => $xnetvn_agent_hub_wp_role_seo_post->post_status,
							'author' => (int) $xnetvn_agent_hub_wp_role_seo_post->post_author,
							'type'   => $xnetvn_agent_hub_wp_role_seo_post->post_type,
						)
					),
				);
			}
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['media'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_attach_id ) {
				$xnetvn_agent_hub_wp_role_seo_attach = get_post( (int) $xnetvn_agent_hub_wp_role_seo_attach_id );
				if ( ! $xnetvn_agent_hub_wp_role_seo_attach instanceof WP_Post ) {
					throw new RuntimeException( 'Role SEO fixture content unavailable.' );
				}
				$xnetvn_agent_hub_wp_role_seo_digests[ $xnetvn_agent_hub_wp_role_seo_logical ] = array(
					'owner'  => (int) $xnetvn_agent_hub_wp_role_seo_attach->post_author,
					'digest' => xnetvn_agent_hub_wp_role_seo_digest(
						array(
							'title'  => $xnetvn_agent_hub_wp_role_seo_attach->post_title,
							'name'   => $xnetvn_agent_hub_wp_role_seo_attach->post_name,
							'status' => $xnetvn_agent_hub_wp_role_seo_attach->post_status,
							'author' => (int) $xnetvn_agent_hub_wp_role_seo_attach->post_author,
							'type'   => $xnetvn_agent_hub_wp_role_seo_attach->post_type,
						)
					),
				);
			}
			foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['terms'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_term_id ) {
				$xnetvn_agent_hub_wp_role_seo_term = get_term( (int) $xnetvn_agent_hub_wp_role_seo_term_id );
				if ( ! $xnetvn_agent_hub_wp_role_seo_term instanceof WP_Term ) {
					throw new RuntimeException( 'Role SEO fixture content unavailable.' );
				}
				$xnetvn_agent_hub_wp_role_seo_digests[ $xnetvn_agent_hub_wp_role_seo_logical ] = array(
					'owner'  => 0,
					'digest' => xnetvn_agent_hub_wp_role_seo_digest(
						array(
							'name'     => $xnetvn_agent_hub_wp_role_seo_term->name,
							'slug'     => $xnetvn_agent_hub_wp_role_seo_term->slug,
							'taxonomy' => $xnetvn_agent_hub_wp_role_seo_term->taxonomy,
						)
					),
				);
			}
			xnetvn_agent_hub_wp_role_seo_report( array( 'content' => $xnetvn_agent_hub_wp_role_seo_digests ) );
			return;
		}
		if ( 'ban-verify' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_author = (int) ( $xnetvn_agent_hub_wp_role_seo_state['users']['author'] ?? 0 );
			$xnetvn_agent_hub_wp_role_seo_user   = get_userdata( $xnetvn_agent_hub_wp_role_seo_author );
			if ( ! $xnetvn_agent_hub_wp_role_seo_user instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
			}
			if ( ! function_exists( 'xnetvn_agent_hub_wp_role_seo_session_count' ) ) {
				throw new RuntimeException( 'Role SEO session verifier unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_sessions = xnetvn_agent_hub_wp_role_seo_session_count( $xnetvn_agent_hub_wp_role_seo_state['control'], 'author' );
			$xnetvn_agent_hub_wp_role_seo_caps     = false;
			foreach ( (array) $xnetvn_agent_hub_wp_role_seo_user->allcaps as $xnetvn_agent_hub_wp_role_seo_cap => $xnetvn_agent_hub_wp_role_seo_enabled ) {
				if ( ! $xnetvn_agent_hub_wp_role_seo_enabled || 'banned' === (string) $xnetvn_agent_hub_wp_role_seo_cap ) {
					continue;
				}
				$xnetvn_agent_hub_wp_role_seo_caps = true;
			}
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'roles'      => array_values( (array) $xnetvn_agent_hub_wp_role_seo_user->roles ),
					'caps_empty' => ! $xnetvn_agent_hub_wp_role_seo_caps,
					'sessions'   => $xnetvn_agent_hub_wp_role_seo_sessions,
					'use'        => user_can( $xnetvn_agent_hub_wp_role_seo_author, Capabilities::USE ),
				)
			);
			return;
		}
		if ( 'ban-reset' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( '' !== $xnetvn_agent_hub_wp_role_seo_sub ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_author = (int) ( $xnetvn_agent_hub_wp_role_seo_state['users']['author'] ?? 0 );
			$xnetvn_agent_hub_wp_role_seo_user   = get_userdata( $xnetvn_agent_hub_wp_role_seo_author );
			if ( ! $xnetvn_agent_hub_wp_role_seo_user instanceof WP_User ) {
				throw new RuntimeException( 'Role SEO fixture actor unavailable.' );
			}
			if ( array( 'banned' ) !== array_values( (array) $xnetvn_agent_hub_wp_role_seo_user->roles ) ) {
				xnetvn_agent_hub_wp_role_seo_report( array( 'reset' => false ) );
				return;
			}
			$xnetvn_agent_hub_wp_role_seo_user->set_role( 'author' );
			BanSnapshot::clear( $xnetvn_agent_hub_wp_role_seo_author );
			clean_user_cache( $xnetvn_agent_hub_wp_role_seo_author );
			$xnetvn_agent_hub_wp_role_seo_fresh = get_userdata( $xnetvn_agent_hub_wp_role_seo_author );
			xnetvn_agent_hub_wp_role_seo_report( array( 'reset' => $xnetvn_agent_hub_wp_role_seo_fresh instanceof WP_User && array( 'author' ) === array_values( (array) $xnetvn_agent_hub_wp_role_seo_fresh->roles ) ) );
			return;
		}
		if ( 'timezone' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_sub, array( 'UTC', 'Asia/Ho_Chi_Minh', 'restore' ), true ) ) {
				exit( 2 );
			}
			if ( 'restore' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				if ( ! isset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) || ! is_array( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) ) {
					xnetvn_agent_hub_wp_role_seo_report( array( 'restored' => false ) );
					return;
				}
				update_option( 'timezone_string', (string) ( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['timezone_string'] ?? '' ) );
				update_option( 'gmt_offset', $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['gmt_offset'] ?? 0 );
				unset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] );
				xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
				xnetvn_agent_hub_wp_role_seo_report(
					array(
						'restored' => true,
						'zone'     => (string) get_option( 'timezone_string' ),
					)
				);
				return;
			}
			if ( ! isset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) ) {
				$xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] = array(
					'timezone_string' => (string) get_option( 'timezone_string' ),
					'gmt_offset'      => get_option( 'gmt_offset' ),
				);
				xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
			}
			update_option( 'timezone_string', $xnetvn_agent_hub_wp_role_seo_sub );
			update_option( 'gmt_offset', 'UTC' === $xnetvn_agent_hub_wp_role_seo_sub ? 0 : 7 );
			xnetvn_agent_hub_wp_role_seo_report( array( 'zone' => $xnetvn_agent_hub_wp_role_seo_sub ) );
			return;
		}
		if ( 'cron-due' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_role_seo_sub ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_owned = get_post( (int) $xnetvn_agent_hub_wp_role_seo_sub );
			if ( ! $xnetvn_agent_hub_wp_role_seo_owned instanceof WP_Post || 'post' !== $xnetvn_agent_hub_wp_role_seo_owned->post_type ) {
				throw new RuntimeException( 'Role SEO fixture content unavailable.' );
			}
			if ( ! in_array( (int) $xnetvn_agent_hub_wp_role_seo_owned->post_author, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) ) {
				throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
			}
			if ( 'future' !== $xnetvn_agent_hub_wp_role_seo_owned->post_status ) {
				throw new RuntimeException( 'Role SEO fixture content mismatch.' );
			}
			$xnetvn_agent_hub_wp_role_seo_due_id       = (int) $xnetvn_agent_hub_wp_role_seo_owned->ID;
			$xnetvn_agent_hub_wp_role_seo_count_events = static function () use ( $xnetvn_agent_hub_wp_role_seo_due_id ): array {
				$stamps = array();
				$total  = 0;
				foreach ( _get_cron_array() as $timestamp => $hooks ) {
					if ( ! is_array( $hooks ) || ! isset( $hooks['publish_future_post'] ) || ! is_array( $hooks['publish_future_post'] ) ) {
						continue;
					}
					foreach ( $hooks['publish_future_post'] as $event ) {
						++$total;
						if ( array( $xnetvn_agent_hub_wp_role_seo_due_id ) === array_values( (array) ( $event['args'] ?? array() ) ) ) {
							$stamps[] = (int) $timestamp;
						}
					}
				}
				return array( $stamps, $total );
			};
			list( $xnetvn_agent_hub_wp_role_seo_stamps, $xnetvn_agent_hub_wp_role_seo_total_before ) = $xnetvn_agent_hub_wp_role_seo_count_events();
			if ( array() === $xnetvn_agent_hub_wp_role_seo_stamps ) {
				throw new RuntimeException( 'Role SEO fixture cron event unavailable.' );
			}
			// The cron callback only publishes when the post date itself is
			// due, so the owned row is moved to a past date with a bounded
			// write that skips the save-time transition (core APIs would
			// auto-publish on a date change, pinned by
			// RoleSeoLifecycleTest); the status stays future. Only the
			// ledger-owned id is touched.
			global $wpdb;
			$xnetvn_agent_hub_wp_role_seo_moved = $wpdb->update(
				$wpdb->posts,
				array(
					'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				),
				array( 'ID' => $xnetvn_agent_hub_wp_role_seo_due_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $xnetvn_agent_hub_wp_role_seo_moved ) {
				throw new RuntimeException( 'Role SEO fixture content unavailable.' );
			}
			clean_post_cache( $xnetvn_agent_hub_wp_role_seo_due_id );
			if ( 'future' !== get_post_status( $xnetvn_agent_hub_wp_role_seo_due_id ) ) {
				throw new RuntimeException( 'Role SEO fixture content mismatch.' );
			}
			// Reschedule only the owned event to a due timestamp through
			// core APIs. The core action below is its only fire.
			foreach ( $xnetvn_agent_hub_wp_role_seo_stamps as $xnetvn_agent_hub_wp_role_seo_stamp ) {
				wp_unschedule_event( $xnetvn_agent_hub_wp_role_seo_stamp, 'publish_future_post', array( $xnetvn_agent_hub_wp_role_seo_due_id ) );
			}
			$xnetvn_agent_hub_wp_role_seo_due_at = time() - 60;
			if ( false === wp_schedule_single_event( $xnetvn_agent_hub_wp_role_seo_due_at, 'publish_future_post', array( $xnetvn_agent_hub_wp_role_seo_due_id ) ) ) {
				throw new RuntimeException( 'Role SEO fixture cron event unavailable.' );
			}
			list( $xnetvn_agent_hub_wp_role_seo_rescheduled, $xnetvn_agent_hub_wp_role_seo_total_due ) = $xnetvn_agent_hub_wp_role_seo_count_events();
			if ( array( $xnetvn_agent_hub_wp_role_seo_due_at ) !== $xnetvn_agent_hub_wp_role_seo_rescheduled ) {
				throw new RuntimeException( 'Role SEO fixture cron event unavailable.' );
			}
			// Unschedule only the owned post event, then fire the core
			// action for that post. No site-wide cron run happens here.
			foreach ( $xnetvn_agent_hub_wp_role_seo_rescheduled as $xnetvn_agent_hub_wp_role_seo_stamp ) {
				wp_unschedule_event( $xnetvn_agent_hub_wp_role_seo_stamp, 'publish_future_post', array( $xnetvn_agent_hub_wp_role_seo_due_id ) );
			}
			do_action( 'publish_future_post', $xnetvn_agent_hub_wp_role_seo_due_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fire the owned core cron event only.
			$xnetvn_agent_hub_wp_role_seo_status                = get_post_status( $xnetvn_agent_hub_wp_role_seo_due_id );
			list( , $xnetvn_agent_hub_wp_role_seo_total_after ) = $xnetvn_agent_hub_wp_role_seo_count_events();
			unset( $xnetvn_agent_hub_wp_role_seo_count_events );
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'due'            => 'publish' === $xnetvn_agent_hub_wp_role_seo_status,
					'status'         => $xnetvn_agent_hub_wp_role_seo_status,
					'events_before'  => $xnetvn_agent_hub_wp_role_seo_total_before,
					'events_after'   => $xnetvn_agent_hub_wp_role_seo_total_after,
					'events_removed' => count( $xnetvn_agent_hub_wp_role_seo_rescheduled ),
				)
			);
			return;
		}
		if ( 'cron-drift' === $xnetvn_agent_hub_wp_role_seo_action ) {
			// Drift the cron digest on purpose: a background cron run may
			// change it between the seed and the verify, and the fixture
			// must report that drift instead of aborting the phase. The
			// cleanup restores the snapshot of the run.
			$xnetvn_agent_hub_wp_role_seo_cron = get_option( 'cron', array() );
			if ( ! is_array( $xnetvn_agent_hub_wp_role_seo_cron ) ) {
				$xnetvn_agent_hub_wp_role_seo_cron = array();
			}
			$xnetvn_agent_hub_wp_role_seo_cron[ time() + 86400 ] = array(
				'xnetvn_agent_hub_wp_role_seo_drift' => array(
					array(
						'args'     => array(),
						'schedule' => false,
						'interval' => 0,
					),
				),
			);
			update_option( 'cron', $xnetvn_agent_hub_wp_role_seo_cron, false );
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'cron_drift' => xnetvn_agent_hub_wp_role_seo_digest( get_option( 'cron', array() ) ) !== ( $xnetvn_agent_hub_wp_role_seo_state['snapshot']['cron_digest'] ?? '' ),
				)
			);
			return;
		}
		if ( 'lifecycle-fault' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_sub, array( 'prepare-fail-once', 'none' ), true ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_fault_option = 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault';
			if ( 'none' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				delete_option( $xnetvn_agent_hub_wp_role_seo_fault_option );
				xnetvn_agent_hub_wp_role_seo_report( array( 'armed' => false ) );
				return;
			}
			update_option( $xnetvn_agent_hub_wp_role_seo_fault_option, array( 'prepare-fail-once' => true ), false );
			xnetvn_agent_hub_wp_role_seo_report( array( 'armed' => true ) );
			return;
		}
		if ( 'stability-barrier' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_sub, array( 'hold', 'hold-short', 'release', 'status', 'none' ), true ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_barrier_option = 'xnetvn_agent_hub_wp_role_seo_stability_barrier';
			$xnetvn_agent_hub_wp_role_seo_crash_option   = 'xnetvn_agent_hub_wp_role_seo_stability_crash';
			if ( 'none' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				delete_option( $xnetvn_agent_hub_wp_role_seo_barrier_option );
				delete_option( $xnetvn_agent_hub_wp_role_seo_crash_option );
				xnetvn_agent_hub_wp_role_seo_report( array( 'armed' => false ) );
				return;
			}
			if ( 'status' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				if ( ! function_exists( 'xnetvn_agent_hub_wp_role_seo_stability_status' ) ) {
					throw new RuntimeException( 'Role SEO stability instrumentation unavailable.' );
				}
				$xnetvn_agent_hub_wp_role_seo_barrier_status = xnetvn_agent_hub_wp_role_seo_stability_status();
				xnetvn_agent_hub_wp_role_seo_report(
					array(
						'waiting'         => $xnetvn_agent_hub_wp_role_seo_barrier_status['waiting'],
						'released'        => $xnetvn_agent_hub_wp_role_seo_barrier_status['released'],
						'crashed'         => $xnetvn_agent_hub_wp_role_seo_barrier_status['crashed'],
						'crash_mechanism' => $xnetvn_agent_hub_wp_role_seo_barrier_status['crash_mechanism'],
					)
				);
				return;
			}
			if ( 'release' === $xnetvn_agent_hub_wp_role_seo_sub ) {
				$xnetvn_agent_hub_wp_role_seo_barrier = get_option( $xnetvn_agent_hub_wp_role_seo_barrier_option, array() );
				if ( ! is_array( $xnetvn_agent_hub_wp_role_seo_barrier ) || array() === $xnetvn_agent_hub_wp_role_seo_barrier ) {
					xnetvn_agent_hub_wp_role_seo_report(
						array(
							'released' => false,
							'waiting'  => 0,
						)
					);
					return;
				}
				$xnetvn_agent_hub_wp_role_seo_barrier['release'] = true;
				update_option( $xnetvn_agent_hub_wp_role_seo_barrier_option, $xnetvn_agent_hub_wp_role_seo_barrier, false );
				xnetvn_agent_hub_wp_role_seo_report(
					array(
						'released' => true,
						'waiting'  => is_array( $xnetvn_agent_hub_wp_role_seo_barrier['holders'] ?? null ) ? count( $xnetvn_agent_hub_wp_role_seo_barrier['holders'] ) : 0,
					)
				);
				return;
			}
			update_option(
				$xnetvn_agent_hub_wp_role_seo_barrier_option,
				array(
					'mode'     => $xnetvn_agent_hub_wp_role_seo_sub,
					'holders'  => array(),
					'release'  => false,
					'deadline' => time() + ( 'hold' === $xnetvn_agent_hub_wp_role_seo_sub ? 120 : 15 ),
				),
				false
			);
			// A fresh barrier starts without a crash record: the record of an
			// earlier holder must never answer for this one.
			delete_option( $xnetvn_agent_hub_wp_role_seo_crash_option );
			xnetvn_agent_hub_wp_role_seo_report( array( 'armed' => true ) );
			return;
		}
		if ( 'stability-probe' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( 1 !== preg_match( '/\Around-[1-5]\z/', $xnetvn_agent_hub_wp_role_seo_sub ) ) {
				exit( 2 );
			}
			$xnetvn_agent_hub_wp_role_seo_probe_actor = $args[2] ?? '';
			if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_probe_actor ) || ! array_key_exists( $xnetvn_agent_hub_wp_role_seo_probe_actor, $xnetvn_agent_hub_wp_role_seo_aliases ) ) {
				exit( 2 );
			}
			if ( ! function_exists( 'xnetvn_agent_hub_wp_role_seo_stability_probe' ) ) {
				throw new RuntimeException( 'Role SEO stability instrumentation unavailable.' );
			}
			$xnetvn_agent_hub_wp_role_seo_probe = xnetvn_agent_hub_wp_role_seo_stability_probe( $xnetvn_agent_hub_wp_role_seo_state['control'], $xnetvn_agent_hub_wp_role_seo_probe_actor );
			if ( ! is_array( $xnetvn_agent_hub_wp_role_seo_probe ) || ! isset( $xnetvn_agent_hub_wp_role_seo_probe['baseline_bytes'], $xnetvn_agent_hub_wp_role_seo_probe['peak_bytes'], $xnetvn_agent_hub_wp_role_seo_probe['samples'] ) || ! is_int( $xnetvn_agent_hub_wp_role_seo_probe['baseline_bytes'] ) || ! is_int( $xnetvn_agent_hub_wp_role_seo_probe['peak_bytes'] ) || 1 !== $xnetvn_agent_hub_wp_role_seo_probe['samples'] || 0 > $xnetvn_agent_hub_wp_role_seo_probe['baseline_bytes'] || $xnetvn_agent_hub_wp_role_seo_probe['baseline_bytes'] > $xnetvn_agent_hub_wp_role_seo_probe['peak_bytes'] ) {
				throw new RuntimeException( 'Role SEO stability probe unavailable.' );
			}
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'baseline_bytes' => $xnetvn_agent_hub_wp_role_seo_probe['baseline_bytes'],
					'peak_bytes'     => $xnetvn_agent_hub_wp_role_seo_probe['peak_bytes'],
					'samples'        => 1,
				)
			);
			return;
		}
	}

	if ( 'verify' === $xnetvn_agent_hub_wp_role_seo_action ) {
		if ( ! $xnetvn_agent_hub_wp_role_seo_state ) {
			throw new RuntimeException( 'Role SEO fixture state unavailable.' );
		}
		$xnetvn_agent_hub_wp_role_seo_marker = get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false );
		if ( ! is_array( $xnetvn_agent_hub_wp_role_seo_marker ) || ( $xnetvn_agent_hub_wp_role_seo_marker['control'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_state['control'] || ( $xnetvn_agent_hub_wp_role_seo_marker['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_run ) {
			throw new RuntimeException( 'Role SEO fixture marker mismatch.' );
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_aliases as $xnetvn_agent_hub_wp_role_seo_alias => $xnetvn_agent_hub_wp_role_seo_role ) {
			$xnetvn_agent_hub_wp_role_seo_uid     = $xnetvn_agent_hub_wp_role_seo_state['users'][ $xnetvn_agent_hub_wp_role_seo_alias ] ?? 0;
			$xnetvn_agent_hub_wp_role_seo_profile = get_userdata( (int) $xnetvn_agent_hub_wp_role_seo_uid );
			if ( ! $xnetvn_agent_hub_wp_role_seo_profile instanceof WP_User || 'role-seo-' . $xnetvn_agent_hub_wp_role_seo_run . '-' . $xnetvn_agent_hub_wp_role_seo_alias !== $xnetvn_agent_hub_wp_role_seo_profile->user_login || ! in_array( $xnetvn_agent_hub_wp_role_seo_role, $xnetvn_agent_hub_wp_role_seo_profile->roles, true ) ) {
				throw new RuntimeException( 'Role SEO fixture actor mismatch.' );
			}
			if ( 'banned' === $xnetvn_agent_hub_wp_role_seo_alias ) {
				if ( $xnetvn_agent_hub_wp_role_seo_profile->has_cap( 'read' ) || user_can( (int) $xnetvn_agent_hub_wp_role_seo_uid, Capabilities::USE ) ) {
					throw new RuntimeException( 'Role SEO fixture banned actor mismatch.' );
				}
			}
		}
		if ( $xnetvn_agent_hub_wp_role_seo_state['users']['author'] === $xnetvn_agent_hub_wp_role_seo_state['users']['author_b'] ) {
			throw new RuntimeException( 'Role SEO fixture authors are not distinct.' );
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_state['keys'] ?? array() as $xnetvn_agent_hub_wp_role_seo_kind => $xnetvn_agent_hub_wp_role_seo_key ) {
			$xnetvn_agent_hub_wp_role_seo_row = $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_role_seo_key['id'] ?? 0 ) );
			if ( null === $xnetvn_agent_hub_wp_role_seo_row || $xnetvn_agent_hub_wp_role_seo_row->user_id !== (int) $xnetvn_agent_hub_wp_role_seo_key['owner'] || $xnetvn_agent_hub_wp_role_seo_row->name !== $xnetvn_agent_hub_wp_role_seo_key['name'] || ! in_array( $xnetvn_agent_hub_wp_role_seo_row->user_id, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) || ! str_starts_with( $xnetvn_agent_hub_wp_role_seo_row->name, 'Role SEO ' ) || $xnetvn_agent_hub_wp_role_seo_row->is_revoked() ) {
				throw new RuntimeException( 'Role SEO fixture key mismatch.' );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['posts'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_post_id ) {
			$xnetvn_agent_hub_wp_role_seo_post = get_post( (int) $xnetvn_agent_hub_wp_role_seo_post_id );
			if ( ! $xnetvn_agent_hub_wp_role_seo_post instanceof WP_Post || ! in_array( (int) $xnetvn_agent_hub_wp_role_seo_post->post_author, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) || 1 !== preg_match( '/-(' . (int) $xnetvn_agent_hub_wp_role_seo_logical . ')\z/', $xnetvn_agent_hub_wp_role_seo_post->post_name ) ) {
				throw new RuntimeException( 'Role SEO fixture content mismatch.' );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['media'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_attach_id ) {
			$xnetvn_agent_hub_wp_role_seo_attach = get_post( (int) $xnetvn_agent_hub_wp_role_seo_attach_id );
			if ( ! $xnetvn_agent_hub_wp_role_seo_attach instanceof WP_Post || 'attachment' !== $xnetvn_agent_hub_wp_role_seo_attach->post_type || ! in_array( (int) $xnetvn_agent_hub_wp_role_seo_attach->post_author, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) ) {
				throw new RuntimeException( 'Role SEO fixture media mismatch.' );
			}
		}
		foreach ( $xnetvn_agent_hub_wp_role_seo_state['content']['terms'] ?? array() as $xnetvn_agent_hub_wp_role_seo_logical => $xnetvn_agent_hub_wp_role_seo_term_id ) {
			$xnetvn_agent_hub_wp_role_seo_term = get_term( (int) $xnetvn_agent_hub_wp_role_seo_term_id );
			if ( ! $xnetvn_agent_hub_wp_role_seo_term instanceof WP_Term ) {
				throw new RuntimeException( 'Role SEO fixture term mismatch.' );
			}
		}
		$xnetvn_agent_hub_wp_role_seo_mu_actual   = file_exists( $xnetvn_agent_hub_wp_role_seo_mu_file ) ? hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_file ) : 'absent';
		$xnetvn_agent_hub_wp_role_seo_mu_expected = hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_source );
		if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_mu_actual ) || ! is_string( $xnetvn_agent_hub_wp_role_seo_mu_expected ) || ! hash_equals( $xnetvn_agent_hub_wp_role_seo_mu_expected, $xnetvn_agent_hub_wp_role_seo_mu_actual ) ) {
			throw new RuntimeException( 'Role SEO fixture instrumentation mismatch.' );
		}
		if ( ! function_exists( 'xnetvn_agent_hub_wp_role_seo_sink_count' ) ) {
			throw new RuntimeException( 'Role SEO mail sink unavailable.' );
		}
		$xnetvn_agent_hub_wp_role_seo_sink_before = xnetvn_agent_hub_wp_role_seo_sink_count();
		wp_mail( 'role-seo-sink-' . substr( $xnetvn_agent_hub_wp_role_seo_state['control'], 0, 8 ) . '@example.test', 'Role SEO sink probe', 'Probe body is never stored.' );
		if ( xnetvn_agent_hub_wp_role_seo_sink_count() !== $xnetvn_agent_hub_wp_role_seo_sink_before + 1 || 'example.test' !== xnetvn_agent_hub_wp_role_seo_sink_last_domain() ) {
			throw new RuntimeException( 'Role SEO mail sink mismatch.' );
		}
		if ( xnetvn_agent_hub_wp_role_seo_digest( ( new WpSiteSettings() )->all() ) !== ( $xnetvn_agent_hub_wp_role_seo_state['snapshot']['settings_digest'] ?? '' ) ) {
			throw new RuntimeException( 'Role SEO settings drift detected.' );
		}
		// A background cron run may change the cron digest between the seed
		// and the verify. The runner treats that drift as information, so the
		// fixture reports it as well and never aborts the phase for it.
		$xnetvn_agent_hub_wp_role_seo_cron_drift = xnetvn_agent_hub_wp_role_seo_digest( get_option( 'cron', array() ) ) !== ( $xnetvn_agent_hub_wp_role_seo_state['snapshot']['cron_digest'] ?? '' );
		xnetvn_agent_hub_wp_role_seo_report(
			array(
				'verified'   => true,
				'actors'     => count( $xnetvn_agent_hub_wp_role_seo_state['users'] ),
				'keys'       => count( $xnetvn_agent_hub_wp_role_seo_state['keys'] ),
				'content'    => count( $xnetvn_agent_hub_wp_role_seo_state['content']['ids'] ),
				'sink'       => true,
				'cron_drift' => $xnetvn_agent_hub_wp_role_seo_cron_drift,
			)
		);
		return;
	}

	if ( 'restore' === $xnetvn_agent_hub_wp_role_seo_action || 'cleanup' === $xnetvn_agent_hub_wp_role_seo_action ) {
		$xnetvn_agent_hub_wp_role_seo_removed = array(
			'users' => 0,
			'keys'  => 0,
			'posts' => 0,
			'terms' => 0,
			'media' => 0,
		);
		if ( ! $xnetvn_agent_hub_wp_role_seo_state ) {
			$xnetvn_agent_hub_wp_role_seo_marker = get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false );
			if ( false !== $xnetvn_agent_hub_wp_role_seo_marker ) {
				exit( 2 );
			}
			xnetvn_agent_hub_wp_role_seo_report(
				array(
					'restored' => true,
					'removed'  => $xnetvn_agent_hub_wp_role_seo_removed,
				)
			);
			if ( 'cleanup' === $xnetvn_agent_hub_wp_role_seo_action && file_exists( $xnetvn_agent_hub_wp_role_seo_state_file ) ) {
				unlink( $xnetvn_agent_hub_wp_role_seo_state_file );
			}
			return;
		}
		$xnetvn_agent_hub_wp_role_seo_marker = get_option( $xnetvn_agent_hub_wp_role_seo_marker_option, false );
		if ( false === $xnetvn_agent_hub_wp_role_seo_marker ) {
			// Resume an interrupted restore through the binding-checked loop below.
			if ( true === ( $xnetvn_agent_hub_wp_role_seo_state['restored'] ?? false ) && array() === ( $xnetvn_agent_hub_wp_role_seo_state['ledger'] ?? array( null ) ) ) {
				xnetvn_agent_hub_wp_role_seo_report(
					array(
						'restored' => true,
						'removed'  => $xnetvn_agent_hub_wp_role_seo_removed,
					)
				);
				if ( 'cleanup' === $xnetvn_agent_hub_wp_role_seo_action ) {
					if ( ! unlink( $xnetvn_agent_hub_wp_role_seo_state_file ) ) {
						throw new RuntimeException( 'Role SEO fixture file cleanup failed.' );
					}
				}
				return;
			}
		} elseif ( ! is_array( $xnetvn_agent_hub_wp_role_seo_marker ) || ( $xnetvn_agent_hub_wp_role_seo_marker['control'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_state['control'] || ( $xnetvn_agent_hub_wp_role_seo_marker['run_id'] ?? '' ) !== $xnetvn_agent_hub_wp_role_seo_run ) {
			exit( 2 );
		}
		if ( file_exists( $xnetvn_agent_hub_wp_role_seo_mu_file ) || is_link( $xnetvn_agent_hub_wp_role_seo_mu_file ) ) {
			$xnetvn_agent_hub_wp_role_seo_mu_actual   = hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_file );
			$xnetvn_agent_hub_wp_role_seo_mu_expected = hash_file( 'sha256', $xnetvn_agent_hub_wp_role_seo_mu_source );
			if ( ! is_string( $xnetvn_agent_hub_wp_role_seo_mu_actual ) || ! is_string( $xnetvn_agent_hub_wp_role_seo_mu_expected ) || ! hash_equals( $xnetvn_agent_hub_wp_role_seo_mu_expected, $xnetvn_agent_hub_wp_role_seo_mu_actual ) ) {
				throw new RuntimeException( 'Role SEO fixture instrumentation mismatch.' );
			}
			wp_delete_file( $xnetvn_agent_hub_wp_role_seo_mu_file );
			if ( file_exists( $xnetvn_agent_hub_wp_role_seo_mu_file ) || is_link( $xnetvn_agent_hub_wp_role_seo_mu_file ) ) {
				throw new RuntimeException( 'Role SEO fixture file cleanup failed.' );
			}
		}
		( new WpSiteSettings() )->update( $xnetvn_agent_hub_wp_role_seo_state['snapshot']['settings'] );
		update_option( 'cron', $xnetvn_agent_hub_wp_role_seo_state['snapshot']['cron'], false );
		delete_option( $xnetvn_agent_hub_wp_role_seo_marker_option );
		delete_option( $xnetvn_agent_hub_wp_role_seo_sink_option );
		delete_option( 'xnetvn_agent_hub_wp_role_seo_lifecycle_fault' );
		delete_option( 'xnetvn_agent_hub_wp_role_seo_stability_barrier' );
		delete_option( 'xnetvn_agent_hub_wp_role_seo_stability_crash' );
		if ( isset( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) && is_array( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot'] ) ) {
			update_option( 'timezone_string', (string) ( $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['timezone_string'] ?? '' ) );
			update_option( 'gmt_offset', $xnetvn_agent_hub_wp_role_seo_state['timezone_snapshot']['gmt_offset'] ?? 0 );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
		while ( array() !== $xnetvn_agent_hub_wp_role_seo_state['ledger'] ) {
			$xnetvn_agent_hub_wp_role_seo_entry = array_pop( $xnetvn_agent_hub_wp_role_seo_state['ledger'] );
			$xnetvn_agent_hub_wp_role_seo_type  = $xnetvn_agent_hub_wp_role_seo_entry['type'] ?? '';
			$xnetvn_agent_hub_wp_role_seo_id    = (int) ( $xnetvn_agent_hub_wp_role_seo_entry['id'] ?? 0 );
			if ( 'media' === $xnetvn_agent_hub_wp_role_seo_type ) {
				$xnetvn_agent_hub_wp_role_seo_attach = get_post( $xnetvn_agent_hub_wp_role_seo_id );
				if ( null !== $xnetvn_agent_hub_wp_role_seo_attach ) {
					if ( 'attachment' !== $xnetvn_agent_hub_wp_role_seo_attach->post_type || ! in_array( (int) $xnetvn_agent_hub_wp_role_seo_attach->post_author, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) ) {
						throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
					}
					if ( ! wp_delete_attachment( $xnetvn_agent_hub_wp_role_seo_id, true ) ) {
						throw new RuntimeException( 'Role SEO fixture media cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_removed['media'];
				}
			} elseif ( 'post' === $xnetvn_agent_hub_wp_role_seo_type ) {
				$xnetvn_agent_hub_wp_role_seo_post = get_post( $xnetvn_agent_hub_wp_role_seo_id );
				if ( null !== $xnetvn_agent_hub_wp_role_seo_post ) {
					if ( ! in_array( (int) $xnetvn_agent_hub_wp_role_seo_post->post_author, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) ) {
						throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
					}
					if ( ! wp_delete_post( $xnetvn_agent_hub_wp_role_seo_id, true ) ) {
						throw new RuntimeException( 'Role SEO fixture post cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_removed['posts'];
				}
			} elseif ( 'term' === $xnetvn_agent_hub_wp_role_seo_type ) {
				$xnetvn_agent_hub_wp_role_seo_term = get_term( $xnetvn_agent_hub_wp_role_seo_id );
				if ( null !== $xnetvn_agent_hub_wp_role_seo_term && ! is_wp_error( $xnetvn_agent_hub_wp_role_seo_term ) ) {
					$xnetvn_agent_hub_wp_role_seo_term_deleted = wp_delete_term( $xnetvn_agent_hub_wp_role_seo_id, $xnetvn_agent_hub_wp_role_seo_term->taxonomy );
					if ( false === $xnetvn_agent_hub_wp_role_seo_term_deleted || is_wp_error( $xnetvn_agent_hub_wp_role_seo_term_deleted ) ) {
						throw new RuntimeException( 'Role SEO fixture term cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_removed['terms'];
				}
			} elseif ( 'key' === $xnetvn_agent_hub_wp_role_seo_type ) {
				$xnetvn_agent_hub_wp_role_seo_row = $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( $xnetvn_agent_hub_wp_role_seo_id );
				if ( null !== $xnetvn_agent_hub_wp_role_seo_row ) {
					if ( ! in_array( $xnetvn_agent_hub_wp_role_seo_row->user_id, $xnetvn_agent_hub_wp_role_seo_state['users'], true ) || ! str_starts_with( $xnetvn_agent_hub_wp_role_seo_row->name, 'Role SEO ' ) ) {
						throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
					}
					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_role_seo_row->id, $xnetvn_agent_hub_wp_role_seo_row->key_id->value, $xnetvn_agent_hub_wp_role_seo_row->user_id ) ) || null !== $xnetvn_agent_hub_wp_role_seo_keys_repo->find_by_id( $xnetvn_agent_hub_wp_role_seo_row->id ) ) {
						throw new RuntimeException( 'Role SEO fixture key cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_removed['keys'];
				}
			} elseif ( 'user' === $xnetvn_agent_hub_wp_role_seo_type ) {
				$xnetvn_agent_hub_wp_role_seo_profile = get_userdata( $xnetvn_agent_hub_wp_role_seo_id );
				if ( false !== $xnetvn_agent_hub_wp_role_seo_profile ) {
					if ( 1 !== preg_match( '/\Arole-seo-' . preg_quote( $xnetvn_agent_hub_wp_role_seo_run, '/' ) . '-[a-z_]+\z/', $xnetvn_agent_hub_wp_role_seo_profile->user_login ) ) {
						throw new RuntimeException( 'Role SEO fixture cleanup binding mismatch.' );
					}
					if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_role_seo_id ) ) ) {
						throw new RuntimeException( 'Role SEO fixture audit cleanup failed.' );
					}
					if ( ! wp_delete_user( $xnetvn_agent_hub_wp_role_seo_id ) ) {
						throw new RuntimeException( 'Role SEO fixture user cleanup failed.' );
					}
					++$xnetvn_agent_hub_wp_role_seo_removed['users'];
				} elseif ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $xnetvn_agent_hub_wp_role_seo_id ) ) ) {
						throw new RuntimeException( 'Role SEO fixture audit cleanup failed.' );
				}
			}
			xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		}
		$xnetvn_agent_hub_wp_role_seo_state['restored'] = true;
		xnetvn_agent_hub_wp_role_seo_save( $xnetvn_agent_hub_wp_role_seo_state_file, $xnetvn_agent_hub_wp_role_seo_state );
		xnetvn_agent_hub_wp_role_seo_report(
			array(
				'restored' => true,
				'removed'  => $xnetvn_agent_hub_wp_role_seo_removed,
			)
		);
		if ( 'cleanup' === $xnetvn_agent_hub_wp_role_seo_action ) {
			if ( ! unlink( $xnetvn_agent_hub_wp_role_seo_state_file ) ) {
				throw new RuntimeException( 'Role SEO fixture file cleanup failed.' );
			}
		}
		return;
	}
} finally {
	if ( 1 < $xnetvn_agent_hub_wp_role_seo_blog_id && is_multisite() ) {
		restore_current_blog();
	}
}
