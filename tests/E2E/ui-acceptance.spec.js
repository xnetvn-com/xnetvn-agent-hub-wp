const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { loginAs, loginFresh, hubFor, installSecretCleanup, assertSafeArtifacts } = require('./ui-helpers');
const {
	classifyTarget,
	blogRestPath,
	selectOwnedRow,
	selectDeletableIds,
	buildRunId,
	GROUP_SITE,
	GROUP_NET,
	MAIN_BLOG_PATH,
	CHILD_BLOG_PATH,
} = require('./ui-acceptance-support.cjs');

/**
 * UI acceptance for the remote lab (P13 of the UI/UX plan).
 *
 * Runner flow (lab shell, not the browser), in this exact order per site:
 * 1. snapshot: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/ui-fixture.php snapshot --use-include (replace wp69 with the site being run)
 * 2. pagination 21: the same command with pagination 21 instead of snapshot
 * 3. seed: the same command with seed instead of snapshot
 * 4. notice: the same command with notice instead of snapshot
 * 5. e2e: RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --compose-file compose.yaml --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-ACC-SITE'
 * 6. restore: the exec command of step 1 with restore instead of snapshot, in a finally block
 *
 * UI-ACC-SITE runs on each single-site lab URL (wp69, wp71) on all three
 * browser projects. UI-ACC-NET runs only on the multisite network namespace
 * (compose.ui-network.yaml) with its handoff URL: the same e2e command with
 * --compose-file compose.ui-network.yaml and --grep UI-ACC-NET. The network
 * target must be the canonical domain of the network, never its alias: the
 * alias host is redirected by WordPress multisite and loses the blog path.
 * The NET cases fail closed with the exact seeding command when the target is
 * not a seeded network; they never skip. Secret assertions use booleans or
 * lengths only, so failure snapshots never carry credential values.
 *
 * Target selection never widens the host allowlist: the lab host names come
 * from the same environment file the remote Playwright configuration reads,
 * and the acceptance suite declares only the group of the resolved target.
 * The group that does not apply to the resolved target is reported as NA
 * metadata, separately from the pass or fail of the group that ran.
 */

const LAB_ENV_FILE = '/work/.env';
const ARTIFACTS_DIR = process.env.RTL_ARTIFACTS_DIR;
const DASHBOARD = '/wp-admin/index.php';
const PROFILE = '/wp-admin/profile.php';
const MAIN_LABEL = 'main';
const CHILD_LABEL = 'child';
const NETWORK_RUN_ID = 'ui-net-fixture';
const SUPER_ADMIN_LOGIN = 'xnetvn_ui_super_admin';

const ADMIN_VIEWS = ['overview', 'keys', 'activity', 'tools', 'settings', 'help'];
const VIEW_URL = {
	overview: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp',
	keys: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-keys',
	activity: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-activity',
	tools: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-tools',
	settings: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-settings',
	help: '/wp-admin/admin.php?page=xnetvn-agent-hub-wp-help',
};
const VIEW_ROOT = {
	overview: '.xnetvn-agent-hub[data-view="overview"]',
	keys: '.xnetvn-agent-hub[data-view="keys"]',
	activity: '.xnetvn-agent-hub[data-view="activity"]',
	tools: '.xnetvn-agent-hub[data-view="tools"]',
	settings: 'form.xnetvn-agent-hub',
	help: '.xnetvn-agent-hub',
};
const MAIN_MARKER = 'xnetvn-ui-net-main-';
const CHILD_MARKER = 'xnetvn-ui-net-child-';
const MAIN_CANARY = 'xnetvn/ui-main-canary';
const CHILD_CANARY = 'xnetvn/ui-child-canary';

/**
 * Read the lab host names from the environment file the remote configuration
 * already trusts. Nothing is read from the request under test, so the
 * allowlist can never widen.
 */
