<?php
/**
 * Scoped editorial HTML boundary tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\SafeEditorialHtml;

/** Pins scoped hook lifecycle and field-appropriate sanitization. */
final class SafeEditorialHtmlTest extends TestCase {

	/** Install function substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** The outermost run adds and removes exactly the reviewed hooks. */
	public function test_run_adds_and_removes_reviewed_hooks(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 8 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 8 )->andReturn( true );

		$html = new SafeEditorialHtml();
		self::assertSame( 'result', $html->run( static fn(): string => 'result' ) );
	}

	/** Nested runs reuse the outer hook boundary. */
	public function test_nested_run_manages_hooks_once(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 8 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 8 )->andReturn( true );

		$html   = new SafeEditorialHtml();
		$nested = static function () use ( $html ): string {
			return $html->run( static fn(): string => 'nested' );
		};
		self::assertSame( 'nested', $html->run( $nested ) );
	}

	/** An exception still restores the exact hook boundary. */
	public function test_exception_restores_hooks(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 8 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 8 )->andReturn( true );

		$html = new SafeEditorialHtml();
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
	}

	/** The reviewed hook map is exact and pre-attached filters are preserved. */
	public function test_reviewed_hook_map_and_pre_attached_filters(): void {
		$calls = array();
		Functions\when( 'has_filter' )->alias(
			static fn( string $hook, string $callback ): bool => 'excerpt_save_pre' === $hook && 'wp_filter_post_kses' === $callback
		);
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, mixed $callback, int $priority ) use ( &$calls ): bool {
				unset( $callback );
				$calls[] = array( $hook, $priority );
				return true;
			}
		);
		Functions\when( 'remove_filter' )->justReturn( true );

		$html = new SafeEditorialHtml();
		self::assertSame( 'ok', $html->run( static fn(): string => 'ok' ) );
		self::assertSame(
			array(
				array( 'map_meta_cap', PHP_INT_MAX ),
				array( 'content_save_pre', 9 ),
				array( 'content_save_pre', 10 ),
				array( 'content_filtered_save_pre', 9 ),
				array( 'content_filtered_save_pre', 10 ),
				array( 'title_save_pre', 10 ),
				array( 'pre_comment_content', 10 ),
			),
			$calls
		);
	}

	/** Registered metadata follows the declared REST schema without recursion. */
	public function test_meta_policy_by_schema(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $value ): string => 'KSES(' . $value . ')' );

		$html = new SafeEditorialHtml();
		self::assertSame( 'KSES(<p>x</p>)', $html->filter_meta( array( 'type' => 'string' ), '<p>x</p>' ) );
		self::assertSame(
			'https://example.com/?a=1&b=2',
			$html->filter_meta(
				array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'https://example.com/?a=1&b=2'
			)
		);
		self::assertSame(
			'fixture@example.com',
			$html->filter_meta(
				array(
					'type'   => 'string',
					'format' => 'email',
				),
				'fixture@example.com'
			)
		);
		self::assertSame(
			array( 'KSES(one)', 'KSES(two)' ),
			$html->filter_meta(
				array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				array( 'one', 'two' )
			)
		);
		self::assertNull( $html->filter_meta( array( 'type' => 'string' ), null ) );
		self::assertSame( 7, $html->filter_meta( array( 'type' => 'integer' ), 7 ) );
		self::assertSame( 1.5, $html->filter_meta( array( 'type' => 'number' ), 1.5 ) );
		self::assertTrue( $html->filter_meta( array( 'type' => 'boolean' ), true ) );
		self::assertInstanceOf( \WP_Error::class, $html->filter_meta( array( 'type' => 'object' ), array( 'x' => 'y' ) ) );
		self::assertInstanceOf(
			\WP_Error::class,
			$html->filter_meta(
				array(
					'type'  => 'array',
					'items' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				array( array( 'x' ) )
			)
		);
		self::assertInstanceOf(
			\WP_Error::class,
			$html->filter_meta(
				array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				array( 1 )
			)
		);
		self::assertInstanceOf( \WP_Error::class, $html->filter_meta( array( 'type' => 'unknown' ), 'x' ) );
	}

	/** The plain-title scope adds one reviewed filter and restores it exactly. */
	public function test_plain_title_scope_adds_and_removes_exact_filter(): void {
		$adds    = array();
		$removes = array();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, mixed $callback, int $priority ) use ( &$adds ): bool {
				unset( $callback );
				$adds[] = array( $hook, $priority );
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			static function ( string $hook, mixed $callback, int $priority ) use ( &$removes ): bool {
				unset( $callback );
				$removes[] = array( $hook, $priority );
				return true;
			}
		);

		$html = new SafeEditorialHtml();
		self::assertSame( 'ok', $html->run_plain_title( static fn(): string => 'ok' ) );
		self::assertSame(
			array(
				array( 'title_save_pre', 20 ),
				array( 'map_meta_cap', PHP_INT_MAX ),
				array( 'content_save_pre', 9 ),
				array( 'content_save_pre', 10 ),
				array( 'content_filtered_save_pre', 9 ),
				array( 'content_filtered_save_pre', 10 ),
				array( 'excerpt_save_pre', 10 ),
				array( 'title_save_pre', 10 ),
				array( 'pre_comment_content', 10 ),
			),
			$adds
		);
		self::assertSame(
			array(
				array( 'content_save_pre', 9 ),
				array( 'content_save_pre', 10 ),
				array( 'content_filtered_save_pre', 9 ),
				array( 'content_filtered_save_pre', 10 ),
				array( 'excerpt_save_pre', 10 ),
				array( 'title_save_pre', 10 ),
				array( 'pre_comment_content', 10 ),
				array( 'map_meta_cap', PHP_INT_MAX ),
				array( 'title_save_pre', 20 ),
			),
			$removes
		);
	}

	/** The plain-title filter is removed when the scoped work throws. */
	public function test_plain_title_scope_restores_after_exception(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 9 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 9 )->andReturn( true );

		$html = new SafeEditorialHtml();
		try {
			$html->run_plain_title(
				static function (): void {
					throw new \RuntimeException( 'Fixture failure.' );
				}
			);
			self::fail( 'The fixture exception was swallowed.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Fixture failure.', $error->getMessage() );
		}
	}

	/** Plain text and post HTML use their distinct allowlists. */
	public function test_plain_text_and_post_html_are_filtered(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $value ): string => preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $value ): string => trim( $value ) );
		Functions\when( 'wp_kses_post' )->justReturn( '<p>safe</p>' );

		$html = new SafeEditorialHtml();
		self::assertSame( 'Title', $html->filter_plain_text( '<b>Title</b>' ) );
		self::assertSame( '<p>safe</p>', $html->filter_post_html( '<script>alert(1)</script>' ) );
	}
}
