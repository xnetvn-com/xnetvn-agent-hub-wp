const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-OV overview getting started and status reading.
 *
 * Runner flow (lab shell, not the browser), in this exact order:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-OV'
 * 3. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * UI-OV-01 uses the real /status response on each site. UI-OV-02 and UI-OV-03
 * mock the status and diagnostics GETs for null fields, errors and presence
 * checks; the browser never sends a real key and no response value is ever
 * asserted as a credential. The fixture password travels only in the login
 * form, never in an assertion, screenshot or artifact scan.
 */

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const HELP_SUFFIX = 'page=xnetvn-agent-hub-wp-help';
const KEYS_SUFFIX = 'page=xnetvn-agent-hub-wp-keys';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

test('UI-OV-01 administrator follows getting started and copies the endpoint', async ({ page, context }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await context.grantPermissions(['clipboard-read', 'clipboard-write']);
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'overview');
	const steps = root.locator('[data-getting-started]');
	await expect(steps).toBeVisible();
	expect(await steps.locator(':scope > li').count()).toBe(3);
	const profileHref = await steps.locator('a[href$="profile.php"]').first().getAttribute('href');
	expect(profileHref, 'profile link target').toMatch(/profile\.php$/);
	expect(profileHref, 'profile link carries no credential').not.toMatch(/nonce|token|key=/i);
	const keysHref = await steps.locator(`a[href*="${KEYS_SUFFIX}"]`).first().getAttribute('href');
	expect(keysHref, 'keys link target').toContain(KEYS_SUFFIX);
	const helpHref = await steps.locator(`a[href*="${HELP_SUFFIX}"]`).first().getAttribute('href');
	expect(helpHref, 'help link target').toContain(HELP_SUFFIX);
	const endpoint = root.locator('#xnetvn-endpoint');
	await expect(endpoint).toBeVisible();
	expect(await endpoint.getAttribute('readonly')).not.toBeNull();
	const endpointValue = await endpoint.inputValue();
	expect(endpointValue, 'endpoint value is the MCP route').toContain('/xnetvn-agent-hub-wp/mcp');
	await root.locator('[data-copy-endpoint]').click();
	await expect(root.locator('.xnetvn-status')).toContainText('Copied.');
	const clipboardValue = await page.evaluate(() => navigator.clipboard.readText());
	expect(clipboardValue, 'clipboard holds the endpoint, never a secret').toBe(endpointValue);
	// The real /status response feeds both groups on a healthy site.
	await expect(root.locator('[data-status-connection]')).toContainText('HTTPS');
	await expect(root.locator('[data-status-site]')).toContainText('Registered abilities');
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-OV-01 clipboard denial selects the endpoint without a script error', async ({ page }) => {
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
	const root = await hubFor(page, 'overview');
	const endpoint = root.locator('#xnetvn-endpoint');
	await expect(endpoint).toBeVisible();
	await root.locator('[data-copy-endpoint]').click();
	await expect(root.locator('.xnetvn-status')).toContainText('Copy was unavailable');
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

test('UI-OV-02 missing fields read Unavailable and an error recovers with retry', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	let statusCalls = 0;
	await page.route('**/xnetvn-agent-hub-wp/v1/status**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		statusCalls += 1;
		if (statusCalls === 1) {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					endpoint: 'https://example.invalid/wp-json/xnetvn-agent-hub-wp/mcp',
					https: null,
					transport_allowed: false,
					adapter: {},
					ability_count: null,
					key_count: null,
					db_version: null,
					audit_cron_next: '2026-11-01T00:00:00+00:00',
				}),
			});
			return;
		}
		if (statusCalls === 2) {
			await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
			return;
		}
		await route.continue();
	});
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'overview');
	const connection = root.locator('[data-status-connection]');
	const site = root.locator('[data-status-site]');
	await expect(connection).toContainText('Unavailable');
	await expect(connection).toContainText('No');
	await expect(site).toContainText('Unavailable');
	const connectionText = await connection.textContent();
	expect(connectionText, 'no invented healthy state').not.toMatch(/Healthy/i);
	const siteText = await site.textContent();
	expect(siteText, 'no invented zero state').not.toMatch(/Healthy/i);
	// The cleanup time is localized, never the raw ISO string.
	expect(siteText, 'cleanup time keeps its year').toContain('2026');
	expect(siteText, 'cleanup time is localized').not.toContain('2026-11-01T00:00:00');
	// A connection that is not ready points at Help instead of self-repair.
	const helpLink = root.locator('[data-connection-help] a');
	await expect(helpLink).toBeVisible();
	expect(await helpLink.getAttribute('href'), 'connection help target').toContain(HELP_SUFFIX);
	// A failed refresh keeps the error region and recovers through Try again.
	await page.reload();
	const reloaded = page.locator('.xnetvn-agent-hub[data-view="overview"]');
	await expect(reloaded.locator('[data-error]')).toBeVisible();
	await expect(reloaded.locator('[data-retry]')).toContainText('Try again');
	await page.unroute('**/xnetvn-agent-hub-wp/v1/status**');
	await reloaded.locator('[data-retry]').click();
	await expect(reloaded.locator('[data-error]')).toBeHidden();
	await expect(reloaded.locator('[data-status-connection]')).toContainText('HTTPS');
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-OV-03 header diagnostic reports presence only with a single request', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'overview');
	const checkButton = root.locator('[data-check-header]');
	const result = root.locator('[data-header-result]');
	for (const present of [true, false]) {
		let diagnosticCalls = 0;
		let sentMarker = '';
		await page.route('**/xnetvn-agent-hub-wp/v1/diagnostics/auth-header**', async route => {
			diagnosticCalls += 1;
			sentMarker = String(route.request().headers()['authorization'] || '');
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					authorization_present: present,
					redirect_authorization_present: false,
					fallback_header_present: false,
				}),
			});
		});
		const previous = await result.textContent();
		await checkButton.click();
		await expect(result).not.toBeEmpty();
		await expect.poll(async () => result.textContent()).not.toBe(previous);
		const message = await result.textContent();
		if (present) {
			expect(message, 'presence message').toMatch(/received/i);
		} else {
			expect(message, 'absence message').toMatch(/not received/i);
		}
		expect(message, 'never claims key or MCP success').not.toMatch(/MCP|authenticated|valid|success|key/i);
		expect(sentMarker, 'diagnostic uses the fake marker').toBe('Bearer redacted');
		expect(diagnosticCalls, 'one diagnostic GET per click').toBe(1);
		await expect(checkButton).toBeEnabled();
		await page.unroute('**/xnetvn-agent-hub-wp/v1/diagnostics/auth-header**');
	}
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-OV-04 overview reflows, keeps keyboard focus and meets contrast', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		const root = await hubFor(page, 'overview');
		await expect(root.locator('[data-getting-started]')).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	const narrow = await hubFor(page, 'overview');
	await expect(narrow.locator('[data-getting-started]')).toBeVisible();
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.setViewportSize({ width: 1440, height: 900 });
	const root = await hubFor(page, 'overview');
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
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub[data-view="overview"]').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});
