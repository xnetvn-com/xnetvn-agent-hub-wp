<?php
/**
 * Shared database expensive-operation concurrency budget.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\RateLimit\WpExpensiveBudget;

/** Multiple calls cannot acquire a third slot and exceptions release ownership. */
final class ExpensiveBudgetTest extends WP_UnitTestCase {
	/** Third same-key call is refused; another key remains independent. */
	public function test_two_slots_and_key_isolation(): void {
		global $wpdb;
		$budget = new WpExpensiveBudget( $wpdb );
		$result = $budget->run(
			'key-a',
			fn() => $budget->run(
				'key-a',
				function () use ( $budget ) {
					self::assertSame( 'other', $budget->run( 'key-b', static fn() => 'other' ) );
					return $budget->run( 'key-a', static fn() => 'never' );
				}
			)
		);
		self::assertWPError( $result );
		self::assertSame( 'xnetvn_agent_hub_wp_rate_limited', $result->get_error_code() );
		self::assertSame( 'released', $budget->run( 'key-a', static fn() => 'released' ) );
	}
	/** Exceptions cannot permanently consume slots. */
	public function test_exception_releases_slot(): void {
		global $wpdb;
		$budget = new WpExpensiveBudget( $wpdb );
		try {
			$budget->run(
				'exception',
				static function () {
					throw new \RuntimeException( 'fixture' );
				}
			);
		} catch ( \RuntimeException ) {
			self::assertTrue( true ); }
		self::assertSame( 7, $budget->run( 'exception', static fn() => 7 ) );
	}
	/** Failed lock cleanup never hides a completed mutation or permits reentrant reuse. */
	public function test_release_failure_preserves_completed_mutation(): void {
		global $wpdb;
		$budget       = new WpExpensiveBudget( $wpdb );
		$identity     = 'release-receipt-fixture';
		$fail_release = static function ( string $sql ): string {
			return str_contains( $sql, 'RELEASE_LOCK' ) ? 'SELECT xnetvn_release_failure_fixture()' : $sql;
		};
		add_filter( 'query', $fail_release );
		try {
			$result = $budget->run(
				$identity,
				function () {
					$operations = new \XNetVN\AgentHubWP\Abilities\Packs\Content\ContentOperations();
					wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
					return $operations->execute( 'create-post', array( 'title' => 'Budget cleanup receipt fixture' ) );
				}
			);
			self::assertInstanceOf( \XNetVN\AgentHubWP\Abilities\MutationResult::class, $result );
			self::assertSame( 'Budget cleanup receipt fixture', get_post( $result->object_id )->post_title );
			$this->expectException( \XNetVN\AgentHubWP\Persistence\StorageException::class );
			$budget->run( $identity, static fn() => 'must not run' );
		} finally {
			remove_filter( 'query', $fail_release );
			$base = 'xnv-cost-' . substr( hash( 'sha256', $wpdb->prefix . ':' . $identity ), 0, 40 );
			foreach ( array( 0, 1 ) as $slot ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $base . '-' . $slot ) ); }
			$property = new \ReflectionProperty( WpExpensiveBudget::class, 'held' );
			$held     = $property->getValue();
			unset( $held[ $base . '-0' ], $held[ $base . '-1' ] );
			$property->setValue( null, $held );
		}
	}
}
