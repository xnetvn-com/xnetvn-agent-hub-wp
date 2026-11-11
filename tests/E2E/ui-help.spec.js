const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-HP help connection guide and troubleshooting.
 *
 * Runner flow (lab shell, not the browser), in this exact order:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-HP'
 * 3. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * The help page is static guidance: the browser never sends a real key and no
 * response value is ever asserted as a credential. The fixture password
 * travels only in the login form, never in an assertion, screenshot or
 * artifact scan. Samples name the MCP_API_KEY variable or REDACTED, never a
 * value, so the artifact scan stays clean.
 */

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const KEYS_SUFFIX = 'page=xnetvn-agent-hub-wp-keys';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

test('UI-HP-01 administrator follows the four guided steps and copies the endpoint', async ({ page, context }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await context.grantPermissions(['clipboard-read', 'clipboard-write']);
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'help');
	const steps = root.locator('[data-help-steps]');
	await expect(steps).toBeVisible();
	expect(await steps.locator(':scope > li').count()).toBe(4);
	const profileHref = await steps.locator('a[href$="profile.php"]').first().getAttribute('href');
	expect(profileHref, 'profile link target').toMatch(/profile\.php$/);
	expect(profileHref, 'profile link carries no credential').not.toMatch(/nonce|token|key=/i);
	const keysHref = await steps.locator(`a[href*="${KEYS_SUFFIX}"]`).first().getAttribute('href');
	expect(keysHref, 'keys link target').toContain(KEYS_SUFFIX);
	const overviewHref = await steps.locator('a[href$="page=xnetvn-agent-hub-wp"]').first().getAttribute('href');
	expect(overviewHref, 'overview link target').toMatch(/page=xnetvn-agent-hub-wp$/);
	expect(overviewHref, 'overview link carries no credential').not.toMatch(/nonce|token|key=/i);
	const endpoint = root.locator('#xnetvn-help-endpoint');
	await expect(endpoint).toBeVisible();
	expect(await endpoint.getAttribute('readonly')).not.toBeNull();
	const endpointValue = await endpoint.inputValue();
	expect(endpointValue, 'endpoint value is the MCP route').toContain('/xnetvn-agent-hub-wp/mcp');
	await root.locator('[data-copy-endpoint]').click();
	await expect(root.locator('[data-help-status]')).toContainText('Copied.');
	const clipboardValue = await page.evaluate(() => navigator.clipboard.readText());
	expect(clipboardValue, 'clipboard holds the endpoint, never a secret').toBe(endpointValue);
	const sample = await root.locator('[data-help-sample]').textContent();
	expect(sample, 'sample names the key variable').toMatch(/MCP_API_KEY/);
	expect(sample, 'sample never carries a value').not.toMatch(/xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}/);
	expect(sample, 'sample never carries a token').not.toMatch(/Bearer\s+[A-Za-z0-9._~-]{8,}/);
	for (const code of ['401', '403', '429', '503']) {
		await expect(root.locator(`[data-help-issue="${code}"]`), `troubleshooting names ${code}`).toBeVisible();
	}
	expect(await root.locator('form').count(), 'guidance never posts').toBe(0);
	expect(await root.locator('a[download], button:has-text("Send"), a:has-text("Download")').count(), 'no support submit or config download').toBe(0);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-HP-02 clipboard denial selects the endpoint without a script error', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	// Simulate an unavailable Clipboard API so the fallback path must select
	// the readonly input instead of throwing.
	await page.addInitScript(() => {
		try {
			delete window.navigator.clipboard;
		} catch (deletionError) {
			Object.defineProperty(window.navigator, 'clipboard', { value: undefined, configurable: true });
		}
	});
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'help');
	const endpoint = root.locator('#xnetvn-help-endpoint');
	await expect(endpoint).toBeVisible();
	await root.locator('[data-copy-endpoint]').click();
	await expect(root.locator('[data-help-status]')).toContainText('Copy was unavailable');
	const selection = await endpoint.evaluate(node => ({
		focused: document.activeElement === node,
		start: node.selectionStart,
		end: node.selectionEnd,
		length: node.value.length,
	}));
	expect(selection.focused, 'fallback focuses the endpoint input').toBe(true);
	expect(selection.start, 'fallback selects from the first character').toBe(0);
	expect(selection.end, 'fallback selects the whole endpoint').toBe(selection.length);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-HP-03 troubleshooting opens by keyboard and separates management from MCP actions', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'help');
	const expectations = {
		401: [/reload/i, /MCP|Bearer/i],
		403: [/capabilit|nonce/i, /scope|MCP/i],
		429: [/wait|minute|limit/i, /retr/i],
		503: [/later|status|service/i, /read|MCP/i],
	};
	for (const [code, patterns] of Object.entries(expectations)) {
		const issue = root.locator(`[data-help-issue="${code}"]`);
		const summary = issue.locator('summary').first();
		await summary.focus();
		await expect(summary).toBeFocused();
		await page.keyboard.press('Enter');
		await expect(issue).toHaveAttribute('open', /.*/);
		const text = await issue.textContent();
		for (const pattern of patterns) {
			expect(text, `${code} names its action`).toMatch(pattern);
		}
		expect(text, `${code} carries no credential`).not.toMatch(/xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}/);
	}
	const revocationLink = root.locator(`a[href*="${KEYS_SUFFIX}"]`).first();
	expect(await revocationLink.getAttribute('href'), 'emergency revocation links the keys page').toContain(KEYS_SUFFIX);
	await expect(root.locator('a[href^="mailto:security@xnetvn.net"]'), 'private reports stay off the page').toBeVisible();
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-HP-04 help reflows, keeps keyboard focus and meets contrast', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		const root = await hubFor(page, 'help');
		await expect(root.locator('[data-help-steps]')).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	const narrow = await hubFor(page, 'help');
	await expect(narrow.locator('[data-help-steps]')).toBeVisible();
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.setViewportSize({ width: 1440, height: 900 });
	const root = await hubFor(page, 'help');
	await root.locator('[data-copy-endpoint]').focus();
	await expect(root.locator('[data-copy-endpoint]')).toBeFocused();
	const focused = await page.evaluate(() => {
		const active = document.activeElement;
		if (!active) {
			return { visible: false };
		}
		const rect = active.getBoundingClientRect();
		return { visible: rect.width > 0 && rect.height > 0 && rect.top >= 0 && rect.left >= 0 };
	});
	expect(focused.visible).toBe(true);
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});
