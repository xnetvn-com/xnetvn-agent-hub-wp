<?php
/**
 * Bounded editorial author selection and live attribution recheck.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test-only WordPress doubles and the fixed handler signatures.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal user double for the author guard. */
final class EditorialAuthorUser {
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
	/** Stored display name.
	 *
	 * @var string
	 */
	public string $display_name;

	/**
	 * Bind fixture state.
	 *
	 * @param int                $id Identity.
	 * @param array<string>      $roles Roles.
	 * @param string             $display_name Display name.
	 * @param array<string,bool> $caps Direct caps.
	 */
	public function __construct( int $id, array $roles, string $display_name, array $caps = array() ) {
		$this->ID           = $id;
		$this->roles        = $roles;
		$this->display_name = $display_name;
		$this->caps         = $caps;
	}
}

/** Minimal role registry double. */
final class EditorialAuthorRoles {
	/** Role definitions.
	 *
	 * @var array<string,mixed>
	 */
	public array $roles;

	/**
	 * Bind role definitions.
	 *
	 * @param array<string,mixed> $roles Roles.
	 */
	public function __construct( array $roles ) {
		$this->roles = $roles;
	}
}

/** Observable database state for the fail-closed cases. */
final class EditorialAuthorDatabase {
	/** Last database error.
	 *
	 * @var string
	 */
	public string $last_error = '';

	/** Statements executed directly by the guard.
	 *
	 * @var array<string>
	 */
	public array $queries = array();

	/**
	 * Record a direct mutation attempt.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public function query( string $sql ): bool {
		$this->queries[] = $sql;
		return true;
	}
}


/** Catalog containing the exact definitions under test. */
final class EditorialAuthorsCatalog implements AbilityCatalog {

	/** Definitions by canonical name.
	 *
	 * @var array<string,AbilitySpec>
	 */
	private array $definitions;

	/**
	 * Bind the definitions.
	 *
	 * @param array<string,AbilitySpec> $definitions Definitions.
	 */
	public function __construct( array $definitions ) {
		$this->definitions = $definitions;
	}

	/**
	 * List every definition.
	 *
	 * @return array<AbilitySpec>
	 */
	public function all(): array {
		return array_values( $this->definitions );
	}

	/**
	 * Resolve one canonical name.
	 *
	 * @param string $name Canonical name.
	 * @return AbilitySpec|null
	 */
	public function get( string $name ): ?AbilitySpec {
		return $this->definitions[ $name ] ?? null;
	}

	/**
	 * List visible definitions.
	 *
	 * @return array<AbilitySpec>
	 */
	public function visible(): array {
		return $this->all();
	}

	/**
	 * Execute the human catalog path.
	 *
	 * @param string              $name Canonical name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed {
		return array( 'catalog' => $name );
	}
}

/** Human definition fixture that must never run on the machine path. */
final class EditorialAuthorsCanary implements AbilitySpec {

	/** Recorded human executions.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Wrap the real definition contract.
	 *
	 * @param AbilitySpec $definition Real definition.
	 */
	public function __construct( private AbilitySpec $definition ) {
	}

	/**
	 * Return the real descriptor.
	 *
	 * @return AbilityDescriptor
	 */
	public function descriptor(): AbilityDescriptor {
		return $this->definition->descriptor();
	}

	/**
	 * Return the real wire name.
	 *
	 * @return string
	 */
	public function tool_name(): string {
		return $this->definition->tool_name();
	}

	/**
	 * Return the real label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->definition->label();
	}

	/**
	 * Return the real description.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->definition->description();
	}

	/**
	 * Return the real input schema.
	 *
	 * @return array<string,mixed>
	 */
	public function input_schema(): array {
		return $this->definition->input_schema();
	}

	/**
	 * Return the real output schema.
	 *
	 * @return array<string,mixed>
	 */
	public function output_schema(): array {
		return $this->definition->output_schema();
	}

	/**
	 * Return the real annotations.
	 *
	 * @return array<string,mixed>
	 */
	public function annotations(): array {
		return $this->definition->annotations();
	}

