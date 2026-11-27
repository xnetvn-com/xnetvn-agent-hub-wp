<?php
/**
 * Bounded editorial policy for an accountless website credential.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Domain;

use DateTimeImmutable;
use DateTimeZone;
use XNetVN\AgentHubWP\Support\Cidr;

/** A machine scope may only narrow and can never become a wildcard. */
final readonly class SiteScope {

	/** Fixed reviewed editorial profile. */
	public const PROFILE = 'editorial-v1';
	/** Permission to suspend a user on the current site. */
	public const PERMISSION_BAN_USERS = 'ban-users';
	/** Permission to restore a validated suspended user. */
	public const PERMISSION_UNBAN_USERS = 'unban-users';
	/** Maximum CIDR entries accepted from a manager. */
	public const MAX_IP_ALLOWLIST = 100;

	/** Reviewed permission set. */
	private const PERMISSIONS = array( self::PERMISSION_BAN_USERS, self::PERMISSION_UNBAN_USERS );

	/** Fixed profile name.
	 *
	 * @var string
	 */
	public string $profile;
	/** Enabled toolsets including system.
	 *
	 * @var array<string>
	 */
	public array $toolsets;
	/** Explicit non-empty allowed abilities.
	 *
	 * @var array<string>
	 */
	public array $allowed_abilities;
	/** Explicit denied abilities.
	 *
	 * @var array<string>
	 */
	public array $denied_abilities;
	/** Optional moderation permissions.
	 *
	 * @var array<string>
	 */
	public array $permissions;
	/** Allowed IP networks.
	 *
	 * @var array<string>
	 */
	public array $ip_allowlist;
	/** Maximum risk tier.
	 *
	 * @var RiskTier
	 */
	public RiskTier $tier_ceiling;
	/** Mandatory UTC expiry.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $expires_at;

	/**
	 * Validate and normalize a bounded editorial scope.
	 *
	 * @param RiskTier               $tier_ceiling Maximum risk.
	 * @param array<string>          $toolsets Enabled packs.
	 * @param array<string>          $allowed_abilities Explicit non-empty allowlist.
	 * @param array<string>          $denied_abilities Explicit denylist.
	 * @param array<string>          $permissions Moderation permissions.
	 * @param array<string>          $ip_allowlist CIDR constraints.
	 * @param DateTimeImmutable|null $expires_at Mandatory expiry.
	 * @throws \InvalidArgumentException For invalid or widening scopes.
	 */
	public function __construct(
		RiskTier $tier_ceiling,
		array $toolsets,
		array $allowed_abilities,
		array $denied_abilities = array(),
		array $permissions = array(),
		array $ip_allowlist = array(),
		?DateTimeImmutable $expires_at = null
	) {
		foreach ( $toolsets as $toolset ) {
			new Toolset( $toolset );
		}
		foreach ( array_merge( $allowed_abilities, $denied_abilities ) as $ability ) {
			self::assert_ability( $ability );
		}
		foreach ( $permissions as $permission ) {
			if ( ! in_array( $permission, self::PERMISSIONS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid site permission.' );
			}
		}
		if ( count( $ip_allowlist ) > self::MAX_IP_ALLOWLIST ) {
			throw new \InvalidArgumentException( 'Invalid site IP allowlist.' );
		}
		foreach ( $ip_allowlist as $cidr ) {
			if ( ! Cidr::valid( $cidr ) ) {
				throw new \InvalidArgumentException( 'Invalid site IP allowlist.' );
			}
		}
		if ( null === $expires_at ) {
			throw new \InvalidArgumentException( 'Invalid site scope expiry.' );
		}

		$this->profile           = self::PROFILE;
		$this->toolsets          = self::normalized( array_merge( $toolsets, array( 'system' ) ) );
		$this->allowed_abilities = self::normalized( $allowed_abilities );
		$this->denied_abilities  = self::normalized( $denied_abilities );
		$this->permissions       = self::normalized( $permissions );
		$this->ip_allowlist      = self::normalized( $ip_allowlist );
		$this->tier_ceiling      = $tier_ceiling;
		$this->expires_at        = $expires_at->setTimezone( new DateTimeZone( 'UTC' ) );

		if ( array() === $this->allowed_abilities ) {
			throw new \InvalidArgumentException( 'Invalid site scope allowlist.' );
		}
		if ( array() !== $this->permissions && ( RiskTier::Admin !== $this->tier_ceiling || ! in_array( 'users', $this->toolsets, true ) || ! in_array( 'xnetvn/set-user-status', $this->allowed_abilities, true ) || in_array( 'xnetvn/set-user-status', $this->denied_abilities, true ) ) ) {
			throw new \InvalidArgumentException( 'Invalid moderation scope: it must grant only the admin moderation ability.' );
		}
	}

	/**
	 * Determine whether an update only narrows the previous scope.
	 *
	 * @param self $old Previous scope.
	 * @return bool
	 */
	public function is_narrower_than( self $old ): bool {
		if ( array_diff( $this->toolsets, $old->toolsets ) || array_diff( $this->allowed_abilities, $old->allowed_abilities ) ) {
			return false;
		}
		if ( array_diff( $old->denied_abilities, $this->denied_abilities ) || array_diff( $this->permissions, $old->permissions ) ) {
			return false;
		}
		if ( ! $this->tier_ceiling->atMost( $old->tier_ceiling ) || $this->expires_at > $old->expires_at ) {
			return false;
		}
		if ( $old->ip_allowlist ) {
			if ( ! $this->ip_allowlist ) {
				return false;
			}
			foreach ( $this->ip_allowlist as $cidr ) {
				if ( ! Cidr::covered_by( $cidr, $old->ip_allowlist ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Serialize the reviewed scope.
	 *
	 * @return array<string,array<string>|string>
	 */
	public function to_array(): array {
		return array(
			'profile'           => $this->profile,
			'toolsets'          => $this->toolsets,
			'allowed_abilities' => $this->allowed_abilities,
			'denied_abilities'  => $this->denied_abilities,
			'permissions'       => $this->permissions,
			'ip_allowlist'      => $this->ip_allowlist,
			'tier_ceiling'      => $this->tier_ceiling->value,
			'expires_at'        => $this->expires_at->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Rebuild a scope from strict stored data.
	 *
	 * @param array<string,mixed> $data Stored scope.
	 * @return self
	 * @throws \InvalidArgumentException For corrupt or unknown data.
	 */
	public static function from_array( array $data ): self {
		$expected = array( 'profile', 'toolsets', 'allowed_abilities', 'denied_abilities', 'permissions', 'ip_allowlist', 'tier_ceiling', 'expires_at' );
		$keys     = array_keys( $data );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) {
			throw new \InvalidArgumentException( 'Invalid site scope fields.' );
		}
		if ( self::PROFILE !== $data['profile'] ) {
			throw new \InvalidArgumentException( 'Invalid site scope profile.' );
		}
		$tier = is_string( $data['tier_ceiling'] ) ? RiskTier::tryFrom( $data['tier_ceiling'] ) : null;
		if ( null === $tier ) {
			throw new \InvalidArgumentException( 'Invalid site scope tier.' );
		}
		$expires = self::parse_expiry( $data['expires_at'] );

		return new self(
			$tier,
			self::string_list( $data['toolsets'] ),
			self::string_list( $data['allowed_abilities'] ),
			self::string_list( $data['denied_abilities'] ),
			self::string_list( $data['permissions'] ),
			self::string_list( $data['ip_allowlist'] ),
			$expires
		);
	}

	/**
	 * Reject malformed canonical ability names.
	 *
	 * @param string $ability Canonical name.
	 * @throws \InvalidArgumentException For invalid or corrupt data.
	 * @return void
	 */
	private static function assert_ability( string $ability ): void {
		if ( 1 !== preg_match( '/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $ability ) ) {
			throw new \InvalidArgumentException( 'Invalid site ability scope.' );
		}
	}

	/**
	 * Return unique, sorted scope elements.
	 *
	 * @param array<string> $values Scope elements.
	 * @return array<string>
	 */
	private static function normalized( array $values ): array {
		$values = array_values( array_unique( $values ) );
		sort( $values, SORT_STRING );
		return $values;
	}

	/**
	 * Validate one stored string list.
	 *
	 * @param mixed $value Stored value.
	 * @throws \InvalidArgumentException For invalid or corrupt data.
	 * @return array<string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid site scope list.' );
		}
		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new \InvalidArgumentException( 'Invalid site scope item.' );
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Parse the mandatory UTC expiry.
	 *
	 * @param mixed $value Stored value.
	 * @throws \InvalidArgumentException For invalid or corrupt data.
	 * @return DateTimeImmutable
	 */
	private static function parse_expiry( mixed $value ): DateTimeImmutable {
		if ( ! is_string( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid site scope expiry.' );
		}
		$expires = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $value, new DateTimeZone( 'UTC' ) );
		if ( false === $expires || $expires->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			throw new \InvalidArgumentException( 'Invalid site scope expiry.' );
		}
		return $expires;
	}
}
