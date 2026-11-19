const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-AC activity audit filtering scoped by owner with bounded paging.
 *
 * Runner flow (lab shell, not the browser), in this exact order:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js tests/E2E/ui-activity.spec.js'
 * 3. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * UI-AC-01 proves owner scoping: choosing an owner sends user_id without
 * all, clearing returns to all=true, and no transport filter exists because
 * the API has none. UI-AC-02 proves local datetimes become UTC, a reversed
 * range never sends, and Apply/Clear reset to page 1. UI-AC-03 proves paging
 * uses result.total/per_page, stale responses are dropped, errors keep the
 * last rows, markup stays text, badges keep text, and no secret or argument
 * material reaches the table or artifacts. The browser never logs filter
 * values; assertions use booleans and counts, never raw values. UI-AC-04,
 * UI-AC-05, UI-AC-06 and UI-AC-07 add the post-FR_29 principal vocabulary:
 * a website key renders as itself, an empty page invents nothing, conflicting
 * principal filters never reach the route and non-managers send no request.
 */

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const ACTIVITY_URL = '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-activity';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

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

/** Deterministic audit page. Only whitelist columns, never secrets. */
function syntheticAudit(tag, pageNumber, total, ownerId) {
	const perPage = 20;
	const start = (pageNumber - 1) * perPage;
	const remaining = Math.max(0, total - start);
	const count = Math.min(perPage, remaining);
	const items = [];
	for (let index = 0; index < count; index += 1) {
		const globalIndex = start + index;
		items.push({
			occurred_at: '2026-10-05 00:00:00',
			user_id: globalIndex % 2 === 0 ? ownerId : null,
			ability: globalIndex === 1 ? '<img src="x" onerror="alert(1)">xnetvn/markup-probe' : 'xnetvn/get-site-info',
			outcome: globalIndex % 7 === 0 ? 'denied' : 'success',
			transport: 'http',
		});
	}
	return { items, total, page: pageNumber, per_page: perPage, tag };
}

