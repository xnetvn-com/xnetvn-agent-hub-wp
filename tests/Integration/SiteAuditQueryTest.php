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
use XNetVN\AgentHubWP\Contracts\AuditRepository;
use XNetVN\AgentHubWP\Persistence\Migrator;
use XNetVN\AgentHubWP\Persistence\WpdbAuditRepository;
use XNetVN\AgentHubWP\Rest\AuditController;
use XNetVN\AgentHubWP\Rest\ManagementGate;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;

/** Proves the audit listing answers principal questions and discloses nothing else. */
final class SiteAuditQueryTest extends WP_UnitTestCase {
	/** Fixed canary stored in three columns that must never reach the wire. */
	private const CANARY = 'CANARY-FR29-VALUE';

	/** Management audit path. */
	private const ROUTE = '/xnetvn-agent-hub-wp/v1/audit';

	/** Exact reviewed wire fields, in projection order. */
	private const ITEM_FIELDS = array( 'occurred_at', 'user_id', 'ability', 'outcome', 'transport', 'principal_type', 'blog_id', 'credential_ref' );

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

	/** Persistence under test.
	 *
	 * @var WpdbAuditRepository
	 */
	private WpdbAuditRepository $audit;

	/** Prepare the schema and clear only this site's plugin audit rows. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->cookies = $_COOKIE;
		( new Migrator( $wpdb, new FrozenClock( 1791158400 ) ) )->migrate();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
		$this->audit = new WpdbAuditRepository( $wpdb );
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

	/** AC-001: the manager separates kinds and references with exact types and no extra field. */
	public function test_manager_principal_filters_and_wire_contract(): void {
		$fixture = $this->seed_fixture();
		$this->login( $fixture['admin'] );

		$site = $this->listing( array( 'principal_type' => 'site' ) );
		self::assertSame( 200, $site->get_status() );
		self::assertSame( 'no-store', $site->get_headers()['Cache-Control'] );
		$page = $site->get_data();
		self::assertSame( array( 'items', 'total', 'page', 'per_page' ), array_keys( $page ) );
		self::assertSame( 1, $page['total'] );
		self::assertCount( 1, $page['items'] );
		$item = $page['items'][0];
		self::assertSame( self::ITEM_FIELDS, array_keys( $item ) );
		self::assertSame( 'site', $item['principal_type'] );
		self::assertNull( $item['user_id'] );
		self::assertIsInt( $item['blog_id'] );
		self::assertSame( max( 1, (int) get_current_blog_id() ), $item['blog_id'] );
		self::assertSame( 'xns:abcdefghijkl', $item['credential_ref'] );
		self::assertIsString( $item['occurred_at'] );
		self::assertIsString( $item['outcome'] );
		self::assertIsString( $item['transport'] );
		self::assertTrue( null === $item['ability'] || is_string( $item['ability'] ) );

		$reference = $this->listing( array( 'credential_ref' => 'xns:abcdefghijkl' ) );
		self::assertSame( 200, $reference->get_status() );
		self::assertSame( 1, $reference->get_data()['total'] );
		self::assertSame( $page['items'], $reference->get_data()['items'] );

		$human = $this->listing( array( 'key_id' => 'abcdefghijkl' ) );
		self::assertSame( 200, $human->get_status() );
		self::assertSame( 1, $human->get_data()['total'] );
		self::assertSame( 'user', $human->get_data()['items'][0]['principal_type'] );
		self::assertSame( $fixture['admin'], $human->get_data()['items'][0]['user_id'] );
		self::assertSame( 'xnv:abcdefghijkl', $human->get_data()['items'][0]['credential_ref'] );

		$human_all = $this->listing(
			array(
				'key_id' => 'abcdefghijkl',
				'all'    => true,
			)
		);
		self::assertSame( 200, $human_all->get_status() );
		self::assertSame( 1, $human_all->get_data()['total'] );
		self::assertSame( 'user', $human_all->get_data()['items'][0]['principal_type'] );

		$all = $this->listing( array( 'all' => true ) );
		self::assertSame( 200, $all->get_status() );
		self::assertSame( 4, $all->get_data()['total'] );
		self::assertSame( array( 'xnv:bcdefghijklm', null, 'xns:abcdefghijkl', 'xnv:abcdefghijkl' ), array_column( $all->get_data()['items'], 'credential_ref' ) );
		foreach ( $all->get_data()['items'] as $row ) {
			self::assertContains( $row['principal_type'], array( 'user', 'site' ) );
			self::assertTrue( null === $row['user_id'] || is_int( $row['user_id'] ) );
			self::assertIsInt( $row['blog_id'] );
			self::assertTrue( null === $row['credential_ref'] || is_string( $row['credential_ref'] ) );
		}
		self::assertStringNotContainsString( self::CANARY, (string) wp_json_encode( $all->get_data() ) );
		foreach ( array( 'key_id', 'request_id', 'ip', 'ua_short', 'arg_keys' ) as $column ) {
			self::assertArrayNotHasKey( $column, $all->get_data()['items'][0] );
		}

		$human_only = $this->listing(
			array(
				'principal_type' => 'user',
				'all'            => true,
			)
		);
		self::assertSame( 3, $human_only->get_data()['total'] );
		self::assertSame( array( 'xnv:bcdefghijklm', null, 'xnv:abcdefghijkl' ), array_column( $human_only->get_data()['items'], 'credential_ref' ) );

		$other = $this->listing( array( 'user_id' => $fixture['other'] ) );
		self::assertSame( 1, $other->get_data()['total'] );
		self::assertSame( $fixture['other'], $other->get_data()['items'][0]['user_id'] );

		$consistent = $this->listing(
			array(
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xnv:abcdefghijkl',
			)
		);
		self::assertSame( 200, $consistent->get_status() );
		self::assertSame( 1, $consistent->get_data()['total'] );
		self::assertSame( 'xnv:abcdefghijkl', $consistent->get_data()['items'][0]['credential_ref'] );

		$cookie_less = $_COOKIE;
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		try {
			self::assertSame( 401, $this->listing( array(), false )->get_status() );
		} finally {
			$_COOKIE = $cookie_less;
		}
	}

