<?php
/**
 * Bounded daily audit retention.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Audit;

use XNetVN\AgentHubWP\Contracts\AuditRepository;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;

/** Bounds each cron invocation, rescheduling remaining backlog. */
final class Retention implements ServiceProvider {
	/** Public daily hook. */
	public const HOOK = 'xnetvn_agent_hub_wp_purge_audit';
	/** Backlog continuation hook. */
	public const CONTINUE_HOOK = 'xnetvn_agent_hub_wp_purge_audit_backlog';
	/**
	 * Bind retention services.
	 *
	 * @param AuditRepository    $audit Audit persistence.
	 * @param SiteSettings       $settings Retention settings.
	 * @param Clock              $clock UTC clock.
	 * @param WpBucketStore|null $buckets Expired rate buckets.
	 */
	public function __construct( private AuditRepository $audit, private SiteSettings $settings, private Clock $clock, private ?WpBucketStore $buckets = null ) {
	}

	/** Register cron scheduling and callbacks. */
	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'purge' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'purge' ) );
	}

	/** Schedule one daily event, never one per page load. */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $this->clock->now() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** Delete a bounded old batch; failed storage never reports a zero backlog. */
	public function purge(): void {
		try {
			$days   = (int) $this->settings->get( 'audit_retention_days' );
			$cutoff = gmdate( 'Y-m-d H:i:s', $this->clock->now() - $days * DAY_IN_SECONDS );
			$more   = 1000 === $this->audit->purge_older_than( $cutoff, 1000 );
			if ( null !== $this->buckets ) {
				$more = 1000 === $this->buckets->purge( intdiv( $this->clock->now(), 60 ) - 3 ) || $more;
				$more = 1000 === $this->buckets->purge_notices( $this->clock->now() ) || $more;
			}
			if ( $more && false === wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( $this->clock->now() + HOUR_IN_SECONDS, self::CONTINUE_HOOK );
			}
		} catch ( \Throwable ) {
			do_action( 'xnetvn_agent_hub_wp_audit_unavailable' );
		}
	}
}
