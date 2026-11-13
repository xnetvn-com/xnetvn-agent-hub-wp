// Enforce the repository workflow hardening contract without services.
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');

const root = path.resolve(__dirname, '../..');
const wpEnvRequire = createRequire(require.resolve('@wordpress/env/package.json'));
const yaml = wpEnvRequire('js-yaml');
const workflowDirectory = path.join(root, '.github/workflows');

// Trusted jobs select the self-hosted runner only when the repository switch is
// `true` (or a manual trial asks for it) and the trusted-PR guard passes. The
// labels live in code; the variable never carries a label. Every job keeps the
// GitHub-hosted fallback.
const trustedRunner =
  "${{ fromJSON(((inputs.self_hosted_trial) || ((github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && vars.XNETVN_CI_RUNNER == 'true')) && '[\"self-hosted\",\"linux\"]' || '\"ubuntu-24.04\"') }}";
const trustedRunnerX64 =
  "${{ fromJSON(((inputs.self_hosted_trial) || ((github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && vars.XNETVN_CI_RUNNER == 'true')) && '[\"self-hosted\",\"linux\",\"x64\"]' || '\"ubuntu-24.04\"') }}";
const hostedRunner = 'ubuntu-24.04';
// Jobs that start Docker containers move to the self-hosted runner only when
// both switches are on: the general switch plus the Docker one. A host whose
// container network cannot reach its own containers keeps them hosted without
// holding back the jobs that need no Docker at all.
const trustedDockerRunner =
  "${{ fromJSON(((inputs.self_hosted_trial) || ((github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && vars.XNETVN_CI_RUNNER == 'true' && vars.XNETVN_CI_RUNNER_DOCKER == 'true')) && '[\"self-hosted\",\"linux\"]' || '\"ubuntu-24.04\"') }}";
const dockerJobs = {
  'ci.yml': ['integration'],
  'coverage-check.yml': ['integration'],
  'plugin-check.yml': ['check'],
};
// Jobs that download an amd64/x64-only binary declare the architecture so an
// ARM self-hosted runner is never selected.
const x64Jobs = {
  'workflow-lint.yml': ['lint'],
  'security-scan.yml': ['secrets'],
};
// Jobs that hold a write token or an OIDC identity, and the job started by an
// untrusted trigger, never touch the persistent machine.
// Jobs that hold a write token or an OIDC identity, and the job started by an
// untrusted trigger, never touch the persistent machine. `plan`, `ci-result`
// and the workflow-lint jobs are not here: they hold `contents: read` or
// nothing, and the fork guard keeps foreign code off the host. A hosted job
// that the account cannot start fails before its first step and takes its whole
// workflow with it, which is how a billing fault hid the cost-free jobs.
// The labeler is the single exception: it needs `pull-requests: write`, cannot
// run hosted while the account has no Actions minutes, and the same-repository
// guard below keeps a fork pull request from starting it on the host.
const hostedOnlyJobs = {
  'release.yml': ['draft'],
  'docs-deploy.yml': ['deploy'],
  'codeql.yml': ['analyze'],
};
const selfHostedPullRequestTargetJobs = {
  'pr-labeler.yml': ['label'],
};
const pullRequestTargetGuard =
  'github.event.pull_request.head.repo.full_name == github.repository';
// The reviewed labeler pin. A dependency bump must update this line and the
// workflow together, so a write-token action change cannot ride along with an
// unrelated pull request.
const pinnedLabelerAction =
  'actions/labeler@bf12e9b00b37c5c0ca2b87b79b2daf7891dbda13';
const normalizeExpression = (value) =>
  String(value ?? '')
    .trim()
    .replace(/^\$\{\{\s*/, '')
    .replace(/\s*\}\}$/, '')
    .trim();
