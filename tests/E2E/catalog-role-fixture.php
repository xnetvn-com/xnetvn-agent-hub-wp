<?php
/**
 * Owned fixture lifecycle for the whole-catalog role/blog matrix (S5).
 *
 * Fixed actions only: prepare, verify, catalog, snapshot, state, counts,
 * reconcile and cleanup. It never accepts code, SQL or an arbitrary path,
 * never prints a credential and never deletes an object that is not bound
 * to this exact campaign and an owned actor.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}

use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Support\SystemClock;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only test fixture files require definite POSIX permissions, not an interactive filesystem transport.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Script locals are not plugin globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Script locals are not core globals.
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- Fixture helpers fail closed with fixed messages.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Root-only fixture adjusts owned upload directories.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Owned fixture ledger binding and cleanup use prepared queries.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Decode one fixed test pixel, never executable code.

$xnetvn_agent_hub_wp_catalog_role_action = $args[0] ?? '';
if ( ! in_array( $xnetvn_agent_hub_wp_catalog_role_action, array( 'prepare', 'verify', 'catalog', 'snapshot', 'state', 'counts', 'reconcile', 'cleanup', 'variant', 'audit' ), true ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_catalog_role_declarations = require __DIR__ . '/catalog-role-cases.php';
if ( ! is_array( $xnetvn_agent_hub_wp_catalog_role_declarations ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_catalog_role_target   = getenv( 'XNETVN_MATRIX_TARGET' );
$xnetvn_agent_hub_wp_catalog_role_campaign = getenv( 'XNETVN_MATRIX_CAMPAIGN' );
$xnetvn_agent_hub_wp_catalog_role_blog     = getenv( 'XNETVN_MATRIX_BLOG' );
if ( ! is_string( $xnetvn_agent_hub_wp_catalog_role_target ) || ! xnetvn_agent_hub_wp_catalog_role_target_allowed( $xnetvn_agent_hub_wp_catalog_role_target ) ) {
	exit( 2 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_catalog_role_campaign ) || ! xnetvn_agent_hub_wp_catalog_role_campaign_valid( $xnetvn_agent_hub_wp_catalog_role_campaign ) ) {
	exit( 2 );
}
if ( ! is_string( $xnetvn_agent_hub_wp_catalog_role_blog ) || '' === $xnetvn_agent_hub_wp_catalog_role_blog ) {
	$xnetvn_agent_hub_wp_catalog_role_blog = '1';
}
if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_catalog_role_blog ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_catalog_role_blog_id = (int) $xnetvn_agent_hub_wp_catalog_role_blog;
if ( 1 < $xnetvn_agent_hub_wp_catalog_role_blog_id && ! is_multisite() ) {
	exit( 2 );
}
if ( in_array( $xnetvn_agent_hub_wp_catalog_role_target, array( 'network-main', 'network-child' ), true ) !== is_multisite() ) {
	exit( 2 );
}

$xnetvn_agent_hub_wp_catalog_role_allowed_hosts = array();
foreach ( array( getenv( 'RTL_ALIAS_WP69' ), getenv( 'RTL_ALIAS_WP71' ), getenv( 'RTL_ALIAS_WPNETWORK' ), getenv( 'RTL_DOMAIN_WPNETWORK' ), getenv( 'XNETVN_RTL_ALIAS' ), getenv( 'XNETVN_RTL_DOMAIN' ) ) as $xnetvn_agent_hub_wp_catalog_role_host ) {
	if ( is_string( $xnetvn_agent_hub_wp_catalog_role_host ) && '' !== $xnetvn_agent_hub_wp_catalog_role_host ) {
		$xnetvn_agent_hub_wp_catalog_role_allowed_hosts[] = $xnetvn_agent_hub_wp_catalog_role_host;
	}
}
$xnetvn_agent_hub_wp_catalog_role_home = parse_url( home_url(), PHP_URL_HOST );
if ( ! is_string( $xnetvn_agent_hub_wp_catalog_role_home ) || ! in_array( $xnetvn_agent_hub_wp_catalog_role_home, $xnetvn_agent_hub_wp_catalog_role_allowed_hosts, true ) ) {
	exit( 2 );
}

$xnetvn_agent_hub_wp_catalog_role_private_dir  = '/work/.local/catalog-role/private/' . $xnetvn_agent_hub_wp_catalog_role_target . '/' . $xnetvn_agent_hub_wp_catalog_role_campaign;
$xnetvn_agent_hub_wp_catalog_role_state_file   = $xnetvn_agent_hub_wp_catalog_role_private_dir . '/state.json';
$xnetvn_agent_hub_wp_catalog_role_catalog_file = $xnetvn_agent_hub_wp_catalog_role_private_dir . '/catalog.json';
$xnetvn_agent_hub_wp_catalog_role_journal_file = $xnetvn_agent_hub_wp_catalog_role_private_dir . '/journal.jsonl';

if ( is_link( $xnetvn_agent_hub_wp_catalog_role_private_dir ) || is_link( $xnetvn_agent_hub_wp_catalog_role_state_file ) || is_link( $xnetvn_agent_hub_wp_catalog_role_journal_file ) ) {
	exit( 2 );
}

global $wpdb;
$xnetvn_agent_hub_wp_catalog_role_keys_repo          = new WpdbKeyRepository( $wpdb, new SystemClock() );
$GLOBALS['xnetvn_catalog_role_fixture_declarations'] = $xnetvn_agent_hub_wp_catalog_role_declarations;
$GLOBALS['xnetvn_catalog_role_fixture_state_file']   = $xnetvn_agent_hub_wp_catalog_role_state_file;
$GLOBALS['xnetvn_catalog_role_fixture_catalog_file'] = $xnetvn_agent_hub_wp_catalog_role_catalog_file;
$GLOBALS['xnetvn_catalog_role_fixture_journal_file'] = $xnetvn_agent_hub_wp_catalog_role_journal_file;
$GLOBALS['xnetvn_catalog_role_fixture_private_dir']  = $xnetvn_agent_hub_wp_catalog_role_private_dir;
$GLOBALS['xnetvn_catalog_role_fixture_keys_repo']    = $xnetvn_agent_hub_wp_catalog_role_keys_repo;
$GLOBALS['xnetvn_catalog_role_fixture_target']       = $xnetvn_agent_hub_wp_catalog_role_target;
$GLOBALS['xnetvn_catalog_role_fixture_campaign']     = $xnetvn_agent_hub_wp_catalog_role_campaign;
$GLOBALS['xnetvn_catalog_role_fixture_blog_id']      = $xnetvn_agent_hub_wp_catalog_role_blog_id;
$xnetvn_agent_hub_wp_catalog_role_state              = is_file( $xnetvn_agent_hub_wp_catalog_role_state_file ) ? Json::array( (string) file_get_contents( $xnetvn_agent_hub_wp_catalog_role_state_file ) ) : array();

/**
 * Canonicalize a value for stable digests.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function xnetvn_agent_hub_wp_catalog_role_c14n( mixed $value ): mixed {
	if ( ! is_array( $value ) ) {
		return $value;
	}
	if ( array_is_list( $value ) ) {
		return array_map( 'xnetvn_agent_hub_wp_catalog_role_c14n', $value );
	}
	ksort( $value );
	foreach ( $value as $key => $item ) {
		$value[ $key ] = xnetvn_agent_hub_wp_catalog_role_c14n( $item );
	}
	return $value;
}

/**
 * Digest a state fragment without secrets.
 *
 * @param mixed $value Value.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_digest( mixed $value ): string {
	return hash( 'sha256', Json::encode( xnetvn_agent_hub_wp_catalog_role_c14n( $value ) ) );
}

/**
 * Persist progress for interruption recovery.
 *
 * @param string              $file Private destination.
 * @param array<string,mixed> $state Ephemeral owned state.
 * @throws RuntimeException For unavailable fixture persistence.
 */
function xnetvn_agent_hub_wp_catalog_role_save( string $file, #[SensitiveParameter] array $state ): void {
	if ( is_link( $file ) ) {
		throw new RuntimeException( 'Catalog role fixture state ownership mismatch.' );
	}
	if ( false === file_put_contents( $file, Json::encode( $state ), LOCK_EX ) ) {
		throw new RuntimeException( 'Catalog role fixture persistence failed.' );
	}
	chmod( $file, 0600 );
}

/**
 * Print one secret-free JSON report line.
 *
 * @param array<string,mixed> $report Safe report.
 */
function xnetvn_agent_hub_wp_catalog_role_report( array $report ): void {
	WP_CLI::line( Json::encode( $report ) );
}