	/**
	 * Return the real example.
	 *
	 * @return mixed
	 */
	public function example(): mixed {
		return $this->definition->example();
	}

	/**
	 * Return the real cost flag.
	 *
	 * @return bool
	 */
	public function costly(): bool {
		return $this->definition->costly();
	}

	/**
	 * Return a canary payload instead of the machine result.
	 *
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed {
		++$this->calls;
		return array( 'canary' => true );
	}
}

/** Budget double recording any expensive-work identity. */
final class EditorialAuthorsBudget implements ExpensiveBudget {

	/** Recorded identities.
	 *
	 * @var array<string>
	 */
	public array $identities = array();

	/**
	 * Run the work and record the identity.
	 *
	 * @param string  $identity Identity.
	 * @param Closure $work Work.
	 * @return mixed
	 */
	public function run( string $identity, Closure $work ): mixed {
		$this->identities[] = $identity;
		return $work();
	}
}

/**
 * The stored-baseline list is never authorization; the live recheck is.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EditorialAuthorsTest extends TestCase {

	/** Fixed current blog. */
	private const BLOG = 1;

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( EditorialAuthorUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( EditorialAuthorRoles::class, 'WP_Roles' );
		}
		if ( ! class_exists( 'wpdb', false ) ) {
			class_alias( EditorialAuthorDatabase::class, 'wpdb' );
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core database global substitute.
		$GLOBALS['wpdb'] = new EditorialAuthorDatabase();
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$this->roles();
	}

	/** Remove substitutes and the database global. */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Only the numeric ID and the sanitized display name leave the guard. */
	public function test_list_projects_only_the_numeric_id_and_display_name(): void {
		$this->users(
			array(
				3 => new \WP_User( 3, array( 'author' ), 'Jane Author' ),
				7 => new \WP_User( 7, array( 'editor' ), 'John Editor' ),
			)
		);
		$this->edit_capable( array( 3, 7 ) );
		$this->members( array( 3, 7 ) );
		$captured = array();
		$this->get_users( array( 7, 3 ), $captured );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( array( 'items', 'page', 'per_page', 'total', 'total_pages' ), array_keys( $result ) );
		self::assertSame(
			array(
				array(
					'id'   => 3,
					'name' => 'Jane Author',
				),
				array(
					'id'   => 7,
					'name' => 'John Editor',
				),
			),
			$result['items']
		);
		self::assertSame( 1, $result['page'] );
		self::assertSame( 20, $result['per_page'] );
		self::assertSame( 2, $result['total'] );
		self::assertSame( 1, $result['total_pages'] );
		self::assertSame( $this->bounded_query(), $captured );
	}

