<?php
/**
 * Bounded credential identifier collision.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

/** Indicates a rejected insert, never permission to replace the prior key. */
final class KeyCollision extends StorageException {
}
