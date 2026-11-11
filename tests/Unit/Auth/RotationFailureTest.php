<?php
/**
 * Committed rotation boundary regression with no WordPress bootstrap or database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\KeyService;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\OwnerLock;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Exercises storage error propagation; real transaction/permission evidence is separate. */
final class RotationFailureTest extends TestCase {
	/**
	 * A transaction failure must suppress disclosure/events without separate insertion or compensation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_atomic_storage_exception_suppresses_disclosure(): void {
		require dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		try {
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'user_can' )->justReturn( true );
			Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
			Functions\when( 'get_option' )->justReturn(
				array(
					'id'    => 1,
					'value' => base64_encode( str_repeat( 'P', 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixed test pepper bytes.
				)
			);
			$events = array();
			Functions\when( 'do_action' )->alias(
				static function ( string $name ) use ( &$events ): void {
					$events[] = $name;
				}
			);
			if ( ! defined( 'DAY_IN_SECONDS' ) ) {
				define( 'DAY_IN_SECONDS', 86400 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core time constant in a standalone test.
				define( 'HOUR_IN_SECONDS', 3600 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core time constant in a standalone test.
			}
			$old  = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable( '@1791158400' ) );
			$keys = $this->createMock( KeyRepository::class );
			$keys->method( 'find_by_id' )->willReturn( $old );
			$keys->method( 'count_active_for_user' )->willReturn( 1 );
			$keys->expects( self::never() )->method( 'insert' );
			$keys->expects( self::never() )->method( 'update_scope' );
			$keys->expects( self::once() )->method( 'insert_rotation' )->willThrowException( new StorageException( 'Unavailable fixture' ) );
			$keys->expects( self::never() )->method( 'revoke' );
			$catalog = $this->createMock( AbilityCatalog::class );
			$catalog->method( 'all' )->willReturn( array() );
			$lock = $this->createMock( OwnerLock::class );
			$lock->method( 'run' )->willReturnCallback( static fn( int $owner, callable $operation ): mixed => $operation() );
			$service = new KeyService( $keys, new PepperStore(), new KeyGenerator(), new MemorySettings(), $catalog, $this->createMock( RateLimiter::class ), $lock, new FrozenClock( 1791158400 ) );
			$result  = $service->rotate( 1, array() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 503, $result->data['status'] );
			self::assertNotContains( 'xnetvn_agent_hub_wp_key_created', $events );
		} finally {
			Monkey\tearDown();
		}
	}
}
