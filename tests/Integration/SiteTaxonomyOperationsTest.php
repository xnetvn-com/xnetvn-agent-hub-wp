<?php
/**
 * Machine taxonomy operations against real core terms and tables.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only catalog and budget doubles.

use Closure;
use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\SiteTaxonomyOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Policy\SitePolicy;

/** Catalog returning the reviewed taxonomy definitions. */
final class SiteTaxonomyIntegrationCatalog implements AbilityCatalog {
	/** Definitions by name.
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
	 * Resolve one name.
	 *
	 * @param string $name Name.
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
	 * @param string              $name Name.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $arguments ): mixed {
		return array( 'catalog' => $name );
	}
}

/** Budget double recording the exact site identity. */
final class SiteTaxonomyIntegrationBudget implements ExpensiveBudget {
	/** Recorded identities.
	 *
	 * @var array<string>
	 */
	public array $identities = array();

	/**
	 * Run the work and record the identity.
	 *
	 * @param string  $identity Site identity.
	 * @param Closure $work Work.
	 * @return mixed
	 */
	public function run( string $identity, Closure $work ): mixed {
		$this->identities[] = $identity;
		return $work();
	}
}

/** Uses real term rows, hierarchy, defaults and the reviewed HTML boundary. */
final class SiteTaxonomyOperationsTest extends WP_UnitTestCase {
	/** Current blog. */
	private const BLOG = 1;

	/** Machine operations under test.
	 *
	 * @var SiteTaxonomyOperations
	 */
	private SiteTaxonomyOperations $operations;

	/** Machine identity.
	 *
	 * @var SiteAuthContext
	 */
	private SiteAuthContext $context;

