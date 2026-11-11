const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-TL tools catalog lookup by name and risk tier.
 *
 * Runner flow (lab shell, not the browser), in this exact order:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir /home/udev/xdev/projects/githubcom/wordpress/xnetvn-agent-hub-wp --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-TL'
 * 3. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * UI-TL-01 and UI-TL-02 mock the tools GET with a fixed catalog spanning
 * several toolsets and tiers, including Unicode, a long name and markup-like
 * text that must render as text. UI-TL-03 reads the real catalog. Filtering
 * is client-side: typing must never issue another tools GET. No response
 * value is ever asserted as a credential and the browser never calls MCP.
 */

const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;

const CATALOG = [
	{ name: 'content/read-post', tool_name: 'read_post', label: 'Read post', description: 'Read a published post.', tier: 'read', toolset: 'content', visibility_cap: 'read' },
	{ name: 'content/publish-post-with-a-very-long-stable-suffix-for-overflow', tool_name: 'publish_post', label: 'Xuất bản bài viết 日本語', description: 'Create or change content. Long description. Long description. Long description. Long description.', tier: 'write', toolset: 'content', visibility_cap: 'edit_posts' },
	{ name: 'media/delete-asset', tool_name: 'delete_asset', label: 'Delete media', description: 'Permanently delete <img src="x" onerror="alert(1)"> permitted media.', tier: 'destructive', toolset: 'media', visibility_cap: 'delete_posts' },
	{ name: 'system/site-info', tool_name: 'site_info', label: 'Site info', description: 'View site facts.', tier: 'read', toolset: 'system', visibility_cap: 'manage_options' },
	{ name: 'users/suspend-user', tool_name: 'suspend_user', label: 'Suspend user', description: 'Manage site operations on accounts.', tier: 'admin', toolset: 'users', visibility_cap: 'manage_options' },
];

/**
 * Serve the fixed catalog for every tools GET and count the calls.
 *
 * @param {import('@playwright/test').Page} page Browser page.
 * @returns {() => number} Reads the tools GET count so far.
 */
async function mockToolsCatalog(page) {
	let calls = 0;
	await page.route('**/xnetvn-agent-hub-wp/v1/tools**', async route => {
		if (route.request().method() !== 'GET') {
			await route.continue();
			return;
		}
		calls += 1;
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(CATALOG),
		});
	});
	return () => calls;
}

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

test('UI-TL-01 search narrows by name and two filters combine with AND', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const toolCalls = await mockToolsCatalog(page);
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'tools');
	const search = root.locator('#xnetvn-tool-search');
	const toolset = root.locator('#xnetvn-tool-toolset');
	const tier = root.locator('#xnetvn-tool-tier');
	await expect(search).toBeVisible();
	await expect(toolset).toBeVisible();
	await expect(tier).toBeVisible();
	// Every control carries an explicit label and a non-submitting type.
	expect(await root.locator('label[for="xnetvn-tool-search"]').count(), 'search label').toBe(1);
	expect(await root.locator('label[for="xnetvn-tool-toolset"]').count(), 'toolset label').toBe(1);
	expect(await root.locator('label[for="xnetvn-tool-tier"]').count(), 'tier label').toBe(1);
	expect(await search.getAttribute('type'), 'search input type').toBe('search');
	const toolsetOptions = await toolset.locator('option').allTextContents();
	expect(toolsetOptions[0], 'toolset starts with All').toMatch(/all/i);
	for (const value of ['content', 'media', 'system', 'users']) {
		expect(await toolset.locator(`option[value="${value}"]`).count(), `toolset option ${value}`).toBe(1);
	}
	for (const value of ['all', 'read', 'write', 'destructive', 'admin']) {
		expect(await tier.locator(`option[value="${value}"]`).count(), `tier option ${value}`).toBe(1);
	}
	await expect(root.locator('[data-tools] article')).toHaveCount(5);
	// A substring matches across name, label and description, case-insensitively.
	await search.fill('POST');
	await expect(root.locator('[data-tools] article')).toHaveCount(2);
	// Surrounding whitespace is trimmed before matching.
	await search.fill('  post  ');
	await expect(root.locator('[data-tools] article')).toHaveCount(2);
	// Unicode matches the localized label.
	await search.fill('日本語');
	await expect(root.locator('[data-tools] article')).toHaveCount(1);
	await expect(root.locator('[data-tools] article code').first()).toContainText('content/publish-post');
	// The two selects combine with AND: content narrows nothing here, write keeps one.
	await search.fill('post');
	await toolset.selectOption('content');
	await expect(root.locator('[data-tools] article')).toHaveCount(2);
	await tier.selectOption('write');
	await expect(root.locator('[data-tools] article')).toHaveCount(1);
	await expect(root.locator('[data-tools] article code').first()).toContainText('content/publish-post');
	// The live count names shown and total after the interaction.
	await expect(root.locator('[data-tools-count]')).toContainText('1 of 5 tools');
	// Results sort by name; filtering never refetches the catalog.
	await search.fill('');
	await toolset.selectOption('all');
	await tier.selectOption('all');
	const names = await root.locator('[data-tools] article code').allTextContents();
	expect([...names].sort((left, right) => left.localeCompare(right)), 'catalog sorts by name').toEqual(names);
	expect(toolCalls(), 'one tools GET for the whole session').toBe(1);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-TL-02 empty results clear back to the full catalog as plain text', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const toolCalls = await mockToolsCatalog(page);
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'tools');
	const search = root.locator('#xnetvn-tool-search');
	await search.fill('zzz-no-such-tool');
	await expect(root.locator('[data-tools] article')).toHaveCount(0);
	await expect(root.locator('[data-tools-no-results]')).toBeVisible();
	await expect(root.locator('[data-tools-count]')).toContainText('0 of 5 tools');
	const clear = root.locator('[data-clear]');
	await expect(clear).toContainText('Clear filters');
	expect(await clear.getAttribute('type'), 'clear never submits').toBe('button');
	await clear.click();
	// Clearing restores every control and the whole catalog.
	expect(await search.inputValue(), 'search resets').toBe('');
	expect(await root.locator('#xnetvn-tool-toolset').inputValue(), 'toolset resets').toBe('all');
	expect(await root.locator('#xnetvn-tool-tier').inputValue(), 'tier resets').toBe('all');
	await expect(root.locator('[data-tools] article')).toHaveCount(5);
	await expect(root.locator('[data-tools-no-results]')).toBeHidden();
	await expect(root.locator('[data-tools-count]')).toContainText('5 of 5 tools');
	// Markup-like catalog text renders as text, never as elements.
	const media = root.locator('[data-tools] article', { hasText: 'media/delete-asset' });
	await expect(media).toContainText('<img src="x"');
	expect(await root.locator('[data-tools] img').count(), 'no element from catalog text').toBe(0);
	expect(toolCalls(), 'clearing refetches nothing').toBe(1);
	expect(errors, 'no client script error').toEqual([]);
});

