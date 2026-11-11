<?php
/**
 * A mutation receipt safe to preserve through the output boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities;

use WP_Error;
/** Provider WP_Error data is never treated as a trusted receipt. */
final class PartialFailure extends WP_Error {
	/**
	 * Report an owned mutation that requires read-only reconciliation.
	 *
	 * @param int  $object_id Known affected object.
	 * @param bool $created Whether this request created it.
	 * @throws \InvalidArgumentException For an invalid receipt.
	 */
	public function __construct( public readonly int $object_id, public readonly bool $created ) {
		if ( $object_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid mutation receipt.' ); }
		$field = $created ? 'created_id' : 'affected_id';
		parent::__construct( 'xnetvn_agent_hub_wp_partial_failure', 'partial_failure: The operation may have partially completed. Reconcile before retrying. ' . $field . '=' . $object_id, array( $field => $object_id ) );
	}
}
