<?php
/**
 * Storage diagnostic copy tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Persistence;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Persistence\MigrationProvider;

/** Guideline 11 requires an error alert to say how to resolve it. */
final class MigrationProviderNoticeTest extends TestCase {
	/** Initialize isolated WordPress function substitutes. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}
	/** Remove every function substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	/** Administrators see an escaped error that names the next step. */
	public function test_notice_names_a_next_step_for_administrators(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		MigrationProvider::render_storage_notice();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Next step', $html );
		self::assertStringContainsString( 'Site Health', $html );
	}
	/** Other roles see nothing at all. */
	public function test_notice_is_silent_for_other_roles(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		MigrationProvider::render_storage_notice();
		self::assertSame( '', (string) ob_get_clean() );
	}
}