const pinnedAction = /^[^@\s]+@[0-9a-f]{40}$/;
// Contexts that can carry attacker-controlled text must reach a shell through
// an environment variable, never through direct `${{ }}` interpolation.
const untrustedRunExpression =
  /\$\{\{\s*(?:github\.(?:event\.|head_ref|ref_name|base_ref|ref)\b|inputs\.)/;
const parallelExpression =
  "${{ ((inputs.self_hosted_trial || ((github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && vars.XNETVN_CI_RUNNER == 'true' && vars.XNETVN_CI_RUNNER_DOCKER == 'true')) && fromJSON(vars.XNETVN_CI_MAX_PARALLEL || '2')) || 16 }}";
const coverageParallelExpression =
  "${{ ((inputs.self_hosted_trial || ((github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && vars.XNETVN_CI_RUNNER == 'true' && vars.XNETVN_CI_RUNNER_DOCKER == 'true')) && fromJSON(vars.XNETVN_CI_MAX_PARALLEL || '2')) || 4 }}";

function assertStepHardening(file, jobName, step) {
  if (step.uses && !step.uses.startsWith('./')) {
    assert.match(
      step.uses,
      pinnedAction,
      `${file}:${jobName}: action ${step.uses} is not pinned to a full commit SHA`
    );
    if (step.uses.startsWith('actions/checkout@')) {
      assert.equal(
        step.with?.['persist-credentials'],
        false,
        `${file}:${jobName}: checkout must not persist credentials`
      );
    }
  }
  if (typeof step.run === 'string') {
    assert.doesNotMatch(
      step.run,
      untrustedRunExpression,
      `${file}:${jobName}: untrusted context must pass through an environment variable`
    );
  }
}

function eventNames(triggers) {
  if (typeof triggers === 'string') {
    return [triggers];
  }
  if (Array.isArray(triggers)) {
    return triggers;
  }
  if (triggers && typeof triggers === 'object') {
    return Object.keys(triggers);
  }
  return [];
}

function load(file) {
  return yaml.load(fs.readFileSync(path.join(workflowDirectory, file), 'utf8'));
}

function triggersOf(document) {
  return document.on || document[true];
}

function triggerConfig(document, name) {
  const triggers = triggersOf(document);
  if (!triggers || typeof triggers !== 'object' || Array.isArray(triggers)) {
    return null;
  }
  return triggers[name] ?? null;
}

function assertRunnerInput(file, document, trigger) {
  const config = triggerConfig(document, trigger);
  if (config === null) {
    assert.fail(`${file}: ${trigger} has no self_hosted_trial input`);
  }
  const trial = config?.inputs?.self_hosted_trial;
  assert.ok(
    trial,
    `${file}: ${trigger} must declare a self_hosted_trial input`
  );
  assert.equal(
    trial.type,
    'boolean',
    `${file}: ${trigger}.inputs.self_hosted_trial must be a boolean`
  );
  assert.equal(
    trial.default,
    false,
    `${file}: ${trigger}.inputs.self_hosted_trial must default to false`
  );
  assert.equal(
    config?.inputs?.runner,
    undefined,
    `${file}: ${trigger}.inputs.runner must be replaced by self_hosted_trial`
  );
}

const workflowFilePattern = /\.ya?ml$/;

function validateWorkflow(file, document) {
  const jobs = document.jobs || {};
  const events = eventNames(triggersOf(document));
  const isPullRequestTarget = events.includes('pull_request_target');
  const hostedJobs = hostedOnlyJobs[file] || [];

  assert.ok(Object.keys(jobs).length > 0, `${file}: no jobs defined`);
  assert.deepEqual(
    document.permissions,
    {},
    `${file}: workflow permissions must default to an empty map`
  );
  assert.ok(document.concurrency, `${file}: concurrency is missing`);
  assert.match(
    String(document.concurrency.group),
    /github\.workflow/,
    `${file}: the concurrency group must include github.workflow`
  );

  if (events.includes('workflow_dispatch')) {
    assertRunnerInput(file, document, 'workflow_dispatch');
  }
  if (events.includes('workflow_call')) {
    assertRunnerInput(file, document, 'workflow_call');
  }

  for (const [name, job] of Object.entries(jobs)) {
    const selfHostedTargetJob = (
      selfHostedPullRequestTargetJobs[file] || []
    ).includes(name);
    if (selfHostedTargetJob) {
      assert.equal(
        job.uses,
        undefined,
        `${file}:${name}: the self-hosted write exception must stay a job, not a reusable workflow`
      );
    }
    if (job.uses) {
      assert.ok(
        job.uses.startsWith('./'),
        `${file}:${name}: external reusable workflows must be pinned locally`
      );
      assert.ok(
        job.permissions,
        `${file}:${name}: the reusable workflow call needs explicit permissions`
      );
      continue;
    }

    assert.ok(
      Number.isInteger(job['timeout-minutes']) && job['timeout-minutes'] > 0,
      `${file}:${name}: timeout-minutes is missing`
    );

    const expectedRunner =
      isPullRequestTarget || hostedJobs.includes(name)
        ? hostedRunner
        : (dockerJobs[file] || []).includes(name)
          ? trustedDockerRunner
          : (x64Jobs[file] || []).includes(name)
            ? trustedRunnerX64
            : trustedRunner;
    if (selfHostedTargetJob) {
      assert.deepEqual(
        [...events].sort(),
        ['pull_request_target'],
        `${file}:${name}: the self-hosted write exception needs exactly the pull_request_target trigger`
      );
      assert.deepEqual(
        job['runs-on'],
        ['self-hosted', 'linux'],
        `${file}:${name}: the self-hosted write exception needs the trusted labels`
      );
      assert.equal(
        normalizeExpression(job.if),
        pullRequestTargetGuard,
        `${file}:${name}: the self-hosted write exception needs exactly the same-repository guard`
      );
      assert.deepEqual(
        job.permissions,
        { contents: 'read', 'pull-requests': 'write' },
        `${file}:${name}: the self-hosted write exception needs the minimal permissions`
      );
      assert.equal(
        job.steps?.length,
        2,
        `${file}:${name}: the self-hosted write exception must run exactly the cleanup step and the labeler action`
      );
      assert.equal(
        job.steps?.[0]?.run,
        'rm -f -- .github/labeler.yml',
        `${file}:${name}: the self-hosted write exception must start with the fixed cleanup command`
      );
      assert.equal(
        job.steps?.[1]?.uses,
        pinnedLabelerAction,
        `${file}:${name}: the self-hosted write exception must run the pinned labeler action`
      );
    } else {
      assert.equal(
        job['runs-on'],
        expectedRunner,
        `${file}:${name}: the runner does not match the workflow trust boundary`
      );
    }
    assert.notEqual(
      job['runs-on'],
      'self-hosted',
      `${file}:${name}: runs-on must not be a bare self-hosted label`
    );

    if (job['max-parallel'] !== undefined) {
      const expectedSwitch = (dockerJobs[file] || []).includes(name)
        ? "vars.XNETVN_CI_RUNNER == 'true' && vars.XNETVN_CI_RUNNER_DOCKER == 'true'"
        : "vars.XNETVN_CI_RUNNER == 'true'";
      assert.ok(
        String(job['max-parallel']).includes(expectedSwitch),
        `${file}:${name}: max-parallel must follow the same switch as runs-on`
      );
    }

    for (const step of job.steps || []) {
      assertStepHardening(file, name, step);
    }
  }

  if (isPullRequestTarget) {
    const checkedOut = Object.values(jobs).some((job) =>
      (job.steps || []).some(
        (step) => step.uses && step.uses.startsWith('actions/checkout@')
      )
    );
    assert.equal(
      checkedOut,
      false,
      `${file}: pull_request_target must not check out the pull request revision`
    );
  }
}

const workflows = fs
  .readdirSync(workflowDirectory)
  .filter((file) => workflowFilePattern.test(file))
  .sort();

for (const file of workflows) {
  validateWorkflow(file, load(file));
}

// The labeler exception is the only place a write token runs on the persistent
// machine, so every weakening below must fail the contract.
const labelerDocument = load('pr-labeler.yml');
const labelerProbe = (mutate) => {
  const document = JSON.parse(JSON.stringify(labelerDocument));
  mutate(document);
  return document;
};
assert.doesNotThrow(
  () => validateWorkflow('pr-labeler.yml', labelerDocument),
  'The reviewed labeler workflow must pass'
);
assert.doesNotThrow(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.if = '${{ ' + pullRequestTargetGuard + ' }}';
      })
    ),
  'The wrapped guard expression must stay allowed'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.if = `${pullRequestTargetGuard} || true`;
      })
    ),
  /needs exactly the same-repository guard/,
  'A weakened same-repository guard must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        const triggers = document[true] || document.on;
        document.on = { ...triggers, pull_request: {} };
        delete document[true];
      })
    ),
  /needs exactly the pull_request_target trigger/,
  'An added pull_request trigger must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.steps.push({ run: 'echo probe' });
      })
    ),
  /exactly the cleanup step and the labeler action/,
  'An extra shell step must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.steps[0].run = 'echo probe';
      })
    ),
  /must start with the fixed cleanup command/,
  'A changed cleanup command must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.steps[1].uses =
          'actions/setup-node@3d3c42e5aac5ba805825da76410c181273ba90b1';
      })
    ),
  /must run the pinned labeler action/,
  'A substituted action must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label = {
          uses: './.github/workflows/sbom.yml',
          permissions: { contents: 'read' },
        };
      })
    ),
  /must stay a job, not a reusable workflow/,
  'A reusable workflow instead of the labeler job must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label['runs-on'] = 'ubuntu-24.04';
      })
    ),
  /needs the trusted labels/,
  'A hosted runner must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'pr-labeler.yml',
      labelerProbe((document) => {
        document.jobs.label.permissions = {
          contents: 'read',
          'pull-requests': 'write',
          issues: 'write',
        };
      })
    ),
  /needs the minimal permissions/,
  'Extra write permissions must fail'
);

