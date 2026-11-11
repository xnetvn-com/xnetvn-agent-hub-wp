<?php
/**
 * Exact-route, Origin and TLS truth tables.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Http\OriginGuard;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Http\TransportGuard;

/** Pins protocol security guards before exposing a product route. */
final class GuardsTest extends TestCase {
	/** Route matching cannot accidentally authenticate management or sibling paths. */
	public function test_exact_route(): void {
		foreach ( array(
			'/xnetvn-agent-hub-wp/mcp'     => true,
			'/xnetvn-agent-hub-wp/mcp/'    => true,
			'/xnetvn-agent-hub-wp/v1/keys' => false,
			'/xnetvn-agent-hub-wp/mcp2'    => false,
			'/xnetvn-agent-hub-wp'         => false,
			'/wp/v2/posts'                 => false,
		) as $route => $expected ) {
			self::assertSame( $expected, RouteMatcher::matches( $route, true ) );
			self::assertFalse( RouteMatcher::matches( $route, false ) );
		}
	}

	/**
	 * Only explicitly allowed serialized HTTP(S) origins pass.
	 *
	 * @dataProvider origins
	 * @param string|null $origin Origin header.
	 * @param bool        $expected Expected decision.
	 */
	public function test_origin( ?string $origin, bool $expected ): void {
		self::assertSame( $expected, OriginGuard::allows( $origin, array( 'https://example.test', 'http://localhost:3000', 'https://[2001:db8::1]:8443' ) ) );
	}

	/**
	 * Origin normalization examples.
	 *
	 * @return array<string,array{string|null,bool}>
	 */
	public function origins(): array {
		return array(
			'absent'            => array( null, true ),
			'match'             => array( 'https://example.test', true ),
			'case/default port' => array( 'HTTPS://EXAMPLE.TEST:443', true ),
			'local frontend'    => array( 'http://localhost:3000', true ),
			'IPv6'              => array( 'https://[2001:db8::1]:8443', true ),
			'wrong scheme'      => array( 'http://example.test', false ),
			'wrong port'        => array( 'https://example.test:8443', false ),
			'null'              => array( 'null', false ),
			'empty'             => array( '', false ),
			'garbage'           => array( 'example.test', false ),
			'path'              => array( 'https://example.test/path', false ),
			'user info'         => array( 'https://name@example.test', false ),
			'query'             => array( 'https://example.test?x=1', false ),
			'fragment'          => array( 'https://example.test#x', false ),
			'newline'           => array( "https://example.test\n", false ),
		);
	}

	/** TLS requires real TLS, an explicitly trusted proxy or a local-only flag. */
	public function test_transport_boundaries(): void {
		self::assertTrue( TransportGuard::secure( true, 'production', false, array(), array(), array() ) );
		self::assertFalse( TransportGuard::secure( false, 'production', true, array(), array(), array() ) );
		self::assertFalse( TransportGuard::secure( false, 'local', false, array(), array(), array() ) );
		self::assertTrue( TransportGuard::secure( false, 'local', true, array(), array(), array() ) );
		self::assertTrue( TransportGuard::secure( false, 'development', true, array(), array(), array() ) );
		$server = array(
			'REMOTE_ADDR'            => '203.0.113.10',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		);
		self::assertFalse( TransportGuard::secure( false, 'production', false, $server, array(), array( '203.0.113.0/24' ) ) );
		self::assertFalse( TransportGuard::secure( false, 'production', false, $server, array( 'X-Forwarded-Proto' ), array() ) );
		self::assertTrue( TransportGuard::secure( false, 'production', false, $server, array( 'X-Forwarded-Proto' ), array( '203.0.113.0/24' ) ) );
		$server['REMOTE_ADDR'] = '198.51.100.10';
		self::assertFalse( TransportGuard::secure( false, 'production', false, $server, array( 'X-Forwarded-Proto' ), array( '203.0.113.0/24' ) ) );
		$server['REMOTE_ADDR']            = '203.0.113.10';
		$server['HTTP_X_FORWARDED_PROTO'] = 'https,http';
		self::assertFalse( TransportGuard::secure( false, 'production', false, $server, array( 'X-Forwarded-Proto' ), array( '203.0.113.0/24' ) ) );
	}
}
