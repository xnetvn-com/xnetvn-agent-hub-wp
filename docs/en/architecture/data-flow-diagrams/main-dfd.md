# Main Data Flow Diagram (DFD)

> **Modeling the path of data through the processing layers of xNetVN Agent Hub WP.**

---

## 1. MCP Request Flow Diagram

```mermaid
sequenceDiagram
    autonumber
    actor Client as AI Agent (Client)
    participant Guard as Transport & Origin Guard
    participant Auth as Authenticator & Pepper
    participant Limiter as Sliding Window Limiter
    participant Gateway as MCP Gateway
    participant Policy as Capability Policy
    participant Ability as Ability Handler
    participant Pipeline as Output Pipeline
    participant Audit as Audit Logger (Shutdown)
    participant DB as MySQL Database

    Client->>Guard: HTTP POST /wp-json/xnetvn-agent-hub-wp/mcp (Bearer Token)
    Guard->>Guard: Check HTTPS & Origin Header
    alt Guard Requirements Not Met
        Guard-->>Client: Return 403 Forbidden / 400 Bad Request
    else Guard Valid
        Guard->>Auth: Forward request
        Auth->>Auth: Parse Token xnv_<key_id>_<secret>
        Auth->>DB: Read Key & Pepper record (hash_equals)
        Auth->>Limiter: Check limits of 120 req/min/key & 10 fail/min/ip
        alt Authentication Failed or Rate Limit Exceeded
            Auth-->>Client: Return 401 Unauthorized / 429 Too Many Requests
        else Authentication Successful
            Auth->>Gateway: Initialize safe AuthContext
            Gateway->>Policy: Check intersected permissions (UserCap ∩ KeyTier ∩ SiteCeiling)
            alt Permission Denied
                Policy-->>Client: Return Tool Execution Error (Permission Denied)
            else Permission Valid
                Policy->>Ability: Execute internal Handler (rest_do_request)
                Ability->>Pipeline: Return raw result
                Pipeline->>Pipeline: Filter sensitive data & Check Output Schema
                Pipeline-->>Client: Return standard JSON-RPC result
                Ability->>Audit: Buffer audit event (RequestScope)
            end
        end
    end
    Audit->>DB: Batch INSERT at shutdown hook
```

---

## 2. Atomic API Key Rotation Flow Diagram

```mermaid
sequenceDiagram
    autonumber
    actor Admin as Administrator (Browser)
    participant REST as KeysController (REST API)
    participant Lock as WpOwnerLock (MySQL GET_LOCK)
    participant Service as KeyService
    participant DB as MySQL Database (InnoDB)

    Admin->>REST: POST /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}/rotate (Cookie + Nonce)
    REST->>REST: Authenticate Cookie & Check wp_rest Nonce
    REST->>Lock: Request exclusive user lock (WpOwnerLock)
    alt Unable to Acquire Lock
        Lock-->>Admin: Return 409 Conflict (Operation in progress)
    else Lock Acquired
        Lock->>Service: Rotate key
        Service->>DB: Start mysqli BEGIN TRANSACTION
        Service->>DB: SELECT old key FOR UPDATE
        Service->>Service: Generate new key xnv_<new_id>_<secret> & Calculate HMAC
        Service->>DB: INSERT new key into *_keys table
        Service->>DB: UPDATE old key with expires_at = NOW() + 24 hours
        Service->>DB: COMMIT TRANSACTION
        Service->>Lock: Release exclusive user lock
        Service-->>Admin: Return new key (Secret shown only 1 time)
    end
```
