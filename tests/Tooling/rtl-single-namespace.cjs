// Source-only safety contract; no YAML execution, subprocess, service or network.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.resolve(__dirname, '../..');
const read = rel => {
  const file = path.join(ROOT, rel);
  assert.ok(fs.existsSync(file), `Missing input: ${rel}`);
  return fs.readFileSync(file, 'utf8');
};

// Accept only the explicit short-mount/block-volume subset used in these files.
// Fail closed on aliases, inline definitions and long mounts instead of pretending
// to implement arbitrary YAML. This checks source, not rendered Compose behavior.
function volumes(source) {
  const lines = source.split(/\r?\n/);
  const start = lines.findIndex(line => line === 'volumes:');
  assert.ok(start >= 0, 'Missing volume declarations');
  const keys = [];
  for (const line of lines.slice(start + 1)) {
    if (!line.trim() || line.trimStart().startsWith('#')) continue;
    assert.match(line, /^  [a-z][a-z0-9_]*:\s*$/, 'Volume definitions must not use aliases, name or external');
    const key = line.trim().slice(0, -1);
    assert.ok(!keys.includes(key), `Duplicate volume: ${key}`);
    keys.push(key);
  }
  const mounts = {};
  let service; let inMounts = false;
  for (const line of lines.slice(0, start)) {
    const match = line.match(/^  ([a-z][a-z0-9_]*):$/);
    if (match) { service = match[1]; mounts[service] = []; inMounts = false; }
    if (line === '    volumes:') { inMounts = true; continue; }
    if (inMounts && /^    \S/.test(line)) inMounts = false;
    if (!inMounts || !line.trim() || line.trimStart().startsWith('#')) continue;
    assert.match(line, /^      - [^\s]+$/, 'Unsupported mount shape');
    const value = line.trim().slice(2);
    const [sourceKey, target, mode, extra] = value.split(':');
    assert.ok(target?.startsWith('/') && !extra, 'Invalid mount');
    if (sourceKey.startsWith('.')) continue;
    assert.ok(keys.includes(sourceKey), `Undeclared mount: ${sourceKey}`);
    mounts[service].push([sourceKey, target, mode || 'rw']);
  }
  return { keys, mounts };
}

function isolation(single, network) {
  const a = volumes(single); const b = volumes(network);
  assert.deepEqual(b.keys.slice().sort(), ['ui_network_db_data', 'ui_network_wp_data', 'ui_network_vendor_data', 'ui_network_adapter_data', 'ui_network_plugin_data'].sort());
  assert.ok(!a.keys.some(key => b.keys.includes(key)), 'Single and network volumes overlap');
  assert.deepEqual(b.mounts.db, [['ui_network_db_data', '/var/lib/mysql', 'rw']]);
  assert.deepEqual(b.mounts.wpnetwork, [
    ['ui_network_wp_data', '/var/www/html', 'rw'],
    ['ui_network_plugin_data', '/var/www/html/wp-content/plugins/xnetvn-agent-hub-wp', 'rw'],
    ['ui_network_vendor_data', '/var/www/html/wp-content/plugins/xnetvn-agent-hub-wp/vendor', 'ro'],
    ['ui_network_adapter_data', '/var/www/html/wp-content/plugins/mcp-adapter', 'ro'],
  ]);
  assert.deepEqual(b.mounts.toolbox, [
    ['ui_network_vendor_data', '/work/vendor', 'rw'],
    ['ui_network_wp_data', '/sites/wpnetwork', 'rw'],
    ['ui_network_vendor_data', '/sites/wpnetwork/wp-content/plugins/xnetvn-agent-hub-wp/vendor', 'rw'],
    ['ui_network_adapter_data', '/sites/wpnetwork/wp-content/plugins/mcp-adapter', 'rw'],
  ]);
}

// The short legacy namespace name may only survive as one explicitly marked
// "Legacy note:" line that states it is not to be used. Any other mention
// would point an agent at a second stack, so it is rejected.
const LEGACY_SHORT = 'xnetvn-ui-network';
const LEGACY_MARK = /^(?:[-*]\s*)?Legacy note:/i;
const NOT_FOR_USE = /(do not use|must not be used|never use|not to be used|do not adopt|deprecated)/i;

function legacyMentions(source) {
  for (const line of source.split('\n')) {
    if (!line.includes(LEGACY_SHORT)) continue;
    assert.match(line, LEGACY_MARK, 'Legacy namespace name must stay on a marked "Legacy note:" line');
    assert.match(line, NOT_FOR_USE, 'Legacy note must state that the legacy namespace is not to be used');
  }
}

