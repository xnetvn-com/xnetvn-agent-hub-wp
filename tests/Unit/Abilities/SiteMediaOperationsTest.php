<?php
/**
 * Machine media operations, the fixed parent class and the dispatcher seam.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test-only WordPress doubles, fixtures, real temporary files and fixed handler signatures.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\SiteMediaOperations;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
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
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal stored attachment used by the machine media doubles. */
final class SiteMediaPost {
	/** Post ID.
	 *
	 * @var int
	 */
	public int $ID = 0;
	/** Post type.
	 *
	 * @var string
	 */
	public string $post_type = 'attachment';
	/** Post status.
	 *
	 * @var string
	 */
	public string $post_status = 'inherit';
	/** Stored author.
	 *
	 * @var int
	 */
	public int $post_author = 0;
	/** Stored title.
	 *
	 * @var string
	 */
	public string $post_title = '';
	/** Stored body.
	 *
	 * @var string
	 */
	public string $post_content = '';
	/** Stored excerpt.
	 *
	 * @var string
	 */
	public string $post_excerpt = '';
	/** Stored slug.
	 *
	 * @var string
	 */
	public string $post_name = '';
	/** Local creation date.
	 *
	 * @var string
	 */
	public string $post_date = '2026-01-01 00:00:00';
	/** UTC creation date.
	 *
	 * @var string
	 */
	public string $post_date_gmt = '2026-01-01 00:00:00';
	/** Local modification date.
	 *
	 * @var string
	 */
	public string $post_modified = '2026-01-01 00:00:00';
	/** UTC modification date.
	 *
	 * @var string
	 */
	public string $post_modified_gmt = '2026-01-01 00:00:00';
	/** Parent ID.
	 *
	 * @var int
	 */
	public int $post_parent = 0;
	/** Stored MIME type.
	 *
	 * @var string
	 */
	public string $post_mime_type = 'image/png';
	/** Stored GUID.
	 *
	 * @var string
	 */
	public string $guid = '';

	/**
	 * Build one stored post.
	 *
	 * @param array<string,mixed> $data Stored values.
	 */
	public function __construct( array $data = array() ) {
		foreach ( $data as $key => $value ) {
			if ( property_exists( $this, (string) $key ) ) {
				$this->{$key} = $value;
			}
		}
	}
}

/** Minimal post type with the reviewed capability object. */
final class SiteMediaPostType {
	/** Type key.
	 *
	 * @var string
	 */
	public string $name;
	/** Display label.
	 *
	 * @var string
	 */
	public string $label;
	/** Label object.
	 *
	 * @var object
	 */
	public object $labels;
	/** Capability base.
	 *
	 * @var string|array<string>
	 */
	public $capability_type = 'post';
	/** Meta-capability mapping.
	 *
	 * @var bool
	 */
	public bool $map_meta_cap = true;
	/** Capability object.
	 *
	 * @var object
	 */
	public object $cap;
	/** Whether the type is hierarchical.
	 *
	 * @var bool
	 */
	public bool $hierarchical = false;
	/** REST base.
	 *
	 * @var string
	 */
	public string $rest_base = '';

	/**
	 * Build one post type.
	 *
	 * @param string               $name Type key.
	 * @param array<string,string> $caps Capability map.
	 * @param string|array<string> $base Capability base.
	 * @param bool                 $map Whether meta caps are mapped.
	 * @param bool                 $hierarchical Whether hierarchical.
	 */
	public function __construct( string $name, array $caps, $base = 'post', bool $map = true, bool $hierarchical = false ) {
		$this->name            = $name;
		$this->label           = ucfirst( $name );
		$this->labels          = (object) array( 'name' => $this->label );
		$this->rest_base       = $name;
		$this->capability_type = $base;
		$this->map_meta_cap    = $map;
		$this->cap             = (object) $caps;
		$this->hierarchical    = $hierarchical;
	}

	/**
	 * Return the reviewed core-default map for one base.
	 *
	 * @param string $base Base post type.
	 * @return array<string,string>
	 */
	public static function core_map( string $base = 'post' ): array {
		$plural = 'post' === $base ? 'posts' : 'pages';
		return array(
			'edit_post'              => 'edit_' . $base,
			'read_post'              => 'read_' . $base,
			'delete_post'            => 'delete_' . $base,
			'edit_posts'             => 'edit_' . $plural,
			'edit_others_posts'      => 'edit_others_' . $plural,
			'delete_posts'           => 'delete_' . $plural,
			'publish_posts'          => 'publish_' . $plural,
			'read_private_posts'     => 'read_private_' . $plural,
			'read'                   => 'read',
			'delete_private_posts'   => 'delete_private_' . $plural,
			'delete_published_posts' => 'delete_published_' . $plural,
			'delete_others_posts'    => 'delete_others_' . $plural,
			'edit_private_posts'     => 'edit_private_' . $plural,
			'edit_published_posts'   => 'edit_published_' . $plural,
			// WordPress core always appends this slot; see wp-includes/post.php:2028-2031.
			'create_posts'           => 'edit_' . $plural,
		);
	}
}

/** Minimal attribution target. */
final class SiteMediaUser {
	/** User ID.
	 *
	 * @var int
	 */
	public int $ID = 0;
	/** Display name.
	 *
	 * @var string
	 */
	public string $display_name = '';
	/** Assigned roles.
	 *
	 * @var array<string>
	 */
	public array $roles = array();
	/** Direct capabilities.
	 *
	 * @var array<string,bool>
	 */
	public array $caps = array();

	/**
	 * Build one user.
	 *
	 * @param int                $id User ID.
	 * @param array<string>      $roles Roles.
	 * @param array<string,bool> $caps Direct capabilities.
	 * @param string             $name Display name.
	 */
	public function __construct( int $id, array $roles = array( 'editor' ), array $caps = array(), string $name = 'Editor' ) {
		$this->ID           = $id;
		$this->roles        = $roles;
		$this->caps         = $caps;
		$this->display_name = $name;
	}
}

/** Minimal roles registry used by the eligibility guard. */
final class SiteMediaRoles {
	/** Role definitions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $roles = array(
		'editor' => array( 'capabilities' => array( 'edit_posts' => true ) ),
		'banned' => array( 'capabilities' => array() ),
	);
}

/** Minimal database error holder. */
final class SiteMediaWpdb {
	/** Last observable database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
}

/** Minimal query double capturing the reviewed arguments. */
final class SiteMediaQuery {
	/** Returned posts.
	 *
	 * @var array<SiteMediaPost>
	 */
	public array $posts = array();
	/** Reported total.
	 *
	 * @var int
	 */
	public int $found_posts = 0;
	/** Captured query arguments.
	 *
	 * @var array<string,mixed>
	 */
	public array $query_vars = array();

