<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- The CI contract fixes this filename.
/**
 * Numeric coverage validation for the standalone CI pipeline.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Ci;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These are CLI diagnostics; main() encodes them as JSON rather than rendering HTML.
/**
 * Validates numeric coverage evidence without loading executable report files.
 *
 * @phpstan-type Source array{sha256: string, line_count: int}
 * @phpstan-type FileCoverage array{sha256: string, lines: array<int, int>}
 * @phpstan-type Measure array{covered: int, executable: int, percent: float|null}
 * @phpstan-type Summary array{overall: Measure, groups: array<string, Measure>}
 */
final class CoverageGate {

	private const MAX_COUNT      = 1000000000;
	private const MAX_JSON_BYTES = 33554432;
	private const SUITES         = array( 'unit', 'integration-6.9-single', 'integration-6.9-multisite', 'integration-latest-single', 'integration-latest-multisite' );
	private const PURE_AUTH      = array( 'FailureReason', 'GeneratedKey', 'HeaderExtractor', 'IssuedKey', 'KeyGenerator', 'KeyHasher', 'ParsedToken', 'Pepper', 'StoredKey', 'TokenParser' );
	private const RESULT_KEYS    = array( 'tests', 'errors', 'failures', 'warnings', 'risky', 'incomplete', 'skipped' );

	/**
	 * Expected skipped counts: 4 network-only cases that only run outside
	 * multisite, 11 lab-only RoleSeoStabilityTest cases skipped inside wp-env,
	 * plus 2 single-only or 1 multisite-only conditional case.
	 */
	private const UNIT_EXPECTED_SKIPS      = 0;
	private const SINGLE_EXPECTED_SKIPS    = 17;
	private const MULTISITE_EXPECTED_SKIPS = 12;

