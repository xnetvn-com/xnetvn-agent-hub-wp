// Exercise the final coverage job's real prerequisite guard without services.
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createRequire } = require('node:module');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const wpEnvRequire = createRequire(require.resolve('@wordpress/env/package.json'));
const yaml = wpEnvRequire('js-yaml');
const workflow = yaml.load(fs.readFileSync(path.join(root, '.github/workflows/coverage-check.yml'), 'utf8'));
const gate = workflow.jobs.coverage;

assert.equal(gate.if, 'always()', 'The final required check must run after failed or skipped prerequisites.');
assert.deepEqual([...gate.needs].sort(), ['helper-tests', 'integration', 'plan', 'unit']);
const guard = gate.steps.find((step) => step.name === 'Require successful collection jobs');
assert.ok(guard && typeof guard.run === 'string', 'The final gate must explicitly reject unsuccessful prerequisites.');
assert.equal(gate.steps[0], guard, 'Reject failed prerequisites before reading any artifacts.');
assert.deepEqual(guard.env, {
  PLAN_RESULT: '${{ needs.plan.result }}',
  HELPER_RESULT: '${{ needs.helper-tests.result }}',
  UNIT_RESULT: '${{ needs.unit.result }}',
  INTEGRATION_RESULT: '${{ needs.integration.result }}',
  DOCS_ONLY: '${{ needs.plan.outputs.docs_only }}'
});
assert.equal(
  workflow.jobs.plan.outputs?.docs_only,
  '${{ steps.select.outputs.docs_only }}',
  'The coverage plan job must expose the documentation-only scope.'
);
for (const id of ['helper-tests', 'unit', 'integration']) {
  assert.equal(
    workflow.jobs[id].if,
    "needs.plan.outputs.docs_only != 'true'",
    `coverage-check.yml: ${id} must stay behind the documentation-only scope`
  );
}

const states = ['success', 'failure', 'cancelled', 'skipped'];
let cases = 0;
const runGuard = (values) =>
  spawnSync('bash', ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', guard.run], {
    cwd: root,
    env: { PATH: process.env.PATH, ...values },
    encoding: 'utf8',
    timeout: 5000
  });
for (const helper of states) {
  for (const unit of states) {
    for (const integration of states) {
      const result = runGuard({
        PLAN_RESULT: 'success',
        DOCS_ONLY: 'false',
        HELPER_RESULT: helper,
        UNIT_RESULT: unit,
        INTEGRATION_RESULT: integration
      });
      assert.ifError(result.error);
      const expected = helper === 'success' && unit === 'success' && integration === 'success' ? 0 : 1;
      assert.equal(result.status, expected, `Prerequisite outcome ${helper}/${unit}/${integration} must be handled safely.`);
      cases += 1;
    }
  }
}
const docsOnlyCases = [
  [{ HELPER_RESULT: 'skipped', UNIT_RESULT: 'skipped', INTEGRATION_RESULT: 'skipped' }, 0],
  [{ HELPER_RESULT: 'success', UNIT_RESULT: 'success', INTEGRATION_RESULT: 'success' }, 0],
  [{ HELPER_RESULT: 'failure', UNIT_RESULT: 'skipped', INTEGRATION_RESULT: 'skipped' }, 1],
  [{ HELPER_RESULT: 'cancelled', UNIT_RESULT: 'skipped', INTEGRATION_RESULT: 'skipped' }, 1]
];
for (const [values, expected] of docsOnlyCases) {
  const result = runGuard({ PLAN_RESULT: 'success', DOCS_ONLY: 'true', ...values });
  assert.ifError(result.error);
  assert.equal(
    result.status,
    expected,
    `A documentation-only run must handle ${JSON.stringify(values)} safely.`
  );
  cases += 1;
}
assert.equal(
  runGuard({
    PLAN_RESULT: 'failure',
    DOCS_ONLY: 'false',
    HELPER_RESULT: 'success',
    UNIT_RESULT: 'success',
    INTEGRATION_RESULT: 'success'
  }).status,
  1,
  'A failed plan must fail the coverage gate.'
);
assert.equal(
  runGuard({
    PLAN_RESULT: 'success',
    DOCS_ONLY: '',
    HELPER_RESULT: 'skipped',
    UNIT_RESULT: 'skipped',
    INTEGRATION_RESULT: 'skipped'
  }).status,
  1,
  'An unknown scope must not accept a skipped coverage job.'
);
assert.equal(
  runGuard({
    PLAN_RESULT: 'success',
    DOCS_ONLY: 'false',
    HELPER_RESULT: 'skipped',
    UNIT_RESULT: 'success',
    INTEGRATION_RESULT: 'success'
  }).status,
  1,
  'A source run must reject a skipped coverage job.'
);
cases += 3;

