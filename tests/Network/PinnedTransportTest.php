<?php
/**
 * Real TLS/socket behavior on the isolated owned-container fixture.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Network;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Support\Http\RequestsDownloadTransport;

/** These tests require explicit lab provisioning, not an Internet-wide test endpoint. */
final class PinnedTransportTest extends WP_UnitTestCase {
	/** Core-created temporary files owned by the current test.
	 *
	 * @var array<string>
	 */
	private array $files = array();
	/** Require the private fixture and its operator-generated public trust file. */
	public function set_up(): void {
		parent::set_up();
		self::assertSame( '1', getenv( 'XNETVN_RTL' ), 'Use the remote network suite.' );
		self::assertFileExists( '/work/.local/mcp-clients-trust/lab-public-cert.pem' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	/** Remove only temporary files created by this test. */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file ); }
		parent::tear_down();
	}
	/**
	 * Construct a one-hop target for the transport layer, without weakening public URL policy.
	 *
	 * @param string $scenario Fixed fixture scenario.
	 * @return array{url:non-empty-string,host:string,ip:string}
	 */
	private function target( string $scenario ): array {
		$ip = gethostbyname( 'wp69' );
		self::assertNotSame( 'wp69', $ip );
		return array(
			'url'  => 'https://xnv-pinning.test/xnetvn-fetch-fixture?case=' . $scenario,
			'host' => 'xnv-pinning.test',
			'ip'   => $ip,
		);
	}
	/**
	 * Create a core-owned destination.
	 *
	 * @return string
	 */
	private function temporary(): string {
		$file = wp_tempnam( 'xnetvn-network-test' );
		self::assertIsString( $file );
		self::assertNotSame( '', $file );
		$this->files[] = $file;
		return $file;
	}
	/**
	 * Use the same production transport with only the owned test CA changed.
	 *
	 * @return RequestsDownloadTransport
	 */
	private function transport(): RequestsDownloadTransport {
		return new RequestsDownloadTransport( '/work/.local/mcp-clients-trust/lab-public-cert.pem' ); }
	/** CURLOPT_RESOLVE reaches the chosen TLS endpoint when fresh DNS cannot resolve the hostname. */
	public function test_pin_avoids_fresh_dns_resolution(): void {
		self::assertSame( 'xnv-pinning.test', gethostbyname( 'xnv-pinning.test' ) );
		$file  = $this->temporary();
		$reply = $this->transport()->request( $this->target( 'png' ), $file, 4096, 3.0 );
		self::assertNotWPError( $reply );
		self::assertSame( 200, $reply->status );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only this test-owned core temporary file.
		self::assertStringStartsWith( "\x89PNG", file_get_contents( $file ) );
	}
	/** Redirects remain visible to policy, including an unsafe next-hop address. */
	public function test_no_automatic_redirect_following(): void {
		$reply = $this->transport()->request( $this->target( 'private' ), $this->temporary(), 4096, 3.0 );
		self::assertNotWPError( $reply );
		self::assertSame( 302, $reply->status );
		self::assertSame( 'https://127.0.0.1/never', $reply->location );
	}
	/** The encoded body remains compressed; a gzip bomb is never inflated in memory or on disk. */
	public function test_gzip_is_not_decompressed(): void {
		$file  = $this->temporary();
		$reply = $this->transport()->request( $this->target( 'gzip' ), $file, 16384, 3.0 );
		self::assertNotWPError( $reply );
		self::assertTrue( $reply->encoded );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only this test-owned core temporary file.
		self::assertStringStartsWith( "\x1f\x8b", file_get_contents( $file ) );
		self::assertLessThan( 4096, filesize( $file ) );
	}
	/** Abort at the first oversized chunk rather than waiting for the fixture's delayed tail. */
	public function test_body_limit_aborts_before_delayed_tail(): void {
		$file    = $this->temporary();
		$start   = microtime( true );
		$reply   = $this->transport()->request( $this->target( 'oversize' ), $file, 1024, 3.0 );
		$elapsed = microtime( true ) - $start;
		self::assertWPError( $reply );
		self::assertSame( 'xnetvn_agent_hub_wp_payload_too_large', $reply->get_error_code() );
		self::assertLessThan( 1.5, $elapsed );
		self::assertLessThanOrEqual( 1024, filesize( $file ) );
	}
	/** The remaining fractional deadline applies to the actual connection/read. */
	public function test_remaining_deadline_stops_slow_response(): void {
		$start   = microtime( true );
		$reply   = $this->transport()->request( $this->target( 'slow' ), $this->temporary(), 4096, 0.4 );
		$elapsed = microtime( true ) - $start;
		self::assertWPError( $reply );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_failed', $reply->get_error_code() );
		self::assertGreaterThanOrEqual( 0.2, $elapsed );
		self::assertLessThan( 1.5, $elapsed );
	}
	/** Incoming WordPress credentials/cookies do not appear at the download server. */
	public function test_credentials_and_cookies_are_not_forwarded(): void {
		// Positive control: prove the fixture detects deliberately supplied synthetic headers.
		$control = \WpOrg\Requests\Requests::get(
			'https://wp69/xnetvn-fetch-fixture?case=headers',
			array(
				'Authorization' => 'Bearer synthetic-network-canary',
				'Cookie'        => 'fixture=synthetic-network-canary',
			),
			array(
				'verify' => '/work/.local/mcp-clients-trust/lab-public-cert.pem',
				'proxy'  => false,
			)
		);
		$present = json_decode( $control->body, true, 16, JSON_THROW_ON_ERROR );
		self::assertTrue( $present['authorization_present'] );
		self::assertTrue( $present['cookie_present'] );
		$server                        = $_SERVER;
		$cookies                       = $_COOKIE;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-network-canary';
		$_SERVER['HTTP_COOKIE']        = 'fixture=synthetic-network-canary';
		$_COOKIE['fixture']            = 'synthetic-network-canary';
		try {
			$file  = $this->temporary();
			$reply = $this->transport()->request( $this->target( 'headers' ), $file, 4096, 3.0 );
			self::assertNotWPError( $reply );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only this test-owned core temporary file.
			$flags = json_decode( file_get_contents( $file ), true, 16, JSON_THROW_ON_ERROR );
			self::assertFalse( $flags['authorization_present'] );
			self::assertFalse( $flags['cookie_present'] );
		} finally {
			$_SERVER = $server;
			$_COOKIE = $cookies; }
	}
}