	/** AC-001: paging reports the honest total, a bounded item and an empty page past the end. */
	public function test_manager_pagination_is_honest(): void {
		$fixture = $this->seed_fixture();
		$this->login( $fixture['admin'] );
		$paged = $this->listing(
			array(
				'all'      => true,
				'per_page' => 1,
				'page'     => 2,
			)
		);
		self::assertSame( 200, $paged->get_status() );
		self::assertIsInt( $paged->get_data()['total'] );
		self::assertSame( 4, $paged->get_data()['total'] );
		self::assertSame( 2, $paged->get_data()['page'] );
		self::assertSame( 1, $paged->get_data()['per_page'] );
		self::assertCount( 1, $paged->get_data()['items'] );
		$beyond = $this->listing(
			array(
				'all'  => true,
				'page' => 99,
			)
		);
		self::assertSame( 200, $beyond->get_status() );
		self::assertSame( 4, $beyond->get_data()['total'] );
		self::assertSame( 99, $beyond->get_data()['page'] );
		self::assertSame( array(), $beyond->get_data()['items'] );
	}

	/** AC-001: every contradictory pair fails closed before any repository call. */
	public function test_conflicting_filters_are_rejected(): void {
		$fixture = $this->seed_fixture();
		$this->login( $fixture['admin'] );
		$before = $this->audit_row_count();
		$cases  = $this->conflict_cases( $fixture['admin'] );
		foreach ( $cases as $label => $params ) {
			$response = $this->listing( $params );
			self::assertSame( 400, $response->get_status(), $label );
			self::assertSame( 'rest_invalid_param', $response->get_data()['code'], $label );
			self::assertSame( 'The requested audit filters conflict.', $response->get_data()['message'], $label );
			self::assertSame( 'no-store', $response->get_headers()['Cache-Control'], $label );
		}
		self::assertSame( $before, $this->audit_row_count() );

		$double     = new class() implements AuditRepository {
			/** Recorded query calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $calls = array();

			/**
			 * Accept writes without storing them.
			 *
			 * @param array<array<string,mixed>> $events Events.
			 * @return int
			 */
			public function insert_batch( array $events ): int {
				return 0;
			}

			/**
			 * Record the call and return a fixed empty page.
			 *
			 * @param array<string,mixed> $filter Filters.
			 * @param int                 $page Page.
			 * @return array{items:array<array<string,mixed>>,total:int,page:int,per_page:int}
			 */
			public function query( array $filter = array(), int $page = 1 ): array {
				$this->calls[] = array(
					'filter' => $filter,
					'page'   => $page,
				);
				return array(
					'items'    => array(),
					'total'    => 0,
					'page'     => 1,
					'per_page' => 1,
				);
			}

			/**
			 * Accept purges without deleting anything.
			 *
			 * @param string $cutoff UTC cutoff.
			 * @param int    $limit Batch size.
			 * @return int
			 */
			public function purge_older_than( string $cutoff, int $limit ): int {
				return 0;
			}
		};
		$controller = new AuditController( new ManagementGate(), $double );
		foreach ( $cases as $label => $params ) {
			$request = new WP_REST_Request( 'GET', self::ROUTE );
			foreach ( $params as $name => $value ) {
				$request->set_param( $name, $value );
			}
			$error = $controller->get_items( $request );
			self::assertWPError( $error, $label );
			self::assertSame( 'rest_invalid_param', $error->get_error_code(), $label );
			self::assertSame( 'The requested audit filters conflict.', $error->get_error_message(), $label );
			self::assertSame( 400, $error->get_error_data()['status'], $label );
		}
		self::assertSame( array(), $double->calls );
	}

