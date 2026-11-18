const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, installSecretCleanup } = require('./ui-helpers');

// Accountless site key coverage. Synthetic responses prove the browser
// contract; live calls stay read-only or are refused before reauthentication,
// so no live password-authenticated site key is created, rotated or revoked.
const KEYS_URL = '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys';
const SITE_KEYS = '**/xnetvn-agent-hub-wp/v1/site-keys**';
const RUN_ID = Date.now();
const XSS_NAME = `<img src=x onerror=window.__fr11=1 data-run="${RUN_ID}">`;
const SYNTHETIC_SECRET = 'xns_UI_SK_SYNTHETIC_SECRET_VALUE';
const ROTATED_SECRET = 'xns_UI_SK_SYNTHETIC_ROTATED_VALUE';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

/** One non-secret site key view shaped like SiteKeyView. Never carries a secret. */
function syntheticKey(overrides = {}) {
	return Object.assign({
		id: 4101,
		key_id: 'abcdefghijkl',
		credential_ref: 'xns:abcdefghijkl',
		principal_type: 'site',
		blog_id: 1,
		name: 'UI-SK synthetic row',
		profile: 'editorial-v1',
		toolsets: ['content', 'system'],
		allowed_abilities: ['xnetvn/get-post', 'xnetvn/list-posts'],
		denied_abilities: [],
		permissions: [],
		ip_allowlist: [],
		tier_ceiling: 'destructive',
		expires_at: '2027-01-01T00:00:00+00:00',
		created_at: '2026-11-01T00:00:00+00:00',
		revoked_at: null,
		created_by: 999,
		revoked_by: null,
		last_used_at: null,
		last_used_ip: null,
	}, overrides);
}

/** Fulfill one synthetic JSON response. */
function jsonResponse(status, payload) {
	return {
		status,
		headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
		body: JSON.stringify(payload),
	};
}

/** Resolve the site key route from a pretty or a plain permalink. */
function restPath(url) {
	return url.pathname.includes('/site-keys') ? url.pathname : (url.searchParams.get('rest_route') || '');
}

/** Record one intercepted request without printing its body. */
function recorded(route) {
	const request = route.request();
	return {
		method: request.method(),
		url: new URL(request.url()),
		headers: request.headers(),
		body: request.postData(),
	};
}

/** Assert the cookie/nonce boundary and the absence of a bearer on one request. */
function expectSiteHeaders(entry) {
	const nonce = entry.headers['x-wp-nonce'];
	expect(typeof nonce === 'string' && nonce.length > 0, `${entry.method} ${entry.url.pathname} carries the REST nonce`).toBe(true);
	expect(Object.keys(entry.headers).map(name => name.toLowerCase())).not.toContain('authorization');
}

/** Parse a JSON request body without ever echoing its raw value in a failure. */
function parsedBody(entry) {
	try {
		return JSON.parse(entry.body || 'null');
	} catch (error) {
		return null;
	}
}

/** Read the persisted non-secret site key projection. */
async function siteSnapshot(page) {
	return page.evaluate(async () => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?state=all&per_page=100', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const body = await response.json();
		return {
			status: response.status,
			items: Array.isArray(body.items) ? body.items.map(item => ({
				id: item.id,
				name: item.name,
				permissions: item.permissions,
				tier_ceiling: item.tier_ceiling,
				expires_at: item.expires_at,
				revoked_at: item.revoked_at,
			})) : null,
		};
	});
}

/** Open the site tab and return the visible site panel. */
async function openSitePanel(page) {
	await page.locator('#xnetvn-tab-site').click();
	const root = page.locator('[data-view="site-keys"]');
	await expect(root).toBeVisible();
	return root;
}

