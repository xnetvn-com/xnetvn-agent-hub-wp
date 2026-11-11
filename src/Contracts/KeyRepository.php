<?php
/**
 * Per-site credential persistence contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

use XNetVN\AgentHubWP\Auth\StoredKey;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\Scope;
/** Separates opaque authentication lookup from safe management metadata. */
interface KeyRepository {
	/**
	 * Create a credential record.
	 *
	 * @param KeyId       $key_id Public lookup ID.
	 * @param int         $user_id Owner.
	 * @param string      $name Display name.
	 * @param Scope       $scope Delegation.
	 * @param string      $hash Stored HMAC.
	 * @param int         $pepper_id Pepper version.
	 * @param string|null $created_ip Source IP.
	 * @return ApiKey
	 */
	public function insert( KeyId $key_id, int $user_id, string $name, Scope $scope, string $hash, int $pepper_id, ?string $created_ip = null ): ApiKey;
	/**
	 * Commit replacement insertion and the old key's expiry together, without reconnect/replay.
	 *
	 * @param ApiKey      $old Expected current snapshot.
	 * @param Scope       $grace Same scope with a narrowed expiry.
	 * @param KeyId       $key_id Replacement lookup ID.
	 * @param string      $hash Replacement HMAC.
	 * @param int         $pepper_id Pepper version.
	 * @param string|null $created_ip Socket metadata.
	 * @return ApiKey Committed replacement metadata.
	 */
	public function insert_rotation( ApiKey $old, Scope $grace, KeyId $key_id, string $hash, int $pepper_id, ?string $created_ip = null ): ApiKey;
	/**
	 * Find opaque verification data.
	 *
	 * @param KeyId $key_id Lookup ID.
	 * @return StoredKey|null
	 */
	public function find_by_key_id( KeyId $key_id ): ?StoredKey;
	/**
	 * Find safe management metadata.
	 *
	 * @param int $id Record ID.
	 * @return ApiKey|null
	 */
	public function find_by_id( int $id ): ?ApiKey;
	/**
	 * List bounded owner metadata.
	 *
	 * @param int $user_id Owner.
	 * @param int $page Page.
	 * @param int $per_page Page size.
	 * @return array<ApiKey>
	 */
	public function list_for_user( int $user_id, int $page = 1, int $per_page = 20 ): array;
	/**
	 * List bounded filtered metadata.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return array<ApiKey>
	 */
	public function list_all( array $filter = array() ): array;
	/**
	 * Apply a narrowing scope using a comparison against the read snapshot.
	 *
	 * @param int   $id Record ID.
	 * @param Scope $scope New restrictions.
	 * @return bool
	 */
	public function update_scope( int $id, Scope $scope ): bool;
	/**
	 * Atomically rename and narrow a currently unrevoked credential.
	 *
	 * @param int    $id Record.
	 * @param string $name New display name.
	 * @param Scope  $scope Narrowed restrictions.
	 * @return bool
	 */
	public function update_key( int $id, string $name, Scope $scope ): bool;
	/**
	 * Update last-use metadata at most every five minutes.
	 *
	 * @param int         $id Record ID.
	 * @param string|null $ip Source IP.
	 * @param string|null $ua User agent.
	 * @return bool
	 */
	public function touch_last_used( int $id, ?string $ip, ?string $ua ): bool;
	/**
	 * Revoke a record.
	 *
	 * @param int    $id Record ID.
	 * @param int    $actor Revoking actor.
	 * @param string $reason Safe reason code.
	 * @return bool
	 */
	public function revoke( int $id, int $actor, string $reason ): bool;
	/**
	 * Revoke all credentials for an owner.
	 *
	 * @param int $user_id Owner.
	 * @return int Number affected.
	 */
	public function revoke_all_for_user( int $user_id ): int;
	/**
	 * Count unexpired/unrevoked credentials.
	 *
	 * @param int $user_id Owner.
	 * @return int
	 */
	public function count_active_for_user( int $user_id ): int;
	/**
	 * Count a validated management filter.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return int
	 */
	public function count_all( array $filter = array() ): int;
}
