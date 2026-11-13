# Troubleshooting guide

Diagnose from evidence, then act — never guess a cause and never retry a mutation to see what happens. The request shapes are in [the connection guide](connect.md); the confirmation rules are in [the safety contract](safety.md).

## 1. Symptom table

| Symptom | Likely cause | Safe next action | Do not |
|---|---|---|---|
| 400 with `HeaderMismatch` | `Mcp-Method` does not match `method`, `Mcp-Name` is missing or differs from `params.name`, or a 2026 header is combined with a legacy body. | Correct the header and body from one profile, then send once. | Retry the same request or patch only the header. |
| 400 with `UnsupportedProtocolVersionError` | The requested `MCP-Protocol-Version` is not supported by this server. | Negotiate to `2025-11-25` and switch to the full legacy handshake. | Claim 2026 support or mix the two profiles. |
| 401 on every request | The key is missing, malformed, revoked or expired, or the key owner was deleted. | Stop, mask the value, and ask the human to check the key in the WordPress admin. | Loop, try other keys or paste a key into a chat. |
| 401 only on some requests | The header was stripped by a proxy, or the client dropped it on a redirect. | Fix the client and proxy so the header is forwarded, then send once. | Add a cookie or a nonce as a workaround. |
| 403 from an Origin, TLS or IP check | A browser `Origin` is not allowed, the request is not HTTPS, or the peer IP is outside the key range. | Use the approved HTTPS client and origin, or ask the human to review the site settings. | Disable TLS verification, spoof the origin or change the IP allowlist. |
| 403 for an existing owner that lacks the delegation capability | The user exists but cannot delegate the route; the key is not the problem. | Stop and report the denial with the user and the route. | Reissue the key yourself or request a wider scope. |
| 429 with `Retry-After` | The key exceeded 120 requests in a minute. | Wait for `Retry-After`, then retry reads only. | Retry a mutation, open parallel workers or raise the budget. |
| 503 | The MCP Adapter is inactive, the database is locked or the cache is unreachable. | Report the outage; a human checks the adapter and the services. | Retry in a tight loop or treat it as a tool result. |
| HTTP 200 with `isError` | The tool failed at the logical level; the transport still succeeded. | Read the reason, reconcile the object and report. | Treat 200 as success or resend the mutation. |
| HTTP 200 with an unchanged object | The write was a no-op, was filtered, or never persisted. | Read the object back, compare every field and report the mismatch. | Assume the write landed or repeat it blindly. |
| The SSE body cannot be parsed | The client split a JSON message across `data:` lines, or tried to parse the whole body as JSON. | Read `data:` lines, join each payload, and match the response `id`. | Lose the partial payload or ignore the `id`. |
| Unknown tool in `tools/call` | A wrong wire name, a typo or an ability outside the key scope. | Use the wire name derived from the canonical discovery output. | Probe similar names or infer a permission. |
| Hidden tool | The ability exists but the key scope, toolset or site ceiling hides it. | Stop and report the denial; the message is intentionally identical to an unknown tool. | Treat it as proof the tool does not exist or ask for a wider scope. |
| Planned tool | The name belongs to a toolset that is not implemented at this revision. | Use an implemented alternative or report the gap. | Copy the planned name into a client or present it as available. |
| Version mismatch after a client upgrade | The client sends 2026 headers to a server that only accepts the 2025 handshake, or the reverse. | Pick one profile per session and follow it in full. | Disable the version check or downgrade silently. |
| `invalid_params` | An argument is missing, has the wrong type, exceeds a maximum or is not allowed by the strict schema. | Re-read the input schema with `xnetvn/get-ability-info` and correct the argument. | Loosen the schema locally or ignore the validation error. |
| `forbidden` | The owner lacks the capability or the object permission for this object, or the delegated target is denied. | Report the object and the missing capability; stop. | Ask for a wider scope, retry with another key or treat it as a hidden tool. |
| `conflict` | The object changed after the pre-read; `expected_modified` no longer matches. | Read the object again, show the human the change and decide together. | Overwrite the object or retry with a fresh timestamp you guessed. |
| `partial_failure` | Some objects in a bulk call changed and some did not. | Read the full set, list every changed and unchanged object, and report. | Resend the whole set or retry only the missing IDs. |
| `internal_error` | The server failed after accepting the request; the outcome is unknown. | Read the target and the surrounding list, classify the outcome, then report. | Assume nothing happened or retry immediately. |
| `rate_limited` | The per-key expensive-work budget is exhausted for one operation. | Read `rate_limit.remaining`, wait, then retry reads only. | Retry the expensive operation repeatedly. |
| `remote_fetch_disabled` | The site disabled remote fetching on purpose. | Ask the human to upload the bytes directly or provide a local file. | Flip the site setting, bypass the policy or retry. |
| `remote_fetch_refused` | The URL resolves to a private address, is not allowed, or redirects to one. | Report the refused URL; do not fetch it another way. | Try another scheme, a proxy or an IP literal. |
| `payload_too_large` | The request or upload exceeds the documented size bound. | Reduce the payload or split it under the limit with the human's confirmation, then proceed. | Raise the limit yourself or resend the same bytes. |
| `file_type_not_allowed` | The MIME type, extension or image dimensions are outside the accepted set. | Convert the file to an accepted type and confirm again. | Rename the extension or forge the MIME type. |
| `duplicate_comment` | An identical comment already exists. | Read the existing comment and report it; treat the intent as already applied. | Create a near-duplicate to force a new row. |
| `duplicate_term` | The term name or slug already exists in the taxonomy. | Read the existing term and use its ID. | Force a new term with a fake slug. |
| `duplicate_user` | The login, email or slug is already taken. | Read the existing user and report the collision. | Change the email to bypass the check. |
| `protected_account` | The account is protected from changes through this interface. | Report the account and the requested action. | Retry with another account or a different tool. |

