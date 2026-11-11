---
name: Detailed feature request
about: Describe a new ability or product feature with measurable acceptance criteria and security boundaries.
title: "[Feature] "
labels: enhancement
assignees: ""
---

> **This template contains the detailed fields needed for the AI coding assistant and the development team to understand and implement the requested feature professionally and completely.**

---

## 1. Overview

- **Feature name (required):**
  _(A short, clear name describing the new feature)_

- **Related module / toolset (required):**
  - [ ] System Pack
  - [ ] Content Pack
  - [ ] Taxonomy Pack
  - [ ] Blocks Pack
  - [ ] Media Pack
  - [ ] Comments Pack
  - [ ] Appearance Pack
  - [ ] Settings Pack
  - [ ] API key management / authentication
  - [ ] Audit log / GDPR
  - [ ] Other: ___________________

- **Priority (required):**
  - [ ] High (Needed for the next release)
  - [ ] Medium (Important but flexible)
  - [ ] Low (Experience improvement, future idea)

---

## 2. Description and benefits

- **Detailed description (required):**
  _(Describe the feature in detail: purpose, how it works, the actions performed by the user or the AI agent)_

- **Expected benefits (required):**
  _(Explain why this feature matters and what value it brings to users, the website or the AI agent ecosystem)_

---

## 3. Use cases

- **Primary audience:**
  _(AI agent via MCP / WordPress administrator via the admin UI / third-party developer)_

- **Real-world scenario 1:**
  - *Step 1:* The AI agent sends an MCP request with parameters ...
  - *Step 2:* The plugin validates permissions and invokes the internal handler ...
  - *Step 3:* The result is returned in the exact expected JSON schema shape ...

- **Real-world scenario 2:**
  _(Additional scenarios or edge cases)_

---

## 4. Technical requirements

- **Expected risk tier:**
  - [ ] `read` (Read-only)
  - [ ] `write` (Create or update)
  - [ ] `destructive` (Delete or destroy)
  - [ ] `admin` (System configuration change)

- **Minimum required WordPress capabilities:**
  _(e.g. `read`, `edit_posts`, `upload_files`, `manage_options`)_

- **Expected input schema:**
  ```json
  {
    "type": "object",
    "properties": {
      "param1": { "type": "string" }
    },
    "required": ["param1"]
  }
  ```

- **Expected output schema:**
  ```json
  {
    "type": "object",
    "properties": {
      "success": { "type": "boolean" }
    },
    "required": ["success"]
  }
  ```

- **Related technologies, core APIs or libraries:**
  _(e.g. rest_do_request, wp_insert_post, wpdb, ...)_

---

## 5. Security risks and safety boundaries

- [ ] Does this feature change the database?
- [ ] Can this feature expose sensitive user information?
- [ ] Does the returned data follow the untrusted-data rule?
- [ ] Could this feature be abused for DoS or prompt injection attacks?

---

## 6. References and notes

- **References:**
  _(Links to WordPress API documentation, the MCP specification or similar implementations)_

- **Additional notes:**
  _(Anything else the development team should know)_