	/**
	 * Collect every source fingerprint and its physical line bound.
	 *
	 * @param string $root Repository root directory.
	 * @return array<string,Source> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	public static function sources( string $root ): array {
		self::assert_no_links( $root );
		$root = realpath( $root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new RuntimeException( 'The repository root is unavailable.' );
		}
		self::assert_no_links( $root . '/src' );
		if ( ! is_dir( $root . '/src' ) ) {
			throw new RuntimeException( 'The complete src directory is required.' );
		}
		$paths    = array( $root . '/uninstall.php', $root . '/xnetvn-agent-hub-wp.php' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() ) {
				throw new RuntimeException( 'Symlinks are forbidden in the source scope: ' . $entry->getPathname() );
			}
			if ( $entry->isFile() && str_ends_with( $entry->getFilename(), '.php' ) ) {
				$paths[] = $entry->getPathname();
			}
		}
		if ( 2 === count( $paths ) ) {
			throw new RuntimeException( 'The src PHP source scope is empty.' );
		}
		$sources = array();
		foreach ( $paths as $path ) {
			$content    = self::read_file( $path );
			$line_count = substr_count( $content, "\n" ) + ( str_ends_with( $content, "\n" ) ? 0 : 1 );
			$sources[ substr( $path, strlen( $root ) + 1 ) ] = array(
				'sha256'     => hash( 'sha256', $content ),
				'line_count' => $line_count,
			);
		}
		ksort( $sources );
		return self::validate_sources( $sources );
	}

	/**
	 * Export the native line map without exposing test identifiers.
	 *
	 * @param CodeCoverage        $coverage Living native coverage instance.
	 * @param string              $root Repository root directory.
	 * @param string              $suite Expected suite identifier.
	 * @param string              $revision Full tested Git revision.
	 * @param array<string,mixed> $testResult Native PHPUnit outcome counts.
	 * @return array<string,mixed> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	public static function snapshot( CodeCoverage $coverage, string $root, string $suite, string $revision, array $testResult ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public snapshot() named parameter from the approved CI interface.
		$sources        = self::sources( $root );
		$canonical_root = realpath( $root );
		if ( false === $canonical_root ) {
			throw new RuntimeException( 'The repository root disappeared.' );
		}
		self::validate_revision( $revision );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public snapshot() named parameter from the approved CI interface.
		self::validate_counts( $testResult, $suite );
		$expected = array();
		foreach ( $sources as $path => $source ) {
			$expected[] = $canonical_root . '/' . $path;
		}
		$filter = $coverage->filter()->files();
		sort( $filter );
		sort( $expected );
		if ( $filter !== $expected ) {
			throw new RuntimeException( 'The native coverage filter must include exactly all src PHP files and both entrypoints.' );
		}
		// Static analysis fills unseen files without executing their top-level code.
		$coverage->includeUncoveredFiles();
		$coverage->doNotProcessUncoveredFiles();
		$native       = $coverage->getData()->lineCoverage();
		$native_files = array_keys( $native );
		sort( $native_files );
		if ( $native_files !== $expected ) {
			throw new RuntimeException( 'The native coverage map is missing source files or includes files outside the filter.' );
		}
		$files = array();
		foreach ( $sources as $path => $source ) {
			$lines = array();
			foreach ( self::array_value( $native[ $canonical_root . '/' . $path ], 'native line map' ) as $line => $hits ) {
				self::validate_line( $line, $source['line_count'], $path );
				if ( null === $hits ) {
					continue;
				}
				if ( ! is_array( $hits ) ) {
					throw new RuntimeException( 'Invalid native hit data for ' . $path . ':' . $line );
				}
				foreach ( $hits as $test_id ) {
					if ( ! is_string( $test_id ) ) {
						throw new RuntimeException( 'Invalid native test identifier for ' . $path . ':' . $line );
					}
				}
				$lines[ $line ] = array() === $hits ? 0 : 1;
			}
			ksort( $lines );
			$files[ $path ] = array(
				'sha256' => $source['sha256'],
				'lines'  => $lines,
			);
		}
		return array(
			'format'      => 1,
			'suite'       => $suite,
			'revision'    => $revision,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the public snapshot() named parameter from the approved CI interface.
			'test_result' => $testResult,
			'files'       => $files,
		);
	}

	/**
	 * Validate five suites and compare integer thresholds and regressions.
	 *
	 * @param array<array-key,mixed> $reports Fresh numeric suite reports.
	 * @param array<array-key,mixed> $sources Complete current source manifest.
	 * @param string                 $revision Full tested Git revision.
	 * @param array<array-key,mixed> $baseline Numeric baseline from the base revision.
	 * @return array{status:string,revision:string,combined:Summary,suites:array<string,Summary>,failures:list<string>} Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	public static function evaluate( array $reports, array $sources, string $revision, array $baseline ): array {
		self::validate_revision( $revision );
		$sources           = self::validate_sources( $sources );
		$baseline_measures = self::baseline_measures( $baseline );
		if ( count( $reports ) !== count( self::SUITES ) ) {
			throw new RuntimeException( 'Exactly five coverage reports are required, one per expected suite.' );
		}
		$suites         = array();
		$combined_files = array();
		foreach ( $reports as $raw_report ) {
			$report = self::array_value( $raw_report, 'coverage report' );
			self::exact_keys( $report, array( 'format', 'suite', 'revision', 'test_result', 'files' ), 'coverage report' );
			if ( 1 !== $report['format'] || $report['revision'] !== $revision ) {
				throw new RuntimeException( 'Every report must use format 1 and the tested revision.' );
			}
			$suite = $report['suite'];
			if ( ! is_string( $suite ) || ! in_array( $suite, self::SUITES, true ) || isset( $suites[ $suite ] ) ) {
				throw new RuntimeException( 'Coverage reports contain an unknown or duplicate suite.' );
			}
			self::validate_counts( self::array_value( $report['test_result'], $suite . ' test results' ), $suite );
			$files            = self::validate_files( self::array_value( $report['files'], $suite . ' files' ), $sources );
			$suites[ $suite ] = self::summarize( $files );
			foreach ( $files as $path => $file ) {
				if ( ! isset( $combined_files[ $path ] ) ) {
					$combined_files[ $path ] = array(
						'sha256' => $file['sha256'],
						'lines'  => array(),
					);
				}
				foreach ( $file['lines'] as $line => $hit ) {
					$combined_files[ $path ]['lines'][ $line ] = max( $combined_files[ $path ]['lines'][ $line ] ?? 0, $hit );
				}
			}
		}
		ksort( $suites );
		$combined   = self::summarize( $combined_files );
		$current    = array( 'combined.overall' => $combined['overall'] );
		$thresholds = array( 'combined.overall' => 80 );
		foreach ( array( 'Domain', 'Auth', 'Policy' ) as $group ) {
			$current[ 'combined.' . $group ]    = $combined['groups'][ $group ];
			$thresholds[ 'combined.' . $group ] = 90;
		}
		foreach ( self::SUITES as $suite ) {
			$summary                           = $suites[ $suite ] ?? throw new RuntimeException( 'A required coverage suite is missing: ' . $suite );
			$current[ $suite . '.overall' ]    = $summary['overall'];
			$thresholds[ $suite . '.overall' ] = 70;
		}
		// The unit suite is not a whole-plugin filter: integration suites cover the
		// WordPress runtime. It keeps the no-regression rule below instead of an
		// absolute floor that contradicts the documented 1.0 coverage policy.
		unset( $thresholds['unit.overall'] );
		$unit = $suites['unit'];
		foreach ( array( 'Domain', 'PureAuth' ) as $group ) {
			$current[ 'unit.' . $group ]    = $unit['groups'][ $group ];
			$thresholds[ 'unit.' . $group ] = 90;
		}
		$failures = array();
		foreach ( $thresholds as $name => $threshold ) {
			$measure = $current[ $name ];
			self::require_executable( $measure, $name );
			if ( $measure['covered'] * 100 < $threshold * $measure['executable'] ) {
				$failures[] = $name . ' requires >= ' . $threshold . '%; measured ' . $measure['covered'] . '/' . $measure['executable'] . ' covered executable lines.';
			}
		}
		foreach ( $baseline_measures as $name => $previous ) {
			$measure = $current[ $name ];
			if ( $measure['covered'] * $previous['executable'] < $previous['covered'] * $measure['executable'] ) {
				$failures[] = $name . ' regressed: current ' . $measure['covered'] . '/' . $measure['executable'] . ', baseline ' . $previous['covered'] . '/' . $previous['executable'] . '. Add coverage without shrinking the source filter.';
			}
		}
		return array(
			'status'   => array() === $failures ? 'passed' : 'failed',
			'revision' => $revision,
			'combined' => $combined,
			'suites'   => $suites,
			'failures' => $failures,
		);
	}

	// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- This CLI entrypoint catches RuntimeExceptions and encodes invalid evidence instead of rethrowing.
	/**
	 * Run the CLI gate and emit a single JSON result.
	 *
	 * @param string[] $argv Script name and eight positional CLI arguments.
	 * @return int Validated numeric evidence or the declared result.
	 * @throws JsonException When the final CLI JSON cannot be encoded.
	 * @phpstan-param list<string> $argv
	 */
	public static function main( array $argv ): int {
		try {
			if ( PHP_SAPI !== 'cli' || 9 !== count( $argv ) ) {
				throw new RuntimeException( 'Usage: check-coverage.php repo_root revision baseline.json unit.json integration-6.9-single.json integration-6.9-multisite.json integration-latest-single.json integration-latest-multisite.json' );
			}
			self::validate_revision( $argv[2] );
			$sources  = self::sources( $argv[1] );
			$baseline = self::read_json( $argv[3] );
			$reports  = array();
			foreach ( array_slice( $argv, 4 ) as $path ) {
				$reports[] = self::read_json( $path );
			}
			$result = self::evaluate( $reports, $sources, $argv[2], $baseline );
			$exit   = 'passed' === $result['status'] ? 0 : 1;
		} catch ( RuntimeException | JsonException $error ) {
			$result = array(
				'status'   => 'invalid',
				'failures' => array( $error->getMessage() ),
			);
			$exit   = 2;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CI has no WordPress runtime; this operation must preserve native filesystem or numeric JSON semantics.
		echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), "\n";
		return $exit;
	}
	// phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

