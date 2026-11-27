<?php
/**
 * Committed rotation boundary regression with no WordPress bootstrap or database.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress doubles must live beside the test.

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
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

/** Minimal role/user doubles for the standalone eligibility check. */
final class RotationUser {
	/** User identity.
	 *
	 * @var int
	 */
	public int $ID;
	/** Role slugs.
	 *
	 * @var array<string>
	 */
	public array $roles;
	/** Direct capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $caps;

	/**
	 * Bind the fixture identity.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct capabilities.
	 */
	public function __construct( int $id, array $roles, array $caps = array() ) {
		$this->ID    = $id;
		$this->roles = $roles;
		$this->caps  = $caps;
	}
}

/** Minimal role registry double. */
final class RotationRoles {
	/** Role definitions.
	 *
	 * @var array<string,mixed>
	 */
	public array $roles;

	/**
	 * Bind role definitions.
	 *
	 * @param array<string,array{capabilities:array<string,bool>}> $roles Roles.
	 */
	public function __construct( array $roles ) {
		$this->roles = $roles;
	}
}

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
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( RotationUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( RotationRoles::class, 'WP_Roles' );
		}
		try {
			$user = new RotationUser( 7, array( 'editor' ) );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'get_userdata' )->justReturn( $user );
			Functions\when( 'is_multisite' )->justReturn( false );
			Functions\when( 'wp_roles' )->justReturn(
				new RotationRoles(
					array(
						'editor' => array(
							'capabilities' => array(
								'edit_posts'      => true,
								'unfiltered_html' => true,
							),
						),
					)
				)
			);
			Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
			Functions\when( 'user_can' )->alias(
				static function ( mixed $subject, string $capability ): bool {
					return Capabilities::USE === $capability || 'edit_posts' === $capability;
				}
			);
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