// GitHub executes both extensions, so the discovery and validation path must
// not silently ignore a .yaml workflow.
assert.match('probe.yaml', workflowFilePattern);
assert.match('probe.yml', workflowFilePattern);
assert.doesNotMatch('probe.txt', workflowFilePattern);

const probeWorkflow = (overrides = {}) => ({
  name: 'Probe',
  on: 'pull_request',
  permissions: {},
  concurrency: {
    group: 'probe-${{ github.workflow }}',
    'cancel-in-progress': true,
  },
  jobs: {
    probe: {
      'runs-on': trustedRunner,
      'timeout-minutes': 5,
      permissions: { contents: 'read' },
      steps: [{ run: 'echo probe' }],
    },
  },
  ...overrides,
});
const probe = probeWorkflow();
assert.doesNotThrow(
  () => validateWorkflow('probe.yaml', probe),
  'A conforming .yaml workflow must pass'
);
assert.throws(
  () =>
    validateWorkflow(
      'probe.yaml',
      probeWorkflow({ permissions: { contents: 'write' } })
    ),
  /permissions must default to an empty map/,
  'A .yaml workflow with write permissions must fail'
);
assert.throws(
  () =>
    validateWorkflow(
      'probe.yaml',
      probeWorkflow({
        jobs: {
          probe: {
            ...probe.jobs.probe,
            steps: [
              {
                uses: 'actions/checkout@v4',
                with: { 'persist-credentials': false },
              },
            ],
          },
        },
      })
    ),
  /not pinned to a full commit SHA/,
  'A .yaml workflow with an unpinned action must fail'
);