	/**
	 * Capture the request and return the configured rows.
	 *
	 * @param array<string,mixed> $query Query arguments.
	 */
	public function __construct( array $query = array() ) {
		$this->query_vars = $query;
		$world            = SiteMediaWorld::$current;
		if ( $world instanceof SiteMediaWorld ) {
			$world->record( 'query' );
			$world->last_query = $query;
			$this->posts       = $world->query_posts;
			$this->found_posts = $world->query_found;
		}
	}
}

/** Deterministic world behind every substitute. */
final class SiteMediaWorld {
	/** Current world.
	 *
	 * @var self|null
	 */
	public static ?self $current = null;
	/** Current WordPress user.
	 *
	 * @var int
	 */
	public int $current_user = 0;
	/** Current blog.
	 *
	 * @var int
	 */
	public int $blog = 1;
	/** Stored posts by ID.
	 *
	 * @var array<int,SiteMediaPost>
	 */
	public array $posts = array();
	/** Registered types.
	 *
	 * @var array<string,SiteMediaPostType>
	 */
	public array $types = array();
	/** Users by ID.
	 *
	 * @var array<int,SiteMediaUser>
	 */
	public array $users = array();
	/** Post meta by object and key.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $meta = array();
	/** Attached file paths.
	 *
	 * @var array<int,string>
	 */
	public array $attached = array();
	/** Attachment metadata.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $metadata = array();
	/** Attachment URLs.
	 *
	 * @var array<int,string>
	 */
	public array $urls = array();
	/** Intermediate size sources.
	 *
	 * @var array<string,array<int,int|string>>
	 */
	public array $image_src = array();
	/** Allowed MIME types returned by the site.
	 *
	 * @var array<string,string>
	 */
	public array $allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'pdf'          => 'application/pdf',
		'mp3'          => 'audio/mpeg',
		'mp4'          => 'video/mp4',
		'txt'          => 'text/plain',
	);
	/** Insert arguments by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $inserted = array();
	/** Inserted attached file by ID.
	 *
	 * @var array<int,string>
	 */
	public array $inserted_file = array();
	/** Configured insert result; 0 allocates an ID.
	 *
	 * @var int|\WP_Error
	 */
	public int|\WP_Error $insert_result = 0;
	/** Next allocated ID.
	 *
	 * @var int
	 */
	public int $next_id = 900;
	/** Deleted IDs.
	 *
	 * @var array<int,bool>
	 */
	public array $deleted = array();
	/** Delete keeps the post row.
	 *
	 * @var bool
	 */
	public bool $delete_keeps_row = false;
	/** Delete keeps the captured files.
	 *
	 * @var bool
	 */
	public bool $delete_keeps_files = false;
	/** Update arguments by ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $updated = array();
	/** Recorded call counters.
	 *
	 * @var array<string,int>
	 */
	public array $calls = array();
	/** Real files written by the uploader.
	 *
	 * @var array<int,string>
	 */
	public array $written = array();
	/** Configured uploader error.
	 *
	 * @var string
	 */
	public string $upload_error = '';
	/** Configured short write.
	 *
	 * @var bool
	 */
	public bool $upload_short = false;
	/** Reported image dimensions or failure.
	 *
	 * @var array<int,int>|false
	 */
	public array|false $image_size = array( 1, 1 );
	/** Detected MIME override for the extension check.
	 *
	 * @var string
	 */
	public string $checked_type = '';
	/** Generated metadata.
	 *
	 * @var array<string,mixed>
	 */
	public array $generated = array(
		'width'  => 1,
		'height' => 1,
		'file'   => '2026/11/fr17-pixel.png',
		'sizes'  => array(
			'thumbnail' => array(
				'file'      => 'fr17-pixel-150x150.png',
				'width'     => 150,
				'height'    => 150,
				'mime-type' => 'image/png',
			),
		),
	);
	/** Stored metadata override after the write.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $metadata_override = null;
	/** Whether the alt write is ignored.
	 *
	 * @var bool
	 */
	public bool $alt_ignored = false;
	/** Multisite mode.
	 *
	 * @var bool
	 */
	public bool $multisite = false;
	/** Remaining upload space in bytes.
	 *
	 * @var int
	 */
	public int $upload_space = 1000000;
	/** Whether the upload-space check is disabled.
	 *
	 * @var bool
	 */
	public bool $space_check_disabled = false;
	/** Maximum upload bytes.
	 *
	 * @var int
	 */
	public int $max_upload = 8388608;
	/** Whether the eligibility recheck after the write is denied.
	 *
	 * @var bool
	 */
	public bool $deny_after_upload = false;
	/** Whether the next eligibility check is denied.
	 *
	 * @var bool
	 */
	public bool $deny_next_check = false;
	/** Whether the eligibility seam itself is unavailable.
	 *
	 * @var bool
	 */
	public bool $eligibility_internal = false;
	/** Rows returned by the query.
	 *
	 * @var array<SiteMediaPost>
	 */
	public array $query_posts = array();
	/** Total reported by the query.
	 *
	 * @var int
	 */
	public int $query_found = 0;
	/** Last query arguments.
	 *
	 * @var array<string,mixed>
	 */
	public array $last_query = array();
	/** Added hooks.
	 *
	 * @var array<string>
	 */
	public array $hooks_added = array();
	/** Removed hooks.
	 *
	 * @var array<string>
	 */
	public array $hooks_removed = array();

	/**
	 * Strip markup the way the test KSES substitute does.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function strip( string $value ): string {
		$stripped = preg_replace( '#<[^>]*>#', '', $value );
		return is_string( $stripped ) ? $stripped : '';
	}

	/**
	 * Record one observable call.
	 *
	 * @param string $name Call name.
	 * @return void
	 */
	public function record( string $name ): void {
		$this->calls[ $name ] = ( $this->calls[ $name ] ?? 0 ) + 1;
	}

	/**
	 * Insert one post.
	 *
	 * @param array<string,mixed> $data Post fields.
	 * @return int|\WP_Error
	 */
	public function insert( array $data ): int|\WP_Error {
		if ( $this->insert_result instanceof \WP_Error ) {
			return $this->insert_result;
		}
		$id                    = $this->insert_result > 0 ? $this->insert_result : $this->next_id++;
		$this->inserted[ $id ] = $data;
		$this->posts[ $id ]    = new SiteMediaPost( array_merge( $data, array( 'ID' => $id ) ) );
		$this->metadata[ $id ] = array();
		return $id;
	}

	/**
	 * Update one stored post.
	 *
	 * @param array<string,mixed> $data Post fields.
	 * @return int
	 */
	public function update( array $data ): int {
		$id                   = (int) ( $data['ID'] ?? 0 );
		$this->updated[ $id ] = $data;
		$current              = $this->posts[ $id ] ?? new SiteMediaPost( array( 'ID' => $id ) );
		foreach ( $data as $key => $value ) {
			if ( 'ID' !== $key && property_exists( $current, (string) $key ) ) {
				$current->{$key} = $value;
			}
		}
		$this->posts[ $id ] = $current;
		return $id;
	}

	/**
	 * Delete one stored post.
	 *
	 * @param int $id Post ID.
	 * @return SiteMediaPost|null
	 */
	public function delete( int $id ): ?SiteMediaPost {
		$post                 = $this->posts[ $id ] ?? null;
		$this->deleted[ $id ] = true;
		if ( ! $this->delete_keeps_row ) {
			unset( $this->posts[ $id ] );
		}
		return $post;
	}

	/**
	 * Write one meta value.
	 *
	 * @param int    $id Object ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Meta value.
	 * @return bool
	 */
	public function write_meta( int $id, string $key, mixed $value ): bool {
		$this->meta[ $id ][ $key ] = $value;
		return true;
	}
}

/** Catalog returning the reviewed media definitions. */
final class SiteMediaCatalog implements AbilityCatalog {
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
final class SiteMediaBudget implements ExpensiveBudget {
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

/**
 * Exercises the accountless media contract without booting WordPress.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SiteMediaOperationsTest extends TestCase {
	/** Current blog. */
	private const BLOG = 1;
	/** Admitted attribution target. */
	private const ELIGIBLE = 21;
	/** Second admitted attribution target. */
	private const ELIGIBLE_TWO = 22;
	/** Protected network administrator. */
	private const PROTECTED = 1;
	/** Account without the editorial capability. */
	private const CAPABILITYLESS = 23;
	/** Protected attribution target on the current blog. */
	private const PROTECTED_TARGET = 24;
	/** Machine-owned attachment. */
	private const ATTACHMENT = 501;
	/** Attachment owned by a protected account. */
	private const PROTECTED_ATTACHMENT = 502;
	/** Page parent. */
	private const PAGE = 601;
	/** Post parent. */
	private const POST = 602;
	/** Trashed parent. */
	private const TRASHED = 700;
	/** One-pixel PNG fixture. */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=';
	/** Document bytes refused by the image extension. */
	private const PDF = 'JVBERi0xLjQKMSAwIG9iajw8Pj5lbmRvYmoKdHJhaWxlcjw8Pj4KJSVFT0YK';
	/** Fixture directory for real temporary files. */
	private const FIXTURE = '/tmp/opencode/fr17-unit';

