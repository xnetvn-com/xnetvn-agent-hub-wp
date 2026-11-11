<?php
/**
 * One failed reviewed comment condition.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

namespace XNetVN\AgentHubWP\Tests\E2E;

use RuntimeException;

/**
 * Carries a safe case id and reason without raw payloads. Blocked means the
 * run stopped before any unknown mutation.
 */
final class CommentsReviewFailure extends RuntimeException {

	/**
	 * Bind a safe case and reason.
	 *
	 * @param string $case_id Reviewed case.
	 * @param string $reason  Safe reason code.
	 * @param bool   $blocked Whether the run stopped before an unsafe mutation.
	 */
	public function __construct( public string $case_id, public string $reason, public bool $blocked = false ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed safe case and reason codes in a CLI-only test.
		parent::__construct( $case_id . ':' . $reason );
	}
}