function readLabHosts() {
	const raw = fs.readFileSync(LAB_ENV_FILE, 'utf8');
	const env = {};
	for (const line of raw.split(/\r?\n/)) {
		if (!/^[A-Z_0-9]+=/.test(line)) {
			continue;
		}
		const offset = line.indexOf('=');
		env[line.slice(0, offset)] = line.slice(offset + 1);
	}
	return {
		wp69: [env.RTL_DOMAIN_WP69, env.RTL_ALIAS_WP69].filter(Boolean),
		wp71: [env.RTL_DOMAIN_WP71, env.RTL_ALIAS_WP71].filter(Boolean),
		network: [env.RTL_DOMAIN_WPNETWORK, env.RTL_ALIAS_WPNETWORK].filter(Boolean),
	};
}

const TARGET = classifyTarget(process.env.BASE_URL, readLabHosts());
if (TARGET.group === GROUP_NET && !TARGET.canonical) {
	throw new Error('The multisite network target must use the canonical domain of the network. The alias host is redirected by WordPress multisite to the network home and loses the blog path, so it can never be the acceptance target.');
}
const SITE_LABEL = TARGET.target;
const LAB_SITE = TARGET.group === GROUP_SITE ? TARGET.target : 'wp69';
const GROUP_MANIFEST = Object.freeze({
	target: { group: TARGET.group, target: TARGET.target },
	groups: Object.freeze([
		Object.freeze({ group: GROUP_SITE, status: TARGET.group === GROUP_SITE ? 'APPLIED' : 'NA' }),
		Object.freeze({ group: GROUP_NET, status: TARGET.group === GROUP_NET ? 'APPLIED' : 'NA' }),
	]),
});

/**
 * Record the applicable and the not-applicable group as run metadata. The
 * not-applicable group is never reported as a pass.
 */
function writeGroupManifest() {
	if (!ARTIFACTS_DIR) {
		return;
	}
	fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
	fs.writeFileSync(path.join(ARTIFACTS_DIR, 'ui-acceptance-groups.json'), `${JSON.stringify(GROUP_MANIFEST, null, 2)}\n`);
}
writeGroupManifest();

function fixtureCommand(scenario) {
	return `RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-wp.sh ${LAB_SITE} eval-file /work/tests/E2E/ui-fixture.php ${scenario} --use-include`;
}

function networkFixtureCommand(scenario) {
	return `RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.ui-network.yaml toolbox -- env WORDPRESS_TABLE_PREFIX=ui_network_ XNETVN_UI_RUN_ID=${NETWORK_RUN_ID} wp --allow-root --path=/sites/wpnetwork eval-file /work/tests/E2E/ui-network-fixture.php ${scenario} --use-include`;
}

/**
 * Blog path of one acceptance label. The label is bound to the blog that the
 * request really addresses, so the ledger can never mix two sites.
 */
function blogPathFor(label) {
	if (label === CHILD_LABEL) {
		return CHILD_BLOG_PATH;
	}
	if (label === MAIN_LABEL || label === SITE_LABEL) {
		return MAIN_BLOG_PATH;
	}
	throw new Error('Unknown acceptance blog label.');
}

function restPath(label, resource) {
	return blogRestPath(blogPathFor(label), resource);
}

function adminUrl(label, view) {
	const prefix = label === CHILD_LABEL ? '/ui-second' : '';
	return `${prefix}${VIEW_URL[view]}`;
}

/**
 * Log in as the owned network super administrator. The password never leaves
 * the password field and the failure message names the seeding command
 * instead of any credential value.
 */
async function loginSuperAdmin(page) {
	const password = process.env.XNETVN_TEST_PASSWORD;
	if (!password) {
		throw new Error('The test-only login password is unavailable.');
	}
	if (/wp-admin/.test(page.url())) {
		await page.context().clearCookies();
	}
	await page.goto('/wp-login.php');
	await page.locator('#user_login').fill(SUPER_ADMIN_LOGIN);
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/wp-admin/, { timeout: 15000 }).catch(() => {});
	if (!/wp-admin/.test(page.url())) {
		throw new Error(`The owned network super administrator is unavailable. Seed the owned multisite lab first: ${networkFixtureCommand('seed')}`);
	}
}

/**
 * Read the id of the signed-in fixture actor. Only the numeric id travels
 * into the ledger; no credential value is read.
 */
