// Source-only contract for the UI acceptance harness.
//
// The checker reads files, evaluates the pure support module and loads the
// acceptance spec in a sandbox. It never renders Compose, never starts a
// container, never opens a socket and never runs a subprocess: a green
// result proves the harness contract, not a lab run.
//
// The checker also runs the acceptance cases of the spec against an in-memory
// model of the owned management API. The cases are executed, not only counted,
// so a spec that stops requiring one unique owned row, that stops checking
// the owner, that deletes another row or that stops reading the key after the
// revocation is caught instead of passing unnoticed.
//
// A missing ignored asset is reported with its path and exits non-zero. The
// checker never recreates an asset and never reports a missing input as a
// pass.
//
// @package XNetVN\AgentHubWP

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '../..');
const SPEC_REL = 'tests/E2E/ui-acceptance.spec.js';
const SUPPORT_REL = 'tests/E2E/ui-acceptance-support.cjs';
const FIXTURE_REL = 'tests/E2E/ui-network-fixture.php';
const START_REL = 'infra/remote/ui-network-start.sh';
const INSTRUCTIONS_REL = '.agents/instructions/mcp-test-debug.instructions.md';
const RUNBOOK_REL = '.agents/skills/xnetvn-mcp-testing/references/runbook.md';
const SCENARIOS_REL = '.agents/evals/test-debug-scenarios.json';
const LEGACY_SHORT = 'xnetvn-ui-network';
const LEGACY_MARK = /^(?:[-*]\s*)?Legacy note:/i;
const NOT_FOR_USE = /(do not use|must not be used|never use|not to be used|do not adopt|deprecated)/i;
/** The case identifiers the suite must declare, in order. */
const SITE_CASE_IDS = [ 'UI-ACC-SITE-01', 'UI-ACC-SITE-02', 'UI-ACC-SITE-02', 'UI-ACC-SITE-03', 'UI-ACC-SITE-04' ];
const NET_CASE_IDS = [ 'UI-ACC-NET-01', 'UI-ACC-NET-02' ];
/** The case that owns the key of the run. */
const CROSS_OWNER_CASE = 'UI-ACC-SITE-02 cross-owner';
/** The assertion that proves a cross-owner key stays denied. */
const DENIED_ASSERTION = 'a cross-owner key must stay denied for the other actor';
/** The assertion that proves a foreign row with the same prefix survives. */
const SENTINEL_ASSERTION = 'a foreign row that shares the name prefix must survive the cleanup of the run';
/** The case that shows the secret once. */
const SECRET_CASE = 'UI-ACC-SITE-04';

/**
 * Read a required input. A missing file is an error that names its path.
 *
 * @param {string} rel Relative path from the project root.
 * @return {string} File content.
 */
function read(rel) {
	const file = path.join(ROOT, rel);
	if (!fs.existsSync(file)) {
		throw new Error(`Missing required input: ${rel}`);
	}
	return fs.readFileSync(file, 'utf8');
}

/**
 * Synthetic lab environment. The checker never reads a real environment file.
 *
 * @return {string} Environment file content.
 */
function syntheticEnv() {
	return [
		'RTL_DOMAIN_WP69=wp69.canonical.test',
		'RTL_ALIAS_WP69=wp69.alias.test',
		'RTL_DOMAIN_WP71=wp71.canonical.test',
		'RTL_ALIAS_WP71=wp71.alias.test',
		'RTL_DOMAIN_WPNETWORK=network.canonical.test',
		'RTL_ALIAS_WPNETWORK=network.alias.test',
		'',
	].join('\n');
}

/**
 * Assert that a call fails with one exact message.
 *
 * @param {Function} fn Call under test.
 * @param {string} message Expected message.
 */
function throwsMessage(fn, message) {
	assert.throws(fn, error => {
		assert.equal(error && error.message, message);
		return true;
	});
}

/**
 * Normalize the group manifest of a sandboxed spec.
 *
 * @param {object} manifest Group manifest.
 * @return {Array<{group:string,status:string}>} Plain group list.
 */
function plainGroups(manifest) {
	return Array.from(manifest.groups, entry => ({ group: entry.group, status: entry.status }));
}

/**
 * Support module contract: pure decisions, no environment value in a failure.
 *
 * @param {object} support Support module under test.
 */
