<?php
/**
 * Request-local capability decisions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Policy;

use Closure;

/**
 * Remembers one live capability decision per key inside one tool list round.
 *
 * The memo holds its decisions in its own array and never writes them outside
 * itself: no transient, no object cache, no option and no shared property. A
 * decision is only reused for the same user, capability and object, and the
 * resolver of a decision always describes the same subject as its key.
 */
final class CapabilityMemo {
	/** Remembered decisions of this round.
	 *
	 * @var array<string,bool>
	 */
	private array $results = array();

	/**
	 * Resolve one capability decision and reuse the remembered value of the same key.
	 *
	 * @param int     $user_id    User the decision belongs to.
	 * @param string  $capability Capability name.
	 * @param int     $object_id  Object the capability is checked against.
	 * @param Closure $resolver   Live resolver of this decision.
	 * @phpstan-param Closure(int,string,int):bool $resolver
	 * @return bool
	 */
	public function allows( int $user_id, string $capability, int $object_id, Closure $resolver ): bool {
		$key = $user_id . '|' . $capability . '|' . $object_id;
		if ( ! array_key_exists( $key, $this->results ) ) {
			$this->results[ $key ] = (bool) $resolver( $user_id, $capability, $object_id );
		}
		return $this->results[ $key ];
	}
}
