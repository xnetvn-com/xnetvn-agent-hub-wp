<?php
/**
 * Protected personal-key lifecycle and bearer acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\FailureReason;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\IssuedKey;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpOwnerLock;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Exercises real storage, live roles and the bearer boundary. */
final class ProtectedPersonalKeysTest extends WP_UnitTestCase {

	/** Original server values.
	 *
	 * @var array<mixed>
	 */
	private array $server;
	/** Credential repository.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/** Mutable settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Fixed clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Lifecycle service.
	 *
	 * @var KeyService
	 */
	private KeyService $service;
	/** Current request scope.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;

	/** Install plugin-owned rows and services. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->server                  = $_SERVER;
		$_SERVER['HTTPS']              = 'on';
		$_SERVER['REMOTE_ADDR']        = '203.0.113.7';
		$_SERVER['HTTP_AUTHORIZATION'] = '';
		$this->clock                   = new FrozenClock( time() );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$this->settings = new MemorySettings();
		$this->keys     = new WpdbKeyRepository( $wpdb, $this->clock );
		$this->request  = new RequestScope();
		$registry       = new Registry( new Policy( $this->settings ), $this->request, new OutputPipeline( $this->settings ) );
		$this->service  = new KeyService( $this->keys, new PepperStore(), new KeyGenerator(), $this->settings, $registry, new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock ), new WpOwnerLock( $wpdb ), $this->clock );
	}

	/** Restore global request data. */
	public function tear_down(): void {
		$_SERVER = $this->server;
		$this->request->reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** A protected administrator cannot mint a personal key. */
	public function test_administrator_cannot_mint_personal_key(): void {
		$admin = $this->user( 'administrator' );
		wp_set_current_user( $admin );
		self::assertTrue( user_can( $admin, Capabilities::USE ) );

		$result = $this->service->create(
			$admin,
			array(
				'name'     => 'Admin key',
				'tier_max' => 'read',
			)
		);

		self::assertWPError( $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertSame( 0, $this->keys->count_active_for_user( $admin ) );
	}

	/** Editors and authors keep the normal personal-key workflow. */
	public function test_editor_and_author_can_mint_and_authenticate(): void {
		foreach ( array( 'editor', 'author' ) as $role ) {
			$owner  = $this->user( $role );
			$issued = $this->mint( $owner, ucfirst( $role ) . ' key' );
			$result = $this->authenticate( $issued->reveal() );

			self::assertSame( $owner, $result['code'] );
			self::assertNull( $result['failure'] );
			self::assertInstanceOf( AuthContext::class, $result['context'] );
			self::assertSame( $owner, $result['context']->user_id );
		}
	}

	/** Mixed-role and direct-capability variants cannot mint even with the use capability. */
	public function test_mixed_and_direct_capability_variants_cannot_mint(): void {
		$mixed = $this->user( 'editor' );
		$user  = get_userdata( $mixed );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_role( 'administrator' );
		$this->assertMintRefused( $mixed );

		$direct = $this->user( 'editor' );
		$user   = get_userdata( $direct );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'manage_options' );
		$this->assertMintRefused( $direct );
	}

	/** A pre-existing administrator bearer, rotation and extension are refused. */
	public function test_administrator_bearer_rotation_and_extension_are_refused(): void {
		$admin   = $this->user( 'administrator' );
		$fixture = $this->insert_key( $admin );
		self::assertTrue( user_can( $admin, Capabilities::USE ) );

		$result = $this->authenticate( $fixture['token'] );
		self::assertSame( 0, $result['code'] );
		self::assertSame( FailureReason::OwnerForbidden, $result['failure'] );
		self::assertNull( $result['context'] );

		$stored = $this->keys->find_by_id( $fixture['id'] );
		self::assertInstanceOf( ApiKey::class, $stored );
		self::assertFalse( $stored->is_revoked() );
		self::assertNull( $stored->last_used_at );
		self::assertSame( 1, $this->keys->count_active_for_user( $admin ) );

		wp_set_current_user( $admin );
		$patch = $this->service->patch( $fixture['id'], array( 'tier_max' => 'write' ) );
		self::assertWPError( $patch );
		self::assertSame( 403, $patch->get_error_data()['status'] );
		$stored = $this->keys->find_by_id( $fixture['id'] );
		self::assertInstanceOf( ApiKey::class, $stored );
		self::assertSame( 'read', $stored->scope->tier_max->value );

		$rotate = $this->service->rotate( $fixture['id'], array() );
		self::assertWPError( $rotate );
		self::assertSame( 403, $rotate->get_error_data()['status'] );
		$stored = $this->keys->find_by_id( $fixture['id'] );
		self::assertInstanceOf( ApiKey::class, $stored );
		self::assertFalse( $stored->is_revoked() );
		self::assertSame( 1, $this->keys->count_active_for_user( $admin ) );
	}

	/** A manager keeps list/get/revoke continuity for an editor key. */
	public function test_manager_can_get_and_revoke_an_editor_key(): void {
		$editor = $this->user( 'editor' );
		$issued = $this->mint( $editor, 'Editor key' );
		$admin  = $this->user( 'administrator' );
		wp_set_current_user( $admin );
		self::assertTrue( current_user_can( Capabilities::MANAGE ) );

		$key = $this->service->get( $issued->key->id );
		self::assertInstanceOf( ApiKey::class, $key );
		self::assertSame( $editor, $key->user_id );
		self::assertTrue( $this->service->revoke( $issued->key->id ) );
		self::assertTrue( $this->keys->find_by_id( $issued->key->id )->is_revoked() );
	}

	/** A Multisite Super Admin is refused at minting and bearer authentication. */
	public function test_multisite_super_admin_is_refused(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Super Admin coverage requires the Multisite network lab.' );
		}

		$super = $this->user( 'editor' );
		grant_super_admin( $super );
		try {
			$this->assertMintRefused( $super );
			$fixture = $this->insert_key( $super );
			$result  = $this->authenticate( $fixture['token'] );
			self::assertSame( 0, $result['code'] );
			self::assertSame( FailureReason::OwnerForbidden, $result['failure'] );
			self::assertNull( $result['context'] );
			$stored = $this->keys->find_by_id( $fixture['id'] );
			self::assertInstanceOf( ApiKey::class, $stored );
			self::assertNull( $stored->last_used_at );
			self::assertFalse( $stored->is_revoked() );
		} finally {
			revoke_super_admin( $super );
		}
	}

