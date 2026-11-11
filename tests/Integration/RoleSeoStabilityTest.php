<?php
/**
 * Role/SEO stability contracts at integration level.
 *
 * Pins the mechanism the remote stability matrix relies on: the seven STAB
 * case declarations, per-key rate boundary with window recovery, the
 * connection-owned expensive budget slots, the rate_limited tool-error
 * chain and the reconcile-without-blind-retry rule for uncertain
 * mutations. Real HTTPS behavior stays in tests/E2E/role-seo-stability.php;
 * this file pins the reviewed pack behavior without any network.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentPack;
use XNetVN\AgentHubWP\Abilities\ReasonMapper;
use XNetVN\AgentHubWP\RateLimit\SlidingWindowLimiter;
use XNetVN\AgentHubWP\RateLimit\WpBucketStore;
use XNetVN\AgentHubWP\RateLimit\WpExpensiveBudget;
use XNetVN\AgentHubWP\Tests\Support\FrozenClock;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Pins stability rate, budget, denial-chain and reconcile contracts without network. */
final class RoleSeoStabilityTest extends WP_UnitTestCase {
	/**
	 * Content tools.
	 *
	 * @var array<string,\XNetVN\AgentHubWP\Contracts\AbilitySpec>
	 */
	private array $content = array();

	/**
	 * Editor identity.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Validated website target, distinct from the integration core pin.
	 *
	 * @var string
	 */
	private string $website_target;

	/**
	 * Expected website version.
	 *
	 * @var string
	 */
	private string $website_version;

	/**
	 * Fresh campaign root supplied inside the RTL exec environment.
	 *
	 * @var string
	 */
	private string $run_id;

