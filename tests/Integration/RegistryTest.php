<?php
/**
 * Private WordPress ability registration and policy execution tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Uses the actual core registry and real permission callbacks. */
final class RegistryTest extends WP_UnitTestCase {
	/** A private definition executes through core and stays private in normalized meta. */
	public function test_private_core_registration(): void {
		$settings   = new MemorySettings();
		$request    = new RequestScope();
		$registry   = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$name       = 'xnetvn/test-private-registry';
		$definition = ( new DefinitionFactory() )->create(
			array(
				'name'          => $name,
				'label'         => 'Private fixture',
				'description'   => 'Returns a safe private fixture. Use this to verify core permission and output boundaries.',
				'inputSchema'   => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'outputSchema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
					'required'             => array( 'id' ),
				),
				'tier'          => RiskTier::Read,
				'toolset'       => 'system',
				'annotations'   => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'visibilityCap' => 'read',
				'handler'       => static fn(): array => array( 'id' => 7 ),
				'example'       => array( 'id' => 7 ),
			)
		);
		$registry->add( $definition );
		wp_get_ability_categories();
		wp_get_abilities();
		$saved_categories = $GLOBALS['wp_filter']['wp_abilities_api_categories_init'] ?? null;
		$saved_abilities  = $GLOBALS['wp_filter']['wp_abilities_api_init'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate and restore core registration hooks in this test.
		$GLOBALS['wp_filter']['wp_abilities_api_categories_init'] = new \WP_Hook();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate and restore core registration hooks in this test.
		$GLOBALS['wp_filter']['wp_abilities_api_init'] = new \WP_Hook();
		$registry->register();
		// The core registry initializes lazily; exercise its real initialization hooks.
		do_action( 'wp_abilities_api_categories_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core's registration lifecycle.
		do_action( 'wp_abilities_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise core's registration lifecycle.
		try {
			$ability = wp_get_ability( $name );
			self::assertNotNull( $ability );
			self::assertFalse( $ability->get_meta()['show_in_rest'] );
			self::assertFalse( $ability->get_meta()['public'] ?? false );
			$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
			wp_set_current_user( $owner );
			$request->context = new \XNetVN\AgentHubWP\Domain\AuthContext( new \XNetVN\AgentHubWP\Domain\ApiKey( 1, new \XNetVN\AgentHubWP\Domain\KeyId( 'abcdefghijkl' ), $owner, 'Fixture', new \XNetVN\AgentHubWP\Domain\Scope( RiskTier::Read ), new \DateTimeImmutable() ), '0123456789abcdef' );
			self::assertSame( array( 'id' => 7 ), $ability->execute( array() ) );
			$target_events = array();
			$started       = static function ( string $target, array $keys ) use ( &$target_events ): void {
				$target_events[] = array(
					'name' => $target,
					'keys' => $keys,
				);
			};
			add_action( 'xnetvn_agent_hub_wp_tool_started', $started, 10, 2 );
			try {
				self::assertSame( array( 'id' => 7 ), $registry->execute( $name, array() ) );
				self::assertSame(
					array(
						array(
							'name' => $name,
							'keys' => array(),
						),
					),
					$target_events
				);
			} finally {
				remove_action( 'xnetvn_agent_hub_wp_tool_started', $started, 10 );
			}
			$core = \WP_Abilities_Registry::get_instance();
			$core->unregister( $name );
			$core->register(
				$name,
				array(
					'label'               => 'Replacement fixture',
					'description'         => 'Foreign replacement after this registry bound its reviewed callback.',
					'category'            => 'xnetvn-system',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static fn(): array => array( 'id' => 99 ),
				)
			);
			self::assertWPError( $registry->execute( $name, array() ) );
			$request->reset();
			wp_set_current_user( 0 );
			self::assertWPError( $ability->execute( array() ) );
		} finally {
			wp_unregister_ability( $name );
			$registry->unregister_hooks();
			if ( null !== $saved_categories ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact saved hook object.
				$GLOBALS['wp_filter']['wp_abilities_api_categories_init'] = $saved_categories; }
			if ( null !== $saved_abilities ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact saved hook object.
				$GLOBALS['wp_filter']['wp_abilities_api_init'] = $saved_abilities; }
		}
	}

	/** Duplicate names/tool mappings cannot replace a previously reviewed definition. */
	public function test_duplicate_definition_is_rejected(): void {
		$settings = new MemorySettings();
		$registry = new Registry( new Policy( $settings ), new RequestScope(), new OutputPipeline( $settings ) );
		$factory  = new DefinitionFactory();
		$data     = array(
			'name'          => 'xnetvn/test-duplicate',
			'label'         => 'Fixture',
			'description'   => 'Returns a safe fixture. Use this only for duplicate-name contract verification.',
			'inputSchema'   => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'outputSchema'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'tier'          => RiskTier::Read,
			'toolset'       => 'system',
			'annotations'   => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'visibilityCap' => 'read',
			'handler'       => static fn(): array => array(),
		);
		$registry->add( $factory->create( $data ) );
		$this->expectException( \InvalidArgumentException::class );
		$registry->add( $factory->create( $data ) );
	}

	/** An existing foreign callback cannot inherit approval from this private definition map. */
	public function test_foreign_registration_collision_is_denied(): void {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$name     = 'xnetvn/test-foreign-collision';
		\WP_Ability_Categories_Registry::get_instance()->register(
			'xnetvn-collision-fixture',
			array(
				'label'       => 'Collision fixture',
				'description' => 'Independent collision registration fixture.',
			)
		);
		$core = \WP_Abilities_Registry::get_instance();
		$core->register(
			$name,
			array(
				'label'               => 'Foreign fixture',
				'description'         => 'Foreign callback fixture for collision testing.',
				'category'            => 'xnetvn-collision-fixture',
				'permission_callback' => '__return_true',
				'execute_callback'    => static fn(): array => array( 'id' => 9 ),
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);
		$definition = ( new DefinitionFactory() )->create(
			array(
				'name'          => $name,
				'label'         => 'Reviewed fixture',
				'description'   => 'Returns a reviewed private fixture. Use this only to verify callback identity binding.',
				'inputSchema'   => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'outputSchema'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'tier'          => RiskTier::Read,
				'toolset'       => 'system',
				'annotations'   => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'visibilityCap' => 'read',
				'handler'       => static fn(): array => array(),
			)
		);
		$registry->add( $definition );
		$registry->register_abilities();
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner );
		$request->context = new \XNetVN\AgentHubWP\Domain\AuthContext( new \XNetVN\AgentHubWP\Domain\ApiKey( 1, new \XNetVN\AgentHubWP\Domain\KeyId( 'abcdefghijkl' ), $owner, 'Fixture', new \XNetVN\AgentHubWP\Domain\Scope( RiskTier::Read ), new \DateTimeImmutable() ), '0123456789abcdef' );
		try {
			self::assertWPError( $registry->execute( $name, array() ) );
		} finally {
			wp_unregister_ability( $name );
		}
	}
}
