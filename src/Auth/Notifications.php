<?php
/**
 * Configurable credential lifecycle notifications.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\SiteCredential;

/** Buffers non-secret notices so mail never runs inside the owner mutation lock. */
final class Notifications implements ServiceProvider {
	/** Daily seven-day expiry check. */
	public const HOOK = 'xnetvn_agent_hub_wp_notify_expiring_keys';
	/** Bounded continuation for larger sites. */
	public const BATCH_HOOK = 'xnetvn_agent_hub_wp_notify_expiring_keys_batch';
	/** Non-secret pending notices.
	 *
	 * @var array<string,array{key:ApiKey,kind:string,marker:?string}>
	 */
	private array $pending = array();
	/** Pending site-administration notices keyed by deduplication marker.
	 *
	 * @var array<string,array{key:SiteCredential,marker:string,until:int}>
	 */
	private array $site_pending = array();
	/** Blog this provider was constructed on.
	 *
	 * @var int
	 */
	private int $blog_id;

	/**
	 * Bind credential metadata and owner notification choices.
	 *
	 * @param KeyRepository          $keys Safe metadata.
	 * @param SiteSettings           $settings Site preferences.
	 * @param Clock                  $clock UTC clock.
	 * @param SiteKeyRepository|null $site_keys Optional site-key metadata.
	 */
	public function __construct( private KeyRepository $keys, private SiteSettings $settings, private Clock $clock, private ?SiteKeyRepository $site_keys = null ) {
		$this->blog_id = max( 1, (int) get_current_blog_id() );
	}