test('UI-AC-01 owner and field filters scope the audit listing without widening access', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const seen = [];
	await page.route('**/xnetvn-agent-hub-wp/v1/audit**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		seen.push(Object.fromEntries(new URL(route.request().url()).searchParams.entries()));
		await route.continue();
	});
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();

	const owner = root.locator('[data-filter="owner"]');
	const keyId = root.locator('[data-filter="key_id"]');
	const ability = root.locator('[data-filter="ability"]');
	const outcome = root.locator('[data-filter="outcome"]');
	await expect(owner).toBeVisible();
	await expect(keyId).toBeVisible();
	await expect(ability).toBeVisible();
	await expect(outcome).toBeVisible();
	expect(await root.locator('label[for="xnetvn-activity-owner"]').count(), 'owner label').toBe(1);
	expect(await root.locator('label[for="xnetvn-activity-key"]').count(), 'key label').toBe(1);
	expect(await root.locator('label[for="xnetvn-activity-ability"]').count(), 'ability label').toBe(1);
	expect(await root.locator('label[for="xnetvn-activity-outcome"]').count(), 'outcome label').toBe(1);
	expect(await root.locator('[data-filter="transport"]').count(), 'no transport filter').toBe(0);
	expect(await root.locator('[data-apply]').getAttribute('type'), 'apply never submits').toBe('button');
	expect(await root.locator('[data-clear]').getAttribute('type'), 'clear never submits').toBe('button');
	expect(await outcome.evaluate(node => Array.from(node.options).map(option => option.value))).toEqual(['all', 'success', 'denied', 'error', 'invalid_input', 'rate_limited', 'auth_failed']);

	const me = await myId(page);
	expect(Number.isInteger(me) && me > 0, 'fixture owner id is numeric').toBe(true);
	await owner.fill(String(me));
	await keyId.fill('abcdefghijkl');
	await ability.fill('xnetvn/get-site-info');
	await outcome.selectOption('denied');
	await root.locator('[data-apply]').click();
	await page.waitForResponse(response => response.url().includes('user_id%3D') || response.url().includes(`user_id=${me}`) || response.url().includes('user_id'), { timeout: 15000 }).catch(() => {});
	await page.waitForTimeout(500);
	const byOwner = seen[seen.length - 1];
	expect(byOwner.user_id).toBe(String(me));
	expect(byOwner.all).toBeUndefined();
	expect(byOwner.key_id).toBe('abcdefghijkl');
	expect(byOwner.ability).toBe('xnetvn/get-site-info');
	expect(byOwner.outcome).toBe('denied');
	expect(byOwner.page).toBe('1');
	expect(byOwner.per_page).toBe('20');

	await root.locator('[data-clear]').click();
	await page.waitForResponse(response => response.url().includes('all%3Dtrue') || response.url().includes('all=true'), { timeout: 15000 }).catch(() => {});
	await page.waitForTimeout(500);
	const cleared = seen[seen.length - 1];
	expect(cleared.all).toBe('true');
	expect(cleared.user_id).toBeUndefined();
	expect(await owner.inputValue(), 'owner resets').toBe('');
	expect(await keyId.inputValue(), 'key resets').toBe('');
	expect(await ability.inputValue(), 'ability resets').toBe('');
	expect(await outcome.inputValue(), 'outcome resets').toBe('all');

	const clean = await page.evaluate(async () => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/audit?all=true&per_page=5', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const text = await response.text();
		return !text.includes('"arg_keys"') && !text.includes('"ip"') && !text.includes('"secret"');
	});
	expect(clean, 'listing keeps the five-column whitelist').toBe(true);

	await page.unroute('**/xnetvn-agent-hub-wp/v1/audit**');
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-01b actors without management are denied the activity screen', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await loginAs(page, 'subscriber');
	await page.goto(ACTIVITY_URL);
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
	await expect(page.locator('body')).toContainText(/not allowed to access this page|You cannot manage Agent Hub/);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-02 local datetimes become UTC and a reversed range never sends', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const seen = [];
	let auditCalls = 0;
	await page.route('**/xnetvn-agent-hub-wp/v1/audit**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		auditCalls += 1;
		seen.push(Object.fromEntries(new URL(route.request().url()).searchParams.entries()));
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(syntheticAudit('UI-AC-02', Number(new URL(route.request().url()).searchParams.get('page') || '1'), 41, 2)),
		});
	});
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();
	const after = root.locator('[data-filter="after"]');
	const before = root.locator('[data-filter="before"]');
	await expect(after).toBeVisible();
	await expect(before).toBeVisible();
	expect(await after.getAttribute('type'), 'after uses datetime-local').toBe('datetime-local');
	expect(await before.getAttribute('type'), 'before uses datetime-local').toBe('datetime-local');
	const zone = root.locator('[data-timezone]');
	await expect(zone).toBeVisible();
	expect(((await zone.textContent()) || '').length > 0, 'browser timezone is named').toBe(true);

	const baseline = auditCalls;
	await after.fill('2026-10-02T10:00');
	await before.fill('2026-10-01T10:00');
	await root.locator('[data-apply]').click();
	await page.waitForTimeout(800);
	expect(auditCalls, 'reversed range sends no GET').toBe(baseline);

	await after.fill('2026-10-01T00:00');
	await before.fill('2026-10-02T00:00');
	await root.locator('[data-apply]').click();
	await page.waitForResponse(response => response.url().includes('after') && response.request().method() === 'GET', { timeout: 15000 });
	const valid = seen[seen.length - 1];
	expect(valid.page).toBe('1');
	expect(typeof valid.after === 'string' && valid.after.length > 0, 'after is sent').toBe(true);
	expect(typeof valid.before === 'string' && valid.before.length > 0, 'before is sent').toBe(true);
	expect(valid.after.includes('2026-10-01'), 'after keeps the chosen day in UTC').toBe(true);
	expect(valid.before.includes('2026-10-02'), 'before keeps the chosen day in UTC').toBe(true);

	await root.locator('[data-next]').click();
	await expect(root.locator('[data-page]')).toContainText('Page 2', { timeout: 15000 });
	await after.fill('2026-10-03T00:00');
	await before.fill('2026-10-04T00:00');
	await root.locator('[data-apply]').click();
	await page.waitForResponse(response => response.url().includes('2026-10-03') && response.request().method() === 'GET', { timeout: 15000 }).catch(() => {});
	await page.waitForTimeout(500);
	expect(seen[seen.length - 1].page).toBe('1');

	await root.locator('[data-clear]').click();
	await page.waitForTimeout(500);
	expect(await after.inputValue(), 'after resets').toBe('');
	expect(await before.inputValue(), 'before resets').toBe('');
	expect(seen[seen.length - 1].page).toBe('1');
	expect(seen[seen.length - 1].after).toBeUndefined();
	expect(seen[seen.length - 1].before).toBeUndefined();
	await page.unroute('**/xnetvn-agent-hub-wp/v1/audit**');
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-03 paging follows total, stale rows drop, and markup stays text', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const seen = [];
	let delayFirstPageTwo = true;
	let failNextPageThree = false;
	await page.route('**/xnetvn-agent-hub-wp/v1/audit**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		const url = new URL(route.request().url());
		const requested = Number(url.searchParams.get('page') || '1');
		seen.push(url.searchParams.get('page') || '1');
		if (failNextPageThree && requested === 3) {
			await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
			return;
		}
		if (requested === 2 && delayFirstPageTwo) {
			delayFirstPageTwo = false;
			await new Promise(resolve => setTimeout(resolve, 1200));
		}
		const total = url.searchParams.get('outcome') === 'denied' ? 0 : 41;
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(syntheticAudit('UI-AC-03', requested, total, 2)),
		});
	});
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();
	const table = root.locator('[data-activity]');
	await expect(table.locator('tr').first()).toBeVisible({ timeout: 15000 });
	expect(await table.locator('tr').count(), 'first page is bounded to 20 rows').toBeLessThanOrEqual(20);
	await expect(root.locator('[data-page]')).toContainText('Page 1');

	await root.locator('[data-next]').click();
	await expect(root.locator('[data-loading]')).toBeVisible();
	await expect(root.locator('[data-page]')).toContainText('Page 2', { timeout: 15000 });
	expect(await table.locator('tr').count(), 'second page is bounded').toBeLessThanOrEqual(20);
	await root.locator('[data-next]').click();
	await expect(root.locator('[data-page]')).toContainText('Page 3', { timeout: 15000 });
	expect(await table.locator('tr').count(), 'third page holds the final row').toBe(1);
	await expect(root.locator('[data-next]')).toBeDisabled();
	await root.locator('[data-prev]').click();
	await expect(root.locator('[data-page]')).toContainText('Page 2', { timeout: 15000 });

	failNextPageThree = true;
	const before = await table.locator('tr').count();
	await root.locator('[data-next]').click();
	await expect(root.locator('[data-error]')).toBeVisible({ timeout: 15000 });
	expect(await table.locator('tr').count(), 'failed read keeps the last rows').toBe(before);
	await expect(root.locator('[data-page]')).toContainText('Page 2');
	failNextPageThree = false;
	await root.locator('[data-retry]').click();
	await expect(root.locator('[data-error]')).toBeHidden({ timeout: 15000 });
	await expect(root.locator('[data-page]')).toContainText('Page 3', { timeout: 15000 });

	await root.locator('[data-filter="outcome"]').selectOption('denied');
	await root.locator('[data-apply]').click();
	await expect(root.locator('[data-empty]')).toBeVisible({ timeout: 15000 });
	await expect(root.locator('[data-next]')).toBeDisabled();
	expect(await page.locator('.xnetvn-agent-hub').getByRole('button', { name: /export/i }).count(), 'no export action').toBe(0);
	expect(await page.locator('.xnetvn-agent-hub').getByRole('link', { name: /export/i }).count(), 'no export link').toBe(0);

	await root.locator('[data-clear]').click();
	await expect(table.locator('tr').first()).toBeVisible({ timeout: 15000 });
	const probe = table.locator('tr', { hasText: 'xnetvn/markup-probe' }).first();
	await expect(probe).toContainText('<img src="x"');
	expect(await table.locator('img').count(), 'markup never becomes an element').toBe(0);
	const outcomeText = await probe.textContent();
	expect((outcomeText || '').length > 0, 'outcome badge keeps text').toBe(true);
	const responseClean = await page.evaluate(async () => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/audit?all=true&per_page=5', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const text = await response.text();
		return !text.includes('"secret"') && !text.includes('"arg_keys"') && !text.includes('"ip"') && !text.includes('@example');
	});
	expect(responseClean, 'audit response keeps the whitelist').toBe(true);
	const headers = await table.locator('tr').first().locator('td').count();
	expect(headers, 'five whitelist columns').toBe(5);

	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		await expect(root.locator('[data-filter="owner"]')).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.setViewportSize({ width: 1440, height: 900 });
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub[data-view="activity"]').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
	await page.unroute('**/xnetvn-agent-hub-wp/v1/audit**');
	expect(errors, 'no client script error').toEqual([]);
});

