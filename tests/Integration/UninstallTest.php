<?php
/**
 * Real uninstall, deactivation and unavailable-schema behavior in the isolated test database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;
use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteAuthenticator;
use XNetVN\AgentHubWP\Auth\SiteKeyService;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Persistence\DeactivationProvider;
use XNetVN\AgentHubWP\Persistence\MigrationProvider;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\Schema;
use XNetVN\AgentHubWP\Persistence\SiteKeyLock;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Includes the actual PHP 7.2-safe entry point with its core-only guard. */
final class UninstallTest extends WP_UnitTestCase {
	/** Deterministic clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;

	/** Install the schema and start every method from a clean default state. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( time() );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		// The isolated database keeps rows across tests; remove only this suite's plugin-owned fixtures.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		delete_option( WpSiteSettings::OPTION );
		delete_option( WpdbSiteKeyRepository::ROTATION_OPTION );
	}

	/** Restore real plugin tables even if an assertion fails after uninstall. */
	public function tear_down(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		( new Migrator( $wpdb, new FrozenClock( time() ) ) )->migrate();
		parent::tear_down();
	}
	/** A deliberate preservation choice keeps data while all cron is removed. */
	public function test_preservation_keeps_tables_and_options(): void {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_option( WpSiteSettings::OPTION, array( 'delete_data_on_uninstall' => false ), false );
		add_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture', '1', '', false );
		( new WpdbSiteKeyRepository( $wpdb, $this->clock, max( 1, (int) get_current_blog_id() ) ) )->insert( new KeyId( 'abcdefghijkl' ), $this->scope(), 'Keep-data fixture', str_repeat( 'a', 64 ), 1, $user );
		( new WpdbAuditRepository( $wpdb ) )->insert_batch(
			array(
				array(
					'occurred_at'    => '2026-01-01 00:00:00',
					'request_id'     => '0123456789abcdef',
					'transport'      => 'http',
					'outcome'        => 'success',
					'arg_keys'       => array(),
					'credential_ref' => 'xns:abcdefghijkl',
					'principal_type' => 'site',
					'key_id'         => null,
					'user_id'        => null,
					'blog_id'        => max( 1, (int) get_current_blog_id() ),
				),
			)
		);
		update_option( WpdbSiteKeyRepository::ROTATION_OPTION, '{"state":"prepared"}', false );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_purge_audit' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_purge_audit_backlog' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) );
		$this->run_uninstall();
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_purge_audit' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_purge_audit_backlog' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) ) );
		self::assertSame( '1', get_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture' ) );
		self::assertSame( '{"state":"prepared"}', get_option( WpdbSiteKeyRepository::ROTATION_OPTION ) );
		foreach ( array( 'keys', 'audit', 'site_keys' ) as $suffix ) {
			self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) ) ) );
		}
		self::assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) ) );
		self::assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE credential_ref = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 'xns:abcdefghijkl' ) ) );
	}

	/** Default deletion affects only exact plugin-owned names and tables. */
	public function test_default_deletion_is_owned_and_complete(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		update_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', '0' );
		delete_option( WpSiteSettings::OPTION );
		update_option( 'xnetvn_agent_hub_wp_pepper', 'synthetic', false );
		update_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture', '1', false );
		update_option( 'unrelated_xnetvn_agent_hub_wp_fixture', 'keep', false );
		update_option( 'xnetvnXagentXhubXwpXfixture', 'keep', false );
		wp_schedule_single_event( time() + 60, Retention::CONTINUE_HOOK );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) );
		( new WpdbSiteKeyRepository( $wpdb, $this->clock, max( 1, (int) get_current_blog_id() ) ) )->insert( new KeyId( 'abcdefghijkl' ), $this->scope(), 'Delete-data fixture', str_repeat( 'c', 64 ), 1, $user );
		( new WpdbAuditRepository( $wpdb ) )->insert_batch(
			array(
				array(
					'occurred_at'    => '2026-01-01 00:00:00',
					'request_id'     => '0123456789abcdef',
					'transport'      => 'http',
					'outcome'        => 'success',
					'arg_keys'       => array(),
					'credential_ref' => 'xns:abcdefghijkl',
					'principal_type' => 'site',
					'key_id'         => null,
					'user_id'        => null,
					'blog_id'        => max( 1, (int) get_current_blog_id() ),
				),
			)
		);
		update_option( WpdbSiteKeyRepository::ROTATION_OPTION, '{"state":"prepared"}', false );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $admin, BanSnapshot::KEY, 'synthetic-snapshot' );
		$unrelated_table = $wpdb->prefix . 'fr25_unrelated';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id int unsigned NOT NULL )', $unrelated_table ) );
		$this->expectOutputString( '' );
		$this->run_uninstall();
		self::assertSame( '', get_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', true ) );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_pepper' ) );
		self::assertFalse( get_option( Migrator::VERSION_OPTION ) );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_rl_0000000001_fixture' ) );
		self::assertSame( 'keep', get_option( 'unrelated_xnetvn_agent_hub_wp_fixture' ) );
		self::assertSame( 'keep', get_option( 'xnetvnXagentXhubXwpXfixture' ) );
		self::assertFalse( wp_next_scheduled( Retention::CONTINUE_HOOK ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) ) );
		try {
			foreach ( array( 'keys', 'audit', 'site_keys' ) as $suffix ) {
				self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) ) ) );
			}
			self::assertFalse( get_option( WpdbSiteKeyRepository::ROTATION_OPTION ) );
			self::assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $wpdb->options, '%' . $wpdb->esc_like( str_repeat( 'c', 64 ) ) . '%' ) ) );
			self::assertNotNull( get_userdata( $admin ) );
			$admin_user = get_userdata( $admin );
			self::assertInstanceOf( \WP_User::class, $admin_user );
			self::assertSame( array( 'administrator' ), $admin_user->roles );
			self::assertSame( 'synthetic-snapshot', get_user_meta( $admin, BanSnapshot::KEY, true ) );
			self::assertSame( 'keep', get_option( 'unrelated_xnetvn_agent_hub_wp_fixture' ) );
			self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $unrelated_table ) ) ) );
			self::assertTrue( xnetvn_agent_hub_wp_uninstall_site() );
			foreach ( array( 'keys', 'audit', 'site_keys' ) as $suffix ) {
				self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) ) ) );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $unrelated_table ) );
		}
		( new Migrator( $wpdb, new FrozenClock( time() ) ) )->migrate();
	}

	/** Deactivation clears owned cron and keeps site credentials. */
	public function test_deactivation_clears_owned_cron_and_keeps_site_credentials(): void {
		global $wpdb;
		$user  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys';
		( new WpdbSiteKeyRepository( $wpdb, $this->clock, max( 1, (int) get_current_blog_id() ) ) )->insert( new KeyId( 'abcdefghijkl' ), $this->scope(), 'Deactivation fixture', str_repeat( 'a', 64 ), 1, $user );
		update_option( WpdbSiteKeyRepository::ROTATION_OPTION, '{"state":"prepared"}', false );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_purge_audit' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_purge_audit_backlog' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys' );
		wp_schedule_single_event( time() + 60, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) );
		$site_keys_before = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$audit_before     = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		( new DeactivationProvider( XNETVN_AGENT_HUB_WP_FILE ) )->unschedule( false );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_purge_audit' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_purge_audit_backlog' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys' ) );
		self::assertFalse( wp_next_scheduled( 'xnetvn_agent_hub_wp_notify_expiring_keys_batch', array( 2 ) ) );
		self::assertSame( '{"state":"prepared"}', get_option( WpdbSiteKeyRepository::ROTATION_OPTION ) );
		self::assertSame( $site_keys_before, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
		self::assertSame( $audit_before, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) ) );
	}

	/** Missing site-key storage fails closed and the next migration recreates it. */
	public function test_site_paths_fail_closed_when_the_site_key_table_is_absent(): void {
		global $wpdb;
		$settings = new MemorySettings( array( 'allowed_origins' => array() ) );
		$limits   = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $this->clock );
		$peppers  = new PepperStore();
		$repo     = new WpdbSiteKeyRepository( $wpdb, $this->clock, max( 1, (int) get_current_blog_id() ) );
		$auth     = new SiteAuthenticator( $repo, $peppers, $settings, $limits, new TransportGuard( $settings ), $this->clock );
		$table    = $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys';
		$audit    = $wpdb->prefix . 'xnetvn_agent_hub_wp_audit';
		$before   = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $audit ) );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$failed = $auth->authenticate( 'xns_aaaaaaaaaaaa_' . str_repeat( 'A', 43 ), '203.0.113.7', null, true );
			self::assertInstanceOf( \WP_Error::class, $failed );
			self::assertSame( 'xnetvn_agent_hub_wp_unavailable', $failed->get_error_code() );
			$failed_data = $failed->get_error_data();
			self::assertIsArray( $failed_data );
			self::assertSame( 503, $failed_data['status'] );
			$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $admin );
			$service = new SiteKeyService( $repo, new SiteKeyLock( $wpdb ), new SiteTokenCodec(), $peppers, $settings, $this->createMock( AbilityCatalog::class ), $limits, $this->clock );
			$listed  = $service->list();
			self::assertInstanceOf( \WP_Error::class, $listed );
			self::assertSame( 'xnetvn_agent_hub_wp_unavailable', $listed->get_error_code() );
			$listed_data = $listed->get_error_data();
			self::assertIsArray( $listed_data );
			self::assertSame( 503, $listed_data['status'] );
			self::assertSame( $before, $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $audit ) ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		delete_option( Migrator::VERSION_OPTION );
		$wpdb->last_error = '';
		( new MigrationProvider( XNETVN_AGENT_HUB_WP_FILE, new Migrator( $wpdb, $this->clock ) ) )->ensure_schema();
		self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
		self::assertSame( (string) Schema::VERSION, (string) get_option( Migrator::VERSION_OPTION ) );
	}

	/** Network uninstall respects each site's delete-data choice and leaves no orphaned reference. */
	public function test_network_uninstall_respects_each_site_choice(): void {
		$this->skipWithoutMultisite();
		global $wpdb;
		$main = max( 1, (int) get_current_blog_id() );
		update_option( WpSiteSettings::OPTION, array( 'delete_data_on_uninstall' => false ), false );
		$first  = self::factory()->blog->create();
		$second = self::factory()->blog->create();
		$hooks  = array( 'xnetvn_agent_hub_wp_purge_audit', 'xnetvn_agent_hub_wp_purge_audit_backlog', 'xnetvn_agent_hub_wp_notify_expiring_keys', 'xnetvn_agent_hub_wp_notify_expiring_keys_batch' );
		try {
			foreach ( array( $main, $first, $second ) as $blog_id ) {
				switch_to_blog( $blog_id );
				if ( $blog_id !== $main ) {
					( new Migrator( $wpdb, $this->clock ) )->migrate();
					$user = self::factory()->user->create( array( 'role' => 'editor' ) );
					( new WpdbSiteKeyRepository( $wpdb, $this->clock, $blog_id ) )->insert( new KeyId( 'abcdefghijkl' ), $this->scope(), 'Network fixture', str_repeat( 'a', 64 ), 1, $user );
					( new WpdbAuditRepository( $wpdb ) )->insert_batch(
						array(
							array(
								'occurred_at'    => '2026-01-01 00:00:00',
								'request_id'     => '0123456789abcdef',
								'transport'      => 'http',
								'outcome'        => 'success',
								'arg_keys'       => array(),
								'credential_ref' => 'xns:abcdefghijkl',
								'principal_type' => 'site',
								'key_id'         => null,
								'user_id'        => null,
								'blog_id'        => $blog_id,
							),
						)
					);
				}
				foreach ( $hooks as $hook ) {
					wp_schedule_single_event( time() + 60, $hook, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch' === $hook ? array( 2 ) : array() );
				}
				restore_current_blog();
			}
			switch_to_blog( $first );
			update_option( WpSiteSettings::OPTION, array( 'delete_data_on_uninstall' => false ), false );
			restore_current_blog();
			$this->run_uninstall();
			foreach ( array( $main, $first ) as $blog_id ) {
				switch_to_blog( $blog_id );
				foreach ( array( 'site_keys', 'audit' ) as $suffix ) {
					self::assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) ) ) );
				}
				foreach ( $hooks as $hook ) {
					self::assertFalse( wp_next_scheduled( $hook, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch' === $hook ? array( 2 ) : array() ) );
				}
				restore_current_blog();
			}
			switch_to_blog( $first );
			self::assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i a JOIN %i s ON s.key_id = SUBSTR(a.credential_ref, 5) WHERE a.credential_ref = %s', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys', 'xns:abcdefghijkl' ) ) );
			restore_current_blog();
			switch_to_blog( $second );
			foreach ( array( 'site_keys', 'audit', 'keys' ) as $suffix ) {
				self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'xnetvn_agent_hub_wp_' . $suffix ) ) ) );
			}
			foreach ( $hooks as $hook ) {
				self::assertFalse( wp_next_scheduled( $hook, 'xnetvn_agent_hub_wp_notify_expiring_keys_batch' === $hook ? array( 2 ) : array() ) );
			}
			restore_current_blog();
		} finally {
			if ( get_current_blog_id() !== $main ) {
				restore_current_blog();
			}
			switch_to_blog( $main );
			delete_option( WpSiteSettings::OPTION );
			( new Migrator( $wpdb, $this->clock ) )->migrate();
			restore_current_blog();
		}
	}

	/** Build a bounded editorial scope. */
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

	/** Run only with the WordPress uninstall guard in the test environment. */
	private function run_uninstall(): void {
		// Core's test harness rewrites DROP into DROP TEMPORARY; this test must exercise the real plugin tables in the isolated test database.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core uninstall guard for the actual entry point test.
			define( 'WP_UNINSTALL_PLUGIN', 'xnetvn-agent-hub-wp/xnetvn-agent-hub-wp.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
