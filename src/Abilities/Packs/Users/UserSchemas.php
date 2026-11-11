<?php
/**
 * Strict users tool schemas.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas as C;
use XNetVN\AgentHubWP\Abilities\SchemaFactory as S;

/** Keeps tool contracts independent of user execution. */
final class UserSchemas {
	/**
	 * User identifier.
	 *
	 * @return array<string,mixed>
	 */
	public static function user_id(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	/**
	 * Bounded user identifier list.
	 *
	 * @return array<string,mixed>
	 */
	public static function user_id_list(): array {
		return array(
			'type'        => 'array',
			'items'       => self::user_id(),
			'maxItems'    => 100,
			'uniqueItems' => true,
		);
	}

	/**
	 * Role slug bounded by guard pattern.
	 *
	 * @return array<string,mixed>
	 */
	public static function role_slug(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9_-]{1,64}$',
		);
	}

	/**
	 * Bounded role slug list.
	 *
	 * @return array<string,mixed>
	 */
	public static function role_slug_list(): array {
		return array(
			'type'        => 'array',
			'items'       => self::role_slug(),
			'maxItems'    => 10,
			'uniqueItems' => true,
		);
	}

	/**
	 * Login name bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function login_name(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[A-Za-z0-9._@-]{1,60}$',
		);
	}

	/**
	 * Email address bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function email_address(): array {
		return array(
			'type'      => 'string',
			'format'    => 'email',
			'minLength' => 3,
			'maxLength' => 100,
		);
	}

	/**
	 * Display name bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function display_name(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 250,
		);
	}

	/**
	 * Person name for first, last and nickname.
	 *
	 * @return array<string,mixed>
	 */
	public static function person_name(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 100,
		);
	}

	/**
	 * Profile URL bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function profile_url(): array {
		return array(
			'type'      => 'string',
			'format'    => 'uri',
			'maxLength' => 100,
		);
	}

	/**
	 * Biography with a reviewed bound.
	 *
	 * @return array<string,mixed>
	 */
	public static function bio(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 5000,
		);
	}

	/**
	 * User slug bounded by the core column.
	 *
	 * @return array<string,mixed>
	 */
	public static function user_slug(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[a-z0-9-]{1,50}$',
		);
	}

	/**
	 * Locale code validated against core at execution.
	 *
	 * @return array<string,mixed>
	 */
	public static function locale_code(): array {
		return array(
			'type'      => 'string',
			'maxLength' => 20,
		);
	}

	/**
	 * User meta with a reviewed key bound.
	 *
	 * @return array<string,mixed>
	 */
	public static function user_meta(): array {
		return array(
			'type'          => 'object',
			'maxProperties' => 20,
		);
	}

	/**
	 * Narrowing field list for the user DTO.
	 *
	 * @return array<string,mixed>
	 */
	public static function user_fields(): array {
		return array(
			'type'        => 'array',
			'items'       => C::choices( array( 'id', 'name', 'slug', 'link', 'status', 'roles', 'registered_date', 'protected', 'description', 'url', 'ban', 'username', 'email', 'first_name', 'last_name', 'nickname', 'locale', 'meta' ) ),
			'uniqueItems' => true,
			'maxItems'    => 18,
		);
	}

	/**
	 * Typed user projection.
	 *
	 * @return array<string,mixed>
	 */
	public static function user(): array {
		return S::object(
			array(
				'id'              => C::integer(),
				'name'            => C::text( 500 ),
				'slug'            => C::text( 100 ),
				'link'            => C::text(),
				'status'          => C::choices( array( 'active', 'banned' ) ),
				'roles'           => array(
					'type'  => 'array',
					'items' => C::text( 64 ),
				),
				'registered_date' => C::text( 40 ),
				'protected'       => array( 'type' => 'boolean' ),
				'description'     => C::text( 5000 ),
				'url'             => C::text( 500 ),
				'ban'             => S::object(
					array(
						'banned_at'   => C::text( 40 ),
						'reason_code' => C::choices( array( 'spam', 'abuse', 'security', 'policy', 'other' ) ),
					),
					array( 'banned_at', 'reason_code' )
				),
				'username'        => C::text( 60 ),
				'email'           => C::text( 100 ),
				'first_name'      => C::text( 100 ),
				'last_name'       => C::text( 100 ),
				'nickname'        => C::text( 100 ),
				'locale'          => C::text( 20 ),
				'meta'            => self::user_meta(),
			),
			array( 'id' ),
			true
		);
	}

	/**
	 * Schema for each reviewed tool.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function input( string $name ): array {
		$id = array( 'id' => self::user_id() );
		switch ( $name ) {
			case 'list-users':
				return S::object(
					array_merge(
						S::pagination(),
						array(
							'search'              => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'search_columns'      => array(
								'type'        => 'array',
								'items'       => C::choices( array( 'id', 'name', 'slug', 'username', 'email' ) ),
								'maxItems'    => 5,
								'uniqueItems' => true,
							),
							'roles'               => self::role_slug_list(),
							'status'              => C::choices( array( 'active', 'banned', 'any' ) ),
							'include'             => self::user_id_list(),
							'exclude'             => self::user_id_list(),
							'has_published_posts' => array( 'type' => 'boolean' ),
							'orderby'             => C::choices( array( 'id', 'name', 'registered_date', 'slug', 'include' ) ),
							'order'               => C::choices( array( 'asc', 'desc' ) ),
							'fields'              => self::user_fields(),
						)
					)
				);
			case 'get-user':
				return S::object(
					array_merge( $id, array( 'fields' => self::user_fields() ) ),
					array( 'id' )
				);
			case 'list-roles':
				return S::object( array() );
			case 'get-user-capabilities':
				return S::object( $id, array( 'id' ) );
			case 'get-my-profile':
				return S::object( array( 'fields' => self::user_fields() ) );
			case 'update-my-profile':
				return S::object(
					array(
						'name'        => self::display_name(),
						'first_name'  => self::person_name(),
						'last_name'   => self::person_name(),
						'nickname'    => self::person_name(),
						'url'         => self::profile_url(),
						'description' => self::bio(),
						'locale'      => self::locale_code(),
						'slug'        => self::user_slug(),
						'meta'        => self::user_meta(),
					)
				);
			case 'create-user':
				return S::object(
					array(
						'username'    => self::login_name(),
						'email'       => self::email_address(),
						'role'        => self::role_slug(),
						'name'        => self::display_name(),
						'first_name'  => self::person_name(),
						'last_name'   => self::person_name(),
						'nickname'    => self::person_name(),
						'url'         => self::profile_url(),
						'description' => self::bio(),
						'locale'      => self::locale_code(),
						'slug'        => self::user_slug(),
						'meta'        => self::user_meta(),
						'notify'      => C::choices( array( 'none', 'user', 'admin', 'both' ) ),
					),
					array( 'username', 'email', 'role' )
				);
			case 'update-user':
				return S::object(
					array_merge(
						$id,
						array(
							'name'        => self::display_name(),
							'first_name'  => self::person_name(),
							'last_name'   => self::person_name(),
							'nickname'    => self::person_name(),
							'slug'        => self::user_slug(),
							'url'         => self::profile_url(),
							'description' => self::bio(),
							'locale'      => self::locale_code(),
							'email'       => self::email_address(),
							'meta'        => self::user_meta(),
						)
					),
					array( 'id' )
				);
			case 'set-user-role':
				return S::object(
					array_merge( $id, array( 'role' => self::role_slug() ) ),
					array( 'id', 'role' )
				);
			case 'set-user-status':
				return S::object(
					array_merge(
						$id,
						array(
							'status'       => C::choices( array( 'active', 'banned' ) ),
							'reason_code'  => C::choices( array( 'spam', 'abuse', 'security', 'policy', 'other' ) ),
							'restore_role' => self::role_slug(),
						)
					),
					array( 'id', 'status' )
				);
			case 'send-password-reset':
				return S::object( $id, array( 'id' ) );
			case 'terminate-user-sessions':
				return S::object( $id, array( 'id' ) );
			default:
				return S::object( $id, array( 'id' ) );
		}
	}

	/**
	 * Reviewed output by operation.
	 *
	 * @param string $name Tool suffix.
	 * @return array<string,mixed>
	 */
	public static function output( string $name ): array {
		switch ( $name ) {
			case 'list-users':
				return S::object(
					array(
						'items'       => array(
							'type'  => 'array',
							'items' => self::user(),
						),
						'total'       => C::integer( 0 ),
						'total_pages' => C::integer( 0 ),
						'page'        => C::integer(),
					),
					array( 'items', 'total', 'total_pages', 'page' ),
					true
				);
			case 'list-roles':
				$item = S::object(
					array(
						'slug'             => self::role_slug(),
						'name'             => C::text( 200 ),
						'privileged'       => array( 'type' => 'boolean' ),
						'assignable'       => array( 'type' => 'boolean' ),
						'banned'           => array( 'type' => 'boolean' ),
						'is_default'       => array( 'type' => 'boolean' ),
						'capability_count' => C::integer( 0 ),
					),
					array( 'slug', 'name', 'privileged', 'assignable', 'banned', 'is_default', 'capability_count' )
				);
				return S::object(
					array(
						'items' => array(
							'type'  => 'array',
							'items' => $item,
						),
					),
					array( 'items' ),
					true
				);
			case 'get-user-capabilities':
				return S::object(
					array(
						'id'              => C::integer(),
						'roles'           => array(
							'type'  => 'array',
							'items' => C::text( 64 ),
						),
						'capabilities'    => array(
							'type'  => 'array',
							'items' => C::text( 100 ),
						),
						'privileged_caps' => array(
							'type'  => 'array',
							'items' => C::text( 100 ),
						),
						'protected'       => array( 'type' => 'boolean' ),
						'status'          => C::choices( array( 'active', 'banned' ) ),
					),
					array( 'id', 'roles', 'capabilities', 'privileged_caps', 'protected', 'status' ),
					true
				);
			case 'create-user':
				return S::object(
					array(
						'id'           => C::integer(),
						'slug'         => C::text( 100 ),
						'name'         => C::text( 500 ),
						'roles'        => array(
							'type'  => 'array',
							'items' => C::text( 64 ),
						),
						'status'       => C::choices( array( 'active', 'banned' ) ),
						'notification' => C::choices( array( 'requested', 'none' ) ),
					),
					array( 'id', 'slug', 'name', 'roles', 'status', 'notification' )
				);
			case 'update-user':
				return S::object(
					array(
						'user'           => self::user(),
						'updated_fields' => array(
							'type'  => 'array',
							'items' => C::text( 50 ),
						),
						'email_changed'  => array( 'type' => 'boolean' ),
					),
					array( 'user', 'updated_fields', 'email_changed' )
				);
			case 'set-user-role':
				return S::object(
					array(
						'id'             => C::integer(),
						'roles'          => array(
							'type'  => 'array',
							'items' => C::text( 64 ),
						),
						'previous_roles' => array(
							'type'  => 'array',
							'items' => C::text( 64 ),
						),
						'changed'        => array( 'type' => 'boolean' ),
					),
					array( 'id', 'roles', 'previous_roles', 'changed' )
				);
			case 'set-user-status':
				return S::object(
					array(
						'id'              => C::integer(),
						'status'          => C::choices( array( 'active', 'banned' ) ),
						'previous_status' => C::choices( array( 'active', 'banned' ) ),
						'changed'         => array( 'type' => 'boolean' ),
						'roles'           => array(
							'type'  => 'array',
							'items' => C::text( 64 ),
						),
					),
					array( 'id', 'status', 'previous_status', 'changed', 'roles' )
				);
			case 'send-password-reset':
				return S::object(
					array(
						'id'        => C::integer(),
						'requested' => array( 'type' => 'boolean' ),
					),
					array( 'id', 'requested' )
				);
			case 'terminate-user-sessions':
				return S::object(
					array(
						'id'         => C::integer(),
						'terminated' => C::integer( 0 ),
					),
					array( 'id', 'terminated' )
				);
			case 'get-my-profile':
			case 'update-my-profile':
				if ( 'get-my-profile' === $name ) {
					return self::user();
				}
				return S::object(
					array(
						'user'           => self::user(),
						'updated_fields' => array(
							'type'  => 'array',
							'items' => C::text( 50 ),
						),
					),
					array( 'user', 'updated_fields' )
				);
			default:
				return self::user();
		}
	}
}
