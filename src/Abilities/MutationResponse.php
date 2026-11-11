<?php
/**
 * Core REST response with independently observed mutation identity.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities;

use Closure;
use WP_REST_Response;

/** Response filters cannot replace the ID used for reconciliation. */
final class MutationResponse extends WP_REST_Response {
	/**
	 * Preserve core response data and the request-owned receipt separately.
	 *
	 * @param WP_REST_Response $response Core response.
	 * @param int              $object_id Core-observed ID.
	 * @param bool             $created Creation flag.
	 */
	public function __construct( WP_REST_Response $response, public readonly int $object_id, public readonly bool $created ) {
		parent::__construct( $response->get_data(), $response->get_status(), $response->get_headers() );
	}
	/**
	 * Refuse tampered identity and protect subsequent projection failures.
	 *
	 * @param Closure $project Logical projection.
	 * @return MutationResult|PartialFailure
	 */
	public function project( Closure $project ): MutationResult|PartialFailure {
		$data = $this->get_data();
		if ( ! is_array( $data ) || ( $data['id'] ?? null ) !== $this->object_id ) {
			return new PartialFailure( $this->object_id, $this->created ); }
		return MutationResult::project( $this->object_id, $this->created, static fn(): mixed => $project( $data ) );
	}
}
