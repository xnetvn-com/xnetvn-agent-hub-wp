<?php
/**
 * Exact accountless site credential authentication.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\SiteKeyRepository;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Http\OriginGuard;
use XNetVN\AgentHubWP\Http\RouteGate;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Support\Cidr;

/** Verifies a site credential without a WordPress user or inherited cookie. */
final class SiteAuthenticator {
	/** Maximum accepted peer address length. */
	private const MAX_PEER_LENGTH = 64;

	/**
	 * Bind the site credential dependencies.
	 *
	 * @param SiteKeyRepository $keys Site credentials for the current blog.
	 * @param PepperStore       $peppers Separate pepper storage.
	 * @param SiteSettings      $settings Site policy.
	 * @param RateLimiter       $limits Shared budgets.
	 * @param TransportGuard    $transport TLS policy.
	 * @param Clock             $clock Wall clock.
	 */
	public function __construct( private SiteKeyRepository $keys, private PepperStore $peppers, private SiteSettings $settings, private RateLimiter $limits, private TransportGuard $transport, private Clock $clock ) {
	}

	/**
	 * Authenticate one site token in the reviewed order.
	 *
	 * @param string      $token Bearer token.
	 * @param string      $client_ip Validated peer address.
	 * @param string|null $origin Origin header, or null when absent.
	 * @param bool        $is_https Caller-measured TLS result.
	 * @return SiteAuthContext|WP_Error
	 */
	public function authenticate( #[\SensitiveParameter] string $token, string $client_ip, ?string $origin, bool $is_https ): SiteAuthContext|WP_Error {
		try {
			$ip = $this->peer( $client_ip );
			if ( '' === $ip ) {
				return $this->error( FailureReason::Malformed );
			}
			$guard = $this->guard( $origin, $is_https );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$fail_budget = $this->limits->check( 'auth-fail', hash( 'sha256', $ip ), (int) $this->settings->get( 'rate_limit_auth_fail_per_min_ip' ), false );
			if ( ! $fail_budget->allowed ) {
				return $this->error( FailureReason::RateLimited );
			}
			$parsed = SiteTokenCodec::parse( $token );
			if ( null === $parsed ) {
				return $this->failure( FailureReason::Malformed, $ip );
			}
			$blog_id = get_current_blog_id();
			if ( $blog_id < 1 ) {
				return $this->error( FailureReason::Unavailable );
			}
			$stored = $this->keys->find_by_key_id( $parsed->id );
			if ( null === $stored ) {
				$pepper = $this->peppers->current();
				KeyHasher::verify( $parsed->secret->reveal(), $pepper->reveal(), str_repeat( '0', 64 ) );
				return $this->failure( FailureReason::UnknownKey, $ip );
			}
			$key = $stored->key;
			if ( $key->blog_id !== $blog_id || $key->is_revoked() ) {
				return $this->failure( FailureReason::Revoked, $ip );
			}
			if ( $key->scope->expires_at->getTimestamp() <= $this->clock->now() ) {
				return $this->failure( FailureReason::Expired, $ip );
			}
			$site_ips = $this->settings->get( 'site_ip_allowlist' );
			$ips_ok   = $this->ip_allowed( $ip, $key->scope->ip_allowlist ) && $this->ip_allowed( $ip, is_array( $site_ips ) ? $site_ips : array() );
			$pepper   = $this->peppers->current();
			$verified = $stored->verify_secret( $parsed->secret->reveal(), $pepper );
			if ( $stored->pepper_id !== $pepper->id || ! $verified ) {
				return $this->failure( FailureReason::BadSecret, $ip );
			}
			if ( ! $ips_ok ) {
				return $this->failure( FailureReason::IpDenied, $ip );
			}
			$key_budget = $this->limits->check( 'key', 'site:' . $blog_id . ':' . $parsed->id->value, (int) $this->settings->get( 'rate_limit_key_per_min' ) );
			if ( ! $key_budget->allowed ) {
				return $this->error( FailureReason::RateLimited );
			}
			return new SiteAuthContext( $key, bin2hex( random_bytes( 8 ) ), $ip );
		} catch ( \Throwable ) {
			return $this->error( FailureReason::Unavailable );
		}
	}

	/**
	 * Apply the site enablement, transport and origin boundary.
	 *
	 * @param string|null $origin Origin header.
	 * @param bool        $is_https Caller TLS result.
	 * @return true|WP_Error
	 */
	private function guard( ?string $origin, bool $is_https ): true|WP_Error {
		if ( true !== $this->settings->get( 'enabled' ) ) {
			return $this->error( FailureReason::SiteDisabled );
		}
		if ( ! $is_https || ! $this->transport->is_secure() ) {
			return $this->error( FailureReason::InsecureTransport );
		}
		$allowed = $this->settings->get( 'allowed_origins' );
		if ( ! is_array( $allowed ) ) {
			return $this->error( FailureReason::Unavailable );
		}
		foreach ( array( home_url(), site_url() ) as $url ) {
			$normalized = OriginGuard::from_url( $url );
			if ( null !== $normalized ) {
				$allowed[] = $normalized;
			}
		}
		$allowed = apply_filters( 'xnetvn_agent_hub_wp_allowed_origins', $allowed );
		if ( ! is_array( $allowed ) ) {
			return $this->error( FailureReason::Unavailable );
		}
		$allowed = array_values( array_filter( $allowed, 'is_string' ) );
		$guard   = RouteGate::evaluate( $origin, true, $allowed );
		return $guard ?? true;
	}

	/**
	 * Validate one peer address without trusting forwarded data.
	 *
	 * @param string $client_ip Caller-supplied peer.
	 * @return string
	 */
	private function peer( string $client_ip ): string {
		$ip = trim( $client_ip );
		if ( '' === $ip || strlen( $ip ) > self::MAX_PEER_LENGTH || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return $ip;
	}

	/**
	 * Apply one optional CIDR list.
	 *
	 * @param string        $ip Peer.
	 * @param array<string> $allowed CIDRs.
	 * @return bool
	 */
	private function ip_allowed( string $ip, array $allowed ): bool {
		if ( array() === $allowed ) {
			return true;
		}
		foreach ( $allowed as $cidr ) {
			if ( is_string( $cidr ) && Cidr::contains( $cidr, $ip ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Charge the shared failure budget and return the mapped refusal.
	 *
	 * @param FailureReason $reason Failure.
	 * @param string        $ip Peer.
	 * @return WP_Error
	 */
	private function failure( FailureReason $reason, string $ip ): WP_Error {
		$budget = $this->limits->check( 'auth-fail', hash( 'sha256', $ip ), (int) $this->settings->get( 'rate_limit_auth_fail_per_min_ip' ) );
		return $budget->allowed ? $this->error( $reason ) : $this->error( FailureReason::RateLimited );
	}

	/**
	 * Map a safe failure to the existing REST error shapes.
	 *
	 * @param FailureReason $reason Failure.
	 * @return WP_Error
	 */
	private function error( FailureReason $reason ): WP_Error {
		$status = $reason->status();
		$code   = 401 === $status ? 'authentication_required' : ( 429 === $status ? 'rate_limited' : ( 503 === $status ? 'unavailable' : 'forbidden' ) );
		return new WP_Error( 'xnetvn_agent_hub_wp_' . $code, 401 === $status ? 'Authentication required.' : 'Access is unavailable.', array( 'status' => $status ) );
	}
}
