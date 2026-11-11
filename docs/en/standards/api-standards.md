# API Design Standards (API Standards)

> **Design standards for the MCP (Model Context Protocol) protocol and the management REST API in xNetVN Agent Hub WP.**

---

## 1. Boundaries Between Two Independent Interfaces

The system provides two completely independent API surfaces with different purposes and protection mechanisms:

```
                                  API SYSTEM
                                       │
            ┌──────────────────────────┴──────────────────────────┐
            ▼                                                     ▼
    [1. MCP Protocol Route]                            [2. Management REST Route]
- Route: /wp-json/xnetvn-agent-hub-wp/mcp          - Route: /wp-json/xnetvn-agent-hub-wp/v1/*
- For: AI Agents                                  - For: Administrators in the WP Dashboard
- Authentication: Bearer Token only              - Authentication: WP Cookie + wp_rest Nonce
- Reject Cookie & Nonce (CSRF prevention)         - Reject Bearer Token
```

---

## 2. MCP Protocol Standards (MCP Specification)

### 2.1 Tool Identification & Naming (Tool Naming)
- **Identifier syntax:** `xnetvn/<verb>-<noun>` (all lowercase, separated by hyphens).
- **Standard examples:**
  - `xnetvn/get-session-info`
  - `xnetvn/get-site-info`
  - `xnetvn/discover-abilities`
  - `xnetvn/get-ability-info`
  - `xnetvn/execute-ability`
  - `xnetvn/list-posts`
  - `xnetvn/create-post`

### 2.2 Strict JSON Schema Constraints
- Every Ability must fully declare an **Input Schema** and **Output Schema** as root objects.
- **Input Schema:**
  - `type: "object"`
  - `additionalProperties: false` (prevent injection of unexpected extra arguments).
  - Explicitly declare data types, maximum length (`maxLength`), regex string patterns (`pattern`) and default values (`default`).
- **Output Schema:**
  - Response structure must be clear, with explicit data types.
  - Collections must be wrapped in a paginated object: `{ items: [...], total: int, page: int, total_pages: int }`.

### 2.3 Risk Tier Management (Risk Tiers)
Every tool must belong to exactly 1 of 4 tiers:
- `read`: Read only non-sensitive data (idempotent, safe).
- `write`: Create or update content (side effects, but recoverable).
- `destructive`: Permanently delete data (irreversible).
- `admin`: Change configuration or intervene deeply in the system.

---

## 3. Management REST API Standards (`/v1/*`)

### 3.1 Endpoint Standards
- **HTTP Methods:**
  - `GET`: Query data (no side effects).
  - `POST`: Create a resource (e.g. create an API key).
  - `PATCH` / `PUT`: Update a resource (permission narrowing only - narrow-only).
  - `DELETE`: Delete or revoke a resource (e.g. revoke an API key).
- **Main endpoints:**
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/keys`: List the user's or site-wide API keys.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/keys`: Create an API key.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}/rotate`: Atomically rotate an API key.
  - `DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}`: Revoke an API key.
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/audit`: Query paginated audit logs.
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/settings`: Get site-wide configuration.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/settings`: Update site configuration.

### 3.2 HTTP Response Status Code Standards (Status Codes)
- `200 OK`: Successful request with returned data.
- `201 Created`: Resource created successfully (with the new resource data).
- `204 No Content`: Successful operation with no returned content.
- `400 Bad Request`: Input has invalid syntax or violates the schema.
- `401 Unauthorized`: Missing or invalid credentials.
- `403 Forbidden`: The user lacks permission for the action.
- `404 Not Found`: The corresponding resource was not found.
- `429 Too Many Requests`: Request rate limit exceeded.
- `500 Internal Server Error`: Unexpected server error (sensitive error details hidden).
- `503 Service Unavailable`: Service temporarily unavailable (e.g. a required plugin is missing).

## MCP tool execution errors

Tools use isError=true with fixed English reason/message text. Reviewed reasons: invalid_params, forbidden, not_found, post_type_unavailable, taxonomy_unavailable, duplicate_term, duplicate_user, protected_account, role_unavailable, cooldown_active, invalid_state, unsupported_operation, conflict, partial_failure, restore_failed, payload_too_large, file_type_not_allowed, image_too_large, quota_exceeded, remote_fetch_disabled, remote_fetch_refused, remote_fetch_failed, rate_limited, duplicate_comment, comments_closed, internal_error. Provider messages/data never pass through. Hidden tools retain the uniform tool_unavailable response. Unknown mutation outcomes require reconciliation before retrying. See ADR-0015, ADR-0017 and ADR-0018.
