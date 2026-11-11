<?php
/**
 * WP-CLI fixture for the retained SEO review campaign.
 *
 * Fixed actions only: prepare, inspect, reconcile and credentials-cleanup.
 * It prepares the dedicated review author and operator, their run-scoped keys,
 * the three owned diagram images and the campaign marker metadata. It never
 * deletes a review post, attachment, term or user, and no credential reaches
 * standard output. The article itself is created through MCP by the flow.
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
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Support\SystemClock;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Campaign;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\ContentPlan;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Flow;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Journal;
use XNetVN\AgentHubWP\Tests\E2E\SeoReview\Marker;

require_once __DIR__ . '/seo-review.php';

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only fixture files require definite POSIX permissions and bounded temporary files.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Owned fixture artifacts use hashing and binary image output, never executable code.
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag -- Typed guards document behavior in prose across this multi-action WP-CLI fixture.

$xnetvn_agent_hub_wp_seo_review_action = $args[0] ?? '';
if ( ! in_array( $xnetvn_agent_hub_wp_seo_review_action, array( 'prepare', 'inspect', 'reconcile', 'credentials-cleanup' ), true ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_seo_review_target   = getenv( 'XNETVN_SEO_REVIEW_TARGET' );
$xnetvn_agent_hub_wp_seo_review_campaign = getenv( 'XNETVN_SEO_REVIEW_CAMPAIGN' );
$xnetvn_agent_hub_wp_seo_review_root     = getenv( 'XNETVN_SEO_REVIEW_HOME' );
if ( ! is_string( $xnetvn_agent_hub_wp_seo_review_root ) || '' === $xnetvn_agent_hub_wp_seo_review_root ) {
	$xnetvn_agent_hub_wp_seo_review_root = Campaign::DEFAULT_ROOT;
}
if ( ! is_string( $xnetvn_agent_hub_wp_seo_review_target ) || ! is_string( $xnetvn_agent_hub_wp_seo_review_campaign ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_seo_review_hosts = array();
foreach ( array( getenv( 'RTL_ALIAS_WP69' ), getenv( 'RTL_ALIAS_WP71' ), getenv( 'XNETVN_RTL_ALIAS' ), getenv( 'XNETVN_RTL_DOMAIN' ) ) as $xnetvn_agent_hub_wp_seo_review_host ) {
	if ( is_string( $xnetvn_agent_hub_wp_seo_review_host ) && '' !== $xnetvn_agent_hub_wp_seo_review_host ) {
		$xnetvn_agent_hub_wp_seo_review_hosts[] = $xnetvn_agent_hub_wp_seo_review_host;
	}
}
$xnetvn_agent_hub_wp_seo_review_home = parse_url( home_url(), PHP_URL_HOST );
if ( ! is_string( $xnetvn_agent_hub_wp_seo_review_home ) || ! in_array( $xnetvn_agent_hub_wp_seo_review_home, $xnetvn_agent_hub_wp_seo_review_hosts, true ) ) {
	exit( 2 );
}
$xnetvn_agent_hub_wp_seo_review_fault = 0;
$xnetvn_agent_hub_wp_seo_review_fail  = getenv( 'XNETVN_SEO_REVIEW_FAIL_AFTER' );
if ( is_string( $xnetvn_agent_hub_wp_seo_review_fail ) && '' !== $xnetvn_agent_hub_wp_seo_review_fail ) {
	if ( 'prepare' !== $xnetvn_agent_hub_wp_seo_review_action || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $xnetvn_agent_hub_wp_seo_review_fail ) ) {
		exit( 2 );
	}
	$xnetvn_agent_hub_wp_seo_review_fault = (int) $xnetvn_agent_hub_wp_seo_review_fail;
}

/**
 * Print one secret-free JSON report line.
 *
 * @param array<string,mixed> $report Safe report.
 */
function xnetvn_agent_hub_wp_seo_review_report( array $report ): void {
	WP_CLI::line( (string) Json::encode( $report ) );
}

/**
 * Save a private state file atomically.
 *
 * @param string              $file Destination.
 * @param array<string,mixed> $data Data.
 * @throws RuntimeException For an unsafe destination.
 */
function xnetvn_agent_hub_wp_seo_review_save( string $file, #[SensitiveParameter] array $data ): void {
	if ( is_link( $file ) ) {
		throw new RuntimeException( 'SEO review state ownership mismatch.' );
	}
	$temporary = $file . '.tmp-' . getmypid();
	$encoded   = (string) Json::encode( $data );
	if ( false === file_put_contents( $temporary, $encoded, LOCK_EX ) ) {
		throw new RuntimeException( 'SEO review state is not writable.' );
	}
	chmod( $temporary, 0600 );
	if ( ! rename( $temporary, $file ) ) {
		unlink( $temporary );
		throw new RuntimeException( 'SEO review state commit failed.' );
	}
	chmod( $file, 0600 );
}

