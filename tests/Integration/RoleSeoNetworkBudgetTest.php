<?php
/**
 * Role/SEO network runner budget contract.
 *
 * Pins the mechanism the remote network matrix relies on: one acceptance
 * phase runs the isolation part and the policy part back to back, so the
 * policy part has to start after the weighted per-minute auth-failure window
 * of the isolation part. Without that pause the child blog answers 429 to
 * later calls, including valid ones, and the policy cases stop being
 * measurable. Real HTTPS behaviour stays in tests/E2E/role-seo-network.php;
 * this file pins the runner contract without any network.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Pins the network runner budget contract without a lab. */
final class RoleSeoNetworkBudgetTest extends TestCase {
	/** Pacing expression the policy part is required to keep. */
	private const PACING = 'sleep $(( 122 - 10#$(date -u +%S) ))';

	/**
	 * Read one repository file or fail.
	 *
	 * @param string $relative Repository-relative path.
	 * @return string File contents.
	 */
	private function read( string $relative ): string {
		$path = dirname( __DIR__, 2 ) . '/' . $relative;
		self::assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read one owned repository file, never a remote URL.
		$contents = file_get_contents( $path );
		self::assertIsString( $contents );
		return $contents;
	}

	/** The policy part waits one full weighted window before it starts. */
	public function test_policy_part_starts_after_the_auth_failure_window(): void {
		$runner = $this->read( 'infra/remote/run-role-seo-network.sh' );
		$guard  = strpos( $runner, 'if [[ "$part" == \'b\' ]]; then' );
		$wait   = strpos( $runner, self::PACING );
		$matrix = strpos( $runner, 'tests/E2E/role-seo-network.php matrix' );
		self::assertIsInt( $guard );
		self::assertIsInt( $wait );
		self::assertIsInt( $matrix );
		self::assertLessThan( $wait, $guard, 'The pause must be guarded by the policy part.' );
		self::assertLessThan( $matrix, $wait, 'The pause must run before the matrix starts.' );
		self::assertSame( 1, substr_count( $runner, self::PACING ), 'Only the policy part may wait.' );
	}

	/** The policy part still runs its own pause and only its own groups. */
	public function test_policy_part_keeps_its_internal_pause_and_group_set(): void {
		$matrix = $this->read( 'tests/E2E/role-seo-network.php' );
		self::assertStringContainsString( 'sleep( 120 );', $matrix, 'The explicit-deny pause must stay.' );
		$runner = $this->read( 'infra/remote/run-role-seo-network.sh' );
		self::assertStringContainsString( 'array( "NET-03", "NET-04", "NET-05" )', $runner, 'Part b still holds the cross-blog policy groups.' );
	}

	/** The two failing case ids stay declared once and keep their expectations. */
	public function test_policy_case_ids_are_unchanged(): void {
		$matrix = $this->read( 'tests/E2E/role-seo-network.php' );
		self::assertSame( 1, substr_count( $matrix, "'NET-03-ban-main-child'" ) );
		self::assertSame( 1, substr_count( $matrix, "'NET-04-collision-main-child'" ) );
		self::assertSame( 1, substr_count( $matrix, "'NET-03-token-main-child'" ) );
		self::assertStringNotContainsString( "'skip'", $matrix );
	}
}