	/** World behind the substitutes.
	 *
	 * @var SiteMediaWorld
	 */
	private SiteMediaWorld $world;
	/** Reviewed names.
	 *
	 * @var array<string>
	 */
	private array $names = array();
	/** Real files created by one test.
	 *
	 * @var array<string>
	 */
	private array $files = array();
	/** Operations under test.
	 *
	 * @var SiteMediaOperations
	 */
	private SiteMediaOperations $operations;

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		$this->paths();
		$this->alias_core_classes();
		$this->world             = new SiteMediaWorld();
		SiteMediaWorld::$current = $this->world;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only database double for the live eligibility guard.
		$GLOBALS['wpdb'] = new SiteMediaWpdb();
		$this->names     = SiteMediaOperations::NAMES;
		$this->seed();
		$this->stubs();
		$this->operations = $this->operations();
	}

	/** Remove substitutes and owned files. */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->files             = array();
		SiteMediaWorld::$current = null;
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The seven names equal the reflected pack order and stay unique. */
	public function test_names_are_the_reflected_reviewed_tools(): void {
		self::assertCount( 7, $this->names );
		self::assertTrue( array_is_list( $this->names ) );
		self::assertSame( array_unique( $this->names ), $this->names );
		$yielded = array();
		foreach ( ( new MediaPack( new DefinitionFactory(), new MemorySettings() ) )->definitions() as $definition ) {
			$yielded[] = $definition->descriptor()->name;
		}
		self::assertSame( $yielded, $this->names );
		self::assertSame(
			array(
				'xnetvn/list-media',
				'xnetvn/get-media',
				'xnetvn/update-media',
				'xnetvn/upload-media-from-url',
				'xnetvn/upload-media-from-data',
				'xnetvn/delete-media',
				'xnetvn/get-media-upload-limits',
			),
			$this->names
		);
	}

	/** The handler is one two-parameter closure and the envelope fails closed. */
	public function test_handler_shape_and_execution_envelope(): void {
		$dispatcher = $this->dispatcher();
		$this->register( $dispatcher );
		$method = new \ReflectionMethod( $dispatcher, 'handler_for' );
		$method->setAccessible( true );
		$handler = $method->invoke( $dispatcher, 'xnetvn/get-media' );
		self::assertInstanceOf( Closure::class, $handler );
		$reflection = new \ReflectionFunction( $handler );
		self::assertSame( 2, $reflection->getNumberOfParameters() );
		self::assertSame( 'array', (string) $reflection->getParameters()[0]->getType() );
		self::assertSame( SiteAuthContext::class, (string) $reflection->getParameters()[1]->getType() );

		$unknown = $this->operations->execute( 'xnetvn/nope', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unknown->get_error_code() );

		$cross = $this->operations->execute( 'xnetvn/get-media', array( 'id' => self::ATTACHMENT ), $this->context( self::BLOG + 1 ) );
		self::assertInstanceOf( \WP_Error::class, $cross );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $cross->get_error_code() );

		$this->world->current_user = self::ELIGIBLE;
		$human                     = $this->operations->execute( 'xnetvn/get-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $human );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $human->get_error_code() );
		$this->world->current_user = 0;

		self::assertSame( 0, $this->world->calls['current_user_can'] ?? 0 );
		self::assertSame( 0, $this->world->calls['set_current_user'] ?? 0 );
		self::assertSame( 0, $this->world->calls['rest_request'] ?? 0 );
	}

	/** Every parent type must carry the exact reviewed capability class. */
	public function test_parent_type_class_is_exact(): void {
		$this->world->types['book']     = new SiteMediaPostType( 'book', SiteMediaPostType::core_map( 'post' ), 'book' );
		$this->world->types['unmapped'] = new SiteMediaPostType( 'unmapped', SiteMediaPostType::core_map( 'post' ), 'post', false );
		$renamed                        = SiteMediaPostType::core_map( 'post' );
		$renamed['delete_post']         = 'delete_ownership';
		$this->world->types['renamed']  = new SiteMediaPostType( 'renamed', $renamed );
		$extra                          = SiteMediaPostType::core_map( 'post' );
		$extra['delete_own_posts']      = 'delete_own_posts';
		$this->world->types['extra']    = new SiteMediaPostType( 'extra', $extra );

		$index = 0;
		foreach ( array( 'book', 'unmapped', 'renamed', 'extra' ) as $slug ) {
			++$index;
			$id                        = 800 + $index;
			$this->world->posts[ $id ] = new SiteMediaPost(
				array(
					'ID'          => $id,
					'post_type'   => $slug,
					'post_status' => 'publish',
					'post_parent' => 0,
				)
			);
			$result                    = $this->operations->execute(
				'xnetvn/update-media',
				array(
					'id'   => self::ATTACHMENT,
					'post' => $id,
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $result, $slug );
			self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code(), $slug );
			self::assertSame( array(), $this->world->updated, $slug );
		}

		$allowed = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'   => self::ATTACHMENT,
				'post' => self::PAGE,
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $allowed );
		self::assertSame( self::PAGE, (int) $this->world->posts[ self::ATTACHMENT ]->post_parent );
	}

	/** Parent object and status rules refuse before any write. */
	public function test_parent_object_and_status_rules(): void {
		$this->world->posts[ self::TRASHED ]->post_status = 'trash';
		$cases = array(
			'trash' => array( self::TRASHED, 'xnetvn_agent_hub_wp_invalid_state' ),
			'auto'  => array( $this->parent_with_status( 'auto-draft' ), 'xnetvn_agent_hub_wp_invalid_state' ),
			'known' => array( $this->parent_with_status( 'unknown-state' ), 'xnetvn_agent_hub_wp_invalid_state' ),
			'miss'  => array( 999001, 'xnetvn_agent_hub_wp_not_found' ),
			'self'  => array( self::ATTACHMENT, 'xnetvn_agent_hub_wp_invalid_state' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $this->operations->execute(
				'xnetvn/update-media',
				array(
					'id'    => self::ATTACHMENT,
					'title' => 'No',
					'post'  => $case[0],
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( $case[1], $result->get_error_code(), $label );
			self::assertSame( array(), $this->world->updated, $label );
		}

		$omitted = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'    => self::ATTACHMENT,
				'title' => 'Kept',
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $omitted );
		self::assertSame( self::PAGE, (int) $this->world->posts[ self::ATTACHMENT ]->post_parent );
		self::assertArrayNotHasKey( 'post_parent', $this->world->updated[ self::ATTACHMENT ] );

		$detached = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'   => self::ATTACHMENT,
				'post' => 0,
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $detached );
		self::assertSame( 0, (int) $this->world->posts[ self::ATTACHMENT ]->post_parent );
	}

	/** Ownership and protected-account rules guard every mutation. */
	public function test_attachment_ownership_and_protected_accounts(): void {
		$owned = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'    => self::PROTECTED_ATTACHMENT,
				'title' => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $owned );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $owned->get_error_code() );
		$deleted = $this->operations->execute( 'xnetvn/delete-media', array( 'id' => self::PROTECTED_ATTACHMENT ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $deleted );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $deleted->get_error_code() );
		self::assertSame( array(), $this->world->updated );
		self::assertSame( array(), $this->world->deleted );

