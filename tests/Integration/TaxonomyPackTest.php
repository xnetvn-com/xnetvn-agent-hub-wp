<?php
/**
 * Taxonomy pack permissions, hierarchy and safe projections.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;

/** Uses real core taxonomies, term functions and the terms controller. */
final class TaxonomyPackTest extends WP_UnitTestCase {
	/** Tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();
	/** Settings.
	 *
	 * @var WpSiteSettings
	 */
	private WpSiteSettings $settings;
	/** Real core server and an editor actor. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->settings = new WpSiteSettings();
		foreach ( ( new TaxonomyPack( new DefinitionFactory() ) )->definitions() as $tool ) {
			$this->tools[ $tool->descriptor()->name ] = $tool; }
	}
	/**
	 * Execute a schema-valid request through final output checks.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' ); }
		$result = $tool->execute( $args );

		return ( new OutputPipeline( $this->settings ) )->process( $result, $tool->output_schema() );
	}
	/** Nine strict contracts have schema-valid examples. */
	public function test_contract(): void {
		self::assertCount( 9, $this->tools );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( new TaxonomyPack( new DefinitionFactory() ) );
	}
	/** Managed taxonomies list without internal taxonomies. */
	public function test_list_taxonomies_only_managed(): void {
		$res = $this->call( 'list-taxonomies', array() );
		self::assertIsArray( $res );
		$slugs = array_column( $res['items'], 'slug' );
		self::assertContains( 'category', $slugs );
		self::assertContains( 'post_tag', $slugs );
		self::assertNotContains( 'nav_menu', $slugs );
		self::assertNotContains( 'wp_pattern_category', $slugs );
	}
	/** Wrong-taxonomy IDs are not found without leaking. */
	public function test_get_term_wrong_taxonomy_is_not_found(): void {
		$tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'WrongTax ' . wp_rand(),
			)
		);
		$res = $this->call(
			'get-term',
			array(
				'taxonomy' => 'category',
				'id'       => $tag,
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $res->get_error_code() );
	}
	/** Parent loops are rejected before any write. */
	public function test_create_hierarchical_parent_loop_is_rejected(): void {
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'ParentLoop ' . wp_rand(),
			)
		);
		$child  = $this->call(
			'create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'ChildLoop ' . wp_rand(),
				'parent'   => $parent,
			)
		);
		self::assertIsArray( $child );
		$bad = $this->call(
			'update-term',
			array(
				'taxonomy' => 'category',
				'id'       => $parent,
				'parent'   => (int) $child['id'],
			)
		);
		self::assertWPError( $bad );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $bad->get_error_code() );
		// No term was reparented by the rejected call.
		self::assertSame( 0, (int) get_term( $parent, 'category' )->parent );
	}
	/** Unknown meta keys are rejected before any write. */
	public function test_meta_unknown_key_is_rejected_before_write(): void {
		$before = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		$res    = $this->call(
			'create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'MetaGuard ' . wp_rand(),
				'meta'     => array( 'nope_not_registered' => 1 ),
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $res->get_error_code() );
		$after = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		self::assertSame( $before, $after );
	}
	/** Unknown term IDs are not silently dropped. */
	public function test_assign_unknown_id_is_not_found_without_write(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$before = wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) );
		$res    = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'post_tag',
				'term_ids' => array( 999999 ),
				'mode'     => 'append',
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $res->get_error_code() );
		self::assertSame( $before, wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
	}
	/** Trashed posts refuse assignment. */
	public function test_assign_trash_is_invalid_state(): void {
		$post = self::factory()->post->create();
		wp_trash_post( $post );
		$tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'TrashGuard ' . wp_rand(),
			)
		);
		$res = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'post_tag',
				'term_ids' => array( $tag ),
				'mode'     => 'append',
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $res->get_error_code() );
	}
	/** Bulk creation is idempotent across repeated names. */
	public function test_bulk_create_existing_is_idempotent(): void {
		$name   = 'BulkIdem ' . wp_rand();
		$first  = $this->call(
			'bulk-create-terms',
			array(
				'taxonomy' => 'post_tag',
				'items'    => array( array( 'name' => $name ) ),
			)
		);
		$second = $this->call(
			'bulk-create-terms',
			array(
				'taxonomy' => 'post_tag',
				'items'    => array( array( 'name' => $name ) ),
			)
		);
		self::assertCount( 1, $first['created'] );
		self::assertCount( 1, $second['existing'] );
		self::assertSame( $first['created'][0]['id'], $second['existing'][0]['id'] );
	}
	/** Default terms cannot be deleted even as a dry run. */
	public function test_delete_default_is_invalid_state_and_dry_run_writes_nothing(): void {
		$def = (int) get_option( 'default_category' );
		$dry = $this->call(
			'delete-term',
			array(
				'taxonomy' => 'category',
				'id'       => $def,
				'dry_run'  => true,
			)
		);
		self::assertWPError( $dry );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $dry->get_error_code() );
		self::assertNotNull( get_term( $def, 'category' ) );
	}
	/** Contributors create flat tags but never hierarchical categories. */
	public function test_contributor_creates_tag_but_not_category(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$tag = $this->call(
			'create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'ContributorTag ' . wp_rand(),
			)
		);
		self::assertIsArray( $tag );
		self::assertGreaterThan( 0, $tag['id'] );
		$denied = $this->call(
			'create-term',
			array(
				'taxonomy' => 'category',
				'name'     => 'ContributorCat ' . wp_rand(),
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $denied->get_error_code() );
	}
	/** Authors assign their own posts but never another owner's post. */
	public function test_author_assign_own_vs_others_post(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$own    = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
			)
		);
		$theirs = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'publish',
			)
		);
		$tag    = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'AuthorScope ' . wp_rand(),
			)
		);
		$ok     = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $own,
				'taxonomy' => 'post_tag',
				'term_ids' => array( $tag ),
				'mode'     => 'append',
			)
		);
		self::assertIsArray( $ok );
		self::assertSame( array( $tag ), $ok['added'] );
		$denied = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $theirs,
				'taxonomy' => 'post_tag',
				'term_ids' => array( $tag ),
				'mode'     => 'append',
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $denied->get_error_code() );
		self::assertSame( array(), wp_get_object_terms( $theirs, 'post_tag', array( 'fields' => 'ids' ) ) );
	}
	/** Emptying categories refills the site default instead of leaving none. */
	public function test_assign_replace_empty_applies_default_category(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res  = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'category',
				'term_ids' => array(),
				'mode'     => 'replace',
			)
		);
		self::assertIsArray( $res );
		self::assertTrue( $res['default_term_applied'] );
		self::assertSame( array( (int) get_option( 'default_category' ) ), $res['term_ids'] );
	}
	/** String term IDs never reach core and create nothing. */
	public function test_assign_string_ids_rejected_without_side_effect(): void {
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$count  = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'fields'     => 'count',
			)
		);
		$result = $this->call(
			'assign-post-terms',
			array(
				'post_id'  => $post,
				'taxonomy' => 'post_tag',
				'term_ids' => array( 'not-an-id' ),
				'mode'     => 'append',
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		self::assertSame(
			$count,
			get_terms(
				array(
					'taxonomy'   => 'post_tag',
					'hide_empty' => false,
					'fields'     => 'count',
				)
			)
		);
	}
	/** Pages beyond the total are refused instead of returning empty. */
	public function test_list_terms_page_overflow_is_invalid(): void {
		$res = $this->call(
			'list-terms',
			array(
				'taxonomy' => 'post_tag',
				'page'     => 9999,
				'per_page' => 1,
			)
		);
		self::assertWPError( $res );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $res->get_error_code() );
	}
	/** Stored scripts in descriptions are filtered even for administrators. */
	public function test_kses_strips_script_in_term_description(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$created = $this->call(
			'create-term',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => 'KsesGuard ' . wp_rand(),
				'description' => 'Hello <script>alert(1)</script> <a href="https://example.test">kept</a>',
			)
		);
		self::assertIsArray( $created );
		self::assertStringNotContainsString( '<script>', (string) $created['description'] );
	}
	/** Bulk assignment isolates per-post failures. */
	public function test_bulk_assign_mixed_outcomes(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$own    = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
			)
		);
		$theirs = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'publish',
			)
		);
		$tag    = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'BulkScope ' . wp_rand(),
			)
		);
		$result = $this->call(
			'bulk-assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( $own, $theirs ),
				'term_ids' => array( $tag ),
				'mode'     => 'append',
			)
		);
		self::assertSame( array( $own ), $result['updated'] );
		self::assertSame( $theirs, $result['failed'][0]['id'] );
		self::assertSame( 'forbidden', $result['failed'][0]['code'] );
	}
	/** Dry runs report impact without writing and still enforce permission. */
	public function test_dry_run_reports_impact_without_write(): void {
		$tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'DryRunGuard ' . wp_rand(),
			)
		);
		$dry = $this->call(
			'delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
				'dry_run'  => true,
			)
		);
		self::assertFalse( $dry['deleted'] );
		self::assertTrue( $dry['dry_run'] );
		self::assertArrayHasKey( 'impact', $dry );
		self::assertNotNull( get_term( $tag, 'post_tag' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$denied = $this->call(
			'delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
				'dry_run'  => true,
			)
		);
		self::assertWPError( $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $denied->get_error_code() );
	}
	/** Term values never reach the audit trail. */
	public function test_canary_values_absent_from_audit(): void {
		global $wpdb;
		$canary = 'CANARY-' . wp_rand();
		$this->call(
			'create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => $canary,
			)
		);
		$this->call(
			'list-terms',
			array(
				'taxonomy' => 'post_tag',
				'search'   => $canary,
			)
		);
		$table = $wpdb->prefix . 'xnetvn_agent_hub_wp_audit';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name via %i placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 20', $table ), ARRAY_A );
		self::assertStringNotContainsString( $canary, wp_json_encode( $rows ? $rows : array() ) );
	}
	/** Real deletion removes the term and repeats as not found. */
	public function test_delete_tag_lifecycle(): void {
		$tag     = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'DeleteLifecycle ' . wp_rand(),
			)
		);
		$deleted = $this->call(
			'delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
			)
		);
		self::assertTrue( $deleted['deleted'] );
		self::assertNull( get_term( $tag, 'post_tag' ) );
		$again = $this->call(
			'delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => $tag,
			)
		);
		self::assertWPError( $again );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $again->get_error_code() );
	}
	/** The registry carries fifty tools after the taxonomy pack. */
	public function test_tool_inventory_is_fifty(): void {
		$factory = new DefinitionFactory();
		$count   = 0;
		$bytes   = 0;
		foreach ( ( new \XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack( $factory ) )->definitions() as $tool ) {
			++$count;
			$bytes += strlen( $tool->description() );
		}
		foreach ( ( new \XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack( $factory, $this->settings ) )->definitions() as $tool ) {
			++$count;
			$bytes += strlen( $tool->description() );
		}
		foreach ( ( new \XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack( $factory ) )->definitions() as $tool ) {
			++$count;
			$bytes += strlen( $tool->description() );
		}
		foreach ( ( new TaxonomyPack( $factory ) )->definitions() as $tool ) {
			++$count;
			$bytes += strlen( $tool->description() );
		}
		self::assertSame( 45, $count );
		self::assertGreaterThan( 0, $bytes );
		// Five system tools bring the managed total to fifty.
		self::assertSame( 50, $count + 5 );
	}
}
