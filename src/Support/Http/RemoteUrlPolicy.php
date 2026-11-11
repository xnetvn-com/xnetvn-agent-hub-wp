<?php
/**
 * HTTPS hostname and complete DNS-answer validation.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

use XNetVN\AgentHubWP\Contracts\DnsResolver;
/** Produces a specific address to pin, never a permission to resolve again. */
final class RemoteUrlPolicy {
	/**
	 * Bind DNS explicitly for deterministic all-answer testing.
	 *
	 * @param DnsResolver $dns Resolver.
	 */
	public function __construct( private DnsResolver $dns ) {}
	/**
	 * Validate without network transmission.
	 *
	 * @param string        $url Candidate URL.
	 * @param array<string> $own_hosts Site hostnames.
	 * @return array{url:non-empty-string,host:string,ip:string}
	 * @throws \InvalidArgumentException For excessive length or control characters.
	 * @throws \DomainException For uniformly refused policy cases.
	 */
	public function resolve( string $url, array $own_hosts ): array {
		if ( strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			throw new \InvalidArgumentException( 'Invalid remote URL.' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure policy uses absolute HTTPS URLs only and has no WordPress dependency.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! isset( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			throw new \DomainException( 'Remote resource refused.' ); }
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( 1 === preg_match( '/[^\x00-\x7f]/', $host ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				throw new \DomainException( 'Remote resource refused.' ); }
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii ) {
				throw new \DomainException( 'Remote resource refused.' ); }
			$host = strtolower( $ascii );
		}
		if ( strlen( $host ) > 253 || false !== filter_var( $host, FILTER_VALIDATE_IP ) || 1 !== preg_match( '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/', $host ) ) {
			throw new \DomainException( 'Remote resource refused.' ); }
		foreach ( array( 'localhost', 'local', 'internal', 'lan', 'home.arpa' ) as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				throw new \DomainException( 'Remote resource refused.' ); }
		}
		foreach ( $own_hosts as $own ) {
			if ( strtolower( rtrim( $own, '.' ) ) === $host ) {
				throw new \DomainException( 'Remote resource refused.' ); }
		}
		$addresses = $this->dns->addresses( $host );
		if ( ! $addresses || count( $addresses ) > 16 ) {
			throw new \DomainException( 'Remote resource refused.' ); }
		foreach ( $addresses as $ip ) {
			if ( ! PublicAddressGuard::allows( $ip ) ) {
				throw new \DomainException( 'Remote resource refused.' ); }
		}
		$normalized = 'https://' . $host . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return array(
			'url'  => $normalized,
			'host' => $host,
			'ip'   => $addresses[0],
		);
	}
}
