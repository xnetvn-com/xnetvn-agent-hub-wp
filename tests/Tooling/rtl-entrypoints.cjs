// Validate remote entrypoints use the installed Remote Test Lab skill path.
// Reads manifests and wrappers only; never executes the commands it inspects.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..');
const OLD_SKILL_DIR = 'skills/remote-test-lab/';
const SKILL_REL = '.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py';

function readFile(rel) {
  return fs.readFileSync(path.join(ROOT, rel), 'utf8');
}

function main() {
  const packageJsonRaw = readFile('package.json');
  const composerJsonRaw = readFile('composer.json');
  const integrationWrapper = readFile('bin/test-integration.sh');
  const networkWrapper = readFile('bin/test-network.sh');

  const sources = {
    'package.json': packageJsonRaw,
    'composer.json': composerJsonRaw,
    'bin/test-integration.sh': integrationWrapper,
    'bin/test-network.sh': networkWrapper,
  };

  // Root cause: every remote entrypoint must reference the renamed skill directory.
  for (const [name, content] of Object.entries(sources)) {
    assert.equal(
      content.includes(OLD_SKILL_DIR),
      false,
      `${name} still references the old skill path skills/remote-test-lab/`,
    );
  }

  const packageJson = JSON.parse(packageJsonRaw);
  const composerJson = JSON.parse(composerJsonRaw);

  const npmScripts = ['env:start', 'env:stop', 'remote:plan', 'remote:up', 'test:e2e'];
  for (const scriptName of npmScripts) {
    const command = packageJson.scripts && packageJson.scripts[scriptName];
    assert.equal(typeof command, 'string', `package.json script ${scriptName} is missing`);
    assert.ok(command.includes(SKILL_REL), `package.json script ${scriptName} must use ${SKILL_REL}`);
    assert.ok(command.includes('python3 -I -B'), `package.json script ${scriptName} must keep python3 -I -B`);
  }
  // Preserve distinctive arguments for each remote entrypoint.
  assert.ok(packageJson.scripts['remote:plan'].includes('--compose-file compose.yaml'), 'remote:plan must keep --compose-file compose.yaml');
  assert.ok(packageJson.scripts['remote:plan'].includes('--site wp69:80'), 'remote:plan must keep --site wp69:80');
  assert.ok(packageJson.scripts['test:e2e'].includes('npm ci --ignore-scripts'), 'test:e2e must keep npm ci --ignore-scripts');

  const testHttp = composerJson.scripts && composerJson.scripts['test:http'];
  assert.equal(typeof testHttp, 'string', 'composer.json script test:http is missing');
  assert.ok(testHttp.includes(SKILL_REL), `composer.json script test:http must use ${SKILL_REL}`);
  assert.ok(testHttp.includes('python3 -I -B'), 'composer.json script test:http must keep python3 -I -B');
  assert.ok(testHttp.includes('run-http-matrix.sh'), 'composer.json script test:http must keep run-http-matrix.sh');

  for (const [name, content] of [['bin/test-integration.sh', integrationWrapper], ['bin/test-network.sh', networkWrapper]]) {
    assert.ok(content.includes(SKILL_REL), `${name} must use ${SKILL_REL}`);
    assert.ok(content.includes('python3 -I -B'), `${name} must keep python3 -I -B`);
    assert.ok(content.includes('"$@"'), `${name} must forward argv via "$@"`);
    assert.ok(!content.includes('docker') || content.includes('rtl.py'), `${name} must not add a local Docker fallback`);
  }
  assert.ok(integrationWrapper.includes('run-integration.sh'), 'bin/test-integration.sh must keep run-integration.sh');
  assert.ok(networkWrapper.includes('run-network.sh'), 'bin/test-network.sh must keep run-network.sh');

  // Confirm the installed entrypoint exists; HOME is only used for this existence check.
  const home = process.env.HOME || process.env.USERPROFILE || '';
  assert.notEqual(home, '', 'HOME must be set to locate the installed skill');
  const installed = path.join(home, SKILL_REL);
  assert.equal(fs.existsSync(installed), true, `installed skill entrypoint is missing: ${installed}`);

  process.stdout.write('RTL entrypoints: OK\n');
}

main();
