<?php
/**
 * Non-secret accountless site key presentation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Rest;

use XNetVN\AgentHubWP\Domain\SiteCredential;

/** Emits only the reviewed site key fields; never serialize secrets or hashes. */
final class SiteKeyView {
	/**
	 * Present one site credential without secret, hash, pepper or owner identity.
	 *
	 * @param SiteCredential $key Metadata.
	 * @return array<string,mixed>
	 */
	public static function data( SiteCredential $key ): array {
		return array(
			'id'                => $key->id,
			'key_id'            => $key->key_id->value,
			'credential_ref'    => 'xns:' . $key->key_id->value,
			'principal_type'    => 'site',
			'blog_id'           => $key->blog_id,
			'name'              => $key->name,
			'profile'           => $key->scope->profile,
			'toolsets'          => $key->scope->toolsets,
			'allowed_abilities' => $key->scope->allowed_abilities,
			'denied_abilities'  => $key->scope->denied_abilities,
			'permissions'       => $key->scope->permissions,
			'ip_allowlist'      => $key->scope->ip_allowlist,
			'tier_ceiling'      => $key->scope->tier_ceiling->value,
			'expires_at'        => $key->scope->expires_at->format( DATE_ATOM ),
			'created_at'        => $key->created_at->format( DATE_ATOM ),
			'revoked_at'        => $key->revoked_at?->format( DATE_ATOM ),
			'created_by'        => $key->created_by,
			'revoked_by'        => $key->revoked_by,
			'last_used_at'      => $key->last_used_at?->format( DATE_ATOM ),
			'last_used_ip'      => $key->last_used_ip,
		);
	}
}