// The coverage matrix, its artifact names and the gate's fixed suite roster
// must move together; a renamed leg must not leave the gate reading a stale
// artifact or an unknown suite.
const coverageIntegration = workflow.jobs.integration;
const coverageMatrix = coverageIntegration.strategy.matrix;
const expectedArtifacts = new Set(['native-coverage-unit']);
for (const wp of coverageMatrix.wp) {
  for (const mode of coverageMatrix.mode) {
    expectedArtifacts.add(`native-coverage-integration-${wp}-${mode}`);
  }
}
const downloads = gate.steps.filter(
  (step) => step.uses && step.uses.startsWith('actions/download-artifact@')
);
const downloadedNames = downloads.map((step) => step.with?.name);
assert.equal(downloads.length, expectedArtifacts.size, 'Every coverage producer needs exactly one download step.');
assert.equal(new Set(downloadedNames).size, downloadedNames.length, 'Coverage artifact names must be unique.');
for (const name of expectedArtifacts) {
  assert.ok(downloadedNames.includes(name), `The coverage gate must download ${name}.`);
}
const thresholdStep = gate.steps.find(
  (step) => step.name === 'Enforce fresh complete-source thresholds and no regression'
);
assert.ok(thresholdStep, 'The numeric coverage gate step is missing.');
for (const wp of coverageMatrix.wp) {
  for (const mode of coverageMatrix.mode) {
    assert.ok(
      thresholdStep.run.includes(`.local/ci/input/${wp}-${mode}/coverage.json`),
      `The coverage gate must read the ${wp}/${mode} report.`
    );
  }
}
const gatePhp = fs.readFileSync(path.join(root, '.github/scripts/CoverageGate.php'), 'utf8');
const suiteDeclaration = gatePhp.match(/private const SUITES\s*=\s*array\(([^)]*)\);/);
assert.ok(suiteDeclaration, 'CoverageGate.php must declare its suite roster.');
const suiteNames = [...suiteDeclaration[1].matchAll(/'([^']+)'/g)].map((match) => match[1]).sort();
const expectedSuites = ['unit'];
for (const wp of coverageMatrix.wp) {
  for (const mode of coverageMatrix.mode) {
    expectedSuites.push(`integration-${wp}-${mode}`);
  }
}
assert.deepEqual(
  suiteNames,
  expectedSuites.sort(),
  'CoverageGate.php suite names must match the coverage workflow matrix.'
);

// The always-reporting lint aggregate may skip its costly job only when change
// detection succeeded and explicitly reported that no automation file changed.
const lintWorkflow = yaml.load(
  fs.readFileSync(path.join(root, '.github/workflows/workflow-lint.yml'), 'utf8')
);
const lintGuard = lintWorkflow.jobs['workflow-lint'].steps.find(
  (step) => step.env && step.env.AUTOMATION !== undefined
);
assert.ok(lintGuard, 'The workflow-lint aggregate guard is missing.');
const lintCases = [
  ['success', 'skipped', 'false', 0],
  ['success', 'success', 'true', 0],
  ['success', 'skipped', 'true', 1],
  ['success', 'success', 'false', 1],
  ['failure', 'skipped', 'false', 1],
  ['success', 'failure', 'true', 1],
  ['success', 'skipped', '', 1],
  ['success', 'success', '', 1],
  ['cancelled', 'cancelled', '', 1]
];
for (const [changes, lint, automation, expected] of lintCases) {
  const result = spawnSync('bash', ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', lintGuard.run], {
    cwd: root,
    env: {
      PATH: process.env.PATH,
      CHANGES_RESULT: changes,
      LINT_RESULT: lint,
      AUTOMATION: automation
    },
    encoding: 'utf8',
    timeout: 5000
  });
  assert.ifError(result.error);
  assert.equal(
    result.status,
    expected,
    `Workflow-lint outcome ${changes}/${lint}/${automation} must be handled safely.`
  );
  cases += 1;
}

// Exercise the real change-detection script against a disposable repository.
const lintChanges = lintWorkflow.jobs.changes.steps.find((step) => step.id === 'diff');
assert.ok(lintChanges, 'The workflow-lint change detection step is missing.');
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-diff-'));
const git = (repo, ...args) => {
  const result = spawnSync('git', args, {
    cwd: repo,
    env: {
      ...process.env,
      GIT_AUTHOR_NAME: 'CI Contract',
      GIT_AUTHOR_EMAIL: 'ci@example.invalid',
      GIT_COMMITTER_NAME: 'CI Contract',
      GIT_COMMITTER_EMAIL: 'ci@example.invalid'
    },
    encoding: 'utf8',
    timeout: 10000
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `git ${args.join(' ')} failed: ${result.stderr}`);
  return result.stdout.trim();
};
const detect = (repo, event, values = {}) => {
  const outputPath = path.join(repo, 'github-output.txt');
  fs.writeFileSync(outputPath, '');
  const result = spawnSync('bash', ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', lintChanges.run], {
    cwd: repo,
    env: {
      ...process.env,
      GITHUB_OUTPUT: outputPath,
      EVENT_NAME: event,
      BASE_SHA: '',
      BEFORE_SHA: '',
      ...values
    },
    encoding: 'utf8',
    timeout: 10000
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `Change detection failed: ${result.stderr}`);
  const match = fs.readFileSync(outputPath, 'utf8').match(/^automation=(true|false)$/m);
  assert.ok(match, 'Change detection must write an automation result.');
  return match[1];
};
try {
  git(fixture, 'init', '-q');
  git(fixture, 'config', 'commit.gpgsign', 'false');
  git(fixture, 'config', 'tag.gpgsign', 'false');
  git(fixture, 'config', 'core.hooksPath', '/dev/null');
  fs.mkdirSync(path.join(fixture, '.github/workflows'), { recursive: true });
  fs.writeFileSync(path.join(fixture, 'README.md'), 'one\n');
  fs.writeFileSync(path.join(fixture, '.github/workflows/ci.yml'), 'name: CI\n');
  fs.writeFileSync(path.join(fixture, '.github/settings.yml'), 'repository:\n  name: fixture\n');
  git(fixture, 'add', '-A');
  git(fixture, 'commit', '-qm', 'base');
  const base = git(fixture, 'rev-parse', 'HEAD');

  fs.writeFileSync(path.join(fixture, 'README.md'), 'two\n');
  git(fixture, 'commit', '-qam', 'docs');
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: base }),
    'false',
    'A documentation-only pull request must not run the costly lint.'
  );

  git(fixture, 'reset', '-q', '--hard', base);
  fs.appendFileSync(path.join(fixture, '.github/workflows/ci.yml'), '# change\n');
  git(fixture, 'commit', '-qam', 'workflow');
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: base }),
    'true',
    'A workflow edit must run the costly lint.'
  );

  git(fixture, 'reset', '-q', '--hard', base);
  fs.appendFileSync(path.join(fixture, '.github/settings.yml'), '# change\n');
  git(fixture, 'commit', '-qam', 'settings');
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: base }),
    'true',
    'A repository automation configuration edit must run the costly lint.'
  );

  git(fixture, 'reset', '-q', '--hard', base);
  fs.mkdirSync(path.join(fixture, 'docs'), { recursive: true });
  git(fixture, 'mv', '.github/workflows/ci.yml', 'docs/ci.yml');
  git(fixture, 'commit', '-qm', 'rename');
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: base }),
    'true',
    'Renaming a workflow away from its directory must run the costly lint.'
  );

  git(fixture, 'reset', '-q', '--hard', base);
  git(fixture, 'rm', '-q', '.github/workflows/ci.yml');
  git(fixture, 'commit', '-qm', 'delete');
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: base }),
    'true',
    'Deleting a workflow must run the costly lint.'
  );

  git(fixture, 'reset', '-q', '--hard', base);
  assert.equal(
    detect(fixture, 'pull_request', { BASE_SHA: 'f'.repeat(40) }),
    'true',
    'An unavailable base revision must run the costly lint.'
  );
  assert.equal(
    detect(fixture, 'push', { BEFORE_SHA: '0'.repeat(40) }),
    'true',
    'A zero before-SHA must run the costly lint.'
  );
  assert.equal(
    detect(fixture, 'workflow_dispatch'),
    'true',
    'A manual run must always lint.'
  );

  fs.writeFileSync(path.join(fixture, 'README.md'), 'three\n');
  git(fixture, 'commit', '-qam', 'docs2');
  assert.equal(
    detect(fixture, 'push', { BEFORE_SHA: base }),
    'false',
    'A documentation-only push must not run the costly lint.'
  );
} finally {
  fs.rmSync(fixture, { recursive: true, force: true });
}

