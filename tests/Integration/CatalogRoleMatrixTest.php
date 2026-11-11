<?php
/**
 * Whole-catalog role/blog matrix declarations and cleanup contracts (S5).
 *
 * The remote matrix executes the cases through real HTTPS; this file pins the
 * declaration set against the real AbilityCatalog, validates every payload
 * factory against the installed strict schema, and checks the ownership and
 * settings-restore semantics the remote fixture relies on.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames -- Case rows are the fixed ticket vocabulary.

use Opis\JsonSchema\Validator;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentsPack;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaPack;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TaxonomyPack;
use XNetVN\AgentHubWP\Abilities\Packs\Users\RoleGuard;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Contracts\AbilitySpec;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Pins the declaration, schema and cleanup contracts without network access. */
final class CatalogRoleMatrixTest extends WP_UnitTestCase {
	/**
	 * Loaded declarations.
	 *
	 * @var array<string,mixed>
	 */
	private static array $declarations = array();

	/**
	 * Real catalog definitions keyed by canonical name.
	 *
	 * @var array<string,AbilitySpec>
	 */
	private static array $catalog = array();

	/**
	 * Prepare the shared contract fixtures.
	 *
	 * @throws \RuntimeException When the declarations are unavailable.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$file = dirname( __DIR__ ) . '/E2E/catalog-role-cases.php';
		$data = require $file;
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Catalog role declarations unavailable.' );
		}
		self::$declarations = $data;
		self::$catalog      = self::build_catalog();
	}

	/**
	 * Build the real catalog through the reviewed packs and contracts.
	 *
	 * @return array<string,AbilitySpec>
	 */
	private static function build_catalog(): array {
		$settings = new MemorySettings();
		$request  = new RequestScope();
		$registry = new Registry( new Policy( $settings ), $request, new OutputPipeline( $settings ) );
		$registry->add_pack( new ContentPack( new DefinitionFactory() ) );
		$registry->add_pack( new MediaPack( new DefinitionFactory(), $settings ) );
		$registry->add_pack( new CommentsPack( new DefinitionFactory() ) );
		$registry->add_pack( new TaxonomyPack( new DefinitionFactory() ) );
		$registry->add_pack( new UsersPack( new DefinitionFactory() ) );
		$registry->add_pack( new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() ) );
		$registry->load_packs();
		$catalog = array();
		foreach ( $registry->all() as $definition ) {
			$catalog[ $definition->descriptor()->name ] = $definition;
		}
		return $catalog;
	}

	/**
	 * Declarations expose exactly the installed sixty-two tools.
	 */
	public function test_declared_tool_set_matches_real_catalog(): void {
		$declared = self::$declarations['tools'];
		self::assertCount( 62, self::$catalog );
		self::assertCount( 62, $declared );
		$catalog_names  = array_keys( self::$catalog );
		$declared_names = array_keys( $declared );
		sort( $catalog_names, SORT_STRING );
		sort( $declared_names, SORT_STRING );
		self::assertSame( $catalog_names, $declared_names );
		$packs = array();
		$wire  = array();
		foreach ( $declared as $name => $tool ) {
			$definition = self::$catalog[ $name ];
			$descriptor = $definition->descriptor();
			self::assertSame( $tool['pack'], $descriptor->toolset, $name );
			self::assertSame( $tool['tier'], $descriptor->tier->value, $name );
			self::assertSame( $tool['visibility'], $descriptor->visibility_cap, $name );
			self::assertSame( str_replace( '/', '-', $name ), $definition->tool_name(), $name );
			self::assertArrayNotHasKey( $definition->tool_name(), $wire, $name );
			$wire[ $definition->tool_name() ] = true;
			$packs[ $descriptor->toolset ]    = (int) ( $packs[ $descriptor->toolset ] ?? 0 ) + 1;
		}
		ksort( $packs );
		self::assertSame(
			array(
				'comments' => 10,
				'content'  => 19,
				'media'    => 7,
				'system'   => 5,
				'taxonomy' => 9,
				'users'    => 12,
			),
			$packs
		);
	}

	/**
	 * Every tool declares exactly one row per actor class.
	 */
	public function test_every_tool_declares_eight_actor_rows(): void {
		$actors = self::$declarations['actors'];
		$seen   = array();
		foreach ( self::$declarations['cases'] as $id => $case ) {
			self::assertSame( $id, $case['id'] );
			self::assertContains( $case['actor'], $actors, $id );
			self::assertContains( $case['expected'], array( 'allowed', 'denied', 'na' ), $id );
			self::assertArrayHasKey( $case['name'], self::$declarations['tools'], $id );
			if ( 'catalog.discover' !== $case['payload'] ) {
				self::assertSame( self::$declarations['tools'][ $case['name'] ]['pack'], $case['pack'], $id );
			}
			$seen[ $case['tool'] ][ $case['actor'] ][ $case['expected'] ] = true;
		}
		$expected_actors = $actors;
		sort( $expected_actors, SORT_STRING );
		foreach ( self::$declarations['tools'] as $name => $tool ) {
			$wire = str_replace( '/', '-', $name );
			self::assertArrayHasKey( $wire, $seen, $name );
			$actual_actors = array_keys( $seen[ $wire ] );
			sort( $actual_actors, SORT_STRING );
			self::assertSame( $expected_actors, $actual_actors, $name );
		}
	}

	/**
	 * A tool without an allowed runtime path is explicit; every tool denies.
	 */
	public function test_every_tool_has_allowed_and_denied_paths(): void {
		$allowed = array();
		$denied  = array();
		$gaps    = array();
		foreach ( self::$declarations['cases'] as $case ) {
			if ( 'allowed' === $case['expected'] ) {
				$allowed[ $case['tool'] ] = true;
			}
			if ( 'denied' === $case['expected'] ) {
				$denied[ $case['tool'] ] = true;
			}
			if ( 'na' === $case['expected'] && 'remote_fetch_disabled_default' === $case['reason'] ) {
				$gaps[ $case['tool'] ] = true;
			}
		}
		foreach ( self::$declarations['tools'] as $name => $tool ) {
			$wire = str_replace( '/', '-', $name );
			self::assertArrayHasKey( $wire, $denied, $name );
			if ( isset( $allowed[ $wire ] ) ) {
				continue;
			}
			self::assertSame( 'xnetvn/upload-media-from-url', $name, $name );
			self::assertArrayHasKey( $wire, $gaps, $name );
		}
	}

	/**
	 * Every payload intent is valid against the installed strict input schema.
	 */
	public function test_payload_factories_match_real_input_schemas(): void {
		$validator = new Validator();
		foreach ( self::$declarations['cases'] as $case ) {
			if ( 'na' === $case['expected'] || isset( $case['covered_by'] ) ) {
				continue;
			}
			if ( in_array( $case['payload'], array( 'catalog.tools_list', 'catalog.tools_list_other' ), true ) ) {
				continue;
			}
			$definition = self::$catalog[ $case['name'] ] ?? null;
			self::assertInstanceOf( AbilitySpec::class, $definition, $case['id'] );
			$context = $this->payload_context( $case );
			$payload = xnetvn_agent_hub_wp_catalog_role_payload( $case, $context );
			$schema  = json_decode( (string) wp_json_encode( $definition->input_schema() ), false, 64, JSON_THROW_ON_ERROR );
			$valid   = $validator->validate( $this->as_json( $payload, $definition->input_schema() ), $schema )->isValid();
			if ( 'schema' === $case['layer'] ) {
				self::assertFalse( $valid, $case['id'] );
			} else {
				self::assertTrue( $valid, $case['id'] );
			}
		}
	}

	/**
	 * Convert a payload to the JSON shapes Opis distinguishes (object vs array).
	 *
	 * @param mixed               $value  PHP payload value.
	 * @param array<string,mixed> $schema Declared schema.
	 * @return mixed
	 */
	private function as_json( mixed $value, array $schema ): mixed {
		$type = $schema['type'] ?? null;
		if ( is_array( $type ) ) {
			if ( in_array( 'object', $type, true ) && ( ! is_array( $value ) || array() === $value || ! array_is_list( $value ) ) ) {
				$type = 'object';
			} elseif ( in_array( 'array', $type, true ) && is_array( $value ) ) {
				$type = 'array';
			} elseif ( null === $value && in_array( 'null', $type, true ) ) {
				return null;
			} else {
				$type = $type[0] ?? null;
			}
		}
		if ( 'object' === $type ) {
			$object     = new \stdClass();
			$properties = (array) ( $schema['properties'] ?? array() );
			foreach ( (array) $value as $key => $item ) {
				$object->{$key} = isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ? $this->as_json( $item, $properties[ $key ] ) : $item;
			}
			return $object;
		}
		if ( 'array' === $type && is_array( $value ) ) {
			$items = $schema['items'] ?? null;
			$out   = array();
			foreach ( $value as $item ) {
				$out[] = is_array( $items ) ? $this->as_json( $item, $items ) : $item;
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Build a deterministic factory context for one declaration.
	 *
	 * @param array<string,mixed> $case Case.
	 * @return array<string,mixed>
	 */
	private function payload_context( array $case ): array {
		$target = 0;
		$extra  = 0;
		$user   = 1;
		if ( isset( $case['target'] ) && is_string( $case['target'] ) && str_starts_with( $case['target'], 'user:' ) ) {
			$user = 1;
		} elseif ( isset( $case['target'] ) && is_string( $case['target'] ) && 'none' !== $case['target'] ) {
			$target = (int) ( self::$declarations['targets'][ $case['target'] ]['id'] ?? 0 );
		}
		if ( isset( $case['extra_target'] ) && is_string( $case['extra_target'] ) ) {
			$extra = (int) ( self::$declarations['targets'][ $case['extra_target'] ]['id'] ?? 0 );
		}
		if ( isset( $case['extra_revision'] ) ) {
			$extra = (int) $case['extra_revision'];
		}
		return array(
			'target'   => $target,
			'extra'    => $extra,
			'user'     => $user,
			'campaign' => 'cat-20260101T000000Z',
			'actor'    => (string) $case['actor'],
		);
	}

	/**
	 * Real WordPress capabilities agree with the declaration model.
	 */
	public function test_declaration_agrees_with_real_wordpress_capabilities(): void {
		$role_guard = RoleGuard::ensure_banned_role();
		self::assertSame( 'banned', $role_guard );
		$ids = array();
		foreach ( array(
			'admin'       => 'administrator',
			'editor'      => 'editor',
			'author'      => 'author',
			'contributor' => 'contributor',
			'subscriber'  => 'subscriber',
			'banned'      => 'banned',
		) as $actor => $role ) {
			$ids[ $actor ] = self::factory()->user->create( array( 'role' => $role ) );
		}
		foreach ( self::$declarations['capabilities'] as $actor => $caps ) {
			if ( 'super-admin' === $actor ) {
				continue;
			}
			if ( 'anonymous' === $actor ) {
				continue;
			}
			$id = (int) $ids[ $actor ];
			foreach ( array( 'read', 'edit_posts', 'upload_files', 'moderate_comments', 'list_users' ) as $capability ) {
				self::assertSame( (bool) $caps[ $capability ], user_can( $id, $capability ), $actor . ':' . $capability );
			}
		}
		foreach ( array( 'admin', 'editor', 'author', 'contributor' ) as $actor ) {
			self::assertTrue( user_can( (int) $ids[ $actor ], Capabilities::USE ), $actor );
		}
		foreach ( array( 'subscriber', 'banned' ) as $actor ) {
			self::assertFalse( user_can( (int) $ids[ $actor ], Capabilities::USE ), $actor );
		}
		foreach ( self::$declarations['cases'] as $case ) {
			if ( 'auth' === $case['layer'] ) {
				continue;
			}
			if ( isset( $case['covered_by'] ) ) {
				continue;
			}
			if ( in_array( $case['actor'], array( 'anonymous', 'super-admin' ), true ) ) {
				continue;
			}
			$id = (int) $ids[ $case['actor'] ];
			if ( isset( $case['variant_action'] ) ) {
				self::assertSame( 'denied', $case['expected'], $case['id'] );
				continue;
			}
			if ( 'denied' === $case['expected'] && 'policy' === $case['layer'] ) {
				$scope         = self::$declarations['keys'][ $case['key'] ];
				$tool          = self::$declarations['tools'][ $case['name'] ];
				$order         = array(
					'read'        => 0,
					'write'       => 1,
					'destructive' => 2,
					'admin'       => 3,
				);
				$policy_denies = $order[ $tool['tier'] ] > $order[ $scope['tier'] ]
					|| ! in_array( $tool['pack'], $scope['toolsets'], true )
					|| ( isset( $scope['allow'] ) && ! in_array( $case['name'], $scope['allow'], true ) )
					|| ( isset( $scope['deny'] ) && in_array( $case['name'], $scope['deny'], true ) )
					|| false === (bool) user_can( $id, $tool['visibility'] );
				self::assertTrue( $policy_denies, $case['id'] );
				continue;
			}
			if ( 'allowed' === $case['expected'] || 'tool' === $case['layer'] ) {
				self::assertTrue( (bool) user_can( $id, self::$declarations['tools'][ $case['name'] ]['visibility'] ), $case['id'] );
			}
		}
	}

	/**
	 * Guard, campaign and ownership helpers refuse foreign values.
	 */
	public function test_declared_guards_and_ownership_predicates(): void {
		foreach ( array( 'wp69', 'wp71', 'network-main', 'network-child' ) as $target ) {
			self::assertTrue( xnetvn_agent_hub_wp_catalog_role_target_allowed( $target ) );
		}
		foreach ( array( 'wp70', 'production', 'network', '' ) as $target ) {
			self::assertFalse( xnetvn_agent_hub_wp_catalog_role_target_allowed( $target ) );
		}
		self::assertTrue( xnetvn_agent_hub_wp_catalog_role_campaign_valid( 'cat-20261025T120000Z' ) );
		foreach ( array( 'cat-2026-10-25', 'CAT-20261025T120000Z', 'cat-20261025T120000', 'cat-20261025T120000Z-extra' ) as $campaign ) {
			self::assertFalse( xnetvn_agent_hub_wp_catalog_role_campaign_valid( $campaign ) );
		}
		$campaign = 'cat-20261025T120000Z';
		self::assertTrue( xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, 'catalog-role-' . $campaign . '-admin' ) );
		self::assertFalse( xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, 'catalog-role-cat-20261025T120001Z-admin' ) );
		self::assertFalse( xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, 'role-seo-' . $campaign . '-admin' ) );
		self::assertTrue( xnetvn_agent_hub_wp_catalog_role_marked( $campaign, 'CatalogRole ' . $campaign . ' post_own_admin catalog-role-' . $campaign . '-post_own_admin' ) );
		self::assertFalse( xnetvn_agent_hub_wp_catalog_role_marked( $campaign, 'CatalogRole cat-20261025T120001Z post catalog-role-cat-20261025T120001Z-post' ) );
	}

	/**
	 * Cleanup binds to the exact campaign login and marker in real WordPress.
	 */
	public function test_cleanup_predicates_bind_to_run_and_owner(): void {
		$campaign     = 'cat-20261025T120000Z';
		$owned        = self::factory()->user->create(
			array(
				'user_login' => 'catalog-role-' . $campaign . '-admin',
				'user_email' => 'catalog-role-admin-12345678@example.test',
				'role'       => 'administrator',
			)
		);
		$foreign      = self::factory()->user->create( array( 'user_login' => 'catalog-role-cat-20261025T120001Z-admin' ) );
		$owned_user   = get_userdata( $owned );
		$foreign_user = get_userdata( $foreign );
		self::assertInstanceOf( \WP_User::class, $owned_user );
		self::assertInstanceOf( \WP_User::class, $foreign_user );
		self::assertTrue( xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, $owned_user->user_login ) );
		self::assertFalse( xnetvn_agent_hub_wp_catalog_role_owned_login( $campaign, $foreign_user->user_login ) );
		$owned_post   = self::factory()->post->create(
			array(
				'post_title'  => 'CatalogRole ' . $campaign . ' post_own_admin',
				'post_name'   => 'catalog-role-' . $campaign . '-post-own-admin',
				'post_author' => $owned,
			)
		);
		$foreign_post = self::factory()->post->create(
			array(
				'post_title' => 'CatalogRole cat-20261025T120001Z post',
				'post_name'  => 'catalog-role-cat-20261025T120001Z-post',
			)
		);
		self::assertTrue( xnetvn_agent_hub_wp_catalog_role_marked( $campaign, get_post( $owned_post )->post_title . ' ' . get_post( $owned_post )->post_name ) );
		self::assertFalse( xnetvn_agent_hub_wp_catalog_role_marked( $campaign, get_post( $foreign_post )->post_title . ' ' . get_post( $foreign_post )->post_name ) );
		$owned_ids = array( (int) $owned );
		self::assertContains( (int) get_post( $owned_post )->post_author, $owned_ids );
		self::assertNotContains( (int) get_post( $foreign_post )->post_author, $owned_ids );
	}

	/**
	 * The fixture setting snapshot restores the exact option value.
	 */
	public function test_settings_snapshot_restore_is_exact(): void {
		$had_before = false !== get_option( WpSiteSettings::OPTION, false );
		$settings   = new WpSiteSettings();
		$before     = $settings->all();
		try {
			$settings->update(
				array(
					'tier_ceiling'     => 'admin',
					'allow_admin_tier' => true,
				)
			);
			$changed = ( new WpSiteSettings() )->all();
			self::assertSame( 'admin', $changed['tier_ceiling'] );
			self::assertTrue( $changed['allow_admin_tier'] );
			$settings->update( $before );
			self::assertSame( $before, ( new WpSiteSettings() )->all() );
		} finally {
			if ( ! $had_before ) {
				delete_option( WpSiteSettings::OPTION );
			}
		}
	}
}
