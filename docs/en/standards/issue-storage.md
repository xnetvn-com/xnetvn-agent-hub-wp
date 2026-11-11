# Issue storage

Status: accepted 2026-11-03. Owner decision: the English documentation in `docs/en/` is canonical and `docs/vi/` is its translation. Project tickets are not documentation: they live in one Git-ignored store on this machine.

## Where tickets live

| Store | Content | Language | In Git |
|---|---|---|---|
| `.local/docs/en/issues/` | every project ticket | English schema; prose may still be Vietnamese for migrated historical tickets | No, ignored by Git |

The store belongs to the main checkout (`git rev-parse --path-format=absolute --git-common-dir` gives the Git directory there). Run the acceptance verifier from that checkout; from a worktree the default lookup finds no store and the gate fails closed.

`docs/vi/issues/` and `docs/en/issues/` no longer exist. 70 tickets were migrated into the store on 2026-11-03 and the two trees were removed in the same change; their history remains in Git.

## Layout

```
.local/docs/en/issues/
├── v0.1/
│   ├── bugs/
│   ├── feature_requests/
│   ├── others/
│   └── refactor/
├── .journal.jsonl        write journal (one JSON line per tool write)
├── .migrated-from        migration provenance
├── .migration-report.md  per-ticket migration result
└── .translation-report.md per-ticket translation result
```

## Rules

- The store is never committed, never packaged and never force-added. `.local/` is ignored by the root `.gitignore`.
- Tickets are written and changed only through `issue_tool.py`: `new`, `status`, `claim`, `block`, `progress`, `answer` and `migrate`. Direct text edits still work but leave an audit warning (`IS41`).
- Copy the store outside the repository before any bulk write, for example `tar -C <checkout> -czf <outside>/tickets-<date>.tgz .local/docs/en/issues`.
- Never use `git clean -x` without `-e .local/`: the flag deletes ignored files, which is the store.
- Search with `rg --no-ignore` or `issue_tool.py board`; plain `rg`, Grep and Glob skip ignored paths and make a ticket look absent.
- Another machine or a lab worktree cannot read the store. Send a dispatch brief with the ticket content instead, and keep this machine as the only writer.

## Language

Every ticket is written in professional English. All 70 migrated tickets were translated on 2026-11-03: `issue_tool.py check` reports no `IS42` warning, and no Vietnamese prose remains outside code. Vietnamese survives only as quoted data inside 47 distinct inline-code values (67 occurrences) and 5 fenced lines: glossary terms that the Vietnamese documentation must use, the fixed sample-article title, a post title inside a command, historical status-log rows, names of Vietnamese template headings, one `rg` pattern that matches those headings, one quoted block of historical test output, and one campaign-report marker comment. Those values are evidence or input, so they stay verbatim; a translation must never rewrite identifiers, quoted commands, measured numbers or stored values.

## Acceptance-gate owners

The acceptance verifier reads a gate owner's status from the store when the store is present. A lab worktree does not carry the store, so a gate whose owner cannot be found is not applied and its red case stays red: the rule fails closed and never hides a failure. Unit and integration tests use the committed fixtures under `tests/Fixtures/tickets/`, so a fresh clone stays testable without the store.

## Migration record

- Tool: `issue_tool.py migrate` of the `lc-udev-writing-project-issues` skill, version 2.0.0.
- Runs: `docs/vi/issues` with `--prefer-en docs/en/issues` (67 tickets), then `docs/en/issues` (3 English-only tickets).
- Date and agent: 2026-11-03, `opencode-docs-sync-20261103`.
- Result: 70 tickets written, 0 errors after adding the metadata and status-log sections the legacy English tickets lacked; 25 claimability warnings remain for closed or unanswered tickets.
- Removal: `git rm -r -- docs/en/issues docs/vi/issues`, in the same reviewed change.
- Backup before migration: a 961 KB archive of both legacy trees kept outside the repository.
- Store backup after translation: `$HOME/.local/share/xnetvn-agent-hub-wp/backups/ticket-store-20261103.tgz` (987 KB, 70 tickets and their reports), also outside the checkout.

## Verification

`composer check:docs` runs `bin/check-docs-parity.py`, which verifies English/Vietnamese path parity, heading structure and every relative documentation link. It does not scan the store, because the store is not documentation and is not shipped; `issue_tool.py board` and `check` are the gates for tickets.
