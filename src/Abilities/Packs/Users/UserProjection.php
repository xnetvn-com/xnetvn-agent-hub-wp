<?php
/**
 * Pure allowlisted user response shaping.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Users;

use Closure;

/** User names, bios, URLs and meta are untrusted data. */
final readonly class UserProjection {
	/** Reviewed logical user DTO fields in deterministic order. */
	public const FIELDS = array( 'id', 'name', 'slug', 'link', 'status', 'roles', 'registered_date', 'protected', 'description', 'url', 'ban', 'username', 'email', 'first_name', 'last_name', 'nickname', 'locale', 'meta' );

	/** Group A fields visible with list_users. */
	public const GROUP_A = array( 'id', 'name', 'slug', 'link', 'status', 'roles', 'registered_date', 'protected', 'description', 'url', 'ban' );

	/** Group B fields requiring explicit request and edit_user. */
	public const GROUP_B = array( 'username', 'email', 'first_name', 'last_name', 'nickname', 'locale', 'meta' );

	/**
	 * Bind the object permission checker.
	 *
	 * @param Closure $can_edit Object permission checker.
	 * @phpstan-param Closure(int):bool $can_edit
	 */
	public function __construct( private Closure $can_edit ) {
	}

	/**
	 * Project a core user record without forwarding private properties.
	 *
	 * @param array<string,mixed> $record Normalized user record.
	 * @param array<string>       $fields Optional narrowing projection.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException For unknown projection input.
	 * @throws \UnexpectedValueException For missing identity.
	 * @throws \DomainException For refused sensitive access.
	 */
	public function project( array $record, array $fields = array() ): array {
		if ( ! array_is_list( $fields ) || count( $fields ) > count( self::FIELDS ) ) {
			throw new \InvalidArgumentException( 'Invalid user projection.' );
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! in_array( $field, self::FIELDS, true ) ) {
				throw new \InvalidArgumentException( 'Invalid user projection field.' );
			}
		}
		if ( ! isset( $record['id'] ) || ! is_int( $record['id'] ) || $record['id'] < 1 ) {
			throw new \UnexpectedValueException( 'User identity is unavailable.' );
		}
		$selected = $fields ? array_values( array_unique( array_merge( array( 'id' ), $fields ) ) ) : array( 'id', 'name', 'slug', 'status', 'roles', 'protected' );
		$needs_b  = array_intersect( $selected, self::GROUP_B );
		if ( $needs_b ) {
			$can = false;
			try {
				$can = (bool) ( $this->can_edit )( (int) $record['id'] );
			} catch ( \Throwable ) {
				$can = false;
			}
			if ( ! $can ) {
				throw new \DomainException( 'User sensitive data is unavailable.' );
			}
		}
		$result = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! in_array( $field, $selected, true ) ) {
				continue;
			}
			switch ( $field ) {
				case 'id':
				case 'name':
				case 'slug':
				case 'status':
				case 'roles':
				case 'protected':
				case 'description':
				case 'url':
				case 'username':
				case 'email':
				case 'first_name':
				case 'last_name':
				case 'nickname':
				case 'locale':
				case 'registered_date':
				case 'ban':
				case 'meta':
					if ( array_key_exists( $field, $record ) ) {
						$result[ $field ] = $record[ $field ];
					}
					break;
				case 'link':
					if ( isset( $record['link'] ) && is_string( $record['link'] ) && '' !== $record['link'] ) {
						$result['link'] = $record['link'];
					}
					break;
			}
		}
		$ordered = array();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$ordered[ $field ] = $result[ $field ];
			}
		}
		return $ordered;
	}

	/**
	 * Build a normalized record from a WP_User.
	 *
	 * @param \WP_User $user Core user.
	 * @param bool     $is_protected Protection flag.
	 * @return array<string,mixed>
	 */
	public static function record( \WP_User $user, bool $is_protected ): array {
		$status = in_array( 'banned', (array) $user->roles, true ) ? 'banned' : 'active';
		$record = array(
			'id'              => (int) $user->ID,
			'name'            => (string) ( $user->display_name ?? '' ),
			'slug'            => (string) ( $user->user_nicename ?? '' ),
			'status'          => $status,
			'roles'           => array_values( (array) $user->roles ),
			'protected'       => $is_protected,
			'description'     => (string) ( $user->description ?? '' ),
			'url'             => (string) ( $user->user_url ?? '' ),
			'username'        => (string) ( $user->user_login ?? '' ),
			'email'           => (string) ( $user->user_email ?? '' ),
			'first_name'      => (string) ( $user->first_name ?? '' ),
			'last_name'       => (string) ( $user->last_name ?? '' ),
			'nickname'        => (string) ( $user->nickname ?? '' ),
			'locale'          => (string) ( $user->locale ?? '' ),
			'registered_date' => self::registered_date( (string) ( $user->user_registered ?? '' ) ),
		);
		try {
			$link = get_author_posts_url( (int) $user->ID );
			if ( is_string( $link ) && '' !== $link ) {
				$record['link'] = $link;
			}
		} catch ( \Throwable $throwable ) {
			$ignored = $throwable->getMessage();
		}
		return $record;
	}

	/**
	 * Normalize a UTC datetime with Z suffix.
	 *
	 * @param string $raw Core datetime.
	 * @return string
	 */
	private static function registered_date( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$time = strtotime( $raw . ' UTC' );
		if ( false === $time ) {
			return $raw;
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
