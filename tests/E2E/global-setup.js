const { execFileSync } = require('node:child_process');

module.exports = async () => {
	if (!process.env.CI) {
		throw new Error('Run service and browser tests through remote-test-lab; local service startup is disabled.');
	}
	const target = new URL(process.env.XNETVN_TEST_URL || 'http://localhost:8889');
	if (!['localhost', '127.0.0.1', '[::1]'].includes(target.hostname)) {
		throw new Error('These credential lifecycle tests require a local wp-env site.');
	}
	const options = { cwd: require('node:path').resolve(__dirname, '../..'), timeout: 60000, env: Object.assign({}, process.env, { NODE_OPTIONS: '--no-network-family-autoselection' }), stdio: 'pipe' };
	// PHPUnit installs a fresh test database and leaves the browser site's plugins inactive.
	// Reconcile the test fixture explicitly before browser actions.
	execFileSync('npx', ['--no-install', 'wp-env', 'run', 'tests-cli', '--', 'wp', 'plugin', 'activate', 'mcp-adapter', 'xnetvn-agent-hub-wp'], options);
	execFileSync('npx', ['--no-install', 'wp-env', 'run', 'tests-cli', '--', 'wp', 'eval-file', 'wp-content/plugins/xnetvn-agent-hub-wp/tests/E2E/prepare.php', '--use-include'], options);
};
