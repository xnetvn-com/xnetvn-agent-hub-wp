<?php
/**
 * Public HTTPS retrieval through a request-private core Requests transport.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\DnsResolver;
use XNetVN\AgentHubWP\Contracts\DownloadTransport;
use XNetVN\AgentHubWP\Contracts\RemoteFetcher;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;

/** Global WordPress HTTP hooks cannot rewrite this transport's validated destination. */
final class SafeRemoteFetcher implements RemoteFetcher {
	/** Private transport.
	 *
	 * @var DownloadTransport
	 */
	private DownloadTransport $transport;
	/** Site-level external HTTP policy.
	 *
	 * @var SiteHttpPolicy
	 */
	private SiteHttpPolicy $site_policy;
	/**
	 * Bind complete DNS resolution.
	 *
	 * @param DnsResolver            $dns Resolver.
	 * @param DownloadTransport|null $transport Internal transport dependency.
	 * @param SiteHttpPolicy|null    $site_policy Internal site policy dependency.
	 */
	public function __construct( private DnsResolver $dns, ?DownloadTransport $transport = null, ?SiteHttpPolicy $site_policy = null ) {
		$this->transport   = $transport ?? new RequestsDownloadTransport();
		$this->site_policy = $site_policy ?? SiteHttpPolicy::fromConstants(); }
	/**
	 * Require the installed core-bundled Requests API and cURL pinning constants.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->transport->available();
	}
	/**
	 * Retrieve a bounded file, repeating the complete policy for every redirect.
	 *
	 * @param string $url Candidate.
	 * @param int    $max Maximum bytes.
	 * @param int    $timeout Total connection deadline seconds.
	 * @param int    $redirects Maximum redirects.
	 * @return FetchedFile|WP_Error
	 */
	public function fetch( string $url, int $max, int $timeout, int $redirects ): FetchedFile|WP_Error {
		if ( ! $this->available() ) {
			return R::error( 'remote_fetch_disabled' ); }
		if ( $max < 1 || $max > 67108864 || $timeout < 3 || $timeout > 30 || $redirects < 0 || $redirects > 5 ) {
			return R::error( 'invalid_params' ); }
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$policy   = new RemoteUrlPolicy( $this->dns );
		$deadline = microtime( true ) + $timeout;
		$own      = array_filter( array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ), 'is_string' );
		for ( $step = 0; $step <= $redirects; ++$step ) {
			if ( ! $this->site_policy->allows( $url ) ) {
				return R::error( 'remote_fetch_disabled' ); }
			try {
				$target = $policy->resolve( $url, $own );
			} catch ( \InvalidArgumentException ) {
				return R::error( 0 === $step ? 'invalid_params' : 'remote_fetch_refused' );
			} catch ( \Throwable ) {
				return R::error( 'remote_fetch_refused' ); }
			try {
				$allowed = apply_filters( 'xnetvn_agent_hub_wp_remote_url_allowed', true, $target['url'] );
			} catch ( \Throwable ) {
				$allowed = false; }
			if ( true !== $allowed || false === wp_http_validate_url( $target['url'] ) ) {
				return R::error( 'remote_fetch_refused' ); }
			$remaining = $deadline - microtime( true );
			if ( $remaining <= 0 ) {
				return R::error( 'remote_fetch_failed' ); }
			$file = wp_tempnam( 'xnetvn-media' );
			if ( ! $file ) {
				return R::error( 'remote_fetch_failed' ); }
			$transfer = false;
			try {
				$response = $this->transport->request( $target, $file, $max, $remaining );
				if ( is_wp_error( $response ) ) {
					return R::sanitize( $response ); }

				clearstatcache( true, $file );
				$bytes = filesize( $file );
				if ( false === $bytes || $bytes > $max ) {
					return R::error( 'payload_too_large' ); }
				if ( in_array( $response->status, array( 301, 302, 303, 307, 308 ), true ) ) {
					$location = $response->location;
					if ( $step >= $redirects || ! is_string( $location ) || '' === $location ) {
						return R::error( 'remote_fetch_refused' ); }
					$url = \WP_Http::make_absolute_url( $location, $target['url'] );
					continue;
				}
				if ( 200 !== $response->status || $bytes < 1 || $response->encoded ) {
					return R::error( 'remote_fetch_failed' ); }
				$transfer = true;
				return new FetchedFile( $file, $max );
			} catch ( \Throwable ) {
				return R::error( 'remote_fetch_failed' );
			} finally {
				if ( ! $transfer ) {
					wp_delete_file( $file ); }
			}
		}
		return R::error( 'remote_fetch_refused' );
	}
}
