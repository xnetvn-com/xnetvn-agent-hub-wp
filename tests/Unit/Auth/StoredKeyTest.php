<?php
/**
 * Corrupt stored verification metadata cannot become a usable credential.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\StoredKey;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;

/** Validate the actual persisted-verifier boundary, independent of repository mocks. */
final class StoredKeyTest extends TestCase {
	/** Invalid hash syntax and pepper versions are rejected without normalization. */
	public function test_invalid_verifier_metadata_is_rejected(): void {
		$key = new ApiKey( 1, new KeyId( 'abcdefghijkl' ), 7, 'Verifier fixture', new Scope( RiskTier::Read ), new \DateTimeImmutable( '@1791072000' ) );
		foreach ( array( array( 'invalid', 1 ), array( str_repeat( 'g', 64 ), 1 ), array( str_repeat( 'a', 64 ), 0 ), array( str_repeat( 'a', 64 ), 256 ) ) as list($hash, $version) ) {
			try {
				new StoredKey( $key, $hash, $version );
				self::fail( 'Corrupt stored verification metadata was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Invalid credential metadata.', $exception->getMessage() );
			}
		}
	}
}
