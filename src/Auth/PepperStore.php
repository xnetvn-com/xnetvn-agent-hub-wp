<?php
/**
 * Separate random non-autoloaded pepper storage.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Persistence\StorageException;

/** Atomic add/readback prevents competing initializers from choosing different peppers. */
final class PepperStore {
	/** Private per-site option. */
	public const OPTION = 'xnetvn_agent_hub_wp_pepper';
	/**
	 * Return the current validated pepper, creating it only if definitively missing.
	 *
	 * @return Pepper
	 * @throws StorageException For unavailable or corrupted material.
	 */
	public function current(): Pepper {
		$data = get_option( self::OPTION, null );
		if ( null === $data ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Persist random pepper bytes, never executable code.
			add_option(
				self::OPTION,
				array(
					'id'    => 1,
					'value' => base64_encode( random_bytes( 32 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode random bytes, never code.
				),
				'',
				false
			);
			$data = get_option( self::OPTION, null );
		}
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['value'] ) || ! is_int( $data['id'] ) || ! is_string( $data['value'] ) ) {
			throw new StorageException( 'Credential pepper is unavailable.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode a fixed-size random pepper, never code.
		$value = base64_decode( $data['value'], true );
		if ( false === $value || 32 !== strlen( $value ) ) {
			throw new StorageException( 'Credential pepper is unavailable.' );
		}
		return new Pepper( $data['id'], $value );
	}
}
