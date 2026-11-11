<?php
/**
 * Complete HTTP failure classification, including unavailable storage.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\FailureReason;

/** Prevents infrastructure errors and revoked identities from becoming authorization success. */
final class FailureReasonTest extends TestCase {
	/** Every named failure has the intended public HTTP classification. */
	public function test_all_failure_statuses(): void {
		$expected = array(
			'missing'            => 401,
			'malformed'          => 401,
			'unknown_key'        => 401,
			'bad_secret'         => 401,
			'revoked'            => 401,
			'expired'            => 401,
			'owner_missing'      => 401,
			'owner_forbidden'    => 403,
			'ip_denied'          => 403,
			'site_disabled'      => 403,
			'insecure_transport' => 403,
			'origin_denied'      => 403,
			'rate_limited'       => 429,
			'unavailable'        => 503,
		);
		foreach ( FailureReason::cases() as $reason ) {
			self::assertSame( $expected[ $reason->value ], $reason->status(), $reason->value );
		}
		self::assertCount( count( $expected ), FailureReason::cases() );
	}
}
