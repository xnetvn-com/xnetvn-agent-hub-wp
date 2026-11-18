<?php
/**
 * Machine taxonomy operations, fixed map and dispatcher resolution.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test-only WordPress doubles and fixed handler signatures.

use Brain\Monkey;
use Brain\Monkey\Functions;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\SiteTaxonomyOperations;
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
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Minimal taxonomy object for the resolver and the fixed map. */
final class SiteTaxonomyTax {
	/** Taxonomy key.
	 *
	 * @var string
	 */
	public string $name;
	/** Display label.
	 *
	 * @var string
	 */
	public string $label;
	/** Description.
	 *
	 * @var string
	 */
	public string $description;
	/** Whether the taxonomy is hierarchical.
	 *
	 * @var bool
	 */
	public bool $hierarchical;
	/** REST base.
	 *
	 * @var string|false
	 */
	public $rest_base;
	/** REST namespace.
	 *
	 * @var string
	 */
	public string $rest_namespace = 'wp/v2';
	/** REST controller.
	 *
	 * @var string|null
	 */
	public $rest_controller_class = 'WP_REST_Terms_Controller';
	/** Managed object types.
	 *
	 * @var array<string>
	 */
	public array $object_type;
	/** Capability object.
	 *
	 * @var object
	 */
	public object $cap;
	/** Whether the taxonomy is exposed in REST.
	 *
	 * @var bool
	 */
	public bool $show_in_rest = true;
	/** Registered default term.
	 *
	 * @var array<string,string>|string
	 */
	public $default_term = '';

	/**
	 * Build one reviewed taxonomy.
	 *
	 * @param string                      $name Taxonomy key.
	 * @param bool                        $hierarchical Whether hierarchical.
	 * @param array<string>               $object_type Managed object types.
	 * @param string|false                $rest_base REST base.
	 * @param array<string>|null          $caps Optional fixed map values.
	 * @param array<string,string>|string $default_term Default term.
	 */
	public function __construct( string $name, bool $hierarchical, array $object_type, $rest_base = false, ?array $caps = null, $default_term = '' ) {
		$caps               = $caps ?? array(
			'manage_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'assign_terms' => 'edit_posts',
		);
		$this->name         = $name;
		$this->label        = ucfirst( $name );
		$this->description  = '';
		$this->hierarchical = $hierarchical;
		$this->rest_base    = $rest_base;
		$this->object_type  = $object_type;
		$this->default_term = $default_term;
		$this->cap          = (object) $caps;
	}
}

/** Minimal term object. */
final class SiteTaxonomyTerm {
	/** Term ID.
	 *
	 * @var int
	 */
	public int $term_id;
	/** Taxonomy key.
	 *
	 * @var string
	 */
	public string $taxonomy;
	/** Term name.
	 *
	 * @var string
	 */
	public string $name;
	/** Term slug.
	 *
	 * @var string
	 */
	public string $slug;
	/** Term description.
	 *
	 * @var string
	 */
	public string $description;
	/** Parent term.
	 *
	 * @var int
	 */
	public int $parent;
	/** Published count.
	 *
	 * @var int
	 */
	public int $count;

	/**
	 * Build one stored term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @param string $name Term name.
	 * @param string $slug Term slug.
	 * @param string $description Term description.
	 * @param int    $parent_id Parent term.
	 * @param int    $count Published count.
	 */
	public function __construct( int $term_id, string $taxonomy, string $name, string $slug, string $description = '', int $parent_id = 0, int $count = 0 ) {
		$this->term_id     = $term_id;
		$this->taxonomy    = $taxonomy;
		$this->name        = $name;
		$this->slug        = $slug;
		$this->description = $description;
		$this->parent      = $parent_id;
		$this->count       = $count;
	}
}

/** Minimal post type object. */
final class SiteTaxonomyPostType {
	/** Post type key.
	 *
	 * @var string
	 */
	public string $name;
	/** Whether REST exposes the type.
	 *
	 * @var bool
	 */
	public bool $show_in_rest = true;
	/** REST controller.
	 *
	 * @var string
	 */
	public string $rest_controller_class = 'WP_REST_Posts_Controller';
	/** Capability base.
	 *
	 * @var string|array<string>
	 */
	public $capability_type = 'post';

	/**
	 * Build one post type.
	 *
	 * @param string               $name Key.
	 * @param string|array<string> $capability_type Capability base.
	 */
	public function __construct( string $name, $capability_type = 'post' ) {
		$this->name            = $name;
		$this->capability_type = $capability_type;
	}
}

/** Minimal post object. */
final class SiteTaxonomyPost {
	/** Post type.
	 *
	 * @var string
	 */
	public string $post_type;
	/** Post status.
	 *
	 * @var string
	 */
	public string $post_status;

	/**
	 * Build one post.
	 *
	 * @param string $post_type Post type.
	 * @param string $post_status Post status.
	 */
	public function __construct( string $post_type, string $post_status ) {
		$this->post_type   = $post_type;
		$this->post_status = $post_status;
	}
}

/** Minimal core term meta field schema source. */
final class SiteTaxonomyMetaFields {
	/**
	 * Bind the taxonomy key.
	 *
	 * @param string $taxonomy Taxonomy key.
	 */
	public function __construct( private string $taxonomy ) {
	}

	/**
	 * Return the registered show_in_rest term meta schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_field_schema(): array {
		$world      = SiteTaxonomyWorld::current();
		$properties = array();
		foreach ( $world->registered_meta[ $this->taxonomy ] ?? array() as $key => $definition ) {
			if ( empty( $definition['show_in_rest'] ) ) {
				continue;
			}
			$properties[ $key ] = array(
				'type'    => (string) ( $definition['type'] ?? 'string' ),
				'default' => $definition['default'] ?? '',
			);
		}
		return array(
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}

/** Minimal core request object. */
final class SiteTaxonomyRequest implements \ArrayAccess {
	/** HTTP method.
	 *
	 * @var string
	 */
	private string $method;
	/** Requested route.
	 *
	 * @var string
	 */
	private string $route;
	/** Query parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $query = array();

	/**
	 * Bind a trusted request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route Route.
	 */
	public function __construct( string $method, string $route ) {
		$this->method = $method;
		$this->route  = $route;
	}

	/**
	 * Record query parameters.
	 *
	 * @param array<string,mixed> $params Parameters.
	 * @return void
	 */
	public function set_query_params( array $params ): void {
		$this->query = $params;
	}

	/**
	 * Return the route.
	 *
	 * @return string
	 */
	public function get_route(): string {
		return $this->route;
	}

	/**
	 * Return the method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Whether a body parameter exists.
	 *
	 * @param mixed $offset Offset.
	 * @return bool
	 */
	public function offsetExists( mixed $offset ): bool {
		return false;
	}

	/**
	 * Read a body parameter.
	 *
	 * @param mixed $offset Offset.
	 * @return mixed
	 */
	public function offsetGet( mixed $offset ): mixed {
		return null;
	}

	/**
	 * Ignore a body write.
	 *
	 * @param mixed $offset Offset.
	 * @param mixed $value Value.
	 * @return void
	 */
	public function offsetSet( mixed $offset, mixed $value ): void {
	}

