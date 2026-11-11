<?php
/**
 * Pepper material cannot be exported or revived by copying metadata.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\Pepper;

/** Verify real opaque-value behavior, without a database or mocked cryptography. */
final class PepperTest extends TestCase {
	/** Exported JSON contains only a version and redaction, never pepper bytes. */
	public function test_json_export_is_redacted(): void {
		$pepper = new Pepper( 1, str_repeat( 'P', 32 ) );
		self::assertSame( str_repeat( 'P', 32 ), $pepper->reveal() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exercise native JSON serialization.
		self::assertSame( '{"id":1,"value":"[redacted]"}', json_encode( $pepper, JSON_THROW_ON_ERROR ) );
	}
	/** A cloned DTO has no weakly owned material and cannot authenticate a key. */
	public function test_clone_has_no_material(): void {
		$copy = clone new Pepper( 1, str_repeat( 'P', 32 ) );
		$this->expectException( \LogicException::class );
		$copy->reveal();
	}
	/** Invalid version and byte length are rejected rather than normalized. */
	public function test_invalid_material_is_rejected(): void {
		foreach ( array( array( 0, str_repeat( 'P', 32 ) ), array( 256, str_repeat( 'P', 32 ) ), array( 1, 'short' ) ) as list($id, $bytes) ) {
			try {
				new Pepper( $id, $bytes );
				self::fail( 'Invalid pepper material was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Invalid credential pepper.', $exception->getMessage() );
			}
		}
	}
}
