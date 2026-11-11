<?php
/**
 * Trusted mutation receipt retained through final output validation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities;

use Closure;

/** Internal envelope only; OutputPipeline removes it before the adapter sees data. */
final readonly class MutationResult {
	/**
	 * Bind projected data to a core-observed mutation.
	 *
	 * @param array<string,mixed> $data Logical output.
	 * @param int                 $object_id Independently observed ID.
	 * @param bool                $created Whether this request created the object.
	 * @throws \InvalidArgumentException For an invalid receipt.
	 */
	public function __construct( public array $data, public int $object_id, public bool $created ) {
		if ( $object_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid mutation receipt.' ); }
	}
	/**
	 * Keep reconciliation identity across projection or readback failures.
	 *
	 * @param int     $object_id Observed object.
	 * @param bool    $created Creation flag.
	 * @param Closure $project Projection or readback.
	 * @return self|PartialFailure
	 */
	public static function project( int $object_id, bool $created, Closure $project ): self|PartialFailure {
		try {
			$data = $project();
			return is_array( $data ) ? new self( $data, $object_id, $created ) : new PartialFailure( $object_id, $created );
		} catch ( \Throwable ) {
			return new PartialFailure( $object_id, $created ); }
	}
}
