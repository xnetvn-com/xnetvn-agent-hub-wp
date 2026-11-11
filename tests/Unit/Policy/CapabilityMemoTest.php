<?php
/**
 * Request-local capability memo contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Policy\CapabilityMemo;

/** Proves the memo key, its request lifetime and the absence of persistent storage. */
final class CapabilityMemoTest extends TestCase {
	/** One key resolves once and the remembered decision is reused. */
	public function test_one_key_resolves_once(): void {
		$calls    = 0;
		$memo     = new CapabilityMemo();
		$resolver = static function ( int $user_id, string $capability, int $object_id ) use ( &$calls ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The memo key fixes this resolver signature.
			++$calls;
			return 'read' === $capability;
		};
		self::assertTrue( $memo->allows( 7, 'read', 0, $resolver ) );
		self::assertFalse( $memo->allows( 7, 'write', 0, $resolver ) );
		self::assertTrue( $memo->allows( 7, 'read', 0, $resolver ) );
		self::assertSame( 2, $calls );
	}

	/** A different user, capability or object resolves on its own key. */
	public function test_distinct_keys_resolve_separately(): void {
		$calls    = array();
		$memo     = new CapabilityMemo();
		$resolver = static function ( int $user_id, string $capability, int $object_id ) use ( &$calls ): bool {
			$calls[] = $user_id . '|' . $capability . '|' . $object_id;
			return true;
		};
		self::assertTrue( $memo->allows( 7, 'read', 0, $resolver ) );
		self::assertTrue( $memo->allows( 8, 'read', 0, $resolver ) );
		self::assertTrue( $memo->allows( 7, 'edit_posts', 0, $resolver ) );
		self::assertTrue( $memo->allows( 7, 'read', 12, $resolver ) );
		self::assertSame( array( '7|read|0', '8|read|0', '7|edit_posts|0', '7|read|12' ), $calls );
	}

	/** Two memos never share a decision, so the next request starts live again. */
	public function test_memo_does_not_outlive_its_own_object(): void {
		$calls    = 0;
		$resolver = static function ( int $user_id, string $capability, int $object_id ) use ( &$calls ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- The memo key fixes this resolver signature.
			++$calls;
			return true;
		};
		( new CapabilityMemo() )->allows( 7, 'read', 0, $resolver );
		( new CapabilityMemo() )->allows( 7, 'read', 0, $resolver );
		self::assertSame( 2, $calls );
	}

	/** The memo keeps its decision inside its own object and writes nothing outside. */
	public function test_memo_keeps_no_persistent_state(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Policy/CapabilityMemo.php';
		self::assertTrue( is_file( $path ), 'The capability memo source is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned memo source only.
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		$forbidden = array(
			'set_transient',
			'get_transient',
			'set_site_transient',
			'get_site_transient',
			'wp_cache_set',
			'wp_cache_get',
			'wp_cache_add',
			'wp_cache_delete',
			'update_option',
			'get_option',
			'add_option',
			'delete_option',
		);
		foreach ( $forbidden as $call ) {
			self::assertStringNotContainsString( $call, $source, 'The memo must not write a decision outside its own object.' );
		}
		self::assertStringNotContainsString( 'static $', $source, 'The memo must not share state between objects.' );
	}
}
