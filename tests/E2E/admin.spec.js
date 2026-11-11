const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

test.beforeEach(async ({ page }) => {
	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(process.env.XNETVN_TEST_LOGIN || 'admin');
	await page.locator('#user_pass').fill(process.env.XNETVN_TEST_PASSWORD || 'password');
	await page.locator('#wp-submit').click();
	await expect(page).toHaveURL(/wp-admin/);
});

test.afterEach(async ({ page }) => {
	// Playwright writes an accessibility snapshot on failures even with traces disabled.
	// Clear credential fields before fixture teardown can collect that snapshot.
	await page.evaluate(() => {
		document.querySelectorAll('[data-secret], input[type="password"]').forEach(input => { input.value = ''; });
		document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
	}).catch(() => {});
});

test('create, dismiss, reload, rotate and revoke with keyboard-safe secret clearing', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/wp-admin/profile.php');
	const hub = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(hub).toBeVisible();
	const name = `Browser fixture ${Date.now()}`;
	await hub.locator('[data-field="name"]').fill(name);
	await hub.locator('[data-field="expires_in_days"]').fill('2');
	await hub.locator('[data-create]').click();
	const dialog = hub.locator('[data-secret-dialog]');
	await expect(dialog).toBeVisible();
	const input = dialog.locator('[data-secret]');
	// Do not include credential values in assertion failure output or saved artifacts.
	expect(await input.evaluate(node => /^xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}$/.test(node.value))).toBe(true);
	await expect(input).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(dialog.locator('[data-copy]')).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(dialog.locator('[data-saved]')).toBeFocused();
	await page.keyboard.press('Enter');
	await expect(dialog).not.toBeVisible();
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	await expect(hub.locator('[data-create]')).toBeFocused();
	await page.reload();
	await expect(dialog).not.toBeVisible();
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	const row = hub.locator('[data-keys] tr').filter({ hasText: name });
	await expect(row).toBeVisible();
	await row.getByRole('button', { name: 'Rotate key' }).click();
	const action = hub.locator('[data-action-dialog]');
	await expect(action).toBeVisible();
	await action.getByRole('button', { name: 'Confirm', exact: true }).click();
	await expect(dialog).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(dialog).not.toBeVisible();
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	const rows = hub.locator('[data-keys] tr').filter({ hasText: name });
	await expect(rows).toHaveCount(2);
	for (let index = 0; index < 2; index += 1) {
		await rows.nth(index).getByRole('button', { name: 'Revoke key' }).click();
		await action.getByRole('button', { name: 'Confirm', exact: true }).click();
		await expect(rows.nth(index)).toContainText('revoked');
	}
	expect(errors).toEqual([]);
});

test('plugin profile controls have no automated WCAG A/AA violations', async ({ page }) => {
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('[data-create]')).toBeEnabled();
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
	expect(results.violations.map(violation => ({ id: violation.id, impact: violation.impact }))).toEqual([]);
});

test('overview, tools, activity, settings and help load without console exceptions', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	for (const view of ['', '-tools', '-activity', '-settings', '-help']) {
		await page.goto(`/wp-admin/admin.php?page=xnetvn-agent-hub-wp${view}`);
		await expect(page.locator('#wpbody-content h1')).toBeVisible();
	}
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp');
	await page.locator('[data-check-header]').click();
	await expect(page.locator('[data-header-result]')).toContainText('received');
	expect(errors).toEqual([]);
});

test('media settings expose bounded inputs and accessible labels', async ({ page }) => {
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings');
	await expect(page.getByLabel('Enable public HTTPS media downloads')).toBeVisible();
	for (const [name, min, max] of [
		['Remote media maximum bytes', '1024', '67108864'],
		['Data upload maximum bytes', '1024', '8388608'],
		['Remote media timeout seconds', '3', '30'],
		['Remote media maximum redirects', '0', '5'],
		['Maximum image pixels', '1000000', '100000000'],
	]) {
		const field = page.getByLabel(name, { exact: true });
		await expect(field).toHaveAttribute('min', min);
		await expect(field).toHaveAttribute('max', max);
	}
	const results = await new AxeBuilder({ page }).include('form.xnetvn-agent-hub').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
	expect(results.violations).toEqual([]);
});