	/** Real core server plus editor actor and the content pack. */
	public function set_up(): void {
		// wp-env is the only source of this core path: @wordpress/env 11.17.0 sets
		// WP_TESTS_DIR=/wordpress-phpunit for its tests-cli service in
		// lib/runtime/docker/build-docker-compose-config.js (pinned in
		// bin/prepare-wp-env.cjs). CI=true alone must never skip the lab contracts.
		if ( '/wordpress-phpunit' === getenv( 'WP_TESTS_DIR' ) ) {
			self::markTestSkipped( 'Role/SEO stability contracts require the Remote Test Lab.' );
		}
		// Fail before the parent, factory, REST or any database mutation.
		// The suite directory keeps the 7.1.2 target label while the lab site
		// tracks the current 7.1.x patch release.
		$targets = array(
			'/opt/wp-tests/6.9'   => array( 'wp69', '6.9' ),
			'/opt/wp-tests/7.1.2' => array( 'wp71', '7.1.4' ),
		);
		$pin     = getenv( 'WP_TESTS_DIR' );
		self::assertTrue( is_string( $pin ) && isset( $targets[ $pin ] ), 'Unsupported integration core path.' );
		list( $this->website_target, $this->website_version ) = $targets[ $pin ];
		self::assertSame( $this->website_target, getenv( 'XNETVN_MATRIX_SITE' ), 'Integration core and E2E target must agree.' );
		$run = getenv( 'XNETVN_ROLE_SEO_RUN_ID' );
		self::assertTrue( is_string( $run ) && 1 === preg_match( '/\A[a-z0-9-]{1,25}\z/', $run ), 'A fresh bounded run id is required.' );
		$this->run_id = $run;
		$version      = array();
		$status       = 0;
		$command      = implode( ' ', array_map( 'escapeshellarg', array( 'bash', '/work/infra/remote/run-wp.sh', $this->website_target, 'core', 'version' ) ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Fixed allowlisted read-only website version guard, before mutation.
		exec( $command, $version, $status );
		self::assertSame( 0, $status, 'Website version guard failed.' );
		self::assertSame( $this->website_version, trim( implode( "\n", $version ) ), 'Website version drift; do not mutate.' );
		parent::set_up();
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset the core REST fixture.
		$wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Register real core controller fixtures.
		do_action( 'rest_api_init', $wp_rest_server );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		foreach ( ( new ContentPack( new DefinitionFactory() ) )->definitions() as $tool ) {
			$this->content[ $tool->descriptor()->name ] = $tool;
		}
	}

	/**
	 * Execute a content request with schema checks and output shaping.
	 *
	 * @param string              $name Tool.
	 * @param array<string,mixed> $args Arguments.
	 * @return mixed
	 */
	private function callContent( string $name, array $args = array() ): mixed {
		$tool = $this->content[ 'xnetvn/' . $name ];
		if ( true !== rest_validate_value_from_schema( $args, $tool->input_schema() ) ) {
			return ReasonMapper::error( 'invalid_params' );
		}
		return ( new OutputPipeline( new MemorySettings() ) )->process( $tool->execute( $args ), $tool->output_schema() );
	}

	/** The stability matrix declares exactly the seven STAB rows from the ticket. */
	public function test_stability_matrix_declares_seven_stab_cases(): void {
		$path = dirname( __DIR__ ) . '/E2E/role-seo-stability.php';
		self::assertTrue( is_file( $path ), 'Stability matrix is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned matrix declaration only.
		$source = file_get_contents( $path );
		self::assertIsString( $source );
		self::assertStringContainsString( 'xnetvn_agent_hub_wp_role_seo_stability_declare_cases', $source );
		foreach ( array( 'STAB-01', 'STAB-02', 'STAB-03', 'STAB-04', 'STAB-05', 'STAB-06', 'STAB-07' ) as $id ) {
			self::assertStringContainsString( $id, $source );
		}
		self::assertStringContainsString( "'unavailable'", $source );
	}

	/**
	 * The stability harness measures the MCP request, never the work around it.
	 *
	 * The offline selftest of the matrix runs without a lab, a network or a
	 * fixture. It pins the unit of the latency sample, the per actor and per
	 * operation separation and the unavailable rule for a missing metric.
	 */
	public function test_stability_probe_measures_the_request_in_milliseconds(): void {
		$path = dirname( __DIR__ ) . '/E2E/role-seo-stability.php';
		self::assertTrue( is_file( $path ), 'Stability matrix is missing.' );
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $path ) . ' selftest';
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the owned offline selftest with a fixed argv and no credentials.
		exec( $command . ' 2>/dev/null', $output, $status );
		$decoded = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $decoded, 'The stability selftest did not answer with a report.' );
		self::assertTrue(
			(bool) ( $decoded['ok'] ?? false ),
			'The stability selftest reported a failed check: ' . wp_json_encode( $decoded['checks'] ?? array() )
		);
		$checks = $decoded['checks'] ?? array();
		self::assertIsArray( $checks );
		self::assertTrue( (bool) ( $checks['allowed-read-measures-the-request'] ?? false ), 'The allowed read must measure the MCP request.' );
		self::assertTrue( (bool) ( $checks['per-actor-samples-are-separate'] ?? false ), 'Every actor must keep its own sample.' );
		self::assertTrue( (bool) ( $checks['the-report-separates-actors-and-operations'] ?? false ), 'The report must separate actors and operations.' );
		self::assertTrue( (bool) ( $checks['a-missing-metric-stays-unavailable'] ?? false ), 'A missing metric must stay unavailable.' );
		self::assertTrue( $checks['website-drift-blocks-before-fixture'] ?? false );
		self::assertTrue( $checks['invalid-target-or-run-never-falls-back'] ?? false );
		self::assertTrue( $checks['existing-state-blocks-before-seed'] ?? false );
	}

	/**
	 * The crash holder of the costly budget stays owned by the suite.
	 *
	 * The remote matrix crashes one holder to prove that the budget slot
	 * comes back with the connection. The crash belongs to the disposable
	 * instrumentation of the lab, never to the product.
	 */
	public function test_stability_crash_holder_stays_owned_by_the_suite_instrumentation(): void {
		$matrix = dirname( __DIR__ ) . '/E2E/role-seo-stability.php';
		$plugin = dirname( __DIR__ ) . '/E2E/role-seo-mu-plugin.php';
		self::assertTrue( is_file( $matrix ), 'Stability matrix is missing.' );
		self::assertTrue( is_file( $plugin ), 'Stability instrumentation is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned matrix and instrumentation only.
		$matrix_source = file_get_contents( $matrix );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned matrix and instrumentation only.
		$plugin_source = file_get_contents( $plugin );
		self::assertIsString( $matrix_source );
		self::assertIsString( $plugin_source );
		self::assertStringContainsString( 'stab-crash-holder-', $matrix_source, 'The matrix must crash one owned holder.' );
		self::assertStringContainsString( 'stab-crash-holder-', $plugin_source, 'The instrumentation must own the crash.' );
		self::assertStringContainsString( 'posix_kill', $plugin_source, 'The crash must end the request without the release path.' );
		self::assertStringContainsString( "'crash'", $matrix_source, 'The crash case must stay declared.' );
	}

	/**
	 * The fixture verify accepts a cron digest drift and reports it.
	 *
	 * The runner already treats a cron digest drift as information. The
	 * fixture must do the same, so a background cron run between the seed
	 * and the verify cannot abort a phase.
	 */
	public function test_fixture_verify_reports_the_cron_digest_drift(): void {
		$fixture = dirname( __DIR__ ) . '/E2E/role-seo-fixture.php';
		self::assertTrue( is_file( $fixture ), 'Role SEO fixture is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned fixture only.
		$source = file_get_contents( $fixture );
		self::assertIsString( $source );
		self::assertStringNotContainsString(
			'Role SEO cron drift detected.',
			$source,
			'A cron digest drift must not abort the verify.'
		);
		self::assertStringContainsString( "'cron_drift'", $source, 'The verify must report the cron digest drift.' );
	}

	/**
	 * A phase that fails between the seed and the verify leaves nothing.
	 *
	 * The regression runs in the lab: it seeds a phase, drifts the cron
	 * digest, verifies and cleans up, then repeats with a verify that must
	 * fail. Both runs must end without a marker, without owned data and
	 * with the site totals back at the baseline.
	 */
	public function test_stability_phase_leaves_nothing_behind_after_a_cron_drift(): void {
		$path = dirname( __DIR__ ) . '/E2E/role-seo-stability.php';
		self::assertTrue( is_file( $path ), 'Stability matrix is missing.' );
		$command = implode(
			' ',
			array_map(
				'escapeshellarg',
				array( 'env', 'XNETVN_MATRIX_SITE=' . $this->website_target, 'XNETVN_ROLE_SEO_BLOG=1', 'XNETVN_ROLE_SEO_RUN_ID=' . $this->run_id, PHP_BINARY, $path, 'phase-cleanup' )
			)
		);
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the owned lab regression with a fixed argv and no credentials.
		exec( $command . ' 2>/dev/null', $output, $status );
		$decoded = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $decoded, 'The phase cleanup regression did not answer with a report.' );
		self::assertSame( $this->website_target, $decoded['target'] ?? null );
		self::assertSame( $this->run_id, $decoded['run_id'] ?? null );
		foreach ( array( 'version', 'expected_version', 'actual_version' ) as $field ) {
			self::assertSame( $this->website_version, $decoded[ $field ] ?? null );
		}
		self::assertTrue( $decoded['abort_observed'] ?? false, 'The real receipt path must reach the deterministic abort before any cleanup result counts as RED.' );
		self::assertTrue( $decoded['abort_ids_absent'] ?? false, 'Every receipted upload must be gone after real phase teardown: ' . wp_json_encode( $decoded ) );
		self::assertTrue( (bool) ( $decoded['cron_drift_reported'] ?? false ), 'A cron digest drift must be reported, not fatal.' );
		self::assertTrue( (bool) ( $decoded['drift_case_clean'] ?? false ), 'The drifted phase must leave nothing behind.' );
		self::assertTrue( (bool) ( $decoded['failed_case_clean'] ?? false ), 'The failed phase must leave nothing behind.' );
		self::assertSame( 0, (int) ( $decoded['leftover_users'] ?? -1 ), 'No owned user may survive a phase.' );
		self::assertSame( 0, (int) ( $decoded['leftover_state_files'] ?? -1 ), 'No owned state file may survive a phase.' );
		foreach ( array( 'keys', 'posts', 'terms', 'media' ) as $kind ) {
			self::assertSame( 0, $decoded[ 'leftover_' . $kind ] ?? null, 'No owned ' . $kind . ' may survive a phase.' );
		}
		self::assertTrue( $decoded['settings_restored'] ?? false );
		self::assertTrue( $decoded['barrier_idle'] ?? false );
		self::assertTrue( $decoded['workers_closed'] ?? false );
		self::assertSame( 0, $status, 'The phase cleanup regression failed: ' . wp_json_encode( $decoded ) );
	}

	/**
	 * The crash record of the dead holder lives in its own option.
	 *
	 * The surviving holder rewrites the whole barrier option every 200
	 * milliseconds. A crash record stored in that same option would be
	 * dropped by the read-modify-write of the survivor, and the matrix would
	 * wait for a marker that never comes.
	 */
	public function test_stability_crash_record_lives_in_its_own_option(): void {
		$plugin = dirname( __DIR__ ) . '/E2E/role-seo-mu-plugin.php';
		self::assertTrue( is_file( $plugin ), 'Stability instrumentation is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the owned instrumentation only.
		$source = file_get_contents( $plugin );
		self::assertIsString( $source );
		$crash   = 'xnetvn_agent_hub_wp_role_seo_stability_crash';
		$barrier = 'xnetvn_agent_hub_wp_role_seo_stability_barrier';
		self::assertStringContainsString( $crash, $source, 'The crash record needs its own option.' );
		foreach ( explode( "\n", $source ) as $line ) {
			if ( str_contains( $line, $crash ) ) {
				self::assertStringNotContainsString(
					$barrier,
					$line,
					'The crash record must never share a statement with the barrier state.'
				);
			}
		}
	}

	/**
	 * A holder whose connection dies releases its slot.
	 *
	 * The budget keeps two connection-owned MySQL locks per identity. A
	 * holder that dies with its connection must free its slot, and the slot
	 * must stay taken while the holder still lives: the crash case of the
	 * remote matrix relies on exactly this contract.
	 */
	public function test_expensive_budget_slot_recovers_after_the_holder_connection_closes(): void {
		global $wpdb;
		$budget   = new WpExpensiveBudget( $wpdb );
		$identity = 'stability-crash-' . bin2hex( random_bytes( 8 ) );
		$base     = 'xnv-cost-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $identity ), 0, 40 );
		$host     = (string) DB_HOST;
		$port     = 3306;
		if ( str_contains( $host, ':' ) ) {
			list( $host, $port_text ) = explode( ':', $host, 2 );
			$port                     = (int) $port_text;
		}
		try {
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- A second connection stands in for the crashed holder; the budget only owns its own connection.
			$holder = new \mysqli( $host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port );
		} catch ( \Throwable ) {
			self::fail( 'The holder connection is unavailable.' );
			return;
		}
		try {
			foreach ( array( 0, 1 ) as $slot ) {
				$name      = $base . '-' . $slot;
				$statement = $holder->prepare( 'SELECT GET_LOCK(?, 0)' );
				self::assertNotFalse( $statement, 'The holder could not prepare its slot query.' );
				$statement->bind_param( 's', $name );
				self::assertTrue( $statement->execute(), 'The holder could not take its slot.' );
				$row = $statement->get_result()->fetch_row();
				self::assertSame( '1', (string) ( $row[0] ?? '' ), 'The holder must own the slot.' );
			}
			$denied = $budget->run(
				$identity,
				static function (): string {
					return 'never';
				}
			);
			self::assertWPError( $denied, 'Both slots are held, so the budget must refuse the request.' );
			self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $denied->get_error_code() );
		} finally {
			// The holder dies with its connection: the MySQL lock dies too.
			$holder->close();
		}
		$recovered = $budget->run(
			$identity,
			static function (): string {
				return 'recovered';
			}
		);
		self::assertSame( 'recovered', $recovered, 'The slot must recover once the holder connection closed.' );
	}

	/** A small per-key budget denies the overflow with retry delay, then the window recovers. */
	public function test_key_rate_boundary_denies_with_retry_then_recovers(): void {
		global $wpdb;
		$clock   = new FrozenClock( 1791072030 );
		$id      = 'stability-rate-' . bin2hex( random_bytes( 8 ) );
		$limiter = new SlidingWindowLimiter( new WpBucketStore( $wpdb ), $clock );
		for ( $used = 0; $used < 5; ++$used ) {
			$result = $limiter->check( 'key', $id, 5 );
			self::assertTrue( $result->allowed, 'Request ' . ( $used + 1 ) . ' of 5 must be allowed.' );
		}
		$denied = $limiter->check( 'key', $id, 5 );
		self::assertFalse( $denied->allowed );
		self::assertGreaterThan( 0, $denied->retry_after );
		self::assertSame( 0, $denied->remaining );
		$clock->timestamp += 75;
		$recovered         = $limiter->check( 'key', $id, 5 );
		self::assertTrue( $recovered->allowed, 'The key window must recover without flushing buckets.' );
	}

	/** Two held costly slots refuse a third without running it; release restores real work. */
	public function test_expensive_budget_two_holders_deny_third_without_mutation_then_release(): void {
		global $wpdb;
		$budget    = new WpExpensiveBudget( $wpdb );
		$identity  = 'stability-holder-' . bin2hex( random_bytes( 8 ) );
		$attempted = 0;
		$completed = 0;
		$outer     = $budget->run(
			$identity,
			function () use ( $budget, $identity, &$attempted ) {
				return $budget->run(
					$identity,
					function () use ( $budget, $identity, &$attempted ) {
						$denied = $budget->run(
							$identity,
							static function () use ( &$attempted ): string {
								++$attempted;
								return 'never';
							}
						);
						self::assertWPError( $denied );
						self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $denied->get_error_code() );
						return 'held';
					}
				);
			}
		);
		self::assertSame( 'held', $outer );
		self::assertSame( 0, $attempted, 'A denied costly request must not run its mutation.' );
		$created = $budget->run(
			$identity,
			function () use ( &$completed ): array {
				++$completed;
				$post = $this->callContent( 'create-post', array( 'title' => 'Stability slot release' ) );
				self::assertIsArray( $post );
				return $post;
			}
		);
		self::assertIsArray( $created );
		self::assertSame( 1, $completed );
		self::assertNotNull( get_post( (int) $created['id'] ) );
		wp_delete_post( (int) $created['id'], true );
	}

	/** A refused costly slot surfaces as the allowlisted rate_limited tool reason. */
	public function test_budget_denial_maps_to_rate_limited_tool_error(): void {
		global $wpdb;
		$budget   = new WpExpensiveBudget( $wpdb );
		$identity = 'stability-chain-' . bin2hex( random_bytes( 8 ) );
		$result   = $budget->run(
			$identity,
			static fn() => $budget->run(
				$identity,
				static fn() => $budget->run( $identity, static fn() => 'never' )
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $result->get_error_code() );
		self::assertSame( 'rate_limited', ReasonMapper::reason( $result->get_error_code() ) );
		self::assertStringStartsWith( 'rate_limited: ', $result->get_error_message() );
	}

	/** An uncertain mutation reconciles by receipt; a blind retry would duplicate it. */
	public function test_uncertain_mutation_reconciles_by_receipt_without_blind_retry(): void {
		$probe = static function () { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter signature requires the prepared response parameter.
			return new \WP_Error( 'role_seo_stability_probe', 'Probe prepare fault.' );
		};
		add_filter( 'rest_prepare_post', $probe );
		try {
			$title  = 'Stability reconcile ' . wp_rand();
			$result = $this->callContent( 'create-post', array( 'title' => $title ) );
			self::assertWPError( $result );
			self::assertSame( 'xnetvn_agent_hub_wp_partial_failure', $result->get_error_code() );
			$created_id = (int) ( $result->get_error_data()['created_id'] ?? 0 );
			self::assertGreaterThan( 0, $created_id );
			$stored = get_post( $created_id );
			self::assertNotNull( $stored );
			self::assertSame( $title, $stored->post_title );
		} finally {
			remove_filter( 'rest_prepare_post', $probe );
		}
		$retry = $this->callContent( 'create-post', array( 'title' => $title ) );
		self::assertIsArray( $retry );
		self::assertNotSame( $created_id, (int) $retry['id'] );
		self::assertSame( $title, get_post( (int) $retry['id'] )->post_title );
		wp_delete_post( (int) $retry['id'], true );
		wp_delete_post( $created_id, true );
		self::assertNull( get_post( $created_id ) );
		self::assertNull( get_post( (int) $retry['id'] ) );
	}
}
