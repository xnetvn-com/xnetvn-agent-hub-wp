/**
 * Capture the ten WordPress.org listing screenshots on the shared lab.
 *
 * Prerequisites, in order, from the main checkout:
 *   RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" up
 *   RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec wp69 -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/docs/listing/capture-fixture.php seed --use-include
 *   RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e -- npx playwright test --config=docs/listing/playwright.capture.config.js
 *
 * Every shot is a real admin screen of this plugin. Two lab-specific values are
 * replaced before capture: the lab host in the endpoint field becomes
 * https://example.com (the documented placeholder), and the Overview stored-key
 * count, which is lab fixture state, is removed. Screenshot 3 redacts the
 * one-time key value. The key created by the UI is revoked through the
 * management API right after screenshot 4, and a teardown revokes it if an
 * earlier step fails.
 */
import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const ARTIFACTS = process.env.RTL_ARTIFACTS_DIR || '/artifacts';
const SHOTS = join(ARTIFACTS, 'listing');
mkdirSync(SHOTS, { recursive: true });

test.use({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2, locale: 'en-US' });

// Key created through the UI during this run. The teardown revokes it when a
// later step fails and annotates any cleanup that does not return 200.
let createdKeyId = 0;
let createdKeyRevoked = false;

test.afterEach(async ({ page }) => {
	if (!createdKeyId || createdKeyRevoked) {
		return;
	}
	const outcome = await page.evaluate(async id => {
		const config = (window as unknown as { xnetvnAgentHubConfig?: { nonce: string } }).xnetvnAgentHubConfig;
		if (!config) {
			return 'no-config';
		}
		const response = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${id}`, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': config.nonce },
		});
		return response.ok ? 'revoked' : `http-${response.status}`;
	}, createdKeyId).catch(error => `error-${String(error)}`);
	if (outcome !== 'revoked') {
		test.info().annotations.push({ type: 'cleanup', description: `key ${createdKeyId} not revoked: ${outcome}` });
	}
});

async function shot(page: Page, number: number): Promise<void> {
	await page.screenshot({ path: join(SHOTS, `screenshot-${number}.png`), animations: 'disabled' });
}

async function settle(locator: ReturnType<Page['locator']>): Promise<void> {
	await locator.first().waitFor({ state: 'visible', timeout: 3000 }).catch(() => undefined);
}

/** Put a block near the top of the viewport, clear of the admin bar. */
async function bring(page: Page, selector: string, offset = 96): Promise<void> {
	await page.locator(selector).first().evaluate((element, top) => {
		const y = element.getBoundingClientRect().top + window.scrollY - top;
		// Instant scroll: wp-admin sets scroll-behavior: smooth, which would
		// otherwise be captured mid-animation.
		window.scrollTo({ top: y, left: 0, behavior: 'instant' as ScrollBehavior });
	}, offset);
}

/**
 * Replace lab-specific values that would date or identify the screenshots:
 * the lab host in the endpoint field and the stored-key count on Overview,
 * which reflects shared fixture data rather than a product capability.
 */
async function redactEnvironment(page: Page): Promise<void> {
	await page.evaluate(() => {
		const endpoint = 'https://example.com/wp-json/xnetvn-agent-hub-wp/mcp';
		for (const id of ['xnetvn-endpoint', 'xnetvn-help-endpoint']) {
			const input = document.getElementById(id);
			if (input instanceof HTMLInputElement) {
				input.value = endpoint;
			}
		}
		document.querySelectorAll('[data-status-site] dt').forEach(term => {
			if (term.textContent?.trim() === 'Stored keys') {
				const value = term.nextElementSibling;
				term.remove();
				value?.remove();
			}
		});
	});
}

test('capture listing screenshots', async ({ page }) => {
	const password = process.env.XNETVN_TEST_PASSWORD;
	if (!password) {
		throw new Error('XNETVN_TEST_PASSWORD is unavailable.');
	}

	// Hide WordPress core update nags on every navigation: they are unrelated to
	// the plugin and would otherwise cover the screenshots.
	await page.addInitScript(() => {
		document.addEventListener('DOMContentLoaded', () => {
			const style = document.createElement('style');
			style.textContent = 'html{scroll-behavior:auto!important}.notice,.update-nag,#wp-admin-bar-updates{display:none!important}';
			document.head.appendChild(style);
		});
	});

	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await expect(page).toHaveURL(/wp-admin/);

	// 1. Overview: getting started, endpoint and connection status.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp');
	await expect(page.locator('#xnetvn-endpoint')).toHaveValue(/xnetvn-agent-hub-wp\/mcp/);
	await settle(page.locator('[data-status-site] dd'));
	await redactEnvironment(page);
	await shot(page, 1);

	// 2. Profile: the create-key form filled in with the lowest useful scope.
	await page.goto('/wp-admin/profile.php');
	await expect(page.locator('#xnetvn-key-name')).toBeVisible();
	await page.locator('#xnetvn-key-name').fill('Editorial assistant');
	await page.locator('#xnetvn-key-days').fill('30');
	await page.locator('#xnetvn-key-tier').selectOption('write');
	await page.locator('input[data-toolset][value="content"]').check();
	await page.locator('input[data-toolset][value="taxonomy"]').check();
	await expect(page.locator('[data-create]')).toBeEnabled();
	await bring(page, '#xnetvn-create-title', 80);
	await shot(page, 2);

	// 3. The one-time key dialog, with the value redacted before capture.
	const createdKey = page.waitForResponse(response =>
		response.url().includes('/wp-json/xnetvn-agent-hub-wp/v1/keys')
		&& response.request().method() === 'POST');
	await page.locator('[data-create]').click();
	// Read the created id before any later assertion can fail; never read the secret.
	const createdPayload = await (await createdKey).json();
	createdKeyId = Number(createdPayload?.key?.id);
	if (!Number.isInteger(createdKeyId) || createdKeyId < 1) {
		throw new Error('The created key id is unavailable.');
	}
	const secretDialog = page.locator('[data-secret-dialog]');
	await expect(secretDialog).toBeVisible();
	await page.evaluate(() => window.scrollTo(0, 0));
	await page.evaluate(() => {
		const input = document.querySelector('[data-secret]');
		if (input instanceof HTMLInputElement) {
			input.value = 'xnv_demokey1234_EXAMPLE-EXAMPLE-EXAMPLE-EXAMPLE-EXAMPLE';
			input.blur();
		}
	});
	await shot(page, 3);
	await page.locator('[data-saved]').click();
	await expect(secretDialog).toBeHidden();

	// 4. Keys: filters, status and actions across owners.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys');
	await expect(page.locator('[data-keys] tr').first()).toBeVisible();
	await shot(page, 4);

	// Revoke the key this run created, so repeats do not leave active keys.
	const revokeStatus = await page.evaluate(async id => {
		const config = (window as unknown as { xnetvnAgentHubConfig: { nonce: string } }).xnetvnAgentHubConfig;
		const response = await fetch(`/wp-json/xnetvn-agent-hub-wp/v1/keys/${id}`, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': config.nonce },
		});
		return response.status;
	}, createdKeyId);
	expect(revokeStatus).toBe(200);
	createdKeyRevoked = true;

	// 5. Tools: the reviewed catalog with tier and toolset filters.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-tools');
	await expect(page.locator('[data-tools] article').first()).toBeVisible();
	await shot(page, 5);

	// 6. Activity: recent requests across both demo owners.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-activity');
	await expect(page.locator('[data-activity] tr').first()).toBeVisible();
	await expect(page.locator('[data-loading]')).toBeHidden();
	await bring(page, '.xnetvn-table-wrap');
	await shot(page, 6);

	// 7. Activity filtered to denials, after the filtered render settles.
	await page.locator('#xnetvn-activity-outcome').selectOption('denied');
	await expect(page.locator('[data-activity] .xnetvn-outcome-success')).toHaveCount(0);
	await expect(page.locator('[data-activity] .xnetvn-outcome-denied').first()).toBeVisible();
	await expect(page.locator('[data-loading]')).toBeHidden();
	await bring(page, '.xnetvn-table-wrap');
	await shot(page, 7);

	// 8. Settings: Access group.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings');
	await expect(page.locator('[data-field="enabled"]')).toBeVisible();
	await shot(page, 8);

	// 9. Settings: Media group reached by scrolling the same form.
	await bring(page, 'fieldset[data-group="media"]', 150);
	await page.waitForTimeout(200);
	await shot(page, 9);

	// 10. Help: connect a client, troubleshooting and emergency revocation.
	await page.goto('/wp-admin/admin.php?page=xnetvn-agent-hub-wp-help');
	await expect(page.locator('[data-help-steps]')).toBeVisible();
	await redactEnvironment(page);
	await shot(page, 10);
});
