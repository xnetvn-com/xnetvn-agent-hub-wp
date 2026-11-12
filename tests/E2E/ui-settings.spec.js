const { test, expect } = require('@playwright/test');
const { loginAs, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');

/**
 * UI-ST Settings: grouped fields, the role override mode and safe submissions.
 *
 * Runner flow (lab shell, not the browser): snapshot the fixture with
 * `wp eval-file tests/E2E/ui-fixture.php snapshot`, run this suite, then run the
 * `restore` scenario in a finally block so the site options return to their
 * pre-suite values. The browser only saves through the native Settings API
 * form (options.php): no new route, no AJAX save, no retry. Settings that cannot
 * be written from a browser (an unreadable stored value) are covered by
 * AdminPagesTest, which renders the same repair guidance.
 */

const SETTINGS_URL = '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings';
const OPTIONS_URL = '**/wp-admin/options.php';
const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;

const GROUP_TITLES = ['Access', 'Network', 'Limits', 'Audit and data', 'Notifications', 'Media'];

/** Documented site defaults this suite writes back after it mutates them. */
const SITE_DEFAULTS = {
	max_keys_per_user: 10,
	rate_limit_key_per_min: 120,
	tier_ceiling: 'destructive',
	allowed_roles_override: null,
};

const DEFAULT_RANGE = [1, 4194304];
const RANGES = {
	media_remote_max_bytes: [1024, 67108864],
	media_data_max_bytes: [1024, 8388608],
	media_remote_timeout_seconds: [3, 30],
	media_remote_max_redirects: [0, 5],
	media_pixels_max: [1000000, 100000000],
};

const BOOLEAN_FIELDS = [
	'enabled', 'allow_admin_tier', 'fallback_header_enabled', 'anonymize_ip',
	'disable_adapter_default_server', 'notify_key_created', 'notify_key_revoked',
	'notify_key_expiring', 'notify_unusual_ip', 'delete_data_on_uninstall',
	'media_remote_fetch_enabled',
];

const INTEGER_FIELDS = [
	'max_keys_per_user', 'max_key_lifetime_days', 'rate_limit_key_per_min',
	'rate_limit_auth_fail_per_min_ip', 'output_max_bytes', 'audit_retention_days',
	'media_remote_max_bytes', 'media_remote_timeout_seconds', 'media_remote_max_redirects',
	'media_data_max_bytes', 'media_pixels_max',
];

const LIST_FIELDS = [
	'allowed_roles_override', 'allowed_origins', 'site_ip_allowlist', 'trusted_proxy_headers',
	'trusted_proxy_cidrs', 'exposed_third_party_abilities', 'media_mime_denylist',
];

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

/**
 * Read validated settings through the existing cookie-authenticated management
 * API. Only the fields this suite asserts are returned; origins and IP lists
 * stay out of assertions so artifacts never carry raw network data.
 */
async function readStored(page, names) {
	return page.evaluate(async (requested) => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/settings', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		if (!response.ok) {
			return { status: response.status };
		}
		const body = await response.json();
		const picked = {};
		requested.forEach(name => { picked[name] = body[name] === undefined ? null : body[name]; });
		return { status: response.status, settings: picked };
	}, names);
}

/** Save through the native Submit button and wait for the round trip to finish. */
async function saveSettings(page) {
	const posted = page.waitForResponse(response => response.url().includes('/wp-admin/options.php'), { timeout: 15000 });
	await page.locator('form.xnetvn-agent-hub input[type="submit"]').click();
	await posted;
	await page.waitForURL(/page=xnetvn-agent-hub-wp-settings/);
	await page.waitForLoadState('load');
	await expect(page.locator('form.xnetvn-agent-hub')).toBeVisible();
}