/**
 * Read the private credential store without exposing values.
 *
 * @param string $file Credential path.
 * @return array<string,mixed>
 */
function xnetvn_agent_hub_wp_seo_review_credentials( string $file ): array {
	if ( is_link( $file ) || ! is_file( $file ) ) {
		return array();
	}
	$raw = file_get_contents( $file );
	if ( ! is_string( $raw ) ) {
		return array();
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Render one owned diagram as PNG bytes.
 *
 * @param string $variant featured, inline-1 or inline-2.
 * @return string PNG bytes.
 * @throws RuntimeException When the image extension is unavailable.
 */
function xnetvn_agent_hub_wp_seo_review_image( string $variant ): string {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		throw new RuntimeException( 'SEO review image extension unavailable.' );
	}
	$width  = 1200;
	$height = 800;
	$canvas = imagecreatetruecolor( $width, $height );
	if ( false === $canvas ) {
		throw new RuntimeException( 'SEO review image canvas unavailable.' );
	}
	$paper  = imagecolorallocate( $canvas, 247, 245, 240 );
	$ink    = imagecolorallocate( $canvas, 28, 38, 52 );
	$muted  = imagecolorallocate( $canvas, 96, 110, 128 );
	$brand  = imagecolorallocate( $canvas, 22, 96, 140 );
	$accent = imagecolorallocate( $canvas, 214, 122, 42 );
	$light  = imagecolorallocate( $canvas, 232, 240, 246 );
	imagefilledrectangle( $canvas, 0, 0, $width, $height, $paper );
	imagefilledrectangle( $canvas, 0, 0, $width, 120, $brand );
	imagestring( $canvas, 5, 60, 40, 'XNETVN AGENT HUB WP - SEO REVIEW', $paper );
	imagestring( $canvas, 5, 60, 70, 'OWNED TEST DIAGRAM - ' . strtoupper( $variant ), $light );
	$blocks = array();
	if ( 'featured' === $variant ) {
		imagestring( $canvas, 5, 60, 170, 'BO CUC BAI VIET RO RANG', $ink );
		imagestring( $canvas, 5, 60, 200, 'MOT TIEU DE - BA PHAN - MOT KET LUAN', $muted );
		$blocks = array(
			array( 'MO DAU', 'NEU VAN DE VA LOI ICH' ),
			array( 'THAN BAI', 'H2 THEO TUNG BUOC' ),
			array( 'KIEM TRA', 'ANH, LIEN KET, MO TA' ),
			array( 'KET LUAN', 'TOM Y VA BUOC TIEP' ),
		);
	} elseif ( 'inline-1' === $variant ) {
		imagestring( $canvas, 5, 60, 170, 'BA BUOC CHUAN BI NOI DUNG', $ink );
		imagestring( $canvas, 5, 60, 200, 'TRA LOI TRUOC KHI MO TRINH SOAN THAO', $muted );
		$blocks = array(
			array( '1  MUC TIEU', 'DOC GIA LAM DUOC GI' ),
			array( '2  NGUOI DOC', 'DA BIET GI, CAN GI' ),
			array( '3  HANH DONG', 'DOC XONG NEN LAM GI' ),
		);
	} else {
		imagestring( $canvas, 5, 60, 170, 'DANH SACH KIEM TRA TRUOC XUAT BAN', $ink );
		imagestring( $canvas, 5, 60, 200, 'DOC LAI TREN GIAO DIEN THAT', $muted );
		$blocks = array(
			array( '[X] TIEU DE', 'MOT H1, KHONG LAP' ),
			array( '[X] THU TU MUC', 'H2 ROI DEN H3' ),
			array( '[X] LIEN KET', 'NOI BO VA NGUON HTTPS' ),
			array( '[X] HINH ANH', 'ALT, CHU THICH, KICH THUOC' ),
		);
	}
	$top = 280;
	foreach ( $blocks as $index => $block ) {
		$left = 60;
		$box  = 250 + ( $index * 220 );
		imagefilledrectangle( $canvas, $left, $box, $left + 200, $box + 200, $light );
		imagefilledrectangle( $canvas, $left, $box, $left + 12, $box + 200, 0 === $index % 2 ? $brand : $accent );
		imagestring( $canvas, 5, $left + 30, $box + 80, $block[0], $ink );
		imagestring( $canvas, 5, $left + 30, $box + 110, $block[1], $muted );
	}
	imageline( $canvas, 60, $top + 250, 1140, $top + 250, $muted );
	imagestring( $canvas, 5, 60, 740, 'HINH MINH HOA THUOC DU AN, KHONG LAY TU WEB', $muted );
	ob_start();
	imagepng( $canvas );
	$bytes = (string) ob_get_clean();
	imagedestroy( $canvas );
	if ( strlen( $bytes ) < 1024 ) {
		throw new RuntimeException( 'SEO review image is too small.' );
	}
	return $bytes;
}

/**
 * Write the three owned images once, keyed by their content digest.
 *
 * @param Campaign $campaign Owned campaign.
 * @return array<string,string> Digest per slot.
 */
function xnetvn_agent_hub_wp_seo_review_images( Campaign $campaign ): array {
	$campaign->ensure();
	$digests = array();
	foreach ( ContentPlan::images() as $image ) {
		$path = $campaign->media_dir() . '/' . $image['file'];
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'SEO review image ownership mismatch.' );
		}
		if ( ! is_file( $path ) ) {
			$bytes = xnetvn_agent_hub_wp_seo_review_image( $image['slot'] );
			if ( false === file_put_contents( $path, $bytes, LOCK_EX ) ) {
				throw new RuntimeException( 'SEO review image is not writable.' );
			}
			chmod( $path, 0600 );
		}
		$digests[ $image['slot'] ] = (string) hash_file( 'sha256', $path );
	}
	return $digests;
}

