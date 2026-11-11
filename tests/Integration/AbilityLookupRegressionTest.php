<?php
/**
 * Ability lookup outside the MCP route must keep its recorded results.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;

/** Pins the lookup results recorded before the lazy MCP route change. */
final class AbilityLookupRegressionTest extends WP_UnitTestCase {

	/**
	 * Representative private abilities of every pack.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function representative_abilities(): array {
		return array(
			'xnetvn/get-session-info'      => array( 'system', 'read' ),
			'xnetvn/get-site-info'         => array( 'system', 'read' ),
			'xnetvn/get-ability-info'      => array( 'system', 'read' ),
			'xnetvn/execute-ability'       => array( 'system', 'read' ),
			'xnetvn/list-posts'            => array( 'content', 'read' ),
			'xnetvn/get-post'              => array( 'content', 'read' ),
			'xnetvn/delete-post'           => array( 'content', 'destructive' ),
			'xnetvn/list-media'            => array( 'media', 'read' ),
			'xnetvn/upload-media-from-url' => array( 'media', 'write' ),
			'xnetvn/list-comments'         => array( 'comments', 'read' ),
			'xnetvn/delete-comment'        => array( 'comments', 'destructive' ),
			'xnetvn/list-terms'            => array( 'taxonomy', 'read' ),
			'xnetvn/delete-term'           => array( 'taxonomy', 'destructive' ),
			'xnetvn/list-users'            => array( 'users', 'read' ),
			'xnetvn/list-roles'            => array( 'users', 'read' ),
			'xnetvn/create-user'           => array( 'users', 'admin' ),
		);
	}

	/** Every private ability stays registered and queryable outside the MCP route. */
	public function test_representative_abilities_stay_registered(): void {
		foreach ( $this->representative_abilities() as $name => $expected ) {
			self::assertTrue( wp_has_ability( $name ), $name . ' must stay registered.' );
			$ability = wp_get_ability( $name );
			self::assertNotNull( $ability, $name . ' must stay queryable.' );
			self::assertSame( $name, $ability->get_name() );
			self::assertSame( $expected[0], $ability->get_meta()['xnetvn']['toolset'] );
			self::assertSame( $expected[1], $ability->get_meta()['xnetvn']['tier'] );
		}
	}

	/** Unknown and forbidden names keep their recorded absence outside the MCP route. */
	public function test_unknown_abilities_stay_absent(): void {
		self::assertFalse( wp_has_ability( 'xnetvn/not-a-real-ability' ) );
		self::assertFalse( wp_has_ability( 'other/get-data' ) );
	}

	/** The recorded metadata of one system ability keeps its exact values. */
	public function test_recorded_ability_metadata_is_unchanged(): void {
		$ability = wp_get_ability( 'xnetvn/get-session-info' );
		self::assertNotNull( $ability );
		self::assertSame( 'Session permissions', $ability->get_label() );
		self::assertSame(
			'Returns your verified user, key scope, site and remaining request budget. Use this first to learn what you are allowed to do. Takes no parameters. Credentials and hashes are never returned.',
			$ability->get_description()
		);
		$meta = $ability->get_meta();
		self::assertFalse( $meta['public'] ?? false, 'A core-normalised public flag must stay false.' );
		self::assertArrayNotHasKey( 'public', $meta['xnetvn'] );
		self::assertFalse( $meta['show_in_rest'] );
		self::assertSame( 'read', $meta['xnetvn']['visibility_cap'] );
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$meta['annotations']
		);
		self::assertSame(
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			$ability->get_input_schema()
		);
		$output = $ability->get_output_schema();
		self::assertSame( 'object', $output['type'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertContains( 'user', $output['required'] );
	}

	/** The ability catalog known to core keeps its recorded size outside the MCP route. */
	public function test_recorded_ability_catalog_size_is_unchanged(): void {
		$ours = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( str_starts_with( $ability->get_name(), 'xnetvn/' ) ) {
				++$ours;
			}
		}
		self::assertSame( 62, $ours );
	}
}
