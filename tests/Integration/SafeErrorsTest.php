<?php
/**
 * Reviewed error propagation without provider details.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Safe code never licenses an arbitrary provider message or attached data. */
final class SafeErrorsTest extends WP_UnitTestCase {
	/** Canary details disappear even for an allowlisted error code. */
	public function test_pipeline_preserves_only_reviewed_reason(): void {
		$pipeline = new OutputPipeline( new MemorySettings() );
		foreach ( array(
			'xnetvn_agent_hub_wp_conflict' => 'conflict',
			'provider_password'            => 'internal_error',
		) as $code => $reason ) {
			$result = $pipeline->process( new \WP_Error( $code, 'SECRET-CANARY', array( 'token' => 'SECRET-CANARY' ) ), array() );
			self::assertSame( 'xnetvn_agent_hub_wp_' . $reason, $result->get_error_code() );
			self::assertStringNotContainsString( 'CANARY', $result->get_error_message() );
			self::assertNull( $result->get_error_data() );
		}
	}
}
