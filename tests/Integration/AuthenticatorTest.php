<?php
/**
 * Credential identity and current-owner lifecycle tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Authenticator;
use XNetVN\AgentHubWP\Auth\HeaderExtractor;
use XNetVN\AgentHubWP\Auth\KeyHasher;
use XNetVN\AgentHubWP\Auth\PepperStore;
use XNetVN\AgentHubWP\Domain\KeyId;
use XNetVN\AgentHubWP\Domain\RiskTier;
use XNetVN\AgentHubWP\Domain\Scope;
use XNetVN\AgentHubWP\Http\RequestScope;
use XNetVN\AgentHubWP\Http\TransportGuard;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Persistence\WpSiteSettings;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Checks auth independently of the adapter's protocol layer. */
final class AuthenticatorTest extends WP_UnitTestCase {
	/** Original server values.
	 *
	 * @var array<mixed>
	 */
	private array $server;
	/** Current request context.
	 *
	 * @var RequestScope
	 */
	private RequestScope $request;
	/** Credential service.
	 *
	 * @var Authenticator
	 */
	private Authenticator $auth;
	/** Fixture owner.
	 *
	 * @var int
	 */
	private int $owner;
	/** Fixture record.
	 *
	 * @var int
	 */
	private int $key;
	/** Repository.
	 *
	 * @var WpdbKeyRepository
	 */
	private WpdbKeyRepository $keys;

	/** Prepare a local TLS request and a non-secret indexed credential fixture. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->server                  = $_SERVER;
		$_SERVER['HTTPS']              = 'on';
		$_SERVER['REMOTE_ADDR']        = '203.0.113.' . wp_rand( 1, 254 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'A', 43 );
		$clock                         = new FrozenClock( 1791072000 );
		$this->request                 = new RequestScope();
		$settings                      = new WpSiteSettings();
		$peppers                       = new PepperStore();
		$this->keys                    = new WpdbKeyRepository( $wpdb, $clock );
		$this->owner                   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->key                     = $this->keys->insert( new KeyId( 'abcdefghijkl' ), $this->owner, 'Auth fixture', new Scope( RiskTier::Read, array(), array(), array(), array( '203.0.113.0/24' ) ), KeyHasher::hash( str_repeat( 'A', 43 ), $peppers->current()->reveal() ), 1 )->id;
		$this->auth                    = new Authenticator( $this->keys, $peppers, $settings, new HeaderExtractor( $settings ), new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock ), new TransportGuard( $settings ), $this->request, $clock );
	}

	/** Restore global request data. */
	public function tear_down(): void {
		$_SERVER = $this->server;
		$this->request->reset();
		parent::tear_down();
	}

	/** Valid credentials establish their owner rather than the current cookie actor. */
	public function test_valid_key_uses_its_owner(): void {
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		self::assertSame( $this->owner, $this->auth->authenticate() );
		self::assertSame( $this->owner, $this->request->context->user_id );
		self::assertSame( $this->owner, $this->auth->authenticate() );
	}