async function currentUserId(page) {
	const id = await page.evaluate(async () => {
		const response = await fetch('/wp-json/wp/v2/users/me', {
			headers: { 'X-WP-Nonce': window.xnetvnAgentHubConfig.nonce },
		});
		if (!response.ok) {
			return -1;
		}
		const body = await response.json();
		return Number(body.id);
	});
	if (!Number.isInteger(id) || id < 1) {
		throw new Error('The current fixture user id is unavailable.');
	}
	return id;
}

/**
 * List the keys of one blog from that blog's own page, so the REST nonce
 * belongs to the blog the request addresses. The manager scope is used on
 * purpose: only the complete table proves that one blog owns no row of the
 * other blog.
 */
async function listKeys(page, label) {
	await page.goto(adminUrl(label, 'keys'));
	await expect(page.locator(VIEW_ROOT.keys), `hub root on ${label}`).toBeVisible();
	const listing = await page.evaluate(async ({ path }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}?all=true&per_page=100`, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return { status: response.status, total: 0, entries: [] };
		}
		const list = await response.json();
		const total = Number(response.headers.get('X-WP-Total'));
		return {
			status: response.status,
			total: Number.isFinite(total) ? total : 0,
			entries: Array.isArray(list)
				? list.map(entry => ({
					id: Number(entry.id),
					name: String(entry.name),
					user_id: Number(entry.user_id),
					state: String(entry.status),
				}))
				: [],
		};
	}, { path: restPath(label, 'keys') });
	if (listing.status !== 200) {
		return listing;
	}
	if (listing.total > listing.entries.length) {
		throw new Error(`The key listing of ${label} is truncated (${listing.entries.length} of ${listing.total} rows). The acceptance run cannot prove the site isolation with a partial listing.`);
	}
	return listing;
}

/**
 * Read one key through the REST route of one blog.
 */
async function keyState(page, label, id) {
	await page.goto(adminUrl(label, 'keys'));
	await expect(page.locator(VIEW_ROOT.keys), `hub root on ${label}`).toBeVisible();
	return page.evaluate(async ({ path, keyId }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}/${keyId}`, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return { status: response.status, state: null, name: null };
		}
		const body = await response.json();
		return { status: response.status, state: String(body.status), name: String(body.name) };
	}, { path: restPath(label, 'keys'), keyId: id });
}

/**
 * Read the site settings of one blog from that blog's own page.
 */
async function readSettings(page, label) {
	await page.goto(adminUrl(label, 'settings'));
	await expect(page.locator(VIEW_ROOT.settings), `settings form on ${label}`).toBeVisible();
	return page.evaluate(async ({ path }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(path, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return { status: response.status, body: null };
		}
		return { status: response.status, body: await response.json() };
	}, { path: restPath(label, 'settings') });
}

/**
 * Read the audit metadata of one blog, filtered by one owned ability. An
 * empty listing is never a pass.
 */
async function readAudit(page, label, ability) {
	await page.goto(adminUrl(label, 'activity'));
	await expect(page.locator(VIEW_ROOT.activity), `activity view on ${label}`).toBeVisible();
	return page.evaluate(async ({ path, name }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}?all=true&per_page=100&ability=${encodeURIComponent(name)}`, {
			headers: { 'X-WP-Nonce': nonce },
		});
		if (!response.ok) {
			return { status: response.status, total: 0, items: [] };
		}
		const body = await response.json();
		return {
			status: response.status,
			total: Number(body.total),
			items: Array.isArray(body.items) ? body.items : [],
		};
	}, { path: restPath(label, 'audit'), name: ability });
}

/**
 * Reconcile the key this run created. Only one exact name on the exact site
 * and owner may enter the ledger; zero, several or unknown rows keep the
 * state unknown and stop the cleanup of that part instead of creating the key
 * again. The decision itself lives in the pure support module, so the
 * contract checker can prove it against a mutated module.
 */
async function reconcileOwnedKey(page, ledger) {
	const matches = await page.evaluate(async ({ path, wanted }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}?per_page=100`, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return [];
		}
		const list = await response.json();
		return Array.isArray(list)
			? list
				.filter(entry => entry && String(entry.name) === wanted)
				.map(entry => ({ id: Number(entry.id), name: String(entry.name), user_id: Number(entry.user_id) }))
			: [];
	}, { path: restPath(ledger.site, 'keys'), wanted: ledger.name });
	let owned;
	try {
		owned = selectOwnedRow(matches.map(match => ({ ...match, site: ledger.site })), ledger);
	} catch (error) {
		throw new Error(`Owned key reconciliation failed for the row named "${ledger.name}" of run ${ledger.run_id}: ${error.message} The state stays unknown: do not create the key again and do not delete any other row.`);
	}
	ledger.id = owned.id;
	return owned;
}