test('UI-ST-01 grouped settings keep every field, its bounds and one saving submit', async ({ page }) => {
	await loginAs(page, 'administrator');
	const submissions = [];
	await page.route(OPTIONS_URL, async route => {
		submissions.push(route.request().method());
		await route.continue();
	});
	await page.goto(SETTINGS_URL);
	const form = page.locator('form.xnetvn-agent-hub');
	await expect(form).toBeVisible();
	await expect(form).toHaveAttribute('action', /\/wp-admin\/options\.php$/);
	await expect(form.locator('input[name="_wpnonce"]')).toHaveCount(1);

	await expect(form.locator('fieldset.xnetvn-settings-group')).toHaveCount(6);
	await expect(form.locator('details')).toHaveCount(0);
	for (const title of GROUP_TITLES) {
		await expect(form.locator('legend', { hasText: title })).toHaveCount(1);
	}

	for (const name of BOOLEAN_FIELDS) {
		const field = form.locator(`[data-field="${name}"]`);
		await expect(field).toHaveCount(1);
		await expect(field.locator('input[type="hidden"][value="0"]')).toHaveCount(1);
		await expect(field.locator('input[type="checkbox"]')).toHaveCount(1);
		await expect(field.locator('label[for]')).toHaveCount(1);
		expect(await field.locator('[aria-describedby]').first().getAttribute('aria-describedby'))
			.toContain(`xnetvn-help-${name}`);
	}
	for (const name of INTEGER_FIELDS) {
		const field = form.locator(`[data-field="${name}"]`);
		await expect(field).toHaveCount(1);
		const input = field.locator('input[type="number"]');
		await expect(input).toHaveCount(1);
		const range = RANGES[name] || DEFAULT_RANGE;
		expect(await input.getAttribute('min')).toBe(String(range[0]));
		expect(await input.getAttribute('max')).toBe(String(range[1]));
		expect(await field.locator(`#xnetvn-help-${name}`).count()).toBe(1);
	}
	await expect(form.locator('[data-field="tier_ceiling"] select')).toHaveCount(1);
	for (const name of LIST_FIELDS) {
		const field = form.locator(`[data-field="${name}"]`);
		await expect(field).toHaveCount(1);
		await expect(field.locator('textarea')).toHaveCount(1);
		await expect(field.locator(`#xnetvn-help-${name}`)).toHaveCount(1);
	}
	const mode = form.locator('[data-field="allowed_roles_override_mode"]');
	await expect(mode.locator('input[type="hidden"][value="0"]')).toHaveCount(1);
	await expect(mode.locator('input[type="checkbox"]')).toHaveCount(1);

	const watched = INTEGER_FIELDS.concat(['allowed_roles_override', 'tier_ceiling']);
	const before = await readStored(page, watched);
	expect(before.status).toBe(200);

	await form.locator('[data-field="max_keys_per_user"] input').fill('7');
	await form.locator('[data-field="rate_limit_key_per_min"] input').fill('45');
	await form.locator('[data-field="tier_ceiling"] select').selectOption('write');
	await form.locator('[data-field="allowed_roles_override_mode"] input[type="checkbox"]').check();
	await form.locator('[data-field="allowed_roles_override"] textarea').fill('editor');
	await saveSettings(page);

	const after = await readStored(page, watched);
	expect(after.status).toBe(200);
	expect(after.settings.max_keys_per_user).toBe(7);
	expect(after.settings.rate_limit_key_per_min).toBe(45);
	expect(after.settings.tier_ceiling).toBe('write');
	expect(after.settings.allowed_roles_override).toEqual(['editor']);
	// A field the actor never touched keeps the value the form re-submitted.
	expect(after.settings.media_pixels_max).toBe(before.settings.media_pixels_max);
	expect(after.settings.audit_retention_days).toBe(before.settings.audit_retention_days);
	await expect(form.locator('[data-field="max_keys_per_user"] input')).toHaveValue('7');
	await expect(form.locator('[data-field="allowed_roles_override"] textarea')).toHaveValue('editor');
	expect(submissions).toEqual(['POST']);

	// Write the documented defaults back so other suites see the same site.
	await form.locator('[data-field="max_keys_per_user"] input').fill(String(SITE_DEFAULTS.max_keys_per_user));
	await form.locator('[data-field="rate_limit_key_per_min"] input').fill(String(SITE_DEFAULTS.rate_limit_key_per_min));
	await form.locator('[data-field="tier_ceiling"] select').selectOption(SITE_DEFAULTS.tier_ceiling);
	await form.locator('[data-field="allowed_roles_override"] textarea').fill('');
	await form.locator('[data-field="allowed_roles_override_mode"] input[type="checkbox"]').uncheck();
	await saveSettings(page);
	const restored = await readStored(page, watched);
	expect(restored.settings.max_keys_per_user).toBe(SITE_DEFAULTS.max_keys_per_user);
	expect(restored.settings.rate_limit_key_per_min).toBe(SITE_DEFAULTS.rate_limit_key_per_min);
	expect(restored.settings.tier_ceiling).toBe(SITE_DEFAULTS.tier_ceiling);
	expect(restored.settings.allowed_roles_override).toBeNull();
});

