<?php
/**
 * Final output validation, redaction and bounded reduction tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\MutationResult;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Abilities\PartialFailure;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** The pipeline never returns values that fail its declared schema. */
final class OutputPipelineTest extends TestCase {

	/** Prepare WordPress function mocks. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
	}

	/** Clean up mock state. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Sensitive keys disappear at every depth and objects keep their shape. */
	public function test_redacts_sensitive_keys_at_every_depth(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$pipeline       = $this->pipeline( 8192 );
		$nested         = new \stdClass();
		$nested->secret = 'hidden';
		$nested->value  = 'kept';

		$result = $pipeline->process(
			array(
				'password' => 'hidden',
				'safe'     => 'visible',
				'child'    => array(
					'API_KEY' => 'hidden',
					'value'   => 'kept',
				),
				'object'   => $nested,
			),
			array( 'type' => 'object' )
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'password', $result );
		self::assertArrayNotHasKey( 'API_KEY', $result['child'] );
		self::assertSame( 'visible', $result['safe'] );
		self::assertSame( 'kept', $result['child']['value'] );
		self::assertInstanceOf( \stdClass::class, $result['object'] );
		self::assertSame( 'kept', $result['object']->value );
	}

	/** A payload that fails the declared schema becomes a safe error. */
	public function test_rejects_a_payload_that_fails_the_schema(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( false );
		$result = $this->pipeline( 8192 )->process( array( 'value' => 1 ), array( 'type' => 'object' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_output', $result->code );
	}

	/** An existing error result is sanitized instead of reflected. */
	public function test_sanitizes_an_error_result(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$result = $this->pipeline( 8192 )->process( new \WP_Error( 'secret_code', 'secret message' ), array() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringNotContainsString( 'secret message', $result->message );
	}

	/** A trusted mutation keeps its identity when projection fails. */
	public function test_returns_partial_failure_for_a_failed_mutation_projection(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$result = $this->pipeline( 8192 )->process( new MutationResult( array( 'value' => 'x' ), 42, true ), array( 'type' => 'object' ) );

		self::assertNotInstanceOf( PartialFailure::class, $result );
		self::assertSame( array( 'value' => 'x' ), $result );

		Functions\when( 'rest_validate_value_from_schema' )->justReturn( false );
		$failed = $this->pipeline( 8192 )->process( new MutationResult( array( 'value' => 'x' ), 42, false ), array( 'type' => 'object' ) );

		self::assertInstanceOf( PartialFailure::class, $failed );
		self::assertSame( 42, $failed->object_id );
		self::assertFalse( $failed->created );
	}

	/** Oversized strings are halved until the payload fits and marked truncated. */
	public function test_truncates_oversized_string_output(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'value'     => array(
					'type'      => 'string',
					'minLength' => 8,
				),
				'truncated' => array( 'type' => 'boolean' ),
			),
		);
		$result = $this->pipeline( 80 )->process( array( 'value' => str_repeat( 'a', 200 ) ), $schema );

		self::assertIsArray( $result );
		self::assertTrue( $result['truncated'] );
		self::assertLessThanOrEqual( 200, strlen( $result['value'] ) );
		self::assertGreaterThanOrEqual( 8, strlen( $result['value'] ) );
	}

	/** Oversized content without a declared truncation field is rejected. */
	public function test_rejects_oversized_output_without_a_truncated_property(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$result = $this->pipeline( 10 )->process(
			array( 'value' => str_repeat( 'a', 200 ) ),
			array(
				'type'       => 'object',
				'properties' => array( 'value' => array( 'type' => 'string' ) ),
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_output', $result->code );
	}

	/** A byte limit that is not a positive integer is refused. */
	public function test_rejects_a_non_integer_byte_limit(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$result = $this->pipeline( 0 )->process( array( 'value' => 'x' ), array( 'type' => 'object' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_invalid_output', $result->code );
	}

	/** Output nesting beyond the reviewed depth is rejected, not truncated silently. */
	public function test_rejects_output_beyond_the_depth_limit(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$value = 'leaf';
		for ( $i = 0; $i < 40; $i++ ) {
			$value = array( 'child' => $value );
		}
		$result = $this->pipeline( 8192 )->process( $value, array( 'type' => 'object' ) );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	/** Arrays reduce only down to their declared minimum item count. */
	public function test_reduces_arrays_with_min_items(): void {
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'items'     => array(
					'type'     => 'array',
					'minItems' => 2,
				),
				'truncated' => array( 'type' => 'boolean' ),
			),
		);
		$result = $this->pipeline( 90 )->process(
			array( 'items' => array( str_repeat( 'a', 60 ), str_repeat( 'b', 60 ), str_repeat( 'c', 60 ), str_repeat( 'd', 60 ) ) ),
			$schema
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['truncated'] );
		self::assertGreaterThanOrEqual( 2, count( $result['items'] ) );
	}

	/**
	 * Build a pipeline with a fixed output byte limit.
	 *
	 * @param int $limit Byte ceiling.
	 * @return OutputPipeline
	 */
	private function pipeline( int $limit ): OutputPipeline {
		return new OutputPipeline(
			new MemorySettings(
				array(
					'enabled'          => true,
					'tier_ceiling'     => 'admin',
					'allow_admin_tier' => true,
					'output_max_bytes' => $limit,
				)
			)
		);
	}
}
