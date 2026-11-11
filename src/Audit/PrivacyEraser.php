<?php
/**
 * WordPress personal data eraser.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Audit;

use XNetVN\AgentHubWP\Contracts\PrivacyStore;
use XNetVN\AgentHubWP\Contracts\ServiceProvider;

/** Repeatedly erases the first remaining batch, regardless of the page number. */
final class PrivacyEraser implements ServiceProvider {
	/**
	 * Bind owner-specific erasure.
	 *
	 * @param PrivacyStore $store Persistence.
	 */
	public function __construct( private PrivacyStore $store ) {
	}

	/** Register the standard eraser filter. */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Add the callback without replacing other erasers.
	 *
	 * @param array<string,mixed> $erasers Existing erasers.
	 * @return array<string,mixed>
	 */
	public function erasers( array $erasers ): array {
		$erasers['xnetvn-agent-hub-wp'] = array(
			'eraser_friendly_name' => __( 'Agent Hub keys and activity', 'xnetvn-agent-hub-wp' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Delete keys and anonymize audit after WordPress has confirmed the request.
	 *
	 * @param string $email_address Confirmed email.
	 * @param int    $page Core page; unused to avoid deletion offset skips.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<string>,done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email_address );
		$removed = false;
		$done    = true;
		if ( false !== $user ) {
			$result  = $this->store->erase_for_user( $user->ID );
			$removed = $result['removed'];
			$done    = $result['done'];
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}
}
