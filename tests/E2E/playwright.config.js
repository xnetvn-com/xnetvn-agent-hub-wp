const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: '.',
	globalSetup: require.resolve('./global-setup.js'),
	testMatch: '*.spec.js',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: Boolean(process.env.CI),
	reporter: [['list']],
	outputDir: '../../.local/execution/browser-artifacts',
	use: {
		baseURL: process.env.XNETVN_TEST_URL || 'http://localhost:8889',
		// Credential-management responses must not be captured in traces, videos or screenshots.
		trace: 'off',
		screenshot: 'off',
		video: 'off',
		actionTimeout: 10000,
		navigationTimeout: 30000,
		launchOptions: { executablePath: process.env.XNETVN_CHROME_PATH || '/usr/bin/google-chrome' },
	},
});
