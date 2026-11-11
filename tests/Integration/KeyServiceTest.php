<?php
/**
 * Key management service acceptance tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\TokenParser;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpOwnerLock;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Exercises real storage, owner capabilities and fresh password checks. */
final class KeyServiceTest extends WP_UnitTestCase {
	/** Active fixture owner.
	 *
	 * @var int
	 */
	private int $owner;
	/** Service under test.
	 *
	 * @var KeyService
	 */
	private KeyService $service;
	/** Safe metadata repository.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/** Mutable settings fixture.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** Fixed wall clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;

	/** Install only plugin-owned fixture rows. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( time() );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$this->owner    = self::factory()->user->create(
			array(
				'role'      => 'editor',
				'user_pass' => 'Fixture-only-password-93',
			)
		);
		$this->settings = new MemorySettings();
		$this->keys     = new WpdbKeyRepository( $wpdb, $this->clock );
		$request        = new RequestScope();
		$registry       = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
		$registry->load_packs();
		$this->service = new KeyService( $this->keys, new PepperStore(), new KeyGenerator(), $this->settings, $registry, new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock ), new WpOwnerLock( $wpdb ), $this->clock );
		wp_set_current_user( $this->owner );
	}

	/** Generated credentials verify, reveal once and never appear in metadata. */
	public function test_create_and_one_time_secret(): void {
		$issued = $this->service->create(
			$this->owner,
			array(
				'name'            => 'Fixture',
				'tier_max'        => 'read',
				'expires_in_days' => 30,
			)
		);
		self::assertFalse( is_wp_error( $issued ) );
		$token  = $issued->reveal();
		$parsed = TokenParser::parse( $token );
		self::assertNotNull( $parsed );
		$stored = $this->keys->find_by_key_id( $parsed->id );
		self::assertTrue( $stored->verify_secret( $parsed->secret->reveal(), ( new PepperStore() )->current()->reveal() ) );
		self::assertStringNotContainsString( $token, wp_json_encode( $issued->key ) );
		self::assertStringNotContainsString( $token, var_export( $issued, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Verify one-time credential export safety.
		$this->expectException( \LogicException::class );
		$issued->reveal();
	}

	/** A low privilege user cannot delegate a capability they do not hold. */
	public function test_scope_is_intersected_with_owner_and_site(): void {
		$user = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $user );
		self::assertWPError(
			$this->service->create(
				$user,
				array(
					'name'             => 'No',
					'tier_max'         => 'destructive',
					'expires_in_days'  => 1,
					'current_password' => 'wrong',
				)
			)
		);
		wp_set_current_user( $this->owner );
		$this->settings->update( array( 'tier_ceiling' => 'read' ) );
		self::assertWPError(
			$this->service->create(
				$this->owner,
				array(
					'name'     => 'No',
					'tier_max' => 'write',
				)
			)
		);
		self::assertWPError( $this->service->create( $user, array( 'name' => 'Not owner' ) ) );
	}

	/** Destructive credentials require a valid password and a bounded expiry. */
	public function test_high_risk_creation_requires_reauthentication(): void {
		$args = array(
			'name'            => 'High risk',
			'tier_max'        => 'destructive',
			'expires_in_days' => 10,
		);
		self::assertWPError( $this->service->create( $this->owner, $args ) );
		self::assertWPError( $this->service->create( $this->owner, array_merge( $args, array( 'current_password' => 'wrong' ) ) ) );
		$created = $this->service->create( $this->owner, array_merge( $args, array( 'current_password' => 'Fixture-only-password-93' ) ) );
		self::assertFalse( is_wp_error( $created ) );
		self::assertNotNull( $created->key->scope->expires_at );
		self::assertWPError(
			$this->service->create(
				$this->owner,
				array(
					'name'             => 'No expiry',
					'tier_max'         => 'destructive',
					'expires_in_days'  => null,
					'current_password' => 'Fixture-only-password-93',
				)
			)
		);
	}

	/** Malformed fields and unavailable scope members are rejected individually. */
	public function test_invalid_fields_are_rejected_without_inserts(): void {
		foreach ( array(
			array( 'name' => '' ),
			array( 'name' => str_repeat( 'x', 101 ) ),
			array( 'user_id' => 1 ),
			array( 'tier_max' => 'admin' ),
			array( 'toolsets' => array( 'not-installed' ) ),
			array( 'toolsets' => 'system' ),
			array( 'ip_allowlist' => array( '127.0.0.999/24' ) ),
			array( 'ability_deny' => array( 'core/get-site-info' ) ),
			array( 'expires_in_days' => 366 ),
			array( 'expires_in_days' => 0 ),
			array( 'expires_in_days' => '30' ),
			array( 'toolsets' => null ),
			array( 'tier_max' => null ),
		) as $changes ) {
			self::assertWPError( $this->service->create( $this->owner, array_merge( array( 'name' => 'Fixture' ), $changes ) ) );
		}
		self::assertSame( 0, $this->keys->count_active_for_user( $this->owner ) );
	}

	/** A second database connection cannot concurrently enter the same owner's mutation. */
	public function test_owner_lock_excludes_other_connections_and_releases_after_failure(): void {
		global $wpdb;
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $wpdb->base_prefix );
		$other->set_blog_id( get_current_blog_id() );
		$one = new WpOwnerLock( $wpdb );
		$two = new WpOwnerLock( $other );
		try {
			$one->run(
				$this->owner,
				function () use ( $two ): void {
					try {
						$two->run( $this->owner, static fn(): bool => true );
						self::fail( 'A second connection entered the same owner mutation.' );
					} catch ( \XNetVN\AgentHubWP\Persistence\StorageException ) {
						self::assertTrue( true );
					}
					throw new \RuntimeException( 'Fixture failure' );
				}
			);
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Fixture failure', $error->getMessage() );
		}
		self::assertTrue( $two->run( $this->owner, static fn(): bool => true ) );
		$other->close();
	}

