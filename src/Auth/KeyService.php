<?php
/**
 * User-owned credential lifecycle services.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use DateTimeImmutable;
use WP_Error;
use XNetVN\AgentHubWP\Contracts\AbilityCatalog;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\OwnerLock;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\KeyCollision;
use XNetVN\AgentHubWP\Support\RequestMetadata;

/** Rechecks ownership, current permissions and quota for every mutation. */
final class KeyService {
	/**
	 * Bind lifecycle dependencies.
	 *
	 * @param KeyRepository  $keys Persistence.
	 * @param PepperStore    $peppers Opaque pepper.
	 * @param KeyGenerator   $generator Random credentials.
	 * @param SiteSettings   $settings Site policy.
	 * @param AbilityCatalog $catalog Reviewed definitions.
	 * @param RateLimiter    $limits Reauthentication budget.
	 * @param OwnerLock      $lock Shared mutation lock.
	 * @param Clock          $clock UTC clock.
	 */
	public function __construct( private KeyRepository $keys, private PepperStore $peppers, private KeyGenerator $generator, private SiteSettings $settings, private AbilityCatalog $catalog, private RateLimiter $limits, private OwnerLock $lock, private Clock $clock ) {
	}

	/**
	 * Create a credential only for the current user.
	 *
	 * @param int                 $user_id Owner.
	 * @param array<string,mixed> $input Validated management fields.
	 * @return IssuedKey|WP_Error
	 */
	public function create( int $user_id, #[\SensitiveParameter] array $input ): IssuedKey|WP_Error {
		try {
			if ( get_current_user_id() !== $user_id || ! user_can( $user_id, Capabilities::USE ) ) {
				return $this->error( 'forbidden', 403 );
			}
			return $this->lock->run(
				$user_id,
				function () use ( $user_id, $input ): IssuedKey|WP_Error {
					$validated = $this->validate( $user_id, $input );
					if ( is_wp_error( $validated ) ) {
						return $validated;
					}
					$password = $this->reauthenticate( $user_id, $validated['scope']->tier_max, $input['current_password'] ?? null );
					return is_wp_error( $password ) ? $password : $this->issue( $user_id, $validated['name'], $validated['scope'] );
				}
			);
		} catch ( \InvalidArgumentException ) {
			return $this->error( 'invalid_key', 400 );
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Generate a replacement only through the repository's committed rotation boundary.
	 *
	 * @param ApiKey $old Current record.
	 * @param Scope  $grace Old narrowed expiry.
	 * @return IssuedKey
	 * @throws \RuntimeException For exhausted identifier generation.
	 * @see KeyRepository::insert_rotation() For transaction storage failures.
	 */
	private function issue_rotation( ApiKey $old, Scope $grace ): IssuedKey {
		$pepper = $this->peppers->current();
		$peer   = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated metadata only, not authorization.
		$peer   = RequestMetadata::ip( $peer, true === $this->settings->get( 'anonymize_ip' ) );
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$generated = $this->generator->generate();
			try {
				$key = $this->keys->insert_rotation( $old, $grace, $generated->id, $generated->hash_with( $pepper ), $pepper->id, $peer );
				return new IssuedKey( $key, $generated );
			} catch ( KeyCollision ) {
				continue;
			}
		}
		throw new \RuntimeException( 'Credential identifier generation is unavailable.' );
	}

	/**
	 * Read only authorized non-secret metadata.
	 *
	 * @param int $id Record.
	 * @return ApiKey|WP_Error
	 */
	public function get( int $id ): ApiKey|WP_Error {
		try {
			$key = $this->keys->find_by_id( $id );
			return null !== $key && $this->owned( $key ) ? $key : $this->error( 'not_found', 404 );
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Apply only a monotone restriction and an optional display name.
	 *
	 * @param int                 $id Record.
	 * @param array<string,mixed> $input Patch.
	 * @return ApiKey|WP_Error
	 */
	public function patch( int $id, array $input ): ApiKey|WP_Error {
		$key = $this->get( $id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		try {
			return $this->lock->run(
				$key->user_id,
				function () use ( $id, $input ): ApiKey|WP_Error {
					$fresh = $this->get( $id );
					if ( is_wp_error( $fresh ) || $fresh->is_revoked() ) {
						return $this->error( 'not_found', 404 );
					}
					if ( array_key_exists( 'current_password', $input ) ) {
						return $this->error( 'invalid_key', 400 );
					}
					$validated = $this->validate( $fresh->user_id, $input, $fresh );
					if ( is_wp_error( $validated ) ) {
						return $validated;
					}
					if ( ! $validated['scope']->is_narrower_than( $fresh->scope ) || ! $this->keys->update_key( $id, $validated['name'], $validated['scope'] ) ) {
						return $this->error( 'scope_expansion', 409 );
					}
					return $this->get( $id );
				}
			);
		} catch ( \InvalidArgumentException ) {
			return $this->error( 'invalid_key', 400 );
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Revoke an owned credential or one managed by the site administrator.
	 *
	 * @param int $id Record.
	 * @return bool|WP_Error
	 */
	public function revoke( int $id ): bool|WP_Error {
		$key = $this->get( $id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		try {
			$reason = get_current_user_id() === $key->user_id ? 'owner_request' : 'administrator_request';
			$result = $this->keys->revoke( $id, get_current_user_id(), $reason );
			if ( $result ) {
				do_action( 'xnetvn_agent_hub_wp_key_revoked', $key, get_current_user_id(), $reason );
			}
			return $result;
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Rotate only the owner's key; inherit scope and narrow the old grace period.
	 *
	 * @param int                 $id Old record.
	 * @param array<string,mixed> $input Grace and reauthentication.
	 * @return IssuedKey|WP_Error
	 */
	public function rotate( int $id, #[\SensitiveParameter] array $input ): IssuedKey|WP_Error {
		$key = $this->get( $id );
		if ( is_wp_error( $key ) || get_current_user_id() !== $key->user_id ) {
			return $this->error( 'not_found', 404 );
		}
		try {
			if ( array_diff( array_keys( $input ), array( 'current_password', 'grace_hours' ) ) || ( isset( $input['grace_hours'] ) && ( ! is_int( $input['grace_hours'] ) || $input['grace_hours'] < 0 || $input['grace_hours'] > 24 ) ) ) {
				return $this->error( 'invalid_key', 400 );
			}
			return $this->lock->run(
				$key->user_id,
				function () use ( $id, $input ): IssuedKey|WP_Error {
					$fresh = $this->get( $id );
					if ( is_wp_error( $fresh ) || $fresh->is_revoked() || ( null !== $fresh->scope->expires_at && $fresh->scope->expires_at->getTimestamp() <= $this->clock->now() ) ) {
						return $this->error( 'not_found', 404 );
					}
					$check = $this->validate( $fresh->user_id, array(), $fresh );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
					$password = $this->reauthenticate( $fresh->user_id, $fresh->scope->tier_max, $input['current_password'] ?? null );
					if ( is_wp_error( $password ) ) {
						return $password;
					}
					if ( $this->keys->count_active_for_user( $fresh->user_id ) >= (int) $this->settings->get( 'max_keys_per_user' ) ) {
						return $this->error( 'key_limit', 409 );
					}
					$grace = new DateTimeImmutable( '@' . ( $this->clock->now() + ( $input['grace_hours'] ?? 24 ) * HOUR_IN_SECONDS ) );
					$old   = $fresh->scope;
					$until = null !== $old->expires_at && $old->expires_at < $grace ? $old->expires_at : $grace;
					$scope = new Scope( $old->tier_max, $old->toolsets, $old->ability_allow, $old->ability_deny, $old->ip_allowlist, $until );
					$new   = $this->issue_rotation( $fresh, $scope );
					do_action( 'xnetvn_agent_hub_wp_key_created', $new->key );
					return $new;
				}
			);
		} catch ( \Throwable ) {
			return $this->error( 'unavailable', 503 );
		}
	}

	/**
	 * Validate exact field types and intersect delegation with current rights.
	 *
	 * @param int                 $user_id Owner.
	 * @param array<string,mixed> $input Fields.
	 * @param ApiKey|null         $old Existing key for a patch.
	 * @return array{name:string,scope:Scope}|WP_Error
	 */
	private function validate( int $user_id, #[\SensitiveParameter] array $input, ?ApiKey $old = null ): array|WP_Error {
		if ( array_diff( array_keys( $input ), array( 'name', 'tier_max', 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist', 'expires_in_days', 'current_password' ) ) ) {
			return $this->error( 'invalid_key', 400 );
		}
		$name = array_key_exists( 'name', $input ) ? $input['name'] : $old?->name;
		$tier = array_key_exists( 'tier_max', $input ) ? $input['tier_max'] : ( $old?->scope->tier_max->value ?? 'read' );
		if ( ! is_string( $name ) || '' === trim( $name ) || 1 !== preg_match( '/\A.{1,100}\z/us', $name ) || ! is_string( $tier ) || null === RiskTier::tryFrom( $tier ) ) {
			return $this->error( 'invalid_key', 400 );
		}
		$tier    = RiskTier::from( $tier );
		$ceiling = RiskTier::from( (string) $this->settings->get( 'tier_ceiling' ) );
		$cap     = match ( $tier ) {
			RiskTier::Read => Capabilities::USE,
			RiskTier::Write => 'edit_posts',
			RiskTier::Destructive => 'delete_posts',
			RiskTier::Admin => Capabilities::MANAGE,
		};
		if ( ! $tier->atMost( $ceiling ) || ! user_can( $user_id, $cap ) || ( RiskTier::Admin === $tier && true !== $this->settings->get( 'allow_admin_tier' ) ) ) {
			return $this->error( 'forbidden', 403 );
		}
		$lists = array();
		foreach ( array( 'toolsets', 'ability_allow', 'ability_deny', 'ip_allowlist' ) as $field ) {
			$value = array_key_exists( $field, $input ) ? $input[ $field ] : ( null === $old ? array() : $old->scope->$field );
			if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 || array_filter( $value, static fn( mixed $item ): bool => ! is_string( $item ) ) ) {
				return $this->error( 'invalid_key', 400 );
			}
			$lists[ $field ] = $value;
		}
		$available = array( 'system' => true );
		$abilities = array();
		foreach ( $this->catalog->all() as $definition ) {
			$descriptor                     = $definition->descriptor();
			$abilities[ $descriptor->name ] = true;
			if ( $descriptor->tier->atMost( $tier ) && user_can( $user_id, $descriptor->visibility_cap ) ) {
				$available[ $descriptor->toolset ] = true;
			}
		}
		if ( array_diff( $lists['toolsets'], array_keys( $available ) ) || array_diff( array_merge( $lists['ability_allow'], $lists['ability_deny'] ), array_keys( $abilities ) ) ) {
			return $this->error( 'invalid_key', 400 );
		}
		$expires = $old?->scope->expires_at;
		if ( array_key_exists( 'expires_in_days', $input ) ) {
			$days = $input['expires_in_days'];
			if ( null !== $days && ( ! is_int( $days ) || $days < 1 || $days > (int) $this->settings->get( 'max_key_lifetime_days' ) ) ) {
				return $this->error( 'invalid_key', 400 );
			}
			$expires = null === $days ? null : new DateTimeImmutable( '@' . ( $this->clock->now() + $days * DAY_IN_SECONDS ) );
		} elseif ( null === $old ) {
			$expires = new DateTimeImmutable( '@' . ( $this->clock->now() + min( 90, (int) $this->settings->get( 'max_key_lifetime_days' ) ) * DAY_IN_SECONDS ) );
		}
		if ( ! $tier->atMost( RiskTier::Write ) && null === $expires ) {
			return $this->error( 'invalid_key', 400 );
		}
		return array(
			'name'  => $name,
			'scope' => new Scope( $tier, $lists['toolsets'], $lists['ability_allow'], $lists['ability_deny'], $lists['ip_allowlist'], $expires ),
		);
	}

	/**
	 * Rate-limit a fresh password check for high risk issuance.
	 *
	 * @param int      $user_id Owner.
	 * @param RiskTier $tier Requested risk.
	 * @param mixed    $password Submitted password; never retained or logged.
	 * @return true|WP_Error
	 */
	private function reauthenticate( int $user_id, RiskTier $tier, #[\SensitiveParameter] mixed $password ): true|WP_Error {
		if ( $tier->atMost( RiskTier::Write ) ) {
			return true;
		}
		$budget = $this->limits->check( 'key-password', (string) $user_id, 5 );
		if ( ! $budget->allowed ) {
			return $this->error( 'rate_limited', 429 );
		}
		$user = get_userdata( $user_id );
		if ( false === $user || ! is_string( $password ) || '' === $password || strlen( $password ) > 4096 || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			return $this->error( 'reauthentication_required', 403 );
		}
		return true;
	}

	/**
	 * Insert under the already-held owner lock with bounded collision retries.
	 *
	 * @param int    $user_id Owner.
	 * @param string $name Display name.
	 * @param Scope  $scope Validated restrictions.
	 * @return IssuedKey|WP_Error
	 */
	private function issue( int $user_id, string $name, Scope $scope ): IssuedKey|WP_Error {
		if ( ! user_can( $user_id, Capabilities::USE ) || $this->keys->count_active_for_user( $user_id ) >= (int) $this->settings->get( 'max_keys_per_user' ) ) {
			return $this->error( 'key_limit', 409 );
		}
		$pepper = $this->peppers->current();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Pure metadata helper validates and masks this peer; it never changes authorization.
		$peer = RequestMetadata::ip( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null, true === $this->settings->get( 'anonymize_ip' ) );
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$generated = $this->generator->generate();
			try {
				$key = $this->keys->insert( $generated->id, $user_id, $name, $scope, $generated->hash_with( $pepper ), $pepper->id, $peer );
				do_action( 'xnetvn_agent_hub_wp_key_created', $key );
				return new IssuedKey( $key, $generated );
			} catch ( KeyCollision ) {
				continue;
			}
		}
		return $this->error( 'unavailable', 503 );
	}

	/**
	 * Check current ownership or site management without leaking row existence.
	 *
	 * @param ApiKey $key Safe metadata.
	 * @return bool
	 */
	private function owned( ApiKey $key ): bool {
		return get_current_user_id() > 0 && ( get_current_user_id() === $key->user_id || current_user_can( Capabilities::MANAGE ) );
	}

	/**
	 * Produce an error containing only a fixed safe reason.
	 *
	 * @param string $reason Fixed internal code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private function error( string $reason, int $status ): WP_Error {
		return new WP_Error( 'xnetvn_agent_hub_wp_' . $reason, 'The key operation is unavailable.', array( 'status' => $status ) );
	}
}
