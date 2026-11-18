<?php
/**
 * Cookie-authenticated site key management REST tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteKeyService;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\Schema;
use XNetVN\AgentHubWP\Persistence\SiteKeyLock;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\RateLimit\WpExpensiveBudget;
use XNetVN\AgentHubWP\Rest\ManagementGate;
use XNetVN\AgentHubWP\Rest\SiteKeysController;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves the six cookie-only management routes and the persisted-row boundaries. */
final class SiteKeysControllerTest extends WP_UnitTestCase {
	/** Fixture password; never a real credential. */
	private const PASSWORD = 'Fixture-only-password-93';

	/** Exact reviewed view keys. */
	private const VIEW_FIELDS = array(
		'id',
		'key_id',
		'credential_ref',
		'principal_type',
		'blog_id',
		'name',
		'profile',
		'toolsets',
		'allowed_abilities',
		'denied_abilities',
		'permissions',
		'ip_allowlist',
		'tier_ceiling',
		'expires_at',
		'created_at',
		'revoked_at',
		'created_by',
		'revoked_by',
		'last_used_at',
		'last_used_ip',
	);

	/** Disposable autocommit connections.
	 *
	 * @var array<\wpdb>
	 */
	private array $connections = array();

	/** Fixed clock shared by storage, service and tests.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;

	/** Disposable autocommit database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/** Repository bound to the disposable database.
	 *
	 * @var WpdbSiteKeyRepository
	 */
	private WpdbSiteKeyRepository $repo;

	/** Mutable site policy fixture.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/** Service under test.
	 *
	 * @var SiteKeyService
	 */
	private SiteKeyService $service;

	/** Non-super-admin site administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/** Editor without management capability.
	 *
	 * @var int
	 */
	private int $editor;

	/** Original cookie snapshot.
	 *
	 * @var array<string,mixed>
	 */
	private array $cookies;

	/** Current session nonce.
	 *
	 * @var string
	 */
	private string $nonce = '';

	/** Original REST server.
	 *
	 * @var mixed
	 */
	private mixed $previous_server = null;

	/** Site-key rows created through the real network tables.
	 *
	 * @var array<array{int,int}>
	 */
	private array $created_rows = array();

	/** Install the real schema, a disposable lifecycle database and synthetic actors. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		global $wpdb;
		$server = $wp_rest_server ?? rest_get_server();
		if ( 0 === did_action( 'rest_api_init' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Initialize core REST registration once before isolating the fixture server.
			do_action( 'rest_api_init', $server );
		}
		$this->cookies         = $_COOKIE;
		$this->previous_server = $server;
		$this->clock           = new FrozenClock( 1791072000 );
		$this->settings        = new MemorySettings();
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$this->db      = $this->disposable();
		$this->repo    = new WpdbSiteKeyRepository( $this->db, $this->clock, 1 );
		$this->service = $this->service( $this->db, 1 );
		$this->admin   = (int) self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => self::PASSWORD,
			)
		);
		$this->editor  = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/** Remove only fixture cookies, globals, rows and disposable tables. */
	public function tear_down(): void {
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		foreach ( $this->created_rows as $entry ) {
			$this->delete_site_row( $entry[0], $entry[1] );
		}
		foreach ( $this->connections as $db ) {
			remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			try {
				foreach ( array( 'keys', 'audit', 'site_keys' ) as $suffix ) {
					$db->query( $db->prepare( 'DROP TABLE IF EXISTS %i', $db->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) );
				}
			} finally {
				add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
				$db->close();
			}
		}
		$_COOKIE = $this->cookies;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$GLOBALS['wp_rest_server'] = $this->previous_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore the prior core fixture.
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** The positive lifecycle, one-time disclosure, no-store and state filters. */
	public function test_lifecycle_lists_narrows_rotates_and_revokes(): void {
		$this->login( $this->admin );
		$this->controller();

		$created = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Lifecycle fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
			)
		);
		self::assertSame( 201, $created->get_status() );
		$issued = $created->get_data();
		self::assertSame( array( 'key', 'secret' ), array_keys( $issued ) );
		self::assertMatchesRegularExpression( '/\Axn[s]_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', (string) $issued['secret'] );
		$secret = (string) $issued['secret'];
		$id     = (int) $issued['key']['id'];
		$this->assert_view( $issued['key'], 1 );
		self::assertSame( $this->admin, $issued['key']['created_by'] );
		self::assertSame(
			( new DateTimeImmutable( '@' . ( $this->clock->timestamp + 30 * DAY_IN_SECONDS ) ) )->format( DATE_ATOM ),
			$issued['key']['expires_at']
		);
		self::assertSame( 'no-store', $created->get_headers()['Cache-Control'] );

