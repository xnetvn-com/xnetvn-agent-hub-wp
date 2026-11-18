<?php
/**
 * Exact-route delegated identity and REST authentication lifecycle.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\Clock;
use XNetVN\AgentHubWP\Contracts\KeyRepository;
use XNetVN\AgentHubWP\Contracts\RateLimiter;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;
use XNetVN\AgentHubWP\Contracts\SiteSettings;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Support\RequestMetadata;
use XNetVN\AgentHubWP\Support\Cidr;

/** A prior cookie identity or authentication success never substitutes for a key. */
final class Authenticator implements ServiceProvider {
	/** Protected-account eligibility.
	 *
	 * @var DelegationEligibility
	 */
	private DelegationEligibility $eligibility;
	/** Recorded site authentication failure for this attempt.
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $site_failure = null;
	/**
	 * Bind credential, policy, transport and request services.
	 *
	 * @param KeyRepository              $keys Credential repository.
	 * @param PepperStore                $peppers Separate pepper storage.
	 * @param SiteSettings               $settings Site policy.
	 * @param HeaderExtractor            $headers Header-only credential input.
	 * @param RateLimiter                $limits Shared budgets.
	 * @param TransportGuard             $transport TLS policy.
	 * @param RequestScope               $request Request-local state.
	 * @param Clock                      $clock Wall clock.
	 * @param DelegationEligibility|null $eligibility Protected-account eligibility.
	 * @param SiteAuthenticator|null     $site_auth Site credential authenticator.
	 */
	public function __construct( private KeyRepository $keys, private PepperStore $peppers, private SiteSettings $settings, private HeaderExtractor $headers, private RateLimiter $limits, private TransportGuard $transport, private RequestScope $request, private Clock $clock, ?DelegationEligibility $eligibility = null, private ?SiteAuthenticator $site_auth = null ) {
		$this->eligibility = $eligibility ?? new DelegationEligibility();
	}
	/** Register route-scoped identity, late reauthentication and safe response headers. */
	public function register(): void {
		add_filter( 'determine_current_user', array( $this, 'determine_user' ), 20 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_result' ), 80 );
		add_filter( 'rest_post_dispatch', array( $this, 'response_headers' ), 10, 3 );
		add_action( 'shutdown', array( $this->request, 'reset' ), PHP_INT_MAX );
	}
	/**
	 * Resolve key identity only within the exact MCP route.
	 *
	 * @param mixed $user Prior cookie/application identity.
	 * @return mixed
	 */
	public function determine_user( mixed $user ): mixed {
		return RouteMatcher::is_mcp_route() ? $this->authenticate() : $user;
	}
	/**
	 * Authenticate once and memoize only non-secret state.
	 *
	 * @return int Verified owner, or zero after explicit failure.
	 */
	public function authenticate(): int {
		if ( $this->request->attempted ) {
			if ( $this->request->has_conflicting_principals() ) {
				return 0;
			}
			$human = $this->request->current();
			return null !== $this->request->site_current() ? 0 : ( null === $human ? 0 : $human->user_id );
		}
		if ( $this->request->has_conflicting_principals() ) {
			return 0;
		}
		$this->request->attempted = true;
		$this->site_failure       = null;
		try {
			$this->request->has_token = $this->headers->present();
			$peer                     = $this->peer();
			$token                    = $this->headers->bearer();
			if ( null !== $token && str_starts_with( $token, 'xns_' ) ) {
				if ( null === $this->site_auth ) {
					return $this->fail( FailureReason::Unavailable, false );
				}
				if ( $this->ambiguous_site_header() ) {
					return $this->fail( FailureReason::Malformed );
				}
				$origin = isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : null;
				$site   = $this->site_auth->authenticate( $token, $peer, $origin, $this->transport->is_secure() );
				if ( is_wp_error( $site ) ) {
					$this->clear_principals();
					$this->site_failure = $site;
					return 0;
				}
				$this->request->bind_site( $site );
				return 0;
			}
			$ip_budget = $this->limits->check( 'auth-fail', hash( 'sha256', $peer ), (int) $this->settings->get( 'rate_limit_auth_fail_per_min_ip' ), false );
			if ( ! $ip_budget->allowed ) {
				$this->request->budget = $ip_budget;
				return $this->fail( FailureReason::RateLimited, false );
			}
			$parsed = null === $token ? null : TokenParser::parse( $token );
			if ( null === $parsed ) {
				return $this->fail( $this->request->has_token ? FailureReason::Malformed : FailureReason::Missing );
			}
			$stored = $this->keys->find_by_key_id( $parsed->id );
			$pepper = $this->peppers->current();
			$secret = $parsed->secret->reveal();
			$valid  = null === $stored ? KeyHasher::verify( $secret, $pepper->reveal(), str_repeat( '0', 64 ) ) : $stored->verify_secret( $secret, $pepper->reveal() );
			if ( null === $stored ) {
				return $this->fail( FailureReason::UnknownKey );
			}
			if ( ! $valid || $stored->pepper_id !== $pepper->id ) {
				return $this->fail( FailureReason::BadSecret );
			}
			$key = $stored->key;
			if ( $key->is_revoked() ) {
				return $this->fail( FailureReason::Revoked );
			}
			if ( null !== $key->scope->expires_at && $key->scope->expires_at->getTimestamp() <= $this->clock->now() ) {
				return $this->fail( FailureReason::Expired );
			}
			$owner = get_userdata( $key->user_id );
			if ( false === $owner ) {
				$this->keys->revoke_all_for_user( $key->user_id );
				return $this->fail( FailureReason::OwnerMissing );
			}
			if ( ! $this->eligibility->check( $key->user_id )->allowed ) {
				return $this->fail( FailureReason::OwnerForbidden );
			}
			if ( ! user_can( $owner, Capabilities::USE ) ) {
				return $this->fail( FailureReason::OwnerForbidden );
			}
			$site_ips = $this->settings->get( 'site_ip_allowlist' );
			if ( ! $this->ip_allowed( $peer, $key->scope->ip_allowlist ) || ! $this->ip_allowed( $peer, is_array( $site_ips ) ? $site_ips : array() ) ) {
				return $this->fail( FailureReason::IpDenied );
			}
			if ( true !== $this->settings->get( 'enabled' ) ) {
				return $this->fail( FailureReason::SiteDisabled );
			}
			if ( ! $this->transport->is_secure() ) {
				return $this->fail( FailureReason::InsecureTransport );
			}
			$this->request->budget = $this->limits->check( 'key', $key->key_id->value, (int) $this->settings->get( 'rate_limit_key_per_min' ) );
			if ( ! $this->request->budget->allowed ) {
				return $this->fail( FailureReason::RateLimited, false );
			}
			$this->keys->touch_last_used( $key->id, RequestMetadata::ip( $peer, true === $this->settings->get( 'anonymize_ip' ) ), RequestMetadata::user_agent( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '', 255 ) );
			$this->request->bind_human( new AuthContext( $key, bin2hex( random_bytes( 8 ) ) ) );
			do_action( 'xnetvn_agent_hub_wp_key_authenticated', $key, RequestMetadata::ip( $peer, true === $this->settings->get( 'anonymize_ip' ) ) );
			return $key->user_id;
		} catch ( \Throwable ) {
			return $this->fail( FailureReason::Unavailable, false );
		}
	}

	/**
	 * Preserve denials, then reauthenticate even an early-cached cookie user.
	 *
	 * @param mixed $result Prior REST auth result.
	 * @return mixed
	 */
	public function rest_result( mixed $result ): mixed {
		if ( ! RouteMatcher::is_mcp_route() || is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $this->request->attempted ) {
			$user = $this->authenticate();
			if ( null === $this->request->site_current() && null === $this->site_failure ) {
				wp_set_current_user( $user );
			}
		}
		if ( $this->request->has_conflicting_principals() ) {
			return new WP_Error( 'xnetvn_agent_hub_wp_forbidden', 'Access is unavailable.', array( 'status' => 403 ) );
		}
		if ( null !== $this->site_failure ) {
			$this->clear_principals();
			if ( 0 !== get_current_user_id() ) {
				wp_set_current_user( 0 );
			}
			return $this->site_failure;
		}
		if ( null !== $this->request->site_current() ) {
			if ( 0 !== get_current_user_id() ) {
				wp_set_current_user( 0 );
			}
			return 0 === get_current_user_id() ? true : new WP_Error( 'xnetvn_agent_hub_wp_forbidden', 'Access is unavailable.', array( 'status' => 403 ) );
		}
		if ( null !== $this->request->context ) {
			wp_set_current_user( $this->request->context->user_id );
			return true;
		}
		$status = ( $this->request->failure ?? FailureReason::Missing )->status();
		$code   = 401 === $status ? 'authentication_required' : ( 429 === $status ? 'rate_limited' : ( 503 === $status ? 'unavailable' : 'forbidden' ) );
		return new WP_Error( 'xnetvn_agent_hub_wp_' . $code, 401 === $status ? 'Authentication required.' : 'Access is unavailable.', array( 'status' => $status ) );
	}

	/**
	 * Attach challenges and no-store only to the owned MCP response.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_REST_Server   $server REST server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_REST_Response
	 */
	public function response_headers( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! RouteMatcher::matches( $request->get_route(), true ) ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		if ( 401 === $response->get_status() ) {
			$response->header( 'WWW-Authenticate', 'Bearer realm="xnetvn-agent-hub-wp"' . ( $this->request->has_token ? ', error="invalid_token"' : '' ) );
		}
		if ( 429 === $response->get_status() ) {
			$response->header( 'Retry-After', (string) ( $this->request->budget->retry_after ?? 60 ) );
		}
		return $response;
	}
	/**
	 * Clear both principals while preserving attempt, header and budget metadata.
	 *
	 * @return void
	 */
	private function clear_principals(): void {
		$this->request->context      = null;
		$this->request->site_context = null;
	}

	/**
	 * Record a safe failure and optionally charge the failed-auth budget.
	 *
	 * @param FailureReason $reason Failure identifier.
	 * @param bool          $charge Charge the peer's failure budget.
	 * @return int Always zero.
	 */
	private function fail( FailureReason $reason, bool $charge = true ): int {
		$this->clear_principals();
		$this->request->failure = $reason;
		if ( $charge ) {
			try {
				$budget = $this->limits->check( 'auth-fail', hash( 'sha256', $this->peer() ), (int) $this->settings->get( 'rate_limit_auth_fail_per_min_ip' ) );
				if ( ! $budget->allowed ) {
					$this->request->failure = FailureReason::RateLimited;
					$this->request->budget  = $budget;
				}
			} catch ( \Throwable ) {
				$this->request->failure = FailureReason::Unavailable;
			}
		}
		do_action( 'xnetvn_agent_hub_wp_auth_failed', $this->request->failure->value, $this->peer() );
		return 0;
	}
	/**
	 * Reject ambiguous credential sources only for a site token.
	 *
	 * @return bool
	 */
	private function ambiguous_site_header(): bool {
		$primary = array();
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $name ) {
			if ( ! isset( $_SERVER[ $name ] ) || '' === $_SERVER[ $name ] ) {
				continue;
			}
			if ( ! is_string( $_SERVER[ $name ] ) ) {
				return true;
			}
			$primary[] = $_SERVER[ $name ];
		}
		$fallback = isset( $_SERVER['HTTP_X_XNETVN_API_KEY'] ) && '' !== $_SERVER['HTTP_X_XNETVN_API_KEY'];
		if ( $fallback && array() !== $primary ) {
			return true;
		}
		return count( array_unique( $primary ) ) > 1;
	}

	/**
	 * Read a validated socket peer, never an untrusted forwarded identity.
	 *
	 * @return string
	 */
	private function peer(): string {
		$peer = $_SERVER['REMOTE_ADDR'] ?? '';
		return is_string( $peer ) && false !== filter_var( $peer, FILTER_VALIDATE_IP ) ? $peer : '';
	}
	/**
	 * Apply an optional explicit IP allowlist.
	 *
	 * @param string        $ip Peer address.
	 * @param array<string> $allowed Constraints.
	 * @return bool
	 */
	private function ip_allowed( string $ip, array $allowed ): bool {
		if ( ! $allowed ) {
			return true;
		}
		foreach ( $allowed as $cidr ) {
			if ( Cidr::contains( $cidr, $ip ) ) {
				return true;
			}
		}
		return false;
	}
}
