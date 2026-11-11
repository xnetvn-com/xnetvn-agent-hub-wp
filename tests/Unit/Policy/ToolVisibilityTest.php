<?php
/**
 * Discovery must retain delegated tiers and current capability decisions.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\Decision;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\ToolVisibility;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Uses the real policy intersection, not a mock of the decision under test. */
final class ToolVisibilityTest extends TestCase {
	/** Losing a capability hides a permitted read tool immediately; higher tiers stay hidden. */
	public function test_visibility_obeys_live_capability_and_scope(): void {
		$can_use = true;
		$policy  = new Policy(
			new MemorySettings(),
			static function () use ( &$can_use ): bool {
				return $can_use;
			},
			static fn( Decision $decision ): Decision => $decision
		);
		$visible = new ToolVisibility( $policy );
		$context = new AuthContext( new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Visibility fixture', new Scope( RiskTier::Read, array( 'content' ) ), new \DateTimeImmutable( '@1791072000' ) ), str_repeat( 'a', 16 ) );
		$read    = new AbilityDescriptor( 'xnetvn/get-post', RiskTier::Read, 'content', 'read' );
		$write   = new AbilityDescriptor( 'xnetvn/update-post', RiskTier::Write, 'content', 'edit_posts' );
		self::assertTrue( $visible->visible( $read, $context, 'http' ) );
		self::assertFalse( $visible->visible( $write, $context, 'http' ) );
		self::assertFalse( $visible->visible( $read, null, 'http' ) );
		$can_use = false;
		self::assertFalse( $visible->visible( $read, $context, 'http' ) );
	}
}