test('UI-SK-01 site keys list by page and per_page without a total', async ({ page }) => {
	const requestLog = [];
	let revoked = false;
	await page.route(SITE_KEYS, async route => {
		const entry = recorded(route);
		requestLog.push(entry);
		const rest = restPath(entry.url);
		const wanted = Number(entry.url.searchParams.get('page') || '1');
		if (entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20') {
			const items = wanted === 2
				? [syntheticKey({ id: 4102, key_id: 'bbbbbbbbbbbb', credential_ref: 'xns:bbbbbbbbbbbb', name: XSS_NAME, revoked_at: revoked ? '2026-11-18T01:00:00+00:00' : null })]
				: Array.from({ length: 20 }, (_, index) => syntheticKey({
					id: 5000 + index,
					key_id: `pageone${String(index).padStart(4, '0')}`,
					credential_ref: `xns:pageone${String(index).padStart(4, '0')}`,
					name: index === 0 ? `UI-SK B row ${RUN_ID}` : `UI-SK filler ${index}`,
				}));
			await route.fulfill(jsonResponse(200, { items, page: wanted, per_page: 20 }));
			return;
		}
		if (entry.method === 'POST' && rest.endsWith('/site-keys')) {
			await route.fulfill(jsonResponse(201, {
				key: syntheticKey({ id: 4102, key_id: 'bbbbbbbbbbbb', credential_ref: 'xns:bbbbbbbbbbbb', name: XSS_NAME }),
				secret: SYNTHETIC_SECRET,
			}));
			return;
		}
		if (entry.method === 'DELETE' && /\/site-keys\/4102$/.test(rest)) {
			revoked = true;
			await route.fulfill(jsonResponse(200, { id: 4102, revoked: true }));
			return;
		}
		await route.continue();
	});

	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const siteRoot = page.locator('[data-view="site-keys"]');
	await expect(siteRoot).toBeHidden();
	const rows = siteRoot.locator('[data-site-keys] tr');
	await openSitePanel(page);
	await expect(rows).toHaveCount(20);
	await expect(siteRoot.locator('[data-prev]')).toBeDisabled();
	await expect(siteRoot.locator('[data-next]')).toBeEnabled();
	await expect(siteRoot.locator('[data-page]')).toHaveText('Page 1');
	expect(await siteRoot.textContent()).not.toMatch(/\btotal\b/i);
	await expect(rows.filter({ hasText: `UI-SK B row ${RUN_ID}` })).toHaveCount(1);
	await expect(rows.filter({ hasText: XSS_NAME })).toHaveCount(0);

	await siteRoot.locator('#xnetvn-site-name').fill(XSS_NAME);
	await siteRoot.locator('#xnetvn-site-days').fill('90');
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await siteRoot.locator('[data-site-create]').click();
	const secretDialog = page.locator('[data-site-secret-dialog]');
	await expect(secretDialog).toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length > 0)).toBe(true);
	await page.locator('[data-site-saved]').click();
	await expect(secretDialog).not.toBeVisible();

	await expect(siteRoot.locator('[data-next]')).toBeEnabled();
	await siteRoot.locator('[data-next]').click();
	await expect(siteRoot.locator('[data-page]')).toHaveText('Page 2');
	await expect(rows).toHaveCount(1);
	await expect(rows.first()).toContainText(XSS_NAME);
	expect(await siteRoot.locator('img').count()).toBe(0);
	expect(await page.evaluate(() => window.__fr11)).toBeUndefined();

	const live = await page.evaluate(async () => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const first = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?page=1&per_page=1', { headers: { 'X-WP-Nonce': nonce } });
		const firstBody = await first.json();
		const second = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?page=2&per_page=1', { headers: { 'X-WP-Nonce': nonce } });
		const secondBody = await second.json();
		return {
			firstStatus: first.status,
			firstPage: firstBody.page,
			firstPerPage: firstBody.per_page,
			firstItems: Array.isArray(firstBody.items) ? firstBody.items.length : -1,
			firstHasTotal: Object.prototype.hasOwnProperty.call(firstBody, 'total'),
			secondStatus: second.status,
			secondPage: secondBody.page,
			secondPerPage: secondBody.per_page,
			secondItems: Array.isArray(secondBody.items) ? secondBody.items.length : -1,
			secondHasTotal: Object.prototype.hasOwnProperty.call(secondBody, 'total'),
		};
	});
	expect(live.firstStatus).toBe(200);
	expect(live.firstPage).toBe(1);
	expect(live.firstPerPage).toBe(1);
	expect(live.firstItems).toBeGreaterThanOrEqual(0);
	expect(live.firstItems).toBeLessThanOrEqual(1);
	expect(live.firstHasTotal).toBe(false);
	expect(live.secondStatus).toBe(200);
	expect(live.secondPage).toBe(2);
	expect(live.secondPerPage).toBe(1);
	expect(live.secondItems).toBeGreaterThanOrEqual(0);
	expect(live.secondItems).toBeLessThanOrEqual(1);
	expect(live.secondHasTotal).toBe(false);

	await rows.first().locator('[data-site-mutation="revoke"]').click();
	await expect(page.locator('[data-site-action-dialog]')).toBeVisible();
	await expect(page.locator('[data-site-action-password-group]')).toBeHidden();
	await page.locator('[data-site-confirm]').click();
	await expect(rows.first()).toContainText('Revoked');
	await expect(rows).toHaveCount(1);
	await siteRoot.locator('[data-prev]').click();
	await expect(siteRoot.locator('[data-page]')).toHaveText('Page 1');
	await expect(rows).toHaveCount(20);

	const listRequests = requestLog.filter(entry => entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20');
	expect(listRequests.length).toBeGreaterThanOrEqual(3);
	expect(listRequests.some(entry => entry.url.searchParams.get('page') === '2')).toBe(true);
	listRequests.forEach(entry => {
		expect(entry.url.searchParams.get('page')).not.toBeNull();
		expect(entry.url.searchParams.get('per_page')).toBe('20');
		expect(entry.url.searchParams.get('state')).toBeNull();
		expect(entry.url.searchParams.get('user_id')).toBeNull();
		expect(entry.url.searchParams.get('blog_id')).toBeNull();
		expectSiteHeaders(entry);
		expect(entry.headers['content-type']).toBeUndefined();
	});
	const creates = requestLog.filter(entry => entry.method === 'POST');
	expect(creates.length).toBe(1);
	expect(restPath(creates[0].url).endsWith('/site-keys')).toBe(true);
	expectSiteHeaders(creates[0]);
	expect(creates[0].headers['content-type']).toContain('application/json');
	const createBody = parsedBody(creates[0]);
	expect(createBody && typeof createBody === 'object').toBe(true);
	expect(Object.keys(createBody).sort()).toEqual(['current_password', 'expires_in_days', 'name', 'permissions']);
	expect(createBody.name).toBe(XSS_NAME);
	expect(createBody.permissions).toEqual([]);
	expect(createBody.expires_in_days).toBe(90);
	expect(typeof createBody.current_password === 'string' && createBody.current_password.length > 0).toBe(true);
	const deletes = requestLog.filter(entry => entry.method === 'DELETE');
	expect(deletes.length).toBe(1);
	expect(restPath(deletes[0].url)).toMatch(/\/site-keys\/4102$/);
	expectSiteHeaders(deletes[0]);
	expect(deletes[0].headers['content-type']).toBeUndefined();
	expect(deletes[0].body).toBeNull();
});

