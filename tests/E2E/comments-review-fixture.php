<?php
/**
 * Retained comment-review fixture: seeds owned review objects and removes only
 * run-scoped credentials plus deliberately disposable safety artifacts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E;

use DateTimeImmutable;
use RuntimeException;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentStatus;
use XNetVN\AgentHubWP\Auth\KeyGenerator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Support\Json;
use XNetVN\AgentHubWP\Support\SystemClock;
use WP_Comment_Query;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Root-only fixture files require definite POSIX permissions, not an interactive filesystem transport.

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	exit( 2 );
}

/**
 * Owned review fixture. It never deletes a review comment, post, user or option.
 */
final class CommentsReviewFixture {

	/** Retained review marker root. */
	private const REVIEW_MARKER = 'CMT-REVIEW';
	/** Deliberately disposable lifecycle marker root. */
	private const EPHEMERAL_MARKER = 'CMT-EPHEMERAL';
	/** Safety sentinel that must block any purge deletion. */
	private const SENTINEL_MARKER = 'CMT-FOREIGN-SENTINEL';
	/** Maximum disposable rows reconciled in one setup. */
	private const DISPOSABLE_LIMIT = 500;

	/**
	 * Lab site.
	 *
	 * @var string
	 */
	private string $site;
	/**
	 * Run identifier.
	 *
	 * @var string
	 */
	private string $run_id;
	/**
	 * Run artifact directory.
	 *
	 * @var string
	 */
	private string $run_dir;
	/**
	 * Private state path.
	 *
	 * @var string
	 */
	private string $state_path;
	/**
	 * Review option key.
	 *
	 * @var string
	 */
	private string $option_key;
	/**
	 * Key repository.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;
	/**
	 * Retained review state.
	 *
	 * @var array<string,mixed>
	 */
	private array $review;
	/**
	 * Private run state, including tokens.
	 *
	 * @var array<string,mixed>
	 */
	private array $state = array();

	/**
	 * Bind one lab site and run.
	 *
	 * @param string            $action Requested action.
	 * @param array<int,string> $args   WP-CLI positional arguments.
	 * @throws RuntimeException For unsafe or incomplete input.
	 */
	public function __construct( private string $action, private array $args ) {
		$this->site       = (string) getenv( 'XNETVN_MATRIX_SITE' );
		$this->run_id     = (string) getenv( 'COMMENTS_REVIEW_RUN_ID' );
		$this->run_dir    = (string) getenv( 'COMMENTS_REVIEW_RUN_DIR' );
		$this->state_path = (string) getenv( 'COMMENTS_REVIEW_STATE' );
		if ( ! in_array( $this->site, array( 'wp69', 'wp71' ), true ) || 1 !== preg_match( '/\A[A-Za-z0-9._-]{1,80}\z/', $this->run_id ) || '' === $this->run_dir || '' === $this->state_path ) {
			throw new RuntimeException( 'Comments review fixture input is invalid.' );
		}
		if ( is_link( $this->run_dir ) || is_link( $this->state_path ) ) {
			throw new RuntimeException( 'Comments review fixture state ownership mismatch.' );
		}
		$this->option_key = 'xnetvn_agent_hub_wp_comments_review_' . $this->site;
		global $wpdb;
		$this->keys   = new WpdbKeyRepository( $wpdb, new SystemClock() );
		$this->review = in_array( $this->action, array( 'cleanup-keys', 'restore-settings', 'delete-state' ), true ) ? array(
			'marker'           => '',
			'users'            => array(),
			'posts'            => array(),
			'known_review_ids' => array(),
		) : $this->load_or_seed_review( 'setup' === $this->action );
	}

	/**
	 * Execute one fixture action.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For a refused or failed action.
	 */
	public function run(): array {
		return match ( $this->action ) {
			'setup'          => $this->setup(),
			'status'         => $this->status(),
			'purge-audit'    => $this->purge_audit(),
			'restore-review' => $this->restore_review(),
			'cleanup-keys'   => $this->cleanup_keys(),
			'restore-settings' => $this->restore_settings(),
			'delete-state'   => $this->delete_state(),
			default          => throw new RuntimeException( 'Comments review fixture action is invalid.' ),
		};
	}