## 2. Diagnosis order

1. Read the HTTP status and the response headers, including `Retry-After` and `WWW-Authenticate`.
2. Read the JSON-RPC error or the tool `isError` reason and match it to a row above.
3. Confirm the request profile in one glance: protocol version, `Mcp-Method`, `Mcp-Name` and `params.name`.
4. Check the key and the object against the session info before changing anything.
5. For a mutation, read the object before any retry.

Do not skip step 5 for an error that arrived after the request was sent; a transport failure is not proof that the mutation did not commit.

## 3. HTTP status and tool-level reason

- A 400 is a request defect: a header mismatch or an unsupported protocol version.
- A 401 is uniform for a missing, malformed, revoked or expired key and for a deleted owner. The message never says which case applies.
- A 403 is a transport or identity denial: Origin, TLS, IP, or an existing owner without the delegation capability.
- A 429 carries `Retry-After`, and only reads may retry automatically.
- HTTP 200 does not mean the tool succeeded. A tool-level failure arrives as `isError` with a reason from the fixed vocabulary.
- A hidden tool and an unknown tool return the same unavailable message. The agent must not distinguish them or infer a permission.

## 4. Reconciliation procedure

Use this procedure for a timeout, a 5xx, an `isError`, a `partial_failure` or an `internal_error`:

1. **Freeze.** Stop the retry loop and keep the request you sent.
2. **Read the target.** Read the object by ID and record the fields the mutation would have changed.
3. **Read the neighbourhood.** List the collection around the object, including a page before and after the expected position, and check for a duplicate row with the same values.
4. **Classify.** Choose exactly one outcome: committed, not committed, partial or unknown, with the evidence written next to it.
5. **Decide.** A committed outcome is reported and closed. A not-committed outcome may be retried once, with the same arguments, after re-reading the preconditions. A partial or unknown outcome goes to the human.
6. **Report.** Name the object, the attempted action, the evidence, the classification and the safe next step. Mask every secret and every raw argument value.

When the read itself fails, the outcome stays unknown. Do not fall back to a retry, do not wait for the object to appear and do not let another agent take over the same object. Stop, report and keep the object ID.

## 5. Evidence to keep

Record these facts before you report, and mask the key, the cookie and every raw argument value:

- the JSON-RPC `id`, the method, the wire name and the protocol version;
- the HTTP status and the response headers, with `Authorization` replaced by `REDACTED`;
- the reason from the fixed vocabulary, never the upstream provider text;
- the object type and ID, and the values read before and after the attempt;
- the outcome class and the evidence that supports it;
- the human confirmation and the sanitized values that were confirmed.

An evidence set that cannot separate committed, not committed, partial and unknown is incomplete; collect the missing read before you decide anything.

## 6. When a conflict meets another agent

The `conflict` message wins over any assumption about ordering. Do not apply a last-write-wins rule, do not merge two edits silently, and do not use a second key to force the write. Read the object, show the current values and the intended values, and let the human choose the final state.

## 7. Known limits

The pack documents the wire contract; it does not prove that a given site runs the same revision. When a response contradicts this guide, report the contradiction with the request, the status, the reason and the sanitized body, and stop. Do not patch the client to match a guess.
