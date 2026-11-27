<?php
/**
 * Machine content operations against real posts, terms, metadata and users.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test-only catalog and budget doubles.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert persisted relationships directly.

use Closure;
use DateTimeImmutable;
use WP_Post;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\SiteContentOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
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
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Catalog returning the reviewed content definitions. */
final class SiteContentIntegrationCatalog implements AbilityCatalog {
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
	 * Resolve one name.
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

/** Budget double recording the exact site identity. */
final class SiteContentIntegrationBudget implements ExpensiveBudget {
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

/** Exercises the machine content contract against real core storage. */
final class SiteContentOperationsTest extends WP_UnitTestCase {
	/** Current blog. */
	private const BLOG = 1;

	/** Published owner editor.
	 *
	 * @var int
	 */
	private int $author = 0;

	/** Second eligible editor used for attribution.
	 *
	 * @var int
	 */
	private int $target = 0;

	/** Administrator attribution target.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/** Banned attribution target.
	 *
	 * @var int
	 */
	private int $banned = 0;

	/** Prepare the real users and the guest machine state. */
	public function set_up(): void {
		parent::set_up();
		self::assertSame( 'banned', RoleGuard::ensure_banned_role() );
		$this->author = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->target = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin  = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->banned = (int) self::factory()->user->create( array( 'role' => 'banned' ) );
		wp_set_current_user( 0 );
	}

	/** Keep the machine identity at zero after every test. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Every reviewed name resolves as one fixed built-in and nothing else does. */
	public function test_names_and_dispatcher_catalog(): void {
		$machine    = $this->machine();
		$dispatcher = $machine['dispatcher'];
		self::assertCount( 19, SiteContentOperations::NAMES );
		foreach ( SiteContentOperations::NAMES as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		self::assertFalse( $dispatcher->has_handler( 'xnetvn/nope' ) );
		$definitions = $this->content_definitions();
		self::assertCount( 20, $definitions );
		self::assertArrayHasKey( 'xnetvn/list-content-authors', $definitions );
	}

	/** Read-only operations project persisted rows and revision data. */
	public function test_read_operations(): void {
		$machine = $this->machine();
		$post    = $this->make_post(
			array(
				'post_title'   => 'Read fixture ' . wp_rand(),
				'post_content' => '<b>Read</b> body',
				'post_excerpt' => 'Read excerpt',
			)
		);
		$tag     = $this->make_term( 'Read tag ' . wp_rand() );
		wp_set_object_terms( $post, array( $tag ), 'post_tag' );

		$types = $machine['dispatcher']->execute( 'xnetvn/list-post-types', array(), $machine['context'] );
		self::assertIsArray( $types, is_wp_error( $types ) ? $types->get_error_message() : '' );
		$type_slugs = array_column( $types['items'], 'slug' );
		self::assertContains( 'post', $type_slugs );
		self::assertContains( 'page', $type_slugs );
		self::assertNotContains( 'attachment', $type_slugs );

		$statuses = $machine['dispatcher']->execute( 'xnetvn/list-post-statuses', array(), $machine['context'] );
		self::assertIsArray( $statuses, is_wp_error( $statuses ) ? $statuses->get_error_message() : '' );
		self::assertSame( array( 'draft', 'pending', 'private', 'publish', 'future' ), array_column( $statuses['items'], 'slug' ) );

		$listed = $machine['dispatcher']->execute( 'xnetvn/list-posts', array( 'post_type' => 'post' ), $machine['context'] );
		self::assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_message() : '' );
		self::assertGreaterThanOrEqual( 1, $listed['total'] );
		self::assertContains( $post, array_column( $listed['items'], 'id' ) );

		$read = $machine['dispatcher']->execute( 'xnetvn/get-post', array( 'id' => $post ), $machine['context'] );
		self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		self::assertSame( '<b>Read</b> body', $read['content'] );

		$raw = $machine['dispatcher']->execute(
			'xnetvn/get-post',
			array(
				'id'             => $post,
				'content_format' => 'raw',
			),
			$machine['context']
		);
		self::assertIsArray( $raw, is_wp_error( $raw ) ? $raw->get_error_message() : '' );
		self::assertSame( '<b>Read</b> body', $raw['content'] );

		$search = $machine['dispatcher']->execute( 'xnetvn/search-content', array( 'search' => (string) get_post_field( 'post_title', $post ) ), $machine['context'] );
		self::assertIsArray( $search, is_wp_error( $search ) ? $search->get_error_message() : '' );
		self::assertContains( $post, array_column( $search['items'], 'id' ) );

		$revision = wp_save_post_revision( $post );
		self::assertIsInt( $revision );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'Newer body',
			)
		);
		$revisions = $machine['dispatcher']->execute( 'xnetvn/list-revisions', array( 'id' => $post ), $machine['context'] );
		self::assertIsArray( $revisions, is_wp_error( $revisions ) ? $revisions->get_error_message() : '' );
		self::assertGreaterThanOrEqual( 1, $revisions['total'] );
		$one = $machine['dispatcher']->execute(
			'xnetvn/get-revision',
			array(
				'id'          => $post,
				'revision_id' => $revision,
			),
			$machine['context']
		);
		self::assertIsArray( $one, is_wp_error( $one ) ? $one->get_error_message() : '' );
		self::assertSame( $revision, $one['id'] );
		self::assertSame( $post, $one['parent'] );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Create and update filter HTML, persist terms and preserve the stored author. */
	public function test_create_update_html_and_terms(): void {
		$machine = $this->machine();
		$tag     = $this->make_term( 'Create tag ' . wp_rand() );
		$created = $machine['dispatcher']->execute(
			'xnetvn/create-post',
			array(
				'title'      => 'Title <script>alert(1)</script>',
				'content'    => '<p onclick="alert(1)">Body</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
				'excerpt'    => '<script>alert(1)</script>excerpt',
				'categories' => array( (int) get_option( 'default_category' ) ),
				'tags'       => array( $tag ),
				'author'     => $this->author,
			),
			$machine['context']
		);
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$stored = get_post( (int) $created['id'] );
		self::assertInstanceOf( WP_Post::class, $stored );
		self::assertSame( $this->author, (int) $stored->post_author );
		self::assertSame( 'draft', $stored->post_status );
		foreach ( array( $stored->post_title, $stored->post_content, $stored->post_excerpt ) as $value ) {
			self::assertStringNotContainsString( '<script', $value );
			self::assertStringNotContainsString( 'onclick', $value );
			self::assertStringNotContainsString( 'javascript:', $value );
		}
		self::assertContains( $tag, array_map( 'intval', wp_get_object_terms( (int) $created['id'], 'post_tag', array( 'fields' => 'ids' ) ) ) );

		$updated = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'    => (int) $created['id'],
				'title' => 'Updated <script>x</script>',
			),
			$machine['context']
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', (int) $created['id'] ) );
		self::assertSame( 0, get_current_user_id() );

