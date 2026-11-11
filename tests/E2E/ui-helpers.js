const { expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const LOGINS = {
	administrator: 'xnetvn_ui_administrator',
	editor: 'xnetvn_ui_editor',
	author: 'xnetvn_ui_author',
	contributor: 'xnetvn_ui_contributor',
	subscriber: 'xnetvn_ui_subscriber',
	editor_b: 'xnetvn_ui_editor_b',
};

const VIEWS = {
	overview: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp', root: '.xnetvn-agent-hub[data-view="overview"]' },
	keys: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys', root: '.xnetvn-agent-hub[data-view="keys"]' },
	activity: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-activity', root: '.xnetvn-agent-hub[data-view="activity"]' },
	tools: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-tools', root: '.xnetvn-agent-hub[data-view="tools"]' },
	settings: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings', root: 'form.xnetvn-agent-hub' },
	help: { url: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-help', root: '.xnetvn-agent-hub' },
	profile: { url: '/wp-admin/profile.php', root: '.xnetvn-agent-hub[data-view="keys"]' },
};

/**
 * Log in with an owned UI fixture actor. Only the login name travels in
 * assertions; the test-only password never leaves the password field.
 */
async function loginAs(page, role) {
	if (!Object.prototype.hasOwnProperty.call(LOGINS, role)) {
		throw new Error(`Unknown fixture role: ${role}. Expected one of ${Object.keys(LOGINS).join(', ')}.`);
	}
	const password = process.env.XNETVN_TEST_PASSWORD;
	if (!password) {
		throw new Error('The test-only login password is unavailable.');
	}
	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(LOGINS[role]);
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await expect(page).toHaveURL(/wp-admin/);
}

/**
 * Log in after forcing a logged-out session. loginAs() assumes the login form
 * is served, but /wp-login.php redirects an already-authenticated session back
 * to wp-admin (no #user_login field), which times out the fill. Clearing
 * cookies first keeps the second login in one test deterministic without
 * changing what the test asserts.
 */
async function loginFresh(page, role) {
	if (/wp-admin/.test(page.url())) {
		await page.context().clearCookies();
	}
	await loginAs(page, role);
}

/**
 * Open a hub view and return its root locator. The profile view renders the
 * keys hub; user-edit resolves the current fixture user first.
 */
async function hubFor(page, view) {
	if (view === 'user-edit') {
		await page.goto('/wp-admin/profile.php');
		const meId = await page.evaluate(async () => {
			const response = await fetch('/wp-json/wp/v2/users/me', {
				headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
			});
			if (!response.ok) {
				throw new Error('The current fixture user is unavailable.');
			}
			const body = await response.json();
			return Number(body.id);
		});
		if (!Number.isInteger(meId) || meId < 1) {
			throw new Error('The current fixture user id is unavailable.');
		}
		await page.goto(`/wp-admin/user-edit.php?user_id=${meId}`);
		const root = page.locator('.xnetvn-agent-hub[data-view="keys"]');
		await expect(root).toBeVisible();
		return root;
	}
	if (!Object.prototype.hasOwnProperty.call(VIEWS, view)) {
		throw new Error(`Unknown hub view: ${view}. Expected one of ${[...Object.keys(VIEWS), 'user-edit'].join(', ')}.`);
	}
	await page.goto(VIEWS[view].url);
	const root = page.locator(VIEWS[view].root);
	await expect(root).toBeVisible();
	return root;
}

/**
 * Cleanup procedure for afterEach: clears credential fields and dialogs right
 * away and installs a pagehide listener for the same cleanup. It never
 * registers test hooks itself; call it from the suite's own afterEach.
 */
async function installSecretCleanup(page) {
	await page.evaluate(() => {
		const clear = () => {
			document.querySelectorAll('[data-secret], input[type="password"]').forEach(node => { node.value = ''; });
			document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
		};
		if (window.__xnetvnSecretCleanup) {
			window.removeEventListener('pagehide', window.__xnetvnSecretCleanup);
		}
		window.__xnetvnSecretCleanup = clear;
		window.addEventListener('pagehide', clear);
		clear();
	}).catch(() => {});
}

/**
 * Scan saved artifacts for leaked credential material: every regular file up to 1048576 bytes is scanned regardless of its extension, and only offending paths and the violation count are reported, never values.
 */
async function assertSafeArtifacts(directory) {
	const resolved = path.resolve(directory);
	const runnerDir = process.env.RTL_ARTIFACTS_DIR ? path.resolve(process.env.RTL_ARTIFACTS_DIR) : null;
	const localRoot = path.resolve(__dirname, '../../.local/remote-test');
	const inRunnerDir = runnerDir !== null && resolved === runnerDir;
	if (!inRunnerDir && (resolved !== localRoot && !resolved.startsWith(`${localRoot}${path.sep}`))) {
		throw new Error('Artifact checks are limited to the runner artifacts directory or .local/remote-test.');
	}
	const files = [];
	const walk = dir => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			const full = path.join(dir, entry.name);
			if (entry.isDirectory()) {
				walk(full);
			} else if (entry.isFile()) {
				files.push(full);
			}
		}
	};
	if (fs.existsSync(resolved)) {
		walk(resolved);
	}
	const offenders = new Set();
	for (const file of files) {
		const base = path.basename(file);
		if (file.endsWith('.state.json') || base === 'storageState.json') {
			offenders.add(path.relative(resolved, file));
			continue;
		}
		const stat = fs.statSync(file);
		if (stat.size > 1048576) {
			continue;
		}
		const content = fs.readFileSync(file, 'utf8');
		if (/xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}/.test(content)
			|| /wordpress_logged_in_[a-f0-9]+/i.test(content)
			|| content.includes('XNETVN_TEST_PASSWORD')) {
			offenders.add(path.relative(resolved, file));
		}
	}
	if (offenders.size > 0) {
		throw new Error(`Unsafe artifacts: ${offenders.size} file(s): ${[...offenders].join(', ')}`);
	}
}

module.exports = { LOGINS, loginAs, loginFresh, hubFor, installSecretCleanup, assertSafeArtifacts };
