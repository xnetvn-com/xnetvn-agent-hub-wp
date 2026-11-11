<?php
/**
 * Bounded JSON/SSE decoding without HTTP or a service.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Tests\E2E\McpClient;

/** Test protocol response framing rather than curl internals. */
final class McpResponseTest extends TestCase {
	/** Standard JSON and SSE data frames produce the same response. */
	public function test_json_and_sse_decode(): void {
		$record = array(
			'jsonrpc' => '2.0',
			'id'      => 7,
			'result'  => array( 'ok' => true ),
		);
		$json   = json_encode( $record, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Service-free protocol framing fixture.
		self::assertSame( $record, McpClient::decode( $json ) );
		self::assertSame( $record, McpClient::decode( "event: message\ndata: $json\n\n" ) );
		self::assertNull( McpClient::decode( '' ) );
	}

	/** Scalar, malformed and oversized responses never become a fabricated result. */
	public function test_bad_framing_is_rejected(): void {
		foreach ( array( '42', '[]', '{invalid}', str_repeat( 'X', 1048577 ) ) as $body ) {
			try {
				McpClient::decode( $body );
				self::fail( 'Invalid response accepted.' );
			} catch ( \UnexpectedValueException ) {
				self::assertTrue( true );
			}
		}
	}

	/** URL user info is refused even when no password is present. */
	public function test_target_user_info_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new McpClient( 'https://private@example.test', array( 'example.test' ) );
	}
}
