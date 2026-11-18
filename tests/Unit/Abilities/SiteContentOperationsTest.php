<?php
/**
 * Machine content operations, the fixed editorial map and dispatcher resolution.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Test-only WordPress doubles and fixed handler signatures.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\EditorialAuthors;
use XNetVN\AgentHubWP\Abilities\Packs\Content\SiteContentOperations;
use XNetVN\AgentHubWP\Abilities\Registry;
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
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal stored post used by the machine content doubles. */
final class SiteContentPost {
	/** Post ID.
	 *
	 * @var int
	 */
	public int $ID = 0;
	/** Post type.
	 *
	 * @var string
	 */
	public string $post_type = 'post';
	/** Post status.
	 *
	 * @var string
	 */
	public string $post_status = 'draft';
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
	/** Local modification date.
	 *
	 * @var string
	 */
	public string $post_modified = '2026-01-01 00:00:00';
	/** UTC creation date.
	 *
	 * @var string
	 */
	public string $post_date_gmt = '2026-01-01 00:00:00';
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
	/** Menu order.
	 *
	 * @var int
	 */
	public int $menu_order = 0;
	/** Stored password.
	 *
	 * @var string
	 */
	public string $post_password = '';
	/** Comment status.
	 *
	 * @var string
	 */
	public string $comment_status = 'open';
	/** Ping status.
	 *
	 * @var string
	 */
	public string $ping_status = 'open';

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

	/**
	 * Build one revision record.
	 *
	 * @param int    $id Parent post ID.
	 * @param int    $revision Revision ID.
	 * @param string $title Revision title.
	 * @param string $content Revision body.
	 * @return self
	 */
	public static function revision( int $id, int $revision, string $title, string $content ): self {
		return new self(
			array(
				'ID'            => $revision,
				'post_type'     => 'revision',
				'post_status'   => 'inherit',
				'post_parent'   => $id,
				'post_author'   => 21,
				'post_title'    => $title,
				'post_content'  => $content,
				'post_excerpt'  => '',
				'post_name'     => $revision . '-revision',
				'post_date'     => '2026-01-02 00:00:00',
				'post_modified' => '2026-01-02 00:00:00',
			)
		);
	}
}

/** Minimal post type with the reviewed capability object. */
final class SiteContentPostType {
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
	/** REST visibility.
	 *
	 * @var bool
	 */
	public bool $show_in_rest = true;
	/** REST controller class.
	 *
	 * @var string
	 */
	public string $rest_controller_class = 'WP_REST_Posts_Controller';
	/** REST base.
	 *
	 * @var string
	 */
	public string $rest_base = '';
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
	/** Declared supports.
	 *
	 * @var array<string,bool>
	 */
	public array $supports = array();

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
	 * Return the reviewed base map.
	 *
	 * @param string $base Base post type.
	 * @return array<string,string>
	 */
	public static function base_map( string $base = 'post' ): array {
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
			'create_posts'           => 'edit_' . $plural,
		);
	}

	/**
	 * Build one post type with the reviewed base map.
	 *
	 * @param string                    $name Type key.
	 * @param string|array<string>      $base Capability base.
	 * @param array<string,string>|null $caps Optional map override.
	 * @param bool                      $map Whether meta caps are mapped.
	 * @param bool                      $hierarchical Whether hierarchical.
	 * @return self
	 */
	public static function editorial( string $name, string|array $base = 'post', ?array $caps = null, bool $map = true, bool $hierarchical = false ): self {
		$map_base = is_array( $base ) ? (string) $base[0] : $base;
		return new self( $name, $caps ?? self::base_map( $map_base ), $base, $map, $hierarchical );
	}
}

/** Minimal user used by the eligibility seam. */
final class SiteContentUser {
	/** User ID.
	 *
	 * @var int
	 */
	public int $ID;
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
	/** Display name.
	 *
	 * @var string
	 */
	public string $display_name = 'Fixture';

	/**
	 * Build one user.
	 *
	 * @param int $id User ID.
	 */
	public function __construct( int $id ) {
		$this->ID = $id;
	}
}

/** Minimal database handle required by the live eligibility guard. */
final class SiteContentWpdb {
	/** Last observable database error.
	 *
	 * @var string
	 */
	public string $last_error = '';
}

/** Minimal role registry used by the real eligibility guard. */
final class SiteContentRoles {
	/** Stored roles.
	 *
	 * @var array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	public array $roles = array(
		'editor'        => array(
			'name'         => 'Editor',
			'capabilities' => array( 'edit_posts' => true ),
		),
		'administrator' => array(
			'name'         => 'Administrator',
			'capabilities' => array( 'manage_options' => true ),
		),
		'banned'        => array(
			'name'         => 'Banned',
			'capabilities' => array(),
		),
	);
}

/** Minimal REST meta field schema source. */
final class SiteContentMetaFields {
	/**
	 * Bind the post type.
	 *
	 * @param string $post_type Post type.
	 */
	public function __construct( private string $post_type = 'post' ) {
	}

	/**
	 * Return the registered field schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_field_schema(): array {
		$world      = SiteContentWorld::$current;
		$properties = array();
		if ( null === $world ) {
			return array( 'properties' => array() );
		}
		foreach ( $world->registered_meta as $key => $definition ) {
			if ( empty( $definition['show_in_rest'] ) ) {
				continue;
			}
			$field = array( 'type' => (string) ( $definition['type'] ?? 'string' ) );
			if ( 'array' === $field['type'] ) {
				$field['items'] = array( 'type' => 'string' );
			}
			$properties[ $key ] = $field;
		}
		return array( 'properties' => $properties );
	}
}

/** Minimal WP_Query replacement over the in-memory world. */
final class SiteContentQuery {
	/** Matched posts.
	 *
	 * @var array<int|SiteContentPost>
	 */
	public array $posts = array();
	/** Total matched rows.
	 *
	 * @var int
	 */
	public int $found_posts = 0;
	/** Total pages.
	 *
	 * @var int
	 */
	public int $max_num_pages = 0;

