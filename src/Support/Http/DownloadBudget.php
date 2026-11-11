<?php
/**
 * Abort native cURL transfers when the permitted body budget is exceeded.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);
namespace XNetVN\AgentHubWP\Support\Http;

/** Native max_bytes bounds storage; this callback also stops consumption. */
final class DownloadBudget {
	/** Whether a native progress callback exceeded the reviewed limit.
	 *
	 * @var bool
	 */
	private bool $overflow = false;
	/**
	 * Bind the exact raw-body byte budget.
	 *
	 * @param int $maximum Byte ceiling.
	 * @throws \InvalidArgumentException For an invalid ceiling.
	 */
	public function __construct( private int $maximum ) {
		if ( $maximum < 1 || $maximum > 67108864 ) {
			throw new \InvalidArgumentException( 'Invalid download budget.' ); }
	}
	/**
	 * Distinguish intentional budget abort from an arbitrary network failure.
	 *
	 * @return bool
	 */
	public function exceeded(): bool {
		return $this->overflow; }
	/**
	 * Return the native cURL abort code; PHP callback exceptions may be deferred.
	 *
	 * @param int $received Total body bytes received by the native transfer.
	 * @return int Zero to continue, one to stop without Requests write-error retries.
	 */
	public function transfer( int $received ): int {
		$this->overflow = $this->overflow || $received < 0 || $received > $this->maximum;
		return $this->overflow ? 1 : 0;
	}
}