	/** Editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/** Build a real REST server and an unfiltered_html editor request. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$user         = get_userdata( $this->editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $this->editor );
		// Reproduce the request state core prepares for an unfiltered_html owner.
		kses_remove_filters();
		// The accountless principal never borrows the editor for machine calls.
		wp_set_current_user( 0 );
		$this->operations = new SiteTaxonomyOperations();
		$this->context    = $this->machine_context();
	}

	/** Restore the shared core state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		kses_init_filters();
		parent::tear_down();
	}

	/** Listing resolves only managed taxonomies with fixed-map hints. */
	public function test_list_taxonomies_and_terms(): void {
		$taxonomies = $this->operations->execute( 'xnetvn/list-taxonomies', array(), $this->context );
		self::assertIsArray( $taxonomies );
		$slugs = array_column( $taxonomies['items'], 'slug' );
		self::assertContains( 'category', $slugs );
		self::assertContains( 'post_tag', $slugs );
		self::assertNotContains( 'nav_menu', $slugs );
		foreach ( $taxonomies['items'] as $item ) {
			self::assertTrue( $item['can_assign'] );
			self::assertTrue( $item['can_edit'] );
			self::assertTrue( $item['can_delete'] );
			self::assertSame( array( 'post' ), $item['types'] );
		}

		$tag   = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Machine tag ' . wp_rand(),
			)
		);
		$total = (int) get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		$page  = $this->operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'per_page' => 100,
			),
			$this->context
		);
		self::assertIsArray( $page, is_wp_error( $page ) ? $page->get_error_message() : '' );
		self::assertSame( $total, $page['total'] );
		self::assertSame( 1, $page['page'] );
		self::assertSame( (int) ceil( $total / 100 ), $page['total_pages'] );
		self::assertCount( min( $total, 100 ), $page['items'] );
		$single = $this->operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'include'  => array( $tag ),
			),
			$this->context
		);
		self::assertIsArray( $single );
		self::assertSame( 1, $single['total'] );
		self::assertSame( array( $tag ), array_column( $single['items'], 'id' ) );

		$one = $this->operations->execute(
			'xnetvn/get-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
				'fields'   => array( 'name', 'count' ),
			),
			$this->context
		);
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( $tag, $one['id'] );
		self::assertSame( 'post_tag', $one['taxonomy'] );
		self::assertArrayHasKey( 'name', $one );
		self::assertArrayNotHasKey( 'description', $one );
	}

	/** An unregistered, REST-disabled or custom-capability taxonomy is unavailable. */
	public function test_unavailable_taxonomies_are_refused(): void {
		$cases = array(
			'site_rest_off_' . wp_rand()    => array( 'show_in_rest' => false ),
			'site_options_cap_' . wp_rand() => array(
				'show_in_rest' => true,
				'capabilities' => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				),
			),
		);
		foreach ( $cases as $taxonomy => $args ) {
			register_taxonomy( $taxonomy, array( 'post' ), $args );
			try {
				$result = $this->operations->execute(
					'xnetvn/create-term',
					array(
						'taxonomy' => $taxonomy,
						'name'     => 'Refused ' . wp_rand(),
					),
					$this->context
				);
				self::assertInstanceOf( \WP_Error::class, $result, $taxonomy );
				self::assertSame( 'xnetvn_agent_hub_wp_taxonomy_unavailable', $result->get_error_code(), $taxonomy );
				self::assertSame(
					0,
					(int) get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'hide_empty' => false,
							'fields'     => 'count',
						)
					),
					$taxonomy
				);
			} finally {
				unregister_taxonomy( $taxonomy );
			}
		}

		$missing = $this->operations->execute(
			'xnetvn/list-terms',
			array( 'taxonomy' => 'site_missing_' . wp_rand() ),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_taxonomy_unavailable', $missing->get_error_code() );
	}

	/** Machine term writes scrub stored HTML even for an unfiltered_html editor. */
	public function test_term_writes_store_filtered_html(): void {
		global $wpdb;
		$kses_state = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$created    = $this->operations->execute(
			'xnetvn/create-term',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => '<b>Unsafe</b><script>alert(1)</script>',
				'description' => '<strong>Safe</strong><script>alert(1)</script><img src="x" onerror="alert(1)">',
			),
			$this->context
		);
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$id = (int) $created['id'];

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix table name.
				"SELECT t.name, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d",
				$id
			)
		);
		self::assertInstanceOf( \stdClass::class, $row );
		self::assertStringNotContainsString( '<', (string) $row->name );
		self::assertStringNotContainsString( '<script', (string) $row->description );
		self::assertStringNotContainsString( 'onerror', (string) $row->description );
		self::assertStringContainsString( '<strong>Safe</strong>', (string) $row->description );
		self::assertSame( $kses_state, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		$updated = $this->operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy'    => 'post_tag',
				'id'          => $id,
				'description' => '<strong>Updated</strong><script>alert(1)</script>',
			),
			$this->context
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		$description = (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix table name.
				"SELECT description FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
				$id,
				'post_tag'
			)
		);
		self::assertStringContainsString( '<strong>Updated</strong>', $description );
		self::assertStringNotContainsString( '<script', $description );
		self::assertSame( $kses_state, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		// Ordinary core writes still work after the machine boundary restores its hooks.
		$direct = wp_insert_term( 'Direct ' . wp_rand(), 'post_tag' );
		self::assertIsArray( $direct );
	}

	/** The human write path also stores filtered HTML for an unfiltered_html editor. */
	public function test_human_term_writes_store_filtered_html(): void {
		global $wpdb;
		wp_set_current_user( $this->editor );
		$kses_state = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$created    = ( new TaxonomyOperations() )->execute(
			'create-term',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => '<b>Unsafe</b><script>alert(1)</script>',
				'description' => '<strong>Safe</strong><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
			)
		);
		$data       = $created instanceof MutationResult ? $created->data : $created;
		self::assertIsArray( $data, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$id  = (int) $data['id'];
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prefix table name.
				"SELECT t.name, tt.description FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d",
				$id
			)
		);
		self::assertInstanceOf( \stdClass::class, $row );
		self::assertStringNotContainsString( '<', (string) $row->name );
		self::assertStringContainsString( '<strong>Safe</strong>', (string) $row->description );
		self::assertStringNotContainsString( '<script', (string) $row->description );
		self::assertStringNotContainsString( 'javascript:', (string) $row->description );
		self::assertSame( $kses_state, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/** Assignment never accepts a string, a zero or a foreign term. */
	public function test_assignment_rejects_strings_and_never_creates_terms(): void {
		global $wpdb;
		$post     = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$before   = (int) get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		$category = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Foreign ' . wp_rand(),
			)
		);
		$cases    = array(
			array( '123' ),
			array( 'new-tag' ),
			array( 'injected-term' ),
			array( 0 ),
			array( -1 ),
			array( 1.5 ),
			array( $category ),
			array( 999999 ),
		);
		foreach ( $cases as $term_ids ) {
			$result = $this->operations->execute(
				'xnetvn/assign-post-terms',
				array(
					'taxonomy' => 'post_tag',
					'post_id'  => $post,
					'term_ids' => $term_ids,
					'mode'     => 'append',
				),
				$this->context
			);
			self::assertInstanceOf( \WP_Error::class, $result, wp_json_encode( $term_ids ) );
			self::assertContains(
				$result->get_error_code(),
				array( 'xnetvn_agent_hub_wp_invalid_params', 'xnetvn_agent_hub_wp_not_found' ),
				wp_json_encode( $term_ids )
			);
			self::assertNull( $result->get_error_data() );
			self::assertStringNotContainsString( 'new-tag', $result->get_error_message() );
			self::assertStringNotContainsString( 'injected-term', $result->get_error_message() );
		}
		self::assertSame( array(), wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
		self::assertSame(
			$before,
			(int) get_terms(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
					'fields'     => 'count',
				)
			)
		);
		self::assertNull( term_exists( 'new-tag', 'post_tag' ) );
		self::assertNull( term_exists( 'injected-term', 'post_tag' ) );
		self::assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = %s", 'new-tag' ) ) );
	}

	/** Assignment requires a real term and an editable target post. */
	public function test_assignment_guards_the_term_and_target(): void {
		$post  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$tag   = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Real tag ' . wp_rand(),
			)
		);
		$trash = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $trash );
		$cases = array(
			array( $trash, array( $tag ), 'xnetvn_agent_hub_wp_invalid_state' ),
			array( 999999, array( $tag ), 'xnetvn_agent_hub_wp_not_found' ),
		);
		foreach ( $cases as $case ) {
			$result = $this->operations->execute(
				'xnetvn/assign-post-terms',
				array(
					'taxonomy' => 'post_tag',
					'post_id'  => $case[0],
					'term_ids' => $case[1],
					'mode'     => 'append',
				),
				$this->context
			);
			self::assertInstanceOf( \WP_Error::class, $result, (string) $case[0] );
			self::assertSame( $case[2], $result->get_error_code(), (string) $case[0] );
		}
		self::assertSame( array(), wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
	}

	/** Verified assignment replaces, appends and fills the default category. */
	public function test_assign_verified_terms_persists_relationships(): void {
		$post    = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$tag     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Verified ' . wp_rand(),
			)
		);
		$tag2    = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Verified two ' . wp_rand(),
			)
		);
		$replace = SiteTaxonomyOperations::assign_verified_terms( $post, 'post_tag', array( $tag ) );
		self::assertIsArray( $replace );
		self::assertSame( array( $tag ), $replace['term_ids'] );
		self::assertSame( array( $tag ), wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );

		$append = SiteTaxonomyOperations::assign_verified_terms( $post, 'post_tag', array( $tag2 ), true );
		self::assertIsArray( $append );
		self::assertSame( 'append', $append['mode'] );
		self::assertContains( $tag2, $append['term_ids'] );
		self::assertCount( 2, wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );

		$default = SiteTaxonomyOperations::assign_verified_terms( $post, 'category', array() );
		self::assertIsArray( $default );
		self::assertTrue( $default['default_term_applied'] );
		self::assertSame( array( (int) get_option( 'default_category' ) ), $default['term_ids'] );
		self::assertSame( array( (int) get_option( 'default_category' ) ), wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ) );
	}

	/** Hierarchy, parent loops and flat-taxonomy parents are refused. */
	public function test_parent_rules(): void {
		$parent = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Parent ' . wp_rand(),
			)
		);
		$child  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Child ' . wp_rand(),
				'parent'   => $parent,
			)
		);
		$self   = $this->operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'category',
				'id'       => $parent,
				'parent'   => $child,
			),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $self );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $self->get_error_code() );
		self::assertSame( 0, (int) get_term( $parent, 'category' )->parent );

		$tag  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Flat ' . wp_rand(),
			)
		);
		$flat = $this->operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
				'parent'   => 0,
			),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $flat );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $flat->get_error_code() );
	}

	/** Default category and default term ids are refused before any dry run. */
	public function test_delete_term_refuses_defaults_before_dry_run(): void {
		$default = (int) get_option( 'default_category' );
		self::assertGreaterThan( 0, $default );
		foreach ( array( true, false ) as $dry_run ) {
			$result = $this->operations->execute(
				'xnetvn/delete-term',
				array(
					'taxonomy' => 'category',
					'id'       => $default,
					'dry_run'  => $dry_run,
				),
				$this->context
			);
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $result->get_error_code() );
		}
		self::assertInstanceOf( \WP_Term::class, get_term( $default, 'category' ) );

		$tag = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Default term ' . wp_rand(),
			)
		);
		update_option( 'default_term_post_tag', $tag );
		$refused = $this->operations->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
			),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertInstanceOf( \WP_Term::class, get_term( $tag, 'post_tag' ) );
	}

	/** A dry run and the real deletion report the reviewed impact. */
	public function test_delete_term_dry_run_and_reparent_children(): void {
		$parent = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Doomed ' . wp_rand(),
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Kept child ' . wp_rand(),
				'parent'   => $parent,
			)
		);
		$dry = $this->operations->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'category',
				'id'       => $parent,
				'dry_run'  => true,
			),
			$this->context
		);
		self::assertIsArray( $dry );
		self::assertFalse( $dry['deleted'] );
		self::assertSame( 1, $dry['impact']['children'] );
		self::assertInstanceOf( \WP_Term::class, get_term( $parent, 'category' ) );

		$deleted = $this->operations->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'category',
				'id'       => $parent,
			),
			$this->context
		);
		self::assertIsArray( $deleted );
		self::assertTrue( $deleted['deleted'] );
		self::assertSame( 1, $deleted['children_reparented'] );
		self::assertNull( get_term( $parent, 'category' ) );

		$again = $this->operations->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'category',
				'id'       => $parent,
			),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $again );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $again->get_error_code() );
	}

	/** Both bulk branches isolate per-item outcomes. */
	public function test_bulk_operations_isolate_outcomes(): void {
		$existing = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Bulk existing ' . wp_rand(),
			)
		);
		$created  = $this->operations->execute(
			'xnetvn/bulk-create-terms',
			array(
				'taxonomy' => 'post_tag',
				'items'    => array(
					array( 'name' => 'Bulk new ' . wp_rand() ),
					array( 'name' => (string) get_term( $existing, 'post_tag' )->name ),
				),
			),
			$this->context
		);
		self::assertIsArray( $created );
		self::assertCount( 1, $created['created'] );
		self::assertCount( 1, $created['existing'] );
		self::assertSame( $existing, $created['existing'][0]['id'] );

		$post  = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$trash = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $trash );
		$tag  = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Bulk assign ' . wp_rand(),
			)
		);
		$bulk = $this->operations->execute(
			'xnetvn/bulk-assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( $post, $trash ),
				'term_ids' => array( $tag ),
				'mode'     => 'append',
			),
			$this->context
		);
		self::assertIsArray( $bulk );
		self::assertSame( array( $post ), $bulk['updated'] );
		self::assertSame( $trash, $bulk['failed'][0]['id'] );
		self::assertSame( 'invalid_state', $bulk['failed'][0]['code'] );
		self::assertSame( array( $tag ), wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );

		$replace = $this->operations->execute(
			'xnetvn/bulk-assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( $post ),
				'term_ids' => array( $tag ),
				'mode'     => 'replace',
			),
			$this->context
		);
		self::assertInstanceOf( \WP_Error::class, $replace );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $replace->get_error_code() );
	}

	/** Term meta is limited to registered show_in_rest keys. */
	public function test_term_meta_is_registered_show_in_rest_only(): void {
		register_term_meta(
			'post_tag',
			'site_meta_fixture',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		try {
			$created = $this->operations->execute(
				'xnetvn/create-term',
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'Meta term ' . wp_rand(),
					'meta'     => array( 'site_meta_fixture' => '<b>stored</b><script>alert(1)</script>' ),
				),
				$this->context
			);
			self::assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
			$value = (string) get_term_meta( (int) $created['id'], 'site_meta_fixture', true );
			self::assertStringContainsString( '<b>stored</b>', $value );
			self::assertStringNotContainsString( '<script', $value );

			$refused = $this->operations->execute(
				'xnetvn/create-term',
				array(
					'taxonomy' => 'post_tag',
					'name'     => 'Meta refused ' . wp_rand(),
					'meta'     => array( 'site_unknown_meta' => 'value' ),
				),
				$this->context
			);
			self::assertInstanceOf( \WP_Error::class, $refused );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $refused->get_error_code() );
		} finally {
			unregister_term_meta( 'post_tag', 'site_meta_fixture' );
		}
	}

	/** The dispatcher runs only the machine handler and the site budget identity. */
	public function test_dispatcher_machine_path_and_budget(): void {
		$settings    = new WpSiteSettings();
		$definitions = array();
		foreach ( ( new TaxonomyPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$definitions[ $definition->descriptor()->name ] = $definition;
		}
		$budget     = new SiteTaxonomyIntegrationBudget();
		$catalog    = new SiteTaxonomyIntegrationCatalog( $definitions );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		foreach ( SiteTaxonomyOperations::NAMES as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		$listed = $dispatcher->execute(
			'xnetvn/list-terms',
			array( 'taxonomy' => 'post_tag' ),
			$this->context
		);
		self::assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_message() : '' );
		self::assertSame( array(), $budget->identities );

		$tag     = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Dispatch delete ' . wp_rand(),
			)
		);
		$deleted = $dispatcher->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
			),
			$this->context
		);
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		self::assertTrue( $deleted['deleted'] );
		self::assertSame( array( 'site:' . self::BLOG . ':abcdefghijkl' ), $budget->identities );
	}

	/**
	 * Build the machine identity.
	 *
	 * @return SiteAuthContext
	 */
	private function machine_context(): SiteAuthContext {
		$scope      = new SiteScope(
			RiskTier::Destructive,
			array( 'taxonomy' ),
			SiteTaxonomyOperations::NAMES,
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential(
			1,
			new KeyId( 'abcdefghijkl' ),
			self::BLOG,
			'Integration',
			$scope,
			new DateTimeImmutable( '@' . time() )
		);
		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
	}
}
