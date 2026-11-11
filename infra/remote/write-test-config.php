<?php
/**
 * Build isolated core test configuration from test-only container environment.
 *
 * @package XNetVN\AgentHubWP
 */

declare(strict_types=1);

if ( '1' !== getenv( 'XNETVN_RTL' ) || ! in_array( $argv[1] ?? '', array( '6.9', '7.1.2' ), true ) ) {
	exit( 1 );
}
$xnetvn_agent_hub_wp_values = array(
	'DB_NAME' => getenv( 'WORDPRESS_DB_NAME' ),
	'DB_USER' => getenv( 'WORDPRESS_DB_USER' ),
	'DB_PASSWORD' => getenv( 'RTL_DB_PASSWORD' ),
	'DB_HOST' => getenv( 'WORDPRESS_DB_HOST' ),
	'DB_CHARSET' => 'utf8',
	'DB_COLLATE' => '',
	'ABSPATH' => getenv( 'XNETVN_RTL_SITE_PATH' ) . '/',
	'WP_TESTS_DOMAIN' => 'example.test',
	'WP_TESTS_EMAIL' => 'admin@example.test',
	'WP_TESTS_TITLE' => 'Isolated Agent Hub integration',
	'WP_PHP_BINARY' => PHP_BINARY,
	'WP_ENVIRONMENT_TYPE' => 'local',
	'XNETVN_AGENT_HUB_WP_ALLOW_INSECURE' => true,
);
$xnetvn_agent_hub_wp_content = "<?php\n";
foreach ( $xnetvn_agent_hub_wp_values as $xnetvn_agent_hub_wp_name => $xnetvn_agent_hub_wp_value ) {
	$xnetvn_agent_hub_wp_content .= 'define(' . var_export( $xnetvn_agent_hub_wp_name, true ) . ',' . var_export( $xnetvn_agent_hub_wp_value, true ) . ");\n";
}
$xnetvn_agent_hub_wp_content .= '$table_prefix = ' . var_export( getenv( 'XNETVN_RTL_TABLE_PREFIX' ), true ) . ";\n";
$xnetvn_agent_hub_wp_target = '/opt/wp-tests/' . $argv[1] . '/wp-tests-config.php';
if ( false === file_put_contents( $xnetvn_agent_hub_wp_target, $xnetvn_agent_hub_wp_content ) ) {
	exit( 1 );
}
chmod( $xnetvn_agent_hub_wp_target, 0600 );