/**
 * Ensure one campaign user exists with the expected role and marker.
 *
 * @param string $login User login.
 * @param string $role WordPress role.
 * @param string $marker Campaign user marker.
 * @param int    $fault_after Test-owned failure point.
 * @param int    $made Created object counter.
 * @return int User id.
 */
function xnetvn_agent_hub_wp_seo_review_user( string $login, string $role, string $marker, int $fault_after, int &$made ): int {
	$existing = get_user_by( 'login', $login );
	if ( $existing instanceof WP_User ) {
		$stored = (string) get_user_meta( $existing->ID, 'xnetvn_seo_review_marker', true );
		$roles  = array_values( (array) $existing->roles );
		if ( $stored !== $marker || array( $role ) !== $roles ) {
			throw new RuntimeException( 'SEO review actor ownership mismatch.' );
		}
		return (int) $existing->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 48, true, true ),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'SEO review actor unavailable.' );
	}
	update_user_meta( (int) $id, 'xnetvn_seo_review_marker', $marker );
	++$made;
	if ( 0 < $fault_after && $made >= $fault_after ) {
		throw new RuntimeException( 'SEO review fixture fault injection.' );
	}
	return (int) $id;
}

/**
 * Issue or reuse the two run-scoped keys.
 *
 * @param Campaign            $campaign Owned campaign.
 * @param array<string,mixed> $credentials Existing store.
 * @param array<string,int>   $owners Owner ids.
 * @return array<string,mixed> Credential store.
 */
function xnetvn_agent_hub_wp_seo_review_keys( Campaign $campaign, array $credentials, array $owners ): array {
	global $wpdb;
	$repo     = new WpdbKeyRepository( $wpdb, new SystemClock() );
	$file     = $campaign->credentials_path();
	$spec     = array(
		'author'   => array(
			'owner'    => $owners['author'],
			'toolsets' => array( 'content', 'media' ),
		),
		'operator' => array(
			'owner'    => $owners['operator'],
			'toolsets' => array( 'taxonomy' ),
		),
	);
	$reusable = isset( $credentials['actors'] ) && is_array( $credentials['actors'] );
	if ( $reusable ) {
		foreach ( $spec as $actor => $config ) {
			$row = $credentials['actors'][ $actor ] ?? array();
			$id  = (int) ( $row['key_row_id'] ?? 0 );
			if ( ! is_string( $row['token'] ?? null ) || '' === $row['token'] || 1 > $id || (int) ( $row['user_id'] ?? 0 ) !== $config['owner'] ) {
				$reusable = false;
				break;
			}
			$stored = $repo->find_by_id( $id );
			if ( null === $stored || $stored->user_id !== $config['owner'] ) {
				$reusable = false;
				break;
			}
		}
	}
	if ( $reusable ) {
		return $credentials;
	}
	$pepper = ( new PepperStore() )->current();
	$store  = array(
		'suite'       => 'seo-review-credentials',
		'target'      => $campaign->target(),
		'campaign'    => $campaign->campaign(),
		'blog'        => $campaign->blog(),
		'site_url'    => untrailingslashit( home_url() ),
		'created_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'actors'      => array(),
	);
	foreach ( $spec as $actor => $config ) {
		$generated                 = ( new KeyGenerator() )->generate();
		$row                       = $repo->insert(
			$generated->id,
			$config['owner'],
			'SEO review ' . $actor . ' ' . $campaign->campaign_tag(),
			new Scope( RiskTier::Write, $config['toolsets'], array(), array( 'xnetvn/delete-post', 'xnetvn/trash-post', 'xnetvn/delete-media', 'xnetvn/delete-term', 'xnetvn/delete-user', 'xnetvn/update-user' ), array(), new DateTimeImmutable( '+1 day' ) ),
			$generated->hash_with( $pepper ),
			$pepper->id
		);
		$store['actors'][ $actor ] = array(
			'token'      => $generated->reveal(),
			'user_id'    => $config['owner'],
			'role'       => 'author' === $actor ? 'author' : 'editor',
			'key_row_id' => $row->id,
			'key_id'     => $row->key_id->value,
			'name'       => $row->name,
		);
	}
	xnetvn_agent_hub_wp_seo_review_save( $file, $store );
	return $store;
}

