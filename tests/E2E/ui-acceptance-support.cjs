// Pure helpers for the remote UI acceptance suite.
//
// The module never touches the network, the filesystem, the database or the
// process environment: every decision is a pure function of its arguments, so
// the contract checker can exercise it without a lab, a browser or a Docker
// container. Host names arrive from the lab environment file, never from the
// request under test, and no error message repeats an environment value.
//
// @package XNetVN\AgentHubWP

'use strict';

const crypto = require('node:crypto');

/** Management REST namespace of the plugin. */
const REST_NAMESPACE = 'xnetvn-agent-hub-wp/v1';
/** Blog path of the network main site. */
const MAIN_BLOG_PATH = '/';
/** Blog path of the owned child site of the network. */
const CHILD_BLOG_PATH = '/ui-second/';
/** Blog paths the acceptance suite may address. */
const BLOG_PATHS = Object.freeze([ MAIN_BLOG_PATH, CHILD_BLOG_PATH ]);
/** Group of the single-site targets. */
const GROUP_SITE = 'SITE';
/** Group of the multisite network target. */
const GROUP_NET = 'NET';
/** Host group name to acceptance group. */
const HOST_GROUPS = Object.freeze({ wp69: GROUP_SITE, wp71: GROUP_SITE, network: GROUP_NET });
/** Bounded run identifier: at most 26 lower-case characters. */
const RUN_ID_PATTERN = /^[a-z0-9][a-z0-9-]{0,25}$/;

/** Fixed failures. They never contain an environment value. */
const ERRORS = Object.freeze({
	target: 'UI acceptance target is not one exact lab host.',
	hosts: 'UI acceptance host configuration is not usable.',
	blog: 'UI acceptance blog path is not an owned lab blog.',
	resource: 'UI acceptance REST resource is not a plain relative path.',
	runId: 'UI acceptance run id is not a bounded identifier.',
	ambiguous: 'UI acceptance owned row is not unique.',
	ownership: 'UI acceptance row is not owned by this run.',
});

/**
 * Check that a value is a plain object.
 *
 * @param {unknown} value Candidate.
 * @return {boolean} True for a plain object.
 */