	/**
	 * Validate the complete source manifest and metadata.
	 *
	 * @param array<array-key,mixed> $sources Untrusted source manifest.
	 * @return array<string,Source> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function validate_sources( array $sources ): array {
		if ( ! isset( $sources['uninstall.php'], $sources['xnetvn-agent-hub-wp.php'] ) || count( $sources ) < 3 ) {
			throw new RuntimeException( 'The complete source manifest, including both entrypoints, is required.' );
		}
		$validated = array();
		foreach ( $sources as $path => $raw_source ) {
			if ( ! is_string( $path ) || ! preg_match( '~^(?:src/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.php|uninstall\.php|xnetvn-agent-hub-wp\.php)$~D', $path ) || in_array( '..', explode( '/', $path ), true ) || in_array( '.', explode( '/', $path ), true ) ) {
				throw new RuntimeException( 'Invalid path in the source manifest.' );
			}
			$source = self::array_value( $raw_source, $path . ' source metadata' );
			self::exact_keys( $source, array( 'sha256', 'line_count' ), $path . ' source metadata' );
			self::validate_sha( $source['sha256'], $path );
			self::validate_count( $source['line_count'], $path . ' line count' );
			if ( 0 === $source['line_count'] ) {
				throw new RuntimeException( 'Source line counts must be positive: ' . $path );
			}
			$validated[ $path ] = array(
				'sha256'     => $source['sha256'],
				'line_count' => $source['line_count'],
			);
		}
		ksort( $validated );
		return $validated;
	}

	/**
	 * Validate exact file membership, fingerprints and line flags.
	 *
	 * @param array<array-key,mixed> $files Untrusted report file map.
	 * @param array<string,Source>   $sources Validated current source manifest.
	 * @return array<string,FileCoverage> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function validate_files( array $files, array $sources ): array {
		self::exact_keys( $files, array_keys( $sources ), 'coverage source file set' );
		$validated = array();
		foreach ( $sources as $path => $source ) {
			$file = self::array_value( $files[ $path ], $path . ' coverage' );
			self::exact_keys( $file, array( 'sha256', 'lines' ), $path . ' coverage' );
			if ( $file['sha256'] !== $source['sha256'] ) {
				throw new RuntimeException( 'Source SHA-256 mismatch: ' . $path );
			}
			$lines = array();
			foreach ( self::array_value( $file['lines'], $path . ' lines' ) as $line => $hit ) {
				self::validate_line( $line, $source['line_count'], $path );
				if ( 0 !== $hit && 1 !== $hit ) {
					throw new RuntimeException( 'Line hit flags must be integer 0 or 1: ' . $path . ':' . $line );
				}
				$lines[ $line ] = $hit;
			}
			$validated[ $path ] = array(
				'sha256' => $source['sha256'],
				'lines'  => $lines,
			);
		}
		return $validated;
	}

	/**
	 * Require successful executed tests and the exact expected skips.
	 *
	 * @param array<array-key,mixed> $counts Untrusted test outcome counts.
	 * @param string                 $suite Expected suite identifier.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function validate_counts( array $counts, string $suite ): void {
		if ( ! in_array( $suite, self::SUITES, true ) ) {
			throw new RuntimeException( 'Unknown coverage suite.' );
		}
		self::exact_keys( $counts, self::RESULT_KEYS, $suite . ' test result counts' );
		foreach ( $counts as $name => $count ) {
			self::validate_count( $count, $suite . ' ' . $name );
			if ( ! in_array( $name, array( 'tests', 'skipped' ), true ) && 0 !== $count ) {
				throw new RuntimeException( $suite . ' contains unsuccessful tests (' . $name . ').' );
			}
		}
		if ( str_ends_with( $suite, '-single' ) ) {
			$skips = self::SINGLE_EXPECTED_SKIPS;
		} elseif ( str_ends_with( $suite, '-multisite' ) ) {
			$skips = self::MULTISITE_EXPECTED_SKIPS;
		} else {
			$skips = self::UNIT_EXPECTED_SKIPS;
		}
		if ( $counts['skipped'] !== $skips || $counts['tests'] <= $skips ) {
			throw new RuntimeException( $suite . ' requires executed tests and exactly ' . $skips . ' expected network-only and lab-only skips.' );
		}
	}

	/**
	 * Count covered and executable lines for each required group.
	 *
	 * @param array<string,FileCoverage> $files Validated coverage file maps.
	 * @return Summary Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function summarize( array $files ): array {
		$counts = array(
			'overall'  => array( 0, 0 ),
			'Domain'   => array( 0, 0 ),
			'Auth'     => array( 0, 0 ),
			'Policy'   => array( 0, 0 ),
			'PureAuth' => array( 0, 0 ),
		);
		foreach ( $files as $path => $file ) {
			$executable = count( $file['lines'] );
			$covered    = array_sum( $file['lines'] );
			$groups     = array( 'overall' );
			foreach ( array( 'Domain', 'Auth', 'Policy' ) as $group ) {
				if ( str_starts_with( $path, 'src/' . $group . '/' ) ) {
					$groups[] = $group;
				}
			}
			if ( in_array( $path, array_map( static fn ( string $class_name ): string => 'src/Auth/' . $class_name . '.php', self::PURE_AUTH ), true ) ) {
				$groups[] = 'PureAuth';
			}
			foreach ( $groups as $group ) {
				$counts[ $group ][0] += $covered;
				$counts[ $group ][1] += $executable;
				self::validate_count( $counts[ $group ][1], $group . ' executable lines' );
			}
		}
		$measures = array();
		foreach ( $counts as $group => [$covered, $executable] ) {
			$measures[ $group ] = array(
				'covered'    => $covered,
				'executable' => $executable,
				'percent'    => 0 === $executable ? null : $covered * 100.0 / $executable,
			);
		}
		$overall = $measures['overall'] ?? throw new RuntimeException( 'Overall coverage is unavailable.' );
		unset( $measures['overall'] );
		return array(
			'overall' => $overall,
			'groups'  => $measures,
		);
	}

	/**
	 * Read all eleven required numeric baseline measures.
	 *
	 * @param array<array-key,mixed> $baseline Untrusted baseline document.
	 * @return array<string,Measure> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function baseline_measures( array $baseline ): array {
		if ( 1 !== ( $baseline['format'] ?? null ) ) {
			throw new RuntimeException( 'A numeric format-1 baseline from the base revision is required.' );
		}
		$groups = self::array_value( $baseline['groups'] ?? null, 'baseline groups' );
		$suites = self::array_value( $baseline['suites'] ?? null, 'baseline suites' );
		$unit   = self::array_value( $suites['unit'] ?? null, 'baseline unit' );
		$raw    = array( 'combined.overall' => $baseline['overall'] ?? null );
		foreach ( array( 'Domain', 'Auth', 'Policy' ) as $group ) {
			$raw[ 'combined.' . $group ] = $groups[ $group ] ?? null;
		}
		foreach ( array( 'Domain', 'PureAuth' ) as $group ) {
			$raw[ 'unit.' . $group ] = $unit[ $group ] ?? null;
		}
		foreach ( self::baseline_suite_names( $suites ) as $suite => $legacy ) {
			$historical                 = self::array_value( $suites[ $legacy ], 'baseline suite ' . $legacy );
			$raw[ $suite . '.overall' ] = $historical['overall'] ?? null;
		}
		$validated = array();
		foreach ( $raw as $name => $raw_measure ) {
			$measure    = self::array_value( $raw_measure, 'baseline ' . $name );
			$covered    = $measure['covered'] ?? null;
			$executable = $measure['executable'] ?? null;
			self::validate_count( $covered, 'baseline ' . $name . ' covered lines' );
			self::validate_count( $executable, 'baseline ' . $name . ' executable lines' );
			if ( 0 === $executable || $covered > $executable ) {
				throw new RuntimeException( 'Invalid or unavailable numeric baseline: ' . $name );
			}
			$validated[ $name ] = array(
				'covered'    => $covered,
				'executable' => $executable,
				'percent'    => null,
			);
		}
		return $validated;
	}

	/**
	 * Bind native suite names to one unambiguous historical baseline roster.
	 *
	 * Latest aliases compare to the recorded nonfloor core version. They do not
	 * assert that the current WordPress core has that historical version.
	 *
	 * @param array<array-key,mixed> $suites Historical suite records.
	 * @return array<string,string> Native suite names mapped to historical names.
	 * @throws RuntimeException When the historical roster is incomplete or ambiguous.
	 */
	private static function baseline_suite_names( array $suites ): array {
		$versions = array();
		foreach ( array_keys( $suites ) as $name ) {
			if ( in_array( $name, array( 'unit', 'single-6.9', 'multisite-6.9' ), true ) ) {
				continue;
			}
			if ( ! is_string( $name ) || 1 !== preg_match( '/^(single|multisite)-([1-9][0-9]*\.(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*))?)$/D', $name, $matches ) ) {
				throw new RuntimeException( 'Unknown suite or core version in the historical baseline roster.' );
			}
			$mode    = $matches[1];
			$version = $matches[2];
			if ( '6.9.0' === $version ) {
				throw new RuntimeException( 'The historical baseline contains a duplicate alias of the 6.9 floor pair.' );
			}
			if ( version_compare( $version, '6.9', '<=' ) ) {
				throw new RuntimeException( 'The recorded nonfloor core version must exceed the supported 6.9 floor.' );
			}
			$versions[ $version ][ $mode ] = $name;
		}
		if ( 1 !== count( $versions ) ) {
			throw new RuntimeException( 'The historical baseline requires exactly one recorded nonfloor core version pair.' );
		}
		$pair = array_values( $versions )[0];
		if ( ! isset( $pair['single'], $pair['multisite'] ) ) {
			throw new RuntimeException( 'The recorded nonfloor baseline requires both single-site and multisite evidence.' );
		}
		self::exact_keys( $pair, array( 'single', 'multisite' ), 'historical baseline nonfloor mode pair' );
		$mapping = array(
			'unit'                         => 'unit',
			'integration-6.9-single'       => 'single-6.9',
			'integration-6.9-multisite'    => 'multisite-6.9',
			'integration-latest-single'    => $pair['single'],
			'integration-latest-multisite' => $pair['multisite'],
		);
		self::exact_keys( $suites, array_values( $mapping ), 'historical baseline suite roster' );
		return $mapping;
	}

