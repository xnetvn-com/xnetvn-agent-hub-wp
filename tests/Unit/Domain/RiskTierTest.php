<?php
/**
 * Risk ordering and validation tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\KeySecret;
use XNetVN\AgentHubWP\Domain\RiskTier;

/** Pins the ordered, restrictive domain contract. */
final class RiskTierTest extends TestCase {

	/** All sixteen tier comparisons follow the fixed ordering. */
	public function test_tier_order(): void {
		$tiers = array( RiskTier::Read, RiskTier::Write, RiskTier::Destructive, RiskTier::Admin );
		foreach ( $tiers as $i => $tier ) {
			self::assertSame( $i, $tier->rank() );
			foreach ( $tiers as $j => $other ) {
				self::assertSame( $i <= $j, $tier->atMost( $other ) );
			}
		}
	}

	/** Identifier validation rejects a token-like or uppercase identifier. */
	public function test_invalid_identifier(): void {
		$this->expectException( \InvalidArgumentException::class );
		new KeyId( 'ABCdefghijkl' );
	}

	/** Secret values never appear in export/debug/JSON. */
	public function test_secret_is_not_exported(): void {
		$value  = str_repeat( 'Z', 43 );
		$secret = new KeySecret( $value );
		self::assertSame( $value, $secret->reveal() );
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Verify real PHP secret-export behavior without WordPress.
		self::assertStringNotContainsString( $value, print_r( $secret, true ) );
		self::assertStringNotContainsString( $value, var_export( $secret, true ) );
		self::assertStringNotContainsString( $value, (string) json_encode( $secret ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/** Secret validation rejects padding and a wrong length. */
	public function test_invalid_secret(): void {
		$this->expectException( \InvalidArgumentException::class );
		new KeySecret( str_repeat( 'Z', 42 ) . '=' );
	}
}
