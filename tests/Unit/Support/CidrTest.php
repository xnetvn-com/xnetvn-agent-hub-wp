<?php
/**
 * Exact IP and subnet boundary tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Support\Cidr;

/** Tests bit-accurate IPv4/IPv6 subnet matching. */
final class CidrTest extends TestCase {

	/** Non-byte-aligned prefixes and zero/full prefix lengths are correct. */
	public function test_contains(): void {
		self::assertTrue( Cidr::contains( '203.0.113.128/25', '203.0.113.255' ) );
		self::assertFalse( Cidr::contains( '203.0.113.128/25', '203.0.113.127' ) );
		self::assertTrue( Cidr::contains( '0.0.0.0/0', '203.0.113.1' ) );
		self::assertFalse( Cidr::contains( '0.0.0.0/0', '::1' ) );
		self::assertTrue( Cidr::contains( '::1/128', '::1' ) );
		self::assertFalse( Cidr::contains( '::1/128', '::2' ) );
		self::assertTrue( Cidr::contains( '2001:db8::/33', '2001:db8:7fff::1' ) );
		self::assertFalse( Cidr::contains( '2001:db8::/33', '2001:db8:8000::1' ) );
		self::assertFalse( Cidr::contains( '203.0.113.0/24', 'malformed' ) );
	}

	/** Adjacent subnets may combine without widening their union. */
	public function test_union_coverage_and_holes(): void {
		self::assertTrue( Cidr::covered_by( '203.0.113.0/24', array( '203.0.113.128/25', '203.0.113.0/25' ) ) );
		self::assertFalse( Cidr::covered_by( '203.0.113.0/24', array( '203.0.113.0/26', '203.0.113.128/25' ) ) );
		self::assertFalse( Cidr::covered_by( '203.0.113.0/24', array( '192.0.2.0/24' ) ) );
		self::assertTrue( Cidr::covered_by( '2001:db8::/32', array( '2001:db8::/33', '2001:db8:8000::/33' ) ) );
		self::assertFalse( Cidr::covered_by( 'invalid', array( '0.0.0.0/0' ) ) );
	}
}
