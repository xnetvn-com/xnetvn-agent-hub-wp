const { test, expect } = require('@playwright/test');
const { loginAs, loginFresh, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

const EXPECTED_ROWS = Number(process.env.UI_FIXTURE_ROWS || '21');
const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
// Resolve the lab site name from the configured base URL so a missing
// prerequisite message names the exact site this suite ran against.
const LAB_SITE = (() => {
	const match = /-(wp[0-9]+)\./.exec(new URL(process.env.BASE_URL || 'https://xnetvn-agent-hub-wp-wp69.vm-2-215.local').hostname);
	return match ? match[1] : 'wp69';
})();

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

/**
 * Query owned key metadata through the published management API with the page
 * nonce. Only fixture-owned rows (by name marker) are counted, so parallel
 * suites on the shared lab cannot pollute the assertions. Never returns
 * secrets: only totals and per-owner active counts.
 */
async function keyTotals(page, query) {
	return page.evaluate(async search => {
		const response = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys?${search}`, {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const body = await response.json();
		const owned = body.filter(item => typeof item.name === 'string' && item.name.startsWith('xnetvn-ui-fx-'));
		const activeByOwner = {};
		for (const item of owned) {
			if (item.status === 'active') {
				activeByOwner[item.user_id] = (activeByOwner[item.user_id] || 0) + 1;
			}
		}
		return { status: response.status, total: owned.length, activeByOwner };
	}, query);
}

test('UI-FX-01 fixture actors log in with separate ownership and quota', async ({ page }) => {
	await loginAs(page, 'administrator');
	const hub = await hubFor(page, 'keys');
	await expect(hub.locator('[data-create]')).toBeVisible();
	const totals = await keyTotals(page, 'all=true&per_page=100&state=all');
	expect(totals.status).toBe(200);
	expect(totals.total).toBe(EXPECTED_ROWS);
	for (const [owner, active] of Object.entries(totals.activeByOwner)) {
		expect(active, `active keys for owner ${owner}`).toBeLessThanOrEqual(10);
	}

	await loginFresh(page, 'editor');
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('#user_login')).toHaveValue('xnetvn_ui_editor');
	const profile = await hubFor(page, 'profile');
	await expect(profile).toBeVisible();
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp');
	await expect(page.locator('body')).toContainText('Sorry, you are not allowed to access this page.');

	await loginFresh(page, 'editor_b');
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('#user_login')).toHaveValue('xnetvn_ui_editor_b');
	await expect(await hubFor(page, 'profile')).toBeVisible();

	await loginFresh(page, 'subscriber');
	await expect(page.locator('#wp-admin-bar-my-account')).toContainText('xnetvn_ui_subscriber');
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
});

test('UI-FX-02 settings change restores the exact prior value', async ({ page }) => {
	await loginAs(page, 'administrator');
	const form = await hubFor(page, 'settings');
	const field = form.locator('#xnetvn-setting-media_remote_timeout_seconds');
	const original = await field.inputValue();
	const next = original === '29' ? '28' : '29';
	await field.fill(next);
	await form.getByRole('button', { name: 'Save settings' }).click();
	// The save posts back to this page with settings-updated=true; wait for that
	// navigation so it is settled before the reload and the next login.
	await page.waitForURL(url => url.searchParams.get('settings-updated') === 'true');
	await expect(field).toHaveValue(next);
	await page.reload();
	await expect(page.locator('#xnetvn-setting-media_remote_timeout_seconds')).toHaveValue(next);
	// Reopen the clean settings URL so the second save has its own
	// settings-updated=true navigation to wait for.
	const restoredForm = await hubFor(page, 'settings');
	await restoredForm.locator('#xnetvn-setting-media_remote_timeout_seconds').fill(original);
	await restoredForm.getByRole('button', { name: 'Save settings' }).click();
	await page.waitForURL(url => url.searchParams.get('settings-updated') === 'true');
	await expect(page.locator('#xnetvn-setting-media_remote_timeout_seconds')).toHaveValue(original);

	await loginFresh(page, 'subscriber');
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings');
	await expect(page.locator('body')).toContainText('Sorry, you are not allowed to access this page.');

	await loginFresh(page, 'administrator');
	const restored = await hubFor(page, 'settings');
	await expect(restored.locator('#xnetvn-setting-media_remote_timeout_seconds')).toHaveValue(original);
});

test.describe('UI-FX-03 hub views load on this lab site without console errors', () => {
	test.use({ viewport: { width: 1440, height: 900 } });
	for (const view of ['overview', 'keys', 'activity', 'tools', 'settings', 'help', 'profile', 'user-edit']) {
		test(`view ${view}`, async ({ page }) => {
			const errors = [];
			page.on('pageerror', error => errors.push(error.message));
			await loginAs(page, 'administrator');
			await hubFor(page, view);
			expect(errors).toEqual([]);
		});
	}
});

test.describe('UI-FX-03 overview reflows at 390px', () => {
	test.use({ viewport: { width: 390, height: 844 } });
	test('overview reflows at 390px', async ({ page }) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await loginAs(page, 'administrator');
		const hub = await hubFor(page, 'overview');
		await expect(hub.locator('[data-check-header]')).toBeVisible();
		expect(errors).toEqual([]);
	});
});

test('UI-FX-04 dataset rows match the prepared scenario and artifacts stay safe', async ({ page }) => {
	await loginAs(page, 'administrator');
	await hubFor(page, 'keys');
	const all = await keyTotals(page, 'all=true&per_page=100&state=all');
	// Runner flow is snapshot, pagination 21, seed, notice, e2e, restore: only
	// `pagination 21` creates the 21 owned rows, so fail fast when it is missing.
	if (all.status !== 200 || all.total === 0) {
		throw new Error(`UI fixture dataset missing (expected 21 owned rows, found ${all.total}). Run the fixture scenarios first: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh ${LAB_SITE} eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include then the same command with pagination 21`);
	}
	const active = await keyTotals(page, 'all=true&per_page=100&state=active');
	const expired = await keyTotals(page, 'all=true&per_page=100&state=expired');
	const revoked = await keyTotals(page, 'all=true&per_page=100&state=revoked');
	expect(all.status).toBe(200);
	expect(all.total).toBe(EXPECTED_ROWS);
	expect(active.total + expired.total + revoked.total).toBe(EXPECTED_ROWS);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});
