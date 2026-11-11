<?php
/**
 * Site-level external HTTP blocking policy.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

/**
 * Site-level external HTTP blocking, applied before DNS resolution.
 *
 * Matches exact WP_ACCESSIBLE_HOSTS entries and leading '*.' subdomain
 * wildcards only. Core also accepts '*' elsewhere in an entry, which this
 * policy does not implement.
 *
 * @internal
 */
final class SiteHttpPolicy {
	/**
	 * Bind the site policy at construction.
	 *
	 * @param bool        $block_external   Whether WP_HTTP_BLOCK_EXTERNAL is truthy.
	 * @param string|null $accessible_hosts Comma-separated WP_ACCESSIBLE_HOSTS value.
	 */
	public function __construct( private bool $block_external, private ?string $accessible_hosts ) {}
	/**
	 * Read the site policy from its WordPress constants.
	 *
	 * @return self
	 */
	public static function fromConstants(): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fixed interface name from the approved task brief.
		$accessible = defined( 'WP_ACCESSIBLE_HOSTS' ) ? constant( 'WP_ACCESSIBLE_HOSTS' ) : null;
		return new self(
			defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && (bool) constant( 'WP_HTTP_BLOCK_EXTERNAL' ),
			is_string( $accessible ) ? $accessible : null
		);
	}
	/**
	 * Decide whether the site policy permits this URL.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public function allows( string $url ): bool {
		if ( ! $this->block_external ) {
			return true;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host || null === $this->accessible_hosts || '' === trim( $this->accessible_hosts ) ) {
			return false;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		foreach ( explode( ',', $this->accessible_hosts ) as $allowed ) {
			$allowed = strtolower( trim( $allowed ) );
			if ( '' === $allowed ) {
				continue;
			}
			if ( str_starts_with( $allowed, '*.' ) ) {
				$suffix = substr( $allowed, 2 );
				if ( '' !== $suffix && str_ends_with( $host, '.' . $suffix ) ) {
					return true;
				}
				continue;
			}
			if ( $host === $allowed ) {
				return true;
			}
		}
		return false;
	}
}
