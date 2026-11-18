<?php
/**
 * Site principal admission through the real MCP server boundary.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use DateTimeImmutable;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Auth\SiteTokenCodec;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\SiteScope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\RouteMatcher;
use XNetVN\AgentHubWP\Mcp\ServerGateway;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbSiteKeyRepository;
use XNetVN\AgentHubWP\Support\SystemClock;

/** Proves the boot gateway is wired for site keys and the real authenticator refuses unsafe principals. */
final class SiteGatewayTest extends WP_UnitTestCase {

	/** Original server values.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	/** Original routing object.
	 *
	 * @var mixed
	 */
	private mixed $wp = null;

	/** Install the real schema and boot route. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Integration route marker.
		}
		$this->server           = $_SERVER;
		$this->wp               = $GLOBALS['wp'] ?? null;
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clear the integration cookie jar.
		$_COOKIE = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Integration route fixture.
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => RouteMatcher::MCP_ROUTE ) );
		( new Migrator( $wpdb, new SystemClock() ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
	}

	/** Restore globals and remove only this test's site rows. */
	public function tear_down(): void {
		global $wpdb;
		$_SERVER = $this->server;
		if ( null === $this->wp ) {
			unset( $GLOBALS['wp'] );
		} else {
			$GLOBALS['wp'] = $this->wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the integration routing object.
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_site_keys' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** The boot gateway receives the site repository and advertises the machine list. */
	public function test_boot_gateway_is_wired_for_site_scope(): void {
		$gateway = $this->boot_gateway();
		$token   = $this->issue_site_key();

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$adapter                       = $this->fake_adapter();
		$gateway->create( $adapter );
		$registry = $this->registry( $gateway );
		self::assertSame(
			$registry->names_for_site_scope( $this->stored_scope( $token ) ),
			$adapter->args[9],
			'The site hint must equal the machine scope list.'
		);
		self::assertNotSame( $registry->names(), $adapter->args[9], 'The site list must differ from the human catalog.' );

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$adapter = $this->fake_adapter();
		$gateway->create( $adapter );
		self::assertSame( $this->registry_names( $gateway ), $adapter->args[9], 'A token-less request keeps the human catalog.' );
		self::assertNotEmpty( $adapter->args[9] );
	}

	/** A real site key binds a user-0 same-blog context; cookie-only and token-less are refused. */
	public function test_real_authenticator_admission_and_refusal(): void {
		$token   = $this->issue_site_key();
		$auth    = $this->boot_authenticator();
		$request = $this->authenticator_request( $auth );
		$gateway = $this->boot_gateway();

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		self::assertSame( 0, $auth->determine_user( 0 ) );
		self::assertTrue( $auth->rest_result( true ) );
		self::assertInstanceOf( \XNetVN\AgentHubWP\Domain\SiteAuthContext::class, $request->site_current() );
		self::assertSame( 1, $request->site_current()->blog_id() );

		$adapter = $this->fake_adapter();
		$gateway->create( $adapter );
		self::assertTrue( ( $adapter->args[12] )(), 'The transport callback must admit the bound site principal.' );

		$request->reset();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$denied = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_authentication_required', $denied->get_error_code() );

		$adapter = $this->fake_adapter();
		$gateway->create( $adapter );
		self::assertFalse( ( $adapter->args[12] )(), 'A cookie-only request must never admit the MCP transport.' );

		$request->reset();
		wp_set_current_user( 0 );
		$denied = $auth->rest_result( true );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'xnetvn_agent_hub_wp_authentication_required', $denied->get_error_code() );
	}

	/** Find the boot-registered gateway. */
	private function boot_gateway(): ServerGateway {
		global $wp_filter;
		foreach ( $wp_filter['mcp_adapter_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && $callback[0] instanceof ServerGateway ) {
					return $callback[0];
				}
			}
		}
		self::fail( 'The boot gateway must be registered on mcp_adapter_init.' );
	}

	/** Find the boot authentication router. */
	private function boot_authenticator(): Authenticator {
		global $wp_filter;
		foreach ( $wp_filter['rest_authentication_errors']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_array( $callback ) && $callback[0] instanceof Authenticator ) {
					return $callback[0];
				}
			}
		}
		self::fail( 'The boot authenticator must be registered on rest_authentication_errors.' );
	}

	/**
	 * Read the authenticator request boundary.
	 *
	 * @param Authenticator $auth Authenticator.
	 * @return RequestScope
	 */
	private function authenticator_request( Authenticator $auth ): RequestScope {
		$property = new \ReflectionProperty( Authenticator::class, 'request' );
		$property->setAccessible( true );
		return $property->getValue( $auth );
	}

	/**
	 * Read the gateway's registry through reflection.
	 *
	 * @param ServerGateway $gateway Gateway.
	 * @return \XNetVN\AgentHubWP\Abilities\Registry
	 */
	private function registry( ServerGateway $gateway ): \XNetVN\AgentHubWP\Abilities\Registry {
		$property = new \ReflectionProperty( ServerGateway::class, 'registry' );
		$property->setAccessible( true );
		return $property->getValue( $gateway );
	}

	/**
	 * Read the gateway's registry names through reflection.
	 *
	 * @param \XNetVN\AgentHubWP\Mcp\ServerGateway $gateway Gateway.
	 * @return array<string>
	 */
	private function registry_names( ServerGateway $gateway ): array {
		return $this->registry( $gateway )->names();
	}

	/**
	 * Read the stored scope of an issued site token.
	 *
	 * @param string $token Wire token.
	 * @return SiteScope
	 */
	private function stored_scope( string $token ): SiteScope {
		global $wpdb;
		$parsed = SiteTokenCodec::parse( $token );
		self::assertNotNull( $parsed );
		$stored = ( new WpdbSiteKeyRepository( $wpdb, new SystemClock(), 1 ) )->find_by_key_id( $parsed->id );
		self::assertNotNull( $stored );
		return $stored->key->scope;
	}

	/** Issue one real site key and return the wire token. */
	private function issue_site_key(): string {
		global $wpdb;
		$codec     = new SiteTokenCodec();
		$generated = $codec->generate();
		$pepper    = ( new PepperStore() )->current();
		$scope     = new SiteScope( RiskTier::Read, array( 'system' ), array( 'xnetvn/get-site-info' ), array(), array(), array(), new DateTimeImmutable( '@' . ( time() + 3600 ) ) );
		$repo      = new WpdbSiteKeyRepository( $wpdb, new SystemClock(), 1 );
		$repo->insert( $generated->id, $scope, 'FR27 fixture', $generated->hash_with( $pepper ), $pepper->id, 1 );
		return $generated->reveal();
	}

	/** Build the fake adapter capturing create_server arguments. */
	private function fake_adapter(): object {
		return new class() {
			/** Captured arguments.
			 *
			 * @var array<mixed>
			 */
			public array $args = array();

			/**
			 * Capture the create_server call.
			 *
			 * @param mixed ...$args Arguments.
			 * @return object
			 */
			public function create_server( mixed ...$args ): object {
				$this->args = $args;
				return new \stdClass();
			}
		};
	}
}
