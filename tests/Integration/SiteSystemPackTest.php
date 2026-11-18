<?php
/**
 * Machine identity on the real registry, dispatcher and output pipeline.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\System\SystemPack;
use XNetVN\AgentHubWP\Abilities\Packs\Users\UsersPack;
use XNetVN\AgentHubWP\Abilities\Registry;
use XNetVN\AgentHubWP\Abilities\SchemaBuilder;
use XNetVN\AgentHubWP\Abilities\SiteDispatcher;
use XNetVN\AgentHubWP\Domain\AbilityDescriptor;
use XNetVN\AgentHubWP\Domain\ApiKey;
use XNetVN\AgentHubWP\Domain\AuthContext;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Domain\SiteAuthContext;
use XNetVN\AgentHubWP\Domain\SiteCredential;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Policy\Policy;
use XNetVN\AgentHubWP\Policy\SafeMcpEnvelope;
use XNetVN\AgentHubWP\Policy\SitePolicy;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Proves the five handlers, the site payload, discovery and the nested denial on core. */
final class SiteSystemPackTest extends WP_UnitTestCase {
	/** Fixed current blog. */
	private const BLOG = 1;
	/** Fixed public key id. */
	private const KEY_ID = 'abcdefghijkl';

	/** Start every case at user 0 with the plugin abilities registered. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		wp_get_ability_categories();
		wp_get_abilities();
	}

	/** Restore the shared identity. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** The boot kernel registers the five handlers through the existing seam. */
	public function test_plugin_registers_the_five_machine_handlers_at_init(): void {
		$registry = $this->boot_registry();
		$expected = SystemPack::NAMES;
		sort( $expected );
		self::assertSame( $expected, $registry->names_for_site_scope( $this->scope() ) );
	}

	/** Site and human sessions, discovery and the nested authority boundary. */
	public function test_site_session_discovery_and_nested_authority(): void {
		[ $registry, $request, $settings ] = $this->build();
		self::assertTrue( wp_has_ability( 'xnetvn/get-session-info' ), 'The plugin must register the System abilities before this test.' );
		$context = $this->context();
		$request->bind_site( $context );
		$payload = $registry->execute( 'xnetvn/get-session-info', array() );
		self::assertIsArray( $payload, is_wp_error( $payload ) ? $payload->get_error_message() : '' );
		self::assertSame( array( 'user', 'principal', 'key', 'site', 'rate_limit' ), array_keys( $payload ) );
		self::assertNull( $payload['user'] );
		self::assertSame( array( 'type', 'blog_id', 'key_id', 'credential_ref' ), array_keys( $payload['principal'] ) );
		self::assertSame( 'site', $payload['principal']['type'] );
		self::assertSame( self::BLOG, $payload['principal']['blog_id'] );
		self::assertSame( self::KEY_ID, $payload['principal']['key_id'] );
		self::assertSame( 'xns:' . self::KEY_ID, $payload['principal']['credential_ref'] );
		self::assertSame( array( 'key_id', 'name', 'tier_max', 'toolsets', 'expires_at' ), array_keys( $payload['key'] ) );
		self::assertSame( self::KEY_ID, $payload['key']['key_id'] );
		self::assertSame( 'Site fixture', $payload['key']['name'] );
		self::assertSame( 'read', $payload['key']['tier_max'] );
		self::assertSame( array( 'system' ), $payload['key']['toolsets'] );
		self::assertSame( array( 'remaining' => null ), $payload['rate_limit'] );
		$json = (string) wp_json_encode( $payload );
		foreach ( array( 'secret', 'hash', 'pepper', 'token', 'created_by', 'revoked_by', 'last_used_ip', '203.0.113.9' ) as $needle ) {
			self::assertStringNotContainsString( $needle, strtolower( $json ), $needle );
		}

		$expected = SystemPack::NAMES;
		sort( $expected );
		self::assertSame( $expected, $registry->names_for_site_scope( $context->credential->scope ) );
		$discover = $registry->execute( 'xnetvn/discover-abilities', array( 'per_page' => 100 ) );
		self::assertIsArray( $discover, is_wp_error( $discover ) ? $discover->get_error_message() : '' );
		self::assertSame( $expected, array_column( $discover['items'], 'name' ) );
		self::assertSame( 5, $discover['total'] );
		$reference = $registry->execute( 'xnetvn/get-ability-info', array( 'name' => 'xnetvn/does-not-exist' ) );
		self::assertWPError( $reference );
		foreach ( array( 'xnetvn/get-my-profile', 'xnetvn/update-my-profile', 'xnetvn/set-user-status' ) as $hidden ) {
			$error = $registry->execute( 'xnetvn/get-ability-info', array( 'name' => $hidden ) );
			self::assertWPError( $error, $hidden );
			self::assertSame( $reference->get_error_code(), $error->get_error_code(), $hidden );
			self::assertSame( $reference->get_error_message(), $error->get_error_message(), $hidden );
		}

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$request->reset();
		$request->bind_human( new AuthContext( new ApiKey( 1, new KeyId( self::KEY_ID ), $editor, 'Fixture', new Scope( RiskTier::Read ), new DateTimeImmutable() ), '0123456789abcdef' ) );
		$definition = $registry->get( 'xnetvn/get-session-info' );
		self::assertNotNull( $definition );
		$human = ( new OutputPipeline( $settings ) )->process( $definition->execute( array() ), $definition->output_schema() );
		self::assertIsArray( $human, is_wp_error( $human ) ? $human->get_error_message() : '' );
		self::assertSame( $editor, $human['user']['id'] );
		self::assertSame( 'user', $human['principal']['type'] );
		self::assertSame( $editor, $human['principal']['user_id'] );
		self::assertNull( $human['rate_limit']['remaining'] );

		$request->reset();
		wp_set_current_user( 0 );
		self::assertSame( 0, get_current_user_id() );
		$request->bind_site( $context );
		$target        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$roles_before  = $this->roles( $target );
		$meta_before   = get_user_meta( $target );
		$counts_before = $this->counts();
		$denied        = $registry->execute(
			'xnetvn/execute-ability',
			array(
				'name'      => 'xnetvn/does-not-exist',
				'arguments' => array(),
			)
		);
		self::assertWPError( $denied );
		foreach ( array( 'xnetvn/update-my-profile', 'xnetvn/create-user', 'xnetvn/set-user-status' ) as $forbidden ) {
			$error = $registry->execute(
				'xnetvn/execute-ability',
				array(
					'name'      => $forbidden,
					'arguments' => array(
						'status'  => 'banned',
						'user_id' => $target,
					),
				)
			);
			self::assertWPError( $error, $forbidden );
			self::assertSame( $denied->get_error_code(), $error->get_error_code(), $forbidden );
			self::assertSame( $denied->get_error_message(), $error->get_error_message(), $forbidden );
		}
		self::assertSame( $roles_before, $this->roles( $target ) );
		self::assertSame( $meta_before, get_user_meta( $target ) );
		self::assertSame( $counts_before, $this->counts() );
		$control = $registry->execute(
			'xnetvn/execute-ability',
			array(
				'name'      => 'xnetvn/get-site-info',
				'arguments' => array(),
			)
		);
		self::assertIsArray( $control, is_wp_error( $control ) ? $control->get_error_message() : '' );
		self::assertSame( array( 'name', 'url', 'wp_version' ), array_keys( $control['result'] ) );
	}