function supportContract(support) {
	const hosts = {
		wp69: [ 'wp69.canonical.test', 'wp69.alias.test' ],
		wp71: [ 'wp71.canonical.test', 'wp71.alias.test' ],
		network: [ 'network.canonical.test', 'network.alias.test' ],
	};

	assert.deepEqual(
		support.classifyTarget('https://wp69.canonical.test', hosts),
		{ group: 'SITE', target: 'wp69', canonical: true },
		'A canonical single-site host must resolve to its own site group',
	);
	assert.deepEqual(
		support.classifyTarget('https://wp71.alias.test', hosts),
		{ group: 'SITE', target: 'wp71', canonical: false },
		'An alias single-site host must resolve to the same site group',
	);
	assert.deepEqual(
		support.classifyTarget('https://network.canonical.test', hosts),
		{ group: 'NET', target: 'network', canonical: true },
		'The canonical network host must resolve to the network group',
	);
	assert.deepEqual(
		support.classifyTarget('https://network.alias.test', hosts),
		{ group: 'NET', target: 'network', canonical: false },
		'The network alias must be recognised but marked as not canonical',
	);

	// A run only needs the group of its own target.
	assert.deepEqual(
		support.classifyTarget('https://wp69.canonical.test', { wp69: [ 'wp69.canonical.test' ] }),
		{ group: 'SITE', target: 'wp69', canonical: true },
		'A target whose group is configured must resolve even when another group is empty',
	);
	throwsMessage(
		() => support.classifyTarget('https://wp69.canonical.test', { wp71: [ 'wp71.canonical.test' ] }),
		support.ERRORS.target,
		'A target whose own group is empty must fail closed',
	);

	// Foreign, ambiguous, missing and insecure targets fail closed.
	throwsMessage(() => support.classifyTarget('https://foreign.example.test', hosts), support.ERRORS.target);
	throwsMessage(() => support.classifyTarget('http://wp69.canonical.test', hosts), support.ERRORS.target);
	throwsMessage(() => support.classifyTarget('https://user:redacted@wp69.canonical.test', hosts), support.ERRORS.target);
	throwsMessage(() => support.classifyTarget('', hosts), support.ERRORS.target);
	throwsMessage(() => support.classifyTarget('https://wp69.canonical.test', {}), support.ERRORS.target);
	throwsMessage(() => support.classifyTarget('https://wp69.canonical.test', { wp69: 'wp69.canonical.test' }), support.ERRORS.hosts);
	throwsMessage(
		() => support.classifyTarget('https://shared.test', {
			wp69: [ 'shared.test' ],
			wp71: [ 'shared.test' ],
			network: [ 'network.canonical.test' ],
		}),
		support.ERRORS.target,
		'A host in two groups is ambiguous and must fail closed',
	);

	// A failure must never repeat an environment value.
	for (const candidate of [ 'https://foreign.example.test', 'http://wp69.canonical.test', '' ]) {
		let message = '';
		try {
			support.classifyTarget(candidate, hosts);
		} catch (error) {
			message = String(error.message);
		}
		for (const value of Object.values(hosts).flat()) {
			assert.ok(!message.includes(value), 'A failure message must not repeat a configured host');
		}
	}

	// REST paths address the blog that the request really belongs to.
	assert.equal(support.blogRestPath('/', 'keys/123'), '/wp-json/xnetvn-agent-hub-wp/v1/keys/123');
	assert.equal(support.blogRestPath('/ui-second/', 'keys/123'), '/ui-second/wp-json/xnetvn-agent-hub-wp/v1/keys/123');
	assert.equal(support.blogRestPath('/', 'audit'), '/wp-json/xnetvn-agent-hub-wp/v1/audit');
	throwsMessage(() => support.blogRestPath('/ui-third/', 'keys/1'), support.ERRORS.blog);
	throwsMessage(() => support.blogRestPath('', 'keys/1'), support.ERRORS.blog);
	throwsMessage(() => support.blogRestPath('/', '../../wp-config.php'), support.ERRORS.resource);
	throwsMessage(() => support.blogRestPath('/', 'keys/../../secret'), support.ERRORS.resource);
	throwsMessage(() => support.blogRestPath('/', 'https://evil.test/keys'), support.ERRORS.resource);
	throwsMessage(() => support.blogRestPath('/', '/keys'), support.ERRORS.resource);
	throwsMessage(() => support.blogRestPath('/', 'keys//1'), support.ERRORS.resource);
	throwsMessage(() => support.blogRestPath('/', ''), support.ERRORS.resource);

	// Only the exact owned row belongs to the run.
	const ledger = { run_id: 'chromium-1a2b3c4d', site: 'main', user_id: 7, name: 'UI-ACC cross chromium-1a2b3c4d', id: 42 };
	const owned = { id: 42, name: ledger.name, user_id: 7, site: 'main' };
	assert.equal(support.isOwnedKey(owned, ledger), true, 'The owned row must be recognised');
	assert.equal(
		support.isOwnedKey({ ...owned, name: 'UI-ACC cross chromium-foreign' }, ledger),
		false,
		'A row that only shares the name prefix is not owned',
	);
	assert.equal(support.isOwnedKey({ ...owned, user_id: 8 }, ledger), false, 'A row of another owner is not owned');
	assert.equal(support.isOwnedKey({ ...owned, site: 'child' }, ledger), false, 'A row of another blog is not owned');
	assert.equal(support.isOwnedKey({ ...owned, id: 43 }, ledger), false, 'A row with another id is not owned');
	assert.equal(support.isOwnedKey({ ...owned, site: 'main' }, { ...ledger, run_id: 'not a run id' }), false);
	assert.equal(support.isOwnedKey(null, ledger), false);
	assert.equal(support.isOwnedKey(owned, null), false);

	// The unique owned row is the only row the ledger may learn.
	const selected = support.selectOwnedRow([ owned ], ledger);
	assert.equal(selected.id, 42, 'The unique owned row must enter the ledger');
	throwsMessage(() => support.selectOwnedRow([], ledger), support.ERRORS.ambiguous);
	throwsMessage(
		() => support.selectOwnedRow([ owned, { ...owned, id: 43 } ], ledger),
		support.ERRORS.ambiguous,
		'Two rows of the same name must keep the state unknown',
	);
	throwsMessage(
		() => support.selectOwnedRow([ { ...owned, user_id: 8 } ], ledger),
		support.ERRORS.ownership,
		'A row of another owner must keep the state unknown',
	);
	throwsMessage(
		() => support.selectOwnedRow([ { ...owned, name: 'UI-ACC cross chromium-foreign' } ], ledger),
		support.ERRORS.ownership,
		'A row of another name must keep the state unknown',
	);

	// Only the owned row may be revoked.
	assert.deepEqual(support.selectDeletableIds([ owned ], ledger), [ 42 ], 'The owned row must be revocable');
	assert.deepEqual(
		support.selectDeletableIds([ { id: 7, name: 'UI-ACC cross chromium-foreign', user_id: 99, site: 'main' } ], ledger),
		[],
		'A row of another owner must never be revoked',
	);
	throwsMessage(
		() => support.selectDeletableIds([ owned, { ...owned, id: 43 } ], ledger),
		support.ERRORS.ambiguous,
		'Two rows of the same name must never be revoked',
	);
	throwsMessage(
		() => support.selectDeletableIds([ { ...owned, user_id: 8 } ], ledger),
		support.ERRORS.ownership,
		'A row of another owner that carries the name must never be revoked',
	);

	// The run identifier stays inside the bound the matrix requires.
	assert.equal(support.RUN_ID_PATTERN.test('a'.repeat(26)), true, 'A 26 character run identifier must be accepted');
	assert.equal(support.RUN_ID_PATTERN.test('a'.repeat(27)), false, 'A 27 character run identifier must be refused');
	assert.equal(support.RUN_ID_PATTERN.test('A'.repeat(10)), false, 'An upper case run identifier must be refused');
	const runId = support.buildRunId({ project: 'chromium', testId: 't1', workerIndex: 0, repeatEachIndex: 0, startedAt: 1 });
	assert.match(runId, support.RUN_ID_PATTERN);
	assert.ok(runId.length <= 26, 'The run identifier must stay at 26 characters or fewer');
	assert.notEqual(
		runId,
		support.buildRunId({ project: 'chromium', testId: 't2', workerIndex: 0, repeatEachIndex: 0, startedAt: 1 }),
		'Two test instances must not share a run identifier',
	);
	assert.notEqual(
		support.buildRunId({ project: 'firefox', testId: 't1', workerIndex: 0, repeatEachIndex: 0, startedAt: 1 }),
		runId,
		'Two browser engines must not share a run identifier',
	);
	throwsMessage(() => support.buildRunId({ project: '', testId: 't1' }), support.ERRORS.runId);
}