	/**
	 * Create or reuse the retained review objects, then seed one run.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For an existing private state or failed seed.
	 */
	private function setup(): array {
		if ( file_exists( $this->state_path ) ) {
			throw new RuntimeException( 'Reconcile the existing comments review state before setup.' );
		}
		wp_mkdir_p( $this->run_dir );
		chmod( $this->run_dir, 0700 );
		wp_mkdir_p( dirname( $this->state_path ) );
		chmod( dirname( $this->state_path ), 0700 );
		$live    = $this->live_review_ids();
		$missing = array_values( array_diff( $this->review['known_review_ids'], $live ) );
		if ( ! empty( $missing ) ) {
			throw new RuntimeException( 'Review comment missing before setup: ' . implode( ',', array_map( 'intval', $missing ) ) );
		}
		$cleaned                               = $this->cleanup_disposable();
		$this->state                           = array(
			'version'    => 1,
			'site'       => $this->site,
			'run_id'     => $this->run_id,
			'option_key' => $this->option_key,
			'users'      => $this->review['users'],
			'posts'      => $this->review['posts'],
			'keys'       => array(),
			'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		$settings                              = ( new WpSiteSettings() )->all();
		$this->state['rate_limit_key_per_min'] = (int) ( $settings['rate_limit_key_per_min'] ?? 0 );
		$this->save_state();
		( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => 1000 ) );
		$this->create_keys();
		$pingback = $this->insert_pingback();
		$sentinel = $this->insert_sentinel();
		$status   = $this->status();
		return array(
			'suite'                    => 'comments-review',
			'site'                     => $this->site,
			'run_id'                   => $this->run_id,
			'retention'                => (string) getenv( 'COMMENTS_REVIEW_RETENTION' ),
			'marker'                   => $this->review['marker'],
			'option_key'               => $this->option_key,
			'users'                    => $this->review['users'],
			'posts'                    => $this->review['posts'],
			'keys'                     => array_keys( $this->state['keys'] ),
			'counts'                   => $status['counts'],
			'review_counts'            => $status['review_counts'],
			'review_ids'               => $status['review_ids'],
			'review_statuses'          => $status['review_statuses'],
			'pingback'                 => $pingback,
			'sentinel'                 => $sentinel,
			'cleaned_prior_disposable' => $cleaned,
		);
	}

	/**
	 * Read retained review state without exposing credentials or content.
	 *
	 * @return array<string,mixed>
	 */
	private function status(): array {
		$comments      = array();
		$ids           = array();
		$states        = array();
		$counts        = array(
			'approved' => 0,
			'hold'     => 0,
			'spam'     => 0,
			'trash'    => 0,
			'all'      => 0,
			'total'    => 0,
		);
		$review_counts = array(
			'approved' => 0,
			'hold'     => 0,
			'spam'     => 0,
			'trash'    => 0,
			'all'      => 0,
			'total'    => 0,
		);
		foreach ( $this->review_posts() as $post_id ) {
			$post_counts         = wp_count_comments( $post_id );
			$counts['approved'] += (int) $post_counts->approved;
			$counts['hold']     += (int) $post_counts->moderated;
			$counts['spam']     += (int) $post_counts->spam;
			$counts['trash']    += (int) $post_counts->trash;
			$counts['all']      += (int) $post_counts->approved + (int) $post_counts->moderated;
			$counts['total']    += (int) $post_counts->total_comments;
			$seen                = array();
			foreach ( array( 'approve', 'hold', 'spam', 'trash' ) as $review_status ) {
				foreach ( $this->review_comments( $post_id, $review_status ) as $comment ) {
					if ( isset( $seen[ $comment->comment_ID ] ) ) {
						continue;
					}
					$seen[ $comment->comment_ID ] = true;
					$normalized                   = CommentStatus::normalize( (string) $comment->comment_approved );
					if ( null === $normalized ) {
						continue;
					}
					$ids[]                          = (int) $comment->comment_ID;
					$states[ $comment->comment_ID ] = $normalized;
					++$review_counts[ $normalized ];
					++$review_counts['total'];
					if ( 'approved' === $normalized || 'hold' === $normalized ) {
						++$review_counts['all'];
					}
					$comments[] = array(
						'id'        => (int) $comment->comment_ID,
						'post'      => (int) $comment->comment_post_ID,
						'author'    => (int) $comment->user_id,
						'status'    => $normalized,
						'marker_ok' => $this->is_review_content( (string) $comment->comment_content ),
					);
				}
			}
		}
		$saved = get_option( $this->option_key, array() );
		if ( is_array( $saved ) ) {
			$saved['review_ids'] = $ids;
			update_option( $this->option_key, $saved, false );
		}
		return array(
			'suite'           => 'comments-review-status',
			'site'            => $this->site,
			'marker'          => $this->review['marker'],
			'posts'           => $this->review['posts'],
			'users'           => $this->review['users'],
			'counts'          => $counts,
			'review_counts'   => $review_counts,
			'review_ids'      => $ids,
			'review_statuses' => $states,
			'comments'        => $comments,
			'total_comments'  => count( $comments ),
		);
	}