assert.throws(
  () =>
    assertStepHardening('fixture.yml', 'fixture', {
      run: 'echo "${{ github.event.pull_request.title }}"',
    }),
  /untrusted context/,
  'The hardening contract must reject untrusted interpolation in a shell-only step'
);
assert.throws(
  () =>
    assertStepHardening('fixture.yml', 'fixture', {
      run: 'echo "${{ inputs.version }}"',
    }),
  /untrusted context/,
  'The hardening contract must reject dispatch input interpolation in a shell-only step'
);
assert.doesNotThrow(
  () => assertStepHardening('fixture.yml', 'fixture', { run: 'echo "$TITLE"' }),
  'Environment indirection must remain allowed'
);

const settings = yaml.load(
  fs.readFileSync(path.join(root, '.github/settings.yml'), 'utf8')
);
for (const name of [
  'dependabot.yml',
  'labeler.yml',
  'settings.yml',
  'actionlint.yaml',
]) {
  const document = yaml.load(
    fs.readFileSync(path.join(root, '.github', name), 'utf8')
  );
  assert.ok(
    document && typeof document === 'object' && !Array.isArray(document),
    `.github/${name} must parse as a YAML mapping`
  );
}

// A required context must name a non-matrix job in a workflow that reports on
// every pull request. Path- or branch-filtered workflows leave the check
// pending and block the pull request.
function pullRequestGateJobs() {
  const gates = new Map();
  for (const file of workflows) {
    const document = load(file);
    const events = eventNames(triggersOf(document));
    if (!events.includes('pull_request')) {
      continue;
    }
    const config = triggerConfig(document, 'pull_request');
    if (
      config &&
      (config.paths ||
        config['paths-ignore'] ||
        config.branches ||
        config['branches-ignore'])
    ) {
      continue;
    }
    for (const [id, job] of Object.entries(document.jobs || {})) {
      if (job.uses || job.strategy) {
        continue;
      }
      const name = job.name || id;
      assert.ok(
        !gates.has(name),
        `job name "${name}" is ambiguous: ${gates.get(name)} and ${file}:${id}`
      );
      gates.set(name, `${file}:${id}`);
    }
  }
  return gates;
}

