const { test, expect } = require('@playwright/test');
const { loginAs, installSecretCleanup } = require('./ui-helpers');

const KEYS_URL = '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

/** Synthetic key metadata for deterministic mutation tests. Never a secret. */
function syntheticRows(tag, count, ownerId) {
	const rows = [];
	for (let index = 0; index < count; index += 1) {
		rows.push({
			id: 7000 + index,
			name: `${tag} row ${index}`,
			key_id: `kid-${tag}-${index}`,
			user_id: ownerId,
			tier_max: 'read',
			toolsets: ['system'],
			created_at: '2026-10-01T00:00:00',
			last_used_at: null,
			expires_at: '2026-11-01T00:00:00',
			status: 'active',
		});
	}
	return rows;
}

function syntheticResult(tag) {
	return {
		key: {
			id: 7001,
			name: `${tag} synthetic`,
			key_id: `kid-${tag}-synthetic`,
			user_id: 2,
			tier_max: 'read',
			toolsets: ['system'],
			status: 'active',
		},
		secret: `SYNTHETIC-${tag}-VALUE`,
	};
}

/** Revoke the actor's own stale test keys. Counts only, never secrets. */
async function revokeOwnLeftovers(page) {
	return page.evaluate(async () => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const list = await (await fetch('/wp-json/xnetvn-agent-hub-wp/v1/keys?per_page=100', { headers: { 'X-WP-Nonce': nonce } })).json();
		let revoked = 0;
		for (const entry of list) {
			if (typeof entry.name === 'string' && entry.name.startsWith('UI-KY ') && entry.status === 'active') {
				const response = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${Number(entry.id)}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
				if (response.status === 200) {
					revoked += 1;
				}
			}
		}
		return revoked;
	});
}

/** Current fixture user id. Numbers only, never credentials. */
async function myId(page) {
	return page.evaluate(async () => {
		const response = await fetch('/wp-json/wp/v2/users/me', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const body = await response.json();
		return Number(body.id);
	});
}

test('UI-KY-01 management filters scope the listing and profiles lock the owner', async ({ page }) => {
	const seen = [];
	await page.route('**/xnetvn-agent-hub-wp/v1/keys**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		seen.push(Object.fromEntries(new URL(route.request().url()).searchParams.entries()));
		await route.continue();
	});
	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();

	const state = root.locator('[data-filter="state"]');
	await expect(state).toBeVisible();
	expect(await state.evaluate(node => Array.from(node.options).map(option => option.value))).toEqual(['all', 'active', 'expired', 'revoked']);
	await state.selectOption('revoked');
	await page.waitForResponse(response => response.url().includes('state=revoked') && response.request().method() === 'GET');
	const byState = seen[seen.length - 1];
	expect(byState.state).toBe('revoked');
	expect(byState.page).toBe('1');
	expect(byState.per_page).toBe('20');

	const me = await myId(page);
	const owner = root.locator('[data-filter="owner"]');
	await expect(owner).toBeVisible();
	await owner.fill(String(me));
	await root.locator('[data-apply]').click();
	await page.waitForResponse(response => response.url().includes(`user_id=${me}`) && response.request().method() === 'GET');
	const byOwner = seen[seen.length - 1];
	expect(byOwner.user_id).toBe(String(me));
	expect(byOwner.all).toBeUndefined();
	await root.locator('[data-clear]').click();
	await page.waitForResponse(response => response.url().includes('all=true') && !response.url().includes('user_id') && response.request().method() === 'GET');
	expect(seen[seen.length - 1].all).toBe('true');

	const clean = await page.evaluate(async () => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/keys?all=true&per_page=5', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const text = await response.text();
		return !text.includes('"secret"');
	});
	expect(clean).toBe(true);

	await page.goto('/wp-admin/profile.php');
	const profile = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(profile).toBeVisible();
	await expect(profile.locator('[data-filter]')).toHaveCount(0);
	expect(await profile.getAttribute('data-all')).toBe('0');
	await page.goto(`/wp-admin/user-edit.php?user_id=${me}`);
	const edit = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(edit).toBeVisible();
	await expect(edit.locator('[data-filter]')).toHaveCount(0);
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys**');
});

