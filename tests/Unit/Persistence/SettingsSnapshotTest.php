<?php
/**
 * Current policy reads must survive memoized validation without stale permissions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Persistence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Validate freshness independently of any snapshot implementation. */
final class SettingsSnapshotTest extends TestCase {
	/** Initialize service-free WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}
	/** Remove every substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	/** Same-request option changes and simulated site switches remain immediately visible. */
	public function test_every_read_observes_current_policy(): void {
		$stored = array( 'enabled' => true );
		$reads  = 0;
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored, &$reads ): array {
				++$reads;
				return $stored;
			}
		);
		$settings = new WpSiteSettings();
		for ( $i = 0; $i < 60; ++$i ) {
			self::assertTrue( $settings->get( 'enabled' ) );
		}
		$stored = array( 'enabled' => false );
		self::assertFalse( $settings->get( 'enabled' ) );
		$stored = array(
			'enabled'      => true,
			'tier_ceiling' => 'read',
		);
		self::assertSame( 'read', $settings->get( 'tier_ceiling' ) );
		self::assertSame( 62, $reads );
	}
	/** A prior valid snapshot cannot hide subsequent corruption or unknown fields. */
	public function test_corrupt_policy_never_reuses_prior_permission(): void {
		$stored = array( 'enabled' => true );
		Functions\when( 'get_option' )->alias(
			static function () use ( &$stored ): mixed {
				return $stored;
			}
		);
		$settings = new WpSiteSettings();
		self::assertTrue( $settings->get( 'enabled' ) );
		foreach ( array( 'unavailable', array( 'enabled' => 1 ), array( 'unknown_policy' => true ) ) as $invalid ) {
			$stored = $invalid;
			try {
				$settings->get( 'enabled' );
				self::fail( 'A corrupt snapshot reused a permission.' );
			} catch ( StorageException $exception ) {
				self::assertSame( 'Site settings are unavailable.', $exception->getMessage() );
			}
		}
		$stored = array( 'enabled' => false );
		self::assertFalse( $settings->get( 'enabled' ) );
	}
}