/**
 * Load the acceptance spec in a sandbox and collect the declared cases.
 *
 * @param {string} source Spec source.
 * @param {string} baseUrl Base URL of the run under test.
 * @param {object} options Support module and matcher overrides.
 * @return {{declared:Array<{title:string,tag:string,body:Function}>,exports:object}} Loaded spec.
 */
function loadSpec(source, baseUrl, options = {}) {
	const declared = [];
	const stubTest = (title, details, body) => {
		if (typeof details === 'function') {
			body = details;
			details = {};
		}
		declared.push({ title: String(title), tag: details && details.tag ? String(details.tag) : '', body });
	};
	stubTest.afterEach = () => {};
	stubTest.describe = (title, body) => {
		void title;
		body();
	};
	for (const forbidden of [ 'skip', 'fixme', 'fail', 'only' ]) {
		stubTest[forbidden] = () => {
			throw new Error(`The acceptance suite must not use test.${forbidden}().`);
		};
	}
	const stubExpect = options.expect || ( () => ( {} ) );
	const support = options.support || require(path.join(ROOT, SUPPORT_REL));
	const helpers = options.helpers || {
		loginAs: async () => {},
		hubFor: async () => ( {} ),
		installSecretCleanup: async () => {},
		assertSafeArtifacts: async () => {},
	};
	const sandboxRequire = name => {
		if (name === '@playwright/test') {
			return { test: stubTest, expect: stubExpect };
		}
		if (name === '@axe-core/playwright') {
			return { default: class {} };
		}
		if (name === './ui-helpers') {
			return helpers;
		}
		if (name === './ui-acceptance-support.cjs') {
			return support;
		}
		if (name === 'node:fs') {
			return {
				readFileSync: (file, encoding) => {
					if (file === '/work/.env') {
						return syntheticEnv();
					}
					return fs.readFileSync(file, encoding);
				},
				existsSync: file => (file === '/work/.env' ? true : fs.existsSync(file)),
				mkdirSync: () => {},
				writeFileSync: () => {},
			};
		}
		if (name === 'node:path') {
			return path;
		}
		throw new Error(`The acceptance spec requires an unsupported module: ${name}`);
	};
	const module = { exports: {} };
	const sandbox = {
		require: sandboxRequire,
		module,
		exports: module.exports,
		__dirname: path.join(ROOT, 'tests/E2E'),
		__filename: path.join(ROOT, SPEC_REL),
		process: { env: { BASE_URL: baseUrl }, cwd: () => ROOT },
		console,
		Buffer,
		setTimeout,
		clearTimeout,
	};
	vm.runInNewContext(source, sandbox, { filename: SPEC_REL });
	return { declared, exports: module.exports };
}

/**
 * Group declaration contract of the acceptance spec.
 *
 * @param {string} source Spec source.
 */
function specContract(source) {
	assert.doesNotMatch(source, new RegExp(LEGACY_SHORT), 'The acceptance spec must not name the legacy network namespace');
	assert.doesNotMatch(source, /--name\b/, 'The acceptance spec must not override the registered namespace');
	assert.doesNotMatch(
		source,
		/\/wp-json\/xnetvn-agent-hub-wp\/v1\//,
		'The plugin REST path must come from the support module, never from a hand-written literal',
	);
	for (const variable of [
		'RTL_DOMAIN_WP69',
		'RTL_ALIAS_WP69',
		'RTL_DOMAIN_WP71',
		'RTL_ALIAS_WP71',
		'RTL_DOMAIN_WPNETWORK',
		'RTL_ALIAS_WPNETWORK',
	]) {
		assert.ok(source.includes(variable), `The acceptance spec must read the lab host ${variable}`);
	}
	assert.ok(source.includes('/work/.env'), 'The acceptance spec must read the lab environment file');
	assert.match(
		source,
		/expect\(\s*sentinel\.state\s*,\s*'a foreign row that shares the name prefix must survive the cleanup of the run'\s*\)\s*\.toBe\(\s*'active'\s*\)/,
		'The acceptance spec must keep the assertion that a foreign row with the same prefix survives the cleanup',
	);
	assert.match(
		source,
		/expect\(\s*denied\s*,\s*'a cross-owner key must stay denied for the other actor'\s*\)\s*\.toEqual\(\s*\{\s*get:\s*404\s*,\s*rotate:\s*404\s*,\s*remove:\s*404\s*\}\s*\)/,
		'The acceptance spec must keep the assertion that a cross-owner key stays denied',
	);

	const site = loadSpec(source, 'https://wp69.canonical.test');
	const siteTitles = site.declared.map(entry => entry.title);
	assert.equal(siteTitles.length, 5, 'A single-site target must declare exactly five cases');
	for (const title of siteTitles) {
		assert.ok(title.startsWith('UI-ACC-SITE-'), `A single-site target must declare only site cases: ${title}`);
	}
	assert.deepEqual(
		siteTitles.map(title => title.split(' ')[0]),
		SITE_CASE_IDS,
		'A single-site target must declare exactly the reviewed case identifiers',
	);
	assert.deepEqual(
		plainGroups(site.exports.GROUP_MANIFEST),
		[
			{ group: 'SITE', status: 'APPLIED' },
			{ group: 'NET', status: 'NA' },
		],
		'The not applicable group must be reported as NA, separately from the applied group',
	);

	const network = loadSpec(source, 'https://network.canonical.test');
	const networkTitles = network.declared.map(entry => entry.title);
	assert.equal(networkTitles.length, 2, 'A network target must declare exactly two cases');
	for (const title of networkTitles) {
		assert.ok(title.startsWith('UI-ACC-NET-'), `A network target must declare only network cases: ${title}`);
	}
	assert.deepEqual(
		networkTitles.map(title => title.split(' ')[0]),
		NET_CASE_IDS,
		'A network target must declare exactly the reviewed case identifiers',
	);
	assert.deepEqual(
		plainGroups(network.exports.GROUP_MANIFEST),
		[
			{ group: 'SITE', status: 'NA' },
			{ group: 'NET', status: 'APPLIED' },
		],
		'The not applicable group must be reported as NA, separately from the applied group',
	);

	// The network alias is never a target: WordPress multisite redirects it to
	// the network home and the blog path is lost.
	assert.throws(
		() => loadSpec(source, 'https://network.alias.test'),
		/canonical domain/,
		'The network alias host must fail closed',
	);
	assert.throws(() => loadSpec(source, 'https://foreign.example.test'), 'A foreign host must fail closed');
	assert.throws(() => loadSpec(source, 'http://wp69.canonical.test'), 'A plain HTTP host must fail closed');
}

