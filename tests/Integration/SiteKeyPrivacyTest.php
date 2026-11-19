<?php
/**
 * Site-key privacy attribution and site-administration expiry notices.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Audit\PrivacyEraser;
use XNetVN\AgentHubWP\Audit\PrivacyExporter;
use XNetVN\AgentHubWP\Auth\Notifications;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpPrivacyStore;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves the real database boundaries and the intercepted mail sink, never a delivery. */
final class SiteKeyPrivacyTest extends WP_UnitTestCase {
	/** Captured mail fixture.
	 *
	 * @var array<array<string,mixed>>
	 */
	private array $messages = array();
	/** Deterministic clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Personal key repository.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/** Audit repository.
	 *
	 * @var WpdbAuditRepository
	 */
	private WpdbAuditRepository $audit;
	/** Site key repository bound to the entry blog.
	 *
	 * @var WpdbSiteKeyRepository
	 */
	private WpdbSiteKeyRepository $site_keys;
	/** Fixture creator.
	 *
	 * @var int
	 */
	private int $creator;
	/** Fixture manager.
	 *
	 * @var int
	 */
	private int $manager;
	/** Entry blog captured before any switch.
	 *
	 * @var int
	 */
	private int $entry_blog;
	/** Prior site administration address.
	 *
	 * @var string
	 */
	private string $prior_admin_email = '';
	/** Prior site settings option, or null when it did not exist.
	 *
	 * @var mixed
	 */
	private mixed $prior_settings = null;
	/** Factory-created sites still owned by this test.
	 *
	 * @var array<int>
	 */
	private array $sites = array();
	/** Site marker options owned by this test.
	 *
	 * @var array<int,string>
	 */
	private array $markers = array();

