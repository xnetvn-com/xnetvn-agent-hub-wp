<?php
/**
 * Independent content response projection contracts.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Content\PostProjection;

/** Does not load WordPress or a database; object permissions are injected. */
final class PostProjectionTest extends TestCase {
	/**
	 * Build a realistic REST-shaped response containing private/unselected fields.
	 *
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		return array(
			'id'                       => 7,
			'type'                     => 'post',
			'status'                   => 'publish',
			'title'                    => array(
				'raw'      => 'Raw title',
				'rendered' => 'Rendered title',
			),
			'content'                  => array(
				'raw'      => 'Raw content CANARY',
				'rendered' => '<p>Rendered content</p>',
			),
			'excerpt'                  => array(
				'raw'      => 'Raw excerpt',
				'rendered' => '<p>Rendered excerpt</p>',
			),
			'author'                   => 3,
			'password'                 => 'PASSWORD-CANARY',
			'_links'                   => array( 'private' => 'LINK-CANARY' ),
			'_embedded'                => array( 'secret' => 'EMBED-CANARY' ),
			'unknown_private_property' => 'PRIVATE-CANARY',
		);
	}

	/** Default rendering never forwards raw/protected or unreviewed REST properties. */
	public function test_rendered_projection_is_allowlisted(): void {
		$view = new PostProjection( static fn( int $id ): bool => 0 === $id );
		$data = $view->project( $this->fixture() );
		self::assertSame( 'Rendered title', $data['title'] );
		self::assertSame( '<p>Rendered content</p>', $data['content'] );
		self::assertSame( '<p>Rendered excerpt</p>', $data['excerpt'] );
		self::assertArrayNotHasKey( 'password', $data );
		self::assertArrayNotHasKey( '_links', $data );
		self::assertArrayNotHasKey( '_embedded', $data );
		self::assertArrayNotHasKey( 'unknown_private_property', $data );
		self::assertStringNotContainsString( 'CANARY', json_encode( $data, JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Service-free unit contract.
	}

	/** Explicit fields narrow output while mandatory object identity remains. */
	public function test_field_projection_retains_identity(): void {
		$view = new PostProjection( static fn( int $id ): bool => 0 === $id );
		self::assertSame(
			array(
				'id'     => 7,
				'type'   => 'post',
				'status' => 'publish',
				'title'  => 'Rendered title',
			),
			$view->project( $this->fixture(), 'rendered', array( 'title' ) )
		);
	}

	/** Raw access asks permission for the actual output ID and refuses a denial. */
	public function test_raw_projection_requires_exact_object_permission(): void {
		$seen = array();
		$view = new PostProjection(
			static function ( int $id ) use ( &$seen ): bool {
				$seen[] = $id;
				return false;
			}
		);
		try {
			$view->project( $this->fixture(), 'raw' );
			self::fail( 'Raw content must not be returned without object permission.' );
		} catch ( \DomainException ) {
			self::assertSame( array( 7 ), $seen );
		}
	}

	/** A permitted raw response never falls back to rendered data for missing raw content. */
	public function test_authorized_raw_projection(): void {
		$view = new PostProjection( static fn( int $id ): bool => 7 === $id );
		$data = $view->project( $this->fixture(), 'raw' );
		self::assertSame( 'Raw title', $data['title'] );
		self::assertSame( 'Raw content CANARY', $data['content'] );
		self::assertArrayNotHasKey( 'password', $data );
	}

	/** A permitted raw response never falls back to rendered data for missing raw content. */
	public function test_missing_raw_data_is_an_error(): void {
		$view = new PostProjection( static fn( int $id ): bool => 7 === $id );
		$data = $this->fixture();
		unset( $data['content']['raw'] );
		$this->expectException( \UnexpectedValueException::class );
		$view->project( $data, 'raw' );
	}

	/** Excerpt mode uses the permitted rendered excerpt as content. */
	public function test_excerpt_mode(): void {
		$view = new PostProjection( static fn( int $id ): bool => 0 === $id );
		self::assertSame( '<p>Rendered excerpt</p>', $view->project( $this->fixture(), 'excerpt' )['content'] );
	}

	/** Invalid projection fields/formats fail instead of forwarding arbitrary properties. */
	public function test_unknown_field_is_rejected(): void {
		$view = new PostProjection( static fn( int $id ): bool => 7 === $id );
		$this->expectException( \InvalidArgumentException::class );
		$view->project( $this->fixture(), 'rendered', array( 'password' ) );
	}

	/** Malformed identity cannot be turned into a fabricated successful post. */
	public function test_unknown_format_is_rejected(): void {
		$view = new PostProjection( static fn( int $id ): bool => 7 === $id );
		$this->expectException( \InvalidArgumentException::class );
		$view->project( $this->fixture(), 'unsafe-format' );
	}

	/** Malformed identity cannot be turned into a fabricated successful post. */
	public function test_missing_identity_is_rejected(): void {
		$view = new PostProjection( static fn( int $id ): bool => 0 === $id );
		$data = $this->fixture();
		unset( $data['id'] );
		$this->expectException( \UnexpectedValueException::class );
		$view->project( $data );
	}
}