	/**
	 * Distinguish missing executable evidence from zero covered lines.
	 *
	 * @param array{covered:int,executable:int,percent:float|null} $measure Current numeric measure.
	 * @param string                                               $name Coverage measure name.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function require_executable( array $measure, string $name ): void {
		if ( 0 === $measure['executable'] ) {
			throw new RuntimeException( 'Executable line evidence is unavailable: ' . $name );
		}
	}

	/**
	 * Require a bounded nonnegative integer count.
	 *
	 * @param mixed  $value Untrusted count value.
	 * @param string $label Safe diagnostic field label.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 * @phpstan-assert int $value
	 */
	private static function validate_count( $value, string $label ): void {
		if ( ! is_int( $value ) || $value < 0 || $value > self::MAX_COUNT ) {
			throw new RuntimeException( 'Expected a bounded nonnegative integer: ' . $label );
		}
	}

	/**
	 * Require a positive integer line within the physical source bound.
	 *
	 * @param int|string $line Untrusted executable line identifier.
	 * @param int        $line_count Physical source line count.
	 * @param string     $path Local source or evidence path.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 * @phpstan-assert int $line
	 */
	private static function validate_line( $line, int $line_count, string $path ): void {
		if ( ! is_int( $line ) || $line < 1 || $line > $line_count ) {
			throw new RuntimeException( 'Invalid executable line ID or source line bound: ' . $path );
		}
	}

