<?php
/**
 * Accountless site credential lifecycle unit tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteKeyService;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RateLimitResult;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Persistence\KeyCollision;
use XNetVN\AgentHubWP\Persistence\SiteKeyLock;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only doubles must live beside the test.

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant shim for the isolated unit process.
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant shim for the isolated unit process.
	define( 'HOUR_IN_SECONDS', 3600 );
}

/** In-memory repository with the exact production contract. */
final class SiteKeyMemoryRepository implements SiteKeyRepository {
	/** Stored credentials.
	 *
	 * @var array<int,SiteCredential>
	 */
	public array $rows = array();
	/** Durable rotation record.
	 *
	 * @var array{outcome:string,record:array<string,mixed>|null}
	 */
	public array $record = array(
		'outcome' => 'not_committed',
		'record'  => null,
	);
	/** Number of insert attempts.
	 *
	 * @var int
	 */
	public int $inserts = 0;
	/** Fail the first identifier collision.
	 *
	 * @var bool
	 */
	public bool $collide_once = false;
	/** Next database identity.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * Bind the shared clock.
	 *
	 * @param FrozenClock $clock Clock.
	 */
	public function __construct( private FrozenClock $clock ) {
	}

	/**
	 * Insert one credential.
	 *
	 * @param KeyId     $id Identifier.
	 * @param SiteScope $scope Scope.
	 * @param string    $name Name.
	 * @param string    $hash Hash.
	 * @param int       $pepper_id Pepper version.
	 * @param int       $created_by Manager.
	 * @return SiteCredential
	 * @throws KeyCollision On the configured first attempt.
	 */
	public function insert( KeyId $id, SiteScope $scope, string $name, #[\SensitiveParameter] string $hash, int $pepper_id, int $created_by ): SiteCredential {
		++$this->inserts;
		if ( $this->collide_once ) {
			$this->collide_once = false;
			throw new KeyCollision( 'Fixture collision.' );
		}
		$key                    = new SiteCredential( $this->next++, $id, 1, $name, $scope, new DateTimeImmutable( '@' . $this->clock->now() ), null, $created_by );
		$this->rows[ $key->id ] = $key;
		return $key;
	}

	/**
	 * Find stored verification data; not needed by these tests.
	 *
	 * @param KeyId $id Identifier.
	 * @return null
	 */
	public function find_by_key_id( KeyId $id ): ?\XNetVN\AgentHubWP\Auth\StoredSiteKey {
		return null;
	}

	/**
	 * Find one row.
	 *
	 * @param int $id Identity.
	 * @return SiteCredential|null
	 */
	public function find_by_id( int $id ): ?SiteCredential {
		return $this->rows[ $id ] ?? null;
	}

