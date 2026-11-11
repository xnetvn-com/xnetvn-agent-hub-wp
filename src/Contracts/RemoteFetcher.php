<?php
/**
 * Bounded untrusted HTTPS resource retrieval.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Contracts;

use WP_Error;
use XNetVN\AgentHubWP\Support\Http\FetchedFile;
/** A successful result transfers temporary-file ownership to the caller. */
interface RemoteFetcher {
	/**
	 * Retrieve with explicit byte, time and redirect budgets.
	 *
	 * @param string $url Public candidate URL.
	 * @param int    $max Maximum bytes.
	 * @param int    $timeout Total connection budget in seconds.
	 * @param int    $redirects Maximum redirects.
	 * @return FetchedFile|WP_Error
	 */
	public function fetch( string $url, int $max, int $timeout, int $redirects ): FetchedFile|WP_Error;
	/**
	 * Whether the verified Requests/cURL pre-send boundary is available.
	 *
	 * @return bool
	 */
	public function available(): bool;
}