/**
 * Deterministic post-FR_29 page that answers the requested page: the first
 * page starts with the three documented rows and pads to the page size, and a
 * later page carries filler rows. Only the eight documented wire fields.
 */
function syntheticPrincipalAudit(pageNumber, total) {
	const perPage = 20;
	const start = (pageNumber - 1) * perPage;
	const count = Math.max(0, Math.min(perPage, total - start));
	const seed = [
		{ occurred_at: '2026-11-18 10:00:00', user_id: null, ability: 'xnetvn/create-post', outcome: 'success', transport: 'http', principal_type: 'site', blog_id: 1, credential_ref: 'xns:abcdefghijkl' },
		{ occurred_at: '2026-11-18 09:59:00', user_id: 7, ability: 'xnetvn/create-post', outcome: 'denied', transport: 'http', principal_type: 'user', blog_id: 1, credential_ref: 'xnv:abcdefghijkl' },
		{ occurred_at: '2026-11-18 09:58:00', user_id: null, ability: 'xnetvn/get-site-info', outcome: 'error', transport: 'stdio', principal_type: 'user', blog_id: 1, credential_ref: null },
	];
	const items = [];
	for (let index = 0; index < count; index += 1) {
		const offset = start + index;
		items.push(offset < seed.length ? seed[offset] : {
			occurred_at: '2026-11-18 09:00:00',
			user_id: offset % 2 === 0 ? null : 7,
			ability: 'xnetvn/get-site-info',
			outcome: 'success',
			transport: 'http',
			principal_type: offset % 2 === 0 ? 'site' : 'user',
			blog_id: 1,
			credential_ref: offset % 2 === 0 ? 'xns:abcdefghijkl' : 'xnv:abcdefghijkl',
		});
	}
	return { items, total, page: pageNumber, per_page: perPage };
}

