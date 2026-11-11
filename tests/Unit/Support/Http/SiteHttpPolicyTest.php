<?php
/**
 * Site-level external HTTP blocking policy tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Support\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Support\Http\SiteHttpPolicy;

/** Site constants decide whether a remote URL may be fetched at all. */
final class SiteHttpPolicyTest extends TestCase {
	/** Initialize isolated WordPress function substitutes. */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test substitute for the WordPress wrapper under test.
				return parse_url( $url, $component );
			}
		);
	}
	/** Remove every function substitute. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
	/** A site that does not block external HTTP allows every fetch. */
	public function test_unblocked_sites_allow_the_fetch(): void {
		self::assertTrue( ( new SiteHttpPolicy( false, null ) )->allows( 'https://example.com/a' ) );
	}
	/** Blocking refuses every host outside the accessible list. */
	public function test_blocked_sites_allow_only_an_accessible_host(): void {
		self::assertFalse( ( new SiteHttpPolicy( true, null ) )->allows( 'https://example.com/a' ) );
		self::assertFalse( ( new SiteHttpPolicy( true, '' ) )->allows( 'https://example.com/a' ) );
		self::assertTrue( ( new SiteHttpPolicy( true, 'example.com' ) )->allows( 'https://example.com/a' ) );
		self::assertFalse( ( new SiteHttpPolicy( true, 'other.com' ) )->allows( 'https://example.com/a' ) );
	}
	/** A wildcard entry matches subdomains only, never the bare domain. */
	public function test_wildcard_accessible_hosts_match_subdomains_only(): void {
		self::assertTrue( ( new SiteHttpPolicy( true, '*.example.com' ) )->allows( 'https://cdn.example.com/a' ) );
		self::assertFalse( ( new SiteHttpPolicy( true, '*.example.com' ) )->allows( 'https://example.com/a' ) );
		self::assertFalse( ( new SiteHttpPolicy( true, '*.example.com' ) )->allows( 'https://evil-example.com/a' ) );
	}
	/** A URL without a host cannot match any accessible entry. */
	public function test_missing_host_is_refused(): void {
		self::assertFalse( ( new SiteHttpPolicy( true, 'example.com' ) )->allows( 'not-a-url' ) );
	}
	/**
	 * Native true blocks every host outside the exact and wildcard allowlist.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constants_true_blocks_unlisted_hosts(): void {
		$this->assert_from_constants( true, true );
	}
	/**
	 * The string '1' is truthy for a site that configures the constant that way.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constants_string_one_blocks_unlisted_hosts(): void {
		$this->assert_from_constants( '1', true );
	}
	/**
	 * The string '0' is falsy and leaves external requests unblocked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_from_constants_string_zero_allows_every_host(): void {
		$this->assert_from_constants( '0', false );
	}
	/**
	 * Exercise the production constants path in this isolated process.
	 *
	 * @param bool|string $block_external WP_HTTP_BLOCK_EXTERNAL value.
	 * @param bool        $blocking       Whether blocking is expected.
	 */
	private function assert_from_constants( bool|string $block_external, bool $blocking ): void {
		self::assertFalse( defined( 'WP_HTTP_BLOCK_EXTERNAL' ), 'The isolated process must start without WP_HTTP_BLOCK_EXTERNAL.' );
		self::assertFalse( defined( 'WP_ACCESSIBLE_HOSTS' ), 'The isolated process must start without WP_ACCESSIBLE_HOSTS.' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress site constant under test in an isolated process.
		define( 'WP_HTTP_BLOCK_EXTERNAL', $block_external );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress site constant under test in an isolated process.
		define( 'WP_ACCESSIBLE_HOSTS', 'example.com, *.example.com' );
		$policy = SiteHttpPolicy::fromConstants();
		self::assertTrue( $policy->allows( 'https://example.com/a' ) );
		self::assertTrue( $policy->allows( 'https://cdn.example.com/a' ) );
		self::assertSame( ! $blocking, $policy->allows( 'https://other.example/a' ) );
		self::assertSame( ! $blocking, $policy->allows( 'https://evil-example.com/a' ) );
	}
}
