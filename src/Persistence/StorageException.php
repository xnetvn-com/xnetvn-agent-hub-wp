<?php
/**
 * Safe storage failure.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

/** Reports unavailability without SQL, parameter or credential details. */
class StorageException extends \RuntimeException {
}
