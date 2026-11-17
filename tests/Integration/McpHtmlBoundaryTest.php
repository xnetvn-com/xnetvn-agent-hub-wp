<?php
/**
 * Stored editorial HTML boundary integration tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;

/** Verifies that the scoped boundary filters stored content, not only responses. */
final class McpHtmlBoundaryTest extends WP_UnitTestCase {

	/** Restore the current user after each case. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Scripts and unsafe URLs do not persist for an unfiltered editor. */
	public function test_post_and_comment_storage_are_filtered(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user   = get_userdata( $editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $editor );
		self::assertTrue( current_user_can( 'unfiltered_html' ) );

		$html   = new SafeEditorialHtml();
		$before = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		$post   = $html->run(
			static fn(): int => (int) wp_insert_post(
				wp_slash(
					array(
						'post_title'   => '<b>Unsafe</b><script>alert(1)</script>',
						'post_content' => '<p>Safe</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
						'post_status'  => 'draft',
					)
				),
				true
			)
		);

		self::assertGreaterThan( 0, $post );
		$stored = get_post( $post );
		self::assertInstanceOf( \WP_Post::class, $stored );
		self::assertStringNotContainsString( '<script', $stored->post_content );
		self::assertStringNotContainsString( 'javascript:', $stored->post_content );
		self::assertStringContainsString( '<p>Safe</p>', $stored->post_content );
		self::assertStringNotContainsString( '<b>', $stored->post_title );
		self::assertStringNotContainsString( '<script', $stored->post_title );
		self::assertSame( $before, has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );

		$comment = $html->run(
			static fn(): int => (int) wp_insert_comment(
				wp_slash(
					array(
						'comment_post_ID'  => $post,
						'comment_content'  => '<p>Safe</p><script>alert(1)</script>',
						'comment_author'   => 'Fixture',
						'comment_approved' => 1,
					)
				)
			)
		);
		self::assertGreaterThan( 0, $comment );
		$stored_comment = get_comment( $comment );
		self::assertInstanceOf( \WP_Comment::class, $stored_comment );
		self::assertStringNotContainsString( '<script', $stored_comment->comment_content );
		self::assertStringContainsString( '<p>Safe</p>', $stored_comment->comment_content );
	}

	/** The unfiltered-html capability is denied only inside the boundary. */
	public function test_unfiltered_html_is_denied_inside_the_boundary(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user   = get_userdata( $editor );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $editor );

		$html = new SafeEditorialHtml();
		self::assertTrue( current_user_can( 'unfiltered_html' ) );
		self::assertFalse( $html->run( static fn(): bool => current_user_can( 'unfiltered_html' ) ) );
		self::assertTrue( current_user_can( 'unfiltered_html' ) );
	}

	/** Hooks are restored after a thrown exception. */
	public function test_hook_state_is_restored_after_an_exception(): void {
		$html   = new SafeEditorialHtml();
		$before = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
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
	}
}