test('UI-KY-01 cross-owner keys stay denied while managers revoke scoped rows', async ({ page }) => {
	const name = `UI-KY cross ${Date.now()}`;
	await loginAs(page, 'editor');
	await page.goto('/wp-admin/profile.php');
	const hub = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(hub).toBeVisible();	await expect(hub.locator('[data-filter]')).toHaveCount(0);
	await revokeOwnLeftovers(page);
	await hub.locator('[data-field="name"]').fill(name);
	await hub.locator('[data-create]').click();
	const dialog = hub.locator('[data-secret-dialog]');
	await expect(dialog).toBeVisible();
	expect(await dialog.locator('[data-secret]').evaluate(node => node.value.length > 0)).toBe(true);
	await hub.locator('[data-saved]').click();
	await expect(dialog).not.toBeVisible();
	const owned = await page.evaluate(async wanted => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const list = await (await fetch('/wp-json/xnetvn-agent-hub-wp/v1/keys?per_page=100', { headers: { 'X-WP-Nonce': nonce } })).json();
		const found = list.find(entry => entry.name === wanted);
		const me = await (await fetch('/wp-json/wp/v2/users/me', { headers: { 'X-WP-Nonce': nonce } })).json();
		return { id: found ? Number(found.id) : -1, me: Number(me.id) };
	}, name);
	expect(owned.id > 0).toBe(true);

	await loginAs(page, 'editor_b');
	await page.goto('/wp-admin/profile.php');
	const other = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(other).toBeVisible();
	await expect(other.locator('[data-keys]')).not.toContainText(name);
	const denied = await page.evaluate(async id => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const get = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${id}`, { headers: { 'X-WP-Nonce': nonce } });
		const rotate = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${id}/rotate`, {
			method: 'POST',
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			body: '{}',
		});
		const remove = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${id}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
		return { get: get.status, rotate: rotate.status, remove: remove.status };
	}, owned.id);
	expect(denied).toEqual({ get: 404, rotate: 404, remove: 404 });

	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const admin = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(admin).toBeVisible();
	await admin.locator('[data-filter="owner"]').fill(String(owned.me));
	await admin.locator('[data-apply]').click();
	const row = admin.locator('[data-keys] tr').filter({ hasText: name });
	await expect(row).toBeVisible();
	await row.getByRole('button', { name: 'Revoke key' }).click();
	await admin.locator('[data-action-dialog]').getByRole('button', { name: 'Confirm', exact: true }).click();
	await expect(row).toContainText('revoked');
});