	/** Revocation invalidates the next fresh request. */
	public function test_revoked_key(): void {
		$this->keys->revoke( $this->key, $this->owner, 'owner_request' );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'revoked', $this->request->failure->value );
	}

	/** A role loss is temporary and restoration does not require a new key. */
	public function test_owner_role_loss_and_recovery(): void {
		$user = get_userdata( $this->owner );
		$user->set_role( 'subscriber' );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'owner_forbidden', $this->request->failure->value );
		$user->set_role( 'editor' );
		$this->request->reset();
		self::assertSame( $this->owner, $this->auth->authenticate() );
		self::assertFalse( $this->keys->find_by_id( $this->key )->is_revoked() );
	}

	/** A wrong secret does not expose whether a lookup ID exists. */
	public function test_bad_secret(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'B', 43 );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'bad_secret', $this->request->failure->value );
	}
	/** A well-formed nonexistent identifier cannot reuse a current cookie actor. */
	public function test_unknown_identifier_has_no_authenticated_context(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_aaaaaaaaaaaa_' . str_repeat( 'A', 43 );
		wp_set_current_user( $this->owner );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'unknown_key', $this->request->failure->value );
		self::assertNull( $this->request->context );
	}
	/** Corrupt pepper storage denies access instead of recreating material or accepting a cookie. */
	public function test_corrupt_pepper_storage_fails_closed(): void {
		$original = get_option( PepperStore::OPTION );
		try {
			foreach ( array(
				'corrupt',
				array(
					'id'    => 1,
					'value' => 'not-valid-base64!',
				),
				array(
					'id'    => 1,
					'value' => 'c2hvcnQ=',
				),
			) as $invalid ) {
				update_option( PepperStore::OPTION, $invalid, false );
				$this->request->reset();
				wp_set_current_user( $this->owner );
				self::assertSame( 0, $this->auth->authenticate() );
				self::assertSame( 'unavailable', $this->request->failure->value );
				self::assertSame( 503, $this->request->failure->status() );
				self::assertNull( $this->request->context );
				self::assertSame( $invalid, get_option( PepperStore::OPTION ) );
			}
		} finally {
			update_option( PepperStore::OPTION, $original, false );
		}
	}

	/** IP restrictions are independent of a correct secret. */
	public function test_ip_denied(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.5';
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'ip_denied', $this->request->failure->value );
	}

	/** Cookie and query-string values cannot substitute for a header credential. */
	public function test_missing_header(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		wp_set_current_user( $this->owner );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertNull( $this->request->context );
		self::assertSame( 'missing', $this->request->failure->value );
	}

	/** Expiry denies a previously valid credential. */
	public function test_expired_key(): void {
		$this->keys->update_scope( $this->key, new Scope( RiskTier::Read, array(), array(), array(), array( '203.0.113.0/24' ), new \DateTimeImmutable( '@1791071999' ) ) );
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'expired', $this->request->failure->value );
	}

	/** A deleted owner is denied and its credentials are revoked. */
	public function test_deleted_owner(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_multisite() ) {
			wpmu_delete_user( $this->owner );
		} else {
			wp_delete_user( $this->owner );
		}
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'owner_missing', $this->request->failure->value );
		self::assertTrue( $this->keys->find_by_id( $this->key )->is_revoked() );
	}

	/** The explicitly enabled fallback still requires a Bearer scheme. */
	public function test_last_use_metadata_honors_privacy_and_redaction(): void {
		global $wpdb;
		( new WpSiteSettings() )->update( array( 'anonymize_ip' => true ) );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.79';
		$_SERVER['HTTP_USER_AGENT'] = 'Client Bearer CANARY-SECRET';
		self::assertSame( $this->owner, $this->auth->authenticate() );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT last_used_ip,last_used_ua FROM %i WHERE id = %d', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys', $this->key ), ARRAY_A );
		self::assertSame( '203.0.113.0', $row['last_used_ip'] );
		self::assertStringNotContainsString( 'CANARY-SECRET', $row['last_used_ua'] );
	}

	/** The explicitly enabled fallback still requires a Bearer scheme. */
	public function test_fallback_header(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_X_XNETVN_API_KEY'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'A', 43 );
		self::assertSame( $this->owner, $this->auth->authenticate() );
	}

	/** Failed-auth attempts reach429 after the exact configured budget. */
	public function test_failed_auth_budget(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid';
		for ( $i = 0; $i < 10; ++$i ) {
			$this->request->reset();
			self::assertSame( 0, $this->auth->authenticate() );
			self::assertSame( 'malformed', $this->request->failure->value );
		}
		$this->request->reset();
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'rate_limited', $this->request->failure->value );
		self::assertGreaterThan( 0, $this->request->budget->retry_after );
	}

	/** Missing and invalid credentials retain safe caching headers and distinct challenge syntax. */
	public function test_challenge_headers_never_include_credentials(): void {
		$request = new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/mcp' );
		foreach ( array( false, true ) as $present ) {
			$this->request->reset();
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			if ( $present ) {
				$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'B', 43 );
			}
			self::assertSame( 0, $this->auth->authenticate() );
			$response = $this->auth->response_headers( new \WP_REST_Response( array(), 401 ), rest_get_server(), $request );
			$headers  = array_change_key_case( $response->get_headers(), CASE_LOWER );
			self::assertSame( 'no-store', $headers['cache-control'] );
			self::assertSame( 'nosniff', $headers['x-content-type-options'] );
			self::assertSame( 'Bearer realm="xnetvn-agent-hub-wp"' . ( $present ? ', error="invalid_token"' : '' ), $headers['www-authenticate'] );
			self::assertStringNotContainsString( 'xnv_', $headers['www-authenticate'] );
		}
	}

	/** A real exhausted bucket emits the conservative measured retry delay. */
	public function test_rate_limit_response_uses_measured_retry(): void {
		( new WpSiteSettings() )->update( array( 'rate_limit_key_per_min' => 1 ) );
		self::assertSame( $this->owner, $this->auth->authenticate() );
		$this->request->reset();
		self::assertSame( 0, $this->auth->authenticate() );
		self::assertSame( 'rate_limited', $this->request->failure->value );
		$response = $this->auth->response_headers( new \WP_REST_Response( array(), 429 ), rest_get_server(), new \WP_REST_Request( 'POST', '/xnetvn-agent-hub-wp/mcp' ) );
		$headers  = array_change_key_case( $response->get_headers(), CASE_LOWER );
		self::assertSame( (string) $this->request->budget->retry_after, $headers['retry-after'] );
		self::assertGreaterThan( 0, (int) $headers['retry-after'] );
	}

	/** Management/core responses do not inherit MCP challenges or rate headers. */
	public function test_other_route_headers_are_untouched(): void {
		$response = new \WP_REST_Response( array( 'ok' => true ), 401 );
		$response->header( 'X-Fixture', 'preserved' );
		$result = $this->auth->response_headers( $response, rest_get_server(), new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		self::assertSame( $response, $result );
		self::assertSame( array( 'X-Fixture' => 'preserved' ), $result->get_headers() );
	}
}
