<?php
/**
 * Comment DTO projection contracts without WordPress.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentProjection;

/** Private fields never leave the projection; email and raw need object permission. */
final class CommentProjectionTest extends TestCase {
	/**
	 * Build a realistic REST-shaped comment record with private fields.
	 *
	 * @return array<string,mixed>
	 */
	private function fixture(): array {
		return array(
			'id'                 => 456,
			'post'               => 123,
			'parent'             => 0,
			'type'               => 'comment',
			'status'             => 'hold',
			'author'             => 0,
			'author_name'        => 'Guest A',
			'author_url'         => 'https://example.test/',
			'author_email'       => 'guest-CANARY@example.test',
			'author_ip'          => '203.0.113.7-CANARY',
			'author_user_agent'  => 'Agent-CANARY',
			'author_avatar_urls' => array( '24' => 'https://example.test/avatar-CANARY' ),
			'content'            => array(
				'raw'      => 'Raw CANARY',
				'rendered' => '<p>Rendered</p>',
			),
			'date'               => '2026-10-08T09:15:00',
			'date_gmt'           => '2026-10-08T02:15:00',
			'link'               => 'https://example.test/post/#comment-456',
			'_links'             => array(),
			'meta'               => array(),
		);
	}

	/** Default output drops email, IP, agent, avatar, links and meta. */
	public function test_default_projection_is_private(): void {
		$projection = new CommentProjection( static fn(): bool => true );
		$result     = $projection->project( $this->fixture() );
		self::assertSame( 456, $result['id'] );
		self::assertSame( 'hold', $result['status'] );
		self::assertArrayNotHasKey( 'author_email', $result );
		self::assertArrayNotHasKey( 'author_ip', $result );
		self::assertArrayNotHasKey( 'author_user_agent', $result );
		self::assertArrayNotHasKey( 'author_avatar_urls', $result );
		self::assertArrayNotHasKey( '_links', $result );
		self::assertArrayNotHasKey( 'meta', $result );
		self::assertStringNotContainsString( 'CANARY', json_encode( $result, JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Service-free unit contract.
	}

	/** Email is returned only when requested with object permission. */
	public function test_email_needs_request_and_permission(): void {
		$allowed = new CommentProjection( static fn(): bool => true );
		$result  = $allowed->project( $this->fixture(), 'rendered', array( 'author_email' ) );
		self::assertSame( 'guest-CANARY@example.test', $result['author_email'] );
		$denied = new CommentProjection( static fn(): bool => false );
		$this->expectException( \DomainException::class );
		$denied->project( $this->fixture(), 'rendered', array( 'author_email' ) );
	}

	/** Refused raw access never falls back to rendered. */
	public function test_raw_without_permission_throws(): void {
		$projection = new CommentProjection( static fn(): bool => false );
		$this->expectException( \DomainException::class );
		$projection->project( $this->fixture(), 'raw' );
	}

	/** Long content is cut on character boundaries with a truncation flag. */
	public function test_content_truncation(): void {
		$projection                 = new CommentProjection( static fn(): bool => true );
		$row                        = $this->fixture();
		$row['content']['rendered'] = 'héllo wörld CANARY text';
		$result                     = $projection->project( $row, 'rendered', array(), 5 );
		self::assertTrue( $result['content_truncated'] );
		self::assertSame( 'héllo', $result['content'] );
	}
}