	/** AC-002: self scope, foreign reference parity and schema refusals for an ordinary user. */
	public function test_editor_scope_syntax_and_foreign_reference(): void {
		$fixture = $this->seed_fixture();
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->insert(
			array(
				'user_id'        => $editor,
				'key_id'         => 'lmnopqrstuvw',
				'credential_ref' => 'xnv:lmnopqrstuvw',
			)
		);
		$this->login( $editor );
		$before = $this->audit_row_count();
		$own    = $this->listing();
		self::assertSame( 200, $own->get_status() );
		self::assertSame( 1, $own->get_data()['total'] );
		self::assertSame( $editor, $own->get_data()['items'][0]['user_id'] );
		self::assertSame( 'user', $own->get_data()['items'][0]['principal_type'] );
		self::assertSame( 'xnv:lmnopqrstuvw', $own->get_data()['items'][0]['credential_ref'] );

		$declared = $this->listing( array( 'principal_type' => 'user' ) );
		self::assertSame( 200, $declared->get_status() );
		self::assertSame( 1, $declared->get_data()['total'] );
		self::assertSame( $editor, $declared->get_data()['items'][0]['user_id'] );

		$foreign = $this->listing( array( 'credential_ref' => 'xnv:abcdefghijkl' ) );
		$missing = $this->listing( array( 'credential_ref' => 'xnv:zzzzzzzzzzzz' ) );
		self::assertSame( 200, $foreign->get_status() );
		self::assertSame( 0, $foreign->get_data()['total'] );
		self::assertSame( $foreign->get_data(), $missing->get_data() );

		$invalid = array(
			'malformed reference' => array( 'credential_ref' => 'invalid' ),
			'unknown principal'   => array( 'principal_type' => 'anonymous' ),
			'uppercase key id'    => array( 'key_id' => 'ABCDEFGHIJKL' ),
		);
		foreach ( $invalid as $label => $params ) {
			self::assertSame( 400, $this->listing( $params )->get_status(), $label );
		}

		$contradictory = $this->listing(
			array(
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xnv:bcdefghijklm',
			)
		);
		self::assertSame( 400, $contradictory->get_status() );

		$inert = $this->listing(
			array(
				'orderby' => 'id',
				'columns' => '*',
				'where'   => '1',
			)
		);
		self::assertSame( 200, $inert->get_status() );
		self::assertSame( 1, $inert->get_data()['total'] );
		self::assertSame( self::ITEM_FIELDS, array_keys( $inert->get_data()['items'][0] ) );

		$foreign_owner = $this->listing( array( 'user_id' => $fixture['admin'] ) );
		self::assertSame( 403, $foreign_owner->get_status() );
		self::assertSame( 'xnetvn_agent_hub_wp_management_unavailable', $foreign_owner->get_data()['code'] );
		self::assertSame( $before, $this->audit_row_count() );
	}

	/** AC-002: paging bounds and the read-only method surface never change stored rows. */
	public function test_bounds_and_read_only_surface(): void {
		$fixture = $this->seed_fixture();
		$this->login( $fixture['admin'] );
		$before = $this->audit_row_count();
		$full   = $this->listing(
			array(
				'all'      => true,
				'per_page' => 100,
			)
		);
		self::assertSame( 200, $full->get_status() );
		self::assertSame( 4, $full->get_data()['total'] );
		self::assertSame( 100, $full->get_data()['per_page'] );
		foreach ( array( array( 'per_page' => 101 ), array( 'page' => 0 ) ) as $params ) {
			self::assertSame( 400, $this->listing( $params )->get_status() );
		}
		$post = new WP_REST_Request( 'POST', self::ROUTE );
		$post->set_header( 'x-wp-nonce', $this->nonce );
		$response = rest_do_request( $post );
		self::assertSame( 404, $response->get_status() );
		self::assertSame( 'rest_no_route', $response->get_data()['code'] );
		self::assertSame( $before, $this->audit_row_count() );
	}

