<?php
/**
 * Internal authentication failure identifiers.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

/** Distinguishes invalid credentials, forbidden delegation and unavailable services. */
enum FailureReason: string {
	case Missing           = 'missing';
	case Malformed         = 'malformed';
	case UnknownKey        = 'unknown_key';
	case BadSecret         = 'bad_secret';
	case Revoked           = 'revoked';
	case Expired           = 'expired';
	case OwnerMissing      = 'owner_missing';
	case OwnerForbidden    = 'owner_forbidden';
	case IpDenied          = 'ip_denied';
	case SiteDisabled      = 'site_disabled';
	case InsecureTransport = 'insecure_transport';
	case OriginDenied      = 'origin_denied';
	case RateLimited       = 'rate_limited';
	case Unavailable       = 'unavailable';

	// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- PHPCompatibility9 does not recognize enum scope; PHP8.2+ is required.
	/**
	 * Map a known internal reason to a safe HTTP status.
	 *
	 * @return int
	 */
	public function status(): int {
		return match ( $this ) {
			self::Missing, self::Malformed, self::UnknownKey, self::BadSecret, self::Revoked, self::Expired, self::OwnerMissing => 401,
			self::RateLimited => 429,
			self::Unavailable => 503,
			default => 403,
		};
	}
	// phpcs:enable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
}
