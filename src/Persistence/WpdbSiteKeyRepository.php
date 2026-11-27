<?php
/**
 * Prepared per-blog site credential queries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

use DateTimeImmutable;
use XNetVN\AgentHubWP\Auth\StoredSiteKey;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Support\Json;
/**
 * Reads fresh indexed rows, never accepts a caller-supplied blog and fails closed on corruption.
 *
 * The class is deliberately not final: the integration test overrides the protected
 * commit() and rollback() seams to reproduce a lost COMMIT acknowledgement and a
 * failed rollback deterministically. No production code subclasses it, and the
 * seam is not part of the SiteKeyRepository contract.
 */
class WpdbSiteKeyRepository implements SiteKeyRepository {
	use RunsQueries;

	/** Blog-scoped option recording a rotation whose outcome may be unknown. */
	public const ROTATION_OPTION = 'xnetvn_agent_hub_wp_site_key_rotation_intent';
	/** Largest accepted grace window in seconds. */
	private const MAX_ROTATE_GRACE = 86400;
	/** Fixed blog-bound listing states; values are bound once. */
	private const WHERE_SQL = "(%s <> 'active' OR (revoked_at IS NULL AND expires_at > %s)) AND (%s <> 'revoked' OR revoked_at IS NOT NULL) AND (%s <> 'expired' OR (revoked_at IS NULL AND expires_at <= %s))";

	/** Blog identity fixed for the lifetime of this repository.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Bind storage, time and the only blog this instance may touch.
	 *
	 * @param \wpdb $db Database connection.
	 * @param Clock $clock Wall clock.
	 * @param int   $blog_id Active blog identity.
	 * @throws \InvalidArgumentException For an invalid blog identity.
	 */
	public function __construct( private \wpdb $db, private Clock $clock, int $blog_id ) {
		if ( $blog_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid site credential blog.' );
		}
		$this->blog_id = $blog_id;
	}

	/**
	 * Insert one validated accountless credential in the bound blog.
	 *
	 * @param KeyId     $id Public lookup identifier.
	 * @param SiteScope $scope Reviewed machine restrictions.
	 * @param string    $name Display name.
	 * @param string    $hash HMAC of the presented token.
	 * @param int       $pepper_id Pepper version.
	 * @param int       $created_by Creating manager.
	 * @return SiteCredential
	 * @throws KeyCollision For a duplicate identifier.
	 * @throws StorageException For an unavailable insert.
	 */
	public function insert( KeyId $id, SiteScope $scope, string $name, #[\SensitiveParameter] string $hash, int $pepper_id, int $created_by ): SiteCredential {
		$created    = $this->now();
		$credential = new SiteCredential( 1, $id, $this->blog_id, $name, $scope, $created, null, $created_by );
		new StoredSiteKey( $credential, $hash, $pepper_id );
		$data = array(
			'key_id'      => $id->value,
			'blog_id'     => $this->blog_id,
			'name'        => $name,
			'secret_hash' => $hash,
			'pepper_id'   => $pepper_id,
			'scope_json'  => $this->scope_json( $scope ),
			'expires_at'  => $scope->expires_at->format( 'Y-m-d H:i:s' ),
			'created_at'  => $created->format( 'Y-m-d H:i:s' ),
			'created_by'  => $created_by,
		);
		try {
			$this->run_query( fn() => $this->db->insert( $this->table(), $data, array_map( static fn( mixed $value ): string => is_int( $value ) ? '%d' : '%s', $data ) ) );
		} catch ( StorageException $error ) {
			if ( str_contains( $this->db->last_error, 'Duplicate entry' ) && str_contains( $this->db->last_error, 'key_id' ) ) {
				throw new KeyCollision( 'Site credential identifier collision.' );
			}
			throw $error;
		}
		return new SiteCredential( (int) $this->db->insert_id, $id, $this->blog_id, $name, $scope, $created, null, $created_by );
	}