	/**
	 * Read every spam/trash candidate and classify ownership before purge.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For invalid input.
	 */
	private function purge_audit(): array {
		$status = (string) ( $this->args[1] ?? '' );
		$before = (string) ( $this->args[2] ?? '' );
		if ( ! in_array( $status, array( 'spam', 'trash' ), true ) ) {
			throw new RuntimeException( 'Purge audit status is invalid.' );
		}
		$cutoff = '' === $before ? time() : strtotime( $before );
		if ( false === $cutoff ) {
			throw new RuntimeException( 'Purge audit cutoff is invalid.' );
		}
		$cutoff = min( $cutoff, time() );
		$query  = new WP_Comment_Query(
			array(
				'status'        => $status,
				'type__in'      => array( 'comment', 'pingback', 'trackback' ),
				'date_query'    => array(
					array(
						'column'    => 'comment_date_gmt',
						'before'    => gmdate( 'Y-m-d H:i:s', $cutoff ),
						'inclusive' => false,
					),
				),
				'orderby'       => 'comment_ID',
				'order'         => 'ASC',
				'number'        => 0,
				'fields'        => 'ids',
				'no_found_rows' => false,
			)
		);
		$ids    = $query->get_comments();
		$result = array(
			'suite'         => 'comments-review-purge-audit',
			'site'          => $this->site,
			'status'        => $status,
			'before'        => gmdate( 'Y-m-d\TH:i:s\Z', $cutoff ),
			'total'         => count( $ids ),
			'review'        => 0,
			'ephemeral'     => 0,
			'sentinel'      => 0,
			'foreign'       => 0,
			'review_ids'    => array(),
			'ephemeral_ids' => array(),
			'sentinel_ids'  => array(),
		);
		foreach ( $ids as $id ) {
			$comment = get_comment( (int) $id );
			if ( null === $comment ) {
				continue;
			}
			$class = $this->classify( $comment );
			++$result[ $class ];
			if ( 'review' === $class ) {
				$result['review_ids'][] = (int) $id;
			} elseif ( 'ephemeral' === $class ) {
				$result['ephemeral_ids'][] = (int) $id;
			} elseif ( 'sentinel' === $class ) {
				$result['sentinel_ids'][] = (int) $id;
			}
		}
		return $result;
	}

	/**
	 * Restore review spam/trash to the state core recorded before the moderation.
	 *
	 * @return array<string,mixed>
	 */
	private function restore_review(): array {
		$restored = array();
		$failed   = array();
		foreach ( $this->review_posts() as $post_id ) {
			foreach ( array( 'spam', 'trash' ) as $status ) {
				foreach ( $this->review_comments( $post_id, $status ) as $comment ) {
					$previous = CommentStatus::normalize( (string) $comment->comment_approved );
					$ok       = 'spam' === $previous ? wp_unspam_comment( (int) $comment->comment_ID ) : wp_untrash_comment( (int) $comment->comment_ID );
					clean_comment_cache( (int) $comment->comment_ID );
					$after      = get_comment( (int) $comment->comment_ID );
					$final      = null === $after ? null : CommentStatus::normalize( (string) $after->comment_approved );
					$row        = array(
						'id'   => (int) $comment->comment_ID,
						'from' => $previous,
						'to'   => $final,
						'ok'   => (bool) $ok && null !== $final && 'spam' !== $final && 'trash' !== $final,
					);
					$restored[] = $row;
					if ( ! $row['ok'] ) {
						$failed[] = (int) $comment->comment_ID;
					}
				}
			}
		}
		$remaining = array();
		foreach ( $this->review_posts() as $post_id ) {
			foreach ( array( 'spam', 'trash' ) as $status ) {
				foreach ( $this->review_comments( $post_id, $status ) as $comment ) {
					$remaining[] = (int) $comment->comment_ID;
				}
			}
		}
		$live    = $this->live_review_ids();
		$missing = array_values( array_diff( $this->review['known_review_ids'], $live ) );
		return array(
			'suite'            => 'comments-review-restore',
			'site'             => $this->site,
			'restored'         => $restored,
			'failed'           => $failed,
			'remaining_review' => $remaining,
			'missing_review'   => $missing,
		);
	}

