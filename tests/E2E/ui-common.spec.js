const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

const ADMIN_VIEWS = ['overview', 'keys', 'activity', 'tools', 'settings', 'help'];
const VIEW_URL = {
	overview: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp',
	keys: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys',
	activity: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-activity',
	tools: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-tools',
	settings: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings',
	help: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-help',
};

/** Synthetic read-only rows for the owned keys listing. Never a secret. */
function syntheticKeys(pageNumber, prefix) {
	const rows = [];
	const count = pageNumber === 1 ? 20 : 5;
	for (let index = 0; index < count; index += 1) {
		rows.push({
			id: 9000 + pageNumber * 100 + index,
			name: `${prefix} row ${pageNumber}-${index}`,
			key_id: `kid-${prefix}-${pageNumber}-${index}`,
			user_id: 1,
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

test('UI-COM-01 unified navigation marks the current view and keeps one heading', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const view of ADMIN_VIEWS) {
		await page.goto(VIEW_URL[view]);
		const wrap = page.locator('#wpbody-content .wrap');
		await expect(wrap).toBeVisible();
		await expect(wrap.locator('h1')).toHaveCount(1);
		const nav = wrap.locator('nav.xnetvn-nav');
		await expect(nav, `navigation on ${view}`).toBeVisible();
		await expect(nav.locator('a')).toHaveCount(6);
		const current = nav.locator('a[aria-current="page"]');
		await expect(current, `current link on ${view}`).toHaveCount(1);
		await expect(current).toHaveAttribute('href', new RegExp(VIEW_URL[view].replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(#|$)'));
	}
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('.xnetvn-agent-hub[data-view="keys"]')).toBeVisible();
	await expect(page.locator('nav.xnetvn-nav')).toHaveCount(0);
});

test('UI-COM-04 plugin styles stay inside the hub and assets stay scoped', async ({ page }) => {
	await loginAs(page, 'administrator');
	await page.goto(VIEW_URL.keys);
	const hub = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(hub).toBeVisible();
	const inspected = await page.evaluate(() => {
		const link = document.querySelector('link#xnetvn-agent-hub-wp-admin-css');
		if (!link || !link.sheet) {
			return { missing: true, bad: [] };
		}
		const bad = [];
		const rules = link.sheet.cssRules;
		for (const rule of rules) {
			if (rule.type !== CSSRule.STYLE_RULE) {
				continue;
			}
			const selector = rule.selectorText || '';
			if (selector === '') {
				continue;
			}
			// The colour-scheme contrast requirement (TESTING.md §4.4.1) needs
			// `.wp-core-ui .xnetvn-agent-hub .button` to outrank the core rule, so a
			// leading `.wp-core-ui ` ancestor is allowed; the remainder must still
			// start inside the hub.
			const scoped = selector.split(',').every(part => {
				const trimmed = part.trim();
				const remainder = trimmed.startsWith('.wp-core-ui ') ? trimmed.slice('.wp-core-ui '.length).trim() : trimmed;
				return remainder.startsWith('.xnetvn-');
			});
			if (!scoped) {
				bad.push(selector);
			}
		}
		return { missing: false, bad: bad.slice(0, 20) };
	});
	expect(inspected.missing).toBe(false);
	expect(inspected.bad).toEqual([]);
	await page.goto('/wp-admin/edit.php');
	expect(await page.evaluate(() => typeof window.xnetvnAgentHubConfig)).toBe('undefined');
	await expect(page.locator('.xnetvn-agent-hub')).toHaveCount(0);
});

test('UI-COM-02 slow reads announce loading without wiping current rows', async ({ page }) => {
	const mutations = [];
	let firstPageTwo = true;
	await page.route('**/xnetvn-agent-hub-wp/v1/keys*', async route => {
		if (route.request().method() !== 'GET') {
			mutations.push(`${route.request().method()} ${route.request().url()}`);
			await route.continue();
			return;
		}
		const url = new URL(route.request().url());
		const requested = url.searchParams.get('page') || '1';
		if (requested === '2' && firstPageTwo) {
			firstPageTwo = false;
			await new Promise(resolve => setTimeout(resolve, 1200));
		}
		await route.fulfill({
			status: 200,
			headers: { 'Content-Type': 'application/json', 'X-WP-Total': '25' },
			body: JSON.stringify(syntheticKeys(Number(requested), 'UI-COM slow')),
		});
	});
	await loginAs(page, 'administrator');
	const hub = await hubFor(page, 'keys');
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	await expect(root.locator('[data-keys] tr').first()).toContainText('UI-COM slow row 1-0');

	await root.locator('[data-next]').click();
	const loading = root.locator('[data-loading]');
	await expect(loading).toBeVisible();
	expect(await root.getAttribute('aria-busy')).toBe('true');
	await expect(root.locator('[data-prev]')).toBeDisabled();
	await expect(root.locator('[data-next]')).toBeDisabled();
	await expect(root.locator('[data-keys] tr').first()).toBeVisible();
	await expect(root.locator('[data-page]')).toContainText('Page 2', { timeout: 15000 });
	expect(await root.getAttribute('aria-busy')).toBe('false');
	await expect(root.locator('[data-keys] tr').first()).toContainText('UI-COM slow row 2-0');
	expect(mutations).toEqual([]);
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys*');
	expect(hub).toBeTruthy();
});

test('UI-COM-02 failed reads keep data, offer one retry GET and never mutate', async ({ page }) => {
	const seen = [];
	const mutations = [];
	let failures = 1;
	let primed = false;
	await page.route('**/xnetvn-agent-hub-wp/v1/keys*', async route => {
		const method = route.request().method();
		const url = new URL(route.request().url());
		if (method !== 'GET') {
			mutations.push(`${method} ${url.pathname}`);
			await route.continue();
			return;
		}
		const requested = url.searchParams.get('page') || '1';
		if (primed && failures > 0 && requested === '2') {
			failures -= 1;
			seen.push(`error:${requested}`);
			await route.fulfill({ status: 500, headers: { 'Content-Type': 'application/json' }, body: '{}' });
			return;
		}
		seen.push(requested);
		await route.fulfill({
			status: 200,
			headers: { 'Content-Type': 'application/json', 'X-WP-Total': '25' },
			body: JSON.stringify(syntheticKeys(Number(requested), 'UI-COM kept')),
		});
	});
	await loginAs(page, 'administrator');
	const hub = await hubFor(page, 'keys');
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root.locator('[data-keys] tr').first()).toContainText('UI-COM kept row 1-0');
	const before = await root.locator('[data-keys] tr').count();
	primed = true;

	await root.locator('[data-next]').click();
	const error = root.locator('[data-error]');
	await expect(error).toBeVisible();
	await expect(root.locator('[data-error-text]')).not.toBeEmpty();
	await expect(root.locator('[data-retry]')).toContainText('Try again');
	expect(await root.locator('[data-keys] tr').count()).toBe(before);
	await expect(root.locator('[data-page]')).toContainText('Page 1');
	const getsBefore = seen.length;
	await root.locator('[data-retry]').click();
	await expect(root.locator('[data-page]')).toContainText('Page 2', { timeout: 15000 });
	expect(seen.length - getsBefore).toBe(1);
	await expect(root.locator('[data-keys] tr').first()).toContainText('UI-COM kept row 2-0');
	expect(mutations).toEqual([]);
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys*');
	expect(hub).toBeTruthy();
});

test('UI-COM-02 expired sessions guide reload and login without raw errors', async ({ page }) => {
	await page.route('**/xnetvn-agent-hub-wp/v1/keys*', async route => {
		await route.fulfill({
			status: 401,
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ code: 'rest_forbidden', message: 'synthetic session marker' }),
		});
	});
	await loginAs(page, 'administrator');
	await page.goto(VIEW_URL.keys);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	await expect(root.locator('[data-error]')).toBeVisible({ timeout: 15000 });
	const message = await root.locator('[data-error-text]').textContent();
	expect(message || '').toMatch(/session|log in|reload/i);
	expect(message || '').not.toContain('synthetic session marker');
	await page.unroute('**/xnetvn-agent-hub-wp/v1/keys*');
});

test('UI-COM-03 tables reflow without page overflow and keep keyboard focus', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		await page.goto(VIEW_URL.keys);
		const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
		await expect(root).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
		const wrap = root.locator('.xnetvn-table-wrap');
		await expect(wrap).toBeVisible();
		expect(await wrap.getAttribute('tabindex')).toBe('0');
		expect(await wrap.getAttribute('aria-label')).not.toBeNull();
	}
	await page.setViewportSize({ width: 320, height: 800 });
	await page.goto(VIEW_URL.overview);
	const overview = page.locator('.xnetvn-agent-hub[data-view="overview"]');
	await expect(overview).toBeVisible();
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.goto(VIEW_URL.keys);
	const keys = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await keys.locator('[data-next], [data-prev]').first().focus();
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
	await page.setViewportSize({ width: 1440, height: 900 });
});

test('UI-COM-03 right-to-left layout keeps content inside the viewport', async ({ page }) => {
	await loginAs(page, 'administrator');
	await page.goto(VIEW_URL.keys);
	const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
	await expect(root).toBeVisible();
	await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
	const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow, 'body overflow in RTL').toBeLessThanOrEqual(1);
	await page.evaluate(() => document.documentElement.setAttribute('dir', 'ltr'));
});

test('UI-COM-04 lab artifacts stay free of credentials', async ({ page }) => {
	await loginAs(page, 'administrator');
	await hubFor(page, 'overview');
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});
