<?php
/**
 * URL and all-answer DNS policy without network access.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Contracts\DnsResolver;
use XNetVN\AgentHubWP\Support\Http\RemoteUrlPolicy;

/** A safe first A answer cannot hide an unsafe AAAA or later A answer. */
final class RemoteUrlPolicyTest extends TestCase {
	/**
	 * Build a deterministic DNS responder.
	 *
	 * @param array<string> $addresses DNS answers.
	 * @return DnsResolver
	 */
	private function dns( array $addresses ): DnsResolver {
		return new class( $addresses ) implements DnsResolver {
			/**
			 * Store synthetic DNS answers.
			 *
			 * @param array<string> $addresses Answers.
			 */
			public function __construct( private array $addresses ) {}
			/**
			 * Return the configured complete answer set.
			 *
			 * @param string $host Host.
			 * @return array<string>
			 */
			public function addresses( string $host ): array {
				return $this->addresses; }
		};
	}
	/** Forbidden URL forms fail before a connection exists. */
	public function test_forbidden_forms(): void {
		$policy = new RemoteUrlPolicy( $this->dns( array( '8.8.8.8' ) ) );
		foreach ( array( 'http://public.test/a', 'file:///etc/passwd', 'ftp://public.test/a', 'gopher://public.test/a', 'data:text/plain,fixture', 'javascript:fixture', 'https://user@public.test/a', 'https://user:redacted@public.test/a', 'https://127.0.0.1/a', 'https://2130706433/a', 'https://0x7f.1/a', 'https://017700000001/a', 'https://[::1]/a', 'https://[::ffff:127.0.0.1]/a', 'https://169.254.169.254/a', 'https://public.test:8443/a', 'https://public.test:22/a', 'https://public.test:80/a', 'https://public.test:8080/a', 'https://localhost/a', 'https://a.localhost/a', 'https://a.local/a', 'https://a.internal/a', 'https://a.home.arpa/a', 'https://single/a', 'https://public.test/\\a', "https://public.test/a\n" ) as $url ) {
			try {
				$policy->resolve( $url, array() );
				self::fail( 'Unsafe URL accepted.' );
			} catch ( \DomainException | \InvalidArgumentException ) {
				self::assertTrue( true ); }
		}
	}
	/** Every answer is checked, including mixed-family and mixed-address results. */
	public function test_mixed_dns_answers(): void {
		foreach ( array( array( '8.8.8.8', '::1' ), array( '8.8.8.8', 'fc00::1' ), array( '8.8.8.8', '10.0.0.1' ), array( '169.254.169.254' ), array(), array_fill( 0, 17, '8.8.8.8' ) ) as $answers ) {
			try {
				( new RemoteUrlPolicy( $this->dns( $answers ) ) )->resolve( 'https://public.test/a', array() );
				self::fail( 'Unsafe DNS accepted.' );
			} catch ( \DomainException ) {
				self::assertTrue( true ); }
		}
	}
	/** A permitted URL returns a pinned validated address; own host is denied. */
	public function test_safe_and_same_host(): void {
		$policy = new RemoteUrlPolicy( $this->dns( array( '8.8.8.8' ) ) );
		$result = $policy->resolve( 'https://PUBLIC.test/a', array( 'own.test' ) );
		self::assertSame( 'public.test', $result['host'] );
		self::assertSame( '8.8.8.8', $result['ip'] );
		$this->expectException( \DomainException::class );
		$policy->resolve( 'https://OWN.test/a', array( 'own.test' ) );
	}
	/** SS-15: excessive length is a malformed argument rather than a policy disclosure. */
	public function test_excessive_length_is_invalid(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new RemoteUrlPolicy( $this->dns( array( '8.8.8.8' ) ) ) )->resolve( 'https://public.test/' . str_repeat( 'x', 2048 ), array() );
	}
	/** SS-16/U-FETCH-5: normalize supported IDN and refuse it when intl is unavailable. */
	public function test_idn_depends_on_available_normalizer(): void {
		$policy   = new RemoteUrlPolicy( $this->dns( array( '8.8.8.8' ) ) );
		$punycode = $policy->resolve( 'https://xn--bcher-kva.example/a', array() );
		self::assertSame( 'xn--bcher-kva.example', $punycode['host'] );
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->expectException( \DomainException::class );
			$policy->resolve( 'https://bücher.example/a', array() );
			return;
		}
		$idn = $policy->resolve( 'https://bücher.example/a', array() );
		self::assertSame( $punycode, $idn );
	}
}
