<?php
/**
 * Non-secret management key presentation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use XNetVN\AgentHubWP\Domain\ApiKey;

/** Emits only explicit safe fields; never serialize persistence entities wholesale. */
final class KeyView {
	/**
	 * Present a key with current expiry status.
	 *
	 * @param ApiKey $key Metadata.
	 * @return array<string,mixed>
	 */
	public static function data( ApiKey $key ): array {
		return array(
			'id'            => $key->id,
			'key_id'        => $key->key_id->value,
			'user_id'       => $key->user_id,
			'name'          => $key->name,
			'tier_max'      => $key->scope->tier_max->value,
			'toolsets'      => $key->scope->toolsets,
			'ability_allow' => $key->scope->ability_allow,
			'ability_deny'  => $key->scope->ability_deny,
			'ip_allowlist'  => $key->scope->ip_allowlist,
			'expires_at'    => $key->scope->expires_at?->format( DATE_ATOM ),
			'created_at'    => $key->created_at->format( DATE_ATOM ),
			'last_used_at'  => $key->last_used_at?->format( DATE_ATOM ),
			'revoked_at'    => $key->revoked_at?->format( DATE_ATOM ),
			'status'        => $key->is_revoked() ? 'revoked' : ( null !== $key->scope->expires_at && $key->scope->expires_at->getTimestamp() <= time() ? 'expired' : 'active' ),
		);
	}
}
