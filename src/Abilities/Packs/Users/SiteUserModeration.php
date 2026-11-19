<?php
/**
 * Directional machine ban and unban for one current-site member.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use Closure;
use WP_Error;
use WP_User;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteScope;

/** User records, roles, filters and stored snapshots are untrusted data. */
final class SiteUserModeration {

	/**
	 * Return the only machine handler for the moderation operation.
	 *
	 * @return Closure(array<string,mixed>,SiteAuthContext):mixed
	 */
	public static function handler(): Closure {
		return static fn( array $arguments, SiteAuthContext $context ): mixed => ( new self() )->execute( $arguments, $context );
	}

	/**
	 * Run one reviewed machine moderation request without a cookie identity.
	 *
	 * @param array<string,mixed> $arguments Validated logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	public function execute( array $arguments, SiteAuthContext $context ): mixed {
		if ( $context->blog_id() !== get_current_blog_id() || 0 !== get_current_user_id() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		try {
			return $this->dispatch( $arguments, $context );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * Require the exact directional grant before any target work.
	 *
	 * @param array<string,mixed> $arguments Validated logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	private function dispatch( array $arguments, SiteAuthContext $context ): mixed {
		$status = isset( $arguments['status'] ) ? (string) $arguments['status'] : '';
		if ( ! in_array( $status, array( 'banned', 'active' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		$permission = 'banned' === $status ? SiteScope::PERMISSION_BAN_USERS : SiteScope::PERMISSION_UNBAN_USERS;
		if ( ! $context->has_permission( $permission ) ) {
			return R::error( 'forbidden' );
		}
		return $this->validated( $arguments, $context, $status );
	}

	/**
	 * Apply the cross-field rules and the current-site target proof.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @param string              $status Requested status.
	 * @return mixed
	 */
	private function validated( array $arguments, SiteAuthContext $context, string $status ): mixed {
		if ( array_key_exists( 'restore_role', $arguments ) ) {
			return R::error( 'invalid_params' );
		}
		$reason = 'other';
		if ( 'active' === $status ) {
			if ( array_key_exists( 'reason_code', $arguments ) ) {
				return R::error( 'invalid_params' );
			}
		} else {
			$reason = isset( $arguments['reason_code'] ) ? (string) $arguments['reason_code'] : 'other';
			if ( ! in_array( $reason, BanSnapshot::REASONS, true ) ) {
				return R::error( 'invalid_params' );
			}
		}
		$id = isset( $arguments['id'] ) ? (int) $arguments['id'] : 0;
		if ( $id < 1 ) {
			return R::error( 'invalid_params' );
		}
		$target = get_userdata( $id );
		if ( ! $target instanceof WP_User ) {
			return R::error( 'not_found' );
		}
		if ( ! is_user_member_of_blog( $id, get_current_blog_id() ) ) {
			return R::error( 'not_found' );
		}
		$guarded = $this->guarded( $id );
		if ( null !== $guarded ) {
			return $guarded;
		}
		$previous = in_array( 'banned', (array) $target->roles, true ) ? 'banned' : 'active';
		if ( $previous === $status ) {
			$unchanged = $this->unchanged( $status, $target );
			if ( null !== $unchanged ) {
				return $unchanged;
			}
		}
		if ( 'banned' === $status ) {
			return $this->ban( $id, $reason, $previous, $context );
		}
		return $this->unban( $id, $previous );
	}

	/**
	 * Re-evaluate network and target protection without caching.
	 *
	 * @param int $id Target.
	 * @return WP_Error|null
	 */
	private function guarded( int $id ): ?WP_Error {
		$network = ( new NetworkUserProtection() )->evaluate( $id );
		if ( ! $network->allowed ) {
			return R::error( 'target_missing' === $network->reason ? 'not_found' : 'protected_account' );
		}
		$state = UserTargetGuard::evaluate( $id );
		if ( 'missing' === $state ) {
			return R::error( 'not_found' );
		}
		if ( 'allowed' !== $state ) {
			return R::error( 'protected_account' );
		}
		return null;
	}