/**
 * Count site-wide fixture-relevant rows.
 *
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_catalog_role_counts(): array {
	global $wpdb;
	return array(
		'users'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->users ),
		'keys'     => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ),
		'posts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type IN ('post','attachment')" ),
		'terms'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->term_taxonomy ),
		'media'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment'" ),
		'comments' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->comments ),
	);
}

/**
 * Owned user ids recorded in the ledger.
 *
 * @param array<string,mixed> $state State.
 * @return array<int>
 */
function xnetvn_agent_hub_wp_catalog_role_owned_users( array $state ): array {
	$ids = array();
	foreach ( $state['users'] ?? array() as $id ) {
		if ( is_int( $id ) && 1 <= $id ) {
			$ids[] = $id;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Count owned objects from the ledger.
 *
 * @param array<string,mixed> $state State.
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_catalog_role_owned_counts( array $state ): array {
	$counts = array(
		'users'    => 0,
		'keys'     => 0,
		'posts'    => 0,
		'terms'    => 0,
		'media'    => 0,
		'comments' => 0,
	);
	$map    = array(
		'user'    => 'users',
		'key'     => 'keys',
		'post'    => 'posts',
		'term'    => 'terms',
		'media'   => 'media',
		'comment' => 'comments',
	);
	foreach ( $state['ledger'] ?? array() as $entry ) {
		$type = (string) ( $entry['type'] ?? '' );
		if ( isset( $map[ $type ] ) ) {
			++$counts[ $map[ $type ] ];
		}
	}
	return $counts;
}

/**
 * Count comments in purge statuses that this run does not own.
 *
 * @param array<string,mixed> $state State.
 * @return array<string,int>
 */
function xnetvn_agent_hub_wp_catalog_role_foreign_purge( array $state ): array {
	$owned = array();
	foreach ( $state['ledger'] ?? array() as $entry ) {
		if ( 'comment' === ( $entry['type'] ?? '' ) ) {
			$owned[] = (int) ( $entry['id'] ?? 0 );
		}
	}
	$owned  = array_values( array_unique( array_filter( $owned ) ) );
	$query  = new WP_Comment_Query();
	$rows   = $query->query(
		array(
			'status'        => array( 'spam', 'trash' ),
			'fields'        => 'ids',
			'number'        => 0,
			'orderby'       => 'comment_ID',
			'order'         => 'ASC',
			'no_found_rows' => true,
		)
	);
	$counts = array(
		'spam'  => 0,
		'trash' => 0,
	);
	foreach ( (array) $rows as $id ) {
		$comment = get_comment( (int) $id );
		if ( ! $comment instanceof WP_Comment ) {
			continue;
		}
		if ( ! in_array( (int) $id, $owned, true ) ) {
			if ( 'spam' === $comment->comment_approved ) {
				++$counts['spam'];
			} elseif ( 'trash' === $comment->comment_approved ) {
				++$counts['trash'];
			}
		}
	}
	return $counts;
}

/**
 * Digest only owned object identity and status, never content.
 *
 * @param array<string,mixed> $state State.
 * @return string
 */
function xnetvn_agent_hub_wp_catalog_role_state_digest( array $state ): string {
	$rows = array();
	foreach ( $state['ledger'] ?? array() as $entry ) {
		$type = (string) ( $entry['type'] ?? '' );
		$id   = (int) ( $entry['id'] ?? 0 );
		if ( 1 > $id ) {
			continue;
		}
		if ( 'post' === $type ) {
			$post   = get_post( $id );
			$rows[] = array(
				'type'   => $type,
				'id'     => $id,
				'status' => null === $post ? 'missing' : $post->post_status,
				'owner'  => null === $post ? 0 : (int) $post->post_author,
			);
		} elseif ( 'media' === $type ) {
			$media  = get_post( $id );
			$rows[] = array(
				'type'   => $type,
				'id'     => $id,
				'status' => null === $media ? 'missing' : $media->post_status,
				'owner'  => null === $media ? 0 : (int) $media->post_author,
			);
		} elseif ( 'term' === $type ) {
			$term   = get_term( $id );
			$rows[] = array(
				'type'   => $type,
				'id'     => $id,
				'status' => is_wp_error( $term ) || null === $term ? 'missing' : $term->taxonomy,
			);
		} elseif ( 'comment' === $type ) {
			$comment = get_comment( $id );
			$rows[]  = array(
				'type'   => $type,
				'id'     => $id,
				'status' => null === $comment ? 'missing' : (string) $comment->comment_approved,
			);
		} elseif ( 'user' === $type ) {
			$user   = get_userdata( $id );
			$rows[] = array(
				'type'   => $type,
				'id'     => $id,
				'status' => false === $user ? 'missing' : implode( ',', (array) $user->roles ),
			);
		} elseif ( 'key' === $type ) {
			$rows[] = array(
				'type'   => $type,
				'id'     => $id,
				'status' => null === $GLOBALS['xnetvn_catalog_role_fixture_keys_repo']->find_by_id( $id ) ? 'missing' : 'present',
			);
		}
	}
	return xnetvn_agent_hub_wp_catalog_role_digest( $rows );
}

/**
 * Build the fixture report fragments shared by state and counts.
 *
 * @param array<string,mixed> $state State.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_catalog_role_state_report( array $state ): array {
	$settings = new WpSiteSettings();
	$mail     = array(
		'count'  => (int) get_option( 'xnetvn_catalog_role_mail_count', 0 ),
		'domain' => (string) get_option( 'xnetvn_catalog_role_mail_domain', '' ),
	);
	return array(
		'target'          => (string) ( $state['target'] ?? '' ),
		'campaign'        => (string) ( $state['campaign'] ?? '' ),
		'blog'            => (int) ( $state['blog'] ?? 0 ),
		'network'         => is_multisite(),
		'counts'          => xnetvn_agent_hub_wp_catalog_role_counts(),
		'owned'           => xnetvn_agent_hub_wp_catalog_role_owned_counts( $state ),
		'foreign_purge'   => xnetvn_agent_hub_wp_catalog_role_foreign_purge( $state ),
		'settings_digest' => xnetvn_agent_hub_wp_catalog_role_digest( $settings->all() ),
		'cron_digest'     => xnetvn_agent_hub_wp_catalog_role_digest( get_option( 'cron', array() ) ),
		'admin_tier'      => 'admin' === $settings->get( 'tier_ceiling' ) && true === $settings->get( 'allow_admin_tier' ),
		'mail'            => $mail,
		'state_digest'    => xnetvn_agent_hub_wp_catalog_role_state_digest( $state ),
		'journal_entries' => xnetvn_agent_hub_wp_catalog_role_journal_count(),
		'catalog_digest'  => is_file( $GLOBALS['xnetvn_catalog_role_fixture_catalog_file'] ) ? hash_file( 'sha256', $GLOBALS['xnetvn_catalog_role_fixture_catalog_file'] ) : '',
	);
}

/**
 * Count journal lines without exposing them.
 *
 * @return int
 */
function xnetvn_agent_hub_wp_catalog_role_journal_count(): int {
	$file = $GLOBALS['xnetvn_catalog_role_fixture_journal_file'];
	if ( ! is_file( $file ) || is_link( $file ) ) {
		return 0;
	}
	$raw = file_get_contents( $file );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return 0;
	}
	return count( array_filter( explode( "\n", trim( $raw ) ) ) );
}

/**
 * Require a state file bound to this exact run.
 *
 * @param array<string,mixed> $state State.
 * @return array<string,mixed>
 * @throws RuntimeException For a missing or foreign state.
 */
function xnetvn_agent_hub_wp_catalog_role_require_state( array $state ): array {
	if ( array() === $state ) {
		throw new RuntimeException( 'Catalog role fixture state unavailable.' );
	}
	if ( 'catalog-role-fixture' !== ( $state['suite'] ?? '' ) || ( $state['target'] ?? '' ) !== $GLOBALS['xnetvn_catalog_role_fixture_target'] || ( $state['campaign'] ?? '' ) !== $GLOBALS['xnetvn_catalog_role_fixture_campaign'] || (int) ( $state['blog'] ?? 0 ) !== (int) $GLOBALS['xnetvn_catalog_role_fixture_blog_id'] ) {
		throw new RuntimeException( 'Catalog role fixture state ownership mismatch.' );
	}
	return $state;
}

/**
 * Verify the marker option binds this exact state.
 *
 * @param array<string,mixed> $state State.
 * @throws RuntimeException For a missing or foreign marker.
 */
function xnetvn_agent_hub_wp_catalog_role_marker_ok( array $state ): void {
	$marker = get_option( 'xnetvn_agent_hub_wp_catalog_role_fixture', false );
	if ( ! is_array( $marker ) || ( $marker['suite'] ?? '' ) !== 'catalog-role-fixture' || ( $marker['control'] ?? '' ) !== ( $state['control'] ?? '' ) || ( $marker['campaign'] ?? '' ) !== ( $state['campaign'] ?? '' ) || ( $marker['target'] ?? '' ) !== ( $state['target'] ?? '' ) ) {
		throw new RuntimeException( 'Catalog role fixture marker mismatch.' );
	}
}

/**
 * Create one owned user and record it.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $alias Alias.
 * @param array<string,mixed> $spec  User specification.
 * @param string              $control Random control prefix.
 * @return int
 */
function xnetvn_agent_hub_wp_catalog_role_user( array &$state, string $alias, array $spec, string $control ): int {
	$campaign = (string) $state['campaign'];
	$login    = 'catalog-role-' . $campaign . '-' . $alias;
	if ( get_user_by( 'login', $login ) instanceof WP_User ) {
		throw new RuntimeException( 'Catalog role fixture user collision.' );
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 48, true, true ),
			'user_email' => 'catalog-role-' . $alias . '-' . substr( $control, 0, 8 ) . '@example.test',
			'role'       => (string) $spec['role'],
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Catalog role fixture user unavailable.' );
	}
	$state['users'][ $alias ] = (int) $id;
	$state['ledger'][]        = array(
		'type' => 'user',
		'ref'  => $alias,
		'id'   => (int) $id,
	);
	if ( true === ( $spec['super'] ?? false ) ) {
		if ( ! is_multisite() ) {
			throw new RuntimeException( 'Catalog role super admin requires a network.' );
		}
		if ( ! grant_super_admin( (int) $id ) ) {
			throw new RuntimeException( 'Catalog role super admin grant failed.' );
		}
		$state['super_admin'] = (int) $id;
	}
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
	return (int) $id;
}

/**
 * Create one owned key and record its safe metadata plus private token.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $alias Key alias.
 * @param array<string,mixed> $spec  Key specification.
 * @param string              $control Random control prefix.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_key( array &$state, string $alias, array $spec, string $control ): void {
	global $wpdb;
	$repo     = $GLOBALS['xnetvn_catalog_role_fixture_keys_repo'];
	$owner    = (int) ( $state['users'][ (string) $spec['actor'] ] ?? 0 );
	$settings = new WpSiteSettings();
	if ( 1 > $owner ) {
		throw new RuntimeException( 'Catalog role fixture key owner unavailable.' );
	}
	$quota    = (int) $settings->get( 'max_keys_per_user' );
	$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $owner ) );
	if ( $existing >= $quota ) {
		throw new RuntimeException( 'Catalog role fixture key quota exceeded.' );
	}
	$pepper                    = ( new PepperStore() )->current();
	$generated                 = ( new KeyGenerator() )->generate();
	$row                       = $repo->insert(
		$generated->id,
		$owner,
		'Catalog Role ' . $alias . ' ' . substr( $control, 0, 8 ),
		new Scope(
			RiskTier::from( (string) $spec['tier'] ),
			(array) $spec['toolsets'],
			(array) ( $spec['allow'] ?? array() ),
			(array) ( $spec['deny'] ?? array() ),
			array(),
			new DateTimeImmutable( '+1 day' )
		),
		$generated->hash_with( $pepper ),
		$pepper->id
	);
	$state['keys'][ $alias ]   = array(
		'id'     => $row->id,
		'key_id' => $row->key_id->value,
		'owner'  => $owner,
		'name'   => $row->name,
	);
	$state['tokens'][ $alias ] = $generated->reveal();
	$state['ledger'][]         = array(
		'type' => 'key',
		'ref'  => $alias,
		'id'   => $row->id,
	);
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Install the request-scoped mail sink and registered post meta.
 *
 * @param array<string,mixed> $state State, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_install_mu( array &$state ): void {
	$file = ABSPATH . 'wp-content/mu-plugins/xnetvn-catalog-role.php';
	if ( file_exists( $file ) || is_link( $file ) ) {
		throw new RuntimeException( 'Catalog role fixture instrumentation collision.' );
	}
	wp_mkdir_p( dirname( $file ) );
	$source = <<<'PHP'
<?php
/**
 * Test-only mail sink and registered meta for the catalog role matrix.
 */
add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		$to      = is_array( $atts['to'] ?? null ) ? implode( ',', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
		$count   = (int) get_option( 'xnetvn_catalog_role_mail_count', 0 ) + 1;
		$domain  = '';
		if ( 1 === preg_match( '/@([A-Za-z0-9.-]+)\z/', $to, $match ) ) {
			$domain = strtolower( $match[1] );
		}
		update_option( 'xnetvn_catalog_role_mail_count', $count, false );
		update_option( 'xnetvn_catalog_role_mail_domain', $domain, false );
		return true;
	},
	10,
	2
);
add_action(
	'init',
	static function (): void {
		register_post_meta(
			'post',
			'catalog_role_note',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => static fn(): bool => true,
			)
		);
	}
);
PHP;
	if ( false === file_put_contents( $file, $source, LOCK_EX ) ) {
		throw new RuntimeException( 'Catalog role fixture instrumentation failed.' );
	}
	chmod( $file, 0644 );
	$state['mu'] = array(
		'file'   => $file,
		'digest' => hash_file( 'sha256', $file ),
	);
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Create one owned post target and optional revision.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $key   Target key.
 * @param array<string,mixed> $spec  Target specification.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_post( array &$state, string $key, array $spec ): void {
	$campaign = (string) $state['campaign'];
	$owner    = (int) ( $state['users'][ (string) $spec['owner'] ] ?? 0 );
	$status   = (string) ( $spec['status'] ?? 'draft' );
	$slug     = strtolower( 'catalog-role-' . $campaign . '-' . str_replace( '_', '-', $key ) );
	$id       = wp_insert_post(
		array(
			'post_title'     => 'CatalogRole ' . $campaign . ' ' . $key . ' catalog-role-' . $campaign . '-' . $key,
			'post_name'      => $slug,
			'post_content'   => 'CatalogRole ' . $campaign . ' ' . $key . ' v1',
			'post_status'    => 'trash' === $status ? 'draft' : $status,
			'post_author'    => $owner,
			'post_type'      => 'post',
			'comment_status' => (string) ( $spec['comments'] ?? 'closed' ),
		),
		true
	);
	if ( is_wp_error( $id ) || 1 > (int) $id ) {
		throw new RuntimeException( 'Catalog role fixture post unavailable.' );
	}
	$state['ids'][ (int) $spec['id'] ] = (int) $id;
	$state['ledger'][]                 = array(
		'type' => 'post',
		'ref'  => $key,
		'id'   => (int) $id,
	);
	if ( true === ( $spec['revisions'] ?? false ) ) {
		wp_update_post(
			array(
				'ID'           => (int) $id,
				'post_content' => 'CatalogRole ' . $campaign . ' ' . $key . ' v2',
			)
		);
		$revision = wp_save_post_revision( (int) $id );
		if ( ! is_int( $revision ) || 1 > $revision ) {
			$revisions = wp_get_post_revisions( (int) $id, array( 'numberposts' => 1 ) );
			$revision  = array() === $revisions ? 0 : (int) array_key_first( $revisions );
		}
		if ( 1 > $revision ) {
			throw new RuntimeException( 'Catalog role fixture revision unavailable.' );
		}
		$actor = (string) $spec['owner'];
		if ( isset( $GLOBALS['xnetvn_catalog_role_fixture_declarations']['revisions'][ $actor ] ) && str_starts_with( $key, 'post_revision_' ) ) {
			$state['ids'][ (int) $GLOBALS['xnetvn_catalog_role_fixture_declarations']['revisions'][ $actor ] ] = $revision;
		}
	}
	if ( 'trash' === $status ) {
		if ( ! wp_trash_post( (int) $id ) ) {
			throw new RuntimeException( 'Catalog role fixture post trash failed.' );
		}
	}
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Create one owned term target.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $key   Target key.
 * @param array<string,mixed> $spec  Target specification.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_term( array &$state, string $key, array $spec ): void {
	$campaign = (string) $state['campaign'];
	$term     = wp_insert_term(
		(string) $spec['name'] . ' ' . $campaign,
		(string) $spec['taxonomy'],
		array( 'slug' => strtolower( 'catalog-role-' . $campaign . '-' . str_replace( '_', '-', $key ) ) )
	);
	if ( is_wp_error( $term ) || ! isset( $term['term_id'] ) ) {
		throw new RuntimeException( 'Catalog role fixture term unavailable.' );
	}
	$state['ids'][ (int) $spec['id'] ] = (int) $term['term_id'];
	$state['ledger'][]                 = array(
		'type' => 'term',
		'ref'  => $key,
		'id'   => (int) $term['term_id'],
	);
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Create one owned media target.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $key   Target key.
 * @param array<string,mixed> $spec  Target specification.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_media( array &$state, string $key, array $spec ): void {
	$campaign = (string) $state['campaign'];
	$owner    = (int) ( $state['users'][ (string) $spec['owner'] ] ?? 0 );
	$pixel    = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$upload   = wp_upload_bits( 'catalog-role-' . $campaign . '-' . str_replace( '_', '-', $key ) . '.png', null, $pixel );
	if ( ! empty( $upload['error'] ) ) {
		throw new RuntimeException( 'Catalog role fixture upload unavailable.' );
	}
	$id = wp_insert_attachment(
		array(
			'post_title'     => 'CatalogRole ' . $campaign . ' ' . $key . ' catalog-role-' . $campaign . '-' . $key,
			'post_name'      => 'catalog-role-' . $campaign . '-' . str_replace( '_', '-', $key ),
			'post_status'    => 'inherit',
			'post_author'    => $owner,
			'post_mime_type' => 'image/png',
		),
		$upload['file']
	);
	if ( 0 === $id || is_wp_error( $id ) ) {
		throw new RuntimeException( 'Catalog role fixture media unavailable.' );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( (int) $id, wp_generate_attachment_metadata( (int) $id, $upload['file'] ) );
	$state['ids'][ (int) $spec['id'] ] = (int) $id;
	$state['ledger'][]                 = array(
		'type' => 'media',
		'ref'  => $key,
		'id'   => (int) $id,
	);
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Keep the current blog's upload directory writable by the web server.
 *
 * WP-CLI runs as root, so fixture uploads leave the blog upload directory
 * root-owned and the later www-data MCP upload cannot create its file. Only
 * the directory chain of this blog is adjusted; file contents stay untouched.
 *
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_fix_uploads(): void {
	$uploads = wp_get_upload_dir();
	$base    = (string) ( $uploads['basedir'] ?? '' );
	if ( '' === $base || ! is_dir( $base ) ) {
		return;
	}
	$dirs = array(
		$base,
		$base . '/' . gmdate( 'Y' ),
		$base . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ),
	);
	foreach ( $dirs as $dir ) {
		if ( is_dir( $dir ) ) {
			@chown( $dir, 'www-data' );
			@chgrp( $dir, 'www-data' );
			@chmod( $dir, 0755 );
		}
	}
}

/**
 * Create one owned comment target.
 *
 * @param array<string,mixed> $state State, by reference.
 * @param string              $key   Target key.
 * @param array<string,mixed> $spec  Target specification.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_comment( array &$state, string $key, array $spec ): void {
	$campaign = (string) $state['campaign'];
	$post     = (int) ( $state['ids'][ (int) ( $GLOBALS['xnetvn_catalog_role_fixture_declarations']['targets'][ (string) $spec['post'] ]['id'] ?? 0 ) ] ?? 0 );
	$owner    = (int) ( $state['users'][ (string) $spec['owner'] ] ?? 0 );
	$user     = get_userdata( $owner );
	if ( 1 > $post || ! $user instanceof WP_User ) {
		throw new RuntimeException( 'Catalog role fixture comment dependency unavailable.' );
	}
	$approved = $spec['approved'];
	$id       = wp_insert_comment(
		array(
			'comment_post_ID'      => $post,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_content'      => 'CatalogRole ' . $campaign . ' ' . $key . ' catalog-role-' . $campaign . '-' . $key,
			'comment_approved'     => is_int( $approved ) ? $approved : 0,
			'user_id'              => $owner,
		)
	);
	if ( ! is_int( $id ) || 1 > $id ) {
		throw new RuntimeException( 'Catalog role fixture comment unavailable.' );
	}
	if ( is_string( $approved ) && in_array( $approved, array( 'spam', 'trash' ), true ) ) {
		wp_set_comment_status( $id, $approved );
	}
	$state['ids'][ (int) $spec['id'] ] = $id;
	$state['ledger'][]                 = array(
		'type' => 'comment',
		'ref'  => $key,
		'id'   => $id,
	);
	xnetvn_agent_hub_wp_catalog_role_save( $GLOBALS['xnetvn_catalog_role_fixture_state_file'], $state );
}

/**
 * Build the real catalog through the reviewed contracts.
 *
 * @return array{items:array<int,array<string,mixed>>,packs:array<string,int>,digest:string}
 * @throws RuntimeException For a catalog/core mismatch.
 */
function xnetvn_agent_hub_wp_catalog_role_catalog(): array {
	global $wpdb;
	$settings = new WpSiteSettings();
	$request  = new RequestScope();
	$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
	$registry->add_pack( new ContentPack( new DefinitionFactory() ) );
	$registry->add_pack( new MediaPack( new DefinitionFactory(), $settings ) );
	$registry->add_pack( new CommentsPack( new DefinitionFactory() ) );
	$registry->add_pack( new TaxonomyPack( new DefinitionFactory() ) );
	$registry->add_pack( new UsersPack( new DefinitionFactory(), new SlidingWindowLimiter( new WpBucketStore( $wpdb ), new SystemClock() ) ) );
	$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
	wp_get_ability_categories();
	wp_get_abilities();
	$registry->load_packs();
	$items = array();
	$packs = array();
	foreach ( $registry->all() as $definition ) {
		$descriptor = $definition->descriptor();
		$name       = $descriptor->name;
		$ability    = wp_get_ability( $name );
		if ( null === $ability ) {
			throw new RuntimeException( 'Catalog role fixture ability registration missing.' );
		}
		$meta = $ability->get_meta();
		if ( true === ( $meta['public'] ?? false ) || true === ( $meta['show_in_rest'] ?? false ) || ( $meta['xnetvn']['tier'] ?? '' ) !== $descriptor->tier->value || ( $meta['xnetvn']['toolset'] ?? '' ) !== $descriptor->toolset || ( $meta['xnetvn']['visibility_cap'] ?? '' ) !== $descriptor->visibility_cap ) {
			throw new RuntimeException( 'Catalog role fixture ability metadata mismatch.' );
		}
		$items[]                       = array(
			'name'           => $name,
			'tool_name'      => $definition->tool_name(),
			'label'          => $definition->label(),
			'description'    => $definition->description(),
			'tier'           => $descriptor->tier->value,
			'toolset'        => $descriptor->toolset,
			'visibility_cap' => $descriptor->visibility_cap,
			'input_schema'   => $definition->input_schema(),
			'output_schema'  => $definition->output_schema(),
			'annotations'    => $definition->annotations(),
			'costly'         => $definition->costly(),
		);
		$packs[ $descriptor->toolset ] = (int) ( $packs[ $descriptor->toolset ] ?? 0 ) + 1;
	}
	ksort( $packs );
	if ( 62 !== count( $items ) || array(
		'comments' => 10,
		'content'  => 19,
		'media'    => 7,
		'system'   => 5,
		'taxonomy' => 9,
		'users'    => 12,
	) !== $packs ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed diagnostic with no secret or user value.
		throw new RuntimeException( 'Catalog role fixture catalog shape mismatch: ' . count( $items ) . ' ' . Json::encode( $packs ) );
	}
	return array(
		'items'  => $items,
		'packs'  => $packs,
		'digest' => xnetvn_agent_hub_wp_catalog_role_digest( $items ),
	);
}

/**
 * Delete only the journal rows bound to this run.
 *
 * @param array<string,mixed> $state State.
 * @param array<string,int>   $removed Removed counters, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_cleanup_journal( array $state, array &$removed ): void {
	$file = $GLOBALS['xnetvn_catalog_role_fixture_journal_file'];
	if ( ! is_file( $file ) || is_link( $file ) ) {
		return;
	}
	$raw = file_get_contents( $file );
	if ( ! is_string( $raw ) ) {
		throw new RuntimeException( 'Catalog role fixture journal unreadable.' );
	}
	$lines = array_values( array_filter( explode( "\n", trim( $raw ) ) ) );
	foreach ( array_reverse( $lines ) as $line ) {
		$entry = json_decode( $line, true, 16 );
		if ( ! is_array( $entry ) ) {
			throw new RuntimeException( 'Catalog role fixture journal malformed.' );
		}
		xnetvn_agent_hub_wp_catalog_role_delete_entry( $state, $entry, $removed );
	}
}

/**
 * Delete one owned entry after binding checks.
 *
 * @param array<string,mixed> $state State.
 * @param array<string,mixed> $entry Entry.
 * @param array<string,int>   $removed Removed counters, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_delete_entry( array $state, array $entry, array &$removed ): void {
	global $wpdb;
	$type     = (string) ( $entry['type'] ?? '' );
	$id       = (int) ( $entry['id'] ?? 0 );
	$campaign = (string) ( $state['campaign'] ?? '' );
	$owned    = xnetvn_agent_hub_wp_catalog_role_owned_users( $state );
	if ( 1 > $id ) {
		throw new RuntimeException( 'Catalog role fixture cleanup entry invalid.' );
	}
	if ( 'user' === $type ) {
		$user = get_userdata( $id );
		if ( false !== $user ) {
			$bound = xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, $user->user_login )
				|| ( str_starts_with( $user->user_login, 'catalogrole' . str_replace( '-', '', strtolower( $campaign ) ) ) && str_ends_with( $user->user_email, '@example.test' ) );
			if ( ! $bound || ! str_ends_with( $user->user_email, '@example.test' ) ) {
				throw new RuntimeException( 'Catalog role fixture user cleanup binding mismatch.' );
			}
			xnetvn_agent_hub_wp_catalog_role_delete_user( $id );
			++$removed['users'];
		}
		return;
	}
	if ( 'key' === $type ) {
		$repo = $GLOBALS['xnetvn_catalog_role_fixture_keys_repo'];
		$row  = $repo->find_by_id( $id );
		if ( null !== $row ) {
			if ( ! in_array( $row->user_id, $owned, true ) || ! str_starts_with( $row->name, 'Catalog Role ' ) ) {
				throw new RuntimeException( 'Catalog role fixture key cleanup binding mismatch.' );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $row->id, $row->key_id->value, $row->user_id ) ) || null !== $repo->find_by_id( $id ) ) {
				throw new RuntimeException( 'Catalog role fixture key cleanup failed.' );
			}
			++$removed['keys'];
		}
		return;
	}
	if ( 'post' === $type || 'media' === $type ) {
		$post = get_post( $id );
		if ( null !== $post ) {
			if ( ! in_array( (int) $post->post_author, $owned, true ) || ! xnetvn_agent_hub_wp_catalog_role_marked( $campaign, $post->post_title . ' ' . $post->post_name ) ) {
				throw new RuntimeException( 'Catalog role fixture content cleanup binding mismatch.' );
			}
			$deleted = 'media' === $type ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
			if ( ! $deleted ) {
				throw new RuntimeException( 'Catalog role fixture content cleanup failed.' );
			}
			++$removed[ 'media' === $type ? 'media' : 'posts' ];
		}
		return;
	}
	if ( 'term' === $type ) {
		$term = get_term( $id );
		if ( $term instanceof WP_Term ) {
			if ( ! xnetvn_agent_hub_wp_catalog_role_marked( $campaign, $term->name . ' ' . $term->slug ) ) {
				throw new RuntimeException( 'Catalog role fixture term cleanup binding mismatch.' );
			}
			$deleted = wp_delete_term( $id, $term->taxonomy );
			if ( false === $deleted || is_wp_error( $deleted ) ) {
				throw new RuntimeException( 'Catalog role fixture term cleanup failed.' );
			}
			++$removed['terms'];
		}
		return;
	}
	if ( 'comment' === $type ) {
		$comment = get_comment( $id );
		if ( $comment instanceof WP_Comment ) {
			$content = (string) $comment->comment_content;
			$ref     = (string) ( $entry['ref'] ?? '' );
			$bound   = xnetvn_agent_hub_wp_catalog_role_marked( $campaign, $content ) || ( '' !== $ref && str_contains( $content, 'CatalogRole ' . $campaign . ' ' . $ref ) ) || ( '' !== $ref && str_starts_with( $content, 'CR-CANARY-' ) );
			if ( ! in_array( (int) $comment->user_id, $owned, true ) || ! $bound ) {
				throw new RuntimeException( 'Catalog role fixture comment cleanup binding mismatch.' );
			}
			if ( ! wp_delete_comment( $id, true ) ) {
				throw new RuntimeException( 'Catalog role fixture comment cleanup failed.' );
			}
			++$removed['comments'];
		}
	}
}

/**
 * Sweep run-bound objects that never reached the journal.
 *
 * @param array<string,mixed> $state State.
 * @param array<string,int>   $removed Removed counters, by reference.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_sweep( array $state, array &$removed ): void {
	global $wpdb;
	$campaign = (string) ( $state['campaign'] ?? '' );
	$owned    = xnetvn_agent_hub_wp_catalog_role_owned_users( $state );
	if ( array() === $owned ) {
		return;
	}
	$posts = get_posts(
		array(
			'post_type'        => array( 'post', 'attachment' ),
			'post_status'      => 'any',
			'author__in'       => $owned,
			'numberposts'      => -1,
			'suppress_filters' => true,
		)
	);
	foreach ( $posts as $post ) {
		if ( ! xnetvn_agent_hub_wp_catalog_role_marked( $campaign, $post->post_title . ' ' . $post->post_name . ' ' . $post->guid ) ) {
			continue;
		}
		if ( ! in_array( (int) $post->post_author, $owned, true ) ) {
			throw new RuntimeException( 'Catalog role fixture sweep ownership mismatch.' );
		}
		$deleted = 'attachment' === $post->post_type ? wp_delete_attachment( (int) $post->ID, true ) : wp_delete_post( (int) $post->ID, true );
		if ( ! $deleted ) {
			throw new RuntimeException( 'Catalog role fixture sweep delete failed.' );
		}
		++$removed[ 'attachment' === $post->post_type ? 'media' : 'posts' ];
	}
	$terms = get_terms(
		array(
			'taxonomy'   => array( 'category', 'post_tag' ),
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	foreach ( $terms as $term ) {
		if ( ! $term instanceof WP_Term || ! xnetvn_agent_hub_wp_catalog_role_marked( $campaign, $term->name . ' ' . $term->slug ) ) {
			continue;
		}
		$deleted = wp_delete_term( $term->term_id, $term->taxonomy );
		if ( false === $deleted || is_wp_error( $deleted ) ) {
			throw new RuntimeException( 'Catalog role fixture sweep term failed.' );
		}
		++$removed['terms'];
	}
	$comments = get_comments(
		array(
			'author__in'    => $owned,
			'status'        => 'any',
			'number'        => 0,
			'no_found_rows' => true,
		)
	);
	foreach ( $comments as $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			continue;
		}
		if ( ! xnetvn_agent_hub_wp_catalog_role_marked( $campaign, (string) $comment->comment_content ) ) {
			continue;
		}
		if ( ! in_array( (int) $comment->user_id, $owned, true ) || ! wp_delete_comment( (int) $comment->comment_ID, true ) ) {
			throw new RuntimeException( 'Catalog role fixture sweep comment failed.' );
		}
		++$removed['comments'];
	}
	$logins = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT user_login FROM ' . $wpdb->users . ' WHERE ( user_login LIKE %s OR user_login LIKE %s ) AND user_email LIKE %s',
			$wpdb->esc_like( 'catalog-role-' . $campaign . '-' ) . '%',
			$wpdb->esc_like( 'catalogrole' . str_replace( '-', '', strtolower( $campaign ) ) ) . '%',
			'%@example.test'
		)
	);
	foreach ( (array) $logins as $login ) {
		if ( ! xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, (string) $login ) && ! str_starts_with( (string) $login, 'catalogrole' . str_replace( '-', '', strtolower( $campaign ) ) ) ) {
			continue;
		}
		$user = get_user_by( 'login', (string) $login );
		if ( ! $user instanceof WP_User ) {
			continue;
		}
		xnetvn_agent_hub_wp_catalog_role_delete_user( $user->ID );
		++$removed['users'];
	}
	$key_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, key_id, user_id, name FROM %i WHERE name LIKE %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', 'Catalog Role %' ) );
	foreach ( (array) $key_rows as $row ) {
		if ( ! in_array( (int) $row->user_id, $owned, true ) || ! str_starts_with( (string) $row->name, 'Catalog Role ' ) ) {
			continue;
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', (int) $row->id, (string) $row->key_id, (int) $row->user_id ) ) ) {
			throw new RuntimeException( 'Catalog role fixture sweep key failed.' );
		}
		++$removed['keys'];
	}
}

/**
 * Delete one owned user on single site or network.
 *
 * @param int $id User id.
 * @return void
 * @throws RuntimeException For a failed delete.
 */
function xnetvn_agent_hub_wp_catalog_role_delete_user( int $id ): void {
	global $wpdb;
	if ( function_exists( 'is_super_admin' ) && is_super_admin( $id ) ) {
		revoke_super_admin( $id );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $id ) );
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( ! wpmu_delete_user( $id ) ) {
			throw new RuntimeException( 'Catalog role fixture user cleanup failed.' );
		}
		return;
	}
	if ( ! wp_delete_user( $id ) ) {
		throw new RuntimeException( 'Catalog role fixture user cleanup failed.' );
	}
}

/**
 * Remove only campaign-owned post cron events.
 *
 * The site cron option is global: cleanup never rewrites it from the
 * snapshot. It removes future-publish events that reference an owned post
 * and leaves every other event untouched; the runner reports cron drift.
 *
 * @param array<string,mixed> $state State.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_cleanup_cron( array $state ): void {
	$ids = array();
	foreach ( $state['ledger'] ?? array() as $entry ) {
		if ( 'post' === ( $entry['type'] ?? '' ) ) {
			$ids[] = (int) ( $entry['id'] ?? 0 );
		}
	}
	$journal = (string) ( $GLOBALS['xnetvn_catalog_role_fixture_journal_file'] ?? '' );
	if ( '' !== $journal && is_file( $journal ) && ! is_link( $journal ) ) {
		$raw = file_get_contents( $journal );
		if ( is_string( $raw ) ) {
			foreach ( array_filter( explode( "\n", trim( $raw ) ) ) as $line ) {
				$entry = json_decode( $line, true, 16 );
				if ( is_array( $entry ) && 'post' === ( $entry['type'] ?? '' ) ) {
					$ids[] = (int) ( $entry['id'] ?? 0 );
				}
			}
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( array() === $ids ) {
		return;
	}
	$cron = _get_cron_array();
	if ( ! is_array( $cron ) ) {
		return;
	}
	$changed = false;
	foreach ( $cron as $timestamp => $hooks ) {
		foreach ( (array) $hooks as $hook => $events ) {
			if ( 'publish_future_post' !== $hook || ! is_array( $events ) ) {
				continue;
			}
			foreach ( $events as $key => $event ) {
				$post_id = (int) ( $event['args'][0] ?? 0 );
				if ( in_array( $post_id, $ids, true ) ) {
					unset( $cron[ $timestamp ][ $hook ][ $key ] );
					$changed = true;
				}
			}
			if ( empty( $cron[ $timestamp ][ $hook ] ) ) {
				unset( $cron[ $timestamp ][ $hook ] );
			}
		}
		if ( empty( $cron[ $timestamp ] ) ) {
			unset( $cron[ $timestamp ] );
		}
	}
	if ( $changed ) {
		_set_cron_array( $cron );
	}
}

/**
 * Remove the owned instrumentation file after a digest check.
 *
 * @param array<string,mixed> $state State.
 * @return void
 */
function xnetvn_agent_hub_wp_catalog_role_remove_mu( array $state ): void {
	$file = (string) ( $state['mu']['file'] ?? '' );
	$hash = (string) ( $state['mu']['digest'] ?? '' );
	if ( '' === $file || '' === $hash || ! file_exists( $file ) ) {
		return;
	}
	if ( is_link( $file ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $file ) ) ) {
		throw new RuntimeException( 'Catalog role fixture instrumentation mismatch.' );
	}
	if ( ! wp_delete_file( $file ) || file_exists( $file ) ) {
		throw new RuntimeException( 'Catalog role fixture instrumentation cleanup failed.' );
	}
}