	/**
	 * Ignore a body delete.
	 *
	 * @param mixed $offset Offset.
	 * @return void
	 */
	public function offsetUnset( mixed $offset ): void {
	}
}

/** Minimal core response object. */
final class SiteTaxonomyResponse {
	/**
	 * Bind response data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public function __construct( private array $data ) {
	}

	/**
	 * Whether the response is an error.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return false;
	}

	/**
	 * Return the response data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}
}

/** In-memory WordPress vocabulary for one isolated test. */
final class SiteTaxonomyWorld {
	/** Active world for core-class doubles.
	 *
	 * @var self|null
	 */
	private static ?self $current = null;
	/** Taxonomies by key.
	 *
	 * @var array<string,SiteTaxonomyTax>
	 */
	public array $taxonomies = array();
	/** Post types by key.
	 *
	 * @var array<string,SiteTaxonomyPostType>
	 */
	public array $post_types = array();
	/** Posts by ID.
	 *
	 * @var array<int,SiteTaxonomyPost>
	 */
	public array $posts = array();
	/** Terms by ID.
	 *
	 * @var array<int,SiteTaxonomyTerm>
	 */
	public array $terms = array();
	/** Object relationships by post ID.
	 *
	 * @var array<int,array<int>>
	 */
	public array $relationships = array();
	/** Options.
	 *
	 * @var array<string,mixed>
	 */
	public array $options = array();
	/** Registered term meta by taxonomy.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public array $registered_meta = array();
	/** Term meta values by term ID.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $meta = array();
	/** Object type to taxonomy support.
	 *
	 * @var array<string,array<string>>
	 */
	public array $supports = array();
	/** Resolved taxonomy keys.
	 *
	 * @var array<string>
	 */
	public array $resolved = array();
	/** Recorded term inserts.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $inserted = array();
	/** Recorded term updates.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $updated = array();
	/** Recorded term deletions.
	 *
	 * @var array<int>
	 */
	public array $deleted = array();
	/** Recorded relationship writes.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $assigned = array();
	/** Recorded meta writes.
	 *
	 * @var array<array<string,mixed>>
	 */
	public array $meta_writes = array();
	/** Recorded counting deferrals.
	 *
	 * @var array<bool>
	 */
	public array $deferred = array();
	/** Recorded added hook names.
	 *
	 * @var array<string>
	 */
	public array $hooks_added = array();
	/** Recorded removed hook names.
	 *
	 * @var array<string>
	 */
	public array $hooks_removed = array();
	/** Next term ID.
	 *
	 * @var int
	 */
	public int $next_term_id = 100;
	/** Whether relationship writes are silently dropped.
	 *
	 * @var bool
	 */
	public bool $noop_write = false;
	/** Whether term links are unavailable.
	 *
	 * @var bool
	 */
	public bool $link_error = false;
	/** Whether deletion reports a core failure.
	 *
	 * @var bool
	 */
	public bool $delete_fails = false;
	/** Whether meta writes report a core failure.
	 *
	 * @var bool
	 */
	public bool $meta_fails = false;
	/** Whether meta writes report unchanged while storing the value.
	 *
	 * @var bool
	 */
	public bool $meta_noop = false;
	/** Current term-count deferral state.
	 *
	 * @var bool
	 */
	public bool $defer_state = false;
	/** Response data for the GET read path.
	 *
	 * @var array<string,mixed>
	 */
	public array $rest_data = array();

	/** Publish this world for the core-class doubles. */
	public function __construct() {
		self::$current = $this;
	}

	/** Return the active world. */
	public static function current(): self {
		if ( null === self::$current ) {
			self::$current = new self();
		}
		return self::$current;
	}

	/**
	 * Register one taxonomy.
	 *
	 * @param SiteTaxonomyTax $taxonomy Taxonomy.
	 * @return void
	 */
	public function add_taxonomy( SiteTaxonomyTax $taxonomy ): void {
		$this->taxonomies[ $taxonomy->name ] = $taxonomy;
	}

	/**
	 * Register one post type.
	 *
	 * @param SiteTaxonomyPostType $type Post type.
	 * @param array<string>        $taxonomies Supported taxonomies.
	 * @return void
	 */
	public function add_post_type( SiteTaxonomyPostType $type, array $taxonomies ): void {
		$this->post_types[ $type->name ] = $type;
		$this->supports[ $type->name ]   = $taxonomies;
	}

	/**
	 * Register one term.
	 *
	 * @param int    $id Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @param string $name Name.
	 * @param string $slug Slug.
	 * @param int    $parent_id Parent.
	 * @return void
	 */
	public function add_term( int $id, string $taxonomy, string $name, string $slug, int $parent_id = 0 ): void {
		$this->terms[ $id ] = new SiteTaxonomyTerm( $id, $taxonomy, $name, $slug, '', $parent_id );
	}

	/**
	 * Resolve one taxonomy key.
	 *
	 * @param string $slug Key.
	 * @return SiteTaxonomyTax|false
	 */
	public function taxonomy( string $slug ) {
		$this->resolved[] = $slug;
		return $this->taxonomies[ $slug ] ?? false;
	}

	/**
	 * Resolve one post type key.
	 *
	 * @param string $slug Key.
	 * @return SiteTaxonomyPostType|null
	 */
	public function post_type( string $slug ): ?SiteTaxonomyPostType {
		return $this->post_types[ $slug ] ?? null;
	}

	/**
	 * Return the expected term route for a taxonomy key.
	 *
	 * @param string $slug Key.
	 * @return string
	 */
	public function term_route( string $slug ): string {
		$taxonomy = $this->taxonomies[ $slug ] ?? null;
		if ( null === $taxonomy ) {
			return '';
		}
		$base = $taxonomy->rest_base ? (string) $taxonomy->rest_base : $taxonomy->name;
		return '/' . $taxonomy->rest_namespace . '/' . $base;
	}

	/**
	 * Return one stored term for its taxonomy.
	 *
	 * @param mixed  $id Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return SiteTaxonomyTerm|false|null
	 */
	public function term( $id, string $taxonomy ) {
		$term_id = is_int( $id ) ? $id : (int) $id;
		$term    = $this->terms[ $term_id ] ?? null;
		if ( null === $term ) {
			return null;
		}
		return $term->taxonomy === $taxonomy ? $term : false;
	}

	/**
	 * Query stored terms.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<int,SiteTaxonomyTerm>|int
	 */
	public function query_terms( array $args ) {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		$terms    = array();
		foreach ( $this->terms as $term ) {
			if ( $term->taxonomy !== $taxonomy ) {
				continue;
			}
			if ( isset( $args['parent'] ) && (int) $args['parent'] !== $term->parent ) {
				continue;
			}
			if ( isset( $args['include'] ) && ! in_array( $term->term_id, (array) $args['include'], true ) ) {
				continue;
			}
			if ( isset( $args['exclude'] ) && in_array( $term->term_id, (array) $args['exclude'], true ) ) {
				continue;
			}
			if ( isset( $args['object_ids'] ) ) {
				$related = $this->relationships[ (int) $args['object_ids'] ] ?? array();
				if ( ! in_array( $term->term_id, $related, true ) ) {
					continue;
				}
			}
			if ( ! empty( $args['search'] ) && false === strpos( $term->name, (string) $args['search'] ) ) {
				continue;
			}
			$terms[] = $term;
		}
		if ( 'count' === ( $args['fields'] ?? 'all' ) ) {
			return count( $terms );
		}
		$offset = (int) ( $args['offset'] ?? 0 );
		$number = (int) ( $args['number'] ?? 0 );
		if ( $number > 0 ) {
			$terms = array_slice( $terms, $offset, $number );
		}
		return $terms;
	}

	/**
	 * Insert one term with the core duplicate rules.
	 *
	 * @param string              $name Name.
	 * @param string              $taxonomy Taxonomy key.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,int>|\WP_Error
	 */
	public function insert_term( string $name, string $taxonomy, array $args = array() ) {
		$parent_id        = (int) ( $args['parent'] ?? 0 );
		$slug             = isset( $args['slug'] ) ? (string) $args['slug'] : sanitize_title( $name );
		$this->inserted[] = array(
			'name'     => $name,
			'taxonomy' => $taxonomy,
			'args'     => $args,
		);
		foreach ( $this->terms as $term ) {
			if ( $term->taxonomy !== $taxonomy || $term->name !== $name || $term->parent !== $parent_id ) {
				continue;
			}
			return new \WP_Error( 'term_exists', 'A term with the name provided already exists.' );
		}
		$slug               = $this->unique_slug( $taxonomy, $slug, 0 );
		$id                 = ++$this->next_term_id;
		$this->terms[ $id ] = new SiteTaxonomyTerm( $id, $taxonomy, $name, $slug, (string) ( $args['description'] ?? '' ), $parent_id );
		return array(
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		);
	}