const gateJobs = pullRequestGateJobs();
const stableContexts = [
  'ci-result',
  'Complete source coverage and regression gate',
  'workflow-lint',
  'Secret detection (Gitleaks)',
  'Static analysis (Semgrep)',
  'Filesystem vulnerability scan (Trivy)',
  'Composer and npm advisories',
];
const requiredContexts = new Set();
for (const branch of settings.branches || []) {
  for (const context of branch.protection?.required_status_checks?.contexts ||
    []) {
    requiredContexts.add(context);
  }
}
for (const context of stableContexts) {
  assert.ok(
    gateJobs.has(context),
    `settings.yml: required context "${context}" is not a non-matrix job of an unfiltered pull_request workflow`
  );
  assert.ok(
    requiredContexts.has(context),
    `settings.yml: stable context "${context}" is missing from branch protection`
  );
}
for (const context of requiredContexts) {
  assert.ok(
    gateJobs.has(context),
    `settings.yml: required context "${context}" does not map to a stable pull_request job`
  );
}
assert.deepEqual(
  [...requiredContexts].sort(),
  [...stableContexts].sort(),
  'settings.yml: required contexts must stay the documented stable set'
);

const ci = load('ci.yml');
for (const id of [
  'quality',
  'bootstrap-syntax',
  'unit',
  'integration',
  'build',
  'tooling',
]) {
  assert.ok(ci.jobs[id], `ci.yml: the ${id} job is missing`);
}
assert.equal(
  ci.jobs.quality.strategy,
  undefined,
  'ci.yml: the quality job must stay a single check so the required context is stable'
);
assert.equal(
  ci.jobs.build.strategy,
  undefined,
  'ci.yml: the build job must stay a single check so the required context is stable'
);
assert.ok(
  ci.jobs.plan,
  'ci.yml: the plan job that selects the matrix and the source scope is missing'
);
assert.equal(
  ci.jobs.plan.outputs?.include,
  '${{ steps.select.outputs.include }}',
  'ci.yml: the plan job must expose the integration matrix'
);
assert.equal(
  ci.jobs.plan.outputs?.docs_only,
  '${{ steps.select.outputs.docs_only }}',
  'ci.yml: the plan job must expose the documentation-only scope'
);
assert.deepEqual(
  ci.jobs.integration.strategy?.matrix,
  { include: '${{ fromJSON(needs.plan.outputs.include) }}' },
  'ci.yml: the integration matrix must come from the plan job'
);
assert.equal(
  ci.jobs.integration.strategy?.['max-parallel'],
  parallelExpression,
  'ci.yml: the full integration matrix must run at once on GitHub-hosted runners'
);
assert.equal(
  load('coverage-check.yml').jobs.integration.strategy?.['max-parallel'],
  coverageParallelExpression,
  'coverage-check.yml: the four coverage legs must run at once on GitHub-hosted runners'
);
for (const id of ['unit', 'integration']) {
  const needs = ci.jobs[id].needs;
  assert.ok(
    needs === 'quality' ||
      (Array.isArray(needs) && needs.includes('quality')),
    `ci.yml: ${id} must wait for the fast quality gate`
  );
}

