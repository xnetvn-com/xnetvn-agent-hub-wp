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
		Functions\expect( 'add_filter' )->times( 7 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 7 )->andReturn( true );

		$html = new SafeEditorialHtml();
		self::assertSame( 'result', $html->run( static fn(): string => 'result' ) );
	}

	/** Nested runs reuse the outer hook boundary. */
	public function test_nested_run_manages_hooks_once(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 7 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 7 )->andReturn( true );

		$html   = new SafeEditorialHtml();
		$nested = static function () use ( $html ): string {
			return $html->run( static fn(): string => 'nested' );
		};
		self::assertSame( 'nested', $html->run( $nested ) );
	}

	/** An exception still restores the exact hook boundary. */
	public function test_exception_restores_hooks(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\expect( 'add_filter' )->times( 7 )->andReturn( true );
		Functions\expect( 'remove_filter' )->times( 7 )->andReturn( true );

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

	/** Plain text and post HTML use their distinct allowlists. */
	public function test_plain_text_and_post_html_are_filtered(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->justReturn( '<p>safe</p>' );

		$html = new SafeEditorialHtml();
		self::assertSame( 'Title', $html->filter_plain_text( 'Title' ) );
		self::assertSame( '<p>safe</p>', $html->filter_post_html( '<script>alert(1)</script>' ) );
	}
}