/**
 * Revoke the owned key of this run and nothing else. A row that no longer
 * matches the ledger stays untouched.
 */
async function cleanupOwnedKey(page, ledger) {
	if (!Number.isInteger(ledger.id) || ledger.id < 1) {
		return;
	}
	await loginFresh(page, 'administrator');
	await page.goto(VIEW_URL.keys);
	await expect(page.locator(VIEW_ROOT.keys)).toBeVisible();
	const records = await page.evaluate(async ({ path }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}?all=true&per_page=100`, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return [];
		}
		const list = await response.json();
		return Array.isArray(list)
			? list.map(entry => ({ id: Number(entry.id), name: String(entry.name), user_id: Number(entry.user_id) }))
			: [];
	}, { path: restPath(ledger.site, 'keys') });
	let deletable;
	try {
		deletable = selectDeletableIds(records.map(record => ({ ...record, site: ledger.site })), ledger);
	} catch (error) {
		throw new Error(`The cleanup of the key "${ledger.name}" of run ${ledger.run_id} stopped: ${error.message} Every other row stays untouched.`);
	}
	for (const keyId of deletable) {
		const status = await page.evaluate(async ({ path, id }) => {
			const nonce = window.xnetvnAgentHubConfig.nonce;
			const response = await fetch(`${path}/${id}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
			return response.status;
		}, { path: restPath(ledger.site, 'keys'), id: keyId });
		if (status !== 200) {
			throw new Error(`The owned key ${keyId} of run ${ledger.run_id} could not be revoked (status ${status}).`);
		}
	}
}

/**
 * Find the id of one key of the current owner by its exact name.
 *
 * @param {object} page Browser page.
 * @param {string} name Exact key name.
 * @return {number} The key id, or -1 when the owner has no such row.
 */
async function findKeyIdByName(page, name) {
	const matches = await page.evaluate(async ({ path, wanted }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}?per_page=100`, { headers: { 'X-WP-Nonce': nonce } });
		if (!response.ok) {
			return [];
		}
		const list = await response.json();
		return Array.isArray(list)
			? list.filter(entry => entry && String(entry.name) === wanted).map(entry => Number(entry.id))
			: [];
	}, { path: restPath(SITE_LABEL, 'keys'), wanted: name });
	return matches.length === 1 ? matches[0] : -1;
}

/**
 * Revoke one row the acceptance case created itself.
 *
 * @param {object} page Browser page.
 * @param {number} id Key id.
 */
async function revokeKey(page, id) {
	const status = await page.evaluate(async ({ path, keyId }) => {
		const nonce = window.xnetvnAgentHubConfig.nonce;
		const response = await fetch(`${path}/${keyId}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
		return response.status;
	}, { path: restPath(SITE_LABEL, 'keys'), keyId: id });
	if (status !== 200) {
		throw new Error(`The sentinel key ${id} could not be revoked (status ${status}).`);
	}
}

test.afterEach(async ({ page }) => {
	await installSecretCleanup(page);
});