	/** Register lifecycle hooks and the daily expiry schedule. */
	public function register(): void {
		add_action( 'xnetvn_agent_hub_wp_key_created', array( $this, 'created' ) );
		add_action( 'xnetvn_agent_hub_wp_key_revoked', array( $this, 'revoked' ), 10, 3 );
		add_action( 'xnetvn_agent_hub_wp_key_authenticated', array( $this, 'authenticated' ), 10, 2 );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'expiring' ) );
		add_action( self::BATCH_HOOK, array( $this, 'expiring' ) );
		add_action( 'shutdown', array( $this, 'flush' ), 150 );
	}

	/**
	 * Queue a creation notice containing safe metadata only.
	 *
	 * @param ApiKey $key Created metadata.
	 */
	public function created( ApiKey $key ): void {
		$this->queue( $key, 'created' );
	}

	/**
	 * Queue only revocations performed by a different actor.
	 *
	 * @param ApiKey $key Revoked metadata.
	 * @param int    $actor Revoking actor.
	 * @param string $reason Safe internal reason, never inserted into mail.
	 */
	public function revoked( ApiKey $key, int $actor, string $reason ): void {
		if ( $actor !== $key->user_id ) {
			$this->queue( $key, 'revoked' );
		}
	}

	/**
	 * Notice a changed recorded network after a successful request, at most once daily.
	 *
	 * @param ApiKey      $key Prior safe metadata.
	 * @param string|null $peer Already validated and privacy-masked peer.
	 */
	public function authenticated( ApiKey $key, ?string $peer ): void {
		if ( null !== $key->last_used_ip && null !== $peer && $key->last_used_ip !== $peer ) {
			$marker = 'xnetvn_agent_hub_wp_notice_ip_' . $key->key_id->value . '_' . gmdate( 'Ymd', $this->clock->now() );
			$this->queue( $key, 'unusual_ip', $marker, $this->clock->now() + 2 * DAY_IN_SECONDS );
		}
	}

	/** Ensure one daily expiry event exists. */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $this->clock->now() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Read at most one indexed expiry page, then continue on a separate cron invocation.
	 *
	 * @param int $page One-based page.
	 */
	public function expiring( int $page = 1 ): void {
		try {
			if ( true === $this->settings->get( 'notify_key_expiring' ) ) {
				$keys = $this->keys->list_all(
					array(
						'state'          => 'active',
						'page'           => max( 1, $page ),
						'per_page'       => 100,
						'expires_after'  => gmdate( 'Y-m-d H:i:s', $this->clock->now() ),
						'expires_before' => gmdate( 'Y-m-d H:i:s', $this->clock->now() + 7 * DAY_IN_SECONDS ),
					)
				);
				foreach ( $keys as $key ) {
					if ( null === $key->scope->expires_at ) {
						continue;
					}
					$expires = $key->scope->expires_at->getTimestamp();
					$marker  = 'xnetvn_agent_hub_wp_notice_expiry_' . $key->key_id->value . '_' . $expires;
					$this->queue( $key, 'expiring', $marker, $expires + DAY_IN_SECONDS );
				}
				if ( count( $keys ) === 100 && false === wp_next_scheduled( self::BATCH_HOOK, array( $page + 1 ) ) ) {
					wp_schedule_single_event( $this->clock->now() + MINUTE_IN_SECONDS, self::BATCH_HOOK, array( $page + 1 ) );
				}
			}
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
		}
		$this->site_expiring( max( 1, $page ) );
	}

	/**
	 * Queue site-key expiry notices on the blog this provider was built on.
	 *
	 * @param int $page One-based page.
	 * @throws \RuntimeException For an unavailable notification blog, caught inside.
	 */
	public function site_expiring( int $page = 1 ): void {
		if ( null === $this->site_keys ) {
			return;
		}
		try {
			$entry  = get_current_blog_id();
			$pushed = false;
			try {
				if ( $entry !== $this->blog_id ) {
					if ( function_exists( 'get_site' ) && null === get_site( $this->blog_id ) ) {
						throw new \RuntimeException( 'Site notification blog is unavailable.' );
					}
					$pushed = true;
					switch_to_blog( $this->blog_id );
					if ( get_current_blog_id() !== $this->blog_id ) {
						throw new \RuntimeException( 'Site notification blog is unavailable.' );
					}
				}
				if ( true !== $this->settings->get( 'notify_key_expiring' ) ) {
					return;
				}
				$keys = $this->site_keys->list_all(
					array(
						'state'    => 'active',
						'page'     => max( 1, $page ),
						'per_page' => 100,
					)
				);
				$now  = $this->clock->now();
				$last = $now + 7 * DAY_IN_SECONDS;
				foreach ( $keys as $key ) {
					if ( ! $key instanceof SiteCredential || $key->blog_id !== $this->blog_id ) {
						continue;
					}
					$expires = $key->scope->expires_at->getTimestamp();
					if ( $expires <= $now || $expires > $last ) {
						continue;
					}
					$marker = 'xnetvn_agent_hub_wp_notice_site_expiry_' . $key->key_id->value . '_' . $expires;
					$this->queue_site( $key, $marker, $expires + DAY_IN_SECONDS );
				}
				if ( count( $keys ) === 100 && false === wp_next_scheduled( self::BATCH_HOOK, array( $page + 1 ) ) ) {
					wp_schedule_single_event( $this->clock->now() + MINUTE_IN_SECONDS, self::BATCH_HOOK, array( $page + 1 ) );
				}
			} finally {
				if ( $pushed && get_current_blog_id() !== $entry ) {
					restore_current_blog();
				}
			}
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
		}
	}

	/** Drain a bounded buffer after database mutation locks have been released. */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		foreach ( $pending as $notice ) {
			try {
				$key   = $notice['key'];
				$user  = get_userdata( $key->user_id );
				$fresh = $this->keys->find_by_id( $key->id );
				if ( false === $user || null === $fresh || $fresh->key_id->value !== $key->key_id->value || $fresh->user_id !== $key->user_id || ! $this->enabled( $key, $notice['kind'] ) ) {
					if ( null !== $notice['marker'] ) {
						delete_option( $notice['marker'] );
					}
					continue;
				}
				$titles  = array(
					'created'    => __( 'Agent Hub key created', 'xnetvn-agent-hub-wp' ),
					'revoked'    => __( 'Agent Hub key revoked by an administrator', 'xnetvn-agent-hub-wp' ),
					'expiring'   => __( 'Agent Hub key expires within seven days', 'xnetvn-agent-hub-wp' ),
					'unusual_ip' => __( 'Agent Hub key used from a different network', 'xnetvn-agent-hub-wp' ),
				);
				$message = sprintf(
					/* translators: 1: key name, 2: public key ID, 3: risk tier, 4: profile URL. No credential is included. */
					__( "Key: %1\$s\nKey ID: %2\$s\nRisk tier: %3\$s\nReview your keys: %4\$s\n\nIf this was unexpected, revoke the affected key. Never share its secret in support reports.", 'xnetvn-agent-hub-wp' ),
					wp_strip_all_tags( $key->name ),
					$key->key_id->value,
					$key->scope->tier_max->value,
					admin_url( 'profile.php' )
				);
				if ( ! wp_mail( $user->user_email, $titles[ $notice['kind'] ], $message ) ) {
					if ( null !== $notice['marker'] ) {
						delete_option( $notice['marker'] );
					}
					do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
				}
			} catch ( \Throwable ) {
				do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
			}
		}
		$this->flush_site();
	}

	/** Deliver site notices on the key's blog and restore the entry blog per notice. */
	private function flush_site(): void {
		$pending            = $this->site_pending;
		$this->site_pending = array();
		if ( ! $pending || null === $this->site_keys ) {
			return;
		}
		foreach ( $pending as $notice ) {
			$key = $notice['key'];
			if ( $key->blog_id !== $this->blog_id ) {
				continue;
			}
			$entry  = get_current_blog_id();
			$pushed = false;
			try {
				if ( get_current_blog_id() !== $key->blog_id ) {
					if ( function_exists( 'get_site' ) && null === get_site( $key->blog_id ) ) {
						continue;
					}
					$pushed = true;
					switch_to_blog( $key->blog_id );
					if ( get_current_blog_id() !== $key->blog_id ) {
						continue;
					}
				}
				$fresh = $this->site_keys->find_by_id( $key->id );
				if ( null === $fresh || $fresh->key_id->value !== $key->key_id->value || $fresh->blog_id !== $key->blog_id || $fresh->is_revoked() || $fresh->scope->expires_at->getTimestamp() !== $key->scope->expires_at->getTimestamp() || $fresh->scope->expires_at->getTimestamp() <= $this->clock->now() || true !== $this->settings->get( 'notify_key_expiring' ) ) {
					delete_option( $notice['marker'] );
					continue;
				}
				$to = get_option( 'admin_email' );
				if ( ! is_string( $to ) || ! is_email( $to ) ) {
					delete_option( $notice['marker'] );
					do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
					continue;
				}
				$title   = __( 'Agent Hub site key expires within seven days', 'xnetvn-agent-hub-wp' );
				$message = sprintf(
					/* translators: 1: public site-key ID, 2: UTC expiry, 3: Keys page URL. No credential is included. */
					__( "Site key: %1\$s\nExpires (UTC): %2\$s\nReview site keys: %3\$s\n\nIf this was unexpected, revoke the affected site key. Never share its secret in support reports.", 'xnetvn-agent-hub-wp' ),
					$key->key_id->value,
					gmdate( 'Y-m-d H:i:s', $key->scope->expires_at->getTimestamp() ),
					admin_url( 'admin.php?page=xnetvn-agent-hub-wp-keys' )
				);
				if ( ! wp_mail( $to, $title, $message ) ) {
					delete_option( $notice['marker'] );
					do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
				}
			} catch ( \Throwable ) {
				do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
			} finally {
				if ( $pushed && get_current_blog_id() !== $entry ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Queue one site notice with marker-based deduplication.
	 *
	 * @param SiteCredential $key Metadata.
	 * @param string         $marker Deduplication option.
	 * @param int            $until Marker expiry.
	 */
	private function queue_site( SiteCredential $key, string $marker, int $until ): void {
		try {
			if ( count( $this->site_pending ) >= 100 || true !== $this->settings->get( 'notify_key_expiring' ) || ! add_option( $marker, $until, '', false ) ) {
				return;
			}
			$this->site_pending[ $marker ] = array(
				'key'    => $key,
				'marker' => $marker,
				'until'  => $until,
			);
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
		}
	}

	/**
	 * Respect both site choices and the owner's opt-out at queue and send time.
	 *
	 * @param ApiKey $key Metadata.
	 * @param string $kind Reviewed notice kind.
	 * @return bool
	 */
	private function enabled( ApiKey $key, string $kind ): bool {
		$setting = 'unusual_ip' === $kind ? 'notify_unusual_ip' : 'notify_key_' . $kind;
		return true === $this->settings->get( $setting ) && '0' !== get_user_meta( $key->user_id, 'xnetvn_agent_hub_wp_notifications', true );
	}

	/**
	 * Queue only safe key metadata with optional atomic persistent deduplication.
	 *
	 * @param ApiKey      $key Metadata.
	 * @param string      $kind Notice kind.
	 * @param string|null $marker Optional deduplication option.
	 * @param int         $until Marker expiry.
	 */
	private function queue( ApiKey $key, string $kind, ?string $marker = null, int $until = 0 ): void {
		try {
			if ( count( $this->pending ) >= 100 || ! $this->enabled( $key, $kind ) || ( null !== $marker && ! add_option( $marker, $until, '', false ) ) ) {
				return;
			}
			$this->pending[ $kind . ':' . $key->key_id->value ] = array(
				'key'    => $key,
				'kind'   => $kind,
				'marker' => $marker,
			);
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_notification_unavailable' );
		}
	}
}
