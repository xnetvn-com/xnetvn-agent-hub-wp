<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- The CI contract fixes this filename.
/**
 * Native PHPUnit numeric coverage producer for standalone CI.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Ci;

use PHPUnit\Framework\TestResult;
use PHPUnit\TextUI\DefaultResultPrinter;
use RuntimeException;

// PHPUnit 9.6 executes --prepend before loading its Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/CoverageGate.php';

/** Native PHPUnit 9.6 producer. This internal dependency must be rechecked on upgrade. */
final class CoveragePrinter extends DefaultResultPrinter {

	/**
	 * Preserve PHPUnit output and write fresh private numeric coverage.
	 *
	 * @param TestResult $result Native completed PHPUnit result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	public function printResult( TestResult $result ): void {
		parent::printResult( $result );
		$coverage = $result->getCodeCoverage();
		$suite    = getenv( 'XNETVN_CI_SUITE' );
		$revision = getenv( 'XNETVN_CI_REVISION' );
		if ( null === $coverage || false === $suite || false === $revision || '' === $suite || '' === $revision ) {
			throw new RuntimeException( 'Native coverage and XNETVN_CI_SUITE/XNETVN_CI_REVISION are required.' );
		}
		$root     = dirname( __DIR__, 2 );
		$snapshot = CoverageGate::snapshot(
			$coverage,
			$root,
			$suite,
			$revision,
			array(
				'tests'      => $result->count(),
				'errors'     => $result->errorCount(),
				'failures'   => $result->failureCount(),
				'warnings'   => $result->warningCount(),
				'risky'      => $result->riskyCount(),
				'incomplete' => $result->notImplementedCount(),
				'skipped'    => $result->skippedCount(),
			)
		);
		foreach ( array( '.local', '.local/ci' ) as $relative ) {
			$directory = $root . '/' . $relative;
			if ( is_link( $directory ) ) {
				throw new RuntimeException( 'Coverage output directories must not be symlinks.' );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The checked native return value becomes RuntimeException without leaking PHP warnings into CLI output.
			if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700 ) ) {
				throw new RuntimeException( 'Cannot create the private coverage output directory.' );
			}
			$metadata = stat( $directory );
			if ( false === $metadata || 0 !== ( $metadata['mode'] & 0022 ) ) {
				throw new RuntimeException( 'Coverage output directories must not be writable by group or other users.' );
			}
		}
		$path = $root . '/.local/ci/coverage.json';
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'Remove the old coverage artifact before starting a fresh suite.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CI has no WordPress runtime; this operation must preserve native filesystem or numeric JSON semantics.
		$json = json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
		$mask = umask( 0077 );
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The checked native return value becomes RuntimeException without leaking PHP warnings into CLI output.
			$handle = @fopen( $path, 'x+b' );
		} finally {
			umask( $mask );
		}
		if ( false === $handle ) {
			throw new RuntimeException( 'Cannot exclusively create the numeric coverage artifact.' );
		}
		try {
			$metadata = fstat( $handle );
			if ( false === $metadata || 0600 !== ( $metadata['mode'] & 0777 ) || 1 !== $metadata['nlink'] ) {
				throw new RuntimeException( 'Coverage artifacts require private 0600 permissions and one link.' );
			}
			$offset = 0;
			$length = strlen( $json );
			while ( $offset < $length ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The checked native return value becomes RuntimeException without leaking PHP warnings into CLI output.
				$written = @fwrite( $handle, substr( $json, $offset ) );
				if ( false === $written || 0 === $written ) {
					throw new RuntimeException( 'Cannot write the complete numeric coverage artifact.' );
				}
				$offset += $written;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The checked native return value becomes RuntimeException without leaking PHP warnings into CLI output.
			if ( ! @fflush( $handle ) ) {
				throw new RuntimeException( 'Cannot flush the numeric coverage artifact.' );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CI has no WordPress runtime; this operation must preserve native filesystem or numeric JSON semantics.
			fclose( $handle );
		}
	}
}
