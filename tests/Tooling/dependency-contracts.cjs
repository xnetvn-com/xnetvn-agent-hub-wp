// Exercise the APIs wp-env actually uses without starting containers or network services.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createRequire } = require('node:module');
const wpEnvRequire = createRequire(require.resolve('@wordpress/env/package.json'));
const yaml = wpEnvRequire('js-yaml');
const { simpleGit } = wpEnvRequire('simple-git');

let phase = "yaml";
async function main() {
  const compose = { services: { wordpress: { image: 'wordpress:6.9', ports: ['127.0.0.1:20880:80'], environment: { WORDPRESS_DEBUG: '1' } } } };
  assert.deepEqual(yaml.load(yaml.dump(compose)), compose);
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'xnetvn-dependency-contract-'));
  const source = path.join(directory, 'source');
  const destination = path.join(directory, 'clone');
  const hooks = path.join(directory, 'empty-hooks');
  await fs.mkdir(source);
  await fs.mkdir(hooks);
  // No caller GIT_*, editor, hook, HOME or XDG overrides reach either Git execution path.
  // With no home/config directory and system config disabled, Git uses only fixture-local config.
  const gitEnv = { PATH: process.env.PATH, GIT_CONFIG_NOSYSTEM: '1' };
  const git = (args) => execFileSync('git', args, { env: gitEnv, timeout: 15000, stdio: 'pipe' });
  try {
    phase = 'fixture-init';
    git(['init', '--initial-branch=main', source]);
    await fs.writeFile(path.join(source, 'fixture.txt'), 'Fixture only.\n');
    git(['-C', source, 'add', 'fixture.txt']);
    git(['-C', source, '-c', 'core.hooksPath=' + hooks, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-m', 'Fixture']);
    phase = 'client-construction';
    const client = simpleGit({
      progress: () => {}, timeout: { block: 15000 },
      // Allow only this hardcoded config-disabling flag; unsafe operation allowances stay off.
      allowEnvironment: ['GIT_CONFIG_NOSYSTEM'],
    }).env(gitEnv);
    phase = 'clone';
    await client.clone(source, destination, { '--depth': '1', '--filter': 'blob:none', '--no-single-branch': null });
    phase = 'cwd';
    await client.cwd(destination);
    assert.equal(await client.checkIsRepo('root'), true);
    phase = 'fetch';
    await client.fetch('origin', 'main', { '--tags': null });
    await client.checkout('main');
    await fs.writeFile(path.join(destination, 'fixture.txt'), 'Disposable edit.\n');
    phase = 'reset';
    await client.reset(['--hard', 'FETCH_HEAD']);
    assert.equal(await fs.readFile(path.join(destination, 'fixture.txt'), 'utf8'), 'Fixture only.\n');
    phase = 'sparse-checkout';
    await client.raw('sparse-checkout', 'set', '--cone', 'tests/phpunit');
    assert.equal(typeof await client.raw(['rev-parse', 'HEAD']), 'string');
    process.stdout.write('wp-env YAML and Git API contracts passed.\n');
  } finally {
    // Delete only the fresh directory returned by mkdtemp; no workspace or reference data is touched.
    await fs.rm(directory, { recursive: true, force: true });
  }
}
main().catch((error) => { process.stderr.write(`wp-env dependency contract failed: phase=${phase} type=${error.constructor.name}.\n`); process.exitCode = 1; });
