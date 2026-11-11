<?php
/**
 * Gate ownership contracts of the role/SEO acceptance verifier.
 *
 * Pins both directions of the gate mechanism the remote campaign relies on:
 * a gate only covers a red case while its owner ticket is still open, and a
 * gate never matches another target. The offline selftest of the tool owns
 * the same assertions against synthetic tickets; this file proves them
 * against the real ticket root, without network, lab or WordPress fixtures.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\Integration;

use WP_UnitTestCase;

require_once __DIR__ . '/../E2E/role-seo-acceptance.php';

/** Pins the ownership rules of a declared acceptance gate. */
final class RoleSeoAcceptanceGateTest extends WP_UnitTestCase {
	/** A gate whose owner ticket is already closed never covers a red case. */
	public function test_a_gate_of_a_closed_ticket_is_not_applied(): void {
		$closed = xnetvn_agent_hub_wp_role_seo_acceptance_closed_owner( $this->tickets_root() );
		if ( '' === $closed ) {
			self::markTestSkipped( 'The ticket fixtures have no closed ticket.' );
		}
		$gate = array(
			'kind'    => 'case',
			'case_id' => 'STAB-03',
			'target'  => 'wp69',
			'owner'   => $closed,
		);
		self::assertFalse( xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( $closed, $this->tickets_root() ) );
		self::assertNull( xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array( $gate ), 'STAB-03', 'wp69', $this->tickets_root() ) );
	}

	/** A gate whose owner ticket is still open covers its red case. */
	public function test_a_gate_of_an_open_ticket_is_applied(): void {
		$open = $this->an_open_owner();
		if ( '' === $open ) {
			self::fail( 'The ticket fixtures have no open ticket, so no gate can own a red case.' );
		}
		$gate = array(
			'kind'    => 'case',
			'case_id' => 'STAB-03',
			'target'  => 'wp69',
			'owner'   => $open,
		);
		self::assertTrue( xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( $open, $this->tickets_root() ) );
		self::assertNotNull( xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array( $gate ), 'STAB-03', 'wp69', $this->tickets_root() ) );
	}

	/** A missing owner never owns a gate. */
	public function test_a_missing_owner_never_owns_a_gate(): void {
		self::assertFalse( xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( '' ) );
		self::assertFalse( xnetvn_agent_hub_wp_role_seo_acceptance_owner_exists( 'ISSUE_v0.1_1999-01-01_01_khong-ton-tai' ) );
		$gate = array(
			'kind'    => 'case',
			'case_id' => 'STAB-03',
			'target'  => 'wp69',
			'owner'   => '',
		);
		self::assertNull( xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array( $gate ), 'STAB-03', 'wp69' ) );
	}

	/** A gate of another target never matches, even with an open owner. */
	public function test_a_case_gate_never_matches_every_target(): void {
		$open = $this->an_open_owner();
		if ( '' === $open ) {
			self::fail( 'The ticket fixtures have no open ticket, so the target rule cannot be isolated from ownership.' );
		}
		$gate = array(
			'kind'    => 'case',
			'case_id' => 'STAB-03',
			'target'  => 'wp71',
			'owner'   => $open,
		);
		self::assertNull( xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array( $gate ), 'STAB-03', 'wp69', $this->tickets_root() ) );
		self::assertNotNull( xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( array( $gate ), 'STAB-03', 'wp71', $this->tickets_root() ) );
	}

	/** The retired STAB-03 gate of wp69 no longer covers a red case. */
	public function test_the_retired_stab03_gate_is_absent_from_the_declared_list(): void {
		$gates = xnetvn_agent_hub_wp_role_seo_acceptance_open_gates();
		foreach ( $gates as $candidate ) {
			self::assertFalse(
				'case' === ( $candidate['kind'] ?? '' ) && 'STAB-03' === ( $candidate['case_id'] ?? '' ) && 'wp69' === ( $candidate['target'] ?? '' ),
				'The retired STAB-03 gate of wp69 must not stay in the declared gate list.'
			);
		}
		self::assertNull(
			xnetvn_agent_hub_wp_role_seo_acceptance_case_gate( $gates, 'STAB-03', 'wp69' ),
			'The retired STAB-03 gate of wp69 must not cover a red case.'
		);
	}

	/**
	 * Ticket fixture root used by the ownership rules.
	 *
	 * @return string Absolute fixture path.
	 */
	private function tickets_root(): string {
		return dirname( __DIR__, 2 ) . '/tests/Fixtures/tickets';
	}

	/**
	 * Read the id of the first open ticket of the fixture tree.
	 *
	 * @return string Issue id of the first open ticket.
	 */
	private function an_open_owner(): string {
		$paths = glob( $this->tickets_root() . '/*/*/*.md' );
		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			if ( in_array( xnetvn_agent_hub_wp_role_seo_acceptance_ticket_status( $path ), XNETVN_AGENT_HUB_WP_ROLE_SEO_OPEN_STATUSES, true ) ) {
				return basename( $path, '.md' );
			}
		}
		return '';
	}
}