	/** AC-003: another site's machines and a foreign blog tag never enter this site's activity. */
	public function test_multisite_blog_isolation(): void {
		$this->skipWithoutMultisite();
		global $wpdb;
		$first  = max( 1, (int) get_current_blog_id() );
		$second = self::factory()->blog->create();
		$owner  = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->insert(
			array(
				'principal_type' => 'site',
				'credential_ref' => 'xns:abcdefghijkl',
				'blog_id'        => $first,
			)
		);
		$this->insert(
			array(
				'user_id'        => $owner,
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xnv:abcdefghijkl',
				'blog_id'        => $second,
			)
		);
		switch_to_blog( $second );
		try {
			( new Migrator( $wpdb, new FrozenClock( 1791158400 ) ) )->migrate();
			( new WpdbAuditRepository( $wpdb ) )->insert_batch(
				array(
					array_merge(
						$this->row(),
						array(
							'principal_type' => 'site',
							'credential_ref' => 'xns:bcdefghijklm',
							'blog_id'        => max( 1, (int) get_current_blog_id() ),
						)
					),
				)
			);
		} finally {
			restore_current_blog();
		}
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login( $admin );

		$all = $this->listing( array( 'all' => true ) );
		self::assertSame( 200, $all->get_status() );
		self::assertSame( 1, $all->get_data()['total'] );
		self::assertSame( 'xns:abcdefghijkl', $all->get_data()['items'][0]['credential_ref'] );
		self::assertSame( $first, $all->get_data()['items'][0]['blog_id'] );

		$site = $this->listing( array( 'principal_type' => 'site' ) );
		self::assertSame( 1, $site->get_data()['total'] );
		self::assertSame( $all->get_data()['items'], $site->get_data()['items'] );

		$other = $this->listing( array( 'credential_ref' => 'xns:bcdefghijklm' ) );
		self::assertSame( 0, $other->get_data()['total'] );

		$ignored = $this->listing(
			array(
				'principal_type' => 'site',
				'blog_id'        => $second,
			)
		);
		self::assertSame( 200, $ignored->get_status() );
		self::assertSame( $site->get_data(), $ignored->get_data() );
	}

	/**
	 * Seed the four current-site rows of the manager contract.
	 *
	 * @return array{admin:int,other:int}
	 */
	private function seed_fixture(): array {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->insert(
			array(
				'user_id'        => $admin,
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xnv:abcdefghijkl',
			)
		);
		$this->insert(
			array(
				'principal_type' => 'site',
				'credential_ref' => 'xns:abcdefghijkl',
				'blog_id'        => max( 1, (int) get_current_blog_id() ),
			)
		);
		$this->insert();
		$this->insert(
			array(
				'user_id'        => $other,
				'key_id'         => 'bcdefghijklm',
				'credential_ref' => 'xnv:bcdefghijklm',
			)
		);
		return array(
			'admin' => $admin,
			'other' => $other,
		);
	}

	/**
	 * Return the six contradictory filter sets of the conflict matrix.
	 *
	 * @param int $admin Current administrator.
	 * @return array<string,array<string,mixed>>
	 */
	private function conflict_cases( int $admin ): array {
		return array(
			'site principal with an owner'             => array(
				'principal_type' => 'site',
				'user_id'        => $admin,
			),
			'user principal with a site reference'     => array(
				'principal_type' => 'user',
				'credential_ref' => 'xns:abcdefghijkl',
			),
			'site principal with a personal reference' => array(
				'principal_type' => 'site',
				'credential_ref' => 'xnv:abcdefghijkl',
			),
			'site principal with a key id'             => array(
				'principal_type' => 'site',
				'key_id'         => 'abcdefghijkl',
			),
			'key id with a site reference'             => array(
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xns:abcdefghijkl',
			),
			'key id with another reference'            => array(
				'key_id'         => 'abcdefghijkl',
				'credential_ref' => 'xnv:bcdefghijklm',
			),
		);
	}

	/**
	 * Add one whitelisted audit row with the canary in every storage-only column.
	 *
	 * @param array<string,mixed> $overrides Row fields.
	 */
	private function insert( array $overrides = array() ): void {
		$this->audit->insert_batch( array( array_merge( $this->row(), $overrides ) ) );
	}

	/**
	 * Build a valid whitelisted audit row.
	 *
	 * @return array<string,mixed>
	 */
	private function row(): array {
		return array(
			'occurred_at' => '2026-11-18 00:00:00',
			'request_id'  => '0123456789abcdef',
			'transport'   => 'http',
			'outcome'     => 'success',
			'arg_keys'    => array( 'id', self::CANARY ),
			'ip'          => self::CANARY,
			'ua_short'    => self::CANARY,
		);
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

	/**
	 * Count stored audit rows on the current site without trusting a response.
	 *
	 * @return int
	 */
	private function audit_row_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'xnetvn_agent_hub_wp_audit' ) );
	}
}
