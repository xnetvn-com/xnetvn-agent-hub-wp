<?php
/**
 * Role/SEO fixture runner cron-stability contract.
 *
 * Pins the FX-06 fault-restore and FX-08 SIGKILL recovery gates of the remote
 * fixture runner: both gates must compare the owned counts
 * (users/keys/posts/terms/media) plus settings_digest and ignore cron_digest,
 * because WP-Cron churns while the scratch run is in flight. Real fixture,
 * ledger and restore behaviour stays in tests/E2E/role-seo-fixture.php; this
 * file pins the runner contract without any lab.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Pins the FX-06 and FX-08 cron-stability contracts of the fixture runner without a lab. */
final class RoleSeoFixtureCronStabilityTest extends TestCase {
	/** Runner that owns the FX gates. */
	private const RUNNER = 'infra/remote/run-role-seo.sh';

	/** Whole-document comparison that cron drift used to break in FX-08. */
	private const FX08_CHURNING_COMPARE = '[[ "$counts_baseline" == "$counts_after_kill" ]]';

	/** Whole-document comparison that cron drift used to break in FX-06. */
	private const FX06_CHURNING_COMPARE = '"$counts_baseline" == "$counts_after_restore"';

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

	/**
	 * Extract one runner gate between two markers.
	 *
	 * @param string $start Marker that opens the gate.
	 * @param string $end   Marker that opens the next gate.
	 * @return string The extracted gate.
	 */
	private function gateBlock( string $start, string $end ): string {
		$runner = $this->read( self::RUNNER );
		$from   = strpos( $runner, $start );
		$to     = strpos( $runner, $end );
		self::assertIsInt( $from, 'The runner must keep the ' . $start . ' marker.' );
		self::assertIsInt( $to, 'The runner must keep the ' . $end . ' marker.' );
		self::assertLessThan( $to, $from, $start . ' must still run before ' . $end . '.' );
		return substr( $runner, $from, $to - $from );
	}

	/**
	 * Extract only the FX-06 fault-restore gate from the runner.
	 *
	 * @return string The FX-06 block, from its marker to the FX-08 gate.
	 */
	private function fx06Block(): string {
		return $this->gateBlock( '# FX-06:', '# FX-08:' );
	}

	/**
	 * Extract only the FX-08 SIGKILL gate from the runner.
	 *
	 * @return string The FX-08 block, from its marker to the main seed.
	 */
	private function fx08Block(): string {
		return $this->gateBlock( '# FX-08:', '# Main seed' );
	}

	/**
	 * Extract the real counts_stable body from the runner.
	 *
	 * @return string The shell function definition.
	 */
	private function countsStableDefinition(): string {
		$runner = $this->read( self::RUNNER );
		$start  = strpos( $runner, 'counts_stable() {' );
		self::assertIsInt( $start, 'The runner must keep counts_stable.' );
		$end = strpos( $runner, "\n}\n", $start );
		self::assertIsInt( $end, 'The runner must close counts_stable.' );
		return substr( $runner, $start, $end - $start + 2 );
	}

	/**
	 * Run the extracted counts_stable against two snapshots.
	 *
	 * @param string $baseline  Snapshot piped on standard input.
	 * @param string $candidate Snapshot passed as the only argument.
	 * @return int Exit status of the comparison.
	 */
	private function runCountsStable( string $baseline, string $candidate ): int {
		$script  = $this->countsStableDefinition() . "\n" . 'printf \'%s\' "$1" | counts_stable "$2"' . "\n";
		$command = escapeshellarg( 'bash' ) . ' -c ' . escapeshellarg( $script ) . ' bash '
			. escapeshellarg( $baseline ) . ' ' . escapeshellarg( $candidate );
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the owned shell comparison with fixed argv and no credentials.
		exec( $command . ' 2>/dev/null', $output, $status );
		return $status;
	}