	/**
	 * Update one stored term.
	 *
	 * @param int                 $id Term ID.
	 * @param string              $taxonomy Taxonomy key.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,int>|\WP_Error
	 */
	public function update_term( int $id, string $taxonomy, array $args = array() ) {
		$term = $this->term( $id, $taxonomy );
		if ( ! $term instanceof SiteTaxonomyTerm ) {
			return new \WP_Error( 'invalid_term', 'Empty Term.' );
		}
		$this->updated[] = array(
			'id'   => $id,
			'args' => $args,
		);
		if ( isset( $args['name'] ) ) {
			$term->name = (string) $args['name'];
		}
		if ( isset( $args['slug'] ) ) {
			$term->slug = $this->unique_slug( $taxonomy, (string) $args['slug'], $id );
		}
		if ( isset( $args['description'] ) ) {
			$term->description = (string) $args['description'];
		}
		if ( isset( $args['parent'] ) ) {
			$term->parent = (int) $args['parent'];
		}
		return array(
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		);
	}

	/**
	 * Return a slug that is unique inside one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug Candidate slug.
	 * @param int    $exclude_id Term that keeps its own slug.
	 * @return string
	 */
	public function unique_slug( string $taxonomy, string $slug, int $exclude_id ): string {
		$candidate = $slug;
		$suffix    = 1;
		while ( true ) {
			$taken = false;
			foreach ( $this->terms as $term ) {
				if ( $term->taxonomy === $taxonomy && $term->slug === $candidate && $term->term_id !== $exclude_id ) {
					$taken = true;
					break;
				}
			}
			if ( ! $taken ) {
				return $candidate;
			}
			++$suffix;
			$candidate = $slug . '-' . $suffix;
		}
	}

	/**
	 * Delete one stored term.
	 *
	 * @param int    $id Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return bool|\WP_Error
	 */
	public function delete_term( int $id, string $taxonomy ) {
		$term = $this->term( $id, $taxonomy );
		if ( ! $term instanceof SiteTaxonomyTerm ) {
			return false;
		}
		if ( $this->delete_fails ) {
			return false;
		}
		$this->deleted[] = $id;
		unset( $this->terms[ $id ] );
		return true;
	}

	/**
	 * Write one relationship set.
	 *
	 * @param int          $post_id Post ID.
	 * @param array<mixed> $term_ids Term identifiers.
	 * @param string       $taxonomy Taxonomy key.
	 * @param bool         $append Whether to append.
	 * @return array<int>|\WP_Error
	 */
	public function set_object_terms( int $post_id, array $term_ids, string $taxonomy, bool $append = false ) {
		$ids = array();
		foreach ( $term_ids as $term_id ) {
			if ( ! is_int( $term_id ) ) {
				return new \WP_Error( 'invalid_term', 'A term identifier must be an integer.' );
			}
			$term = $this->term( $term_id, $taxonomy );
			if ( ! $term instanceof SiteTaxonomyTerm ) {
				return new \WP_Error( 'invalid_term', 'Empty Term.' );
			}
			$ids[] = $term_id;
		}
		$this->assigned[] = array(
			'post'   => $post_id,
			'terms'  => $ids,
			'append' => $append,
		);
		if ( $this->noop_write ) {
			return $ids;
		}
		$current                         = $this->relationships[ $post_id ] ?? array();
		$this->relationships[ $post_id ] = $append ? array_values( array_unique( array_merge( $current, $ids ) ) ) : array_values( array_unique( $ids ) );
		return $this->relationships[ $post_id ];
	}

	/**
	 * Remove terms from one relationship set.
	 *
	 * @param int          $post_id Post ID.
	 * @param array<mixed> $term_ids Term identifiers.
	 * @param string       $taxonomy Taxonomy key.
	 * @return array<int>|\WP_Error
	 */
	public function remove_object_terms( int $post_id, array $term_ids, string $taxonomy ) {
		unset( $taxonomy );
		$ids                             = array_map( 'intval', $term_ids );
		$this->assigned[]                = array(
			'post'   => $post_id,
			'terms'  => $ids,
			'append' => false,
		);
		$current                         = $this->relationships[ $post_id ] ?? array();
		$this->relationships[ $post_id ] = array_values( array_diff( $current, $ids ) );
		return $this->relationships[ $post_id ];
	}

	/**
	 * Return one option.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function option( string $name ): mixed {
		return $this->options[ $name ] ?? false;
	}

	/**
	 * Return registered term meta keys.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<string,array<string,mixed>>
	 */
	public function registered_meta_keys( string $taxonomy ): array {
		return $this->registered_meta[ $taxonomy ] ?? array();
	}

	/**
	 * Read one term meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key Key.
	 * @param bool   $single Whether a single value is requested.
	 * @return mixed
	 */
	public function term_meta( int $term_id, string $key, bool $single ): mixed {
		$value = $this->meta[ $term_id ][ $key ] ?? null;
		if ( null === $value ) {
			return '';
		}
		return $single ? $value : array( $value );
	}

	/**
	 * Write one term meta value.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @return int|bool
	 */
	public function update_meta( int $term_id, string $key, mixed $value ): int|bool {
		if ( $this->meta_fails ) {
			return false;
		}
		if ( $this->meta_noop ) {
			$this->meta[ $term_id ][ $key ] = $value;
			return false;
		}
		$this->meta[ $term_id ][ $key ] = $value;
		$this->meta_writes[]            = array(
			'term'  => $term_id,
			'key'   => $key,
			'value' => $value,
		);
		return 1;
	}

	/**
	 * Find a term by name and optional parent.
	 *
	 * @param string   $name Name.
	 * @param string   $taxonomy Taxonomy key.
	 * @param int|null $parent_id Parent.
	 * @return array<string,int>|null
	 */
	public function term_exists( string $name, string $taxonomy, ?int $parent_id = null ): ?array {
		foreach ( $this->terms as $term ) {
			if ( $term->taxonomy !== $taxonomy || $term->name !== $name ) {
				continue;
			}
			if ( null !== $parent_id && $term->parent !== $parent_id ) {
				continue;
			}
			return array(
				'term_id'          => $term->term_id,
				'term_taxonomy_id' => $term->term_id,
			);
		}
		return null;
	}

