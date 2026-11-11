<?php
/**
 * Audit buffering, retention and privacy integration tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Audit\AuditLogger;
use XNetVN\AgentHubWP\Audit\PrivacyExporter;
use XNetVN\AgentHubWP\Audit\PrivacyEraser;
use XNetVN\AgentHubWP\Audit\Retention;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpPrivacyStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Tests actual database boundaries, not only hook registration. */
final class AuditPrivacyTest extends WP_UnitTestCase {
	/** Clock used for retention and event timestamps.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Persistence under test.
	 *
	 * @var WpdbAuditRepository
	 */
	private WpdbAuditRepository $audit;

	/** Empty only plugin tables in the isolated WordPress test database. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( 1791158400 );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		$this->audit = new WpdbAuditRepository( $wpdb );
	}

	/** Flush writes one batch once, with no argument values or authorization. */
	public function test_audit_buffer_flushes_once(): void {
		global $wpdb;
		$logger = new AuditLogger( $this->audit, new RequestScope(), new MemorySettings(), $this->clock );
		$logger->started( 'xnetvn/get-site-info', array( 'title', 'id' ) );
		$logger->finished( 'xnetvn/get-site-info', 'success' );
		$logger->auth_failed( 'bad_secret', '203.0.113.12' );
		self::assertSame( 0, $this->audit->query()['total'] );
		$before = $wpdb->num_queries;
		$logger->flush();
		self::assertSame( 1, $wpdb->num_queries - $before );
		$logger->flush();
		self::assertSame( 1, $wpdb->num_queries - $before );
		$rows = $this->audit->query()['items'];
		self::assertCount( 2, $rows );
		self::assertStringNotContainsString( 'Authorization', wp_json_encode( $rows ) );
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT arg_keys FROM %i ORDER BY id ASC LIMIT %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 1 ), ARRAY_A );
		self::assertNotNull( $stored );
		self::assertSame( '["id","title"]', $stored['arg_keys'] );
	}

	/** Daily retention purges old rows while preserving the exact cutoff. */
	public function test_protocol_validation_errors_are_audited_without_values(): void {
		$logger  = new AuditLogger( $this->audit, new RequestScope(), new MemorySettings(), $this->clock );
		$request = new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'CANARY-tool',
						'arguments' => array( 'id' => 'CANARY-VALUE' ),
					),
				)
			)
		);
		$response = new \WP_REST_Response(
			(object) array(
				'error' => (object) array(
					'code'    => -32602,
					'message' => 'CANARY-response',
				),
			)
		);
		self::assertSame( $response, $logger->observe( $response, rest_get_server(), $request ) );
		$logger->flush();
		$rows = $this->audit->query()['items'];
		self::assertCount( 1, $rows );
		self::assertSame( 'invalid_input', $rows[0]['outcome'] );
		global $wpdb;
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT arg_keys FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ), ARRAY_A );
		self::assertNotNull( $stored );
		self::assertSame( '["id"]', $stored['arg_keys'] );
		self::assertNull( $rows[0]['ability'] );
		self::assertStringNotContainsString( 'CANARY', wp_json_encode( $rows ) );
	}

	/** Daily retention purges old rows while preserving the exact cutoff. */
	public function test_retention_schedules_and_preserves_recent_data(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->clock->now() - 90 * DAY_IN_SECONDS );
		$this->audit->insert_batch( array( $this->event( 0, '2026-01-01 00:00:00' ), $this->event( 0, $cutoff ) ) );
		global $wpdb;
		add_option( 'xnetvn_agent_hub_wp_notice_expiry_abcdefghijkl_123', $this->clock->now() - 1, '', false );
		add_option( 'xnetvn_agent_hub_wp_notice_expiry_bcdefghijklm_456', $this->clock->now() + 1, '', false );
		$retention = new Retention( $this->audit, new MemorySettings(), $this->clock, new \XNetVN\AgentHubWP\RateLimit\WpBucketStore( $wpdb ) );
		$retention->schedule();
		$retention->schedule();
		self::assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$retention->purge();
		self::assertSame( 1, $this->audit->query()['total'] );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_notice_expiry_abcdefghijkl_123' ) );
		self::assertNotFalse( get_option( 'xnetvn_agent_hub_wp_notice_expiry_bcdefghijklm_456' ) );
		wp_clear_scheduled_hook( Retention::HOOK );
	}

	/** Export never includes credential hashes and never crosses ownership. */
	public function test_privacy_export_is_scoped_and_has_no_secrets(): void {
		global $wpdb;
		$user  = self::factory()->user->create( array( 'user_email' => 'owner@example.test' ) );
		$other = self::factory()->user->create();
		$keys  = new WpdbKeyRepository( $wpdb, $this->clock );
		$keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Owner fixture', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$keys->insert( new KeyId( 'bcdefghijklm' ), $other, 'Other CANARY', new Scope( RiskTier::Read ), str_repeat( 'b', 64 ), 1 );
		$this->audit->insert_batch( array( $this->event( $user ), $this->event( $other ) ) );
		update_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', '0' );
		$export = ( new PrivacyExporter( new WpPrivacyStore( $wpdb ) ) )->export( 'owner@example.test', 1 );
		self::assertTrue( $export['done'] );
		self::assertCount( 3, $export['data'] );
		$json = wp_json_encode( $export );
		self::assertStringNotContainsString( 'CANARY', $json );
		self::assertStringNotContainsString( str_repeat( 'a', 64 ), $json );
		self::assertStringNotContainsString( 'secret_hash', $json );
		self::assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			( new PrivacyExporter( new WpPrivacyStore( $wpdb ) ) )->export( 'missing@example.test', 1 )
		);
	}

	/** Erasure repeatedly consumes the first batch, preventing offset skips. */
	public function test_erasure_is_bounded_and_anonymizes_audit(): void {
		global $wpdb;
		$user  = self::factory()->user->create( array( 'user_email' => 'erase@example.test' ) );
		$other = self::factory()->user->create();
		$keys  = new WpdbKeyRepository( $wpdb, $this->clock );
		$keys->insert( new KeyId( 'abcdefghijkl' ), $user, 'Erase', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$keys->insert( new KeyId( 'bcdefghijklm' ), $other, 'Keep', new Scope( RiskTier::Read ), str_repeat( 'b', 64 ), 1 );
		update_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', '0' );
		add_option( 'xnetvn_agent_hub_wp_notice_expiry_abcdefghijkl_1791158400', 1791244800, '', false );
		add_option( 'xnetvn_agent_hub_wp_notice_ip_bcdefghijklm_20261005', 1791244800, '', false );
		$this->audit->insert_batch( array( $this->event( $user ), $this->event( $user ), $this->event( $other ) ) );
		$store = new WpPrivacyStore( $wpdb );
		$first = $store->erase_for_user( $user, 1 );
		self::assertFalse( $first['done'] );
		$second = $store->erase_for_user( $user, 1 );
		self::assertTrue( $second['done'] );
		self::assertCount( 0, $keys->list_for_user( $user ) );
		self::assertCount( 1, $keys->list_for_user( $other ) );
		self::assertSame( '', get_user_meta( $user, 'xnetvn_agent_hub_wp_notifications', true ) );
		self::assertFalse( get_option( 'xnetvn_agent_hub_wp_notice_expiry_abcdefghijkl_1791158400' ) );
		self::assertNotFalse( get_option( 'xnetvn_agent_hub_wp_notice_ip_bcdefghijklm_20261005' ) );
		self::assertSame( 1, $this->audit->query( array( 'user_id' => $other ) )['total'] );
		$rows = $this->audit->query()['items'];
		self::assertSame( 3, count( $rows ) );
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT key_id,ip,ua_short,arg_keys FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit', 1, 1 ), ARRAY_A );
		self::assertNotNull( $stored );
		self::assertNull( $stored['key_id'] );
		self::assertNull( $stored['ip'] );
		self::assertNull( $stored['ua_short'] );
		self::assertNull( $stored['arg_keys'] );
		self::assertTrue( ( new PrivacyEraser( $store ) )->erase( 'erase@example.test', 9 )['done'] );
	}

	/** Listing carries only the five columns the activity UI renders; storage-only columns never reach the response. */
	public function test_query_items_expose_only_the_ui_whitelist(): void {
		self::assertSame( array(), $this->audit->query()['items'] );
		$this->audit->insert_batch( array( $this->event( 0 ) ) );
		$rows = $this->audit->query()['items'];
		self::assertCount( 1, $rows );
		self::assertSame(
			array( 'occurred_at', 'user_id', 'ability', 'outcome', 'transport' ),
			array_keys( $rows[0] )
		);
		foreach ( array( 'id', 'request_id', 'key_id', 'protocol_version', 'mcp_method', 'error_code', 'duration_ms', 'ip', 'ua_short', 'arg_keys' ) as $column ) {
			self::assertArrayNotHasKey( $column, $rows[0] );
		}
	}

	/** Owner and outcome filters combine with AND without widening the whitelist. */
	public function test_query_filters_by_owner_and_outcome_with_and(): void {
		$owner                = self::factory()->user->create();
		$other                = self::factory()->user->create();
		$first                = $this->event( $owner );
		$first['request_id']  = 'aaaaaaaaaaaaaaaa';
		$first['outcome']     = 'success';
		$second               = $this->event( $other );
		$second['request_id'] = 'bbbbbbbbbbbbbbbb';
		$second['outcome']    = 'denied';
		$this->audit->insert_batch( array( $first, $second ) );
		$scoped = $this->audit->query( array( 'user_id' => $owner ) );
		self::assertSame( 1, $scoped['total'] );
		self::assertCount( 1, $scoped['items'] );
		self::assertSame( $owner, (int) $scoped['items'][0]['user_id'] );
		self::assertSame(
			array( 'occurred_at', 'user_id', 'ability', 'outcome', 'transport' ),
			array_keys( $scoped['items'][0] )
		);
		$combined = $this->audit->query(
			array(
				'user_id' => $owner,
				'outcome' => 'denied',
			)
		);
		self::assertSame( 0, $combined['total'] );
		$denied = $this->audit->query( array( 'outcome' => 'denied' ) );
		self::assertSame( 1, $denied['total'] );
		self::assertSame( $other, (int) $denied['items'][0]['user_id'] );
		$json = wp_json_encode( $scoped );
		self::assertIsString( $json );
		self::assertStringNotContainsString( 'arg_keys', $json );
		self::assertStringNotContainsString( '203.0.113', $json );
	}

	/**
	 * Create whitelisted fixture metadata.
	 *
	 * @param int    $user Owner.
	 * @param string $time UTC timestamp.
	 * @return array<string,mixed>
	 */
	private function event( int $user, string $time = '2026-10-05 00:00:00' ): array {
		return array(
			'occurred_at' => $time,
			'request_id'  => '0123456789abcdef',
			'user_id'     => $user > 0 ? $user : null,
			'key_id'      => $user > 0 ? 'abcdefghijkl' : null,
			'transport'   => 'http',
			'outcome'     => 'success',
			'arg_keys'    => array( 'id' ),
			'ip'          => '203.0.113.12',
			'ua_short'    => 'Fixture',
		);
	}

	/** Erasure removes a revoker's identity without deleting another owner's credential. */
	public function test_erasure_anonymizes_revoker_on_another_owners_key(): void {
		global $wpdb;
		$actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$keys  = new WpdbKeyRepository( $wpdb, $this->clock );
		$key   = $keys->insert( new KeyId( 'abcdefghijkl' ), $owner, 'Other owner', new Scope( RiskTier::Read ), str_repeat( 'a', 64 ), 1 );
		$keys->revoke( $key->id, $actor, 'administrator_request' );
		$store = new WpPrivacyStore( $wpdb );
		self::assertTrue( $store->erase_for_user( $actor, 1 )['done'] );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id,revoked_by,revoked_at FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $key->id ), ARRAY_A );
		self::assertNotNull( $row );
		self::assertSame( $owner, (int) $row['user_id'] );
		self::assertNull( $row['revoked_by'] );
		self::assertNotNull( $row['revoked_at'] );
	}
}