test('UI-KY-02 overlapping key actions send at most one mutation', async ({ page }) => {
	const mutations = [];
	await loginAs(page, 'administrator');
	// The fixture owner id is only known at runtime; read it on a plugin page that
	// exposes the REST config, then intercept the listing before the first load.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp');
	const ownerId = await myId(page);
	await page.route('**/xnetvn-agent-hub-wp/v1/keys**', async route => {
		const method = route.request().method();
		if (method !== 'GET') {
			mutations.push(`${method} ${new URL(route.request().url()).pathname}`);
		}
		if (method === 'POST' && route.request().url().includes('/rotate')) {
			await new Promise(resolve => setTimeout(resolve, 900));
			await route.fulfill({
				status: 201,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(syntheticResult('single')),
			});
			return;
		}
		if (method === 'GET') {
			await route.fulfill({
				status: 200,
				headers: { 'Content-Type': 'application/json', 'X-WP-Total': '2' },
				body: JSON.stringify(syntheticRows('UI-KY single', 2, ownerId)),
			});
			return;
		}
		await route.fulfill({
			status: 201,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(syntheticResult('single-create')),
		});
	});
	await page.goto(KEYS_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	await expect(root.locator('[data-keys] tr').first()).toContainText('UI-KY single row 0');

	await root.locator('[data-field="name"]').fill('UI-KY single create');
	await root.locator('[data-create]').evaluate(node => { node.click(); node.click(); });
	await page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/v1/keys'));
	const created = root.locator('[data-secret-dialog]');
	await expect(created).toBeVisible();
	await root.locator('[data-saved]').click();
	await expect(created).not.toBeVisible();
	expect(mutations.filter(entry => entry === 'POST /wp-json/xnetvn-agent-hub-wp/v1/keys').length).toBe(1);

	const rows = root.locator('[data-keys] tr');
	await rows.nth(0).getByRole('button', { name: 'Rotate key' }).click();
	const action = root.locator('[data-action-dialog]');
	await expect(action).toBeVisible();
	await action.getByRole('button', { name: 'Confirm', exact: true }).click();
	await rows.nth(1).getByRole('button', { name: 'Revoke key' }).evaluate(node => node.click());
	await root.locator('[data-create]').evaluate(node => node.click());
	if (await action.isVisible()) {
		await action.getByRole('button', { name: 'Confirm', exact: true }).click();
	}
	await expect(root.locator('[data-secret-dialog]')).toBeVisible();
	expect(mutations.length).toBe(2);
	await root.locator('[data-saved]').click();
	await expect(root.locator('[data-create]')).toBeEnabled();
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys**');
});

test('UI-KY-02 lost responses stay uncertain and failed refreshes keep the secret', async ({ page }) => {
	let posts = 0;
	let gets = 0;
	await page.route('**/xnetvn-agent-hub-wp/v1/keys**', async route => {
		if (route.request().method() === 'POST') {
			posts += 1;
			await route.abort('failed');
			return;
		}
		if (route.request().method() === 'GET') {
			gets += 1;
		}
		await route.continue();
	});
	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	const baseline = gets;
	await root.locator('[data-field="name"]').fill('UI-KY uncertain');
	await root.locator('[data-create]').click();
	const status = root.locator('.xnetvn-status');
	await expect(status).toContainText(/uncertain/i);
	expect(posts).toBe(1);
	await expect(root.locator('[data-secret-dialog]')).not.toBeVisible();
	await expect.poll(() => Promise.resolve(gets > baseline ? 1 : 0), { timeout: 10000 }).toBe(1);
	await expect.poll(() => Promise.resolve(posts), { timeout: 3000 }).toBe(1);

	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys**');
	await page.route('**/xnetvn-agent-hub-wp/v1/keys**', async route => {
		if (route.request().method() === 'POST') {
			await route.fulfill({
				status: 201,
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(syntheticResult('refresh')),
			});
			return;
		}
		if (route.request().method() === 'GET') {
			await route.fulfill({
				status: 500,
				headers: { 'Content-Type': 'application/json' },
				body: '{}',
			});
			return;
		}
		await route.continue();
	});
	await root.locator('[data-field="name"]').fill('UI-KY refresh');
	await root.locator('[data-create]').click();
	const secret = root.locator('[data-secret-dialog]');
	await expect(secret).toBeVisible();
	await expect(status).toContainText(/refresh/i);
	expect(await status.textContent()).not.toContain('could not complete');
	expect(await secret.locator('[data-secret]').evaluate(node => node.value.length > 0)).toBe(true);
	await root.locator('[data-saved]').click();
	await expect(secret).not.toBeVisible();
	expect(await secret.locator('[data-secret]').evaluate(node => node.value.length)).toBe(0);
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys**');
});

test('UI-KY-03 secret dialogs keep focus and clear on close', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	const name = `UI-KY focus ${Date.now()}`;
	await revokeOwnLeftovers(page);
	await root.locator('[data-field="name"]').fill(name);
	await root.locator('[data-create]').click();
	const dialog = root.locator('[data-secret-dialog]');
	await expect(dialog).toBeVisible();
	const input = dialog.locator('[data-secret]');
	expect(await input.evaluate(node => node.value.length > 0)).toBe(true);
	await expect(input).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(dialog.locator('[data-copy]')).toBeFocused();
	await page.keyboard.press('Shift+Tab');
	await expect(input).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(dialog.locator('[data-copy]')).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(dialog.locator('[data-saved]')).toBeFocused();
	await page.keyboard.press('Tab');
	// A native dialog hands focus to the inert document between its last and
	// first control; focus must never reach a control outside the modal.
	expect(await dialog.evaluate(node => node.contains(document.activeElement) || document.activeElement === document.body)).toBe(true);
	await page.keyboard.press('Tab');
	await expect(input).toBeFocused();
	await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	await expect(dialog).toBeVisible();
	await root.locator('[data-saved]').click();
	await expect(dialog).not.toBeVisible();
	await expect(root.locator('[data-create]')).toBeFocused();
	await page.reload();
	await expect(dialog).not.toBeVisible();
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	const row = root.locator('[data-keys] tr').filter({ hasText: name });
	await expect(row).toBeVisible();
	await row.getByRole('button', { name: 'Rotate key' }).click();
	const action = root.locator('[data-action-dialog]');
	await expect(action).toBeVisible();
	await action.getByRole('button', { name: 'Confirm', exact: true }).click();
	await expect(dialog).toBeVisible();
	await page.keyboard.press('Escape');
	await expect(dialog).not.toBeVisible();
	expect(await input.evaluate(node => node.value.length)).toBe(0);
	const rows = root.locator('[data-keys] tr').filter({ hasText: name });
	await expect(rows).toHaveCount(2);
	for (let index = 0; index < 2; index += 1) {
		await rows.nth(index).getByRole('button', { name: 'Revoke key' }).click();
		await action.getByRole('button', { name: 'Confirm', exact: true }).click();
		await expect(rows.nth(index)).toContainText('revoked');
	}
	expect(errors).toEqual([]);
});

