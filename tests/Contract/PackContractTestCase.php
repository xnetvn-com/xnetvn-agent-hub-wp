<?php
/**
 * Shared pack schema and identity contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Contract;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Contracts\AbilityPack;
use XNetVN\AgentHubWP\Domain\RiskTier;

/** Independent schema validation complements runtime WordPress validation. */
abstract class PackContractTestCase extends TestCase {
	/**
	 * Validate every immutable pack definition and output example.
	 *
	 * @param AbilityPack $pack Pack.
	 */
	public static function assert_pack_contract( AbilityPack $pack ): void {
		$names     = array();
		$validator = new Validator();
		foreach ( $pack->definitions() as $definition ) {
			$descriptor = $definition->descriptor();
			self::assertMatchesRegularExpression( '/\Axnetvn\/[a-z0-9-]+\z/', $descriptor->name );
			self::assertLessThanOrEqual( 128, strlen( $definition->tool_name() ) );
			self::assertNotContains( $definition->tool_name(), $names );
			$names[] = $definition->tool_name();
			self::assertGreaterThanOrEqual( 32, strlen( $definition->description() ) );
			self::assertNotSame( '', $descriptor->visibility_cap );
			self::assertFalse( $definition->input_schema()['additionalProperties'] );
			self::assertFalse( $definition->output_schema()['additionalProperties'] );
			if ( true === $definition->annotations()['readonly'] ) {
				self::assertSame( RiskTier::Read, $descriptor->tier );
			}
			if ( true === $definition->annotations()['destructive'] ) {
				self::assertFalse( $descriptor->tier->atMost( RiskTier::Write ) );
			}
			$schema = json_decode( wp_json_encode( $definition->output_schema() ) );
			$data   = json_decode( wp_json_encode( $definition->example() ) );
			self::assertTrue( $validator->validate( $data, $schema )->isValid(), $descriptor->name );
		}
	}
}
