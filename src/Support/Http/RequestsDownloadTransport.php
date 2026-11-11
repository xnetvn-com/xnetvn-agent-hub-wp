<?php
/**
 * Request-private pinned cURL transport using the installed core Requests API.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

use WP_Error;
use XNetVN\AgentHubWP\Contracts\DownloadTransport;
use XNetVN\AgentHubWP\Domain\DownloadReply;
use XNetVN\AgentHubWP\Abilities\ReasonMapper as R;

/** Policy must approve every target before invoking this transport.
 *
 * @internal
 */
final class RequestsDownloadTransport implements DownloadTransport {
	/**
	 * Bind an internal trust file; normal product wiring always uses core CA roots.
	 *
	 * @param string|null $trust_file Explicit owned test trust roots, never an MCP argument.
	 */
	public function __construct( private ?string $trust_file = null ) {}
	/**
	 * Resolve readable trust roots without accepting a caller-supplied URL.
	 *
	 * @return non-empty-string|null
	 */
	private function trust(): ?string {
		$file = $this->trust_file;
		if ( null === $file ) {
			$includes = defined( 'WPINC' ) ? constant( 'WPINC' ) : null;
			if ( ! is_string( $includes ) || ! defined( 'ABSPATH' ) ) {
				return null; }
			$file = ABSPATH . $includes . '/certificates/ca-bundle.crt';
		}
		return '' !== $file && is_file( $file ) && is_readable( $file ) ? $file : null;
	}
	/**
	 * Require all native functions/constants needed before any transmission.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return function_exists( 'curl_init' ) && function_exists( 'curl_exec' ) && function_exists( 'curl_getinfo' ) && function_exists( 'curl_setopt_array' ) && defined( 'CURLOPT_XFERINFOFUNCTION' ) && defined( 'CURLOPT_RESOLVE' ) && defined( 'CURLOPT_HTTP_CONTENT_DECODING' ) && defined( 'CURLOPT_CONNECT_TO' ) && defined( 'CURLPROTO_HTTPS' ) && class_exists( '\WpOrg\Requests\Transport\Curl' ) && class_exists( '\WpOrg\Requests\Hooks' ) && null !== $this->trust();
	}
	/**
	 * Execute one pinned, credential-free HTTPS hop with an immediate body abort.
	 *
	 * @param array{url:non-empty-string,host:string,ip:string} $target Prevalidated target.
	 * @param string                                            $file Owned temporary file.
	 * @param int                                               $maximum Maximum bytes.
	 * @param float                                             $timeout Remaining deadline.
	 * @return DownloadReply|WP_Error
	 */
	public function request( array $target, string $file, int $maximum, float $timeout ): DownloadReply|WP_Error {
		$ca = $this->trust();
		if ( ! $this->available() || null === $ca ) {
			return R::error( 'remote_fetch_disabled' ); }
		if ( $maximum < 1 || $maximum > 67108864 || $timeout <= 0 || $timeout > 30 ) {
			return R::error( 'invalid_params' ); }
		$state  = new RemoteRequestState();
		$hooks  = new \WpOrg\Requests\Hooks();
		$budget = new DownloadBudget( $maximum );
		$hooks->register(
			'curl.before_send',
			static function ( \CurlHandle $handle ) use ( $target, $timeout, $ca, $state, $budget ): void {
				$address = str_contains( $target['ip'], ':' ) ? '[' . $target['ip'] . ']' : $target['ip'];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Request-private core cURL transport; pre-send destination, IP, TLS and decompression controls.
				$ok = curl_setopt_array(
					$handle,
					array(
						CURLOPT_URL                   => $target['url'],
						CURLOPT_CONNECT_TO            => array(),
						CURLOPT_RESOLVE               => array( $target['host'] . ':443:' . $address ),
						CURLOPT_PROTOCOLS             => CURLPROTO_HTTPS,
						CURLOPT_REDIR_PROTOCOLS       => CURLPROTO_HTTPS,
						CURLOPT_FOLLOWLOCATION        => false,
						CURLOPT_PROXY                 => '',
						CURLOPT_NOPROXY               => '*',
						CURLOPT_SSL_VERIFYPEER        => true,
						CURLOPT_SSL_VERIFYHOST        => 2,
						CURLOPT_CAINFO                => $ca,
						CURLOPT_HTTPHEADER            => array( 'Accept-Encoding: identity' ),
						CURLOPT_HTTPAUTH              => CURLAUTH_NONE,
						CURLOPT_HTTPGET               => true,
						CURLOPT_HTTP_CONTENT_DECODING => false,
						CURLOPT_NOPROGRESS            => false,
						CURLOPT_XFERINFOFUNCTION      => static function ( \CurlHandle $curl, int $total, int $received ) use ( $budget ): int {
							return $budget->transfer( $received );
						},
						CURLOPT_TIMEOUT_MS            => max( 1, (int) ( $timeout * 1000 ) ),
						CURLOPT_CONNECTTIMEOUT_MS     => max( 1, (int) ( $timeout * 1000 ) ),
						CURLOPT_USERAGENT             => 'xNetVN-Agent-Hub-Media/0.1',
					)
				);
				if ( ! $ok ) {
					throw new \RuntimeException( 'Remote transport unavailable.' ); }
				$state->pinned = true;
			}
		);
		try {
			$response = \WpOrg\Requests\Requests::request(
				$target['url'],
				array( 'Accept-Encoding' => 'identity' ),
				array(),
				'GET',
				array(
					'transport'        => new \WpOrg\Requests\Transport\Curl(),
					'hooks'            => $hooks,
					'filename'         => $file,
					'max_bytes'        => $maximum,
					'timeout'          => $timeout,
					'connect_timeout'  => $timeout,
					'follow_redirects' => false,
					'auth'             => false,
					'proxy'            => false,
					'cookies'          => false,
					'verify'           => $ca,
					'verifyname'       => true,
					'useragent'        => 'xNetVN-Agent-Hub-Media/0.1',
				)
			);
			if ( ! $state->pinned ) {
				return R::error( 'remote_fetch_disabled' ); }
			if ( ! is_int( $response->status_code ) ) {
				return R::error( 'remote_fetch_failed' ); }
			$location = $response->headers['location'] ?? null;
			$encoding = $response->headers['content-encoding'] ?? null;
			$encoded  = null !== $encoding && ( ! is_string( $encoding ) || ! in_array( strtolower( trim( $encoding ) ), array( '', 'identity' ), true ) );
			return new DownloadReply( $response->status_code, is_string( $location ) ? $location : null, $encoded );
		} catch ( \Throwable ) {
			if ( $budget->exceeded() ) {
				return R::error( 'payload_too_large' ); }
			return R::error( $state->pinned ? 'remote_fetch_failed' : 'remote_fetch_disabled' );
		}
	}
}