	/** Create plugin-owned fixtures and attach the no-delivery capture callback. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock      = new FrozenClock( 1791158400 );
		$this->entry_blog = max( 1, (int) get_current_blog_id() );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		wp_clear_scheduled_hook( Notifications::HOOK );
		wp_clear_scheduled_hook( Notifications::BATCH_HOOK );
		$this->delete_rows( $wpdb->prefix );
		$this->delete_site_markers( $wpdb->prefix );
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
		$prior                   = get_option( 'admin_email' );
		$this->prior_admin_email = is_string( $prior ) ? $prior : '';
		$this->prior_settings    = get_option( WpSiteSettings::OPTION, null );
		update_option( 'admin_email', 'site-admin@example.test' );
		$this->keys      = new WpdbKeyRepository( $wpdb, $this->clock );
		$this->audit     = new WpdbAuditRepository( $wpdb );
		$this->site_keys = new WpdbSiteKeyRepository( $wpdb, $this->clock, $this->entry_blog );
		$this->creator   = self::factory()->user->create( array( 'user_email' => 'creator-canary@example.test' ) );
		$this->manager   = self::factory()->user->create( array( 'user_email' => 'manager-site@example.test' ) );
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	/** Remove only this test's rows, options, sites and schedules. */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 10 );
		wp_clear_scheduled_hook( Notifications::HOOK );
		wp_clear_scheduled_hook( Notifications::BATCH_HOOK );
		$guard = 0;
		while ( get_current_blog_id() !== $this->entry_blog && $guard < 10 ) {
			restore_current_blog();
			++$guard;
		}
		foreach ( $this->markers as $marker ) {
			delete_option( $marker );
		}
		$this->markers = array();
		global $wpdb;
		$this->delete_rows( $wpdb->prefix );
		foreach ( $this->sites as $site ) {
			if ( ! function_exists( 'get_site' ) || null === get_site( $site ) ) {
				continue;
			}
			switch_to_blog( $site );
			try {
				$this->delete_rows( $wpdb->prefix );
				$this->delete_site_markers( $wpdb->prefix );
				delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
			} finally {
				restore_current_blog();
			}
		}
		$this->sites = array();
		if ( '' !== $this->prior_admin_email ) {
			update_option( 'admin_email', $this->prior_admin_email );
		}
		if ( null === $this->prior_settings ) {
			delete_option( WpSiteSettings::OPTION );
		} else {
			update_option( WpSiteSettings::OPTION, $this->prior_settings, false );
		}
		parent::tear_down();
	}

	/**
	 * Intercept a message without a network or sendmail operation.
	 *
	 * @param bool|null           $result Existing short circuit.
	 * @param array<string,mixed> $message Mail fields.
	 * @return bool
	 */
	public function capture( ?bool $result, array $message ): bool {
		$this->messages[] = $message;
		return true;
	}

	/**
	 * Change the site address without capturing core's own admin-email notice.
	 *
	 * @param string $email New address.
	 */
	private function set_admin_email( string $email ): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 10 );
		update_option( 'admin_email', $email );
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	/** A person sees only safe site-key attribution and the export never leaks a stored name. */
	public function test_export_lists_only_safe_site_key_attribution(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys';
		$this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->creator, 'Personal fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$corrupted_id = 0;
		for ( $seed = 1; $seed <= 50; ++$seed ) {
			$name = 'Site fixture ' . $seed;
			if ( 1 === $seed ) {
				$name = 'xns_abcdefghijkl_CANARYSECRET';
			}
			if ( 2 === $seed ) {
				$name = 'CANARY' . str_repeat( 'c', 58 );
			}
			$inserted = $this->insert_site_key( $seed, 30 * DAY_IN_SECONDS, $name );
			if ( 1 === $seed ) {
				$corrupted_id = $inserted->id;
			}
		}
		$revoked = $this->site_keys->insert( $this->key_id( 51 ), $this->site_scope( 30 * DAY_IN_SECONDS ), 'Revoked by creator', str_repeat( 'c', 64 ), 1, $this->manager );
		self::assertTrue( $this->site_keys->revoke( $revoked->id, $this->creator, 'administrator_request' ) );
		$this->audit->insert_batch( array( array_merge( $this->event( $this->creator ), array( 'credential_ref' => 'xnv:abcdefghijkl' ) ) ) );
		self::assertSame( 1, $this->insert_site_audit_row() );

		$exporter  = new PrivacyExporter( new WpPrivacyStore( $wpdb ) );
		$page_one  = $exporter->export( 'creator-canary@example.test', 1 );
		$page_two  = $exporter->export( 'creator-canary@example.test', 2 );
		$site_rows = $this->site_items( $page_one );
		self::assertNotEmpty( $site_rows );
		self::assertCount( 50, $site_rows );
		self::assertFalse( $page_one['done'] );
		self::assertTrue( $page_two['done'] );
		$second_page = $this->site_items( $page_two );
		self::assertCount( 1, $second_page );
		$personal_items = array_values(
			array_filter(
				$page_one['data'],
				fn( array $item ): bool => 'xnetvn-agent-hub-wp-keys' === $item['group_id'] && 'issued_site_key' !== $this->value( $item, 'role' )
			)
		);
		self::assertCount( 1, $personal_items );

		foreach ( $site_rows as $row ) {
			$key_id = substr( (string) $this->value( $row, 'credential_ref' ), 4 );
			self::assertSame(
				array( 'id', 'credential_ref', 'name', 'role', 'blog_id', 'created_at', 'expires_at' ),
				array_column( $row['data'], 'name' )
			);
			self::assertStringStartsWith( 'site:', (string) $this->value( $row, 'id' ) );
			self::assertSame( 'xns:' . $key_id, $this->value( $row, 'credential_ref' ) );
			self::assertSame( 'Site key ' . $key_id, $this->value( $row, 'name' ) );
			self::assertSame( 'issued_site_key', $this->value( $row, 'role' ) );
			self::assertSame( (string) $this->entry_blog, $this->value( $row, 'blog_id' ) );
			self::assertStringNotContainsString( 'CANARY', (string) $this->value( $row, 'name' ) );
		}

		$revoked_row = $second_page[0];
		self::assertSame(
			array( 'id', 'credential_ref', 'name', 'role', 'blog_id', 'created_at', 'expires_at', 'revoked_at' ),
			array_column( $revoked_row['data'], 'name' )
		);
		self::assertNotNull( $this->value( $revoked_row, 'revoked_at' ) );
		self::assertSame( 'Site key ' . $revoked->key_id->value, $this->value( $revoked_row, 'name' ) );
		self::assertSame( 'xns:' . $revoked->key_id->value, $this->value( $revoked_row, 'credential_ref' ) );

		$item_ids = array_column( $page_one['data'], 'item_id' );
		self::assertSame( count( $item_ids ), count( array_unique( $item_ids ) ) );
		$audit_items = array_values(
			array_filter(
				$page_one['data'],
				static fn( array $item ): bool => 'xnetvn-agent-hub-wp-audit' === $item['group_id']
			)
		);
		self::assertCount( 1, $audit_items );
		self::assertSame( 'xnv:abcdefghijkl', $this->value( $audit_items[0], 'credential_ref' ) );
		$json = (string) wp_json_encode( array( $page_one, $page_two ) );
		foreach ( array( 'CANARY', 'xns:bcdefghijklm', str_repeat( 'c', 64 ), 'secret_hash', 'pepper_id', 'scope_json', 'last_used_ip', 'last_used_ua', 'created_by', 'revoked_by' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $json );
		}
		self::assertSame( 1, $wpdb->update( $table, array( 'key_id' => 'CANARYCANARY' ), array( 'id' => $corrupted_id ) ) );
		$this->expectException( StorageException::class );
		$exporter->export( 'creator-canary@example.test', 1 );
	}

	/** Erasure is bounded, idempotent and never touches the site-owned credential. */
	public function test_erasure_is_bounded_idempotent_and_keeps_site_keys(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys';
		$this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->creator, 'Personal fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$this->audit->insert_batch( array( array_merge( $this->event( $this->creator ), array( 'credential_ref' => 'xnv:abcdefghijkl' ) ) ) );
		self::assertSame( 1, $this->insert_site_audit_row() );
		for ( $seed = 1; $seed <= 50; ++$seed ) {
			$this->insert_site_key( $seed, 30 * DAY_IN_SECONDS, 'Active fixture ' . $seed );
		}
		$revoked_by_manager = $this->site_keys->insert( $this->key_id( 51 ), $this->site_scope( 30 * DAY_IN_SECONDS ), 'Revoked by manager', str_repeat( 'c', 64 ), 1, $this->creator );
		self::assertTrue( $this->site_keys->revoke( $revoked_by_manager->id, $this->manager, 'administrator_request' ) );
		$revoked_by_creator = $this->site_keys->insert( $this->key_id( 52 ), $this->site_scope( 30 * DAY_IN_SECONDS ), 'Revoked by creator', str_repeat( 'c', 64 ), 1, $this->manager );
		self::assertTrue( $this->site_keys->revoke( $revoked_by_creator->id, $this->creator, 'administrator_request' ) );
		$both = $this->site_keys->insert( $this->key_id( 53 ), $this->site_scope( 30 * DAY_IN_SECONDS ), 'Both columns', str_repeat( 'c', 64 ), 1, $this->creator );
		self::assertTrue( $this->site_keys->revoke( $both->id, $this->creator, 'administrator_request' ) );
		$this->remember_marker( 'xnetvn_agent_hub_wp_notice_site_expiry_' . $this->key_id( 1 )->value . '_' . ( $this->clock->now() + 6 * DAY_IN_SECONDS ), $this->clock->now() + 7 * DAY_IN_SECONDS );

		$snapshot = $wpdb->get_results( $wpdb->prepare( 'SELECT id,key_id,secret_hash,pepper_id,scope_json,expires_at,revoked_at,revoke_reason FROM %i ORDER BY id ASC', $table ), ARRAY_A );
		$matching = $this->matching_ids();
		self::assertCount( 53, $matching );
		$store = new WpPrivacyStore( $wpdb );
		$first = $store->erase_for_user( $this->creator, 10 );
		self::assertFalse( $first['done'] );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE id = %d', $table, $matching[0] ) ) );
		self::assertTrue( $first['removed'] );
		$after   = $this->matching_ids();
		$changed = count( $matching ) - count( $after );
		self::assertSame( 10, $changed );
		self::assertSame( $this->creator, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE id = %d', $table, $matching[10] ) ) );
		$calls = 1;
		$call  = $first;
		while ( ! $call['done'] && $calls < 20 ) {
			$call  = $store->erase_for_user( $this->creator, 10 );
			$next  = $this->matching_ids();
			$moved = count( $after ) - count( $next );
			$after = $next;
			++$calls;
			self::assertGreaterThan( 0, $moved );
			self::assertLessThanOrEqual( 10, $moved );
			if ( 2 === $calls ) {
				self::assertTrue( $call['removed'] );
				self::assertFalse( $call['done'] );
			}
		}
		self::assertSame( 6, $calls );
		self::assertTrue( $call['done'] );
		self::assertTrue( $call['removed'] );
		$extra = $store->erase_for_user( $this->creator, 10 );
		self::assertFalse( $extra['removed'] );
		self::assertTrue( $extra['done'] );
		$facade = ( new PrivacyEraser( $store ) )->erase( 'creator-canary@example.test', 9 );
		self::assertFalse( $facade['items_removed'] );
		self::assertTrue( $facade['done'] );
		self::assertCount( 0, $this->keys->list_for_user( $this->creator ) );
		self::assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d OR revoked_by = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $this->creator, $this->creator ) )
		);
		$human = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,key_id,credential_ref FROM %i WHERE principal_type = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 'user' ), ARRAY_A );
		self::assertNotNull( $human );
		self::assertNull( $human['user_id'] );
		self::assertNull( $human['key_id'] );
		self::assertNull( $human['credential_ref'] );
		$site_audit = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,key_id,credential_ref FROM %i WHERE principal_type = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 'site' ), ARRAY_A );
		self::assertNotNull( $site_audit );
		self::assertSame( $this->creator, (int) $site_audit['user_id'] );
		self::assertNull( $site_audit['key_id'] );
		self::assertSame( 'xns:bcdefghijklm', $site_audit['credential_ref'] );
		self::assertSame( $snapshot, $wpdb->get_results( $wpdb->prepare( 'SELECT id,key_id,secret_hash,pepper_id,scope_json,expires_at,revoked_at,revoke_reason FROM %i ORDER BY id ASC', $table ), ARRAY_A ) );
		self::assertSame( array(), $this->matching_ids() );
		self::assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE credential_ref LIKE %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 'xnv:%' ) )
		);
		self::assertSame( $this->manager, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_by FROM %i WHERE id = %d', $table, $revoked_by_manager->id ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_by FROM %i WHERE id = %d', $table, $revoked_by_creator->id ) ) );
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_at FROM %i WHERE id = %d', $table, $revoked_by_creator->id ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE id = %d', $table, $both->id ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT revoked_by FROM %i WHERE id = %d', $table, $both->id ) ) );
		self::assertNotFalse( $wpdb->get_var( $wpdb->prepare( 'SELECT key_id FROM %i WHERE id = %d', $table, $both->id ) ) );
		foreach ( $this->markers as $marker ) {
			self::assertNotFalse( get_option( $marker ) );
		}
	}

	/** A site key notice reaches the site address once, after the creator is gone, with no secret. */
	public function test_site_expiry_notice_uses_the_site_admin_after_creator_deletion(): void {
		global $wpdb;
		$first = $this->insert_site_key( 101, 6 * DAY_IN_SECONDS, 'xns_abcdefghijkl_CANARYSECRET', 'xnetvn/canary-ability' );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $this->creator );
		} else {
			wp_delete_user( $this->creator );
		}
		self::assertFalse( get_userdata( $this->creator ) );
		$settings      = new MemorySettings();
		$notifications = new Notifications( $this->keys, $settings, $this->clock, $this->site_keys );
		$first_marker  = $this->remember_key_marker( $first );
		$notifications->expiring();
		$this->set_admin_email( 'site-admin-2@example.test' );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		self::assertSame( 'site-admin-2@example.test', $this->messages[0]['to'] );
		$body = (string) $this->messages[0]['message'];
		self::assertSame(
			'Site key: ' . $first->key_id->value . "\nExpires (UTC): " . gmdate( 'Y-m-d H:i:s', $first->scope->expires_at->getTimestamp() ) . "\nReview site keys: " . admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' ) . "\n\nIf this was unexpected, revoke the affected site key. Never share its secret in support reports.",
			$body
		);
		foreach ( array( 'creator-canary', 'manager-site', 'xns_', 'CANARY', str_repeat( 'c', 64 ), 'pepper', 'scope', 'xnetvn/canary-ability' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $body );
		}
		$notifications->expiring();
		$notifications->flush();
		self::assertCount( 1, $this->messages );

		$second        = $this->insert_site_key( 102, 6 * DAY_IN_SECONDS, 'Second fixture' );
		$third         = $this->insert_site_key( 103, 6 * DAY_IN_SECONDS, 'Third fixture' );
		$second_marker = $this->remember_key_marker( $second );
		$third_marker  = $this->remember_key_marker( $third );
		$notifications->expiring();
		self::assertTrue( $this->site_keys->update_key( $second->id, 'Second fixture narrowed', $this->site_scope( 5 * DAY_IN_SECONDS ) ) );
		self::assertTrue( $this->site_keys->revoke( $third->id, $this->manager, 'administrator_request' ) );
		self::assertNotFalse( get_option( $second_marker ) );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		self::assertFalse( get_option( $second_marker ) );
		self::assertFalse( get_option( $third_marker ) );
		$this->site_keys->revoke( $second->id, $this->manager, 'administrator_request' );

		$fourth            = $this->insert_site_key( 104, 6 * DAY_IN_SECONDS, 'Fourth fixture' );
		$fourth_marker     = $this->remember_key_marker( $fourth );
		$unavailable       = 0;
		$count_unavailable = function () use ( &$unavailable ): void {
			++$unavailable;
		};
		$invalid           = static fn(): string => 'not-an-email';
		add_action( 'xnetvn_agent_hub_wp_notification_unavailable', $count_unavailable );
		add_filter( 'pre_option_admin_email', $invalid );
		try {
			$notifications->expiring();
			$notifications->flush();
		} finally {
			remove_filter( 'pre_option_admin_email', $invalid );
			remove_action( 'xnetvn_agent_hub_wp_notification_unavailable', $count_unavailable );
		}
		self::assertCount( 1, $this->messages );
		self::assertFalse( get_option( $fourth_marker ) );
		self::assertSame( 1, $unavailable );
		$this->site_keys->revoke( $fourth->id, $this->manager, 'administrator_request' );

		$fifth        = $this->insert_site_key( 105, 6 * DAY_IN_SECONDS, 'Fifth fixture' );
		$fifth_marker = $this->remember_key_marker( $fifth );
		$unavailable  = 0;
		$reject       = static fn(): bool => false;
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_filter( 'pre_wp_mail', $reject, PHP_INT_MAX );
		add_action( 'xnetvn_agent_hub_wp_notification_unavailable', $count_unavailable );
		try {
			$notifications->expiring();
			$notifications->flush();
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_notification_unavailable', $count_unavailable );
			remove_filter( 'pre_wp_mail', $reject, PHP_INT_MAX );
			add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		}
		self::assertCount( 2, $this->messages );
		self::assertFalse( get_option( $fifth_marker ) );
		self::assertSame( 1, $unavailable );
		$this->site_keys->revoke( $fifth->id, $this->manager, 'administrator_request' );

		$sixth        = $this->insert_site_key( 106, 6 * DAY_IN_SECONDS, 'Sixth fixture' );
		$sixth_marker = $this->remember_key_marker( $sixth );
		$settings->update( array( 'notify_key_expiring' => false ) );
		$notifications->expiring();
		$notifications->flush();
		self::assertCount( 2, $this->messages );
		self::assertFalse( get_option( $sixth_marker ) );
		$settings->update( array( 'notify_key_expiring' => true ) );
		$this->site_keys->revoke( $sixth->id, $this->manager, 'administrator_request' );
		$this->site_keys->revoke( $first->id, $this->manager, 'administrator_request' );

		for ( $seed = 201; $seed <= 301; ++$seed ) {
			$key = $this->insert_site_key( $seed, 6 * DAY_IN_SECONDS, 'Batch fixture ' . $seed );
			$this->remember_key_marker( $key );
		}
		$notifications->expiring();
		$notifications->flush();
		self::assertCount( 102, $this->messages );
		self::assertNotFalse( wp_next_scheduled( Notifications::BATCH_HOOK, array( 2 ) ) );
		$notifications->expiring( 2 );
		$notifications->flush();
		self::assertCount( 103, $this->messages );
		self::assertFalse( wp_next_scheduled( Notifications::BATCH_HOOK, array( 3 ) ) );

		$future = 'xnetvn_agent_hub_wp_notice_site_expiry_' . $first->key_id->value . '_9999999999';
		$this->remember_marker( $future, $this->clock->now() + 30 * DAY_IN_SECONDS );
		( new WpBucketStore( $wpdb ) )->purge_notices( $this->clock->now() + 8 * DAY_IN_SECONDS );
		self::assertFalse( get_option( $first_marker ) );
		self::assertNotFalse( get_option( $future ) );
	}

	/** Export, erasure and the expiry notice each stay on their own blog. */
	public function test_multisite_privacy_and_notification_stay_on_their_blog(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'The multisite harness is required for the cross-blog guarantees.' );
		}
		global $wpdb;
		$settings_one = new WpSiteSettings();
		$settings_one->update( array( 'notify_key_expiring' => true ) );
		$this->set_admin_email( 'blog-one@example.test' );
		$url_one       = admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' );
		$one           = $this->insert_site_key( 401, 6 * DAY_IN_SECONDS, 'Blog one fixture' );
		$site          = self::factory()->blog->create();
		$this->sites[] = $site;
		switch_to_blog( $site );
		try {
			( new Migrator( $wpdb, $this->clock ) )->migrate();
			$this->set_admin_email( 'blog-two@example.test' );
			( new WpSiteSettings() )->update( array( 'notify_key_expiring' => true ) );
			$url_two           = admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' );
			$repo_two          = new WpdbSiteKeyRepository( $wpdb, $this->clock, $site );
			$notifications_two = new Notifications( $this->keys, new WpSiteSettings(), $this->clock, $repo_two );
			$two               = $repo_two->insert( $this->key_id( 402 ), $this->site_scope( 6 * DAY_IN_SECONDS ), 'Blog two fixture', str_repeat( 'c', 64 ), 1, $this->creator );
		} finally {
			restore_current_blog();
		}
		self::assertNotSame( $url_one, $url_two );

		( new WpPrivacyStore( $wpdb ) )->erase_for_user( $this->creator, 100 );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE key_id = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', $one->key_id->value ) ) );
		switch_to_blog( $site );
		try {
			self::assertSame( $this->creator, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE key_id = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', $two->key_id->value ) ) );
			( new WpPrivacyStore( $wpdb ) )->erase_for_user( $this->creator, 100 );
			self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT created_by FROM %i WHERE key_id = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', $two->key_id->value ) ) );
		} finally {
			restore_current_blog();
		}

		$one_export = $this->insert_site_key( 403, 6 * DAY_IN_SECONDS, 'Blog one export fixture' );
		switch_to_blog( $site );
		try {
			$two_export = ( new WpdbSiteKeyRepository( $wpdb, $this->clock, $site ) )->insert( $this->key_id( 404 ), $this->site_scope( 6 * DAY_IN_SECONDS ), 'Blog two export fixture', str_repeat( 'c', 64 ), 1, $this->creator );
		} finally {
			restore_current_blog();
		}
		$export_one = ( new PrivacyExporter( new WpPrivacyStore( $wpdb ) ) )->export( 'creator-canary@example.test', 1 );
		self::assertSame( array( 'xns:' . $one_export->key_id->value ), $this->site_refs( $export_one ) );
		switch_to_blog( $site );
		try {
			$export_two = ( new PrivacyExporter( new WpPrivacyStore( $wpdb ) ) )->export( 'creator-canary@example.test', 1 );
			self::assertSame( array( 'xns:' . $two_export->key_id->value ), $this->site_refs( $export_two ) );
		} finally {
			restore_current_blog();
		}
		$this->site_keys->revoke( $one_export->id, $this->manager, 'administrator_request' );
		switch_to_blog( $site );
		try {
			( new WpdbSiteKeyRepository( $wpdb, $this->clock, $site ) )->revoke( $two_export->id, $this->manager, 'administrator_request' );
		} finally {
			restore_current_blog();
		}

		$notifications_one = new Notifications( $this->keys, $settings_one, $this->clock, $this->site_keys );
		$marker_one        = $this->remember_key_marker( $one );
		$notifications_one->expiring();
		$marker_foreign = 'xnetvn_agent_hub_wp_notice_site_expiry_' . $two->key_id->value . '_1712345678';
		switch_to_blog( $site );
		try {
			$this->remember_marker( $marker_foreign, $this->clock->now() + 9 * DAY_IN_SECONDS );
			$depth = count( $GLOBALS['_wp_switched_stack'] );
			$notifications_one->flush();
			self::assertSame( $site, get_current_blog_id() );
			self::assertSame( $depth, count( $GLOBALS['_wp_switched_stack'] ) );
			self::assertNotFalse( get_option( $marker_foreign ) );
		} finally {
			restore_current_blog();
		}
		self::assertCount( 1, $this->messages );
		self::assertSame( 'blog-one@example.test', $this->messages[0]['to'] );
		self::assertStringContainsString( 'Site key: ' . $one->key_id->value, (string) $this->messages[0]['message'] );
		self::assertStringContainsString( $url_one, (string) $this->messages[0]['message'] );

		$settings_one->update( array( 'notify_key_expiring' => false ) );
		$notifications_two->expiring();
		$notifications_two->flush();
		self::assertCount( 2, $this->messages );
		self::assertSame( 'blog-two@example.test', $this->messages[1]['to'] );
		self::assertStringContainsString( 'Site key: ' . $two->key_id->value, (string) $this->messages[1]['message'] );
		self::assertStringContainsString( $url_two, (string) $this->messages[1]['message'] );
		self::assertStringNotContainsString( $url_one, (string) $this->messages[1]['message'] );
		self::assertNotFalse( get_option( $marker_one ) );
		self::assertSame( $this->entry_blog, get_current_blog_id() );
		$settings_one->update( array( 'notify_key_expiring' => true ) );

		switch_to_blog( $site );
		try {
			$missing = ( new WpdbSiteKeyRepository( $wpdb, $this->clock, $site ) )->insert( $this->key_id( 406 ), $this->site_scope( 6 * DAY_IN_SECONDS ), 'Blog two missing fixture', str_repeat( 'c', 64 ), 1, $this->creator );
		} finally {
			restore_current_blog();
		}
		$notifications_two->expiring();
		$missing_marker = $this->remember_key_marker( $missing );
		switch_to_blog( $site );
		try {
			self::assertNotFalse( get_option( $missing_marker ) );
		} finally {
			restore_current_blog();
		}
		$site_object = get_site( $site );
		self::assertNotFalse( $site_object );
		self::assertNotSame( 0, (int) $wpdb->delete( $wpdb->blogs, array( 'blog_id' => $site ) ) );
		clean_blog_cache( $site_object );
		wp_cache_flush();
		self::assertNull( get_site( $site ) );
		$this->sites       = array_values( array_diff( $this->sites, array( $site ) ) );
		$reads             = 0;
		$count_admin_email = function () use ( &$reads ): bool {
			++$reads;
			return false;
		};
		add_filter( 'pre_option_admin_email', $count_admin_email );
		try {
			$notifications_two->flush();
		} finally {
			remove_filter( 'pre_option_admin_email', $count_admin_email );
		}
		self::assertSame( 0, $reads );
		self::assertCount( 2, $this->messages );
		self::assertSame( $this->entry_blog, get_current_blog_id() );

		$three         = self::factory()->blog->create();
		$this->sites[] = $three;
		$this->insert_site_key( 405, 6 * DAY_IN_SECONDS, 'Blog one throw fixture' );
		$notifications_one->expiring();
		switch_to_blog( $three );
		try {
			$thrown  = false;
			$thrower = function () use ( &$thrown ): void {
				if ( $thrown ) {
					return;
				}
				$thrown = true;
				throw new \RuntimeException( 'Fixture switch failure.' );
			};
			add_action( 'switch_blog', $thrower );
			$depth = count( $GLOBALS['_wp_switched_stack'] );
			try {
				$notifications_one->flush();
			} finally {
				remove_action( 'switch_blog', $thrower );
			}
			self::assertTrue( $thrown );
			self::assertSame( $three, get_current_blog_id() );
			self::assertSame( $depth, count( $GLOBALS['_wp_switched_stack'] ) );
		} finally {
			restore_current_blog();
		}
		self::assertCount( 2, $this->messages );
		self::assertSame( $this->entry_blog, get_current_blog_id() );
	}

	/**
	 * Insert one benign-site audit row that FR_23's write boundary would reject.
	 *
	 * @return int|false Insert result.
	 */
	private function insert_site_audit_row() {
		global $wpdb;
		return $wpdb->insert(
			$wpdb->prefix . 'xnetvn_agent_hub_wp_audit',
			array(
				'occurred_at'      => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
				'request_id'       => 'eeeeeeeeeeeeeeee',
				'key_id'           => null,
				'user_id'          => $this->creator,
				'transport'        => 'http',
				'protocol_version' => null,
				'mcp_method'       => 'tools/call',
				'ability'          => 'xnetvn/get-post',
				'outcome'          => 'success',
				'error_code'       => null,
				'duration_ms'      => 4,
				'ip'               => null,
				'ua_short'         => null,
				'arg_keys'         => null,
				'principal_type'   => 'site',
				'credential_ref'   => 'xns:bcdefghijklm',
				'blog_id'          => max( 1, (int) get_current_blog_id() ),
			)
		);
	}

	/**
	 * Collect the exported site attribution references in row order.
	 *
	 * @param array{data:array<array<string,mixed>>,done:bool} $export Export result.
	 * @return array<string|null>
	 */
	private function site_refs( array $export ): array {
		$refs = array();
		foreach ( $this->site_items( $export ) as $row ) {
			$refs[] = $this->value( $row, 'credential_ref' );
		}
		return $refs;
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
	 * @param int    $expires_in Lifetime in seconds.
	 * @param string $ability    Allowed ability.
	 * @return SiteScope
	 */
	private function site_scope( int $expires_in, string $ability = 'xnetvn/create-post' ): SiteScope {
		return new SiteScope(
			RiskTier::Write,
			array( 'content' ),
			array( $ability ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( $this->clock->now() + $expires_in ) )
		);
	}

	/**
	 * Insert one site key in the entry blog.
	 *
	 * @param int    $seed       Identifier seed.
	 * @param int    $expires_in Lifetime in seconds.
	 * @param string $name       Stored display name.
	 * @param string $ability    Allowed ability.
	 * @return SiteCredential
	 */
	private function insert_site_key( int $seed, int $expires_in, string $name, string $ability = 'xnetvn/create-post' ): SiteCredential {
		return $this->site_keys->insert( $this->key_id( $seed ), $this->site_scope( $expires_in, $ability ), $name, str_repeat( 'c', 64 ), 1, $this->creator );
	}

	/**
	 * Delete only the plugin's tables in one prefix.
	 *
	 * @param string $prefix Table prefix.
	 */
	private function delete_rows( string $prefix ): void {
		global $wpdb;
		foreach ( array( 'keys', 'audit', 'site_keys' ) as $suffix ) {
			$table = $prefix . 'xnetvn_agent_hub_wp_' . $suffix;
			if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		}
	}

	/**
	 * Remove stale site-notice markers from one prefix.
	 *
	 * @param string $prefix Table prefix.
	 */
	private function delete_site_markers( string $prefix ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $prefix . 'options', $wpdb->esc_like( 'xnetvn_agent_hub_wp_notice_site_expiry_' ) . '%' ) );
	}

	/**
	 * Build one valid human audit row.
	 *
	 * @param int $user Owner.
	 * @return array<string,mixed>
	 */
	private function event( int $user ): array {
		return array(
			'occurred_at' => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
			'request_id'  => '0123456789abcdef',
			'user_id'     => $user,
			'key_id'      => 'abcdefghijkl',
			'transport'   => 'http',
			'outcome'     => 'success',
			'arg_keys'    => array( 'id' ),
			'ip'          => '203.0.113.12',
			'ua_short'    => 'Fixture',
		);
	}

	/**
	 * Track one marker this test may create.
	 *
	 * @param string $marker Option name.
	 * @param int    $value  Option value.
	 * @return string
	 */
	private function remember_marker( string $marker, int $value ): string {
		if ( ! in_array( $marker, $this->markers, true ) ) {
			$this->markers[] = $marker;
		}
		add_option( $marker, $value, '', false );
		return $marker;
	}

	/**
	 * Track and return the marker of one expiring site key.
	 *
	 * @param SiteCredential $key Site key.
	 * @return string
	 */
	private function remember_key_marker( SiteCredential $key ): string {
		$marker = 'xnetvn_agent_hub_wp_notice_site_expiry_' . $key->key_id->value . '_' . $key->scope->expires_at->getTimestamp();
		if ( ! in_array( $marker, $this->markers, true ) ) {
			$this->markers[] = $marker;
		}
		return $marker;
	}

	/**
	 * Collect exported site attribution rows.
	 *
	 * @param array{data:array<array<string,mixed>>,done:bool} $export Export result.
	 * @return array<array<string,mixed>>
	 */
	private function site_items( array $export ): array {
		return array_values(
			array_filter(
				$export['data'],
				fn( array $item ): bool => 'xnetvn-agent-hub-wp-keys' === $item['group_id'] && 'issued_site_key' === $this->value( $item, 'role' )
			)
		);
	}

	/**
	 * Read one exported field value.
	 *
	 * @param array<string,mixed> $item Exported item.
	 * @param string              $name Field name.
	 * @return string|null
	 */
	private function value( array $item, string $name ): ?string {
		foreach ( $item['data'] as $field ) {
			if ( is_array( $field ) && isset( $field['name'], $field['value'] ) && (string) $field['name'] === $name ) {
				return (string) $field['value'];
			}
		}
		return null;
	}

	/**
	 * List the site rows still matching the creator on the current blog.
	 *
	 * @return array<int,string>
	 */
	private function matching_ids(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE blog_id = %d AND (created_by = %d OR revoked_by = %d) ORDER BY id ASC', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', max( 1, (int) get_current_blog_id() ), $this->creator, $this->creator ) );
		return is_array( $ids ) ? $ids : array();
	}
}