	/**
	 * Run one bounded in-memory query.
	 *
	 * @param array<string,mixed> $query Query arguments.
	 */
	public function __construct( array $query = array() ) {
		$world = SiteContentWorld::$current;
		if ( null === $world ) {
			return;
		}
		$types    = (array) ( $query['post_type'] ?? 'post' );
		$statuses = (array) ( $query['post_status'] ?? 'publish' );
		$matched  = array();
		foreach ( $world->posts as $post ) {
			if ( ! in_array( $post->post_type, $types, true ) || ! in_array( $post->post_status, $statuses, true ) ) {
				continue;
			}
			if ( ! empty( $query['has_password'] ) && '' !== $post->post_password ) {
				continue;
			}
			if ( isset( $query['author__in'] ) && ! in_array( $post->post_author, (array) $query['author__in'], true ) ) {
				continue;
			}
			if ( isset( $query['s'] ) && '' !== (string) $query['s'] ) {
				$needle = strtolower( (string) $query['s'] );
				if ( ! str_contains( strtolower( $post->post_title . ' ' . $post->post_content ), $needle ) ) {
					continue;
				}
			}
			if ( isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ) {
				$matched_taxonomy = true;
				foreach ( $query['tax_query'] as $clause ) {
					if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
						continue;
					}
					$assigned = $world->object_terms[ $post->ID ][ (string) $clause['taxonomy'] ] ?? array();
					if ( array() === array_intersect( (array) ( $clause['terms'] ?? array() ), $assigned ) ) {
						$matched_taxonomy = false;
					}
				}
				if ( ! $matched_taxonomy ) {
					continue;
				}
			}
			$matched[] = $post;
		}
		$orderby = (string) ( $query['orderby'] ?? 'date' );
		$order   = strtoupper( (string) ( $query['order'] ?? 'DESC' ) );
		usort(
			$matched,
			static function ( SiteContentPost $left, SiteContentPost $right ) use ( $orderby, $order ): int {
				$field = match ( $orderby ) {
					'title' => 'post_title',
					'ID', 'id' => 'ID',
					'name', 'slug' => 'post_name',
					'author' => 'post_author',
					default => 'post_date',
				};
				$result = $left->{$field} <=> $right->{$field};
				return 'ASC' === $order ? $result : -$result;
			}
		);
		$this->found_posts = count( $matched );
		$per_page          = (int) ( $query['posts_per_page'] ?? 10 );
		$paged             = max( 1, (int) ( $query['paged'] ?? 1 ) );
		if ( $per_page > 0 ) {
			$matched             = array_slice( $matched, ( $paged - 1 ) * $per_page, $per_page );
			$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
		}
		if ( 'ids' === (string) ( $query['fields'] ?? '' ) ) {
			$this->posts = array_map( static fn( SiteContentPost $post ): int => $post->ID, $matched );
			return;
		}
		$this->posts = $matched;
	}
}

/** Minimal ability catalog over the reviewed content definitions. */
final class SiteContentCatalog implements AbilityCatalog {
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

	/** {@inheritDoc} */
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

	/** {@inheritDoc} */
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
final class SiteContentBudget implements ExpensiveBudget {
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

/** In-memory WordPress content world for the fixed machine map. */
final class SiteContentWorld {
	/** Current world for class doubles.
	 *
	 * @var self|null
	 */
	public static ?self $current = null;
	/** Posts by ID.
	 *
	 * @var array<int,SiteContentPost>
	 */
	public array $posts = array();
	/** Post types by slug.
	 *
	 * @var array<string,SiteContentPostType>
	 */
	public array $post_types = array();
	/** Declared supports by type.
	 *
	 * @var array<string,array<string,bool>>
	 */
	public array $supports = array();
	/** Supported taxonomies by type.
	 *
	 * @var array<string,array<string>>
	 */
	public array $taxonomies = array();
	/** Terms by taxonomy and ID.
	 *
	 * @var array<string,array<int,string>>
	 */
	public array $terms = array();
	/** Assigned object terms.
	 *
	 * @var array<int,array<string,array<int>>>
	 */
	public array $object_terms = array();
	/** Registered meta definitions.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $registered_meta = array();
	/** Stored post meta.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $post_meta = array();
	/** Thumbnail IDs by post.
	 *
	 * @var array<int,int>
	 */
	public array $thumbnails = array();
	/** Sticky post IDs.
	 *
	 * @var array<int>
	 */
	public array $sticky = array();
	/** Theme templates by post type.
	 *
	 * @var array<string,array<string,string>>
	 */
	public array $templates = array();
	/** Assigned page templates.
	 *
	 * @var array<int,string>
	 */
	public array $page_template = array();
	/** Post formats.
	 *
	 * @var array<int,string>
	 */
	public array $formats = array();
	/** Options.
	 *
	 * @var array<string,int>
	 */
	public array $options = array();
	/** Users by ID.
	 *
	 * @var array<int,SiteContentUser>
	 */
	public array $users = array();
	/** Inserted write arguments.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $inserted = array();
	/** Updated write arguments.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $updated = array();
	/** Trashed post IDs.
	 *
	 * @var array<int>
	 */
	public array $trashed = array();
	/** Deleted post IDs.
	 *
	 * @var array<int>
	 */
	public array $deleted = array();
	/** Restored revision IDs.
	 *
	 * @var array<int>
	 */
	public array $restored = array();
	/** Meta keys written.
	 *
	 * @var array<string>
	 */
	public array $meta_writes = array();
	/** Meta writes to fail.
	 *
	 * @var array<string,bool>
	 */
	public array $fail_meta = array();
	/** Next generated post ID.
	 *
	 * @var int
	 */
	public int $next_id = 100;
	/** Current WordPress user ID.
	 *
	 * @var int
	 */
	public int $current_user = 0;
	/** Added filter hooks.
	 *
	 * @var array<string>
	 */
	public array $hooks_added = array();
	/** Removed filter hooks.
	 *
	 * @var array<string>
	 */
	public array $hooks_removed = array();

	/** Build the reviewed fixture world. */
	public function __construct() {
		$this->post_types['post']        = SiteContentPostType::editorial( 'post' );
		$this->post_types['page']        = SiteContentPostType::editorial( 'page', 'page', null, true, true );
		$this->post_types['order_post']  = new SiteContentPostType( 'order_post', array_reverse( SiteContentPostType::base_map( 'post' ), true ) );
		$this->post_types['array_post']  = SiteContentPostType::editorial( 'array_post', array( 'post', 'posts' ) );
		$this->post_types['book']        = new SiteContentPostType( 'book', SiteContentPostType::base_map( 'book' ), 'book' );
		$this->post_types['custom_post'] = new SiteContentPostType(
			'custom_post',
			array_merge( SiteContentPostType::base_map( 'post' ), array( 'edit_posts' => 'manage_options' ) )
		);
		$this->post_types['extra_post']  = new SiteContentPostType(
			'extra_post',
			array_merge( SiteContentPostType::base_map( 'post' ), array( 'manage_options' => 'manage_options' ) )
		);
		$this->post_types['meta_post']   = SiteContentPostType::editorial( 'meta_post', 'post', null, false );
		$this->post_types['attachment']  = new SiteContentPostType( 'attachment', SiteContentPostType::base_map( 'post' ) );
		$this->post_types['revision']    = new SiteContentPostType( 'revision', SiteContentPostType::base_map( 'post' ) );

		$this->supports['post']        = array_fill_keys( array( 'title', 'editor', 'author', 'excerpt', 'thumbnail', 'comments', 'revisions', 'custom-fields', 'post-formats' ), true );
		$this->supports['page']        = array_fill_keys( array( 'title', 'editor', 'author', 'excerpt', 'thumbnail', 'comments', 'revisions', 'custom-fields', 'page-attributes' ), true );
		$this->supports['order_post']  = $this->supports['post'];
		$this->supports['array_post']  = $this->supports['post'];
		$this->supports['book']        = $this->supports['post'];
		$this->supports['custom_post'] = $this->supports['post'];
		$this->supports['extra_post']  = $this->supports['post'];
		$this->supports['meta_post']   = $this->supports['post'];
		$this->supports['attachment']  = array_fill_keys( array( 'title', 'author', 'thumbnail' ), true );
		$this->supports['revision']    = array_fill_keys( array( 'title', 'author' ), true );

		$this->taxonomies['post'] = array( 'category', 'post_tag' );
		$this->taxonomies['page'] = array();

		$this->terms['category'] = array( 1 => 'News' );
		$this->terms['post_tag'] = array(
			2 => 'Fixture tag',
			3 => 'Other tag',
		);

		$this->registered_meta = array(
			'site_meta_fixture' => array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			),
			'site_array_meta'   => array(
				'type'          => 'array',
				'single'        => false,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			),
			'site_custom_meta'  => array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn(): bool => false,
			),
			'_site_protected'   => array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			),
		);

