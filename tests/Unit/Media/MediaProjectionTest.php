<?php
/**
 * Distinguish unavailable attachment-parent data from explicit detachment.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Media\MediaProjection;

/** Core null is an explicit unattached value; a missing field is unknown. */
final class MediaProjectionTest extends TestCase {
	/** Projection must not fabricate a successful detached state. */
	public function test_missing_parent_is_not_zero(): void {
		$projection = new MediaProjection();
		$record     = array(
			'id'     => 1,
			'status' => 'inherit',
		);
		self::assertArrayNotHasKey( 'post', $projection->project( $record ) );
		$record['post'] = null;
		self::assertSame( 0, $projection->project( $record )['post'] );
		$record['post'] = 12;
		self::assertSame( 12, $projection->project( $record )['post'] );
	}
}
