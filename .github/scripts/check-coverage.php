<?php
/**
 * CLI-only entrypoint for the standalone numeric coverage gate.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

require_once __DIR__ . '/CoverageGate.php';

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI exit status is an integer, not HTML output.
exit( \XNetVN\AgentHubWP\Ci\CoverageGate::main( $argv ) );