// The CI plan job selects the pull-request scope and the integration matrix.
const ciWorkflow = yaml.load(
  fs.readFileSync(path.join(root, '.github/workflows/ci.yml'), 'utf8')
);
const planStep = (ciWorkflow.jobs.plan.steps || []).find(
  (step) => step.id === 'select'
);
assert.ok(
  planStep && typeof planStep.run === 'string',
  'ci.yml: the plan step that selects the matrix is missing.'
);
const planFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-plan-'));
const gitPlan = (repo, ...args) => {
  const result = spawnSync('git', args, {
    cwd: repo,
    env: {
      ...process.env,
      GIT_AUTHOR_NAME: 'CI Contract',
      GIT_AUTHOR_EMAIL: 'ci@example.invalid',
      GIT_COMMITTER_NAME: 'CI Contract',
      GIT_COMMITTER_EMAIL: 'ci@example.invalid',
    },
    encoding: 'utf8',
    timeout: 10000,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `git ${args.join(' ')} failed: ${result.stderr}`);
  return result.stdout.trim();
};
const runPlanStep = (repo, step, event, base = '') => {
  const outputPath = path.join(repo, 'github-output.txt');
  fs.writeFileSync(outputPath, '');
  const result = spawnSync(
    'bash',
    ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', step.run],
    {
      cwd: repo,
      env: {
        ...process.env,
        GITHUB_OUTPUT: outputPath,
        EVENT_NAME: event,
        BASE_SHA: base,
      },
      encoding: 'utf8',
      timeout: 10000,
    }
  );
  assert.ifError(result.error);
  assert.equal(result.status, 0, `Plan selection failed: ${result.stderr}`);
  const text = fs.readFileSync(outputPath, 'utf8');
  const docs = text.match(/^docs_only=(true|false)$/m);
  const include = text.match(/^include=(.+)$/m);
  assert.ok(docs, 'The plan step must write docs_only.');
  return {
    docsOnly: docs[1] === 'true',
    legs: include ? JSON.parse(include[1]) : null,
  };
};
const runPlan = (repo, event, base = '') =>
  runPlanStep(repo, planStep, event, base);
const coverageWorkflow = yaml.load(
  fs.readFileSync(path.join(root, '.github/workflows/coverage-check.yml'), 'utf8')
);
const coveragePlanStep = (coverageWorkflow.jobs.plan.steps || []).find(
  (step) => step.id === 'select'
);
assert.ok(
  coveragePlanStep && typeof coveragePlanStep.run === 'string',
  'coverage-check.yml: the plan step that selects the scope is missing.'
);
const caseArm = (run) => {
  const match = String(run).match(/case "\$file" in\s*\n\s*([^\n)]+)\)/);
  return match ? match[1].trim() : null;
};
const sourceCaseArm = caseArm(planStep.run);
const coverageCaseArm = caseArm(coveragePlanStep.run);
assert.ok(sourceCaseArm, 'ci.yml: the documentation allowlist case arm is missing.');
assert.ok(
  coverageCaseArm,
  'coverage-check.yml: the documentation allowlist case arm is missing.'
);
assert.equal(
  coverageCaseArm,
  sourceCaseArm,
  'The coverage and source selectors must share the same complete allowlist.'
);
try {
  gitPlan(planFixture, 'init', '-q');
  gitPlan(planFixture, 'config', 'commit.gpgsign', 'false');
  gitPlan(planFixture, 'config', 'tag.gpgsign', 'false');
  gitPlan(planFixture, 'config', 'core.hooksPath', '/dev/null');
  fs.mkdirSync(path.join(planFixture, 'docs'), { recursive: true });
  fs.mkdirSync(path.join(planFixture, 'src'), { recursive: true });
  fs.writeFileSync(path.join(planFixture, 'README.md'), 'one\n');
  fs.writeFileSync(path.join(planFixture, 'docs/guide.md'), 'one\n');
  fs.writeFileSync(path.join(planFixture, 'src/Plugin.php'), '<?php\n');
  fs.writeFileSync(path.join(planFixture, 'readme.txt'), 'Stable tag: 1.0.0\n');
  gitPlan(planFixture, 'add', '-A');
  gitPlan(planFixture, 'commit', '-qm', 'base');
  const planBase = gitPlan(planFixture, 'rev-parse', 'HEAD');

  fs.writeFileSync(path.join(planFixture, 'docs/guide.md'), 'two\n');
  gitPlan(planFixture, 'commit', '-qam', 'docs');
  let plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(plan.docsOnly, true, 'A documentation commit must be docs-only.');
  assert.equal(plan.legs.length, 10, 'A pull request must keep the ten boundary legs.');
  assert.deepEqual(
    plan.legs
      .map((leg) => [leg.php, leg.wp, leg.adapter, leg.mode].join('/'))
      .sort(),
    [
      '8.2/6.9/0.7.0/single',
      '8.2/6.9/latest/single',
      '8.2/6.9/0.7.0/multisite',
      '8.2/latest/0.7.0/single',
      '8.2/latest/latest/single',
      '8.4/6.9/0.7.0/single',
      '8.4/6.9/latest/single',
      '8.4/latest/0.7.0/single',
      '8.4/latest/latest/single',
      '8.4/latest/latest/multisite',
    ].sort(),
    'The pull-request matrix must keep the exact ten boundary tuples.'
  );
  assert.equal(
    plan.legs.some((leg) => leg.php === '8.3'),
    false,
    'A pull request must leave PHP 8.3 to the weekly full matrix.'
  );
  assert.equal(
    plan.legs.some((leg) => leg.experimental === true),
    false,
    'A pull request must leave experimental legs to the weekly full matrix.'
  );
  assert.ok(
    plan.legs.some(
      (leg) =>
        leg.php === '8.2' &&
        leg.wp === '6.9' &&
        leg.adapter === '0.7.0' &&
        leg.mode === 'multisite'
    ),
    'The pull-request matrix must keep the minimum multisite boundary leg.'
  );
  assert.ok(
    plan.legs.some(
      (leg) =>
        leg.php === '8.4' &&
        leg.wp === 'latest' &&
        leg.adapter === 'latest' &&
        leg.mode === 'multisite'
    ),
    'The pull-request matrix must keep a newest-runtime multisite leg.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'src/Plugin.php'), '<?php // change\n');
  gitPlan(planFixture, 'commit', '-qam', 'source');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(plan.docsOnly, false, 'A source change must run the full gate.');

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'readme.txt'), 'Stable tag: 1.0.1\n');
  gitPlan(planFixture, 'commit', '-qam', 'readme');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    false,
    'readme.txt enters the release package and must not be docs-only.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'CHANGELOG.md'), '# Changelog\n\ndocs entry\n');
  gitPlan(planFixture, 'add', 'CHANGELOG.md');
  gitPlan(planFixture, 'commit', '-qm', 'changelog');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    true,
    'A changelog-only change must take the documentation fast path.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  gitPlan(planFixture, 'mv', 'src/Plugin.php', 'docs/plugin.md');
  gitPlan(planFixture, 'commit', '-qm', 'rename');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    false,
    'A source-to-docs rename must not be read as documentation-only.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  gitPlan(planFixture, 'rm', '-q', 'src/Plugin.php');
  gitPlan(planFixture, 'commit', '-qm', 'delete');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    false,
    'A deleted source file must run the full gate.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    false,
    'An empty diff must run the full gate.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'src/Plugin.php'), '<?php // mixed\n');
  fs.appendFileSync(path.join(planFixture, 'docs/guide.md'), 'mixed\n');
  gitPlan(planFixture, 'commit', '-qam', 'mixed');
  plan = runPlan(planFixture, 'pull_request', planBase);
  assert.equal(
    plan.docsOnly,
    false,
    'A source change mixed with documentation must run the full gate.'
  );

  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  plan = runPlan(planFixture, 'pull_request', 'f'.repeat(40));
  assert.equal(
    plan.docsOnly,
    false,
    'An unavailable base revision must run the full gate.'
  );

  plan = runPlan(planFixture, 'push');
  assert.equal(plan.docsOnly, false, 'A default-branch push must run the full gate.');
  assert.equal(plan.legs.length, 16, 'A default-branch push must keep sixteen legs.');
  assert.deepEqual(
    plan.legs
      .map((leg) => [leg.php, leg.wp, leg.adapter, leg.mode].join('/'))
      .sort(),
    [
      '8.2/6.9/0.7.0/single',
      '8.2/6.9/latest/single',
      '8.2/6.9/0.7.0/multisite',
      '8.2/latest/0.7.0/single',
      '8.2/latest/latest/single',
      '8.3/6.9/0.7.0/single',
      '8.3/6.9/latest/single',
      '8.3/latest/0.7.0/single',
      '8.3/latest/latest/single',
      '8.4/6.9/0.7.0/single',
      '8.4/6.9/latest/single',
      '8.4/latest/0.7.0/single',
      '8.4/latest/latest/single',
      '8.4/latest/latest/multisite',
      '8.5/latest/latest/single',
      '8.4/trunk/latest/single',
    ].sort(),
    'The default-branch matrix must keep the exact sixteen tuples.'
  );
  assert.ok(
    plan.legs.some((leg) => leg.php === '8.3') &&
      plan.legs.some((leg) => leg.php === '8.5' && leg.experimental === true) &&
      plan.legs.some((leg) => leg.wp === 'trunk' && leg.experimental === true),
    'The default-branch matrix must keep the full coverage.'
  );

  // The coverage selector must agree with the source selector on the same
  // fixture: documentation-only takes the fast path, source and push do not.
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'docs/guide.md'), 'scope\n');
  gitPlan(planFixture, 'commit', '-qam', 'coverage docs');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    true,
    'The coverage selector must take the documentation fast path.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'src/Plugin.php'), '<?php // coverage source\n');
  gitPlan(planFixture, 'commit', '-qam', 'coverage source');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must run the collectors for a source change.'
  );
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'push').docsOnly,
    false,
    'The coverage selector must run the collectors on the default branch.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.writeFileSync(path.join(planFixture, 'readme.txt'), 'Stable tag: 1.0.1\n');
  gitPlan(planFixture, 'commit', '-qam', 'coverage readme');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must not treat readme.txt as documentation-only.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  fs.mkdirSync(path.join(planFixture, 'languages'), { recursive: true });
  fs.writeFileSync(path.join(planFixture, 'languages/x.pot'), 'msgid ""\n');
  gitPlan(planFixture, 'add', 'languages/x.pot');
  gitPlan(planFixture, 'commit', '-qm', 'coverage languages');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must not treat a translation template as documentation-only.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  gitPlan(planFixture, 'mv', 'src/Plugin.php', 'docs/plugin.md');
  gitPlan(planFixture, 'commit', '-qm', 'coverage rename');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must not treat a source-to-docs rename as documentation-only.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  gitPlan(planFixture, 'rm', '-q', 'src/Plugin.php');
  gitPlan(planFixture, 'commit', '-qm', 'coverage delete');
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must run the collectors for a deleted source file.'
  );
  gitPlan(planFixture, 'reset', '-q', '--hard', planBase);
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', 'f'.repeat(40)).docsOnly,
    false,
    'The coverage selector must run the collectors for an unavailable base.'
  );
  assert.equal(
    runPlanStep(planFixture, coveragePlanStep, 'pull_request', planBase).docsOnly,
    false,
    'The coverage selector must run the collectors for an empty diff.'
  );
} finally {
  fs.rmSync(planFixture, { recursive: true, force: true });
}

