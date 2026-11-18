<?php
/**
 * Bounded editorial author selection and live attribution recheck.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only WordPress doubles must live beside the test.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Domain\Decision;

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
