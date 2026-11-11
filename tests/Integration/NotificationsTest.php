<?php
/**
 * Notification content, opt-out and expiry deduplication tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Notifications;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** The test mail sink intercepts every message before any delivery attempt. */
final class NotificationsTest extends WP_UnitTestCase {
	/** Captured safe mail fixture.
	 *
	 * @var array<array<string,mixed>>
	 */
	private array $messages = array();
	/** Clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Keys.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/** Owner.
	 *
	 * @var int
	 */
	private int $owner;

	/** Create plugin-owned fixtures and attach the no-delivery capture callback. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock = new FrozenClock( time() );
		( new Migrator( $wpdb, $this->clock ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$this->keys  = new WpdbKeyRepository( $wpdb, $this->clock );
		$this->owner = self::factory()->user->create( array( 'user_email' => 'mail-owner@example.test' ) );
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
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

	/** Creation messages are deferred outside the mutation lock and contain no credential. */
	public function test_creation_queue_and_owner_opt_out(): void {
		$key           = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Safe fixture', new Scope( RiskTier::Read ), str_repeat( 'c', 64 ), 1 );
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$notifications->created( $key );
		self::assertCount( 0, $this->messages );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		self::assertSame( 'mail-owner@example.test', $this->messages[0]['to'] );
		self::assertStringNotContainsString( str_repeat( 'c', 64 ), $this->messages[0]['message'] );
		self::assertStringNotContainsString( 'xnv_', $this->messages[0]['message'] );
		update_user_meta( $this->owner, 'xnetvn_agent_hub_wp_notifications', '0' );
		$notifications->created( $key );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
	}

	/** The registered lifecycle event queues a safe notice, with delivery still deferred. */
	public function test_registered_creation_event_is_deferred(): void {
		$key           = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Lifecycle fixture', new Scope( RiskTier::Read ), str_repeat( 'c', 64 ), 1 );
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$notifications->register();
		try {
			do_action( 'xnetvn_agent_hub_wp_key_created', $key );
			self::assertCount( 0, $this->messages );
			$notifications->flush();
			self::assertCount( 1, $this->messages );
			self::assertStringContainsString( 'Lifecycle fixture', $this->messages[0]['message'] );
			self::assertStringNotContainsString( str_repeat( 'c', 64 ), $this->messages[0]['message'] );
		} finally {
			remove_action( 'xnetvn_agent_hub_wp_key_created', array( $notifications, 'created' ) );
			remove_action( 'xnetvn_agent_hub_wp_key_revoked', array( $notifications, 'revoked' ) );
			remove_action( 'xnetvn_agent_hub_wp_key_authenticated', array( $notifications, 'authenticated' ) );
			remove_action( 'init', array( $notifications, 'schedule' ) );
			remove_action( Notifications::HOOK, array( $notifications, 'expiring' ) );
			remove_action( Notifications::BATCH_HOOK, array( $notifications, 'expiring' ) );
			remove_action( 'shutdown', array( $notifications, 'flush' ), 150 );
		}
	}

	/** Only administrator revocation sends the documented revocation notification. */
	public function test_revocation_and_site_opt_out(): void {
		$key           = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Safe fixture', new Scope( RiskTier::Read ), str_repeat( 'c', 64 ), 1 );
		$settings      = new MemorySettings();
		$notifications = new Notifications( $this->keys, $settings, $this->clock );
		$notifications->revoked( $key, $this->owner, 'owner_request' );
		$notifications->flush();
		self::assertCount( 0, $this->messages );
		$notifications->revoked( $key, $this->owner + 1, 'administrator_request' );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		$settings->update( array( 'notify_key_revoked' => false ) );
		$notifications->revoked( $key, $this->owner + 1, 'administrator_request' );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
	}

