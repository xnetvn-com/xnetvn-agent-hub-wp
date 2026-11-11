<?php
/**
 * Schema-valid redaction and bounded output tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;
use XNetVN\AgentHubWP\Abilities\OutputPipeline;
use XNetVN\AgentHubWP\Tests\Support\MemorySettings;

/** Final structured data must remain truthful and schema-valid. */
final class OutputPipelineTest extends WP_UnitTestCase {
	/** Secret fields disappear while declared safe data remains. */
	public function test_redaction(): void {
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'value'  => array( 'type' => 'string' ),
				'secret' => array( 'type' => 'string' ),
			),
			'required'             => array( 'value' ),
		);
		$result = ( new OutputPipeline( new MemorySettings() ) )->process(
			array(
				'value'  => 'safe',
				'secret' => 'canary',
			),
			$schema
		);
		self::assertSame( array( 'value' => 'safe' ), $result );
	}
	/** Required secret fields cannot be removed and then mislabeled as success. */
	public function test_redaction_invalidating_schema_fails(): void {
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array( 'token' => array( 'type' => 'string' ) ),
			'required'             => array( 'token' ),
		);
		self::assertWPError( ( new OutputPipeline( new MemorySettings() ) )->process( array( 'token' => 'canary' ), $schema ) );
	}
	/** Declared truncation preserves UTF8 and the final schema under the byte limit. */
	public function test_bounded_schema_valid_truncation(): void {
		$schema   = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'content'   => array( 'type' => 'string' ),
				'truncated' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'content' ),
		);
		$pipeline = new OutputPipeline( new MemorySettings( array( 'output_max_bytes' => 1024 ) ) );
		$result   = $pipeline->process( array( 'content' => str_repeat( 'Content Ω ', 1000 ) ), $schema );
		self::assertFalse( is_wp_error( $result ) );
		self::assertTrue( $result['truncated'] );
		self::assertLessThanOrEqual( 1024, strlen( wp_json_encode( $result ) ) );
		self::assertTrue( rest_validate_value_from_schema( $result, $schema ) );
	}
	/** Invalid output and an impossible bounded schema never become empty success. */
	public function test_invalid_or_impossible_output(): void {
		$pipeline = new OutputPipeline( new MemorySettings( array( 'output_max_bytes' => 1024 ) ) );
		$schema   = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
			'required'             => array( 'id' ),
		);
		self::assertWPError( $pipeline->process( array( 'id' => 'bad' ), $schema ) );
		$big = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'content'   => array(
					'type'      => 'string',
					'minLength' => 2000,
				),
				'truncated' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'content' ),
		);
		self::assertWPError( $pipeline->process( array( 'content' => str_repeat( 'x', 3000 ) ), $big ) );
	}
}
