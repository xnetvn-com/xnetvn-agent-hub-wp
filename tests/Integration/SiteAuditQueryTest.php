<?php
/**
 * Principal-aware audit query contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use XNetVN\AgentHubWP\Auth\Capabilities;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Proves the audit listing answers principal questions and discloses nothing else. */
final class SiteAuditQueryTest extends WP_UnitTestCase {
	/** Management audit path. */
	private const ROUTE = '/xnetvn-agent-hub-wp/v1/audit';

	/** Nonce tied to the current cookie session.
	 *
	 * @var string
	 */
	private string $nonce = '';

	/** Original cookie snapshot.
	 *
	 * @var array<string,mixed>
	 */
	private array $cookies = array();

	/** Prepare the schema used by every audit query. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->cookies = $_COOKIE;
		( new Migrator( $wpdb, new FrozenClock( 1791158400 ) ) )->migrate();
	}

	/** Remove only fixture cookies. */
	public function tear_down(): void {
		$_COOKIE = $this->cookies;
		parent::tear_down();
	}

	/** AC-002: a non-manager cannot probe site activity or the all-rows filter. */
	public function test_editor_site_probes_are_denied(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->login( $editor );
		$probes = array(
			'all rows'       => array( 'all' => true ),
			'site principal' => array( 'principal_type' => 'site' ),
			'site reference' => array( 'credential_ref' => 'xns:abcdefghijkl' ),
		);
		foreach ( $probes as $label => $params ) {
			$response = $this->listing( $params );
			self::assertSame( 403, $response->get_status(), $label );
			self::assertSame( 'xnetvn_agent_hub_wp_management_unavailable', $response->get_data()['code'], $label );
		}
	}

	/** AC-002: a withdrawn management capability is rechecked on the next request. */
	public function test_capability_loss_is_rechecked(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login( $admin );
		self::assertSame( 200, $this->listing( array( 'all' => true ) )->get_status() );
		$withdraw = static function ( array $allcaps ): array {
			unset( $allcaps[ Capabilities::MANAGE ] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $withdraw, 20, 1 );
		try {
			self::assertSame( 403, $this->listing( array( 'all' => true ) )->get_status() );
		} finally {
			remove_filter( 'user_has_cap', $withdraw, 20 );
		}
		self::assertSame( 200, $this->listing( array( 'all' => true ) )->get_status() );
	}

	/**
	 * Dispatch one audit listing request.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @param bool                $nonce Include the REST nonce.
	 * @return WP_REST_Response
	 */
	private function listing( array $params = array(), bool $nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		if ( $nonce ) {
			$request->set_header( 'x-wp-nonce', $this->nonce );
		}
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
		$token                       = \WP_Session_Tokens::get_instance( $user )->create( time() + HOUR_IN_SECONDS );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user, time() + HOUR_IN_SECONDS, 'logged_in', $token );
		$this->nonce                 = wp_create_nonce( 'wp_rest' );
	}
}
