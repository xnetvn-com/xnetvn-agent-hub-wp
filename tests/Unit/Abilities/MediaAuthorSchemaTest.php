<?php
/**
 * Media author attribution schema contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaSchemas;

/** Pins attribution to the two upload schemas and away from every human write. */
final class MediaAuthorSchemaTest extends TestCase {

	/** Media tools that may carry attribution. */
	private const UPLOAD_TOOLS = array( 'upload-media-from-url', 'upload-media-from-data' );

	/** Media tools inspected by this contract. */
	private const ALL_TOOLS = array( 'list-media', 'get-media', 'update-media', 'upload-media-from-url', 'upload-media-from-data', 'delete-media', 'get-media-upload-limits' );

	/**
	 * Upload tools.
	 *
	 * @return array<string,array{string}>
	 */
	public function upload_tools(): array {
		return array(
			'upload-media-from-url'  => array( 'upload-media-from-url' ),
			'upload-media-from-data' => array( 'upload-media-from-data' ),
		);
	}

	/**
	 * The upload schemas accept an optional positive integer author.
	 *
	 * @dataProvider upload_tools
	 * @param string $name Upload tool.
	 */
	public function test_upload_schemas_expose_optional_positive_author( string $name ): void {
		$schema     = MediaSchemas::input( $name );
		$properties = (array) ( $schema['properties'] ?? array() );
		self::assertSame( 'object', $schema['type'], $name );
		self::assertFalse( $schema['additionalProperties'], $name );
		self::assertArrayHasKey( 'author', $properties, $name );
		self::assertSame(
			array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			$properties['author'],
			$name
		);
		self::assertNotContains( 'author', (array) ( $schema['required'] ?? array() ), $name );
	}

	/** The human update schema never exposes attribution. */
	public function test_update_schema_never_exposes_author(): void {
		$schema     = MediaSchemas::input( 'update-media' );
		$properties = (array) ( $schema['properties'] ?? array() );
		self::assertSame( 'object', $schema['type'] );
		self::assertFalse( $schema['additionalProperties'] );
		self::assertArrayNotHasKey( 'author', $properties );
		self::assertSame( array( 'id', 'title', 'alt_text', 'caption', 'description', 'post' ), array_keys( $properties ) );
	}

	/** Editable attachment metadata stays free of attribution. */
	public function test_changes_never_expose_author(): void {
		$changes = MediaSchemas::changes();
		self::assertArrayNotHasKey( 'author', $changes );
		self::assertArrayNotHasKey( 'post_author', $changes );
		self::assertSame( array( 'title', 'alt_text', 'caption', 'description', 'post' ), array_keys( $changes ) );
	}

	/** The read-side author filter is a filter list, not an attribution field. */
	public function test_read_author_filter_stays_an_array_filter(): void {
		$filter = MediaSchemas::input( 'list-media' )['properties']['author'];
		self::assertSame( 'array', $filter['type'] );
		self::assertSame( ContentSchemas::integer(), $filter['items'] );
	}

	/** Every media input object stays strict and only the uploads carry attribution. */
	public function test_only_upload_inputs_carry_attribution(): void {
		foreach ( self::ALL_TOOLS as $name ) {
			$schema = MediaSchemas::input( $name );
			self::assertSame( 'object', $schema['type'], $name );
			self::assertFalse( $schema['additionalProperties'], $name );
			if ( 'list-media' === $name ) {
				continue;
			}
			self::assertSame(
				in_array( $name, self::UPLOAD_TOOLS, true ),
				array_key_exists( 'author', (array) ( $schema['properties'] ?? array() ) ),
				$name . ' attribution presence'
			);
		}
	}
}
