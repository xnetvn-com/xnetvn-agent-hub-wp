<?php
/**
 * Core content controller and private pack acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Exercises actual core permissions rather than mocked successful REST calls. */
final class ContentPackTest extends WP_UnitTestCase {
	/** Definitions.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $tools = array();
	/** Editor identity.
	 *
	 * @var int
	 */
	private int $editor;
	/** Establish a real REST server and current actor. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $definition ) {
			$this->tools[ $definition->descriptor()->name ] = $definition;
		}
	}
	/**
	 * Execute with both schema checks, as the registry does after authorization.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function call( string $name, array $args = array() ): mixed {
		$tool = $this->tools[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( new MemorySettings() ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}
	/** Every content contract includes a valid strict example. */
	public function test_contract(): void {
		self::assertCount( 19, $this->tools );
		\XNetVN\AgentHubWP\Tests\Contract\PackContractTestCase::assert_pack_contract( new ContentPack( new DefinitionFactory() ) );
	}
	/** A core query filter cannot widen search to include unpublished content. */
	public function test_search_rechecks_public_status_after_core_filters(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Synthetic private search canary',
				'post_author' => $this->editor,
			)
		);
		$widen = static function ( array $query ): array {
			$query['post_status'] = 'draft';
			return $query;
		};
		add_filter( 'rest_post_search_query', $widen );
		try {
			$result = $this->call( 'search-content', array( 'search' => 'Synthetic private search canary' ) );
			self::assertWPError( $result );
			self::assertStringNotContainsString( 'Synthetic private search canary', $result->get_error_message() );
		} finally {
			remove_filter( 'rest_post_search_query', $widen );
		}
	}
	/** Wrong-type IDs have the same not_found result even when raw access is denied. */
	public function test_type_binding_precedes_raw_permission(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $this->editor,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = $this->call(
			'get-post',
			array(
				'id'             => $page,
				'content_format' => 'raw',
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $result->get_error_code() );
	}
	/** Create/read/update/trash/restore/delete exercise actual persistent state. */
	public function test_lifecycle_and_conflict(): void {
		$post = $this->call(
			'create-post',
			array(
				'title'   => 'Content fixture',
				'content' => '<p>Body</p>',
			)
		);
		self::assertIsArray( $post );
		self::assertSame( 'draft', $post['status'] );
		self::assertSame( $this->editor, $post['author'] );
		$id = $post['id'];
		self::assertArrayHasKey( 'modified_gmt', $post );
		$updated = $this->call(
			'update-post',
			array(
				'id'    => $id,
				'title' => 'Changed',
			)
		);
		self::assertSame( 'Changed', $updated['title'] );
		$conflict = $this->call(
			'update-post',
			array(
				'id'                => $id,
				'title'             => 'Never',
				'expected_modified' => '2000-01-01T00:00:00Z',
			)
		);
		self::assertSame( 'xnetvn_agent_hub_wp_conflict', $conflict->get_error_code() );
		self::assertSame( 'Changed', get_post( $id )->post_title );
		self::assertSame( 'trash', $this->call( 'trash-post', array( 'id' => $id ) )['status'] );
		self::assertSame( 'draft', $this->call( 'restore-post', array( 'id' => $id ) )['status'] );
		self::assertSame(
			array(
				'id'      => $id,
				'deleted' => true,
			),
			$this->call( 'delete-post', array( 'id' => $id ) )
		);
		self::assertNull( get_post( $id ) );
	}
	/** Cross-owner writes/raw reads and Contributor publishing are denied. */
	public function test_object_permissions(): void {
		$id = self::factory()->post->create(
			array(
				'post_author' => $this->editor,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		foreach ( array(
			array(
				'update-post',
				array(
					'id'    => $id,
					'title' => 'Denied',
				),
			),
			array(
				'get-post',
				array(
					'id'             => $id,
					'content_format' => 'raw',
				),
			),
			array( 'duplicate-post', array( 'id' => $id ) ),
		) as $case ) {
			$error = $this->call( $case[0], $case[1] );
			self::assertWPError( $error );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $error->get_error_code() );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		self::assertSame(
			'xnetvn_agent_hub_wp_forbidden',
			$this->call(
				'create-post',
				array(
					'title'  => 'Denied',
					'status' => 'publish',
				)
			)->get_error_code()
		);
	}
	/** System types never pass the general content gateway; CPTs and page binding do. */
	public function test_type_boundary_and_projection(): void {
		foreach ( array( 'attachment', 'wp_navigation', 'wp_block', 'wp_template', 'revision' ) as $type ) {
			self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $this->call( 'list-posts', array( 'post_type' => $type ) )->get_error_code() );
		}
		register_post_type(
			'fixture_book',
			array(
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields', 'revisions' ),
			)
		);
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$book = $this->call(
			'create-post',
			array(
				'post_type' => 'fixture_book',
				'title'     => 'Book',
			)
		);
		self::assertSame( 'fixture_book', $book['type'] );
		self::assertSame( 'xnetvn_agent_hub_wp_not_found', $this->call( 'get-post', array( 'id' => $book['id'] ) )->get_error_code() );
		$view = $this->call(
			'get-post',
			array(
				'post_type' => 'fixture_book',
				'id'        => $book['id'],
				'fields'    => array( 'title' ),
			)
		);
		self::assertSame( array( 'id', 'type', 'status', 'title' ), array_keys( $view ) );
		unregister_post_type( 'fixture_book' );
	}
	/** No-op updates, unsupported sticky and unknown meta fail before changes. */
	public function test_validation_and_meta(): void {
		$id = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		self::assertWPError( $this->call( 'update-post', array( 'id' => $id ) ) );
		self::assertWPError(
			$this->call(
				'update-post-meta',
				array(
					'id'     => $id,
					'values' => array( 'unregistered' => 'Never' ),
				)
			)
		);
		self::assertSame( '', get_post_meta( $id, 'unregistered', true ) );
		self::assertWPError(
			$this->call(
				'create-post',
				array(
					'title'  => 'Future',
					'status' => 'future',
					'date'   => '2000-01-01T00:00:00Z',
				)
			)
		);
		self::assertSame(
			'xnetvn_agent_hub_wp_unsupported_operation',
			$this->call(
				'stick-post',
				array(
					'post_type' => 'page',
					'id'        => self::factory()->post->create( array( 'post_type' => 'page' ) ),
					'sticky'    => true,
				)
			)->get_error_code()
		);
	}
	/** CT-07/09/10: every system type and non-default or hidden controller is denied. */
	public function test_complete_post_type_policy_matrix(): void {
		foreach ( array( 'attachment', 'wp_navigation', 'wp_block', 'wp_template', 'wp_template_part', 'nav_menu_item', 'wp_global_styles', 'wp_font_family', 'revision' ) as $type ) {
			$result = $this->call( 'list-posts', array( 'post_type' => $type ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code() );
		}
		register_post_type( 'fixture_hidden', array( 'show_in_rest' => false ) );
		register_post_type(
			'fixture_custom',
			array(
				'show_in_rest'          => true,
				'rest_controller_class' => FixtureCustomPostsController::class,
			)
		);
		try {
			foreach ( array( 'fixture_hidden', 'fixture_custom' ) as $type ) {
				$result = $this->call( 'list-posts', array( 'post_type' => $type ) );
				self::assertWPError( $result );
				self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code() );
			}
		} finally {
			unregister_post_type( 'fixture_hidden' );
			unregister_post_type( 'fixture_custom' );
		}
	}
	/** CT-14/15/16: malformed collection and mutation inputs never alter state. */
	public function test_invalid_input_matrix(): void {
		$id = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		foreach ( array(
			array( 'list-posts', array( 'per_page' => 101 ) ),
			array( 'list-posts', array( 'page' => 0 ) ),
			array( 'list-posts', array( 'page' => 9999 ) ),
			array( 'list-posts', array( 'status' => array( 'not-a-status' ) ) ),
			array( 'list-posts', array( 'order' => 'sideways' ) ),
			array( 'list-posts', array( 'orderby' => 'arbitrary' ) ),
			array(
				'create-post',
				array(
					'title'  => 'Denied',
					'author' => $this->editor,
				),
			),
			array(
				'create-post',
				array(
					'title' => 'Denied',
					'guid'  => 'fixture',
				),
			),
			array(
				'update-post',
				array(
					'id'    => $id,
					'title' => 'Denied',
					'type'  => 'page',
				),
			),
			array(
				'update-post',
				array(
					'id'   => $id,
					'date' => '2026-10-08T12:00:00',
				),
			),
		) as $case ) {
			$result = $this->call( $case[0], $case[1] );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
		self::assertSame( 1, (int) wp_count_posts()->publish );
	}
	/** CT-17/41: bounds reject before writing; Contributor publishing fails per object. */
	public function test_bulk_bounds_and_contributor_publish(): void {
		$actor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$ids   = self::factory()->post->create_many(
			50,
			array(
				'post_author' => $actor,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $actor );
		foreach ( array( array( $ids[0], $ids[0] ), array_merge( $ids, array( 999999 ) ) ) as $invalid_ids ) {
			$result = $this->call(
				'bulk-update-posts',
				array(
					'ids'     => $invalid_ids,
					'changes' => array( 'status' => 'pending' ),
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
		$result = $this->call(
			'bulk-update-posts',
			array(
				'ids'     => $ids,
				'changes' => array( 'status' => 'pending' ),
			)
		);
		self::assertSame( $ids, $result['updated'] );
		self::assertSame( array(), $result['failed'] );
		$result = $this->call(
			'bulk-update-posts',
			array(
				'ids'     => array_slice( $ids, 0, 2 ),
				'changes' => array( 'status' => 'publish' ),
			)
		);
		self::assertSame( array(), $result['updated'] );
		self::assertCount( 2, $result['failed'] );
		foreach ( $result['failed'] as $failure ) {
			self::assertSame( 'forbidden', $failure['code'] );
			self::assertSame( 'pending', get_post_status( $failure['id'] ) );
		}
	}
	/** CT-28/31/32: scheduling and trash/restore state checks retain core semantics. */
	public function test_scheduling_and_trash_state_matrix(): void {
		foreach ( array( array(), array( 'date' => '2026-10-08T12:00:00' ), array( 'date' => '2000-01-01T00:00:00Z' ) ) as $changes ) {
			$result = $this->call(
				'create-post',
				array_merge(
					array(
						'title'  => 'Future fixture',
						'status' => 'future',
					),
					$changes
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code() );
		}
		$post = $this->call(
			'create-post',
			array(
				'title'  => 'Future fixture',
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ),
			)
		);
		self::assertIsArray( $post );
		self::assertSame( 'future', $post['status'] );
		$result = $this->call( 'restore-post', array( 'id' => $post['id'] ) );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $result->get_error_code() );
		self::assertSame( 'trash', $this->call( 'trash-post', array( 'id' => $post['id'] ) )['status'] );
		$result = $this->call( 'trash-post', array( 'id' => $post['id'] ) );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_state', $result->get_error_code() );
	}
	/** Bulk results are per-object; duplicate never copies password or source owner. */
	public function test_bulk_and_duplicate(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = self::factory()->post->create(
			array(
				'post_author'   => $author,
				'post_password' => 'SECRET',
			)
		);
		$other  = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		wp_set_current_user( $author );
		$result = $this->call(
			'bulk-update-posts',
			array(
				'ids'     => array( $own, $other ),
				'changes' => array( 'status' => 'pending' ),
			)
		);
		self::assertSame( array( $own ), $result['updated'] );
		self::assertSame(
			array(
				array(
					'id'   => $other,
					'code' => 'forbidden',
				),
			),
			$result['failed']
		);
		$copy = $this->call( 'duplicate-post', array( 'id' => $own ) );
		self::assertIsArray( $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		self::assertSame( 'draft', $copy['status'] );
		self::assertSame( $author, $copy['author'] );
		self::assertSame( '', get_post( $copy['id'] )->post_password );
	}
	/** All read and specialized write tools retain their real core contracts. */
	public function test_read_revision_metadata_and_specialized_tools(): void {
		$post = $this->call(
			'create-post',
			array(
				'title'   => 'First revision',
				'content' => '<p>First</p>',
			)
		);
		$id   = $post['id'];
		self::assertStringEndsWith( 'Z', $post['modified_gmt'] );
		self::assertIsArray( $this->call( 'list-post-types' ) );
		self::assertIsArray( $this->call( 'list-post-statuses' ) );
		$list = $this->call(
			'list-posts',
			array(
				'status' => array( 'draft' ),
				'search' => 'First revision',
			)
		);
		self::assertSame( 1, $list['total'] );
		self::assertSame( $id, $list['items'][0]['id'] );
		self::assertSame(
			'First revision',
			$this->call(
				'get-post',
				array(
					'id'             => $id,
					'content_format' => 'raw',
				)
			)['title']
		);
		self::assertSame(
			0,
			$this->call(
				'set-featured-media',
				array(
					'id'       => $id,
					'media_id' => 0,
				)
			)['featured_media']
		);
		self::assertTrue(
			$this->call(
				'stick-post',
				array(
					'id'     => $id,
					'sticky' => true,
				)
			)['sticky']
		);
		register_post_meta(
			'post',
			'fixture_public',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			)
		);
		try {
			$meta = $this->call(
				'update-post-meta',
				array(
					'id'     => $id,
					'values' => array( 'fixture_public' => 'visible' ),
				)
			);
			self::assertSame( 'visible', $meta['meta']->fixture_public );
			self::assertSame(
				'visible',
				$this->call(
					'get-post-meta',
					array(
						'id'   => $id,
						'keys' => array( 'fixture_public' ),
					)
				)['meta']->fixture_public
			);
			self::assertIsArray(
				$this->call(
					'update-post-meta',
					array(
						'id'     => $id,
						'values' => array( 'fixture_public' => null ),
					)
				)
			);
			self::assertFalse( metadata_exists( 'post', $id, 'fixture_public' ) );
		} finally {
			unregister_post_meta( 'post', 'fixture_public' ); }
		$this->call(
			'update-post',
			array(
				'id'      => $id,
				'title'   => 'Second revision',
				'content' => '<p>Second</p>',
			)
		);
		$revisions = $this->call( 'list-revisions', array( 'id' => $id ) );
		self::assertIsArray( $revisions );
		self::assertNotEmpty( $revisions['items'] );
		$rid = $revisions['items'][0]['id'];
		self::assertSame(
			$id,
			$this->call(
				'get-revision',
				array(
					'id'          => $id,
					'revision_id' => $rid,
				)
			)['parent']
		);
		self::assertIsArray(
			$this->call(
				'restore-revision',
				array(
					'id'          => $id,
					'revision_id' => $rid,
				)
			)
		);
		self::assertSame( 0, $this->call( 'search-content', array( 'search' => 'Second revision' ) )['total'] );
	}
	/** A raw collection refuses another owner's record with the reviewed reason. */
	public function test_raw_collection_refusal(): void {
		$id = self::factory()->post->create(
			array(
				'post_author' => $this->editor,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$inject = static function ( $response, $handler, $request ) use ( $id ) {
			if ( '/wp/v2/posts' === $request->get_route() && 'GET' === $request->get_method() ) {
				$response = new \WP_REST_Response(
					array(
						array(
							'id'     => $id,
							'type'   => 'post',
							'status' => 'publish',
							'title'  => array( 'raw' => 'Never disclose' ),
						),
					)
				);
				$response->header( 'X-WP-Total', 1 );
				$response->header( 'X-WP-TotalPages', 1 );
			}
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $inject, 10, 3 );
		try {
			$result = $this->call( 'list-posts', array( 'content_format' => 'raw' ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_forbidden', $result->get_error_code() );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $inject, 10 ); }
	}

	/** Core may create a post before a later registered-meta permission refusal. */
	public function test_partial_create_has_reconciliation_id(): void {
		register_post_meta(
			'post',
			'fixture_refused',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);
		try {
			$result = $this->call(
				'create-post',
				array(
					'title' => 'Partial fixture',
					'meta'  => array( 'fixture_refused' => 'No' ),
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			$id = $result->get_error_data()['created_id'];
			self::assertSame( 'Partial fixture', get_post( $id )->post_title );
			self::assertSame(
				1,
				(int) ( new \WP_Query(
					array(
						'post_status' => 'draft',
						's'           => 'Partial fixture',
					)
				) )->found_posts
			);
		} finally {
			unregister_post_meta( 'post', 'fixture_refused' ); }
	}
	/** A projection failure in the middle of bulk never drops later IDs. */
	public function test_bulk_continues_after_post_write_projection_failure(): void {
		$ids    = self::factory()->post->create_many( 3, array( 'post_author' => $this->editor ) );
		$inject = static function ( $response, $handler, $request ) use ( $ids ) {
			if ( 'POST' === $request->get_method() && '/wp/v2/posts/' . $ids[1] === $request->get_route() && $response instanceof \WP_REST_Response ) {
				$row = $response->get_data();
				unset( $row['title']['rendered'] );
				$response->set_data( $row );
			}
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $inject, 10, 3 );
		try {
			$result = $this->call(
				'bulk-update-posts',
				array(
					'ids'     => $ids,
					'changes' => array( 'status' => 'pending' ),
				)
			);
			self::assertSame( array( $ids[0], $ids[2] ), $result['updated'] );
			self::assertSame(
				array(
					array(
						'id'   => $ids[1],
						'code' => 'partial_failure',
					),
				),
				$result['failed']
			);
			self::assertSame( 'pending', get_post_status( $ids[1] ) );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $inject, 10 ); }
	}
	/** Invalid output after insert preserves the trusted creation identity. */
	public function test_output_failure_retains_real_creation_receipt(): void {
		$corrupt = static function ( $response, $handler, $request ) {
			if ( '/wp/v2/posts' === $request->get_route() && 'POST' === $request->get_method() && $response instanceof \WP_REST_Response ) {
				$row           = $response->get_data();
				$row['author'] = 'invalid provider type';
				$response->set_data( $row ); }
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $corrupt, 10, 3 );
		try {
			$result = $this->call( 'create-post', array( 'title' => 'Output receipt fixture' ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			self::assertSame( 'Output receipt fixture', get_post( $result->get_error_data()['created_id'] )->post_title );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $corrupt, 10 ); }
	}
	/** Valid maximum-sized input is bounded after rendering without denying the valid write. */
	public function test_rendered_expansion_is_truncated_after_success(): void {
		$result = $this->call(
			'create-post',
			array(
				'title'   => 'Rendered size fixture',
				'content' => str_repeat( 'a', 262144 ),
			)
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['truncated'] );
		self::assertLessThanOrEqual( 262144, strlen( wp_json_encode( $result ) ) );
		self::assertSame( 262144, strlen( get_post( $result['id'] )->post_content ) );
	}

	/** A response filter cannot erase the independently observed mutation ID. */
	public function test_response_id_removal_retains_receipt(): void {
		$strip = static function ( $response, $handler, $request ) {
			if ( '/wp/v2/posts' === $request->get_route() && 'POST' === $request->get_method() && $response instanceof \WP_REST_Response ) {
				$row = $response->get_data();
				unset( $row['id'] );
				$response->set_data( $row );
			}
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $strip, 10, 3 );
		try {
			$result = $this->call( 'create-post', array( 'title' => 'Removed ID receipt fixture' ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			self::assertSame( 'Removed ID receipt fixture', get_post( $result->get_error_data()['created_id'] )->post_title );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $strip, 10 ); }
	}

	/** Failed readback after a successful revision restore remains a partial result. */
	public function test_restore_readback_failure_retains_affected_id(): void {
		$post = $this->call(
			'create-post',
			array(
				'title'   => 'Revision receipt before',
				'content' => 'Before',
			)
		);
		$id   = $post['id'];
		$this->call(
			'update-post',
			array(
				'id'      => $id,
				'title'   => 'Revision receipt after',
				'content' => 'After',
			)
		);
		$revisions = $this->call( 'list-revisions', array( 'id' => $id ) );
		$rid       = $revisions['items'][0]['id'];
		$deny      = static function ( $response, $handler, $request ) use ( $id ) {
			return 'GET' === $request->get_method() && '/wp/v2/posts/' . $id === $request->get_route() ? new \WP_Error( 'rest_forbidden', 'Untrusted provider detail', array( 'status' => 403 ) ) : $response;
		};
		add_filter( 'rest_request_after_callbacks', $deny, 10, 3 );
		try {
			$result = $this->call(
				'restore-revision',
				array(
					'id'          => $id,
					'revision_id' => $rid,
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			self::assertSame( $id, $result->get_error_data()['affected_id'] );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $deny, 10 ); }
	}
	/** Registered metadata output validation cannot erase a completed update receipt. */
	public function test_metadata_projection_failure_retains_affected_id(): void {
		$id = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		register_post_meta(
			'post',
			'fixture_receipt',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => '__return_true',
			)
		);
		$corrupt = static function ( $response, $handler, $request ) use ( $id ) {
			if ( 'POST' === $request->get_method() && '/wp/v2/posts/' . $id === $request->get_route() && $response instanceof \WP_REST_Response ) {
				$row                            = $response->get_data();
				$row['meta']['fixture_receipt'] = array( 'wrong type' );
				$response->set_data( $row ); }
			return $response;
		};
		add_filter( 'rest_request_after_callbacks', $corrupt, 10, 3 );
		try {
			$result = $this->call(
				'update-post-meta',
				array(
					'id'     => $id,
					'values' => array( 'fixture_receipt' => 'saved' ),
				)
			);
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			self::assertSame( $id, $result->get_error_data()['affected_id'] );
			self::assertSame( 'saved', get_post_meta( $id, 'fixture_receipt', true ) );
		} finally {
			remove_filter( 'rest_request_after_callbacks', $corrupt, 10 );
			unregister_post_meta( 'post', 'fixture_receipt' ); }
	}
}
