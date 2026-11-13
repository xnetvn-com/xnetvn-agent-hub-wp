# Workflow guide

How an agent moves from a task to one verified MCP call — from the first session read to the final report. The contract is in [the connection guide](connect.md); the rules an agent must not break are in [the safety contract](safety.md).

## 1. The universal loop

1. **Session.** Call `xnetvn-get-session-info` and confirm the user, the key scope, the toolsets and `rate_limit.remaining`.
2. **Discover.** Call `xnetvn-discover-abilities`, optionally filtered by toolset or search text, and keep only the visible tools.
3. **Schema.** Call `xnetvn-get-ability-info` with the canonical name and read the exact input schema.
4. **Pre-read.** Read the target object and record the values the change depends on.
5. **Call.** Send the call with the wire name, the documented arguments and `Mcp-Method` and `Mcp-Name` matching the body.
6. **Read back.** Read the object again and compare every field the call was meant to change.
7. **Report.** State the object, the action, the sanitized before and after values and the visible effect.

A read stops after step 6. A write adds the confirmation of section 8 between steps 4 and 5.

## 2. System

Minimal sequence: `xnetvn/get-session-info`, then `xnetvn/discover-abilities`, then `xnetvn/get-ability-info` for the target.

- **Do:** use `xnetvn/execute-ability` only when the client cannot call the target directly; pass the canonical name and the target arguments, and apply the target tier and confirmation rule.
- **Do not:** use the wrapper to turn a write into a read, to skip the target schema or to retry a target that failed. The wrapper rechecks the target policy and grants nothing extra.

## 3. Content

Minimal sequence: pre-read with `xnetvn/get-post`, confirm a consequential change, then `xnetvn/update-post` or `xnetvn/create-post`, then read the post back.

- **Do:** keep the post ID from the pre-read, send only the fields that change, and compare the read-back with the intended values.
- **Do not:** publish, trash, delete, change featured media or sticky state, or run any bulk mutation without the human confirmation in section 8.

`update-post` accepts `expected_modified` as a best-effort UTC conflict field, described in section 10.

## 4. Taxonomy

Minimal sequence: `xnetvn/list-terms` to resolve the term IDs, then the assignment or term write, then `xnetvn/list-terms` again for the target taxonomy.

- **Do:** resolve names to IDs first and keep the taxonomy slug and the object ID in the report.
- **Do not:** send a term name where the schema requires an ID, and do not treat a refused assignment as a reason to widen the key.

## 5. Media

Minimal sequence: `xnetvn/get-media` to confirm the attachment and its owner, then the update, then `xnetvn/get-media` again.

- **Do:** read the upload limits before an upload and confirm the target when the media is attached to a published post.
- **Do not:** upload from a URL when the site disabled remote fetching; `remote_fetch_disabled` and `remote_fetch_refused` are final answers, not retry prompts.

## 6. Comments

Minimal sequence: list comments, `xnetvn/get-comment` for the target, then the moderation or edit, then read the comment back.

- **Do:** confirm every moderation action and every bulk moderation, and keep the comment and its post ID together in the report.
- **Do not:** purge comments without an explicit human instruction; purge is destructive, takes `status` as its required argument and requires the confirmation of section 8.

## 7. Users

Minimal sequence: `xnetvn/list-users`, then the user read, then the admin-gated change, then the user read again.

- **Do:** confirm every role, status or session change with a human and record the previous value.
- **Do not:** create, edit or remove an account that the response marks as protected, and do not retry a refusal with a different account.

## 8. Confirmation before a write

State the object, the action, the sanitized before and after values and the visible effect, then wait for a human answer.

| Tier | What needs a human yes |
|---|---|
| read | nothing |
| write | every consequential write: publication, trashing, featured media, sticky state, comment moderation, taxonomy bulk work and every bulk mutation at any size |
| destructive | every call |
| admin | every call |

A write that only changes a draft field inside the instruction the human already gave is not a consequential write; perform it, read the object back and report the persisted values. When in doubt, ask.

## 9. Batching and ordering

- Reads may run in parallel when they are independent; keep the response order in the report.
- Mutations run one at a time, in the order the human confirmed. Never fan out a bulk operation into parallel single calls.
- Read the whole set before a bulk mutation, confirm the complete set with its IDs and changes, and then send one bounded call.
- A request above the documented cap is a schema validation error: correct it and never send it. A human yes never raises a schema maximum. The seven caps are in the bulk caps table of [the safety contract](safety.md); do not restate them from memory.

## 10. Conflict detection

`xnetvn/update-post` accepts `expected_modified`, the UTC modification time read from the object. It is a best-effort conflict field, not a transaction lock: a concurrent writer can still win between the read and the write. When the answer is the `conflict` reason, read the object again, show the human what changed and decide with them. Never overwrite on a conflict and never assume the field makes a retry safe.

## 11. Rate budget

The default budget is 120 requests per minute per key. `xnetvn/get-session-info` returns `rate_limit.remaining`; read it before a batch and after a long task. On 429, honour `Retry-After` and retry reads only. Never retry a mutation automatically, and never start several agents on the same key to get around the budget.

## 12. Idempotency

No mutation is automatically safe to repeat. Use three signals instead:

- the object read: the change is already there or it is not;
- the `conflict` message: another writer moved the object;
- the duplicate messages (`duplicate_comment`, `duplicate_term`, `duplicate_user`): the object already exists.

When the outcome is not clear from those signals, classify it with the reconciliation procedure in [the troubleshooting guide](troubleshooting.md) before any retry.

## 13. Reporting

Close every task with the object type and ID, the action, the sanitized before and after values, the persisted result and any remaining risk. Write `REDACTED` in place of a key, a secret, a hash, a nonce, a cookie or a raw argument value. Leave out anything the human does not need to decide the next step.