	/**
	 * Assert one gate compares owned counts plus the settings digest, not the whole document.
	 *
	 * @param string $block     Extracted gate block.
	 * @param string $candidate Candidate snapshot variable.
	 * @param string $settings  Candidate settings variable.
	 * @param string $legacy    Whole-document comparison the gate must drop.
	 * @param string $gate      Gate name used in failure messages.
	 * @return void
	 */
	private function assertOwnedStateGate( string $block, string $candidate, string $settings, string $legacy, string $gate ): void {
		self::assertStringNotContainsString(
			$legacy,
			$block,
			$gate . ' must not compare the whole counts document: cron_digest churns during a run.'
		);
		self::assertStringContainsString(
			'printf \'%s\' "$counts_baseline" | counts_stable "$' . $candidate . '"',
			$block,
			$gate . ' must reuse the owned-counts comparison used by the cleanup gate.'
		);
		self::assertMatchesRegularExpression(
			'/\[\[ "\$baseline_settings" == "\$' . preg_quote( $settings, '/' ) . '" \]\]/',
			$block,
			$gate . ' must compare the settings digest of both snapshots in a separate step.'
		);
	}

	/**
	 * Assert one gate reads settings_digest from both snapshots.
	 *
	 * @param string $block     Extracted gate block.
	 * @param string $candidate Candidate snapshot variable.
	 * @param string $prefix    Candidate settings variable prefix.
	 * @param string $gate      Gate name used in failure messages.
	 * @return void
	 */
	private function assertSettingsDigestSources( string $block, string $candidate, string $prefix, string $gate ): void {
		self::assertSame(
			2,
			substr_count( $block, '["settings_digest"]' ),
			$gate . ' must decode settings_digest for both snapshots.'
		);
		$lines = preg_split( '/\R/', $block );
		self::assertIsArray( $lines );
		$sources = array();
		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^\s*(baseline|' . preg_quote( $prefix, '/' ) . ')_settings="/', $line ) ) {
				$sources[] = trim( $line );
			}
		}
		self::assertCount( 2, $sources, $gate . ' must read settings_digest from the baseline and the candidate snapshot.' );
		self::assertStringContainsString( 'settings_digest', $sources[0] );
		self::assertStringContainsString( '$counts_baseline', $sources[0] );
		self::assertStringContainsString( 'settings_digest', $sources[1] );
		self::assertStringContainsString( '$' . $candidate, $sources[1] );
	}

	/** FX-06 ignores cron drift but keeps the owned-state and settings gate. */
	public function test_fx06_compares_owned_counts_and_settings_not_the_whole_document(): void {
		$this->assertOwnedStateGate(
			$this->fx06Block(),
			'counts_after_restore',
			'restore_settings',
			self::FX06_CHURNING_COMPARE,
			'FX-06'
		);
	}

	/** FX-06 extracts the settings digest from both snapshots. */
	public function test_fx06_extracts_the_settings_digest_from_both_snapshots(): void {
		$this->assertSettingsDigestSources( $this->fx06Block(), 'counts_after_restore', 'restore', 'FX-06' );
	}

	/** FX-08 ignores cron drift but keeps the owned-state and settings gate. */
	public function test_fx08_compares_owned_counts_and_settings_not_the_whole_document(): void {
		$this->assertOwnedStateGate(
			$this->fx08Block(),
			'counts_after_kill',
			'kill_settings',
			self::FX08_CHURNING_COMPARE,
			'FX-08'
		);
	}

	/** The shared comparison ignores cron_digest and still refuses an owned-count change. */
	public function test_counts_stable_ignores_cron_drift_and_reports_owned_changes(): void {
		$baseline  = '{"users":19,"keys":31,"posts":25,"terms":14,"media":12,"settings_digest":"aaa","cron_digest":"bbb"}';
		$cron_only = '{"users":19,"keys":31,"posts":25,"terms":14,"media":12,"settings_digest":"aaa","cron_digest":"ccc"}';
		$key_moved = '{"users":19,"keys":32,"posts":25,"terms":14,"media":12,"settings_digest":"aaa","cron_digest":"bbb"}';
		self::assertSame( 0, $this->runCountsStable( $baseline, $cron_only ), 'A cron_digest-only change must stay green.' );
		self::assertSame( 1, $this->runCountsStable( $baseline, $key_moved ), 'An owned-count change must stay red.' );
	}

	/** The settings gate survives in FX-08: the digest comes from both snapshots. */
	public function test_fx08_extracts_the_settings_digest_from_both_snapshots(): void {
		$this->assertSettingsDigestSources( $this->fx08Block(), 'counts_after_kill', 'kill', 'FX-08' );
	}
}