		$this->templates['page'] = array( 'templates/full.php' => 'Full width' );
		$this->templates['post'] = array();

		$this->posts[1]                    = new SiteContentPost(
			array(
				'ID'           => 1,
				'post_title'   => 'Alpha',
				'post_content' => 'Hello <b>world</b>',
				'post_excerpt' => 'Excerpt',
				'post_name'    => 'alpha',
				'post_status'  => 'publish',
				'post_author'  => 21,
			)
		);
		$this->posts[2]                    = new SiteContentPost(
			array(
				'ID'          => 2,
				'post_status' => 'trash',
				'post_title'  => 'Trashed',
				'post_author' => 21,
			)
		);
		$this->posts[3]                    = new SiteContentPost(
			array(
				'ID'          => 3,
				'post_title'  => 'Delete me',
				'post_status' => 'publish',
				'post_author' => 21,
			)
		);
		$this->posts[4]                    = new SiteContentPost(
			array(
				'ID'          => 4,
				'post_type'   => 'page',
				'post_title'  => 'A page',
				'post_status' => 'publish',
				'post_author' => 21,
			)
		);
		$this->posts[5]                    = new SiteContentPost(
			array(
				'ID'            => 5,
				'post_title'    => 'Protected',
				'post_content'  => 'Hidden body',
				'post_excerpt'  => 'Hidden excerpt',
				'post_password' => 'secret',
				'post_status'   => 'publish',
				'post_author'   => 21,
			)
		);
		$this->posts[6]                    = new SiteContentPost(
			array(
				'ID'          => 6,
				'post_title'  => 'Trash target',
				'post_status' => 'publish',
				'post_author' => 21,
			)
		);
		$this->posts[7]                    = new SiteContentPost(
			array(
				'ID'          => 7,
				'post_title'  => 'Bulk target',
				'post_status' => 'publish',
				'post_author' => 21,
			)
		);
		$this->posts[10]                   = SiteContentPost::revision( 1, 10, 'Alpha old', 'Old body' );
		$this->posts[30]                   = new SiteContentPost(
			array(
				'ID'          => 30,
				'post_type'   => 'attachment',
				'post_title'  => 'Image',
				'post_author' => 21,
			)
		);
		$this->object_terms[1]['category'] = array( 1 );
		$this->object_terms[1]['post_tag'] = array( 2 );

		$this->users[21]        = new SiteContentUser( 21 );
		$this->users[21]->roles = array( 'editor' );
		$this->users[22]        = new SiteContentUser( 22 );
		$this->users[22]->roles = array( 'administrator' );
		$this->users[23]        = new SiteContentUser( 23 );
		$this->users[23]->roles = array( 'banned' );
	}

	/**
	 * Insert one post from write arguments.
	 *
	 * @param array<string,mixed> $data Write arguments.
	 * @return int
	 */
	public function insert( array $data ): int {
		$this->inserted[] = $data;
		$id               = $this->next_id++;
		$post             = new SiteContentPost( array( 'ID' => $id ) );
		$this->apply( $post, $data );
		$this->posts[ $id ] = $post;
		return $id;
	}

	/**
	 * Update one post from write arguments.
	 *
	 * @param array<string,mixed> $data Write arguments.
	 * @return int
	 */
	public function update( array $data ): int {
		$this->updated[] = $data;
		$id              = (int) ( $data['ID'] ?? 0 );
		if ( ! isset( $this->posts[ $id ] ) ) {
			return 0;
		}
		$this->apply( $this->posts[ $id ], $data );
		return $id;
	}

	/**
	 * Apply allowlisted write fields.
	 *
	 * @param SiteContentPost     $post Stored post.
	 * @param array<string,mixed> $data Write arguments.
	 * @return void
	 */
	private function apply( SiteContentPost $post, array $data ): void {
		$map = array(
			'post_title'     => 'post_title',
			'post_content'   => 'post_content',
			'post_excerpt'   => 'post_excerpt',
			'post_status'    => 'post_status',
			'post_name'      => 'post_name',
			'post_author'    => 'post_author',
			'post_parent'    => 'post_parent',
			'menu_order'     => 'menu_order',
			'post_date'      => 'post_date',
			'post_date_gmt'  => 'post_date_gmt',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
			'post_type'      => 'post_type',
			'post_password'  => 'post_password',
		);
		foreach ( $map as $source => $target ) {
			if ( array_key_exists( $source, $data ) ) {
				$post->{$target} = $data[ $source ];
			}
		}
	}

	/**
	 * Move one post to the trash.
	 *
	 * @param int $id Post ID.
	 * @return SiteContentPost|false|null
	 */
	public function trash( int $id ): SiteContentPost|false|null {
		if ( ! isset( $this->posts[ $id ] ) ) {
			return null;
		}
		if ( 'trash' === $this->posts[ $id ]->post_status ) {
			return false;
		}
		$this->trashed[]                 = $id;
		$this->posts[ $id ]->post_status = 'trash';
		return $this->posts[ $id ];
	}

	/**
	 * Permanently delete one post.
	 *
	 * @param int $id Post ID.
	 * @return SiteContentPost|false|null
	 */
	public function delete( int $id ): SiteContentPost|false|null {
		if ( ! isset( $this->posts[ $id ] ) ) {
			return null;
		}
		$this->deleted[] = $id;
		$post            = $this->posts[ $id ];
		unset( $this->posts[ $id ] );
		return $post;
	}