test('UI-KY-04 destructive tiers require the current password and strangers are denied', async ({ page }) => {
	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	const password = process.env.XNETVN_TEST_PASSWORD;
	expect(typeof password === 'string' && password.length > 0).toBe(true);
	await revokeOwnLeftovers(page);
	const tier = root.locator('[data-field="tier_max"]');
	const group = root.locator('[data-password-group]');
	const current = root.locator('[data-field="current_password"]');
	expect(await group.evaluate(node => {
		const details = document.querySelector('details[data-group-advanced]');
		return Boolean(details) && !details.contains(node);
	})).toBe(true);
	await tier.selectOption('destructive');
	await expect(group).toBeVisible();
	expect(await current.evaluate(node => node.required)).toBe(true);
	await tier.selectOption('read');
	await expect(group).not.toBeVisible();
	expect(await current.evaluate(node => node.value.length)).toBe(0);
	await tier.selectOption('destructive');
	await root.locator('[data-field="name"]').fill(`UI-KY tier ${Date.now()}`);
	await current.fill(password);
	await root.locator('[data-create]').click();
	const dialog = root.locator('[data-secret-dialog]');
	await expect(dialog).toBeVisible();
	expect(await current.evaluate(node => node.value.length)).toBe(0);
	await root.locator('[data-saved]').click();
	await expect(dialog).not.toBeVisible();
	const created = root.locator('[data-keys] tr').filter({ hasText: 'UI-KY tier ' }).first();
	await expect(created).toBeVisible();
	await created.getByRole('button', { name: 'Revoke key' }).click();
	await root.locator('[data-action-dialog]').getByRole('button', { name: 'Confirm', exact: true }).click();
	await expect(created).toContainText('revoked');

	await loginAs(page, 'subscriber');
	await page.goto(KEYS_URL);
	// WordPress refuses the screen before the plugin renders it: no hub markup and a denial.
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
	await expect(page.locator('body')).toContainText(/not allowed to access this page|You cannot manage Agent Hub/);
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
});
