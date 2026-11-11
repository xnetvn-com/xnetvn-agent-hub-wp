<?php
/**
 * Realistic server header precedence without a WordPress service.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Apache can populate empty Authorization variables without a supplied credential. */
final class HeaderExtractorTest extends TestCase {
	/** Empty server variables must not shadow the enabled fallback header. */
	public function test_empty_primary_header_allows_enabled_fallback(): void {
		$before = $_SERVER;
		try {
			$_SERVER['HTTP_AUTHORIZATION']          = '';
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = '';
			$_SERVER['HTTP_X_XNETVN_API_KEY']       = 'Bearer fixture-value';
			self::assertSame( 'fixture-value', ( new HeaderExtractor( new MemorySettings() ) )->bearer() );
		} finally {
			$_SERVER = $before;
		}
	}

	/** A malformed nonempty primary remains authoritative and cannot fall through. */
	public function test_malformed_primary_does_not_fall_back(): void {
		$before = $_SERVER;
		try {
			$_SERVER['HTTP_AUTHORIZATION']    = 'Basic malformed';
			$_SERVER['HTTP_X_XNETVN_API_KEY'] = 'Bearer fixture-value';
			self::assertNull( ( new HeaderExtractor( new MemorySettings() ) )->bearer() );
		} finally {
			$_SERVER = $before;
		}
	}
}
