<?php
/**
 * Runtime requirement checks; must remain compatible with PHP 7.2 syntax.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Bootstrap;

/** Checks requirements without loading modern plugin code. */
final class Requirements {

	/** Minimum supported PHP version. */
	public const MIN_PHP = '8.2.0';

	/** Minimum supported WordPress version. */
	public const MIN_WP = '6.9';

	/**
	 * Report MCP dependency failures separately from base plugin runtime failures.
	 *
	 * @param string|null $version Installed runtime adapter version.
	 * @return array<array{code:string,required:string,actual:string}>
	 */
	public static function adapter_failures( ?string $version ): array {
		if ( null === $version ) {
			return array(
				array(
					'code'     => 'adapter_missing',
					'required' => 'MCP Adapter >=0.7.0 <0.8.0',
					'actual'   => 'unavailable',
				),
			);
		}
		if ( version_compare( $version, '0.7.0', '<' ) || version_compare( $version, '0.8.0', '>=' ) ) {
			return array(
				array(
					'code'     => 'adapter_version',
					'required' => '>=0.7.0 <0.8.0',
					'actual'   => $version,
				),
			);
		}
		return array();
	}

	/**
	 * Return ordered failures.
	 *
	 * @param string $php_version Actual PHP version.
	 * @param string $wp_version Actual WordPress version.
	 * @param bool   $has_abilities_api Whether the Abilities API is present.
	 * @return array<array{code:string,required:string,actual:string}>
	 */
	public static function failures( string $php_version, string $wp_version, bool $has_abilities_api ): array {
		$failures = array();
		if ( version_compare( self::normalize( $php_version ), self::MIN_PHP, '<' ) ) {
			$failures[] = array(
				'code'     => 'php_version',
				'required' => self::MIN_PHP,
				'actual'   => $php_version,
			);
		}
		if ( version_compare( self::normalize( $wp_version ), self::MIN_WP, '<' ) ) {
			$failures[] = array(
				'code'     => 'wp_version',
				'required' => self::MIN_WP,
				'actual'   => $wp_version,
			);
		}
		if ( ! $has_abilities_api ) {
			$failures[] = array(
				'code'     => 'abilities_api',
				'required' => 'WordPress Abilities API',
				'actual'   => 'unavailable',
			);
		}
		return $failures;
	}

	/**
	 * Ignore release qualifiers for the minimum version comparison.
	 *
	 * @param string $version Version string.
	 * @return string
	 */
	private static function normalize( string $version ): string {
		return explode( '+', explode( '-', $version )[0] )[0];
	}
}
