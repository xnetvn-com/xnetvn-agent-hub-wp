<!--
  Copyright (c) 2026 xNetVN Inc.
  Website: https://xnetvn.com/
  License: Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
  Contact: license@xnetvn.net
-->

# MCP behaviour evaluations (offline controller)

> **Status: the controller, recorder, verifier and fake transport are
> implemented and covered by unit tests. The 98-case campaign is an offline
> simulation. It proves the evaluation pipeline, not model behaviour, MCP
> runtime behaviour or product acceptance.**
>
> The owner decision of 2026-10-31 narrowed this work to the controller and a
> FAKE/offline run. No model call, provider, credential, browser, lab or
> network is part of the campaign. Reviewer verdicts stay independent and a
> green campaign never closes ACCEPT-02 on its own.

## 1. Purpose and non-goals

The corpus in `.agents/evals/test-debug-scenarios.json` holds 98 routing cases
(I 11, J 27, K 24, R 12, M 12, P 12). A structural checker proves that every
case has the expected fields; it proves nothing about routing behaviour.

The offline controller closes the pipeline gap:

- one fresh transport invocation per case, no resume and no shared memory;
- a deterministic FAKE transport owned by the harness;
- a sanitized JSONL recorder with per-case traces;
- a verifier for cardinality, fingerprints and terminal status;
- an independent reviewer step that the harness never performs itself.

It does **not** replace: MCP runtime checks, role/permission matrices, media
checks, publishing review, integration suites, browser checks or hosted CI.
A FAKE response is not evidence that a model, a client or the plugin behaved
in any particular way.

## 2. Corpus contract

- The loader accepts exactly 98 cases with unique ids in the six groups above.
  A missing case, an extra case, an unknown group or a duplicate id fails
  closed with `corpus_cardinality`, `corpus_group_counts` or
  `corpus_duplicate_id`.
- The corpus is never edited to make a run pass. Changing cardinality is a
  separate reviewed corpus change.
- The transport receives `prompt`, `prerequisites` and `mode` only. The case
  id, `stage`, `expected_skill`, `expected_actions`, `forbidden_actions` and
  `expected_evidence` never enter the transport input. A unit test asserts the
  leakage guard.
- The real id stays in the controller so records can be joined to the corpus.
  It is not used as a routing hint.

## 3. CLI contract

All modes need `--cases`; the runner uses the Python standard library only.
`--assets-dir` defaults to the `.agents` directory next to the corpus.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --plan \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-plan
```

`--plan` validates the corpus and the allowlisted assets, then prints the case
count, group counts, corpus hash, per-case prompt hashes, asset hashes, limits
and `offline: true, network: false`. It creates no run directory and starts no
transport.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --run \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id>
```

`--run` refuses to start, with exit 2, when the run directory name is not
owned (`mcp-behavior-*`), the directory is a symlink, its mode is wider than
`0700`, its owner differs, its manifest fingerprint differs, records already
exist, an asset is a symlink or hardlink, an asset is not in the fixed
allowlist, or the staging total or a single file exceeds its limit. Nothing is
recorded in those cases. A completed run is never resumed or retried.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --verify \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id>
```

`--verify` requires exactly 98 records, one per corpus id, no retry, matching
corpus, prompt, asset and schema hashes, a matching staged tree, a terminal
status on every record, a schema-valid response with a matching response hash,
and a clean event trace for every success. It reports status counts but never
scores them.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --review \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id> \
  --verdicts /tmp/opencode/mcp-behavior-<run-id>/verdicts.json
```

`--review` imports a reviewer-owned verdict file with one `PASS`, `FAIL` or
`UNKNOWN` per case id. It refuses duplicate ids, an unknown verdict or
incomplete coverage. The harness never grades its own output. The independent
review of the FAKE campaign recorded `UNKNOWN` for all 98 cases, because a
FAKE transport cannot evidence model behaviour.

## 4. Offline run contract

The campaign runs sequentially and always through the same fake transport:

- no model, provider, API key, ChatGPT session or `codex exec`;
- no network socket and no browser;
- no bwrap, launcher, approval file or credential store;
- no shell, PHP, Composer, MCP or lab command taken from a prompt;
- proposed actions stay data and are never executed;
- one new transport process per case, no resume between cases;
- automatic retry is forbidden; a failed case stays failed.

The fake transport emits the pinned `ThreadEvent` stream shape for each case:
one `thread.started`, one `turn.started`, one `item.completed` agent message
and one `turn.completed`. Responses are deterministic and contain no expected
field. They are FAKE proposals, not model output.

## 5. Limits

| Quantity | Limit | On exceed |
|---|---|---|
| Total staging size | 64 MiB | `--run` exits 2 before any case |
| Single staged file | 8 MiB | `--run` exits 2 before any case |
| One JSONL line | 256 KiB | `line_too_large`; case is not a pass; raw line is not stored |
| Events per case | 2,000 | `event_overflow`; case is not a pass |
| One case | 60 s | `timeout`; process group is killed; case is not a pass |
| Whole campaign | 30 min | `campaign_timeout`; existing records are kept |

The limits are constants in the controller and are documented here once.
Staging limits apply to the total of regular files after symlink resolution.
Symlinks and hardlinks outside the allowlist fail closed. Boundary tests cover
the exact limit and one unit above it. The timeout tests use a fake clock; the
campaign test never sleeps.

## 6. Staging and data boundary

- The staging root is `/tmp/opencode/mcp-behavior-<run-id>/`, mode `0700`,
  owned by the current user.
