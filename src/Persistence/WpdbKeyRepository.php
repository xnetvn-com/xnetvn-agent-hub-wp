<?php
/**
 * Prepared per-site credential queries.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Persistence;

use DateTimeImmutable;
use XNetVN\AgentHubWP\Auth\StoredKey;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Support\Json;
/** Reads fresh indexed rows so revocation cannot be delayed by a credential cache. */
final class WpdbKeyRepository implements KeyRepository {
	use RunsQueries;

	/** Fixed filter template; values are bound once. */
	private const WHERE_SQL = "(%d = 0 OR user_id = %d) AND (%s <> 'active' OR (revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s))) AND (%s <> 'revoked' OR revoked_at IS NOT NULL) AND (%s <> 'expired' OR (revoked_at IS NULL AND expires_at <= %s)) AND (%s = '' OR expires_at >= %s) AND (%s = '' OR expires_at <= %s)";

	/**
	 * Bind storage and time.
	 *
	 * @param \wpdb $db Database connection.
	 * @param Clock $clock Wall clock.
	 */
	public function __construct( private \wpdb $db, private Clock $clock ) {
	}
	/**
	 * Insert validated opaque credential material.
	 *
	 * @param KeyId       $key_id Public identifier.
	 * @param int         $user_id Owner.
	 * @param string      $name Display name.
	 * @param Scope       $scope Delegation.
	 * @param string      $hash HMAC.
	 * @param int         $pepper_id Pepper version.
	 * @param string|null $created_ip Source IP.
	 * @return ApiKey
	 * @throws KeyCollision For a rejected duplicate identifier.
	 * @throws StorageException For an unavailable insert.
	 * @throws \InvalidArgumentException For invalid input metadata.
	 */
	public function insert( KeyId $key_id, int $user_id, string $name, Scope $scope, #[\SensitiveParameter] string $hash, int $pepper_id, ?string $created_ip = null ): ApiKey {
		$created = new DateTimeImmutable( '@' . $this->clock->now() );
		new StoredKey( new ApiKey( 1, $key_id, $user_id, $name, $scope, $created ), $hash, $pepper_id );
		if ( null !== $created_ip && false === filter_var( $created_ip, FILTER_VALIDATE_IP ) ) {
			throw new \InvalidArgumentException( 'Invalid source IP.' );
		}
		$data = array_merge(
			array(
				'key_id'      => $key_id->value,
				'user_id'     => $user_id,
				'name'        => $name,
				'secret_hash' => $hash,
				'pepper_id'   => $pepper_id,
				'created_at'  => $created->format( 'Y-m-d H:i:s' ),
				'created_ip'  => $created_ip,
			),
			$this->scope_data( $scope )
		);
		try {
			$this->run_query( fn() => $this->db->insert( $this->table(), $data, array_map( static fn( mixed $v ): string => is_int( $v ) ? '%d' : '%s', $data ) ) );
		} catch ( StorageException $error ) {
			if ( str_contains( $this->db->last_error, 'Duplicate entry' ) && str_contains( $this->db->last_error, 'key_id' ) ) {
				throw new KeyCollision( 'Credential identifier collision.' );
			}
			throw $error;
		}
		return new ApiKey( $this->db->insert_id, $key_id, $user_id, $name, $scope, $created );
	}
	/**
	 * Rotate using one pinned native connection, so wpdb cannot reconnect/replay a transaction write.
	 *
	 * @param ApiKey      $old Expected snapshot.
	 * @param Scope       $grace Narrowed expiry.
	 * @param KeyId       $key_id New public identifier.
	 * @param string      $hash Opaque HMAC.
	 * @param int         $pepper_id Pepper version.
	 * @param string|null $created_ip Peer metadata.
	 * @return ApiKey Committed replacement.
	 * @throws StorageException For unavailable/unsupported storage or a changed snapshot.
	 * Duplicate identifiers raise KeyCollision through native_query().
	 *
	 * @see native_query()
	 */
	public function insert_rotation( ApiKey $old, Scope $grace, KeyId $key_id, #[\SensitiveParameter] string $hash, int $pepper_id, ?string $created_ip = null ): ApiKey {
		$created = new DateTimeImmutable( '@' . $this->clock->now() );
		$new     = new ApiKey( 1, $key_id, $old->user_id, $old->name, $old->scope, $created );
		new StoredKey( $new, $hash, $pepper_id );
		$old_data   = $this->scope_data( $old->scope );
		$grace_data = $this->scope_data( $grace );
		unset( $old_data['expires_at'], $grace_data['expires_at'] );
		$handle = $this->db->__get( 'dbh' );
		if ( ! $handle instanceof \mysqli || null === $grace->expires_at || ! $grace->is_narrower_than( $old->scope ) || $grace_data !== $old_data || ( null !== $created_ip && false === filter_var( $created_ip, FILTER_VALIDATE_IP ) ) ) {
			throw new StorageException( 'Credential rotation is unavailable.' );
		}
		$engine = $this->native_query( $handle, PreparedSql::make( $this->db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', array( $this->table() ) ) );
		if ( ! $engine instanceof \mysqli_result || 'InnoDB' !== ( $engine->fetch_assoc()['ENGINE'] ?? null ) ) {
			throw new StorageException( 'Credential rotation requires transactional storage.' );
		}
		$autocommit = $this->native_query( $handle, 'SELECT @@autocommit' );
		if ( ! $autocommit instanceof \mysqli_result || '1' !== (string) ( $autocommit->fetch_row()[0] ?? null ) ) {
			throw new StorageException( 'Credential rotation requires an independent transaction.' );
		}
		// SET without SESSION fails within an enclosing transaction; never implicitly commit another caller's work.
		$this->native_query( $handle, 'SET TRANSACTION READ WRITE' );
		$this->native_query( $handle, 'START TRANSACTION' );
		try {
			$selected = $this->native_query( $handle, PreparedSql::make( $this->db, 'SELECT * FROM %i WHERE id = %d FOR UPDATE', array( $this->table(), $old->id ) ) );
			$row      = $selected instanceof \mysqli_result ? $selected->fetch_assoc() : null;
			$fresh    = is_array( $row ) ? $this->hydrate( $row ) : null;
			if ( null === $fresh || $fresh->is_revoked() || $fresh->user_id !== $old->user_id || $fresh->key_id->value !== $old->key_id->value || $fresh->name !== $old->name || $this->scope_data( $fresh->scope ) !== $this->scope_data( $old->scope ) || ( null !== $fresh->scope->expires_at && $fresh->scope->expires_at <= $created ) ) {
				throw new StorageException( 'Credential rotation snapshot changed.' );
			}
			$scope = $this->scope_data( $old->scope );
			$sql   = PreparedSql::make( $this->db, "INSERT INTO %i (key_id,user_id,name,secret_hash,pepper_id,created_at,created_ip,tier_max,toolsets,ability_allow,ability_deny,ip_allowlist,expires_at) VALUES (%s,%d,%s,%s,%d,%s,NULLIF(%s,''),%s,%s,%s,%s,%s,NULLIF(%s,''))", array( $this->table(), $key_id->value, $old->user_id, $old->name, $hash, $pepper_id, $created->format( 'Y-m-d H:i:s' ), $created_ip ?? '', $scope['tier_max'], $scope['toolsets'], $scope['ability_allow'], $scope['ability_deny'], $scope['ip_allowlist'], $scope['expires_at'] ?? '' ) );
			$this->native_query( $handle, $sql );
			$id = (int) $handle->insert_id;
			$this->native_query( $handle, PreparedSql::make( $this->db, 'UPDATE %i SET expires_at = %s WHERE id = %d', array( $this->table(), $grace->expires_at->format( 'Y-m-d H:i:s' ), $old->id ) ) );
			$this->native_query( $handle, 'COMMIT AND NO CHAIN NO RELEASE' );
			return new ApiKey( $id, $key_id, $old->user_id, $old->name, $old->scope, $created );
		} catch ( \Throwable $error ) {
			try {
				$this->native_query( $handle, 'ROLLBACK AND NO CHAIN NO RELEASE' );
			} catch ( \Throwable ) {
				// A lost connection rolls back uncommitted writes; an unknown commit outcome needs metadata reconciliation.
				do_action( 'xnetvn_agent_hub_wp_rotation_incomplete' );
			}
			throw $error;
		}
	}

	/**
	 * Execute only developer-owned prepared SQL without reconnect, replay or SQL error disclosure.
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
			throw new KeyCollision( 'Credential identifier collision.' );
		}
		throw new StorageException( 'Credential rotation is unavailable.' );
	}
	/**
	 * Read current opaque verification data.
	 *
	 * @param KeyId $key_id Identifier.
	 * @return StoredKey|null
	 */
	public function find_by_key_id( KeyId $key_id ): ?StoredKey {
		$row = $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE key_id = %s', $this->table(), $key_id->value ), ARRAY_A ) );
		return null === $row ? null : new StoredKey( $this->hydrate( $row ), (string) $row['secret_hash'], (int) $row['pepper_id'] );
	}
	/**
	 * Read safe management metadata.
	 *
	 * @param int $id Record ID.
	 * @return ApiKey|null
	 */
	public function find_by_id( int $id ): ?ApiKey {
		$row = $this->row( $id );
		return null === $row ? null : $this->hydrate( $row );
	}
	/**
	 * Read one bounded owner's page.
	 *
	 * @param int $user_id Owner.
	 * @param int $page Page.
	 * @param int $per_page Page size.
	 * @return array<ApiKey>
	 */
	public function list_for_user( int $user_id, int $page = 1, int $per_page = 20 ): array {
		return $this->list_all(
			array(
				'user_id'  => $user_id,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}
	/**
	 * Read bounded filtered metadata in a stable order.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return array<ApiKey>
	 * @throws StorageException For an unavailable listing.
	 */
	public function list_all( array $filter = array() ): array {
		$page   = max( 1, (int) ( $filter['page'] ?? 1 ) );
		$size   = max( 1, min( 100, (int) ( $filter['per_page'] ?? 20 ) ) );
		$values = array_merge( array( $this->table() ), $this->where_values( $filter ), array( $size, ( $page - 1 ) * $size ) );
		$sql    = PreparedSql::make( $this->db, 'SELECT * FROM %i WHERE ' . self::WHERE_SQL . ' ORDER BY id DESC LIMIT %d OFFSET %d', $values );
		$rows   = $this->run_query( fn() => $this->db->get_results( $sql, ARRAY_A ) );
		if ( ! is_array( $rows ) ) {
			throw new StorageException( 'Credential listing is unavailable.' );
		}
		return array_map( fn( array $row ): ApiKey => $this->hydrate( $row ), $rows );
	}
	/**
	 * Update only a narrowing scope with atomic snapshot comparison.
	 *
	 * @param int   $id Record ID.
	 * @param Scope $scope New restrictions.
	 * @return bool
	 */
	public function update_scope( int $id, Scope $scope ): bool {
		$key = $this->find_by_id( $id );
		return null !== $key && $this->update_key( $id, $key->name, $scope );
	}
	/**
	 * Apply a name and narrowing scope with atomic snapshot comparison.
	 *
	 * @param int    $id Record ID.
	 * @param string $name Display name.
	 * @param Scope  $scope New restrictions.
	 * @return bool
	 * @throws \InvalidArgumentException For invalid names.
	 */
	public function update_key( int $id, string $name, Scope $scope ): bool {
		if ( '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid key name.' );
		}
		$row = $this->row( $id );
		if ( null === $row || null !== $row['revoked_at'] || ! $scope->is_narrower_than( $this->hydrate( $row )->scope ) ) {
			return false;
		}
		$data  = array_merge( $this->scope_data( $scope ), array( 'name' => $name ) );
		$where = array(
			'id'         => $id,
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
		return null !== $fresh && null === $fresh['revoked_at'] && array_merge( $this->scope_data( $this->hydrate( $fresh )->scope ), array( 'name' => $fresh['name'] ) ) === $data;
	}
	/**
	 * Touch last-use metadata once per five minutes using an atomic condition.
	 *
	 * @param int         $id Record ID.
	 * @param string|null $ip Source IP.
	 * @param string|null $ua User agent.
	 * @return bool
	 */
	public function touch_last_used( int $id, ?string $ip, ?string $ua ): bool {
		$ip     = null !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
		$ua     = null === $ua ? null : mb_substr( sanitize_text_field( $ua ), 0, 255 );
		$now    = gmdate( 'Y-m-d H:i:s', $this->clock->now() );
		$cutoff = gmdate( 'Y-m-d H:i:s', $this->clock->now() - 300 );
		$sql    = PreparedSql::make( $this->db, "UPDATE %i SET last_used_at = %s, last_used_ip = NULLIF(%s, ''), last_used_ua = NULLIF(%s, '') WHERE id = %d AND revoked_at IS NULL AND (last_used_at IS NULL OR last_used_at <= %s)", array( $this->table(), $now, $ip ?? '', $ua ?? '', $id, $cutoff ) );
		return 1 === $this->write_query( fn() => $this->db->query( $sql ) );
	}
	/**
	 * Revoke a credential immediately.
	 *
	 * @param int    $id Record ID.
	 * @param int    $actor Revoking actor.
	 * @param string $reason Safe reason identifier.
	 * @return bool
	 * @throws \InvalidArgumentException For invalid revocation metadata.
	 */
	public function revoke( int $id, int $actor, string $reason ): bool {
		if ( 1 !== preg_match( '/\A[a-z0-9_]{1,100}\z/', $reason ) || $actor < 1 ) {
			throw new \InvalidArgumentException( 'Invalid revocation metadata.' );
		}
		return 1 === $this->run_query(
			fn() => $this->db->update(
				$this->table(),
				array(
					'revoked_at'    => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
					'revoked_by'    => $actor,
					'revoke_reason' => $reason,
				),
				array(
					'id'         => $id,
					'revoked_at' => null,
				),
				array( '%s', '%d', '%s' ),
				array( '%d', '%s' )
			)
		);
	}
	/**
	 * Revoke every active credential of an owner.
	 *
	 * @param int $user_id Owner.
	 * @return int Affected rows.
	 */
	public function revoke_all_for_user( int $user_id ): int {
		return $this->write_query(
			fn() => $this->db->update(
				$this->table(),
				array(
					'revoked_at'    => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
					'revoke_reason' => 'owner_missing',
				),
				array(
					'user_id'    => $user_id,
					'revoked_at' => null,
				),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			)
		);
	}
	/**
	 * Count active credentials with expiry respected.
	 *
	 * @param int $user_id Owner.
	 * @return int
	 */
	public function count_active_for_user( int $user_id ): int {
		return $this->count_all(
			array(
				'user_id' => $user_id,
				'state'   => 'active',
			)
		);
	}
	/**
	 * Count metadata matching the same listing filters.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return int
	 * @throws StorageException For an unavailable count.
	 */
	public function count_all( array $filter = array() ): int {
		$sql   = PreparedSql::make( $this->db, 'SELECT COUNT(*) FROM %i WHERE ' . self::WHERE_SQL, array_merge( array( $this->table() ), $this->where_values( $filter ) ) );
		$value = $this->run_query( fn() => $this->db->get_var( $sql ) );
		if ( ! is_numeric( $value ) ) {
			throw new StorageException( 'Credential count is unavailable.' );
		}
		return (int) $value;
	}
	/**
	 * Resolve the active site's table at call time, including after switch_to_blog.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->db->prefix . 'xnetvn_agent_hub_wp_keys';
	}
	/**
	 * Fetch a fresh row.
	 *
	 * @param int $id Record ID.
	 * @return array<string,mixed>|null
	 */
	private function row( int $id ): ?array {
		return $this->run_query( fn() => $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ) );
	}
	/**
	 * Serialize canonical scope columns.
	 *
	 * @param Scope $scope Scope.
	 * @return array<string,string|null>
	 */
	private function scope_data( Scope $scope ): array {
		return array(
			'tier_max'      => $scope->tier_max->value,
			'toolsets'      => Json::encode( $scope->toolsets ),
			'ability_allow' => Json::encode( $scope->ability_allow ),
			'ability_deny'  => Json::encode( $scope->ability_deny ),
			'ip_allowlist'  => Json::encode( $scope->ip_allowlist ),
			'expires_at'    => $scope->expires_at?->format( 'Y-m-d H:i:s' ),
		);
	}
	/**
	 * Decode safe key metadata; corrupt values are not repaired into permissive scopes.
	 *
	 * @param array<string,mixed> $row Stored row.
	 * @return ApiKey
	 * @throws StorageException For corrupted metadata.
	 */
	private function hydrate( array $row ): ApiKey {
		try {
			$scope   = new Scope( RiskTier::from( (string) $row['tier_max'] ), $this->strings( $row['toolsets'] ), $this->strings( $row['ability_allow'] ), $this->strings( $row['ability_deny'] ), $this->strings( $row['ip_allowlist'] ), $this->date( $row['expires_at'] ) );
			$created = $this->date( $row['created_at'] );
			if ( null === $created ) {
				throw new StorageException( 'Missing creation time.' );
			}
			return new ApiKey( (int) $row['id'], new KeyId( (string) $row['key_id'] ), (int) $row['user_id'], (string) $row['name'], $scope, $created, $this->date( $row['revoked_at'] ), $this->date( $row['last_used_at'] ), is_string( $row['last_used_ip'] ) ? $row['last_used_ip'] : null );
		} catch ( \Throwable ) {
			throw new StorageException( 'Credential metadata is unavailable.' );
		}
	}
	/**
	 * Decode only a list of strings.
	 *
	 * @param mixed $value Persisted JSON.
	 * @return array<string>
	 * @throws \JsonException For invalid storage.
	 */
	private function strings( mixed $value ): array {
		$data = null === $value ? array() : Json::array( (string) $value );
		if ( ! array_is_list( $data ) ) {
			throw new \JsonException( 'Expected a string list.' );
		}
		foreach ( $data as $item ) {
			if ( ! is_string( $item ) ) {
				throw new \JsonException( 'Expected a string list.' );
			}
		}
		return $data;
	}
	/**
	 * Parse a strict UTC database timestamp.
	 *
	 * @param mixed $value Database value.
	 * @return DateTimeImmutable|null
	 * @throws \InvalidArgumentException For invalid dates.
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
	 * Prepare all supported filter conditions.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return array<int|string> Bound WHERE values.
	 */
	private function where_values( array $filter ): array {
		$user   = (int) ( $filter['user_id'] ?? 0 );
		$state  = is_string( $filter['state'] ?? null ) ? $filter['state'] : '';
		$now    = gmdate( 'Y-m-d H:i:s', $this->clock->now() );
		$after  = is_string( $filter['expires_after'] ?? null ) ? $filter['expires_after'] : '';
		$before = is_string( $filter['expires_before'] ?? null ) ? $filter['expires_before'] : '';
		return array( $user, $user, $state, $now, $state, $state, $now, $after, $after, $before, $before );
	}
}