test('UI-SK-02 keyboard tabs and site dialogs keep focus and clear passwords', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const requestLog = [];
	let rotated = false;
	let revoked = false;
	let releaseCreate = null;
	const createGate = new Promise(resolve => { releaseCreate = resolve; });
	let createPosts = 0;
	const baseName = `UI-SK single row ${RUN_ID}`;
	const replacementName = `UI-SK replacement row ${RUN_ID}`;
	const createName = `UI-SK single create ${RUN_ID}`;
	const baseRow = syntheticKey({ id: 4201, key_id: 'rotatedold01', credential_ref: 'xns:rotatedold01', name: baseName });
	const replacement = syntheticKey({ id: 4202, key_id: 'replacement1', credential_ref: 'xns:replacement1', name: replacementName, expires_at: '2027-02-01T00:00:00+00:00' });
	await page.route(SITE_KEYS, async route => {
		const entry = recorded(route);
		requestLog.push(entry);
		const rest = restPath(entry.url);
		if (entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20') {
			let items = [baseRow];
			if (rotated) {
				items = [syntheticKey({ ...baseRow, expires_at: '2026-11-19T00:00:00+00:00' }), replacement];
			}
			if (revoked) {
				items = items.map(item => item.id === 4201 ? syntheticKey({ ...item, revoked_at: '2026-11-18T02:00:00+00:00' }) : item);
			}
			await route.fulfill(jsonResponse(200, { items, page: 1, per_page: 20 }));
			return;
		}
		if (entry.method === 'POST' && /\/site-keys\/4201\/rotate$/.test(rest)) {
			rotated = true;
			await route.fulfill(jsonResponse(201, { key: replacement, secret: ROTATED_SECRET }));
			return;
		}
		if (entry.method === 'POST' && rest.endsWith('/site-keys')) {
			createPosts += 1;
			await createGate;
			await route.fulfill(jsonResponse(201, {
				key: syntheticKey({ id: 4203, key_id: 'createdrow01', credential_ref: 'xns:createdrow01', name: createName }),
				secret: SYNTHETIC_SECRET,
			}));
			return;
		}
		if (entry.method === 'DELETE' && /\/site-keys\/4201$/.test(rest)) {
			revoked = true;
			await route.fulfill(jsonResponse(200, { id: 4201, revoked: true }));
			return;
		}
		await route.continue();
	});

	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const personalTab = page.locator('#xnetvn-tab-personal');
	const siteTab = page.locator('#xnetvn-tab-site');
	const personalPanel = page.locator('#xnetvn-panel-personal');
	const sitePanel = page.locator('#xnetvn-panel-site');
	await personalTab.focus();
	await expect(personalTab).toBeFocused();
	await expect(personalTab).toHaveAttribute('aria-selected', 'true');
	await expect(sitePanel).toBeHidden();
	await page.keyboard.press('ArrowRight');
	await expect(siteTab).toBeFocused();
	await expect(siteTab).toHaveAttribute('aria-selected', 'true');
	await expect(personalTab).toHaveAttribute('aria-selected', 'false');
	await expect(sitePanel).toBeVisible();
	await expect(personalPanel).toBeHidden();
	expect(await siteTab.evaluate(node => node.matches(':focus-visible'))).toBe(true);
	await page.keyboard.press('Enter');
	await expect(siteTab).toBeFocused();
	await expect(sitePanel).toBeVisible();
	await page.keyboard.press('Space');
	await expect(siteTab).toBeFocused();
	await page.keyboard.press('ArrowLeft');
	await expect(personalTab).toBeFocused();
	await expect(personalPanel).toBeVisible();
	await expect(sitePanel).toBeHidden();
	await page.keyboard.press('ArrowRight');
	await expect(siteTab).toBeFocused();

	const siteRoot = page.locator('[data-view="site-keys"]');
	const createButton = siteRoot.locator('[data-site-create]');
	await siteRoot.locator('#xnetvn-site-name').fill(createName);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await expect(createButton).toBeEnabled();
	await createButton.evaluate(node => { node.click(); node.click(); });
	await expect.poll(() => createPosts).toBe(1);
	await expect(createButton).toBeDisabled();
	releaseCreate();
	const secretDialog = page.locator('[data-site-secret-dialog]');
	await expect(secretDialog).toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length > 0)).toBe(true);
	await expect(secretDialog.locator('#xnetvn-site-secret')).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(secretDialog.locator('[data-site-copy]')).toBeFocused();
	await page.keyboard.press('Tab');
	await expect(secretDialog.locator('[data-site-saved]')).toBeFocused();
	await page.keyboard.press('Tab');
	expect(await secretDialog.evaluate(node => node.contains(document.activeElement) || document.activeElement === document.body)).toBe(true);
	await page.keyboard.press('Tab');
	await expect(secretDialog.locator('#xnetvn-site-secret')).toBeFocused();
	await page.locator('[data-site-saved]').click();
	await expect(secretDialog).not.toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length)).toBe(0);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	await expect(createButton).toBeFocused();

	const rows = siteRoot.locator('[data-site-keys] tr');
	const actionDialog = page.locator('[data-site-action-dialog]');
	const actionPassword = page.locator('#xnetvn-site-action-password');
	const mutationsBefore = requestLog.filter(entry => entry.method === 'POST' || entry.method === 'DELETE').length;
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await rows.first().locator('[data-site-mutation="rotate"]').click();
	await expect(actionDialog).toBeVisible();
	await expect(actionPassword).toBeFocused();
	await page.keyboard.press('Escape');
	await expect(actionDialog).not.toBeVisible();
	await expect.poll(async () => siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	await expect.poll(async () => actionPassword.evaluate(node => node.value.length)).toBe(0);
	expect(requestLog.filter(entry => entry.method === 'POST' || entry.method === 'DELETE').length).toBe(mutationsBefore);

	await rows.first().locator('[data-site-mutation="rotate"]').click();
	await expect(actionDialog).toBeVisible();
	await expect(page.locator('[data-site-action-password-group]')).toBeVisible();
	await expect(actionPassword).toBeFocused();
	await actionPassword.fill(process.env.XNETVN_TEST_PASSWORD);
	await page.locator('[data-site-confirm]').click();
	await expect(secretDialog).toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length > 0)).toBe(true);
	await expect(rows).toHaveCount(2);
	await expect(rows.filter({ hasText: baseName })).toHaveCount(1);
	await expect(rows.filter({ hasText: replacementName })).toHaveCount(1);
	const credentialRefs = await rows.locator('td:nth-child(2)').allTextContents();
	expect(credentialRefs.length).toBe(2);
	expect(new Set(credentialRefs).size).toBe(2);
	credentialRefs.forEach(value => {
		expect(value.startsWith('xns:')).toBe(true);
		expect(value.length).toBeGreaterThan(4);
	});
	const expiresCells = await rows.locator('td:nth-child(8)').allTextContents();
	expect(expiresCells.length).toBe(2);
	expect(expiresCells[0]).toContain('2026');
	expect(expiresCells[1]).toContain('2027');
	await page.keyboard.press('Escape');
	await expect(secretDialog).not.toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length)).toBe(0);
	expect(await actionPassword.evaluate(node => node.value.length)).toBe(0);
	await expect(page.locator('#xnetvn-site-heading')).toBeFocused();

	await rows.first().locator('[data-site-mutation="revoke"]').click();
	await expect(actionDialog).toBeVisible();
	await expect(page.locator('[data-site-action-password-group]')).toBeHidden();
	await page.locator('[data-site-confirm]').click();
	await expect(rows.first()).toContainText('Revoked');
	expect(await actionPassword.evaluate(node => node.value.length)).toBe(0);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	await expect(page.locator('#xnetvn-site-heading')).toBeFocused();

	const listEntries = requestLog.filter(entry => entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20');
	expect(listEntries.length).toBeGreaterThanOrEqual(2);
	listEntries.forEach(entry => {
		expect(entry.url.searchParams.get('page')).toBe('1');
		expect(entry.url.searchParams.get('per_page')).toBe('20');
		expectSiteHeaders(entry);
		expect(entry.headers['content-type']).toBeUndefined();
	});
	const createEntry = requestLog.find(entry => entry.method === 'POST' && restPath(entry.url).endsWith('/site-keys'));
	expect(Boolean(createEntry)).toBe(true);
	expectSiteHeaders(createEntry);
	expect(createEntry.headers['content-type']).toContain('application/json');
	const createBody = parsedBody(createEntry);
	expect(createBody && typeof createBody === 'object').toBe(true);
	expect(Object.keys(createBody).sort()).toEqual(['current_password', 'expires_in_days', 'name', 'permissions']);
	expect(createBody.name).toBe(createName);
	expect(createBody.permissions).toEqual([]);
	expect(createBody.expires_in_days).toBe(90);
	expect(typeof createBody.current_password === 'string' && createBody.current_password.length > 0).toBe(true);
	const rotateEntry = requestLog.find(entry => entry.method === 'POST' && restPath(entry.url).endsWith('/rotate'));
	expect(Boolean(rotateEntry)).toBe(true);
	expectSiteHeaders(rotateEntry);
	expect(rotateEntry.headers['content-type']).toContain('application/json');
	const rotateBody = parsedBody(rotateEntry);
	expect(rotateBody && typeof rotateBody === 'object').toBe(true);
	expect(Object.keys(rotateBody)).toEqual(['current_password']);
	expect(typeof rotateBody.current_password === 'string' && rotateBody.current_password.length > 0).toBe(true);
	const deleteEntry = requestLog.find(entry => entry.method === 'DELETE');
	expect(Boolean(deleteEntry)).toBe(true);
	expect(restPath(deleteEntry.url)).toMatch(/\/site-keys\/4201$/);
	expectSiteHeaders(deleteEntry);
	expect(deleteEntry.headers['content-type']).toBeUndefined();
	expect(deleteEntry.body).toBeNull();
	expect(errors).toEqual([]);
});

