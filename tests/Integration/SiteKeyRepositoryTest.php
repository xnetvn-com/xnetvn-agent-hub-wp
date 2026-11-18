<?php
/**
 * Per-blog site credential storage, isolation and rotation reconciliation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\Schema;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Uses the real database and owns every disposable table it creates. */
final class SiteKeyRepositoryTest extends WP_UnitTestCase {
	/** Deterministic clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Repository bound to blog 1.
	 *
	 * @var WpdbSiteKeyRepository
	 */
	private WpdbSiteKeyRepository $repo;
	/** Independent disposable connections owned by this test.
	 *
	 * @var array<\wpdb>
	 */
	private array $connections = array();

	/** Migrate, clear plugin-owned rows and bind the fixture clock. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( 1791072000 );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$this->repo = new WpdbSiteKeyRepository( $wpdb, $this->clock, 1 );
	}

	/** Delete only this test's rows, options and disposable tables. */
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

	/** A version-1 site upgrades, keeps personal rows and lists 101 site keys in two pages. */
	public function test_version_one_upgrade_creates_isolated_storage(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys';
		delete_option( Migrator::VERSION_OPTION );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		$user     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$personal = ( new WpdbKeyRepository( $wpdb, $this->clock ) )->insert( new KeyId( 'abcdefghijkl' ), $user, 'Personal fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		( new WpdbAuditRepository( $wpdb ) )->insert_batch(
			array(
				array(
					'occurred_at' => '2026-01-01 00:00:00',
					'request_id'  => '0123456789abcdef',
					'transport'   => 'http',
					'outcome'     => 'success',
					'arg_keys'    => array(),
				),
			)
		);
		$personal_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $personal->id ), ARRAY_A );
		$audit_rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ), ARRAY_A );
		update_option( Migrator::VERSION_OPTION, 1, false );

		self::assertTrue( ( new Migrator( $wpdb, $this->clock ) )->migrate() );
		self::assertSame( 2, Schema::VERSION );
		self::assertSame( 2, (int) get_option( Migrator::VERSION_OPTION ) );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		self::assertSame(
			array( 'id', 'key_id', 'blog_id', 'name', 'secret_hash', 'pepper_id', 'scope_json', 'expires_at', 'created_at', 'created_by', 'revoked_at', 'revoked_by', 'revoke_reason', 'last_used_at', 'last_used_ip', 'last_used_ua' ),
			$columns
		);
		self::assertNotContains( 'user_id', $columns );
		self::assertNotContains( 'created_ip', $columns );
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$names   = array_column( $indexes, 'Key_name' );
		self::assertContains( 'key_id', $names );
		self::assertContains( 'blog_active', $names );
		$unique = array_values( array_filter( $indexes, static fn( array $index ): bool => 'key_id' === $index['Key_name'] ) );
		self::assertSame( '0', (string) $unique[0]['Non_unique'] );
		$blog_active = array_values( array_filter( $indexes, static fn( array $index ): bool => 'blog_active' === $index['Key_name'] ) );
		usort( $blog_active, static fn( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index'] );
		self::assertSame( array( 'blog_id', 'revoked_at', 'expires_at' ), array_column( $blog_active, 'Column_name' ) );

		self::assertSame( $personal_row, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $personal->id ), ARRAY_A ) );
		self::assertSame( $audit_rows, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ), ARRAY_A ) );

		for ( $i = 1; $i <= 101; ++$i ) {
			$this->repo->insert( $this->key_id( $i ), $this->scope(), 'Site fixture ' . $i, str_repeat( 'a', 64 ), 1, 7 );
		}
		self::assertCount( 100, $this->repo->list_all( array( 'per_page' => 500 ) ) );
		self::assertCount(
			1,
			$this->repo->list_all(
				array(
					'per_page' => 500,
					'page'     => 2,
				)
			)
		);
	}

	/** Two blogs cannot read or write each other's site credentials. */
	public function test_two_blogs_isolate_site_credentials(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Two-blog isolation requires the multisite harness.' );
		}
		global $wpdb;
		$old  = $this->repo->insert( $this->key_id( 1 ), $this->scope(), 'Blog A fixture', str_repeat( 'a', 64 ), 1, 7 );
		$site = self::factory()->blog->create();
		try {
			switch_to_blog( $site );
			( new Migrator( $wpdb, $this->clock ) )->migrate();
			$other = new WpdbSiteKeyRepository( $wpdb, $this->clock, $site );
			self::assertNull( $other->find_by_key_id( $old->key_id ) );
			self::assertNull( $other->find_by_id( $old->id ) );
			self::assertFalse( $other->update_key( $old->id, 'Cross blog', $this->narrow( $old->scope, $this->clock->now() + 3600 ) ) );
			self::assertFalse( $other->revoke( $old->id, 7, 'cross_blog' ) );
			$error = $this->failure( fn() => $other->insert_rotation( $old, $this->narrow( $old->scope, $this->clock->now() + 3600 ), $this->key_id( 2 ), str_repeat( 'b', 64 ), 1, 9 ) );
			self::assertInstanceOf( StorageException::class, $error );
			$stale  = new WpdbSiteKeyRepository( $wpdb, $this->clock, 1 );
			$blog_b = $other->insert( $this->key_id( 3 ), $this->scope(), 'Blog B fixture', str_repeat( 'c', 64 ), 1, 7 );
			self::assertSame( 'Blog B fixture', $other->find_by_id( $blog_b->id )->name );
			self::assertNull( $stale->find_by_id( $old->id ) );
			self::assertNull( $stale->find_by_id( $blog_b->id ) );
			self::assertNull( $stale->find_by_key_id( $blog_b->key_id ) );
			self::assertFalse( $stale->update_key( $blog_b->id, 'Cross blog', $this->narrow( $blog_b->scope, $this->clock->now() + 3600 ) ) );
			self::assertFalse( $stale->revoke( $blog_b->id, 7, 'cross_blog_write' ) );
			self::assertSame( 'Blog B fixture', $other->find_by_id( $blog_b->id )->name );
			self::assertNull( $other->find_by_id( $blog_b->id )->revoked_at );
		} finally {
			restore_current_blog();
		}
		self::assertSame( 'Blog A fixture', $this->repo->find_by_id( $old->id )->name );
		self::assertNull( $this->repo->find_by_key_id( new KeyId( $this->key_id( 3 )->value ) ) );
	}

	/** A refused grace write rolls back fully and leaves no reconciliation evidence. */
	public function test_rotation_refuses_pre_commit_write_failure(): void {
		$db    = $this->disposable();
		$repo  = new WpdbSiteKeyRepository( $db, $this->clock, 1 );
		$old   = $this->seed( $repo );
		$grace = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$db->query( $db->prepare( "CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rotation test refusal'", $db->prefix . 'refuse_site_update', $this->table( $db ) ) );
		$events   = array();
		$listener = static function ( ...$args ) use ( &$events ): void {
			$events[] = $args;
		};
		add_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $listener, 10, 4 );
		try {
			$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $this->key_id( 20 ), str_repeat( 'b', 64 ), 1, 9 ) );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $listener );
		}
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( array(), $events );
		self::assertSame( 1, $this->rows( $db ) );
		$row = $this->raw( $db, $old->key_id->value );
		self::assertSame( $old->scope->expires_at->format( 'Y-m-d H:i:s' ), $row['expires_at'] );
		self::assertSame( $old->scope->to_array(), Json::array( (string) $row['scope_json'] ) );
		self::assertSame(
			array(
				'outcome' => 'not_committed',
				'record'  => null,
			),
			$repo->reconcile_rotation()
		);
		self::assertFalse( $repo->resolve_rotation_intent() );
		self::assertNull( get_option( WpdbSiteKeyRepository::ROTATION_OPTION, null ) );
	}

	/** A lost COMMIT acknowledgement is classified, auto-revoked once and never replayed. */
	public function test_lost_commit_acknowledgement_is_reconciled_once(): void {
		$db     = $this->disposable();
		$repo   = new class( $db, $this->clock, 1 ) extends WpdbSiteKeyRepository {
			/**
			 * Commit for real, then lose the acknowledgement.
			 *
			 * @param \mysqli $handle Pinned connection.
			 * @return void
			 * @throws \RuntimeException Always, after the real commit.
			 */
			protected function commit( \mysqli $handle ): void {
				parent::commit( $handle );
				throw new \RuntimeException( 'Simulated lost acknowledgement.' );
			}
		};
		$old    = $this->seed( $repo );
		$grace  = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new_id = $this->key_id( 30 );
		$events = array();
		$hook   = static function ( ...$args ) use ( &$events ): void {
			$events[] = $args;
		};
		add_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $hook, 10, 4 );
		try {
			$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $new_id, str_repeat( 'b', 64 ), 1, 9 ) );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $hook );
		}
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( 'Site credential rotation outcome is unknown.', $error->getMessage() );
		self::assertSame( array( array( $old->id, $new_id->value ) ), $events );
		self::assertSame( 2, $this->rows( $db ) );
		$state = $repo->reconcile_rotation();
		self::assertSame( 'committed', $state['outcome'] );
		self::assertCount( 9, $state['record'] );
		self::assertSame( 'commit_unknown', $state['record']['phase'] );
		self::assertSame( array( 'v', 'blog_id', 'old_id', 'old_expires_at', 'grace_expires_at', 'new_key_id', 'actor', 'attempted_at', 'phase' ), array_keys( $state['record'] ) );
		self::assertTrue( $repo->resolve_rotation_intent() );
		self::assertNull( $repo->reconcile_rotation()['record'] );
		$replacement = $this->raw( $db, $new_id->value );
		self::assertSame( 'rotation_unacknowledged', $replacement['revoke_reason'] );
		self::assertNull( $replacement['revoked_by'] );
		self::assertSame( 2, $this->rows( $db ) );
	}

	/** A rollback that cannot be confirmed keeps commit_unknown evidence and blocks replay. */
	public function test_failed_rollback_is_treated_as_unknown(): void {
		$db     = $this->disposable();
		$repo   = new class( $db, $this->clock, 1 ) extends WpdbSiteKeyRepository {
			/**
			 * Roll back for real, then throw so the outcome stays uncertain.
			 *
			 * @param \mysqli $handle Pinned connection.
			 * @return void
			 * @throws \RuntimeException Always, after the real rollback.
			 */
			protected function rollback( \mysqli $handle ): void {
				parent::rollback( $handle );
				throw new \RuntimeException( 'Simulated lost rollback acknowledgement.' );
			}
		};
		$old    = $this->seed( $repo );
		$grace  = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new_id = $this->key_id( 40 );
		$db->query( $db->prepare( "CREATE TRIGGER %i BEFORE UPDATE ON %i FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rotation test refusal'", $db->prefix . 'refuse_site_rollback', $this->table( $db ) ) );
		$events   = array();
		$listener = static function ( ...$args ) use ( &$events ): void {
			$events[] = $args;
		};
		add_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $listener, 10, 4 );
		try {
			$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $new_id, str_repeat( 'b', 64 ), 1, 9 ) );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $listener );
		}
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( array( array( $old->id, $new_id->value ) ), $events );
		self::assertSame( 'commit_unknown', $repo->reconcile_rotation()['record']['phase'] );
		$db->query( $db->prepare( 'DROP TRIGGER %i', $db->prefix . 'refuse_site_rollback' ) );
		$fresh = $this->connection( $db->prefix );
		$probe = new WpdbSiteKeyRepository( $fresh, $this->clock, 1 );
		$state = $probe->reconcile_rotation();
		self::assertSame( 'not_committed', $state['outcome'] );
		self::assertSame( 1, $this->rows( $db ) );
		self::assertTrue( $probe->resolve_rotation_intent() );
		$new = $repo->insert_rotation( $old, $grace, $this->key_id( 41 ), str_repeat( 'c', 64 ), 1, 9 );
		self::assertSame( $this->key_id( 41 )->value, $new->key_id->value );
		self::assertSame( 2, $this->rows( $db ) );
	}

	/** A leftover prepared record whose replacement committed is kept for the operator. */
	public function test_prepared_committed_record_is_kept_for_operator(): void {
		$db     = $this->disposable();
		$repo   = new WpdbSiteKeyRepository( $db, $this->clock, 1 );
		$old    = $this->seed( $repo );
		$grace  = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new_id = $this->key_id( 50 );
		$repo->insert( $new_id, $old->scope, $old->name, str_repeat( 'b', 64 ), 1, 9 );
		$this->move_to_grace( $db, $old, $grace );
		$this->seed_record( $this->record( $old, $grace, $new_id, 9, 'prepared' ) );
		$state = $repo->reconcile_rotation();
		self::assertSame( 'committed', $state['outcome'] );
		self::assertSame( 'prepared', $state['record']['phase'] );
		self::assertFalse( $repo->resolve_rotation_intent() );
		self::assertNotNull( $repo->reconcile_rotation()['record'] );
		self::assertNull( $this->raw( $db, $new_id->value )['revoked_at'] );
		$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $this->key_id( 51 ), str_repeat( 'c', 64 ), 1, 9 ) );
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( 2, $this->rows( $db ) );
	}

	/** A partial rotation is unknown, is kept and blocks a further attempt. */
	public function test_partial_rotation_is_unknown_and_blocks(): void {
		$db     = $this->disposable();
		$repo   = new WpdbSiteKeyRepository( $db, $this->clock, 1 );
		$old    = $this->seed( $repo );
		$grace  = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new_id = $this->key_id( 60 );
		$repo->insert( $new_id, $old->scope, $old->name, str_repeat( 'b', 64 ), 1, 9 );
		$this->seed_record( $this->record( $old, $grace, $new_id, 9, 'prepared' ) );
		$state = $repo->reconcile_rotation();
		self::assertSame( 'unknown', $state['outcome'] );
		self::assertFalse( $repo->resolve_rotation_intent() );
		self::assertNotNull( $repo->reconcile_rotation()['record'] );
		$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $this->key_id( 61 ), str_repeat( 'c', 64 ), 1, 9 ) );
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( 2, $this->rows( $db ) );
		self::assertSame( $old->scope->expires_at->format( 'Y-m-d H:i:s' ), $this->raw( $db, $old->key_id->value )['expires_at'] );
	}

	/** A tampered durable record stops the rotation before the transaction starts. */
	public function test_tampered_record_blocks_before_transaction(): void {
		global $wpdb;
		$db     = $this->disposable();
		$repo   = new WpdbSiteKeyRepository( $db, $this->clock, 1 );
		$old    = $this->seed( $repo );
		$grace  = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new_id = $this->key_id( 70 );
		$filter = static function ( mixed $pre ) use ( $wpdb ): mixed {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, WpdbSiteKeyRepository::ROTATION_OPTION ) );
			return (int) $exists > 0 ? array( 'tampered' ) : $pre;
		};
		add_filter( 'pre_option_' . WpdbSiteKeyRepository::ROTATION_OPTION, $filter );
		try {
			$error = $this->failure( fn() => $repo->insert_rotation( $old, $grace, $new_id, str_repeat( 'b', 64 ), 1, 9 ) );
		} finally {
			remove_filter( 'pre_option_' . WpdbSiteKeyRepository::ROTATION_OPTION, $filter );
		}
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( 1, $this->rows( $db ) );
		self::assertSame( $old->scope->expires_at->format( 'Y-m-d H:i:s' ), $this->raw( $db, $old->key_id->value )['expires_at'] );
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WpdbSiteKeyRepository::ROTATION_OPTION ) );
		self::assertIsString( $stored );
		$record = Json::array( $stored );
		self::assertSame( array( 'v', 'blog_id', 'old_id', 'old_expires_at', 'grace_expires_at', 'new_key_id', 'actor', 'attempted_at', 'phase' ), array_keys( $record ) );
		self::assertSame( 'prepared', $record['phase'] );
		self::assertSame( $old->id, $record['old_id'] );
		self::assertSame( $new_id->value, $record['new_key_id'] );
	}

	/** A future version blocks, the reset restores migration and personal rows survive. */
	public function test_rollback_requires_version_option_reset(): void {
		global $wpdb;
		$user     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$personal = ( new WpdbKeyRepository( $wpdb, $this->clock ) )->insert( new KeyId( 'abcdefghijkl' ), $user, 'Personal fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$before   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		update_option( Migrator::VERSION_OPTION, Schema::VERSION + 1 );
		$error = $this->failure( fn() => ( new Migrator( $wpdb, $this->clock ) )->migrate() );
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( (string) ( Schema::VERSION + 1 ), (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) ) );
		delete_option( Migrator::VERSION_OPTION );
		self::assertTrue( ( new Migrator( $wpdb, $this->clock ) )->migrate() );
		self::assertSame( (string) Schema::VERSION, (string) get_option( Migrator::VERSION_OPTION ) );
		self::assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) ) );
		self::assertSame( 'Personal fixture', ( new WpdbKeyRepository( $wpdb, $this->clock ) )->find_by_id( $personal->id )->name );
	}

	/** The indexed and serialized expiries agree after a committed rotation. */
	public function test_rotation_keeps_scope_json_expiry_in_agreement(): void {
		$db    = $this->disposable();
		$repo  = new WpdbSiteKeyRepository( $db, $this->clock, 1 );
		$old   = $this->seed( $repo, 1, null, 7 );
		$grace = $this->narrow( $old->scope, $this->clock->now() + 3600 );
		$new   = $repo->insert_rotation( $old, $grace, $this->key_id( 80 ), str_repeat( 'b', 64 ), 1, 9 );
		$row   = $this->raw( $db, $old->key_id->value );
		self::assertSame( $grace->expires_at->format( 'Y-m-d H:i:s' ), $row['expires_at'] );
		$scope = Json::array( (string) $row['scope_json'] );
		self::assertSame( $grace->expires_at->format( 'Y-m-d\TH:i:s\Z' ), $scope['expires_at'] );
		$before = $old->scope->to_array();
		$after  = $scope;
		unset( $before['expires_at'], $after['expires_at'] );
		self::assertSame( $before, $after );
		$stored = $repo->find_by_key_id( $old->key_id );
		self::assertSame( $grace->expires_at->getTimestamp(), $stored->key->scope->expires_at->getTimestamp() );
		self::assertCount( 2, $repo->list_all( array( 'per_page' => 100 ) ) );
		$replacement = $repo->find_by_id( $new->id );
		self::assertSame( $old->name, $replacement->name );
		self::assertSame( 9, $replacement->created_by );
		self::assertSame( $old->scope->to_array(), $replacement->scope->to_array() );
		self::assertSame( 7, $repo->find_by_id( $old->id )->created_by );
		self::assertSame( $grace->expires_at->getTimestamp(), $repo->find_by_id( $old->id )->scope->expires_at->getTimestamp() );
	}

	/** Corrupt scope, expiry mismatch, unsupported storage and missing rows fail closed. */
	public function test_fail_closed_hydration_and_unsupported_storage(): void {
		global $wpdb;
		$corrupt = $this->seed( $this->repo, 1 );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scope_json = %s WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', '{', $corrupt->id ) );
		$before = $this->raw( $wpdb, $corrupt->key_id->value );
		foreach ( array(
			fn() => $this->repo->find_by_key_id( $corrupt->key_id ),
			fn() => $this->repo->find_by_id( $corrupt->id ),
			fn() => $this->repo->list_all(),
			fn() => $this->repo->update_key( $corrupt->id, 'Corrupt', $this->narrow( $corrupt->scope, $this->clock->now() + 3600 ) ),
		) as $operation ) {
			self::assertInstanceOf( StorageException::class, $this->failure( $operation ) );
		}
		self::assertSame( $before, $this->raw( $wpdb, $corrupt->key_id->value ) );

		$mismatch           = $this->seed( $this->repo, 2 );
		$data               = $mismatch->scope->to_array();
		$data['expires_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() + 7200 );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scope_json = %s WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', Json::encode( $data ), $mismatch->id ) );
		$before = $this->raw( $wpdb, $mismatch->key_id->value );
		foreach ( array(
			fn() => $this->repo->find_by_key_id( $mismatch->key_id ),
			fn() => $this->repo->find_by_id( $mismatch->id ),
			fn() => $this->repo->list_all(),
			fn() => $this->repo->update_key( $mismatch->id, 'Mismatch', $this->narrow( $mismatch->scope, $this->clock->now() + 3600 ) ),
		) as $operation ) {
			self::assertInstanceOf( StorageException::class, $this->failure( $operation ) );
		}
		self::assertSame( $before, $this->raw( $wpdb, $mismatch->key_id->value ) );
		self::assertSame( 2, $this->rows( $wpdb ) );

		$myisam = $this->myisam();
		$repo   = new WpdbSiteKeyRepository( $myisam, $this->clock, 1 );
		$old    = $this->seed( $repo, 3 );
		$error  = $this->failure( fn() => $repo->insert_rotation( $old, $this->narrow( $old->scope, $this->clock->now() + 3600 ), $this->key_id( 90 ), str_repeat( 'b', 64 ), 1, 9 ) );
		self::assertInstanceOf( StorageException::class, $error );
		self::assertSame( 1, $this->rows( $myisam ) );
		self::assertNull( get_option( WpdbSiteKeyRepository::ROTATION_OPTION, null ) );

		$empty = new WpdbSiteKeyRepository( $this->disposable(), $this->clock, 1 );
		self::assertNull( $empty->find_by_key_id( $this->key_id( 95 ) ) );
		self::assertNull( $empty->find_by_id( 999999 ) );
		self::assertSame( array(), $empty->list_all() );
		self::assertFalse( $empty->update_key( 999999, 'Missing', $this->scope() ) );
		self::assertFalse( $empty->revoke( 999999, 7, 'missing' ) );
		self::assertFalse( $empty->touch_last_used( 999999, '203.0.113.1', 'test' ) );
	}

	/** Sensitive parameters are marked and peer metadata stays bounded by characters. */
	public function test_sensitive_metadata_and_user_agent_bounds(): void {
		global $wpdb;
		foreach ( array( SiteKeyRepository::class, WpdbSiteKeyRepository::class ) as $class ) {
			$reflection = new \ReflectionClass( $class );
			$hashes     = 0;
			foreach ( $reflection->getMethods() as $method ) {
				foreach ( $method->getParameters() as $parameter ) {
					if ( 'hash' === $parameter->getName() ) {
						++$hashes;
						self::assertNotEmpty( $parameter->getAttributes( \SensitiveParameter::class ), $class . '::$hash must be sensitive.' );
					}
				}
			}
			self::assertSame( 2, $hashes, $class . ' must declare exactly two hash parameters.' );
		}
		$native = new \ReflectionMethod( WpdbSiteKeyRepository::class, 'native_query' );
		$sql    = $native->getParameters()[1];
		self::assertSame( 'sql', $sql->getName() );
		self::assertNotEmpty( $sql->getAttributes( \SensitiveParameter::class ) );

		$row = $this->seed( $this->repo );
		$ua  = str_repeat( 'ệ', 600 );
		self::assertTrue( $this->repo->touch_last_used( $row->id, 'not-an-ip', $ua ) );
		$stored = $this->raw( $wpdb, $row->key_id->value );
		self::assertNull( $stored['last_used_ip'] );
		self::assertSame( 255, mb_strlen( (string) $stored['last_used_ua'] ) );
		self::assertGreaterThan( 255, strlen( (string) $stored['last_used_ua'] ) );
		$this->clock->timestamp += 299;
		self::assertFalse( $this->repo->touch_last_used( $row->id, '203.0.113.9', 'short' ) );
		$this->clock->timestamp += 1;
		self::assertTrue( $this->repo->touch_last_used( $row->id, '203.0.113.9', 'short' ) );
		self::assertSame( '203.0.113.9', $this->raw( $wpdb, $row->key_id->value )['last_used_ip'] );
	}

	/** Narrowing, revocation precedence and state filters stay blog-scoped. */
	public function test_narrowing_revocation_and_state_filters(): void {
		$row    = $this->seed( $this->repo );
		$narrow = $this->narrow( $row->scope, $this->clock->now() + 1800 );
		self::assertTrue( $this->repo->update_key( $row->id, 'Narrowed fixture', $narrow ) );
		self::assertSame( 'Narrowed fixture', $this->repo->find_by_id( $row->id )->name );
		self::assertSame( 1, $this->repo->count_active() );
		self::assertCount( 1, $this->repo->list_all( array( 'state' => 'active' ) ) );
		self::assertCount( 0, $this->repo->list_all( array( 'state' => 'revoked' ) ) );
		self::assertTrue( $this->repo->revoke( $row->id, 7, 'owner_request' ) );
		self::assertFalse( $this->repo->revoke( $row->id, 7, 'second_request' ) );
		self::assertSame( 0, $this->repo->count_active() );
		self::assertCount( 0, $this->repo->list_all( array( 'state' => 'active' ) ) );
		self::assertCount( 1, $this->repo->list_all( array( 'state' => 'revoked' ) ) );
		self::assertFalse( $this->repo->update_key( $row->id, 'Revoked', $narrow ) );
		self::assertFalse( $this->repo->touch_last_used( $row->id, '203.0.113.1', 'test' ) );
		$this->clock->timestamp += 7200;
		self::assertCount( 1, $this->repo->list_all( array( 'state' => 'revoked' ) ) );
	}

	/**
	 * Build a twelve-character lowercase base32 identifier.
	 *
	 * @param int $seed Unique seed.
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
	 * Build a bounded editorial scope.
	 *
	 * @return SiteScope
	 */
	private function scope(): SiteScope {
		return new SiteScope(
			RiskTier::Write,
			array( 'content', 'media' ),
			array( 'xnetvn/create-post' ),
			array( 'xnetvn/delete-post' ),
			array(),
			array( '203.0.113.0/24' ),
			new DateTimeImmutable( '@' . ( $this->clock->now() + 2592000 ) )
		);
	}

	/**
	 * Build the same scope with a moved expiry.
	 *
	 * @param SiteScope $scope Source scope.
	 * @param int       $expires New Unix expiry.
	 * @return SiteScope
	 */
	private function narrow( SiteScope $scope, int $expires ): SiteScope {
		return new SiteScope( $scope->tier_ceiling, $scope->toolsets, $scope->allowed_abilities, $scope->denied_abilities, $scope->permissions, $scope->ip_allowlist, new DateTimeImmutable( '@' . $expires ) );
	}

	/**
	 * Insert one fixture credential.
	 *
	 * @param WpdbSiteKeyRepository $repo Repository.
	 * @param int                   $seed Identifier seed.
	 * @param SiteScope|null        $scope Optional scope.
	 * @param int                   $created_by Manager attribution.
	 * @return SiteCredential
	 */
	private function seed( WpdbSiteKeyRepository $repo, int $seed = 1, ?SiteScope $scope = null, int $created_by = 7 ): SiteCredential {
		return $repo->insert( $this->key_id( $seed ), $scope ?? $this->scope(), 'Site fixture ' . $seed, str_repeat( 'a', 64 ), 1, $created_by );
	}

	/**
	 * Build the exact nine-key reconciliation record.
	 *
	 * @param SiteCredential              $old Expected snapshot.
	 * @param SiteScope                   $grace Narrowed expiry.
	 * @param KeyId                       $new_id Replacement identifier.
	 * @param int                         $actor Rotating manager.
	 * @param 'prepared'|'commit_unknown' $phase Durable phase.
	 * @return array<string,int|string>
	 */
	private function record( SiteCredential $old, SiteScope $grace, KeyId $new_id, int $actor, string $phase ): array {
		return array(
			'v'                => 1,
			'blog_id'          => 1,
			'old_id'           => $old->id,
			'old_expires_at'   => $old->scope->expires_at->format( 'Y-m-d H:i:s' ),
			'grace_expires_at' => $grace->expires_at->format( 'Y-m-d H:i:s' ),
			'new_key_id'       => $new_id->value,
			'actor'            => $actor,
			'attempted_at'     => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
			'phase'            => $phase,
		);
	}

	/**
	 * Store a reconciliation record directly.
	 *
	 * @param array<string,int|string> $record Evidence.
	 * @return void
	 */
	private function seed_record( array $record ): void {
		update_option( WpdbSiteKeyRepository::ROTATION_OPTION, Json::encode( $record ), false );
	}

	/**
	 * Move the old row to the grace expiry in both encodings.
	 *
	 * @param \wpdb          $db Database.
	 * @param SiteCredential $old Old credential.
	 * @param SiteScope      $grace Narrowed scope.
	 * @return void
	 */
	private function move_to_grace( \wpdb $db, SiteCredential $old, SiteScope $grace ): void {
		$data               = $old->scope->to_array();
		$data['expires_at'] = $grace->expires_at->format( 'Y-m-d\TH:i:s\Z' );
		$db->query( $db->prepare( 'UPDATE %i SET expires_at = %s, scope_json = %s WHERE id = %d AND blog_id = %d', $this->table( $db ), $grace->expires_at->format( 'Y-m-d H:i:s' ), Json::encode( $data ), $old->id, 1 ) );
	}

	/**
	 * Create an isolated InnoDB schema on its own connection.
	 *
	 * @return \wpdb
	 */
	private function disposable(): \wpdb {
		global $wpdb;
		$db = $this->connection( $wpdb->prefix . 'siterot_' . bin2hex( random_bytes( 4 ) ) . '_' );
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
	 * Create an isolated MyISAM site table on its own connection.
	 *
	 * @return \wpdb
	 */
	private function myisam(): \wpdb {
		global $wpdb;
		$db        = $this->connection( $wpdb->prefix . 'sitemy_' . bin2hex( random_bytes( 4 ) ) . '_' );
		$statement = '';
		foreach ( Schema::statements( $db ) as $sql ) {
			if ( str_contains( $sql, '_site_keys' ) ) {
				$statement = $sql;
			}
		}
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			self::assertNotFalse( $db->query( rtrim( $statement, ';' ) . ' ENGINE=MyISAM;' ) );
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		}
		return $db;
	}

	/**
	 * Create a disposable connection with a unique prefix.
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
	 * Resolve the site-key table for a connection.
	 *
	 * @param \wpdb $db Database.
	 * @return string
	 */
	private function table( \wpdb $db ): string {
		return $db->prefix . 'xnetvn_agent_hub_wp_site_keys';
	}

	/**
	 * Count site-key rows for a connection.
	 *
	 * @param \wpdb $db Database.
	 * @return int
	 */
	private function rows( \wpdb $db ): int {
		return (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $this->table( $db ) ) );
	}

	/**
	 * Read one raw site-key row.
	 *
	 * @param \wpdb  $db Database.
	 * @param string $key_id Public identifier.
	 * @return array<string,mixed>
	 */
	private function raw( \wpdb $db, string $key_id ): array {
		$row = $db->get_row( $db->prepare( 'SELECT * FROM %i WHERE key_id = %s', $this->table( $db ), $key_id ), ARRAY_A );
		self::assertIsArray( $row );
		return $row;
	}

	/**
	 * Capture a storage failure.
	 *
	 * @param callable $operation Operation under test.
	 * @return \Throwable
	 */
	private function failure( callable $operation ): \Throwable {
		try {
			$operation();
		} catch ( \Throwable $error ) {
			return $error;
		}
		self::fail( 'Expected a storage failure.' );
		return new \RuntimeException( 'Unreachable after a failed assertion.' );
	}
}