// ---------------------------------------------------------------------------
// The behavioural harness: the acceptance cases run against an in-memory
// model of the owned management API.
// ---------------------------------------------------------------------------

/**
 * Build the in-memory model of the owned management API.
 *
 * @param {object} options Model options.
 * @return {object} The model.
 */
function createModel(options = {}) {
	return {
		url: '/wp-admin/profile.php',
		user: 'administrator',
		users: { administrator: 11, editor: 12, editor_b: 13, subscriber: 14, super_admin: 15 },
		blogs: {
			main: { keys: [], settings: { audit_retention_days: 90 }, audit: [] },
			child: { keys: [], settings: { audit_retention_days: 90 }, audit: [] },
		},
		calls: [],
		created: [],
		nextId: 100,
		pendingName: '',
		secretShown: false,
		createMode: options.createMode || 'normal',
		misScoped: options.misScoped === true,
		revokeStaysActive: options.revokeStaysActive === true,
		sentinel: options.sentinel === true,
	};
}

/**
 * Resolve the blog the current page belongs to.
 *
 * @param {object} model The model.
 * @return {string} Blog name.
 */
function currentBlog(model) {
	return model.url.startsWith('/ui-second/') ? 'child' : 'main';
}

/**
 * Build the fetch implementation of the model.
 *
 * @param {object} model The model.
 * @return {Function} Fetch implementation.
 */
function modelFetch(model) {
	return async (input, init = {}) => {
		const url = new URL(String(input), 'https://wp69.canonical.test');
		const blog = url.pathname.startsWith('/ui-second/') ? 'child' : 'main';
		const data = model.blogs[blog];
		const method = (init.method || 'GET').toUpperCase();
		const resource = url.pathname.replace(/^\/ui-second/, '');
		model.calls.push(`${method} ${resource}${url.search}`);
		const respond = (status, body) => ( {
			ok: status >= 200 && status < 300,
			status,
			headers: { get: name => ( String(name).toLowerCase() === 'x-wp-total' ? String(data.keys.length) : null ) },
			json: async () => body,
		} );
		const userId = model.users[model.user];
		const isManager = model.user === 'administrator' || model.user === 'super_admin';
		if ( resource === '/wp-json/wp/v2/users/me' ) {
			return respond(200, { id: userId });
		}
		if ( resource.endsWith('/keys') && method === 'GET' ) {
			const all = url.searchParams.get('all') === 'true';
			const rows = all
				? data.keys
				: data.keys.filter(row => row.user_id === userId || ( model.misScoped && row.name === model.pendingName ));
			return respond(200, rows.map(row => ( { id: row.id, name: row.name, user_id: row.user_id, status: row.status } ) ));
		}
		const single = resource.match(/\/keys\/(\d+)(\/rotate)?$/);
		if ( single ) {
			const id = Number(single[1]);
			const row = data.keys.find(item => item.id === id);
			const visible = Boolean(row) && ( row.user_id === userId || isManager );
			if ( method === 'GET' ) {
				return visible ? respond(200, { id: row.id, name: row.name, user_id: row.user_id, status: row.status }) : respond(404, {});
			}
			if ( method === 'DELETE' ) {
				if ( ! visible ) {
					return respond(404, {});
				}
				if ( ! model.revokeStaysActive ) {
					row.status = 'revoked';
				}
				return respond(200, { id, revoked: true });
			}
			if ( method === 'POST' ) {
				return visible ? respond(200, {}) : respond(404, {});
			}
		}
		if ( resource.endsWith('/settings' ) ) {
			return respond(200, data.settings);
		}
		if ( resource.endsWith('/audit' ) ) {
			const ability = url.searchParams.get('ability');
			const rows = data.audit.filter(row => ! ability || row.ability === ability);
			return respond(200, { items: rows, total: rows.length, page: 1, per_page: 100 });
		}
		return respond(404, {});
	};
}

/**
 * Store the key the acceptance case creates in the hub.
 *
 * @param {object} model The model.
 */
function createKey(model) {
	if ( ! model.pendingName ) {
		return;
	}
	model.nextId += 1;
	const owner = model.createMode === 'foreign' ? 99 : model.users[model.user];
	const row = { id: model.nextId, name: model.pendingName, user_id: owner, status: 'active' };
	model.blogs[currentBlog(model)].keys.push(row);
	model.created.push(row);
	if ( model.createMode === 'duplicate' ) {
		model.nextId += 1;
		model.blogs[currentBlog(model)].keys.push({ id: model.nextId, name: model.pendingName, user_id: owner, status: 'active' });
	}
	model.secretShown = true;
}

