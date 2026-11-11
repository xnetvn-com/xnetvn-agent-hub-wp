<?php
/**
 * Behavior and native API tests for the standalone CI coverage gate.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);


namespace XNetVN\AgentHubWP\Ci\Tests;

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\TestResult;

use RuntimeException;

use SebastianBergmann\CodeCoverage\CodeCoverage;

use SebastianBergmann\CodeCoverage\Driver\Selector;

use SebastianBergmann\CodeCoverage\Filter;

use XNetVN\AgentHubWP\Ci\CoverageGate;


$xnetvn_agent_hub_wp_ci_gate = dirname( __DIR__ ) . '/scripts/CoverageGate.php';

if ( is_file( $xnetvn_agent_hub_wp_ci_gate ) ) {

	require_once $xnetvn_agent_hub_wp_ci_gate;


}

/** Verifies coverage decisions, native exports and filesystem boundaries. */
final class CoverageGateTest extends TestCase {


	private const REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private const SUITES = array( 'unit', 'integration-6.9-single', 'integration-6.9-multisite', 'integration-latest-single', 'integration-latest-multisite' );

	private const PATHS = array( 'src/Domain/Decision.php', 'src/Auth/TokenParser.php', 'src/Policy/Policy.php', 'src/Support/Global.php', 'uninstall.php', 'xnetvn-agent-hub-wp.php' );

	private const COUNTS = array(
		'tests'      => 344,
		'errors'     => 0,
		'failures'   => 0,
		'warnings'   => 0,
		'risky'      => 0,
		'incomplete' => 0,
		'skipped'    => 0,
	);


	/**
	 * Temporary fixture roots owned exclusively by the current test.
	 *
	 * @var list<string>
	 */
	private array $temporary = array();


	/**
	 * Require the implementation before running behavioral assertions.
	 */
	protected function setUp(): void {
		self::assertTrue( class_exists( CoverageGate::class ), 'The coverage gate implementation is missing.' );
	}

