# Technical Glossary (Glossary)

> **Reference table and definitions of technical concepts and terms used in xNetVN Agent Hub WP.**

---

| Term | English / Abbreviation | Definition & Technical Meaning |
|---|---|---|
| **AI Agent** | Artificial Intelligence Agent | An autonomous software entity controlled by a large language model (LLM), capable of reasoning, planning and using tools to accomplish goals. |
| **Model Context Protocol** | MCP | An open protocol standardizing how AI models connect to and retrieve data and safely execute tools (Tools/Resources/Prompts) from external servers. |
| **Abilities API** | WordPress Abilities API | A new API interface in the WordPress ecosystem for defining website interaction capabilities as actions with explicit input/output data structures. |
| **MCP Adapter** | `mcp-adapter` | The official WordPress adapter plugin, bridging the MCP network protocol and WordPress's internal Abilities system. |
| **API Key** | User-Owned API Key | A dual-identifier credential string comprising a public ID and a secret (`xnv_<key_id>_<secret>`), owned by a specific user and constrained by that user's permissions. |
| **Risk Tier** | Risk Tier | Action impact classification: `read` (read only), `write` (create/edit), `destructive` (delete/destroy), `admin` (system administration). |
| **Toolset** | Toolset | A group of related Abilities by business domain: `system` (system), `content` (post content), `taxonomy` (classification), `blocks` (Gutenberg blocks), `media` (multimedia), `comments` (comments), `users` (users and roles), `appearance` (appearance), `settings` (settings), `plugins` (plugin management), `maintenance` (site maintenance). |
| **Capability Intersection** | Capability Intersection | A mechanism calculating actual Agent permissions as the mathematical intersection of the user account's original permissions, API key scope and website ceiling. |
| **Pepper** | Cryptographic Pepper | A random 32-byte secret stored separately on the server, combined with the API key Secret in `HMAC-SHA256` hashing to protect against Rainbow table attacks when a database is exposed. |
| **Timing Attack** | Timing Attack / Side-Channel | An attack technique measuring server response times to infer individual password or token bytes. Prevented using `hash_equals()` and Dummy HMAC. |
| **Sliding Window Limiter** | Sliding Window Rate Limiter | A request rate-limiting algorithm based on a sliding time window, smoothing traffic and preventing brute-force attacks. |
| **Atomic Rotation** | Atomic Key Rotation | API key rotation within an atomic database transaction (ACID), ensuring keys are never lost or duplicated. |
| **Output Pipeline** | Output Pipeline / Sanitization | An output filtering sequence: remove unnecessary internal links, hide sensitive information, format content safely and validate the structure against JSON Schema. |
| **Prompt Injection** | Prompt Injection Attack | An attack technique inserting malicious instructions into data content to trick an AI model into unauthorized actions. Prevented by treating all website content as untrusted data. |
| **Audit Trail** | Audit Trail / Audit Logging | An immutable audit log recording MCP request history: time, key identifier, user, tool name and execution outcome (without argument values). |
| **GDPR / CCPA** | Personal Data Privacy Regulations | International personal data protection regulations requiring systems to support Export and Erasure of user-related information. |
