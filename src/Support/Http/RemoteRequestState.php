<?php
/**
 * Request-private proof of pre-send pinning.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

/** Shared only with this request's private Requests hook collection. */
final class RemoteRequestState {
	/** Whether cURL accepted every required security option before transmission.
	 *
	 * @var bool
	 */
	public bool $pinned = false;
}
