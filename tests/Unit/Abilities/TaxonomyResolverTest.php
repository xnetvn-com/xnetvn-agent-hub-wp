<?php
/**
 * Taxonomy resolver format gates.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyResolver;

/** Format validation never builds a route from caller strings. */
final class TaxonomyResolverTest extends TestCase {
	/** Set up the isolated error substitute. */
	protected function setUp(): void {
		parent::setUp();
		require dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
	}

	/** Bad slugs are rejected before any taxonomy lookup. */
	public function test_rejects_bad_format(): void {
		$resolver = new TaxonomyResolver();
		$bad      = $resolver->resolve( 'Bad Name' );
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $bad->code );
		foreach ( array( '', str_repeat( 'a', 33 ), '../evil' ) as $slug ) {
			$result = $resolver->resolve( $slug );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->code );
		}
	}
}
