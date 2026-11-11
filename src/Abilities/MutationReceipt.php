<?php
/**
 * Request-owned core mutation observation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities;

/** Core REST insert actions provide the exact request object and affected ID. */
final class MutationReceipt {
	/** Known object after core's insert/update and before ancillary field writes.
	 *
	 * @var int|null
	 */
	public ?int $id = null;
}