	/**
	 * Restore one revision into its parent.
	 *
	 * @param int $revision Revision ID.
	 * @return int
	 */
	public function restore_revision( int $revision ): int {
		if ( ! isset( $this->posts[ $revision ] ) ) {
			return 0;
		}
		$record           = $this->posts[ $revision ];
		$parent           = (int) $record->post_parent;
		$this->restored[] = $revision;
		if ( isset( $this->posts[ $parent ] ) ) {
			$this->posts[ $parent ]->post_title   = $record->post_title;
			$this->posts[ $parent ]->post_content = $record->post_content;
		}
		return $parent;
	}

	/**
	 * Write one metadata value.
	 *
	 * @param int    $id Object ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function update_meta( int $id, string $key, mixed $value ): bool {
		$this->meta_writes[] = $key;
		if ( ! empty( $this->fail_meta[ $key ] ) ) {
			return false;
		}
		if ( '_wp_page_template' === $key ) {
			$this->page_template[ $id ] = (string) $value;
			return true;
		}
		$this->post_meta[ $id ][ $key ] = $value;
		return true;
	}

	/**
	 * Delete one metadata value.
	 *
	 * @param int    $id Object ID.
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function delete_meta( int $id, string $key ): bool {
		unset( $this->post_meta[ $id ][ $key ] );
		return true;
	}

	/**
	 * Resolve a revision list for one parent.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return array<int,SiteContentPost>
	 */
	public function revisions( int $parent_id ): array {
		$found = array();
		foreach ( $this->posts as $post ) {
			if ( 'revision' === $post->post_type && $parent_id === (int) $post->post_parent ) {
				$found[ $post->ID ] = $post;
			}
		}
		return $found;
	}

	/**
	 * Existing term IDs for one taxonomy.
	 *
	 * @param string     $taxonomy Taxonomy.
	 * @param array<int> $ids Requested IDs.
	 * @return array<int>
	 */
	public function existing_terms( string $taxonomy, array $ids ): array {
		$existing = array_keys( $this->terms[ $taxonomy ] ?? array() );
		return array_values( array_intersect( array_map( 'intval', $ids ), $existing ) );
	}

	/**
	 * Strip script, event handler and javascript payloads for unit assertions.
	 *
	 * @param string $value Content.
	 * @return string
	 */
	public static function strip( string $value ): string {
		$value = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value );
		$value = (string) preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $value );
		return str_ireplace( 'javascript:', '', $value );
	}
}