test('UI-SK-03 site requests keep the auth boundary and reveal a secret once', async ({ page }) => {
	const requestLog = [];
	let wrongPassword = false;
	let liveCreate = false;
	let injectedCreates = 0;
	let livePosts = 0;
	await page.route(SITE_KEYS, async route => {
		const entry = recorded(route);
		const rest = restPath(entry.url);
		if (entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20') {
			await route.fulfill(jsonResponse(200, { items: [syntheticKey({ id: 4300, key_id: 'secretsrow01', credential_ref: 'xns:secretsrow01', name: `UI-SK secret row ${RUN_ID}` })], page: 1, per_page: 20 }));
			return;
		}
		if (entry.method === 'POST' && rest.endsWith('/site-keys')) {
			requestLog.push(entry);
			if (liveCreate) {
				livePosts += 1;
				await route.continue();
				return;
			}
			injectedCreates += 1;
			if (wrongPassword) {
				await route.fulfill(jsonResponse(403, { code: 'xnetvn_agent_hub_wp_reauthentication_required', message: 'The site key operation is unavailable.', data: { status: 403 } }));
				return;
			}
			await route.fulfill(jsonResponse(201, {
				key: syntheticKey({ id: 4301, key_id: 'secretrow001', credential_ref: 'xns:secretrow001', name: `UI-SK created secret row ${RUN_ID}` }),
				secret: SYNTHETIC_SECRET,
			}));
			return;
		}
		await route.continue();
	});

	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const siteRoot = await openSitePanel(page);
	const defaults = await page.evaluate(() => ({
		moderationAllowed: window.xnetvnAgentHubConfig.site.moderationAllowed,
		ceiling: window.xnetvnAgentHubConfig.site.ceiling,
	}));
	expect(defaults).toEqual({ moderationAllowed: false, ceiling: 'destructive' });
	await expect(siteRoot.locator('#xnetvn-site-permission-ban')).toBeDisabled();
	await expect(siteRoot.locator('#xnetvn-site-permission-unban')).toBeDisabled();

	await siteRoot.locator('#xnetvn-site-name').fill(`UI-SK secret row ${RUN_ID}`);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await siteRoot.locator('[data-site-create]').click();
	const secretDialog = page.locator('[data-site-secret-dialog]');
	await expect(secretDialog).toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length > 0)).toBe(true);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	const storageHasSecret = await page.evaluate(() => {
		const read = store => Object.keys(store).map(key => String(store.getItem(key)));
		const values = read(window.localStorage).concat(read(window.sessionStorage), [document.cookie]);
		return values.some(value => value.includes('xns_'));
	});
	expect(storageHasSecret).toBe(false);
	await page.keyboard.press('Escape');
	await expect(secretDialog).not.toBeVisible();
	expect(await secretDialog.locator('#xnetvn-site-secret').evaluate(node => node.value.length)).toBe(0);
	const domHasSecret = await page.evaluate(() => /xns_[A-Za-z0-9_-]{8,}/.test(document.documentElement.outerHTML));
	expect(domHasSecret).toBe(false);
	expect(JSON.stringify(await page.context().storageState()).includes('xns_')).toBe(false);

	wrongPassword = true;
	const injectedBefore = injectedCreates;
	await siteRoot.locator('#xnetvn-site-name').fill(`UI-SK wrong password ${RUN_ID}`);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await siteRoot.locator('[data-site-create]').click();
	await expect(siteRoot.locator('.xnetvn-status')).toContainText(/refused/i);
	expect(injectedCreates).toBe(injectedBefore + 1);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	await expect(secretDialog).not.toBeVisible();

	const originalNonce = await page.evaluate(() => window.xnetvnAgentHubConfig.nonce);
	await page.evaluate(() => { window.xnetvnAgentHubConfig.nonce = 'invalid-nonce'; });
	liveCreate = true;
	await siteRoot.locator('#xnetvn-site-name').fill(`UI-SK stale nonce ${RUN_ID}`);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	const liveResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/site-keys'));
	await siteRoot.locator('[data-site-create]').click();
	expect((await liveResponse).status()).toBe(403);
	await expect(siteRoot.locator('.xnetvn-status')).toContainText(/session/i);
	expect(livePosts).toBe(1);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	await expect(secretDialog).not.toBeVisible();
	await page.evaluate(nonce => { window.xnetvnAgentHubConfig.nonce = nonce; }, originalNonce);
	liveCreate = false;

	const results = await new AxeBuilder({ page })
		.include('[data-view="site-keys"]')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
		.analyze();
	expect(results.violations.map(violation => ({ id: violation.id, impact: violation.impact }))).toEqual([]);

	expect(requestLog.length).toBe(3);
	requestLog.forEach(entry => {
		expectSiteHeaders(entry);
		expect(entry.headers['content-type']).toContain('application/json');
		expect(restPath(entry.url).endsWith('/site-keys')).toBe(true);
		const body = parsedBody(entry);
		expect(body && typeof body === 'object').toBe(true);
		expect(Object.keys(body).sort()).toEqual(['current_password', 'expires_in_days', 'name', 'permissions']);
		expect(body.permissions).toEqual([]);
		expect(body.expires_in_days).toBe(90);
		expect(typeof body.current_password === 'string' && body.current_password.length > 0).toBe(true);
	});
});