	/**
	 * Create a disposable user.
	 *
	 * @param string $role Role slug.
	 * @return int
	 */
	private function user( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	/**
	 * Mint a personal key for a positive owner.
	 *
	 * @param int    $user_id Owner.
	 * @param string $name Name.
	 * @return IssuedKey
	 */
	private function mint( int $user_id, string $name ): IssuedKey {
		wp_set_current_user( $user_id );
		$issued = $this->service->create(
			$user_id,
			array(
				'name'     => $name,
				'tier_max' => 'read',
			)
		);
		self::assertInstanceOf( IssuedKey::class, $issued );
		return $issued;
	}

	/**
	 * Insert a stored key without the issuance gate.
	 *
	 * @param int $user_id Protected owner.
	 * @return array{id:int,token:string}
	 */
	private function insert_key( int $user_id ): array {
		$generated = ( new KeyGenerator() )->generate();
		$pepper    = ( new PepperStore() )->current();
		$key       = $this->keys->insert( $generated->id, $user_id, 'Protected fixture', new Scope( RiskTier::Read ), $generated->hash_with( $pepper ), $pepper->id, '203.0.113.7' );
		return array(
			'id'    => $key->id,
			'token' => $generated->reveal(),
		);
	}

	/**
	 * Authenticate a token through the real bearer boundary.
	 *
	 * @param string $token Full one-time credential.
	 * @return array{code:int,failure:FailureReason|null,context:AuthContext|null}
	 */
	private function authenticate( string $token ): array {
		global $wpdb;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->request                 = new RequestScope();
		$auth                          = new Authenticator(
			$this->keys,
			new PepperStore(),
			$this->settings,
			new HeaderExtractor( $this->settings ),
			new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock ),
			new TransportGuard( $this->settings ),
			$this->request,
			$this->clock
		);
		return array(
			'code'    => $auth->authenticate(),
			'failure' => $this->request->failure,
			'context' => $this->request->context,
		);
	}

	/**
	 * Assert that a protected owner cannot mint and creates no row.
	 *
	 * @param int $user_id Protected owner.
	 * @return void
	 */
	private function assertMintRefused( int $user_id ): void {
		wp_set_current_user( $user_id );
		self::assertTrue( user_can( $user_id, Capabilities::USE ) );
		$result = $this->service->create(
			$user_id,
			array(
				'name'     => 'Protected key',
				'tier_max' => 'read',
			)
		);
		self::assertWPError( $result );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertSame( 0, $this->keys->count_active_for_user( $user_id ) );
	}
}