/**
 * Proves the machine content contract inside the exact five-file boundary.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SiteContentOperationsTest extends TestCase {
	/** Current blog. */
	private const BLOG = 1;

	/** Eligible attribution user. */
	private const ELIGIBLE = 21;

	/** Ineligible attribution user. */
	private const INELIGIBLE = 22;

	/** In-memory world.
	 *
	 * @var SiteContentWorld
	 */
	private SiteContentWorld $world;

	/** Machine names.
	 *
	 * @var array<string>
	 */
	private array $names = array();

	/** Assign-terms seam result.
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $terms_failure = null;

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		$this->alias_core_classes();
		$this->world               = new SiteContentWorld();
		SiteContentWorld::$current = $this->world;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only database double for the live eligibility guard.
		$GLOBALS['wpdb'] = new SiteContentWpdb();
		$this->stubs();
		$this->names = SiteContentOperations::NAMES;
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		SiteContentWorld::$current = null;
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The 19 reviewed names equal the reflected private map and the pack order. */
	public function test_names_are_the_reflected_reviewed_tools(): void {
		$constant = new \ReflectionClassConstant( ContentPack::class, 'TOOLS' );
		$tools    = $constant->getValue();
		self::assertIsArray( $tools );
		self::assertSame(
			array_map( static fn( string $key ): string => 'xnetvn/' . $key, array_keys( $tools ) ),
			SiteContentOperations::NAMES
		);
		self::assertTrue( array_is_list( SiteContentOperations::NAMES ) );
		self::assertCount( 19, SiteContentOperations::NAMES );
		self::assertSame( array_unique( SiteContentOperations::NAMES ), SiteContentOperations::NAMES );

		$yielded = array();
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$name = $definition->descriptor()->name;
			if ( 'xnetvn/list-content-authors' !== $name ) {
				$yielded[] = $name;
			}
		}
		self::assertSame( SiteContentOperations::NAMES, $yielded );
	}

	/** The handler is one two-parameter closure and the envelope fails closed. */
	public function test_handler_shape_and_execution_envelope(): void {
		$handler    = SiteContentOperations::handler( 'xnetvn/list-post-statuses' );
		$reflection = new \ReflectionFunction( $handler );
		self::assertSame( 2, $reflection->getNumberOfParameters() );
		self::assertSame( 'array', (string) $reflection->getParameters()[0]->getType() );
		self::assertSame( SiteAuthContext::class, (string) $reflection->getParameters()[1]->getType() );

		self::assertSame(
			$handler( array(), $this->context() ),
			( new SiteContentOperations( null, null, $this->authors(), $this->assign_terms() ) )->execute( 'xnetvn/list-post-statuses', array(), $this->context() )
		);

		$unknown = ( new SiteContentOperations() )->execute( 'xnetvn/nope', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unknown->get_error_code() );

		$cross = ( new SiteContentOperations() )->execute( 'xnetvn/list-post-statuses', array(), $this->context( self::BLOG + 1 ) );
		self::assertInstanceOf( \WP_Error::class, $cross );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $cross->get_error_code() );

		$this->world->current_user = 7;
		$human                     = ( new SiteContentOperations() )->execute( 'xnetvn/list-post-statuses', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $human );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $human->get_error_code() );
	}

	/** Every fixed built-in name is refused as an explicit registration. */
	public function test_registration_rejects_every_content_name(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		foreach ( $this->names as $name ) {
			$thrown = false;
			try {
				$dispatcher->register( $name, static fn( array $arguments, SiteAuthContext $context ): array => array() );
			} catch ( \InvalidArgumentException ) {
				$thrown = true;
			}
			self::assertTrue( $thrown, $name );
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
	}

	/** The type guard compares the exact reviewed class map. */
	public function test_type_guard_is_exact(): void {
		$operations = new SiteContentOperations( null, null, $this->authors(), $this->assign_terms() );
		foreach ( array( 'book', 'custom_post', 'extra_post', 'meta_post' ) as $slug ) {
			$result = $operations->execute( 'xnetvn/list-posts', array( 'post_type' => $slug ), $this->context() );
			self::assertInstanceOf( \WP_Error::class, $result, $slug );
			self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code(), $slug );
		}
		self::assertSame( array(), $this->world->inserted );
		self::assertSame( array(), $this->world->updated );
		self::assertSame( array(), $this->world->meta_writes );

		foreach ( array( 'post', 'page', 'order_post', 'array_post' ) as $slug ) {
			$result = $operations->execute( 'xnetvn/list-posts', array( 'post_type' => $slug ), $this->context() );
			self::assertIsArray( $result, $slug );
		}

		$create = $operations->execute(
			'xnetvn/create-post',
			array(
				'post_type' => 'custom_post',
				'title'     => 'Nope',
				'author'    => self::ELIGIBLE,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $create );
		self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $create->get_error_code() );
		self::assertSame( array(), $this->world->inserted );
	}

	/** Create and duplicate require an eligible author before any write. */
	public function test_create_and_duplicate_require_an_eligible_author(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		$missing    = $dispatcher->execute( 'xnetvn/create-post', array( 'title' => 'No author' ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $missing->get_error_code() );
		self::assertSame( array(), $this->world->inserted );

		foreach ( array( self::INELIGIBLE, 23, 99 ) as $author ) {
			$refused = $dispatcher->execute(
				'xnetvn/create-post',
				array(
					'title'  => 'Refused author',
					'author' => $author,
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $refused, (string) $author );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $refused->get_error_code(), (string) $author );
			self::assertSame( array(), $this->world->inserted );
		}

		$duplicate_missing = $dispatcher->execute( 'xnetvn/duplicate-post', array( 'id' => 1 ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $duplicate_missing );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $duplicate_missing->get_error_code() );
		self::assertSame( array(), $this->world->inserted );

		$duplicate_refused = $dispatcher->execute(
			'xnetvn/duplicate-post',
			array(
				'id'     => 1,
				'author' => self::INELIGIBLE,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $duplicate_refused );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $duplicate_refused->get_error_code() );
		self::assertSame( array(), $this->world->inserted );

		$created = $dispatcher->execute(
			'xnetvn/create-post',
			array(
				'title'   => 'Created',
				'content' => 'Body',
				'author'  => self::ELIGIBLE,
			),
			$this->context()
		);
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->message : '' );
		self::assertGreaterThanOrEqual( 1, $created['id'] );
		self::assertSame( self::ELIGIBLE, $this->world->posts[ $created['id'] ]->post_author );
	}

	/** Editorial HTML is filtered before a create persists it. */
	public function test_create_filters_html_and_persists(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		$created    = $dispatcher->execute(
			'xnetvn/create-post',
			array(
				'title'   => 'Title <script>alert(1)</script>',
				'content' => '<p onclick="alert(1)">Body</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
				'excerpt' => '<script>alert(1)</script>excerpt',
				'author'  => self::ELIGIBLE,
			),
			$this->context()
		);
		self::assertIsArray( $created, is_wp_error( $created ) ? $created->message : '' );
		$stored = $this->world->posts[ $created['id'] ];
		foreach ( array( $stored->post_title, $stored->post_content, $stored->post_excerpt ) as $value ) {
			self::assertStringNotContainsString( '<script', $value );
			self::assertStringNotContainsString( 'onclick', $value );
			self::assertStringNotContainsString( 'javascript:', $value );
		}
		self::assertSame( self::ELIGIBLE, $stored->post_author );
		self::assertSame( 'draft', $stored->post_status );
	}

	/** Update never passes the stored author and keeps the stored value. */
	public function test_update_never_changes_the_author(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		$updated    = $dispatcher->execute(
			'xnetvn/update-post',
			array(
				'id'    => 1,
				'title' => 'Updated',
			),
			$this->context()
		);
		self::assertIsArray( $updated, is_wp_error( $updated ) ? $updated->message : '' );
		self::assertSame( self::ELIGIBLE, $this->world->posts[1]->post_author );
		foreach ( $this->world->updated as $arguments ) {
			self::assertArrayNotHasKey( 'post_author', $arguments );
		}

		$forbidden = $dispatcher->execute(
			'xnetvn/update-post',
			array(
				'id'     => 1,
				'author' => self::INELIGIBLE,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $forbidden );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $forbidden->get_error_code() );
	}

	/** Bulk attribution is validated per item before that item's first write. */
	public function test_bulk_author_is_per_item(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		$result     = $dispatcher->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => array( 7, 1 ),
				'changes' => array( 'author' => self::ELIGIBLE ),
			),
			$this->context()
		);
		self::assertIsArray( $result, is_wp_error( $result ) ? $result->message : '' );
		self::assertSame( array( 7, 1 ), $result['updated'] );
		self::assertSame( self::ELIGIBLE, $this->world->posts[7]->post_author );
		foreach ( $this->world->updated as $arguments ) {
			self::assertSame( self::ELIGIBLE, $arguments['post_author'] ?? null );
		}

		$this->world->updated = array();
		$preserved            = $dispatcher->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => array( 7 ),
				'changes' => array( 'status' => 'draft' ),
			),
			$this->context()
		);
		self::assertIsArray( $preserved, is_wp_error( $preserved ) ? $preserved->message : '' );
		self::assertSame( array( 7 ), $preserved['updated'] );
		foreach ( $this->world->updated as $arguments ) {
			self::assertArrayNotHasKey( 'post_author', $arguments );
		}

		$status_before        = $this->world->posts[7]->post_status;
		$this->world->updated = array();
		$refused              = $dispatcher->execute(
			'xnetvn/bulk-update-posts',
			array(
				'ids'     => array( 7 ),
				'changes' => array(
					'author' => 23,
					'status' => 'private',
				),
			),
			$this->context()
		);
		self::assertIsArray( $refused, is_wp_error( $refused ) ? $refused->message : '' );
		self::assertSame( array(), $refused['updated'] );
		self::assertSame(
			array(
				array(
					'id'   => 7,
					'code' => 'forbidden',
				),
			),
			$refused['failed']
		);
		self::assertSame( array(), $this->world->updated );
		self::assertSame( $status_before, $this->world->posts[7]->post_status );
	}

	/** Object, status and site-page guards refuse before a write or projection. */
	public function test_object_state_and_site_page_guards(): void {
		$dispatcher = $this->dispatcher( $this->content_definitions() );

		$trashed = $dispatcher->execute(
			'xnetvn/update-post',
			array(
				'id'    => 2,
				'title' => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $trashed );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $trashed->get_error_code() );

		$not_trashed = $dispatcher->execute( 'xnetvn/restore-post', array( 'id' => 1 ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $not_trashed );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $not_trashed->get_error_code() );

		$raw = $dispatcher->execute(
			'xnetvn/get-post',
			array(
				'id'             => 5,
				'content_format' => 'raw',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $raw );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $raw->get_error_code() );

		$rendered = $dispatcher->execute( 'xnetvn/get-post', array( 'id' => 5 ), $this->context() );
		self::assertIsArray( $rendered, is_wp_error( $rendered ) ? $rendered->message : '' );
		self::assertArrayNotHasKey( 'content', $rendered );
		self::assertArrayNotHasKey( 'excerpt', $rendered );

		$page_stick = $dispatcher->execute(
			'xnetvn/stick-post',
			array(
				'id'        => 4,
				'post_type' => 'page',
				'sticky'    => true,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $page_stick );
		self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $page_stick->get_error_code() );

		$this->world->options['wp_page_for_privacy_policy'] = 4;
		$privacy = $dispatcher->execute(
			'xnetvn/update-post',
			array(
				'id'        => 4,
				'post_type' => 'page',
				'title'     => 'No',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $privacy );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $privacy->get_error_code() );

		$this->world->options['page_on_front'] = 3;
		$front_delete                          = $dispatcher->execute( 'xnetvn/delete-post', array( 'id' => 3 ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $front_delete );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $front_delete->get_error_code() );
		self::assertSame( array(), $this->world->deleted );
	}

	/** Registered, unprotected metadata only; null deletes and read-back failures are receipts. */
	public function test_meta_rules(): void {
		$dispatcher   = $this->dispatcher( $this->content_definitions() );
		$unregistered = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( 'site_unknown' => 'x' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $unregistered );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unregistered->get_error_code() );

		$protected = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( '_site_protected' => 'x' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $protected );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $protected->get_error_code() );

		$custom = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( 'site_custom_meta' => 'x' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $custom );
		self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $custom->get_error_code() );
		self::assertSame( array(), $this->world->meta_writes );

		$written = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( 'site_meta_fixture' => 'value' ),
			),
			$this->context()
		);
		self::assertIsArray( $written, is_wp_error( $written ) ? $written->message : '' );
		self::assertSame( 'value', $this->world->post_meta[1]['site_meta_fixture'] );
		self::assertSame( 'value', $written['meta']->site_meta_fixture );

		$deleted = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( 'site_meta_fixture' => null ),
			),
			$this->context()
		);
		self::assertIsArray( $deleted, is_wp_error( $deleted ) ? $deleted->message : '' );
		self::assertArrayNotHasKey( 'site_meta_fixture', $this->world->post_meta[1] );

		$this->world->fail_meta['site_meta_fixture'] = true;
		$failed                                      = $dispatcher->execute(
			'xnetvn/update-post-meta',
			array(
				'id'     => 1,
				'values' => array( 'site_meta_fixture' => 'lost' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $failed );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $failed->get_error_code() );
		self::assertSame( array( 'affected_id' => 1 ), $failed->data );
	}

	/** A post-write term failure returns the truthful receipt without a term value. */
	public function test_term_failure_is_a_truthful_receipt(): void {
		$operations = new SiteContentOperations( null, null, $this->authors(), $this->failing_terms() );
		$create     = $operations->execute(
			'xnetvn/create-post',
			array(
				'title'  => 'Created',
				'tags'   => array( 2 ),
				'author' => self::ELIGIBLE,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $create );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $create->get_error_code() );
		self::assertSame( array( 'created_id' => 100 ), $create->data );
		self::assertStringContainsString( 'created_id=100', $create->message );
		self::assertStringNotContainsString( 'Fixture tag', $create->message );
		self::assertStringNotContainsString( '2', $create->message );

		$update = $operations->execute(
			'xnetvn/update-post',
			array(
				'id'   => 1,
				'tags' => array( 2 ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $update );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $update->get_error_code() );
		self::assertSame( array( 'affected_id' => 1 ), $update->data );
	}

	/** A post-write metadata failure keeps the persisted post ID. */
	public function test_meta_failure_after_create_is_a_receipt(): void {
		$this->world->fail_meta['site_meta_fixture'] = true;
		$operations                                  = new SiteContentOperations( null, null, $this->authors(), $this->assign_terms() );
		$create                                      = $operations->execute(
			'xnetvn/create-post',
			array(
				'title'  => 'Meta failure',
				'meta'   => array( 'site_meta_fixture' => 'lost' ),
				'author' => self::ELIGIBLE,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $create );
		self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $create->get_error_code() );
		self::assertSame( array( 'created_id' => 100 ), $create->data );
		self::assertArrayNotHasKey( 'site_meta_fixture', $this->world->post_meta[100] ?? array() );
	}

	/** Trash is refused when core would escalate it to permanent deletion. */
	public function test_trash_requires_trash_support(): void {
		if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
			define( 'EMPTY_TRASH_DAYS', 0 );
		}
		$dispatcher = $this->dispatcher( $this->content_definitions() );
		$result     = $dispatcher->execute( 'xnetvn/trash-post', array( 'id' => 6 ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_unsupported_operation', $result->get_error_code() );
		self::assertSame( array(), $this->world->trashed );
		self::assertSame( array(), $this->world->deleted );
		self::assertSame( 'publish', $this->world->posts[6]->post_status );
	}

	/** Every reviewed name reaches exactly one operation branch. */
	public function test_every_name_dispatches(): void {
		$operations = new SiteContentOperations( null, null, $this->authors(), $this->assign_terms() );
		$arguments  = $this->valid_arguments();
		self::assertCount( 19, $arguments );
		foreach ( $this->names as $name ) {
			self::assertArrayHasKey( $name, $arguments );
			$result = $operations->execute( $name, $arguments[ $name ], $this->context() );
			if ( 'xnetvn/create-post' === $name || 'xnetvn/update-post' === $name || 'xnetvn/trash-post' === $name || 'xnetvn/restore-post' === $name || 'xnetvn/delete-post' === $name || 'xnetvn/duplicate-post' === $name || 'xnetvn/set-featured-media' === $name || 'xnetvn/stick-post' === $name || 'xnetvn/restore-revision' === $name ) {
				self::assertInstanceOf( MutationResult::class, $result, $name );
				continue;
			}
			self::assertIsArray( $result, $name . ( is_wp_error( $result ) ? ': ' . $result->message : '' ) );
		}
	}

	/** Registry execution agrees with the dispatcher for one allowed and one denied row. */
	public function test_registry_parity_for_allowed_and_denied_rows(): void {
		$this->world->options['wp_page_for_privacy_policy'] = 0;
		$settings = new MemorySettings();
		$scope    = new SiteScope(
			RiskTier::Destructive,
			array( 'content' ),
			array( 'xnetvn/list-post-statuses' ),
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		$context  = $this->context_for( $scope );
		$request  = new RequestScope();
		$request->bind_site( $context );
		$catalog    = new SiteContentCatalog( $this->content_definitions() );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ) );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ), null, new SafeMcpEnvelope(), new SitePolicy( $settings ), $dispatcher );
		foreach ( $this->content_definitions() as $definition ) {
			$registry->add( $definition );
		}
		self::assertSame( array( 'xnetvn/list-post-statuses' ), $registry->names_for_site_scope( $scope ) );
		$allowed = $registry->execute( 'xnetvn/list-post-statuses', array() );
		self::assertIsArray( $allowed );
		self::assertCount( 5, $allowed['items'] );
		$denied = $registry->execute(
			'xnetvn/update-post',
			array(
				'id'    => 1,
				'title' => 'No',
			)
		);
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $denied->get_error_code() );
	}

	/** Register the isolated core-class doubles. */
	private function alias_core_classes(): void {
		if ( ! class_exists( 'WP_Post', false ) ) {
			class_alias( SiteContentPost::class, 'WP_Post' );
		}
		if ( ! class_exists( 'WP_Post_Type', false ) ) {
			class_alias( SiteContentPostType::class, 'WP_Post_Type' );
		}
		if ( ! class_exists( 'WP_User', false ) ) {
			class_alias( SiteContentUser::class, 'WP_User' );
		}
		if ( ! class_exists( 'WP_Roles', false ) ) {
			class_alias( SiteContentRoles::class, 'WP_Roles' );
		}
		if ( ! class_exists( 'wpdb', false ) ) {
			class_alias( SiteContentWpdb::class, 'wpdb' );
		}
		if ( ! class_exists( 'WP_Query', false ) ) {
			class_alias( SiteContentQuery::class, 'WP_Query' );
		}
		if ( ! class_exists( 'WP_REST_Post_Meta_Fields', false ) ) {
			class_alias( SiteContentMetaFields::class, 'WP_REST_Post_Meta_Fields' );
		}
	}

	/** Install the fixed WordPress substitutes. */
	private function stubs(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->world->current_user );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_has_ability' )->justReturn( true );
		Functions\when( 'get_post_type_object' )->alias( fn( string $slug ): ?SiteContentPostType => $this->world->post_types[ $slug ] ?? null );
		Functions\when( 'rest_get_route_for_post_type_items' )->alias( static fn( string $slug ): string => '/wp/v2/' . $slug );
		Functions\when( 'get_post_types' )->alias( fn(): array => $this->world->post_types );
		Functions\when( 'get_all_post_type_supports' )->alias( fn( string $slug ): array => $this->world->supports[ $slug ] ?? array() );
		Functions\when( 'post_type_supports' )->alias( fn( string $slug, string $support ): bool => isset( $this->world->supports[ $slug ][ $support ] ) );
		Functions\when( 'is_object_in_taxonomy' )->alias( fn( string $slug, string $taxonomy ): bool => in_array( $taxonomy, $this->world->taxonomies[ $slug ] ?? array(), true ) );
		Functions\when( 'get_option' )->alias( fn( string $name ): mixed => $this->world->options[ $name ] ?? 0 );
		Functions\when( 'get_post' )->alias( fn( int $id ): ?SiteContentPost => $this->world->posts[ $id ] ?? null );
		Functions\when( 'get_permalink' )->alias( static fn( SiteContentPost $post ): string => 'https://example.test/?p=' . $post->ID );
		Functions\when( 'get_post_thumbnail_id' )->alias( fn( int $id ): int => $this->world->thumbnails[ $id ] ?? 0 );
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $id, int $thumbnail ): bool {
				if ( ! isset( $this->world->posts[ $thumbnail ] ) ) {
					return false;
				}
				$this->world->thumbnails[ $id ] = $thumbnail;
				return true;
			}
		);
		Functions\when( 'delete_post_thumbnail' )->alias(
			function ( int $id ): bool {
				unset( $this->world->thumbnails[ $id ] );
				return true;
			}
		);
		Functions\when( 'is_sticky' )->alias( fn( int $id ): bool => in_array( $id, $this->world->sticky, true ) );
		Functions\when( 'stick_post' )->alias(
			function ( int $id ): bool {
				$this->world->sticky[] = $id;
				return true;
			}
		);
		Functions\when( 'unstick_post' )->alias(
			function ( int $id ): bool {
				$this->world->sticky = array_values( array_diff( $this->world->sticky, array( $id ) ) );
				return true;
			}
		);
		Functions\when( 'get_page_template_slug' )->alias( fn( int $id ): string => $this->world->page_template[ $id ] ?? '' );
		Functions\when( 'get_post_format' )->alias( fn( int $id ): string|false => $this->world->formats[ $id ] ?? false );
		Functions\when( 'get_post_format_slugs' )->justReturn( array( 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat' ) );
		Functions\when( 'set_post_format' )->alias(
			function ( int $id, string $format ): array {
				$this->world->formats[ $id ] = $format;
				return array( 'id' => $id );
			}
		);
		Functions\when( 'get_page_templates' )->alias( fn( mixed $post = null, string $post_type = 'page' ): array => $this->world->templates[ $post_type ] ?? array() );
		Functions\when( 'get_ancestors' )->alias(
			function ( int $id, string $type = '' ): array {
				$ancestors = array();
				$current   = $id;
				while ( isset( $this->world->posts[ $current ] ) && $this->world->posts[ $current ]->post_parent > 0 ) {
					$current     = $this->world->posts[ $current ]->post_parent;
					$ancestors[] = $current;
				}
				return $ancestors;
			}
		);
		Functions\when( 'get_registered_meta_keys' )->alias(
			function ( string $object_type = '', string $object_subtype = '' ): array {
				return 'post' === $object_type ? $this->world->registered_meta : array();
			}
		);
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'rest_sanitize_value_from_schema' )->alias( static fn( mixed $value ): mixed => $value );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				$value = $this->world->post_meta[ $id ][ $key ] ?? null;
				if ( $single ) {
					return $value ?? '';
				}
				return null === $value ? array() : (array) $value;
			}
		);
		Functions\when( 'metadata_exists' )->alias( fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $this->world->post_meta[ $id ] ?? array() ) );
		Functions\when( 'update_metadata' )->alias( fn( string $type, int $id, string $key, mixed $value ): bool => $this->world->update_meta( $id, $key, $value ) );
		Functions\when( 'delete_metadata' )->alias( fn( string $type, int $id, string $key ): bool => $this->world->delete_meta( $id, $key ) );
		Functions\when( 'wp_get_object_terms' )->alias( fn( int $id, string $taxonomy, array $args = array() ): array => $this->world->object_terms[ $id ][ $taxonomy ] ?? array() );
		Functions\when( 'get_terms' )->alias(
			function ( array $args = array() ): array {
				$taxonomy = (string) ( $args['taxonomy'] ?? '' );
				$ids      = (array) ( $args['include'] ?? array() );
				return $this->world->existing_terms( $taxonomy, array_map( 'intval', $ids ) );
			}
		);
		Functions\when( 'wp_get_post_revisions' )->alias( fn( int $id, array $args = array() ): array => $this->world->revisions( $id ) );
		Functions\when( 'wp_restore_post_revision' )->alias( fn( int $id ): int => $this->world->restore_revision( $id ) );
		Functions\when( 'wp_insert_post' )->alias( fn( array $data, bool $wp_error = false ): int => $this->world->insert( $data ) );
		Functions\when( 'wp_update_post' )->alias( fn( array $data, bool $wp_error = false ): int => $this->world->update( $data ) );
		Functions\when( 'wp_trash_post' )->alias( fn( int $id ): SiteContentPost|false|null => $this->world->trash( $id ) );
		Functions\when( 'wp_delete_post' )->alias( fn( int $id, bool $force = false ): SiteContentPost|false|null => $this->world->delete( $id ) );
		Functions\when( 'wp_slash' )->alias( static fn( mixed $value ): mixed => $value );
		Functions\when( 'get_date_from_gmt' )->alias( static fn( string $value, string $format = 'Y-m-d H:i:s' ): string => $value );
		Functions\when( 'get_userdata' )->alias( fn( int $id ): ?SiteContentUser => $this->world->users[ $id ] ?? null );
		Functions\when( 'is_user_member_of_blog' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_super_admin' )->justReturn( false );
		Functions\when( 'wp_roles' )->justReturn( new SiteContentRoles() );
		Functions\when( 'user_can' )->alias(
			static function ( SiteContentUser $user, string $capability ): bool {
				if ( in_array( $capability, array( 'edit_posts', 'edit_pages' ), true ) ) {
					return in_array( $user->ID, array( 21, 22 ), true );
				}
				if ( 'use_xnetvn_agent_hub_wp' === $capability ) {
					return 21 === $user->ID;
				}
				return in_array( 'administrator', $user->roles, true );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( wp_strip_all_tags( $value ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => (string) preg_replace( '#<[^>]*>#', '', $value ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $value ): string => SiteContentWorld::strip( $value ) );
		Functions\when( 'wp_filter_post_kses' )->alias( static fn( string $value ): string => SiteContentWorld::strip( $value ) );
		Functions\when( 'wp_filter_kses' )->alias( static fn( string $value ): string => SiteContentWorld::strip( $value ) );
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
	}

	/**
	 * Build the injected eligibility seam.
	 *
	 * @return EditorialAuthors
	 */
	private function authors(): EditorialAuthors {
		return new EditorialAuthors(
			static fn( int $id ): Decision => self::ELIGIBLE === $id
				? Decision::permit()
				: Decision::deny( 'delegation_protected_account' )
		);
	}

	/**
	 * Build a successful term-assignment seam.
	 *
	 * @return Closure
	 */
	private function assign_terms(): Closure {
		return function ( int $post_id, string $taxonomy, array $term_ids, bool $append ): array {
			if ( $append ) {
				$current  = $this->world->object_terms[ $post_id ][ $taxonomy ] ?? array();
				$term_ids = array_values( array_unique( array_merge( $current, $term_ids ) ) );
			}
			$this->world->object_terms[ $post_id ][ $taxonomy ] = array_values( array_map( 'intval', $term_ids ) );
			return array(
				'post_id'  => $post_id,
				'taxonomy' => $taxonomy,
				'term_ids' => $term_ids,
			);
		};
	}

	/**
	 * Build a failing term-assignment seam.
	 *
	 * @return Closure
	 */
	private function failing_terms(): Closure {
		return static function ( int $post_id, string $taxonomy, array $term_ids, bool $append ): \WP_Error {
			return new \WP_Error( 'xnetvn_agent_hub_wp_not_found', 'The requested object is unavailable.' );
		};
	}

	/**
	 * Build the reviewed content definitions by name.
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
	 * Build a dispatcher bound to the reviewed catalog.
	 *
	 * @param array<string,AbilitySpec> $definitions Definitions.
	 * @param ExpensiveBudget|null      $budget Optional budget.
	 * @return SiteDispatcher
	 */
	private function dispatcher( array $definitions, ?ExpensiveBudget $budget = null ): SiteDispatcher {
		$settings   = new MemorySettings();
		$catalog    = new SiteContentCatalog( $definitions );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		return $dispatcher;
	}

	/**
	 * Build one machine identity.
	 *
	 * @param int $blog_id Credential blog.
	 * @return SiteAuthContext
	 */
	private function context( int $blog_id = self::BLOG ): SiteAuthContext {
		$scope = new SiteScope(
			RiskTier::Destructive,
			array( 'content' ),
			SiteContentOperations::NAMES,
			array(),
			array(),
			array(),
			new DateTimeImmutable( '@' . ( time() + 3600 ) )
		);
		return $this->context_for( $scope, $blog_id );
	}

	/**
	 * Build a machine identity for one scope.
	 *
	 * @param SiteScope $scope Scope.
	 * @param int       $blog_id Credential blog.
	 * @return SiteAuthContext
	 */
	private function context_for( SiteScope $scope, int $blog_id = self::BLOG ): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( 'abcdefghijkl' ), $blog_id, 'Fixture', $scope, new DateTimeImmutable( '@' . time() ) );
		return new SiteAuthContext( $credential, '0123456789abcdef', '203.0.113.7' );
	}

	/**
	 * Valid arguments per reviewed operation.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function valid_arguments(): array {
		return array(
			'xnetvn/list-post-types'    => array(),
			'xnetvn/list-post-statuses' => array(),
			'xnetvn/list-posts'         => array( 'post_type' => 'post' ),
			'xnetvn/get-post'           => array( 'id' => 1 ),
			'xnetvn/create-post'        => array(
				'title'  => 'Created',
				'author' => self::ELIGIBLE,
			),
			'xnetvn/update-post'        => array(
				'id'    => 1,
				'title' => 'Updated',
			),
			'xnetvn/trash-post'         => array( 'id' => 6 ),
			'xnetvn/restore-post'       => array( 'id' => 2 ),
			'xnetvn/delete-post'        => array( 'id' => 3 ),
			'xnetvn/duplicate-post'     => array(
				'id'     => 1,
				'author' => self::ELIGIBLE,
			),
			'xnetvn/bulk-update-posts'  => array(
				'ids'     => array( 7 ),
				'changes' => array( 'status' => 'draft' ),
			),
			'xnetvn/list-revisions'     => array( 'id' => 1 ),
			'xnetvn/get-revision'       => array(
				'id'          => 1,
				'revision_id' => 10,
			),
			'xnetvn/restore-revision'   => array(
				'id'          => 1,
				'revision_id' => 10,
			),
			'xnetvn/set-featured-media' => array(
				'id'       => 1,
				'media_id' => 30,
			),
			'xnetvn/stick-post'         => array(
				'id'     => 1,
				'sticky' => true,
			),
			'xnetvn/get-post-meta'      => array( 'id' => 1 ),
			'xnetvn/update-post-meta'   => array(
				'id'     => 1,
				'values' => array( 'site_meta_fixture' => 'value' ),
			),
			'xnetvn/search-content'     => array( 'search' => 'Alpha' ),
		);
	}
}