	/**
	 * Delete only this run's credentials and the private state file.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For a binding mismatch or failed deletion.
	 */
	private function cleanup_keys(): array {
		if ( ! is_file( $this->state_path ) ) {
			return array(
				'suite'        => 'comments-review-cleanup',
				'site'         => $this->site,
				'keys_deleted' => 0,
				'keys_absent'  => 0,
				'state'        => 'absent',
			);
		}
		$state = Json::array( (string) file_get_contents( $this->state_path ) );
		if ( ( $state['site'] ?? '' ) !== $this->site || ( $state['run_id'] ?? '' ) !== $this->run_id ) {
			throw new RuntimeException( 'Comments review cleanup binding mismatch.' );
		}
		$users   = array_map( 'intval', (array) ( $state['users'] ?? array() ) );
		$deleted = 0;
		$absent  = 0;
		foreach ( (array) ( $state['keys'] ?? array() ) as $key ) {
			$id  = (int) ( $key['id'] ?? 0 );
			$row = $this->keys->find_by_id( $id );
			if ( null === $row ) {
				++$absent;
				continue;
			}
			if ( ! in_array( (int) $row->user_id, $users, true ) || ! str_starts_with( $row->name, 'Comments review ' . $this->run_id . ' ' ) ) {
				throw new RuntimeException( 'Comments review key cleanup binding mismatch.' );
			}
			global $wpdb;
			$ok = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE id = %d AND key_id = %s AND user_id = %d',
					$wpdb->prefix . 'xnetvn_agent_hub_wp_keys',
					$row->id,
					$row->key_id->value,
					$row->user_id
				)
			);
			if ( false === $ok || null !== $this->keys->find_by_id( $row->id ) ) {
				throw new RuntimeException( 'Comments review key cleanup failed.' );
			}
			++$deleted;
		}
		$rate_limit = $this->restore_rate_limit( $state );
		return array(
			'suite'        => 'comments-review-cleanup',
			'site'         => $this->site,
			'keys_deleted' => $deleted,
			'keys_absent'  => $absent,
			'state'        => 'keys_removed',
			'rate_limit'   => $rate_limit,
		);
	}

	/**
	 * Restore the saved per-key rate limit without touching keys or comments.
	 *
	 * @return array<string,mixed>
	 */
	private function restore_settings(): array {
		if ( ! is_file( $this->state_path ) ) {
			return array(
				'suite'      => 'comments-review-settings',
				'site'       => $this->site,
				'rate_limit' => 'absent',
				'state'      => 'absent',
			);
		}
		$state = $this->read_state();
		return array(
			'suite'      => 'comments-review-settings',
			'site'       => $this->site,
			'rate_limit' => $this->restore_rate_limit( $state ),
			'state'      => 'keys_retained',
		);
	}

	/**
	 * Delete the private state only after retention verification.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For a binding mismatch or failed deletion.
	 */
	private function delete_state(): array {
		if ( ! is_file( $this->state_path ) ) {
			return array(
				'suite' => 'comments-review-state',
				'site'  => $this->site,
				'state' => 'absent',
			);
		}
		$this->read_state();
		if ( ! unlink( $this->state_path ) ) {
			throw new RuntimeException( 'Comments review state cleanup failed.' );
		}
		return array(
			'suite' => 'comments-review-state',
			'site'  => $this->site,
			'state' => 'removed',
		);
	}

	/**
	 * Read and bind the private state.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException For an absent or foreign state.
	 */
	private function read_state(): array {
		if ( ! is_file( $this->state_path ) ) {
			throw new RuntimeException( 'Comments review state is absent.' );
		}
		$state = Json::array( (string) file_get_contents( $this->state_path ) );
		if ( ( $state['site'] ?? '' ) !== $this->site || ( $state['run_id'] ?? '' ) !== $this->run_id ) {
			throw new RuntimeException( 'Comments review state binding mismatch.' );
		}
		return $state;
	}

	/**
	 * Restore only the rate-limit field when it is still the run value.
	 *
	 * @param array<string,mixed> $state Private state.
	 * @return string restored|skipped_changed|absent
	 */
	private function restore_rate_limit( array $state ): string {
		if ( ! array_key_exists( 'rate_limit_key_per_min', $state ) ) {
			return 'absent';
		}
		$current = ( new WpSiteSettings() )->all();
		if ( 1000 !== (int) ( $current['rate_limit_key_per_min'] ?? 0 ) ) {
			return 'skipped_changed';
		}
		( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => (int) $state['rate_limit_key_per_min'] ) );
		return 'restored';
	}

	/**
	 * Return live review comment IDs for the missing-ledger check.
	 *
	 * @return array<int>
	 */
	private function live_review_ids(): array {
		$ids = array();
		foreach ( $this->review_posts() as $post_id ) {
			foreach ( array( 'approve', 'hold', 'spam', 'trash' ) as $status ) {
				foreach ( $this->review_comments( $post_id, $status ) as $comment ) {
					$ids[] = (int) $comment->comment_ID;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Merge the retained option with every prior run ledger.
	 *
	 * @param array<string,mixed> $saved Retained option.
	 * @return array<int>
	 */
	private function known_review_ids( array $saved ): array {
		$known    = array_map( 'intval', (array) ( $saved['review_ids'] ?? array() ) );
		$site_dir = dirname( $this->run_dir );
		$files    = glob( $site_dir . '/run-*/ledger.json' );
		foreach ( false === $files ? array() : $files as $file ) {
			if ( ! is_file( $file ) || is_link( $file ) ) {
				continue;
			}
			try {
				$data = Json::array( (string) file_get_contents( $file ) );
			} catch ( \Throwable ) {
				continue;
			}
			$known = array_merge( $known, array_map( 'intval', (array) ( $data['review'] ?? array() ) ) );
		}
		$known = array_values( array_unique( array_filter( $known ) ) );
		sort( $known );
		return $known;
	}

	/**
	 * Create one key per reviewed scope and persist it before the next mutation.
	 *
	 * @throws RuntimeException For an unavailable key owner.
	 */
	private function create_keys(): void {
		$pepper = ( new PepperStore() )->current();
		$config = array(
			'editor'    => array( 'editor', RiskTier::Destructive, array( 'comments' ) ),
			'moderator' => array( 'moderator', RiskTier::Write, array( 'comments' ) ),
			'read'      => array( 'editor', RiskTier::Read, array( 'comments' ) ),
			'content'   => array( 'editor', RiskTier::Write, array( 'content' ) ),
			'author'    => array( 'author', RiskTier::Write, array( 'comments' ) ),
		);
		foreach ( $config as $kind => $item ) {
			$owner                        = (int) $this->review['users'][ $item[0] ];
			$scope                        = new Scope( $item[1], $item[2], array(), array(), array(), new DateTimeImmutable( '+1 day' ) );
			$key                          = ( new KeyGenerator() )->generate();
			$row                          = $this->keys->insert(
				$key->id,
				$owner,
				'Comments review ' . $this->run_id . ' ' . $kind,
				$scope,
				$key->hash_with( $pepper ),
				$pepper->id
			);
			$this->state['keys'][ $kind ] = array(
				'id'    => $row->id,
				'token' => $key->reveal(),
			);
			$this->save_state();
		}
	}

	/**
	 * Insert one disposable approved pingback for the type allowlist checks.
	 *
	 * @return int
	 * @throws RuntimeException For an unavailable review post.
	 */
	private function insert_pingback(): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $this->review['posts']['review'],
				'comment_author'       => 'Comments review pingback',
				'comment_author_email' => 'comments-review-pingback@example.test',
				'comment_author_url'   => 'https://example.test',
				'comment_content'      => '[' . self::EPHEMERAL_MARKER . ':' . $this->site . ':' . $this->run_id . ':pingback] Pingback fixture.',
				'comment_type'         => 'pingback',
				'comment_approved'     => 1,
				'user_id'              => 0,
			)
		);
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new RuntimeException( 'Comments review pingback seed failed.' );
		}
		return $id;
	}

	/**
	 * Insert one foreign-authored spam sentinel that must block purge.
	 *
	 * @return int
	 * @throws RuntimeException For an unavailable review post or owner.
	 */
	private function insert_sentinel(): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => (int) $this->review['posts']['review'],
				'comment_author'       => 'Comments review foreign sentinel',
				'comment_author_email' => 'comments-review-foreign@example.test',
				'comment_content'      => '[' . self::SENTINEL_MARKER . ':' . $this->site . ':' . $this->run_id . '] Safety sentinel.',
				'comment_type'         => 'comment',
				'comment_approved'     => 'spam',
				'user_id'              => (int) $this->review['users']['foreign'],
			)
		);
		if ( ! is_int( $id ) || $id < 1 ) {
			throw new RuntimeException( 'Comments review sentinel seed failed.' );
		}
		return $id;
	}

	/**
	 * Remove only prior disposable pingbacks, sentinels and ephemeral comments.
	 *
	 * @return array<int>
	 * @throws RuntimeException For an unbounded disposable set.
	 */
	private function cleanup_disposable(): array {
		$ids   = array();
		$known = array_map( 'intval', (array) ( $this->review['known_review_ids'] ?? array() ) );
		foreach ( $this->review_posts() as $post_id ) {
			foreach ( array( 'approve', 'hold', 'spam', 'trash' ) as $disposable_status ) {
				$comments = get_comments(
					array(
						'post_id' => $post_id,
						'status'  => $disposable_status,
						'type'    => 'comment',
						'orderby' => 'comment_ID',
						'order'   => 'ASC',
						'number'  => 0,
					)
				);
				foreach ( $comments as $comment ) {
					if ( in_array( (int) $comment->comment_ID, $known, true ) ) {
						continue;
					}
					if ( ( $this->is_ephemeral_content( (string) $comment->comment_content ) && in_array( (int) $comment->user_id, array_map( 'intval', $this->review['users'] ), true ) ) || $this->is_sentinel_content( (string) $comment->comment_content ) ) {
						$ids[] = (int) $comment->comment_ID;
					}
				}
				$pingbacks = get_comments(
					array(
						'post_id' => $post_id,
						'status'  => $disposable_status,
						'type'    => 'pingback',
						'orderby' => 'comment_ID',
						'order'   => 'ASC',
						'number'  => 0,
					)
				);
				foreach ( $pingbacks as $comment ) {
					if ( in_array( (int) $comment->comment_ID, $known, true ) ) {
						continue;
					}
					if ( $this->is_ephemeral_content( (string) $comment->comment_content ) ) {
						$ids[] = (int) $comment->comment_ID;
					}
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( count( $ids ) > self::DISPOSABLE_LIMIT ) {
			throw new RuntimeException( 'Comments review disposable set exceeds the bound.' );
		}
		foreach ( $ids as $id ) {
			if ( ! wp_delete_comment( $id, true ) ) {
				throw new RuntimeException( 'Comments review disposable cleanup failed.' );
			}
		}
		return $ids;
	}

	/**
	 * Load retained objects or create them once with explicit markers.
	 *
	 * @param bool $seed Whether setup may create the retained objects.
	 * @return array<string,mixed>
	 * @throws RuntimeException For a marker or object mismatch.
	 */
	private function load_or_seed_review( bool $seed ): array {
		$marker = strtoupper( self::REVIEW_MARKER ) . '-' . $this->site;
		$saved  = get_option( $this->option_key, array() );
		if ( ! is_array( $saved ) || ! isset( $saved['marker'] ) ) {
			if ( ! $seed ) {
				throw new RuntimeException( 'Comments review fixture is not seeded.' );
			}
			$saved = $this->seed_review( $marker );
		}
		if ( ( $saved['marker'] ?? '' ) !== $marker ) {
			throw new RuntimeException( 'Comments review option marker mismatch.' );
		}
		foreach ( array(
			'editor'    => 'editor',
			'author'    => 'author',
			'moderator' => 'author',
			'foreign'   => 'author',
		) as $kind => $role ) {
			$user = get_userdata( (int) ( $saved['users'][ $kind ] ?? 0 ) );
			if ( false === $user || ! str_starts_with( $user->user_login, 'cmt-review-' . $this->site . '-' ) || (int) get_user_meta( $user->ID, 'xnetvn_comments_review_user', true ) !== 1 ) {
				throw new RuntimeException( 'Comments review user mismatch.' );
			}
			if ( ! in_array( $role, (array) $user->roles, true ) ) {
				$user->set_role( $role );
			}
			if ( 'moderator' === $kind ) {
				$user->add_cap( 'moderate_comments' );
			}
		}
		if ( ! user_can( (int) $saved['users']['moderator'], 'moderate_comments' ) ) {
			$moderator = new \WP_User( (int) $saved['users']['moderator'] );
			$moderator->add_cap( 'moderate_comments' );
		}
		foreach ( array( 'review', 'author', 'moderator', 'closed' ) as $kind ) {
			$post = get_post( (int) ( $saved['posts'][ $kind ] ?? 0 ) );
			if ( null === $post || (string) get_post_meta( $post->ID, '_xnetvn_comments_review_post', true ) !== $marker . ':' . $kind ) {
				throw new RuntimeException( 'Comments review post mismatch.' );
			}
		}
		return array(
			'marker'           => $marker,
			'users'            => array_map( 'intval', (array) $saved['users'] ),
			'posts'            => array_map( 'intval', (array) $saved['posts'] ),
			'known_review_ids' => $this->known_review_ids( $saved ),
		);
	}

	/**
	 * Create retained users and posts exactly once.
	 *
	 * @param string $marker Review marker root.
	 * @return array<string,mixed>
	 * @throws RuntimeException For a failed seed.
	 */
	private function seed_review( string $marker ): array {
		$users = array();
		foreach ( array(
			'editor'    => 'editor',
			'author'    => 'author',
			'moderator' => 'author',
			'foreign'   => 'author',
		) as $kind => $role ) {
			$login = 'cmt-review-' . $this->site . '-' . $kind;
			$user  = get_user_by( 'login', $login );
			if ( false === $user ) {
				$id = wp_insert_user(
					array(
						'user_login' => $login,
						'user_pass'  => wp_generate_password( 48, true, true ),
						'user_email' => $login . '@example.test',
						'role'       => $role,
					)
				);
				if ( is_wp_error( $id ) ) {
					throw new RuntimeException( 'Comments review user seed failed.' );
				}
				$user = get_userdata( (int) $id );
			}
			if ( false === $user ) {
				throw new RuntimeException( 'Comments review user seed unavailable.' );
			}
			update_user_meta( $user->ID, 'xnetvn_comments_review_user', 1 );
			if ( 'moderator' === $kind ) {
				$user->add_cap( 'moderate_comments' );
			}
			$users[ $kind ] = (int) $user->ID;
		}
		$posts = array();
		foreach ( array(
			'review'    => array( $users['editor'], 'open', 'Comments MCP review fixture (retained)' ),
			'author'    => array( $users['author'], 'open', 'Comments MCP review other-owner fixture' ),
			'moderator' => array( $users['moderator'], 'open', 'Comments MCP review moderator fixture' ),
			'closed'    => array( $users['editor'], 'closed', 'Comments MCP review closed fixture' ),
		) as $kind => $config ) {
			$existing = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_xnetvn_comments_review_post',
					'meta_value'     => $marker . ':' . $kind,
				)
			);
			if ( $existing ) {
				$posts[ $kind ] = (int) $existing[0]->ID;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_title'     => $config[2],
					'post_content'   => '[' . $marker . ':' . $kind . '] Retained comments review fixture. Do not delete without an explicit owner request.',
					'post_status'    => 'publish',
					'post_author'    => (int) $config[0],
					'comment_status' => (string) $config[1],
					'ping_status'    => 'closed',
					'meta_input'     => array( '_xnetvn_comments_review_post' => $marker . ':' . $kind ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( 'Comments review post seed failed.' );
			}
			$posts[ $kind ] = (int) $id;
		}
		$saved = array(
			'version'    => 1,
			'marker'     => $marker,
			'users'      => $users,
			'posts'      => $posts,
			'review_ids' => array(),
		);
		update_option( $this->option_key, $saved, false );
		return $saved;
	}

	/**
	 * Persist private state before the next mutation.
	 *
	 * @throws RuntimeException For unavailable persistence.
	 */
	private function save_state(): void {
		if ( false === file_put_contents( $this->state_path, Json::encode( $this->state ), LOCK_EX ) ) {
			throw new RuntimeException( 'Comments review state persistence failed.' );
		}
		chmod( $this->state_path, 0600 );
	}

	/**
	 * Return the two posts that hold retained review comments.
	 *
	 * @return array<int>
	 */
	private function review_posts(): array {
		return array( (int) $this->review['posts']['review'], (int) $this->review['posts']['moderator'] );
	}

	/**
	 * Return owned review comments for one post and status.
	 *
	 * @param int    $post_id Post.
	 * @param string $status Status.
	 * @return array<\WP_Comment>
	 */
	private function review_comments( int $post_id, string $status ): array {
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => $status,
				'type'    => 'comment',
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
				'number'  => 0,
			)
		);
		$owned    = array();
		foreach ( $comments as $comment ) {
			if ( $this->is_review_content( (string) $comment->comment_content ) && in_array( (int) $comment->user_id, array_map( 'intval', $this->review['users'] ), true ) ) {
				$owned[] = $comment;
			}
		}
		return $owned;
	}

	/**
	 * Classify one purge candidate without exposing foreign content.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return string review|ephemeral|sentinel|foreign
	 */
	private function classify( \WP_Comment $comment ): string {
		if ( in_array( (int) $comment->comment_ID, $this->review['known_review_ids'], true ) ) {
			return 'review';
		}
		if ( $this->is_review_content( (string) $comment->comment_content ) && in_array( (int) $comment->user_id, array_map( 'intval', $this->review['users'] ), true ) ) {
			return 'review';
		}
		if ( $this->is_ephemeral_content( (string) $comment->comment_content ) && in_array( (int) $comment->user_id, array_map( 'intval', $this->review['users'] ), true ) ) {
			return 'ephemeral';
		}
		if ( $this->is_sentinel_content( (string) $comment->comment_content ) ) {
			return 'sentinel';
		}
		return 'foreign';
	}

	/**
	 * Check the retained review marker.
	 *
	 * @param string $content Stored content.
	 * @return bool
	 */
	private function is_review_content( string $content ): bool {
		return str_starts_with( $content, '[' . self::REVIEW_MARKER . ':' . $this->site . ':' );
	}

	/**
	 * Check the disposable lifecycle marker.
	 *
	 * @param string $content Stored content.
	 * @return bool
	 */
	private function is_ephemeral_content( string $content ): bool {
		return str_starts_with( $content, '[' . self::EPHEMERAL_MARKER . ':' . $this->site . ':' );
	}

	/**
	 * Check the foreign safety sentinel marker.
	 *
	 * @param string $content Stored content.
	 * @return bool
	 */
	private function is_sentinel_content( string $content ): bool {
		return str_starts_with( $content, '[' . self::SENTINEL_MARKER . ':' . $this->site . ':' );
	}
}

try {
	$xnetvn_agent_hub_wp_comments_review_fixture = new CommentsReviewFixture( (string) ( $args[0] ?? 'setup' ), array_map( 'strval', (array) $args ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed CLI JSON summary without user input.
	fwrite( STDOUT, Json::encode( $xnetvn_agent_hub_wp_comments_review_fixture->run() ) . PHP_EOL );
} catch ( \Throwable $failure ) {
	fwrite( STDERR, 'comments-review-fixture: ' . $failure->getMessage() . PHP_EOL );
	exit( 1 );
}