const ciResult = ci.jobs['ci-result'];
assert.ok(ciResult, 'ci.yml: the ci-result aggregate is missing');
assert.equal(
  ciResult.if,
  'always()',
  'ci.yml: ci-result must run even when a dependency fails'
);
assert.deepEqual(
  ciResult.needs,
  ['plan', 'quality', 'bootstrap-syntax', 'unit', 'integration', 'build', 'tooling'],
  'ci.yml: ci-result must depend on every source-gate job and the plan'
);
assert.deepEqual(
  ciResult.permissions,
  {},
  'ci.yml: ci-result must not request any permission'
);
const ciResultGuard = (ciResult.steps || []).find(
  (step) =>
    step.name === 'Fail when a required job failed or the scope is incomplete'
);
assert.ok(ciResultGuard, 'ci.yml: ci-result needs an explicit result guard');
assert.deepEqual(
  Object.keys(ciResultGuard.env || {}).sort(),
  [
    'BOOTSTRAP_RESULT',
    'BUILD_RESULT',
    'DOCS_ONLY',
    'INTEGRATION_RESULT',
    'PLAN_RESULT',
    'QUALITY_RESULT',
    'TOOLING_RESULT',
    'UNIT_RESULT',
  ],
  'ci.yml: ci-result must read every needed result and the plan scope'
);
assert.match(
  String(ciResultGuard.run),
  /DOCS_ONLY/,
  'ci.yml: ci-result must branch on the documentation-only scope'
);
for (const result of ['failure', 'cancelled']) {
  assert.ok(
    ciResultGuard.run.includes(`${result}`),
    `ci.yml: ci-result must fail when a mandatory job is ${result}`
  );
}

const lintWorkflow = load('workflow-lint.yml');
assert.equal(
  lintWorkflow.jobs.lint.if,
  "needs.changes.outputs.automation == 'true'",
  'workflow-lint.yml: the costly job must stay behind change detection'
);
assert.equal(
  lintWorkflow.jobs['workflow-lint'].if,
  'always()',
  'workflow-lint.yml: the required aggregate must always report'
);
const securityAudit = (lintWorkflow.jobs.lint.steps || []).find(
  (step) => step.name === 'Audit workflow security'
);
assert.ok(securityAudit, 'workflow-lint.yml: the zizmor audit is missing');
assert.match(
  String(securityAudit.run),
  /--min-severity medium/,
  'workflow-lint.yml: the zizmor audit must fail on medium or higher findings'
);
assert.deepEqual(
  lintWorkflow.jobs['workflow-lint'].needs,
  ['changes', 'lint'],
  'workflow-lint.yml: the aggregate must wait for both jobs'
);
for (const trigger of ['push', 'pull_request']) {
  const config = triggerConfig(lintWorkflow, trigger);
  assert.equal(
    config?.paths,
    undefined,
    `workflow-lint.yml: ${trigger} must not be path-filtered, or the required check never reports`
  );
}
const lintAggregateGuard = (lintWorkflow.jobs['workflow-lint'].steps || []).find(
  (step) => step.env && step.env.AUTOMATION !== undefined
);
assert.ok(
  lintAggregateGuard,
  'workflow-lint.yml: the aggregate needs an explicit result guard'
);
assert.deepEqual(
  Object.keys(lintAggregateGuard.env).sort(),
  ['AUTOMATION', 'CHANGES_RESULT', 'LINT_RESULT'],
  'workflow-lint.yml: the aggregate guard must read every needed result'
);