	/**
	 * Require a lowercase SHA-256 source fingerprint.
	 *
	 * @param mixed  $sha Untrusted source fingerprint.
	 * @param string $path Local source or evidence path.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 * @phpstan-assert string $sha
	 */
	private static function validate_sha( $sha, string $path ): void {
		if ( ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) {
			throw new RuntimeException( 'Invalid source SHA-256: ' . $path );
		}
	}

	/**
	 * Require an exact full hexadecimal Git revision.
	 *
	 * @param string $revision Full tested Git revision.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function validate_revision( string $revision ): void {
		if ( ! preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision ) ) {
			throw new RuntimeException( 'An exact full hexadecimal Git revision is required.' );
		}
	}

	/**
	 * Require an array map before reading untrusted fields.
	 *
	 * @param mixed  $value Untrusted field value.
	 * @param string $label Safe diagnostic field label.
	 * @return array<array-key,mixed> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function array_value( $value, string $label ): array {
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( 'A complete map is required: ' . $label );
		}
		return $value;
	}

	/**
	 * Reject missing and unexpected map fields.
	 *
	 * @param array<array-key,mixed> $value Map whose keys must match.
	 * @param string[]               $keys Complete expected key roster.
	 * @param string                 $label Safe diagnostic field label.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 * @phpstan-param list<string> $keys
	 */
	private static function exact_keys( array $value, array $keys, string $label ): void {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $keys );
		if ( $actual !== $keys ) {
			throw new RuntimeException( 'Missing or unexpected fields: ' . $label );
		}
	}

	/**
	 * Decode bounded JSON evidence without executing report code.
	 *
	 * @param string $path Local source or evidence path.
	 * @return array<array-key,mixed> Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function read_json( string $path ): array {
		$value = json_decode( self::read_file( $path ), true, 512, JSON_THROW_ON_ERROR );
		return self::array_value( $value, 'JSON report or baseline' );
	}

	/**
	 * Read one bounded local regular file after rejecting symlinks.
	 *
	 * @param string $path Local source or evidence path.
	 * @return string Validated numeric evidence or the declared result.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function read_file( string $path ): string {
		self::assert_no_links( $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Required regular file is unavailable: ' . $path );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_JSON_BYTES ) {
			throw new RuntimeException( 'Evidence or source file exceeds the 32 MiB safety limit: ' . $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CI has no WordPress runtime; this operation must preserve native filesystem or numeric JSON semantics.
		$content = file_get_contents( $path, false, null, 0, self::MAX_JSON_BYTES + 1 );
		if ( false === $content || strlen( $content ) > self::MAX_JSON_BYTES ) {
			throw new RuntimeException( 'Cannot read complete evidence or source file: ' . $path );
		}
		return $content;
	}

	/**
	 * Reject stream paths and symlinks in every ancestor component.
	 *
	 * @param string $path Local source or evidence path.
	 * @throws RuntimeException When evidence or filesystem state is invalid or unavailable.
	 */
	private static function assert_no_links( string $path ): void {
		if ( '' === $path || str_contains( $path, "\0" ) || str_contains( $path, '://' ) ) {
			throw new RuntimeException( 'A local filesystem path is required.' );
		}
		if ( ! str_starts_with( $path, '/' ) ) {
			$directory = getcwd();
			if ( false === $directory ) {
				throw new RuntimeException( 'The working directory is unavailable.' );
			}
			$path = $directory . '/' . $path;
		}
		$prefix = '';
		foreach ( explode( '/', $path ) as $component ) {
			if ( '' === $component || '.' === $component ) {
				continue;
			}
			if ( '..' === $component ) {
				$prefix = dirname( $prefix );
				continue;
			}
			$prefix .= '/' . $component;
			if ( is_link( $prefix ) ) {
				throw new RuntimeException( 'Symlinks are forbidden in evidence and source paths: ' . $prefix );
			}
		}
	}
}
// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
