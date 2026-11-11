<?php
/**
 * Reviewed users operations with core capability checks.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use WP_Error;
use WP_User;
use XNetVN\AgentHubWP\Abilities\MutationResponse;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;
use XNetVN\AgentHubWP\Abilities\RestBackedAbility;
use XNetVN\AgentHubWP\Contracts\RateLimiter;

/** User records and filters are untrusted data. */
final class UserOperations {
	/**
	 * Internal dispatcher.
	 *
	 * @var RestBackedAbility
	 */
	private RestBackedAbility $rest;
	/**
	 * Allowlisted output.
	 *
	 * @var UserProjection
	 */
	private UserProjection $projection;

	/**
	 * Bind pure infrastructure.
	 *
	 * @param RateLimiter $limits Optional budget checker.
	 */
	public function __construct( private ?RateLimiter $limits = null ) {
		$this->rest       = new RestBackedAbility();
		$this->projection = new UserProjection( static fn( int $id ): bool => current_user_can( 'edit_user', $id ) );
	}

	/**
	 * Return the bound rate limiter.
	 *
	 * @return RateLimiter|null
	 */
	public function rate_limiter(): ?RateLimiter {
		return $this->limits;
	}

	/**
	 * Run a reviewed operation after registry schema/policy validation.
	 *
	 * @param string              $name Reviewed operation.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	public function execute( string $name, array $args ): mixed {
		return match ( $name ) {
			'list-users' => $this->list_users( $args ),
			'get-user' => $this->get_user( $args ),
			'list-roles' => $this->list_roles( $args ),
			'get-user-capabilities' => $this->get_user_capabilities( $args ),
			'get-my-profile' => $this->get_my_profile( $args ),
			'update-my-profile' => $this->update_my_profile( $args ),
			'create-user' => $this->create_user( $args ),
			'update-user' => $this->update_user( $args ),
			'set-user-role' => $this->set_user_role( $args ),
			'set-user-status' => $this->set_user_status( $args ),
			'send-password-reset' => $this->send_password_reset( $args ),
			'terminate-user-sessions' => $this->terminate_user_sessions( $args ),
			default => R::error( 'unsupported_operation' ),
		};
	}

	/**
	 * Standard admin chain: resolve, self, guard, capability.
	 *
	 * @param int    $id Target.
	 * @param string $cap Required object capability.
	 * @return WP_User|WP_Error
	 */
	private function admin_target( int $id, string $cap ): WP_User|WP_Error {
		if ( $id < 1 ) {
			return R::error( 'invalid_params' );
		}
		$user = get_userdata( $id );
		if ( false === $user ) {
			return R::error( 'not_found' );
		}
		$state = UserTargetGuard::evaluate( $id );
		if ( 'missing' === $state ) {
			return R::error( 'not_found' );
		}
		if ( in_array( $state, array( 'self', 'protected', 'denied' ), true ) ) {
			return R::error( 'protected_account' );
		}
		if ( ! current_user_can( $cap, $id ) ) {
			return R::error( 'forbidden' );
		}
		return $user;
	}

	/**
	 * Re-evaluate protection immediately before writing.
	 *
	 * @param int $id Target.
	 * @return WP_Error|null
	 */
	private function reguard( int $id ): ?WP_Error {
		$state = UserTargetGuard::evaluate( $id );
		if ( in_array( $state, array( 'self', 'protected', 'denied' ), true ) ) {
			return R::error( 'protected_account' );
		}
		if ( 'missing' === $state ) {
			return R::error( 'not_found' );
		}
		return null;
	}