	/**
	 * List rows with the production state filter.
	 *
	 * @param array<string,mixed> $filter Filter.
	 * @return array<SiteCredential>
	 */
	public function list_all( array $filter = array() ): array {
		$state = (string) ( $filter['state'] ?? '' );
		$items = array_values(
			array_filter(
				$this->rows,
				function ( SiteCredential $row ) use ( $state ): bool {
					if ( 'active' === $state ) {
						return ! $row->is_revoked() && $row->scope->expires_at->getTimestamp() > $this->clock->now();
					}
					if ( 'revoked' === $state ) {
						return $row->is_revoked();
					}
					if ( 'expired' === $state ) {
						return ! $row->is_revoked() && $row->scope->expires_at->getTimestamp() <= $this->clock->now();
					}
					return true;
				}
			)
		);
		$size  = max( 1, min( 100, (int) ( $filter['per_page'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $filter['page'] ?? 1 ) );
		return array_slice( $items, ( $page - 1 ) * $size, $size );
	}

	/**
	 * Count active rows.
	 *
	 * @return int
	 */
	public function count_active(): int {
		return count(
			array_filter(
				$this->rows,
				fn( SiteCredential $row ): bool => ! $row->is_revoked() && $row->scope->expires_at->getTimestamp() > $this->clock->now()
			)
		);
	}

	/**
	 * Rename/narrow one live row.
	 *
	 * @param int       $id Identity.
	 * @param string    $name Name.
	 * @param SiteScope $scope Scope.
	 * @return bool
	 */
	public function update_key( int $id, string $name, SiteScope $scope ): bool {
		$row = $this->rows[ $id ] ?? null;
		if ( null === $row || $row->is_revoked() ) {
			return false;
		}
		$this->rows[ $id ] = new SiteCredential( $row->id, $row->key_id, $row->blog_id, $name, $scope, $row->created_at, $row->revoked_at, $row->created_by, $row->revoked_by, $row->last_used_at, $row->last_used_ip );
		return true;
	}

	/**
	 * Revoke one live row.
	 *
	 * @param int    $id Identity.
	 * @param int    $by Manager.
	 * @param string $reason Reason.
	 * @return bool
	 */
	public function revoke( int $id, int $by, string $reason ): bool {
		$row = $this->rows[ $id ] ?? null;
		if ( null === $row || $row->is_revoked() ) {
			return false;
		}
		$this->rows[ $id ] = new SiteCredential( $row->id, $row->key_id, $row->blog_id, $row->name, $row->scope, $row->created_at, new DateTimeImmutable( '@' . $this->clock->now() ), $row->created_by, $by, $row->last_used_at, $row->last_used_ip );
		return true;
	}

	/**
	 * Touch usage; not needed by these tests.
	 *
	 * @param int         $id Identity.
	 * @param string|null $ip Peer.
	 * @param string|null $ua Agent.
	 * @return bool
	 */
	public function touch_last_used( int $id, ?string $ip, ?string $ua ): bool {
		return true;
	}

	/**
	 * Insert a replacement and move the old row to its grace scope.
	 *
	 * @param SiteCredential $old Old row.
	 * @param SiteScope      $grace Grace scope.
	 * @param KeyId          $new_id Replacement identifier.
	 * @param string         $hash Hash.
	 * @param int            $pepper_id Pepper version.
	 * @param int            $by Manager.
	 * @return SiteCredential
	 * @throws StorageException When a durable record is unresolved.
	 */
	public function insert_rotation( SiteCredential $old, SiteScope $grace, KeyId $new_id, #[\SensitiveParameter] string $hash, int $pepper_id, int $by ): SiteCredential {
		if ( null !== $this->record['record'] && 'not_committed' !== $this->record['outcome'] ) {
			throw new StorageException( 'Fixture unresolved rotation.' );
		}
		$row = $this->rows[ $old->id ] ?? null;
		if ( null === $row || $row->is_revoked() ) {
			throw new StorageException( 'Fixture old credential is unavailable.' );
		}
		$this->record                   = array(
			'outcome' => 'not_committed',
			'record'  => null,
		);
		$replacement                    = new SiteCredential( $this->next++, $new_id, 1, $old->name, $old->scope, new DateTimeImmutable( '@' . $this->clock->now() ), null, $by );
		$this->rows[ $old->id ]         = new SiteCredential( $row->id, $row->key_id, $row->blog_id, $row->name, $grace, $row->created_at, $row->revoked_at, $row->created_by, $row->revoked_by, $row->last_used_at, $row->last_used_ip );
		$this->rows[ $replacement->id ] = $replacement;
		return $replacement;
	}

	/**
	 * Return the durable rotation record.
	 *
	 * @return array{outcome:string,record:array<string,mixed>|null}
	 */
	public function reconcile_rotation(): array {
		return $this->record;
	}

	/**
	 * Clear one recorded unknown intent.
	 *
	 * @return bool
	 */
	public function resolve_rotation_intent(): bool {
		if ( 'unknown' !== $this->record['outcome'] ) {
			return false;
		}
		$this->record = array(
			'outcome' => 'not_committed',
			'record'  => null,
		);
		return true;
	}
}

/** Minimal registered catalog for materialization tests. */
final class SiteKeyCatalog implements AbilityCatalog {
	/** Registered definitions.
	 *
	 * @var array<AbilitySpec>
	 */
	private array $specs = array();

	/**
	 * Register the fixed fixture operations.
	 */
	public function __construct() {
		$map = array(
			'xnetvn/create-post'     => array( RiskTier::Write, 'content' ),
			'xnetvn/delete-media'    => array( RiskTier::Destructive, 'media' ),
			'xnetvn/get-site-info'   => array( RiskTier::Read, 'system' ),
			'xnetvn/set-user-status' => array( RiskTier::Admin, 'users' ),
		);
		foreach ( $map as $name => $definition ) {
			$this->specs[] = new class( $name, $definition[0], $definition[1] ) implements AbilitySpec {
				/** Policy metadata.
				 *
				 * @var AbilityDescriptor
				 */
				private AbilityDescriptor $descriptor;

				/**
				 * Bind one fixture definition.
				 *
				 * @param string   $name Name.
				 * @param RiskTier $tier Tier.
				 * @param string   $toolset Toolset.
				 */
				public function __construct( string $name, RiskTier $tier, string $toolset ) {
					$this->descriptor = new AbilityDescriptor( $name, $tier, $toolset, 'manage_options' );
				}

				/** Return policy metadata.
				 *
				 * @return AbilityDescriptor
				 */
				public function descriptor(): AbilityDescriptor {
					return $this->descriptor;
				}

				/** Return the wire name.
				 *
				 * @return string
				 */
				public function tool_name(): string {
					return str_replace( '/', '-', $this->descriptor->name );
				}

				/** Return a label.
				 *
				 * @return string
				 */
				public function label(): string {
					return 'Fixture';
				}

				/** Return a description.
				 *
				 * @return string
				 */
				public function description(): string {
					return 'Fixture operation used only by the site key unit test.';
				}

				/** Return a strict input schema.
				 *
				 * @return array<string,mixed>
				 */
				public function input_schema(): array {
					return array(
						'type'                 => 'object',
						'additionalProperties' => false,
					);
				}

				/** Return a strict output schema.
				 *
				 * @return array<string,mixed>
				 */
				public function output_schema(): array {
					return array(
						'type'                 => 'object',
						'additionalProperties' => false,
					);
				}

				/** Return annotations.
				 *
				 * @return array<string,mixed>
				 */
				public function annotations(): array {
					return array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					);
				}

				/** Return an example.
				 *
				 * @return mixed
				 */
				public function example(): mixed {
					return null;
				}

				/** Report cost.
				 *
				 * @return bool
				 */
				public function costly(): bool {
					return false;
				}

				/** Execute nothing.
				 *
				 * @param array<string,mixed> $arguments Arguments.
				 * @return mixed
				 */
				public function execute( array $arguments ): mixed {
					return null;
				}
			};
		}
	}

	/**
	 * List all definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function all(): array {
		return $this->specs;
	}

	/**
	 * Find one definition.
	 *
	 * @param string $name Name.
	 * @return AbilitySpec|null
	 */
	public function get( string $name ): ?AbilitySpec {
		foreach ( $this->specs as $spec ) {
			if ( $spec->descriptor()->name === $name ) {
				return $spec;
			}
		}
		return null;
	}

	/**
	 * List visible definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function visible(): array {
		return $this->specs;
	}

	/**
	 * Execute nothing.
	 *
	 * @param string              $name Name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed {
		return null;
	}
}

/** Mutable password budget result. */
final class SiteKeyBudget implements RateLimiter {
	/** Whether the budget allows the check.
	 *
	 * @var bool
	 */
	public bool $allowed = true;
	/** Recorded budget calls.
	 *
	 * @var array<array{0:string,1:string,2:int}>
	 */
	public array $calls = array();

	/**
	 * Return the configured decision.
	 *
	 * @param string $scope Scope.
	 * @param string $id Identity.
	 * @param int    $limit Limit.
	 * @param bool   $increment Increment.
	 * @return RateLimitResult
	 */
	public function check( string $scope, string $id, int $limit, bool $increment = true ): RateLimitResult {
		$this->calls[] = array( $scope, $id, $limit );
		return new RateLimitResult( $this->allowed, 0, $this->allowed ? 99 : 0, 1.0 );
	}
}

/** Exercises the full site credential lifecycle without WordPress. */
final class SiteKeyServiceTest extends TestCase {
	/** Fixed administrator identity. */
	private const MANAGER = 7;
	/** Fixed second administrator identity. */
	private const SECOND_MANAGER = 8;

	/** Mutable current user.
	 *
	 * @var int
	 */
	private int $current_user = self::MANAGER;
	/** Whether the current user passes MANAGE.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;
	/** Shared clock.
	 *
	 * @var FrozenClock
	 */
	private FrozenClock $clock;
	/** Mutable site settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;
	/** In-memory repository.
	 *
	 * @var SiteKeyMemoryRepository
	 */
	private SiteKeyMemoryRepository $repo;
	/** Mutable password budget.
	 *
	 * @var SiteKeyBudget
	 */
	private SiteKeyBudget $budget;
	/** Service under test.
	 *
	 * @var SiteKeyService
	 */
	private SiteKeyService $service;

	/** Install WordPress substitutes and deterministic fixtures. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		require_once dirname( __DIR__, 2 ) . '/Support/WpdbStub.php';
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn(
			array(
				'id'    => 1,
				'value' => base64_encode( str_repeat( 'P', 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixed non-production pepper bytes.
			)
		);
		Functions\when( 'add_option' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user );
		Functions\when( 'user_can' )->alias( fn( int $user, string $capability ): bool => $this->can_manage && in_array( $user, array( self::MANAGER, self::SECOND_MANAGER ), true ) && Capabilities::MANAGE === $capability );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_userdata' )->alias(
			static fn( int $id ): object => (object) array(
				'ID'        => $id,
				'user_pass' => 'fixture-hash',
			)
		);
		Functions\when( 'wp_check_password' )->alias( static fn( string $password ): bool => 'correct-password' === $password );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'do_action' )->justReturn( null );

		$this->clock    = new FrozenClock( 1791072000 );
		$this->settings = new MemorySettings( array( 'allow_admin_tier' => false ) );
		$this->repo     = new SiteKeyMemoryRepository( $this->clock );
		$this->budget   = new SiteKeyBudget();
		$this->service  = new SiteKeyService( $this->repo, new SiteKeyLock( new \wpdb() ), new SiteTokenCodec(), new PepperStore(), $this->settings, new SiteKeyCatalog(), $this->budget, $this->clock );
	}

	/** Remove all substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A live MANAGE capability is mandatory. */
	public function test_create_requires_live_manage(): void {
		$this->can_manage = false;
		$this->assertError( 'forbidden', 403, $this->service->create( $this->valid_input() ) );
		self::assertSame( 0, count( $this->repo->rows ) );
		self::assertSame( 0, $this->repo->inserts );
	}

	/** Unknown and owner-shaped fields never reach storage. */
	public function test_create_rejects_unknown_and_owner_fields(): void {
		foreach ( array( 'owner_user_id', 'user_id', 'blog_id', 'principal_type', 'created_by' ) as $field ) {
			$this->assertError( 'invalid_site_key', 400, $this->service->create( $this->valid_input( array( $field => 1 ) ) ) );
		}
		self::assertSame( 0, $this->repo->inserts );
	}

	/** Missing or wrong passwords create no row. */
	public function test_create_requires_the_current_password(): void {
		$without = $this->valid_input();
		unset( $without['current_password'] );
		$this->assertError( 'invalid_site_key', 400, $this->service->create( $without ) );
		$this->assertError( 'reauthentication_required', 403, $this->service->create( $this->valid_input( array( 'current_password' => 'wrong-password' ) ) ) );
		self::assertSame( 0, $this->repo->inserts );
	}

	/** A throttled password budget refuses before any row is written. */
	public function test_create_honours_the_password_budget(): void {
		$this->budget->allowed = false;
		$this->assertError( 'rate_limited', 429, $this->service->create( $this->valid_input() ) );
		self::assertSame( 0, $this->repo->inserts );
	}

	/** The default scope is the bounded destructive editorial profile. */
	public function test_create_materializes_defaults_and_reveals_once(): void {
		$this->repo->collide_once = true;
		$issued                   = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( 2, $this->repo->inserts );
		self::assertSame( $this->clock->now() + 90 * DAY_IN_SECONDS, $issued->key->scope->expires_at->getTimestamp() );
		self::assertSame( RiskTier::Destructive, $issued->key->scope->tier_ceiling );
		self::assertSame( array( 'xnetvn/create-post', 'xnetvn/delete-media', 'xnetvn/get-site-info' ), $issued->key->scope->allowed_abilities );
		self::assertSame( array( 'content', 'media', 'system' ), $issued->key->scope->toolsets );
		self::assertSame( self::MANAGER, $issued->key->created_by );
		$token = $issued->reveal();
		self::assertStringStartsWith( 'xns_', $token );
		$this->expectException( \LogicException::class );
		$issued->reveal();
	}

	/** A lower site ceiling narrows the materialized profile. */
	public function test_create_caps_the_profile_to_the_site_ceiling(): void {
		$this->settings->update( array( 'tier_ceiling' => 'read' ) );
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( RiskTier::Read, $issued->key->scope->tier_ceiling );
		self::assertSame( array( 'xnetvn/get-site-info' ), $issued->key->scope->allowed_abilities );
	}

	/** Moderation needs the explicit admin opt-in and grants one extra operation. */
	public function test_create_moderation_requires_the_admin_site_gates(): void {
		$input = $this->valid_input( array( 'permissions' => array( 'ban-users' ) ) );
		$this->assertError( 'forbidden', 403, $this->service->create( $input ) );
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$issued = $this->service->create( $input );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( RiskTier::Admin, $issued->key->scope->tier_ceiling );
		self::assertContains( 'users', $issued->key->scope->toolsets );
		self::assertContains( 'xnetvn/set-user-status', $issued->key->scope->allowed_abilities );
		self::assertNotContains( 'xnetvn/set-user-role', $issued->key->scope->allowed_abilities );
	}

	/** Create and rotate both refuse at the hard ten-key quota. */
	public function test_create_and_rotate_refuse_at_the_active_quota(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		for ( $i = 0; $i < 9; ++$i ) {
			$this->seed( 'Quota ' . $i );
		}
		self::assertSame( SiteKeyService::ACTIVE_QUOTA, $this->repo->count_active() );
		$this->assertError( 'key_limit', 409, $this->service->create( $this->valid_input() ) );
		$this->assertError(
			'key_limit',
			409,
			$this->service->rotate(
				$issued->key->id,
				array(
					'current_password' => 'correct-password',
					'grace_hours'      => 0,
				)
			)
		);
		self::assertSame( 10, count( $this->repo->rows ) );
	}

	/** Patch can only rename or narrow and preserves the exact scope otherwise. */
	public function test_patch_rejects_expansion_and_accepts_narrowing(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$before = $issued->key->scope->to_array();
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$this->assertError( 'scope_expansion', 409, $this->service->patch( $issued->key->id, array( 'permissions' => array( 'ban-users' ) ) ) );
		$this->assertError( 'scope_expansion', 409, $this->service->patch( $issued->key->id, array( 'expires_in_days' => 200 ) ) );
		self::assertSame( $before, $this->repo->find_by_id( $issued->key->id )->scope->to_array() );
		$patched = $this->service->patch(
			$issued->key->id,
			array(
				'name'            => 'Renamed',
				'expires_in_days' => 30,
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $patched );
		self::assertSame( 'Renamed', $patched->name );
		self::assertSame( $this->clock->now() + 30 * DAY_IN_SECONDS, $patched->scope->expires_at->getTimestamp() );
	}

	/** Rotation inherits the scope, narrows the old grace and reveals once. */
	public function test_rotate_inherits_scope_and_narrows_grace(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$scope  = $issued->key->scope->to_array();
		$result = $this->service->rotate(
			$issued->key->id,
			array(
				'current_password' => 'correct-password',
				'grace_hours'      => 4,
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( $issued->key->id, $result->key->id );
		self::assertSame( $scope, $result->key->scope->to_array() );
		self::assertSame( $this->clock->now() + 4 * HOUR_IN_SECONDS, $this->repo->find_by_id( $issued->key->id )->scope->expires_at->getTimestamp() );
		self::assertStringStartsWith( 'xns_', $result->reveal() );
		$this->expectException( \LogicException::class );
		$result->reveal();
	}

	/** An unresolved rotation blocks before any write and the operator can clear it. */
	public function test_rotate_refuses_an_unresolved_record_until_the_operator_resolves(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$count              = count( $this->repo->rows );
		$this->repo->record = array(
			'outcome' => 'unknown',
			'record'  => array( 'old_id' => $issued->key->id ),
		);
		$this->assertError( 'rotation_unresolved', 409, $this->service->rotate( $issued->key->id, array( 'current_password' => 'correct-password' ) ) );
		self::assertSame( $count, count( $this->repo->rows ) );
		self::assertTrue( $this->service->resolve_rotation_intent() );
		$rotated = $this->service->rotate( $issued->key->id, array( 'current_password' => 'correct-password' ) );
		self::assertNotInstanceOf( \WP_Error::class, $rotated );
	}

	/** A second live manager can revoke after the issuer is demoted. */
	public function test_revoke_survives_issuer_demotion(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$this->current_user = self::SECOND_MANAGER;
		self::assertTrue( $this->service->revoke( $issued->key->id ) );
		self::assertTrue( $this->repo->find_by_id( $issued->key->id )->is_revoked() );
		self::assertFalse( $this->service->revoke( $issued->key->id ) );
	}

	/** Missing rows and malformed filters fail safely. */
	public function test_get_list_and_filter_validation(): void {
		$this->assertError( 'not_found', 404, $this->service->get( 999 ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->list( array( 'per_page' => 0 ) ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->list( array( 'page' => '2' ) ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->list( array( 'state' => 'future' ) ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->list( array( 'state' => 'all' ) ) );
		self::assertSame( array(), $this->service->list() );
	}

	/** A second connection that cannot acquire the lock fails as unavailable. */
	public function test_lock_contention_fails_closed(): void {
		$db      = new class() extends \wpdb {
			/**
			 * Refuse every lock acquisition.
			 *
			 * @param string|null $query Query.
			 * @return string
			 */
			public function get_var( ?string $query = null ): ?string {
				return '0';
			}
		};
		$service = new SiteKeyService( $this->repo, new SiteKeyLock( $db ), new SiteTokenCodec(), new PepperStore(), $this->settings, new SiteKeyCatalog(), $this->budget, $this->clock );
		$this->assertError( 'unavailable', 503, $service->create( $this->valid_input() ) );
		self::assertSame( 0, $this->repo->inserts );
	}

	/** Explicit toolsets and allowlists only narrow the registered profile. */
	public function test_create_restricts_to_requested_toolsets_and_abilities(): void {
		$issued = $this->service->create(
			$this->valid_input(
				array(
					'toolsets'      => array( 'content', 'media' ),
					'ability_allow' => array( 'xnetvn/create-post' ),
				)
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( array( 'xnetvn/create-post' ), $issued->key->scope->allowed_abilities );
		self::assertSame( array( 'content', 'media', 'system' ), $issued->key->scope->toolsets );
		$this->assertError( 'invalid_site_key', 400, $this->service->create( $this->valid_input( array( 'toolsets' => array( 'not-a-toolset' ) ) ) ) );
		$this->assertError( 'invalid_site_key', 400, $this->service->create( $this->valid_input( array( 'ability_allow' => array( 'xnetvn/not-registered' ) ) ) ) );
		self::assertSame( 1, count( $this->repo->rows ) );
	}

	/** Malformed CIDRs fail before any write. */
	public function test_create_rejects_an_invalid_ip_allowlist(): void {
		$this->assertError( 'invalid_site_key', 400, $this->service->create( $this->valid_input( array( 'ip_allowlist' => array( '999.0.0.1/24' ) ) ) ) );
		self::assertSame( 0, $this->repo->inserts );
	}

	/** Patch refuses unknown keys, a cleared allowlist and revoked rows. */
	public function test_patch_rejects_unknown_cleared_and_revoked(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		$this->assertError( 'invalid_site_key', 400, $this->service->patch( $issued->key->id, array( 'user_id' => 1 ) ) );
		$this->assertError( 'scope_expansion', 409, $this->service->patch( $issued->key->id, array( 'ability_allow' => array() ) ) );
		self::assertTrue( $this->service->revoke( $issued->key->id ) );
		$this->assertError( 'not_found', 404, $this->service->patch( $issued->key->id, array( 'name' => 'Revoked' ) ) );
	}

	/** Moderation keeps the editorial profile when toolsets are restricted. */
	public function test_moderation_with_explicit_toolsets_keeps_the_editorial_profile(): void {
		$this->settings->update(
			array(
				'allow_admin_tier' => true,
				'tier_ceiling'     => 'admin',
			)
		);
		$issued = $this->service->create(
			$this->valid_input(
				array(
					'permissions' => array( 'ban-users' ),
					'toolsets'    => array( 'content' ),
				)
			)
		);
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( array( 'xnetvn/create-post', 'xnetvn/set-user-status' ), $issued->key->scope->allowed_abilities );
		self::assertSame( array( 'content', 'system', 'users' ), $issued->key->scope->toolsets );
	}

	/** The shared password bucket identity and limit are pinned. */
	public function test_password_budget_uses_the_shared_key_bucket(): void {
		$issued = $this->service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertSame( array( 'key-password', (string) self::MANAGER, 5 ), $this->budget->calls[0] );
		$this->budget->allowed = false;
		$this->assertError( 'rate_limited', 429, $this->service->create( $this->valid_input() ) );
	}

	/** An unacknowledged release is announced without failing the committed write. */
	public function test_incomplete_lock_release_is_announced(): void {
		$seen = array();
		Functions\when( 'do_action' )->alias(
			static function ( string $hook ) use ( &$seen ): void {
				$seen[] = $hook;
			}
		);
		$db      = new class() extends \wpdb {
			/**
			 * Grant the lock and refuse the release.
			 *
			 * @param string|null $query Query.
			 * @return string
			 */
			public function get_var( ?string $query = null ): ?string {
				return str_contains( (string) $query, 'RELEASE_LOCK' ) ? '0' : '1';
			}
		};
		$service = new SiteKeyService( $this->repo, new SiteKeyLock( $db ), new SiteTokenCodec(), new PepperStore(), $this->settings, new SiteKeyCatalog(), $this->budget, $this->clock );
		$issued  = $service->create( $this->valid_input() );
		self::assertNotInstanceOf( \WP_Error::class, $issued );
		self::assertContains( 'xnetvn_agent_hub_wp_site_lock_release_incomplete', $seen );
		self::assertSame( 1, count( $this->repo->rows ) );
	}

	/**
	 * Build one valid creation payload.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'             => 'Fixture site key',
				'current_password' => 'correct-password',
			),
			$overrides
		);
	}

	/**
	 * Seed one live credential directly.
	 *
	 * @param string $name Name.
	 * @return SiteCredential
	 */
	private function seed( string $name ): SiteCredential {
		$scope = new SiteScope(
			RiskTier::Write,
			array( 'content' ),
			array( 'xnetvn/create-post' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( $this->clock->now() + 90 * DAY_IN_SECONDS ) )
		);
		return $this->repo->insert( new KeyId( 'abcdefghijkl' ), $scope, $name, str_repeat( 'a', 64 ), 1, self::MANAGER );
	}

	/**
	 * Assert one fixed safe error.
	 *
	 * @param string $reason Expected reason.
	 * @param int    $status Expected status.
	 * @param mixed  $value Actual value.
	 * @return void
	 */
	private function assertError( string $reason, int $status, mixed $value ): void {
		self::assertInstanceOf( \WP_Error::class, $value );
		self::assertSame( 'xnetvn_agent_hub_wp_' . $reason, $value->get_error_code() );
		self::assertSame( $status, $value->data['status'] );
	}
}