	/**
	 * Return the no-write receipt or the retained-recovery conflict.
	 *
	 * @param string  $status Requested status.
	 * @param WP_User $target Fresh target.
	 * @return mixed|null
	 */
	private function unchanged( string $status, WP_User $target ): mixed {
		$id = (int) $target->ID;
		if ( 'banned' === $status ) {
			if ( array( 'banned' ) === array_values( (array) $target->roles ) && $this->effective_caps_empty( $target ) ) {
				return $this->result( $id, 'banned', 'banned', false, array( 'banned' ) );
			}
			return null;
		}
		// An active member with a retained recovery point is a conflict, never a clean no-op.
		if ( null !== BanSnapshot::load( $id ) ) {
			return R::error( 'invalid_state' );
		}
		return $this->result( $id, 'active', 'active', false, array_values( (array) $target->roles ) );
	}

	/**
	 * Suspend one eligible member on the current site only.
	 *
	 * @param int             $id Target.
	 * @param string          $reason Validated reason code.
	 * @param string          $previous Previous status.
	 * @param SiteAuthContext $context Verified machine identity.
	 * @return mixed
	 */
	private function ban( int $id, string $reason, string $previous, SiteAuthContext $context ): mixed {
		$target = get_userdata( $id );
		if ( ! $target instanceof WP_User ) {
			return R::error( 'not_found' );
		}
		$snapshot = BanSnapshot::build_for_site( $target, $context, $reason );
		if ( ! BanSnapshot::validate( $snapshot ) ) {
			return R::error( 'invalid_state' );
		}
		$created = false;
		if ( ! BanSnapshot::save( $id, $snapshot, $created ) ) {
			return R::error( 'internal_error' );
		}
		$guarded = $this->guarded( $id );
		if ( null !== $guarded ) {
			$this->discard_created_snapshot( $id, $created );
			return $guarded;
		}
		$ensured = RoleGuard::ensure_banned_role();
		if ( is_wp_error( $ensured ) ) {
			$this->discard_created_snapshot( $id, $created );
			return $ensured;
		}
		$remaining = array();
		try {
			$fresh = get_userdata( $id );
			if ( $fresh instanceof WP_User ) {
				$fresh->set_role( 'banned' );
			}
			$current = get_userdata( $id );
			if ( $current instanceof WP_User ) {
				foreach ( array_keys( (array) ( $snapshot['caps'] ?? array() ) ) as $cap ) {
					$current->remove_cap( (string) $cap );
				}
			}
			if ( ! class_exists( 'WP_Session_Tokens' ) ) {
				return new PartialFailure( $id, false );
			}
			$sessions = \WP_Session_Tokens::get_instance( $id );
			$sessions->destroy_all();
			$remaining = $sessions->get_all();
		} catch ( \Throwable ) {
			return new PartialFailure( $id, false );
		}
		clean_user_cache( $id );
		$after = get_userdata( $id );
		if ( ! $after instanceof WP_User || array( 'banned' ) !== array_values( (array) $after->roles ) ) {
			return new PartialFailure( $id, false );
		}
		if ( array() !== $remaining || ! $this->effective_caps_empty( $after ) ) {
			return new PartialFailure( $id, false );
		}
		return MutationResult::project( $id, false, fn(): array => $this->result( $id, 'banned', $previous, true, array( 'banned' ) ) );
	}

	/**
	 * Delete only the snapshot this invocation inserted while no ban is visible.
	 *
	 * A reused snapshot belongs to an earlier ban, and a confirmed banned role
	 * may come from a concurrent ban that depends on the stored record. Neither
	 * case may be cleared by a refused attempt.
	 *
	 * @param int  $id Target.
	 * @param bool $created Whether this invocation inserted the stored snapshot.
	 * @return void
	 */
	private function discard_created_snapshot( int $id, bool $created ): void {
		if ( ! $created ) {
			return;
		}
		$fresh = get_userdata( $id );
		if ( $fresh instanceof WP_User && in_array( 'banned', (array) $fresh->roles, true ) ) {
			return;
		}
		BanSnapshot::clear( $id );
	}

