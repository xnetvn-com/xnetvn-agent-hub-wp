<?php
/**
 * WordPress guard error and hook-order tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Http\RouteGate;

/** Checks error precedence with real WP_Error objects. */
final class RouteGateTest extends WP_UnitTestCase {
	/** Bad Origin takes precedence over TLS and produces a safe HTTP403. */
	public function test_guard_precedence(): void {
		$error = RouteGate::evaluate( 'https://other.test', false, array( 'https://example.test' ) );
		self::assertWPError( $error );
		self::assertSame( 403, $error->get_error_data()['status'] );
		self::assertSame( 'xnetvn_agent_hub_wp_origin_denied', $error->get_error_code() );
		$tls = RouteGate::evaluate( null, false, array() );
		self::assertWPError( $tls );
		self::assertSame( 'xnetvn_agent_hub_wp_insecure_transport', $tls->get_error_code() );
		self::assertNull( RouteGate::evaluate( null, true, array() ) );
	}
}
