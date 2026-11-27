<?php
/**
 * Bounded editorial author selection for attribution only.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Abilities\Packs\Content;

use Closure;
use WP_Error;
use WP_User;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Auth\DelegationEligibility;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;

/** Never creates, administers or impersonates a WordPress user. */
final class EditorialAuthors {

	/** Hard candidate bound for one lookup. */
	public const MAX_CANDIDATE_SCAN = 1000;

	/** Maximum projected display-name length. */
	private const NAME_LENGTH = 200;

	/** Live eligibility decision seam.
	 *
	 * @var Closure(int):Decision
	 */
	private Closure $eligibility;

	/**
	 * Bind the only live-eligibility seam.
	 *
	 * @param Closure(int):Decision|null $eligibility Optional reviewed decision.
	 */
	public function __construct( ?Closure $eligibility = null ) {
		$this->eligibility = $eligibility ?? static fn( int $id ): Decision => ( new DelegationEligibility() )->check( $id );
	}

	/**
	 * List the stored-baseline eligible current-site authors.
	 *
	 * @param int $page Requested page.
	 * @param int $per_page Requested page size.
	 * @return array<string,mixed>|WP_Error
	 */
	public function list_eligible( int $page = 1, int $per_page = 20 ): array|WP_Error {
		if ( $page < 1 || $per_page < 1 || $per_page > 100 || $page > intdiv( PHP_INT_MAX, $per_page ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		$db = $this->database();
		if ( null === $db ) {
			return ReasonMapper::error( 'internal_error' );
		}
		$db->last_error = '';
		$candidates     = get_users(
			array(
				'fields'        => 'ID',
				'capability'    => 'edit_posts',
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'number'        => self::MAX_CANDIDATE_SCAN + 1,
				'cache_results' => false,
			)
		);
		$error          = $this->database_error();
		if ( null === $error || '' !== $error ) {
			return ReasonMapper::error( 'internal_error' );
		}
		if ( count( $candidates ) > self::MAX_CANDIDATE_SCAN ) {
			return ReasonMapper::error( 'internal_error' );
		}
		$roles = wp_roles();
		if ( ! $roles instanceof \WP_Roles || ! is_array( $roles->roles ) || array() === $roles->roles ) {
			return ReasonMapper::error( 'internal_error' );
		}
		$eligible = array();
		foreach ( $this->normalize( $candidates ) as $id ) {
			$db->last_error = '';
			$user           = get_userdata( $id );
			$error          = $this->database_error();
			if ( null === $error || '' !== $error ) {
				return ReasonMapper::error( 'internal_error' );
			}
			if ( ! $user instanceof WP_User || (int) $user->ID !== $id ) {
				continue;
			}
			$db->last_error = '';
			$member         = is_user_member_of_blog( $id, get_current_blog_id() );
			$error          = $this->database_error();
			if ( null === $error || '' !== $error ) {
				return ReasonMapper::error( 'internal_error' );
			}
			if ( true !== $member || in_array( 'banned', (array) $user->roles, true ) || ! user_can( $user, 'edit_posts' ) ) {
				continue;
			}
			$decision = $this->decide( $id );
			if ( null === $decision ) {
				return ReasonMapper::error( 'internal_error' );
			}
			if ( ! $decision->allowed ) {
				if ( ! in_array( $decision->reason, array( 'delegation_protected_account', 'delegation_capability_missing' ), true ) ) {
					return ReasonMapper::error( 'internal_error' );
				}
				continue;
			}
			$eligible[] = array(
				'id'   => $id,
				'name' => mb_substr( sanitize_text_field( (string) $user->display_name ), 0, self::NAME_LENGTH ),
			);
		}
		$total = count( $eligible );
		return array(
			'items'       => array_slice( $eligible, ( $page - 1 ) * $per_page, $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Recheck one attribution target immediately before a write.
	 *
	 * @param int $author_id Attribution target.
	 * @return WP_User|WP_Error
	 */
	public function require_eligible( int $author_id ): WP_User|WP_Error {
		if ( $author_id < 1 ) {
			return ReasonMapper::error( 'forbidden' );
		}
		$db = $this->database();
		if ( null === $db ) {
			return ReasonMapper::error( 'internal_error' );
		}
		$db->last_error = '';
		$user           = get_userdata( $author_id );
		$error          = $this->database_error();
		if ( null === $error || '' !== $error ) {
			return ReasonMapper::error( 'internal_error' );
		}
		if ( ! $user instanceof WP_User || (int) $user->ID !== $author_id ) {
			return ReasonMapper::error( 'forbidden' );
		}
		$db->last_error = '';
		$member         = is_user_member_of_blog( $author_id, get_current_blog_id() );
		$error          = $this->database_error();
		if ( null === $error || '' !== $error ) {
			return ReasonMapper::error( 'internal_error' );
		}
		if ( true !== $member || in_array( 'banned', (array) $user->roles, true ) || ! user_can( $user, 'edit_posts' ) ) {
			return ReasonMapper::error( 'forbidden' );
		}
		$decision = $this->decide( $author_id );
		if ( null === $decision ) {
			return ReasonMapper::error( 'internal_error' );
		}
		if ( $decision->allowed ) {
			return $user;
		}
		return in_array( $decision->reason, array( 'delegation_role_state_unavailable', 'delegation_check_failed' ), true )
			? ReasonMapper::error( 'internal_error' )
			: ReasonMapper::error( 'forbidden' );
	}

	/**
	 * Run the machine path with a fixed current-blog check and strict bounds.
	 *
	 * @param array<string,mixed> $arguments Logical arguments.
	 * @param SiteAuthContext     $context Verified machine identity.
	 * @return mixed
	 */
	public function handle( array $arguments, SiteAuthContext $context ): mixed {
		if ( $context->blog_id() !== get_current_blog_id() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_tool_unavailable', 'The tool is unavailable.' );
		}
		$page     = $arguments['page'] ?? 1;
		$per_page = $arguments['per_page'] ?? 20;
		if ( ! is_int( $page ) || ! is_int( $per_page ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return $this->list_eligible( $page, $per_page );
	}

	/**
	 * Return the only machine handler for the fixed built-in name.
	 *
	 * @return Closure(array<string,mixed>,SiteAuthContext):mixed
	 */
	public static function handler(): Closure {
		return static fn( array $arguments, SiteAuthContext $context ): mixed => ( new self() )->handle( $arguments, $context );
	}

	/**
	 * Read the global database object without trusting request data.
	 *
	 * @return \wpdb|null Null when the global is missing or unreadable.
	 */
	private function database(): ?\wpdb {
		$db = $GLOBALS['wpdb'] ?? null;
		return $db instanceof \wpdb ? $db : null;
	}

	/**
	 * Read the observable database error from the global state.
	 *
	 * @return string|null Null when the global object is missing or unreadable.
	 */
	private function database_error(): ?string {
		$db = $GLOBALS['wpdb'] ?? null;
		return $db instanceof \wpdb ? $db->last_error : null;
	}

	/**
	 * Normalize candidate identifiers to unique positive integers.
	 *
	 * @param array<mixed> $candidates Raw candidate values.
	 * @return array<int>
	 */
	private function normalize( array $candidates ): array {
		$ids = array();
		foreach ( $candidates as $candidate ) {
			if ( $candidate instanceof WP_User ) {
				$id = (int) $candidate->ID;
			} elseif ( is_int( $candidate ) ) {
				$id = $candidate;
			} elseif ( is_string( $candidate ) && ctype_digit( $candidate ) ) {
				$id = (int) $candidate;
			} else {
				continue;
			}
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Run the live eligibility seam.
	 *
	 * @param int $id Candidate identity.
	 * @return Decision|null Null when the seam itself is unusable.
	 */
	private function decide( int $id ): ?Decision {
		try {
			return ( $this->eligibility )( $id );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