/**
 * Build a locator of the fake hub.
 *
 * @param {object} model The model.
 * @param {string} selector Locator selector.
 * @return {object} The locator.
 */
function createLocator(model, selector) {
	const locator = {
		selector,
		visible: true,
		value: '',
		async fill(value) {
			model.pendingName = value;
			locator.value = value;
		},
		async click() {
			if ( selector.includes('data-create') ) {
				createKey(model);
			}
			if ( selector.includes('data-saved') ) {
				model.secretShown = false;
			}
		},
		async waitFor() {},
		async count() {
			return 1;
		},
		first() {
			return locator;
		},
		locator(child) {
			return createLocator(model, `${selector} ${child}`);
		},
		async evaluate(fn) {
			return fn({ value: locator.value });
		},
		text() {
			if ( selector.includes('data-keys') ) {
				const userId = model.users[model.user];
				return model.blogs[currentBlog(model)].keys
					.filter(row => row.user_id === userId)
					.map(row => row.name)
					.join(' ');
			}
			return locator.value;
		},
	};
	if ( selector.includes('data-secret-dialog') ) {
		Object.defineProperty(locator, 'visible', { get: () => Boolean(model.secretShown) });
	}
	if ( selector.includes('data-secret') ) {
		Object.defineProperty(locator, 'value', { get: () => ( model.secretShown ? 'xnv_secret_value' : '' ) });
	}
	return locator;
}

/**
 * Build the expect implementation of the harness.
 *
 * Every assertion the spec evaluates is recorded, so the contract can demand
 * that a reviewed assertion is still evaluated and still passes. A spec that
 * drops the assertion no longer proves the rule.
 *
 * @param {unknown} actual Value under test.
 * @param {unknown} message Message of the assertion.
 * @param {Array<object>} recorder Recorder of the evaluated assertions.
 * @return {object} The matcher.
 */
function makeExpect(actual, message, recorder) {
	const text = () => ( actual && typeof actual.text === 'function' ? String(actual.text()) : String(actual) );
	const check = (pass, matcher, expected, detail) => {
		if ( recorder ) {
			recorder.push({
				message: typeof message === 'string' ? message : '',
				matcher,
				expected,
				passed: pass,
			});
		}
		if ( ! pass ) {
			throw new Error(`expect failed: ${matcher} ${detail}`);
		}
	};
	const positive = {
		toBe(expected) {
			check(Object.is(actual, expected), 'toBe', expected, `expected ${String(expected)} got ${String(actual)}`);
		},
		toEqual(expected) {
			check(JSON.stringify(actual) === JSON.stringify(expected), 'toEqual', expected, `expected ${JSON.stringify(expected)} got ${JSON.stringify(actual)}`);
		},
		toBeVisible() {
			check(Boolean(actual) && actual.visible !== false, 'toBeVisible', true, 'expected the element to be visible');
		},
		toHaveCount(expected) {
			check(actual === expected, 'toHaveCount', expected, `expected the count ${expected} got ${String(actual)}`);
		},
		toBeGreaterThan(expected) {
			check(typeof actual === 'number' && actual > expected, 'toBeGreaterThan', expected, `expected a number above ${expected} got ${String(actual)}`);
		},
		toContainText(expected) {
			check(text().includes(expected), 'toContainText', expected, `expected the text to contain ${expected}`);
		},
	};
	const negative = {
		toBe(expected) {
			check(! Object.is(actual, expected), 'not.toBe', expected, `expected a value other than ${String(expected)}`);
		},
		toEqual(expected) {
			check(JSON.stringify(actual) !== JSON.stringify(expected), 'not.toEqual', expected, 'expected a different value');
		},
		toBeVisible() {
			check(! actual || actual.visible === false, 'not.toBeVisible', false, 'expected the element to be hidden');
		},
		toHaveCount(expected) {
			check(actual !== expected, 'not.toHaveCount', expected, `expected a count other than ${expected}`);
		},
		toBeGreaterThan(expected) {
			check(typeof actual !== 'number' || actual <= expected, 'not.toBeGreaterThan', expected, `expected a number at or below ${expected}`);
		},
		toContainText(expected) {
			check(! text().includes(expected), 'not.toContainText', expected, `expected the text not to contain ${expected}`);
		},
	};
	return Object.assign({}, positive, { not: negative });
}

/**
 * Build the page of the harness.
 *
 * @param {object} model The model.
 * @return {object} The page.
 */
function createPage(model) {
	return {
		url: () => model.url,
		context: () => ( { clearCookies: async () => {} } ),
		locator: selector => createLocator(model, selector),
		async goto(url) {
			model.url = url;
		},
		async setViewportSize() {},
		async waitForURL() {},
		async evaluate(fn, arg) {
			const sandbox = {
				window: { xnetvnAgentHubConfig: { nonce: 'nonce-main', userId: model.users[model.user] } },
				fetch: modelFetch(model),
				document: { documentElement: { scrollWidth: 0, clientWidth: 0 } },
				encodeURIComponent,
				Number,
				String,
				JSON,
				Array,
				Object,
				Boolean,
				Math,
			};
			return vm.runInNewContext(`(${fn.toString()})(arg)`, Object.assign({ arg }, sandbox));
		},
	};
}

/**
 * Build the helper stubs of the harness.
 *
 * @param {object} model The model.
 * @return {object} Helper stubs.
 */
function harnessHelpers(model) {
	return {
		loginAs: async (page, role) => {
			model.user = role;
			model.url = '/wp-admin/profile.php';
		},
		hubFor: async (page, view) => createLocator(model, `.xnetvn-agent-hub[data-view="${view}"]`),
		installSecretCleanup: async () => {},
		assertSafeArtifacts: async () => {},
	};
}

