<?php
/**
 * Term projection contract.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use XNetVN\AgentHubWP\Abilities\Packs\Taxonomy\TermProjection;

/** Allowlist shaping never forwards internal core fields. */
final class TermProjectionTest extends TestCase {
	/** Extra core fields are stripped and order is deterministic. */
	public function test_project_strips_extra_and_keeps_order(): void {
		$projection = new TermProjection();
		$row        = array(
			'id'               => 3,
			'taxonomy'         => 'category',
			'name'             => 'Tin',
			'slug'             => 'tin',
			'description'      => 'd',
			'parent'           => 0,
			'count'            => 5,
			'link'             => 'https://example.test/tin',
			'meta'             => array(),
			'term_taxonomy_id' => 99,
			'_links'           => array(),
			'extra'            => 1,
		);
		$out        = $projection->project( $row );
		self::assertSame( array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count', 'link' ), array_keys( $out ) );
		self::assertArrayNotHasKey( 'term_taxonomy_id', $out );
		self::assertArrayNotHasKey( '_links', $out );
	}

	/** A non-string link is dropped instead of failing the schema. */
	public function test_link_non_string_is_dropped(): void {
		$projection = new TermProjection();
		$row        = array(
			'id'          => 1,
			'taxonomy'    => 'category',
			'name'        => 'a',
			'slug'        => 'a',
			'description' => '',
			'parent'      => 0,
			'count'       => 0,
			'link'        => array( 'bad' ),
		);
		$out        = $projection->project( $row );
		self::assertArrayNotHasKey( 'link', $out );
	}

	/** Long multibyte descriptions are cut by characters with a flag. */
	public function test_description_truncation_flags(): void {
		$projection = new TermProjection();
		$text       = str_repeat( 'ệ', 10 ) . 'abc';
		$row        = array(
			'id'          => 1,
			'taxonomy'    => 'post_tag',
			'name'        => 'n',
			'slug'        => 'n',
			'description' => $text,
			'count'       => 0,
		);
		$out        = $projection->project( $row, array(), 10 );
		self::assertTrue( $out['description_truncated'] );
		self::assertSame( 10, mb_strlen( $out['description'] ) );
	}
}
