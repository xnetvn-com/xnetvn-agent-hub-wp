const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-NT environment notices with cause and next step.
 *
 * Runner flow (lab shell, not the browser), in this exact order:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. notice: the same command with notice instead of snapshot
 * 3. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-NT'
 * 4. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * The `notice` scenario registers one synthetic php_version failure
 * (required `99.0-fixture`, actual `0.0-fixture`) for fixture actors only,
 * so a red UI-NT-01 almost always means `notice` was never run. The browser
 * never mutates server files. Markup escaping is proved by unit tests; the
 * fixture actual value is clean by design so screenshots stay free of payloads.
 */

// Resolve the lab site name from the configured base URL so a missing
// prerequisite message names the exact site this suite ran against.
const LAB_SITE = (() => {
	const match = /-(wp[0-9]+)\./.exec(new URL(process.env.BASE_URL || 'https://xnetvn-agent-hub-wp-wp69.vm-2-215.local').hostname);
	return match ? match[1] : 'wp69';
})();

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const DASHBOARD = '/wp-admin/index.php';
const PROFILE = '/wp-admin/profile.php';

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

test('UI-NT-01 fixture administrator sees the cause and the next step', async ({ page }) => {
	await loginAs(page, 'administrator');
	await page.goto(DASHBOARD);
	const notice = page.locator('.notice.notice-error', { hasText: '99.0-fixture' });
	// Runner flow is snapshot, notice, e2e, restore: the `notice` scenario
	// registers the MU notice, so fail fast when it was never enabled.
	if (await notice.count() === 0) {
		throw new Error(`UI fixture notice missing (no .notice.notice-error with 99.0-fixture on ${DASHBOARD}). Run the fixture scenarios first: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh ${LAB_SITE} eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include then the same command with notice`);
	}
	await expect(notice.first()).toBeVisible();
	await expect(notice.first()).toContainText('Requirement not met');
	await expect(notice.first()).toContainText('PHP');
	await expect(notice.first()).toContainText('99.0-fixture');
	await expect(notice.first()).toContainText('0.0-fixture');
	await expect(notice.first()).toContainText('Next step');
	await expect(notice.first()).not.toHaveClass(/is-dismissible/);
	const inner = await notice.first().innerHTML();
	expect(inner).not.toContain('<script');
	expect(inner).not.toContain('<link');
});

test('UI-NT-02 actor without activate_plugins sees no environment notice', async ({ page }) => {
	await loginAs(page, 'subscriber');
	await page.goto(PROFILE);
	await expect(page.locator('.notice.notice-error', { hasText: '99.0-fixture' })).toHaveCount(0);
});

test('UI-NT-01 notice reflows, keeps keyboard focus and meets contrast', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		await page.goto(DASHBOARD);
		const notice = page.locator('.notice.notice-error', { hasText: '99.0-fixture' });
		// Runner flow is snapshot, notice, e2e, restore: the `notice` scenario
		// registers the MU notice, so fail fast when it was never enabled.
		if (await notice.count() === 0) {
			throw new Error(`UI fixture notice missing (no .notice.notice-error with 99.0-fixture on ${DASHBOARD}). Run the fixture scenarios first: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh ${LAB_SITE} eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include then the same command with notice`);
		}
		await expect(notice.first()).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	await page.goto(DASHBOARD);
	await expect(page.locator('.notice.notice-error', { hasText: '99.0-fixture' }).first()).toBeVisible();
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.setViewportSize({ width: 1440, height: 900 });
	await page.goto(DASHBOARD);
	await page.locator('#menu-dashboard a').first().focus();
	const focused = await page.evaluate(() => {
		const active = document.activeElement;
		if (!active) {
			return { visible: false };
		}
		const rect = active.getBoundingClientRect();
		return { visible: rect.width > 0 && rect.height > 0 && rect.top >= 0 && rect.left >= 0 };
	});
	expect(focused.visible).toBe(true);
	const results = await new AxeBuilder({ page }).include('.notice.notice-error').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});
