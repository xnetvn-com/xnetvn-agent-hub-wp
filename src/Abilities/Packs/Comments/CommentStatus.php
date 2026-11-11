<?php
/**
 * Normalized comment statuses and the moderation transition table.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Abilities\Packs\Comments;

use WP_Error;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;

/** Core stores several spellings; tools expose exactly four states. */
final class CommentStatus {
	/** Reviewed states. */
	public const STATES = array( 'approved', 'hold', 'spam', 'trash' );
	/** Reviewed moderation actions. */
	public const ACTIONS = array( 'approve', 'hold', 'spam', 'unspam', 'trash', 'untrash' );
	/**
	 * Normalize a stored approval value.
	 *
	 * @param string $raw Stored comment_approved value.
	 * @return string|null Normalized state, null when out of scope.
	 */
	public static function normalize( string $raw ): ?string {
		if ( '1' === $raw || 'approve' === $raw || 'approved' === $raw ) {
			return 'approved';
		}
		if ( '0' === $raw || 'hold' === $raw || 'unapproved' === $raw || 'unapprove' === $raw ) {
			return 'hold';
		}
		if ( 'spam' === $raw ) {
			return 'spam';
		}
		if ( 'trash' === $raw ) {
			return 'trash';
		}
		return null;
	}
	/**
	 * Core verb used when a fresh state must be written.
	 *
	 * @param string $status Normalized state.
	 * @return string
	 */
	public static function to_core( string $status ): string {
		return 'approved' === $status ? 'approve' : $status;
	}
	/**
	 * Apply the transition table without touching the database.
	 *
	 * @param string $current Normalized current state.
	 * @param string $action Requested action.
	 * @return array{0:string,1:bool}|WP_Error Target state with changed flag, or invalid_state.
	 */
	public static function apply( string $current, string $action ): array|WP_Error {
		$terminal = ReasonMapper::error( 'invalid_state' );
		switch ( $action ) {
			case 'approve':
				if ( 'approved' === $current ) {
					return array( 'approved', false );
				}
				if ( 'hold' === $current ) {
					return array( 'approved', true );
				}
				return $terminal;
			case 'hold':
				if ( 'approved' === $current ) {
					return array( 'hold', true );
				}
				if ( 'hold' === $current ) {
					return array( 'hold', false );
				}
				return $terminal;
			case 'spam':
				if ( 'approved' === $current || 'hold' === $current ) {
					return array( 'spam', true );
				}
				if ( 'spam' === $current ) {
					return array( 'spam', false );
				}
				return $terminal;
			case 'trash':
				if ( 'approved' === $current || 'hold' === $current ) {
					return array( 'trash', true );
				}
				if ( 'trash' === $current ) {
					return array( 'trash', false );
				}
				return $terminal;
			case 'unspam':
				if ( 'spam' === $current ) {
					return array( 'restore', true );
				}
				return array( $current, false );
			case 'untrash':
				if ( 'trash' === $current ) {
					return array( 'restore', true );
				}
				return array( $current, false );
			default:
				return ReasonMapper::error( 'invalid_params' );
		}
	}
}