test('UI-ST-02 the role override mode stores null, deny-all and one list', async ({ page }) => {
	await loginAs(page, 'administrator');
	await page.goto(SETTINGS_URL);
	const form = page.locator('form.xnetvn-agent-hub');
	await expect(form).toBeVisible();
	const mode = form.locator('[data-field="allowed_roles_override_mode"] input[type="checkbox"]');
	const roles = form.locator('[data-field="allowed_roles_override"] textarea');
	const watching = ['allowed_roles_override', 'enabled', 'tier_ceiling', 'max_keys_per_user'];
	const start = await readStored(page, watching);
	expect(start.status).toBe(200);

	await mode.uncheck();
	await roles.fill('editor');
	await saveSettings(page);
	const off = await readStored(page, watching);
	expect(off.settings.allowed_roles_override).toBeNull();
	await expect(mode).not.toBeChecked();
	// With the mode off the typed list is not stored, so the field renders empty.
	await expect(roles).toHaveValue('');

	await mode.check();
	await roles.fill('');
	await saveSettings(page);
	const deny = await readStored(page, watching);
	expect(deny.settings.allowed_roles_override).toEqual([]);
	await expect(mode).toBeChecked();
	await expect(roles).toHaveValue('');
	expect(deny.settings.enabled).toBe(start.settings.enabled);
	expect(deny.settings.tier_ceiling).toBe(start.settings.tier_ceiling);

	await roles.fill('editor');
	await saveSettings(page);
	const listed = await readStored(page, watching);
	expect(listed.settings.allowed_roles_override).toEqual(['editor']);
	await expect(mode).toBeChecked();
	await expect(roles).toHaveValue('editor');

	await mode.uncheck();
	await saveSettings(page);
	const restored = await readStored(page, watching);
	expect(restored.settings.allowed_roles_override).toBeNull();
	await expect(mode).not.toBeChecked();
});