	/** The indexed seven-day expiry range excludes distant keys and is notified once. */
	public function test_changed_network_is_notified_at_most_once_daily(): void {
		$stored        = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Network fixture', new Scope( RiskTier::Read ), str_repeat( 'c', 64 ), 1 );
		$key           = new \XNetVN\AgentHubWP\Domain\ApiKey( $stored->id, $stored->key_id, $stored->user_id, $stored->name, $stored->scope, $stored->created_at, null, null, '203.0.113.0' );
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$notifications->authenticated( $key, '203.0.113.0' );
		$notifications->flush();
		self::assertCount( 0, $this->messages );
		$notifications->authenticated( $key, '198.51.100.0' );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		$notifications->authenticated( $key, '198.51.100.0' );
		$notifications->flush();
		self::assertCount( 1, $this->messages );
	}

	/** The indexed seven-day expiry range excludes distant keys and is notified once. */
	public function test_expiry_deduplication_and_scheduling(): void {
		$soon  = new Scope( RiskTier::Read, array(), array(), array(), array(), new \DateTimeImmutable( '@' . ( $this->clock->now() + 6 * DAY_IN_SECONDS ) ) );
		$later = new Scope( RiskTier::Read, array(), array(), array(), array(), new \DateTimeImmutable( '@' . ( $this->clock->now() + 30 * DAY_IN_SECONDS ) ) );
		$this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Soon', $soon, str_repeat( 'c', 64 ), 1 );
		$this->keys->insert( new KeyId( 'bcdefghijklm' ), $this->owner, 'Later', $later, str_repeat( 'd', 64 ), 1 );
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$notifications->schedule();
		self::assertNotFalse( wp_next_scheduled( Notifications::HOOK ) );
		$notifications->expiring();
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		self::assertStringContainsString( 'Soon', $this->messages[0]['message'] );
		$notifications->expiring();
		$notifications->flush();
		self::assertCount( 1, $this->messages );
		wp_clear_scheduled_hook( Notifications::HOOK );
	}

	/** An unsent expiry notice releases its marker so a later successful attempt may retry. */
	public function test_failed_delivery_releases_expiry_marker(): void {
		$expiry        = $this->clock->now() + 6 * DAY_IN_SECONDS;
		$key           = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Retry fixture', new Scope( RiskTier::Read, array(), array(), array(), array(), new \DateTimeImmutable( '@' . $expiry ) ), str_repeat( 'c', 64 ), 1 );
		$marker        = 'xnetvn_agent_hub_wp_notice_expiry_' . $key->key_id->value . '_' . $expiry;
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$reject        = static fn(): bool => false;
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		add_filter( 'pre_wp_mail', $reject, PHP_INT_MAX );
		try {
			$notifications->expiring();
			self::assertNotFalse( get_option( $marker ) );
			$notifications->flush();
			self::assertFalse( get_option( $marker ) );
			self::assertCount( 1, $this->messages );
		} finally {
			remove_filter( 'pre_wp_mail', $reject, PHP_INT_MAX );
			add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
		}
		$notifications->expiring();
		$notifications->flush();
		self::assertNotFalse( get_option( $marker ) );
		self::assertCount( 2, $this->messages );
	}

	/** Deleting a queued key suppresses delivery and releases its marker without touching others. */
	public function test_disappearing_key_is_not_notified(): void {
		global $wpdb;
		$expiry        = $this->clock->now() + 6 * DAY_IN_SECONDS;
		$key           = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Removed fixture', new Scope( RiskTier::Read, array(), array(), array(), array(), new \DateTimeImmutable( '@' . $expiry ) ), str_repeat( 'c', 64 ), 1 );
		$marker        = 'xnetvn_agent_hub_wp_notice_expiry_' . $key->key_id->value . '_' . $expiry;
		$notifications = new Notifications( $this->keys, new MemorySettings(), $this->clock );
		$notifications->expiring();
		self::assertNotFalse( get_option( $marker ) );
		self::assertSame( 1, $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND user_id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $key->id, $this->owner ) ) );
		$notifications->flush();
		self::assertCount( 0, $this->messages );
		self::assertFalse( get_option( $marker ) );
	}
}
