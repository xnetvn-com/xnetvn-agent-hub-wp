<?php
/**
 * Header-only bearer credential extraction.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Auth;

use XNetVN\AgentHubWP\Contracts\SiteSettings;

/** Never reads query-string, cookie or request-body authentication values. */
final class HeaderExtractor {
	/**
	 * Bind the optional fallback header policy.
	 *
	 * @param SiteSettings $settings Site settings.
	 */
	public function __construct( private SiteSettings $settings ) {
	}
	/**
	 * Determine if a credential header was supplied.
	 *
	 * @return bool
	 */
	public function present(): bool {
		return null !== $this->raw();
	}
	/**
	 * Return the token from an exact Bearer scheme, or null.
	 *
	 * @return string|null
	 */
	public function bearer(): ?string {
		$raw = $this->raw();
		if ( null === $raw || 1 !== preg_match( '/\ABearer[ \t]+([^\r\n]+)\z/i', $raw, $match ) ) {
			return null;
		}
		return $match[1];
	}
	/**
	 * Read one prioritized header without retaining it in request state.
	 *
	 * @return string|null
	 */
	private function raw(): ?string {
		$names = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );
		if ( true === $this->settings->get( 'fallback_header_enabled' ) ) {
			$names[] = 'HTTP_X_XNETVN_API_KEY';
		}
		foreach ( $names as $name ) {
			if ( isset( $_SERVER[ $name ] ) ) {
				if ( '' === $_SERVER[ $name ] ) {
					continue;
				}
				return is_string( $_SERVER[ $name ] ) ? $_SERVER[ $name ] : '';
			}
		}
		return null;
	}
}
