<?php
/**
 * Stored editorial HTML boundary integration tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentOperations;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;

/** Verifies that the reviewed MCP handlers filter persisted rows, not only responses. */
final class McpHtmlBoundaryTest extends WP_UnitTestCase {

	/** Editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/** Content handler.
	 *
	 * @var ContentOperations
	 */
	private ContentOperations $content;

	/** Comment handler.
	 *
	 * @var CommentOperations
	 */
	private CommentOperations $comments;

	/** Build a real REST server and reproduce an unfiltered_html request. */
	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user         = get_userdata( $this->editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $this->editor );
		// Reproduce the request state core prepares for an unfiltered_html owner.
		kses_remove_filters();
		self::assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		$this->content  = new ContentOperations();
		$this->comments = new CommentOperations();
	}

	/** Restore the shared core state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		kses_init_filters();
		parent::tear_down();
	}

	/**
	 * Assert stored markup keeps benign formatting and drops executable HTML.
	 *
	 * @param string $value   Stored value.
	 * @param string $allowed Benign markup that must survive.
	 */
	private function assert_safe( string $value, string $allowed ): void {
		self::assertStringNotContainsString( '<script', $value );
		self::assertStringNotContainsString( 'onerror', $value );
		self::assertStringNotContainsString( 'onload', $value );
		self::assertStringNotContainsString( 'javascript:', $value );
		self::assertStringContainsString( $allowed, $value );
	}

	/** Create, update, duplicate and bulk handlers all filter stored rows. */
	public function test_content_handlers_filter_persisted_rows(): void {
		$created = $this->content->execute(
			'create-post',
			array(
				'title'   => '<b>Unsafe</b><script>alert(1)</script>',
				'content' => '<p>Safe</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">x</a>',
				'excerpt' => '<p>Safe excerpt</p><script>alert(1)</script>',
			)
		);
		self::assertInstanceOf( MutationResult::class, $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$id     = $created->object_id;
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		$this->assert_safe( $stored->post_content, '<p>Safe</p>' );
		$this->assert_safe( $stored->post_excerpt, '<p>Safe excerpt</p>' );
		self::assertStringNotContainsString( '<b>', $stored->post_title );
		self::assertStringNotContainsString( '<script', $stored->post_title );
		self::assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		$updated = $this->content->execute(
			'update-post',
			array(
				'id'      => $id,
				'content' => '<p>Updated</p><script>alert(2)</script><svg onload="alert(2)"></svg>',
			)
		);
		self::assertInstanceOf( MutationResult::class, $updated );
		$this->assert_safe( (string) get_post( $id )->post_content, '<p>Updated</p>' );

		$legacy = self::factory()->post->create(
			array(
				'post_author'  => $this->editor,
				'post_content' => '<p>Legacy</p><script>alert(3)</script>',
				'post_status'  => 'draft',
				'post_title'   => '<b>Legacy</b><script>alert(3)</script>',
			)
		);
		$copy   = $this->content->execute( 'duplicate-post', array( 'id' => $legacy ) );
		self::assertInstanceOf( MutationResult::class, $copy, is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$copied = get_post( $copy->object_id );
		self::assertInstanceOf( \WP_Post::class, $copied );
		$this->assert_safe( $copied->post_content, '<p>Legacy</p>' );
		self::assertStringNotContainsString( '<b>', $copied->post_title );

		$bulk = $this->content->execute(
			'bulk-update-posts',
			array(
				'ids'     => array( $id, $legacy ),
				'changes' => array( 'content' => '<p>Bulk</p><script>alert(4)</script>' ),
			)
		);
		self::assertIsArray( $bulk );
		self::assertSame( array( $id, $legacy ), $bulk['updated'] );
		foreach ( array( $id, $legacy ) as $target ) {
			$this->assert_safe( (string) get_post( $target )->post_content, '<p>Bulk</p>' );
		}
	}

	/** Restoring a stored unsafe revision still filters the parent row. */
	public function test_restore_revision_filters_persisted_html(): void {
		$created = $this->content->execute(
			'create-post',
			array(
				'title'   => 'Revision fixture',
				'content' => '<p>Current</p>',
			)
		);
		self::assertInstanceOf( MutationResult::class, $created );
		$id       = $created->object_id;
		$revision = wp_insert_post(
			wp_slash(
				array(
					'post_content' => '<p>Revision</p><script>alert(5)</script>',
					'post_excerpt' => '<p>Revision excerpt</p><script>alert(5)</script>',
					'post_parent'  => $id,
					'post_status'  => 'inherit',
					'post_title'   => '<b>Revision</b><script>alert(5)</script>',
					'post_type'    => 'revision',
				)
			),
			true
		);
		self::assertIsInt( $revision );
		self::assertStringContainsString( '<script', (string) get_post( $revision )->post_content );

		$writes  = 0;
		$counter = static function ( int $post_id ) use ( &$writes, $id ): void {
			if ( $post_id === $id ) {
				++$writes;
			}
		};
		add_action( 'wp_insert_post', $counter, 10, 1 );
		try {
			$restored = $this->content->execute(
				'restore-revision',
				array(
					'id'          => $id,
					'revision_id' => $revision,
				)
			);
		} finally {
			remove_action( 'wp_insert_post', $counter );
		}
		self::assertSame( 1, $writes );
		self::assertInstanceOf( MutationResult::class, $restored, is_wp_error( $restored ) ? $restored->get_error_message() : '' );
		$stored = get_post( $id );
		self::assertInstanceOf( \WP_Post::class, $stored );
		$this->assert_safe( $stored->post_content, '<p>Revision</p>' );
		$this->assert_safe( $stored->post_excerpt, '<p>Revision excerpt</p>' );
		self::assertStringNotContainsString( '<b>', $stored->post_title );
		self::assertStringNotContainsString( '<script', $stored->post_title );
	}

	/** Registered metadata and comment handlers filter persisted values. */
	public function test_meta_and_comment_handlers_filter_persisted_rows(): void {
		register_post_meta(
			'post',
			'fixture_html',
			array(
				'auth_callback' => '__return_true',
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
			)
		);
		register_post_meta(
			'post',
			'fixture_html_list',
			array(
				'auth_callback' => '__return_true',
				'single'        => true,
				'type'          => 'array',
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_post_meta(
			'post',
			'fixture_uri',
			array(
				'auth_callback' => '__return_true',
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => array(
					'schema' => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
			)
		);
		register_post_meta(
			'post',
			'fixture_object',
			array(
				'auth_callback' => '__return_true',
				'single'        => true,
				'type'          => 'object',
				'show_in_rest'  => true,
			)
		);
		register_post_meta(
			'post',
			'fixture_nested',
			array(
				'auth_callback' => '__return_true',
				'single'        => true,
				'type'          => 'array',
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
		$this->setExpectedIncorrectUsage( 'register_meta' );
		register_post_meta(
			'post',
			'fixture_unproven',
			array(
				'auth_callback' => '__return_true',
				'items'         => array( 'type' => 'string' ),
				'single'        => true,
				'type'          => 'array',
				'show_in_rest'  => true,
			)
		);
		try {
			$created = $this->content->execute(
				'create-post',
				array(
					'title'   => 'Meta fixture',
					'content' => '<p>Body</p>',
				)
			);
			self::assertInstanceOf( MutationResult::class, $created );
			$id   = $created->object_id;
			$meta = $this->content->execute(
				'update-post-meta',
				array(
					'id'     => $id,
					'values' => array(
						'fixture_html'      => '<p>Meta</p><script>alert(6)</script>',
						'fixture_html_list' => array( '<p>One</p><script>alert(6)</script>' ),
						'fixture_uri'       => 'https://example.com/?a=1&b=2',
					),
				)
			);
			self::assertInstanceOf( MutationResult::class, $meta, is_wp_error( $meta ) ? $meta->get_error_message() : '' );
			$this->assert_safe( (string) get_post_meta( $id, 'fixture_html', true ), '<p>Meta</p>' );
			$list = get_post_meta( $id, 'fixture_html_list', true );
			self::assertIsArray( $list );
			$this->assert_safe( (string) $list[0], '<p>One</p>' );
			self::assertSame( 'https://example.com/?a=1&b=2', get_post_meta( $id, 'fixture_uri', true ) );

			foreach ( array(
				'fixture_object'   => array( 'x' => 'y' ),
				'fixture_nested'   => array( array( '<p>x</p>' ) ),
				'fixture_unproven' => array( '<p>x</p>' ),
			) as $key => $value ) {
				$refused = $this->content->execute(
					'update-post-meta',
					array(
						'id'     => $id,
						'values' => array( $key => $value ),
					)
				);
				self::assertWPError( $refused );
				self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $refused->get_error_code() );
				self::assertFalse( metadata_exists( 'post', $id, $key ) );
			}
			$before         = (int) wp_count_posts( 'post' )->draft;
			$refused_create = $this->content->execute(
				'create-post',
				array(
					'title' => 'Refused meta fixture',
					'meta'  => array( 'fixture_object' => array( 'x' => 'y' ) ),
				)
			);
			self::assertWPError( $refused_create );
			self::assertSame( $before, (int) wp_count_posts( 'post' )->draft );
		} finally {
			foreach ( array( 'fixture_html', 'fixture_html_list', 'fixture_uri', 'fixture_object', 'fixture_nested', 'fixture_unproven' ) as $key ) {
				unregister_post_meta( 'post', $key );
			}
		}

		$post    = self::factory()->post->create( array( 'comment_status' => 'open' ) );
		$comment = $this->comments->execute(
			'create-comment',
			array(
				'post'    => $post,
				'content' => '<strong>Safe comment</strong><script>alert(7)</script><img src="x" onerror="alert(7)">',
				'status'  => 'approved',
			)
		);
		self::assertInstanceOf( MutationResult::class, $comment, is_wp_error( $comment ) ? $comment->get_error_message() : '' );
		$stored = get_comment( $comment->object_id );
		self::assertInstanceOf( \WP_Comment::class, $stored );
		$this->assert_safe( $stored->comment_content, '<strong>Safe comment</strong>' );

		$updated = $this->comments->execute(
			'update-comment',
			array(
				'id'          => $comment->object_id,
				'content'     => '<strong>Edited comment</strong><script>alert(8)</script>',
				'author_name' => '<b>Fixture</b><script>alert(8)</script>',
			)
		);
		self::assertInstanceOf( MutationResult::class, $updated );
		$stored = get_comment( $comment->object_id );
		self::assertInstanceOf( \WP_Comment::class, $stored );
		$this->assert_safe( $stored->comment_content, '<strong>Edited comment</strong>' );
		self::assertStringNotContainsString( '<b>', $stored->comment_author );
		self::assertStringNotContainsString( '<script', $stored->comment_author );
	}

	/** The boundary restores hooks and capability after a thrown exception. */
	public function test_hook_state_is_restored_after_an_exception(): void {
		$custom = static fn( string $value ): string => $value;
		add_filter( 'content_save_pre', $custom, 10 );
		$before = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		// Core denies unfiltered_html to a non-Super-Admin on Multisite (wp-includes/capabilities.php:595-602).
		$cap_before = current_user_can( 'unfiltered_html' );
		self::assertSame( ! is_multisite(), $cap_before );
		$html = new SafeEditorialHtml();
		try {
			try {
				$html->run(
					static function (): void {
						throw new \RuntimeException( 'Fixture failure.' );
					}
				);
				self::fail( 'The fixture exception was swallowed.' );
			} catch ( \RuntimeException $error ) {
				self::assertSame( 'Fixture failure.', $error->getMessage() );
			}
			self::assertSame( $before, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
			self::assertSame( $cap_before, current_user_can( 'unfiltered_html' ) );
			self::assertNotFalse( has_filter( 'content_save_pre', $custom ) );
		} finally {
			remove_filter( 'content_save_pre', $custom, 10 );
		}
		self::assertFalse( has_filter( 'content_save_pre', $custom ) );
	}

	/** The capability and pre-existing hook state are restored outside the boundary. */
	public function test_capability_and_hook_state_are_restored(): void {
		$custom = static fn( string $value ): string => $value;
		add_filter( 'content_save_pre', $custom, 10 );
		try {
			$html = new SafeEditorialHtml();
			// Core denies unfiltered_html to a non-Super-Admin on Multisite (wp-includes/capabilities.php:595-602).
			$cap_before = current_user_can( 'unfiltered_html' );
			self::assertSame( ! is_multisite(), $cap_before );
			self::assertNotFalse( has_filter( 'content_save_pre', $custom ) );
			self::assertFalse( $html->run( static fn(): bool => current_user_can( 'unfiltered_html' ) ) );
			self::assertSame( $cap_before, current_user_can( 'unfiltered_html' ) );
			self::assertNotFalse( has_filter( 'content_save_pre', $custom ) );
			self::assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		} finally {
			remove_filter( 'content_save_pre', $custom, 10 );
		}
	}
}
