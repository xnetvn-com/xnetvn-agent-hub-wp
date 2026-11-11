<?php
/**
 * Immutable private-definition contract tests.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\DefinitionFactory;
use XNetVN\AgentHubWP\Domain\RiskTier;

/** Validates data contracts before WordPress registration. */
final class DefinitionTest extends TestCase {
	/** Valid definitions expose only safe contract metadata and a canonical tool name. */
	public function test_valid_definition(): void {
		$definition = ( new DefinitionFactory() )->create( $this->data() );
		self::assertSame( 'xnetvn/get-fixture', $definition->descriptor()->name );
		self::assertSame( 'xnetvn-get-fixture', $definition->tool_name() );
		self::assertSame( array( 'value' => 'safe' ), $definition->execute( array() ) );
		self::assertSame( false, $definition->input_schema()['additionalProperties'] );
	}
	/** Public metadata is rejected at the plugin definition boundary. */
	public function test_public_metadata_is_forbidden(): void {
		$data           = $this->data();
		$data['public'] = true;
		$this->expectException( \InvalidArgumentException::class );
		( new DefinitionFactory() )->create( $data );
	}
	/** Destructive hints cannot describe a read-only definition. */
	public function test_inconsistent_annotation_is_forbidden(): void {
		$data                               = $this->data();
		$data['annotations']['destructive'] = true;
		$this->expectException( \InvalidArgumentException::class );
		( new DefinitionFactory() )->create( $data );
	}

	/** A permanent exclusion cannot be enabled by an otherwise valid definition. */
	public function test_key_management_ability_is_permanently_excluded(): void {
		$data         = $this->data();
		$data['name'] = 'xnetvn/create-api-key';
		$this->expectException( \InvalidArgumentException::class );
		( new DefinitionFactory() )->create( $data );
	}

	/** Admin risk must be part of the explicitly reviewed administrator roster. */
	public function test_unreviewed_admin_definition_is_excluded(): void {
		$data                = $this->data();
		$data['name']        = 'xnetvn/unreviewed-admin-operation';
		$data['tier']        = RiskTier::Admin;
		$data['annotations'] = array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
		$this->expectException( \InvalidArgumentException::class );
		( new DefinitionFactory() )->create( $data );
	}
	/**
	 * Return a minimal strict logical-output definition.
	 *
	 * @return array<string,mixed>
	 */
	private function data(): array {
		return array(
			'name'          => 'xnetvn/get-fixture',
			'label'         => 'Read fixture',
			'description'   => 'Returns a safe fixture value. Use this tool to verify read-only structured output.',
			'inputSchema'   => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'outputSchema'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array( 'value' => array( 'type' => 'string' ) ),
				'required'             => array( 'value' ),
			),
			'tier'          => RiskTier::Read,
			'toolset'       => 'system',
			'annotations'   => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'visibilityCap' => 'read',
			'handler'       => static fn(): array => array( 'value' => 'safe' ),
			'example'       => array( 'value' => 'safe' ),
		);
	}
}
