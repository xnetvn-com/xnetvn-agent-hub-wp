<?php
/**
 * Per-blog site credential persistence contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

use XNetVN\AgentHubWP\Auth\StoredSiteKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
/**
 * Binds every read and write to the constructor's blog and never accepts an owner.
 *
 * A repository instance may not be reused for another blog: the blog identity is
 * set once, while the table prefix is resolved per call so a stale instance still
 * cannot cross a switch_to_blog() boundary.
 */
interface SiteKeyRepository {
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
	 */
	public function insert( KeyId $id, SiteScope $scope, string $name, #[\SensitiveParameter] string $hash, int $pepper_id, int $created_by ): SiteCredential;
	/**
	 * Find opaque verification data in the bound blog.
	 *
	 * @param KeyId $id Lookup identifier.
	 * @return StoredSiteKey|null
	 */
	public function find_by_key_id( KeyId $id ): ?StoredSiteKey;
	/**
	 * Find safe management metadata by database identity.
	 *
	 * @param int $id Record identity.
	 * @return SiteCredential|null
	 */
	public function find_by_id( int $id ): ?SiteCredential;
	/**
	 * List bounded metadata with an allowlisted state filter.
	 *
	 * @param array<string,mixed> $filter Filters.
	 * @return array<SiteCredential>
	 */
	public function list_all( array $filter = array() ): array;
	/**
	 * Count unrevoked and unexpired credentials in the bound blog.
	 *
	 * @return int
	 */
	public function count_active(): int;
	/**
	 * Atomically rename and narrow a currently unrevoked credential.
	 *
	 * @param int       $id Record identity.
	 * @param string    $name New display name.
	 * @param SiteScope $scope Narrowed restrictions.
	 * @return bool
	 */
	public function update_key( int $id, string $name, SiteScope $scope ): bool;
	/**
	 * Revoke one credential without rewriting an earlier revocation.
	 *
	 * @param int    $id Record identity.
	 * @param int    $by Revoking manager.
	 * @param string $reason Safe reason identifier.
	 * @return bool
	 */
	public function revoke( int $id, int $by, string $reason ): bool;
	/**
	 * Record last use at most once every five minutes.
	 *
	 * @param int         $id Record identity.
	 * @param string|null $ip Peer address.
	 * @param string|null $ua Peer user agent.
	 * @return bool
	 */
	public function touch_last_used( int $id, ?string $ip, ?string $ua ): bool;
	/**
	 * Commit a replacement and the old row's grace expiry in one transaction.
	 *
	 * @param SiteCredential $old Expected current snapshot.
	 * @param SiteScope      $grace Same scope with a narrowed expiry.
	 * @param KeyId          $new_id Replacement lookup identifier.
	 * @param string         $hash Replacement HMAC.
	 * @param int            $pepper_id Pepper version.
	 * @param int            $by Rotating manager.
	 * @return SiteCredential Committed replacement metadata.
	 */
	public function insert_rotation( SiteCredential $old, SiteScope $grace, KeyId $new_id, #[\SensitiveParameter] string $hash, int $pepper_id, int $by ): SiteCredential;
	/**
	 * Classify a recorded rotation without writing or restoring anything.
	 *
	 * @return array{outcome:'committed'|'not_committed'|'unknown',record:array{v:int,blog_id:int,old_id:int,old_expires_at:string,grace_expires_at:string,new_key_id:string,actor:int,attempted_at:string,phase:'prepared'|'commit_unknown'}|array{}|null}
	 */
	public function reconcile_rotation(): array;
	/**
	 * Resolve a recorded rotation only when the classification is unambiguous.
	 *
	 * @return bool Whether evidence was resolved.
	 */
	public function resolve_rotation_intent(): bool;
}
