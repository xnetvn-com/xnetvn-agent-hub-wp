<?php
/**
 * Internal prevalidated-target transport seam for deterministic network acceptance.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

use WP_Error;
use XNetVN\AgentHubWP\Domain\DownloadReply;

/** Never exposed as an agent tool or configurable trust override.
 *
 * @internal
 */
interface DownloadTransport {
	/**
	 * Whether native HTTPS pinning and trust roots are available.
	 *
	 * @return bool
	 */
	public function available(): bool;
	/**
	 * Download one hop; the caller owns policy, redirects and temporary cleanup.
	 *
	 * @param array{url:non-empty-string,host:string,ip:string} $target Validated target.
	 * @param string                                            $file Core-owned temporary file.
	 * @param int                                               $maximum Body byte ceiling.
	 * @param float                                             $timeout Remaining seconds.
	 * @return DownloadReply|WP_Error
	 */
	public function request( array $target, string $file, int $maximum, float $timeout ): DownloadReply|WP_Error;
}
