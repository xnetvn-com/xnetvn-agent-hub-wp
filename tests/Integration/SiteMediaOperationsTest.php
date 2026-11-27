<?php
/**
 * Machine media operations against real attachments, uploads and policy rows.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test-only catalog and budget doubles.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert persisted rows directly.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Owned test fixtures.

use Closure;
use DateTimeImmutable;
use WP_Post;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\SiteMediaOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\Registry;
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
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Catalog returning the reviewed media definitions. */
final class SiteMediaIntegrationCatalog implements AbilityCatalog {
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
final class SiteMediaIntegrationBudget implements ExpensiveBudget {
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

/** Exercises the accountless media contract against real core storage. */
final class SiteMediaOperationsTest extends WP_UnitTestCase {
	/** Current blog. */
	private const BLOG = 1;
	/** One-pixel PNG fixture. */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
	/** Document bytes refused by the image extension. */
	private const PDF = 'JVBERi0xLjQKMSAwIG9iajw8Pj5lbmRvYmoKdHJhaWxlcjw8Pj4KJSVFT0YK';
	/** Size-generating PNG fixture, 200 by 200 pixels. */
	private const LARGE = 'iVBORw0KGgoAAAANSUhEUgAAAMgAAADICAIAAAAiOjnJAAABh0lEQVR42u3bMREAQAgEMaQg7aXiDAx8B2WYKLjZlshXcC5MgLAQFsICYSEshAXCQlgI66sXZ1xhCQthISyEJSyEhbAQlrAQFsJCWMJCWAgLYQkLYSEshCUshIWwEJaw8EyBsBAWCAthISwQFsJCWCAshIWwQFgIC2GBsBAWwgJhISyEBcJCWAgLhIWwEBYIC2EhLBAWwkJYICyEhbBAWAgLYYGwEBbCAmEhLIQFwkJYCAuEhbAQFggLYSEsEBbCQlggLISFsEBYCAthISwrICyEhbBAWAgLYYGwEBbCAmEhLIQFwkJYCAuEhbAQFggLYSEsEBbCQlggLISFsEBYCAthgbAQFsICYSEshAXCQlgIC4SFsBAWCAthISwQFsJCWCAshIWwQFgIC2GBsBAWwgJhISyEBcJCWAgLhIWwEBYIC2EhLBAWwkJYICyEhbBAWAgLYYGwEBbCAmEhLISFsEyAsBAWwgJhISyEBcJCWAgLhIWwEBYIC2EhLBAWwkJYICyEhbBgaQDM8BLrkveYxAAAAABJRU5ErkJggg==';

	/** Created attachment IDs.
	 *
	 * @var array<int>
	 */
	private array $attachments = array();
	/** Created post IDs.
	 *
	 * @var array<int>
	 */
	private array $posts = array();
	/** Owned file paths.
	 *
	 * @var array<string>
	 */
	private array $files = array();
	/** Registered fixture post type.
	 *
	 * @var bool
	 */
	private bool $registered_book = false;