	/**
	 * Writable user meta keys for this site.
	 *
	 * @return array<string>
	 */
	private function writable_meta_keys(): array {
		try {
			$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'xnetvn_agent_hub_wp_user_meta_writable', array() ) : array();
			if ( ! is_array( $filtered ) ) {
				return array();
			}
			$registered = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'user' ) : array();
			$hard       = array( 'session_tokens', 'application_passwords', 'user_pass', 'user_activation_key' );
			$out        = array();
			foreach ( $filtered as $key ) {
				if ( ! is_string( $key ) || 1 !== preg_match( '/\A[A-Za-z0-9_:.\-]{1,100}\z/', $key ) ) {
					continue;
				}
				if ( str_starts_with( $key, '_' ) ) {
					continue;
				}
				if ( function_exists( 'is_protected_meta' ) && is_protected_meta( $key, 'user' ) ) {
					continue;
				}
				if ( str_ends_with( $key, 'capabilities' ) || str_ends_with( $key, 'user_level' ) ) {
					continue;
				}
				if ( in_array( $key, $hard, true ) ) {
					continue;
				}
				if ( ! array_key_exists( $key, $registered ) ) {
					continue;
				}
				$def = $registered[ $key ];
				if ( empty( $def['show_in_rest'] ) ) {
					continue;
				}
				$out[] = $key;
			}
			return array_values( array_unique( $out ) );
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Check meta write allowlist before any write.
	 *
	 * @param mixed $meta Candidate meta.
	 * @return WP_Error|null
	 */
	private function check_meta_writable( mixed $meta ): ?WP_Error {
		if ( ! is_array( $meta ) || count( $meta ) > 20 ) {
			return R::error( 'invalid_params' );
		}
		$allowed = $this->writable_meta_keys();
		foreach ( $meta as $key => $value ) {
			unset( $value );
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return R::error( 'invalid_params' );
			}
		}
		return null;
	}

	/**
	 * Read registered show_in_rest meta for one user.
	 *
	 * @param int $id Target.
	 * @return array<string,mixed>
	 */
	private function read_meta( int $id ): array {
		try {
			if ( ! function_exists( 'get_registered_meta_keys' ) ) {
				return array();
			}
			$registered = get_registered_meta_keys( 'user' );
			$out        = array();
			foreach ( $registered as $key => $def ) {
				if ( ! is_string( $key ) || str_starts_with( $key, '_' ) ) {
					continue;
				}
				if ( empty( $def['show_in_rest'] ) ) {
					continue;
				}
				$value = get_user_meta( $id, $key, true );
				if ( null === $value ) {
					continue;
				}
				$out[ $key ] = $value;
			}
			return $out;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Project one user with privacy groups and ban info.
	 *
	 * @param WP_User       $user Target.
	 * @param array<string> $fields Requested fields.
	 * @return array<string,mixed>|WP_Error
	 */
	private function project_user( WP_User $user, array $fields ): array|WP_Error {
		$id        = (int) $user->ID;
		$protected = UserTargetGuard::is_protected_user( $user );
		$record    = UserProjection::record( $user, $protected );
		$status    = in_array( 'banned', (array) $user->roles, true ) ? 'banned' : 'active';
		if ( 'banned' === $status ) {
			$snapshot = BanSnapshot::load( $id );
			if ( is_array( $snapshot ) && isset( $snapshot['at'], $snapshot['reason'] ) ) {
				$record['ban'] = array(
					'banned_at'   => (string) $snapshot['at'],
					'reason_code' => (string) $snapshot['reason'],
				);
			}
		}
		if ( in_array( 'meta', $fields, true ) || array() === $fields ) {
			$needs_meta = in_array( 'meta', $fields, true );
			if ( $needs_meta || array() === $fields ) {
				if ( ! current_user_can( 'edit_user', $id ) ) {
					if ( $needs_meta ) {
						return R::error( 'forbidden' );
					}
				} else {
					$record['meta'] = $this->read_meta( $id );
				}
			}
		}
		try {
			return $this->projection->project( $record, $fields );
		} catch ( \DomainException ) {
			return R::error( 'forbidden' );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * List and search users with pagination.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function list_users( array $args ): mixed {
		if ( ! current_user_can( 'list_users' ) ) {
			return R::error( 'forbidden' );
		}
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'any';
		if ( ! in_array( $status, array( 'active', 'banned', 'any' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'banned' === $status && isset( $args['roles'] ) ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $args['orderby'] ) && 'include' === $args['orderby'] && empty( $args['include'] ) ) {
			return R::error( 'invalid_params' );
		}
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		if ( $page < 1 || $per_page < 1 || $per_page > 100 ) {
			return R::error( 'invalid_params' );
		}
		$params = array(
			'context'  => 'view',
			'page'     => $page,
			'per_page' => $per_page,
		);
		foreach ( array( 'search', 'search_columns', 'roles', 'include', 'exclude', 'has_published_posts', 'orderby', 'order' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}
		if ( isset( $params['search_columns'] ) && is_array( $params['search_columns'] ) ) {
			$mapped = array();
			foreach ( $params['search_columns'] as $col ) {
				$mapped[] = match ( (string) $col ) {
					'id' => 'ID',
					'name' => 'display_name',
					'slug' => 'user_nicename',
					'username' => 'user_login',
					'email' => 'user_email',
					default => (string) $col,
				};
			}
			$params['search_columns'] = $mapped;
		}
		$filter = null;
		if ( 'banned' === $status || 'active' === $status ) {
			$filter = static function ( array $query_args ) use ( $status ): array {
				if ( 'banned' === $status ) {
					$query_args['role__in'] = array( 'banned' );
				} else {
					$query_args['role__not_in'] = array( 'banned' );
				}
				return $query_args;
			};
			add_filter( 'rest_user_query', $filter, 10, 1 );
		}
		try {
			$response = $this->rest->dispatch( 'GET', '/wp/v2/users', $params );
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'rest_user_query', $filter, 10 );
			}
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : array();
		$ids    = array();
		foreach ( (array) $response->get_data() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				return R::error( 'internal_error' );
			}
			$ids[] = (int) $row['id'];
		}
		$items = array();
		foreach ( $ids as $uid ) {
			$user = get_userdata( $uid );
			if ( false === $user ) {
				continue;
			}
			$projected = $this->project_user( $user, $fields );
			if ( is_wp_error( $projected ) ) {
				return $projected;
			}
			$items[] = $projected;
		}
		$result = $this->rest->collection( $response, $page, static fn( array $row ): array => $row );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$total_pages = (int) $result['total_pages'];
		if ( $page > max( 1, $total_pages ) ) {
			return R::error( 'invalid_params' );
		}
		$result['items'] = $items;
		return $result;
	}

	/**
	 * Read one user by ID.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function get_user( array $args ): mixed {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id < 1 ) {
			return R::error( 'invalid_params' );
		}
		$response = $this->rest->dispatch( 'GET', '/wp/v2/users/' . $id, array( 'context' => 'view' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$user = get_userdata( $id );
		if ( false === $user ) {
			return R::error( 'not_found' );
		}
		$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : array();
		return $this->project_user( $user, $fields );
	}

	/**
	 * List roles with privilege flags.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function list_roles( array $args ): mixed {
		unset( $args );
		if ( ! current_user_can( 'list_users' ) ) {
			return R::error( 'forbidden' );
		}
		try {
			$roles   = wp_roles();
			$default = function_exists( 'get_option' ) ? (string) get_option( 'default_role' ) : '';
			$items   = array();
			foreach ( $roles->roles as $slug => $def ) {
				$caps  = is_array( $def['capabilities'] ?? null ) ? $def['capabilities'] : array();
				$count = 0;
				foreach ( $caps as $enabled ) {
					if ( $enabled ) {
						++$count;
					}
				}
				$items[] = array(
					'slug'             => (string) $slug,
					'name'             => isset( $def['name'] ) && is_string( $def['name'] ) ? (string) $def['name'] : (string) $slug,
					'privileged'       => RoleGuard::is_privileged( (string) $slug ),
					'assignable'       => RoleGuard::assignable( (string) $slug ),
					'banned'           => 'banned' === $slug,
					'is_default'       => (string) $slug === $default,
					'capability_count' => $count,
				);
			}
			usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['slug'], (string) $b['slug'] ) );
			if ( count( $items ) > 100 ) {
				return array(
					'items'     => array_slice( $items, 0, 100 ),
					'truncated' => true,
				);
			}
			return array( 'items' => $items );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
	}

	/**
	 * List effective capabilities for one user.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function get_user_capabilities( array $args ): mixed {
		$id = (int) ( $args['id'] ?? 0 );
		if ( $id < 1 ) {
			return R::error( 'invalid_params' );
		}
		$user = get_userdata( $id );
		if ( false === $user ) {
			return R::error( 'not_found' );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return R::error( 'forbidden' );
		}
		try {
			$roles = wp_roles();
			$names = array_keys( $roles->roles );
		} catch ( \Throwable ) {
			$names = array();
		}
		$caps = array();
		foreach ( (array) $user->allcaps as $cap => $enabled ) {
			if ( ! $enabled || ! is_string( $cap ) ) {
				continue;
			}
			if ( in_array( $cap, $names, true ) ) {
				continue;
			}
			$caps[] = $cap;
		}
		sort( $caps );
		$truncated = false;
		if ( count( $caps ) > 500 ) {
			$caps      = array_slice( $caps, 0, 500 );
			$truncated = true;
		}
		$pcs        = RoleGuard::privileged_caps();
		$privileged = array_values( array_intersect( $pcs, $caps ) );
		sort( $privileged );
		$result = array(
			'id'              => $id,
			'roles'           => array_values( (array) $user->roles ),
			'capabilities'    => $caps,
			'privileged_caps' => $privileged,
			'protected'       => UserTargetGuard::is_protected_user( $user ),
			'status'          => in_array( 'banned', (array) $user->roles, true ) ? 'banned' : 'active',
		);
		if ( $truncated ) {
			$result['truncated'] = true;
		}
		return $result;
	}

	/**
	 * Read the key owner profile.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function get_my_profile( array $args ): mixed {
		$response = $this->rest->dispatch( 'GET', '/wp/v2/users/me', array( 'context' => 'edit' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$current = get_current_user_id();
		if ( $current < 1 ) {
			return R::error( 'not_found' );
		}
		$user = get_userdata( $current );
		if ( false === $user ) {
			return R::error( 'not_found' );
		}
		$fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? array_values( $args['fields'] ) : array();
		return $this->project_user( $user, $fields );
	}

	/**
	 * Update the key owner non-sensitive profile.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function update_my_profile( array $args ): mixed {
		$allowed = array( 'name', 'first_name', 'last_name', 'nickname', 'url', 'description', 'locale', 'slug', 'meta' );
		$changes = array_intersect_key( $args, array_fill_keys( $allowed, true ) );
		if ( array() === $changes ) {
			return R::error( 'invalid_params' );
		}
		if ( array_key_exists( 'name', $changes ) && '' === trim( (string) $changes['name'] ) ) {
			return R::error( 'invalid_params' );
		}
		if ( isset( $changes['meta'] ) ) {
			$meta_check = $this->check_meta_writable( $changes['meta'] );
			if ( is_wp_error( $meta_check ) ) {
				return $meta_check;
			}
		}
		if ( isset( $changes['locale'] ) ) {
			$locale_check = $this->check_locale( (string) $changes['locale'] );
			if ( is_wp_error( $locale_check ) ) {
				return $locale_check;
			}
			if ( '' === (string) $changes['locale'] ) {
				unset( $changes['locale'] );
				if ( array() === $changes ) {
					return R::error( 'invalid_params' );
				}
			}
		}
		if ( isset( $changes['slug'] ) ) {
			$other = get_user_by( 'slug', (string) $changes['slug'] );
			if ( $other instanceof WP_User && (int) get_current_user_id() !== (int) $other->ID ) {
				return R::error( 'duplicate_user' );
			}
		}
		$response = $this->rest->dispatch( 'POST', '/wp/v2/users/me', $changes, 'user', 'user' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse ) {
			return R::error( 'partial_failure' );
		}
		return $response->project(
			function ( array $row ) use ( $changes ): mixed {
				$current = get_current_user_id();
				clean_user_cache( $current );
				$user = get_userdata( $current );
				if ( false === $user ) {
					return new PartialFailure( $current > 0 ? $current : 1, false );
				}
				$projected = $this->project_user( $user, array() );
				if ( is_wp_error( $projected ) ) {
					return new PartialFailure( (int) $user->ID, false );
				}
				unset( $row );
				return array(
					'user'           => $projected,
					'updated_fields' => array_keys( $changes ),
				);
			}
		);
	}

	/**
	 * Validate a locale code.
	 *
	 * @param string $locale Candidate.
	 * @return bool|WP_Error
	 */
	private function check_locale( string $locale ): bool|WP_Error {
		if ( '' === $locale ) {
			return true;
		}
		try {
			$available = function_exists( 'get_available_languages' ) ? get_available_languages() : array();
			if ( in_array( $locale, (array) $available, true ) || 'en_US' === $locale ) {
				return true;
			}
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		return R::error( 'invalid_params' );
	}

	/**
	 * Create one user with an explicit assignable role.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function create_user( array $args ): mixed {
		if ( null !== $this->limits ) {
			try {
				$result = $this->limits->check( 'user-create', (string) get_current_user_id(), 5 );
				if ( ! $result->allowed ) {
					return R::error( 'rate_limited' );
				}
			} catch ( \Throwable ) {
				return R::error( 'internal_error' );
			}
		}
		if ( ! current_user_can( 'create_users' ) ) {
			return R::error( 'forbidden' );
		}
		$role = (string) ( $args['role'] ?? '' );
		if ( ! RoleGuard::assignable( $role ) ) {
			return R::error( 'role_unavailable' );
		}
		$username = (string) ( $args['username'] ?? '' );
		$email    = (string) ( $args['email'] ?? '' );
		if ( function_exists( 'username_exists' ) && username_exists( $username ) ) {
			return R::error( 'duplicate_user' );
		}
		if ( function_exists( 'email_exists' ) && email_exists( $email ) ) {
			return R::error( 'duplicate_user' );
		}
		if ( isset( $args['slug'] ) ) {
			$other = get_user_by( 'slug', (string) $args['slug'] );
			if ( $other instanceof WP_User ) {
				return R::error( 'duplicate_user' );
			}
		}
		if ( isset( $args['meta'] ) ) {
			$meta_check = $this->check_meta_writable( $args['meta'] );
			if ( is_wp_error( $meta_check ) ) {
				return $meta_check;
			}
		}
		if ( isset( $args['locale'] ) ) {
			$locale_check = $this->check_locale( (string) $args['locale'] );
			if ( is_wp_error( $locale_check ) ) {
				return $locale_check;
			}
		}
		$password = wp_generate_password( 32, true, false );
		$params   = array(
			'username' => $username,
			'email'    => $email,
			'password' => $password,
			'roles'    => array( $role ),
		);
		foreach ( array( 'name', 'first_name', 'last_name', 'nickname', 'url', 'description', 'locale', 'slug', 'meta' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$params[ $key ] = $args[ $key ];
			}
		}
		$response = $this->rest->dispatch( 'POST', '/wp/v2/users', $params, 'user', 'user' );
		unset( $password );
		unset( $params['password'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse ) {
			return R::error( 'partial_failure' );
		}
		$notify = isset( $args['notify'] ) ? (string) $args['notify'] : 'user';
		if ( ! in_array( $notify, array( 'none', 'user', 'admin', 'both' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		return $response->project(
			function ( array $row ) use ( $role, $notify ): mixed {
				$id = (int) ( $row['id'] ?? 0 );
				if ( $id < 1 ) {
					return new PartialFailure( 1, true );
				}
				clean_user_cache( $id );
				$user = get_userdata( $id );
				if ( false === $user || array( $role ) !== array_values( (array) $user->roles ) ) {
					return new PartialFailure( $id, true );
				}
				if ( UserTargetGuard::is_protected_user( $user ) ) {
					return new PartialFailure( $id, true );
				}
				if ( 'none' === $notify ) {
					$notification = 'none';
				} elseif ( function_exists( 'wp_new_user_notification' ) ) {
					try {
						if ( 'user' === $notify ) {
							wp_new_user_notification( $id, null, 'user' );
						} elseif ( 'admin' === $notify ) {
							wp_new_user_notification( $id, null, 'admin' );
						} else {
							wp_new_user_notification( $id, null, 'both' );
						}
					} catch ( \Throwable ) {
						return new PartialFailure( $id, true );
					}
					$notification = 'requested';
				} else {
					$notification = 'none';
				}
				return array(
					'id'           => $id,
					'slug'         => (string) ( $user->user_nicename ?? '' ),
					'name'         => (string) ( $user->display_name ?? '' ),
					'roles'        => array_values( (array) $user->roles ),
					'status'       => 'active',
					'notification' => $notification,
				);
			}
		);
	}

	/**
	 * Update another user profile and email.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function update_user( array $args ): mixed {
		$id            = (int) ( $args['id'] ?? 0 );
		$user_or_error = $this->admin_target( $id, 'edit_user' );
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		$user    = $user_or_error;
		$allowed = array( 'name', 'first_name', 'last_name', 'nickname', 'slug', 'url', 'description', 'locale', 'email', 'meta' );
		$changes = array_intersect_key( $args, array_fill_keys( $allowed, true ) );
		if ( array() === $changes ) {
			return R::error( 'invalid_params' );
		}
		$email_changed = false;
		if ( array_key_exists( 'email', $changes ) ) {
			$new_email = trim( (string) $changes['email'] );
			if ( ! is_email( $new_email ) ) {
				return R::error( 'invalid_params' );
			}
			$current_email = (string) $user->user_email;
			if ( 0 === strcasecmp( $new_email, $current_email ) ) {
				unset( $changes['email'] );
			} else {
				$owner = function_exists( 'email_exists' ) ? email_exists( $new_email ) : false;
				if ( is_int( $owner ) && $owner !== $id ) {
					return R::error( 'duplicate_user' );
				}
				if ( false !== $owner && (int) $owner !== $id ) {
					return R::error( 'duplicate_user' );
				}
				$changes['email'] = $new_email;
				$email_changed    = true;
			}
		}
		if ( array_key_exists( 'slug', $changes ) ) {
			$other = get_user_by( 'slug', (string) $changes['slug'] );
			if ( $other instanceof WP_User && (int) $other->ID !== $id ) {
				return R::error( 'duplicate_user' );
			}
		}
		if ( isset( $changes['meta'] ) ) {
			$meta_check = $this->check_meta_writable( $changes['meta'] );
			if ( is_wp_error( $meta_check ) ) {
				return $meta_check;
			}
		}
		if ( isset( $changes['locale'] ) ) {
			$locale_check = $this->check_locale( (string) $changes['locale'] );
			if ( is_wp_error( $locale_check ) ) {
				return $locale_check;
			}
		}
		if ( array() === $changes ) {
			return R::error( 'invalid_params' );
		}
		$reguard = $this->reguard( $id );
		if ( null !== $reguard ) {
			return $reguard;
		}
		$response = $this->rest->dispatch( 'POST', '/wp/v2/users/' . $id, $changes, 'user', 'user' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof MutationResponse ) {
			return R::error( 'partial_failure' );
		}
		return $response->project(
			function ( array $row ) use ( $id, $changes, $email_changed ): mixed {
				unset( $row );
				clean_user_cache( $id );
				$fresh = get_userdata( $id );
				if ( false === $fresh ) {
					return new PartialFailure( $id, false );
				}
				if ( $email_changed && 0 !== strcasecmp( (string) $fresh->user_email, (string) $changes['email'] ) ) {
					return new PartialFailure( $id, false );
				}
				$projected = $this->project_user( $fresh, array() );
				if ( is_wp_error( $projected ) ) {
					return new PartialFailure( $id, false );
				}
				return array(
					'user'           => $projected,
					'updated_fields' => array_keys( $changes ),
					'email_changed'  => $email_changed,
				);
			}
		);
	}

	/**
	 * Replace all roles with one assignable role.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function set_user_role( array $args ): mixed {
		$id            = (int) ( $args['id'] ?? 0 );
		$user_or_error = $this->admin_target( $id, 'promote_user' );
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		$user = $user_or_error;
		if ( in_array( 'banned', (array) $user->roles, true ) ) {
			return R::error( 'invalid_state' );
		}
		$role = (string) ( $args['role'] ?? '' );
		if ( ! RoleGuard::assignable( $role ) ) {
			return R::error( 'role_unavailable' );
		}
		$before = array_values( (array) $user->roles );
		if ( array( $role ) === $before ) {
			return array(
				'id'             => $id,
				'roles'          => array( $role ),
				'previous_roles' => $before,
				'changed'        => false,
			);
		}
		$reguard = $this->reguard( $id );
		if ( null !== $reguard ) {
			return $reguard;
		}
		if ( ! current_user_can( 'promote_user', $id ) ) {
			return R::error( 'forbidden' );
		}
		try {
			$user->set_role( $role );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		clean_user_cache( $id );
		$fresh = get_userdata( $id );
		if ( false === $fresh || array( $role ) !== array_values( (array) $fresh->roles ) ) {
			return new PartialFailure( $id, false );
		}
		if ( UserTargetGuard::is_protected_user( $fresh ) ) {
			return new PartialFailure( $id, false );
		}
		$result = array(
			'id'             => $id,
			'roles'          => array( $role ),
			'previous_roles' => $before,
			'changed'        => true,
		);
		return MutationResult::project( $id, false, static fn(): array => $result );
	}

	/**
	 * Ban or unban one user.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function set_user_status( array $args ): mixed {
		$id     = (int) ( $args['id'] ?? 0 );
		$status = (string) ( $args['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'banned' ), true ) ) {
			return R::error( 'invalid_params' );
		}
		if ( 'banned' === $status && ( isset( $args['restore_role'] ) || ! isset( $args['reason_code'] ) ) ) {
			if ( isset( $args['restore_role'] ) ) {
				return R::error( 'invalid_params' );
			}
		}
		if ( 'active' === $status && isset( $args['reason_code'] ) ) {
			return R::error( 'invalid_params' );
		}
		$reason = isset( $args['reason_code'] ) ? (string) $args['reason_code'] : 'other';
		if ( 'banned' === $status && ! in_array( $reason, BanSnapshot::REASONS, true ) ) {
			return R::error( 'invalid_params' );
		}
		$user_or_error = $this->admin_target( $id, 'promote_user' );
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		$user            = $user_or_error;
		$previous_status = in_array( 'banned', (array) $user->roles, true ) ? 'banned' : 'active';
		if ( $previous_status === $status ) {
			if ( 'banned' === $status ) {
				$fresh_check = get_userdata( $id );
				if ( $fresh_check instanceof WP_User ) {
					$clean = array( 'banned' ) === array_values( (array) $fresh_check->roles ) && $this->effective_caps_empty( $fresh_check );
					if ( $clean ) {
						return array(
							'id'              => $id,
							'status'          => $status,
							'previous_status' => $previous_status,
							'changed'         => false,
							'roles'           => array( 'banned' ),
						);
					}
				}
			} elseif ( ! in_array( 'banned', (array) $user->roles, true ) ) {
					return array(
						'id'              => $id,
						'status'          => $status,
						'previous_status' => $previous_status,
						'changed'         => false,
						'roles'           => array_values( (array) $user->roles ),
					);
			}
		}
		if ( 'banned' === $status ) {
			return $this->ban_user( $user, $previous_status, $reason );
		}
		return $this->unban_user( $user, $previous_status, isset( $args['restore_role'] ) ? (string) $args['restore_role'] : null );
	}

	/**
	 * Whether effective capabilities are empty except banned.
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
	 * Ban one user with snapshot and session termination.
	 *
	 * @param WP_User $user Target.
	 * @param string  $previous_status Previous status.
	 * @param string  $reason Reason code.
	 * @return mixed
	 */
	private function ban_user( WP_User $user, string $previous_status, string $reason ): mixed {
		$id      = (int) $user->ID;
		$ensured = RoleGuard::ensure_banned_role();
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}
		$direct = array();
		foreach ( (array) $user->caps as $cap => $enabled ) {
			$name = (string) $cap;
			try {
				$roles = wp_roles();
				if ( isset( $roles->roles[ $name ] ) ) {
					continue;
				}
			} catch ( \Throwable ) {
				continue;
			}
			$direct[ $name ] = (bool) $enabled;
		}
		$snapshot = BanSnapshot::build( array_values( (array) $user->roles ), $direct, (int) get_current_user_id(), $reason );
		if ( ! BanSnapshot::validate( $snapshot ) ) {
			return R::error( 'invalid_state' );
		}
		if ( ! BanSnapshot::save( $id, $snapshot ) ) {
			return R::error( 'internal_error' );
		}
		$reguard = $this->reguard( $id );
		if ( null !== $reguard ) {
			return $reguard;
		}
		try {
			$fresh = get_userdata( $id );
			if ( $fresh instanceof WP_User ) {
				$fresh->set_role( 'banned' );
			}
			$target = get_userdata( $id );
			if ( $target instanceof WP_User ) {
				foreach ( array_keys( $direct ) as $cap ) {
					$target->remove_cap( (string) $cap );
				}
			}
			if ( class_exists( 'WP_Session_Tokens' ) ) {
				\WP_Session_Tokens::get_instance( $id )->destroy_all();
			}
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		clean_user_cache( $id );
		$after = get_userdata( $id );
		if ( false === $after || array( 'banned' ) !== array_values( (array) $after->roles ) ) {
			return new PartialFailure( $id, false );
		}
		if ( ! $this->effective_caps_empty( $after ) ) {
			return new PartialFailure( $id, false );
		}
		$result = array(
			'id'              => $id,
			'status'          => 'banned',
			'previous_status' => $previous_status,
			'changed'         => true,
			'roles'           => array( 'banned' ),
		);
		return MutationResult::project( $id, false, static fn(): array => $result );
	}

	/**
	 * Unban one user with safe restore.
	 *
	 * @param WP_User     $user Target.
	 * @param string      $previous_status Previous status.
	 * @param string|null $restore_role Optional single restore role.
	 * @return mixed
	 */
	private function unban_user( WP_User $user, string $previous_status, ?string $restore_role ): mixed {
		$id = (int) $user->ID;
		if ( ! in_array( 'banned', (array) $user->roles, true ) ) {
			return array(
				'id'              => $id,
				'status'          => 'active',
				'previous_status' => $previous_status,
				'changed'         => false,
				'roles'           => array_values( (array) $user->roles ),
			);
		}
		$roles_to_restore = array();
		$caps_to_restore  = array();
		if ( null !== $restore_role ) {
			if ( ! RoleGuard::assignable( $restore_role ) ) {
				return R::error( 'role_unavailable' );
			}
			$roles_to_restore = array( $restore_role );
		} else {
			$snapshot = BanSnapshot::load( $id );
			if ( null === $snapshot ) {
				return R::error( 'invalid_state' );
			}
			foreach ( (array) ( $snapshot['roles'] ?? array() ) as $slug ) {
				$slug = (string) $slug;
				if ( 'banned' === $slug || ! RoleGuard::assignable( $slug ) ) {
					return R::error( 'invalid_state' );
				}
				try {
					if ( ! function_exists( 'wp_roles' ) ) {
						return R::error( 'internal_error' );
					}
					$roles_obj = wp_roles();
					if ( ! isset( $roles_obj->roles[ $slug ] ) ) {
						return R::error( 'invalid_state' );
					}
					$editable = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
					if ( ! isset( $editable[ $slug ] ) ) {
						return R::error( 'invalid_state' );
					}
				} catch ( \Throwable ) {
					return R::error( 'internal_error' );
				}
				$roles_to_restore[] = $slug;
			}
			if ( array() === $roles_to_restore ) {
				return R::error( 'invalid_state' );
			}
			$pcs = RoleGuard::privileged_caps();
			foreach ( (array) ( $snapshot['caps'] ?? array() ) as $cap => $enabled ) {
				if ( in_array( (string) $cap, $pcs, true ) ) {
					return R::error( 'invalid_state' );
				}
				$caps_to_restore[ (string) $cap ] = (bool) $enabled;
			}
		}
		$reguard = $this->reguard( $id );
		if ( null !== $reguard ) {
			return $reguard;
		}
		if ( ! current_user_can( 'promote_user', $id ) ) {
			return R::error( 'forbidden' );
		}
		try {
			$fresh = get_userdata( $id );
			if ( ! $fresh instanceof WP_User ) {
				return R::error( 'not_found' );
			}
			$fresh->set_role( $roles_to_restore[0] );
			foreach ( array_slice( $roles_to_restore, 1 ) as $extra ) {
				$fresh->add_role( $extra );
			}
			foreach ( $caps_to_restore as $cap => $enabled ) {
				$fresh->add_cap( (string) $cap, $enabled );
			}
			BanSnapshot::clear( $id );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		clean_user_cache( $id );
		$after = get_userdata( $id );
		if ( false === $after ) {
			return new PartialFailure( $id, false );
		}
		if ( UserTargetGuard::is_protected_user( $after ) ) {
			return new PartialFailure( $id, false );
		}
		$result = array(
			'id'              => $id,
			'status'          => 'active',
			'previous_status' => $previous_status,
			'changed'         => true,
			'roles'           => array_values( (array) $after->roles ),
		);
		return MutationResult::project( $id, false, static fn(): array => $result );
	}

	/**
	 * End all cookie sessions for one user.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function terminate_user_sessions( array $args ): mixed {
		$id            = (int) ( $args['id'] ?? 0 );
		$user_or_error = $this->admin_target( $id, 'edit_user' );
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		try {
			if ( ! class_exists( 'WP_Session_Tokens' ) ) {
				return R::error( 'internal_error' );
			}
			$manager = \WP_Session_Tokens::get_instance( $id );
			$before  = count( $manager->get_all() );
			$manager->destroy_all();
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		$result = array(
			'id'         => $id,
			'terminated' => $before,
		);
		return MutationResult::project( $id, false, static fn(): array => $result );
	}

	/**
	 * Ask core to email a password reset link.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function send_password_reset( array $args ): mixed {
		$id            = (int) ( $args['id'] ?? 0 );
		$user_or_error = $this->admin_target( $id, 'edit_user' );
		if ( is_wp_error( $user_or_error ) ) {
			return $user_or_error;
		}
		$user = $user_or_error;
		if ( in_array( 'banned', (array) $user->roles, true ) ) {
			return R::error( 'invalid_state' );
		}
		if ( null !== $this->limits ) {
			try {
				$result = $this->limits->check( 'user-reset', (string) get_current_user_id(), 10 );
				if ( ! $result->allowed ) {
					return R::error( 'rate_limited' );
				}
			} catch ( \Throwable ) {
				return R::error( 'internal_error' );
			}
		}
		try {
			$last = function_exists( 'get_user_option' ) ? get_user_option( 'xnetvn_agent_hub_wp_reset_at', $id ) : false;
			$now  = time();
			if ( is_numeric( $last ) && ( $now - (int) $last ) < 900 ) {
				return R::error( 'cooldown_active' );
			}
			if ( function_exists( 'update_user_option' ) ) {
				update_user_option( $id, 'xnetvn_agent_hub_wp_reset_at', $now, false );
			}
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		try {
			$login = (string) $user->user_login;
			if ( '' === trim( $login ) ) {
				return R::error( 'invalid_params' );
			}
			$sent = retrieve_password( $login );
		} catch ( \Throwable ) {
			return R::error( 'internal_error' );
		}
		if ( is_wp_error( $sent ) ) {
			$code = (string) $sent->get_error_code();
			if ( 'no_password_reset' === $code ) {
				return R::error( 'forbidden' );
			}
			if ( in_array( $code, array( 'invalidcombo', 'invalid_email' ), true ) ) {
				return R::error( 'not_found' );
			}
			if ( 'retrieve_password_email_failure' === $code ) {
				return R::error( 'internal_error' );
			}
			return R::sanitize( $sent );
		}
		if ( true !== $sent ) {
			return R::error( 'internal_error' );
		}
		return array(
			'id'        => $id,
			'requested' => true,
		);
	}
}