/**
 * Count retained review objects recorded in the journal.
 *
 * @param Campaign $campaign Owned campaign.
 * @return array{post:int,media:int,terms:int,users:int}
 */
function xnetvn_agent_hub_wp_seo_review_retained( Campaign $campaign ): array {
	$journal = new Journal( $campaign );
	if ( ! is_file( $campaign->journal_path() ) ) {
		return array(
			'post'  => 0,
			'media' => 0,
			'terms' => 0,
			'users' => 0,
		);
	}
	$state  = $journal->open();
	$count  = array(
		'post'  => 0,
		'media' => 0,
		'terms' => 0,
		'users' => 0,
	);
	$mapped = array(
		'post'  => 'post',
		'media' => 'media',
		'term'  => 'terms',
		'user'  => 'users',
	);
	foreach ( (array) ( $state['objects'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$kind = (string) ( $row['kind'] ?? '' );
		if ( isset( $mapped[ $kind ] ) ) {
			++$count[ $mapped[ $kind ] ];
		}
	}
	return $count;
}

try {
	$xnetvn_agent_hub_wp_seo_review_campaign_obj = new Campaign( $xnetvn_agent_hub_wp_seo_review_root, $xnetvn_agent_hub_wp_seo_review_target, $xnetvn_agent_hub_wp_seo_review_campaign );
	$xnetvn_agent_hub_wp_seo_review_campaign_obj->ensure();
	$xnetvn_agent_hub_wp_seo_review_journal = new Journal( $xnetvn_agent_hub_wp_seo_review_campaign_obj );
	$xnetvn_agent_hub_wp_seo_review_site    = untrailingslashit( home_url() );
	$xnetvn_agent_hub_wp_seo_review_tag     = $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign_tag();

	if ( 'prepare' === $xnetvn_agent_hub_wp_seo_review_action ) {
		$xnetvn_agent_hub_wp_seo_review_state = $xnetvn_agent_hub_wp_seo_review_journal->open();
		if ( $xnetvn_agent_hub_wp_seo_review_campaign_obj->target() !== ( $xnetvn_agent_hub_wp_seo_review_state['target'] ?? '' ) || (int) ( $xnetvn_agent_hub_wp_seo_review_state['blog'] ?? 0 ) !== $xnetvn_agent_hub_wp_seo_review_campaign_obj->blog() ) {
			throw new RuntimeException( 'SEO review journal ownership mismatch.' );
		}
		$xnetvn_agent_hub_wp_seo_review_made   = 0;
		$xnetvn_agent_hub_wp_seo_review_marker = static fn( string $kind, string $ref ): string => Marker::build( $xnetvn_agent_hub_wp_seo_review_campaign_obj->target(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->blog(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign(), $kind, $ref );
		$xnetvn_agent_hub_wp_seo_review_actors = array(
			'author'   => array(
				'login'  => 'seo-review-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->target() . '-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign() . '-author',
				'role'   => 'author',
				'marker' => $xnetvn_agent_hub_wp_seo_review_marker( 'user', 'author' ),
			),
			'operator' => array(
				'login'  => 'seo-review-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->target() . '-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign() . '-operator',
				'role'   => 'editor',
				'marker' => $xnetvn_agent_hub_wp_seo_review_marker( 'user', 'operator' ),
			),
		);
		$xnetvn_agent_hub_wp_seo_review_users  = array();
		foreach ( $xnetvn_agent_hub_wp_seo_review_actors as $xnetvn_agent_hub_wp_seo_review_alias => $xnetvn_agent_hub_wp_seo_review_actor ) {
			$xnetvn_agent_hub_wp_seo_review_uid = xnetvn_agent_hub_wp_seo_review_user( (string) $xnetvn_agent_hub_wp_seo_review_actor['login'], (string) $xnetvn_agent_hub_wp_seo_review_actor['role'], (string) $xnetvn_agent_hub_wp_seo_review_actor['marker'], $xnetvn_agent_hub_wp_seo_review_fault, $xnetvn_agent_hub_wp_seo_review_made );
			$xnetvn_agent_hub_wp_seo_review_users[ $xnetvn_agent_hub_wp_seo_review_alias ] = $xnetvn_agent_hub_wp_seo_review_uid;
			$xnetvn_agent_hub_wp_seo_review_journal->put_object(
				'user:' . $xnetvn_agent_hub_wp_seo_review_alias,
				array(
					'kind'   => 'user',
					'id'     => $xnetvn_agent_hub_wp_seo_review_uid,
					'owner'  => $xnetvn_agent_hub_wp_seo_review_uid,
					'role'   => (string) $xnetvn_agent_hub_wp_seo_review_actor['role'],
					'name'   => (string) $xnetvn_agent_hub_wp_seo_review_actor['login'],
					'marker' => (string) $xnetvn_agent_hub_wp_seo_review_actor['marker'],
				)
			);
		}
		$xnetvn_agent_hub_wp_seo_review_allowed = array(
			'author'   => array( 'edit_posts', 'publish_posts', 'upload_files' ),
			'operator' => array( 'manage_categories', 'edit_others_posts', 'publish_posts' ),
		);
		foreach ( $xnetvn_agent_hub_wp_seo_review_allowed as $xnetvn_agent_hub_wp_seo_review_alias => $xnetvn_agent_hub_wp_seo_review_caps ) {
			foreach ( $xnetvn_agent_hub_wp_seo_review_caps as $xnetvn_agent_hub_wp_seo_review_cap ) {
				if ( ! user_can( $xnetvn_agent_hub_wp_seo_review_users[ $xnetvn_agent_hub_wp_seo_review_alias ], $xnetvn_agent_hub_wp_seo_review_cap ) ) {
					throw new RuntimeException( 'SEO review actor capability unavailable.' );
				}
			}
		}
		$xnetvn_agent_hub_wp_seo_review_existing = xnetvn_agent_hub_wp_seo_review_credentials( $xnetvn_agent_hub_wp_seo_review_campaign_obj->credentials_path() );
		$xnetvn_agent_hub_wp_seo_review_keys     = xnetvn_agent_hub_wp_seo_review_keys( $xnetvn_agent_hub_wp_seo_review_campaign_obj, $xnetvn_agent_hub_wp_seo_review_existing, $xnetvn_agent_hub_wp_seo_review_users );
		$xnetvn_agent_hub_wp_seo_review_images   = xnetvn_agent_hub_wp_seo_review_images( $xnetvn_agent_hub_wp_seo_review_campaign_obj );
		// Re-read the journal after the object writes: saving the stale array
		// captured before them would drop the recorded actors.
		$xnetvn_agent_hub_wp_seo_review_state                                = $xnetvn_agent_hub_wp_seo_review_journal->open();
		$xnetvn_agent_hub_wp_seo_review_state['site_url']                    = $xnetvn_agent_hub_wp_seo_review_site;
		$xnetvn_agent_hub_wp_seo_review_state['checkpoints']                 = is_array( $xnetvn_agent_hub_wp_seo_review_state['checkpoints'] ?? null ) ? $xnetvn_agent_hub_wp_seo_review_state['checkpoints'] : array();
		$xnetvn_agent_hub_wp_seo_review_state['checkpoints']['images']       = $xnetvn_agent_hub_wp_seo_review_images;
		$xnetvn_agent_hub_wp_seo_review_state['checkpoints']['prepared_utc'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$xnetvn_agent_hub_wp_seo_review_journal->save( $xnetvn_agent_hub_wp_seo_review_state );
		xnetvn_agent_hub_wp_seo_review_report(
			array(
				'prepared'    => true,
				'idempotent'  => $xnetvn_agent_hub_wp_seo_review_made < 1,
				'actors'      => array(
					'author'   => array(
						'id'   => $xnetvn_agent_hub_wp_seo_review_users['author'],
						'role' => 'author',
					),
					'operator' => array(
						'id'   => $xnetvn_agent_hub_wp_seo_review_users['operator'],
						'role' => 'editor',
					),
				),
				'images'      => count( $xnetvn_agent_hub_wp_seo_review_images ),
				'keys'        => count( (array) ( $xnetvn_agent_hub_wp_seo_review_keys['actors'] ?? array() ) ),
				'credentials' => 'ready',
			)
		);
		return;
	}

	if ( 'inspect' === $xnetvn_agent_hub_wp_seo_review_action ) {
		$xnetvn_agent_hub_wp_seo_review_state = $xnetvn_agent_hub_wp_seo_review_journal->open();
		$xnetvn_agent_hub_wp_seo_review_rows  = array();
		foreach ( (array) ( $xnetvn_agent_hub_wp_seo_review_state['objects'] ?? array() ) as $xnetvn_agent_hub_wp_seo_review_ref => $xnetvn_agent_hub_wp_seo_review_row ) {
			if ( ! is_array( $xnetvn_agent_hub_wp_seo_review_row ) ) {
				continue;
			}
			$xnetvn_agent_hub_wp_seo_review_exists = false;
			$xnetvn_agent_hub_wp_seo_review_marker = (string) ( $xnetvn_agent_hub_wp_seo_review_row['marker'] ?? '' );
			if ( 'user' === ( $xnetvn_agent_hub_wp_seo_review_row['kind'] ?? '' ) ) {
				$xnetvn_agent_hub_wp_seo_review_user_obj = get_userdata( (int) $xnetvn_agent_hub_wp_seo_review_row['id'] );
				$xnetvn_agent_hub_wp_seo_review_exists   = $xnetvn_agent_hub_wp_seo_review_user_obj instanceof WP_User
					&& '' !== $xnetvn_agent_hub_wp_seo_review_marker
					&& (string) get_user_meta( (int) $xnetvn_agent_hub_wp_seo_review_row['id'], 'xnetvn_seo_review_marker', true ) === $xnetvn_agent_hub_wp_seo_review_marker;
			} elseif ( 'post' === ( $xnetvn_agent_hub_wp_seo_review_row['kind'] ?? '' ) || 'media' === ( $xnetvn_agent_hub_wp_seo_review_row['kind'] ?? '' ) ) {
				$xnetvn_agent_hub_wp_seo_review_post   = get_post( (int) $xnetvn_agent_hub_wp_seo_review_row['id'] );
				$xnetvn_agent_hub_wp_seo_review_exists = $xnetvn_agent_hub_wp_seo_review_post instanceof WP_Post;
			} elseif ( 'term' === ( $xnetvn_agent_hub_wp_seo_review_row['kind'] ?? '' ) ) {
				$xnetvn_agent_hub_wp_seo_review_term   = get_term( (int) $xnetvn_agent_hub_wp_seo_review_row['id'] );
				$xnetvn_agent_hub_wp_seo_review_exists = $xnetvn_agent_hub_wp_seo_review_term instanceof WP_Term;
			}
			$xnetvn_agent_hub_wp_seo_review_rows[ $xnetvn_agent_hub_wp_seo_review_ref ] = $xnetvn_agent_hub_wp_seo_review_exists;
		}
		$xnetvn_agent_hub_wp_seo_review_credentials = xnetvn_agent_hub_wp_seo_review_credentials( $xnetvn_agent_hub_wp_seo_review_campaign_obj->credentials_path() );
		xnetvn_agent_hub_wp_seo_review_report(
			array(
				'inspect'     => true,
				'objects'     => $xnetvn_agent_hub_wp_seo_review_rows,
				'retained'    => xnetvn_agent_hub_wp_seo_review_retained( $xnetvn_agent_hub_wp_seo_review_campaign_obj ),
				'credentials' => array() === $xnetvn_agent_hub_wp_seo_review_credentials ? 'absent' : 'present',
			)
		);
		return;
	}

	if ( 'reconcile' === $xnetvn_agent_hub_wp_seo_review_action ) {
		$xnetvn_agent_hub_wp_seo_review_found                  = array();
		$xnetvn_agent_hub_wp_seo_review_slug                   = ContentPlan::slug( $xnetvn_agent_hub_wp_seo_review_tag );
		$xnetvn_agent_hub_wp_seo_review_state                  = $xnetvn_agent_hub_wp_seo_review_journal->open();
		$xnetvn_agent_hub_wp_seo_review_owner                  = (int) ( $xnetvn_agent_hub_wp_seo_review_state['objects']['user:author']['id'] ?? 0 );
		$xnetvn_agent_hub_wp_seo_review_posts                  = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'draft', 'pending', 'private', 'publish' ),
				'numberposts' => 20,
				'fields'      => 'ids',
				'name'        => $xnetvn_agent_hub_wp_seo_review_slug,
			)
		);
		$xnetvn_agent_hub_wp_seo_review_posts                  = array_values( array_filter( array_map( 'intval', $xnetvn_agent_hub_wp_seo_review_posts ), static fn( int $id ): bool => (int) get_post_field( 'post_author', $id ) === $xnetvn_agent_hub_wp_seo_review_owner ) );
		$xnetvn_agent_hub_wp_seo_review_found['post:bai-viet'] = $xnetvn_agent_hub_wp_seo_review_posts;
		foreach ( ContentPlan::images() as $xnetvn_agent_hub_wp_seo_review_image ) {
			$xnetvn_agent_hub_wp_seo_review_marker = Marker::build( $xnetvn_agent_hub_wp_seo_review_campaign_obj->target(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->blog(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign(), 'media', $xnetvn_agent_hub_wp_seo_review_image['slot'] );
			$xnetvn_agent_hub_wp_seo_review_media  = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 20,
					'fields'      => 'ids',
					's'           => $xnetvn_agent_hub_wp_seo_review_marker,
				)
			);
			$xnetvn_agent_hub_wp_seo_review_media  = array_values(
				array_filter(
					array_map( 'intval', $xnetvn_agent_hub_wp_seo_review_media ),
					static fn( int $id ): bool => (int) get_post_field( 'post_author', $id ) === $xnetvn_agent_hub_wp_seo_review_owner && trim( (string) get_post_field( 'post_content', $id ) ) === $xnetvn_agent_hub_wp_seo_review_marker
				)
			);
			$xnetvn_agent_hub_wp_seo_review_found[ 'media:' . $xnetvn_agent_hub_wp_seo_review_image['slot'] ] = $xnetvn_agent_hub_wp_seo_review_media;
		}
		foreach ( array(
			'author'   => 'user:author',
			'operator' => 'user:operator',
		) as $xnetvn_agent_hub_wp_seo_review_alias => $xnetvn_agent_hub_wp_seo_review_ref ) {
			$xnetvn_agent_hub_wp_seo_review_login = 'seo-review-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->target() . '-' . $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign() . '-' . $xnetvn_agent_hub_wp_seo_review_alias;
			$xnetvn_agent_hub_wp_seo_review_user  = get_user_by( 'login', $xnetvn_agent_hub_wp_seo_review_login );
			$xnetvn_agent_hub_wp_seo_review_found[ $xnetvn_agent_hub_wp_seo_review_ref ] = $xnetvn_agent_hub_wp_seo_review_user instanceof WP_User && (string) get_user_meta( $xnetvn_agent_hub_wp_seo_review_user->ID, 'xnetvn_seo_review_marker', true ) === Marker::build( $xnetvn_agent_hub_wp_seo_review_campaign_obj->target(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->blog(), $xnetvn_agent_hub_wp_seo_review_campaign_obj->campaign(), 'user', $xnetvn_agent_hub_wp_seo_review_alias ) ? array( (int) $xnetvn_agent_hub_wp_seo_review_user->ID ) : array();
		}
		$xnetvn_agent_hub_wp_seo_review_terms = array(
			'term:category' => ContentPlan::category( $xnetvn_agent_hub_wp_seo_review_tag ),
			'term:tag-1'    => ContentPlan::tags( $xnetvn_agent_hub_wp_seo_review_tag )[0],
			'term:tag-2'    => ContentPlan::tags( $xnetvn_agent_hub_wp_seo_review_tag )[1],
		);
		foreach ( $xnetvn_agent_hub_wp_seo_review_terms as $xnetvn_agent_hub_wp_seo_review_ref => $xnetvn_agent_hub_wp_seo_review_spec ) {
			$xnetvn_agent_hub_wp_seo_review_term = get_term_by( 'slug', $xnetvn_agent_hub_wp_seo_review_spec['slug'], $xnetvn_agent_hub_wp_seo_review_spec['taxonomy'] );
			$xnetvn_agent_hub_wp_seo_review_found[ $xnetvn_agent_hub_wp_seo_review_ref ] = $xnetvn_agent_hub_wp_seo_review_term instanceof WP_Term ? array( (int) $xnetvn_agent_hub_wp_seo_review_term->term_id ) : array();
		}
		$xnetvn_agent_hub_wp_seo_review_ambiguous = 0;
		foreach ( $xnetvn_agent_hub_wp_seo_review_found as $xnetvn_agent_hub_wp_seo_review_ids ) {
			if ( 1 < count( $xnetvn_agent_hub_wp_seo_review_ids ) ) {
				++$xnetvn_agent_hub_wp_seo_review_ambiguous;
			}
		}
		xnetvn_agent_hub_wp_seo_review_report(
			array(
				'reconcile' => true,
				'found'     => array_map( 'count', $xnetvn_agent_hub_wp_seo_review_found ),
				'ambiguous' => $xnetvn_agent_hub_wp_seo_review_ambiguous,
			)
		);
		if ( 0 < $xnetvn_agent_hub_wp_seo_review_ambiguous ) {
			exit( 2 );
		}
		return;
	}

	if ( 'credentials-cleanup' === $xnetvn_agent_hub_wp_seo_review_action ) {
		global $wpdb;
		$xnetvn_agent_hub_wp_seo_review_repo = new WpdbKeyRepository( $wpdb, new SystemClock() );
		$xnetvn_agent_hub_wp_seo_review_file = $xnetvn_agent_hub_wp_seo_review_campaign_obj->credentials_path();
		if ( is_link( $xnetvn_agent_hub_wp_seo_review_file ) ) {
			throw new RuntimeException( 'SEO review credential ownership mismatch.' );
		}
		$xnetvn_agent_hub_wp_seo_review_store   = xnetvn_agent_hub_wp_seo_review_credentials( $xnetvn_agent_hub_wp_seo_review_file );
		$xnetvn_agent_hub_wp_seo_review_revoked = 0;
		foreach ( (array) ( $xnetvn_agent_hub_wp_seo_review_store['actors'] ?? array() ) as $xnetvn_agent_hub_wp_seo_review_actor ) {
			if ( ! is_array( $xnetvn_agent_hub_wp_seo_review_actor ) ) {
				continue;
			}
			$xnetvn_agent_hub_wp_seo_review_row = $xnetvn_agent_hub_wp_seo_review_repo->find_by_id( (int) ( $xnetvn_agent_hub_wp_seo_review_actor['key_row_id'] ?? 0 ) );
			if ( null === $xnetvn_agent_hub_wp_seo_review_row ) {
				continue;
			}
			if ( ! str_starts_with( $xnetvn_agent_hub_wp_seo_review_row->name, 'SEO review ' ) || (int) ( $xnetvn_agent_hub_wp_seo_review_actor['user_id'] ?? 0 ) !== $xnetvn_agent_hub_wp_seo_review_row->user_id ) {
				throw new RuntimeException( 'SEO review credential binding mismatch.' );
			}
			$xnetvn_agent_hub_wp_seo_review_deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $xnetvn_agent_hub_wp_seo_review_row->id, $xnetvn_agent_hub_wp_seo_review_row->key_id->value, $xnetvn_agent_hub_wp_seo_review_row->user_id ) );
			if ( false === $xnetvn_agent_hub_wp_seo_review_deleted || null !== $xnetvn_agent_hub_wp_seo_review_repo->find_by_id( $xnetvn_agent_hub_wp_seo_review_row->id ) ) {
				throw new RuntimeException( 'SEO review credential cleanup failed.' );
			}
			++$xnetvn_agent_hub_wp_seo_review_revoked;
		}
		$xnetvn_agent_hub_wp_seo_review_cleanup = Flow::cleanup_run_files( $xnetvn_agent_hub_wp_seo_review_campaign_obj );
		xnetvn_agent_hub_wp_seo_review_report(
			array(
				'keys_revoked'        => $xnetvn_agent_hub_wp_seo_review_revoked,
				'credentials_removed' => $xnetvn_agent_hub_wp_seo_review_cleanup['credentials_removed'],
				'temp_removed'        => $xnetvn_agent_hub_wp_seo_review_cleanup['temp_removed'],
				'retained'            => xnetvn_agent_hub_wp_seo_review_retained( $xnetvn_agent_hub_wp_seo_review_campaign_obj ),
			)
		);
		return;
	}
} catch ( Throwable $error ) {
	WP_CLI::line(
		(string) Json::encode(
			array(
				'error'  => 'seo_review_guard',
				'reason' => substr( preg_replace( '/[^A-Za-z0-9 _-]/', '', $error->getMessage() ), 0, 120 ),
			)
		)
	);
	exit( 2 );
}

exit( 2 );