try {
	if ( 1 < $xnetvn_agent_hub_wp_catalog_role_blog_id ) {
		switch_to_blog( $xnetvn_agent_hub_wp_catalog_role_blog_id );
	}
	if ( get_current_blog_id() !== $xnetvn_agent_hub_wp_catalog_role_blog_id ) {
		exit( 2 );
	}

	if ( 'counts' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		xnetvn_agent_hub_wp_catalog_role_report(
			array_merge(
				array( 'counts' => true ),
				xnetvn_agent_hub_wp_catalog_role_counts(),
				array(
					'settings_digest' => xnetvn_agent_hub_wp_catalog_role_digest( ( new WpSiteSettings() )->all() ),
					'cron_digest'     => xnetvn_agent_hub_wp_catalog_role_digest( get_option( 'cron', array() ) ),
				)
			)
		);
		return;
	}

	if ( 'state' === $xnetvn_agent_hub_wp_catalog_role_action || 'snapshot' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		$label = (string) ( $args[1] ?? ( 'snapshot' === $xnetvn_agent_hub_wp_catalog_role_action ? 'before' : 'after' ) );
		if ( ! in_array( $label, array( 'before', 'after' ), true ) ) {
			exit( 2 );
		}
		$report = xnetvn_agent_hub_wp_catalog_role_state_report( $state );
		$file   = $xnetvn_agent_hub_wp_catalog_role_private_dir . '/state-' . $label . '.json';
		if ( is_link( $file ) || false === file_put_contents( $file, Json::encode( $report ), LOCK_EX ) ) {
			throw new RuntimeException( 'Catalog role fixture state report failed.' );
		}
		chmod( $file, 0600 );
		xnetvn_agent_hub_wp_catalog_role_report(
			array_merge(
				array(
					'state' => true,
					'label' => $label,
				),
				$report
			)
		);
		return;
	}

	if ( 'variant' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		$variant = (string) ( $args[1] ?? '' );
		if ( ! in_array( $variant, array( 'ceiling-write', 'admin-off', 'restore' ), true ) ) {
			exit( 2 );
		}
		$settings = new WpSiteSettings();
		if ( 'ceiling-write' === $variant ) {
			$settings->update( array( 'tier_ceiling' => 'write' ) );
		} elseif ( 'admin-off' === $variant ) {
			$settings->update( array( 'allow_admin_tier' => false ) );
		} else {
			$settings->update(
				array(
					'tier_ceiling'     => 'admin',
					'allow_admin_tier' => true,
				)
			);
		}
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'variant'         => $variant,
				'settings_digest' => xnetvn_agent_hub_wp_catalog_role_digest( ( new WpSiteSettings() )->all() ),
			)
		);
		return;
	}

	if ( 'audit' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		$owned   = xnetvn_agent_hub_wp_catalog_role_owned_users( $state );
		$started = (string) ( $state['started_at'] ?? '' );
		if ( '' === $started ) {
			throw new RuntimeException( 'Catalog role fixture audit window unavailable.' );
		}
		$table         = $wpdb->prefix . 'xnetvn_agent_hub_wp_audit';
		$rows          = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, ability, outcome, arg_keys FROM %i WHERE occurred_at >= %s', $table, $started ) );
		$count         = 0;
		$bad           = 0;
		$canary_hits   = 0;
		$value_like    = 0;
		$campaign_hits = 0;
		$campaign      = (string) ( $state['campaign'] ?? '' );
		foreach ( (array) $rows as $row ) {
			if ( ! in_array( (int) $row->user_id, $owned, true ) ) {
				continue;
			}
			++$count;
			$keys = json_decode( (string) $row->arg_keys, true, 16 );
			if ( ! is_array( $keys ) || ! array_is_list( $keys ) ) {
				++$bad;
				continue;
			}
			foreach ( $keys as $key ) {
				if ( ! is_string( $key ) || 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\z/', $key ) ) {
					++$bad;
					continue;
				}
				if ( false !== stripos( $key, 'CR-CANARY' ) || false !== stripos( $key, 'catalog-role-' ) ) {
					++$canary_hits;
				}
				if ( str_contains( $key, '@' ) || str_contains( $key, '=' ) ) {
					++$value_like;
				}
				if ( '' !== $campaign && str_contains( $key, $campaign ) ) {
					++$campaign_hits;
				}
			}
		}
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'audit'         => true,
				'rows'          => $count,
				'bad'           => $bad,
				'canary_hits'   => $canary_hits,
				'value_like'    => $value_like,
				'campaign_hits' => $campaign_hits,
			)
		);
		return;
	}

	if ( 'catalog' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		$catalog = xnetvn_agent_hub_wp_catalog_role_catalog();
		if ( is_link( $xnetvn_agent_hub_wp_catalog_role_catalog_file ) || false === file_put_contents( $xnetvn_agent_hub_wp_catalog_role_catalog_file, Json::encode( $catalog['items'] ), LOCK_EX ) ) {
			throw new RuntimeException( 'Catalog role fixture catalog write failed.' );
		}
		chmod( $xnetvn_agent_hub_wp_catalog_role_catalog_file, 0600 );
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'catalog' => true,
				'tools'   => count( $catalog['items'] ),
				'packs'   => $catalog['packs'],
				'digest'  => $catalog['digest'],
			)
		);
		return;
	}

	if ( 'prepare' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		if ( is_array( $xnetvn_agent_hub_wp_catalog_role_state ) && array() !== $xnetvn_agent_hub_wp_catalog_role_state ) {
			if ( 'catalog-role-fixture' !== ( $xnetvn_agent_hub_wp_catalog_role_state['suite'] ?? '' ) || ( $xnetvn_agent_hub_wp_catalog_role_state['target'] ?? '' ) !== $xnetvn_agent_hub_wp_catalog_role_target || ( $xnetvn_agent_hub_wp_catalog_role_state['campaign'] ?? '' ) !== $xnetvn_agent_hub_wp_catalog_role_campaign || true === ( $xnetvn_agent_hub_wp_catalog_role_state['restored'] ?? false ) ) {
				exit( 2 );
			}
			xnetvn_agent_hub_wp_catalog_role_report(
				array(
					'prepared'   => true,
					'idempotent' => true,
					'owned'      => xnetvn_agent_hub_wp_catalog_role_owned_counts( $xnetvn_agent_hub_wp_catalog_role_state ),
				)
			);
			return;
		}
		foreach ( $xnetvn_agent_hub_wp_catalog_role_declarations['users'] as $xnetvn_agent_hub_wp_catalog_role_alias => $xnetvn_agent_hub_wp_catalog_role_spec ) {
			if ( get_user_by( 'login', 'catalog-role-' . $xnetvn_agent_hub_wp_catalog_role_campaign . '-' . $xnetvn_agent_hub_wp_catalog_role_alias ) instanceof WP_User ) {
				exit( 2 );
			}
		}
		if ( false !== get_option( 'xnetvn_agent_hub_wp_catalog_role_fixture', false ) ) {
			exit( 2 );
		}
		if ( file_exists( ABSPATH . 'wp-content/mu-plugins/xnetvn-catalog-role.php' ) || is_link( ABSPATH . 'wp-content/mu-plugins/xnetvn-catalog-role.php' ) ) {
			exit( 2 );
		}
		wp_mkdir_p( $xnetvn_agent_hub_wp_catalog_role_private_dir );
		chmod( $xnetvn_agent_hub_wp_catalog_role_private_dir, 0700 );
		$xnetvn_agent_hub_wp_catalog_role_control  = bin2hex( random_bytes( 24 ) );
		$xnetvn_agent_hub_wp_catalog_role_settings = new WpSiteSettings();
		$xnetvn_agent_hub_wp_catalog_role_state    = array(
			'suite'      => 'catalog-role-fixture',
			'started_at' => gmdate( 'Y-m-d H:i:s' ),
			'target'     => $xnetvn_agent_hub_wp_catalog_role_target,
			'campaign'   => $xnetvn_agent_hub_wp_catalog_role_campaign,
			'blog'       => $xnetvn_agent_hub_wp_catalog_role_blog_id,
			'control'    => $xnetvn_agent_hub_wp_catalog_role_control,
			'snapshot'   => array(
				'settings'        => $xnetvn_agent_hub_wp_catalog_role_settings->all(),
				'settings_digest' => xnetvn_agent_hub_wp_catalog_role_digest( $xnetvn_agent_hub_wp_catalog_role_settings->all() ),
				'cron'            => get_option( 'cron', array() ),
				'cron_digest'     => xnetvn_agent_hub_wp_catalog_role_digest( get_option( 'cron', array() ) ),
				'mu'              => 'absent',
			),
			'users'      => array(),
			'keys'       => array(),
			'tokens'     => array(),
			'ids'        => array(),
			'ledger'     => array(),
			'restored'   => false,
		);
		xnetvn_agent_hub_wp_catalog_role_save( $xnetvn_agent_hub_wp_catalog_role_state_file, $xnetvn_agent_hub_wp_catalog_role_state );
		update_option(
			'xnetvn_agent_hub_wp_catalog_role_fixture',
			array(
				'suite'    => 'catalog-role-fixture',
				'campaign' => $xnetvn_agent_hub_wp_catalog_role_campaign,
				'target'   => $xnetvn_agent_hub_wp_catalog_role_target,
				'blog'     => $xnetvn_agent_hub_wp_catalog_role_blog_id,
				'control'  => $xnetvn_agent_hub_wp_catalog_role_control,
			),
			false
		);
		$banned = RoleGuard::ensure_banned_role();
		if ( 'banned' !== $banned ) {
			throw new RuntimeException( 'Catalog role fixture banned role unavailable.' );
		}
		foreach ( $xnetvn_agent_hub_wp_catalog_role_declarations['users'] as $xnetvn_agent_hub_wp_catalog_role_alias => $xnetvn_agent_hub_wp_catalog_role_spec ) {
			if ( true === ( $xnetvn_agent_hub_wp_catalog_role_spec['network'] ?? false ) && ! is_multisite() ) {
				continue;
			}
			xnetvn_agent_hub_wp_catalog_role_user( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_alias, (array) $xnetvn_agent_hub_wp_catalog_role_spec, $xnetvn_agent_hub_wp_catalog_role_control );
		}
		foreach ( $xnetvn_agent_hub_wp_catalog_role_declarations['keys'] as $xnetvn_agent_hub_wp_catalog_role_alias => $xnetvn_agent_hub_wp_catalog_role_spec ) {
			if ( true === ( $xnetvn_agent_hub_wp_catalog_role_spec['network'] ?? false ) && ! is_multisite() ) {
				continue;
			}
			xnetvn_agent_hub_wp_catalog_role_key( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_alias, (array) $xnetvn_agent_hub_wp_catalog_role_spec, $xnetvn_agent_hub_wp_catalog_role_control );
		}
		xnetvn_agent_hub_wp_catalog_role_install_mu( $xnetvn_agent_hub_wp_catalog_role_state );
		foreach ( $xnetvn_agent_hub_wp_catalog_role_declarations['targets'] as $xnetvn_agent_hub_wp_catalog_role_key => $xnetvn_agent_hub_wp_catalog_role_spec ) {
			if ( in_array( $xnetvn_agent_hub_wp_catalog_role_spec['kind'], array( 'post', 'term', 'media', 'comment' ), true ) ) {
				if ( 'post' === $xnetvn_agent_hub_wp_catalog_role_spec['kind'] ) {
					xnetvn_agent_hub_wp_catalog_role_post( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_key, (array) $xnetvn_agent_hub_wp_catalog_role_spec );
				} elseif ( 'term' === $xnetvn_agent_hub_wp_catalog_role_spec['kind'] ) {
					xnetvn_agent_hub_wp_catalog_role_term( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_key, (array) $xnetvn_agent_hub_wp_catalog_role_spec );
				} elseif ( 'media' === $xnetvn_agent_hub_wp_catalog_role_spec['kind'] ) {
					xnetvn_agent_hub_wp_catalog_role_media( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_key, (array) $xnetvn_agent_hub_wp_catalog_role_spec );
				} else {
					xnetvn_agent_hub_wp_catalog_role_comment( $xnetvn_agent_hub_wp_catalog_role_state, (string) $xnetvn_agent_hub_wp_catalog_role_key, (array) $xnetvn_agent_hub_wp_catalog_role_spec );
				}
			}
		}
		xnetvn_agent_hub_wp_catalog_role_fix_uploads();
		update_option(
			'xnetvn_agent_hub_wp_catalog_role_fixture',
			array(
				'suite'    => 'catalog-role-fixture',
				'campaign' => $xnetvn_agent_hub_wp_catalog_role_campaign,
				'target'   => $xnetvn_agent_hub_wp_catalog_role_target,
				'blog'     => $xnetvn_agent_hub_wp_catalog_role_blog_id,
				'control'  => $xnetvn_agent_hub_wp_catalog_role_control,
				'users'    => $xnetvn_agent_hub_wp_catalog_role_state['users'],
			),
			false
		);
		( new WpSiteSettings() )->update(
			array(
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => true,
			)
		);
		xnetvn_agent_hub_wp_catalog_role_save( $xnetvn_agent_hub_wp_catalog_role_state_file, $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'prepared'   => true,
				'idempotent' => false,
				'actors'     => count( $xnetvn_agent_hub_wp_catalog_role_state['users'] ),
				'keys'       => count( $xnetvn_agent_hub_wp_catalog_role_state['keys'] ),
				'content'    => count( $xnetvn_agent_hub_wp_catalog_role_state['ids'] ),
			)
		);
		return;
	}

	if ( 'verify' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		foreach ( $xnetvn_agent_hub_wp_catalog_role_declarations['users'] as $alias => $spec ) {
			if ( true === ( $spec['network'] ?? false ) && ! is_multisite() ) {
				continue;
			}
			$user = get_userdata( (int) ( $state['users'][ $alias ] ?? 0 ) );
			if ( ! $user instanceof WP_User || ! xnetvn_agent_hub_wp_catalog_role_owned_login( (string) $state['campaign'], $user->user_login ) || ! in_array( (string) $spec['role'], (array) $user->roles, true ) ) {
				throw new RuntimeException( 'Catalog role fixture actor mismatch.' );
			}
		}
		foreach ( array( 'subscriber', 'banned' ) as $alias ) {
			$user = get_userdata( (int) ( $state['users'][ $alias ] ?? 0 ) );
			if ( $user instanceof WP_User && user_can( $user, Capabilities::USE ) ) {
				throw new RuntimeException( 'Catalog role fixture delegation mismatch.' );
			}
		}
		foreach ( array( 'admin', 'editor', 'author', 'contributor' ) as $alias ) {
			$user = get_userdata( (int) ( $state['users'][ $alias ] ?? 0 ) );
			if ( ! $user instanceof WP_User || ! user_can( $user, Capabilities::USE ) ) {
				throw new RuntimeException( 'Catalog role fixture delegation mismatch.' );
			}
		}
		foreach ( $state['keys'] as $key ) {
			$row = ( new WpdbKeyRepository( $GLOBALS['wpdb'], new SystemClock() ) )->find_by_id( (int) $key['id'] );
			if ( null === $row || $row->user_id !== (int) $key['owner'] || ! str_starts_with( $row->name, 'Catalog Role ' ) || $row->is_revoked() ) {
				throw new RuntimeException( 'Catalog role fixture key mismatch.' );
			}
		}
		$settings = new WpSiteSettings();
		if ( 'admin' !== $settings->get( 'tier_ceiling' ) || true !== $settings->get( 'allow_admin_tier' ) ) {
			throw new RuntimeException( 'Catalog role fixture settings mismatch.' );
		}
		$count_before = (int) get_option( 'xnetvn_catalog_role_mail_count', 0 );
		wp_mail( 'catalog-role-sink-' . substr( (string) $state['control'], 0, 8 ) . '@example.test', 'Catalog role probe', 'Probe body is never stored.' );
		if ( (int) get_option( 'xnetvn_catalog_role_mail_count', 0 ) !== $count_before + 1 || 'example.test' !== (string) get_option( 'xnetvn_catalog_role_mail_domain', '' ) ) {
			throw new RuntimeException( 'Catalog role fixture mail sink mismatch.' );
		}
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'verified' => true,
				'actors'   => count( $state['users'] ),
				'keys'     => count( $state['keys'] ),
				'sink'     => true,
			)
		);
		return;
	}

	if ( 'reconcile' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		if ( is_array( $xnetvn_agent_hub_wp_catalog_role_state ) && array() !== $xnetvn_agent_hub_wp_catalog_role_state ) {
			$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
			xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
			$remaining = array(
				'users'    => 0,
				'keys'     => 0,
				'posts'    => 0,
				'terms'    => 0,
				'media'    => 0,
				'comments' => 0,
			);
			foreach ( $state['ledger'] as $entry ) {
				$type = (string) ( $entry['type'] ?? '' );
				$id   = (int) ( $entry['id'] ?? 0 );
				if ( 'user' === $type && get_userdata( $id ) instanceof WP_User ) {
					++$remaining['users'];
				} elseif ( 'key' === $type && null !== $GLOBALS['xnetvn_catalog_role_fixture_keys_repo']->find_by_id( $id ) ) {
					++$remaining['keys'];
				} elseif ( 'post' === $type && null !== get_post( $id ) ) {
					++$remaining['posts'];
				} elseif ( 'media' === $type && null !== get_post( $id ) ) {
					++$remaining['media'];
				} elseif ( 'term' === $type && get_term( $id ) instanceof WP_Term ) {
					++$remaining['terms'];
				} elseif ( 'comment' === $type && get_comment( $id ) instanceof WP_Comment ) {
					++$remaining['comments'];
				}
			}
			$clean = 0 === array_sum( $remaining );
			xnetvn_agent_hub_wp_catalog_role_report(
				array(
					'reconciled' => $clean,
					'remaining'  => $remaining,
				)
			);
			if ( ! $clean ) {
				exit( 2 );
			}
			return;
		}
		if ( false !== get_option( 'xnetvn_agent_hub_wp_catalog_role_fixture', false ) ) {
			xnetvn_agent_hub_wp_catalog_role_report(
				array(
					'reconciled' => false,
					'remaining'  => array( 'marker' => 1 ),
				)
			);
			exit( 2 );
		}
		$left = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->users . ' WHERE user_login LIKE %s', $GLOBALS['wpdb']->esc_like( 'catalog-role-' . $xnetvn_agent_hub_wp_catalog_role_campaign . '-' ) . '%' ) );
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'reconciled' => 0 === $left,
				'remaining'  => array( 'users' => $left ),
			)
		);
		if ( 0 !== $left ) {
			exit( 2 );
		}
		return;
	}

	if ( 'cleanup' === $xnetvn_agent_hub_wp_catalog_role_action ) {
		if ( ! is_array( $xnetvn_agent_hub_wp_catalog_role_state ) || array() === $xnetvn_agent_hub_wp_catalog_role_state ) {
			if ( false !== get_option( 'xnetvn_agent_hub_wp_catalog_role_fixture', false ) ) {
				exit( 2 );
			}
			if ( 0 < xnetvn_agent_hub_wp_catalog_role_journal_count() ) {
				exit( 2 );
			}
			xnetvn_agent_hub_wp_catalog_role_report(
				array(
					'restored' => true,
					'removed'  => array(
						'users'    => 0,
						'keys'     => 0,
						'posts'    => 0,
						'terms'    => 0,
						'media'    => 0,
						'comments' => 0,
					),
				)
			);
			return;
		}
		$state = xnetvn_agent_hub_wp_catalog_role_require_state( $xnetvn_agent_hub_wp_catalog_role_state );
		xnetvn_agent_hub_wp_catalog_role_marker_ok( $state );
		$removed = array(
			'users'    => 0,
			'keys'     => 0,
			'posts'    => 0,
			'terms'    => 0,
			'media'    => 0,
			'comments' => 0,
		);
		xnetvn_agent_hub_wp_catalog_role_remove_mu( $state );
		( new WpSiteSettings() )->update( $state['snapshot']['settings'] );
		xnetvn_agent_hub_wp_catalog_role_cleanup_cron( $state );
		xnetvn_agent_hub_wp_catalog_role_cleanup_journal( $state, $removed );
		foreach ( array_reverse( $state['ledger'] ) as $entry ) {
			xnetvn_agent_hub_wp_catalog_role_delete_entry( $state, (array) $entry, $removed );
		}
		xnetvn_agent_hub_wp_catalog_role_sweep( $state, $removed );
		delete_option( 'xnetvn_agent_hub_wp_catalog_role_fixture' );
		delete_option( 'xnetvn_catalog_role_mail_count' );
		delete_option( 'xnetvn_catalog_role_mail_domain' );
		$state['restored'] = true;
		xnetvn_agent_hub_wp_catalog_role_save( $xnetvn_agent_hub_wp_catalog_role_state_file, $state );
		if ( is_file( $xnetvn_agent_hub_wp_catalog_role_state_file ) && ! unlink( $xnetvn_agent_hub_wp_catalog_role_state_file ) ) {
			throw new RuntimeException( 'Catalog role fixture state cleanup failed.' );
		}
		xnetvn_agent_hub_wp_catalog_role_report(
			array(
				'restored' => true,
				'removed'  => $removed,
			)
		);
		return;
	}
} catch ( \Throwable $xnetvn_agent_hub_wp_catalog_role_error ) {
	xnetvn_agent_hub_wp_catalog_role_report(
		array(
			'error'  => 'fixture_action_failed',
			'action' => $xnetvn_agent_hub_wp_catalog_role_action,
		)
	);
	exit( 2 );
} finally {
	if ( 1 < $xnetvn_agent_hub_wp_catalog_role_blog_id && is_multisite() ) {
		restore_current_blog();
	}
}
