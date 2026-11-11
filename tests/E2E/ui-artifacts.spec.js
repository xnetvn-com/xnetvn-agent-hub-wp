const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { assertSafeArtifacts } = require('./ui-helpers');

const SELFTEST_DIR = path.resolve(__dirname, '../../.local/remote-test/artifact-scan-selftest');
const SIZE_CAP = 1048576;
const SAMPLE_KEY = 'xnv_' + 'a'.repeat(12) + '_' + 'A'.repeat(43);
const SAMPLE_COOKIE = 'wordpress_logged_in_' + 'a'.repeat(32);

function clearFixtures() {
	fs.rmSync(SELFTEST_DIR, { recursive: true, force: true });
}

function writeFixture(name, content) {
	fs.mkdirSync(SELFTEST_DIR, { recursive: true });
	fs.writeFileSync(path.join(SELFTEST_DIR, name), content);
}

function oversize(content) {
	return content.padEnd(SIZE_CAP + 1, 'x');
}

async function scanMessage() {
	try {
		await assertSafeArtifacts(SELFTEST_DIR);
		return '';
	} catch (error) {
		return error.message;
	}
}

test.afterEach(() => {
	clearFixtures();
});

test('UI-ART-01 text artifacts with credential-like content are reported', async () => {
	clearFixtures();
	writeFixture('error-context.md', 'failure snapshot\nkey ' + SAMPLE_KEY + '\n');
	writeFixture('notes.txt', 'session cookie ' + SAMPLE_COOKIE);
	writeFixture('clean.md', 'no credential material in this file');
	writeFixture('huge.md', oversize('failure snapshot\nkey ' + SAMPLE_KEY + '\n'));
	const message = await scanMessage();
	expect(message).toContain('Unsafe artifacts: 2 file(s)');
	expect(message).toContain('error-context.md');
	expect(message).toContain('notes.txt');
	expect(message).not.toContain('clean.md');
	expect(message).not.toContain('huge.md');
	expect(message).not.toContain(SAMPLE_KEY);
	expect(message).not.toContain(SAMPLE_COOKIE);
});

test('UI-ART-02 json and log checks keep their limits and never print values', async () => {
	clearFixtures();
	writeFixture('results.json', JSON.stringify({ key: SAMPLE_KEY }));
	writeFixture('notes.log', 'session cookie ' + SAMPLE_COOKIE);
	writeFixture('storageState.json', '{}');
	writeFixture('huge.txt', oversize('session cookie ' + SAMPLE_COOKIE));
	writeFixture('clean.txt', 'no credential material in this file');
	const message = await scanMessage();
	expect(message).toContain('Unsafe artifacts: 3 file(s)');
	expect(message).toContain('results.json');
	expect(message).toContain('notes.log');
	expect(message).toContain('storageState.json');
	expect(message).not.toContain('huge.txt');
	expect(message).not.toContain('clean.txt');
	expect(message).not.toContain(SAMPLE_KEY);
	expect(message).not.toContain(SAMPLE_COOKIE);
});

// Annotation required by BUG_v0.1_2026-10-15_03 (B-01):
// SAMPLE_KEY and SAMPLE_COOKIE are fabricated values built by this test, not real secrets.
// SELFTEST_DIR is the hardcoded path <project root>/.local/remote-test/artifact-scan-selftest
// (it never reads process.env.RTL_ARTIFACTS_DIR to build a path) so the guard at
// tests/E2E/ui-helpers.js:104-110 accepts it in both run modes: when RTL_ARTIFACTS_DIR is
// set (the guard accepts exactly that path, not its subdirectories) and when it is not set
// (the guard accepts every path under <project root>/.local/remote-test). fs.rmSync in
// clearFixtures removes only the artifact-scan-selftest directory created by this test and
// never touches other artifacts in .local/remote-test/. This ticket does not modify the
// guard at lines 104-110 and does not add new exception paths to the guard.