/**
 * Run one acceptance case against the model.
 *
 * @param {string} source Spec source.
 * @param {object} model The model.
 * @param {string} prefix Title prefix of the case.
 * @return {Promise<{outcome:string,message:string}>} The outcome.
 */
async function runCase(source, model, prefix) {
	const helpers = harnessHelpers(model);
	const assertions = [];
	const loaded = loadSpec(source, 'https://wp69.canonical.test', {
		expect: (actual, message) => makeExpect(actual, message, assertions),
		helpers,
	});
	const target = loaded.declared.find(entry => entry.title.startsWith(prefix));
	if ( ! target ) {
		throw new Error(`The acceptance suite does not declare the case ${prefix}`);
	}
	const page = createPage(model);
	const testInfo = { project: { name: 'chromium' }, testId: 'harness', workerIndex: 0, repeatEachIndex: 0 };
	try {
		await target.body({ page }, testInfo);
		return { outcome: 'pass', message: '', assertions };
	} catch (error) {
		return { outcome: 'fail', message: String(error && error.message ? error.message : error), assertions };
	}
}

/**
 * Run every scenario of the harness against one spec source.
 *
 * @param {string} source Spec source.
 * @return {Promise<object>} The scenario results.
 */
async function runScenarios(source) {
	const sentinelName = 'UI-ACC cross chromium-foreign';
	const results = {};

	const normal = createModel();
	normal.blogs.main.keys.push({ id: 7, name: sentinelName, user_id: 99, status: 'active' });
	results.S1 = await runCase(source, normal, CROSS_OWNER_CASE);
	results.S1.calls = normal.calls;
	results.S1.sentinel = normal.blogs.main.keys.find(row => row.name === sentinelName);
	results.S1.deletes = normal.calls.filter(call => call.startsWith('DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/'));
	results.S1.owned = normal.created.length > 0 ? normal.created[0] : null;
	results.S1.createdIds = normal.created.map(row => row.id);

	const ambiguous = createModel({ createMode: 'duplicate' });
	results.S2 = await runCase(source, ambiguous, CROSS_OWNER_CASE);
	results.S2.calls = ambiguous.calls;
	results.S2.deletes = ambiguous.calls.filter(call => call.startsWith('DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/'));

	const foreign = createModel({ createMode: 'foreign', misScoped: true });
	results.S3 = await runCase(source, foreign, CROSS_OWNER_CASE);
	results.S3.calls = foreign.calls;
	results.S3.deletes = foreign.calls.filter(call => call.startsWith('DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/'));

	const secret = createModel();
	results.S4 = await runCase(source, secret, SECRET_CASE);
	results.S4.owned = secret.created.length > 0 ? secret.created[0] : null;

	const broken = createModel({ revokeStaysActive: true });
	results.S5 = await runCase(source, broken, SECRET_CASE);

	return results;
}

/**
 * The scenarios of the harness must hold for the reviewed spec.
 *
 * @param {object} results Scenario results.
 */
function scenarioContract(results) {
	assert.equal(results.S1.outcome, 'pass', `The owned key case must pass: ${results.S1.message}`);
	assert.ok(results.S1.owned, 'The owned key case must create a key');
	assert.equal(results.S1.owned.status, 'revoked', 'The owned key must be revoked');
	assert.ok(results.S1.sentinel, 'The foreign sentinel row must exist');
	assert.equal(results.S1.sentinel.status, 'active', 'A foreign row that shares the name prefix must survive');
	assert.ok(results.S1.deletes.length >= 2, 'The owned key must be revoked and the cleanup must repeat it');
	for (const call of results.S1.deletes) {
		const target = Number(call.replace('DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/', '').replace(/\?.*$/, ''));
		assert.ok(results.S1.createdIds.includes(target), `A delete must target a row of this run: ${call}`);
		assert.ok(! call.includes(`/${results.S1.sentinel.id}/`), `A delete must never target the foreign row: ${call}`);
	}
	assert.ok(results.S1.createdIds.length >= 2, 'The case must create the owned key and the foreign sentinel row');
	// The two reviewed rules of the browser case must still be asserted by the
	// spec itself: the harness records every assertion the case evaluates, so
	// a spec that drops one of them loses the proof of that rule.
	for (const message of [ SENTINEL_ASSERTION, DENIED_ASSERTION ]) {
		const evaluated = results.S1.assertions.filter(entry => entry.message === message);
		assert.equal(evaluated.length, 1, `The acceptance case must keep the assertion "${message}"`);
		assert.equal(evaluated[0].passed, true, `The assertion "${message}" must pass`);
	}
	assert.equal(results.S2.outcome, 'fail', 'Two rows of the same name must stop the case');
	assert.equal(results.S2.deletes.length, 0, 'An ambiguous reconciliation must not delete anything');
	assert.equal(results.S3.outcome, 'fail', 'A row of another owner must stop the case');
	assert.equal(results.S3.deletes.length, 0, 'A row of another owner must not be deleted');
	assert.ok(
		results.S3.calls.length < results.S1.calls.length,
		'A row of another owner must stop the case before the cross-owner steps',
	);
	assert.equal(results.S4.outcome, 'pass', `The secret case must pass: ${results.S4.message}`);
	assert.ok(results.S4.owned, 'The secret case must create a key');
	assert.equal(results.S4.owned.status, 'revoked', 'The secret case must revoke its key');
	assert.equal(results.S5.outcome, 'fail', 'A revocation that does not take effect must fail the case');
}

/**
 * The harness must catch a spec that breaks a reviewed rule.
 *
 * @param {string} source Spec source.
 * @param {object} baseline Results of the reviewed spec.
 */
