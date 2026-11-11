<?php
/**
 * Connection-owned MySQL expensive-operation slots.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\RateLimit;

use Closure;
use XNetVN\AgentHubWP\Contracts\ExpensiveBudget;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Persistence\RunsQueries;
use XNetVN\AgentHubWP\Persistence\StorageException;

/** Atomic across workers; process death releases ownership without stale leases. */
final class WpExpensiveBudget implements ExpensiveBudget {
	use RunsQueries;

	/** Held slots prevent MySQL's same-connection reentrant acquisition.
	 *
	 * @var array<string,bool>
	 */
	private static array $held = array();
	/** Failed cleanup closes further expensive work for this request.
	 *
	 * @var bool
	 */
	private bool $storage_available = true;

	/**
	 * Bind the primary WordPress connection.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( private \wpdb $db ) {}
	/**
	 * Acquire one of two nonblocking connection-owned locks, then always release.
	 *
	 * @param string  $identity Verified scoped identity.
	 * @param Closure $work Operation.
	 * @return mixed
	 * @throws StorageException For unavailable locking; never treat failure as zero.
	 */
	public function run( string $identity, Closure $work ): mixed {
		if ( ! $this->storage_available ) {
			throw new StorageException( 'Expensive operation storage is unavailable.' ); }
		$base = 'xnv-cost-' . substr( hash( 'sha256', $this->db->prefix . ':' . $identity ), 0, 40 );
		for ( $slot = 0; $slot < 2; ++$slot ) {
			$name = $base . '-' . $slot;
			if ( isset( self::$held[ $name ] ) ) {
				continue; }
			$result = $this->run_query( fn() => $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
			if ( null === $result ) {
				throw new StorageException( 'Expensive operation storage is unavailable.' ); }
			if ( '1' !== (string) $result ) {
				continue; }
			self::$held[ $name ] = true;
			try {
				return $work(); } finally {
				try {
					$released = $this->run_query( fn() => $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ) );
					if ( '1' === (string) $released ) {
						unset( self::$held[ $name ] );
					} else {
						$this->storage_available = false; }
				} catch ( \Throwable ) {
					// Cleanup must not erase a completed mutation. Keep ownership and fail closed until request teardown.
					$this->storage_available = false;
				}
				}
		}
		return ReasonMapper::error( 'rate_limited' );
	}
}
