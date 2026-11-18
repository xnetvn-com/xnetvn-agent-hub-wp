<?php
/**
 * Accountless site credential lifecycle services.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use DateTimeImmutable;
use WP_Error;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Persistence\KeyCollision;
use XNetVN\AgentHubWP\Persistence\SiteKeyLock;
use XNetVN\AgentHubWP\Persistence\StorageException;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;

/** Issues and manages bounded site credentials without inventing a WordPress owner. */
final class SiteKeyService {
	/** Active credential quota per blog. */
	public const ACTIVE_QUOTA = 10;
	/** Default lifetime when the caller does not choose one. */
	public const DEFAULT_EXPIRY_DAYS = 90;
	/** Maximum rotation grace in hours. */
	public const MAX_ROTATE_GRACE_HOURS = 24;

	/** Shared password-retry budget size. */
	private const PASSWORD_ATTEMPTS = 5;
	/** Exact creation fields. */
	private const CREATE_FIELDS = array( 'name', 'permissions', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'expires_in_days', 'current_password' );
	/** Exact patch fields. */
	private const PATCH_FIELDS = array( 'name', 'permissions', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'expires_in_days' );
	/** Exact rotation fields. */
	private const ROTATE_FIELDS = array( 'current_password', 'grace_hours' );
	/** Exact list fields. */
	private const LIST_FIELDS = array( 'page', 'per_page', 'state' );

	/**
	 * Bind lifecycle dependencies.
	 *
	 * @param SiteKeyRepository $keys Persistence bound to one blog.
	 * @param SiteKeyLock       $lock Per-blog mutation lock.
	 * @param SiteTokenCodec    $codec Site credential generator.
	 * @param PepperStore       $peppers Separate random pepper.
	 * @param SiteSettings      $settings Site policy.
	 * @param AbilityCatalog    $catalog Registered abilities.
	 * @param RateLimiter       $limits Shared password-retry budget.
	 * @param Clock             $clock UTC clock shared with the repository.
	 */
	public function __construct( private SiteKeyRepository $keys, private SiteKeyLock $lock, private SiteTokenCodec $codec, private PepperStore $peppers, private SiteSettings $settings, private AbilityCatalog $catalog, private RateLimiter $limits, private Clock $clock ) {
	}