async function mutantContract(source, baseline) {
	const reconcile = 'selectOwnedRow(matches.map(match => ({ ...match, site: ledger.site })), ledger)';
	const deleteTarget = 'selectDeletableIds(records.map(record => ({ ...record, site: ledger.site })), ledger)';
	const revokeCheck = "expect(after.state, 'the revoked key must report the revoked state').toBe('revoked');";
	const mutants = [
		{
			id: 'M4',
			rule: 'exactly one row of the run may enter the ledger',
			mutate: text => text.replace(
				reconcile,
				'selectOwnedRow(matches.slice(0, 1).map(match => ({ ...match, site: ledger.site })), ledger)',
			),
			caught: (results, reference) => results.S2.calls.length !== reference.S2.calls.length,
		},
		{
			id: 'M6',
			rule: 'the key must be read again after the revocation',
			mutate: text => text.split(revokeCheck).join('void after;'),
			caught: (results, reference) => results.S5.outcome !== reference.S5.outcome,
		},
		{
			id: 'M7',
			rule: 'only the key of the run may be revoked',
			mutate: text => text.replace(
				deleteTarget,
				"records.filter(record => typeof record.name === 'string' && record.name.startsWith('UI-ACC ')).map(record => record.id)",
			),
			caught: (results, reference) => {
				const sentinel = results.S1.sentinel;
				const original = reference.S1.sentinel;
				return ! sentinel || sentinel.status !== original.status;
			},
		},
		{
			id: 'R1',
			rule: 'a foreign row that shares the name prefix must survive the cleanup',
			mutate: text => text.split(
				"expect(sentinel.state, 'a foreign row that shares the name prefix must survive the cleanup of the run').toBe('active');",
			).join(''),
			caught: (results, reference) => ! results.S1.assertions.some(entry => entry.message === SENTINEL_ASSERTION),
		},
		{
			id: 'R2',
			rule: 'a cross-owner key must stay denied for the other actor',
			mutate: text => text.split(
				"expect(denied, 'a cross-owner key must stay denied for the other actor').toEqual({ get: 404, rotate: 404, remove: 404 });",
			).join(''),
			caught: (results, reference) => ! results.S1.assertions.some(entry => entry.message === DENIED_ASSERTION),
		},
		{
			id: 'M8',
			rule: 'the row must belong to the owner of the run',
			mutate: text => text.replace(
				reconcile,
				'selectOwnedRow(matches.map(match => ({ ...match, site: ledger.site })), { run_id: ledger.run_id, site: ledger.site, user_id: matches[0] && matches[0].user_id, name: ledger.name })',
			),
			caught: (results, reference) => results.S3.calls.length !== reference.S3.calls.length,
		},
	];
	for (const mutant of mutants) {
		const mutated = mutant.mutate(source);
		assert.notEqual(mutated, source, `The mutant ${mutant.id} did not change the spec`);
		const results = await runScenarios(mutated);
		assert.ok(
			mutant.caught(results, baseline),
			`The harness must catch the mutant ${mutant.id} (${mutant.rule})`,
		);
	}
}

/**
 * Compose contract of the two stacks of the one namespace.
 */
function composeContract(single, network) {
	for (const service of [ 'db', 'wpnetwork', 'toolbox' ]) {
		assert.ok(network.includes(`  ${service}:`), `The network Compose must declare the ${service} service`);
	}
	for (const volume of [
		'ui_network_db_data',
		'ui_network_wp_data',
		'ui_network_vendor_data',
		'ui_network_adapter_data',
		'ui_network_plugin_data',
	]) {
		assert.ok(network.includes(volume), `The network Compose must own the ${volume} volume`);
		assert.ok(! single.includes(volume), `The single-site Compose must not own the ${volume} volume`);
	}
	assert.ok(network.includes('/work/infra/remote/ui-network-start.sh'), 'The network toolbox must run the owned installer');
	assert.ok(network.includes('mem_limit: 512m') && network.includes('mem_limit: 1024m'), 'The network services must keep their memory limits');
	assert.ok(network.includes('cpus: 1.0'), 'The network services must keep their CPU limits');
	assert.ok(! network.includes(LEGACY_SHORT), 'The network Compose must not name the legacy namespace');
	assert.doesNotMatch(network, /--name\b/, 'The network Compose must not override the registered namespace');
	assert.ok(! network.includes('privileged: true'), 'The network Compose must not run a privileged service');
	assert.ok(! network.includes('/var/run/docker.sock'), 'The network Compose must not mount the Docker socket');
}

/**
 * Guidance contract: the ignored assets of the project stay authoritative.
 */
function guidanceContract(instructions, runbook, scenarios) {
	for (const source of [ instructions, runbook ]) {
		assert.ok(source.includes('`xnetvn-agent-hub-wp`'), 'The main namespace must be explicit');
		assert.doesNotMatch(source, /--name\b/, 'Do not override the registered namespace');
		for (const line of source.split('\n')) {
			if (! line.includes(LEGACY_SHORT)) {
				continue;
			}
			assert.match(line, LEGACY_MARK, 'The legacy namespace name must stay on a marked "Legacy note:" line');
			assert.match(line, NOT_FOR_USE, 'The legacy note must state that the legacy namespace is not to be used');
		}
	}
	assert.ok(runbook.includes('compose.ui-network.yaml'), 'The runbook must keep the network Compose file');
	assert.ok(runbook.includes('compose.yaml'), 'The runbook must keep the single-site Compose file');
	assert.match(runbook, /lease[^\n]*(?:STOP|stop)/, 'The runbook must keep the live lease stop gate');
	assert.match(runbook, /domain[^\n]*(?:STOP|stop)/, 'The runbook must keep the domain stop gate');
	assert.ok(Array.isArray(scenarios.cases) && scenarios.cases.length > 0, 'The safety scenarios must be readable');
	for (const entry of scenarios.cases) {
		assert.ok(typeof entry.id === 'string' && entry.id !== '', 'Every safety scenario needs an id');
		assert.ok(Array.isArray(entry.expected_actions), `The safety scenario ${entry.id} needs its expected actions`);
	}
}

/**
 * Host name guard contract of the fixtures and of the browser configuration.
 */
