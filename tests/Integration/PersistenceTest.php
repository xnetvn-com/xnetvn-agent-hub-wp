<?php
/**
 * Real database migration and credential repository tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\Schema;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Validates schema and lifecycle against the installed WordPress database. */
final class PersistenceTest extends WP_UnitTestCase {
	/** Test clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Repository under test.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;

	/** Prepare the schema and empty plugin-owned test tables. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( 1791072000 );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		$this->keys = new WpdbKeyRepository( $wpdb, $this->clock );
	}

	/** Migration is idempotent and creates the specified indexes/nullable fields. */
	public function test_schema_is_idempotent(): void {
		global $wpdb;
		$migrator = new Migrator( $wpdb, $this->clock );
		self::assertTrue( $migrator->migrate() );
		self::assertTrue( $migrator->migrate() );
		self::assertSame( (string) Schema::VERSION, (string) get_option( 'xnetvn_agent_hub_wp_db_version' ) );
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ), ARRAY_N );
		self::assertStringContainsString( 'secret_hash', $row[1] );
		self::assertStringContainsString( 'UNIQUE KEY', $row[1] );
		self::assertStringContainsString( 'last_used_ip', $row[1] );
		self::assertStringContainsString( 'varchar(45)', $row[1] );
	}

	/** Create, verify, list and revoke preserve scope and never expose a hash. */
	public function test_key_lifecycle(): void {
		$user   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$scope  = new Scope( RiskTier::Write, array( 'content' ), array(), array(), array( '203.0.113.0/24' ), new DateTimeImmutable( '2027-01-01T00:00:00Z' ) );
		$secret = str_repeat( 'A', 43 );
		$pepper = str_repeat( 'B', 32 );
		$hash   = KeyHasher::hash( $secret, $pepper );
		$key    = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Fixture', $scope, $hash, 1, '203.0.113.7' );
		$stored = $this->keys->find_by_key_id( $key->key_id );
		self::assertNotNull( $stored );
		self::assertTrue( $stored->verify_secret( $secret, $pepper ) );
		self::assertFalse( $stored->verify_secret( 'Wrong', $pepper ) );
		self::assertSame( $user, $stored->key->user_id );
		self::assertSame( $scope->toolsets, $stored->key->scope->toolsets );
		self::assertCount( 1, $this->keys->list_for_user( $user ) );
		self::assertCount( 0, $this->keys->list_for_user( $user + 1 ) );
		self::assertSame( 1, $this->keys->count_active_for_user( $user ) );
		self::assertSame( $key->name, $this->keys->find_by_id( $key->id )->name );
		self::assertStringNotContainsString( $hash, var_export( $stored, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Verify secret-hash export safety.
		self::assertTrue( $this->keys->revoke( $key->id, $user, 'owner_request' ) );
		self::assertTrue( $this->keys->find_by_id( $key->id )->is_revoked() );
		self::assertSame( 0, $this->keys->count_active_for_user( $user ) );
	}

	/** Narrow scope updates succeed and widening is rejected. */
	public function test_scope_update_never_widens(): void {
		$user = self::factory()->user->create();
		$key  = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Fixture', new Scope( RiskTier::Write, array( 'content' ) ), str_repeat( 'a', 64 ), 1 );
		self::assertTrue( $this->keys->update_scope( $key->id, new Scope( RiskTier::Read, array( 'content' ) ) ) );
		self::assertFalse( $this->keys->update_scope( $key->id, new Scope( RiskTier::Write, array( 'content' ) ) ) );
		self::assertSame( RiskTier::Read, $this->keys->find_by_id( $key->id )->scope->tier_max );
	}

	/** Last-used writes are throttled to once per five minutes. */
	public function test_touch_is_throttled(): void {
		$user = self::factory()->user->create();
		$key  = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		self::assertTrue( $this->keys->touch_last_used( $key->id, '203.0.113.1', 'test' ) );
		$this->clock->timestamp += 299;
		self::assertFalse( $this->keys->touch_last_used( $key->id, '203.0.113.2', 'test' ) );
		$this->clock->timestamp += 1;
		self::assertTrue( $this->keys->touch_last_used( $key->id, '203.0.113.2', 'test' ) );
	}

	/** Duplicate lookup identifiers do not replace an existing credential. */
	public function test_identifier_is_unique(): void {
		$user = self::factory()->user->create();
		$this->keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'First', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$this->expectException( \XNetVN\AgentHubWP\Persistence\KeyCollision::class );
		$this->keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Second', new Scope( RiskTier::Read ), str_repeat( 'b', 64 ), 1 );
	}

	/** Safe audit batches and bounded retention preserve recent records. */
	public function test_audit_batch_and_retention(): void {
		global $wpdb;
		$audit = new WpdbAuditRepository( $wpdb );
		$rows  = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$rows[] = array(
				'occurred_at' => '2026-01-01 00:00:00',
				'request_id'  => '0123456789abcdef',
				'transport'   => 'http',
				'outcome'     => 'success',
				'arg_keys'    => array( 'id' ),
			);
		}
		$rows[] = array(
			'occurred_at' => '2026-10-04 00:00:00',
			'request_id'  => 'fedcba9876543210',
			'transport'   => 'stdio',
			'outcome'     => 'denied',
			'arg_keys'    => array(),
		);
		self::assertSame( 4, $audit->insert_batch( $rows ) );
		self::assertCount( 3, $audit->query( array( 'outcome' => 'success' ), 1 )['items'] );
		self::assertSame( 2, $audit->purge_older_than( '2026-07-01 00:00:00', 2 ) );
		self::assertSame( 1, $audit->purge_older_than( '2026-07-01 00:00:00', 2 ) );
		self::assertSame( 1, $audit->query( array(), 1 )['total'] );
	}

	/** A held lease prevents concurrent DDL and an expired lease can be claimed. */
	public function test_migration_lease(): void {
		global $wpdb;
		delete_option( Migrator::VERSION_OPTION );
		add_option( Migrator::LOCK_OPTION, ( $this->clock->now() + 120 ) . ':other-worker', '', false );
		self::assertFalse( ( new Migrator( $wpdb, $this->clock ) )->migrate() );
		self::assertFalse( get_option( Migrator::VERSION_OPTION ) );
		$this->clock->timestamp += 121;
		self::assertTrue( ( new Migrator( $wpdb, $this->clock ) )->migrate() );
		self::assertFalse( get_option( Migrator::LOCK_OPTION ) );
	}

	/** A future database version is never downgraded. */
	public function test_future_version_is_rejected(): void {
		global $wpdb;
		update_option( Migrator::VERSION_OPTION, Schema::VERSION + 1 );
		$this->expectException( \XNetVN\AgentHubWP\Persistence\StorageException::class );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
	}

	/** Placeholder-like data stays data in optional audit filters. */
	public function test_audit_filter_does_not_reinterpret_placeholders(): void {
		global $wpdb;
		$audit = new WpdbAuditRepository( $wpdb );
		self::assertSame( 0, $audit->query( array( 'ability' => "xnetvn/%s' OR 1=1 --" ) )['total'] );
	}

	/** Credentials are not accepted as arbitrary additional audit fields. */
	public function test_audit_rejects_credential_fields(): void {
		global $wpdb;
		$this->expectException( \InvalidArgumentException::class );
		( new WpdbAuditRepository( $wpdb ) )->insert_batch( array( array( 'Authorization' => 'canary' ) ) );
	}
}
