<?php
/**
 * Committed owner-lock outcome regression with no WordPress bootstrap or database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Persistence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Persistence\WpOwnerLock;

/** A completed mutation must survive an unacknowledged lock release. */
final class WpOwnerLockReleaseTest extends TestCase {

	/** Public action announced when the lock is not released. */
	private const ACTION = 'xnetvn_agent_hub_wp_lock_release_incomplete';

	/** Initialize isolated function substitutes and the database stub. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/WpdbStub.php';
	}

	/** Remove every function substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An acknowledged release returns the operation result and stays silent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_acknowledged_release_returns_the_operation_result(): void {
		$events = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $name ) use ( &$events ): void {
				$events[] = $name;
			}
		);
		$lock = new WpOwnerLock( new \wpdb() );
		self::assertSame( 'committed', $lock->run( 7, static fn(): string => 'committed' ) );
		self::assertSame( array(), $events );
	}

	/**
	 * A release that reports failure still returns the committed result.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_zero_release_returns_the_committed_result(): void {
		$db                 = new \wpdb();
		$db->release_result = '0';
		$events             = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $name, int $user_id ) use ( &$events ): void {
				$events[] = array( $name, $user_id );
			}
		);
		$lock = new WpOwnerLock( $db );
		self::assertSame( 'committed', $lock->run( 7, static fn(): string => 'committed' ) );
		self::assertSame( array( array( self::ACTION, 7 ) ), $events );
	}

	/**
	 * A throwing release still returns the committed result and announces it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_throwing_release_returns_the_committed_result(): void {
		$db                 = new \wpdb();
		$db->release_result = null;
		$events             = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $name, int $user_id ) use ( &$events ): void {
				$events[] = array( $name, $user_id );
			}
		);
		$lock = new WpOwnerLock( $db );
		self::assertSame( 'committed', $lock->run( 7, static fn(): string => 'committed' ) );
		self::assertSame( array( array( self::ACTION, 7 ) ), $events );
	}

	/**
	 * An operation exception survives a failed release and a throwing listener.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_operation_exception_is_not_masked_by_a_failed_release(): void {
		$db                 = new \wpdb();
		$db->release_result = '0';
		$events             = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $name, int $user_id ) use ( &$events ): void {
				$events[] = array( $name, $user_id );
				throw new \RuntimeException( 'Listener failed.' );
			}
		);
		$lock = new WpOwnerLock( $db );
		try {
			$lock->run(
				7,
				static function (): void {
					throw new \LogicException( 'Operation failed.' );
				}
			);
			self::fail( 'The operation exception was masked.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'Operation failed.', $exception->getMessage() );
		}
		self::assertSame( array( array( self::ACTION, 7 ) ), $events );
	}
}
