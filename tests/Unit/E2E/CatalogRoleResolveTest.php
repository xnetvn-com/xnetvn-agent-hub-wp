<?php
/**
 * Logical-identifier resolution checks for the catalog role matrix harness.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Unit\E2E;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/tests/E2E/catalog-role-cases.php';

/**
 * A bare integer is never a logical identifier.
 *
 * The harness used to resolve every payload integer in 5000-5999 through the
 * fixture ledger. Once WordPress object identifiers reached that band, a real
 * identifier was either rejected or silently replaced by another object's
 * identifier. Only an explicit marker may be resolved.
 */
final class CatalogRoleResolveTest extends TestCase {

	/** A real identifier inside the old band is passed through unchanged. */
	public function test_real_identifier_inside_the_old_band_is_not_rewritten(): void {
		self::assertSame(
			array( 'id' => 5318 ),
			xnetvn_agent_hub_wp_catalog_role_resolve( array( 'id' => 5318 ), array( 5307 => 7001 ) )
		);
	}

	/** A real identifier that equals a ledger key is not substituted. */
	public function test_real_identifier_equal_to_a_ledger_key_is_not_substituted(): void {
		self::assertSame(
			array( 'id' => 5401 ),
			xnetvn_agent_hub_wp_catalog_role_resolve( array( 'id' => 5401 ), array( 5401 => 460 ) )
		);
	}

	/** An explicit marker resolves through the ledger, including at depth. */
	public function test_marker_resolves_through_the_ledger(): void {
		$payload = array(
			'ids'     => array( 10, 11 ),
			'changes' => array( 'categories' => array( xnetvn_agent_hub_wp_catalog_role_logical( 5307 ) ) ),
		);
		self::assertSame(
			array(
				'ids'     => array( 10, 11 ),
				'changes' => array( 'categories' => array( 7001 ) ),
			),
			xnetvn_agent_hub_wp_catalog_role_resolve( $payload, array( 5307 => 7001 ) )
		);
	}

	/** An unknown marker key fails closed instead of passing the marker on. */
	public function test_unknown_marker_key_throws(): void {
		$this->expectException( \RuntimeException::class );
		xnetvn_agent_hub_wp_catalog_role_resolve( array( 'id' => xnetvn_agent_hub_wp_catalog_role_logical( 5307 ) ), array() );
	}

	/** A malformed marker fails closed. */
	public function test_malformed_marker_throws(): void {
		$this->expectException( \RuntimeException::class );
		xnetvn_agent_hub_wp_catalog_role_resolve( array( '$logical' => '5307' ), array( 5307 => 7001 ) );
	}

	/** The factories keep plain integers; only the harness wraps the three sites. */
	public function test_factory_payloads_stay_plain_and_the_harness_marks_them(): void {
		$context = array(
			'target'          => 100,
			'extra'           => 101,
			'user'            => 102,
			'target_kind'     => 'post',
			'target_taxonomy' => 'category',
			'extra_kind'      => 'term',
			'extra_taxonomy'  => 'post_tag',
			'actor_user'      => 1,
			'campaign'        => 'cat-test',
			'actor'           => 'admin',
		);
		$bulk    = xnetvn_agent_hub_wp_catalog_role_payload(
			array(
				'id'      => 'CAT-test',
				'payload' => 'content.bulk_update_posts',
			),
			$context
		);
		self::assertSame( 5307, $bulk['changes']['categories'][0] ?? null );
		$marked = xnetvn_agent_hub_wp_catalog_role_mark_logical( 'content.bulk_update_posts', $bulk );
		self::assertSame( 5307, $marked['changes']['categories'][0]['$logical'] ?? null );
		self::assertSame(
			array(
				'ids'     => array( 100, 101 ),
				'changes' => array( 'categories' => array( 7001 ) ),
			),
			xnetvn_agent_hub_wp_catalog_role_resolve( $marked, array( 5307 => 7001 ) )
		);
		$assign = xnetvn_agent_hub_wp_catalog_role_payload(
			array(
				'id'      => 'CAT-test',
				'payload' => 'taxonomy.assign_post_terms',
			),
			$context
		);
		self::assertSame( 5307, $assign['term_ids'][0] ?? null );
		self::assertSame( 5307, xnetvn_agent_hub_wp_catalog_role_mark_logical( 'taxonomy.assign_post_terms', $assign )['term_ids'][0]['$logical'] ?? null );
		$bulk_terms = xnetvn_agent_hub_wp_catalog_role_payload(
			array(
				'id'      => 'CAT-test',
				'payload' => 'taxonomy.bulk_assign_post_terms',
			),
			$context
		);
		self::assertSame( 5308, xnetvn_agent_hub_wp_catalog_role_mark_logical( 'taxonomy.bulk_assign_post_terms', $bulk_terms )['term_ids'][0]['$logical'] ?? null );
	}
}