		$rows = $this->rows();
		self::assertCount( 1, $rows );
		self::assertSame( 1, (int) $rows[0]['blog_id'] );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', (string) $rows[0]['secret_hash'] );
		self::assertSame( 1, (int) $rows[0]['pepper_id'] );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $rows ) );

		$list = $this->call( 'GET', '/site-keys' );
		self::assertSame( 200, $list->get_status() );
		$payload = $list->get_data();
		self::assertSame( array( 'items', 'page', 'per_page' ), array_keys( $payload ) );
		self::assertSame( 1, $payload['page'] );
		self::assertSame( 20, $payload['per_page'] );
		self::assertCount( 1, $payload['items'] );
		$this->assert_view( $payload['items'][0], 1 );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $payload ) );
		self::assertArrayNotHasKey( 'X-WP-Total', $list->get_headers() );
		self::assertArrayNotHasKey( 'X-WP-TotalPages', $list->get_headers() );
		self::assertSame( 'no-store', $list->get_headers()['Cache-Control'] );

		$item = $this->call( 'GET', '/site-keys/' . $id );
		self::assertSame( 200, $item->get_status() );
		$this->assert_view( $item->get_data(), 1 );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $item->get_data() ) );
		self::assertSame( 'no-store', $item->get_headers()['Cache-Control'] );

		$patched = $this->call(
			'PATCH',
			'/site-keys/' . $id,
			array(
				'name'         => 'Narrowed fixture',
				'ip_allowlist' => array( '203.0.113.0/24' ),
			)
		);
		self::assertSame( 200, $patched->get_status() );
		$this->assert_view( $patched->get_data(), 1 );
		self::assertSame( 'Narrowed fixture', $patched->get_data()['name'] );
		self::assertSame( array( '203.0.113.0/24' ), $patched->get_data()['ip_allowlist'] );
		self::assertStringNotContainsString( $secret, (string) wp_json_encode( $patched->get_data() ) );
		self::assertSame( 'no-store', $patched->get_headers()['Cache-Control'] );
		self::assertSame( 200, $this->call( 'PATCH', '/site-keys/' . $id )->get_status() );

		$rotated = $this->call(
			'POST',
			'/site-keys/' . $id . '/rotate',
			array(
				'current_password' => self::PASSWORD,
				'grace_hours'      => 4,
			)
		);
		self::assertSame( 201, $rotated->get_status() );
		$replacement = $rotated->get_data();
		self::assertSame( array( 'key', 'secret' ), array_keys( $replacement ) );
		self::assertNotSame( $secret, $replacement['secret'] );
		self::assertMatchesRegularExpression( '/\Axn[s]_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', (string) $replacement['secret'] );
		$this->assert_view( $replacement['key'], 1 );
		self::assertNotSame( $id, $replacement['key']['id'] );
		self::assertSame( 'no-store', $rotated->get_headers()['Cache-Control'] );
		self::assertCount( 2, $this->rows() );
		$old = $this->call( 'GET', '/site-keys/' . $id );
		self::assertSame( 200, $old->get_status() );
		self::assertSame(
			( new DateTimeImmutable( '@' . ( $this->clock->timestamp + 4 * HOUR_IN_SECONDS ) ) )->format( DATE_ATOM ),
			$old->get_data()['expires_at']
		);

		$this->clock->timestamp += 5 * HOUR_IN_SECONDS;
		self::assertSame( 404, $this->call( 'PATCH', '/site-keys/' . $id, array( 'name' => 'Expired' ) )->get_status() );
		self::assertSame( 404, $this->call( 'POST', '/site-keys/' . $id . '/rotate', array( 'current_password' => self::PASSWORD ) )->get_status() );
		self::assertSame( 200, $this->call( 'GET', '/site-keys/' . $id )->get_status() );

		$new_id  = (int) $replacement['key']['id'];
		$deleted = $this->call( 'DELETE', '/site-keys/' . $new_id );
		self::assertSame( 200, $deleted->get_status() );
		self::assertSame(
			array(
				'id'      => $new_id,
				'revoked' => true,
			),
			$deleted->get_data()
		);
		self::assertSame( 'no-store', $deleted->get_headers()['Cache-Control'] );
		$again = $this->call( 'DELETE', '/site-keys/' . $new_id );
		self::assertSame( 200, $again->get_status() );
		self::assertSame(
			array(
				'id'      => $new_id,
				'revoked' => false,
			),
			$again->get_data()
		);
		$revoked = $this->call( 'GET', '/site-keys/' . $new_id );
		self::assertSame( 200, $revoked->get_status() );
		self::assertNotNull( $revoked->get_data()['revoked_at'] );
		self::assertSame( 404, $this->call( 'PATCH', '/site-keys/' . $new_id, array( 'name' => 'Revoked' ) )->get_status() );
		self::assertSame( 404, $this->call( 'POST', '/site-keys/' . $new_id . '/rotate', array( 'current_password' => self::PASSWORD ) )->get_status() );

		self::assertCount( 0, $this->listing( array( 'state' => 'active' ) )->get_data()['items'] );
		self::assertCount( 1, $this->listing( array( 'state' => 'revoked' ) )->get_data()['items'] );
		self::assertCount( 1, $this->listing( array( 'state' => 'expired' ) )->get_data()['items'] );
		self::assertCount( 2, $this->listing( array( 'state' => 'all' ) )->get_data()['items'] );
	}

	/** Strict schemas and authentication refusals never write. */
	public function test_strict_refusals_leave_persisted_state_unchanged(): void {
		$this->login( $this->admin );
		$this->controller();
		$created = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Refusal fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
			)
		);
		self::assertSame( 201, $created->get_status() );
		$id     = (int) $created->get_data()['key']['id'];
		$before = $this->rows();

		foreach ( array( 'user_id', 'owner_user_id', 'blog_id', 'principal_type', 'created_by' ) as $field ) {
			$this->assert_status(
				400,
				$this->call(
					'POST',
					'/site-keys',
					array(
						'name'             => 'Forbidden',
						'current_password' => self::PASSWORD,
						$field             => 1,
					)
				)
			);
		}
		$this->assert_status(
			400,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Unknown',
					'current_password' => self::PASSWORD,
					'role'             => 'administrator',
				)
			)
		);
		$this->assert_status(
			400,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Null expiry',
					'current_password' => self::PASSWORD,
					'expires_in_days'  => null,
				)
			)
		);
		$this->assert_status(
			400,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Too long',
					'current_password' => self::PASSWORD,
					'expires_in_days'  => 366,
				)
			)
		);
		$this->assert_status( 400, $this->raw( 'POST', '/site-keys', '[]' ) );
		$this->assert_status( 400, $this->raw( 'PATCH', '/site-keys/' . $id, '[]' ) );
		$this->assert_status(
			403,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Moderation',
					'current_password' => self::PASSWORD,
					'permissions'      => array( 'ban-users' ),
				)
			)
		);
		$wrong = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Wrong password',
				'current_password' => 'wrong-password',
			)
		);
		$this->assert_status( 403, $wrong );
		self::assertStringNotContainsString( 'wrong-password', (string) wp_json_encode( $wrong->get_data() ) );
		$this->assert_status(
			400,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'current_password' => self::PASSWORD )
			)
		);
		$this->assert_status(
			400,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'expires_in_days' => null )
			)
		);
		$this->assert_status(
			400,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'expires_in_days' => 366 )
			)
		);
		$this->assert_status(
			400,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'name' => str_repeat( 'a', 101 ) )
			)
		);
		$this->assert_status(
			409,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'expires_in_days' => 90 )
			)
		);
		$this->assert_status(
			409,
			$this->call(
				'PATCH',
				'/site-keys/' . $id,
				array( 'ability_allow' => array( 'xnetvn/set-user-status' ) )
			)
		);
		$this->assert_status( 404, $this->call( 'GET', '/site-keys/999' ) );
		$this->assert_status( 404, $this->call( 'PATCH', '/site-keys/999', array( 'name' => 'Absent' ) ) );
		$this->assert_status( 404, $this->call( 'DELETE', '/site-keys/999' ) );
		$this->assert_status( 404, $this->call( 'POST', '/site-keys/999/rotate', array( 'current_password' => self::PASSWORD ) ) );

		self::assertSame( $before, $this->rows() );

		foreach ( array( array( 'user_id' => 1 ), array( 'blog_id' => 1 ), array( 'all' => true ), array( 'filter' => 'x' ) ) as $params ) {
			$this->assert_status( 400, $this->listing( $params ) );
		}
		$this->assert_status( 400, $this->listing( array( 'state' => 'bogus' ) ) );
		$this->assert_status( 400, $this->listing( array( 'per_page' => 101 ) ) );

		$cookie = (string) $_COOKIE[ LOGGED_IN_COOKIE ];
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		try {
			wp_set_current_user( 0 );
			$unauthorized = $this->call( 'GET', '/site-keys' );
			$this->assert_status( 401, $unauthorized );
			$this->assert_no_store( $unauthorized );

			wp_set_current_user( $this->admin );
			$this->assert_status( 401, $this->call( 'GET', '/site-keys' ) );

			wp_set_current_user( 0 );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xns_abcdefghijkl_' . str_repeat( 'A', 43 );
			$this->assert_status( 401, $this->call( 'GET', '/site-keys' ) );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'C', 43 );
			$this->assert_status( 401, $this->call( 'GET', '/site-keys' ) );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Basic Zml4dHVyZTphcHBsaWNhdGlvbi1wYXNzd29yZA==';
			$this->assert_status( 401, $this->call( 'GET', '/site-keys' ) );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		} finally {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		}
		wp_set_current_user( $this->admin );

		$no_nonce = $this->call( 'GET', '/site-keys', array(), false );
		$this->assert_status( 403, $no_nonce );
		$this->assert_no_store( $no_nonce );

		$this->login( $this->editor );
		$editor = $this->call( 'GET', '/site-keys' );
		$this->assert_status( 403, $editor );
		$this->assert_no_store( $editor );
		$this->assert_status(
			403,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Editor',
					'current_password' => self::PASSWORD,
				)
			)
		);

		self::assertSame( $before, $this->rows() );
	}

	/** Permission removal is a narrowing; permission, toolset, CIDR and expiry widening stay refused. */
	public function test_scope_direction_allows_removal_and_refuses_addition(): void {
		$this->login( $this->admin );
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$this->controller();

		$created = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Moderation fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
				'permissions'      => array( 'ban-users' ),
			)
		);
		self::assertSame( 201, $created->get_status() );
		$view = $created->get_data()['key'];
		self::assertSame( array( 'ban-users' ), $view['permissions'] );
		self::assertSame( 'admin', $view['tier_ceiling'] );
		self::assertContains( 'users', $view['toolsets'] );
		self::assertContains( 'xnetvn/set-user-status', $view['allowed_abilities'] );
		$id     = (int) $view['id'];
		$before = $this->row( $id );

		$removal = $this->call( 'PATCH', '/site-keys/' . $id, array( 'permissions' => array() ) );
		self::assertSame( 200, $removal->get_status() );
		self::assertSame( array(), $removal->get_data()['permissions'] );
		self::assertSame( 'no-store', $removal->get_headers()['Cache-Control'] );
		$after = $this->row( $id );
		foreach ( array( 'name', 'expires_at', 'revoked_at', 'revoked_by', 'secret_hash', 'pepper_id', 'created_by' ) as $column ) {
			self::assertSame( $before[ $column ], $after[ $column ] );
		}
		$old_scope = json_decode( (string) $before['scope_json'], true, 512, JSON_THROW_ON_ERROR );
		$new_scope = json_decode( (string) $after['scope_json'], true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $old_scope );
		self::assertIsArray( $new_scope );
		self::assertSame( array( 'ban-users' ), $old_scope['permissions'] );
		self::assertSame( array(), $new_scope['permissions'] );
		$old_scope['permissions'] = array();
		self::assertSame( $old_scope, $new_scope );

		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $id, array( 'permissions' => array( 'ban-users' ) ) )->get_status() );
		self::assertSame( $after['scope_json'], $this->row( $id )['scope_json'] );

		$open = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'CIDR fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
			)
		);
		self::assertSame( 201, $open->get_status() );
		$open_id = (int) $open->get_data()['key']['id'];
		self::assertSame( array(), $open->get_data()['key']['ip_allowlist'] );
		$restricted = $this->call( 'PATCH', '/site-keys/' . $open_id, array( 'ip_allowlist' => array( '203.0.113.0/24' ) ) );
		self::assertSame( 200, $restricted->get_status() );
		self::assertSame( array( '203.0.113.0/24' ), $restricted->get_data()['ip_allowlist'] );
		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $open_id, array( 'ip_allowlist' => array( '0.0.0.0/0' ) ) )->get_status() );
		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $open_id, array( 'ip_allowlist' => array() ) )->get_status() );
		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $open_id, array( 'ip_allowlist' => array( '198.51.100.0/24' ) ) )->get_status() );
		self::assertSame( 200, $this->call( 'PATCH', '/site-keys/' . $open_id, array( 'ip_allowlist' => array( '203.0.113.128/25' ) ) )->get_status() );

		$restricted_tools = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Toolset fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
				'toolsets'         => array( 'content' ),
			)
		);
		self::assertSame( 201, $restricted_tools->get_status() );
		$tools_id = (int) $restricted_tools->get_data()['key']['id'];
		self::assertSame( array( 'content', 'system' ), $restricted_tools->get_data()['key']['toolsets'] );
		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $tools_id, array( 'toolsets' => array( 'content', 'media' ) ) )->get_status() );

		$expiry = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Expiry fixture',
				'current_password' => self::PASSWORD,
				'expires_in_days'  => 30,
			)
		);
		self::assertSame( 201, $expiry->get_status() );
		$expiry_id = (int) $expiry->get_data()['key']['id'];
		self::assertSame( 409, $this->call( 'PATCH', '/site-keys/' . $expiry_id, array( 'expires_in_days' => 31 ) )->get_status() );
		self::assertSame( 200, $this->call( 'PATCH', '/site-keys/' . $expiry_id, array( 'expires_in_days' => 10 ) )->get_status() );
	}

	/** A live capability loss denies the patch and leaves the row intact. */
	public function test_capability_loss_blocks_patch(): void {
		$this->login( $this->admin );
		$this->controller();
		$created = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Capability fixture',
				'current_password' => self::PASSWORD,
			)
		);
		self::assertSame( 201, $created->get_status() );
		$id     = (int) $created->get_data()['key']['id'];
		$before = $this->rows();
		$admin  = $this->admin;
		self::assertTrue( current_user_can( Capabilities::MANAGE ) );

		$deny = static function ( array $allcaps, array $caps, array $args, \WP_User $user ) use ( $admin ): array {
			if ( $user->ID === $admin ) {
				$allcaps['manage_options'] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny, 9, 4 );
		try {
			self::assertFalse( current_user_can( Capabilities::MANAGE ) );
			$response = $this->call( 'PATCH', '/site-keys/' . $id, array( 'name' => 'Denied' ) );
			$this->assert_status( 403, $response );
			$this->assert_no_store( $response );
		} finally {
			remove_filter( 'user_has_cap', $deny, 9 );
		}
		self::assertTrue( current_user_can( Capabilities::MANAGE ) );
		self::assertSame( $before, $this->rows() );
	}

	/** Quota, password retry budget and lock failure fail closed. */
	public function test_quota_rate_budget_and_lock_failure(): void {
		$this->login( $this->admin );
		$this->controller();

		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_blog_id( 1 );
		$lock = 'xnetvn_ahwp_sk_1';
		self::assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) );
		try {
			$this->assert_status(
				503,
				$this->call(
					'POST',
					'/site-keys',
					array(
						'name'             => 'Locked',
						'current_password' => self::PASSWORD,
					)
				)
			);
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		self::assertCount( 0, $this->rows() );

		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			$this->assert_status(
				403,
				$this->call(
					'POST',
					'/site-keys',
					array(
						'name'             => 'Wrong password ' . $attempt,
						'current_password' => 'wrong-password',
					)
				)
			);
		}
		$this->assert_status(
			429,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Retry budget',
					'current_password' => 'wrong-password',
				)
			)
		);
		self::assertCount( 0, $this->rows() );

		for ( $seed = 1; $seed <= SiteKeyService::ACTIVE_QUOTA; ++$seed ) {
			$this->seed( $seed );
		}
		self::assertCount( SiteKeyService::ACTIVE_QUOTA, $this->rows() );
		self::assertSame( SiteKeyService::ACTIVE_QUOTA, $this->repo->count_active(), 'The quota case must start from a full active set.' );
		$this->assert_status(
			409,
			$this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Quota',
					'current_password' => self::PASSWORD,
				)
			)
		);
		self::assertCount( SiteKeyService::ACTIVE_QUOTA, $this->rows() );
	}

	/** The boot-wired route is registered and still requires the cookie gate. */
	public function test_boot_wired_route_serves_only_cookie_managers(): void {
		global $wp_rest_server;
		$this->login( $this->admin );
		$this->install_server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register the real boot route fixture.
		do_action( 'rest_api_init', $wp_rest_server );
		$response = $this->call( 'GET', '/site-keys' );
		$this->assert_status( 200, $response );
		self::assertSame( array( 'items', 'page', 'per_page' ), array_keys( $response->get_data() ) );
		self::assertIsArray( $response->get_data()['items'] );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		$denied = $this->call( 'GET', '/site-keys' );
		$this->assert_status( 401, $denied );
		$this->assert_no_store( $denied );
	}

	/** Cross-blog reads never select another site's credential and the stale guard refuses first. */
	public function test_multisite_cross_blog_keys_stay_isolated(): void {
		$this->require_controller();
		if ( ! is_multisite() ) {
			$this->skipWithoutMultisite();
		}
		global $wpdb;
		$this->login( $this->admin );
		$blog_one_service = $this->service( $wpdb, 1 );
		$blog_one         = new SiteKeysController( new ManagementGate(), $blog_one_service, 1 );
		$this->install_server();
		$this->use_controller( $blog_one );
		$first = $this->call(
			'POST',
			'/site-keys',
			array(
				'name'             => 'Blog one fixture',
				'current_password' => self::PASSWORD,
			)
		);
		self::assertSame( 201, $first->get_status() );
		$blog_one_id          = (int) $first->get_data()['key']['id'];
		$blog_one_key         = (string) $first->get_data()['key']['key_id'];
		$this->created_rows[] = array( 1, $blog_one_id );

		$site = self::factory()->blog->create();
		self::assertIsInt( $site );
		self::assertNotFalse( get_blog_details( $site ) );
		$site_admin = (int) self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => self::PASSWORD,
			)
		);
		add_user_to_blog( $site, $site_admin, 'administrator' );

		$site_key = '';
		$site_id  = 0;
		switch_to_blog( $site );
		try {
			self::assertSame( $site, get_current_blog_id() );
			self::assertTrue( ( new Migrator( $wpdb, $this->clock ) )->migrate() );
			$this->login( $site_admin );
			self::assertTrue( current_user_can( Capabilities::MANAGE ) );
			$site_controller = new SiteKeysController( new ManagementGate(), $this->service( $wpdb, $site ), $site );
			$this->install_server();
			$this->use_controller( $site_controller );
			$created = $this->call(
				'POST',
				'/site-keys',
				array(
					'name'             => 'Site fixture',
					'current_password' => self::PASSWORD,
				)
			);
			self::assertSame( 201, $created->get_status() );
			$site_id              = (int) $created->get_data()['key']['id'];
			$site_key             = (string) $created->get_data()['key']['key_id'];
			$this->created_rows[] = array( $site, $site_id );
			self::assertSame( $site, $created->get_data()['key']['blog_id'] );
			$list = $this->call( 'GET', '/site-keys' );
			self::assertSame( 200, $list->get_status() );
			self::assertCount( 1, $list->get_data()['items'] );
			self::assertSame( $site, $list->get_data()['items'][0]['blog_id'] );
			self::assertSame( $site_key, $list->get_data()['items'][0]['key_id'] );

			$stale = new SiteKeysController( new ManagementGate(), $blog_one_service, 1 );
			$this->install_server();
			$this->use_controller( $stale );
			self::assertSame( 404, $this->call( 'GET', '/site-keys/' . $site_id )->get_status() );
			self::assertSame( 404, $this->call( 'GET', '/site-keys' )->get_status() );
		} finally {
			restore_current_blog();
		}

		$this->login( $this->admin );
		$local = new SiteKeysController( new ManagementGate(), $blog_one_service, 1 );
		$this->install_server();
		$this->use_controller( $local );
		$list = $this->call( 'GET', '/site-keys' );
		self::assertSame( 200, $list->get_status() );
		$ids = array_column( $list->get_data()['items'], 'key_id' );
		self::assertContains( $blog_one_key, $ids );
		self::assertNotContains( $site_key, $ids );
		foreach ( $list->get_data()['items'] as $view ) {
			self::assertSame( 1, $view['blog_id'] );
		}
		$cross = $this->call( 'GET', '/site-keys/' . $site_id );
		if ( $site_id === $blog_one_id ) {
			self::assertSame( 200, $cross->get_status() );
			self::assertSame( 1, $cross->get_data()['blog_id'] );
			self::assertSame( $blog_one_key, $cross->get_data()['key_id'] );
		} else {
			self::assertSame( 404, $cross->get_status() );
		}
		self::assertNotSame( $site_key, $cross->get_data()['key_id'] ?? '' );
	}

	/**
	 * Build the isolated controller on a fresh REST server.
	 *
	 * @return object
	 */
	private function controller(): object {
		$this->require_controller();
		$this->install_server();
		$controller = new SiteKeysController( new ManagementGate(), $this->service, 1 );
		$this->use_controller( $controller );
		return $controller;
	}

	/**
	 * Register fixture routes on the isolated server and prove they will dispatch.
	 *
	 * The boot-wired controllers are already bound to the default server; firing
	 * rest_api_init again here would register them on this fixture server too and
	 * let their real service shadow the fixture route.
	 *
	 * @param SiteKeysController $controller Fixture controller.
	 */
	private function use_controller( SiteKeysController $controller ): void {
		$controller->register_routes();
		$namespace = ManagementGate::REST_NAMESPACE;
		$routes    = rest_get_server()->get_routes( $namespace );
		$route     = $routes[ '/' . $namespace . '/site-keys' ] ?? array();
		self::assertSame( $controller, $route[0]['callback'][0] ?? null, 'The fixture server must dispatch the fixture controller.' );
	}

	/** Skip only the direct-controller cases while the runtime class is absent. */
	private function require_controller(): void {
		if ( ! class_exists( SiteKeysController::class ) ) {
			$this->markTestSkipped( 'SiteKeysController is not implemented yet.' );
		}
	}

	/** Replace the global REST server for one isolated dispatch. */
	private function install_server(): void {
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Replace the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
	}

	/**
	 * Build one service on an explicit connection and blog.
	 *
	 * @param \wpdb $db Database.
	 * @param int   $blog_id Blog identity.
	 * @return SiteKeyService
	 */
	private function service( \wpdb $db, int $blog_id ): SiteKeyService {
		global $wpdb;
		$limits = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock );
		return new SiteKeyService(
			new WpdbSiteKeyRepository( $db, $this->clock, $blog_id ),
			new SiteKeyLock( $db ),
			new SiteTokenCodec(),
			new PepperStore(),
			$this->settings,
			$this->registry( $limits ),
			$limits,
			$this->clock
		);
	}

	/**
	 * Build the real reviewed catalog.
	 *
	 * @param RateLimiter $limits Shared rate limiter.
	 * @return Registry
	 */
	private function registry( RateLimiter $limits ): Registry {
		global $wpdb;
		$request  = new RequestScope();
		$registry = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ), new WpExpensiveBudget( $wpdb ) );
		$registry->add_pack( new ContentPack( new DefinitionFactory() ) );
		$registry->add_pack( new MediaPack( new DefinitionFactory(), $this->settings ) );
		$registry->add_pack( new CommentsPack( new DefinitionFactory() ) );
		$registry->add_pack( new TaxonomyPack( new DefinitionFactory() ) );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), $limits ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
		$registry->load_packs();
		return $registry;
	}

	/**
	 * Create one disposable autocommit database with the real schema.
	 *
	 * @return \wpdb
	 */
	private function disposable(): \wpdb {
		global $wpdb;
		$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_blog_id( 1 );
		$db->set_prefix( $wpdb->prefix . 'skeys_' . bin2hex( random_bytes( 4 ) ) . '_' );
		$this->connections[] = $db;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			foreach ( Schema::statements( $db ) as $sql ) {
				self::assertNotFalse( $db->query( $sql ) );
			}
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
		return $db;
	}

	/**
	 * Read every persisted disposable row.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return (array) $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i ORDER BY id', $this->table() ), ARRAY_A );
	}

	/**
	 * Read one persisted disposable row.
	 *
	 * @param int $id Record identity.
	 * @return array<string,mixed>
	 */
	private function row( int $id ): array {
		return (array) $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );
	}

	/**
	 * Resolve the disposable site-key table.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'xnetvn_agent_hub_wp_site_keys';
	}

	/**
	 * Insert one live fixture credential through the repository.
	 *
	 * @param int $seed Identifier seed.
	 * @return SiteCredential
	 */
	private function seed( int $seed ): SiteCredential {
		return $this->repo->insert(
			( new SiteTokenCodec() )->generate()->id,
			new SiteScope(
				RiskTier::Write,
				array( 'system' ),
				array( 'xnetvn/get-site-info' ),
				array(),
				array(),
				array(),
				new DateTimeImmutable( '@' . ( $this->clock->timestamp + HOUR_IN_SECONDS ) )
			),
			'Seed ' . $seed,
			str_repeat( 'a', 64 ),
			1,
			$this->admin
		);
	}

	/**
	 * Delete one site-key row from the correct blog.
	 *
	 * @param int $blog_id Blog identity.
	 * @param int $id Record identity.
	 */
	private function delete_site_row( int $blog_id, int $id ): void {
		global $wpdb;
		$current = get_current_blog_id();
		if ( $blog_id !== $current ) {
			switch_to_blog( $blog_id );
		}
		try {
			$wpdb->delete(
				$wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys',
				array(
					'id'      => $id,
					'blog_id' => $blog_id,
				),
				array( '%d', '%d' )
			);
		} finally {
			if ( $blog_id !== $current ) {
				switch_to_blog( $current );
			}
		}
	}

	/**
	 * Set a real logged-in cookie and create its REST nonce.
	 *
	 * @param int $user Owner.
	 */
	private function login( int $user ): void {
		wp_set_current_user( $user );
		$token                       = \WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'logged_in', $token );
		$this->nonce                 = wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Build a nonce-bearing route request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Relative management path.
	 * @param bool   $nonce Include a nonce.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $path, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/' . ManagementGate::REST_NAMESPACE . $path );
		if ( $nonce ) {
			$request->set_header( 'x-wp-nonce', $this->nonce );
		}
		return $request;
	}

	/**
	 * Dispatch a JSON request without making an external HTTP call.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path Path.
	 * @param array<string,mixed> $body Fields.
	 * @param bool                $nonce Include nonce.
	 * @return WP_REST_Response
	 */
	private function call( string $method, string $path, array $body = array(), bool $nonce = true ): WP_REST_Response {
		$request = $this->request( $method, $path, $nonce );
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( (object) $body ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Dispatch an exact raw body.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Path.
	 * @param string $body Exact raw body.
	 * @return WP_REST_Response
	 */
	private function raw( string $method, string $path, string $body ): WP_REST_Response {
		$request = $this->request( $method, $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $body );
		return rest_do_request( $request );
	}

	/**
	 * Dispatch a collection read with explicit query filters.
	 *
	 * @param array<string,mixed> $params Listing filters.
	 * @param bool                $nonce Include nonce.
	 * @return WP_REST_Response
	 */
	private function listing( array $params, bool $nonce = true ): WP_REST_Response {
		$request = $this->request( 'GET', '/site-keys', $nonce );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}

	/**
	 * Assert one status with a safe diagnostic payload.
	 *
	 * @param int              $expected Expected status.
	 * @param WP_REST_Response $response Response.
	 */
	private function assert_status( int $expected, WP_REST_Response $response ): void {
		self::assertSame( $expected, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Assert the management no-store directive after the post-dispatch filter.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assert_no_store( WP_REST_Response $response ): void {
		$request = $this->request( 'GET', '/site-keys' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Apply the core post-dispatch header filter.
		$filtered = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		self::assertInstanceOf( WP_REST_Response::class, $filtered );
		self::assertSame( 'no-store', $filtered->get_headers()['Cache-Control'] );
	}

	/**
	 * Assert the exact safe site-key projection.
	 *
	 * @param array<string,mixed> $view View.
	 * @param int                 $blog_id Blog identity.
	 */
	private function assert_view( array $view, int $blog_id ): void {
		self::assertSame( self::VIEW_FIELDS, array_keys( $view ) );
		self::assertSame( 'site', $view['principal_type'] );
		self::assertSame( $blog_id, $view['blog_id'] );
		self::assertSame( 'editorial-v1', $view['profile'] );
		self::assertSame( 'xns:' . $view['key_id'], $view['credential_ref'] );
		self::assertMatchesRegularExpression( '/\A[a-z2-7]{12}\z/', (string) $view['key_id'] );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', (string) $view['expires_at'] );
		self::assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', (string) $view['created_at'] );
		self::assertArrayNotHasKey( 'secret', $view );
		self::assertArrayNotHasKey( 'secret_hash', $view );
		self::assertArrayNotHasKey( 'pepper_id', $view );
		self::assertArrayNotHasKey( 'user_id', $view );
		self::assertArrayNotHasKey( 'scope_json', $view );
		self::assertArrayNotHasKey( 'revoke_reason', $view );
	}
}