test('UI-SK-04 refusals and bounds leave persisted state unchanged', async ({ page }) => {
	const requestLog = [];
	let injectCreate = null;
	let injectList503 = false;
	let list503Requests = 0;
	await page.route(SITE_KEYS, async route => {
		const entry = recorded(route);
		requestLog.push(entry);
		const rest = restPath(entry.url);
		if (entry.method === 'GET' && entry.url.searchParams.get('per_page') === '20') {
			if (injectList503) {
				list503Requests += 1;
				await route.fulfill(jsonResponse(503, { code: 'xnetvn_agent_hub_wp_unavailable', message: 'The site key operation is unavailable.', data: { status: 503 } }));
				return;
			}
			await route.continue();
			return;
		}
		if (entry.method === 'POST' && rest.endsWith('/site-keys') && injectCreate) {
			const status = injectCreate;
			await route.fulfill(jsonResponse(status, {
				code: status === 409 ? 'xnetvn_agent_hub_wp_key_limit' : 'xnetvn_agent_hub_wp_rate_limited',
				message: 'The site key operation is unavailable.',
				data: { status },
			}));
			return;
		}
		await route.continue();
	});

	await loginAs(page, 'administrator');
	await page.goto(KEYS_URL);
	const before = await siteSnapshot(page);
	expect(before.status).toBe(200);
	expect(Array.isArray(before.items)).toBe(true);
	const siteRoot = await openSitePanel(page);
	const defaults = await page.evaluate(() => ({
		moderationAllowed: window.xnetvnAgentHubConfig.site.moderationAllowed,
		ceiling: window.xnetvnAgentHubConfig.site.ceiling,
	}));
	expect(defaults).toEqual({ moderationAllowed: false, ceiling: 'destructive' });

	const staleStatus = await page.evaluate(async () => (await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?page=1&per_page=1', {
		headers: { 'X-WP-Nonce': 'invalid-nonce' },
	})).status);
	expect(staleStatus).toBe(403);
	expect(await siteSnapshot(page)).toEqual(before);

	const forbiddenStatus = await page.evaluate(async runId => (await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys', {
		method: 'POST',
		headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce, 'Content-Type': 'application/json' },
		body: JSON.stringify({ name: `UI-SK forbidden ${runId}`, permissions: ['ban-users'], expires_in_days: 90, current_password: 'UI-SK-unused-password' }),
	})).status, RUN_ID);
	expect(forbiddenStatus).toBe(403);
	expect(await siteSnapshot(page)).toEqual(before);

	const absentStatus = await page.evaluate(async () => (await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys/99999999/rotate', {
		method: 'POST',
		headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce, 'Content-Type': 'application/json' },
		body: JSON.stringify({ current_password: 'UI-SK-unused-password' }),
	})).status);
	expect(absentStatus).toBe(404);
	expect(await siteSnapshot(page)).toEqual(before);

	let posts = requestLog.filter(entry => entry.method === 'POST').length;
	injectCreate = 409;
	await siteRoot.locator('#xnetvn-site-name').fill(`UI-SK quota ${RUN_ID}`);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await siteRoot.locator('[data-site-create]').click();
	await expect(siteRoot.locator('.xnetvn-status')).toContainText(/quota/i);
	expect(requestLog.filter(entry => entry.method === 'POST').length).toBe(posts + 1);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	expect(await page.locator('#xnetvn-site-action-password').evaluate(node => node.value.length)).toBe(0);
	await expect(page.locator('[data-site-secret-dialog]')).not.toBeVisible();
	injectCreate = null;
	expect(await siteSnapshot(page)).toEqual(before);

	posts = requestLog.filter(entry => entry.method === 'POST').length;
	injectCreate = 429;
	await siteRoot.locator('#xnetvn-site-name').fill(`UI-SK wait ${RUN_ID}`);
	await siteRoot.locator('#xnetvn-site-password').fill(process.env.XNETVN_TEST_PASSWORD);
	await siteRoot.locator('[data-site-create]').click();
	await expect(siteRoot.locator('.xnetvn-status')).toContainText(/wait a minute/i);
	expect(requestLog.filter(entry => entry.method === 'POST').length).toBe(posts + 1);
	expect(await siteRoot.locator('#xnetvn-site-password').evaluate(node => node.value.length)).toBe(0);
	expect(await page.locator('#xnetvn-site-action-password').evaluate(node => node.value.length)).toBe(0);
	await expect(page.locator('[data-site-secret-dialog]')).not.toBeVisible();
	injectCreate = null;
	expect(await siteSnapshot(page)).toEqual(before);

	injectList503 = true;
	list503Requests = 0;
	await page.reload();
	await page.locator('#xnetvn-tab-site').click();
	await expect(siteRoot.locator('[data-error]')).toBeVisible();
	await expect(siteRoot.locator('[data-error-text]')).toContainText(/unavailable/i);
	expect(list503Requests).toBe(1);
	injectList503 = false;
	await siteRoot.locator('[data-retry]').click();
	await expect(siteRoot.locator('[data-error]')).toBeHidden();
	expect(await siteSnapshot(page)).toEqual(before);

	const bounds = await page.evaluate(async () => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const one = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?per_page=1', { headers: { 'X-WP-Nonce': nonce } });
		const oneBody = await one.json();
		const hundred = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?per_page=100', { headers: { 'X-WP-Nonce': nonce } });
		const hundredBody = await hundred.json();
		const over = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/site-keys?per_page=101', { headers: { 'X-WP-Nonce': nonce } });
		const overBody = await over.json();
		return {
			oneStatus: one.status,
			onePerPage: oneBody.per_page,
			hundredStatus: hundred.status,
			hundredPerPage: hundredBody.per_page,
			overStatus: over.status,
			overHasItems: Object.prototype.hasOwnProperty.call(overBody, 'items'),
		};
	});
	expect(bounds.oneStatus).toBe(200);
	expect(bounds.onePerPage).toBe(1);
	expect(bounds.hundredStatus).toBe(200);
	expect(bounds.hundredPerPage).toBe(100);
	expect(bounds.overStatus).toBe(400);
	expect(bounds.overHasItems).toBe(false);
	expect(await siteSnapshot(page)).toEqual(before);
});
