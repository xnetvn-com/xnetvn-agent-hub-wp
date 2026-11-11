<?php
/**
 * Remote fetch policy and pre-transmission transport proof.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Support\Http\SafeRemoteFetcher;
use XNetVN\AgentHubWP\Support\Http\NativeDnsResolver;
use XNetVN\AgentHubWP\Support\Http\SiteHttpPolicy;

/** Network success is a separate explicit runtime group. */
final class RemoteFetchTest extends WP_UnitTestCase {
	/** Policy failures use one safe message and never reach an HTTP transport. */
	public function test_policy_refusal_is_uniform(): void {
		$fetcher  = new SafeRemoteFetcher( new NativeDnsResolver() );
		$messages = array();
		foreach ( array( 'http://169.254.169.254/a', 'https://127.0.0.1/a', 'https://localhost/a', 'https://example.test:8443/a' ) as $url ) {
			$result = $fetcher->fetch( $url, 1024, 3, 0 );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_refused', $result->get_error_code() );
			$messages[] = $result->get_error_message();
		}
		self::assertCount( 1, array_unique( $messages ) );
	}
	/** Site-wide blocking refuses a URL before DNS resolution and transport. */
	public function test_block_external_refuses_before_transport(): void {
		$dns       = new class() implements \XNetVN\AgentHubWP\Contracts\DnsResolver {
			/** Resolution attempts observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/**
			 * Return a synthetic public address without contacting it.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				++$this->calls;
				return array( '93.184.216.34' ); }
		};
		$transport = new class() implements \XNetVN\AgentHubWP\Contracts\DownloadTransport {
			/** Transmission attempts observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Fixture availability.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return true; }
			/**
			 * Record an unexpected transmission that must never happen.
			 *
			 * @param array{url:string,host:string,ip:string} $target Validated target.
			 * @param string                                  $file Owned temporary destination.
			 * @param int                                     $maximum Maximum bytes.
			 * @param float                                   $timeout Remaining seconds.
			 * @return \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error
			 */
			public function request( array $target, string $file, int $maximum, float $timeout ): \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error {
				++$this->calls;
				return new \WP_Error( 'unexpected_transport_call', 'The transport must not run.' ); }
		};
		$result    = ( new SafeRemoteFetcher( $dns, $transport, new SiteHttpPolicy( true, null ) ) )->fetch( 'https://example.com/a', 1024, 3, 0 );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_disabled', $result->get_error_code() );
		self::assertSame( 0, $dns->calls );
		self::assertSame( 0, $transport->calls );
	}
	/** A redirect to a blocked host stops after the allowed first transmission. */
	public function test_block_external_refuses_a_blocked_redirect(): void {
		$dns       = new class() implements \XNetVN\AgentHubWP\Contracts\DnsResolver {
			/** Resolution attempts observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/**
			 * Return a synthetic public address without contacting it.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				++$this->calls;
				return array( '8.8.8.8' ); }
		};
		$transport = new class() implements \XNetVN\AgentHubWP\Contracts\DownloadTransport {
			/** Owned intermediate files.
			 *
			 * @var array<string>
			 */
			public array $files = array();
			/** Transmissions observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Fixture availability.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return true; }
			/**
			 * Return a redirect to a host outside the accessible list.
			 *
			 * @param array{url:string,host:string,ip:string} $target Validated target.
			 * @param string                                  $file Owned temporary destination.
			 * @param int                                     $maximum Maximum bytes.
			 * @param float                                   $timeout Remaining seconds.
			 * @return \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error
			 */
			public function request( array $target, string $file, int $maximum, float $timeout ): \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error {
				++$this->calls;
				$this->files[] = $file;
				return new \XNetVN\AgentHubWP\Domain\DownloadReply( 302, 'https://blocked.example/next', false );
			}
		};
		$result    = ( new SafeRemoteFetcher( $dns, $transport, new SiteHttpPolicy( true, 'example.org' ) ) )->fetch( 'https://example.org/fixture', 1024, 3, 2 );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_disabled', $result->get_error_code() );
		self::assertSame( 1, $transport->calls );
		self::assertSame( 1, $dns->calls );
		foreach ( $transport->files as $file ) {
			self::assertFileDoesNotExist( $file ); }
	}
	/** An accessible hostname never overrides the private-address SSRF refusal. */
	public function test_accessible_host_with_private_address_is_refused_before_transport(): void {
		$dns       = new class() implements \XNetVN\AgentHubWP\Contracts\DnsResolver {
			/** Resolution attempts observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/**
			 * Return a private address for the accessible hostname.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				++$this->calls;
				return array( '10.0.0.1' ); }
		};
		$transport = new class() implements \XNetVN\AgentHubWP\Contracts\DownloadTransport {
			/** Transmission attempts observed by this fixture.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Fixture availability.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return true; }
			/**
			 * Record an unexpected transmission that must never happen.
			 *
			 * @param array{url:string,host:string,ip:string} $target Validated target.
			 * @param string                                  $file Owned temporary destination.
			 * @param int                                     $maximum Maximum bytes.
			 * @param float                                   $timeout Remaining seconds.
			 * @return \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error
			 */
			public function request( array $target, string $file, int $maximum, float $timeout ): \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error {
				++$this->calls;
				return new \WP_Error( 'unexpected_transport_call', 'The transport must not run.' ); }
		};
		$result    = ( new SafeRemoteFetcher( $dns, $transport, new SiteHttpPolicy( true, 'example.com' ) ) )->fetch( 'https://example.com/a', 1024, 3, 0 );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_refused', $result->get_error_code() );
		self::assertSame( 1, $dns->calls );
		self::assertSame( 0, $transport->calls );
	}
	/**
	 * Real public TLS request proves the pre-send hook executes and streams bytes.
	 *
	 * @group external-http
	 */
	public function test_public_https_stream(): void {
		$fetcher = new SafeRemoteFetcher( new NativeDnsResolver() );
		$result  = $fetcher->fetch( 'https://s.w.org/images/wmark.png', 1048576, 15, 0 );
		self::assertNotWPError( $result );
		try {
			self::assertStringStartsWith( "\x89PNG", $result->bytes() );
		} finally {
			$result->close(); }
	}
	/** Shared WordPress HTTP filters cannot mutate a guarded download. */
	public function test_transport_is_private_from_global_http_filters(): void {
		$calls   = 0;
		$observe = static function ( $args ) use ( &$calls ) {
			++$calls;
			return $args;
		};
		add_filter( 'http_request_args', $observe, PHP_INT_MAX );
		try {
			$result = ( new SafeRemoteFetcher( new NativeDnsResolver() ) )->fetch( 'https://s.w.org/images/wmark.png', 1048576, 15, 0 );
			self::assertNotWPError( $result );
			try {
				self::assertSame( 0, $calls );
			} finally {
				$result->close(); }
		} finally {
			remove_filter( 'http_request_args', $observe, PHP_INT_MAX ); }
	}
	/** A real oversized public HTTPS response returns the safe size reason. */
	public function test_public_https_size_abort(): void {
		$result = ( new SafeRemoteFetcher( new NativeDnsResolver() ) )->fetch( 'https://s.w.org/images/wmark.png', 1024, 15, 0 );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_payload_too_large', $result->get_error_code() );
	}
	/**
	 * Every unsafe next hop is refused and its temporary file is removed.
	 *
	 * @dataProvider refused_redirects
	 * @param string $location Controlled Location header.
	 * @param int    $expected_calls Expected transmissions.
	 * @param bool   $rebind Change the next DNS answer.
	 */
	public function test_controlled_redirect_refuses_private_destination( string $location, int $expected_calls = 1, bool $rebind = false ): void {
		$transport = new class( $location ) implements \XNetVN\AgentHubWP\Contracts\DownloadTransport {
			/** Owned intermediate files.
			 *
			 * @var array<string>
			 */
			public array $files = array();
			/** Bind the controlled redirect.
			 *
			 * @param string $location Controlled redirect.
			 */
			public function __construct( private string $location ) {}
			/** Calls observed by this deterministic provider.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Fixture transport availability.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return true; }
			/**
			 * Return a controlled redirect; never make a network connection.
			 *
			 * @param array{url:string,host:string,ip:string} $target Validated target.
			 * @param string                                  $file Owned temporary destination.
			 * @param int                                     $maximum Maximum bytes.
			 * @param float                                   $timeout Remaining seconds.
			 * @return \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error
			 */
			public function request( array $target, string $file, int $maximum, float $timeout ): \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error {
				++$this->calls;
				$this->files[] = $file;
				return new \XNetVN\AgentHubWP\Domain\DownloadReply( 302, $this->location, false );
			}
		};
		$dns       = new class( $rebind ) implements \XNetVN\AgentHubWP\Contracts\DnsResolver {
			/** Resolver invocation count.
			 *
			 * @var int
			 */
			private int $calls = 0;
			/** Bind whether the second DNS answer changes.
			 *
			 * @param bool $rebind Simulate mixed private/public DNS on the next hop.
			 */
			public function __construct( private bool $rebind ) {}
			/**
			 * Public synthetic address; the transport does not contact it.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				++$this->calls;
				return $this->rebind && $this->calls > 1 ? array( '8.8.8.8', '127.0.0.1' ) : array( '8.8.8.8' ); }
		};
		$result    = ( new SafeRemoteFetcher( $dns, $transport ) )->fetch( 'https://example.org/fixture', 1024, 3, 2 );
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_remote_fetch_refused', $result->get_error_code() );
		self::assertSame( $expected_calls, $transport->calls );
		foreach ( $transport->files as $file ) {
			self::assertFileDoesNotExist( $file ); }
	}
	/** Unsafe remote destinations and bounded loops.
	 *
	 * @return array<string,array{string,int?,bool?}>
	 */
	public function refused_redirects(): array {
		return array(
			'rebinding'   => array( 'https://example.org/next', 1, true ),
			'loop'        => array( 'https://example.org/next', 3 ),
			'loopback'    => array( 'https://127.0.0.1/never' ),
			'downgrade'   => array( 'http://example.org/never' ),
			'credentials' => array( 'https://user:redacted@example.org/never' ),
			'port'        => array( 'https://example.org:8443/never' ),
			'oversized'   => array( 'https://example.org/' . str_repeat( 'x', 2048 ) ),
		);
	}
	/** Provider failures and encoded bodies never leave an intermediate file behind. */
	public function test_download_outcome_cleanup(): void {
		$dns = new class() implements \XNetVN\AgentHubWP\Contracts\DnsResolver {
			/** Return a synthetic public address without contacting it.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				return array( '8.8.8.8' ); }
		};
		foreach ( array( 'error', 'throw', 'encoded', 'empty', 'status', 'success' ) as $scenario ) {
			$transport = new class( $scenario ) implements \XNetVN\AgentHubWP\Contracts\DownloadTransport {
				/** Owned destination observed by the fixture.
				 *
				 * @var string
				 */
				public string $file = '';
				/** Bind the fixed synthetic outcome.
				 *
				 * @param string $scenario Fixed outcome.
				 */
				public function __construct( private string $scenario ) {}
				/** Fixture availability.
				 *
				 * @return bool
				 */
				public function available(): bool {
					return true; }
				/** Simulate a provider outcome after writing a partial body.
				 *
				 * @param array{url:string,host:string,ip:string} $target Target.
				 * @param string                                  $file Owned file.
				 * @param int                                     $maximum Maximum bytes.
				 * @param float                                   $timeout Remaining time.
				 * @return \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error
				 * @throws \RuntimeException For the synthetic thrown failure.
				 */
				public function request( array $target, string $file, int $maximum, float $timeout ): \XNetVN\AgentHubWP\Domain\DownloadReply|\WP_Error {
					$this->file = $file;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixed synthetic bytes in this test-owned core temporary file.
					file_put_contents( $file, 'empty' === $this->scenario ? '' : 'partial-body' );
					if ( 'throw' === $this->scenario ) {
						throw new \RuntimeException( 'synthetic-provider-private-detail' ); }
					if ( 'error' === $this->scenario ) {
						return new \WP_Error( 'unknown_provider_error', 'synthetic-provider-private-detail', array( 'private' => 'canary' ) ); }
					return new \XNetVN\AgentHubWP\Domain\DownloadReply( 'status' === $this->scenario ? 503 : 200, null, 'encoded' === $this->scenario );
				}
			};
			$result    = ( new SafeRemoteFetcher( $dns, $transport ) )->fetch( 'https://example.org/fixture', 1024, 3, 0 );
			if ( 'success' === $scenario ) {
				self::assertNotWPError( $result );
				self::assertSame( 'partial-body', $result->bytes() );
				self::assertFileExists( $transport->file );
				$result->close();
				$result->close();
			} else {
				self::assertWPError( $result );
				self::assertStringNotContainsString( 'synthetic-provider-private-detail', $result->get_error_message() );
				self::assertArrayNotHasKey( 'private', (array) $result->get_error_data() );
			}
			self::assertFileDoesNotExist( $transport->file );
		}
	}
}
