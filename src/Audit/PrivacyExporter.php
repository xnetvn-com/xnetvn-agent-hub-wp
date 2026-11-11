<?php
/**
 * WordPress personal data exporter.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Audit;

use XNetVN\AgentHubWP\Contracts\PrivacyStore;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** Runs within WordPress's confirmed administrator privacy workflow. */
final class PrivacyExporter implements ServiceProvider {
	/**
	 * Bind whitelisted privacy persistence.
	 *
	 * @param PrivacyStore $store Persistence.
	 */
	public function __construct( private PrivacyStore $store ) {
	}

	/** Register the standard privacy exporter. */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
	}

	/**
	 * Add the callback without replacing other exporters.
	 *
	 * @param array<string,mixed> $exporters Existing exporters.
	 * @return array<string,mixed>
	 */
	public function exporters( array $exporters ): array {
		$exporters['xnetvn-agent-hub-wp'] = array(
			'exporter_friendly_name' => __( 'Agent Hub keys and activity', 'xnetvn-agent-hub-wp' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Export only data linked to the requested registered email address.
	 *
	 * @param string $email_address Confirmed email.
	 * @param int    $page Page.
	 * @return array{data:array<array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( false === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$result = $this->store->export_for_user( $user->ID, $page );
		$items  = array();
		$labels = array(
			'keys'        => __( 'Agent Hub API keys', 'xnetvn-agent-hub-wp' ),
			'audit'       => __( 'Agent Hub activity', 'xnetvn-agent-hub-wp' ),
			'preferences' => __( 'Agent Hub notification preferences', 'xnetvn-agent-hub-wp' ),
		);
		foreach ( array( 'keys', 'audit', 'preferences' ) as $group ) {
			foreach ( $result[ $group ] as $row ) {
				$data = array();
				foreach ( $row as $name => $value ) {
					if ( null !== $value ) {
						$data[] = array(
							'name'  => $name,
							'value' => (string) $value,
						);
					}
				}
				$items[] = array(
					'group_id'    => 'xnetvn-agent-hub-wp-' . $group,
					'group_label' => $labels[ $group ],
					'item_id'     => 'xnetvn-agent-hub-wp-' . $group . '-' . $row['id'],
					'data'        => $data,
				);
			}
		}
		return array(
			'data' => $items,
			'done' => $result['done'],
		);
	}
}
