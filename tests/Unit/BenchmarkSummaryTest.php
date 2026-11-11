<?php
/**
 * Percentile and invalid-sample checks for public benchmark evidence.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Tests\Support\BenchmarkSummary;

/** Prevents an average or missing samples from being reported as latency percentiles. */
final class BenchmarkSummaryTest extends TestCase {

	/** Percentiles use nearest rank on the sorted complete sample set. */
	public function test_nearest_rank_percentiles(): void {
		$result = BenchmarkSummary::summarize( array_reverse( range( 1, 100 ) ) );
		$this->assertSame( 100, $result['samples'] );
		$this->assertSame( 50.0, $result['p50'] );
		$this->assertSame( 95.0, $result['p95'] );
		$this->assertSame( 99.0, $result['p99'] );
		$this->assertSame( 100.0, $result['max'] );
	}

	/** Missing, negative and nonfinite measurements cannot become zero. */
	public function test_invalid_samples_are_rejected(): void {
		foreach ( array( array(), array( -1.0 ), array( NAN ), array( INF ) ) as $samples ) {
			try {
				BenchmarkSummary::summarize( $samples );
				$this->fail( 'Invalid benchmark evidence was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				$this->assertSame( 'Invalid benchmark samples.', $exception->getMessage() );
			}
		}
	}
}