	/**
	 * Issue one site credential under the current manager.
	 *
	 * @param array<string,mixed> $input Exact creation fields.
	 * @return IssuedSiteKey|WP_Error
	 */
	public function create( #[\SensitiveParameter] array $input ): IssuedSiteKey|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		try {
			$validated = $this->validate_create( $input );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			return $this->lock->run(
				function () use ( $manager, $validated ): IssuedSiteKey|WP_Error {
					if ( $this->keys->count_active() >= self::ACTIVE_QUOTA ) {
						return $this->error( 'key_limit', 409 );
					}
					$password = $this->reauthenticate( $manager, $validated['current_password'] );
					if ( is_wp_error( $password ) ) {
						return $password;
					}
					return $this->issue( $manager, $validated['name'], $validated['scope'] );
				}
			);
		} catch ( \InvalidArgumentException ) {
			return $this->error( 'invalid_site_key', 400 );
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Read only authorized non-secret metadata.
	 *
	 * @param int $id Record identity.
	 * @return SiteCredential|WP_Error
	 */
	public function get( int $id ): SiteCredential|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		try {
			$key = $this->keys->find_by_id( $id );
			return null === $key ? $this->error( 'not_found', 404 ) : $key;
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * List bounded site credential metadata.
	 *
	 * @param array<string,mixed> $filter Exact list fields.
	 * @return array<SiteCredential>|WP_Error
	 */
	public function list( array $filter = array() ): array|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		if ( array_diff( array_keys( $filter ), self::LIST_FIELDS ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$page  = array_key_exists( 'page', $filter ) ? $filter['page'] : 1;
		$size  = array_key_exists( 'per_page', $filter ) ? $filter['per_page'] : 20;
		$state = array_key_exists( 'state', $filter ) ? $filter['state'] : '';
		if ( ! is_int( $page ) || $page < 1 || ! is_int( $size ) || $size < 1 || $size > 100 || ! is_string( $state ) || ! in_array( $state, array( '', 'active', 'revoked', 'expired' ), true ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		try {
			return $this->keys->list_all(
				array(
					'page'     => $page,
					'per_page' => $size,
					'state'    => $state,
				)
			);
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Rename or narrow one credential without extending it.
	 *
	 * @param int                 $id Record identity.
	 * @param array<string,mixed> $input Exact patch fields.
	 * @return SiteCredential|WP_Error
	 */
	public function patch( int $id, array $input ): SiteCredential|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		if ( array_diff( array_keys( $input ), self::PATCH_FIELDS ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		try {
			return $this->lock->run(
				function () use ( $id, $input ): SiteCredential|WP_Error {
					$old = $this->keys->find_by_id( $id );
					if ( null === $old || $old->is_revoked() || $old->scope->expires_at->getTimestamp() <= $this->clock->now() ) {
						return $this->error( 'not_found', 404 );
					}
					$name   = array_key_exists( 'name', $input ) ? $input['name'] : $old->name;
					$narrow = $this->narrowed_scope( $old->scope, $input );
					if ( is_wp_error( $narrow ) ) {
						return $narrow;
					}
					if ( ! is_string( $name ) || '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) ) {
						return $this->error( 'invalid_site_key', 400 );
					}
					if ( ! $narrow->is_narrower_than( $old->scope ) ) {
						return $this->error( 'scope_expansion', 409 );
					}
					if ( ! $this->keys->update_key( $id, $name, $narrow ) ) {
						return $this->error( 'not_found', 404 );
					}
					$fresh = $this->keys->find_by_id( $id );
					return null === $fresh ? $this->error( 'not_found', 404 ) : $fresh;
				}
			);
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Replace a credential inside the committed rotation boundary.
	 *
	 * @param int                 $id Old record identity.
	 * @param array<string,mixed> $input Exact rotation fields.
	 * @return IssuedSiteKey|WP_Error
	 */
	public function rotate( int $id, #[\SensitiveParameter] array $input ): IssuedSiteKey|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		if ( array_diff( array_keys( $input ), self::ROTATE_FIELDS ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$grace_hours = array_key_exists( 'grace_hours', $input ) ? $input['grace_hours'] : 24;
		$password    = array_key_exists( 'current_password', $input ) ? $input['current_password'] : null;
		if ( ! is_int( $grace_hours ) || $grace_hours < 0 || $grace_hours > self::MAX_ROTATE_GRACE_HOURS || ! is_string( $password ) || '' === $password || strlen( $password ) > 4096 ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		try {
			return $this->lock->run(
				function () use ( $id, $manager, $grace_hours, $password ): IssuedSiteKey|WP_Error {
					$old = $this->keys->find_by_id( $id );
					if ( null === $old || $old->is_revoked() || $old->scope->expires_at->getTimestamp() <= $this->clock->now() ) {
						return $this->error( 'not_found', 404 );
					}
					if ( $this->keys->count_active() >= self::ACTIVE_QUOTA ) {
						return $this->error( 'key_limit', 409 );
					}
					$pending = $this->keys->reconcile_rotation();
					if ( null !== $pending['record'] && in_array( $pending['outcome'], array( 'committed', 'unknown' ), true ) ) {
						return $this->error( 'rotation_unresolved', 409 );
					}
					$reauth = $this->reauthenticate( $manager, $password );
					if ( is_wp_error( $reauth ) ) {
						return $reauth;
					}
					$until = min( $old->scope->expires_at->getTimestamp(), $this->clock->now() + $grace_hours * HOUR_IN_SECONDS );
					$grace = new SiteScope(
						$old->scope->tier_ceiling,
						$old->scope->toolsets,
						$old->scope->allowed_abilities,
						$old->scope->denied_abilities,
						$old->scope->permissions,
						$old->scope->ip_allowlist,
						new DateTimeImmutable( '@' . $until )
					);
					return $this->rotate_issue( $old, $grace, $manager );
				}
			);
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Revoke one credential; revocation is one-way.
	 *
	 * @param int $id Record identity.
	 * @return bool|WP_Error
	 */
	public function revoke( int $id ): bool|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		try {
			return $this->lock->run(
				function () use ( $id, $manager ): bool|WP_Error {
					$key = $this->keys->find_by_id( $id );
					if ( null === $key ) {
						return $this->error( 'not_found', 404 );
					}
					return $this->keys->revoke( $id, $manager, 'administrator_request' );
				}
			);
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Resolve a recorded commit-unknown rotation on the explicit operator path.
	 *
	 * @return bool|WP_Error
	 */
	public function resolve_rotation_intent(): bool|WP_Error {
		$manager = $this->manager();
		if ( is_wp_error( $manager ) ) {
			return $manager;
		}
		try {
			return $this->lock->run( fn(): bool => $this->keys->resolve_rotation_intent() );
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Require one live manager identity.
	 *
	 * @return int|WP_Error
	 */
	private function manager(): int|WP_Error {
		try {
			$manager = get_current_user_id();
			if ( $manager < 1 || ! user_can( $manager, Capabilities::MANAGE ) ) {
				return $this->error( 'forbidden', 403 );
			}
			return $manager;
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Validate exact creation fields into one materialized scope.
	 *
	 * @param array<string,mixed> $input Creation fields.
	 * @return array{name:string,scope:SiteScope,current_password:string}|WP_Error
	 */
	private function validate_create( #[\SensitiveParameter] array $input ): array|WP_Error {
		if ( array_diff( array_keys( $input ), self::CREATE_FIELDS ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$name        = $input['name'] ?? null;
		$password    = $input['current_password'] ?? null;
		$permissions = $this->string_list( $input['permissions'] ?? array() );
		if ( ! is_string( $name ) || '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) || ! is_string( $password ) || '' === $password || strlen( $password ) > 4096 || is_wp_error( $permissions ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		foreach ( $permissions as $permission ) {
			if ( ! in_array( $permission, array( SiteScope::PERMISSION_BAN_USERS, SiteScope::PERMISSION_UNBAN_USERS ), true ) ) {
				return $this->error( 'invalid_site_key', 400 );
			}
		}
		$ceiling = $this->ceiling( $permissions );
		if ( is_wp_error( $ceiling ) ) {
			return $ceiling;
		}
		$toolsets = $this->string_list( $input['toolsets'] ?? array() );
		$allow    = $this->string_list( $input['ability_allow'] ?? array() );
		$deny     = $this->string_list( $input['ability_deny'] ?? array() );
		$ips      = $this->string_list( $input['ip_allowlist'] ?? array() );
		if ( is_wp_error( $toolsets ) || is_wp_error( $allow ) || is_wp_error( $deny ) || is_wp_error( $ips ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$materialized = $this->materialize( $toolsets, $allow, $deny, $permissions, $ceiling );
		if ( is_wp_error( $materialized ) ) {
			return $materialized;
		}
		$days = array_key_exists( 'expires_in_days', $input ) ? $input['expires_in_days'] : min( self::DEFAULT_EXPIRY_DAYS, (int) $this->settings->get( 'max_key_lifetime_days' ) );
		if ( ! is_int( $days ) || $days < 1 || $days > (int) $this->settings->get( 'max_key_lifetime_days' ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		try {
			$scope = new SiteScope(
				$ceiling,
				$materialized['toolsets'],
				$materialized['allowed'],
				$deny,
				$permissions,
				$ips,
				new DateTimeImmutable( '@' . ( $this->clock->now() + $days * DAY_IN_SECONDS ) )
			);
		} catch ( \InvalidArgumentException ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		return array(
			'name'             => $name,
			'scope'            => $scope,
			'current_password' => $password,
		);
	}

	/**
	 * Resolve the effective ceiling from site policy and moderation selection.
	 *
	 * @param array<string> $permissions Selected permissions.
	 * @return RiskTier|WP_Error
	 */
	private function ceiling( array $permissions ): RiskTier|WP_Error {
		$site = RiskTier::tryFrom( (string) $this->settings->get( 'tier_ceiling' ) );
		if ( null === $site ) {
			return $this->error( 'unavailable', 503 );
		}
		if ( array() !== $permissions ) {
			if ( true !== $this->settings->get( 'allow_admin_tier' ) || RiskTier::Admin !== $site ) {
				return $this->error( 'forbidden', 403 );
			}
			return RiskTier::Admin;
		}
		return RiskTier::Destructive->atMost( $site ) ? RiskTier::Destructive : $site;
	}

	/**
	 * Materialize registered reviewed operations into one bounded allowlist.
	 *
	 * @param array<string> $toolsets Requested toolsets.
	 * @param array<string> $allow Requested allowlist.
	 * @param array<string> $deny Requested denylist.
	 * @param array<string> $permissions Selected permissions.
	 * @param RiskTier      $ceiling Effective ceiling.
	 * @return array{toolsets:array<string>,allowed:array<string>}|WP_Error
	 */
	private function materialize( array $toolsets, array $allow, array $deny, array $permissions, RiskTier $ceiling ): array|WP_Error {
		$restrict = array() !== $toolsets;
		$known    = array();
		foreach ( $this->catalog->all() as $definition ) {
			$known[ $definition->descriptor()->toolset ] = true;
		}
		if ( array_diff( $toolsets, array_keys( $known ) ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$names = SafeMcpEnvelope::REVIEWED_EDITORIAL_SYSTEM_OPERATIONS;
		if ( array() !== $permissions ) {
			$names = array_merge( $names, SafeMcpEnvelope::SITE_MODERATION_OPERATIONS );
			if ( $restrict ) {
				$toolsets[] = 'users';
			}
		}
		$candidate = array();
		$available = array();
		foreach ( $this->catalog->all() as $definition ) {
			$descriptor = $definition->descriptor();
			if ( ! in_array( $descriptor->name, $names, true ) || ! $descriptor->tier->atMost( $ceiling ) ) {
				continue;
			}
			if ( $restrict && ! in_array( $descriptor->toolset, $toolsets, true ) ) {
				continue;
			}
			$candidate[ $descriptor->name ]    = true;
			$available[ $descriptor->toolset ] = true;
		}
		if ( array() !== $permissions && ! isset( $candidate['xnetvn/set-user-status'] ) ) {
			return $this->error( 'forbidden', 403 );
		}
		foreach ( array_merge( $allow, $deny ) as $name ) {
			if ( ! isset( $candidate[ $name ] ) ) {
				return $this->error( 'invalid_site_key', 400 );
			}
		}
		$allowed = array() === $allow ? array_keys( $candidate ) : $allow;
		$allowed = array_values( array_diff( $allowed, $deny ) );
		sort( $allowed, SORT_STRING );
		$tools = $restrict ? $toolsets : array_keys( $available );
		if ( array() === $allowed ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$tools = array_values( array_unique( $tools ) );
		sort( $tools, SORT_STRING );
		return array(
			'toolsets' => $tools,
			'allowed'  => $allowed,
		);
	}

	/**
	 * Build only a narrowing patch from the existing scope.
	 *
	 * @param SiteScope           $old Existing scope.
	 * @param array<string,mixed> $input Patch fields.
	 * @return SiteScope|WP_Error
	 */
	private function narrowed_scope( SiteScope $old, array $input ): SiteScope|WP_Error {
		$permissions = array_key_exists( 'permissions', $input ) ? $this->string_list( $input['permissions'] ) : $old->permissions;
		$toolsets    = array_key_exists( 'toolsets', $input ) ? $this->string_list( $input['toolsets'] ) : $old->toolsets;
		$allow       = array_key_exists( 'ability_allow', $input ) ? $this->string_list( $input['ability_allow'] ) : $old->allowed_abilities;
		$deny        = array_key_exists( 'ability_deny', $input ) ? $this->string_list( $input['ability_deny'] ) : $old->denied_abilities;
		$ips         = array_key_exists( 'ip_allowlist', $input ) ? $this->string_list( $input['ip_allowlist'] ) : $old->ip_allowlist;
		if ( is_wp_error( $permissions ) || is_wp_error( $toolsets ) || is_wp_error( $allow ) || is_wp_error( $deny ) || is_wp_error( $ips ) ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		if ( array() === $allow || array() !== array_diff( $permissions, $old->permissions ) ) {
			return $this->error( 'scope_expansion', 409 );
		}
		$expires = $old->expires_at;
		if ( array_key_exists( 'expires_in_days', $input ) ) {
			$days = $input['expires_in_days'];
			if ( ! is_int( $days ) || $days < 1 || $days > (int) $this->settings->get( 'max_key_lifetime_days' ) ) {
				return $this->error( 'invalid_site_key', 400 );
			}
			$expires = new DateTimeImmutable( '@' . ( $this->clock->now() + $days * DAY_IN_SECONDS ) );
		}
		try {
			return new SiteScope( $old->tier_ceiling, $toolsets, $allow, $deny, $permissions, $ips, $expires );
		} catch ( \InvalidArgumentException ) {
			return $this->error( 'invalid_site_key', 400 );
		}
	}

	/**
	 * Insert one credential under the held lock with bounded collision retries.
	 *
	 * @param int       $manager Manager identity.
	 * @param string    $name Display name.
	 * @param SiteScope $scope Reviewed restrictions.
	 * @return IssuedSiteKey|WP_Error
	 */
	private function issue( int $manager, string $name, SiteScope $scope ): IssuedSiteKey|WP_Error {
		$pepper = $this->peppers->current();
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$generated = $this->codec->generate();
			try {
				$key = $this->keys->insert( $generated->id, $scope, $name, $generated->hash_with( $pepper ), $pepper->id, $manager );
				return new IssuedSiteKey( $key, $generated );
			} catch ( KeyCollision ) {
				continue;
			}
		}
		return $this->error( 'unavailable', 503 );
	}

	/**
	 * Insert one replacement while keeping the old row in its grace window.
	 *
	 * @param SiteCredential $old Old credential.
	 * @param SiteScope      $grace Narrowed old expiry.
	 * @param int            $manager Rotating manager.
	 * @return IssuedSiteKey|WP_Error
	 */
	private function rotate_issue( SiteCredential $old, SiteScope $grace, int $manager ): IssuedSiteKey|WP_Error {
		$pepper    = $this->peppers->current();
		$generated = $this->codec->generate();
		try {
			$replacement = $this->keys->insert_rotation( $old, $grace, $generated->id, $generated->hash_with( $pepper ), $pepper->id, $manager );
			return new IssuedSiteKey( $replacement, $generated );
		} catch ( KeyCollision ) {
			return $this->error( 'unavailable', 503 );
		} catch ( StorageException ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Rate-limit and verify the current password without retaining it.
	 *
	 * @param int    $manager Manager identity.
	 * @param string $password Current password.
	 * @return true|WP_Error
	 */
	private function reauthenticate( int $manager, #[\SensitiveParameter] string $password ): true|WP_Error {
		$budget = $this->limits->check( 'key-password', (string) $manager, self::PASSWORD_ATTEMPTS );
		if ( ! $budget->allowed ) {
			return $this->error( 'rate_limited', 429 );
		}
		$user = get_userdata( $manager );
		if ( false === $user || ! wp_check_password( $password, $user->user_pass, $manager ) ) {
			return $this->error( 'reauthentication_required', 403 );
		}
		return true;
	}

	/**
	 * Validate one bounded unique string list.
	 *
	 * @param mixed $value Candidate list.
	 * @return array<string>|WP_Error
	 */
	private function string_list( mixed $value ): array|WP_Error {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 ) {
			return $this->error( 'invalid_site_key', 400 );
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item || strlen( $item ) > 191 ) {
				return $this->error( 'invalid_site_key', 400 );
			}
			$items[] = $item;
		}
		$items = array_values( array_unique( $items ) );
		sort( $items, SORT_STRING );
		return $items;
	}

	/**
	 * Produce an error containing only a fixed safe reason.
	 *
	 * @param string $reason Fixed internal code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private function error( string $reason, int $status ): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_' . $reason, 'The site key operation is unavailable.', array( 'status' => $status ) );
	}
}