if (TARGET.group === GROUP_SITE) {
	test('UI-ACC-SITE-01 admin views, profile and environment notice render once', { tag: '@ui-acc-site' }, async ({ page }) => {
		await loginAs(page, 'administrator');
		for (const view of ADMIN_VIEWS) {
			await page.goto(VIEW_URL[view]);
			const root = page.locator(VIEW_ROOT[view]);
			await expect(root, `hub root on ${view}`).toBeVisible();
			await expect(page.locator('#wpbody-content .wrap > h1').first(), `heading on ${view}`).toBeVisible();
		}
		await page.goto(PROFILE);
		await expect(page.locator('.xnetvn-agent-hub[data-view="keys"]'), 'keys hub on profile').toBeVisible();
		await expect(page.locator('nav.xnetvn-nav'), 'no management nav on profile').toHaveCount(0);
		await page.goto(DASHBOARD);
		const notice = page.locator('.notice.notice-error', { hasText: '99.0-fixture' });
		// Runner flow is snapshot, pagination 21, seed, notice, e2e, restore: the
		// `notice` scenario registers the MU notice, so fail fast when missing.
		if (await notice.count() === 0) {
			throw new Error(`UI fixture notice missing (no .notice.notice-error with 99.0-fixture on ${DASHBOARD}). Run the fixture scenarios first: ${fixtureCommand('snapshot')} then the same command with notice`);
		}
		await expect(notice.first()).toBeVisible();
		await expect(notice.first()).toContainText('Requirement not met');
		await expect(notice.first()).toContainText('Next step');
		await page.goto(VIEW_URL.keys);
		const keyRows = page.locator('.xnetvn-agent-hub[data-view="keys"] [data-keys] tr');
		// Auto-wait for the seeded listing; only fail closed when rows never arrive.
		await keyRows.first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => {});
		const rows = await keyRows.count();
		if (rows === 0) {
			throw new Error(`UI fixture dataset missing (expected seeded key rows, found 0). Run the fixture scenarios first: ${fixtureCommand('snapshot')} then the same command with pagination 21`);
		}
		expect(rows > 0).toBe(true);
	});

	test('UI-ACC-SITE-02 cross-owner keys stay denied while managers revoke scoped rows', { tag: '@ui-acc-site' }, async ({ page }, testInfo) => {
		const runId = buildRunId({
			project: testInfo.project.name,
			testId: testInfo.testId,
			workerIndex: testInfo.workerIndex,
			repeatEachIndex: testInfo.repeatEachIndex,
			startedAt: Date.now(),
		});
		const name = `UI-ACC cross ${runId}`;
		const sentinelName = `${name} foreign`;
		const ledger = { run_id: runId, site: SITE_LABEL, user_id: 0, name, id: 0 };
		const sentinel = { id: 0, name: sentinelName, state: '' };
		await loginAs(page, 'editor');
		await page.goto(PROFILE);
		const hub = page.locator('.xnetvn-agent-hub[data-view="keys"]');
		await expect(hub).toBeVisible();
		ledger.user_id = await currentUserId(page);
		let failure = null;
		try {
			await hub.locator('[data-field="name"]').fill(name);
			await hub.locator('[data-create]').click();
			const dialog = hub.locator('[data-secret-dialog]');
			await expect(dialog).toBeVisible();
			// Boolean only: the shown-once secret is present without reading its value.
			expect(await dialog.locator('[data-secret]').evaluate(node => node.value.length > 0)).toBe(true);
			await hub.locator('[data-saved]').click();
			await expect(dialog).not.toBeVisible();
			await reconcileOwnedKey(page, ledger);

			await loginAs(page, 'editor_b');
			await page.goto(PROFILE);
			const other = page.locator('.xnetvn-agent-hub[data-view="keys"]');
			await expect(other).toBeVisible();
			await expect(other.locator('[data-keys]')).not.toContainText(name);
			const denied = await page.evaluate(async ({ path, keyId }) => {
				const nonce = window.xnetvnAgentHubConfig.nonce;
				const got = await fetch(`${path}/${keyId}`, { headers: { 'X-WP-Nonce': nonce } });
				const rotated = await fetch(`${path}/${keyId}/rotate`, {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
					body: '{}',
				});
				const removed = await fetch(`${path}/${keyId}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
				return { get: got.status, rotate: rotated.status, remove: removed.status };
			}, { path: restPath(SITE_LABEL, 'keys'), keyId: ledger.id });
			expect(denied, 'a cross-owner key must stay denied for the other actor').toEqual({ get: 404, rotate: 404, remove: 404 });

			// A second actor creates a row whose name shares the prefix of the
			// run. The cleanup of the run must never touch that foreign row.
			await other.locator('[data-field="name"]').fill(sentinelName);
			await other.locator('[data-create]').click();
			const sentinelDialog = other.locator('[data-secret-dialog]');
			await expect(sentinelDialog).toBeVisible();
			await other.locator('[data-saved]').click();
			await expect(sentinelDialog).not.toBeVisible();
			sentinel.id = await findKeyIdByName(page, sentinelName);
			expect(sentinel.id, 'the foreign sentinel row must exist').toBeGreaterThan(0);

			await loginAs(page, 'administrator');
			await page.goto(VIEW_URL.keys);
			await expect(page.locator(VIEW_ROOT.keys)).toBeVisible();
			const revoked = await page.evaluate(async ({ path, keyId }) => {
				const nonce = window.xnetvnAgentHubConfig.nonce;
				const response = await fetch(`${path}/${keyId}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
				return response.status;
			}, { path: restPath(SITE_LABEL, 'keys'), keyId: ledger.id });
			expect(revoked).toBe(200);
			const after = await keyState(page, SITE_LABEL, ledger.id);
			expect(after.status, 'the revoked key must still answer on the site').toBe(200);
			expect(after.state, 'the revoked key must report the revoked state').toBe('revoked');
		} catch (error) {
			failure = error;
			throw error;
		} finally {
			try {
				await cleanupOwnedKey(page, ledger);
				if (sentinel.id > 0) {
					const state = await keyState(page, SITE_LABEL, sentinel.id);
					sentinel.state = state.state;
					await revokeKey(page, sentinel.id);
				}
			} catch (cleanupError) {
				throw new Error(`${failure ? failure.message : ''} | The owned key cleanup failed: ${cleanupError.message}`);
			}
		}
		expect(sentinel.state, 'a foreign row that shares the name prefix must survive the cleanup of the run').toBe('active');
	});

	test('UI-ACC-SITE-02 actors without management see no hub', { tag: '@ui-acc-site' }, async ({ page }) => {
		await loginAs(page, 'subscriber');
		await page.goto(VIEW_URL.keys);
		await expect(page.locator('.xnetvn-agent-hub'), 'no hub markup for subscriber').toHaveCount(0);
		await page.goto(PROFILE);
		await expect(page.locator('.xnetvn-agent-hub'), 'no hub markup on subscriber profile').toHaveCount(0);
	});

	test('UI-ACC-SITE-03 viewport, reflow, RTL and focus hold', { tag: '@ui-acc-site' }, async ({ page }) => {
		await loginAs(page, 'administrator');
		for (const size of [{ width: 1440, height: 900 }, { width: 768, height: 900 }, { width: 390, height: 844 }]) {
			await page.setViewportSize(size);
			for (const view of ['overview', 'keys']) {
				await page.goto(VIEW_URL[view]);
				const root = page.locator(VIEW_ROOT[view]);
				await expect(root, `hub root on ${view} at ${size.width}px`).toBeVisible();
				const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
				expect(overflow, `body overflow on ${view} at ${size.width}px`).toBeLessThanOrEqual(1);
			}
		}
		await page.setViewportSize({ width: 320, height: 800 });
		await page.goto(VIEW_URL.overview);
		await expect(page.locator(VIEW_ROOT.overview)).toBeVisible();
		const overflow320 = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow320, 'body overflow at 320px').toBeLessThanOrEqual(1);
		await page.goto(VIEW_URL.keys);
		const keys = page.locator(VIEW_ROOT.keys);
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
		await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
		const overflowRtl = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflowRtl, 'body overflow in RTL').toBeLessThanOrEqual(1);
		await page.evaluate(() => document.documentElement.setAttribute('dir', 'ltr'));
		for (const view of ['overview', 'keys']) {
			await page.goto(VIEW_URL[view]);
			const results = await new AxeBuilder({ page }).include('.xnetvn-agent-hub').withTags(['wcag2a', 'wcag2aa']).analyze();
			expect(results.violations.map(violation => violation.id), `axe violations on ${view}`).toEqual([]);
		}
		await page.setViewportSize({ width: 1440, height: 900 });
	});

	test('UI-ACC-SITE-04 secrets show once and artifacts stay clean', { tag: '@ui-acc-site' }, async ({ page }, testInfo) => {
		const runId = buildRunId({
			project: testInfo.project.name,
			testId: testInfo.testId,
			workerIndex: testInfo.workerIndex,
			repeatEachIndex: testInfo.repeatEachIndex,
			startedAt: Date.now(),
		});
		const name = `UI-ACC once ${runId}`;
		const ledger = { run_id: runId, site: SITE_LABEL, user_id: 0, name, id: 0 };
		await loginAs(page, 'administrator');
		const hub = await hubFor(page, 'keys');
		ledger.user_id = await currentUserId(page);
		let failure = null;
		try {
			await hub.locator('[data-field="name"]').fill(name);
			await hub.locator('[data-create]').click();
			const dialog = hub.locator('[data-secret-dialog]');
			await expect(dialog).toBeVisible();
			// Booleans and lengths only: never read or assert the secret value itself.
			expect(await dialog.locator('[data-secret]').evaluate(node => node.value.length > 0)).toBe(true);
			await hub.locator('[data-saved]').click();
			await expect(dialog).not.toBeVisible();
			expect(await dialog.locator('[data-secret]').evaluate(node => node.value.length)).toBe(0);
			await reconcileOwnedKey(page, ledger);
			const revoked = await page.evaluate(async ({ path, keyId }) => {
				const nonce = window.xnetvnAgentHubConfig.nonce;
				const response = await fetch(`${path}/${keyId}`, { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } });
				return response.status;
			}, { path: restPath(SITE_LABEL, 'keys'), keyId: ledger.id });
			expect(revoked).toBe(200);
			const after = await keyState(page, SITE_LABEL, ledger.id);
			expect(after.state, 'the revoked key must report the revoked state').toBe('revoked');
		} catch (error) {
			failure = error;
			throw error;
		} finally {
			try {
				await cleanupOwnedKey(page, ledger);
			} catch (cleanupError) {
				throw new Error(`${failure ? failure.message : ''} | The owned key cleanup failed: ${cleanupError.message}`);
			}
		}
		if (ARTIFACTS_DIR) {
			await assertSafeArtifacts(ARTIFACTS_DIR);
		}
	});
}

