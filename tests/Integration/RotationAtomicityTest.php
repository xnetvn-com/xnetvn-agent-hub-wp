<?php
/**
 * Rotation failure proof using isolated real InnoDB tables and connections.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpOwnerLock;
use XNetVN\AgentHubWP\Persistence\Schema;
use XNetVN\AgentHubWP\Rest\KeysController;
use XNetVN\AgentHubWP\Rest\ManagementGate;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Owns only unique disposable table names inside the integration database. */
final class RotationAtomicityTest extends WP_UnitTestCase {
	/** Independent autocommit connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;
	/** Repository using that connection.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/** Lifecycle service.
	 *
	 * @var KeyService
	 */
	private KeyService $service;
	/** Active actor.
	 *
	 * @var int
	 */
	private int $owner;
	/** Frozen clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Mutable policy.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/** Avoid the core harness's enclosing transaction for commit/rollback evidence. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->db->set_prefix( $wpdb->prefix . 'rotation_' . bin2hex( random_bytes( 4 ) ) . '_' );
		$this->db->set_blog_id( 1 );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			foreach ( Schema::statements( $this->db ) as $sql ) {
				self::assertNotFalse( $this->db->query( $sql ) );
			}
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
		$this->owner    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->clock    = new FrozenClock( time() );
		$this->settings = new MemorySettings();
		$this->keys     = new WpdbKeyRepository( $this->db, $this->clock );
		$catalog        = $this->createMock( AbilityCatalog::class );
		$catalog->method( 'all' )->willReturn( array() );
		$this->service = new KeyService( $this->keys, new PepperStore(), new KeyGenerator(), $this->settings, $catalog, $this->createMock( RateLimiter::class ), new WpOwnerLock( $this->db ), $this->clock );
		wp_set_current_user( $this->owner );
	}

	/** Drop only this test's unique tables after finishing its connection. */
	public function tear_down(): void {
		$this->db->query( 'ROLLBACK' );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			foreach ( array( 'keys', 'audit' ) as $suffix ) {
				$this->db->query( $this->db->prepare( 'DROP TABLE IF EXISTS %i', $this->db->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) );
			}
		} finally {
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			$this->db->close();
			parent::tear_down();
		}
	}

	/** A failed grace write and failed compensating revoke cannot leave a second key. */
	public function test_failed_update_and_revoke_leave_no_undisclosed_key(): void {
		$old    = $this->service->create( $this->owner, array( 'name' => 'Rotation failure' ) );
		$events = array();
		$event  = static function () use ( &$events ): void {
			$events[] = 'created';
		};
		self::assertNotFalse( $this->db->query( $this->db->prepare( "CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rotation test write refusal'", $this->db->prefix . 'refuse_update', $this->db->prefix . 'xnetvn_agent_hub_wp_keys' ) ) );
		add_action( 'xnetvn_agent_hub_wp_key_created', $event );
		try {
			$result = $this->service->rotate( $old->key->id, array() );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_key_created', $event );
		}
		self::assertWPError( $result );
		self::assertSame( 503, $result->get_error_data()['status'] );
		self::assertSame( 1, $this->keys->count_active_for_user( $this->owner ) );
		self::assertCount( 1, $this->keys->list_for_user( $this->owner ) );
		self::assertSame( $old->key->scope->expires_at->getTimestamp(), $this->keys->find_by_id( $old->key->id )->scope->expires_at->getTimestamp() );
		self::assertSame( array(), $events );
	}

	/** Scope, grace, committed visibility and quota remain unchanged on success. */
	public function test_rotation_commits_before_creation_event(): void {
		$old   = $this->service->create(
			$this->owner,
			array(
				'name'            => 'Rotate',
				'expires_in_days' => 30,
			)
		);
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $this->db->prefix );
		$other->set_blog_id( 1 );
		$observed = array();
		$event    = function () use ( $other, &$observed ): void {
			$observed[] = ( new WpdbKeyRepository( $other, $this->clock ) )->count_active_for_user( $this->owner );
		};
		add_action( 'xnetvn_agent_hub_wp_key_created', $event );
		try {
			$new = $this->service->rotate( $old->key->id, array() );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_key_created', $event );
			$other->close();
		}
		self::assertFalse( is_wp_error( $new ) );
		self::assertNotSame( $old->key->key_id->value, $new->key->key_id->value );
		self::assertSame( $old->key->scope->toolsets, $new->key->scope->toolsets );
		self::assertSame( $this->clock->now() + DAY_IN_SECONDS, $this->keys->find_by_id( $old->key->id )->scope->expires_at->getTimestamp() );
		self::assertSame( array( 2 ), $observed );
		$this->settings->update( array( 'max_keys_per_user' => 2 ) );
		self::assertWPError( $this->service->create( $this->owner, array( 'name' => 'Over limit' ) ) );
	}

	/** Existing transaction work stays uncommitted and remains the caller's responsibility. */
	public function test_enclosing_transaction_is_refused_without_implicit_commit(): void {
		$old = $this->service->create( $this->owner, array( 'name' => 'Outer original' ) );
		$this->db->query( 'START TRANSACTION' );
		$this->db->query( $this->db->prepare( 'UPDATE %i SET name = %s WHERE id = %d', $this->db->prefix . 'xnetvn_agent_hub_wp_keys', 'Uncommitted name', $old->key->id ) );
		self::assertWPError( $this->service->rotate( $old->key->id, array() ) );
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $this->db->prefix );
		$other->set_blog_id( 1 );
		try {
			self::assertSame( 'Outer original', ( new WpdbKeyRepository( $other, $this->clock ) )->find_by_id( $old->key->id )->name );
		} finally {
			$other->close();
			$this->db->query( 'ROLLBACK' );
		}
		self::assertSame( 1, $this->keys->count_active_for_user( $this->owner ) );
	}

	/** Nontransactional storage is rejected without converting tables or inserting a key. */
	public function test_nontransactional_table_is_refused(): void {
		$old = $this->service->create( $this->owner, array( 'name' => 'Nontransactional' ) );
		self::assertNotFalse( $this->db->query( $this->db->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $this->db->prefix . 'xnetvn_agent_hub_wp_keys' ) ) );
		self::assertWPError( $this->service->rotate( $old->key->id, array() ) );
		self::assertSame( 1, $this->keys->count_active_for_user( $this->owner ) );
	}

	/**
	 * Prepared literal text must survive native execution without WordPress placeholder markers.
	 *
	 * @param string $name Literal display name.
	 * @dataProvider literal_names
	 */
	public function test_rotation_preserves_literal_names( string $name ): void {
		$old = $this->service->create( $this->owner, array( 'name' => $name ) );
		$new = $this->service->rotate( $old->key->id, array() );
		self::assertFalse( is_wp_error( $new ) );
		self::assertSame( $name, $this->keys->find_by_id( $new->key->id )->name );
		self::assertSame( $name, $new->key->name );
	}

	/**
	 * Literal punctuation, formatter-like strings and Unicode are valid display data.
	 *
	 * @return array<string,array{string}>
	 */
	public static function literal_names(): array {
		return array(
			'percent' => array( 'Deployment 100%' ),
			'format'  => array( 'Printf %s' ),
			'quote'   => array( "Quote '%s'" ),
			'unicode' => array( 'Café 100%' ),
			'ordered' => array( 'Budget %1$s' ),
		);
	}

	/** Actual cookie/nonce route dispatch returns a committed replacement exactly once. */
	public function test_management_route_rotation_with_independent_transaction(): void {
		$server = rest_get_server();
		if ( 0 === did_action( 'rest_api_init' ) ) {
			do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Initialize core REST registration before isolating its server.
		}
		$cookies                     = $_COOKIE;
		$token                       = \WP_Session_Tokens::get_instance( $this->owner )->create( time() + HOUR_IN_SECONDS );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->owner, time() + HOUR_IN_SECONDS, 'logged_in', $token );
		$nonce                       = wp_create_nonce( 'wp_rest' );
		try {
			$GLOBALS['wp_rest_server'] = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Isolated core REST fixture, restored in finally.
			( new KeysController( new ManagementGate(), $this->service, $this->keys ) )->register_routes();
			$old     = $this->service->create( $this->owner, array( 'name' => 'REST rotation' ) );
			$request = new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/v1/keys/' . $old->key->id . '/rotate' );
			$request->set_header( 'x-wp-nonce', $nonce );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( '{}' );
			$response = rest_do_request( $request );
			self::assertSame( 201, $response->get_status() );
			$data = $response->get_data();
			self::assertTrue( 1 === preg_match( '/\Axnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', $data['secret'] ) );
			self::assertSame( 2, $this->keys->count_active_for_user( $this->owner ) );
			self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
			$request = new \WP_REST_Request( 'GET', '/xnetvn-agent-hub-wp/v1/keys/' . $data['key']['id'] );
			$request->set_header( 'x-wp-nonce', $nonce );
			$metadata = rest_do_request( $request );
			self::assertSame( 200, $metadata->get_status() );
			self::assertArrayNotHasKey( 'secret', $metadata->get_data() );
			self::assertStringNotContainsString( $data['secret'], wp_json_encode( $metadata->get_data() ) );
		} finally {
			$_COOKIE                   = $cookies;
			$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore the core REST server fixture.
		}
	}
}