// The coverage baseline selector must read the reviewed path from the base
// revision and keep the legacy .ecc fallback only while older bases exist.
const baselineStep = (coverageWorkflow.jobs.coverage.steps || []).find(
  (step) => step.name === 'Read the baseline from the base revision'
);
assert.ok(
  baselineStep && typeof baselineStep.run === 'string',
  'coverage-check.yml: the baseline selection step is missing.'
);
const baselineFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'coverage-baseline-'));
const runBaseline = (repo, base) => {
  const result = spawnSync(
    'bash',
    ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', baselineStep.run],
    {
      cwd: repo,
      env: { ...process.env, BASE_REVISION: base },
      encoding: 'utf8',
      timeout: 10000,
    }
  );
  assert.ifError(result.error);
  return result;
};
const writeBaseline = (repo, relative, body) => {
  const target = path.join(repo, relative);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, body);
};
const commitBaseline = (repo, message) => {
  gitPlan(repo, 'add', '-A');
  gitPlan(repo, 'commit', '-qm', message);
  return gitPlan(repo, 'rev-parse', 'HEAD');
};
const readSelectedBaseline = (repo) =>
  fs.readFileSync(path.join(repo, '.local/ci/baseline.json'), 'utf8');
try {
  gitPlan(baselineFixture, 'init', '-q');
  gitPlan(baselineFixture, 'config', 'commit.gpgsign', 'false');
  gitPlan(baselineFixture, 'config', 'tag.gpgsign', 'false');
  gitPlan(baselineFixture, 'config', 'core.hooksPath', '/dev/null');
  gitPlan(baselineFixture, 'config', 'core.excludesFile', '/dev/null');
  writeBaseline(baselineFixture, '.ecc/coverage/M1-lines.json', '{"legacy":true}\n');
  const legacyBase = commitBaseline(baselineFixture, 'legacy baseline');
  gitPlan(baselineFixture, 'rm', '-r', '-q', '.ecc');
  writeBaseline(baselineFixture, '.github/coverage/M1-lines.json', '{"new":true}\n');
  const newBase = commitBaseline(baselineFixture, 'new baseline');
  writeBaseline(baselineFixture, '.ecc/coverage/M1-lines.json', '{"legacy":true}\n');
  const bothBase = commitBaseline(baselineFixture, 'both baselines');
  gitPlan(baselineFixture, 'rm', '-r', '-q', '.github/coverage', '.ecc');
  const noBase = commitBaseline(baselineFixture, 'no baseline');
  writeBaseline(baselineFixture, '.ecc/coverage/M1-lines.json', '{"legacy":true}\n');
  writeBaseline(baselineFixture, '.github/coverage/M1-lines.json', '{');
  const invalidNewBase = commitBaseline(baselineFixture, 'invalid new baseline');
  gitPlan(baselineFixture, 'update-ref', 'refs/remotes/origin/main', newBase);

  let selected = runBaseline(baselineFixture, legacyBase);
  assert.equal(selected.status, 0, selected.stderr);
  assert.equal(
    readSelectedBaseline(baselineFixture),
    '{"legacy":true}\n',
    'A legacy-only base must select the legacy baseline.'
  );
  selected = runBaseline(baselineFixture, newBase);
  assert.equal(selected.status, 0, selected.stderr);
  assert.equal(
    readSelectedBaseline(baselineFixture),
    '{"new":true}\n',
    'A new-path base must select the reviewed baseline.'
  );
  selected = runBaseline(baselineFixture, bothBase);
  assert.equal(selected.status, 0, selected.stderr);
  assert.equal(
    readSelectedBaseline(baselineFixture),
    '{"new":true}\n',
    'The reviewed path must win when both baselines exist.'
  );
  selected = runBaseline(baselineFixture, invalidNewBase);
  assert.equal(selected.status, 0, selected.stderr);
  assert.equal(
    readSelectedBaseline(baselineFixture),
    '{',
    'An invalid reviewed baseline must not fall back to the legacy file.'
  );
  selected = runBaseline(baselineFixture, noBase);
  assert.equal(selected.status, 2, 'A base with no baseline must fail closed.');
  assert.match(selected.stderr, /numeric coverage baseline is required/);
  selected = runBaseline(baselineFixture, 'f'.repeat(40));
  assert.equal(selected.status, 2, 'An absent base commit must fail closed.');
  selected = runBaseline(baselineFixture, 'not-a-sha');
  assert.equal(selected.status, 2, 'An invalid base revision must fail closed.');
  selected = runBaseline(baselineFixture, '0'.repeat(40));
  assert.equal(selected.status, 0, selected.stderr);
  assert.equal(
    readSelectedBaseline(baselineFixture),
    '{"new":true}\n',
    'An all-zero base must fall back to origin/main and select the reviewed path.'
  );
} finally {
  fs.rmSync(baselineFixture, { recursive: true, force: true });
}

