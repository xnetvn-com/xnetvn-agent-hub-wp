<?php
/**
 * Denial-only extension result tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Error results cannot be mistaken for permission to proceed. */
final class PolicyExtensionTest extends WP_UnitTestCase {
	/** A WP_Error or malformed hook result always denies additional access. */
	public function test_error_and_invalid_hook_results_deny(): void {
		$descriptor = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read' );
		$error      = new Policy( new MemorySettings(), static fn(): bool => true, static fn(): \WP_Error => new \WP_Error( 'extra_denied', 'Denied' ) );
		self::assertFalse( $error->evaluate( $descriptor, null, 'stdio' )->allowed );
		$invalid = new Policy( new MemorySettings(), static fn(): bool => true, static fn(): mixed => null );
		self::assertFalse( $invalid->evaluate( $descriptor, null, 'stdio' )->allowed );
	}
}