	/**
	 * Build the isolated registry, dispatcher and pack.
	 *
	 * @return array{Registry,RequestScope,MemorySettings}
	 */
	private function build(): array {
		$settings   = new MemorySettings(
			array(
				'enabled'          => true,
				'tier_ceiling'     => 'admin',
				'allow_admin_tier' => false,
				'output_max_bytes' => 262144,
			)
		);
		$request    = new RequestScope();
		$policy     = new SitePolicy( $settings );
		$output     = new OutputPipeline( $settings );
		$dispatcher = new SiteDispatcher( $policy, $output );
		$registry   = new Registry( new Policy( $settings ), $request, $output, null, new SafeMcpEnvelope(), $policy, $dispatcher );
		$pack       = new SystemPack( new DefinitionFactory(), $registry, $request, new SchemaBuilder() );
		$registry->add_pack( $pack );
		$registry->add_pack( new UsersPack( new DefinitionFactory(), null ) );
		$dispatcher->bind_catalog( $registry, static fn( string $name ): ?AbilityDescriptor => $registry->resolved_descriptor( $name ) );
		$registry->load_packs();
		$pack->register_machine_handlers( $dispatcher );
		return array( $registry, $request, $settings );
	}

	/**
	 * Read the boot registry from the init pack loader.
	 *
	 * @return Registry
	 */
	private function boot_registry(): Registry {
		global $wp_filter;
		if ( ! isset( $wp_filter['init'] ) ) {
			self::fail( 'The init hook must contain the boot registry.' );
		}
		foreach ( $wp_filter['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && $callback[0] instanceof Registry && 'load_packs' === $callback[1] ) {
					return $callback[0];
				}
			}
		}
		self::fail( 'The boot registry must be registered on init.' );
	}

	/**
	 * Build the bounded system scope.
	 *
	 * @return SiteScope
	 */
	private function scope(): SiteScope {
		return new SiteScope( RiskTier::Read, array( 'system' ), SystemPack::NAMES, array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
	}

	/**
	 * Build the verified machine identity with non-secret attribution.
	 *
	 * @return SiteAuthContext
	 */
	private function context(): SiteAuthContext {
		$credential = new SiteCredential( 1, new KeyId( self::KEY_ID ), self::BLOG, 'Site fixture', $this->scope(), new DateTimeImmutable( '@' . time() ), null, 7, null, null, '203.0.113.9' );
		return new SiteAuthContext( $credential, 'fr19-request-id', '203.0.113.7' );
	}

	/**
	 * Read the current roles of one account.
	 *
	 * @param int $user_id Account.
	 * @return array<string>
	 */
	private function roles( int $user_id ): array {
		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		return array_values( $user->roles );
	}

	/**
	 * Count the fixture's users, posts, comments and terms.
	 *
	 * @return array<string,int>
	 */
	private function counts(): array {
		return array(
			'users'    => (int) count_users()['total_users'],
			'posts'    => (int) array_sum( array_map( 'intval', get_object_vars( wp_count_posts( 'post' ) ) ) ),
			'comments' => (int) wp_count_comments()->total_comments,
			'terms'    => (int) wp_count_terms(
				array(
					'hide_empty' => false,
					'taxonomy'   => array( 'category', 'post_tag' ),
				)
			),
		);
	}
}
