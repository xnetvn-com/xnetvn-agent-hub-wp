<?php
/**
 * Deterministic complete-sample latency statistics.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Support;

/** Test utility; never loaded by the distributed plugin. */
final class BenchmarkSummary {
	/**
	 * Summarize nonnegative finite measurements using nearest-rank percentiles.
	 *
	 * @param array<int|float> $samples Measurements in one declared unit.
	 * @return array{samples:int,p50:float,p95:float,p99:float,max:float}
	 * @throws \InvalidArgumentException For unavailable or invalid measurements.
	 */
	public static function summarize( array $samples ): array {
		if ( ! $samples ) {
			throw new \InvalidArgumentException( 'Invalid benchmark samples.' );
		}
		foreach ( $samples as $sample ) {
			if ( ! is_numeric( $sample ) || ! is_finite( (float) $sample ) || $sample < 0 ) {
				throw new \InvalidArgumentException( 'Invalid benchmark samples.' );
			}
		}
		sort( $samples, SORT_NUMERIC );
		$count  = count( $samples );
		$result = array( 'samples' => $count );
		foreach ( array(
			'p50' => 0.5,
			'p95' => 0.95,
			'p99' => 0.99,
			'max' => 1.0,
		) as $name => $percentile ) {
			$result[ $name ] = (float) $samples[ (int) ceil( $count * $percentile ) - 1 ];
		}
		return $result;
	}
}
