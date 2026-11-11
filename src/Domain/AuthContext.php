<?php
/**
 * Request-local authenticated actor.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

/** Derives actor identity from the validated key, never the current cookie user. */
final readonly class AuthContext {

	/** Effective WordPress actor.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Bind validated credential metadata to a fresh request identifier.
	 *
	 * @param ApiKey $key Verified key metadata.
	 * @param string $request_id Sixteen hexadecimal request identifier characters.
	 * @throws \InvalidArgumentException For invalid request identifiers.
	 */
	public function __construct( public ApiKey $key, public string $request_id ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{16}\z/', $request_id ) ) {
			throw new \InvalidArgumentException( 'Invalid request identity.' );
		}
		$this->user_id = $key->user_id;
	}
}
