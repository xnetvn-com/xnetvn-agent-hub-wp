<?php
/**
 * Machine content-author schema and human guard contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentOperations;
use XNetVN\AgentHubWP\Abilities\Packs\Content\ContentSchemas;

/** Declares machine attribution without widening human authority. */
final class ContentAuthorSchemaTest extends TestCase {

	/** Reviewed property description shared by create and duplicate. */
	private const DESCRIPTION = 'Machine attribution only; never an execution identity.';

	/** Install isolated WordPress substitutes. */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => $value instanceof \WP_Error );
	}

	/** Remove substitutes. */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reviewed machine attribution property.
	 *
	 * @return array<string,mixed>
	 */
	private static function attribution(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => self::DESCRIPTION,
		);
	}

	/** Create and duplicate declare the optional author only. */
	public function test_create_and_duplicate_declare_optional_author(): void {
		foreach ( array( 'create-post', 'duplicate-post' ) as $name ) {
			$schema = ContentSchemas::input( $name );
			self::assertFalse( $schema['additionalProperties'], $name );
			self::assertArrayHasKey( 'author', $schema['properties'], $name );
			self::assertSame( self::attribution(), $schema['properties']['author'], $name );
			self::assertNotContains( 'author', $schema['required'] ?? array(), $name );
		}
		self::assertArrayNotHasKey( 'required', ContentSchemas::input( 'create-post' ) );
		self::assertSame( array( 'id' ), ContentSchemas::input( 'duplicate-post' )['required'] );
	}

	/** The shared restore branch is split so restore never gains attribution. */
	public function test_restore_post_has_no_author_and_keeps_its_statuses(): void {
		$restore = ContentSchemas::input( 'restore-post' );
		self::assertArrayNotHasKey( 'author', $restore['properties'] );
		self::assertSame( array( 'id' ), $restore['required'] );
		self::assertSame( array( 'draft', 'publish' ), $restore['properties']['status']['enum'] );
		$duplicate = ContentSchemas::input( 'duplicate-post' );
		self::assertSame( array( 'draft', 'pending' ), $duplicate['properties']['status']['enum'] );
	}

	/** Update, changes, revisions and bulk keep their reviewed shape. */
	public function test_update_changes_bulk_and_reads_are_unaffected(): void {
		self::assertArrayNotHasKey( 'author', ContentSchemas::changes() );
		$update = ContentSchemas::input( 'update-post' );
		self::assertArrayNotHasKey( 'author', $update['properties'] );
		self::assertSame( array( 'id' ), $update['required'] );
		$revision = ContentSchemas::input( 'restore-revision' );
		self::assertArrayNotHasKey( 'author', $revision['properties'] );
		$bulk = ContentSchemas::input( 'bulk-update-posts' );
		self::assertArrayNotHasKey( 'author', $bulk['properties'] );
		self::assertSame(
			array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			$bulk['properties']['changes']['properties']['author']
		);
		$list = ContentSchemas::input( 'list-posts' );
		self::assertSame( 'array', $list['properties']['author']['type'] );
		self::assertSame( 20, $list['properties']['author']['maxItems'] );
	}

	/** An explicit human author is refused before any lookup or dispatch. */
	public function test_human_guard_rejects_explicit_author_before_any_lookup(): void {
		Functions\expect( 'get_post_type_object' )->never();
		Functions\expect( 'rest_get_route_for_post_type_items' )->never();
		Functions\expect( 'get_post' )->never();
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'wp_insert_post' )->never();
		$operations = new ContentOperations();
		$cases      = array(
			array(
				'create-post',
				array(
					'title'  => 'Machine author',
					'author' => 99,
				),
			),
			array(
				'create-post',
				array(
					'title'  => 'Existing author',
					'author' => 42,
				),
			),
			array(
				'duplicate-post',
				array(
					'id'     => 7,
					'author' => 42,
				),
			),
			array(
				'duplicate-post',
				array(
					'id'     => 7,
					'author' => 99,
				),
			),
		);
		foreach ( $cases as list( $name, $arguments ) ) {
			$result = $operations->execute( $name, $arguments );
			self::assertInstanceOf( \WP_Error::class, $result, $name );
			self::assertSame( 'xnetvn_agent_hub_wp_invalid_params', $result->get_error_code(), $name );
		}
	}

	/** Omission is not refused by the guard: execution reaches the type resolver. */
	public function test_omitted_author_reaches_the_type_resolver(): void {
		Functions\expect( 'get_post_type_object' )->once()->with( 'post' )->andReturn( null );
		$result = ( new ContentOperations() )->execute( 'create-post', array( 'title' => 'Personal author' ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'xnetvn_agent_hub_wp_post_type_unavailable', $result->get_error_code() );
	}
}
