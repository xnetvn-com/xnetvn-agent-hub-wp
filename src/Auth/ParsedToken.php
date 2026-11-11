<?php
/**
 * Validated request-local credential parts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;

/** Contains opaque token parts only after syntax validation. */
final readonly class ParsedToken {

	/** Public lookup identifier.
	 *
	 * @var KeyId
	 */
	public KeyId $id;

	/** Opaque request credential.
	 *
	 * @var KeySecret
	 */
	public KeySecret $secret;

	/**
	 * Bind validated credential parts.
	 *
	 * @param KeyId     $id Public identifier.
	 * @param KeySecret $secret Opaque secret.
	 */
	public function __construct( KeyId $id, KeySecret $secret ) {
		$this->id     = $id;
		$this->secret = $secret;
	}
}