	/**
	 * Restore one member from a validated recovery snapshot only.
	 *
	 * @param int    $id Target.
	 * @param string $previous Previous status.
	 * @return mixed
	 */
	private function unban( int $id, string $previous ): mixed {
		$snapshot = BanSnapshot::load( $id );
		if ( null === $snapshot ) {
			return R::error( 'invalid_state' );
		}
		$roles = array();
		foreach ( (array) ( $snapshot['roles'] ?? array() ) as $slug ) {
			$role = (string) $slug;
			if ( 'banned' === $role || ! RoleGuard::assignable( $role ) ) {
				return R::error( 'invalid_state' );
			}
			$roles[] = $role;
		}
		if ( array() === $roles ) {
			return R::error( 'invalid_state' );
		}
		$privileged = RoleGuard::privileged_caps();
		$caps       = array();
		foreach ( (array) ( $snapshot['caps'] ?? array() ) as $cap => $enabled ) {
			if ( in_array( (string) $cap, $privileged, true ) ) {
				return R::error( 'invalid_state' );
			}
			$caps[ (string) $cap ] = (bool) $enabled;
		}
		$guarded = $this->guarded( $id );
		if ( null !== $guarded ) {
			return $guarded;
		}
		try {
			$fresh = get_userdata( $id );
			if ( ! $fresh instanceof WP_User ) {
				return R::error( 'not_found' );
			}
			$fresh->set_role( $roles[0] );
			foreach ( array_slice( $roles, 1 ) as $extra ) {
				$fresh->add_role( $extra );
			}
			foreach ( $caps as $cap => $enabled ) {
				$fresh->add_cap( $cap, $enabled );
			}
		} catch ( \Throwable ) {
			return new PartialFailure( $id, false );
		}
		clean_user_cache( $id );
		$after = get_userdata( $id );
		if ( ! $after instanceof WP_User || ! $this->restored( $after, $roles, $caps ) ) {
			return new PartialFailure( $id, false );
		}
		BanSnapshot::clear( $id );
		if ( null !== BanSnapshot::load( $id ) ) {
			return new PartialFailure( $id, false );
		}
		$current = array_values( (array) $after->roles );
		return MutationResult::project( $id, false, fn(): array => $this->result( $id, 'active', $previous, true, $current ) );
	}

	/**
	 * Whether the stored account state matches the requested restore.
	 *
	 * @param WP_User            $after Fresh target.
	 * @param array<string>      $roles Requested roles.
	 * @param array<string,bool> $caps Requested direct capabilities.
	 * @return bool
	 */
	private function restored( WP_User $after, array $roles, array $caps ): bool {
		$actual = array_values( array_unique( array_map( 'strval', (array) $after->roles ) ) );
		$wanted = array_values( array_unique( $roles ) );
		sort( $actual );
		sort( $wanted );
		if ( $actual !== $wanted ) {
			return false;
		}
		$direct = is_array( $after->caps ) ? $after->caps : array();
		foreach ( $caps as $cap => $enabled ) {
			if ( ! array_key_exists( $cap, $direct ) || (bool) $direct[ $cap ] !== $enabled ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether effective capabilities are empty except the banned role.
	 *
	 * @param WP_User $user Target.
	 * @return bool
	 */
	private function effective_caps_empty( WP_User $user ): bool {
		foreach ( (array) $user->allcaps as $cap => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}
			if ( 'banned' === (string) $cap ) {
				continue;
			}
			return false;
		}
		return true;
	}

	/**
	 * Build one schema-valid moderation receipt.
	 *
	 * @param int           $id Target.
	 * @param string        $status Resulting status.
	 * @param string        $previous Previous status.
	 * @param bool          $changed Whether this request wrote.
	 * @param array<string> $roles Resulting roles.
	 * @return array<string,mixed>
	 */
	private function result( int $id, string $status, string $previous, bool $changed, array $roles ): array {
		return array(
			'id'              => $id,
			'status'          => $status,
			'previous_status' => $previous,
			'changed'         => $changed,
			'roles'           => array_values( $roles ),
		);
	}
}