	/** Prepare the real users and the guest machine state. */
	public function set_up(): void {
		parent::set_up();
		self::assertSame( 'banned', RoleGuard::ensure_banned_role() );
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->user->create( array( 'role' => 'banned' ) );
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), (int) $editor, 'editor' );
		}
		wp_set_current_user( 0 );
	}

	/** Remove every owned attachment, post and file. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		if ( $this->registered_book ) {
			unregister_post_type( 'fr17_book' );
		}
		$this->attachments     = array();
		$this->posts           = array();
		$this->files           = array();
		$this->registered_book = false;
		remove_all_filters( 'wp_insert_post_empty_content' );
		remove_all_filters( 'wp_update_attachment_metadata' );
		parent::tear_down();
	}

	/** The seven names resolve through the seam and the boot order is explicit. */
	public function test_names_and_registration_seam(): void {
		self::assertCount( 7, SiteMediaOperations::NAMES );
		global $wp_filter;
		self::assertArrayHasKey( 'wp_abilities_api_init', $wp_filter );
		self::assertArrayHasKey( 'mcp_adapter_init', $wp_filter );
		$abilities = $wp_filter['wp_abilities_api_init']->callbacks;
		self::assertArrayHasKey( 9, $abilities );
		$closure = false;
		foreach ( $abilities[9] as $entry ) {
			if ( $entry['function'] instanceof Closure ) {
				$closure = true;
			}
		}
		self::assertTrue( $closure, 'The plugin registers its media handlers at priority 9.' );
		self::assertArrayHasKey( 10, $abilities );
		$registry = false;
		foreach ( $abilities[10] as $entry ) {
			$callback = $entry['function'];
			if ( is_array( $callback ) && $callback[0] instanceof Registry && 'register_abilities' === $callback[1] ) {
				$registry = true;
			}
		}
		self::assertTrue( $registry, 'The registry freezes after the media handlers are registered.' );
		$adapter = $wp_filter['mcp_adapter_init']->callbacks;
		self::assertArrayHasKey( 9, $adapter );
		$initializer = false;
		foreach ( $adapter[9] as $entry ) {
			if ( $entry['function'] instanceof Closure ) {
				$initializer = true;
			}
		}
		self::assertTrue( $initializer, 'The plugin initializes abilities before the gateway snapshots the site scope.' );
		self::assertArrayHasKey( 10, $adapter );
		$gateway = false;
		foreach ( $adapter[10] as $entry ) {
			$callback = $entry['function'];
			if ( is_array( $callback ) && $callback[0] instanceof ServerGateway && 'create' === $callback[1] ) {
				$gateway = true;
			}
		}
		self::assertTrue( $gateway );

		$machine = $this->machine();
		$names   = $this->scoped_names( $machine['dispatcher'], $machine['scope'] );
		self::assertCount( 7, $names );
		self::assertEqualsCanonicalizing( SiteMediaOperations::NAMES, $names );
		$content = new SiteScope(
			RiskTier::Destructive,
			array( 'content' ),
			array( 'xnetvn/list-posts' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		self::assertSame( array(), $this->scoped_names( $machine['dispatcher'], $content ) );
	}

	/** Upload, read, update and delete one machine attachment. */
	public function test_lifecycle_persists_and_keeps_the_user_at_zero(): void {
		$machine = $this->machine();
		$author  = $this->editor();
		$parent  = $this->make_page();

		$uploaded = $machine['dispatcher']->execute(
			'xnetvn/upload-media-from-data',
			array(
				'author'      => $author,
				'filename'    => 'fr17-lifecycle.png',
				'mime_type'   => 'image/png',
				'data_base64' => self::PNG,
				'title'       => 'FR17 <script>alert(1)</script>',
				'alt_text'    => 'FR17 alt',
				'caption'     => 'FR17 caption',
				'description' => 'FR17 description',
				'post'        => $parent,
			),
			$machine['context']
		);
		self::assertIsArray( $uploaded, is_wp_error( $uploaded ) ? $uploaded->get_error_message() : '' );
		$id                  = (int) $uploaded['id'];
		$this->attachments[] = $id;
		$stored              = get_post( $id );
		self::assertInstanceOf( WP_Post::class, $stored );
		self::assertSame( 'attachment', $stored->post_type );
		self::assertSame( $author, (int) $stored->post_author );
		self::assertSame( $parent, (int) $stored->post_parent );
		self::assertStringNotContainsString( '<script', $stored->post_title );
		self::assertSame( 'FR17 description', $stored->post_content );
		self::assertSame( 'FR17 caption', $stored->post_excerpt );
		self::assertSame( 'FR17 alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$file = get_attached_file( $id );
		self::assertIsString( $file );
		self::assertFileExists( $file );
		$this->files[] = $file;
		self::assertNotEmpty( wp_get_attachment_metadata( $id ) );
		self::assertSame( 0, get_current_user_id() );

		$read = $machine['dispatcher']->execute( 'xnetvn/get-media', array( 'id' => $id ), $machine['context'] );
		self::assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		self::assertSame( $author, $read['author'] );
		self::assertSame( 'FR17 alt', $read['alt_text'] );
		self::assertSame( 'image/png', $read['mime_type'] );

		$listed = $machine['dispatcher']->execute( 'xnetvn/list-media', array( 'per_page' => 100 ), $machine['context'] );
		self::assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_message() : '' );
		self::assertContains( $id, array_column( $listed['items'], 'id' ) );

		$updated = $machine['dispatcher']->execute(
			'xnetvn/update-media',
			array(
				'id'          => $id,
				'title'       => 'FR17 updated',
				'alt_text'    => 'FR17 updated alt',
				'description' => 'FR17 updated body',
				'post'        => 0,
			),
			$machine['context']
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		self::assertSame( 'FR17 updated', (string) get_post_field( 'post_title', $id ) );
		self::assertSame( 'FR17 updated body', (string) get_post_field( 'post_content', $id ) );
		self::assertSame( 'FR17 updated alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		self::assertSame( 0, (int) get_post_field( 'post_parent', $id ) );
		self::assertSame( $author, (int) get_post_field( 'post_author', $id ) );
		self::assertSame( 0, get_current_user_id() );

		$deleted = $machine['dispatcher']->execute( 'xnetvn/delete-media', array( 'id' => $id ), $machine['context'] );
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		self::assertTrue( $deleted['deleted'] );
		self::assertNull( get_post( $id ) );
		self::assertFalse( file_exists( $file ) );
		self::assertSame( 0, get_current_user_id() );
	}

	/** Delete removes the original and every generated size file. */
	public function test_delete_removes_the_original_and_every_size(): void {
		$machine  = $this->machine();
		$uploaded = $machine['dispatcher']->execute(
			'xnetvn/upload-media-from-data',
			array(
				'author'      => $this->editor(),
				'filename'    => 'fr17-large.png',
				'mime_type'   => 'image/png',
				'data_base64' => self::LARGE,
			),
			$machine['context']
		);
		self::assertIsArray( $uploaded, is_wp_error( $uploaded ) ? $uploaded->get_error_message() : '' );
		$id                  = (int) $uploaded['id'];
		$this->attachments[] = $id;
		$metadata            = wp_get_attachment_metadata( $id );
		self::assertIsArray( $metadata );
		self::assertNotEmpty( $metadata['sizes'] );
		$original = get_attached_file( $id );
		self::assertIsString( $original );
		$captured = array( $original );
		foreach ( $metadata['sizes'] as $size ) {
			$captured[] = dirname( $original ) . '/' . $size['file'];
		}
		$this->files = array_merge( $this->files, $captured );
		foreach ( $captured as $file ) {
			self::assertFileExists( $file );
		}

		$deleted = $machine['dispatcher']->execute( 'xnetvn/delete-media', array( 'id' => $id ), $machine['context'] );
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->get_error_message() : '' );
		self::assertTrue( $deleted['deleted'] );
		foreach ( $captured as $file ) {
			self::assertFalse( file_exists( $file ), $file );
		}
	}

	/** Every refusal keeps zero unauthorized state and the user at zero. */
	public function test_refusals_leave_no_state(): void {
		$machine = $this->machine();
		$limited = $this->machine( SiteMediaOperations::NAMES, RiskTier::Destructive, self::BLOG, array(), array( 'media' ), new MemorySettings( array( 'media_data_max_bytes' => 1024 ) ) );
		$editor  = $this->editor();
		$admin   = $this->admin();
		$banned  = $this->banned();
		$foreign = $this->make_attachment( $admin );
		$update  = $this->make_attachment( $editor );
		$trashed = $this->make_post();
		wp_trash_post( $trashed );
		$this->register_book();
		$book   = $this->make_post( 'fr17_book' );
		$before = $this->snapshot();

		$cases = array(
			'missing-author' => array( $machine, 'xnetvn/upload-media-from-data', $this->data_arguments( $editor, array( 'author' => null ) ), 'xnetvn_agent_hub_wp_invalid_params' ),
			'author-admin'   => array( $machine, 'xnetvn/upload-media-from-data', $this->data_arguments( $admin ), 'xnetvn_agent_hub_wp_forbidden' ),
			'author-banned'  => array( $machine, 'xnetvn/upload-media-from-data', $this->data_arguments( $banned ), 'xnetvn_agent_hub_wp_forbidden' ),
			'wrong-bytes'    => array( $machine, 'xnetvn/upload-media-from-data', $this->data_arguments( $editor, array( 'data_base64' => self::PDF ) ), 'xnetvn_agent_hub_wp_file_type_not_allowed' ),
			'oversized'      => array( $limited, 'xnetvn/upload-media-from-data', $this->data_arguments( $editor, array( 'data_base64' => base64_encode( str_repeat( 'a', 2048 ) ) ) ), 'xnetvn_agent_hub_wp_payload_too_large' ),
			'remote-off'     => array(
				$machine,
				'xnetvn/upload-media-from-url',
				array(
					'author' => $editor,
					'url'    => 'https://example.test/pixel.png',
				),
				'xnetvn_agent_hub_wp_remote_fetch_disabled',
			),
			'parent-type'    => array(
				$machine,
				'xnetvn/update-media',
				array(
					'id'   => $update,
					'post' => $book,
				),
				'xnetvn_agent_hub_wp_post_type_unavailable',
			),
			'parent-trash'   => array(
				$machine,
				'xnetvn/update-media',
				array(
					'id'   => $update,
					'post' => $trashed,
				),
				'xnetvn_agent_hub_wp_invalid_state',
			),
			'protected-upd'  => array(
				$machine,
				'xnetvn/update-media',
				array(
					'id'    => $foreign,
					'title' => 'No',
				),
				'xnetvn_agent_hub_wp_forbidden',
			),
			'protected-del'  => array( $machine, 'xnetvn/delete-media', array( 'id' => $foreign ), 'xnetvn_agent_hub_wp_forbidden' ),
			'raw-list'       => array( $machine, 'xnetvn/list-media', array( 'content_format' => 'raw' ), 'xnetvn_agent_hub_wp_forbidden' ),
			'raw-get'        => array(
				$machine,
				'xnetvn/get-media',
				array(
					'id'             => $foreign,
					'content_format' => 'raw',
				),
				'xnetvn_agent_hub_wp_forbidden',
			),
		);
		foreach ( $cases as $label => $case ) {
			$result = $case[0]['dispatcher']->execute( $case[1], $case[2], $case[0]['context'] );
			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( $case[3], $result->get_error_code(), $label );
			self::assertNotInstanceOf( PartialFailure::class, $result, $label );
			self::assertSame( 0, get_current_user_id(), $label );
		}

		self::assertSame( $before, $this->snapshot() );
	}

	/** A failed insert deletes exactly the file it wrote. */
	public function test_insert_failure_deletes_the_written_file(): void {
		$machine   = $this->machine();
		$editor    = $this->editor();
		$unrelated = $this->write_unrelated();
		$before    = $this->count_attachments();
		$filter    = static function ( bool $maybe_empty, array $postarr ): bool {
			$title = isset( $postarr['post_title'] ) && is_string( $postarr['post_title'] ) ? $postarr['post_title'] : '';
			return str_contains( $title, 'fr17-insert-fail' ) ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $filter, 10, 2 );
		try {
			$result = $machine['dispatcher']->execute(
				'xnetvn/upload-media-from-data',
				$this->data_arguments(
					$editor,
					array(
						'filename' => 'fr17-insert-fail.png',
						'title'    => 'fr17-insert-fail',
					)
				),
				$machine['context']
			);
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $filter, 10 );
		}
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
		self::assertSame( $before, $this->count_attachments(), 'No attachment row persists.' );
		self::assertSame( array(), $this->upload_files( 'fr17-insert-fail' ) );
		self::assertFileExists( $unrelated );
		self::assertSame( 0, get_current_user_id() );
	}

	/** A failed metadata write keeps the row and returns the created receipt. */
	public function test_metadata_failure_returns_a_partial_receipt(): void {
		$machine = $this->machine();
		$filter  = static function ( array $data, int $attachment_id ): array {
			if ( 'fr17-meta-fail' === (string) get_post_field( 'post_title', $attachment_id ) ) {
				$data['width'] = (int) ( $data['width'] ?? 0 ) + 1;
			}
			return $data;
		};
		add_filter( 'wp_update_attachment_metadata', $filter, 10, 2 );
		try {
			$result = $machine['dispatcher']->execute(
				'xnetvn/upload-media-from-data',
				$this->data_arguments(
					$this->editor(),
					array(
						'filename' => 'fr17-meta-fail.png',
						'title'    => 'fr17-meta-fail',
					)
				),
				$machine['context']
			);
		} finally {
			remove_filter( 'wp_update_attachment_metadata', $filter, 10 );
		}
		self::assertInstanceOf( PartialFailure::class, $result );
		self::assertTrue( $result->created );
		$id                  = $result->object_id;
		$this->attachments[] = $id;
		self::assertGreaterThan( 0, $id );
		self::assertSame( array( 'created_id' => $id ), $result->get_error_data() );
		self::assertInstanceOf( WP_Post::class, get_post( $id ) );
		$file = get_attached_file( $id );
		self::assertIsString( $file );
		self::assertFileExists( $file );
		$this->files[] = $file;
		self::assertStringNotContainsString( $this->uploads_basedir(), $result->get_error_message() );
		self::assertStringNotContainsString( 'fr17-meta-fail', $result->get_error_message() );
		self::assertSame( 0, get_current_user_id() );
	}

	/** The machine matrix admits only the granted media scope. */
	public function test_machine_scope_matrix(): void {
		$editor = $this->editor();
		$reader = $this->machine();
		$list   = $reader['dispatcher']->execute( 'xnetvn/list-media', array(), $reader['context'] );
		self::assertIsArray( $list, is_wp_error( $list ) ? $list->get_error_message() : '' );

		$attachment = $this->make_attachment( $editor );
		$toolset    = $this->machine( SiteMediaOperations::NAMES, RiskTier::Destructive, self::BLOG, array(), array( 'content' ) );
		$denied     = $toolset['dispatcher']->execute( 'xnetvn/list-media', array(), $toolset['context'] );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $this->denied_code( $denied ) );

		$read_ceiling = $this->machine( SiteMediaOperations::NAMES, RiskTier::Read );
		$allowed      = $read_ceiling['dispatcher']->execute( 'xnetvn/list-media', array(), $read_ceiling['context'] );
		self::assertIsArray( $allowed, is_wp_error( $allowed ) ? $allowed->get_error_message() : '' );
		$before = $this->snapshot();
		$rows   = array(
			array(
				'xnetvn/update-media',
				array(
					'id'    => $attachment,
					'title' => 'Denied',
				),
			),
			array( 'xnetvn/upload-media-from-data', $this->data_arguments( $editor ) ),
			array(
				'xnetvn/upload-media-from-url',
				array(
					'author' => $editor,
					'url'    => 'https://example.test/pixel.png',
				),
			),
			array( 'xnetvn/delete-media', array( 'id' => $attachment ) ),
		);
		foreach ( $rows as $row ) {
			$denied = $read_ceiling['dispatcher']->execute( $row[0], $row[1], $read_ceiling['context'] );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $this->denied_code( $denied ), $row[0] );
			self::assertSame( 'The tool is unavailable.', $this->denied_message( $denied ), $row[0] );
		}
		self::assertSame( $before, $this->snapshot() );

		$other_blog = $this->machine( SiteMediaOperations::NAMES, RiskTier::Destructive, self::BLOG + 1 );
		$denied     = $other_blog['dispatcher']->execute( 'xnetvn/list-media', array(), $other_blog['context'] );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $this->denied_code( $denied ) );

		$without_delete = $this->machine( array_values( array_diff( SiteMediaOperations::NAMES, array( 'xnetvn/delete-media' ) ) ) );
		$allowed        = $without_delete['dispatcher']->execute( 'xnetvn/list-media', array(), $without_delete['context'] );
		self::assertIsArray( $allowed, is_wp_error( $allowed ) ? $allowed->get_error_message() : '' );
		$before = $this->snapshot();
		$denied = $without_delete['dispatcher']->execute( 'xnetvn/delete-media', array( 'id' => $attachment ), $without_delete['context'] );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $this->denied_code( $denied ) );
		self::assertSame( $before, $this->snapshot() );

		$granted = $this->machine();
		$updated = $granted['dispatcher']->execute(
			'xnetvn/update-media',
			array(
				'id'    => $attachment,
				'title' => 'Granted',
			),
			$granted['context']
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		self::assertSame( 'Granted', (string) get_post_field( 'post_title', $attachment ) );
		$uploaded = $granted['dispatcher']->execute( 'xnetvn/upload-media-from-data', $this->data_arguments( $editor ), $granted['context'] );
		self::assertIsArray( $uploaded, is_wp_error( $uploaded ) ? $uploaded->get_error_message() : '' );
		$this->attachments[] = (int) $uploaded['id'];
		self::assertSame( $editor, (int) get_post_field( 'post_author', (int) $uploaded['id'] ) );
		self::assertSame( 0, get_current_user_id() );
	}

	/** On multisite, exhausted space is refused before decoding or writing. */
	public function test_multisite_quota_precedes_decode(): void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Multisite quota row: set XNETVN_TEST_MULTISITE=1 on wp69.' );
		}
		$previous_space   = get_option( 'blog_upload_space' );
		$previous_checked = get_site_option( 'upload_space_check_disabled' );
		update_option( 'blog_upload_space', 0 );
		update_site_option( 'upload_space_check_disabled', 0 );
		try {
			$machine = $this->machine();
			$before  = $this->snapshot();
			$result  = $machine['dispatcher']->execute( 'xnetvn/upload-media-from-data', $this->data_arguments( $this->editor() ), $machine['context'] );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_quota_exceeded', $result->get_error_code() );
			self::assertSame( $before, $this->snapshot() );
		} finally {
			update_option( 'blog_upload_space', $previous_space );
			update_site_option( 'upload_space_check_disabled', $previous_checked );
		}
	}

	/**
	 * Build one dispatcher, context and scope.
	 *
	 * @param array<string>       $allowed Allowed names.
	 * @param RiskTier            $tier Key ceiling.
	 * @param int                 $blog_id Credential blog.
	 * @param array<string>       $denied Denied names.
	 * @param array<string>       $toolsets Enabled toolsets.
	 * @param MemorySettings|null $settings Optional settings.
	 * @return array{dispatcher:SiteDispatcher,context:SiteAuthContext,scope:SiteScope,budget:SiteMediaIntegrationBudget}
	 */
	private function machine( array $allowed = SiteMediaOperations::NAMES, RiskTier $tier = RiskTier::Destructive, int $blog_id = self::BLOG, array $denied = array(), array $toolsets = array( 'media' ), ?MemorySettings $settings = null ): array {
		$settings   = $settings ?? new MemorySettings();
		$catalog    = new SiteMediaIntegrationCatalog( $this->definitions() );
		$budget     = new SiteMediaIntegrationBudget();
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		$operations = new SiteMediaOperations( $settings );
		$dispatcher->register( 'xnetvn/list-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/list-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/get-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/get-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/update-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/update-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/upload-media-from-url', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/upload-media-from-url', $args, $context ) );
		$dispatcher->register( 'xnetvn/upload-media-from-data', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/upload-media-from-data', $args, $context ) );
		$dispatcher->register( 'xnetvn/delete-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/delete-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/get-media-upload-limits', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/get-media-upload-limits', $args, $context ) );
		$scope      = new SiteScope(
			$tier,
			$toolsets,
			array_values( $allowed ),
			$denied,
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential( 1, new KeyId( str_repeat( 'a', 12 ) ), $blog_id, 'Integration', $scope, new DateTimeImmutable( '@' . time() ) );
		return array(
			'dispatcher' => $dispatcher,
			'context'    => new SiteAuthContext( $credential, str_repeat( 'f', 16 ), '203.0.113.7' ),
			'scope'      => $scope,
			'budget'     => $budget,
		);
	}

	/**
	 * Resolve the site-scoped names of one bound dispatcher.
	 *
	 * @param SiteDispatcher $dispatcher Bound dispatcher.
	 * @param SiteScope      $scope Machine scope.
	 * @return array<string>
	 */
	private function scoped_names( SiteDispatcher $dispatcher, SiteScope $scope ): array {
		$settings = new MemorySettings();
		$policy   = new SitePolicy( $settings );
		$output   = new OutputPipeline( $settings );
		$registry = new Registry( new Policy( $settings ), new RequestScope(), $output, null, null, $policy, $dispatcher );
		foreach ( $this->definitions() as $definition ) {
			$registry->add( $definition );
		}
		return $registry->names_for_site_scope( $scope );
	}

	/**
	 * Reviewed media definitions by name.
	 *
	 * @return array<string,AbilitySpec>
	 */
	private function definitions(): array {
		$definitions = array();
		foreach ( ( new MediaPack( new DefinitionFactory(), new MemorySettings() ) )->definitions() as $definition ) {
			$definitions[ $definition->descriptor()->name ] = $definition;
		}
		return $definitions;
	}

	/**
	 * Create one published attachment owned by the author.
	 *
	 * @param int $author Stored author.
	 * @return int
	 */
	private function make_attachment( int $author ): int {
		$bytes = base64_decode( self::PNG, true );
		self::assertIsString( $bytes );
		$upload = wp_upload_bits( 'fr17-own-' . wp_rand() . '.png', null, $bytes );
		self::assertIsArray( $upload );
		self::assertSame( '', (string) ( $upload['error'] ?? '' ) );
		$file = (string) $upload['file'];
		$id   = wp_insert_attachment(
			array(
				'post_title'     => 'FR17 owned ' . wp_rand(),
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_author'    => $author,
			),
			$file,
			0,
			true
		);
		self::assertIsInt( $id );
		$this->attachments[] = $id;
		$this->files[]       = $file;
		return $id;
	}

	/**
	 * Create one published page owned by the fixture editor.
	 *
	 * @return int
	 */
	private function make_page(): int {
		return $this->make_post( 'page' );
	}

	/**
	 * Create one published post of one type.
	 *
	 * @param string $type Post type.
	 * @return int
	 */
	private function make_post( string $type = 'post' ): int {
		$id = wp_insert_post(
			array(
				'post_title'   => 'FR17 parent ' . wp_rand(),
				'post_content' => 'FR17 parent',
				'post_status'  => 'publish',
				'post_type'    => $type,
				'post_author'  => $this->editor(),
			),
			true
		);
		self::assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	/** Register a parent type whose capability base is outside post and page. */
	private function register_book(): void {
		if ( $this->registered_book ) {
			return;
		}
		register_post_type(
			'fr17_book',
			array(
				'label'           => 'FR17 Book',
				'public'          => false,
				'capability_type' => 'book',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor' ),
			)
		);
		$this->registered_book = true;
	}

	/**
	 * Valid data-upload arguments.
	 *
	 * @param int                 $author Attribution target.
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function data_arguments( int $author, array $overrides = array() ): array {
		return array_merge(
			array(
				'author'      => $author,
				'filename'    => 'fr17-pixel.png',
				'mime_type'   => 'image/png',
				'data_base64' => self::PNG,
			),
			$overrides
		);
	}

	/** Current editor identity. */
	private function editor(): int {
		$users = get_users(
			array(
				'role'   => 'editor',
				'number' => 1,
			)
		);
		self::assertNotEmpty( $users );
		return (int) $users[0]->ID;
	}

	/** Current administrator identity. */
	private function admin(): int {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		self::assertNotEmpty( $users );
		return (int) $users[0]->ID;
	}

	/** Banned identity. */
	private function banned(): int {
		$users = get_users(
			array(
				'role'   => 'banned',
				'number' => 1,
			)
		);
		self::assertNotEmpty( $users );
		return (int) $users[0]->ID;
	}

	/** Write one unrelated uploads file and keep it for the assertion. */
	private function write_unrelated(): string {
		$upload = wp_upload_bits( 'fr17-unrelated.png', null, 'unrelated' );
		self::assertIsArray( $upload );
		$this->files[] = (string) $upload['file'];
		return (string) $upload['file'];
	}

	/** Absolute uploads base directory. */
	private function uploads_basedir(): string {
		$uploads = wp_get_upload_dir();
		return (string) $uploads['basedir'];
	}

	/**
	 * Owned uploads files whose name starts with the prefix.
	 *
	 * @param string $prefix Filename prefix.
	 * @return array<string>
	 */
	private function upload_files( string $prefix ): array {
		$files     = array();
		$directory = $this->uploads_basedir();
		if ( ! is_dir( $directory ) ) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry instanceof \SplFileInfo && $entry->isFile() && str_starts_with( $entry->getFilename(), $prefix ) ) {
				$files[] = $entry->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/** Count stored attachments. */
	private function count_attachments(): int {
		$counts = (array) wp_count_posts( 'attachment' );
		$total  = 0;
		foreach ( $counts as $count ) {
			$total += (int) $count;
		}
		return $total;
	}

	/**
	 * Immutable state snapshot for the zero-change assertions.
	 *
	 * @return array<string,mixed>
	 */
	private function snapshot(): array {
		global $wpdb;
		$attachments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		$meta        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		return array(
			'attachments' => $attachments,
			'meta'        => $meta,
			'files'       => $this->upload_files( 'fr17-' ),
		);
	}

	/**
	 * Error code of a denied dispatch.
	 *
	 * @param mixed $result Dispatcher result.
	 * @return string
	 */
	private function denied_code( mixed $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : 'not-an-error';
	}

	/**
	 * Error message of a denied dispatch.
	 *
	 * @param mixed $result Dispatcher result.
	 * @return string
	 */
	private function denied_message( mixed $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_message() : '';
	}
}