test('UI-TL-03 catalog lists metadata without any run action or MCP call', async ({ page }) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	const mcpCalls = [];
	page.on('request', request => {
		if (/\/mcp(\?|$)/.test(request.url())) {
			mcpCalls.push(request.url());
		}
	});
	await loginAs(page, 'administrator');
	const root = await hubFor(page, 'tools');
	// The real catalog feeds this case: shown and total agree on a full list.
	const count = root.locator('[data-tools-count]');
	await expect(count).toContainText(/of \d+ tools/);
	const match = /(\d+) of (\d+) tools/.exec(await count.textContent());
	expect(match, 'count names shown and total').not.toBeNull();
	const shown = Number(match[1]);
	const total = Number(match[2]);
	expect(shown, 'full catalog after load').toBe(total);
	const articles = root.locator('[data-tools] article');
	if (total > 0) {
		await expect(articles.first()).toBeVisible();
		expect(await articles.count(), 'count matches rendered articles').toBe(total);
		const first = articles.first();
		await expect(first.locator('h2')).not.toBeEmpty();
		await expect(first.locator('code').first()).not.toBeEmpty();
		expect(await first.locator('details').count(), 'technical details group').toBe(1);
		expect(await first.locator('details summary').first().textContent(), 'details label').toMatch(/technical details/i);
		expect(await first.locator('details[open]').count(), 'details start closed').toBe(0);
	}
	// No run action exists and the permission warning always explains the boundary.
	expect(await root.getByRole('button', { name: /run/i }).count(), 'no Run button').toBe(0);
	expect(await root.getByRole('link', { name: /run/i }).count(), 'no Run link').toBe(0);
	await expect(root).toContainText('Execution always rechecks');
	expect(mcpCalls, 'no MCP request from the catalog page').toEqual([]);
	// Keyboard, viewports and contrast hold on the lookup controls.
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		const resized = page.locator('.xnetvn-agent-hub[data-view="tools"]');
		await expect(resized.locator('#xnetvn-tool-search')).toBeVisible();
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	const narrow = page.locator('.xnetvn-agent-hub[data-view="tools"]');
	await expect(narrow.locator('#xnetvn-tool-search')).toBeVisible();
	const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.setViewportSize({ width: 1440, height: 900 });
	const settled = page.locator('.xnetvn-agent-hub[data-view="tools"]');
	await settled.locator('#xnetvn-tool-search').focus();
	await expect(settled.locator('#xnetvn-tool-search')).toBeFocused();
	const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub[data-view="tools"]').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
	expect(errors, 'no client script error').toEqual([]);
});