		$this->world->posts[ self::ATTACHMENT ]->post_author = 0;
		$ownerless = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'    => self::ATTACHMENT,
				'title' => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $ownerless );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $ownerless->get_error_code() );
		$this->world->posts[ self::ATTACHMENT ]->post_author = self::ELIGIBLE;

		$this->world->eligibility_internal = true;
		$unavailable                       = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'    => self::ATTACHMENT,
				'title' => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $unavailable );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $unavailable->get_error_code() );
		$this->world->eligibility_internal = false;
		self::assertSame( array(), $this->world->updated );

		$missing = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'    => 999002,
				'title' => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $missing->get_error_code() );
	}

	/** Attachment visibility bounds reads without projecting the parent. */
	public function test_attachment_visibility_predicate(): void {
		$this->world->posts[ self::TRASHED ]->post_status              = 'trash';
		$this->world->posts[ self::PROTECTED_ATTACHMENT ]->post_parent = self::TRASHED;
		$this->world->query_posts                                      = array(
			$this->world->posts[ self::ATTACHMENT ],
			$this->world->posts[ self::PROTECTED_ATTACHMENT ],
			$this->world->posts[503],
		);
		$this->world->query_found                                      = 3;

		$list = $this->operations->execute( 'xnetvn/list-media', array( 'per_page' => 2 ), $this->context() );
		self::assertIsArray( $list, is_wp_error( $list ) ? $list->get_error_code() : '' );
		self::assertSame( array( self::ATTACHMENT, 503 ), array_column( $list['items'], 'id' ) );
		self::assertSame( 2, $list['total'] );
		self::assertSame( 1, $list['total_pages'] );
		self::assertSame( 1, $list['page'] );
		self::assertSame( 'inherit', $this->world->last_query['post_status'] );
		self::assertSame( 'attachment', $this->world->last_query['post_type'] );
		self::assertTrue( $this->world->last_query['ignore_sticky_posts'] );
		self::assertSame( 2, $this->world->last_query['posts_per_page'] );

		$hidden = $this->operations->execute( 'xnetvn/get-media', array( 'id' => self::PROTECTED_ATTACHMENT ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $hidden );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $hidden->get_error_code() );

		$private = $this->operations->execute( 'xnetvn/get-media', array( 'id' => 503 ), $this->context() );
		self::assertIsArray( $private, is_wp_error( $private ) ? $private->get_error_code() : '' );
		self::assertSame( 'private', $private['status'] );

		$this->world->posts[ self::ATTACHMENT ]->post_parent = 0;
		$detached = $this->operations->execute( 'xnetvn/get-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertIsArray( $detached, is_wp_error( $detached ) ? $detached->get_error_code() : '' );
	}

	/** Raw reads and malformed selectors are refused before any query. */
	public function test_read_refusals_and_selectors(): void {
		foreach ( array( 'xnetvn/list-media', 'xnetvn/get-media' ) as $name ) {
			$arguments = array( 'content_format' => 'raw' );
			if ( 'xnetvn/get-media' === $name ) {
				$arguments['id'] = self::ATTACHMENT;
			}
			$result = $this->operations->execute( $name, $arguments, $this->context() );
			self::assertInstanceOf( \WP_Error::class, $result, $name );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $result->get_error_code(), $name );
		}
		self::assertSame( 0, $this->world->calls['get_post'] ?? 0 );
		self::assertSame( 0, $this->world->calls['query'] ?? 0 );

		$this->world->allowed_mimes = array_diff( $this->world->allowed_mimes, array( 'text/plain' ) );
		$cases                      = array(
			'relevance' => array(
				'orderby' => 'relevance',
			),
			'media'     => array(
				'media_type' => array( 'text' ),
			),
			'offset'    => array(
				'page'     => PHP_INT_MAX,
				'per_page' => 2,
			),
			'fields'    => array(
				'fields' => array( 'nope' ),
			),
		);
		foreach ( $cases as $label => $arguments ) {
			$result = $this->operations->execute( 'xnetvn/list-media', $arguments, $this->context() );
			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code(), $label );
		}
		self::assertSame( 0, $this->world->calls['query'] ?? 0 );

		$this->world->query_posts = array( $this->world->posts[ self::ATTACHMENT ] );
		$this->world->query_found = 1;
		$narrow                   = $this->operations->execute(
			'xnetvn/list-media',
			array(
				'fields'     => array( 'title' ),
				'media_type' => array( 'image' ),
			),
			$this->context()
		);
		self::assertIsArray( $narrow, is_wp_error( $narrow ) ? $narrow->get_error_code() : '' );
		self::assertSame( array( 'id', 'status', 'title' ), array_keys( $narrow['items'][0] ) );
		self::assertSame( array( 'image/jpeg', 'image/png', 'image/gif' ), $this->world->last_query['post_mime_type'] );
		self::assertSame( 'date', $this->world->last_query['orderby'] );
		self::assertSame( 'desc', $this->world->last_query['order'] );

		$excerpt = $this->operations->execute(
			'xnetvn/get-media',
			array(
				'id'             => self::ATTACHMENT,
				'content_format' => 'excerpt',
			),
			$this->context()
		);
		self::assertIsArray( $excerpt, is_wp_error( $excerpt ) ? $excerpt->get_error_code() : '' );
		self::assertSame( 'Pixel caption', $excerpt['description'] );
	}

	/** Update changes only the requested fields and keeps the author. */
	public function test_update_changes_only_requested_fields(): void {
		$result = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'          => self::ATTACHMENT,
				'title'       => 'Pixel <script>alert(1)</script>',
				'alt_text'    => 'Pixel alt',
				'caption'     => 'Pixel caption',
				'description' => 'Pixel <b>description</b>',
				'post'        => self::POST,
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertSame( self::ELIGIBLE, $this->world->posts[ self::ATTACHMENT ]->post_author );
		$written = $this->world->updated[ self::ATTACHMENT ];
		self::assertSame( array( 'ID', 'post_title', 'post_excerpt', 'post_content', 'post_parent' ), array_keys( $written ) );
		self::assertStringNotContainsString( '<script', (string) $written['post_title'] );
		self::assertSame( 'Pixel description', (string) $written['post_content'] );
		self::assertSame( 'Pixel alt', $this->world->meta[ self::ATTACHMENT ]['_wp_attachment_image_alt'] );
		self::assertSame( 'Pixel alt', $result->data['alt_text'] );
		self::assertSame( self::ELIGIBLE, $result->data['author'] );

		foreach ( array( 'author', 'guid', 'post_name', 'post_mime_type', 'file' ) as $forbidden ) {
			$refused = $this->operations->execute(
				'xnetvn/update-media',
				array(
					'id'       => self::ATTACHMENT,
					$forbidden => 'x',
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $refused, $forbidden );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $refused->get_error_code(), $forbidden );
		}

		$empty = $this->operations->execute( 'xnetvn/update-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $empty );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $empty->get_error_code() );

		$this->world->alt_ignored = true;
		$partial                  = $this->operations->execute(
			'xnetvn/update-media',
			array(
				'id'       => self::ATTACHMENT,
				'alt_text' => 'Changed alt',
			),
			$this->context()
		);
		self::assertInstanceOf( PartialFailure::class, $partial );
		self::assertFalse( $partial->created );
		self::assertSame( self::ATTACHMENT, $partial->object_id );
		$this->world->alt_ignored = false;
	}

	/** Delete removes only the addressed attachment and reports survived files. */
	public function test_delete_receipts_are_truthful(): void {
		$this->seed_attachment( self::ATTACHMENT, self::ELIGIBLE );

		$deleted = $this->operations->execute( 'xnetvn/delete-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertInstanceOf( MutationResult::class, $deleted );
		self::assertTrue( $deleted->data['deleted'] );
		self::assertSame( self::ATTACHMENT, $deleted->data['id'] );
		self::assertTrue( $this->world->deleted[ self::ATTACHMENT ] );
		self::assertNotNull( $this->world->posts[ self::PROTECTED_ATTACHMENT ] );

		$this->seed_attachment( self::ATTACHMENT, self::ELIGIBLE );
		$derivative = $this->write_file( 'uploads/2026/11/fr17-derivative-150x150.png', str_repeat( 'x', 8 ) );
		$this->world->metadata[ self::ATTACHMENT ]['sizes'] = array(
			'thumbnail' => array(
				'file'      => basename( $derivative ),
				'width'     => 150,
				'height'    => 150,
				'mime-type' => 'image/png',
			),
		);
		$this->world->delete_keeps_files                    = true;
		$survived = $this->operations->execute( 'xnetvn/delete-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertInstanceOf( PartialFailure::class, $survived );
		self::assertFalse( $survived->created );
		self::assertSame( self::ATTACHMENT, $survived->object_id );
		$this->world->delete_keeps_files = false;

		$this->seed_attachment( self::ATTACHMENT, self::ELIGIBLE );
		$this->world->delete_keeps_row = true;
		$survives                      = $this->operations->execute( 'xnetvn/delete-media', array( 'id' => self::ATTACHMENT ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $survives );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $survives->get_error_code() );
		$this->world->delete_keeps_row = false;
	}

	/** Uploads require a positive eligible author before any file work. */
	public function test_upload_requires_an_eligible_author(): void {
		$cases = array(
			'missing'        => array( array( 'author' => null ), 'xnetvn_agent_hub_wp_invalid_params' ),
			'zero'           => array( array( 'author' => 0 ), 'xnetvn_agent_hub_wp_invalid_params' ),
			'negative'       => array( array( 'author' => -3 ), 'xnetvn_agent_hub_wp_invalid_params' ),
			'string'         => array( array( 'author' => '21' ), 'xnetvn_agent_hub_wp_invalid_params' ),
			'protected'      => array( array( 'author' => self::PROTECTED ), 'xnetvn_agent_hub_wp_forbidden' ),
			'capabilityless' => array( array( 'author' => self::CAPABILITYLESS ), 'xnetvn_agent_hub_wp_forbidden' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $this->operations->execute( 'xnetvn/upload-media-from-data', array_merge( $this->upload_arguments(), $case[0] ), $this->context() );
			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( $case[1], $result->get_error_code(), $label );
		}
		self::assertSame( 0, $this->world->calls['upload_bits'] ?? 0 );
		self::assertSame( array(), $this->world->inserted );
		self::assertSame( array(), $this->world->written );

		$this->world->eligibility_internal = true;
		$internal                          = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $internal );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $internal->get_error_code() );
		$this->world->eligibility_internal = false;
		self::assertSame( 0, $this->world->calls['upload_bits'] ?? 0 );
	}

	/** Quota precedes the byte ceiling and the remaining-space guard. */
	public function test_upload_gates_precede_file_work(): void {
		$this->world->multisite    = true;
		$this->world->upload_space = 0;
		$quota                     = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $quota );
		self::assertSame( 'xnetvn_agent_hub_wp_quota_exceeded', $quota->get_error_code() );
		self::assertSame( 0, $this->world->calls['upload_bits'] ?? 0 );
		self::assertSame( array(), $this->world->written );

		$this->world->upload_space = 10;
		$remaining                 = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $remaining );
		self::assertSame( 'xnetvn_agent_hub_wp_quota_exceeded', $remaining->get_error_code() );
		self::assertSame( 0, $this->world->calls['upload_bits'] ?? 0 );

		$this->world->multisite    = false;
		$this->world->upload_space = 1000000;
		$disabled                  = $this->operations->execute(
			'xnetvn/upload-media-from-url',
			array(
				'author' => self::ELIGIBLE,
				'url'    => 'https://example.test/pixel.png',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $disabled );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_disabled', $disabled->get_error_code() );

		$enabled = $this->operations( new MemorySettings( array( 'media_remote_fetch_enabled' => true ) ) );
		$gate    = $enabled->execute(
			'xnetvn/upload-media-from-url',
			array(
				'author' => self::ELIGIBLE,
				'url'    => 'https://example.test/pixel.png',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $gate );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_disabled', $gate->get_error_code() );
		self::assertSame( array(), $this->world->written );

		$limited = $this->operations( new MemorySettings( array( 'media_data_max_bytes' => 1024 ) ) );
		$large   = $limited->execute( 'xnetvn/upload-media-from-data', array_merge( $this->upload_arguments(), array( 'data_base64' => base64_encode( str_repeat( 'a', 2048 ) ) ) ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $large );
		self::assertSame( 'xnetvn_agent_hub_wp_payload_too_large', $large->get_error_code() );

		$wrong = $this->operations->execute( 'xnetvn/upload-media-from-data', array_merge( $this->upload_arguments(), array( 'data_base64' => self::PDF ) ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $wrong );
		self::assertSame( 'xnetvn_agent_hub_wp_file_type_not_allowed', $wrong->get_error_code() );
		self::assertSame( array(), $this->world->written );
		self::assertSame( array(), $this->world->inserted );

		$this->world->upload_error = 'Sorry, you are not allowed to upload this file type.';
		$failed                    = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $failed->get_error_code() );
		self::assertSame( array(), $this->world->inserted );
		$this->world->upload_error = '';

		$this->world->upload_short = true;
		$short                     = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $short );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $short->get_error_code() );
		self::assertSame( array(), $this->world->inserted );
		foreach ( $this->world->written as $file ) {
			self::assertFalse( file_exists( $file ), $file );
		}
		$this->world->upload_short = false;
	}

	/** A valid upload persists one attachment with sanitized metadata. */
	public function test_upload_persists_one_attachment(): void {
		$result = $this->operations->execute(
			'xnetvn/upload-media-from-data',
			array_merge(
				$this->upload_arguments(),
				array(
					'title'       => 'Pixel <script>alert(1)</script>',
					'alt_text'    => 'Pixel alt',
					'caption'     => 'Pixel caption',
					'description' => 'Pixel <b>description</b>',
					'post'        => self::PAGE,
				)
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		self::assertTrue( $result->created );
		self::assertSame( 1, count( $this->world->inserted ) );
		$id      = $result->object_id;
		$written = $this->world->inserted[ $id ];
		self::assertSame( 'attachment', $written['post_type'] );
		self::assertSame( 'inherit', $written['post_status'] );
		self::assertSame( 'image/png', $written['post_mime_type'] );
		self::assertSame( self::ELIGIBLE, $written['post_author'] );
		self::assertSame( self::PAGE, $written['post_parent'] );
		self::assertStringNotContainsString( '<script', (string) $written['post_title'] );
		self::assertSame( 'Pixel description', (string) $written['post_content'] );
		self::assertArrayNotHasKey( 'guid', $written );
		self::assertArrayNotHasKey( 'post_name', $written );
		self::assertSame( $this->world->written[0], $this->world->inserted_file[ $id ] );
		self::assertSame( $this->world->written[0], $this->world->attached[ $id ] );
		self::assertSame( self::ELIGIBLE, $this->world->posts[ $id ]->post_author );
		self::assertSame( 'Pixel alt', $this->world->meta[ $id ]['_wp_attachment_image_alt'] );
		self::assertSame( $this->world->generated, $this->world->metadata[ $id ] );
		self::assertSame( self::ELIGIBLE, $result->data['author'] );
		self::assertSame( 'inherit', $result->data['status'] );
		self::assertSame( 1, (int) $result->data['media_details']->width );
		self::assertSame( 1, $this->world->calls['generate_metadata'] ?? 0 );
		self::assertSame( 0, get_current_user_id() );
	}

	/** A failed insert deletes exactly the file it wrote. */
	public function test_upload_insert_failure_deletes_the_written_file(): void {
		$unrelated                  = $this->write_file( 'uploads/fr17-unrelated.png', 'x' );
		$this->world->insert_result = new \WP_Error( 'empty_content', 'Empty content.' );
		$result                     = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertNotInstanceOf( MutationResult::class, $result );
		self::assertSame( array(), $this->world->inserted );
		foreach ( $this->world->written as $file ) {
			self::assertFalse( file_exists( $file ), $file );
		}
		self::assertTrue( file_exists( $unrelated ) );
	}

	/** Metadata and attribution mismatches keep a truthful created receipt. */
	public function test_upload_partial_receipts(): void {
		$this->world->metadata_override = array(
			'width'  => 2,
			'height' => 1,
			'file'   => '2026/11/fr17-pixel.png',
			'sizes'  => array(),
		);
		$mismatch                       = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( PartialFailure::class, $mismatch );
		self::assertTrue( $mismatch->created );
		self::assertGreaterThan( 0, $mismatch->object_id );
		self::assertArrayHasKey( $mismatch->object_id, $this->world->posts );
		$this->world->metadata_override = null;

		$this->world->alt_ignored = true;
		$alt                      = $this->operations->execute( 'xnetvn/upload-media-from-data', array_merge( $this->upload_arguments(), array( 'alt_text' => 'Pixel alt' ) ), $this->context() );
		self::assertInstanceOf( PartialFailure::class, $alt );
		self::assertTrue( $alt->created );
		$this->world->alt_ignored = false;

		$before                         = array_keys( $this->world->inserted );
		$written_before                 = count( $this->world->written );
		$this->world->deny_after_upload = true;
		$recheck                        = $this->operations->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $recheck );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $recheck->get_error_code() );
		self::assertSame( $before, array_keys( $this->world->inserted ) );
		foreach ( array_slice( $this->world->written, $written_before ) as $file ) {
			self::assertFalse( file_exists( $file ), $file );
		}
		$this->world->deny_after_upload = false;
	}

	/** The dispatcher admits exactly the seven registered names and charges uploads. */
	public function test_registered_handler_set_and_costly_budget(): void {
		$budget     = new SiteMediaBudget();
		$dispatcher = $this->dispatcher( $budget );
		foreach ( $this->names as $name ) {
			self::assertFalse( $dispatcher->has_handler( $name ), $name );
		}
		$this->register( $dispatcher );
		foreach ( $this->names as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		self::assertFalse( $dispatcher->has_handler( 'xnetvn/nope' ) );

		$thrown = false;
		try {
			$dispatcher->register( 'xnetvn/list-media', static fn( array $arguments, SiteAuthContext $context ): array => array() );
		} catch ( \InvalidArgumentException ) {
			$thrown = true;
		}
		self::assertTrue( $thrown );

		$context = $this->context();
		$listed  = $dispatcher->execute( 'xnetvn/list-media', array(), $context );
		self::assertIsArray( $listed, is_wp_error( $listed ) ? $listed->get_error_code() : '' );
		self::assertSame( array(), $budget->identities );

		$uploaded = $dispatcher->execute( 'xnetvn/upload-media-from-data', $this->upload_arguments(), $context );
		self::assertIsArray( $uploaded, is_wp_error( $uploaded ) ? $uploaded->get_error_code() : '' );
		self::assertSame( self::ELIGIBLE, $uploaded['author'] );
		self::assertSame( 1, count( $this->world->inserted ) );
		self::assertSame( array( 'site:' . self::BLOG . ':' . str_repeat( 'a', 12 ) ), $budget->identities );

		$limits = $dispatcher->execute( 'xnetvn/get-media-upload-limits', array(), $context );
		self::assertIsArray( $limits, is_wp_error( $limits ) ? $limits->get_error_code() : '' );
		self::assertSame(
			array( 'max_upload_bytes', 'data_upload_max_bytes', 'remote_fetch_enabled', 'remote_fetch_max_bytes', 'pixels_max', 'allowed_mime_types' ),
			array_keys( $limits )
		);
		self::assertFalse( $limits['remote_fetch_enabled'] );
		self::assertSame( 8388608, $limits['max_upload_bytes'] );
		self::assertSame( 1048576, $limits['data_upload_max_bytes'] );
		self::assertSame( 8388608, $limits['remote_fetch_max_bytes'] );
		self::assertSame( 40000000, $limits['pixels_max'] );
		self::assertSame( array( 'application/pdf', 'audio/mpeg', 'image/gif', 'image/jpeg', 'image/png', 'video/mp4' ), $limits['allowed_mime_types'] );
	}

	/** Create the real fixture files used by the scripts and includes. */
	private function paths(): void {
		$base = self::FIXTURE . '/wp-admin/includes';
		if ( ! is_dir( $base ) && ! mkdir( $base, 0777, true ) && ! is_dir( $base ) ) {
			self::fail( 'Fixture directory is unavailable.' );
		}
		foreach ( array( 'file.php', 'image.php' ) as $include ) {
			$path = $base . '/' . $include;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, '<?php' . "\n" );
			}
		}
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', self::FIXTURE . '/' );
		}
	}

	/**
	 * Write one owned fixture file and return its path.
	 *
	 * @param string $relative Repository-relative fixture path.
	 * @param string $contents File bytes.
	 * @return string
	 */
	private function write_file( string $relative, string $contents ): string {
		$path = self::FIXTURE . '/' . ltrim( $relative, '/' );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			self::fail( 'Fixture path is unavailable.' );
		}
		file_put_contents( $path, $contents );
		$this->files[] = $path;
		return $path;
	}

	/** Register the isolated core-class doubles. */
	private function alias_core_classes(): void {
		if ( ! class_exists( 'WP_Post', false ) ) {
			class_alias( SiteMediaPost::class, 'WP_Post' );
		}
		if ( ! class_exists( 'WP_Post_Type', false ) ) {
			class_alias( SiteMediaPostType::class, 'WP_Post_Type' );
		}
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( SiteMediaUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( SiteMediaRoles::class, 'WP_Roles' );
		}
		if ( ! class_exists( 'wpdb', false ) ) {
			class_alias( SiteMediaWpdb::class, 'wpdb' );
		}
		if ( ! class_exists( 'WP_Query', false ) ) {
			class_alias( SiteMediaQuery::class, 'WP_Query' );
		}
	}

	/** Install the fixed reviewed storage. */
	private function seed(): void {
		$this->seed_attachment( self::ATTACHMENT, self::ELIGIBLE );
		$this->world->posts[ self::PROTECTED_ATTACHMENT ] = new SiteMediaPost(
			array(
				'ID'             => self::PROTECTED_ATTACHMENT,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_author'    => self::PROTECTED,
				'post_parent'    => 0,
				'post_title'     => 'Protected title',
				'post_mime_type' => 'image/png',
			)
		);
		$this->world->posts[503]                          = new SiteMediaPost(
			array(
				'ID'          => 503,
				'post_type'   => 'attachment',
				'post_status' => 'private',
				'post_author' => self::ELIGIBLE_TWO,
				'post_parent' => self::TRASHED,
			)
		);
		$this->world->posts[ self::PAGE ]                 = new SiteMediaPost(
			array(
				'ID'          => self::PAGE,
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->world->posts[ self::POST ]                 = new SiteMediaPost(
			array(
				'ID'          => self::POST,
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);
		$this->world->posts[ self::TRASHED ]              = new SiteMediaPost(
			array(
				'ID'          => self::TRASHED,
				'post_type'   => 'post',
				'post_status' => 'trash',
			)
		);
		$this->world->types['post']                       = new SiteMediaPostType( 'post', SiteMediaPostType::core_map( 'post' ) );
		$this->world->types['page']                       = new SiteMediaPostType( 'page', SiteMediaPostType::core_map( 'page' ), 'page', true, true );
		foreach ( array( self::ELIGIBLE, self::ELIGIBLE_TWO, self::PROTECTED, self::CAPABILITYLESS, self::PROTECTED_TARGET ) as $id ) {
			$this->world->users[ $id ] = new SiteMediaUser( $id, array( 1 === $id ? 'administrator' : 'editor' ) );
		}
	}

	/**
	 * Install one attachment with stable files and metadata.
	 *
	 * @param int $id Attachment ID.
	 * @param int $author Stored author.
	 * @return void
	 */
	private function seed_attachment( int $id, int $author ): void {
		$this->world->posts[ $id ]    = new SiteMediaPost(
			array(
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_author'    => $author,
				'post_parent'    => self::PAGE,
				'post_title'     => 'Pixel title',
				'post_excerpt'   => 'Pixel caption',
				'post_content'   => 'Pixel description',
				'post_mime_type' => 'image/png',
			)
		);
		$this->world->meta[ $id ]     = array( '_wp_attachment_image_alt' => 'Pixel alt' );
		$this->world->attached[ $id ] = self::FIXTURE . '/uploads/2026/11/fr17-pixel.png';
		$this->world->metadata[ $id ] = array(
			'width'  => 1,
			'height' => 1,
			'file'   => '2026/11/fr17-pixel.png',
			'sizes'  => array(
				'thumbnail' => array(
					'file'      => 'fr17-pixel-150x150.png',
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/png',
				),
			),
		);
		$this->world->urls[ $id ]     = 'https://example.test/uploads/2026/11/fr17-pixel.png';
		if ( ! isset( $this->world->image_src['uploads/2026/11/fr17-pixel-150x150.png'] ) ) {
			$this->world->image_src['uploads/2026/11/fr17-pixel-150x150.png'] = array( 'https://example.test/uploads/2026/11/fr17-pixel-150x150.png', 150, 150 );
		}
	}

	/** Install the fixed WordPress substitutes. */
	private function stubs(): void {
		Functions\when( 'get_current_blog_id' )->alias( fn(): int => $this->world->blog );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->world->current_user );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_post' )->alias(
			function ( mixed $id = 0 ): ?SiteMediaPost {
				$this->world->record( 'get_post' );
				return $this->world->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'get_post_type_object' )->alias( fn( string $slug ): ?SiteMediaPostType => $this->world->types[ $slug ] ?? null );
		Functions\when( 'get_post_mime_type' )->alias( fn( int $id ): string|false => $this->world->posts[ $id ]->post_mime_type ?? false );
		Functions\when( 'get_permalink' )->alias( static fn( mixed $id = 0 ): string => 'https://example.test/?attachment_id=' . (int) $id );
		Functions\when( 'wp_get_attachment_url' )->alias( fn( int $id ): string|false => $this->world->urls[ $id ] ?? false );
		Functions\when( 'wp_get_attachment_image_src' )->alias(
			function ( int $id, mixed $size = 'thumbnail' ): array|false {
				$name = is_string( $size ) ? $size : 'thumbnail';
				$file = (string) ( $this->world->metadata[ $id ]['sizes'][ $name ]['file'] ?? '' );
				return $this->world->image_src[ 'uploads/2026/11/' . $file ] ?? false;
			}
		);
		Functions\when( 'get_attached_file' )->alias( fn( int $id ): string|false => $this->world->attached[ $id ] ?? false );
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn( int $id ): array|false => $this->world->metadata[ $id ] ?? false );
		Functions\when( 'wp_update_attachment_metadata' )->alias(
			function ( int $id, array $data ): bool {
				$this->world->record( 'metadata_write' );
				$this->world->metadata[ $id ] = null === $this->world->metadata_override ? $data : $this->world->metadata_override;
				return true;
			}
		);
		Functions\when( 'wp_generate_attachment_metadata' )->alias(
			function ( int $id, string $file ): array {
				$this->world->record( 'generate_metadata' );
				return $this->world->generated;
			}
		);
		Functions\when( 'wp_delete_attachment' )->alias(
			function ( int $id, bool $force = false ): ?SiteMediaPost {
				$this->world->record( 'delete_attachment' );
				if ( ! $this->world->delete_keeps_files ) {
					$attached = $this->world->attached[ $id ] ?? '';
					if ( is_string( $attached ) && file_exists( $attached ) ) {
						unlink( $attached );
					}
					$directory = is_string( $attached ) ? dirname( $attached ) : '';
					foreach ( (array) ( $this->world->metadata[ $id ]['sizes'] ?? array() ) as $size ) {
						$file = is_array( $size ) ? (string) ( $size['file'] ?? '' ) : '';
						if ( '' !== $file && '' !== $directory && file_exists( $directory . '/' . $file ) ) {
							unlink( $directory . '/' . $file );
						}
					}
				}
				return $this->world->delete( $id );
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			function ( string $file ): void {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		);
		Functions\when( 'wp_upload_bits' )->alias(
			function ( string $name, mixed $deprecated = null, string $bits = '' ): array {
				$this->world->record( 'upload_bits' );
				if ( '' !== $this->world->upload_error ) {
					return array(
						'file'  => '',
						'url'   => '',
						'type'  => '',
						'error' => $this->world->upload_error,
					);
				}
				$path                   = $this->write_file( 'uploads/2026/11/' . $name, $this->world->upload_short ? 'x' : $bits );
				$this->world->written[] = $path;
				if ( $this->world->deny_after_upload ) {
					$this->world->deny_next_check = true;
				}
				return array(
					'file'  => $path,
					'url'   => 'https://example.test/uploads/2026/11/' . $name,
					'type'  => 'image/png',
					'error' => false,
				);
			}
		);
		Functions\when( 'wp_insert_attachment' )->alias(
			function ( array $data, mixed $file = false, int $parent_id = 0 ): int|\WP_Error {
				$this->world->record( 'insert_attachment' );
				if ( $parent_id > 0 ) {
					$data['post_parent'] = $parent_id;
				}
				$id = $this->world->insert( $data );
				if ( is_int( $id ) && is_string( $file ) && '' !== $file ) {
					$this->world->inserted_file[ $id ] = $file;
					$this->world->attached[ $id ]      = $file;
				}
				return $id;
			}
		);
		Functions\when( 'update_attached_file' )->alias(
			function ( int $id, string $file ): bool {
				$this->world->attached[ $id ] = $file;
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias( fn( array $data, bool $wp_error = false ): int => $this->world->update( $data ) );
		Functions\when( 'wp_slash' )->alias( static fn( mixed $value ): mixed => $value );
		Functions\when( 'wp_unslash' )->alias( static fn( mixed $value ): mixed => $value );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				$value = $this->world->meta[ $id ][ $key ] ?? null;
				return $single ? ( null === $value ? '' : $value ) : array( $value );
			}
		);
		Functions\when( 'update_metadata' )->alias(
			function ( string $type, int $id, string $key, mixed $value ): bool {
				$this->world->record( 'meta_write' );
				if ( '_wp_attachment_image_alt' === $key && $this->world->alt_ignored ) {
					return false;
				}
				return $this->world->write_meta( $id, $key, $value );
			}
		);
		Functions\when( 'delete_metadata' )->justReturn( true );
		Functions\when( 'metadata_exists' )->alias( fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $this->world->meta[ $id ] ?? array() ) );
		Functions\when( 'wp_max_upload_size' )->alias( fn(): int => $this->world->max_upload );
		Functions\when( 'is_multisite' )->alias( fn(): bool => $this->world->multisite );
		Functions\when( 'is_upload_space_available' )->alias( fn(): bool => $this->world->space_check_disabled || $this->world->upload_space > 0 );
		Functions\when( 'get_upload_space_available' )->alias( fn(): int|bool => $this->world->upload_space );
		Functions\when( 'get_site_option' )->alias(
			function ( string $name, mixed $fallback = false ): mixed {
				if ( 'upload_space_check_disabled' === $name ) {
					return $this->world->space_check_disabled;
				}
				return $fallback;
			}
		);
		Functions\when( 'get_allowed_mime_types' )->alias( fn(): array => $this->world->allowed_mimes );
		Functions\when( 'wp_tempnam' )->alias(
			function ( string $filename = '', string $dir = '' ): string {
				$path = tempnam( self::FIXTURE, 'fr17' );
				self::assertIsString( $path );
				$this->files[] = $path;
				return $path;
			}
		);
		Functions\when( 'wp_check_filetype' )->alias(
			static function ( string $filename, mixed $mimes = null ): array {
				$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = array(
					'png' => 'image/png',
					'jpg' => 'image/jpeg',
					'gif' => 'image/gif',
					'pdf' => 'application/pdf',
					'mp3' => 'audio/mpeg',
					'mp4' => 'video/mp4',
				);
				return array(
					'ext'  => isset( $map[ $ext ] ) ? $ext : false,
					'type' => $map[ $ext ] ?? false,
				);
			}
		);
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			function ( string $file, string $filename, mixed $mimes = null ): array {
				$ext = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				$map = array(
					'png' => 'image/png',
					'jpg' => 'image/jpeg',
					'pdf' => 'application/pdf',
				);
				return array(
					'ext'             => isset( $map[ $ext ] ) ? $ext : false,
					'type'            => '' !== $this->world->checked_type ? $this->world->checked_type : ( $map[ $ext ] ?? false ),
					'proper_filename' => false,
				);
			}
		);
		Functions\when( 'wp_getimagesize' )->alias(
			function ( string $file, mixed $data = null ): array|false {
				$this->world->record( 'image_size' );
				return $this->world->image_size;
			}
		);
		Functions\when( 'wp_convert_hr_to_bytes' )->alias( static fn( string $value ): int => (int) $value );
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = preg_replace( '~[^A-Za-z0-9._-]~', '-', $name );
				return is_string( $name ) ? $name : '';
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( SiteMediaWorld::strip( $value ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => SiteMediaWorld::strip( $value ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $value ): string => SiteMediaWorld::strip( $value ) );
		Functions\when( 'wp_filter_post_kses' )->alias( static fn( string $value ): string => SiteMediaWorld::strip( $value ) );
		Functions\when( 'wp_filter_kses' )->alias( static fn( string $value ): string => SiteMediaWorld::strip( $value ) );
		Functions\when( 'add_filter' )->alias(
			function ( string $hook ): bool {
				$this->world->hooks_added[] = $hook;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $hook ): bool {
				$this->world->hooks_removed[] = $hook;
				return true;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_userdata' )->alias( fn( int $id ): ?SiteMediaUser => $this->world->users[ $id ] ?? null );
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( new SiteMediaRoles() );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'user_can' )->alias(
			static function ( mixed $user, string $capability ): bool {
				if ( 'edit_posts' === $capability ) {
					return $user instanceof SiteMediaUser && in_array( $user->ID, array( self::ELIGIBLE, self::ELIGIBLE_TWO, self::PROTECTED, self::PROTECTED_TARGET ), true );
				}
				return false;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( string $capability, mixed $capability_object = 0 ): bool {
				$this->world->record( 'current_user_can' );
				return false;
			}
		);
		Functions\when( 'map_meta_cap' )->alias(
			function ( string $capability, int $user_id, mixed $args = array() ): array {
				$this->world->record( 'map_meta_cap' );
				return array( 'do_not_allow' );
			}
		);
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $id ): SiteMediaUser {
				$this->world->record( 'set_current_user' );
				$this->world->current_user = $id;
				return $this->world->users[ $id ] ?? new SiteMediaUser( $id );
			}
		);
		Functions\when( 'rest_do_request' )->alias(
			function ( mixed $request ): array {
				$this->world->record( 'rest_request' );
				return array();
			}
		);
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'rest_sanitize_value_from_schema' )->alias( static fn( mixed $value ): mixed => $value );
	}

	/**
	 * Build one parent with an arbitrary status.
	 *
	 * @param string $status Requested status.
	 * @return int
	 */
	private function parent_with_status( string $status ): int {
		$id                        = 810 + strlen( $status );
		$this->world->posts[ $id ] = new SiteMediaPost(
			array(
				'ID'          => $id,
				'post_type'   => 'post',
				'post_status' => $status,
			)
		);
		return $id;
	}

	/**
	 * Build the operations under test.
	 *
	 * @param MemorySettings|null $settings Optional settings.
	 * @return SiteMediaOperations
	 */
	private function operations( ?MemorySettings $settings = null ): SiteMediaOperations {
		return new SiteMediaOperations( $settings ?? new MemorySettings(), $this->authors() );
	}

	/**
	 * Build the injected attribution seam.
	 *
	 * @return EditorialAuthors
	 */
	private function authors(): EditorialAuthors {
		return new EditorialAuthors(
			function ( int $id ): Decision {
				if ( $this->world->eligibility_internal ) {
					return Decision::deny( 'delegation_check_failed' );
				}
				if ( $this->world->deny_next_check ) {
					$this->world->deny_next_check = false;
					return Decision::deny( 'delegation_protected_account' );
				}
				return in_array( $id, array( self::ELIGIBLE, self::ELIGIBLE_TWO ), true )
					? Decision::permit()
					: Decision::deny( 'delegation_protected_account' );
			}
		);
	}

	/**
	 * Build one dispatcher bound to the reviewed catalog.
	 *
	 * @param SiteMediaBudget|null $budget Optional budget double.
	 * @return SiteDispatcher
	 */
	private function dispatcher( ?SiteMediaBudget $budget = null ): SiteDispatcher {
		$settings   = new MemorySettings();
		$catalog    = new SiteMediaCatalog( $this->definitions() );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		return $dispatcher;
	}

	/**
	 * Register the seven literal handlers exactly as the plugin seam does.
	 *
	 * @param SiteDispatcher $dispatcher Bound dispatcher.
	 * @return void
	 */
	private function register( SiteDispatcher $dispatcher ): void {
		$operations = $this->operations;
		$dispatcher->register( 'xnetvn/list-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/list-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/get-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/get-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/update-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/update-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/upload-media-from-url', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/upload-media-from-url', $args, $context ) );
		$dispatcher->register( 'xnetvn/upload-media-from-data', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/upload-media-from-data', $args, $context ) );
		$dispatcher->register( 'xnetvn/delete-media', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/delete-media', $args, $context ) );
		$dispatcher->register( 'xnetvn/get-media-upload-limits', static fn( array $args, SiteAuthContext $context ): mixed => $operations->execute( 'xnetvn/get-media-upload-limits', $args, $context ) );
	}

	/**
	 * Build the reviewed media definitions by name.
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
	 * Build one machine identity.
	 *
	 * @param int $blog_id Credential blog.
	 * @return SiteAuthContext
	 */
	private function context( int $blog_id = self::BLOG ): SiteAuthContext {
		$scope      = new SiteScope(
			RiskTier::Destructive,
			array( 'media' ),
			$this->names,
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$credential = new SiteCredential( 1, new KeyId( str_repeat( 'a', 12 ) ), $blog_id, 'Fixture', $scope, new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, str_repeat( 'f', 16 ), '203.0.113.7' );
	}

	/**
	 * Valid data-upload arguments.
	 *
	 * @return array<string,mixed>
	 */
	private function upload_arguments(): array {
		return array(
			'author'      => self::ELIGIBLE,
			'filename'    => 'fr17-pixel.png',
			'mime_type'   => 'image/png',
			'data_base64' => self::PNG,
		);
	}
}
