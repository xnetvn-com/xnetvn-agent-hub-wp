<?php
/**
 * Editable-role availability across admin and REST contexts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;

/**
 * REST/MCP requests run without wp-admin includes; the guard must load them.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RoleEditableLoaderTest extends TestCase {
	/** The admin file on disk is loaded lazily so assignable roles keep working. */
	public function test_assignable_loads_admin_includes_when_missing(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is the WordPress core bootstrap constant, not a plugin global.
		define( 'ABSPATH', __DIR__ . '/Fixtures/RestContext/' );
		// phpcs:enable
		self::assertFalse( function_exists( 'get_editable_roles' ) );
		self::assertTrue( RoleGuard::assignable( 'author' ) );
		self::assertTrue( function_exists( 'get_editable_roles' ) );
	}

	/** Missing admin includes stay fail-closed instead of failing open. */
	public function test_assignable_fail_closed_without_admin_includes(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ABSPATH is the WordPress core bootstrap constant, not a plugin global.
		define( 'ABSPATH', __DIR__ . '/Fixtures/Empty/' );
		// phpcs:enable
		self::assertFalse( function_exists( 'get_editable_roles' ) );
		self::assertFalse( RoleGuard::assignable( 'author' ) );
	}
}