function isPlainObject(value) {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Read a strictly positive integer.
 *
 * @param {unknown} value Candidate.
 * @return {number|null} The integer or null.
 */
function positiveInt(value) {
	const parsed = typeof value === 'number' ? value : Number(value);
	if (!Number.isInteger(parsed) || parsed < 1) {
		return null;
	}
	return parsed;
}

/**
 * Resolve the host name of an HTTPS base URL.
 *
 * @param {unknown} baseUrl Candidate base URL.
 * @return {string} Lower-case host name.
 * @throws {Error} When the URL is missing, not HTTPS or carries credentials.
 */
function targetHostname(baseUrl) {
	if (typeof baseUrl !== 'string' || baseUrl === '') {
		throw new Error(ERRORS.target);
	}
	let url;
	try {
		url = new URL(baseUrl);
	} catch {
		throw new Error(ERRORS.target);
	}
	if (url.protocol !== 'https:' || url.username !== '' || url.password !== '' || url.hostname === '') {
		throw new Error(ERRORS.target);
	}
	return url.hostname.toLowerCase();
}

/**
 * Normalize the configured host groups.
 *
 * A group without hosts is allowed: a run against one target only needs the
 * group of that target. A malformed group is still refused.
 *
 * @param {unknown} hosts Host configuration.
 * @return {Array<[string,{hosts:string[]}]>} Group name and host list.
 * @throws {Error} When the configuration is missing or malformed.
 */
function hostGroups(hosts) {
	if (!isPlainObject(hosts)) {
		throw new Error(ERRORS.hosts);
	}
	const groups = [];
	for (const name of Object.keys(HOST_GROUPS)) {
		const list = hosts[name];
		if (list === undefined || list === null) {
			groups.push([ name, { hosts: [] } ]);
			continue;
		}
		if (!Array.isArray(list)) {
			throw new Error(ERRORS.hosts);
		}
		const cleaned = [];
		for (const host of list) {
			if (typeof host !== 'string' || host === '' || /[\s/\\@:]/.test(host)) {
				throw new Error(ERRORS.hosts);
			}
			const lower = host.toLowerCase();
			if (!cleaned.includes(lower)) {
				cleaned.push(lower);
			}
		}
		groups.push([ name, { hosts: cleaned } ]);
	}
	return groups;
}

/**
 * Classify the acceptance target from its base URL.
 *
 * The host must match exactly one configured lab host over HTTPS. A missing,
 * foreign or ambiguous host fails closed with a fixed message, and the result
 * never repeats the host name so a report cannot leak the environment.
 *
 * @param {unknown} baseUrl Base URL of the run.
 * @param {unknown} hosts Host configuration with wp69/wp71/network lists.
 * @return {{group:string,target:string,canonical:boolean}} Classification.
 * @throws {Error} When the target is not one exact configured lab host.
 */
function classifyTarget(baseUrl, hosts) {
	const hostname = targetHostname(baseUrl);
	const matches = [];
	for (const [ name, entry ] of hostGroups(hosts)) {
		entry.hosts.forEach((host, index) => {
			if (host === hostname) {
				matches.push({ group: HOST_GROUPS[name], target: name, canonical: index === 0 });
			}
		});
	}
	if (matches.length !== 1) {
		throw new Error(ERRORS.target);
	}
	return Object.freeze({ group: matches[0].group, target: matches[0].target, canonical: matches[0].canonical });
}

/**
 * Build the REST path of one blog.
 *
 * @param {unknown} blogPath Blog path of the addressed site.
 * @param {unknown} resource Relative REST resource.
 * @return {string} Absolute path of the resource on that blog.
 * @throws {Error} When the blog path or the resource is not allowed.
 */
function blogRestPath(blogPath, resource) {
	if (!BLOG_PATHS.includes(blogPath)) {
		throw new Error(ERRORS.blog);
	}
	if (typeof resource !== 'string' || resource === '' || !/^[A-Za-z0-9_\-./]+$/.test(resource)) {
		throw new Error(ERRORS.resource);
	}
	if (resource.includes('..') || resource.includes('//') || resource.startsWith('/') || resource.endsWith('/')) {
		throw new Error(ERRORS.resource);
	}
	const prefix = blogPath === CHILD_BLOG_PATH ? '/ui-second' : '';
	return `${prefix}/wp-json/${REST_NAMESPACE}/${resource}`;
}

/**
 * Check that a listed key carries the identity of this run.
 *
 * The check covers the exact name, the same owner, the same blog and a
 * bounded run identifier. The id is checked separately, because the ledger
 * learns the id from the row it reconciles.
 *
 * @param {unknown} record Key record as returned by the listing.
 * @param {unknown} ledgerEntry Ledger entry written by this run.
 * @return {boolean} True when the record carries the identity of the run.
 */
function matchesOwnership(record, ledgerEntry) {
	if (!isPlainObject(record) || !isPlainObject(ledgerEntry)) {
		return false;
	}
	if (positiveInt(record.id) === null) {
		return false;
	}
	if (typeof record.name !== 'string' || record.name === '' || record.name !== ledgerEntry.name) {
		return false;
	}
	const owner = positiveInt(record.user_id);
	const ledgerOwner = positiveInt(ledgerEntry.user_id);
	if (owner === null || ledgerOwner === null || owner !== ledgerOwner) {
		return false;
	}
	if (typeof ledgerEntry.site !== 'string' || ledgerEntry.site === '') {
		return false;
	}
	if (record.site !== ledgerEntry.site) {
		return false;
	}
	if (typeof ledgerEntry.run_id !== 'string' || !RUN_ID_PATTERN.test(ledgerEntry.run_id)) {
		return false;
	}
	return true;
}

/**
 * Check that a listed key is the one this run created.
 *
 * Ownership needs an exact id, an exact name, the same owner and the same
 * blog. A key that merely shares the name prefix of the run is not owned.
 *
 * @param {unknown} record Key record as returned by the listing.
 * @param {unknown} ledgerEntry Ledger entry written by this run.
 * @return {boolean} True when the record is the owned key.
 */
function isOwnedKey(record, ledgerEntry) {
	if (!matchesOwnership(record, ledgerEntry)) {
		return false;
	}
	const id = positiveInt(record.id);
	const ledgerId = positiveInt(ledgerEntry.id);
	return id !== null && ledgerId !== null && id === ledgerId;
}

/**
 * Select the one row this run created.
 *
 * Exactly one row may carry the name of the run, and that row must belong to
 * the owner and the blog of the run. Zero, several or a foreign row keep the
 * state unknown: the caller must stop instead of guessing.
 *
 * @param {unknown} matches Rows of the listing that carry the run name.
 * @param {unknown} ledgerEntry Ledger entry written by this run.
 * @return {object} The owned row.
 * @throws {Error} When the row is not unique or not owned.
 */
function selectOwnedRow(matches, ledgerEntry) {
	if (!Array.isArray(matches) || matches.length !== 1) {
		throw new Error(ERRORS.ambiguous);
	}
	const record = matches[0];
	if (!matchesOwnership(record, ledgerEntry)) {
		throw new Error(ERRORS.ownership);
	}
	return {
		run_id: ledgerEntry.run_id,
		site: ledgerEntry.site,
		user_id: positiveInt(ledgerEntry.user_id),
		name: ledgerEntry.name,
		id: positiveInt(record.id),
	};
}

/**
 * Select the rows this run may revoke.
 *
 * Only the row of the run, identified by its exact name and its exact id,
 * may be revoked. A row of another owner that carries the same name is never
 * touched: the caller has to stop instead of deleting a foreign row.
 *
 * @param {unknown} records Rows of the listing.
 * @param {unknown} ledgerEntry Ledger entry written by this run.
 * @return {number[]} Ids this run may revoke.
 * @throws {Error} When a row of the run exists but is not owned.
 */
function selectDeletableIds(records, ledgerEntry) {
	if (!Array.isArray(records)) {
		throw new Error(ERRORS.ownership);
	}
	const named = records.filter(record => isPlainObject(record) && record.name === ledgerEntry.name);
	if (named.length === 0) {
		return [];
	}
	if (named.length > 1) {
		throw new Error(ERRORS.ambiguous);
	}
	if (!isOwnedKey(named[0], ledgerEntry)) {
		throw new Error(ERRORS.ownership);
	}
	return [ positiveInt(named[0].id) ];
}

/**
 * Build the bounded run identifier of one test instance.
 *
 * The identifier carries the browser project and a digest of the test
 * instance, so the three engines never share a name and a repeated run never
 * collides with a leftover row of an earlier run.
 *
 * @param {unknown} parts Project name and test instance parts.
 * @return {string} Bounded run identifier.
 * @throws {Error} When the identifier cannot be built in bounds.
 */
function buildRunId(parts) {
	const source = isPlainObject(parts) ? parts : {};
	const project = String(source.project ?? '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
	if (project === '') {
		throw new Error(ERRORS.runId);
	}
	const seed = [ 'testId', 'workerIndex', 'repeatEachIndex', 'startedAt' ]
		.map(name => String(source[name] ?? ''))
		.join('|');
	const digest = crypto.createHash('sha256').update(seed).digest('hex').slice(0, 8);
	const runId = `${project}-${digest}`;
	if (!RUN_ID_PATTERN.test(runId)) {
		throw new Error(ERRORS.runId);
	}
	return runId;
}

module.exports = {
	REST_NAMESPACE,
	MAIN_BLOG_PATH,
	CHILD_BLOG_PATH,
	BLOG_PATHS,
	GROUP_SITE,
	GROUP_NET,
	HOST_GROUPS,
	RUN_ID_PATTERN,
	ERRORS,
	classifyTarget,
	blogRestPath,
	isOwnedKey,
	matchesOwnership,
	selectOwnedRow,
	selectDeletableIds,
	buildRunId,
};
