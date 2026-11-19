<?php
/**
 * Site key lifecycle acceptance tests with real storage and WordPress auth.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
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
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteKeyService;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Domain\KeyId;
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
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves quota, reauthentication, rotation and revocation against the real database. */
final class SiteKeyServiceTest extends WP_UnitTestCase {
	/** Fixture password; never a real credential. */
	private const PASSWORD = 'Fixture-only-password-93';

	/** Independent disposable connections.
	 *
	 * @var array<\wpdb>
	 */
	private array $connections = array();
	/** Fixed clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Disposable database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;
	/** Repository bound to the disposable database.
	 *
	 * @var WpdbSiteKeyRepository
	 */
	private WpdbSiteKeyRepository $repo;
	/** Mutable site settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** First administrator.
	 *
	 * @var int
	 */
	private int $manager;
	/** Second administrator.
	 *
	 * @var int
	 */
	private int $second;
	/** Service under test.
	 *
	 * @var SiteKeyService
	 */
	private SiteKeyService $service;

	/** Install the real schema and fixed managers. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( 1791072000 );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$this->db       = $this->disposable();
		$this->repo     = new WpdbSiteKeyRepository( $this->db, $this->clock, 1 );
		$this->settings = new MemorySettings( array( 'allow_admin_tier' => false ) );
		$request        = new RequestScope();
		$limits         = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock );
		$registry       = new Registry( new Policy( $this->settings ), $request, new OutputPipeline( $this->settings ), new WpExpensiveBudget( $wpdb ) );
		$registry->add_pack( new ContentPack( new DefinitionFactory() ) );
		$registry->add_pack( new MediaPack( new DefinitionFactory(), $this->settings ) );
		$registry->add_pack( new CommentsPack( new DefinitionFactory() ) );
		$registry->add_pack( new TaxonomyPack( new DefinitionFactory() ) );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), $limits ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
		$registry->load_packs();
		$this->service = new SiteKeyService( $this->repo, new SiteKeyLock( $this->db ), new SiteTokenCodec(), new PepperStore(), $this->settings, $registry, $limits, $this->clock );
		$this->manager = (int) self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => self::PASSWORD,
			)
		);
		$this->second  = (int) self::factory()->user->create(
			array(
				'role'      => 'administrator',
				'user_pass' => self::PASSWORD,
			)
		);
		wp_set_current_user( $this->manager );
	}

	/** Remove only the disposable fixtures. */
	public function tear_down(): void {
		global $wpdb;
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		foreach ( $this->connections as $db ) {
			$db->query( 'ROLLBACK' );
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
		parent::tear_down();
	}

	/** The default creation is one bounded 51-operation editorial credential. */
	public function test_create_defaults_to_the_bounded_editorial_profile(): void {
		$issued = $this->service->create( $this->input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( $this->clock->now() + 90 * DAY_IN_SECONDS, $issued->key->scope->expires_at->getTimestamp() );
		self::assertSame( RiskTier::Destructive, $issued->key->scope->tier_ceiling );
		self::assertCount( 51, $issued->key->scope->allowed_abilities );
		self::assertContains( 'xnetvn/create-post', $issued->key->scope->allowed_abilities );
		self::assertNotContains( 'xnetvn/set-user-status', $issued->key->scope->allowed_abilities );
		self::assertSame( $this->manager, $issued->key->created_by );
		self::assertSame( 1, $this->rows() );
		$token = $issued->reveal();
		self::assertStringStartsWith( 'xns_', $token );
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i', $this->table() ), ARRAY_A );
		self::assertIsArray( $row );
		self::assertStringNotContainsString( $token, Json::encode( $row ) );
		$this->expectException( \LogicException::class );
		$issued->reveal();
	}

	/** Wrong passwords and non-managers write nothing. */
	public function test_wrong_password_and_non_manager_write_nothing(): void {
		$this->assertError( 'reauthentication_required', 403, $this->service->create( $this->input( array( 'current_password' => 'wrong-password' ) ) ) );
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertError( 'forbidden', 403, $this->service->create( $this->input() ) );
		self::assertSame( 0, $this->rows() );
	}

	/** The hard quota refuses both create and rotate after ten live rows. */
	public function test_create_and_rotate_refuse_at_the_real_quota(): void {
		$old = $this->seed( 1 );
		for ( $i = 2; $i <= 10; ++$i ) {
			$this->seed( $i );
		}
		self::assertSame( 10, $this->repo->count_active() );
		$this->assertError( 'key_limit', 409, $this->service->create( $this->input() ) );
		$this->assertError( 'key_limit', 409, $this->service->create( $this->input( array( 'current_password' => 'wrong-password' ) ) ) );
		$this->assertError(
			'key_limit',
			409,
			$this->service->rotate(
				$old->id,
				array(
					'current_password' => self::PASSWORD,
					'grace_hours'      => 0,
				)
			)
		);
		self::assertSame( 10, $this->rows() );
	}

	/** A restricted toolset narrows the real catalog to that pack only. */
	public function test_create_restricts_the_real_catalog_to_requested_toolsets(): void {
		$issued = $this->service->create( $this->input( array( 'toolsets' => array( 'content' ) ) ) );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertContains( 'xnetvn/create-post', $issued->key->scope->allowed_abilities );
		self::assertNotContains( 'xnetvn/delete-media', $issued->key->scope->allowed_abilities );
		self::assertNotContains( 'xnetvn/set-user-status', $issued->key->scope->allowed_abilities );
		self::assertSame( array( 'content', 'system' ), $issued->key->scope->toolsets );
		$this->assertError( 'invalid_site_key', 400, $this->service->create( $this->input( array( 'toolsets' => array( 'not-a-toolset' ) ) ) ) );
	}

	/** Patch rejects expansion, preserves the row, and persists narrowing. */
	public function test_patch_rejects_expansion_and_persists_narrowing(): void {
		$issued = $this->service->create( $this->input( array( 'name' => 'Patch fixture' ) ) );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$before = $issued->key->scope->to_array();
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$this->assertError( 'scope_expansion', 409, $this->service->patch( $issued->key->id, array( 'permissions' => array( 'ban-users' ) ) ) );
		self::assertError( 'invalid_site_key', 400, $this->service->patch( $issued->key->id, array( 'current_password' => self::PASSWORD ) ) );
		self::assertSame( $before, $this->repo->find_by_id( $issued->key->id )->scope->to_array() );
		$patched = $this->service->patch(
			$issued->key->id,
			array(
				'name'            => 'Narrowed fixture',
				'expires_in_days' => 30,
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $patched );
		self::assertSame( 'Narrowed fixture', $patched->name );
		self::assertSame( $this->clock->now() + 30 * DAY_IN_SECONDS, $patched->scope->expires_at->getTimestamp() );
	}

	/** Rotation keeps every restriction and moves only the old row to grace. */
	public function test_rotation_inherits_restrictions_and_narrows_old_grace(): void {
		$issued = $this->service->create( $this->input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$before = $issued->key->scope->to_array();
		$result = $this->service->rotate(
			$issued->key->id,
			array(
				'current_password' => self::PASSWORD,
				'grace_hours'      => 4,
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( $before, $result->key->scope->to_array() );
		self::assertSame( $this->clock->now() + 4 * HOUR_IN_SECONDS, $this->repo->find_by_id( $issued->key->id )->scope->expires_at->getTimestamp() );
		self::assertSame( 2, $this->rows() );
		self::assertStringStartsWith( 'xns_', $result->reveal() );
	}

	/** Moderation needs the admin site gates and adds exactly one operation. */
	public function test_moderation_requires_the_admin_site_gates(): void {
		$input = $this->input( array( 'permissions' => array( 'ban-users' ) ) );
		$this->assertError( 'forbidden', 403, $this->service->create( $input ) );
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$issued = $this->service->create( $input );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( RiskTier::Admin, $issued->key->scope->tier_ceiling );
		self::assertContains( 'users', $issued->key->scope->toolsets );
		self::assertContains( 'xnetvn/set-user-status', $issued->key->scope->allowed_abilities );
		self::assertCount( 52, $issued->key->scope->allowed_abilities );
	}

	/** A second manager can revoke after the issuer is demoted. */
	public function test_demoted_issuer_does_not_block_second_manager_revoke(): void {
		$issued = $this->service->create( $this->input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$issuer = new \WP_User( $this->manager );
		$issuer->set_role( 'subscriber' );
		wp_set_current_user( $this->second );
		self::assertTrue( $this->service->revoke( $issued->key->id ) );
		$row = $this->repo->find_by_id( $issued->key->id );
		self::assertTrue( $row->is_revoked() );
		self::assertSame( $this->second, $row->revoked_by );
		self::assertFalse( $this->service->revoke( $issued->key->id ) );
	}

	/** A committed unknown rotation blocks writes until the operator resolves it. */
	public function test_unresolved_rotation_blocks_until_the_operator_resolves(): void {
		$old = $this->seed( 20 );
		$this->seed_committed_unknown( $old, $this->clock->now() + 4 * HOUR_IN_SECONDS );
		self::assertSame( 'committed', $this->repo->reconcile_rotation()['outcome'] );
		$this->assertError( 'rotation_unresolved', 409, $this->service->rotate( $old->id, array( 'current_password' => self::PASSWORD ) ) );
		self::assertSame( 2, $this->rows() );
		self::assertTrue( $this->service->resolve_rotation_intent() );
		self::assertNull( get_option( WpdbSiteKeyRepository::ROTATION_OPTION, null ) );
		$rotated = $this->service->rotate( $old->id, array( 'current_password' => self::PASSWORD ) );
		self::assertNotInstanceOf( \WP_Error::class, $rotated );
	}

	/** A corrupt record fails closed as unknown and is never treated as success. */
	public function test_corrupt_rotation_record_fails_closed(): void {
		$old = $this->seed( 21 );
		update_option( WpdbSiteKeyRepository::ROTATION_OPTION, '{', false );
		$this->assertError( 'rotation_unresolved', 409, $this->service->rotate( $old->id, array( 'current_password' => self::PASSWORD ) ) );
		self::assertSame( 1, $this->rows() );
	}

	/** A second connection holding the blog lock makes the mutation unavailable. */
	public function test_lock_contention_fails_closed(): void {
		$other = $this->connection( $this->db->prefix );
		self::assertSame( '1', (string) $other->get_var( "SELECT GET_LOCK('xnetvn_ahwp_sk_1',0)" ) );
		try {
			$this->assertError( 'unavailable', 503, $this->service->create( $this->input() ) );
			self::assertSame( 0, $this->rows() );
		} finally {
			$other->get_var( "SELECT RELEASE_LOCK('xnetvn_ahwp_sk_1')" );
		}
	}

	/** Reads validate filters and never expose another record. */
	public function test_get_and_list_validate_and_stay_bounded(): void {
		self::assertError( 'not_found', 404, $this->service->get( 999 ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->list( array( 'per_page' => 0 ) ) );
		$issued = $this->service->create( $this->input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertCount( 1, $this->service->list( array( 'state' => 'active' ) ) );
		self::assertSame( $issued->key->id, $this->service->get( $issued->key->id )->id );
	}

	/**
	 * Build one valid creation payload.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'             => 'Fixture site key',
				'current_password' => self::PASSWORD,
			),
			$overrides
		);
	}

	/**
	 * Insert one live fixture credential.
	 *
	 * @param int $seed Identifier seed.
	 * @return SiteCredential
	 */
	private function seed( int $seed ): SiteCredential {
		return $this->repo->insert(
			$this->key_id( $seed ),
			new SiteScope(
				RiskTier::Write,
				array( 'content' ),
				array( 'xnetvn/create-post' ),
				array(),
				array(),
				array(),
				new DateTimeImmutable( '@' . ( $this->clock->now() + 90 * DAY_IN_SECONDS ) )
			),
			'Seed ' . $seed,
			str_repeat( 'a', 64 ),
			1,
			$this->manager
		);
	}

	/**
	 * Seed one committed rotation whose acknowledgement was lost.
	 *
	 * @param SiteCredential $old Old credential.
	 * @param int            $grace Grace timestamp.
	 * @return void
	 */
	private function seed_committed_unknown( SiteCredential $old, int $grace ): void {
		$new_id = $this->key_id( 90 );
		$this->repo->insert( $new_id, $old->scope, $old->name, str_repeat( 'b', 64 ), 1, $this->manager );
		$data               = $old->scope->to_array();
		$data['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $grace );
		$this->db->query( $this->db->prepare( 'UPDATE %i SET expires_at = %s, scope_json = %s WHERE id = %d AND blog_id = %d', $this->table(), gmdate( 'Y-m-d H:i:s', $grace ), Json::encode( $data ), $old->id, 1 ) );
		update_option(
			WpdbSiteKeyRepository::ROTATION_OPTION,
			Json::encode(
				array(
					'v'                => 1,
					'blog_id'          => 1,
					'old_id'           => $old->id,
					'old_expires_at'   => $old->scope->expires_at->format( 'Y-m-d H:i:s' ),
					'grace_expires_at' => gmdate( 'Y-m-d H:i:s', $grace ),
					'new_key_id'       => $new_id->value,
					'actor'            => $this->manager,
					'attempted_at'     => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
					'phase'            => 'commit_unknown',
				)
			),
			false
		);
	}

	/**
	 * Build one deterministic public identifier.
	 *
	 * @param int $seed Seed.
	 * @return KeyId
	 */
	private function key_id( int $seed ): KeyId {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$value    = '';
		$number   = $seed;
		for ( $i = 0; $i < 12; ++$i ) {
			$value  = $alphabet[ $number % 32 ] . $value;
			$number = intdiv( $number, 32 );
		}
		return new KeyId( $value );
	}

	/**
	 * Create an isolated schema on its own autocommit connection.
	 *
	 * @return \wpdb
	 */
	private function disposable(): \wpdb {
		global $wpdb;
		$db = $this->connection( $wpdb->prefix . 'sksvc_' . bin2hex( random_bytes( 4 ) ) . '_' );
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
	 * Open one tracked connection.
	 *
	 * @param string $prefix Table prefix.
	 * @return \wpdb
	 */
	private function connection( string $prefix ): \wpdb {
		$db = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$db->set_blog_id( 1 );
		$db->set_prefix( $prefix );
		$this->connections[] = $db;
		return $db;
	}

	/**
	 * Resolve the site-key table.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'xnetvn_agent_hub_wp_site_keys';
	}

	/**
	 * Count site-key rows on the disposable connection.
	 *
	 * @return int
	 */
	private function rows(): int {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );
	}

	/**
	 * Assert one fixed safe error.
	 *
	 * @param string $reason Expected reason.
	 * @param int    $status Expected status.
	 * @param mixed  $value Actual value.
	 * @return void
	 */
	private function assertError( string $reason, int $status, mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $reason, $value->get_error_code() );
		$data = $value->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( $status, $data['status'] );
	}
}
