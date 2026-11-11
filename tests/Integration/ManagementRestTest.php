<?php
/**
 * Cookie-only management REST security tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbKeyRepository;
use XNetVN\AgentHubWP\Rest\ManagementGate;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Exercises real route schemas, nonce binding and cross-owner restrictions. */
final class ManagementRestTest extends WP_UnitTestCase {
	/** Current cookie owner.
	 *
	 * @var int
	 */
	private int $owner;
	/** Nonce tied to the owner's cookie session.
	 *
	 * @var string
	 */
	private string $nonce;
	/** Original cookie snapshot.
	 *
	 * @var array<string,mixed>
	 */
	private array $cookies;
	/** Current session owner.
	 *
	 * @var int
	 */
	private int $user;
	/** Current session token.
	 *
	 * @var string
	 */
	private string $token = '';

	/** Prepare a real session and only clear plugin-owned test rows. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->cookies = $_COOKIE;
		( new Migrator( $wpdb, new FrozenClock( time() ) ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
		$this->owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->login( $this->owner );
	}

	/** Remove only fixture cookies and any test authorization header. */
	public function tear_down(): void {
		$_COOKIE = $this->cookies;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	/** Valid cookies and a nonce enable creation; lists never repeat the token. */
	public function test_creation_returns_secret_once_and_listing_is_safe(): void {
		$response = $this->call(
			'POST',
			'/keys',
			array(
				'name'            => 'REST fixture',
				'expires_in_days' => 30,
			)
		);
		self::assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		self::assertMatchesRegularExpression( '/\Axnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}\z/', $data['secret'] );
		$list = $this->call( 'GET', '/keys' );
		self::assertSame( 200, $list->get_status() );
		self::assertCount( 1, $list->get_data() );
		self::assertStringNotContainsString( $data['secret'], wp_json_encode( $list->get_data() ) );
		self::assertArrayNotHasKey( 'secret', $this->call( 'GET', '/keys/' . $data['key']['id'] )->get_data() );
		self::assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/** Missing nonce, missing cookie and Bearer-only identity all fail. */
	public function test_cookie_and_nonce_are_both_required(): void {
		self::assertContains( $this->call( 'GET', '/keys', array(), false )->get_status(), array( 401, 403 ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		self::assertContains( $this->call( 'GET', '/keys' )->get_status(), array( 401, 403 ) );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer xnv_abcdefghijkl_' . str_repeat( 'C', 43 );
		wp_set_current_user( 0 );
		self::assertContains( $this->call( 'GET', '/keys' )->get_status(), array( 401, 403 ) );
	}

	/** Another editor cannot see or revoke the first editor's key. */
	public function test_owner_isolation_and_manager_listing(): void {
		$data = $this->call( 'POST', '/keys', array( 'name' => 'Owner' ) )->get_data();
		$this->login( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertCount( 0, $this->call( 'GET', '/keys' )->get_data() );
		self::assertSame( 404, $this->call( 'GET', '/keys/' . $data['key']['id'] )->get_status() );
		self::assertSame( 404, $this->call( 'DELETE', '/keys/' . $data['key']['id'] )->get_status() );
		$request = $this->request( 'GET', '/keys' );
		$request->set_query_params( array( 'all' => true ) );
		self::assertSame( 403, rest_do_request( $request )->get_status() );
		$this->login( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = $this->request( 'GET', '/keys' );
		$request->set_query_params( array( 'all' => true ) );
		self::assertCount( 1, rest_do_request( $request )->get_data() );
		self::assertSame( 200, $this->call( 'DELETE', '/keys/' . $data['key']['id'] )->get_status() );
	}

	/** PATCH cannot widen; invalid extra fields never reach persistence. */
	public function test_patch_rotation_and_schema_boundaries(): void {
		$data = $this->call(
			'POST',
			'/keys',
			array(
				'name'            => 'Patch',
				'tier_max'        => 'write',
				'expires_in_days' => 30,
			)
		)->get_data();
		$id   = $data['key']['id'];
		self::assertSame(
			200,
			$this->call(
				'PATCH',
				'/keys/' . $id,
				array(
					'tier_max' => 'read',
					'name'     => 'Narrowed',
				)
			)->get_status()
		);
		self::assertSame( 409, $this->call( 'PATCH', '/keys/' . $id, array( 'tier_max' => 'write' ) )->get_status() );
		self::assertSame(
			400,
			$this->call(
				'POST',
				'/keys',
				array(
					'name'    => 'Unknown',
					'user_id' => 1,
				)
			)->get_status()
		);
		self::assertSame(
			400,
			$this->call(
				'POST',
				'/keys',
				array(
					'name'         => 'Unknown',
					'ip_allowlist' => array( 'broken' ),
				)
			)->get_status()
		);
		// This harness wraps the request in a transaction; real committed REST rotation is covered by RotationAtomicityTest and browser E2E.
		self::assertSame( 503, $this->call( 'POST', '/keys/' . $id . '/rotate', array() )->get_status() );
	}

	/** Settings/status require management; header diagnostics return booleans only. */
	public function test_settings_status_and_diagnostics_permissions(): void {
		self::assertSame( 403, $this->call( 'GET', '/settings' )->get_status() );
		self::assertSame( 403, $this->call( 'GET', '/status' )->get_status() );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer CANARY-NEVER-RETURN';
		$diagnostic                    = $this->call( 'GET', '/diagnostics/auth-header' );
		self::assertSame( 200, $diagnostic->get_status() );
		self::assertTrue( $diagnostic->get_data()['authorization_present'] );
		self::assertStringNotContainsString( 'CANARY', wp_json_encode( $diagnostic->get_data() ) );
		$this->login( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertSame( 200, $this->call( 'PUT', '/settings', array( 'tier_ceiling' => 'read' ) )->get_status() );
		self::assertSame( 'read', $this->call( 'GET', '/settings' )->get_data()['tier_ceiling'] );
		self::assertSame( 400, $this->call( 'PUT', '/settings', array( 'unknown_security_field' => true ) )->get_status() );
		self::assertSame( 200, $this->call( 'GET', '/status' )->get_status() );
	}

	/** State, owner and paging filters narrow the listing without leaking secrets. */
	public function test_listing_state_user_and_all_filters(): void {
		$first  = $this->call( 'POST', '/keys', array( 'name' => 'Filter A' ) )->get_data();
		$second = $this->call( 'POST', '/keys', array( 'name' => 'Filter B' ) )->get_data();
		self::assertSame( 200, $this->call( 'DELETE', '/keys/' . $first['key']['id'] )->get_status() );
		$active = $this->listing(
			array(
				'state'    => 'active',
				'per_page' => 1,
			)
		);
		self::assertSame( 200, $active->get_status() );
		self::assertCount( 1, $active->get_data() );
		self::assertSame( 'Filter B', $active->get_data()[0]['name'] );
		self::assertSame( '1', $active->get_headers()['X-WP-Total'] );
		self::assertSame( 'no-store', $active->get_headers()['Cache-Control'] );
		self::assertCount( 1, $this->listing( array( 'state' => 'revoked' ) )->get_data() );
		self::assertCount( 2, $this->listing( array( 'state' => 'all' ) )->get_data() );
		self::assertSame( 400, $this->listing( array( 'state' => 'bogus' ) )->get_status() );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::assertSame( 403, $this->listing( array( 'user_id' => $other ) )->get_status() );
		self::assertSame( 403, $this->listing( array( 'all' => true ) )->get_status() );
		$this->login( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertCount( 2, $this->listing( array( 'all' => true ) )->get_data() );
		self::assertCount( 2, $this->listing( array( 'user_id' => $this->owner ) )->get_data() );
		self::assertCount( 0, $this->listing( array( 'user_id' => $other ) )->get_data() );
		self::assertStringNotContainsString( 'secret', wp_json_encode( $this->listing( array( 'all' => true ) )->get_data() ) );
		self::assertSame( $second['key']['id'], $active->get_data()[0]['id'] );
	}

	/** Destructive tiers demand the current password; rotation windows stay within a day. */
	public function test_high_risk_create_requires_current_password(): void {
		self::assertSame(
			403,
			$this->call(
				'POST',
				'/keys',
				array(
					'name'     => 'No password',
					'tier_max' => 'destructive',
				)
			)->get_status()
		);
		self::assertSame(
			403,
			$this->call(
				'POST',
				'/keys',
				array(
					'name'             => 'Bad password',
					'tier_max'         => 'admin',
					'current_password' => 'wrong-password',
				)
			)->get_status()
		);
		$read = $this->call(
			'POST',
			'/keys',
			array(
				'name'     => 'Patch guard',
				'tier_max' => 'read',
			)
		)->get_data();
		self::assertSame( 400, $this->call( 'PATCH', '/keys/' . $read['key']['id'], array( 'current_password' => 'rejected-field' ) )->get_status() );
		self::assertSame( 400, $this->call( 'POST', '/keys/' . $read['key']['id'] . '/rotate', array( 'grace_hours' => 25 ) )->get_status() );
	}

	/** Subscribers are denied; a lost capability blocks mutations; managers revoke cross-owner. */
	public function test_subscriber_denied_capability_loss_and_manager_revoke(): void {
		$owned = $this->call( 'POST', '/keys', array( 'name' => 'Mine' ) )->get_data();
		$id    = $owned['key']['id'];
		$this->login( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertSame( 403, $this->call( 'GET', '/keys' )->get_status() );
		self::assertSame( 403, $this->call( 'POST', '/keys', array( 'name' => 'Denied' ) )->get_status() );
		// The owner loses the delegated capability before the session is rebuilt, exactly like a later request.
		get_userdata( $this->owner )->set_role( 'subscriber' );
		$this->login( $this->owner );
		self::assertSame( 403, $this->call( 'GET', '/keys' )->get_status() );
		self::assertSame( 403, $this->call( 'POST', '/keys', array( 'name' => 'Lost capability' ) )->get_status() );
		// Revoking an own key stays available to its owner by design (KeyService::revoke);
		// the capability-gated operations above are the ones that must be denied.
		self::assertSame( 200, $this->call( 'DELETE', '/keys/' . $id )->get_status() );
		$this->login( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertSame( 404, $this->call( 'POST', '/keys/' . $id . '/rotate', array() )->get_status() );
		$this->login( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertSame( 200, $this->call( 'DELETE', '/keys/' . $id )->get_status() );
	}

	/**
	 * Dispatch a nonce-bearing collection read with explicit filters.
	 *
	 * @param array<string,mixed> $params Listing filters.
	 * @return \WP_REST_Response
	 */
	private function listing( array $params ): \WP_REST_Response {
		$request = $this->request( 'GET', '/keys' );
		$request->set_query_params( $params );
		return rest_do_request( $request );
	}
	/**
	 * Set a real logged-in cookie and create its REST nonce.
	 *
	 * @param int $user Owner.
	 */
	private function login( int $user ): void {
		wp_set_current_user( $user );
		$this->user                  = $user;
		$this->token                 = \WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'logged_in', $this->token );
		$this->nonce                 = wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Build a nonce-bearing route request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Relative management path.
	 * @param bool   $nonce Include a nonce.
	 * @return WP_REST_Request
	 */
	private function request( string $method, string $path, bool $nonce = true ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/xnetvn-agent-hub-wp/v1' . $path );
		if ( $nonce ) {
			$request->set_header( 'x-wp-nonce', $this->nonce );
		}
		return $request;
	}

	/**
	 * Dispatch JSON without making an external HTTP call.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path Path.
	 * @param array<string,mixed> $body Fields.
	 * @param bool                $nonce Include nonce.
	 * @return \WP_REST_Response
	 */
	private function call( string $method, string $path, array $body = array(), bool $nonce = true ): \WP_REST_Response {
		$request = $this->request( $method, $path, $nonce );
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( (object) $body ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Build a direct gate request with an optional header and parameter source.
	 *
	 * @param array<string,mixed> $headers Header values.
	 * @param array<string,mixed> $params Parameter values.
	 * @return WP_REST_Request
	 */
	private function gate_request( array $headers = array(), array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/xnetvn-agent-hub-wp/v1/keys' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		return $request;
	}

	/**
	 * Run the gate directly without core HTTP authentication.
	 *
	 * @param array<string,mixed> $headers Header values.
	 * @param array<string,mixed> $params Parameter values.
	 * @return true|\WP_Error
	 */
	private function gate( array $headers = array(), array $params = array() ): true|\WP_Error {
		return ( new ManagementGate() )->check( $this->gate_request( $headers, $params ) );
	}

	/**
	 * Assert the existing nonce denial contract.
	 *
	 * @param true|\WP_Error $result Gate result.
	 */
	private function assert_nonce_denied( true|\WP_Error $result ): void {
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_nonce_required', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Assert the existing cookie denial contract.
	 *
	 * @param true|\WP_Error $result Gate result.
	 */
	private function assert_cookie_denied( true|\WP_Error $result ): void {
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_cookie_required', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Construct the installed verifier's previous-tick nonce without changing time.
	 *
	 * @param int $tick Nonce tick.
	 * @return string
	 */
	private function nonce_for_tick( int $tick ): string {
		return substr( wp_hash( $tick . '|wp_rest|' . $this->user . '|' . $this->token, 'nonce' ), -12, 10 );
	}

	/** Return a stable age-two nonce while accounting for a 12-hour tick rollover. */
	private function age_two_nonce(): string {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$tick      = (int) wp_nonce_tick();
			$candidate = $this->nonce_for_tick( $tick - 1 );
			$verified  = wp_verify_nonce( $candidate, 'wp_rest' );
			if ( 2 === $verified && (int) wp_nonce_tick() === $tick ) {
				return $candidate;
			}
		}
		self::fail( 'Could not construct a stable prior-tick nonce fixture.' );
		return '';
	}

	/** Return a stable age-one nonce while accounting for a tick rollover. */
	private function age_one_nonce(): string {
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$tick      = (int) wp_nonce_tick();
			$candidate = wp_create_nonce( 'wp_rest' );
			$verified  = wp_verify_nonce( $candidate, 'wp_rest' );
			if ( 1 === $verified && (int) wp_nonce_tick() === $tick ) {
				return $candidate;
			}
		}
		self::fail( 'Could not construct a stable current-tick nonce fixture.' );
		return '';
	}

	/** Count keys without trusting a route response alone. */
	private function key_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_keys' ) );
	}

	/** NNC-G01/G02/G03/G13: valid source precedence and both verifier ages. */
	public function test_gate_accepts_valid_header_and_parameter_nonces(): void {
		self::assertTrue( $this->gate( array( 'x-wp-nonce' => $this->age_one_nonce() ) ) );
		self::assertTrue( $this->gate( array( 'x-wp-nonce' => $this->age_two_nonce() ) ) );
		self::assertTrue( $this->gate( array(), array( '_wpnonce' => $this->nonce ) ) );
		self::assertTrue( $this->gate( array( 'x-wp-nonce' => $this->nonce ), array( '_wpnonce' => 'invalid' ) ) );
	}

	/** NNC-G04/G05/G06/G07/G10/G14: malformed sources never fall back or coerce. */
	public function test_gate_rejects_missing_or_malformed_sources_without_fallback(): void {
		$valid = $this->nonce;
		$cases = array(
			'G04 missing'                     => array( array(), array() ),
			'G04 null'                        => array( array(), array( '_wpnonce' => null ) ),
			'G04 empty'                       => array( array(), array( '_wpnonce' => '' ) ),
			'G05 empty header'                => array( array( 'x-wp-nonce' => '' ), array( '_wpnonce' => $valid ) ),
			'G05 invalid header'              => array( array( 'x-wp-nonce' => 'invalid' ), array( '_wpnonce' => $valid ) ),
			'G06 duplicate values'            => array( array( 'x-wp-nonce' => array( $valid, $valid ) ), array() ),
			'G07 non-string member'           => array( array( 'x-wp-nonce' => array( 123 ) ), array() ),
			'G07 null member'                 => array( array( 'x-wp-nonce' => array( null ) ), array() ),
			'G10 invalid unchanged candidate' => array( array( 'x-wp-nonce' => '\\' . $valid ), array() ),
			'G14 invalid header, valid param' => array( array( 'x-wp-nonce' => 'invalid' ), array( '_wpnonce' => $valid ) ),
		);
		foreach ( $cases as $label => $case ) {
			$this->assert_nonce_denied( $this->gate( $case[0], $case[1] ) );
		}
		$request = new class() extends \WP_REST_Request {
			/**
			 * Return a scalar where the gate expects an array.
			 *
			 * `WP_REST_Request` always returns array|null, so the gate's
			 * defensive non-array branch can only be reached with a double.
			 *
			 * @param string $key Header name.
			 * @return string
			 */
			public function get_header_as_array( $key ) {
				unset( $key );
				return 'not-an-array';
			}
		};
		$this->assert_nonce_denied( ( new ManagementGate() )->check( $request ) );
	}

	/** NNC-G08: parameter values are rejected before sanitization or verification. */
	public function test_gate_rejects_non_string_parameter_values(): void {
		foreach ( array( array( $this->nonce ), (object) array( 'nonce' => $this->nonce ), true, 123, 1.5 ) as $value ) {
			$this->assert_nonce_denied( $this->gate( array(), array( '_wpnonce' => $value ) ) );
		}
	}

	/** NNC-G09: a sanitizer-changed candidate is rejected, not repaired. */
	public function test_gate_rejects_sanitizer_changed_candidates(): void {
		$valid      = $this->nonce;
		$candidates = array( ' ' . $valid, $valid . ' ', '<b>' . $valid . '</b>', "\t" . $valid, '%41' . $valid );
		foreach ( $candidates as $candidate ) {
			self::assertNotSame( $candidate, sanitize_text_field( $candidate ) );
			$this->assert_nonce_denied( $this->gate( array( 'x-wp-nonce' => $candidate ) ) );
		}
	}

	/** NNC-G11/G12: sanitizer and verifier failures are denial, not disclosure. */
	public function test_gate_fails_closed_on_sanitizer_and_verifier_exceptions(): void {
		$candidate = $this->nonce;
		$filter    = static function ( $filtered, $text ) use ( $candidate ) {
			return $text === $candidate ? 123 : $filtered;
		};
		add_filter( 'sanitize_text_field', $filter, 10, 2 );
		try {
			$this->assert_nonce_denied( $this->gate( array( 'x-wp-nonce' => $candidate ) ) );
		} finally {
			remove_filter( 'sanitize_text_field', $filter, 10 );
		}

		$throw = static function ( $filtered, $text ) use ( $candidate ) {
			if ( $text === $candidate ) {
				throw new \RuntimeException( 'sanitizer canary' );
			}
			return $filtered;
		};
		add_filter( 'sanitize_text_field', $throw, 10, 2 );
		try {
			$result = $this->gate( array( 'x-wp-nonce' => $candidate ) );
			$this->assert_nonce_denied( $result );
			self::assertStringNotContainsString( 'sanitizer canary', $result->get_error_message() );
		} finally {
			remove_filter( 'sanitize_text_field', $throw, 10 );
		}

		$invalid = $this->nonce . 'x';
		self::assertSame( $invalid, sanitize_text_field( $invalid ) );
		$agent = $this->user;
		$fired = false;
		$fail  = static function ( $nonce, $action, $user ) use ( $invalid, $agent, &$fired ): void {
			// `wp_verify_nonce_failed` passes the current user object, not an ID.
			if ( $nonce === $invalid && 'wp_rest' === $action && $user instanceof \WP_User && (int) $user->ID === $agent ) {
				$fired = true;
				throw new \RuntimeException( 'verifier canary' );
			}
		};
		add_action( 'wp_verify_nonce_failed', $fail, 10, 3 );
		try {
			$result = $this->gate( array( 'x-wp-nonce' => $invalid ) );
			$this->assert_nonce_denied( $result );
			self::assertTrue( $fired, 'The verifier failure hook must fire for an owned invalid nonce.' );
			self::assertStringNotContainsString( 'verifier canary', $result->get_error_message() );
		} finally {
			remove_action( 'wp_verify_nonce_failed', $fail, 10 );
		}
	}

	/** NNC-G15: cookie identity is checked before the nonce. */
	public function test_gate_cookie_boundary_precedes_nonce(): void {
		self::assertTrue( $this->gate( array( 'x-wp-nonce' => $this->nonce ) ) );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$this->assert_cookie_denied( $this->gate( array( 'x-wp-nonce' => $this->nonce ) ) );
		$this->login( $this->owner );
		wp_set_current_user( 0 );
		$this->assert_cookie_denied( $this->gate( array( 'x-wp-nonce' => $this->nonce ) ) );
		$this->login( $this->owner );
	}

	/** NNC-G16: controller capability and owner boundaries still deny and do not mutate. */
	public function test_gate_preserves_controller_owner_and_capability_denials(): void {
		$before = $this->key_count();
		$data   = $this->call( 'POST', '/keys', array( 'name' => 'Gate owner' ) )->get_data();
		$id     = $data['key']['id'];
		$after  = $this->key_count();
		self::assertSame( $before + 1, $after );
		$this->login( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		self::assertSame( 404, $this->call( 'GET', '/keys/' . $id )->get_status() );
		self::assertSame( 404, $this->call( 'DELETE', '/keys/' . $id )->get_status() );
		self::assertSame( $after, $this->key_count() );
	}
}
