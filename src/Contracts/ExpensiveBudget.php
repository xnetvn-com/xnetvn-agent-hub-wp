<?php
/**
 * Atomic expensive-work budget contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

use Closure;
/** Work executes only while an exclusive bounded slot is held. */
interface ExpensiveBudget {
	/**
	 * Run with at most two concurrent operations per current site and identity.
	 *
	 * @param string  $identity Verified key or explicit STDIO actor.
	 * @param Closure $work Work.
	 * @return mixed
	 */
	public function run( string $identity, Closure $work ): mixed;
}
