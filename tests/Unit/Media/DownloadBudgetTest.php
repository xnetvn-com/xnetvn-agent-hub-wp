<?php
/**
 * Stop reading immediately when the permitted body budget is exceeded.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Support\Http\DownloadBudget;

/** Limits consumption, rather than merely truncating stored output. */
final class DownloadBudgetTest extends TestCase {
	/** Native progress abort uses a return code rather than a deferred PHP exception. */
	public function test_native_transfer_abort_is_latched(): void {
		$budget = new DownloadBudget( 1024 );
		self::assertSame( 0, $budget->transfer( 1024 ) );
		self::assertSame( 1, $budget->transfer( 1025 ) );
		self::assertTrue( $budget->exceeded() );
		self::assertSame( 1, $budget->transfer( 0 ) );
	}
}
