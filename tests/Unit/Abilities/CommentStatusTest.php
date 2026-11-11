<?php
/**
 * Comment status normalization and transition table.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Comments\CommentStatus;

/** Does not load WordPress; the standalone error substitute stands in for WP_Error.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommentStatusTest extends TestCase {

	/** Load the isolated error substitute. */
	protected function setUp(): void {
		parent::setUp();
		require dirname( __DIR__, 2 ) . '/Support/StandaloneWordPressError.php';
	}

	/** Normalized core values cover every stored representation. */
	public function test_normalize(): void {
		self::assertSame( 'approved', CommentStatus::normalize( '1' ) );
		self::assertSame( 'hold', CommentStatus::normalize( '0' ) );
		self::assertSame( 'spam', CommentStatus::normalize( 'spam' ) );
		self::assertSame( 'trash', CommentStatus::normalize( 'trash' ) );
		self::assertNull( CommentStatus::normalize( 'post-trashed' ) );
	}

	/** The transition table accepts, ignores or rejects each action. */
	public function test_transition_table(): void {
		[$status, $changed] = CommentStatus::apply( 'hold', 'approve' );
		self::assertSame( 'approved', $status );
		self::assertTrue( $changed );
		[$status, $changed] = CommentStatus::apply( 'approved', 'approve' );
		self::assertSame( 'approved', $status );
		self::assertFalse( $changed );
		self::assertInstanceOf( \WP_Error::class, CommentStatus::apply( 'spam', 'approve' ) );
		self::assertInstanceOf( \WP_Error::class, CommentStatus::apply( 'trash', 'spam' ) );
		[$status, $changed] = CommentStatus::apply( 'approved', 'untrash' );
		self::assertSame( 'approved', $status );
		self::assertFalse( $changed );
		[$status, $changed] = CommentStatus::apply( 'spam', 'unspam' );
		self::assertSame( 'restore', $status );
		self::assertTrue( $changed );
	}
}
