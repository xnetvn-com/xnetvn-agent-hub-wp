# Connection guide

The wire contract for the xNetVN Agent Hub WP MCP server — read it with [the skill overview](../SKILL.md) before the first request.

## 1. Environment variables

| Variable | Value |
|---|---|
| `XNETVN_MCP_URL` | `https://example.com/wp-json/xnetvn-agent-hub-wp/mcp` |
| `XNETVN_MCP_KEY` | The bearer key, shape `xnv_<key-id>_<secret>` |

Load both from the environment or a secret store. The WordPress admin shows the key once. A document, a chat, a commit, a log and a shell history never contain the value, and a command line never carries it as a literal.

## 2. Request headers

| Header | Value | When |
|---|---|---|
| `Authorization` | `Bearer $XNETVN_MCP_KEY` | every request |
| `Content-Type` | `application/json` | every request |
| `Accept` | `application/json, text/event-stream` | every request |
| `MCP-Protocol-Version` | `2026-07-28` | every 2026 request |
| `Mcp-Method` | `tools/list` or `tools/call` | every request |
| `Mcp-Name` | The wire name, for example `xnetvn-get-post` | `tools/call` only |
| `Mcp-Session-Id` | The value returned by `initialize` | 2025-11-25 session only |

Do not send a cookie or `X-WP-Nonce` to the MCP route. That route accepts a bearer key only; the cookie and the nonce belong to the WordPress management REST routes, where a human works in a browser.

## 3. The 2026-07-28 sessionless profile

The 2026-07-28 profile has no handshake: every JSON-RPC message is one `POST`, the server keeps no session, and the client sends `MCP-Protocol-Version` plus the method headers on every request. The body carries one request or one notification; a `tools/call` copies the wire name into both `Mcp-Name` and `params.name`.

A complete modern `tools/list` request looks like this:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientInfo": { "name": "example-agent", "version": "1.0.0" },
      "io.modelcontextprotocol/clientCapabilities": {}
    }
  }
}
```

A list request omits `Mcp-Name`. Send it with `Mcp-Method: tools/list`.

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientInfo": { "name": "example-agent", "version": "1.0.0" },
      "io.modelcontextprotocol/clientCapabilities": {}
    },
    "name": "xnetvn-get-session-info",
    "arguments": {}
  }
}
```

Send it with `Mcp-Method: tools/call` and `Mcp-Name: xnetvn-get-session-info`.

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientInfo": { "name": "example-agent", "version": "1.0.0" },
      "io.modelcontextprotocol/clientCapabilities": {}
    },
    "name": "xnetvn-get-ability-info",
    "arguments": { "name": "xnetvn/get-post" }
  }
}
```

Send it with `Mcp-Method: tools/call` and `Mcp-Name: xnetvn-get-ability-info`. The argument is the canonical name, never the wire name.

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "tools/call",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientInfo": { "name": "example-agent", "version": "1.0.0" },
      "io.modelcontextprotocol/clientCapabilities": {}
    },
    "name": "xnetvn-execute-ability",
    "arguments": {
      "name": "xnetvn/get-post",
      "arguments": { "id": 123 }
    }
  }
}
```

Send it with `Mcp-Method: tools/call` and `Mcp-Name: xnetvn-execute-ability`. Delegated execution takes the canonical target name and rechecks the target policy; it never grants more authority than a direct call.

## 4. The two `curl` commands

Both commands read the body from a file. The key stays in the environment and the payload stays out of the command line.

```bash
curl -sS "$XNETVN_MCP_URL" \
  -H "Authorization: Bearer $XNETVN_MCP_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/list" \
  --data-binary @tools-list.json
```

```bash
curl -sS "$XNETVN_MCP_URL" \
  -H "Authorization: Bearer $XNETVN_MCP_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/call" \
  -H "Mcp-Name: xnetvn-get-session-info" \
  --data-binary @tools-call.json
```

For a call to another tool, change `Mcp-Name` and the body together; a header that names one tool and a body that names another is a `HeaderMismatch` error.

## 5. Legacy 2025-11-25 handshake

**Legacy 2025-11-25 only.** Use this sequence only when the client or the server negotiates 2025-11-25. Do not send it in the 2026 sessionless profile.

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-11-25",
    "capabilities": {},
    "clientInfo": { "name": "example-agent", "version": "1.0.0" }
  }
}
```

The `initialize` response may return an `Mcp-Session-Id` header; when it does, send that header on every later request, send `MCP-Protocol-Version: 2025-11-25`, then acknowledge the session:

```json
{
  "jsonrpc": "2.0",
  "method": "notifications/initialized"
}
```

A notification has no `id` and expects no response. Do not send `Mcp-Method` or `Mcp-Name` in the legacy profile, and never mix a 2026 per-request `_meta` block with a 2025 session.

## 6. Reading the response

A response is either `application/json` or `text/event-stream`; the client must handle both because the server chooses per request.

- With `application/json`, parse the body as one JSON-RPC response.
- With `text/event-stream`, read the stream line by line and ignore `event:`, `id:`, `retry:` and `:` comment lines.
- Parse every `data:` payload as JSON and keep the message whose `id` equals the request `id`; notifications may arrive first and have no `id`.
- Stop reading when that response arrives; the stream may stay open, and it never carries a second answer with the same `id`.

## 7. Version negotiation

The client states `MCP-Protocol-Version: 2026-07-28`. A server that does not support that version answers 400 with `UnsupportedProtocolVersionError` and the versions it accepts. Negotiate down to `2025-11-25`, switch to the full legacy handshake in section 5, and record the negotiated value in the client configuration. Never send a 2026-shaped request after falling back, and never claim a version the server did not accept.

## 8. Header and protocol errors

- `HeaderMismatch` means a header disagrees with the body: `Mcp-Method` does not match `method`, `Mcp-Name` is missing on `tools/call`, or `Mcp-Name` differs from `params.name`.
- `UnsupportedProtocolVersionError` means the requested protocol version is not available; negotiate as described in section 7.

Both are request defects. Correct the request and resend it once; a retry with the same headers fails the same way.

## 9. No-token health check

Send a `tools/list` request without the `Authorization` header and expect 401. The route exists when the answer is 401 rather than 404, and the web server forwards the header when the same request with the key answers 200.

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST "$XNETVN_MCP_URL" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/list" \
  --data-binary @tools-list.json
```

A 401 here is the expected result, not a failure. Do not repeat the check in a loop and do not try a token to see whether it works.

## 10. Common mistakes

| Mistake | Correction |
|---|---|
| `tools/call` with `"name": "xnetvn/get-post"` | Use the wire name `xnetvn-get-post`. |
| `get-ability-info` or `execute-ability` with the wire name `xnetvn-get-post` | Those arguments take the canonical `xnetvn/get-post`. |
| A cookie or `X-WP-Nonce` on the MCP route | Send the bearer key only. |
| `Accept: application/json` without `text/event-stream` | Send both; the server may answer with a stream. |
| A 2026 `_meta` request inside a 2025 handshake | Keep one profile per session and follow its sections in full. |

The error vocabulary and the recovery procedure are in [the troubleshooting guide](troubleshooting.md).
