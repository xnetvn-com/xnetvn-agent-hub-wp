<?php
/**
 * Users pack contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;

/** Twelve strict user tools with reviewed tiers and visibility. */
final class UsersPackContractTest extends TestCase {
	/** Twelve tools with exact tiers and visibility. */
	public function test_twelve_tools_with_contract(): void {
		$pack = new UsersPack( new DefinitionFactory(), null );
		$defs = iterator_to_array( $pack->definitions() );
		self::assertCount( 12, $defs );
		$by_name = array();
		foreach ( $defs as $def ) {
			$by_name[ $def->descriptor()->name ] = $def;
		}
		$expected = array(
			'xnetvn/list-users'              => array( 'read', 'list_users' ),
			'xnetvn/get-user'                => array( 'read', 'list_users' ),
			'xnetvn/list-roles'              => array( 'read', 'list_users' ),
			'xnetvn/get-user-capabilities'   => array( 'read', 'list_users' ),
			'xnetvn/get-my-profile'          => array( 'read', 'read' ),
			'xnetvn/update-my-profile'       => array( 'write', 'read' ),
			'xnetvn/create-user'             => array( 'admin', 'list_users' ),
			'xnetvn/update-user'             => array( 'admin', 'list_users' ),
			'xnetvn/set-user-role'           => array( 'admin', 'list_users' ),
			'xnetvn/set-user-status'         => array( 'admin', 'list_users' ),
			'xnetvn/send-password-reset'     => array( 'admin', 'list_users' ),
			'xnetvn/terminate-user-sessions' => array( 'admin', 'list_users' ),
		);
		foreach ( $expected as $name => $pair ) {
			self::assertArrayHasKey( $name, $by_name, $name );
			self::assertSame( $pair[0], $by_name[ $name ]->descriptor()->tier->value, $name );
			self::assertSame( 'users', $by_name[ $name ]->descriptor()->toolset, $name );
			self::assertSame( $pair[1], $by_name[ $name ]->descriptor()->visibility_cap, $name );
			self::assertGreaterThanOrEqual( 32, strlen( $by_name[ $name ]->description() ) );
			self::assertStringContainsString( 'untrusted data', $by_name[ $name ]->description() );
			self::assertFalse( $by_name[ $name ]->input_schema()['additionalProperties'] ?? true );
		}
		foreach ( $defs as $def ) {
			self::assertMatchesRegularExpression( '/\Axnetvn\/[a-z0-9-]+\z/', $def->descriptor()->name );
			self::assertFalse( $def->input_schema()['additionalProperties'] );
			self::assertFalse( $def->output_schema()['additionalProperties'] );
			$validator = new \Opis\JsonSchema\Validator();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
			$schema = json_decode( (string) json_encode( $def->output_schema() ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
			$data = json_decode( (string) json_encode( $def->example() ) );
			self::assertTrue( $validator->validate( $data, $schema )->isValid(), $def->descriptor()->name );
		}
	}
}