const release = load('release.yml');
const sbom = load('sbom.yml');
const releaseSbom = release.jobs.sbom;
const releaseDraft = release.jobs.draft;
const downloadStep = (releaseDraft.steps || []).find(
  (step) => step.uses && step.uses.startsWith('actions/download-artifact@')
);

assert.ok(
  releaseSbom && releaseSbom.uses === './.github/workflows/sbom.yml',
  'release.yml: the draft release must call the local SBOM workflow'
);
assert.ok(
  releaseDraft.needs === 'sbom' ||
    (Array.isArray(releaseDraft.needs) && releaseDraft.needs.includes('sbom')),
  'release.yml: the draft release must wait for the SBOM workflow'
);
assert.ok(downloadStep, 'release.yml: the draft release must download SBOM artifacts');
assert.equal(
  downloadStep.with?.pattern,
  'sbom-*',
  'release.yml: the draft release must download only SBOM artifacts'
);
assert.equal(
  releaseDraft.permissions?.contents,
  'write',
  'release.yml: only the draft job may write repository contents'
);
assert.ok(
  eventNames(triggersOf(sbom)).includes('workflow_call'),
  'sbom.yml: the SBOM workflow must be callable from release.yml'
);
assert.equal(
  sbom.jobs.manifest.if,
  "needs.composer-sbom.result == 'success' && needs.npm-sbom.result == 'success'",
  'sbom.yml: the manifest must require both generators'
);
const sbomDownloads = (sbom.jobs.manifest.steps || []).filter(
  (step) => step.uses && step.uses.startsWith('actions/download-artifact@')
);
assert.deepEqual(
  sbomDownloads.map((step) => step.with?.name),
  ['sbom-composer', 'sbom-npm'],
  'sbom.yml: the manifest must download both producer artifacts by name'
);
for (const step of sbomDownloads) {
  assert.notEqual(
    step['continue-on-error'],
    true,
    'sbom.yml: a download failure must fail the manifest'
  );
}
assert.ok(
  (releaseDraft.steps || []).some(
    (step) => step.name === 'Require complete SBOM evidence'
  ),
  'release.yml: the draft release must verify complete SBOM evidence'
);

const gitleaksStep = load('security-scan.yml').jobs.secrets.steps.find(
  (step) => step.name === 'Scan for leaked secrets'
);
assert.ok(gitleaksStep, 'security-scan.yml: the secret scan step is missing');
assert.match(
  gitleaksStep.run,
  /gitleaks dir/,
  'security-scan.yml: the merge tree must be scanned on pull requests'
);
assert.match(
  gitleaksStep.run,
  /--log-opts/,
  'security-scan.yml: the push and pull-request commit ranges must be scanned'
);
assert.ok(
  gitleaksStep.run.includes('${BASE_SHA}..${HEAD_SHA} -m'),
  'security-scan.yml: the pull-request history scan must use the base range'
);
assert.ok(
  gitleaksStep.run.includes('--log-opts="--all -m"'),
  'security-scan.yml: an unavailable base must fall back to full history, not a tree-only scan'
);
const gitleaksPolicy = load('security-scan.yml').jobs.secrets.steps.find(
  (step) => step.id === 'policy'
);
assert.ok(gitleaksPolicy, 'security-scan.yml: the trusted policy step is missing');
assert.equal(
  gitleaksPolicy.if,
  "github.event_name == 'pull_request'",
  'security-scan.yml: the trusted policy must only be prepared for pull requests'
);
assert.equal(
  gitleaksPolicy.run,
  'bash bin/prepare-gitleaks-policy.sh "$BASE_SHA"',
  'security-scan.yml: the trusted policy must come from the base revision'
);
assert.equal(
  gitleaksPolicy.env?.BASE_SHA,
  '${{ github.event.pull_request.base.sha }}',
  'security-scan.yml: the policy step must read the pull-request base SHA'
);
assert.equal(
  gitleaksStep.env?.CONFIG,
  '${{ steps.policy.outputs.config }}',
  'security-scan.yml: the scan must use the trusted policy output'
);
assert.ok(
  gitleaksStep.run.includes('config_args=(--config "$CONFIG")'),
  'security-scan.yml: the trusted configuration must be passed explicitly'
);
assert.doesNotMatch(
  gitleaksStep.run,
  /only the merge tree is scanned/,
  'security-scan.yml: the history scan must never fail open'
);

