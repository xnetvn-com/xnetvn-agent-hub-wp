<?php
/**
 * Safe audit metadata tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Audit\AuditEvent;

/** Keeps untrusted arguments and credentials out of persistence. */
final class AuditEventTest extends TestCase {

	/** Argument names are bounded identifiers; no nested data or values survive. */
	public function test_metadata_is_bounded_and_contains_no_argument_values(): void {
		$event = new AuditEvent( '2026-10-05 00:00:00', '0123456789abcdef', 'http', 'success' );
		$row   = $event->row( array( 'title', 'Authorization', 'id', 'title', 'xnv_abcdefghijkl_CANARY', 'nested.secret', str_repeat( 'z', 101 ) ), '203.0.113.79', str_repeat( 'agent', 50 ), true );
		self::assertSame( array( 'id', 'title' ), $row['arg_keys'] );
		self::assertSame( '203.0.113.0', $row['ip'] );
		self::assertSame( 120, strlen( $row['ua_short'] ) );
		self::assertStringNotContainsString( 'CANARY', json_encode( $row, JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The domain-only unit test does not bootstrap WordPress.
	}

	/** IPv6 masking clears all bits after the first 48; invalid peers are absent. */
	public function test_ip_anonymization(): void {
		$event = new AuditEvent( '2026-10-05 00:00:00', '0123456789abcdef', 'http', 'auth_failed' );
		self::assertSame( '2001:db8:abcd::', $event->row( array(), '2001:db8:abcd:1234::8', '', true )['ip'] );
		self::assertNull( $event->row( array(), 'invalid', '', true )['ip'] );
		self::assertSame( '203.0.113.79', $event->row( array(), '203.0.113.79', '', false )['ip'] );
	}

	/** Bearer-shaped credential fragments cannot survive user-agent metadata. */
	public function test_user_agent_redacts_credentials(): void {
		$event = new AuditEvent( '2026-10-05 00:00:00', '0123456789abcdef', 'http', 'auth_failed' );
		$row   = $event->row( array(), null, 'Client xnv_abcdefghijkl_' . str_repeat( 'C', 43 ) . ' Bearer CANARY', false );
		self::assertStringNotContainsString( 'CANARY', $row['ua_short'] );
		self::assertStringNotContainsString( str_repeat( 'C', 43 ), $row['ua_short'] );
	}

	/** Unknown outcomes cannot bypass the fixed event vocabulary. */
	public function test_invalid_event_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AuditEvent( '2026-10-05 00:00:00', '0123456789abcdef', 'http', 'CANARY' );
	}
}
