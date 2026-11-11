<?php
/**
 * Minimal untrusted HTTP response metadata for remote-fetch orchestration.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Domain;

/** No provider body, arbitrary headers or socket details cross this boundary.
 *
 * @internal
 */
final readonly class DownloadReply {
	/**
	 * Capture only status, redirect location and encoding state.
	 *
	 * @param int         $status HTTP status.
	 * @param string|null $location Untrusted redirect target.
	 * @param bool        $encoded Whether content requires decoding.
	 * @throws \InvalidArgumentException For an invalid status.
	 */
	public function __construct( public int $status, public ?string $location, public bool $encoded ) {
		if ( $status < 100 || $status > 599 ) {
			throw new \InvalidArgumentException( 'Invalid remote response.' ); }
	}
}
