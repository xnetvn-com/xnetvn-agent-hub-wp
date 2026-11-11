<?php
/**
 * Users shared security foundation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Policy\HardDeny;

/** Foundation contracts for the Users pack. */
final class UsersFoundationTest extends TestCase {
	/** New admin names are reviewed. */
	public function test_new_admin_names_are_reviewed(): void {
		self::assertTrue( HardDeny::reviewed_admin( 'xnetvn/set-user-status' ) );
		self::assertTrue( HardDeny::reviewed_admin( 'xnetvn/terminate-user-sessions' ) );
		self::assertFalse( HardDeny::forbidden( 'xnetvn/set-user-status' ) );
		self::assertFalse( HardDeny::forbidden( 'xnetvn/terminate-user-sessions' ) );
	}

	/** Four new safe reasons survive classification. */
	public function test_new_safe_reasons_exist(): void {
		foreach ( array( 'duplicate_user', 'protected_account', 'role_unavailable', 'cooldown_active' ) as $reason ) {
			self::assertSame( $reason, ReasonMapper::reason( 'xnetvn_agent_hub_wp_' . $reason ) );
		}
	}

	/** Core user codes map to safe reasons. */
	public function test_core_user_codes_map(): void {
		self::assertSame( 'not_found', ReasonMapper::reason( 'rest_user_invalid_id', 404 ) );
		self::assertSame( 'forbidden', ReasonMapper::reason( 'rest_user_cannot_view', 403 ) );
		self::assertSame( 'role_unavailable', ReasonMapper::reason( 'rest_user_invalid_role', 400 ) );
		self::assertSame( 'duplicate_user', ReasonMapper::reason( 'existing_user_email', 400 ) );
		self::assertSame( 'duplicate_user', ReasonMapper::reason( 'existing_user_login', 400 ) );
		self::assertSame( 'duplicate_user', ReasonMapper::reason( 'rest_user_invalid_email', 400 ) );
		self::assertSame( 'duplicate_user', ReasonMapper::reason( 'rest_user_invalid_slug', 400 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'rest_user_invalid_username', 400 ) );
		self::assertSame( 'invalid_params', ReasonMapper::reason( 'rest_user_invalid_argument', 400 ) );
		self::assertSame( 'forbidden', ReasonMapper::reason( 'no_password_reset', 0 ) );
		self::assertSame( 'not_found', ReasonMapper::reason( 'invalidcombo', 0 ) );
		self::assertSame( 'internal_error', ReasonMapper::reason( 'retrieve_password_email_failure', 0 ) );
	}
}