		$refused = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'     => (int) $created['id'],
				'author' => $this->target,
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $refused->get_error_code() );
	}

	/** Author requirements and the exact editorial type guard refuse before writes. */
	public function test_author_requirements_and_type_guard(): void {
		$machine = $this->machine();
		$before  = wp_count_posts( 'post' )->draft;

		$missing = $machine['dispatcher']->execute( 'xnetvn/create-post', array( 'title' => 'No author' ), $machine['context'] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $missing->get_error_code() );

		foreach ( array( $this->admin, $this->banned, PHP_INT_MAX ) as $author ) {
			$refused = $machine['dispatcher']->execute(
				'xnetvn/create-post',
				array(
					'title'  => 'Refused',
					'author' => $author,
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $refused );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $refused->get_error_code() );
		}
		self::assertSame( $before, wp_count_posts( 'post' )->draft );

		register_post_type(
			'book_fixture',
			array(
				'label'           => 'Books',
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'book',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'author' ),
			)
		);
		register_post_type(
			'custom_fixture',
			array(
				'label'           => 'Custom',
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array( 'edit_posts' => 'manage_options' ),
				'supports'        => array( 'title', 'editor', 'author' ),
			)
		);
		try {
			foreach ( array( 'book_fixture', 'custom_fixture' ) as $slug ) {
				$result = $machine['dispatcher']->execute(
					'xnetvn/create-post',
					array(
						'post_type' => $slug,
						'title'     => 'Refused type',
						'author'    => $this->author,
					),
					$machine['context']
				);
				self::assertInstanceOf( \WP_Error::class, $result, $slug );
				self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code(), $slug );
			}
		} finally {
			unregister_post_type( 'book_fixture' );
			unregister_post_type( 'custom_fixture' );
		}

		$source = $this->make_post( array( 'post_title' => 'Duplicate source' ) );
		$copy   = $machine['dispatcher']->execute(
			'xnetvn/duplicate-post',
			array(
				'id'     => $source,
				'author' => $this->target,
			),
			$machine['context']
		);
		self::assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		self::assertSame( $this->target, (int) get_post_field( 'post_author', (int) $copy['id'] ) );
		self::assertSame( 'Duplicate source', get_post_field( 'post_title', (int) $copy['id'] ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $source ) );

		$page   = $this->make_page( array( 'post_title' => 'Stick page' ) );
		$denied = $machine['dispatcher']->execute(
			'xnetvn/stick-post',
			array(
				'id'        => $page,
				'post_type' => 'page',
				'sticky'    => true,
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $denied->get_error_code() );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Trash, restore, delete and revision restore keep the stored author. */
	public function test_lifecycle_keeps_the_stored_author(): void {
		$machine = $this->machine();
		$first   = $this->make_post();
		$second  = $this->make_post();
		$third   = $this->make_post();

		$trashed = $machine['dispatcher']->execute( 'xnetvn/trash-post', array( 'id' => $first ), $machine['context'] );
		self::assertIsArray( $trashed, is_wp_error( $trashed ) ? $trashed->get_error_message() : '' );
		self::assertSame( 'trash', get_post_status( $first ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $first ) );

		$restored = $machine['dispatcher']->execute( 'xnetvn/restore-post', array( 'id' => $first ), $machine['context'] );
		self::assertIsArray( $restored, is_wp_error( $restored ) ? $restored->get_error_message() : '' );
		self::assertSame( 'draft', get_post_status( $first ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $first ) );

		$deleted = $machine['dispatcher']->execute( 'xnetvn/delete-post', array( 'id' => $second ), $machine['context'] );
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		self::assertTrue( $deleted['deleted'] );
		self::assertNull( get_post( $second ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $third ) );

		$revision = wp_save_post_revision( $third );
		self::assertIsInt( $revision );
		wp_update_post(
			array(
				'ID'           => $third,
				'post_content' => 'Changed after revision',
			)
		);
		$restore = $machine['dispatcher']->execute(
			'xnetvn/restore-revision',
			array(
				'id'          => $third,
				'revision_id' => $revision,
			),
			$machine['context']
		);
		self::assertIsArray( $restore, is_wp_error( $restore ) ? $restore->get_error_message() : '' );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $third ) );

		wp_trash_post( $third );
		$refused = $machine['dispatcher']->execute(
			'xnetvn/restore-revision',
			array(
				'id'          => $third,
				'revision_id' => $revision,
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $refused->get_error_code() );
		self::assertSame( 0, get_current_user_id() );
	}

	/** The site-page and password guards never write or disclose a body. */
	public function test_site_page_and_password_guards(): void {
		$machine  = $this->machine();
		$privacy  = $this->make_page( array( 'post_title' => 'Privacy' ) );
		$front    = $this->make_page( array( 'post_title' => 'Front' ) );
		$posts    = $this->make_page( array( 'post_title' => 'Posts page' ) );
		$password = $this->make_post(
			array(
				'post_title'    => 'Protected',
				'post_content'  => 'Hidden body',
				'post_excerpt'  => 'Hidden excerpt',
				'post_password' => 'secret',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $posts );
		try {
			$edit = $machine['dispatcher']->execute(
				'xnetvn/update-post',
				array(
					'id'        => $privacy,
					'post_type' => 'page',
					'title'     => 'No',
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $edit );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $edit->get_error_code() );
			self::assertSame( 'Privacy', get_post_field( 'post_title', $privacy ) );

			$trash_front = $machine['dispatcher']->execute(
				'xnetvn/trash-post',
				array(
					'id'        => $front,
					'post_type' => 'page',
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $trash_front );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $trash_front->get_error_code() );

			$delete_posts = $machine['dispatcher']->execute(
				'xnetvn/delete-post',
				array(
					'id'        => $posts,
					'post_type' => 'page',
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $delete_posts );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $delete_posts->get_error_code() );
			self::assertNotNull( get_post( $posts ) );
		} finally {
			update_option( 'wp_page_for_privacy_policy', 0 );
			update_option( 'page_on_front', 0 );
			update_option( 'page_for_posts', 0 );
		}

		$rendered = $machine['dispatcher']->execute( 'xnetvn/get-post', array( 'id' => $password ), $machine['context'] );
		self::assertIsArray( $rendered, is_wp_error( $rendered ) ? $rendered->get_error_message() : '' );
		self::assertArrayNotHasKey( 'content', $rendered );
		self::assertArrayNotHasKey( 'excerpt', $rendered );

		$raw = $machine['dispatcher']->execute(
			'xnetvn/get-post',
			array(
				'id'             => $password,
				'content_format' => 'raw',
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $raw );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $raw->get_error_code() );

		$sticky = $machine['dispatcher']->execute(
			'xnetvn/stick-post',
			array(
				'id'     => $password,
				'sticky' => true,
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $sticky );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $sticky->get_error_code() );
	}

	/** Registered metadata, featured media, sticky and fixed invalid values. */
	public function test_metadata_media_and_fixed_fields(): void {
		$machine = $this->machine();
		$post    = $this->make_post();
		register_post_meta(
			'post',
			'site_meta_fixture',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
		register_post_meta(
			'post',
			'site_custom_meta',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => true,
			)
		);
		try {
			$written = $machine['dispatcher']->execute(
				'xnetvn/update-post-meta',
				array(
					'id'     => $post,
					'values' => array( 'site_meta_fixture' => 'stored <b>value</b>' ),
				),
				$machine['context']
			);
			self::assertIsArray( $written, is_wp_error( $written ) ? $written->get_error_message() : '' );
			self::assertSame( 'stored <b>value</b>', (string) get_post_meta( $post, 'site_meta_fixture', true ) );

			$custom = $machine['dispatcher']->execute(
				'xnetvn/update-post-meta',
				array(
					'id'     => $post,
					'values' => array( 'site_custom_meta' => 'x' ),
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $custom );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $custom->get_error_code() );

			$unknown = $machine['dispatcher']->execute(
				'xnetvn/update-post-meta',
				array(
					'id'     => $post,
					'values' => array( 'site_unknown_meta' => 'x' ),
				),
				$machine['context']
			);
			self::assertInstanceOf( \WP_Error::class, $unknown );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unknown->get_error_code() );

			$read = $machine['dispatcher']->execute( 'xnetvn/get-post-meta', array( 'id' => $post ), $machine['context'] );
			self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
			self::assertSame( 'stored <b>value</b>', $read['meta']->site_meta_fixture );
		} finally {
			unregister_post_meta( 'post', 'site_meta_fixture' );
			unregister_post_meta( 'post', 'site_custom_meta' );
		}

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		self::assertIsInt( $attachment );
		$featured = $machine['dispatcher']->execute(
			'xnetvn/set-featured-media',
			array(
				'id'       => $post,
				'media_id' => $attachment,
			),
			$machine['context']
		);
		self::assertIsArray( $featured, is_wp_error( $featured ) ? $featured->get_error_message() : '' );
		self::assertSame( $attachment, (int) get_post_thumbnail_id( $post ) );

		$removed = $machine['dispatcher']->execute(
			'xnetvn/set-featured-media',
			array(
				'id'       => $post,
				'media_id' => 0,
			),
			$machine['context']
		);
		self::assertIsArray( $removed, is_wp_error( $removed ) ? $removed->get_error_message() : '' );
		self::assertSame( 0, (int) get_post_thumbnail_id( $post ) );

		$stuck = $machine['dispatcher']->execute(
			'xnetvn/stick-post',
			array(
				'id'     => $post,
				'sticky' => true,
			),
			$machine['context']
		);
		self::assertIsArray( $stuck, is_wp_error( $stuck ) ? $stuck->get_error_message() : '' );
		self::assertTrue( is_sticky( $post ) );

		$page   = $this->make_page();
		$denied = $machine['dispatcher']->execute(
			'xnetvn/set-featured-media',
			array(
				'id'        => $page,
				'post_type' => 'page',
				'media_id'  => $attachment,
			),
			$machine['context']
		);
		self::assertIsArray( $denied, is_wp_error( $denied ) ? $denied->get_error_message() : '' );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Bulk attribution is per item and truncation is never full completion. */
	public function test_bulk_author_matrix_and_truncation(): void {
		$machine = $this->machine();
		$first   = $this->make_post( array( 'post_title' => 'Bulk one' ) );
		$second  = $this->make_post( array( 'post_title' => 'Bulk two' ) );
		$third   = $this->make_post( array( 'post_title' => 'Bulk three' ) );

		$assigned = $machine['dispatcher']->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => array( $first, $second ),
				'changes' => array( 'author' => $this->target ),
			),
			$machine['context']
		);
		self::assertIsArray( $assigned, is_wp_error( $assigned ) ? $assigned->get_error_message() : '' );
		self::assertSame( array( $first, $second ), $assigned['updated'] );
		self::assertSame( $this->target, (int) get_post_field( 'post_author', $first ) );
		self::assertSame( $this->target, (int) get_post_field( 'post_author', $second ) );
		self::assertSame( array( 'site:' . self::BLOG . ':abcdefghijkl' ), $machine['budget']->identities );

		$preserved = $machine['dispatcher']->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => array( $third ),
				'changes' => array( 'status' => 'draft' ),
			),
			$machine['context']
		);
		self::assertIsArray( $preserved, is_wp_error( $preserved ) ? $preserved->get_error_message() : '' );
		self::assertSame( array( $third ), $preserved['updated'] );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $third ) );

		foreach ( array( $this->admin, $this->banned ) as $author ) {
			$refused = $machine['dispatcher']->execute(
				'xnetvn/bulk-update-posts',
				array(
					'ids'     => array( $third ),
					'changes' => array(
						'author' => $author,
						'status' => 'private',
					),
				),
				$machine['context']
			);
			self::assertIsArray( $refused, is_wp_error( $refused ) ? $refused->get_error_message() : '' );
			self::assertSame( array(), $refused['updated'] );
			self::assertSame(
				array(
					array(
						'id'   => $third,
						'code' => 'forbidden',
					),
				),
				$refused['failed']
			);
			self::assertSame( $this->author, (int) get_post_field( 'post_author', $third ) );
			self::assertSame( 'draft', get_post_status( $third ) );
		}

		$settings    = new MemorySettings( array( 'output_max_bytes' => 256 ) );
		$truncated   = $this->machine( SiteContentOperations::NAMES, RiskTier::Destructive, self::BLOG, array(), $settings );
		$missing_ids = range( 100000, 100044 );
		$result      = $truncated['dispatcher']->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => $missing_ids,
				'changes' => array( 'status' => 'draft' ),
			),
			$truncated['context']
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertTrue( $result['truncated'] ?? false );
		self::assertSame( array(), $result['updated'] );
		self::assertSame( 0, get_current_user_id() );
	}

	/** A real mid-write term loss returns the truthful created receipt. */
	public function test_partial_failure_after_post_persists(): void {
		$machine = $this->machine();
		$name    = 'Disposable tag ' . wp_rand();
		$term_id = $this->make_term( $name );
		$term    = get_term( $term_id, 'post_tag' );
		self::assertInstanceOf( \WP_Term::class, $term );
		$taxonomy_id = (int) $term->term_taxonomy_id;
		$default     = (int) get_option( 'default_category' );
		$seen_user   = array();
		$fired       = false;
		$hook        = static function ( int $post_id, WP_Post $post, bool $update ) use ( &$fired, &$seen_user, $term_id, $name ): void {
			if ( $update || $fired || 'post' !== $post->post_type ) {
				return;
			}
			$fired       = true;
			$seen_user[] = get_current_user_id();
			wp_delete_term( $term_id, 'post_tag' );
		};
		add_action( 'wp_insert_post', $hook, 10, 3 );
		try {
			$result = $machine['dispatcher']->execute(
				'xnetvn/create-post',
				array(
					'title'  => 'Mid-write receipt',
					'tags'   => array( $term_id ),
					'author' => $this->author,
				),
				$machine['context']
			);
		} finally {
			remove_action( 'wp_insert_post', $hook, 10 );
		}

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
		self::assertSame( true, $result->created );
		$created_id = $result->object_id;
		self::assertGreaterThan( 0, $created_id );
		self::assertSame( array( 'created_id' => $created_id ), $result->get_error_data() );
		self::assertStringContainsString( 'created_id=' . $created_id, $result->get_error_message() );
		self::assertStringNotContainsString( $name, $result->get_error_message() );
		self::assertStringNotContainsString( sanitize_title( $name ), $result->get_error_message() );

		$post = get_post( $created_id );
		self::assertInstanceOf( WP_Post::class, $post );
		self::assertSame( $this->author, (int) $post->post_author );
		self::assertSame( array( 0 ), array_values( array_unique( $seen_user ) ) );
		self::assertSame( 0, get_current_user_id() );
		self::assertFalse( get_term_by( 'name', $name, 'post_tag' ) );

		global $wpdb;
		$linked = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
				$created_id,
				$taxonomy_id
			)
		);
		self::assertSame( 0, $linked );
		self::assertSame( $default, (int) get_option( 'default_category' ) );
	}

	/** The machine scope matrix admits only the granted rows and preserves authors. */
	public function test_machine_scope_matrix(): void {
		$first  = $this->make_post( array( 'post_title' => 'Matrix one' ) );
		$second = $this->make_post( array( 'post_title' => 'Matrix two' ) );
		$third  = $this->make_post( array( 'post_title' => 'Matrix three' ) );

		$allowed_names = array(
			'xnetvn/list-post-statuses',
			'xnetvn/get-post',
			'xnetvn/update-post',
			'xnetvn/trash-post',
			'xnetvn/delete-post',
		);
		$granted       = $this->machine( $allowed_names, RiskTier::Destructive );
		$updated       = $granted['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'    => $first,
				'title' => 'Matrix updated',
			),
			$granted['context']
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $first ) );
		$trashed = $granted['dispatcher']->execute( 'xnetvn/trash-post', array( 'id' => $third ), $granted['context'] );
		self::assertIsArray( $trashed, is_wp_error( $trashed ) ? $trashed->get_error_message() : '' );
		self::assertSame( 'trash', get_post_status( $third ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $third ) );

		$deletable = $this->make_post( array( 'post_title' => 'Delete only' ) );
		$deleted   = $granted['dispatcher']->execute( 'xnetvn/delete-post', array( 'id' => $deletable ), $granted['context'] );
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		self::assertNull( get_post( $deletable ) );
		self::assertSame( $this->author, (int) get_post_field( 'post_author', $second ) );

		$without_update = $this->machine( array( 'xnetvn/list-post-statuses', 'xnetvn/trash-post', 'xnetvn/delete-post' ) );
		$status         = get_post_status( $second );
		$denied         = $without_update['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'    => $second,
				'title' => 'Denied',
			),
			$without_update['context']
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
		self::assertSame( $status, get_post_status( $second ) );

		$denylisted = $this->machine( $allowed_names, RiskTier::Destructive, self::BLOG, array( 'xnetvn/update-post' ) );
		$denied     = $denylisted['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'    => $second,
				'title' => 'Denied',
			),
			$denylisted['context']
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
		self::assertSame( $status, get_post_status( $second ) );

		$read_ceiling = $this->machine( array( 'xnetvn/trash-post', 'xnetvn/delete-post' ), RiskTier::Read );
		foreach ( array( 'xnetvn/trash-post', 'xnetvn/delete-post' ) as $name ) {
			$denied = $read_ceiling['dispatcher']->execute( $name, array( 'id' => $second ), $read_ceiling['context'] );
			self::assertInstanceOf( \WP_Error::class, $denied, $name );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code(), $name );
		}
		self::assertNotNull( get_post( $second ) );

		$write_ceiling = $this->machine( array( 'xnetvn/trash-post', 'xnetvn/delete-post' ), RiskTier::Write );
		$denied        = $write_ceiling['dispatcher']->execute( 'xnetvn/delete-post', array( 'id' => $second ), $write_ceiling['context'] );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
		self::assertNotNull( get_post( $second ) );
		$trashed = $write_ceiling['dispatcher']->execute( 'xnetvn/trash-post', array( 'id' => $second ), $write_ceiling['context'] );
		self::assertIsArray( $trashed, is_wp_error( $trashed ) ? $trashed->get_error_message() : '' );
		self::assertSame( 'trash', get_post_status( $second ) );

		$other_blog = $this->machine( $allowed_names, RiskTier::Destructive, self::BLOG + 1 );
		$denied     = $other_blog['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'    => $first,
				'title' => 'Other blog',
			),
			$other_blog['context']
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
		self::assertSame( 'Matrix updated', get_post_field( 'post_title', $first ) );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Parent, template, conflict and future transitions stay inside the review. */
	public function test_parent_template_conflict_and_future(): void {
		$machine = $this->machine();
		$parent  = $this->make_page( array( 'post_title' => 'Parent page' ) );
		$child   = $machine['dispatcher']->execute(
			'xnetvn/create-post',
			array(
				'post_type' => 'page',
				'title'     => 'Child page',
				'parent'    => $parent,
				'author'    => $this->author,
			),
			$machine['context']
		);
		self::assertIsArray( $child, is_wp_error( $child ) ? $child->get_error_message() : '' );
		self::assertSame( $parent, (int) get_post_field( 'post_parent', (int) $child['id'] ) );

		$self_parent = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'        => (int) $child['id'],
				'post_type' => 'page',
				'parent'    => (int) $child['id'],
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $self_parent );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $self_parent->get_error_code() );

		$bad_template = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'        => (int) $child['id'],
				'post_type' => 'page',
				'template'  => 'missing-template.php',
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $bad_template );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $bad_template->get_error_code() );

		$post       = $this->make_post( array( 'post_title' => 'Conflict fixture' ) );
		$post_again = get_post( $post );
		self::assertInstanceOf( WP_Post::class, $post_again );
		$modified = gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $post_again->post_modified_gmt . 'Z' ) );
		$ok       = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'                => $post,
				'title'             => 'Conflict ok',
				'expected_modified' => $modified,
			),
			$machine['context']
		);
		self::assertIsArray( $ok, is_wp_error( $ok ) ? $ok->get_error_message() : '' );
		$conflict = $machine['dispatcher']->execute(
			'xnetvn/update-post',
			array(
				'id'                => $post,
				'title'             => 'Conflict wrong',
				'expected_modified' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
			),
			$machine['context']
		);
		self::assertInstanceOf( \WP_Error::class, $conflict );
		self::assertSame( 'xnetvn_agent_hub_wp_conflict', $conflict->get_error_code() );
		self::assertSame( 'Conflict ok', get_post_field( 'post_title', $post ) );

		$future = $machine['dispatcher']->execute(
			'xnetvn/create-post',
			array(
				'title'  => 'Scheduled',
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 172800 ),
				'author' => $this->author,
			),
			$machine['context']
		);
		self::assertIsArray( $future, is_wp_error( $future ) ? $future->get_error_message() : '' );
		self::assertSame( 'future', get_post_status( (int) $future['id'] ) );
	}

	/** Build one dispatcher, context and budget.
	 *
	 * @param array<string>       $allowed Allowed names.
	 * @param RiskTier            $tier Key ceiling.
	 * @param int                 $blog_id Credential blog.
	 * @param array<string>       $denied Denied names.
	 * @param MemorySettings|null $settings Optional settings.
	 * @return array{dispatcher:SiteDispatcher,context:SiteAuthContext,budget:SiteContentIntegrationBudget}
	 */
	private function machine( array $allowed = SiteContentOperations::NAMES, RiskTier $tier = RiskTier::Destructive, int $blog_id = self::BLOG, array $denied = array(), ?MemorySettings $settings = null ): array {
		$settings   = $settings ?? new MemorySettings();
		$catalog    = new SiteContentIntegrationCatalog( $this->content_definitions() );
		$budget     = new SiteContentIntegrationBudget();
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		$scope      = new SiteScope(
			$tier,
			array( 'content' ),
			$allowed,
			$denied,
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), $blog_id, 'Integration', $scope, new DateTimeImmutable( '@' . time() ) );
		return array(
			'dispatcher' => $dispatcher,
			'context'    => new SiteAuthContext( $credential, '0fa0fa0fa0fa0fa0', '203.0.113.7' ),
			'budget'     => $budget,
		);
	}

	/**
	 * Reviewed content definitions by name.
	 *
	 * @return array<string,AbilitySpec>
	 */
	private function content_definitions(): array {
		$definitions = array();
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$definitions[ $definition->descriptor()->name ] = $definition;
		}
		return $definitions;
	}

	/**
	 * Create one published post owned by the fixture author.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return int
	 */
	private function make_post( array $overrides = array() ): int {
		$defaults = array(
			'post_title'   => 'Fixture post ' . wp_rand(),
			'post_content' => 'Fixture body',
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $this->author,
		);
		$id       = wp_insert_post( array_merge( $defaults, $overrides ), true );
		self::assertIsInt( $id );
		return $id;
	}

	/**
	 * Create one published page owned by the fixture author.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return int
	 */
	private function make_page( array $overrides = array() ): int {
		return $this->make_post( array_merge( array( 'post_type' => 'page' ), $overrides ) );
	}

	/**
	 * Create one non-default post tag.
	 *
	 * @param string $name Term name.
	 * @return int
	 */
	private function make_term( string $name ): int {
		$term = wp_insert_term( $name, 'post_tag' );
		self::assertIsArray( $term );
		return (int) $term['term_id'];
	}
}