test('UI-ST-03 invalid input never submits and a rejected save stays readable', async ({ page }) => {
	await loginAs(page, 'administrator');
	const submissions = [];
	await page.route(OPTIONS_URL, async route => {
		submissions.push(`${route.request().method()} ${new URL(route.request().url()).pathname}`);
		await route.continue();
	});
	await page.goto(SETTINGS_URL);
	const form = page.locator('form.xnetvn-agent-hub');
	await expect(form).toBeVisible();
	const save = form.locator('input[type="submit"]');
	await expect(save).toBeEnabled();

	const watched = ['max_keys_per_user', 'output_max_bytes'];
	const before = await readStored(page, watched);
	expect(before.status).toBe(200);

	// Below the documented minimum: the browser blocks the post and focuses the field.
	await form.locator('[data-field="max_keys_per_user"] input').fill('0');
	await save.click();
	await expect(form).toBeVisible();
	await expect.poll(async () => page.evaluate(() => {
		const active = document.activeElement;
		return active ? active.id : '';
	})).toBe('xnetvn-setting-max_keys_per_user');
	expect(submissions).toEqual([]);
	await expect(save).toBeEnabled();

	// A value the server rejects keeps the stored policy and shows the settings error.
	await form.locator('[data-field="max_keys_per_user"] input').fill('6');
	await form.locator('[data-field="allowed_origins"] textarea').fill('example.com');
	await saveSettings(page);
	const rejected = await readStored(page, watched);
	expect(rejected.settings.max_keys_per_user).toBe(before.settings.max_keys_per_user);
	// Only a boolean is asserted: the stored origin list is never printed.
	expect(await page.evaluate(async () => {
		const response = await fetch('/wp-json/xnetvn-agent-hub-wp/v1/settings', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		const body = await response.json();
		return !(Array.isArray(body.allowed_origins) && body.allowed_origins.some((origin) => origin === 'example.com'));
	})).toBe(true);
	const errors = page.locator('.xnetvn-settings-errors');
	await expect(errors).toBeVisible();
	await expect(errors).toHaveAttribute('role', 'alert');
	await expect(errors).toContainText('Settings were not saved');
	await expect(errors).toContainText('Check the field types');
	expect(await errors.textContent()).not.toContain('example.com');
	await errors.evaluate(node => node.focus());
	expect(await page.evaluate(() => document.activeElement === document.querySelector('.xnetvn-settings-errors'))).toBe(true);
	await expect(form.locator('[data-field="max_keys_per_user"] input')).toHaveValue(String(before.settings.max_keys_per_user));
	expect(submissions).toEqual(['POST /wp-admin/options.php']);

	// A valid value saves after the failure without a retry loop.
	await form.locator('[data-field="output_max_bytes"] input').fill(String(before.settings.output_max_bytes));
	await saveSettings(page);
	const saved = await readStored(page, watched);
	// The rejected save changed nothing, so the value the form re-submitted is the stored one.
	expect(saved.settings.output_max_bytes).toBe(before.settings.output_max_bytes);
	expect(saved.status).toBe(200);
	expect(submissions).toEqual(['POST /wp-admin/options.php', 'POST /wp-admin/options.php']);
});

test('UI-ST-03 groups reflow, keep keyboard focus and pass automated accessibility checks', async ({ page }) => {
	await loginAs(page, 'administrator');
	for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
		await page.setViewportSize(size);
		await page.goto(SETTINGS_URL);
		const form = page.locator('form.xnetvn-agent-hub');
		await expect(form).toBeVisible();
		await expect(form.locator('fieldset.xnetvn-settings-group')).toHaveCount(6);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow, `body overflow at ${size.width}px`).toBeLessThanOrEqual(1);
	}
	await page.setViewportSize({ width: 320, height: 800 });
	await page.goto(SETTINGS_URL);
	const narrow = page.locator('form.xnetvn-agent-hub');
	await expect(narrow).toBeVisible();
	const narrowOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(narrowOverflow, 'body overflow at 320px').toBeLessThanOrEqual(1);
	await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
	const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	expect(rtlOverflow, 'body overflow in RTL').toBeLessThanOrEqual(1);
	await page.evaluate(() => document.documentElement.setAttribute('dir', 'ltr'));
	await narrow.locator('[data-field="max_keys_per_user"] input').focus();
	const focused = await page.evaluate(() => {
		const active = document.activeElement;
		if (!active) {
			return { visible: false };
		}
		const rect = active.getBoundingClientRect();
		return { visible: rect.width > 0 && rect.height > 0 && rect.top >= 0 && rect.left >= 0 };
	});
	expect(focused.visible).toBe(true);
	const AxeBuilder = require('@axe-core/playwright').default;
	const results = await new AxeBuilder({ page }).include('form.xnetvn-agent-hub').withTags(['wcag2a', 'wcag2aa']).analyze();
	expect(results.violations.map(violation => violation.id)).toEqual([]);
	await page.setViewportSize({ width: 1440, height: 900 });
	if (ARTIFACTS_DIR) {
		await assertSafeArtifacts(ARTIFACTS_DIR);
	}
});

test('UI-ST-03 actors without management are denied the settings screen', async ({ page }) => {
	await loginAs(page, 'editor');
	await page.goto(SETTINGS_URL);
	await expect(page.locator('form.xnetvn-agent-hub')).toHaveCount(0);
	await expect(page.locator('body')).toContainText(/not allowed to access this page|You cannot manage Agent Hub/);
});
