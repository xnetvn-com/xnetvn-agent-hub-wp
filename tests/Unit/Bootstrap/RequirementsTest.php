<?php
/**
 * Requirement boundary tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Bootstrap\Requirements;

/** Tests version floors and ordered requirement failures. */
final class RequirementsTest extends TestCase {

	/**
	 * Check the supported version matrix.
	 *
	 * @dataProvider versions
	 * @param string        $php PHP version.
	 * @param string        $wp WordPress version.
	 * @param bool          $abilities Abilities API availability.
	 * @param array<string> $codes Expected failure codes.
	 */
	public function test_requirements( string $php, string $wp, bool $abilities, array $codes ): void {
		self::assertSame( $codes, array_column( Requirements::failures( $php, $wp, $abilities ), 'code' ) );
	}

	/**
	 * Required versions remain explicit in diagnostics.
	 */
	public function test_failure_contains_required_and_actual_versions(): void {
		self::assertSame(
			array(
				'code'     => 'php_version',
				'required' => '8.2.0',
				'actual'   => '8.1.9',
			),
			Requirements::failures( '8.1.9', '6.9', true )[0]
		);
	}

	/** Adapter absence/unsupported minors close MCP while base plugin requirements stay separate. */
	public function test_adapter_range(): void {
		self::assertSame( array(), Requirements::adapter_failures( '0.7.0' ) );
		self::assertSame( array(), Requirements::adapter_failures( '0.7.9' ) );
		self::assertSame( 'adapter_missing', Requirements::adapter_failures( null )[0]['code'] );
		self::assertSame( 'adapter_version', Requirements::adapter_failures( '0.6.1' )[0]['code'] );
		self::assertSame( 'adapter_version', Requirements::adapter_failures( '0.8.0' )[0]['code'] );
	}

	/**
	 * Return version combinations.
	 *
	 * @return array<string,array{string,string,bool,array<string>}>
	 */
	public function versions(): array {
		return array(
			'minimum'      => array( '8.2.0', '6.9', true, array() ),
			'newer'        => array( '8.4.1', '7.1.2', true, array() ),
			'old php'      => array( '8.1.9', '6.9', true, array( 'php_version' ) ),
			'old wp'       => array( '8.2.0', '6.8.3', true, array( 'wp_version' ) ),
			'no api'       => array( '8.2.0', '6.9', false, array( 'abilities_api' ) ),
			'all failures' => array( '7.4.33', '6.5', false, array( 'php_version', 'wp_version', 'abilities_api' ) ),
			'wp rc'        => array( '8.2.0', '6.9-RC1', true, array() ),
			'wp alpha'     => array( '8.2.0', '7.2-alpha-63948', true, array() ),
			'php dev'      => array( '8.2.0-dev', '6.9', true, array() ),
		);
	}
}
