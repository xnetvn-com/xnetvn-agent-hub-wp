// Listing screenshot run: reuse the lab guard from the project E2E suite and
// point Playwright at this folder so the capture spec can live with its assets.
const base = require('../../tests/E2E/playwright.remote.config.js');

module.exports = Object.assign({}, base, {
	testDir: __dirname,
	testMatch: '*.spec.ts',
	projects: [{ name: 'chromium' }],
	outputDir: `${process.env.RTL_ARTIFACTS_DIR || '/artifacts'}/listing-results`,
});