const AUDIT_ROUTE = '**/xnetvn-agent-hub-wp/v1/audit**';

test('UI-AC-04 site activity renders a website key without a fabricated user', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const seed = syntheticPrincipalAudit(1, 3).items;
	const payload = {
		items: seed.concat([{
			occurred_at: '2026-11-18 09:57:00',
			user_id: null,
			ability: 'xnetvn/get-site-info',
			outcome: 'success',
			transport: 'http',
			principal_type: 'site',
			blog_id: 1,
			credential_ref: '<img src="x" onerror="alert(1)">',
		}]),
		total: 4,
		page: 1,
		per_page: 20,
	};
	await page.route(AUDIT_ROUTE, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) }));
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();
	await expect(root.locator('[data-filter="principal_type"]')).toBeVisible();
	await expect(root.locator('[data-filter="credential_ref"]')).toBeVisible();
	await expect(root.locator('[data-activity] tr')).toHaveCount(4);

	const rows = root.locator('[data-activity] tr');
	for (let index = 0; index < 4; index += 1) {
		expect(await rows.nth(index).locator('td').count(), `five cells in row ${index}`).toBe(5);
	}
	const userCells = root.locator('[data-activity] tr td:nth-child(2)');
	expect(await userCells.nth(0).textContent(), 'site row names the key, never a person').toBe('Website MCP key · Not applicable · xns:abcdefghijkl');
	expect(await userCells.nth(1).textContent(), 'human row keeps its user id').toBe('7');
	expect(await userCells.nth(2).textContent(), 'legacy human row reads Anonymous').toBe('Anonymous');
	expect(await userCells.nth(3).textContent(), 'malformed reference is discarded').toBe('Website MCP key · Not applicable · Unavailable');
	expect(await root.locator('thead th').count(), 'five headers').toBe(5);
	expect(await root.locator('[data-filter="transport"]').count(), 'no transport filter').toBe(0);
	expect(await root.locator('[data-activity] img').count(), 'payload creates no element').toBe(0);
	const tableText = await root.locator('[data-activity]').textContent();
	expect(tableText, 'no invented user 0').not.toContain('user 0');
	expect(tableText, 'no invented unknown user').not.toContain('Unknown user');
	expect(tableText, 'no payload element').not.toContain('<img');
	await expect(root.locator('[data-page]')).toContainText('Page 1');
	await expect(root.locator('[data-next]')).toBeDisabled();

	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }, { width: 320, height: 800 }]) {
		await page.setViewportSize(size);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 1440, height: 900 });
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub[data-view="activity"]').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	await page.unroute(AUDIT_ROUTE);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-05 an empty activity page invents no row and no total', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const requests = [];
	await page.route(AUDIT_ROUTE, async route => {
		requests.push(new URL(route.request().url()).search);
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ items: [], total: 0, page: 1, per_page: 20 }) });
	});
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();
	await expect(root.locator('[data-activity] tr')).toHaveCount(1);
	const emptyRow = root.locator('[data-activity] tr').first();
	expect(await emptyRow.locator('td').count(), 'one message cell').toBe(1);
	await expect(emptyRow.locator('td')).toHaveAttribute('colspan', '5');
	await expect(root.locator('[data-empty]')).toBeVisible();
	await expect(root.locator('[data-page]')).toContainText('Page 1');
	await expect(root.locator('[data-prev]')).toBeDisabled();
	await expect(root.locator('[data-next]')).toBeDisabled();

	await page.unroute(AUDIT_ROUTE);
	await page.route(AUDIT_ROUTE, async route => {
		requests.push(new URL(route.request().url()).search);
		const pageNumber = Number(new URL(route.request().url()).searchParams.get('page') || '1');
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(syntheticPrincipalAudit(pageNumber, 41)) });
	});
	await root.locator('[data-clear]').click();
	await expect(root.locator('[data-activity] tr')).toHaveCount(20);
	await expect(root.locator('[data-next]')).toBeEnabled();

	const beforeNext = requests.length;
	await root.locator('[data-next]').click();
	await expect(root.locator('[data-page]')).toContainText('Page 2');
	await expect(root.locator('[data-activity] tr')).toHaveCount(20);
	const pageTwo = requests.slice(beforeNext).map(search => new URLSearchParams(search));
	expect(pageTwo.length, 'one request for the second page').toBe(1);
	expect(pageTwo[0].get('page'), 'second page request').toBe('2');
	await page.unroute(AUDIT_ROUTE);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-06 conflicting principal filters never reach the audit route', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const messageInvalidReference = 'Enter a credential reference such as xnv:abcdefghijkl or xns:abcdefghijkl.';
	const messageWebsiteReferenceNeedsSource = 'A website reference needs the Website MCP key (xns:) source. Clear the reference or choose it.';
	const messagePersonalReferenceNotWebsite = 'A personal reference does not belong to the Website MCP key (xns:) source. Clear it or change the source.';
	const messageOwnerNotForWebsite = 'Owner ID does not apply to website activity. Clear the owner or the website reference.';
	const messageKeyNotForWebsite = 'Key ID does not apply to website activity. Clear the key or the website reference.';
	const messageReferenceMismatch = 'Key ID and credential reference must name the same personal key.';
	const seen = [];
	await page.route(AUDIT_ROUTE, async route => {
		const request = route.request();
		const url = new URL(request.url());
		seen.push({ method: request.method(), url: request.url() });
		await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(syntheticPrincipalAudit(Number(url.searchParams.get('page') || '1'), 41)) });
	});
	await loginAs(page, 'administrator');
	await page.goto(ACTIVITY_URL);
	const root = page.locator('.xnetvn-agent-hub[data-view="activity"]');
	await expect(root).toBeVisible();
	const source = root.locator('[data-filter="principal_type"]');
	const credential = root.locator('[data-filter="credential_ref"]');
	const owner = root.locator('[data-filter="owner"]');
	const keyId = root.locator('[data-filter="key_id"]');
	const status = root.locator('.xnetvn-status');
	const apply = () => root.locator('[data-apply]').click();
	const entriesAt = index => Object.fromEntries(new URL(seen[index].url).searchParams.entries());
	await expect(root.locator('[data-activity] tr')).toHaveCount(20);
	const baseline = seen.length;
	const me = await myId(page);

	seen.forEach(record => {
		expect(record.method, 'audit requests are GETs').toBe('GET');
		const names = Array.from(new URL(record.url).searchParams.keys());
		expect(new Set(names).size, 'no duplicated parameter name').toBe(names.length);
	});

	await source.selectOption('site');
	await apply();
	await expect.poll(() => seen.length).toBe(baseline + 1);
	let params = entriesAt(baseline);
	expect(params.all).toBe('true');
	expect(params.principal_type).toBe('site');
	expect(params.page).toBe('1');
	expect(params.per_page).toBe('20');
	expect(params.user_id).toBeUndefined();
	expect(params.key_id).toBeUndefined();
	expect(params.credential_ref).toBeUndefined();
	await expect(status).toHaveText('');

	await source.selectOption('all');
	await credential.fill('xns:abcdefghijkl');
	await apply();
	await expect.poll(() => seen.length).toBe(baseline + 2);
	params = entriesAt(baseline + 1);
	expect(params.all).toBe('true');
	expect(params.credential_ref).toBe('xns:abcdefghijkl');
	expect(params.principal_type).toBeUndefined();
	expect(params.user_id).toBeUndefined();

	await owner.fill(String(me));
	await apply();
	await expect(status).toHaveText(messageOwnerNotForWebsite);
	expect(seen.length, 'refused owner never sends').toBe(baseline + 2);
	await expect(credential).not.toHaveAttribute('aria-invalid', 'true');
	await expect(root.locator('[data-loading]')).toBeHidden();
	await expect(root.locator('[data-activity] tr')).toHaveCount(20);
	await expect(root.locator('[data-page]')).toContainText('Page 1');

	await owner.fill('');
	await source.selectOption('site');
	await keyId.fill('abcdefghijkl');
	await apply();
	await expect(status).toHaveText(messageKeyNotForWebsite);
	expect(seen.length, 'refused key never sends').toBe(baseline + 2);

	await keyId.fill('');
	await source.selectOption('user');
	await apply();
	await expect(status).toHaveText(messageWebsiteReferenceNeedsSource);
	await expect(credential).toHaveAttribute('aria-invalid', 'true');
	expect(seen.length, 'refused source never sends').toBe(baseline + 2);

	await source.selectOption('site');
	await credential.fill('xnv:abcdefghijkl');
	await apply();
	await expect(status).toHaveText(messagePersonalReferenceNotWebsite);
	await expect(credential).toHaveAttribute('aria-invalid', 'true');
	expect(seen.length, 'refused personal reference never sends').toBe(baseline + 2);

	await source.selectOption('all');
	await keyId.fill('abcdefghijkl');
	await credential.fill('xnv:bcdefghijklm');
	await apply();
	await expect(status).toHaveText(messageReferenceMismatch);
	await expect(credential).toHaveAttribute('aria-invalid', 'true');
	expect(seen.length, 'refused mismatch never sends').toBe(baseline + 2);

	await credential.fill('xnv:abcdefghijkl');
	await apply();
	await expect.poll(() => seen.length).toBe(baseline + 3);
	params = entriesAt(baseline + 2);
	expect(params.all).toBe('true');
	expect(params.key_id).toBe('abcdefghijkl');
	expect(params.credential_ref).toBe('xnv:abcdefghijkl');
	expect(params.principal_type).toBeUndefined();
	await expect(credential).not.toHaveAttribute('aria-invalid', 'true');

	await credential.fill('xns:ABCDEFGHIJKL');
	await apply();
	await expect(status).toHaveText(messageInvalidReference);
	await expect(credential).toHaveAttribute('aria-invalid', 'true');
	expect(seen.length, 'refused malformed reference never sends').toBe(baseline + 3);

	await expect(root.locator('[data-next]')).toBeEnabled();
	await root.locator('[data-next]').click();
	await expect.poll(() => seen.length).toBe(baseline + 4);
	params = entriesAt(baseline + 3);
	expect(params.page).toBe('2');
	expect(params.all).toBe('true');
	expect(params.key_id).toBe('abcdefghijkl');
	expect(params.credential_ref).toBe('xnv:abcdefghijkl');
	expect(seen[baseline + 3].url, 'refused candidate never enters paging').not.toContain('ABCDEFGHIJKL');

	await root.locator('[data-clear]').click();
	await expect.poll(() => seen.length).toBe(baseline + 5);
	params = entriesAt(baseline + 4);
	expect(params.page).toBe('1');
	expect(params.per_page).toBe('20');
	expect(params.all).toBe('true');
	expect(params.principal_type).toBeUndefined();
	expect(params.credential_ref).toBeUndefined();
	expect(params.key_id).toBeUndefined();
	expect(params.user_id).toBeUndefined();
	await expect(source).toHaveValue('all');
	await expect(credential).toHaveValue('');
	await expect(credential).not.toHaveAttribute('aria-invalid', 'true');
	await page.unroute(AUDIT_ROUTE);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-AC-07 non-managers cannot open the activity screen or request its listing', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const seen = [];
	await page.route(AUDIT_ROUTE, async route => {
		seen.push(route.request().url());
		await route.continue();
	});
	await loginAs(page, 'editor');
	await page.goto(ACTIVITY_URL);
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
	await expect(page.locator('body')).toHaveText(/not allowed to access this page|You cannot manage Agent Hub/);
	expect(seen.length, 'a denied page sends no audit request').toBe(0);
	await page.unroute(AUDIT_ROUTE);
	expect(errors, 'no client script error').toEqual([]);
});