function guardContract(fixture, browser) {
	const needle = 'in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_hosts, true )';
	const lines = fixture.split(/\r?\n/);
	const index = lines.findIndex(line => line.includes(needle));
	assert.ok(index >= 0, `Missing strict host allowlist guard: ${needle}`);
	assert.match(lines[index], /^\s*if \(.*! in_array\( \$xnetvn_agent_hub_wp_net_home, \$\w+, true \)/);
	const body = lines.slice(index + 1)
		.map(line => line.trim())
		.filter(line => line !== '' && ! line.startsWith('//'));
	assert.ok(body.length > 0, 'The host allowlist guard must deny something');
	assert.match(body[0], /^throw new RuntimeException\(/);
	assert.ok(fixture.includes('! is_multisite()'), 'The network fixture must require a multisite install');
	assert.ok(fixture.includes('PHP_URL_HOST'), 'The network fixture must compare the home host');
	for (const variable of [ 'XNETVN_RTL_DOMAIN', 'RTL_DOMAIN_WPNETWORK', 'RTL_ALIAS_WPNETWORK' ]) {
		assert.ok(fixture.includes(variable), `The network fixture must read the lab host ${variable}`);
	}
	assert.ok(browser.includes('!allowedHosts.includes(new URL(process.env.BASE_URL).hostname)'), 'The browser configuration must keep its host allowlist');
}

/**
 * Fixture and installer contract of the owned network data.
 */
function fixtureContract(fixture, startup) {
	assert.ok(fixture.includes('xnetvn_ui_super_admin'), 'The network fixture must own the super administrator actor');
	assert.ok(fixture.includes('xnetvn_ui_network_fixture'), 'The network fixture must mark its own actors');
	assert.ok(fixture.includes('grant_super_admin'), 'The network fixture must grant the owned super administrator');
	assert.ok(fixture.includes('89'), 'The network fixture must seed the main retention value');
	assert.ok(fixture.includes('88'), 'The network fixture must seed the child retention value');
	assert.ok(fixture.includes('xnetvn/ui-main-canary'), 'The network fixture must seed the main audit canary');
	assert.ok(fixture.includes('xnetvn/ui-child-canary'), 'The network fixture must seed the child audit canary');
	assert.ok(fixture.includes('insert_batch'), 'The network fixture must insert the audit canaries through the repository');
	assert.doesNotMatch(fixture, /TRUNCATE/i, 'The network fixture must never truncate a table');
	assert.doesNotMatch(fixture, /audit[^\n]*LIKE[^\n]*%/i, 'The network fixture must never clean the audit table by prefix');
	assert.doesNotMatch(
		fixture,
		/update_option\( WpSiteSettings::OPTION, [^)]*, false \)/,
		'The network fixture must not change the autoload flag of the settings option',
	);

	assert.ok(startup.includes('XNETVN_RTL'), 'The installer must check the lab guard');
	assert.ok(startup.includes('/work') && startup.includes('/sites/wpnetwork'), 'The installer must use the fixed owned paths');
	assert.ok(startup.includes('tests/E2E/prepare.php'), 'The installer must prepare the mail sink before the actors');
	assert.ok(startup.includes('site create'), 'The installer must create the owned child site');
	assert.ok(startup.includes('--skip-email'), 'The installer must not send the install mail');
	const childIndex = startup.indexOf('site create');
	const activateIndex = startup.indexOf('plugin activate');
	assert.ok(childIndex > 0 && activateIndex > 0, 'The installer must create the child site and activate the plugin');
	assert.ok(childIndex < activateIndex, 'The child site must exist before the installer activates the plugin on it');
	const prepareIndex = startup.indexOf('tests/E2E/prepare.php');
	assert.ok(prepareIndex < childIndex, 'The mail sink must exist before the installer creates the child site');
	assert.doesNotMatch(startup, new RegExp(LEGACY_SHORT), 'The installer must not name the legacy namespace');

	// The single-site hosts of the collision guard come from the lab
	// environment file: the network toolbox declares only the network hosts,
	// so a process environment check would never prove anything.
	assert.ok(
		startup.includes("xnetvn_env_file=\"$work_dir/.env\""),
		'The installer must read the single-site hosts from the lab environment file',
	);
	for (const variable of [ 'RTL_DOMAIN_WP69', 'RTL_ALIAS_WP69', 'RTL_DOMAIN_WP71', 'RTL_ALIAS_WP71' ]) {
		assert.ok(startup.includes(variable), `The installer must compare the network host with ${variable}`);
	}
	assert.doesNotMatch(
		startup,
		/\$\{RTL_DOMAIN_WP69:-\}/,
		'The installer must not read the single-site hosts from the process environment',
	);
	const guardIndex = startup.indexOf('xnetvn_env_file');
	const collisionIndex = startup.indexOf('must not collide with a single-site host');
	assert.ok(guardIndex > 0 && collisionIndex > guardIndex, 'The installer must read the hosts before the collision guard');
	assert.ok(
		startup.includes('The lab environment file is missing'),
		'The installer must fail closed when the lab environment file is missing',
	);
}

/**
 * Run the whole contract.
 */
async function main() {
	const source = read(SPEC_REL);
	const support = require(path.join(ROOT, SUPPORT_REL));
	supportContract(support);
	// The behavioural contract runs first: the cases of the spec are executed
	// and their own assertion results are demanded. The source level guard
	// below is the second layer.
	const baseline = await runScenarios(source);
	scenarioContract(baseline);
	specContract(source);
	await mutantContract(source, baseline);
	composeContract(read('compose.yaml'), read('compose.ui-network.yaml'));
	guidanceContract(read(INSTRUCTIONS_REL), read(RUNBOOK_REL), JSON.parse(read(SCENARIOS_REL)));
	guardContract(read(FIXTURE_REL), read('tests/E2E/playwright.remote.config.js'));
	fixtureContract(read(FIXTURE_REL), read(START_REL));
	process.stdout.write('UI acceptance contract: OK\n');
}

main().catch(error => {
	process.stderr.write(`UI acceptance contract: FAIL — ${error && error.message ? error.message : String(error)}\n`);
	if (process.env.UI_CONTRACT_DEBUG) { process.stderr.write(String(error && error.stack) + '\n'); }
	process.exitCode = 1;
});
