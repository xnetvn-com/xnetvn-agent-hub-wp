<?php
/**
 * Independent SSRF address boundaries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Support\Http\PublicAddressGuard;

/** Explicitly checks every prohibited network plus ordinary global addresses. */
final class PublicAddressGuardTest extends TestCase {
	/** Special addresses never become public through textual encoding tricks. */
	public function test_special_ranges_are_refused(): void {
		foreach ( array( '0.0.0.0', '10.1.1.1', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::', '::1', '::ffff:8.8.8.8', '64:ff9b::808:808', '100::1', '2001::1', '2001:db8::1', '2002::1', 'fc00::1', 'fe80::1', 'ff02::1', '2130706433', '0x7f.1' ) as $ip ) {
			self::assertFalse( PublicAddressGuard::allows( $ip ), $ip ); }
	}
	/** Normal global addresses retain an allowed path. */
	public function test_global_addresses(): void {
		self::assertTrue( PublicAddressGuard::allows( '8.8.8.8' ) );
		self::assertTrue( PublicAddressGuard::allows( '2606:4700:4700::1111' ) );
	}
}