	/**
	 * Remove only test-owned fixtures and reset fixture environment.
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary as $directory ) {
			$this->remove( $directory );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Set or reset environment only for the coverage fixture.
		putenv( 'XNETVN_CI_SUITE' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Set or reset environment only for the coverage fixture.
		putenv( 'XNETVN_CI_REVISION' );
	}

	/**
	 * Verify accepts exact 80 percent without rounding.
	 */
	public function testAcceptsExact80PercentWithoutRounding(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		foreach ( $reports as &$report ) {
			foreach ( range( 1, 40 ) as $line ) {
				$report['files']['src/Support/Global.php']['lines'][ $line ] = 0;
			}
		}
		unset( $report );
		$baseline['overall'] = array(
			'covered'    => 50,
			'executable' => 100,
			'percent'    => 50,
		);
		$result              = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'passed', $result['status'] );
		self::assertSame( 160, $result['combined']['overall']['covered'] );
		self::assertSame( 200, $result['combined']['overall']['executable'] );
		self::assertSame( array(), $result['failures'] );
	}

	/**
	 * Verify unions executable line ids and ors complementary hits.
	 */
	public function testUnionsExecutableLineIdsAndOrsComplementaryHits(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		foreach ( $reports as &$report ) {
			foreach ( range( 1, 20 ) as $line ) {
				$report['files']['src/Support/Global.php']['lines'][ $line ] = 0;
			}
		}
		unset( $report );
		foreach ( range( 1, 10 ) as $line ) {
			unset( $reports[0]['files']['src/Support/Global.php']['lines'][ $line ] );
			$reports[1]['files']['src/Support/Global.php']['lines'][ $line ] = 1;
		}
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'passed', $result['status'] );
		self::assertSame( 190, $result['combined']['overall']['covered'] );
		self::assertSame( 200, $result['combined']['overall']['executable'] );
		self::assertSame( 190, $result['suites']['unit']['overall']['executable'] );
	}

	/**
	 * Verify does not round 79.995 percent up to 80.
	 */
	public function testDoesNotRound79995PercentUpTo80(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		foreach ( $sources as $path => &$source ) {
			$source['line_count'] = 'src/Support/Global.php' === $path ? 20000 : 1;
		}
		unset( $source );
		foreach ( $reports as &$report ) {
			foreach ( $report['files'] as $path => &$file ) {
				$file['lines'] = 'src/Support/Global.php' === $path ? array_fill( 1, 20000, 0 ) : array( 1 => 1 );
			}
			unset( $file );
			foreach ( range( 1, 15998 ) as $line ) {
				$report['files']['src/Support/Global.php']['lines'][ $line ] = 1;
			}
		}
		unset( $report );
		$baseline['overall'] = array(
			'covered'    => 90,
			'executable' => 100,
			'percent'    => 100,
		);
		$result              = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 16003, $result['combined']['overall']['covered'] );
		self::assertSame( 20005, $result['combined']['overall']['executable'] );
		self::assertStringContainsString( 'combined.overall', implode( ' ', $result['failures'] ) );
		self::assertStringContainsString( '80%', implode( ' ', $result['failures'] ) );
	}

	/**
	 * Verify enforces each threshold.
	 *
	 * @param string $measure Coverage gate being exercised.
	 * @param int    $uncovered Hand-counted uncovered line total.
	 * @param string $status Expected gate status.
	 * @dataProvider thresholdCases
	 */
	public function testEnforcesEachThreshold( string $measure, int $uncovered, string $status ): void {
		[$sources, $reports, $baseline] = $this->evidence();
		if ( 'combined.overall' === $measure ) {
			$baseline['overall'] = array(
				'covered'    => 50,
				'executable' => 100,
				'percent'    => 50,
			);
		}
		$indexes = str_starts_with( $measure, 'unit.' ) ? array( 0 ) : ( 'integration.overall' === $measure ? array( 1 ) : range( 0, 4 ) );
		$path    = match ( $measure ) {
			'combined.Domain', 'unit.Domain' => self::PATHS[0],
			'combined.Auth', 'unit.PureAuth' => self::PATHS[1],
			'combined.Policy' => self::PATHS[2],
			default => self::PATHS[3],
		};
		foreach ( $indexes as $index ) {
			foreach ( range( 1, $uncovered ) as $line ) {
				$reports[ $index ]['files'][ $path ]['lines'][ $line ] = 0;
			}
		}
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( $status, $result['status'], implode( ' ', $result['failures'] ) );
		if ( 'failed' === $status ) {
			$name = 'integration.overall' === $measure ? 'integration-6.9-single.overall' : $measure;
			self::assertStringContainsString( $name . ' requires >=', implode( ' ', $result['failures'] ) );
		}
	}

	/**
	 * Provide threshold cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function thresholdCases(): iterable {
		yield 'combined exactly80' => array( 'combined.overall', 40, 'passed' );
		yield 'combined below80' => array( 'combined.overall', 41, 'failed' );
		yield 'integration exactly70' => array( 'integration.overall', 60, 'passed' );
		yield 'integration below70' => array( 'integration.overall', 61, 'failed' );
		foreach ( array( 'combined.Domain', 'combined.Auth', 'combined.Policy', 'unit.Domain', 'unit.PureAuth' ) as $measure ) {
			yield $measure . ' exactly90' => array( $measure, 2, 'passed' );
			yield $measure . ' below90' => array( $measure, 3, 'failed' );
		}
	}

	/**
	 * Verify compares numerators and denominators for every regression gate.
	 *
	 * @param string $measure Coverage gate being exercised.
	 * @dataProvider regressionCases
	 */
	public function testComparesNumeratorsAndDenominatorsForEveryRegressionGate( string $measure ): void {
		[$sources, $reports, $baseline] = $this->evidence();
		$path                           = match ( $measure ) {
			'combined.Domain', 'unit.Domain' => self::PATHS[0],
			'combined.Auth', 'unit.PureAuth' => self::PATHS[1],
			'combined.Policy' => self::PATHS[2],
			default => self::PATHS[3],
		};
		foreach ( $reports as &$report ) {
			$report['files'][ $path ]['lines'][1] = 0;
		}
		unset( $report );
		if ( 'combined.overall' === $measure ) {
			$baseline['overall'] = array(
				'covered'    => 200,
				'executable' => 200,
				'percent'    => 0,
			);
		} elseif ( str_starts_with( $measure, 'unit.' ) ) {
			$baseline['suites']['unit'][ substr( $measure, 5 ) ] = array(
				'covered'    => 20,
				'executable' => 20,
				'percent'    => 0,
			);
		} else {
			$baseline['groups'][ substr( $measure, 9 ) ] = array(
				'covered'    => 20,
				'executable' => 20,
				'percent'    => 0,
			);
		}
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( $measure . ' regressed', implode( ' ', $result['failures'] ) );
	}

	/**
	 * Provide regression cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function regressionCases(): iterable {
		foreach ( array( 'combined.overall', 'combined.Domain', 'combined.Auth', 'combined.Policy', 'unit.Domain', 'unit.PureAuth' ) as $measure ) {
			yield $measure => array( $measure );
		}
	}

	/**
	 * Reject one suite regression even when the union and every floor pass.
	 *
	 * @param int    $index Current suite index.
	 * @param string $suite Current native suite identifier.
	 * @param string $legacy Historical baseline suite identifier.
	 * @dataProvider overallRegressionCases
	 */
	public function testEverySuiteOverallHasAnIndependentRegressionGate( int $index, string $suite, string $legacy ): void {
		[$sources, $reports, $baseline]                           = $this->evidence();
		$reports[ $index ]['files'][ self::PATHS[3] ]['lines'][1] = 0;
		$baseline['suites'][ $legacy ]['overall']                 = array(
			'covered'    => 100,
			'executable' => 100,
			'percent'    => 0,
		);
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertCount( 1, $result['failures'] );
		self::assertStringContainsString( $suite . '.overall regressed', $result['failures'][0] );
		self::assertSame( 200, $result['combined']['overall']['covered'] );
		self::assertSame( 199, $result['suites'][ $suite ]['overall']['covered'] );
		$baseline['suites'][ $legacy ]['overall'] = array(
			'covered'    => 995,
			'executable' => 1000,
			'percent'    => 0,
		);
		self::assertSame( 'passed', CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline )['status'] );
		$baseline['suites'][ $legacy ]['overall'] = array(
			'covered'    => 995000001,
			'executable' => 1000000000,
			'percent'    => 0,
		);
		self::assertSame( 'failed', CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline )['status'] );
	}

	/**
	 * Provide all five suite regressions and their historical names.
	 *
	 * @return iterable<string,array> Independent hand-counted regression scenarios.
	 */
	public static function overallRegressionCases(): iterable {
		yield 'unit overall' => array( 0, 'unit', 'unit' );
		yield 'floor single overall' => array( 1, 'integration-6.9-single', 'single-6.9' );
		yield 'floor multisite overall' => array( 2, 'integration-6.9-multisite', 'multisite-6.9' );
		yield 'recorded latest single overall' => array( 3, 'integration-latest-single', 'single-7.1.2' );
		yield 'recorded latest multisite overall' => array( 4, 'integration-latest-multisite', 'multisite-7.1.2' );
	}

	/**
	 * Reject missing, ambiguous, orphaned or invalid legacy baseline evidence.
	 *
	 * @param string $scenario Invalid baseline scenario.
	 * @dataProvider invalidLegacyBaselineCases
	 */
	public function testRejectsInvalidLegacyBaselineRostersAndOverallCounts( string $scenario ): void {
		[$sources, $reports, $baseline] = $this->evidence();
		$historical                     = $baseline['suites']['single-7.1.2'];
		if ( str_starts_with( $scenario, 'missing:' ) ) {
			unset( $baseline['suites'][ substr( $scenario, 8 ) ] );
		} elseif ( 'no recorded pair' === $scenario ) {
			unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
		} elseif ( 'duplicate recorded pairs' === $scenario ) {
			$baseline['suites']['single-7.2']    = $historical;
			$baseline['suites']['multisite-7.2'] = $historical;
		} elseif ( 'orphan single' === $scenario ) {
			$baseline['suites']['single-7.2'] = $historical;
		} elseif ( 'orphan multisite' === $scenario ) {
			$baseline['suites']['multisite-7.2'] = $historical;
		} elseif ( 'mismatched versions' === $scenario ) {
			unset( $baseline['suites']['multisite-7.1.2'] );
			$baseline['suites']['multisite-7.1.3'] = $historical;
		} elseif ( 'unknown suite' === $scenario ) {
			$baseline['suites']['other-suite'] = $historical;
		} elseif ( 'native alias' === $scenario ) {
			$baseline['suites']['integration-latest-single'] = $historical;
		} elseif ( 'unknown version' === $scenario ) {
			unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
			$baseline['suites']['single-latest']    = $historical;
			$baseline['suites']['multisite-latest'] = $historical;
		} elseif ( 'duplicate floor alias' === $scenario ) {
			unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
			$baseline['suites']['single-6.9.0']    = $historical;
			$baseline['suites']['multisite-6.9.0'] = $historical;
		} elseif ( 'older than floor' === $scenario ) {
			unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
			$baseline['suites']['single-6.8.9']    = $historical;
			$baseline['suites']['multisite-6.8.9'] = $historical;
		} elseif ( 'malformed version' === $scenario ) {
			unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
			$baseline['suites']['single-07.1.2']    = $historical;
			$baseline['suites']['multisite-07.1.2'] = $historical;
		} elseif ( 'unit overall missing' === $scenario ) {
			unset( $baseline['suites']['unit']['overall'] );
		} elseif ( 'floor overall missing' === $scenario ) {
			unset( $baseline['suites']['single-6.9']['overall'] );
		} elseif ( 'latest overall missing' === $scenario ) {
			unset( $baseline['suites']['multisite-7.1.2']['overall'] );
		} elseif ( 'unit zero denominator' === $scenario ) {
			$baseline['suites']['unit']['overall']['executable'] = 0;
		} elseif ( 'floor floating count' === $scenario ) {
			$baseline['suites']['multisite-6.9']['overall']['covered'] = 70.0;
		} elseif ( 'latest impossible count' === $scenario ) {
			$baseline['suites']['single-7.1.2']['overall']['covered'] = 101;
		} elseif ( 'nonmap suite' === $scenario ) {
			$baseline['suites']['multisite-7.1.2'] = null;
		}
		$this->expectException( RuntimeException::class );
		CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
	}

	/**
	 * Provide invalid historical suite rosters and numeric outcomes.
	 *
	 * @return iterable<string,array> Invalid baseline scenarios.
	 */
	public static function invalidLegacyBaselineCases(): iterable {
		foreach ( array( 'missing:unit', 'missing:single-6.9', 'missing:multisite-6.9', 'missing:single-7.1.2', 'missing:multisite-7.1.2', 'no recorded pair', 'duplicate recorded pairs', 'orphan single', 'orphan multisite', 'mismatched versions', 'unknown suite', 'native alias', 'unknown version', 'duplicate floor alias', 'older than floor', 'malformed version', 'unit overall missing', 'floor overall missing', 'latest overall missing', 'unit zero denominator', 'floor floating count', 'latest impossible count', 'nonmap suite' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Compare latest aliases to the uniquely recorded historical core version.
	 */
	public function testLatestSuiteRegressionUsesTheRecordedHistoricalVersion(): void {
		[$sources, $reports, $baseline]        = $this->evidence();
		$baseline['suites']['single-8.0.1']    = $baseline['suites']['single-7.1.2'];
		$baseline['suites']['multisite-8.0.1'] = $baseline['suites']['multisite-7.1.2'];
		unset( $baseline['suites']['single-7.1.2'], $baseline['suites']['multisite-7.1.2'] );
		$baseline['suites']['single-8.0.1']['overall']     = array(
			'covered'    => 100,
			'executable' => 100,
		);
		$reports[3]['files'][ self::PATHS[3] ]['lines'][1] = 0;
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 1, count( $result['failures'] ) );
		self::assertStringContainsString( 'integration-latest-single.overall regressed', $result['failures'][0] );
	}

	/**
	 * Verify ignores stored baseline percent and target booleans.
	 */
	public function testIgnoresStoredBaselinePercentAndTargetBooleans(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		$baseline['overall']['percent'] = 999;
		$baseline['targets']            = array( 'everything' => false );
		self::assertSame( 'passed', CoverageGate::evaluate( array_reverse( $reports ), $sources, self::REVISION, $baseline )['status'] );
	}

	/**
	 * Verify regression equality uses different denominators and tiny loss fails.
	 */
	public function testRegressionEqualityUsesDifferentDenominatorsAndTinyLossFails(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		foreach ( $reports as &$report ) {
			foreach ( range( 1, 10 ) as $line ) {
				$report['files'][ self::PATHS[3] ]['lines'][ $line ] = 0;
			}
		}
		unset( $report );
		$baseline['overall'] = array(
			'covered'    => 95,
			'executable' => 100,
		);
		self::assertSame( 'passed', CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline )['status'] );
		$baseline['overall'] = array(
			'covered'    => 95001,
			'executable' => 100000,
		);
		$result              = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertStringContainsString( 'combined.overall regressed', implode( ' ', $result['failures'] ) );
	}

	/**
	 * Verify rejects unsafe or unavailable filesystem inputs.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider filesystemCases
	 */
	public function testRejectsUnsafeOrUnavailableFilesystemInputs( string $scenario ): void {
		$root = $this->project();
		if ( 'missing root' === $scenario ) {
			$root .= '/missing';
		} elseif ( 'empty src' === $scenario ) {
			$this->remove( $root . '/src' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			mkdir( $root . '/src' );
		} elseif ( 'nul path' === $scenario ) {
				$root .= "\0";
		} elseif ( 'stream path' === $scenario ) {
				$root = 'file://' . $root;
		}
				$this->expectException( RuntimeException::class );
				CoverageGate::sources( $root );
	}

	/**
	 * Provide filesystem cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function filesystemCases(): iterable {
		foreach ( array( 'missing root', 'empty src', 'nul path', 'stream path' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Verify reads relative root and evidence paths without changing scope.
	 */
	public function testReadsRelativeRootAndEvidencePathsWithoutChangingScope(): void {
		$root     = $this->project();
		$previous = getcwd();
		self::assertIsString( $previous );
		chdir( $root . '/src' );
		try {
			$sources = CoverageGate::sources( '../.' );
		} finally {
			chdir( $previous );
		}
			self::assertCount( 7, $sources );
			self::assertArrayHasKey( 'uninstall.php', $sources );
	}

	/**
	 * Verify rejects invalid or unavailable evidence.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider invalidCases
	 */
	public function testRejectsInvalidOrUnavailableEvidence( string $scenario ): void {
		[$sources, $reports, $baseline] = $this->evidence();
		switch ( $scenario ) {
			case 'no reports':
				$reports = array();
				break;
			case 'missing suite':
				array_pop( $reports );
				break;
			case 'duplicate suite':
				$reports[4] = $reports[0];
				break;
			case 'unknown suite':
				$reports[0]['suite'] = 'other';
				break;
			case 'wrong revision':
				$reports[0]['revision'] = str_repeat( 'b', 40 );
				break;
			case 'invalid revision':
				$reports[0]['revision'] = 'main';
				break;
			case 'wrong format':
				$reports[0]['format'] = '1';
				break;
			case 'unknown field':
				$reports[0]['secret'] = 'forbidden';
				break;
			case 'missing file':
				unset( $reports[0]['files'][ self::PATHS[0] ] );
				break;
			case 'extra file':
				$reports[0]['files']['vendor/extra.php'] = $reports[0]['files'][ self::PATHS[0] ];
				break;
			case 'wrong sha':
				$reports[0]['files'][ self::PATHS[0] ]['sha256'] = str_repeat( 'b', 64 );
				break;
			case 'malformed sha':
				$reports[0]['files'][ self::PATHS[0] ]['sha256'] = 'x';
				break;
			case 'nonarray file':
				$reports[0]['files'][ self::PATHS[0] ] = null;
				break;
			case 'unknown file field':
				$reports[0]['files'][ self::PATHS[0] ]['extra'] = 1;
				break;
			case 'nonarray lines':
				$reports[0]['files'][ self::PATHS[0] ]['lines'] = null;
				break;
			case 'zero line':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][0] = 1;
				break;
			case 'negative line':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][-1] = 1;
				break;
			case 'string line':
				$reports[0]['files'][ self::PATHS[0] ]['lines']['01'] = 1;
				break;
			case 'out of bounds':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][201] = 1;
				break;
			case 'boolean hit':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][1] = true;
				break;
			case 'negative hit':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][1] = -1;
				break;
			case 'overcount hit':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][1] = 2;
				break;
			case 'string hit':
				$reports[0]['files'][ self::PATHS[0] ]['lines'][1] = '1';
				break;
			case 'missing counts':
				unset( $reports[0]['test_result']['warnings'] );
				break;
			case 'extra counts':
				$reports[0]['test_result']['extra'] = 0;
				break;
			case 'no tests':
				$reports[0]['test_result']['tests'] = 0;
				break;
			case 'negative tests':
				$reports[0]['test_result']['tests'] = -1;
				break;
			case 'string tests':
				$reports[0]['test_result']['tests'] = '12';
				break;
			case 'float tests':
				$reports[0]['test_result']['tests'] = 12.0;
				break;
			case 'huge tests':
				$reports[0]['test_result']['tests'] = PHP_INT_MAX;
				break;
			case 'wrong skips':
				$reports[1]['test_result']['skipped'] = 0;
				break;
			case 'all skipped':
				$reports[1]['test_result']['tests'] = 4;
				break;
			case 'unexpected unit skip':
				$reports[0]['test_result']['skipped'] = 1;
				break;
			case 'unexpected single skip':
				$reports[1]['test_result']['skipped'] = 18;
				break;
			case 'unexpected multisite skip':
				$reports[2]['test_result']['skipped'] = 4;
				break;
			case 'empty sources':
				$sources = array();
				break;
			case 'source traversal':
				$sources['src/../escape.php'] = $sources[ self::PATHS[0] ];
				break;
			case 'source outside filter':
				$sources['tests/escape.php'] = $sources[ self::PATHS[0] ];
				break;
			case 'missing entrypoint':
				unset( $sources['uninstall.php'] );
				break;
			case 'missing source sha':
				unset( $sources[ self::PATHS[0] ]['sha256'] );
				break;
			case 'invalid source count':
				$sources[ self::PATHS[0] ]['line_count'] = 0;
				break;
			case 'extra source field':
				$sources[ self::PATHS[0] ]['lines'] = array();
				break;
			case 'empty baseline':
				$baseline = array();
				break;
			case 'wrong baseline format':
				$baseline['format'] = 2;
				break;
			case 'missing baseline group':
				unset( $baseline['groups']['Auth'] );
				break;
			case 'missing baseline unit':
				unset( $baseline['suites']['unit']['PureAuth'] );
				break;
			case 'zero baseline denominator':
				$baseline['overall']['executable'] = 0;
				break;
			case 'noninteger baseline numerator':
				$baseline['overall']['covered'] = 1.0;
				break;
			case 'baseline covered too large':
				$baseline['overall']['covered'] = 101;
				break;
			case 'huge baseline denominator':
				$baseline['overall']['executable'] = PHP_INT_MAX;
				break;
			case 'no executable evidence':
				foreach ( $reports as &$report ) {
					foreach ( $report['files'] as &$file ) {
						$file['lines'] = array();
					}
					unset( $file );
				}
				unset( $report );
				break;
			case 'missing pure auth executable':
				foreach ( $reports as &$report ) {
					$report['files'][ self::PATHS[1] ]['lines'] = array();
				}
				unset( $report );
				break;
			default:
				$reports[0]['test_result'][ $scenario ] = 1;
		}
		$this->expectException( RuntimeException::class );
		CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
	}

	/**
	 * Provide invalid cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function invalidCases(): iterable {
		foreach ( array( 'no reports', 'missing suite', 'duplicate suite', 'unknown suite', 'wrong revision', 'invalid revision', 'wrong format', 'unknown field', 'missing file', 'extra file', 'wrong sha', 'malformed sha', 'nonarray file', 'unknown file field', 'nonarray lines', 'zero line', 'negative line', 'string line', 'out of bounds', 'boolean hit', 'negative hit', 'overcount hit', 'string hit', 'missing counts', 'extra counts', 'no tests', 'negative tests', 'string tests', 'float tests', 'huge tests', 'wrong skips', 'all skipped', 'unexpected unit skip', 'unexpected single skip', 'unexpected multisite skip', 'errors', 'failures', 'warnings', 'risky', 'incomplete', 'empty sources', 'source traversal', 'source outside filter', 'missing entrypoint', 'missing source sha', 'invalid source count', 'extra source field', 'empty baseline', 'wrong baseline format', 'missing baseline group', 'missing baseline unit', 'zero baseline denominator', 'noninteger baseline numerator', 'baseline covered too large', 'huge baseline denominator', 'no executable evidence', 'missing pure auth executable' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Verify zero covered evidence is numeric and fails thresholds.
	 */
	public function testZeroCoveredEvidenceIsNumericAndFailsThresholds(): void {
		[$sources, $reports, $baseline] = $this->evidence();
		foreach ( $reports as &$report ) {
			foreach ( $report['files'] as &$file ) {
				$file['lines'] = array_fill_keys( array_keys( $file['lines'] ), 0 );
			}
			unset( $file );
		}
		unset( $report );
		$result = CoverageGate::evaluate( $reports, $sources, self::REVISION, $baseline );
		self::assertSame( 'failed', $result['status'] );
		self::assertSame( 0, $result['combined']['overall']['covered'] );
		self::assertSame( 200, $result['combined']['overall']['executable'] );
		self::assertSame( 0.0, $result['combined']['overall']['percent'] );
	}

	/**
	 * Verify sources scans every php file and both entrypoints.
	 */
	public function testSourcesScansEveryPhpFileAndBothEntrypoints(): void {
		$root    = $this->project();
		$sources = CoverageGate::sources( $root );
		self::assertCount( 7, $sources );
		self::assertArrayHasKey( 'src/Contracts/EmptyContract.php', $sources );
		self::assertSame( hash_file( 'sha256', $root . '/uninstall.php' ), $sources['uninstall.php']['sha256'] );
		self::assertSame( 201, $sources['uninstall.php']['line_count'] );
	}

	/**
	 * Verify sources rejects missing or symlinked scope.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider unsafeSourceCases
	 */
	public function testSourcesRejectsMissingOrSymlinkedScope( string $scenario ): void {
		$root = $this->project();
		if ( 'missing entrypoint' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			unlink( $root . '/uninstall.php' );
		} elseif ( 'missing src' === $scenario ) {
			$this->remove( $root . '/src' );
		} elseif ( 'file link' === $scenario ) {
				symlink( $root . '/uninstall.php', $root . '/src/linked.php' );
		} elseif ( 'directory link' === $scenario ) {
				symlink( $root . '/src/Domain', $root . '/src/linked' );
		} elseif ( 'entrypoint link' === $scenario ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					unlink( $root . '/uninstall.php' );
					symlink( $root . '/xnetvn-agent-hub-wp.php', $root . '/uninstall.php' );
		} elseif ( 'root link' === $scenario ) {
					symlink( $root, $root . '-link' );
					$this->temporary[] = $root . '-link';
					$root             .= '-link';
		} elseif ( 'parent link' === $scenario ) {
						symlink( $root . '/src', $root . '/linked' );
						$root .= '/linked/..';
		}
					$this->expectException( RuntimeException::class );
					CoverageGate::sources( $root );
	}

	/**
	 * Provide unsafe source cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function unsafeSourceCases(): iterable {
		foreach ( array( 'missing entrypoint', 'missing src', 'file link', 'directory link', 'entrypoint link', 'root link', 'parent link' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Verify native snapshot preserves global uncovered lines and zero line files.
	 */
	public function testNativeSnapshotPreservesGlobalUncoveredLinesAndZeroLineFiles(): void {
		$root     = $this->project();
		$coverage = $this->native( $root );
		$data     = array();
		foreach ( CoverageGate::sources( $root ) as $path => $source ) {
			$data[ $root . '/' . $path ] = 'src/Contracts/EmptyContract.php' === $path ? array() : array(
				2 => array( 'test secret arguments must not leave process' ),
				3 => array(),
				4 => null,
			);
		}
		$coverage->getData( true )->setLineCoverage( $data );
		$snapshot = CoverageGate::snapshot( $coverage, $root, 'unit', self::REVISION, self::COUNTS );
		self::assertSame(
			array(
				2 => 1,
				3 => 0,
			),
			$snapshot['files']['src/Support/Global.php']['lines']
		);
		self::assertSame( array(), $snapshot['files']['src/Contracts/EmptyContract.php']['lines'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		self::assertStringNotContainsString( 'secret arguments', json_encode( $snapshot, JSON_THROW_ON_ERROR ) );
		self::assertSame( self::COUNTS, $snapshot['test_result'] );
	}

	/**
	 * Verify native snapshot adds unseen files without executing their globals.
	 */
	public function testNativeSnapshotAddsUnseenFilesWithoutExecutingTheirGlobals(): void {
		$root = $this->project();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/src/Support/Global.php', "<?php\nthrow new \\RuntimeException('must not execute');\n" );
		$coverage = $this->native( $root );
		$coverage->processUncoveredFiles();
		$snapshot = CoverageGate::snapshot( $coverage, $root, 'unit', self::REVISION, self::COUNTS );
		self::assertSame( array( 2 => 0 ), $snapshot['files']['src/Support/Global.php']['lines'] );
		self::assertSame( array(), $snapshot['files']['src/Contracts/EmptyContract.php']['lines'] );
	}

	/**
	 * Verify native snapshot rejects incomplete filter and invalid data.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider invalidNativeCases
	 */
	public function testNativeSnapshotRejectsIncompleteFilterAndInvalidData( string $scenario ): void {
		$root     = $this->project();
		$coverage = $this->native( $root );
		if ( 'subset filter' === $scenario ) {
			$coverage->filter()->excludeFile( $root . '/uninstall.php' );
		} elseif ( 'extra filter' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			file_put_contents( $root . '/extra.php', '<?php' );
			$coverage->filter()->includeFile( $root . '/extra.php' );
		} elseif ( 'bad native hits' === $scenario ) {
				$coverage->getData( true )->setLineCoverage( array( $root . '/uninstall.php' => array( 2 => 1 ) ) );
		} elseif ( 'bad native line' === $scenario ) {
				$coverage->getData( true )->setLineCoverage( array( $root . '/uninstall.php' => array( 0 => array() ) ) );
		} elseif ( 'unknown native file' === $scenario ) {
					$coverage->getData( true )->setLineCoverage( array( $root . '/extra.php' => array( 2 => array() ) ) );
		}
				$this->expectException( RuntimeException::class );
				CoverageGate::snapshot( $coverage, $root, 'unit', self::REVISION, self::COUNTS );
	}

	/**
	 * Provide invalid native cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function invalidNativeCases(): iterable {
		foreach ( array( 'subset filter', 'extra filter', 'bad native hits', 'bad native line', 'unknown native file' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Verify cli produces json with pass fail or invalid exit codes.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @param int    $expected Expected CLI exit status.
	 * @dataProvider cliCases
	 */
	public function testCliProducesJsonWithPassFailOrInvalidExitCodes( string $scenario, int $expected ): void {
		$root                           = $this->project();
		[$sources, $reports, $baseline] = $this->evidence();
		$sources                        = CoverageGate::sources( $root );
		foreach ( $reports as &$report ) {
			foreach ( $sources as $path => $source ) {
				$report['files'][ $path ] = array(
					'sha256' => $source['sha256'],
					'lines'  => 'src/Contracts/EmptyContract.php' === $path ? array() : array( 2 => 1 ),
				);
			}
		}
		unset( $report );
		if ( 'threshold' === $scenario ) {
			foreach ( $reports as &$report ) {
				$report['files'][ self::PATHS[3] ]['lines'][2] = 0;
			} unset( $report );
		}
		if ( 'ambiguous baseline roster' === $scenario ) {
			$baseline['suites']['single-7.2']    = $baseline['suites']['single-7.1.2'];
			$baseline['suites']['multisite-7.2'] = $baseline['suites']['multisite-7.1.2'];
		} elseif ( 'missing baseline overall' === $scenario ) {
			unset( $baseline['suites']['unit']['overall'] );
		}
		$baseline_path = $root . '/baseline.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $baseline_path, json_encode( $baseline, JSON_THROW_ON_ERROR ) );
		$argv = array( 'check-coverage.php', $root, self::REVISION, $baseline_path );
		foreach ( $reports as $index => $report ) {
			$path = $root . '/report-' . $index . '.json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			file_put_contents( $path, json_encode( $report, JSON_THROW_ON_ERROR ) );
			$argv[] = $path;
		}
		if ( 'malformed json' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			file_put_contents( $argv[4], '{bad' );
		} elseif ( 'json scalar' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			file_put_contents( $argv[4], 'true' );
		} elseif ( 'missing report' === $scenario ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				unlink( $argv[4] );
		} elseif ( 'report symlink' === $scenario ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				unlink( $argv[4] );
				symlink( $argv[5], $argv[4] );
		} elseif ( 'baseline symlink' === $scenario ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					rename( $baseline_path, $root . '/saved.json' );
					symlink( $root . '/saved.json', $baseline_path );
		} elseif ( 'report parent symlink' === $scenario ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					mkdir( $root . '/real' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					rename( $argv[4], $root . '/real/report.json' );
					symlink( $root . '/real', $root . '/linked' );
					$argv[4] = $root . '/linked/report.json';
		} elseif ( 'wrong arguments' === $scenario ) {
						array_pop( $argv );
		} elseif ( 'unsafe revision' === $scenario ) {
						$argv[2] = 'HEAD';
		} elseif ( 'php payload' === $scenario ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
							file_put_contents( $argv[4], '<?php throw new RuntimeException("executed");' );
		} elseif ( 'oversize file' === $scenario ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
							$handle = fopen( $argv[4], 'w' );
							ftruncate( $handle, 33554433 );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
							fclose( $handle );
		}
							ob_start();
		try {
			$exit = CoverageGate::main( $argv );
			$json = ob_get_contents();
		} finally {
								ob_end_clean();
		}
								$result = json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
								self::assertSame( $expected, $exit, (string) $json );
								self::assertSame( 0 === $expected ? 'passed' : ( 1 === $expected ? 'failed' : 'invalid' ), $result['status'] );
	}

	/**
	 * Provide cli cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function cliCases(): iterable {
		yield 'pass' => array( 'pass', 0 );
		yield 'threshold' => array( 'threshold', 1 );
		foreach ( array( 'malformed json', 'json scalar', 'missing report', 'report symlink', 'baseline symlink', 'report parent symlink', 'wrong arguments', 'unsafe revision', 'php payload', 'oversize file', 'ambiguous baseline roster', 'missing baseline overall' ) as $scenario ) {
			yield $scenario => array( $scenario, 2 );
		}
	}

	/**
	 * Verify entrypoint executes the gate and returns invalid evidence as json.
	 */
	public function testEntrypointExecutesTheGateAndReturnsInvalidEvidenceAsJson(): void {
		$command                  = array( PHP_BINARY, dirname( __DIR__ ) . '/scripts/check-coverage.php' );
		[$exit, $stdout, $stderr] = $this->process( $command, dirname( __DIR__, 2 ) );
		self::assertSame( 2, $exit, $stderr );
		self::assertSame( 'invalid', json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR )['status'] );
		self::assertSame( '', $stderr );
	}

	/**
	 * Verify printer preserves phpunit output and writes only safe fresh private json.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider printerCases
	 */
	public function testPrinterPreservesPhpunitOutputAndWritesOnlySafeFreshPrivateJson( string $scenario ): void {
		$root = $this->project();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		mkdir( $root . '/.github/scripts', 0700, true );
		foreach ( array( 'CoverageGate.php', 'CoveragePrinter.php' ) as $name ) {
			copy( dirname( __DIR__ ) . '/scripts/' . $name, $root . '/.github/scripts/' . $name );
		}
		if ( 'directory symlink' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			mkdir( $root . '/outside' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			mkdir( $root . '/.local' );
			symlink( $root . '/outside', $root . '/.local/ci' );
		} elseif ( 'local symlink' === $scenario ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			mkdir( $root . '/outside' );
			symlink( $root . '/outside', $root . '/.local' );
		} elseif ( 'file symlink' === $scenario || 'stale file' === $scenario ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
						mkdir( $root . '/.local/ci', 0700, true );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
						file_put_contents( $root . '/untouched', 'sentinel' );
			if ( 'file symlink' === $scenario ) {
				symlink( $root . '/untouched', $root . '/.local/ci/coverage.json' );
			} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					file_put_contents( $root . '/.local/ci/coverage.json', 'old artifact' );
			}
		}
			$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote controlled fixture PHP literals; this value is not logged.
			$code = 'require ' . var_export( $autoload, true ) . '; require ' . var_export( $root . '/.github/scripts/CoveragePrinter.php', true ) . ';'
			. '$filter = new \\SebastianBergmann\\CodeCoverage\\Filter();'
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote controlled fixture PHP literals; this value is not logged.
			. 'foreach (\\XNetVN\\AgentHubWP\\Ci\\CoverageGate::sources(' . var_export( $root, true ) . ') as $path => $source) {$filter->includeFile(' . var_export( $root . '/', true ) . '.$path);}'
			. '$coverage = new \\SebastianBergmann\\CodeCoverage\\CodeCoverage((new \\SebastianBergmann\\CodeCoverage\\Driver\\Selector())->forLineCoverage($filter),$filter);'
			. '$result = new \\PHPUnit\\Framework\\TestResult();'
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote controlled fixture PHP literals; this value is not logged.
			. 'if (' . var_export( 'missing coverage' !== $scenario, true ) . ') {$result->setCodeCoverage($coverage);}'
			. '$test = new class("testProbe") extends \\PHPUnit\\Framework\\TestCase {public function testProbe(): void {}};'
			. '$result->startTest($test); $result->endTest($test,0.0);'
			. '(new \\XNetVN\\AgentHubWP\\Ci\\CoveragePrinter())->printResult($result);';
			$suite                    = 'missing environment' === $scenario ? '' : 'unit';
			[$exit, $stdout, $stderr] = $this->process(
				array( PHP_BINARY, '-r', $code ),
				$root,
				array(
					'XDEBUG_MODE'        => 'coverage',
					'XNETVN_CI_SUITE'    => $suite,
					'XNETVN_CI_REVISION' => self::REVISION,
				)
			);
		if ( 'valid' !== $scenario ) {
			self::assertNotSame( 0, $exit, $stdout . $stderr );
			if ( is_file( $root . '/untouched' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				self::assertSame( 'sentinel', file_get_contents( $root . '/untouched' ) );
			}
			return;
		}
		self::assertSame( 0, $exit, $stderr );
		self::assertStringContainsString( 'OK (1 test', $stdout );
		$path = $root . '/.local/ci/coverage.json';
		self::assertSame( 0600, fileperms( $path ) & 0777 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		$snapshot = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'unit', $snapshot['suite'] );
		self::assertSame( 1, $snapshot['test_result']['tests'] );
		self::assertCount( 7, $snapshot['files'] );
	}

	/**
	 * Provide printer cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function printerCases(): iterable {
		foreach ( array( 'valid', 'directory symlink', 'local symlink', 'file symlink', 'stale file', 'missing coverage', 'missing environment' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Verify installed phpunit loads printer through prepend and class option.
	 */
	public function testInstalledPhpunitLoadsPrinterThroughPrependAndClassOption(): void {
		$root = $this->project();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		mkdir( $root . '/.github/scripts', 0700, true );
		foreach ( array( 'CoverageGate.php', 'CoveragePrinter.php' ) as $name ) {
			copy( dirname( __DIR__ ) . '/scripts/' . $name, $root . '/.github/scripts/' . $name );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/ProbeTest.php', '<?php final class ProbeTest extends \\PHPUnit\\Framework\\TestCase {public function testProbe(): void {self::assertTrue(true);}}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/phpunit.xml', '<phpunit><coverage><include><directory suffix=".php">src</directory><file>uninstall.php</file><file>xnetvn-agent-hub-wp.php</file></include></coverage></phpunit>' );
		[$exit, $stdout, $stderr] = $this->process(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/vendor/bin/phpunit', '--prepend', $root . '/.github/scripts/CoveragePrinter.php', '--configuration', $root . '/phpunit.xml', '--do-not-cache-result', '--printer', 'XNetVN\\AgentHubWP\\Ci\\CoveragePrinter', '--coverage-text=' . $root . '/coverage.txt', $root . '/ProbeTest.php' ),
			$root,
			array(
				'XDEBUG_MODE'        => 'coverage',
				'XNETVN_CI_SUITE'    => 'unit',
				'XNETVN_CI_REVISION' => self::REVISION,
			)
		);
		self::assertSame( 0, $exit, $stdout . $stderr );
		self::assertStringContainsString( 'OK (1 test, 1 assertion)', $stdout );
		self::assertFileExists( $root . '/.local/ci/coverage.json' );
	}

	/**
	 * Verify original printer handles native results and filesystem failures.
	 *
	 * @param string $scenario Named invalid or filesystem scenario.
	 * @dataProvider originalPrinterCases
	 */
	public function testOriginalPrinterHandlesNativeResultsAndFilesystemFailures( string $scenario ): void {
		require_once dirname( __DIR__ ) . '/scripts/CoveragePrinter.php';
		$root      = dirname( __DIR__, 2 );
		$directory = $root . '/.local/ci';
		$path      = $directory . '/coverage.json';
		self::assertFalse( file_exists( $directory ) || is_link( $directory ), 'Refusing to touch a preexisting collector directory.' );
		$created_directory = false;
		$created_file      = false;
		$created_link      = false;
		$native            = $this->native( $root );
		$result            = new TestResult();
		if ( 'missing coverage' !== $scenario ) {
			$result->setCodeCoverage( $native );
		}
		$probe = new class('testProbe') extends TestCase {
			/**
			 * Provide a native PHPUnit result probe.
			 */
			public function testProbe(): void {
			}
		};
		$result->startTest( $probe );
		$result->endTest( $probe, 0.0 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Set or reset environment only for the coverage fixture.
		putenv( 'XNETVN_CI_SUITE=unit' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Set or reset environment only for the coverage fixture.
		putenv( 'XNETVN_CI_REVISION=' . self::REVISION );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		$output = fopen( 'php://memory', 'w+' );
		self::assertIsResource( $output );
		$printer = new \XNetVN\AgentHubWP\Ci\CoveragePrinter( $output );
		try {
			if ( 'stale artifact' === $scenario || 'unwritable directory' === $scenario || 'insecure directory' === $scenario ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				mkdir( $directory, 0700 );
				$created_directory = true;
				if ( 'stale artifact' === $scenario ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					file_put_contents( $path, 'owned sentinel' );
					$created_file = true;
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					chmod( $directory, 'insecure directory' === $scenario ? 0777 : 0500 );
				}
			} elseif ( 'directory link' === $scenario ) {
				symlink( $root . '/.local/reports', $directory );
				$created_link = true;
			} elseif ( 'blocked directory' === $scenario ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				file_put_contents( $directory, 'owned directory blocker' );
				$created_file = true;
			}
			if ( 'valid' !== $scenario ) {
				$this->expectException( RuntimeException::class );
			}
			$printer->printResult( $result );
			$created_directory = true;
			$created_file      = true;
			self::assertSame( 0600, fileperms( $path ) & 0777 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			$snapshot = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
			self::assertSame( 1, $snapshot['test_result']['tests'] );
			self::assertSame( count( CoverageGate::sources( $root ) ), count( $snapshot['files'] ) );
			self::assertArrayHasKey( 'src/Contracts/AbilityCatalog.php', $snapshot['files'] );
			self::assertSame( array(), $snapshot['files']['src/Contracts/AbilityCatalog.php']['lines'] );
			rewind( $output );
			self::assertStringContainsString( 'OK (1 test', (string) stream_get_contents( $output ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			fclose( $output );
			if ( $created_link ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				unlink( $directory );
			} elseif ( 'blocked directory' === $scenario && $created_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				unlink( $directory );
			} else {
				if ( $created_directory ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					chmod( $directory, 0700 );
				}
				if ( $created_file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					unlink( $path );
				}
				if ( $created_directory ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
					rmdir( $directory );
				}
			}
		}
	}

	/**
	 * Provide original printer cases.
	 *
	 * @return iterable<string,array> Validated numeric evidence or the declared result.
	 */
	public static function originalPrinterCases(): iterable {
		foreach ( array( 'valid', 'stale artifact', 'directory link', 'blocked directory', 'unwritable directory', 'insecure directory', 'missing coverage' ) as $scenario ) {
			yield $scenario => array( $scenario );
		}
	}

	/**
	 * Build hand-counted source maps and five successful suite reports.
	 *
	 * @return array{array<string,array{sha256:string,line_count:int}>,list<array<string,mixed>>,array<string,mixed>} Validated numeric evidence or the declared result.
	 */
	private function evidence(): array {
		$sources = array();
		$files   = array();
		foreach ( self::PATHS as $path ) {
			$sources[ $path ] = array(
				'sha256'     => str_repeat( 'a', 64 ),
				'line_count' => 200,
			);
			$count            = str_contains( $path, '/Support/' ) ? 120 : ( str_starts_with( $path, 'src/' ) ? 20 : 10 );
			$files[ $path ]   = array(
				'sha256' => str_repeat( 'a', 64 ),
				'lines'  => array_fill( 1, $count, 1 ),
			);
		}
		$reports = array();
		foreach ( self::SUITES as $suite ) {
			$counts = self::COUNTS;
			if ( str_ends_with( $suite, '-single' ) ) {
				$counts['skipped'] = 17;
			} elseif ( str_ends_with( $suite, '-multisite' ) ) {
				$counts['skipped'] = 12;
			} else {
				$counts['skipped'] = 0;
			}
			$reports[] = array(
				'format'      => 1,
				'suite'       => $suite,
				'revision'    => self::REVISION,
				'test_result' => $counts,
				'files'       => $files,
			);
		}
		$metric = array(
			'covered'    => 90,
			'executable' => 100,
			'percent'    => 90,
		);
		return array(
			$sources,
			$reports,
			array(
				'format'  => 1,
				'overall' => $metric,
				'groups'  => array(
					'Domain' => $metric,
					'Auth'   => $metric,
					'Policy' => $metric,
				),
				'suites'  => array(
					'unit'            => array(
						'Domain'   => $metric,
						'PureAuth' => $metric,
						'overall'  => array(
							'covered'    => 80,
							'executable' => 100,
						),
					),
					'single-6.9'      => array(
						'overall' => array(
							'covered'    => 70,
							'executable' => 100,
						),
					),
					'multisite-6.9'   => array(
						'overall' => array(
							'covered'    => 70,
							'executable' => 100,
						),
					),
					'single-7.1.2'    => array(
						'overall' => array(
							'covered'    => 70,
							'executable' => 100,
						),
					),
					'multisite-7.1.2' => array(
						'overall' => array(
							'covered'    => 70,
							'executable' => 100,
						),
					),
				),
			),
		);
	}

	/**
	 * Create a private temporary repository with representative source files.
	 *
	 * @return string Validated numeric evidence or the declared result.
	 */
	private function project(): string {
		$root = sys_get_temp_dir() . '/xnetvn-ci-coverage-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		mkdir( $root, 0700 );
		$this->temporary[] = $root;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		mkdir( $root . '/vendor' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/vendor/autoload.php', '<?php require_once ' . var_export( dirname( __DIR__, 2 ) . '/vendor/autoload.php', true ) . ';' );
		foreach ( self::PATHS as $path ) {
			if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
				mkdir( dirname( $root . '/' . $path ), 0700, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			file_put_contents( $root . '/' . $path, "<?php\n" . str_repeat( "// Fixture source.\n", 199 ) . "return 1;\n" );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		mkdir( $root . '/src/Contracts' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/src/Contracts/EmptyContract.php', "<?php\ninterface EmptyContract {}\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		file_put_contents( $root . '/src/ignored.txt', 'not PHP' );
		return $root;
	}

	/**
	 * Create a real native coverage instance and restore outer instrumentation.
	 *
	 * @param string $root Repository root directory.
	 * @return CodeCoverage Validated numeric evidence or the declared result.
	 */
	private function native( string $root ): CodeCoverage {
		$filter = new Filter();
		foreach ( CoverageGate::sources( $root ) as $path => $source ) {
			$filter->includeFile( $root . '/' . $path );
		}
		$coverage = new CodeCoverage( ( new Selector() )->forLineCoverage( $filter ), $filter );
		// The native driver's constructor changes Xdebug's process-wide filter.
		// Restore the outer helper run's instrumentation; this fixture never starts the driver.
		xdebug_set_filter( XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, array( dirname( __DIR__, 2 ) . '/.github/scripts/' ) );
		return $coverage;
	}

	/**
	 * Run a trusted PHP command without a shell and capture its result.
	 *
	 * @param string[]             $command Trusted executable and argument vector.
	 * @param string               $root Repository root directory.
	 * @param array<string,string> $environment Explicit child process environment.
	 * @return array{int,string,string} Validated numeric evidence or the declared result.
	 */
	private function process( array $command, string $root, array $environment = array() ): array {
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run trusted PHP fixtures through an argument vector without a shell.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root,
			array_merge( (array) getenv(), $environment )
		);
		self::assertIsResource( $process );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		fclose( $pipes[2] );
		return array( proc_close( $process ), (string) $stdout, (string) $stderr );
	}

	/**
	 * Remove only a fixture path owned by the current test.
	 *
	 * @param string $path Local source or evidence path.
	 */
	private function remove( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
			unlink( $path );
			return;
		}
		foreach ( new \FilesystemIterator( $path ) as $entry ) {
			$this->remove( $entry->getPathname() );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Standalone CI fixtures have no WordPress runtime and verify native filesystem or JSON behavior.
		rmdir( $path );
	}
}