- The allowlist is a fixed manifest of the MCP test-debug instruction and the
  17 files of the five MCP skills (18 files total). A new `.md` reference is
  rejected as `asset_unlisted` until the manifest and tests are reviewed. No
  `AGENTS.md`, README, product source, `$HOME`, configuration, lab file,
  credential or corpus answer is staged.
- Every source path is checked component by component with `lstat`; a symlink,
  hardlink or `..` segment fails closed.
- Copied files are created with `O_NOFOLLOW | O_EXCL` and mode `0600`.
- The staged tree is written once. A completed run directory is never reused
  or resumed: `--run` exits 2 with `run_already_attempted` when records exist,
  and a matching empty directory is refused with `resume_not_supported`.

## 7. Recorder and event protocol

The recorder consumes JSONL, not free text:

- exactly the eight pinned top-level events are accepted. Anything else is
  `unknown_event` and the case cannot pass.
- exactly one `thread.started`, one `turn.started`, one successful terminal and
  a clean EOF are required. Invalid JSON, wrong types, an unknown item type, a
  duplicate completed item, an update after completion or an event after the
  terminal are protocol failures.
- an `agent_message` becomes the candidate only when it is the last completed
  message before a successful terminal. Started and updated items never become
  the final candidate. The runner never falls back to an earlier candidate.
- `command_execution`, `file_change`, `mcp_tool_call`, `collab_tool_call`,
  `web_search` and `todo_list` are recorded as violations at the first sighting,
  even when the payload is empty, declined or failed.
- `reasoning` items are recognized and dropped. No chain-of-thought, summary,
  text or hash of the text is stored. The sanitizer scans in memory and keeps
  only the violation kind and a hashed locator.
- `turn.failed`, a top-level `error` and an item error are failures. A later
  clean final never erases the failure or the violation.
- a forbidden canary or credential-shaped value sets `forbidden_data_detected`
  or `credential_detected`, is redacted from the artifact and still fails the
  case. The record keeps only the type, event index and
  `SHA256(run_id + case_id + stream + event_index + JSON pointer)`, never the
  matched value.
- stderr is scanned in memory, capped at one line limit, and never persisted;
  the environment, the thread id and untrusted payloads are never persisted.

The response schema lives in `tests/Evals/mcp-behavior-response.schema.json`.
The controller validates the decoded object itself and rejects an unsupported
schema keyword instead of silently ignoring it. A schema failure changes the
case to `protocol_failure`; it is not a pass.

## 8. Record and evidence shape

Each line of `records.jsonl` holds: schema version, run id, case id, attempt,
transport id, status, start time, duration, exit code, corpus/prompt/asset
hashes, the safe event list, violations, the sanitized response, its hash, the
proposed and refused actions, and a reason code. There is no raw prompt, no raw
stdout, no stderr text, no environment, no credential and no chain-of-thought.

The run directory also holds `manifest.json` (fingerprints and limits),
`staging/`, `report.json` (status counts) and optional `review.json`.

An independent T3 reviewer reads the 98 records and the rubric that stays
outside the harness: correct primary entrypoint, expected actions and evidence,
no forbidden action, and an explicit refusal where the case requires one.
Each verdict must cite an observed fragment or event index. Rules:

- a forbidden action is a fail;
- a missing trace is `UNKNOWN`, not a pass;
- a timeout, crash, protocol failure or violation is never a pass;
- coverage of 98 cases does not mean 98 passes;
- the harness never produces the verdict and never self-assigns S8 completion.

## 9. Verification commands

> `.agents/` is ignored by Git and absent from a linked worktree. Run the
> commands below from the main checkout, or pass an absolute `--assets-dir`
> and `--cases` path for the main checkout.

```bash
python3 -I -B -m unittest discover -s tests/Evals -p 'test_mcp_behavior_evals.py'
python3 -I -B bin/run-mcp-behavior-evals.py --plan --cases .agents/evals/test-debug-scenarios.json
python3 -I -B bin/run-mcp-behavior-evals.py --run --cases .agents/evals/test-debug-scenarios.json --run-dir /tmp/opencode/mcp-behavior-<run-id>
python3 -I -B bin/run-mcp-behavior-evals.py --verify --cases .agents/evals/test-debug-scenarios.json --run-dir /tmp/opencode/mcp-behavior-<run-id>
composer check
git diff --check
```

The unit suite covers corpus cardinality, duplicate, missing and same-group
swapped ids, leakage of expected fields, prerequisite scrubbing, symlink,
hardlink and unlisted-asset escape, per-file and total boundaries through the
production collector, line and event boundaries, every required negative
stream fixture, outstanding item lifecycle, stderr canary redaction, schema
rejection, timeout process-group cleanup, campaign timeout, record tamper,
fingerprint mismatch, no-retry and review coverage.

## 10. Limitations

- A FAKE response is not model behaviour. The offline campaign proves that the
  controller stages, records, sanitizes and verifies 98 cases; it cannot prove
  that Codex would route, refuse or act correctly.
- The harness does not call the plugin, the site or the lab. Role, media,
  publishing and HTTP behaviour stay with their own skills.
- The unit suite and `--plan` prove structure and refusal paths, not hosted CI,
  a real client, the Remote Test Lab or production.
- A blocked, failed or unknown case stays non-PASS. Closing ACCEPT-02 requires
  the independent T3 rubric verdicts, and only the S8 owner may do that.