	/** Candidate identifiers are normalized, deduplicated and sorted. */
	public function test_candidates_are_normalized_deduplicated_and_sorted(): void {
		$this->users(
			array(
				3 => new \WP_User( 3, array( 'author' ), 'Three' ),
				7 => new \WP_User( 7, array( 'editor' ), 'Seven' ),
			)
		);
		$this->edit_capable( array( 3, 7 ) );
		$this->members( array( 3, 7 ) );
		$captured = array();
		$this->get_users( array( '7', 3, '3', 0, 'not-an-identifier' ), $captured );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( array( 3, 7 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 2, $result['total'] );
	}

	/** Pagination is the exact requested slice with exact totals. */
	public function test_pagination_is_an_exact_slice_with_exact_totals(): void {
		$users = array();
		foreach ( array( 10, 20, 30, 40, 50 ) as $id ) {
			$users[ $id ] = new \WP_User( $id, array( 'editor' ), 'Editor ' . $id );
		}
		$this->users( $users );
		$this->edit_capable( array_keys( $users ) );
		$this->members( array_keys( $users ) );
		Functions\when( 'get_users' )->justReturn( array( 50, 20, 40, 10, 30 ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible( 2, 2 );

		self::assertIsArray( $result );
		self::assertSame( array( 30, 40 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 2, $result['page'] );
		self::assertSame( 2, $result['per_page'] );
		self::assertSame( 5, $result['total'] );
		self::assertSame( 3, $result['total_pages'] );
	}

	/** A page beyond the last page is an empty page with exact totals. */
	public function test_page_beyond_the_last_page_returns_exact_totals(): void {
		$users = array();
		foreach ( array( 10, 20, 30, 40, 50 ) as $id ) {
			$users[ $id ] = new \WP_User( $id, array( 'editor' ), 'Editor ' . $id );
		}
		$this->users( $users );
		$this->edit_capable( array_keys( $users ) );
		$this->members( array_keys( $users ) );
		Functions\when( 'get_users' )->justReturn( array_keys( $users ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible( 4, 2 );

		self::assertIsArray( $result );
		self::assertSame( array(), $result['items'] );
		self::assertSame( 5, $result['total'] );
		self::assertSame( 3, $result['total_pages'] );
	}

	/** Invalid page and per-page bounds are refused before any scan. */
	public function test_invalid_page_and_per_page_bounds_are_refused(): void {
		Functions\expect( 'get_users' )->never();
		$authors = new EditorialAuthors( $this->seam() );
		foreach ( array( array( 0, 20 ), array( 1, 0 ), array( 1, 101 ) ) as $bounds ) {
			$result = $authors->list_eligible( $bounds[0], $bounds[1] );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
	}

	/** An oversized page is refused before an overflowing offset is computed. */
	public function test_oversized_page_is_refused_before_overflow(): void {
		Functions\expect( 'get_users' )->never();
		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible( PHP_INT_MAX, 20 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
	}

	/** More than the reviewed candidate bound fails closed without partial totals. */
	public function test_more_than_the_candidate_bound_fails_closed(): void {
		Functions\when( 'get_users' )->justReturn( range( 1, EditorialAuthors::MAX_CANDIDATE_SCAN + 1 ) );
		$this->writes_never();

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
		self::assertObjectNotHasProperty( 'items', $result );
		self::assertObjectNotHasProperty( 'total', $result );
		self::assertObjectNotHasProperty( 'total_pages', $result );
		self::assertSame( array(), $this->database()->queries );
	}

	/** A failed candidate query is not an empty directory and writes nothing. */
	public function test_failed_candidate_query_fails_closed_and_writes_nothing(): void {
		$db = $this->database();
		Functions\when( 'get_users' )->alias(
			static function ( array $arguments ) use ( $db ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The candidate query signature requires the arguments.
				$db->last_error = 'Synthetic candidate query failure';
				return array();
			}
		);
		$this->writes_never();

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
		self::assertSame( array(), $db->queries );
	}

	/** A missing or unreadable database state fails closed. */
	public function test_missing_or_unreadable_database_state_fails_closed(): void {
		Functions\expect( 'get_users' )->never();
		unset( $GLOBALS['wpdb'] );
		$authors = new EditorialAuthors( $this->seam() );
		$result  = $authors->list_eligible();
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core database global substitute.
		$GLOBALS['wpdb'] = new \stdClass();
		$result          = $authors->list_eligible();
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
	}

	/** A failed candidate row read is not reported as a vanished user. */
	public function test_failed_candidate_read_is_not_a_vanished_user(): void {
		$db = $this->database();
		Functions\when( 'get_users' )->justReturn( array( 5 ) );
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ) use ( $db ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The row-read signature requires the user ID.
				$db->last_error = 'Synthetic candidate row failure';
				return false;
			}
		);
		$this->writes_never();

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
		self::assertSame( array(), $this->database()->queries );
	}

	/** A failed membership read fails closed for the list and the live recheck. */
	public function test_membership_read_failure_fails_closed(): void {
		$db = $this->database();
		$this->users( array( 5 => new \WP_User( 5, array( 'editor' ), 'Editor' ) ) );
		$this->edit_capable( array( 5 ) );
		Functions\when( 'get_users' )->justReturn( array( 5 ) );
		Functions\when( 'is_user_member_of_blog' )->alias(
			static function ( int $id ) use ( $db ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The membership signature requires the user ID.
				$db->last_error = 'Synthetic membership failure';
				return true;
			}
		);
		$authors = new EditorialAuthors( $this->seam() );

		$list = $authors->list_eligible();
		self::assertInstanceOf( \WP_Error::class, $list );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $list->get_error_code() );
		self::assertSame( array(), $db->queries );

		$db->last_error = '';
		$user           = $authors->require_eligible( 5 );
		self::assertInstanceOf( \WP_Error::class, $user );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $user->get_error_code() );
		self::assertSame( array(), $db->queries );
	}

	/** A vanished candidate with a clean error state is excluded. */
	public function test_vanished_candidate_with_clean_state_is_excluded(): void {
		Functions\when( 'get_users' )->justReturn( array( 5 ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( array(), $result['items'] );
		self::assertSame( 0, $result['total'] );
		self::assertSame( 0, $result['total_pages'] );
	}

	/** An unreadable role registry fails closed. */
	public function test_empty_role_registry_fails_closed(): void {
		Functions\when( 'get_users' )->justReturn( array( 5 ) );
		Functions\when( 'wp_roles' )->justReturn( new \WP_Roles( array() ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
	}

	/** Protected and capability-missing candidates are excluded silently. */
	public function test_protected_and_capability_missing_candidates_are_excluded(): void {
		$this->users(
			array(
				2 => new \WP_User( 2, array( 'administrator' ), 'Admin' ),
				3 => new \WP_User( 3, array( 'subscriber' ), 'Subscriber' ),
				4 => new \WP_User( 4, array( 'editor' ), 'Editor' ),
			)
		);
		$this->edit_capable( array( 2, 3, 4 ) );
		$this->members( array( 2, 3, 4 ) );
		Functions\when( 'get_users' )->justReturn( array( 2, 3, 4 ) );

		$result = ( new EditorialAuthors(
			$this->seam(
				array(
					2 => Decision::deny( 'delegation_protected_account' ),
					3 => Decision::deny( 'delegation_capability_missing' ),
				)
			)
		) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( array( 4 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 1, $result['total'] );
	}

	/** An unreadable eligibility decision fails closed. */
	public function test_unreadable_eligibility_state_fails_closed(): void {
		$this->users( array( 4 => new \WP_User( 4, array( 'editor' ), 'Editor' ) ) );
		$this->edit_capable( array( 4 ) );
		$this->members( array( 4 ) );
		Functions\when( 'get_users' )->justReturn( array( 4 ) );

		$result = ( new EditorialAuthors( $this->seam( array( 4 => Decision::deny( 'delegation_role_state_unavailable' ) ) ) ) )->list_eligible();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
	}

	/** The stored-baseline list is never live authorization. */
	public function test_stored_baseline_list_is_not_live_authorization(): void {
		$this->users(
			array(
				10 => new \WP_User( 10, array( 'editor' ), 'Editor' ),
				11 => new \WP_User( 11, array( 'contributor' ), 'Contributor' ),
				12 => new \WP_User( 12, array( 'fixture_editorial' ), 'Custom role' ),
				13 => new \WP_User( 13, array( 'subscriber' ), 'Filtered subscriber' ),
			)
		);
		$this->edit_capable( array( 10, 11, 12, 13 ) );
		$this->members( array( 10, 11, 12, 13 ) );
		Functions\when( 'get_users' )->justReturn( array( 10, 11, 12 ) );
		$state         = new \stdClass();
		$state->denied = false;
		$seam          = static function ( int $id ) use ( $state ): Decision { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The seam signature fixes the candidate identity.
			return $state->denied ? Decision::deny( 'delegation_capability_missing' ) : Decision::permit();
		};
		$authors       = new EditorialAuthors( $seam );

		$result = $authors->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( array( 10, 11, 12 ), array_column( $result['items'], 'id' ) );
		self::assertNotContains( 13, array_column( $result['items'], 'id' ) );

		$accepted = $authors->require_eligible( 13 );
		self::assertInstanceOf( \WP_User::class, $accepted );
		self::assertSame( 13, $accepted->ID );

		$state->denied = true;
		$refused       = $authors->require_eligible( 11 );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $refused->get_error_code() );
	}

	/** The stored display name is projected to at most two hundred characters. */
	public function test_display_name_is_truncated_to_two_hundred_characters(): void {
		$long = str_repeat( 'A', 250 );
		$this->users( array( 9 => new \WP_User( 9, array( 'author' ), $long ) ) );
		$this->edit_capable( array( 9 ) );
		$this->members( array( 9 ) );
		Functions\when( 'get_users' )->justReturn( array( 9 ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( str_repeat( 'A', 200 ), $result['items'][0]['name'] );
		self::assertSame( 200, strlen( $result['items'][0]['name'] ) );
	}

	/** An empty display name never falls back to a login or email. */
	public function test_empty_display_name_never_falls_back(): void {
		$this->users( array( 9 => new \WP_User( 9, array( 'author' ), '' ) ) );
		$this->edit_capable( array( 9 ) );
		$this->members( array( 9 ) );
		Functions\when( 'get_users' )->justReturn( array( 9 ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->list_eligible();

		self::assertIsArray( $result );
		self::assertSame( '', $result['items'][0]['name'] );
	}

	/** The live authority returns the fresh user for attribution. */
	public function test_require_eligible_accepts_an_eligible_member(): void {
		$this->users( array( 10 => new \WP_User( 10, array( 'author' ), 'Author' ) ) );
		$this->edit_capable( array( 10 ) );
		$this->members( array( 10 ) );

		$result = ( new EditorialAuthors( $this->seam() ) )->require_eligible( 10 );

		self::assertInstanceOf( \WP_User::class, $result );
		self::assertSame( 10, $result->ID );
	}

	/** The live authority refuses missing, changed, banned and foreign targets. */
	public function test_require_eligible_refuses_missing_banned_and_foreign_targets(): void {
		$this->users(
			array(
				4 => new \WP_User( 4, array( 'editor' ), 'Editor' ),
				5 => new \WP_User( 5, array( 'banned' ), 'Banned' ),
				6 => new \WP_User( 6, array( 'subscriber' ), 'Subscriber' ),
				7 => new \WP_User( 7, array( 'editor' ), 'Foreign editor' ),
				8 => new \WP_User( 88, array( 'editor' ), 'Changed identity' ),
			)
		);
		$this->edit_capable( array( 4, 5, 7, 8 ) );
		$this->members( array( 4, 5, 6, 8 ) );
		$authors = new EditorialAuthors( $this->seam() );

		foreach ( array( 0, 999, 5, 6, 7, 8 ) as $target ) {
			$result = $authors->require_eligible( $target );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $result->get_error_code() );
		}
	}

	/** A protected or unreadable live decision maps to the reviewed codes. */
	public function test_require_eligible_maps_live_decisions(): void {
		$this->users(
			array(
				2 => new \WP_User( 2, array( 'administrator' ), 'Admin' ),
				3 => new \WP_User( 3, array( 'editor' ), 'Editor' ),
				4 => new \WP_User( 4, array( 'editor' ), 'Unreadable' ),
			)
		);
		$this->edit_capable( array( 2, 3, 4 ) );
		$this->members( array( 2, 3, 4 ) );

		$protected = ( new EditorialAuthors( $this->seam( array( 2 => Decision::deny( 'delegation_protected_account' ) ) ) ) )->require_eligible( 2 );
		self::assertInstanceOf( \WP_Error::class, $protected );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $protected->get_error_code() );

		$unreadable = ( new EditorialAuthors( $this->seam( array( 3 => Decision::deny( 'delegation_role_state_unavailable' ) ) ) ) )->require_eligible( 3 );
		self::assertInstanceOf( \WP_Error::class, $unreadable );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $unreadable->get_error_code() );

		$throwing = new EditorialAuthors(
			static function ( int $id ): Decision { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The seam signature fixes the candidate identity.
				throw new \RuntimeException( 'Synthetic eligibility failure' );
			}
		);
		$result   = $throwing->require_eligible( 4 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
	}

	/** The machine path uses the real 20th definition and the fixed built-in handler. */
	public function test_machine_path_uses_the_real_definition_and_built_in_handler(): void {
		$this->eligible_machine_users( array( 42 ) );
		$definitions = $this->pack();
		self::assertArrayHasKey( 'xnetvn/list-content-authors', $definitions );
		$budget     = new EditorialAuthorsBudget();
		$dispatcher = $this->dispatcher( $definitions, new MemorySettings(), $budget );
		$context    = $this->context( array( 'xnetvn/list-content-authors' ) );

		self::assertTrue( $dispatcher->has_handler( 'xnetvn/list-content-authors' ) );
		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );

		self::assertIsArray( $result );
		self::assertSame( array( 42 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 'Machine author 42', $result['items'][0]['name'] );
		self::assertSame( array(), $budget->identities );
	}

	/** The machine path never falls back to the human definition closure. */
	public function test_machine_path_never_falls_back_to_the_human_closure(): void {
		$this->eligible_machine_users( array( 42 ) );
		$definitions = $this->pack();
		$canary      = new EditorialAuthorsCanary( $definitions['xnetvn/list-content-authors'] );
		$dispatcher  = $this->dispatcher( array( 'xnetvn/list-content-authors' => $canary ) );
		$context     = $this->context( array( 'xnetvn/list-content-authors' ) );

		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );

		self::assertIsArray( $result );
		self::assertSame( array( 42 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 0, $canary->calls );
	}

	/** A definition absent from the bound catalog leaves the name unavailable. */
	public function test_machine_path_requires_the_bound_definition(): void {
		$dispatcher = $this->dispatcher( array() );
		$context    = $this->context( array( 'xnetvn/list-content-authors' ) );

		self::assertFalse( $dispatcher->has_handler( 'xnetvn/list-content-authors' ) );
		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
	}

	/** Denied, revoked and cross-blog contexts never reach the handler. */
	public function test_machine_path_denials_precede_the_handler(): void {
		$this->eligible_machine_users( array( 42 ) );
		$definitions = $this->pack();
		$canary      = new EditorialAuthorsCanary( $definitions['xnetvn/list-content-authors'] );
		$dispatcher  = $this->dispatcher( array( 'xnetvn/list-content-authors' => $canary ) );
		$cases       = array(
			$this->context( array( 'xnetvn/list-posts' ) ),
			$this->context( array( 'xnetvn/list-content-authors' ), self::BLOG, true ),
			$this->context( array( 'xnetvn/list-content-authors' ), self::BLOG + 1 ),
		);
		foreach ( $cases as $context ) {
			$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
		}
		self::assertSame( 0, $canary->calls );

		$handler = EditorialAuthors::handler();
		$result  = $handler( array(), $this->context( array( 'xnetvn/list-content-authors' ), self::BLOG + 1 ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
		self::assertSame( array(), $this->database()->queries );
	}

	/** Invalid page and per-page arguments are refused before any mutation path. */
	public function test_machine_path_rejects_invalid_arguments(): void {
		$this->eligible_machine_users( array( 42 ) );
		$dispatcher = $this->dispatcher( $this->pack() );
		$context    = $this->context( array( 'xnetvn/list-content-authors' ) );

		$handler = EditorialAuthors::handler();
		$result  = $handler( array( 'page' => 0 ), $context );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );

		Functions\when( 'rest_validate_value_from_schema' )->justReturn( false );
		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array( 'per_page' => 101 ), $context );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
	}

	/** The reserved built-in name cannot be registered as an explicit handler. */
	public function test_register_rejects_the_reserved_built_in_name(): void {
		$dispatcher = $this->dispatcher( $this->pack() );
		try {
			$dispatcher->register(
				'xnetvn/list-content-authors',
				static fn( array $arguments, SiteAuthContext $context ): array => array()
			);
			self::fail( 'The reserved built-in name was registered.' );
		} catch ( \InvalidArgumentException ) {
			self::assertTrue( $dispatcher->has_handler( 'xnetvn/list-content-authors' ) );
		}
	}

	/** A low output ceiling truncates items while exact totals stay unchanged. */
	public function test_low_output_ceiling_truncates_items_with_exact_totals(): void {
		$this->eligible_machine_users( array( 41, 42, 43 ) );
		$settings   = new MemorySettings( array( 'output_max_bytes' => 160 ) );
		$dispatcher = $this->dispatcher( $this->pack(), $settings );
		$context    = $this->context( array( 'xnetvn/list-content-authors' ) );

		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );

		self::assertIsArray( $result );
		self::assertTrue( $result['truncated'] );
		self::assertSame( 3, $result['total'] );
		self::assertSame( 1, $result['total_pages'] );
		self::assertSame( 1, $result['page'] );
		self::assertSame( 20, $result['per_page'] );
		self::assertLessThan( 3, count( $result['items'] ) );
		self::assertSame(
			array_slice( array( 41, 42, 43 ), 0, count( $result['items'] ) ),
			array_column( $result['items'], 'id' )
		);
		self::assertLessThanOrEqual( 160, strlen( Json::encode( $result ) ) );
	}

	/** An impossible output ceiling returns invalid_output, never a partial success. */
	public function test_impossible_output_ceiling_returns_invalid_output(): void {
		$this->eligible_machine_users( array( 42 ) );
		$settings   = new MemorySettings( array( 'output_max_bytes' => 1 ) );
		$dispatcher = $this->dispatcher( $this->pack(), $settings );
		$context    = $this->context( array( 'xnetvn/list-content-authors' ) );

		$result = $dispatcher->execute( 'xnetvn/list-content-authors', array(), $context );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_output', $result->get_error_code() );
	}

	/**
	 * Return the fixed bounded candidate query.
	 *
	 * @return array<string,mixed>
	 */
	private function bounded_query(): array {
		return array(
			'fields'        => 'ID',
			'capability'    => 'edit_posts',
			'orderby'       => 'ID',
			'order'         => 'ASC',
			'number'        => EditorialAuthors::MAX_CANDIDATE_SCAN + 1,
			'cache_results' => false,
		);
	}

	/**
	 * Return the real Content pack definitions by canonical name.
	 *
	 * @return array<string,AbilitySpec>
	 */
	private function pack(): array {
		$definitions = array();
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$definitions[ $definition->descriptor()->name ] = $definition;
		}
		return $definitions;
	}

	/**
	 * Build the dispatcher with a bound fixture catalog.
	 *
	 * @param array<string,AbilitySpec> $definitions Definitions.
	 * @param MemorySettings|null       $settings Optional settings.
	 * @param ExpensiveBudget|null      $budget Optional budget.
	 * @return SiteDispatcher
	 */
	private function dispatcher( array $definitions, ?MemorySettings $settings = null, ?ExpensiveBudget $budget = null ): SiteDispatcher {
		$settings   = $settings ?? new MemorySettings();
		$catalog    = new EditorialAuthorsCatalog( $definitions );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		return $dispatcher;
	}

	/**
	 * Build a machine identity with an explicit scope.
	 *
	 * @param array<string> $allowed Allowed ability names.
	 * @param int           $blog_id Credential blog.
	 * @param bool          $revoked Whether the credential is revoked.
	 * @return SiteAuthContext
	 */
	private function context( array $allowed, int $blog_id = self::BLOG, bool $revoked = false ): SiteAuthContext {
		$scope      = new SiteScope(
			RiskTier::Read,
			array( 'content' ),
			$allowed,
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential(
			1,
			new KeyId( 'abcdefghijkl' ),
			$blog_id,
			'Fixture',
			$scope,
			new DateTimeImmutable( '@' . time() ),
			$revoked ? new DateTimeImmutable( '@' . time() ) : null
		);
		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
	}

	/**
	 * Install stored-baseline eligible machine authors for the default seam.
	 *
	 * @param array<int> $ids Author identifiers.
	 * @return void
	 */
	private function eligible_machine_users( array $ids ): void {
		$users = array();
		foreach ( $ids as $id ) {
			$users[ $id ] = new \WP_User( $id, array( 'editor' ), 'Machine author ' . $id, array( 'edit_posts' => true ) );
		}
		$this->users( $users );
		$this->members( $ids );
		Functions\when( 'get_users' )->justReturn( $ids );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'user_can' )->alias(
			static function ( mixed $user, string $capability ) use ( $ids ): bool {
				$reviewed = array( 'edit_posts', 'use_xnetvn_agent_hub_wp' );
				return in_array( $capability, $reviewed, true ) && in_array( (int) $user->ID, $ids, true );
			}
		);
	}

	/**
	 * Install the candidate query result and capture its arguments.
	 *
	 * @param array<mixed>        $ids Candidate identifiers.
	 * @param array<string,mixed> $captured Captured arguments.
	 * @return void
	 */
	private function get_users( array $ids, array &$captured ): void {
		Functions\when( 'get_users' )->alias(
			static function ( array $arguments ) use ( $ids, &$captured ): array {
				$captured = $arguments;
				return $ids;
			}
		);
	}

	/**
	 * Install the user fixtures.
	 *
	 * @param array<int,EditorialAuthorUser> $users Users by ID.
	 * @return void
	 */
	private function users( array $users ): void {
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ) use ( $users ) {
				return $users[ $id ] ?? false;
			}
		);
	}

	/**
	 * Install the current-blog membership set.
	 *
	 * @param array<int> $ids Member identifiers.
	 * @return void
	 */
	private function members( array $ids ): void {
		Functions\when( 'is_user_member_of_blog' )->alias(
			static fn( int $id ): bool => in_array( $id, $ids, true )
		);
	}

	/**
	 * Install the live edit_posts capability decision.
	 *
	 * @param array<int> $ids Capable identifiers.
	 * @return void
	 */
	private function edit_capable( array $ids ): void {
		Functions\when( 'user_can' )->alias(
			static function ( mixed $user, string $capability ) use ( $ids ): bool {
				return 'edit_posts' === $capability && in_array( (int) $user->ID, $ids, true );
			}
		);
	}

	/**
	 * Install a non-empty role registry.
	 *
	 * @param array<string,mixed>|null $roles Optional role definitions.
	 * @return void
	 */
	private function roles( ?array $roles = null ): void {
		$roles = $roles ?? array(
			'editor'      => array( 'capabilities' => array( 'edit_posts' => true ) ),
			'author'      => array( 'capabilities' => array( 'edit_posts' => true ) ),
			'contributor' => array( 'capabilities' => array( 'edit_posts' => true ) ),
			'subscriber'  => array( 'capabilities' => array( 'read' => true ) ),
			'banned'      => array( 'capabilities' => array() ),
		);
		Functions\when( 'wp_roles' )->justReturn( new \WP_Roles( $roles ) );
	}

	/**
	 * Build the eligibility seam.
	 *
	 * @param array<int,Decision> $decisions Decisions by user ID.
	 * @return Closure(int):Decision
	 */
	private function seam( array $decisions = array() ): Closure {
		return static fn( int $id ): Decision => $decisions[ $id ] ?? Decision::permit();
	}

	/** The observable database substitute. */
	private function database(): EditorialAuthorDatabase {
		$db = $GLOBALS['wpdb'] ?? null;
		self::assertInstanceOf( EditorialAuthorDatabase::class, $db );
		return $db;
	}

	/** User, role and capability writes are forbidden for this read. */
	private function writes_never(): void {
		Functions\expect( 'wp_insert_user' )->never();
		Functions\expect( 'wp_update_user' )->never();
		Functions\expect( 'add_role' )->never();
		Functions\expect( 'remove_role' )->never();
		Functions\expect( 'add_cap' )->never();
		Functions\expect( 'remove_cap' )->never();
	}
}
