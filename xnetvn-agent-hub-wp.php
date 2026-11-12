<?php
/**
 * Plugin Name: xNetVN Agent Hub WP
 * Plugin URI: https://github.com/xnetvn-com/xnetvn-agent-hub-wp
 * Description: A capability-scoped WordPress MCP server for AI agents with private abilities, per-key scope and audit logging.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: xNetVN
 * Author URI: https://xnetvn.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: xnetvn-agent-hub-wp
 * Domain Path: /languages
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'XNETVN_AGENT_HUB_WP_VERSION', '1.0.0' );
define( 'XNETVN_AGENT_HUB_WP_FILE', __FILE__ );
define( 'XNETVN_AGENT_HUB_WP_DIR', __DIR__ . '/' );

require_once __DIR__ . '/src/Bootstrap/Requirements.php';
require_once __DIR__ . '/src/Bootstrap/Notice.php';
require_once __DIR__ . '/src/Bootstrap/Loader.php';

\XNetVN\AgentHubWP\Bootstrap\Loader::run( __FILE__ );