const docs = load('docs-deploy.yml');
assert.match(
  String(docs.jobs.deploy.if || ''),
  /vars\.XNETVN_PAGES_ENABLED == 'true'/,
  'docs-deploy.yml: Pages deployment must stay behind an explicit repository variable'
);

for (const file of ['ci.yml', 'coverage-check.yml', 'plugin-check.yml']) {
  const workflow = load(file);
  let found = false;
  for (const [jobName, job] of Object.entries(workflow.jobs || {})) {
    const steps = job.steps || [];
    const cleanupIndex = steps.findIndex(
      (step) => step.name === 'Clean up stale wp-env state'
    );
    if (cleanupIndex === -1) {
      continue;
    }
    found = true;
    const cleanup = steps[cleanupIndex];
    assert.equal(
      String(cleanup.run).trim(),
      'bash bin/cleanup-wp-env.sh --reset --volumes',
      `${file}:${jobName}: the wp-env cleanup must reset the cache and remove the project volumes`
    );
    assert.equal(
      cleanup.if,
      undefined,
      `${file}:${jobName}: the wp-env cleanup must run unconditionally`
    );
    assert.equal(
      cleanup['continue-on-error'],
      undefined,
      `${file}:${jobName}: the wp-env cleanup must not mask a failure`
    );
    const startIndex = steps.findIndex(
      (step) =>
        step.name === 'Start the disposable WordPress environment' ||
        step.name === 'Start WordPress with the native coverage driver' ||
        String(step.uses || '').startsWith('WordPress/plugin-check-action@')
    );
    assert.ok(
      startIndex === -1 || cleanupIndex < startIndex,
      `${file}:${jobName}: the wp-env cleanup must precede the environment start`
    );
  }
  assert.ok(found, `${file}: the wp-env cleanup step is missing`);
  for (const job of Object.values(workflow.jobs || {})) {
    for (const step of job.steps || []) {
      assert.doesNotMatch(
        String(step.run || ''),
        /wp-env destroy/,
        `${file}: the network-heavy wp-env destroy step must not return`
      );
    }
  }
}

for (const file of workflows) {
  const document = load(file);
  for (const [jobName, job] of Object.entries(document.jobs || {})) {
    for (const step of job.steps || []) {
      if (!/^actions\/cache(?:@|\/(?:restore|save)@)/.test(step.uses || '')) {
        continue;
      }
      assert.match(
        String(step.with?.key || ''),
        /runner\.environment/,
        `${file}:${jobName}: a cache key must be scoped by runner environment`
      );
      const restore = String(step.with?.['restore-keys'] || '');
      for (const line of restore.split('\n')) {
        if (line.trim() === '') {
          continue;
        }
        assert.match(
          line,
          /runner\.environment/,
          `${file}:${jobName}: a cache restore key must be scoped by runner environment`
        );
      }
    }
  }
}

for (const file of ['ci.yml', 'coverage-check.yml', 'plugin-check.yml']) {
  const workflow = load(file);
  assert.match(
    String(workflow.env?.NODE_OPTIONS || ''),
    /--dns-result-order=ipv4first/,
    `${file}: wp-env jobs must prefer IPv4 for the WordPress download`
  );
  assert.match(
    String(workflow.env?.NODE_OPTIONS || ''),
    /--no-network-family-autoselection/,
    `${file}: wp-env jobs must not race the address families`
  );
}

process.stdout.write(
  `Workflow hardening contracts passed for ${workflows.length} workflows.\n`
);