	/**
	 * Find a term by one stored field.
	 *
	 * @param string $field Field.
	 * @param string $value Value.
	 * @param string $taxonomy Taxonomy key.
	 * @return SiteTaxonomyTerm|null
	 */
	public function term_by( string $field, string $value, string $taxonomy ): ?SiteTaxonomyTerm {
		foreach ( $this->terms as $term ) {
			if ( $term->taxonomy !== $taxonomy ) {
				continue;
			}
			if ( 'slug' === $field && $term->slug === $value ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Return the stored ancestors of one term.
	 *
	 * @param int    $id Term ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return array<int>
	 */
	public function ancestors( int $id, string $taxonomy ): array {
		$ancestors = array();
		$term      = $this->term( $id, $taxonomy );
		while ( $term instanceof SiteTaxonomyTerm && $term->parent > 0 ) {
			$ancestors[] = $term->parent;
			$term        = $this->term( $term->parent, $taxonomy );
		}
		return $ancestors;
	}

	/**
	 * Whether one object type carries the taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy Taxonomy key.
	 * @return bool
	 */
	public function supports_taxonomy( string $post_type, string $taxonomy ): bool {
		return in_array( $taxonomy, $this->supports[ $post_type ] ?? array(), true );
	}
}

/** Catalog containing exact definitions. */
final class SiteTaxonomyCatalog implements AbilityCatalog {
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

/** Human definition canary that must never run. */
final class SiteTaxonomyCanary implements AbilitySpec {
	/** Recorded human executions.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Wrap the real definition.
	 *
	 * @param AbilitySpec $definition Definition.
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
	 * Return a canary payload.
	 *
	 * @param array<string,mixed> $arguments Arguments.
	 * @return mixed
	 */
	public function execute( array $arguments ): mixed {
		++$this->calls;
		return array( 'canary' => true );
	}
}

/** Budget double recording the site identity. */
final class SiteTaxonomyBudget implements ExpensiveBudget {
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
 * Proves the machine taxonomy contract inside the five-file security boundary.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SiteTaxonomyOperationsTest extends TestCase {
	/** Fixed current blog. */
	private const BLOG = 1;

	/** Machine world.
	 *
	 * @var SiteTaxonomyWorld
	 */
	private SiteTaxonomyWorld $world;

	/** The nine machine names.
	 *
	 * @var array<string>
	 */
	private array $names = SiteTaxonomyOperations::NAMES;

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		$this->alias_core_classes();
		$this->world = new SiteTaxonomyWorld();
		$this->wordpress();
		$this->stubs();
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The machine name list is the reviewed pack list. */
	public function test_names_match_the_pack(): void {
		$reviewed = array();
		foreach ( ( new TaxonomyPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$reviewed[] = $definition->descriptor()->name;
		}
		sort( $reviewed );
		$names = $this->names;
		sort( $names );
		self::assertCount( 9, $this->names );
		self::assertSame( $reviewed, $names );
	}

	/** Unknown names and cross-blog identities are refused before any work. */
	public function test_unknown_name_and_cross_blog_are_refused(): void {
		$operations = new SiteTaxonomyOperations();
		$unknown    = $operations->execute( 'xnetvn/nope', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $unknown );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unknown->get_error_code() );
		self::assertSame( array(), $this->world->resolved );

		$cross = $operations->execute( 'xnetvn/list-taxonomies', array(), $this->context( $this->names, RiskTier::Destructive, self::BLOG + 1 ) );
		self::assertInstanceOf( \WP_Error::class, $cross );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $cross->get_error_code() );
		self::assertSame( array(), $this->world->resolved );
	}

	/** A custom capability map never grants machine taxonomy access. */
	public function test_custom_capability_map_is_refused(): void {
		$this->world->add_taxonomy(
			new SiteTaxonomyTax(
				'book_genre',
				true,
				array( 'post' ),
				'book-genres',
				array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				)
			)
		);
		$this->world->add_term( 40, 'book_genre', 'History', 'history' );
		$operations = new SiteTaxonomyOperations();
		$context    = $this->context();
		$cases      = array(
			'xnetvn/create-term'       => array(
				'taxonomy' => 'book_genre',
				'name'     => 'Biography',
			),
			'xnetvn/update-term'       => array(
				'taxonomy' => 'book_genre',
				'id'       => 40,
				'name'     => 'Renamed',
			),
			'xnetvn/assign-post-terms' => array(
				'taxonomy' => 'book_genre',
				'post_id'  => 11,
				'term_ids' => array( 40 ),
				'mode'     => 'append',
			),
			'xnetvn/delete-term'       => array(
				'taxonomy' => 'book_genre',
				'id'       => 40,
			),
		);
		foreach ( $cases as $name => $args ) {
			$result = $operations->execute( $name, $args, $context );
			self::assertInstanceOf( \WP_Error::class, $result, $name );
			self::assertSame( 'xnetvn_agent_hub_wp_taxonomy_unavailable', $result->get_error_code(), $name );
		}
		self::assertSame( array(), $this->world->inserted );
		self::assertSame( array(), $this->world->updated );
		self::assertSame( array(), $this->world->deleted );
		self::assertSame( array(), $this->world->assigned );
	}

	/** Core category and post_tag capability objects are accepted from the taxonomy. */
	public function test_core_taxonomy_capability_objects_are_accepted(): void {
		$operations = new SiteTaxonomyOperations();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$created = $operations->execute(
				'xnetvn/create-term',
				array(
					'taxonomy' => $taxonomy,
					'name'     => 'Cap ' . $taxonomy,
				),
				$this->context()
			);
			self::assertIsArray( $created, $taxonomy );
			self::assertSame( $taxonomy, $created['taxonomy'] );
		}
		self::assertCount( 2, $this->world->inserted );
	}

	/** Every registered taxonomy is resolved by its own key, never by an empty slug. */
	public function test_list_taxonomies_resolves_each_registered_taxonomy(): void {
		$result = ( new SiteTaxonomyOperations() )->execute( 'xnetvn/list-taxonomies', array(), $this->context() );
		self::assertIsArray( $result );
		self::assertSame( array( 'category', 'post_tag' ), array_column( $result['items'], 'slug' ) );
		$category = $result['items'][0];
		self::assertTrue( $category['hierarchical'] );
		self::assertSame( 'categories', $category['rest_base'] );
		self::assertSame( array( 'post' ), $category['types'] );
		self::assertTrue( $category['can_assign'] );
		self::assertTrue( $category['can_edit'] );
		self::assertTrue( $category['can_delete'] );
		self::assertSame( 5, $category['default_term_id'] );
		self::assertNull( $result['items'][1]['default_term_id'] );
		self::assertNotContains( '', $this->world->resolved );
		self::assertContains( 'category', $this->world->resolved );
		self::assertContains( 'post_tag', $this->world->resolved );
	}

	/** Listing filters by a managed post type and refuses a foreign one. */
	public function test_list_taxonomies_filters_by_managed_post_type(): void {
		$operations = new SiteTaxonomyOperations();
		$filtered   = $operations->execute( 'xnetvn/list-taxonomies', array( 'post_type' => 'post' ), $this->context() );
		self::assertIsArray( $filtered );
		self::assertCount( 2, $filtered['items'] );

		$missing = $operations->execute( 'xnetvn/list-taxonomies', array( 'post_type' => 'book' ), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $missing->get_error_code() );
	}

	/** Hints follow the explicit key scope, never a WordPress capability. */
	public function test_list_taxonomies_hints_follow_the_key_scope(): void {
		$context = $this->context( array( 'xnetvn/list-terms' ) );
		$result  = ( new SiteTaxonomyOperations() )->execute( 'xnetvn/list-taxonomies', array(), $context );
		self::assertIsArray( $result );
		foreach ( $result['items'] as $item ) {
			self::assertFalse( $item['can_assign'] );
			self::assertFalse( $item['can_edit'] );
			self::assertFalse( $item['can_delete'] );
		}
	}

	/** Listing terms keeps exact totals, bounded pages and omitted links. */
	public function test_list_terms_returns_exact_totals(): void {
		$this->world->add_term( 8, 'post_tag', 'Tag C', 'tag-c' );
		$this->world->link_error = true;
		$operations              = new SiteTaxonomyOperations( null, null, null, null, null );
		$page                    = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'page'     => 1,
				'per_page' => 2,
			),
			$this->context()
		);
		self::assertIsArray( $page );
		self::assertSame( 3, $page['total'] );
		self::assertSame( 2, $page['total_pages'] );
		self::assertSame( 1, $page['page'] );
		self::assertCount( 2, $page['items'] );
		self::assertArrayNotHasKey( 'link', $page['items'][0] );

		$second = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'page'     => 2,
				'per_page' => 2,
			),
			$this->context()
		);
		self::assertIsArray( $second );
		self::assertCount( 1, $second['items'] );

