const { defineConfig } = require('@playwright/test');
const base = require('./playwright.config');

if (!process.env.RTL_ARTIFACTS_DIR || !process.env.BASE_URL) {
	throw new Error('Run this configuration through remote-test-lab e2e.');
}
const testEnvironment = Object.fromEntries(require('node:fs').readFileSync('/work/.env', 'utf8').split(/\r?\n/).filter(line => /^[A-Z_0-9]+=/.test(line)).map(line => { const offset = line.indexOf('='); return [line.slice(0, offset), line.slice(offset + 1)]; }));
// Test-site whitelist: the two single-site labs plus the optional multisite
// network lab. Only host names configured in the lab environment are accepted;
// nothing is added from the incoming request, so the whitelist never widens.
const allowedHosts = ['RTL_DOMAIN_WP69', 'RTL_ALIAS_WP69', 'RTL_DOMAIN_WP71', 'RTL_ALIAS_WP71', 'RTL_DOMAIN_WPNETWORK', 'RTL_ALIAS_WPNETWORK'].map(name => testEnvironment[name]).filter(Boolean);
if (!allowedHosts.includes(new URL(process.env.BASE_URL).hostname)) {
	throw new Error('The remote browser suite is limited to this project lab sites.');
}
if (!process.env.XNETVN_TEST_PASSWORD) {
	if (!testEnvironment.RTL_ADMIN_PASSWORD) throw new Error('The test-only administrator password is unavailable.');
	process.env.XNETVN_TEST_PASSWORD = testEnvironment.RTL_ADMIN_PASSWORD;
}

module.exports = defineConfig(Object.assign({}, base, {
	globalSetup: undefined,
	outputDir: process.env.RTL_ARTIFACTS_DIR || '../../.local/execution/browser-artifacts',
	// Acceptance runs every browser engine in turn: one worker, no parallel
	// projects and no retries, so a failure is always a real regression.
	workers: 1,
	fullyParallel: false,
	retries: 0,
	// No executablePath here: the stock Playwright runner image carries all
	// three engines, and a Chrome-only path must not apply to Firefox/WebKit.
	projects: [
		{ name: 'chromium' },
		{ name: 'firefox' },
		{ name: 'webkit' },
	],
	use: Object.assign({}, base.use, {
		baseURL: process.env.BASE_URL,
		ignoreHTTPSErrors: true,
		launchOptions: {},
	}),
}));