function guidance(instructions, runbook, scenarios) {
  for (const source of [instructions, runbook]) {
    assert.ok(source.includes('`xnetvn-agent-hub-wp`'), 'Main namespace must be explicit');
    assert.doesNotMatch(source, /--name\b/, 'Do not override the registered namespace');
    assert.doesNotMatch(source, /xnetvn-agent-hub-wp-ui-network/, 'Obsolete namespace allowlist');
    legacyMentions(source);
  }
  const commands = runbook.split('\n').filter(line => line.startsWith('RTL_AGENT='));
  assert.ok(commands.length > 10, 'Runbook commands missing');
  for (const command of commands) {
    assert.ok(command.includes('python3 -I -B') && command.includes('rtl.py" '));
    if (/rtl.py" up\b/.test(command)) assert.doesNotMatch(command, /--site\b/);
    if (/rtl.py" down\b/.test(command)) assert.doesNotMatch(command, /--volumes|--purge/);
  }
  const networkDown = commands.findIndex(line => /down --compose-file compose.ui-network.yaml$/.test(line));
  const rollback = commands.slice(networkDown + 1);
  const plan = rollback.findIndex(line => /plan --compose-file compose.yaml --site wp69:80 --site wp71:80$/.test(line));
  const up = rollback.findIndex(line => /up --compose-file compose.yaml$/.test(line));
  assert.ok(networkDown >= 0 && plan >= 0 && up > plan, 'Rollback must review a complete single-site plan before up');
  assert.match(runbook, /lease[^\n]*(?:STOP|stop)/, 'Missing live lease stop gate');
  assert.match(runbook, /unknown[^\n]*(?:STOP|stop|reconcile)/, 'Missing uncertain mutation gate');
  assert.match(runbook, /domain[^\n]*(?:STOP|stop)/, 'Missing domain stop gate');
  for (const token of ['2 GB', '15 GB', '5 GB', '3 projects', 'sync --compose-file compose.ui-network.yaml --dry-run']) assert.ok(runbook.includes(token), `Missing gate: ${token}`);
  const cases = new Map(scenarios.cases.map(c => [c.id, c]));
  for (const id of ['I-04', 'J-07']) {
    const c = cases.get(id);
    assert.ok(c && JSON.stringify(c).includes('xnetvn-agent-hub-wp'));
    assert.doesNotMatch(JSON.stringify(c), /two approved namespaces|approved namespaces are/);
    assert.ok(c.forbidden_actions.some(s => /namespace/i.test(s)));
  }
  for (const id of ['J-13', 'J-14', 'J-15']) assert.ok(cases.has(id), `Missing safety scenario: ${id}`);
  assert.match(JSON.stringify(cases.get('J-14')), /xnetvn-ui-network/);
  assert.match(JSON.stringify(cases.get('J-15').expected_actions), /lease/i);
  assert.match(JSON.stringify(cases.get('J-15').expected_actions), /volume/i);
}

// Anchor the environment host allowlist guard to the resolved home host and
// to the deny action that follows it. A loose "any in_array(..., true)" match
// accepts a file whose real guard was removed or loosened while an unrelated
// strict comparison (for example the fixture scenario check) stays in place.
function hostGuard(source, hosts, deny) {
  const needle = `in_array( $xnetvn_agent_hub_wp_net_home, $${hosts}, true )`;
  const lines = source.split(/\r?\n/);
  const index = lines.findIndex(line => line.includes(needle));
  assert.ok(index >= 0, `Missing strict host allowlist guard: ${needle}`);
  assert.match(lines[index], /^\s*if \(.*! in_array\( \$xnetvn_agent_hub_wp_net_home, \$\w+, true \)/,
    `Host allowlist comparison must be negated inside a guard: ${needle}`);
  const body = lines.slice(index + 1)
    .map(line => line.trim())
    .filter(line => line !== '' && !line.startsWith('//'));
  assert.ok(body.length > 0, `Host allowlist guard denies nothing: ${needle}`);
  assert.match(body[0], deny, `Host allowlist guard must deny with ${deny}`);
}

function guards(sources) {
  const ui = sources.ui;
  const fixture = sources.fixture;
  const runner = sources.runner;
  const browser = sources.browser;
  for (const source of [ui, fixture]) {
    assert.ok(source.includes('! is_multisite()') && source.includes('PHP_URL_HOST'));
    for (const key of ['XNETVN_RTL_DOMAIN', 'RTL_DOMAIN_WPNETWORK', 'RTL_ALIAS_WPNETWORK']) assert.ok(source.includes(key));
  }
  hostGuard(ui, 'xnetvn_agent_hub_wp_net_hosts', /^throw new RuntimeException\(/);
  hostGuard(fixture, 'xnetvn_agent_hub_wp_net_allowed_hosts', /^exit\( 2 \);$/);
  assert.ok(fixture.includes("'ui_network_' !== $wpdb->base_prefix"));
  assert.ok(runner.includes('${RTL_DOMAIN_WPNETWORK:?') && runner.includes('${RTL_ALIAS_WPNETWORK:?'));
  assert.ok(browser.includes('!allowedHosts.includes(new URL(process.env.BASE_URL).hostname)'));
  assert.ok(browser.includes("'RTL_DOMAIN_WPNETWORK', 'RTL_ALIAS_WPNETWORK'"));
}

function main() {
  const single = read('compose.yaml'); const network = read('compose.ui-network.yaml');
  const instructions = read('.agents/instructions/mcp-test-debug.instructions.md');
  const runbook = read('.agents/skills/xnetvn-mcp-testing/references/runbook.md');
  const scenarios = JSON.parse(read('.agents/evals/test-debug-scenarios.json'));
  isolation(single, network);
  // Pure-input mutants prove that declarations alone do not establish isolation.
  for (const mutant of [
    network.replace('ui_network_db_data:/var/lib/mysql', 'db_data:/var/lib/mysql'),
    network.replace('  ui_network_db_data:', '  ui_network_db_data:\n    name: db_data'),
    network.replace('  ui_network_db_data:', '  ui_network_db_data:\n    external: true'),
    network.replace('ui_network_vendor_data:/work/vendor', 'ui_network_wp_data:/work/vendor'),
  ]) { assert.notEqual(mutant, network); assert.throws(() => isolation(single, mutant)); }
  guidance(instructions, runbook, scenarios);
  assert.throws(() => guidance(instructions, runbook + '\nRTL_AGENT=codex python3 -I -B "rtl.py" up --name xnetvn-ui-network\n', scenarios));
  assert.throws(() => guidance(instructions, runbook.replace(/[^\n]*lease[^\n]*/gi, ''), scenarios));
  // Pure-input mutants: a legacy namespace name turned into an active
  // instruction must be rejected, not only the long obsolete name.
  const guidanceSources = [instructions, runbook];
  for (let index = 0; index < guidanceSources.length; index += 1) {
    const legacyLine = guidanceSources[index].split('\n').find(line => line.includes(LEGACY_SHORT));
    if (!legacyLine) continue;
    const mutated = guidanceSources[index].replace(legacyLine, `Use \`${LEGACY_SHORT}\` for the network stack.`);
    assert.notEqual(mutated, guidanceSources[index], 'Legacy note line was not replaced');
    const pair = guidanceSources.slice();
    pair[index] = mutated;
    assert.throws(() => guidance(pair[0], pair[1], scenarios), 'Active legacy namespace name must be rejected');
  }
  const guardSources = {
    ui: read('tests/E2E/ui-network-fixture.php'),
    fixture: read('tests/E2E/role-seo-network-fixture.php'),
    runner: read('infra/remote/run-role-seo-network.sh'),
    browser: read('tests/E2E/playwright.remote.config.js'),
  };
  guards(guardSources);
  // Pure-input mutants: a removed, loosened or non-strict host allowlist
  // guard must be rejected in both fixtures.
  for (const mutant of [
    { file: 'ui', text: guardSources.ui.replace(
      'in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_hosts, true )',
      'in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_hosts )') },
    { file: 'ui', text: guardSources.ui.replace(
      /if \( ! in_array\( \$xnetvn_agent_hub_wp_net_home, \$xnetvn_agent_hub_wp_net_hosts, true \) \) \{[^}]*\}/,
      '') },
    { file: 'ui', text: guardSources.ui.replace(
      'if ( ! in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_hosts, true ) ) {',
      'if ( false ) {') },
    { file: 'fixture', text: guardSources.fixture.replace(
      'in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_allowed_hosts, true )',
      'in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_allowed_hosts )') },
    { file: 'fixture', text: guardSources.fixture.replace(
      /if \( ! is_string\( \$xnetvn_agent_hub_wp_net_home \) \|\| ! in_array\( \$xnetvn_agent_hub_wp_net_home, \$xnetvn_agent_hub_wp_net_allowed_hosts, true \) \) \{[^}]*\}/,
      '') },
    { file: 'fixture', text: guardSources.fixture.replace(
      'if ( ! is_string( $xnetvn_agent_hub_wp_net_home ) || ! in_array( $xnetvn_agent_hub_wp_net_home, $xnetvn_agent_hub_wp_net_allowed_hosts, true ) ) {',
      'if ( false ) {') },
  ]) {
    assert.notEqual(mutant.text, guardSources[mutant.file], 'Host guard mutant did not change the fixture source');
    const changed = { ...guardSources, [mutant.file]: mutant.text };
    assert.throws(() => guards(changed), 'Host allowlist guard mutant must be rejected');
  }
  process.stdout.write('RTL single namespace: OK\n');
}
main();