		$overflow = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'page'     => 3,
				'per_page' => 2,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $overflow );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $overflow->get_error_code() );

		$unbounded = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'page'     => 101,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $unbounded );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $unbounded->get_error_code() );
	}

	/** Terms refuse a parent filter on a flat taxonomy and an empty include order. */
	public function test_list_terms_rejects_invalid_filters(): void {
		$operations = new SiteTaxonomyOperations();
		$parent     = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'parent'   => 0,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $parent );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $parent->get_error_code() );

		$orderby = $operations->execute(
			'xnetvn/list-terms',
			array(
				'taxonomy' => 'post_tag',
				'orderby'  => 'include',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $orderby );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $orderby->get_error_code() );
	}

	/** One term read requires a taxonomy match and narrows to requested fields. */
	public function test_get_term_rejects_wrong_taxonomy_and_projects_fields(): void {
		$this->world->rest_data = array(
			'id'          => 6,
			'taxonomy'    => 'post_tag',
			'name'        => 'Tag A',
			'slug'        => 'tag-a',
			'description' => 'Description',
			'parent'      => 0,
			'count'       => 1,
			'link'        => 'https://example.test/tag-a',
		);
		$operations             = new SiteTaxonomyOperations();
		$wrong                  = $operations->execute(
			'xnetvn/get-term',
			array(
				'taxonomy' => 'category',
				'id'       => 6,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $wrong );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $wrong->get_error_code() );

		$term = $operations->execute(
			'xnetvn/get-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
				'fields'   => array( 'name', 'slug' ),
			),
			$this->context()
		);
		self::assertIsArray( $term );
		self::assertSame( array( 'id', 'taxonomy', 'name', 'slug' ), array_keys( $term ) );
		self::assertSame( 6, $term['id'] );
		self::assertSame( 'Tag A', $term['name'] );
	}

	/** Created terms are filtered, projected and meta checked. */
	public function test_create_term_filters_html_and_writes_meta(): void {
		$operations = new SiteTaxonomyOperations();
		$result     = $operations->execute(
			'xnetvn/create-term',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => '<b>New</b><script>alert(1)</script>',
				'description' => '<p>Safe</p><script>alert(1)</script>',
				'slug'        => 'new-tag',
				'meta'        => array( 'site_note' => '<b>note</b><script>alert(1)</script>' ),
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertGreaterThan( 0, $result['id'] );
		self::assertSame( 'post_tag', $result['taxonomy'] );
		self::assertFalse( $result['slug_adjusted'] );
		self::assertCount( 1, $this->world->inserted );
		$inserted = $this->world->inserted[0];
		self::assertStringNotContainsString( '<', $inserted['name'] );
		self::assertStringContainsString( '<p>Safe</p>', (string) $inserted['args']['description'] );
		self::assertStringNotContainsString( '<script', (string) $inserted['args']['description'] );
		self::assertSame( 'new-tag', $inserted['args']['slug'] );
		self::assertCount( 1, $this->world->meta_writes );
		self::assertSame( 'site_note', $this->world->meta_writes[0]['key'] );
		self::assertStringNotContainsString( '<script', (string) $this->world->meta_writes[0]['value'] );
	}

	/** A colliding slug is reported as adjusted after the write. */
	public function test_create_term_reports_an_adjusted_slug(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Slug collision',
				'slug'     => 'tag-a',
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertSame( 'tag-a-2', $result['slug'] );
		self::assertTrue( $result['slug_adjusted'] );
	}

	/** A meta failure after the term write is a bounded created receipt. */
	public function test_create_term_meta_failure_is_a_partial_receipt(): void {
		$this->world->meta_fails = true;
		$result                  = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Meta failure',
				'meta'     => array( 'site_note' => 'value' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \XNetVN\AgentHubWP\Abilities\PartialFailure::class, $result );
		self::assertTrue( $result->created );
		self::assertGreaterThan( 0, $result->object_id );
		self::assertSame( array( 'created_id' => $result->object_id ), $result->data );
	}

	/** A filtered name that becomes empty never creates a term. */
	public function test_create_term_requires_a_visible_name(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => '<script></script>',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		self::assertSame( array(), $this->world->inserted );
	}

	/** Unknown term meta keys are refused before the write. */
	public function test_create_term_rejects_unregistered_meta(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/create-term',
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Meta guard',
				'meta'     => array( '_private' => 'value' ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		self::assertSame( array(), $this->world->inserted );
	}

	/** Term updates filter HTML and keep hierarchy rules. */
	public function test_update_term_filters_html_and_rejects_parent_loops(): void {
		$this->world->add_term( 9, 'category', 'Child', 'child', 5 );
		$operations = new SiteTaxonomyOperations();
		$updated    = $operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy'    => 'category',
				'id'          => 5,
				'description' => '<p>Kept</p><script>alert(1)</script>',
			),
			$this->context()
		);
		self::assertIsArray( $updated );
		self::assertCount( 1, $this->world->updated );
		$description = (string) $this->world->updated[0]['args']['description'];
		self::assertStringContainsString( '<p>Kept</p>', $description );
		self::assertStringNotContainsString( '<script', $description );

		$self = $operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'category',
				'id'       => 5,
				'parent'   => 5,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $self );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $self->get_error_code() );

		$descendant = $operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'category',
				'id'       => 5,
				'parent'   => 9,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $descendant );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $descendant->get_error_code() );

		$flat = $operations->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
				'parent'   => 0,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $flat );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $flat->get_error_code() );
		self::assertCount( 1, $this->world->updated );
	}

	/** An update without any reviewed change is refused. */
	public function test_update_term_requires_a_change(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'category',
				'id'       => 5,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		self::assertSame( array(), $this->world->updated );
	}

	/** Strings, floats and zero never reach the relationship write. */
	public function test_assignment_is_integer_only(): void {
		Functions\expect( 'wp_set_object_terms' )->never();
		Functions\expect( 'wp_remove_object_terms' )->never();
		Functions\expect( 'wp_insert_term' )->never();
		$operations = new SiteTaxonomyOperations();
		foreach ( array(
			'reason' => 'new-tag',
			'injected-term',
			'123',
			0,
			-1,
			1.5,
		) as $invalid ) {
			$result = $operations->execute(
				'xnetvn/assign-post-terms',
				array(
					'taxonomy' => 'post_tag',
					'post_id'  => 11,
					'term_ids' => array( $invalid ),
					'mode'     => 'append',
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
			self::assertStringNotContainsString( (string) $invalid, $result->message );
			self::assertSame( array(), $result->data );
		}
		$duplicate = $operations->execute(
			'xnetvn/assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_id'  => 11,
				'term_ids' => array( 6, 6 ),
				'mode'     => 'append',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $duplicate );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $duplicate->get_error_code() );
		self::assertSame( array(), $this->world->assigned );
	}

	/** A wrong-taxonomy identifier is never written. */
	public function test_assignment_requires_terms_in_the_requested_taxonomy(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_id'  => 11,
				'term_ids' => array( 5 ),
				'mode'     => 'append',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $result->get_error_code() );
		self::assertSame( array(), $this->world->assigned );
	}

	/** The target post requires a managed editable type and taxonomy support. */
	public function test_assignment_requires_an_editable_target_post(): void {
		$this->world->posts[12] = new SiteTaxonomyPost( 'post', 'trash' );
		$this->world->posts[13] = new SiteTaxonomyPost( 'page', 'publish' );
		$this->world->add_post_type( new SiteTaxonomyPostType( 'page' ), array( 'category' ) );
		$this->world->add_post_type( new SiteTaxonomyPostType( 'book', 'book' ), array( 'post_tag' ) );
		$this->world->posts[14] = new SiteTaxonomyPost( 'book', 'publish' );
		$operations             = new SiteTaxonomyOperations();
		$cases                  = array(
			array( 99, 'xnetvn_agent_hub_wp_not_found' ),
			array( 12, 'xnetvn_agent_hub_wp_invalid_state' ),
			array( 14, 'xnetvn_agent_hub_wp_post_type_unavailable' ),
			array( 13, 'xnetvn_agent_hub_wp_unsupported_operation' ),
		);
		foreach ( $cases as $case ) {
			$result = $operations->execute(
				'xnetvn/assign-post-terms',
				array(
					'taxonomy' => 'post_tag',
					'post_id'  => $case[0],
					'term_ids' => array( 6 ),
					'mode'     => 'append',
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $result, (string) $case[0] );
			self::assertSame( $case[1], $result->get_error_code(), (string) $case[0] );
		}
		self::assertSame( array(), $this->world->assigned );
	}

	/** Verified assignment replaces, appends and fills the default category. */
	public function test_assign_verified_terms_replaces_and_appends(): void {
		$replace = SiteTaxonomyOperations::assign_verified_terms( 11, 'post_tag', array( 7 ) );
		self::assertIsArray( $replace );
		self::assertSame( 'replace', $replace['mode'] );
		self::assertSame( array( 7 ), $replace['term_ids'] );
		self::assertSame( array( 7 ), $replace['added'] );

		$append = SiteTaxonomyOperations::assign_verified_terms( 11, 'post_tag', array( 6 ), true );
		self::assertIsArray( $append );
		self::assertSame( 'append', $append['mode'] );
		self::assertSame( array( 6, 7 ), $append['term_ids'] );

		$default = SiteTaxonomyOperations::assign_verified_terms( 11, 'category', array() );
		self::assertIsArray( $default );
		self::assertTrue( $default['default_term_applied'] );
		self::assertSame( array( 5 ), $default['term_ids'] );

		$empty = SiteTaxonomyOperations::assign_verified_terms( 11, 'post_tag', array(), true );
		self::assertInstanceOf( \WP_Error::class, $empty );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $empty->get_error_code() );
	}

	/** An unchanged meta value is a success, not a partial failure. */
	public function test_unchanged_meta_write_is_a_success(): void {
		$this->world->meta_noop = true;
		$result                 = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/update-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
				'meta'     => array( 'site_note' => 'Existing' ),
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertSame( 6, $result['id'] );
	}

	/** The single assignment branch keeps a trusted receipt through the output boundary. */
	public function test_assignment_branch_returns_a_trusted_receipt(): void {
		$branch = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_id'  => 11,
				'term_ids' => array( 7 ),
				'mode'     => 'append',
			),
			$this->context()
		);
		self::assertInstanceOf( MutationResult::class, $branch );
		self::assertSame( 11, $branch->object_id );
		self::assertFalse( $branch->created );
		self::assertSame( array( 7 ), $branch->data['term_ids'] );

		$dispatcher = $this->dispatcher( $this->taxonomy_definitions() );
		$dispatcher->freeze();
		$result = $dispatcher->execute(
			'xnetvn/assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_id'  => 11,
				'term_ids' => array( 6 ),
				'mode'     => 'append',
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertSame( array( 6, 7 ), $result['term_ids'] );
	}

	/** A relationship read-back mismatch returns a bounded partial receipt. */
	public function test_assignment_read_back_mismatch_is_a_partial_failure(): void {
		$this->world->noop_write = true;
		$result                  = SiteTaxonomyOperations::assign_verified_terms( 11, 'post_tag', array( 7 ) );
		self::assertInstanceOf( \XNetVN\AgentHubWP\Abilities\PartialFailure::class, $result );
		self::assertSame( 11, $result->object_id );
		self::assertFalse( $result->created );
		self::assertSame( array( 'affected_id' => 11 ), $result->data );
		self::assertStringNotContainsString( 'Tag B', $result->message );
	}

	/** Defaults are refused before a dry run and before any deletion. */
	public function test_delete_term_refuses_defaults_before_dry_run(): void {
		Functions\expect( 'wp_delete_term' )->never();
		$operations = new SiteTaxonomyOperations();
		foreach ( array( true, false ) as $dry_run ) {
			$result = $operations->execute(
				'xnetvn/delete-term',
				array(
					'taxonomy' => 'category',
					'id'       => 5,
					'dry_run'  => $dry_run,
				),
				$this->context()
			);
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $result->get_error_code() );
		}

		$this->world->add_taxonomy( new SiteTaxonomyTax( 'site_topic', true, array( 'post' ), 'site-topics', null, array( 'name' => 'Default' ) ) );
		$this->world->add_term( 13, 'site_topic', 'Default', 'default' );
		$this->world->options['default_term_site_topic'] = 13;
		$default = $operations->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'site_topic',
				'id'       => 13,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $default );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $default->get_error_code() );
		self::assertSame( array(), $this->world->deleted );
	}

	/** A dry run reports the impact without writing anything. */
	public function test_delete_term_dry_run_reports_impact(): void {
		$this->world->add_term( 9, 'category', 'Child', 'child', 5 );
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'category',
				'id'       => 9,
				'dry_run'  => true,
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertFalse( $result['deleted'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 5, $result['impact']['new_parent_for_children'] );
		self::assertTrue( $result['impact']['will_reassign_to_default'] );
		self::assertSame( array(), $this->world->deleted );

		$flat = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
				'dry_run'  => true,
			),
			$this->context()
		);
		self::assertIsArray( $flat );
		self::assertFalse( $flat['impact']['will_reassign_to_default'] );

		$deleted = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
			),
			$this->context()
		);
		self::assertIsArray( $deleted );
		self::assertTrue( $deleted['deleted'] );
		self::assertSame( array( 6 ), $this->world->deleted );
	}

	/** A false core deletion is never reported as success. */
	public function test_delete_term_maps_a_core_failure(): void {
		$this->world->delete_fails = true;
		$result                    = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_internal_error', $result->get_error_code() );
	}

	/** Bulk creation isolates duplicates, invalid items and successful terms. */
	public function test_bulk_create_isolates_each_item(): void {
		$result = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/bulk-create-terms',
			array(
				'taxonomy' => 'post_tag',
				'items'    => array(
					array( 'name' => 'Bulk new' ),
					array( 'name' => 'Tag A' ),
					array( 'name' => '' ),
				),
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertCount( 1, $result['created'] );
		self::assertSame( 'Bulk new', $result['created'][0]['name'] );
		self::assertCount( 1, $result['existing'] );
		self::assertSame( 6, $result['existing'][0]['id'] );
		self::assertCount( 1, $result['failed'] );
		self::assertSame( 2, $result['failed'][0]['index'] );
		self::assertSame( 'invalid_params', $result['failed'][0]['code'] );

		$oversized = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/bulk-create-terms',
			array(
				'taxonomy' => 'post_tag',
				'items'    => array_fill( 0, 51, array( 'name' => 'Many' ) ),
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $oversized );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $oversized->get_error_code() );
	}

	/** Bulk assignment validates terms once and isolates each post. */
	public function test_bulk_assign_isolates_posts(): void {
		$this->world->posts[12] = new SiteTaxonomyPost( 'post', 'trash' );
		$result                 = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/bulk-assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( 11, 12 ),
				'term_ids' => array( 6 ),
				'mode'     => 'append',
			),
			$this->context()
		);
		self::assertIsArray( $result );
		self::assertSame( array( 11 ), $result['updated'] );
		self::assertSame( 12, $result['failed'][0]['id'] );
		self::assertSame( 'invalid_state', $result['failed'][0]['code'] );
		self::assertSame( array( true, false ), $this->world->deferred );

		$replace = ( new SiteTaxonomyOperations() )->execute(
			'xnetvn/bulk-assign-post-terms',
			array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( 11 ),
				'term_ids' => array( 6 ),
				'mode'     => 'replace',
			),
			$this->context()
		);
		self::assertInstanceOf( \WP_Error::class, $replace );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $replace->get_error_code() );
	}

	/** The machine HTML boundary restores every hook it installs. */
	public function test_html_boundary_restores_hook_state(): void {
		( new SiteTaxonomyOperations() )->execute(
			'xnetvn/create-term',
			array(
				'taxonomy'    => 'post_tag',
				'name'        => 'Hook check',
				'description' => '<p>Safe</p>',
			),
			$this->context()
		);
		self::assertNotSame( array(), $this->world->hooks_added );
		$added   = $this->world->hooks_added;
		$removed = $this->world->hooks_removed;
		sort( $added );
		sort( $removed );
		self::assertSame( $added, $removed );
	}

	/** The dispatcher resolves the nine built-ins only through the bound catalog. */
	public function test_dispatcher_resolves_the_built_ins(): void {
		$definitions                           = $this->taxonomy_definitions();
		$canary                                = new SiteTaxonomyCanary( $definitions['xnetvn/list-taxonomies'] );
		$definitions['xnetvn/list-taxonomies'] = $canary;
		$dispatcher                            = $this->dispatcher( $definitions );
		$context                               = $this->context();
		foreach ( $this->names as $name ) {
			self::assertTrue( $dispatcher->has_handler( $name ), $name );
		}
		self::assertFalse( $dispatcher->has_handler( 'xnetvn/nope' ) );
		$dispatcher->freeze();
		$result = $dispatcher->execute( 'xnetvn/list-taxonomies', array(), $context );
		self::assertIsArray( $result );
		self::assertSame( array( 'category', 'post_tag' ), array_column( $result['items'], 'slug' ) );
		self::assertSame( 0, $canary->calls );
	}

	/** An absent catalog definition leaves the exact name unavailable. */
	public function test_dispatcher_requires_the_bound_definition(): void {
		$definitions = $this->taxonomy_definitions();
		unset( $definitions['xnetvn/delete-term'] );
		$dispatcher = $this->dispatcher( $definitions );
		self::assertFalse( $dispatcher->has_handler( 'xnetvn/delete-term' ) );
		$result = $dispatcher->execute( 'xnetvn/delete-term', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );

		$unbound = new SiteDispatcher( new SitePolicy( new MemorySettings() ), new OutputPipeline( new MemorySettings() ) );
		self::assertFalse( $unbound->has_handler( 'xnetvn/list-terms' ) );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $unbound->execute( 'xnetvn/list-terms', array(), $this->context() )->get_error_code() );
	}

	/** Denied, revoked and cross-blog identities never reach the handler. */
	public function test_dispatcher_denials_precede_the_handler(): void {
		$dispatcher = $this->dispatcher( $this->taxonomy_definitions() );
		$cases      = array(
			$this->context( array( 'xnetvn/list-posts' ) ),
			$this->context( $this->names, RiskTier::Destructive, self::BLOG, true ),
			$this->context( $this->names, RiskTier::Destructive, self::BLOG + 1 ),
		);
		foreach ( $cases as $context ) {
			$result = $dispatcher->execute( 'xnetvn/list-taxonomies', array(), $context );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
		}
	}

	/** The three costly names use exactly the site identity. */
	public function test_dispatcher_uses_the_site_budget_identity(): void {
		$budget     = new SiteTaxonomyBudget();
		$dispatcher = $this->dispatcher( $this->taxonomy_definitions(), new MemorySettings(), $budget );
		$context    = $this->context( $this->names, RiskTier::Destructive );
		$calls      = array(
			'xnetvn/bulk-create-terms'      => array(
				'taxonomy' => 'post_tag',
				'items'    => array( array( 'name' => 'Budget term' ) ),
			),
			'xnetvn/bulk-assign-post-terms' => array(
				'taxonomy' => 'post_tag',
				'post_ids' => array( 11 ),
				'term_ids' => array( 6 ),
				'mode'     => 'append',
			),
			'xnetvn/delete-term'            => array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
			),
		);
		$results    = array();
		foreach ( $calls as $name => $args ) {
			$results[ $name ] = $dispatcher->execute( $name, $args, $context );
		}
		self::assertSame(
			array( 'site:1:abcdefghijkl', 'site:1:abcdefghijkl', 'site:1:abcdefghijkl' ),
			$budget->identities
		);
		self::assertIsArray( $results['xnetvn/bulk-create-terms'] );
		self::assertCount( 1, $results['xnetvn/bulk-create-terms']['created'] );
		self::assertIsArray( $results['xnetvn/bulk-assign-post-terms'] );
		self::assertSame( array( 11 ), $results['xnetvn/bulk-assign-post-terms']['updated'] );
		self::assertIsArray( $results['xnetvn/delete-term'] );
		self::assertTrue( $results['xnetvn/delete-term']['deleted'] );
	}

	/** A write ceiling refuses delete-term before the handler and the budget. */
	public function test_write_ceiling_refuses_delete_term_before_the_handler(): void {
		$budget     = new SiteTaxonomyBudget();
		$dispatcher = $this->dispatcher( $this->taxonomy_definitions(), new MemorySettings(), $budget );
		$context    = $this->context( $this->names, RiskTier::Write );
		$result     = $dispatcher->execute(
			'xnetvn/delete-term',
			array(
				'taxonomy' => 'post_tag',
				'id'       => 6,
			),
			$context
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_tool_unavailable', $result->get_error_code() );
		self::assertSame( array(), $budget->identities );
		self::assertSame( array(), $this->world->resolved );
		self::assertSame( array(), $this->world->deleted );
	}

	/** Invalid dispatcher arguments never reach the machine handler. */
	public function test_dispatcher_refuses_invalid_arguments(): void {
		$dispatcher = $this->dispatcher( $this->taxonomy_definitions() );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( false );
		$result = $dispatcher->execute( 'xnetvn/list-taxonomies', array(), $this->context() );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		self::assertSame( array(), $this->world->resolved );
	}

	/** Every fixed built-in name is refused as an explicit registration. */
	public function test_register_rejects_every_taxonomy_name(): void {
		$dispatcher = $this->dispatcher( $this->taxonomy_definitions() );
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

	/** Register the isolated core-class doubles. */
	private function alias_core_classes(): void {
		if ( ! class_exists( 'WP_Taxonomy', false ) ) {
			class_alias( SiteTaxonomyTax::class, 'WP_Taxonomy' );
		}
		if ( ! class_exists( 'WP_Term', false ) ) {
			class_alias( SiteTaxonomyTerm::class, 'WP_Term' );
		}
		if ( ! class_exists( 'WP_Post_Type', false ) ) {
			class_alias( SiteTaxonomyPostType::class, 'WP_Post_Type' );
		}
		if ( ! class_exists( 'WP_REST_Term_Meta_Fields', false ) ) {
			class_alias( SiteTaxonomyMetaFields::class, 'WP_REST_Term_Meta_Fields' );
		}
		if ( ! class_exists( 'WP_REST_Request', false ) ) {
			class_alias( SiteTaxonomyRequest::class, 'WP_REST_Request' );
		}
		if ( ! class_exists( 'WP_REST_Response', false ) ) {
			class_alias( SiteTaxonomyResponse::class, 'WP_REST_Response' );
		}
	}

	/** Populate the default vocabulary. */
	private function wordpress(): void {
		$this->world->add_taxonomy(
			new SiteTaxonomyTax(
				'category',
				true,
				array( 'post' ),
				'categories',
				array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'edit_categories',
					'delete_terms' => 'delete_categories',
					'assign_terms' => 'assign_categories',
				)
			)
		);
		$this->world->add_taxonomy(
			new SiteTaxonomyTax(
				'post_tag',
				false,
				array( 'post' ),
				'tags',
				array(
					'manage_terms' => 'manage_post_tags',
					'edit_terms'   => 'edit_post_tags',
					'delete_terms' => 'delete_post_tags',
					'assign_terms' => 'assign_post_tags',
				)
			)
		);
		$this->world->add_post_type( new SiteTaxonomyPostType( 'post' ), array( 'category', 'post_tag' ) );
		$this->world->posts[11] = new SiteTaxonomyPost( 'post', 'publish' );
		$this->world->add_term( 5, 'category', 'News', 'news' );
		$this->world->add_term( 6, 'post_tag', 'Tag A', 'tag-a' );
		$this->world->add_term( 7, 'post_tag', 'Tag B', 'tag-b' );
		$this->world->options['default_category'] = 5;
		$this->world->registered_meta['post_tag'] = array(
			'site_note' => array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			),
			'_private'  => array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'default'      => '',
			),
		);
		$this->world->meta[6]                     = array( 'site_note' => 'Existing' );
	}

	/** Install the reviewed WordPress substitutes. */
	private function stubs(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( self::BLOG );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
		Functions\when( 'apply_filters' )->alias( static fn( string $tag, mixed $value ): mixed => $value );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'rest_sanitize_value_from_schema' )->alias( static fn( mixed $value, array $schema ): mixed => $value );
		Functions\when( 'get_taxonomies' )->justReturn( array( 'category', 'post_tag' ) );
		Functions\when( 'get_taxonomy' )->alias( fn( string $slug ) => $this->world->taxonomy( $slug ) );
		Functions\when( 'rest_get_route_for_taxonomy_items' )->alias( fn( string $slug ): string => $this->world->term_route( $slug ) );
		Functions\when( 'get_post_type_object' )->alias( fn( string $slug ) => $this->world->post_type( $slug ) );
		Functions\when( 'rest_get_route_for_post_type_items' )->alias( static fn( string $slug ): string => '' === $slug ? '' : '/wp/v2/' . $slug );
		Functions\when( 'get_post' )->alias( fn( int $id ) => $this->world->posts[ $id ] ?? null );
		Functions\when( 'is_object_in_taxonomy' )->alias( fn( string $post_type, string $taxonomy ): bool => $this->world->supports_taxonomy( $post_type, $taxonomy ) );
		Functions\when( 'get_term' )->alias( fn( mixed $id, string $taxonomy ) => $this->world->term( $id, $taxonomy ) );
		Functions\when( 'get_terms' )->alias( fn( array $args ) => $this->world->query_terms( $args ) );
		Functions\when( 'get_term_link' )->alias(
			function ( mixed $term ): mixed {
				if ( $this->world->link_error ) {
					return new \WP_Error( 'invalid_term', 'Empty Term.' );
				}
				$slug = $term instanceof \WP_Term ? $term->slug : '';
				return 'https://example.test/' . $slug;
			}
		);
		Functions\when( 'get_option' )->alias( fn( string $name ) => $this->world->option( $name ) );
		Functions\when( 'get_registered_meta_keys' )->alias(
			function ( string $type, string $taxonomy = '' ): array {
				if ( 'term' !== $type ) {
					return array();
				}
				return $this->world->registered_meta_keys( $taxonomy );
			}
		);
		Functions\when( 'get_term_meta' )->alias( fn( int $term_id, string $key, bool $single = false ) => $this->world->term_meta( $term_id, $key, $single ) );
		Functions\when( 'update_term_meta' )->alias( fn( int $term_id, string $key, mixed $value ) => $this->world->update_meta( $term_id, $key, $value ) );
		Functions\when( 'wp_insert_term' )->alias( fn( string $name, string $taxonomy, array $args = array() ) => $this->world->insert_term( $name, $taxonomy, $args ) );
		Functions\when( 'wp_update_term' )->alias( fn( int $id, string $taxonomy, array $args = array() ) => $this->world->update_term( $id, $taxonomy, $args ) );
		Functions\when( 'wp_delete_term' )->alias( fn( int $id, string $taxonomy ) => $this->world->delete_term( $id, $taxonomy ) );
		Functions\when( 'wp_get_object_terms' )->alias(
			fn( mixed $post_id, string $taxonomy ): array => array_values( $this->world->relationships[ (int) $post_id ] ?? array() )
		);
		Functions\when( 'wp_set_object_terms' )->alias(
			fn( mixed $post_id, array $terms, string $taxonomy, bool $append = false ) => $this->world->set_object_terms( (int) $post_id, $terms, $taxonomy, $append )
		);
		Functions\when( 'wp_remove_object_terms' )->alias(
			fn( mixed $post_id, array $terms, string $taxonomy ) => $this->world->remove_object_terms( (int) $post_id, $terms, $taxonomy )
		);
		Functions\when( 'term_exists' )->alias( fn( string $name, string $taxonomy = '', mixed $parent_id = null ) => $this->world->term_exists( $name, $taxonomy, is_int( $parent_id ) ? $parent_id : null ) );
		Functions\when( 'get_term_by' )->alias( fn( string $field, string $value, string $taxonomy = '' ) => $this->world->term_by( $field, $value, $taxonomy ) );
		Functions\when( 'get_ancestors' )->alias( fn( int $id, string $taxonomy = '', string $context = 'taxonomy' ): array => $this->world->ancestors( $id, $taxonomy ) );
		Functions\when( 'wp_defer_term_counting' )->alias(
			function ( ?bool $defer = null ): bool {
				if ( null === $defer ) {
					return $this->world->defer_state;
				}
				$this->world->defer_state = $defer;
				$this->world->deferred[]  = $defer;
				return $defer;
			}
		);
		Functions\when( 'rest_do_request' )->alias(
			function ( mixed $request ): mixed {
				unset( $request );
				return new SiteTaxonomyResponse( $this->world->rest_data );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			static fn( string $value ): string => strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', $value ), '-' ) )
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => $value );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => (string) preg_replace( '/<[^>]*>/', '', $value ) );
		Functions\when( 'wp_kses_post' )->alias(
			static function ( string $value ): string {
				$stripped = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value );
				$stripped = preg_replace( '#\son\w+\s*=\s*(["\']).*?\1#is', '', (string) $stripped );
				return (string) $stripped;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( string $hook, mixed $callback = null, int $priority = 10, int $accepted_args = 1 ): bool {
				$this->world->hooks_added[] = $hook;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( string $hook, mixed $callback = null, int $priority = 10 ): bool {
				$this->world->hooks_removed[] = $hook;
				return true;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
	}

	/**
	 * Build a machine identity.
	 *
	 * @param array<string> $allowed Allowed names.
	 * @param RiskTier      $tier Scope ceiling.
	 * @param int           $blog_id Credential blog.
	 * @param bool          $revoked Whether revoked.
	 * @return SiteAuthContext
	 */
	private function context( array $allowed = SiteTaxonomyOperations::NAMES, RiskTier $tier = RiskTier::Destructive, int $blog_id = self::BLOG, bool $revoked = false ): SiteAuthContext {
		$scope      = new SiteScope( $tier, array( 'taxonomy' ), $allowed, array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
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
	 * Return the real taxonomy pack definitions by name.
	 *
	 * @return array<string,AbilitySpec>
	 */
	private function taxonomy_definitions(): array {
		$definitions = array();
		foreach ( ( new TaxonomyPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$definitions[ $definition->descriptor()->name ] = $definition;
		}
		return $definitions;
	}

	/**
	 * Build a dispatcher with a bound catalog.
	 *
	 * @param array<string,AbilitySpec> $definitions Definitions.
	 * @param MemorySettings|null       $settings Optional settings.
	 * @param ExpensiveBudget|null      $budget Optional budget.
	 * @return SiteDispatcher
	 */
	private function dispatcher( array $definitions, ?MemorySettings $settings = null, ?ExpensiveBudget $budget = null ): SiteDispatcher {
		$settings   = $settings ?? new MemorySettings();
		$catalog    = new SiteTaxonomyCatalog( $definitions );
		$dispatcher = new SiteDispatcher( new SitePolicy( $settings ), new OutputPipeline( $settings ), $budget );
		$dispatcher->bind_catalog( $catalog, static fn( string $name ): ?AbilityDescriptor => $catalog->get( $name )?->descriptor() );
		return $dispatcher;
	}
}