	/**
	 * Find opaque verification data in the bound blog.
	 *
	 * @param KeyId $id Lookup identifier.
	 * @return StoredSiteKey|null
	 * @throws StorageException For corrupted stored metadata.
	 */
	public function find_by_key_id( KeyId $id ): ?StoredSiteKey {
		$row = $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE key_id = %s AND blog_id = %d', $this->table(), $id->value, $this->blog_id ), ARRAY_A ) );
		if ( null === $row ) {
			return null;
		}
		try {
			return new StoredSiteKey( $this->hydrate( $row ), (string) $row['secret_hash'], (int) $row['pepper_id'] );
		} catch ( \Throwable ) {
			throw new StorageException( 'Site credential metadata is unavailable.' );
		}
	}

	/**
	 * Find safe management metadata by database identity.
	 *
	 * @param int $id Record identity.
	 * @return SiteCredential|null
	 * @throws StorageException For corrupted stored metadata.
	 */
	public function find_by_id( int $id ): ?SiteCredential {
		$row = $this->row( $id );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * List bounded metadata with an allowlisted state filter.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return array<SiteCredential>
	 * @throws StorageException For an unavailable listing.
	 */
	public function list_all( array $filter = array() ): array {
		$page   = max( 1, (int) ( $filter['page'] ?? 1 ) );
		$size   = max( 1, min( 100, (int) ( $filter['per_page'] ?? 20 ) ) );
		$state  = is_string( $filter['state'] ?? null ) ? $filter['state'] : '';
		$now    = gmdate( 'Y-m-d H:i:s', $this->clock->now() );
		$values = array( $this->table(), $this->blog_id, $state, $now, $state, $state, $now, $size, ( $page - 1 ) * $size );
		$sql    = PreparedSql::make( $this->db, 'SELECT * FROM %i WHERE blog_id = %d AND ' . self::WHERE_SQL . ' ORDER BY id DESC LIMIT %d OFFSET %d', $values );
		$rows   = $this->run_query( fn() => $this->db->get_results( $sql, ARRAY_A ) );
		if ( ! is_array( $rows ) ) {
			throw new StorageException( 'Site credential listing is unavailable.' );
		}
		return array_map( fn( array $row ): SiteCredential => $this->hydrate( $row ), $rows );
	}

	/**
	 * Count unrevoked and unexpired credentials in the bound blog.
	 *
	 * @return int
	 * @throws StorageException For an unavailable count.
	 */
	public function count_active(): int {
		$sql   = PreparedSql::make( $this->db, 'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND revoked_at IS NULL AND expires_at > %s', array( $this->table(), $this->blog_id, gmdate( 'Y-m-d H:i:s', $this->clock->now() ) ) );
		$value = $this->run_query( fn() => $this->db->get_var( $sql ) );
		if ( ! is_numeric( $value ) ) {
			throw new StorageException( 'Site credential count is unavailable.' );
		}
		return (int) $value;
	}

	/**
	 * Atomically rename and narrow a currently unrevoked credential.
	 *
	 * @param int       $id Record identity.
	 * @param string    $name New display name.
	 * @param SiteScope $scope Narrowed restrictions.
	 * @return bool
	 * @throws \InvalidArgumentException For an invalid name.
	 */
	public function update_key( int $id, string $name, SiteScope $scope ): bool {
		if ( '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid site credential name.' );
		}
		$row = $this->row( $id );
		if ( null === $row || null !== $row['revoked_at'] ) {
			return false;
		}
		if ( ! $scope->is_narrower_than( $this->hydrate( $row )->scope ) ) {
			return false;
		}
		$data  = array(
			'name'       => $name,
			'scope_json' => $this->scope_json( $scope ),
			'expires_at' => $scope->expires_at->format( 'Y-m-d H:i:s' ),
		);
		$where = array(
			'id'         => $id,
			'blog_id'    => $this->blog_id,
			'revoked_at' => null,
		);
		foreach ( array_keys( $data ) as $column ) {
			$where[ $column ] = $row[ $column ];
		}
		$changed = $this->run_query( fn() => $this->db->update( $this->table(), $data, $where ) );
		if ( 1 === $changed ) {
			return true;
		}
		$fresh = $this->row( $id );
		return null !== $fresh && null === $fresh['revoked_at'] && array(
			'name'       => $fresh['name'],
			'scope_json' => $fresh['scope_json'],
			'expires_at' => $fresh['expires_at'],
		) === $data;
	}

