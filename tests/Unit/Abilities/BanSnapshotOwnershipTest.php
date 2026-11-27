<?php
/**
 * Ban snapshot ownership regression with no WordPress bootstrap or database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\BanSnapshot;

/** A caller may delete only the snapshot its own invocation inserted. */
final class BanSnapshotOwnershipTest extends TestCase {

	/** Valid recovery point used by every case. */
	private const SNAPSHOT = array(
		'v'      => 1,
		'roles'  => array( 'author' ),
		'caps'   => array( 'edit_posts' => true ),
		'at'     => '2026-11-16T00:00:00Z',
		'by'     => 7,
		'reason' => 'spam',
	);

	/** Initialize isolated function substitutes and the core database global. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/WpdbStub.php';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core database global substitute.
		$GLOBALS['wpdb'] = new \wpdb();
	}

	/** Remove every function substitute and the database global. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An existing valid snapshot is reported as reused, never replaced.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_existing_snapshot_is_reused_without_replacement(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		$json = (string) json_encode( self::SNAPSHOT );
		Functions\when( 'get_user_option' )->justReturn( $json );
		Functions\expect( 'add_user_meta' )->never();
		Functions\expect( 'update_user_meta' )->never();
		$created = true;
		self::assertTrue( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertFalse( $created );
	}

	/**
	 * Only a successful unique insert marks the snapshot as owned.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_inserted_snapshot_is_reported_as_created(): void {
		Functions\when( 'get_user_option' )->justReturn( null );
		$this->stub_json_encoder();
		Functions\expect( 'add_user_meta' )
			->once()
			->with( 21, 'wp_' . BanSnapshot::KEY, \Mockery::type( 'string' ), true )
			->andReturn( 1 );
		Functions\expect( 'update_user_meta' )->never();
		$created = false;
		self::assertTrue( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertTrue( $created );
	}

	/**
	 * A lost insert race reuses the winner's snapshot without claiming ownership.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_concurrent_insert_is_reused_without_ownership(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
		$json  = (string) json_encode( self::SNAPSHOT );
		$reads = 0;
		Functions\when( 'get_user_option' )->alias(
			static function () use ( &$reads, $json ): ?string {
				++$reads;
				return 1 === $reads ? null : $json;
			}
		);
		$this->stub_json_encoder();
		Functions\expect( 'add_user_meta' )->once()->andReturn( false );
		Functions\expect( 'update_user_meta' )->never();
		$created = true;
		self::assertTrue( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertFalse( $created );
	}

	/**
	 * An unreadable stored value fails closed without claiming ownership.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unreadable_existing_value_fails_closed(): void {
		Functions\when( 'get_user_option' )->justReturn( 'not-a-snapshot' );
		$this->stub_json_encoder();
		Functions\expect( 'add_user_meta' )->once()->andReturn( false );
		Functions\expect( 'update_user_meta' )->never();
		$created = true;
		self::assertFalse( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertFalse( $created );
	}

	/**
	 * A missing database prefix fails closed before any insert.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_storage_prefix_fails_closed(): void {
		Functions\when( 'get_user_option' )->justReturn( null );
		$this->stub_json_encoder();
		Functions\when( 'add_user_meta' )->justReturn( 1 );
		unset( $GLOBALS['wpdb'] );
		$created = true;
		self::assertFalse( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertFalse( $created );
	}

	/**
	 * A throwing insert reports failure and never claims ownership.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_throwing_insert_reports_failure(): void {
		Functions\when( 'get_user_option' )->justReturn( null );
		$this->stub_json_encoder();
		Functions\when( 'add_user_meta' )->alias(
			static function (): void {
				throw new \RuntimeException( 'Insert failed.' );
			}
		);
		$created = true;
		self::assertFalse( BanSnapshot::save( 21, self::SNAPSHOT, $created ) );
		self::assertFalse( $created );
	}

	/** Encode a snapshot exactly as the production path does. */
	private function stub_json_encoder(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( array $value ): string {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
				return (string) json_encode( $value );
			}
		);
	}
}