	/** Password guessing cannot exceed the fixed per-owner budget. */
	public function test_high_risk_password_attempts_are_rate_limited(): void {
		$args = array(
			'name'             => 'High risk',
			'tier_max'         => 'destructive',
			'expires_in_days'  => 10,
			'current_password' => 'wrong',
		);
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			self::assertSame( 403, $this->service->create( $this->owner, $args )->get_error_data()['status'] );
		}
		self::assertSame( 429, $this->service->create( $this->owner, $args )->get_error_data()['status'] );
	}

	/** Editing can rename and narrow, never add permissions or extend lifetime. */
	public function test_patch_only_narrows_and_revocation_is_owner_scoped(): void {
		$issued = $this->service->create(
			$this->owner,
			array(
				'name'            => 'Original',
				'tier_max'        => 'write',
				'expires_in_days' => 30,
			)
		);
		$key    = $this->service->patch(
			$issued->key->id,
			array(
				'name'            => 'Renamed',
				'tier_max'        => 'read',
				'expires_in_days' => 10,
			)
		);
		self::assertFalse( is_wp_error( $key ) );
		self::assertSame( 'Renamed', $key->name );
		self::assertWPError( $this->service->patch( $key->id, array( 'tier_max' => 'write' ) ) );
		self::assertWPError( $this->service->patch( $key->id, array( 'expires_in_days' => 20 ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertWPError( $this->service->revoke( $key->id ) );
		wp_set_current_user( $this->owner );
		self::assertTrue( $this->service->revoke( $key->id ) );
		self::assertTrue( $this->keys->find_by_id( $key->id )->is_revoked() );
	}

	/** The core harness's outer transaction is never implicitly committed by rotation. */
	public function test_rotation_refuses_an_enclosing_transaction(): void {
		$issued = $this->service->create(
			$this->owner,
			array(
				'name'            => 'Rotate',
				'expires_in_days' => 30,
			)
		);
		$new    = $this->service->rotate( $issued->key->id, array() );
		self::assertWPError( $new );
		self::assertSame( 1, $this->keys->count_active_for_user( $this->owner ) );
		self::assertSame( $issued->key->scope->expires_at->getTimestamp(), $this->keys->find_by_id( $issued->key->id )->scope->expires_at->getTimestamp() );
	}
}