	/**
	 * Revoke one credential without rewriting an earlier revocation.
	 *
	 * @param int    $id Record identity.
	 * @param int    $by Revoking manager.
	 * @param string $reason Safe reason identifier.
	 * @return bool
	 * @throws \InvalidArgumentException For invalid revocation metadata.
	 */
	public function revoke( int $id, int $by, string $reason ): bool {
		if ( 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $reason ) || $by < 1 ) {
			throw new \InvalidArgumentException( 'Invalid site credential revocation metadata.' );
		}
		return 1 === $this->run_query(
			fn() => $this->db->update(
				$this->table(),
				array(
					'revoked_at'    => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
					'revoked_by'    => $by,
					'revoke_reason' => $reason,
				),
				array(
					'id'         => $id,
					'blog_id'    => $this->blog_id,
					'revoked_at' => null,
				),
				array( '%s', '%d', '%s' ),
				array( '%d', '%d', '%s' )
			)
		);
	}

	/**
	 * Record last use at most once every five minutes.
	 *
	 * @param int         $id Record identity.
	 * @param string|null $ip Peer address.
	 * @param string|null $ua Peer user agent.
	 * @return bool
	 * @throws StorageException For an unavailable write.
	 */
	public function touch_last_used( int $id, ?string $ip, ?string $ua ): bool {
		$ip     = null !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
		$ua     = null === $ua ? null : mb_substr( sanitize_text_field( $ua ), 0, 255 );
		$now    = gmdate( 'Y-m-d H:i:s', $this->clock->now() );
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->clock->now() - 300 );
		$sql    = PreparedSql::make( $this->db, "UPDATE %i SET last_used_at = %s, last_used_ip = NULLIF(%s, ''), last_used_ua = NULLIF(%s, '') WHERE id = %d AND blog_id = %d AND revoked_at IS NULL AND (last_used_at IS NULL OR last_used_at <= %s)", array( $this->table(), $now, $ip ?? '', $ua ?? '', $id, $this->blog_id, $cutoff ) );
		return 1 === $this->write_query( fn() => $this->db->query( $sql ) );
	}

	/**
	 * Commit a replacement and the old row's grace expiry in one transaction.
	 *
	 * The caller must hold FR_07's per-blog SiteKeyLock; this slice documents the
	 * requirement and does not enforce it.
	 *
	 * @param SiteCredential $old Expected current snapshot.
	 * @param SiteScope      $grace Same scope with a narrowed expiry.
	 * @param KeyId          $new_id Replacement lookup identifier.
	 * @param string         $hash Replacement HMAC.
	 * @param int            $pepper_id Pepper version.
	 * @param int            $by Rotating manager.
	 * @return SiteCredential Committed replacement metadata.
	 * @throws StorageException For unavailable storage, a changed snapshot or an unknown outcome.
	 * @throws \Throwable For a rethrown pre-commit or unknown-outcome failure, including a duplicate replacement identifier.
	 */
	public function insert_rotation( SiteCredential $old, SiteScope $grace, KeyId $new_id, #[\SensitiveParameter] string $hash, int $pepper_id, int $by ): SiteCredential {
		$created     = $this->now();
		$replacement = new SiteCredential( 1, $new_id, $this->blog_id, $old->name, $old->scope, $created, null, $by );
		new StoredSiteKey( $replacement, $hash, $pepper_id );
		$handle = $this->db->__get( 'dbh' );
		if ( ! $handle instanceof \mysqli ) {
			throw new StorageException( 'Site credential rotation is unavailable.' );
		}
		if ( $old->blog_id !== $this->blog_id || $old->is_revoked() || ! $grace->is_narrower_than( $old->scope ) || $grace->expires_at > $old->scope->expires_at || $grace->expires_at->getTimestamp() > $this->clock->now() + self::MAX_ROTATE_GRACE || $this->scope_body( $grace ) !== $this->scope_body( $old->scope ) ) {
			throw new StorageException( 'Site credential rotation is unavailable.' );
		}
		$engine = $this->native_query( $handle, PreparedSql::make( $this->db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $this->table() ) ) );
		if ( ! $engine instanceof \mysqli_result || 'InnoDB' !== ( $engine->fetch_assoc()['ENGINE'] ?? null ) ) {
			throw new StorageException( 'Site credential rotation requires transactional storage.' );
		}
		$autocommit = $this->native_query( $handle, 'SELECT @@autocommit' );
		if ( ! $autocommit instanceof \mysqli_result || '1' !== (string) ( $autocommit->fetch_row()[0] ?? null ) ) {
			throw new StorageException( 'Site credential rotation requires an independent transaction.' );
		}
		$pending = $this->reconcile_rotation();
		if ( null !== $pending['record'] ) {
			if ( 'not_committed' !== $pending['outcome'] || ! isset( $pending['record']['old_id'] ) ) {
				throw new StorageException( 'Site credential rotation outcome is unresolved.' );
			}
			$this->delete_intent();
		}
		$record = $this->rotation_record( $old, $grace, $new_id, $by, 'prepared' );
		update_option( self::ROTATION_OPTION, Json::encode( $record ), false );
		wp_cache_delete( self::ROTATION_OPTION, 'options' );
		$raw = get_option( self::ROTATION_OPTION, null );
		if ( ! is_string( $raw ) ) {
			throw new StorageException( 'Site credential rotation is unavailable.' );
		}
		try {
			$decoded = Json::array( $raw );
		} catch ( \Throwable ) {
			throw new StorageException( 'Site credential rotation is unavailable.' );
		}
		if ( $decoded !== $record ) {
			throw new StorageException( 'Site credential rotation is unavailable.' );
		}
		// SET without SESSION fails within an enclosing transaction; never implicitly commit another caller's work.
		$this->native_query( $handle, 'SET TRANSACTION READ WRITE' );
		$this->native_query( $handle, 'START TRANSACTION' );
		$commit_attempted = false;
		try {
			$selected = $this->native_query( $handle, PreparedSql::make( $this->db, 'SELECT * FROM %i WHERE id = %d AND blog_id = %d FOR UPDATE', array( $this->table(), $old->id, $this->blog_id ) ) );
			$row      = $selected instanceof \mysqli_result ? $selected->fetch_assoc() : null;
			$fresh    = is_array( $row ) ? $this->hydrate( $row ) : null;
			if ( null === $fresh || $fresh->is_revoked() || $fresh->blog_id !== $this->blog_id || $fresh->key_id->value !== $old->key_id->value || $fresh->name !== $old->name || $this->scope_json( $fresh->scope ) !== $this->scope_json( $old->scope ) || $fresh->scope->expires_at <= $created ) {
				throw new StorageException( 'Site credential rotation snapshot changed.' );
			}
			$insert = PreparedSql::make( $this->db, 'INSERT INTO %i (key_id,blog_id,name,secret_hash,pepper_id,scope_json,expires_at,created_at,created_by) VALUES (%s,%d,%s,%s,%d,%s,%s,%s,%d)', array( $this->table(), $new_id->value, $this->blog_id, $old->name, $hash, $pepper_id, $this->scope_json( $old->scope ), $old->scope->expires_at->format( 'Y-m-d H:i:s' ), $created->format( 'Y-m-d H:i:s' ), $by ) );
			$this->native_query( $handle, $insert );
			$id                        = (int) $handle->insert_id;
			$grace_scope               = $old->scope->to_array();
			$grace_scope['expires_at'] = $grace->expires_at->format( 'Y-m-d\TH:i:s\Z' );
			$update                    = PreparedSql::make( $this->db, 'UPDATE %i SET expires_at = %s, scope_json = %s WHERE id = %d AND blog_id = %d', array( $this->table(), $grace->expires_at->format( 'Y-m-d H:i:s' ), Json::encode( $grace_scope ), $old->id, $this->blog_id ) );
			$this->native_query( $handle, $update );
			$commit_attempted = true;
			try {
				$this->commit( $handle );
			} catch ( \Throwable ) {
				throw new StorageException( 'Site credential rotation outcome is unknown.' );
			}
		} catch ( \Throwable $error ) {
			if ( $commit_attempted ) {
				$this->mark_unknown( $record );
				$this->fire_incomplete( $old->id, $new_id->value );
				throw $error;
			}
			try {
				$this->rollback( $handle );
			} catch ( \Throwable ) {
				$this->mark_unknown( $record );
				$this->fire_incomplete( $old->id, $new_id->value );
				throw $error;
			}
			try {
				$this->delete_intent();
			} catch ( \Throwable ) {
				// The prepared record remains as evidence; the probe classifies it as not_committed.
				wp_cache_delete( self::ROTATION_OPTION, 'options' );
			}
			throw $error;
		}
		try {
			$this->delete_intent();
		} catch ( \Throwable ) {
			// A leftover prepared record is classified committed and is never auto-revoked.
			wp_cache_delete( self::ROTATION_OPTION, 'options' );
		}
		return new SiteCredential( $id, $new_id, $this->blog_id, $old->name, $old->scope, $created, null, $by );
	}

	/**
	 * Classify a recorded rotation without writing or restoring anything.
	 *
	 * @return array{outcome:'committed'|'not_committed'|'unknown',record:array{v:int,blog_id:int,old_id:int,old_expires_at:string,grace_expires_at:string,new_key_id:string,actor:int,attempted_at:string,phase:'prepared'|'commit_unknown'}|array{}|null}
	 */
	public function reconcile_rotation(): array {
		wp_cache_delete( self::ROTATION_OPTION, 'options' );
		$raw = get_option( self::ROTATION_OPTION, null );
		if ( null === $raw ) {
			return array(
				'outcome' => 'not_committed',
				'record'  => null,
			);
		}
		if ( ! is_string( $raw ) ) {
			return array(
				'outcome' => 'unknown',
				'record'  => array(),
			);
		}
		try {
			$record = $this->record( Json::array( $raw ) );
		} catch ( \Throwable ) {
			return array(
				'outcome' => 'unknown',
				'record'  => array(),
			);
		}
		try {
			$replacement = $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT id, expires_at, revoked_at FROM %i WHERE key_id = %s AND blog_id = %d', $this->table(), $record['new_key_id'], $record['blog_id'] ), ARRAY_A ) );
			$old         = $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT expires_at, revoked_at FROM %i WHERE id = %d AND blog_id = %d', $this->table(), $record['old_id'], $record['blog_id'] ), ARRAY_A ) );
		} catch ( \Throwable ) {
			return array(
				'outcome' => 'unknown',
				'record'  => $record,
			);
		}
		if ( null !== $replacement && is_array( $old ) && (string) $old['expires_at'] === $record['grace_expires_at'] ) {
			return array(
				'outcome' => 'committed',
				'record'  => $record,
			);
		}
		if ( null === $replacement && is_array( $old ) && (string) $old['expires_at'] === $record['old_expires_at'] ) {
			return array(
				'outcome' => 'not_committed',
				'record'  => $record,
			);
		}
		return array(
			'outcome' => 'unknown',
			'record'  => $record,
		);
	}

	/**
	 * Resolve a recorded rotation only when the classification is unambiguous.
	 *
	 * @return bool Whether evidence was resolved.
	 * @throws StorageException For a failed revoke or evidence delete.
	 */
	public function resolve_rotation_intent(): bool {
		$state  = $this->reconcile_rotation();
		$record = $state['record'];
		if ( null === $record || ! isset( $record['old_id'] ) ) {
			return false;
		}
		if ( 'not_committed' === $state['outcome'] ) {
			$this->delete_intent();
			return true;
		}
		if ( 'committed' === $state['outcome'] && 'commit_unknown' === $record['phase'] ) {
			$this->revoke_unacknowledged( $record['new_key_id'] );
			$this->delete_intent();
			return true;
		}
		return false;
	}

	/**
	 * Commit the pinned transaction without reconnect or replay.
	 *
	 * @param \mysqli $handle Pinned connection.
	 * @return void
	 * @throws StorageException For an unavailable commit.
	 */
	protected function commit( \mysqli $handle ): void {
		$this->native_query( $handle, 'COMMIT AND NO CHAIN NO RELEASE' );
	}

	/**
	 * Roll back the pinned transaction without reconnect or replay.
	 *
	 * @param \mysqli $handle Pinned connection.
	 * @return void
	 * @throws StorageException For an unavailable rollback.
	 */
	protected function rollback( \mysqli $handle ): void {
		$this->native_query( $handle, 'ROLLBACK AND NO CHAIN NO RELEASE' );
	}

	/**
	 * Execute only developer-owned prepared SQL without error disclosure.
	 *
	 * @param \mysqli $handle Pinned connection.
	 * @param string  $sql Prepared statement.
	 * @return \mysqli_result|bool
	 * @throws StorageException For unavailable writes.
	 * @throws KeyCollision For a duplicate identifier.
	 */
	private function native_query( \mysqli $handle, #[\SensitiveParameter] string $sql ): \mysqli_result|bool {
		try {
			// prepare() protects literal percent signs with markers normally removed by wpdb's query filter.
			$result = $handle->query( $this->db->remove_placeholder_escape( $sql ) );
			if ( false !== $result ) {
				return $result;
			}
			$code = $handle->errno;
		} catch ( \mysqli_sql_exception $error ) {
			$code = $error->getCode();
		} catch ( \Throwable ) {
			$code = 0;
		}
		if ( 1062 === $code ) {
			throw new KeyCollision( 'Site credential identifier collision.' );
		}
		throw new StorageException( 'Site credential rotation is unavailable.' );
	}

	/**
	 * Build the bounded reconciliation record.
	 *
	 * @param SiteCredential              $old Expected current snapshot.
	 * @param SiteScope                   $grace Narrowed expiry.
	 * @param KeyId                       $new_id Replacement identifier.
	 * @param int                         $by Rotating manager.
	 * @param 'prepared'|'commit_unknown' $phase Durable phase.
	 * @return array{v:int,blog_id:int,old_id:int,old_expires_at:string,grace_expires_at:string,new_key_id:string,actor:int,attempted_at:string,phase:'prepared'|'commit_unknown'}
	 */
	private function rotation_record( SiteCredential $old, SiteScope $grace, KeyId $new_id, int $by, string $phase ): array {
		return array(
			'v'                => 1,
			'blog_id'          => $this->blog_id,
			'old_id'           => $old->id,
			'old_expires_at'   => $old->scope->expires_at->format( 'Y-m-d H:i:s' ),
			'grace_expires_at' => $grace->expires_at->format( 'Y-m-d H:i:s' ),
			'new_key_id'       => $new_id->value,
			'actor'            => $by,
			'attempted_at'     => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
			'phase'            => $phase,
		);
	}

	/**
	 * Upgrade the durable record to commit_unknown without replacing the original failure.
	 *
	 * @param array{v:int,blog_id:int,old_id:int,old_expires_at:string,grace_expires_at:string,new_key_id:string,actor:int,attempted_at:string,phase:'prepared'|'commit_unknown'} $record Evidence.
	 * @return void
	 */
	private function mark_unknown( array $record ): void {
		try {
			$record['phase'] = 'commit_unknown';
			update_option( self::ROTATION_OPTION, Json::encode( $record ), false );
			wp_cache_delete( self::ROTATION_OPTION, 'options' );
		} catch ( \Throwable ) {
			wp_cache_delete( self::ROTATION_OPTION, 'options' );
		}
	}

	/**
	 * Fire the two-argument incomplete-rotation hook once without replacing the failure.
	 *
	 * @param int    $old_id Old row identity.
	 * @param string $new_key_id Replacement public identifier.
	 * @return void
	 */
	private function fire_incomplete( int $old_id, string $new_key_id ): void {
		try {
			do_action( 'xnetvn_agent_hub_wp_site_key_rotation_incomplete', $old_id, $new_key_id );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Delete the durable record and prove it is gone.
	 *
	 * @return void
	 * @throws StorageException When evidence survives the delete.
	 */
	private function delete_intent(): void {
		delete_option( self::ROTATION_OPTION );
		wp_cache_delete( self::ROTATION_OPTION, 'options' );
		if ( null !== get_option( self::ROTATION_OPTION, null ) ) {
			throw new StorageException( 'Site credential rotation evidence is unavailable.' );
		}
	}

	/**
	 * Revoke a committed replacement whose secret was never acknowledged.
	 *
	 * @param string $key_id Replacement public identifier.
	 * @return void
	 * @throws StorageException For an unavailable write.
	 */
	private function revoke_unacknowledged( string $key_id ): void {
		$sql = PreparedSql::make(
			$this->db,
			'UPDATE %i SET revoked_at = %s, revoked_by = NULL, revoke_reason = %s WHERE key_id = %s AND blog_id = %d AND revoked_at IS NULL',
			array( $this->table(), gmdate( 'Y-m-d H:i:s', $this->clock->now() ), 'rotation_unacknowledged', $key_id, $this->blog_id )
		);
		$this->write_query( fn() => $this->db->query( $sql ) );
	}

	/**
	 * Validate the exact nine-key reconciliation schema.
	 *
	 * @param array<mixed> $data Decoded record.
	 * @return array{v:int,blog_id:int,old_id:int,old_expires_at:string,grace_expires_at:string,new_key_id:string,actor:int,attempted_at:string,phase:'prepared'|'commit_unknown'}
	 * @throws \InvalidArgumentException For any corrupt record.
	 */
	private function record( array $data ): array {
		$expected = array( 'v', 'blog_id', 'old_id', 'old_expires_at', 'grace_expires_at', 'new_key_id', 'actor', 'attempted_at', 'phase' );
		$keys     = array_keys( $data );
		sort( $keys, SORT_STRING );
		$sorted = $expected;
		sort( $sorted, SORT_STRING );
		if ( $keys !== $sorted ) {
			throw new \InvalidArgumentException( 'Invalid rotation record.' );
		}
		if ( 1 !== $data['v'] || $data['blog_id'] !== $this->blog_id || ! is_int( $data['old_id'] ) || ! is_int( $data['actor'] ) || ! is_string( $data['new_key_id'] ) || 1 !== preg_match( '/\A[a-z2-7]{12}\z/', $data['new_key_id'] ) || ( 'prepared' !== $data['phase'] && 'commit_unknown' !== $data['phase'] ) ) {
			throw new \InvalidArgumentException( 'Invalid rotation record.' );
		}
		foreach ( array( 'old_expires_at', 'grace_expires_at', 'attempted_at' ) as $key ) {
			if ( ! is_string( $data[ $key ] ) || ! $this->is_datetime( $data[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Invalid rotation record.' );
			}
		}
		return array(
			'v'                => 1,
			'blog_id'          => $this->blog_id,
			'old_id'           => $data['old_id'],
			'old_expires_at'   => $data['old_expires_at'],
			'grace_expires_at' => $data['grace_expires_at'],
			'new_key_id'       => $data['new_key_id'],
			'actor'            => $data['actor'],
			'attempted_at'     => $data['attempted_at'],
			'phase'            => $data['phase'],
		);
	}

	/**
	 * Resolve the active blog's table at call time, including after switch_to_blog.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'xnetvn_agent_hub_wp_site_keys';
	}

	/**
	 * Fetch a fresh blog-bound row.
	 *
	 * @param int $id Record identity.
	 * @return array<string,mixed>|null
	 */
	private function row( int $id ): ?array {
		return $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d AND blog_id = %d', $this->table(), $id, $this->blog_id ), ARRAY_A ) );
	}

	/**
	 * Encode canonical site scope JSON.
	 *
	 * @param SiteScope $scope Scope.
	 * @return string
	 */
	private function scope_json( SiteScope $scope ): string {
		return Json::encode( $scope->to_array() );
	}

	/**
	 * Return the canonical scope without its movable expiry.
	 *
	 * @param SiteScope $scope Scope.
	 * @return array<string,array<string>|string>
	 */
	private function scope_body( SiteScope $scope ): array {
		$data = $scope->to_array();
		unset( $data['expires_at'] );
		return $data;
	}

	/**
	 * Decode safe site metadata; corrupt values are never repaired into permissive scopes.
	 *
	 * @param array<string,mixed> $row Stored row.
	 * @return SiteCredential
	 * @throws StorageException For corrupted metadata, a cross-blog row or an expiry mismatch.
	 */
	private function hydrate( array $row ): SiteCredential {
		try {
			$blog = (int) $row['blog_id'];
			if ( $blog !== $this->blog_id ) {
				throw new StorageException( 'Site credential metadata is unavailable.' );
			}
			$scope = SiteScope::from_array( Json::array( (string) $row['scope_json'] ) );
			if ( $scope->expires_at->format( 'Y-m-d H:i:s' ) !== (string) $row['expires_at'] ) {
				throw new StorageException( 'Site credential metadata is unavailable.' );
			}
			$created = $this->date( $row['created_at'] );
			if ( null === $created ) {
				throw new StorageException( 'Site credential metadata is unavailable.' );
			}
			return new SiteCredential( (int) $row['id'], new KeyId( (string) $row['key_id'] ), $blog, (string) $row['name'], $scope, $created, $this->date( $row['revoked_at'] ), $this->manager( $row['created_by'] ), $this->manager( $row['revoked_by'] ), $this->date( $row['last_used_at'] ), is_string( $row['last_used_ip'] ) ? $row['last_used_ip'] : null );
		} catch ( \Throwable ) {
			throw new StorageException( 'Site credential metadata is unavailable.' );
		}
	}

	/**
	 * Parse a strict UTC database timestamp.
	 *
	 * @param mixed $value Database value.
	 * @return DateTimeImmutable|null
	 * @throws \InvalidArgumentException For an invalid timestamp.
	 */
	private function date( mixed $value ): ?DateTimeImmutable {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid timestamp.' );
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new \InvalidArgumentException( 'Invalid timestamp.' );
		}
		return $date;
	}

	/**
	 * Parse the mandatory UTC reconciliation timestamp.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	private function is_datetime( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Decode a strictly positive manager attribution.
	 *
	 * @param mixed $value Database value.
	 * @return int|null
	 * @throws \InvalidArgumentException For a non-numeric value.
	 */
	private function manager( mixed $value ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid site credential manager.' );
		}
		return (int) $value;
	}

	/**
	 * Return the current instant from the injected clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . $this->clock->now() );
	}
}