// The Gitleaks policy helper must replace pull-request policy files with the
// base revision's files, and remove them when the base policy is unavailable.
const gitleaksScript = path.join(root, 'bin/prepare-gitleaks-policy.sh');
const policyFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-gitleaks-'));
const runPolicy = (repo, base, temp) => {
  const outputPath = path.join(repo, 'policy-output.txt');
  fs.writeFileSync(outputPath, '');
  const result = spawnSync('bash', [gitleaksScript, base], {
    cwd: repo,
    env: { ...process.env, RUNNER_TEMP: temp, GITHUB_OUTPUT: outputPath },
    encoding: 'utf8',
    timeout: 10000,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `Gitleaks policy selection failed: ${result.stderr}`);
  const match = fs.readFileSync(outputPath, 'utf8').match(/^config=(.*)$/m);
  assert.ok(match, 'The Gitleaks policy step must write a config result.');
  return match[1];
};
try {
  gitPlan(policyFixture, 'init', '-q');
  gitPlan(policyFixture, 'config', 'commit.gpgsign', 'false');
  gitPlan(policyFixture, 'config', 'tag.gpgsign', 'false');
  gitPlan(policyFixture, 'config', 'core.hooksPath', '/dev/null');
  fs.writeFileSync(path.join(policyFixture, '.gitleaks.toml'), 'base\n');
  fs.writeFileSync(path.join(policyFixture, '.gitleaksignore'), 'base-ignore\n');
  gitPlan(policyFixture, 'add', '-A');
  gitPlan(policyFixture, 'commit', '-qm', 'base');
  const policyBase = gitPlan(policyFixture, 'rev-parse', 'HEAD');
  fs.writeFileSync(path.join(policyFixture, '.gitleaks.toml'), 'pr\n');
  fs.writeFileSync(path.join(policyFixture, '.gitleaksignore'), 'pr-ignore\n');
  gitPlan(policyFixture, 'commit', '-qam', 'pull request');
  const policyTemp = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-policy-'));
  const configPath = runPolicy(policyFixture, policyBase, policyTemp);
  assert.equal(configPath, path.join(policyTemp, 'gitleaks-base.toml'));
  assert.equal(
    fs.readFileSync(configPath, 'utf8'),
    'base\n',
    'The base .gitleaks.toml must win over the pull-request copy.'
  );
  assert.equal(
    fs.readFileSync(path.join(policyFixture, '.gitleaksignore'), 'utf8'),
    'base-ignore\n',
    'The base .gitleaksignore must replace the pull-request copy.'
  );

  fs.writeFileSync(path.join(policyFixture, '.gitleaks.toml'), 'pr-again\n');
  fs.writeFileSync(path.join(policyFixture, '.gitleaksignore'), 'pr-ignore-again\n');
  assert.equal(
    runPolicy(policyFixture, 'f'.repeat(40), policyTemp),
    '',
    'An unavailable base revision must not trust a pull-request policy.'
  );
  assert.equal(
    fs.existsSync(path.join(policyFixture, '.gitleaks.toml')),
    false,
    'An unavailable base revision must remove the pull-request .gitleaks.toml.'
  );
  assert.equal(
    fs.existsSync(path.join(policyFixture, '.gitleaksignore')),
    false,
    'An unavailable base revision must remove the pull-request .gitleaksignore.'
  );

  const noPolicyFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-no-policy-'));
  gitPlan(noPolicyFixture, 'init', '-q');
  gitPlan(noPolicyFixture, 'config', 'commit.gpgsign', 'false');
  gitPlan(noPolicyFixture, 'config', 'tag.gpgsign', 'false');
  gitPlan(noPolicyFixture, 'config', 'core.hooksPath', '/dev/null');
  fs.writeFileSync(path.join(noPolicyFixture, 'README.md'), 'base\n');
  gitPlan(noPolicyFixture, 'add', '-A');
  gitPlan(noPolicyFixture, 'commit', '-qm', 'base');
  const noPolicyBase = gitPlan(noPolicyFixture, 'rev-parse', 'HEAD');
  fs.writeFileSync(path.join(noPolicyFixture, '.gitleaks.toml'), 'pr\n');
  fs.writeFileSync(path.join(noPolicyFixture, '.gitleaksignore'), 'pr\n');
  gitPlan(noPolicyFixture, 'add', '-A');
  gitPlan(noPolicyFixture, 'commit', '-qm', 'pull request');
  assert.equal(
    runPolicy(noPolicyFixture, noPolicyBase, policyTemp),
    '',
    'A base without .gitleaks.toml must fall back to the built-in policy.'
  );
  assert.equal(
    fs.existsSync(path.join(noPolicyFixture, '.gitleaks.toml')),
    false,
    'A base without .gitleaks.toml must remove the pull-request policy.'
  );
  fs.rmSync(noPolicyFixture, { recursive: true, force: true });
} finally {
  fs.rmSync(policyFixture, { recursive: true, force: true });
}

// The ci-result aggregate must accept an expected docs-only skip and reject a
// missing source job in every other scope.
const ciResultGuard = (ciWorkflow.jobs['ci-result'].steps || []).find(
  (step) =>
    step.name === 'Fail when a required job failed or the scope is incomplete'
);
assert.ok(ciResultGuard, 'ci.yml: the ci-result guard is missing.');
const ciResult = (overrides = {}) => {
  const env = {
    PATH: process.env.PATH,
    PLAN_RESULT: 'success',
    QUALITY_RESULT: 'success',
    BOOTSTRAP_RESULT: 'success',
    UNIT_RESULT: 'success',
    INTEGRATION_RESULT: 'success',
    BUILD_RESULT: 'success',
    TOOLING_RESULT: 'success',
    DOCS_ONLY: 'false',
    ...overrides,
  };
  const result = spawnSync(
    'bash',
    ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', ciResultGuard.run],
    { cwd: root, env, encoding: 'utf8', timeout: 5000 }
  );
  assert.ifError(result.error);
  return result.status;
};
assert.equal(ciResult(), 0, 'A complete source run must pass ci-result.');
assert.equal(
  ciResult({ DOCS_ONLY: 'true', UNIT_RESULT: 'skipped', INTEGRATION_RESULT: 'skipped' }),
  0,
  'A docs-only run must accept the intentionally skipped source jobs.'
);
assert.equal(
  ciResult({ DOCS_ONLY: '', UNIT_RESULT: 'skipped' }),
  1,
  'An unknown scope must not accept a skipped source job.'
);
assert.equal(
  ciResult({ UNIT_RESULT: 'skipped' }),
  1,
  'A source run must reject a skipped source job.'
);
assert.equal(
  ciResult({ DOCS_ONLY: 'true', INTEGRATION_RESULT: 'failure' }),
  1,
  'A docs-only run must still reject a failed source job.'
);
assert.equal(
  ciResult({ DOCS_ONLY: 'true', BUILD_RESULT: 'cancelled' }),
  1,
  'A docs-only run must still reject a cancelled source job.'
);
assert.equal(ciResult({ PLAN_RESULT: 'failure' }), 1, 'A failed plan must fail ci-result.');
assert.equal(
  ciResult({ QUALITY_RESULT: 'failure' }),
  1,
  'A failed quality job must fail ci-result.'
);
cases += 8;

// The disposable wp-env cache must reset the managed WordPress checkouts on a
// persistent runner. @wordpress/env creates WordPress-PHPUnit and
// tests-WordPress-PHPUnit below the project cache; the cache root itself is not
// a Git worktree.
const cleanupFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-wp-env-'));
const cleanupCache = path.join(
  cleanupFixture,
  'wp-env-xnetvn-agent-hub-wp-fixture'
);
const managedCheckouts = [
  path.join(cleanupCache, 'WordPress-PHPUnit'),
  path.join(cleanupCache, 'tests-WordPress-PHPUnit'),
];
const symlinkedCheckout = path.join(cleanupCache, 'tests-WordPress');
const outsideRepo = path.join(cleanupFixture, 'outside-repo');
const sentinel = path.join(cleanupFixture, 'sentinel.txt');
fs.writeFileSync(sentinel, 'keep\n');
const gitCleanup = (cwd, ...args) => {
  const result = spawnSync('git', args, {
    cwd,
    env: {
      ...process.env,
      GIT_AUTHOR_NAME: 'CI Contract',
      GIT_AUTHOR_EMAIL: 'ci@example.invalid',
      GIT_COMMITTER_NAME: 'CI Contract',
      GIT_COMMITTER_EMAIL: 'ci@example.invalid',
    },
    encoding: 'utf8',
    timeout: 10000,
  });
  assert.ifError(result.error);
  assert.equal(result.status, 0, `git ${args.join(' ')} failed: ${result.stderr}`);
};
const initCheckout = (dir) => {
  fs.mkdirSync(dir, { recursive: true });
  gitCleanup(dir, 'init', '-q');
  gitCleanup(dir, 'config', 'commit.gpgsign', 'false');
  fs.writeFileSync(path.join(dir, 'tracked.txt'), 'base\n');
  gitCleanup(dir, 'add', 'tracked.txt');
  gitCleanup(dir, 'commit', '-qm', 'base');
};
const dirtyCheckout = (dir) => {
  fs.writeFileSync(path.join(dir, 'tracked.txt'), 'changed\n');
  fs.writeFileSync(path.join(dir, 'untracked.txt'), 'untracked\n');
};
try {
  for (const dir of managedCheckouts) {
    initCheckout(dir);
  }
  initCheckout(outsideRepo);
  dirtyCheckout(outsideRepo);
  fs.symlinkSync(outsideRepo, symlinkedCheckout, 'dir');
  for (const dir of managedCheckouts) {
    dirtyCheckout(dir);
  }
  let cleanup = spawnSync(
    'bash',
    [path.join(root, 'bin/cleanup-wp-env.sh'), '--reset', '--no-docker'],
    {
      cwd: root,
      env: { ...process.env, WP_ENV_HOME: cleanupFixture },
      encoding: 'utf8',
      timeout: 10000,
    }
  );
  assert.ifError(cleanup.error);
  assert.equal(cleanup.status, 0, `wp-env cleanup failed: ${cleanup.stderr}`);
  for (const dir of managedCheckouts) {
    assert.equal(
      fs.readFileSync(path.join(dir, 'tracked.txt'), 'utf8'),
      'base\n',
      `The cleanup must reset tracked local changes in ${dir}.`
    );
    assert.equal(
      fs.existsSync(path.join(dir, 'untracked.txt')),
      false,
      `The cleanup must remove untracked files from ${dir}.`
    );
  }
  assert.equal(
    fs.readFileSync(sentinel, 'utf8'),
    'keep\n',
    'The cleanup must not touch files outside the wp-env cache.'
  );
  assert.equal(
    fs.readFileSync(path.join(outsideRepo, 'tracked.txt'), 'utf8'),
    'changed\n',
    'A symlinked checkout outside the cache must not be reset.'
  );
  assert.equal(
    fs.existsSync(path.join(outsideRepo, 'untracked.txt')),
    true,
    'A symlinked checkout outside the cache must keep its untracked files.'
  );

  for (const dir of managedCheckouts) {
    dirtyCheckout(dir);
  }
  cleanup = spawnSync(
    'bash',
    [
      path.join(root, 'bin/cleanup-wp-env.sh'),
      '--reset',
      '--dry-run',
      '--no-docker',
    ],
    {
      cwd: root,
      env: { ...process.env, WP_ENV_HOME: cleanupFixture },
      encoding: 'utf8',
      timeout: 10000,
    }
  );
  assert.ifError(cleanup.error);
  assert.equal(cleanup.status, 0, `wp-env dry run failed: ${cleanup.stderr}`);
  for (const dir of managedCheckouts) {
    assert.equal(
      fs.readFileSync(path.join(dir, 'tracked.txt'), 'utf8'),
      'changed\n',
      `A dry run must not reset ${dir}.`
    );
    assert.equal(
      fs.existsSync(path.join(dir, 'untracked.txt')),
      true,
      `A dry run must not remove untracked files from ${dir}.`
    );
  }
  assert.equal(
    fs.readFileSync(sentinel, 'utf8'),
    'keep\n',
    'A dry run must not touch files outside the wp-env cache.'
  );
} finally {
  fs.rmSync(cleanupFixture, { recursive: true, force: true });
}

// The volume cleanup must remove only the project volumes, keep images and
// local files, support a dry run and fail when the Docker cleanup fails.
const volumeFixture = fs.mkdtempSync(path.join(os.tmpdir(), 'ci-wp-env-volumes-'));
const volumeCache = path.join(
  volumeFixture,
  'wp-env-xnetvn-agent-hub-wp-volume-fixture'
);
const fakeBin = path.join(volumeFixture, 'bin');
const dockerCalls = path.join(volumeFixture, 'docker-calls.txt');
fs.mkdirSync(volumeCache, { recursive: true });
fs.writeFileSync(path.join(volumeCache, 'docker-compose.yml'), 'services: {}\n');
fs.mkdirSync(fakeBin, { recursive: true });
fs.writeFileSync(
  path.join(fakeBin, 'docker'),
  '#!/usr/bin/env bash\nprintf "%s\\n" "$*" >> "$DOCKER_CALLS"\nif [ "${1:-}" = compose ]; then exit "${FAKE_DOCKER_DOWN_EXIT:-0}"; fi\nexit 0\n'
);
fs.writeFileSync(path.join(fakeBin, 'npx'), '#!/usr/bin/env bash\nexit 0\n');
fs.chmodSync(path.join(fakeBin, 'docker'), 0o755);
fs.chmodSync(path.join(fakeBin, 'npx'), 0o755);
const runVolumeCleanup = (args, extraEnv = {}) =>
  spawnSync(
    'bash',
    [path.join(root, 'bin/cleanup-wp-env.sh'), ...args],
    {
      cwd: root,
      env: {
        ...process.env,
        PATH: `${fakeBin}:${process.env.PATH}`,
        WP_ENV_HOME: volumeFixture,
        DOCKER_CALLS: dockerCalls,
        ...extraEnv,
      },
      encoding: 'utf8',
      timeout: 10000,
    }
  );
try {
  let volume = runVolumeCleanup(['--volumes', '--dry-run']);
  assert.ifError(volume.error);
  assert.equal(volume.status, 0, `wp-env volume dry run failed: ${volume.stderr}`);
  assert.match(
    String(volume.stdout),
    /DRY-RUN: would remove the Docker volumes for wp-env-xnetvn-agent-hub-wp-volume-fixture/,
    'The volume dry run must report the project it would clean.'
  );
  const calls = fs.existsSync(dockerCalls)
    ? fs.readFileSync(dockerCalls, 'utf8')
    : '';
  assert.doesNotMatch(
    calls,
    /compose/,
    'The volume dry run must not call docker compose.'
  );

  volume = runVolumeCleanup(['--volumes', '--no-docker']);
  assert.ifError(volume.error);
  assert.equal(volume.status, 0, `wp-env volume skip failed: ${volume.stderr}`);
  assert.match(
    String(volume.stdout),
    /Volume step skipped \(--no-docker\)/,
    'The volume step must be skipped with --no-docker.'
  );

  volume = runVolumeCleanup(['--volumes'], { FAKE_DOCKER_DOWN_EXIT: '1' });
  assert.ifError(volume.error);
  assert.equal(
    volume.status,
    1,
    'A failed Docker volume removal must fail the cleanup.'
  );
  assert.match(
    String(volume.stderr),
    /ERROR: could not remove the Docker volumes for wp-env-xnetvn-agent-hub-wp-volume-fixture/,
    'A failed Docker volume removal must name the project.'
  );
} finally {
  fs.rmSync(volumeFixture, { recursive: true, force: true });
}

process.stdout.write(`Workflow gate contracts passed: ${cases} outcome combinations.\n`);
process.stdout.write('Workflow change-detection and coverage mapping contracts passed.\n');
process.stdout.write('CI plan scope, matrix selection and ci-result contracts passed.\n');
process.stdout.write('Coverage baseline selection contracts passed.\n');
