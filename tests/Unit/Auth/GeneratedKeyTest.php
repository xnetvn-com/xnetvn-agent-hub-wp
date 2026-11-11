<?php
/**
 * Prevent accidental duplication or persistence of one-time credentials.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\KeyGenerator;

/** Checks credential lifetime boundaries. */
final class GeneratedKeyTest extends TestCase {

	/** Cloning cannot create a second disclosure budget. */
	public function test_generated_key_cannot_be_cloned(): void {
		$key = ( new KeyGenerator() )->generate();
		$this->expectException( \Error::class );
		clone $key;
	}

	/** Serializing a generated credential is forbidden. */
	public function test_generated_key_cannot_be_serialized(): void {
		$key = ( new KeyGenerator() )->generate();
		$this->expectException( \LogicException::class );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verify that credential serialization is explicitly blocked.
		serialize( $key );
	}
}