if (TARGET.group === GROUP_NET) {
	test('UI-ACC-NET-01 multisite main and child hubs stay separated', { tag: '@ui-acc-net' }, async ({ page }) => {
		await loginAs(page, 'administrator');
		await page.goto(VIEW_URL.overview);
		await expect(page.locator(VIEW_ROOT.overview), 'hub root on the network main site').toBeVisible();
		await page.goto(adminUrl(CHILD_LABEL, 'overview'));
		await expect(page.locator(VIEW_ROOT.overview), 'hub root on the network child site').toBeVisible();

		const main = await listKeys(page, MAIN_LABEL);
		if (main.status !== 200) {
			throw new Error(`The network main site key listing failed (status ${main.status}). Seed the owned multisite lab first: ${networkFixtureCommand('seed')}`);
		}
		const child = await listKeys(page, CHILD_LABEL);
		if (child.status !== 200) {
			throw new Error(`The network child site key listing failed (status ${child.status}). Seed the owned multisite lab first: ${networkFixtureCommand('seed')}`);
		}
		const childIds = new Set(child.entries.map(entry => entry.id));
		const mainRows = main.entries.filter(entry => entry.name.startsWith(MAIN_MARKER));
		const childRows = child.entries.filter(entry => entry.name.startsWith(CHILD_MARKER));
		if (mainRows.length === 0) {
			throw new Error(`UI network seed missing (no ${MAIN_MARKER} row on the main site). Run the network fixture first: ${networkFixtureCommand('seed')}`);
		}
		if (childRows.length === 0) {
			throw new Error(`UI network seed missing (no ${CHILD_MARKER} row on the child site). Run the network fixture first: ${networkFixtureCommand('seed')}`);
		}
		expect(child.entries.some(entry => entry.name.startsWith(MAIN_MARKER)), 'main-site rows must not leak into the child site').toBe(false);
		expect(main.entries.some(entry => entry.name.startsWith(CHILD_MARKER)), 'child-site rows must not leak into the main site').toBe(false);

		// The cross-site probe needs a main row whose id the child site does not
		// own: the ids of two blogs are independent, so the ledger decides.
		const probe = mainRows.find(entry => !childIds.has(entry.id));
		if (!probe) {
			throw new Error(`UI network seed missing (no ${MAIN_MARKER} row with an id the child site does not own). Run the network fixture first: ${networkFixtureCommand('seed')}`);
		}
		const mainBefore = await keyState(page, MAIN_LABEL, probe.id);
		expect(mainBefore.status, 'the main-site key must be readable on the main site').toBe(200);
		const childDenial = await keyState(page, CHILD_LABEL, probe.id);
		expect(childDenial.status, 'the main-site key id must stay denied on the child site').toBe(404);
		const mainAfter = await keyState(page, MAIN_LABEL, probe.id);
		expect(mainAfter.status, 'the main-site key must stay readable after the child denial').toBe(200);

		const childKey = await keyState(page, CHILD_LABEL, childRows[0].id);
		expect(childKey.status, 'the child-site key must be readable on the child site').toBe(200);
		expect(childKey.name.startsWith(CHILD_MARKER), 'the child-site key must carry the child marker').toBe(true);
		expect(childKey.name.startsWith(MAIN_MARKER), 'the child-site key must not carry the main marker').toBe(false);
	});

	test('UI-ACC-NET-02 network roles stay scoped and artifacts stay clean', { tag: '@ui-acc-net' }, async ({ page }) => {
		await loginSuperAdmin(page);
		await page.goto(VIEW_URL.overview);
		await expect(page.locator(VIEW_ROOT.overview), 'hub root for the network super administrator on the main site').toBeVisible();
		await page.goto(adminUrl(CHILD_LABEL, 'overview'));
		await expect(page.locator(VIEW_ROOT.overview), 'hub root for the network super administrator on the child site').toBeVisible();

		const mainSettings = await readSettings(page, MAIN_LABEL);
		expect(mainSettings.status, 'the main site settings must be readable').toBe(200);
		expect(mainSettings.body.audit_retention_days, 'the main site keeps its own retention').toBe(89);
		const childSettings = await readSettings(page, CHILD_LABEL);
		expect(childSettings.status, 'the child site settings must be readable').toBe(200);
		expect(childSettings.body.audit_retention_days, 'the child site keeps its own retention').toBe(88);

		const mainCanary = await readAudit(page, MAIN_LABEL, MAIN_CANARY);
		expect(mainCanary.status, 'the main site audit listing must be readable').toBe(200);
		expect(mainCanary.items.length, 'the main site must list its own audit canary').toBeGreaterThan(0);
		const childCanaryOnMain = await readAudit(page, MAIN_LABEL, CHILD_CANARY);
		expect(childCanaryOnMain.total, 'the child audit canary must not appear on the main site').toBe(0);
		const childCanary = await readAudit(page, CHILD_LABEL, CHILD_CANARY);
		expect(childCanary.status, 'the child site audit listing must be readable').toBe(200);
		expect(childCanary.items.length, 'the child site must list its own audit canary').toBeGreaterThan(0);
		const mainCanaryOnChild = await readAudit(page, CHILD_LABEL, MAIN_CANARY);
		expect(mainCanaryOnChild.total, 'the main audit canary must not appear on the child site').toBe(0);

		const mainAll = await listKeys(page, MAIN_LABEL);
		const childAll = await listKeys(page, CHILD_LABEL);
		expect(mainAll.entries.some(entry => entry.name.startsWith(CHILD_MARKER)), 'child-site rows must not leak into the main listing').toBe(false);
		expect(childAll.entries.some(entry => entry.name.startsWith(MAIN_MARKER)), 'main-site rows must not leak into the child listing').toBe(false);

		await loginFresh(page, 'subscriber');
		await page.goto(VIEW_URL.keys);
		await expect(page.locator('.xnetvn-agent-hub'), 'no hub markup for subscriber on the network main site').toHaveCount(0);
		await page.goto(adminUrl(CHILD_LABEL, 'keys'));
		await expect(page.locator('.xnetvn-agent-hub'), 'no hub markup for subscriber on the network child site').toHaveCount(0);
		if (ARTIFACTS_DIR) {
			await assertSafeArtifacts(ARTIFACTS_DIR);
		}
	});
}

module.exports = { GROUP_MANIFEST, TARGET };
